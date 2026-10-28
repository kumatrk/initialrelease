<?php

declare(strict_types=1);

namespace SimpleKuma\Guard;

use mysqli;
use SimpleKuma\Settings\SettingsManager;
use SimpleKuma\Utils\SecretEncryption;

/**
 * Account-wide Grizzly Guard settings. API keys are encrypted at rest.
 */
final class GrizzlyGuardSettings
{
    public const KEY_ENABLED = 'grizzly_guard_enabled';
    public const KEY_ALERT = 'grizzly_guard_alert';
    public const KEY_VT = 'grizzly_guard_vt_key';
    public const KEY_GSB = 'grizzly_guard_gsb_key';
    public const KEY_CURSOR = 'grizzly_guard_cursor';
    public const KEY_POOL = 'grizzly_guard_domain_pool';
    public const KEY_HEALTH = 'grizzly_guard_domain_health';
    public const KEY_ANOMALY = 'grizzly_guard_anomalies';

    public static function isEnabled(SettingsManager $settings): bool
    {
        return (string) $settings->get(self::KEY_ENABLED, '0') === '1';
    }

    public static function secret(SettingsManager $settings, string $key): string
    {
        $raw = trim((string) $settings->get($key, ''));
        if ($raw === '') {
            return '';
        }

        return SecretEncryption::decryptFlexible($raw);
    }

    public static function hasSecret(SettingsManager $settings, string $key): bool
    {
        return self::secret($settings, $key) !== '';
    }

