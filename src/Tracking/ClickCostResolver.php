<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Resolve click cost from URL params using the traffic source cost_param_key.
 *
 * Prefer the configured key (e.g. TrafficStars "price") over a literal "cost"
 * field. A Worker payload.cost is only used when the keyed param is missing,
 * so old Workers still record something instead of dropping cost to 0.
 */
final class ClickCostResolver
{
    /**
     * @param array<string, mixed> $params
     * @return array{cost: float, currency: string}|null
     */
    public static function resolve(
        array $params,
        ?string $costParamKey,
        mixed $payloadCost = null,
        ?string $payloadCurrency = 'USD',
        mixed $defaultCpc = null
    ): ?array {
        $key = trim((string) ($costParamKey ?? ''));
        if ($key === '') {
            $key = 'cost';
        }

        $fromKey = self::numericValue($params[$key] ?? null);
        if ($fromKey !== null) {
            return [
                'cost' => $fromKey,
                'currency' => ($payloadCurrency !== null && $payloadCurrency !== '') ? $payloadCurrency : 'USD',
            ];
        }

        $fromPayload = self::numericValue($payloadCost);
        if ($fromPayload !== null) {
            return [
                'cost' => $fromPayload,
                'currency' => ($payloadCurrency !== null && $payloadCurrency !== '') ? $payloadCurrency : 'USD',
            ];
        }

        $fromDefault = self::numericValue($defaultCpc);
        if ($fromDefault !== null) {
            return [
                'cost' => $fromDefault,
                'currency' => 'USD',
            ];
        }

        return null;
    }

    private static function numericValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
