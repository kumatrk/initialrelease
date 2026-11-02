<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AjaxHandler {

    public function init(): void {
        $actions = [
            'optee_save_sidebar_state',
            'optee_get_campaigns',
            'optee_create_campaign',
            'optee_update_campaign',
            'optee_delete_campaign',
            'optee_get_rules',
            'optee_create_rule',
            'optee_update_rule',
            'optee_delete_rule',
            'optee_duplicate_rule',
            'optee_toggle_rule',
            'optee_snooze_rule',
            'optee_reset_rule_last_run',
            'optee_get_alerts',
            'optee_save_alert',
            'optee_delete_alert',
            'optee_toggle_alert',
            'optee_preview_alert',
            'optee_get_rule_templates',
            'optee_preview_rule',
            'optee_preview_rule_draft',
            'optee_run_rule',
            'optee_save_credentials',
            'optee_test_connection',
            'optee_delete_credentials',
            'optee_get_notifications',
            'optee_dismiss_notification',
            'optee_dismiss_all_notifications',
            'optee_get_logs',
            'optee_clear_logs',
            'optee_get_traffic_source_campaigns',
            'optee_get_tracker_campaigns',
            'optee_get_tracker_zones',
            'optee_get_tracker_traffic_sources',
            'optee_get_tracker_campaign_options',
            'optee_get_antifraud_campaigns',
            'optee_activate_plugin',
            'optee_deactivate_plugin',
            'optee_get_dashboard_stats',
            'optee_get_campaign_stats',
            'optee_get_campaign_perday',
            'optee_save_perday_config',
            'optee_save_cache_ttl',
            'optee_get_balances',
            'optee_fetch_balances_now',
            'optee_get_manual_balance_modules',
            'optee_save_manual_balance_modules',
            'optee_save_manual_balance',
            'optee_get_earnings',
            'optee_fetch_earnings_now',
            'optee_vt_save_url',
            'optee_vt_edit_url',
            'optee_vt_delete_url',
            'optee_vt_get_urls',
            'optee_vt_save_interval',
            'optee_vt_refresh_url',
            'optee_vt_toggle_url_status',
            'optee_vt_key_info',
            'optee_vt_usage_history',
            'optee_check_offer_status',
            'optee_get_cron_status',
            'optee_save_heartbeat_interval',
            'optee_save_display_prefs',
            'optee_reset_automation_lock',
            'optee_send_test_email',
            'optee_save_lock_timeout',
            'optee_save_max_execution_time',
            'optee_toggle_zone',
            'optee_toggle_country',
            'optee_toggle_targeting',
            'optee_toggle_campaign_status',
            'optee_get_monetag_zones',
            'optee_set_monetag_source',
            'optee_get_propush_zones',
            'optee_get_propush_sites',
            'optee_set_propush_source',
            'optee_set_partners_house_source',
            'optee_get_rap_sites',
            'optee_get_rap_zones',
            'optee_set_rap_source',
            'optee_get_hta_sites',
            'optee_get_hta_zones',
            'optee_set_hta_source',
            'optee_get_monetizer_mon_leagues',
            'optee_get_monetizer_mon_campaigns',
            'optee_set_monetizer_mon_source',
            'optee_set_zeydoo_mon_source',
            'optee_save_timezone',
            'optee_save_cache_buster',
            'optee_get_notes',
            'optee_save_note',
            'optee_delete_note',
            'optee_get_token_mapping',
            'optee_save_token_mapping',
            'optee_get_rule_targeting_dims',
            'optee_search_campaigns',
            'optee_check_campaign_whitelist',
            'optee_get_notifications_archive',
            'optee_clear_notifications_archive',
            'optee_validate_activation_key',
            'optee_transfer_activation_key',
            'optee_get_activation_info',
            'optee_install_plugin_from_api',
            'optee_update_plugin_from_api',
            'optee_check_plugin_updates',
            'optee_get_available_plugins',
            'optee_get_tools',
            'optee_request_tool',
            'optee_install_tool_from_api',
            'optee_update_tool_from_api',
            'optee_activate_tool',
            'optee_get_support_updates',
            'optee_mark_support_read',
            'optee_wizard_save_step',
            'optee_wizard_detect_cpanel',
            'optee_wizard_cpanel_cron',
            'optee_wizard_detect_directadmin',
            'optee_wizard_check_cron',
            'optee_wizard_send_verify_email',
            'optee_wizard_check_email',
            'optee_wizard_complete',
            'optee_get_ts_statuses',
            'optee_wizard_system_check',
            'optee_wizard_fix_issue',
            'optee_load_zone_status',
            'optee_get_promo_campaigns',
            'optee_submit_promo_request',
            'optee_get_promo_my_requests',
            'optee_dismiss_promo_popup',
            'optee_dismiss_cache_warning',
            'optee_tour_seen',
            'optee_get_news',
            'optee_dismiss_news',
            'optee_list_custom_columns',
            'optee_save_custom_column',
            'optee_delete_custom_column',
            'optee_reorder_custom_columns',
            'optee_toggle_custom_column_assignment',
            'optee_reset_custom_column_override',
            'optee_set_uninstall_action',
            'optee_get_insights',
            'optee_refresh_insights',
        ];

        foreach ( $actions as $action ) {
            $method = str_replace( 'optee_', '', $action );
            add_action( "wp_ajax_{$action}", [ $this, $method ] );
        }
    }

    /**
     * Verify nonce and admin role.
     */
    private function verify(): void {
        if ( ! check_ajax_referer( 'optee_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }
    }

    // ──────────────────────────────────────────
    // Sidebar
    // ──────────────────────────────────────────

    public function save_sidebar_state(): void {
        $this->verify();
        $collapsed = sanitize_text_field( $_POST['collapsed'] ?? '0' );
        update_user_meta( get_current_user_id(), 'optee_sidebar_collapsed', $collapsed );
        wp_send_json_success();
    }

    public function tour_seen(): void {
        $this->verify();
        update_user_meta( get_current_user_id(), 'optee_tour_seen', '1' );
        wp_send_json_success();
    }

    // ──────────────────────────────────────────
    // Campaigns
    // ──────────────────────────────────────────

    public function get_campaigns(): void {
        $this->verify();
        $user_id = get_current_user_id();

        $args = [
            'post_type'      => 'optee_campaign',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => intval( $_POST['per_page'] ?? 20 ),
            'paged'          => intval( $_POST['page'] ?? 1 ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $status_filter = sanitize_text_field( $_POST['status'] ?? '' );
        if ( $status_filter ) {
            $args['meta_query'] = [
                [
                    'key'   => '_optee_status',
                    'value' => $status_filter,
                ],
            ];
        }

        $query     = new \WP_Query( $args );
        $campaigns = [];

        foreach ( $query->posts as $post ) {
            $campaigns[] = self::format_campaign( $post );
        }

        wp_send_json_success( [
            'campaigns' => $campaigns,
            'total'     => $query->found_posts,
            'pages'     => $query->max_num_pages,
        ] );
    }

    public function create_campaign(): void {
        $this->verify();

        $title = sanitize_text_field( $_POST['title'] ?? '' );
        if ( empty( $title ) ) {
            wp_send_json_error( [ 'message' => 'Campaign title is required.' ] );
        }

        $post_id = wp_insert_post( [
            'post_type'   => 'optee_campaign',
            'post_title'  => $title,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ] );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
        }

        // Save meta fields.
        $meta_fields = [
            'traffic_source_module'      => '_optee_traffic_source_module',
            'traffic_source_campaign_id' => '_optee_traffic_source_campaign_id',
            'traffic_source_campaign_name' => '_optee_traffic_source_campaign_name',
            'tracker_module'               => '_optee_tracker_module',
            'tracker_campaign_id'          => '_optee_tracker_campaign_id',
            'tracker_campaign_name'        => '_optee_tracker_campaign_name',
            'tracker_ts_id'                => '_optee_tracker_ts_id',
            'tracker_ts_name'              => '_optee_tracker_ts_name',
            'tracker_campaign_ids'         => '_optee_tracker_campaign_ids',
            'zone_id'                      => '_optee_zone_id',
            'zone_name'                    => '_optee_zone_name',
            'affiliate_network_module'     => '_optee_affiliate_network_module',
            'antifraud_module'             => '_optee_antifraud_module',
            'antifraud_campaign_id'        => '_optee_antifraud_campaign_id',
            'antifraud_campaign_name'      => '_optee_antifraud_campaign_name',
            'monetization_module'          => '_optee_monetization_module',
            'offer_id'                     => '_optee_offer_id',
            'is_smartlink'                 => '_optee_is_smartlink',
        ];

        foreach ( $meta_fields as $param => $meta_key ) {
            $value = sanitize_text_field( $_POST[ $param ] ?? '' );
            if ( $value !== '' ) {
                update_post_meta( $post_id, $meta_key, $value );
            }
        }

        self::apply_tracker_ts_composite( $post_id );
        self::save_ts_campaign_members( $post_id );

        update_post_meta( $post_id, '_optee_status', 'active' );
        update_post_meta( $post_id, '_optee_created_by', get_current_user_id() );

        Logger::log( 'info', 'campaign', "Campaign created: {$title}", [
            'campaign_id' => $post_id,
        ] );

        wp_send_json_success( [
            'campaign' => self::format_campaign( get_post( $post_id ) ),
            'message'  => 'Campaign created successfully.',
        ] );
    }

    public function update_campaign(): void {
        $this->verify();

        $post_id = intval( $_POST['campaign_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $title = sanitize_text_field( $_POST['title'] ?? '' );
        if ( $title ) {
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
        }

        $meta_fields = [
            'traffic_source_module'        => '_optee_traffic_source_module',
            'traffic_source_campaign_id'   => '_optee_traffic_source_campaign_id',
            'traffic_source_campaign_name' => '_optee_traffic_source_campaign_name',
            'tracker_module'               => '_optee_tracker_module',
            'tracker_campaign_id'          => '_optee_tracker_campaign_id',
            'tracker_campaign_name'        => '_optee_tracker_campaign_name',
            'tracker_ts_id'                => '_optee_tracker_ts_id',
            'tracker_ts_name'              => '_optee_tracker_ts_name',
            'tracker_campaign_ids'         => '_optee_tracker_campaign_ids',
            'zone_id'                      => '_optee_zone_id',
            'zone_name'                    => '_optee_zone_name',
            'affiliate_network_module'     => '_optee_affiliate_network_module',
            'antifraud_module'             => '_optee_antifraud_module',
            'antifraud_campaign_id'        => '_optee_antifraud_campaign_id',
            'antifraud_campaign_name'      => '_optee_antifraud_campaign_name',
            'monetization_module'          => '_optee_monetization_module',
            'offer_id'                     => '_optee_offer_id',
            'is_smartlink'                 => '_optee_is_smartlink',
            'status'                       => '_optee_status',
        ];

        foreach ( $meta_fields as $param => $meta_key ) {
            if ( isset( $_POST[ $param ] ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( $_POST[ $param ] ) );
            }
        }

        self::apply_tracker_ts_composite( $post_id );
        self::save_ts_campaign_members( $post_id );

        $local_status = sanitize_text_field( $_POST['status'] ?? '' );
        if ( $local_status === 'paused' ) {
            Logger::log( 'info', 'campaign', "Local campaign paused: {$post->post_title}", [
                'campaign_id' => $post_id,
            ] );
        } elseif ( $local_status === 'active' ) {
            Logger::log( 'info', 'campaign', "Local campaign resumed: {$post->post_title}", [
                'campaign_id' => $post_id,
            ] );
        } else {
            Logger::log( 'info', 'campaign', "Campaign updated: {$post->post_title}", [
                'campaign_id' => $post_id,
            ] );
        }

        wp_send_json_success( [
            'campaign' => self::format_campaign( get_post( $post_id ) ),
            'message'  => 'Campaign updated successfully.',
        ] );
    }

    /**
     * Encode an optional tracker traffic-source filter into the stored tracker
     * campaign id as "{campaignId}|{trafficSourceId}".
     *
     * Downstream stats calls (campaign report, dashboard batch, rules engine,
     * conversions) all read _optee_tracker_campaign_id verbatim and hand it to the
     * tracker module, so encoding the filter here scopes every path at once with
     * no per-call-site changes. Trackers that don't understand the composite
     * (everything except FunnelFlux today) simply never receive a ts id. An empty
     * ts id leaves the plain campaign id untouched (whole-funnel behaviour).
     *
     * Format: "{campaignId}|{trafficSourceId}|{campaignId,campaignId…}". The third
     * segment is an optional comma list of external campaign ids (FunnelFlux's
     * {campaign} field) so a single funnel can be split by the traffic-source
     * campaign that drove it — several campaigns can point at one funnel.
     */
    private static function apply_tracker_ts_composite( int $post_id ): void {
        $campaign = (string) get_post_meta( $post_id, '_optee_tracker_campaign_id', true );
        if ( $campaign === '' ) {
            return;
        }
        // Strip any existing composite first so re-saving never double-encodes.
        $base  = explode( '|', $campaign )[0];
        $ts_id = (string) get_post_meta( $post_id, '_optee_tracker_ts_id', true );

        // Normalize the campaign-id list: trim, drop blanks, comma-join.
        $cids_raw = (string) get_post_meta( $post_id, '_optee_tracker_campaign_ids', true );
        $cids     = implode( ',', array_filter( array_map( 'trim', explode( ',', $cids_raw ) ), 'strlen' ) );

        $value = $base;
        if ( $ts_id !== '' || $cids !== '' ) {
            $value = $base . '|' . $ts_id;
            if ( $cids !== '' ) {
                $value .= '|' . $cids;
            }
        }
        update_post_meta( $post_id, '_optee_tracker_campaign_id', $value );
    }

    /**
     * Persist the per-creative member list for a grouped traffic-source campaign.
     *
     * Stores `_optee_ts_campaign_members` as a JSON array of {id, name} so the
     * campaign report can offer an "All / single creative" dropdown without an
     * extra API call. Sources that don't group (no `traffic_source_campaign_members`
     * POST field) leave any existing value untouched-on-create / cleared-on-empty.
     */
    private static function save_ts_campaign_members( int $post_id ): void {
        if ( ! isset( $_POST['traffic_source_campaign_members'] ) ) {
            return;
        }

        $raw     = wp_unslash( $_POST['traffic_source_campaign_members'] );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;

        $clean = [];
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $m ) {
                $id = isset( $m['id'] ) ? sanitize_text_field( (string) $m['id'] ) : '';
                if ( $id === '' ) {
                    continue;
                }
                $clean[] = [
                    'id'   => $id,
                    'name' => isset( $m['name'] ) ? sanitize_text_field( (string) $m['name'] ) : $id,
                ];
            }
        }

        if ( empty( $clean ) ) {
            delete_post_meta( $post_id, '_optee_ts_campaign_members' );
            return;
        }

        update_post_meta( $post_id, '_optee_ts_campaign_members', wp_json_encode( $clean ) );
    }

    public function delete_campaign(): void {
        $this->verify();

        $post_id = intval( $_POST['campaign_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        // Delete associated rules.
        $rules = get_posts( [
            'post_type'      => 'optee_rule',
            'author'         => get_current_user_id(),
            'meta_key'       => '_optee_rule_campaign_id',
            'meta_value'     => $post_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
        foreach ( $rules as $rule_id ) {
            wp_delete_post( $rule_id, true );
        }

        $title = $post->post_title;
        wp_delete_post( $post_id, true );

        Logger::log( 'info', 'campaign', "Campaign deleted: {$title}", [
            'campaign_id' => $post_id,
        ] );

        wp_send_json_success( [ 'message' => 'Campaign deleted successfully.' ] );
    }

    // ──────────────────────────────────────────
    // Rules
    // ──────────────────────────────────────────

    public function get_rules(): void {
        $this->verify();
        $user_id = get_current_user_id();
        $grouped = intval( $_POST['grouped'] ?? 0 );

        $args = [
            'post_type'      => 'optee_rule',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => $grouped ? -1 : intval( $_POST['per_page'] ?? 20 ),
            'paged'          => $grouped ? 1 : intval( $_POST['page'] ?? 1 ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $campaign_filter = intval( $_POST['campaign_id'] ?? 0 );
        if ( $campaign_filter ) {
            $args['meta_query'] = [
                [
                    'key'   => '_optee_rule_campaign_id',
                    'value' => $campaign_filter,
                ],
            ];
        }

        $query = new \WP_Query( $args );
        $rules = [];

        foreach ( $query->posts as $post ) {
            $rules[] = self::format_rule( $post );
        }

        if ( $grouped ) {
            // Group rules by campaign.
            $campaigns = [];
            foreach ( $rules as $rule ) {
                $cid = $rule['campaign_id'];
                if ( ! isset( $campaigns[ $cid ] ) ) {
                    $campaign = get_post( $cid );
                    $campaigns[ $cid ] = [
                        'campaign_id'   => $cid,
                        'campaign_name' => $campaign ? $campaign->post_title : '(deleted)',
                        'campaign_status' => $campaign ? ( get_post_meta( $cid, '_optee_status', true ) ?: 'active' ) : 'unknown',
                        'rules'         => [],
                    ];
                }
                $campaigns[ $cid ]['rules'][] = $rule;
            }
            wp_send_json_success( [
                'campaigns' => array_values( $campaigns ),
                'total'     => $query->found_posts,
            ] );
        } else {
            wp_send_json_success( [
                'rules' => $rules,
                'total' => $query->found_posts,
                'pages' => $query->max_num_pages,
            ] );
        }
    }

    /**
     * Sanitize per-action parameters (JSON from the rule form) into a clean array
     * stored as _optee_rule_action_params. Only the fields an action understands
     * are kept; unknown actions store nothing.
     *
     * adjust_bid: percent (float, may be negative), min/max (optional float ≥ 0).
     */
    private static function sanitize_action_params( string $action, $raw ): array {
        $decoded = is_array( $raw ) ? $raw : json_decode( wp_unslash( (string) $raw ), true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }

        if ( $action === 'adjust_bid' ) {
            $out = [ 'percent' => (float) ( $decoded['percent'] ?? 0 ) ];
            foreach ( [ 'min', 'max' ] as $k ) {
                if ( isset( $decoded[ $k ] ) && $decoded[ $k ] !== '' && $decoded[ $k ] !== null ) {
                    $out[ $k ] = max( 0.0, (float) $decoded[ $k ] );
                }
            }
            return $out;
        }

        if ( $action === 'remove_targeting' ) {
            $allowed = [ 'category', 'os', 'browser', 'form_factor', 'connection_type', 'connection_speed', 'isp', 'resolution', 'device' ];
            $dim     = sanitize_text_field( $decoded['dimension'] ?? '' );
            return in_array( $dim, $allowed, true ) ? [ 'dimension' => $dim ] : [];
        }

        return [];
    }

    public function create_rule(): void {
        $this->verify();

        $title = sanitize_text_field( $_POST['title'] ?? '' );
        if ( empty( $title ) ) {
            wp_send_json_error( [ 'message' => 'Rule name is required.' ] );
        }

        $campaign_id = intval( $_POST['campaign_id'] ?? 0 );
        $campaign    = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
        }

        // Block zone-level actions on whitelist campaigns + campaigns with no traffic source.
        $rule_action = sanitize_text_field( $_POST['rule_action'] ?? 'pause_traffic' );
        if ( in_array( $rule_action, [ 'block_zone', 'unblock_zone', 'block_zones', 'unblock_zones' ], true ) ) {
            if ( ! self::campaign_has_traffic_source( $campaign_id ) ) {
                wp_send_json_error( [ 'message' => 'Zone block/unblock actions require the campaign to have a traffic source connected. The block has to be pushed to the traffic source via its API. Connect a traffic source to this campaign first.' ] );
            }
            if ( self::campaign_is_whitelist( $campaign_id ) ) {
                wp_send_json_error( [ 'message' => 'Zone block/unblock actions cannot be used with whitelist campaigns. Use Pause/Resume Traffic instead.' ] );
            }
        }

        $post_id = wp_insert_post( [
            'post_type'   => 'optee_rule',
            'post_title'  => $title,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ] );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
        }

        update_post_meta( $post_id, '_optee_rule_campaign_id', $campaign_id );
        update_post_meta( $post_id, '_optee_rule_action', sanitize_text_field( $_POST['rule_action'] ?? 'pause_traffic' ) );

        // Conditions (JSON string from frontend).
        $conditions = [];
        if ( ! empty( $_POST['conditions'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['conditions'] ), true );
            if ( is_array( $decoded ) ) {
                $conditions = self::sanitize_rule_conditions( $decoded );
            }
        }

        if ( $err = self::validate_vt_rule( $conditions, sanitize_text_field( $_POST['rule_action'] ?? '' ) ) ) {
            wp_send_json_error( [ 'message' => $err ] );
        }

        // Validate: offer status rules can only use pause/resume traffic.
        $is_offer_rule = ! empty( $conditions ) && $conditions[0]['source'] === 'affiliate_network' && $conditions[0]['metric'] === 'offer_status';
        if ( $is_offer_rule && ! in_array( $rule_action, [ 'pause_traffic', 'resume_traffic' ], true ) ) {
            wp_delete_post( $post_id, true );
            wp_send_json_error( [ 'message' => 'Offer status rules can only use Pause Traffic or Resume Traffic actions.' ] );
        }

        update_post_meta( $post_id, '_optee_rule_conditions', $conditions );

        update_post_meta( $post_id, '_optee_rule_action_params', self::sanitize_action_params( $rule_action, $_POST['action_params'] ?? '' ) );

        update_post_meta( $post_id, '_optee_rule_interval', intval( $_POST['interval'] ?? 60 ) );
        update_post_meta( $post_id, '_optee_rule_timeframe', sanitize_text_field( $_POST['timeframe'] ?? '7d' ) );
        update_post_meta( $post_id, '_optee_rule_last_run', '' );
        update_post_meta( $post_id, '_optee_rule_enabled', intval( $_POST['enabled'] ?? 1 ) );
        update_post_meta( $post_id, '_optee_rule_created_by', get_current_user_id() );

        // On success flags.
        $on_success = [];
        if ( ! empty( $_POST['on_success'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['on_success'] ), true );
            if ( is_array( $decoded ) ) {
                $on_success = array_map( 'sanitize_text_field', $decoded );
            }
        }
        update_post_meta( $post_id, '_optee_rule_on_success', $on_success );

        update_post_meta( $post_id, '_optee_rule_is_template', intval( $_POST['is_template'] ?? 0 ) );

        Logger::log( 'info', 'rule', "Rule created: {$title}", [
            'rule_id'     => $post_id,
            'campaign_id' => $campaign_id,
        ] );

        wp_send_json_success( [
            'rule'    => self::format_rule( get_post( $post_id ) ),
            'message' => 'Rule created successfully.',
        ] );
    }

    public function update_rule(): void {
        $this->verify();

        $post_id = intval( $_POST['rule_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'optee_rule' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Rule not found.' ] );
        }

        $title = sanitize_text_field( $_POST['title'] ?? '' );
        if ( $title ) {
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
        }

        if ( isset( $_POST['campaign_id'] ) ) {
            $new_campaign_id = intval( $_POST['campaign_id'] );
            $target_campaign = get_post( $new_campaign_id );
            if ( ! $target_campaign || $target_campaign->post_type !== 'optee_campaign' || (int) $target_campaign->post_author !== get_current_user_id() ) {
                wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
            }
            update_post_meta( $post_id, '_optee_rule_campaign_id', $new_campaign_id );
        }
        if ( isset( $_POST['rule_action'] ) ) {
            $new_action = sanitize_text_field( $_POST['rule_action'] );
            if ( in_array( $new_action, [ 'block_zone', 'unblock_zone', 'block_zones', 'unblock_zones' ], true ) ) {
                $cid = intval( $_POST['campaign_id'] ?? get_post_meta( $post_id, '_optee_rule_campaign_id', true ) );
                if ( $cid && ! self::campaign_has_traffic_source( $cid ) ) {
                    wp_send_json_error( [ 'message' => 'Zone block/unblock actions require the campaign to have a traffic source connected. The block has to be pushed to the traffic source via its API. Connect a traffic source to this campaign first.' ] );
                }
                if ( $cid && self::campaign_is_whitelist( $cid ) ) {
                    wp_send_json_error( [ 'message' => 'Zone block/unblock actions cannot be used with whitelist campaigns. Use Pause/Resume Traffic instead.' ] );
                }
            }
            update_post_meta( $post_id, '_optee_rule_action', $new_action );
        }
        if ( isset( $_POST['action_params'] ) ) {
            $eff_action = sanitize_text_field( $_POST['rule_action'] ?? get_post_meta( $post_id, '_optee_rule_action', true ) );
            update_post_meta( $post_id, '_optee_rule_action_params', self::sanitize_action_params( $eff_action, $_POST['action_params'] ) );
        }
        if ( isset( $_POST['conditions'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['conditions'] ), true );
            if ( is_array( $decoded ) ) {
                $conditions = self::sanitize_rule_conditions( $decoded );

                // Validate: offer status rules can only use pause/resume traffic.
                $is_offer_rule = ! empty( $conditions ) && $conditions[0]['source'] === 'affiliate_network' && $conditions[0]['metric'] === 'offer_status';
                $current_action = sanitize_text_field( $_POST['rule_action'] ?? get_post_meta( $post_id, '_optee_rule_action', true ) );

                if ( $err = self::validate_vt_rule( $conditions, $current_action ) ) {
                    wp_send_json_error( [ 'message' => $err ] );
                }
                if ( $is_offer_rule && ! in_array( $current_action, [ 'pause_traffic', 'resume_traffic' ], true ) ) {
                    wp_send_json_error( [ 'message' => 'Offer status rules can only use Pause Traffic or Resume Traffic actions.' ] );
                }

                update_post_meta( $post_id, '_optee_rule_conditions', $conditions );
            }
        }
        if ( isset( $_POST['interval'] ) ) {
            update_post_meta( $post_id, '_optee_rule_interval', intval( $_POST['interval'] ) );
        }
        if ( isset( $_POST['timeframe'] ) ) {
            update_post_meta( $post_id, '_optee_rule_timeframe', sanitize_text_field( $_POST['timeframe'] ) );
        }
        if ( isset( $_POST['enabled'] ) ) {
            update_post_meta( $post_id, '_optee_rule_enabled', intval( $_POST['enabled'] ) );
        }
        if ( isset( $_POST['on_success'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['on_success'] ), true );
            if ( is_array( $decoded ) ) {
                update_post_meta( $post_id, '_optee_rule_on_success', array_map( 'sanitize_text_field', $decoded ) );
            }
        }
        if ( isset( $_POST['is_template'] ) ) {
            update_post_meta( $post_id, '_optee_rule_is_template', intval( $_POST['is_template'] ) );
        }

        Logger::log( 'info', 'rule', "Rule updated: {$post->post_title}", [
            'rule_id' => $post_id,
        ] );

        wp_send_json_success( [
            'rule'    => self::format_rule( get_post( $post_id ) ),
            'message' => 'Rule updated successfully.',
        ] );
    }

    public function delete_rule(): void {
        $this->verify();

        $post_id = intval( $_POST['rule_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'optee_rule' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Rule not found.' ] );
        }

        $title = $post->post_title;
        wp_delete_post( $post_id, true );

        Logger::log( 'info', 'rule', "Rule deleted: {$title}", [
            'rule_id' => $post_id,
        ] );

        wp_send_json_success( [ 'message' => 'Rule deleted successfully.' ] );
    }

    /**
     * Duplicate a rule: clone the post + all rule meta into a new rule,
     * reset its run history, and leave it paused so it doesn't fire before
     * the user reviews it. Returns the new rule ID for redirect to edit.
     */
    public function duplicate_rule(): void {
        $this->verify();

        $post_id = intval( $_POST['rule_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'optee_rule' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Rule not found.' ] );
        }

        $new_id = wp_insert_post( [
            'post_type'   => 'optee_rule',
            'post_title'  => $post->post_title . ' (copy)',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ] );

        if ( is_wp_error( $new_id ) ) {
            wp_send_json_error( [ 'message' => $new_id->get_error_message() ] );
        }

        // Copy configuration meta verbatim.
        $copy_keys = [
            '_optee_rule_campaign_id',
            '_optee_rule_action',
            '_optee_rule_action_params',
            '_optee_rule_conditions',
            '_optee_rule_interval',
            '_optee_rule_timeframe',
            '_optee_rule_on_success',
            '_optee_rule_is_template',
        ];
        foreach ( $copy_keys as $key ) {
            $value = get_post_meta( $post_id, $key, true );
            if ( '' !== $value && null !== $value ) {
                update_post_meta( $new_id, $key, $value );
            }
        }

        // Fresh run history; start paused for review.
        update_post_meta( $new_id, '_optee_rule_enabled', 0 );
        update_post_meta( $new_id, '_optee_rule_last_run', '' );
        update_post_meta( $new_id, '_optee_rule_created_by', get_current_user_id() );

        Logger::log( 'info', 'rule', "Rule duplicated: {$post->post_title}", [
            'rule_id'     => $new_id,
            'source_rule' => $post_id,
        ] );

        wp_send_json_success( [
            'rule_id' => $new_id,
            'message' => 'Rule duplicated.',
        ] );
    }

    public function toggle_rule(): void {
        $this->verify();

        $post_id = intval( $_POST['rule_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'optee_rule' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Rule not found.' ] );
        }

        $current = get_post_meta( $post_id, '_optee_rule_enabled', true );
        $new     = $current === '1' ? '0' : '1';
        update_post_meta( $post_id, '_optee_rule_enabled', $new );

        $state = $new === '1' ? 'enabled' : 'disabled';
        Logger::log( 'info', 'rule', "Rule {$state}: {$post->post_title}", [
            'rule_id' => $post_id,
        ] );

        wp_send_json_success( [
            'enabled' => $new,
            'message' => "Rule {$state}.",
        ] );
    }

    /**
     * Temporarily pause ("snooze") a single rule's automation for a set number of
     * hours, then let it resume on its normal schedule. Distinct from the On/Off
     * toggle, which disables the rule indefinitely. Use case: a campaign has mixed
     * data and you want it to collect fresh, clean data for a few days without
     * rules blocking otherwise-OK zones.
     *
     * POST: rule_id, hours (float; <= 0 cancels the snooze / resumes now).
     */
    public function snooze_rule(): void {
        $this->verify();

        $post_id = intval( $_POST['rule_id'] ?? 0 );
        $hours   = (float) ( $_POST['hours'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'optee_rule' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Rule not found.' ] );
        }

        // Cancel / resume now.
        if ( $hours <= 0 ) {
            delete_post_meta( $post_id, '_optee_rule_snooze_until' );
            Logger::log( 'info', 'rule', "Rule snooze cancelled — automation resumed: {$post->post_title}", [
                'rule_id' => $post_id,
            ] );
            wp_send_json_success( [
                'snoozed'          => false,
                'snooze_remaining' => 0,
                'message'          => 'Rule resumed.',
            ] );
        }

        // Cap at 30 days to avoid an accidental "forever" snooze.
        $hours   = min( $hours, 24 * 30 );
        $seconds = (int) round( $hours * 3600 );
        // Store as WP-local timestamp so the engine + format_rule compare against
        // current_time('timestamp') consistently.
        $until = current_time( 'timestamp' ) + $seconds;
        update_post_meta( $post_id, '_optee_rule_snooze_until', $until );

        $label = self::humanize_duration( $seconds );
        Logger::log( 'info', 'rule', "Rule snoozed for {$label}: {$post->post_title}", [
            'rule_id'      => $post_id,
            'snooze_until' => $until,
        ] );

        wp_send_json_success( [
            'snoozed'          => true,
            'snooze_remaining' => $seconds,
            'message'          => "Rule snoozed for {$label}.",
        ] );
    }

    /** Human-friendly duration from seconds, e.g. "2 days", "6 hours", "1 day 12 hours". */
    private static function humanize_duration( int $seconds ): string {
        $days  = intdiv( $seconds, 86400 );
        $hours = intdiv( $seconds % 86400, 3600 );
        $parts = [];
        if ( $days > 0 )  $parts[] = $days . ' day' . ( $days === 1 ? '' : 's' );
        if ( $hours > 0 ) $parts[] = $hours . ' hour' . ( $hours === 1 ? '' : 's' );
        if ( ! $parts )   $parts[] = 'less than an hour';
        return implode( ' ', $parts );
    }

    public function reset_rule_last_run(): void {
        $this->verify();

        $post_id = intval( $_POST['rule_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'optee_rule' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Rule not found.' ] );
        }

        delete_post_meta( $post_id, '_optee_rule_last_run' );

        Logger::log( 'info', 'rule', "Rule last-run reset: {$post->post_title}", [
            'rule_id' => $post_id,
        ] );

        wp_send_json_success( [ 'message' => 'Last run reset — rule will execute on next automation cycle.' ] );
    }

    // ──────────────────────────────────────────
    // Credentials
    // ──────────────────────────────────────────

    public function save_credentials(): void {
        $this->verify();

        $type = sanitize_text_field( $_POST['module_type'] ?? '' );
        $slug = sanitize_text_field( $_POST['module_slug'] ?? '' );

        if ( ! $type || ! $slug ) {
            wp_send_json_error( [ 'message' => 'Module type and slug are required.' ] );
        }

        // Collect credential fields.
        $credentials = [];
        if ( ! empty( $_POST['credentials'] ) && is_array( $_POST['credentials'] ) ) {
            foreach ( $_POST['credentials'] as $key => $value ) {
                $credentials[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
            }
        }

        if ( empty( $credentials ) ) {
            wp_send_json_error( [ 'message' => 'No credentials provided.' ] );
        }

        $stored = ModuleRegistry::store_credentials( $type, $slug, $credentials );

        if ( ! $stored ) {
            wp_send_json_error( [ 'message' => 'Failed to save credentials.' ] );
        }

        Logger::log( 'info', 'module', "Credentials saved for {$slug}", [
            'module_type' => $type,
            'module_slug' => $slug,
        ] );

        wp_send_json_success( [ 'message' => 'Credentials saved successfully.' ] );
    }

    public function test_connection(): void {
        $this->verify();

        $type = sanitize_text_field( $_POST['module_type'] ?? '' );
        $slug = sanitize_text_field( $_POST['module_slug'] ?? '' );

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( $slug, $type ?: null );

        if ( ! $module ) {
            wp_send_json_error( [ 'message' => 'Module not found.' ] );
        }

        // Use submitted credentials or stored credentials.
        $credentials = [];
        if ( ! empty( $_POST['credentials'] ) && is_array( $_POST['credentials'] ) ) {
            foreach ( $_POST['credentials'] as $key => $value ) {
                $credentials[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
            }
        } else {
            $credentials = ModuleRegistry::get_stored_credentials( $type, $slug ) ?? [];
        }

        if ( empty( $credentials ) ) {
            wp_send_json_error( [ 'message' => 'No credentials to test.' ] );
        }

        $result = $module->test_connection( $credentials );

        $log_context = [ 'module_type' => $type, 'module_slug' => $slug ];
        // A module's debug payload can carry raw upstream request/response fragments.
        // Only persist it (and only return it to the browser) when WP_DEBUG is on;
        // the logger additionally redacts any secret-keyed values inside it.
        $debugging = defined( 'WP_DEBUG' ) && WP_DEBUG;
        if ( $debugging && ! empty( $result['debug'] ) ) {
            $log_context['debug'] = $result['debug'];
        }

        Logger::log(
            $result['success'] ? 'info' : 'warning',
            'module',
            "Connection test for {$slug}: " . $result['message'],
            $log_context
        );

        if ( ! $debugging ) {
            unset( $result['debug'] );
        }

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function delete_credentials(): void {
        $this->verify();

        $type = sanitize_text_field( $_POST['module_type'] ?? '' );
        $slug = sanitize_text_field( $_POST['module_slug'] ?? '' );

        ModuleRegistry::delete_credentials( $type, $slug );

        Logger::log( 'info', 'module', "Credentials deleted for {$slug}", [
            'module_type' => $type,
            'module_slug' => $slug,
        ] );

        wp_send_json_success( [ 'message' => 'Credentials deleted.' ] );
    }

    // ──────────────────────────────────────────
    // Notifications
    // ──────────────────────────────────────────

    public function get_notifications(): void {
        $this->verify();
        $notifications = Notifications::get_all();
        wp_send_json_success( [ 'notifications' => $notifications ] );
    }

    public function dismiss_notification(): void {
        $this->verify();
        $index = intval( $_POST['index'] ?? -1 );
        Notifications::dismiss( $index );
        wp_send_json_success();
    }

    public function dismiss_all_notifications(): void {
        $this->verify();
        Notifications::dismiss_all();
        wp_send_json_success();
    }

    public function get_notifications_archive(): void {
        $this->verify();

        $args = [
            'per_page' => intval( $_POST['per_page'] ?? 50 ),
            'page'     => intval( $_POST['page'] ?? 1 ),
        ];

        if ( ! empty( $_POST['type'] ) ) {
            $args['type'] = sanitize_text_field( $_POST['type'] );
        }

        wp_send_json_success( Notifications::query( $args ) );
    }

    public function clear_notifications_archive(): void {
        $this->verify();
        Notifications::clear_all();
        wp_send_json_success( [ 'message' => 'Notifications cleared.' ] );
    }

    // ──────────────────────────────────────────
    // Logs
    // ──────────────────────────────────────────

    public function get_logs(): void {
        $this->verify();

        $args = [
            'per_page' => intval( $_POST['per_page'] ?? 50 ),
            'page'     => intval( $_POST['page'] ?? 1 ),
        ];

        if ( ! empty( $_POST['log_type'] ) ) {
            $args['log_type'] = sanitize_text_field( $_POST['log_type'] );
        }
        if ( ! empty( $_POST['category'] ) ) {
            $args['category'] = sanitize_text_field( $_POST['category'] );
        }
        if ( ! empty( $_POST['search'] ) ) {
            $args['search'] = sanitize_text_field( wp_unslash( $_POST['search'] ) );
        }
        if ( ! empty( $_POST['alert_id'] ) ) {
            $args['alert_id'] = (int) $_POST['alert_id'];
        }

        $result = Logger::query( $args );

        // Enrich logs with campaign/rule names for display. Titles are resolved
        // only for the current user's own posts, so a global (user_id = 0) cron log
        // referencing another admin's campaign/rule never discloses its title.
        $post_cache = [];
        $uid        = get_current_user_id();
        if ( ! empty( $result['logs'] ) ) {
            foreach ( $result['logs'] as &$log ) {
                $ctx = $log['context'] ?? [];
                if ( is_string( $ctx ) ) {
                    $ctx = json_decode( $ctx, true ) ?: [];
                }

                $log['campaign_name'] = '';
                $log['campaign_id']   = 0;
                $log['rule_name']     = '';
                $log['rule_id']       = 0;

                if ( ! empty( $ctx['campaign_id'] ) ) {
                    $cid = (int) $ctx['campaign_id'];
                    $log['campaign_id']   = $cid;
                    $cp = self::log_owned_post( $cid, $uid, $post_cache );
                    $log['campaign_name'] = $cp ? html_entity_decode( $cp->post_title, ENT_QUOTES, 'UTF-8' ) : '';
                }

                if ( ! empty( $ctx['rule_id'] ) ) {
                    $rid = (int) $ctx['rule_id'];
                    $log['rule_id'] = $rid;
                    $p = self::log_owned_post( $rid, $uid, $post_cache );
                    $log['rule_name'] = $p ? html_entity_decode( $p->post_title, ENT_QUOTES, 'UTF-8' ) : '';
                    // Also resolve campaign from rule if not already set.
                    if ( ! $log['campaign_id'] && $p ) {
                        $cid = (int) get_post_meta( $rid, '_optee_rule_campaign_id', true );
                        if ( $cid ) {
                            $log['campaign_id']   = $cid;
                            $cp = self::log_owned_post( $cid, $uid, $post_cache );
                            $log['campaign_name'] = $cp ? html_entity_decode( $cp->post_title, ENT_QUOTES, 'UTF-8' ) : '';
                        }
                    }
                }
            }
            unset( $log );
        }

        // Whole-view row count (ignores filters) — drives the "log table is large" warning.
        $result['table_total'] = Logger::total_for_current_user();

        wp_send_json_success( $result );
    }

    /**
     * Resolve a post by id for log enrichment, but only if the current user owns
     * it. Caches results (including misses/foreign posts as null) for the request.
     */
    private static function log_owned_post( int $id, int $uid, array &$cache ) {
        if ( ! array_key_exists( $id, $cache ) ) {
            $p = get_post( $id );
            $cache[ $id ] = ( $p && (int) $p->post_author === $uid ) ? $p : null;
        }
        return $cache[ $id ];
    }

    public function clear_logs(): void {
        $this->verify();
        // Delete one bounded chunk per request; the client loops until `done`.
        // Keeps each DELETE small so huge log tables don't block or time out.
        $batch   = 100000;
        $deleted = Logger::clear_batch( $batch );
        wp_send_json_success( [
            'deleted' => $deleted,
            'done'    => $deleted < $batch,
            'message' => 'Logs cleared.',
        ] );
    }

    // ──────────────────────────────────────────
    // Traffic source campaign fetch
    // ──────────────────────────────────────────

    public function get_traffic_source_campaigns(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['module_slug'] ?? '' );
        if ( ! $slug ) {
            wp_send_json_error( [ 'message' => 'Module slug is required.' ] );
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $slug );

        if ( ! $module || $module->get_type() !== 'traffic_source' ) {
            wp_send_json_error( [ 'message' => 'Traffic source module not found.' ] );
        }

        // Sources that can also return archived campaigns (e.g. PropellerAds) let the
        // user opt in when their live campaigns are all archived on the platform.
        $supports_archived = method_exists( $module, 'set_include_archived' );
        if ( $supports_archived && ! empty( $_POST['include_archived'] ) ) {
            $module->set_include_archived( true );
        }

        try {
            $campaigns = $module->get_campaigns();
            $data      = array_map( function ( $c ) {
                return $c->to_array();
            }, $campaigns );
            // Sources where one creative = one campaign let users group several campaigns into one.
            $supports_grouping = method_exists( $module, 'campaigns_are_creatives' ) && $module->campaigns_are_creatives();
            wp_send_json_success( [ 'campaigns' => $data, 'supports_grouping' => $supports_grouping, 'supports_archived' => $supports_archived ] );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'module', "Failed to fetch campaigns from {$slug}: " . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Failed to fetch campaigns: ' . $e->getMessage() ] );
        }
    }

    // ──────────────────────────────────────────
    // Tracker campaign & zone fetch
    // ──────────────────────────────────────────

    public function get_tracker_campaigns(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['module_slug'] ?? '' );
        if ( ! $slug ) {
            wp_send_json_error( [ 'message' => 'Module slug is required.' ] );
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $slug );

        if ( ! $module || $module->get_type() !== 'tracker' ) {
            wp_send_json_error( [ 'message' => 'Tracker module not found.' ] );
        }

        try {
            $campaigns = $module->get_campaigns();
            $data      = array_map( function ( $c ) {
                return $c->to_array();
            }, $campaigns );
            wp_send_json_success( [ 'campaigns' => $data ] );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'module', "Failed to fetch campaigns from tracker {$slug}: " . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Failed to fetch campaigns: ' . $e->getMessage() ] );
        }
    }

    public function get_tracker_zones(): void {
        $this->verify();

        $slug        = sanitize_text_field( $_POST['module_slug'] ?? '' );
        $campaign_id = sanitize_text_field( $_POST['campaign_id'] ?? '' );

        if ( ! $slug || ! $campaign_id ) {
            wp_send_json_error( [ 'message' => 'Module slug and campaign ID are required.' ] );
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $slug );

        if ( ! $module || $module->get_type() !== 'tracker' ) {
            wp_send_json_error( [ 'message' => 'Tracker module not found.' ] );
        }

        try {
            $zones = $module->get_zone_fields( $campaign_id );
            wp_send_json_success( [ 'zones' => $zones ] );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'module', "Failed to fetch zone fields from tracker {$slug}: " . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Failed to fetch zone fields: ' . $e->getMessage() ] );
        }
    }

    /**
     * List a tracker's traffic sources for the optional per-campaign filter.
     *
     * Only trackers whose module implements get_traffic_sources() (currently
     * FunnelFlux, whose funnels aggregate traffic from many sources) support this.
     */
    public function get_tracker_traffic_sources(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['module_slug'] ?? '' );
        if ( ! $slug ) {
            wp_send_json_error( [ 'message' => 'Module slug is required.' ] );
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $slug );

        if ( ! $module || $module->get_type() !== 'tracker' || ! method_exists( $module, 'get_traffic_sources' ) ) {
            wp_send_json_error( [ 'message' => 'Tracker does not support traffic-source filtering.' ] );
        }

        try {
            $sources = $module->get_traffic_sources();
            wp_send_json_success( [ 'traffic_sources' => $sources ] );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'module', "Failed to fetch traffic sources from tracker {$slug}: " . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Failed to fetch traffic sources: ' . $e->getMessage() ] );
        }
    }

    /**
     * List the external campaign ids a tracker has seen for a funnel, so the
     * campaign form can offer them as click-to-add chips. Only trackers whose
     * module implements get_campaign_options() (FunnelFlux) support this; the list
     * is best-effort (empty when the funnel has no data yet — user types manually).
     */
    public function get_tracker_campaign_options(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['module_slug'] ?? '' );
        if ( ! $slug ) {
            wp_send_json_error( [ 'message' => 'Module slug is required.' ] );
        }

        $campaign_id = sanitize_text_field( $_POST['campaign_id'] ?? '' );
        $ts_id       = sanitize_text_field( $_POST['ts_id'] ?? '' );

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $slug );

        if ( ! $module || $module->get_type() !== 'tracker' || ! method_exists( $module, 'get_campaign_options' ) ) {
            wp_send_json_error( [ 'message' => 'Tracker does not support campaign-id filtering.' ] );
        }

        try {
            $campaigns = $module->get_campaign_options( $campaign_id, $ts_id );
            wp_send_json_success( [ 'campaigns' => $campaigns ] );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'module', "Failed to fetch campaign options from tracker {$slug}: " . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Failed to fetch campaign ids: ' . $e->getMessage() ] );
        }
    }

    // ──────────────────────────────────────────
    // Antifraud campaign fetch
    // ──────────────────────────────────────────

    public function get_antifraud_campaigns(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['module_slug'] ?? '' );
        if ( ! $slug ) {
            wp_send_json_error( [ 'message' => 'Module slug is required.' ] );
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $slug );

        if ( ! $module || $module->get_type() !== 'antifraud_tool' ) {
            wp_send_json_error( [ 'message' => 'Antifraud module not found.' ] );
        }

        try {
            $campaigns = $module->get_campaigns();
            $data      = array_map( function ( $c ) {
                return $c->to_array();
            }, $campaigns );
            wp_send_json_success( [ 'campaigns' => $data ] );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'module', "Failed to fetch campaigns from antifraud {$slug}: " . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Failed to fetch campaigns: ' . $e->getMessage() ] );
        }
    }

    // ──────────────────────────────────────────
    // Plugin Activation
    // ──────────────────────────────────────────

    public function activate_plugin(): void {
        $this->verify();

        $plugin_file = sanitize_text_field( $_POST['plugin_file'] ?? '' );

        if ( empty( $plugin_file ) ) {
            wp_send_json_error( [ 'message' => 'Plugin file is required.' ] );
        }

        // Only allow activating optee-traffic-source-* plugins.
        $dir = dirname( $plugin_file );
        if ( ! str_starts_with( $dir, 'optee-traffic-source-' )
          && ! str_starts_with( $dir, 'optee-tracker-' )
          && ! str_starts_with( $dir, 'optee-affiliate-network-' )
          && ! str_starts_with( $dir, 'optee-antifraud-' )
          && ! str_starts_with( $dir, 'optee-monetization-' ) ) {
            wp_send_json_error( [ 'message' => 'Only Optee module plugins can be activated here.' ] );
        }

        // Verify the plugin actually exists.
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
            wp_send_json_error( [ 'message' => 'Plugin not found.' ] );
        }

        if ( is_plugin_active( $plugin_file ) ) {
            wp_send_json_error( [ 'message' => 'Plugin is already active.' ] );
        }

        $result = activate_plugin( $plugin_file );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        $plugin_name = $all_plugins[ $plugin_file ]['Name'] ?? $plugin_file;
        Logger::log( 'info', 'module', "Plugin activated: {$plugin_name}", [
            'plugin_file' => $plugin_file,
        ] );

        wp_send_json_success( [ 'message' => "{$plugin_name} activated successfully." ] );
    }

    // ──────────────────────────────────────────
    // Plugin Deactivation
    // ──────────────────────────────────────────

    public function deactivate_plugin(): void {
        $this->verify();

        $plugin_file = sanitize_text_field( $_POST['plugin_file'] ?? '' );

        if ( empty( $plugin_file ) ) {
            wp_send_json_error( [ 'message' => 'Plugin file is required.' ] );
        }

        $dir = dirname( $plugin_file );
        if ( ! str_starts_with( $dir, 'optee-traffic-source-' )
          && ! str_starts_with( $dir, 'optee-tracker-' )
          && ! str_starts_with( $dir, 'optee-affiliate-network-' )
          && ! str_starts_with( $dir, 'optee-antifraud-' )
          && ! str_starts_with( $dir, 'optee-monetization-' ) ) {
            wp_send_json_error( [ 'message' => 'Only Optee module plugins can be deactivated here.' ] );
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
            wp_send_json_error( [ 'message' => 'Plugin not found.' ] );
        }

        if ( ! is_plugin_active( $plugin_file ) ) {
            wp_send_json_error( [ 'message' => 'Plugin is already inactive.' ] );
        }

        deactivate_plugins( $plugin_file );

        $plugin_name = $all_plugins[ $plugin_file ]['Name'] ?? $plugin_file;
        Logger::log( 'info', 'module', "Plugin deactivated: {$plugin_name}", [
            'plugin_file' => $plugin_file,
        ] );

        wp_send_json_success( [ 'message' => "{$plugin_name} deactivated successfully." ] );
    }

    // ──────────────────────────────────────────
    // Cache Settings
    // ──────────────────────────────────────────

    public function save_cache_ttl(): void {
        $this->verify();
        $ttl = intval( $_POST['cache_ttl'] ?? 5 );
        $ttl = max( 0, min( 60, $ttl ) );
        update_user_meta( get_current_user_id(), 'optee_cache_ttl', $ttl );
        wp_send_json_success( [ 'message' => 'Cache TTL saved.', 'cache_ttl' => $ttl ] );
    }

    public function save_cache_buster(): void {
        $this->verify();
        $value = substr( md5( uniqid( mt_rand(), true ) ), 0, 10 );
        update_user_meta( get_current_user_id(), 'optee_cache_buster', $value );
        wp_send_json_success( [ 'message' => 'Cache buster regenerated.', 'cache_buster' => $value ] );
    }

    /**
     * Build a transient key for a module's stats cache.
     */
    private static function stats_cache_key( int $user_id, int $campaign_id, string $module_slug, string $drilldown, string $date_from, string $date_to ): string {
        return 'optee_stats_' . md5( "{$user_id}_{$campaign_id}_{$module_slug}_{$drilldown}_{$date_from}_{$date_to}" );
    }

    /**
     * Fetch module stats with transient caching.
     *
     * @return array{rows: array, cached: bool, skipped?: bool}
     */
    private static function fetch_module_stats_cached( $module, string $campaign_id, string $date_from, string $date_to, string $drilldown, int $ttl_seconds, string $cache_key, bool $force_refresh, bool $automation_running = false, ?array $zone_filter = null ): array {
        // Check cache first (always, regardless of automation state).
        if ( $ttl_seconds > 0 && ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) {
                return [ 'rows' => $cached, 'cached' => true ];
            }
        }

        // If automation is running and we have no cache, skip the live API call.
        if ( $automation_running ) {
            return [ 'rows' => [], 'cached' => false, 'skipped' => true ];
        }

        // If TTL is 0, caching is disabled — just fetch live.
        if ( $ttl_seconds <= 0 ) {
            $rows = $module->get_statistics( $campaign_id, $date_from, $date_to, $drilldown, $zone_filter );
            return [ 'rows' => $rows, 'cached' => false ];
        }

        // Live API call + cache.
        $rows = $module->get_statistics( $campaign_id, $date_from, $date_to, $drilldown, $zone_filter );
        set_transient( $cache_key, $rows, $ttl_seconds );
        return [ 'rows' => $rows, 'cached' => false ];
    }

    // ──────────────────────────────────────────
    // Balances
    // ──────────────────────────────────────────

    public function get_balances(): void {
        $this->verify();
        global $wpdb;

        $user_id = get_current_user_id();
        $days    = absint( $_POST['days'] ?? 30 );
        $table   = $wpdb->prefix . 'optee_balances';

        $date_from = wp_date( 'Y-m-d', strtotime( "-{$days} days" ) );

        // Get all balance rows for this user in the date range.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT module_type, module_slug, balance_date, balance, currency
             FROM {$table}
             WHERE user_id = %d AND balance_date >= %s
             ORDER BY balance_date DESC",
            $user_id,
            $date_from
        ), ARRAY_A );

        wp_send_json_success( self::format_balance_response( $rows ) );
    }

    public function fetch_balances_now(): void {
        $this->verify();
        global $wpdb;

        $user_id  = get_current_user_id();
        $table    = $wpdb->prefix . 'optee_balances';
        $registry = ModuleRegistry::get_instance();
        $today    = current_time( 'Y-m-d' );
        $fetched  = [];

        // Types that support balances.
        $types = [ 'traffic_source', 'affiliate_network', 'monetization_platform', 'antifraud_tool' ];

        foreach ( $types as $type ) {
            $modules = $registry->get_modules_by_type( $type );
            foreach ( $modules as $module ) {
                $slug = $module->get_slug();
                $creds = ModuleRegistry::get_stored_credentials( $type, $slug );
                if ( ! $creds ) {
                    continue;
                }
                $module->set_credentials( $creds );

                $result = $module->get_balance();
                if ( empty( $result['supported'] ) ) {
                    continue;
                }

                $balance  = floatval( $result['balance'] ?? 0 );
                $currency = sanitize_text_field( $result['currency'] ?? 'USD' );

                // Upsert: insert or update today's balance.
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$table} (user_id, module_type, module_slug, balance_date, balance, currency)
                     VALUES (%d, %s, %s, %s, %f, %s)
                     ON DUPLICATE KEY UPDATE balance = %f, currency = %s",
                    $user_id, $type, $slug, $today, $balance, $currency,
                    $balance, $currency
                ) );

                // Record last update timestamp (Unix time, matching SystemTasks).
                update_option( "optee_auto_balance_{$user_id}_{$slug}", time(), false );

                // Alerts module: evaluate balance threshold alerts on manual refresh too
                // (same daily-capped dispatch as the cron path).
                \Optee\Plugin\Alerts\AlertEngine::check_balance( $user_id, $type, $slug, $module->get_name(), $balance, $currency );

                $fetched[] = [
                    'module_slug' => $slug,
                    'module_name' => $module->get_name(),
                    'module_type' => $type,
                    'balance'     => $balance,
                    'currency'    => $currency,
                ];
            }
        }

        // Carry forward the last known balance to today for manual (no-API) modules.
        // Handles both a missing today row and a today=0 row, and looks back past gaps.
        SystemTasks::carry_forward_manual_balances( $user_id );

        // Return fresh data (last 30 days).
        $days      = absint( $_POST['days'] ?? 30 );
        $date_from = wp_date( 'Y-m-d', strtotime( "-{$days} days" ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT module_type, module_slug, balance_date, balance, currency
             FROM {$table}
             WHERE user_id = %d AND balance_date >= %s
             ORDER BY balance_date DESC",
            $user_id,
            $date_from
        ), ARRAY_A );

        $data = self::format_balance_response( $rows );
        $data['fetched'] = $fetched;

        wp_send_json_success( $data );
    }

    public function get_manual_balance_modules(): void {
        $this->verify();

        $user_id  = get_current_user_id();
        $registry = ModuleRegistry::get_instance();
        $types    = [ 'traffic_source', 'affiliate_network', 'monetization_platform' ];

        // Collect all registered modules that don't support auto-balance.
        // Use capabilities list instead of live API call to avoid false negatives
        // when a module's API is temporarily unreachable.
        $available = [];
        foreach ( $types as $type ) {
            $modules = $registry->get_modules_by_type( $type );
            foreach ( $modules as $module ) {
                $slug = $module->get_slug();

                // Check capabilities for "Get Balance" — if implemented, it's auto-balance.
                $has_auto_balance = false;
                foreach ( $module->get_capabilities() as $cap ) {
                    if ( ( $cap['label'] ?? '' ) === 'Get Balance' && ( $cap['implemented'] ?? false ) === true ) {
                        $has_auto_balance = true;
                        break;
                    }
                }
                if ( $has_auto_balance ) {
                    continue;
                }

                $available[] = [
                    'slug' => $slug,
                    'name' => $module->get_name(),
                    'type' => $type,
                ];
            }
        }

        $enabled = get_user_meta( $user_id, 'optee_manual_balance_modules', true ) ?: [];
        if ( ! is_array( $enabled ) ) {
            $enabled = [];
        }

        wp_send_json_success( [
            'available' => $available,
            'enabled'   => $enabled,
        ] );
    }

    public function save_manual_balance_modules(): void {
        $this->verify();

        $user_id = get_current_user_id();
        $raw     = json_decode( wp_unslash( $_POST['modules'] ?? '[]' ), true );

        if ( ! is_array( $raw ) ) {
            wp_send_json_error( [ 'message' => 'Invalid data.' ] );
        }

        $modules = [];
        foreach ( $raw as $item ) {
            if ( empty( $item['slug'] ) || empty( $item['name'] ) || empty( $item['type'] ) ) {
                continue;
            }
            $modules[] = [
                'slug' => sanitize_key( $item['slug'] ),
                'name' => sanitize_text_field( $item['name'] ),
                'type' => sanitize_key( $item['type'] ),
            ];
        }

        update_user_meta( $user_id, 'optee_manual_balance_modules', $modules );

        wp_send_json_success( [ 'message' => 'Manual balance modules saved.' ] );
    }

    public function save_manual_balance(): void {
        $this->verify();
        global $wpdb;

        $user_id      = get_current_user_id();
        $table        = $wpdb->prefix . 'optee_balances';
        $module_type  = sanitize_key( $_POST['module_type'] ?? '' );
        $module_slug  = sanitize_key( $_POST['module_slug'] ?? '' );
        $balance_date = sanitize_text_field( $_POST['balance_date'] ?? '' );
        $balance      = floatval( $_POST['balance'] ?? 0 );

        if ( ! $module_type || ! $module_slug || ! $balance_date ) {
            wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
        }

        // Validate date format.
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $balance_date ) ) {
            wp_send_json_error( [ 'message' => 'Invalid date format.' ] );
        }

        // is_manual = 1 marks this as an explicit user edit (even a 0), so the carry-forward
        // task won't overwrite an intentional zero with yesterday's balance.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (user_id, module_type, module_slug, balance_date, balance, currency, is_manual)
             VALUES (%d, %s, %s, %s, %f, %s, 1)
             ON DUPLICATE KEY UPDATE balance = %f, is_manual = 1",
            $user_id, $module_type, $module_slug, $balance_date, $balance, 'USD',
            $balance
        ) );

        wp_send_json_success( [ 'message' => 'Balance saved.', 'balance' => $balance ] );
    }

    // ──────────────────────────────────────────
    // Earnings
    // ──────────────────────────────────────────

    public function get_earnings(): void {
        $this->verify();
        global $wpdb;

        $user_id = get_current_user_id();
        $year    = intval( $_POST['year'] ?? current_time( 'Y' ) );
        $table   = $wpdb->prefix . 'optee_earnings';

        $date_from = "{$year}-01-01";
        $date_to   = "{$year}-12-31";

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT module_type, module_slug, earnings_date, amount, currency
             FROM {$table}
             WHERE user_id = %d AND earnings_date >= %s AND earnings_date <= %s
             ORDER BY earnings_date DESC",
            $user_id,
            $date_from,
            $date_to
        ), ARRAY_A );

        // Get available years for the filter.
        $years = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT YEAR(earnings_date) AS yr FROM {$table}
             WHERE user_id = %d ORDER BY yr DESC",
            $user_id
        ) );
        $current_year = (int) current_time( 'Y' );
        if ( ! in_array( $current_year, array_map( 'intval', $years ), true ) ) {
            array_unshift( $years, $current_year );
        }

        $data = self::format_earnings_response( $rows );
        $data['years']        = array_map( 'intval', $years );
        $data['selected_year'] = $year;

        wp_send_json_success( $data );
    }

    public function fetch_earnings_now(): void {
        $this->verify();
        global $wpdb;

        $user_id  = get_current_user_id();
        $table    = $wpdb->prefix . 'optee_earnings';
        $registry = ModuleRegistry::get_instance();

        // Fetch last 30 days including today.
        $date_from = wp_date( 'Y-m-d', strtotime( '-29 days' ) );
        $date_to   = current_time( 'Y-m-d' );

        $types   = [ 'traffic_source', 'affiliate_network', 'monetization_platform' ];
        $fetched = [];

        foreach ( $types as $type ) {
            $modules = $registry->get_modules_by_type( $type );
            foreach ( $modules as $module ) {
                $slug  = $module->get_slug();
                $creds = ModuleRegistry::get_stored_credentials( $type, $slug );
                if ( ! $creds ) {
                    continue;
                }
                $module->set_credentials( $creds );

                if ( ! method_exists( $module, 'get_account_earnings' ) ) {
                    continue;
                }

                $result = $module->get_account_earnings( $date_from, $date_to );
                if ( empty( $result['supported'] ) ) {
                    continue;
                }

                // No activity returned — record a $0 entry for today so the
                // network stays visible on the earnings page (mirrors the
                // balances page behaviour).
                if ( empty( $result['data'] ) ) {
                    $result['data'] = [ [ 'date' => current_time( 'Y-m-d' ), 'amount' => 0 ] ];
                }

                foreach ( $result['data'] as $row ) {
                    $date   = sanitize_text_field( $row['date'] ?? '' );
                    $amount = floatval( $row['amount'] ?? 0 );
                    if ( ! $date ) {
                        continue;
                    }

                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$table} (user_id, module_type, module_slug, earnings_date, amount, currency)
                         VALUES (%d, %s, %s, %s, %f, %s)
                         ON DUPLICATE KEY UPDATE amount = %f",
                        $user_id, $type, $slug, $date, $amount, 'USD',
                        $amount
                    ) );
                }

                // Record last update timestamp (Unix time, matching SystemTasks).
                update_option( "optee_auto_earnings_{$user_id}_{$slug}", time(), false );

                $fetched[] = [
                    'module_slug' => $slug,
                    'module_name' => $module->get_name(),
                    'module_type' => $type,
                    'days'        => count( $result['data'] ),
                ];
            }
        }

        // Return current year data.
        $year      = intval( $_POST['year'] ?? current_time( 'Y' ) );
        $year_from = "{$year}-01-01";
        $year_to   = "{$year}-12-31";

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT module_type, module_slug, earnings_date, amount, currency
             FROM {$table}
             WHERE user_id = %d AND earnings_date >= %s AND earnings_date <= %s
             ORDER BY earnings_date DESC",
            $user_id,
            $year_from,
            $year_to
        ), ARRAY_A );

        $years = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT YEAR(earnings_date) AS yr FROM {$table}
             WHERE user_id = %d ORDER BY yr DESC",
            $user_id
        ) );
        $current_year = (int) current_time( 'Y' );
        if ( ! in_array( $current_year, array_map( 'intval', $years ), true ) ) {
            array_unshift( $years, $current_year );
        }

        $data = self::format_earnings_response( $rows );
        $data['years']         = array_map( 'intval', $years );
        $data['selected_year'] = $year;
        $data['fetched']       = $fetched;

        wp_send_json_success( $data );
    }

    private static function format_earnings_response( array $rows, int $user_id = 0 ): array {
        $registry = ModuleRegistry::get_instance();
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        $columns  = [];
        $by_date  = [];

        foreach ( $rows as $row ) {
            $slug = $row['module_slug'];
            $type = $row['module_type'];
            $date = $row['earnings_date'];

            $col_key = $type . '__' . $slug;
            if ( ! isset( $columns[ $col_key ] ) ) {
                $module = $registry->get_module( $slug );
                // Look up last auto-update timestamp.
                $last_ts = get_option( "optee_auto_earnings_{$user_id}_{$slug}", '' );
                $columns[ $col_key ] = [
                    'slug'         => $slug,
                    'name'         => $module ? $module->get_name() : $slug,
                    'type'         => $type,
                    'role'         => $type === 'traffic_source' ? 'cost' : 'revenue',
                    'last_updated' => $last_ts ? human_time_diff( (int) $last_ts ) . ' ago' : 'Never',
                ];
            }

            if ( ! isset( $by_date[ $date ] ) ) {
                $by_date[ $date ] = [ 'date' => $date ];
            }
            $by_date[ $date ][ $col_key ] = floatval( $row['amount'] );
        }

        // Sort by date desc.
        krsort( $by_date );

        // Compute summary columns per row.
        $result_rows = [];
        foreach ( $by_date as $date_row ) {
            $total_revenue = 0;
            $total_cost    = 0;
            foreach ( $columns as $col_key => $col ) {
                $val = $date_row[ $col_key ] ?? 0;
                if ( $col['role'] === 'revenue' ) {
                    $total_revenue += $val;
                } else {
                    $total_cost += $val;
                }
            }
            $profit = $total_revenue - $total_cost;
            $roi    = $total_cost > 0 ? round( ( $profit / $total_cost ) * 100, 2 ) : 0;

            $date_row['total_revenue'] = round( $total_revenue, 2 );
            $date_row['total_cost']    = round( $total_cost, 2 );
            $date_row['profit']        = round( $profit, 2 );
            $date_row['roi']           = $roi;

            $result_rows[] = $date_row;
        }

        // Sort columns: revenue (affiliate networks + monetization) first, cost (traffic sources) last; name ASC within each group.
        $sorted_columns = array_values( $columns );
        usort( $sorted_columns, function ( $a, $b ) {
            $order = [ 'affiliate_network' => 0, 'monetization_platform' => 0, 'traffic_source' => 1 ];
            $cmp = ( $order[ $a['type'] ] ?? 2 ) - ( $order[ $b['type'] ] ?? 2 );
            return $cmp !== 0 ? $cmp : strcasecmp( $a['name'], $b['name'] );
        } );

        return [
            'columns' => $sorted_columns,
            'rows'    => $result_rows,
        ];
    }

    // ──────────────────────────────────────────
    // Balance helpers
    // ──────────────────────────────────────────

    private static function format_balance_response( array $rows, int $user_id = 0 ): array {
        $registry = ModuleRegistry::get_instance();
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }
        $columns  = [];
        $by_date  = [];

        foreach ( $rows as $row ) {
            $slug = $row['module_slug'];
            $type = $row['module_type'];
            $date = $row['balance_date'];

            // Build column list.
            $col_key = $type . '__' . $slug;
            if ( ! isset( $columns[ $col_key ] ) ) {
                $module = $registry->get_module( $slug );
                // Look up last auto-update timestamp.
                $last_ts = get_option( "optee_auto_balance_{$user_id}_{$slug}", '' );
                $columns[ $col_key ] = [
                    'slug'         => $slug,
                    'name'         => $module ? $module->get_name() : $slug,
                    'type'         => $type,
                    'last_updated' => $last_ts ? human_time_diff( (int) $last_ts ) . ' ago' : 'Never',
                ];
            }

            // Pivot into date rows.
            if ( ! isset( $by_date[ $date ] ) ) {
                $by_date[ $date ] = [ 'date' => $date ];
            }
            $by_date[ $date ][ $col_key ] = floatval( $row['balance'] );
        }

        // Merge in manual module columns that may not have any data rows yet.
        $manual_modules = get_user_meta( get_current_user_id(), 'optee_manual_balance_modules', true ) ?: [];
        if ( is_array( $manual_modules ) ) {
            foreach ( $manual_modules as $mm ) {
                $col_key = $mm['type'] . '__' . $mm['slug'];
                if ( ! isset( $columns[ $col_key ] ) ) {
                    $module = $registry->get_module( $mm['slug'] );
                    $columns[ $col_key ] = [
                        'slug'   => $mm['slug'],
                        'name'   => $module ? $module->get_name() : $mm['name'],
                        'type'   => $mm['type'],
                        'manual' => true,
                    ];
                } else {
                    $columns[ $col_key ]['manual'] = true;
                }
            }
        }

        // Sort by date desc.
        krsort( $by_date );

        // Compute totals per row.
        $result_rows = [];
        foreach ( $by_date as $date_row ) {
            $total = 0;
            foreach ( $columns as $col_key => $col ) {
                $total += $date_row[ $col_key ] ?? 0;
            }
            $date_row['total'] = round( $total, 2 );
            $result_rows[] = $date_row;
        }

        // Sort columns: revenue (affiliate networks + monetization) first, cost (traffic sources) last; name ASC within each group.
        $sorted_columns = array_values( $columns );
        usort( $sorted_columns, function ( $a, $b ) {
            $order = [ 'affiliate_network' => 0, 'monetization_platform' => 0, 'traffic_source' => 1 ];
            $cmp = ( $order[ $a['type'] ] ?? 2 ) - ( $order[ $b['type'] ] ?? 2 );
            return $cmp !== 0 ? $cmp : strcasecmp( $a['name'], $b['name'] );
        } );

        return [
            'columns' => $sorted_columns,
            'rows'    => $result_rows,
        ];
    }

    // ──────────────────────────────────────────
    // VirusTotal URLs
    // ──────────────────────────────────────────

    public function vt_save_url(): void {
        $this->verify();

        $label = sanitize_text_field( $_POST['label'] ?? '' );
        $url   = esc_url_raw( $_POST['url'] ?? '' );

        if ( ! $url ) {
            wp_send_json_error( [ 'message' => 'URL is required.' ] );
        }

        $user_id = get_current_user_id();
        $urls    = get_user_meta( $user_id, 'optee_virustotal_urls', true ) ?: [];

        $urls[] = [
            'label'  => $label,
            'url'    => $url,
            'added'  => current_time( 'Y-m-d H:i' ),
            'status' => 'active',
        ];

        update_user_meta( $user_id, 'optee_virustotal_urls', $urls );

        $days     = intval( $_POST['days'] ?? 10 );
        $enriched = self::vt_enrich_urls( $urls, $user_id, $days );
        wp_send_json_success( [ 'urls' => $enriched, 'message' => 'URL added.' ] );
    }

    public function vt_edit_url(): void {
        $this->verify();

        $index   = intval( $_POST['index'] ?? -1 );
        $label   = sanitize_text_field( $_POST['label'] ?? '' );
        $url     = esc_url_raw( $_POST['url'] ?? '' );
        $user_id = get_current_user_id();
        $urls    = get_user_meta( $user_id, 'optee_virustotal_urls', true ) ?: [];

        if ( $index < 0 || $index >= count( $urls ) ) {
            wp_send_json_error( [ 'message' => 'Invalid index.' ] );
        }
        if ( ! $url ) {
            wp_send_json_error( [ 'message' => 'URL is required.' ] );
        }

        $urls[ $index ]['label'] = $label;
        $urls[ $index ]['url']   = $url;

        update_user_meta( $user_id, 'optee_virustotal_urls', $urls );

        $days     = intval( $_POST['days'] ?? 10 );
        $enriched = self::vt_enrich_urls( $urls, $user_id, $days );
        wp_send_json_success( [ 'urls' => $enriched, 'message' => 'URL updated.' ] );
    }

    public function vt_delete_url(): void {
        $this->verify();

        $index   = intval( $_POST['index'] ?? -1 );
        $user_id = get_current_user_id();
        $urls    = get_user_meta( $user_id, 'optee_virustotal_urls', true ) ?: [];

        if ( $index < 0 || $index >= count( $urls ) ) {
            wp_send_json_error( [ 'message' => 'Invalid index.' ] );
        }

        array_splice( $urls, $index, 1 );
        update_user_meta( $user_id, 'optee_virustotal_urls', $urls );

        $days     = intval( $_POST['days'] ?? 10 );
        $enriched = self::vt_enrich_urls( $urls, $user_id, $days );
        wp_send_json_success( [ 'urls' => $enriched, 'message' => 'URL removed.' ] );
    }

    public function vt_get_urls(): void {
        $this->verify();

        $user_id = get_current_user_id();
        $urls    = get_user_meta( $user_id, 'optee_virustotal_urls', true ) ?: [];
        $days    = intval( $_POST['days'] ?? 10 );

        $enriched = self::vt_enrich_urls( $urls, $user_id, $days );
        wp_send_json_success( [ 'urls' => $enriched ] );
    }

    public function vt_refresh_url(): void {
        $this->verify();

        $index   = intval( $_POST['index'] ?? -1 );
        $user_id = get_current_user_id();
        $urls    = get_user_meta( $user_id, 'optee_virustotal_urls', true ) ?: [];

        if ( $index < 0 || $index >= count( $urls ) ) {
            wp_send_json_error( [ 'message' => 'Invalid index.' ] );
        }

        $url = $urls[ $index ]['url'] ?? '';
        if ( ! $url ) {
            wp_send_json_error( [ 'message' => 'URL is empty.' ] );
        }

        try {
            $result = SystemTasks::scan_and_store_vt( $user_id, $url, $index );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'virustotal', 'VT scan failed: ' . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Scan failed. Check the logs for details.' ] );
        }

        // Return updated URL list with scan data.
        $urls     = get_user_meta( $user_id, 'optee_virustotal_urls', true ) ?: [];
        $days     = intval( $_POST['days'] ?? 10 );
        $enriched = self::vt_enrich_urls( $urls, $user_id, $days );

        // Interim: VirusTotal queued a fresh analysis and hasn't reported yet. We did
        // NOT overwrite the stored score — tell the user to retry shortly.
        if ( ! empty( $result['pending'] ) ) {
            wp_send_json_success( [
                'message' => 'VirusTotal is still analysing this URL — results not ready yet. Try again in a minute.',
                'stats'   => [],
                'urls'    => $enriched,
                'pending' => true,
            ] );
        }

        $mal   = $result['malicious'] ?? 0;
        $total = $result['total'] ?? 0;

        wp_send_json_success( [
            'message' => "Scan complete: {$mal}/{$total} flagged.",
            'stats'   => $result['stats'],
            'urls'    => $enriched,
        ] );
    }

    public function vt_save_interval(): void {
        $this->verify();

        $interval = max( 1, intval( $_POST['interval'] ?? 30 ) );
        update_user_meta( get_current_user_id(), 'optee_vt_check_interval', $interval );
        wp_send_json_success( [ 'interval' => $interval, 'message' => 'Check interval saved.' ] );
    }

    public function vt_toggle_url_status(): void {
        $this->verify();

        $index   = intval( $_POST['index'] ?? -1 );
        $user_id = get_current_user_id();
        $urls    = get_user_meta( $user_id, 'optee_virustotal_urls', true ) ?: [];

        if ( $index < 0 || $index >= count( $urls ) ) {
            wp_send_json_error( [ 'message' => 'Invalid index.' ] );
        }

        $current = $urls[ $index ]['status'] ?? 'active';
        $urls[ $index ]['status'] = $current === 'active' ? 'paused' : 'active';

        update_user_meta( $user_id, 'optee_virustotal_urls', $urls );

        $days     = intval( $_POST['days'] ?? 10 );
        $enriched = self::vt_enrich_urls( $urls, $user_id, $days );
        wp_send_json_success( [ 'urls' => $enriched, 'status' => $urls[ $index ]['status'] ] );
    }

    /**
     * Return per-key info (masked key + API quota) for the VirusTotal configure page.
     */
    public function vt_key_info(): void {
        $this->verify();

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( 'virustotal' );

        if ( ! $module || ! method_exists( $module, 'get_keys_info' ) ) {
            wp_send_json_error( [ 'message' => 'VirusTotal module is not active.' ] );
        }

        // Use submitted credentials if present, otherwise fall back to stored.
        $credentials = [];
        if ( ! empty( $_POST['credentials'] ) && is_array( $_POST['credentials'] ) ) {
            foreach ( $_POST['credentials'] as $key => $value ) {
                $credentials[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
            }
        } else {
            $credentials = ModuleRegistry::get_stored_credentials( 'virustotal', 'virustotal' ) ?? [];
        }

        $module->set_credentials( $credentials );

        // Today's egress split travels with the key info so the configure page can
        // show "Direct: N · Proxy: N" without a second round trip.
        $today  = gmdate( 'Y-m-d' );
        $routes = [ 'direct' => 0, 'proxy' => 0 ];
        foreach ( \Optee\Plugin\SystemTasks::vt_usage_history( get_current_user_id(), 7 ) as $row ) {
            if ( $row['date'] === $today ) {
                $routes = [
                    'direct' => (int) ( $row['direct'] ?? 0 ),
                    'proxy'  => (int) ( $row['proxy'] ?? 0 ),
                ];
                break;
            }
        }

        wp_send_json_success( [
            'keys'   => $module->get_keys_info(),
            'routes' => $routes,
        ] );
    }

    /**
     * Per-day VirusTotal API usage for the chart on the VirusTotal page.
     *
     * Two series, deliberately: `quota_used` is what VirusTotal itself reports
     * against the allowance, `calls` is what this install actually spent. The
     * allowance is enforced per source IP, so anything else on the same server eats
     * from the same pot without appearing in our count — the gap between the lines
     * is that contention, and it is the whole point of showing both.
     */
    public function vt_usage_history(): void {
        $this->verify();

        $days = intval( $_POST['days'] ?? 14 );
        $days = max( 7, min( 30, $days ) );

        $history = \Optee\Plugin\SystemTasks::vt_usage_history( get_current_user_id(), $days );

        $today       = gmdate( 'Y-m-d' );
        $today_row   = [];
        $peak        = 0;
        $total_calls = 0;
        foreach ( $history as $row ) {
            $peak         = max( $peak, $row['calls'], $row['quota_used'] );
            $total_calls += $row['calls'];
            if ( $row['date'] === $today ) {
                $today_row = $row;
            }
        }

        wp_send_json_success( [
            'history'     => $history,
            'today'       => $today_row,
            'peak'        => $peak,
            'total_calls' => $total_calls,
            'days'        => $days,
        ] );
    }

    /**
     * Enrich URL list with scan data from the optee_vt_scans table.
     *
     * @param array $urls    URL list from user meta.
     * @param int   $user_id Current user ID.
     * @param int   $days    Number of days to include.
     * @return array URLs with 'scans' key attached.
     */
    private static function vt_enrich_urls( array $urls, int $user_id, int $days ): array {
        global $wpdb;

        if ( empty( $urls ) ) {
            return $urls;
        }

        $table     = $wpdb->prefix . 'optee_vt_scans';
        $date_from = wp_date( 'Y-m-d', strtotime( "-{$days} days" ) );

        // Build hash → index map.
        $hash_map = [];
        foreach ( $urls as $i => $item ) {
            $hash = hash( 'sha256', $item['url'] );
            $hash_map[ $hash ] = $i;
        }

        $hashes      = array_keys( $hash_map );
        $placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

        // Query all scan rows for this user's URLs in the date range.
        $query_args = array_merge( [ $user_id, $date_from ], $hashes );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT url_hash, scan_date, total_vendors, malicious, suspicious, harmless, undetected, vendor_results
             FROM {$table}
             WHERE user_id = %d AND scan_date >= %s AND url_hash IN ({$placeholders})
             ORDER BY scan_date DESC",
            $query_args
        ) );

        // Attach scans to each URL.
        foreach ( $urls as &$item ) {
            $item['scans'] = (object) [];
        }
        unset( $item );

        foreach ( $rows as $row ) {
            $idx = $hash_map[ $row->url_hash ] ?? null;
            if ( $idx === null ) {
                continue;
            }

            $vendors_raw = json_decode( $row->vendor_results, true ) ?: [];

            $scans_obj = (array) $urls[ $idx ]['scans'];
            $scans_obj[ $row->scan_date ] = [
                'total'      => intval( $row->total_vendors ),
                'malicious'  => intval( $row->malicious ),
                'suspicious' => intval( $row->suspicious ),
                'harmless'   => intval( $row->harmless ),
                'undetected' => intval( $row->undetected ),
                'vendors'    => $vendors_raw,
            ];
            $urls[ $idx ]['scans'] = (object) $scans_obj;
        }

        return $urls;
    }

    // ──────────────────────────────────────────
    // Cron Status / Heartbeat
    // ──────────────────────────────────────────

    public function get_cron_status(): void {
        $this->verify();

        $cron_last_run      = get_option( 'optee_cron_last_complete', '' );
        $automation_running = ( get_transient( 'optee_automation_lock' ) !== false );
        $status             = 'unknown';
        $text               = 'Never';

        if ( $automation_running ) {
            $status = 'running';
        }

        if ( $cron_last_run ) {
            $now_local = current_time( 'timestamp' );
            $last_ts   = strtotime( $cron_last_run );
            $diff      = $now_local - $last_ts;
            $text      = human_time_diff( $last_ts, $now_local ) . ' ago';
            if ( ! $automation_running ) {
                if ( $diff < 300 ) {
                    $status = 'ok';
                } elseif ( $diff < 900 ) {
                    $status = 'warning';
                } else {
                    $status = 'error';
                }
            }
        }

        wp_send_json_success( [
            'last_run'           => $text,
            'status'             => $status,
            'automation_running' => $automation_running,
        ] );
    }

    public function save_heartbeat_interval(): void {
        $this->verify();

        $interval = max( 10, intval( $_POST['interval'] ?? 10 ) );
        update_user_meta( get_current_user_id(), 'optee_heartbeat_interval', $interval );
        wp_send_json_success( [ 'interval' => $interval, 'message' => 'Heartbeat interval saved.' ] );
    }

    public function save_display_prefs(): void {
        $this->verify();

        $decimals = max( 0, min( 5, intval( $_POST['decimals'] ?? 2 ) ) );
        $weekends = ( ! empty( $_POST['highlight_weekends'] ) && $_POST['highlight_weekends'] !== '0' ) ? 1 : 0;

        update_user_meta( get_current_user_id(), 'optee_stats_decimals', $decimals );
        update_user_meta( get_current_user_id(), 'optee_highlight_weekends', $weekends );

        wp_send_json_success( [
            'decimals'           => $decimals,
            'highlight_weekends' => $weekends,
            'message'            => 'Display preferences saved.',
        ] );
    }

    public function reset_automation_lock(): void {
        $this->verify();

        delete_transient( 'optee_automation_lock' );

        Logger::log( 'info', 'cron', 'Automation lock manually reset by user.', [
            'user_id' => get_current_user_id(),
        ] );

        wp_send_json_success( [ 'message' => 'Automation lock has been reset.' ] );
    }

    public function send_test_email(): void {
        $this->verify();

        $to       = sanitize_email( $_POST['to'] ?? '' );
        $template = sanitize_text_field( $_POST['template'] ?? 'automation' );

        if ( empty( $to ) || ! is_email( $to ) ) {
            wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ] );
        }

        if ( $template === 'notifications' ) {
            $notifications = [
                [
                    'type'    => 'info',
                    'title'   => 'Block zones with 10+ visits and 0 conversions',
                    'message' => 'PopAds Test 2026 #2 — executed: 27 zone(s) matched conditions but all were already blocked.',
                    'time'    => current_time( 'H:i:s' ),
                ],
                [
                    'type'    => 'warning',
                    'title'   => 'Block zones if fraud > 30%',
                    'message' => 'Mondiad Push #3 — Cannot perform zone actions on whitelist campaigns.',
                    'time'    => current_time( 'H:i:s' ),
                ],
                [
                    'type'    => 'error',
                    'title'   => 'Resume campaign if conversions > 3',
                    'message' => 'PropellerAds RON Test — API error: rate limit exceeded.',
                    'time'    => current_time( 'H:i:s' ),
                ],
            ];
            $html    = EmailTemplate::rule_notifications( $notifications );
            $subject = 'Optee — Rule Notifications (Test)';
        } else {
            $lines = [
                [ 'time' => current_time( 'H:i:s' ), 'task' => 'BALANCE',  'detail' => 'Adcash — $0.998 EUR',                    'duration' => '0.7s', 'success' => true ],
                [ 'time' => current_time( 'H:i:s' ), 'task' => 'EARNINGS', 'detail' => 'Partners.House — 30 days',                'duration' => '1.2s', 'success' => true ],
                [ 'time' => current_time( 'H:i:s' ), 'task' => 'VT SCAN',  'detail' => 'example.com — 0/95 flagged',              'duration' => '0.9s', 'success' => true ],
                [ 'time' => current_time( 'H:i:s' ), 'task' => 'UPDATES',  'detail' => 'All plugins up to date',                  'duration' => '0.9s', 'success' => true ],
                [ 'time' => current_time( 'H:i:s' ), 'task' => 'RULE',     'detail' => 'Block zones with 10+ visits — executed: 5 zone(s) blocked.', 'duration' => '3.2s', 'success' => true ],
            ];
            $html    = EmailTemplate::automation_run( $lines, 6.9 );
            $subject = 'Optee — Automation Run (Test)';
        }

        add_filter( 'wp_mail_content_type', [ $this, 'html_content_type' ] );
        $sent = wp_mail( $to, $subject, $html );
        remove_filter( 'wp_mail_content_type', [ $this, 'html_content_type' ] );

        if ( $sent ) {
            wp_send_json_success( [ 'message' => "Test email sent to {$to}. Check your inbox (and spam folder)." ] );
        } else {
            wp_send_json_error( [ 'message' => 'wp_mail() failed. Your server may not have mail configured. Consider installing an SMTP plugin (e.g. WP Mail SMTP).' ] );
        }
    }

    public function html_content_type(): string {
        return 'text/html';
    }

    public function save_timezone(): void {
        $this->verify();

        $tz = sanitize_text_field( $_POST['timezone'] ?? '' );

        if ( ! $tz || ! in_array( $tz, timezone_identifiers_list(), true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid timezone.' ] );
        }

        update_option( 'timezone_string', $tz );
        update_option( 'gmt_offset', 0 );

        $now = new \DateTime( 'now', new \DateTimeZone( $tz ) );
        wp_send_json_success( [
            'message'     => 'Timezone updated to ' . $tz . '.',
            'timezone'    => $tz,
            'server_time' => $now->format( 'H:i:s' ),
        ] );
    }

    public function save_lock_timeout(): void {
        $this->verify();

        $timeout = intval( $_POST['lock_timeout'] ?? 5 );
        $timeout = max( 5, min( 30, $timeout ) );
        update_user_meta( get_current_user_id(), 'optee_lock_timeout', $timeout );
        wp_send_json_success( [ 'message' => 'Lock timeout saved.', 'lock_timeout' => $timeout ] );
    }

    public function save_max_execution_time(): void {
        $this->verify();

        $value = intval( $_POST['max_execution_time'] ?? 0 );
        $value = max( 0, min( 300, $value ) );
        update_user_meta( get_current_user_id(), 'optee_max_execution_time', $value );

        $label = $value > 0 ? "{$value}s" : 'auto (' . intval( ini_get( 'max_execution_time' ) ) . 's from PHP)';
        wp_send_json_success( [ 'message' => "Max execution time saved: {$label}.", 'max_execution_time' => $value ] );
    }

    public function check_offer_status(): void {
        $this->verify();

        $slug     = sanitize_text_field( $_POST['module_slug'] ?? '' );
        $offer_id = sanitize_text_field( $_POST['offer_id'] ?? '' );

        if ( ! $slug || ! $offer_id ) {
            wp_send_json_error( [ 'message' => 'Module slug and offer ID are required.' ] );
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( $slug, 'affiliate_network' );

        if ( ! $module ) {
            wp_send_json_error( [ 'message' => 'Affiliate network module not found.' ] );
        }

        $creds = ModuleRegistry::get_stored_credentials( 'affiliate_network', $slug );
        if ( ! $creds ) {
            wp_send_json_error( [ 'message' => 'No credentials configured for ' . $module->get_name() . '.' ] );
        }

        $module->set_credentials( $creds );

        if ( ! method_exists( $module, 'get_offer_status' ) ) {
            wp_send_json_error( [ 'message' => 'This module does not support offer status checks.' ] );
        }

        $result = $module->get_offer_status( $offer_id );

        wp_send_json_success( $result );
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    private static function resolve_module_name( string $slug ): string {
        if ( ! $slug ) {
            return '';
        }
        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( $slug );
        return $module ? $module->get_name() : $slug;
    }

    private static function format_campaign( \WP_Post $post ): array {
        $ts_slug  = get_post_meta( $post->ID, '_optee_traffic_source_module', true );
        $tr_slug  = get_post_meta( $post->ID, '_optee_tracker_module', true );

        $rules_count = (int) ( new \WP_Query( [
            'post_type'      => 'optee_rule',
            'post_status'    => 'publish',
            'meta_key'       => '_optee_rule_campaign_id',
            'meta_value'     => $post->ID,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ] ) )->found_posts;

        return [
            'id'                           => $post->ID,
            'rules_count'                  => $rules_count,
            'title'                        => $post->post_title,
            'date'                         => $post->post_date,
            'traffic_source_module'        => self::resolve_module_name( $ts_slug ),
            'traffic_source_module_slug'   => $ts_slug,
            'traffic_source_campaign_id'   => get_post_meta( $post->ID, '_optee_traffic_source_campaign_id', true ),
            'traffic_source_campaign_name' => get_post_meta( $post->ID, '_optee_traffic_source_campaign_name', true ),
            'tracker_module'               => self::resolve_module_name( $tr_slug ),
            'tracker_module_slug'          => $tr_slug,
            // Strip the optional "|trafficSourceId" suffix so the form/list see the bare campaign id.
            'tracker_campaign_id'          => explode( '|', (string) get_post_meta( $post->ID, '_optee_tracker_campaign_id', true ) )[0],
            'tracker_campaign_name'        => get_post_meta( $post->ID, '_optee_tracker_campaign_name', true ),
            'tracker_ts_id'                => get_post_meta( $post->ID, '_optee_tracker_ts_id', true ),
            'tracker_ts_name'              => get_post_meta( $post->ID, '_optee_tracker_ts_name', true ),
            'tracker_campaign_ids'         => get_post_meta( $post->ID, '_optee_tracker_campaign_ids', true ),
            'zone_id'                      => get_post_meta( $post->ID, '_optee_zone_id', true ),
            'zone_name'                    => get_post_meta( $post->ID, '_optee_zone_name', true ),
            'affiliate_network_module'     => get_post_meta( $post->ID, '_optee_affiliate_network_module', true ),
            'antifraud_module'             => get_post_meta( $post->ID, '_optee_antifraud_module', true ),
            'antifraud_campaign_id'        => get_post_meta( $post->ID, '_optee_antifraud_campaign_id', true ),
            'antifraud_campaign_name'      => get_post_meta( $post->ID, '_optee_antifraud_campaign_name', true ),
            'monetization_module'          => get_post_meta( $post->ID, '_optee_monetization_module', true ),
            'offer_id'                     => get_post_meta( $post->ID, '_optee_offer_id', true ),
            'is_smartlink'                 => get_post_meta( $post->ID, '_optee_is_smartlink', true ),
            'status'                       => get_post_meta( $post->ID, '_optee_status', true ) ?: 'active',
        ];
    }

    /**
     * Get dashboard stats for multiple campaigns in a single request.
     * Only fetches tracker data (the dashboard doesn't need TS/AF/monetization rows).
     */
    public function get_dashboard_stats(): void {
        $this->verify();

        $campaign_ids  = array_map( 'absint', (array) ( $_POST['campaign_ids'] ?? [] ) );
        $timeframe     = sanitize_key( $_POST['timeframe'] ?? '7d' );
        $force_refresh = ! empty( $_POST['force_refresh'] );

        if ( empty( $campaign_ids ) ) {
            wp_send_json_success( [ 'campaigns' => [], 'cache_status' => [], 'cache_ttl' => 0 ] );
        }

        $dates     = self::resolve_range_from_request( $timeframe );
        $date_from = $dates['from'];
        $date_to   = $dates['to'];

        $user_id = get_current_user_id();
        if ( metadata_exists( 'user', $user_id, 'optee_cache_ttl' ) ) {
            $cache_ttl = intval( get_user_meta( $user_id, 'optee_cache_ttl', true ) );
        } else {
            $cache_ttl = 5;
        }
        $ttl_seconds = $cache_ttl * 60;

        $registry           = ModuleRegistry::get_instance();
        $automation_running = ( get_transient( 'optee_automation_lock' ) !== false );
        $cache_status       = [];
        $results            = []; // campaign_id => { visits, clicks, conv, cost, revenue }
        $module_errors      = [];

        // Group campaigns by tracker module.
        $tracker_groups = []; // tracker_slug => [ [ campaign_id, tracker_campaign_id ], ... ]
        foreach ( $campaign_ids as $cid ) {
            $post = get_post( $cid );
            if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== $user_id ) {
                continue;
            }

            $tr_slug        = get_post_meta( $cid, '_optee_tracker_module', true );
            $tr_campaign_id = get_post_meta( $cid, '_optee_tracker_campaign_id', true );

            if ( $tr_slug && $tr_campaign_id ) {
                $tracker_groups[ $tr_slug ][] = [
                    'campaign_id'         => $cid,
                    'tracker_campaign_id' => $tr_campaign_id,
                ];
            } else {
                $results[ $cid ] = null;
            }
        }

        // For each tracker module, fetch stats for all its campaigns in one batch.
        foreach ( $tracker_groups as $tr_slug => $group ) {
            $tr_module = $registry->get_module( $tr_slug );
            if ( ! $tr_module || ! method_exists( $tr_module, 'get_dashboard_statistics' ) ) {
                foreach ( $group as $item ) {
                    $results[ $item['campaign_id'] ] = null;
                }
                continue;
            }

            $tr_module->set_credentials(
                ModuleRegistry::get_stored_credentials( 'tracker', $tr_slug ) ?: []
            );

            // Collect remote campaign IDs and build local→remote mapping.
            $remote_ids     = []; // remote_campaign_id => local_campaign_id
            $remote_id_list = [];
            foreach ( $group as $item ) {
                $remote_ids[ $item['tracker_campaign_id'] ] = $item['campaign_id'];
                $remote_id_list[] = $item['tracker_campaign_id'];
            }

            // Cache key for the entire batch.
            $batch_cache_key = 'optee_dash_' . md5( $user_id . '_' . $tr_slug . '_' . $date_from . '_' . $date_to . '_' . implode( ',', $remote_id_list ) );
            $is_cached       = false;

            if ( $ttl_seconds > 0 && ! $force_refresh ) {
                $cached_batch = get_transient( $batch_cache_key );
                if ( $cached_batch !== false ) {
                    $batch_data = $cached_batch;
                    $is_cached  = true;
                }
            }

            if ( ! $is_cached && $automation_running ) {
                // Skip live API calls during automation.
                $cache_status[ $tr_slug ] = [
                    'cached'      => false,
                    'module_name' => $tr_module->get_name(),
                    'module_type' => 'tracker',
                    'skipped'     => true,
                ];
                foreach ( $group as $item ) {
                    $results[ $item['campaign_id'] ] = null;
                }
                continue;
            }

            if ( ! $is_cached ) {
                try {
                    $batch_data = $tr_module->get_dashboard_statistics( $remote_id_list, $date_from, $date_to );
                    if ( $ttl_seconds > 0 ) {
                        set_transient( $batch_cache_key, $batch_data, $ttl_seconds );
                    }
                } catch ( \Exception $e ) {
                    foreach ( $group as $item ) {
                        $results[ $item['campaign_id'] ] = null;
                    }
                    $module_errors[] = [
                        'module'  => $tr_module->get_name(),
                        'message' => $e->getMessage(),
                    ];
                    $cache_status[ $tr_slug ] = [
                        'cached'      => false,
                        'module_name' => $tr_module->get_name(),
                        'module_type' => 'tracker',
                    ];
                    continue;
                }
            }

            $cache_status[ $tr_slug ] = [
                'cached'      => $is_cached,
                'module_name' => $tr_module->get_name(),
                'module_type' => 'tracker',
            ];

            // Map remote results back to local campaign IDs.
            foreach ( $batch_data as $remote_cid => $stats ) {
                if ( isset( $remote_ids[ $remote_cid ] ) ) {
                    $local_cid = $remote_ids[ $remote_cid ];
                    if ( $stats === null ) {
                        $results[ $local_cid ] = null;
                    } else {
                        $results[ $local_cid ] = [
                            'visits'  => (int) ( $stats['tr_visits'] ?? 0 ),
                            'clicks'  => (int) ( $stats['tr_clicks'] ?? 0 ),
                            'conv'    => (int) ( $stats['tr_conv'] ?? 0 ),
                            'cost'    => (float) ( $stats['tr_cost'] ?? 0 ),
                            'revenue' => (float) ( $stats['tr_revenue'] ?? 0 ),
                        ];
                    }
                }
            }
        }

        wp_send_json_success( [
            'campaigns'     => $results,
            'cache_status'  => $cache_status,
            'cache_ttl'     => $cache_ttl,
            'module_errors' => $module_errors,
        ] );
    }

    /**
     * Get campaign statistics data.
     */
    /**
     * Per-day breakout for the zones (default) report.
     *
     * Fetches the campaign's zone-grouped tracker stats for each of the last N days
     * SEPARATELY (Today = d0, Yesterday = d1, …) and merges them by zone id, so the
     * table can show standalone single-day columns per selected metric alongside the
     * aggregate report. Fetches are SEQUENTIAL (never parallel) to stay gentle on
     * rate-limited tracker APIs; closed days (d>0) are immutable so they get a long
     * cache TTL and are fetched at most once, leaving only "today" on the short TTL.
     *
     * Response: { rows: { zoneId: { "tr_profit_d0": .., "tr_roi_d0": .., "tr_profit_d1": .. } },
     *             metrics: [...], days: ["Y-m-d" (d0=today), ...] }
     */
    public function get_campaign_perday(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $campaign    = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        // Requested metrics (subset of tracker metric keys) + day count (1–7).
        $allowed_metrics = [ 'tr_visits', 'tr_clicks', 'tr_conv', 'tr_conv_all', 'tr_revenue', 'tr_cost', 'tr_profit', 'tr_roi', 'tr_ctr', 'tr_cv', 'tr_cpm', 'tr_cpa', 'tr_ap' ];
        $raw_metrics     = isset( $_POST['metrics'] ) && is_array( $_POST['metrics'] ) ? $_POST['metrics'] : [];
        $metrics         = array_values( array_intersect( array_map( 'sanitize_key', $raw_metrics ), $allowed_metrics ) );
        if ( empty( $metrics ) ) {
            $metrics = [ 'tr_profit', 'tr_roi' ];
        }
        $days = max( 1, min( 7, absint( $_POST['days'] ?? 4 ) ) );

        // Resolve the tracker + its campaign id + the campaign's zone field.
        $tr_slug        = get_post_meta( $campaign_id, '_optee_tracker_module', true );
        $tr_campaign_id = get_post_meta( $campaign_id, '_optee_tracker_campaign_id', true );
        $zone_field     = get_post_meta( $campaign_id, '_optee_zone_id', true );
        if ( ! $tr_slug || ! $tr_campaign_id || ! $zone_field ) {
            wp_send_json_error( [ 'message' => 'Per-day columns need a tracker and a zone field on this campaign.' ] );
        }

        $registry  = ModuleRegistry::get_instance();
        $tr_module = $registry->get_module( $tr_slug );
        if ( ! $tr_module || ! method_exists( $tr_module, 'get_statistics' ) ) {
            wp_send_json_error( [ 'message' => 'Tracker not available.' ] );
        }
        $tr_module->set_credentials( ModuleRegistry::get_stored_credentials( 'tracker', $tr_slug ) ?: [] );

        $user_id = get_current_user_id();
        if ( metadata_exists( 'user', $user_id, 'optee_cache_ttl' ) ) {
            $cache_ttl = intval( get_user_meta( $user_id, 'optee_cache_ttl', true ) );
        } else {
            $cache_ttl = 5;
        }
        $today_ttl  = $cache_ttl * 60;
        $closed_ttl = max( $today_ttl, 12 * HOUR_IN_SECONDS );

        $automation_running = ( get_transient( 'optee_automation_lock' ) !== false );
        $force_refresh      = ! empty( $_POST['force_refresh'] );
        $ts_slug            = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );

        $today   = current_time( 'Y-m-d' );
        $perday  = [];   // zoneId => [ 'tr_profit_d0' => .., ... ]
        $labels  = [];
        for ( $d = 0; $d < $days; $d++ ) {
            $labels[] = ( $d === 0 ) ? $today : wp_date( 'Y-m-d', strtotime( "-{$d} days", strtotime( $today ) ) );
        }

        // ── Fast path: trackers that return the whole zone×day matrix in ONE call
        // (BeMob supports multiple groupBy dimensions). One request replaces the N
        // single-day calls below, so it never burns the shared, rate-limited tracker
        // budget — the per-day columns fill in a single load instead of drip-feeding.
        if ( method_exists( $tr_module, 'get_zone_day_matrix' ) ) {
            $from   = $labels[ $days - 1 ];   // oldest day in the range
            $mkey   = self::stats_cache_key( $user_id, $campaign_id, $tr_slug, 'matrix', $from, $today );
            $matrix = ( $today_ttl > 0 && ! $force_refresh ) ? get_transient( $mkey ) : false;

            if ( $matrix === false ) {
                // Don't compete with the automation cron for the rate-limit budget.
                if ( $automation_running ) {
                    wp_send_json_success( [ 'rows' => [], 'metrics' => $metrics, 'days' => $labels, 'pending' => $days ] );
                }
                try {
                    $matrix = $tr_module->get_zone_day_matrix( $tr_campaign_id, $from, $today, $zone_field );
                    if ( $today_ttl > 0 ) {
                        set_transient( $mkey, $matrix, $today_ttl );
                    }
                } catch ( \Throwable $e ) {
                    $msg  = $e->getMessage();
                    $rate = ( stripos( $msg, 'rate_limit' ) !== false || stripos( $msg, '429' ) !== false || stripos( $msg, 'too many' ) !== false );
                    Logger::log( $rate ? 'warning' : 'error', 'perday', 'per-day matrix fetch failed', [ 'error' => $msg, 'rate_limit' => $rate ] );
                    wp_send_json_success( [ 'rows' => [], 'metrics' => $metrics, 'days' => $labels, 'pending' => $days ] );
                }
            }

            $date_to_d = array_flip( $labels );   // 'Y-m-d' => day index (d0 = today)
            foreach ( (array) $matrix as $row ) {
                $date = (string) ( $row['date'] ?? '' );
                if ( ! isset( $date_to_d[ $date ] ) ) {
                    continue;
                }
                $d   = $date_to_d[ $date ];
                $key = ! empty( $row['zone_id'] ) ? (string) $row['zone_id'] : $date;
                $key = (string) apply_filters( 'optee_tracker_zone_key', $key, [
                    'tracker'        => $tr_slug,
                    'traffic_source' => $ts_slug,
                    'campaign_id'    => $campaign_id,
                    'drilldown'      => 'default',
                ] );
                if ( $key === '' ) {
                    continue;
                }
                if ( ! isset( $perday[ $key ] ) ) {
                    $perday[ $key ] = [];
                }
                foreach ( $metrics as $m ) {
                    $perday[ $key ][ $m . '_d' . $d ] = $row[ $m ] ?? 0;
                }
            }

            wp_send_json_success( [ 'rows' => $perday, 'metrics' => $metrics, 'days' => $labels, 'pending' => 0 ] );
        }

        // ── Fallback: per-day single-day calls, rate-limit-aware (drip-fed, re-polled).
        // BeMob-style trackers rate-limit hard (~1 request / 10s, small burst) and that
        // budget is shared with the automation cron — so we must NOT fire a burst of N
        // single-day calls. Instead: serve every cached day instantly, and spend at most
        // a couple of LIVE calls per request. Days we don't reach stay "pending" and the
        // frontend re-polls (~10s apart) until the cache is fully warm. A rate-limited
        // call throws before caching, so failures never poison the cache with empty rows.
        $max_live  = max( 1, (int) apply_filters( 'optee_perday_max_live_calls', 2 ) );
        $live_used = 0;
        $pending   = 0;
        $debug     = defined( 'WP_DEBUG' ) && WP_DEBUG;

        for ( $d = 0; $d < $days; $d++ ) {
            $date = $labels[ $d ];
            $ttl  = ( $d === 0 ) ? $today_ttl : $closed_ttl;

            $cache_key = self::stats_cache_key( $user_id, $campaign_id, $tr_slug, 'default', $date, $date );

            // Cache first — cached days are free (no API call, no rate-limit cost).
            $rows = ( $ttl > 0 && ! $force_refresh ) ? get_transient( $cache_key ) : false;

            if ( $rows === false ) {
                // Cache miss. Only spend a live call if we still have per-request budget
                // and the automation cron isn't already using the API right now.
                if ( $live_used >= $max_live || $automation_running ) {
                    $pending++;
                    continue; // leave for a later poll
                }

                // Small spacing between consecutive live calls — cheap insurance on top
                // of the per-request cap (the real spacing is the frontend's re-poll).
                if ( $live_used > 0 ) {
                    usleep( 250000 );
                }

                $t0 = microtime( true );
                try {
                    $rows = $tr_module->get_statistics( $tr_campaign_id, $date, $date, $zone_field );
                    $live_used++;
                    if ( $ttl > 0 ) {
                        set_transient( $cache_key, $rows, $ttl );
                    }
                    if ( $debug ) {
                        Logger::log( 'debug', 'perday', 'per-day live fetch', [
                            'date' => $date,
                            'ms'   => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
                            'rows' => count( (array) $rows ),
                        ] );
                    }
                } catch ( \Throwable $e ) {
                    $live_used++;   // the attempt still consumed a rate-limit token
                    $pending++;     // not cached → retried on a later poll
                    $msg  = $e->getMessage();
                    $rate = ( stripos( $msg, 'rate_limit' ) !== false || stripos( $msg, '429' ) !== false || stripos( $msg, 'too many' ) !== false );
                    Logger::log( $rate ? 'warning' : 'error', 'perday', 'per-day fetch failed', [
                        'date'       => $date,
                        'error'      => $msg,
                        'rate_limit' => $rate,
                    ] );
                    continue;
                }
            }

            foreach ( (array) $rows as $row ) {
                $key = ! empty( $row['zone_id'] ) ? (string) $row['zone_id'] : (string) ( $row['date'] ?? '' );
                $key = (string) apply_filters( 'optee_tracker_zone_key', $key, [
                    'tracker'        => $tr_slug,
                    'traffic_source' => $ts_slug,
                    'campaign_id'    => $campaign_id,
                    'drilldown'      => 'default',
                ] );
                if ( $key === '' ) {
                    continue;
                }
                if ( ! isset( $perday[ $key ] ) ) {
                    $perday[ $key ] = [];
                }
                foreach ( $metrics as $m ) {
                    $perday[ $key ][ $m . '_d' . $d ] = $row[ $m ] ?? 0;
                }
            }
        }

        wp_send_json_success( [
            'rows'    => $perday,
            'metrics' => $metrics,
            'days'    => $labels,
            'pending' => $pending,
        ] );
    }

    /**
     * Persist the per-campaign "per-day breakout" configuration (enabled flag,
     * days-back count, and the chosen metric keys). Stored in post_meta so it
     * round-trips per campaign the same way custom-column assignments do.
     */
    public function save_perday_config(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $campaign    = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $allowed_metrics = [ 'tr_visits', 'tr_clicks', 'tr_conv', 'tr_conv_all', 'tr_revenue', 'tr_cost', 'tr_profit', 'tr_roi', 'tr_ctr', 'tr_cv', 'tr_cpm', 'tr_cpa', 'tr_ap' ];
        $raw_metrics     = isset( $_POST['metrics'] ) && is_array( $_POST['metrics'] ) ? $_POST['metrics'] : [];
        $metrics         = array_values( array_intersect( array_map( 'sanitize_key', $raw_metrics ), $allowed_metrics ) );

        $config = [
            'enabled' => ! empty( $_POST['enabled'] ) && $_POST['enabled'] !== '0' && $_POST['enabled'] !== 'false',
            'days'    => max( 1, min( 7, absint( $_POST['days'] ?? 4 ) ) ),
            'metrics' => $metrics ?: [ 'tr_profit', 'tr_roi' ],
        ];

        update_post_meta( $campaign_id, '_optee_perday_config', wp_json_encode( $config ) );
        wp_send_json_success( [ 'config' => $config ] );
    }

    /**
     * Registry of PopAds-forwarded tracker tokens → report drilldown dimensions.
     * Each becomes a Category-style drilldown (tracker performance keyed by the
     * forwarded targeting value). `annotate`: 'id' = tracker value is a PopAds ID →
     * annotate with its name; 'name' = tracker value is a name → annotate with the
     * matched PopAds option; '' = no annotation (value already readable / no catalog).
     *
     * @return array<string, array{token:string,label:string,first_label:string,annotate:string,ann_label:string}>
     */
    public static function token_dim_registry(): array {
        return [
            // 'category' / 'form_factor' add NEW tabs. 'isp' matches an EXISTING native
            // tracker drilldown → the mapped path overrides it (shows the traffic
            // source's forwarded [ISPNAME], the values PopAds actually targets), and
            // falls back to native tracker ISP for sources that don't forward it.
            'category'    => [ 'token' => '[CATEGORYID]',     'label' => 'Category',    'first_label' => 'Category ID', 'annotate' => 'id',   'ann_label' => 'Category' ],
            'form_factor' => [ 'token' => '[FORMFACTORNAME]', 'label' => 'Form Factor', 'first_label' => 'Form Factor', 'annotate' => 'name', 'ann_label' => 'Target' ],
            'isp'         => [ 'token' => '[ISPNAME]',        'label' => 'ISP',         'first_label' => 'ISP',         'annotate' => '',     'ann_label' => '' ],
            'resolution'  => [ 'token' => '[SCREENRESOLUTION]', 'label' => 'Resolution', 'first_label' => 'Resolution', 'annotate' => '',   'ann_label' => '' ],
        ];
    }

    /**
     * Resolve which tracker custom slot holds a targeting dimension's forwarded
     * token — honouring a per-campaign OVERRIDE (`_optee_token_mapping`) first, then
     * falling back to auto-detection from the tracker's placeholder tokens. Returns
     * the slot (e.g. 'custom3') or null. This is the single source of truth used by
     * the report, the rule engine, and the mapping UI.
     */
    public static function resolve_dim_slot( int $campaign_id, string $dim ): ?string {
        $override = get_post_meta( $campaign_id, '_optee_token_mapping', true );
        if ( is_array( $override ) && isset( $override[ $dim ] ) && $override[ $dim ] !== '' ) {
            return $override[ $dim ] === 'none' ? null : (string) $override[ $dim ];
        }

        $reg = self::token_dim_registry();
        if ( ! isset( $reg[ $dim ] ) ) {
            return null;
        }
        $tr_slug = get_post_meta( $campaign_id, '_optee_tracker_module', true );
        $tr_cid  = get_post_meta( $campaign_id, '_optee_tracker_campaign_id', true );
        if ( ! $tr_slug || ! $tr_cid ) {
            return null;
        }
        $module = ModuleRegistry::get_instance()->get_module_with_credentials( $tr_slug );
        if ( ! $module || ! method_exists( $module, 'get_source_tokens' ) ) {
            return null;
        }
        try {
            $toks = $module->get_source_tokens( $tr_cid );
            return $toks[ $reg[ $dim ]['token'] ]['slot'] ?? null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * Return the token-mapping state for the campaign's mapping override UI: every
     * mappable dimension with its auto-detected slot, the current override (if any),
     * and the list of available tracker custom slots (with names) to choose from.
     */
    public function get_token_mapping(): void {
        $this->verify();
        $campaign_id = intval( $_POST['campaign_id'] ?? 0 );
        $campaign    = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
        }

        $override = get_post_meta( $campaign_id, '_optee_token_mapping', true );
        $override = is_array( $override ) ? $override : [];

        // Available tracker custom slots (+ their names + tokens), auto-detected.
        $slots   = [];
        $tr_slug = get_post_meta( $campaign_id, '_optee_tracker_module', true );
        $tr_cid  = get_post_meta( $campaign_id, '_optee_tracker_campaign_id', true );
        $token_to_slot = [];
        if ( $tr_slug && $tr_cid ) {
            $module = ModuleRegistry::get_instance()->get_module_with_credentials( $tr_slug );
            if ( $module && method_exists( $module, 'get_source_tokens' ) ) {
                try {
                    foreach ( $module->get_source_tokens( $tr_cid ) as $token => $info ) {
                        $slot = $info['slot'] ?? '';
                        if ( $slot ) {
                            $slots[]              = [ 'slot' => $slot, 'name' => $info['name'] ?? $slot, 'token' => $token ];
                            $token_to_slot[ $token ] = $slot;
                        }
                    }
                } catch ( \Throwable $e ) {}
            }
        }
        usort( $slots, fn( $a, $b ) => strnatcmp( $a['slot'], $b['slot'] ) );

        $dims = [];
        foreach ( self::token_dim_registry() as $dim => $cfg ) {
            $auto = $token_to_slot[ $cfg['token'] ] ?? '';
            $dims[] = [
                'dim'       => $dim,
                'label'     => $cfg['label'],
                'token'     => $cfg['token'],
                'auto_slot' => $auto,                              // detected from tokens
                'override'  => $override[ $dim ] ?? '',            // '' = use auto, 'none' = disabled, or a slot
                'effective' => self::resolve_dim_slot( $campaign_id, $dim ) ?? '',
            ];
        }

        wp_send_json_success( [ 'dimensions' => $dims, 'slots' => $slots ] );
    }

    /** Human labels for the removable targeting dimensions. */
    public static function targeting_dim_labels(): array {
        return [
            'category'         => 'Category',
            'os'               => 'OS',
            'browser'          => 'Browser',
            'form_factor'      => 'Form Factor',
            'connection_type'  => 'Connection Type',
            'connection_speed' => 'Connection Speed',
            'isp'              => 'ISP',
            'resolution'       => 'Resolution',
            'device'           => 'Device',
        ];
    }

    /**
     * Which targeting dimensions a "Remove Targeting" rule can actually use for this
     * campaign: the traffic source must support removing the dimension AND the tracker
     * must be able to supply per-value performance (a native group, or a forwarded token
     * resolved via the mapping). Drives the Rules dimension dropdown.
     */
    public function get_rule_targeting_dims(): void {
        $this->verify();
        $campaign_id = intval( $_POST['campaign_id'] ?? 0 );
        $campaign    = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
        }

        $out     = [];
        $ts_slug = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $module  = $ts_slug ? ModuleRegistry::get_instance()->get_module( $ts_slug ) : null;
        $has_tracker = (bool) get_post_meta( $campaign_id, '_optee_tracker_module', true );

        if ( $module && method_exists( $module, 'remove_targeting' ) && method_exists( $module, 'get_supported_targeting_dims' ) && $has_tracker ) {
            $labels = self::targeting_dim_labels();
            // Dimensions the tracker can report natively (no forwarded token needed).
            // BeMob's 'connection' group is connection SPEED (Cable/DSL, Cellular…),
            // which maps to PopAds connection_speed — NOT connection_type (a different
            // taxonomy PopAds uses: Business/Residential/…, which BeMob doesn't report).
            $native = [ 'os' => 'os', 'browser' => 'browsers', 'connection_speed' => 'connection', 'isp' => 'isp' ];
            foreach ( $module->get_supported_targeting_dims() as $dim ) {
                $available = isset( $native[ $dim ] ) || ( self::resolve_dim_slot( $campaign_id, $dim ) !== null );
                if ( $available ) {
                    $out[] = [ 'dim' => $dim, 'label' => $labels[ $dim ] ?? $dim ];
                }
            }
        }

        wp_send_json_success( [ 'dims' => $out ] );
    }

    /** Save the per-campaign token-mapping overrides ('' = auto, 'none' = off, or a custom slot). */
    public function save_token_mapping(): void {
        $this->verify();
        $campaign_id = intval( $_POST['campaign_id'] ?? 0 );
        $campaign    = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
        }

        $decoded = json_decode( wp_unslash( $_POST['mapping'] ?? '' ), true );
        $valid_dims  = array_keys( self::token_dim_registry() );
        $out = [];
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $dim => $slot ) {
                if ( ! in_array( $dim, $valid_dims, true ) ) {
                    continue;
                }
                $slot = sanitize_text_field( (string) $slot );
                // Keep only meaningful overrides: 'none' or a customN slot. '' = auto (omit).
                if ( $slot === 'none' || preg_match( '/^custom([1-9]|10)$/', $slot ) ) {
                    $out[ $dim ] = $slot;
                }
            }
        }

        if ( $out ) {
            update_post_meta( $campaign_id, '_optee_token_mapping', $out );
        } else {
            delete_post_meta( $campaign_id, '_optee_token_mapping' );
        }

        wp_send_json_success( [ 'message' => 'Mapping saved.', 'mapping' => $out ] );
    }

    public function get_campaign_stats(): void {
        $this->verify();

        $campaign_id   = absint( $_POST['campaign_id'] ?? 0 );
        $drilldown     = sanitize_key( $_POST['drilldown'] ?? 'default' );
        $timeframe     = sanitize_key( $_POST['timeframe'] ?? '7d' );
        $force_refresh = ! empty( $_POST['force_refresh'] );

        $campaign = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        // Calculate date range from timeframe (supports the `custom` range picker).
        $dates     = self::resolve_range_from_request( $timeframe );
        $date_from = $dates['from'];
        $date_to   = $dates['to'];

        $now       = current_time( 'd.m.Y, H:i' );
        $cron_last = get_option( 'optee_cron_last_complete', '' );

        // Read cache TTL from user meta (default 5 min if never set).
        $user_id = get_current_user_id();
        if ( metadata_exists( 'user', $user_id, 'optee_cache_ttl' ) ) {
            $cache_ttl = intval( get_user_meta( $user_id, 'optee_cache_ttl', true ) );
        } else {
            $cache_ttl = 5;
        }
        $ttl_seconds = $cache_ttl * 60;

        $registry           = ModuleRegistry::get_instance();
        $rows               = [];
        $cache_status       = [];
        $automation_running = ( get_transient( 'optee_automation_lock' ) !== false );
        $skipped_modules    = [];
        $module_errors      = [];

        // Drilldowns that only need tracker data — skip traffic source and antifraud.
        $tracker_only_drilldowns = [ 'geo', 'languages', 'cities', 'regions', 'browsers', 'browser-versions', 'useragents', 'isp', 'mobile-carriers', 'os', 'os-versions', 'connection', 'lp', 'offers', 'conversions', 'months' ];
        $is_tracker_only         = in_array( $drilldown, $tracker_only_drilldowns, true )
                                   || preg_match( '/^(custom|t)\d+$/', $drilldown );

        // Countries (geo) drilldown: when the traffic source exposes native per-country
        // stats (e.g. PopAds), merge them INTO the geo report so the traffic-source and
        // tracker columns sit side by side (matched on country name) — makes cross-platform
        // mismatches obvious. Falls back to tracker-only geo for sources without it.
        // ('ts_geo' kept as an alias for any old links.) Antifraud + monetization skipped.
        $ts_slug_cap = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $country_cmp = $ts_slug_cap
            && ( $tsm_cap = $registry->get_module( $ts_slug_cap ) )
            && method_exists( $tsm_cap, 'get_country_stats' )
            && in_array( $drilldown, [ 'geo', 'ts_geo' ], true );
        if ( $country_cmp ) {
            $is_tracker_only = false; // allow the traffic-source fetch to run for geo
        }

        // Mapped tracker-token dimensions: the traffic source forwards a targeting
        // token (e.g. [CATEGORYID]) into a tracker custom slot, so the tracker reports
        // performance keyed by the PopAds targeting value for a dimension PopAds itself
        // doesn't report. Registry: drilldown key → PopAds token. Extensible.
        $token_reg   = self::token_dim_registry();
        $mapped_dim  = null;
        $mapped_slot = null;
        if ( isset( $token_reg[ $drilldown ] ) ) {
            $is_tracker_only = true; // mapped dims are tracker-only (no TS/AF/monetization perf)
            $slot = self::resolve_dim_slot( $campaign_id, $drilldown ); // override-aware
            if ( $slot ) {
                $mapped_dim  = $drilldown;
                $mapped_slot = $slot;
            }
        }

        // Zone drilldown: scope the whole report to a single zone. Pair the campaign's
        // zone field with the requested zone value. While a zone is selected the report
        // is tracker-scoped (the tracker returns just that zone), so the traffic-source /
        // antifraud / monetization merges are skipped.
        $zone_filter       = null;
        $zone_filter_value = sanitize_text_field( $_POST['zone_filter'] ?? '' );
        if ( $zone_filter_value !== '' ) {
            $zf_field = get_post_meta( $campaign_id, '_optee_zone_id', true );
            if ( $zf_field ) {
                $zone_filter     = [ 'field' => $zf_field, 'value' => $zone_filter_value ];
                $is_tracker_only = true;
            }
        }

        // --- Traffic Source stats ---
        $ts_slug        = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        // Grouped campaigns (one creative = one source campaign) may scope the report to a
        // single creative. An empty/invalid member means "All campaigns" (the full group).
        $ts_member      = sanitize_text_field( $_POST['ts_campaign_member'] ?? '' );
        $ts_cache_scope = $ts_slug;
        if ( $ts_member !== '' && strpos( (string) $ts_campaign_id, ',' ) !== false ) {
            $members = array_map( 'trim', explode( ',', $ts_campaign_id ) );
            if ( in_array( $ts_member, $members, true ) ) {
                $ts_campaign_id = $ts_member;          // scope source stats to this creative
                $ts_cache_scope = $ts_slug . '_m' . $ts_member; // keep its cache separate from "All"
            }
        }

        if ( $ts_slug && $ts_campaign_id && ! $is_tracker_only ) {
            $ts_module = $registry->get_module( $ts_slug );
            if ( $ts_module ) {
                $ts_module->set_credentials(
                    ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: []
                );
                try {
                    $cache_key = self::stats_cache_key( $user_id, $campaign_id, $ts_cache_scope, $drilldown, $date_from, $date_to );
                    $result    = self::fetch_module_stats_cached( $ts_module, $ts_campaign_id, $date_from, $date_to, $drilldown, $ttl_seconds, $cache_key, $force_refresh, $automation_running );
                    $ts_rows   = $result['rows'];
                    $cache_status[ $ts_slug ] = [
                        'cached'      => $result['cached'],
                        'module_name' => $ts_module->get_name(),
                        'module_type' => 'traffic_source',
                        'skipped'     => ! empty( $result['skipped'] ),
                    ];
                    if ( ! empty( $result['skipped'] ) ) {
                        $skipped_modules[] = $ts_module->get_name();
                    }
                    // Merge TS data into the combined rows keyed by zone_id (or date).
                    foreach ( $ts_rows as $row ) {
                        $key = ! empty( $row['zone_id'] ) ? (string) $row['zone_id'] : ( $row['date'] ?? '' );
                        if ( ! isset( $rows[ $key ] ) ) {
                            $rows[ $key ] = [ 'zone_id' => $key ];
                        }
                        $rows[ $key ]['impressions']  = $row['impressions'] ?? 0;
                        $rows[ $key ]['clicks']       = $row['clicks'] ?? 0;
                        $rows[ $key ]['conversions']  = $row['conversions'] ?? 0;
                        $rows[ $key ]['cost']         = $row['cost'] ?? 0;
                        $rows[ $key ]['payout']       = $row['payout'] ?? 0;
                        $rows[ $key ]['profit']       = $row['profit'] ?? 0;
                        $rows[ $key ]['roi']          = $row['roi'] ?? 0;
                        $rows[ $key ]['cpm']          = $row['cpm'] ?? 0;
                        $rows[ $key ]['cpc']          = $row['cpc'] ?? 0;
                        $rows[ $key ]['cpa']          = $row['cpa'] ?? 0;
                        $rows[ $key ]['ctr']          = $row['ctr'] ?? 0;
                        $rows[ $key ]['cr']           = $row['cr'] ?? 0;
                        // ISO code for the geo Active toggle (targeting is code-keyed).
                        if ( ! empty( $row['country_code'] ) ) {
                            $rows[ $key ]['country_code'] = $row['country_code'];
                        }
                    }
                } catch ( \Throwable $e ) {
                    $module_errors[] = [ 'module' => $ts_module->get_name(), 'message' => $e->getMessage() ];

                    // BidVertiser rate limit: fall back to cached data if available.
                    if ( $ts_module->get_slug() === 'bidvertiser' && isset( $cache_key ) ) {
                        $stale = get_transient( $cache_key );
                        if ( $stale !== false && is_array( $stale ) && ! empty( $stale ) ) {
                            foreach ( $stale as $row ) {
                                $key = ! empty( $row['zone_id'] ) ? (string) $row['zone_id'] : ( $row['date'] ?? '' );
                                if ( ! isset( $rows[ $key ] ) ) {
                                    $rows[ $key ] = [ 'zone_id' => $key ];
                                }
                                $rows[ $key ]['impressions']  = $row['impressions'] ?? 0;
                                $rows[ $key ]['clicks']       = $row['clicks'] ?? 0;
                                $rows[ $key ]['conversions']  = $row['conversions'] ?? 0;
                                $rows[ $key ]['cost']         = $row['cost'] ?? 0;
                                $rows[ $key ]['payout']       = $row['payout'] ?? 0;
                                $rows[ $key ]['profit']       = $row['profit'] ?? 0;
                                $rows[ $key ]['roi']          = $row['roi'] ?? 0;
                                $rows[ $key ]['cpm']          = $row['cpm'] ?? 0;
                                $rows[ $key ]['cpc']          = $row['cpc'] ?? 0;
                                $rows[ $key ]['cpa']          = $row['cpa'] ?? 0;
                                $rows[ $key ]['ctr']          = $row['ctr'] ?? 0;
                                $rows[ $key ]['cr']           = $row['cr'] ?? 0;
                            }
                            $cache_status[ $ts_slug ] = [
                                'cached'      => true,
                                'module_name' => $ts_module->get_name(),
                                'module_type' => 'traffic_source',
                                'stale'       => true,
                            ];
                            // Replace the error with a warning (keep data visible).
                            array_pop( $module_errors );
                            $module_errors[] = [ 'module' => $ts_module->get_name(), 'message' => 'Rate limit reached. Showing cached data.', 'type' => 'warning' ];
                        }
                    }
                }

                // Defer BL/WL loading when it would be wasted:
                //  - BidVertiser: avoid spending targeting API quota on page load.
                //  - Grouped "All campaigns" view: a comma-list ID means no single creative is
                //    selected, so zone toggles are locked — skip the costly per-creative
                //    audience lookups (2 API calls × every member) entirely.
                $defer_bl_wl = $ts_module->get_slug() === 'bidvertiser'
                    || strpos( (string) $ts_campaign_id, ',' ) !== false;
                if ( ! $defer_bl_wl && $drilldown === 'default' && method_exists( $ts_module, 'get_blocked_zones' ) ) {
                    $blocked_zones = $ts_module->get_blocked_zones( $ts_campaign_id );
                }
                if ( ! $defer_bl_wl && $drilldown === 'default' && method_exists( $ts_module, 'get_whitelisted_zones' ) ) {
                    $whitelisted_zones = $ts_module->get_whitelisted_zones( $ts_campaign_id );
                }
                // Geo report: the campaign's currently-targeted country codes drive the
                // per-country Active toggle (empty = RON / all countries, no toggling).
                if ( $country_cmp && method_exists( $ts_module, 'get_targeted_countries' ) ) {
                    try {
                        $targeted_countries = array_map( 'strval', $ts_module->get_targeted_countries( $ts_campaign_id ) );
                    } catch ( \Throwable $e ) {
                        // Non-fatal — report still renders without the toggle.
                    }
                }
            }
        }

        // --- Campaign status from traffic source ---
        $campaign_status = '';
        if ( $ts_slug && $ts_campaign_id ) {
            if ( empty( $ts_module ) ) {
                $ts_module = $registry->get_module( $ts_slug );
                if ( $ts_module ) {
                    $ts_module->set_credentials(
                        ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: []
                    );
                }
            }
            if ( $ts_module && method_exists( $ts_module, 'get_campaign_status' ) ) {
                try {
                    $campaign_status = $ts_module->get_campaign_status( $ts_campaign_id );
                } catch ( \Throwable $e ) {
                    // Ignore — badge simply won't show.
                }
            }
        }

        // --- Antifraud stats ---
        $af_slug        = get_post_meta( $campaign_id, '_optee_antifraud_module', true );
        $af_campaign_id = get_post_meta( $campaign_id, '_optee_antifraud_campaign_id', true );

        if ( $af_slug && $af_campaign_id && ! $is_tracker_only && ! $country_cmp ) {
            $af_module = $registry->get_module( $af_slug );
            if ( $af_module && method_exists( $af_module, 'get_statistics' ) ) {
                $af_module->set_credentials(
                    ModuleRegistry::get_stored_credentials( 'antifraud_tool', $af_slug ) ?: []
                );
                try {
                    $cache_key = self::stats_cache_key( $user_id, $campaign_id, $af_slug, $drilldown, $date_from, $date_to );
                    $result    = self::fetch_module_stats_cached( $af_module, $af_campaign_id, $date_from, $date_to, $drilldown, $ttl_seconds, $cache_key, $force_refresh, $automation_running );
                    $af_rows   = $result['rows'];
                    $cache_status[ $af_slug ] = [
                        'cached'      => $result['cached'],
                        'module_name' => $af_module->get_name(),
                        'module_type' => 'antifraud_tool',
                        'skipped'     => ! empty( $result['skipped'] ),
                    ];
                    if ( ! empty( $result['skipped'] ) ) {
                        $skipped_modules[] = $af_module->get_name();
                    }
                    foreach ( $af_rows as $row ) {
                        $key = ! empty( $row['zone_id'] ) ? (string) $row['zone_id'] : ( $row['date'] ?? '' );
                        if ( ! isset( $rows[ $key ] ) ) {
                            $rows[ $key ] = [ 'zone_id' => $key ];
                        }
                        $rows[ $key ]['af_visits']     = $row['af_visits'] ?? 0;
                        $rows[ $key ]['valid_hits']    = $row['valid_hits'] ?? 0;
                        $rows[ $key ]['valid_percent'] = $row['valid_percent'] ?? 0;
                        $rows[ $key ]['traffic_loss']  = $row['traffic_loss'] ?? 0;
                        $rows[ $key ]['proxy_percent'] = $row['proxy_percent'] ?? 0;
                        $rows[ $key ]['junk_percent']  = $row['junk_percent'] ?? 0;
                        $rows[ $key ]['bot_percent']   = $row['bot_percent'] ?? 0;
                        $rows[ $key ]['af_cost']       = $row['af_cost'] ?? 0;
                    }
                } catch ( \Throwable $e ) {
                    $module_errors[] = [ 'module' => $af_module->get_name(), 'message' => $e->getMessage() ];
                }
            }
        }

        // --- Tracker stats ---
        $tr_slug        = get_post_meta( $campaign_id, '_optee_tracker_module', true );
        $tr_campaign_id = get_post_meta( $campaign_id, '_optee_tracker_campaign_id', true );

        if ( $tr_slug && $tr_campaign_id ) {
            $tr_module = $registry->get_module( $tr_slug );
            if ( $tr_module && method_exists( $tr_module, 'get_statistics' ) ) {
                $tr_module->set_credentials(
                    ModuleRegistry::get_stored_credentials( 'tracker', $tr_slug ) ?: []
                );

                // For tracker, resolve 'default' drilldown to the campaign's zone field
                // (e.g., 'custom1') so the tracker API groups by the correct field.
                $tr_drilldown = $drilldown;
                if ( $drilldown === 'default' ) {
                    $zone_field = get_post_meta( $campaign_id, '_optee_zone_id', true );
                    if ( $zone_field ) {
                        $tr_drilldown = $zone_field;
                    }
                } elseif ( $country_cmp ) {
                    // Side-by-side country compare — group the tracker by country.
                    $tr_drilldown = 'geo';
                } elseif ( $mapped_slot ) {
                    // Mapped-token dimension (e.g. Category) — group the tracker by the
                    // custom slot that captured the forwarded token.
                    $tr_drilldown = $mapped_slot;
                }

                try {
                    // Zone-scoped reports cache separately from the full-campaign report.
                    $tr_cache_scope = $drilldown . ( $zone_filter ? '_z' . $zone_filter['value'] : '' );
                    $cache_key = self::stats_cache_key( $user_id, $campaign_id, $tr_slug, $tr_cache_scope, $date_from, $date_to );
                    $result    = self::fetch_module_stats_cached( $tr_module, $tr_campaign_id, $date_from, $date_to, $tr_drilldown, $ttl_seconds, $cache_key, $force_refresh, $automation_running, $zone_filter );
                    $tr_rows   = $result['rows'];
                    $cache_status[ $tr_slug ] = [
                        'cached'      => $result['cached'],
                        'module_name' => $tr_module->get_name(),
                        'module_type' => 'tracker',
                        'skipped'     => ! empty( $result['skipped'] ),
                    ];
                    if ( ! empty( $result['skipped'] ) ) {
                        $skipped_modules[] = $tr_module->get_name();
                    }
                    if ( $drilldown === 'conversions' ) {
                        // Conversions detail: each row is a unique conversion, use raw keys.
                        foreach ( $tr_rows as $row ) {
                            $rows[] = $row;
                        }
                    } else {
                        foreach ( $tr_rows as $row ) {
                            $key = ! empty( $row['zone_id'] ) ? (string) $row['zone_id'] : ( $row['date'] ?? '' );
                            /**
                             * Normalize the tracker zone key before it is merged with traffic-source
                             * rows. Lets a plugin fix malformed zone ids (e.g. a stray trailing
                             * character from a bad tracking token) so rows join by zone correctly.
                             *
                             * @param string $key  Tracker zone id used as the merge key.
                             * @param array  $ctx  { tracker, traffic_source, campaign_id, drilldown }
                             */
                            $key = (string) apply_filters( 'optee_tracker_zone_key', $key, [
                                'tracker'        => $tr_slug,
                                'traffic_source' => $ts_slug,
                                'campaign_id'    => $campaign_id,
                                'drilldown'      => $drilldown,
                            ] );
                            if ( ! isset( $rows[ $key ] ) ) {
                                $rows[ $key ] = [ 'zone_id' => $key ];
                            }
                            $rows[ $key ]['tr_visits']       = $row['tr_visits'] ?? 0;
                            $rows[ $key ]['tr_clicks']       = $row['tr_clicks'] ?? 0;
                            $rows[ $key ]['tr_conv']          = $row['tr_conv'] ?? 0;
                            $rows[ $key ]['tr_conv_all']      = $row['tr_conv_all'] ?? 0;
                            $rows[ $key ]['tr_revenue']       = $row['tr_revenue'] ?? 0;
                            $rows[ $key ]['tr_revenue_all']   = $row['tr_revenue_all'] ?? 0;
                            $rows[ $key ]['tr_cost']          = $row['tr_cost'] ?? 0;
                            $rows[ $key ]['tr_profit']        = $row['tr_profit'] ?? 0;
                            $rows[ $key ]['tr_roi']          = $row['tr_roi'] ?? 0;
                            $rows[ $key ]['tr_ctr']          = $row['tr_ctr'] ?? 0;
                            $rows[ $key ]['tr_cv']           = $row['tr_cv'] ?? 0;
                            $rows[ $key ]['tr_cpm']          = $row['tr_cpm'] ?? 0;
                            $rows[ $key ]['tr_cpa']          = $row['tr_cpa'] ?? 0;
                            $rows[ $key ]['tr_cpa_ts']       = $row['tr_cpa_ts'] ?? 0;
                            $rows[ $key ]['tr_ap']           = $row['tr_ap'] ?? 0;
                        }
                    }
                } catch ( \Throwable $e ) {
                    $module_errors[] = [ 'module' => $tr_module->get_name(), 'message' => $e->getMessage() ];
                }
            }
        }

        // --- PopAds Target annotation (Browsers / OS tabs) ---
        // These dimensions are tracker-only (PopAds reports no performance for them),
        // but PopAds CAN target them. Annotate each tracker row with the PopAds
        // targeting option it maps to, so the user sees which rows are actionable.
        $target_dims = [ 'browsers' => 'browser', 'browser-versions' => 'browser', 'os' => 'os', 'os-versions' => 'os' ];
        if ( isset( $target_dims[ $drilldown ] ) && $ts_slug && ! empty( $rows ) ) {
            $ann_dim = $target_dims[ $drilldown ];
            $ts_ann  = $registry->get_module( $ts_slug );
            if ( $ts_ann && method_exists( $ts_ann, 'describe_option_matches' ) ) {
                $ts_ann->set_credentials( ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: [] );
                try {
                    $names = [];
                    foreach ( $rows as $k => $r ) {
                        $names[] = (string) ( $r['zone_id'] ?? $k );
                    }
                    $desc = $ts_ann->describe_option_matches( $ann_dim, $names );
                    // Resolve each row's PopAds targeting ID too, so the report's Active
                    // toggle knows which option to add/remove for the row.
                    $id_map = [];
                    if ( method_exists( $ts_ann, 'resolve_option_ids' ) ) {
                        $id_map = $ts_ann->resolve_option_ids( $ann_dim, $names )['matched'] ?? [];
                    }
                    foreach ( $rows as $k => &$r ) {
                        $nm                = (string) ( $r['zone_id'] ?? $k );
                        $r['ts_target']    = $desc[ $nm ] ?? '';
                        $r['ts_target_id'] = isset( $id_map[ $nm ] ) ? (string) $id_map[ $nm ] : '';
                    }
                    unset( $r );

                    // Current targeting list drives the toggle's on/off state (empty = RON
                    // / all options active). Exposed alongside the dimension it applies to.
                    if ( method_exists( $ts_ann, 'get_targeting_values' ) && method_exists( $ts_ann, 'add_targeting' ) ) {
                        $targeting_dim        = $ann_dim;
                        $targeted_targeting   = array_map( 'strval', $ts_ann->get_targeting_values( $ts_campaign_id, $ann_dim ) );
                    }
                } catch ( \Throwable $e ) {
                    // Annotation is best-effort — never break the report over it.
                }
            }
        }

        // --- Mapped-token dimension annotation (Category / Form Factor / …) ---
        // Tracker rows are keyed by the forwarded targeting value. Annotate with the
        // readable PopAds option: 'id' tokens → describe_ids (id→name), 'name' tokens
        // → describe_option_matches (name→matched option). '' = no annotation column.
        $ann_type = $mapped_dim ? ( $token_reg[ $mapped_dim ]['annotate'] ?? '' ) : '';
        if ( $ann_type && $ts_slug && ! empty( $rows ) ) {
            $ts_ann = $registry->get_module( $ts_slug );
            if ( $ts_ann ) {
                $ts_ann->set_credentials( ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: [] );
                try {
                    $vals = [];
                    foreach ( $rows as $k => $r ) {
                        $vals[] = (string) ( $r['zone_id'] ?? $k );
                    }
                    $ann = [];
                    if ( $ann_type === 'id' && method_exists( $ts_ann, 'describe_ids' ) ) {
                        $ann = $ts_ann->describe_ids( $mapped_dim, $vals );
                    } elseif ( $ann_type === 'name' && method_exists( $ts_ann, 'describe_option_matches' ) ) {
                        $ann = $ts_ann->describe_option_matches( $mapped_dim, $vals );
                    }
                    // Resolve each row's PopAds targeting VALUE so the Active toggle can
                    // add/remove it: 'id' tokens (Category) already carry the ID; 'name'
                    // tokens (Form Factor) resolve name → ID.
                    $id_map = [];
                    if ( $ann_type === 'id' ) {
                        foreach ( $vals as $v ) {
                            $id_map[ $v ] = $v;
                        }
                    } elseif ( $ann_type === 'name' && method_exists( $ts_ann, 'resolve_option_ids' ) ) {
                        $id_map = $ts_ann->resolve_option_ids( $mapped_dim, $vals )['matched'] ?? [];
                    }
                    if ( $ann ) {
                        foreach ( $rows as $k => &$r ) {
                            $nm                = (string) ( $r['zone_id'] ?? $k );
                            $r['ts_target']    = $ann[ $nm ] ?? '';
                            $r['ts_target_id'] = isset( $id_map[ $nm ] ) ? (string) $id_map[ $nm ] : '';
                        }
                        unset( $r );

                        // Current targeting list drives the toggle's on/off state.
                        if ( method_exists( $ts_ann, 'get_targeting_values' ) && method_exists( $ts_ann, 'add_targeting' ) ) {
                            $targeting_dim      = $mapped_dim;
                            $targeted_targeting = array_map( 'strval', $ts_ann->get_targeting_values( $ts_campaign_id, $mapped_dim ) );

                            // Form factors are hierarchical (Computer covers Desktop/
                            // Notebook), so a row can be active via its PARENT even when
                            // its own id isn't in the list. Precompute a hierarchy-aware
                            // active flag per row for the toggle state.
                            if ( $mapped_dim === 'form_factor' && method_exists( $ts_ann, 'targeting_active_map' ) ) {
                                $active_map = $ts_ann->targeting_active_map( $mapped_dim, array_values( $id_map ), $targeted_targeting );
                                foreach ( $rows as $k => &$r2 ) {
                                    $tid = (string) ( $r2['ts_target_id'] ?? '' );
                                    if ( $tid !== '' && isset( $active_map[ $tid ] ) ) {
                                        $r2['ts_target_active'] = $active_map[ $tid ] ? 1 : 0;
                                    }
                                }
                                unset( $r2 );
                            }
                        }
                    }
                } catch ( \Throwable $e ) {
                    // best-effort
                }
            }
        }

        // --- Per-value targeting for un-annotated ID dimensions (Resolution) ---
        // The Resolution tab has no PopAds-target annotation column, and the row value
        // is the "1920x1080" string — but PopAds targets resolutions by catalog ID, so
        // resolve each row's string → id for the Active toggle (targeted list is IDs).
        // Rows whose resolution isn't in PopAds' catalog get no toggle (blank cell).
        // 'connection' (BeMob "Connection Types" tab) maps to PopAds connection-SPEED
        // (Cable/DSL, Corporate, Cellular/Carrier, …), not connection-type. Satellite
        // has no PopAds equivalent → unresolved → blank toggle cell.
        $resolve_toggle_dims = [ 'resolution' => 'resolution', 'connection' => 'connection_speed' ];
        if ( isset( $resolve_toggle_dims[ $drilldown ] ) && $ts_slug && ! empty( $rows ) ) {
            $rdim   = $resolve_toggle_dims[ $drilldown ];
            $ts_ann = $registry->get_module( $ts_slug );
            if ( $ts_ann && method_exists( $ts_ann, 'get_targeting_values' ) && method_exists( $ts_ann, 'add_targeting' ) && method_exists( $ts_ann, 'resolve_option_ids' ) ) {
                $ts_ann->set_credentials( ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: [] );
                try {
                    $names = [];
                    foreach ( $rows as $k => $r ) {
                        $names[] = (string) ( $r['zone_id'] ?? $k );
                    }
                    $id_map = $ts_ann->resolve_option_ids( $rdim, $names )['matched'] ?? [];
                    foreach ( $rows as $k => &$r ) {
                        $nm                = (string) ( $r['zone_id'] ?? $k );
                        $r['ts_target_id'] = isset( $id_map[ $nm ] ) ? (string) $id_map[ $nm ] : '';
                    }
                    unset( $r );
                    $targeting_dim      = $rdim;
                    $targeted_targeting = array_map( 'strval', $ts_ann->get_targeting_values( $ts_campaign_id, $rdim ) );
                } catch ( \Throwable $e ) {
                    // best-effort
                }
            }
        }

        // --- Monetization (Monetag) stats ---
        $mon_zone_id = get_post_meta( $campaign_id, '_optee_monetag_zone_id', true );

        if ( $mon_zone_id && $drilldown === 'days' && ! $zone_filter ) {
            $mon_module = $registry->get_module( 'monetag' );
            if ( $mon_module && method_exists( $mon_module, 'get_statistics' ) ) {
                $mon_module->set_credentials(
                    ModuleRegistry::get_stored_credentials( 'monetization_platform', 'monetag' ) ?: []
                );
                try {
                    $mon_country = get_post_meta( $campaign_id, '_optee_monetag_country', true );
                    $mon_filters = [ 'zone_id' => $mon_zone_id ];
                    if ( $mon_country ) {
                        $mon_filters['country'] = $mon_country;
                    }

                    // Cache key includes zone_id + country for uniqueness.
                    $mon_cache_key = self::stats_cache_key( $user_id, $campaign_id, 'monetag', $drilldown, $date_from, $date_to )
                        . '_z' . $mon_zone_id . ( $mon_country ? '_' . $mon_country : '' );
                    $mon_cached = false;

                    if ( $ttl_seconds > 0 && ! $force_refresh ) {
                        $cached_mon = get_transient( $mon_cache_key );
                        if ( $cached_mon !== false ) {
                            $mon_rows   = $cached_mon;
                            $mon_cached = true;
                        }
                    }

                    if ( ! $mon_cached && $automation_running ) {
                        $mon_rows = [];
                        $skipped_modules[] = 'Monetag';
                        $cache_status['monetag'] = [
                            'cached'      => false,
                            'module_name' => 'Monetag',
                            'module_type' => 'monetization_platform',
                            'skipped'     => true,
                        ];
                    } else {
                        if ( ! $mon_cached ) {
                            $mon_rows = $mon_module->get_statistics( $date_from, $date_to, [ 'date_time' ], $mon_filters );
                            if ( $ttl_seconds > 0 ) {
                                set_transient( $mon_cache_key, $mon_rows, $ttl_seconds );
                            }
                        }

                        $cache_status['monetag'] = [
                            'cached'      => $mon_cached,
                            'module_name' => 'Monetag',
                            'module_type' => 'monetization_platform',
                            'skipped'     => false,
                        ];
                    }

                    // Build a date-keyed map from Monetag rows.
                    $mon_by_date = [];
                    foreach ( $mon_rows as $mr ) {
                        $date_key = $mr['date_time'] ?? '';
                        if ( ! $date_key ) continue;
                        $money = (float) ( $mr['money'] ?? 0 );
                        $imps  = (int) ( $mr['impressions'] ?? 0 );
                        $ecpm  = $imps > 0 ? ( $money / $imps ) * 1000 : 0;
                        $mon_by_date[ $date_key ] = [
                            'mtg_earnings'    => round( $money, 6 ),
                            'mtg_impressions' => $imps,
                            'mtg_ecpm'        => round( $ecpm, 4 ),
                        ];
                    }

                    // Merge Monetag data into existing rows by date key.
                    foreach ( $mon_by_date as $date_key => $mon_data ) {
                        if ( ! isset( $rows[ $date_key ] ) ) {
                            $rows[ $date_key ] = [ 'zone_id' => $date_key ];
                        }
                        $rows[ $date_key ]['mtg_earnings']    = $mon_data['mtg_earnings'];
                        $rows[ $date_key ]['mtg_impressions'] = $mon_data['mtg_impressions'];
                        $rows[ $date_key ]['mtg_ecpm']        = $mon_data['mtg_ecpm'];
                    }
                } catch ( \Throwable $e ) {
                    $module_errors[] = [ 'module' => $mon_module->get_name(), 'message' => $e->getMessage() ];
                }
            }
        }

        // --- Monetization (ProPush) stats ---
        $pp_zone_id = get_post_meta( $campaign_id, '_optee_propush_zone_id', true );
        $pp_site_id = get_post_meta( $campaign_id, '_optee_propush_site_id', true );
        $pp_source_only = get_post_meta( $campaign_id, '_optee_propush_source_id', true );
        if ( ( $pp_zone_id || $pp_site_id || $pp_source_only ) && $drilldown === 'days' && ! $zone_filter ) {
            $pp_module = $registry->get_module( 'propush' );
            if ( $pp_module && method_exists( $pp_module, 'get_statistics' ) ) {
                $pp_module->set_credentials(
                    ModuleRegistry::get_stored_credentials( 'monetization_platform', 'propush' ) ?: []
                );
                try {
                    $pp_source_id = get_post_meta( $campaign_id, '_optee_propush_source_id', true );
                    $pp_country   = get_post_meta( $campaign_id, '_optee_propush_country', true );
                    $pp_filters   = [];
                    if ( $pp_zone_id ) {
                        $pp_filters['zone_id'] = $pp_zone_id;
                    }
                    if ( $pp_site_id ) {
                        $pp_filters['site_id'] = $pp_site_id;
                    }
                    if ( $pp_source_id ) {
                        $pp_filters['source_id'] = $pp_source_id;
                    }
                    if ( $pp_country ) {
                        $pp_filters['country'] = $pp_country;
                    }

                    // Cache key includes zone_id(s) + site_id(s) + source_id + country for
                    // uniqueness. Hash the suffix — zone/site can be comma-separated lists.
                    $pp_cache_key = self::stats_cache_key( $user_id, $campaign_id, 'propush', $drilldown, $date_from, $date_to )
                        . '_' . md5( $pp_zone_id . '|' . $pp_site_id . '|' . $pp_source_id . '|' . $pp_country );
                    $pp_cached = false;

                    if ( $ttl_seconds > 0 && ! $force_refresh ) {
                        $cached_pp = get_transient( $pp_cache_key );
                        if ( $cached_pp !== false ) {
                            $pp_rows   = $cached_pp;
                            $pp_cached = true;
                        }
                    }

                    if ( ! $pp_cached && $automation_running ) {
                        $pp_rows = [];
                        $skipped_modules[] = 'ProPush';
                        $cache_status['propush'] = [
                            'cached'      => false,
                            'module_name' => 'ProPush',
                            'module_type' => 'monetization_platform',
                            'skipped'     => true,
                        ];
                    } else {
                        if ( ! $pp_cached ) {
                            $pp_rows = $pp_module->get_statistics( $date_from, $date_to, [ 'date_time' ], $pp_filters );
                            if ( $ttl_seconds > 0 ) {
                                set_transient( $pp_cache_key, $pp_rows, $ttl_seconds );
                            }
                        }

                        $cache_status['propush'] = [
                            'cached'      => $pp_cached,
                            'module_name' => 'ProPush',
                            'module_type' => 'monetization_platform',
                            'skipped'     => false,
                        ];
                    }

                    // Build a date-keyed map from ProPush rows.
                    $pp_by_date = [];
                    foreach ( $pp_rows as $pr ) {
                        $date_key = $pr['date_time'] ?? '';
                        if ( ! $date_key ) continue;
                        $money = (float) ( $pr['money'] ?? 0 );
                        $imps  = (int) ( $pr['impressions'] ?? 0 );
                        $subs  = (int) ( $pr['subscriptions'] ?? 0 );
                        $cpm   = $imps > 0 ? ( $money / $imps ) * 1000 : 0;
                        $pp_by_date[ $date_key ] = [
                            'pp_earnings'    => round( $money, 6 ),
                            'pp_impressions' => $imps,
                            'pp_subscribed'  => $subs,
                            'pp_cpm'         => round( $cpm, 4 ),
                        ];
                    }

                    // Merge ProPush data into existing rows by date key.
                    foreach ( $pp_by_date as $date_key => $pp_data ) {
                        if ( ! isset( $rows[ $date_key ] ) ) {
                            $rows[ $date_key ] = [ 'zone_id' => $date_key ];
                        }
                        $rows[ $date_key ]['pp_earnings']    = $pp_data['pp_earnings'];
                        $rows[ $date_key ]['pp_impressions'] = $pp_data['pp_impressions'];
                        $rows[ $date_key ]['pp_subscribed']  = $pp_data['pp_subscribed'];
                        $rows[ $date_key ]['pp_cpm']         = $pp_data['pp_cpm'];
                    }
                } catch ( \Throwable $e ) {
                    $module_errors[] = [ 'module' => $pp_module->get_name(), 'message' => $e->getMessage() ];
                }
            }
        }

        // --- Monetization (Partners.House) stats ---
        $ph_active = get_post_meta( $campaign_id, '_optee_ph_active', true );
        if ( $ph_active && $drilldown === 'days' && ! $zone_filter ) {
            $ph_module = $registry->get_module( 'partners-house' );
            if ( $ph_module && method_exists( $ph_module, 'get_statistics' ) ) {
                $ph_module->set_credentials(
                    ModuleRegistry::get_stored_credentials( 'monetization_platform', 'partners-house' ) ?: []
                );
                try {
                    $ph_sub1    = get_post_meta( $campaign_id, '_optee_ph_sub1', true );
                    $ph_sub2    = get_post_meta( $campaign_id, '_optee_ph_sub2', true );
                    $ph_sub3    = get_post_meta( $campaign_id, '_optee_ph_sub3', true );
                    $ph_sub4    = get_post_meta( $campaign_id, '_optee_ph_sub4', true );
                    $ph_country = get_post_meta( $campaign_id, '_optee_ph_country', true );
                    $ph_filters = [];
                    if ( $ph_sub1 ) $ph_filters['sub1'] = $ph_sub1;
                    if ( $ph_sub2 ) $ph_filters['sub2'] = $ph_sub2;
                    if ( $ph_sub3 ) $ph_filters['sub3'] = $ph_sub3;
                    if ( $ph_sub4 ) $ph_filters['sub4'] = $ph_sub4;
                    if ( $ph_country ) $ph_filters['country'] = $ph_country;

                    // Cache key includes filters for uniqueness.
                    $ph_cache_key = self::stats_cache_key( $user_id, $campaign_id, 'partners-house', $drilldown, $date_from, $date_to )
                        . ( $ph_sub1 ? '_s1' . $ph_sub1 : '' )
                        . ( $ph_sub2 ? '_s2' . $ph_sub2 : '' )
                        . ( $ph_sub3 ? '_s3' . $ph_sub3 : '' )
                        . ( $ph_sub4 ? '_s4' . $ph_sub4 : '' )
                        . ( $ph_country ? '_' . $ph_country : '' );
                    $ph_cached = false;

                    if ( $ttl_seconds > 0 && ! $force_refresh ) {
                        $cached_ph = get_transient( $ph_cache_key );
                        if ( $cached_ph !== false ) {
                            $ph_rows   = $cached_ph;
                            $ph_cached = true;
                        }
                    }

                    if ( ! $ph_cached && $automation_running ) {
                        $ph_rows = [];
                        $skipped_modules[] = 'Partners.House';
                        $cache_status['partners-house'] = [
                            'cached'      => false,
                            'module_name' => 'Partners.House',
                            'module_type' => 'monetization_platform',
                            'skipped'     => true,
                        ];
                    } else {
                        if ( ! $ph_cached ) {
                            $ph_rows = $ph_module->get_statistics( $date_from, $date_to, [ 'days' ], $ph_filters );
                            if ( $ttl_seconds > 0 ) {
                                set_transient( $ph_cache_key, $ph_rows, $ttl_seconds );
                            }
                        }

                        $cache_status['partners-house'] = [
                            'cached'      => $ph_cached,
                            'module_name' => 'Partners.House',
                            'module_type' => 'monetization_platform',
                            'skipped'     => false,
                        ];
                    }

                    // Build a date-keyed map from Partners.House rows.
                    $ph_by_date = [];
                    foreach ( $ph_rows as $pr ) {
                        $date_key = $pr['firstColumn'] ?? '';
                        if ( ! $date_key ) continue;
                        $money = (float) ( $pr['totalProfit'] ?? 0 );
                        $imps  = (int) ( $pr['rsShows'] ?? 0 );
                        $subs  = (int) ( $pr['subscriptions'] ?? 0 );
                        $cpm   = $imps > 0 ? ( $money / $imps ) * 1000 : 0;
                        $ph_by_date[ $date_key ] = [
                            'ph_earnings'      => round( $money, 6 ),
                            'ph_impressions'    => $imps,
                            'ph_subscriptions'  => $subs,
                            'ph_cpm'            => round( $cpm, 4 ),
                        ];
                    }

                    // Merge Partners.House data into existing rows by date key.
                    foreach ( $ph_by_date as $date_key => $ph_data ) {
                        if ( ! isset( $rows[ $date_key ] ) ) {
                            $rows[ $date_key ] = [ 'zone_id' => $date_key ];
                        }
                        $rows[ $date_key ]['ph_earnings']      = $ph_data['ph_earnings'];
                        $rows[ $date_key ]['ph_impressions']    = $ph_data['ph_impressions'];
                        $rows[ $date_key ]['ph_subscriptions']  = $ph_data['ph_subscriptions'];
                        $rows[ $date_key ]['ph_cpm']            = $ph_data['ph_cpm'];
                    }
                } catch ( \Throwable $e ) {
                    $module_errors[] = [ 'module' => $ph_module->get_name(), 'message' => $e->getMessage() ];
                }
            }
        }

        // --- Monetization (RollerAds Publisher) stats ---
        $rap_site_id = get_post_meta( $campaign_id, '_optee_rap_site_id', true );
        $rap_zone_id = get_post_meta( $campaign_id, '_optee_rap_zone_id', true );
        if ( $rap_site_id && $drilldown === 'days' && ! $zone_filter ) {
            $rap_module = $registry->get_module( 'rollerads-publisher' );
            if ( $rap_module && method_exists( $rap_module, 'get_statistics' ) ) {
                $rap_module->set_credentials(
                    ModuleRegistry::get_stored_credentials( 'monetization_platform', 'rollerads-publisher' ) ?: []
                );
                try {
                    $rap_cache_key = self::stats_cache_key( $user_id, $campaign_id, 'rollerads-publisher', $drilldown, $date_from, $date_to )
                        . '_s' . $rap_site_id . ( $rap_zone_id ? '_z' . $rap_zone_id : '' );
                    $rap_cached = false;

                    if ( $ttl_seconds > 0 && ! $force_refresh ) {
                        $cached_rap = get_transient( $rap_cache_key );
                        if ( $cached_rap !== false ) {
                            $rap_rows  = $cached_rap;
                            $rap_cached = true;
                        }
                    }

                    if ( ! $rap_cached && $automation_running ) {
                        $rap_rows = [];
                        $skipped_modules[] = 'RollerAds Publisher';
                        $cache_status['rollerads-publisher'] = [
                            'cached'      => false,
                            'module_name' => 'RollerAds Publisher',
                            'module_type' => 'monetization_platform',
                            'skipped'     => true,
                        ];
                    } else {
                        if ( ! $rap_cached ) {
                            $rap_filters = [ 'site_id' => $rap_site_id ];
                            if ( $rap_zone_id ) {
                                $rap_filters['zone_id'] = $rap_zone_id;
                            }
                            $rap_rows = $rap_module->get_statistics( $date_from, $date_to, [ 'day' ], $rap_filters );
                            if ( $ttl_seconds > 0 ) {
                                set_transient( $rap_cache_key, $rap_rows, $ttl_seconds );
                            }
                        }

                        $cache_status['rollerads-publisher'] = [
                            'cached'      => $rap_cached,
                            'module_name' => 'RollerAds Publisher',
                            'module_type' => 'monetization_platform',
                            'skipped'     => false,
                        ];
                    }

                    // Build a date-keyed map from RollerAds Publisher rows.
                    $rap_by_date = [];
                    foreach ( $rap_rows as $rr ) {
                        $date_key = $rr['ts_title'] ?? '';
                        if ( ! $date_key ) continue;
                        $money = (float) ( $rr['amt_omoney'] ?? 0 );
                        $imps  = (int) ( $rr['cnt_impression'] ?? 0 );
                        $subs  = (int) ( $rr['cnt_subscribe'] ?? 0 );
                        $reqs  = (int) ( $rr['cnt_request'] ?? 0 );
                        $unsub = (int) ( $rr['cnt_unsubscribe'] ?? 0 );
                        $cpm   = $imps > 0 ? ( $money / $imps ) * 1000 : 0;
                        // CR arrives from the API already as a percentage. It is null (not 0)
                        // on days with no subscription requests — keep the null so the cell
                        // renders as an em dash instead of a misleading 0%.
                        $cr = isset( $rr['cr'] ) && $rr['cr'] !== null ? (float) $rr['cr'] : null;
                        $rap_by_date[ $date_key ] = [
                            'rap_earnings'    => round( $money, 6 ),
                            'rap_impressions' => $imps,
                            'rap_requests'    => $reqs,
                            'rap_subscribed'  => $subs,
                            'rap_unsubscribed' => $unsub,
                            'rap_cr'          => $cr,
                            'rap_cpm'         => round( $cpm, 4 ),
                        ];
                    }

                    // Merge RollerAds Publisher data into existing rows by date key.
                    foreach ( $rap_by_date as $date_key => $rap_data ) {
                        if ( ! isset( $rows[ $date_key ] ) ) {
                            $rows[ $date_key ] = [ 'zone_id' => $date_key ];
                        }
                        $rows[ $date_key ]['rap_earnings']     = $rap_data['rap_earnings'];
                        $rows[ $date_key ]['rap_impressions']  = $rap_data['rap_impressions'];
                        $rows[ $date_key ]['rap_requests']     = $rap_data['rap_requests'];
                        $rows[ $date_key ]['rap_subscribed']   = $rap_data['rap_subscribed'];
                        $rows[ $date_key ]['rap_unsubscribed'] = $rap_data['rap_unsubscribed'];
                        $rows[ $date_key ]['rap_cr']           = $rap_data['rap_cr'];
                        $rows[ $date_key ]['rap_cpm']          = $rap_data['rap_cpm'];
                    }
                } catch ( \Throwable $e ) {
                    $module_errors[] = [ 'module' => $rap_module->get_name(), 'message' => $e->getMessage() ];
                }
            }
        }

        // --- Monetization (HilltopAds Publisher) stats ---
        $hta_site_id = get_post_meta( $campaign_id, '_optee_hta_site_id', true );
        $hta_zone_id = get_post_meta( $campaign_id, '_optee_hta_zone_id', true );
        if ( $hta_site_id && $drilldown === 'days' && ! $zone_filter ) {
            $hta_module = $registry->get_module( 'hilltopads-publisher' );
            if ( $hta_module && method_exists( $hta_module, 'get_statistics' ) ) {
                $hta_module->set_credentials(
                    ModuleRegistry::get_stored_credentials( 'monetization_platform', 'hilltopads-publisher' ) ?: []
                );
                try {
                    $hta_cache_key = self::stats_cache_key( $user_id, $campaign_id, 'hilltopads-publisher', $drilldown, $date_from, $date_to )
                        . '_s' . $hta_site_id . ( $hta_zone_id ? '_z' . $hta_zone_id : '' );
                    $hta_cached = false;

                    if ( $ttl_seconds > 0 && ! $force_refresh ) {
                        $cached_hta = get_transient( $hta_cache_key );
                        if ( $cached_hta !== false ) {
                            $hta_rows  = $cached_hta;
                            $hta_cached = true;
                        }
                    }

                    if ( ! $hta_cached && $automation_running ) {
                        $hta_rows = [];
                        $skipped_modules[] = 'HilltopAds Publisher';
                        $cache_status['hilltopads-publisher'] = [
                            'cached'      => false,
                            'module_name' => 'HilltopAds Publisher',
                            'module_type' => 'monetization_platform',
                            'skipped'     => true,
                        ];
                    } else {
                        if ( ! $hta_cached ) {
                            $hta_filters = [ 'site_id' => $hta_site_id ];
                            if ( $hta_zone_id ) {
                                $hta_filters['zone_id'] = $hta_zone_id;
                            }
                            $hta_rows = $hta_module->get_statistics( $date_from, $date_to, [ 'date' ], $hta_filters );
                            if ( $ttl_seconds > 0 ) {
                                set_transient( $hta_cache_key, $hta_rows, $ttl_seconds );
                            }
                        }

                        $cache_status['hilltopads-publisher'] = [
                            'cached'      => $hta_cached,
                            'module_name' => 'HilltopAds Publisher',
                            'module_type' => 'monetization_platform',
                            'skipped'     => false,
                        ];
                    }

                    // Build a date-keyed map from HilltopAds Publisher rows.
                    $hta_by_date = [];
                    foreach ( $hta_rows as $hr ) {
                        $date_key = $hr['date'] ?? '';
                        if ( ! $date_key ) continue;
                        $money = (float) ( $hr['revenue'] ?? 0 );
                        $imps  = (int) ( $hr['impressions'] ?? 0 );
                        $clicks = (int) ( $hr['clicks'] ?? 0 );
                        $cpm   = $imps > 0 ? ( $money / $imps ) * 1000 : 0;
                        $hta_by_date[ $date_key ] = [
                            'hta_earnings'    => round( $money, 6 ),
                            'hta_impressions' => $imps,
                            'hta_clicks'      => $clicks,
                            'hta_cpm'         => round( $cpm, 4 ),
                        ];
                    }

                    // Merge HilltopAds Publisher data into existing rows by date key.
                    foreach ( $hta_by_date as $date_key => $hta_data ) {
                        if ( ! isset( $rows[ $date_key ] ) ) {
                            $rows[ $date_key ] = [ 'zone_id' => $date_key ];
                        }
                        $rows[ $date_key ]['hta_earnings']    = $hta_data['hta_earnings'];
                        $rows[ $date_key ]['hta_impressions'] = $hta_data['hta_impressions'];
                        $rows[ $date_key ]['hta_clicks']      = $hta_data['hta_clicks'];
                        $rows[ $date_key ]['hta_cpm']         = $hta_data['hta_cpm'];
                    }
                } catch ( \Throwable $e ) {
                    $module_errors[] = [ 'module' => $hta_module->get_name(), 'message' => $e->getMessage() ];
                }
            }
        }

        // --- Monetization (Monetizer) stats ---
        $mtzr_league_id        = get_post_meta( $campaign_id, '_optee_mtzr_league_id', true );
        $mtzr_campaign_keyword = get_post_meta( $campaign_id, '_optee_mtzr_campaign_keyword', true );
        if ( $mtzr_league_id && $drilldown === 'days' && ! $zone_filter ) {
            $mtzr_module = $registry->get_module( 'monetizer', 'monetization_platform' );
            if ( $mtzr_module && method_exists( $mtzr_module, 'get_league_report' ) ) {
                $mtzr_module->set_credentials(
                    ModuleRegistry::get_stored_credentials( 'monetization_platform', 'monetizer' ) ?: []
                );
                try {
                    $mtzr_cache_key = self::stats_cache_key( $user_id, $campaign_id, 'monetizer', $drilldown, $date_from, $date_to )
                        . '_l' . $mtzr_league_id . ( $mtzr_campaign_keyword ? '_c' . $mtzr_campaign_keyword : '' );
                    $mtzr_cached = false;

                    if ( $ttl_seconds > 0 && ! $force_refresh ) {
                        $cached_mtzr = get_transient( $mtzr_cache_key );
                        if ( $cached_mtzr !== false ) {
                            $mtzr_rows  = $cached_mtzr;
                            $mtzr_cached = true;
                        }
                    }

                    if ( ! $mtzr_cached && $automation_running ) {
                        $mtzr_rows = [];
                        $skipped_modules[] = 'Monetizer';
                        $cache_status['monetizer'] = [
                            'cached'      => false,
                            'module_name' => 'Monetizer',
                            'module_type' => 'monetization_platform',
                            'skipped'     => true,
                        ];
                    } else {
                        if ( ! $mtzr_cached ) {
                            $mtzr_rows = $mtzr_module->get_league_report( $mtzr_league_id, $date_from, $date_to, $mtzr_campaign_keyword ?: '' );
                            if ( $ttl_seconds > 0 ) {
                                set_transient( $mtzr_cache_key, $mtzr_rows, $ttl_seconds );
                            }
                        }

                        $cache_status['monetizer'] = [
                            'cached'      => $mtzr_cached,
                            'module_name' => 'Monetizer',
                            'module_type' => 'monetization_platform',
                            'skipped'     => false,
                        ];
                    }

                    // Merge Monetizer data into existing rows by date key.
                    foreach ( $mtzr_rows as $mr ) {
                        $date_key = $mr['date'] ?? '';
                        if ( ! $date_key ) continue;
                        if ( ! isset( $rows[ $date_key ] ) ) {
                            $rows[ $date_key ] = [ 'zone_id' => $date_key ];
                        }
                        $rows[ $date_key ]['mtzr_clicks']  = (int) ( $mr['clicks'] ?? 0 );
                        $rows[ $date_key ]['mtzr_leads']   = (int) ( $mr['leads'] ?? 0 );
                        $rows[ $date_key ]['mtzr_revenue'] = round( (float) ( $mr['revenue'] ?? 0 ), 6 );
                        $rows[ $date_key ]['mtzr_epc']     = round( (float) ( $mr['epc'] ?? 0 ), 4 );
                    }
                } catch ( \Throwable $e ) {
                    $module_errors[] = [ 'module' => $mtzr_module->get_name(), 'message' => $e->getMessage() ];
                }
            }
        }

        // --- Monetization (Zeydoo) stats ---
        $zd_request_var = get_post_meta( $campaign_id, '_optee_zd_request_var', true );
        if ( $zd_request_var && $drilldown === 'days' && ! $zone_filter ) {
            $zd_module = $registry->get_module( 'zeydoo', 'monetization_platform' );
            if ( $zd_module && method_exists( $zd_module, 'get_statistics' ) ) {
                $zd_module->set_credentials(
                    ModuleRegistry::get_stored_credentials( 'monetization_platform', 'zeydoo' ) ?: []
                );
                try {
                    $zd_filters = [ 'request_var' => $zd_request_var ];

                    $zd_cache_key = self::stats_cache_key( $user_id, $campaign_id, 'zeydoo-mon', $drilldown, $date_from, $date_to )
                        . '_r' . $zd_request_var;
                    $zd_cached = false;

                    if ( $ttl_seconds > 0 && ! $force_refresh ) {
                        $cached_zd = get_transient( $zd_cache_key );
                        if ( $cached_zd !== false ) {
                            $zd_rows   = $cached_zd;
                            $zd_cached = true;
                        }
                    }

                    if ( ! $zd_cached && $automation_running ) {
                        $zd_rows = [];
                        $skipped_modules[] = 'Zeydoo';
                        $cache_status['zeydoo-mon'] = [
                            'cached'      => false,
                            'module_name' => 'Zeydoo',
                            'module_type' => 'monetization_platform',
                            'skipped'     => true,
                        ];
                    } else {
                        if ( ! $zd_cached ) {
                            $zd_rows = $zd_module->get_statistics( $date_from, $date_to, [ 'date_time' ], $zd_filters );
                            if ( $ttl_seconds > 0 ) {
                                set_transient( $zd_cache_key, $zd_rows, $ttl_seconds );
                            }
                        }

                        $cache_status['zeydoo-mon'] = [
                            'cached'      => $zd_cached,
                            'module_name' => 'Zeydoo',
                            'module_type' => 'monetization_platform',
                            'skipped'     => false,
                        ];
                    }

                    // Build a date-keyed map from Zeydoo rows.
                    $zd_by_date = [];
                    foreach ( $zd_rows as $zr ) {
                        $date_key = $zr['date_time'] ?? '';
                        if ( ! $date_key ) continue;
                        $money = (float) ( $zr['money'] ?? 0 );
                        $reqs  = (int) ( $zr['requests'] ?? 0 );
                        $imps  = (int) ( $zr['impressions'] ?? 0 );
                        $convs = (int) ( $zr['conversions'] ?? 0 );
                        $cpm   = (float) ( $zr['cpm'] ?? 0 );
                        $zd_by_date[ $date_key ] = [
                            'zd_requests'    => $reqs,
                            'zd_impressions' => $imps,
                            'zd_conversions' => $convs,
                            'zd_earnings'    => round( $money, 6 ),
                            'zd_cpm'         => round( $cpm, 4 ),
                        ];
                    }

                    // Merge Zeydoo data into existing rows by date key.
                    foreach ( $zd_by_date as $date_key => $zd_data ) {
                        if ( ! isset( $rows[ $date_key ] ) ) {
                            $rows[ $date_key ] = [ 'zone_id' => $date_key ];
                        }
                        $rows[ $date_key ]['zd_requests']    = $zd_data['zd_requests'];
                        $rows[ $date_key ]['zd_impressions'] = $zd_data['zd_impressions'];
                        $rows[ $date_key ]['zd_conversions'] = $zd_data['zd_conversions'];
                        $rows[ $date_key ]['zd_earnings']    = $zd_data['zd_earnings'];
                        $rows[ $date_key ]['zd_cpm']         = $zd_data['zd_cpm'];
                    }
                } catch ( \Throwable $e ) {
                    $module_errors[] = [ 'module' => $zd_module->get_name(), 'message' => $e->getMessage() ];
                }
            }
        }

        // --- Traffic source daily balance (days report only) ---
        // Pull the recorded balance snapshot for this campaign's traffic source
        // on each date from the Balances table, so it can be shown as a column or
        // referenced in custom-column formulas via [Traffic Source] Balance.
        if ( $drilldown === 'days' && ! $zone_filter && $ts_slug ) {
            global $wpdb;
            $bal_table = $wpdb->prefix . 'optee_balances';
            $bal_rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT balance_date, balance FROM {$bal_table}
                 WHERE user_id = %d AND module_type = %s AND module_slug = %s
                   AND balance_date BETWEEN %s AND %s",
                $user_id, 'traffic_source', $ts_slug, $date_from, $date_to
            ), ARRAY_A );
            foreach ( (array) $bal_rows as $br ) {
                $date_key = $br['balance_date'] ?? '';
                if ( ! $date_key ) continue;
                if ( ! isset( $rows[ $date_key ] ) ) {
                    $rows[ $date_key ] = [ 'zone_id' => $date_key ];
                }
                $rows[ $date_key ]['ts_balance'] = round( (float) $br['balance'], 2 );
            }
        }

        // Calculate cross-module traffic loss %.
        // Loss between adscore visits and TS clicks (or impressions if clicks = 0).
        foreach ( $rows as &$row ) {
            $af_visits   = $row['af_visits'] ?? 0;
            $clicks      = $row['clicks'] ?? 0;
            $impressions = $row['impressions'] ?? 0;

            if ( $af_visits > 0 && $clicks > 0 ) {
                $row['traffic_loss'] = max( 0, round( ( 1 - ( $af_visits / $clicks ) ) * 100, 2 ) );
            } elseif ( $af_visits > 0 && $impressions > 0 ) {
                $row['traffic_loss'] = max( 0, round( ( 1 - ( $af_visits / $impressions ) ) * 100, 2 ) );
            } else {
                $row['traffic_loss'] = 0;
            }
        }
        unset( $row );

        $result = [
            'generated_at'      => $now,
            'cron_last_run'     => $cron_last ?: 'Never',
            'drilldown'         => $drilldown,
            'timeframe'         => $timeframe,
            'date_from'         => $date_from,
            'date_to'           => $date_to,
            'rows'              => array_values( $rows ),
            'cache_status'      => $cache_status,
            'cache_ttl'         => $cache_ttl,
            'campaign_status'    => $campaign_status,
            'automation_running' => $automation_running,
            'blocked_zones'      => $blocked_zones ?? [],
            'whitelisted_zones'  => $whitelisted_zones ?? [],
            'targeted_countries' => $targeted_countries ?? null,
            'targeting_dim'      => $targeting_dim ?? null,
            'targeted_targeting' => $targeted_targeting ?? null,
            // { "<zone value>": <note count> } — drives the note dot in the zone cell,
            // so annotated rows are visible without opening each one's menu.
            'note_counts'        => self::note_counts_for( $campaign_id, 'zone' ),
        ];

        if ( ! empty( $skipped_modules ) ) {
            $result['automation_note'] = 'Automation running. Live data skipped for: ' . implode( ', ', $skipped_modules ) . '. Try again when automation finishes.';
        }

        if ( ! empty( $module_errors ) ) {
            $result['module_errors'] = $module_errors;
        }

        wp_send_json_success( $result );
    }

    // ──────────────────────────────────────────
    // Monetag
    // ──────────────────────────────────────────

    public function get_monetag_zones(): void {
        $this->verify();

        $registry   = ModuleRegistry::get_instance();
        $mon_module = $registry->get_module( 'monetag' );

        if ( ! $mon_module ) {
            wp_send_json_error( [ 'message' => 'Monetag module not found.' ] );
        }

        $creds = ModuleRegistry::get_stored_credentials( 'monetization_platform', 'monetag' );
        if ( empty( $creds ) ) {
            wp_send_json_error( [ 'message' => 'Monetag credentials not configured.' ] );
        }

        $mon_module->set_credentials( $creds );
        $zones = $mon_module->get_zones();

        if ( empty( $zones ) ) {
            wp_send_json_error( [ 'message' => 'No zones found or API error.' ] );
        }

        wp_send_json_success( [ 'zones' => $zones ] );
    }

    public function set_monetag_source(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $post        = get_post( $campaign_id );

        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $zone_id   = sanitize_text_field( $_POST['monetag_zone_id'] ?? '' );
        $zone_name = sanitize_text_field( $_POST['monetag_zone_name'] ?? '' );
        $country   = sanitize_text_field( $_POST['monetag_country'] ?? '' );

        if ( $zone_id ) {
            update_post_meta( $campaign_id, '_optee_monetag_zone_id', $zone_id );
            update_post_meta( $campaign_id, '_optee_monetag_zone_name', $zone_name );
            update_post_meta( $campaign_id, '_optee_monetag_country', $country );
        } else {
            delete_post_meta( $campaign_id, '_optee_monetag_zone_id' );
            delete_post_meta( $campaign_id, '_optee_monetag_zone_name' );
            delete_post_meta( $campaign_id, '_optee_monetag_country' );
        }

        Logger::log( 'info', 'campaign', "Monetag source updated for: {$post->post_title}", [
            'campaign_id' => $campaign_id,
            'zone_id'     => $zone_id,
            'zone_name'   => $zone_name,
            'country'     => $country,
        ] );

        wp_send_json_success( [
            'message'   => $zone_id ? 'Monetag source saved.' : 'Monetag source removed.',
            'zone_id'   => $zone_id,
            'zone_name' => $zone_name,
            'country'   => $country,
        ] );
    }

    // ──────────────────────────────────────────
    // ProPush
    // ──────────────────────────────────────────

    public function get_propush_zones(): void {
        $this->verify();

        $registry   = ModuleRegistry::get_instance();
        $pp_module = $registry->get_module( 'propush' );

        if ( ! $pp_module ) {
            wp_send_json_error( [ 'message' => 'ProPush module not found.' ] );
        }

        $creds = ModuleRegistry::get_stored_credentials( 'monetization_platform', 'propush' );
        if ( empty( $creds ) ) {
            wp_send_json_error( [ 'message' => 'ProPush credentials not configured.' ] );
        }

        $pp_module->set_credentials( $creds );
        $zones = $pp_module->get_zones();

        if ( empty( $zones ) ) {
            wp_send_json_error( [ 'message' => 'No zones found or API error.' ] );
        }

        wp_send_json_success( [ 'zones' => $zones ] );
    }

    public function get_propush_sites(): void {
        $this->verify();

        $registry  = ModuleRegistry::get_instance();
        $pp_module = $registry->get_module( 'propush' );

        if ( ! $pp_module || ! method_exists( $pp_module, 'get_sites' ) ) {
            wp_send_json_error( [ 'message' => 'ProPush module not found.' ] );
        }

        $creds = ModuleRegistry::get_stored_credentials( 'monetization_platform', 'propush' );
        if ( empty( $creds ) ) {
            wp_send_json_error( [ 'message' => 'ProPush credentials not configured.' ] );
        }

        $pp_module->set_credentials( $creds );

        try {
            $sites = $pp_module->get_sites();
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Failed to load sites: ' . $e->getMessage() ] );
        }

        wp_send_json_success( [ 'sites' => $sites ] );
    }

    public function set_propush_source(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $post        = get_post( $campaign_id );

        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $zone_id   = sanitize_text_field( $_POST['propush_zone_id'] ?? '' );
        $zone_name = sanitize_text_field( $_POST['propush_zone_name'] ?? '' );
        $site_id   = sanitize_text_field( $_POST['propush_site_id'] ?? '' );
        $site_name = sanitize_text_field( $_POST['propush_site_name'] ?? '' );
        $source_id = sanitize_text_field( $_POST['propush_source_id'] ?? '' );
        $country   = sanitize_text_field( $_POST['propush_country'] ?? '' );

        if ( $zone_id || $site_id || $source_id ) {
            if ( $zone_id ) {
                update_post_meta( $campaign_id, '_optee_propush_zone_id', $zone_id );
                update_post_meta( $campaign_id, '_optee_propush_zone_name', $zone_name );
            } else {
                delete_post_meta( $campaign_id, '_optee_propush_zone_id' );
                delete_post_meta( $campaign_id, '_optee_propush_zone_name' );
            }
            if ( $site_id ) {
                update_post_meta( $campaign_id, '_optee_propush_site_id', $site_id );
                update_post_meta( $campaign_id, '_optee_propush_site_name', $site_name );
            } else {
                delete_post_meta( $campaign_id, '_optee_propush_site_id' );
                delete_post_meta( $campaign_id, '_optee_propush_site_name' );
            }
            update_post_meta( $campaign_id, '_optee_propush_source_id', $source_id );
            update_post_meta( $campaign_id, '_optee_propush_country', $country );
        } else {
            delete_post_meta( $campaign_id, '_optee_propush_zone_id' );
            delete_post_meta( $campaign_id, '_optee_propush_zone_name' );
            delete_post_meta( $campaign_id, '_optee_propush_site_id' );
            delete_post_meta( $campaign_id, '_optee_propush_site_name' );
            delete_post_meta( $campaign_id, '_optee_propush_source_id' );
            delete_post_meta( $campaign_id, '_optee_propush_country' );
        }

        Logger::log( 'info', 'campaign', "ProPush source updated for: {$post->post_title}", [
            'campaign_id' => $campaign_id,
            'zone_id'     => $zone_id,
            'zone_name'   => $zone_name,
            'site_id'     => $site_id,
            'site_name'   => $site_name,
            'source_id'   => $source_id,
            'country'     => $country,
        ] );

        wp_send_json_success( [
            'message'   => ( $zone_id || $site_id ) ? 'ProPush source saved.' : 'ProPush source removed.',
            'zone_id'   => $zone_id,
            'zone_name' => $zone_name,
            'site_id'   => $site_id,
            'site_name' => $site_name,
            'source_id' => $source_id,
            'country'   => $country,
        ] );
    }

    public function set_partners_house_source(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $post        = get_post( $campaign_id );

        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $active  = sanitize_text_field( $_POST['ph_active'] ?? '' );
        $sub1    = sanitize_text_field( $_POST['ph_sub1'] ?? '' );
        $sub2    = sanitize_text_field( $_POST['ph_sub2'] ?? '' );
        $sub3    = sanitize_text_field( $_POST['ph_sub3'] ?? '' );
        $sub4    = sanitize_text_field( $_POST['ph_sub4'] ?? '' );
        $country = sanitize_text_field( $_POST['ph_country'] ?? '' );

        if ( $active ) {
            update_post_meta( $campaign_id, '_optee_ph_active', '1' );
            update_post_meta( $campaign_id, '_optee_ph_sub1', $sub1 );
            update_post_meta( $campaign_id, '_optee_ph_sub2', $sub2 );
            update_post_meta( $campaign_id, '_optee_ph_sub3', $sub3 );
            update_post_meta( $campaign_id, '_optee_ph_sub4', $sub4 );
            update_post_meta( $campaign_id, '_optee_ph_country', $country );
        } else {
            delete_post_meta( $campaign_id, '_optee_ph_active' );
            delete_post_meta( $campaign_id, '_optee_ph_sub1' );
            delete_post_meta( $campaign_id, '_optee_ph_sub2' );
            delete_post_meta( $campaign_id, '_optee_ph_sub3' );
            delete_post_meta( $campaign_id, '_optee_ph_sub4' );
            delete_post_meta( $campaign_id, '_optee_ph_country' );
        }

        Logger::log( 'info', 'campaign', "Partners.House source updated for: {$post->post_title}", [
            'campaign_id' => $campaign_id,
            'active'      => $active,
            'sub1'        => $sub1,
            'sub2'        => $sub2,
            'sub3'        => $sub3,
            'sub4'        => $sub4,
            'country'     => $country,
        ] );

        wp_send_json_success( [
            'message' => $active ? 'Partners.House source saved.' : 'Partners.House source removed.',
            'sub1'    => $sub1,
            'sub2'    => $sub2,
            'sub3'    => $sub3,
            'sub4'    => $sub4,
            'country' => $country,
        ] );
    }

    public function get_rap_sites(): void {
        $this->verify();

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( 'rollerads-publisher' );

        if ( ! $module || ! method_exists( $module, 'get_sites' ) ) {
            wp_send_json_error( [ 'message' => 'RollerAds Publisher module not available.' ] );
        }

        $credentials = ModuleRegistry::get_stored_credentials( 'monetization_platform', 'rollerads-publisher' );
        if ( ! $credentials ) {
            wp_send_json_error( [ 'message' => 'No RollerAds Publisher credentials found.' ] );
        }

        $module->set_credentials( $credentials );

        try {
            $sites = $module->get_sites();
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Failed to load sites: ' . $e->getMessage() ] );
        }

        wp_send_json_success( [ 'sites' => $sites ] );
    }

    public function get_rap_zones(): void {
        $this->verify();

        $site_id = sanitize_text_field( $_POST['site_id'] ?? '' );
        if ( ! $site_id ) {
            wp_send_json_error( [ 'message' => 'Site ID is required.' ] );
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( 'rollerads-publisher' );

        if ( ! $module || ! method_exists( $module, 'get_zones' ) ) {
            wp_send_json_error( [ 'message' => 'RollerAds Publisher module not available.' ] );
        }

        $credentials = ModuleRegistry::get_stored_credentials( 'monetization_platform', 'rollerads-publisher' );
        if ( ! $credentials ) {
            wp_send_json_error( [ 'message' => 'No RollerAds Publisher credentials found.' ] );
        }

        $module->set_credentials( $credentials );

        try {
            $zones = $module->get_zones( $site_id );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Failed to load zones: ' . $e->getMessage() ] );
        }

        wp_send_json_success( [ 'zones' => $zones ] );
    }

    public function set_rap_source(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $post        = get_post( $campaign_id );

        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $site_id   = sanitize_text_field( $_POST['rap_site_id'] ?? '' );
        $site_name = sanitize_text_field( $_POST['rap_site_name'] ?? '' );
        $zone_id   = sanitize_text_field( $_POST['rap_zone_id'] ?? '' );
        $zone_name = sanitize_text_field( $_POST['rap_zone_name'] ?? '' );

        if ( $site_id ) {
            update_post_meta( $campaign_id, '_optee_rap_site_id', $site_id );
            update_post_meta( $campaign_id, '_optee_rap_site_name', $site_name );
            if ( $zone_id ) {
                update_post_meta( $campaign_id, '_optee_rap_zone_id', $zone_id );
                update_post_meta( $campaign_id, '_optee_rap_zone_name', $zone_name );
            } else {
                delete_post_meta( $campaign_id, '_optee_rap_zone_id' );
                delete_post_meta( $campaign_id, '_optee_rap_zone_name' );
            }
        } else {
            delete_post_meta( $campaign_id, '_optee_rap_site_id' );
            delete_post_meta( $campaign_id, '_optee_rap_site_name' );
            delete_post_meta( $campaign_id, '_optee_rap_zone_id' );
            delete_post_meta( $campaign_id, '_optee_rap_zone_name' );
        }

        Logger::log( 'info', 'campaign', "RollerAds Publisher source updated for: {$post->post_title}", [
            'campaign_id' => $campaign_id,
            'site_id'     => $site_id,
            'site_name'   => $site_name,
            'zone_id'     => $zone_id,
            'zone_name'   => $zone_name,
        ] );

        wp_send_json_success( [
            'message'   => $site_id ? 'RollerAds Publisher source saved.' : 'RollerAds Publisher source removed.',
            'site_id'   => $site_id,
            'site_name' => $site_name,
            'zone_id'   => $zone_id,
            'zone_name' => $zone_name,
        ] );
    }

    // ──────────────────────────────────────────
    // HilltopAds Publisher (Monetization)
    // ──────────────────────────────────────────

    public function get_hta_sites(): void {
        $this->verify();

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( 'hilltopads-publisher' );

        if ( ! $module || ! method_exists( $module, 'get_sites' ) ) {
            wp_send_json_error( [ 'message' => 'HilltopAds Publisher module not available.' ] );
        }

        $credentials = ModuleRegistry::get_stored_credentials( 'monetization_platform', 'hilltopads-publisher' );
        if ( ! $credentials ) {
            wp_send_json_error( [ 'message' => 'No HilltopAds Publisher credentials found.' ] );
        }

        $module->set_credentials( $credentials );

        try {
            $sites = $module->get_sites();
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Failed to load sites: ' . $e->getMessage() ] );
        }

        wp_send_json_success( [ 'sites' => $sites ] );
    }

    public function get_hta_zones(): void {
        $this->verify();

        $site_id = sanitize_text_field( $_POST['site_id'] ?? '' );
        if ( ! $site_id ) {
            wp_send_json_error( [ 'message' => 'Site ID is required.' ] );
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( 'hilltopads-publisher' );

        if ( ! $module || ! method_exists( $module, 'get_zones' ) ) {
            wp_send_json_error( [ 'message' => 'HilltopAds Publisher module not available.' ] );
        }

        $credentials = ModuleRegistry::get_stored_credentials( 'monetization_platform', 'hilltopads-publisher' );
        if ( ! $credentials ) {
            wp_send_json_error( [ 'message' => 'No HilltopAds Publisher credentials found.' ] );
        }

        $module->set_credentials( $credentials );

        try {
            $zones = $module->get_zones( $site_id );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Failed to load zones: ' . $e->getMessage() ] );
        }

        wp_send_json_success( [ 'zones' => $zones ] );
    }

    public function set_hta_source(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $post        = get_post( $campaign_id );

        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $site_id   = sanitize_text_field( $_POST['hta_site_id'] ?? '' );
        $site_name = sanitize_text_field( $_POST['hta_site_name'] ?? '' );
        $zone_id   = sanitize_text_field( $_POST['hta_zone_id'] ?? '' );
        $zone_name = sanitize_text_field( $_POST['hta_zone_name'] ?? '' );

        if ( $site_id ) {
            update_post_meta( $campaign_id, '_optee_hta_site_id', $site_id );
            update_post_meta( $campaign_id, '_optee_hta_site_name', $site_name );
            if ( $zone_id ) {
                update_post_meta( $campaign_id, '_optee_hta_zone_id', $zone_id );
                update_post_meta( $campaign_id, '_optee_hta_zone_name', $zone_name );
            } else {
                delete_post_meta( $campaign_id, '_optee_hta_zone_id' );
                delete_post_meta( $campaign_id, '_optee_hta_zone_name' );
            }
        } else {
            delete_post_meta( $campaign_id, '_optee_hta_site_id' );
            delete_post_meta( $campaign_id, '_optee_hta_site_name' );
            delete_post_meta( $campaign_id, '_optee_hta_zone_id' );
            delete_post_meta( $campaign_id, '_optee_hta_zone_name' );
        }

        Logger::log( 'info', 'campaign', "HilltopAds Publisher source updated for: {$post->post_title}", [
            'campaign_id' => $campaign_id,
            'site_id'     => $site_id,
            'site_name'   => $site_name,
            'zone_id'     => $zone_id,
            'zone_name'   => $zone_name,
        ] );

        wp_send_json_success( [
            'message'   => $site_id ? 'HilltopAds Publisher source saved.' : 'HilltopAds Publisher source removed.',
            'site_id'   => $site_id,
            'site_name' => $site_name,
            'zone_id'   => $zone_id,
            'zone_name' => $zone_name,
        ] );
    }

    // ──────────────────────────────────────────
    // Monetizer (Monetization)
    // ──────────────────────────────────────────

    public function get_monetizer_mon_leagues(): void {
        $this->verify();

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( 'monetizer', 'monetization_platform' );

        if ( ! $module || ! method_exists( $module, 'get_leagues' ) ) {
            wp_send_json_error( [ 'message' => 'Monetizer module not available.' ] );
        }

        $credentials = ModuleRegistry::get_stored_credentials( 'monetization_platform', 'monetizer' );
        if ( ! $credentials ) {
            wp_send_json_error( [ 'message' => 'No Monetizer credentials found.' ] );
        }

        $module->set_credentials( $credentials );

        try {
            $leagues = $module->get_leagues();
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Failed to load leagues: ' . $e->getMessage() ] );
        }

        if ( empty( $leagues ) ) {
            wp_send_json_error( [ 'message' => 'No leagues found or API error.' ] );
        }

        wp_send_json_success( [ 'leagues' => $leagues ] );
    }

    public function get_monetizer_mon_campaigns(): void {
        $this->verify();

        $league_id = sanitize_text_field( $_POST['league_id'] ?? '' );
        if ( ! $league_id ) {
            wp_send_json_error( [ 'message' => 'League ID is required.' ] );
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module( 'monetizer', 'monetization_platform' );

        if ( ! $module || ! method_exists( $module, 'get_league_campaigns' ) ) {
            wp_send_json_error( [ 'message' => 'Monetizer module not available.' ] );
        }

        $credentials = ModuleRegistry::get_stored_credentials( 'monetization_platform', 'monetizer' );
        if ( ! $credentials ) {
            wp_send_json_error( [ 'message' => 'No Monetizer credentials found.' ] );
        }

        $module->set_credentials( $credentials );

        try {
            $campaigns = $module->get_league_campaigns( $league_id );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Failed to load campaigns: ' . $e->getMessage() ] );
        }

        wp_send_json_success( [ 'campaigns' => $campaigns ] );
    }

    public function set_monetizer_mon_source(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $post        = get_post( $campaign_id );

        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $league_id        = sanitize_text_field( $_POST['mtzr_league_id'] ?? '' );
        $league_name      = sanitize_text_field( $_POST['mtzr_league_name'] ?? '' );
        $campaign_keyword = sanitize_text_field( $_POST['mtzr_campaign_keyword'] ?? '' );

        if ( $league_id ) {
            update_post_meta( $campaign_id, '_optee_mtzr_league_id', $league_id );
            update_post_meta( $campaign_id, '_optee_mtzr_league_name', $league_name );
            if ( $campaign_keyword ) {
                update_post_meta( $campaign_id, '_optee_mtzr_campaign_keyword', $campaign_keyword );
            } else {
                delete_post_meta( $campaign_id, '_optee_mtzr_campaign_keyword' );
            }
        } else {
            delete_post_meta( $campaign_id, '_optee_mtzr_league_id' );
            delete_post_meta( $campaign_id, '_optee_mtzr_league_name' );
            delete_post_meta( $campaign_id, '_optee_mtzr_campaign_keyword' );
        }

        Logger::log( 'info', 'campaign', "Monetizer source updated for: {$post->post_title}", [
            'campaign_id'      => $campaign_id,
            'league_id'        => $league_id,
            'league_name'      => $league_name,
            'campaign_keyword' => $campaign_keyword,
        ] );

        wp_send_json_success( [
            'message'          => $league_id ? 'Monetizer source saved.' : 'Monetizer source removed.',
            'league_id'        => $league_id,
            'league_name'      => $league_name,
            'campaign_keyword' => $campaign_keyword,
        ] );
    }

    public function set_zeydoo_mon_source(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $post        = get_post( $campaign_id );

        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $request_var = sanitize_text_field( $_POST['zd_request_var'] ?? '' );

        if ( $request_var ) {
            update_post_meta( $campaign_id, '_optee_zd_request_var', $request_var );
        } else {
            delete_post_meta( $campaign_id, '_optee_zd_request_var' );
        }

        Logger::log( 'info', 'campaign', "Zeydoo source updated for: {$post->post_title}", [
            'campaign_id'  => $campaign_id,
            'request_var'  => $request_var,
        ] );

        wp_send_json_success( [
            'message'     => $request_var ? 'Zeydoo source saved.' : 'Zeydoo source removed.',
            'request_var' => $request_var,
        ] );
    }

    /**
     * Convert a timeframe key to date_from / date_to.
     */
    private static function resolve_timeframe( string $timeframe ): array {
        $today     = current_time( 'Y-m-d' );
        $yesterday = wp_date( 'Y-m-d', strtotime( '-1 day', strtotime( $today ) ) );

        switch ( $timeframe ) {
            case 'today':
                return [ 'from' => $today, 'to' => $today ];
            case 'yesterday':
                return [ 'from' => $yesterday, 'to' => $yesterday ];
            case '3d':
                return [ 'from' => wp_date( 'Y-m-d', strtotime( '-2 days', strtotime( $today ) ) ), 'to' => $today ];
            case '7d':
                return [ 'from' => wp_date( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) ), 'to' => $today ];
            case '14d':
                return [ 'from' => wp_date( 'Y-m-d', strtotime( '-13 days', strtotime( $today ) ) ), 'to' => $today ];
            case '30d':
                return [ 'from' => wp_date( 'Y-m-d', strtotime( '-29 days', strtotime( $today ) ) ), 'to' => $today ];
            case '60d':
                return [ 'from' => wp_date( 'Y-m-d', strtotime( '-59 days', strtotime( $today ) ) ), 'to' => $today ];
            case '90d':
                return [ 'from' => wp_date( 'Y-m-d', strtotime( '-89 days', strtotime( $today ) ) ), 'to' => $today ];
            case '180d':
                return [ 'from' => wp_date( 'Y-m-d', strtotime( '-179 days', strtotime( $today ) ) ), 'to' => $today ];
            case '360d':
                return [ 'from' => wp_date( 'Y-m-d', strtotime( '-359 days', strtotime( $today ) ) ), 'to' => $today ];
            case 'this_month':
                return [ 'from' => wp_date( 'Y-m-01', strtotime( $today ) ), 'to' => $today ];
            case 'last_month':
                $first = wp_date( 'Y-m-01', strtotime( 'first day of last month', strtotime( $today ) ) );
                $last  = wp_date( 'Y-m-t', strtotime( $first ) );
                return [ 'from' => $first, 'to' => $last ];
            default:
                return [ 'from' => wp_date( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) ), 'to' => $today ];
        }
    }

    /**
     * Resolve a stats date range from the request.
     *
     * For the `custom` timeframe, reads `date_from` / `date_to` from POST, validates them,
     * and clamps the span to a maximum of 365 days (matches the widest preset — "Last 360
     * days"; some source APIs error out on wider windows). Anything else falls through
     * to the named-timeframe resolver.
     */
    private static function resolve_range_from_request( string $timeframe ): array {
        if ( $timeframe !== 'custom' ) {
            return self::resolve_timeframe( $timeframe );
        }

        $today = current_time( 'Y-m-d' );
        $from  = sanitize_text_field( $_POST['date_from'] ?? '' );
        $to    = sanitize_text_field( $_POST['date_to'] ?? '' );

        // Validate format strictly (Y-m-d, real calendar dates).
        $valid = static function ( string $d ): bool {
            $dt = \DateTime::createFromFormat( 'Y-m-d', $d );
            return $dt && $dt->format( 'Y-m-d' ) === $d;
        };
        if ( ! $valid( $from ) || ! $valid( $to ) ) {
            // Fall back to a safe default rather than passing garbage to module APIs.
            return self::resolve_timeframe( '7d' );
        }

        // Normalise order.
        if ( strtotime( $from ) > strtotime( $to ) ) {
            [ $from, $to ] = [ $to, $from ];
        }

        // Never allow a future end date.
        if ( strtotime( $to ) > strtotime( $today ) ) {
            $to = $today;
        }

        // Clamp the span to 365 days (inclusive).
        $max_from = wp_date( 'Y-m-d', strtotime( '-364 days', strtotime( $to ) ) );
        if ( strtotime( $from ) < strtotime( $max_from ) ) {
            $from = $max_from;
        }

        return [ 'from' => $from, 'to' => $to ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Alerts module (Phase 1: balance threshold alerts)
    // ──────────────────────────────────────────────────────────────────

    /** Module types eligible for balance alerts. */
    private const ALERT_BALANCE_TYPES = [ 'traffic_source', 'affiliate_network', 'monetization_platform', 'antifraud_tool' ];

    public function get_alerts(): void {
        $this->verify();
        $user_id = get_current_user_id();

        $posts = get_posts( [
            'post_type'      => 'optee_alert',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $alerts = array_map( [ self::class, 'format_alert' ], $posts );

        wp_send_json_success( [
            'alerts'         => $alerts,
            'sources'        => self::alert_balance_sources(),
            'campaigns'      => self::alert_campaigns( $user_id ),
            'custom_columns' => array_map(
                fn( $c ) => [ 'id' => $c['id'], 'name' => $c['name'] ],
                CustomColumns::get_all( $user_id )
            ),
            'presets'        => self::alert_report_presets(),
        ] );
    }

    /** The user's campaigns for the report-alert dropdown. */
    private static function alert_campaigns( int $user_id ): array {
        $posts = get_posts( [
            'post_type'      => 'optee_campaign',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );
        return array_map(
            fn( $p ) => [ 'id' => $p->ID, 'name' => html_entity_decode( $p->post_title, ENT_QUOTES, 'UTF-8' ) ],
            $posts
        );
    }

    /** Curated report-alert presets (New Alert → pick → tweak the number). */
    private static function alert_report_presets(): array {
        return [
            [ 'key' => 'losing', 'label' => '🔴 Losing campaign',        'conditions' => [ [ 'source' => 'traffic_source', 'metric' => 'profit', 'operator' => '<', 'value' => -5 ] ] ],
            [ 'key' => 'bot',    'label' => '🤖 High bot traffic',       'conditions' => [ [ 'source' => 'antifraud', 'metric' => 'bot_percent', 'operator' => '>', 'value' => 90 ] ] ],
            [ 'key' => 'roi',    'label' => '📉 ROI collapse',           'conditions' => [ [ 'source' => 'traffic_source', 'metric' => 'roi', 'operator' => '<', 'value' => -20 ] ] ],
            [ 'key' => 'nocv',   'label' => '💸 Spending, no conversions','conditions' => [ [ 'source' => 'traffic_source', 'metric' => 'cost', 'operator' => '>', 'value' => 10 ], [ 'source' => 'traffic_source', 'metric' => 'conversions', 'operator' => '==', 'value' => 0 ] ] ],
            [ 'key' => 'tloss',  'label' => '🚦 Traffic-loss spike',     'conditions' => [ [ 'source' => 'antifraud', 'metric' => 'traffic_loss', 'operator' => '>', 'value' => 40 ] ] ],
            [ 'key' => 'lowq',   'label' => '🧪 Low quality traffic',    'conditions' => [ [ 'source' => 'antifraud', 'metric' => 'valid_percent', 'operator' => '<', 'value' => 40 ] ] ],
        ];
    }

    /** Readable one-line condition summary for the alerts list. */
    private static function alert_condition_summary( array $conditions, int $campaign_id ): string {
        $parts = [];
        foreach ( $conditions as $c ) {
            $metric = (string) ( $c['metric'] ?? '' );
            if ( ( $c['source'] ?? '' ) === 'custom' && str_starts_with( $metric, 'cc__' ) ) {
                $cc    = CustomColumns::find( get_current_user_id(), substr( $metric, 4 ) );
                $label = $cc['name'] ?? $metric;
            } else {
                $label = \Optee\Plugin\Alerts\AlertEngine::metric_label( $metric );
            }
            $val     = rtrim( rtrim( number_format( (float) ( $c['value'] ?? 0 ), 4 ), '0' ), '.' );
            $parts[] = $label . ' ' . ( $c['operator'] ?? '' ) . ' ' . $val;
        }
        return implode( ' AND ', $parts );
    }

    public function save_alert(): void {
        $this->verify();
        $user_id  = get_current_user_id();
        $alert_id = intval( $_POST['alert_id'] ?? 0 );
        $type     = sanitize_text_field( $_POST['alert_type'] ?? 'balance' );

        // Channels — at least one (shared by both types).
        $channels = [];
        if ( ! empty( $_POST['channel_email'] ) && $_POST['channel_email'] !== '0' ) $channels[] = 'email';
        if ( ! empty( $_POST['channel_bell'] ) && $_POST['channel_bell'] !== '0' )   $channels[] = 'bell';
        if ( empty( $channels ) ) {
            wp_send_json_error( [ 'message' => 'Pick at least one notification channel (email or bell).' ] );
        }
        $enabled = ( ( $_POST['enabled'] ?? '1' ) === '0' ) ? '0' : '1';

        // ── Report alert (Phase 2) ───────────────────────────────────
        if ( $type === 'report' ) {
            $campaign_id = intval( $_POST['campaign_id'] ?? 0 );
            $campaign    = $campaign_id ? get_post( $campaign_id ) : null;
            if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== $user_id ) {
                wp_send_json_error( [ 'message' => 'Please pick a valid campaign.' ] );
            }

            $timeframe = sanitize_text_field( $_POST['timeframe'] ?? 'yesterday' );
            if ( ! in_array( $timeframe, [ 'today', 'yesterday', '24h' ], true ) ) {
                $timeframe = 'yesterday';
            }
            $sched = sanitize_text_field( $_POST['schedule_time'] ?? '06:00' );
            if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $sched ) ) {
                $sched = '06:00';
            }

            // Conditions (JSON) — validate to the rule-condition shape.
            $valid_ops  = [ '>', '>=', '<', '<=', '==', '!=' ];
            $decoded    = json_decode( wp_unslash( $_POST['conditions'] ?? '[]' ), true );
            $conditions = [];
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $c ) {
                    $metric = sanitize_text_field( $c['metric'] ?? '' );
                    $op     = $c['operator'] ?? '>';
                    if ( $metric === '' || ! in_array( $op, $valid_ops, true ) ) {
                        continue;
                    }
                    $conditions[] = [
                        'source'   => sanitize_text_field( $c['source'] ?? 'traffic_source' ),
                        'metric'   => $metric,
                        'operator' => $op,
                        'value'    => (float) ( $c['value'] ?? 0 ),
                    ];
                }
            }
            if ( empty( $conditions ) ) {
                wp_send_json_error( [ 'message' => 'Add at least one condition.' ] );
            }

            $camp_name = html_entity_decode( $campaign->post_title, ENT_QUOTES, 'UTF-8' );
            $title     = sprintf( '%s — %d condition(s) [%s]', $camp_name, count( $conditions ), $timeframe );

            $alert_id = self::upsert_alert( $alert_id, $user_id, $title );
            if ( is_wp_error( $alert_id ) ) {
                wp_send_json_error( [ 'message' => $alert_id->get_error_message() ] );
            }

            update_post_meta( $alert_id, '_optee_alert_type', 'report' );
            update_post_meta( $alert_id, '_optee_alert_campaign_id', $campaign_id );
            update_post_meta( $alert_id, '_optee_alert_conditions', $conditions );
            update_post_meta( $alert_id, '_optee_alert_timeframe', $timeframe );
            update_post_meta( $alert_id, '_optee_alert_schedule_time', $sched );
            update_post_meta( $alert_id, '_optee_alert_channels', implode( ',', $channels ) );
            update_post_meta( $alert_id, '_optee_alert_enabled', $enabled );

            wp_send_json_success( [
                'message' => 'Alert saved.',
                'alert'   => self::format_alert( get_post( $alert_id ) ),
            ] );
        }

        // ── Balance alert (Phase 1, default) ─────────────────────────
        $module_type = sanitize_text_field( $_POST['module_type'] ?? '' );
        $module_slug = sanitize_text_field( $_POST['module_slug'] ?? '' );
        $direction   = sanitize_text_field( $_POST['direction'] ?? 'below' );
        $threshold   = (float) ( $_POST['threshold'] ?? 0 );

        if ( ! in_array( $module_type, self::ALERT_BALANCE_TYPES, true ) || $module_slug === '' ) {
            wp_send_json_error( [ 'message' => 'Please pick a valid integration.' ] );
        }
        if ( ! in_array( $direction, [ 'below', 'above' ], true ) ) {
            $direction = 'below';
        }

        $module      = ModuleRegistry::get_instance()->get_module( $module_slug, $module_type );
        $module_name = $module ? $module->get_name() : $module_slug;
        $title       = sprintf( '%s balance %s %s', $module_name, $direction, number_format( $threshold, 2 ) );

        $alert_id = self::upsert_alert( $alert_id, $user_id, $title );
        if ( is_wp_error( $alert_id ) ) {
            wp_send_json_error( [ 'message' => $alert_id->get_error_message() ] );
        }

        update_post_meta( $alert_id, '_optee_alert_type', 'balance' );
        update_post_meta( $alert_id, '_optee_alert_module_type', $module_type );
        update_post_meta( $alert_id, '_optee_alert_module_slug', $module_slug );
        update_post_meta( $alert_id, '_optee_alert_direction', $direction );
        update_post_meta( $alert_id, '_optee_alert_threshold', $threshold );
        update_post_meta( $alert_id, '_optee_alert_channels', implode( ',', $channels ) );
        update_post_meta( $alert_id, '_optee_alert_enabled', $enabled );

        wp_send_json_success( [
            'message' => 'Alert saved.',
            'alert'   => self::format_alert( get_post( $alert_id ) ),
        ] );
    }

    /** Create or update an optee_alert (ownership-checked). Returns id or WP_Error. */
    private static function upsert_alert( int $alert_id, int $user_id, string $title ) {
        if ( $alert_id > 0 ) {
            $post = get_post( $alert_id );
            if ( ! $post || $post->post_type !== 'optee_alert' || (int) $post->post_author !== $user_id ) {
                return new \WP_Error( 'not_found', 'Alert not found.' );
            }
            wp_update_post( [ 'ID' => $alert_id, 'post_title' => $title ] );
            return $alert_id;
        }
        return wp_insert_post( [
            'post_type'   => 'optee_alert',
            'post_title'  => $title,
            'post_status' => 'publish',
            'post_author' => $user_id,
        ] );
    }

    public function delete_alert(): void {
        $this->verify();
        $alert_id = intval( $_POST['alert_id'] ?? 0 );
        $post     = get_post( $alert_id );
        if ( ! $post || $post->post_type !== 'optee_alert' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Alert not found.' ] );
        }
        wp_delete_post( $alert_id, true );
        wp_send_json_success( [ 'message' => 'Alert deleted.' ] );
    }

    public function toggle_alert(): void {
        $this->verify();
        $alert_id = intval( $_POST['alert_id'] ?? 0 );
        $post     = get_post( $alert_id );
        if ( ! $post || $post->post_type !== 'optee_alert' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Alert not found.' ] );
        }
        $current = get_post_meta( $alert_id, '_optee_alert_enabled', true );
        $new     = $current === '1' ? '0' : '1';
        update_post_meta( $alert_id, '_optee_alert_enabled', $new );
        wp_send_json_success( [ 'enabled' => $new, 'message' => $new === '1' ? 'Alert enabled.' : 'Alert disabled.' ] );
    }

    /**
     * Dry-run a report alert: evaluate its conditions against the campaign's live
     * data now and return whether it WOULD fire — no notifications sent.
     */
    public function preview_alert(): void {
        $this->verify();
        $alert_id = intval( $_POST['alert_id'] ?? 0 );
        $post     = get_post( $alert_id );
        if ( ! $post || $post->post_type !== 'optee_alert' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Alert not found.' ] );
        }
        if ( ( get_post_meta( $alert_id, '_optee_alert_type', true ) ?: 'balance' ) !== 'report' ) {
            wp_send_json_error( [ 'message' => 'Preview is only available for report alerts.' ] );
        }

        $campaign_id = (int) get_post_meta( $alert_id, '_optee_alert_campaign_id', true );
        $conditions  = get_post_meta( $alert_id, '_optee_alert_conditions', true ) ?: [];
        $timeframe   = get_post_meta( $alert_id, '_optee_alert_timeframe', true ) ?: 'yesterday';
        $campaign    = $campaign_id ? get_post( $campaign_id ) : null;
        if ( ! $campaign ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }
        if ( empty( $conditions ) ) {
            wp_send_json_error( [ 'message' => 'This alert has no conditions.' ] );
        }

        try {
            $result = \Optee\Plugin\Rules\RuleCondition::evaluate_campaign( $conditions, $campaign_id, $timeframe );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Evaluation failed: ' . $e->getMessage() ] );
        }

        // Enrich each condition with a readable label for display.
        $details = [];
        foreach ( ( $result['details'] ?? [] ) as $d ) {
            $metric = (string) ( $d['metric'] ?? '' );
            $label  = str_starts_with( $metric, 'cc__' )
                ? ( ( CustomColumns::find( get_current_user_id(), substr( $metric, 4 ) )['name'] ?? $metric ) )
                : \Optee\Plugin\Alerts\AlertEngine::metric_label( $metric );
            $details[] = array_merge( $d, [ 'label' => $label ] );
        }

        wp_send_json_success( [
            'passed'        => ! empty( $result['passed'] ),
            'campaign_name' => html_entity_decode( $campaign->post_title, ENT_QUOTES, 'UTF-8' ),
            'timeframe'     => \Optee\Plugin\Alerts\AlertEngine::timeframe_label( $timeframe ),
            'details'       => $details,
        ] );
    }

    /** Integrations (with stored credentials) that a balance alert can target. */
    private static function alert_balance_sources(): array {
        $registry = ModuleRegistry::get_instance();
        $sources  = [];
        foreach ( self::ALERT_BALANCE_TYPES as $type ) {
            foreach ( $registry->get_modules_by_type( $type ) as $module ) {
                $slug = $module->get_slug();
                if ( empty( ModuleRegistry::get_stored_credentials( $type, $slug ) ) ) {
                    continue; // only integrations the user has configured
                }
                $name = $module->get_name();
                $sources[] = [
                    'type'  => $type,
                    'slug'  => $slug,
                    'name'  => $name,
                    // Prefixed label disambiguates integrations that share a name
                    // across types (e.g. Monetizer as both affiliate network + monetization).
                    'label' => '[' . self::alert_type_label( $type ) . '] ' . $name,
                ];
            }
        }
        usort( $sources, fn( $a, $b ) => strcasecmp( $a['label'], $b['label'] ) );
        return $sources;
    }

    /** Human label for a module type (used to disambiguate the alert source dropdown). */
    private static function alert_type_label( string $type ): string {
        $labels = [
            'traffic_source'        => 'Traffic Source',
            'affiliate_network'     => 'Affiliate Network',
            'monetization_platform' => 'Monetization',
            'antifraud_tool'        => 'Antifraud',
        ];
        return $labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
    }

    private static function format_alert( \WP_Post $post ): array {
        $type     = get_post_meta( $post->ID, '_optee_alert_type', true ) ?: 'balance';
        $channels = (string) get_post_meta( $post->ID, '_optee_alert_channels', true );

        $base = [
            'id'            => $post->ID,
            'type'          => $type,
            'channels'      => array_values( array_filter( array_map( 'trim', explode( ',', $channels ) ) ) ) ?: [ 'email', 'bell' ],
            'enabled'       => get_post_meta( $post->ID, '_optee_alert_enabled', true ) === '1',
            'last_fired_at' => get_post_meta( $post->ID, '_optee_alert_last_fired_at', true ) ?: null,
        ];

        if ( $type === 'report' ) {
            $cid        = (int) get_post_meta( $post->ID, '_optee_alert_campaign_id', true );
            $camp       = $cid ? get_post( $cid ) : null;
            $conditions = get_post_meta( $post->ID, '_optee_alert_conditions', true ) ?: [];
            return array_merge( $base, [
                'campaign_id'       => $cid,
                'campaign_name'     => $camp ? html_entity_decode( $camp->post_title, ENT_QUOTES, 'UTF-8' ) : '(deleted)',
                'conditions'        => $conditions,
                'condition_summary' => self::alert_condition_summary( $conditions, $cid ),
                'timeframe'         => get_post_meta( $post->ID, '_optee_alert_timeframe', true ) ?: 'yesterday',
                'schedule_time'     => get_post_meta( $post->ID, '_optee_alert_schedule_time', true ) ?: '06:00',
            ] );
        }

        // Balance alert.
        $module_type = get_post_meta( $post->ID, '_optee_alert_module_type', true );
        $module_slug = get_post_meta( $post->ID, '_optee_alert_module_slug', true );
        $module      = $module_slug ? ModuleRegistry::get_instance()->get_module( $module_slug, $module_type ) : null;
        return array_merge( $base, [
            'module_type'  => $module_type,
            'module_slug'  => $module_slug,
            'module_name'  => $module ? $module->get_name() : $module_slug,
            'module_label' => '[' . self::alert_type_label( (string) $module_type ) . '] ' . ( $module ? $module->get_name() : $module_slug ),
            'direction'    => get_post_meta( $post->ID, '_optee_alert_direction', true ) ?: 'below',
            'threshold'    => (float) get_post_meta( $post->ID, '_optee_alert_threshold', true ),
            'last_value'   => get_post_meta( $post->ID, '_optee_alert_last_value', true ),
        ] );
    }

    private static function format_rule( \WP_Post $post ): array {
        $campaign_id = get_post_meta( $post->ID, '_optee_rule_campaign_id', true );
        $campaign    = $campaign_id ? get_post( $campaign_id ) : null;

        // Snooze state — remaining seconds until automation resumes for this rule.
        // Stored value is a WP-local timestamp (current_time('timestamp') + duration),
        // so compare against current_time('timestamp') to stay TZ-consistent.
        $snooze_until     = (int) get_post_meta( $post->ID, '_optee_rule_snooze_until', true );
        $snooze_remaining = $snooze_until > current_time( 'timestamp' ) ? $snooze_until - current_time( 'timestamp' ) : 0;

        return [
            'id'              => $post->ID,
            'title'           => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
            'date'            => $post->post_date,
            'campaign_id'     => (int) $campaign_id,
            'campaign_name'   => $campaign ? html_entity_decode( $campaign->post_title, ENT_QUOTES, 'UTF-8' ) : '(deleted)',
            'action'          => get_post_meta( $post->ID, '_optee_rule_action', true ),
            'action_params'   => get_post_meta( $post->ID, '_optee_rule_action_params', true ) ?: [],
            'conditions'      => get_post_meta( $post->ID, '_optee_rule_conditions', true ) ?: [],
            'interval'        => (int) get_post_meta( $post->ID, '_optee_rule_interval', true ),
            'timeframe'       => get_post_meta( $post->ID, '_optee_rule_timeframe', true ) ?: '7d',
            'last_run'        => get_post_meta( $post->ID, '_optee_rule_last_run', true ),
            'enabled'         => get_post_meta( $post->ID, '_optee_rule_enabled', true ) === '1',
            'on_success'      => get_post_meta( $post->ID, '_optee_rule_on_success', true ) ?: [],
            'is_template'     => get_post_meta( $post->ID, '_optee_rule_is_template', true ) === '1',
            'last_run_result' => get_post_meta( $post->ID, '_optee_rule_last_run_result', true ) ?: null,
            'snoozed'         => $snooze_remaining > 0,
            'snooze_remaining' => $snooze_remaining,
            'vt'              => self::rule_vt_badges( $post ),
        ];
    }

    /**
     * VirusTotal badges for a rule's own conditions, so the rules list can show the
     * live score next to a rule whose whole job is watching that domain.
     *
     * Scored against the CAMPAIGN's owner, matching how the rule engine resolves the
     * watch list — a rule read by anyone else must not silently score against an
     * empty list. Disabled rules still get a badge: their score is exactly what you
     * need before deciding to switch them back on.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rule_vt_badges( \WP_Post $post ): array {
        $conditions = get_post_meta( $post->ID, '_optee_rule_conditions', true ) ?: [];
        if ( ! is_array( $conditions ) ) {
            return [];
        }

        $campaign_id = (int) get_post_meta( $post->ID, '_optee_rule_campaign_id', true );
        $owner_id    = $campaign_id ? (int) get_post_field( 'post_author', $campaign_id ) : (int) $post->post_author;
        if ( ! $owner_id ) {
            return [];
        }

        $out  = [];
        $seen = [];

        foreach ( $conditions as $c ) {
            if ( ( $c['source'] ?? '' ) !== 'virustotal' ) {
                continue;
            }
            $url = (string) ( $c['target'] ?? '' );
            if ( $url === '' || isset( $seen[ $url ] ) ) {
                continue;
            }
            $seen[ $url ] = true;

            $badge          = \Optee\Plugin\Rules\RuleCondition::vt_badge_for( $owner_id, $url );
            $badge['label'] = \Optee\Plugin\Rules\RuleCondition::vt_badge_label( $badge );
            $badge['title'] = \Optee\Plugin\Rules\RuleCondition::vt_badge_title( $badge );
            $badge['style'] = \Optee\Plugin\Rules\RuleCondition::vt_badge_style( $badge['state'] );
            $out[]          = $badge;
        }

        return $out;
    }

    public function get_rule_templates(): void {
        $this->verify();
        $user_id = get_current_user_id();

        // Get all template rules + all rules from other campaigns (for import).
        $rules = get_posts( [
            'post_type'      => 'optee_rule',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $templates = [];
        foreach ( $rules as $post ) {
            $formatted = self::format_rule( $post );
            $formatted['label'] = $formatted['title'] . ' (' . $formatted['campaign_name'] . ')';
            if ( $formatted['is_template'] ) {
                $formatted['label'] = '[Template] ' . $formatted['label'];
            }
            $templates[] = $formatted;
        }

        wp_send_json_success( [ 'templates' => $templates ] );
    }

    /**
     * Sanitize a decoded conditions array (from the rule form JSON) into the stored
     * shape. Shared by rule create/update and the Dry Run preview.
     */
    public static function sanitize_rule_conditions( array $decoded ): array {
        $valid_operators = [ '>', '>=', '<', '<=', '==', '!=' ];
        return array_map( function ( $c ) use ( $valid_operators ) {
            $source = sanitize_text_field( $c['source'] ?? 'traffic_source' );
            $op     = $c['operator'] ?? '>';
            if ( $source === 'affiliate_network' && ( $c['metric'] ?? '' ) === 'offer_status' ) {
                $val = sanitize_text_field( $c['value'] ?? 'running' );
                if ( ! in_array( $val, [ 'running', 'paused' ], true ) ) {
                    $val = 'running';
                }
                return [ 'source' => $source, 'metric' => 'offer_status', 'operator' => '==', 'value' => $val ];
            }
            // VirusTotal conditions carry a target: which monitored URL to score.
            if ( $source === 'virustotal' ) {
                $metric = sanitize_text_field( $c['metric'] ?? '' );
                if ( ! in_array( $metric, \Optee\Plugin\Rules\RuleCondition::VT_METRICS, true ) ) {
                    $metric = 'vt_flagged';
                }
                return [
                    'source'   => $source,
                    'metric'   => $metric,
                    'operator' => in_array( $op, $valid_operators, true ) ? $op : '>',
                    'value'    => floatval( $c['value'] ?? 0 ),
                    'target'   => esc_url_raw( $c['target'] ?? '' ),
                ];
            }
            return [
                'source'   => $source,
                'metric'   => sanitize_text_field( $c['metric'] ?? '' ),
                'operator' => in_array( $op, $valid_operators, true ) ? $op : '>',
                'value'    => floatval( $c['value'] ?? 0 ),
            ];
        }, $decoded );
    }

    /**
     * Actions a rule may take when any condition is VirusTotal-sourced. A domain
     * verdict says nothing about an individual zone, country, or targeting value,
     * so only the campaign-level actions are coherent.
     */
    private static function rule_has_vt_condition( array $conditions ): bool {
        foreach ( $conditions as $c ) {
            if ( ( $c['source'] ?? '' ) === 'virustotal' ) {
                return true;
            }
        }
        return false;
    }

    /** Reject VT rules wired to a scope the verdict can't address. Returns an error string or ''. */
    private static function validate_vt_rule( array $conditions, string $action ): string {
        if ( ! self::rule_has_vt_condition( $conditions ) ) {
            return '';
        }
        if ( ! in_array( $action, [ 'pause_traffic', 'resume_traffic', 'pause_campaign', 'start_campaign' ], true ) ) {
            return 'VirusTotal conditions apply to the whole domain, so they can only drive Pause Traffic or Resume Traffic.';
        }
        foreach ( $conditions as $c ) {
            if ( ( $c['source'] ?? '' ) === 'virustotal' && empty( $c['target'] ) ) {
                return 'Select which monitored domain the VirusTotal condition should check.';
            }
        }
        return '';
    }

    public function preview_rule(): void {
        $this->verify();

        $rule_id = intval( $_POST['rule_id'] ?? 0 );
        $post    = get_post( $rule_id );

        if ( ! $post || $post->post_type !== 'optee_rule' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Rule not found.' ] );
        }

        $this->render_rule_preview(
            (int) get_post_meta( $rule_id, '_optee_rule_campaign_id', true ),
            get_post_meta( $rule_id, '_optee_rule_action', true ),
            get_post_meta( $rule_id, '_optee_rule_conditions', true ) ?: [],
            get_post_meta( $rule_id, '_optee_rule_timeframe', true ) ?: '7d',
            get_post_meta( $rule_id, '_optee_rule_action_params', true ) ?: [],
            html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' )
        );
    }

    /**
     * Dry-run an UNSAVED rule straight from the create/edit form. Same evaluation as
     * preview_rule, but the parameters come from the posted form and nothing is
     * stored — a pure read-only preview.
     */
    public function preview_rule_draft(): void {
        $this->verify();

        $campaign_id = intval( $_POST['campaign_id'] ?? 0 );
        $campaign    = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
        }

        $action    = sanitize_text_field( $_POST['rule_action'] ?? '' );
        $timeframe = sanitize_text_field( $_POST['timeframe'] ?? '7d' );
        $rule_name = sanitize_text_field( $_POST['title'] ?? '' );
        $rule_name = $rule_name !== '' ? $rule_name : '(unsaved rule)';

        $conditions_raw = json_decode( wp_unslash( $_POST['conditions'] ?? '[]' ), true );
        $params_raw     = json_decode( wp_unslash( $_POST['action_params'] ?? '[]' ), true );
        $conditions     = self::sanitize_rule_conditions( is_array( $conditions_raw ) ? $conditions_raw : [] );
        $action_params  = is_array( $params_raw ) ? array_map( 'sanitize_text_field', $params_raw ) : [];

        if ( empty( $conditions ) ) {
            wp_send_json_error( [ 'message' => 'Add at least one condition to preview.' ] );
        }

        $this->render_rule_preview( $campaign_id, $action, $conditions, $timeframe, $action_params, $rule_name );
    }

    /**
     * Shared rule-preview evaluator (saved-rule Preview + form Dry Run). Evaluates
     * conditions and sends the preview payload; performs NO writes.
     */
    private function render_rule_preview( int $campaign_id, string $action, array $conditions, string $timeframe, array $action_params, string $rule_name ): void {
        $campaign = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        Logger::log( 'info', 'rule', "Rule dry-run: \"{$rule_name}\" on \"{$campaign->post_title}\" (action: {$action}, timeframe: {$timeframe})", [
            'campaign_id' => $campaign_id,
        ] );

        // Offer status rule preview.
        $is_offer_rule = ! empty( $conditions ) && $conditions[0]['source'] === 'affiliate_network' && $conditions[0]['metric'] === 'offer_status';
        if ( $is_offer_rule ) {
            $an_slug  = get_post_meta( $campaign_id, '_optee_affiliate_network_module', true );
            $offer_id = get_post_meta( $campaign_id, '_optee_offer_id', true );

            if ( ! $an_slug || ! $offer_id ) {
                wp_send_json_error( [ 'message' => 'Campaign has no affiliate network or offer ID configured.' ] );
            }

            $registry  = ModuleRegistry::get_instance();
            $an_module = $registry->get_module_with_credentials( $an_slug );

            if ( ! $an_module || ! method_exists( $an_module, 'get_offer_status' ) ) {
                wp_send_json_error( [ 'message' => 'Affiliate network module not found or does not support offer status checks.' ] );
            }

            $offer_info = $an_module->get_offer_status( $offer_id );
            $offer_status_str = $offer_info['active'] ? 'active' : ( $offer_info['status'] ?? 'paused' );

            // Map condition value to actual status for comparison.
            // Condition value "running" matches offer status "active", "paused" matches any non-active.
            $condition_value = $conditions[0]['value'] ?? 'running';
            $actual_matches_running = $offer_info['active'];
            $passed = ( $condition_value === 'running' ) ? $actual_matches_running : ! $actual_matches_running;

            wp_send_json_success( [
                'type'         => 'offer_status',
                'rule_name'    => $rule_name,
                'action'       => $action,
                'conditions'   => $conditions,
                'offer_name'   => $offer_id,
                'offer_status' => $offer_status_str,
                'passed'       => $passed,
            ] );
        }

        $is_zone_action = in_array( $action, [ 'block_zone', 'unblock_zone', 'block_zones', 'unblock_zones' ], true );

        if ( $is_zone_action && self::campaign_is_whitelist( $campaign_id ) ) {
            wp_send_json_error( [ 'message' => 'Zone block/unblock actions cannot be used with whitelist campaigns. Use Pause/Resume Traffic instead.' ] );
        }

        if ( $is_zone_action ) {
            $eval = \Optee\Plugin\Rules\RuleCondition::evaluate_zones( $conditions, $campaign_id, $timeframe );

            $zone_results = [];
            foreach ( $eval['all_evaluations'] as $ze ) {
                $zone_results[] = [
                    'zone_id' => $ze['zone_id'],
                    'passed'  => $ze['passed'],
                    'details' => $ze['details'],
                ];
            }

            // Fetch currently blocked zones to show diff in preview.
            $already_blocked = [];
            $ts_module_slug  = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
            $ts_campaign_id  = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );
            if ( $ts_module_slug && $ts_campaign_id ) {
                $registry = ModuleRegistry::get_instance();
                $module   = $registry->get_module_with_credentials( $ts_module_slug );
                if ( $module && method_exists( $module, 'get_blocked_zones' ) ) {
                    try {
                        $already_blocked = array_map( 'strval', $module->get_blocked_zones( $ts_campaign_id ) );
                    } catch ( \Throwable $e ) {
                        // Ignore — preview still works without blocked zone info.
                    }
                }
            }

            wp_send_json_success( [
                'type'                => 'zone',
                'rule_name'           => $rule_name,
                'unit'                => 'zone',
                'action'              => $action,
                'timeframe'           => $timeframe,
                'conditions'          => $conditions,
                'total_zones'         => count( $eval['all_evaluations'] ),
                'matching_zones'      => $eval['matching_zones'],
                'zone_results'        => $zone_results,
                'blocked_zones'       => $already_blocked,
                'has_traffic_source'  => ( $ts_module_slug && $ts_campaign_id ),
                'errors'              => $eval['errors'] ?? [],
            ] );
        }

        // Per-country action (remove_countries) and per-dimension targeting action
        // (remove_targeting) evaluate each row (country / OS / category / …) on its
        // OWN stats — same as zone rules, not the campaign aggregate. Reuse the zone
        // renderer with a `unit` label so the preview reflects what actually runs.
        if ( $action === 'remove_countries' ) {
            $eval = \Optee\Plugin\Rules\RuleCondition::evaluate_countries( $conditions, $campaign_id, $timeframe );

            $row_results = [];
            foreach ( $eval['all_evaluations'] as $ce ) {
                $label = ( $ce['country_name'] ?? '' ) && $ce['country_name'] !== $ce['zone_id']
                    ? $ce['country_name'] . ' (' . $ce['zone_id'] . ')'
                    : $ce['zone_id'];
                $row_results[] = [
                    'zone_id' => $label,
                    'passed'  => $ce['passed'],
                    'details' => $ce['details'],
                ];
            }

            wp_send_json_success( [
                'type'           => 'zone',
                'rule_name'      => $rule_name,
                'unit'           => 'country',
                'action'         => $action,
                'timeframe'      => $timeframe,
                'conditions'     => $conditions,
                'total_zones'    => count( $eval['all_evaluations'] ),
                'matching_zones' => $eval['matching_zones'],
                'zone_results'   => $row_results,
                'blocked_zones'  => [],
                'errors'         => $eval['errors'] ?? [],
            ] );
        }

        if ( $action === 'remove_targeting' ) {
            $dim  = $action_params['dimension'] ?? '';
            $eval = \Optee\Plugin\Rules\RuleCondition::evaluate_targeting( $conditions, $campaign_id, $timeframe, $dim );

            // For id-token dims (Category), the per-value key is the raw PopAds id.
            // Resolve it to "Name (id)" so the preview reads like the report, not "6".
            $label_map = [];
            $annotate  = self::token_dim_registry()[ $dim ]['annotate'] ?? '';
            if ( $annotate === 'id' ) {
                $ts_slug = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
                $ts_mod  = $ts_slug ? ModuleRegistry::get_instance()->get_module_with_credentials( $ts_slug ) : null;
                if ( $ts_mod && method_exists( $ts_mod, 'describe_ids' ) ) {
                    try {
                        $vals  = array_map( static fn( $e ) => (string) ( $e['zone_id'] ?? '' ), $eval['all_evaluations'] );
                        $names = $ts_mod->describe_ids( $dim, $vals );
                        foreach ( $vals as $v ) {
                            $nm             = $names[ $v ] ?? '';
                            $label_map[ $v ] = ( $nm !== '' ) ? ( $nm . ' (' . $v . ')' ) : $v;
                        }
                    } catch ( \Throwable $e ) {
                        // best-effort — fall back to raw ids.
                    }
                }
            }

            $row_results = [];
            foreach ( $eval['all_evaluations'] as $ve ) {
                $vid = (string) ( $ve['zone_id'] ?? '' );
                $row_results[] = [
                    'zone_id' => $label_map[ $vid ] ?? $ve['zone_id'],
                    'passed'  => $ve['passed'],
                    'details' => $ve['details'],
                ];
            }

            wp_send_json_success( [
                'type'           => 'zone',
                'rule_name'      => $rule_name,
                'unit'           => $dim ? str_replace( '_', ' ', $dim ) : 'value',
                'dimension'      => $dim,
                'action'         => $action,
                'timeframe'      => $timeframe,
                'conditions'     => $conditions,
                'total_zones'    => count( $eval['all_evaluations'] ),
                'matching_zones' => $eval['matching'],
                'zone_results'   => $row_results,
                'blocked_zones'  => [],
                'errors'         => $eval['errors'] ?? [],
            ] );
        }

        $eval = \Optee\Plugin\Rules\RuleCondition::evaluate_campaign( $conditions, $campaign_id, $timeframe );

        wp_send_json_success( [
            'type'       => 'campaign',
            'rule_name'  => $rule_name,
            'action'     => $action,
            'timeframe'  => $timeframe,
            'conditions' => $conditions,
            'passed'     => $eval['passed'],
            'details'    => $eval['details'],
        ] );
    }

    /**
     * Run a rule immediately (manual trigger).
     *
     * POST params: rule_id
     */
    public function run_rule(): void {
        $this->verify();

        $rule_id = intval( $_POST['rule_id'] ?? 0 );
        $post    = get_post( $rule_id );

        if ( ! $post || $post->post_type !== 'optee_rule' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Rule not found.' ] );
        }

        $campaign_id = (int) get_post_meta( $rule_id, '_optee_rule_campaign_id', true );
        $campaign    = get_post( $campaign_id );
        $camp_title  = $campaign ? $campaign->post_title : "#{$campaign_id}";
        $rule_action = get_post_meta( $rule_id, '_optee_rule_action', true ) ?: '?';

        Logger::log( 'info', 'rule', "Manual Run Rule: \"{$post->post_title}\" on \"{$camp_title}\" (action: {$rule_action})", [
            'rule_id'     => $rule_id,
            'campaign_id' => $campaign_id,
        ] );

        try {
            \Optee\Plugin\Rules\RuleEngine::process_rule( $post );

            $result = get_post_meta( $rule_id, '_optee_rule_last_run_result', true );
            $message = 'Rule executed.';
            if ( is_array( $result ) && ! empty( $result['message'] ) ) {
                $message = $result['message'];
            }

            $action     = get_post_meta( $rule_id, '_optee_rule_action', true );
            $timeframe  = get_post_meta( $rule_id, '_optee_rule_timeframe', true ) ?: '7d';
            $conditions = get_post_meta( $rule_id, '_optee_rule_conditions', true ) ?: [];

            wp_send_json_success( [
                'message'       => $message,
                'result'        => is_array( $result ) ? $result : [],
                'rule_name'     => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
                'action'        => $action,
                'action_params' => get_post_meta( $rule_id, '_optee_rule_action_params', true ) ?: [],
                'timeframe'     => $timeframe,
                'conditions'    => $conditions,
            ] );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'rule', 'Rule execution failed: ' . $e->getMessage(), [
                'rule_id' => $rule_id,
                'trace'   => $e->getTraceAsString(),
            ] );
            wp_send_json_error( [ 'message' => 'Rule execution failed. Check the logs for details.' ] );
        }
    }

    /**
     * Toggle a zone's blocked status on the traffic source.
     *
     * POST params: campaign_id, zone_id, zone_action (block|unblock)
     */
    public function toggle_zone(): void {
        $this->verify();

        $campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
        $zone_id     = sanitize_text_field( $_POST['zone_id'] ?? '' );
        $action      = sanitize_text_field( $_POST['zone_action'] ?? '' );

        if ( ! $campaign_id || ! $zone_id || ! in_array( $action, [ 'block', 'unblock' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Missing or invalid parameters.' ] );
        }

        // Verify ownership.
        $post = get_post( $campaign_id );
        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $ts_slug        = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        if ( ! $ts_slug || ! $ts_campaign_id ) {
            wp_send_json_error( [ 'message' => 'No traffic source linked to this campaign.' ] );
        }

        // Grouped campaign: manual zone blocking must target one creative. The report's
        // member dropdown sends ts_campaign_member; "All campaigns" sends none and is blocked here.
        if ( strpos( (string) $ts_campaign_id, ',' ) !== false ) {
            $ts_member = sanitize_text_field( $_POST['ts_campaign_member'] ?? '' );
            $members   = array_map( 'trim', explode( ',', $ts_campaign_id ) );
            if ( $ts_member === '' || ! in_array( $ts_member, $members, true ) ) {
                wp_send_json_error( [ 'message' => 'Select a single campaign first to block zones.' ] );
            }
            $ts_campaign_id = $ts_member;
        }

        $registry  = \Optee\Plugin\ModuleRegistry::get_instance();
        $ts_module = $registry->get_module( $ts_slug );

        if ( ! $ts_module ) {
            wp_send_json_error( [ 'message' => 'Traffic source module not found.' ] );
        }

        $ts_module->set_credentials(
            ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: []
        );

        try {
            if ( $action === 'block' ) {
                $result = $ts_module->block_zone( $ts_campaign_id, $zone_id );
            } else {
                $result = $ts_module->unblock_zone( $ts_campaign_id, $zone_id );
            }
        } catch ( \RuntimeException $e ) {
            // RuntimeException messages are user-facing (e.g., whitelist warnings).
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'campaign', 'Zone toggle failed: ' . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Zone operation failed. Check the logs for details.' ] );
        }

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => 'Traffic source returned an error.' ] );
        }

        // Determine if this is a whitelist campaign for better log labels.
        $is_whitelist = false;
        if ( method_exists( $ts_module, 'get_whitelisted_zones' ) ) {
            $wl = $ts_module->get_whitelisted_zones( $ts_campaign_id );
            if ( ! empty( $wl ) || sanitize_text_field( $_POST['is_whitelist'] ?? '' ) === '1' ) {
                $is_whitelist = true;
            }
        }

        if ( $is_whitelist ) {
            $label = $action === 'block' ? 'Zone removed from whitelist' : 'Zone added to whitelist';
        } else {
            $label = $action === 'block' ? 'Zone blocked' : 'Zone unblocked';
        }

        Logger::log( 'info', 'campaign', "{$label}: {$zone_id} on {$post->post_title}", [
            'campaign_id'    => $campaign_id,
            'zone_id'        => $zone_id,
            'action'         => $action,
            'traffic_source' => $ts_slug,
            'is_whitelist'   => $is_whitelist,
        ] );

        wp_send_json_success( [
            'message' => $label . '.',
            'zone_id' => $zone_id,
            'action'  => $action,
        ] );
    }

    /**
     * Toggle a country's targeting status on the traffic source (geo report Active
     * column). Mirrors toggle_zone but acts on country targeting: 'remove' strips a
     * country from targeting (disable), 'add' puts it back (re-enable).
     *
     * POST params: campaign_id, country_code, country_action (remove|add)
     */
    public function toggle_country(): void {
        $this->verify();

        $campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
        $code        = strtoupper( sanitize_text_field( $_POST['country_code'] ?? '' ) );
        $action      = sanitize_text_field( $_POST['country_action'] ?? '' );

        if ( ! $campaign_id || $code === '' || ! in_array( $action, [ 'remove', 'add' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Missing or invalid parameters.' ] );
        }

        $post = get_post( $campaign_id );
        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $ts_slug        = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        if ( ! $ts_slug || ! $ts_campaign_id ) {
            wp_send_json_error( [ 'message' => 'No traffic source linked to this campaign.' ] );
        }
        if ( strpos( (string) $ts_campaign_id, ',' ) !== false ) {
            wp_send_json_error( [ 'message' => 'Select a single campaign first to edit country targeting.' ] );
        }

        $registry  = \Optee\Plugin\ModuleRegistry::get_instance();
        $ts_module = $registry->get_module( $ts_slug );
        if ( ! $ts_module || ! method_exists( $ts_module, 'remove_countries' ) || ! method_exists( $ts_module, 'add_countries' ) ) {
            wp_send_json_error( [ 'message' => 'This traffic source does not support per-country targeting.' ] );
        }

        $ts_module->set_credentials(
            ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: []
        );

        try {
            $result = $action === 'remove'
                ? $ts_module->remove_countries( $ts_campaign_id, [ $code ] )
                : $ts_module->add_countries( $ts_campaign_id, [ $code ] );
        } catch ( \RuntimeException $e ) {
            // User-facing messages (RON / last-country guards).
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'campaign', 'Country toggle failed: ' . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Country operation failed. Check the logs for details.' ] );
        }

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['message'] ?? 'Traffic source returned an error.' ] );
        }

        // Fresh targeted list so the UI stays in sync (also reflects no-op cases).
        $targeted = [];
        try {
            $targeted = array_map( 'strval', $ts_module->get_targeted_countries( $ts_campaign_id ) );
        } catch ( \Throwable $e ) {
            // Non-fatal.
        }

        $label = $action === 'remove' ? 'Country disabled' : 'Country enabled';
        Logger::log( 'info', 'campaign', "{$label}: {$code} on {$post->post_title}", [
            'campaign_id'    => $campaign_id,
            'country'        => $code,
            'action'         => $action,
            'traffic_source' => $ts_slug,
        ] );

        wp_send_json_success( [
            'message'            => $result['message'] ?? ( $label . '.' ),
            'country_code'       => $code,
            'action'             => $action,
            'targeted_countries' => $targeted,
        ] );
    }

    /**
     * Toggle one targeting value (OS / browser / category / …) on the traffic
     * source, from the dimension report's Active column. 'remove' strips the value
     * from targeting (disable), 'add' puts it back (re-enable). Mirrors
     * toggle_country but keyed by a targeting dimension + value (an ID for id-dims,
     * a string for ISP/resolution).
     *
     * POST params: campaign_id, dimension, value, targeting_action (remove|add)
     */
    public function toggle_targeting(): void {
        $this->verify();

        $campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
        $dimension   = sanitize_text_field( $_POST['dimension'] ?? '' );
        $value       = sanitize_text_field( $_POST['value'] ?? '' );
        $action      = sanitize_text_field( $_POST['targeting_action'] ?? '' );

        if ( ! $campaign_id || $dimension === '' || $value === '' || ! in_array( $action, [ 'remove', 'add' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Missing or invalid parameters.' ] );
        }

        $post = get_post( $campaign_id );
        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $ts_slug        = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        if ( ! $ts_slug || ! $ts_campaign_id ) {
            wp_send_json_error( [ 'message' => 'No traffic source linked to this campaign.' ] );
        }
        if ( strpos( (string) $ts_campaign_id, ',' ) !== false ) {
            wp_send_json_error( [ 'message' => 'Select a single campaign first to edit targeting.' ] );
        }

        $registry  = \Optee\Plugin\ModuleRegistry::get_instance();
        $ts_module = $registry->get_module( $ts_slug );
        if ( ! $ts_module || ! method_exists( $ts_module, 'remove_targeting' ) || ! method_exists( $ts_module, 'add_targeting' ) ) {
            wp_send_json_error( [ 'message' => 'This traffic source does not support per-value targeting.' ] );
        }

        $ts_module->set_credentials(
            ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: []
        );

        try {
            $result = $action === 'remove'
                ? $ts_module->remove_targeting( $ts_campaign_id, $dimension, [ $value ] )
                : $ts_module->add_targeting( $ts_campaign_id, $dimension, [ $value ] );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        } catch ( \Throwable $e ) {
            Logger::log( 'error', 'campaign', 'Targeting toggle failed: ' . $e->getMessage() );
            wp_send_json_error( [ 'message' => 'Targeting operation failed. Check the logs for details.' ] );
        }

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['message'] ?? 'Traffic source returned an error.' ] );
        }

        $targeted = [];
        try {
            if ( method_exists( $ts_module, 'get_targeting_values' ) ) {
                $targeted = array_map( 'strval', $ts_module->get_targeting_values( $ts_campaign_id, $dimension ) );
            }
        } catch ( \Throwable $e ) {
            // Non-fatal.
        }

        $label = $action === 'remove' ? 'Targeting disabled' : 'Targeting enabled';
        Logger::log( 'info', 'campaign', "{$label}: {$dimension}={$value} on {$post->post_title}", [
            'campaign_id'    => $campaign_id,
            'dimension'      => $dimension,
            'value'          => $value,
            'action'         => $action,
            'traffic_source' => $ts_slug,
        ] );

        wp_send_json_success( [
            'message'            => $result['message'] ?? ( $label . '.' ),
            'dimension'          => $dimension,
            'value'              => $value,
            'action'             => $action,
            'targeted_targeting' => $targeted,
        ] );
    }

    /**
     * Toggle campaign status (start/pause) on the traffic source.
     */
    public function toggle_campaign_status(): void {
        $this->verify();

        $campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
        $action      = sanitize_text_field( $_POST['status_action'] ?? '' );

        if ( ! $campaign_id || ! in_array( $action, [ 'start', 'pause' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Missing or invalid parameters.' ] );
        }

        $post = get_post( $campaign_id );
        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $ts_slug        = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        if ( ! $ts_slug || ! $ts_campaign_id ) {
            wp_send_json_error( [ 'message' => 'No traffic source linked to this campaign.' ] );
        }

        $registry  = ModuleRegistry::get_instance();
        $ts_module = $registry->get_module( $ts_slug );

        if ( ! $ts_module ) {
            wp_send_json_error( [ 'message' => 'Traffic source module not found.' ] );
        }

        $ts_module->set_credentials(
            ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: []
        );

        try {
            if ( $action === 'pause' ) {
                $result = $ts_module->pause_campaign( $ts_campaign_id );
            } else {
                $result = $ts_module->start_campaign( $ts_campaign_id );
            }
        } catch ( \Throwable $e ) {
            $msg = $e->getMessage();
            Logger::log( 'error', 'campaign', "Campaign {$action} failed: {$msg}", [ 'campaign_id' => $campaign_id ] );

            // Human-readable messages for common API errors.
            if ( stripos( $msg, 'unable to start' ) !== false || stripos( $msg, 'current state' ) !== false ) {
                $msg = 'Cannot start this campaign — it may have been stopped by the traffic source (e.g. budget limit reached). Check the campaign on the traffic source dashboard.';
            } else {
                $msg = 'Traffic source error. Check the logs for details.';
            }

            wp_send_json_error( [ 'message' => $msg ] );
        }

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => 'Traffic source returned an error.' ] );
        }

        // Verify the new status.
        $new_status = '';
        try {
            $new_status = $ts_module->get_campaign_status( $ts_campaign_id );
        } catch ( \Throwable $e ) {
            // Ignore — return success anyway since the action itself succeeded.
        }

        $label = $action === 'pause' ? 'Campaign paused' : 'Campaign started';

        Logger::log( 'info', 'campaign', "{$label}: {$post->post_title}", [
            'campaign_id'    => $campaign_id,
            'action'         => $action,
            'traffic_source' => $ts_slug,
            'new_status'     => $new_status,
        ] );

        wp_send_json_success( [
            'message'         => $label . '.',
            'campaign_status' => $new_status,
        ] );
    }

    /**
     * Batch-fetch traffic source campaign statuses.
     *
     * POST params: campaign_ids (array of local campaign IDs)
     * Groups by traffic source, calls get_campaigns() once per TS,
     * returns { campaign_id => status } map.
     */
    public function get_ts_statuses(): void {
        $this->verify();

        $campaign_ids = isset( $_POST['campaign_ids'] ) ? array_map( 'intval', (array) $_POST['campaign_ids'] ) : [];
        if ( empty( $campaign_ids ) ) {
            wp_send_json_success( [ 'statuses' => [] ] );
        }

        $user_id  = get_current_user_id();
        $registry = ModuleRegistry::get_instance();

        // Group local campaigns by traffic source slug.
        $ts_groups = []; // ts_slug => [ [ 'local_id' => int, 'ts_campaign_id' => string ], ... ]
        foreach ( $campaign_ids as $cid ) {
            $post = get_post( $cid );
            if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== $user_id ) {
                continue;
            }
            $ts_slug        = get_post_meta( $cid, '_optee_traffic_source_module', true );
            $ts_campaign_id = get_post_meta( $cid, '_optee_traffic_source_campaign_id', true );
            if ( ! $ts_slug || ! $ts_campaign_id ) {
                continue;
            }
            $ts_groups[ $ts_slug ][] = [
                'local_id'       => $cid,
                'ts_campaign_id' => $ts_campaign_id,
            ];
        }

        $statuses = []; // local_campaign_id => status string

        foreach ( $ts_groups as $ts_slug => $entries ) {
            $module = $registry->get_module( $ts_slug );
            if ( ! $module || ! method_exists( $module, 'get_campaigns' ) ) {
                continue;
            }
            $module->set_credentials(
                ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: []
            );

            try {
                $ts_campaigns = $module->get_campaigns();

                // Build lookup: remote campaign ID → status.
                $status_map = [];
                foreach ( $ts_campaigns as $tc ) {
                    $arr = is_object( $tc ) && method_exists( $tc, 'to_array' ) ? $tc->to_array() : (array) $tc;
                    $remote_id = (string) ( $arr['campaign_id'] ?? '' );
                    $status    = strtolower( $arr['status'] ?? 'unknown' );
                    if ( $remote_id !== '' ) {
                        $status_map[ $remote_id ] = $status;
                    }
                }

                // Match to local campaigns.
                foreach ( $entries as $entry ) {
                    $rid = (string) $entry['ts_campaign_id'];

                    // Grouped campaign (comma-separated members): aggregate by priority.
                    if ( strpos( $rid, ',' ) !== false ) {
                        $member_statuses = [];
                        foreach ( array_map( 'trim', explode( ',', $rid ) ) as $mid ) {
                            if ( isset( $status_map[ $mid ] ) ) {
                                $member_statuses[] = $status_map[ $mid ];
                            }
                        }
                        $agg = 'unknown';
                        foreach ( [ 'active', 'paused', 'pending', 'rejected' ] as $priority ) {
                            if ( in_array( $priority, $member_statuses, true ) ) {
                                $agg = $priority;
                                break;
                            }
                        }
                        $statuses[ $entry['local_id'] ] = $agg;
                        continue;
                    }

                    $statuses[ $entry['local_id'] ] = $status_map[ $rid ] ?? 'unknown';
                }
            } catch ( \Throwable $e ) {
                // On error, mark all campaigns for this TS as unknown.
                foreach ( $entries as $entry ) {
                    $statuses[ $entry['local_id'] ] = 'error';
                }
            }
        }

        wp_send_json_success( [ 'statuses' => $statuses ] );
    }

    // ──────────────────────────────────────────
    // Campaign Notes
    // ──────────────────────────────────────────

    /**
     * Note counts for one dimension, keyed by value: [ '2225307' => 2, ... ].
     * Read straight from the campaign's note blob — no extra query.
     */
    private static function note_counts_for( int $campaign_id, string $dim ): array {
        $notes = json_decode( get_post_meta( $campaign_id, '_optee_notes', true ) ?: '[]', true );
        if ( ! is_array( $notes ) ) {
            return [];
        }

        $counts = [];
        foreach ( $notes as $n ) {
            if ( ( $n['dim'] ?? '' ) !== $dim ) {
                continue;
            }
            $value = (string) ( $n['value'] ?? '' );
            if ( $value === '' ) {
                continue;
            }
            $counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
        }

        return $counts;
    }

    public function get_notes(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        if ( ! $campaign_id || get_post_type( $campaign_id ) !== 'optee_campaign' ) {
            wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
        }
        if ( (int) get_post_field( 'post_author', $campaign_id ) !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Access denied.' ] );
        }

        $notes = json_decode( get_post_meta( $campaign_id, '_optee_notes', true ) ?: '[]', true );
        $notes = is_array( $notes ) ? $notes : [];

        $sort = fn( array &$list ) => usort( $list, fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) ?: $b['id'] <=> $a['id'] );

        // Scoped read: a specific dimension value (e.g. dim=zone, value=2225307).
        $dim   = sanitize_text_field( $_POST['dim'] ?? '' );
        $value = sanitize_text_field( $_POST['value'] ?? '' );

        if ( $dim !== '' && $value !== '' ) {
            $scoped = array_values( array_filter(
                $notes,
                fn( $n ) => ( $n['dim'] ?? '' ) === $dim && (string) ( $n['value'] ?? '' ) === $value
            ) );
            $sort( $scoped );
            wp_send_json_success( [ 'notes' => $scoped ] );
        }

        // Unscoped read: campaign-level notes, plus dimension notes grouped by value
        // so the Campaign Notes modal can show everything in one place.
        $campaign_level = [];
        $groups         = [];
        foreach ( $notes as $n ) {
            $n_dim = $n['dim'] ?? '';
            $n_val = (string) ( $n['value'] ?? '' );
            if ( $n_dim === '' || $n_val === '' ) {
                $campaign_level[] = $n;
                continue;
            }
            $key = $n_dim . ':' . $n_val;
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = [ 'dim' => $n_dim, 'value' => $n_val, 'notes' => [] ];
            }
            $groups[ $key ]['notes'][] = $n;
        }

        $sort( $campaign_level );
        foreach ( $groups as &$g ) {
            $sort( $g['notes'] );
        }
        unset( $g );

        // Most-annotated first, then by value for a stable order.
        $groups = array_values( $groups );
        usort( $groups, fn( $a, $b ) => count( $b['notes'] ) <=> count( $a['notes'] ) ?: strcmp( $a['value'], $b['value'] ) );

        wp_send_json_success( [
            'notes'  => $campaign_level,
            'groups' => $groups,
        ] );
    }

    public function save_note(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        if ( ! $campaign_id || get_post_type( $campaign_id ) !== 'optee_campaign' ) {
            wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
        }
        if ( (int) get_post_field( 'post_author', $campaign_id ) !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Access denied.' ] );
        }

        $text = sanitize_textarea_field( $_POST['text'] ?? '' );
        $date = sanitize_text_field( $_POST['date'] ?? '' );
        $note_id = absint( $_POST['note_id'] ?? 0 );

        // Optional dimension scope, e.g. dim=zone value=2225307. Absent = campaign-level.
        $dim   = substr( sanitize_text_field( $_POST['dim'] ?? '' ), 0, 32 );
        $value = substr( sanitize_text_field( $_POST['value'] ?? '' ), 0, 190 );
        $scoped = ( $dim !== '' && $value !== '' );

        if ( ! $text ) {
            wp_send_json_error( [ 'message' => 'Note text is required.' ] );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = current_time( 'Y-m-d' );
        }

        $notes = json_decode( get_post_meta( $campaign_id, '_optee_notes', true ) ?: '[]', true );
        $notes = is_array( $notes ) ? $notes : [];

        if ( $note_id ) {
            // Edit existing. The dimension scope is fixed at creation — editing text
            // must never silently move a note from one zone to another.
            foreach ( $notes as &$n ) {
                if ( $n['id'] === $note_id ) {
                    $n['text'] = $text;
                    $n['date'] = $date;
                    break;
                }
            }
            unset( $n );
        } else {
            // New note.
            $max_id = 0;
            foreach ( $notes as $n ) {
                if ( $n['id'] > $max_id ) {
                    $max_id = $n['id'];
                }
            }
            $new = [
                'id'   => $max_id + 1,
                'date' => $date,
                'text' => $text,
            ];
            if ( $scoped ) {
                $new['dim']   = $dim;
                $new['value'] = $value;
            }
            $notes[] = $new;
        }

        update_post_meta( $campaign_id, '_optee_notes', wp_json_encode( $notes ) );

        wp_send_json_success( [ 'message' => $note_id ? 'Note updated.' : 'Note added.' ] );
    }

    public function delete_note(): void {
        $this->verify();

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $note_id     = absint( $_POST['note_id'] ?? 0 );

        if ( ! $campaign_id || get_post_type( $campaign_id ) !== 'optee_campaign' ) {
            wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
        }
        if ( (int) get_post_field( 'post_author', $campaign_id ) !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Access denied.' ] );
        }

        $notes = json_decode( get_post_meta( $campaign_id, '_optee_notes', true ) ?: '[]', true );
        $notes = array_values( array_filter( $notes, fn( $n ) => $n['id'] !== $note_id ) );

        update_post_meta( $campaign_id, '_optee_notes', wp_json_encode( $notes ) );

        wp_send_json_success( [ 'message' => 'Note deleted.' ] );
    }

    // ──────────────────────────────────────────
    // Command Palette — Campaign Search
    // ──────────────────────────────────────────

    public function search_campaigns(): void {
        $this->verify();

        $campaigns = get_posts( [
            'post_type'      => 'optee_campaign',
            'post_status'    => 'any',
            'author'         => get_current_user_id(),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $results = [];
        foreach ( $campaigns as $campaign ) {
            $results[] = [
                'id'     => $campaign->ID,
                'title'  => $campaign->post_title,
                'status' => get_post_meta( $campaign->ID, '_optee_local_status', true ) ?: $campaign->post_status,
            ];
        }

        wp_send_json_success( $results );
    }

    /**
     * Check if a campaign uses whitelist targeting.
     *
     * POST params: campaign_id
     */
    public function check_campaign_whitelist(): void {
        $this->verify();

        $campaign_id = intval( $_POST['campaign_id'] ?? 0 );
        $campaign    = get_post( $campaign_id );

        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        wp_send_json_success( [ 'is_whitelist' => self::campaign_is_whitelist( $campaign_id ) ] );
    }

    /**
     * Determine if a campaign has a usable traffic source connected.
     *
     * Mirrors the exact requirement in RuleAction::execute() — both the module
     * slug and the remote campaign ID must be present, otherwise no rule action
     * (pause/resume/block/unblock) can reach the traffic source API.
     */
    public static function campaign_has_traffic_source( int $campaign_id ): bool {
        $ts_module_slug = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        return ! empty( $ts_module_slug ) && ! empty( $ts_campaign_id );
    }

    /**
     * Determine if a campaign uses whitelist targeting via its traffic source module.
     */
    public static function campaign_is_whitelist( int $campaign_id ): bool {
        // Check transient cache first (avoids burning rate-limited API calls).
        $cache_key = 'optee_wl_' . $campaign_id;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached === 'yes';
        }

        $ts_module_slug = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        if ( ! $ts_module_slug || ! $ts_campaign_id ) {
            return false;
        }

        $registry = ModuleRegistry::get_instance();
        $module   = $registry->get_module_with_credentials( $ts_module_slug );

        if ( ! $module || ! method_exists( $module, 'get_whitelisted_zones' ) ) {
            return false;
        }

        try {
            $wl = $module->get_whitelisted_zones( $ts_campaign_id );
        } catch ( \Throwable $e ) {
            // API error — don't cache failure, just assume not whitelist.
            return false;
        }

        $is_wl = ! empty( $wl );
        set_transient( $cache_key, $is_wl ? 'yes' : 'no', HOUR_IN_SECONDS );

        return $is_wl;
    }

    // ──────────────────────────────────────────
    // Activation Key
    // ──────────────────────────────────────────

    public function validate_activation_key(): void {
        $this->verify();

        $key   = sanitize_text_field( $_POST['key'] ?? '' );
        $name  = sanitize_text_field( $_POST['name'] ?? '' );
        $email = sanitize_email( $_POST['email'] ?? '' );

        if ( empty( $key ) || empty( $name ) || empty( $email ) ) {
            wp_send_json_error( [ 'message' => 'All fields are required.' ] );
        }

        $result = Activation::validate_key_remote( $key, $name, $email );

        if ( $result['success'] ) {
            // Register installation in admin panel immediately.
            Updater::get_available_updates( true );

            wp_send_json_success( [
                'message'  => $result['message'],
                'key_type' => $result['data']['key_type'] ?? '',
                'expiry'   => $result['data']['expiry_date'] ?? '',
            ] );
        }

        wp_send_json_error( [ 'message' => $result['message'] ] );
    }

    public function transfer_activation_key(): void {
        $this->verify();

        $result = Activation::transfer_key();

        if ( $result['success'] ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        }

        wp_send_json_error( [ 'message' => $result['message'] ] );
    }

    public function get_activation_info(): void {
        $this->verify();

        wp_send_json_success( [
            'activated' => Activation::is_activated(),
            'info'      => Activation::get_info(),
        ] );
    }

    // ──────────────────────────────────────────
    // Plugin Install / Update
    // ──────────────────────────────────────────

    public function install_plugin_from_api(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['slug'] ?? '' );
        // Strict allowlist shape: "optee" or "optee-<segments>" of [a-z0-9] only.
        // Rejects slashes/dots so the slug can't traverse paths downstream.
        if ( ! preg_match( '/^optee(-[a-z0-9]+)*$/', $slug ) ) {
            wp_send_json_error( [ 'message' => 'Invalid plugin slug.' ] );
        }

        $result = Updater::install_plugin( $slug );

        if ( $result['success'] ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        }

        wp_send_json_error( [ 'message' => $result['message'] ] );
    }

    public function update_plugin_from_api(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['slug'] ?? '' );
        // Strict allowlist shape: "optee" or "optee-<segments>" of [a-z0-9] only.
        // Rejects slashes/dots so the slug can't traverse paths downstream.
        if ( ! preg_match( '/^optee(-[a-z0-9]+)*$/', $slug ) ) {
            wp_send_json_error( [ 'message' => 'Invalid plugin slug.' ] );
        }

        $result = Updater::install_plugin( $slug );

        if ( $result['success'] ) {
            // Read new version from updated plugin header.
            $new_version = '';
            $main_file = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
            if ( file_exists( $main_file ) ) {
                $plugin_data = get_file_data( $main_file, [ 'Version' => 'Version' ] );
                $new_version = $plugin_data['Version'] ?? '';
            }

            // Recount remaining updates and sync the stored count immediately.
            $fresh = Updater::get_available_updates( true );
            $remaining = 0;
            foreach ( $fresh['updates'] as $info ) {
                if ( ! empty( $info['update_available'] ) ) {
                    $remaining++;
                }
            }
            update_option( 'optee_updates_available', $remaining );

            wp_send_json_success( [
                'message'      => "Updated {$slug} successfully.",
                'slug'         => $slug,
                'version'      => $new_version,
                'update_count' => $remaining,
            ] );
        }

        wp_send_json_error( [ 'message' => $result['message'] ] );
    }

    /**
     * Return the private tools this install's license is granted. Drives the
     * client Tools page + refreshes the optee_has_tools menu flag.
     */
    public function get_tools(): void {
        $this->verify();
        wp_send_json_success( [ 'tools' => Updater::get_available_tools( true ) ] );
    }

    /**
     * Request access to an available-to-all tool. Auto-approve tools grant
     * instantly; others queue for admin approval. Bell + email are raised by the
     * Updater (now on instant approval, later on the poll for manual approval).
     */
    public function request_tool(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['slug'] ?? '' );
        if ( ! preg_match( '/^optee(-[a-z0-9]+)*$/', $slug ) ) {
            wp_send_json_error( [ 'message' => 'Invalid tool slug.' ] );
        }

        $result = Updater::request_tool( $slug );

        if ( $result['status'] === 'error' ) {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }

        wp_send_json_success( [
            'status'  => $result['status'], // approved | pending
            'message' => $result['message'],
        ] );
    }

    /**
     * Install a granted private tool (downloads from the gated /api/tool-download)
     * and activate it so its page is usable immediately.
     */
    public function install_tool_from_api(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['slug'] ?? '' );
        if ( ! preg_match( '/^optee(-[a-z0-9]+)*$/', $slug ) ) {
            wp_send_json_error( [ 'message' => 'Invalid tool slug.' ] );
        }

        $result = Updater::install_plugin( $slug, true );

        if ( $result['success'] ) {
            // Auto-activate — a granted tool is meant to be used, not staged.
            if ( ! function_exists( 'activate_plugin' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $activated = activate_plugin( $slug . '/' . $slug . '.php' );
            Updater::get_available_tools( true ); // refresh cache + menu flag

            $message = $result['message'];
            if ( is_wp_error( $activated ) ) {
                $message .= ' Activate it from WP Admin: ' . $activated->get_error_message();
            }
            wp_send_json_success( [
                'message'   => $message,
                'activated' => ! is_wp_error( $activated ),
            ] );
        }

        wp_send_json_error( [ 'message' => $result['message'] ] );
    }

    /**
     * Update an installed private tool to its latest granted version.
     */
    public function update_tool_from_api(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['slug'] ?? '' );
        if ( ! preg_match( '/^optee(-[a-z0-9]+)*$/', $slug ) ) {
            wp_send_json_error( [ 'message' => 'Invalid tool slug.' ] );
        }

        $result = Updater::install_plugin( $slug, true );

        if ( $result['success'] ) {
            $new_version = '';
            $main_file   = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
            if ( file_exists( $main_file ) ) {
                $plugin_data = get_file_data( $main_file, [ 'Version' => 'Version' ] );
                $new_version = $plugin_data['Version'] ?? '';
            }
            Updater::get_available_tools( true );

            wp_send_json_success( [
                'message' => "Updated {$slug} successfully.",
                'slug'    => $slug,
                'version' => $new_version,
            ] );
        }

        wp_send_json_error( [ 'message' => $result['message'] ] );
    }

    /**
     * Activate an installed-but-deactivated private tool (mirrors the integration
     * Activate button, but keyed by slug and scoped to the tool channel).
     */
    public function activate_tool(): void {
        $this->verify();

        $slug = sanitize_text_field( $_POST['slug'] ?? '' );
        if ( ! preg_match( '/^optee(-[a-z0-9]+)*$/', $slug ) ) {
            wp_send_json_error( [ 'message' => 'Invalid tool slug.' ] );
        }

        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file = $slug . '/' . $slug . '.php';
        if ( is_plugin_active( $plugin_file ) ) {
            wp_send_json_success( [ 'message' => 'Already active.' ] );
        }

        $result = activate_plugin( $plugin_file );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        Logger::log( 'info', 'updater', "Tool activated: {$slug}", [ 'slug' => $slug ] );
        wp_send_json_success( [ 'message' => 'Activated.' ] );
    }

    public function check_plugin_updates(): void {
        $this->verify();

        // Refresh both caches: version updates AND available-for-install list.
        Updater::get_available_plugins( true );
        $data = Updater::get_available_updates( true );
        $count = 0;

        // Enrich each update entry with human-readable name and type.
        $type_map = [
            'traffic-source'      => 'Traffic Source',
            'tracker'             => 'Tracker',
            'affiliate-network'   => 'Affiliate Network',
            'antifraud'           => 'Antifraud',
            'monetization'        => 'Monetization',
        ];

        foreach ( $data['updates'] as $slug => &$info ) {
            if ( ! empty( $info['update_available'] ) ) {
                $count++;
            }

            // Derive display name and type from slug.
            $short = $slug;
            $type  = '';
            if ( $slug === 'optee' ) {
                $info['display_name'] = 'Optee Core';
                $info['type_label']   = 'Core';
                continue;
            }
            foreach ( $type_map as $prefix => $label ) {
                if ( str_starts_with( $slug, 'optee-' . $prefix . '-' ) ) {
                    $short = substr( $slug, strlen( 'optee-' . $prefix . '-' ) );
                    $type  = $label;
                    break;
                }
            }
            // Convert slug to title case: "propellerads" → "PropellerAds" etc.
            // Try to get from WP plugin headers first.
            $info['display_name'] = ucwords( str_replace( '-', ' ', $short ) );
            $info['type_label']   = $type;

            $plugin_file = $slug . '/' . $slug . '.php';
            if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
                $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
                if ( ! empty( $plugin_data['Name'] ) ) {
                    $info['display_name'] = preg_replace( '/^Optee\s*[-–—]\s*/i', '', $plugin_data['Name'] );
                }
            }
        }
        unset( $info );

        // Private tools ride the same badge/popup — append their pending updates.
        $tool_updates = Updater::get_tool_updates( true );
        if ( ! empty( $tool_updates ) ) {
            $data['updates'] = array_merge( $data['updates'], $tool_updates );
            $count          += count( $tool_updates );
        }

        // Sync the stored count so the footer badge stays accurate on next page load.
        update_option( 'optee_updates_available', $count );

        wp_send_json_success( [
            'update_count' => $count,
            'updates'      => $data['updates'],
            'expired'      => $data['expired'],
            'checked_at'   => $data['checked_at'] ?? '',
        ] );
    }

    public function get_available_plugins(): void {
        $this->verify();

        $plugins = Updater::get_available_plugins( true );
        wp_send_json_success( [ 'plugins' => $plugins ] );
    }

    // ──────────────────────────────────────────
    // Support Updates (polling)
    // ──────────────────────────────────────────

    public function get_support_updates(): void {
        $this->verify();

        $key   = get_option( 'optee_activation_key', '' );
        $email = get_option( 'optee_activation_email', '' );

        if ( ! $key || ! $email ) {
            wp_send_json_success( [ 'updates' => [] ] );
        }

        $response = wp_remote_post( 'https://support.optee.co/api/updates', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'key'   => $key,
                'email' => $email,
            ] ),
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_success( [ 'updates' => [] ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['ok'] ) || empty( $body['updates'] ) ) {
            wp_send_json_success( [ 'updates' => [] ] );
        }

        // Process promo notifications: save locally + send email (once per update).
        $user_id        = get_current_user_id();
        $processed_ids  = get_user_meta( $user_id, 'optee_promo_processed_ids', true );
        $processed_ids  = is_array( $processed_ids ) ? $processed_ids : [];

        foreach ( $body['updates'] as $update ) {
            $update_type = $update['update_type'] ?? '';
            $update_id   = $update['id'] ?? 0;

            if ( ! in_array( $update_type, [ 'promo_approved', 'promo_declined' ], true ) ) {
                continue;
            }
            if ( in_array( $update_id, $processed_ids, true ) ) {
                continue;
            }

            // Send email to WP admin user.
            $is_approved = $update_type === 'promo_approved';
            $user = get_userdata( $user_id );
            if ( $user && $user->user_email ) {
                $subject    = $is_approved ? 'Your Promo Code is Ready!' : 'Promo Code Request Update';
                $promo_url  = Shortcode::get_url( 'promo-codes' );
                $email_html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:600px;margin:0 auto;">'
                    . '<h2 style="color:#111827;font-size:18px;margin:0 0 12px;">' . esc_html( $subject ) . '</h2>'
                    . '<p style="font-size:14px;color:#374151;margin:0 0 16px;">' . esc_html( $update['summary'] ?? '' ) . '</p>'
                    . '<p style="margin:16px 0 0;"><a href="' . esc_url( $promo_url ) . '" style="display:inline-block;padding:10px 20px;background:#2aa5ff;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">View Promo Codes</a></p>'
                    . '</div>';
                wp_mail( $user->user_email, '[Optee] ' . $subject, $email_html, [ 'Content-Type: text/html; charset=UTF-8' ] );
            }

            $processed_ids[] = $update_id;
        }

        // Keep only last 100 processed IDs.
        if ( count( $processed_ids ) > 100 ) {
            $processed_ids = array_slice( $processed_ids, -100 );
        }
        update_user_meta( $user_id, 'optee_promo_processed_ids', $processed_ids );

        wp_send_json_success( [ 'updates' => $body['updates'] ] );
    }

    public function mark_support_read(): void {
        $this->verify();

        $key   = get_option( 'optee_activation_key', '' );
        $email = get_option( 'optee_activation_email', '' );

        if ( ! $key || ! $email ) {
            wp_send_json_success();
        }

        $update_ids = isset( $_POST['update_ids'] ) ? array_map( 'intval', (array) $_POST['update_ids'] ) : [];

        wp_remote_post( 'https://support.optee.co/api/mark-read', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'key'        => $key,
                'email'      => $email,
                'update_ids' => $update_ids,
            ] ),
            'timeout' => 10,
        ] );

        wp_send_json_success();
    }

    // ──────────────────────────────────────────
    // Deferred Zone Status (BidVertiser)
    // ──────────────────────────────────────────

    public function load_zone_status(): void {
        $this->verify();

        $campaign_id    = intval( $_POST['campaign_id'] ?? 0 );
        $campaign       = get_post( $campaign_id );
        if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Campaign not found.' ] );
        }

        $ts_slug        = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
        $ts_campaign_id = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );

        if ( ! $ts_slug || ! $ts_campaign_id ) {
            wp_send_json_error( [ 'message' => 'No traffic source configured.' ] );
        }

        $registry  = ModuleRegistry::get_instance();
        $ts_module = $registry->get_module( $ts_slug );
        if ( ! $ts_module ) {
            wp_send_json_error( [ 'message' => 'Module not found.' ] );
        }

        $ts_module->set_credentials(
            ModuleRegistry::get_stored_credentials( 'traffic_source', $ts_slug ) ?: []
        );

        $blocked    = [];
        $whitelisted = [];

        try {
            if ( method_exists( $ts_module, 'get_blocked_zones' ) ) {
                $blocked = $ts_module->get_blocked_zones( $ts_campaign_id );
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }

        try {
            if ( method_exists( $ts_module, 'get_whitelisted_zones' ) ) {
                $whitelisted = $ts_module->get_whitelisted_zones( $ts_campaign_id );
            }
        } catch ( \Throwable $e ) {
            // BL succeeded but WL failed — return what we have.
        }

        wp_send_json_success( [
            'blocked_zones'    => $blocked,
            'whitelisted_zones' => $whitelisted,
        ] );
    }

    // ──────────────────────────────────────────
    // Promo Codes
    // ──────────────────────────────────────────

    public function get_promo_campaigns(): void {
        $this->verify();

        $key   = get_option( 'optee_activation_key', '' );
        $email = get_option( 'optee_activation_email', '' );

        if ( ! $key || ! $email ) {
            wp_send_json_success( [ 'campaigns' => [] ] );
        }

        $request_body = apply_filters( 'optee_promo_active_request_body', [
            'key'   => $key,
            'email' => $email,
        ] );

        $response = wp_remote_post( 'https://support.optee.co/api/promo/active-campaigns', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $request_body ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_success( [ 'campaigns' => [] ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['ok'] ) || empty( $body['campaigns'] ) ) {
            update_option( 'optee_has_active_promo', '0', false );
            wp_send_json_success( [ 'campaigns' => [], 'preview' => ! empty( $body['preview'] ) ] );
        }

        // Filter out campaigns the user has dismissed.
        $user_id    = get_current_user_id();
        $campaigns  = [];
        $has_active = false;
        foreach ( $body['campaigns'] as $campaign ) {
            $campaign['dismissed'] = (bool) get_user_meta( $user_id, 'optee_promo_dismissed_' . $campaign['id'], true );
            // Drafts don't count — they're dev-only previews and must not unhide the sidebar link.
            if ( ( $campaign['status'] ?? '' ) === 'active' ) {
                $has_active = true;
            }
            $campaigns[] = $campaign;
        }
        update_option( 'optee_has_active_promo', $has_active ? '1' : '0', false );

        wp_send_json_success( [
            'campaigns' => $campaigns,
            'preview'   => ! empty( $body['preview'] ),
        ] );
    }

    public function submit_promo_request(): void {
        $this->verify();

        $key     = get_option( 'optee_activation_key', '' );
        $email   = get_option( 'optee_activation_email', '' );
        $tier_id = isset( $_POST['tier_id'] ) ? intval( $_POST['tier_id'] ) : 0;

        if ( ! $key || ! $email || ! $tier_id ) {
            wp_send_json_error( [ 'message' => 'Missing parameters.' ] );
        }

        $response = wp_remote_post( 'https://support.optee.co/api/promo/request', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'key'     => $key,
                'email'   => $email,
                'tier_id' => $tier_id,
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Connection error.' ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['ok'] ) ) {
            wp_send_json_error( [ 'message' => $body['error'] ?? 'Request failed.' ] );
        }

        wp_send_json_success( [ 'request_id' => $body['request_id'] ?? 0 ] );
    }

    public function get_promo_my_requests(): void {
        $this->verify();

        $key   = get_option( 'optee_activation_key', '' );
        $email = get_option( 'optee_activation_email', '' );

        if ( ! $key || ! $email ) {
            wp_send_json_success( [ 'requests' => [] ] );
        }

        $response = wp_remote_post( 'https://support.optee.co/api/promo/my-requests', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'key'   => $key,
                'email' => $email,
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_success( [ 'requests' => [] ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        wp_send_json_success( [ 'requests' => $body['requests'] ?? [] ] );
    }

    public function dismiss_promo_popup(): void {
        $this->verify();

        $campaign_id = isset( $_POST['campaign_id'] ) ? intval( $_POST['campaign_id'] ) : 0;

        if ( $campaign_id ) {
            update_user_meta( get_current_user_id(), 'optee_promo_dismissed_' . $campaign_id, '1' );
        }

        wp_send_json_success();
    }

    public function dismiss_cache_warning(): void {
        $this->verify();
        update_user_meta( get_current_user_id(), 'optee_cache_warning_dismissed', '1' );
        wp_send_json_success();
    }

    // ──────────────────────────────────────────
    // Setup Wizard
    // ──────────────────────────────────────────

    /**
     * Run system health checks for the setup wizard.
     */
    public function wizard_system_check(): void {
        $this->verify();
        global $wpdb;

        $checks = [];

        // 1. Dashboard page exists with shortcode.
        $page_id = (int) get_option( 'optee_dashboard_page_id', 0 );
        $page    = $page_id ? get_post( $page_id ) : null;
        $page_ok = $page && $page->post_status === 'publish' && str_contains( $page->post_content, '[optee_dashboard]' );
        $checks[] = [
            'id'     => 'dashboard_page',
            'label'  => 'Dashboard page',
            'status' => $page_ok ? 'pass' : 'fail',
            'detail' => $page_ok ? 'Page exists with shortcode' : 'Page missing or invalid',
            'fix'    => 'dashboard_page',
        ];

        // 2. Homepage set to dashboard page.
        $front_page = (int) get_option( 'page_on_front', 0 );
        $show_front = get_option( 'show_on_front', 'posts' );
        $front_ok   = $show_front === 'page' && $front_page === $page_id && $page_id > 0;
        $checks[] = [
            'id'     => 'front_page',
            'label'  => 'Homepage set to dashboard',
            'status' => $front_ok ? 'pass' : 'fail',
            'detail' => $front_ok ? 'Correctly configured' : 'Homepage is not set to the dashboard page',
            'fix'    => 'front_page',
        ];

        // 3. Theme exists.
        $theme_dir = get_theme_root() . '/optee/';
        $theme_exists = is_dir( $theme_dir ) && file_exists( $theme_dir . 'style.css' );
        $checks[] = [
            'id'     => 'theme_exists',
            'label'  => 'Optee theme files',
            'status' => $theme_exists ? 'pass' : 'fail',
            'detail' => $theme_exists ? 'Theme directory exists' : 'Theme not found in ' . basename( get_theme_root() ) . '/optee/',
            'fix'    => 'theme_extract',
        ];

        // 4. Theme is active.
        $active_theme = get_option( 'stylesheet', '' );
        $theme_active = $active_theme === 'optee';
        $checks[] = [
            'id'     => 'theme_active',
            'label'  => 'Optee theme active',
            'status' => $theme_active ? 'pass' : ( $theme_exists ? 'fail' : 'skip' ),
            'detail' => $theme_active ? 'Active theme is Optee' : "Active theme is \"{$active_theme}\"",
            'fix'    => 'theme_activate',
        ];

        // 5. DB tables exist.
        $required_tables = [ 'optee_logs', 'optee_balances', 'optee_earnings', 'optee_vt_scans', 'optee_notifications' ];
        $missing_tables  = [];
        foreach ( $required_tables as $t ) {
            $full = $wpdb->prefix . $t;
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
            if ( ! $exists ) {
                $missing_tables[] = $t;
            }
        }
        $db_ok = empty( $missing_tables );
        $checks[] = [
            'id'     => 'db_tables',
            'label'  => 'Database tables (' . count( $required_tables ) . ')',
            'status' => $db_ok ? 'pass' : 'fail',
            'detail' => $db_ok ? 'All tables present' : 'Missing: ' . implode( ', ', $missing_tables ),
            'fix'    => 'db_tables',
        ];

        // 6. Cron secret exists.
        $cron_secret = get_option( 'optee_cron_secret', '' );
        $checks[] = [
            'id'     => 'cron_secret',
            'label'  => 'Cron secret token',
            'status' => $cron_secret ? 'pass' : 'fail',
            'detail' => $cron_secret ? 'Token configured' : 'Missing — automation will not work',
            'fix'    => 'cron_secret',
        ];

        // 7. WP-Cron disabled.
        $wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $checks[] = [
            'id'     => 'wp_cron_disabled',
            'label'  => 'WP-Cron disabled',
            'status' => $wp_cron_disabled ? 'pass' : 'warn',
            'detail' => $wp_cron_disabled ? 'DISABLE_WP_CRON is true' : 'WP-Cron is still active — may interfere with Optee cron',
            'fix'    => 'disable_wp_cron',
        ];

        // 8. PHP version.
        $php_ok = version_compare( PHP_VERSION, '8.0', '>=' );
        $checks[] = [
            'id'     => 'php_version',
            'label'  => 'PHP version',
            'status' => $php_ok ? 'pass' : 'fail',
            'detail' => 'PHP ' . PHP_VERSION . ( $php_ok ? '' : ' (8.0+ required)' ),
            'fix'    => null,
        ];

        // 9. OpenSSL extension.
        $openssl = extension_loaded( 'openssl' );
        $checks[] = [
            'id'     => 'openssl',
            'label'  => 'OpenSSL extension',
            'status' => $openssl ? 'pass' : 'fail',
            'detail' => $openssl ? 'Loaded' : 'Missing — credential encryption will not work',
            'fix'    => null,
        ];

        $pass_count = count( array_filter( $checks, fn( $c ) => $c['status'] === 'pass' ) );

        wp_send_json_success( [
            'checks'     => $checks,
            'pass_count' => $pass_count,
            'total'      => count( $checks ),
        ] );
    }

    /**
     * Fix a specific system issue.
     */
    public function wizard_fix_issue(): void {
        $this->verify();

        $issue = sanitize_text_field( $_POST['issue'] ?? '' );

        switch ( $issue ) {
            case 'dashboard_page':
                Activator::activate(); // Re-runs all activation steps.
                wp_send_json_success( [ 'message' => 'Dashboard page re-created.' ] );
                break;

            case 'front_page':
                $page_id = (int) get_option( 'optee_dashboard_page_id', 0 );
                if ( $page_id ) {
                    update_option( 'page_on_front', $page_id );
                    update_option( 'show_on_front', 'page' );
                    wp_send_json_success( [ 'message' => 'Homepage set to dashboard.' ] );
                } else {
                    wp_send_json_error( [ 'message' => 'Dashboard page not found — run Dashboard Page fix first.' ] );
                }
                break;

            case 'theme_extract':
                $source = OPTEE_PLUGIN_DIR . 'theme/';
                $dest   = get_theme_root() . '/optee/';
                if ( ! is_dir( $source ) ) {
                    wp_send_json_error( [ 'message' => 'Theme source not found in plugin.' ] );
                }
                if ( ! is_dir( $dest ) ) {
                    wp_mkdir_p( $dest );
                }
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ( $iterator as $item ) {
                    $target = $dest . $iterator->getSubPathname();
                    if ( $item->isDir() ) {
                        wp_mkdir_p( $target );
                    } else {
                        copy( $item->getRealPath(), $target );
                    }
                }
                wp_send_json_success( [ 'message' => 'Theme files extracted.' ] );
                break;

            case 'theme_activate':
                switch_theme( 'optee' );
                wp_send_json_success( [ 'message' => 'Optee theme activated.' ] );
                break;

            case 'db_tables':
                require_once OPTEE_PLUGIN_DIR . 'includes/db/class-db-manager.php';
                \Optee\Plugin\DB\DbManager::create_tables();
                wp_send_json_success( [ 'message' => 'Database tables created.' ] );
                break;

            case 'cron_secret':
                update_option( 'optee_cron_secret', wp_generate_password( 32, false ) );
                wp_send_json_success( [ 'message' => 'Cron secret generated.' ] );
                break;

            case 'disable_wp_cron':
                if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
                    wp_send_json_success( [ 'message' => 'Already disabled.' ] );
                }
                $config_path = ABSPATH . 'wp-config.php';
                if ( ! is_writable( $config_path ) ) {
                    wp_send_json_error( [ 'message' => 'wp-config.php is not writable. Add define(\'DISABLE_WP_CRON\', true); manually.' ] );
                }
                $config = file_get_contents( $config_path );
                if ( strpos( $config, 'DISABLE_WP_CRON' ) !== false ) {
                    wp_send_json_success( [ 'message' => 'Already in wp-config.php (restart PHP to apply).' ] );
                }
                $marker = "/* That's all, stop editing!";
                $insert = "define( 'DISABLE_WP_CRON', true ); // Added by Optee\n\n";
                $config = str_replace( $marker, $insert . $marker, $config );
                file_put_contents( $config_path, $config, LOCK_EX );
                wp_send_json_success( [ 'message' => 'DISABLE_WP_CRON added to wp-config.php.' ] );
                break;

            default:
                wp_send_json_error( [ 'message' => 'Unknown issue.' ] );
        }
    }

    public function wizard_save_step(): void {
        $this->verify();
        $step = max( 1, min( 6, intval( $_POST['step'] ?? 1 ) ) );
        update_option( 'optee_setup_step', $step );
        wp_send_json_success();
    }

    public function wizard_detect_cpanel(): void {
        $this->verify();
        wp_send_json_success( [ 'available' => is_dir( '/usr/local/cpanel/' ) ] );
    }

    public function wizard_cpanel_cron(): void {
        $this->verify();

        $username   = sanitize_text_field( $_POST['username'] ?? '' );
        $password   = $_POST['password'] ?? '';
        $cpanel_url = sanitize_text_field( $_POST['cpanel_url'] ?? '' );

        if ( empty( $username ) || empty( $password ) ) {
            wp_send_json_error( [ 'message' => 'Username and password are required.' ] );
        }

        // Validate cPanel URL — allow localhost or the server's own hostname.
        if ( ! empty( $cpanel_url ) ) {
            $parsed_host = wp_parse_url( $cpanel_url, PHP_URL_HOST );
            $allowed     = [ 'localhost', '127.0.0.1', '::1' ];
            $server_host = gethostname();
            if ( $server_host ) {
                $allowed[] = $server_host;
            }
            // Also allow the hostname from SERVER_NAME / HTTP_HOST.
            if ( ! empty( $_SERVER['SERVER_NAME'] ) ) {
                $allowed[] = $_SERVER['SERVER_NAME'];
            }
            $parsed_port = wp_parse_url( $cpanel_url, PHP_URL_PORT );
            $is_cpanel_port = in_array( (int) $parsed_port, [ 2083, 2087, 2082, 2086 ], true );
            if ( ! $parsed_host || ( ! in_array( $parsed_host, $allowed, true ) && ! $is_cpanel_port ) ) {
                wp_send_json_error( [ 'message' => 'cPanel URL must point to your server (localhost or server hostname with cPanel port).' ] );
            }
        }

        $command = "wget -q --no-check-certificate -O /dev/null '" . CronHandler::get_cron_url( true ) . "' >/dev/null 2>&1";

        // Try localhost first, then custom URL.
        $hosts = [];
        if ( ! empty( $cpanel_url ) ) {
            $hosts[] = rtrim( $cpanel_url, '/' );
        } else {
            $hosts[] = 'https://localhost:2083';
        }

        $last_error = '';
        $auth_header = 'Basic ' . base64_encode( $username . ':' . $password );
        $cron_body   = [
            'command' => $command,
            'minute'  => '*',
            'hour'    => '*',
            'day'     => '*',
            'month'   => '*',
            'weekday' => '*',
        ];

        foreach ( $hosts as $host ) {
            // Try UAPI first, then cPanel API 2 as fallback.
            $attempts = [
                [
                    'url'  => $host . '/execute/Cron/add_line',
                    'body' => $cron_body,
                    'type' => 'uapi',
                ],
                [
                    'url'  => $host . '/json-api/cpanel?cpanel_jsonapi_user=' . urlencode( $username )
                             . '&cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=add_line'
                             . '&command=' . urlencode( $command )
                             . '&minute=*&hour=*&day=*&month=*&weekday=*',
                    'body' => null,
                    'type' => 'api2',
                ],
            ];

            foreach ( $attempts as $attempt ) {
                $args = [
                    'timeout'   => 15,
                    'sslverify' => false,
                    'headers'   => [ 'Authorization' => $auth_header ],
                ];

                if ( $attempt['body'] ) {
                    $args['body'] = $attempt['body'];
                    $response = wp_remote_post( $attempt['url'], $args );
                } else {
                    $response = wp_remote_get( $attempt['url'], $args );
                }

                if ( is_wp_error( $response ) ) {
                    $last_error = $response->get_error_message();
                    continue;
                }

                $code = wp_remote_retrieve_response_code( $response );
                $body = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( $code === 401 ) {
                    wp_send_json_error( [ 'message' => 'Invalid cPanel credentials.' ] );
                }

                // UAPI success check.
                if ( $attempt['type'] === 'uapi' && isset( $body['status'] ) && $body['status'] == 1 ) {
                    update_option( 'optee_cron_setup_done', '1' );
                    Logger::log( 'info', 'wizard', 'Cron job added via cPanel UAPI.', [ 'host' => $host ] );
                    wp_send_json_success( [ 'message' => 'Cron job added successfully.' ] );
                }

                // API 2 success check.
                if ( $attempt['type'] === 'api2' ) {
                    $result = $body['cpanelresult']['data'][0] ?? $body['cpanelresult'] ?? [];
                    $api2_ok = ( isset( $result['statusmsg'] ) && stripos( $result['statusmsg'], 'added' ) !== false )
                            || ( isset( $body['cpanelresult']['event']['result'] ) && $body['cpanelresult']['event']['result'] == 1 );
                    if ( $api2_ok ) {
                        update_option( 'optee_cron_setup_done', '1' );
                        Logger::log( 'info', 'wizard', 'Cron job added via cPanel API 2.', [ 'host' => $host ] );
                        wp_send_json_success( [ 'message' => 'Cron job added successfully.' ] );
                    }
                }

                $err = $body['errors'][0]
                    ?? $body['error']
                    ?? $body['cpanelresult']['error']
                    ?? '';

                // If module not found, try next endpoint.
                if ( stripos( $err, 'failed to load' ) !== false || stripos( $err, 'module' ) !== false ) {
                    $last_error = $err;
                    continue;
                }

                $last_error = $err ?: 'Unknown error from cPanel.';
            }
        }

        wp_send_json_error( [ 'message' => 'Could not connect to cPanel: ' . $last_error ] );
    }

    public function wizard_detect_directadmin(): void {
        $this->verify();
        wp_send_json_success( [ 'available' => is_dir( '/usr/local/directadmin/' ) ] );
    }

    public function wizard_check_cron(): void {
        $this->verify();
        $last = (int) get_option( 'optee_cron_last_auto', 0 );
        $running = $last > 0 && ( time() - $last ) < 120;
        if ( $running ) {
            update_option( 'optee_cron_setup_done', '1' );
        }
        wp_send_json_success( [ 'running' => $running ] );
    }

    public function wizard_send_verify_email(): void {
        $this->verify();

        $email = sanitize_email( $_POST['email'] ?? '' );
        if ( empty( $email ) ) {
            wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ] );
        }

        // Generate verification token.
        $token = wp_generate_password( 32, false );
        update_option( 'optee_email_verify_token', $token );
        delete_option( 'optee_email_verified' );

        $verify_url = add_query_arg( 'optee_verify_email', $token, home_url( '/' ) );

        // Build email HTML.
        $logo_url = OPTEE_PLUGIN_URL . 'assets/images/optee-logo-light.svg';
        $html  = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;padding:40px 16px;">';
        $html .= '<tr><td align="center"><table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">';
        $html .= '<tr><td style="padding:32px 32px 16px;text-align:center;">';
        $html .= '<img src="' . esc_url( $logo_url ) . '" alt="Optee" style="height:28px;margin-bottom:24px;">';
        $html .= '<h1 style="font-size:18px;font-weight:700;color:#111827;margin:0 0 8px;">Verify Your Email</h1>';
        $html .= '<p style="font-size:14px;color:#6b7280;margin:0 0 24px;">Click the button below to verify your email address and confirm that Optee can send you notifications.</p>';
        $html .= '<a href="' . esc_url( $verify_url ) . '" style="display:inline-block;background:#2aa5ff;color:#fff;font-size:14px;font-weight:600;padding:10px 28px;border-radius:8px;text-decoration:none;">Verify Email</a>';
        $html .= '<p style="font-size:12px;color:#9ca3af;margin:24px 0 0;">If the button doesn\'t work, copy this link:<br>' . esc_html( $verify_url ) . '</p>';
        $html .= '</td></tr></table></td></tr></table></body></html>';

        $html_content_type = function() { return 'text/html'; };
        add_filter( 'wp_mail_content_type', $html_content_type );
        $sent = wp_mail( $email, 'Verify your email — Optee', $html );
        remove_filter( 'wp_mail_content_type', $html_content_type );

        if ( $sent ) {
            wp_send_json_success( [ 'message' => 'Verification email sent.' ] );
        }

        wp_send_json_error( [ 'message' => 'Failed to send email. Check your server mail configuration.' ] );
    }

    public function wizard_check_email(): void {
        $this->verify();
        $verified = get_option( 'optee_email_verified', '' ) === '1';
        wp_send_json_success( [ 'verified' => $verified ] );
    }

    // ──────────────────────────────────────────
    // News Announcements
    // ──────────────────────────────────────────

    public function get_news(): void {
        $this->verify();

        $user_id = get_current_user_id();
        $news    = get_option( 'optee_news_items', [] );

        if ( ! is_array( $news ) || empty( $news ) ) {
            // If no cached news, try a live fetch (first visit before cron runs).
            $response = wp_remote_get( 'https://api.optee.co/api/news', [ 'timeout' => 5 ] );
            if ( ! is_wp_error( $response ) ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $news = $body['news'] ?? [];
                if ( ! empty( $news ) ) {
                    update_option( 'optee_news_items', $news, false );
                }
            }
        }

        // Filter out dismissed news.
        $dismissed = get_user_meta( $user_id, 'optee_news_dismissed', true );
        $dismissed = is_array( $dismissed ) ? $dismissed : [];

        $filtered = array_values( array_filter( $news, function ( $item ) use ( $dismissed ) {
            return ! in_array( (int) $item['id'], $dismissed, true );
        } ) );

        wp_send_json_success( $filtered );
    }

    public function dismiss_news(): void {
        $this->verify();

        $user_id  = get_current_user_id();
        $news_ids = isset( $_POST['news_ids'] ) ? array_map( 'intval', (array) $_POST['news_ids'] ) : [];

        if ( ! empty( $news_ids ) ) {
            $dismissed = get_user_meta( $user_id, 'optee_news_dismissed', true );
            $dismissed = is_array( $dismissed ) ? $dismissed : [];
            $dismissed = array_unique( array_merge( $dismissed, $news_ids ) );
            // Keep only the last 100 dismissed IDs.
            $dismissed = array_slice( $dismissed, -100 );
            update_user_meta( $user_id, 'optee_news_dismissed', $dismissed );
        }

        wp_send_json_success();
    }

    public function wizard_complete(): void {
        $this->verify();
        update_option( 'optee_setup_complete', '1' );
        update_option( 'optee_setup_step', 6 );
        wp_send_json_success();
    }

    // ──────────────────────────────────────────
    // Custom Columns (campaign-stats report builder)
    // ──────────────────────────────────────────

    public function list_custom_columns(): void {
        $this->verify();
        wp_send_json_success( [
            'columns' => CustomColumns::get_all( get_current_user_id() ),
        ] );
    }

    public function save_custom_column(): void {
        $this->verify();
        $user_id     = get_current_user_id();
        $scope       = sanitize_text_field( $_POST['scope'] ?? 'global' );
        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $input = [
            'id'          => sanitize_text_field( $_POST['id'] ?? '' ),
            'name'        => sanitize_text_field( $_POST['name'] ?? '' ),
            'description' => sanitize_text_field( $_POST['description'] ?? '' ),
            'formula'     => wp_unslash( (string) ( $_POST['formula'] ?? '' ) ),
            'format'      => sanitize_text_field( $_POST['format'] ?? '' ),
            'decimals'    => (int) ( $_POST['decimals'] ?? 0 ),
            'color'       => sanitize_text_field( $_POST['color'] ?? '' ),
            'clamp_negative' => ( ! empty( $_POST['clamp_negative'] ) && (string) $_POST['clamp_negative'] !== '0' ),
            'treat_missing_zero' => ( ! empty( $_POST['treat_missing_zero'] ) && (string) $_POST['treat_missing_zero'] !== '0' ),
        ];

        // Validate campaign ownership once if a campaign id was supplied.
        $owns_campaign = false;
        if ( $campaign_id > 0 ) {
            $post = get_post( $campaign_id );
            $owns_campaign = $post && $post->post_type === 'optee_campaign' && (int) $post->post_author === $user_id;
            if ( ! $owns_campaign ) {
                wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
            }
        }

        // Per-campaign override: only for an existing library column on an owned campaign.
        if ( $scope === 'campaign' && $input['id'] !== '' && $owns_campaign ) {
            $result = CustomColumns::save_override( $campaign_id, $user_id, $input['id'], $input );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            }
            wp_send_json_success( [ 'column' => $result, 'scope' => 'campaign' ] );
        }

        // Global save (the shared library).
        $result = CustomColumns::save( $user_id, $input );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        // Editing globally clears any local override on this campaign so the new
        // global formula actually takes effect here too.
        if ( $owns_campaign && $input['id'] !== '' ) {
            CustomColumns::delete_override( $campaign_id, $input['id'] );
        }
        wp_send_json_success( [ 'column' => $result, 'scope' => 'global' ] );
    }

    /**
     * Remove a per-campaign formula override (revert the column to the global one).
     */
    public function reset_custom_column_override(): void {
        $this->verify();
        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $column_id   = sanitize_text_field( $_POST['column_id'] ?? '' );

        $post = get_post( $campaign_id );
        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
        }
        if ( $column_id === '' ) {
            wp_send_json_error( [ 'message' => 'Missing column id.' ] );
        }

        CustomColumns::delete_override( $campaign_id, $column_id );
        wp_send_json_success();
    }

    public function delete_custom_column(): void {
        $this->verify();
        $id = sanitize_text_field( $_POST['id'] ?? '' );
        if ( $id === '' ) {
            wp_send_json_error( [ 'message' => 'Missing column id.' ] );
        }
        CustomColumns::delete( get_current_user_id(), $id );
        wp_send_json_success();
    }

    public function set_uninstall_action(): void {
        $this->verify();
        $action = sanitize_text_field( $_POST['uninstall_action'] ?? '' );
        $action = $action === 'wipe' ? 'wipe' : 'keep';
        update_option( 'optee_uninstall_action', $action, false );
        wp_send_json_success( [ 'action' => $action ] );
    }

    public function reorder_custom_columns(): void {
        $this->verify();
        $ids = $_POST['ids'] ?? [];
        if ( ! is_array( $ids ) ) {
            $ids = explode( ',', (string) $ids );
        }
        $ids = array_values( array_filter( array_map( 'sanitize_text_field', $ids ) ) );
        CustomColumns::reorder( get_current_user_id(), $ids );
        wp_send_json_success();
    }

    /**
     * Enable/disable a custom column on a single campaign.
     */
    public function toggle_custom_column_assignment(): void {
        $this->verify();
        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $column_id   = sanitize_text_field( $_POST['column_id'] ?? '' );
        $enabled     = ! empty( $_POST['enabled'] ) && (string) $_POST['enabled'] !== '0';

        $post = get_post( $campaign_id );
        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Invalid campaign.' ] );
        }
        if ( $column_id === '' ) {
            wp_send_json_error( [ 'message' => 'Missing column id.' ] );
        }

        CustomColumns::assign( $campaign_id, get_current_user_id(), $column_id, $enabled );
        wp_send_json_success( [ 'enabled' => CustomColumns::get_enabled_ids( $campaign_id ) ] );
    }

    // ──────────────────────────────────────────
    // Insights
    // ──────────────────────────────────────────

    /** Return stored insights rows + per-network last-refreshed timestamps. */
    public function get_insights(): void {
        $this->verify();
        $user_id = get_current_user_id();

        $rows = InsightsService::get_stored( $user_id );

        $networks = [];
        foreach ( InsightsService::NETWORKS as $slug ) {
            $last = InsightsService::get_last_run( $user_id, $slug );
            $networks[ $slug ] = [
                'last_run'    => $last,
                'last_run_ago' => $last ? human_time_diff( $last ) . ' ago' : null,
            ];
        }

        wp_send_json_success( [
            'offers'   => $rows,
            'networks' => $networks,
        ] );
    }

    /** Fetch all 3 networks in parallel and rewrite DB rows. */
    public function refresh_insights(): void {
        $this->verify();
        $user_id = get_current_user_id();

        $result = InsightsService::refresh_all_parallel( $user_id );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        // Re-fetch stored rows so client gets immediate fresh data
        $rows = InsightsService::get_stored( $user_id );

        wp_send_json_success( [
            'result'   => $result,
            'offers'   => $rows,
            'fetched_at' => time(),
        ] );
    }
}
