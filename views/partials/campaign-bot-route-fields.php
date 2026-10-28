<?php
/**
 * Bot and proxy routing. Shared by the campaign editor and the create wizard.
 *
 * Expects:
 * - $botRouteMode (string) allow | record_only | safe_url
 * - $botRouteUrl (string)
 */
$botRouteMode = $botRouteMode ?? 'allow';
$botRouteUrl = $botRouteUrl ?? '';
if (!in_array($botRouteMode, ['allow', 'record_only', 'safe_url'], true)) {
    $botRouteMode = 'allow';
}
?>
<div class="campaign-form-field" id="bot-route-box" style="margin-top: 16px;">
    <label class="campaign-form-label" for="bot_route_mode">Bots and proxy traffic</label>
    <select name="bot_route_mode" id="bot_route_mode" class="campaign-form-input campaign-form-input--narrow"
            onchange="toggleBotRouteFields()">
        <option value="allow" <?= $botRouteMode === 'allow' ? 'selected' : '' ?>>Allow — send them with everyone else</option>
        <option value="record_only" <?= $botRouteMode === 'record_only' ? 'selected' : '' ?>>Record only — log the visit, do not send them on</option>
        <option value="safe_url" <?= $botRouteMode === 'safe_url' ? 'selected' : '' ?>>Safe URL — log the visit, then send them to a URL</option>
    </select>
    <div class="campaign-form-hint">Known bots, and proxy, datacenter, hosting, or VPN connections. Suspected bots are included only when Settings already counts them as bots. Edge redirect stays on Allow.</div>
    <div id="bot_route_url_wrap" style="margin-top: 10px; <?= $botRouteMode === 'safe_url' ? '' : 'display:none;' ?>">
        <label class="campaign-form-label" for="bot_route_url">Safe URL</label>
        <input type="url" name="bot_route_url" id="bot_route_url" class="campaign-form-input"
               value="<?= htmlspecialchars((string) $botRouteUrl, ENT_QUOTES, 'UTF-8') ?>"
               placeholder="https://example.com/safe">
    </div>
</div>
<script>
function toggleBotRouteFields() {
    var modeEl = document.getElementById('bot_route_mode');
    var urlWrap = document.getElementById('bot_route_url_wrap');
    if (!modeEl || !urlWrap) return;
    urlWrap.style.display = modeEl.value === 'safe_url' ? '' : 'none';
}
</script>
