<?php

namespace Optee\Plugin\Rules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Optee\Plugin\AjaxHandler;
use Optee\Plugin\Logger;
use Optee\Plugin\ModuleRegistry;
use Optee\Plugin\Notifications;
use Optee\Plugin\ZoneFilter;

class RuleEngine {

    /**
     * Process all due rules in randomized priority order.
     *
     * Rules are sorted by how overdue they are (most overdue first),
     * with a random tiebreaker so same-priority rules don't always
     * run in the same order. Each rule is wrapped in try/catch so
     * one failure doesn't block others. Stops picking new rules when
     * nearing PHP's max execution time limit.
     *
     * @param float $cron_start_time microtime(true) when the cron run began (0 = no budget).
     */
    public static function process_due_rules( float $cron_start_time = 0 ): array {
        $results    = [];
        $time_limit = self::get_time_limit();
        $buffer     = 5; // Stop 5 seconds before the limit.

        $rules = get_posts( [
            'post_type'      => 'optee_rule',
            'post_status'    => 'publish',
            'meta_key'       => '_optee_rule_enabled',
            'meta_value'     => '1',
            'posts_per_page' => -1,
        ] );

        // Build list of due rules with overdue score.
        // Use WP local time to match current_time('mysql') stored in _optee_rule_last_run.
        $due_rules = [];
        $now       = current_time( 'timestamp' );

        foreach ( $rules as $rule ) {
            // Paused-campaign gate — FIRST check, before due/interval logic.
            // Silently skip rules whose campaign is locally paused: don't add to
            // the due list, don't log, don't count in "rules checked", don't touch
            // last-run. Resume/start actions are exempt so a "resume when conditions
            // recover" rule can still un-pause the campaign.
            $action = get_post_meta( $rule->ID, '_optee_rule_action', true );
            if ( ! in_array( $action, [ 'resume_traffic', 'start_campaign' ], true ) ) {
                $cid          = (int) get_post_meta( $rule->ID, '_optee_rule_campaign_id', true );
                $local_status = $cid ? ( get_post_meta( $cid, '_optee_status', true ) ?: 'active' ) : 'active';
                if ( $local_status === 'paused' ) {
                    continue;
                }
            }

            // Snooze gate — the user has temporarily paused THIS rule for a set
            // window (e.g. "let the campaign collect fresh data for 3 days without
            // rules touching it"). While snoozed, skip silently — don't run, don't
            // touch last-run. When the window elapses the meta is cleared so the
            // rule resumes on its normal schedule (see stored value below, which is
            // current_time('timestamp') + duration, so we compare against $now).
            $snooze_until = (int) get_post_meta( $rule->ID, '_optee_rule_snooze_until', true );
            if ( $snooze_until > 0 ) {
                if ( $now < $snooze_until ) {
                    continue;
                }
                // Window elapsed — clear it so the rule is back to normal.
                delete_post_meta( $rule->ID, '_optee_rule_snooze_until' );
                Logger::log( 'info', 'rule', "Rule snooze ended — resuming automation: {$rule->post_title}", [
                    'rule_id' => $rule->ID,
                ] );
            }

            $interval = (int) get_post_meta( $rule->ID, '_optee_rule_interval', true );
            $last_run = get_post_meta( $rule->ID, '_optee_rule_last_run', true );

            if ( empty( $last_run ) ) {
                // Never run — highest priority.
                $overdue_ratio = PHP_INT_MAX;
            } else {
                $next_run = strtotime( $last_run ) + ( $interval * 60 );
                if ( $now < $next_run ) {
                    continue; // Not due yet.
                }
                // How many intervals overdue (higher = more overdue).
                $overdue_seconds = $now - $next_run;
                $overdue_ratio   = $interval > 0 ? $overdue_seconds / ( $interval * 60 ) : $overdue_seconds;
            }

            $due_rules[] = [
                'rule'          => $rule,
                'overdue_ratio' => $overdue_ratio,
                'rand'          => mt_rand(),
            ];
        }

        // Sort: most overdue first, random tiebreaker.
        usort( $due_rules, function ( $a, $b ) {
            if ( $a['overdue_ratio'] !== $b['overdue_ratio'] ) {
                return $b['overdue_ratio'] <=> $a['overdue_ratio'];
            }
            return $a['rand'] <=> $b['rand'];
        } );

        foreach ( $due_rules as $item ) {
            $rule = $item['rule'];

            // Check time budget before starting a new rule.
            if ( $time_limit > 0 && $cron_start_time > 0 ) {
                $elapsed = microtime( true ) - $cron_start_time;
                if ( $elapsed >= ( $time_limit - $buffer ) ) {
                    $remaining = count( $due_rules ) - count( $results );
                    $elapsed_r = round( $elapsed, 1 );
                    Logger::log( 'warning', 'rule', "Automation time limit approaching ({$elapsed_r}s / {$time_limit}s) — {$remaining} remaining rule(s) deferred to next run.", [
                        'elapsed'    => $elapsed_r,
                        'limit'      => $time_limit,
                        'processed'  => count( $results ),
                        'deferred'   => $remaining,
                    ] );
                    break;
                }
            }

            $rule_start   = microtime( true );
            $campaign_id  = (int) get_post_meta( $rule->ID, '_optee_rule_campaign_id', true );
            $campaign     = get_post( $campaign_id );
            $campaign_name = $campaign ? $campaign->post_title : '(unknown)';

            // Set the user context to the rule's owner so ModuleRegistry::get_stored_credentials()
            // (which keys off get_current_user_id()) finds the encrypted API keys. Without this,
            // cron-triggered runs have no current user and modules call APIs with empty creds.
            $original_user = get_current_user_id();
            $rule_user     = (int) $rule->post_author;
            if ( $rule_user > 0 && $rule_user !== $original_user ) {
                wp_set_current_user( $rule_user );
            }

            try {
                $result = self::process_rule( $rule );
                $result['rule_name']     = $rule->post_title;
                $result['campaign_name'] = $campaign_name;
                $result['duration']      = round( microtime( true ) - $rule_start, 2 );
                $results[ $rule->ID ]    = $result;
            } catch ( \Throwable $e ) {
                $error_msg = $e->getMessage();
                $result = [
                    'status'        => 'error',
                    'message'       => "Exception: {$error_msg}",
                    'rule_name'     => $rule->post_title,
                    'campaign_name' => $campaign_name,
                    'duration'      => round( microtime( true ) - $rule_start, 2 ),
                ];
                $results[ $rule->ID ] = $result;
                self::store_result( $rule->ID, $result );

                Logger::log( 'error', 'rule', "Rule \"{$rule->post_title}\" threw exception: {$error_msg}", [
                    'rule_id'   => $rule->ID,
                    'exception' => get_class( $e ),
                    'file'      => $e->getFile() . ':' . $e->getLine(),
                ] );
            }

            // Always update last run time — even on error — so broken rules
            // don't monopolize the queue by retrying every cron cycle.
            update_post_meta( $rule->ID, '_optee_rule_last_run', current_time( 'mysql' ) );

            // Restore the original user context before processing the next rule.
            if ( $rule_user > 0 && $rule_user !== $original_user ) {
                wp_set_current_user( $original_user );
            }
        }

        return $results;
    }

