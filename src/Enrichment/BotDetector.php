<?php

declare(strict_types=1);

namespace SimpleKuma\Enrichment;

use Jaybizzle\CrawlerDetect\CrawlerDetect;

/**
 * Write-path bot / crawler classification.
 *
 * Designed for the redirect hot path: reuses Matomo bot flags from DeviceDetector
 * (already parsed), then optionally runs Crawler-Detect once if still needed.
 * Fail-open: missing libraries never break redirects.
 */
class BotDetector
{
    public const CLASS_HUMAN = 'human';
    public const CLASS_KNOWN_BOT = 'known_bot';
    public const CLASS_SUSPECTED_BOT = 'suspected_bot';

    /** @var CrawlerDetect|null|false false = unavailable */
    private static $crawlerDetect = null;

    /** @var array<string, array<string, mixed>> */
    private static array $uaCache = [];

    /**
     * @param array{
     *   enabled?: bool,
     *   exclude_known?: bool,
     *   exclude_suspected?: bool,
     *   check_headers?: bool
     * } $options
     * @param array<string, mixed>|null $deviceData Result from DeviceDetector::getAll() when already available
     * @return array{
     *   classification: string,
     *   name: ?string,
     *   category: ?string,
     *   reasons: list<string>,
     *   exclude_from_stats: bool
     * }
     */
    public static function detect(?string $ua, ?array $deviceData = null, array $options = []): array
    {
        $enabled = $options['enabled'] ?? true;
        $excludeKnown = $options['exclude_known'] ?? true;
        $excludeSuspected = $options['exclude_suspected'] ?? false;
        // Off by default: missing Accept headers are common on real XHR/mobile WebViews
        $checkHeaders = $options['check_headers'] ?? false;

        $human = [
            'classification' => self::CLASS_HUMAN,
            'name' => null,
            'category' => null,
            'reasons' => [],
            'exclude_from_stats' => false,
        ];

        if (!$enabled) {
            return $human;
        }

        $uaKey = md5(($ua ?? '') . '|' . ($excludeKnown ? '1' : '0') . '|' . ($excludeSuspected ? '1' : '0') . '|' . ($checkHeaders ? '1' : '0'));
        if (isset(self::$uaCache[$uaKey])) {
            return self::$uaCache[$uaKey];
        }

        $reasons = [];
        $name = null;
        $category = null;
        $classification = self::CLASS_HUMAN;

        $uaTrimmed = trim((string) $ua);
        if ($uaTrimmed === '') {
            $classification = self::CLASS_KNOWN_BOT;
            $name = 'Empty User-Agent';
            $category = 'Unknown Automation';
            $reasons[] = 'empty_ua';
        }

        // Meta preview / ads crawlers (existing product rule; cheap stripos)
        if ($classification === self::CLASS_HUMAN && \SimpleKuma\Stats\CampaignStatsExpressions::isFacebookCrawlerUa($uaTrimmed)) {
            $classification = self::CLASS_KNOWN_BOT;
            $name = 'Meta Crawler';
            $category = 'Social Preview';
            $reasons[] = 'meta_crawler_ua';
        }

        // Reuse Matomo parse result when provided (zero extra parse cost)
        if ($classification === self::CLASS_HUMAN && is_array($deviceData) && !empty($deviceData['is_bot'])) {
            $classification = self::CLASS_KNOWN_BOT;
            $name = isset($deviceData['bot_name']) && $deviceData['bot_name'] !== ''
                ? (string) $deviceData['bot_name']
                : 'Bot';
            $category = self::normalizeCategory(
                isset($deviceData['bot_category']) ? (string) $deviceData['bot_category'] : null
            );
            $reasons[] = 'matomo_is_bot';
        }

        // Crawler-Detect only if still not classified (one preg against crawler list)
        if ($classification === self::CLASS_HUMAN && $uaTrimmed !== '') {
            $cd = self::crawlerDetect();
            if ($cd !== null) {
                try {
                    if ($cd->isCrawler($uaTrimmed)) {
                        $classification = self::CLASS_KNOWN_BOT;
                        $match = $cd->getMatches();
                        $name = is_string($match) && $match !== '' ? $match : 'Crawler';
                        $category = self::guessCategoryFromName($name);
                        $reasons[] = 'crawler_detect';
                    }
                } catch (\Throwable $e) {
                    // Fail-open: never break redirects
                }
            }
        }

        // Cheap header heuristics → suspected only (never default-exclude)
        if ($classification === self::CLASS_HUMAN && $checkHeaders) {
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method === 'HEAD') {
                $classification = self::CLASS_SUSPECTED_BOT;
                $name = $name ?? 'HEAD Request';
                $category = $category ?? 'Unknown Automation';
                $reasons[] = 'head_request';
            }
            if (!isset($_SERVER['HTTP_ACCEPT']) || trim((string) $_SERVER['HTTP_ACCEPT']) === '') {
                if ($classification === self::CLASS_HUMAN) {
                    $classification = self::CLASS_SUSPECTED_BOT;
                    $name = $name ?? 'Missing Accept';
                    $category = $category ?? 'Unknown Automation';
                }
                $reasons[] = 'missing_accept';
            }
            if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) || trim((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']) === '') {
                if ($classification === self::CLASS_HUMAN) {
                    $classification = self::CLASS_SUSPECTED_BOT;
                    $name = $name ?? 'Missing Accept-Language';
                    $category = $category ?? 'Unknown Automation';
                }
                $reasons[] = 'missing_accept_language';
            }
        }

