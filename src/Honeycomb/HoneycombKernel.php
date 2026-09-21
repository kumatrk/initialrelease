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
