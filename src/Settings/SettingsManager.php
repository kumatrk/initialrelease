<?php

declare(strict_types=1);

namespace SimpleKuma\Settings;

use mysqli;

/**
 * Settings Manager
 * Handles application settings storage and retrieval
 */
class SettingsManager
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /** @var array<string, mixed> Per-request cache */
    private array $cache = [];

    /**
     * Get setting value
     */
    public function get(string $key, $default = null)
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $value = $row ? $row['value'] : $default;
        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Fetch multiple settings in one query (hot-path friendly).
     *
     * @param list<string> $keys
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function getMany(array $keys, array $defaults = []): array
    {
        $out = [];
        $missing = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->cache)) {
                $out[$key] = $this->cache[$key];
            } else {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            $types = str_repeat('s', count($missing));
            $stmt = $this->db->prepare("SELECT `key`, value FROM settings WHERE `key` IN ({$placeholders})");
            if ($stmt) {
                $stmt->bind_param($types, ...$missing);
                $stmt->execute();
                $result = $stmt->get_result();
                $found = [];
                while ($row = $result->fetch_assoc()) {
                    $found[$row['key']] = $row['value'];
                }
                foreach ($missing as $key) {
                    $value = array_key_exists($key, $found)
                        ? $found[$key]
                        : ($defaults[$key] ?? null);
                    $this->cache[$key] = $value;
                    $out[$key] = $value;
                }
            } else {
                foreach ($missing as $key) {
                    $value = $defaults[$key] ?? null;
                    $this->cache[$key] = $value;
                    $out[$key] = $value;
                }
            }
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $out)) {
                $out[$key] = $defaults[$key] ?? null;
            }
        }

        return $out;
    }

    /**
     * Set setting value
     */
    public function set(string $key, $value): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO settings (`key`, value, created_at) 
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()"
        );
        $stmt->bind_param('sss', $key, $value, $value);
        $ok = $stmt->execute();
        if ($ok) {
            $this->cache[$key] = $value;
        }
        return $ok;
    }

    /**
     * Get all settings
     */
    public function getAll(): array
    {
        $result = $this->db->query("SELECT `key`, value FROM settings");
        $settings = [];
        
        while ($row = $result->fetch_assoc()) {
            $settings[$row['key']] = $row['value'];
        }

        return $settings;
    }

    /**
     * Set multiple settings at once
     */
    public function setMultiple(array $settings): bool
    {
        foreach ($settings as $key => $value) {
            if (!$this->set($key, $value)) {
                return false;
            }
        }
        return true;
    }
}


