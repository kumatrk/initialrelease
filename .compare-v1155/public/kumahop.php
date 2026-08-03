<?php

declare(strict_types=1);

/**
 * Kuma bear-hop intermediary (second hop of two-step referrer privacy mode).
 * Requires a signed token — no open ?to= redirects.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Tracking\KumaHopRedirect;
use SimpleKuma\Tracking\KumaHopToken;

$token = isset($_GET['k']) ? (string) $_GET['k'] : '';
$destination = KumaHopToken::verify($token);

if ($destination === null) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    echo 'Invalid or expired Kuma hop link';
    exit;
}

KumaHopRedirect::serveIntermediaryPage($destination);
