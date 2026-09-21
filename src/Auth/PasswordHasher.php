<?php

declare(strict_types=1);

namespace SimpleKuma\Auth;

/**
 * Argon2id hashing with options safe for both libargon2 and libsodium PHP builds.
 *
 * libsodium's password_hash() implementation only allows threads=1 and throws
 * ValueError: "A thread value other than 1 is not supported by this implementation"
 * when HASH_OPTIONS requests more. PASSWORD_ARGON2_DEFAULT_THREADS is only defined
 * when PHP uses libargon2.
 */
final class PasswordHasher
{
    /**
     * Preferred Argon2id parameters written into new installs.
     * Always use threads=1 so shared-host libsodium PHP never fatals on config values.
     */
    public static function preferredOptions(): array
    {
        return [
            'memory_cost' => self::preferredMemoryCost(),
            'time_cost' => 4,
            'threads' => 1,
        ];
    }

    /**
     * Normalize options from config or callers so password_hash() never fails on threads.
     */
    public static function resolveOptions(?array $options = null): array
    {
        $resolved = $options ?? (defined('HASH_OPTIONS') ? HASH_OPTIONS : self::preferredOptions());

        if (!is_array($resolved)) {
            $resolved = self::preferredOptions();
        }

        $resolved['memory_cost'] = (int) ($resolved['memory_cost'] ?? self::preferredMemoryCost());
        $resolved['time_cost'] = max(1, (int) ($resolved['time_cost'] ?? 4));
        $threads = (int) ($resolved['threads'] ?? 1);

        if (!self::supportsParallelThreads() || $threads < 1) {
            $threads = 1;
        }

        $resolved['threads'] = $threads;

        return $resolved;
    }

    public static function algorithm(): string|int|null
    {
        if (defined('HASH_ALGO')) {
            return HASH_ALGO;
        }

        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * Hash a password with host-safe fallbacks (threads, memory, algorithm).
     *
     * @throws \RuntimeException when hashing cannot complete
     */
    public static function hash(string $password, ?array $options = null): string
    {
        $attempts = [
            self::resolveOptions($options),
            array_merge(self::resolveOptions($options), ['threads' => 1]),
            [
                'memory_cost' => 32768,
                'time_cost' => 4,
                'threads' => 1,
            ],
        ];

        $lastError = null;

        foreach ($attempts as $attemptOptions) {
            try {
                $hash = @password_hash($password, self::algorithm(), $attemptOptions);
                if (is_string($hash) && $hash !== '') {
                    return $hash;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        // Last resort: bcrypt (always available) if Argon2 backend is broken/missing
        try {
            $hash = @password_hash($password, PASSWORD_DEFAULT);
            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        } catch (\Throwable $e) {
            $lastError = $e;
        }

        $detail = $lastError !== null ? $lastError->getMessage() : 'password_hash returned empty';
        throw new \RuntimeException('Password hashing failed: ' . $detail);
    }

    /**
     * Probe whether password hashing works on this PHP build (for installer requirements).
     *
     * @return string|null Error message, or null when OK
     */
    public static function probe(): ?string
    {
        if (!defined('PASSWORD_ARGON2ID') && !defined('PASSWORD_DEFAULT')) {
            return 'PHP password hashing is unavailable on this build.';
        }

        try {
            $hash = self::hash('simplekuma-install-probe');
            if (!password_verify('simplekuma-install-probe', $hash)) {
                return 'Password hash verification failed. Check PHP password / Argon2 support.';
            }
        } catch (\Throwable $e) {
            return 'Password hashing failed: ' . $e->getMessage()
                . ' (common on restricted PHP builds — enable Argon2 or libsodium password hashing).';
        }

        return null;
    }

    /**
     * True when PHP's Argon2 backend accepts threads > 1 (libargon2, not libsodium).
     */
    public static function supportsParallelThreads(): bool
    {
        return defined('PASSWORD_ARGON2_DEFAULT_THREADS');
    }

    private static function preferredMemoryCost(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') {
            return 65536;
        }

        $bytes = self::iniToBytes((string) $limit);
        // Keep headroom under low memory_limit hosts (64–128M)
        if ($bytes > 0 && $bytes < 128 * 1024 * 1024) {
            return 32768;
        }

        return 65536;
    }

    private static function iniToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
