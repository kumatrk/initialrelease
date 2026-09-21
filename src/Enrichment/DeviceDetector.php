<?php

declare(strict_types=1);

namespace SimpleKuma\Enrichment;

use DeviceDetector\DeviceDetector as MatomoDeviceDetector;
use DeviceDetector\Cache\StaticCache;

/**
 * Device Detector
 * Enhanced device detection using Matomo DeviceDetector library
 * Provides device type, brand, model, OS, OS version, browser, browser version,
 * and bot classification from the same parse() call (no second parse).
 */
class DeviceDetector
{
    private string $userAgent;
    private static array $staticCache = [];

    public function __construct(string $userAgent)
    {
        $this->userAgent = $userAgent;
    }

    /**
     * Get all device information
     * Uses Matomo DeviceDetector with caching for performance
     * Falls back to basic detection if library not installed
     *
     * @return array{
     *   device: string,
     *   device_brand: ?string,
     *   device_model: ?string,
     *   os: string,
     *   os_version: ?string,
     *   browser: string,
     *   browser_version: ?string,
     *   is_bot: bool,
     *   bot_name: ?string,
     *   bot_category: ?string
     * }
     */
    public function getAll(): array
    {
        // Check static cache first (in-memory cache for this request)
        $uaHash = md5($this->userAgent);
        if (isset(self::$staticCache[$uaHash])) {
            return self::$staticCache[$uaHash];
        }

        // Check if Matomo DeviceDetector is available
        if (!class_exists('DeviceDetector\DeviceDetector')) {
            // Fallback to basic detection if library not installed
            return $this->getBasicDetection();
        }

        // Initialize Matomo DeviceDetector
        $dd = new MatomoDeviceDetector($this->userAgent);

        // Use static cache adapter for better performance
        if (class_exists('DeviceDetector\Cache\StaticCache')) {
            $dd->setCache(new StaticCache());
        }

        // Parse the user agent once
        $dd->parse();

        $isBot = $dd->isBot();
        $botName = null;
        $botCategory = null;
        if ($isBot) {
            $bot = $dd->getBot();
            if (is_array($bot)) {
                $botName = isset($bot['name']) && $bot['name'] !== '' ? (string) $bot['name'] : null;
                $botCategory = isset($bot['category']) && $bot['category'] !== '' ? (string) $bot['category'] : null;
            }
        }

        // Extract device information
        $deviceType = $dd->getDeviceName(); // smartphone, tablet, desktop, etc.
        $deviceBrand = $dd->getBrandName(); // Apple, Samsung, Google, etc.
        $deviceModel = $dd->getModel(); // iPhone 12, Galaxy S21, etc.

        // Extract OS information
        $osName = $dd->getOs('name') ?? 'Unknown';
        $osVersion = $dd->getOs('version') ?? null;

        // Extract browser information
        $browserName = $dd->getClient('name') ?? 'Unknown';
        $browserVersion = $dd->getClient('version') ?? null;

        // Normalize device type to match existing schema (mobile/tablet/desktop)
        $normalizedDevice = $this->normalizeDeviceType($deviceType);

        // Build result array
        $result = [
            'device' => $normalizedDevice,
            'device_brand' => $deviceBrand ?: null,
            'device_model' => $deviceModel ?: null,
            'os' => $osName,
            'os_version' => $osVersion ?: null,
            'browser' => $browserName,
            'browser_version' => $browserVersion ?: null,
            'is_bot' => $isBot,
            'bot_name' => $botName,
            'bot_category' => $botCategory,
        ];

        // Cache the result
        self::$staticCache[$uaHash] = $result;

        return $result;
    }

    /**
     * Whether Matomo classified this UA as a bot (uses cached getAll()).
     */
    public function isBot(): bool
    {
        return !empty($this->getAll()['is_bot']);
    }

    /**
     * Normalize device type to match existing schema
     * Matomo returns: smartphone, tablet, desktop, car, tv, etc.
     * We normalize to: mobile, tablet, desktop
     */
    private function normalizeDeviceType(?string $deviceType): string
    {
        if (empty($deviceType)) {
            return 'desktop';
        }

        $deviceTypeLower = strtolower($deviceType);

        // Map Matomo device types to our schema
        if (in_array($deviceTypeLower, ['smartphone', 'feature phone', 'phablet'])) {
            return 'mobile';
        }

        if ($deviceTypeLower === 'tablet') {
            return 'tablet';
        }

        // Default to desktop for desktop, car, tv, console, etc.
        return 'desktop';
    }

    /**
     * Get device type only (backward compatibility)
     */
    public function getDevice(): string
    {
        $all = $this->getAll();
        return $all['device'];
    }

    /**
     * Get OS name only (backward compatibility)
     */
    public function getOS(): string
    {
        $all = $this->getAll();
        return $all['os'];
    }

    /**
     * Get browser name only (backward compatibility)
     */
    public function getBrowser(): string
    {
        $all = $this->getAll();
        return $all['browser'];
    }

    /**
     * Basic device detection fallback (when Matomo library not installed)
     * Provides basic device/os/browser detection without brand/model/version
     */
    private function getBasicDetection(): array
    {
        $uaOriginal = $this->userAgent;

        // Device type
        $device = 'desktop';
        if (preg_match('/(tablet|ipad|playbook)|(android(?!.*mobile))/i', $uaOriginal)) {
            $device = 'tablet';
        } elseif (preg_match('/(mobile|iphone|ipod|blackberry|android|windows phone)/i', $uaOriginal)) {
            $device = 'mobile';
        }

        // OS
        $os = 'Unknown';
        if (preg_match('/windows nt 10/i', $uaOriginal)) $os = 'Windows 10';
        elseif (preg_match('/windows nt 11/i', $uaOriginal)) $os = 'Windows 11';
        elseif (preg_match('/windows/i', $uaOriginal)) $os = 'Windows';
        elseif (preg_match('/macintosh|mac os x/i', $uaOriginal)) $os = 'macOS';
        elseif (preg_match('/iphone/i', $uaOriginal)) $os = 'iOS';
        elseif (preg_match('/ipad/i', $uaOriginal)) $os = 'iPadOS';
        elseif (preg_match('/android/i', $uaOriginal)) $os = 'Android';
        elseif (preg_match('/linux/i', $uaOriginal)) $os = 'Linux';

        // Browser
        $browser = 'Unknown';
        if (preg_match('/edg/i', $uaOriginal)) $browser = 'Edge';
        elseif (preg_match('/chrome|crios/i', $uaOriginal)) $browser = 'Chrome';
        elseif (preg_match('/safari/i', $uaOriginal) && !preg_match('/chrome/i', $uaOriginal)) $browser = 'Safari';
        elseif (preg_match('/firefox|fxios/i', $uaOriginal)) $browser = 'Firefox';
        elseif (preg_match('/opera|opr/i', $uaOriginal)) $browser = 'Opera';
        elseif (preg_match('/msie|trident/i', $uaOriginal)) $browser = 'IE';

        $result = [
            'device' => $device,
            'device_brand' => null,
            'device_model' => null,
            'os' => $os,
            'os_version' => null,
            'browser' => $browser,
            'browser_version' => null,
            'is_bot' => false,
            'bot_name' => null,
            'bot_category' => null,
        ];

        // Cache the result
        $uaHash = md5($this->userAgent);
        self::$staticCache[$uaHash] = $result;

        return $result;
    }
}
