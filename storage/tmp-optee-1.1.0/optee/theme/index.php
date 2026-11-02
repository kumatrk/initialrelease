<?php
/**
 * Fallback template — redirect to the dashboard page.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$dashboard_page_id = get_option( 'optee_dashboard_page_id' );

if ( $dashboard_page_id ) {
    wp_safe_redirect( get_permalink( $dashboard_page_id ) );
    exit;
}

// Fallback if no dashboard page exists.
wp_safe_redirect( home_url() );
exit;
