<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Resolves Meta CAPI event_name and stable identity fields for a conversion.
 */
final class MetaCapiEventResolver
{
    public const STANDARD_EVENTS = [
        'Purchase',
        'Lead',
        'CompleteRegistration',
        'Contact',
        'FindLocation',
        'Schedule',
        'Search',
        'StartTrial',
        'SubmitApplication',
        'Subscribe',
        'ViewContent',
        'AddPaymentInfo',
        'AddToCart',
        'AddToWishlist',
        'CustomizeProduct',
        'Donate',
        'InitiateCheckout',
        'PageView',
    ];

    /**
     * @param array<string, mixed>|null $mappingDecoded event_key => Meta event_name
     * @return array{event_name: string, mapped: bool, mapping_error: ?string}
     */
    public static function resolveEventName(
        ?string $eventKey,
        ?array $mappingDecoded,
        ?string $defaultEventType
    ): array {
        $default = self::sanitizeMetaEventName($defaultEventType) ?? 'Purchase';

        if ($eventKey === null || $eventKey === '') {
            return [
                'event_name' => $default,
                'mapped' => false,
                'mapping_error' => null,
            ];
        }

        if ($mappingDecoded === null) {
            return [
                'event_name' => $default,
                'mapped' => false,
                'mapping_error' => null,
            ];
        }

        if (!array_key_exists($eventKey, $mappingDecoded)) {
            return [
                'event_name' => $default,
                'mapped' => false,
                'mapping_error' => null,
            ];
        }

        $mappedRaw = $mappingDecoded[$eventKey];
        if (!is_string($mappedRaw)) {
            return [
                'event_name' => $default,
                'mapped' => false,
                'mapping_error' => "Mapping for {$eventKey} is not a string",
            ];
        }

        $mapped = self::sanitizeMetaEventName($mappedRaw);
        if ($mapped === null) {
            return [
                'event_name' => $default,
                'mapped' => false,
                'mapping_error' => "Invalid mapped Meta event for {$eventKey}: {$mappedRaw}",
            ];
        }

        return [
            'event_name' => $mapped,
            'mapped' => true,
            'mapping_error' => null,
        ];
    }

    /**
     * Decode event_mapping_json; on failure return empty map + error (never throw).
     *
     * @return array{mapping: array<string, string>, error: ?string}
     */
    public static function decodeMapping(mixed $json): array
    {
        if ($json === null || $json === '' || $json === false) {
            return ['mapping' => [], 'error' => null];
        }

        if (is_array($json)) {
            return ['mapping' => self::normalizeMappingArray($json), 'error' => null];
        }

        if (!is_string($json)) {
            return ['mapping' => [], 'error' => 'event_mapping_json is not a string or array'];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return ['mapping' => [], 'error' => 'Malformed event_mapping_json'];
        }

        return ['mapping' => self::normalizeMappingArray($decoded), 'error' => null];
    }

    /**
     * Stable Meta event_id for deduplication / retries.
     */
    public static function resolveMetaEventId(array $conversion): string
    {
        $inbound = $conversion['event_id'] ?? null;
        if (is_string($inbound)) {
            $validated = self::sanitizeInboundEventId($inbound);
            if ($validated !== null) {
                return $validated;
            }
        }

        $id = (int)($conversion['id'] ?? 0);
        return 'conv:' . $id;
    }

    /**
     * order_id for commerce-style identity (prefer txid).
     */
    public static function resolveOrderId(array $conversion): string
    {
        $txid = $conversion['txid'] ?? null;
        if (is_string($txid) && trim($txid) !== '') {
            $trimmed = trim($txid);
            if (strlen($trimmed) <= 128) {
                return $trimmed;
            }
        }

        return 'conv:' . (int)($conversion['id'] ?? 0);
    }

    public static function sanitizeMetaEventName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $name = trim($name);
        if ($name === '' || strlen($name) > 50) {
            return null;
        }
        // Meta standard events are PascalCase; custom events allow letters/numbers/underscore
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,49}$/', $name)) {
            return null;
        }

        return $name;
    }

    public static function sanitizeInboundEventId(string $eventId): ?string
    {
        $eventId = trim($eventId);
        if ($eventId === '' || strlen($eventId) > 100) {
            return null;
        }
        // Allow printable non-space identity tokens used by networks / browsers
        if (!preg_match('/^[A-Za-z0-9_.:\\-]{1,100}$/', $eventId)) {
            return null;
        }

        return $eventId;
    }

    /**
     * Validate and normalize a mapping payload from UI/API.
     *
     * @param array<string, mixed> $raw
     * @return array{ok: bool, mapping: array<string, string>, errors: list<string>}
     */
    public static function validateMappingInput(array $raw): array
    {
        $mapping = [];
        $errors = [];

        foreach ($raw as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                $errors[] = 'Mapping keys must be strings';
                continue;
            }
            $canonicalKey = ConversionEventKey::canonicalize((string)$key);
            if ($canonicalKey === null) {
                $errors[] = "Invalid inbound event key: {$key}";
                continue;
            }
            if (!is_string($value)) {
                $errors[] = "Meta event for {$canonicalKey} must be a string";
                continue;
            }
            $metaName = self::sanitizeMetaEventName($value);
            if ($metaName === null) {
                $errors[] = "Invalid Meta event name for {$canonicalKey}: {$value}";
                continue;
            }
            $mapping[$canonicalKey] = $metaName;
        }

        return [
            'ok' => $errors === [],
            'mapping' => $mapping,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<mixed, mixed> $decoded
     * @return array<string, string>
     */
    private static function normalizeMappingArray(array $decoded): array
    {
        $out = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            // Support nested Phase-2-ish shape without requiring it
            if (is_array($value) && isset($value['meta_event_name']) && is_string($value['meta_event_name'])) {
                $value = $value['meta_event_name'];
            }
            if (!is_string($value)) {
                continue;
            }
            $canonicalKey = ConversionEventKey::canonicalize((string)$key);
            $metaName = self::sanitizeMetaEventName($value);
            if ($canonicalKey === null || $metaName === null) {
                continue;
            }
            $out[$canonicalKey] = $metaName;
        }

        return $out;
    }
}
