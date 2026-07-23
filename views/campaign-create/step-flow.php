<?php
use SimpleKuma\Utils\Formatter;
?>
<h3 class="wizard-step-title">Flow &amp; rotation</h3>
<p class="wizard-step-desc">Configure how traffic flows to offers and landing pages.</p>

<div style="margin-bottom: 24px;">
    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Flow Type <span style="color:#d32f2f;">*</span></label>
    <select name="flow_type" id="flow_type" required onchange="updateFlowFields()"
            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;">
        <option value="DTO" <?= cc_selected($flowType, 'DTO') ?>>Direct to Offer (DTO)</option>
        <option value="LP" <?= cc_selected($flowType, 'LP') ?>>Landing Page → Offer</option>
        <option value="Split" <?= cc_selected($flowType, 'Split') ?>>Split Test</option>
    </select>
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
        <legend style="font-weight: 600; color: #0d47a1;">
            Landing Page Rotation
            <button type="button" onclick="equalizeLPWeights()" class="btn btn-outline" style="margin-left: 12px; font-size: 13px;">= Equal Weights</button>
        </legend>
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
                        <?= !$lp['enabled'] ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    <option value="">Select landing page...</option>
                    <?php foreach ($landingPages as $lpItem): ?>
                        <option value="<?= $lpItem['id'] ?>" <?= (string)$lpItem['id'] === (string)$lp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($lpItem['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="lp_weight[]" id="lp_weight_<?= $idx ?>" min="0" max="100"
                       value="<?= (int)$lp['weight'] ?>" <?= !$lp['enabled'] ? 'readonly' : '' ?>
                       style="padding: 8px; border: 2px solid #ddd; border-radius: 4px;<?= !$lp['enabled'] ? ' background:#f5f5f5;color:#999;cursor:not-allowed;' : '' ?>">
                <button type="button" class="btn btn-outline" onclick="addLPItem()">+</button>
            </div>
            <?php endforeach; ?>
        </div>
    </fieldset>
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
