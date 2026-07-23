<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Entity\TrafficSource;
use SimpleKuma\Utils\Formatter;

/**
 * Campaign-aware breakdown dimensions for Campaign Stats V2.
 *
 * Mirrors legacy campaign-stats token pickers: traffic source tokens (Meta, Google,
 * PropellerAds, etc.), campaign custom tokens, and tracker geo/device columns.
 */
final class CampaignStatsDimensionRegistry
{
    /** @var list<string> Params that are not useful breakdown dimensions */
    private const EXCLUDED_PARAMS = ['fbclid', 'gclid', 'wbraid', 'gbraid', 'msclkid', 'cost', 'tf'];

    /** @var array<string, array{key: string, label: string, group: string}> */
    private const CORE = [
        'offer' => ['key' => 'offer', 'label' => 'Offer', 'group' => 'core'],
        'landing' => ['key' => 'landing', 'label' => 'Landing Page', 'group' => 'core'],
        'date' => ['key' => 'date', 'label' => 'Date', 'group' => 'core'],
    ];

    /** @var array<string, array{key: string, label: string, group: string}> */
    private const TRACKER = [
        'country' => ['key' => 'country', 'label' => 'Location (Country)', 'group' => 'tracker'],
        'region' => ['key' => 'region', 'label' => 'State/Region', 'group' => 'tracker'],
        'city' => ['key' => 'city', 'label' => 'City', 'group' => 'tracker'],
        'device' => ['key' => 'device', 'label' => 'Device', 'group' => 'tracker'],
        'device_brand' => ['key' => 'device_brand', 'label' => 'Device Brand', 'group' => 'tracker'],
        'device_model' => ['key' => 'device_model', 'label' => 'Device Model', 'group' => 'tracker'],
        'os' => ['key' => 'os', 'label' => 'Operating System', 'group' => 'tracker'],
        'os_version' => ['key' => 'os_version', 'label' => 'OS Version', 'group' => 'tracker'],
        'browser' => ['key' => 'browser', 'label' => 'Browser', 'group' => 'tracker'],
        'browser_version' => ['key' => 'browser_version', 'label' => 'Browser Version', 'group' => 'tracker'],
        'isp' => ['key' => 'isp', 'label' => 'ISP', 'group' => 'tracker'],
        'ip' => ['key' => 'ip', 'label' => 'IP Address', 'group' => 'tracker'],
    ];

    /**
     * @param array<string, mixed> $campaign
     * @return list<array{key: string, label: string, group: string, traffic_source?: string}>
     */
    public static function availableForCampaign(
        array $campaign,
        TrafficSource $trafficSourceEntity,
        mysqli $db,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $timezone = 'UTC'
    ): array {
        $out = [];
        $seen = [];

        foreach (self::CORE as $item) {
            $out[] = $item;
            $seen[$item['key']] = true;
        }

        foreach (self::TRACKER as $item) {
            if (isset($seen[$item['key']])) {
                continue;
            }
            $out[] = $item;
            $seen[$item['key']] = true;
        }

        $utcFrom = null;
        $utcTo = null;
        if ($dateFrom !== null && $dateFrom !== '' && $dateTo !== null && $dateTo !== '') {
            $utcRange = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
            $utcFrom = $utcRange['from'];
            $utcTo = $utcRange['to'];
        }

        $trafficSourceIds = self::resolveTrafficSourceIds(
            $campaign,
            $trafficSourceEntity,
            $db,
            $utcFrom,
            $utcTo
        );

        foreach ($trafficSourceIds as $tsId) {
            $ts = $trafficSourceEntity->getById($tsId);
            if ($ts === null) {
                continue;
            }

            $tsName = (string)($ts['name'] ?? 'Traffic Source');
            $exclude = self::excludedParamsForTrafficSource($ts);

            foreach (self::decodeTokenList($ts['tokens_json'] ?? []) as $token) {
                $param = trim((string)($token['parameter'] ?? $token['key'] ?? ''));
                if ($param === '' || isset($seen[$param]) || self::isExcludedParam($param, $exclude)) {
                    continue;
                }

                $label = trim((string)($token['name'] ?? ''));
                if ($label === '') {
                    $label = self::humanizeToken($param);
                }

                if (count($trafficSourceIds) > 1) {
                    $label .= ' (' . $tsName . ')';
                }

                $out[] = [
                    'key' => $param,
                    'label' => $label,
                    'group' => 'traffic_source',
                    'traffic_source' => $tsName,
                ];
                $seen[$param] = true;
            }
        }

        foreach (self::decodeTokenList($campaign['custom_tokens_json'] ?? []) as $token) {
            $param = trim((string)($token['parameter'] ?? ''));
            if ($param === '' || isset($seen[$param])) {
                continue;
            }

            $label = trim((string)($token['name'] ?? ''));
            if ($label === '') {
                $label = self::humanizeToken($param);
            }

            $out[] = [
                'key' => $param,
                'label' => $label,
                'group' => 'campaign',
            ];
            $seen[$param] = true;
        }

        return $out;
    }

