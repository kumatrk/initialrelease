<?php

declare(strict_types=1);

namespace SimpleKuma\Api;

/**
 * Field / parameter reference tables for API documentation.
 *
 * @phpstan-type RefRow array{field: string, type: string, required: bool, description: string}
 */
class ApiFieldReference
{
    /** @return list<RefRow> */
    public static function offerPostFields(): array
    {
        return [
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Offer display name.'],
            ['field' => 'url', 'type' => 'string', 'required' => true, 'description' => 'Destination URL. Must start with http:// or https://. Supports tokens like {click_id}, {campaign_name}.'],
            ['field' => 'payout_type', 'type' => 'string', 'required' => true, 'description' => 'One of: CPA, CPL, CPS.'],
            ['field' => 'payout_value', 'type' => 'number', 'required' => true, 'description' => 'Payout amount (0 or greater).'],
            ['field' => 'network_id', 'type' => 'integer', 'required' => false, 'description' => 'Affiliate network id from GET /catalog → networks[].'],
            ['field' => 'notes', 'type' => 'string', 'required' => false, 'description' => 'Internal notes.'],
            ['field' => 'is_24_7', 'type' => 'boolean', 'required' => false, 'description' => 'Default true. Set false to enable day parting (schedule_* fields).'],
            ['field' => 'schedule_days', 'type' => 'string[]', 'required' => false, 'description' => 'Lowercase day names when is_24_7 is false: monday … sunday.'],
            ['field' => 'schedule_start_time', 'type' => 'string', 'required' => false, 'description' => 'Start time HH:MM:SS in schedule_timezone.'],
            ['field' => 'schedule_end_time', 'type' => 'string', 'required' => false, 'description' => 'End time HH:MM:SS in schedule_timezone.'],
            ['field' => 'schedule_timezone', 'type' => 'string', 'required' => false, 'description' => 'IANA timezone for schedule (default UTC).'],
            ['field' => 'cap_enabled', 'type' => 'boolean', 'required' => false, 'description' => 'Enable click cap (default false).'],
            ['field' => 'cap_limit', 'type' => 'integer', 'required' => false, 'description' => 'Max clicks in the cap period when cap_enabled is true.'],
            ['field' => 'cap_period', 'type' => 'string', 'required' => false, 'description' => 'When cap enabled: day, week, month, or lifetime.'],
            ['field' => 'cap_timezone', 'type' => 'string', 'required' => false, 'description' => 'IANA timezone for cap period boundaries (default UTC).'],
        ];
    }

    /** @return list<RefRow> */
    public static function campaignPostFields(): array
    {
        return [
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Campaign name.'],
            ['field' => 'flow_type', 'type' => 'string', 'required' => true, 'description' => 'DTO, LP, or Split.'],
            ['field' => 'traffic_source_id', 'type' => 'integer', 'required' => true, 'description' => 'From GET /catalog → traffic_sources[]. Required on create.'],
            ['field' => 'rotation', 'type' => 'object | array', 'required' => true, 'description' => 'DTO: array of offers. LP: { landing_pages[], offers[] }. Split: split_traffic + lp_path + direct_path. Enabled weights sum to 100 per group.'],
            ['field' => 'status', 'type' => 'string', 'required' => false, 'description' => 'active, paused, or archived (default active).'],
            ['field' => 'tracking_domain_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Custom tracking domain id from catalog.tracking_domains[]. Omit for default install URL.'],
            ['field' => 'campaign_group_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Group id from catalog.campaign_groups[].'],
            ['field' => 'fallback_offer_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Offer used when rotation is unavailable (cap/schedule).'],
            ['field' => 'default_cpc', 'type' => 'number | null', 'required' => false, 'description' => 'Default cost per click for manual cost sources.'],
            ['field' => 'min_postback_payout', 'type' => 'number | null', 'required' => false, 'description' => 'Minimum payout to count as conversion (0 or greater).'],
            ['field' => 'referrer_mode', 'type' => 'string', 'required' => false, 'description' => 'Referrer privacy mode: blank, noreferrer, or double (bear-hop).'],
            ['field' => 'slugs[]', 'type' => 'object', 'required' => false, 'description' => 'slug (string, URL path), slug_label (string, display name).'],
            ['field' => 'custom_tokens[]', 'type' => 'object', 'required' => false, 'description' => 'name, parameter, placeholder, pass_to_lp (bool), pass_to_offer (bool).'],
            ['field' => 'redirect_rules[]', 'type' => 'object', 'required' => false, 'description' => 'token_name, token_source (custom|traffic_source:…), operator, value, case_sensitive, redirect_url, execute_on (campaign_click, offer_click, …).'],
            ['field' => 'custom_postback_ids', 'type' => 'integer[]', 'required' => false, 'description' => 'Ids of custom postbacks to attach.'],
            ['field' => 'traffic_source_postbacks', 'type' => 'object', 'required' => false, 'description' => 'Keyed by traffic_source_id → { facebook_capi_integration_id, google_ads_integration_id }. Used for auto-detect / per-source postback config.'],
            ['field' => 'google_ads_integration_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Optional for Google Ads or YouTube traffic sources. Enables CSV/Data Manager and/or API conversion upload; cost sync when OAuth credentials are configured.'],
            ['field' => 'facebook_capi_integration_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Facebook CAPI integration id.'],
            ['field' => 'facebook_marketing_integration_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Facebook marketing API integration.'],
            ['field' => 'facebook_marketing_ad_account_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Linked Facebook ad account.'],
            ['field' => 'facebook_marketing_campaign_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Linked Facebook campaign for cost sync.'],
        ];
    }

