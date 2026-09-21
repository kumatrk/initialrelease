<?php
/**
 * API Usage Overview — Settings → API Cost Updates (counts only, no rate caps).
 *
 * Expects: $db, $googleAds, $allFacebookMarketingIntegrations, $allGoogleAdsIntegrations
 */
$fbStats = null;
$gaStats = null;
$fbPurposeHour = [];

try {
    $fbTable = $db->query("SHOW TABLES LIKE 'facebook_api_calls'");
    if ($fbTable && $fbTable->num_rows > 0) {
        require_once __DIR__ . '/../../src/Facebook/FacebookApiCallTracker.php';
        $fbTracker = new \SimpleKuma\Facebook\FacebookApiCallTracker($db);
        $fbStats = $fbTracker->getCallStats();
        $fbPurposeHour = $fbTracker->getPurposeBreakdownThisHour();
    }
} catch (Throwable $e) {
    $fbStats = null;
    $fbPurposeHour = [];
}

try {
    $gaTable = $db->query("SHOW TABLES LIKE 'google_ads_api_calls'");
    if ($gaTable && $gaTable->num_rows > 0) {
        $gaStats = (new \SimpleKuma\GoogleAds\GoogleAdsApiCallTracker($db))->getCallStats();
    }
} catch (Throwable $e) {
    $gaStats = null;
}

$fbActive = 0;
foreach ($allFacebookMarketingIntegrations ?? [] as $fm) {
    if (($fm['status'] ?? '') === 'active') {
        $fbActive++;
    }
}

$gaActive = 0;
foreach ($allGoogleAdsIntegrations ?? [] as $gaRow) {
    if (!empty($googleAds) && $googleAds->isCostTrackingConfigured($gaRow)) {
        $gaActive++;
    }
}

$fbToday = (int)($fbStats['today'] ?? 0);
$gaToday = (int)($gaStats['today'] ?? 0);
$fbFailed = (int)($fbStats['failed_today'] ?? 0);
$gaFailed = (int)($gaStats['failed_today'] ?? 0);
$totalToday = $fbToday + $gaToday;
$failedToday = $fbFailed + $gaFailed;
$activeIntegrations = $fbActive + $gaActive;

if ($fbToday === $gaToday) {
    $highestLabel = $totalToday > 0 ? 'Tied today' : 'No calls yet';
    $highestValue = (string)$fbToday;
} elseif ($fbToday > $gaToday) {
    $highestLabel = 'Facebook (Meta) today';
    $highestValue = number_format($fbToday);
} else {
    $highestLabel = 'Google Ads today';
    $highestValue = number_format($gaToday);
}
$apiUsageCssPath = __DIR__ . '/../../public/assets/css/api-usage-overview.css';
$apiUsageCssVer = file_exists($apiUsageCssPath) ? (string)filemtime($apiUsageCssPath) : '1';
?>
<link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/api-usage-overview.css?v=<?= htmlspecialchars($apiUsageCssVer) ?>">

