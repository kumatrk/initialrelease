<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

/**
 * Optional campaign-editor UI for an installed Honeycomb addon.
 * Registered via HoneycombKernel::addCampaignFieldsProvider() from addon bootstraps.
 */
interface CampaignFieldsProvider
{
    public function addonSlug(): string;

    public function providerKey(): string;

    public function title(): string;

    /** Lower runs first when multiple providers are listed. */
    public function priority(): int;

    /**
     * When true, the campaign Honeycomb panel shows for any traffic source
     * (utility / destination addons such as Ringba). When false, the panel
     * only shows when the selected traffic source provider_key matches.
     */
    public function alwaysVisible(): bool;

    /**
     * Render HTML for the campaign form panel (escaped by the provider).
     *
     * @param array{
     *   binding?: array<string, mixed>|null,
     *   post?: array<string, mixed>,
     *   mode?: string,
     *   prefer_export_on_create?: bool
     * } $ctx
     */
    public function renderHtml(array $ctx): string;

    /**
     * Shape POST fields for BindingStore extra_json / remote IDs before save.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function normalizeBindingFields(array $fields): array;
}
