<h3 class="wizard-step-title">Basics</h3>
<p class="wizard-step-desc">Name your campaign and set core tracking options.</p>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 20px;">
    <div>
        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Campaign Name <span style="color:#d32f2f;">*</span></label>
        <input type="text" name="name" value="<?= htmlspecialchars(cc_input('name')) ?>" required
               placeholder="e.g., FB Keto Campaign"
               style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;">
    </div>
    <div>
        <label style="display: block; font-weight: 600; margin-bottom: 8px;">Default CPC</label>
        <input type="number" name="default_cpc" step="any" min="0" value="<?= htmlspecialchars(cc_input('default_cpc')) ?>"
               placeholder="0.00" style="width:100%;padding:10px;border:2px solid #ddd;border-radius:4px;">
        <div style="font-size:12px;color:#666;margin-top:4px;">Used when cost param not provided</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
    <div>
        <label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;">Status</label>
        <select name="status" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;">
            <option value="active" <?= cc_selected(cc_input('status', 'active'), 'active') ?>>Active</option>
            <option value="paused" <?= cc_selected(cc_input('status'), 'paused') ?>>Paused</option>
            <option value="archived" <?= cc_selected(cc_input('status'), 'archived') ?>>Archived</option>
        </select>
    </div>
    <div>
        <label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;">Group (Optional)</label>
        <select name="campaign_group_id" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;">
            <option value="">No Group</option>
            <?php foreach ($campaignGroups as $group): ?>
                <option value="<?= $group['id'] ?>" <?= cc_selected(cc_input('campaign_group_id'), $group['id']) ?>>
                    <?= htmlspecialchars($group['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;">Referrer privacy</label>
        <select name="referrer_mode" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;">
            <option value="" <?= cc_selected(cc_input('referrer_mode') ?: cc_input('cloaking_mode'), '') ?>>Standard redirect</option>
            <option value="blank" <?= cc_selected(cc_input('referrer_mode') ?: cc_input('cloaking_mode'), 'blank') ?>>Strip referrer (meta refresh)</option>
            <option value="noreferrer" <?= cc_selected(cc_input('referrer_mode') ?: cc_input('cloaking_mode'), 'noreferrer') ?>>No referrer header</option>
            <option value="double" <?= cc_selected(cc_input('referrer_mode') ?: cc_input('cloaking_mode'), 'double') ?>>Bear hop (two-step)</option>
        </select>
    </div>
    <div>
        <label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;">Tracking Domain</label>
        <select name="tracking_domain_id" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;">
            <option value="">Use Main Tracker Domain</option>
            <?php foreach ($verifiedTrackingDomains as $domain): ?>
                <?php $domainStatusLabel = ($domain['status'] ?? '') === 'verified_manual' ? ' (Manual)' : ''; ?>
                <option value="<?= $domain['id'] ?>" <?= cc_selected(cc_input('tracking_domain_id'), $domain['id']) ?>>
                    <?= htmlspecialchars($domain['domain']) ?><?= $domainStatusLabel ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
