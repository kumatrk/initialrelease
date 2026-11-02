<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shortcode {

    /**
     * Valid module routes.
     */
    private const ROUTES = [
        'dashboard'              => 'dashboard/main.php',
        'dashboard-campaign'     => 'dashboard/campaign.php',
        'traffic-sources'           => 'modules/traffic-sources.php',
        'traffic-sources-configure' => 'modules/traffic-sources-configure.php',
        'trackers'               => 'modules/trackers.php',
        'trackers-configure'     => 'modules/trackers-configure.php',
        'affiliate-networks'     => 'modules/affiliate-networks.php',
        'affiliate-networks-configure' => 'modules/affiliate-networks-configure.php',
        'antifraud-tools'        => 'modules/antifraud-tools.php',
        'antifraud-tools-configure' => 'modules/antifraud-tools-configure.php',
        'monetization-platforms'  => 'modules/monetization-platforms.php',
        'monetization-platforms-configure' => 'modules/monetization-platforms-configure.php',
        'balances'               => 'modules/balances.php',
        'earnings'               => 'modules/earnings.php',
        'campaigns'              => 'campaigns/list.php',
        'campaigns-create'       => 'campaigns/create.php',
        'campaigns-edit'         => 'campaigns/edit.php',
        'rules'                  => 'rules/list.php',
        'rules-create'           => 'rules/create.php',
        'rules-edit'             => 'rules/edit.php',
        'alerts'                 => 'alerts/index.php',
        'bonuses'                => 'bonuses/index.php',
        'promo-codes'            => 'promo/index.php',
        'settings-profile'       => 'settings/profile.php',
        'settings-status'        => 'settings/status.php',
        'settings-license'       => 'settings/license.php',
        'logs'                   => 'logs/list.php',
        'logs-notifications'     => 'logs/notifications.php',
        'knowledgebase'          => 'knowledgebase/index.php',
        'knowledgebase-article'  => 'knowledgebase/article.php',
        'zone-database'          => 'zone-database/index.php',
        'virustotal'             => 'virustotal/index.php',
        'virustotal-configure'   => 'virustotal/configure.php',
        'offers'                 => 'offers/index.php',
        'insights'               => 'insights/index.php',
        'landersync'             => 'tools/landersync.php',
        'tools'                  => 'tools/index.php',
        'setup-wizard'           => 'wizard/wizard.php',
    ];

    /**
     * Register the shortcode.
     */
    public function init(): void {
        add_shortcode( 'optee_dashboard', [ $this, 'render' ] );
    }

    /**
     * Render the dashboard.
     */
    public function render( $atts = [] ): string {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '';
        }

        $module = isset( $_GET['module'] ) ? sanitize_key( $_GET['module'] ) : 'dashboard';
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

        // Resolve the route.
        $route_key = $module;
        if ( $action ) {
            $route_key = $module . '-' . $action;
        }

        // Allow extension plugins to register additional dashboard routes.
        // A route value may be a path relative to the core templates/ directory,
        // or an absolute filesystem path (so a plugin can ship its own template).
        $routes = apply_filters( 'optee_dashboard_routes', self::ROUTES );

        if ( ! isset( $routes[ $route_key ] ) ) {
            $route_key = 'dashboard';
        }

        $template_file = $routes[ $route_key ];
        $current_module = $module;
        $current_action = $action;

        // Get module registry for templates to use.
        $registry = ModuleRegistry::get_instance();

        // Detect conflicting caching/optimization plugins.
        $conflicting_plugins = self::detect_conflicting_plugins();

        // Prevent caching plugins from caching the dashboard.
        if ( ! headers_sent() ) {
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
        }

        ob_start();

        // Setup wizard uses standalone layout (no sidebar/topbar).
        if ( $route_key === 'setup-wizard' ) {
            include OPTEE_PLUGIN_DIR . 'templates/' . $template_file;
        } else {
            include OPTEE_PLUGIN_DIR . 'templates/dashboard/layout.php';
        }

        return ob_get_clean();
    }

    /**
     * Get the dashboard URL with optional module/action.
     */
    public static function get_url( string $module = '', string $action = '', array $params = [] ): string {
        $page_id = get_option( 'optee_dashboard_page_id' );
        $url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );

        if ( $module ) {
            $params['module'] = $module;
        }
        if ( $action ) {
            $params['action'] = $action;
        }

        return ! empty( $params ) ? add_query_arg( $params, $url ) : $url;
    }

    /**
     * Detect active plugins known to conflict with Optee's dynamic dashboard.
     *
     * @return array [ [ 'slug' => '...', 'name' => '...' ], ... ]
     */
    private static function detect_conflicting_plugins(): array {
        $known = [
            'litespeed-cache/litespeed-cache.php'           => 'LiteSpeed Cache',
            'wp-super-cache/wp-cache.php'                   => 'WP Super Cache',
            'w3-total-cache/w3-total-cache.php'             => 'W3 Total Cache',
            'wp-fastest-cache/wpFastestCache.php'           => 'WP Fastest Cache',
            'wp-rocket/wp-rocket.php'                       => 'WP Rocket',
            'breeze/breeze.php'                             => 'Breeze (Cloudways)',
            'sg-cachepress/sg-cachepress.php'               => 'SiteGround Optimizer',
            'hummingbird-performance/wp-hummingbird.php'    => 'Hummingbird',
            'cache-enabler/cache-enabler.php'               => 'Cache Enabler',
            'comet-cache/comet-cache.php'                   => 'Comet Cache',
            'powered-cache/powered-cache.php'               => 'Powered Cache',
            'nitropack/main.php'                            => 'NitroPack',
            'autoptimize/autoptimize.php'                   => 'Autoptimize',
            'swift-performance-lite/performance.php'        => 'Swift Performance Lite',
            'swift-performance/performance.php'             => 'Swift Performance',
            'flying-press/flying-press.php'                 => 'FlyingPress',
            'wp-optimize/wp-optimize.php'                   => 'WP-Optimize',
        ];

        $active = [];

        foreach ( $known as $slug => $name ) {
            if ( is_plugin_active( $slug ) ) {
                $active[] = [ 'slug' => $slug, 'name' => $name ];
            }
        }

        return $active;
    }
}
