<?php

declare(strict_types=1);

namespace SimpleKuma\Edge;

use mysqli;
use SimpleKuma\Entity\Campaign;
use SimpleKuma\Tracking\ClickRecorder;

/**
 * Authenticates and ingests async click events from the Edge Worker.
 */
final class EdgeClickIngest
{
    private const MAX_SKEW_SECONDS = 300;

    private mysqli $db;
    private EdgeSettings $settings;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->settings = new EdgeSettings($db);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, http: int, body: array<string, mixed>}
     */
    public function handle(array $payload, string $rawBody, ?string $authHeader, ?string $timestampHeader, ?string $signatureHeader): array
    {
        $secret = $this->settings->getIngestSecret();
        if ($secret === '') {
            return $this->fail(503, 'edge_not_configured', 'Edge ingest secret is not configured');
        }

        if (!$this->authenticate($secret, $rawBody, $authHeader, $timestampHeader, $signatureHeader)) {
            return $this->fail(401, 'unauthorized', 'Invalid edge ingest authentication');
        }

        $clickId = trim((string) ($payload['click_id'] ?? ''));
        $campaignId = (int) ($payload['campaign_id'] ?? 0);
        if ($clickId === '' || $campaignId <= 0) {
            return $this->fail(400, 'invalid_payload', 'click_id and campaign_id are required');
        }

        if (!preg_match('/^[a-f0-9-]{36}$/i', $clickId)) {
            return $this->fail(400, 'invalid_click_id', 'click_id must be a UUID');
        }

        $campaignEntity = new Campaign($this->db);
        $campaign = $campaignEntity->getById($campaignId);
        if (!$campaign) {
            return $this->fail(404, 'campaign_not_found', 'Campaign not found');
        }

        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $cost = null;
        if (isset($payload['cost']) && is_numeric($payload['cost'])) {
            $cost = [
                'cost' => (float) $payload['cost'],
                'currency' => isset($payload['cost_currency']) ? (string) $payload['cost_currency'] : 'USD',
            ];
        }

        $recorder = new ClickRecorder($this->db);
        $result = $recorder->record([
            'campaign_id' => $campaignId,
            'click_id' => $clickId,
            'params' => $params,
            'cost' => $cost,
            'campaign' => $campaign,
            'is_direct_to_offer' => !empty($payload['is_direct_to_offer']),
            'offer_id' => isset($payload['offer_id']) ? (int) $payload['offer_id'] : null,
            'landing_page_id' => isset($payload['landing_page_id']) ? (int) $payload['landing_page_id'] : null,
            'traffic_source_id' => isset($payload['traffic_source_id'])
                ? (int) $payload['traffic_source_id']
                : (!empty($campaign['traffic_source_id']) ? (int) $campaign['traffic_source_id'] : null),
            'slug_id' => isset($payload['slug_id']) ? (int) $payload['slug_id'] : null,
            'redirect_rule_matched' => !empty($payload['redirect_rule_matched']),
            'ip' => isset($payload['ip']) ? (string) $payload['ip'] : null,
            'ua' => isset($payload['user_agent']) ? (string) $payload['user_agent'] : ($payload['ua'] ?? null),
            'referrer' => isset($payload['referer']) ? (string) $payload['referer'] : ($payload['referrer'] ?? null),
            'geo' => [
                'country' => $payload['country'] ?? null,
                'region' => $payload['region'] ?? null,
                'city' => $payload['city'] ?? null,
                'postal' => $payload['postal'] ?? null,
            ],
            'device' => [
                'device' => $payload['device'] ?? null,
                'device_brand' => $payload['device_brand'] ?? null,
                'device_model' => $payload['device_model'] ?? null,
                'os' => $payload['operating_system'] ?? ($payload['os'] ?? null),
                'os_version' => $payload['os_version'] ?? null,
                'browser' => $payload['browser'] ?? null,
                'browser_version' => $payload['browser_version'] ?? null,
            ],
            'source' => 'edge',
        ]);

        if (!$result['ok']) {
            return $this->fail(500, 'persist_failed', $result['message'] ?? 'Failed to persist click');
        }

        return [
            'ok' => true,
            'http' => 202,
            'body' => [
                'accepted' => true,
                'duplicate' => !empty($result['duplicate']),
                'click_id' => $clickId,
            ],
        ];
    }

    private function authenticate(
        string $secret,
        string $rawBody,
        ?string $authHeader,
        ?string $timestampHeader,
        ?string $signatureHeader
    ): bool {
        $bearerOk = false;
        if ($authHeader !== null && preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
            $bearerOk = hash_equals($secret, trim($m[1]));
        }

        // Prefer HMAC(timestamp + "." + body) when headers present (replay protection)
        if ($timestampHeader !== null && $signatureHeader !== null && $timestampHeader !== '' && $signatureHeader !== '') {
            if (!ctype_digit($timestampHeader)) {
                return false;
            }
            $ts = (int) $timestampHeader;
            if (abs(time() - $ts) > self::MAX_SKEW_SECONDS) {
                return false;
            }
            $expected = hash_hmac('sha256', $timestampHeader . '.' . $rawBody, $secret);
            $sig = preg_replace('#^sha256=#i', '', trim($signatureHeader)) ?? '';
            return hash_equals($expected, $sig);
        }

        return $bearerOk;
    }

    /**
     * @return array{ok: bool, http: int, body: array<string, mixed>}
     */
    private function fail(int $http, string $code, string $message): array
    {
        return [
            'ok' => false,
            'http' => $http,
            'body' => [
                'error' => $code,
                'message' => $message,
            ],
        ];
    }
}
