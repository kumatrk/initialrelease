<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Canonical inbound funnel event key from postback/pixel params.
 *
 * Precedence: et > event_type > event
 * Normalization: trim, lowercase, [a-z0-9][a-z0-9_-]{0,63}
 */
final class ConversionEventKey
{
    public const MAX_LENGTH = 64;

    /**
     * Resolve canonical event_key from request-like arrays (GET/POST merged or data bag).
     *
     * @param array<string, mixed> $params
     * @return array{event_key: ?string, conflict: bool, invalid: bool, raw_winner: ?string}
     */
    public static function resolveFromParams(array $params): array
    {
        $candidates = [];
        foreach (['et', 'event_type', 'event'] as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $raw = $params[$key];
            if ($raw === null || $raw === '' || $raw === false) {
                continue;
            }
            if (is_array($raw)) {
                continue;
            }
            $candidates[$key] = trim((string)$raw);
        }

        if ($candidates === []) {
            return [
                'event_key' => null,
                'conflict' => false,
                'invalid' => false,
                'raw_winner' => null,
            ];
        }

        // Precedence: et > event_type > event
        $winnerKey = array_key_exists('et', $candidates)
            ? 'et'
            : (array_key_exists('event_type', $candidates) ? 'event_type' : 'event');
        $rawWinner = $candidates[$winnerKey];

        $normalizedValues = [];
        foreach ($candidates as $raw) {
            $canonical = self::canonicalize($raw);
            if ($canonical !== null) {
                $normalizedValues[$canonical] = true;
            }
        }
        $conflict = count($normalizedValues) > 1;
        if ($conflict) {
            error_log(
                'ConversionEventKey: conflicting aliases '
                . json_encode($candidates)
                . "; using precedence winner={$winnerKey}"
            );
        }

        $canonical = self::canonicalize($rawWinner);
        $invalid = ($canonical === null && $rawWinner !== '');
        if ($invalid) {
            error_log("ConversionEventKey: invalid event key rejected: {$rawWinner}");
        }

        return [
            'event_key' => $canonical,
            'conflict' => $conflict,
            'invalid' => $invalid,
            'raw_winner' => $rawWinner,
        ];
    }

    /**
     * Normalize a raw key; return null if empty or invalid.
     */
    public static function canonicalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $key = strtolower(trim($raw));
        if ($key === '') {
            return null;
        }
        if (strlen($key) > self::MAX_LENGTH) {
            return null;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key)) {
            return null;
        }

        return $key;
    }
}
