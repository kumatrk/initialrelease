<?php

declare(strict_types=1);

namespace SimpleKuma\NewsBreak;

use SimpleKuma\Support\ApiFailureLog;

/**
 * NewsBreak Advertising API (business-api/v1).
 * Auth: Access-Token header. Report cost fields are in cents.
 */
final class NewsBreakClient
{
    private const BASE = 'https://business.newsbreak.com/business-api/v1';

    public function __construct(
        private string $accessToken,
        private ?\mysqli $db = null
    ) {
    }

    /**
     * Lightweight auth check used by Settings → Test.
     *
     * @return array{ok: bool, message: string, http_code: int}
     */
    public function testConnection(): array
    {
        $result = $this->request(
            'POST',
            '/reports/getIntegratedReport',
            [
                'name' => 'kuma_token_test',
                'timezone' => 'UTC',
                'dateRange' => 'YESTERDAY',
                'dimensions' => ['DATE'],
                'metrics' => ['COST'],
                'dataSource' => 'HOURLY',
            ],
            'test_token'
        );

        if ($result['http_code'] >= 200 && $result['http_code'] < 300 && ($result['json']['code'] ?? 1) === 0) {
            return ['ok' => true, 'message' => 'Connected', 'http_code' => $result['http_code']];
        }

        $msg = (string) ($result['json']['errMsg'] ?? $result['error'] ?? 'Request failed');
        return ['ok' => false, 'message' => $msg, 'http_code' => $result['http_code']];
    }

    /**
     * Synchronous report. Cost in response is cents.
     *
     * @param list<string> $dimensions
     * @param list<string> $metrics
     * @param list<int|string>|null $filterIds
     * @return array{ok: bool, rows: list<array<string, mixed>>, error: ?string, http_code: int}
     */
    public function getIntegratedReport(
        string $timezone,
        string $startDate,
        string $endDate,
        array $dimensions,
        array $metrics = ['COST', 'IMPRESSION', 'CLICK'],
        ?string $filter = null,
        ?array $filterIds = null,
        string $dataSource = 'HOURLY'
    ): array {
        $body = [
            'name' => 'kuma_cost_' . $startDate . '_' . $endDate,
            'timezone' => $timezone !== '' ? $timezone : 'UTC',
            'dateRange' => 'FIXED',
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => array_values($dimensions),
            'metrics' => array_values($metrics),
            'dataSource' => $dataSource,
        ];
        if ($filter !== null && $filterIds !== null && $filterIds !== []) {
            $body['filter'] = $filter;
            $body['filterIds'] = array_map(static fn($id) => is_numeric($id) ? (int) $id : $id, $filterIds);
        }

        $result = $this->request('POST', '/reports/getIntegratedReport', $body, 'cost_report');
        if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
            return [
                'ok' => false,
                'rows' => [],
                'error' => (string) ($result['json']['errMsg'] ?? $result['error'] ?? 'HTTP ' . $result['http_code']),
                'http_code' => $result['http_code'],
            ];
        }
        $code = (int) ($result['json']['code'] ?? 1);
        if ($code !== 0) {
            return [
                'ok' => false,
                'rows' => [],
                'error' => (string) ($result['json']['errMsg'] ?? 'API code ' . $code),
                'http_code' => $result['http_code'],
            ];
        }

        $rows = $result['json']['data']['rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }

        return [
            'ok' => true,
            'rows' => array_values(array_filter($rows, 'is_array')),
            'error' => null,
            'http_code' => $result['http_code'],
        ];
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array{http_code: int, error: ?string, json: array<string, mixed>, raw: string}
     */
    private function request(string $method, string $path, ?array $jsonBody, string $action): array
    {
        $url = self::BASE . $path;
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Access-Token: ' . $this->accessToken,
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = $payload !== false ? $payload : '{}';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rawStr = is_string($raw) ? $raw : '';
        $decoded = json_decode($rawStr, true);
        $json = is_array($decoded) ? $decoded : [];

        if ($this->db && ($errno || $httpCode < 200 || $httpCode >= 300 || ((int) ($json['code'] ?? 0)) !== 0)) {
            ApiFailureLog::record($this->db, 'newsbreak', $action, $httpCode > 0 ? $httpCode : null, $error ?: (string) ($json['errMsg'] ?? 'request failed'));
        }

        return [
            'http_code' => $httpCode,
            'error' => $error,
            'json' => $json,
            'raw' => $rawStr,
        ];
    }
}