    /** @return list<RefRow> */
    public static function trackingLinkParams(): array
    {
        return [
            ['field' => '{id}', 'type' => 'path', 'required' => true, 'description' => 'Campaign id (integer).'],
            ['field' => 'slug', 'type' => 'query string', 'required' => false, 'description' => 'Use a custom slug instead of campaign_key in the URL path (/km/{slug}).'],
        ];
    }

    /** @return list<RefRow> */
    public static function statsCampaignParams(): array
    {
        return [
            ['field' => 'from', 'type' => 'query date', 'required' => false, 'description' => 'Start date YYYY-MM-DD (default: today in timezone).'],
            ['field' => 'to', 'type' => 'query date', 'required' => false, 'description' => 'End date YYYY-MM-DD (default: today in timezone).'],
            ['field' => 'timezone', 'type' => 'query string', 'required' => false, 'description' => 'IANA timezone for date boundaries (default: your user timezone).'],
            ['field' => 'group_by', 'type' => 'query string', 'required' => false, 'description' => 'Break down stats by dimension: date, country, browser, os, isp, landing, offer, or any token from GET /campaigns/{id}/tracking-link (e.g. zoneid, subid). Changes response to a paginated array.'],
            ['field' => 'campaign_id', 'type' => 'query integer', 'required' => false, 'description' => 'Required on GET /stats/campaigns when group_by is set.'],
            ['field' => 'page', 'type' => 'query integer', 'required' => false, 'description' => 'Grouped mode only — page number (default 1).'],
            ['field' => 'per_page', 'type' => 'query integer', 'required' => false, 'description' => 'Grouped mode only — rows per page (default 50, max 1000).'],
            ['field' => 'sort', 'type' => 'query string', 'required' => false, 'description' => 'Grouped mode only — clicks, lp_clicks, conversions, cost, revenue, profit, roi, conversion_rate (default clicks).'],
            ['field' => 'order', 'type' => 'query string', 'required' => false, 'description' => 'Grouped mode only — asc or desc (default desc).'],
            ['field' => '{id}', 'type' => 'path', 'required' => false, 'description' => 'Single-campaign endpoint only — campaign id.'],
        ];
    }

    /** @return list<RefRow> */
    public static function conversionsParams(): array
    {
        return [
            ['field' => 'from', 'type' => 'query date', 'required' => false, 'description' => 'Start date YYYY-MM-DD.'],
            ['field' => 'to', 'type' => 'query date', 'required' => false, 'description' => 'End date YYYY-MM-DD.'],
            ['field' => 'timezone', 'type' => 'query string', 'required' => false, 'description' => 'IANA timezone for date boundaries.'],
            ['field' => 'campaign_id', 'type' => 'query integer', 'required' => false, 'description' => 'Filter to one campaign. Omit for all campaigns.'],
            ['field' => 'page', 'type' => 'query integer', 'required' => false, 'description' => 'Page number (default 1).'],
            ['field' => 'per_page', 'type' => 'query integer', 'required' => false, 'description' => 'Rows per page (default 50).'],
        ];
    }

