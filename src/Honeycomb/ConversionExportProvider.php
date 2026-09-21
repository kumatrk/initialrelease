<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

/**
 * Honeycomb addons that push conversions to an external Events/CAPI-style API.
 * Core calls registered providers once from PostbackDispatcher; networks live in addons.
 */
interface ConversionExportProvider
{
    public function addonSlug(): string;

    /**
     * Human label for campaign settings (e.g. "Whop Ads — lead").
     */
    public function label(): string;

    /**
     * @param array<string, mixed> $conversion Row from PostbackDispatcher::getConversionData
     * @return array{
     *   ok: bool,
     *   status: string,
     *   message: string,
     *   url?: string,
     *   http_status?: int,
     *   request_body?: string|null,
     *   response_body?: string|null
     * }
     */
    public function exportConversion(array $conversion): array;
}
