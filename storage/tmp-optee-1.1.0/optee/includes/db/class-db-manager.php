<?php

namespace Optee\Plugin\DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DbManager {

    /**
     * Create or update the custom database tables.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name      = $wpdb->prefix . 'optee_logs';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            log_type varchar(50) NOT NULL DEFAULT 'info',
            category varchar(50) NOT NULL DEFAULT 'general',
            message text NOT NULL,
            context longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user_id (user_id),
            KEY idx_log_type (log_type),
            KEY idx_category (category),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        $balances_table = $wpdb->prefix . 'optee_balances';

        $sql2 = "CREATE TABLE {$balances_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            module_type varchar(50) NOT NULL,
            module_slug varchar(100) NOT NULL,
            balance_date date NOT NULL,
            balance decimal(12,2) NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            is_manual tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_module_date (user_id, module_type, module_slug, balance_date),
            KEY balance_date (balance_date)
        ) {$charset_collate};";

        $earnings_table = $wpdb->prefix . 'optee_earnings';

        $sql3 = "CREATE TABLE {$earnings_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            module_type varchar(50) NOT NULL,
            module_slug varchar(100) NOT NULL,
            earnings_date date NOT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_module_date (user_id, module_type, module_slug, earnings_date),
            KEY earnings_date (earnings_date)
        ) {$charset_collate};";

        $vt_scans_table = $wpdb->prefix . 'optee_vt_scans';

        $sql4 = "CREATE TABLE {$vt_scans_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            url_hash varchar(64) NOT NULL,
            url text NOT NULL,
            scan_date date NOT NULL,
            total_vendors smallint unsigned NOT NULL DEFAULT 0,
            malicious smallint unsigned NOT NULL DEFAULT 0,
            suspicious smallint unsigned NOT NULL DEFAULT 0,
            harmless smallint unsigned NOT NULL DEFAULT 0,
            undetected smallint unsigned NOT NULL DEFAULT 0,
            timeout smallint unsigned NOT NULL DEFAULT 0,
            vendor_results longtext NOT NULL,
            scanned_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_url_date (user_id, url_hash, scan_date),
            KEY idx_user_id (user_id),
            KEY idx_scan_date (scan_date)
        ) {$charset_collate};";

        $notifications_table = $wpdb->prefix . 'optee_notifications';

        $sql5 = "CREATE TABLE {$notifications_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
            type varchar(20) NOT NULL DEFAULT 'info',
            title varchar(255) NOT NULL DEFAULT '',
            message text NOT NULL,
            is_read tinyint(1) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user_id (user_id),
            KEY idx_campaign_id (campaign_id),
            KEY idx_is_read (is_read),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        $insights_table = $wpdb->prefix . 'optee_insights_offers';

        $sql6 = "CREATE TABLE {$insights_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            network_slug varchar(64) NOT NULL,
            offer_id varchar(64) NOT NULL,
            offer_name varchar(255) DEFAULT NULL,
            payout decimal(10,4) DEFAULT 0,
            currency varchar(8) DEFAULT 'USD',
            geo varchar(255) DEFAULT NULL,
            vertical varchar(64) DEFAULT NULL,
            thumbnail varchar(500) DEFAULT NULL,
            rank_metric varchar(32) NOT NULL DEFAULT 'epc',
            rank_value decimal(14,4) DEFAULT 0,
            rank_display varchar(32) DEFAULT NULL,
            cap_pct int DEFAULT NULL,
            cr_pct decimal(8,4) DEFAULT NULL,
            revenue_today decimal(14,4) DEFAULT 0,
            leads_today int DEFAULT 0,
            boosted tinyint(1) DEFAULT 0,
            raw_data longtext DEFAULT NULL,
            fetched_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_user_net_offer (user_id, network_slug, offer_id),
            KEY recency (user_id, network_slug, rank_value),
            KEY revenue (user_id, network_slug, revenue_today)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        dbDelta( $sql2 );
        dbDelta( $sql3 );
        dbDelta( $sql4 );
        dbDelta( $sql5 );
        dbDelta( $sql6 );
    }

    /**
     * Drop all custom tables.
     */
    public static function drop_tables(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_insights_offers" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_notifications" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_vt_scans" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_earnings" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_balances" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_logs" );
    }
}
