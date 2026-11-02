<?php

namespace Optee\Plugin\Reports;

use Optee\Plugin\ModuleRegistry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Assembles the data for a campaign's optimization "timeline" report — the source
 * behind the printable Campaign Report / PDF.
 *
 * Design notes:
 *  - The heart of the report (rule timeline, manual actions, zones touched, rule
 *    list) comes entirely from the DB — {prefix}optee_logs and the optee_rule CPT —
 *    so it's always complete and never hits a tracker API.
 *  - Per-day performance, summary KPIs and the previous-period comparison are read
 *    from ALREADY-CACHED tracker stats (the same per-day transients the campaign
 *    page warms). We make NO live tracker calls here — that keeps report generation
 *    instant and never spends a rate-limit token (important for BeMob). Days/periods
 *    that aren't cached simply degrade gracefully.
 */
class CampaignReport {

    /** Metric key → short human label (ported from optee-rules.js). */
    private const METRIC_LABELS = [
        'impressions' => 'Impressions', 'clicks' => 'Clicks', 'conversions' => 'Conversions',
        'cost' => 'Cost', 'payout' => 'Payout', 'profit' => 'Profit', 'roi' => 'ROI',
        'cpm' => 'CPM', 'cpc' => 'CPC', 'cpa' => 'CPA', 'ctr' => 'CTR', 'cr' => 'CR',
        'af_visits' => 'AF Visits', 'valid_hits' => 'Valid Hits', 'valid_percent' => 'Valid %',
        'traffic_loss' => 'Traffic Loss %', 'proxy_percent' => 'Proxy %', 'junk_percent' => 'Junk %',
        'bot_percent' => 'Bot %', 'af_cost' => 'AF Cost',
        'tr_visits' => 'Visits', 'tr_clicks' => 'Clicks', 'tr_conv' => 'Conversions',
        'tr_conv_all' => 'All Conversions', 'tr_revenue' => 'Revenue', 'tr_cost' => 'Cost',
        'tr_profit' => 'Profit', 'tr_roi' => 'ROI', 'tr_ctr' => 'CTR', 'tr_cv' => 'CV',
        'tr_cpm' => 'CPM', 'tr_cpa' => 'CPA', 'tr_ap' => 'Avg Payout', 'offer_status' => 'Offer Status',
    ];

    private const ACTION_LABELS = [
        'block_zone' => 'Block zones', 'block_zones' => 'Block zones',
        'unblock_zone' => 'Unblock zones', 'unblock_zones' => 'Unblock zones',
        'pause_traffic' => 'Pause traffic', 'resume_traffic' => 'Resume traffic',
        'pause_campaign' => 'Pause campaign', 'start_campaign' => 'Start campaign',
    ];

    private const TIMEFRAME_LABELS = [
        'today' => 'Today', 'yesterday' => 'Yesterday', '24h' => '24h', '48h' => '48h',
        '3d' => '3 days', '7d' => '7 days', '14d' => '14 days', '30d' => '30 days',
        '90d' => '90 days', '180d' => '180 days',
    ];

    /**
     * Build the full report payload for a campaign over the given timeframe token
     * (e.g. '7d', '30d', 'today') — the same tokens the stats views use, so the
     * report reads the very cache the Days view warmed.
     *
     * @return array|\WP_Error
     */
    public static function build( int $campaign_id, string $timeframe = '7d' ) {
        $campaign = get_post( $campaign_id );
        $user_id  = get_current_user_id();

        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== $user_id ) {
            return new \WP_Error( 'not_found', 'Campaign not found.' );
        }

        [ $from_date, $to_date ] = self::resolve_timeframe( $timeframe );
        $days = (int) round( ( strtotime( $to_date ) - strtotime( $from_date ) ) / DAY_IN_SECONDS ) + 1;

        $ts_slug = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $tr_slug = get_post_meta( $campaign_id, '_optee_tracker_module', true );

        // ── Stats: read the SAME cache the Days view warms (cache-only, no live calls) ──
        $stats = self::load_cached_stats( $user_id, $campaign_id, $ts_slug, $tr_slug, $from_date, $to_date );

        // ── Logs → timeline / counts / zones ─────────────────────────────────
        $logs  = self::query_logs( $user_id, $campaign_id, $from_date );
        $parsed = self::parse_logs( $logs, $stats['zone_window'], $user_id );

        // ── Rules attached ───────────────────────────────────────────────────
        $rules = self::load_rules( $campaign_id, $user_id, $parsed['rule_stats'] );

