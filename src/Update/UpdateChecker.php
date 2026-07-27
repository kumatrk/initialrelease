<?php

declare(strict_types=1);

namespace SimpleKuma\Update;

use mysqli;
use SimpleKuma\Settings\SettingsManager;

/**
 * Update Checker
 * Checks for available updates from GitHub repository
 */
class UpdateChecker
{
    private mysqli $db;
    private SettingsManager $settings;
    private const GITHUB_REPO_DEFAULT = 'kumatrk/initialrelease';
    private const GITHUB_API_BASE = 'https://api.github.com/repos/';
    private const GITHUB_RAW_BASE = 'https://raw.githubusercontent.com/';
    private const CACHE_DURATION = 3600; // 1 hour cache

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->settings = new SettingsManager($db);
    }

    /**
     * Get the configured GitHub repository
     */
    private function getRepository(): string
    {
        $repo = trim((string) $this->settings->get('update_repository', ''));
        if ($repo === '' || strcasecmp($repo, 'SimpleKuma/simplekuma') === 0) {
            return self::GITHUB_REPO_DEFAULT;
        }

        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) === 1
            ? $repo
            : self::GITHUB_REPO_DEFAULT;
    }

    /**
     * Get current local version
     */
    public function getCurrentVersion(): string
    {
        // version.php is the package source of truth. The DB value is only a
        // compatibility fallback for older installs.
        $versionFile = __DIR__ . '/../../version.php';
        if (file_exists($versionFile)) {
            $versionData = include $versionFile;
            $packageVersion = trim((string) ($versionData['version'] ?? ''));
            if ($this->isValidVersion($packageVersion)) {
                return $packageVersion;
            }
        }

        $storedVersion = trim((string) $this->settings->get('app_version', ''));
        return $this->isValidVersion($storedVersion) ? $storedVersion : '0.0.0';
    }

    /**
     * Check if update checking is enabled
     */
    public function isUpdateCheckEnabled(): bool
    {
        return $this->settings->get('update_check_enabled', '0') === '1';
    }

    /**
     * Check for updates from GitHub
     * @param bool $force If true, bypasses the enabled check (for manual checks)
     * @param bool $bypassCache If true, bypasses cache and forces a fresh check
     */
    public function checkForUpdates(bool $force = false, bool $bypassCache = false): array
    {
        if (!$force && !$this->isUpdateCheckEnabled()) {
            return [
                'success' => false,
                'message' => 'Update checking is disabled',
                'update_available' => false
            ];
        }

        $currentVersion = $this->getCurrentVersion();
        
        // Check cache first (unless bypassing)
        if (!$bypassCache) {
            $cached = $this->getCachedUpdateInfo();
            if ($cached && (time() - $cached['checked_at']) < self::CACHE_DURATION) {
                return $cached;
            }
        }

        try {
            // Fetch latest release from GitHub
            $latestRelease = $this->fetchLatestRelease();
            
            if (!$latestRelease || !isset($latestRelease['tag_name'])) {
                return [
                    'success' => false,
                    'message' => 'No versioned GitHub tag was found. Releases must use tags such as v1.2.3.4.',
                    'update_available' => false
                ];
            }

            $latestVersion = self::extractVersionFromTag($latestRelease['tag_name']);
            if ($latestVersion === null) {
                throw new \RuntimeException('The latest GitHub tag is not a supported version tag.');
            }
            $updateAvailable = $this->compareVersions($currentVersion, $latestVersion) < 0;
            $updateType = $this->determineUpdateType($currentVersion, $latestVersion);
            
            $result = [
                'success' => true,
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'tag_name' => $latestRelease['tag_name'],
                'zipball_url' => $latestRelease['zipball_url']
                    ?? (self::GITHUB_API_BASE . $this->getRepository() . '/zipball/' . rawurlencode($latestRelease['tag_name'])),
                'update_available' => $updateAvailable,
                'update_type' => $updateType,
                'changelog' => $latestRelease['body'] ?? '',
                'release_url' => $latestRelease['html_url'] ?? '',
                'published_at' => $latestRelease['published_at'] ?? null,
                'checked_at' => time()
            ];

            // Cache the result
            $this->cacheUpdateInfo($result);

            // Log to database
            $this->logUpdateCheck($currentVersion, $latestVersion, $updateAvailable, $updateType, $latestRelease['body'] ?? '', true, null);

            return $result;

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Log error to database
            $this->logUpdateCheck($currentVersion, null, false, null, null, false, $errorMessage);

            return [
                'success' => false,
                'message' => 'Error checking for updates: ' . $errorMessage,
                'update_available' => false,
                'error' => $errorMessage
            ];
        }
    }

    /**
     * Fetch latest release from GitHub API
     */
    private function fetchLatestRelease(): ?array
    {
        $repo = $this->getRepository();
        $candidates = [];

        $releases = $this->fetchGitHubJson(self::GITHUB_API_BASE . $repo . '/releases?per_page=30');
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
                continue;
            }
            $version = self::extractVersionFromTag((string) ($release['tag_name'] ?? ''));
            if ($version !== null) {
                $candidates[$version] = $release;
            }
        }

        // A versioned Git tag is sufficient for an update; a GitHub Release
        // and uploaded release asset are not required.
        $tags = $this->fetchGitHubJson(self::GITHUB_API_BASE . $repo . '/tags?per_page=100');
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $tagName = (string) ($tag['name'] ?? '');
            $version = self::extractVersionFromTag($tagName);
            if ($version === null || isset($candidates[$version])) {
                continue;
            }
            $candidates[$version] = [
                'tag_name' => $tagName,
                'zipball_url' => self::GITHUB_API_BASE . $repo . '/zipball/' . rawurlencode($tagName),
                'html_url' => 'https://github.com/' . $repo . '/tree/' . rawurlencode($tagName),
                'body' => '',
                'published_at' => null,
            ];
        }

        if ($candidates === []) {
            return null;
        }
        uksort($candidates, 'version_compare');
        return end($candidates) ?: null;
    }

    private function fetchGitHubJson(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'SimpleKuma-UpdateChecker/1.0',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github.v3+json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Update check failed: cURL error - $error");
            throw new \Exception("Network error: $error");
        }

        if ($httpCode !== 200) {
            $errorMsg = "HTTP $httpCode";
            if ($httpCode === 404) {
                $errorMsg = "Repository not found or no releases available. Please check the repository name: $repo";
            } elseif ($httpCode === 403) {
                $errorMsg = "GitHub API rate limit exceeded or access denied. Please try again later.";
            } else {
                $errorData = json_decode($response, true);
                if (isset($errorData['message'])) {
                    $errorMsg .= " - " . $errorData['message'];
                }
            }
            error_log("Update check failed: $errorMsg");
            throw new \Exception($errorMsg);
        }

        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonError = json_last_error_msg();
            error_log("Update check failed: Invalid JSON response - $jsonError");
            throw new \Exception("Invalid response from GitHub: $jsonError");
        }

        if (!is_array($data)) {
            throw new \Exception('Invalid response from GitHub: expected a JSON array.');
        }

        return $data;
    }

    /**
     * Extract version from Git tag (e.g., "v1.2.3" -> "1.2.3")
     */
    public static function extractVersionFromTag(string $tag): ?string
    {
        if (preg_match('/^v(\d+\.\d+\.\d+(?:\.\d+)?)$/i', trim($tag), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function isValidVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    /**
     * Compare two semantic versions
     * Returns: -1 if v1 < v2, 0 if equal, 1 if v1 > v2
     */
    private function compareVersions(string $v1, string $v2): int
    {
        return version_compare($v1, $v2);
    }

    /**
     * Determine update type (patch, minor, major)
     */
    private function determineUpdateType(string $current, string $latest): string
    {
        $currentParts = explode('.', $current);
        $latestParts = explode('.', $latest);

        if (count($currentParts) < 3 || count($latestParts) < 3) {
            return 'patch';
        }

        // Major version change
        if ((int)$latestParts[0] > (int)$currentParts[0]) {
            return 'major';
        }

        // Minor version change
        if ((int)$latestParts[1] > (int)$currentParts[1]) {
            return 'minor';
        }

        // Patch version change
        return 'patch';
    }

    /**
     * Cache update info in settings
     */
    private function cacheUpdateInfo(array $info): void
    {
        $this->settings->set('update_check_cache', json_encode($info));
        $this->settings->set('update_check_cache_time', (string)time());
    }

    /**
     * Get cached update info
     */
    private function getCachedUpdateInfo(): ?array
    {
        $cache = $this->settings->get('update_check_cache', null);
        $cacheTime = $this->settings->get('update_check_cache_time', '0');
        
        if (!$cache || (time() - (int)$cacheTime) >= self::CACHE_DURATION) {
            return null;
        }

        $data = json_decode($cache, true);
        return $data ?: null;
    }

    /**
     * Log update check to database
     */
    private function logUpdateCheck(
        string $currentVersion,
        ?string $latestVersion,
        bool $updateAvailable,
        ?string $updateType,
        ?string $changelog,
        bool $success,
        ?string $errorMessage
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO update_check_history 
            (current_version, latest_version, update_available, update_type, changelog, check_successful, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if ($stmt === false) {
            error_log('Could not record update check: ' . $this->db->error);
            return;
        }

        $stmt->bind_param(
            'ssissis',
            $currentVersion,
            $latestVersion,
            $updateAvailable,
            $updateType,
            $changelog,
            $success,
            $errorMessage
        );
        
        $stmt->execute();
    }

    /**
     * Get last update check result
     */
    public function getLastUpdateCheck(): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM update_check_history 
            ORDER BY checked_at DESC 
            LIMIT 1
        ");
        if ($stmt === false) {
            return null;
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row ?: null;
    }

    /**
     * Fetch detailed update information from repository files
     * Tries to fetch updates.json first, then falls back to CHANGELOG.md parsing
     */
    private function fetchUpdateDetails(string $version): array
    {
        $details = [];
        
        // Try to fetch updates.json (structured data)
        $updatesJson = $this->fetchRawFile('updates.json');
        if ($updatesJson) {
            $jsonData = json_decode($updatesJson, true);
            if ($jsonData && isset($jsonData['version']) && $jsonData['version'] === $version) {
                $details = [
                    'changelog_structured' => $jsonData['changelog'] ?? null,
                    'release_date' => $jsonData['release_date'] ?? null,
                    'requirements' => $jsonData['requirements'] ?? null,
                    'critical' => $jsonData['critical'] ?? false,
                    'maintenance_mode_required' => $jsonData['maintenance_mode_required'] ?? false,
                    'migrations' => $jsonData['migrations'] ?? []
                ];
                
                // Convert structured changelog to markdown format
                if (isset($jsonData['changelog'])) {
                    $details['changelog'] = $this->formatStructuredChangelog($jsonData['changelog']);
                }
            }
        }

        // If updates.json doesn't have this version, try parsing CHANGELOG.md
        if (empty($details['changelog'])) {
            $changelogMd = $this->fetchRawFile('CHANGELOG.md');
            if ($changelogMd) {
                $parsedChangelog = $this->parseChangelogForVersion($changelogMd, $version);
                if ($parsedChangelog) {
                    $details['changelog'] = $parsedChangelog;
                }
            }
        }

        return $details;
    }

    /**
     * Fetch a raw file from GitHub repository
     */
    private function fetchRawFile(string $filename): ?string
    {
        $repo = $this->getRepository();
        // Try main branch first, then master as fallback
        $branches = ['main', 'master'];
        
        foreach ($branches as $branch) {
            $url = self::GITHUB_RAW_BASE . $repo . '/' . $branch . '/' . $filename;
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'SimpleKuma-UpdateChecker/1.0',
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response !== false) {
                return $response;
            }
        }
        
        return null;
    }

    /**
     * Parse CHANGELOG.md to extract changelog for a specific version
     */
    private function parseChangelogForVersion(string $changelogMd, string $version): ?string
    {
        // Look for version header: ## [1.2.3] or ## 1.2.3
        $pattern = '/^##\s*\[?' . preg_quote($version, '/') . '\]?.*$/mi';
        
        if (preg_match($pattern, $changelogMd, $matches, PREG_OFFSET_CAPTURE)) {
            $startPos = $matches[0][1];
            
            // Find the next version header or end of file
            $remaining = substr($changelogMd, $startPos);
            $nextVersionPattern = '/^##\s*\[?\d+\.\d+\.\d+\]?/m';
            
            if (preg_match($nextVersionPattern, $remaining, $nextMatch, PREG_OFFSET_CAPTURE, strlen($matches[0][0]))) {
                $endPos = $nextMatch[0][1];
                return trim(substr($remaining, 0, $endPos));
            } else {
                // This is the latest version, return everything until end
                return trim($remaining);
            }
        }
        
        return null;
    }

    /**
     * Format structured changelog (from JSON) to markdown
     */
    private function formatStructuredChangelog(array $changelog): string
    {
        $markdown = '';
        
        $sections = [
            'added' => '### Added',
            'changed' => '### Changed',
            'fixed' => '### Fixed',
            'security' => '### Security',
            'removed' => '### Removed',
            'deprecated' => '### Deprecated'
        ];
        
        foreach ($sections as $key => $header) {
            if (isset($changelog[$key]) && is_array($changelog[$key]) && !empty($changelog[$key])) {
                $markdown .= $header . "\n\n";
                foreach ($changelog[$key] as $item) {
                    $markdown .= "- " . $item . "\n";
                }
                $markdown .= "\n";
            }
        }
        
        return trim($markdown);
    }

    /**
     * Fetch update manifest for a specific version
     * Tries version-specific manifest first, then root manifest
     */
    public function fetchManifest(string $version): ?array
    {
        // Try version-specific manifest first: updates/{version}/manifest.json
        $versionManifest = $this->fetchRawFile("updates/{$version}/manifest.json");
        if ($versionManifest) {
            $data = json_decode($versionManifest, true);
            if ($data && isset($data['version']) && $data['version'] === $version) {
                return $data;
            }
        }

        // Fallback to root updates.json (for backward compatibility)
        $rootManifest = $this->fetchRawFile('updates.json');
        if ($rootManifest) {
            $data = json_decode($rootManifest, true);
            if ($data && isset($data['version']) && $data['version'] === $version) {
                return $data;
            }
        }

        return null;
    }
}

