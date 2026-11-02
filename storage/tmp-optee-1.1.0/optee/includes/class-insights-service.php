<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fetch + persist network-wide offer insights from Monetizer, Mobipium, OGAds.
 *
 * Read-only data for the ?module=insights page. Per-user storage.
 * Cron rotates 1 network per hourly tick (hour % 3); manual refresh fetches
 * all 3 in parallel via curl_multi.
 *
 * Storage convention: REPLACE INTO — each refresh wipes prior rows for
 * (user_id, network_slug) and inserts the fresh set.
 */
class InsightsService {

    /** Networks supported in v1. */
    public const NETWORKS = [ 'mobipium', 'monetizer', 'ogads' ];

    /** Mobipium fetch limit. */
    private const MOBIPIUM_LIMIT = 500;

    /** OGAds fetch limit. */
    private const OGADS_LIMIT = 500;

    // ──────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────

    /**
     * Refresh a single network for a given user. Returns an array describing the result.
     */
    public static function refresh_network( int $user_id, string $slug ): array {
        $start = microtime( true );

        $rows = match ( $slug ) {
            'monetizer' => self::fetch_monetizer( $user_id ),
            'mobipium'  => self::fetch_mobipium( $user_id ),
            'ogads'     => self::fetch_ogads( $user_id ),
            default     => [ 'error' => "Unknown network: $slug" ],
        };

        if ( isset( $rows['error'] ) ) {
            return [
                'success' => false,
                'slug'    => $slug,
                'count'   => 0,
                'ms'      => round( ( microtime( true ) - $start ) * 1000 ),
                'error'   => $rows['error'],
            ];
        }

        $count = self::store_rows( $user_id, $slug, $rows );
        self::set_last_run( $user_id, $slug );

        return [
            'success' => true,
            'slug'    => $slug,
            'count'   => $count,
            'ms'      => round( ( microtime( true ) - $start ) * 1000 ),
        ];
    }

    /**
     * Refresh all three networks IN PARALLEL via curl_multi for one user.
     * Returns per-network result array keyed by slug.
     */
    public static function refresh_all_parallel( int $user_id ): array {
        $start = microtime( true );

        // Prepare each network's HTTP requests
        $request_specs = [];
        $request_specs += self::monetizer_requests( $user_id ); // 2 requests
        $request_specs += self::mobipium_requests( $user_id );  // 1 request
        $request_specs += self::ogads_requests( $user_id );     // 1 request

        if ( empty( $request_specs ) ) {
            return [ 'error' => 'No connected networks for this user.' ];
        }

        // Fire all requests in parallel
        $mh = curl_multi_init();
        $handles = [];
        foreach ( $request_specs as $key => $spec ) {
            $ch = curl_init( $spec['url'] );
            curl_setopt_array( $ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => $spec['headers'] ?? [],
            ] );
            curl_multi_add_handle( $mh, $ch );
            $handles[ $key ] = $ch;
        }
        $running = null;
        do {
            curl_multi_exec( $mh, $running );
            curl_multi_select( $mh );
        } while ( $running > 0 );

        $responses = [];
        foreach ( $handles as $key => $ch ) {
            $body = curl_multi_getcontent( $ch );
            $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $responses[ $key ] = [
                'code' => $code,
                'body' => $body,
            ];
            curl_multi_remove_handle( $mh, $ch );
            curl_close( $ch );
        }
        curl_multi_close( $mh );

        // Normalize + store per network
        $results = [];

        if ( isset( $responses['monetizer:lifetime'] ) && isset( $responses['monetizer:today'] ) ) {
            $rows = self::normalize_monetizer(
                json_decode( $responses['monetizer:lifetime']['body'], true ),
                json_decode( $responses['monetizer:today']['body'], true )
            );
            $count = self::store_rows( $user_id, 'monetizer', $rows );
            $results['monetizer'] = [ 'success' => true, 'count' => $count ];
            self::set_last_run( $user_id, 'monetizer' );
        }

        if ( isset( $responses['mobipium'] ) ) {
            $json = json_decode( $responses['mobipium']['body'], true );
            $rows = self::normalize_mobipium( $json );
            $count = self::store_rows( $user_id, 'mobipium', $rows );
            $results['mobipium'] = [ 'success' => true, 'count' => $count ];
            self::set_last_run( $user_id, 'mobipium' );
        }

