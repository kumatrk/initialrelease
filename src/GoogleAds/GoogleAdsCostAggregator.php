<?php

declare(strict_types=1);

namespace SimpleKuma\GoogleAds;

use mysqli;

/**
 * Aggregates costs from manual clicks.cost and Google Ads API hourly cost tables.
 */
class GoogleAdsCostAggregator
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getTotalCostForClick(string $clickId): float
    {
        $clickQuery = $this->db->prepare('SELECT cost FROM clicks WHERE click_id = ?');
        $clickQuery->bind_param('s', $clickId);
        $clickQuery->execute();
        $clickResult = $clickQuery->get_result()->fetch_assoc();
        $manualCost = (float)($clickResult['cost'] ?? 0.0);

        return $manualCost + $this->getGoogleAdsCostForClick($clickId);
    }

    private function getGoogleAdsCostForClick(string $clickId): float
    {
        $campaignIdSql = GoogleAdsTokenHelper::campaignIdExtractSql('extra_json');

        $query = "
            SELECT
                {$campaignIdSql} AS campaign_id,
                JSON_UNQUOTE(JSON_EXTRACT(extra_json, '$.traffic_source_tokens.customer_id')) AS customer_id,
                DATE(ts) AS click_date,
                HOUR(ts) AS click_hour
            FROM clicks
            WHERE click_id = ?
        ";

        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if (!$result) {
            return 0.0;
        }

        $campaignId = trim((string)($result['campaign_id'] ?? ''));
        $customerId = trim((string)($result['customer_id'] ?? ''));
        $clickDate = $result['click_date'];
        $clickHour = (int)($result['click_hour'] ?? 0);

        if ($campaignId === '' || $campaignId === 'null') {
            return 0.0;
        }

        $integrationId = null;
        if ($customerId !== '' && $customerId !== 'null') {
            $integrationQuery = $this->db->prepare(
                "SELECT id FROM google_ads_integrations
                 WHERE customer_id = ? AND status = 'active'
                 LIMIT 1"
            );
            $integrationQuery->bind_param('s', $customerId);
            $integrationQuery->execute();
            $integrationResult = $integrationQuery->get_result()->fetch_assoc();
            $integrationId = isset($integrationResult['id']) ? (int)$integrationResult['id'] : null;
        }

        return $this->getCampaignHourlyCost($campaignId, $clickDate, $clickHour, $integrationId);
    }

    private function getCampaignHourlyCost(string $campaignId, string $date, int $hour, ?int $integrationId = null): float
    {
        if ($integrationId !== null) {
            $query = '
                SELECT SUM(delta_spend) AS total_cost
                FROM google_campaign_hourly_costs
                WHERE campaign_id = ? AND date = ? AND hour = ? AND integration_id = ?
            ';
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ssii', $campaignId, $date, $hour, $integrationId);
        } else {
            $query = '
                SELECT SUM(delta_spend) AS total_cost
                FROM google_campaign_hourly_costs
                WHERE campaign_id = ? AND date = ? AND hour = ?
            ';
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('ssi', $campaignId, $date, $hour);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $totalHourlySpend = (float)($result['total_cost'] ?? 0.0);

        if ($totalHourlySpend <= 0) {
            return 0.0;
        }

        $campaignIdMatch = GoogleAdsTokenHelper::campaignIdExtractSql('extra_json') . ' = ?';
        $countQuery = "
            SELECT COUNT(*) AS click_count
            FROM clicks
            WHERE {$campaignIdMatch}
                AND DATE(ts) = ?
                AND HOUR(ts) = ?
        ";

        $countStmt = $this->db->prepare($countQuery);
        $countStmt->bind_param('ssi', $campaignId, $date, $hour);
        $countStmt->execute();
        $countResult = $countStmt->get_result()->fetch_assoc();
        $clickCount = (int)($countResult['click_count'] ?? 1);

        if ($clickCount <= 0) {
            return 0.0;
        }

        return $totalHourlySpend / $clickCount;
    }

    public static function getUnifiedCostSQL(): string
    {
        $campaignIdSql = GoogleAdsTokenHelper::campaignIdExtractSql('cl2.extra_json');

        return "
            COALESCE(SUM(cl.cost), 0) +
            COALESCE((
                SELECT SUM(
                    ga_cost.delta_spend / GREATEST((
                        SELECT COUNT(*)
                        FROM clicks cl3
                        WHERE " . str_replace('cl2.extra_json', 'cl3.extra_json', $campaignIdSql) . " = ga_cost.campaign_id
                            AND DATE(cl3.ts) = ga_cost.date
                            AND HOUR(cl3.ts) = ga_cost.hour
                    ), 1)
                )
                FROM clicks cl2
                LEFT JOIN google_campaign_hourly_costs ga_cost ON
                    ga_cost.campaign_id = {$campaignIdSql}
                    AND ga_cost.date = DATE(cl2.ts)
                    AND ga_cost.hour = HOUR(cl2.ts)
                LEFT JOIN campaigns camp ON cl2.campaign_id = camp.id
                LEFT JOIN google_ads_integrations gai ON
                    (camp.google_ads_integration_id = gai.id OR gai.customer_id = ga_cost.customer_id)
                    AND gai.status = 'active'
                WHERE cl2.click_id = cl.click_id
                    AND {$campaignIdSql} IS NOT NULL
                    AND ga_cost.delta_spend IS NOT NULL
            ), 0)
        ";
    }

    public function getTotalGoogleAdsCost(string $startDate, string $endDate, ?string $campaignFilter = null): float
    {
        $bindTypes = 'ss';
        $bindValues = [$startDate, $endDate];

        if ($campaignFilter) {
            $bindTypes .= 'i';
            $bindValues[] = (int)$campaignFilter;
        }

        $campaignIdSql = GoogleAdsTokenHelper::campaignIdExtractSql('cl.extra_json');
        $campaignIdSub = GoogleAdsTokenHelper::campaignIdExtractSql('extra_json');

        $gaCostQuery = "
            SELECT COALESCE(SUM(
                ga_cost.delta_spend / GREATEST((
                    SELECT COUNT(*)
                    FROM clicks cl2
                    WHERE {$campaignIdSub} = ga_cost.campaign_id
                        AND DATE(cl2.ts) = ga_cost.date
                        AND HOUR(cl2.ts) = ga_cost.hour
                ), 1)
            ), 0) AS total_ga_cost
            FROM clicks cl
            INNER JOIN google_campaign_hourly_costs ga_cost ON
                ga_cost.campaign_id = {$campaignIdSql}
                AND ga_cost.date = DATE(cl.ts)
                AND ga_cost.hour = HOUR(cl.ts)
            LEFT JOIN google_ads_integrations gai ON
                gai.id = ga_cost.integration_id
                AND gai.status = 'active'
            WHERE cl.ts >= ? AND cl.ts <= ?
                " . ($campaignFilter ? 'AND cl.campaign_id = ?' : '') . "
                AND {$campaignIdSql} IS NOT NULL
        ";

        $gaStmt = $this->db->prepare($gaCostQuery);
        $gaStmt->bind_param($bindTypes, ...$bindValues);
        $gaStmt->execute();
        $gaResult = $gaStmt->get_result()->fetch_assoc();

        return (float)($gaResult['total_ga_cost'] ?? 0.0);
    }

    /**
     * Google Ads API spend only (not manual), grouped by Simple Kuma campaign_id.
     *
     * @param list<int> $campaignIds
     * @return array<int, float>
     */
    public function getGoogleAdsCostsByCampaignIds(array $campaignIds, string $utcFrom, string $utcTo): array
    {
        $campaignIds = array_values(array_unique(array_filter(array_map('intval', $campaignIds))));
        if ($campaignIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $campaignIdSql = GoogleAdsTokenHelper::campaignIdExtractSql('cl.extra_json');
        $campaignIdSub = GoogleAdsTokenHelper::campaignIdExtractSql('extra_json');

        $sql = "
            SELECT cl.campaign_id,
                   COALESCE(SUM(
                       ga_cost.delta_spend / GREATEST((
                           SELECT COUNT(*)
                           FROM clicks cl2
                           WHERE {$campaignIdSub} = ga_cost.campaign_id
                               AND DATE(cl2.ts) = ga_cost.date
                               AND HOUR(cl2.ts) = ga_cost.hour
                       ), 1)
                   ), 0) AS ga_cost
            FROM clicks cl
            INNER JOIN google_campaign_hourly_costs ga_cost ON
                ga_cost.campaign_id = {$campaignIdSql}
                AND ga_cost.date = DATE(cl.ts)
                AND ga_cost.hour = HOUR(cl.ts)
            LEFT JOIN google_ads_integrations gai ON
                gai.id = ga_cost.integration_id
                AND gai.status = 'active'
            WHERE cl.campaign_id IN ({$placeholders})
              AND cl.ts >= ? AND cl.ts <= ?
              AND {$campaignIdSql} IS NOT NULL
            GROUP BY cl.campaign_id
        ";

        $types = str_repeat('i', count($campaignIds)) . 'ss';
        $params = array_merge($campaignIds, [$utcFrom, $utcTo]);
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();

        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['campaign_id']] = (float)($row['ga_cost'] ?? 0);
        }

        return $out;
    }
}
