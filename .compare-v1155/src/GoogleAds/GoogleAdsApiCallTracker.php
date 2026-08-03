<?php

declare(strict_types=1);

namespace SimpleKuma\GoogleAds;

use mysqli;

/**
 * Tracks outbound Google Ads API requests for usage overview (counts only).
 */
class GoogleAdsApiCallTracker
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function logCall(
        string $endpoint,
        string $method = 'POST',
        ?string $customerId = null,
        ?int $integrationId = null,
        ?int $responseCode = null,
        bool $success = true
    ): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO google_ads_api_calls
                 (endpoint, method, customer_id, integration_id, response_code, success, called_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            if (!$stmt) {
                return;
            }

            $successInt = $success ? 1 : 0;
            $stmt->bind_param(
                'sssiii',
                $endpoint,
                $method,
                $customerId,
                $integrationId,
                $responseCode,
                $successInt
            );
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('GoogleAdsApiCallTracker::logCall failed: ' . $e->getMessage());
        }
    }

    public function getCallsThisHour(?int $integrationId = null): int
    {
        return $this->countCalls('called_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)', $integrationId);
    }

    public function getCallsToday(?int $integrationId = null): int
    {
        return $this->countCalls('DATE(called_at) = CURDATE()', $integrationId);
    }

    public function getCallsThisMonth(?int $integrationId = null): int
    {
        return $this->countCalls(
            'YEAR(called_at) = YEAR(CURDATE()) AND MONTH(called_at) = MONTH(CURDATE())',
            $integrationId
        );
    }

    public function getFailedToday(?int $integrationId = null): int
    {
        return $this->countCalls('DATE(called_at) = CURDATE() AND success = 0', $integrationId);
    }

    /**
     * @return array{this_hour: int, today: int, this_month: int, failed_today: int, endpoints_this_hour: array<int, array{endpoint: string, count: int}>}
     */
    public function getCallStats(?int $integrationId = null): array
    {
        return [
            'this_hour' => $this->getCallsThisHour($integrationId),
            'today' => $this->getCallsToday($integrationId),
            'this_month' => $this->getCallsThisMonth($integrationId),
            'failed_today' => $this->getFailedToday($integrationId),
            'endpoints_this_hour' => $this->getEndpointBreakdownThisHour($integrationId),
        ];
    }

    /**
     * @return array<int, array{endpoint: string, count: int}>
     */
    public function getEndpointBreakdownThisHour(?int $integrationId = null, int $limit = 10): array
    {
        $sql = 'SELECT endpoint, COUNT(*) AS count
                FROM google_ads_api_calls
                WHERE called_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)';
        $params = [];
        $types = '';

        if ($integrationId !== null) {
            $sql .= ' AND integration_id = ?';
            $params[] = $integrationId;
            $types .= 'i';
        }

        $sql .= ' GROUP BY endpoint ORDER BY count DESC LIMIT ?';
        $params[] = $limit;
        $types .= 'i';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $out = [];
        while ($row = $result->fetch_assoc()) {
            $endpoint = trim((string)($row['endpoint'] ?? ''));
            if ($endpoint === '') {
                $endpoint = '(unknown)';
            }
            $out[] = [
                'endpoint' => $endpoint,
                'count' => (int)($row['count'] ?? 0),
            ];
        }
        $stmt->close();

        return $out;
    }

    private function countCalls(string $whereSql, ?int $integrationId): int
    {
        $sql = "SELECT COUNT(*) AS count FROM google_ads_api_calls WHERE {$whereSql}";
        $params = [];
        $types = '';

        if ($integrationId !== null) {
            $sql .= ' AND integration_id = ?';
            $params[] = $integrationId;
            $types .= 'i';
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }
}
