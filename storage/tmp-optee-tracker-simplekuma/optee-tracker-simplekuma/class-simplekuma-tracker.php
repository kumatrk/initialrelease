<?php

namespace Optee\Tracker\SimpleKuma;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Optee\Plugin\Modules\AbstractTracker;
use Optee\Plugin\Models\StandardizedCampaignData;

/**
 * Simple KUMA (self-hosted) tracker integration.
 *
 * API: <install>/api/v1/...  — auth via `Authorization: Bearer kuma_...`.
 * Each user supplies their own install URL + API key (self-hosted, like Binom).
 *
 * Verified against Simple KUMA v1.1.4 (local):
 *   - GET /campaigns                  — campaign list (id, name, status string)
 *   - GET /campaigns/{id}             — campaign detail incl. custom_tokens
 *   - GET /stats/campaigns            — per-campaign summary (all campaigns) for from/to
 *   - GET /stats/campaigns/{id}       — single-campaign summary for from/to
 *   - GET /conversions                — conversion feed (campaign_id, from/to, page/per_page)
 *   - GET /clicks                     — click feed
 *
 * Grouped stats (added server-side, see Josh's API update):
 *   - GET /stats/campaigns/{id}?group_by=<dim> returns one row per dimension value
 *     plus meta.totals, paginated (page / per_page, max 1000). Supported dimensions:
 *     date, country, browser, os, isp, landing, offer, and any traffic-source token
 *     from the campaign's tracking link (zoneid, subid, …).
 *   - Without group_by the endpoint still returns a single summary object.
 *   - Every row carries lp_clicks (landing-page clicks) → Optee's Clicks column.
 *   - 'conversions' returns the raw conversion feed.
 */
class SimpleKumaTracker extends AbstractTracker {

    private const TIMEZONE  = 'UTC';
    private const PAGE_SIZE = 1000;     // Optee policy: never exceed 1000.

    public function get_slug(): string {
        return 'simplekuma';
    }

    public function get_name(): string {
        return 'Simple KUMA';
    }

    public function get_guide_url(): string {
        return '';
    }

    /** The Simple KUMA logo has white text — render it on a dark background. */
    public function get_logo_dark_bg(): bool {
        return true;
    }

    public function get_capabilities(): array {
        return [
            [ 'label' => 'Test Connection', 'implemented' => true ],
            [ 'label' => 'Get Campaigns',   'implemented' => true ],
            [ 'label' => 'Get Statistics',  'implemented' => true ],
            [ 'label' => 'Get Conversions', 'implemented' => true ],
            [ 'label' => 'Get Zone Fields', 'implemented' => true ],
        ];
    }

    public function get_credential_fields(): array {
        return [
            [
                'key'         => 'api_url',
                'label'       => 'Tracker URL',
                'type'        => 'text',
                'placeholder' => 'https://track.example.com/path',
                'help_text'   => 'Your Simple KUMA app URL (include any subfolder)',
            ],
            [
                'key'         => 'api_key',
                'label'       => 'API Key',
                'type'        => 'password',
                'placeholder' => 'kuma_...',
                'help_text'   => 'Settings → API (key starts with kuma_)',
            ],
        ];
    }