    /** @return list<RefRow> */
    public static function rotationItemFields(): array
    {
        return [
            ['field' => 'type', 'type' => 'string', 'required' => true, 'description' => 'offer or landing_page.'],
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Offer or landing page id from catalog.'],
            ['field' => 'weight', 'type' => 'integer', 'required' => true, 'description' => 'Rotation weight; enabled items in each group must sum to 100.'],
            ['field' => 'enabled', 'type' => 'boolean', 'required' => true, 'description' => 'false excludes item from rotation and weight sum.'],
        ];
    }

    /** @return list<RefRow> */
    public static function envelopeFields(): array
    {
        return [
            ['field' => 'data', 'type' => 'object | array', 'required' => true, 'description' => 'Success payload — shape depends on endpoint.'],
            ['field' => 'meta', 'type' => 'object | null', 'required' => false, 'description' => 'null for single resources; { page, per_page, total } for paginated lists.'],
            ['field' => 'error', 'type' => 'object', 'required' => false, 'description' => 'Present on failure: code (string), message (string).'],
            ['field' => 'fields', 'type' => 'object', 'required' => false, 'description' => 'Validation errors only — field name → error message.'],
        ];
    }

    /** @return list<RefRow> */
    public static function catalogResponseFields(): array
    {
        return [
            ['field' => 'traffic_sources[]', 'type' => 'object', 'required' => true, 'description' => 'id, name, cost_param_key, cost_tracking_method, tokens[].'],
            ['field' => 'offers[]', 'type' => 'object', 'required' => true, 'description' => 'Full offer objects plus is_available_for_rotation.'],
            ['field' => 'landing_pages[]', 'type' => 'object', 'required' => true, 'description' => 'id, name, url, notes, timestamps.'],
            ['field' => 'networks[]', 'type' => 'object', 'required' => true, 'description' => 'id, name, postback_template, notes.'],
            ['field' => 'campaign_groups[]', 'type' => 'object', 'required' => true, 'description' => 'id, name.'],
            ['field' => 'tracking_domains[]', 'type' => 'object', 'required' => true, 'description' => 'Verified only: id, domain, url.'],
        ];
    }

    /** @return list<RefRow> */
    public static function offerResponseFields(): array
    {
        return [
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Offer id — use in campaign rotation.'],
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Offer name.'],
            ['field' => 'url', 'type' => 'string', 'required' => true, 'description' => 'Destination URL with tokens.'],
            ['field' => 'payout_type', 'type' => 'string', 'required' => true, 'description' => 'CPA, CPL, or CPS.'],
            ['field' => 'payout_value', 'type' => 'number', 'required' => true, 'description' => 'Payout amount.'],
            ['field' => 'network_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Linked network id.'],
            ['field' => 'network_name', 'type' => 'string | null', 'required' => false, 'description' => 'Resolved network name.'],
            ['field' => 'is_24_7', 'type' => 'boolean', 'required' => false, 'description' => 'Schedule mode flag.'],
            ['field' => 'schedule_days', 'type' => 'string[]', 'required' => false, 'description' => 'Active days when not 24/7.'],
            ['field' => 'cap_enabled', 'type' => 'boolean', 'required' => false, 'description' => 'Whether click cap is on.'],
            ['field' => 'is_available_for_rotation', 'type' => 'boolean', 'required' => true, 'description' => 'false if outside schedule or cap reached — important for agents picking offers.'],
            ['field' => 'created_at', 'type' => 'string', 'required' => false, 'description' => 'MySQL datetime.'],
            ['field' => 'updated_at', 'type' => 'string | null', 'required' => false, 'description' => 'MySQL datetime or null.'],
        ];
    }

