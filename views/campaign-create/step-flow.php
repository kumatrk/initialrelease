<?php
use SimpleKuma\Utils\Formatter;

$whopBizForSnippet = ($whopBizAccountId ?? '') !== '' ? $whopBizAccountId : 'biz_xxxxxxxxxxxxx';
$whopPixelSnippet = '<script>
!function(w,d,s,u,n,a,b){if(w[n])return;a=w[n]={q:[],t:+new Date,s:[],o:u,track:function(){a.q.push([+new Date].concat([].slice.call(arguments)))},setScope:function(){a.s=[].slice.call(arguments).filter(function(x){return typeof x==="string"});a.q.push([+new Date,"setScope"].concat(a.s))},scope:function(){var c=[].slice.call(arguments);return{track:function(){a.q.push([+new Date].concat([].slice.call(arguments)).concat([{__scope:c}]))}}}};b=d.createElement(s);b.async=1;b.src=u+"/s.js";d.getElementsByTagName(s)[0].parentNode.insertBefore(b,d.getElementsByTagName(s)[0])}(window,document,"script","https://t.whop.tw","whop");
whop.setScope("' . $whopBizForSnippet . '");
whop.track("page");
</script>';
?>
<h3 class="wizard-step-title">Flow &amp; rotation</h3>
<p class="wizard-step-desc">Configure how traffic flows to offers and landing pages.</p>

<div style="margin-bottom: 24px;">
    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Flow Type <span style="color:#d32f2f;">*</span></label>
    <select name="flow_type" id="flow_type" required onchange="updateFlowFields(); syncWhopWizardUi();"
            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;">
        <option value="DTO" <?= cc_selected($flowType, 'DTO') ?>>Direct to Offer (DTO)</option>
        <option value="LP" <?= cc_selected($flowType, 'LP') ?>>Landing Page → Offer</option>
        <option value="Split" <?= cc_selected($flowType, 'Split') ?>>Split Test</option>
    </select>
    <p id="wizard-whop-flow-hint" style="display:none;margin:8px 0 0;font-size:12px;color:#4a3563;line-height:1.45;">
        Whop needs an owned landing page that runs the Whop Pixel. DTO is not recommended — use <strong>Landing Page → Offer</strong>.
    </p>
</div>

