<?php
use SimpleKuma\Release\TrafficSourceReleaseHelper;
?>
<h3 class="wizard-step-title">Traffic &amp; postbacks</h3>
<p class="wizard-step-desc">Choose your traffic source and optional integrations.</p>

<div style="margin-bottom: 20px;">
    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Traffic Source <span style="color:#d32f2f;">*</span></label>
    <select name="traffic_source_id" id="traffic_source_id"
            onchange="toggleTrafficSourceSelector(); toggleFacebookIntegration(); toggleGoogleAdsIntegration(); toggleHoneycombBindings(); syncWhopWizardUi();"
            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;">
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
    <p style="font-size: 12px; color: #666; margin-top: 6px; line-height: 1.45;">
        Facebook, Google Ads, YouTube, Whop Ads, or any custom source (including ones you add yourself).
        Cost can stay manual/URL until a live cost API exists for that network.
        Google/YouTube conversions export via scheduled CSV import (Settings → Integrations).
    </p>
    <div id="wizard-whop-traffic-note" style="display:none;margin-top:12px;padding:12px 14px;background:#f3eef8;border:1px solid #c5b3d6;border-radius:6px;font-size:13px;color:#4a3563;line-height:1.45;">
        <strong>Whop Ads:</strong> Use <strong>Landing Page → Offer</strong> flow with <strong>one</strong> owned LP.
        Paste that LP URL into Whop (not a <code>/km/</code> link). Pixel + CTA codes are on the Flow step and again after create.
    </div>
</div>

<div style="margin-bottom: 20px; padding: 14px; background: #f9faf7; border: 1px solid #e0e6d8; border-radius: 6px;">
    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Minimum payout to fire postbacks (optional)</label>
    <input type="number" name="min_postback_payout" step="any" min="0"
           value="<?= htmlspecialchars(cc_input('min_postback_payout')) ?>"
           placeholder="No minimum — fire all postbacks"
           style="width:100%;max-width:280px;padding:10px;border:2px solid #ddd;border-radius:4px;">
    <p style="font-size: 12px; color: #666; margin-top: 6px; line-height: 1.45;">
        Optional. All conversions always appear in Kuma. When set, outbound postbacks only fire when value or payout meets this minimum.
    </p>
</div>

<div style="margin-bottom: 20px; padding: 14px; background: #f9faf7; border: 1px solid #e0e6d8; border-radius: 6px;">
    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
        <input type="checkbox" name="allow_multiple_conversions" value="1"
               <?= cc_checked(!empty(cc_input('allow_multiple_conversions'))) ?>
               style="margin-top: 3px;">
        <span>
            <span style="display: block; font-weight: 600; margin-bottom: 4px;">Allow multiple conversions on the same click</span>
            <span style="display: block; font-size: 12px; color: #666; line-height: 1.45;">
                For networks like Propush that can send several payouts on one click ID.
                Prefer a unique <code>txid</code> when available. Same <code>txid</code>/<code>event_id</code> remains a duplicate.
            </span>
        </span>
    </label>
</div>