    /** @var list<string> */
    private const NON_FILTER_KEYS = ['offer', 'landing', 'date'];

    /**
     * Dimensions usable as advanced token/value filters (excludes offer, landing, date).
     *
     * @param array<string, mixed> $campaign
     * @return list<array{key: string, label: string, group: string}>
     */
    public static function filterableDimensionsForCampaign(
        array $campaign,
        TrafficSource $trafficSourceEntity,
        mysqli $db,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $timezone = 'UTC'
    ): array {
        $out = [];
        foreach (self::availableForCampaign($campaign, $trafficSourceEntity, $db, $dateFrom, $dateTo, $timezone) as $dim) {
            if (in_array($dim['key'], self::NON_FILTER_KEYS, true)) {
                continue;
            }
            $out[] = [
                'key' => $dim['key'],
                'label' => $dim['label'],
                'group' => $dim['group'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $campaign
     * @return list<string>
     */
    public static function filterableKeysForCampaign(
        array $campaign,
        TrafficSource $trafficSourceEntity,
        mysqli $db,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $timezone = 'UTC'
    ): array {
        $keys = [];
        foreach (self::filterableDimensionsForCampaign($campaign, $trafficSourceEntity, $db, $dateFrom, $dateTo, $timezone) as $dim) {
            $keys[] = $dim['key'];
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $campaign
     * @return list<string>
     */
    public static function allowedKeysForCampaign(
        array $campaign,
        TrafficSource $trafficSourceEntity,
        mysqli $db,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $timezone = 'UTC'
    ): array {
        $keys = [];
        foreach (self::availableForCampaign($campaign, $trafficSourceEntity, $db, $dateFrom, $dateTo, $timezone) as $dim) {
            $keys[] = $dim['key'];
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    public static function normalizeDimensionList(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $key = trim((string)$key);
            if ($key === '' || in_array($key, $out, true)) {
                continue;
            }
            $out[] = $key;
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }

    public static function humanizeToken(string $key): string
    {
        $key = str_replace(['_', '-'], ' ', $key);

        return ucwords($key);
    }

    /**
     * @param array<string, mixed> $campaign
     * @return list<int>
     */
    private static function resolveTrafficSourceIds(
        array $campaign,
        TrafficSource $trafficSourceEntity,
        mysqli $db,
        ?string $utcFrom,
        ?string $utcTo
    ): array {
        $campaignTsId = (int)($campaign['traffic_source_id'] ?? 0);
        if ($campaignTsId > 0) {
            return [$campaignTsId];
        }

        $detected = self::detectTrafficSourceIdsFromClicks($db, (int)($campaign['id'] ?? 0), $utcFrom, $utcTo);
        if ($detected !== []) {
            return $detected;
        }

        $ids = [];
        foreach ($trafficSourceEntity->getAll() as $ts) {
            $id = (int)($ts['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function detectTrafficSourceIdsFromClicks(
        mysqli $db,
        int $campaignId,
        ?string $utcFrom,
        ?string $utcTo
    ): array {
        if ($campaignId < 1 || !self::clicksHaveTrafficSourceColumn($db)) {
            return [];
        }

        $clicksTable = ClicksTableResolver::getStatsTable($db);
        $sql = "
            SELECT DISTINCT cl.traffic_source_id
            FROM {$clicksTable} cl
            WHERE cl.campaign_id = ?
              AND cl.traffic_source_id IS NOT NULL
        ";
        $types = 'i';
        $params = [$campaignId];

        if ($utcFrom !== null && $utcTo !== null) {
            $sql .= ' AND cl.ts >= ? AND cl.ts <= ?';
            $types .= 'ss';
            $params[] = $utcFrom;
            $params[] = $utcTo;
        }

        $sql .= ' ORDER BY cl.traffic_source_id';

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int)($row['traffic_source_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $stmt->close();

        return $ids;
    }

    private static function clicksHaveTrafficSourceColumn(mysqli $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $dbName = $db->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
        $column = 'traffic_source_id';
        $table = ClicksTableResolver::getStatsTable($db);

        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        if ($stmt === false) {
            $cache = false;

            return false;
        }

        $stmt->bind_param('sss', $dbName, $table, $column);
        $stmt->execute();
        $cache = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();

        return $cache;
    }

    /**
     * @param array<string, mixed> $trafficSource
     * @return list<string>
     */
    private static function excludedParamsForTrafficSource(array $trafficSource): array
    {
        $exclude = self::EXCLUDED_PARAMS;
        $costKey = strtolower(trim((string)($trafficSource['cost_param_key'] ?? '')));
        if ($costKey !== '' && !in_array($costKey, $exclude, true)) {
            $exclude[] = $costKey;
        }

        return $exclude;
    }

    private static function isExcludedParam(string $param, array $exclude): bool
    {
        return in_array(strtolower($param), $exclude, true);
    }

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    private static function decodeTokenList($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }
}
