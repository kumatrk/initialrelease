<h3 class="wizard-step-title">Basics</h3>
<p class="wizard-step-desc">Name your campaign and set core tracking options.</p>

<div class="campaign-form-grid campaign-form-grid--name-cpc" style="margin-bottom: 20px;">
    <div class="campaign-form-field" style="margin-bottom:0;">
        <label class="campaign-form-label" for="wizard_campaign_name">Campaign Name <span class="campaign-form-required">*</span></label>
        <input type="text" name="name" id="wizard_campaign_name" value="<?= htmlspecialchars(cc_input('name')) ?>" required
               placeholder="e.g., FB Keto Campaign" class="campaign-form-input">
    </div>
    <div class="campaign-form-field" style="margin-bottom:0;">
        <label class="campaign-form-label" for="wizard_default_cpc">Default CPC</label>
        <input type="number" name="default_cpc" id="wizard_default_cpc" step="any" min="0"
               value="<?= htmlspecialchars(cc_input('default_cpc')) ?>"
               placeholder="0.00" class="campaign-form-input">
        <div class="campaign-form-hint">Used when cost param not provided</div>
    </div>
</div>

<div class="campaign-form-grid campaign-form-grid--auto">
    <div>
        <label class="campaign-form-label campaign-form-label--sm" for="wizard_status">Status</label>
        <select name="status" id="wizard_status" class="campaign-form-input campaign-form-input--sm">
            <option value="active" <?= cc_selected(cc_input('status', 'active'), 'active') ?>>Active</option>
            <option value="paused" <?= cc_selected(cc_input('status'), 'paused') ?>>Paused</option>
            <option value="archived" <?= cc_selected(cc_input('status'), 'archived') ?>>Archived</option>
        </select>
    </div>
    <div>
        <label class="campaign-form-label campaign-form-label--sm" for="wizard_campaign_group_id">Group (Optional)</label>
        <select name="campaign_group_id" id="wizard_campaign_group_id" class="campaign-form-input campaign-form-input--sm">
            <option value="">No Group</option>
            <?php foreach ($campaignGroups as $group): ?>
                <option value="<?= $group['id'] ?>" <?= cc_selected(cc_input('campaign_group_id'), $group['id']) ?>>
                    <?= htmlspecialchars($group['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="campaign-form-hint campaign-form-hint--sm">
            <a href="?page=settings&tab=campaign-groups" class="campaign-form-link">Manage Groups</a>
        </div>
    </div>
    <div>
        <label class="campaign-form-label campaign-form-label--sm" for="wizard_referrer_mode">Referrer privacy</label>
        <select name="referrer_mode" id="wizard_referrer_mode" class="campaign-form-input campaign-form-input--sm">
            <option value="" <?= cc_selected(cc_input('referrer_mode') ?: cc_input('cloaking_mode'), '') ?>>Standard redirect</option>
            <option value="blank" <?= cc_selected(cc_input('referrer_mode') ?: cc_input('cloaking_mode'), 'blank') ?>>Strip referrer (meta refresh)</option>
            <option value="noreferrer" <?= cc_selected(cc_input('referrer_mode') ?: cc_input('cloaking_mode'), 'noreferrer') ?>>No referrer header</option>
            <option value="double" <?= cc_selected(cc_input('referrer_mode') ?: cc_input('cloaking_mode'), 'double') ?>>Bear hop (two-step)</option>
        </select>
    </div>
    <div>
        <label class="campaign-form-label campaign-form-label--sm" for="wizard_tracking_domain_id">Tracking Domain</label>
        <select name="tracking_domain_id" id="wizard_tracking_domain_id" class="campaign-form-input campaign-form-input--sm">
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

<div class="campaign-form-field" style="margin-top: 16px;">
    <label class="campaign-form-label" for="wizard_tags">Tags</label>
    <input type="text" name="tags" id="wizard_tags" value="<?= htmlspecialchars(cc_input('tags')) ?>"
           placeholder="e.g., sweeps, tier1, test" class="campaign-form-input">
    <div class="campaign-form-hint">Comma-separated tags for filtering and organizing</div>
</div>

<?php
$inactiveRedirectMode = cc_input('inactive_redirect_mode', 'off');
$inactiveRedirectCampaignId = cc_input('inactive_redirect_campaign_id') !== ''
    ? (int) cc_input('inactive_redirect_campaign_id')
    : null;
$inactiveRedirectUrl = cc_input('inactive_redirect_url');
$inactiveRedirectExcludeId = null;
$inactiveRedirectCampaignOptions = $inactiveRedirectCampaignOptions ?? [];
$inactiveRedirectCompact = false;
include __DIR__ . '/../partials/campaign-inactive-redirect-fields.php';
?>
