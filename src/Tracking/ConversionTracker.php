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

        $resolved = ConversionEventKey::resolveFromParams($data);
        $eventKey = $resolved['event_key'];
        $data['event_key'] = $eventKey;

        // Check for duplicate
        if ($this->isDuplicate(
            $clickId,
            $data['txid'] ?? null,
            $data['event_id'] ?? null,
            $eventKey,
            $click
        )) {
            return ['success' => false, 'message' => 'Duplicate conversion'];
        }

        // Store conversion
        if ($this->storeConversion($clickId, $data)) {
            return ['success' => true, 'message' => 'Conversion tracked', 'event_key' => $eventKey];
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

        $windowDays = (int)($this->settings->get('attribution_window_days', self::DEFAULT_ATTRIBUTION_WINDOW_DAYS));
        $windowSeconds = $windowDays * 24 * 60 * 60;

        return ($now - $clickTime) <= $windowSeconds;
    }

    /**
     * Check if conversion is duplicate.
     *
     * Rules:
     * - Same inbound event_id → duplicate
     * - Same click + same txid + same event_key → duplicate
     * - Same click + same txid + different event_key → allowed
     * - No txid/event_id: same click + same event_key (or both null) → duplicate (legacy),
     *   unless the campaign has allow_multiple_conversions enabled (Propush-style multi-earn)
     *
     * @param array<string, mixed> $click
     */
    private function isDuplicate(
        string $clickId,
        ?string $txid,
        ?string $eventId,
        ?string $eventKey,
        array $click
    ): bool {
        if ($eventId) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM conversions WHERE event_id = ?"
            );
            $stmt->bind_param('s', $eventId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ((int)($row['count'] ?? 0) > 0) {
                return true;
            }
        }

        $hasEventKeyColumn = $this->hasEventKeyColumn();

        if ($txid) {
            if ($hasEventKeyColumn) {
                if ($eventKey === null) {
                    $stmt = $this->db->prepare(
                        "SELECT COUNT(*) as count FROM conversions
                         WHERE click_id = ? AND txid = ? AND event_key IS NULL"
                    );
                    $stmt->bind_param('ss', $clickId, $txid);
                } else {
                    $stmt = $this->db->prepare(
                        "SELECT COUNT(*) as count FROM conversions
                         WHERE click_id = ? AND txid = ? AND event_key = ?"
                    );
                    $stmt->bind_param('sss', $clickId, $txid, $eventKey);
                }
            } else {
                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) as count FROM conversions WHERE click_id = ? AND txid = ?"
                );
                $stmt->bind_param('ss', $clickId, $txid);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ((int)($row['count'] ?? 0) > 0) {
                return true;
            }
        }

        // No txid or event_id: preserve one-conversion-per-click for same event_key (legacy),
        // unless campaign allows multiple conversions on the same click.
        if (!$txid && !$eventId) {
            if ($this->campaignAllowsMultipleConversions($click)) {
                return false;
            }
            if ($hasEventKeyColumn) {
                if ($eventKey === null) {
                    $stmt = $this->db->prepare(
                        "SELECT COUNT(*) as count FROM conversions
                         WHERE click_id = ? AND event_key IS NULL"
                    );
                    $stmt->bind_param('s', $clickId);
                } else {
                    $stmt = $this->db->prepare(
                        "SELECT COUNT(*) as count FROM conversions
                         WHERE click_id = ? AND event_key = ?"
                    );
                    $stmt->bind_param('ss', $clickId, $eventKey);
                }
            } else {
                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) as count FROM conversions WHERE click_id = ?"
                );
                $stmt->bind_param('s', $clickId);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ((int)($row['count'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $click
     */
    private function campaignAllowsMultipleConversions(array $click): bool
    {
        if (!$this->campaignsTableHasAllowMultipleConversions()) {
            return false;
        }
        $campaignId = isset($click['campaign_id']) ? (int)$click['campaign_id'] : 0;
        if ($campaignId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT allow_multiple_conversions FROM campaigns WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return !empty($row['allow_multiple_conversions']);
    }

    private function campaignsTableHasAllowMultipleConversions(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $check = $this->db->query("SHOW COLUMNS FROM campaigns LIKE 'allow_multiple_conversions'");
        $cached = ($check && $check->num_rows > 0);
        return $cached;
    }

    /**
     * Store conversion in database
     */
    private function storeConversion(string $clickId, array $data): bool
    {
        $txid = $data['txid'] ?? null;
        $eventId = $data['event_id'] ?? null;
        $eventKey = $data['event_key'] ?? null;
        $value = $data['value'] ?? null;
        $currency = $data['currency'] ?? 'USD';
        $status = $data['status'] ?? 'pending';
        $payout = $data['payout'] ?? null;
        $data['conversion_epoch_ms'] = (int) round(microtime(true) * 1000);
        $sourceJson = json_encode($data);

        $hasEventKeyColumn = $this->hasEventKeyColumn();

        if ($hasEventKeyColumn) {
            $stmt = $this->db->prepare(
                "INSERT INTO conversions
                (click_id, txid, event_id, event_key, value, currency, status, ts, payout, source_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)"
            );
            $stmt->bind_param(
                'ssssdssds',
                $clickId,
                $txid,
                $eventId,
                $eventKey,
                $value,
                $currency,
                $status,
                $payout,
                $sourceJson
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO conversions
                (click_id, txid, event_id, value, currency, status, ts, payout, source_json)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)"
            );
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
        }

        $success = $stmt->execute();

        if ($success) {
            $conversionId = $stmt->insert_id;
            $stmt->close();
            $this->firePostbacks($conversionId);
            $updater = new DailySummaryUpdater($this->db);
            $updater->upsertConversion($clickId, $payout, $value, $eventKey);
        } else {
            error_log('ConversionTracker: INSERT failed: ' . $stmt->error);
            $stmt->close();
        }

        return $success;
    }

    private function hasEventKeyColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $check = $this->db->query("SHOW COLUMNS FROM conversions LIKE 'event_key'");
        $cached = ($check && $check->num_rows > 0);
        return $cached;
    }

    /**
     * Fire postbacks for conversion (simplified for MVP)
     */
    private function firePostbacks(int $conversionId): void
    {
        $dispatcher = new PostbackDispatcher($this->db);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $dispatcher->firePostbacks($conversionId);
    }
}