    /** @return list<RefRow> */
    public static function campaignResponseFields(): array
    {
        return [
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Campaign id.'],
            ['field' => 'campaign_key', 'type' => 'string', 'required' => true, 'description' => 'Short key for default tracking URL /km/{key}.'],
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Campaign name.'],
            ['field' => 'status', 'type' => 'string', 'required' => true, 'description' => 'active, paused, or archived.'],
            ['field' => 'flow_type', 'type' => 'string', 'required' => true, 'description' => 'DTO, LP, or Split.'],
            ['field' => 'traffic_source_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Linked traffic source.'],
            ['field' => 'traffic_source_name', 'type' => 'string | null', 'required' => false, 'description' => 'Resolved name.'],
            ['field' => 'tracking_domain_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Custom domain id if set.'],
            ['field' => 'rotation', 'type' => 'object | array', 'required' => true, 'description' => 'Current rotation config (same shape as request).'],
            ['field' => 'slugs', 'type' => 'object[]', 'required' => false, 'description' => 'id, slug, slug_label.'],
            ['field' => 'custom_tokens', 'type' => 'object[]', 'required' => false, 'description' => 'Campaign URL tokens.'],
            ['field' => 'redirect_rules', 'type' => 'object[]', 'required' => false, 'description' => 'Active redirect rules.'],
            ['field' => 'tracking_url', 'type' => 'string', 'required' => false, 'description' => 'Present on POST /campaigns create — ready-to-use URL with tokens.'],
            ['field' => 'created_at', 'type' => 'string', 'required' => false, 'description' => 'MySQL datetime.'],
            ['field' => 'updated_at', 'type' => 'string | null', 'required' => false, 'description' => 'MySQL datetime or null.'],
        ];
    }

    /** @return list<RefRow> */
    public static function trackingLinkResponseFields(): array
    {
        return [
            ['field' => 'url', 'type' => 'string', 'required' => true, 'description' => 'Full tracking URL with query tokens — paste into ads.'],
            ['field' => 'base_url', 'type' => 'string', 'required' => true, 'description' => 'URL without query string.'],
            ['field' => 'identifier', 'type' => 'string', 'required' => true, 'description' => 'campaign_key or slug used in path.'],
            ['field' => 'tokens', 'type' => 'string[]', 'required' => true, 'description' => 'Query parameter names appended (for documentation/debug).'],
        ];
    }

    /** @return list<RefRow> */
    public static function statsResponseFields(): array
    {
        return [
            ['field' => 'campaign_id', 'type' => 'integer', 'required' => true, 'description' => 'Campaign id.'],
            ['field' => 'campaign_name', 'type' => 'string', 'required' => true, 'description' => 'Campaign name.'],
            ['field' => 'status', 'type' => 'string', 'required' => true, 'description' => 'Campaign status at query time.'],
            ['field' => 'clicks', 'type' => 'integer', 'required' => true, 'description' => 'Visit count in range (Facebook bot/ad-id filtered).'],
            ['field' => 'lp_clicks', 'type' => 'integer', 'required' => true, 'description' => 'Landing-page click count (lp_click=true).'],
            ['field' => 'conversions', 'type' => 'integer', 'required' => true, 'description' => 'Conversion count in range.'],
            ['field' => 'cost', 'type' => 'number', 'required' => true, 'description' => 'Ad spend attributed to clicks.'],
            ['field' => 'revenue', 'type' => 'number', 'required' => true, 'description' => 'Sum of conversion payout/value.'],
            ['field' => 'profit', 'type' => 'number', 'required' => true, 'description' => 'revenue − cost.'],
            ['field' => 'roi', 'type' => 'number', 'required' => true, 'description' => 'Return on investment percent ((profit/cost)×100, 0 if cost=0).'],
            ['field' => 'conversion_rate', 'type' => 'number', 'required' => true, 'description' => 'Conversions / clicks × 100.'],
            ['field' => 'date_from', 'type' => 'string', 'required' => true, 'description' => 'Echo of request from date.'],
            ['field' => 'date_to', 'type' => 'string', 'required' => true, 'description' => 'Echo of request to date.'],
            ['field' => 'timezone', 'type' => 'string', 'required' => true, 'description' => 'Echo of request timezone.'],
        ];
    }