    /**
     * Get the effective time limit in seconds for automation.
     *
     * Returns 0 if unlimited (no limit should be enforced).
     */
    private static function get_time_limit(): int {
        // Check user setting first.
        $admins   = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
        $admin_id = ! empty( $admins ) ? (int) $admins[0] : 1;
        $custom   = (int) get_user_meta( $admin_id, 'optee_max_execution_time', true );

        if ( $custom > 0 ) {
            return $custom;
        }

        // Auto-detect from PHP.
        $php_limit = (int) ini_get( 'max_execution_time' );

        // 0 means unlimited in PHP — don't enforce a limit.
        return $php_limit;
    }

    /**
     * Process a single rule.
     */
    public static function process_rule( \WP_Post $rule ): array {
        $rule_id     = $rule->ID;
        $campaign_id = (int) get_post_meta( $rule_id, '_optee_rule_campaign_id', true );
        $action      = get_post_meta( $rule_id, '_optee_rule_action', true );
        $conditions  = get_post_meta( $rule_id, '_optee_rule_conditions', true ) ?: [];
        $timeframe   = get_post_meta( $rule_id, '_optee_rule_timeframe', true ) ?: '7d';
        $on_success  = get_post_meta( $rule_id, '_optee_rule_on_success', true ) ?: [];
        $action_params = get_post_meta( $rule_id, '_optee_rule_action_params', true ) ?: [];

        $campaign = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' ) {
            $result = [ 'status' => 'skip', 'reason' => 'campaign_not_found' ];
            self::store_result( $rule_id, $result );
            Logger::log( 'warning', 'rule', "Rule \"{$rule->post_title}\": campaign not found (ID {$campaign_id})", [
                'rule_id' => $rule_id,
            ] );
            return $result;
        }

