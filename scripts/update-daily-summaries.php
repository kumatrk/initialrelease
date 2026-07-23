<?php
/**
 * Update Daily Summary Tables Script
 * Pre-calculates daily aggregates to avoid scanning millions of clicks for reports.
 * On-write upserts (DailySummaryUpdater) are primary; this cron is the safety net.
 *
 * Production cron (recommended hourly):
 *   5 * * * * /usr/bin/php /path/to/simplekuma/scripts/update-daily-summaries.php >> /var/log/simplekuma-daily-summaries.log 2>&1
 *
 * Default: yesterday + today. Optional backfill:
 *   php scripts/update-daily-summaries.php --from=2026-07-01 --to=2026-07-10
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Stats\StatsViewExclusions;

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($db->connect_error) {
    error_log("Summary script: Database connection failed: " . $db->connect_error);
    exit(1);
}

// summary_date = UTC DATE(ts); keep session timezone aligned with writers (DailySummaryUpdater).
$db->query("SET time_zone = '+00:00'");

$opts = getopt('', ['from::', 'to::']);
if (!empty($opts['from']) && !empty($opts['to'])) {
    $datesToProcess = [];
    $cursor = strtotime($opts['from'] . ' UTC');
    $end = strtotime($opts['to'] . ' UTC');
    if ($cursor === false || $end === false || $cursor > $end) {
        fwrite(STDERR, "Invalid --from/--to dates\n");
        exit(1);
    }
    while ($cursor <= $end) {
        $datesToProcess[] = gmdate('Y-m-d', $cursor);
        $cursor = strtotime('+1 day', $cursor);
    }
} else {
    // Process yesterday and today in UTC (to catch any late-arriving conversions)
    $datesToProcess = [
        gmdate('Y-m-d', strtotime('-1 day')),
        gmdate('Y-m-d')
    ];
}

$excl = StatsViewExclusions::andClickWhereSql($db, 'cl');

echo "Updating daily summaries for: " . implode(', ', $datesToProcess) . "\n";

foreach ($datesToProcess as $summaryDate) {
    echo "Processing date: {$summaryDate}\n";

    $db->begin_transaction();

    try {
        $deleteStmt = $db->prepare('DELETE FROM clicks_daily_summary WHERE summary_date = ?');
        $deleteStmt->bind_param('s', $summaryDate);
        $deleteStmt->execute();
        $deleteStmt->close();

        // WHERE exclusions match DailySummaryUpdater / list / dashboard (FB approval + hidden IPs).
        // COUNT(DISTINCT id) after filter keeps parity with historical CASE semantics.
        $insertStmt = $db->prepare("
            INSERT INTO clicks_daily_summary
            (campaign_id, traffic_source_id, offer_id, landing_page_id, summary_date,
             clicks, lp_clicks, direct_clicks, conversions, revenue, cost, profit, roi)
            SELECT
                cl.campaign_id,
                cl.traffic_source_id,
                cl.offer_id,
                cl.landing_page_id,
                DATE(cl.ts) as summary_date,
                COUNT(DISTINCT cl.id) as clicks,
                COUNT(DISTINCT CASE
                    WHEN cl.lp_click = 1 AND cl.landing_page_id IS NOT NULL THEN cl.id
                END) as lp_clicks,
                COUNT(DISTINCT CASE
                    WHEN cl.lp_click = 1 AND cl.landing_page_id IS NULL THEN cl.id
                END) as direct_clicks,
                COUNT(DISTINCT conv.id) as conversions,
                COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) as revenue,
                COALESCE(SUM(cl.cost), 0) as cost,
                COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) - COALESCE(SUM(cl.cost), 0) as profit,
                CASE
                    WHEN COALESCE(SUM(cl.cost), 0) > 0
                    THEN ((COALESCE(SUM(COALESCE(conv.payout, conv.value)), 0) - COALESCE(SUM(cl.cost), 0)) / COALESCE(SUM(cl.cost), 0)) * 100
                    ELSE NULL
                END as roi
            FROM (
                SELECT * FROM clicks WHERE DATE(ts) = ?
                UNION ALL
                SELECT * FROM clicks_archive WHERE DATE(ts) = ?
            ) as cl
            LEFT JOIN conversions conv ON cl.click_id = conv.click_id
            WHERE 1=1
              {$excl}
            GROUP BY cl.campaign_id, cl.traffic_source_id, cl.offer_id, cl.landing_page_id, DATE(cl.ts)
            ON DUPLICATE KEY UPDATE
                clicks = VALUES(clicks),
                lp_clicks = VALUES(lp_clicks),
                direct_clicks = VALUES(direct_clicks),
                conversions = VALUES(conversions),
                revenue = VALUES(revenue),
                cost = VALUES(cost),
                profit = VALUES(profit),
                roi = VALUES(roi),
                updated_at = NOW()
        ");
        $insertStmt->bind_param('ss', $summaryDate, $summaryDate);
        $insertStmt->execute();
        $inserted = $insertStmt->affected_rows;
        $insertStmt->close();

        $db->commit();

        echo "  Updated {$inserted} summary rows for {$summaryDate}\n";
    } catch (Exception $e) {
        $db->rollback();
        error_log('Summary script: Error processing ' . $summaryDate . ': ' . $e->getMessage());
        echo '  Error processing ' . $summaryDate . ': ' . $e->getMessage() . "\n";
    }
}

$db->close();
echo "Daily summary update completed.\n";
exit(0);
