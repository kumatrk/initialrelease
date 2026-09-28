<?php

declare(strict_types=1);

namespace SimpleKuma\Campaign;

/**
 * Parse / normalize inactive-redirect fields from form POST or API arrays.
 */
final class InactiveRedirectParser
{
    public const MODE_OFF = 'off';
    public const MODE_CAMPAIGN = 'campaign';
    public const MODE_URL = 'url';

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_OFF, self::MODE_CAMPAIGN, self::MODE_URL];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   inactive_redirect_mode: string,
     *   inactive_redirect_campaign_id: int|null,
     *   inactive_redirect_url: string|null
     * }
     */
    public static function fromInput(array $input): array
    {
        $mode = strtolower(trim((string) ($input['inactive_redirect_mode'] ?? self::MODE_OFF)));
        if (!in_array($mode, self::modes(), true)) {
            $mode = self::MODE_OFF;
        }

        $campaignId = !empty($input['inactive_redirect_campaign_id'])
            ? (int) $input['inactive_redirect_campaign_id']
            : null;
        if ($campaignId !== null && $campaignId <= 0) {
            $campaignId = null;
        }

        $url = isset($input['inactive_redirect_url'])
            ? trim((string) $input['inactive_redirect_url'])
            : '';
        if ($url === '') {
            $url = null;
        }

        if ($mode === self::MODE_OFF) {
            $campaignId = null;
            $url = null;
        } elseif ($mode === self::MODE_CAMPAIGN) {
            $url = null;
        } else {
            $campaignId = null;
        }

        return [
            'inactive_redirect_mode' => $mode,
            'inactive_redirect_campaign_id' => $campaignId,
            'inactive_redirect_url' => $url,
        ];
    }

    /**
     * @param array<string, mixed> $data Normalized fields from fromInput()
     * @return array<string, string> Field => error message
     */
    public static function validateFields(array $data, ?int $editingCampaignId = null): array
    {
        $errors = [];
        $mode = $data['inactive_redirect_mode'] ?? self::MODE_OFF;

        if ($mode === self::MODE_CAMPAIGN) {
            $targetId = $data['inactive_redirect_campaign_id'] ?? null;
            if ($targetId === null || (int) $targetId <= 0) {
                $errors['inactive_redirect_campaign_id'] = 'Select a campaign to receive traffic when this campaign is paused or archived.';
            } elseif ($editingCampaignId !== null && (int) $targetId === $editingCampaignId) {
                $errors['inactive_redirect_campaign_id'] = 'Inactive redirect cannot point to the same campaign.';
            }
        } elseif ($mode === self::MODE_URL) {
            $url = trim((string) ($data['inactive_redirect_url'] ?? ''));
            if ($url === '') {
                $errors['inactive_redirect_url'] = 'Enter a URL for inactive traffic.';
            } elseif (!self::isAllowedHttpUrl($url)) {
                $errors['inactive_redirect_url'] = 'Inactive redirect URL must be a valid http or https address.';
            }
        }

        return $errors;
    }

    public static function isAllowedHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return $scheme === 'http' || $scheme === 'https';
    }
}
