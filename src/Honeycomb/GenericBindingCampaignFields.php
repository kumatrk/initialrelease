<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

/**
 * Default campaign-editor panel for addons that declare campaign_fields:
 * conversion_export checkbox/event name and/or cost_sync remote IDs.
 */
final class GenericBindingCampaignFields implements CampaignFieldsProvider
{
    /**
     * @param list<string> $provides
     */
    public function __construct(
        private string $slug,
        private string $name,
        private string $providerKey,
        private array $provides,
        private int $priority = 100,
    ) {
    }

    /**
     * Build from enabledByProviderKey() / describeInstalled() row.
     *
     * @param array{slug: string, name?: string, provider_key: string, provides?: list<string>} $meta
     */
    public static function fromAddonMeta(array $meta, int $priority = 100): self
    {
        $provides = $meta['provides'] ?? [];
        if (!is_array($provides)) {
            $provides = [];
        }

        return new self(
            (string) $meta['slug'],
            (string) ($meta['name'] ?? $meta['slug']),
            (string) $meta['provider_key'],
            array_values(array_filter($provides, 'is_string')),
            $priority
        );
    }

    public function addonSlug(): string
    {
        return $this->slug;
    }

    public function providerKey(): string
    {
        return $this->providerKey;
    }

    public function title(): string
    {
        return $this->name;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function alwaysVisible(): bool
    {
        return $this->providerKey === '' || $this->providerKey === '*';
    }

    public function normalizeBindingFields(array $fields): array
    {
        return $fields;
    }

    public function renderHtml(array $ctx): string
    {
        $binding = is_array($ctx['binding'] ?? null) ? $ctx['binding'] : null;
        $postBlock = is_array($ctx['post'] ?? null) ? $ctx['post'] : [];
        $preferExportOnCreate = !empty($ctx['prefer_export_on_create']);

        $bindingExtra = is_array($binding['extra'] ?? null) ? $binding['extra'] : [];
        $hasConversionExport = in_array('conversion_export', $this->provides, true);
        $hasCostSync = in_array('cost_sync', $this->provides, true);

        $exportOn = !empty($bindingExtra['conversion_export']);
        if ($preferExportOnCreate && $binding === null && !array_key_exists('conversion_export', $postBlock)) {
            $exportOn = true;
        }
        if (array_key_exists('conversion_export', $postBlock)) {
            $exportOn = !empty($postBlock['conversion_export']);
        }

        $eventName = (string) ($bindingExtra['event_name'] ?? 'lead');
        if (!empty($postBlock['event_name'])) {
            $eventName = (string) $postBlock['event_name'];
        }

        $remoteAccount = (string) ($postBlock['remote_account_id'] ?? $binding['remote_account_id'] ?? '');
        $remoteCampaign = (string) ($postBlock['remote_campaign_id'] ?? $binding['remote_campaign_id'] ?? '');

        $slugEsc = htmlspecialchars($this->slug, ENT_QUOTES, 'UTF-8');
        $nameEsc = htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8');
        $keyEsc = htmlspecialchars($this->providerKey, ENT_QUOTES, 'UTF-8');
        $eventEsc = htmlspecialchars($eventName, ENT_QUOTES, 'UTF-8');
        $accountEsc = htmlspecialchars($remoteAccount, ENT_QUOTES, 'UTF-8');
        $campaignEsc = htmlspecialchars($remoteCampaign, ENT_QUOTES, 'UTF-8');
        $alwaysAttr = $this->alwaysVisible() ? ' data-always-visible="1"' : '';

        $html = '<div class="honeycomb-binding-panel" data-provider-key="' . $keyEsc . '"' . $alwaysAttr
            . ' style="display:none;margin-bottom:16px;padding:14px;background:#f5f8f2;border:1px solid #c5d4b8;border-radius:6px;">'
            . '<strong style="color:#3d5a26;">' . $nameEsc . ' (Honeycomb)</strong>';

        if ($hasConversionExport) {
            $html .= '<p style="font-size:12px;color:#666;margin:8px 0 12px;line-height:1.45;">'
                . 'Send conversions to this network’s Events API when this campaign converts. '
                . 'Credentials are under <a href="?page=honeycomb" style="color:#3d5a26;">Honeycomb</a>.'
                . ($this->providerKey === 'whop'
                    ? ' Copy-ready Whop Pixel + Kuma CTA codes appear below when this traffic source is selected.'
                    : '')
                . '</p>'
                . '<label style="display:flex;align-items:center;gap:10px;margin-bottom:12px;cursor:pointer;">'
                . '<input type="hidden" name="honeycomb_binding[' . $slugEsc . '][conversion_export]" value="0">'
                . '<input type="checkbox" name="honeycomb_binding[' . $slugEsc . '][conversion_export]" value="1"'
                . ($exportOn ? ' checked' : '')
                . ' style="width:18px;height:18px;">'
                . '<span style="font-weight:600;color:#333;">Send conversions to ' . $nameEsc . '</span>'
                . '</label>'
                . '<label style="display:block;font-weight:600;margin-bottom:6px;">Event name</label>'
                . '<select name="honeycomb_binding[' . $slugEsc . '][event_name]"'
                . ' style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;margin-bottom:12px;">';
            foreach (['lead', 'schedule', 'contact', 'complete_registration', 'submit_application'] as $ev) {
                $html .= '<option value="' . $ev . '"' . ($eventName === $ev ? ' selected' : '') . '>' . $ev . '</option>';
            }
            $html .= '</select>';
        }

        if ($hasCostSync) {
            $margin = $hasConversionExport ? '12px' : '8px';
            $html .= '<p style="font-size:12px;color:#666;margin:' . $margin . ' 0 12px;line-height:1.45;">'
                . 'Link this Kuma campaign to the remote account + campaign IDs so Honeycomb can sync ad spend hourly. '
                . 'Credentials are managed under <a href="?page=honeycomb" style="color:#3d5a26;">Honeycomb</a>.'
                . '</p>'
                . '<label style="display:block;font-weight:600;margin-bottom:6px;">Remote account ID</label>'
                . '<input type="text" name="honeycomb_binding[' . $slugEsc . '][remote_account_id]"'
                . ' value="' . $accountEsc . '"'
                . ' placeholder="e.g. biz_… or advertiser id"'
                . ' style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;margin-bottom:12px;">'
                . '<label style="display:block;font-weight:600;margin-bottom:6px;">Remote campaign ID</label>'
                . '<input type="text" name="honeycomb_binding[' . $slugEsc . '][remote_campaign_id]"'
                . ' value="' . $campaignEsc . '"'
                . ' placeholder="Ad campaign id from the network"'
                . ' style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;">';
        } elseif ($hasConversionExport) {
            $html .= '<input type="hidden" name="honeycomb_binding[' . $slugEsc . '][remote_account_id]" value="">'
                . '<input type="hidden" name="honeycomb_binding[' . $slugEsc . '][remote_campaign_id]" value="">';
        }

        $html .= '</div>';

        return $html;
    }
}
