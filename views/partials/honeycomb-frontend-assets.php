<?php
/**
 * Emit script/link tags for Honeycomb addon frontend assets registered on the kernel.
 * Safe to include when Honeycomb is unavailable (no-op).
 *
 * Optional: mysqli $db (falls back to global $db)
 */
use SimpleKuma\Honeycomb\AddonLoader;

$honeyAssetDb = $db ?? null;
$honeyFrontendAssets = [];
if ($honeyAssetDb instanceof mysqli) {
    try {
        $honeyFrontendAssets = (new AddonLoader($honeyAssetDb))->kernel()->frontendAssets(
            defined('APP_BASE_URL') ? rtrim((string) APP_BASE_URL, '/') : null
        );
    } catch (Throwable $e) {
        $honeyFrontendAssets = [];
    }
}

foreach ($honeyFrontendAssets as $honeyAsset):
    $type = strtolower((string) ($honeyAsset['type'] ?? 'js'));
    $url = (string) ($honeyAsset['url'] ?? '');
    if ($url === '') {
        continue;
    }
    // Cache-bust with slug+file identity (asset endpoint already uses Last-Modified).
    $urlWithV = $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode(
        ($honeyAsset['slug'] ?? '') . '-' . ($honeyAsset['file'] ?? '1')
    );
    $esc = htmlspecialchars($urlWithV, ENT_QUOTES, 'UTF-8');
    if ($type === 'css') {
        echo '<link rel="stylesheet" href="' . $esc . '">' . "\n";
    } else {
        echo '<script src="' . $esc . '"></script>' . "\n";
    }
endforeach;
