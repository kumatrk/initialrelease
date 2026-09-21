<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;
use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Update\UpdateChecker;
use Throwable;

/**
 * Fetches catalog.json (JSON only) from the Honeycomb traffic-sources repo.
 */
final class CatalogClient
{
    public function __construct(
        private mysqli $db,
        private SettingsManager $settings,
        private AllowlistedHttpClient $http = new AllowlistedHttpClient()
    ) {
    }

    /**
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   schema_version?: int,
     *   updated_at?: string,
     *   addons: list<array<string, mixed>>,
     *   repository: string,
     *   ref: string,
     *   catalog_url: string,
     *   fetched_at?: string,
     *   from_cache?: bool
     * }
     */
    public function fetch(bool $bypassCache = false): array
    {
        $repo = HoneycombConfig::catalogRepository($this->settings);
        $ref = HoneycombConfig::catalogRef($this->settings);
        $url = HoneycombConfig::catalogRawUrl($repo, $ref);

        if (!$bypassCache) {
            $cached = $this->readCache();
            if ($cached !== null) {
                $cached['from_cache'] = true;
                $cached['repository'] = $repo;
                $cached['ref'] = $ref;
                $cached['catalog_url'] = $url;
                return $cached;
            }
        }

        try {
            $response = $this->http->get($url);
            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Catalog is not valid JSON.');
            }
            $payload = $this->normalizeCatalog($decoded, $repo, $ref, $url);
            $this->writeCache($payload);
            $this->settings->set(
                HoneycombConfig::SETTING_LAST_CHECK,
                json_encode([
                    'checked_at' => $payload['fetched_at'],
                    'ok' => true,
                    'addon_count' => count($payload['addons']),
                    'error' => null,
                ], JSON_THROW_ON_ERROR)
            );
            return $payload;
        } catch (Throwable $e) {
            $this->settings->set(
                HoneycombConfig::SETTING_LAST_CHECK,
                json_encode([
                    'checked_at' => gmdate('c'),
                    'ok' => false,
                    'addon_count' => 0,
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR)
            );
            $cached = $this->readCache();
            if ($cached !== null) {
                $cached['from_cache'] = true;
                $cached['ok'] = false;
                $cached['error'] = $e->getMessage();
                $cached['repository'] = $repo;
                $cached['ref'] = $ref;
                $cached['catalog_url'] = $url;
                return $cached;
            }

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'addons' => [],
                'repository' => $repo,
                'ref' => $ref,
                'catalog_url' => $url,
            ];
        }
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function normalizeCatalog(array $decoded, string $repo, string $ref, string $url): array
    {
        $schema = (int) ($decoded['schema_version'] ?? 0);
        if ($schema !== 1) {
            throw new \RuntimeException('Unsupported catalog schema_version (expected 1).');
        }
        $rawAddons = $decoded['addons'] ?? [];
        if (!is_array($rawAddons)) {
            throw new \RuntimeException('Catalog addons must be a list.');
        }

        $addons = [];
        $kumaVersion = (new UpdateChecker($this->db))->getCurrentVersion();
        foreach ($rawAddons as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entry = $this->normalizeCatalogEntry($row, $repo, $kumaVersion);
            if ($entry !== null) {
                $addons[] = $entry;
            }
        }

        return [
            'ok' => true,
            'schema_version' => 1,
            'updated_at' => (string) ($decoded['updated_at'] ?? ''),
            'addons' => $addons,
            'repository' => $repo,
            'ref' => $ref,
            'catalog_url' => $url,
            'fetched_at' => gmdate('c'),
            'from_cache' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function normalizeCatalogEntry(array $row, string $repo, string $kumaVersion): ?array
    {
        $slug = trim((string) ($row['slug'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $version = trim((string) ($row['version'] ?? ''));
        $type = trim((string) ($row['type'] ?? 'traffic_source'));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || $name === '' || $version === '') {
            return null;
        }
        if (!in_array($type, HoneycombConfig::ALLOWED_TYPES, true)) {
            return null;
        }
        $zipUrl = trim((string) ($row['zip_url'] ?? ''));
        $sha256 = strtolower(trim((string) ($row['sha256'] ?? '')));
        if ($zipUrl !== '') {
            try {
                $this->http->assertUrlBelongsToRepo($zipUrl, $repo);
            } catch (\RuntimeException) {
                return null;
            }
        }
        if ($sha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return null;
        }
        $minKuma = trim((string) ($row['min_kuma'] ?? '0.0.0'));
        $compatible = version_compare($kumaVersion, $minKuma, '>=');

        return [
            'slug' => $slug,
            'name' => $name,
            'version' => $version,
            'type' => $type,
            'provider_key' => trim((string) ($row['provider_key'] ?? '')) ?: null,
            'min_kuma' => $minKuma,
            'summary' => trim((string) ($row['summary'] ?? '')),
            'zip_url' => $zipUrl,
            'sha256' => $sha256,
            'compatible' => $compatible,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        $path = HoneycombPaths::catalogCacheFile();
        if (!is_file($path)) {
            return null;
        }
        $age = time() - (int) filemtime($path);
        if ($age > 3600) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeCache(array $payload): void
    {
        HoneycombPaths::ensureRuntimeDirs();
        $path = HoneycombPaths::catalogCacheFile();
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