<fieldset style="margin-bottom: 24px; background: #f1f8e9; padding: 20px; border-radius: 8px; border: 2px solid #8bc34a;">
    <legend style="font-weight: 600; color: #33691e;">
        Offer Rotation
        <button type="button" onclick="equalizeOfferWeights()" class="btn btn-outline" style="margin-left: 12px; font-size: 13px;">= Equal Weights</button>
    </legend>
    <div id="offer_rotation_items">
        <?php foreach ($offerRows as $idx => $offer): ?>
        <div class="offer-rotation-row" style="display: grid; grid-template-columns: auto 2fr 1fr auto; gap: 12px; margin-bottom: 8px; align-items: center;">
            <input type="hidden" name="offer_enabled[<?= $idx ?>]" id="offer_enabled_hidden_<?= $idx ?>" value="<?= $offer['enabled'] ? '1' : '0' ?>">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" id="offer_checkbox_<?= $idx ?>" <?= $offer['enabled'] ? 'checked' : '' ?> style="margin-right: 6px;">
                <span style="font-size: 12px;">Enable</span>
            </label>
            <select name="offer_id[]" id="offer_select_<?= $idx ?>"
                    style="padding: 8px; border: 2px solid #ddd; border-radius: 4px; width: 100%;<?= !$offer['enabled'] ? ' background:#f5f5f5;color:#999;cursor:not-allowed;pointer-events:none;' : '' ?>"
                    <?= !$offer['enabled'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                <option value="">Select offer...</option>
                <?php foreach ($offers as $off): ?>
                    <option value="<?= $off['id'] ?>" <?= (string)$off['id'] === (string)$offer['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($off['name']) ?> (<?= Formatter::formatCurrency($off['payout_value'], $userCurrency) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="offer_weight[]" id="offer_weight_<?= $idx ?>" min="0" max="100"
                   value="<?= (int)$offer['weight'] ?>" <?= !$offer['enabled'] ? 'readonly' : '' ?>
                   style="padding: 8px; border: 2px solid #ddd; border-radius: 4px;<?= !$offer['enabled'] ? ' background:#f5f5f5;color:#999;cursor:not-allowed;' : '' ?>">
            <button type="button" class="btn btn-outline" onclick="addOfferRotationItem()">+</button>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="margin-top: 16px;">
        <label for="fallback_offer_id" style="font-weight: 600;">Fallback Offer (optional)</label>
        <select name="fallback_offer_id" id="fallback_offer_id" style="padding: 8px; border: 2px solid #ddd; border-radius: 4px; min-width: 280px; margin-top: 8px;">
            <option value="">None</option>
            <?php foreach ($offers as $off): ?>
                <option value="<?= $off['id'] ?>" <?= cc_selected(cc_input('fallback_offer_id'), $off['id']) ?>>
                    <?= htmlspecialchars($off['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</fieldset>

<div id="lp_fields" style="margin-bottom: 24px; display: <?= in_array($flowType, ['LP', 'Split'], true) ? 'block' : 'none' ?>;">
    <fieldset style="background: #e3f2fd; padding: 20px; border-radius: 8px; border: 2px solid #2196F3;">
        <legend id="lp_rotation_legend" style="font-weight: 600; color: #0d47a1; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span id="lp_rotation_legend_text">Landing Page Rotation</span>
            <button type="button" id="lp_equalize_weights_btn" onclick="equalizeLPWeights()" class="btn btn-outline" style="margin-left: 12px; font-size: 13px;">= Equal Weights</button>
        </legend>
        <p id="lp_rotation_help" style="font-size: 12px; color: #1565c0; margin: 0 0 12px; line-height: 1.45;">
            Weights must sum to 100% across enabled landing pages.
        </p>
        <p id="lp_rotation_whop_help" style="display:none;font-size:12px;color:#4a3563;margin:0 0 12px;line-height:1.45;padding:10px 12px;background:#f3eef8;border:1px solid #c5b3d6;border-radius:6px;">
            Whop Ads allows <strong>one landing page</strong> — that LP URL is what you paste into Whop as the ad destination (Pixel must run there).
        </p>
        <div id="lp_items">
            <?php foreach ($lpRows as $idx => $lp): ?>
            <div style="display: grid; grid-template-columns: auto 2fr 1fr auto; gap: 12px; margin-bottom: 8px; align-items: center;">
                <input type="hidden" name="lp_enabled[<?= $idx ?>]" id="lp_enabled_hidden_<?= $idx ?>" value="<?= $lp['enabled'] ? '1' : '0' ?>">
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" id="lp_checkbox_<?= $idx ?>" <?= $lp['enabled'] ? 'checked' : '' ?> style="margin-right: 6px;">
                    <span style="font-size: 12px;">Enable</span>
                </label>
                <select name="lp_id[]" id="lp_select_<?= $idx ?>"
                        style="padding: 8px; border: 2px solid #ddd; border-radius: 4px; width: 100%;<?= !$lp['enabled'] ? ' background:#f5f5f5;color:#999;cursor:not-allowed;pointer-events:none;' : '' ?>"
                        <?= !$lp['enabled'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>
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
                       value="<?= (int)$lp['weight'] ?>" <?= !$lp['enabled'] ? 'readonly' : '' ?>
                       style="padding: 8px; border: 2px solid #ddd; border-radius: 4px;<?= !$lp['enabled'] ? ' background:#f5f5f5;color:#999;cursor:not-allowed;' : '' ?>"
                       onchange="if (typeof updateWhopAdDestination === 'function') updateWhopAdDestination();">
                <button type="button" class="btn btn-outline lp-add-btn" onclick="addLPItem()">+</button>
            </div>
            <?php endforeach; ?>
        </div>
        <div id="lp_rotation_tip" style="font-size: 12px; color: #666; margin-top: 8px;">
            💡 Make sure your LPs include the click tracker script
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
        <button type="button" id="copy-whop-ad-destination-btn" onclick="copyWhopAdDestination()" style="white-space:nowrap;padding:12px 24px;background:#6b4f8a;border:none;border-radius:6px;color:#fff;font-weight:600;cursor:pointer;">📋 Copy</button>
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
        <button type="button" onclick="copyWhopSnippet('whop-pixel-code', this)" style="padding:6px 14px;font-size:12px;background:#6b4f8a;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;">📋 Copy Pixel</button>
    </div>
    <pre id="whop-pixel-code" style="margin:0;padding:12px;background:#1e1e1e;color:#d4d4d4;border-radius:6px;overflow:auto;font-size:12px;line-height:1.45;white-space:pre-wrap;"><?= htmlspecialchars($whopPixelSnippet) ?></pre>
</div>

<div id="split_fields" style="margin-bottom: 24px; display: <?= $flowType === 'Split' ? 'block' : 'none' ?>;">
    <div style="background: #fff3e0; padding: 16px; border-radius: 8px; border: 2px solid #ff9800;">
        <label style="font-weight: 600; color: #e65100;">Split: Traffic to LP Path</label>
        <div style="display: flex; gap: 12px; align-items: center; margin-top: 12px;">
            <input type="number" name="split_traffic_to_lp" id="split_traffic_to_lp_input" min="0" max="100"
                   value="<?= $splitLPPercent ?>" oninput="updateSplitPercentage()"
                   style="width: 80px; padding: 8px; border: 2px solid #e65100; border-radius: 4px;">
            <span id="split_remaining_percent">(<?= 100 - $splitLPPercent ?>% Direct)</span>
        </div>
    </div>
</div>
