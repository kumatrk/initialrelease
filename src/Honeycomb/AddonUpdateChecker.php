<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;
use SimpleKuma\Settings\SettingsManager;
use Throwable;

/**
 * Compare installed Honeycomb addons against the official catalog.
 * Check path is read-only: never downloads zips or executes remote PHP.
 */
final class AddonUpdateChecker
{
    public const CACHE_TTL_SECONDS = 3600;
    public const SETTING_CACHE = 'honeycomb_update_check_cache';
    public const SETTING_CACHE_TIME = 'honeycomb_update_check_cache_time';

    private CatalogClient $catalog;
    private AddonLoader $loader;

    public function __construct(
        private mysqli $db,
        private SettingsManager $settings,
        ?CatalogClient $catalog = null,
        ?AddonLoader $loader = null
    ) {
        $this->catalog = $catalog ?? new CatalogClient($db, $settings);
        $this->loader = $loader ?? new AddonLoader($db);
    }

    /**
     * @return array{
     *   success: bool,
     *   outdated_count: int,
     *   outdated: list<array{
     *     slug: string,
     *     name: string,
     *     current_version: string,
     *     latest_version: string,
     *     summary: string,
     *     compatible: bool
     *   }>,
     *   fingerprint: string,
     *   checked_at: string,
     *   from_cache?: bool,
     *   error?: string
     * }
     */
    public function checkForUpdates(bool $bypassCache = false): array
    {
        if (!$bypassCache) {
            $cached = $this->getCachedResult(true);
            if ($cached !== null) {
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        try {
            $catalogData = $this->catalog->fetch($bypassCache);
            $installed = $this->loader->describeInstalled();
            $result = $this->compare($installed, $catalogData['addons'] ?? []);
            if (!empty($catalogData['error']) && ($catalogData['ok'] ?? false) === false && $result['outdated_count'] === 0) {
                $result['success'] = false;
                $result['error'] = (string) $catalogData['error'];
            }
            $this->writeCache($result);
            return $result;
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'outdated_count' => 0,
                'outdated' => [],
                'fingerprint' => '',
                'checked_at' => gmdate('c'),
                'error' => $e->getMessage(),
            ];
            // Keep prior cache on hard failure so banners still work.
            $prior = $this->getCachedResult(false);
            if ($prior !== null && ($prior['outdated_count'] ?? 0) > 0) {
                $prior['from_cache'] = true;
                $prior['error'] = $e->getMessage();
                return $prior;
            }
            $this->writeCache($result);
            return $result;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCachedResult(bool $requireFresh = false): ?array
    {
        $raw = (string) $this->settings->get(self::SETTING_CACHE, '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        if ($requireFresh && !$this->isCacheFresh()) {
            return null;
        }
        return $decoded;
    }

    public function isCacheFresh(): bool
    {
        $time = (int) $this->settings->get(self::SETTING_CACHE_TIME, '0');
        if ($time <= 0) {
            return false;
        }
        return (time() - $time) < self::CACHE_TTL_SECONDS;
    }

    public function clearCache(): void
    {
        $this->settings->set(self::SETTING_CACHE, '');
        $this->settings->set(self::SETTING_CACHE_TIME, '0');
    }

    /**
     * Map of slug => update info for installed outdated addons.
     *
     * @return array<string, array{slug: string, name: string, current_version: string, latest_version: string, summary: string, compatible: bool}>
     */
    public function outdatedBySlug(bool $bypassCache = false): array
    {
        $result = $this->checkForUpdates($bypassCache);
        $map = [];
        foreach ($result['outdated'] as $row) {
            $map[(string) $row['slug']] = $row;
        }
        return $map;
    }

    /**
     * @param list<array<string, mixed>> $installed
     * @param list<array<string, mixed>> $catalogAddons
     * @return array{
     *   success: bool,
     *   outdated_count: int,
     *   outdated: list<array{
     *     slug: string,
     *     name: string,
     *     current_version: string,
     *     latest_version: string,
     *     summary: string,
     *     compatible: bool
     *   }>,
     *   fingerprint: string,
     *   checked_at: string
     * }
     */
    public function compare(array $installed, array $catalogAddons): array
    {
        $catalogBySlug = [];
        foreach ($catalogAddons as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = trim((string) ($entry['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $catalogBySlug[$slug] = $entry;
        }

        $outdated = [];
        foreach ($installed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '' || empty($row['valid'])) {
                continue;
            }
            $current = trim((string) ($row['version'] ?? ''));
            if ($current === '' || !isset($catalogBySlug[$slug])) {
                continue;
            }
            $entry = $catalogBySlug[$slug];
            $latest = trim((string) ($entry['version'] ?? ''));
            if ($latest === '') {
                continue;
            }
            // Only offer updates when catalog has a ready zip + checksum.
            $zipUrl = trim((string) ($entry['zip_url'] ?? ''));
            $sha256 = strtolower(trim((string) ($entry['sha256'] ?? '')));
            if ($zipUrl === '' || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                continue;
            }
            if (version_compare($current, $latest) >= 0) {
                continue;
            }
            $outdated[] = [
                'slug' => $slug,
                'name' => (string) ($entry['name'] ?? $row['name'] ?? $slug),
                'current_version' => $current,
                'latest_version' => $latest,
                'summary' => trim((string) ($entry['summary'] ?? '')),
                'compatible' => !empty($entry['compatible']),
            ];
        }

        usort($outdated, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        $fpParts = [];
        foreach ($outdated as $row) {
            $fpParts[] = $row['slug'] . '@' . $row['latest_version'];
        }

        return [
            'success' => true,
            'outdated_count' => count($outdated),
            'outdated' => $outdated,
            'fingerprint' => implode(',', $fpParts),
            'checked_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function writeCache(array $result): void
    {
        try {
            $this->settings->set(self::SETTING_CACHE, json_encode($result, JSON_THROW_ON_ERROR));
            $this->settings->set(self::SETTING_CACHE_TIME, (string) time());
        } catch (Throwable $e) {
            error_log('Honeycomb update check cache write failed: ' . $e->getMessage());
        }
    }
}
