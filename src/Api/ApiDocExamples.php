<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

/**
 * Copy-paste API examples for Settings documentation.
 */
class ApiDocExamples
{
    public static function apiBaseUrl(): string
    {
        return OpenApiBuilder::apiBaseUrl();
    }

    public static function healthCheck(): string
    {
        $base = self::apiBaseUrl();
        return "curl -s \"{$base}/health\"";
    }

    public static function catalog(): string
    {
        $base = self::apiBaseUrl();
        return "curl -s \"{$base}/catalog\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"";
    }

    public static function createOffer(): string
    {
        return self::offerExampleCurl('basic');
    }

    public static function createCampaignDto(): string
    {
        return self::campaignExampleCurl('dto');
    }

    /**
     * @param list<array<string, mixed>> $tabs
     * @param list<array{field: string, type: string, required: bool, description: string}> $fields
     * @return list<array<string, mixed>>
     */
    public static function appendReferenceTab(array $tabs, string $id, string $summary, array $fields): array
    {
        $tabs[] = [
            'id' => $id,
            'label' => 'Field reference',
            'summary' => $summary,
            'reference' => true,
            'fields' => $fields,
        ];

        return $tabs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function offerExamples(): array
    {
        $tabs = [
            [
                'id' => 'offer-basic',
                'label' => 'Basic',
                'summary' => 'Required fields — name, URL with tokens, payout, and network_id from GET /catalog.',
                'example' => self::offerExampleCurl('basic'),
            ],
            [
                'id' => 'offer-cap',
                'label' => 'Click cap',
                'summary' => 'Limit clicks per day, week, month, or lifetime. When cap is hit, is_available_for_rotation becomes false until the period resets.',
                'example' => self::offerExampleCurl('cap'),
            ],
            [
                'id' => 'offer-schedule',
                'label' => 'Day parting',
                'summary' => 'Run only on selected days and hours. Set is_24_7 to false and provide schedule_days, times, and timezone.',
                'example' => self::offerExampleCurl('schedule'),
            ],
            [
                'id' => 'offer-cap-schedule',
                'label' => 'Cap + day parting',
                'summary' => 'Both together — offer must pass schedule AND stay under the click cap to rotate.',
                'example' => self::offerExampleCurl('cap_schedule'),
            ],
        ];

        return $tabs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function campaignExamples(): array
    {
        $tabs = [
            [
                'id' => 'campaign-dto',
                'label' => 'DTO',
                'summary' => 'Direct-to-offer — rotation is a flat array of weighted offers.',
                'example' => self::campaignExampleCurl('dto'),
            ],
            [
                'id' => 'campaign-lp',
                'label' => 'LP',
                'summary' => 'Landing page then offer — rotation has landing_pages[] and offers[] (each group sums to 100).',
                'example' => self::campaignExampleCurl('lp'),
            ],
            [
                'id' => 'campaign-split',
                'label' => 'Split',
                'summary' => 'Split traffic between LP path and direct-to-offer path.',
                'example' => self::campaignExampleCurl('split'),
            ],
            [
                'id' => 'campaign-full',
                'label' => 'All fields',
                'summary' => 'Every optional POST /campaigns field with example values. Replace ids with your GET /catalog (and integration ids from Settings).',
                'example' => self::campaignExampleCurl('full'),
            ],
        ];

        return $tabs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function trackingLinkExamples(): array
    {
        $base = self::apiBaseUrl();
        $tabs = [
            [
                'id' => 'tracking-basic',
                'label' => 'By campaign id',
                'summary' => 'Default tracking URL using campaign_key in the path, with traffic-source tokens appended.',
                'example' => "curl -s \"{$base}/campaigns/1/tracking-link\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
            [
                'id' => 'tracking-slug',
                'label' => 'Custom slug',
                'summary' => 'Use ?slug= to build /km/{slug} instead of /km/{campaign_key} (slug must exist on the campaign).',
                'example' => "curl -s \"{$base}/campaigns/1/tracking-link?slug=roof-v1\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
        ];

        return $tabs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function statsExamples(): array
    {
        $base = self::apiBaseUrl();
        $tabs = [
            [
                'id' => 'stats-all',
                'label' => 'All campaigns',
                'summary' => 'Summary stats (clicks, conversions, cost, revenue, profit, ROI) for every campaign in the date range.',
                'example' => "curl -s \"{$base}/stats/campaigns?from=2026-06-01&to=2026-06-18&timezone=America/New_York\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
            [
                'id' => 'stats-one',
                'label' => 'Single campaign',
                'summary' => 'Same metrics for one campaign by id (includes lp_clicks).',
                'example' => "curl -s \"{$base}/stats/campaigns/1?from=2026-06-01&to=2026-06-18&timezone=America/New_York\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
            [
                'id' => 'stats-grouped-country',
                'label' => 'Grouped by country',
                'summary' => 'Break down one campaign by country — paginated rows plus meta.totals.',
                'example' => "curl -s \"{$base}/stats/campaigns/1?from=2026-06-01&to=2026-06-18&group_by=country&page=1&per_page=1000\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
            [
                'id' => 'stats-grouped-zone',
                'label' => 'Grouped by zoneid',
                'summary' => 'Token breakdown (use any token from GET /campaigns/{id}/tracking-link).',
                'example' => "curl -s \"{$base}/stats/campaigns/1?from=2026-06-01&to=2026-06-18&group_by=zoneid&page=1&per_page=500\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
            [
                'id' => 'stats-grouped-date',
                'label' => 'Grouped by day',
                'summary' => 'One row per day — replaces looping the summary endpoint per day.',
                'example' => "curl -s \"{$base}/stats/campaigns/1?from=2026-06-01&to=2026-06-18&group_by=date\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
        ];

        return $tabs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function conversionsExamples(): array
    {
        $base = self::apiBaseUrl();
        $tabs = [
            [
                'id' => 'conv-filtered',
                'label' => 'By campaign',
                'summary' => 'Paginated conversion rows for one campaign in a date range.',
                'example' => "curl -s \"{$base}/conversions?campaign_id=1&from=2026-06-01&to=2026-06-18&page=1&per_page=50\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
            [
                'id' => 'conv-all',
                'label' => 'All campaigns',
                'summary' => 'All conversions in range — omit campaign_id.',
                'example' => "curl -s \"{$base}/conversions?from=2026-06-01&to=2026-06-18&page=1&per_page=50\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
        ];

        return $tabs;
    }

    private static function offerExampleCurl(string $variant): string
    {
        $base = self::apiBaseUrl();
        $bodies = [
            'basic' => <<<'JSON'
{
  "name": "Keto CPA Offer",
  "url": "https://affiliate.example.com/click?cid={click_id}",
  "payout_type": "CPA",
  "payout_value": 45.00,
  "network_id": 1,
  "notes": "Optional notes",
  "cap_enabled": false,
  "is_24_7": true
}
JSON,
            'cap' => <<<'JSON'
{
  "name": "Capped Roof Offer",
  "url": "https://affiliate.example.com/click?cid={click_id}",
  "payout_type": "CPA",
  "payout_value": 55.00,
  "network_id": 1,
  "cap_enabled": true,
  "cap_limit": 100,
  "cap_period": "day",
  "cap_timezone": "America/New_York",
  "is_24_7": true
}
JSON,
            'schedule' => <<<'JSON'
{
  "name": "Weekday Business Hours Offer",
  "url": "https://affiliate.example.com/click?cid={click_id}",
  "payout_type": "CPA",
  "payout_value": 40.00,
  "network_id": 1,
  "is_24_7": false,
  "schedule_days": ["monday", "tuesday", "wednesday", "thursday", "friday"],
  "schedule_start_time": "09:00:00",
  "schedule_end_time": "17:00:00",
  "schedule_timezone": "America/New_York",
  "cap_enabled": false
}
JSON,
            'cap_schedule' => <<<'JSON'
{
  "name": "Capped + Day-parted Offer",
  "url": "https://affiliate.example.com/click?cid={click_id}",
  "payout_type": "CPA",
  "payout_value": 50.00,
  "network_id": 1,
  "is_24_7": false,
  "schedule_days": ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday"],
  "schedule_start_time": "08:00:00",
  "schedule_end_time": "22:00:00",
  "schedule_timezone": "America/Los_Angeles",
  "cap_enabled": true,
  "cap_limit": 250,
  "cap_period": "week",
  "cap_timezone": "America/Los_Angeles"
}
JSON,
        ];

        return self::postJsonCurl("{$base}/offers", $bodies[$variant] ?? $bodies['basic']);
    }

    private static function campaignExampleCurl(string $variant): string
    {
        $base = self::apiBaseUrl();
        $bodies = [
            'dto' => <<<'JSON'
{
  "name": "AI Keto DTO Campaign",
  "flow_type": "DTO",
  "traffic_source_id": 4,
  "tracking_domain_id": 2,
  "status": "active",
  "rotation": [
    { "type": "offer", "id": 5, "weight": 70, "enabled": true },
    { "type": "offer", "id": 8, "weight": 30, "enabled": true }
  ]
}
JSON,
            'lp' => <<<'JSON'
{
  "name": "FB Roof LP Campaign",
  "flow_type": "LP",
  "traffic_source_id": 4,
  "tracking_domain_id": 2,
  "status": "active",
  "rotation": {
    "landing_pages": [
      { "type": "landing_page", "id": 1, "weight": 50, "enabled": true },
      { "type": "landing_page", "id": 3, "weight": 50, "enabled": true }
    ],
    "offers": [
      { "type": "offer", "id": 5, "weight": 100, "enabled": true }
    ]
  }
}
JSON,
            'split' => <<<'JSON'
{
  "name": "Split LP vs DTO Test",
  "flow_type": "Split",
  "traffic_source_id": 4,
  "status": "active",
  "rotation": {
    "split_traffic": { "lp_percent": 50, "direct_percent": 50 },
    "lp_path": {
      "landing_pages": [{ "type": "landing_page", "id": 1, "weight": 100, "enabled": true }],
      "offers": [{ "type": "offer", "id": 5, "weight": 100, "enabled": true }]
    },
    "direct_path": {
      "offers": [{ "type": "offer", "id": 5, "weight": 100, "enabled": true }]
    }
  }
}
JSON,
            'full' => <<<'JSON'
{
  "name": "Full LP Campaign Example",
  "flow_type": "LP",
  "traffic_source_id": 4,
  "tracking_domain_id": 2,
  "campaign_group_id": 1,
  "fallback_offer_id": 5,
  "status": "active",
  "default_cpc": 1.25,
  "min_postback_payout": 10.00,
  "allow_multiple_conversions": false,
  "referrer_mode": "blank",
  "rotation": {
    "landing_pages": [
      { "type": "landing_page", "id": 1, "weight": 100, "enabled": true }
    ],
    "offers": [
      { "type": "offer", "id": 5, "weight": 100, "enabled": true }
    ]
  },
  "slugs": [
    { "slug": "roof-v1", "slug_label": "Creative A" }
  ],
  "custom_tokens": [
    {
      "name": "SubID",
      "parameter": "subid",
      "placeholder": "{click_id}",
      "pass_to_lp": true,
      "pass_to_offer": true
    }
  ],
  "redirect_rules": [
    {
      "token_name": "subid",
      "token_source": "custom",
      "operator": "not_equals",
      "value": "blocked",
      "case_sensitive": false,
      "redirect_url": "https://example.com/safe-page",
      "execute_on": ["campaign_click", "offer_click"]
    }
  ],
  "custom_postback_ids": [1],
  "traffic_source_postbacks": {
    "4": {
      "facebook_capi_integration_id": 1,
      "google_ads_integration_id": null
    }
  },
  "facebook_capi_integration_id": 1,
  "facebook_marketing_integration_id": 1,
  "facebook_marketing_ad_account_id": 1,
  "facebook_marketing_campaign_id": 1,
  "google_ads_integration_id": null
}
JSON,
        ];

        return self::postJsonCurl("{$base}/campaigns", $bodies[$variant] ?? $bodies['dto']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function clicksExamples(): array
    {
        $base = self::apiBaseUrl();
        $tabs = [
            [
                'id' => 'clicks-filtered',
                'label' => 'By campaign',
                'summary' => 'Paginated click/visitor rows for one campaign.',
                'example' => "curl -s \"{$base}/clicks?campaign_id=1&from=2026-06-01&to=2026-06-18&page=1&per_page=50&timezone=America/New_York\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
            [
                'id' => 'clicks-all',
                'label' => 'All campaigns',
                'summary' => 'All clicks in range — omit campaign_id.',
                'example' => "curl -s \"{$base}/clicks?from=2026-06-01&to=2026-06-18&page=1&per_page=50\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
            ],
        ];

        return $tabs;
    }

    private static function postJsonCurl(string $url, string $jsonBody): string
    {
        $body = trim($jsonBody);
        $bodyIndented = '  ' . str_replace("\n", "\n  ", $body);

        return <<<CURL
curl -s -X POST "{$url}" \\
  -H "Authorization: Bearer kuma_YOUR_KEY_HERE" \\
  -H "Content-Type: application/json" \\
  -d '{$bodyIndented}'
CURL;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rotationExamples(): array
    {
        $tabs = [
            [
                'id' => 'rot-dto',
                'label' => 'DTO',
                'summary' => 'flow_type DTO — rotation is a flat array of offers.',
                'example' => self::rotationDtoJson(),
            ],
            [
                'id' => 'rot-lp',
                'label' => 'LP',
                'summary' => 'flow_type LP — object with landing_pages[] and offers[].',
                'example' => self::rotationLpJson(),
            ],
            [
                'id' => 'rot-split',
                'label' => 'Split',
                'summary' => 'flow_type Split — split_traffic percentages plus lp_path and direct_path.',
                'example' => self::rotationSplitJson(),
            ],
        ];

        return $tabs;
    }

    public static function trackingLink(int $campaignId = 1): string
    {
        $base = self::apiBaseUrl();
        return "curl -s \"{$base}/campaigns/{$campaignId}/tracking-link\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"";
    }

    public static function campaignStats(): string
    {
        $base = self::apiBaseUrl();
        return "curl -s \"{$base}/stats/campaigns?from=2026-06-01&to=2026-06-18&timezone=America/New_York\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"";
    }

    public static function conversions(): string
    {
        $base = self::apiBaseUrl();
        return "curl -s \"{$base}/conversions?campaign_id=1&from=2026-06-01&to=2026-06-18&page=1&per_page=50\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"";
    }

    public static function rotationDtoJson(): string
    {
        return '[{"type":"offer","id":1,"weight":70,"enabled":true},{"type":"offer","id":2,"weight":30,"enabled":true}]';
    }

    public static function rotationLpJson(): string
    {
        return '{"landing_pages":[{"type":"landing_page","id":1,"weight":100,"enabled":true}],"offers":[{"type":"offer","id":1,"weight":100,"enabled":true}]}';
    }

    public static function rotationSplitJson(): string
    {
        return '{"split_traffic":{"lp_percent":50,"direct_percent":50},"lp_path":{"landing_pages":[{"type":"landing_page","id":1,"weight":100,"enabled":true}],"offers":[{"type":"offer","id":1,"weight":100,"enabled":true}]},"direct_path":{"offers":[{"type":"offer","id":1,"weight":100,"enabled":true}]}}';
    }

    /**
     * Guided quick-start steps for the API docs page.
     *
     * @return list<array{
     *   id: string,
     *   title: string,
     *   summary: string,
     *   curl?: string,
     *   expect?: string,
     *   expectNote?: string,
     *   tips?: list<string>,
     *   action?: array{type: string, label: string, href: string}
     * }>
     */
    public static function quickStartSteps(): array
    {
        $base = self::apiBaseUrl();

        return [
            [
                'id' => 'qs-key',
                'title' => 'Create an API key',
                'summary' => 'Generate a key on this page (above). It is shown once at creation — copy it immediately. You will send it as Authorization: Bearer kuma_... on every authenticated request.',
                'action' => [
                    'type' => 'anchor',
                    'label' => 'Go to API keys',
                    'href' => '#api-keys-section',
                ],
            ],
            [
                'id' => 'qs-health',
                'title' => 'Confirm the API is reachable',
                'summary' => 'Ping /health with no auth. If you get status ok, your base URL and server routing are working.',
                'curl' => self::healthCheck(),
                'expect' => '{"data":{"status":"ok","version":"1.0.0"},"meta":null}',
                'expectNote' => 'HTTP 200 — no Bearer header needed.',
            ],
            [
                'id' => 'qs-catalog',
                'title' => 'Bootstrap with the catalog',
                'summary' => 'One call loads every id you need: traffic_sources, offers, landing_pages, networks, campaign_groups, and verified tracking_domains. Save these ids for create/update calls.',
                'curl' => self::catalog(),
                'expect' => '{"data":{"traffic_sources":[...],"offers":[...],"landing_pages":[...],...},"meta":null}',
                'expectNote' => 'HTTP 200 — check data.traffic_sources[].id and data.offers[].id before creating campaigns.',
                'tips' => [
                    'Offers include is_available_for_rotation (respects caps and day-parting).',
                    'Use tracking_domains[].id as tracking_domain_id on campaigns.',
                ],
            ],
            [
                'id' => 'qs-campaign',
                'title' => 'Create your first campaign',
                'summary' => 'POST a DTO campaign using ids from the catalog response. Replace traffic_source_id and offer ids with yours. Enabled rotation weights must sum to 100.',
                'curl' => self::createCampaignDto(),
                'expect' => '{"data":{"id":12,"campaign_key":"...","tracking_url":"https://.../km/...?...","...": "..."},"meta":null}',
                'expectNote' => 'HTTP 201 — copy data.tracking_url into your ad platform, or fetch it anytime via GET /campaigns/{id}/tracking-link.',
                'tips' => [
                    'Need LP or Split instead? See Request examples → Create campaign.',
                ],
            ],
            [
                'id' => 'qs-stats',
                'title' => 'Pull today’s stats (optional)',
                'summary' => 'Once clicks are flowing, summarize performance for a date range. Omit campaign id to get all campaigns, or use /stats/campaigns/{id} for one.',
                'curl' => "curl -s \"{$base}/stats/campaigns?from=2026-06-01&to=2026-06-18&timezone=America/New_York\" \\\n  -H \"Authorization: Bearer kuma_YOUR_KEY_HERE\"",
                'expect' => '{"data":[{"campaign_id":1,"clicks":0,"conversions":0,"revenue":0,"profit":0,"roi":0,...}],"meta":null}',
                'expectNote' => 'HTTP 200 — dates use the timezone param for day boundaries.',
            ],
        ];
    }
}
