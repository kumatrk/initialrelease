<?php

namespace SimpleKuma\Tracking;

use SimpleKuma\Stats\CampaignStatsExpressions;
use SimpleKuma\Stats\StatsHiddenIpService;
use SimpleKuma\Stats\StatsExclusionFlag;

/**
 * On-write updates to clicks_daily_summary and clicks_stats_by_token_daily (plan: stats pre-aggregation).
 * Called after each click insert and after each conversion insert so aggregates stay current.
 * No cron: token table is updated only on-write (click + conversion).
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
        if (CampaignStatsExpressions::shouldExcludeClickFromStats($trafficSourceId, $extraData, $ua)) {
            return true;
        }
        if ($ip !== null && $ip !== '' && $this->hiddenIps()->isHidden($ip)) {
            return true;
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
     * On-write: UPSERT clicks_stats_by_token_daily for one click (or conversion-only update).
     * Single multi-row INSERT per call. Pass extraData as array when available to avoid JSON decode.
     *
     * @param array|null $extraDataAsArray extra_json as array (e.g. $extraData), or null to skip
     * @param int $conversionsDelta 1 for conversion event, 0 for click-only or opt-in
     * @param float $revenueDelta revenue for conversion event, 0 for click-only or opt-in
     * @param int $optinsDelta 1 for opt-in event, 0 otherwise
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
        int $optinsDelta = 0
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
        $lpInc = ($lpClick === 1) ? 1 : 0;
        $visitors = ($conversionsDelta === 0 && $optinsDelta === 0) ? 1 : 0;
        $hasOptins = $this->tokenTableHasOptinsColumn();

        // Negative conversion/optin deltas must UPDATE only — INSERT of -1 into UNSIGNED fails
        if ($conversionsDelta < 0 || $optinsDelta < 0) {
            foreach ($tokens as $t) {
                if ($hasOptins) {
                    $stmt = $this->db->prepare("
                        UPDATE clicks_stats_by_token_daily
                        SET conversions = GREATEST(0, CAST(conversions AS SIGNED) + ?),
                            optins = GREATEST(0, CAST(optins AS SIGNED) + ?),
                            revenue = GREATEST(0, revenue + ?),
                            updated_at = NOW()
                        WHERE campaign_id = ?
                          AND summary_date = ?
                          AND token_param = ?
                          AND token_value = ?
                          AND (traffic_source_id <=> ?)
                    ");
                    if (!$stmt) {
                        continue;
                    }
                    $param = $t['param'];
                    $value = $t['value'];
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
                } else {
                    $stmt = $this->db->prepare("
                        UPDATE clicks_stats_by_token_daily
                        SET conversions = GREATEST(0, CAST(conversions AS SIGNED) + ?),
                            revenue = GREATEST(0, revenue + ?),
                            updated_at = NOW()
                        WHERE campaign_id = ?
                          AND summary_date = ?
                          AND token_param = ?
                          AND token_value = ?
                          AND (traffic_source_id <=> ?)
                    ");
                    if (!$stmt) {
                        continue;
                    }
                    $param = $t['param'];
                    $value = $t['value'];
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
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        $rows = [];
        foreach ($tokens as $t) {
            $rows[] = [
                'campaign_id' => $campaignId,
                'summary_date' => $summaryDate,
                'token_param' => $t['param'],
                'token_value' => $t['value'],
                'traffic_source_id' => $trafficSourceId,
                'visitors' => $visitors,
                'lp_clicks' => ($conversionsDelta === 0 && $optinsDelta === 0) ? $lpInc : 0,
                'cost' => ($conversionsDelta === 0 && $optinsDelta === 0) ? $cost : 0.0,
                'conversions' => $conversionsDelta,
                'optins' => $optinsDelta,
                'revenue' => $revenueDelta,
            ];
        }

        $values = [];
        $types = '';
        $params = [];
        foreach ($rows as $u) {
            if ($hasOptins) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $types .= 'isssiiididi';
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
                $types .= 'isssiiidid';
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

        if ($hasOptins) {
            $sql = 'INSERT INTO clicks_stats_by_token_daily (campaign_id, summary_date, token_param, token_value, traffic_source_id, visitors, lp_clicks, cost, conversions, optins, revenue)
                VALUES ' . implode(', ', $values) . '
                ON DUPLICATE KEY UPDATE
                    visitors = GREATEST(0, CAST(visitors AS SIGNED) + VALUES(visitors)),
                    lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) + VALUES(lp_clicks)),
                    cost = GREATEST(0, cost + VALUES(cost)),
                    conversions = GREATEST(0, CAST(conversions AS SIGNED) + VALUES(conversions)),
                    optins = GREATEST(0, CAST(optins AS SIGNED) + VALUES(optins)),
                    revenue = GREATEST(0, revenue + VALUES(revenue)),
                    updated_at = NOW()';
        } else {
            $sql = 'INSERT INTO clicks_stats_by_token_daily (campaign_id, summary_date, token_param, token_value, traffic_source_id, visitors, lp_clicks, cost, conversions, revenue)
                VALUES ' . implode(', ', $values) . '
                ON DUPLICATE KEY UPDATE
                    visitors = GREATEST(0, CAST(visitors AS SIGNED) + VALUES(visitors)),
                    lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) + VALUES(lp_clicks)),
                    cost = GREATEST(0, cost + VALUES(cost)),
                    conversions = GREATEST(0, CAST(conversions AS SIGNED) + VALUES(conversions)),
                    revenue = GREATEST(0, revenue + VALUES(revenue)),
                    updated_at = NOW()';
        }
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log('DailySummaryUpdater::upsertTokenAggregatesForClick prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            error_log('DailySummaryUpdater::upsertTokenAggregatesForClick execute failed: ' . $stmt->error);
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
            gmdate('Y-m-d')
        );
    }

    private function upsertClickForSummaryDate(
        int $campaignId,
        ?int $trafficSourceId,
        ?int $offerId,
        ?int $landingPageId,
        int $lpClick,
        ?float $cost,
        string $summaryDate
    ): void {
        $cost = $cost !== null ? (float) $cost : 0.0;
        // LP rows (offer_id=null) get lp_clicks ONLY from upsertLpClickUpdate to avoid double-counting
        $lpInc = ($lpClick && $landingPageId !== null && $offerId !== null) ? 1 : 0;
        $directInc = ($lpClick && $landingPageId === null) ? 1 : 0;

        // MySQL UNIQUE allows multiple NULLs, so ON DUPLICATE KEY never fires when
        // landing_page_id/offer_id/traffic_source_id is NULL. Update with <=> then insert.
        $updated = $this->incrementSummaryClickRow(
            $campaignId,
            $trafficSourceId,
            $offerId,
            $landingPageId,
            $summaryDate,
            $lpInc,
            $directInc,
            $cost
        );
        if ($updated) {
            return;
        }

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
        if (!$stmt->execute()) {
            // Race: another request inserted the same nullable dimensional row — retry update
            if (!$this->incrementSummaryClickRow(
                $campaignId,
                $trafficSourceId,
                $offerId,
                $landingPageId,
                $summaryDate,
                $lpInc,
                $directInc,
                $cost
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
        int $lpInc,
        int $directInc,
        float $cost
    ): bool {
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
            1, // lpInc
            0,
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
                        1,
                        0,
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
                   extra_json, cost, ua, ip{$flagSelect}
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
                       DATE(ts) as summary_date, lp_click, cost, extra_json, ua, ip
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
                    0
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
                $optinsDelta
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
                $optinsDelta
            );
        }
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
                $cost
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
                !empty($row['_stats_flag_present'])
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
                       DATE(ts) as summary_date, extra_json, ua, ip{$flagSelect}{$selectExtra}
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
     * Decrement token-level visitors/lp_clicks/cost for a deleted click.
     */
    private function decrementTokenVisitorsForClick(
        int $campaignId,
        ?int $trafficSourceId,
        string $summaryDate,
        array $extraData,
        int $lpClick,
        float $cost
    ): void {
        $tokens = $this->extractTokensFromExtraData($extraData);
        if ($tokens === []) {
            return;
        }
        $lpInc = ($lpClick === 1) ? 1 : 0;
        foreach ($tokens as $t) {
            $stmt = $this->db->prepare("
                UPDATE clicks_stats_by_token_daily
                SET visitors = GREATEST(0, CAST(visitors AS SIGNED) - 1),
                    lp_clicks = GREATEST(0, CAST(lp_clicks AS SIGNED) - ?),
                    cost = GREATEST(0, cost - ?),
                    updated_at = NOW()
                WHERE campaign_id = ?
                  AND summary_date = ?
                  AND token_param = ?
                  AND token_value = ?
                  AND (traffic_source_id <=> ?)
            ");
            if (!$stmt) {
                continue;
            }
            $param = $t['param'];
            $value = $t['value'];
            $stmt->bind_param('idisssi', $lpInc, $cost, $campaignId, $summaryDate, $param, $value, $trafficSourceId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
