<?php
/**
 * MaxMind GeoLite2 Database Downloader
 * 
 * This script downloads the GeoLite2-City.mmdb database from MaxMind
 * 
 * Usage:
 *   php scripts/download-geolite2.php [--account-id=YOUR_ACCOUNT_ID] [--license-key=YOUR_LICENSE_KEY] [--output=storage/GeoLite2-City.mmdb]
 * 
 * Or set environment variables:
 *   export MAXMIND_ACCOUNT_ID=your_account_id
 *   export MAXMIND_LICENSE_KEY=your_license_key
 *   php scripts/download-geolite2.php
 */

$accountId = null;
$licenseKey = null;
$outputPath = __DIR__ . '/../storage/GeoLite2-City.mmdb';

// Parse command line arguments
foreach ($argv as $arg) {
    if (strpos($arg, '--account-id=') === 0) {
        $accountId = substr($arg, 14);
    } elseif (strpos($arg, '--license-key=') === 0) {
        $licenseKey = substr($arg, 15);
    } elseif (strpos($arg, '--output=') === 0) {
        $outputPath = substr($arg, 9);
    }
}

// Check environment variables if not provided via CLI
if (empty($accountId)) {
    $accountId = getenv('MAXMIND_ACCOUNT_ID') ?: null;
}
if (empty($licenseKey)) {
    $licenseKey = getenv('MAXMIND_LICENSE_KEY') ?: null;
}

// If still not provided, prompt user
if (empty($accountId) || empty($licenseKey)) {
    echo "MaxMind GeoLite2 Database Downloader\n";
    echo "=====================================\n\n";
    
    if (empty($accountId)) {
        echo "To download GeoLite2, you need a free MaxMind account.\n";
        echo "1. Sign up at: https://www.maxmind.com/en/geolite2/signup\n";
        echo "2. Get your Account ID and License Key from: https://www.maxmind.com/en/accounts/current/license-key\n\n";
        
        $accountId = readline("Enter your MaxMind Account ID: ");
    }
    
    if (empty($licenseKey)) {
        $licenseKey = readline("Enter your MaxMind License Key: ");
    }
}

if (empty($accountId) || empty($licenseKey)) {
    echo "Error: Account ID and License Key are required.\n";
    echo "\nUsage:\n";
    echo "  php scripts/download-geolite2.php --account-id=YOUR_ID --license-key=YOUR_KEY\n";
    echo "\nOr set environment variables:\n";
    echo "  export MAXMIND_ACCOUNT_ID=your_id\n";
    echo "  export MAXMIND_LICENSE_KEY=your_key\n";
    echo "  php scripts/download-geolite2.php\n";
    exit(1);
}

// Ensure storage directory exists
$storageDir = dirname($outputPath);
if (!is_dir($storageDir)) {
    if (!mkdir($storageDir, 0755, true)) {
        echo "Error: Could not create storage directory: {$storageDir}\n";
        exit(1);
    }
}

echo "Downloading GeoLite2-City database...\n";
echo "Account ID: {$accountId}\n";
echo "Output: {$outputPath}\n\n";

// MaxMind download URL
$downloadUrl = sprintf(
    'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=%s&suffix=tar.gz',
    urlencode($licenseKey)
);

// Download the tar.gz file
$tempFile = sys_get_temp_dir() . '/GeoLite2-City.tar.gz';
echo "Downloading from MaxMind...\n";

$ch = curl_init($downloadUrl);
$fp = fopen($tempFile, 'wb');
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minute timeout
curl_setopt($ch, CURLOPT_USERAGENT, 'SimpleKUMA-GeoIP-Downloader/1.0');

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);

if (!$result || $httpCode !== 200) {
    echo "Error: Failed to download from MaxMind (HTTP {$httpCode})\n";
    echo "Please check your Account ID and License Key.\n";
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    exit(1);
}

echo "Extracting archive...\n";

// Extract the tar.gz file to a temporary directory
$extractDir = sys_get_temp_dir() . '/geolite2-extract-' . uniqid();
mkdir($extractDir, 0755, true);

$phar = new PharData($tempFile);
$phar->extractTo($extractDir);

// Find the .mmdb file in the extracted directory
$mmdbFile = null;

// Try to find the .mmdb file (it could be in a subdirectory)
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($extractDir),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'mmdb') {
        $mmdbFile = $file->getPathname();
        break;
    }
}

if (!$mmdbFile || !file_exists($mmdbFile)) {
    echo "Error: Could not find .mmdb file in downloaded archive.\n";
    echo "Extracted directory: {$extractDir}\n";
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    if (is_dir($extractDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($extractDir);
    }
    exit(1);
}

// Copy the .mmdb file to the output location
if (!copy($mmdbFile, $outputPath)) {
    echo "Error: Could not copy database file to: {$outputPath}\n";
    exit(1);
}

// Set permissions
chmod($outputPath, 0644);

// Clean up
unlink($tempFile);
if (is_dir($extractDir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($extractDir);
}

$fileSize = filesize($outputPath);
echo "\n✅ Success!\n";
echo "Database downloaded to: {$outputPath}\n";
echo "File size: " . number_format($fileSize / 1024 / 1024, 2) . " MB\n";
echo "\nThe system will now use MaxMind GeoLite2 for geolocation.\n";

