<?php

/**
 * GeoIP Database Downloader
 * 
 * Downloads all three free geolocation databases:
 * - DB-IP Lite (MMDB) - Direct download
 * - IP2Location LITE (CSV) - Direct download (requires conversion to BIN)
 * - IPinfo DB-Lite (MMDB) - Direct download
 * 
 * Usage:
 *   php scripts/download-geoip-databases.php [--dbip] [--ip2location] [--ipinfo] [--all]
 * 
 * Options:
 *   --dbip        Download only DB-IP Lite
 *   --ip2location Download only IP2Location LITE (CSV format)
 *   --ipinfo      Download only IPinfo DB-Lite
 *   --all         Download all databases (default)
 *   --output      Output directory (default: ./geoip/)
 * 
 * Note: IP2Location downloads CSV format. The IP2Location PHP library requires BIN format.
 *       You can convert CSV to BIN using IP2Location's conversion tools, or use the CSV
 *       with a custom CSV reader (not currently implemented).
 */

declare(strict_types=1);

// Parse command line arguments
$options = getopt('', ['dbip', 'ip2location', 'ipinfo', 'all', 'output:']);
$downloadAll = !isset($options['dbip']) && !isset($options['ip2location']) && !isset($options['ipinfo']) || isset($options['all']);
$outputDir = $options['output'] ?? __DIR__ . '/../geoip/';

// Ensure output directory exists
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

echo "GeoIP Database Downloader\n";
echo "========================\n\n";

$errors = [];

// Download DB-IP Lite
if ($downloadAll || isset($options['dbip'])) {
    echo "Downloading DB-IP Lite...\n";
    try {
        downloadDBIP($outputDir);
        echo "✓ DB-IP Lite downloaded successfully\n\n";
    } catch (Exception $e) {
        $errors[] = "DB-IP Lite: " . $e->getMessage();
        echo "✗ DB-IP Lite failed: " . $e->getMessage() . "\n\n";
    }
}

// Download IP2Location LITE
if ($downloadAll || isset($options['ip2location'])) {
    echo "Downloading IP2Location LITE...\n";
    try {
        downloadIP2Location($outputDir);
        echo "✓ IP2Location LITE downloaded successfully\n";
        echo "⚠ NOTE: Downloaded CSV format. IP2Location PHP library requires BIN format.\n";
        echo "   You can convert using IP2Location tools or download BIN manually.\n\n";
    } catch (Exception $e) {
        $errors[] = "IP2Location LITE: " . $e->getMessage();
        echo "✗ IP2Location LITE failed: " . $e->getMessage() . "\n";
        echo "   Manual download: https://lite.ip2location.com/database-download\n\n";
    }
}

// Download IPinfo DB-Lite
if ($downloadAll || isset($options['ipinfo'])) {
    echo "Downloading IPinfo DB-Lite...\n";
    try {
        downloadIPinfo($outputDir);
        echo "✓ IPinfo DB-Lite downloaded successfully\n\n";
    } catch (Exception $e) {
        $errors[] = "IPinfo DB-Lite: " . $e->getMessage();
        echo "✗ IPinfo DB-Lite failed: " . $e->getMessage() . "\n\n";
    }
}

