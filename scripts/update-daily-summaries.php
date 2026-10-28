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
 *
 * Fast-path / retention safety:
 *   If a date has zero rows in clicks + clicks_archive (raw purged), SKIP the DELETE+rebuild
 *   so historical clicks_daily_summary rows survive age-based purge.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Stats\CampaignStatsExpressions;
use SimpleKuma\Stats\StatsViewExclusions;
use SimpleKuma\Tracking\ConversionOptInClassifier;

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

$hasArchive = false;
$archiveCheck = $db->query("SHOW TABLES LIKE 'clicks_archive'");
if ($archiveCheck && $archiveCheck->num_rows > 0) {
    $hasArchive = true;
}

echo "Updating daily summaries for: " . implode(', ', $datesToProcess) . "\n";

foreach ($datesToProcess as $summaryDate) {
    echo "Processing date: {$summaryDate}\n";

    $rawCount = 0;
    $countStmt = $db->prepare('SELECT COUNT(*) AS c FROM clicks WHERE DATE(ts) = ?');
    $countStmt->bind_param('s', $summaryDate);
    $countStmt->execute();
    $rawCount += (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $countStmt->close();

    if ($hasArchive) {
        $archStmt = $db->prepare('SELECT COUNT(*) AS c FROM clicks_archive WHERE DATE(ts) = ?');
        $archStmt->bind_param('s', $summaryDate);
        $archStmt->execute();
        $rawCount += (int) ($archStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $archStmt->close();
    }

    if ($rawCount === 0) {
        $summaryExists = 0;
        $sumStmt = $db->prepare(
            'SELECT COALESCE(SUM(clicks), 0) AS c FROM clicks_daily_summary WHERE summary_date = ?'
        );
        if ($sumStmt) {
            $sumStmt->bind_param('s', $summaryDate);
            $sumStmt->execute();
            $summaryExists = (int) ($sumStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $sumStmt->close();
        }
        if ($summaryExists > 0) {
            echo "  Skip {$summaryDate}: no raw/archive rows but summary has {$summaryExists} clicks — preserving pre-agg history.\n";
        } else {
            echo "  Skip {$summaryDate}: no raw/archive rows and no summary to rebuild.\n";
        }
        continue;
    }

    $db->begin_transaction();

    try {
        $deleteStmt = $db->prepare('DELETE FROM clicks_daily_summary WHERE summary_date = ?');
        $deleteStmt->bind_param('s', $summaryDate);
        $deleteStmt->execute();
        $deleteStmt->close();

        // WHERE exclusions match DailySummaryUpdater / list / dashboard (FB approval + hidden IPs).
        // Rebuild must preserve bot_clicks + optins (same split as on-write DailySummaryUpdater).
        $fromSql = $hasArchive
            ? '(SELECT * FROM clicks WHERE DATE(ts) = ?
                UNION ALL
                SELECT * FROM clicks_archive WHERE DATE(ts) = ?) as cl'
            : '(SELECT * FROM clicks WHERE DATE(ts) = ?) as cl';

        $hasBotClicksCol = false;
        $hasOptinsCol = false;
        $colBot = $db->query("SHOW COLUMNS FROM clicks_daily_summary LIKE 'bot_clicks'");
        if ($colBot && $colBot->num_rows > 0) {
            $hasBotClicksCol = true;
        }
        $colOpt = $db->query("SHOW COLUMNS FROM clicks_daily_summary LIKE 'optins'");
        if ($colOpt && $colOpt->num_rows > 0) {
            $hasOptinsCol = true;
        }

        $fbUa = CampaignStatsExpressions::FACEBOOK_CRAWLER_UA_FRAGMENT;
        $metaUa = CampaignStatsExpressions::META_EXTERNAL_ADS_UA_FRAGMENT;
        $botCond = "(cl.ua LIKE '%{$fbUa}%' OR cl.ua LIKE '%{$metaUa}%'
            OR cl.extra_json LIKE '%\"classification\":\"known_bot\"%'
            OR cl.extra_json LIKE '%\"classification\":\"suspected_bot\"%'
            OR cl.extra_json LIKE '%\"exclude_from_stats\":true%')";

        $optInList = ConversionOptInClassifier::sqlInList();
        if ($hasOptinsCol) {
            $convJoin = "LEFT JOIN (
                SELECT click_id,
                       SUM(CASE WHEN event_key IS NULL OR event_key NOT IN ({$optInList}) THEN 1 ELSE 0 END) AS conversion_count,
                       SUM(CASE WHEN event_key IN ({$optInList}) THEN 1 ELSE 0 END) AS optin_count,
                       SUM(CASE WHEN event_key IS NULL OR event_key NOT IN ({$optInList}) THEN COALESCE(payout, value) ELSE 0 END) AS revenue_sum
                FROM conversions
                GROUP BY click_id
            ) conv ON conv.click_id = cl.click_id";
        } else {
            $convJoin = CampaignStatsExpressions::conversionsCountJoinAllEvents();
        }

        $clicksExpr = $hasBotClicksCol
            ? "COUNT(DISTINCT CASE WHEN NOT {$botCond} THEN cl.id END)"
            : 'COUNT(DISTINCT cl.id)';
        $lpExpr = $hasBotClicksCol
            ? "COUNT(DISTINCT CASE WHEN NOT {$botCond} AND cl.lp_click = 1 AND cl.landing_page_id IS NOT NULL THEN cl.id END)"
            : 'COUNT(DISTINCT CASE WHEN cl.lp_click = 1 AND cl.landing_page_id IS NOT NULL THEN cl.id END)';
        $directExpr = $hasBotClicksCol
            ? "COUNT(DISTINCT CASE WHEN NOT {$botCond} AND cl.lp_click = 1 AND cl.landing_page_id IS NULL THEN cl.id END)"
            : 'COUNT(DISTINCT CASE WHEN cl.lp_click = 1 AND cl.landing_page_id IS NULL THEN cl.id END)';
        $botExpr = $hasBotClicksCol
            ? "COUNT(DISTINCT CASE WHEN {$botCond} THEN cl.id END)"
            : null;
        $optinsExpr = $hasOptinsCol ? 'COALESCE(SUM(conv.optin_count), 0)' : null;

        // Column order matches table: … conversions, optins, revenue… then bot_clicks after optins in schema,
        // but INSERT lists only selected columns so order must match SELECT exactly.
        $insertCols = [
            'campaign_id',
            'traffic_source_id',
            'offer_id',
            'landing_page_id',
            'summary_date',
            'clicks',
            'lp_clicks',
            'direct_clicks',
            'conversions',
        ];
        $selectParts = [
            'cl.campaign_id',
            'cl.traffic_source_id',
            'cl.offer_id',
            'cl.landing_page_id',
            'DATE(cl.ts) as summary_date',
            "{$clicksExpr} as clicks",
            "{$lpExpr} as lp_clicks",
            "{$directExpr} as direct_clicks",
            'COALESCE(SUM(conv.conversion_count), 0) as conversions',
        ];
        $dupParts = [
            'clicks = VALUES(clicks)',
            'lp_clicks = VALUES(lp_clicks)',
            'direct_clicks = VALUES(direct_clicks)',
            'conversions = VALUES(conversions)',
        ];

        if ($hasOptinsCol) {
            $insertCols[] = 'optins';
            $selectParts[] = "{$optinsExpr} as optins";
            $dupParts[] = 'optins = VALUES(optins)';
        }

        $roiSql = CampaignStatsExpressions::storedRoiPercentSql(
            'COALESCE(SUM(conv.revenue_sum), 0)',
            'COALESCE(SUM(cl.cost), 0)'
        );
        $insertCols = array_merge($insertCols, ['revenue', 'cost', 'profit', 'roi']);
        $selectParts = array_merge($selectParts, [
            'COALESCE(SUM(conv.revenue_sum), 0) as revenue',
            'COALESCE(SUM(cl.cost), 0) as cost',
            'COALESCE(SUM(conv.revenue_sum), 0) - COALESCE(SUM(cl.cost), 0) as profit',
            "{$roiSql} as roi",
        ]);
        $dupParts = array_merge($dupParts, [
            'revenue = VALUES(revenue)',
            'cost = VALUES(cost)',
            'profit = VALUES(profit)',
            'roi = VALUES(roi)',
        ]);

        if ($hasBotClicksCol) {
            $insertCols[] = 'bot_clicks';
            $selectParts[] = "{$botExpr} as bot_clicks";
            $dupParts[] = 'bot_clicks = VALUES(bot_clicks)';
        }

        $dupParts[] = 'updated_at = NOW()';
        $insertColSql = implode(', ', $insertCols);
        $selectSql = implode(",\n                ", $selectParts);
        $dupSql = implode(",\n                ", $dupParts);

        $insertStmt = $db->prepare("
            INSERT INTO clicks_daily_summary
            ({$insertColSql})
            SELECT
                {$selectSql}
            FROM {$fromSql}
            {$convJoin}
            WHERE 1=1
              {$excl}
            GROUP BY cl.campaign_id, cl.traffic_source_id, cl.offer_id, cl.landing_page_id, DATE(cl.ts)
            ON DUPLICATE KEY UPDATE
                {$dupSql}
        ");
        if ($hasArchive) {
            $insertStmt->bind_param('ss', $summaryDate, $summaryDate);
        } else {
            $insertStmt->bind_param('s', $summaryDate);
        }
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