    /** @return list<RefRow> */
    public static function statsGroupedRowFields(): array
    {
        return [
            ['field' => 'group', 'type' => 'string', 'required' => true, 'description' => 'Dimension value (e.g. US, 2026-06-23, zone id). Empty values are returned as N/A.'],
            ['field' => 'group_label', 'type' => 'string', 'required' => false, 'description' => 'Friendly label when available (offer name, landing page name).'],
            ['field' => 'clicks', 'type' => 'integer', 'required' => true, 'description' => 'Visits for this group.'],
            ['field' => 'lp_clicks', 'type' => 'integer', 'required' => true, 'description' => 'Landing-page clicks for this group.'],
            ['field' => 'conversions', 'type' => 'integer', 'required' => true, 'description' => 'Conversions for this group.'],
            ['field' => 'cost', 'type' => 'number', 'required' => true, 'description' => 'Click-level cost sum for this group.'],
            ['field' => 'revenue', 'type' => 'number', 'required' => true, 'description' => 'Conversion revenue for this group.'],
            ['field' => 'profit', 'type' => 'number', 'required' => true, 'description' => 'revenue − cost.'],
            ['field' => 'roi', 'type' => 'number', 'required' => true, 'description' => 'ROI percent for this group.'],
            ['field' => 'conversion_rate', 'type' => 'number', 'required' => true, 'description' => 'Conversions / clicks × 100 for this group.'],
        ];
    }

    /** @return list<RefRow> */
    public static function statsGroupedMetaFields(): array
    {
        return [
            ['field' => 'page', 'type' => 'integer', 'required' => true, 'description' => 'Current page.'],
            ['field' => 'per_page', 'type' => 'integer', 'required' => true, 'description' => 'Rows per page.'],
            ['field' => 'total', 'type' => 'integer', 'required' => true, 'description' => 'Total distinct group values.'],
            ['field' => 'group_by', 'type' => 'string', 'required' => true, 'description' => 'Echo of request group_by.'],
            ['field' => 'campaign_id', 'type' => 'integer', 'required' => true, 'description' => 'Campaign id queried.'],
            ['field' => 'date_from', 'type' => 'string', 'required' => true, 'description' => 'Echo of request from date.'],
            ['field' => 'date_to', 'type' => 'string', 'required' => true, 'description' => 'Echo of request to date.'],
            ['field' => 'timezone', 'type' => 'string', 'required' => true, 'description' => 'Echo of request timezone.'],
            ['field' => 'totals', 'type' => 'object', 'required' => true, 'description' => 'Campaign summary metrics for the full date range (same shape as grouped rows, without group).'],
        ];
    }

    /** @return list<RefRow> */
    public static function conversionsResponseFields(): array
    {
        return [
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Conversion row id.'],
            ['field' => 'click_id', 'type' => 'string', 'required' => true, 'description' => 'Linked click id.'],
            ['field' => 'campaign_id', 'type' => 'integer', 'required' => true, 'description' => 'Campaign id.'],
            ['field' => 'campaign_name', 'type' => 'string', 'required' => true, 'description' => 'Campaign name.'],
            ['field' => 'offer_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Offer on the click.'],
            ['field' => 'ts', 'type' => 'string', 'required' => true, 'description' => 'Conversion timestamp (UTC MySQL datetime).'],
            ['field' => 'payout', 'type' => 'number | null', 'required' => false, 'description' => 'Network-reported payout if set.'],
            ['field' => 'value', 'type' => 'number | null', 'required' => false, 'description' => 'Alternate value field if used.'],
            ['field' => 'revenue', 'type' => 'number', 'required' => true, 'description' => 'payout ?? value ?? 0 — use this for reporting.'],
        ];
    }

