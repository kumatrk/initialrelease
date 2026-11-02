<?php
/**
 * Plugin Name: Optee
 * Plugin URI:  https://optee.co
 * Description: Affiliate campaign monitoring & optimization dashboard.
 * Version:     1.1.0
 * Author:      Optee
 * Author URI:  https://optee.co
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: optee
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OPTEE_VERSION', '1.1.0' );
define( 'OPTEE_DB_VERSION', '1.8.0' );
define( 'OPTEE_PLUGIN_FILE', __FILE__ );
define( 'OPTEE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPTEE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OPTEE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4-style autoloader.
 * Maps Optee\Plugin\{Sub}\ClassName → includes/{sub}/class-class-name.php
 * Also handles interface/abstract files in modules/ directory.
 */
spl_autoload_register( function ( string $class ) {
    $prefix = 'Optee\\Plugin\\';

    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    // Explicit mapping for non-standard filenames (interfaces, abstracts).
    static $map = [
        'Modules\\ModuleInterface'            => 'modules/interface-module.php',
        'Modules\\AbstractTrafficSource'      => 'modules/abstract-traffic-source.php',
        'Modules\\AbstractTracker'            => 'modules/abstract-tracker.php',
        'Modules\\AbstractAntifraudTool'      => 'modules/abstract-antifraud-tool.php',
        'Modules\\AbstractAffiliateNetwork'   => 'modules/abstract-affiliate-network.php',
        'Modules\\AbstractMonetizationPlatform' => 'modules/abstract-monetization-platform.php',
    ];

    $relative = substr( $class, strlen( $prefix ) );

    // Check explicit map first.
    if ( isset( $map[ $relative ] ) ) {
        require_once OPTEE_PLUGIN_DIR . 'includes/' . $map[ $relative ];
        return;
    }

    $parts    = explode( '\\', $relative );
    $filename = array_pop( $parts );

    // Convert CamelCase to kebab-case: CampaignCpt → campaign-cpt
    $filename = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $filename ) );
    $filename = 'class-' . $filename . '.php';

    // Convert namespace parts to lowercase directory names
    $subdir = '';
    if ( ! empty( $parts ) ) {
        $subdir = strtolower( implode( '/', $parts ) ) . '/';
    }

    $file = OPTEE_PLUGIN_DIR . 'includes/' . $subdir . $filename;

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

/**
 * Activation hook.
 */
register_activation_hook( __FILE__, function () {
    require_once OPTEE_PLUGIN_DIR . 'includes/class-activator.php';
    \Optee\Plugin\Activator::activate();
} );

/**
 * Redirect to dashboard after activation.
 */
add_action( 'activated_plugin', function ( $plugin ) {
    if ( $plugin !== plugin_basename( __FILE__ ) ) {
        return;
    }
    $page_id = get_option( 'optee_dashboard_page_id' );
    if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
        wp_safe_redirect( add_query_arg( 'module', 'setup-wizard', get_permalink( $page_id ) ) );
        exit;
    }
} );

/**
 * Deactivation hook.
 */
register_deactivation_hook( __FILE__, function () {
    require_once OPTEE_PLUGIN_DIR . 'includes/class-deactivator.php';
    \Optee\Plugin\Deactivator::deactivate();
} );

/**
 * Boot the plugin.
 */
add_action( 'plugins_loaded', function () {
    // Sync version option after plugin file update (e.g., auto-update).
    if ( get_option( 'optee_version' ) !== OPTEE_VERSION ) {
        update_option( 'optee_version', OPTEE_VERSION );
        // Clear all cache busters so users get fresh CSS/JS.
        global $wpdb;
        $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'optee_cache_buster' ] );
    }

    $plugin = \Optee\Plugin\Optee::get_instance();
    $plugin->run();
}, 10 );
