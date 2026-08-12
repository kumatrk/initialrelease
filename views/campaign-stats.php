<?php
/** Campaign Stats — main reporting workspace. */

use SimpleKuma\Entity\Campaign;
use SimpleKuma\Utils\Formatter;

$campaignEntity = new Campaign($GLOBALS['db']);
$allCampaigns = $campaignEntity->getAll();

$userTimezone = $GLOBALS['userTimezone'] ?? 'UTC';
$userCurrency = $GLOBALS['userCurrency'] ?? 'USD';
$today = Formatter::getTodayInTimezone($userTimezone);

$selectedCampaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;
if ($selectedCampaignId === null && count($allCampaigns) > 0) {
    $selectedCampaignId = (int)$allCampaigns[0]['id'];
}

$hasCampaign = $selectedCampaignId !== null && $selectedCampaignId > 0;

$dateFrom = $_GET['date_from'] ?? $today;
$dateTo = $_GET['date_to'] ?? $today;

$apiBase = APP_BASE_URL . '/api-campaign-stats.php';
$savedViewsApi = APP_BASE_URL . '/api-campaign-stats-saved-views.php';

$statsCssPath = __DIR__ . '/../public/assets/css/campaign-stats.css';
$statsMobileCssPath = __DIR__ . '/../public/assets/css/mobile-campaign-stats.css';
$statsJsPath = __DIR__ . '/../public/assets/js/campaign-stats.js';
$statsCssVer = file_exists($statsCssPath) ? (string)filemtime($statsCssPath) : '1';
$statsMobileCssVer = file_exists($statsMobileCssPath) ? (string)filemtime($statsMobileCssPath) : '1';
$statsJsVer = file_exists($statsJsPath) ? (string)filemtime($statsJsPath) : '1';
?>
<link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/campaign-stats.css?v=<?= htmlspecialchars($statsCssVer) ?>">
<link rel="stylesheet" href="<?= ASSETS_BASE_URL ?>/assets/css/mobile-campaign-stats.css?v=<?= htmlspecialchars($statsMobileCssVer) ?>">

