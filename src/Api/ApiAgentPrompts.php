<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

/**
 * Copy-paste AI agent prompts for Kuma API documentation.
 */
class ApiAgentPrompts
{
    /**
     * @return list<array{id: string, label: string, summary: string, prompt: string}>
     */
    public static function all(string $baseUrl): array
    {
        return [
            self::generalSetup($baseUrl),
            self::bootstrapCatalog($baseUrl),
            self::createOffer($baseUrl),
            self::createCampaignDto($baseUrl),
            self::createCampaignLp($baseUrl),
            self::createCampaignSplit($baseUrl),
            self::statsReporting($baseUrl),
            self::updateManage($baseUrl),
        ];
    }

    /** @return array{id: string, label: string, summary: string, prompt: string} */
    private static function generalSetup(string $baseUrl): array
    {
        return [
            'id' => 'setup',
            'label' => 'General setup',
            'summary' => 'Give this to any agent before task-specific work — auth, base URL, and rules of engagement.',
            'prompt' => <<<PROMPT
You manage my Kuma affiliate tracker via its REST API v1.

Base URL: {$baseUrl}
Authentication: Authorization: Bearer kuma_<API_KEY> on every request except GET /health and GET /openapi.json.
OpenAPI schema: {$baseUrl}/openapi.json

Rules:
1. Always call GET /catalog first when you need IDs (traffic sources, offers, landing pages, networks, campaign groups, tracking domains).
2. Use JSON bodies on POST and PATCH with Content-Type: application/json.
3. Responses use { "data": ... } on success or { "error": { "code", "message" } } on failure.
4. After creating a campaign, always return the tracking_url from the response (or GET /campaigns/{id}/tracking-link).
5. Enabled rotation weights must sum to exactly 100 in each rotation group.
6. Do not guess IDs — read them from /catalog or from a prior create response.
7. tracking_domain_id is optional; omit it to use the default install domain, or pick an id from catalog.tracking_domains (verified domains only).
8. Rate limit: 120 requests/minute per key.

Ask me for the API key if you do not have it. Confirm each created resource id and tracking URL before finishing.
PROMPT,
        ];
    }

    /** @return array{id: string, label: string, summary: string, prompt: string} */
    private static function bootstrapCatalog(string $baseUrl): array
    {
        return [
            'id' => 'catalog',
            'label' => 'Bootstrap catalog',
            'summary' => 'Load all reference IDs in one call before building anything.',
            'prompt' => <<<PROMPT
Connect to my Kuma tracker API and bootstrap context.

Base URL: {$baseUrl}
Use Bearer auth with my API key.

1. GET /health — confirm API is up.
2. GET /catalog — fetch and summarize:
   - traffic_sources (id, name, cost_tracking_method, tokens)
   - offers (id, name, network_id, is_available_for_rotation)
   - landing_pages (id, name, url)
   - networks (id, name)
   - campaign_groups (id, name)
   - tracking_domains (id, domain, url) — only verified domains appear here

Present the catalog as a short table I can reference. Note that Google Ads / YouTube support optional google_ads_integration_id (CSV/API conversion delivery and API cost sync when configured).

Do not create anything yet — just return the catalog summary and ask what I want to build next.
PROMPT,
        ];
    }

    /** @return array{id: string, label: string, summary: string, prompt: string} */
    private static function createOffer(string $baseUrl): array
    {
        return [
            'id' => 'offer',
            'label' => 'Create offer',
            'summary' => 'Create an affiliate offer with network, payout, URL tokens, and optional caps.',
            'prompt' => <<<PROMPT
Create a new offer in my Kuma tracker via API.

Base URL: {$baseUrl}
Bearer auth required.

Steps:
1. GET /catalog — pick network_id from networks[] (or tell me if I need to POST /networks first).
2. POST /offers with JSON body:
   - name (required)
   - url (required) — include Kuma tokens like {click_id}, {campaign_name}, {traffic_source}
   - payout_type: CPA | CPL | CPS | RevShare
   - payout_value (number)
   - network_id (from catalog)
   - cap_enabled, cap_limit, cap_period, cap_timezone (optional)
   - is_24_7, schedule_days, schedule_start_time, schedule_end_time, schedule_timezone (optional scheduling)

Example body:
{
  "name": "Roof Repair CPA",
  "url": "https://affiliate.example.com/click?cid={click_id}&camp={campaign_name}",
  "payout_type": "CPA",
  "payout_value": 45,
  "network_id": 1,
  "cap_enabled": false
}

Return the new offer id and full response. If validation fails, show the error fields and fix them.
PROMPT,
        ];
    }

