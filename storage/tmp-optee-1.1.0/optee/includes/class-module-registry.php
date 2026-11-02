<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Optee\Plugin\Modules\ModuleInterface;

/**
 * Module registry — discovers and manages all registered modules.
 */
class ModuleRegistry {

    private static ?self $instance = null;

    /** @var ModuleInterface[] Indexed by {type}:{slug}. */
    private array $modules = [];

    /** Preapproved integration slugs. Only these modules are accepted. */
    private const APPROVED_SLUGS = [
        'propellerads',
        'rollerads',
        'popads',
        'popcash',
        'mondiad',
        'galaksion',
        'adsterra',
        'adcash',
        'bemob',
        'maxconv',
        'skro',
        'voluum',
        'funnelflux',
        'adscore',
        'virustotal',
        'monetizer',
        'ogads',
        'zeydoo',
        'mobipium',
        'traffic-company',
        'monetag',
        'propush',
        'partners-house',
        'rollerads-publisher',
        'hilltopads',
        'hilltopads-publisher',
        'pushhouse',
        'bidvertiser',
        'clickadu',
        'clickflare',
        'advertizer',
        'adsbridge',
        'mylead',
        'cpalead',
        'cpagrip',
        'clickadilla',
        'cpicash',
        'binom',
        'simplekuma',
        'adoperator',
        'metricanic',
        'pinup',
        'redtrack',
        'peerclick',
        'cpvone',
        'cpvlabpro',
    ];

    private function __construct() {}

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Trigger module discovery via action.
     */
    public function discover(): void {
        do_action( 'optee_register_modules', $this );
    }

    /**
     * Register a module.
     */
    public function register( ModuleInterface $module ): void {
        if ( ! in_array( $module->get_slug(), self::APPROVED_SLUGS, true ) ) {
            return;
        }
        $key = $module->get_type() . ':' . $module->get_slug();
        $this->modules[ $key ] = $module;
    }

    /**
     * Get a module by slug, optionally filtered by type.
     */
    public function get_module( string $slug, ?string $type = null ): ?ModuleInterface {
        if ( $type !== null ) {
            return $this->modules[ $type . ':' . $slug ] ?? null;
        }
        foreach ( $this->modules as $module ) {
            if ( $module->get_slug() === $slug ) {
                return $module;
            }
        }
        return null;
    }

    /**
     * Get all registered modules.
     *
     * @return ModuleInterface[]
     */
    public function get_modules(): array {
        return $this->modules;
    }

    /**
     * Get modules filtered by type.
     *
     * @param string $type traffic_source|tracker|affiliate_network|antifraud_tool|monetization_platform
     * @return ModuleInterface[]
     */
    public function get_modules_by_type( string $type ): array {
        return array_filter(
            $this->modules,
            fn( ModuleInterface $m ) => $m->get_type() === $type
        );
    }

    /**
     * Get a module with credentials loaded for the current user.
     */
    public function get_module_with_credentials( string $slug, ?string $type = null ): ?ModuleInterface {
        $module = $this->get_module( $slug, $type );
        if ( ! $module ) {
            return null;
        }

        $credentials = self::get_stored_credentials( $module->get_type(), $slug );
        if ( $credentials ) {
            $module->set_credentials( $credentials );
        }

        return $module;
    }

    /**
     * Encrypt and store credentials for the current user.
     */
    public static function store_credentials( string $type, string $slug, array $credentials ): bool {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        $encrypted = self::encrypt( wp_json_encode( $credentials ) );
        if ( $encrypted === false ) {
            return false;
        }

        return (bool) update_user_meta( $user_id, "optee_credentials_{$type}_{$slug}", $encrypted );
    }

    /**
     * Retrieve and decrypt credentials for the current user.
     */
    public static function get_stored_credentials( string $type, string $slug ): ?array {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return null;
        }

        $encrypted = get_user_meta( $user_id, "optee_credentials_{$type}_{$slug}", true );
        if ( empty( $encrypted ) ) {
            return null;
        }

        $decrypted = self::decrypt( $encrypted );
        if ( $decrypted === false ) {
            return null;
        }

        $data = json_decode( $decrypted, true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Delete credentials for the current user.
     */
    public static function delete_credentials( string $type, string $slug ): bool {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        return delete_user_meta( $user_id, "optee_credentials_{$type}_{$slug}" );
    }

    /**
     * Public helper: encrypt an arbitrary short string (e.g. a cached API token)
     * with the same key/scheme used for stored credentials, so module plugins
     * never persist secrets in plaintext (transients, etc.). False on failure.
     */
    public static function encrypt_value( string $plaintext ): string|false {
        return self::encrypt( $plaintext );
    }

    /**
     * Public counterpart to encrypt_value(). False if decryption fails.
     */
    public static function decrypt_value( string $ciphertext ): string|false {
        return self::decrypt( $ciphertext );
    }

    /**
     * Encrypt a string using openssl and WP auth salt.
     */
    private static function encrypt( string $plaintext ): string|false {
        $key    = self::get_encryption_key();
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

        if ( $cipher === false ) {
            return false;
        }

        return base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a string.
     */
    private static function decrypt( string $ciphertext ): string|false {
        $key  = self::get_encryption_key();
        $data = base64_decode( $ciphertext, true );

        if ( $data === false || strlen( $data ) < 17 ) {
            return false;
        }

        $iv     = substr( $data, 0, 16 );
        $cipher = substr( $data, 16 );

        return openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
    }

    /**
     * Derive an encryption key from WP auth salt.
     */
    private static function get_encryption_key(): string {
        if ( ! defined( 'AUTH_SALT' ) || AUTH_SALT === '' ) {
            throw new \RuntimeException( 'AUTH_SALT is not defined in wp-config.php. Cannot encrypt/decrypt credentials.' );
        }
        $salt = AUTH_SALT;
        return hash( 'sha256', $salt, true );
    }
}
