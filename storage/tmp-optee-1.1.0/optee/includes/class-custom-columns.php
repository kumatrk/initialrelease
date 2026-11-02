<?php
/**
 * Custom Columns — storage, formula parser, validation.
 *
 * Custom columns are user-defined arithmetic combinations of built-in
 * report fields (e.g. "[Tracker] Revenue - [Traffic Source] Cost"). They
 * are stored per-user in user_meta and rendered client-side; this class
 * is responsible for storage, friendly-token-to-internal-key mapping,
 * and parser-based formula validation.
 */

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CustomColumns {

    private const META_KEY = 'optee_custom_columns';

    /**
     * Canonical token map. Each entry: friendly token string => spec.
     *
     * Spec keys:
     *   key   — internal row-data key (matches keys used in COL_GROUPS JS)
     *   group — module-type group key (traffic_source, antifraud, tracker, *_mon)
     *   type  — number | money | percent
     */
    public static function get_token_map(): array {
        return [
            // Traffic Source.
            '[Traffic Source] Impressions' => [ 'key' => 'impressions',  'group' => 'traffic_source', 'type' => 'number'  ],
            '[Traffic Source] Clicks'      => [ 'key' => 'clicks',       'group' => 'traffic_source', 'type' => 'number'  ],
            '[Traffic Source] Conversions' => [ 'key' => 'conversions',  'group' => 'traffic_source', 'type' => 'number'  ],
            '[Traffic Source] Cost'        => [ 'key' => 'cost',         'group' => 'traffic_source', 'type' => 'money'   ],
            '[Traffic Source] Payout'      => [ 'key' => 'payout',       'group' => 'traffic_source', 'type' => 'money'   ],
            '[Traffic Source] Profit'      => [ 'key' => 'profit',       'group' => 'traffic_source', 'type' => 'money'   ],
            '[Traffic Source] ROI'         => [ 'key' => 'roi',          'group' => 'traffic_source', 'type' => 'percent' ],
            '[Traffic Source] CPM'         => [ 'key' => 'cpm',          'group' => 'traffic_source', 'type' => 'money'   ],
            '[Traffic Source] CPC'         => [ 'key' => 'cpc',          'group' => 'traffic_source', 'type' => 'money'   ],
            '[Traffic Source] CPA'         => [ 'key' => 'cpa',          'group' => 'traffic_source', 'type' => 'money'   ],
            '[Traffic Source] CTR'         => [ 'key' => 'ctr',          'group' => 'traffic_source', 'type' => 'percent' ],
            '[Traffic Source] CR'          => [ 'key' => 'cr',           'group' => 'traffic_source', 'type' => 'percent' ],
            '[Traffic Source] Balance'     => [ 'key' => 'ts_balance',   'group' => 'traffic_source', 'type' => 'money'   ], // Days report only — from Balances table.

            // Antifraud.
            '[Antifraud] Visits'         => [ 'key' => 'af_visits',      'group' => 'antifraud', 'type' => 'number'  ],
            '[Antifraud] Valid Hits'     => [ 'key' => 'valid_hits',     'group' => 'antifraud', 'type' => 'number'  ],
            '[Antifraud] Valid %'        => [ 'key' => 'valid_percent',  'group' => 'antifraud', 'type' => 'percent' ],
            '[Antifraud] Traffic Loss %' => [ 'key' => 'traffic_loss',   'group' => 'antifraud', 'type' => 'percent' ],
            '[Antifraud] Proxy %'        => [ 'key' => 'proxy_percent',  'group' => 'antifraud', 'type' => 'percent' ],
            '[Antifraud] Junk %'         => [ 'key' => 'junk_percent',   'group' => 'antifraud', 'type' => 'percent' ],
            '[Antifraud] Bot %'          => [ 'key' => 'bot_percent',    'group' => 'antifraud', 'type' => 'percent' ],
            '[Antifraud] Cost'           => [ 'key' => 'af_cost',        'group' => 'antifraud', 'type' => 'money'   ],

            // Tracker.
            '[Tracker] Visits'      => [ 'key' => 'tr_visits',    'group' => 'tracker', 'type' => 'number'  ],
            '[Tracker] Clicks'      => [ 'key' => 'tr_clicks',    'group' => 'tracker', 'type' => 'number'  ],
            '[Tracker] Conversions' => [ 'key' => 'tr_conv_all',  'group' => 'tracker', 'type' => 'number'  ],
            '[Tracker] Revenue'     => [ 'key' => 'tr_revenue',   'group' => 'tracker', 'type' => 'money'   ],
            '[Tracker] Cost'        => [ 'key' => 'tr_cost',      'group' => 'tracker', 'type' => 'money'   ],
            '[Tracker] Profit'      => [ 'key' => 'tr_profit',    'group' => 'tracker', 'type' => 'money'   ],
            '[Tracker] ROI'         => [ 'key' => 'tr_roi',       'group' => 'tracker', 'type' => 'percent' ],
            '[Tracker] CTR'         => [ 'key' => 'tr_ctr',       'group' => 'tracker', 'type' => 'percent' ],
            '[Tracker] CV'          => [ 'key' => 'tr_cv',        'group' => 'tracker', 'type' => 'percent' ],
            '[Tracker] CPM'         => [ 'key' => 'tr_cpm',       'group' => 'tracker', 'type' => 'money'   ],
            '[Tracker] CPA'         => [ 'key' => 'tr_cpa',       'group' => 'tracker', 'type' => 'money'   ],
            '[Tracker] AP'          => [ 'key' => 'tr_ap',        'group' => 'tracker', 'type' => 'money'   ],

            // Monetization — per platform (user can have multiple active simultaneously).
            '[Monetag] Earnings'    => [ 'key' => 'mtg_earnings',    'group' => 'monetag_mon', 'type' => 'money'  ],
            '[Monetag] Impressions' => [ 'key' => 'mtg_impressions', 'group' => 'monetag_mon', 'type' => 'number' ],
            '[Monetag] eCPM'        => [ 'key' => 'mtg_ecpm',        'group' => 'monetag_mon', 'type' => 'money'  ],

            '[ProPush] Subscribed'  => [ 'key' => 'pp_subscribed',   'group' => 'propush_mon', 'type' => 'number' ],
            '[ProPush] Impressions' => [ 'key' => 'pp_impressions',  'group' => 'propush_mon', 'type' => 'number' ],
            '[ProPush] Profit'      => [ 'key' => 'pp_earnings',     'group' => 'propush_mon', 'type' => 'money'  ],
            '[ProPush] CPM'         => [ 'key' => 'pp_cpm',          'group' => 'propush_mon', 'type' => 'money'  ],

            '[Partners.House] Subscriptions' => [ 'key' => 'ph_subscriptions', 'group' => 'ph_mon', 'type' => 'number' ],
            '[Partners.House] Impressions'   => [ 'key' => 'ph_impressions',   'group' => 'ph_mon', 'type' => 'number' ],
            '[Partners.House] Profit'        => [ 'key' => 'ph_earnings',      'group' => 'ph_mon', 'type' => 'money'  ],
            '[Partners.House] CPM'           => [ 'key' => 'ph_cpm',           'group' => 'ph_mon', 'type' => 'money'  ],

            '[RollerAds Publisher] Requests'     => [ 'key' => 'rap_requests',     'group' => 'rap_mon', 'type' => 'number'  ],
            '[RollerAds Publisher] Subscribed'  => [ 'key' => 'rap_subscribed',   'group' => 'rap_mon', 'type' => 'number'  ],
            '[RollerAds Publisher] Unsubscribed' => [ 'key' => 'rap_unsubscribed', 'group' => 'rap_mon', 'type' => 'number' ],
            '[RollerAds Publisher] CR'          => [ 'key' => 'rap_cr',           'group' => 'rap_mon', 'type' => 'percent' ],
            '[RollerAds Publisher] Impressions' => [ 'key' => 'rap_impressions',  'group' => 'rap_mon', 'type' => 'number'  ],
            '[RollerAds Publisher] Profit'      => [ 'key' => 'rap_earnings',     'group' => 'rap_mon', 'type' => 'money'   ],
            '[RollerAds Publisher] CPM'         => [ 'key' => 'rap_cpm',          'group' => 'rap_mon', 'type' => 'money'   ],

            '[HilltopAds Publisher] Impressions' => [ 'key' => 'hta_impressions', 'group' => 'hta_mon', 'type' => 'number' ],
            '[HilltopAds Publisher] Clicks'      => [ 'key' => 'hta_clicks',      'group' => 'hta_mon', 'type' => 'number' ],
            '[HilltopAds Publisher] Profit'      => [ 'key' => 'hta_earnings',    'group' => 'hta_mon', 'type' => 'money'  ],
            '[HilltopAds Publisher] CPM'         => [ 'key' => 'hta_cpm',         'group' => 'hta_mon', 'type' => 'money'  ],

            '[Monetizer] Clicks'  => [ 'key' => 'mtzr_clicks',  'group' => 'mtzr_mon', 'type' => 'number' ],
            '[Monetizer] Leads'   => [ 'key' => 'mtzr_leads',   'group' => 'mtzr_mon', 'type' => 'number' ],
            '[Monetizer] Revenue' => [ 'key' => 'mtzr_revenue', 'group' => 'mtzr_mon', 'type' => 'money'  ],
            '[Monetizer] EPC'     => [ 'key' => 'mtzr_epc',     'group' => 'mtzr_mon', 'type' => 'money'  ],

            '[Zeydoo] Requests'    => [ 'key' => 'zd_requests',    'group' => 'zeydoo_mon', 'type' => 'number' ],
            '[Zeydoo] Impressions' => [ 'key' => 'zd_impressions', 'group' => 'zeydoo_mon', 'type' => 'number' ],
            '[Zeydoo] Conversions' => [ 'key' => 'zd_conversions', 'group' => 'zeydoo_mon', 'type' => 'number' ],
            '[Zeydoo] Earnings'    => [ 'key' => 'zd_earnings',    'group' => 'zeydoo_mon', 'type' => 'money'  ],
            '[Zeydoo] CPM'         => [ 'key' => 'zd_cpm',         'group' => 'zeydoo_mon', 'type' => 'money'  ],
        ];
    }

    /**
     * Set of all valid internal keys (used when sanity-checking fields_used).
     */
    public static function get_valid_keys(): array {
        $keys = [];
        foreach ( self::get_token_map() as $spec ) {
            $keys[ $spec['key'] ] = true;
        }
        return $keys;
    }

    /**
     * Format options whitelist.
     */
    public static function valid_formats(): array {
        return [ 'number', 'money', 'percent' ];
    }

    /**
     * Color modes whitelist (mirrors built-in column color modes).
     */
    public static function valid_colors(): array {
        return [ '', 'profit', 'higher_better', 'lower_better' ];
    }

    // ──────────────────────────────────────────
    // Storage
    // ──────────────────────────────────────────

    public static function get_all( int $user_id ): array {
        $raw = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        // Normalize each column (defensive in case of stored-shape drift).
        $out = [];
        foreach ( $raw as $col ) {
            if ( ! is_array( $col ) || empty( $col['id'] ) ) {
                continue;
            }
            $out[] = self::normalize_stored( $col );
        }
        return $out;
    }

    private static function normalize_stored( array $col ): array {
        return [
            'id'           => (string) $col['id'],
            'name'         => (string) ( $col['name'] ?? '' ),
            'description'  => (string) ( $col['description'] ?? '' ),
            'formula'      => (string) ( $col['formula'] ?? '' ),
            'fields_used'  => is_array( $col['fields_used'] ?? null ) ? array_values( $col['fields_used'] ) : [],
            'format'       => in_array( $col['format'] ?? '', self::valid_formats(), true ) ? $col['format'] : 'number',
            'decimals'     => max( 0, min( 6, (int) ( $col['decimals'] ?? 0 ) ) ),
            'color'        => in_array( $col['color'] ?? '', self::valid_colors(), true ) ? $col['color'] : '',
            'clamp_negative' => ! empty( $col['clamp_negative'] ),
            'treat_missing_zero' => ! empty( $col['treat_missing_zero'] ),
            'created_at'   => (string) ( $col['created_at'] ?? '' ),
            'updated_at'   => (string) ( $col['updated_at'] ?? '' ),
        ];
    }

    /**
     * Create or update a custom column. Returns the saved column on success,
     * or a WP_Error with a user-facing message on validation failure.
     *
     * @param int    $user_id
     * @param array  $input { id?, name, description, formula, format, decimals, color }
     * @return array|\WP_Error
     */
    public static function save( int $user_id, array $input ) {
        $name    = trim( (string) ( $input['name'] ?? '' ) );
        $formula = trim( (string) ( $input['formula'] ?? '' ) );

        if ( $name === '' ) {
            return new \WP_Error( 'name_required', 'Column name is required.' );
        }
        if ( mb_strlen( $name ) > 60 ) {
            return new \WP_Error( 'name_too_long', 'Column name must be 60 characters or fewer.' );
        }
        if ( $formula === '' ) {
            return new \WP_Error( 'formula_required', 'Formula is required.' );
        }

        $format = in_array( $input['format'] ?? '', self::valid_formats(), true ) ? $input['format'] : 'number';
        $color  = in_array( $input['color']  ?? '', self::valid_colors(),  true ) ? $input['color']  : '';
        $dec    = max( 0, min( 6, (int) ( $input['decimals'] ?? 0 ) ) );
        $clamp  = ! empty( $input['clamp_negative'] );
        $missing0 = ! empty( $input['treat_missing_zero'] );

        // Parse + validate the formula. Returns ['fields' => [keys...], 'normalized' => 'cleaned formula'].
        $parsed = self::parse_formula( $formula );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        $existing = self::get_all( $user_id );
        $id       = (string) ( $input['id'] ?? '' );

        // Uniqueness check on name (case-insensitive, excluding self).
        $name_lower = mb_strtolower( $name );
        foreach ( $existing as $row ) {
            if ( $row['id'] === $id ) {
                continue;
            }
            if ( mb_strtolower( $row['name'] ) === $name_lower ) {
                return new \WP_Error( 'name_taken', 'A custom column with this name already exists.' );
            }
        }

        $now = current_time( 'mysql' );

        if ( $id === '' ) {
            $id = 'cc_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
            $created_at = $now;
        } else {
            // Find existing for created_at preservation.
            $created_at = $now;
            foreach ( $existing as $row ) {
                if ( $row['id'] === $id ) {
                    $created_at = $row['created_at'] ?: $now;
                    break;
                }
            }
        }

        $saved = [
            'id'          => $id,
            'name'        => $name,
            'description' => trim( (string) ( $input['description'] ?? '' ) ),
            'formula'     => $parsed['normalized'],
            'fields_used' => array_values( $parsed['fields'] ),
            'format'      => $format,
            'decimals'    => $dec,
            'color'       => $color,
            'clamp_negative' => $clamp,
            'treat_missing_zero' => $missing0,
            'created_at'  => $created_at,
            'updated_at'  => $now,
        ];

        // Insert or replace.
        $replaced = false;
        foreach ( $existing as &$row ) {
            if ( $row['id'] === $id ) {
                $row      = $saved;
                $replaced = true;
                break;
            }
        }
        unset( $row );
        if ( ! $replaced ) {
            $existing[] = $saved;
        }

        update_user_meta( $user_id, self::META_KEY, $existing );

        return $saved;
    }

    /**
     * Persist the column order. Unknown ids are ignored; any existing
     * columns not in $ordered_ids are appended to the end in their
     * existing order (defensive against drift).
     */
    public static function reorder( int $user_id, array $ordered_ids ): void {
        $existing = self::get_all( $user_id );
        if ( ! $existing ) {
            return;
        }
        $by_id = [];
        foreach ( $existing as $row ) {
            $by_id[ $row['id'] ] = $row;
        }
        $reordered = [];
        $seen      = [];
        foreach ( $ordered_ids as $id ) {
            if ( isset( $by_id[ $id ] ) && ! isset( $seen[ $id ] ) ) {
                $reordered[] = $by_id[ $id ];
                $seen[ $id ] = true;
            }
        }
        // Append any not mentioned (defensive).
        foreach ( $existing as $row ) {
            if ( ! isset( $seen[ $row['id'] ] ) ) {
                $reordered[] = $row;
            }
        }
        update_user_meta( $user_id, self::META_KEY, $reordered );
    }

    public static function delete( int $user_id, string $id ): bool {
        $existing = self::get_all( $user_id );
        $kept     = array_values( array_filter( $existing, fn( $c ) => $c['id'] !== $id ) );
        if ( count( $kept ) === count( $existing ) ) {
            return false;
        }
        update_user_meta( $user_id, self::META_KEY, $kept );
        // Also strip the column from every campaign that had it enabled or overridden.
        self::strip_from_all_campaigns( $user_id, $id );
        self::strip_overrides_from_all_campaigns( $user_id, $id );
        return true;
    }

    // ──────────────────────────────────────────
    // Per-campaign assignment
    //
    // The library (above) is the user's master list of column definitions.
    // Which of those columns actually render on a given campaign is stored
    // separately, as a post-meta array of column ids on the campaign post.
    // ──────────────────────────────────────────

    private const ENABLED_META_KEY = '_optee_custom_columns_enabled';

    /**
     * Per-campaign formula overrides. Post-meta on the campaign:
     *   { column_id => { name, description, formula, fields_used, format, decimals, color } }
     * An override replaces the global library spec for that column ON THIS CAMPAIGN ONLY.
     */
    private const OVERRIDES_META_KEY = '_optee_custom_column_overrides';

    /**
     * Column ids enabled (assigned) for a given campaign.
     */
    public static function get_enabled_ids( int $campaign_id ): array {
        if ( $campaign_id <= 0 ) {
            return [];
        }
        $raw = get_post_meta( $campaign_id, self::ENABLED_META_KEY, true );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        return array_values( array_filter( array_map( 'strval', $raw ) ) );
    }

    /**
     * Replace the enabled-column list for a campaign. Ids are intersected
     * with the user's real library so stale ids never persist.
     */
    public static function set_enabled( int $campaign_id, int $user_id, array $ids ): void {
        if ( $campaign_id <= 0 ) {
            return;
        }
        $valid = [];
        foreach ( self::get_all( $user_id ) as $col ) {
            $valid[ $col['id'] ] = true;
        }
        $clean = [];
        foreach ( $ids as $id ) {
            $id = (string) $id;
            if ( isset( $valid[ $id ] ) && ! in_array( $id, $clean, true ) ) {
                $clean[] = $id;
            }
        }
        update_post_meta( $campaign_id, self::ENABLED_META_KEY, $clean );
    }

    /**
     * Toggle a single column's assignment on a campaign.
     */
    public static function assign( int $campaign_id, int $user_id, string $id, bool $on ): void {
        $ids = self::get_enabled_ids( $campaign_id );
        $has = in_array( $id, $ids, true );
        if ( $on && ! $has ) {
            $ids[] = $id;
        } elseif ( ! $on && $has ) {
            $ids = array_values( array_filter( $ids, fn( $x ) => $x !== $id ) );
        } else {
            return; // no change
        }
        self::set_enabled( $campaign_id, $user_id, $ids );
    }

    /**
     * Remove a column id from every one of the user's campaigns. Called on
     * delete so an assignment never dangles past its definition.
     */
    private static function strip_from_all_campaigns( int $user_id, string $id ): void {
        $campaigns = get_posts( [
            'post_type'      => 'optee_campaign',
            'post_status'    => 'any',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::ENABLED_META_KEY,
        ] );
        foreach ( $campaigns as $cid ) {
            $cid = (int) $cid;
            $ids = self::get_enabled_ids( $cid );
            if ( in_array( $id, $ids, true ) ) {
                $ids = array_values( array_filter( $ids, fn( $x ) => $x !== $id ) );
                update_post_meta( $cid, self::ENABLED_META_KEY, $ids );
            }
        }
    }

    /**
     * One-time migration: enable every existing column on every existing
     * campaign, so per-campaign filtering is backward-compatible (nothing
     * disappears on upgrade). Idempotent — campaigns that already have an
     * enabled-list (array) are left untouched, including the empty array
     * written here, so columns created later don't leak in retroactively.
     */
    public static function migrate_enable_all_existing(): void {
        $campaigns = get_posts( [
            'post_type'      => 'optee_campaign',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
        $library_by_author = [];
        foreach ( $campaigns as $cid ) {
            $cid = (int) $cid;
            if ( is_array( get_post_meta( $cid, self::ENABLED_META_KEY, true ) ) ) {
                continue; // already migrated / explicitly set
            }
            $author = (int) get_post_field( 'post_author', $cid );
            if ( ! isset( $library_by_author[ $author ] ) ) {
                $library_by_author[ $author ] = array_map(
                    fn( $c ) => $c['id'],
                    self::get_all( $author )
                );
            }
            update_post_meta( $cid, self::ENABLED_META_KEY, array_values( $library_by_author[ $author ] ) );
        }
    }

    // ──────────────────────────────────────────
    // Per-campaign formula overrides
    //
    // The library holds one global definition per column. A campaign may
    // override that column's spec (formula, name, format, …) for itself only,
    // stored as a post-meta map keyed by column id.
    // ──────────────────────────────────────────

    /**
     * All per-campaign overrides for a campaign: { column_id => spec }.
     */
    public static function get_overrides( int $campaign_id ): array {
        if ( $campaign_id <= 0 ) {
            return [];
        }
        $raw = get_post_meta( $campaign_id, self::OVERRIDES_META_KEY, true );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( $raw as $id => $spec ) {
            if ( is_array( $spec ) && ! empty( $spec['formula'] ) ) {
                $out[ (string) $id ] = $spec;
            }
        }
        return $out;
    }

    /**
     * Create or update a per-campaign override for column $id. Validates the
     * formula with the same parser as the global save. Returns the effective
     * column (base merged with override) on success, or WP_Error.
     *
     * @param array $input { name?, description?, formula, format?, decimals?, color? }
     * @return array|\WP_Error
     */
    public static function save_override( int $campaign_id, int $user_id, string $id, array $input ) {
        if ( $campaign_id <= 0 ) {
            return new \WP_Error( 'no_campaign', 'A campaign is required to save a per-campaign column.' );
        }
        $base = self::find( $user_id, $id );
        if ( ! $base ) {
            return new \WP_Error( 'not_found', 'Column not found in your library.' );
        }

        $name = trim( (string) ( $input['name'] ?? $base['name'] ) );
        if ( $name === '' ) {
            return new \WP_Error( 'name_required', 'Column name is required.' );
        }
        if ( mb_strlen( $name ) > 60 ) {
            return new \WP_Error( 'name_too_long', 'Column name must be 60 characters or fewer.' );
        }

        $formula = trim( (string) ( $input['formula'] ?? '' ) );
        if ( $formula === '' ) {
            return new \WP_Error( 'formula_required', 'Formula is required.' );
        }
        $parsed = self::parse_formula( $formula );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        $format = in_array( $input['format'] ?? '', self::valid_formats(), true ) ? $input['format'] : $base['format'];
        $color  = in_array( $input['color']  ?? '', self::valid_colors(),  true ) ? $input['color']  : $base['color'];
        $dec    = isset( $input['decimals'] ) ? max( 0, min( 6, (int) $input['decimals'] ) ) : (int) $base['decimals'];

        $clamp    = isset( $input['clamp_negative'] ) ? ! empty( $input['clamp_negative'] ) : ! empty( $base['clamp_negative'] );
        $missing0 = isset( $input['treat_missing_zero'] ) ? ! empty( $input['treat_missing_zero'] ) : ! empty( $base['treat_missing_zero'] );

        $spec = [
            'name'        => $name,
            'description' => trim( (string) ( $input['description'] ?? $base['description'] ) ),
            'formula'     => $parsed['normalized'],
            'fields_used' => array_values( $parsed['fields'] ),
            'format'      => $format,
            'decimals'    => $dec,
            'color'       => $color,
            'clamp_negative' => $clamp,
            'treat_missing_zero' => $missing0,
        ];

        $overrides         = self::get_overrides( $campaign_id );
        $overrides[ $id ]  = $spec;
        update_post_meta( $campaign_id, self::OVERRIDES_META_KEY, $overrides );

        // Effective = base def with the override spec applied (keeps id + created_at).
        return array_merge( $base, $spec, [ 'id' => $id, 'overridden' => true ] );
    }

    /**
     * Remove a campaign's override for a column (revert to the global formula).
     */
    public static function delete_override( int $campaign_id, string $id ): void {
        if ( $campaign_id <= 0 ) {
            return;
        }
        $overrides = self::get_overrides( $campaign_id );
        if ( isset( $overrides[ $id ] ) ) {
            unset( $overrides[ $id ] );
            update_post_meta( $campaign_id, self::OVERRIDES_META_KEY, $overrides );
        }
    }

    /**
     * The user's library with this campaign's overrides applied. Each column is
     * the global def, or (when overridden) the def merged with the override spec.
     */
    public static function get_effective_for_campaign( int $user_id, int $campaign_id ): array {
        $overrides = self::get_overrides( $campaign_id );
        $out       = [];
        foreach ( self::get_all( $user_id ) as $col ) {
            if ( isset( $overrides[ $col['id'] ] ) ) {
                $out[] = array_merge( $col, $overrides[ $col['id'] ], [ 'overridden' => true ] );
            } else {
                $out[] = $col;
            }
        }
        return $out;
    }

    /**
     * Find a single column for a campaign, override-aware (null if missing).
     * Used by the rule engine so automation matches what the report shows.
     */
    public static function find_effective( int $user_id, int $campaign_id, string $id ): ?array {
        $base = self::find( $user_id, $id );
        if ( ! $base ) {
            return null;
        }
        $overrides = self::get_overrides( $campaign_id );
        if ( isset( $overrides[ $id ] ) ) {
            return array_merge( $base, $overrides[ $id ], [ 'overridden' => true ] );
        }
        return $base;
    }

    /**
     * Remove a column id's override from every campaign that has one. Called on
     * global delete so an override never dangles past its definition.
     */
    private static function strip_overrides_from_all_campaigns( int $user_id, string $id ): void {
        $campaigns = get_posts( [
            'post_type'      => 'optee_campaign',
            'post_status'    => 'any',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::OVERRIDES_META_KEY,
        ] );
        foreach ( $campaigns as $cid ) {
            self::delete_override( (int) $cid, $id );
        }
    }

    // ──────────────────────────────────────────
    // Formula parser
    //
    // Grammar:
    //   expr   := term (('+' | '-') term)*
    //   term   := factor (('*' | '/') factor)*
    //   factor := number | token | '(' expr ')' | '-' factor
    //   token  := '[' GROUP ']' SPACE LABEL  (matched greedily against the token map)
    //
    // Returns ['fields' => [keys...], 'normalized' => 'cleaned formula'] on success,
    // or WP_Error on syntax / unknown-token failure.
    // ──────────────────────────────────────────

    public static function parse_formula( string $formula ) {
        $tokens = self::tokenize( $formula );
        if ( is_wp_error( $tokens ) ) {
            return $tokens;
        }
        if ( empty( $tokens ) ) {
            return new \WP_Error( 'formula_empty', 'Formula is empty.' );
        }

        $state = [ 'tokens' => $tokens, 'pos' => 0, 'fields' => [] ];
        $err   = self::parse_expr( $state );
        if ( is_wp_error( $err ) ) {
            return $err;
        }
        if ( $state['pos'] < count( $state['tokens'] ) ) {
            $bad = $state['tokens'][ $state['pos'] ]['raw'] ?? '';
            return new \WP_Error( 'syntax', sprintf( 'Unexpected token near "%s".', $bad ) );
        }

        // Build a normalized formula string (single spaces between tokens / operators).
        $parts = [];
        foreach ( $tokens as $t ) {
            $parts[] = $t['raw'];
        }
        $normalized = implode( ' ', $parts );
        // Collapse spaces around parens.
        $normalized = preg_replace( '/\(\s+/', '(', $normalized );
        $normalized = preg_replace( '/\s+\)/', ')', $normalized );

        return [
            'fields'     => array_values( array_unique( $state['fields'] ) ),
            'normalized' => $normalized,
        ];
    }

    /**
     * Tokenize a formula into a stream of { type, value?, raw } records.
     * Types: 'token' (field reference), 'number', 'op', 'lparen', 'rparen'.
     */
    private static function tokenize( string $formula ) {
        $map = self::get_token_map();
        // Sort token strings by length desc for greedy matching.
        $token_strings = array_keys( $map );
        usort( $token_strings, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

        $tokens = [];
        $len    = strlen( $formula );
        $i      = 0;

        while ( $i < $len ) {
            $ch = $formula[ $i ];

            // Whitespace.
            if ( ctype_space( $ch ) ) {
                $i++;
                continue;
            }

            // Operators.
            if ( $ch === '+' || $ch === '-' || $ch === '*' || $ch === '/' ) {
                $tokens[] = [ 'type' => 'op', 'value' => $ch, 'raw' => $ch ];
                $i++;
                continue;
            }
            // Unicode multiplication / division (× ÷) — convert to ASCII.
            if ( substr( $formula, $i, 2 ) === "\xC3\x97" ) { // ×
                $tokens[] = [ 'type' => 'op', 'value' => '*', 'raw' => '*' ];
                $i += 2;
                continue;
            }
            if ( substr( $formula, $i, 2 ) === "\xC3\xB7" ) { // ÷
                $tokens[] = [ 'type' => 'op', 'value' => '/', 'raw' => '/' ];
                $i += 2;
                continue;
            }

            // Parens.
            if ( $ch === '(' ) {
                $tokens[] = [ 'type' => 'lparen', 'raw' => '(' ];
                $i++;
                continue;
            }
            if ( $ch === ')' ) {
                $tokens[] = [ 'type' => 'rparen', 'raw' => ')' ];
                $i++;
                continue;
            }

            // Number literal (digits, optional decimal).
            if ( ctype_digit( $ch ) || ( $ch === '.' && $i + 1 < $len && ctype_digit( $formula[ $i + 1 ] ) ) ) {
                $start = $i;
                $has_dot = false;
                while ( $i < $len && ( ctype_digit( $formula[ $i ] ) || ( $formula[ $i ] === '.' && ! $has_dot ) ) ) {
                    if ( $formula[ $i ] === '.' ) {
                        $has_dot = true;
                    }
                    $i++;
                }
                $num = substr( $formula, $start, $i - $start );
                $tokens[] = [ 'type' => 'number', 'value' => (float) $num, 'raw' => $num ];
                continue;
            }

            // Field token — starts with '['.
            if ( $ch === '[' ) {
                $matched = null;
                foreach ( $token_strings as $candidate ) {
                    $clen = strlen( $candidate );
                    if ( substr( $formula, $i, $clen ) === $candidate ) {
                        $matched = $candidate;
                        break;
                    }
                }
                if ( $matched === null ) {
                    // Find closing bracket for error message.
                    $end = strpos( $formula, ']', $i );
                    $tail = $end === false ? substr( $formula, $i, 30 ) : substr( $formula, $i, $end - $i + 1 );
                    return new \WP_Error( 'unknown_token', sprintf( 'Unknown field "%s".', $tail ) );
                }
                $spec = $map[ $matched ];
                $tokens[] = [
                    'type'  => 'token',
                    'value' => $spec['key'],
                    'raw'   => $matched,
                    'spec'  => $spec,
                ];
                $i += strlen( $matched );
                continue;
            }

            return new \WP_Error( 'syntax', sprintf( 'Unexpected character "%s" at position %d.', $ch, $i ) );
        }

        return $tokens;
    }

    private static function parse_expr( array &$state ) {
        $err = self::parse_term( $state );
        if ( is_wp_error( $err ) ) {
            return $err;
        }
        while ( self::peek_op( $state, [ '+', '-' ] ) ) {
            $state['pos']++; // consume operator
            $err = self::parse_term( $state );
            if ( is_wp_error( $err ) ) {
                return $err;
            }
        }
        return true;
    }

    private static function parse_term( array &$state ) {
        $err = self::parse_factor( $state );
        if ( is_wp_error( $err ) ) {
            return $err;
        }
        while ( self::peek_op( $state, [ '*', '/' ] ) ) {
            $state['pos']++;
            $err = self::parse_factor( $state );
            if ( is_wp_error( $err ) ) {
                return $err;
            }
        }
        return true;
    }

    private static function parse_factor( array &$state ) {
        $tok = $state['tokens'][ $state['pos'] ] ?? null;
        if ( ! $tok ) {
            return new \WP_Error( 'syntax', 'Unexpected end of formula.' );
        }

        // Unary minus.
        if ( $tok['type'] === 'op' && $tok['value'] === '-' ) {
            $state['pos']++;
            return self::parse_factor( $state );
        }

        if ( $tok['type'] === 'number' ) {
            $state['pos']++;
            return true;
        }

        if ( $tok['type'] === 'token' ) {
            $state['fields'][] = $tok['value'];
            $state['pos']++;
            return true;
        }

        if ( $tok['type'] === 'lparen' ) {
            $state['pos']++;
            $err = self::parse_expr( $state );
            if ( is_wp_error( $err ) ) {
                return $err;
            }
            $next = $state['tokens'][ $state['pos'] ] ?? null;
            if ( ! $next || $next['type'] !== 'rparen' ) {
                return new \WP_Error( 'syntax', 'Missing closing parenthesis.' );
            }
            $state['pos']++;
            return true;
        }

        return new \WP_Error( 'syntax', sprintf( 'Unexpected token "%s".', $tok['raw'] ) );
    }

    private static function peek_op( array $state, array $ops ): bool {
        $tok = $state['tokens'][ $state['pos'] ] ?? null;
        return $tok && $tok['type'] === 'op' && in_array( $tok['value'], $ops, true );
    }

    // ──────────────────────────────────────────
    // PHP-side evaluator (mirrors the JS evalRPN in optee-campaign-stats.js).
    // Used by the rule engine to evaluate custom-column conditions.
    // ──────────────────────────────────────────

    /**
     * Evaluate a formula string against a data row.
     *
     * @param string $formula
     * @param array  $row  Field key → numeric value.
     * @return float|null  Numeric result, or null on missing data / div-by-zero.
     */
    public static function evaluate( string $formula, array $row, bool $missing_as_zero = false ): ?float {
        $tokens = self::tokenize( $formula );
        if ( is_wp_error( $tokens ) || empty( $tokens ) ) {
            return null;
        }
        $rpn = self::to_rpn( $tokens );
        return self::eval_rpn( $rpn, $row, $missing_as_zero );
    }

    /** Convert a token list to RPN via shunting-yard (with unary-minus handling). */
    private static function to_rpn( array $tokens ): array {
        $output = [];
        $stack  = [];
        $prec   = [ '+' => 1, '-' => 1, '*' => 2, '/' => 2 ];
        $prev   = null;

        foreach ( $tokens as $t ) {
            if ( $t['type'] === 'number' || $t['type'] === 'token' ) {
                $output[] = $t;
            } elseif ( $t['type'] === 'op' ) {
                // Unary minus: emit a literal 0 to turn it into binary subtraction.
                if ( $t['value'] === '-' && ( $prev === null || $prev === 'op' || $prev === 'lparen' ) ) {
                    $output[] = [ 'type' => 'number', 'value' => 0 ];
                }
                while ( ! empty( $stack ) && end( $stack )['type'] === 'op' &&
                    $prec[ end( $stack )['value'] ] >= $prec[ $t['value'] ] ) {
                    $output[] = array_pop( $stack );
                }
                $stack[] = $t;
            } elseif ( $t['type'] === 'lparen' ) {
                $stack[] = $t;
            } elseif ( $t['type'] === 'rparen' ) {
                while ( ! empty( $stack ) && end( $stack )['type'] !== 'lparen' ) {
                    $output[] = array_pop( $stack );
                }
                if ( ! empty( $stack ) ) {
                    array_pop( $stack ); // discard '('
                }
            }
            $prev = $t['type'];
        }
        while ( ! empty( $stack ) ) {
            $output[] = array_pop( $stack );
        }
        return $output;
    }

    private static function eval_rpn( array $rpn, array $row, bool $missing_as_zero = false ): ?float {
        $stack = [];
        foreach ( $rpn as $t ) {
            if ( $t['type'] === 'number' ) {
                $stack[] = (float) $t['value'];
            } elseif ( $t['type'] === 'token' ) {
                $v = $row[ $t['value'] ] ?? null;
                if ( $v === null || $v === '' || ! is_numeric( $v ) ) {
                    if ( ! $missing_as_zero ) {
                        return null;
                    }
                    $v = 0;
                }
                $stack[] = (float) $v;
            } elseif ( $t['type'] === 'op' ) {
                if ( count( $stack ) < 2 ) {
                    return null;
                }
                $b = array_pop( $stack );
                $a = array_pop( $stack );
                $r = null;
                switch ( $t['value'] ) {
                    case '+': $r = $a + $b; break;
                    case '-': $r = $a - $b; break;
                    case '*': $r = $a * $b; break;
                    case '/':
                        if ( $b == 0.0 ) return null;
                        $r = $a / $b;
                        break;
                }
                if ( $r === null || is_nan( $r ) || ! is_finite( $r ) ) {
                    return null;
                }
                $stack[] = $r;
            }
        }
        return empty( $stack ) ? null : (float) $stack[0];
    }

    /**
     * Resolve which underlying data sources are needed for a custom column
     * based on its fields_used (token internal keys).
     *
     * Returns: array of source slugs from {'traffic_source','antifraud','tracker'}.
     * Monetization keys exist in the token map but rule-engine zone fetchers
     * don't fetch monetization data, so they are intentionally not returned.
     */
    public static function sources_for_fields( array $field_keys ): array {
        $key_to_source = [];
        foreach ( self::get_token_map() as $spec ) {
            $key_to_source[ $spec['key'] ] = $spec['group'];
        }
        $sources = [];
        foreach ( $field_keys as $k ) {
            $g = $key_to_source[ $k ] ?? null;
            if ( $g === 'traffic_source' || $g === 'antifraud' || $g === 'tracker' ) {
                $sources[ $g ] = true;
            }
        }
        return array_keys( $sources );
    }

    /**
     * Find a stored custom column by id for a given user (null if missing).
     */
    public static function find( int $user_id, string $id ): ?array {
        foreach ( self::get_all( $user_id ) as $c ) {
            if ( $c['id'] === $id ) {
                return $c;
            }
        }
        return null;
    }
}
