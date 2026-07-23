<?php

declare(strict_types=1);

namespace SimpleKuma\Entity;

use mysqli;
use SimpleKuma\Campaign\CampaignRotationReference;

/**
 * Offer Entity
 * Handles CRUD operations for offers
 */
class Offer
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $result = $this->db->query(
            "SELECT o.*, n.name as network_name 
             FROM offers o 
             LEFT JOIN networks n ON o.network_id = n.id 
             ORDER BY o.created_at DESC"
        );

        $offers = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                // Decode schedule_days JSON if present
                if (!empty($row['schedule_days'])) {
                    $row['schedule_days'] = json_decode($row['schedule_days'], true) ?? [];
                }
                $offers[] = $row;
            }
        }
        return $offers;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT o.*, n.name as network_name 
             FROM offers o 
             LEFT JOIN networks n ON o.network_id = n.id 
             WHERE o.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $offer = $result->fetch_assoc();
        
        if ($offer && !empty($offer['schedule_days'])) {
            $offer['schedule_days'] = json_decode($offer['schedule_days'], true) ?? [];
        }
        
        return $offer ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO offers 
            (name, url, payout_type, payout_value, network_id, notes, is_24_7, schedule_days, schedule_start_time, schedule_end_time, schedule_timezone, cap_enabled, cap_limit, cap_period, cap_timezone, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );

        $networkId = !empty($data['network_id']) ? (int)$data['network_id'] : null;
        $is247 = isset($data['is_24_7']) ? (int)$data['is_24_7'] : 1;
        $scheduleDays = isset($data['schedule_days']) && is_array($data['schedule_days']) ? json_encode($data['schedule_days']) : null;
        $scheduleStartTime = !empty($data['schedule_start_time']) ? $data['schedule_start_time'] : null;
        $scheduleEndTime = !empty($data['schedule_end_time']) ? $data['schedule_end_time'] : null;
        $scheduleTimezone = !empty($data['schedule_timezone']) ? $data['schedule_timezone'] : 'UTC';
        $capEnabled = isset($data['cap_enabled']) ? (int)$data['cap_enabled'] : 0;
        $capLimit = !empty($data['cap_limit']) ? (int)$data['cap_limit'] : null;
        $capPeriod = !empty($data['cap_period']) ? $data['cap_period'] : null;
        $capTimezone = !empty($data['cap_timezone']) ? $data['cap_timezone'] : 'UTC';

        $stmt->bind_param(
            'sssdisissssiiss',
            $data['name'],
            $data['url'],
            $data['payout_type'],
            $data['payout_value'],
            $networkId,
            $data['notes'],
            $is247,
            $scheduleDays,
            $scheduleStartTime,
            $scheduleEndTime,
            $scheduleTimezone,
            $capEnabled,
            $capLimit,
            $capPeriod,
            $capTimezone
        );

        $stmt->execute();
        return $stmt->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE offers 
            SET name = ?, url = ?, payout_type = ?, payout_value = ?, 
                network_id = ?, notes = ?, is_24_7 = ?, schedule_days = ?, 
                schedule_start_time = ?, schedule_end_time = ?, schedule_timezone = ?, 
                cap_enabled = ?, cap_limit = ?, cap_period = ?, cap_timezone = ?, updated_at = NOW()
            WHERE id = ?"
        );

        $networkId = !empty($data['network_id']) ? (int)$data['network_id'] : null;
        $is247 = isset($data['is_24_7']) ? (int)$data['is_24_7'] : 1;
        $scheduleDays = isset($data['schedule_days']) && is_array($data['schedule_days']) ? json_encode($data['schedule_days']) : null;
        $scheduleStartTime = !empty($data['schedule_start_time']) ? $data['schedule_start_time'] : null;
        $scheduleEndTime = !empty($data['schedule_end_time']) ? $data['schedule_end_time'] : null;
        $scheduleTimezone = !empty($data['schedule_timezone']) ? $data['schedule_timezone'] : 'UTC';
        $capEnabled = isset($data['cap_enabled']) ? (int)$data['cap_enabled'] : 0;
        $capLimit = !empty($data['cap_limit']) ? (int)$data['cap_limit'] : null;
        $capPeriod = !empty($data['cap_period']) ? $data['cap_period'] : null;
        $capTimezone = !empty($data['cap_timezone']) ? $data['cap_timezone'] : 'UTC';

        $stmt->bind_param(
            'sssdisissssiissi',
            $data['name'],
            $data['url'],
            $data['payout_type'],
            $data['payout_value'],
            $networkId,
            $data['notes'],
            $is247,
            $scheduleDays,
            $scheduleStartTime,
            $scheduleEndTime,
            $scheduleTimezone,
            $capEnabled,
            $capLimit,
            $capPeriod,
            $capTimezone,
            $id
        );

        return $stmt->execute();
    }

    /**
     * Returns a user-facing message when delete is blocked, or null when allowed.
     */
    public function getDeleteBlockReason(int $id): ?string
    {
        $campaigns = (new CampaignRotationReference($this->db))->getCampaignsUsingOffer($id);
        if ($campaigns === []) {
            return null;
        }

        return CampaignRotationReference::formatDeleteBlockMessage('offer', $campaigns);
    }

    public function delete(int $id): bool
    {
        if ($this->getDeleteBlockReason($id) !== null) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM offers WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Offer name is required';
        }

        if (empty($data['url'])) {
            $errors['url'] = 'Offer URL is required';
        } else {
            $url = trim($data['url']);
            // Basic validation: should start with http:// or https://
            // Allow tokens like {click_id}, {token1}, etc. in the URL
            if (!preg_match('/^https?:\/\//i', $url)) {
                $errors['url'] = 'Offer URL must start with http:// or https://';
            }
            // Check length
            if (strlen($url) > 2048) {
                $errors['url'] = 'Offer URL must be 2048 characters or less';
            }
        }

        if (empty($data['payout_type'])) {
            $errors['payout_type'] = 'Payout type is required';
        } elseif (!in_array($data['payout_type'], ['CPL', 'CPS', 'CPA'], true)) {
            $errors['payout_type'] = 'Invalid payout type';
        }

        if (!isset($data['payout_value']) || $data['payout_value'] < 0) {
            $errors['payout_value'] = 'Payout value must be 0 or greater';
        }

        // Validate CAP settings if enabled
        if (!empty($data['cap_enabled'])) {
            if (empty($data['cap_limit']) || !is_numeric($data['cap_limit']) || (int)$data['cap_limit'] <= 0) {
                $errors['cap_limit'] = 'Cap limit is required and must be a positive number when CAP is enabled';
            }
            if (empty($data['cap_period']) || !in_array($data['cap_period'], ['day', 'week', 'month', 'lifetime'], true)) {
                $errors['cap_period'] = 'Cap period is required and must be one of: day, week, month, lifetime';
            }
        }

        return $errors;
    }

    /**
     * Check if offer is currently available based on schedule
     * @param array $offer Offer data with scheduling fields
     * @return bool True if offer is available now, false otherwise
     */
    public function isAvailableNow(array $offer): bool
    {
        // If 24/7, always available
        if (!empty($offer['is_24_7'])) {
            return true;
        }

        // If no schedule configured, default to available
        if (empty($offer['schedule_days']) || empty($offer['schedule_start_time']) || empty($offer['schedule_end_time'])) {
            return true;
        }

        // Parse schedule days
        $scheduleDays = is_string($offer['schedule_days']) 
            ? json_decode($offer['schedule_days'], true) 
            : ($offer['schedule_days'] ?? []);
        
        if (!is_array($scheduleDays) || empty($scheduleDays)) {
            return true;
        }

        // Get timezone
        $timezone = $offer['schedule_timezone'] ?? 'UTC';
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('UTC');
        }

        // Get current time in offer's timezone
        $now = new \DateTime('now', $tz);
        $currentDay = strtolower($now->format('l')); // e.g., "monday"
        $currentTime = $now->format('H:i:s');

        // Check if current day is in schedule
        if (!in_array($currentDay, $scheduleDays, true)) {
            return false;
        }

        // Check if current time is within schedule range
        $startTime = $offer['schedule_start_time'];
        $endTime = $offer['schedule_end_time'];

        // Handle time ranges that span midnight (e.g., 22:00 to 02:00)
        if ($startTime > $endTime) {
            // Range spans midnight
            return ($currentTime >= $startTime || $currentTime <= $endTime);
        } else {
            // Normal range
            return ($currentTime >= $startTime && $currentTime <= $endTime);
        }
    }

    /**
     * Filter offers to only include those currently available
     * @param array $offers Array of offer arrays
     * @return array Filtered array of available offers
     */
    public function filterAvailable(array $offers): array
    {
        return array_filter($offers, function($offer) {
            return $this->isAvailableNow($offer);
        });
    }

    /**
     * Check if offer has hit its CAP limit
     * @param array $offer Offer data with cap fields
     * @return bool True if offer has hit its cap, false otherwise
     */
    public function hasHitCap(array $offer): bool
    {
        // If cap is not enabled, no cap restriction
        if (empty($offer['cap_enabled'])) {
            return false;
        }

        // If cap_limit or cap_period is missing, treat as no cap (invalid config)
        if (empty($offer['cap_limit']) || empty($offer['cap_period'])) {
            return false;
        }

        $offerId = (int)($offer['id'] ?? 0);
        if (!$offerId) {
            return false;
        }

        $capLimit = (int)$offer['cap_limit'];
        $capPeriod = $offer['cap_period'];
        $capTimezone = $offer['cap_timezone'] ?? 'UTC';

        // Validate timezone
        try {
            $tz = new \DateTimeZone($capTimezone);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('UTC');
            $capTimezone = 'UTC';
        }

        // Build query based on cap period
        if ($capPeriod === 'lifetime') {
            // Count all clicks for this offer
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count 
                 FROM clicks 
                 WHERE offer_id = ?"
            );
            $stmt->bind_param('i', $offerId);
        } else {
            // For day/week/month, need to convert UTC to offer's timezone
            // Get current date/time in offer's timezone
            $now = new \DateTime('now', $tz);
            
            if ($capPeriod === 'day') {
                // Count clicks for today in offer's timezone
                $dateStr = $now->format('Y-m-d');
                // Try using timezone name first, fallback to offset if needed
                $timezoneStr = $this->getTimezoneString($capTimezone);
                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) as count 
                     FROM clicks 
                     WHERE offer_id = ? 
                     AND DATE(CONVERT_TZ(ts, '+00:00', ?)) = ?"
                );
                $stmt->bind_param('iss', $offerId, $timezoneStr, $dateStr);
            } elseif ($capPeriod === 'week') {
                // Count clicks for this week (Monday to Sunday) in offer's timezone
                $monday = clone $now;
                $monday->modify('monday this week');
                $mondayStr = $monday->format('Y-m-d');
                $sunday = clone $now;
                $sunday->modify('sunday this week');
                $sundayStr = $sunday->format('Y-m-d');
                $timezoneStr = $this->getTimezoneString($capTimezone);
                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) as count 
                     FROM clicks 
                     WHERE offer_id = ? 
                     AND DATE(CONVERT_TZ(ts, '+00:00', ?)) >= ? 
                     AND DATE(CONVERT_TZ(ts, '+00:00', ?)) <= ?"
                );
                $stmt->bind_param('issss', $offerId, $timezoneStr, $mondayStr, $timezoneStr, $sundayStr);
            } elseif ($capPeriod === 'month') {
                // Count clicks for this month in offer's timezone
                $yearMonth = $now->format('Y-m');
                $timezoneStr = $this->getTimezoneString($capTimezone);
                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) as count 
                     FROM clicks 
                     WHERE offer_id = ? 
                     AND DATE_FORMAT(CONVERT_TZ(ts, '+00:00', ?), '%Y-%m') = ?"
                );
                $stmt->bind_param('iss', $offerId, $timezoneStr, $yearMonth);
            } else {
                // Unknown period, treat as no cap
                return false;
            }
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $count = (int)($row['count'] ?? 0);

        // Return true if count has reached or exceeded the cap limit
        return $count >= $capLimit;
    }

    /**
     * Get timezone string for MySQL CONVERT_TZ function
     * Tries to use timezone name first, falls back to offset if timezone tables aren't loaded
     * @param string $timezone Timezone identifier (e.g., 'America/New_York')
     * @return string Timezone name or offset string (e.g., 'America/New_York' or '-05:00')
     */
    private function getTimezoneString(string $timezone): string
    {
        // First, try to validate the timezone
        try {
            $tz = new \DateTimeZone($timezone);
            // MySQL CONVERT_TZ can use timezone names if timezone tables are loaded
            // For better compatibility, we'll use the timezone name directly
            // MySQL will handle DST automatically if timezone tables are populated
            return $timezone;
        } catch (\Exception $e) {
            // If timezone is invalid, calculate offset as fallback
            try {
                $tz = new \DateTimeZone('UTC');
                $now = new \DateTime('now', $tz);
                $offset = $tz->getOffset($now);
                $hours = (int)($offset / 3600);
                $minutes = (int)(($offset % 3600) / 60);
                return sprintf('%+03d:%02d', $hours, abs($minutes));
            } catch (\Exception $e2) {
                return '+00:00'; // Final fallback to UTC
            }
        }
    }

    /**
     * Check if offer is available for rotation (both schedule and cap)
     * @param array $offer Offer data with scheduling and cap fields
     * @return bool True if offer is available for rotation, false otherwise
     */
    public function isAvailableForRotation(array $offer): bool
    {
        // Check schedule availability
        if (!$this->isAvailableNow($offer)) {
            return false;
        }

        // Check CAP status
        if ($this->hasHitCap($offer)) {
            return false;
        }

        // Both checks passed
        return true;
    }
}