    /** @return list<RefRow> */
    public static function clicksResponseFields(): array
    {
        return [
            ['field' => 'click_id', 'type' => 'string', 'required' => true, 'description' => 'Unique click identifier.'],
            ['field' => 'campaign_id', 'type' => 'integer', 'required' => true, 'description' => 'Campaign id.'],
            ['field' => 'campaign_name', 'type' => 'string', 'required' => true, 'description' => 'Campaign name.'],
            ['field' => 'offer_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Offer routed to.'],
            ['field' => 'landing_page_id', 'type' => 'integer | null', 'required' => false, 'description' => 'LP id if LP flow.'],
            ['field' => 'traffic_source_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Traffic source id.'],
            ['field' => 'ip', 'type' => 'string', 'required' => false, 'description' => 'Visitor IP.'],
            ['field' => 'country', 'type' => 'string', 'required' => false, 'description' => 'Geo country code.'],
            ['field' => 'ts', 'type' => 'string', 'required' => true, 'description' => 'Click timestamp (UTC).'],
            ['field' => 'cost', 'type' => 'number', 'required' => true, 'description' => 'Click cost if tracked.'],
            ['field' => 'lp_click', 'type' => 'boolean', 'required' => true, 'description' => 'true if this was an LP→offer click.'],
            ['field' => 'has_conversion', 'type' => 'boolean', 'required' => true, 'description' => 'Whether click converted.'],
            ['field' => 'conversion_payout', 'type' => 'number | null', 'required' => false, 'description' => 'Payout if converted.'],
        ];
    }

    /** @return list<RefRow> */
    public static function clicksQueryParams(): array
    {
        return [
            ['field' => 'from', 'type' => 'query date', 'required' => false, 'description' => 'Start date YYYY-MM-DD.'],
            ['field' => 'to', 'type' => 'query date', 'required' => false, 'description' => 'End date YYYY-MM-DD.'],
            ['field' => 'timezone', 'type' => 'query string', 'required' => false, 'description' => 'IANA timezone for date boundaries.'],
            ['field' => 'campaign_id', 'type' => 'query integer', 'required' => false, 'description' => 'Filter to one campaign.'],
            ['field' => 'page', 'type' => 'query integer', 'required' => false, 'description' => 'Page number (default 1).'],
            ['field' => 'per_page', 'type' => 'query integer', 'required' => false, 'description' => 'Rows per page (default 50, max 200).'],
        ];
    }

    /** @return list<RefRow> */
    public static function paginationMetaFields(): array
    {
        return [
            ['field' => 'meta.page', 'type' => 'integer', 'required' => true, 'description' => 'Current page (1-based).'],
            ['field' => 'meta.per_page', 'type' => 'integer', 'required' => true, 'description' => 'Rows per page.'],
            ['field' => 'meta.total', 'type' => 'integer', 'required' => true, 'description' => 'Total rows matching filters.'],
        ];
    }

    /** @return list<RefRow> */
    public static function errorFields(): array
    {
        return [
            ['field' => 'error.code', 'type' => 'string', 'required' => true, 'description' => 'Machine-readable code (e.g. unauthorized, not_found, validation_failed).'],
            ['field' => 'error.message', 'type' => 'string', 'required' => true, 'description' => 'Human-readable error text.'],
        ];
    }

    /** @return list<RefRow> */
    public static function validationFields(): array
    {
        return array_merge(self::errorFields(), [
            ['field' => 'fields', 'type' => 'object', 'required' => true, 'description' => 'Input field name → validation message.'],
        ]);
    }

    /** @return list<RefRow> */
    public static function healthResponseFields(): array
    {
        return [
            ['field' => 'status', 'type' => 'string', 'required' => true, 'description' => 'Always "ok" when the API is reachable.'],
            ['field' => 'version', 'type' => 'string', 'required' => true, 'description' => 'API version string (e.g. 1.0.0).'],
        ];
    }

    /** @return list<RefRow> */
    public static function networkPostFields(): array
    {
        return [
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Network display name.'],
            ['field' => 'postback_template', 'type' => 'string | null', 'required' => false, 'description' => 'Optional postback URL template.'],
            ['field' => 'notes', 'type' => 'string | null', 'required' => false, 'description' => 'Internal notes.'],
        ];
    }