// Summary
if (empty($errors)) {
    echo "All downloads completed successfully!\n";
} else {
    echo "Some downloads failed:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

/**
 * Download DB-IP Lite database
 * Official URL: https://db-ip.com/db/download/ip-to-city-lite
 */
function downloadDBIP(string $outputDir): void
{
    // DB-IP Lite direct download URL (monthly updated)
    // Try current month first, fallback to previous month if not available
    $currentMonth = date('Y-m');
    $previousMonth = date('Y-m', strtotime('-1 month'));
    
    $urls = [
        "https://download.db-ip.com/free/dbip-city-lite-{$currentMonth}.mmdb.gz",
        "https://download.db-ip.com/free/dbip-city-lite-{$previousMonth}.mmdb.gz",
    ];
    
    $outputFile = $outputDir . '/DBIP-City-Lite.mmdb';
    $tempFile = $outputDir . '/dbip-city-lite.mmdb.gz';
    
    $data = false;
    $usedUrl = '';
    
    foreach ($urls as $url) {
        echo "  Trying: {$url}\n";
        $data = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'SimpleKUMA-GeoIP-Downloader/1.0',
            ]
        ]));
        
        if ($data !== false && strlen($data) > 1000) {
            $usedUrl = $url;
            break;
        }
    }
    
    if ($data === false || strlen($data) < 1000) {
        throw new Exception("Failed to download from DB-IP. Try manual download: https://db-ip.com/db/download/ip-to-city-lite");
    }
    
    // Save compressed file
    file_put_contents($tempFile, $data);
    echo "  Downloaded: " . number_format(strlen($data) / 1024 / 1024, 2) . " MB\n";
    
    // Decompress
    echo "  Decompressing...\n";
    $gz = gzopen($tempFile, 'rb');
    if ($gz === false) {
        unlink($tempFile);
        throw new Exception("Failed to open gzip file");
    }
    
    $decompressed = '';
    while (!gzeof($gz)) {
        $decompressed .= gzread($gz, 8192);
    }
    gzclose($gz);
    
    // Save decompressed file
    file_put_contents($outputFile, $decompressed);
    echo "  Decompressed: " . number_format(strlen($decompressed) / 1024 / 1024, 2) . " MB\n";
    
    // Clean up
    unlink($tempFile);
    
    // Verify file
    if (!file_exists($outputFile) || filesize($outputFile) < 1000000) { // At least 1MB
        throw new Exception("Downloaded file appears to be invalid (too small)");
    }
    
    // Set permissions
    chmod($outputFile, 0644);
    echo "  Saved to: {$outputFile}\n";
}

/**
 * Download IP2Location LITE database (CSV format)
 * Official URL: https://lite.ip2location.com/database-download
 * Note: IP2Location PHP library requires BIN format, CSV needs conversion
 * 
 * IMPORTANT: IP2Location may not provide direct download links. This function
 * attempts to download, but manual download may be required.
 */
function downloadIP2Location(string $outputDir): void
{
    // Check if ZipArchive extension is available
    if (!class_exists('ZipArchive')) {
        throw new Exception("ZipArchive extension is required for IP2Location download. Manual download: https://lite.ip2location.com/database-download");
    }
    
    // IP2Location LITE CSV download (attempt direct link)
    // Note: This may not work if they require form submission
    $url = "https://download.ip2location.com/lite/IP2LOCATION-LITE-DB11.CSV.ZIP";
    $outputFile = $outputDir . '/IP2LOCATION-LITE-DB11.CSV';
    $tempZip = $outputDir . '/ip2location-temp.zip';
    
    echo "  Attempting download from: {$url}\n";
    echo "  NOTE: IP2Location may require manual download from their website.\n";
    
    $data = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'timeout' => 60,
            'user_agent' => 'SimpleKUMA-GeoIP-Downloader/1.0',
            'follow_location' => true,
        ]
    ]));
    
    if ($data === false || strlen($data) < 1000) {
        // If direct download fails, provide instructions
        throw new Exception("Direct download not available. Please download manually from: https://lite.ip2location.com/database-download\n" .
                          "   Download the CSV file and place it in: {$outputDir}\n" .
                          "   Note: The IP2Location PHP library requires BIN format.\n" .
                          "   You may need to convert CSV to BIN or download BIN format if available.");
    }
    
    // Save ZIP file
    file_put_contents($tempZip, $data);
    echo "  Downloaded ZIP: " . number_format(strlen($data) / 1024 / 1024, 2) . " MB\n";
    
    // Extract ZIP
    echo "  Extracting ZIP...\n";
    $zip = new ZipArchive();
    if ($zip->open($tempZip) === true) {
        // Find the CSV file in the ZIP
        $csvFound = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (preg_match('/IP2LOCATION-LITE-DB11\.CSV$/i', $filename)) {
                $zip->extractTo($outputDir, $filename);
                // Rename to standard name
                $extractedPath = $outputDir . '/' . basename($filename);
                if (file_exists($extractedPath) && $extractedPath !== $outputFile) {
                    rename($extractedPath, $outputFile);
                }
                $csvFound = true;
                break;
            }
        }
        $zip->close();
        
        if (!$csvFound) {
            unlink($tempZip);
            throw new Exception("CSV file not found in ZIP archive");
        }
    } else {
        unlink($tempZip);
        throw new Exception("Failed to open ZIP file");
    }
    
    // Clean up
    unlink($tempZip);
    
    // Verify file
    if (!file_exists($outputFile) || filesize($outputFile) < 1000000) {
        throw new Exception("Extracted CSV file appears to be invalid (too small)");
    }
    
    // Set permissions
    chmod($outputFile, 0644);
    echo "  Saved CSV to: {$outputFile}\n";
    echo "  ⚠ WARNING: IP2Location PHP library requires BIN format.\n";
    echo "     You may need to convert CSV to BIN or download BIN format manually.\n";
}

