<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * By default we KEEP all user data (campaigns, rules, scans, options, user
 * meta, dashboard page) so re-installing the plugin restores the user's
 * setup. The only path that wipes everything is when the admin explicitly
 * chose "Remove all data" in the pre-delete modal — that choice sets
 * the `optee_uninstall_action` option to "wipe".
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Read the choice the admin made in the Plugins-page modal. Anything other
// than the explicit "wipe" string means: leave data untouched.
$action = get_option( 'optee_uninstall_action', 'keep' );
if ( $action !== 'wipe' ) {
    // Still clear the choice marker so a future re-install starts clean.
    delete_option( 'optee_uninstall_action' );
    return;
}

// 1. Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_notifications" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_vt_scans" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_earnings" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_balances" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optee_logs" );

// 2. Delete all optee_campaign posts and their meta.
$campaign_ids = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'optee_campaign'"
);
foreach ( $campaign_ids as $id ) {
    wp_delete_post( (int) $id, true );
}

// 3. Delete all optee_rule posts and their meta.
$rule_ids = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'optee_rule'"
);
foreach ( $rule_ids as $id ) {
    wp_delete_post( (int) $id, true );
}

// 4. Delete the dashboard page.
$page_id = get_option( 'optee_dashboard_page_id' );
if ( $page_id ) {
    wp_delete_post( (int) $page_id, true );
}

// 5. Delete all plugin options.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'optee\_%'"
);

// 6. Delete all user meta with optee_ prefix.
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'optee\_%'"
);

// 7. Remove the extracted theme directory.
$theme_dir = get_theme_root() . '/optee';
if ( is_dir( $theme_dir ) ) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $theme_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $item ) {
        if ( $item->isDir() ) {
            rmdir( $item->getRealPath() );
        } else {
            unlink( $item->getRealPath() );
        }
    }
    rmdir( $theme_dir );
}
