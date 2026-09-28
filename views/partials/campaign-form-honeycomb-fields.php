<?php
/**
 * Honeycomb campaign field panels (provider loop).
 *
 * Expected vars:
 * - list<\SimpleKuma\Honeycomb\CampaignFieldsProvider>|$honeycombCampaignFieldProviders
 * - array<string, array|null> $honeycombBindingsBySlug
 * - array $honeycombFieldPost (optional POST honeycomb_binding map)
 * - bool $honeycombPreferExportOnCreate (wizard create default)
 */
use SimpleKuma\Honeycomb\CampaignFieldsProvider;

$honeycombCampaignFieldProviders = $honeycombCampaignFieldProviders ?? [];
$honeycombBindingsBySlug = $honeycombBindingsBySlug ?? [];
$honeycombFieldPost = is_array($honeycombFieldPost ?? null) ? $honeycombFieldPost : [];
$honeycombPreferExportOnCreate = !empty($honeycombPreferExportOnCreate ?? false);

if ($honeycombCampaignFieldProviders === []) {
    return;
}
?>
<div id="honeycomb_binding_fields" class="campaign-form-honeycomb-fields" style="display: none;">
    <?php foreach ($honeycombCampaignFieldProviders as $provider):
        if (!$provider instanceof CampaignFieldsProvider) {
            continue;
        }
        $slug = $provider->addonSlug();
        $postForSlug = is_array($honeycombFieldPost[$slug] ?? null) ? $honeycombFieldPost[$slug] : [];
        echo $provider->renderHtml([
            'binding' => $honeycombBindingsBySlug[$slug] ?? null,
            'post' => $postForSlug,
            'prefer_export_on_create' => $honeycombPreferExportOnCreate,
        ]);
    endforeach; ?>
</div>
