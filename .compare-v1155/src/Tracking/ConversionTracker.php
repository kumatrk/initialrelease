<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Settings\SettingsManager;

/**
 * Conversion Tracker
 * Handles conversion ingestion with deduplication and attribution window
 */
class ConversionTracker
{
    private mysqli $db;
    private ?SettingsManager $settings = null;
    private const DEFAULT_ATTRIBUTION_WINDOW_DAYS = 30;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->settings = new SettingsManager($db);
    }

    /**
     * Track a conversion
     */
    public function trackConversion(string $clickId, array $data): array
    {
        // Validate click exists
        $click = $this->getClick($clickId);

        if (!$click) {
            return ['success' => false, 'message' => 'Click not found'];
        }

        // Check attribution window
        if (!$this->isWithinAttributionWindow($click)) {
            return ['success' => false, 'message' => 'Conversion outside attribution window'];
        }

        // Check for duplicate
        if ($this->isDuplicate($clickId, $data['txid'] ?? null, $data['event_id'] ?? null)) {
            return ['success' => false, 'message' => 'Duplicate conversion'];
        }

        // Store conversion
        if ($this->storeConversion($clickId, $data)) {
            return ['success' => true, 'message' => 'Conversion tracked'];
        }

        return ['success' => false, 'message' => 'Failed to store conversion'];
    }

    /**
     * Get click by click_id
     */
    private function getClick(string $clickId): ?array
    {
        foreach (ClicksTableResolver::getClickLookupTables($this->db) as $table) {
            $sql = "SELECT * FROM `{$table}` WHERE click_id = ? LIMIT 1";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('s', $clickId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Check if conversion is within attribution window
     */
    private function isWithinAttributionWindow(array $click): bool
    {
        $clickTime = strtotime($click['ts']);
        $now = time();
        
        // Get attribution window from settings, fallback to default
        $windowDays = (int)($this->settings->get('attribution_window_days', self::DEFAULT_ATTRIBUTION_WINDOW_DAYS));
        $windowSeconds = $windowDays * 24 * 60 * 60;

        return ($now - $clickTime) <= $windowSeconds;
    }

    /**
     * Check if conversion is duplicate
     */
    private function isDuplicate(string $clickId, ?string $txid, ?string $eventId): bool
    {
        // Check by event_id if provided
        if ($eventId) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM conversions WHERE event_id = ?"
            );
            $stmt->bind_param('s', $eventId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] > 0) {
                return true;
            }
        }

        // Check by (click_id, txid) if txid provided
        if ($txid) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM conversions WHERE click_id = ? AND txid = ?"
            );
            $stmt->bind_param('ss', $clickId, $txid);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] > 0) {
                return true;
            }
        }

        // Check by click_id only if no txid or event_id
        if (!$txid && !$eventId) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM conversions WHERE click_id = ?"
            );
            $stmt->bind_param('s', $clickId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store conversion in database
     */
    private function storeConversion(string $clickId, array $data): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO conversions 
            (click_id, txid, event_id, value, currency, status, ts, payout, source_json) 
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)"
        );

        $txid = $data['txid'] ?? null;
        $eventId = $data['event_id'] ?? null;
        $value = $data['value'] ?? null;
        $currency = $data['currency'] ?? 'USD';
        $status = $data['status'] ?? 'pending';
        $payout = $data['payout'] ?? null;
        // Same epoch clock as fbclid_first_seen_epoch_ms for accurate Meta CAPI elapsed time
        $data['conversion_epoch_ms'] = (int) round(microtime(true) * 1000);
        $sourceJson = json_encode($data);

        $stmt->bind_param(
            'sssdssds',
            $clickId,
            $txid,
            $eventId,
            $value,
            $currency,
            $status,
            $payout,
            $sourceJson
        );

        $success = $stmt->execute();

        // Fire postbacks asynchronously (in production, use queue)
        if ($success) {
            $conversionId = $stmt->insert_id;
            $this->firePostbacks($conversionId);
            // On-write: update clicks_daily_summary (conversions, revenue) (plan: stats pre-aggregation Phase 3)
            $updater = new DailySummaryUpdater($this->db);
            $updater->upsertConversion($clickId, $payout, $value);
        }

        return $success;
    }

    /**
     * Fire postbacks for conversion (simplified for MVP)
     */
    private function firePostbacks(int $conversionId): void
    {
        // In production, this should queue the job
        // For MVP, we'll fire synchronously in background
        $dispatcher = new PostbackDispatcher($this->db);
        
        // Fire in background (simple approach)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        $dispatcher->firePostbacks($conversionId);
    }
}

