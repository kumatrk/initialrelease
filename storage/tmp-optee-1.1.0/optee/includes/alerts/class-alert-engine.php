<?php

namespace Optee\Plugin\Alerts;

use Optee\Plugin\Logger;
use Optee\Plugin\Notifications;
use Optee\Plugin\EmailTemplate;
use Optee\Plugin\CustomColumns;
use Optee\Plugin\Rules\RuleCondition;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Evaluates + dispatches user-configured Alerts.
 *
 * Phase 1: balance threshold alerts. When the cron balance task refreshes a
 * module's balance, it calls check_balance(); any enabled balance alert for that
 * (user, module) whose threshold is breached fires — at most ONCE PER CALENDAR
 * DAY per alert (WP-local date). It re-arms the next day.
 */
class AlertEngine {

    /**
     * Evaluate all enabled balance alerts for a freshly-updated module balance.
     * Called from the cron balance task right after the balance row is upserted.
     */
    public static function check_balance( int $user_id, string $module_type, string $module_slug, string $module_name, float $balance, string $currency ): void {
        $alerts = get_posts( [
            'post_type'      => 'optee_alert',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_optee_alert_enabled',     'value' => '1' ],
                [ 'key' => '_optee_alert_type',        'value' => 'balance' ],
                [ 'key' => '_optee_alert_module_type', 'value' => $module_type ],
                [ 'key' => '_optee_alert_module_slug', 'value' => $module_slug ],
            ],
        ] );

        if ( empty( $alerts ) ) {
            return;
        }

        $today = current_time( 'Y-m-d' );

