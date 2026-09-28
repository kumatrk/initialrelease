<?php

namespace SimpleKuma\Tracking;

use SimpleKuma\Stats\CampaignStatsExpressions;
use SimpleKuma\Stats\StatsHiddenIpService;
use SimpleKuma\Stats\StatsExclusionFlag;

/**
 * On-write updates to clicks_daily_summary, clicks_stats_by_token_daily,
 * and clicks_stats_by_token_hourly (plan: stats pre-aggregation).
 * Called after each click insert and after each conversion insert so aggregates stay current.
 * No cron: token tables are updated only on-write (click + conversion).
 */
class DailySummaryUpdater
{
    private const TOKEN_SKIP_PARAMS = ['click_id', 'user_id', 'transaction_id', 'timestamp', 'ip_address', 'txid', 'event_id'];

    /** @var \mysqli */
    private $db;

    /** @var StatsHiddenIpService|null */
    private $hiddenIpService = null;

    private ?bool $summaryTableExists = null;

    private ?bool $tokenSummaryTableExists = null;

    private ?bool $tokenHourlyTableExists = null;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    private function hiddenIps(): StatsHiddenIpService
    {
        if ($this->hiddenIpService === null) {
            $this->hiddenIpService = new StatsHiddenIpService($this->db);
        }

        return $this->hiddenIpService;
    }

