<?php

declare(strict_types=1);

namespace SimpleKuma\Guard;

/**
 * Pure decisions for Grizzly Guard. No database and no HTTP.
 */
final class GrizzlyGuardPlan
{
    public const MODE_OFF = 'off';
    public const MODE_BACKUPS = 'backups';
    public const MODE_FULL = 'full';

    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === self::MODE_BACKUPS || $mode === self::MODE_FULL) {
            return $mode;
        }

        return self::MODE_OFF;
    }

    public static function isHttpUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || preg_match('/[\s<>"\']/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return ($scheme === 'http' || $scheme === 'https') && $host !== '';
    }

    /**
     * Checker-only. Offer and landing-page URLs can be longer than the last-resort field.
     */
    public static function isProbeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return ($scheme === 'http' || $scheme === 'https') && $host !== '';
    }

    /**
     * up, down, or unknown. Unknown is not treated as a failure.
     */
    public static function classifyHttp(int $status, bool $transportFailed): string
    {
        if ($transportFailed || $status === 0) {
            return 'down';
        }
        if ($status === 401 || $status === 403 || $status === 429) {
            return 'unknown';
        }
        if ($status >= 200 && $status < 400) {
            return 'up';
        }
        if ($status === 404 || $status === 408 || $status === 410 || $status >= 500) {
            return 'down';
        }

        return 'unknown';
    }

    /**
     * hold pulls the item out of rotation. leave keeps the current destination.
     */
    public static function itemAction(
        string $mode,
        string $health,
        bool $backupHealthy,
        int $otherHealthy,
        bool $lastResortReady
    ): string {
        if ($health !== 'down' || $mode === self::MODE_OFF) {
            return 'leave';
        }
        if ($mode === self::MODE_BACKUPS) {
            return $backupHealthy ? 'hold' : 'leave';
        }
        if ($mode === self::MODE_FULL && ($backupHealthy || $otherHealthy > 0 || $lastResortReady)) {
            return 'hold';
        }

        return 'leave';
    }

    /**
     * Next hostname a visit can use. The ordered list is tried first.
     * Full mode may use another verified host only after that list is exhausted.
     *
     * @param list<string> $orderedHosts
     * @param array<string, string> $healthByHost
     * @param list<string> $otherHosts
     */
    public static function nextHealthyHost(
        array $orderedHosts,
        array $healthByHost,
        string $currentHost,
        bool $allowOtherVerified,
        array $otherHosts
    ): ?string {
        $currentHost = strtolower(trim($currentHost));
        $pick = static function (array $hosts, array $skip) use ($healthByHost, $currentHost): ?string {
            foreach ($hosts as $host) {
                $host = strtolower(trim((string) $host));
                if ($host === '' || $host === $currentHost || isset($skip[$host])) {
                    continue;
                }
                if (($healthByHost[$host] ?? '') === 'up') {
                    return $host;
                }
            }

            return null;
        };

        $fromPool = $pick($orderedHosts, []);
        if ($fromPool !== null || !$allowOtherVerified) {
            return $fromPool;
        }

        $used = [];
        foreach ($orderedHosts as $host) {
            $used[strtolower(trim((string) $host))] = true;
        }

        return $pick($otherHosts, $used);
    }

    /**
     * A landing-page path direct-links only when Guard is holding every page
     * and a chosen offer can take the visit.
     */
    public static function shouldDirectLinkLp(
        string $mode,
        int $heldLandingPages,
        int $enabledLandingPages,
        bool $backupOfferReady,
        bool $anyOfferReady
    ): bool {
        if ($mode === self::MODE_OFF || $heldLandingPages < 1 || $enabledLandingPages > 0) {
            return false;
        }
        if ($mode === self::MODE_BACKUPS) {
            return $backupOfferReady;
        }
        if ($mode === self::MODE_FULL) {
            return $anyOfferReady || $backupOfferReady;
        }

        return false;
    }

    /**
     * @param array<mixed> $rotation
     */
    public static function countItems(array $rotation, string $kind, bool $enabledOnly): int
    {
        $count = 0;
        $take = static function (array $list) use ($kind, $enabledOnly, &$count): void {
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $type = (string) ($item['type'] ?? 'offer');
                $isLp = $type === 'landing_page';
                if ($kind === 'landing_page' && !$isLp) {
                    continue;
                }
                if ($kind === 'offer' && $isLp) {
                    continue;
                }
                if ($enabledOnly && isset($item['enabled']) && $item['enabled'] === false) {
                    continue;
                }
                $count++;
            }
        };

        if (array_is_list($rotation)) {
            $take($rotation);
        } else {
            foreach (['offers', 'landing_pages'] as $key) {
                if (isset($rotation[$key]) && is_array($rotation[$key])) {
                    $take($rotation[$key]);
                }
            }
            foreach (['lp_path', 'direct_path'] as $path) {
                if (!isset($rotation[$path]) || !is_array($rotation[$path])) {
                    continue;
                }
                foreach (['offers', 'landing_pages'] as $key) {
                    if (isset($rotation[$path][$key]) && is_array($rotation[$path][$key])) {
                        $take($rotation[$path][$key]);
                    }
                }
            }
        }

        return $count;
    }

    /**
     * switch is the old campaign-domain rewrite. The live path hops one visit instead.
     */
    public static function domainAction(string $mode, string $health, bool $replacementHealthy): string
    {
        if ($health !== 'down' || $mode === self::MODE_OFF || !$replacementHealthy) {
            return 'stay';
        }
        if ($mode === self::MODE_BACKUPS || $mode === self::MODE_FULL) {
            return 'switch';
        }

        return 'stay';
    }

    /**
     * @param array<mixed> $rotation
     * @return array{0: array<mixed>, 1: bool}
     */
    public static function applyEnabled(array $rotation, string $kind, int $id, bool $enabled): array
    {
        $changed = false;
        $touch = static function (array $list) use ($kind, $id, $enabled, &$changed): array {
            foreach ($list as $i => $item) {
                if (!is_array($item) || (int) ($item['id'] ?? 0) !== $id) {
                    continue;
                }
                $type = (string) ($item['type'] ?? 'offer');
                $isLp = $type === 'landing_page';
                if ($kind === 'landing_page' && !$isLp) {
                    continue;
                }
                if ($kind === 'offer' && $isLp) {
                    continue;
                }
                $currentlyOn = !array_key_exists('enabled', $item) || $item['enabled'] !== false;
                if ($currentlyOn === $enabled) {
                    continue;
                }
                $list[$i]['enabled'] = $enabled;
                $changed = true;
            }

            return $list;
        };

        if (array_is_list($rotation)) {
            return [$touch($rotation), $changed];
        }
        foreach (['offers', 'landing_pages'] as $key) {
            if (isset($rotation[$key]) && is_array($rotation[$key])) {
                $rotation[$key] = $touch($rotation[$key]);
            }
        }
        foreach (['lp_path', 'direct_path'] as $path) {
            if (!isset($rotation[$path]) || !is_array($rotation[$path])) {
                continue;
            }
            foreach (['offers', 'landing_pages'] as $key) {
                if (isset($rotation[$path][$key]) && is_array($rotation[$path][$key])) {
                    $rotation[$path][$key] = $touch($rotation[$path][$key]);
                }
            }
        }

        return [$rotation, $changed];
    }

    /**
     * @param array<mixed> $rotation
     * @return list<int>
     */
    public static function collectIds(array $rotation, string $kind): array
    {
        $ids = [];
        $take = static function (array $list) use ($kind, &$ids): void {
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $type = (string) ($item['type'] ?? 'offer');
                $isLp = $type === 'landing_page';
                if ($kind === 'landing_page' && !$isLp) {
                    continue;
                }
                if ($kind === 'offer' && $isLp) {
                    continue;
                }
                $ids[$id] = true;
            }
        };

        if (array_is_list($rotation)) {
            $take($rotation);
        } else {
            foreach (['offers', 'landing_pages'] as $key) {
                if (isset($rotation[$key]) && is_array($rotation[$key])) {
                    $take($rotation[$key]);
                }
            }
            foreach (['lp_path', 'direct_path'] as $path) {
                if (!isset($rotation[$path]) || !is_array($rotation[$path])) {
                    continue;
                }
                foreach (['offers', 'landing_pages'] as $key) {
                    if (isset($rotation[$path][$key]) && is_array($rotation[$path][$key])) {
                        $take($rotation[$path][$key]);
                    }
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Put the backup landing page into LP lists when it is not already there.
     *
     * @param array<mixed> $rotation
     * @return array{0: array<mixed>, 1: bool}
     */
    public static function ensureBackupLandingPage(array $rotation, int $backupId): array
    {
        if ($backupId <= 0 || array_is_list($rotation)) {
            return [$rotation, false];
        }
        $changed = false;
        $ensure = static function (array $list) use ($backupId, &$changed): array {
            foreach ($list as $item) {
                if (is_array($item) && (int) ($item['id'] ?? 0) === $backupId) {
                    return $list;
                }
            }
            $list[] = [
                'type' => 'landing_page',
                'id' => $backupId,
                'weight' => 100,
                'enabled' => true,
                'guard_added' => true,
            ];
            $changed = true;

            return $list;
        };
        foreach (['landing_pages'] as $key) {
            if (isset($rotation[$key]) && is_array($rotation[$key])) {
                $rotation[$key] = $ensure($rotation[$key]);
            }
        }
        if (isset($rotation['lp_path']['landing_pages']) && is_array($rotation['lp_path']['landing_pages'])) {
            $rotation['lp_path']['landing_pages'] = $ensure($rotation['lp_path']['landing_pages']);
        }

        return [$rotation, $changed];
    }

    /**
     * @param array<mixed> $rotation
     * @return array{0: array<mixed>, 1: bool}
     */
    public static function removeGuardAdded(array $rotation): array
    {
        if (array_is_list($rotation)) {
            return [$rotation, false];
        }
        $changed = false;
        $strip = static function (array $list) use (&$changed): array {
            $next = [];
            foreach ($list as $item) {
                if (is_array($item) && !empty($item['guard_added'])) {
                    $changed = true;
                    continue;
                }
                $next[] = $item;
            }

            return $next;
        };
        if (isset($rotation['landing_pages']) && is_array($rotation['landing_pages'])) {
            $rotation['landing_pages'] = $strip($rotation['landing_pages']);
        }
        if (isset($rotation['lp_path']['landing_pages']) && is_array($rotation['lp_path']['landing_pages'])) {
            $rotation['lp_path']['landing_pages'] = $strip($rotation['lp_path']['landing_pages']);
        }

        return [$rotation, $changed];
    }
}