        // Skip rules on locally-paused campaigns — there's no fresh traffic to act on,
        // so blocking zones / re-pausing is pointless. The cron loop already filters these
        // out silently; this guard only fires on a manual "Run Now", where we return a
        // message (no log) so the user sees why nothing happened. Resume/start actions are
        // exempt: a "resume when conditions recover" rule must run to un-pause the campaign.
        $is_resume_action = in_array( $action, [ 'resume_traffic', 'start_campaign' ], true );
        $local_status     = get_post_meta( $campaign_id, '_optee_status', true ) ?: 'active';
        if ( $local_status === 'paused' && ! $is_resume_action ) {
            $result = [
                'status'  => 'skip',
                'reason'  => 'campaign_paused',
                'message' => 'Campaign is paused — rule skipped.',
            ];
            self::store_result( $rule_id, $result );
            return $result;
        }

        // Offer status rule — check live offer status from affiliate network.
        $is_offer_rule = ! empty( $conditions ) && $conditions[0]['source'] === 'affiliate_network' && $conditions[0]['metric'] === 'offer_status';
        if ( $is_offer_rule ) {
            return self::process_offer_status_rule( $rule, $campaign, $action, $conditions, $on_success );
        }

        $is_zone_action = in_array( $action, [ 'block_zone', 'unblock_zone', 'block_zones', 'unblock_zones' ], true );

        if ( $is_zone_action ) {
            return self::process_zone_rule( $rule, $campaign, $action, $conditions, $timeframe, $on_success );
        }

        // Country-level action — evaluate per-country traffic-source stats and
        // remove the under-performing countries from targeting.
        if ( $action === 'remove_countries' ) {
            return self::process_country_rule( $rule, $campaign, $action, $conditions, $timeframe, $on_success );
        }

        // Targeting-dimension action (remove category / OS / browser / ISP / …) —
        // evaluate per-value tracker performance, remove the losers from targeting.
        if ( $action === 'remove_targeting' ) {
            $dim = $action_params['dimension'] ?? '';
            return self::process_targeting_rule( $rule, $campaign, $action, $dim, $conditions, $timeframe, $on_success );
        }

