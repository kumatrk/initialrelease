<?php

declare(strict_types=1);

/**
 * Legacy shim — prefer /api/honeycomb-addon.php?slug=ringba&action=…
 * Kept for older bookmarks / cached campaign-ringba.js fallbacks.
 */
$_GET['slug'] = 'ringba';
require __DIR__ . '/honeycomb-addon.php';
