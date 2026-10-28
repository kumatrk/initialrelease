<?php
use SimpleKuma\Utils\Formatter;

$whopBizForSnippet = ($whopBizAccountId ?? '') !== '' ? $whopBizAccountId : 'biz_xxxxxxxxxxxxx';
$whopPixelSnippet = '<script>
!function(w,d,s,u,n,a,b){if(w[n])return;a=w[n]={q:[],t:+new Date,s:[],o:u,track:function(){a.q.push([+new Date].concat([].slice.call(arguments)))},setScope:function(){a.s=[].slice.call(arguments).filter(function(x){return typeof x==="string"});a.q.push([+new Date,"setScope"].concat(a.s))},scope:function(){var c=[].slice.call(arguments);return{track:function(){a.q.push([+new Date].concat([].slice.call(arguments)).concat([{__scope:c}]))}}}};b=d.createElement(s);b.async=1;b.src=u+"/s.js";d.getElementsByTagName(s)[0].parentNode.insertBefore(b,d.getElementsByTagName(s)[0])}(window,document,"script","https://t.whop.tw","whop");
whop.setScope("' . $whopBizForSnippet . '");
whop.track("page");
</script>';

$deepFunnelsAddonEnabled = !empty($deepFunnelsAddonEnabled);
$isDeepFunnelFlow = ($flowType === 'DeepFunnel');
$deepFunnelJsonPost = '';
if (!empty($_POST['deep_funnel_json']) && is_string($_POST['deep_funnel_json'])) {
    $deepFunnelJsonPost = $_POST['deep_funnel_json'];
}
?>
<h3 class="wizard-step-title">Flow &amp; rotation</h3>
<p class="wizard-step-desc">Configure how traffic flows to offers and landing pages.</p>

<div class="campaign-form-field" style="margin-bottom: 24px;">
    <label class="campaign-form-label" for="flow_type">Flow Type <span class="campaign-form-required">*</span></label>
    <select name="flow_type" id="flow_type" required class="campaign-form-input"
            onchange="updateFlowFields(); syncWhopWizardUi();">
        <option value="DTO" <?= cc_selected($flowType, 'DTO') ?>>Direct to Offer (DTO)</option>
        <option value="LP" <?= cc_selected($flowType, 'LP') ?>>Landing Page → Offer</option>
        <option value="Split" <?= cc_selected($flowType, 'Split') ?>>Split Test</option>
        <?php if ($deepFunnelsAddonEnabled || $isDeepFunnelFlow): ?>
        <option value="DeepFunnel" <?= cc_selected($flowType, 'DeepFunnel') ?>>Deep Funnel (Honeycomb)</option>
        <?php endif; ?>
    </select>
    <?php if ($deepFunnelsAddonEnabled): ?>
    <p class="campaign-form-hint">
        Deep Funnel replaces the landing-page list below the offers. After the campaign is created, open it in the campaign editor
        to copy button link codes.
    </p>
    <?php endif; ?>
    <p id="wizard-whop-flow-hint" class="campaign-form-hint" style="display:none;color:#4a3563;">
        Whop needs an owned landing page that runs the Whop Pixel. DTO is not recommended — use <strong>Landing Page → Offer</strong>.
    </p>
    <input type="hidden" name="deep_funnel_json" id="deep_funnel_json"
           value="<?= htmlspecialchars($deepFunnelJsonPost, ENT_QUOTES, 'UTF-8') ?>">

    <div id="split_fields" class="flow-split-panel" style="display: <?= $flowType === 'Split' ? 'block' : 'none' ?>;">
        <div class="flow-split-panel__title">Split traffic</div>
        <div class="flow-split-panel__row">
            <label class="flow-split-panel__label" for="split_traffic_to_lp_input">To landing-page path</label>
            <span class="flow-split-panel__control">
                <input type="number" name="split_traffic_to_lp" id="split_traffic_to_lp_input"
                       class="campaign-form-input flow-split-panel__input"
                       min="0" max="100" value="<?= (int) $splitLPPercent ?>"
                       oninput="updateSplitPercentage()" aria-describedby="split_remaining_percent">
                <span class="flow-split-panel__pct" aria-hidden="true">%</span>
            </span>
            <span id="split_remaining_percent" class="flow-split-panel__rest">
                <?= 100 - (int) $splitLPPercent ?>% goes direct to offer
            </span>
        </div>
        <p class="flow-split-panel__hint">
            LP path uses the landing pages + offers below. Direct path skips the landing page and uses offers only.
        </p>
    </div>
