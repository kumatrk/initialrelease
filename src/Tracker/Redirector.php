<?php

declare(strict_types=1);

namespace SimpleKuma\Tracker;

use mysqli;

/**
 * Redirector
 * High-performance click tracking and redirection
 */
class Redirector
{
    private mysqli $db;

    public function __construct()
    {
        // Fast DB connection
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        
        if ($this->db->connect_error) {
            throw new \Exception('Database connection failed');
        }
    }

    /**
     * Handle incoming click
     */
    public function handleClick(int $campaignId): void
    {
        // 1. Load campaign (with minimal query for speed)
        $campaign = $this->loadCampaign($campaignId);
        
        if (!$campaign || $campaign['status'] !== 'active') {
            http_response_code(404);
            exit('Campaign not found or inactive');
        }

        // 2. Generate unique click ID
        $clickId = $this->generateClickId();

        // 3. Capture request data
        $clickData = $this->captureClickData($campaign, $clickId);

        // 4. Resolve destination URL
        $destinationUrl = $this->resolveDestination($campaign);

        // 5. Apply param pass-through
        $destinationUrl = $this->applyPassThrough($destinationUrl, $campaign, $clickData);

        // 6. Store click in database (async if possible)
        $this->storeClick($clickData);

        // 7. Apply cloaking and redirect
        $this->performRedirect($destinationUrl, $campaign['referrer_mode'] ?? $campaign['cloaking_mode'] ?? 'none');
    }

    /**
     * Load campaign from database
     */
    private function loadCampaign(int $campaignId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, ts.cost_param_key, ts.cost_currency as ts_cost_currency
             FROM campaigns c
             LEFT JOIN traffic_sources ts ON c.traffic_source_id = ts.id
             WHERE c.id = ?"
        );
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $result = $stmt->get_result();
        $campaign = $result->fetch_assoc();

        if ($campaign) {
            $campaign['rotation_json'] = json_decode($campaign['rotation_json'] ?? '{}', true);
            $campaign['pass_through_json'] = json_decode($campaign['pass_through_json'] ?? '{}', true);
        }

        return $campaign ?: null;
    }

    /**
     * Generate unique click ID (UUID v4)
     */
    private function generateClickId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Capture click data from request
     */
    private function captureClickData(array $campaign, string $clickId): array
    {
        $params = $_GET;
        
        // Capture cost from traffic source parameter
        $cost = null;
        $costCurrency = null;
        
        if (!empty($campaign['cost_param_key']) && isset($params[$campaign['cost_param_key']])) {
            $cost = (float)$params[$campaign['cost_param_key']];
            $costCurrency = $campaign['ts_cost_currency'] ?? $campaign['currency'];
        } elseif ($campaign['default_cpc'] !== null) {
            // Fallback to campaign default CPC
            $cost = (float)$campaign['default_cpc'];
            $costCurrency = $campaign['currency'];
        }

        return [
            'campaign_id' => $campaign['id'],
            'click_id' => $clickId,
            'ts' => date('Y-m-d H:i:s'),
            'ts_hour' => date('Y-m-d H:00:00'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
            'cost' => $cost,
            'cost_currency' => $costCurrency,
            'extra_json' => json_encode($params),
        ];
    }

    /**
     * Resolve destination URL based on campaign flow and rotation
     */
    private function resolveDestination(array $campaign): string
    {
        $rotation = $campaign['rotation_json'] ?? [];
        $flowType = $campaign['flow_type'];

        if ($flowType === 'DTO') {
            // Direct to offer
            return $this->selectByWeight($rotation['offers'] ?? []);
        } elseif ($flowType === 'LP') {
            // LP flow (for now, just return first LP)
            $lps = $rotation['landing_pages'] ?? [];
            return $this->selectByWeight($lps);
        } elseif ($flowType === 'Split') {
            // Split test (return LP for now, offer logic comes later)
            $lps = $rotation['landing_pages'] ?? [];
            return $this->selectByWeight($lps);
        }

        return 'about:blank'; // Fallback
    }

    /**
     * Select URL by weight distribution
     */
    private function selectByWeight(array $items): string
    {
        if (empty($items)) {
            return 'about:blank';
        }

        // Simple weight selection
        $rand = mt_rand(1, 100);
        $cumulative = 0;

        foreach ($items as $item) {
            $cumulative += $item['weight'] ?? 0;
            if ($rand <= $cumulative) {
                return $this->getItemUrl($item['id'], $item);
            }
        }

        // Fallback to first item
        return $this->getItemUrl($items[0]['id'], $items[0]);
    }

    /**
     * Get URL for offer or landing page
     */
    private function getItemUrl(int $id, array $item): string
    {
        // If URL is already in item, use it
        if (!empty($item['url'])) {
            return $item['url'];
        }

        // Otherwise fetch from database
        $stmt = $this->db->prepare("SELECT url FROM offers WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row) {
            // Try landing pages
            $stmt = $this->db->prepare("SELECT url FROM landing_pages WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
        }

        return $row['url'] ?? 'about:blank';
    }

    /**
     * Apply parameter pass-through to destination URL
     */
    private function applyPassThrough(string $url, array $campaign, array $clickData): string
    {
        $passThrough = $campaign['pass_through_json'] ?? [];
        
        if (empty($passThrough) || !is_array($passThrough)) {
            return $url;
        }

        $params = $_GET;
        $urlParts = parse_url($url);
        $existingParams = [];
        
        if (!empty($urlParts['query'])) {
            parse_str($urlParts['query'], $existingParams);
        }

        // Add click_id
        $existingParams['click_id'] = $clickData['click_id'];

        // Add selected params
        foreach ($passThrough as $paramKey => $enabled) {
            if ($enabled && isset($params[$paramKey])) {
                $existingParams[$paramKey] = $params[$paramKey];
            }
        }

        // Rebuild URL
        $newQuery = http_build_query($existingParams);
        $baseUrl = $urlParts['scheme'] . '://' . $urlParts['host'] . ($urlParts['path'] ?? '/');
        
        return $baseUrl . ($newQuery ? '?' . $newQuery : '');
    }

    /**
     * Store click in database
     */
    private function storeClick(array $clickData): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO clicks 
             (campaign_id, click_id, ts, ts_hour, ip, ua, referrer, cost, cost_currency, extra_json) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            'issssssdss',
            $clickData['campaign_id'],
            $clickData['click_id'],
            $clickData['ts'],
            $clickData['ts_hour'],
            $clickData['ip'],
            $clickData['ua'],
            $clickData['referrer'],
            $clickData['cost'],
            $clickData['cost_currency'],
            $clickData['extra_json']
        );

        $stmt->execute();
    }

    /**
     * Perform redirect with referrer privacy (Kuma hop).
     */
    private function performRedirect(string $url, string $referrerMode): void
    {
        $mode = $referrerMode === 'none' ? null : ($referrerMode !== '' ? $referrerMode : null);
        \SimpleKuma\Tracking\KumaHopRedirect::redirect($url, $mode);
    }

    public function __destruct()
    {
        if ($this->db) {
            $this->db->close();
        }
    }
}

