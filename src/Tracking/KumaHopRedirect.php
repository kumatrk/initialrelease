<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Referrer-privacy modes for outbound offer redirects (LP→offer, DTO→offer).
 */
class KumaHopRedirect
{
    /**
     * Referrer privacy applies on LP→offer and DTO→offer hops, not on ad→landing page.
     */
    public static function modeForOutboundOffer(bool $isOutboundOffer, ?string $referrerMode): ?string
    {
        if (!$isOutboundOffer) {
            return null;
        }

        $mode = $referrerMode ?? '';

        return $mode !== '' ? $mode : null;
    }

    public static function redirect(string $url, ?string $referrerMode): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (headers_sent($file, $line)) {
            error_log("KumaHopRedirect: Headers already sent in {$file}:{$line}");
            echo '<!DOCTYPE html><html><head><script>window.location.href='
                . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                . ';</script></head><body>Continuing…</body></html>';
            exit;
        }

        if ($referrerMode === 'blank') {
            self::blankReferrer($url);
        } elseif ($referrerMode === 'noreferrer') {
            self::noReferrerPolicy($url);
        } elseif ($referrerMode === 'double') {
            self::doubleHop($url);
        } else {
            self::standard($url);
        }
    }

    /**
     * Second hop of bear-hop mode: HTML page with no-referrer meta refresh to destination.
     */
    public static function serveIntermediaryPage(string $destinationUrl): void
    {
        if (!self::isAllowedDestination($destinationUrl)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid destination URL';
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Content-Type: text/html; charset=utf-8');
        echo self::metaRefreshHtml($destinationUrl);
        exit;
    }

    public static function isAllowedDestination(string $url): bool
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }

    public static function metaRefreshHtml(string $url): string
    {
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="referrer" content="no-referrer">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<meta http-equiv="refresh" content="0;url=' . $safe . '">'
            . '</head><body>Continuing…</body></html>';
    }

    private static function blankReferrer(string $url): void
    {
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=utf-8');
        echo self::metaRefreshHtml($url);
        exit;
    }

    private static function noReferrerPolicy(string $url): void
    {
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $url, true, 302);
        exit;
    }

    /**
     * Hop 1: 302 to kumahop.php (signed). Hop 2: meta refresh to destination.
     */
    private static function doubleHop(string $url): void
    {
        if (!self::isAllowedDestination($url)) {
            self::standard($url);
            return;
        }

        header('Referrer-Policy: no-referrer');
        header('Location: ' . self::buildKumaHopUrl($url), true, 302);
        exit;
    }

    private static function standard(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    public static function buildKumaHopUrl(string $destinationUrl): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
            ? 'https'
            : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = self::getPublicBasePath();
        $token = KumaHopToken::sign($destinationUrl);

        return $scheme . '://' . $host . $base . '/kumahop.php?k=' . rawurlencode($token);
    }

    private static function getPublicBasePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = dirname($script);
        if ($dir === '/' || $dir === '.') {
            return '';
        }

        return rtrim($dir, '/');
    }
}
