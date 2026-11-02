<?php

namespace Optee\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Optee {

    private static ?self $instance = null;

    private function __construct() {}

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Boot all plugin components.
     */
    public function run(): void {
        // Auto-upgrade DB tables when version changes.
        $this->maybe_upgrade_db();

        // Register CPTs.
        ( new CPT\CampaignCpt() )->register();
        ( new CPT\RuleCpt() )->register();
        ( new CPT\AlertCpt() )->register();

        // Access control.
        ( new AccessControl() )->init();

        // Shortcode routing.
        ( new Shortcode() )->init();

        // AJAX handlers.
        ( new AjaxHandler() )->init();

        // Cron handler.
        ( new CronHandler() )->init();

        // Setup wizard.
        ( new SetupWizard() )->init();

        // Module discovery.
        add_action( 'init', [ $this, 'discover_modules' ], 20 );

        // Count VirusTotal requests the module makes outside the scan loop
        // (configure-page key badges, connection tests, quota snapshots). Without
        // this the usage chart only ever sees cron traffic and blames the shortfall
        // on other tenants of the server's IP.
        // Two args: kind and egress route. accepted_args MUST stay 2 — with 1 the
        // route silently arrives as its default and every call counts as direct,
        // which makes the per-route readout quietly wrong rather than broken.
        add_action( 'optee_vt_api_call', [ SystemTasks::class, 'count_module_vt_call' ], 10, 2 );

        // One-time per-campaign custom-columns backfill (after CPTs exist).
        add_action( 'init', [ $this, 'maybe_migrate_custom_columns' ], 25 );

        // Enqueue assets.
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Dashboard favicon.
        add_action( 'wp_head', [ $this, 'dashboard_favicon' ], 2 );
        add_action( 'login_head', [ $this, 'dashboard_favicon' ], 2 );

        // Set page template for the dashboard page.
        add_filter( 'page_template', [ $this, 'set_dashboard_template' ] );

        // Custom login page.
        add_action( 'login_enqueue_scripts', [ $this, 'login_styles' ] );
        add_filter( 'login_headerurl', fn() => home_url( '/' ) );
        add_filter( 'login_headertext', fn() => 'Optee' );

        // Plugins-page: intercept Delete with a "keep or wipe data?" modal.
        add_action( 'admin_footer-plugins.php', [ $this, 'render_uninstall_modal' ] );

        // LanderSync menu entry. Registered as a late filter rather than a static
        // entry in the sidebar array so it lands BELOW the tool plugins, which
        // splice themselves in just before the 'System' separator at priorities
        // 10-14. A static entry would sit above them.
        add_filter( 'optee_sidebar_nav_items', [ $this, 'add_landersync_nav_item' ], 99 );
        add_filter( 'optee_mobile_nav_items', [ $this, 'add_landersync_nav_item' ], 99 );

        // Standalone printable campaign report (?module=dashboard&action=campaign-report).
        // Rendered as its own clean HTML document — bypasses the theme so the PDF has no
        // site header/footer/sidebar chrome.
        add_action( 'template_redirect', [ $this, 'maybe_render_campaign_report' ] );
    }

