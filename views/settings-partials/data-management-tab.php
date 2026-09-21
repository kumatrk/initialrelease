<?php
/**
 * Settings → Data Management tab (Click / Conversion action cards)
 * Expects: $allCampaignsForData
 */

$postAction = $_POST['action'] ?? '';
$dataSection = 'clicks';
$dataPanel = '';

switch ($postAction) {
    case 'delete_clicks_by_campaign':
        $dataPanel = 'delete-campaign';
        break;
    case 'delete_clicks_by_ip':
        $dataPanel = 'delete-ip';
        break;
    case 'hide_ip_from_stats':
    case 'unhide_ip_from_stats':
        $dataPanel = 'hide-ip';
        break;
    case 'delete_clicks_by_subid':
        $dataPanel = 'delete-param';
        break;
    case 'delete_all_clicks':
        $dataPanel = 'delete-all';
        break;
    case 'add_manual_conversions':
        $dataSection = 'conversions';
        $dataPanel = 'add-conversions';
        break;
    case 'delete_conversions_by_campaign':
    case 'delete_conversions_by_clickid':
        $dataSection = 'conversions';
        $dataPanel = 'delete-conversions';
        break;
}

$deleteConvMode = ($postAction === 'delete_conversions_by_clickid') ? 'clickid' : 'campaign';
$addConvMode = (!empty($_POST['bulk_conversions'])) ? 'bulk' : 'single';
$clicksOnList = !($dataSection === 'clicks' && $dataPanel !== '');
$convsOnList = !($dataSection === 'conversions' && $dataPanel !== '');
?>
<link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/settings-data-management.css?v=3">

