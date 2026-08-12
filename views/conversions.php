<?php
// Conversion Log Page
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Stats/ConversionsQueryService.php';

use SimpleKuma\Stats\ConversionsQueryService;
use SimpleKuma\Tracking\ConversionOptInClassifier;
use SimpleKuma\Utils\Formatter;

function renderClickLookupLink(string $clickId): string
{
    $url = APP_BASE_URL . '/index.php?page=click-lookup&click_id=' . rawurlencode($clickId);
    $icon = ASSETS_BASE_URL . '/assets/images/clicklookbear.png';
    return sprintf(
        '<a href="%s" class="click-lookup-link" title="Look up this click" aria-label="Look up click %s">'
        . '<img src="%s" alt="" width="22" height="22">'
        . '</a>',
        htmlspecialchars($url),
        htmlspecialchars($clickId),
        htmlspecialchars($icon)
    );
}

function renderConversionStatusBadge(string $status): string
{
    $class = 'badge';
    if ($status === 'approved') {
        $class .= ' badge-success';
    } elseif ($status === 'rejected') {
        $class .= ' badge-danger';
    } else {
        $class .= ' badge-warning';
    }

    return '<span class="' . htmlspecialchars($class) . '">' . htmlspecialchars($status) . '</span>';
}

function renderConversionEventCell(?string $eventKey): string
{
    if (ConversionOptInClassifier::isOptIn($eventKey)) {
        $key = htmlspecialchars((string) $eventKey);
        return '<span class="badge badge-optin" title="Counted as Opt-in (not a purchase conversion)">Opt-in</span>'
            . ' <span class="conversion-mono conversion-event-key">' . $key . '</span>';
    }

    if ($eventKey === null || $eventKey === '') {
        return '<span class="conversion-mono">-</span>';
    }

    return '<span class="conversion-mono">' . htmlspecialchars($eventKey) . '</span>';
}

$db = $GLOBALS['db'] ?? new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$userTimezone = $GLOBALS['userTimezone'] ?? 'UTC';
$userCurrency = $GLOBALS['userCurrency'] ?? 'USD';

$pageNum = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$perPage = isset($_GET['per_page_limit']) ? (int) $_GET['per_page_limit'] : 50;
$perPage = in_array($perPage, [50, 100, 200, 500], true) ? $perPage : 50;

$campaignFilter = isset($_GET['campaign']) && $_GET['campaign'] !== '' ? (int) $_GET['campaign'] : null;
if ($campaignFilter === 0) {
    $campaignFilter = null;
}

$eventTypeFilter = isset($_GET['event_type']) ? (string) $_GET['event_type'] : 'all';
if (!in_array($eventTypeFilter, ['all', 'optins', 'conversions'], true)) {
    $eventTypeFilter = 'all';
}

$todayInUserTz = Formatter::getTodayInTimezone($userTimezone);
if (!isset($_GET['date_from']) && !isset($_GET['date_to'])) {
    $dateFrom = $todayInUserTz;
    $dateTo = $todayInUserTz;
} else {
    $dateFrom = $_GET['date_from'] ?? $todayInUserTz;
    $dateTo = $_GET['date_to'] ?? $todayInUserTz;
}

$service = new ConversionsQueryService($db);
$result = $service->listConversionsForLog(
    $campaignFilter,
    $dateFrom,
    $dateTo,
    $userTimezone,
    $pageNum,
    $perPage,
    null,
    null,
    $eventTypeFilter
);

