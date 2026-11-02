<?php

namespace Optee\Plugin\CPT;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AlertCpt {

    /**
     * Register the custom post type.
     */
    public function register(): void {
        add_action( 'init', [ $this, 'register_post_type' ] );
    }

    /**
     * Register optee_alert CPT.
     */
    public function register_post_type(): void {
        register_post_type( 'optee_alert', [
            'labels'       => [
                'name'          => __( 'Alerts', 'optee' ),
                'singular_name' => __( 'Alert', 'optee' ),
            ],
            'public'       => false,
            'show_ui'      => false,
            'show_in_rest' => false,
            'supports'     => [ 'title' ],
            'has_archive'  => false,
            'rewrite'      => false,
            'query_var'    => false,
        ] );
    }

    /**
     * Meta keys used by alerts.
     */
    public static function meta_keys(): array {
        return [
            '_optee_alert_type',          // 'balance' (phase 1) | 'report' (phase 2)
            // Balance alert:
            '_optee_alert_module_type',   // traffic_source | affiliate_network | monetization_platform
            '_optee_alert_module_slug',
            '_optee_alert_direction',     // 'below' | 'above'
            '_optee_alert_threshold',     // float
            // Report alert (per-campaign):
            '_optee_alert_campaign_id',   // int
            '_optee_alert_conditions',    // array of { source, metric, operator, value } (AND)
            '_optee_alert_timeframe',     // today | yesterday | 24h
            '_optee_alert_schedule_time', // 'HH:MM' (WP-local) — daily check time
            '_optee_alert_last_check_date', // Y-m-d (WP local) — once-per-day gate
            // Shared:
            '_optee_alert_channels',      // csv: 'email,bell'
            '_optee_alert_enabled',       // '1' | '0'
            '_optee_alert_last_fired_date', // Y-m-d (WP local) — daily dedupe cap (balance)
            '_optee_alert_last_fired_at',   // mysql datetime (display)
            '_optee_alert_last_value',      // balance value when it last fired (display)
        ];
    }
}
