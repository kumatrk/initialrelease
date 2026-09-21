<?php

declare(strict_types=1);

namespace SimpleKuma\Enrichment;

/**
 * Normalize Accept-Language (or edge payload) to a short primary language tag.
 */
final class LanguageDetector
{
    /**
     * @param string|null $raw Header value, edge payload language, or empty
     */
    public static function primaryTag(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '' || strcasecmp($raw, 'N/A') === 0) {
            return null;
        }

        // First list item, strip quality / extensions: "en-US,en;q=0.9" → "en-US"
        $first = trim(explode(',', $raw)[0]);
        $first = trim(explode(';', $first)[0]);
        if ($first === '') {
            return null;
        }

        // Prefer primary subtag (en from en-US) for stable breakdowns
        if (preg_match('/^([A-Za-z]{2,3})(?:[-_][A-Za-z0-9]+)*$/', $first, $m)) {
            return strtolower($m[1]);
        }

        // Fallback: truncate safely
        $tag = strtolower(substr($first, 0, 16));
        return $tag !== '' ? $tag : null;
    }

    public static function fromRequest(): ?string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
        return self::primaryTag(is_string($header) ? $header : null);
    }
}
