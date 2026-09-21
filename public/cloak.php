<?php

declare(strict_types=1);

/**
 * Deprecated — formerly open redirect endpoint. Returns 410 so scanners delist this path.
 * Use kumahop.php (signed tokens) via referrer privacy "two-step" mode instead.
 */

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store');
echo 'This endpoint is no longer available. Update your Simple KUMA tracker.';
exit;
