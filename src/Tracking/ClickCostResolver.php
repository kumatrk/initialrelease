<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Resolve click cost from URL params using the traffic source cost_param_key.
 *
 * Prefer the configured key (e.g. TrafficStars "price") over a literal "cost"
 * field. A Worker payload.cost is only used when the keyed param is missing,
 * so old Workers still record something instead of dropping cost to 0.
 *
 * When campaign cost_model is "cpm", the resolved raw number is treated as a
 * CPM rate and stored as per-click dollars (raw / 1000).
 */
final class ClickCostResolver
{
    public const MODEL_CPC = 'cpc';
    public const MODEL_CPM = 'cpm';

    /**
     * @param array<string, mixed> $params
     * @return array{cost: float, currency: string}|null
     */
    public static function resolve(
        array $params,
        ?string $costParamKey,
        mixed $payloadCost = null,
        ?string $payloadCurrency = 'USD',
        mixed $defaultCpc = null,
        ?string $costModel = self::MODEL_CPC
    ): ?array {
        $key = trim((string) ($costParamKey ?? ''));
        if ($key === '') {
            $key = 'cost';
        }

        $fromKey = self::numericValue($params[$key] ?? null);
        if ($fromKey !== null) {
            return self::finalize($fromKey, $payloadCurrency, $costModel);
        }

        $fromPayload = self::numericValue($payloadCost);
        if ($fromPayload !== null) {
            return self::finalize($fromPayload, $payloadCurrency, $costModel);
        }

        $fromDefault = self::numericValue($defaultCpc);
        if ($fromDefault !== null) {
            return self::finalize($fromDefault, 'USD', $costModel);
        }

        return null;
    }

    public static function normalizeModel(?string $costModel): string
    {
        $model = strtolower(trim((string) $costModel));

        return $model === self::MODEL_CPM ? self::MODEL_CPM : self::MODEL_CPC;
    }

    /**
     * Apply CPC/CPM model to an already-resolved raw dollar amount.
     */
    public static function applyModel(float $rawCost, ?string $costModel): float
    {
        if (self::normalizeModel($costModel) === self::MODEL_CPM) {
            return $rawCost / 1000.0;
        }

        return $rawCost;
    }

    /**
     * @return array{cost: float, currency: string}
     */
    private static function finalize(float $raw, ?string $payloadCurrency, ?string $costModel): array
    {
        return [
            'cost' => self::applyModel($raw, $costModel),
            'currency' => ($payloadCurrency !== null && $payloadCurrency !== '') ? $payloadCurrency : 'USD',
        ];
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