    /**
     * Append the LanderSync entry to the bottom of the Tools group.
     *
     * Shared by the sidebar and the mobile menu (the mobile template ignores the
     * icon key). Placement: just before the 'System' separator; falls back to
     * after 'insights', then to a plain append.
     */
    public function add_landersync_nav_item( array $items ): array {
        $entry = [
            'module' => 'landersync',
            'label'  => 'LanderSync',
            'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 13v8"/><path d="M4.393 15.269A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.436 8.284"/><path d="m8 17 4-4 4 4"/></svg>',
        ];

        $out = [];
        foreach ( $items as $item ) {
            if ( ! empty( $item['separator'] ) && ( $item['label'] ?? '' ) === 'System' ) {
                $out[] = $entry;
                $entry = null;
            }
            $out[] = $item;
        }

        if ( $entry !== null ) {
            $out = [];
            foreach ( $items as $item ) {
                $out[] = $item;
                if ( $entry !== null && ( $item['module'] ?? '' ) === 'insights' ) {
                    $out[] = $entry;
                    $entry = null;
                }
            }
        }

        if ( $entry !== null ) {
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Intercept the dashboard page's campaign-report route and output a standalone
     * printable document, then exit before the theme renders.
     */
    public function maybe_render_campaign_report(): void {
        if ( ! $this->is_dashboard_page() ) {
            return;
        }
        if ( ( $_GET['module'] ?? '' ) !== 'dashboard' || ( $_GET['action'] ?? '' ) !== 'campaign-report' ) {
            return;
        }
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not authorized.', 'Optee', [ 'response' => 403 ] );
        }

        nocache_headers();
        include OPTEE_PLUGIN_DIR . 'templates/dashboard/campaign-report.php';
        exit;
    }

    /**
     * Inject a "keep or wipe data?" modal that intercepts the Delete link
     * for the Optee plugin. The choice is persisted via AJAX so uninstall.php
     * can read it.
     */
    public function render_uninstall_modal(): void {
        if ( ! current_user_can( 'delete_plugins' ) ) {
            return;
        }
        $basename = OPTEE_PLUGIN_BASENAME;          // e.g. "optee/optee.php"
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'optee_nonce' );
        ?>
        <div id="optee-uninstall-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:10px;padding:28px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    <div style="font-weight:600;font-size:16px;">Delete Optee plugin?</div>
                </div>
                <p style="color:#4b5563;line-height:1.5;font-size:13px;margin:0 0 16px;">
                    What should happen to your saved data — campaigns, rules, traffic-source / tracker configurations, scan history, and user settings?
                </p>
                <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:18px;">
                    <button type="button" id="optee-uninstall-keep" class="button button-primary" style="text-align:left;padding:10px 14px;height:auto;">
                        <strong>Keep my data</strong><br>
                        <span style="font-weight:400;opacity:0.85;font-size:12px;">Recommended. Re-installing later restores everything.</span>
                    </button>
                    <button type="button" id="optee-uninstall-wipe" class="button" style="text-align:left;padding:10px 14px;height:auto;color:#b91c1c;border-color:#fecaca;">
                        <strong>Remove all data</strong><br>
                        <span style="font-weight:400;opacity:0.85;font-size:12px;">Drops campaigns, rules, scans, options, user meta. Cannot be undone.</span>
                    </button>
                </div>
                <div style="text-align:right;">
                    <button type="button" id="optee-uninstall-cancel" class="button-link">Cancel</button>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var basename = <?php echo wp_json_encode( $basename ); ?>;
            var ajaxUrl  = <?php echo wp_json_encode( $ajax_url ); ?>;
            var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
            var modal    = document.getElementById('optee-uninstall-modal');
            var pendingHref = null;

            // Intercept the Delete link for our plugin only.
            document.addEventListener('click', function(e) {
                var link = e.target.closest('a.delete');
                if (!link) return;
                var href = link.getAttribute('href') || '';
                // Plugin row delete links contain "plugin=<urlencoded basename>".
                if (href.indexOf('plugin=' + encodeURIComponent(basename)) === -1) return;
                e.preventDefault();
                e.stopImmediatePropagation();
                pendingHref = href;
                modal.style.display = 'flex';
            }, true);

            function setActionAndProceed(action) {
                var body = new URLSearchParams();
                body.set('action', 'optee_set_uninstall_action');
                body.set('uninstall_action', action);
                body.set('nonce', nonce);
                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function(){
                    if (pendingHref) window.location.href = pendingHref;
                });
            }

