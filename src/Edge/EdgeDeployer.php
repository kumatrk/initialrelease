<?php

declare(strict_types=1);

namespace SimpleKuma\Edge;

use mysqli;

/**
 * Deploys / updates the Edge Redirect Worker via Cloudflare API.
 */
final class EdgeDeployer
{
    private mysqli $db;
    private EdgeSettings $settings;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->settings = new EdgeSettings($db);
    }

    /**
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public function deploy(): array
    {
        if (!$this->settings->isConfiguredForDeploy()) {
            return [
                'ok' => false,
                'message' => 'Configure Cloudflare Account ID, API token, and origin base URL first.',
            ];
        }

        $client = new CloudflareClient(
            $this->settings->getAccountId(),
            $this->settings->getApiToken()
        );

        $verify = $client->verifyToken();
        if (!$verify['ok']) {
            $this->settings->markHealth(false, $verify['message']);
            return $verify;
        }

        $kvTitle = 'simplekuma-edge-campaigns';
        $kv = $client->ensureKvNamespace($kvTitle);
        if (!$kv['ok'] || empty($kv['namespace_id'])) {
            $this->settings->markHealth(false, $kv['message']);
            return $kv;
        }
        $this->settings->settingsManager()->set(
            EdgeSettings::KEY_CF_KV_NAMESPACE_ID,
            $kv['namespace_id']
        );

        $ingestSecret = $this->settings->ensureIngestSecret();
        $ingestUrl = $this->settings->ingestUrl();
        $originBase = $this->settings->getOriginBaseUrl();
        if ($ingestUrl === '') {
            return ['ok' => false, 'message' => 'Origin base URL is required for ingest endpoint'];
        }

        $script = $this->loadWorkerScript();
        if ($script === null) {
            return ['ok' => false, 'message' => 'Worker template not found at workers/edge-redirect/src/index.js'];
        }

        $workerName = $this->settings->getWorkerName();
        $deploy = $client->deployWorkerScript(
            $workerName,
            $script,
            $kv['namespace_id'],
            'CAMPAIGNS',
            [
                'INGEST_URL' => $ingestUrl,
                'ORIGIN_FALLBACK_URL' => $originBase,
            ],
            [
                'INGEST_SECRET' => $ingestSecret,
            ]
        );
        if (!$deploy['ok']) {
            $this->settings->markHealth(false, $deploy['message']);
            return $deploy;
        }

        $routeResult = null;
        $zoneId = $this->settings->getZoneId();
        $pattern = $this->settings->getRoutePattern();
        if ($zoneId !== '' && $pattern !== '') {
            $routeResult = $client->ensureWorkerRoute($zoneId, $pattern, $workerName);
            if (!$routeResult['ok']) {
                $this->settings->markHealth(false, 'Worker deployed but route failed: ' . $routeResult['message']);
                return [
                    'ok' => false,
                    'message' => 'Worker deployed but route failed: ' . $routeResult['message'],
                    'details' => ['kv_namespace_id' => $kv['namespace_id']],
                ];
            }
        }

        $this->settings->settingsManager()->set(EdgeSettings::KEY_ENABLED, '1');
        $this->settings->markDeployed();
        $this->settings->markHealth(true, 'Worker deployed successfully');

        // Sync all edge-enabled campaigns after deploy
        $sync = new EdgeCampaignSync($this->db);
        $syncSummary = $sync->syncAllEligible();

        return [
            'ok' => true,
            'message' => 'Edge Redirect Worker deployed'
                . ($routeResult ? ' and route configured' : ' (configure zone + route pattern to attach to a domain)'),
            'details' => [
                'kv_namespace_id' => $kv['namespace_id'],
                'worker_name' => $workerName,
                'ingest_url' => $ingestUrl,
                'campaigns_synced' => $syncSummary['synced'] ?? 0,
                'campaigns_removed' => $syncSummary['removed'] ?? 0,
                'sync_errors' => $syncSummary['errors'] ?? [],
            ],
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function healthCheck(): array
    {
        if ($this->settings->getAccountId() === '' || $this->settings->getApiToken() === '') {
            $msg = 'Cloudflare credentials not configured';
            $this->settings->markHealth(false, $msg);
            return ['ok' => false, 'message' => $msg];
        }

        $client = new CloudflareClient(
            $this->settings->getAccountId(),
            $this->settings->getApiToken()
        );
        $verify = $client->verifyToken();
        if (!$verify['ok']) {
            $this->settings->markHealth(false, $verify['message']);
            return $verify;
        }

        $kvId = $this->settings->getKvNamespaceId();
        if ($kvId === '') {
            $msg = 'KV namespace not provisioned — deploy the Worker first';
            $this->settings->markHealth(false, $msg);
            return ['ok' => false, 'message' => $msg];
        }

        $msg = 'Cloudflare token and KV namespace look healthy';
        $this->settings->markHealth(true, $msg);
        return ['ok' => true, 'message' => $msg];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function disable(): array
    {
        $messages = [];
        $accountId = $this->settings->getAccountId();
        $token = $this->settings->getApiToken();

        if ($accountId !== '' && $token !== '') {
            $client = new CloudflareClient($accountId, $token);
            $zoneId = $this->settings->getZoneId();
            $pattern = $this->settings->getRoutePattern();
            if ($zoneId !== '' && $pattern !== '') {
                $route = $client->deleteWorkerRoute($zoneId, $pattern);
                $messages[] = $route['message'];
                if (!$route['ok']) {
                    return [
                        'ok' => false,
                        'message' => 'Failed to remove Worker route: ' . $route['message'],
                    ];
                }
            }

            $sync = new EdgeCampaignSync($this->db);
            $purge = $sync->purgeAllCampaignKeys();
            $messages[] = $purge['message'] . " ({$purge['removed']} campaigns)";
            if (!$purge['ok']) {
                return [
                    'ok' => false,
                    'message' => 'Route removed but KV purge failed: ' . $purge['message'],
                ];
            }
        } else {
            $messages[] = 'Cloudflare credentials missing — skipped route/KV cleanup';
        }

        $this->settings->settingsManager()->set(EdgeSettings::KEY_ENABLED, '0');
        $this->settings->markHealth(false, 'Edge redirect disabled');

        return [
            'ok' => true,
            'message' => 'Edge redirect disabled. ' . implode(' ', $messages),
        ];
    }

    private function loadWorkerScript(): ?string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/workers/edge-redirect/src/index.js',
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'edge-redirect' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'index.js',
        ];
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                $js = file_get_contents($path);
                return $js === false ? null : $js;
            }
        }
        return null;
    }
}