<div class="card dm-wrap" data-dm-initial-section="<?= htmlspecialchars($dataSection) ?>" data-dm-initial-panel="<?= htmlspecialchars($dataPanel) ?>">
    <div class="card-header">
        <h2 class="card-title">Data Management</h2>
    </div>
    <div class="card-body">
        <div class="dm-subtabs" role="tablist" aria-label="Data management sections">
            <button type="button" class="dm-subtab<?= $dataSection === 'clicks' ? ' is-active' : '' ?>" data-dm-section="clicks" role="tab" aria-selected="<?= $dataSection === 'clicks' ? 'true' : 'false' ?>">
                Click Management
            </button>
            <button type="button" class="dm-subtab<?= $dataSection === 'conversions' ? ' is-active' : '' ?>" data-dm-section="conversions" role="tab" aria-selected="<?= $dataSection === 'conversions' ? 'true' : 'false' ?>">
                Conversion Management
            </button>
        </div>

        <div class="dm-warning" role="status">
            <span aria-hidden="true">⚠️</span>
            <div><strong>Warning:</strong> Deleting data is permanent and cannot be undone. Use with caution.</div>
        </div>

        <!-- Click Management -->
        <div class="dm-section" id="dm-section-clicks" data-dm-section-panel="clicks"<?= $dataSection !== 'clicks' ? ' hidden' : '' ?>>
            <div class="dm-stage" data-dm-stage="clicks">
                <div class="dm-view dm-view-list<?= $clicksOnList ? ' is-active' : '' ?>" data-dm-view="list">
                    <div class="dm-section-head">
                        <h3>Manual Actions</h3>
                        <p>Perform actions on clicks or related data. Choose an action below to get started.</p>
                    </div>
                    <div class="dm-action-list">
                        <button type="button" class="dm-action-card" data-dm-open="delete-campaign">
                            <span class="dm-action-icon dm-action-icon--green" aria-hidden="true">🖱️</span>
                            <span class="dm-action-body">
                                <strong>Delete Clicks by Campaign</strong>
                                <span>Remove all clicks for a campaign (active + archive), including conversions, and reset that campaign’s stats.</span>
                            </span>
                            <span class="dm-action-cta">Delete Clicks →</span>
                        </button>
                        <button type="button" class="dm-action-card" data-dm-open="delete-ip">
                            <span class="dm-action-icon dm-action-icon--purple" aria-hidden="true">IP</span>
                            <span class="dm-action-body">
                                <strong>Delete Clicks by IP</strong>
                                <span>Remove all clicks from a specific IP address (and matching anonymized form if privacy anonymization is on).</span>
                            </span>
                            <span class="dm-action-cta">Delete by IP →</span>
                        </button>
                        <button type="button" class="dm-action-card" data-dm-open="hide-ip">
                            <span class="dm-action-icon dm-action-icon--purple" aria-hidden="true">👁</span>
                            <span class="dm-action-body">
                                <strong>Hide IPs from Stats Views</strong>
                                <span>Omit an IP from dashboard, campaign list, campaign stats, and visitor log. Does not block traffic or delete clicks.</span>
                            </span>
                            <span class="dm-action-cta">Manage hidden IPs →</span>
                        </button>
                        <button type="button" class="dm-action-card" data-dm-open="delete-param">
                            <span class="dm-action-icon dm-action-icon--amber" aria-hidden="true">🏷️</span>
                            <span class="dm-action-body">
                                <strong>Delete Clicks by Parameter</strong>
                                <span>Remove clicks that match a parameter value (e.g. source, subid, custom token).</span>
                            </span>
                            <span class="dm-action-cta">Delete by Parameter →</span>
                        </button>
                        <button type="button" class="dm-action-card is-danger" data-dm-open="delete-all">
                            <span class="dm-action-icon dm-action-icon--red" aria-hidden="true">⚠️</span>
                            <span class="dm-action-body">
                                <strong>Delete ALL Click Data</strong>
                                <span>Permanently wipe every click, conversion, and related aggregate stats in this install.</span>
                            </span>
                            <span class="dm-action-cta">Delete Everything →</span>
                        </button>
                    </div>
                    <p class="dm-tip">Tip: After deletes, check Visitor Log and campaign stats — aggregates are adjusted when clicks are removed.</p>
                </div>

                <div class="dm-view<?= (!$clicksOnList && $dataPanel === 'delete-campaign') ? ' is-active' : '' ?>" data-dm-view="delete-campaign">
                    <div class="dm-panel-card">
                        <div class="dm-panel-toolbar">
                            <button type="button" class="dm-back" data-dm-back aria-label="Back to actions">← Back</button>
                        </div>
                        <h4>Delete Clicks by Campaign</h4>
                        <p class="dm-panel-desc">Removes active and archive clicks for the campaign, associated conversions, and resets that campaign’s click statistics.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="delete_clicks_by_campaign">
                            <div class="dm-row">
                                <div class="dm-field">
                                    <label for="dm-campaign-clicks">Select Campaign</label>
                                    <select id="dm-campaign-clicks" name="campaign_id" required>
                                        <option value="">Choose a campaign...</option>
                                        <?php foreach ($allCampaignsForData as $camp): ?>
                                            <option value="<?= (int)$camp['id'] ?>"><?= htmlspecialchars($camp['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="dm-btn-danger"
                                        onclick="return confirm('Delete ALL clicks for this campaign?\n\nThis cannot be undone.');">
                                    Delete Campaign Clicks
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="dm-view<?= (!$clicksOnList && $dataPanel === 'delete-ip') ? ' is-active' : '' ?>" data-dm-view="delete-ip">
                    <div class="dm-panel-card">
                        <div class="dm-panel-toolbar">
                            <button type="button" class="dm-back" data-dm-back aria-label="Back to actions">← Back</button>
                        </div>
                        <h4>Delete Clicks by IP Address</h4>
                        <p class="dm-panel-desc">
                            Removes matching clicks (active + archive) and their conversions.
                            With IP anonymization enabled, full and anonymized forms are both searched (IPv4 last octet zeroed, e.g. <code>192.168.1.0</code>).
                        </p>
                        <form method="post">
                            <input type="hidden" name="action" value="delete_clicks_by_ip">
                            <div class="dm-row">
                                <div class="dm-field">
                                    <label for="dm-ip">IP Address</label>
                                    <input id="dm-ip" type="text" name="ip_address" required placeholder="e.g., 192.168.1.1 or ::1">
                                </div>
                                <button type="submit" class="dm-btn-danger"
                                        onclick="return confirm('Delete all clicks from this IP address?\n\nThis cannot be undone.');">
                                    Delete IP Clicks
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="dm-view<?= (!$clicksOnList && $dataPanel === 'hide-ip') ? ' is-active' : '' ?>" data-dm-view="hide-ip">
                    <div class="dm-panel-card">
                        <div class="dm-panel-toolbar">
                            <button type="button" class="dm-back" data-dm-back aria-label="Back to actions">← Back</button>
                        </div>
                        <h4>Hide IPs from Stats Views</h4>
                        <p class="dm-panel-desc">
                            Hidden IPs are omitted from dashboard, campaign list, campaign stats, and the visitor log.
                            Clicks stay in the database and tracking links still work — this is not a traffic block.
                            You can also hide an IP from the Visitor Log row action.
                        </p>
                        <form method="post" style="margin-bottom: 1.25rem;">
                            <input type="hidden" name="action" value="hide_ip_from_stats">
                            <div class="dm-row">
                                <div class="dm-field">
                                    <label for="dm-hide-ip">IP Address</label>
                                    <input id="dm-hide-ip" type="text" name="ip_address" required placeholder="e.g., 192.168.1.1">
                                </div>
                                <div class="dm-field">
                                    <label for="dm-hide-ip-note">Note (optional)</label>
                                    <input id="dm-hide-ip-note" type="text" name="note" placeholder="e.g., office VPN">
                                </div>
                                <button type="submit" class="btn btn-primary">Hide from stats</button>
                            </div>
                        </form>
                        <?php
                        $statsHiddenIps = $statsHiddenIps ?? [];
                        if ($statsHiddenIps === []): ?>
                            <p class="dm-panel-desc" style="margin:0;">No IPs are currently hidden from stats.</p>
                        <?php else: ?>
                            <table class="dm-table" style="width:100%; border-collapse: collapse; font-size: 13px;">
                                <thead>
                                    <tr>
                                        <th style="text-align:left; padding:6px 8px; border-bottom:1px solid var(--border-color, #ddd);">IP</th>
                                        <th style="text-align:left; padding:6px 8px; border-bottom:1px solid var(--border-color, #ddd);">Note</th>
                                        <th style="text-align:left; padding:6px 8px; border-bottom:1px solid var(--border-color, #ddd);">Added</th>
                                        <th style="padding:6px 8px; border-bottom:1px solid var(--border-color, #ddd);"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($statsHiddenIps as $hiddenRow): ?>
                                    <tr>
                                        <td style="padding:6px 8px; font-family: monospace;"><?= htmlspecialchars($hiddenRow['ip']) ?></td>
                                        <td style="padding:6px 8px;"><?= htmlspecialchars($hiddenRow['note'] ?? '') ?></td>
                                        <td style="padding:6px 8px;"><?= htmlspecialchars($hiddenRow['created_at'] ?? '') ?></td>
                                        <td style="padding:6px 8px; text-align:right;">
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="unhide_ip_from_stats">
                                                <input type="hidden" name="id" value="<?= (int)$hiddenRow['id'] ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm"
                                                        onclick="return confirm('Show this IP in stats again? Aggregates will be restored.');">
                                                    Unhide
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dm-view<?= (!$clicksOnList && $dataPanel === 'delete-param') ? ' is-active' : '' ?>" data-dm-view="delete-param">
                    <div class="dm-panel-card">
                        <div class="dm-panel-toolbar">
                            <button type="button" class="dm-back" data-dm-back aria-label="Back to actions">← Back</button>
                        </div>
                        <h4>Delete Clicks by Parameter</h4>
                        <p class="dm-panel-desc">Searches all_params, traffic source tokens, and custom tokens. Matching clicks and conversions are removed.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="delete_clicks_by_subid">
                            <div class="dm-row">
                                <div class="dm-field">
                                    <label for="dm-param-name">Parameter Name</label>
                                    <input id="dm-param-name" type="text" name="subid_param" required placeholder="e.g., sub1, source, keyword">
                                </div>
                                <div class="dm-field">
                                    <label for="dm-param-value">Parameter Value</label>
                                    <input id="dm-param-value" type="text" name="subid_value" required placeholder="e.g., test, demo">
                                </div>
                                <button type="submit" class="dm-btn-danger"
                                        onclick="return confirm('Delete all clicks with this parameter value?\n\nThis cannot be undone.');">
                                    Delete Matching Clicks
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="dm-view<?= (!$clicksOnList && $dataPanel === 'delete-all') ? ' is-active' : '' ?>" data-dm-view="delete-all">
                    <div class="dm-panel-card">
                        <div class="dm-panel-toolbar">
                            <button type="button" class="dm-back" data-dm-back aria-label="Back to actions">← Back</button>
                        </div>
                        <h4>Delete ALL Click Data</h4>
                        <div class="dm-danger-box">
                            DANGER: This permanently deletes ALL clicks and conversions and resets statistics for the entire database.
                        </div>
                        <form method="post">
                            <input type="hidden" name="action" value="delete_all_clicks">
                            <button type="submit" class="dm-btn-danger"
                                    onclick="return confirm('FINAL WARNING\n\nDelete ALL click data?\n\n- All clicks\n- All conversions\n- All click statistics\n\nThis CANNOT be undone.') && prompt('Type DELETE to confirm:') === 'DELETE';">
                                Delete ALL Clicks
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Conversion Management -->
        <div class="dm-section" id="dm-section-conversions" data-dm-section-panel="conversions"<?= $dataSection !== 'conversions' ? ' hidden' : '' ?>>
            <div class="dm-stage" data-dm-stage="conversions">
                <div class="dm-view dm-view-list<?= $convsOnList ? ' is-active' : '' ?>" data-dm-view="list">
                    <div class="dm-section-head">
                        <h3>Manual Actions</h3>
                        <p>Add missing conversions or remove test / false-positive conversion records.</p>
                    </div>
                    <div class="dm-action-list">
                        <button type="button" class="dm-action-card" data-dm-open="add-conversions">
                            <span class="dm-action-icon dm-action-icon--teal" aria-hidden="true">➕</span>
                            <span class="dm-action-body">
                                <strong>Add Manual Conversions</strong>
                                <span>Create conversions for existing clicks and fire configured postbacks (traffic source, CAPI, Google, custom).</span>
                            </span>
                            <span class="dm-action-cta">Add Conversions →</span>
                        </button>
                        <button type="button" class="dm-action-card" data-dm-open="delete-conversions">
                            <span class="dm-action-icon dm-action-icon--red" aria-hidden="true">🗑️</span>
                            <span class="dm-action-body">
                                <strong>Delete Conversions</strong>
                                <span>Remove conversion records by campaign or click ID without deleting the underlying clicks.</span>
                            </span>
                            <span class="dm-action-cta">Delete Conversions →</span>
                        </button>
                    </div>
                    <p class="dm-tip">Tip: Manual conversions appear in Conversion Log. Deleting conversions does not remove the original click.</p>
                </div>

                <div class="dm-view<?= (!$convsOnList && $dataPanel === 'add-conversions') ? ' is-active' : '' ?>" data-dm-view="add-conversions">
                    <div class="dm-panel-card">
                        <div class="dm-panel-toolbar">
                            <button type="button" class="dm-back" data-dm-back aria-label="Back to actions">← Back</button>
                        </div>
                        <h4>Add Manual Conversions</h4>
                        <p class="dm-panel-desc">Creates the conversion, updates stats, and fires outbound postbacks based on campaign configuration.</p>

                        <div class="dm-mini-tabs" role="tablist">
                            <button type="button" class="dm-mini-tab<?= $addConvMode === 'single' ? ' is-active' : '' ?>" data-dm-conv-mode="single">Single Entry</button>
                            <button type="button" class="dm-mini-tab<?= $addConvMode === 'bulk' ? ' is-active' : '' ?>" data-dm-conv-mode="bulk">Bulk Entry</button>
                        </div>

                        <form method="post" id="manual-conversion-form">
                            <input type="hidden" name="action" value="add_manual_conversions">
                            <div id="conversion-single-mode"<?= $addConvMode === 'bulk' ? ' style="display:none"' : '' ?>>
                                <div id="conversion-entries">
                                    <div class="conversion-entry dm-conversion-entry">
                                        <div class="dm-field">
                                            <label>Click ID</label>
                                            <input type="text" name="click_id[]"<?= $addConvMode === 'single' ? ' required' : '' ?> placeholder="e.g., abc123-def456-ghi789" style="font-family:monospace;">
                                        </div>
                                        <div class="dm-field">
                                            <label>Revenue</label>
                                            <input type="number" name="revenue[]" step="0.01"<?= $addConvMode === 'single' ? ' required' : '' ?> placeholder="0.00">
                                        </div>
                                        <div class="dm-field">
                                            <label>Currency</label>
                                            <select name="currency[]">
                                                <option value="USD">USD</option>
                                                <option value="EUR">EUR</option>
                                                <option value="GBP">GBP</option>
                                                <option value="CAD">CAD</option>
                                                <option value="AUD">AUD</option>
                                            </select>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-primary" onclick="addConversionEntry()" title="Add another entry" style="padding:10px 16px;">+</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="conversion-bulk-mode"<?= $addConvMode === 'single' ? ' style="display:none"' : '' ?>>
                                <div class="dm-field">
                                    <label for="dm-bulk-conversions">Bulk Entry (Comma-Separated)</label>
                                    <p class="hint">
                                        Format: <code>click_id,revenue</code> (one per line)
                                        <code class="dm-code-sample">abc123-def456-ghi789,25.50<br>xyz789-abc123-def456,15.75</code>
                                    </p>
                                    <textarea id="dm-bulk-conversions" name="bulk_conversions" rows="8" placeholder="click_id,revenue&#10;click_id,revenue&#10;..."></textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary" style="margin-top:8px;">Add Conversions &amp; Fire Postbacks</button>
                        </form>
                    </div>
                </div>

                <div class="dm-view<?= (!$convsOnList && $dataPanel === 'delete-conversions') ? ' is-active' : '' ?>" data-dm-view="delete-conversions">
                    <div class="dm-panel-card">
                        <div class="dm-panel-toolbar">
                            <button type="button" class="dm-back" data-dm-back aria-label="Back to actions">← Back</button>
                        </div>
                        <h4>Delete Conversions</h4>
                        <p class="dm-panel-desc">Only conversion records are removed — clicks stay in Visitor Log. Stats aggregates are adjusted when possible.</p>

                        <div class="dm-mini-tabs">
                            <button type="button" class="dm-mini-tab<?= $deleteConvMode === 'campaign' ? ' is-active' : '' ?>" data-dm-del-conv-mode="campaign">By Campaign</button>
                            <button type="button" class="dm-mini-tab<?= $deleteConvMode === 'clickid' ? ' is-active' : '' ?>" data-dm-del-conv-mode="clickid">By Click ID</button>
                        </div>

                        <div id="delete-conversion-campaign-mode"<?= $deleteConvMode === 'clickid' ? ' style="display:none"' : '' ?>>
                            <form method="post">
                                <input type="hidden" name="action" value="delete_conversions_by_campaign">
                                <div class="dm-row">
                                    <div class="dm-field">
                                        <label for="dm-campaign-conv">Select Campaign</label>
                                        <select id="dm-campaign-conv" name="campaign_id" required>
                                            <option value="">Choose a campaign...</option>
                                            <?php foreach ($allCampaignsForData as $camp): ?>
                                                <option value="<?= (int)$camp['id'] ?>"><?= htmlspecialchars($camp['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="dm-btn-danger"
                                            onclick="return confirm('Delete ALL conversions for this campaign?\n\nThis cannot be undone.');">
                                        Delete Campaign Conversions
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div id="delete-conversion-clickid-mode"<?= $deleteConvMode === 'campaign' ? ' style="display:none"' : '' ?>>
                            <form method="post">
                                <input type="hidden" name="action" value="delete_conversions_by_clickid">
                                <div class="dm-row">
                                    <div class="dm-field">
                                        <label for="dm-click-ids">Click ID(s)</label>
                                        <input id="dm-click-ids" type="text" name="click_ids" required
                                               placeholder="e.g., abc123-def456 or abc123,def456,ghi789"
                                               style="font-family:monospace;">
                                        <p class="hint">Single click ID or multiple IDs separated by commas.</p>
                                    </div>
                                    <button type="submit" class="dm-btn-danger"
                                            onclick="return confirm('Delete the specified conversion(s)?\n\nThis cannot be undone.');">
                                        Delete Conversions
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var root = document.querySelector('.dm-wrap');
    if (!root) return;

    function showSection(name) {
        root.querySelectorAll('.dm-subtab').forEach(function (tab) {
            var active = tab.getAttribute('data-dm-section') === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        root.querySelectorAll('[data-dm-section-panel]').forEach(function (panel) {
            var match = panel.getAttribute('data-dm-section-panel') === name;
            if (match) {
                panel.removeAttribute('hidden');
            } else {
                panel.setAttribute('hidden', 'hidden');
            }
        });
    }

    function setStageView(stageEl, viewName) {
        if (!stageEl) return;
        stageEl.querySelectorAll('.dm-view').forEach(function (view) {
            var match = view.getAttribute('data-dm-view') === viewName;
            view.classList.toggle('is-active', match);
        });
        // Restart fade animation when switching
        var active = stageEl.querySelector('.dm-view.is-active');
        if (active) {
            active.style.animation = 'none';
            // force reflow
            void active.offsetWidth;
            active.style.animation = '';
        }
    }

    root.querySelectorAll('.dm-subtab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            showSection(tab.getAttribute('data-dm-section'));
        });
    });

    root.querySelectorAll('[data-dm-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var stageEl = btn.closest('[data-dm-stage]');
            setStageView(stageEl, btn.getAttribute('data-dm-open'));
        });
    });

    root.querySelectorAll('[data-dm-back]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var stageEl = btn.closest('[data-dm-stage]');
            setStageView(stageEl, 'list');
        });
    });

    window.switchConversionMode = function (mode) {
        var singleMode = document.getElementById('conversion-single-mode');
        var bulkMode = document.getElementById('conversion-bulk-mode');
        if (!singleMode || !bulkMode) return;
        var singleInputs = singleMode.querySelectorAll('input[name="click_id[]"], input[name="revenue[]"]');
        root.querySelectorAll('[data-dm-conv-mode]').forEach(function (t) {
            t.classList.toggle('is-active', t.getAttribute('data-dm-conv-mode') === mode);
        });
        if (mode === 'single') {
            singleMode.style.display = '';
            bulkMode.style.display = 'none';
            singleInputs.forEach(function (input) { input.required = true; });
        } else {
            singleMode.style.display = 'none';
            bulkMode.style.display = '';
            singleInputs.forEach(function (input) { input.required = false; });
        }
    };

    root.querySelectorAll('[data-dm-conv-mode]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            window.switchConversionMode(tab.getAttribute('data-dm-conv-mode'));
        });
    });

    window.switchDeleteConversionMode = function (mode) {
        var campaignMode = document.getElementById('delete-conversion-campaign-mode');
        var clickidMode = document.getElementById('delete-conversion-clickid-mode');
        if (!campaignMode || !clickidMode) return;
        root.querySelectorAll('[data-dm-del-conv-mode]').forEach(function (t) {
            t.classList.toggle('is-active', t.getAttribute('data-dm-del-conv-mode') === mode);
        });
        campaignMode.style.display = mode === 'campaign' ? '' : 'none';
        clickidMode.style.display = mode === 'clickid' ? '' : 'none';
    };

    root.querySelectorAll('[data-dm-del-conv-mode]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            window.switchDeleteConversionMode(tab.getAttribute('data-dm-del-conv-mode'));
        });
    });

    window.addConversionEntry = function () {
        var container = document.getElementById('conversion-entries');
        if (!container) return;
        var newEntry = document.createElement('div');
        newEntry.className = 'conversion-entry dm-conversion-entry';
        newEntry.innerHTML =
            '<div class="dm-field"><label>Click ID</label>' +
            '<input type="text" name="click_id[]" required placeholder="e.g., abc123-def456-ghi789" style="font-family:monospace;"></div>' +
            '<div class="dm-field"><label>Revenue</label>' +
            '<input type="number" name="revenue[]" step="0.01" required placeholder="0.00"></div>' +
            '<div class="dm-field"><label>Currency</label>' +
            '<select name="currency[]"><option value="USD">USD</option><option value="EUR">EUR</option>' +
            '<option value="GBP">GBP</option><option value="CAD">CAD</option><option value="AUD">AUD</option></select></div>' +
            '<div><button type="button" class="dm-btn-danger dm-btn-danger-icon" ' +
            'onclick="this.closest(\'.conversion-entry\').remove()" title="Remove">×</button></div>';
        container.appendChild(newEntry);
    };
})();
</script>
