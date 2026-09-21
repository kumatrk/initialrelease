<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

/**
 * Parsed honeycomb.json from a local addon folder or zip.
 *
 * @phpstan-type ManifestArray array{
 *   slug: string,
 *   name: string,
 *   version: string,
 *   type: string,
 *   provider_key?: string,
 *   min_kuma: string,
 *   provides: list<string>,
 *   autoload?: array{psr-4?: array<string, string>},
 *   bootstrap?: string
 * }
 */
final class Manifest
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors = self::validate($data);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        return new self($data);
    }

    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('honeycomb.json is missing.');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException('Could not read honeycomb.json.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('honeycomb.json is not valid JSON.');
        }

        return self::fromArray($decoded);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function validate(array $data): array
    {
        $errors = [];
        $slug = trim((string) ($data['slug'] ?? ''));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $errors[] = 'Invalid or missing slug.';
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || strlen($name) > 191) {
            $errors[] = 'Invalid or missing name.';
        }
        $version = trim((string) ($data['version'] ?? ''));
        if ($version === '' || strlen($version) > 32) {
            $errors[] = 'Invalid or missing version.';
        }
        $type = trim((string) ($data['type'] ?? ''));
        if (!in_array($type, HoneycombConfig::ALLOWED_TYPES, true)) {
            $errors[] = 'Unsupported Honeycomb type (allowed: traffic_source, utility, other).';
        }
        $minKuma = trim((string) ($data['min_kuma'] ?? ''));
        if ($minKuma === '') {
            $errors[] = 'Missing min_kuma.';
        }
        $provides = $data['provides'] ?? [];
        if (!is_array($provides) || $provides === []) {
            $errors[] = 'Missing provides list.';
        } else {
            foreach ($provides as $cap) {
                if (!is_string($cap) || !in_array($cap, HoneycombConfig::ALLOWED_PROVIDES, true)) {
                    $errors[] = 'Unknown capability in provides.';
                    break;
                }
            }
        }
        $providerKey = trim((string) ($data['provider_key'] ?? ''));
        if ($providerKey !== '' && preg_match('/^[a-z0-9_]+$/', $providerKey) !== 1) {
            $errors[] = 'Invalid provider_key.';
        }

        return $errors;
    }

    public function slug(): string
    {
        return (string) $this->data['slug'];
    }

    public function name(): string
    {
        return (string) $this->data['name'];
    }

    public function version(): string
    {
        return (string) $this->data['version'];
    }

    public function type(): string
    {
        return (string) $this->data['type'];
    }

    public function providerKey(): ?string
    {
        $key = trim((string) ($this->data['provider_key'] ?? ''));
        return $key !== '' ? $key : null;
    }

    public function minKuma(): string
    {
        return (string) $this->data['min_kuma'];
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        /** @var list<string> $provides */
        $provides = array_values(array_filter(
            is_array($this->data['provides'] ?? null) ? $this->data['provides'] : [],
            'is_string'
        ));
        return $provides;
    }

    public function bootstrapClass(): ?string
    {
        $boot = trim((string) ($this->data['bootstrap'] ?? ''));
        return $boot !== '' ? $boot : null;
    }

    /**
     * @return array<string, string>
     */
    public function psr4Map(): array
    {
        $autoload = $this->data['autoload'] ?? null;
        if (!is_array($autoload)) {
            return [];
        }
        $psr4 = $autoload['psr-4'] ?? [];
        if (!is_array($psr4)) {
            return [];
        }
        $out = [];
        foreach ($psr4 as $prefix => $rel) {
            if (is_string($prefix) && is_string($rel) && $prefix !== '' && $rel !== '') {
                $out[$prefix] = $rel;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function meetsMinKuma(string $installedVersion): bool
    {
        return version_compare($installedVersion, $this->minKuma(), '>=');
    }
}