    /**
     * Skip aggregate writes for Meta approval/crawler clicks and stats-hidden IPs.
     *
     * @param array<string, mixed>|null $extraData
     */
    private function shouldSkipStatsAggregate(
        ?int $trafficSourceId,
        ?array $extraData,
        ?string $ua = null,
        ?string $ip = null
    ): bool {
        if ($ip !== null && $ip !== '' && $this->hiddenIps()->isHidden($ip)) {
            return true;
        }

        // When summary table has bot_clicks column, bot clicks are tracked in bot_clicks.
        // If bot_clicks column does not exist (legacy), exclude them from total clicks.
        if (!$this->summaryTableHasBotClicksColumn()) {
            if (CampaignStatsExpressions::shouldExcludeClickFromStats($trafficSourceId, $extraData, $ua)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if clicks_daily_summary table exists.
     */
    public function tableExists(): bool
    {
        if ($this->summaryTableExists !== null) {
            return $this->summaryTableExists;
        }
        $result = $this->db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_daily_summary' LIMIT 1");
        return $this->summaryTableExists = $result && $result->num_rows > 0;
    }

    /**
     * Check if clicks_stats_by_token_daily table exists.
     */
    public function tokenTableExists(): bool
    {
        if ($this->tokenSummaryTableExists !== null) {
            return $this->tokenSummaryTableExists;
        }
        $result = $this->db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_stats_by_token_daily' LIMIT 1");
        return $this->tokenSummaryTableExists = $result && $result->num_rows > 0;
    }

    /**
     * Check if clicks_stats_by_token_hourly table exists.
     */
    public function tokenHourlyTableExists(): bool
    {
        if ($this->tokenHourlyTableExists !== null) {
            return $this->tokenHourlyTableExists;
        }
        $result = $this->db->query(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clicks_stats_by_token_hourly' LIMIT 1"
        );
        return $this->tokenHourlyTableExists = $result && $result->num_rows > 0;
    }

    /**
     * Normalize UTC hour 0–23; null/out-of-range skips hourly upsert.
     */
    public static function normalizeSummaryHour(?int $summaryHour): ?int
    {
        if ($summaryHour === null || $summaryHour < 0 || $summaryHour > 23) {
            return null;
        }

        return $summaryHour;
    }

    /**
     * Extract (param, value) token pairs from extra_data array (traffic_source_tokens + custom_tokens).
     * Accepts array to avoid re-parsing JSON on redirect path.
     */
    private function extractTokensFromExtraData(array $extraData): array
    {
        $pairs = [];
        foreach (['traffic_source_tokens', 'custom_tokens'] as $key) {
            if (empty($extraData[$key]) || !is_array($extraData[$key])) {
                continue;
            }
            foreach ($extraData[$key] as $param => $val) {
                if (in_array($param, self::TOKEN_SKIP_PARAMS, true)) {
                    continue;
                }
                if (is_scalar($val)) {
                    $value = (string) $val;
                } elseif (is_array($val) && isset($val['value'])) {
                    $value = (string) $val['value'];
                } else {
                    $value = json_encode($val);
                }
                $value = mb_substr($value, 0, 512);
                $pairs[] = ['param' => $param, 'value' => $value];
            }
        }
        return $pairs;
    }

    /**
     * mysqli bind types for one clicks_stats_by_token_daily INSERT row.
     *
     * Column order with optins + bot_clicks:
     * campaign_id, summary_date, token_param, token_value, traffic_source_id,
     * visitors, lp_clicks, cost, conversions, optins, revenue, bot_clicks
     *
     * optins is INT; revenue is DECIMAL(12,6). Swapping those two types truncates
     * fractional payouts to whole dollars (typically 0) on every token/zone breakdown.
     */
    public static function tokenUpsertBindTypes(bool $hasBotClicks, bool $hasOptins): string
    {
        if ($hasBotClicks && $hasOptins) {
            return 'isssiiidiidi';
        }
        if ($hasOptins) {
            return 'isssiiidiid';
        }

        return 'isssiiidid';
    }

    /**
     * mysqli bind types for one clicks_stats_by_token_hourly INSERT row.
     * Same as daily with hour (INT) after summary_date.
     */
    public static function tokenHourlyUpsertBindTypes(bool $hasBotClicks, bool $hasOptins): string
    {
        // campaign_id, summary_date, hour, token_param, token_value, traffic_source_id, ...
        if ($hasBotClicks && $hasOptins) {
            return 'isissiiidiidi';
        }
        if ($hasOptins) {
            return 'isissiiidiid';
        }

        return 'isissiiidid';
    }

    /**
     * On-write: UPSERT clicks_stats_by_token_daily (and hourly when $summaryHour is 0–23).
     * Single multi-row INSERT per table per call. Pass extraData as array when available to avoid JSON decode.
     *
     * @param array|null $extraDataAsArray extra_json as array (e.g. $extraData), or null to skip
     * @param int $conversionsDelta 1 for conversion event, 0 for click-only or opt-in
     * @param float $revenueDelta revenue for conversion event, 0 for click-only or opt-in
     * @param int $optinsDelta 1 for opt-in event, 0 otherwise
     * @param int|null $summaryHour UTC hour 0–23 from the click ts; null skips hourly write
     */
    public function upsertTokenAggregatesForClick(
        int $campaignId,
        ?int $trafficSourceId,
        string $summaryDate,
        ?array $extraDataAsArray,
        int $lpClick,
        ?float $cost,
        int $conversionsDelta = 0,
        float $revenueDelta = 0.0,
        ?string $ua = null,
        ?string $ip = null,
        bool $forceInclude = false,
        int $optinsDelta = 0,
        ?int $summaryHour = null
    ): void {
        if (!$this->tokenTableExists() || $extraDataAsArray === null) {
            return;
        }
        if (!$forceInclude
            && $this->shouldSkipStatsAggregate($trafficSourceId, $extraDataAsArray, $ua, $ip)) {
            return;
        }
        $tokens = $this->extractTokensFromExtraData($extraDataAsArray);
        if (empty($tokens)) {
            return;
        }
        $cost = $cost !== null ? (float) $cost : 0.0;
        $isBot = self::isBotClick($extraDataAsArray, $ua);
        $lpInc = (!$isBot && $lpClick === 1) ? 1 : 0;
        $visitors = ($conversionsDelta === 0 && $optinsDelta === 0 && !$isBot) ? 1 : 0;
        $botInc = ($conversionsDelta === 0 && $optinsDelta === 0 && $isBot) ? 1 : 0;
        $hasOptins = $this->tokenTableHasOptinsColumn();
        $hasBotClicks = $this->tokenTableHasBotClicksColumn();
        $hour = self::normalizeSummaryHour($summaryHour);

        // Negative conversion/optin deltas must UPDATE only — INSERT of -1 into UNSIGNED fails
        if ($conversionsDelta < 0 || $optinsDelta < 0) {
            $this->applyNegativeTokenDeltas(
                'clicks_stats_by_token_daily',
                $tokens,
                $campaignId,
                $trafficSourceId,
                $summaryDate,
                null,
                $conversionsDelta,
                $optinsDelta,
                $revenueDelta,
                $hasOptins
            );
            if ($hour !== null && $this->tokenHourlyTableExists()) {
                $this->applyNegativeTokenDeltas(
                    'clicks_stats_by_token_hourly',
                    $tokens,
                    $campaignId,
                    $trafficSourceId,
                    $summaryDate,
                    $hour,
                    $conversionsDelta,
                    $optinsDelta,
                    $revenueDelta,
                    $hasOptins
                );
            }
            return;
        }

        $rows = [];
        foreach ($tokens as $t) {
            $rows[] = [
                'campaign_id' => $campaignId,
                'summary_date' => $summaryDate,
                'hour' => $hour,
                'token_param' => $t['param'],
                'token_value' => $t['value'],
                'traffic_source_id' => $trafficSourceId,
                'visitors' => $visitors,
                'lp_clicks' => ($conversionsDelta === 0 && $optinsDelta === 0) ? $lpInc : 0,
                'cost' => ($conversionsDelta === 0 && $optinsDelta === 0) ? $cost : 0.0,
                'conversions' => $conversionsDelta,
                'optins' => $optinsDelta,
                'revenue' => $revenueDelta,
                'bot_clicks' => $botInc,
            ];
        }

        $this->insertTokenAggregateRows('clicks_stats_by_token_daily', $rows, false, $hasOptins, $hasBotClicks);
        if ($hour !== null && $this->tokenHourlyTableExists()) {
            $this->insertTokenAggregateRows('clicks_stats_by_token_hourly', $rows, true, $hasOptins, $hasBotClicks);
        }
    }

    /**
     * @param list<array{param: string, value: string}> $tokens
     */
    private function applyNegativeTokenDeltas(
        string $table,
        array $tokens,
        int $campaignId,
        ?int $trafficSourceId,
        string $summaryDate,
        ?int $hour,
        int $conversionsDelta,
        int $optinsDelta,
        float $revenueDelta,
        bool $hasOptins
    ): void {
        $hourClause = $hour !== null ? ' AND hour = ?' : '';
        foreach ($tokens as $t) {
            if ($hasOptins) {
                $stmt = $this->db->prepare("
                    UPDATE {$table}
                    SET conversions = GREATEST(0, CAST(conversions AS SIGNED) + ?),
                        optins = GREATEST(0, CAST(optins AS SIGNED) + ?),
                        revenue = GREATEST(0, revenue + ?),
                        updated_at = NOW()
                    WHERE campaign_id = ?
                      AND summary_date = ?
                      AND token_param = ?
                      AND token_value = ?
                      AND (traffic_source_id <=> ?)
                      {$hourClause}
                ");
                if (!$stmt) {
                    continue;
                }
                $param = $t['param'];
                $value = $t['value'];
                if ($hour !== null) {
                    $stmt->bind_param(
                        'iidisssii',
                        $conversionsDelta,
                        $optinsDelta,
                        $revenueDelta,
                        $campaignId,
                        $summaryDate,
                        $param,
                        $value,
                        $trafficSourceId,
                        $hour
                    );
                } else {
                    $stmt->bind_param(
                        'iidisssi',
                        $conversionsDelta,
                        $optinsDelta,
                        $revenueDelta,
                        $campaignId,
                        $summaryDate,
                        $param,
                        $value,
                        $trafficSourceId
                    );
                }
            } else {
                $stmt = $this->db->prepare("
                    UPDATE {$table}
                    SET conversions = GREATEST(0, CAST(conversions AS SIGNED) + ?),
                        revenue = GREATEST(0, revenue + ?),
                        updated_at = NOW()
                    WHERE campaign_id = ?
                      AND summary_date = ?
                      AND token_param = ?
                      AND token_value = ?
                      AND (traffic_source_id <=> ?)
                      {$hourClause}
                ");
                if (!$stmt) {
                    continue;
                }
                $param = $t['param'];
                $value = $t['value'];
                if ($hour !== null) {
                    $stmt->bind_param(
                        'idisssii',
                        $conversionsDelta,
                        $revenueDelta,
                        $campaignId,
                        $summaryDate,
                        $param,
                        $value,
                        $trafficSourceId,
                        $hour
                    );
                } else {
                    $stmt->bind_param(
                        'idisssi',
                        $conversionsDelta,
                        $revenueDelta,
                        $campaignId,
                        $summaryDate,
                        $param,
                        $value,
                        $trafficSourceId
                    );
                }
            }
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function insertTokenAggregateRows(
        string $table,
        array $rows,
        bool $withHour,
        bool $hasOptins,
        bool $hasBotClicks
    ): void {
        $values = [];
        $types = '';
        $params = [];
        foreach ($rows as $u) {
            if ($withHour) {
                if ($hasBotClicks && $hasOptins) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    $types .= self::tokenHourlyUpsertBindTypes(true, true);
                    $params[] = $u['campaign_id'];
                    $params[] = $u['summary_date'];
                    $params[] = $u['hour'];
                    $params[] = $u['token_param'];
                    $params[] = $u['token_value'];
                    $params[] = $u['traffic_source_id'];
                    $params[] = $u['visitors'];
                    $params[] = $u['lp_clicks'];
                    $params[] = $u['cost'];
                    $params[] = $u['conversions'];
                    $params[] = $u['optins'];
                    $params[] = $u['revenue'];
                    $params[] = $u['bot_clicks'];
                } elseif ($hasOptins) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    $types .= self::tokenHourlyUpsertBindTypes(false, true);
                    $params[] = $u['campaign_id'];
                    $params[] = $u['summary_date'];
                    $params[] = $u['hour'];
                    $params[] = $u['token_param'];
                    $params[] = $u['token_value'];
                    $params[] = $u['traffic_source_id'];
                    $params[] = $u['visitors'];
                    $params[] = $u['lp_clicks'];
                    $params[] = $u['cost'];
                    $params[] = $u['conversions'];
                    $params[] = $u['optins'];
                    $params[] = $u['revenue'];
                } else {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                    $types .= self::tokenHourlyUpsertBindTypes(false, false);
                    $params[] = $u['campaign_id'];
                    $params[] = $u['summary_date'];
                    $params[] = $u['hour'];
                    $params[] = $u['token_param'];
                    $params[] = $u['token_value'];
                    $params[] = $u['traffic_source_id'];
                    $params[] = $u['visitors'];
                    $params[] = $u['lp_clicks'];
                    $params[] = $u['cost'];
                    $params[] = $u['conversions'];
                    $params[] = $u['revenue'];
                }
            } elseif ($hasBotClicks && $hasOptins) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $types .= self::tokenUpsertBindTypes(true, true);
                $params[] = $u['campaign_id'];
                $params[] = $u['summary_date'];
                $params[] = $u['token_param'];
                $params[] = $u['token_value'];
                $params[] = $u['traffic_source_id'];
                $params[] = $u['visitors'];
                $params[] = $u['lp_clicks'];
                $params[] = $u['cost'];
                $params[] = $u['conversions'];
                $params[] = $u['optins'];
                $params[] = $u['revenue'];
                $params[] = $u['bot_clicks'];
            } elseif ($hasOptins) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $types .= self::tokenUpsertBindTypes(false, true);
                $params[] = $u['campaign_id'];
                $params[] = $u['summary_date'];
                $params[] = $u['token_param'];
                $params[] = $u['token_value'];
                $params[] = $u['traffic_source_id'];
                $params[] = $u['visitors'];
                $params[] = $u['lp_clicks'];
                $params[] = $u['cost'];
                $params[] = $u['conversions'];
                $params[] = $u['optins'];
                $params[] = $u['revenue'];
            } else {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $types .= self::tokenUpsertBindTypes(false, false);
                $params[] = $u['campaign_id'];
                $params[] = $u['summary_date'];
                $params[] = $u['token_param'];
                $params[] = $u['token_value'];
                $params[] = $u['traffic_source_id'];
                $params[] = $u['visitors'];
                $params[] = $u['lp_clicks'];
                $params[] = $u['cost'];
                $params[] = $u['conversions'];
                $params[] = $u['revenue'];
            }
        }

        if ($withHour) {
            if ($hasBotClicks && $hasOptins) {
                $cols = 'campaign_id, summary_date, hour, token_param, token_value, traffic_source_id, visitors, lp_clicks, cost, conversions, optins, revenue, bot_clicks';
                $dup = 'visitors = GREATEST(0, CAST(visitors AS SIGNED) + VALUES(visitors)),
                    lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) + VALUES(lp_clicks)),
                    cost = GREATEST(0, cost + VALUES(cost)),
                    conversions = GREATEST(0, CAST(conversions AS SIGNED) + VALUES(conversions)),
                    optins = GREATEST(0, CAST(optins AS SIGNED) + VALUES(optins)),
                    revenue = GREATEST(0, revenue + VALUES(revenue)),
                    bot_clicks = GREATEST(0, CAST(bot_clicks AS SIGNED) + VALUES(bot_clicks)),
                    updated_at = NOW()';
            } elseif ($hasOptins) {
                $cols = 'campaign_id, summary_date, hour, token_param, token_value, traffic_source_id, visitors, lp_clicks, cost, conversions, optins, revenue';
                $dup = 'visitors = GREATEST(0, CAST(visitors AS SIGNED) + VALUES(visitors)),
                    lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) + VALUES(lp_clicks)),
                    cost = GREATEST(0, cost + VALUES(cost)),
                    conversions = GREATEST(0, CAST(conversions AS SIGNED) + VALUES(conversions)),
                    optins = GREATEST(0, CAST(optins AS SIGNED) + VALUES(optins)),
                    revenue = GREATEST(0, revenue + VALUES(revenue)),
                    updated_at = NOW()';
            } else {
                $cols = 'campaign_id, summary_date, hour, token_param, token_value, traffic_source_id, visitors, lp_clicks, cost, conversions, revenue';
                $dup = 'visitors = GREATEST(0, CAST(visitors AS SIGNED) + VALUES(visitors)),
                    lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) + VALUES(lp_clicks)),
                    cost = GREATEST(0, cost + VALUES(cost)),
                    conversions = GREATEST(0, CAST(conversions AS SIGNED) + VALUES(conversions)),
                    revenue = GREATEST(0, revenue + VALUES(revenue)),
                    updated_at = NOW()';
            }
        } elseif ($hasBotClicks && $hasOptins) {
            $cols = 'campaign_id, summary_date, token_param, token_value, traffic_source_id, visitors, lp_clicks, cost, conversions, optins, revenue, bot_clicks';
            $dup = 'visitors = GREATEST(0, CAST(visitors AS SIGNED) + VALUES(visitors)),
                    lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) + VALUES(lp_clicks)),
                    cost = GREATEST(0, cost + VALUES(cost)),
                    conversions = GREATEST(0, CAST(conversions AS SIGNED) + VALUES(conversions)),
                    optins = GREATEST(0, CAST(optins AS SIGNED) + VALUES(optins)),
                    revenue = GREATEST(0, revenue + VALUES(revenue)),
                    bot_clicks = GREATEST(0, CAST(bot_clicks AS SIGNED) + VALUES(bot_clicks)),
                    updated_at = NOW()';
        } elseif ($hasOptins) {
            $cols = 'campaign_id, summary_date, token_param, token_value, traffic_source_id, visitors, lp_clicks, cost, conversions, optins, revenue';
            $dup = 'visitors = GREATEST(0, CAST(visitors AS SIGNED) + VALUES(visitors)),
                    lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) + VALUES(lp_clicks)),
                    cost = GREATEST(0, cost + VALUES(cost)),
                    conversions = GREATEST(0, CAST(conversions AS SIGNED) + VALUES(conversions)),
                    optins = GREATEST(0, CAST(optins AS SIGNED) + VALUES(optins)),
                    revenue = GREATEST(0, revenue + VALUES(revenue)),
                    updated_at = NOW()';
        } else {
            $cols = 'campaign_id, summary_date, token_param, token_value, traffic_source_id, visitors, lp_clicks, cost, conversions, revenue';
            $dup = 'visitors = GREATEST(0, CAST(visitors AS SIGNED) + VALUES(visitors)),
                    lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) + VALUES(lp_clicks)),
                    cost = GREATEST(0, cost + VALUES(cost)),
                    conversions = GREATEST(0, CAST(conversions AS SIGNED) + VALUES(conversions)),
                    revenue = GREATEST(0, revenue + VALUES(revenue)),
                    updated_at = NOW()';
        }

        $sql = "INSERT INTO {$table} ({$cols})
                VALUES " . implode(', ', $values) . "
                ON DUPLICATE KEY UPDATE {$dup}";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log("DailySummaryUpdater::insertTokenAggregateRows prepare failed ({$table}): " . $this->db->error);
            return;
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            error_log("DailySummaryUpdater::insertTokenAggregateRows execute failed ({$table}): " . $stmt->error);
        }
        $stmt->close();
    }

    /**
     * After a click insert: UPSERT one row into clicks_daily_summary (clicks += 1, lp_clicks/direct_clicks, cost).
     * Dimensions: campaign_id, traffic_source_id, offer_id, landing_page_id, summary_date (UTC DATE).
     * Skips Meta approval/crawler clicks and stats-hidden IPs.
     *
     * @param array|null $extraDataAsArray extra_json as array (e.g. $extraData), or null to always write
     */
    public function upsertClick(
        int $campaignId,
        ?int $trafficSourceId,
        ?int $offerId,
        ?int $landingPageId,
        int $lpClick,
        ?float $cost,
        ?array $extraDataAsArray = null,
        ?string $ua = null,
        ?string $ip = null
    ): void {
        if (!$this->tableExists()) {
            return;
        }
        if ($this->shouldSkipStatsAggregate($trafficSourceId, $extraDataAsArray, $ua, $ip)) {
            return;
        }
        $this->upsertClickForSummaryDate(
            $campaignId,
            $trafficSourceId,
            $offerId,
            $landingPageId,
            $lpClick,
            $cost,
            gmdate('Y-m-d'),
            $extraDataAsArray,
            $ua
        );
    }

    private function upsertClickForSummaryDate(
        int $campaignId,
        ?int $trafficSourceId,
        ?int $offerId,
        ?int $landingPageId,
        int $lpClick,
        ?float $cost,
        string $summaryDate,
        ?array $extraDataAsArray = null,
        ?string $ua = null
    ): void {
        $cost = $cost !== null ? (float) $cost : 0.0;
        $isBot = self::isBotClick($extraDataAsArray, $ua);
        $clickInc = $isBot ? 0 : 1;
        $botInc = $isBot ? 1 : 0;
        // LP rows (offer_id=null) get lp_clicks ONLY from upsertLpClickUpdate to avoid double-counting
        $lpInc = (!$isBot && $lpClick && $landingPageId !== null && $offerId !== null) ? 1 : 0;
        $directInc = (!$isBot && $lpClick && $landingPageId === null) ? 1 : 0;
        $hasBotClicks = $this->summaryTableHasBotClicksColumn();

        // MySQL UNIQUE allows multiple NULLs, so ON DUPLICATE KEY never fires when
        // landing_page_id/offer_id/traffic_source_id is NULL. Update with <=> then insert.
        $updated = $this->incrementSummaryClickRow(
            $campaignId,
            $trafficSourceId,
            $offerId,
            $landingPageId,
            $summaryDate,
            $clickInc,
            $lpInc,
            $directInc,
            $cost,
            $botInc
        );
        if ($updated) {
            return;
        }

        if ($hasBotClicks) {
            $stmt = $this->db->prepare("
                INSERT INTO clicks_daily_summary 
                (campaign_id, traffic_source_id, offer_id, landing_page_id, summary_date, clicks, lp_clicks, direct_clicks, cost, bot_clicks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                error_log("DailySummaryUpdater::upsertClick prepare failed: " . $this->db->error);
                return;
            }
            $stmt->bind_param(
                'iiiisiiidi',
                $campaignId,
                $trafficSourceId,
                $offerId,
                $landingPageId,
                $summaryDate,
                $clickInc,
                $lpInc,
                $directInc,
                $cost,
                $botInc
            );
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO clicks_daily_summary 
                (campaign_id, traffic_source_id, offer_id, landing_page_id, summary_date, clicks, lp_clicks, direct_clicks, cost)
                VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
            ");
            if (!$stmt) {
                error_log("DailySummaryUpdater::upsertClick prepare failed: " . $this->db->error);
                return;
            }
            $stmt->bind_param(
                'iiiisiid',
                $campaignId,
                $trafficSourceId,
                $offerId,
                $landingPageId,
                $summaryDate,
                $lpInc,
                $directInc,
                $cost
            );
        }

        if (!$stmt->execute()) {
            // Race: another request inserted the same nullable dimensional row — retry update
            if (!$this->incrementSummaryClickRow(
                $campaignId,
                $trafficSourceId,
                $offerId,
                $landingPageId,
                $summaryDate,
                $clickInc,
                $lpInc,
                $directInc,
                $cost,
                $botInc
            )) {
                error_log("DailySummaryUpdater::upsertClick execute failed: " . $stmt->error);
            }
        }
        $stmt->close();
    }

    /**
     * Null-safe increment for one daily summary dimension row.
     * @return bool true when an existing row was updated
     */
    private function incrementSummaryClickRow(
        int $campaignId,
        ?int $trafficSourceId,
        ?int $offerId,
        ?int $landingPageId,
        string $summaryDate,
        int $clickInc,
        int $lpInc,
        int $directInc,
        float $cost,
        int $botInc = 0
    ): bool {
        if ($this->summaryTableHasBotClicksColumn()) {
            $stmt = $this->db->prepare("
                UPDATE clicks_daily_summary
                SET clicks = clicks + ?,
                    lp_clicks = lp_clicks + ?,
                    direct_clicks = direct_clicks + ?,
                    cost = cost + ?,
                    bot_clicks = bot_clicks + ?,
                    updated_at = NOW()
                WHERE campaign_id = ?
                  AND (traffic_source_id <=> ?)
                  AND (offer_id <=> ?)
                  AND (landing_page_id <=> ?)
                  AND summary_date = ?
                LIMIT 1
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param(
                'iiidiiiiis',
                $clickInc,
                $lpInc,
                $directInc,
                $cost,
                $botInc,
                $campaignId,
                $trafficSourceId,
                $offerId,
                $landingPageId,
                $summaryDate
            );
        } else {
            $stmt = $this->db->prepare("
                UPDATE clicks_daily_summary
                SET clicks = clicks + 1,
                    lp_clicks = lp_clicks + ?,
                    direct_clicks = direct_clicks + ?,
                    cost = cost + ?,
                    updated_at = NOW()
                WHERE campaign_id = ?
                  AND (traffic_source_id <=> ?)
                  AND (offer_id <=> ?)
                  AND (landing_page_id <=> ?)
                  AND summary_date = ?
                LIMIT 1
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param(
                'iidiiiis',
                $lpInc,
                $directInc,
                $cost,
                $campaignId,
                $trafficSourceId,
                $offerId,
                $landingPageId,
                $summaryDate
            );
        }
        $ok = $stmt->execute();
        $affected = $ok ? $stmt->affected_rows : 0;
        $stmt->close();

        return $ok && $affected > 0;
    }

    /**
     * After LPRotator marks lp_click: increment lp_clicks on landing page row (offer_id=null).
     * Called for every LP CTA click, including rule redirects.
     */
    public function upsertLpClickUpdate(string $clickId): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $row = $this->loadClickForSummaryUpdate($clickId);
        if (!$row || empty($row['landing_page_id'])) {
            return;
        }
        if ($this->shouldSkipLoadedClick($row)) {
            return;
        }

        $updLp = $this->db->prepare("
            UPDATE clicks_daily_summary
            SET lp_clicks = lp_clicks + 1, updated_at = NOW()
            WHERE campaign_id = ? AND (traffic_source_id <=> ?) AND offer_id IS NULL AND landing_page_id = ? AND summary_date = ?
        ");
        if ($updLp) {
            $updLp->bind_param('iiis', $row['campaign_id'], $row['traffic_source_id'], $row['landing_page_id'], $row['summary_date']);
            $updLp->execute();
            $updLp->close();
        }
    }

    /**
     * After LPRotator sets offer_id: add/update offer row in clicks_daily_summary for offer performance.
     * Call after upsertLpClickUpdate when user clicks through to an offer (not rule redirect).
     */
    public function upsertOfferUpdate(string $clickId, int $offerId): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $row = $this->loadClickForSummaryUpdate($clickId);
        if (!$row || empty($row['landing_page_id'])) {
            return;
        }
        if ($this->shouldSkipLoadedClick($row)) {
            return;
        }

        $cost = $row['cost'] ?? 0.0;
        // Move click attribution from LP-only row (offer_id NULL) to offer row so campaign SUM(clicks) stays accurate.
        $dec = $this->db->prepare("
            UPDATE clicks_daily_summary
            SET clicks = GREATEST(CAST(clicks AS SIGNED) - 1, 0),
                lp_clicks = GREATEST(CAST(lp_clicks AS SIGNED) - 1, 0),
                cost = GREATEST(cost - ?, 0),
                updated_at = NOW()
            WHERE campaign_id = ? AND (traffic_source_id <=> ?) AND offer_id IS NULL AND landing_page_id = ? AND summary_date = ?
              AND clicks > 0
            LIMIT 1
        ");
        if ($dec) {
            $dec->bind_param('diiis', $cost, $row['campaign_id'], $row['traffic_source_id'], $row['landing_page_id'], $row['summary_date']);
            $dec->execute();
            $dec->close();
        }

        $offerUpdated = $this->incrementSummaryClickRow(
            (int)$row['campaign_id'],
            $row['traffic_source_id'],
            $offerId,
            $row['landing_page_id'],
            (string)$row['summary_date'],
            1, // clickInc
            1, // lpInc
            0, // directInc
            (float)$cost
        );
        if (!$offerUpdated) {
            $insOffer = $this->db->prepare("
                INSERT INTO clicks_daily_summary
                (campaign_id, traffic_source_id, offer_id, landing_page_id, summary_date, clicks, lp_clicks, direct_clicks, cost)
                VALUES (?, ?, ?, ?, ?, 1, 1, 0, ?)
            ");
            if ($insOffer) {
                $insOffer->bind_param('iiiisd', $row['campaign_id'], $row['traffic_source_id'], $offerId, $row['landing_page_id'], $row['summary_date'], $cost);
                if (!$insOffer->execute()) {
                    $this->incrementSummaryClickRow(
                        (int)$row['campaign_id'],
                        $row['traffic_source_id'],
                        $offerId,
                        $row['landing_page_id'],
                        (string)$row['summary_date'],
                        1, // clickInc
                        1, // lpInc
                        0, // directInc
                        (float)$cost
                    );
                }
                $insOffer->close();
            }
        }
    }

    /**
     * Load click data for summary updates. Returns array with campaign_id, traffic_source_id, landing_page_id, summary_date, extra_data, ua, ip.
     */
    private function loadClickForSummaryUpdate(string $clickId): ?array
    {
        $hasPersistedFlag = StatsExclusionFlag::columnExists($this->db);
        $flagSelect = $hasPersistedFlag ? ', exclude_from_stats' : '';
        $stmt = $this->db->prepare("
            SELECT campaign_id, traffic_source_id, landing_page_id, DATE(ts) as summary_date,
                   HOUR(ts) as summary_hour, extra_json, cost, ua, ip{$flagSelect}
            FROM clicks
            WHERE click_id = ?
            LIMIT 1
        ");
        if (!$stmt || !$stmt->bind_param('s', $clickId) || !$stmt->execute()) {
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'campaign_id' => (int) $row['campaign_id'],
            'traffic_source_id' => $row['traffic_source_id'] !== null ? (int) $row['traffic_source_id'] : null,
            'landing_page_id' => $row['landing_page_id'] !== null ? (int) $row['landing_page_id'] : null,
            'summary_date' => $row['summary_date'],
            'summary_hour' => isset($row['summary_hour']) ? (int) $row['summary_hour'] : null,
            'extra_data' => !empty($row['extra_json']) ? json_decode($row['extra_json'], true) : null,
            'cost' => isset($row['cost']) && $row['cost'] !== null ? (float) $row['cost'] : 0.0,
            'ua' => $row['ua'] !== null ? (string) $row['ua'] : null,
            'ip' => $row['ip'] !== null ? (string) $row['ip'] : null,
            'exclude_from_stats' => $hasPersistedFlag ? (int)$row['exclude_from_stats'] : null,
            '_stats_flag_present' => $hasPersistedFlag,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function shouldSkipLoadedClick(array $row): bool
    {
        $ip = isset($row['ip']) && $row['ip'] !== null ? (string)$row['ip'] : null;
        if (!empty($row['_stats_flag_present'])) {
            return (int)($row['exclude_from_stats'] ?? 1) === 1
                || ($ip !== null && $ip !== '' && $this->hiddenIps()->isHidden($ip));
        }

        return $this->shouldSkipStatsAggregate(
            $row['traffic_source_id'] ?? null,
            is_array($row['extra_data'] ?? null) ? $row['extra_data'] : null,
            isset($row['ua']) ? (string)$row['ua'] : null,
            $ip
        );
    }

    /**
     * After a conversion insert: load click by click_id, then UPSERT clicks_daily_summary.
     * Opt-in event keys increment optins (not conversions/revenue).
     */
    public function upsertConversion(string $clickId, ?float $payout, ?float $value, ?string $eventKey = null): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $isOptIn = ConversionOptInClassifier::isOptIn($eventKey);
        $revenue = $isOptIn ? 0.0 : ($payout !== null ? (float) $payout : ($value !== null ? (float) $value : 0.0));
        $conversionsDelta = $isOptIn ? 0 : 1;
        $optinsDelta = $isOptIn ? 1 : 0;
        $hasOptinsCol = $this->summaryTableHasOptinsColumn();
        if ($isOptIn && !$hasOptinsCol) {
            // Column missing: still store conversion row, but skip summary until migration runs
            return;
        }

        $row = null;
        $rowTable = null;
        $hasPersistedFlag = false;
        foreach (\SimpleKuma\Database\ClicksTableResolver::getClickLookupTables($this->db) as $clickTable) {
            $tableHasFlag = StatsExclusionFlag::columnExists($this->db, $clickTable);
            $flagSelect = $tableHasFlag ? ', exclude_from_stats' : '';
            $stmt = $this->db->prepare("
                SELECT campaign_id, traffic_source_id, offer_id, landing_page_id,
                       DATE(ts) as summary_date, HOUR(ts) as summary_hour, lp_click, cost, extra_json, ua, ip
                       {$flagSelect}
                FROM `{$clickTable}`
                WHERE click_id = ?
                LIMIT 1
            ");
            if (!$stmt || !$stmt->bind_param('s', $clickId) || !$stmt->execute()) {
                if ($stmt) {
                    $stmt->close();
                }
                continue;
            }
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $rowTable = $clickTable;
                $hasPersistedFlag = $tableHasFlag;
                break;
            }
        }
        if (!$row || $rowTable === null) {
            return;
        }

        $extraData = !empty($row['extra_json']) ? json_decode($row['extra_json'], true) : null;
        $trafficSourceId = $row['traffic_source_id'] !== null ? (int) $row['traffic_source_id'] : null;
        $ua = $row['ua'] !== null ? (string) $row['ua'] : null;
        $ip = $row['ip'] !== null ? (string) $row['ip'] : null;
        $isHiddenIp = $ip !== null && $ip !== '' && $this->hiddenIps()->isHidden($ip);
        $promoted = false;

        if ($hasPersistedFlag) {
            if ($isHiddenIp) {
                return;
            }
            if ((int)($row['exclude_from_stats'] ?? 0) === 1) {
                $promote = $this->db->prepare(
                    "UPDATE `{$rowTable}`
                     SET exclude_from_stats = 0
                     WHERE click_id = ? AND exclude_from_stats = 1
                     LIMIT 1"
                );
                if ($promote === false) {
                    error_log('DailySummaryUpdater::upsertConversion promotion prepare failed: ' . $this->db->error);
                    return;
                }
                $promote->bind_param('s', $clickId);
                if (!$promote->execute()) {
                    error_log('DailySummaryUpdater::upsertConversion promotion failed: ' . $promote->error);
                    $promote->close();
                    return;
                }
                $promoted = $promote->affected_rows > 0;
                $promote->close();
            }
        } elseif ($this->shouldSkipStatsAggregate(
            $trafficSourceId,
            is_array($extraData) ? $extraData : null,
            $ua,
            $ip
        )) {
            return;
        }

        $campaignId = (int) $row['campaign_id'];
        $offerId = $row['offer_id'] !== null ? (int) $row['offer_id'] : null;
        $landingPageId = $row['landing_page_id'] !== null ? (int) $row['landing_page_id'] : null;
        $summaryDate = $row['summary_date'];

        if ($promoted) {
            $this->upsertClickForSummaryDate(
                $campaignId,
                $trafficSourceId,
                $offerId,
                $landingPageId,
                !empty($row['lp_click']) ? 1 : 0,
                isset($row['cost']) && $row['cost'] !== null ? (float)$row['cost'] : null,
                (string)$summaryDate
            );
            if ($this->tokenTableExists() && is_array($extraData)) {
                $this->upsertTokenAggregatesForClick(
                    $campaignId,
                    $trafficSourceId,
                    (string)$summaryDate,
                    $extraData,
                    !empty($row['lp_click']) ? 1 : 0,
                    isset($row['cost']) && $row['cost'] !== null ? (float)$row['cost'] : null,
                    0,
                    0.0,
                    $ua,
                    $ip,
                    true,
                    0,
                    isset($row['summary_hour']) ? (int)$row['summary_hour'] : null
                );
            }
        }

        if ($isOptIn) {
            $upd = $this->db->prepare("
                UPDATE clicks_daily_summary
                SET optins = optins + 1,
                    updated_at = NOW()
                WHERE campaign_id = ?
                  AND (traffic_source_id <=> ?)
                  AND (offer_id <=> ?)
                  AND (landing_page_id <=> ?)
                  AND summary_date = ?
                LIMIT 1
            ");
            if ($upd) {
                $upd->bind_param('iiiis', $campaignId, $trafficSourceId, $offerId, $landingPageId, $summaryDate);
                $upd->execute();
                $updated = $upd->affected_rows > 0;
                $upd->close();
            } else {
                $updated = false;
            }
            if (!$updated) {
                $ins = $this->db->prepare("
                    INSERT INTO clicks_daily_summary
                    (campaign_id, traffic_source_id, offer_id, landing_page_id, summary_date, conversions, optins, revenue)
                    VALUES (?, ?, ?, ?, ?, 0, 1, 0)
                ");
                if ($ins) {
                    $ins->bind_param('iiiis', $campaignId, $trafficSourceId, $offerId, $landingPageId, $summaryDate);
                    if (!$ins->execute()) {
                        error_log('DailySummaryUpdater::upsertConversion optin insert failed: ' . $ins->error);
                    }
                    $ins->close();
                }
            }
        } else {
            $upd = $this->db->prepare("
                UPDATE clicks_daily_summary
                SET conversions = conversions + 1,
                    revenue = revenue + ?,
                    profit = (revenue + ?) - cost,
                    roi = CASE WHEN cost > 0 THEN (((revenue + ?) - cost) / cost) * 100 ELSE NULL END,
                    updated_at = NOW()
                WHERE campaign_id = ?
                  AND (traffic_source_id <=> ?)
                  AND (offer_id <=> ?)
                  AND (landing_page_id <=> ?)
                  AND summary_date = ?
                LIMIT 1
            ");
            if ($upd) {
                $upd->bind_param(
                    'dddiiiis',
                    $revenue,
                    $revenue,
                    $revenue,
                    $campaignId,
                    $trafficSourceId,
                    $offerId,
                    $landingPageId,
                    $summaryDate
                );
                $upd->execute();
                $updated = $upd->affected_rows > 0;
                $upd->close();
            } else {
                $updated = false;
            }

            if (!$updated) {
                $ins = $this->db->prepare("
                    INSERT INTO clicks_daily_summary
                    (campaign_id, traffic_source_id, offer_id, landing_page_id, summary_date, conversions, revenue)
                    VALUES (?, ?, ?, ?, ?, 1, ?)
                ");
                if (!$ins) {
                    error_log('DailySummaryUpdater::upsertConversion prepare failed: ' . $this->db->error);
                    return;
                }
                $ins->bind_param('iiiisd', $campaignId, $trafficSourceId, $offerId, $landingPageId, $summaryDate, $revenue);
                if (!$ins->execute()) {
                    error_log('DailySummaryUpdater::upsertConversion execute failed: ' . $ins->error);
                }
                $ins->close();
            }
        }

        if ($this->tokenTableExists() && is_array($extraData)) {
            $this->upsertTokenAggregatesForClick(
                $campaignId,
                $trafficSourceId,
                $summaryDate,
                $extraData,
                0,
                null,
                $conversionsDelta,
                $revenue,
                $ua,
                $ip,
                $hasPersistedFlag,
                $optinsDelta,
                isset($row['summary_hour']) ? (int)$row['summary_hour'] : null
            );
        }
    }

    /**
     * After a conversion delete: subtract from clicks_daily_summary so Offer/Landing Page Performance stay in sync.
     * Call this BEFORE deleting the conversion record so we have click_id and revenue.
     */
    public function removeConversion(string $clickId, ?float $payout, ?float $value, ?string $eventKey = null): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $isOptIn = ConversionOptInClassifier::isOptIn($eventKey);
        $revenue = $isOptIn ? 0.0 : ($payout !== null ? (float) $payout : ($value !== null ? (float) $value : 0.0));
        $conversionsDelta = $isOptIn ? 0 : -1;
        $optinsDelta = $isOptIn ? -1 : 0;
        $hasOptinsCol = $this->summaryTableHasOptinsColumn();
        if ($isOptIn && !$hasOptinsCol) {
            return;
        }

        $row = $this->loadClickSummaryRow($clickId);
        if (!$row) {
            return;
        }

        $campaignId = (int) $row['campaign_id'];
        $trafficSourceId = $row['traffic_source_id'] !== null ? (int) $row['traffic_source_id'] : null;
        $offerId = $row['offer_id'] !== null ? (int) $row['offer_id'] : null;
        $landingPageId = $row['landing_page_id'] !== null ? (int) $row['landing_page_id'] : null;
        $summaryDate = $row['summary_date'];

        if ($isOptIn) {
            $upd = $this->db->prepare("
                UPDATE clicks_daily_summary SET
                    optins = GREATEST(0, CAST(optins AS SIGNED) - 1),
                    updated_at = NOW()
                WHERE campaign_id = ? AND (traffic_source_id <=> ?) AND (offer_id <=> ?) AND (landing_page_id <=> ?) AND summary_date = ?
            ");
            if (!$upd) {
                error_log('DailySummaryUpdater::removeConversion optin prepare failed: ' . $this->db->error);
                return;
            }
            $upd->bind_param('iiiis', $campaignId, $trafficSourceId, $offerId, $landingPageId, $summaryDate);
        } else {
            $upd = $this->db->prepare("
                UPDATE clicks_daily_summary SET
                    conversions = GREATEST(0, CAST(conversions AS SIGNED) - 1),
                    revenue = GREATEST(0, revenue - ?),
                    profit = GREATEST(0, revenue - ?) - cost,
                    roi = CASE WHEN cost > 0 THEN ((GREATEST(0, revenue - ?) - cost) / cost) * 100 ELSE NULL END,
                    updated_at = NOW()
                WHERE campaign_id = ? AND (traffic_source_id <=> ?) AND (offer_id <=> ?) AND (landing_page_id <=> ?) AND summary_date = ?
            ");
            if (!$upd) {
                error_log('DailySummaryUpdater::removeConversion prepare failed: ' . $this->db->error);
                return;
            }
            $upd->bind_param('dddiiiis', $revenue, $revenue, $revenue, $campaignId, $trafficSourceId, $offerId, $landingPageId, $summaryDate);
        }
        if (!$upd->execute()) {
            error_log('DailySummaryUpdater::removeConversion execute failed: ' . $upd->error);
        }
        $upd->close();

        $extraData = !empty($row['extra_json']) ? json_decode($row['extra_json'], true) : null;
        if ($this->tokenTableExists() && is_array($extraData)) {
            $this->upsertTokenAggregatesForClick(
                $campaignId,
                $trafficSourceId,
                $summaryDate,
                $extraData,
                0,
                null,
                $conversionsDelta,
                -$revenue,
                $row['ua'] ?? null,
                $row['ip'] ?? null,
                !empty($row['_stats_flag_present'])
                    && (int)($row['exclude_from_stats'] ?? 1) === 0,
                $optinsDelta,
                isset($row['summary_hour']) ? (int)$row['summary_hour'] : null
            );
        }
    }

    /**
     * Determine whether extraData or UA indicates a bot click.
     *
     * @param array<string, mixed>|null $extraData
     */
    public static function isBotClick(?array $extraData, ?string $ua = null): bool
    {
        if ($ua !== null && CampaignStatsExpressions::isFacebookCrawlerUa($ua)) {
            return true;
        }
        if (is_array($extraData) && isset($extraData['bot']) && is_array($extraData['bot'])) {
            $classification = $extraData['bot']['classification'] ?? null;
            if ($classification !== null && $classification !== 'human') {
                return true;
            }
            if (!empty($extraData['bot']['exclude_from_stats'])) {
                return true;
            }
        }
        return false;
    }

    private function summaryTableHasBotClicksColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (!$this->tableExists()) {
            return $cached = false;
        }
        $check = $this->db->query("SHOW COLUMNS FROM clicks_daily_summary LIKE 'bot_clicks'");
        return $cached = ($check && $check->num_rows > 0);
    }

    private function tokenTableHasBotClicksColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (!$this->tokenTableExists()) {
            return $cached = false;
        }
        $check = $this->db->query("SHOW COLUMNS FROM clicks_stats_by_token_daily LIKE 'bot_clicks'");
        return $cached = ($check && $check->num_rows > 0);
    }

    private function summaryTableHasOptinsColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (!$this->tableExists()) {
            return $cached = false;
        }
        $check = $this->db->query("SHOW COLUMNS FROM clicks_daily_summary LIKE 'optins'");
        return $cached = ($check && $check->num_rows > 0);
    }

    private function tokenTableHasOptinsColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (!$this->tokenTableExists()) {
            return $cached = false;
        }
        $check = $this->db->query("SHOW COLUMNS FROM clicks_stats_by_token_daily LIKE 'optins'");
        return $cached = ($check && $check->num_rows > 0);
    }

    /**
     * Before deleting a click: decrement clicks_daily_summary / token aggregates for that click.
     */
    public function removeClick(string $clickId): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $row = $this->loadClickSummaryRow($clickId, true);
        if (!$row) {
            return;
        }

        $extraData = !empty($row['extra_json']) ? json_decode($row['extra_json'], true) : null;
        $trafficSourceId = $row['traffic_source_id'] !== null ? (int) $row['traffic_source_id'] : null;
        $ua = isset($row['ua']) && $row['ua'] !== null ? (string) $row['ua'] : null;
        $ip = isset($row['ip']) && $row['ip'] !== null ? (string) $row['ip'] : null;
        // Hidden-IP omit calls this after list insertion. Persisted inclusion is the
        // source of truth so converted real clicks with missing tokens are removed too.
        $skipForClassification = !empty($row['_stats_flag_present'])
            ? (int)($row['exclude_from_stats'] ?? 1) === 1
            : CampaignStatsExpressions::shouldExcludeClickFromStats(
                $trafficSourceId,
                is_array($extraData) ? $extraData : null,
                $ua
            );
        if ($skipForClassification) {
            return;
        }

        $campaignId = (int) $row['campaign_id'];
        $offerId = $row['offer_id'] !== null ? (int) $row['offer_id'] : null;
        $landingPageId = $row['landing_page_id'] !== null ? (int) $row['landing_page_id'] : null;
        $summaryDate = $row['summary_date'];
        $lpClick = !empty($row['lp_click']) ? 1 : 0;
        $cost = isset($row['cost']) && $row['cost'] !== null ? (float) $row['cost'] : 0.0;
        $lpInc = ($lpClick && $landingPageId !== null && $offerId !== null) ? 1 : 0;
        $directInc = ($lpClick && $landingPageId === null) ? 1 : 0;

        $upd = $this->db->prepare("
            UPDATE clicks_daily_summary
            SET clicks = GREATEST(0, CAST(clicks AS SIGNED) - 1),
                lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) - ?),
                direct_clicks = GREATEST(0, CAST(direct_clicks AS SIGNED) - ?),
                cost = GREATEST(0, cost - ?),
                profit = GREATEST(0, revenue) - GREATEST(0, cost - ?),
                roi = CASE
                    WHEN GREATEST(0, cost - ?) > 0
                    THEN ((GREATEST(0, revenue) - GREATEST(0, cost - ?)) / GREATEST(0, cost - ?)) * 100
                    ELSE NULL
                END,
                updated_at = NOW()
            WHERE campaign_id = ?
              AND (traffic_source_id <=> ?)
              AND (offer_id <=> ?)
              AND (landing_page_id <=> ?)
              AND summary_date = ?
        ");
        if ($upd) {
            $upd->bind_param(
                'iidddddiiiis',
                $lpInc,
                $directInc,
                $cost,
                $cost,
                $cost,
                $cost,
                $cost,
                $campaignId,
                $trafficSourceId,
                $offerId,
                $landingPageId,
                $summaryDate
            );
            if (!$upd->execute()) {
                error_log("DailySummaryUpdater::removeClick execute failed: " . $upd->error);
            }
            $upd->close();
        }

        if ($this->tokenTableExists() && is_array($extraData)) {
            $this->decrementTokenVisitorsForClick(
                $campaignId,
                $trafficSourceId,
                $summaryDate,
                $extraData,
                $lpClick,
                $cost,
                isset($row['summary_hour']) ? (int)$row['summary_hour'] : null
            );
        }
    }

    /**
     * Re-add a click to aggregates (e.g. after un-hiding an IP). Uses the click's actual summary_date.
     * Skips Meta approval/crawler clicks; caller must ensure IP is no longer on the hide list.
     */
    public function restoreClick(string $clickId): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $row = $this->loadClickSummaryRow($clickId, true);
        if (!$row) {
            return;
        }

        $extraData = !empty($row['extra_json']) ? json_decode($row['extra_json'], true) : null;
        $trafficSourceId = $row['traffic_source_id'] !== null ? (int) $row['traffic_source_id'] : null;
        $ua = isset($row['ua']) && $row['ua'] !== null ? (string) $row['ua'] : null;
        $ip = isset($row['ip']) && $row['ip'] !== null ? (string) $row['ip'] : null;
        $skipForClassification = !empty($row['_stats_flag_present'])
            ? (int)($row['exclude_from_stats'] ?? 1) === 1
            : CampaignStatsExpressions::shouldExcludeClickFromStats(
                $trafficSourceId,
                is_array($extraData) ? $extraData : null,
                $ua
            );
        if ($skipForClassification
            || ($ip !== null && $ip !== '' && $this->hiddenIps()->isHidden($ip))) {
            return;
        }

        $campaignId = (int) $row['campaign_id'];
        $offerId = $row['offer_id'] !== null ? (int) $row['offer_id'] : null;
        $landingPageId = $row['landing_page_id'] !== null ? (int) $row['landing_page_id'] : null;
        $summaryDate = (string) $row['summary_date'];
        $lpClick = !empty($row['lp_click']) ? 1 : 0;
        $cost = isset($row['cost']) && $row['cost'] !== null ? (float) $row['cost'] : 0.0;
        $lpInc = ($lpClick && $landingPageId !== null && $offerId !== null) ? 1 : 0;
        $directInc = ($lpClick && $landingPageId === null) ? 1 : 0;

        $updated = $this->incrementSummaryClickRow(
            $campaignId,
            $trafficSourceId,
            $offerId,
            $landingPageId,
            $summaryDate,
            1, // clickInc (excluded clicks already skipped above)
            $lpInc,
            $directInc,
            $cost
        );
        if (!$updated) {
            $ins = $this->db->prepare("
                INSERT INTO clicks_daily_summary
                (campaign_id, traffic_source_id, offer_id, landing_page_id, summary_date, clicks, lp_clicks, direct_clicks, cost)
                VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
            ");
            if ($ins) {
                $ins->bind_param(
                    'iiiisiid',
                    $campaignId,
                    $trafficSourceId,
                    $offerId,
                    $landingPageId,
                    $summaryDate,
                    $lpInc,
                    $directInc,
                    $cost
                );
                if (!$ins->execute()) {
                    $this->incrementSummaryClickRow(
                        $campaignId,
                        $trafficSourceId,
                        $offerId,
                        $landingPageId,
                        $summaryDate,
                        1,
                        $lpInc,
                        $directInc,
                        $cost
                    );
                }
                $ins->close();
            }
        }

        if ($this->tokenTableExists() && is_array($extraData)) {
            $this->upsertTokenAggregatesForClick(
                $campaignId,
                $trafficSourceId,
                $summaryDate,
                $extraData,
                $lpClick,
                $cost,
                0,
                0.0,
                $ua,
                $ip,
                !empty($row['_stats_flag_present']),
                0,
                isset($row['summary_hour']) ? (int)$row['summary_hour'] : null
            );
        }
    }

    /**
     * Load click dimensions for summary adjustments (active then archive).
     *
     * @return array<string, mixed>|null
     */
    private function loadClickSummaryRow(string $clickId, bool $includeCostAndLp = false): ?array
    {
        $selectExtra = $includeCostAndLp ? ', cost, lp_click' : '';
        foreach (\SimpleKuma\Database\ClicksTableResolver::getClickLookupTables($this->db) as $clickTable) {
            $hasPersistedFlag = StatsExclusionFlag::columnExists($this->db, $clickTable);
            $flagSelect = $hasPersistedFlag ? ', exclude_from_stats' : '';
            $stmt = $this->db->prepare("
                SELECT campaign_id, traffic_source_id, offer_id, landing_page_id,
                       DATE(ts) as summary_date, HOUR(ts) as summary_hour, extra_json, ua, ip{$flagSelect}{$selectExtra}
                FROM `{$clickTable}`
                WHERE click_id = ?
                LIMIT 1
            ");
            if (!$stmt || !$stmt->bind_param('s', $clickId) || !$stmt->execute()) {
                if ($stmt) {
                    $stmt->close();
                }
                continue;
            }
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $row['_stats_flag_present'] = $hasPersistedFlag;
                return $row;
            }
        }
        return null;
    }

    /**
     * Decrement token-level visitors/lp_clicks/cost for a deleted click (daily + hourly).
     */
    private function decrementTokenVisitorsForClick(
        int $campaignId,
        ?int $trafficSourceId,
        string $summaryDate,
        array $extraData,
        int $lpClick,
        float $cost,
        ?int $summaryHour = null
    ): void {
        $tokens = $this->extractTokensFromExtraData($extraData);
        if ($tokens === []) {
            return;
        }
        $lpInc = ($lpClick === 1) ? 1 : 0;
        $isBot = self::isBotClick($extraData, null);
        $botInc = $isBot ? 1 : 0;
        $hasBotClicks = $this->tokenTableHasBotClicksColumn();
        $hour = self::normalizeSummaryHour($summaryHour);

        $this->decrementTokenVisitorRows(
            'clicks_stats_by_token_daily',
            $tokens,
            $campaignId,
            $trafficSourceId,
            $summaryDate,
            null,
            $lpInc,
            $cost,
            $botInc,
            $hasBotClicks
        );
        if ($hour !== null && $this->tokenHourlyTableExists()) {
            $this->decrementTokenVisitorRows(
                'clicks_stats_by_token_hourly',
                $tokens,
                $campaignId,
                $trafficSourceId,
                $summaryDate,
                $hour,
                $lpInc,
                $cost,
                $botInc,
                $hasBotClicks
            );
        }
    }

    /**
     * @param list<array{param: string, value: string}> $tokens
     */
    private function decrementTokenVisitorRows(
        string $table,
        array $tokens,
        int $campaignId,
        ?int $trafficSourceId,
        string $summaryDate,
        ?int $hour,
        int $lpInc,
        float $cost,
        int $botInc,
        bool $hasBotClicks
    ): void {
        $hourClause = $hour !== null ? ' AND hour = ?' : '';
        foreach ($tokens as $t) {
            if ($hasBotClicks) {
                $stmt = $this->db->prepare("
                    UPDATE {$table}
                    SET visitors = GREATEST(0, CAST(visitors AS SIGNED) - 1),
                        lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) - ?),
                        cost = GREATEST(0, cost - ?),
                        bot_clicks = GREATEST(0, CAST(bot_clicks AS SIGNED) - ?),
                        updated_at = NOW()
                    WHERE campaign_id = ?
                      AND summary_date = ?
                      AND token_param = ?
                      AND token_value = ?
                      AND (traffic_source_id <=> ?)
                      {$hourClause}
                ");
                if (!$stmt) {
                    continue;
                }
                $param = $t['param'];
                $value = $t['value'];
                if ($hour !== null) {
                    $stmt->bind_param('idiisssii', $lpInc, $cost, $botInc, $campaignId, $summaryDate, $param, $value, $trafficSourceId, $hour);
                } else {
                    $stmt->bind_param('idiisssi', $lpInc, $cost, $botInc, $campaignId, $summaryDate, $param, $value, $trafficSourceId);
                }
            } else {
                $stmt = $this->db->prepare("
                    UPDATE {$table}
                    SET visitors = GREATEST(0, CAST(visitors AS SIGNED) - 1),
                        lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) - ?),
                        cost = GREATEST(0, cost - ?),
                        updated_at = NOW()
                    WHERE campaign_id = ?
                      AND summary_date = ?
                      AND token_param = ?
                      AND token_value = ?
                      AND (traffic_source_id <=> ?)
                      {$hourClause}
                ");
                if (!$stmt) {
                    continue;
                }
                $param = $t['param'];
                $value = $t['value'];
                if ($hour !== null) {
                    $stmt->bind_param('idisssii', $lpInc, $cost, $campaignId, $summaryDate, $param, $value, $trafficSourceId, $hour);
                } else {
                    $stmt->bind_param('idisssi', $lpInc, $cost, $campaignId, $summaryDate, $param, $value, $trafficSourceId);
                }
            }
            $stmt->execute();
            $stmt->close();
        }
    }
}
