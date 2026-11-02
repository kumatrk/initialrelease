<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SetupWizard {

    /**
     * Register hooks.
     */
    public function init(): void {
        add_action( 'init', [ $this, 'handle_email_verification' ], 1 );
        add_action( 'template_redirect', [ $this, 'maybe_redirect_to_wizard' ] );
    }

    /**
     * Catch ?optee_verify_email=TOKEN URL, validate, and render confirmation page.
     */
    public function handle_email_verification(): void {
        if ( empty( $_GET['optee_verify_email'] ) ) {
            return;
        }

        $token  = sanitize_text_field( $_GET['optee_verify_email'] );
        $stored = get_option( 'optee_email_verify_token', '' );

        if ( empty( $stored ) || ! hash_equals( $stored, $token ) ) {
            // Generic 404 — don't confirm the plugin to an unauthenticated caller
            // presenting a wrong or missing verification token.
            status_header( 404 );
            nocache_headers();
            exit;
        }

        // Mark email as verified.
        update_option( 'optee_email_verified', '1' );
        delete_option( 'optee_email_verify_token' );

        $this->render_verification_page( true );
        exit;
    }

    /**
     * If user is on the dashboard page and setup is not complete, redirect to wizard.
     */
    public function maybe_redirect_to_wizard(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $page_id = get_option( 'optee_dashboard_page_id' );
        if ( ! $page_id || ! is_page( (int) $page_id ) ) {
            return;
        }

        // Already completed setup.
        if ( get_option( 'optee_setup_complete', '' ) ) {
            return;
        }

        // Already on the wizard page.
        $module = isset( $_GET['module'] ) ? sanitize_key( $_GET['module'] ) : '';
        if ( $module === 'setup-wizard' ) {
            return;
        }

        $wizard_url = Shortcode::get_url( 'setup-wizard' );
        wp_safe_redirect( $wizard_url );
        exit;
    }

    /**
     * Render a simple branded verification result page.
     */
    private function render_verification_page( bool $success ): void {
        $logo = esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-logo-light.svg' );
        $title   = $success ? 'Email Verified!' : 'Verification Failed';
        $message = $success
            ? 'Your email has been verified successfully. You can close this tab and return to the setup wizard.'
            : 'This verification link is invalid or has already been used.';
        $color   = $success ? '#22c55e' : '#ef4444';
        $icon    = $success
            ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'
            : '<path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>';

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . esc_html( $title ) . ' — Optee</title>';
        echo '<style>';
        echo '*{margin:0;padding:0;box-sizing:border-box}';
        echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:16px}';
        echo '.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:48px 40px;text-align:center;max-width:420px;width:100%;box-shadow:0 1px 3px rgba(0,0,0,.04)}';
        echo '.logo{height:28px;margin-bottom:32px}';
        echo 'h1{font-size:20px;font-weight:700;color:#111827;margin-bottom:8px}';
        echo 'p{font-size:14px;color:#6b7280;line-height:1.6}';
        echo '</style></head><body>';
        echo '<div class="card">';
        echo '<img src="' . $logo . '" alt="Optee" class="logo">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="1.5" style="margin:0 auto 16px;display:block;">' . $icon . '</svg>';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        echo '<p>' . esc_html( $message ) . '</p>';
        echo '</div></body></html>';
    }
}
