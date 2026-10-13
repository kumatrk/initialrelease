<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;

/**
 * In-process registry filled by enabled addon bootstraps.
 */
final class HoneycombKernel
{
    /** @var list<CostSyncJob> */
    private array $syncJobs = [];

    /** @var list<CostOverlayProvider> */
    private array $overlayProviders = [];

    /** @var list<SettingsPanelProvider> */
    private array $settingsPanels = [];

    /** @var list<ConversionExportProvider> */
    private array $conversionExporters = [];

    /** @var list<CampaignFieldsProvider> */
    private array $campaignFieldsProviders = [];

    /** @var array<string, AddonHttpHandler> keyed by addon slug */
    private array $httpHandlers = [];

    /** @var list<array{slug: string, file: string, type: string}> */
    private array $frontendAssets = [];

    public function __construct(private mysqli $db)
    {
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    public function addCostSyncJob(CostSyncJob $job): void
    {
        $this->syncJobs[] = $job;
    }

    public function addCostOverlayProvider(CostOverlayProvider $provider): void
    {
        $this->overlayProviders[] = $provider;
    }

    public function addSettingsPanel(SettingsPanelProvider $panel): void
    {
        $this->settingsPanels[] = $panel;
    }

    public function addConversionExportProvider(ConversionExportProvider $provider): void
    {
        $this->conversionExporters[] = $provider;
    }

    public function addCampaignFieldsProvider(CampaignFieldsProvider $provider): void
    {
        $this->campaignFieldsProviders[] = $provider;
    }

    public function addHttpHandler(AddonHttpHandler $handler): void
    {
        $slug = $handler->addonSlug();
        if ($slug === '') {
            return;
        }
        $this->httpHandlers[$slug] = $handler;
    }

    /**
     * Register a file under honeycomb/addons/{slug}/assets/ for the campaign editor.
     * $file is relative to the addon's assets/ directory (e.g. campaign-tiktok.js).
     */
    public function addFrontendAsset(string $addonSlug, string $file, string $type = 'js'): void
    {
        $addonSlug = trim($addonSlug);
        $file = str_replace('\\', '/', trim($file));
        $file = ltrim($file, '/');
        if ($addonSlug === '' || $file === '' || str_contains($file, '..')) {
            return;
        }
        $type = strtolower(trim($type));
        if ($type === '') {
            $type = 'js';
        }
        $this->frontendAssets[] = [
            'slug' => $addonSlug,
            'file' => $file,
            'type' => $type,
        ];
    }

    public function httpHandler(string $slug): ?AddonHttpHandler
    {
        return $this->httpHandlers[$slug] ?? null;
    }

    /**
     * @return array<string, AddonHttpHandler>
     */
    public function httpHandlers(): array
    {
        return $this->httpHandlers;
    }

    /**
     * @return list<array{slug: string, file: string, type: string, url: string}>
     */
    public function frontendAssets(?string $appBaseUrl = null): array
    {
        $out = [];
        $seen = [];
        foreach ($this->frontendAssets as $asset) {
            $key = $asset['slug'] . "\0" . $asset['file'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'slug' => $asset['slug'],
                'file' => $asset['file'],
                'type' => $asset['type'],
                'url' => HoneycombAddonUrls::asset($asset['slug'], $asset['file'], $appBaseUrl),
            ];
        }
        return $out;
    }

    /**
     * @return list<CostSyncJob>
     */
    public function costSyncJobs(): array
    {
        return $this->syncJobs;
    }

    /**
     * @return list<CostOverlayProvider>
     */
    public function costOverlayProviders(): array
    {
        return $this->overlayProviders;
    }

    /**
     * @return list<SettingsPanelProvider>
     */
    public function settingsPanels(): array
    {
        return $this->settingsPanels;
    }

    /**
     * @return list<ConversionExportProvider>
     */
    public function conversionExporters(): array
    {
        return $this->conversionExporters;
    }

    /**
     * @return list<CampaignFieldsProvider>
     */
    public function campaignFieldsProviders(): array
    {
        $list = $this->campaignFieldsProviders;
        usort(
            $list,
            static fn(CampaignFieldsProvider $a, CampaignFieldsProvider $b): int => $a->priority() <=> $b->priority()
        );
        return $list;
    }

    /**
     * Notify all registered conversion exporters for one conversion.
     * Core stays network-agnostic; addons decide eligibility.
     *
     * @param array<string, mixed> $conversion
     * @return list<array{slug: string, ok: bool, status: string, message: string}>
     */
    public function dispatchConversionExports(array $conversion): array
    {
        $results = [];
        foreach ($this->conversionExporters as $exporter) {
            try {
                $result = $exporter->exportConversion($conversion);
                $results[] = array_merge(
                    [
                        'slug' => $exporter->addonSlug(),
                        'ok' => (bool) ($result['ok'] ?? false),
                        'status' => (string) ($result['status'] ?? 'unknown'),
                        'message' => (string) ($result['message'] ?? ''),
                    ],
                    array_intersect_key($result, array_flip([
                        'url',
                        'http_status',
                        'request_body',
                        'response_body',
                    ]))
                );
            } catch (\Throwable $e) {
                error_log(
                    'Honeycomb conversion export failed for ' . $exporter->addonSlug()
                    . ': ' . $e->getMessage()
                );
                $results[] = [
                    'slug' => $exporter->addonSlug(),
                    'ok' => false,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }
}
