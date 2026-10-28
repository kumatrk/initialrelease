<?php
/**
 * NewsBreak cost updater — getIntegratedReport → newsbreak_campaign_hourly_costs.
 *
 * Usage: php scripts/newsbreak_cost_updater.php
 * Combined: scripts/kuma-traffic-api-cron.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\Cron\TrafficApiCronHooks;
use SimpleKuma\Entity\NewsBreakIntegration;
use SimpleKuma\Logger;
use SimpleKuma\NewsBreak\NewsBreakClickExtractor;
use SimpleKuma\NewsBreak\NewsBreakClient;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    error_log('NewsBreak Cost Updater: DB connection failed: ' . $db->connect_error);
    exit(1);
}
date_default_timezone_set('UTC');

$logger = new Logger();
$logger->logDetail('=== NewsBreak Cost Updater started ===', [
    'utc' => gmdate('Y-m-d H:i:s'),
]);

if (!TrafficApiCronHooks::boot($db, $logger, 'newsbreak_cost')) {
    exit(0);
}

$integrations = new NewsBreakIntegration($db);
if (!$integrations->tableExists()) {
    $logger->logDetail('NewsBreak tables missing — run Settings → Updates → Update database');
    exit(0);
}

$extractor = new NewsBreakClickExtractor($db);
$tsIds = $extractor->newsBreakTrafficSourceIds();
if ($tsIds === []) {
    $logger->logDetail('No NewsBreak traffic sources found');
    exit(0);
}

$active = $integrations->getActive();
if ($active === []) {
    $logger->logDetail('No active NewsBreak integrations');
    exit(0);
}

$lookbackDays = 2;
$utcTo = gmdate('Y-m-d H:i:s');
$utcFrom = gmdate('Y-m-d H:i:s', time() - ($lookbackDays * 86400));
$remoteMap = $extractor->remoteToKumaCampaignMap($tsIds, $utcFrom, $utcTo);
if ($remoteMap === []) {
    $logger->logDetail('No NewsBreak campaign tokens on recent clicks');
    // Still pull account-level report for yesterday/today so spend is ready when clicks appear
}

$upsert = $db->prepare(
    'INSERT INTO newsbreak_campaign_hourly_costs
     (integration_id, kuma_campaign_id, remote_campaign_id, remote_campaign_name, cost_date, cost_hour, spend, impressions, clicks, last_synced)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE
        remote_campaign_name = VALUES(remote_campaign_name),
        spend = VALUES(spend),
        impressions = VALUES(impressions),
        clicks = VALUES(clicks),
        last_synced = NOW()'
);
if (!$upsert) {
    error_log('NewsBreak Cost Updater: upsert prepare failed: ' . $db->error);
    exit(1);
}

$dates = [
    gmdate('Y-m-d', strtotime('-1 day')),
    gmdate('Y-m-d'),
];

foreach ($active as $row) {
    $id = (int) $row['id'];
    $full = $integrations->getById($id, true);
    if ($full === null || empty($full['access_token'])) {
        $logger->logDetail('NewsBreak integration missing token', ['id' => $id]);
        continue;
    }

    $client = new NewsBreakClient((string) $full['access_token'], $db);
    $tz = trim((string) ($full['timezone'] ?? 'UTC')) ?: 'UTC';
    $adAccount = trim((string) ($full['ad_account_id'] ?? ''));
    $filter = $adAccount !== '' ? 'AD_ACCOUNT' : null;
    $filterIds = $adAccount !== '' ? [$adAccount] : null;

    foreach ($dates as $day) {
        $report = $client->getIntegratedReport(
            $tz,
            $day,
            $day,
            ['DATE', 'HOUR', 'CAMPAIGN'],
            ['COST', 'IMPRESSION', 'CLICK'],
            $filter,
            $filterIds,
            'HOURLY'
        );
        if (!$report['ok']) {
            $logger->logDetail('NewsBreak report failed', [
                'integration_id' => $id,
                'day' => $day,
                'error' => $report['error'],
            ]);
            continue;
        }

        $written = 0;
        foreach ($report['rows'] as $r) {
            $remoteId = trim((string) ($r['campaignId'] ?? ''));
            if ($remoteId === '') {
                continue;
            }
            $costDate = substr((string) ($r['date'] ?? $day), 0, 10);
            $hourRaw = $r['hour'] ?? 0;
            $hour = is_numeric($hourRaw) ? (int) $hourRaw : (int) preg_replace('/\D+/', '', (string) $hourRaw);
            if ($hour < 0 || $hour > 23) {
                $hour = 0;
            }

            // API cost is cents → dollars
            $spendCents = (float) ($r['costDecimal'] ?? $r['cost'] ?? 0);
            $spend = round($spendCents / 100.0, 6);
            $impressions = (int) ($r['impression'] ?? 0);
            $clicks = (int) ($r['click'] ?? 0);
            $remoteName = isset($r['campaign']) ? (string) $r['campaign'] : null;

            $kumaIds = $remoteMap[$remoteId] ?? [];
            if ($kumaIds === []) {
                // No click mapping yet — skip (cannot attach to a Kuma campaign)
                continue;
            }

            if (count($kumaIds) === 1) {
                $kumaId = $kumaIds[0];
                $upsert->bind_param(
                    'iisssidii',
                    $id,
                    $kumaId,
                    $remoteId,
                    $remoteName,
                    $costDate,
                    $hour,
                    $spend,
                    $impressions,
                    $clicks
                );
                $upsert->execute();
                $written++;
                continue;
            }

            // Split spend across Kuma campaigns by click share that hour (UTC date/hour approx).
            $shares = [];
            $totalClicks = 0;
            foreach ($kumaIds as $kumaId) {
                $c = $extractor->countClicksForHour($kumaId, $remoteId, $costDate, $hour);
                $shares[$kumaId] = $c;
                $totalClicks += $c;
            }
            foreach ($kumaIds as $kumaId) {
                $part = $totalClicks > 0
                    ? round($spend * ($shares[$kumaId] / $totalClicks), 6)
                    : round($spend / count($kumaIds), 6);
                $partImp = $totalClicks > 0
                    ? (int) round($impressions * ($shares[$kumaId] / $totalClicks))
                    : (int) floor($impressions / count($kumaIds));
                $partClk = $shares[$kumaId];
                $upsert->bind_param(
                    'iisssidii',
                    $id,
                    $kumaId,
                    $remoteId,
                    $remoteName,
                    $costDate,
                    $hour,
                    $part,
                    $partImp,
                    $partClk
                );
                $upsert->execute();
                $written++;
            }
        }

        $logger->logDetail('NewsBreak report stored', [
            'integration_id' => $id,
            'day' => $day,
            'rows' => count($report['rows']),
            'written' => $written,
        ]);
    }
}

$upsert->close();
$logger->logDetail('=== NewsBreak Cost Updater finished ===');
exit(0);