            document.getElementById('optee-uninstall-keep').addEventListener('click', function(){ setActionAndProceed('keep'); });
            document.getElementById('optee-uninstall-wipe').addEventListener('click', function(){
                if (!confirm('Really remove ALL Optee data? This cannot be undone.')) return;
                setActionAndProceed('wipe');
            });
            document.getElementById('optee-uninstall-cancel').addEventListener('click', function(){
                modal.style.display = 'none';
                pendingHref = null;
            });
            modal.addEventListener('click', function(e){
                if (e.target === modal) { modal.style.display = 'none'; pendingHref = null; }
            });
        })();
        </script>
        <?php
    }

    /**
     * Discover registered modules.
     */
    public function discover_modules(): void {
        ModuleRegistry::get_instance()->discover();
    }

    /**
     * Enqueue frontend assets on the dashboard page.
     */
    public function enqueue_assets(): void {
        if ( ! $this->is_dashboard_page() ) {
            return;
        }

        $cache_buster = get_user_meta( get_current_user_id(), 'optee_cache_buster', true );
        $version      = $cache_buster ?: OPTEE_VERSION;

        // CSS
        wp_enqueue_style(
            'optee-dashboard',
            OPTEE_PLUGIN_URL . 'assets/css/optee-dashboard.css',
            [],
            $version
        );

        // Core JS
        wp_enqueue_script( 'jquery' );

        wp_enqueue_script(
            'optee-dashboard',
            OPTEE_PLUGIN_URL . 'assets/js/optee-dashboard.js',
            [ 'jquery' ],
            $version,
            true
        );

        wp_enqueue_script(
            'optee-campaigns',
            OPTEE_PLUGIN_URL . 'assets/js/optee-campaigns.js',
            [ 'jquery', 'optee-dashboard' ],
            $version,
            true
        );

        wp_enqueue_script(
            'optee-rules',
            OPTEE_PLUGIN_URL . 'assets/js/optee-rules.js',
            [ 'jquery', 'optee-dashboard' ],
            $version,
            true
        );

        wp_enqueue_script(
            'optee-alerts',
            OPTEE_PLUGIN_URL . 'assets/js/optee-alerts.js',
            [ 'jquery', 'optee-dashboard' ],
            $version,
            true
        );

        wp_enqueue_script(
            'optee-campaign-stats',
            OPTEE_PLUGIN_URL . 'assets/js/optee-campaign-stats.js',
            [ 'jquery', 'optee-dashboard' ],
            $version,
            true
        );

        wp_enqueue_script(
            'optee-notifications',
            OPTEE_PLUGIN_URL . 'assets/js/optee-notifications.js',
            [ 'jquery', 'optee-dashboard' ],
            $version,
            true
        );

        // Bonus hints appear on integration cards, configure pages and the
        // Bonuses page itself, which span most of the dashboard — cheaper to load
        // once than to gate per route.
        wp_enqueue_script(
            'optee-bonus-hint',
            OPTEE_PLUGIN_URL . 'assets/js/optee-bonus-hint.js',
            [ 'jquery', 'optee-dashboard' ],
            $version,
            true
        );

        // Slug-keyed offers for the campaign-form toast. Restricted to bonuses
        // claimable by someone who already has the account — a registration-only
        // discount is an interruption at that point, not an opportunity. Small
        // enough (a dozen entries, five fields each) to ship inline rather than
        // fetch per selection.
        wp_localize_script( 'optee-bonus-hint', 'opteeBonusData', [
            'campaign'   => Bonuses::js_map( [ 'topup', 'both' ] ),
            'bonusesUrl' => Shortcode::get_url( 'bonuses' ),
        ] );

        wp_enqueue_script(
            'optee-command-palette',
            OPTEE_PLUGIN_URL . 'assets/js/optee-command-palette.js',
            [ 'jquery', 'optee-dashboard' ],
            $version,
            true
        );

        // Driver.js product tour.
        wp_enqueue_style(
            'driver-js',
            OPTEE_PLUGIN_URL . 'assets/vendor/driver.js/driver.css',
            [],
            '1.3.1'
        );
        wp_enqueue_script(
            'driver-js',
            OPTEE_PLUGIN_URL . 'assets/vendor/driver.js/driver.js.iife.js',
            [],
            '1.3.1',
            true
        );
        wp_enqueue_script(
            'optee-tour',
            OPTEE_PLUGIN_URL . 'assets/js/optee-tour.js',
            [ 'jquery', 'optee-dashboard', 'driver-js' ],
            $version,
            true
        );

        // Setup wizard JS.
        $current_module = isset( $_GET['module'] ) ? sanitize_key( $_GET['module'] ) : '';
        if ( $current_module === 'setup-wizard' ) {
            wp_enqueue_script(
                'optee-wizard',
                OPTEE_PLUGIN_URL . 'assets/js/optee-wizard.js',
                [ 'jquery', 'optee-dashboard' ],
                $version,
                true
            );

            // Timezone match check.
            $wp_tz_string  = get_option( 'timezone_string', '' );
            $wp_gmt_offset = get_option( 'gmt_offset', 0 );
            if ( $wp_tz_string ) {
                $wp_tz = new \DateTimeZone( $wp_tz_string );
            } else {
                $off = (float) $wp_gmt_offset;
                $sign = $off >= 0 ? '+' : '-';
                $abs  = abs( $off );
                $wp_tz = new \DateTimeZone( sprintf( '%s%02d:%02d', $sign, intval( $abs ), ( $abs - intval( $abs ) ) * 60 ) );
            }
            $wp_offset_sec = $wp_tz->getOffset( new \DateTime( 'now', $wp_tz ) );

            // Cron confirmed check — persistent flag set by cPanel setup or poll verification.
            $cron_confirmed = get_option( 'optee_cron_setup_done', '' ) === '1';

            // Also detect cron from last run time (covers manual cPanel setup).
            $cron_last_run = get_option( 'optee_cron_last_run', '' );
            if ( ! $cron_confirmed && ! empty( $cron_last_run ) ) {
                $cron_confirmed = true;
            }

            wp_localize_script( 'optee-wizard', 'opteeWizard', [
                'currentStep'     => (int) get_option( 'optee_setup_step', 1 ),
                'isActivated'     => Activation::is_activated(),
                'isEmailVerified' => get_option( 'optee_email_verified', '' ) === '1',
                'isCronConfirmed' => $cron_confirmed,
                'wpOffsetSec'     => $wp_offset_sec,
                'cronUrl'         => CronHandler::get_cron_url( true ),
                'adminEmail'      => get_option( 'admin_email', '' ),
                'dashboardUrl'    => Shortcode::get_url(),
                'setupComplete'   => get_option( 'optee_setup_complete', '' ) === '1',
            ] );
        }

        // Balances page JS.
        if ( $current_module === 'balances' ) {
            wp_enqueue_script(
                'optee-balances',
                OPTEE_PLUGIN_URL . 'assets/js/optee-balances.js',
                [ 'jquery', 'optee-dashboard' ],
                $version,
                true
            );
        }

        // Earnings page JS.
        if ( $current_module === 'earnings' ) {
            wp_enqueue_script(
                'optee-earnings',
                OPTEE_PLUGIN_URL . 'assets/js/optee-earnings.js',
                [ 'jquery', 'optee-dashboard' ],
                $version,
                true
            );
        }

        // Localize script with AJAX URL and nonce.
        $heartbeat = intval( get_user_meta( get_current_user_id(), 'optee_heartbeat_interval', true ) ) ?: 10;
        $mon_slugs = array_map(
            fn( $m ) => $m->get_slug(),
            array_values( ModuleRegistry::get_instance()->get_modules_by_type( 'monetization_platform' ) )
        );
        wp_localize_script( 'optee-dashboard', 'opteeData', [
            'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'optee_nonce' ),
            'dashboardUrl'      => Shortcode::get_url(),
            'currentUser'       => get_current_user_id(),
            'heartbeatInterval' => max( 10, $heartbeat ),
            'statsDecimals'     => metadata_exists( 'user', get_current_user_id(), 'optee_stats_decimals' )
                ? max( 0, min( 5, intval( get_user_meta( get_current_user_id(), 'optee_stats_decimals', true ) ) ) )
                : 2,
            'highlightWeekends' => (bool) get_user_meta( get_current_user_id(), 'optee_highlight_weekends', true ),
            'cmdPalettePages'   => $this->get_palette_pages(),
            'cmdPaletteModules' => $this->get_palette_modules(),
            'cmdPaletteKb'      => $this->get_palette_kb(),
            'cronUrl'           => CronHandler::get_cron_url(),
            'activationValid'   => Activation::is_activated(),
            'updatesAvailable'  => (int) get_option( 'optee_updates_available', 0 ),
            'supportUrls'       => $this->get_support_urls(),
            'monSlugs'          => $mon_slugs,
            'tourSeen'          => (bool) get_user_meta( get_current_user_id(), 'optee_tour_seen', true ),
            'currentModule'     => $current_module,
            'customColumns'     => CustomColumns::get_all( get_current_user_id() ),
            'customColumnTokens' => CustomColumns::get_token_map(),
            'customColumnsEnabled' => $this->get_campaign_enabled_columns(),
            'customColumnsOverrides' => $this->get_campaign_column_overrides(),
        ] );
    }

    /**
     * Use custom page template for the dashboard page.
     */
    public function set_dashboard_template( string $template ): string {
        if ( $this->is_dashboard_page() ) {
            $custom = get_stylesheet_directory() . '/page-optee-dashboard.php';
            if ( file_exists( $custom ) ) {
                return $custom;
            }
        }
        return $template;
    }

    /**
     * Run dbDelta when DB version changes (e.g. new tables).
     */
    private function maybe_upgrade_db(): void {
        $installed = get_option( 'optee_db_version', '0' );
        if ( version_compare( $installed, OPTEE_DB_VERSION, '<' ) ) {
            require_once OPTEE_PLUGIN_DIR . 'includes/db/class-db-manager.php';
            DB\DbManager::create_tables();
            update_option( 'optee_db_version', OPTEE_DB_VERSION );
        }
    }

    /**
     * One-time backfill (runs after CPTs are registered): custom columns
     * became per-campaign in 1.7.0, so enable every existing column on every
     * existing campaign — nothing disappears on upgrade; users then uncheck
     * per campaign. Guarded by an option flag so it runs exactly once.
     */
    public function maybe_migrate_custom_columns(): void {
        if ( get_option( 'optee_cc_percampaign_migrated' ) ) {
            return;
        }
        CustomColumns::migrate_enable_all_existing();
        update_option( 'optee_cc_percampaign_migrated', 1, false );
    }

    /**
     * Custom-column ids assigned to the campaign currently being viewed
     * (empty unless we're on a campaign stats page the user owns). Used to
     * filter which custom columns render, client-side.
     */
    private function get_campaign_enabled_columns(): array {
        $campaign_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $campaign_id ) {
            return [];
        }
        $post = get_post( $campaign_id );
        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            return [];
        }
        return CustomColumns::get_enabled_ids( $campaign_id );
    }

    /**
     * Per-campaign custom-column formula overrides for the campaign currently
     * being viewed ({ column_id => spec }). Empty unless on an owned campaign
     * stats page. Applied client-side over the global library definitions.
     */
    private function get_campaign_column_overrides(): array {
        $campaign_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $campaign_id ) {
            return [];
        }
        $post = get_post( $campaign_id );
        if ( ! $post || $post->post_type !== 'optee_campaign' || (int) $post->post_author !== get_current_user_id() ) {
            return [];
        }
        return CustomColumns::get_overrides( $campaign_id );
    }

    /**
     * Get static pages for command palette.
     */
    private function get_palette_pages(): array {
        return [
            [ 'label' => 'Dashboard',            'url' => Shortcode::get_url( 'dashboard' ),              'keywords' => 'home overview' ],
            [ 'label' => 'Campaigns',             'url' => Shortcode::get_url( 'campaigns' ),              'keywords' => 'campaign list' ],
            [ 'label' => 'Rules',                 'url' => Shortcode::get_url( 'rules' ),                  'keywords' => 'automation rule' ],
            [ 'label' => 'Bonuses',               'url' => Shortcode::get_url( 'bonuses' ),                'keywords' => 'promo code bonus coupon' ],
            [ 'label' => 'Traffic Sources',       'url' => Shortcode::get_url( 'traffic-sources' ),        'keywords' => 'traffic source' ],
            [ 'label' => 'Trackers',              'url' => Shortcode::get_url( 'trackers' ),               'keywords' => 'tracker tracking' ],
            [ 'label' => 'Affiliate Networks',    'url' => Shortcode::get_url( 'affiliate-networks' ),     'keywords' => 'affiliate network offer' ],
            [ 'label' => 'Antifraud Tools',       'url' => Shortcode::get_url( 'antifraud-tools' ),        'keywords' => 'antifraud fraud bot' ],
            [ 'label' => 'Monetization',          'url' => Shortcode::get_url( 'monetization-platforms' ), 'keywords' => 'monetization publisher' ],
            [ 'label' => 'Balances',              'url' => Shortcode::get_url( 'balances' ),               'keywords' => 'balance money' ],
            [ 'label' => 'Earnings',              'url' => Shortcode::get_url( 'earnings' ),               'keywords' => 'earnings revenue income' ],
            [ 'label' => 'Zone Database',         'url' => Shortcode::get_url( 'zone-database' ),          'keywords' => 'zone whitelist blacklist' ],
            [ 'label' => 'VirusTotal',            'url' => Shortcode::get_url( 'virustotal' ),             'keywords' => 'virustotal domain scan' ],
            [ 'label' => 'Offers',                'url' => Shortcode::get_url( 'offers' ),                 'keywords' => 'offer check status' ],
            [ 'label' => 'Settings',              'url' => Shortcode::get_url( 'settings', 'profile' ),    'keywords' => 'settings profile timezone' ],
            [ 'label' => 'System Status',         'url' => Shortcode::get_url( 'settings', 'status' ),     'keywords' => 'system status health' ],
            [ 'label' => 'License',               'url' => Shortcode::get_url( 'settings', 'license' ),    'keywords' => 'license key activation' ],
            [ 'label' => 'Logs',                  'url' => Shortcode::get_url( 'logs' ),                   'keywords' => 'log history automation' ],
            [ 'label' => 'Knowledgebase',         'url' => Shortcode::get_url( 'knowledgebase' ),          'keywords' => 'knowledgebase help guide article' ],
        ];
    }

    /**
     * Get registered modules for command palette "Configure" items.
     */
    private function get_palette_modules(): array {
        $registry = ModuleRegistry::get_instance();
        $type_route_map = [
            'traffic_source'        => 'traffic-sources',
            'tracker'               => 'trackers',
            'affiliate_network'     => 'affiliate-networks',
            'antifraud_tool'        => 'antifraud-tools',
            'monetization_platform' => 'monetization-platforms',
        ];

        $type_label_map = [
            'traffic_source'        => 'Traffic Source',
            'tracker'               => 'Tracker',
            'affiliate_network'     => 'Affiliate Network',
            'antifraud_tool'        => 'Antifraud Tool',
            'monetization_platform' => 'Monetization',
        ];

        $items = [];
        foreach ( $registry->get_modules() as $module ) {
            $route = $type_route_map[ $module->get_type() ] ?? '';
            if ( ! $route ) {
                continue;
            }
            $items[] = [
                'label'    => $module->get_name(),
                'url'      => Shortcode::get_url( $route, 'configure', [ 'slug' => $module->get_slug() ] ),
                'typeLabel' => $type_label_map[ $module->get_type() ] ?? '',
            ];
        }

        usort( $items, fn( $a, $b ) => strcasecmp( $a['label'], $b['label'] ) );

        return $items;
    }

    /**
     * Get knowledgebase articles for command palette.
     */
    private function get_palette_kb(): array {
        $data_file = OPTEE_PLUGIN_DIR . 'templates/knowledgebase/articles-data.php';
        if ( ! file_exists( $data_file ) ) {
            return [];
        }

        require $data_file; // defines $articles
        if ( empty( $articles ) || ! is_array( $articles ) ) {
            return [];
        }

        $items = [];
        foreach ( $articles as $a ) {
            $title = $a['title'] ?? '';
            if ( $title === '' ) {
                continue;
            }

            if ( ! empty( $a['slug'] ) ) {
                // Local article — link into the KB reader (in-app, same tab).
                $url = Shortcode::get_url( 'knowledgebase', 'article' ) . '&slug=' . urlencode( $a['slug'] );
                // Extra keywords so loose queries match: slug words ("cron setup")
                // and the space-stripped title ("setupthecronjob" → matches "setup").
                $keywords = str_replace( '-', ' ', $a['slug'] ) . ' ' . str_replace( ' ', '', strtolower( $title ) );
                $external = false;
            } elseif ( ! empty( $a['url'] ) ) {
                // External article — open the source link in a new tab.
                $url      = $a['url'];
                $keywords = str_replace( ' ', '', strtolower( $title ) );
                $external = true;
            } else {
                continue;
            }

            $items[] = [
                'label'    => $title,
                'url'      => $url,
                'keywords' => $keywords,
                'external' => $external,
            ];
        }

        return $items;
    }

    /**
     * Build support portal URLs for bug reports / feature requests.
     */
    private function get_support_urls(): array {
        $key   = get_option( 'optee_activation_key', '' );
        $email = get_option( 'optee_activation_email', '' );

        // URLs are now token-based (fetched async via JS). Return redirect paths only.
        if ( $key && $email ) {
            return [
                'bug'     => '/tickets/new?type=bug',
                'feature' => '/tickets/new?type=feature',
                'active'  => true,
            ];
        }

        return [ 'bug' => '', 'feature' => '', 'active' => false ];
    }

    /**
     * Custom login page styles.
     */
    public function login_styles(): void {
        $logo_url = esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-logo-light.svg' );
        ?>
        <style>
            body.login {
                background: #f9fafb;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            }
            #login h1 a {
                background-image: url('<?php echo $logo_url; ?>');
                background-size: 160px auto;
                width: 160px;
                height: 50px;
                margin-bottom: 16px;
            }
            .login form {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                box-shadow: 0 1px 3px rgba(0,0,0,.04);
                padding: 24px;
            }
            .login form .input,
            .login form input[type="text"],
            .login form input[type="password"] {
                border: 1px solid #d1d5db;
                border-radius: 8px;
                padding: 8px 12px;
                font-size: 14px;
                box-shadow: none;
            }
            .login form .input:focus,
            .login form input[type="text"]:focus,
            .login form input[type="password"]:focus {
                border-color: #2aa5ff;
                box-shadow: 0 0 0 2px rgba(42,165,255,.2);
                outline: none;
            }
            .login label {
                font-size: 13px;
                font-weight: 500;
                color: #374151;
            }
            .wp-core-ui .button-primary {
                background: #2aa5ff;
                border: none;
                border-radius: 8px;
                padding: 6px 20px;
                font-size: 14px;
                font-weight: 500;
                text-shadow: none;
                box-shadow: none;
                transition: background .15s;
            }
            .wp-core-ui .button-primary:hover,
            .wp-core-ui .button-primary:focus {
                background: #1a8fe0;
                box-shadow: none;
            }
            .login #backtoblog a,
            .login #nav a {
                color: #6b7280;
                font-size: 13px;
                transition: color .15s;
            }
            .login #backtoblog a:hover,
            .login #nav a:hover {
                color: #2aa5ff;
            }
            .login .privacy-policy-page-link {
                display: none;
            }
            .login #login_error,
            .login .message,
            .login .success {
                border-radius: 8px;
                border-left-width: 3px;
            }
            .wp-core-ui .button.wp-hide-pw {
                color: #6b7280;
            }
        </style>
        <?php
    }

    /**
     * Check if we're on the dashboard page.
     */
    private function is_dashboard_page(): bool {
        $page_id = get_option( 'optee_dashboard_page_id' );
        return $page_id && is_page( (int) $page_id );
    }

    /**
     * Emit favicon link tags on the dashboard + login pages.
     */
    public function dashboard_favicon(): void {
        if ( current_action() === 'wp_head' && ! $this->is_dashboard_page() ) {
            return;
        }
        $base = OPTEE_PLUGIN_URL . 'assets/images/';
        printf( '<link rel="icon" type="image/svg+xml" href="%s">' . "\n", esc_url( $base . 'favicon.svg' ) );
        printf( '<link rel="icon" type="image/png" sizes="32x32" href="%s">' . "\n", esc_url( $base . 'favicon-32.png' ) );
        printf( '<link rel="apple-touch-icon" sizes="180x180" href="%s">' . "\n", esc_url( $base . 'apple-touch-icon.png' ) );
    }
}
