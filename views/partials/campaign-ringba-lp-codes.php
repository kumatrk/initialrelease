<?php
/**
 * Ringba LP copy panel — campaign editor Tracking + create wizard Traffic.
 *
 * Expects (optional):
 *   $ringbaAddonEnabled (bool)
 *   $ringbaBindingExtra = ['click_param' => ..., 'js_tag_id' => ..., 'number_to_replace' => ...]
 *   $ringbaLpCodesCompact (bool) — slightly tighter copy for wizard
 */
if (empty($ringbaAddonEnabled)) {
    return;
}
$ringbaBindingExtra = is_array($ringbaBindingExtra ?? null) ? $ringbaBindingExtra : [];
$ringbaLpCodesCompact = !empty($ringbaLpCodesCompact);
$clickParam = (string) ($ringbaBindingExtra['click_param'] ?? 'click_id');
$jsTag = (string) ($ringbaBindingExtra['js_tag_id'] ?? '');
$numberReplace = (string) ($ringbaBindingExtra['number_to_replace'] ?? '');
$marginTop = $ringbaLpCodesCompact ? '16px' : '24px';
?>
<div id="ringba-lp-codes-panel" style="display:none;background:linear-gradient(135deg,#e3f2fd 0%,#e8eaf6 100%);border:2px solid #1565c0;border-radius:8px;padding:20px;margin-top:<?= htmlspecialchars($marginTop, ENT_QUOTES) ?>;"
     data-click-param="<?= htmlspecialchars($clickParam, ENT_QUOTES) ?>"
     data-js-tag="<?= htmlspecialchars($jsTag, ENT_QUOTES) ?>"
     data-number-replace="<?= htmlspecialchars($numberReplace, ENT_QUOTES) ?>">
    <h3 style="margin:0 0 8px 0;color:#0d47a1;font-size:16px;font-weight:600;">Ringba — copy codes for your landing page</h3>
    <p style="margin:0 0 16px 0;font-size:13px;color:#37474f;line-height:1.5;">
        Paste on your call LP after Kuma has created <strong>its own</strong> click id
        (from a <code>/km/</code> redirect or redirectless). That is separate from Propeller / Meta / other ad click ids.
        Enable Ringba and set the tag id under <strong>Honeycomb</strong><?= $ringbaLpCodesCompact ? ' above' : ' above' ?>, then copy below.
        Pixel URLs: <a href="?page=honeycomb-addon&amp;slug=ringba" style="color:#0d47a1;font-weight:600;">Honeycomb → Ringba</a>.
    </p>

    <div style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:8px;flex-wrap:wrap;">
            <p style="margin:0;font-weight:600;color:#333;font-size:14px;">1. Ringba script + tag for <em>Kuma’s</em> click id <span style="font-weight:400;color:#666;">(before <code>&lt;/body&gt;</code>)</span></p>
            <button type="button" onclick="copyRingbaSnippet('ringba-script-code', this)" style="padding:6px 14px;font-size:12px;background:#1565c0;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;">📋 Copy Script</button>
        </div>
        <pre id="ringba-script-code" style="margin:0;padding:12px;background:#1e1e1e;color:#d4d4d4;border-radius:6px;overflow:auto;font-size:12px;line-height:1.45;white-space:pre-wrap;"></pre>
        <p style="margin:8px 0 0;font-size:12px;color:#666;line-height:1.45;">
            Pushes <strong>Kuma’s</strong> click id into Ringba <code>_rgba_tags</code> (not the ad network’s), then loads your number-pool script.
            In Ringba, create a URL Parameter with the same tag name so pixels can return
            <code>[tag:User:…]</code> to Kuma’s postback.
        </p>
    </div>

    <div id="ringba-number-hint-wrap" style="margin-bottom:12px;display:none;padding:12px;background:#fff;border:1px solid #90caf9;border-radius:6px;font-size:12px;color:#555;line-height:1.5;">
        <strong>Number to replace:</strong> put <code id="ringba-number-hint-text"></code> on the page as the visible phone number (any format). Ringba swaps it from the pool.
    </div>

    <div style="padding:12px;background:#fff;border:1px solid #90caf9;border-radius:6px;font-size:12px;color:#555;line-height:1.5;">
        <strong>In Ringba:</strong> add Pixels for Connected / Converted / Payout using the URLs on
        <a href="?page=honeycomb-addon&amp;slug=ringba" style="color:#0d47a1;">Honeycomb → Ringba</a>.
        Use <code>txid=[Call:InboundCallId]</code> so Kuma can dedupe call events.
        Pixel <code>click_id</code> token must match your tag name (default <code id="ringba-pixel-tag-hint">[tag:User:<?= htmlspecialchars($clickParam !== '' ? $clickParam : 'click_id', ENT_QUOTES) ?>]</code>).
    </div>
</div>
