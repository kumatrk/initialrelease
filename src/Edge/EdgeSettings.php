<?php

declare(strict_types=1);

namespace SimpleKuma\Edge;

use mysqli;
use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Utils\SecretEncryption;

/**
 * Edge Redirect Engine settings keys and helpers.
 */
final class EdgeSettings
{
    public const KEY_ENABLED = 'edge_redirect_enabled';
    public const KEY_CF_ACCOUNT_ID = 'edge_cf_account_id';
    public const KEY_CF_API_TOKEN = 'edge_cf_api_token';
    public const KEY_CF_ZONE_ID = 'edge_cf_zone_id';
    public const KEY_CF_WORKER_NAME = 'edge_cf_worker_name';
    public const KEY_CF_KV_NAMESPACE_ID = 'edge_cf_kv_namespace_id';
    public const KEY_CF_ROUTE_PATTERN = 'edge_cf_route_pattern';
    public const KEY_INGEST_SECRET = 'edge_ingest_secret';
    public const KEY_ORIGIN_BASE_URL = 'edge_origin_base_url';
    public const KEY_LAST_DEPLOY_AT = 'edge_last_deploy_at';
    public const KEY_LAST_HEALTH_AT = 'edge_last_health_at';
    public const KEY_LAST_HEALTH_OK = 'edge_last_health_ok';
    public const KEY_LAST_HEALTH_MSG = 'edge_last_health_message';
    public const KEY_LAST_SYNC_AT = 'edge_last_campaign_sync_at';

    public const DEFAULT_WORKER_NAME = 'simplekuma-edge-redirect';

    private SettingsManager $settings;

    public function __construct(mysqli $db)
    {
        $this->settings = new SettingsManager($db);
    }

    public function settingsManager(): SettingsManager
    {
        return $this->settings;
    }

    public function isEnabled(): bool
    {
        return $this->settings->get(self::KEY_ENABLED, '0') === '1';
    }

    public function getAccountId(): string
    {
        return trim((string) $this->settings->get(self::KEY_CF_ACCOUNT_ID, ''));
    }

    public function getApiToken(): string
    {
        $raw = (string) $this->settings->get(self::KEY_CF_API_TOKEN, '');
        if ($raw === '') {
            return '';
        }
        try {
            return SecretEncryption::decryptFlexible($raw);
        } catch (\Throwable $e) {
            error_log('EdgeSettings: failed to decrypt CF API token: ' . $e->getMessage());
            return '';
        }
    }

    public function getApiTokenMasked(): string
    {
        $token = $this->getApiToken();
        if ($token === '') {
            return '';
        }
        return SecretEncryption::mask($token);
    }

    public function setApiToken(string $plaintext): bool
    {
        if ($plaintext === '') {
            return $this->settings->set(self::KEY_CF_API_TOKEN, '');
        }
        return $this->settings->set(self::KEY_CF_API_TOKEN, SecretEncryption::encrypt($plaintext));
    }

    public function getZoneId(): string
    {
        return trim((string) $this->settings->get(self::KEY_CF_ZONE_ID, ''));
    }

    public function getWorkerName(): string
    {
        $name = trim((string) $this->settings->get(self::KEY_CF_WORKER_NAME, ''));
        return $name !== '' ? $name : self::DEFAULT_WORKER_NAME;
    }

    public function getKvNamespaceId(): string
    {
        return trim((string) $this->settings->get(self::KEY_CF_KV_NAMESPACE_ID, ''));
    }

    public function getRoutePattern(): string
    {
        return trim((string) $this->settings->get(self::KEY_CF_ROUTE_PATTERN, ''));
    }

    public function getIngestSecret(): string
    {
        $raw = (string) $this->settings->get(self::KEY_INGEST_SECRET, '');
        if ($raw === '') {
            return '';
        }
        try {
            return SecretEncryption::decryptFlexible($raw);
        } catch (\Throwable $e) {
            error_log('EdgeSettings: failed to decrypt ingest secret: ' . $e->getMessage());
            return '';
        }
    }

    public function ensureIngestSecret(): string
    {
        $existing = $this->getIngestSecret();
        if ($existing !== '') {
            return $existing;
        }
        $secret = bin2hex(random_bytes(32));
        $this->settings->set(self::KEY_INGEST_SECRET, SecretEncryption::encrypt($secret));
        return $secret;
    }

    public function rotateIngestSecret(): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->settings->set(self::KEY_INGEST_SECRET, SecretEncryption::encrypt($secret));
        return $secret;
    }

    public function getOriginBaseUrl(): string
    {
        $configured = trim((string) $this->settings->get(self::KEY_ORIGIN_BASE_URL, ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        if (defined('BASE_URL') && BASE_URL !== '') {
            return rtrim((string) BASE_URL, '/');
        }
        return '';
    }

    public function ingestUrl(): string
    {
        $base = $this->getOriginBaseUrl();
        return $base !== '' ? $base . '/api/edge-click' : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function statusSnapshot(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'account_id' => $this->getAccountId(),
            'has_api_token' => $this->getApiToken() !== '',
            'api_token_masked' => $this->getApiTokenMasked(),
            'zone_id' => $this->getZoneId(),
            'worker_name' => $this->getWorkerName(),
            'kv_namespace_id' => $this->getKvNamespaceId(),
            'route_pattern' => $this->getRoutePattern(),
            'origin_base_url' => $this->getOriginBaseUrl(),
            'ingest_url' => $this->ingestUrl(),
            'has_ingest_secret' => $this->getIngestSecret() !== '',
            'last_deploy_at' => $this->settings->get(self::KEY_LAST_DEPLOY_AT),
            'last_health_at' => $this->settings->get(self::KEY_LAST_HEALTH_AT),
            'last_health_ok' => $this->settings->get(self::KEY_LAST_HEALTH_OK) === '1',
            'last_health_message' => $this->settings->get(self::KEY_LAST_HEALTH_MSG, ''),
            'last_campaign_sync_at' => $this->settings->get(self::KEY_LAST_SYNC_AT),
        ];
    }

    public function markHealth(bool $ok, string $message): void
    {
        $this->settings->setMultiple([
            self::KEY_LAST_HEALTH_AT => gmdate('c'),
            self::KEY_LAST_HEALTH_OK => $ok ? '1' : '0',
            self::KEY_LAST_HEALTH_MSG => mb_substr($message, 0, 500),
        ]);
    }

    public function markDeployed(): void
    {
        $this->settings->set(self::KEY_LAST_DEPLOY_AT, gmdate('c'));
    }

    public function markCampaignSync(): void
    {
        $this->settings->set(self::KEY_LAST_SYNC_AT, gmdate('c'));
    }

    public function isConfiguredForDeploy(): bool
    {
        return $this->getAccountId() !== ''
            && $this->getApiToken() !== ''
            && $this->getOriginBaseUrl() !== '';
    }
}
