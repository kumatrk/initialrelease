<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use SimpleKuma\Campaign\InactiveRedirectParser;
use SimpleKuma\Enrichment\BotDetector;
use SimpleKuma\Enrichment\ConnectionTypeNormalizer;

/**
 * Per-campaign routing for known bots and proxy/datacenter (Corporate) traffic.
 * Default Allow keeps today's redirect. Edge campaigns stay on Allow (the worker
 * does not classify).
 */
final class BotRoute
{
    public const MODE_ALLOW = 'allow';
    public const MODE_RECORD_ONLY = 'record_only';
    public const MODE_SAFE_URL = 'safe_url';

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_ALLOW, self::MODE_RECORD_ONLY, self::MODE_SAFE_URL];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{bot_route_mode: string, bot_route_url: string|null}
     */
    public static function fromInput(array $input): array
    {
        $mode = strtolower(trim((string) ($input['bot_route_mode'] ?? self::MODE_ALLOW)));
        if (!in_array($mode, self::modes(), true)) {
            $mode = self::MODE_ALLOW;
        }

        $url = isset($input['bot_route_url']) ? trim((string) $input['bot_route_url']) : '';
        if ($url === '') {
            $url = null;
        }
        if ($mode !== self::MODE_SAFE_URL) {
            $url = null;
        }

        return [
            'bot_route_mode' => $mode,
            'bot_route_url' => $url,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function validateFields(array $data): array
    {
        $errors = [];
        $parsed = self::fromInput($data);
        if ($parsed['bot_route_mode'] !== self::MODE_SAFE_URL) {
            return $errors;
        }
        $url = trim((string) ($parsed['bot_route_url'] ?? ''));
        if ($url === '') {
            $errors['bot_route_url'] = 'Enter a URL for bot and proxy traffic.';
        } elseif (!InactiveRedirectParser::isAllowedHttpUrl($url)) {
            $errors['bot_route_url'] = 'Bot route URL must be a valid http or https address.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $campaign
     */
    public static function mode(array $campaign): string
    {
        $mode = strtolower(trim((string) ($campaign['bot_route_mode'] ?? self::MODE_ALLOW)));

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_ALLOW;
    }

    /**
     * Visit snapshot from a stored click (offer hop / funnel hop).
     *
     * @param array<string, mixed> $click
     * @return array{classification: string, exclude_from_stats: bool, connection_type: ?string}
     */
    public static function visitFromClick(array $click): array
    {
        $extra = json_decode((string) ($click['extra_json'] ?? ''), true);
        $bot = is_array($extra) && is_array($extra['bot'] ?? null) ? $extra['bot'] : [];
        $connection = $click['connection_type'] ?? null;
        if (!is_string($connection) || trim($connection) === '') {
            $connection = null;
        }

        return [
            'classification' => (string) ($bot['classification'] ?? BotDetector::CLASS_HUMAN),
            'exclude_from_stats' => !empty($bot['exclude_from_stats']),
            'connection_type' => $connection,
        ];
    }

    /**
     * @param array<string, mixed> $campaign
     * @param array{classification?: string, exclude_from_stats?: bool, connection_type?: ?string} $visit
     */
    public static function applies(array $campaign, array $visit): bool
    {
        if (!empty($campaign['edge_enabled'])) {
            return false;
        }
        if (self::mode($campaign) === self::MODE_ALLOW) {
            return false;
        }

        $classification = (string) ($visit['classification'] ?? BotDetector::CLASS_HUMAN);
        if ($classification === BotDetector::CLASS_KNOWN_BOT) {
            return true;
        }
        if ($classification === BotDetector::CLASS_SUSPECTED_BOT && !empty($visit['exclude_from_stats'])) {
            return true;
        }

        $connection = (string) ($visit['connection_type'] ?? '');

        return strcasecmp($connection, ConnectionTypeNormalizer::CORPORATE) === 0;
    }

    /**
     * @param array<string, mixed> $campaign
     * @param array{classification?: string, exclude_from_stats?: bool, connection_type?: ?string} $visit
     * @return array{mode: string, url: string|null}|null
     */
    public static function decision(array $campaign, array $visit): ?array
    {
        if (!self::applies($campaign, $visit)) {
            return null;
        }
        $mode = self::mode($campaign);
        $url = $mode === self::MODE_SAFE_URL ? trim((string) ($campaign['bot_route_url'] ?? '')) : null;
        if ($url === '') {
            $url = null;
        }

        return ['mode' => $mode, 'url' => $url];
    }

    /**
     * Empty 200 for record-only, or a token-free 302 for a safe URL.
     * Falls through to the empty 200 when the stored URL is not http(s).
     *
     * @param array<string, mixed> $campaign
     */
    public static function respond(array $campaign): void
    {
        $mode = self::mode($campaign);
        if ($mode === self::MODE_SAFE_URL) {
            $url = trim((string) ($campaign['bot_route_url'] ?? ''));
            if (InactiveRedirectParser::isAllowedHttpUrl($url)) {
                KumaHopRedirect::redirect($url, null);
            }
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }
        exit;
    }
}