        foreach ( $alerts as $alert ) {
            $direction = get_post_meta( $alert->ID, '_optee_alert_direction', true ) ?: 'below';
            $threshold = (float) get_post_meta( $alert->ID, '_optee_alert_threshold', true );

            $dir_word = $direction === 'above' ? 'above' : 'below';
            $breached = ( $direction === 'above' ) ? ( $balance > $threshold ) : ( $balance < $threshold );
            if ( ! $breached ) {
                // Diagnostic (deduped to once/day/alert — balances refresh ~every 30 min).
                self::log_nofire_once_per_day( $alert->ID, $today,
                    "Balance alert did not fire: {$module_name} balance is " . self::money( $balance, $currency ) .
                        " — not {$dir_word} " . self::money( $threshold, $currency ) . '.',
                    [
                        'alert_id'  => $alert->ID,
                        'user_id'   => $user_id,
                        'reason'    => 'threshold_not_breached',
                        'balance'   => $balance,
                        'threshold' => $threshold,
                        'direction' => $direction,
                    ]
                );
                continue;
            }

            // Daily cap: fire at most once per calendar day per alert.
            $last_fired_date = get_post_meta( $alert->ID, '_optee_alert_last_fired_date', true );
            if ( $last_fired_date === $today ) {
                self::log_nofire_once_per_day( $alert->ID, $today,
                    "Balance alert did not fire: {$module_name} threshold breached but already notified today (daily cap).",
                    [ 'alert_id' => $alert->ID, 'user_id' => $user_id, 'reason' => 'daily_cap' ]
                );
                continue;
            }

            self::fire_balance_alert( $alert->ID, $user_id, $module_name, $direction, $threshold, $balance, $currency );

            update_post_meta( $alert->ID, '_optee_alert_last_fired_date', $today );
            update_post_meta( $alert->ID, '_optee_alert_last_fired_at', current_time( 'mysql' ) );
            update_post_meta( $alert->ID, '_optee_alert_last_value', $balance );
        }
    }

    /**
     * Dispatch a balance alert through its configured channels (bell + email).
     */
    private static function fire_balance_alert( int $alert_id, int $user_id, string $module_name, string $direction, float $threshold, float $balance, string $currency ): void {
        $channels = self::channels( $alert_id );
        $dir_word = $direction === 'above' ? 'above' : 'below';
        $bal_str  = self::money( $balance, $currency );
        $thr_str  = self::money( $threshold, $currency );

        $title   = "Balance alert: {$module_name}";
        $message = "{$module_name} balance is {$bal_str} ({$dir_word} {$thr_str}).";

        if ( in_array( 'bell', $channels, true ) ) {
            Notifications::add( $user_id, [
                'type'         => 'warning',
                'title'        => $title,
                'message'      => $message,
                'message_full' => $message,
                'time'         => current_time( 'mysql' ),
            ] );
        }

        if ( in_array( 'email', $channels, true ) ) {
            self::send_email( $user_id, $module_name, $dir_word, $threshold, $balance, $currency );
        }

        Logger::log( 'info', 'alert', "Balance alert fired: {$message}", [
            'alert_id' => $alert_id,
            'user_id'  => $user_id,
            'channels' => implode( ',', $channels ),
        ] );
    }

    /**
     * Send the balance-alert email to the alert owner.
     */
    private static function send_email( int $user_id, string $module_name, string $direction, float $threshold, float $balance, string $currency ): void {
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return;
        }

        $subject = sprintf( 'Optee — %s balance %s %s', $module_name, $direction, self::money( $threshold, $currency ) );
        $html    = EmailTemplate::balance_alert( $module_name, $direction, $threshold, $balance, $currency );

        $html_type = function () { return 'text/html'; };
        add_filter( 'wp_mail_content_type', $html_type );
        $sent = wp_mail( $user->user_email, $subject, $html );
        remove_filter( 'wp_mail_content_type', $html_type );

        do_action( 'optee_debug_event', 'alert.email.attempt', [
            'user_id'   => $user_id,
            'recipient' => $user->user_email,
            'subject'   => $subject,
            'sent'      => (bool) $sent,
        ] );
    }

    // ──────────────────────────────────────────────────────────────────
    // Report alerts (Phase 2) — per-campaign condition alerts, once per day
    // ──────────────────────────────────────────────────────────────────

    /**
     * Evaluate all enabled report alerts that are due today. Called each cron tick
     * by SystemTasks::run_report_alert_task(). An alert is "due" once the clock has
     * passed its daily schedule time AND it hasn't been checked yet today. Evaluated
     * via RuleCondition::evaluate_campaign() (which handles TS/AF/tracker + custom
     * columns); if ALL conditions pass, it fires. Returns the number of alerts fired.
     */
    public static function check_report_alerts(): int {
        $alerts = get_posts( [
            'post_type'      => 'optee_alert',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_optee_alert_enabled', 'value' => '1' ],
                [ 'key' => '_optee_alert_type',    'value' => 'report' ],
            ],
        ] );
        if ( empty( $alerts ) ) {
            return 0;
        }

        $today  = current_time( 'Y-m-d' );
        $now_hm = current_time( 'H:i' );
        $fired  = 0;

        foreach ( $alerts as $alert ) {
            // Once per day.
            if ( get_post_meta( $alert->ID, '_optee_alert_last_check_date', true ) === $today ) {
                continue;
            }
            // Scheduled time reached? (HH:MM string compare — both WP-local).
            $sched = get_post_meta( $alert->ID, '_optee_alert_schedule_time', true ) ?: '06:00';
            if ( strcmp( $now_hm, $sched ) < 0 ) {
                continue;
            }

            // Claim today's check immediately so a slow/failed eval doesn't re-run every tick.
            update_post_meta( $alert->ID, '_optee_alert_last_check_date', $today );

            $campaign_id = (int) get_post_meta( $alert->ID, '_optee_alert_campaign_id', true );
            $conditions  = get_post_meta( $alert->ID, '_optee_alert_conditions', true ) ?: [];
            $timeframe   = get_post_meta( $alert->ID, '_optee_alert_timeframe', true ) ?: 'yesterday';
            $campaign    = $campaign_id ? get_post( $campaign_id ) : null;
            if ( ! $campaign || empty( $conditions ) ) {
                continue;
            }

            // Stats fetch needs the owner's encrypted credentials → set user context.
            $owner    = (int) $alert->post_author;
            $original = get_current_user_id();
            if ( $owner > 0 && $owner !== $original ) {
                wp_set_current_user( $owner );
            }

            $eval_failed = false;
            try {
                $result = RuleCondition::evaluate_campaign( $conditions, $campaign_id, $timeframe );
            } catch ( \Throwable $e ) {
                Logger::log( 'error', 'alert', "Report alert eval failed: " . $e->getMessage(), [ 'alert_id' => $alert->ID ] );
                $result = [ 'passed' => false, 'details' => [] ];
                $eval_failed = true;
            }

            if ( $owner > 0 && $owner !== $original ) {
                wp_set_current_user( $original );
            }

            $campaign_title = html_entity_decode( $campaign->post_title, ENT_QUOTES, 'UTF-8' );

            if ( ! empty( $result['passed'] ) ) {
                self::fire_report_alert(
                    $alert->ID, $owner,
                    $campaign_title,
                    $campaign_id, $timeframe, self::label_details( $result['details'] ?? [], $owner )
                );
                update_post_meta( $alert->ID, '_optee_alert_last_fired_at', current_time( 'mysql' ) );
                $fired++;
            } elseif ( ! $eval_failed ) {
                // Diagnostic: record why the alert stayed silent today (checked once/day/alert).
                $labelled = self::label_details( $result['details'] ?? [], $owner );
                $summary  = self::conditions_summary( $labelled );
                Logger::log( 'info', 'alert',
                    "Report alert did not fire: {$campaign_title} — conditions not all met" . ( $summary ? " ({$summary})" : '' ),
                    [
                        'alert_id'    => $alert->ID,
                        'user_id'     => $owner,
                        'campaign_id' => $campaign_id,
                        'timeframe'   => $timeframe,
                        'reason'      => 'conditions_not_met',
                        'details'     => $labelled,
                    ]
                );
            }
        }

        return $fired;
    }

    /** Dispatch a report alert (bell + email). */
    private static function fire_report_alert( int $alert_id, int $user_id, string $campaign_name, int $campaign_id, string $timeframe, array $details ): void {
        $channels = self::channels( $alert_id );
        $tf_label = self::timeframe_label( $timeframe );
        $summary  = self::conditions_summary( $details );

        $title   = "Campaign alert: {$campaign_name}";
        $message = "{$campaign_name} ({$tf_label}) — {$summary}";

        if ( in_array( 'bell', $channels, true ) ) {
            Notifications::add( $user_id, [
                'type'         => 'warning',
                'title'        => $title,
                'message'      => $message,
                'message_full' => $message,
                'campaign_id'  => $campaign_id,
                'time'         => current_time( 'mysql' ),
            ] );
        }
        if ( in_array( 'email', $channels, true ) ) {
            self::send_report_email( $user_id, $campaign_name, $tf_label, $details );
        }

        Logger::log( 'info', 'alert', "Report alert fired: {$message}", [
            'alert_id' => $alert_id,
            'user_id'  => $user_id,
            'channels' => implode( ',', $channels ),
        ] );
    }

    private static function send_report_email( int $user_id, string $campaign_name, string $tf_label, array $details ): void {
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return;
        }
        $subject = sprintf( 'Optee — campaign alert: %s', $campaign_name );
        $html    = EmailTemplate::report_alert( $campaign_name, $tf_label, $details );

        $html_type = function () { return 'text/html'; };
        add_filter( 'wp_mail_content_type', $html_type );
        $sent = wp_mail( $user->user_email, $subject, $html );
        remove_filter( 'wp_mail_content_type', $html_type );

        do_action( 'optee_debug_event', 'alert.email.attempt', [
            'user_id'   => $user_id,
            'recipient' => $user->user_email,
            'subject'   => $subject,
            'sent'      => (bool) $sent,
        ] );
    }

    /** Human timeframe label. */
    public static function timeframe_label( string $tf ): string {
        return [ 'today' => 'today', 'yesterday' => 'yesterday', '24h' => 'last 24h' ][ $tf ] ?? $tf;
    }

    /**
     * Enrich each condition detail with a human-readable `label`, resolving custom
     * columns (cc__<id>) to their stored name for the alert owner — mirrors the
     * Preview modal so bell + email show "True Profit", not "cc__cc_fb6a0701".
     */
    private static function label_details( array $details, int $user_id ): array {
        foreach ( $details as &$d ) {
            if ( isset( $d['label'] ) && $d['label'] !== '' ) {
                continue;
            }
            $metric = (string) ( $d['metric'] ?? '' );
            if ( str_starts_with( $metric, 'cc__' ) ) {
                $col        = CustomColumns::find( $user_id, substr( $metric, 4 ) );
                $d['label'] = $col['name'] ?? $metric;
            } else {
                $d['label'] = self::metric_label( $metric );
            }
        }
        unset( $d );
        return $details;
    }

    /** Short one-line summary of the triggered conditions for bell/log. */
    private static function conditions_summary( array $details ): string {
        $parts = [];
        foreach ( $details as $d ) {
            $label  = (string) ( $d['label'] ?? self::metric_label( (string) ( $d['metric'] ?? '' ) ) );
            $op     = (string) ( $d['operator'] ?? '' );
            $actual = is_numeric( $d['actual'] ?? null ) ? rtrim( rtrim( number_format( (float) $d['actual'], 2 ), '0' ), '.' ) : 'N/A';
            $exp    = rtrim( rtrim( number_format( (float) ( $d['expected'] ?? 0 ), 2 ), '0' ), '.' );
            $parts[] = "{$label} {$actual} ({$op} {$exp})";
        }
        return implode( ', ', $parts );
    }

    /** Metric key → readable label (built-ins; custom columns pass through). */
    public static function metric_label( string $metric ): string {
        static $map = [
            'impressions' => 'Impressions', 'clicks' => 'Clicks', 'conversions' => 'Conversions',
            'cost' => 'Cost', 'payout' => 'Payout', 'profit' => 'Profit', 'roi' => 'ROI',
            'cpm' => 'CPM', 'cpc' => 'CPC', 'cpa' => 'CPA', 'ctr' => 'CTR', 'cr' => 'CR',
            'af_visits' => 'AF Visits', 'valid_hits' => 'Valid Hits', 'valid_percent' => 'Valid %',
            'traffic_loss' => 'Traffic Loss %', 'proxy_percent' => 'Proxy %', 'junk_percent' => 'Junk %',
            'bot_percent' => 'Bot %', 'af_cost' => 'AF Cost',
            'tr_visits' => 'Tracker Visits', 'tr_clicks' => 'Tracker Clicks', 'tr_conv' => 'Tracker Conversions',
            'tr_revenue' => 'Tracker Revenue', 'tr_cost' => 'Tracker Cost', 'tr_profit' => 'Tracker Profit',
            'tr_roi' => 'Tracker ROI', 'tr_ctr' => 'Tracker CTR', 'tr_cv' => 'Tracker CV',
            'tr_cpm' => 'Tracker CPM', 'tr_cpa' => 'Tracker CPA', 'tr_ap' => 'Tracker Avg Payout',
        ];
        return $map[ $metric ] ?? $metric;
    }

    /**
     * Log a "did not fire" diagnostic at most once per calendar day per alert.
     * Balance alerts re-evaluate every ~30 min, so without this dedupe a silent
     * alert would emit ~48 log rows/day — this keeps it to one daily snapshot.
     */
    private static function log_nofire_once_per_day( int $alert_id, string $today, string $message, array $context ): void {
        if ( get_post_meta( $alert_id, '_optee_alert_last_nofire_date', true ) === $today ) {
            return;
        }
        update_post_meta( $alert_id, '_optee_alert_last_nofire_date', $today );
        Logger::log( 'info', 'alert', $message, $context );
    }

    /** Channels for an alert (defaults to both if unset). */
    private static function channels( int $alert_id ): array {
        $raw = (string) get_post_meta( $alert_id, '_optee_alert_channels', true );
        if ( $raw === '' ) {
            return [ 'email', 'bell' ];
        }
        return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
    }

    /** Format a money value with a currency prefix, e.g. "$50.00" / "12.34 EUR". */
    private static function money( float $value, string $currency ): string {
        $currency = strtoupper( trim( $currency ) ?: 'USD' );
        $num      = number_format( $value, 2 );
        return $currency === 'USD' ? '$' . $num : $num . ' ' . $currency;
    }
}
