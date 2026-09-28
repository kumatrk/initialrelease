<?php
use SimpleKuma\Release\TrafficSourceReleaseHelper;
?>
                <!-- Main Settings (sectioned) -->
                <div class="campaign-form-main">
                    <?php
                    // --- Section 1: Campaign ---
                    ob_start();
                    ?>
                        <div class="campaign-form-grid campaign-form-grid--name-cpc">
                            <div>
                                <label class="campaign-form-label">
                                    Campaign Name <span class="campaign-form-required">*</span>
                                </label>
                                <input type="text" name="name" value="<?= htmlspecialchars($editCampaign['name'] ?? '') ?>"
                                       required placeholder="e.g., FB Keto Campaign"
                                       class="campaign-form-input">
                            </div>
                            <div>
                                <label class="campaign-form-label">Default CPC</label>
                                <?php
                                $editDefaultCpc = $editCampaign['default_cpc'] ?? null;
                                $editDefaultCpcValue = ($editDefaultCpc === null || $editDefaultCpc === '')
                                    ? ''
                                    : rtrim(rtrim(number_format((float) $editDefaultCpc, 6, '.', ''), '0'), '.');
                                ?>
                                <input type="number" name="default_cpc" step="any" min="0"
                                       value="<?= htmlspecialchars($editDefaultCpcValue) ?>"
                                       placeholder="0.00"
                                       class="campaign-form-input">
                                <div class="campaign-form-hint">Used when cost param not provided</div>
                            </div>
                        </div>

                        <div class="campaign-form-field">
                            <label class="campaign-form-label">Tags</label>
                            <input type="text" name="tags" value="<?= htmlspecialchars($editCampaign['tags'] ?? '') ?>"
                                   placeholder="e.g. sweeps, tier1, test (comma-separated)"
                                   class="campaign-form-input">
                            <div class="campaign-form-hint">Comma-separated tags for filtering and organizing</div>
                        </div>

                        <div class="campaign-settings-row campaign-form-grid campaign-form-grid--auto">
                            <div>
                                <label class="campaign-form-label campaign-form-label--sm">Status</label>
                                <select name="status" class="campaign-form-input campaign-form-input--sm">
                                    <option value="active" <?= ($editCampaign['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="paused" <?= ($editCampaign['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                                    <option value="archived" <?= ($editCampaign['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                                </select>
                            </div>
                            <div>
                                <label class="campaign-form-label campaign-form-label--sm">Group (Optional)</label>
                                <select name="campaign_group_id" class="campaign-form-input campaign-form-input--sm">
                                    <option value="">No Group</option>
                                    <?php foreach ($campaignGroups as $group): ?>
                                        <option value="<?= $group['id'] ?>" <?= ($editCampaign['campaign_group_id'] ?? 0) == $group['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($group['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="campaign-form-hint campaign-form-hint--sm">
                                    <a href="?page=settings&tab=campaign-groups" class="campaign-form-link">Manage Groups</a>
                                </div>
                            </div>
                            <?php $editReferrerMode = $editCampaign['referrer_mode'] ?? $editCampaign['cloaking_mode'] ?? ''; ?>
                            <div>
                                <label class="campaign-form-label campaign-form-label--sm">Referrer privacy</label>
                                <select name="referrer_mode" class="campaign-form-input campaign-form-input--sm">
                                    <option value="" <?= $editReferrerMode === '' ? 'selected' : '' ?>>Standard redirect</option>
                                    <option value="blank" <?= $editReferrerMode === 'blank' ? 'selected' : '' ?>>Strip referrer (meta refresh)</option>
                                    <option value="noreferrer" <?= $editReferrerMode === 'noreferrer' ? 'selected' : '' ?>>No referrer header</option>
                                    <option value="double" <?= $editReferrerMode === 'double' ? 'selected' : '' ?>>Bear hop (two-step)</option>
                                </select>
                            </div>
                        </div>

                        <?php
                        $editCampaignSafeForInactive = is_array($editCampaign ?? null) ? $editCampaign : [];
                        $inactiveRedirectMode = $editCampaignSafeForInactive['inactive_redirect_mode'] ?? ($_POST['inactive_redirect_mode'] ?? 'off');
                        $inactiveRedirectCampaignId = $editCampaignSafeForInactive['inactive_redirect_campaign_id'] ?? ($_POST['inactive_redirect_campaign_id'] ?? null);
                        $inactiveRedirectUrl = $editCampaignSafeForInactive['inactive_redirect_url'] ?? ($_POST['inactive_redirect_url'] ?? '');
                        $inactiveRedirectExcludeId = ($action === 'edit' && !empty($editCampaignSafeForInactive['id']))
                            ? (int) $editCampaignSafeForInactive['id']
                            : null;
                        $inactiveRedirectCampaignOptions = $campaign->getActiveOptionsForInactiveRedirect($inactiveRedirectExcludeId);
                        $inactiveRedirectCompact = false;
                        include __DIR__ . '/campaign-inactive-redirect-fields.php';
                        ?>

                        <div class="campaign-form-field">
                            <label class="campaign-form-label">
                                Traffic Source <span class="campaign-form-required">*</span>
                            </label>
                            <?php if ($isLegacyAutoDetectCampaign): ?>
                            <div class="campaign-form-notice campaign-form-notice--warn">
                                This campaign was using <strong>Kuma Auto Detected</strong>, which is no longer available. Select a specific traffic source before saving.
                            </div>
                            <?php endif; ?>
                            <select name="traffic_source_id" id="traffic_source_id"
                                    onchange="updateTrackingLink(); toggleFacebookIntegration(); toggleGoogleAdsIntegration(); toggleHoneycombBindings(); toggleWhopLpCodes(); toggleTrafficSourceSelector(); syncRedirectlessTrafficSourceFromCampaign(); toggleTsIntegrationEmpty();"
                                    class="campaign-form-input"
                                    aria-label="Select traffic source" required>
                                <?php if ($isLegacyAutoDetectCampaign): ?>
                                <option value="">Select traffic source...</option>
                                <?php endif; ?>
                                <?php foreach ($trafficSources as $ts):
                                    $isSelectable = TrafficSourceReleaseHelper::isSelectableForRelease($ts);
                                    $isFacebook = stripos($ts['name'], 'facebook') !== false;
                                    $isGoogle = TrafficSourceReleaseHelper::usesGoogleAdsIntegration($ts);
                                    $providerKey = trim((string) ($ts['provider_key'] ?? ''));
                                    $isSelected = ($editCampaign['traffic_source_id'] ?? 0) == $ts['id']
                                        || ($action === 'add' && $firstSelectableTrafficSource && (int)$firstSelectableTrafficSource['id'] === (int)$ts['id']);
                                ?>
                                    <option value="<?= $ts['id'] ?>"
                                            data-tokens='<?= htmlspecialchars(json_encode($ts['tokens_json'] ?? [])) ?>'
                                            data-cost-param='<?= htmlspecialchars($ts['cost_param_key'] ?? '') ?>'
                                            data-is-facebook="<?= $isFacebook ? '1' : '0' ?>"
                                            data-is-google="<?= $isGoogle ? '1' : '0' ?>"
                                            data-provider-key="<?= htmlspecialchars($providerKey) ?>"
                                            <?= !$isSelectable ? ' disabled' : '' ?>
                                            <?= $isSelected ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ts['name']) ?><?= !$isSelectable ? ' (Coming soon)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="campaign-form-hint">
                        Facebook, Google Ads, YouTube, or a custom source with manual cost in the URL.
                        Google/YouTube conversions use scheduled CSV import (Settings → Integrations). API cost sync is optional.
                    </div>
                </div>
                    <?php
                    $sectionId = 'campaign-form-section-campaign';
                    $sectionTitle = 'Campaign';
                    $sectionSubtitle = 'Basic campaign settings and traffic source selection.';
                    $sectionBodyHtml = ob_get_clean();
                    $sectionOpen = true;
                    $sectionExtraClass = '';
                    $sectionExtraAttrs = '';
                    include __DIR__ . '/campaign-form-section.php';

                    // --- Section 2: Tracking ---
                    ob_start();
                    $editCampaignSafe = is_array($editCampaign) ? $editCampaign : [];
                    $editEdgeEnabled = !empty($editCampaignSafe['edge_enabled']);
                    $edgeEligibility = \SimpleKuma\Edge\EdgeEligibility::evaluate(array_merge($editCampaignSafe, [
                        'edge_enabled' => true,
                        'status' => $editCampaignSafe['status'] ?? 'active',
                        'referrer_mode' => $editReferrerMode,
                        'redirectless_tracking' => !empty($editCampaignSafe['redirectless_tracking']),
                    ]));
                    $edgeSyncedAt = $editCampaignSafe['edge_synced_at'] ?? null;
                    $edgeSyncError = $editCampaignSafe['edge_sync_error'] ?? null;
                    ?>
                        <div class="campaign-form-grid campaign-form-grid--tracking">
                            <div class="campaign-form-tracking-main">
                                <div class="campaign-form-field">
                                    <label class="campaign-form-label">Tracking Domain (Optional)</label>
                                    <select name="tracking_domain_id"
                                            id="campaign-tracking-domain-select"
                                            onchange="updateChompJSCode()"
                                            class="campaign-form-input">
                                        <option value="" data-domain-url="<?= htmlspecialchars(BASE_URL) ?>">Use Main Tracker Domain (<?= parse_url(BASE_URL, PHP_URL_HOST) ?>)</option>
                                        <?php if (!empty($verifiedTrackingDomains)): ?>
                                            <option value="">─────────────────────────</option>
                                            <?php foreach ($verifiedTrackingDomains as $domain): ?>
                                                <?php $domainStatusLabel = ($domain['status'] ?? '') === 'verified_manual' ? ' (Manual)' : ''; ?>
                                                <option value="<?= $domain['id'] ?>"
                                                        data-domain-url="<?= htmlspecialchars($domain['domain']) ?>"
                                                        <?= ($editCampaign['tracking_domain_id'] ?? null) == $domain['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($domain['domain']) ?><?= $domainStatusLabel ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <div class="campaign-form-hint">
                                        Select a custom tracking domain to use for this campaign's tracking links. Only verified domains are shown.
                                        <a href="?page=settings&tab=domains" target="_blank" class="campaign-form-link">Manage domains</a>
                                    </div>
                                </div>

                                <div class="campaign-form-panel">
                                    <label class="campaign-form-label">Minimum payout to fire postbacks (optional)</label>
                                    <?php
                                    $editMinPostbackPayout = $editCampaign['min_postback_payout'] ?? null;
                                    $editMinPostbackPayoutValue = ($editMinPostbackPayout === null || $editMinPostbackPayout === '')
                                        ? ''
                                        : rtrim(rtrim(number_format((float)$editMinPostbackPayout, 6, '.', ''), '0'), '.');
                                    ?>
                                    <input type="number" name="min_postback_payout" step="any" min="0"
                                           value="<?= htmlspecialchars($editMinPostbackPayoutValue) ?>"
                                           placeholder="No minimum — fire all postbacks"
                                           class="campaign-form-input campaign-form-input--narrow">
                                    <p class="campaign-form-hint">
                                        Optional. All conversions always appear in Kuma. When set, outbound postbacks only fire when value or payout meets this minimum.
                                    </p>
                                </div>
                            </div>

                            <div class="campaign-form-tracking-flags">
                                <div class="campaign-edge-redirect-box campaign-form-panel">
                                    <label class="campaign-form-check">
                                        <input type="checkbox" name="edge_enabled" value="1" <?= $editEdgeEnabled ? 'checked' : '' ?>>
                                        <span>
                                            <strong class="edge-box-title">Edge redirect (Cloudflare Worker)</strong>
                                            <span class="edge-box-desc">
                                                Serve redirects from Cloudflare’s edge for much lower latency worldwide.
                                                Requires Edge Redirect setup under Settings. Phase 1 supports standard 302 only (no referrer privacy modes).
                                                Offer and landing-page weight changes sync to the edge after save; propagation is usually within about a minute (Cloudflare KV). Origin links update immediately.
                                            </span>
                                        </span>
                                    </label>
                                    <?php if ($action === 'edit' && $editEdgeEnabled): ?>
                                        <div class="edge-box-status">
                                            <?php if (!$edgeEligibility['eligible']): ?>
                                                <div class="edge-status-ineligible">Not eligible while enabled: <?= htmlspecialchars((string) $edgeEligibility['reason']) ?></div>
                                            <?php elseif ($edgeSyncError): ?>
                                                <div class="edge-status-error">Last sync error: <?= htmlspecialchars((string) $edgeSyncError) ?></div>
                                            <?php elseif ($edgeSyncedAt): ?>
                                                <div class="edge-status-synced">Last synced to edge: <?= htmlspecialchars((string) $edgeSyncedAt) ?> UTC</div>
                                            <?php else: ?>
                                                <div class="edge-status-waiting">Waiting for first edge sync…</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="campaign-form-hint">
                                        <a href="?page=settings&tab=edge-redirect" class="edge-box-link">Configure Edge Redirect →</a>
                                    </div>
                                </div>

                                <div class="campaign-form-panel">
                                    <label class="campaign-form-check">
                                        <input type="checkbox" name="allow_multiple_conversions" value="1"
                                               <?= !empty($editCampaign['allow_multiple_conversions']) ? 'checked' : '' ?>>
                                        <span>
                                            <span class="campaign-form-check-title">Allow multiple conversions on the same click</span>
                                            <span class="campaign-form-hint">
                                                For networks like Propush that can send several payouts on one click ID.
                                                Prefer a unique <code>txid</code> when the network provides one. Same <code>txid</code>/<code>event_id</code> is still treated as a duplicate.
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    <?php
                    $sectionId = 'campaign-form-section-tracking';
                    $sectionTitle = 'Tracking';
                    $sectionSubtitle = 'Configure how this campaign tracks and handles conversions.';
                    $sectionBodyHtml = ob_get_clean();
                    $sectionOpen = true;
                    $sectionExtraClass = '';
                    $sectionExtraAttrs = '';
                    include __DIR__ . '/campaign-form-section.php';

                    // --- Section 3: Traffic Source Integration ---
                    ob_start();
                    ?>
                        <div id="ts_integration_empty" class="campaign-form-empty" style="display: none;">
                            No first-party Meta or Google Ads integration for this traffic source.
                            Honeycomb addons (if any) appear in the Honeycomb section below.
                        </div>

                        <!-- Facebook integrations (only visible when Facebook is selected) -->
                        <div id="facebook_integration_field" style="margin-bottom: 24px; display: none;">
                            <div class="campaign-form-grid campaign-form-grid--2 campaign-form-meta-peers">
                                <div class="campaign-form-meta-col">
                                    <div class="campaign-form-meta-heading">Conversion reporting</div>
                                    <label class="campaign-form-label" for="facebook_capi_integration_id">Meta Conversions API (CAPI)</label>
                                    <select name="facebook_capi_integration_id" id="facebook_capi_integration_id"
                                            class="campaign-form-input">
                                        <option value="">No CAPI integration</option>
                                        <?php foreach ($facebookIntegrations as $fbIntegration): ?>
                                            <option value="<?= $fbIntegration['id'] ?>"
                                                    <?= ($editCampaign['facebook_capi_integration_id'] ?? null) == $fbIntegration['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($fbIntegration['name']) ?> (<?= htmlspecialchars($fbIntegration['pixel_id']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="campaign-form-hint">
                                        Optional. Sends conversions to Meta.
                                        <a href="?page=settings&tab=integrations" target="_blank" class="campaign-form-link">Manage integrations</a>
                                    </div>
                                </div>

                                <div class="campaign-form-meta-col">
                                    <div class="campaign-form-meta-heading">Cost tracking</div>
                                    <label class="campaign-form-label" for="facebook_marketing_ad_account_id">Ad account</label>
                                    <select name="facebook_marketing_ad_account_id" id="facebook_marketing_ad_account_id"
                                            class="campaign-form-input">
                                        <option value="">No ad account</option>
                                        <?php foreach ($allFacebookAdAccounts as $adAccount): ?>
                                            <option value="<?= $adAccount['id'] ?>"
                                                    <?= ($editCampaign['facebook_marketing_ad_account_id'] ?? null) == $adAccount['id'] ? 'selected' : '' ?>
                                                    <?= ($adAccount['integration_status'] ?? 'active') !== 'active' ? 'style="color: #999;"' : '' ?>>
                                                <?= htmlspecialchars($adAccount['ad_account_name']) ?>
                                                <?= !empty($adAccount['ad_account_id']) ? ' (' . htmlspecialchars($adAccount['ad_account_id']) . ')' : '' ?>
                                                <?= !empty($adAccount['currency']) ? ' - ' . htmlspecialchars($adAccount['currency']) : '' ?>
                                                <?= !empty($adAccount['integration_name']) ? ' [' . htmlspecialchars($adAccount['integration_name']) . ']' : '' ?>
                                                <?= ($adAccount['integration_status'] ?? 'active') !== 'active' ? ' [Integration Paused]' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="campaign-form-hint">
                                        Which Meta ad account to pull spend from.
                                        <a href="?page=settings&tab=api-costs" target="_blank" class="campaign-form-link">Manage integrations</a>
                                    </div>
                                </div>
                            </div>

                            <div id="facebook_meta_campaign_field" class="campaign-form-meta-campaign">
                                <div class="campaign-form-info">
                                    <strong>Meta campaign for cost tracking</strong><br>
                                    Pick the Meta campaign whose ad spend should land in reports.
                                    Choose an ad account above, click <strong>Refresh</strong>, then select the matching campaign.
                                    Optional — leave blank to infer costs from clicks only (slower, less accurate on large ad accounts).
                                </div>
                                <label class="campaign-form-label" for="facebook_marketing_campaign_id">Meta campaign (optional)</label>
                                <div class="campaign-form-meta-campaign-row">
                                    <select name="facebook_marketing_campaign_id" id="facebook_marketing_campaign_id"
                                            class="campaign-form-input" disabled>
                                        <option value="">Select ad account first</option>
                                    </select>
                                    <button type="button" id="fb_refresh_meta_campaigns_btn" class="btn btn-secondary campaign-form-meta-refresh">
                                        Refresh
                                    </button>
                                </div>
                                <span id="fb_meta_campaign_status" class="campaign-form-hint"></span>
                                <p class="campaign-form-hint">
                                    Only <strong>ACTIVE</strong> campaigns are listed.
                                    <a href="?page=settings&tab=api-costs" target="_blank" class="campaign-form-link">Manage ad accounts</a>
                                </p>
                            </div>
                        </div>

                        <!-- Google Ads Integration Dropdown (only when Google/YouTube is selected) -->
                        <div id="google_ads_integration_field" style="margin-bottom: 24px; display: none;">
                            <label class="campaign-form-label">Google Ads Integration (Optional)</label>
                            <select name="google_ads_integration_id" id="google_ads_integration_id"
                                    class="campaign-form-input">
                                <option value="">No Google Ads Integration</option>
                                <?php foreach ($googleAdsIntegrations as $gaIntegration): ?>
                                    <option value="<?= (int)$gaIntegration['id'] ?>"
                                            <?= ($editCampaign['google_ads_integration_id'] ?? null) == $gaIntegration['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($gaIntegration['name']) ?>
                                        <?php if (!empty($gaIntegration['customer_id'])): ?>
                                            (<?= htmlspecialchars($gaIntegration['customer_id']) ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="campaign-form-hint">
                                Optional. Link an integration for CSV/Data Manager import and/or API conversion upload. Cost sync uses the Google Ads API cost cron when credentials are configured.
                                <a href="?page=settings&tab=api-costs" target="_blank" class="campaign-form-link">Manage integrations</a>
                            </div>
                        </div>
                    <?php
                    $sectionId = 'campaign-form-section-ts-integration';
                    $sectionTitle = 'Traffic Source Integration';
                    $sectionSubtitle = 'First-party Meta and Google Ads settings for the selected traffic source.';
                    $sectionBodyHtml = ob_get_clean();
                    $sectionOpen = true;
                    $sectionExtraClass = '';
                    $sectionExtraAttrs = '';
                    include __DIR__ . '/campaign-form-section.php';

                    // --- Section 4: Honeycomb ---
                    if ($honeycombCampaignFieldProviders !== []):
                        ob_start();
                        $honeycombPreferExportOnCreate = false;
                        $honeycombFieldPost = [];
                        include __DIR__ . '/campaign-form-honeycomb-fields.php';
                        $sectionId = 'campaign-form-section-honeycomb';
                        $sectionTitle = 'Honeycomb';
                        $sectionSubtitle = 'Addon-specific campaign fields. Traffic-source addons appear when that source is selected; utility addons (e.g. Ringba) can appear for any source.';
                        $sectionBodyHtml = ob_get_clean();
                        $sectionOpen = true;
                        $sectionExtraClass = '';
                        $sectionExtraAttrs = 'style="display:none;"';
                        include __DIR__ . '/campaign-form-section.php';
                    endif;

                    // Legacy auto-detect postbacks block (kept hidden)
                    ?>
                        <div id="traffic_source_postbacks_section" style="margin-bottom: 24px; display: none;">
                            <div style="background: #fff3e0; border: 2px solid #ff9800; border-radius: 6px; padding: 16px;">
                                <h4 style="margin: 0 0 12px 0; color: #e65100; font-size: 16px;">
                                    Integration Selection for Auto-Detect Campaign
                                </h4>
                                <p style="margin: 0 0 16px 0; color: #666; font-size: 12px; line-height: 1.5;">
                                    Select which integrations to use for each traffic source type. When a conversion occurs, Kuma will automatically use the correct integration based on the detected traffic source.
                                </p>
                                <?php
                                $existingTsPostbacks = [];
                                if ($action === 'edit' && $editCampaign && !empty($editCampaign['traffic_source_postbacks_json'])) {
                                    $existingTsPostbacks = is_array($editCampaign['traffic_source_postbacks_json'])
                                        ? $editCampaign['traffic_source_postbacks_json']
                                        : json_decode($editCampaign['traffic_source_postbacks_json'], true) ?? [];
                                }
                                $trafficSourceGroups = [];
                                foreach ($trafficSources as $ts) {
                                    if (empty($ts['id'])) continue;
                                    $name = strtolower($ts['name'] ?? '');
                                    $group = 'other';
                                    if (strpos($name, 'facebook') !== false) $group = 'facebook';
                                    elseif (strpos($name, 'google') !== false && strpos($name, 'youtube') === false) $group = 'google';
                                    elseif (strpos($name, 'youtube') !== false) $group = 'youtube';
                                    elseif (strpos($name, 'bing') !== false) $group = 'bing';
                                    if (!isset($trafficSourceGroups[$group])) {
                                        $trafficSourceGroups[$group] = [];
                                    }
                                    $trafficSourceGroups[$group][] = $ts;
                                }
                                $integrationGroups = [
                                    'facebook' => ['label' => 'Facebook', 'integrations' => $facebookIntegrations, 'type' => 'facebook_capi_integration_id'],
                                    'google' => ['label' => 'Google Ads', 'integrations' => $googleAdsIntegrations, 'type' => 'google_ads_integration_id'],
                                    'youtube' => ['label' => 'YouTube', 'integrations' => $googleAdsIntegrations, 'type' => 'google_ads_integration_id'],
                                    'bing' => ['label' => 'Bing', 'integrations' => [], 'type' => null],
                                ];
                                foreach ($integrationGroups as $groupKey => $groupConfig):
                                    if (empty($groupConfig['integrations']) && $groupConfig['type'] !== null) continue;
                                    $groupTrafficSources = $trafficSourceGroups[$groupKey] ?? [];
                                    if (empty($groupTrafficSources)) continue;
                                    $firstTsId = $groupTrafficSources[0]['id'];
                                    $tsConfig = $existingTsPostbacks[$firstTsId] ?? [];
                                ?>
                                <div style="margin-bottom: 12px;">
                                    <label style="display: block; font-weight: 600; margin-bottom: 6px; color: #333; font-size: 13px;">
                                        <?= htmlspecialchars($groupConfig['label']) ?> Integration
                                    </label>
                                    <?php if ($groupConfig['type'] === 'facebook_capi_integration_id'): ?>
                                        <select name="traffic_source_postbacks[<?= $firstTsId ?>][facebook_capi_integration_id]"
                                                style="width: 100%; max-width: 400px; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;">
                                            <option value="">None</option>
                                            <?php foreach ($groupConfig['integrations'] as $integration): ?>
                                                <option value="<?= $integration['id'] ?>"
                                                        <?= ($tsConfig['facebook_capi_integration_id'] ?? null) == $integration['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($integration['name']) ?> (<?= htmlspecialchars($integration['pixel_id']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($groupConfig['type'] === 'google_ads_integration_id'): ?>
                                        <select name="traffic_source_postbacks[<?= $firstTsId ?>][google_ads_integration_id]"
                                                style="width: 100%; max-width: 400px; padding: 8px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;">
                                            <option value="">None</option>
                                            <?php foreach ($groupConfig['integrations'] as $integration): ?>
                                                <option value="<?= $integration['id'] ?>"
                                                        <?= ($tsConfig['google_ads_integration_id'] ?? null) == $integration['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($integration['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php
                    // --- Section 5: Postbacks ---
                    ob_start();
                    ?>
                        <div id="custom_postbacks_section">
                            <label class="campaign-form-label">Custom Postbacks (Optional)</label>
                            <?php if (!empty($allCustomPostbacks)): ?>
                            <div class="custom-postbacks-container campaign-form-checklist">
                                <?php foreach ($allCustomPostbacks as $postback): ?>
                                    <label class="custom-postback-item campaign-form-check-item">
                                        <input type="checkbox"
                                               name="custom_postback_ids[]"
                                               value="<?= $postback['id'] ?>"
                                               <?= in_array($postback['id'], $selectedCustomPostbackIds) ? 'checked' : '' ?>>
                                        <div class="custom-postback-content">
                                            <div class="campaign-form-check-title">
                                                <?= htmlspecialchars($postback['name']) ?>
                                            </div>
                                            <?php if (!empty($postback['description'])): ?>
                                                <div class="campaign-form-hint">
                                                    <?= htmlspecialchars($postback['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="campaign-form-hint">
                                Select one or more postbacks to fire when conversions occur for this campaign (any traffic source).
                                <a href="?page=settings&tab=integrations" target="_blank" class="campaign-form-link">Manage postbacks</a>
                            </div>
                            <?php else: ?>
                            <div class="campaign-form-empty campaign-form-empty--dashed">
                                No custom postbacks yet. Create outbound postback URLs under
                                <a href="?page=settings&tab=integrations" target="_blank" class="campaign-form-link">Settings → Integrations</a>,
                                then attach them here — they work for every traffic source (including PropellerAds).
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php
                    $sectionId = 'campaign-form-section-postbacks';
                    $sectionTitle = 'Postbacks & Conversion Delivery';
                    $sectionSubtitle = 'Choose which postbacks to fire when conversions occur.';
                    $sectionBodyHtml = ob_get_clean();
                    $sectionOpen = true;
                    $sectionExtraClass = '';
                    $sectionExtraAttrs = '';
                    include __DIR__ . '/campaign-form-section.php';
                    ?>
                </div>

