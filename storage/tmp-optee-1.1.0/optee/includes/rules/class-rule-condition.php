<?php

namespace Optee\Plugin\Rules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Optee\Plugin\ModuleRegistry;
use Optee\Plugin\CustomColumns;

class RuleCondition {

    /** Metric keys served by the `virustotal` condition source. */
    public const VT_METRICS = [ 'vt_flagged', 'vt_malicious', 'vt_suspicious', 'vt_total', 'vt_flagged_pct' ];

    /** Floor for how old the newest scan may be before a VT condition goes unavailable (minutes). */
    private const VT_MAX_SCAN_AGE_MIN = 360;

    /** @var string[] Errors from fetch methods, reset on each evaluate call. */
    private static array $fetch_errors = [];

    /** True while the rule engine is actively fetching module stats — modules
     *  may use this to bias sort/limit toward rule-relevant zones (e.g. cost desc). */
    private static bool $in_rule_fetch = false;

    public static function is_rule_fetch_active(): bool {
        return self::$in_rule_fetch;
    }

    /**
     * Evaluate conditions at zone level — returns matching zones and all evaluation details.
     *
     * @param array  $conditions  Array of ['source' => ..., 'metric' => ..., 'operator' => ..., 'value' => ...]
     * @param int    $campaign_id Optee campaign post ID.
     * @param string $timeframe   Timeframe key (e.g., '7d', 'today').
     * @return array ['matching_zones' => string[], 'all_evaluations' => [...]]
     */
    public static function evaluate_zones( array $conditions, int $campaign_id, string $timeframe ): array {
        if ( empty( $conditions ) ) {
            return [ 'matching_zones' => [], 'all_evaluations' => [], 'errors' => [] ];
        }

        self::$fetch_errors = [];
        $dates    = self::resolve_timeframe( $timeframe );
        $custom_map = self::resolve_custom_columns( $conditions, $campaign_id );
        $zone_data = self::get_zone_stats( $campaign_id, $conditions, $dates['from'], $dates['to'], $custom_map );

        $matching_zones   = [];
        $all_evaluations  = [];

        foreach ( $zone_data as $zone_id => $stats ) {
            $zone_passed = true;
            $zone_details = [];

            foreach ( $conditions as $condition ) {
                $metric   = $condition['metric'] ?? '';
                $operator = $condition['operator'] ?? '>';
                $value    = (float) ( $condition['value'] ?? 0 );
                $source   = $condition['source'] ?? '';
                // Custom-column condition: evaluate formula against merged zone stats.
                if ( $source === 'custom' ) {
                    $cdef = $custom_map[ $metric ] ?? null;
                    $actual = $cdef ? CustomColumns::evaluate( $cdef['formula'], $stats, ! empty( $cdef['treat_missing_zero'] ) ) : null;
                    if ( $actual !== null && ! empty( $cdef['clamp_negative'] ) && $actual < 0 ) {
                        $actual = 0.0;
                    }
                } else {
                    $actual = $stats[ $metric ] ?? null;
                }

                if ( $actual === null ) {
                    $zone_details[] = [
                        'metric'   => $metric,
                        'operator' => $operator,
                        'expected' => $value,
                        'actual'   => null,
                        'passed'   => false,
                        'reason'   => 'metric_not_available',
                    ];
                    $zone_passed = false;
                    continue;
                }

                $result = self::compare( (float) $actual, $operator, $value );
                $zone_details[] = [
                    'metric'   => $metric,
                    'operator' => $operator,
                    'expected' => $value,
                    'actual'   => $actual,
                    'passed'   => $result,
                ];

                if ( ! $result ) {
                    $zone_passed = false;
                }
            }

            $all_evaluations[] = [
                'zone_id' => $zone_id,
                'passed'  => $zone_passed,
                'details' => $zone_details,
            ];

            if ( $zone_passed ) {
                $matching_zones[] = $zone_id;
            }
        }

        return [
            'matching_zones'  => $matching_zones,
            'all_evaluations' => $all_evaluations,
            'errors'          => self::$fetch_errors,
        ];
    }

    /**
     * Evaluate conditions at country level against the traffic source's native
     * per-country stats (PopAds reports conversion economics per country, so no
     * tracker join is needed). Returns the same shape as evaluate_zones, with the
     * country code standing in for zone_id.
     *
     * @return array ['matching_zones' => string[], 'all_evaluations' => [...], 'errors' => [...]]
     */
    public static function evaluate_countries( array $conditions, int $campaign_id, string $timeframe ): array {
        if ( empty( $conditions ) ) {
            return [ 'matching_zones' => [], 'all_evaluations' => [], 'errors' => [] ];
        }

        self::$fetch_errors = [];
        $dates      = self::resolve_timeframe( $timeframe );
        $custom_map = self::resolve_custom_columns( $conditions, $campaign_id );
        $rows       = self::fetch_traffic_source_country_stats( $campaign_id, $dates['from'], $dates['to'] );

        // Merge tracker per-country stats (tr_* fields) when any condition needs them —
        // either a direct tracker-source condition or a custom column whose formula
        // references a tracker field (e.g. True ROI = tracker revenue vs TS cost). The
        // traffic source reports only its own economics per country; the tracker is
        // grouped by country and joined on country NAME (PopAds gives code + name,
        // BeMob et al. report the country name), matching the geo drilldown report.
        if ( self::countries_need_tracker( $conditions, $custom_map ) ) {
            $tr_by_name = self::fetch_tracker_country_stats( $campaign_id, $dates['from'], $dates['to'] );
            if ( ! empty( $tr_by_name ) ) {
                foreach ( $rows as $code => &$stats ) {
                    $name = strtolower( trim( (string) ( $stats['country_name'] ?? '' ) ) );
                    if ( $name !== '' && isset( $tr_by_name[ $name ] ) ) {
                        $stats = array_merge( $stats, $tr_by_name[ $name ] );
                    }
                }
                unset( $stats );
            }
        }

        $matching_zones  = [];
        $all_evaluations = [];

        foreach ( $rows as $code => $stats ) {
            $passed  = true;
            $details = [];

            foreach ( $conditions as $condition ) {
                $metric   = $condition['metric'] ?? '';
                $operator = $condition['operator'] ?? '>';
                $value    = (float) ( $condition['value'] ?? 0 );
                $source   = $condition['source'] ?? '';
                // Custom-column condition: evaluate its formula against the merged
                // per-country stats (TS native + tracker), same as evaluate_zones.
                if ( $source === 'custom' ) {
                    $cdef   = $custom_map[ $metric ] ?? null;
                    $actual = $cdef ? CustomColumns::evaluate( $cdef['formula'], $stats, ! empty( $cdef['treat_missing_zero'] ) ) : null;
                    if ( $actual !== null && ! empty( $cdef['clamp_negative'] ) && $actual < 0 ) {
                        $actual = 0.0;
                    }
                } else {
                    $actual = $stats[ $metric ] ?? null;
                }

                if ( $actual === null ) {
                    $details[] = [
                        'metric'   => $metric,
                        'operator' => $operator,
                        'expected' => $value,
                        'actual'   => null,
                        'passed'   => false,
                        'reason'   => 'metric_not_available',
                    ];
                    $passed = false;
                    continue;
                }

                $result = self::compare( (float) $actual, $operator, $value );
                $details[] = [
                    'metric'   => $metric,
                    'operator' => $operator,
                    'expected' => $value,
                    'actual'   => $actual,
                    'passed'   => $result,
                ];
                if ( ! $result ) {
                    $passed = false;
                }
            }

            $all_evaluations[] = [
                'zone_id'      => $code,
                'country_name' => $stats['country_name'] ?? $code,
                'passed'       => $passed,
                'details'      => $details,
            ];

            if ( $passed ) {
                $matching_zones[] = $code;
            }
        }

        return [
            'matching_zones'  => $matching_zones,
            'all_evaluations' => $all_evaluations,
            'errors'          => self::$fetch_errors,
        ];
    }

