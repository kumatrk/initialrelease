<?php
/**
 * Grizzly Guard campaign choices. Shared by the campaign editor and the create wizard.
 * Render this outside the offer-rotation fieldset. The backup offer stays on fallback_offer_id.
 *
 * Expects:
 * - $guardMode (string) off | backups | full
 * - $guardBackupLpId (int|string)
 * - $guardLastResortUrl (string)
 * - $offers, $landingPages, $verifiedTrackingDomains when available
 */
$guardMode = $guardMode ?? 'off';
if (!in_array($guardMode, ['off', 'backups', 'full'], true)) {
    $guardMode = 'off';
}
$guardBackupLpId = (string) ($guardBackupLpId ?? '');
$guardLastResortUrl = (string) ($guardLastResortUrl ?? '');
$guardLandingPages = $landingPages ?? [];
$guardIcon = (defined('ASSETS_BASE_URL') ? ASSETS_BASE_URL : '') . '/assets/images/grizzly-guard.png';
$guardDestHidden = $guardMode === 'off';

$guardModeHelp = [
    'off' => 'Guard will not change this campaign. Dead links stay as they are. Account-wide Settings → Grizzly Guard can still watch other campaigns.',
    'backups' => 'Moves traffic only to destinations you set: Settings backup domains, the fallback offer, the backup landing page below, or the last-resort URL. If none of those are up, traffic stays put.',
    'full' => 'Tries your backups first. If those are also down, Guard can hop to another verified tracking domain or another healthy offer / landing page in this campaign.',
];
$guardHelpNow = $guardModeHelp[$guardMode] ?? $guardModeHelp['off'];
?>
<fieldset class="grizzly-guard-panel" id="grizzly-guard-box">
    <legend>
        <img src="<?= htmlspecialchars($guardIcon, ENT_QUOTES, 'UTF-8') ?>" alt="" width="22" height="22">
        Grizzly Guard
    </legend>
    <div class="campaign-form-field">
        <label class="campaign-form-label" for="guard_mode">If a link or tracking domain goes down</label>
        <select name="guard_mode" id="guard_mode" class="campaign-form-input campaign-form-input--narrow"
                onchange="toggleGrizzlyGuardFields()"
                aria-describedby="grizzly-guard-mode-help">
            <option value="off" <?= $guardMode === 'off' ? 'selected' : '' ?>>Off — leave this campaign alone</option>
            <option value="backups" <?= $guardMode === 'backups' ? 'selected' : '' ?>>Only use my backups</option>
            <option value="full" <?= $guardMode === 'full' ? 'selected' : '' ?>>Full Grizzly Guard protection</option>
        </select>
        <p class="grizzly-guard-mode-help" id="grizzly-guard-mode-help"
           data-help-off="<?= htmlspecialchars($guardModeHelp['off'], ENT_QUOTES, 'UTF-8') ?>"
           data-help-backups="<?= htmlspecialchars($guardModeHelp['backups'], ENT_QUOTES, 'UTF-8') ?>"
           data-help-full="<?= htmlspecialchars($guardModeHelp['full'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($guardHelpNow, ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>
    <div id="grizzly-guard-destinations" <?= $guardDestHidden ? 'hidden' : '' ?>>
        <div class="campaign-form-grid campaign-form-grid--2">
            <div class="campaign-form-field">
                <label class="campaign-form-label" for="guard_backup_lp_id">Backup landing page</label>
                <select name="guard_backup_lp_id" id="guard_backup_lp_id" class="campaign-form-input">
                    <option value="">None</option>
                    <?php foreach ($guardLandingPages as $lp): ?>
                        <?php $lpId = (string) ($lp['id'] ?? ''); ?>
                        <option value="<?= htmlspecialchars($lpId, ENT_QUOTES, 'UTF-8') ?>" <?= $guardBackupLpId === $lpId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($lp['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="campaign-form-field" style="margin-bottom: 0;">
            <label class="campaign-form-label" for="guard_last_resort_url">Last-resort URL</label>
            <input type="url" name="guard_last_resort_url" id="guard_last_resort_url" class="campaign-form-input"
                   value="<?= htmlspecialchars($guardLastResortUrl, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="https://example.com/safe">
            <p class="campaign-form-hint">Plain http or https. Offer tokens are not added. Used only when nothing you picked is still up.</p>
        </div>
    </div>
</fieldset>
<script>
function toggleGrizzlyGuardFields() {
    var modeEl = document.getElementById('guard_mode');
    var dest = document.getElementById('grizzly-guard-destinations');
    var help = document.getElementById('grizzly-guard-mode-help');
    if (!modeEl) return;
    var mode = modeEl.value;
    if (dest) dest.hidden = mode === 'off';
    if (!help) return;
    var text = help.getAttribute('data-help-' + mode);
    if (text) help.textContent = text;
}
toggleGrizzlyGuardFields();
</script>
