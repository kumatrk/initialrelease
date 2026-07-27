<?php

declare(strict_types=1);

namespace SimpleKuma\Tracking;

/**
 * Token Replacer
 * Replaces token placeholders in postback URLs with actual values from conversion context
 */
class TokenReplacer
{
    /**
     * Replace tokens in a postback URL
     * 
     * Supported built-in tokens:
     * - {click_id} - The click ID
     * - {campaign_id} - Campaign ID
     * - {campaign_key} - Campaign key (friendly identifier)
     * - {location} - Country code
     * - {state} - State/region
     * - {city} - City name
     * - {value} - Conversion value (falls back to payout when value is empty)
     * - {payout} - Conversion payout (falls back to value when payout is empty)
     * - {currency} - Currency code
     * - {txid} - Transaction ID
     * - {event_id} - Event ID
     * - {event_key} - Canonical inbound funnel event key (et), empty when unset
     * - {timestamp} - Conversion timestamp (Unix)
     * - {datetime} - Conversion datetime (Y-m-d H:i:s)
     * 
     * Custom tokens from campaign:
     * - {token1} through {token20} - Custom campaign tokens
     * 
     * Custom tokens from traffic source:
     * - {ts_token1} through {ts_token20} - Custom traffic source tokens
     * 
     * @param string $postbackUrl The postback URL with token placeholders
     * @param array $context Conversion context with click, campaign, and conversion data
     * @return string URL with tokens replaced
     */
    public function replace(string $postbackUrl, array $context): string
    {
        $click = $context['click'] ?? [];
        $campaign = $context['campaign'] ?? [];
        $conversion = $context['conversion'] ?? [];
        $extraJson = json_decode($click['extra_json'] ?? '{}', true) ?? [];
        
        // Get all traffic sources from context (populated by caller)
        $allTrafficSources = $context['all_traffic_sources'] ?? [];

        // Extract custom tokens from campaign
        $customTokens = json_decode($campaign['custom_tokens_json'] ?? '[]', true) ?? [];
        $customTokenValues = [];
        foreach ($customTokens as $idx => $token) {
            $paramName = $token['parameter'] ?? '';
            $tokenNum = $idx + 1;
            
            // Get value from extra_json - check campaign custom tokens first, then all_params
            $value = null;
            if (!empty($extraJson['custom_tokens'][$paramName])) {
                $value = $extraJson['custom_tokens'][$paramName]['value'] ?? $extraJson['custom_tokens'][$paramName];
            } elseif (!empty($extraJson['all_params'][$paramName])) {
                $value = $extraJson['all_params'][$paramName];
            }
            
            $customTokenValues["token{$tokenNum}"] = $value ?? '';
        }
        
        // Extract traffic source tokens - support both old format {ts_token1} and new unique format {ts:TrafficSourceName:parameter}
        // Build map of traffic source name (sanitized) -> tokens
        $trafficSourceTokenMap = [];
        foreach ($allTrafficSources as $ts) {
            $tsName = $ts['name'] ?? '';
            $tsNameSanitized = preg_replace('/[^a-zA-Z0-9]/', '', $tsName);
            $tsTokens = is_array($ts['tokens_json'] ?? null) 
                ? $ts['tokens_json'] 
                : (json_decode($ts['tokens_json'] ?? '[]', true) ?: []);
            
            if (!empty($tsNameSanitized) && !empty($tsTokens)) {
                $trafficSourceTokenMap[$tsNameSanitized] = $tsTokens;
            }
        }
        
        // Also support old format {ts_token1} for backward compatibility
        $trafficSourceTokenValues = [];
        $trafficSourceTokens = $campaign['traffic_source_tokens'] ?? [];
        if (!empty($trafficSourceTokens) && is_array($trafficSourceTokens)) {
            foreach ($trafficSourceTokens as $idx => $tsToken) {
                $paramName = $tsToken['parameter'] ?? '';
                $tokenNum = $idx + 1;
                
                // Get value from extra_json - check traffic_source_tokens first, then all_params
                $value = null;
                if (!empty($extraJson['traffic_source_tokens'][$paramName])) {
                    $value = $extraJson['traffic_source_tokens'][$paramName];
                } elseif (!empty($extraJson['all_params'][$paramName])) {
                    $value = $extraJson['all_params'][$paramName];
                }
                
                $trafficSourceTokenValues["ts_token{$tokenNum}"] = $value ?? '';
            }
        }

        // Networks often send amount as payout; pixels often send value.
        // Both tokens resolve to a usable monetary amount so outbound URLs work either way.
        $conversionValue = self::resolveAmount($conversion['value'] ?? null, $conversion['payout'] ?? null);
        $conversionPayout = self::resolveAmount($conversion['payout'] ?? null, $conversion['value'] ?? null);

        // Build replacement map
        $replacements = [
            // Click data
            '{click_id}' => $click['click_id'] ?? '',
            '{campaign_id}' => (string)($campaign['id'] ?? ''),
            '{campaign_key}' => $campaign['campaign_key'] ?? '',
            '{campaign_name}' => $campaign['name'] ?? '',
            
            // Geo data
            '{location}' => $click['country'] ?? '',
            '{state}' => $click['region'] ?? '',
            '{city}' => $click['city'] ?? '',
            
            // Conversion data
            '{value}' => $conversionValue,
            '{payout}' => $conversionPayout,
            '{currency}' => $conversion['currency'] ?? 'USD',
            '{txid}' => $conversion['txid'] ?? '',
            '{event_id}' => $conversion['event_id'] ?? '',
            '{event_key}' => $conversion['event_key'] ?? '',
            '{timestamp}' => isset($conversion['ts']) ? (string)strtotime($conversion['ts']) : (string)time(),
            '{datetime}' => $conversion['ts'] ?? date('Y-m-d H:i:s'),
            
            // Campaign custom tokens
            '{token1}' => $customTokenValues['token1'] ?? '',
            '{token2}' => $customTokenValues['token2'] ?? '',
            '{token3}' => $customTokenValues['token3'] ?? '',
            '{token4}' => $customTokenValues['token4'] ?? '',
            '{token5}' => $customTokenValues['token5'] ?? '',
            '{token6}' => $customTokenValues['token6'] ?? '',
            '{token7}' => $customTokenValues['token7'] ?? '',
            '{token8}' => $customTokenValues['token8'] ?? '',
            '{token9}' => $customTokenValues['token9'] ?? '',
            '{token10}' => $customTokenValues['token10'] ?? '',
            '{token11}' => $customTokenValues['token11'] ?? '',
            '{token12}' => $customTokenValues['token12'] ?? '',
            '{token13}' => $customTokenValues['token13'] ?? '',
            '{token14}' => $customTokenValues['token14'] ?? '',
            '{token15}' => $customTokenValues['token15'] ?? '',
            '{token16}' => $customTokenValues['token16'] ?? '',
            '{token17}' => $customTokenValues['token17'] ?? '',
            '{token18}' => $customTokenValues['token18'] ?? '',
            '{token19}' => $customTokenValues['token19'] ?? '',
            '{token20}' => $customTokenValues['token20'] ?? '',
            
            // Traffic source custom tokens (distinct from campaign tokens)
            '{ts_token1}' => $trafficSourceTokenValues['ts_token1'] ?? '',
            '{ts_token2}' => $trafficSourceTokenValues['ts_token2'] ?? '',
            '{ts_token3}' => $trafficSourceTokenValues['ts_token3'] ?? '',
            '{ts_token4}' => $trafficSourceTokenValues['ts_token4'] ?? '',
            '{ts_token5}' => $trafficSourceTokenValues['ts_token5'] ?? '',
            '{ts_token6}' => $trafficSourceTokenValues['ts_token6'] ?? '',
            '{ts_token7}' => $trafficSourceTokenValues['ts_token7'] ?? '',
            '{ts_token8}' => $trafficSourceTokenValues['ts_token8'] ?? '',
            '{ts_token9}' => $trafficSourceTokenValues['ts_token9'] ?? '',
            '{ts_token10}' => $trafficSourceTokenValues['ts_token10'] ?? '',
            '{ts_token11}' => $trafficSourceTokenValues['ts_token11'] ?? '',
            '{ts_token12}' => $trafficSourceTokenValues['ts_token12'] ?? '',
            '{ts_token13}' => $trafficSourceTokenValues['ts_token13'] ?? '',
            '{ts_token14}' => $trafficSourceTokenValues['ts_token14'] ?? '',
            '{ts_token15}' => $trafficSourceTokenValues['ts_token15'] ?? '',
            '{ts_token16}' => $trafficSourceTokenValues['ts_token16'] ?? '',
            '{ts_token17}' => $trafficSourceTokenValues['ts_token17'] ?? '',
            '{ts_token18}' => $trafficSourceTokenValues['ts_token18'] ?? '',
            '{ts_token19}' => $trafficSourceTokenValues['ts_token19'] ?? '',
            '{ts_token20}' => $trafficSourceTokenValues['ts_token20'] ?? '',
        ];

        // URL encode values for safe insertion
        foreach ($replacements as $token => &$value) {
            $value = urlencode((string)$value);
        }
        unset($value);

        // Replace standard tokens first
        $replacedUrl = str_replace(array_keys($replacements), array_values($replacements), $postbackUrl);
        
        // Now handle unique traffic source tokens in format {ts:TrafficSourceName:parameter}
        // Pattern: {ts:TrafficSourceName:parameter}
        $pattern = '/\{ts:([a-zA-Z0-9]+):([a-zA-Z0-9_]+)\}/';
        $replacedUrl = preg_replace_callback($pattern, function($matches) use ($trafficSourceTokenMap, $extraJson) {
            $tsNameSanitized = $matches[1] ?? '';
            $paramName = $matches[2] ?? '';
            
            if (empty($tsNameSanitized) || empty($paramName)) {
                return $matches[0]; // Return original if parsing failed
            }
            
            // Find the traffic source token
            if (!isset($trafficSourceTokenMap[$tsNameSanitized])) {
                return ''; // Traffic source not found, replace with empty
            }
            
            // Get value from extra_json - check traffic_source_tokens first, then all_params
            $value = null;
            if (!empty($extraJson['traffic_source_tokens'][$paramName])) {
                $value = $extraJson['traffic_source_tokens'][$paramName];
            } elseif (!empty($extraJson['all_params'][$paramName])) {
                $value = $extraJson['all_params'][$paramName];
            }
            
            return urlencode((string)($value ?? ''));
        }, $replacedUrl);

        return $replacedUrl;
    }