</div>

<fieldset class="wizard-rotation-fieldset wizard-rotation-fieldset--offers">
    <legend>
        <img src="<?= ASSETS_BASE_URL ?>/assets/images/offers.png" alt="" width="20" height="20">
        <span>Offer Rotation (Weights must sum to 100%)</span>
        <button type="button" onclick="equalizeOfferWeights()" class="btn btn-outline wizard-equalize-btn"
                title="Distribute weights evenly">= Equal Weights</button>
    </legend>
    <p class="campaign-form-hint" style="margin-top:0;">
        Plain <code>kTrack()</code> rotates; <code>kTrack(1)</code>, <code>kTrack(2)</code>, … force that offer slot.
    </p>
    <div id="offer_rotation_items">
        <?php foreach ($offerRows as $idx => $offer): ?>
        <div class="offer-rotation-row wizard-rotation-row">
            <input type="hidden" name="offer_enabled[<?= $idx ?>]" id="offer_enabled_hidden_<?= $idx ?>" value="<?= $offer['enabled'] ? '1' : '0' ?>">
            <label class="wizard-enable-label">
                <input type="checkbox" id="offer_checkbox_<?= $idx ?>" <?= $offer['enabled'] ? 'checked' : '' ?>>
                <span>Enable</span>
            </label>
            <select name="offer_id[]" id="offer_select_<?= $idx ?>" class="campaign-form-input"
                    <?= !$offer['enabled'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>
                    style="<?= !$offer['enabled'] ? 'background:#f5f5f5;color:#999;cursor:not-allowed;pointer-events:none;' : '' ?>">
                <option value="">Select offer...</option>
                <?php foreach ($offers as $off): ?>
                    <option value="<?= $off['id'] ?>" <?= (string)$off['id'] === (string)$offer['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($off['name']) ?> (<?= Formatter::formatCurrency($off['payout_value'], $userCurrency) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="offer_weight[]" id="offer_weight_<?= $idx ?>" min="0" max="100"
                   class="campaign-form-input"
                   value="<?= (int)$offer['weight'] ?>" <?= !$offer['enabled'] ? 'readonly' : '' ?>
                   style="<?= !$offer['enabled'] ? 'background:#f5f5f5;color:#999;cursor:not-allowed;' : '' ?>">
            <button type="button" class="btn btn-outline" onclick="addOfferRotationItem()">+</button>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="campaign-form-field" style="margin-top: 16px; margin-bottom: 0;">
        <label class="campaign-form-label" for="fallback_offer_id">Fallback Offer (optional)</label>
        <select name="fallback_offer_id" id="fallback_offer_id" class="campaign-form-input campaign-form-input--narrow">
            <option value="">None</option>
            <?php foreach ($offers as $off): ?>
                <option value="<?= $off['id'] ?>" <?= cc_selected(cc_input('fallback_offer_id'), $off['id']) ?>>
                    <?= htmlspecialchars($off['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="campaign-form-hint">Used when no enabled offer is available. Grizzly Guard uses this as the backup offer.</p>
    </div>
    <?php
    $botRouteMode = function_exists('cc_input') ? cc_input('bot_route_mode', 'allow') : 'allow';
    $botRouteUrl = function_exists('cc_input') ? cc_input('bot_route_url') : '';
    include __DIR__ . '/../partials/campaign-bot-route-fields.php';
    ?>
</fieldset>

<?php if ($deepFunnelsAddonEnabled && $deepFunnelsProvider instanceof \SimpleKuma\Honeycomb\CampaignFieldsProvider): ?>
<div id="wizard-deep-funnels-wrap" style="margin-bottom: 24px;<?= $isDeepFunnelFlow ? '' : 'display:none;' ?>">
    <?= $deepFunnelsProvider->renderHtml([
        'binding' => null,
        'post' => [],
        'prefer_export_on_create' => false,
        'embedded' => true,
    ]) ?>
</div>
<?php endif; ?>

<div id="lp_fields" style="margin-bottom: 24px; display: <?= in_array($flowType, ['LP', 'Split'], true) ? 'block' : 'none' ?>;">
    <fieldset class="wizard-rotation-fieldset wizard-rotation-fieldset--lp">
        <legend id="lp_rotation_legend">
            <img src="<?= ASSETS_BASE_URL ?>/assets/images/landingpages.png" alt="" width="20" height="20">
            <span id="lp_rotation_legend_text">Landing Page Rotation (Weights must sum to 100%)</span>
            <button type="button" id="lp_equalize_weights_btn" onclick="equalizeLPWeights()" class="btn btn-outline wizard-equalize-btn">= Equal Weights</button>
        </legend>
        <p id="lp_rotation_help" class="campaign-form-hint" style="margin-top:0;">
            Weights must sum to 100% across enabled landing pages.
        </p>
        <p id="lp_rotation_whop_help" class="campaign-form-hint" style="display:none;color:#4a3563;padding:10px 12px;background:#f3eef8;border:1px solid #c5b3d6;border-radius:6px;">
            Whop Ads allows <strong>one landing page</strong> — that LP URL is what you paste into Whop as the ad destination (Pixel must run there).
        </p>
        <div id="lp_items">
            <?php foreach ($lpRows as $idx => $lp): ?>
            <div class="wizard-rotation-row">
                <input type="hidden" name="lp_enabled[<?= $idx ?>]" id="lp_enabled_hidden_<?= $idx ?>" value="<?= $lp['enabled'] ? '1' : '0' ?>">
                <label class="wizard-enable-label">
                    <input type="checkbox" id="lp_checkbox_<?= $idx ?>" <?= $lp['enabled'] ? 'checked' : '' ?>>
                    <span>Enable</span>
                </label>
                <select name="lp_id[]" id="lp_select_<?= $idx ?>" class="campaign-form-input"
                        <?= !$lp['enabled'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>
                        style="<?= !$lp['enabled'] ? 'background:#f5f5f5;color:#999;cursor:not-allowed;pointer-events:none;' : '' ?>"
                        onchange="if (typeof updateWhopAdDestination === 'function') updateWhopAdDestination();">
                    <option value="">Select landing page...</option>
                    <?php foreach ($landingPages as $lpItem): ?>
                        <option value="<?= $lpItem['id'] ?>"
                                data-lp-url="<?= htmlspecialchars((string) ($lpItem['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                <?= (string)$lpItem['id'] === (string)$lp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($lpItem['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="lp_weight[]" id="lp_weight_<?= $idx ?>" min="0" max="100"
                       class="campaign-form-input"
                       value="<?= (int)$lp['weight'] ?>" <?= !$lp['enabled'] ? 'readonly' : '' ?>
                       style="<?= !$lp['enabled'] ? 'background:#f5f5f5;color:#999;cursor:not-allowed;' : '' ?>"
                       onchange="if (typeof updateWhopAdDestination === 'function') updateWhopAdDestination();">
                <button type="button" class="btn btn-outline lp-add-btn" onclick="addLPItem()">+</button>
            </div>
            <?php endforeach; ?>
        </div>
        <div id="lp_rotation_tip" class="campaign-form-hint">
            Make sure your LPs include the click tracker script (<code>chomp.js</code> / <code>kTrack()</code>).
        </div>
    </fieldset>
</div>

<div id="whop-ad-destination-box" style="display:none;background:linear-gradient(135deg,#f3eef8 0%,#e8dff0 100%);border:2px solid #6b4f8a;border-radius:8px;padding:20px;margin-bottom:24px;">
    <p style="margin:0 0 8px 0;font-weight:700;color:#4a3563;font-size:15px;">Whop ad destination (paste this into Whop Ads)</p>
    <p style="margin:0 0 12px 0;font-size:13px;color:#5d4037;line-height:1.45;">
        This is your campaign’s single landing page URL — not a <code>/km/</code> link. Whop appends tracking params onto this URL.
    </p>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
        <code id="whop-ad-destination-url" style="font-size:14px;color:#333;word-break:break-all;flex:1;white-space:pre-wrap;font-family:'Courier New',monospace;background:#fff;padding:12px;border-radius:4px;border:1px solid #d4c4e4;min-width:200px;">Select a landing page above</code>
        <button type="button" id="copy-whop-ad-destination-btn" onclick="copyWhopAdDestination()" style="white-space:nowrap;padding:12px 24px;background:#6b4f8a;border:none;border-radius:6px;color:#fff;font-weight:600;cursor:pointer;">Copy</button>
    </div>
</div>

<div id="whop-lp-codes-panel" style="display:none;background:linear-gradient(135deg,#f3eef8 0%,#e8dff0 100%);border:2px solid #6b4f8a;border-radius:8px;padding:20px;margin-bottom:24px;">
    <h3 style="margin:0 0 8px 0;color:#4a3563;font-size:16px;font-weight:600;">Whop Ads — Pixel for your landing page</h3>
    <p style="margin:0 0 16px 0;font-size:13px;color:#5d4037;line-height:1.5;">
        Paste the Pixel into your LP <code>&lt;head&gt;</code> now. After you create the campaign, the edit screen shows the Kuma CTA / handoff codes (needs your tracking link).
        <?php if (($whopBizAccountId ?? '') === ''): ?>
        <br><span style="color:#b71c1c;">Connect your <code>biz_…</code> account under <a href="?page=honeycomb" style="color:#4a3563;">Honeycomb → Whop Ads</a> so this snippet is prefilled.</span>
        <?php endif; ?>
    </p>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:8px;flex-wrap:wrap;">
        <p style="margin:0;font-weight:600;color:#333;font-size:14px;">Whop Pixel <span style="font-weight:400;color:#666;">(paste in <code>&lt;head&gt;</code>)</span></p>
        <button type="button" onclick="copyWhopSnippet('whop-pixel-code', this)" style="padding:6px 14px;font-size:12px;background:#6b4f8a;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;">Copy Pixel</button>
    </div>
    <pre id="whop-pixel-code" style="margin:0;padding:12px;background:#1e1e1e;color:#d4d4d4;border-radius:6px;overflow:auto;font-size:12px;line-height:1.45;white-space:pre-wrap;"><?= htmlspecialchars($whopPixelSnippet) ?></pre>
</div>

<?php
$guardMode = function_exists('cc_input') ? cc_input('guard_mode', 'off') : 'off';
$guardBackupDomainId = function_exists('cc_input') ? cc_input('guard_backup_domain_id') : '';
$guardBackupLpId = function_exists('cc_input') ? cc_input('guard_backup_lp_id') : '';
$guardLastResortUrl = function_exists('cc_input') ? cc_input('guard_last_resort_url') : '';
$guardIconSrc = (defined('ASSETS_BASE_URL') ? ASSETS_BASE_URL : '') . '/assets/images/grizzly-guard.png';
?>
<details class="campaign-optional-details"<?= ($guardMode !== 'off') ? ' open' : '' ?>>
    <summary>
        <img src="<?= htmlspecialchars($guardIconSrc, ENT_QUOTES, 'UTF-8') ?>" alt="">
        Grizzly Guard (Optional)
    </summary>
    <div class="campaign-optional-details__body">
        <p class="campaign-optional-details__hint">
            Pick how this campaign should react when a link or tracking domain goes down. The dropdown explains each option. Account backup domains are under Settings → Grizzly Guard.
        </p>
        <?php include __DIR__ . '/../partials/campaign-grizzly-guard-fields.php'; ?>
    </div>
</details>
