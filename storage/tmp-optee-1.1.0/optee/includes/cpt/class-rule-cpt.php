<?php

namespace Optee\Plugin\CPT;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RuleCpt {

    /**
     * Register the custom post type.
     */
    public function register(): void {
        add_action( 'init', [ $this, 'register_post_type' ] );
    }

    /**
     * Register optee_rule CPT.
     */
    public function register_post_type(): void {
        register_post_type( 'optee_rule', [
            'labels'       => [
                'name'          => __( 'Rules', 'optee' ),
                'singular_name' => __( 'Rule', 'optee' ),
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
     * Meta keys used by rules.
     */
    public static function meta_keys(): array {
        return [
            '_optee_rule_campaign_id',
            '_optee_rule_action',
            '_optee_rule_conditions',
            '_optee_rule_interval',
            '_optee_rule_timeframe',
            '_optee_rule_last_run',
            '_optee_rule_enabled',
            '_optee_rule_created_by',
            '_optee_rule_on_success',
            '_optee_rule_is_template',
            '_optee_rule_last_run_result',
        ];
    }
}