    /**
     * Evaluate a targeting dimension (category / OS / browser / form_factor / ISP /
     * resolution) per value, using the TRACKER's performance for that dimension —
     * the traffic source doesn't report these, but forwards its targeting values to
     * the tracker (via tokens) or the tracker detects them natively. Returns matching
     * values (to remove from the traffic source's targeting) + all evaluations.
     *
     * @return array ['matching'=>string[], 'all_evaluations'=>[...], 'errors'=>[...]]
     */
    public static function evaluate_targeting( array $conditions, int $campaign_id, string $timeframe, string $dim ): array {
        if ( empty( $conditions ) || $dim === '' ) {
            return [ 'matching' => [], 'all_evaluations' => [], 'errors' => [] ];
        }

        self::$fetch_errors = [];
        $group = self::tracker_group_for_dim( $campaign_id, $dim );
        if ( ! $group ) {
            self::$fetch_errors[] = "No tracker data source for {$dim} on this campaign.";
            return [ 'matching' => [], 'all_evaluations' => [], 'errors' => self::$fetch_errors ];
        }

        $dates = self::resolve_timeframe( $timeframe );
        $rows  = self::fetch_tracker_grouped( $campaign_id, $dates['from'], $dates['to'], $group );

        $matching        = [];
        $all_evaluations = [];
        foreach ( $rows as $val => $stats ) {
            $passed  = true;
            $details = [];
            foreach ( $conditions as $condition ) {
                $metric   = $condition['metric'] ?? '';
                $operator = $condition['operator'] ?? '>';
                $value    = (float) ( $condition['value'] ?? 0 );
                $actual   = $stats[ $metric ] ?? null;

                if ( $actual === null ) {
                    $details[] = [ 'metric' => $metric, 'operator' => $operator, 'expected' => $value, 'actual' => null, 'passed' => false, 'reason' => 'metric_not_available' ];
                    $passed    = false;
                    continue;
                }
                $result    = self::compare( (float) $actual, $operator, $value );
                $details[] = [ 'metric' => $metric, 'operator' => $operator, 'expected' => $value, 'actual' => $actual, 'passed' => $result ];
                if ( ! $result ) {
                    $passed = false;
                }
            }
            $all_evaluations[] = [ 'zone_id' => (string) $val, 'passed' => $passed, 'details' => $details ];
            if ( $passed ) {
                $matching[] = (string) $val;
            }
        }

        return [ 'matching' => $matching, 'all_evaluations' => $all_evaluations, 'errors' => self::$fetch_errors ];
    }

    /**
     * Resolve which tracker groupBy key holds a targeting dimension's data:
     * token-forwarded dims (category/form_factor/isp/resolution) → the tracker
     * custom slot that captured the token; OS/browser/ISP have native tracker groups.
     */
    private static function tracker_group_for_dim( int $campaign_id, string $dim ): ?string {
        // Token-forwarded dims (category/form_factor/isp/resolution) → the campaign's
        // mapped custom slot (honours the mapping override). Otherwise native group.
        $slot = \Optee\Plugin\AjaxHandler::resolve_dim_slot( $campaign_id, $dim );
        if ( $slot ) {
            return $slot;
        }
        // connection_speed uses BeMob's native 'connection' group (Cable/DSL, Cellular…).
        $native = [ 'os' => 'os', 'browser' => 'browsers', 'isp' => 'isp', 'connection_speed' => 'connection' ];
        return $native[ $dim ] ?? null;
    }

    /** Fetch tracker stats grouped by an arbitrary key (native group or custom slot), keyed by value. */
    private static function fetch_tracker_grouped( int $campaign_id, string $date_from, string $date_to, string $group ): array {
        $tr_slug = get_post_meta( $campaign_id, '_optee_tracker_module', true );
        $tr_cid  = get_post_meta( $campaign_id, '_optee_tracker_campaign_id', true );
        if ( ! $tr_slug || ! $tr_cid ) {
            return [];
        }
        $module = ModuleRegistry::get_instance()->get_module_with_credentials( $tr_slug );
        if ( ! $module ) {
            return [];
        }

        self::$in_rule_fetch = true;
        try {
            $rows = $module->get_statistics( $tr_cid, $date_from, $date_to, $group );
            $out  = [];
            foreach ( $rows as $row ) {
                $z = (string) ( $row['zone_id'] ?? '' );
                if ( $z === '' ) {
                    continue;
                }
                $out[ $z ] = $row;
            }
            return $out;
        } catch ( \Throwable $e ) {
            self::$fetch_errors[] = $module->get_name() . ': ' . $e->getMessage();
            return [];
        } finally {
            self::$in_rule_fetch = false;
        }
    }

    /**
     * Fetch the traffic source's per-country stats map (code => stats). Only
     * traffic sources implementing get_country_stats() (e.g. PopAds) support this.
     */
    private static function fetch_traffic_source_country_stats( int $campaign_id, string $date_from, string $date_to ): array {
        $ts_module_slug = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        if ( ! $ts_module_slug || ! $ts_campaign_id ) {
            return [];
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $ts_module_slug );

        if ( ! $module || ! method_exists( $module, 'get_country_stats' ) ) {
            self::$fetch_errors[] = ( $module ? $module->get_name() : $ts_module_slug ) . ': per-country stats not supported.';
            return [];
        }

        self::$in_rule_fetch = true;
        try {
            return $module->get_country_stats( $ts_campaign_id, $date_from, $date_to );
        } catch ( \Throwable $e ) {
            self::$fetch_errors[] = $module->get_name() . ': ' . $e->getMessage();
            return [];
        } finally {
            self::$in_rule_fetch = false;
        }
    }