    /**
     * Get list of available tokens with descriptions
     */
    public function getAvailableTokens(): array
    {
        return [
            'Built-in Tokens' => [
                '{click_id}' => 'The unique click ID',
                '{campaign_id}' => 'Campaign database ID',
                '{campaign_key}' => 'Campaign friendly key identifier',
                '{campaign_name}' => 'Campaign name',
                '{location}' => 'Country code',
                '{state}' => 'State/region name',
                '{city}' => 'City name',
                '{value}' => 'Conversion value/amount (falls back to payout)',
                '{payout}' => 'Conversion payout/amount (falls back to value)',
                '{currency}' => 'Currency code (e.g., USD)',
                '{txid}' => 'Transaction ID from affiliate network',
                '{event_id}' => 'Event ID for deduplication',
                '{event_key}' => 'Inbound funnel event key (from et=), empty when unset',
                '{timestamp}' => 'Conversion timestamp (Unix)',
                '{datetime}' => 'Conversion datetime (Y-m-d H:i:s)',
            ],
            'Custom Tokens' => [
                '{token1}' => 'Custom campaign token 1',
                '{token2}' => 'Custom campaign token 2',
                '{token3}' => 'Custom campaign token 3',
                '{token4}' => 'Custom campaign token 4',
                '{token5}' => 'Custom campaign token 5',
                '{token6}' => 'Custom campaign token 6',
                '{token7}' => 'Custom campaign token 7',
                '{token8}' => 'Custom campaign token 8',
                '{token9}' => 'Custom campaign token 9',
                '{token10}' => 'Custom campaign token 10',
                '{token11}' => 'Custom campaign token 11',
                '{token12}' => 'Custom campaign token 12',
                '{token13}' => 'Custom campaign token 13',
                '{token14}' => 'Custom campaign token 14',
                '{token15}' => 'Custom campaign token 15',
                '{token16}' => 'Custom campaign token 16',
                '{token17}' => 'Custom campaign token 17',
                '{token18}' => 'Custom campaign token 18',
                '{token19}' => 'Custom campaign token 19',
                '{token20}' => 'Custom campaign token 20',
            ],
            'Traffic Source Tokens' => [
                '{ts_token1}' => 'Custom traffic source token 1',
                '{ts_token2}' => 'Custom traffic source token 2',
                '{ts_token3}' => 'Custom traffic source token 3',
                '{ts_token4}' => 'Custom traffic source token 4',
                '{ts_token5}' => 'Custom traffic source token 5',
                '{ts_token6}' => 'Custom traffic source token 6',
                '{ts_token7}' => 'Custom traffic source token 7',
                '{ts_token8}' => 'Custom traffic source token 8',
                '{ts_token9}' => 'Custom traffic source token 9',
                '{ts_token10}' => 'Custom traffic source token 10',
                '{ts_token11}' => 'Custom traffic source token 11',
                '{ts_token12}' => 'Custom traffic source token 12',
                '{ts_token13}' => 'Custom traffic source token 13',
                '{ts_token14}' => 'Custom traffic source token 14',
                '{ts_token15}' => 'Custom traffic source token 15',
                '{ts_token16}' => 'Custom traffic source token 16',
                '{ts_token17}' => 'Custom traffic source token 17',
                '{ts_token18}' => 'Custom traffic source token 18',
                '{ts_token19}' => 'Custom traffic source token 19',
                '{ts_token20}' => 'Custom traffic source token 20',
            ],
        ];
    }

    /**
     * Prefer primary monetary field; fall back to alternate when empty.
     * Affiliate S2S often populates payout; pixels often populate value.
     */
    private static function resolveAmount(mixed $primary, mixed $fallback): string
    {
        if ($primary !== null && $primary !== '' && $primary !== false) {
            return (string)$primary;
        }
        if ($fallback !== null && $fallback !== '' && $fallback !== false) {
            return (string)$fallback;
        }
        return '';
    }
}

