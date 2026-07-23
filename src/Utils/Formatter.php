<?php

declare(strict_types=1);

namespace SimpleKuma\Utils;

/**
 * Formatter Utility
 * Handles timezone-aware date formatting and currency formatting
 */
class Formatter
{
    /**
     * Format date/time using user's timezone
     */
    public static function formatDateTime(string $datetime, ?string $userTimezone = null, string $format = 'Y-m-d H:i:s'): string
    {
        if (empty($datetime)) {
            return '';
        }

        try {
            // Default to UTC if no timezone provided
            $userTimezone = $userTimezone ?? 'UTC';
            
            // Normalize common timezone abbreviations
            $timezoneMap = [
                'PT' => 'America/Los_Angeles',
                'PST' => 'America/Los_Angeles',
                'PDT' => 'America/Los_Angeles',
                'ET' => 'America/New_York',
                'EST' => 'America/New_York',
                'EDT' => 'America/New_York',
                'CT' => 'America/Chicago',
                'CST' => 'America/Chicago',
                'CDT' => 'America/Chicago',
                'MT' => 'America/Denver',
                'MST' => 'America/Denver',
                'MDT' => 'America/Denver',
            ];
            
            if (isset($timezoneMap[$userTimezone])) {
                $userTimezone = $timezoneMap[$userTimezone];
            }
            
            // Validate timezone
            $tz = new \DateTimeZone($userTimezone);
            $userTimezone = $tz->getName(); // Get canonical name
            
            // Create DateTime object from database datetime (assumed UTC)
            $dt = new \DateTime($datetime, new \DateTimeZone('UTC'));
            
            // Convert to user's timezone
            $dt->setTimezone($tz);
            
            return $dt->format($format);
        } catch (\Exception $e) {
            // Fallback to original datetime if timezone conversion fails
            error_log("Invalid timezone '{$userTimezone}': " . $e->getMessage());
            return $datetime;
        }
    }

    /**
     * Format date only using user's timezone
     */
    public static function formatDate(string $datetime, ?string $userTimezone = null, string $format = 'Y-m-d'): string
    {
        return self::formatDateTime($datetime, $userTimezone, $format);
    }

    /**
     * Format time only using user's timezone
     */
    public static function formatTime(string $datetime, ?string $userTimezone = null, string $format = 'H:i:s'): string
    {
        return self::formatDateTime($datetime, $userTimezone, $format);
    }

    /**
     * Format currency value with currency symbol
     */
    public static function formatCurrency(float $amount, ?string $currency = 'USD', int $decimals = 2): string
    {
        $currency = strtoupper($currency ?? 'USD');
        
        // Currency symbols map
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CAD' => 'C$',
            'AUD' => 'A$',
            'JPY' => '¥',
            'CNY' => '¥',
            'INR' => '₹',
            'BRL' => 'R$',
            'MXN' => '$',
            'CHF' => 'CHF ',
            'SEK' => 'kr ',
            'NOK' => 'kr ',
            'DKK' => 'kr ',
            'PLN' => 'zł ',
            'RUB' => '₽',
            'ZAR' => 'R ',
            'SGD' => 'S$',
            'HKD' => 'HK$',
            'NZD' => 'NZ$',
        ];
        
        // For JPY, don't show decimals
        if ($currency === 'JPY') {
            $decimals = 0;
        }
        
        $symbol = $symbols[$currency] ?? $currency . ' ';
        
        // Format number with proper decimals
        $formatted = number_format($amount, $decimals, '.', ',');
        
