<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Activator {

    /**
     * Run on plugin activation.
     */
    public static function activate(): void {
        self::create_db_tables();
        self::create_dashboard_page();
        self::extract_theme();
        self::generate_cron_secret();
        self::set_options();
        self::disable_wp_cron();
        flush_rewrite_rules();
    }

    /**
     * Create custom DB tables.
     */
    private static function create_db_tables(): void {
        require_once OPTEE_PLUGIN_DIR . 'includes/db/class-db-manager.php';
        \Optee\Plugin\DB\DbManager::create_tables();
    }

    /**
     * Create the dashboard page with [optee_dashboard] shortcode.
     */
    private static function create_dashboard_page(): void {
        $page_id = get_option( 'optee_dashboard_page_id' );

        if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
            return;
        }

        $page_id = wp_insert_post( [
            'post_title'   => 'Optee Dashboard',
            'post_name'    => 'optee-dashboard',
            'post_content' => '[optee_dashboard]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id() ?: 1,
        ] );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'optee_dashboard_page_id', $page_id );
            update_option( 'page_on_front', $page_id );
            update_option( 'show_on_front', 'page' );
        }
    }

    /**
     * Extract bundled theme to wp-content/themes/optee/.
     */
    private static function extract_theme(): void {
        $source    = OPTEE_PLUGIN_DIR . 'theme/';
        $theme_root = get_theme_root();
        $dest      = $theme_root . '/optee/';

        if ( ! is_dir( $source ) ) {
            wp_die(
                '<h1>Optee Activation Failed</h1>'
                . '<p>The bundled theme directory was not found inside the plugin.</p>'
                . '<p><strong>Expected path:</strong> <code>' . esc_html( $source ) . '</code></p>'
                . '<p>This usually means the plugin zip was incomplete or corrupted. Please re-download and re-install.</p>'
                . '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; Back to Plugins</a></p>'
            );
        }

        if ( ! is_dir( $theme_root ) ) {
            wp_die(
                '<h1>Optee Activation Failed</h1>'
                . '<p>The WordPress themes directory does not exist.</p>'
                . '<p><strong>Expected path:</strong> <code>' . esc_html( $theme_root ) . '</code></p>'
                . '<p>Please check your WordPress installation and file permissions.</p>'
                . '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; Back to Plugins</a></p>'
            );
        }

        if ( ! is_writable( $theme_root ) ) {
            wp_die(
                '<h1>Optee Activation Failed</h1>'
                . '<p>The themes directory is not writable. Optee needs to install its bundled theme.</p>'
                . '<p><strong>Themes directory:</strong> <code>' . esc_html( $theme_root ) . '</code></p>'
                . '<p><strong>How to fix:</strong> Set directory permissions to <code>755</code> or contact your hosting provider.</p>'
                . '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; Back to Plugins</a></p>'
            );
        }

        if ( ! is_dir( $dest ) ) {
            $created = wp_mkdir_p( $dest );
            if ( ! $created ) {
                wp_die(
                    '<h1>Optee Activation Failed</h1>'
                    . '<p>Could not create the theme directory.</p>'
                    . '<p><strong>Tried to create:</strong> <code>' . esc_html( $dest ) . '</code></p>'
                    . '<p><strong>Themes directory writable:</strong> ' . ( is_writable( $theme_root ) ? 'Yes' : 'No' ) . '</p>'
                    . '<p><strong>How to fix:</strong> Manually create <code>wp-content/themes/optee/</code> with permissions <code>755</code>, then re-activate.</p>'
                    . '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; Back to Plugins</a></p>'
                );
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $errors = [];
        foreach ( $iterator as $item ) {
            $target = $dest . $iterator->getSubPathname();
            if ( $item->isDir() ) {
                if ( ! is_dir( $target ) ) {
                    wp_mkdir_p( $target );
                }
            } else {
                $copied = @copy( $item->getRealPath(), $target );
                if ( ! $copied ) {
                    $errors[] = $iterator->getSubPathname();
                }
            }
        }

        if ( ! empty( $errors ) ) {
            wp_die(
                '<h1>Optee Activation Failed</h1>'
                . '<p>Could not copy some theme files to <code>' . esc_html( $dest ) . '</code>.</p>'
                . '<p><strong>Failed files:</strong></p>'
                . '<ul><li><code>' . implode( '</code></li><li><code>', array_map( 'esc_html', $errors ) ) . '</code></li></ul>'
                . '<p><strong>How to fix:</strong> Check that <code>wp-content/themes/optee/</code> has write permissions (<code>755</code>).</p>'
                . '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; Back to Plugins</a></p>'
            );
        }

        // Verify style.css exists before switching (WP requires it).
        if ( ! file_exists( $dest . 'style.css' ) ) {
            wp_die(
                '<h1>Optee Activation Failed</h1>'
                . '<p>Theme files were copied but <code>style.css</code> is missing.</p>'
                . '<p><strong>Theme directory:</strong> <code>' . esc_html( $dest ) . '</code></p>'
                . '<p>Please re-download and re-install the plugin.</p>'
                . '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; Back to Plugins</a></p>'
            );
        }

        // Activate the theme.
        switch_theme( 'optee' );
    }

    /**
     * Generate a random cron secret token.
     */
    private static function generate_cron_secret(): void {
        if ( ! get_option( 'optee_cron_secret' ) ) {
            update_option( 'optee_cron_secret', wp_generate_password( 32, false ) );
        }
    }

    /**
     * Set plugin version options.
     */
    private static function set_options(): void {
        update_option( 'optee_version', OPTEE_VERSION );
        update_option( 'optee_db_version', OPTEE_DB_VERSION );
    }

    /**
     * Add DISABLE_WP_CRON constant to wp-config.php.
     */
    private static function disable_wp_cron(): void {
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            return;
        }

        $config_path = ABSPATH . 'wp-config.php';
        if ( ! is_writable( $config_path ) ) {
            return;
        }

        $config = file_get_contents( $config_path );
        if ( strpos( $config, 'DISABLE_WP_CRON' ) !== false ) {
            return;
        }

        $marker  = "/* That's all, stop editing!";
        $insert  = "define( 'DISABLE_WP_CRON', true ); // Added by Optee\n\n";
        $config  = str_replace( $marker, $insert . $marker, $config );

        file_put_contents( $config_path, $config, LOCK_EX );
    }
}