        if ( isset( $responses['ogads'] ) ) {
            $json = json_decode( $responses['ogads']['body'], true );
            $rows = self::normalize_ogads( $json );
            $count = self::store_rows( $user_id, 'ogads', $rows );
            $results['ogads'] = [ 'success' => true, 'count' => $count ];
            self::set_last_run( $user_id, 'ogads' );
        }

        $results['_elapsed_ms'] = round( ( microtime( true ) - $start ) * 1000 );
        return $results;
    }

    /**
     * Get stored insights for a user, optionally filtered by network.
     */
    public static function get_stored( int $user_id, ?string $network_slug = null ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'optee_insights_offers';
        $where = $wpdb->prepare( 'user_id = %d', $user_id );
        if ( $network_slug ) {
            $where .= $wpdb->prepare( ' AND network_slug = %s', $network_slug );
        }
        $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY rank_value DESC", ARRAY_A );
        // Decode raw_data on the way out
        foreach ( $rows as &$r ) {
            if ( ! empty( $r['raw_data'] ) ) {
                $r['raw_data'] = json_decode( $r['raw_data'], true );
            }
        }
        return $rows ?: [];
    }

    /** Last-refreshed timestamp per (user, network). */
    public static function get_last_run( int $user_id, string $slug ): int {
        return (int) get_option( "optee_insights_last_{$user_id}_{$slug}", 0 );
    }

    public static function set_last_run( int $user_id, string $slug ): void {
        update_option( "optee_insights_last_{$user_id}_{$slug}", time(), false );
    }

    // ──────────────────────────────────────────────────────────────────
    // Per-network: build request specs (used by parallel refresh)
    // ──────────────────────────────────────────────────────────────────

    private static function monetizer_requests( int $user_id ): array {
        $creds = self::get_creds( $user_id, 'monetizer' );
        if ( empty( $creds['api_token'] ) ) return [];

        $build_url = function ( $start_ts = null ) {
            $params = [
                'min_payout'      => '0',
                'max_payout'      => '99999',
                'min_epc'         => '0',
                'max_epc'         => '999',
                'min_revenue'     => '0',
                'max_revenue'     => '99999999',
                'conversion_flow' => 'all',
                'vertical'        => 'all',
                'hide_paused'     => '1',
            ];
            if ( $start_ts !== null ) $params['start_ts'] = (int) $start_ts;
            return add_query_arg( $params, 'https://api.monetizer.com/v3/offersAggregatedGlobal.php' );
        };

        $headers = [
            'X_afflow_api_token: ' . $creds['api_token'],
            'Content-Type: application/json',
        ];

        return [
            'monetizer:lifetime' => [ 'url' => $build_url(),                  'headers' => $headers ],
            'monetizer:today'    => [ 'url' => $build_url( time() - 86400 ), 'headers' => $headers ],
        ];
    }

    private static function mobipium_requests( int $user_id ): array {
        $creds = self::get_creds( $user_id, 'mobipium' );
        if ( empty( $creds['api_token'] ) ) return [];

        $url = 'https://affiliates.mobipium.com/api/cpa/findmyoffers/?' . http_build_query( [
            'mwsd'  => $creds['api_token'],
            'limit' => self::MOBIPIUM_LIMIT,
            'pages' => 1,
        ] );

        return [ 'mobipium' => [ 'url' => $url, 'headers' => [ 'Accept: application/json' ] ] ];
    }

    private static function ogads_requests( int $user_id ): array {
        $creds = self::get_creds( $user_id, 'ogads' );
        if ( empty( $creds['affiliate_id'] ) ) return [];

        $url = 'https://mobverify.com/api/v1/?affiliateid=' . urlencode( $creds['affiliate_id'] );

        return [ 'ogads' => [ 'url' => $url, 'headers' => [] ] ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Per-network: single-fetch path (used by cron)
    // ──────────────────────────────────────────────────────────────────

    private static function fetch_monetizer( int $user_id ) {
        $creds = self::get_creds( $user_id, 'monetizer' );
        if ( empty( $creds['api_token'] ) ) {
            return [ 'error' => 'Monetizer: no credentials.' ];
        }
        $specs = self::monetizer_requests( $user_id );

        // Parallel fetch for the two windows (lifetime + 24h)
        $mh = curl_multi_init();
        $handles = [];
        foreach ( $specs as $key => $spec ) {
            $ch = curl_init( $spec['url'] );
            curl_setopt_array( $ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => $spec['headers'],
            ] );
            curl_multi_add_handle( $mh, $ch );
            $handles[ $key ] = $ch;
        }
        $running = null;
        do { curl_multi_exec( $mh, $running ); curl_multi_select( $mh ); } while ( $running > 0 );

        $bodies = [];
        foreach ( $handles as $key => $ch ) {
            $bodies[ $key ] = json_decode( curl_multi_getcontent( $ch ), true );
            curl_multi_remove_handle( $mh, $ch );
            curl_close( $ch );
        }
        curl_multi_close( $mh );

        return self::normalize_monetizer(
            $bodies['monetizer:lifetime'] ?? null,
            $bodies['monetizer:today']    ?? null
        );
    }

    private static function fetch_mobipium( int $user_id ) {
        $creds = self::get_creds( $user_id, 'mobipium' );
        if ( empty( $creds['api_token'] ) ) {
            return [ 'error' => 'Mobipium: no credentials.' ];
        }
        $resp = wp_remote_get( 'https://affiliates.mobipium.com/api/cpa/findmyoffers/?' . http_build_query( [
            'mwsd'  => $creds['api_token'],
            'limit' => self::MOBIPIUM_LIMIT,
            'pages' => 1,
        ] ), [ 'headers' => [ 'Accept' => 'application/json' ], 'timeout' => 30 ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'error' => 'Mobipium: ' . $resp->get_error_message() ];
        }
        return self::normalize_mobipium( json_decode( wp_remote_retrieve_body( $resp ), true ) );
    }

    private static function fetch_ogads( int $user_id ) {
        $creds = self::get_creds( $user_id, 'ogads' );
        if ( empty( $creds['affiliate_id'] ) ) {
            return [ 'error' => 'OGAds: no credentials.' ];
        }
        $resp = wp_remote_get(
            'https://mobverify.com/api/v1/?affiliateid=' . urlencode( $creds['affiliate_id'] ),
            [ 'timeout' => 30 ]
        );
        if ( is_wp_error( $resp ) ) {
            return [ 'error' => 'OGAds: ' . $resp->get_error_message() ];
        }
        return self::normalize_ogads( json_decode( wp_remote_retrieve_body( $resp ), true ) );
    }

    // ──────────────────────────────────────────────────────────────────
    // Normalization → unified row shape
    // ──────────────────────────────────────────────────────────────────

    private static function normalize_monetizer( $lifetime_body, $today_body ): array {
        if ( ! is_array( $lifetime_body ) ) return [];

        // Index today's offers by hash for merge
        $today_idx = [];
        if ( is_array( $today_body ) ) {
            foreach ( $today_body as $t ) {
                $hash = (string) ( $t['public_id_hash'] ?? '' );
                if ( $hash !== '' ) $today_idx[ $hash ] = $t;
            }
        }

        $rows = [];
        foreach ( $lifetime_body as $o ) {
            $hash    = (string) ( $o['public_id_hash'] ?? '' );
            if ( $hash === '' ) continue;
            $today_o = $today_idx[ $hash ] ?? [];

            // Compose name: "Revshare-1a2131-PH"
            $flow_clean = ucfirst( strtolower( str_replace( ' ', '', (string) ( $o['conversion_flow'] ?? '' ) ) ) );
            $dep        = (string) ( $o['offerIdDep'] ?? '' );
            $geo_short  = (string) ( $o['aff_campaign_geo'] ?? '' );
            $parts      = array_filter( [ $flow_clean, $dep, $geo_short ] );
            $offer_name = $parts ? implode( '-', $parts ) : $hash;

            $ctl = floatval( $today_o['ctl'] ?? 0 );

            $rows[] = [
                'offer_id'      => $hash,
                'offer_name'    => $offer_name,
                'payout'        => floatval( $o['payout'] ?? 0 ),
                'currency'      => 'USD',
                'geo'           => (string) ( $o['aff_campaign_geo'] ?? '' ),
                'vertical'      => (string) ( $o['aff_campaign_vertical'] ?? '' ),
                'thumbnail'     => (string) ( $o['preview_image'] ?? '' ),
                'rank_metric'   => 'epc',
                'rank_value'    => floatval( $o['epc'] ?? 0 ),
                'rank_display'  => null,
                'cap_pct'       => null,
                'cr_pct'        => $ctl > 0 ? round( 100 / $ctl, 4 ) : null,
                'revenue_today' => floatval( $today_o['revenue'] ?? 0 ),
                'leads_today'   => intval( $today_o['leads'] ?? 0 ),
                'boosted'       => 0,
                'raw_data'      => [ 'lifetime' => $o, 'today' => $today_o ],
            ];
        }
        return $rows;
    }

    private static function normalize_mobipium( $body ): array {
        if ( ! is_array( $body ) || empty( $body['offers'] ) ) return [];

        $rows = [];
        foreach ( $body['offers'] as $o ) {
            $offer_id = (string) ( $o['offer_id'] ?? '' );
            if ( $offer_id === '' ) continue;

            $last_conv_str = (string) ( $o['last_conv'] ?? '' );
            $last_conv_min = self::parse_duration_minutes( $last_conv_str );

            // Per locked decision: skip Mobipium offers with no last_conv (=dormant)
            if ( $last_conv_min === null ) continue;

            $rows[] = [
                'offer_id'      => $offer_id,
                'offer_name'    => (string) ( $o['offer_name'] ?? '' ),
                'payout'        => floatval( $o['payout'] ?? 0 ),
                'currency'      => 'USD',
                'geo'           => (string) ( $o['country'] ?? '' ),
                'vertical'      => (string) ( $o['vertical'] ?? '' ),
                'thumbnail'     => (string) ( $o['thumbnails'] ?? '' ),
                'rank_metric'   => 'last_conv',
                'rank_value'    => $last_conv_min,
                'rank_display'  => $last_conv_str,
                'cap_pct'       => isset( $o['filled_cap'] ) && $o['filled_cap'] !== '' ? intval( $o['filled_cap'] ) : null,
                'cr_pct'        => null,
                'revenue_today' => 0,
                'leads_today'   => 0,
                'boosted'       => 0,
                'raw_data'      => $o,
            ];
        }
        return $rows;
    }

    private static function normalize_ogads( $body ): array {
        if ( ! is_array( $body ) || empty( $body['offers'] ) ) return [];

        $rows = [];
        foreach ( $body['offers'] as $o ) {
            $offer_id = (string) ( $o['offerid'] ?? '' );
            if ( $offer_id === '' ) continue;

            $rows[] = [
                'offer_id'      => $offer_id,
                'offer_name'    => (string) ( $o['name_short'] ?? $o['name'] ?? '' ),
                'payout'        => floatval( $o['payout'] ?? 0 ),
                'currency'      => 'USD',
                'geo'           => (string) ( $o['country'] ?? '' ),
                'vertical'      => (string) ( $o['ctype'] ?? '' ),
                'thumbnail'     => (string) ( $o['picture'] ?? '' ),
                'rank_metric'   => 'epc',
                'rank_value'    => floatval( $o['epc'] ?? 0 ),
                'rank_display'  => null,
                'cap_pct'       => null,
                'cr_pct'        => floatval( $o['cvr'] ?? 0 ),  // already %
                'revenue_today' => 0,
                'leads_today'   => 0,
                'boosted'       => ! empty( $o['boosted'] ) ? 1 : 0,
                'raw_data'      => $o,
            ];
        }
        return $rows;
    }

    // ──────────────────────────────────────────────────────────────────
    // Storage
    // ──────────────────────────────────────────────────────────────────

    /**
     * Wipe + insert rows for (user, network). Returns the count actually stored.
     *
     * Rows are deduplicated on offer_id before insert. The table has a UNIQUE key
     * `uniq_user_net_offer` on (user_id, network_slug, offer_id), and the affiliate feeds do NOT
     * guarantee one row per offer — Mobipium in particular lists an offer once per carrier/GEO
     * variant, so the same offer_id arrives several times in a single response. Inserting them
     * blind produced a "Duplicate entry" DB error per collision: 343 of them in eight days on the
     * test site, every one written to the PHP error_log, which is what filled it to 714 KB.
     *
     * Last occurrence wins, which is also what the REPLACE below would do — so the two paths
     * agree rather than quietly disagreeing about which variant survives.
     */
    private static function store_rows( int $user_id, string $network_slug, array $rows ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'optee_insights_offers';

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE user_id = %d AND network_slug = %s",
            $user_id, $network_slug
        ) );

        if ( empty( $rows ) ) return 0;

        $unique = [];
        foreach ( $rows as $r ) {
            $offer_id = (string) ( $r['offer_id'] ?? '' );
            if ( $offer_id === '' ) {
                continue;
            }
            $unique[ $offer_id ] = $r;
        }

        $now = current_time( 'mysql' );
        $count = 0;
        foreach ( $unique as $r ) {
            // REPLACE, not insert: the dedupe above removes every collision we know about, but a
            // feed that invents a new way to repeat itself should update the row rather than emit
            // another error into the log. Safe here because the DELETE above already cleared this
            // (user, network), so no id worth preserving exists.
            $ok = $wpdb->replace( $table, [
                'user_id'       => $user_id,
                'network_slug'  => $network_slug,
                'offer_id'      => (string) $r['offer_id'],
                'offer_name'    => (string) ( $r['offer_name'] ?? '' ),
                'payout'        => (float)  ( $r['payout'] ?? 0 ),
                'currency'      => (string) ( $r['currency'] ?? 'USD' ),
                'geo'           => (string) ( $r['geo'] ?? '' ),
                'vertical'      => (string) ( $r['vertical'] ?? '' ),
                'thumbnail'     => (string) ( $r['thumbnail'] ?? '' ),
                'rank_metric'   => (string) ( $r['rank_metric'] ?? 'epc' ),
                'rank_value'    => (float)  ( $r['rank_value'] ?? 0 ),
                'rank_display'  => $r['rank_display'] ?? null,
                'cap_pct'       => $r['cap_pct'] ?? null,
                'cr_pct'        => $r['cr_pct'] ?? null,
                'revenue_today' => (float)  ( $r['revenue_today'] ?? 0 ),
                'leads_today'   => (int)    ( $r['leads_today'] ?? 0 ),
                'boosted'       => (int)    ( $r['boosted'] ?? 0 ),
                'raw_data'      => isset( $r['raw_data'] ) ? wp_json_encode( $r['raw_data'] ) : null,
                'fetched_at'    => $now,
            ] );
            // Count what was stored, not what was attempted. The old unconditional increment
            // reported every failed insert as a success, so the refresh result said "142 offers"
            // while the table held fewer — the count agreed with the feed, never with the DB.
            if ( $ok !== false ) {
                $count++;
            }
        }
        return $count;
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Fetch decrypted credentials for a user. Switches WP user context temporarily so
     * ModuleRegistry::get_stored_credentials() (which reads get_current_user_id) works.
     */
    private static function get_creds( int $user_id, string $slug ): array {
        $original = get_current_user_id();
        if ( $user_id !== $original ) {
            wp_set_current_user( $user_id );
        }
        $creds = ModuleRegistry::get_stored_credentials( 'affiliate_network', $slug );
        if ( $user_id !== $original ) {
            wp_set_current_user( $original );
        }
        return is_array( $creds ) ? $creds : [];
    }

    /**
     * Parse Mobipium-style duration string ("4min", "12h32min", "1d") → minutes.
     * Returns null for empty/unparseable (which means "no recent conversion data").
     */
    private static function parse_duration_minutes( string $s ): ?int {
        $s = strtolower( trim( $s ) );
        if ( $s === '' ) return null;
        $total = 0; $matched = false;
        if ( preg_match( '/(\d+)\s*d/', $s, $m ) )   { $total += (int) $m[1] * 1440; $matched = true; }
        if ( preg_match( '/(\d+)\s*h/', $s, $m ) )   { $total += (int) $m[1] * 60;   $matched = true; }
        if ( preg_match( '/(\d+)\s*min/', $s, $m ) ) { $total += (int) $m[1];        $matched = true; }
        return $matched ? $total : null;
    }
}
