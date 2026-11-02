<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AccessControl {

    /**
     * Initialize access control hooks.
     */
    public function init(): void {
        add_action( 'template_redirect', [ $this, 'enforce_admin_access' ] );
    }

    /**
     * Enforce login + administrator role on all frontend URLs.
     * Allows wp-login, wp-admin, admin-ajax, and cron endpoint through.
     */
    public function enforce_admin_access(): void {
        // Allow WordPress admin area.
        if ( is_admin() ) {
            return;
        }

        // Allow AJAX requests.
        if ( wp_doing_ajax() ) {
            return;
        }

        // Allow cron endpoint.
        if ( get_query_var( 'optee-cron' ) ) {
            return;
        }

        // Allow REST API requests.
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        // If not logged in, redirect to login. Build the return URL from the
        // trusted site host (home_url) rather than the attacker-controllable Host
        // header; add_query_arg(null, null) preserves the current path + query.
        if ( ! is_user_logged_in() ) {
            $redirect_url = esc_url_raw( home_url( add_query_arg( null, null ) ) );
            wp_safe_redirect( wp_login_url( $redirect_url ) );
            exit;
        }

        // If logged in but not admin, show error.
        if ( ! current_user_can( 'manage_options' ) ) {
            $template = OPTEE_PLUGIN_DIR . 'templates/partials/error-no-access.php';
            if ( file_exists( $template ) ) {
                status_header( 403 );
                include $template;
                exit;
            }
            wp_die(
                esc_html__( 'You do not have permission to access this page.', 'optee' ),
                esc_html__( 'Access Denied', 'optee' ),
                [ 'response' => 403 ]
            );
        }
    }
}
