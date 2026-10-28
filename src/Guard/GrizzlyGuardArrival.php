<?php

declare(strict_types=1);

namespace SimpleKuma\Guard;

use mysqli;
use SimpleKuma\Settings\SettingsManager;

/**
 * One visit that arrived on a down tracking domain. The click path reads
 * health the checker already stored. It does not probe the network.
 */
final class GrizzlyGuardArrival
{
    public const HOP_PARAM = 'kgh';

    /**
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $query
     */
    public static function hopUrl(mysqli $db, array $campaign, string $arrivalHost, string $requestUri, string $campaignKey, array $query, bool $alreadyHopped): ?string
    {
        if ($alreadyHopped || !empty($campaign['edge_enabled'])) {
            return null;
        }
        $mode = GrizzlyGuardPlan::normalizeMode((string) ($campaign['guard_mode'] ?? GrizzlyGuardPlan::MODE_OFF));
        if ($mode === GrizzlyGuardPlan::MODE_OFF) {
            return null;
        }

        $settings = new SettingsManager($db);
        if (!GrizzlyGuardSettings::isEnabled($settings)) {
            return null;
        }
        $health = GrizzlyGuardSettings::freshHealth($settings);
        if ($health === null) {
            return null;
        }

        $arrivalHost = self::hostOnly($arrivalHost);
        if ($arrivalHost === '' || ($health[$arrivalHost] ?? '') !== 'down') {
            return null;
        }

        $verified = self::verifiedHosts($db);
        $pool = [];
        foreach (GrizzlyGuardSettings::domainPool($settings) as $id) {
            if (isset($verified[$id])) {
                $pool[] = $verified[$id];
            }
        }
        $next = GrizzlyGuardPlan::nextHealthyHost(
            $pool,
            $health,
            $arrivalHost,
            $mode === GrizzlyGuardPlan::MODE_FULL,
            array_values($verified)
        );
        if ($next === null || $next === $arrivalHost) {
            return null;
        }

        return self::buildUrl($next, $requestUri, $campaignKey, $query);
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function buildUrl(string $host, string $requestUri, string $campaignKey, array $query): ?string
    {
        $host = self::hostOnly($host);
        if ($host === '' || str_contains($host, '/') || str_contains($host, ' ')) {
            return null;
        }
        $query[self::HOP_PARAM] = '1';
        $scheme = ($host === 'localhost' || str_starts_with($host, '127.')) ? 'http' : 'https';
        $path = self::publicPath($requestUri, $campaignKey);
        $qs = http_build_query($query);

        return $scheme . '://' . $host . $path . ($qs !== '' ? '?' . $qs : '');
    }

    public static function publicPath(string $requestUri, string $campaignKey): string
    {
        $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '');
        foreach (['/km/', '/go/', '/c/'] as $prefix) {
            $pos = strpos($path, $prefix);
            if ($pos !== false) {
                return substr($path, $pos);
            }
        }
        $campaignKey = trim($campaignKey);
        if ($campaignKey === '') {
            return '/km/';
        }

        return '/km/' . rawurlencode($campaignKey);
    }

    public static function hostOnly(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        if (str_contains($host, '://')) {
            $parsed = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsed) ? strtolower($parsed) : '';
        }
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return trim($host, ". \t");
    }

    /**
     * @return array<int, string>
     */
    private static function verifiedHosts(mysqli $db): array
    {
        $hosts = [];
        $result = $db->query("SELECT id, domain FROM tracking_domains WHERE status IN ('verified', 'verified_manual')");
        if (!$result) {
            return [];
        }
        while ($row = $result->fetch_assoc()) {
            $host = self::hostOnly((string) ($row['domain'] ?? ''));
            if ($host !== '') {
                $hosts[(int) $row['id']] = $host;
            }
        }

        return $hosts;
    }
}
