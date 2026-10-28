<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;

/**
 * On-write Deep Funnel path logging + step/path daily pre-agg.
 * Isolated from clicks_daily_summary grain (Overview stays summary-first).
 */
final class FunnelPathTracker
{
    private mysqli $db;
    private bool $tablesReady = false;
    private bool $tablesChecked = false;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function tablesExist(): bool
    {
        if ($this->tablesChecked) {
            return $this->tablesReady;
        }
        $this->tablesChecked = true;
        $r1 = $this->db->query("SHOW TABLES LIKE 'clicks_funnel_step_daily'");
        $r2 = $this->db->query("SHOW TABLES LIKE 'clicks_funnel_path_daily'");
        $this->tablesReady = ($r1 && $r1->num_rows > 0 && $r2 && $r2->num_rows > 0);
        return $this->tablesReady;
    }

    /**
     * Record LP level entry / hop (DeepFunnel only).
     */
    public function recordLevelEntry(
        string $clickId,
        int $campaignId,
        int $level,
        int $slot,
        int $landingPageId,
        float $cost = 0.0
    ): void {
        if ($level < 1 || !$this->tablesExist()) {
            return;
        }

        $click = $this->loadClick($clickId);
        if (!$click) {
            return;
        }

        $extra = json_decode($click['extra_json'] ?? '{}', true) ?? [];
        $path = isset($extra['funnel_path']) && is_array($extra['funnel_path']) ? $extra['funnel_path'] : [];
        $oldPathKey = (string) ($extra['funnel_path_key'] ?? '');

        $last = $path !== [] ? $path[count($path) - 1] : null;
        if (is_array($last)
            && (int) ($last['level'] ?? 0) === $level
            && (int) ($last['slot'] ?? 0) === $slot
            && (int) ($last['lp_id'] ?? 0) === $landingPageId
        ) {
            return;
        }

        if (is_array($last) && empty($last['offer_id'])) {
            $this->bumpStep(
                $campaignId,
                $this->summaryDateFromClick($click),
                (int) $last['level'],
                (int) $last['slot'],
                (int) ($last['lp_id'] ?? 0),
                0,
                0,
                1,
                0,
                0,
                0.0,
                0.0
            );
        }

        $path[] = [
            'level' => $level,
            'slot' => $slot,
            'lp_id' => $landingPageId,
            'ts' => gmdate('c'),
        ];
        $newPathKey = $this->pathKeyFromHops($path);
        $extra['funnel_path'] = $path;
        $extra['funnel_path_key'] = $newPathKey;

        $this->saveExtraAndLanding($clickId, $extra, $landingPageId);
        $summaryDate = $this->summaryDateFromClick($click);

        $costToAdd = ($level === 1 && $oldPathKey === '') ? $cost : 0.0;
        $this->bumpStep(
            $campaignId,
            $summaryDate,
            $level,
            $slot,
            $landingPageId,
            0,
            1,
            0,
            0,
            0,
            0.0,
            $costToAdd
        );

        if ($oldPathKey !== '') {
            $this->bumpPath($campaignId, $summaryDate, $oldPathKey, -1, 0, 0, 0.0, 0.0);
        }
        $this->bumpPath($campaignId, $summaryDate, $newPathKey, 1, 0, 0, 0.0, $costToAdd);
    }

    /**
     * Record offer exit (append o:slot to path).
     */
    public function recordOfferExit(
        string $clickId,
        int $campaignId,
        int $offerSlot,
        int $offerId
    ): void {
        if (!$this->tablesExist()) {
            return;
        }

        $click = $this->loadClick($clickId);
        if (!$click) {
            return;
        }

        $extra = json_decode($click['extra_json'] ?? '{}', true) ?? [];
        $path = isset($extra['funnel_path']) && is_array($extra['funnel_path']) ? $extra['funnel_path'] : [];
        $oldPathKey = (string) ($extra['funnel_path_key'] ?? '');

        $last = $path !== [] ? $path[count($path) - 1] : null;
        if (is_array($last) && !empty($last['offer_id'])) {
            return;
        }

        $summaryDate = $this->summaryDateFromClick($click);

        if (is_array($last) && empty($last['offer_id'])) {
            $this->bumpStep(
                $campaignId,
                $summaryDate,
                (int) $last['level'],
                (int) $last['slot'],
                (int) ($last['lp_id'] ?? 0),
                0,
                0,
                1,
                0,
                0,
                0.0,
                0.0
            );
        }

        $path[] = [
            'level' => 0,
            'slot' => $offerSlot,
            'offer_id' => $offerId,
            'ts' => gmdate('c'),
        ];
        $newPathKey = $this->pathKeyFromHops($path);
        $extra['funnel_path'] = $path;
        $extra['funnel_path_key'] = $newPathKey;
        $this->saveExtraAndLanding($clickId, $extra, null);

        $this->bumpStep(
            $campaignId,
            $summaryDate,
            0,
            $offerSlot,
            0,
            $offerId,
            1,
            0,
            1,
            0,
            0.0,
            0.0
        );

        if ($oldPathKey !== '') {
            $this->bumpPath($campaignId, $summaryDate, $oldPathKey, -1, 0, 0, 0.0, 0.0);
        }
        $this->bumpPath($campaignId, $summaryDate, $newPathKey, 1, 1, 0, 0.0, 0.0);
    }