        return self::process_campaign_rule( $rule, $campaign, $action, $conditions, $timeframe, $on_success, $action_params );
    }

    /**
     * Process a zone-level rule (block/unblock zones).
     */
    private static function process_zone_rule(
        \WP_Post $rule,
        \WP_Post $campaign,
        string $action,
        array $conditions,
        string $timeframe,
        array $on_success
    ): array {
        $rule_id     = $rule->ID;
        $campaign_id = $campaign->ID;

        // Skip zone-level actions on whitelist campaigns.
        if ( AjaxHandler::campaign_is_whitelist( $campaign_id ) ) {
            $msg = 'Skipped: zone block/unblock actions cannot be used with whitelist campaigns.';
            $result = [ 'status' => 'skip', 'reason' => 'whitelist_campaign', 'message' => $msg ];
            self::store_result( $rule_id, $result );

            Logger::log( 'warning', 'rule', "Rule \"{$rule->post_title}\": {$msg}", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
            ] );

            $user_id = (int) $campaign->post_author;
            Notifications::add( $user_id, [
                'type'        => 'warning',
                'title'       => $rule->post_title,
                'message'     => $msg,
                'time'        => current_time( 'mysql' ),
                'campaign_id' => $campaign_id,
            ] );

            return $result;
        }

        $eval = RuleCondition::evaluate_zones( $conditions, $campaign_id, $timeframe );

        // Log every zone evaluation.
        foreach ( $eval['all_evaluations'] as $zone_eval ) {
            $status_str = $zone_eval['passed'] ? 'MATCHED' : 'not matched';
            $values = [];
            foreach ( $zone_eval['details'] as $d ) {
                $values[] = "{$d['metric']} {$d['operator']} {$d['expected']} (actual: " . ( $d['actual'] ?? 'N/A' ) . ') → ' . ( $d['passed'] ? 'pass' : 'fail' );
            }
            Logger::log( 'debug', 'rule', "Rule \"{$rule->post_title}\" zone {$zone_eval['zone_id']}: {$status_str}", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
                'zone_id'     => $zone_eval['zone_id'],
                'conditions'  => $values,
            ] );
        }

        $matching_zones = ZoneFilter::clean( $eval['matching_zones'] );
        $zone_count     = count( $matching_zones );
        $total_zones    = count( $eval['all_evaluations'] );

        $fetch_errors = $eval['errors'] ?? [];

        if ( empty( $matching_zones ) ) {
            $result = [
                'status'  => 'no_match',
                'message' => "No zones matched conditions ({$total_zones} evaluated).",
                'zones_blocked'   => 0,
                'zones_unblocked' => 0,
                'details' => $eval['all_evaluations'],
                'errors'  => $fetch_errors,
            ];
            self::store_result( $rule_id, $result );

            Logger::log( 'info', 'rule', "Rule \"{$rule->post_title}\": no zones matched ({$total_zones} evaluated)", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
            ] );

            return $result;
        }

        // Execute action with discovered zones.
        $action_result = RuleAction::execute( $action, $campaign_id, $matching_zones );

        // The action only touches zones that actually changed state — it skips zones
        // already on the blacklist. Count/log THAT set, not every matched zone, so
        // the optimization timeline shows a zone the first time it's blocked and
        // doesn't re-list already-blocked zones on every subsequent run.
        $affected = isset( $action_result['affected_zones'] ) && is_array( $action_result['affected_zones'] )
            ? array_values( array_map( 'strval', $action_result['affected_zones'] ) )
            : $matching_zones;
        $affected_count = count( $affected );

        $blocked   = in_array( $action, [ 'block_zone', 'block_zones' ], true ) ? $affected_count : 0;
        $unblocked = in_array( $action, [ 'unblock_zone', 'unblock_zones' ], true ) ? $affected_count : 0;

        $result = [
            'status'          => $action_result['success'] ? 'executed' : 'failed',
            'message'         => $action_result['message'],
            'zones_blocked'   => $blocked,
            'zones_unblocked' => $unblocked,
            'matching_zones'  => $matching_zones, // full matched set — for the last-run detail UI
            'details'         => $eval['all_evaluations'],
            'errors'          => $fetch_errors,
        ];

        self::store_result( $rule_id, $result );

        $log_type = $action_result['success'] ? 'info' : 'error';
        Logger::log( $log_type, 'rule', "Rule \"{$rule->post_title}\": {$action_result['message']} ({$zone_count}/{$total_zones} zones matched)", [
            'rule_id'        => $rule_id,
            'campaign_id'    => $campaign_id,
            'action'         => $action,
            'matching_zones' => $affected, // only what actually changed → drives the timeline
        ] );

        // Notification — skip if action made no actual changes (e.g. all matched zones
        // were already blocked). The log entry still records the run; we just don't
        // ping the bell when nothing changed.
        if ( empty( $action_result['no_change'] ) ) {
            $user_id = (int) $campaign->post_author;
            Notifications::add( $user_id, [
                'type'         => $action_result['success'] ? 'info' : 'error',
                'title'        => $rule->post_title,
                'message'      => $action_result['message'],
                'message_full' => $action_result['message_full'] ?? $action_result['message'],
                'time'         => current_time( 'mysql' ),
                'campaign_id'  => $campaign_id,
            ] );
        }

        // Handle on_success flags.
        if ( $action_result['success'] ) {
            self::handle_on_success( $rule->ID, $campaign->ID, $on_success );
        }

        return $result;
    }

    /**
     * Process a country-level rule (remove under-performing countries).
     *
     * Parallels process_zone_rule but evaluates per-country traffic-source stats
     * (PopAds reports native conversion economics per country, so no tracker join
     * is needed). Matched countries are removed from the campaign's targeting.
     */
    private static function process_country_rule(
        \WP_Post $rule,
        \WP_Post $campaign,
        string $action,
        array $conditions,
        string $timeframe,
        array $on_success
    ): array {
        $rule_id     = $rule->ID;
        $campaign_id = $campaign->ID;

        $eval = RuleCondition::evaluate_countries( $conditions, $campaign_id, $timeframe );

        // Log every country evaluation.
        foreach ( $eval['all_evaluations'] as $c_eval ) {
            $status_str = $c_eval['passed'] ? 'MATCHED' : 'not matched';
            $values = [];
            foreach ( $c_eval['details'] as $d ) {
                $values[] = "{$d['metric']} {$d['operator']} {$d['expected']} (actual: " . ( $d['actual'] ?? 'N/A' ) . ') → ' . ( $d['passed'] ? 'pass' : 'fail' );
            }
            Logger::log( 'debug', 'rule', "Rule \"{$rule->post_title}\" country {$c_eval['zone_id']}: {$status_str}", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
                'country'     => $c_eval['zone_id'],
                'conditions'  => $values,
            ] );
        }

        $matching     = array_values( array_filter( array_map( 'strval', $eval['matching_zones'] ) ) );
        $match_count  = count( $matching );
        $total        = count( $eval['all_evaluations'] );
        $fetch_errors = $eval['errors'] ?? [];

        if ( empty( $matching ) ) {
            $result = [
                'status'  => 'no_match',
                'message' => "No countries matched conditions ({$total} evaluated).",
                'countries_removed' => 0,
                'details' => $eval['all_evaluations'],
                'errors'  => $fetch_errors,
            ];
            self::store_result( $rule_id, $result );

            Logger::log( 'info', 'rule', "Rule \"{$rule->post_title}\": no countries matched ({$total} evaluated)", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
            ] );

            return $result;
        }

        $action_result = RuleAction::execute( $action, $campaign_id, $matching );

        // Only the countries actually removed drive the timeline (skip ones not targeted).
        $affected = isset( $action_result['affected_zones'] ) && is_array( $action_result['affected_zones'] )
            ? array_values( array_map( 'strval', $action_result['affected_zones'] ) )
            : $matching;

        $result = [
            'status'            => $action_result['success'] ? 'executed' : 'failed',
            'message'           => $action_result['message'],
            'countries_removed' => count( $affected ),
            'matching_zones'    => $matching, // full matched set — for the last-run detail UI
            'details'           => $eval['all_evaluations'],
            'errors'            => $fetch_errors,
        ];

        self::store_result( $rule_id, $result );

        $log_type = $action_result['success'] ? 'info' : 'error';
        Logger::log( $log_type, 'rule', "Rule \"{$rule->post_title}\": {$action_result['message']} ({$match_count}/{$total} countries matched)", [
            'rule_id'        => $rule_id,
            'campaign_id'    => $campaign_id,
            'action'         => $action,
            'matching_zones' => $affected,
        ] );

        if ( empty( $action_result['no_change'] ) ) {
            $user_id = (int) $campaign->post_author;
            Notifications::add( $user_id, [
                'type'         => $action_result['success'] ? 'info' : 'error',
                'title'        => $rule->post_title,
                'message'      => $action_result['message'],
                'message_full' => $action_result['message_full'] ?? $action_result['message'],
                'time'         => current_time( 'mysql' ),
                'campaign_id'  => $campaign_id,
            ] );
        }

        if ( $action_result['success'] ) {
            self::handle_on_success( $rule->ID, $campaign->ID, $on_success );
        }

        return $result;
    }

    /**
     * Process a targeting-dimension rule (remove category / OS / browser / ISP /
     * form_factor / resolution). Parallels process_country_rule but the per-value
     * performance comes from the TRACKER (grouped by the dimension), and matched
     * values are removed from the traffic source's targeting for that dimension.
     */
    private static function process_targeting_rule(
        \WP_Post $rule,
        \WP_Post $campaign,
        string $action,
        string $dim,
        array $conditions,
        string $timeframe,
        array $on_success
    ): array {
        $rule_id     = $rule->ID;
        $campaign_id = $campaign->ID;

        if ( $dim === '' ) {
            $result = [ 'status' => 'skip', 'reason' => 'no_dimension', 'message' => 'Rule has no targeting dimension configured.' ];
            self::store_result( $rule_id, $result );
            return $result;
        }

        $eval = RuleCondition::evaluate_targeting( $conditions, $campaign_id, $timeframe, $dim );

        foreach ( $eval['all_evaluations'] as $v_eval ) {
            $status_str = $v_eval['passed'] ? 'MATCHED' : 'not matched';
            $values     = [];
            foreach ( $v_eval['details'] as $d ) {
                $values[] = "{$d['metric']} {$d['operator']} {$d['expected']} (actual: " . ( $d['actual'] ?? 'N/A' ) . ') → ' . ( $d['passed'] ? 'pass' : 'fail' );
            }
            Logger::log( 'debug', 'rule', "Rule \"{$rule->post_title}\" {$dim} {$v_eval['zone_id']}: {$status_str}", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
                'dimension'   => $dim,
                'value'       => $v_eval['zone_id'],
                'conditions'  => $values,
            ] );
        }

        $matching     = array_values( array_filter( array_map( 'strval', $eval['matching'] ) ) );
        $match_count  = count( $matching );
        $total        = count( $eval['all_evaluations'] );
        $fetch_errors = $eval['errors'] ?? [];

        if ( empty( $matching ) ) {
            $result = [
                'status'  => 'no_match',
                'message' => "No {$dim} matched conditions ({$total} evaluated).",
                'items_removed' => 0,
                'details' => $eval['all_evaluations'],
                'errors'  => $fetch_errors,
            ];
            self::store_result( $rule_id, $result );
            Logger::log( 'info', 'rule', "Rule \"{$rule->post_title}\": no {$dim} matched ({$total} evaluated)", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
            ] );
            return $result;
        }

        $action_result = RuleAction::execute( $action, $campaign_id, $matching, [ 'dimension' => $dim ] );

        $affected = isset( $action_result['affected_zones'] ) && is_array( $action_result['affected_zones'] )
            ? array_values( array_map( 'strval', $action_result['affected_zones'] ) )
            : $matching;

        // Display list for the result modal — the values ACTUALLY removed, as readable
        // names. Prefer the action's own labels (so a matched-but-unremovable value
        // like a Satellite connection speed with no PopAds equivalent isn't shown as
        // "affected"); fall back to the matched set for actions that don't provide them.
        $display_values = ! empty( $action_result['affected_labels'] )
            ? array_values( array_map( 'strval', $action_result['affected_labels'] ) )
            : self::labelize_targeting_values( $campaign_id, $dim, $matching );

        $result = [
            'status'         => $action_result['success'] ? 'executed' : 'failed',
            'message'        => $action_result['message'],
            'items_removed'  => count( $affected ),
            'dimension'      => $dim,
            'matching_zones' => $display_values,
            'details'        => $eval['all_evaluations'],
            'errors'         => $fetch_errors,
        ];
        self::store_result( $rule_id, $result );

        $log_type = $action_result['success'] ? 'info' : 'error';
        Logger::log( $log_type, 'rule', "Rule \"{$rule->post_title}\": {$action_result['message']} ({$match_count}/{$total} {$dim} matched)", [
            'rule_id'        => $rule_id,
            'campaign_id'    => $campaign_id,
            'action'         => $action,
            'dimension'      => $dim,
            'matching_zones' => $affected,
        ] );

        if ( empty( $action_result['no_change'] ) ) {
            $user_id = (int) $campaign->post_author;
            Notifications::add( $user_id, [
                'type'         => $action_result['success'] ? 'info' : 'error',
                'title'        => $rule->post_title,
                'message'      => $action_result['message'],
                'message_full' => $action_result['message_full'] ?? $action_result['message'],
                'time'         => current_time( 'mysql' ),
                'campaign_id'  => $campaign_id,
            ] );
        }

        if ( $action_result['success'] ) {
            self::handle_on_success( $rule->ID, $campaign->ID, $on_success );
        }

        return $result;
    }

    /**
     * Make a targeting-rule's matched values readable for the result modal. For
     * id-token dimensions (Category stores numeric ids), resolve each id → "Name
     * (id)"; other dims already carry human-readable values. Display-only — the
     * timeline log keeps the raw values.
     *
     * @param string[]|int[] $values
     * @return string[]
     */
    private static function labelize_targeting_values( int $campaign_id, string $dim, array $values ): array {
        $annotate = AjaxHandler::token_dim_registry()[ $dim ]['annotate'] ?? '';
        if ( $annotate !== 'id' ) {
            return array_map( 'strval', $values );
        }
        $slug = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $mod  = $slug ? ModuleRegistry::get_instance()->get_module_with_credentials( $slug ) : null;
        if ( ! $mod || ! method_exists( $mod, 'describe_ids' ) ) {
            return array_map( 'strval', $values );
        }
        try {
            $names = $mod->describe_ids( $dim, array_map( 'strval', $values ) );
            return array_map( function ( $v ) use ( $names ) {
                $v  = (string) $v;
                $nm = $names[ $v ] ?? '';
                return $nm !== '' ? ( $nm . ' (' . $v . ')' ) : $v;
            }, $values );
        } catch ( \Throwable $e ) {
            return array_map( 'strval', $values );
        }
    }

    /**
     * Process a campaign-level rule (pause/resume traffic, adjust bid).
     */
    private static function process_campaign_rule(
        \WP_Post $rule,
        \WP_Post $campaign,
        string $action,
        array $conditions,
        string $timeframe,
        array $on_success,
        array $action_params = []
    ): array {
        $rule_id     = $rule->ID;
        $campaign_id = $campaign->ID;

        $condition_result = RuleCondition::evaluate_campaign( $conditions, $campaign_id, $timeframe );

        if ( ! $condition_result['passed'] ) {
            $result = [
                'status'  => 'no_match',
                'message' => 'Conditions not met.',
                'details' => $condition_result['details'],
            ];
            self::store_result( $rule_id, $result );

            Logger::log( 'info', 'rule', "Rule \"{$rule->post_title}\": conditions not met", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
                'details'     => $condition_result,
            ] );

            return $result;
        }

        // Execute action.
        $action_result = RuleAction::execute( $action, $campaign_id, [], $action_params );

        $result = [
            'status'  => $action_result['success'] ? 'executed' : 'failed',
            'action'  => $action,
            'message' => $action_result['message'],
            'details' => $condition_result['details'],
        ];

        self::store_result( $rule_id, $result );

        $log_type = $action_result['success'] ? 'info' : 'error';
        Logger::log( $log_type, 'rule', "Rule \"{$rule->post_title}\": action \"{$action}\" — " . $action_result['message'], [
            'rule_id'     => $rule_id,
            'campaign_id' => $campaign_id,
            'action'      => $action,
        ] );

        // Notification.
        $user_id = (int) $campaign->post_author;
        Notifications::add( $user_id, [
            'type'         => $action_result['success'] ? 'info' : 'error',
            'title'        => $rule->post_title,
            'message'      => $action_result['message'],
            'message_full' => $action_result['message_full'] ?? $action_result['message'],
            'time'         => current_time( 'mysql' ),
            'campaign_id'  => $campaign_id,
        ] );

        // Handle on_success flags.
        if ( $action_result['success'] ) {
            self::handle_on_success( $rule->ID, $campaign->ID, $on_success );
        }

        return $result;
    }

    /**
     * Process an offer-status rule (check live offer status, pause/resume traffic).
     */
    private static function process_offer_status_rule(
        \WP_Post $rule,
        \WP_Post $campaign,
        string $action,
        array $conditions,
        array $on_success
    ): array {
        $rule_id     = $rule->ID;
        $campaign_id = $campaign->ID;

        $an_slug  = get_post_meta( $campaign_id, '_optee_affiliate_network_module', true );
        $offer_id = get_post_meta( $campaign_id, '_optee_offer_id', true );

        if ( ! $an_slug || ! $offer_id ) {
            $result = [ 'status' => 'skip', 'reason' => 'no_affiliate_network', 'message' => 'Campaign has no affiliate network or offer ID configured.' ];
            self::store_result( $rule_id, $result );
            Logger::log( 'warning', 'rule', "Rule \"{$rule->post_title}\": no affiliate network or offer ID on campaign", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
            ] );
            return $result;
        }

        $registry  = ModuleRegistry::get_instance();
        $an_module = $registry->get_module_with_credentials( $an_slug );

        if ( ! $an_module || ! method_exists( $an_module, 'get_offer_status' ) ) {
            $result = [ 'status' => 'skip', 'reason' => 'module_not_found', 'message' => 'Affiliate network module not found or does not support offer status.' ];
            self::store_result( $rule_id, $result );
            Logger::log( 'warning', 'rule', "Rule \"{$rule->post_title}\": affiliate network module \"{$an_slug}\" not available", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
            ] );
            return $result;
        }

        $offer_info       = $an_module->get_offer_status( $offer_id );
        $offer_status_str = $offer_info['active'] ? 'active' : ( $offer_info['status'] ?? 'paused' );
        $offer_name       = $offer_id;

        // Compare: condition value "running" matches active=true, "paused" matches active=false.
        $condition_value       = $conditions[0]['value'] ?? 'running';
        $actual_matches_running = $offer_info['active'];
        $passed                = ( $condition_value === 'running' ) ? $actual_matches_running : ! $actual_matches_running;

        Logger::log( 'debug', 'rule', "Rule \"{$rule->post_title}\" offer status check: offer \"{$offer_name}\" is {$offer_status_str}, condition wants \"{$condition_value}\" → " . ( $passed ? 'MATCH' : 'no match' ), [
            'rule_id'      => $rule_id,
            'campaign_id'  => $campaign_id,
            'offer_id'     => $offer_id,
            'offer_status' => $offer_status_str,
        ] );

        if ( ! $passed ) {
            $result = [
                'status'       => 'no_match',
                'message'      => "Offer \"{$offer_name}\" is {$offer_status_str} — condition not met.",
                'offer_name'   => $offer_name,
                'offer_status' => $offer_status_str,
            ];
            self::store_result( $rule_id, $result );
            return $result;
        }

        // Execute action.
        $action_result = RuleAction::execute( $action, $campaign_id );

        $result = [
            'status'       => $action_result['success'] ? 'executed' : 'failed',
            'action'       => $action,
            'message'      => $action_result['message'],
            'offer_name'   => $offer_name,
            'offer_status' => $offer_status_str,
        ];

        self::store_result( $rule_id, $result );

        $log_type = $action_result['success'] ? 'info' : 'error';
        Logger::log( $log_type, 'rule', "Rule \"{$rule->post_title}\": offer \"{$offer_name}\" is {$offer_status_str} → action \"{$action}\" — {$action_result['message']}", [
            'rule_id'     => $rule_id,
            'campaign_id' => $campaign_id,
            'offer_id'    => $offer_id,
            'action'      => $action,
        ] );

        // Notification.
        $user_id = (int) $campaign->post_author;
        Notifications::add( $user_id, [
            'type'         => $action_result['success'] ? 'info' : 'error',
            'title'        => $rule->post_title,
            'message'      => $action_result['message'],
            'message_full' => $action_result['message_full'] ?? $action_result['message'],
            'time'         => current_time( 'mysql' ),
            'campaign_id'  => $campaign_id,
        ] );

        // Handle on_success flags.
        if ( $action_result['success'] ) {
            self::handle_on_success( $rule->ID, $campaign->ID, $on_success );
        }

        return $result;
    }

    /**
     * Store last run result as JSON meta.
     */
    private static function store_result( int $rule_id, array $result ): void {
        update_post_meta( $rule_id, '_optee_rule_last_run_result', $result );
    }

    /**
     * Handle on_success flags (pause rule, pause campaign).
     */
    private static function handle_on_success( int $rule_id, int $campaign_id, array $on_success ): void {
        if ( in_array( 'pause_rule', $on_success, true ) ) {
            update_post_meta( $rule_id, '_optee_rule_enabled', '0' );
            Logger::log( 'info', 'rule', "Rule auto-paused after successful execution (rule ID {$rule_id})", [
                'rule_id' => $rule_id,
            ] );
        }

        if ( in_array( 'pause_campaign', $on_success, true ) ) {
            $result = RuleAction::execute( 'pause_traffic', $campaign_id );
            Logger::log( 'info', 'rule', "Campaign auto-paused after successful rule execution (campaign ID {$campaign_id}): {$result['message']}", [
                'rule_id'     => $rule_id,
                'campaign_id' => $campaign_id,
            ] );
        }
    }
}
