<?php
/** NewsBreak API cost integrations (Settings → API Cost Updates). */
$nbEdit = $editingNewsBreakIntegration ?? null;
$nbList = $allNewsBreakIntegrations ?? [];
?>
    <div class="card api-integrations-card" id="newsbreak-cost-integrations-list" style="margin-top: 24px;">
        <div class="card-header api-integrations-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <h2 class="card-title" style="margin: 0;">NewsBreak API Integrations</h2>
            <a href="?page=settings&tab=api-costs&edit_nb_integration=0" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; color: #fff; white-space: nowrap;">
                + Add Integration
            </a>
        </div>
        <div class="card-body api-integrations-body">
            <div style="background: #e3f2fd; border-left: 4px solid #2196F3; padding: 12px; border-radius: 4px; margin-bottom: 16px;">
                <p style="margin: 0; font-size: 13px; color: #1565c0; line-height: 1.6;">
                    Pulls campaign spend hourly from NewsBreak <code>getIntegratedReport</code> (cost is converted from cents).
                    Create a NewsBreak traffic source from template so clicks store <code>__CAMPAIGN_ID__</code> and
                    <code>__CALLBACK_PARAM__</code>. Generate an Access Token in NewsBreak Ad Manager → Resources → API Access Tokens.
                    Conversion postbacks use the traffic source postback URL (edit <code>event_type</code> to match Event Management).
                </p>
            </div>

            <?php if (isset($errors['nb_integration']) && is_array($errors['nb_integration'])): ?>
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
                    <strong>Errors:</strong>
                    <ul style="margin: 8px 0 0 20px;">
                        <?php foreach ($errors['nb_integration'] as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($nbList)): ?>
                <div style="text-align: center; padding: 40px; color: #999;">
                    <p>No NewsBreak integrations yet.</p>
                    <a href="?page=settings&tab=api-costs&edit_nb_integration=0" class="btn btn-primary" style="margin-top: 16px; color: #fff;">
                        Create Your First Integration
                    </a>
                </div>
            <?php else: ?>
                <div class="api-integrations-table" style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                                <th style="padding: 12px; text-align: left;">Name</th>
                                <th style="padding: 12px; text-align: left;">Timezone</th>
                                <th style="padding: 12px; text-align: left;">Ad account filter</th>
                                <th style="padding: 12px; text-align: left;">Status</th>
                                <th style="padding: 12px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($nbList as $integration): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 12px;"><?= htmlspecialchars((string) $integration['name']) ?></td>
                                    <td style="padding: 12px; font-family: monospace; font-size: 13px;">
                                        <?= htmlspecialchars((string) ($integration['timezone'] ?? 'UTC')) ?>
                                    </td>
                                    <td style="padding: 12px; font-family: monospace; font-size: 13px;">
                                        <?= !empty($integration['ad_account_id'])
                                            ? htmlspecialchars((string) $integration['ad_account_id'])
                                            : '<span style="color:#999;">All</span>' ?>
                                    </td>
                                    <td style="padding: 12px; text-transform: capitalize;">
                                        <?= htmlspecialchars((string) ($integration['status'] ?? 'active')) ?>
                                    </td>
                                    <td style="padding: 12px; text-align: right;">
                                        <a href="?page=settings&tab=api-costs&edit_nb_integration=<?= (int) $integration['id'] ?>"
                                           class="btn btn-secondary" style="padding: 6px 10px; text-decoration: none;">Edit</a>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Delete this NewsBreak integration?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                                            <input type="hidden" name="action" value="delete_nb_integration">
                                            <input type="hidden" name="nb_integration_id" value="<?= (int) $integration['id'] ?>">
                                            <button type="submit" class="btn btn-secondary" style="padding: 6px 10px;">Delete</button>
                                        </form>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                                            <input type="hidden" name="action" value="test_nb_integration">
                                            <input type="hidden" name="nb_integration_id" value="<?= (int) $integration['id'] ?>">
                                            <button type="submit" class="btn btn-secondary" style="padding: 6px 10px;">Test</button>
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

    <?php if ($nbEdit !== null || isset($_GET['edit_nb_integration'])): ?>
        <div class="card" style="margin-top: 24px;" id="newsbreak-cost-editor">
            <div class="card-header">
                <h2 class="card-title"><?= is_array($nbEdit) && !empty($nbEdit['id']) ? 'Edit' : 'Add' ?> NewsBreak Integration</h2>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="<?= is_array($nbEdit) && !empty($nbEdit['id']) ? 'update_nb_integration' : 'create_nb_integration' ?>">
                    <?php if (is_array($nbEdit) && !empty($nbEdit['id'])): ?>
                        <input type="hidden" name="nb_integration_id" value="<?= (int) $nbEdit['id'] ?>">
                    <?php endif; ?>

                    <div style="max-width: 560px;">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Name</label>
                            <input type="text" name="nb_name" required
                                   value="<?= htmlspecialchars((string) ($nbEdit['name'] ?? 'NewsBreak')) ?>"
                                   style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                                Access Token <?php if (empty($nbEdit['id'])): ?><span style="color:#d32f2f;">*</span><?php endif; ?>
                            </label>
                            <input type="password" name="nb_access_token" autocomplete="new-password"
                                   placeholder="<?= !empty($nbEdit['id']) ? 'Leave blank to keep the saved token' : 'Paste Access Token from Ad Manager' ?>"
                                   <?= empty($nbEdit['id']) ? 'required' : '' ?>
                                   style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Report timezone</label>
                            <input type="text" name="nb_timezone"
                                   value="<?= htmlspecialchars((string) ($nbEdit['timezone'] ?? 'America/Los_Angeles')) ?>"
                                   placeholder="America/Los_Angeles"
                                   style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                            <div style="font-size: 12px; color: #666; margin-top: 6px;">Passed to NewsBreak as the report timezone (default in their docs is PDT).</div>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Ad account ID (optional)</label>
                            <input type="text" name="nb_ad_account_id"
                                   value="<?= htmlspecialchars((string) ($nbEdit['ad_account_id'] ?? '')) ?>"
                                   placeholder="Leave blank for all accounts on this token"
                                   style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 8px;">Status</label>
                            <select name="nb_status" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                <?php $st = (string) ($nbEdit['status'] ?? 'active'); ?>
                                <option value="active" <?= $st === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="paused" <?= $st === 'paused' ? 'selected' : '' ?>>Paused</option>
                            </select>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary" style="color: #fff;">Save</button>
                            <a href="?page=settings&tab=api-costs" class="btn btn-secondary" style="text-decoration: none;">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
