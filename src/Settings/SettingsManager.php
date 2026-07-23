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

    /**
     * Get setting value
     */
    public function get(string $key, $default = null)
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? $row['value'] : $default;
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
        return $stmt->execute();
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


