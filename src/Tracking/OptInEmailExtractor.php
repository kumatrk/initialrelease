<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Pull a plaintext email from click extra_json / postback source_json when present.
 */
final class OptInEmailExtractor
{
    /** @var list<string> */
    private const KEYS = ['email', 'em', 'e', 'user_email', 'lead_email'];

    public static function fromBags(?array ...$bags): ?string
    {
        foreach ($bags as $bag) {
            if (!is_array($bag)) {
                continue;
            }
            $found = self::search($bag, 0);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function search(array $data, int $depth): ?string
    {
        if ($depth > 3) {
            return null;
        }

        foreach (self::KEYS as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                continue;
            }
            $value = trim($data[$key]);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        foreach (['traffic_source_tokens', 'all_params', 'params'] as $nest) {
            if (!empty($data[$nest]) && is_array($data[$nest])) {
                $found = self::search($data[$nest], $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