    public function recordConversion(string $clickId, float $revenue = 0.0): void
    {
        if (!$this->tablesExist()) {
            return;
        }
        $click = $this->loadClick($clickId);
        if (!$click) {
            return;
        }
        $extra = json_decode($click['extra_json'] ?? '{}', true) ?? [];
        $pathKey = (string) ($extra['funnel_path_key'] ?? '');
        if ($pathKey === '') {
            return;
        }
        $campaignId = (int) $click['campaign_id'];
        $summaryDate = $this->summaryDateFromClick($click);
        $this->bumpPath($campaignId, $summaryDate, $pathKey, 0, 0, 1, $revenue, 0.0);

        $path = isset($extra['funnel_path']) && is_array($extra['funnel_path']) ? $extra['funnel_path'] : [];
        $last = $path !== [] ? $path[count($path) - 1] : null;
        if (is_array($last) && !empty($last['offer_id'])) {
            $this->bumpStep(
                $campaignId,
                $summaryDate,
                0,
                (int) ($last['slot'] ?? 1),
                0,
                (int) $last['offer_id'],
                0,
                0,
                0,
                1,
                $revenue,
                0.0
            );
        }
    }

    /**
     * Resolve offer slot number for a campaign offer id (for path tagging).
     */
    public static function offerSlotForId(array $campaign, int $offerId): int
    {
        $config = FunnelRotation::getConfig($campaign);
        $offers = $config['offers'] ?? [];
        if ($offers === []) {
            $rotation = $campaign['rotation_json'] ?? [];
            if (is_string($rotation)) {
                $rotation = json_decode($rotation, true) ?? [];
            }
            $offers = $rotation['offers'] ?? $rotation['lp_path']['offers'] ?? [];
        }
        $enabled = [];
        foreach (array_values(is_array($offers) ? $offers : []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['enabled']) && $item['enabled'] === false) {
                continue;
            }
            $enabled[] = $item;
            $slot = isset($item['slot']) ? (int) $item['slot'] : count($enabled);
            if ((int) ($item['id'] ?? 0) === $offerId) {
                return max(1, $slot);
            }
        }
        return 1;
    }

    /** @param list<array<string,mixed>> $hops */
    private function pathKeyFromHops(array $hops): string
    {
        $parts = [];
        foreach ($hops as $hop) {
            if (!empty($hop['offer_id'])) {
                $parts[] = 'o:' . (int) ($hop['slot'] ?? 1);
            } else {
                $parts[] = (int) ($hop['level'] ?? 0) . ':' . (int) ($hop['slot'] ?? 0);
            }
        }
        $key = implode('>', $parts);
        return strlen($key) > 191 ? substr($key, 0, 191) : $key;
    }

    private function loadClick(string $clickId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT click_id, campaign_id, landing_page_id, offer_id, extra_json, DATE(ts) AS summary_date, cost
             FROM clicks WHERE click_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function summaryDateFromClick(array $click): string
    {
        return (string) ($click['summary_date'] ?? gmdate('Y-m-d'));
    }

    private function saveExtraAndLanding(string $clickId, array $extra, ?int $landingPageId): void
    {
        $json = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        if ($landingPageId !== null) {
            $stmt = $this->db->prepare('UPDATE clicks SET extra_json = ?, landing_page_id = ? WHERE click_id = ?');
            if ($stmt) {
                $stmt->bind_param('sis', $json, $landingPageId, $clickId);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }
        $stmt = $this->db->prepare('UPDATE clicks SET extra_json = ? WHERE click_id = ?');
        if ($stmt) {
            $stmt->bind_param('ss', $json, $clickId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function bumpStep(
        int $campaignId,
        string $summaryDate,
        int $level,
        int $slot,
        int $landingPageId,
        int $offerId,
        int $entries,
        int $continues,
        int $offerClicks,
        int $conversions,
        float $revenue,
        float $cost
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO clicks_funnel_step_daily
                (campaign_id, summary_date, level, slot, landing_page_id, offer_id, entries, continues, offer_clicks, conversions, revenue, cost)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                entries = entries + VALUES(entries),
                continues = continues + VALUES(continues),
                offer_clicks = offer_clicks + VALUES(offer_clicks),
                conversions = conversions + VALUES(conversions),
                revenue = revenue + VALUES(revenue),
                cost = cost + VALUES(cost)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param(
            'isiiiiiiiddd',
            $campaignId,
            $summaryDate,
            $level,
            $slot,
            $landingPageId,
            $offerId,
            $entries,
            $continues,
            $offerClicks,
            $conversions,
            $revenue,
            $cost
        );
        $stmt->execute();
        $stmt->close();
    }

    private function bumpPath(
        int $campaignId,
        string $summaryDate,
        string $pathKey,
        int $visitorsDelta,
        int $offerClicks,
        int $conversions,
        float $revenue,
        float $cost
    ): void {
        if ($pathKey === '') {
            return;
        }
        $visitorsInsert = max(0, $visitorsDelta);
        $stmt = $this->db->prepare(
            'INSERT INTO clicks_funnel_path_daily
                (campaign_id, summary_date, path_key, visitors, offer_clicks, conversions, revenue, cost)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                visitors = GREATEST(0, CAST(visitors AS SIGNED) + ?),
                offer_clicks = offer_clicks + VALUES(offer_clicks),
                conversions = conversions + VALUES(conversions),
                revenue = revenue + VALUES(revenue),
                cost = cost + VALUES(cost)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param(
            'issiiiddi',
            $campaignId,
            $summaryDate,
            $pathKey,
            $visitorsInsert,
            $offerClicks,
            $conversions,
            $revenue,
            $cost,
            $visitorsDelta
        );
        $stmt->execute();
        $stmt->close();
    }
}