    /** @return list<RefRow> */
    public static function networkResponseFields(): array
    {
        return [
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Network id — use as offer network_id.'],
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Network name.'],
            ['field' => 'postback_template', 'type' => 'string | null', 'required' => false, 'description' => 'Postback URL template.'],
            ['field' => 'notes', 'type' => 'string | null', 'required' => false, 'description' => 'Internal notes.'],
            ['field' => 'created_at', 'type' => 'string', 'required' => false, 'description' => 'MySQL datetime.'],
            ['field' => 'updated_at', 'type' => 'string | null', 'required' => false, 'description' => 'MySQL datetime or null.'],
        ];
    }

    /** @return list<RefRow> */
    public static function landingPagePostFields(): array
    {
        return [
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Landing page name.'],
            ['field' => 'url', 'type' => 'string', 'required' => true, 'description' => 'LP URL. Must start with http:// or https://.'],
            ['field' => 'notes', 'type' => 'string | null', 'required' => false, 'description' => 'Internal notes.'],
        ];
    }

    /** @return list<RefRow> */
    public static function landingPageResponseFields(): array
    {
        return [
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Landing page id — use in LP/Split rotation.'],
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Landing page name.'],
            ['field' => 'url', 'type' => 'string', 'required' => true, 'description' => 'LP URL.'],
            ['field' => 'notes', 'type' => 'string | null', 'required' => false, 'description' => 'Internal notes.'],
            ['field' => 'created_at', 'type' => 'string', 'required' => false, 'description' => 'MySQL datetime.'],
            ['field' => 'updated_at', 'type' => 'string | null', 'required' => false, 'description' => 'MySQL datetime or null.'],
        ];
    }

    /** @return list<RefRow> */
    public static function deleteResponseFields(): array
    {
        return [
            ['field' => 'deleted', 'type' => 'boolean', 'required' => true, 'description' => 'Always true on successful DELETE.'],
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Id of the deleted resource.'],
        ];
    }

    /** @return list<RefRow> */
    public static function campaignStatusResponseFields(): array
    {
        return [
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Campaign id.'],
            ['field' => 'status', 'type' => 'string', 'required' => true, 'description' => 'New status: active, paused, or archived.'],
        ];
    }

    /** @return list<RefRow> */
    public static function catalogTrafficSourceFields(): array
    {
        return [
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Traffic source id for POST /campaigns.'],
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Display name.'],
            ['field' => 'cost_param_key', 'type' => 'string | null', 'required' => false, 'description' => 'Query param for manual cost token.'],
            ['field' => 'cost_tracking_method', 'type' => 'string', 'required' => true, 'description' => 'manual_token, integrated_api, etc.'],
            ['field' => 'tokens', 'type' => 'object[]', 'required' => true, 'description' => 'Traffic source URL token definitions.'],
        ];
    }

    /** @return list<RefRow> */
    public static function catalogTrackingDomainFields(): array
    {
        return [
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Use as tracking_domain_id on campaigns.'],
            ['field' => 'domain', 'type' => 'string', 'required' => true, 'description' => 'Hostname.'],
            ['field' => 'url', 'type' => 'string | null', 'required' => false, 'description' => 'Full base URL if set.'],
        ];
    }

    /** @return list<RefRow> */
    public static function offerResponseFieldsFull(): array
    {
        return array_merge(self::formatOfferFieldRows(), [
            ['field' => 'is_available_for_rotation', 'type' => 'boolean', 'required' => false, 'description' => 'GET /offers and GET /catalog only — false when outside schedule or cap reached. Not on POST/PATCH responses.'],
        ]);
    }

