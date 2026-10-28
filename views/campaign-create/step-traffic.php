<?php
use SimpleKuma\Release\TrafficSourceReleaseHelper;
?>
<h3 class="wizard-step-title">Traffic &amp; postbacks</h3>
<p class="wizard-step-desc">Choose your traffic source and optional integrations.</p>

<div class="campaign-form-field">
    <label class="campaign-form-label" for="traffic_source_id">Traffic Source <span class="campaign-form-required">*</span></label>
    <select name="traffic_source_id" id="traffic_source_id" class="campaign-form-input"
            onchange="toggleTrafficSourceSelector(); toggleFacebookIntegration(); toggleGoogleAdsIntegration(); toggleHoneycombBindings(); syncWhopWizardUi();">
        <?php foreach ($trafficSources as $ts):
            $isSelectable = TrafficSourceReleaseHelper::isSelectableForRelease($ts);
            $isFacebook = stripos($ts['name'], 'facebook') !== false;
            $isGoogle = TrafficSourceReleaseHelper::usesGoogleAdsIntegration($ts);
            $providerKey = trim((string) ($ts['provider_key'] ?? ''));
        ?>
            <option value="<?= $ts['id'] ?>"
                    data-is-facebook="<?= $isFacebook ? '1' : '0' ?>"
                    data-is-google="<?= $isGoogle ? '1' : '0' ?>"
                    data-provider-key="<?= htmlspecialchars($providerKey, ENT_QUOTES, 'UTF-8') ?>"
                    <?= !$isSelectable ? ' disabled' : '' ?>
                    <?= cc_selected($defaultTrafficSourceId, $ts['id']) ?>>
                <?= htmlspecialchars($ts['name']) ?><?= !$isSelectable ? ' (Coming soon)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="campaign-form-hint">
        Facebook, Google Ads, YouTube, Whop Ads, or any custom source (including ones you add yourself).
        Cost can stay manual/URL until a live cost API exists for that network.
        Google/YouTube conversions export via scheduled CSV import (Settings → Integrations).
    </p>
    <div id="wizard-whop-traffic-note" class="wizard-callout wizard-callout--whop" style="display:none;">
        <strong>Whop Ads:</strong> Use <strong>Landing Page → Offer</strong> flow with <strong>one</strong> owned LP.
        Paste that LP URL into Whop (not a <code>/km/</code> link). Pixel + CTA codes are on the Flow step and again after create.
    </div>
</div>

<div class="campaign-form-panel" style="margin-bottom: 20px;">
    <label class="campaign-form-label" for="wizard_min_postback_payout">Minimum payout to fire postbacks (optional)</label>
    <input type="number" name="min_postback_payout" id="wizard_min_postback_payout" step="any" min="0"
           value="<?= htmlspecialchars(cc_input('min_postback_payout')) ?>"
           placeholder="No minimum — fire all postbacks"
           class="campaign-form-input campaign-form-input--narrow">
    <p class="campaign-form-hint">
        Optional. All conversions always appear in Kuma. When set, outbound postbacks only fire when value or payout meets this minimum.
    </p>
</div>

<div class="campaign-form-panel" style="margin-bottom: 20px;">
    <label class="campaign-form-check">
        <input type="checkbox" name="allow_multiple_conversions" value="1"
               <?= cc_checked(!empty(cc_input('allow_multiple_conversions'))) ?>>
        <span>
            <span class="campaign-form-check-title">Allow multiple conversions on the same click</span>
            <span class="campaign-form-hint" style="display:block;margin-top:4px;">
                For networks like Propush that can send several payouts on one click ID.
                Prefer a unique <code>txid</code> when available. Same <code>txid</code>/<code>event_id</code> remains a duplicate.
            </span>
        </span>
    </label>
</div>

<div class="campaign-form-panel campaign-form-panel--edge" style="margin-bottom: 20px;">
    <label class="campaign-form-check">
        <input type="checkbox" name="edge_enabled" value="1" id="wizard_edge_enabled"
               <?= cc_checked(!empty(cc_input('edge_enabled'))) ?>>
        <span>
            <span class="campaign-form-check-title">Edge redirect (Cloudflare Worker)</span>
            <span class="campaign-form-hint" style="display:block;margin-top:4px;">
                Serve redirects from Cloudflare’s edge for lower latency. Requires Edge Redirect under
                <a href="?page=settings&amp;tab=edge-redirect" target="_blank" class="campaign-form-link">Settings</a>.
                Phase 1 supports standard 302 only (not referrer privacy modes). You can fine-tune sync status after create.
            </span>
        </span>
    </label>
</div>