    /** @return array{id: string, label: string, summary: string, prompt: string} */
    private static function createCampaignDto(string $baseUrl): array
    {
        return [
            'id' => 'campaign-dto',
            'label' => 'Campaign (DTO)',
            'summary' => 'Direct-to-offer campaign with weighted offer rotation and optional custom tracking domain.',
            'prompt' => <<<PROMPT
Create a direct-to-offer (DTO) campaign in my Kuma tracker.

Base URL: {$baseUrl}
Bearer auth required.

Steps:
1. GET /catalog — collect:
   - traffic_source_id from traffic_sources[] (Facebook, Google Ads, YouTube, or custom source with cost param)
   - offer id(s) from offers[] for rotation
   - tracking_domain_id from tracking_domains[] (OPTIONAL — omit or null for default BASE_URL domain)
   - campaign_group_id from campaign_groups[] (optional)

2. POST /campaigns with JSON like:
{
  "name": "FB Keto DTO — US",
  "flow_type": "DTO",
  "traffic_source_id": 4,
  "tracking_domain_id": 2,
  "campaign_group_id": 1,
  "status": "active",
  "rotation": [
    { "type": "offer", "id": 5, "weight": 70, "enabled": true },
    { "type": "offer", "id": 8, "weight": 30, "enabled": true }
  ],
  "slugs": [
    { "slug": "keto-main", "slug_label": "Main ad set link" }
  ],
  "fallback_offer_id": 5,
  "default_cpc": null,
  "min_postback_payout": null
}

Rotation rules for DTO:
- rotation is a flat array of offer objects
- each item: type "offer", id (offer id), weight (integer), enabled (boolean)
- enabled weights MUST sum to 100

If traffic source is Google Ads or YouTube, you may include an optional google_ads_integration_id from my setup (ask me if not in catalog). Conversion delivery can be CSV and/or API; cost sync uses the Google Ads cost cron when credentials are set.

Return: campaign id, campaign_key, tracking_url from the create response, and the full tracking link with tokens (GET /campaigns/{id}/tracking-link if needed).
PROMPT,
        ];
    }

    /** @return array{id: string, label: string, summary: string, prompt: string} */
    private static function createCampaignLp(string $baseUrl): array
    {
        return [
            'id' => 'campaign-lp',
            'label' => 'Campaign (LP)',
            'summary' => 'Landing page → offer flow with separate LP and offer rotations.',
            'prompt' => <<<PROMPT
Create a landing-page (LP) campaign in my Kuma tracker.

Base URL: {$baseUrl}
Bearer auth required.

Steps:
1. GET /catalog — collect:
   - traffic_source_id
   - landing_page id(s) from landing_pages[]
   - offer id(s) from offers[]
   - tracking_domain_id from tracking_domains[] (optional — use when I want links on a custom domain like https://track.mydomain.com)
   - campaign_group_id (optional)

2. POST /campaigns with JSON like:
{
  "name": "FB Roof LP Test",
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
  },
  "slugs": [
    { "slug": "roof-v1", "slug_label": "Creative A" }
  ],
  "custom_tokens": [],
  "redirect_rules": [],
  "fallback_offer_id": 5
}

Rotation rules for LP:
- rotation is an object with landing_pages[] and offers[] arrays
- enabled landing_page weights must sum to 100
- enabled offer weights must sum to 100
- use type "landing_page" or "offer" on each item

Optional fields I may specify:
- referrer_mode, facebook_capi_integration_id, custom_postback_ids
- tracking_domain_id: omit if I want the default install URL

Return campaign id, campaign_key, tracking_url, and explain which domain the link uses (custom vs default).
PROMPT,
        ];
    }

