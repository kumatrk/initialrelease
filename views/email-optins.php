<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Utils/Formatter.php';
require_once __DIR__ . '/../src/Stats/EmailOptinStatsService.php';
require_once __DIR__ . '/../src/Stats/ConversionsQueryService.php';

use SimpleKuma\Stats\ConversionsQueryService;
use SimpleKuma\Stats\EmailOptinStatsService;
use SimpleKuma\Utils\Formatter;

function renderEmailOptinLookupLink(string $clickId): string
{
    $url = APP_BASE_URL . '/index.php?page=click-lookup&click_id=' . rawurlencode($clickId);
    $icon = ASSETS_BASE_URL . '/assets/images/clicklookbear.png';
    return sprintf(
        '<a href="%s" class="click-lookup-link" title="Look up this click">'
        . '<img src="%s" alt="" width="22" height="22"></a>',
        htmlspecialchars($url),
        htmlspecialchars($icon)
    );
}

$db = $GLOBALS['db'] ?? new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$userTimezone = $GLOBALS['userTimezone'] ?? 'UTC';
$userCurrency = $GLOBALS['userCurrency'] ?? 'USD';

$pageNum = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$perPage = isset($_GET['per_page_limit']) ? (int) $_GET['per_page_limit'] : 50;
$perPage = in_array($perPage, [50, 100, 200], true) ? $perPage : 50;

$campaignFilter = isset($_GET['campaign']) && $_GET['campaign'] !== '' ? (int) $_GET['campaign'] : null;
if ($campaignFilter === 0) {
    $campaignFilter = null;
}

$todayInUserTz = Formatter::getTodayInTimezone($userTimezone);
if (!isset($_GET['date_from']) && !isset($_GET['date_to'])) {
    $dateFrom = $todayInUserTz;
    $dateTo = $todayInUserTz;
} else {
    $dateFrom = $_GET['date_from'] ?? $todayInUserTz;
    $dateTo = $_GET['date_to'] ?? $todayInUserTz;
}

$overview = (new EmailOptinStatsService($db))->overview($campaignFilter, $dateFrom, $dateTo, $userTimezone);
$kpis = $overview['kpis'];
$campaignRows = $overview['campaigns'];
$days = $overview['days'];

