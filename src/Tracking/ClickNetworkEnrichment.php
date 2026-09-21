<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

use SimpleKuma\Enrichment\ConnectionTypeNormalizer;
use SimpleKuma\Enrichment\LanguageDetector;
use SimpleKuma\GeoIP\AsnLookup;

/**
 * Shared isp / connection_type / language enrichment for click inserts.
 */
final class ClickNetworkEnrichment
{
    /**
     * Merge ASN + TS tokens + language into enrichment fields.
     *
     * @param array<string, mixed> $geoData
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function enrich(array $geoData, ?string $ip, array $params, ?string $languageRaw = null): array
    {
        $isp = self::nonEmptyString($geoData['isp'] ?? null);
        $connectionType = ConnectionTypeNormalizer::normalize(
            self::nonEmptyString($geoData['connection_type'] ?? null)
        );

        $asnOrg = null;
        if ($ip) {
            $asn = AsnLookup::instance()->lookup($ip);
            $asnOrg = self::nonEmptyString($asn['isp'] ?? null);
            if ($isp === null && $asnOrg !== null) {
                $isp = $asnOrg;
            }
            if ($connectionType === null && !empty($asn['connection_type'])) {
                $connectionType = $asn['connection_type'];
            }
        }

        // Traffic-source connection tokens override ASN heuristic
        foreach (['connection_type', 'conn', 'connectionType', 'connection'] as $key) {
            if (!isset($params[$key]) || !is_scalar($params[$key])) {
                continue;
            }
            $normalized = ConnectionTypeNormalizer::normalize((string) $params[$key]);
            if ($normalized !== null) {
                $connectionType = $normalized;
                break;
            }
        }

        if ($isp === null) {
            foreach (['isp', 'ISP'] as $key) {
                if (!isset($params[$key]) || !is_scalar($params[$key])) {
                    continue;
                }
                $tokenIsp = self::nonEmptyString((string) $params[$key]);
                if ($tokenIsp !== null) {
                    $isp = $tokenIsp;
                    break;
                }
            }
        }

        if ($connectionType === null && $asnOrg !== null) {
            $connectionType = ConnectionTypeNormalizer::fromAsnOrganization($asnOrg);
        }

        $language = LanguageDetector::primaryTag($languageRaw);
        if ($language === null) {
            $language = LanguageDetector::primaryTag(
                isset($geoData['language']) && is_string($geoData['language']) ? $geoData['language'] : null
            );
        }

        $geoData['isp'] = $isp;
        $geoData['connection_type'] = $connectionType;
        $geoData['language'] = $language;

        return $geoData;
    }

    /**
     * @param callable(string, string): bool $columnExists
     * @param list<?string> $geoBind
     * @param array<string, mixed> $geoData
     * @return array{cols: string, placeholders: string, types: string, bind: list<?string>}
     */
    public static function appendOptionalColumns(
        callable $columnExists,
        string $geoCols,
        string $geoPlaceholders,
        string $geoParamTypes,
        array $geoBind,
        array $geoData
    ): array {
        foreach (
            [
                'isp' => $geoData['isp'] ?? null,
                'connection_type' => $geoData['connection_type'] ?? null,
                'language' => $geoData['language'] ?? null,
            ] as $col => $value
        ) {
            if (!$columnExists('clicks', $col)) {
                continue;
            }
            $geoCols .= ', ' . $col;
            $geoPlaceholders .= ', ?';
            $geoParamTypes .= 's';
            $geoBind[] = is_string($value) || $value === null ? $value : (string) $value;
        }

        return [
            'cols' => $geoCols,
            'placeholders' => $geoPlaceholders,
            'types' => $geoParamTypes,
            'bind' => $geoBind,
        ];
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '' || strcasecmp($s, 'N/A') === 0) {
            return null;
        }
        return $s;
    }
}
