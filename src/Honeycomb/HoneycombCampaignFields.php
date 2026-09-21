<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use mysqli;

/**
 * Helpers for campaign edit UI: which Honeycomb addons apply to a traffic source.
 */
final class HoneycombCampaignFields
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * Enabled traffic_source addons keyed by provider_key.
     *
     * @return array<string, array{slug: string, name: string, provider_key: string, provides: list<string>}>
     */
    public function enabledByProviderKey(): array
    {
        $loader = new AddonLoader($this->db);
        $out = [];
        foreach ($loader->describeInstalled() as $row) {
            if (($row['status'] ?? '') !== 'enabled' || empty($row['valid'])) {
                continue;
            }
            $key = trim((string) ($row['provider_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $provides = $row['provides'] ?? [];
            if (!is_array($provides)) {
                $provides = [];
            }
            $out[$key] = [
                'slug' => (string) $row['slug'],
                'name' => (string) ($row['name'] ?? $row['slug']),
                'provider_key' => $key,
                'provides' => array_values(array_filter($provides, 'is_string')),
            ];
        }
        return $out;
    }

    /**
     * Enabled conversion exporters from Honeycomb kernel (for campaign UI).
     *
     * @return list<array{slug: string, label: string}>
     */
    public function conversionExportOptions(): array
    {
        try {
            $kernel = (new AddonLoader($this->db))->kernel();
            $out = [];
            foreach ($kernel->conversionExporters() as $exporter) {
                $out[] = [
                    'slug' => $exporter->addonSlug(),
                    'label' => $exporter->label(),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve Honeycomb addon linkage for a traffic source.
     * Matches enabled addons by provider_key, credential traffic_source_id, or name.
     *
     * @param array<string, mixed> $trafficSource
     * @return array{
     *   linked: bool,
     *   configured: bool,
     *   addon_slug: string,
     *   addon_name: string,
     *   provider_key: string,
     *   via_provider_key: bool,
     *   via_credential: bool,
     *   via_name: bool,
     *   options_url: string
     * }|null
     */
    public function linkForTrafficSource(array $trafficSource): ?array
    {
        $tsId = (int) ($trafficSource['id'] ?? 0);
        $providerKey = trim((string) ($trafficSource['provider_key'] ?? ''));
        $tsName = strtolower(trim((string) ($trafficSource['name'] ?? '')));
        $enabled = $this->enabledByProviderKey();

        $match = null;
        $viaProvider = false;
        $viaCredential = false;
        $viaName = false;

        if ($providerKey !== '' && isset($enabled[$providerKey])) {
            $match = $enabled[$providerKey];
            $viaProvider = true;
        }

        // Credentials may explicitly store traffic_source_id (Options page link).
        $creds = new CredentialStore($this->db);
        foreach ($enabled as $addon) {
            foreach ($creds->listByAddon($addon['slug']) as $meta) {
                if (($meta['status'] ?? '') !== 'active') {
                    continue;
                }
                $full = $creds->getById((int) ($meta['id'] ?? 0), true);
                $payload = is_array($full['payload'] ?? null) ? $full['payload'] : [];
                if ($tsId > 0 && (int) ($payload['traffic_source_id'] ?? 0) === $tsId) {
                    $match = $addon;
                    $viaCredential = true;
                    break 2;
                }
            }
        }

        // Soft match: source name contains the addon provider_key (e.g. "Taboola").
        if ($match === null && $tsName !== '') {
            foreach ($enabled as $key => $addon) {
                if ($key !== '' && str_contains($tsName, $key)) {
                    $match = $addon;
                    $viaName = true;
                    break;
                }
            }
        }

        if ($match === null) {
            return null;
        }

        $base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
        $configured = $viaProvider || $viaCredential;

        return [
            'linked' => true,
            'configured' => $configured,
            'addon_slug' => $match['slug'],
            'addon_name' => $match['name'],
            'provider_key' => $match['provider_key'],
            'via_provider_key' => $viaProvider,
            'via_credential' => $viaCredential,
            'via_name' => $viaName,
            'options_url' => $base . '/index.php?page=honeycomb-addon&slug=' . rawurlencode($match['slug']),
        ];
    }

    /**
     * HTML notice for traffic source edit (empty string when not linked).
     *
     * @param array<string, mixed> $trafficSource
     */
    public function renderTrafficSourceNotice(array $trafficSource): string
    {
        $link = $this->linkForTrafficSource($trafficSource);
        if ($link === null) {
            return '';
        }

        $name = htmlspecialchars($link['addon_name'], ENT_QUOTES, 'UTF-8');
        $slug = htmlspecialchars($link['addon_slug'], ENT_QUOTES, 'UTF-8');
        $key = htmlspecialchars($link['provider_key'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($link['options_url'], ENT_QUOTES, 'UTF-8');

        if ($link['configured']) {
            $detail = 'Matched via <code>provider_key=' . $key . '</code>';
            if ($link['via_credential']) {
                $detail .= ' and Honeycomb credential link';
            }
            $detail .= '.';
            $title = 'Honeycomb connected';
        } else {
            $detail = 'An enabled Honeycomb addon matches this source. Open Options to finish linking credentials and API cost.';
            $title = 'Honeycomb addon available';
        }

        return '<div style="margin:0 0 16px 0;padding:14px 16px;background:#fff8e1;border:1px solid #ffe082;'
            . 'border-left:4px solid #c9a227;border-radius:6px;font-size:14px;color:#5d4037;line-height:1.5;">'
            . '<strong style="color:#3d5a26;">' . $title . '</strong> — '
            . '<strong>' . $name . '</strong> (<code>' . $slug . '</code>). '
            . $detail . ' '
            . '<a href="' . $url . '" style="color:#3d5a26;font-weight:600;">Open addon Options</a>'
            . '</div>';
    }

    /**
     * Compact clickable badge for list rows (empty when not linked).
     *
     * @param array<string, mixed> $trafficSource
     */
    public function renderTrafficSourceBadge(array $trafficSource): string
    {
        $link = $this->linkForTrafficSource($trafficSource);
        if ($link === null) {
            return '';
        }
        $label = $link['configured'] ? 'Honeycomb' : 'Honeycomb';
        $title = htmlspecialchars(
            ($link['configured'] ? 'Connected to ' : 'Open Options for ')
            . $link['addon_name'] . ' (' . $link['addon_slug'] . ')',
            ENT_QUOTES,
            'UTF-8'
        );
        $url = htmlspecialchars($link['options_url'], ENT_QUOTES, 'UTF-8');
        $bg = $link['configured'] ? '#fff8e1' : '#f5f5f5';
        $fg = $link['configured'] ? '#f57f17' : '#666';
        $border = $link['configured'] ? '#ffe082' : '#ddd';

        return '<a href="' . $url . '" title="' . $title . '" style="display:inline-block;margin-left:6px;padding:2px 8px;'
            . 'border-radius:4px;font-size:11px;font-weight:600;background:' . $bg . ';color:' . $fg . ';'
            . 'border:1px solid ' . $border . ';text-decoration:none;vertical-align:middle;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</a>';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function bindingForCampaign(int $campaignId, string $addonSlug): ?array
    {
        return (new BindingStore($this->db))->getForCampaignAddon($campaignId, $addonSlug);
    }

    /**
     * Save binding from campaign POST fields honeycomb_binding[{slug}][remote_*].
     *
     * @param array<string, mixed> $post
     */
    public function saveFromPost(int $campaignId, array $post): void
    {
        if ($campaignId < 1) {
            return;
        }
        $block = $post['honeycomb_binding'] ?? null;
        if (!is_array($block)) {
            return;
        }
        $store = new BindingStore($this->db);
        $creds = new CredentialStore($this->db);
        foreach ($block as $slug => $fields) {
            if (!is_string($slug) || !is_array($fields)) {
                continue;
            }
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
                continue;
            }
            $account = trim((string) ($fields['remote_account_id'] ?? ''));
            $campaign = trim((string) ($fields['remote_campaign_id'] ?? ''));
            $credId = (int) ($fields['credential_id'] ?? 0);
            $exportOn = !empty($fields['conversion_export']);
            $eventName = trim((string) ($fields['event_name'] ?? ''));
            $extra = [];
            if ($credId > 0) {
                $extra['credential_id'] = $credId;
            } else {
                // Default to first active credential for this addon.
                $list = $creds->listByAddon($slug);
                foreach ($list as $c) {
                    if (($c['status'] ?? '') === 'active') {
                        $extra['credential_id'] = (int) $c['id'];
                        // Prefer credential's account_id when remote account blank.
                        $full = $creds->getById((int) $c['id'], true);
                        $payload = is_array($full['payload'] ?? null) ? $full['payload'] : [];
                        if ($account === '' && !empty($payload['account_id'])) {
                            $account = trim((string) $payload['account_id']);
                        }
                        break;
                    }
                }
            }
            if ($exportOn) {
                $extra['conversion_export'] = true;
                if ($eventName !== '') {
                    $extra['event_name'] = preg_replace('/[^a-z0-9_]/', '', strtolower($eventName)) ?: 'lead';
                } else {
                    $extra['event_name'] = 'lead';
                }
                // Ensure binding survives when only export is enabled (no remote campaign id).
                if ($account === '' && isset($extra['credential_id'])) {
                    $full = $creds->getById((int) $extra['credential_id'], true);
                    $payload = is_array($full['payload'] ?? null) ? $full['payload'] : [];
                    if (!empty($payload['account_id'])) {
                        $account = trim((string) $payload['account_id']);
                    }
                }
                if ($account === '') {
                    $account = 'export';
                }
            }
            $store->upsert(
                $campaignId,
                $slug,
                $account !== '' ? $account : null,
                $campaign !== '' ? $campaign : null,
                $extra !== [] ? $extra : null
            );
        }
    }
}