<div id="facebook_integration_field" class="campaign-form-field" style="margin-bottom: 20px; display: none;">
    <div class="campaign-form-grid campaign-form-grid--2 campaign-form-meta-peers">
        <div class="campaign-form-meta-col">
            <div class="campaign-form-meta-heading">Conversion reporting</div>
            <label class="campaign-form-label" for="facebook_capi_integration_id">Meta Conversions API (CAPI)</label>
            <select name="facebook_capi_integration_id" id="facebook_capi_integration_id" class="campaign-form-input">
                <option value="">No CAPI integration</option>
                <?php foreach ($facebookIntegrations as $fbIntegration): ?>
                    <option value="<?= $fbIntegration['id'] ?>" <?= cc_selected(cc_input('facebook_capi_integration_id'), $fbIntegration['id']) ?>>
                        <?= htmlspecialchars($fbIntegration['name']) ?> (<?= htmlspecialchars((string) ($fbIntegration['pixel_id'] ?? '')) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="campaign-form-hint">
                Optional. Sends conversions to Meta.
                <a href="?page=settings&tab=integrations" target="_blank" class="campaign-form-link">Manage integrations</a>
            </div>
        </div>

        <div class="campaign-form-meta-col">
            <div class="campaign-form-meta-heading">Cost tracking</div>
            <label class="campaign-form-label" for="facebook_marketing_ad_account_id">Ad account</label>
            <select name="facebook_marketing_ad_account_id" id="facebook_marketing_ad_account_id" class="campaign-form-input">
                <option value="">No ad account</option>
                <?php foreach ($allFacebookAdAccounts as $adAccount): ?>
                    <option value="<?= $adAccount['id'] ?>" <?= cc_selected(cc_input('facebook_marketing_ad_account_id'), $adAccount['id']) ?>>
                        <?= htmlspecialchars($adAccount['ad_account_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="campaign-form-hint">
                Which Meta ad account to pull spend from.
                <a href="?page=settings&tab=api-costs" target="_blank" class="campaign-form-link">Manage integrations</a>
            </div>
        </div>
    </div>

    <div id="facebook_meta_campaign_field" class="campaign-form-meta-campaign">
        <div class="campaign-form-info">
            <strong>Meta campaign for cost tracking</strong><br>
            Pick the Meta campaign whose ad spend should land in reports.
            Choose an ad account above, click <strong>Refresh</strong>, then select the matching campaign.
            Optional — leave blank to infer costs from clicks only (slower, less accurate on large ad accounts).
        </div>
        <label class="campaign-form-label" for="facebook_marketing_campaign_id">Meta campaign (optional)</label>
        <div class="campaign-form-meta-campaign-row">
            <select name="facebook_marketing_campaign_id" id="facebook_marketing_campaign_id"
                    class="campaign-form-input" disabled>
                <option value="">Select ad account first</option>
            </select>
            <button type="button" id="fb_refresh_meta_campaigns_btn" class="btn btn-secondary campaign-form-meta-refresh">
                Refresh
            </button>
        </div>
        <span id="fb_meta_campaign_status" class="campaign-form-hint"></span>
        <p class="campaign-form-hint">
            Only <strong>ACTIVE</strong> campaigns are listed.
            <a href="?page=settings&tab=api-costs" target="_blank" class="campaign-form-link">Manage ad accounts</a>
        </p>
    </div>
</div>

<div id="google_ads_integration_field" class="campaign-form-field" style="margin-bottom: 20px; display: none;">
    <label class="campaign-form-label" for="google_ads_integration_id">Google Ads Integration (Optional)</label>
    <select name="google_ads_integration_id" id="google_ads_integration_id" class="campaign-form-input">
        <option value="">No Google Ads Integration</option>
        <?php foreach ($googleAdsIntegrations as $gaIntegration): ?>
            <option value="<?= (int)$gaIntegration['id'] ?>" <?= cc_selected(cc_input('google_ads_integration_id'), $gaIntegration['id']) ?>>
                <?= htmlspecialchars($gaIntegration['name']) ?>
                <?php if (!empty($gaIntegration['customer_id'])): ?>
                    (<?= htmlspecialchars($gaIntegration['customer_id']) ?>)
                <?php endif; ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="campaign-form-hint">
        Optional. Links this campaign to a Google integration for filtered CSV conversion export.
        <a href="?page=settings&tab=integrations" target="_blank" class="campaign-form-link">Manage conversion integrations</a>
    </p>
</div>

<?php
// Deep Funnels level builder lives on the Flow step (not Traffic) so users configure it next to flow type.
$honeycombCampaignFieldProvidersForTraffic = array_values(array_filter(
    $honeycombCampaignFieldProviders ?? [],
    static function ($p) {
        return is_object($p) && method_exists($p, 'addonSlug') && $p->addonSlug() !== 'deep-funnels';
    }
));
if ($honeycombCampaignFieldProvidersForTraffic !== []):
    $honeycombCampaignFieldProviders = $honeycombCampaignFieldProvidersForTraffic;
    $honeycombPreferExportOnCreate = true;
    $honeycombFieldPost = is_array($_POST['honeycomb_binding'] ?? null) ? $_POST['honeycomb_binding'] : [];
    include __DIR__ . '/../partials/campaign-form-honeycomb-fields.php';
endif;
?>

<?php
$ringbaLpCodesCompact = true;
include __DIR__ . '/../partials/campaign-ringba-lp-codes.php';
?>

<div id="traffic_source_postbacks_section" style="display: none;"></div>

<div id="custom_postbacks_section" style="margin-bottom: 20px;">
    <label class="campaign-form-label">Custom Postbacks (Optional)</label>
    <?php if (!empty($allCustomPostbacks)): ?>
    <div class="campaign-form-checklist custom-postbacks-container">
        <?php foreach ($allCustomPostbacks as $postback): ?>
            <label class="campaign-form-check-item custom-postback-item">
                <input type="checkbox" name="custom_postback_ids[]" value="<?= $postback['id'] ?>"
                       <?= in_array($postback['id'], $selectedCustomPostbackIds, true) ? 'checked' : '' ?>>
                <span class="campaign-form-check-title"><?= htmlspecialchars($postback['name']) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <p class="campaign-form-hint">
        Available for every traffic source. Manage under
        <a href="?page=settings&tab=integrations" target="_blank" class="campaign-form-link">Settings → Integrations</a>.
    </p>
    <?php else: ?>
    <div class="campaign-form-empty campaign-form-empty--dashed">
        No custom postbacks yet. Create them under
        <a href="?page=settings&tab=integrations" target="_blank" class="campaign-form-link">Settings → Integrations</a>,
        then attach them here — they work for any traffic source.
    </div>
    <?php endif; ?>
</div>