$conversions = $result['rows'];
$totalRows = $result['total'];
$totalRevenue = $result['total_revenue'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$campaigns = $db->query('SELECT id, name FROM campaigns ORDER BY name')->fetch_all(MYSQLI_ASSOC);

if (!isset($GLOBALS['db'])) {
    $db->close();
}

function buildConversionPaginationUrl(int $pageNumber, array $currentParams): string
{
    $params = array_diff_key($currentParams, ['p' => '', 'page' => '']);
    $params['page'] = 'conversions';
    $params['p'] = $pageNumber;
    return '?' . http_build_query($params);
}

function generateConversionPagination(int $currentPage, int $totalPages, array $getParams): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<div style="display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap;">';

    if ($currentPage > 1) {
        $firstUrl = buildConversionPaginationUrl(1, $getParams);
        $html .= '<a href="' . htmlspecialchars($firstUrl) . '" class="btn btn-outline" style="min-width: 80px;">First</a>';
    }

    if ($currentPage > 1) {
        $prevUrl = buildConversionPaginationUrl($currentPage - 1, $getParams);
        $html .= '<a href="' . htmlspecialchars($prevUrl) . '" class="btn btn-outline">Previous</a>';
    }

    $html .= '<span style="padding: 8px 16px; color: #666; font-weight: 500;">Page '
        . $currentPage . ' of ' . $totalPages . '</span>';

    if ($currentPage < $totalPages) {
        $nextUrl = buildConversionPaginationUrl($currentPage + 1, $getParams);
        $html .= '<a href="' . htmlspecialchars($nextUrl) . '" class="btn btn-outline">Next</a>';
    }

    $html .= '</div>';

    return $html;
}

$activePreset = null;
try {
    $tz = new DateTimeZone($userTimezone);
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');

    $yesterdayDt = clone $now;
    $yesterdayDt->modify('-1 day');
    $yesterday = $yesterdayDt->format('Y-m-d');

    $last7Dt = clone $now;
    $last7Dt->modify('-6 days');
    $last7Start = $last7Dt->format('Y-m-d');

    $last14Dt = clone $now;
    $last14Dt->modify('-13 days');
    $last14Start = $last14Dt->format('Y-m-d');

    $last30Dt = clone $now;
    $last30Dt->modify('-29 days');
    $last30Start = $last30Dt->format('Y-m-d');

    $lastMonthStartDt = clone $now;
    $lastMonthStartDt->modify('first day of last month');
    $lastMonthStart = $lastMonthStartDt->format('Y-m-d');

    $lastMonthEndDt = clone $now;
    $lastMonthEndDt->modify('last day of last month');
    $lastMonthEnd = $lastMonthEndDt->format('Y-m-d');

    $thisMonthStartDt = clone $now;
    $thisMonthStartDt->modify('first day of this month');
    $thisMonthStart = $thisMonthStartDt->format('Y-m-d');

    $allTimeStart = '2025-01-01';
} catch (Exception $e) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $last7Start = date('Y-m-d', strtotime('-6 days'));
    $last14Start = date('Y-m-d', strtotime('-13 days'));
    $last30Start = date('Y-m-d', strtotime('-29 days'));
    $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
    $lastMonthEnd = date('Y-m-t', strtotime('last month'));
    $thisMonthStart = date('Y-m-01');
    $allTimeStart = '2025-01-01';
}

if ($dateFrom === $today && $dateTo === $today) {
    $activePreset = 'today';
} elseif ($dateFrom === $yesterday && $dateTo === $yesterday) {
    $activePreset = 'yesterday';
} elseif ($dateFrom === $last7Start && $dateTo === $today) {
    $activePreset = 'last7';
} elseif ($dateFrom === $last14Start && $dateTo === $today) {
    $activePreset = 'last14';
} elseif ($dateFrom === $last30Start && $dateTo === $today) {
    $activePreset = 'last30';
} elseif ($dateFrom === $lastMonthStart && $dateTo === $lastMonthEnd) {
    $activePreset = 'lastmonth';
} elseif ($dateFrom === $thisMonthStart && $dateTo === $today) {
    $activePreset = 'thismonth';
} elseif ($dateFrom === $allTimeStart && $dateTo === $today) {
    $activePreset = 'alltime';
}

$exportParams = $_GET;
unset($exportParams['export'], $exportParams['p']);
$exportParams['page'] = 'conversions';
$exportParams['export'] = 'csv';
$exportUrl = '?' . http_build_query($exportParams);
?>

