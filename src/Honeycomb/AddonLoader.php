<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;
use SimpleKuma\Update\UpdateChecker;
use Throwable;

/**
 * Loads enabled addons from the local honeycomb/addons directory only.
 */
final class AddonLoader
{
    private HoneycombKernel $kernel;
    private bool $booted = false;

    /** @var array<string, callable(string): void> */
    private array $psr4 = [];

    private AddonStore $store;

    public function __construct(
        private mysqli $db,
        ?AddonStore $store = null
    ) {
        $this->store = $store ?? new AddonStore($db);
        $this->kernel = new HoneycombKernel($db);
    }

    public function kernel(): HoneycombKernel
    {
        $this->boot();
        return $this->kernel;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function describeInstalled(): array
    {
        HoneycombPaths::ensureRuntimeDirs();
        $dbRows = [];
        foreach ($this->store->all() as $row) {
            $dbRows[(string) $row['slug']] = $row;
        }

        $kumaVersion = (new UpdateChecker($this->db))->getCurrentVersion();
        $out = [];
        $root = HoneycombPaths::addonsRoot();
        if (is_dir($root)) {
            $dirs = scandir($root) ?: [];
            foreach ($dirs as $name) {
                if ($name === '.' || $name === '..' || $name === '.gitkeep') {
                    continue;
                }
                $dir = $root . DIRECTORY_SEPARATOR . $name;
                if (!is_dir($dir)) {
                    continue;
                }
                $entry = [
                    'slug' => $name,
                    'on_disk' => true,
                    'valid' => false,
                    'status' => $dbRows[$name]['status'] ?? 'disabled',
                    'error' => null,
                ];
                try {
                    $manifest = Manifest::fromJsonFile($dir . DIRECTORY_SEPARATOR . 'honeycomb.json');
                    if ($manifest->slug() !== $name) {
                        throw new \RuntimeException('Folder name must match honeycomb.json slug.');
                    }
                    $entry['valid'] = true;
                    $entry['name'] = $manifest->name();
                    $entry['version'] = $manifest->version();
                    $entry['type'] = $manifest->type();
                    $entry['provider_key'] = $manifest->providerKey();
                    $entry['min_kuma'] = $manifest->minKuma();
                    $entry['compatible'] = $manifest->meetsMinKuma($kumaVersion);
                    $entry['provides'] = $manifest->provides();
                } catch (Throwable $e) {
                    $entry['error'] = $e->getMessage();
                    $entry['name'] = $dbRows[$name]['name'] ?? $name;
                    $entry['version'] = $dbRows[$name]['version'] ?? '';
                }
                unset($dbRows[$name]);
                $out[] = $entry;
            }
        }

        foreach ($dbRows as $slug => $row) {
            $out[] = [
                'slug' => $slug,
                'on_disk' => false,
                'valid' => false,
                'status' => $row['status'] ?? 'disabled',
                'name' => $row['name'] ?? $slug,
                'version' => $row['version'] ?? '',
                'error' => 'Addon is recorded but files are missing from honeycomb/addons.',
            ];
        }

        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        return $out;
    }

    public function boot(): HoneycombKernel
    {
        if ($this->booted) {
            return $this->kernel;
        }
        $this->booted = true;
        HoneycombPaths::ensureRuntimeDirs();
        $this->registerAutoloader();

        $kumaVersion = (new UpdateChecker($this->db))->getCurrentVersion();
        foreach ($this->store->all() as $row) {
            if (($row['status'] ?? '') !== 'enabled') {
                continue;
            }
            $slug = (string) $row['slug'];
            $dir = HoneycombPaths::addonDir($slug);
            $manifestPath = $dir . DIRECTORY_SEPARATOR . 'honeycomb.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            try {
                $manifest = Manifest::fromJsonFile($manifestPath);
                if ($manifest->slug() !== $slug || !$manifest->meetsMinKuma($kumaVersion)) {
                    continue;
                }
                foreach ($manifest->psr4Map() as $prefix => $rel) {
                    $base = $dir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
                    $this->psr4[$prefix] = static function (string $class) use ($prefix, $base): void {
                        if (!str_starts_with($class, $prefix)) {
                            return;
                        }
                        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
                        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
                            return;
                        }
                        $file = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative . '.php';
                        $baseReal = realpath($base);
                        $fileReal = realpath($file);
                        if ($baseReal === false || $fileReal === false) {
                            return;
                        }
                        if ($fileReal !== $baseReal && !str_starts_with($fileReal, $baseReal . DIRECTORY_SEPARATOR)) {
                            return;
                        }
                        if (is_file($fileReal)) {
                            require_once $fileReal;
                        }
                    };
                }
                $bootClass = $manifest->bootstrapClass();
                if ($bootClass !== null && class_exists($bootClass)) {
                    $instance = new $bootClass();
                    if ($instance instanceof AddonBootstrap) {
                        $instance->register($this->kernel);
                    }
                }
            } catch (Throwable $e) {
                error_log('Honeycomb addon boot failed for ' . $slug . ': ' . $e->getMessage());
            }
        }

        return $this->kernel;
    }

    private function registerAutoloader(): void
    {
        spl_autoload_register(function (string $class): void {
            foreach ($this->psr4 as $loader) {
                $loader($class);
                if (class_exists($class, false)) {
                    return;
                }
            }
        });
    }
}
