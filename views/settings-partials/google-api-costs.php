<?php
/** Google Ads API cost integrations (Settings → API Cost Updates). */
$gaCost = $editingGoogleAdsCostIntegration ?? [];
?>
    <!-- Google Ads API Cost Integrations -->
    <div class="card api-integrations-card" id="ga-cost-integrations-list" style="margin-top: 24px;">
        <div class="card-header api-integrations-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <h2 class="card-title" style="margin: 0;">Google Ads API Integrations</h2>
            <a href="?page=settings&tab=api-costs&edit_ga_cost_integration=0" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; color: #fff; white-space: nowrap;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/tgoogle.png" alt="" style="width: 24px; height: 24px;">
                + Add Integration
            </a>
        </div>
        <div class="card-body api-integrations-body">
            <div style="background: #e3f2fd; border-left: 4px solid #2196F3; padding: 12px; border-radius: 4px; margin-bottom: 16px;">
                <p style="margin: 0; font-size: 13px; color: #1565c0; line-height: 1.6;">
                    Pulls campaign spend from the Google Ads API (<code>metrics.cost_micros</code>) hourly.
                    Ensure traffic sources capture <strong>{CampaignId}</strong> as <code>campid</code> so costs match clicks.
                    Conversion reporting stays on <strong>CSV / Data Manager</strong> under
                    <a href="?page=settings&tab=integrations" style="color: #3d5a26; font-weight: 600;">Integrations</a>
                    (same integration row; a conversion key is auto-created when you add cost credentials here).
                </p>
            </div>

            <?php if (isset($errors['ga_cost_integration']) && is_array($errors['ga_cost_integration'])): ?>
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
                    <strong>Errors:</strong>
                    <ul style="margin: 8px 0 0 20px;">
                        <?php foreach ($errors['ga_cost_integration'] as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($allGoogleAdsIntegrations)): ?>
                <div style="text-align: center; padding: 40px; color: #999;">
                    <p>No Google Ads cost integrations configured yet.</p>
                    <p style="font-size: 14px; margin-top: 8px; color: #666;">
                        You need a developer token, OAuth credentials, and the Google Ads customer ID (no dashes).
                    </p>
                    <a href="?page=settings&tab=api-costs&edit_ga_cost_integration=0" class="btn btn-primary" style="margin-top: 16px; display: inline-flex; align-items: center; gap: 6px; color: #fff;">
                        <img src="<?= ASSETS_BASE_URL ?>/assets/images/tgoogle.png" alt="" style="width: 24px; height: 24px;">
                        Create Your First Integration
                    </a>
                </div>
            <?php else: ?>
                <div class="api-integrations-table" style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Name</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Customer ID</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Cost API</th>
                                <th style="padding: 12px; text-align: left; font-weight: 600;">Status</th>
                                <th style="padding: 12px; text-align: right; font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allGoogleAdsIntegrations as $integration):
                                $costReady = $googleAds->isCostTrackingConfigured($integration);
                            ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 12px;"><?= htmlspecialchars($integration['name']) ?></td>
                                    <td style="padding: 12px; font-family: monospace; font-size: 13px;">
                                        <?= !empty($integration['customer_id']) ? htmlspecialchars($integration['customer_id']) : '<span style="color:#999;">—</span>' ?>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span style="display: inline-block; padding: 4px 10px; background: <?= $costReady ? '#d4edda' : '#fff3cd' ?>; color: <?= $costReady ? '#155724' : '#856404' ?>; border-radius: 12px; font-size: 12px; font-weight: 500;">
                                            <?= $costReady ? 'Ready' : 'Incomplete' ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span style="display: inline-block; padding: 4px 10px; background: <?= ($integration['status'] ?? 'active') === 'active' ? '#d4edda' : '#fff3cd' ?>; color: <?= ($integration['status'] ?? 'active') === 'active' ? '#155724' : '#856404' ?>; border-radius: 12px; font-size: 12px; font-weight: 500; text-transform: capitalize;">
                                            <?= htmlspecialchars($integration['status'] ?? 'active') ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px; text-align: right;">
                                        <a href="?page=settings&tab=api-costs&edit_ga_cost_integration=<?= (int)$integration['id'] ?>"
                                           style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; margin-right: 6px;"
                                           title="Edit">✏️</a>
                                        <form method="post" style="display: inline; margin: 0;"
                                              onsubmit="return confirm('Delete this Google Ads integration? Hourly cost data and the conversion CSV key for this integration will also be removed.');">
                                            <input type="hidden" name="action" value="delete_ga_cost_integration">
                                            <input type="hidden" name="ga_cost_integration_id" value="<?= (int)$integration['id'] ?>">
                                            <button type="submit" style="width: 36px; height: 36px; padding: 0; border: 1px solid #ddd; border-radius: 6px; background: #fff; cursor: pointer;" title="Delete">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($editingGoogleAdsCostIntegration !== null || isset($_GET['edit_ga_cost_integration'])): ?>
        <div class="card" style="margin-top: 24px;">
            <div class="card-header">
                <h2 class="card-title"><?= !empty($gaCost['id']) ? 'Edit' : 'Add' ?> Google Ads Cost Integration</h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?= !empty($gaCost['id']) ? 'update_ga_cost_integration' : 'create_ga_cost_integration' ?>">
                    <?php if (!empty($gaCost['id'])): ?>
                        <input type="hidden" name="ga_cost_integration_id" value="<?= (int)$gaCost['id'] ?>">
                    <?php endif; ?>

                    <div style="max-width: 640px;">
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Integration Name <span style="color: #d32f2f;">*</span></label>
                            <input type="text" name="ga_cost_integration_name" required
                                   value="<?= htmlspecialchars($gaCost['name'] ?? '') ?>"
                                   placeholder="e.g., Main Google Ads Account"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Google Ads Customer ID <span style="color: #d32f2f;">*</span></label>
                            <input type="text" name="ga_cost_customer_id" required
                                   value="<?= htmlspecialchars($gaCost['customer_id'] ?? '') ?>"
                                   placeholder="1234567890 (no dashes)"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Developer Token <?= empty($gaCost['id']) ? '<span style="color: #d32f2f;">*</span>' : '' ?></label>
                            <input type="password" name="ga_cost_developer_token" autocomplete="off"
                                   <?= empty($gaCost['id']) ? 'required' : '' ?>
                                   value=""
                                   placeholder="<?= !empty($gaCost['developer_token']) ? 'Leave blank to keep existing' : 'Paste developer token' ?>"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">OAuth Client ID <span style="color: #d32f2f;">*</span></label>
                            <input type="text" name="ga_cost_oauth_client_id" required
                                   value="<?= htmlspecialchars($gaCost['oauth_client_id'] ?? '') ?>"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">OAuth Client Secret <?= empty($gaCost['id']) ? '<span style="color: #d32f2f;">*</span>' : '' ?></label>
                            <input type="password" name="ga_cost_oauth_client_secret" autocomplete="new-password" <?= empty($gaCost['id']) ? 'required' : '' ?>
                                   value=""
                                   placeholder="<?= !empty($gaCost['oauth_client_secret']) ? 'Leave blank to keep current secret' : 'Paste client secret' ?>"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">OAuth Refresh Token <?= empty($gaCost['id']) ? '<span style="color: #d32f2f;">*</span>' : '' ?></label>
                            <input type="password" name="ga_cost_oauth_refresh_token" autocomplete="off"
                                   <?= empty($gaCost['id']) ? 'required' : '' ?>
                                   value=""
                                   placeholder="<?= !empty($gaCost['oauth_refresh_token']) ? 'Leave blank to keep existing' : 'Paste refresh token' ?>"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Login Customer ID (MCC, optional)</label>
                            <input type="text" name="ga_cost_login_customer_id"
                                   value="<?= htmlspecialchars($gaCost['login_customer_id'] ?? '') ?>"
                                   placeholder="Manager account ID if accessing client accounts"
                                   style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px; font-family: monospace;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Status</label>
                            <select name="ga_cost_status" style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 4px;">
                                <option value="active" <?= ($gaCost['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($gaCost['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>

                        <div style="display: flex; gap: 12px;">
                            <button type="submit" class="btn btn-primary"><?= !empty($gaCost['id']) ? 'Update' : 'Create' ?> Integration</button>
                            <a href="?page=settings&tab=api-costs" class="btn btn-secondary">Cancel</a>
                        </div>

                        <div style="margin-top: 24px; padding: 16px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 6px;">
                            <h3 style="margin: 0 0 12px 0; font-size: 16px; color: #856404;">Cron job (hourly)</h3>
                            <code style="display: block; padding: 8px; background: #fff; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; word-break: break-all;">
                                0 * * * * php <?= htmlspecialchars(str_replace('\\', '/', ROOT_PATH)) ?>/scripts/google_ads_cost_updater.php >> <?= htmlspecialchars(str_replace('\\', '/', ROOT_PATH)) ?>/storage/logs/google_ads_cost_updater.log 2>&amp;1
                            </code>
                            <p style="margin: 12px 0 0; font-size: 12px; color: #856404;">
                                Run separately from the Facebook cost updater. Requires <code>googleads/google-ads-php</code> (included when you run <code>composer install</code>).
                            </p>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