    /**
     * Does any condition need per-country tracker stats? True when a condition is
     * tracker-sourced, or a custom column whose fields reference the tracker.
     */
    private static function countries_need_tracker( array $conditions, array $custom_map ): bool {
        foreach ( $conditions as $c ) {
            $source = $c['source'] ?? '';
            if ( $source === 'tracker' ) {
                return true;
            }
            if ( $source === 'custom' ) {
                $cdef = $custom_map[ $c['metric'] ?? '' ] ?? null;
                if ( $cdef && in_array( 'tracker', CustomColumns::sources_for_fields( $cdef['fields_used'] ?? [] ), true ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Fetch the tracker's per-country stats, keyed by lowercased country name (the
     * join key for traffic-source country rows). Only trackers that report a geo
     * grouping contribute; failures are recorded but never fatal.
     *
     * @return array name(lowercased) => tracker stat row (tr_* fields)
     */
    private static function fetch_tracker_country_stats( int $campaign_id, string $date_from, string $date_to ): array {
        $tr_slug = get_post_meta( $campaign_id, '_optee_tracker_module', true );
        $tr_cid  = get_post_meta( $campaign_id, '_optee_tracker_campaign_id', true );
        if ( ! $tr_slug || ! $tr_cid ) {
            return [];
        }
        $module = ModuleRegistry::get_instance()->get_module_with_credentials( $tr_slug );
        if ( ! $module ) {
            return [];
        }

        self::$in_rule_fetch = true;
        try {
            $rows = $module->get_statistics( $tr_cid, $date_from, $date_to, 'geo' );
            $out  = [];
            foreach ( $rows as $row ) {
                $name = strtolower( trim( (string) ( $row['zone_id'] ?? '' ) ) );
                if ( $name === '' ) {
                    continue;
                }
                $out[ $name ] = $row;
            }
            return $out;
        } catch ( \Throwable $e ) {
            self::$fetch_errors[] = $module->get_name() . ': ' . $e->getMessage();
            return [];
        } finally {
            self::$in_rule_fetch = false;
        }
    }

    /**
     * Evaluate conditions at campaign level with timeframe support.
     *
     * @param array  $conditions
     * @param int    $campaign_id
     * @param string $timeframe
     * @return array ['passed' => bool, 'details' => [...]]
     */
    public static function evaluate_campaign( array $conditions, int $campaign_id, string $timeframe ): array {
        if ( empty( $conditions ) ) {
            return [ 'passed' => true, 'details' => [] ];
        }

        $dates = self::resolve_timeframe( $timeframe );
        // A VirusTotal-only rule reads one local table row — don't spend a traffic
        // source / tracker API round-trip fetching stats nothing will look at. These
        // rules run on short intervals, so the saving is not incidental.
        $vt_only = true;
        foreach ( $conditions as $c ) {
            if ( ( $c['source'] ?? '' ) !== 'virustotal' ) {
                $vt_only = false;
                break;
            }
        }
        $stats = $vt_only ? [] : self::get_campaign_stats( $campaign_id, $dates['from'], $dates['to'] );
        $custom_map = self::resolve_custom_columns( $conditions, $campaign_id );

        $details = [];
        $passed  = true;

        foreach ( $conditions as $condition ) {
            $metric   = $condition['metric'] ?? '';
            $operator = $condition['operator'] ?? '>';
            $value    = (float) ( $condition['value'] ?? 0 );
            $source   = $condition['source'] ?? '';
            $vt_note  = null;
            if ( $source === 'custom' ) {
                $cdef = $custom_map[ $metric ] ?? null;
                $actual = $cdef ? CustomColumns::evaluate( $cdef['formula'], $stats, ! empty( $cdef['treat_missing_zero'] ) ) : null;
                if ( $actual !== null && ! empty( $cdef['clamp_negative'] ) && $actual < 0 ) {
                    $actual = 0.0;
                }
            } elseif ( $source === 'virustotal' ) {
                // Domain reputation, not a range metric — the timeframe doesn't apply.
                // Reads the newest scan for the URL the condition explicitly targets.
                [ $actual, $vt_note ] = self::fetch_vt_metric( $campaign_id, (string) ( $condition['target'] ?? '' ), $metric );
            } else {
                $actual = $stats[ $metric ] ?? null;
            }

            if ( $actual === null ) {
                $details[] = [
                    'metric'   => $metric,
                    'operator' => $operator,
                    'expected' => $value,
                    'actual'   => null,
                    'passed'   => false,
                    'reason'   => $vt_note ?: 'metric_not_available',
                    'target'   => $condition['target'] ?? null,
                ];
                $passed = false;
                continue;
            }

            $result = self::compare( (float) $actual, $operator, $value );
            $details[] = [
                'metric'   => $metric,
                'operator' => $operator,
                'expected' => $value,
                'actual'   => $actual,
                'passed'   => $result,
                'target'   => $condition['target'] ?? null,
                'note'     => $vt_note,
            ];

            if ( ! $result ) {
                $passed = false;
            }
        }

        return [ 'passed' => $passed, 'details' => $details ];
    }

    /**
     * Resolve a `virustotal` condition to its numeric value.
     *
     * Returns [ value|null, note|null ]. A null value is deliberate and means "do not
     * act": the URL was never scanned, isn't on the owner's monitored list, or its
     * newest scan is too stale to trust. evaluate_campaign() turns that into a failed
     * condition, so a campaign is never paused off missing or ancient data.
     *
     * @return array{0: float|null, 1: string|null}
     */
    private static function fetch_vt_metric( int $campaign_id, string $url, string $metric ): array {
        if ( $url === '' || ! in_array( $metric, self::VT_METRICS, true ) ) {
            return [ null, 'vt_no_target' ];
        }

        // Rules run from cron with no current user — the campaign author owns the
        // VT list and the scan rows, same resolution resolve_custom_columns() uses.
        $owner_id = (int) get_post_field( 'post_author', $campaign_id );
        if ( ! $owner_id ) {
            return [ null, 'vt_no_owner' ];
        }

        // Only URLs the owner actually monitors are readable — a tampered condition
        // can't be pointed at another user's scan rows.
        if ( ! self::vt_url_is_monitored( $owner_id, $url ) ) {
            return [ null, 'vt_url_not_monitored' ];
        }

        $scan = self::vt_latest_scan( $owner_id, $url );
        if ( $scan === null ) {
            return [ null, 'vt_never_scanned' ];
        }
        if ( $scan['stale'] ) {
            return [ null, 'vt_scan_stale' ];
        }

        $val = match ( $metric ) {
            'vt_flagged'     => (float) $scan['flagged'],
            'vt_malicious'   => (float) $scan['malicious'],
            'vt_suspicious'  => (float) $scan['suspicious'],
            'vt_total'       => (float) $scan['total'],
            'vt_flagged_pct' => $scan['total'] > 0 ? round( ( $scan['flagged'] / $scan['total'] ) * 100, 2 ) : 0.0,
            default          => null,
        };

        return [ $val, 'vt ' . $scan['flagged'] . '/' . $scan['total'] . ', scanned ' . $scan['age_min'] . ' min ago' ];
    }

    /**
     * The user's active VirusTotal URLs, shaped for the rule form's target dropdown.
     * Paused entries are left out — they stop being scanned, so a rule pointed at one
     * would silently never fire.
     *
     * @return array<int, array{url:string,label:string,host:string}>
     */
    public static function vt_url_options( int $user_id ): array {
        $urls = get_user_meta( $user_id, 'optee_virustotal_urls', true );
        if ( ! is_array( $urls ) ) {
            return [];
        }
        $out = [];
        foreach ( $urls as $item ) {
            $url = (string) ( $item['url'] ?? '' );
            if ( $url === '' || ( $item['status'] ?? 'active' ) !== 'active' ) {
                continue;
            }
            $host  = wp_parse_url( $url, PHP_URL_HOST ) ?: preg_replace( '#^https?://#', '', $url );
            $out[] = [
                'url'   => $url,
                'label' => (string) ( $item['label'] ?? '' ) ?: $host,
                'host'  => $host,
            ];
        }
        return $out;
    }

    /** Is this URL on the user's VirusTotal watch list (and still active)? */
    private static function vt_url_is_monitored( int $user_id, string $url ): bool {
        $urls = get_user_meta( $user_id, 'optee_virustotal_urls', true );
        if ( ! is_array( $urls ) ) {
            return false;
        }
        foreach ( $urls as $item ) {
            if ( ( $item['url'] ?? '' ) === $url && ( $item['status'] ?? 'active' ) === 'active' ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Newest VirusTotal scan row for one monitored URL.
     *
     * "Flagged" is malicious + suspicious — the same total the VT change
     * notification uses (SystemTasks), so a rule fires on the same event that
     * already rings the bell.
     *
     * @return array|null ['flagged','malicious','suspicious','total','scanned_at','age_min','stale']
     */
    public static function vt_latest_scan( int $user_id, string $url ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'optee_vt_scans';
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT malicious, suspicious, total_vendors, scanned_at
             FROM {$table}
             WHERE user_id = %d AND url_hash = %s
             ORDER BY scan_date DESC, scanned_at DESC
             LIMIT 1",
            $user_id,
            hash( 'sha256', $url )
        ) );

        if ( ! $row ) {
            return null;
        }

        $malicious  = (int) $row->malicious;
        $suspicious = (int) $row->suspicious;

        // scanned_at is written with current_time('mysql') — compare in site time,
        // never against time() (UTC), or every scan reads as hours in the future.
        $age_min = (int) max( 0, round( ( current_time( 'timestamp' ) - strtotime( (string) $row->scanned_at ) ) / 60 ) );

        // Scans stop advancing when the API key hits its daily quota. Allow a few
        // missed cycles, then treat the verdict as unusable rather than act on it.
        $interval = (int) get_user_meta( $user_id, 'optee_vt_check_interval', true ) ?: 30;
        $max_age  = max( self::VT_MAX_SCAN_AGE_MIN, $interval * 4 );

        return [
            'flagged'    => $malicious + $suspicious,
            'malicious'  => $malicious,
            'suspicious' => $suspicious,
            'total'      => (int) $row->total_vendors,
            'scanned_at' => (string) $row->scanned_at,
            'age_min'    => $age_min,
            'stale'      => $age_min > $max_age,
        ];
    }

    /**
     * One VirusTotal badge for one watched URL.
     *
     * The single place the badge states are decided, so the campaign report and the
     * rules list can never disagree about what a domain's score means.
     *
     * State is deliberately explicit about failure. `unmonitored` is not cosmetic:
     * fetch_vt_metric() rejects the same URL with 'vt_url_not_monitored', so a rule
     * pointed at it can never evaluate, let alone fire. Rendering nothing (what this
     * used to do) hid a dead rule behind an empty toolbar — see issue #36, where a
     * pause rule silently stopped guarding a campaign the moment its URL left the
     * watch list.
     *
     * @return array{url:string,host:string,state:string,flagged:int,total:int,age_min:int,score:string}
     */
    public static function vt_badge_for( int $user_id, string $url ): array {
        $host = wp_parse_url( $url, PHP_URL_HOST ) ?: preg_replace( '#^https?://#', '', $url );

        $badge = [
            'url'     => $url,
            'host'    => (string) $host,
            'state'   => 'unmonitored',
            'flagged' => 0,
            'total'   => 0,
            'age_min' => 0,
            'score'   => '',
        ];

        if ( ! self::vt_url_is_monitored( $user_id, $url ) ) {
            return $badge;
        }

        $scan = self::vt_latest_scan( $user_id, $url );
        if ( $scan === null ) {
            $badge['state'] = 'unscanned';
            return $badge;
        }

        $badge['flagged'] = (int) $scan['flagged'];
        $badge['total']   = (int) $scan['total'];
        $badge['age_min'] = (int) $scan['age_min'];
        $badge['score']   = $scan['flagged'] . '/' . $scan['total'];
        $badge['state']   = $scan['stale'] ? 'stale' : ( $scan['flagged'] > 0 ? 'flagged' : 'ok' );

        return $badge;
    }

    /**
     * Colours for a badge state. Returned to the client too, so the campaign report
     * and the rules list stay in sync from one definition instead of two colour maps
     * drifting apart.
     *
     * A dashed border marks the two states that mean "nothing is guarding this" —
     * they must not read as a healthy score at a glance.
     *
     * @return array{bg:string,fg:string,border:string}
     */
    public static function vt_badge_style( string $state ): array {
        switch ( $state ) {
            case 'unmonitored':   // rule is broken — loudest state
                return [ 'bg' => '#fef2f2', 'fg' => '#b91c1c', 'border' => '1px dashed #f87171' ];
            case 'flagged':
                return [ 'bg' => '#fef2f2', 'fg' => '#b91c1c', 'border' => '1px solid transparent' ];
            case 'stale':
                return [ 'bg' => '#fffbeb', 'fg' => '#92400e', 'border' => '1px solid transparent' ];
            case 'rule_disabled':
                return [ 'bg' => '#f9fafb', 'fg' => '#6b7280', 'border' => '1px dashed #d1d5db' ];
            case 'unscanned':
                return [ 'bg' => '#f3f4f6', 'fg' => '#6b7280', 'border' => '1px solid transparent' ];
            default:              // ok
                return [ 'bg' => '#f0fdf4', 'fg' => '#15803d', 'border' => '1px solid transparent' ];
        }
    }

    /** Short text inside the badge, after the host. */
    public static function vt_badge_label( array $b ): string {
        switch ( $b['state'] ) {
            case 'unmonitored':
                return 'not watched';
            case 'unscanned':
                return 'not scanned';
            default:
                return (string) $b['score'];
        }
    }

    /**
     * Human-readable tooltip for a badge produced by vt_badge_for().
     * Shared so the report and the rules list explain a state the same way.
     */
    public static function vt_badge_title( array $b ): string {
        switch ( $b['state'] ) {
            case 'unmonitored':
                return 'VirusTotal: ' . $b['url'] . ' is NOT on your watch list — this rule cannot evaluate and will never fire. Re-add the URL on the VirusTotal page.';
            case 'unscanned':
                return 'VirusTotal: no scan yet for ' . $b['url'];
            case 'stale':
                return 'VirusTotal: last scan is ' . $b['age_min'] . ' min old — too stale for rules to act on';
            case 'rule_disabled':
                return 'VirusTotal: ' . $b['score'] . ' on ' . $b['host'] . ' — the rule watching it is switched off, so nothing is guarding this domain';
            case 'flagged':
                return 'VirusTotal: ' . $b['score'] . ' vendors flagged ' . $b['host'] . ', scanned ' . $b['age_min'] . ' min ago';
            default:
                return 'VirusTotal: ' . $b['score'] . ' — ' . $b['host'] . ' is clean, scanned ' . $b['age_min'] . ' min ago';
        }
    }

    /**
     * VirusTotal scores for every domain a campaign's rules watch.
     *
     * Every VT-sourced condition on every bound rule produces a badge — including
     * rules that are switched off (state `rule_disabled`) and targets that have left
     * the watch list (state `unmonitored`). Silence used to mean four different
     * things here; now it only ever means "no rule watches a domain".
     *
     * A URL watched by both an enabled and a disabled rule reports the enabled one:
     * something IS guarding it.
     *
     * The rotator's subdomains are still not included — a rule targets the main
     * domain on purpose, and the rotator scores its own candidates separately.
     *
     * @return array<int, array{url:string,host:string,state:string,flagged:int,total:int,age_min:int,score:string,rule_id:int,rule_title:string}>
     */
    public static function campaign_vt_badges( int $campaign_id ): array {
        $rules = get_posts( [
            'post_type'      => 'optee_rule',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [ 'key' => '_optee_rule_campaign_id', 'value' => $campaign_id ],
            ],
        ] );

        if ( empty( $rules ) ) {
            return [];
        }

        $owner_id = (int) get_post_field( 'post_author', $campaign_id );

        // url => ['rule_id','rule_title','enabled'] — an enabled rule always wins.
        $targets = [];

        foreach ( $rules as $rule_id ) {
            $enabled    = get_post_meta( $rule_id, '_optee_rule_enabled', true ) !== '0';
            $conditions = get_post_meta( $rule_id, '_optee_rule_conditions', true ) ?: [];

            foreach ( $conditions as $c ) {
                if ( ( $c['source'] ?? '' ) !== 'virustotal' ) {
                    continue;
                }
                $url = (string) ( $c['target'] ?? '' );
                if ( $url === '' ) {
                    continue;
                }
                if ( isset( $targets[ $url ] ) && ( $targets[ $url ]['enabled'] || ! $enabled ) ) {
                    continue;
                }
                $targets[ $url ] = [
                    'rule_id'    => (int) $rule_id,
                    'rule_title' => (string) get_the_title( $rule_id ),
                    'enabled'    => $enabled,
                ];
            }
        }

        $out = [];
        foreach ( $targets as $url => $meta ) {
            $badge = self::vt_badge_for( $owner_id, $url );

            // A switched-off rule is reported as such — but only when the target is
            // otherwise healthy. A missing watch-list entry is the more urgent fault
            // and keeps its own state.
            if ( ! $meta['enabled'] && in_array( $badge['state'], [ 'ok', 'flagged', 'stale' ], true ) ) {
                $badge['state'] = 'rule_disabled';
            }

            $badge['rule_id']    = $meta['rule_id'];
            $badge['rule_title'] = $meta['rule_title'];
            $out[]               = $badge;
        }

        return $out;
    }

    /**
     * Legacy method — kept for backward compatibility.
     */
    public static function evaluate_all( array $conditions, int $campaign_id ): array {
        return self::evaluate_campaign( $conditions, $campaign_id, 'today' );
    }

    /**
     * Compare a value using the given operator.
     */
    private static function compare( float $actual, string $operator, float $expected ): bool {
        return match ( $operator ) {
            '>'  => $actual > $expected,
            '>=' => $actual >= $expected,
            '<'  => $actual < $expected,
            '<=' => $actual <= $expected,
            '==' => abs( $actual - $expected ) < 0.0001,
            '!=' => abs( $actual - $expected ) >= 0.0001,
            default => false,
        };
    }

    /**
     * Resolve timeframe string to date range.
     */
    public static function resolve_timeframe( string $timeframe ): array {
        $today     = current_time( 'Y-m-d' );
        $yesterday = wp_date( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) );

        return match ( $timeframe ) {
            'today'     => [ 'from' => $today, 'to' => $today ],
            'yesterday' => [ 'from' => $yesterday, 'to' => $yesterday ],
            '24h'       => [ 'from' => $yesterday, 'to' => $today ],
            '48h'       => [ 'from' => wp_date( 'Y-m-d', strtotime( '-2 days', strtotime( $today ) ) ), 'to' => $today ],
            '3d'        => [ 'from' => wp_date( 'Y-m-d', strtotime( '-2 days', strtotime( $today ) ) ), 'to' => $today ],
            '7d'        => [ 'from' => wp_date( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) ), 'to' => $today ],
            '14d'       => [ 'from' => wp_date( 'Y-m-d', strtotime( '-13 days', strtotime( $today ) ) ), 'to' => $today ],
            '30d'       => [ 'from' => wp_date( 'Y-m-d', strtotime( '-29 days', strtotime( $today ) ) ), 'to' => $today ],
            '60d'       => [ 'from' => wp_date( 'Y-m-d', strtotime( '-59 days', strtotime( $today ) ) ), 'to' => $today ],
            '90d'       => [ 'from' => wp_date( 'Y-m-d', strtotime( '-89 days', strtotime( $today ) ) ), 'to' => $today ],
            '180d'      => [ 'from' => wp_date( 'Y-m-d', strtotime( '-179 days', strtotime( $today ) ) ), 'to' => $today ],
            default     => [ 'from' => wp_date( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) ), 'to' => $today ],
        };
    }

    /**
     * Look up each custom-source condition's stored definition for the
     * campaign owner. Returns map: metric_key (cc__<id>) → custom column array.
     */
    private static function resolve_custom_columns( array $conditions, int $campaign_id ): array {
        $owner_id = (int) get_post_field( 'post_author', $campaign_id );
        $map = [];
        foreach ( $conditions as $c ) {
            if ( ( $c['source'] ?? '' ) !== 'custom' ) {
                continue;
            }
            $metric = $c['metric'] ?? '';
            if ( ! str_starts_with( $metric, 'cc__' ) ) {
                continue;
            }
            $id = substr( $metric, 4 );
            // Override-aware: use this campaign's formula if it has one, so automation
            // matches what the campaign report displays.
            $col = CustomColumns::find_effective( $owner_id, $campaign_id, $id );
            if ( $col ) {
                $map[ $metric ] = $col;
            }
        }
        return $map;
    }

    /**
     * Get zone-level stats merged from all sources referenced in conditions.
     *
     * @param array $custom_map  Output of resolve_custom_columns() — used to
     *                           expand custom conditions into the underlying
     *                           sources that need fetching.
     */
    private static function get_zone_stats( int $campaign_id, array $conditions, string $date_from, string $date_to, array $custom_map = [] ): array {
        $sources = array_unique( array_filter( array_map( function ( $c ) {
            // Custom conditions resolve to their underlying sources via fields_used.
            return ( $c['source'] ?? 'traffic_source' ) === 'custom' ? null : ( $c['source'] ?? 'traffic_source' );
        }, $conditions ) ) );

        // Expand custom conditions: union their referenced sources.
        foreach ( $custom_map as $cdef ) {
            $needed = CustomColumns::sources_for_fields( $cdef['fields_used'] ?? [] );
            foreach ( $needed as $s ) {
                if ( ! in_array( $s, $sources, true ) ) {
                    $sources[] = $s;
                }
            }
        }

        // traffic_loss is a derived metric that needs both antifraud + traffic source data.
        $needs_ts_for_traffic_loss = in_array( 'antifraud', $sources, true )
            && ! in_array( 'traffic_source', $sources, true );
        if ( $needs_ts_for_traffic_loss ) {
            $sources[] = 'traffic_source';
        }

        $zones = [];

        // Traffic source zone stats.
        if ( in_array( 'traffic_source', $sources, true ) ) {
            $ts_rows = self::fetch_traffic_source_zones( $campaign_id, $date_from, $date_to );
            foreach ( $ts_rows as $row ) {
                $zid = (string) ( $row['zone_id'] ?? '' );
                if ( $zid === '' ) {
                    continue;
                }
                if ( ! isset( $zones[ $zid ] ) ) {
                    $zones[ $zid ] = [];
                }
                $zones[ $zid ] = array_merge( $zones[ $zid ], $row );
            }
        }

        // Antifraud zone stats.
        if ( in_array( 'antifraud', $sources, true ) ) {
            $af_rows = self::fetch_antifraud_zones( $campaign_id, $date_from, $date_to );
            foreach ( $af_rows as $row ) {
                $zid = (string) ( $row['zone_id'] ?? '' );
                if ( $zid === '' ) {
                    continue;
                }
                if ( ! isset( $zones[ $zid ] ) ) {
                    $zones[ $zid ] = [];
                }
                $zones[ $zid ] = array_merge( $zones[ $zid ], $row );
            }
        }

        // Tracker zone stats.
        if ( in_array( 'tracker', $sources, true ) ) {
            $tr_rows = self::fetch_tracker_zones( $campaign_id, $date_from, $date_to );
            foreach ( $tr_rows as $row ) {
                $zid = (string) ( $row['zone_id'] ?? '' );
                if ( $zid === '' ) {
                    continue;
                }
                if ( ! isset( $zones[ $zid ] ) ) {
                    $zones[ $zid ] = [];
                }
                $zones[ $zid ] = array_merge( $zones[ $zid ], $row );
            }
        }

        // Compute derived metric: traffic_loss = (1 - af_visits / clicks) * 100.
        // Same formula as the campaign stats dashboard.
        foreach ( $zones as &$z ) {
            $af_visits   = $z['af_visits'] ?? 0;
            $clicks      = $z['clicks'] ?? 0;
            $impressions = $z['impressions'] ?? 0;

            if ( $af_visits > 0 && $clicks > 0 ) {
                $z['traffic_loss'] = max( 0, round( ( 1 - ( $af_visits / $clicks ) ) * 100, 2 ) );
            } elseif ( $af_visits > 0 && $impressions > 0 ) {
                $z['traffic_loss'] = max( 0, round( ( 1 - ( $af_visits / $impressions ) ) * 100, 2 ) );
            }
        }
        unset( $z );

        return $zones;
    }

    /**
     * Fetch traffic source zone-level stats.
     */
    private static function fetch_traffic_source_zones( int $campaign_id, string $date_from, string $date_to ): array {
        $ts_module_slug = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        if ( ! $ts_module_slug || ! $ts_campaign_id ) {
            return [];
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $ts_module_slug );

        if ( ! $module ) {
            return [];
        }

        self::$in_rule_fetch = true;
        try {
            return $module->get_statistics( $ts_campaign_id, $date_from, $date_to, 'zones' );
        } catch ( \Throwable $e ) {
            self::$fetch_errors[] = $module->get_name() . ': ' . $e->getMessage();
            return [];
        } finally {
            self::$in_rule_fetch = false;
        }
    }

    /**
     * Fetch antifraud zone-level stats.
     */
    private static function fetch_antifraud_zones( int $campaign_id, string $date_from, string $date_to ): array {
        $af_module_slug = get_post_meta( $campaign_id, '_optee_antifraud_module', true );
        $af_campaign_id = get_post_meta( $campaign_id, '_optee_antifraud_campaign_id', true );

        if ( ! $af_module_slug || ! $af_campaign_id ) {
            return [];
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $af_module_slug );

        if ( ! $module ) {
            return [];
        }

        self::$in_rule_fetch = true;
        try {
            return $module->get_statistics( $af_campaign_id, $date_from, $date_to, 'zones' );
        } catch ( \Throwable $e ) {
            self::$fetch_errors[] = $module->get_name() . ': ' . $e->getMessage();
            return [];
        } finally {
            self::$in_rule_fetch = false;
        }
    }

    /**
     * Fetch tracker zone-level stats.
     */
    private static function fetch_tracker_zones( int $campaign_id, string $date_from, string $date_to ): array {
        $tr_module_slug = get_post_meta( $campaign_id, '_optee_tracker_module', true );
        $tr_campaign_id = get_post_meta( $campaign_id, '_optee_tracker_campaign_id', true );
        $tr_zone_field  = get_post_meta( $campaign_id, '_optee_zone_id', true );

        if ( ! $tr_module_slug || ! $tr_campaign_id ) {
            return [];
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $tr_module_slug );

        if ( ! $module ) {
            return [];
        }

        $group_by = $tr_zone_field ?: 'zones';

        self::$in_rule_fetch = true;
        try {
            return $module->get_statistics( $tr_campaign_id, $date_from, $date_to, $group_by );
        } catch ( \Throwable $e ) {
            self::$fetch_errors[] = $module->get_name() . ': ' . $e->getMessage();
            return [];
        } finally {
            self::$in_rule_fetch = false;
        }
    }

    /**
     * Get combined campaign-level stats from configured modules.
     */
    private static function get_campaign_stats( int $campaign_id, string $date_from, string $date_to ): array {
        $stats = [];

        $ts_module_slug = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        if ( $ts_module_slug && $ts_campaign_id ) {
            $registry = ModuleRegistry::get_instance();
            $module   = $registry->get_module_with_credentials( $ts_module_slug );

            if ( $module ) {
                try {
                    $campaign_stats = $module->get_campaign_stats( $ts_campaign_id, $date_from, $date_to );
                    $stats = array_merge( $stats, $campaign_stats->to_array() );
                } catch ( \Throwable $e ) {
                    // Stats unavailable.
                }
            }
        }

        // Antifraud data.
        $af_module_slug = get_post_meta( $campaign_id, '_optee_antifraud_module', true );
        $af_campaign_id = get_post_meta( $campaign_id, '_optee_antifraud_campaign_id', true );

        if ( $af_module_slug && $af_campaign_id ) {
            $registry = ModuleRegistry::get_instance();
            $module   = $registry->get_module_with_credentials( $af_module_slug );

            if ( $module ) {
                try {
                    $fraud_data = $module->get_fraud_data( $af_campaign_id, $date_from, $date_to );
                    $total      = (float) $fraud_data->total_visits;

                    // Only expose antifraud metrics when there's actually data for the
                    // range. Otherwise leave them unset so a condition reads "not
                    // available" instead of a misleading 0 (which made Valid %-style
                    // "less than" alerts fire falsely).
                    if ( $total > 0 ) {
                        $clean                  = (float) $fraud_data->clean_visits;
                        $valid_pct              = round( ( $clean / $total ) * 100, 2 );
                        $stats['af_visits']     = $total;
                        $stats['valid_hits']    = $clean;
                        $stats['valid_percent'] = $valid_pct;
                        $stats['bot_percent']   = $fraud_data->fraud_by_type['bot'] ?? 0;
                        $stats['proxy_percent'] = $fraud_data->fraud_by_type['proxy'] ?? 0;
                        $stats['junk_percent']  = $fraud_data->fraud_by_type['junk'] ?? 0;
                        $stats['traffic_loss']  = round( 100 - $valid_pct, 2 );
                        $stats['af_cost']       = 0;
                    }
                } catch ( \Throwable $e ) {
                    // Fraud data unavailable.
                }
            }
        }

        // Tracker data.
        $tr_module_slug = get_post_meta( $campaign_id, '_optee_tracker_module', true );
        $tr_campaign_id = get_post_meta( $campaign_id, '_optee_tracker_campaign_id', true );

        if ( $tr_module_slug && $tr_campaign_id ) {
            $registry = ModuleRegistry::get_instance();
            $module   = $registry->get_module_with_credentials( $tr_module_slug );

            if ( $module ) {
                try {
                    $tracker_stats = $module->get_campaign_stats( $tr_campaign_id, $date_from, $date_to );
                    $arr = $tracker_stats->to_array();
                    $stats['tr_visits']  = $arr['impressions'] ?? 0;
                    $stats['tr_clicks']  = $arr['clicks'] ?? 0;
                    $stats['tr_conv']    = $arr['conversions'] ?? 0;
                    $stats['tr_revenue'] = $arr['revenue'] ?? 0;
                    $stats['tr_cost']    = $arr['cost'] ?? 0;
                    $stats['tr_profit']  = $arr['profit'] ?? 0;
                    $stats['tr_roi']     = $arr['roi'] ?? 0;
                    $stats['tr_ctr']     = $arr['ctr'] ?? 0;
                    $stats['tr_cv']      = $arr['cr'] ?? 0;
                    $stats['tr_cpm']     = $arr['cpm'] ?? 0;
                    $stats['tr_cpa']     = $arr['cpa'] ?? 0;
                    $stats['tr_ap']      = 0;
                } catch ( \Throwable $e ) {
                    // Tracker data unavailable.
                }
            }
        }

        // Monetization totals — so custom columns referencing monetization fields
        // (pp_earnings, mtg_earnings, …) resolve in rules + report alerts, matching
        // what the campaign-stats page shows.
        $stats = array_merge( $stats, self::get_campaign_monetization( $campaign_id, $date_from, $date_to ) );

        return $stats;
    }

    /**
     * Fetch the campaign's monetization-platform totals for the range (summed), so
     * custom columns using monetization fields evaluate correctly. Mirrors the
     * campaign-stats page's per-platform fetch but returns totals, fresh + uncached
     * (runs at most once/day per report alert). Each platform is isolated so one
     * failure can't affect the others; only configured platforms are fetched.
     */
    private static function get_campaign_monetization( int $campaign_id, string $date_from, string $date_to ): array {
        $out      = [];
        $registry = ModuleRegistry::get_instance();

        $mod = function ( string $slug ) use ( $registry ) {
            $m = $registry->get_module( $slug, 'monetization_platform' );
            if ( ! $m ) {
                return null;
            }
            $m->set_credentials( ModuleRegistry::get_stored_credentials( 'monetization_platform', $slug ) ?: [] );
            return $m;
        };
        $sum = function ( array $rows, string $money_key, string $imp_key, ?string $sub_key = null ): array {
            $money = 0.0; $imp = 0.0; $sub = 0.0;
            foreach ( $rows as $r ) {
                $money += (float) ( $r[ $money_key ] ?? 0 );
                $imp   += (float) ( $r[ $imp_key ] ?? 0 );
                if ( $sub_key !== null ) {
                    $sub += (float) ( $r[ $sub_key ] ?? 0 );
                }
            }
            return [ $money, $imp, $sub ];
        };
        $cpm = fn( float $money, float $imp ): float => $imp > 0 ? round( ( $money / $imp ) * 1000, 4 ) : 0;

        // Monetag
        $zone = get_post_meta( $campaign_id, '_optee_monetag_zone_id', true );
        if ( $zone ) {
            try {
                $m = $mod( 'monetag' );
                if ( $m && method_exists( $m, 'get_statistics' ) ) {
                    $f = [ 'zone_id' => $zone ];
                    if ( $c = get_post_meta( $campaign_id, '_optee_monetag_country', true ) ) { $f['country'] = $c; }
                    [ $money, $imp ] = $sum( $m->get_statistics( $date_from, $date_to, [ 'date_time' ], $f ), 'money', 'impressions' );
                    $out['mtg_earnings'] = round( $money, 6 ); $out['mtg_impressions'] = $imp; $out['mtg_ecpm'] = $cpm( $money, $imp );
                }
            } catch ( \Throwable $e ) {}
        }

        // ProPush
        $pp_zone = get_post_meta( $campaign_id, '_optee_propush_zone_id', true );
        $pp_site = get_post_meta( $campaign_id, '_optee_propush_site_id', true );
        if ( $pp_zone || $pp_site ) {
            try {
                $m = $mod( 'propush' );
                if ( $m && method_exists( $m, 'get_statistics' ) ) {
                    $f = [];
                    if ( $pp_zone ) { $f['zone_id'] = $pp_zone; }
                    if ( $pp_site ) { $f['site_id'] = $pp_site; }
                    if ( $s = get_post_meta( $campaign_id, '_optee_propush_source_id', true ) ) { $f['source_id'] = $s; }
                    if ( $c = get_post_meta( $campaign_id, '_optee_propush_country', true ) ) { $f['country'] = $c; }
                    [ $money, $imp, $sub ] = $sum( $m->get_statistics( $date_from, $date_to, [ 'date_time' ], $f ), 'money', 'impressions', 'subscriptions' );
                    $out['pp_earnings'] = round( $money, 6 ); $out['pp_impressions'] = $imp; $out['pp_subscribed'] = $sub; $out['pp_cpm'] = $cpm( $money, $imp );
                }
            } catch ( \Throwable $e ) {}
        }

        // Partners.House
        if ( get_post_meta( $campaign_id, '_optee_ph_active', true ) ) {
            try {
                $m = $mod( 'partners-house' );
                if ( $m && method_exists( $m, 'get_statistics' ) ) {
                    $f = [];
                    foreach ( [ 'sub1', 'sub2', 'sub3', 'sub4', 'country' ] as $k ) {
                        if ( $v = get_post_meta( $campaign_id, '_optee_ph_' . $k, true ) ) { $f[ $k ] = $v; }
                    }
                    [ $money, $imp, $sub ] = $sum( $m->get_statistics( $date_from, $date_to, [ 'days' ], $f ), 'totalProfit', 'rsShows', 'subscriptions' );
                    $out['ph_earnings'] = round( $money, 6 ); $out['ph_impressions'] = $imp; $out['ph_subscriptions'] = $sub; $out['ph_cpm'] = $cpm( $money, $imp );
                }
            } catch ( \Throwable $e ) {}
        }

        // RollerAds Publisher
        $rap_site = get_post_meta( $campaign_id, '_optee_rap_site_id', true );
        if ( $rap_site ) {
            try {
                $m = $mod( 'rollerads-publisher' );
                if ( $m && method_exists( $m, 'get_statistics' ) ) {
                    $f = [ 'site_id' => $rap_site ];
                    if ( $z = get_post_meta( $campaign_id, '_optee_rap_zone_id', true ) ) { $f['zone_id'] = $z; }
                    $rap_rows = $m->get_statistics( $date_from, $date_to, [ 'day' ], $f );
                    [ $money, $imp, $sub ] = $sum( $rap_rows, 'amt_omoney', 'cnt_impression', 'cnt_subscribe' );
                    $req = 0.0; $unsub = 0.0;
                    foreach ( $rap_rows as $rr ) {
                        $req   += (float) ( $rr['cnt_request'] ?? 0 );
                        $unsub += (float) ( $rr['cnt_unsubscribe'] ?? 0 );
                    }
                    $out['rap_earnings'] = round( $money, 6 ); $out['rap_impressions'] = $imp; $out['rap_subscribed'] = $sub; $out['rap_cpm'] = $cpm( $money, $imp );
                    // CR recomputed from the period totals, not averaged across days.
                    $out['rap_requests'] = $req; $out['rap_unsubscribed'] = $unsub;
                    $out['rap_cr'] = $req > 0 ? round( ( $sub / $req ) * 100, 2 ) : 0;
                }
            } catch ( \Throwable $e ) {}
        }

        // HilltopAds Publisher
        $hta_site = get_post_meta( $campaign_id, '_optee_hta_site_id', true );
        if ( $hta_site ) {
            try {
                $m = $mod( 'hilltopads-publisher' );
                if ( $m && method_exists( $m, 'get_statistics' ) ) {
                    $f = [ 'site_id' => $hta_site ];
                    if ( $z = get_post_meta( $campaign_id, '_optee_hta_zone_id', true ) ) { $f['zone_id'] = $z; }
                    [ $money, $imp, $clk ] = $sum( $m->get_statistics( $date_from, $date_to, [ 'date' ], $f ), 'revenue', 'impressions', 'clicks' );
                    $out['hta_earnings'] = round( $money, 6 ); $out['hta_impressions'] = $imp; $out['hta_clicks'] = $clk; $out['hta_cpm'] = $cpm( $money, $imp );
                }
            } catch ( \Throwable $e ) {}
        }

        // Monetizer (get_league_report)
        $league = get_post_meta( $campaign_id, '_optee_mtzr_league_id', true );
        if ( $league ) {
            try {
                $m = $mod( 'monetizer' );
                if ( $m && method_exists( $m, 'get_league_report' ) ) {
                    $kw   = get_post_meta( $campaign_id, '_optee_mtzr_campaign_keyword', true ) ?: '';
                    $rows = $m->get_league_report( $league, $date_from, $date_to, $kw );
                    $clk = 0.0; $lead = 0.0; $rev = 0.0;
                    foreach ( $rows as $r ) { $clk += (float) ( $r['clicks'] ?? 0 ); $lead += (float) ( $r['leads'] ?? 0 ); $rev += (float) ( $r['revenue'] ?? 0 ); }
                    $out['mtzr_clicks'] = $clk; $out['mtzr_leads'] = $lead; $out['mtzr_revenue'] = round( $rev, 6 ); $out['mtzr_epc'] = $clk > 0 ? round( $rev / $clk, 4 ) : 0;
                }
            } catch ( \Throwable $e ) {}
        }

        // Zeydoo
        $zd_rv = get_post_meta( $campaign_id, '_optee_zd_request_var', true );
        if ( $zd_rv ) {
            try {
                $m = $mod( 'zeydoo' );
                if ( $m && method_exists( $m, 'get_statistics' ) ) {
                    $rows = $m->get_statistics( $date_from, $date_to, [ 'date_time' ], [ 'request_var' => $zd_rv ] );
                    $req = 0.0; $imp = 0.0; $conv = 0.0; $money = 0.0;
                    foreach ( $rows as $r ) { $req += (float) ( $r['requests'] ?? 0 ); $imp += (float) ( $r['impressions'] ?? 0 ); $conv += (float) ( $r['conversions'] ?? 0 ); $money += (float) ( $r['money'] ?? 0 ); }
                    $out['zd_requests'] = $req; $out['zd_impressions'] = $imp; $out['zd_conversions'] = $conv; $out['zd_earnings'] = round( $money, 6 ); $out['zd_cpm'] = $cpm( $money, $imp );
                }
            } catch ( \Throwable $e ) {}
        }

        return $out;
    }
}
