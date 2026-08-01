<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;
use SimpleKuma\Enrichment\BotDetector;
use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Stats\CampaignStatsExpressions;
use SimpleKuma\Stats\StatsExclusionFlag;

/**
 * Shared click INSERT + daily summary updates for origin Redirector and Edge ingest.
 */
final class ClickRecorder
{
    private mysqli $db;
    private SettingsManager $settings;

    public function __construct(mysqli $db, ?SettingsManager $settings = null)
    {
        $this->db = $db;
        $this->settings = $settings ?? new SettingsManager($db);
    }

    /**
     * @param array{
     *   campaign_id: int,
     *   click_id: string,
     *   params?: array<string, mixed>,
     *   cost?: array{cost?: float|null, currency?: string|null}|null,
     *   campaign?: array<string, mixed>,
     *   is_direct_to_offer?: bool,
     *   offer_id?: int|null,
     *   landing_page_id?: int|null,
     *   traffic_source_id?: int|null,
     *   slug_id?: int|null,
     *   redirect_rule_matched?: bool,
     *   ip?: string|null,
     *   ua?: string|null,
     *   referrer?: string|null,
     *   geo?: array{country?: ?string, region?: ?string, city?: ?string, postal?: ?string},
     *   device?: array<string, ?string>,
     *   source?: string
     * } $input
     * @return array{ok: bool, duplicate?: bool, message?: string}
     */
    public function record(array $input): array
    {
        $clickId = (string) ($input['click_id'] ?? '');
        $campaignId = (int) ($input['campaign_id'] ?? 0);
        if ($clickId === '' || $campaignId <= 0) {
            return ['ok' => false, 'message' => 'click_id and campaign_id are required'];
        }

        if ($this->clickExists($clickId)) {
            return ['ok' => true, 'duplicate' => true];
        }

        $params = is_array($input['params'] ?? null) ? $input['params'] : [];
        $cost = $input['cost'] ?? null;
        $campaign = is_array($input['campaign'] ?? null) ? $input['campaign'] : [];
        $isDirectToOffer = !empty($input['is_direct_to_offer']);
        $offerId = isset($input['offer_id']) ? (int) $input['offer_id'] : null;
        $landingPageId = isset($input['landing_page_id']) ? (int) $input['landing_page_id'] : null;
        $trafficSourceId = isset($input['traffic_source_id']) ? (int) $input['traffic_source_id'] : null;
        $slugId = isset($input['slug_id']) ? (int) $input['slug_id'] : null;
        $isRedirectRuleMatch = !empty($input['redirect_rule_matched']);

        $ip = $this->normalizeIp($input['ip'] ?? null);
        $ua = isset($input['ua']) ? (string) $input['ua'] : null;
        $referrer = isset($input['referrer']) ? (string) $input['referrer'] : null;

        $geoData = [
            'country' => $input['geo']['country'] ?? null,
            'region' => $input['geo']['region'] ?? null,
            'city' => $input['geo']['city'] ?? null,
            'postal' => $input['geo']['postal'] ?? null,
        ];
        $deviceData = [
            'device' => $input['device']['device'] ?? null,
            'device_brand' => $input['device']['device_brand'] ?? null,
            'device_model' => $input['device']['device_model'] ?? null,
            'os' => $input['device']['os'] ?? null,
            'os_version' => $input['device']['os_version'] ?? null,
            'browser' => $input['device']['browser'] ?? null,
            'browser_version' => $input['device']['browser_version'] ?? null,
        ];

        // Fill geo from MaxMind if Worker only sent country / nothing
        if ($ip && empty($geoData['country']) && $ip !== '::1' && $ip !== '127.0.0.1') {
            try {
                $geoLocator = new \SimpleKuma\Enrichment\GeoLocator($ip);
                $looked = $geoLocator->getGeoData();
                foreach (['country', 'region', 'city', 'postal'] as $k) {
                    if (empty($geoData[$k]) && !empty($looked[$k])) {
                        $geoData[$k] = $looked[$k];
                    }
                }
            } catch (\Throwable $e) {
                error_log('ClickRecorder geo: ' . $e->getMessage());
            }
        }

        $clickSource = (string) ($input['source'] ?? 'origin');
        if ($ua) {
            $missingDevice = empty($deviceData['device']) && empty($deviceData['os']) && empty($deviceData['browser']);
            // Edge Worker UA parse is coarse — always re-detect with Matomo for edge clicks
            if ($missingDevice || $clickSource === 'edge') {
                try {
                    $detector = new \SimpleKuma\Enrichment\DeviceDetector($ua);
                    $deviceData = $detector->getAll();
                } catch (\Throwable $e) {
                    error_log('ClickRecorder device: ' . $e->getMessage());
                }
            }
        }

        $extraData = [
            'custom_tokens' => [],
            'traffic_source_tokens' => [],
            'all_params' => $params,
            'redirect_rule_matched' => $isRedirectRuleMatch,
            'cookies' => [],
            'source' => $clickSource,
        ];

        $botDetection = BotDetector::detect($ua, $deviceData, $this->getBotDetectionOptions());
        $extraData['bot'] = BotDetector::toExtraJson($botDetection);

        foreach (['_fbc', '_fbp'] as $cookieKey) {
            if (!empty($params[$cookieKey])) {
                $extraData['cookies'][$cookieKey] = $params[$cookieKey];
            }
        }

        $customTokens = $campaign['custom_tokens_json'] ?? [];
        if (is_string($customTokens)) {
            $customTokens = json_decode($customTokens, true) ?? [];
        }
        if (!empty($customTokens) && is_array($customTokens)) {
            foreach ($customTokens as $token) {
                $parameter = $token['parameter'] ?? '';
                $name = $token['name'] ?? $parameter;
                if ($parameter !== '' && isset($params[$parameter])) {
                    $extraData['custom_tokens'][$parameter] = [
                        'name' => $name,
                        'value' => $params[$parameter],
                    ];
                }
            }
        }

        $placeholderPatterns = ['{value}', '{{value}}'];
        foreach ($params as $key => $value) {
            if ($key === 'Tf' || $key === 'k') {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            if (in_array(trim($value), $placeholderPatterns, true)) {
                continue;
            }
            $isCustomToken = false;
            foreach ($customTokens as $token) {
                if (($token['parameter'] ?? '') === $key) {
                    $isCustomToken = true;
                    break;
                }
            }
            if (!$isCustomToken) {
                $extraData['traffic_source_tokens'][$key] = $value;
                if ($key === 'fbclid' && $value !== '') {
                    $extraData['fbclid_first_seen_epoch_ms'] = (int) round(microtime(true) * 1000);
                }
            }
        }

        $lpClick = $isDirectToOffer ? 1 : 0;
        $trafficSourceColumnExists = $this->columnExists('clicks', 'traffic_source_id');
        $postalColumnExists = $this->columnExists('clicks', 'postal');
        $statsFlagColumnExists = StatsExclusionFlag::columnExists($this->db);
        $excludeFromStats = CampaignStatsExpressions::shouldExcludeClickFromStats(
            $trafficSourceId,
            $extraData,
            $ua
        ) ? 1 : 0;

        $statsFlagColumn = $statsFlagColumnExists ? ', exclude_from_stats' : '';
        $statsFlagValue = $statsFlagColumnExists ? ', ?' : '';
        $statsFlagType = $statsFlagColumnExists ? 'i' : '';

        $geoCols = 'country, region, city';
        $geoPlaceholders = '?, ?, ?';
        $geoParamTypes = 'sss';
        $geoBind = [$geoData['country'], $geoData['region'], $geoData['city']];
        if ($postalColumnExists) {
            $geoCols .= ', postal';
            $geoPlaceholders .= ', ?';
            $geoParamTypes .= 's';
            $geoBind[] = $geoData['postal'] ?? null;
        }

        if ($trafficSourceColumnExists) {
            $sql = "INSERT INTO clicks
                (campaign_id, slug_id, traffic_source_id, offer_id, landing_page_id, click_id, ts, ip, ua, referrer, {$geoCols},
                 device, device_brand, device_model, os, os_version, browser, browser_version, cost, cost_currency, extra_json{$statsFlagColumn}, ts_hour, lp_click, ts_lp)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, {$geoPlaceholders}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$statsFlagValue}, DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, "
                . ($isDirectToOffer ? 'NOW()' : 'NULL') . ')';
            $paramTypes = 'iiiiissss' . $geoParamTypes . 'sssssssdss' . $statsFlagType . 'i';
        } else {
            $sql = "INSERT INTO clicks
                (campaign_id, slug_id, offer_id, landing_page_id, click_id, ts, ip, ua, referrer, {$geoCols},
                 device, device_brand, device_model, os, os_version, browser, browser_version, cost, cost_currency, extra_json{$statsFlagColumn}, ts_hour, lp_click, ts_lp)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, {$geoPlaceholders}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$statsFlagValue}, DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), ?, "
                . ($isDirectToOffer ? 'NOW()' : 'NULL') . ')';
            $paramTypes = 'iiiissss' . $geoParamTypes . 'sssssssdss' . $statsFlagType . 'i';
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Failed to prepare click insert: ' . $this->db->error];
        }

        $costValue = is_array($cost) ? ($cost['cost'] ?? null) : null;
        $costCurrency = is_array($cost) ? ($cost['currency'] ?? null) : null;
        $extraJson = json_encode($extraData);

        $bindValues = $trafficSourceColumnExists
            ? [$campaignId, $slugId, $trafficSourceId, $offerId, $landingPageId, $clickId, $ip, $ua, $referrer]
            : [$campaignId, $slugId, $offerId, $landingPageId, $clickId, $ip, $ua, $referrer];
        $bindValues = array_merge($bindValues, $geoBind, [
            $deviceData['device'],
            $deviceData['device_brand'],
            $deviceData['device_model'],
            $deviceData['os'],
            $deviceData['os_version'],
            $deviceData['browser'],
            $deviceData['browser_version'],
            $costValue,
            $costCurrency,
            $extraJson,
        ]);
        if ($statsFlagColumnExists) {
            $bindValues[] = $excludeFromStats;
        }
        $bindValues[] = $lpClick;

        $stmt->bind_param($paramTypes, ...$bindValues);
        try {
            if (!$stmt->execute()) {
                // Race on unique click_id
                if ($this->clickExists($clickId)) {
                    return ['ok' => true, 'duplicate' => true];
                }
                return ['ok' => false, 'message' => 'Click insert failed: ' . $stmt->error];
            }
        } catch (\mysqli_sql_exception $e) {
            if ($this->clickExists($clickId)) {
                return ['ok' => true, 'duplicate' => true];
            }
            return ['ok' => false, 'message' => 'Click insert failed: ' . $e->getMessage()];
        }

        $updater = new DailySummaryUpdater($this->db);
        $updater->upsertClick(
            $campaignId,
            $trafficSourceId,
            $offerId,
            $landingPageId,
            $lpClick,
            $costValue !== null ? (float) $costValue : null,
            $extraData,
            $ua,
            $ip
        );
        $updater->upsertTokenAggregatesForClick(
            $campaignId,
            $trafficSourceId,
            gmdate('Y-m-d'),
            $extraData,
            $lpClick,
            $costValue !== null ? (float) $costValue : null,
            0,
            0.0,
            $ua,
            $ip
        );

        try {
            $sender = new MetaCapiPageViewSender($this->db);
            $fbc = $extraData['cookies']['_fbc'] ?? $params['_fbc'] ?? null;
            $fbp = $extraData['cookies']['_fbp'] ?? $params['_fbp'] ?? null;
            register_shutdown_function(static function () use ($sender, $campaignId, $clickId, $ip, $ua, $fbc, $fbp): void {
                $sender->maybeSendForClick($campaignId, $clickId, [
                    'ip' => $ip ?? '',
                    'ua' => $ua ?? '',
                    'fbc' => $fbc,
                    'fbp' => $fbp,
                ]);
            });
        } catch (\Throwable $e) {
            error_log('ClickRecorder Meta PageView: ' . $e->getMessage());
        }

        return ['ok' => true, 'duplicate' => false];
    }

    private function clickExists(string $clickId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM clicks WHERE click_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_row();
    }

    private function normalizeIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $anonymize = $this->settings->get('ip_anonymization', '0') === '1';
        if ($anonymize && $ip !== '::1' && $ip !== '127.0.0.1') {
            if (str_contains($ip, ':')) {
                $parts = explode(':', $ip);
                return implode(':', array_slice($parts, 0, 4)) . '::';
            }
            $octets = explode('.', $ip);
            if (count($octets) === 4) {
                $octets[3] = '0';
                return implode('.', $octets);
            }
        }
        return $ip;
    }

    /**
     * @return array{enabled: bool, exclude_known: bool, exclude_suspected: bool, check_headers: bool}
     */
    private function getBotDetectionOptions(): array
    {
        $defaults = [
            'bot_detection_enabled' => '1',
            'bot_exclude_known_from_stats' => '1',
            'bot_exclude_suspected_from_stats' => '0',
        ];
        $vals = $this->settings->getMany(array_keys($defaults), $defaults);

        return [
            'enabled' => ($vals['bot_detection_enabled'] ?? '1') === '1',
            'exclude_known' => ($vals['bot_exclude_known_from_stats'] ?? '1') === '1',
            'exclude_suspected' => ($vals['bot_exclude_suspected_from_stats'] ?? '0') === '1',
            'check_headers' => false,
        ];
    }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $t = $this->db->real_escape_string($table);
        $c = $this->db->real_escape_string($column);
        $result = $this->db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
        $cache[$key] = $result && $result->num_rows > 0;
        return $cache[$key];
    }
}