/**
 * Download IPinfo DB-Lite database
 * Official URL: https://ipinfo.io/data/free (public direct link)
 */
function downloadIPinfo(string $outputDir): void
{
    // IPinfo DB-Lite - try multiple possible URLs
    // Note: IPinfo may require account registration for direct downloads
    // Public link: https://ipinfo.io/data/free
    $urls = [
        "https://ipinfo.io/data/free/city.mmdb",
        "https://ipinfo.io/data/free/city.mmdb.gz",
        "https://ipinfo.io/data/free/ipinfo-lite.mmdb",
        "https://ipinfo.io/data/free/ipinfo-lite.mmdb.gz",
    ];
    
    $outputFile = $outputDir . '/ipinfo-db-lite.mmdb';
    $data = false;
    $usedUrl = '';
    
    foreach ($urls as $url) {
        echo "  Trying: {$url}\n";
        $data = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'timeout' => 60,
                'user_agent' => 'SimpleKUMA-GeoIP-Downloader/1.0',
                'follow_location' => true,
            ]
        ]));
        
        if ($data !== false && strlen($data) > 1000) {
            $usedUrl = $url;
            
            // Check if it's gzipped
            if (strpos($url, '.gz') !== false || substr($data, 0, 2) === "\x1f\x8b") {
                echo "  Detected gzip, decompressing...\n";
                $tempFile = $outputDir . '/ipinfo-temp.mmdb.gz';
                file_put_contents($tempFile, $data);
                $gz = gzopen($tempFile, 'rb');
                if ($gz !== false) {
                    $decompressed = '';
                    while (!gzeof($gz)) {
                        $decompressed .= gzread($gz, 8192);
                    }
                    gzclose($gz);
                    $data = $decompressed;
                    unlink($tempFile);
                } else {
                    $data = false;
                    continue;
                }
            }
            
            if ($data !== false && strlen($data) > 1000000) {
                break;
            }
        }
        $data = false;
    }
    
    if ($data === false || strlen($data) < 1000000) { // At least 1MB
        throw new Exception("Failed to download IPinfo from all URLs. Manual download required: https://ipinfo.io/account/data-downloads (free account needed)");
    }
    
    // Save file
    file_put_contents($outputFile, $data);
    echo "  Downloaded: " . number_format(strlen($data) / 1024 / 1024, 2) . " MB\n";
    
    // Verify file
    if (!file_exists($outputFile) || filesize($outputFile) < 1000000) {
        throw new Exception("Downloaded file appears to be invalid (too small)");
    }
    
    // Set permissions
    chmod($outputFile, 0644);
    echo "  Saved to: {$outputFile}\n";
}

