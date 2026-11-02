<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Activation key management.
 *
 * Options used:
 *   optee_activation_key      — The license key code
 *   optee_activation_domain   — Domain the key is bound to
 *   optee_activation_type     — Key type (e.g., "standard", "lifetime")
 *   optee_activation_expiry   — Expiry date ISO string or empty for lifetime
 *   optee_activation_name     — Licensee name
 *   optee_activation_email    — Licensee email
 *   optee_activated_at        — Timestamp of activation
 */
class Activation {

    private const API_BASE = 'https://api.optee.co';
    private const CHARS    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * Check if the plugin is activated with a valid key.
     */
    public static function is_activated(): bool {
        $key = get_option( 'optee_activation_key', '' );
        if ( empty( $key ) ) {
            return false;
        }

        // Basic format/checksum validation.
        return self::validate_key_format( $key );
    }

    /**
     * Check if the key has expired (expired keys still count as activated).
     */
    public static function is_expired(): bool {
        $expiry = get_option( 'optee_activation_expiry', '' );
        if ( empty( $expiry ) ) {
            return false; // No expiry = lifetime.
        }

        return strtotime( $expiry ) < time();
    }

    /**
     * Get stored activation info.
     */
    public static function get_info(): array {
        return [
            'key'        => get_option( 'optee_activation_key', '' ),
            'domain'     => get_option( 'optee_activation_domain', '' ),
            'key_type'   => get_option( 'optee_activation_type', '' ),
            'expiry'     => get_option( 'optee_activation_expiry', '' ),
            'name'       => get_option( 'optee_activation_name', '' ),
            'email'      => get_option( 'optee_activation_email', '' ),
            'activated_at' => get_option( 'optee_activated_at', '' ),
            'is_expired' => self::is_expired(),
        ];
    }

    /**
     * Validate a key against the remote API and store activation data.
     *
     * @return array{success: bool, message: string, data?: array}
     */
    public static function validate_key_remote( string $key, string $name, string $email ): array {
        // Local format check first.
        if ( ! self::validate_key_format( $key ) ) {
            return [ 'success' => false, 'message' => 'Invalid key format.' ];
        }

        $domain = self::get_domain();

        $response = wp_remote_post( self::API_BASE . '/api/validate-key', [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'key'    => $key,
                'name'   => $name,
                'email'  => $email,
                'domain' => $domain,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => 'Could not reach activation server: ' . $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            return [ 'success' => false, 'message' => 'Invalid response from activation server.' ];
        }

        if ( empty( $body['valid'] ) ) {
            return [ 'success' => false, 'message' => $body['error'] ?? 'Activation failed.' ];
        }

        // Store activation data.
        update_option( 'optee_activation_key', $key );
        update_option( 'optee_activation_domain', $domain );
        update_option( 'optee_activation_type', $body['key_type'] ?? 'standard' );
        update_option( 'optee_activation_expiry', $body['expiry_date'] ?? '' );
        update_option( 'optee_activation_name', $name );
        update_option( 'optee_activation_email', $email );
        update_option( 'optee_activated_at', current_time( 'mysql' ) );

        Logger::log( 'info', 'activation', 'Plugin activated successfully.', [
            'key_type'  => $body['key_type'] ?? '',
            'domain'    => $domain,
        ] );

        return [
            'success' => true,
            'message' => $body['message'] ?? 'Key activated successfully.',
            'data'    => [
                'key_type'    => $body['key_type'] ?? 'standard',
                'expiry_date' => $body['expiry_date'] ?? '',
            ],
        ];
    }

    /**
     * Transfer (release) the license from this domain.
     *
     * @return array{success: bool, message: string}
     */
    public static function transfer_key(): array {
        $key    = get_option( 'optee_activation_key', '' );
        $domain = self::get_domain();

        if ( empty( $key ) ) {
            return [ 'success' => false, 'message' => 'No activation key found.' ];
        }

        $response = wp_remote_post( self::API_BASE . '/api/transfer-key', [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'key'    => $key,
                'domain' => $domain,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => 'Could not reach activation server: ' . $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || empty( $body['success'] ) ) {
            return [ 'success' => false, 'message' => $body['error'] ?? 'Transfer failed.' ];
        }

        // Clear local activation data.
        self::clear_activation();

        Logger::log( 'info', 'activation', 'License transferred (released from this domain).', [
            'key'    => substr( $key, 0, 11 ) . '...',
            'domain' => $domain,
        ] );

        return [ 'success' => true, 'message' => $body['message'] ?? 'License transferred successfully.' ];
    }

    /**
     * Clear all stored activation data.
     */
    public static function clear_activation(): void {
        delete_option( 'optee_activation_key' );
        delete_option( 'optee_activation_domain' );
        delete_option( 'optee_activation_type' );
        delete_option( 'optee_activation_expiry' );
        delete_option( 'optee_activation_name' );
        delete_option( 'optee_activation_email' );
        delete_option( 'optee_activated_at' );
    }

    /**
     * Validate key format and checksum locally (mirrors keys.ts logic).
     *
     * Format: OPTEE-XXXXX-XXXXX-XXXXX-XXXXX (A-Z, 0-9)
     * Checksum: sum ASCII of last char of each group (positions 4,9,14,19 of 20-char body),
     *           mod 36 must equal charToValue of first char.
     */
    public static function validate_key_format( string $key ): bool {
        if ( ! preg_match( '/^OPTEE-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/', $key ) ) {
            return false;
        }

        // Extract 20-char body (strip prefix and dashes).
        $raw = str_replace( [ 'OPTEE-', '-' ], '', $key );
        if ( strlen( $raw ) !== 20 ) {
            return false;
        }

        // Verify checksum.
        $checksum_positions = [ 4, 9, 14, 19 ];
        $checksum_sum       = 0;
        foreach ( $checksum_positions as $pos ) {
            $checksum_sum += ord( $raw[ $pos ] );
        }

        return self::char_to_value( $raw[0] ) === ( $checksum_sum % 36 );
    }

    /**
     * Map a char to its numeric value: A-Z = 0-25, 0-9 = 26-35.
     */
    private static function char_to_value( string $ch ): int {
        $code = ord( $ch );
        if ( $code >= 65 && $code <= 90 ) {
            return $code - 65; // A-Z
        }
        if ( $code >= 48 && $code <= 57 ) {
            return $code - 48 + 26; // 0-9
        }
        return -1;
    }

    /**
     * Get the current site domain (without protocol/trailing slash).
     */
    private static function get_domain(): string {
        return wp_parse_url( site_url(), PHP_URL_HOST ) ?: site_url();
    }
}
