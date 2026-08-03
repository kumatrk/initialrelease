<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;

/**
 * Server-side saved breakdown views for Campaign Stats V2.
 */
final class CampaignStatsSavedViewRepository
{
    private const TABLE = 'campaign_stats_saved_views';

    public function __construct(private mysqli $db)
    {
    }

    public function tableExists(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $result = $this->db->query(
            "SHOW TABLES LIKE '" . self::TABLE . "'"
        );
        $cache = $result !== false && $result->num_rows > 0;

        return $cache;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUserCampaign(int $userId, int $campaignId): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT id, name, config, created_at, updated_at
             FROM ' . self::TABLE . '
             WHERE user_id = ? AND campaign_id = ?
             ORDER BY name ASC'
        );
        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param('ii', $userId, $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();

        $views = [];
        while ($row = $result->fetch_assoc()) {
            $views[] = $this->formatRow($row);
        }
        $stmt->close();

        return $views;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    public function create(int $userId, int $campaignId, string $name, array $config): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $json = json_encode($this->normalizeConfig($config), JSON_THROW_ON_ERROR);

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . ' (user_id, campaign_id, name, config, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('iiss', $userId, $campaignId, $name, $json);
        if (!$stmt->execute()) {
            $stmt->close();

            return null;
        }

        $id = (int)$stmt->insert_id;
        $stmt->close();

        return $this->getById($userId, $id);
    }

    public function delete(int $userId, int $viewId): bool
    {
        if (!$this->tableExists() || $viewId < 1) {
            return false;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE id = ? AND user_id = ?'
        );
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('ii', $viewId, $userId);
        $ok = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();

        return $ok;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getById(int $userId, int $viewId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, config, created_at, updated_at
             FROM ' . self::TABLE . '
             WHERE id = ? AND user_id = ?
             LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('ii', $viewId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->formatRow($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row): array
    {
        $config = json_decode((string)($row['config'] ?? '{}'), true);
        if (!is_array($config)) {
            $config = [];
        }
        $normalized = $this->normalizeConfig($config);

        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'dimensions' => $normalized['dimensions'],
            'columns' => $normalized['columns'],
            'density' => $normalized['density'],
            'showTotals' => $normalized['showTotals'],
            'trafficSourceId' => $normalized['trafficSourceId'],
            'offerId' => $normalized['offerId'],
            'landingPageId' => $normalized['landingPageId'],
            'tokenParam' => $normalized['tokenParam'],
            'tokenValue' => $normalized['tokenValue'],
            'created' => (string)($row['created_at'] ?? ''),
            'updated' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   dimensions: list<string>,
     *   columns: list<string>,
     *   density: string,
     *   showTotals: bool,
     *   trafficSourceId: int|null,
     *   offerId: int|null,
     *   landingPageId: int|null,
     *   tokenParam: string|null,
     *   tokenValue: string|null
     * }
     */
    private function normalizeConfig(array $config): array
    {
        $dimensions = $config['dimensions'] ?? ['offer'];
        if (!is_array($dimensions)) {
            $dimensions = ['offer'];
        }
        $dimensions = array_values(array_filter(array_map('strval', $dimensions)));

        $columns = $config['columns'] ?? [];
        if (!is_array($columns)) {
            $columns = [];
        }
        $columns = array_values(array_filter(array_map('strval', $columns)));

        $density = (string)($config['density'] ?? 'comfortable');
        if (!in_array($density, ['comfortable', 'compact'], true)) {
            $density = 'comfortable';
        }

        $trafficSourceId = isset($config['trafficSourceId']) ? (int)$config['trafficSourceId'] : null;
        if ($trafficSourceId !== null && $trafficSourceId < 1) {
            $trafficSourceId = null;
        }

        $offerId = isset($config['offerId']) ? (int)$config['offerId'] : null;
        if ($offerId !== null && $offerId < 1) {
            $offerId = null;
        }

        $landingPageId = isset($config['landingPageId']) ? (int)$config['landingPageId'] : null;
        if ($landingPageId !== null && $landingPageId < 1) {
            $landingPageId = null;
        }

        $tokenParam = isset($config['tokenParam']) ? trim((string)$config['tokenParam']) : null;
        if ($tokenParam === '') {
            $tokenParam = null;
        }
        $tokenValue = isset($config['tokenValue']) ? trim((string)$config['tokenValue']) : null;
        if ($tokenValue === '') {
            $tokenValue = null;
        }

        return [
            'dimensions' => $dimensions !== [] ? $dimensions : ['offer'],
            'columns' => $columns,
            'density' => $density,
            'showTotals' => ($config['showTotals'] ?? true) !== false,
            'trafficSourceId' => $trafficSourceId,
            'offerId' => $offerId,
            'landingPageId' => $landingPageId,
            'tokenParam' => $tokenParam,
            'tokenValue' => $tokenValue,
        ];
    }
}
