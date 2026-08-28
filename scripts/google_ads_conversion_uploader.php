<?php
/**
 * Google Ads Conversion Upload Retry Cron
 *
 * Retries queued ConversionUploadService pushes (6h click indexing window, transient errors).
 *
 * Usage: php scripts/google_ads_conversion_uploader.php
 * Cron:  every 15 min: /usr/bin/php /path/to/scripts/google_ads_conversion_uploader.php >> /var/log/google_ads_conversion_uploader.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SimpleKuma\GoogleAds\GoogleAdsConversionUploader;
use SimpleKuma\Logger;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_error) {
    error_log('Google Ads Conversion Uploader: DB connection failed: ' . $db->connect_error);
    exit(1);
}

date_default_timezone_set('UTC');
$logger = new Logger();
$logger->logDetail('=== Google Ads Conversion Uploader Cron Started ===', [
    'date' => date('Y-m-d H:i:s'),
]);

$uploader = new GoogleAdsConversionUploader($db);
$stats = $uploader->processPendingQueue(50);

$logger->logDetail('Google Ads Conversion Uploader finished', $stats);
$db->close();
exit(0);
