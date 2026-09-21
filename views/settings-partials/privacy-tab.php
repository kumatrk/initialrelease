<?php
$advancedLifecycleEnabled = ($allSettings['advanced_data_lifecycle'] ?? '0') === '1'
    || (int) ($allSettings['log_retention_days'] ?? '0') > 0
    || (int) ($allSettings['archive_after_days'] ?? '0') > 0;
?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Privacy & Attribution</h2>
    </div>
    <div class="card-body">
        <form method="post" id="privacy-settings-form">
            <input type="hidden" name="action" value="update_settings">

            <div style="max-width: 640px;">
                <p style="margin: 0 0 24px 0; color: #555; font-size: 14px; line-height: 1.5;">
                    Core settings apply immediately. Archive and deletion are optional, need a daily server cron, and are for high-volume installs only.
                </p>

                <div style="margin-bottom: 28px;">
                    <h3 style="margin: 0 0 16px 0; color: #3d5a26; font-size: 18px; border-bottom: 2px solid #e0e0e0; padding-bottom: 8px;">Privacy</h3>
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                        <input type="checkbox" name="ip_anonymization"
                               <?= ($allSettings['ip_anonymization'] ?? '0') === '1' ? 'checked' : '' ?>
                               style="width: 20px; height: 20px;">
                        <span style="font-weight: 600;">Enable IP anonymization</span>
                    </label>
                    <div style="font-size: 12px; color: #666; margin-top: 8px; margin-left: 32px;">
                        Masks the last IPv4 octet before storage. May reduce geo and Facebook CAPI match quality.
                    </div>
                </div>

                <div style="margin-bottom: 28px;">
                    <h3 style="margin: 0 0 16px 0; color: #3d5a26; font-size: 18px; border-bottom: 2px solid #e0e0e0; padding-bottom: 8px;">Conversion attribution</h3>
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Attribution window</label>
                    <?php
                    $attrStored = (string) ($allSettings['attribution_window_days'] ?? '30');
                    if ($attrStored === '' || !is_numeric($attrStored)) {
                        $attrStored = '30';
                    }
                    $attrPresets = ['0', '7', '14', '30', '60', '90', '180', '365'];
                    ?>
                    <select name="attribution_window_days"
                            style="width: 100%; max-width: 280px; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                        <option value="0" <?= $attrStored === '0' ? 'selected' : '' ?>>Unlimited</option>
                        <option value="7" <?= $attrStored === '7' ? 'selected' : '' ?>>7 days</option>
                        <option value="14" <?= $attrStored === '14' ? 'selected' : '' ?>>14 days</option>
                        <option value="30" <?= $attrStored === '30' ? 'selected' : '' ?>>30 days</option>
                        <option value="60" <?= $attrStored === '60' ? 'selected' : '' ?>>60 days</option>
                        <option value="90" <?= $attrStored === '90' ? 'selected' : '' ?>>90 days</option>
                        <option value="180" <?= $attrStored === '180' ? 'selected' : '' ?>>180 days</option>
                        <option value="365" <?= $attrStored === '365' ? 'selected' : '' ?>>1 year</option>
                        <?php if (!in_array($attrStored, $attrPresets, true)): ?>
                        <option value="<?= htmlspecialchars($attrStored) ?>" selected><?= (int) $attrStored ?> days (current)</option>
                        <?php endif; ?>
                    </select>
                    <div style="font-size: 12px; color: #666; margin-top: 4px;">How long after a click that postbacks and pixels may record a conversion (default: 30). Unlimited never rejects for age.</div>
                </div>

                <details id="advanced-data-lifecycle" style="margin-bottom: 24px; border: 1px solid #ddd; border-radius: 8px; padding: 0 16px 16px; background: #fafafa;"<?= $advancedLifecycleEnabled ? ' open' : '' ?>>
                    <summary style="cursor: pointer; font-weight: 600; color: #555; padding: 16px 0;">Advanced: archive &amp; deletion (optional)</summary>
                    <div style="font-size: 13px; color: #8a4b00; background: #fff8e6; border: 1px solid #f0d78c; border-radius: 6px; padding: 12px; margin-bottom: 16px;">
                        <strong>Not required for most installs.</strong> Schedule <code>scripts/run-data-retention-cron.php</code> daily. Deletion is permanent. Campaign stats include archived clicks; dashboard and billing use active clicks only.
                    </div>
                    <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; margin-bottom: 20px;">
                        <input type="checkbox" name="advanced_data_lifecycle" id="advanced_data_lifecycle" value="1"
                               <?= $advancedLifecycleEnabled ? 'checked' : '' ?>
                               style="width: 20px; height: 20px; margin-top: 2px;">
                        <span style="font-weight: 600;">Enable archive and click data deletion</span>
                    </label>
                    <div class="advanced-lifecycle-fields" style="<?= $advancedLifecycleEnabled ? '' : 'opacity: 0.55;' ?>">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Archive after (days)</label>
                            <input type="number" name="archive_after_days" id="archive_after_days"
                                   value="<?= htmlspecialchars($allSettings['archive_after_days'] ?? '0') ?>"
                                   min="0" max="3650"
                                   <?= $advancedLifecycleEnabled ? '' : 'disabled' ?>
                                   style="width: 100%; max-width: 200px; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Move old clicks to archive (0 = off). Use only when the clicks table is very large.</div>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Click data retention</label>
                            <select name="log_retention_days" id="log_retention_days"
                                    <?= $advancedLifecycleEnabled ? '' : 'disabled' ?>
                                    style="width: 100%; max-width: 280px; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                <option value="0" <?= ($allSettings['log_retention_days'] ?? '0') === '0' ? 'selected' : '' ?>>Never delete</option>
                                <option value="30" <?= ($allSettings['log_retention_days'] ?? '0') === '30' ? 'selected' : '' ?>>30 days</option>
                                <option value="90" <?= ($allSettings['log_retention_days'] ?? '0') === '90' ? 'selected' : '' ?>>90 days</option>
                                <option value="180" <?= ($allSettings['log_retention_days'] ?? '0') === '180' ? 'selected' : '' ?>>180 days</option>
                                <option value="365" <?= ($allSettings['log_retention_days'] ?? '0') === '365' ? 'selected' : '' ?>>1 year</option>
                            </select>
                            <div style="font-size: 12px; color: #666; margin-top: 4px;">Permanently deletes old clicks and conversions. Must be at least as long as archive days, or Never delete.</div>
                        </div>
                    </div>
                </details>

                <button type="submit" class="btn btn-primary">Save privacy settings</button>
            </div>
        </form>
    </div>
    <script>
    (function () {
        var toggle = document.getElementById('advanced_data_lifecycle');
        var archive = document.getElementById('archive_after_days');
        var retention = document.getElementById('log_retention_days');
        var wrap = document.querySelector('.advanced-lifecycle-fields');
        if (!toggle || !archive || !retention) return;
        function syncAdvancedFields() {
            var on = toggle.checked;
            archive.disabled = !on;
            retention.disabled = !on;
            if (wrap) wrap.style.opacity = on ? '1' : '0.55';
        }
        toggle.addEventListener('change', syncAdvancedFields);
        syncAdvancedFields();
    })();
    </script>
</div>
