<?php

declare(strict_types=1);

namespace SimpleKuma\Installer;

/**
 * Dependency Installer
 * Automatically installs Composer dependencies and GeoIP databases via web interface
 */
class DependencyInstaller
{
    private string $basePath;
    private array $errors = [];
    private array $messages = [];

    public function __construct()
    {
        $this->basePath = dirname(__DIR__, 2);
    }

    /**
     * Install Composer dependencies
     */
    public function installComposerDependencies(): bool
    {
        $composerJsonPath = $this->basePath . '/composer.json';
        
        // Check if composer.json exists
        if (!file_exists($composerJsonPath)) {
            $this->errors[] = "composer.json not found";
            return false;
        }

        // Check if vendor/autoload.php already exists
        $autoloadPath = $this->basePath . '/vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            // Check if key classes are available
            try {
                require_once $autoloadPath;
                if (class_exists('\SimpleKuma\GeoIP\GeoResolver') || 
                    (class_exists('\Matomo\DeviceDetector\DeviceDetector') && 
                     class_exists('\PHPMailer\PHPMailer\PHPMailer'))) {
                    $this->messages[] = "Composer dependencies already installed";
                    return true;
                }
            } catch (\Exception $e) {
                // Continue with installation if autoload fails
            }
        }

        // Find composer executable
        $composerCommand = $this->findComposer();
        if (!$composerCommand) {
            $this->errors[] = "Composer not found. Please install Composer or contact your hosting provider.";
            return false;
        }

        $this->messages[] = "Found Composer: " . $composerCommand;

        // Run composer install
        $command = "cd " . escapeshellarg($this->basePath) . " && {$composerCommand} install --no-dev --optimize-autoloader --no-interaction 2>&1";
        
        $this->messages[] = "Running: {$command}";
        
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        $outputText = implode("\n", $output);
        $this->messages[] = "Output: " . $outputText;