$log = (new ConversionsQueryService($db))->listConversionsForLog(
    $campaignFilter,
    $dateFrom,
    $dateTo,
    $userTimezone,
    $pageNum,
    $perPage,
    null,
    null,
    'optins'
);
$events = $log['rows'];
$totalRows = $log['total'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$campaigns = $db->query('SELECT id, name FROM campaigns ORDER BY name')->fetch_all(MYSQLI_ASSOC);

if (!isset($GLOBALS['db'])) {
    $db->close();
}

$activePreset = null;
try {
    $tz = new DateTimeZone($userTimezone);
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
    $last7Start = (clone $now)->modify('-6 days')->format('Y-m-d');
    $last14Start = (clone $now)->modify('-13 days')->format('Y-m-d');
    $last30Start = (clone $now)->modify('-29 days')->format('Y-m-d');
    $lastMonthStart = (clone $now)->modify('first day of last month')->format('Y-m-d');
    $lastMonthEnd = (clone $now)->modify('last day of last month')->format('Y-m-d');
    $thisMonthStart = (clone $now)->modify('first day of this month')->format('Y-m-d');
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

$chartDays = $days;
if (count($chartDays) > 45) {
    $chartDays = array_slice($chartDays, -31);
}
$maxOptins = 1;
foreach ($chartDays as $dayRow) {
    $maxOptins = max($maxOptins, (int) $dayRow['optins']);
}

function emailOptinPresetClass(?string $active, string $key): string
{
    return $active === $key ? 'visitor-date-preset is-active' : 'visitor-date-preset';
}
?>

<div class="email-optins-page">
    <div class="page-header">
        <h1 class="page-title">Email Opt-ins</h1>
        <p class="page-description">Opt-in rate and lead events for email campaigns.</p>
    </div>

    <div class="card email-optins-filters" style="margin-bottom: 24px;">
        <div class="card-body">
            <div class="visitor-date-presets" style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px;">
                <button type="button" class="<?= emailOptinPresetClass($activePreset, 'today') ?>" onclick="setEmailOptinDate('today')">Today</button>
                <button type="button" class="<?= emailOptinPresetClass($activePreset, 'yesterday') ?>" onclick="setEmailOptinDate('yesterday')">Yesterday</button>
                <button type="button" class="<?= emailOptinPresetClass($activePreset, 'last7') ?>" onclick="setEmailOptinDate('last7')">7d</button>
                <button type="button" class="<?= emailOptinPresetClass($activePreset, 'last14') ?>" onclick="setEmailOptinDate('last14')">14d</button>
                <button type="button" class="<?= emailOptinPresetClass($activePreset, 'last30') ?>" onclick="setEmailOptinDate('last30')">30d</button>
                <button type="button" class="<?= emailOptinPresetClass($activePreset, 'lastmonth') ?>" onclick="setEmailOptinDate('lastmonth')">Last Mo</button>
                <button type="button" class="<?= emailOptinPresetClass($activePreset, 'thismonth') ?>" onclick="setEmailOptinDate('thismonth')">This Mo</button>
                <button type="button" class="<?= emailOptinPresetClass($activePreset, 'alltime') ?>" onclick="setEmailOptinDate('alltime')">ALL TIME</button>
            </div>
            <form method="get" action="" id="email-optin-date-form" class="email-optins-filter-form">
                <input type="hidden" name="page" value="email-optins">
                <div>
                    <label for="email_optin_date_from">Date From</label>
                    <input type="date" name="date_from" id="email_optin_date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div>
                    <label for="email_optin_date_to">Date To</label>
                    <input type="date" name="date_to" id="email_optin_date_to" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div>
                    <label for="email_optin_campaign">Campaign</label>
                    <select name="campaign" id="email_optin_campaign">
                        <option value="">All Campaigns</option>
                        <?php foreach ($campaigns as $camp): ?>
                            <option value="<?= (int) $camp['id'] ?>" <?= $campaignFilter === (int) $camp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($camp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="email_optin_per_page">Per Page</label>
                    <select name="per_page_limit" id="email_optin_per_page">
                        <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
                        <option value="200" <?= $perPage === 200 ? 'selected' : '' ?>>200</option>
                    </select>
                </div>
                <div class="email-optins-filters__apply">
                    <label aria-hidden="true">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>
        </div>
    </div>

    <div class="email-optins-kpis">
        <div class="email-optins-kpi">
            <div class="email-optins-kpi__head">
                <span class="email-optins-kpi__icon email-optins-kpi__icon--visitors" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <p class="email-optins-kpi__label">Visitors</p>
            </div>
            <p class="email-optins-kpi__value"><?= number_format($kpis['visitors']) ?></p>
            <p class="email-optins-kpi__hint">Clicks in this range</p>
        </div>
        <div class="email-optins-kpi">
            <div class="email-optins-kpi__head">
                <span class="email-optins-kpi__icon email-optins-kpi__icon--optins" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                </span>
                <p class="email-optins-kpi__label">Opt-ins</p>
            </div>
            <p class="email-optins-kpi__value"><?= number_format($kpis['optins']) ?></p>
            <p class="email-optins-kpi__hint">Lead events (optin / lead / email / subscribe)</p>
        </div>
        <div class="email-optins-kpi">
            <div class="email-optins-kpi__head">
                <span class="email-optins-kpi__icon email-optins-kpi__icon--rate" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20V8"/></svg>
                </span>
                <p class="email-optins-kpi__label">Opt-in %</p>
            </div>
            <p class="email-optins-kpi__value"><?= number_format($kpis['optin_rate'], 2) ?>%</p>
            <p class="email-optins-kpi__hint">Opt-ins ÷ visitors</p>
        </div>
        <div class="email-optins-kpi">
            <div class="email-optins-kpi__head">
                <span class="email-optins-kpi__icon email-optins-kpi__icon--cost" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v10"/><path d="M15 10c0-1.1-1.3-2-3-2s-3 .9-3 2 1.3 2 3 2 3 .9 3 2-1.3 2-3 2-3-.9-3-2"/></svg>
                </span>
                <p class="email-optins-kpi__label">Cost / opt-in</p>
            </div>
            <p class="email-optins-kpi__value"><?= $kpis['optins'] > 0 ? Formatter::formatCurrency($kpis['cost_per_optin'], $userCurrency) : '—' ?></p>
            <p class="email-optins-kpi__hint">Traffic cost ÷ opt-ins</p>
        </div>
    </div>

    <?php if ($chartDays !== []): ?>
    <?php $chartMid = (int) round($maxOptins / 2); ?>
    <div class="card email-optins-chart-card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h2 class="card-title">Opt-ins by day</h2>
            <span class="email-optins-chart-meta"><?= number_format($kpis['optins']) ?> in range</span>
        </div>
        <div class="card-body">
            <p class="email-optins-chart-legend">
                <span class="email-optins-chart-legend__swatch" aria-hidden="true"></span>
                Each bar is that day’s opt-in count. A full bar is the busiest day
                (<?= number_format($maxOptins) ?> opt-ins).
            </p>
            <div class="email-optins-chart">
                <div class="email-optins-chart__yaxis" aria-hidden="true">
                    <span><?= number_format($maxOptins) ?></span>
                    <span><?= number_format($chartMid) ?></span>
                    <span>0</span>
                </div>
                <div class="email-optins-chart__plot">
                    <div class="email-optins-chart__grid" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                    <div class="email-optins-bars">
                        <?php foreach ($chartDays as $dayRow): ?>
                            <?php
                            $dayOptins = (int) $dayRow['optins'];
                            $pct = $maxOptins > 0 ? round(($dayOptins / $maxOptins) * 100, 2) : 0;
                            ?>
                            <div class="email-optins-bars__col" title="<?= htmlspecialchars($dayRow['day']) ?>: <?= number_format($dayOptins) ?> opt-ins">
                                <span class="email-optins-bars__count"><?= $dayOptins > 0 ? (string) $dayOptins : '' ?></span>
                                <div class="email-optins-bars__track">
                                    <div class="email-optins-bars__bar<?= $dayOptins === 0 ? ' is-empty' : '' ?>" style="height: <?= $dayOptins > 0 ? $pct : 0 ?>%;"></div>
                                </div>
                                <div class="email-optins-bars__label"><?= htmlspecialchars(substr($dayRow['day'], 5)) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header"><h2 class="card-title">By campaign</h2></div>
        <div class="card-body">
            <?php if ($campaignRows === []): ?>
                <p class="email-optin-card__empty">No visitors or opt-ins in this range.</p>
            <?php else: ?>
                <div class="email-optins-table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Campaign</th>
                                <th class="num">Visitors</th>
                                <th class="num">Opt-ins</th>
                                <th>Opt-in %</th>
                                <th class="num">Cost / opt-in</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaignRows as $row): ?>
                                <?php $rateWidth = max(0, min(100, (float) $row['optin_rate'])); ?>
                                <tr>
                                    <td>
                                        <a href="?page=email-optins&amp;campaign=<?= (int) $row['id'] ?>&amp;date_from=<?= htmlspecialchars($dateFrom) ?>&amp;date_to=<?= htmlspecialchars($dateTo) ?>">
                                            <?= htmlspecialchars($row['name']) ?>
                                        </a>
                                    </td>
                                    <td class="num"><?= number_format($row['visitors']) ?></td>
                                    <td class="num"><?= number_format($row['optins']) ?></td>
                                    <td>
                                        <div class="email-optins-rate">
                                            <span class="email-optins-rate__value"><?= number_format($row['optin_rate'], 2) ?>%</span>
                                            <div class="email-optins-rate__track" aria-hidden="true">
                                                <span class="email-optins-rate__fill" style="width: <?= $rateWidth ?>%;"></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="num"><?= $row['optins'] > 0 ? Formatter::formatCurrency($row['cost_per_optin'], $userCurrency) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">Opt-in events (<?= number_format($totalRows) ?>)</h2></div>
        <div class="card-body">
            <?php if ($events === []): ?>
                <p class="email-optin-card__empty">No opt-in events in this range. Fire a postback with event key optin, email, lead, or subscribe.</p>
            <?php else: ?>
                <div class="email-optins-table-wrap">
                    <table class="table conversion-log-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Click</th>
                                <th>Campaign</th>
                                <th>Event</th>
                                <th>Offer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $conv): ?>
                                <tr>
                                    <td class="conversion-nowrap"><?= htmlspecialchars(Formatter::formatDateTime($conv['ts'], $userTimezone)) ?></td>
                                    <td>
                                        <div class="click-id-row">
                                            <code><?= htmlspecialchars($conv['click_id']) ?></code>
                                            <?= renderEmailOptinLookupLink($conv['click_id']) ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($conv['campaign_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars((string) ($conv['event_key'] ?? 'optin')) ?></td>
                                    <td><?= htmlspecialchars($conv['offer_name'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <div style="margin-top: 16px; display: flex; gap: 8px; justify-content: center;">
        <?php if ($pageNum > 1): ?>
            <a class="btn btn-outline" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => 'email-optins', 'p' => $pageNum - 1]))) ?>">Previous</a>
        <?php endif; ?>
        <span style="padding: 8px;">Page <?= $pageNum ?> of <?= $totalPages ?></span>
        <?php if ($pageNum < $totalPages): ?>
            <a class="btn btn-outline" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => 'email-optins', 'p' => $pageNum + 1]))) ?>">Next</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
const emailOptinPresets = {
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
function setEmailOptinDate(preset) {
    const from = document.getElementById('email_optin_date_from');
    const to = document.getElementById('email_optin_date_to');
    if (preset === 'today') { from.value = emailOptinPresets.today; to.value = emailOptinPresets.today; }
    else if (preset === 'yesterday') { from.value = emailOptinPresets.yesterday; to.value = emailOptinPresets.yesterday; }
    else if (preset === 'last7') { from.value = emailOptinPresets.last7Start; to.value = emailOptinPresets.today; }
    else if (preset === 'last14') { from.value = emailOptinPresets.last14Start; to.value = emailOptinPresets.today; }
    else if (preset === 'last30') { from.value = emailOptinPresets.last30Start; to.value = emailOptinPresets.today; }
    else if (preset === 'lastmonth') { from.value = emailOptinPresets.lastMonthStart; to.value = emailOptinPresets.lastMonthEnd; }
    else if (preset === 'thismonth') { from.value = emailOptinPresets.thisMonthStart; to.value = emailOptinPresets.today; }
    else if (preset === 'alltime') { from.value = emailOptinPresets.allTimeStart; to.value = emailOptinPresets.today; }
    document.getElementById('email-optin-date-form').submit();
}
</script>