<div class="page-header">
    <h1 class="page-title">Conversion Log</h1>
    <p class="page-description">Spreadsheet-style view of all conversions with full attribution details. Opt-ins show a teal badge and can be filtered separately.</p>
</div>

<div class="card conversion-log-filters" style="margin-bottom: 24px;">
    <div class="card-body">
        <div class="visitor-date-presets conversion-date-presets" style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px;">
            <button type="button" onclick="setConversionDate('today')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'today' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'today' ? '#fff' : '#666' ?>; cursor: pointer;">Today</button>
            <button type="button" onclick="setConversionDate('yesterday')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'yesterday' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'yesterday' ? '#fff' : '#666' ?>; cursor: pointer;">Yesterday</button>
            <button type="button" onclick="setConversionDate('last7')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last7' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last7' ? '#fff' : '#666' ?>; cursor: pointer;">7d</button>
            <button type="button" onclick="setConversionDate('last14')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last14' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last14' ? '#fff' : '#666' ?>; cursor: pointer;">14d</button>
            <button type="button" onclick="setConversionDate('last30')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'last30' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'last30' ? '#fff' : '#666' ?>; cursor: pointer;">30d</button>
            <button type="button" onclick="setConversionDate('lastmonth')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'lastmonth' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'lastmonth' ? '#fff' : '#666' ?>; cursor: pointer;">Last Mo</button>
            <button type="button" onclick="setConversionDate('thismonth')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'thismonth' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'thismonth' ? '#fff' : '#666' ?>; cursor: pointer;">This Mo</button>
            <button type="button" onclick="setConversionDate('alltime')" style="padding: 4px 8px; font-size: 11px; border: 1px solid #ddd; border-radius: 3px; background: <?= $activePreset === 'alltime' ? '#3d5a26' : '#fff' ?>; color: <?= $activePreset === 'alltime' ? '#fff' : '#666' ?>; cursor: pointer;">ALL TIME</button>
        </div>

        <form method="get" action="" id="conversion-date-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end;">
            <input type="hidden" name="page" value="conversions">

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Date From</label>
                <input type="date" name="date_from" id="conversion_date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                       style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Date To</label>
                <input type="date" name="date_to" id="conversion_date_to" value="<?= htmlspecialchars($dateTo) ?>"
                       style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Campaign</label>
                <select name="campaign" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                    <option value="">All Campaigns</option>
                    <?php foreach ($campaigns as $camp): ?>
                        <option value="<?= (int) $camp['id'] ?>" <?= $campaignFilter === (int) $camp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($camp['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Event type</label>
                <select name="event_type" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                    <option value="all" <?= $eventTypeFilter === 'all' ? 'selected' : '' ?>>All events</option>
                    <option value="optins" <?= $eventTypeFilter === 'optins' ? 'selected' : '' ?>>Opt-ins only</option>
                    <option value="conversions" <?= $eventTypeFilter === 'conversions' ? 'selected' : '' ?>>Conversions only</option>
                </select>
            </div>

            <div>
                <label style="display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px;">Per Page</label>
                <select name="per_page_limit" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 4px;">
                    <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                    <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                    <option value="200" <?= $perPage === 200 ? 'selected' : '' ?>>200</option>
                    <option value="500" <?= $perPage === 500 ? 'selected' : '' ?>>500</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Apply Filters</button>
        </form>
    </div>
</div>

<script>
const conversionDatePresets = {
    today: '<?= $today ?>',
    yesterday: '<?= $yesterday ?>',
    last7Start: '<?= $last7Start ?>',
    last14Start: '<?= $last14Start ?>',
    last30Start: '<?= $last30Start ?>',
    lastMonthStart: '<?= $lastMonthStart ?>',
    lastMonthEnd: '<?= $lastMonthEnd ?>',
    thisMonthStart: '<?= $thisMonthStart ?>',
    allTimeStart: '<?= $allTimeStart ?>'
};

function setConversionDate(preset) {
    const dateFromInput = document.getElementById('conversion_date_from');
    const dateToInput = document.getElementById('conversion_date_to');
    let fromDate;
    let toDate;

    switch (preset) {
        case 'today':
            fromDate = conversionDatePresets.today;
            toDate = conversionDatePresets.today;
            break;
        case 'yesterday':
            fromDate = conversionDatePresets.yesterday;
            toDate = conversionDatePresets.yesterday;
            break;
        case 'last7':
            fromDate = conversionDatePresets.last7Start;
            toDate = conversionDatePresets.today;
            break;
        case 'last14':
            fromDate = conversionDatePresets.last14Start;
            toDate = conversionDatePresets.today;
            break;
        case 'last30':
            fromDate = conversionDatePresets.last30Start;
            toDate = conversionDatePresets.today;
            break;
        case 'lastmonth':
            fromDate = conversionDatePresets.lastMonthStart;
            toDate = conversionDatePresets.lastMonthEnd;
            break;
        case 'thismonth':
            fromDate = conversionDatePresets.thisMonthStart;
            toDate = conversionDatePresets.today;
            break;
        case 'alltime':
            fromDate = conversionDatePresets.allTimeStart;
            toDate = conversionDatePresets.today;
            break;
    }

    dateFromInput.value = fromDate;
    dateToInput.value = toDate;
    document.getElementById('conversion-date-form').submit();
}
</script>

<div class="conversion-log-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Total Conversions</div>
        <div style="font-size: 28px; font-weight: 700; color: #3d5a26;"><?= number_format($totalRows) ?></div>
    </div>
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Total Revenue</div>
        <div style="font-size: 28px; font-weight: 700; color: #2ecc71;"><?= Formatter::formatCurrency($totalRevenue, $userCurrency) ?></div>
    </div>
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Showing</div>
        <div style="font-size: 28px; font-weight: 700; color: #3d5a26;"><?= number_format(min($perPage, count($conversions))) ?></div>
    </div>
    <div class="card" style="padding: 16px;">
        <div style="font-size: 12px; color: #666;">Page</div>
        <div style="font-size: 28px; font-weight: 700; color: #3d5a26;"><?= $pageNum ?> / <?= $totalPages ?></div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <div style="margin-bottom: 24px;">
        <?= generateConversionPagination($pageNum, $totalPages, $_GET) ?>
    </div>
<?php endif; ?>

<div class="card conversion-log-card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <h2 class="card-title">Conversions (<?= number_format($totalRows) ?>)</h2>
        <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-secondary">Export CSV</a>
    </div>
    <div class="card-body">
        <?php if (empty($conversions)): ?>
            <div style="text-align: center; padding: 60px; color: #999;">
                <img src="<?= ASSETS_BASE_URL ?>/assets/images/conversionbear.png" alt="" width="64" height="64" style="margin-bottom: 16px; opacity: 0.5;">
                <p>No conversions found for the selected filters.</p>
            </div>
        <?php else: ?>
            <div class="conversion-log-table-wrapper table-wrapper">
                <table class="table conversion-log-table">
                    <thead>
                        <tr>
                            <th>Conv ID</th>
                            <th>Time</th>
                            <th>Click ID</th>
                            <th>Campaign</th>
                            <th>Offer</th>
                            <th>LP</th>
                            <th>Status</th>
                            <th>Payout</th>
                            <th>Value</th>
                            <th>Revenue</th>
                            <th>Currency</th>
                            <th>TXID</th>
                            <th>Event</th>
                            <th>Event ID</th>
                            <th>Traffic Source</th>
                            <th>Country</th>
                            <th>City</th>
                            <th>State</th>
                            <th>IP</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conversions as $index => $conv): ?>
                        <?php
                            $isOptIn = ConversionOptInClassifier::isOptIn($conv['event_key'] ?? null);
                            $rowClass = ($index % 2 === 0 ? 'conversion-row-even' : 'conversion-row-odd')
                                . ($isOptIn ? ' conversion-row-optin' : '');
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td class="conversion-mono"><?= (int) $conv['id'] ?></td>
                            <td class="conversion-mono conversion-nowrap"><?= htmlspecialchars(Formatter::formatDateTime($conv['ts'], $userTimezone)) ?></td>
                            <td>
                                <div class="click-id-row">
                                    <code
                                        class="click-id-copyable conversion-mono"
                                        data-click-id="<?= htmlspecialchars($conv['click_id']) ?>"
                                        onclick="copyClickId(this)"
                                        title="Click to copy: <?= htmlspecialchars($conv['click_id']) ?>">
                                        <?= htmlspecialchars($conv['click_id']) ?>
                                    </code>
                                    <?= renderClickLookupLink($conv['click_id']) ?>
                                </div>
                            </td>
                            <td><strong><?= htmlspecialchars($conv['campaign_name'] ?? '-') ?></strong></td>
                            <td><?= htmlspecialchars($conv['offer_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($conv['landing_page_name'] ?? '-') ?></td>
                            <td><?= renderConversionStatusBadge($conv['status'] ?? 'pending') ?></td>
                            <td class="conversion-num"><?= $conv['payout'] !== null ? Formatter::formatCurrency($conv['payout'], $conv['currency'] ?? $userCurrency) : '-' ?></td>
                            <td class="conversion-num"><?= $conv['value'] !== null ? Formatter::formatCurrency($conv['value'], $conv['currency'] ?? $userCurrency) : '-' ?></td>
                            <td class="conversion-num conversion-revenue"><?= Formatter::formatCurrency($conv['revenue'], $conv['currency'] ?? $userCurrency) ?></td>
                            <td class="conversion-mono"><?= htmlspecialchars($conv['currency'] ?? '-') ?></td>
                            <td class="conversion-mono"><?= htmlspecialchars($conv['txid'] ?? '-') ?></td>
                            <td><?= renderConversionEventCell($conv['event_key'] ?? null) ?></td>
                            <td class="conversion-mono"><?= htmlspecialchars($conv['event_id'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($conv['traffic_source_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($conv['country'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($conv['city'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($conv['region'] ?? '-') ?></td>
                            <td class="conversion-mono"><?= htmlspecialchars($conv['ip'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($conv['source'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <div style="margin-top: 24px;">
        <?= generateConversionPagination($pageNum, $totalPages, $_GET) ?>
    </div>
<?php endif; ?>

<script>
function showCopyFeedback(element, status, message) {
    const row = element.closest('.click-id-row');
    if (!element._copyState) {
        element._copyState = {
            text: element.textContent,
            color: element.style.color,
            weight: element.style.fontWeight,
            bg: element.style.background
        };
    }
    element.textContent = message;
    row?.classList.remove('is-copy-failed', 'is-copied');
    if (status === 'success') {
        row?.classList.add('is-copied');
    } else if (status === 'error') {
        row?.classList.add('is-copy-failed');
    }
}

function resetCopyFeedback(element) {
    const row = element.closest('.click-id-row');
    const state = element._copyState;
    if (!state) {
        return;
    }
    element.textContent = state.text;
    row?.classList.remove('is-copy-failed', 'is-copied');
    delete element._copyState;
}

function copyClickId(element) {
    const clickId = element.getAttribute('data-click-id');

    function onCopySuccess() {
        showCopyFeedback(element, 'success', 'Copied!');
        setTimeout(function() { resetCopyFeedback(element); }, 2000);
    }

    function onCopyError() {
        showCopyFeedback(element, 'error', 'Failed');
        setTimeout(function() { resetCopyFeedback(element); }, 2000);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(clickId).then(onCopySuccess).catch(function() {
            const textArea = document.createElement('textarea');
            textArea.value = clickId;
            textArea.style.position = 'fixed';
            textArea.style.opacity = '0';
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                onCopySuccess();
            } catch (err) {
                onCopyError();
            }
            document.body.removeChild(textArea);
        });
    }
}
</script>
