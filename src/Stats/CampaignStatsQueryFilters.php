<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;

/**
 * Optional server-side filters for Campaign Stats V2 queries.
 */
final class CampaignStatsQueryFilters
{
    /** @var list<string> */
    private const EXCLUDED_FILTER_KEYS = ['offer', 'landing', 'date'];

    public function __construct(
        public ?int $trafficSourceId = null,
        public ?int $offerId = null,
        public ?int $landingPageId = null,
        public ?string $tokenParam = null,
        public ?string $tokenValue = null,
    ) {
    }

    public static function fromRequest(array $query): self
    {
        $tsId = isset($query['traffic_source_id']) ? (int)$query['traffic_source_id'] : 0;
        $offerId = isset($query['offer_id']) ? (int)$query['offer_id'] : 0;
        $landingPageId = isset($query['landing_page_id']) ? (int)$query['landing_page_id'] : 0;
        $tokenParam = trim((string)($query['token_param'] ?? ''));
        $tokenValue = trim((string)($query['token_value'] ?? ''));

        return new self(
            $tsId > 0 ? $tsId : null,
            $offerId > 0 ? $offerId : null,
            $landingPageId > 0 ? $landingPageId : null,
            $tokenParam !== '' ? $tokenParam : null,
            $tokenValue !== '' ? $tokenValue : null,
        );
    }

    public function requiresScopedCost(): bool
    {
        return $this->trafficSourceId !== null
            || $this->offerId !== null
            || $this->landingPageId !== null
            || $this->hasTokenFilter();
    }

    public function hasTokenFilter(): bool
    {
        return $this->tokenParam !== null
            && $this->tokenValue !== null
            && trim($this->tokenValue) !== '';
    }

    public function hasActiveFilters(): bool
    {
        return $this->requiresScopedCost();
    }

    /**
     * @param list<string> $allowedTokenKeys
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    public function clickFilterSql(mysqli $db, string $clAlias = 'cl', array $allowedTokenKeys = []): array
    {
        $sql = '';
        $types = '';
        $params = [];

        if ($this->trafficSourceId !== null && self::clicksHaveColumn($db, 'traffic_source_id')) {
            $sql .= " AND {$clAlias}.traffic_source_id = ?";
            $types .= 'i';
            $params[] = $this->trafficSourceId;
        }

        if ($this->offerId !== null) {
            $sql .= " AND {$clAlias}.offer_id = ?";
            $types .= 'i';
            $params[] = $this->offerId;
        }

        if ($this->landingPageId !== null) {
            $sql .= " AND {$clAlias}.landing_page_id = ?";
            $types .= 'i';
            $params[] = $this->landingPageId;
        }

        if ($this->hasTokenFilter() && in_array($this->tokenParam, $allowedTokenKeys, true)) {
            $parts = CampaignStatsExpressions::groupKeyParts((string)$this->tokenParam);
            $sql .= " AND ({$parts['expr']}) = ?";
            $types .= 's';
            $params[] = trim((string)$this->tokenValue);
        }

        // Account-wide hide-from-stats IPs (FB approval already handled via validClickCase)
        $ipSql = (new StatsHiddenIpService($db))->exclusionSql($clAlias);
        if ($ipSql !== '') {
            $sql .= ' AND ' . $ipSql;
        }

        return [$sql, $types, $params];
    }

    /**
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    public function trafficSourceSql(mysqli $db, string $clAlias = 'cl'): array
    {
        return $this->clickFilterSql($db, $clAlias);
    }

    private static function clicksHaveColumn(mysqli $db, string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        $dbName = $db->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
        $table = ClicksTableResolver::getStatsTable($db);

        $stmt = $db->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        if ($stmt === false) {
            $cache[$column] = false;

            return false;
        }

        $stmt->bind_param('sss', $dbName, $table, $column);
        $stmt->execute();
        $cache[$column] = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();

        return $cache[$column];
    }
}