        // Position symbol based on currency
        if (in_array($currency, ['EUR', 'GBP', 'JPY', 'CNY', 'INR', 'RUB'])) {
            return $symbol . $formatted;
        } else {
            return $symbol . $formatted;
        }
    }

    /**
     * Get today's date in user's timezone
     */
    public static function getTodayInTimezone(?string $userTimezone = null): string
    {
        $userTimezone = $userTimezone ?? 'UTC';
        
        // Normalize common timezone abbreviations
        $timezoneMap = [
            'PT' => 'America/Los_Angeles',
            'PST' => 'America/Los_Angeles',
            'PDT' => 'America/Los_Angeles',
            'ET' => 'America/New_York',
            'EST' => 'America/New_York',
            'EDT' => 'America/New_York',
            'CT' => 'America/Chicago',
            'CST' => 'America/Chicago',
            'CDT' => 'America/Chicago',
            'MT' => 'America/Denver',
            'MST' => 'America/Denver',
            'MDT' => 'America/Denver',
        ];
        
        if (isset($timezoneMap[$userTimezone])) {
            $userTimezone = $timezoneMap[$userTimezone];
        }
        
        try {
            $tz = new \DateTimeZone($userTimezone);
            $now = new \DateTime('now', $tz);
            return $now->format('Y-m-d');
        } catch (\Exception $e) {
            // Fallback to UTC
            return date('Y-m-d');
        }
    }

    /**
     * Convert UTC datetime to user timezone for SQL queries
     * Returns array with start and end datetime strings in UTC
     */
    public static function convertDateRangeToUTC(string $dateFrom, string $dateTo, ?string $userTimezone = null): array
    {
        $userTimezone = $userTimezone ?? 'UTC';
        
        // Normalize common timezone abbreviations
        $timezoneMap = [
            'PT' => 'America/Los_Angeles',
            'PST' => 'America/Los_Angeles',
            'PDT' => 'America/Los_Angeles',
            'ET' => 'America/New_York',
            'EST' => 'America/New_York',
            'EDT' => 'America/New_York',
            'CT' => 'America/Chicago',
            'CST' => 'America/Chicago',
            'CDT' => 'America/Chicago',
            'MT' => 'America/Denver',
            'MST' => 'America/Denver',
            'MDT' => 'America/Denver',
        ];
        
        if (isset($timezoneMap[$userTimezone])) {
            $userTimezone = $timezoneMap[$userTimezone];
        }
        
        try {
            // Validate timezone
            $tz = new \DateTimeZone($userTimezone);
            $userTimezone = $tz->getName(); // Get canonical name
            
            // Create start of day in user's timezone
            $start = new \DateTime($dateFrom . ' 00:00:00', $tz);
            $start->setTimezone(new \DateTimeZone('UTC'));
            
            // Create end of day in user's timezone
            $end = new \DateTime($dateTo . ' 23:59:59', $tz);
            $end->setTimezone(new \DateTimeZone('UTC'));
            
            return [
                'from' => $start->format('Y-m-d H:i:s'),
                'to' => $end->format('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            // Fallback to UTC if timezone is invalid
            error_log("Invalid timezone '{$userTimezone}': " . $e->getMessage());
            return [
                'from' => $dateFrom . ' 00:00:00',
                'to' => $dateTo . ' 23:59:59'
            ];
        }
    }

    /**
     * Whether the UTC summary_date span exactly matches the user calendar range.
     * When false, callers should use {@see TimezoneSummaryBlend} (hybrid) or raw clicks.
     */
    public static function canUseUtcSummaryDateRange(
        string $userDateFrom,
        string $userDateTo,
        string $utcFrom,
        string $utcTo
    ): bool {
        return substr($utcFrom, 0, 10) === $userDateFrom
            && substr($utcTo, 0, 10) === $userDateTo;
    }

    /**
     * Get base URL for a campaign (custom domain or main domain)
     * @param array|null $campaign Campaign data with optional 'tracking_domain' field
     * @return string Base URL (e.g., https://track.example.com or https://main-domain.com)
     */
    public static function getCampaignBaseUrl(?array $campaign = null): string
    {
        // If campaign has a custom tracking domain, use it
        if ($campaign && !empty($campaign['tracking_domain'])) {
            // tracking_domain is already a full URL (e.g., https://track.example.com)
            return rtrim($campaign['tracking_domain'], '/');
        }
        
        // Otherwise use main tracker domain
        return rtrim(BASE_URL, '/');
    }
}

