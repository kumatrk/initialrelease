<?php
/**
 * Inactive redirect controls (paused / archived).
 * Shared by campaign editor and create wizard.
 *
 * Expects:
 * - $inactiveRedirectMode (string)
 * - $inactiveRedirectCampaignId (int|null)
 * - $inactiveRedirectUrl (string)
 * - $inactiveRedirectCampaignOptions (list of campaigns with id, name, status)
 * - $inactiveRedirectExcludeId (int|null) current campaign when editing
 * - $inactiveRedirectCompact (bool) optional — tighter layout for wizard
 */
$inactiveRedirectMode = $inactiveRedirectMode ?? 'off';
$inactiveRedirectCampaignId = $inactiveRedirectCampaignId ?? null;
$inactiveRedirectUrl = $inactiveRedirectUrl ?? '';
$inactiveRedirectCampaignOptions = $inactiveRedirectCampaignOptions ?? [];
$inactiveRedirectExcludeId = $inactiveRedirectExcludeId ?? null;
$inactiveRedirectCompact = !empty($inactiveRedirectCompact);
$fieldClass = $inactiveRedirectCompact ? '' : 'campaign-form-field';
$labelClass = $inactiveRedirectCompact ? '' : 'campaign-form-label';
$inputClass = $inactiveRedirectCompact ? '' : 'campaign-form-input';
$hintClass = $inactiveRedirectCompact ? '' : 'campaign-form-hint';
$inlineStyle = $inactiveRedirectCompact
    ? 'style="margin-top:16px;padding:14px 16px;border:2px solid #e0e0e0;border-radius:6px;background:#fafafa;"'
    : '';
$labelStyle = $inactiveRedirectCompact ? 'style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;"' : '';
$inputStyle = $inactiveRedirectCompact ? 'style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;"' : '';
$hintStyle = $inactiveRedirectCompact ? 'style="font-size:12px;color:#666;margin-top:6px;line-height:1.45;"' : '';
?>
<div class="inactive-redirect-box <?= $fieldClass ?>" id="inactive-redirect-box" <?= $inlineStyle ?>>
    <label class="<?= $labelClass ?>" for="inactive_redirect_mode" <?= $labelStyle ?>>
        When paused or archived
    </label>
    <select name="inactive_redirect_mode" id="inactive_redirect_mode"
            class="<?= $inputClass ?>"
            <?= $inputStyle ?>
            onchange="toggleInactiveRedirectFields()">
        <option value="off" <?= $inactiveRedirectMode === 'off' ? 'selected' : '' ?>>Off — show not found</option>
        <option value="campaign" <?= $inactiveRedirectMode === 'campaign' ? 'selected' : '' ?>>Redirect to another campaign</option>
        <option value="url" <?= $inactiveRedirectMode === 'url' ? 'selected' : '' ?>>Redirect to a custom URL</option>
    </select>
    <div class="<?= $hintClass ?>" <?= $hintStyle ?>>
        Sends incoming tracking-link traffic somewhere useful instead of a dead end.
        Redirecting to another campaign creates a normal click there (tracked). Custom URL does not create a Kuma click.
    </div>

    <div id="inactive_redirect_campaign_wrap"
         style="margin-top:12px;<?= $inactiveRedirectMode === 'campaign' ? '' : 'display:none;' ?>">
        <label class="<?= $labelClass ?>" for="inactive_redirect_campaign_id" <?= $labelStyle ?>>
            Target campaign
        </label>
        <select name="inactive_redirect_campaign_id" id="inactive_redirect_campaign_id"
                class="<?= $inputClass ?>" <?= $inputStyle ?>>
            <option value="">Select an active campaign…</option>
            <?php foreach ($inactiveRedirectCampaignOptions as $optCamp):
                $optId = (int) ($optCamp['id'] ?? 0);
                if ($optId <= 0) {
                    continue;
                }
                if ($inactiveRedirectExcludeId !== null && $optId === (int) $inactiveRedirectExcludeId) {
                    continue;
                }
                if (($optCamp['status'] ?? '') !== 'active') {
                    continue;
                }
                $selected = ((int) $inactiveRedirectCampaignId === $optId) ? 'selected' : '';
                ?>
                <option value="<?= $optId ?>" <?= $selected ?>>
                    <?= htmlspecialchars((string) ($optCamp['name'] ?? ('Campaign #' . $optId))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div id="inactive_redirect_url_wrap"
         style="margin-top:12px;<?= $inactiveRedirectMode === 'url' ? '' : 'display:none;' ?>">
        <label class="<?= $labelClass ?>" for="inactive_redirect_url" <?= $labelStyle ?>>
            Custom URL
        </label>
        <input type="url" name="inactive_redirect_url" id="inactive_redirect_url"
               class="<?= $inputClass ?>" <?= $inputStyle ?>
               value="<?= htmlspecialchars((string) $inactiveRedirectUrl) ?>"
               placeholder="https://example.com/backup">
    </div>
</div>
<script>
function toggleInactiveRedirectFields() {
    var modeEl = document.getElementById('inactive_redirect_mode');
    var campWrap = document.getElementById('inactive_redirect_campaign_wrap');
    var urlWrap = document.getElementById('inactive_redirect_url_wrap');
    if (!modeEl || !campWrap || !urlWrap) return;
    var mode = modeEl.value;
    campWrap.style.display = mode === 'campaign' ? '' : 'none';
    urlWrap.style.display = mode === 'url' ? '' : 'none';
}
</script>