<div class="stats-v2-page" id="stats-v2-app"
     data-api-base="<?= htmlspecialchars($apiBase) ?>"
     data-saved-views-api="<?= htmlspecialchars($savedViewsApi) ?>"
     data-campaign-id="<?= (int)($selectedCampaignId ?? 0) ?>"
     data-date-from="<?= htmlspecialchars($dateFrom) ?>"
     data-date-to="<?= htmlspecialchars($dateTo) ?>"
     data-today="<?= htmlspecialchars($today) ?>"
     data-user-timezone="<?= htmlspecialchars($userTimezone) ?>"
     data-currency="<?= htmlspecialchars($userCurrency) ?>">

    <div class="stats-v2-header">
        <div>
            <h1 class="page-title">Campaign Stats</h1>
        </div>
    </div>
    <div id="stats-v2-flash" class="stats-v2-flash hidden" role="alert" hidden></div>
    <?php if (!$hasCampaign): ?>
    <div class="stats-v2-message error">Select or create a campaign to view stats.</div>
    <?php else: ?>
    <div class="stats-v2-filters card">
        <div class="stats-v2-filter-row">
            <div class="stats-v2-field stats-v2-field-campaign">
                <label for="stats-v2-campaign">Campaign</label>
                <select id="stats-v2-campaign">
                    <?php foreach ($allCampaigns as $camp): ?>
                        <option value="<?= (int)$camp['id'] ?>" <?= $selectedCampaignId === (int)$camp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($camp['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="stats-v2-field">
                <label for="stats-v2-date-from">From</label>
                <input type="date" id="stats-v2-date-from" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="stats-v2-field">
                <label for="stats-v2-date-to">To</label>
                <input type="date" id="stats-v2-date-to" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="stats-v2-filter-actions">
                <div class="stats-v2-presets">
                    <button type="button" class="btn btn-sm btn-secondary" data-preset="today">Today</button>
                    <button type="button" class="btn btn-sm btn-secondary" data-preset="yesterday">Yesterday</button>
                    <button type="button" class="btn btn-sm btn-secondary" data-preset="last7">Last 7</button>
                    <button type="button" class="btn btn-sm btn-secondary" data-preset="last30">Last 30</button>
                </div>
                <button type="button" class="btn btn-primary" id="stats-v2-apply-filters">Apply</button>
                <button type="button" class="btn btn-sm btn-outline stats-v2-advanced-toggle" id="stats-v2-advanced-toggle" aria-expanded="false">Advanced filters</button>
            </div>
        </div>
        <div class="stats-v2-advanced-panel hidden" id="stats-v2-advanced-panel">
            <div class="stats-v2-field stats-v2-field-ts hidden" id="stats-v2-traffic-source-wrap">
                <label for="stats-v2-traffic-source">Traffic source</label>
                <select id="stats-v2-traffic-source">
                    <option value="">All traffic sources</option>
                </select>
            </div>
            <div class="stats-v2-field stats-v2-field-offer hidden" id="stats-v2-offer-wrap">
                <label for="stats-v2-offer">Offer</label>
                <select id="stats-v2-offer">
                    <option value="">All offers</option>
                </select>
            </div>
            <div class="stats-v2-field stats-v2-field-landing hidden" id="stats-v2-landing-wrap">
                <label for="stats-v2-landing">Landing page</label>
                <select id="stats-v2-landing">
                    <option value="">All landing pages</option>
                </select>
            </div>
            <div class="stats-v2-field stats-v2-field-token hidden" id="stats-v2-token-wrap">
                <label for="stats-v2-token-param">Token filter</label>
                <select id="stats-v2-token-param">
                    <option value="">Any token</option>
                </select>
            </div>
            <div class="stats-v2-field stats-v2-field-token-value hidden" id="stats-v2-token-value-wrap">
                <label for="stats-v2-token-value">Token value</label>
                <input type="text" id="stats-v2-token-value" list="stats-v2-token-values-list" placeholder="Exact value…" autocomplete="off">
                <datalist id="stats-v2-token-values-list"></datalist>
            </div>
            <div class="stats-v2-field stats-v2-field-search">
                <label for="stats-v2-breakdown-search">Filter breakdown rows</label>
                <input type="search" id="stats-v2-breakdown-search" placeholder="Search name in current report…" autocomplete="off">
            </div>
            <p class="stats-v2-advanced-hint">Offer, landing, traffic source, and token filters apply to KPIs, charts, and breakdown. Row search filters the loaded table only.</p>
        </div>
    </div>

    <div class="stats-v2-kpi-row" id="stats-v2-kpi">
        <div class="stats-v2-kpi-card skeleton"></div>
        <div class="stats-v2-kpi-card skeleton"></div>
        <div class="stats-v2-kpi-card skeleton"></div>
        <div class="stats-v2-kpi-card skeleton"></div>
        <div class="stats-v2-kpi-card skeleton"></div>
        <div class="stats-v2-kpi-card skeleton"></div>
        <div class="stats-v2-kpi-card skeleton"></div>
        <div class="stats-v2-kpi-card skeleton"></div>
    </div>

    <div class="stats-v2-tabs">
        <button type="button" class="stats-v2-tab active" data-tab="overview">Overview</button>
        <button type="button" class="stats-v2-tab" data-tab="breakdown">Breakdown</button>
        <button type="button" class="stats-v2-tab" data-tab="chart">Chart</button>
    </div>

    <div class="stats-v2-panel stats-v2-panel-overview active" data-panel="overview">
        <div class="card stats-v2-chart-wrap">
            <div class="stats-v2-chart-header">
                <h3>Performance Trend</h3>
                <div class="stats-v2-chart-header-actions">
                    <div class="stats-v2-chart-metrics stats-v2-overview-chart-metrics">
                        <label><input type="checkbox" class="overview-chart-metric" value="visitors" checked> Visitors</label>
                        <label><input type="checkbox" class="overview-chart-metric" value="clicks" checked> Clicks</label>
                        <label><input type="checkbox" class="overview-chart-metric" value="conversions" checked> Conversions</label>
                        <label><input type="checkbox" class="overview-chart-metric" value="cost" checked> Cost</label>
                        <label><input type="checkbox" class="overview-chart-metric" value="revenue" checked> Revenue</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" id="stats-v2-toggle-chart">Collapse</button>
                </div>
            </div>
            <div class="stats-v2-chart-body" id="stats-v2-overview-chart-area">
                <canvas id="stats-v2-overview-chart"></canvas>
            </div>
        </div>
        <div class="card stats-v2-efficiency-wrap">
            <h3>Efficiency metrics</h3>
            <div class="stats-v2-efficiency-grid" id="stats-v2-overview-efficiency"></div>
        </div>
    </div>

    <div class="stats-v2-panel stats-v2-panel-breakdown" data-panel="breakdown">
        <div class="card stats-v2-breakdown-config">
            <div class="stats-v2-dimensions">
                <label class="stats-v2-dim-label" for="stats-v2-dim-preset">Quick preset</label>
                <select id="stats-v2-dim-preset" class="stats-v2-dim-preset-select">
                    <option value="">Custom…</option>
                </select>
                <span class="stats-v2-dim-label">Break down by</span>
                <div id="stats-v2-dimension-tags" class="stats-v2-dimension-tags"></div>
                <select id="stats-v2-add-dimension" class="stats-v2-add-dimension">
                    <option value="">+ Add dimension</option>
                </select>
            </div>
            <div class="stats-v2-breakdown-actions">
                <div class="stats-v2-saved-views">
                    <select id="stats-v2-saved-view" class="stats-v2-saved-view-select" title="Saved views">
                        <option value="">Saved views…</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-secondary" id="stats-v2-save-view" title="Save current breakdown config">Save view</button>
                    <button type="button" class="btn btn-sm btn-secondary" id="stats-v2-delete-view" title="Delete selected view">Delete</button>
                </div>
                <div class="stats-v2-breakdown-run">
                    <label class="stats-v2-checkbox"><input type="checkbox" id="stats-v2-show-totals" checked> Show totals</label>
                    <select id="stats-v2-density">
                        <option value="comfortable">Comfortable</option>
                        <option value="compact">Compact</option>
                    </select>
                    <button type="button" class="btn btn-primary" id="stats-v2-run-report">Run Report</button>
                </div>
            </div>
        </div>
        <div class="card stats-v2-table-card">
            <div class="stats-v2-table-toolbar">
                <button type="button" class="btn btn-sm btn-secondary" id="stats-v2-export-csv">Export CSV</button>
                <button type="button" class="btn btn-sm btn-secondary" id="stats-v2-customize-columns">Customize Columns</button>
            </div>
            <div id="stats-v2-column-picker" class="stats-v2-column-picker hidden">
                <label><input type="checkbox" class="col-toggle" data-col="visitors" checked> Visitors</label>
                <label><input type="checkbox" class="col-toggle" data-col="lp_clicks" checked> Clicks</label>
                <label><input type="checkbox" class="col-toggle" data-col="ctr" checked> CTR</label>
                <label><input type="checkbox" class="col-toggle" data-col="conversions" checked> Conversions</label>
                <label><input type="checkbox" class="col-toggle" data-col="optins" checked> Opt-ins</label>
                <label><input type="checkbox" class="col-toggle" data-col="cr" checked> CR</label>
                <label><input type="checkbox" class="col-toggle" data-col="cost" checked> Cost</label>
                <label><input type="checkbox" class="col-toggle" data-col="revenue" checked> Revenue</label>
                <label><input type="checkbox" class="col-toggle" data-col="profit" checked> Profit</label>
                <label><input type="checkbox" class="col-toggle" data-col="roi" checked> ROI</label>
            </div>
            <div id="stats-v2-breakdown-message" class="stats-v2-message"></div>
            <div class="stats-v2-table-scroll">
                <table class="stats-v2-table" id="stats-v2-breakdown-table">
                    <thead>
                        <tr id="stats-v2-breakdown-head">
                            <th data-col="name">Name</th>
                            <th class="num" data-col="visitors">Visitors</th>
                            <th class="num" data-col="lp_clicks">Clicks</th>
                            <th class="num" data-col="ctr">CTR</th>
                            <th class="num" data-col="conversions">Conv.</th>
                            <th class="num" data-col="optins">Opt-ins</th>
                            <th class="num" data-col="cr">CR</th>
                            <th class="num" data-col="cost">Cost</th>
                            <th class="num" data-col="revenue">Revenue</th>
                            <th class="num" data-col="profit">Profit</th>
                            <th class="num" data-col="roi">ROI</th>
                        </tr>
                    </thead>
                    <tbody id="stats-v2-breakdown-body">
                        <tr><td colspan="10" class="stats-v2-empty">Configure dimensions and click Run Report</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="stats-v2-pagination" id="stats-v2-pagination"></div>
        </div>
    </div>

    <div class="stats-v2-panel stats-v2-panel-chart" data-panel="chart">
        <div class="card stats-v2-chart-wrap">
            <div class="stats-v2-chart-toolbar">
                <select id="stats-v2-chart-granularity">
                    <option value="auto">Auto</option>
                    <option value="hour">Hour</option>
                    <option value="day">Day</option>
                </select>
                <div class="stats-v2-chart-metrics">
                    <label><input type="checkbox" class="chart-metric" value="visitors" checked> Visitors</label>
                    <label><input type="checkbox" class="chart-metric" value="clicks" checked> Clicks</label>
                    <label><input type="checkbox" class="chart-metric" value="conversions" checked> Conversions</label>
                    <label><input type="checkbox" class="chart-metric" value="cost" checked> Cost</label>
                    <label><input type="checkbox" class="chart-metric" value="revenue" checked> Revenue</label>
                </div>
            </div>
            <div class="stats-v2-chart-body stats-v2-chart-body--main">
                <canvas id="stats-v2-chart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?= ASSETS_BASE_URL ?>/assets/js/campaign-stats.js?v=<?= htmlspecialchars($statsJsVer) ?>"></script>