<div class="api-usage-overview card">
    <div class="api-usage-overview__header">
        <h2 class="api-usage-overview__title">API Usage Overview</h2>
        <p class="api-usage-overview__subtitle">
            Calls made for cost sync and related API work across connected integrations.
        </p>
    </div>
    <div class="api-usage-overview__body">
        <div class="api-usage-overview__providers">
            <!-- Facebook -->
            <section class="api-usage-provider" id="api-usage-facebook">
                <div class="api-usage-provider__head">
                    <div class="api-usage-provider__brand">
                        <img class="api-usage-provider__logo"
                             src="<?= ASSETS_BASE_URL ?>/assets/images/fblogoapi.png"
                             alt="Facebook">
                        <h3 class="api-usage-provider__name">Facebook (Meta)</h3>
                    </div>
                    <button type="button"
                            class="api-usage-provider__details"
                            id="fb-cost-sync-details-btn"
                            <?= $fbStats === null ? 'disabled' : '' ?>>
                        View details
                    </button>
                </div>

                <?php if ($fbStats === null): ?>
                    <div class="api-usage-provider__empty">
                        Call tracking table not available yet. Run pending database migrations, then refresh.
                    </div>
                <?php else: ?>
                    <div class="api-usage-provider__metrics">
                        <div class="api-usage-metric">
                            <div class="api-usage-metric__value"><?= number_format((int)$fbStats['this_hour']) ?></div>
                            <div class="api-usage-metric__label">This Hour</div>
                            <div class="api-usage-metric__hint">API calls</div>
                        </div>
                        <div class="api-usage-metric">
                            <div class="api-usage-metric__value"><?= number_format((int)$fbStats['today']) ?></div>
                            <div class="api-usage-metric__label">Today</div>
                            <div class="api-usage-metric__hint">API calls</div>
                        </div>
                        <div class="api-usage-metric">
                            <div class="api-usage-metric__value"><?= number_format((int)$fbStats['this_month']) ?></div>
                            <div class="api-usage-metric__label">This Month</div>
                            <div class="api-usage-metric__hint">API calls</div>
                        </div>
                    </div>
                    <div class="api-usage-provider__meta">
                        <span>Failed today: <strong><?= number_format($fbFailed) ?></strong></span>
                        <span>Active integrations: <strong><?= number_format($fbActive) ?></strong></span>
                    </div>
                    <?php if (!empty($fbPurposeHour)): ?>
                    <div class="api-usage-endpoints">
                        <div class="api-usage-endpoints__title">This hour by purpose</div>
                        <ul class="api-usage-endpoints__list">
                            <?php foreach ($fbPurposeHour as $row): ?>
                                <li>
                                    <span class="api-usage-endpoints__path"><?= htmlspecialchars((string)($row['label'] ?? $row['purpose'] ?? '')) ?></span>
                                    <span class="api-usage-endpoints__count"><?= number_format((int)($row['count'] ?? 0)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php else:
                    $fbEndpoints = $fbStats['endpoints_this_hour'] ?? [];
                    if (!empty($fbEndpoints)):
                    ?>
                    <div class="api-usage-endpoints">
                        <div class="api-usage-endpoints__title">This hour by endpoint</div>
                        <ul class="api-usage-endpoints__list">
                            <?php foreach ($fbEndpoints as $ep): ?>
                                <li>
                                    <code class="api-usage-endpoints__path"><?= htmlspecialchars((string)($ep['endpoint'] ?? '')) ?></code>
                                    <span class="api-usage-endpoints__count"><?= number_format((int)($ep['count'] ?? 0)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; endif; ?>
                <?php endif; ?>
            </section>

            <!-- Google Ads -->
            <section class="api-usage-provider" id="api-usage-google">
                <div class="api-usage-provider__head">
                    <div class="api-usage-provider__brand">
                        <img class="api-usage-provider__logo"
                             src="<?= ASSETS_BASE_URL ?>/assets/images/googlelogoapi.png"
                             alt="Google Ads">
                        <h3 class="api-usage-provider__name">Google Ads</h3>
                    </div>
                    <a class="api-usage-provider__details" href="#ga-cost-integrations-list">View details</a>
                </div>

                <?php if ($gaStats === null): ?>
                    <div class="api-usage-provider__empty">
                        Call tracking table not available yet. Run pending database migrations (including 075), then refresh.
                    </div>
                <?php else: ?>
                    <div class="api-usage-provider__metrics">
                        <div class="api-usage-metric">
                            <div class="api-usage-metric__value"><?= number_format((int)$gaStats['this_hour']) ?></div>
                            <div class="api-usage-metric__label">This Hour</div>
                            <div class="api-usage-metric__hint">API calls</div>
                        </div>
                        <div class="api-usage-metric">
                            <div class="api-usage-metric__value"><?= number_format((int)$gaStats['today']) ?></div>
                            <div class="api-usage-metric__label">Today</div>
                            <div class="api-usage-metric__hint">API calls</div>
                        </div>
                        <div class="api-usage-metric">
                            <div class="api-usage-metric__value"><?= number_format((int)$gaStats['this_month']) ?></div>
                            <div class="api-usage-metric__label">This Month</div>
                            <div class="api-usage-metric__hint">API calls</div>
                        </div>
                    </div>
                    <div class="api-usage-provider__meta">
                        <span>Failed today: <strong><?= number_format($gaFailed) ?></strong></span>
                        <span>Cost API ready: <strong><?= number_format($gaActive) ?></strong></span>
                    </div>
                    <?php
                    $gaEndpoints = $gaStats['endpoints_this_hour'] ?? [];
                    if (!empty($gaEndpoints)):
                    ?>
                    <div class="api-usage-endpoints">
                        <div class="api-usage-endpoints__title">This hour by endpoint</div>
                        <ul class="api-usage-endpoints__list">
                            <?php foreach ($gaEndpoints as $ep): ?>
                                <li>
                                    <code class="api-usage-endpoints__path"><?= htmlspecialchars((string)($ep['endpoint'] ?? '')) ?></code>
                                    <span class="api-usage-endpoints__count"><?= number_format((int)($ep['count'] ?? 0)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>

        <div class="api-usage-summary">
            <h3 class="api-usage-summary__title">Usage Summary</h3>
            <div class="api-usage-summary__grid">
                <div class="api-usage-summary__tile">
                    <div class="api-usage-summary__tile-value"><?= number_format($totalToday) ?></div>
                    <div class="api-usage-summary__tile-label">Total API Calls</div>
                    <div class="api-usage-summary__tile-hint">Across all integrations today</div>
                </div>
                <div class="api-usage-summary__tile">
                    <div class="api-usage-summary__tile-value"><?= number_format($failedToday) ?></div>
                    <div class="api-usage-summary__tile-label">Failed Calls</div>
                    <div class="api-usage-summary__tile-hint">Today (both providers)</div>
                </div>
                <div class="api-usage-summary__tile">
                    <div class="api-usage-summary__tile-value"><?= number_format($activeIntegrations) ?></div>
                    <div class="api-usage-summary__tile-label">Active Integrations</div>
                    <div class="api-usage-summary__tile-hint">Facebook + Google cost ready</div>
                </div>
                <div class="api-usage-summary__tile">
                    <div class="api-usage-summary__tile-value"><?= htmlspecialchars($highestValue) ?></div>
                    <div class="api-usage-summary__tile-label">Highest Volume</div>
                    <div class="api-usage-summary__tile-hint"><?= htmlspecialchars($highestLabel) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="fb-cost-sync-diagnostics-modal" class="api-diag-modal" hidden aria-hidden="true">
    <div class="api-diag-modal__backdrop" data-fb-diag-close></div>
    <div class="api-diag-modal__panel" role="dialog" aria-modal="true" aria-labelledby="fb-diag-title">
        <div class="api-diag-modal__header">
            <h3 id="fb-diag-title">Meta cost sync diagnostics</h3>
            <button type="button" class="api-diag-modal__close" data-fb-diag-close aria-label="Close">&times;</button>
        </div>
        <div class="api-diag-modal__body" id="fb-cost-sync-diagnostics-body">
            <p class="api-diag-loading">Loading last cost-sync run…</p>
        </div>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('fb-cost-sync-details-btn');
    var modal = document.getElementById('fb-cost-sync-diagnostics-modal');
    var body = document.getElementById('fb-cost-sync-diagnostics-body');
    if (!btn || !modal || !body) return;

    function openModal() {
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('api-diag-modal-open');
        loadDiagnostics();
    }

    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('api-diag-modal-open');
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderDiagnostics(payload) {
        var last = payload.last_run || null;
        var html = '';

        if (!last) {
            html += '<div class="api-diag-banner">No <strong>last-run summary</strong> yet — that appears after the next hourly Meta cost cron with this build. The tables below still show recent Graph calls from the database.</div>';
        } else {
            var status = esc(last.status || 'completed');
            html += '<section class="api-diag-section">';
            html += '<h4>Last cost-sync run</h4>';
            html += '<div class="api-diag-meta-grid">';
            html += '<div><span class="api-diag-k">Status</span><span class="api-diag-v">' + status + '</span></div>';
            html += '<div><span class="api-diag-k">Started (DB)</span><span class="api-diag-v">' + esc(last.started_at) + '</span></div>';
            html += '<div><span class="api-diag-k">Finished</span><span class="api-diag-v">' + esc(last.finished_at) + '</span></div>';
            html += '<div><span class="api-diag-k">Duration</span><span class="api-diag-v">' + esc(last.duration_seconds) + 's</span></div>';
            html += '<div><span class="api-diag-k">API calls this run</span><span class="api-diag-v">' + esc(last.api_calls_total) + '</span></div>';
            html += '<div><span class="api-diag-k">Ad accounts</span><span class="api-diag-v">' + esc(last.ad_accounts_processed != null ? last.ad_accounts_processed : last.ad_accounts) + '</span></div>';
            html += '<div><span class="api-diag-k">User today</span><span class="api-diag-v">' + esc(last.user_today || '—') + ' <small>(' + esc(last.user_timezone || '') + ')</small></span></div>';
            html += '<div><span class="api-diag-k">UTC dates</span><span class="api-diag-v">' + esc((last.utc_dates_processed || []).join(', ') || '—') + '</span></div>';
            html += '<div><span class="api-diag-k">Adset fallbacks</span><span class="api-diag-v">' + esc(last.adset_fallback_attempts || 0);
            if (last.adset_fallback_capped) {
                html += ' <small>(cap hits: ' + esc(last.adset_fallback_capped) + ')</small>';
            }
            html += '</span></div>';
            if (last.skip_reason) {
                html += '<div class="api-diag-span"><span class="api-diag-k">Skipped</span><span class="api-diag-v">' + esc(last.skip_reason) + '</span></div>';
            }
            html += '</div></section>';

            var byPurpose = last.api_calls_by_purpose || [];
            html += '<section class="api-diag-section"><h4>Calls by purpose (this run)</h4>';
            if (!byPurpose.length) {
                html += '<p class="api-diag-muted">No Graph calls recorded during this run window.</p>';
            } else {
                html += '<table class="api-diag-table"><thead><tr><th>Purpose</th><th>Calls</th><th>OK</th><th>Failed</th></tr></thead><tbody>';
                byPurpose.forEach(function (row) {
                    html += '<tr><td>' + esc(row.label || row.purpose) + '</td><td>' + esc(row.count) + '</td><td>' + esc(row.success) + '</td><td>' + esc(row.failed) + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            html += '</section>';
        }

        var hourPurpose = payload.this_hour_by_purpose || [];
        html += '<section class="api-diag-section"><h4>This hour by purpose</h4>';
        if (!hourPurpose.length) {
            html += '<p class="api-diag-muted">No calls in the last hour.</p>';
        } else {
            html += '<table class="api-diag-table"><thead><tr><th>Purpose</th><th>Calls</th></tr></thead><tbody>';
            hourPurpose.forEach(function (row) {
                html += '<tr><td>' + esc(row.label || row.purpose) + '</td><td>' + esc(row.count) + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        html += '</section>';

        var calls = payload.recent_calls || [];
        html += '<section class="api-diag-section"><h4>Recent Graph calls</h4>';
        if (!calls.length) {
            html += '<p class="api-diag-muted">No recent calls to show.</p>';
        } else {
            html += '<div class="api-diag-table-wrap"><table class="api-diag-table api-diag-table--compact"><thead><tr><th>Time</th><th>Purpose</th><th>Endpoint</th><th>Account</th><th>HTTP</th><th>OK</th></tr></thead><tbody>';
            calls.forEach(function (c) {
                html += '<tr>';
                html += '<td>' + esc(c.called_at) + '</td>';
                html += '<td>' + esc(c.purpose_label || c.purpose) + '</td>';
                html += '<td><code>' + esc(c.endpoint) + '</code></td>';
                html += '<td>' + esc(c.ad_account_id || '—') + '</td>';
                html += '<td>' + esc(c.response_code) + '</td>';
                html += '<td>' + (c.success ? 'yes' : 'no') + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';
        }
        html += '</section>';

        body.innerHTML = html;
    }

    function loadDiagnostics() {
        body.innerHTML = '<p class="api-diag-loading">Loading last cost-sync run…</p>';
        fetch('?page=settings&tab=api-costs&ajax=fb_cost_sync_diagnostics', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    body.innerHTML = '<p class="api-diag-error">' + esc((data && data.error) || 'Failed to load diagnostics') + '</p>';
                    return;
                }
                renderDiagnostics(data.diagnostics || {});
            })
            .catch(function () {
                body.innerHTML = '<p class="api-diag-error">Network error loading diagnostics.</p>';
            });
    }

    btn.addEventListener('click', openModal);
    modal.querySelectorAll('[data-fb-diag-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });
})();
</script>
