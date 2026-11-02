<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin update & install manager.
 *
 * Checks api.optee.co for available updates and handles
 * downloading/installing/updating plugins from R2.
 *
 * Transients:
 *   optee_available_updates  — cached update check results (5 min TTL)
 *   optee_available_plugins  — list of plugins available on API (1 hour TTL)
 */
class Updater {

    private const API_BASE     = 'https://api.optee.co';
    private const UPDATES_TTL  = 300;   // 5 minutes
    private const AVAILABLE_TTL = 3600; // 1 hour

    /** Site option: pending tool access requests, [ tool_slug => requester_user_id ]. */
    private const PENDING_OPTION = 'optee_tool_pending_requests';

    /**
     * Ed25519 public key (hex) used to verify signed release packages before they
     * are extracted into WP_PLUGIN_DIR. The matching private key never leaves the
     * release machine (optee-api/signing-key.sec); a compromise of api.optee.co /
     * R2 / DNS therefore cannot forge an installable update.
     */
    private const RELEASE_PUBLIC_KEY = '3d9b2c6c3393ea8c11b01f25dc81f318f85a2d10c31eea45f5b1a4a0b5725ab7';

    /**
     * Get available updates (cached).
     *
     * @param bool $force_refresh  Bypass cache.
     * @return array{expired: bool, updates: array}
     */
    public static function get_available_updates( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( 'optee_available_updates' );
            if ( $cached !== false ) {
                return $cached;
            }
        }

        $result = self::check_updates_from_api();
        if ( $result ) {
            set_transient( 'optee_available_updates', $result, self::UPDATES_TTL );
        }