    public function test_connection( array $credentials ): array {
        if ( empty( $credentials['api_url'] ) ) {
            return [ 'success' => false, 'message' => 'Tracker URL is required.' ];
        }
        if ( empty( $credentials['api_key'] ) ) {
            return [ 'success' => false, 'message' => 'API Key is required.' ];
        }

        $base = $this->base_url( $credentials['api_url'] );
        if ( $base === '' ) {
            return [ 'success' => false, 'message' => 'Tracker URL is not a valid URL.' ];
        }

        $insecure = $this->insecure_http( $base );
        if ( $insecure !== '' ) {
            return [ 'success' => false, 'message' => $insecure ];
        }

        $response = wp_remote_get( $base . '/campaigns?per_page=1', [
            'headers'   => $this->auth_headers( $credentials['api_key'] ),
            'timeout'   => 15,
            'sslverify' => $this->ssl_verify( $base ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => 'Request failed: ' . $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 200 ) {
            return [ 'success' => true, 'message' => 'Connected successfully. Click on SAVE button to finish setup.' ];
        }
        if ( $code === 401 || $code === 403 ) {
            return [ 'success' => false, 'message' => 'Invalid API key (HTTP ' . $code . ').' ];
        }
        if ( $code === 0 || $code === 404 ) {
            return [ 'success' => false, 'message' => 'Could not reach the Simple KUMA API at this URL. Check the Tracker URL.' ];
        }
        return [ 'success' => false, 'message' => 'Unexpected response (HTTP ' . $code . ').' ];
    }

    /* ------------------------------------------------------------------
     * URL / request helpers
     * ----------------------------------------------------------------*/

    /**
     * Build the API base URL from the stored Tracker URL.
     * Preserves any sub-directory path (self-hosted installs) and appends /api/v1.
     */
    private function base_url( ?string $raw = null ): string {
        $raw = trim( (string) ( $raw ?? ( $this->credentials['api_url'] ?? '' ) ) );
        if ( $raw === '' ) {
            return '';
        }
        if ( ! preg_match( '#^https?://#i', $raw ) ) {
            $raw = 'https://' . $raw;
        }
        if ( ! wp_parse_url( $raw, PHP_URL_HOST ) ) {
            return '';
        }
        $raw = rtrim( $raw, '/' );
        $raw = preg_replace( '#/api/v1$#', '', $raw ); // tolerate a pasted /api/v1
        return $raw . '/api/v1';
    }

    private function auth_headers( ?string $key = null ): array {
        return [
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . ( $key ?? ( $this->credentials['api_key'] ?? '' ) ),
        ];
    }

    /**
     * Self-hosted trackers on localhost / private IPs typically use a self-signed
     * certificate; skip SSL verification only for those hosts.
     */
    /**
     * Returns an error string if $base uses cleartext http:// to a public host
     * (the API key would travel unencrypted). Empty string means acceptable:
     * https, or http to a loopback / private-range / .local host on a trusted LAN.
     */
    private function insecure_http( string $base ): string {
        $parts = wp_parse_url( $base );
        if ( strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'http' ) {
            return '';
        }
        $host     = (string) ( $parts['host'] ?? '' );
        $is_local = $host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
            || preg_match( '#\.(local|test|localhost)$#i', $host )
            || ( filter_var( $host, FILTER_VALIDATE_IP ) !== false
                 && filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false );
        if ( $is_local ) {
            return '';
        }
        return 'Refusing to send your API key over plain http:// to a public host. Use https:// in the Tracker URL.';
    }

    private function ssl_verify( string $base ): bool {
        $host = (string) wp_parse_url( $base, PHP_URL_HOST );
        if ( $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' ) {
            return false;
        }
        if ( preg_match( '#\.(local|test|localhost)$#i', $host ) ) {
            return false;
        }
        // Only disable verification when the host is a *literal* IP address in a
        // private or reserved range — checked via filter_var, never by string
        // prefix, so a public host like "10.cdn.example.com" keeps TLS on.
        if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false
            && filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            return false;
        }
        return true;
    }

    /**
     * GET a relative endpoint and return the decoded `data` payload.
     *
     * @return array{data:mixed,meta:mixed}
     * @throws \RuntimeException On transport error or non-2xx response.
     */
    private function api_get( string $endpoint, array $params = [] ): array {
        $base = $this->base_url();
        $url  = $base . $endpoint;
        if ( $params ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'headers'   => $this->auth_headers(),
            'timeout'   => 30,
            'sslverify' => $this->ssl_verify( $base ),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Simple KUMA API request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 401 || $code === 403 ) {
            throw new \RuntimeException( 'Simple KUMA API: invalid API key.' );
        }
        if ( $code < 200 || $code >= 300 ) {
            $msg = $body['error']['message'] ?? $body['message'] ?? "HTTP {$code}";
            throw new \RuntimeException( "Simple KUMA API error: {$msg}" );
        }

        return is_array( $body ) ? $body : [];
    }

    /* ------------------------------------------------------------------
     * Campaigns
     * ----------------------------------------------------------------*/

    public function get_campaigns(): array {
        try {
            $response = $this->api_get( '/campaigns' );
        } catch ( \Throwable $e ) {
            return [];
        }

        $campaigns = [];
        foreach ( (array) ( $response['data'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
                continue;
            }
            $campaigns[] = StandardizedCampaignData::from_array( [
                'campaign_id'   => (string) $item['id'],
                'campaign_name' => (string) ( $item['name'] ?? '' ),
                'status'        => $this->map_status( (string) ( $item['status'] ?? '' ) ),
                'raw_data'      => $item,
            ] );
        }

        return $campaigns;
    }

    public function get_campaign_stats( string $campaign_id, string $date_from, string $date_to ): StandardizedCampaignData {
        // Aggregate the per-day breakdown (get_statistics is the tested path and uses
        // inclusive date boundaries) into a single campaign total. Base metrics sum;
        // derived metrics (roi/ctr/cpc/cpa) are recomputed from the totals.
        try {
            $rows = $this->get_statistics( $campaign_id, $date_from, $date_to, 'days' );
        } catch ( \Throwable $e ) {
            $rows = [];
        }

        $imp = $clk = $conv = $rev = $cost = $profit = 0.0;
        foreach ( $rows as $r ) {
            $imp    += (float) ( $r['tr_visits'] ?? 0 );
            $clk    += (float) ( $r['tr_clicks'] ?? 0 );
            $conv   += (float) ( $r['tr_conv'] ?? 0 );
            $rev    += (float) ( $r['tr_revenue'] ?? 0 );
            $cost   += (float) ( $r['tr_cost'] ?? 0 );
            $profit += (float) ( $r['tr_profit'] ?? 0 );
        }

        return StandardizedCampaignData::from_array( [
            'campaign_id' => $campaign_id,
            'impressions' => $imp,
            'clicks'      => $clk,
            'conversions' => $conv,
            'revenue'     => $rev,
            'cost'        => $cost,
            'profit'      => $profit,
            'roi'         => $cost > 0 ? round( ( $profit / $cost ) * 100, 2 ) : 0,
            'ctr'         => $imp > 0 ? round( ( $clk / $imp ) * 100, 2 ) : 0,
            'cpc'         => $clk > 0 ? round( $cost / $clk, 4 ) : 0,
            'cpa'         => $conv > 0 ? round( $cost / $conv, 4 ) : 0,
            'currency'    => 'USD',
            'date_from'   => $date_from,
            'date_to'     => $date_to,
        ] );
    }

    /** Simple KUMA has no per-zone breakdown endpoint. */
    public function get_stats_by_zone( string $campaign_id, string $date_from, string $date_to ): array {
        return [];
    }

    /* ------------------------------------------------------------------
     * Zone fields — Simple KUMA campaign custom tokens
     * ----------------------------------------------------------------*/

    /** Human labels for the common Simple KUMA / PropellerAds token parameters. */
    private const TOKEN_LABELS = [
        'zoneid'   => 'Zone ID',     'subid'    => 'Sub ID',         'bann'     => 'Banner ID',
        'dev'      => 'Device',      'campid'   => 'Campaign ID',    'brw'      => 'Browser',
        'brwv'     => 'Browser Version', 'os'   => 'OS',             'osv'      => 'OS Version',
        'ctry'     => 'Country',     'ctryname' => 'Country Name',   'lang'     => 'Language',
        'isp'      => 'ISP',         'carrier'  => 'Carrier',        'regi'     => 'Region',
        'conn'     => 'Connection',  'acti'     => 'User Activity',  'ztp'      => 'Zone Type',
        'cost'     => 'Cost',
    ];

    /**
     * Zone fields = the campaign's traffic-source token parameters.
     *
     * Simple KUMA's /catalog hides these (traffic_sources[].tokens = []), but the
     * tracking-link endpoint returns the full token parameter list appended to the
     * campaign URL (zoneid, subid, …), so we read it from there.
     */
    public function get_zone_fields( string $campaign_id ): array {
        try {
            $resp = $this->api_get( '/campaigns/' . rawurlencode( $campaign_id ) . '/tracking-link' );
        } catch ( \Throwable $e ) {
            return [];
        }

        $tokens = $resp['data']['tokens'] ?? [];
        $fields = [];
        $seen   = [];
        foreach ( (array) $tokens as $param ) {
            $param = (string) $param;
            if ( $param === '' || isset( $seen[ $param ] ) ) {
                continue;
            }
            $seen[ $param ] = true;
            $fields[]       = [
                'zone_id'   => $param,
                'zone_name' => self::TOKEN_LABELS[ $param ] ?? $param,
            ];
        }

        return $fields;
    }

    /**
     * Map an Optee drilldown key to a Simple KUMA group_by dimension.
     *
     * Returns null for dimensions Simple KUMA has no equivalent for (cities,
     * useragents) so those tabs come back empty rather than erroring.
     */
    private function map_group_by( string $group_by ): ?string {
        // Named Optee drilldowns → Simple KUMA named dimensions / token dimensions.
        // 'default' (the main zone view with no zone field configured) and 'days'
        // both fall back to the day breakdown.
        $named = [
            'default'          => 'date',
            'days'             => 'date',
            'geo'              => 'country',
            'browsers'         => 'browser',
            'os'               => 'os',
            'isp'              => 'isp',
            'lp'               => 'landing',
            'offers'           => 'offer',
            // Dimensions Simple KUMA only exposes as traffic-source tokens.
            'languages'        => 'lang',
            'regions'          => 'regi',
            'connection'       => 'conn',
            'mobile-carriers'  => 'carrier',
            'browser-versions' => 'brwv',
            'os-versions'      => 'osv',
        ];
        if ( isset( $named[ $group_by ] ) ) {
            return $named[ $group_by ];
        }

        // Zone-token drilldown: the campaign's tracking-link token (zoneid, subid, …)
        // is passed straight through as the Simple KUMA group_by value.
        if ( isset( self::TOKEN_LABELS[ $group_by ] ) ) {
            return $group_by;
        }

        return null;
    }

    /* ------------------------------------------------------------------
     * Statistics
     * ----------------------------------------------------------------*/

    public function get_statistics( string $campaign_id, string $date_from, string $date_to, string $group_by = 'days' ): array {
        if ( $group_by === 'conversions' ) {
            return $this->get_conversions( $campaign_id, $date_from, $date_to );
        }

        $dimension = $this->map_group_by( $group_by );
        if ( $dimension === null ) {
            return [];
        }

        $is_date   = ( $dimension === 'date' );
        $use_label = in_array( $dimension, [ 'landing', 'offer' ], true );

        $rows = [];
        $page = 1;
        do {
            try {
                $resp = $this->api_get( '/stats/campaigns/' . rawurlencode( $campaign_id ), [
                    'from'     => $date_from,
                    'to'       => $date_to,
                    'timezone' => self::TIMEZONE,
                    'group_by' => $dimension,
                    'page'     => $page,
                    'per_page' => self::PAGE_SIZE,
                ] );
            } catch ( \Throwable $e ) {
                break;
            }

            foreach ( (array) ( $resp['data'] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $group = (string) ( $item['group'] ?? '' );
                $label = (string) ( $item['group_label'] ?? '' );
                if ( $label === '' ) {
                    $label = $group;
                }
                // Skip blank group values on non-date dimensions; keep blank dates
                // so the day series stays contiguous.
                if ( $group === '' && ! $is_date ) {
                    continue;
                }

                // For date rows the value lives in the date column (zone empty) so they
                // merge with traffic-source day rows. For landing/offer use the friendly
                // name; for every other dimension use the raw value (country code, zone
                // id, …) so it joins traffic-source zone rows by the same key.
                $display    = $use_label ? $label : $group;
                $date_value = $is_date ? $group : '';
                $zone_value = $is_date ? ''     : $display;

                $rows[] = $this->map_stat_row( $item, $date_value, $zone_value );
            }

            $meta     = $resp['meta'] ?? [];
            $per_page = (int) ( $meta['per_page'] ?? self::PAGE_SIZE );
            $total    = (int) ( $meta['total'] ?? count( $rows ) );
            $more     = $per_page > 0 && ( $page * $per_page ) < $total;
            $page++;
        } while ( $more && $page <= 50 );

        return $rows;
    }

    /**
     * Map a Simple KUMA stat row (summary or grouped) to Optee's tracker row (tr_ columns).
     *
     * Simple KUMA returns `clicks` (= visits) and `lp_clicks` (landing-page clicks);
     * lp_clicks feeds Optee's Clicks column.
     */
    private function map_stat_row( array $item, string $date_value, string $zone_value ): array {
        $visits  = (float) ( $item['clicks'] ?? 0 );
        $clicks  = (float) ( $item['lp_clicks'] ?? 0 );
        $conv    = (float) ( $item['conversions'] ?? 0 );
        $cost    = (float) ( $item['cost'] ?? 0 );
        $revenue = (float) ( $item['revenue'] ?? 0 );
        $profit  = isset( $item['profit'] ) ? (float) $item['profit'] : ( $revenue - $cost );

        $cpm = $visits > 0 ? ( $cost / $visits ) * 1000 : 0;
        $cpa = $conv > 0 ? ( $cost / $conv ) : 0;
        $ap  = $conv > 0 ? ( $revenue / $conv ) : 0;
        $ctr = $visits > 0 ? ( $clicks / $visits ) * 100 : 0;
        $cv  = isset( $item['conversion_rate'] )
            ? (float) $item['conversion_rate']
            : ( $visits > 0 ? $conv / $visits * 100 : 0 );

        return [
            'date'           => $date_value,
            'zone_id'        => $zone_value,
            'tr_visits'      => $visits,
            'tr_clicks'      => $clicks,
            'tr_conv'        => $conv,
            'tr_conv_all'    => $conv,
            'tr_revenue'     => $revenue,
            'tr_revenue_all' => $revenue,
            'tr_cost'        => $cost,
            'tr_profit'      => $profit,
            'tr_roi'         => (float) ( $item['roi'] ?? ( $cost > 0 ? $profit / $cost * 100 : 0 ) ),
            'tr_ctr'         => round( $ctr, 2 ),
            'tr_cv'          => round( (float) $cv, 4 ),
            'tr_cpm'         => round( $cpm, 3 ),
            'tr_cpa'         => round( $cpa, 3 ),
            'tr_cpa_ts'      => 0,
            'tr_ap'          => round( $ap, 3 ),
            'raw'            => $item,
        ];
    }

    /* ------------------------------------------------------------------
     * Conversions
     * ----------------------------------------------------------------*/

    private function get_conversions( string $campaign_id, string $date_from, string $date_to ): array {
        $rows = [];
        $page = 1;

        do {
            try {
                $response = $this->api_get( '/conversions', [
                    'campaign_id' => $campaign_id,
                    'from'        => $date_from,
                    'to'          => $date_to,
                    'timezone'    => self::TIMEZONE,
                    'page'        => $page,
                    'per_page'    => self::PAGE_SIZE,
                ] );
            } catch ( \Throwable $e ) {
                break;
            }

            $items = $response['data'] ?? [];
            foreach ( (array) $items as $idx => $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                // Simple KUMA uses `ts` for timestamps and `conversion_payout` for
                // payout in its feeds (confirmed via the /clicks feed); accept the
                // generic names too in case the conversions feed differs.
                $when = $item['conversed_at'] ?? $item['converted_at'] ?? $item['ts'] ?? $item['created_at'] ?? '';
                $rows[] = [
                    'zone_id'    => $item['id'] ?? $idx,
                    'date'       => $when,
                    'timestamp'  => $when,
                    'click_id'   => $item['click_id'] ?? $item['id'] ?? '',
                    'click_time' => $item['clicked_at'] ?? '',
                    'offer'      => $item['offer_name'] ?? $item['offer'] ?? ( isset( $item['offer_id'] ) ? '#' . $item['offer_id'] : '' ),
                    'revenue'    => (float) ( $item['conversion_payout'] ?? $item['payout'] ?? $item['revenue'] ?? 0 ),
                    'status'     => $item['status'] ?? '',
                    'country'    => $item['country'] ?? $item['geo'] ?? '',
                    'raw'        => $item,
                ];
            }

            $meta     = $response['meta'] ?? [];
            $per_page = (int) ( $meta['per_page'] ?? self::PAGE_SIZE );
            $total    = (int) ( $meta['total'] ?? count( $rows ) );
            $more     = ( $page * $per_page ) < $total && ! empty( $items );
            $page++;
        } while ( $more && $page <= 50 );

        return $rows;
    }

    /* ------------------------------------------------------------------
     * Dashboard — all campaigns in one call via /stats/campaigns
     * ----------------------------------------------------------------*/

    public function get_dashboard_statistics( array $campaign_ids, string $date_from, string $date_to ): array {
        $results = [];
        foreach ( $campaign_ids as $cid ) {
            $results[ (string) $cid ] = [ 'tr_visits' => 0, 'tr_clicks' => 0, 'tr_conv' => 0, 'tr_cost' => 0.0, 'tr_revenue' => 0.0 ];
        }

        try {
            $response = $this->api_get( '/stats/campaigns', [
                'from'     => $date_from,
                'to'       => $date_to,
                'timezone' => self::TIMEZONE,
            ] );
        } catch ( \Throwable $e ) {
            return $results;
        }

        $lookup = array_flip( array_map( 'strval', $campaign_ids ) );
        foreach ( (array) ( $response['data'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['campaign_id'] ) ) {
                continue;
            }
            $id = (string) $item['campaign_id'];
            if ( ! isset( $lookup[ $id ] ) ) {
                continue;
            }
            $results[ $id ] = [
                'tr_visits'  => (int) ( $item['clicks'] ?? 0 ),
                'tr_clicks'  => (int) ( $item['lp_clicks'] ?? 0 ),
                'tr_conv'    => (int) ( $item['conversions'] ?? 0 ),
                'tr_cost'    => (float) ( $item['cost'] ?? 0 ),
                'tr_revenue' => (float) ( $item['revenue'] ?? 0 ),
            ];
        }

        return $results;
    }

    /* ------------------------------------------------------------------
     * Status mapping
     * ----------------------------------------------------------------*/

    private function map_status( string $status ): string {
        return match ( strtolower( $status ) ) {
            'active'           => 'active',
            'paused'           => 'paused',
            'archived'         => 'archived',
            default            => 'paused',
        };
    }
}