        $exclude = false;
        if ($classification === self::CLASS_KNOWN_BOT && $excludeKnown) {
            $exclude = true;
        } elseif ($classification === self::CLASS_SUSPECTED_BOT && $excludeSuspected) {
            $exclude = true;
        }

        $result = [
            'classification' => $classification,
            'name' => $name,
            'category' => $category,
            'reasons' => $reasons,
            'exclude_from_stats' => $exclude,
        ];

        self::$uaCache[$uaKey] = $result;

        return $result;
    }

    /**
     * Compact payload for clicks.extra_json.bot
     *
     * @param array<string, mixed> $detection
     * @return array<string, mixed>
     */
    public static function toExtraJson(array $detection): array
    {
        return [
            'classification' => $detection['classification'] ?? self::CLASS_HUMAN,
            'name' => $detection['name'] ?? null,
            'category' => $detection['category'] ?? null,
            'reasons' => array_values($detection['reasons'] ?? []),
            'exclude_from_stats' => !empty($detection['exclude_from_stats']),
        ];
    }

    private static function crawlerDetect(): ?CrawlerDetect
    {
        if (self::$crawlerDetect === false) {
            return null;
        }
        if (self::$crawlerDetect instanceof CrawlerDetect) {
            return self::$crawlerDetect;
        }
        if (!class_exists(CrawlerDetect::class)) {
            self::$crawlerDetect = false;
            return null;
        }
        try {
            self::$crawlerDetect = new CrawlerDetect();
            return self::$crawlerDetect;
        } catch (\Throwable $e) {
            self::$crawlerDetect = false;
            return null;
        }
    }

    private static function normalizeCategory(?string $category): string
    {
        if ($category === null || $category === '') {
            return 'Unknown Automation';
        }

        $lower = strtolower($category);
        if (str_contains($lower, 'search')) {
            return 'Search Engine';
        }
        if (str_contains($lower, 'social') || str_contains($lower, 'feed')) {
            return 'Social Preview';
        }
        if (str_contains($lower, 'email') || str_contains($lower, 'security')) {
            return 'Email Security';
        }
        if (str_contains($lower, 'seo')) {
            return 'SEO Crawler';
        }
        if (str_contains($lower, 'monitor') || str_contains($lower, 'uptime') || str_contains($lower, 'check')) {
            return 'Monitoring';
        }
        if (str_contains($lower, 'scrap') || str_contains($lower, 'automat') || str_contains($lower, 'library')) {
            return 'Browser Automation';
        }

        return $category;
    }

    private static function guessCategoryFromName(string $name): string
    {
        return self::normalizeCategory($name);
    }
}