        return $result ?: [ 'expired' => false, 'updates' => [] ];
    }

    /**
     * Get plugins available for install from the API (cached).
     *
     * @return array  List of { slug, version }.
     */
    public static function get_available_plugins( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( 'optee_available_plugins' );
            if ( $cached !== false ) {
                return $cached;
            }
        }

        $response = wp_remote_get( self::API_BASE . '/api/available-plugins', [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $plugins = $body['plugins'] ?? [];

        set_transient( 'optee_available_plugins', $plugins, self::AVAILABLE_TTL );

        return $plugins;
    }

    /**
     * Get the private tools this install's license is granted (cached).
     *
     * Distinct from get_available_plugins(): tools live in the gated tools/ R2
     * channel and are returned only for license keys with an explicit grant. The
     * response drives the client Tools page and the optee_has_tools menu flag.
     *
     * @param bool $force_refresh  Bypass cache.
     * @return array  List of { slug, name, description, latest_version, installed,
     *                installed_version, update_available, changelog, download_url }.
     */
    public static function get_available_tools( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( 'optee_available_tools' );
            if ( $cached !== false ) {
                return $cached;
            }
        }

        $result = self::fetch_tools_from_api();

        // Only persist on a definitive answer (valid response). A transient network
        // error returns null — keep the last known menu flag rather than hiding it.
        if ( $result !== null ) {
            set_transient( 'optee_available_tools', $result, self::UPDATES_TTL );
            update_option( 'optee_has_tools', ! empty( $result ) ? '1' : '0', false );
            self::reconcile_tool_requests( $result );
        }

        return $result ?: [];
    }

    /**
     * Pending tool updates, shaped like the plugin-update entries the "Check for
     * updates" badge + popup consume, so private tools ride the same flow instead
     * of only surfacing on the Tools page. Keyed by slug; each is flagged is_tool
     * so the client routes the Update button through the tool updater. Skips
     * available-to-request tools (not installed) — only real installed updates.
     *
     * @return array<string,array>
     */
    public static function get_tool_updates( bool $force_refresh = false ): array {
        $out = [];
        foreach ( self::get_available_tools( $force_refresh ) as $t ) {
            if ( empty( $t['update_available'] ) || ! empty( $t['requestable'] ) ) {
                continue;
            }
            $slug = (string) ( $t['slug'] ?? '' );
            if ( $slug === '' ) {
                continue;
            }
            $out[ $slug ] = [
                'update_available' => true,
                'current_version'  => (string) ( $t['installed_version'] ?? '' ),
                'latest_version'   => (string) ( $t['latest_version'] ?? '' ),
                'changelog'        => (string) ( $t['changelog'] ?? '' ),
                // Truthy so the popup renders an Update button (tool updater keys off slug).
                'download_url'     => $t['download_url'] ?? '1',
                'display_name'     => self::pretty_tool_name( $slug, (string) ( $t['name'] ?? '' ) ),
                'type_label'       => 'Tool',
                'is_tool'          => true,
            ];
        }
        return $out;
    }

    /**
     * Request access to an available-to-all tool (POST /api/tool-request).
     * Auto-approve tools grant instantly (we raise the bell + email now);
     * others queue for admin approval and are caught later by
     * reconcile_tool_requests() on the next tools poll.
     *
     * @return array{status:string, message:string}  status ∈ approved|pending|error.
     */
    public static function request_tool( string $slug ): array {
        if ( ! preg_match( '/^optee(-[a-z0-9]+)*$/', $slug ) ) {
            return [ 'status' => 'error', 'message' => 'Invalid tool.' ];
        }
        $key = get_option( 'optee_activation_key', '' );
        if ( empty( $key ) ) {
            return [ 'status' => 'error', 'message' => 'This install is not activated.' ];
        }

        $response = wp_remote_post( self::API_BASE . '/api/tool-request', [
            'timeout' => 20,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'key' => $key, 'slug' => $slug ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'status' => 'error', 'message' => 'Request failed: ' . $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return [ 'status' => 'error', 'message' => 'Unexpected response from the server.' ];
        }
        if ( $code >= 400 || isset( $body['error'] ) ) {
            return [ 'status' => 'error', 'message' => $body['error'] ?? 'Request rejected.' ];
        }

        // Bypass the 5-min cache so a refreshed Tools page reflects the new grant.
        delete_transient( 'optee_available_tools' );

        if ( ! empty( $body['approved'] ) ) {
            self::clear_pending_request( $slug );
            update_option( 'optee_has_tools', '1', false );
            // Resolve the friendly name from the (now-granted) tools list so the
            // bell + email read "ProPush Traffic Demand", not the raw slug.
            self::notify_tool_resolved( $slug, get_current_user_id(), 'approved', self::tool_display_name( $slug ) );
            return [ 'status' => 'approved', 'message' => 'Access approved — you can install it now.' ];
        }

        self::mark_pending_request( $slug, get_current_user_id() );
        return [ 'status' => 'pending', 'message' => "Request sent. You'll be notified once it's approved." ];
    }

    private static function mark_pending_request( string $slug, int $user_id ): void {
        $pending = get_option( self::PENDING_OPTION, [] );
        if ( ! is_array( $pending ) ) {
            $pending = [];
        }
        $pending[ $slug ] = $user_id;
        update_option( self::PENDING_OPTION, $pending, false );
    }

    private static function clear_pending_request( string $slug ): void {
        $pending = get_option( self::PENDING_OPTION, [] );
        if ( is_array( $pending ) && isset( $pending[ $slug ] ) ) {
            unset( $pending[ $slug ] );
            update_option( self::PENDING_OPTION, $pending, false );
        }
    }

    /**
     * Fire bell + email for any pending tool request an admin has since resolved
     * (approved → a grant is now present; declined → request_status flips). Runs
     * after every successful tools fetch (Tools page + 5-min system task), so it
     * also covers approvals made while no one was on the Tools page.
     */
    private static function reconcile_tool_requests( array $tools ): void {
        $pending = get_option( self::PENDING_OPTION, [] );
        if ( ! is_array( $pending ) || empty( $pending ) ) {
            return;
        }

        $by_slug = [];
        foreach ( $tools as $t ) {
            if ( ! empty( $t['slug'] ) ) {
                $by_slug[ $t['slug'] ] = $t;
            }
        }

        $changed = false;
        foreach ( $pending as $slug => $user_id ) {
            $tool = $by_slug[ $slug ] ?? null;
            if ( $tool === null ) {
                continue; // not in this response — leave it pending.
            }
            if ( ! empty( $tool['granted'] ) ) {
                self::notify_tool_resolved( $slug, (int) $user_id, 'approved', $tool['name'] ?? $slug );
                unset( $pending[ $slug ] );
                $changed = true;
            } elseif ( ( $tool['request_status'] ?? '' ) === 'declined' ) {
                self::notify_tool_resolved( $slug, (int) $user_id, 'declined', $tool['name'] ?? $slug );
                unset( $pending[ $slug ] );
                $changed = true;
            }
        }

        if ( $changed ) {
            update_option( self::PENDING_OPTION, $pending, false );
        }
    }

    /**
     * Resolve a tool's friendly display name from the granted tools list (falls
     * back to '' when not found, so the caller/normalizer can prettify the slug).
     */
    private static function tool_display_name( string $slug ): string {
        foreach ( self::get_available_tools() as $t ) {
            if ( ( $t['slug'] ?? '' ) === $slug ) {
                return (string) ( $t['name'] ?? '' );
            }
        }
        return '';
    }

    /**
     * Human-readable tool name: strip the "Optee — " prefix from a real name, or
     * prettify the slug when no name is known ("optee-foo-bar" → "Foo Bar").
     */
    private static function pretty_tool_name( string $slug, string $name = '' ): string {
        $name = trim( $name );
        if ( $name !== '' ) {
            return preg_replace( '/^Optee\s*[-–—]\s*/iu', '', $name );
        }
        return ucwords( str_replace( '-', ' ', preg_replace( '/^optee-/', '', $slug ) ) );
    }

    /**
     * Raise the in-app bell notification + a transactional email to the requester
     * when a tool request is approved or declined.
     */
    private static function notify_tool_resolved( string $slug, int $user_id, string $status, string $name = '' ): void {
        if ( $user_id <= 0 ) {
            return;
        }
        $name = self::pretty_tool_name( $slug, $name );

        if ( $status === 'approved' ) {
            $title      = 'Tool access approved';
            $bell       = sprintf( '%s is ready to install — open Settings ▸ Tools.', $name );
            $subject    = sprintf( 'Your access to %s is approved', $name );
            $email_body = sprintf(
                'Good news — your request for <strong>%s</strong> has been approved. Open <strong>Settings &#9656; Tools</strong> in your Optee dashboard to install it.',
                esc_html( $name )
            );
        } else {
            $title      = 'Tool access declined';
            $bell       = sprintf( 'Your request for %s was declined.', $name );
            $subject    = sprintf( 'Update on your access request for %s', $name );
            $email_body = sprintf(
                'Your request for <strong>%s</strong> wasn\'t approved this time. If you think this is a mistake, just reply to this email or contact support.',
                esc_html( $name )
            );
        }

        Notifications::add( $user_id, [
            'type'    => $status === 'approved' ? 'success' : 'info',
            'title'   => $title,
            'message' => $bell,
            'time'    => current_time( 'mysql' ),
        ] );

        $user = get_userdata( $user_id );
        if ( $user && $user->user_email ) {
            $set_html = static function () {
                return 'text/html';
            };
            add_filter( 'wp_mail_content_type', $set_html );
            wp_mail(
                $user->user_email,
                $subject,
                '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#111827;">'
                    . '<p style="margin:0 0 12px;">' . $email_body . '</p>'
                    . '<p style="margin:0;color:#6b7280;font-size:12px;">— The Optee team</p>'
                . '</div>'
            );
            remove_filter( 'wp_mail_content_type', $set_html );
        }
    }

    /**
     * POST the license key to /api/tools and return the granted tools.
     *
     * @return array|null  Tools array (possibly empty) on success, null on failure.
     */
    private static function fetch_tools_from_api(): ?array {
        $key = get_option( 'optee_activation_key', '' );
        if ( empty( $key ) ) {
            update_option( 'optee_has_tools', '0', false );
            return [];
        }

        $response = wp_remote_post( self::API_BASE . '/api/tools', [
            'timeout' => 20,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'key'           => $key,
                'domain'        => wp_parse_url( site_url(), PHP_URL_HOST ),
                'optee_version' => defined( 'OPTEE_VERSION' ) ? OPTEE_VERSION : '',
                'installed'     => self::get_installed_optee_plugins(),
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            Logger::log( 'warning', 'updater', 'Failed to fetch tools: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! isset( $body['tools'] ) || ! is_array( $body['tools'] ) ) {
            return null;
        }

        return $body['tools'];
    }

    /**
     * Check API for updates across all installed Optee plugins.
     *
     * @return array|null  Null on failure.
     */
    private static function check_updates_from_api(): ?array {
        $key = get_option( 'optee_activation_key', '' );
        if ( empty( $key ) ) {
            return null;
        }

        $plugins = self::get_installed_optee_plugins();
        if ( empty( $plugins ) ) {
            return null;
        }

        $response = wp_remote_post( self::API_BASE . '/api/check-updates', [
            'timeout' => 20,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'key'            => $key,
                'domain'         => wp_parse_url( site_url(), PHP_URL_HOST ),
                'optee_version'  => defined( 'OPTEE_VERSION' ) ? OPTEE_VERSION : '',
                'plugins'        => $plugins,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            Logger::log( 'warning', 'updater', 'Failed to check for updates: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || ! isset( $body['updates'] ) ) {
            return null;
        }

        return [
            'expired'    => $body['expired'] ?? false,
            'updates'    => $body['updates'] ?? [],
            'checked_at' => current_time( 'mysql' ),
        ];
    }

    /**
     * Get all installed Optee plugins with their versions.
     *
     * @return array  [{ slug, version }]
     */
    public static function get_installed_optee_plugins(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $result = [];

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            // Match optee core or optee-* plugins.
            $dir = dirname( $plugin_file );
            if ( $dir === 'optee' || strpos( $dir, 'optee-' ) === 0 ) {
                $result[] = [
                    'slug'    => $dir,
                    'version' => $plugin_data['Version'] ?? '0.0.0',
                ];
            }
        }

        return $result;
    }

    /**
     * Count how many updates are available.
     */
    public static function get_update_count(): int {
        $data = self::get_available_updates();
        $count = 0;
        foreach ( $data['updates'] as $info ) {
            if ( ! empty( $info['update_available'] ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Install or update a plugin from the API.
     *
     * @param string $slug     Plugin slug (e.g., "optee-traffic-source-propellerads").
     * @param bool   $is_tool  When true, download from the gated private-tools
     *                         endpoint (/api/tool-download) instead of /api/download.
     * @return array{success: bool, message: string}
     */
    public static function install_plugin( string $slug, bool $is_tool = false ): array {
        // Defense-in-depth: only ever operate on well-formed optee slugs. Blocks
        // path traversal (e.g. "optee-../../x") and anything with slashes/dots
        // before the slug reaches a URL or a filesystem path.
        if ( ! preg_match( '/^optee(-[a-z0-9]+)*$/', $slug ) ) {
            return [ 'success' => false, 'message' => 'Invalid plugin slug.' ];
        }

        $key = get_option( 'optee_activation_key', '' );
        if ( empty( $key ) ) {
            return [ 'success' => false, 'message' => 'No activation key found.' ];
        }

        if ( ! function_exists( 'wp_tempnam' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Download zip from API to a temp file, keeping the response so we can read
        // the integrity/signature headers the download endpoint attaches. Private
        // tools come from the grant-gated tool-download endpoint; both sign the zip
        // the same way, so verify_package() below is identical for either channel.
        $endpoint     = $is_tool ? '/api/tool-download' : '/api/download';
        $download_url = self::API_BASE . $endpoint . '?key=' . urlencode( $key ) . '&slug=' . urlencode( $slug );

        $tmp_file = wp_tempnam( $slug . '.zip' );
        if ( ! $tmp_file ) {
            return [ 'success' => false, 'message' => 'Could not create a temporary file.' ];
        }

        $response = wp_remote_get( $download_url, [
            'timeout'  => 60,
            'stream'   => true,
            'filename' => $tmp_file,
        ] );

        if ( is_wp_error( $response ) ) {
            @unlink( $tmp_file );
            return [ 'success' => false, 'message' => 'Download failed: ' . $response->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            // On error the streamed file holds the API's JSON error body.
            $msg = 'Download failed (HTTP ' . $code . ').';
            $raw = @file_get_contents( $tmp_file );
            if ( $raw ) {
                $err = json_decode( $raw, true );
                if ( ! empty( $err['error'] ) ) {
                    $msg = 'Download failed: ' . $err['error'];
                }
            }
            @unlink( $tmp_file );
            return [ 'success' => false, 'message' => $msg ];
        }

        // Verify integrity (SHA-256) and authenticity (Ed25519 signature) BEFORE
        // extracting untrusted code into the plugins directory.
        $verify = self::verify_package( $tmp_file, $response );
        if ( ! $verify['ok'] ) {
            @unlink( $tmp_file );
            Logger::log( 'error', 'updater', "Package verification failed for {$slug}: {$verify['message']}", [ 'slug' => $slug ] );
            return [ 'success' => false, 'message' => 'Update rejected — ' . $verify['message'] ];
        }

        // Use WP filesystem to extract.
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        $result = unzip_file( $tmp_file, WP_PLUGIN_DIR );
        @unlink( $tmp_file );

        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'message' => 'Extraction failed: ' . $result->get_error_message() ];
        }

        // Clear update cache so the UI reflects the new state.
        delete_transient( 'optee_available_updates' );
        delete_transient( 'optee_available_plugins' );
        delete_transient( 'optee_available_tools' );

        // Resolve human-readable name from plugin headers.
        $display_name = $slug;
        $plugin_file  = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
        if ( file_exists( $plugin_file ) ) {
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $data = get_plugin_data( $plugin_file, false, false );
            if ( ! empty( $data['Name'] ) ) {
                $display_name = preg_replace( '/^Optee\s*[-–—]\s*/iu', '', $data['Name'] );
            }
        }

        Logger::log( 'info', 'updater', "{$display_name} installed/updated from API.", [ 'slug' => $slug ] );

        return [ 'success' => true, 'message' => "{$display_name} installed successfully." ];
    }

    /**
     * Verify a downloaded package before extraction.
     *
     * Two independent checks, both derived from headers the download endpoint
     * attaches from the signed latest.json:
     *   - X-Optee-Sha256    — content hash (catches corruption / R2-object tampering)
     *   - X-Optee-Signature — Ed25519 detached signature over the raw zip bytes,
     *                         verified against the bundled RELEASE_PUBLIC_KEY
     *                         (catches a forged package from a compromised API/DNS,
     *                         which cannot be signed without the offline private key)
     *
     * Fail-closed: if a signature is present it MUST verify. Legacy releases with
     * no signature are allowed unless the `optee_require_signed_updates` filter
     * returns true (flip it on once every plugin has been re-released signed).
     *
     * @param string $file     Path to the downloaded zip.
     * @param array  $response  The wp_remote_get response (for headers).
     * @return array{ok: bool, message: string}
     */
    private static function verify_package( string $file, array $response ): array {
        $bytes = @file_get_contents( $file );
        if ( $bytes === false || $bytes === '' ) {
            return [ 'ok' => false, 'message' => 'the downloaded file was empty.' ];
        }

        $expected_hash = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'x-optee-sha256' ) ) );
        $sig_b64       = trim( (string) wp_remote_retrieve_header( $response, 'x-optee-signature' ) );

        // 1. Content hash.
        if ( $expected_hash !== '' ) {
            if ( ! hash_equals( $expected_hash, hash( 'sha256', $bytes ) ) ) {
                return [ 'ok' => false, 'message' => 'checksum mismatch (the file was corrupted or altered in transit).' ];
            }
        }

        // 2. Signature (authenticity).
        if ( $sig_b64 !== '' ) {
            if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
                return [ 'ok' => false, 'message' => 'this server lacks the libsodium extension needed to verify the update signature.' ];
            }
            $sig = base64_decode( $sig_b64, true );
            $pub = @hex2bin( self::RELEASE_PUBLIC_KEY );
            if ( $sig === false || $pub === false || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
                return [ 'ok' => false, 'message' => 'the update signature was malformed.' ];
            }
            if ( ! sodium_crypto_sign_verify_detached( $sig, $bytes, $pub ) ) {
                return [ 'ok' => false, 'message' => 'the update signature was invalid (this package was not signed by Optee).' ];
            }
            return [ 'ok' => true, 'message' => 'verified' ];
        }

        // 3. No signature present (release built before signing was added).
        if ( (bool) apply_filters( 'optee_require_signed_updates', false ) ) {
            return [ 'ok' => false, 'message' => 'this update is not signed and signed updates are required.' ];
        }
        Logger::log( 'warning', 'updater', 'Installing an unsigned package (legacy release — no signature header).' );
        return [ 'ok' => true, 'message' => 'unsigned (legacy release)' ];
    }

    /**
     * Check if a specific slug is available for install from the API
     * (exists on API but not installed locally).
     */
    public static function is_available_for_install( string $slug ): bool {
        $available = self::get_available_plugins();
        foreach ( $available as $p ) {
            if ( $p['slug'] === $slug ) {
                return true;
            }
        }
        return false;
    }
}
