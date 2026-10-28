<?php

declare(strict_types=1);

namespace SimpleKuma\Facebook;

use mysqli;
use SimpleKuma\Stats\CampaignStatsExpressions;

/**
 * Daily Meta impressions, reach, clicks, and spend.
 * The cost updater writes one row per ad or ad set per day.
 * Campaign Stats reads it only when a Meta column is visible, and only for ad / ad set rows.
 */
final class MetaDeliveryStore
{
    /**
     * CTR, CPC, and CPM from summed counts. Null when the divisor is zero.
     *
     * @return array{
     *   meta_impressions: int,
     *   meta_reach: int|null,
     *   meta_clicks: int,
     *   meta_ctr: float|null,
     *   meta_cpc: float|null,
     *   meta_cpm: float|null,
     *   meta_spend: float
     * }
     */
    public static function rates(int $impressions, int $clicks, float $spend, ?int $reach = null): array
    {
        return [
            'meta_impressions' => $impressions,
            'meta_reach' => $reach,
            'meta_clicks' => $clicks,
            'meta_ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
            'meta_cpc' => $clicks > 0 ? round($spend / $clicks, 4) : null,
            'meta_cpm' => $impressions > 0 ? round($spend / $impressions * 1000, 4) : null,
            'meta_spend' => round($spend, 4),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $rows keyed by ad or ad set id
     */
    public static function saveInsights(mysqli $db, int $integrationId, string $date, string $level, array $rows): int
    {
        if ($integrationId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return 0;
        }
        if ($level !== 'ad' && $level !== 'adset') {
            return 0;
        }

        $stmt = $db->prepare(
            'INSERT INTO meta_delivery_daily
                (ad_account_id, entity_level, entity_id, summary_date, entity_name, meta_campaign_id,
                 impressions, reach, clicks, spend)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                entity_name = VALUES(entity_name),
                meta_campaign_id = VALUES(meta_campaign_id),
                impressions = VALUES(impressions),
                reach = VALUES(reach),
                clicks = VALUES(clicks),
                spend = VALUES(spend)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Meta delivery table is not ready. Run Update database.');
        }

        $saved = 0;
        foreach ($rows as $entityId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) $entityId;
            $metaCampaignId = trim((string) ($row['campaign_id'] ?? ''));
            if (!ctype_digit($id) || $metaCampaignId === '' || !ctype_digit($metaCampaignId)) {
                continue;
            }
            $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 500);
            $impressions = max(0, (int) ($row['impressions'] ?? 0));
            $clicks = max(0, (int) ($row['clicks'] ?? 0));
            $spend = round((float) ($row['spend'] ?? 0), 4);
            $reach = $row['reach'] ?? null;
            $reachParam = ($reach === null || $reach === '') ? null : (string) max(0, (int) $reach);

            $stmt->bind_param(
                'isssssisid',
                $integrationId,
                $level,
                $id,
                $date,
                $name,
                $metaCampaignId,
                $impressions,
                $reachParam,
                $clicks,
                $spend
            );
            if ($stmt->execute()) {
                $saved++;
            }
        }
        $stmt->close();

        return $saved;
    }

    /**
     * Fill Meta columns on ad / ad set breakdown rows from the daily table.
     * Other dimensions are left alone. No clicks query.
     *
     * @param array<string, mixed>|null $campaign
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|float|null>|null}
     */
    public static function overlay(
        mysqli $db,
        ?array $campaign,
        string $dimension,
        array $rows,
        string $dateFrom,
        string $dateTo
    ): array {
        $unchanged = ['rows' => $rows, 'totals' => null];
        $dim = CampaignStatsExpressions::unwrapDimensionKey($dimension);
        $level = $dim === 'ad_name' ? 'ad' : ($dim === 'adset_name' ? 'adset' : '');
        if ($level === '' || $campaign === null) {
            return $unchanged;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            return $unchanged;
        }

        $fmcId = (int) ($campaign['facebook_marketing_campaign_id'] ?? 0);
        if ($fmcId <= 0) {
            return $unchanged;
        }
        $link = $db->prepare('SELECT meta_campaign_id FROM facebook_marketing_campaigns WHERE id = ? LIMIT 1');
        if ($link === false) {
            return $unchanged;
        }
        $link->bind_param('i', $fmcId);
        $link->execute();
        $linked = $link->get_result()->fetch_assoc();
        $link->close();
        $metaCampaignId = trim((string) ($linked['meta_campaign_id'] ?? ''));
        if ($metaCampaignId === '' || !ctype_digit($metaCampaignId)) {
            return $unchanged;
        }

        $stmt = $db->prepare(
            'SELECT entity_id, entity_name,
                    SUM(impressions) AS impressions,
                    SUM(clicks) AS clicks,
                    SUM(spend) AS spend,
                    CASE WHEN MIN(summary_date) = MAX(summary_date) THEN MAX(reach) ELSE NULL END AS reach
             FROM meta_delivery_daily
             WHERE meta_campaign_id = ? AND entity_level = ? AND summary_date >= ? AND summary_date <= ?
             GROUP BY entity_id, entity_name'
        );
        if ($stmt === false) {
            return $unchanged;
        }
        $stmt->bind_param('ssss', $metaCampaignId, $level, $dateFrom, $dateTo);
        $stmt->execute();
        $result = $stmt->get_result();
        $stored = [];
        while ($result && ($found = $result->fetch_assoc())) {
            $stored[] = $found;
        }
        $stmt->close();

        $byKey = [];
        $impressions = 0;
        $clicks = 0;
        $spend = 0.0;
        foreach ($stored as $found) {
            $impressions += (int) $found['impressions'];
            $clicks += (int) $found['clicks'];
            $spend += (float) $found['spend'];
            $metrics = self::rates(
                (int) $found['impressions'],
                (int) $found['clicks'],
                (float) $found['spend'],
                $found['reach'] === null ? null : (int) $found['reach']
            );
            $idKey = 'id:' . (string) $found['entity_id'];
            $byKey[$idKey] = $metrics;
            $name = trim((string) ($found['entity_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $nameKey = mb_strtolower($name);
            if (!isset($byKey[$nameKey])) {
                $byKey[$nameKey] = $metrics;
                continue;
            }
            $byKey[$nameKey] = self::rates(
                (int) $byKey[$nameKey]['meta_impressions'] + (int) $metrics['meta_impressions'],
                (int) $byKey[$nameKey]['meta_clicks'] + (int) $metrics['meta_clicks'],
                (float) $byKey[$nameKey]['meta_spend'] + (float) $metrics['meta_spend'],
                null
            );
        }

        foreach ($rows as &$row) {
            $group = trim((string) ($row['group'] ?? $row['group_key'] ?? ''));
            $hit = $byKey[mb_strtolower($group)] ?? $byKey['id:' . $group] ?? null;
            if ($hit === null) {
                $row['meta_impressions'] = null;
                $row['meta_reach'] = null;
                $row['meta_clicks'] = null;
                $row['meta_ctr'] = null;
                $row['meta_cpc'] = null;
                $row['meta_cpm'] = null;
                $row['meta_spend'] = null;
                continue;
            }
            foreach ($hit as $key => $value) {
                $row[$key] = $value;
            }
        }
        unset($row);

        $totals = null;
        if ($stored !== []) {
            $reach = count($stored) === 1 && $stored[0]['reach'] !== null ? (int) $stored[0]['reach'] : null;
            $totals = self::rates($impressions, $clicks, $spend, $reach);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }
}
