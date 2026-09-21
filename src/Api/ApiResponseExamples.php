<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

/**
 * Sample API response bodies for documentation (Hermes / agent parsing).
 */
class ApiResponseExamples
{
    /** @param array<string, mixed>|list<mixed> $payload */
    private static function prettyJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** @param list<array<string, mixed>> $tabs @return list<array<string, mixed>> */
    private static function markJsonTabs(array $tabs): array
    {
        foreach ($tabs as &$tab) {
            if (empty($tab['reference']) && !empty($tab['example'])) {
                $tab['is_json'] = true;
            }
        }
        unset($tab);

        return $tabs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function envelopeExamples(): array
    {
        $tabs = [
            [
                'id' => 'resp-health',
                'label' => 'GET /health',
                'summary' => 'No Bearer token required — confirms API is up.',
                'example' => self::prettyJson([
                    'data' => [
                        'status' => 'ok',
                        'version' => '1.0.0',
                    ],
                    'meta' => null,
                ]),
            ],
            [
                'id' => 'resp-success',
                'label' => 'Success object',
                'summary' => 'Most endpoints return a single resource or object in data. meta is null unless noted.',
                'example' => self::prettyJson([
                    'data' => [
                        'id' => 1,
                        'name' => 'Example',
                    ],
                    'meta' => null,
                ]),
            ],
            [
                'id' => 'resp-list',
                'label' => 'Paginated list',
                'summary' => 'GET /clicks and GET /conversions use Response::list — data is an array, meta has pagination.',
                'example' => self::prettyJson([
                    'data' => [
                        ['id' => 1, 'click_id' => 'abc123'],
                    ],
                    'meta' => [
                        'page' => 1,
                        'per_page' => 50,
                        'total' => 1234,
                    ],
                ]),
            ],
            [
                'id' => 'resp-error',
                'label' => 'Error',
                'summary' => 'HTTP 4xx/5xx — error.code is a machine-readable string; error.message is human text.',
                'example' => self::prettyJson([
                    'error' => [
                        'code' => 'unauthorized',
                        'message' => 'Unauthorized - Invalid or revoked API key',
                    ],
                ]),
            ],
            [
                'id' => 'resp-validation',
                'label' => 'Validation (422)',
                'summary' => 'POST/PATCH validation failures include fields keyed by input name.',
                'example' => self::prettyJson([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => 'Validation failed',
                    ],
                    'fields' => [
                        'traffic_source_id' => 'Please select a traffic source.',
                        'rotation' => 'Enabled offer weights must sum to 100%',
                    ],
                ]),
            ],
        ];

        return self::markJsonTabs($tabs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function catalogResponseExamples(): array
    {
        $tabs = [
            [
                'id' => 'resp-catalog',
                'label' => 'Sample response',
                'summary' => 'GET /catalog — bootstrap ids for campaign/offer creation. Arrays may be empty.',
                'example' => self::prettyJson([
                    'data' => [
                        'traffic_sources' => [
                            [
                                'id' => 4,
                                'name' => 'Facebook',
                                'cost_param_key' => '',
                                'cost_tracking_method' => 'integrated_api',
                                'tokens' => [],
                            ],
                        ],
                        'offers' => [
                            [
                                'id' => 1,
                                'name' => 'Test Offer',
                                'url' => 'https://example.com/?cid={click_id}',
                                'payout_type' => 'CPA',
                                'payout_value' => 45,
                                'network_id' => 1,
                                'network_name' => 'Example Network',
                                'is_available_for_rotation' => true,
                            ],
                        ],
                        'landing_pages' => [
                            ['id' => 1, 'name' => 'LP 1', 'url' => 'https://tracker.example/lp/'],
                        ],
                        'networks' => [
                            ['id' => 1, 'name' => 'Example Network'],
                        ],
                        'campaign_groups' => [
                            ['id' => 1, 'name' => 'Home Services'],
                        ],
                        'tracking_domains' => [
                            ['id' => 2, 'domain' => 'track.example.com', 'url' => 'https://track.example.com'],
                        ],
                    ],
                    'meta' => null,
                ]),
            ],
        ];

        return self::markJsonTabs($tabs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function campaignCreateResponseExamples(): array
    {
        $tabs = [
            [
                'id' => 'resp-campaign-create',
                'label' => 'POST /campaigns (201)',
                'summary' => 'Create returns the full campaign object plus tracking_url. campaign_key is the default /km/{key} identifier.',
                'example' => self::prettyJson([
                    'data' => [
                        'id' => 12,
                        'campaign_key' => 'cXample1',
                        'name' => 'Full LP Campaign Example',
                        'status' => 'active',
                        'flow_type' => 'LP',
                        'traffic_source_id' => 4,
                        'traffic_source_name' => 'Facebook',
                        'tracking_domain_id' => 2,
                        'rotation' => [
                            'landing_pages' => [
                                ['type' => 'landing_page', 'id' => 1, 'weight' => 100, 'enabled' => true],
                            ],
                            'offers' => [
                                ['type' => 'offer', 'id' => 5, 'weight' => 100, 'enabled' => true],
                            ],
                        ],
                        'slugs' => [
                            ['id' => 3, 'slug' => 'roof-v1', 'slug_label' => 'Creative A'],
                        ],
                        'tracking_url' => 'https://track.example.com/km/cXample1?subid={click_id}',
                        'created_at' => '2026-06-18 14:30:00',
                        'updated_at' => null,
                    ],
                    'meta' => null,
                ]),
            ],
        ];

        return self::markJsonTabs($tabs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function trackingLinkResponseExamples(): array
    {
        $tabs = [
            [
                'id' => 'resp-tracking',
                'label' => 'Sample response',
                'summary' => 'GET /campaigns/{id}/tracking-link — use data.url in ad platforms.',
                'example' => self::prettyJson([
                    'data' => [
                        'url' => 'https://track.example.com/km/roof-v1?subid={click_id}&cost={value}',
                        'base_url' => 'https://track.example.com/km/roof-v1',
                        'identifier' => 'roof-v1',
                        'tokens' => ['subid', 'cost'],
                    ],
                    'meta' => null,
                ]),
            ],
        ];

        return self::markJsonTabs($tabs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function statsResponseExamples(): array
    {
        $tabs = [
            [
                'id' => 'resp-stats-all',
                'label' => 'All campaigns',
                'summary' => 'GET /stats/campaigns — data is an array of per-campaign summary rows for the date range.',
                'example' => self::prettyJson([
                    'data' => [
                        [
                            'campaign_id' => 1,
                            'campaign_name' => 'Test Campaign 1',
                            'status' => 'active',
                            'clicks' => 1250,
                            'lp_clicks' => 980,
                            'conversions' => 42,
                            'cost' => 315.5,
                            'revenue' => 1890,
                            'profit' => 1574.5,
                            'roi' => 498.57,
                            'conversion_rate' => 3.36,
                            'date_from' => '2026-06-01',
                            'date_to' => '2026-06-18',
                            'timezone' => 'America/New_York',
                        ],
                    ],
                    'meta' => null,
                ]),
            ],
            [
                'id' => 'resp-stats-one',
                'label' => 'Single campaign',
                'summary' => 'GET /stats/campaigns/{id} — data is one object (not an array).',
                'example' => self::prettyJson([
                    'data' => [
                        'campaign_id' => 1,
                        'campaign_name' => 'Test Campaign 1',
                        'status' => 'active',
                        'clicks' => 1250,
                        'lp_clicks' => 980,
                        'conversions' => 42,
                        'cost' => 315.5,
                        'revenue' => 1890,
                        'profit' => 1574.5,
                        'roi' => 498.57,
                        'conversion_rate' => 3.36,
                        'date_from' => '2026-06-01',
                        'date_to' => '2026-06-18',
                        'timezone' => 'America/New_York',
                    ],
                    'meta' => null,
                ]),
            ],
            [
                'id' => 'resp-stats-grouped',
                'label' => 'Grouped by country',
                'summary' => 'GET /stats/campaigns/{id}?group_by=country — data is an array of rows; meta includes pagination and totals.',
                'example' => self::prettyJson([
                    'data' => [
                        [
                            'group' => 'US',
                            'clicks' => 1240,
                            'lp_clicks' => 980,
                            'conversions' => 31,
                            'cost' => 86.4,
                            'revenue' => 142.1,
                            'profit' => 55.7,
                            'roi' => 64.46,
                            'conversion_rate' => 2.5,
                        ],
                        [
                            'group' => 'DE',
                            'clicks' => 512,
                            'lp_clicks' => 400,
                            'conversions' => 9,
                            'cost' => 33.1,
                            'revenue' => 54.0,
                            'profit' => 20.9,
                            'roi' => 63.14,
                            'conversion_rate' => 1.76,
                        ],
                    ],
                    'meta' => [
                        'page' => 1,
                        'per_page' => 1000,
                        'total' => 37,
                        'group_by' => 'country',
                        'campaign_id' => 3,
                        'date_from' => '2026-06-01',
                        'date_to' => '2026-06-23',
                        'timezone' => 'UTC',
                        'totals' => [
                            'clicks' => 1840,
                            'lp_clicks' => 1200,
                            'conversions' => 41,
                            'cost' => 123.7,
                            'revenue' => 200.0,
                            'profit' => 76.3,
                            'roi' => 61.68,
                            'conversion_rate' => 2.23,
                        ],
                    ],
                ]),
            ],
        ];

        return self::markJsonTabs($tabs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function conversionsResponseExamples(): array
    {
        $tabs = [
            [
                'id' => 'resp-conversions',
                'label' => 'Sample response',
                'summary' => 'GET /conversions — paginated list. Loop pages while meta.page * meta.per_page < meta.total.',
                'example' => self::prettyJson([
                    'data' => [
                        [
                            'id' => 501,
                            'click_id' => 'clk_abc123xyz',
                            'campaign_id' => 1,
                            'campaign_name' => 'Test Campaign 1',
                            'offer_id' => 5,
                            'ts' => '2026-06-18 12:04:33',
                            'payout' => 45,
                            'value' => null,
                            'revenue' => 45,
                        ],
                    ],
                    'meta' => [
                        'page' => 1,
                        'per_page' => 50,
                        'total' => 128,
                    ],
                ]),
            ],
        ];

        return self::markJsonTabs($tabs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function clicksResponseExamples(): array
    {
        $tabs = [
            [
                'id' => 'resp-clicks',
                'label' => 'Sample response',
                'summary' => 'GET /clicks — paginated visitor log rows. Same meta shape as conversions.',
                'example' => self::prettyJson([
                    'data' => [
                        [
                            'click_id' => 'clk_abc123xyz',
                            'campaign_id' => 1,
                            'campaign_name' => 'Test Campaign 1',
                            'offer_id' => 5,
                            'landing_page_id' => 1,
                            'traffic_source_id' => 4,
                            'ip' => '203.0.113.10',
                            'country' => 'US',
                            'ts' => '2026-06-18 11:58:01',
                            'cost' => 0.85,
                            'lp_click' => true,
                            'has_conversion' => true,
                            'conversion_payout' => 45,
                        ],
                    ],
                    'meta' => [
                        'page' => 1,
                        'per_page' => 50,
                        'total' => 1250,
                    ],
                ]),
            ],
        ];

        return self::markJsonTabs($tabs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function offerResponseExamples(): array
    {
        $tabs = [
            [
                'id' => 'resp-offer-get',
                'label' => 'GET /offers (list or one)',
                'summary' => 'GET responses include is_available_for_rotation (schedule + cap aware).',
                'example' => self::prettyJson([
                    'data' => [
                        'id' => 8,
                        'name' => 'Keto CPA Offer',
                        'url' => 'https://affiliate.example.com/click?cid={click_id}',
                        'payout_type' => 'CPA',
                        'payout_value' => 45,
                        'network_id' => 1,
                        'network_name' => 'Example Network',
                        'is_24_7' => true,
                        'schedule_days' => [],
                        'cap_enabled' => false,
                        'is_available_for_rotation' => true,
                        'created_at' => '2026-06-18 14:00:00',
                        'updated_at' => null,
                    ],
                    'meta' => null,
                ]),
            ],
            [
                'id' => 'resp-offer',
                'label' => 'POST /offers (201)',
                'summary' => 'Create response — same offer fields without is_available_for_rotation.',
                'example' => self::prettyJson([
                    'data' => [
                        'id' => 8,
                        'name' => 'Keto CPA Offer',
                        'url' => 'https://affiliate.example.com/click?cid={click_id}',
                        'payout_type' => 'CPA',
                        'payout_value' => 45,
                        'network_id' => 1,
                        'network_name' => null,
                        'is_24_7' => true,
                        'cap_enabled' => false,
                        'created_at' => '2026-06-18 14:00:00',
                        'updated_at' => null,
                    ],
                    'meta' => null,
                ]),
            ],
        ];

        return self::markJsonTabs($tabs);
    }
}
