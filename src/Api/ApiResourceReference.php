<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

/**
 * Complete per-resource API reference for documentation UI.
 *
 * @phpstan-type EndpointRow array{method: string, path: string, body: string, response: string, http: string}
 */
class ApiResourceReference
{
    /**
     * @return list<array{
     *   id: string,
     *   label: string,
     *   summary: string,
     *   endpoints: list<EndpointRow>,
     *   request_fields: list<array{field: string, type: string, required: bool, description: string}>,
     *   response_sections: list<array{title: string, note: string, fields: list<array{field: string, type: string, required: bool, description: string}>}>,
     *   samples: list<array{id: string, label: string, json: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            self::envelope(),
            self::catalog(),
            self::networks(),
            self::offers(),
            self::landingPages(),
            self::campaigns(),
            self::trackingLink(),
            self::stats(),
            self::clicks(),
            self::conversions(),
        ];
    }

    /** @return array<string, mixed> */
    private static function envelope(): array
    {
        return [
            'id' => 'ref-envelope',
            'label' => 'Envelopes',
            'summary' => 'Every response starts here. Check for error before reading data. GET /health uses the success envelope with no auth.',
            'endpoints' => [
                self::ep('GET', '/health', '—', '{ status, version } in data — no Bearer token', '200'),
            ],
            'request_fields' => [],
            'response_sections' => [
                ['title' => 'Success (object)', 'note' => 'data holds the resource; meta is null.', 'fields' => ApiFieldReference::envelopeFields()],
                ['title' => 'GET /health data', 'note' => 'No authentication required.', 'fields' => ApiFieldReference::healthResponseFields()],
                ['title' => 'Paginated list', 'note' => 'data is an array; meta has page, per_page, total.', 'fields' => ApiFieldReference::paginationMetaFields()],
                ['title' => 'Error', 'note' => 'HTTP 4xx/5xx — no data key.', 'fields' => ApiFieldReference::errorFields()],
                ['title' => 'Validation (422)', 'note' => 'POST/PATCH failed validation.', 'fields' => ApiFieldReference::validationFields()],
            ],
            'samples' => self::pickSamples(ApiResponseExamples::envelopeExamples()),
        ];
    }

    /** @return array<string, mixed> */
    private static function catalog(): array
    {
        return [
            'id' => 'ref-catalog',
            'label' => 'Catalog',
            'summary' => 'Bootstrap ids — traffic sources, offers, LPs, networks, groups, tracking domains.',
            'endpoints' => [
                self::ep('GET', '/catalog', '—', 'Bootstrap object in data', '200'),
            ],
            'request_fields' => [],
            'response_sections' => [
                ['title' => 'data object', 'note' => 'All keys are arrays (may be empty).', 'fields' => ApiFieldReference::catalogResponseFields()],
                ['title' => 'traffic_sources[] item', 'note' => 'Nested fields per traffic source.', 'fields' => ApiFieldReference::catalogTrafficSourceFields()],
                ['title' => 'tracking_domains[] item', 'note' => 'Verified domains only.', 'fields' => ApiFieldReference::catalogTrackingDomainFields()],
            ],
            'samples' => self::pickSamples(ApiResponseExamples::catalogResponseExamples()),
        ];
    }

    /** @return array<string, mixed> */
    private static function networks(): array
    {
        return [
            'id' => 'ref-networks',
            'label' => 'Networks',
            'summary' => 'Affiliate network labels used by offers.',
            'endpoints' => [
                self::ep('GET', '/networks', '—', 'Network[] in data', '200'),
                self::ep('GET', '/networks/{id}', '—', 'Network object in data', '200'),
                self::ep('POST', '/networks', 'JSON body', 'Created network in data', '201'),
                self::ep('PATCH', '/networks/{id}', 'Partial JSON', 'Updated network in data', '200'),
                self::ep('DELETE', '/networks/{id}', '—', '{ deleted, id } in data', '200'),
            ],
            'request_fields' => ApiFieldReference::networkPostFields(),
            'response_sections' => [
                ['title' => 'Network resource', 'note' => 'GET, POST (201), PATCH responses.', 'fields' => ApiFieldReference::networkResponseFields()],
                ['title' => 'GET list vs one', 'note' => 'GET /networks → data is an array. GET /networks/{id} → data is one object.', 'fields' => []],
                ['title' => 'DELETE response', 'note' => 'data.deleted is true.', 'fields' => ApiFieldReference::deleteResponseFields()],
            ],
            'samples' => array_merge(self::networkSamples(), self::deleteSample('network', 1)),
        ];
    }

