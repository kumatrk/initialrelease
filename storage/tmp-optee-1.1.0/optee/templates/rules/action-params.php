<?php
/**
 * Per-action parameter controls, shared by the rule create + edit forms.
 * Hidden by default; optee-rules.js shows the relevant block based on the
 * selected action. Values are collected into the `action_params` JSON field.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<!-- Adjust Bid parameters -->
<div id="rule-bid-params" class="optee-form-row" style="display:none;">
    <label class="optee-label" style="width:180px;flex-shrink:0;">Bid Change</label>
    <div class="flex-1 space-y-2">
        <div class="flex items-center gap-2 flex-wrap">
            <select id="rule-bid-direction" class="optee-select" style="width:140px;">
                <option value="increase">Increase by</option>
                <option value="decrease">Decrease by</option>
            </select>
            <input type="text" inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*" id="rule-bid-percent"
                   class="optee-input" style="width:90px;" placeholder="20">
            <span class="text-sm font-medium text-gray-500">%</span>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm text-gray-500" style="width:190px;">Never bid below ($)</span>
            <input type="text" inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*" id="rule-bid-min"
                   class="optee-input" style="width:110px;" placeholder="no limit">
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm text-gray-500" style="width:190px;">Never bid above ($)</span>
            <input type="text" inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*" id="rule-bid-max"
                   class="optee-input" style="width:110px;" placeholder="no limit">
        </div>
        <p class="text-xs text-gray-400">The new bid is clamped into this range (and the traffic source's own minimum) before it's applied.</p>
    </div>
</div>

<!-- Remove Targeting: which dimension to trim -->
<div id="rule-targeting-params" class="optee-form-row" style="display:none;">
    <label class="optee-label" style="width:180px;flex-shrink:0;" for="rule-targeting-dim">Targeting to Remove</label>
    <div class="flex-1">
        <select id="rule-targeting-dim" class="optee-select w-full">
            <option value="category">Category</option>
            <option value="os">OS</option>
            <option value="browser">Browser</option>
            <option value="form_factor">Form Factor</option>
            <option value="isp">ISP</option>
            <option value="resolution">Resolution</option>
            <option value="connection_type">Connection Type</option>
            <option value="connection_speed">Connection Speed</option>
        </select>
        <div class="rounded-lg border px-3 py-2 text-xs mt-2" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;">
            Conditions are evaluated <strong>per value using tracker data</strong> (use Tracker metrics like Cost / ROI), and each matching value is removed from the traffic source's targeting for this dimension. Requires a traffic source that supports it (e.g. PopAds) and, for some dimensions, the traffic source forwarding the value to the tracker. If the campaign targets "all", it's converted to an explicit list minus the removed values.
        </div>
    </div>
</div>

<!-- Remove Countries note -->
<div id="rule-country-note" class="optee-form-row" style="display:none;">
    <label class="optee-label" style="width:180px;flex-shrink:0;">Per-Country</label>
    <div class="flex-1">
        <div class="rounded-lg border px-3 py-2 text-xs" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;">
            Conditions are evaluated <strong>per country</strong> using the traffic source's own stats, and every country that matches is removed from the campaign's targeting. Requires a traffic source that supports per-country editing (e.g. PopAds). The last remaining country is never removed.
        </div>
    </div>
</div>