<div id="facebook_integration_field" style="margin-bottom: 20px; display: none;">
    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Facebook CAPI Integration (Optional)</label>
    <select name="facebook_capi_integration_id" style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;">
        <option value="">No Facebook Integration</option>
        <?php foreach ($facebookIntegrations as $fbIntegration): ?>
            <option value="<?= $fbIntegration['id'] ?>" <?= cc_selected(cc_input('facebook_capi_integration_id'), $fbIntegration['id']) ?>>
                <?= htmlspecialchars($fbIntegration['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <div style="margin-top: 16px;">
        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Facebook Ad Account (Optional)</label>
        <select name="facebook_marketing_ad_account_id" id="facebook_marketing_ad_account_id" style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;">
            <option value="">No Facebook Ad Account</option>
            <?php foreach ($allFacebookAdAccounts as $adAccount): ?>
                <option value="<?= $adAccount['id'] ?>" <?= cc_selected(cc_input('facebook_marketing_ad_account_id'), $adAccount['id']) ?>>
                    <?= htmlspecialchars($adAccount['ad_account_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div id="facebook_meta_campaign_field" style="margin-top: 16px;">
            <div style="background: #f5f8f2; border: 1px solid #c5d4b8; border-radius: 6px; padding: 12px 14px; margin-bottom: 12px; font-size: 13px; color: #444; line-height: 1.45;">
                <strong style="color: #3d5a26;">Meta campaign for cost tracking</strong><br>
                Choose the Facebook/Meta campaign whose ad spend you want Kuma to pull into reports.
                Pick your ad account above first, click <strong>Refresh Meta campaigns</strong>, then select the matching campaign.
                Optional — leave blank to infer costs from clicks only (slower, less accurate on large ad accounts).
            </div>
            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Meta Campaign (optional)</label>
            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 8px;">
                <button type="button" id="fb_refresh_meta_campaigns_btn" class="btn btn-secondary" style="padding: 8px 14px;">
                    Refresh Meta campaigns
                </button>
                <span id="fb_meta_campaign_status" style="font-size: 12px; color: #666;"></span>
            </div>
            <select name="facebook_marketing_campaign_id" id="facebook_marketing_campaign_id"
                    style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;" disabled>
                <option value="">Select ad account first</option>
            </select>
            <p style="font-size: 12px; color: #666; margin-top: 6px;">
                Only <strong>ACTIVE</strong> campaigns are listed. Sync pulls the latest from Meta for the selected ad account.
                <a href="?page=settings&tab=api-costs" target="_blank" style="color: #3d5a26;">Manage ad accounts</a>
            </p>
        </div>
    </div>
</div>

<div id="google_ads_integration_field" style="margin-bottom: 20px; display: none;">
    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Google Ads Integration (Optional)</label>
    <select name="google_ads_integration_id" id="google_ads_integration_id" style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;">
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
    <p style="font-size: 12px; color: #666; margin-top: 6px; line-height: 1.45;">
        Optional. Links this campaign to a Google integration for filtered CSV conversion export.
        <a href="?page=settings&tab=integrations" target="_blank" style="color: #3d5a26;">Manage conversion integrations</a>
    </p>
</div>

<?php if ($honeycombAddonsByProvider !== []): ?>
<div id="honeycomb_binding_fields" style="margin-bottom: 20px; display: none;">
    <?php foreach ($honeycombAddonsByProvider as $providerKey => $addonMeta):
        $slug = (string) $addonMeta['slug'];
        $binding = $honeycombBindingsBySlug[$slug] ?? null;
        $bindingExtra = is_array($binding['extra'] ?? null) ? $binding['extra'] : [];
        $provides = is_array($addonMeta['provides'] ?? null) ? $addonMeta['provides'] : [];
        $hasConversionExport = in_array('conversion_export', $provides, true);
        $hasCostSync = in_array('cost_sync', $provides, true);
        $exportOn = !empty($bindingExtra['conversion_export']);
        if ($hasConversionExport && !isset($_POST['honeycomb_binding'][$slug]['conversion_export']) && empty($binding)) {
            $exportOn = true;
        }
        $eventName = (string) ($bindingExtra['event_name'] ?? 'lead');
        if (!empty($_POST['honeycomb_binding'][$slug]['event_name'])) {
            $eventName = (string) $_POST['honeycomb_binding'][$slug]['event_name'];
        }
        if (isset($_POST['honeycomb_binding'][$slug]['conversion_export'])) {
            $exportOn = !empty($_POST['honeycomb_binding'][$slug]['conversion_export']);
        }
        $remoteAccount = (string) ($_POST['honeycomb_binding'][$slug]['remote_account_id'] ?? $binding['remote_account_id'] ?? '');
        $remoteCampaign = (string) ($_POST['honeycomb_binding'][$slug]['remote_campaign_id'] ?? $binding['remote_campaign_id'] ?? '');
    ?>
        <div class="honeycomb-binding-panel" data-provider-key="<?= htmlspecialchars($providerKey) ?>" style="display:none;margin-bottom:16px;padding:14px;background:#f5f8f2;border:1px solid #c5d4b8;border-radius:6px;">
            <strong style="color:#3d5a26;"><?= htmlspecialchars((string) $addonMeta['name']) ?> (Honeycomb)</strong>
            <?php if ($hasConversionExport): ?>
            <p style="font-size:12px;color:#666;margin:8px 0 12px;line-height:1.45;">
                Send conversions to this network’s Events API when this campaign converts.
                Credentials are under <a href="?page=honeycomb" style="color:#3d5a26;">Honeycomb</a>.
            </p>
            <label style="display:flex;align-items:center;gap:10px;margin-bottom:12px;cursor:pointer;">
                <input type="hidden" name="honeycomb_binding[<?= htmlspecialchars($slug) ?>][conversion_export]" value="0">
                <input type="checkbox"
                       name="honeycomb_binding[<?= htmlspecialchars($slug) ?>][conversion_export]"
                       value="1"
                       <?= $exportOn ? 'checked' : '' ?>
                       style="width:18px;height:18px;">
                <span style="font-weight:600;color:#333;">Send conversions to <?= htmlspecialchars((string) $addonMeta['name']) ?></span>
            </label>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Event name</label>
            <select name="honeycomb_binding[<?= htmlspecialchars($slug) ?>][event_name]"
                    style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;margin-bottom:12px;">
                <?php foreach (['lead', 'schedule', 'contact', 'complete_registration', 'submit_application'] as $ev): ?>
                    <option value="<?= $ev ?>" <?= $eventName === $ev ? 'selected' : '' ?>><?= $ev ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($hasCostSync): ?>
            <p style="font-size:12px;color:#666;margin:<?= $hasConversionExport ? '12px' : '8px' ?> 0 12px;line-height:1.45;">
                Link this Kuma campaign to the remote account + campaign IDs so Honeycomb can sync ad spend hourly.
                Credentials are managed under <a href="?page=honeycomb" style="color:#3d5a26;">Honeycomb</a>.
            </p>
            <label style="display:block;font-weight:600;margin-bottom:6px;">Remote account ID</label>
            <input type="text" name="honeycomb_binding[<?= htmlspecialchars($slug) ?>][remote_account_id]"
                   value="<?= htmlspecialchars($remoteAccount) ?>"
                   placeholder="e.g. biz_… or advertiser id"
                   style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;margin-bottom:12px;">
            <label style="display:block;font-weight:600;margin-bottom:6px;">Remote campaign ID</label>
            <input type="text" name="honeycomb_binding[<?= htmlspecialchars($slug) ?>][remote_campaign_id]"
                   value="<?= htmlspecialchars($remoteCampaign) ?>"
                   placeholder="Ad campaign id from the network"
                   style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;">
            <?php elseif ($hasConversionExport): ?>
            <input type="hidden" name="honeycomb_binding[<?= htmlspecialchars($slug) ?>][remote_account_id]" value="">
            <input type="hidden" name="honeycomb_binding[<?= htmlspecialchars($slug) ?>][remote_campaign_id]" value="">
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div id="traffic_source_postbacks_section" style="display: none;"></div>

<div id="custom_postbacks_section" style="margin-bottom: 20px;">
    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Custom Postbacks (Optional)</label>
    <?php if (!empty($allCustomPostbacks)): ?>
    <div style="border: 2px solid #ddd; border-radius: 4px; padding: 12px; max-height: 200px; overflow-y: auto;">
        <?php foreach ($allCustomPostbacks as $postback): ?>
            <label style="display: flex; gap: 10px; padding: 8px; cursor: pointer;">
                <input type="checkbox" name="custom_postback_ids[]" value="<?= $postback['id'] ?>"
                       <?= in_array($postback['id'], $selectedCustomPostbackIds, true) ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($postback['name']) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <p style="font-size: 12px; color: #666; margin-top: 6px;">
        Available for every traffic source. Manage under
        <a href="?page=settings&tab=integrations" target="_blank" style="color: #3d5a26;">Settings → Integrations</a>.
    </p>
    <?php else: ?>
    <div style="border: 2px dashed #ddd; border-radius: 4px; padding: 14px; background: #fafafa; color: #666; font-size: 13px; line-height: 1.45;">
        No custom postbacks yet. Create them under
        <a href="?page=settings&tab=integrations" target="_blank" style="color: #3d5a26;">Settings → Integrations</a>,
        then attach them here — they work for any traffic source.
    </div>
    <?php endif; ?>
</div>
