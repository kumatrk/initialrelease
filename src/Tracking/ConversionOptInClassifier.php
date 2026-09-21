<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Classifies conversion event_key values as email / lead opt-ins (BeMob-style).
 * Opt-ins are stored in conversions but counted separately from purchase conversions in stats.
 */
final class ConversionOptInClassifier
{
    /** Canonical keys treated as opt-ins (after ConversionEventKey normalization). */
    public const KEYS = [
        'optin',
        'opt-in',
        'email',
        'lead',
        'subscribe',
    ];

    public static function isOptIn(?string $eventKey): bool
    {
        if ($eventKey === null || $eventKey === '') {
            return false;
        }

        return in_array(strtolower($eventKey), self::KEYS, true);
    }

    /**
     * SQL fragment for IN (...), safe for embedding (fixed allowlist only).
     */
    public static function sqlInList(): string
    {
        $quoted = array_map(
            static fn(string $k): string => "'" . str_replace("'", "''", $k) . "'",
            self::KEYS
        );

        return implode(',', $quoted);
    }
}