    /** @return array<string, mixed> */
    private static function offers(): array
    {
        return [
            'id' => 'ref-offers',
            'label' => 'Offers',
            'summary' => 'Affiliate offers with caps, scheduling, and rotation availability.',
            'endpoints' => [
                self::ep('GET', '/offers', '—', 'Offer[] in data', '200'),
                self::ep('GET', '/offers/{id}', '—', 'Offer object in data', '200'),
                self::ep('POST', '/offers', 'JSON body', 'Created offer in data', '201'),
                self::ep('PATCH', '/offers/{id}', 'Partial JSON', 'Updated offer in data', '200'),
                self::ep('DELETE', '/offers/{id}', '—', '{ deleted, id } in data', '200'),
            ],
            'request_fields' => array_merge(ApiFieldReference::offerPostFields(), [
                ['field' => '(PATCH note)', 'type' => '—', 'required' => false, 'description' => 'Send only fields to change; omitted fields keep existing values.'],
            ]),
            'response_sections' => [
                ['title' => 'Offer resource', 'note' => 'All GET/POST/PATCH responses use this shape (see is_available note below).', 'fields' => ApiFieldReference::offerResponseFieldsFull()],
                ['title' => 'GET list vs one', 'note' => 'GET /offers → data is an array. GET /offers/{id} → data is one object.', 'fields' => []],
                ['title' => 'DELETE response', 'note' => 'data.deleted is true.', 'fields' => ApiFieldReference::deleteResponseFields()],
            ],
            'samples' => array_merge(
                self::pickSamples(ApiResponseExamples::offerResponseExamples()),
                self::deleteSample('offer', 5)
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function landingPages(): array
    {
        return [
            'id' => 'ref-landing-pages',
            'label' => 'Landing pages',
            'summary' => 'LP rotation targets for LP and Split campaigns.',
            'endpoints' => [
                self::ep('GET', '/landing-pages', '—', 'LandingPage[] in data', '200'),
                self::ep('GET', '/landing-pages/{id}', '—', 'LandingPage object in data', '200'),
                self::ep('POST', '/landing-pages', 'JSON body', 'Created LP in data', '201'),
                self::ep('PATCH', '/landing-pages/{id}', 'Partial JSON', 'Updated LP in data', '200'),
                self::ep('DELETE', '/landing-pages/{id}', '—', '{ deleted, id } in data', '200'),
            ],
            'request_fields' => ApiFieldReference::landingPagePostFields(),
            'response_sections' => [
                ['title' => 'Landing page resource', 'note' => 'GET, POST (201), PATCH responses.', 'fields' => ApiFieldReference::landingPageResponseFields()],
                ['title' => 'GET list vs one', 'note' => 'GET /landing-pages → data is an array. GET /landing-pages/{id} → data is one object.', 'fields' => []],
                ['title' => 'DELETE response', 'note' => 'data.deleted is true.', 'fields' => ApiFieldReference::deleteResponseFields()],
            ],
            'samples' => array_merge(self::landingPageSamples(), self::deleteSample('landing page', 1)),
        ];
    }

    /** @return array<string, mixed> */
    private static function campaigns(): array
    {
        return [
            'id' => 'ref-campaigns',
            'label' => 'Campaigns',
            'summary' => 'Campaign CRUD, status updates, rotation, slugs, integrations.',
            'endpoints' => [
                self::ep('GET', '/campaigns', '—', 'Campaign[] in data', '200'),
                self::ep('GET', '/campaigns/{id}', '—', 'Campaign object in data', '200'),
                self::ep('POST', '/campaigns', 'JSON body', 'Campaign + tracking_url in data', '201'),
                self::ep('PATCH', '/campaigns/{id}', 'Partial JSON', 'Updated campaign in data', '200'),
                self::ep('PATCH', '/campaigns/{id}/status', '{ "status": "..." }', '{ id, status } in data', '200'),
            ],
            'request_fields' => array_merge(ApiFieldReference::campaignPostFields(), [
                ['field' => '(PATCH note)', 'type' => '—', 'required' => false, 'description' => 'Partial merge — send only fields to change. rotation, slugs, tokens merge/replace per controller logic.'],
                ['field' => 'status (status endpoint)', 'type' => 'string', 'required' => true, 'description' => 'PATCH /campaigns/{id}/status only — active, paused, or archived.'],
            ]),
            'response_sections' => [
                ['title' => 'Campaign resource', 'note' => 'GET list, GET one, PATCH responses.', 'fields' => ApiFieldReference::campaignResponseFieldsFull()],
                ['title' => 'GET list vs one', 'note' => 'GET /campaigns → data is an array. GET /campaigns/{id} → data is one object.', 'fields' => []],
                ['title' => 'POST /campaigns (201)', 'note' => 'Same as campaign resource plus tracking_url.', 'fields' => array_merge(ApiFieldReference::campaignResponseFieldsFull(), [
                    ['field' => 'tracking_url', 'type' => 'string', 'required' => true, 'description' => 'Only on create — use in ad platform.'],
                ])],
                ['title' => 'PATCH /status response', 'note' => 'Minimal payload.', 'fields' => ApiFieldReference::campaignStatusResponseFields()],
                ['title' => 'rotation[] item', 'note' => 'Nested in rotation — see Rotation section for DTO/LP/Split shapes.', 'fields' => ApiFieldReference::rotationItemFields()],
            ],
            'samples' => array_merge(
                self::pickSamples(ApiResponseExamples::campaignCreateResponseExamples()),
                self::campaignExtraSamples()
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function trackingLink(): array
    {
        return [
            'id' => 'ref-tracking',
            'label' => 'Tracking link',
            'summary' => 'Built tracking URL with tokens for a campaign.',
            'endpoints' => [
                self::ep('GET', '/campaigns/{id}/tracking-link', 'Query: ?slug=', 'Tracking URL object in data', '200'),
            ],
            'request_fields' => ApiFieldReference::trackingLinkParams(),
            'response_sections' => [
                ['title' => 'data object', 'note' => 'Use data.url in ads.', 'fields' => ApiFieldReference::trackingLinkResponseFields()],
            ],
            'samples' => self::pickSamples(ApiResponseExamples::trackingLinkResponseExamples()),
        ];
    }

    /** @return array<string, mixed> */
    private static function stats(): array
    {
        return [
            'id' => 'ref-stats',
            'label' => 'Stats',
            'summary' => 'Campaign performance summary for a date range. Use group_by to break down a single campaign by day, geo, device, offer, landing page, or traffic tokens (zoneid, subid, etc.).',
            'endpoints' => [
                self::ep('GET', '/stats/campaigns', 'Query: from, to, timezone, group_by + campaign_id', 'StatsRow[] or grouped rows + meta', '200'),
                self::ep('GET', '/stats/campaigns/{id}', 'Query: from, to, timezone, group_by, page, per_page', 'StatsRow or grouped rows + meta', '200'),
            ],
            'request_fields' => ApiFieldReference::statsCampaignParams(),
            'response_sections' => [
                ['title' => 'Stats row (summary)', 'note' => 'Omit group_by — one row per campaign with lp_clicks.', 'fields' => ApiFieldReference::statsResponseFields()],
                ['title' => 'Grouped row (data[])', 'note' => 'With group_by — one row per dimension value.', 'fields' => ApiFieldReference::statsGroupedRowFields()],
                ['title' => 'Grouped meta', 'note' => 'Pagination plus totals object matching campaign summary.', 'fields' => ApiFieldReference::statsGroupedMetaFields()],
                ['title' => 'List vs single', 'note' => 'Summary: GET /stats/campaigns → data is array; GET /stats/campaigns/{id} → data is one object. Grouped: both return data as array + meta.', 'fields' => []],
            ],
            'samples' => self::pickSamples(ApiResponseExamples::statsResponseExamples()),
        ];
    }

    /** @return array<string, mixed> */
    private static function clicks(): array
    {
        return [
            'id' => 'ref-clicks',
            'label' => 'Clicks',
            'summary' => 'Paginated visitor log (requires visitor log permission).',
            'endpoints' => [
                self::ep('GET', '/clicks', 'Query: from, to, timezone, campaign_id, page, per_page', 'Click[] + meta pagination', '200'),
            ],
            'request_fields' => ApiFieldReference::clicksQueryParams(),
            'response_sections' => [
                ['title' => 'Click row (data[])', 'note' => 'One visitor click.', 'fields' => ApiFieldReference::clicksResponseFields()],
                ['title' => 'Pagination (meta)', 'note' => 'Standard list envelope.', 'fields' => ApiFieldReference::paginationMetaFields()],
            ],
            'samples' => self::pickSamples(ApiResponseExamples::clicksResponseExamples()),
        ];
    }

    /** @return array<string, mixed> */
    private static function conversions(): array
    {
        return [
            'id' => 'ref-conversions',
            'label' => 'Conversions',
            'summary' => 'Paginated conversion rows.',
            'endpoints' => [
                self::ep('GET', '/conversions', 'Query: from, to, timezone, campaign_id, page, per_page', 'Conversion[] + meta pagination', '200'),
            ],
            'request_fields' => ApiFieldReference::conversionsParams(),
            'response_sections' => [
                ['title' => 'Conversion row (data[])', 'note' => 'Use revenue for reporting.', 'fields' => ApiFieldReference::conversionsResponseFields()],
                ['title' => 'Pagination (meta)', 'note' => 'Standard list envelope.', 'fields' => ApiFieldReference::paginationMetaFields()],
            ],
            'samples' => self::pickSamples(ApiResponseExamples::conversionsResponseExamples()),
        ];
    }

    /** @return EndpointRow */
    private static function ep(string $method, string $path, string $body, string $response, string $http): array
    {
        return [
            'method' => $method,
            'path' => $path,
            'body' => $body,
            'response' => $response,
            'http' => $http,
        ];
    }

    /**
     * @param list<array<string, mixed>> $tabs
     * @return list<array{id: string, label: string, json: string}>
     */
    private static function pickSamples(array $tabs): array
    {
        $samples = [];
        foreach ($tabs as $tab) {
            if (!empty($tab['reference']) || empty($tab['example'])) {
                continue;
            }
            $samples[] = [
                'id' => (string)$tab['id'],
                'label' => (string)$tab['label'],
                'json' => (string)$tab['example'],
            ];
        }

        return $samples;
    }

    /** @return list<array{id: string, label: string, json: string}> */
    private static function deleteSample(string $resourceLabel, int $id): array
    {
        return [[
            'id' => 'delete-' . preg_replace('/\s+/', '-', strtolower($resourceLabel)),
            'label' => 'DELETE response',
            'json' => json_encode([
                'data' => [
                    'deleted' => true,
                    'id' => $id,
                ],
                'meta' => null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]];
    }

    /** @return list<array{id: string, label: string, json: string}> */
    private static function campaignExtraSamples(): array
    {
        return [
            [
                'id' => 'campaign-list',
                'label' => 'GET list',
                'json' => json_encode([
                    'data' => [
                        [
                            'id' => 1,
                            'campaign_key' => 'abc12',
                            'name' => 'Example Campaign',
                            'status' => 'active',
                            'flow_type' => 'DTO',
                        ],
                    ],
                    'meta' => null,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            ],
            [
                'id' => 'campaign-status',
                'label' => 'PATCH status',
                'json' => json_encode([
                    'data' => [
                        'id' => 1,
                        'status' => 'paused',
                    ],
                    'meta' => null,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            ],
        ];
    }

    /** @return list<array{id: string, label: string, json: string}> */
    private static function networkSamples(): array
    {
        return [[
            'id' => 'network-sample',
            'label' => 'Network resource',
            'json' => json_encode([
                'data' => [
                    'id' => 1,
                    'name' => 'Example Network',
                    'postback_template' => '',
                    'notes' => '',
                    'created_at' => '2026-06-18 10:00:00',
                    'updated_at' => null,
                ],
                'meta' => null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]];
    }

    /** @return list<array{id: string, label: string, json: string}> */
    private static function landingPageSamples(): array
    {
        return [[
            'id' => 'lp-sample',
            'label' => 'Landing page resource',
            'json' => json_encode([
                'data' => [
                    'id' => 1,
                    'name' => 'Test LP1',
                    'url' => 'https://tracker.example/lp/',
                    'notes' => '',
                    'created_at' => '2026-06-18 10:00:00',
                    'updated_at' => null,
                ],
                'meta' => null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]];
    }
}