    /** @return list<RefRow> */
    public static function campaignResponseFieldsFull(): array
    {
        return [
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Campaign id.'],
            ['field' => 'campaign_key', 'type' => 'string', 'required' => true, 'description' => 'Short key for default tracking URL /km/{key}.'],
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Campaign name.'],
            ['field' => 'status', 'type' => 'string', 'required' => true, 'description' => 'active, paused, or archived.'],
            ['field' => 'flow_type', 'type' => 'string', 'required' => true, 'description' => 'DTO, LP, or Split.'],
            ['field' => 'traffic_source_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Linked traffic source.'],
            ['field' => 'traffic_source_name', 'type' => 'string | null', 'required' => false, 'description' => 'Resolved name.'],
            ['field' => 'campaign_group_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Campaign group id.'],
            ['field' => 'campaign_group_name', 'type' => 'string | null', 'required' => false, 'description' => 'Resolved group name.'],
            ['field' => 'tracking_domain_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Custom domain id if set.'],
            ['field' => 'referrer_mode', 'type' => 'string', 'required' => false, 'description' => 'Referrer privacy mode: blank, noreferrer, or double (bear-hop).'],
            ['field' => 'default_cpc', 'type' => 'number | null', 'required' => false, 'description' => 'Default CPC for manual cost.'],
            ['field' => 'min_postback_payout', 'type' => 'number | null', 'required' => false, 'description' => 'Minimum payout to count conversion.'],
            ['field' => 'fallback_offer_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Fallback when rotation unavailable.'],
            ['field' => 'rotation', 'type' => 'object | array', 'required' => true, 'description' => 'Current rotation config (same shape as request).'],
            ['field' => 'custom_tokens', 'type' => 'object[]', 'required' => false, 'description' => 'Campaign URL tokens.'],
            ['field' => 'redirect_rules', 'type' => 'object[]', 'required' => false, 'description' => 'Active redirect rules.'],
            ['field' => 'facebook_capi_integration_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Linked Facebook CAPI integration.'],
            ['field' => 'google_ads_integration_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Linked Google Ads integration.'],
            ['field' => 'custom_postback_ids', 'type' => 'integer[]', 'required' => false, 'description' => 'Attached custom postback ids.'],
            ['field' => 'slugs[]', 'type' => 'object', 'required' => false, 'description' => 'id, slug, slug_label.'],
            ['field' => 'created_at', 'type' => 'string', 'required' => false, 'description' => 'MySQL datetime.'],
            ['field' => 'updated_at', 'type' => 'string | null', 'required' => false, 'description' => 'MySQL datetime or null.'],
        ];
    }

    /** @return list<RefRow> */
    private static function formatOfferFieldRows(): array
    {
        return [
            ['field' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Offer id — use in campaign rotation.'],
            ['field' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Offer name.'],
            ['field' => 'url', 'type' => 'string', 'required' => true, 'description' => 'Destination URL with tokens.'],
            ['field' => 'payout_type', 'type' => 'string', 'required' => true, 'description' => 'CPA, CPL, or CPS.'],
            ['field' => 'payout_value', 'type' => 'number', 'required' => true, 'description' => 'Payout amount.'],
            ['field' => 'network_id', 'type' => 'integer | null', 'required' => false, 'description' => 'Linked network id.'],
            ['field' => 'network_name', 'type' => 'string | null', 'required' => false, 'description' => 'Resolved network name (GET only).'],
            ['field' => 'notes', 'type' => 'string | null', 'required' => false, 'description' => 'Internal notes.'],
            ['field' => 'is_24_7', 'type' => 'boolean', 'required' => false, 'description' => 'Schedule mode flag.'],
            ['field' => 'schedule_days', 'type' => 'string[]', 'required' => false, 'description' => 'Active days when not 24/7.'],
            ['field' => 'schedule_start_time', 'type' => 'string | null', 'required' => false, 'description' => 'Schedule start HH:MM:SS.'],
            ['field' => 'schedule_end_time', 'type' => 'string | null', 'required' => false, 'description' => 'Schedule end HH:MM:SS.'],
            ['field' => 'schedule_timezone', 'type' => 'string', 'required' => false, 'description' => 'IANA timezone for schedule.'],
            ['field' => 'cap_enabled', 'type' => 'boolean', 'required' => false, 'description' => 'Whether click cap is on.'],
            ['field' => 'cap_limit', 'type' => 'integer | null', 'required' => false, 'description' => 'Max clicks in cap period.'],
            ['field' => 'cap_period', 'type' => 'string | null', 'required' => false, 'description' => 'day, week, month, or lifetime.'],
            ['field' => 'cap_timezone', 'type' => 'string', 'required' => false, 'description' => 'IANA timezone for cap period.'],
            ['field' => 'created_at', 'type' => 'string', 'required' => false, 'description' => 'MySQL datetime.'],
            ['field' => 'updated_at', 'type' => 'string | null', 'required' => false, 'description' => 'MySQL datetime or null.'],
        ];
    }
}
