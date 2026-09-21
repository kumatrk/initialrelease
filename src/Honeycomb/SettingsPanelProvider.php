<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

/**
 * Optional settings UI hosted on the Honeycomb page for an installed addon.
 */
interface SettingsPanelProvider
{
    public function addonSlug(): string;

    public function title(): string;

    /**
     * Render HTML for the settings panel (already escaped where needed by the addon).
     */
    public function renderHtml(): string;

    /**
     * Handle a POST when action matches this panel. Return null if not handled.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string}|null
     */
    public function handlePost(array $post): ?array;
}