    /**
     * Ordered backup domain ids. An empty slot ends the list.
     *
     * @return list<int>
     */
    public static function domainPool(SettingsManager $settings): array
    {
        $decoded = json_decode((string) $settings->get(self::KEY_POOL, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $id) {
            $id = (int) $id;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            if (count($ids) >= 3) {
                break;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $post
     * @return list<int>
     */
    public static function poolFromPost(array $post): array
    {
        $ids = [];
        foreach (['grizzly_guard_domain_1', 'grizzly_guard_domain_2', 'grizzly_guard_domain_3'] as $key) {
            $id = (int) ($post[$key] ?? 0);
            if ($id <= 0) {
                break;
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Host health written by the checker. Null when the record is missing or older than $maxAgeSeconds.
     *
     * @return array<string, string>|null
     */
    public static function freshHealth(SettingsManager $settings, int $maxAgeSeconds = 1800): ?array
    {
        $decoded = json_decode((string) $settings->get(self::KEY_HEALTH, ''), true);
        if (!is_array($decoded)) {
            return null;
        }
        $checked = strtotime((string) ($decoded['checked_at'] ?? ''));
        if ($checked === false || $checked < time() - $maxAgeSeconds) {
            return null;
        }
        $hosts = $decoded['hosts'] ?? null;
        if (!is_array($hosts)) {
            return null;
        }
        $clean = [];
        foreach ($hosts as $host => $status) {
            $host = strtolower(trim((string) $host));
            $status = (string) $status;
            if ($host !== '' && ($status === 'up' || $status === 'down' || $status === 'unknown')) {
                $clean[$host] = $status;
            }
        }

        return $clean;
    }

    /**
     * @param array<string, string> $hosts
     */
    public static function saveHealth(SettingsManager $settings, array $hosts): void
    {
        $clean = [];
        foreach ($hosts as $host => $status) {
            $host = strtolower(trim((string) $host));
            $status = (string) $status;
            if ($host === '' || ($status !== 'up' && $status !== 'down' && $status !== 'unknown')) {
                continue;
            }
            $clean[$host] = $status;
        }
        $json = json_encode([
            'checked_at' => gmdate('c'),
            'hosts' => $clean,
        ], JSON_UNESCAPED_SLASHES);
        $settings->set(self::KEY_HEALTH, $json === false ? '{}' : $json);
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function saveFromPost(SettingsManager $settings, array $post, ?mysqli $db = null): ?string
    {
        $alert = trim((string) ($post['grizzly_guard_alert'] ?? ''));
        if ($alert !== '' && !filter_var($alert, FILTER_VALIDATE_EMAIL) && !GrizzlyGuardPlan::isHttpUrl($alert)) {
            return 'The alert channel must be an email address or an http(s) URL.';
        }

        $settings->set(self::KEY_ENABLED, !empty($post['grizzly_guard_enabled']) ? '1' : '0');
        $settings->set(self::KEY_ALERT, $alert);
        $settings->set(self::KEY_POOL, self::encodePool(self::verifiedPool($db, self::poolFromPost($post))));
        $settings->set(self::KEY_ANOMALY, self::encodeAnomaly(self::anomalyFromPost($post)));

        if (!empty($post['grizzly_guard_clear_vt'])) {
            $settings->set(self::KEY_VT, '');
        } else {
            $vt = trim((string) ($post['grizzly_guard_vt_key'] ?? ''));
            if ($vt !== '') {
                $settings->set(self::KEY_VT, SecretEncryption::encrypt($vt));
            }
        }

        if (!empty($post['grizzly_guard_clear_gsb'])) {
            $settings->set(self::KEY_GSB, '');
        } else {
            $gsb = trim((string) ($post['grizzly_guard_gsb_key'] ?? ''));
            if ($gsb !== '') {
                $settings->set(self::KEY_GSB, SecretEncryption::encrypt($gsb));
            }
        }

        return null;
    }

    /**
     * Which anomaly alerts are on. Every rule starts off.
     *
     * @return array<string, array{on: bool, value: int, min: int, max: int}>
     */
    public static function anomalyDefaults(): array
    {
        return [
            'conversion_drop' => ['on' => false, 'value' => 50, 'min' => 20, 'max' => 90],
            'revenue_under_cost' => ['on' => false, 'value' => 0, 'min' => 0, 'max' => 0],
            'bot_spike' => ['on' => false, 'value' => 30, 'min' => 10, 'max' => 90],
            'zone_spike' => ['on' => false, 'value' => 3, 'min' => 2, 'max' => 10],
            'postback_failure' => ['on' => false, 'value' => 3, 'min' => 1, 'max' => 50],
            'tracker_errors' => ['on' => false, 'value' => 0, 'min' => 0, 'max' => 0],
            'no_conversions' => ['on' => false, 'value' => 100, 'min' => 20, 'max' => 5000],
        ];
    }

    /**
     * @return array<string, array{on: bool, value: int, min: int, max: int}>
     */
    public static function anomalyConfig(SettingsManager $settings): array
    {
        $config = self::anomalyDefaults();
        $decoded = json_decode((string) $settings->get(self::KEY_ANOMALY, ''), true);
        if (!is_array($decoded)) {
            return $config;
        }
        foreach ($config as $id => $rule) {
            $saved = $decoded[$id] ?? null;
            if (!is_array($saved)) {
                continue;
            }
            $config[$id]['on'] = !empty($saved['on']);
            if ($rule['max'] > 0) {
                $config[$id]['value'] = max($rule['min'], min($rule['max'], (int) ($saved['value'] ?? $rule['value'])));
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, array{on: bool, value: int, min: int, max: int}>
     */
    public static function anomalyFromPost(array $post): array
    {
        $config = self::anomalyDefaults();
        foreach ($config as $id => $rule) {
            $config[$id]['on'] = !empty($post['grizzly_anomaly_' . $id]);
            if ($rule['max'] > 0) {
                $config[$id]['value'] = max($rule['min'], min($rule['max'], (int) ($post['grizzly_anomaly_' . $id . '_value'] ?? $rule['value'])));
            }
        }

        return $config;
    }

    /**
     * @param array<string, array{on: bool, value: int, min: int, max: int}> $config
     */
    private static function encodeAnomaly(array $config): string
    {
        $stored = [];
        foreach ($config as $id => $rule) {
            $stored[$id] = ['on' => !empty($rule['on']), 'value' => (int) $rule['value']];
        }
        $json = json_encode($stored);

        return $json === false ? '{}' : $json;
    }

    /**
     * @param list<int> $ids
     */
    private static function encodePool(array $ids): string
    {
        $json = json_encode(array_values($ids));

        return $json === false ? '[]' : $json;
    }

    /**
     * Drop ids that are not verified tracking domains.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function verifiedPool(?mysqli $db, array $ids): array
    {
        if ($db === null || $ids === []) {
            return [];
        }
        $safe = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $safe[] = $id;
            }
        }
        if ($safe === []) {
            return [];
        }
        $in = implode(',', $safe);
        $result = $db->query(
            "SELECT id FROM tracking_domains WHERE id IN ({$in}) AND status IN ('verified', 'verified_manual')"
        );
        $allowed = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $allowed[(int) $row['id']] = true;
            }
        }
        $kept = [];
        foreach ($safe as $id) {
            if (isset($allowed[$id]) && !in_array($id, $kept, true)) {
                $kept[] = $id;
            }
        }

        return $kept;
    }
}
