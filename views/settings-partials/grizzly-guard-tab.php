<?php
/**
 * Settings → Grizzly Guard.
 *
 * Expects $db and $canEditSettings.
 */
use SimpleKuma\Guard\GrizzlyGuardSettings;
use SimpleKuma\Settings\SettingsManager;

$guardSettings = new SettingsManager($db);
$guardEnabled = GrizzlyGuardSettings::isEnabled($guardSettings);
$guardAlert = (string) $guardSettings->get(GrizzlyGuardSettings::KEY_ALERT, '');
$guardHasVt = GrizzlyGuardSettings::hasSecret($guardSettings, GrizzlyGuardSettings::KEY_VT);
$guardHasGsb = GrizzlyGuardSettings::hasSecret($guardSettings, GrizzlyGuardSettings::KEY_GSB);
$guardIcon = (defined('ASSETS_BASE_URL') ? ASSETS_BASE_URL : '') . '/assets/images/grizzly-guard.png';
$guardLog = [];
$guardLogReady = false;
$logTable = $db->query("SHOW TABLES LIKE 'grizzly_guard_log'");
if ($logTable && $logTable->num_rows > 0) {
    $guardLogReady = true;
    $logResult = $db->query('SELECT created_at, action, target_label, detail FROM grizzly_guard_log ORDER BY id DESC LIMIT 30');
    if ($logResult) {
        while ($row = $logResult->fetch_assoc()) {
            $guardLog[] = $row;
        }
    }
}
$guardActionLabels = [
    'hold_offer' => 'Offer pulled',
    'release_offer' => 'Offer restored',
    'hold_lp' => 'Landing page pulled',
    'release_lp' => 'Landing page restored',
    'use_backup_lp' => 'Backup landing page used',
    'switch_domain' => 'Domain switched',
    'restore_domain' => 'Domain restored',
    'left_offer' => 'Offer left in place',
    'left_lp' => 'Landing page left in place',
    'left_domain' => 'Domain left in place',
    'domain_down' => 'Domain down',
    'anomaly_conversions' => 'Conversion drop',
    'anomaly_cost' => 'Revenue under cost',
    'anomaly_bots' => 'Bot spike',
    'anomaly_zone' => 'Zone spike',
    'anomaly_postback' => 'Postback failures',
    'anomaly_tracker' => 'Tracker error',
    'anomaly_silent' => 'No conversions',
];
$guardAnomalies = GrizzlyGuardSettings::anomalyConfig($guardSettings);
$guardAnomalyRows = [
    'conversion_drop' => ['Conversion drop', 'Today is down this far from yesterday.', '%'],
    'revenue_under_cost' => ['Revenue under cost', 'Today’s revenue is below click cost.', ''],
    'bot_spike' => ['Bot spike', 'Known bots are at least this share of today’s clicks.', '%'],
    'zone_spike' => ['Zone spike', 'One zone has this many times yesterday’s visits.', '×'],
    'postback_failure' => ['Postback failures', 'At least this many postbacks fail in an hour.', 'fails'],
    'tracker_errors' => ['Tracker errors', 'A click could not be saved. Uses the server error log when Kuma can read it.', ''],
    'no_conversions' => ['No conversions', 'A campaign reaches this many clicks today with no conversions.', 'clicks'],
];
?>
<div class="card<?= $guardEnabled ? ' is-on' : '' ?>" id="grizzly-guard-card">
    <div class="card-header" style="display: flex; align-items: center; gap: 10px;">
        <img class="grizzly-guard-card-mark" src="<?= htmlspecialchars($guardIcon, ENT_QUOTES, 'UTF-8') ?>" alt="" width="36" height="36">
        <h3 style="margin: 0;">Grizzly Guard</h3>
    </div>
    <div class="card-body">
        <p class="account-meta">Offers and landing pages that are down can be pulled or skipped. A visit that still arrives on a down domain goes to the next backup. The ad is not edited.</p>

        <?php if (!$guardLogReady): ?>
            <p class="notify-hint"><strong>Database update needed.</strong> Open Updates and run Update database so migration 100 can add the Grizzly Guard columns.</p>
        <?php endif; ?>

        <form method="post" action="?page=settings&tab=grizzly-guard" id="grizzly-guard-form">
            <?= \SimpleKuma\Auth\Csrf::field() ?>
            <input type="hidden" name="action" value="save_grizzly_guard">

            <div>
                <label class="grizzly-guard-arm<?= $guardEnabled ? ' is-on' : '' ?>" id="grizzly-guard-arm" for="grizzly_guard_enabled">
                    <input type="checkbox" id="grizzly_guard_enabled" name="grizzly_guard_enabled" value="1" <?= $guardEnabled ? 'checked' : '' ?> <?= empty($canEditSettings) ? 'disabled' : '' ?>>
                    <span class="grizzly-guard-arm__track" aria-hidden="true">
                        <span class="grizzly-guard-arm__shock"></span>
                        <span class="grizzly-guard-arm__spark grizzly-guard-arm__spark--1"><img src="<?= htmlspecialchars($guardIcon, ENT_QUOTES, 'UTF-8') ?>" alt=""></span>
                        <span class="grizzly-guard-arm__spark grizzly-guard-arm__spark--2"><img src="<?= htmlspecialchars($guardIcon, ENT_QUOTES, 'UTF-8') ?>" alt=""></span>
                        <span class="grizzly-guard-arm__spark grizzly-guard-arm__spark--3"><img src="<?= htmlspecialchars($guardIcon, ENT_QUOTES, 'UTF-8') ?>" alt=""></span>
                        <span class="grizzly-guard-arm__knob">
                            <img src="<?= htmlspecialchars($guardIcon, ENT_QUOTES, 'UTF-8') ?>" alt="">
                        </span>
                    </span>
                    <span class="grizzly-guard-arm__copy">
                        <span class="grizzly-guard-arm__title">Turn Grizzly Guard on for this account</span>
                        <span class="grizzly-guard-arm__status"><?= $guardEnabled ? 'Standing watch' : 'Asleep' ?></span>
                    </span>
                </label>
            </div>

            <div class="account-section">
            <h3>Anomaly alerts</h3>
            <p class="notify-hint">These run only while Grizzly Guard is standing watch. They use the notification channels on Account, plus the extra address on this tab. Each one starts off.</p>
            <div class="notify-board notify-board--rules">
                <div class="notify-board__head">
                    <span>Rule</span>
                    <span>On</span>
                    <span>Limit</span>
                </div>
                <?php foreach ($guardAnomalyRows as $guardRuleId => $guardRuleCopy): ?>
                    <?php $guardRule = $guardAnomalies[$guardRuleId]; ?>
                    <div class="notify-channel">
                        <div class="notify-channel__bar">
                            <span class="notify-channel__name"><?= htmlspecialchars($guardRuleCopy[0], ENT_QUOTES, 'UTF-8') ?><span class="guard-rule__note"><?= htmlspecialchars($guardRuleCopy[1], ENT_QUOTES, 'UTF-8') ?></span></span>
                            <label class="notify-channel__choice">
                                <input type="checkbox" name="grizzly_anomaly_<?= htmlspecialchars($guardRuleId, ENT_QUOTES, 'UTF-8') ?>" value="1" aria-label="<?= htmlspecialchars($guardRuleCopy[0], ENT_QUOTES, 'UTF-8') ?>" <?= !empty($guardRule['on']) ? 'checked' : '' ?> <?= empty($canEditSettings) ? 'disabled' : '' ?>>
                            </label>
                            <?php if ($guardRuleCopy[2] !== ''): ?>
                                <label class="guard-threshold">
                                    <input type="number" name="grizzly_anomaly_<?= htmlspecialchars($guardRuleId, ENT_QUOTES, 'UTF-8') ?>_value" value="<?= (int) $guardRule['value'] ?>" min="<?= (int) $guardRule['min'] ?>" max="<?= (int) $guardRule['max'] ?>" aria-label="<?= htmlspecialchars($guardRuleCopy[0], ENT_QUOTES, 'UTF-8') ?> limit" <?= empty($canEditSettings) ? 'disabled' : '' ?>>
                                    <span class="guard-threshold__unit"><?= htmlspecialchars($guardRuleCopy[2], ENT_QUOTES, 'UTF-8') ?></span>
                                </label>
                            <?php else: ?>
                                <span class="guard-threshold guard-threshold--empty" aria-hidden="true">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            </div>

            <?php
            $guardPool = GrizzlyGuardSettings::domainPool($guardSettings);
            $guardDomains = [];
            $guardDomainResult = $db->query("SELECT id, domain FROM tracking_domains WHERE status IN ('verified', 'verified_manual') ORDER BY domain ASC");
            if ($guardDomainResult) {
                while ($guardDomainRow = $guardDomainResult->fetch_assoc()) {
                    $guardDomains[] = $guardDomainRow;
                }
            }
            ?>
            <div class="account-section">
            <div class="account-split">
                <div class="account-panel">
                    <h3>Backup domains</h3>
                    <p class="notify-hint">Tried in order. An empty slot ends the list. The same domain cannot be picked twice.</p>
                    <?php if ($guardDomains === []): ?>
                        <p class="notify-hint">No verified tracking domains yet. Verify one under Settings → Domains.</p>
                    <?php endif; ?>
                    <?php for ($guardSlot = 1; $guardSlot <= 3; $guardSlot++): ?>
                        <?php $guardSelected = (int) ($guardPool[$guardSlot - 1] ?? 0); ?>
                        <div class="account-field">
                            <label for="grizzly_guard_domain_<?= $guardSlot ?>">Backup domain <?= $guardSlot ?></label>
                            <select id="grizzly_guard_domain_<?= $guardSlot ?>" name="grizzly_guard_domain_<?= $guardSlot ?>" <?= empty($canEditSettings) ? 'disabled' : '' ?>>
                                <option value="">None</option>
                                <?php foreach ($guardDomains as $guardDomain): ?>
                                    <option value="<?= (int) $guardDomain['id'] ?>" <?= $guardSelected === (int) $guardDomain['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $guardDomain['domain'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="account-panel">
                    <h3>Extra alert</h3>
                    <div class="account-field">
                        <label for="grizzly_guard_alert">Email or webhook</label>
                        <input type="text" name="grizzly_guard_alert" id="grizzly_guard_alert"
                               value="<?= htmlspecialchars($guardAlert, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="you@example.com or https://example.com/hook"
                               <?= empty($canEditSettings) ? 'disabled' : '' ?>>
                    </div>
                    <p class="notify-hint">One extra channel. Discord, Slack, Telegram, ntfy, and SMTP stay under Account.</p>
                    <h3 class="guard-subhead">Listing checks</h3>
                    <div class="account-field">
                        <label for="grizzly_guard_gsb_key">Google Safe Browsing key</label>
                        <input type="password" name="grizzly_guard_gsb_key" id="grizzly_guard_gsb_key" value="" autocomplete="new-password" <?= empty($canEditSettings) ? 'disabled' : '' ?>>
                        <p class="notify-hint" style="margin-top: 6px;"><?= $guardHasGsb ? 'A key is saved. Leave this blank to keep it.' : 'Optional. Checked on the same run as the link check.' ?></p>
                        <?php if ($guardHasGsb && !empty($canEditSettings)): ?>
                            <label class="notify-channel__choice" style="justify-content: flex-start; margin-top: 6px;"><input type="checkbox" name="grizzly_guard_clear_gsb" value="1"> Remove the saved key</label>
                        <?php endif; ?>
                    </div>
                    <div class="account-field">
                        <label for="grizzly_guard_vt_key">VirusTotal key</label>
                        <input type="password" name="grizzly_guard_vt_key" id="grizzly_guard_vt_key" value="" autocomplete="new-password" <?= empty($canEditSettings) ? 'disabled' : '' ?>>
                        <p class="notify-hint" style="margin-top: 6px;"><?= $guardHasVt ? 'A key is saved. Leave this blank to keep it.' : 'Optional. Listed when at least three engines mark it malicious.' ?></p>
                        <?php if ($guardHasVt && !empty($canEditSettings)): ?>
                            <label class="notify-channel__choice" style="justify-content: flex-start; margin-top: 6px;"><input type="checkbox" name="grizzly_guard_clear_vt" value="1"> Remove the saved key</label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            </div>
        </form>

        <?php if (!empty($canEditSettings)): ?>
            <div class="guard-actions">
                <button type="submit" class="btn btn-primary" form="grizzly-guard-form">Save Grizzly Guard</button>
                <form method="post" action="?page=settings&tab=grizzly-guard">
                    <?= \SimpleKuma\Auth\Csrf::field() ?>
                    <input type="hidden" name="action" value="run_grizzly_guard">
                    <button type="submit" class="btn btn-outline">Run a check now</button>
                </form>
                <p class="notify-hint">Uses the saved settings. Schedule <code>php scripts/grizzly-guard-cron.php</code> every 5 minutes.</p>
            </div>
        <?php endif; ?>

        <div class="account-section">
        <h3>Recent actions</h3>
        <?php if (!$guardLogReady): ?>
            <p>The action log is created by the database update.</p>
        <?php elseif ($guardLog === []): ?>
            <p>Nothing has been recorded yet.</p>
        <?php else: ?>
            <table class="table" style="width: 100%; margin-top: 8px;">
                <thead>
                    <tr>
                        <th>When (UTC)</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guardLog as $entry): ?>
                        <?php $actionKey = (string) ($entry['action'] ?? ''); ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($guardActionLabels[$actionKey] ?? $actionKey, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($entry['target_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($entry['detail'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    </div>
</div>
<script>
(function () {
    var input = document.getElementById('grizzly_guard_enabled');
    var arm = document.getElementById('grizzly-guard-arm');
    var card = document.getElementById('grizzly-guard-card');
    if (!input || !arm) return;
    var status = arm.querySelector('.grizzly-guard-arm__status');
    function paint(engage) {
        var on = input.checked;
        arm.classList.toggle('is-on', on);
        if (card) card.classList.toggle('is-on', on);
        if (status) status.textContent = on ? 'Standing watch' : 'Asleep';
        if (!engage) return;
        arm.classList.remove('is-engaging');
        if (card) card.classList.remove('is-engaging');
        void arm.offsetWidth;
        arm.classList.add('is-engaging');
        if (card && on) card.classList.add('is-engaging');
        window.setTimeout(function () {
            arm.classList.remove('is-engaging');
            if (card) card.classList.remove('is-engaging');
        }, 700);
    }
    input.addEventListener('change', function () { paint(true); });
    paint(false);
})();
</script>