    /** @return array{id: string, label: string, summary: string, prompt: string} */
    private static function createCampaignSplit(string $baseUrl): array
    {
        return [
            'id' => 'campaign-split',
            'label' => 'Campaign (Split)',
            'summary' => 'Split traffic between LP path and direct-to-offer path.',
            'prompt' => <<<PROMPT
Create a Split-flow campaign in my Kuma tracker (LP path vs direct-to-offer path).

Base URL: {$baseUrl}
Bearer auth required.

Steps:
1. GET /catalog for traffic_source_id, landing_pages[], offers[], tracking_domains[] (optional tracking_domain_id).

2. POST /campaigns with flow_type "Split" and rotation shape:
{
  "name": "Split Test — 50/50 LP vs DTO",
  "flow_type": "Split",
  "traffic_source_id": 4,
  "tracking_domain_id": null,
  "status": "active",
  "rotation": {
    "split_traffic": { "lp_percent": 50, "direct_percent": 50 },
    "lp_path": {
      "landing_pages": [
        { "type": "landing_page", "id": 1, "weight": 100, "enabled": true }
      ],
      "offers": [
        { "type": "offer", "id": 5, "weight": 100, "enabled": true }
      ]
    },
    "direct_path": {
      "offers": [
        { "type": "offer", "id": 5, "weight": 100, "enabled": true }
      ]
    }
  }
}

Rules:
- lp_percent + direct_percent = 100
- enabled weights inside lp_path.landing_pages sum to 100
- enabled weights inside lp_path.offers sum to 100
- enabled weights inside direct_path.offers sum to 100

Return tracking_url and summarize the split configuration.
PROMPT,
        ];
    }

    /** @return array{id: string, label: string, summary: string, prompt: string} */
    private static function statsReporting(string $baseUrl): array
    {
        return [
            'id' => 'stats',
            'label' => 'Stats & reporting',
            'summary' => 'Pull campaign performance, clicks, and conversions for a date range.',
            'prompt' => <<<PROMPT
Pull performance data from my Kuma tracker API.

Base URL: {$baseUrl}
Bearer auth required.

1. GET /campaigns — list campaigns (or use campaign id I provide).

2. Campaign summary stats:
   GET /stats/campaigns?from=YYYY-MM-DD&to=YYYY-MM-DD&timezone=America/New_York

3. Single campaign summary:
   GET /stats/campaigns/{id}?from=...&to=...&timezone=...

4. Grouped breakdown (one dimension per call):
   GET /stats/campaigns/{id}?from=...&to=...&group_by=country
   group_by values: date, country, browser, os, isp, landing, offer, or any token from GET /campaigns/{id}/tracking-link (zoneid, subid, etc.)
   Response: data[] rows with group, clicks, lp_clicks, conversions, cost, revenue, profit, roi, conversion_rate; meta has page, per_page, total, totals.

5. Raw clicks (paginated):
   GET /clicks?from=...&to=...&campaign_id=...&page=1&per_page=50

6. Conversions (paginated):
   GET /conversions?from=...&to=...&campaign_id=...&page=1&per_page=50

Use my date range and timezone. Present summary metrics (clicks, lp_clicks, conversions, cost, revenue, profit, ROI) in a table. Offer to drill into grouped breakdowns or raw clicks/conversions if I ask.

Do not modify campaigns — read-only reporting unless I explicitly ask to pause or update something.
PROMPT,
        ];
    }

    /** @return array{id: string, label: string, summary: string, prompt: string} */
    private static function updateManage(string $baseUrl): array
    {
        return [
            'id' => 'manage',
            'label' => 'Update & manage',
            'summary' => 'Pause campaigns, change rotation, swap tracking domain, or refresh tracking links.',
            'prompt' => <<<PROMPT
Manage existing Kuma campaigns and offers via API (no UI).

Base URL: {$baseUrl}
Bearer auth required.

Common tasks:

Pause / activate campaign:
  PATCH /campaigns/{id}/status
  Body: { "status": "active" | "paused" | "archived" }

Update campaign fields (partial merge):
  PATCH /campaigns/{id}
  Body can include: name, traffic_source_id, tracking_domain_id, rotation, slugs, custom_tokens, redirect_rules, fallback_offer_id, status
  — GET /campaigns/{id} first, merge my changes, then PATCH

Change tracking domain:
  PATCH /campaigns/{id} with { "tracking_domain_id": 2 } or null to revert to default
  — verify id exists in GET /catalog → tracking_domains[]

Refresh tracking URL after changes:
  GET /campaigns/{id}/tracking-link
  Optional query: ?slug=my-slug

Update offer payout or URL:
  PATCH /offers/{id} with changed fields

List before edit: GET /campaigns/{id} or GET /offers/{id}.

Always confirm the before/after state and return the updated tracking_url if the campaign changed.
PROMPT,
        ];
    }
}