        // ── Summary + previous period ────────────────────────────────────────
        $summary  = $stats['summary'];
        $prev_to  = wp_date( 'Y-m-d', strtotime( '-1 day', strtotime( $from_date ) ) );
        $prev_from = wp_date( 'Y-m-d', strtotime( "-" . ( $days - 1 ) . " days", strtotime( $prev_to ) ) );
        // Previous period is cache-only — don't spend extra live calls on it.
        $prev     = self::load_cached_stats( $user_id, $campaign_id, $ts_slug, $tr_slug, $prev_from, $prev_to, false )['summary'];

        // ── Estimated savings (blocked losers) ───────────────────────────────
        $savings = self::compute_savings( $parsed['zones'], $days );
        $tz_now  = current_time( 'timestamp' );

        return [
            'campaign' => [
                'id'             => $campaign_id,
                'title'          => self::dec( $campaign->post_title ),
                'traffic_source' => self::pretty_slug( $ts_slug ),
                'tracker'        => self::pretty_slug( $tr_slug ),
                'status'         => self::campaign_status( $campaign_id ),
            ],
            'range' => [
                'days'  => $days,
                'from'  => $from_date,
                'to'    => $to_date,
                'label' => self::range_label( $from_date, $to_date ),
            ],
            'generated'        => wp_date( 'Y-m-d H:i', $tz_now ),
            'summary'          => $summary,
            'prev'             => $prev,
            'counts'           => $parsed['counts'],
            'perday'           => $stats['perday'],
            'perday_pending'   => $stats['pending'],
            'stats_available'  => $stats['available'],
            'rules'            => $rules,
            'timeline'         => $parsed['timeline'],
            'zones'            => array_values( $parsed['zones'] ),
            'savings'          => $savings,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Stats (cache-only)
    // ─────────────────────────────────────────────────────────────────────────

    /** Same cache key format as AjaxHandler::stats_cache_key(). */
    private static function stats_cache_key( int $user_id, int $campaign_id, string $module_slug, string $drilldown, string $date_from, string $date_to ): string {
        return 'optee_stats_' . md5( "{$user_id}_{$campaign_id}_{$module_slug}_{$drilldown}_{$date_from}_{$date_to}" );
    }

    /**
     * Read the cached stats the campaign's Days view warms — the single 'days'
     * drilldown transient per module for the whole [from, to] range — and merge
     * traffic-source spend with tracker revenue into per-day rows. No live calls.
     * Also reads the 'default' (zones) cache for per-zone window figures used by
     * the timeline "why" and the zones-touched table (best-effort).
     *
     * @return array{perday: array, summary: ?array, zone_window: array, available: bool, pending: int}
     */
    private static function load_cached_stats( int $user_id, int $campaign_id, string $ts_slug, string $tr_slug, string $from, string $to, bool $allow_live = true ): array {
        // Per-day: merge traffic-source (impressions/clicks/cost) + tracker
        // (conversions/revenue) from each module's 'days' data — cache-first, with a
        // single live 'days' call per module on a miss (server-side date grouping =
        // one request, not one-per-day, so it's rate-limit-safe).
        $ts_id   = (string) get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );
        $tr_id   = (string) get_post_meta( $campaign_id, '_optee_tracker_campaign_id', true );
        $ts_days = self::fetch_days( $user_id, $campaign_id, 'traffic_source', $ts_slug, $ts_id, $from, $to, $allow_live );
        $tr_days = self::fetch_days( $user_id, $campaign_id, 'tracker', $tr_slug, $tr_id, $from, $to, $allow_live );

        $by = self::merge_rows( $ts_days, $tr_days, 'date' );

        $perday = [];
        $tot    = [ 'impr' => 0.0, 'clicks' => 0.0, 'cost' => 0.0, 'conv' => 0.0, 'revenue' => 0.0 ];
        foreach ( $by as $date => $m ) {
            $perday[] = [
                'date'    => $date,
                'label'   => wp_date( 'M j', strtotime( $date ) ),
                'impr'    => (int) $m['impr'],
                'clicks'  => (int) $m['clicks'],
                'cost'    => $m['cost'],
                'conv'    => (int) $m['conv'],
                'revenue' => $m['revenue'],
                'profit'  => $m['revenue'] - $m['cost'],
                'roi'     => self::roi( $m['revenue'], $m['cost'] ),
            ];
            foreach ( $tot as $k => $_ ) {
                $tot[ $k ] += $m[ $k ];
            }
        }
        usort( $perday, static fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

        $available = ! empty( $perday );
        $summary   = $available ? [
            'cost'        => $tot['cost'],
            'revenue'     => $tot['revenue'],
            'profit'      => $tot['revenue'] - $tot['cost'],
            'roi'         => self::roi( $tot['revenue'], $tot['cost'] ),
            'conversions' => (int) $tot['conv'],
        ] : null;

        // Per-zone window (whole range) from the 'default' zones drilldown — cache
        // first, then a single rate-limit-safe live call on a miss (same one-call
        // pattern as fetch_days). Without the live fallback, long ranges the user
        // never opened in the zones view (e.g. 60d) rendered the whole "zones
        // changed" table as dashes.
        $ts_def = self::fetch_zones( $user_id, $campaign_id, 'traffic_source', $ts_slug, $ts_id, $from, $to, $allow_live );
        $tr_def = self::fetch_zones( $user_id, $campaign_id, 'tracker', $tr_slug, $tr_id, $from, $to, $allow_live );
        $zone_window = [];
        foreach ( self::merge_rows( $ts_def, $tr_def, 'zone_id' ) as $zid => $m ) {
            $zone_window[ $zid ] = [
                'cost'    => $m['cost'],
                'conv'    => (int) $m['conv'],
                'revenue' => $m['revenue'],
                'profit'  => $m['revenue'] - $m['cost'],
                'roi'     => self::roi( $m['revenue'], $m['cost'] ),
            ];
        }

        return [
            'perday'      => $perday,
            'summary'     => $summary,
            'zone_window' => $zone_window,
            'available'   => $available,
            'pending'     => 0,
        ];
    }

    /**
     * Return a module's per-day rows for [from, to]: cache-first, then a single
     * live 'days' call on a miss (unless $allow_live is false, e.g. for the
     * previous-period comparison). Skips the live call while the automation cron
     * holds the lock, so it never competes for a rate-limited tracker's budget.
     * Failures degrade to [] — the report just omits stats rather than erroring.
     */
    private static function fetch_days( int $user_id, int $campaign_id, string $type, string $slug, string $remote_id, string $from, string $to, bool $allow_live ): array {
        if ( ! $slug || ! $remote_id ) {
            return [];
        }
        $key    = self::stats_cache_key( $user_id, $campaign_id, $slug, 'days', $from, $to );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        if ( ! $allow_live || get_transient( 'optee_automation_lock' ) !== false ) {
            return [];
        }

        $module = ModuleRegistry::get_instance()->get_module( $slug );
        if ( ! $module || ! method_exists( $module, 'get_statistics' ) ) {
            return [];
        }
        try {
            $module->set_credentials( ModuleRegistry::get_stored_credentials( $type, $slug ) ?: [] );
            $rows = $module->get_statistics( $remote_id, $from, $to, 'days' );
            $rows = is_array( $rows ) ? $rows : [];
            $ttl  = ( metadata_exists( 'user', $user_id, 'optee_cache_ttl' ) ? (int) get_user_meta( $user_id, 'optee_cache_ttl', true ) : 5 ) * 60;
            set_transient( $key, $rows, max( 60, $ttl ) );
            return $rows;
        } catch ( \Throwable $e ) {
            do_action( 'optee_debug_event', 'report.days_fetch_failed', [ 'slug' => $slug, 'error' => $e->getMessage() ] );
            return [];
        }
    }

    /**
     * Return a module's per-zone rows for [from, to] (keyed by 'zone_id' for merge):
     * cache-first from the 'default' zones transient the campaign's Days ▸ Zones view
     * warms, then a single live call on a miss — the same rate-limit-safe, one-call
     * pattern as fetch_days(), guarded by the automation lock. The tracker groups by
     * the campaign's configured zone field (_optee_zone_id) while the cache key keeps
     * the 'default' token, exactly matching how AjaxHandler warms this key.
     */
    private static function fetch_zones( int $user_id, int $campaign_id, string $type, string $slug, string $remote_id, string $from, string $to, bool $allow_live ): array {
        if ( ! $slug || ! $remote_id ) {
            return [];
        }
        $key    = self::stats_cache_key( $user_id, $campaign_id, $slug, 'default', $from, $to );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        if ( ! $allow_live || get_transient( 'optee_automation_lock' ) !== false ) {
            return [];
        }

        $module = ModuleRegistry::get_instance()->get_module( $slug );
        if ( ! $module || ! method_exists( $module, 'get_statistics' ) ) {
            return [];
        }
        // Traffic source groups by the bare 'default' zone drilldown; the tracker
        // groups by the campaign's zone field so its rows carry matching zone_ids.
        $drilldown = 'default';
        if ( $type === 'tracker' ) {
            $zone_field = (string) get_post_meta( $campaign_id, '_optee_zone_id', true );
            if ( $zone_field !== '' ) {
                $drilldown = $zone_field;
            }
        }
        try {
            $module->set_credentials( ModuleRegistry::get_stored_credentials( $type, $slug ) ?: [] );
            $rows = $module->get_statistics( $remote_id, $from, $to, $drilldown );
            $rows = is_array( $rows ) ? $rows : [];
            $ttl  = ( metadata_exists( 'user', $user_id, 'optee_cache_ttl' ) ? (int) get_user_meta( $user_id, 'optee_cache_ttl', true ) : 5 ) * 60;
            set_transient( $key, $rows, max( 60, $ttl ) );
            return $rows;
        } catch ( \Throwable $e ) {
            do_action( 'optee_debug_event', 'report.zones_fetch_failed', [ 'slug' => $slug, 'error' => $e->getMessage() ] );
            return [];
        }
    }

    /**
     * Merge traffic-source rows (bare keys: impressions/clicks/cost) with tracker
     * rows (tr_conv/tr_revenue, and tr_visits/tr_clicks/tr_cost as fallback when
     * there's no traffic source) into a map keyed by $key_field ('date' or 'zone_id').
     */
    private static function merge_rows( array $ts_rows, array $tr_rows, string $key_field ): array {
        $by = [];
        $ensure = static function ( array &$by, string $k ): void {
            if ( ! isset( $by[ $k ] ) ) {
                $by[ $k ] = [ 'impr' => 0.0, 'clicks' => 0.0, 'cost' => 0.0, 'conv' => 0.0, 'revenue' => 0.0, 'has_ts' => false,
                              'tr_impr' => 0.0, 'tr_clicks' => 0.0, 'tr_cost' => 0.0 ];
            }
        };

        foreach ( $ts_rows as $r ) {
            $k = (string) ( $r[ $key_field ] ?? '' );
            if ( $k === '' ) { continue; }
            $ensure( $by, $k );
            $by[ $k ]['impr']   += (float) ( $r['impressions'] ?? 0 );
            $by[ $k ]['clicks'] += (float) ( $r['clicks'] ?? 0 );
            $by[ $k ]['cost']   += (float) ( $r['cost'] ?? 0 );
            $by[ $k ]['has_ts']  = true;
        }
        foreach ( $tr_rows as $r ) {
            $k = (string) ( $r[ $key_field ] ?? '' );
            if ( $k === '' ) { continue; }
            $ensure( $by, $k );
            $by[ $k ]['conv']      += (float) ( $r['tr_conv'] ?? 0 );
            $by[ $k ]['revenue']   += (float) ( $r['tr_revenue'] ?? 0 );
            $by[ $k ]['tr_impr']   += (float) ( $r['tr_visits'] ?? 0 );
            $by[ $k ]['tr_clicks'] += (float) ( $r['tr_clicks'] ?? 0 );
            $by[ $k ]['tr_cost']   += (float) ( $r['tr_cost'] ?? 0 );
        }
        // When there's no traffic source, fall back to tracker figures for impr/clicks/cost.
        foreach ( $by as $k => $m ) {
            if ( ! $m['has_ts'] ) {
                $by[ $k ]['impr']   = $m['tr_impr'];
                $by[ $k ]['clicks'] = $m['tr_clicks'];
                $by[ $k ]['cost']   = $m['tr_cost'];
            }
        }
        return $by;
    }

    /**
     * Resolve a timeframe token to [from, to] dates — mirrors
     * AjaxHandler::resolve_timeframe() so the report's cache keys match the
     * stats views'. 'custom' isn't expected here (the tile converts it to Nd).
     *
     * @return array{0:string,1:string}
     */
    private static function resolve_timeframe( string $tf ): array {
        $today = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
        $mk    = static fn( int $back ) => wp_date( 'Y-m-d', strtotime( "-{$back} days", strtotime( $today ) ) );

        switch ( $tf ) {
            case 'today':      return [ $today, $today ];
            case 'yesterday':  return [ $mk( 1 ), $mk( 1 ) ];
            case '3d':         return [ $mk( 2 ), $today ];
            case '7d':         return [ $mk( 6 ), $today ];
            case '14d':        return [ $mk( 13 ), $today ];
            case '30d':        return [ $mk( 29 ), $today ];
            case '60d':        return [ $mk( 59 ), $today ];
            case '90d':        return [ $mk( 89 ), $today ];
            case '180d':       return [ $mk( 179 ), $today ];
            case '360d':       return [ $mk( 359 ), $today ];
            case 'this_month': return [ wp_date( 'Y-m-01', strtotime( $today ) ), $today ];
            case 'last_month':
                $first = wp_date( 'Y-m-01', strtotime( 'first day of last month', strtotime( $today ) ) );
                return [ $first, wp_date( 'Y-m-t', strtotime( $first ) ) ];
        }
        if ( preg_match( '/^(\d+)d$/', $tf, $m ) ) {
            return [ $mk( max( 0, (int) $m[1] - 1 ) ), $today ];
        }
        return [ $mk( 6 ), $today ]; // default 7d
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Logs → timeline / counts / zones
    // ─────────────────────────────────────────────────────────────────────────

    private static function query_logs( int $user_id, int $campaign_id, string $from_date ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'optee_logs';

        // created_at is WP local time (current_time('mysql')), so compare against the local from-date.
        // Pre-filter to this campaign in SQL: without it we'd load EVERY rule/campaign log
        // row for the user (each rule-eval row carries a large context blob) and narrow in
        // PHP — enough to exhaust PHP memory on active accounts. campaign_id lives in the
        // context JSON as "campaign_id":123 or "campaign_id":"123"; the LIKE is a coarse
        // pre-filter and the exact (int) check below stays authoritative.
        $like_num_comma = '%"campaign_id":' . (int) $campaign_id . ',%';
        $like_num_close = '%"campaign_id":' . (int) $campaign_id . '}%';
        $like_str       = '%"campaign_id":"' . (int) $campaign_id . '"%';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT log_type, category, message, context, created_at
             FROM {$table}
             WHERE user_id = %d
               AND category IN ('rule','campaign')
               AND created_at >= %s
               AND ( context LIKE %s OR context LIKE %s OR context LIKE %s )
             ORDER BY created_at ASC, id ASC",
            $user_id,
            $from_date . ' 00:00:00',
            $like_num_comma,
            $like_num_close,
            $like_str
        ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $ctx = $r['context'] ? json_decode( $r['context'], true ) : [];
            if ( ! is_array( $ctx ) ) {
                $ctx = [];
            }
            if ( (int) ( $ctx['campaign_id'] ?? 0 ) !== $campaign_id ) {
                continue;
            }
            $r['ctx'] = $ctx;
            $out[]    = $r;
        }
        return $out;
    }

    /**
     * Turn the raw log rows into timeline events, counts, per-zone touch records
     * and per-rule run stats.
     */
    private static function parse_logs( array $logs, array $zone_window, int $user_id ): array {
        $timeline    = [];
        $zones       = [];   // zone_id => record
        $rule_stats  = [];   // rule_id => ['runs'=>int,'blocked'=>int]
        $auto_blocks = 0;
        $manual      = 0;
        $rule_runs   = 0;

        $eval_buffer = []; // rule_id => [ zone_id => conditions[] ] (why source, flushed per run)

        foreach ( $logs as $log ) {
            $ctx   = $log['ctx'];
            $when  = $log['created_at'];
            $msg   = $log['message'];

            // ── Manual zone action (category campaign) ──
            if ( $log['category'] === 'campaign' && isset( $ctx['zone_id'], $ctx['action'] )
                 && in_array( $ctx['action'], [ 'block', 'unblock' ], true ) ) {
                $manual++;
                $zid    = (string) $ctx['zone_id'];
                $is_blk = $ctx['action'] === 'block';
                $why    = self::zone_window_why( $zid, $zone_window );

                $timeline[] = [
                    'ts'       => $when,
                    'ts_label' => self::ts_label( $when ),
                    'group'    => 'manual',
                    'badge'    => 'Manual · You',
                    'emoji'    => $is_blk ? '🚫' : '▶️',
                    'title'    => 'You ' . ( $is_blk ? 'blocked' : 'unblocked' ) . ' zone <span class="mono">' . esc_html( $zid ) . '</span>',
                    'zones'    => $why !== '' ? [ [ 'zid' => $zid, 'why' => $why ] ] : [],
                    'note'     => $why === '' ? 'Zone performance not cached — open the campaign\'s Days view to include it.' : '',
                ];

                self::touch_zone( $zones, $zid, $is_blk ? 'Manual (you)' : 'Manual → unblock', 'man', $when, $is_blk, $zone_window );
                continue;
            }

            if ( $log['category'] !== 'rule' ) {
                continue;
            }

            // ── Per-zone evaluation debug rows: buffer as the "why" source. ──
            if ( isset( $ctx['zone_id'], $ctx['conditions'] ) && is_array( $ctx['conditions'] ) ) {
                $rid = (string) ( $ctx['rule_id'] ?? '' );
                $eval_buffer[ $rid ][ (string) $ctx['zone_id'] ] = $ctx['conditions'];
                continue;
            }

            $rid = (string) ( $ctx['rule_id'] ?? '' );

            // ── Zone action result (block/unblock zones) ──
            if ( isset( $ctx['action'], $ctx['matching_zones'] ) && is_array( $ctx['matching_zones'] ) ) {
                $rule_runs++;
                self::bump( $rule_stats, $rid, 'runs' );

                $action = (string) $ctx['action'];
                $mzones = array_values( array_filter( array_map( 'strval', $ctx['matching_zones'] ) ) );
                $is_blk = in_array( $action, [ 'block_zone', 'block_zones' ], true );
                $count  = count( $mzones );

                if ( $count > 0 ) {
                    $rule_name = self::rule_name_from_msg( $msg );
                    $zrows     = [];
                    foreach ( $mzones as $zid ) {
                        $conds = $eval_buffer[ $rid ][ $zid ] ?? [];
                        $zrows[] = [ 'zid' => $zid, 'why' => self::why_from_conditions( $conds, $user_id ) ];
                        self::touch_zone( $zones, $zid, $is_blk ? 'Rule (auto)' : 'Rule → unblock', 'auto', $when, $is_blk, $zone_window );
                    }
                    if ( $is_blk ) {
                        $auto_blocks += $count;
                        self::bump( $rule_stats, $rid, 'blocked', $count );
                    }

                    $timeline[] = [
                        'ts'       => $when,
                        'ts_label' => self::ts_label( $when ),
                        'group'    => 'auto',
                        'badge'    => 'Auto · Rule',
                        'emoji'    => $is_blk ? '🚫' : '▶️',
                        'title'    => 'Rule <span class="rule">' . esc_html( $rule_name ) . '</span> ' . ( $is_blk ? 'blocked' : 'unblocked' ) . ' ' . $count . ' zone' . ( $count === 1 ? '' : 's' ),
                        'zones'    => $zrows,
                        'note'     => '',
                    ];
                }

                unset( $eval_buffer[ $rid ] );
                continue;
            }

            // ── Non-zone action result (pause/resume/start) ──
            if ( isset( $ctx['action'] ) && is_string( $ctx['action'] ) ) {
                $rule_runs++;
                self::bump( $rule_stats, $rid, 'runs' );
                $rule_name = self::rule_name_from_msg( $msg );
                $timeline[] = [
                    'ts'       => $when,
                    'ts_label' => self::ts_label( $when ),
                    'group'    => 'auto',
                    'badge'    => 'Auto · Rule',
                    'emoji'    => in_array( $ctx['action'], [ 'pause_campaign', 'pause_traffic' ], true ) ? '⏸️' : '▶️',
                    'title'    => 'Rule <span class="rule">' . esc_html( $rule_name ) . '</span> — ' . esc_html( self::ACTION_LABELS[ $ctx['action'] ] ?? $ctx['action'] ),
                    'zones'    => [],
                    'note'     => '',
                ];
                unset( $eval_buffer[ $rid ] );
                continue;
            }

            // ── "No zones matched" / "conditions not met" → no-action run ──
            if ( stripos( $msg, 'no zones matched' ) !== false || stripos( $msg, 'conditions not met' ) !== false ) {
                $rule_runs++;
                self::bump( $rule_stats, $rid, 'runs' );
                $rule_name = self::rule_name_from_msg( $msg );
                $timeline[] = [
                    'ts'       => $when,
                    'ts_label' => self::ts_label( $when ),
                    'group'    => 'noop',
                    'badge'    => 'Auto · No action',
                    'emoji'    => '💤',
                    'title'    => 'Rule <span class="rule">' . esc_html( $rule_name ) . '</span> ran — conditions not met, nothing changed',
                    'zones'    => [],
                    'note'     => '',
                ];
                unset( $eval_buffer[ $rid ] );
                continue;
            }
        }

        // Newest first.
        usort( $timeline, static fn( $a, $b ) => strcmp( $b['ts'], $a['ts'] ) );

        return [
            'timeline'   => $timeline,
            'zones'      => $zones,
            'rule_stats' => $rule_stats,
            'counts'     => [
                'auto_blocks'    => $auto_blocks,
                'manual_actions' => $manual,
                'rule_runs'      => $rule_runs,
            ],
        ];
    }

    /** Record/refresh a zone in the "zones touched" map. */
    private static function touch_zone( array &$zones, string $zid, string $by_label, string $by_class, string $when, bool $is_block, array $zone_window ): void {
        $w = $zone_window[ $zid ] ?? null;
        if ( ! isset( $zones[ $zid ] ) ) {
            $zones[ $zid ] = [
                'zid'           => $zid,
                'changed_by'    => $by_label,
                'changed_class' => $by_class,
                'when'          => wp_date( 'M j', strtotime( $when ) ),
                'ts'            => $when,
                'blocked'       => $is_block,
                'cost'          => $w['cost'] ?? null,
                'conv'          => $w['conv'] ?? null,
                'revenue'       => $w['revenue'] ?? null,
                'profit'        => $w['profit'] ?? null,
                'roi'           => $w['roi'] ?? null,
            ];
            return;
        }
        // Later change wins for status/when; note a mixed source.
        $rec = &$zones[ $zid ];
        if ( strcmp( $when, $rec['ts'] ) >= 0 ) {
            $rec['ts']      = $when;
            $rec['when']    = wp_date( 'M j', strtotime( $when ) );
            $rec['blocked'] = $is_block;
            if ( strpos( $rec['changed_by'], $by_label ) === false ) {
                $rec['changed_by']    = 'Rule / manual';
                $rec['changed_class'] = 'mix';
            }
        }
    }

    private static function bump( array &$map, string $key, string $field, int $by = 1 ): void {
        if ( ! isset( $map[ $key ] ) ) {
            $map[ $key ] = [ 'runs' => 0, 'blocked' => 0 ];
        }
        $map[ $key ][ $field ] += $by;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rules attached
    // ─────────────────────────────────────────────────────────────────────────

    private static function load_rules( int $campaign_id, int $user_id, array $rule_stats ): array {
        $posts = get_posts( [
            'post_type'   => 'optee_rule',
            'post_status' => 'any',
            'numberposts' => -1,
            'author'      => $user_id,
            'meta_key'    => '_optee_rule_campaign_id',
            'meta_value'  => $campaign_id,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ] );

        $out = [];
        foreach ( $posts as $p ) {
            $action     = get_post_meta( $p->ID, '_optee_rule_action', true );
            $conditions = get_post_meta( $p->ID, '_optee_rule_conditions', true ) ?: [];
            $timeframe  = get_post_meta( $p->ID, '_optee_rule_timeframe', true ) ?: '7d';
            $interval   = (int) get_post_meta( $p->ID, '_optee_rule_interval', true );
            $enabled    = get_post_meta( $p->ID, '_optee_rule_enabled', true ) !== '0';

            $conds = [];
            foreach ( (array) $conditions as $c ) {
                $metric = $c['metric'] ?? '';
                $conds[] = [
                    'metric' => self::metric_label( $metric, $user_id ),
                    'op'     => (string) ( $c['operator'] ?? '>' ),
                    'value'  => self::format_metric( $metric, (float) ( $c['value'] ?? 0 ), $user_id ),
                ];
            }

            $st = $rule_stats[ (string) $p->ID ] ?? [ 'runs' => 0, 'blocked' => 0 ];

            $out[] = [
                'title'        => self::dec( $p->post_title ),
                'enabled'      => $enabled,
                'action'       => self::ACTION_LABELS[ $action ] ?? $action,
                'action_emoji' => in_array( $action, [ 'block_zone', 'block_zones' ], true ) ? '🚫'
                                    : ( in_array( $action, [ 'pause_campaign', 'pause_traffic' ], true ) ? '⏸️' : '▶️' ),
                'conditions'   => $conds,
                'timeframe'    => self::TIMEFRAME_LABELS[ $timeframe ] ?? $timeframe,
                'interval'     => self::human_interval( $interval ),
                'runs'         => (int) $st['runs'],
                'blocked'      => (int) $st['blocked'],
            ];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Savings
    // ─────────────────────────────────────────────────────────────────────────

    private static function compute_savings( array $zones, int $days ): ?array {
        $removed = 0.0;
        $n       = 0;
        foreach ( $zones as $z ) {
            if ( ! empty( $z['blocked'] ) && $z['profit'] !== null && $z['profit'] < 0 ) {
                $removed += abs( (float) $z['profit'] );
                $n++;
            }
        }
        if ( $n === 0 || $removed <= 0 ) {
            return null;
        }
        return [
            'removed'     => $removed,
            'zone_count'  => $n,
            'avg'         => $removed / $n,
            'monthly_est' => $removed / max( 1, $days ) * 30,
            'days'        => $days,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Reconstruct a zone's "why" from the rule's per-zone evaluation strings. */
    private static function why_from_conditions( array $conditions, int $user_id ): string {
        if ( empty( $conditions ) ) {
            return '';
        }
        $chips = [];
        foreach ( $conditions as $c ) {
            // Format: "tr_cost > 10 (actual: 15.3) → pass"
            if ( ! preg_match( '/^(\S+)\s+([<>=!]+)\s+([\d.\-]+)\s+\(actual:\s*([^)]*)\)\s*(?:→|->)\s*(pass|fail)/u', (string) $c, $m ) ) {
                continue;
            }
            $metric = $m[1];
            $label  = self::metric_label( $metric, $user_id );
            $actual = is_numeric( $m[4] ) ? self::format_metric( $metric, (float) $m[4], $user_id ) : esc_html( $m[4] );
            $thresh = self::format_metric( $metric, (float) $m[3], $user_id );
            $mark   = $m[5] === 'pass' ? '✓' : '✗';
            $chips[] = '<b>' . esc_html( $label ) . ' ' . $actual . '</b> ' . esc_html( $m[2] ) . ' ' . $thresh . ' ' . $mark;
        }
        return implode( ' &nbsp;·&nbsp; ', $chips );
    }

    private static function zone_window_why( string $zid, array $zone_window ): string {
        if ( ! isset( $zone_window[ $zid ] ) ) {
            return '';
        }
        $w = $zone_window[ $zid ];
        return 'Tracker (period): <b>' . self::money( $w['cost'] ) . '</b> spend, ' . (int) $w['conv']
             . ' conv, ROI <b>' . self::pct( $w['roi'] ) . '</b>.';
    }

    private static function rule_name_from_msg( string $msg ): string {
        if ( preg_match( '/Rule\s+"([^"]+)"/', $msg, $m ) ) {
            return self::dec( $m[1] );
        }
        return 'rule';
    }

    /**
     * Decode HTML entities in stored titles (WP stores e.g. "ROI &lt; 0" with the
     * entity) so a later esc_html() renders "<" instead of double-encoding to "&lt;".
     */
    private static function dec( string $s ): string {
        return html_entity_decode( $s, ENT_QUOTES, 'UTF-8' );
    }

    private static function ts_label( string $mysql ): string {
        $t = strtotime( $mysql );
        return wp_date( 'M j · H:i', $t );
    }

    private static function roi( float $revenue, float $cost ): float {
        return $cost > 0 ? ( ( $revenue - $cost ) / $cost ) * 100 : 0.0;
    }

    public static function money( float $v ): string {
        return '$' . number_format( $v, 2 );
    }

    public static function signed_money( float $v ): string {
        return ( $v >= 0 ? '+' : '−' ) . '$' . number_format( abs( $v ), 2 );
    }

    public static function pct( float $v ): string {
        return ( $v >= 0 ? '+' : '−' ) . number_format( abs( $v ), 1 ) . '%';
    }

    /** Per-request cache of resolved custom columns (user|id => col|null). */
    private static $cc_cache = [];

    /** Resolve a cc__<id> metric to its saved custom column, or null. */
    private static function custom_col( int $user_id, string $metric ): ?array {
        if ( strpos( $metric, 'cc__' ) !== 0 ) {
            return null;
        }
        $key = $user_id . '|' . substr( $metric, 4 );
        if ( ! array_key_exists( $key, self::$cc_cache ) ) {
            $col = \Optee\Plugin\CustomColumns::find( $user_id, substr( $metric, 4 ) );
            self::$cc_cache[ $key ] = is_array( $col ) ? $col : null;
        }
        return self::$cc_cache[ $key ];
    }

    /** Human label for a metric key, resolving custom columns to their name. */
    private static function metric_label( string $metric, int $user_id ): string {
        $cc = self::custom_col( $user_id, $metric );
        if ( $cc ) {
            return $cc['name'] !== '' ? $cc['name'] : $metric;
        }
        return self::METRIC_LABELS[ $metric ] ?? $metric;
    }

    private static function format_metric( string $metric, float $v, int $user_id = 0 ): string {
        $cc = $user_id ? self::custom_col( $user_id, $metric ) : null;
        if ( $cc ) {
            $dec = (int) ( $cc['decimals'] ?? 0 );
            if ( ( $cc['format'] ?? '' ) === 'money' ) {
                return '$' . number_format( $v, $dec ?: 2 );
            }
            if ( ( $cc['format'] ?? '' ) === 'percent' ) {
                return number_format( $v, $dec ?: 1 ) . '%';
            }
            return rtrim( rtrim( number_format( $v, max( $dec, 2 ) ), '0' ), '.' );
        }
        if ( preg_match( '/(cost|revenue|profit|payout|cpm|cpc|cpa|_ap)/i', $metric ) ) {
            return self::money( $v );
        }
        if ( preg_match( '/(roi|ctr|_cr|_cv|percent|loss)/i', $metric ) ) {
            return number_format( $v, 1 ) . '%';
        }
        return rtrim( rtrim( number_format( $v, 2 ), '0' ), '.' );
    }

    private static function human_interval( int $seconds ): string {
        if ( $seconds <= 0 ) {
            return '';
        }
        if ( $seconds % 86400 === 0 ) {
            return 'every ' . ( $seconds / 86400 ) . 'd';
        }
        if ( $seconds % 3600 === 0 ) {
            return 'every ' . ( $seconds / 3600 ) . 'h';
        }
        return 'every ' . max( 1, (int) round( $seconds / 60 ) ) . 'm';
    }

    private static function range_label( string $from, string $to ): string {
        return wp_date( 'M j', strtotime( $from ) ) . ' – ' . wp_date( 'M j, Y', strtotime( $to ) );
    }

    private static function pretty_slug( string $slug ): string {
        if ( ! $slug ) {
            return '—';
        }
        $short = preg_replace( '/^optee-(traffic-source|tracker|monetization|antifraud|affiliate-network)-/', '', $slug );
        return ucwords( str_replace( '-', ' ', $short ) );
    }

    private static function campaign_status( int $campaign_id ): string {
        $s = get_post_meta( $campaign_id, '_optee_status', true ) ?: 'active';
        return $s === 'paused' ? 'Paused' : 'Active';
    }
}
