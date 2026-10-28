<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;

/**
 * Deep Funnel inter-level hops (same click_id). Core only — not an addon.
 * Public hops resolve by opaque link_key (not level/slot numbers).
 */
final class FunnelHop
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function process(string $clickId, string $linkKey): void
    {
        $click = $this->getClick($clickId);
        if (!$click) {
            http_response_code(404);
            die('Click not found');
        }

        $campaign = $this->getCampaign((int) $click['campaign_id']);
        if (!$campaign || ($campaign['status'] ?? '') !== 'active') {
            http_response_code(403);
            die('Campaign not active');
        }

        if (!FunnelRotation::isDeepFunnelCampaign($campaign)) {
            http_response_code(400);
            die('Not a Deep Funnel campaign');
        }

        if (BotRoute::applies($campaign, BotRoute::visitFromClick($click))) {
            BotRoute::respond($campaign);
        }

        $config = FunnelRotation::getConfig($campaign);
        if (!$config) {
            http_response_code(500);
            die('Deep Funnel not configured');
        }

        $picked = FunnelRotation::resolveHopByLinkKey($config, $linkKey);
        if (!$picked) {
            http_response_code(404);
            die('Funnel destination not found');
        }

        $lpUrl = $this->getLandingPageUrl((int) $picked['id']);
        if (!$lpUrl) {
            http_response_code(404);
            die('Landing page not found');
        }

        $tracker = new FunnelPathTracker($this->db);
        $tracker->recordLevelEntry(
            $clickId,
            (int) $campaign['id'],
            (int) $picked['level'],
            (int) $picked['slot'],
            (int) $picked['id'],
            0.0
        );

        $sep = str_contains($lpUrl, '?') ? '&' : '?';
        $dest = $lpUrl . $sep . 'click_id=' . rawurlencode($clickId);

        header('Location: ' . $dest, true, 302);
        exit;
    }

    private function getClick(string $clickId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clicks WHERE click_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $clickId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function getCampaign(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM campaigns WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && isset($row['rotation_json']) && is_string($row['rotation_json'])) {
            $row['rotation_json'] = json_decode($row['rotation_json'], true) ?? [];
        }
        return $row ?: null;
    }

    private function getLandingPageUrl(int $id): ?string
    {
        $stmt = $this->db->prepare('SELECT url FROM landing_pages WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['url'] ?? null;
    }
}
