<?php

namespace Optee\Plugin\CPT;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CampaignCpt {

    /**
     * Register the custom post type.
     */
    public function register(): void {
        add_action( 'init', [ $this, 'register_post_type' ] );
    }

    /**
     * Register optee_campaign CPT.
     */
    public function register_post_type(): void {
        register_post_type( 'optee_campaign', [
            'labels'       => [
                'name'          => __( 'Campaigns', 'optee' ),
                'singular_name' => __( 'Campaign', 'optee' ),
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
     * Meta keys used by campaigns.
     */
    public static function meta_keys(): array {
        return [
            '_optee_traffic_source_module',
            '_optee_traffic_source_campaign_id',
            '_optee_traffic_source_campaign_name',
            '_optee_tracker_module',
            '_optee_affiliate_network_module',
            '_optee_antifraud_module',
            '_optee_monetization_module',
            '_optee_status',
            '_optee_created_by',
        ];
    }
}