        if ($returnVar === 0) {
            // Verify installation
            if (file_exists($autoloadPath)) {
                try {
                    require_once $autoloadPath;
                    // Check for key classes (GeoIP resolver, Device Detector, PHPMailer)
                    $hasGeoIP = class_exists('\SimpleKuma\GeoIP\GeoResolver');
                    $hasDeviceDetector = class_exists('\Matomo\DeviceDetector\DeviceDetector');
                    $hasPHPMailer = class_exists('\PHPMailer\PHPMailer\PHPMailer');
                    
                    if ($hasGeoIP || ($hasDeviceDetector && $hasPHPMailer)) {
                        $this->messages[] = "✓ Composer dependencies installed successfully";
                        if ($hasGeoIP) {
                            $this->messages[] = "  - GeoIP resolver library ready";
                        }
                        if ($hasDeviceDetector) {
                            $this->messages[] = "  - Device Detector library ready";
                        }
                        if ($hasPHPMailer) {
                            $this->messages[] = "  - PHPMailer library ready";
                        }
                        return true;
                    } else {
                        $this->errors[] = "Composer install completed but required libraries not found";
                        return false;
                    }
                } catch (\Exception $e) {
                    $this->errors[] = "Error loading autoloader: " . $e->getMessage();
                    return false;
                }
            } else {
                $this->errors[] = "Composer install completed but vendor/autoload.php not found";
                return false;
            }
        } else {
            $this->errors[] = "Composer install failed (exit code: {$returnVar})";
            $this->errors[] = "Output: " . $outputText;
            return false;
        }
    }

    /**
     * Download MaxMind GeoLite2 database
     */
    public function downloadMaxMindDatabase(): bool
    {
        $storagePath = $this->basePath . '/storage';
        $dbPath = $storagePath . '/GeoLite2-City.mmdb';

        // Check if database already exists
        if (file_exists($dbPath) && filesize($dbPath) > 1000000) { // At least 1MB
            $this->messages[] = "MaxMind database already exists";
            return true;
        }

        // Ensure storage directory exists
        if (!is_dir($storagePath)) {
            if (!@mkdir($storagePath, 0755, true)) {
                $this->errors[] = "Cannot create storage directory";
                return false;
            }
        }

        // Use the download script
        $downloadScript = $this->basePath . '/scripts/download-geolite2.php';
        
        if (!file_exists($downloadScript)) {
            $this->errors[] = "MaxMind download script not found";
            return false;
        }

        // Try to download using PHP
        $this->messages[] = "Attempting to download MaxMind database...";
        
        // Check if we can use the download script via include
        try {
            // Set up environment for the download script
            $_SERVER['REQUEST_METHOD'] = 'GET'; // Prevent interactive prompts
            
            // Capture output
            ob_start();
            $oldErrorReporting = error_reporting(E_ALL);
            $oldDisplayErrors = ini_get('display_errors');
            ini_set('display_errors', '0');
            
            // Include the download script (it will detect it's being run programmatically)
            $scriptOutput = '';
            $scriptSuccess = false;
            
            // We'll use a simpler approach - direct download
            $scriptSuccess = $this->downloadMaxMindDirect($dbPath);
            
            error_reporting($oldErrorReporting);
            ini_set('display_errors', $oldDisplayErrors);
            ob_end_clean();
            
            if ($scriptSuccess && file_exists($dbPath) && filesize($dbPath) > 1000000) {
                $this->messages[] = "✓ MaxMind database downloaded successfully";
                return true;
            } else {
                $this->errors[] = "MaxMind database download failed or file is too small";
                return false;
            }
        } catch (\Exception $e) {
            $this->errors[] = "Error downloading MaxMind database: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Direct download of MaxMind database (simplified)
     * Note: This requires MaxMind credentials or uses a public mirror if available
     */
    private function downloadMaxMindDirect(string $targetPath): bool
    {
        // For now, we'll skip automatic download and just inform the user
        // MaxMind requires account registration and credentials
        // Users can download manually or we can provide instructions
        
        $this->messages[] = "MaxMind database download requires manual setup";
        $this->messages[] = "Please download GeoLite2-City.mmdb from: https://dev.maxmind.com/geoip/geoip2/geolite2/";
        $this->messages[] = "And place it in: storage/GeoLite2-City.mmdb";
        
        // Return false so user knows they need to do it manually
        // But don't treat it as an error - it's optional
        return false;
    }

    /**
     * Download updated GeoIP databases (DB-IP, IPinfo, IP2Location)
     * Attempts to download updated versions, but falls back to pre-packaged versions if download fails.
     * This is non-blocking - installation continues even if downloads fail.
     */
    public function downloadGeoIPDatabases(): bool
    {
        $geoipDir = $this->basePath . '/geoip';
        $storageGeoipDir = $this->basePath . '/storage/geoip';
        
        // Ensure directories exist
        if (!is_dir($geoipDir)) {
            @mkdir($geoipDir, 0755, true);
        }
        if (!is_dir($storageGeoipDir)) {
            @mkdir($storageGeoipDir, 0755, true);
        }
        
        $this->messages[] = "Checking for GeoIP database updates...";
        
        $results = [
            'dbip' => false,
            'ipinfo' => false,
            'ip2location' => false,
        ];
        
        // Check if pre-packaged databases exist
        $prepackaged = [
            'dbip' => file_exists($geoipDir . '/DBIP-City-Lite.mmdb') || file_exists($storageGeoipDir . '/DBIP-City-Lite.mmdb'),
            'ipinfo' => file_exists($geoipDir . '/ipinfo-db-lite.mmdb') || file_exists($storageGeoipDir . '/ipinfo-db-lite.mmdb'),
            'ip2location' => file_exists($geoipDir . '/IP2LOCATION-LITE-DB11.BIN') || file_exists($storageGeoipDir . '/IP2LOCATION-LITE-DB11.BIN'),
        ];
        
        if ($prepackaged['dbip']) {
            $this->messages[] = "✓ Pre-packaged DB-IP database found";
        }
        if ($prepackaged['ipinfo']) {
            $this->messages[] = "✓ Pre-packaged IPinfo database found";
        }
        if ($prepackaged['ip2location']) {
            $this->messages[] = "✓ Pre-packaged IP2Location database found";
        }
        
        // Attempt to download updated versions (non-blocking)
        $this->messages[] = "Attempting to download updated databases...";
        
        // Download DB-IP Lite
        try {
            $this->downloadDBIPDatabase($geoipDir);
            $results['dbip'] = true;
            $this->messages[] = "✓ DB-IP database updated";
        } catch (\Exception $e) {
            if ($prepackaged['dbip']) {
                $this->messages[] = "⚠ DB-IP update failed, using pre-packaged version: " . $e->getMessage();
            } else {
                $this->messages[] = "⚠ DB-IP download failed: " . $e->getMessage();
            }
        }
        
        // Download IPinfo DB-Lite
        try {
            $this->downloadIPinfoDatabase($geoipDir);
            $results['ipinfo'] = true;
            $this->messages[] = "✓ IPinfo database updated";
        } catch (\Exception $e) {
            if ($prepackaged['ipinfo']) {
                $this->messages[] = "⚠ IPinfo update failed, using pre-packaged version: " . $e->getMessage();
            } else {
                $this->messages[] = "⚠ IPinfo download failed: " . $e->getMessage();
            }
        }
        
        // IP2Location - note that it may require manual download
        // We'll skip automatic download for now since it requires form submission
        if (!$prepackaged['ip2location']) {
            $this->messages[] = "ℹ IP2Location requires manual download from: https://lite.ip2location.com/database-download";
        }
        
        // Return true if at least one database is available (pre-packaged or downloaded)
        $hasAnyDatabase = $prepackaged['dbip'] || $prepackaged['ipinfo'] || $results['dbip'] || $results['ipinfo'];
        
        if ($hasAnyDatabase) {
            $this->messages[] = "✓ GeoIP databases ready (pre-packaged or updated)";
            return true;
        } else {
            $this->messages[] = "⚠ No GeoIP databases found. System will work but geolocation may be limited.";
            return false; // Non-blocking, so this is just informational
        }
    }

    /**
     * Download DB-IP Lite database
     */
    private function downloadDBIPDatabase(string $outputDir): void
    {
        $currentMonth = date('Y-m');
        $previousMonth = date('Y-m', strtotime('-1 month'));
        
        $urls = [
            "https://download.db-ip.com/free/dbip-city-lite-{$currentMonth}.mmdb.gz",
            "https://download.db-ip.com/free/dbip-city-lite-{$previousMonth}.mmdb.gz",
        ];
        
        $outputFile = $outputDir . '/DBIP-City-Lite.mmdb';
        $tempFile = $outputDir . '/dbip-temp.mmdb.gz';
        
        $data = false;
        
        foreach ($urls as $url) {
            $data = @file_get_contents($url, false, stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'SimpleKUMA-Installer/1.0',
                ]
            ]));
            
            if ($data !== false && strlen($data) > 1000) {
                break;
            }
        }
        
        if ($data === false || strlen($data) < 1000) {
            throw new Exception("Failed to download from DB-IP");
        }
        
        file_put_contents($tempFile, $data);
        
        // Decompress
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
        
        file_put_contents($outputFile, $decompressed);
        unlink($tempFile);
        
        if (!file_exists($outputFile) || filesize($outputFile) < 1000000) {
            throw new Exception("Downloaded file is invalid");
        }
        
        chmod($outputFile, 0644);
    }

    /**
     * Download IPinfo DB-Lite database
     */
    private function downloadIPinfoDatabase(string $outputDir): void
    {
        $url = "https://ipinfo.io/data/free/city.mmdb";
        $outputFile = $outputDir . '/ipinfo-db-lite.mmdb';
        
        $data = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'timeout' => 60,
                'user_agent' => 'SimpleKUMA-Installer/1.0',
            ]
        ]));
        
        // Try alternative URL if first fails
        if (($data === false || strlen($data) < 1000) && function_exists('gzopen')) {
            $altUrl = "https://ipinfo.io/data/free/city.mmdb.gz";
            $data = @file_get_contents($altUrl, false, stream_context_create([
                'http' => [
                    'timeout' => 60,
                    'user_agent' => 'SimpleKUMA-Installer/1.0',
                ]
            ]));
            
            if ($data !== false && strlen($data) > 1000) {
                // Decompress if gzipped
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
                }
            }
        }
        
        if ($data === false || strlen($data) < 1000000) {
            throw new Exception("Failed to download from IPinfo");
        }
        
        file_put_contents($outputFile, $data);
        
        if (!file_exists($outputFile) || filesize($outputFile) < 1000000) {
            throw new Exception("Downloaded file is invalid");
        }
        
        chmod($outputFile, 0644);
    }

    /**
     * Find Composer executable
     */
    private function findComposer(): ?string
    {
        // Check for composer.phar in project root
        $composerPhar = $this->basePath . '/composer.phar';
        if (file_exists($composerPhar)) {
            return 'php ' . escapeshellarg($composerPhar);
        }

        // Try to download composer.phar if it doesn't exist
        $this->messages[] = "Composer.phar not found, attempting to download...";
        if ($this->downloadComposerPhar($composerPhar)) {
            if (file_exists($composerPhar)) {
                chmod($composerPhar, 0755);
                return 'php ' . escapeshellarg($composerPhar);
            }
        }

        // Check common system locations
        $possiblePaths = [
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            'composer', // In PATH
        ];

        foreach ($possiblePaths as $path) {
            if ($path === 'composer' || file_exists($path)) {
                // Test if it works
                $testOutput = [];
                $testReturn = 0;
                @exec("{$path} --version 2>&1", $testOutput, $testReturn);
                
                if ($testReturn === 0) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Download composer.phar if it doesn't exist
     */
    private function downloadComposerPhar(string $targetPath): bool
    {
        try {
            $installerUrl = 'https://getcomposer.org/installer';
            $installerScript = @file_get_contents($installerUrl, false, stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'SimpleKUMA-Installer/1.0',
                ]
            ]));

            if ($installerScript === false) {
                $this->errors[] = "Failed to download Composer installer";
                return false;
            }

            // Save installer script temporarily
            $installerPath = $this->basePath . '/composer-installer.php';
            file_put_contents($installerPath, $installerScript);

            // Run installer
            $output = [];
            $returnVar = 0;
            exec("cd " . escapeshellarg($this->basePath) . " && php composer-installer.php 2>&1", $output, $returnVar);
            
            // Clean up installer
            if (file_exists($installerPath)) {
                @unlink($installerPath);
            }

            if ($returnVar === 0 && file_exists($targetPath)) {
                $this->messages[] = "✓ Composer.phar downloaded successfully";
                return true;
            } else {
                $this->errors[] = "Composer installer failed: " . implode("\n", $output);
                return false;
            }
        } catch (\Exception $e) {
            $this->errors[] = "Error downloading Composer: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Get errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get messages
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Check if exec/shell_exec is available
     */
    public function canExecuteCommands(): bool
    {
        // Check if exec is disabled
        $disabledFunctions = explode(',', ini_get('disable_functions'));
        $disabledFunctions = array_map('trim', $disabledFunctions);
        
        if (in_array('exec', $disabledFunctions) && in_array('shell_exec', $disabledFunctions)) {
            return false;
        }

        // Check if safe_mode is enabled (old PHP versions)
        if (ini_get('safe_mode')) {
            return false;
        }

        return true;
    }
}

