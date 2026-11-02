<?php
/**
 * Sidebar navigation.
 *
 * Variables available: $current_module, $collapsed_class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$nav_items = [
    [
        'module' => 'dashboard',
        'label'  => 'Dashboard',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
    ],
    [
        'module' => 'campaigns',
        'label'  => 'Campaigns',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    ],
    [
        'module' => 'rules',
        'label'  => 'Rules',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>',
    ],
    [
        'module' => 'alerts',
        'label'  => 'Alerts',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg>',
    ],
    [
        'module' => 'bonuses',
        'label'  => 'Bonuses',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/></svg>',
    ],
];

// Show Promo Codes only when there's at least one active campaign (cached server-side
// from the bonuses-popup AJAX fetch). Dev-tools also forces it on so we can navigate
// while previewing drafts.
$show_promo_codes = get_option( 'optee_has_active_promo', '0' ) === '1'
    || ( defined( 'OPTEE_DEV_TOOLS' ) && OPTEE_DEV_TOOLS );

if ( $show_promo_codes ) {
    $nav_items[] = [
        'module' => 'promo-codes',
        'label'  => 'Promo Codes',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>',
    ];
}

$nav_items = array_merge( $nav_items, [
    [ 'separator' => true, 'label' => 'Integrations' ],
    [
        'module' => 'traffic-sources',
        'label'  => 'Traffic Sources',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
    ],
    [
        'module' => 'trackers',
        'label'  => 'Trackers',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>',
    ],
    [
        'module' => 'affiliate-networks',
        'label'  => 'Affiliate Networks',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>',
    ],
    [
        'module' => 'antifraud-tools',
        'label'  => 'Antifraud Tools',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>',
    ],
    [
        'module' => 'monetization-platforms',
        'label'  => 'Monetization',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
    ],
    [
        'module' => 'balances',
        'label'  => 'Balances',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2.5"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>',
    ],
    [
        'module' => 'earnings',
        'label'  => 'Earnings',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
    ],
    [ 'separator' => true, 'label' => 'Tools' ],
    [
        'module' => 'zone-database',
        'label'  => 'Zone Database',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>',
    ],
    [
        'module' => 'virustotal',
        'label'  => 'VirusTotal',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="M9.1 12a2.1 2.1 0 0 1 0-4.2 2.1 2.1 0 0 1 0 4.2z"/><path d="M14.9 12a2.1 2.1 0 0 1 0-4.2 2.1 2.1 0 0 1 0 4.2z"/></svg>',
    ],
    [
        'module' => 'offers',
        'label'  => 'Offers',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>',
    ],
    [
        'module' => 'insights',
        'label'  => 'Insights',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>',
    ],
    [ 'separator' => true, 'label' => 'System' ],
    [
        'module' => 'logs',
        'label'  => 'Logs',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 12H8"/><path d="M16 17H8"/><path d="M16 12h-2"/></svg>',
    ],
    [
        'module' => 'settings-profile',
        'label'  => 'Settings',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
    ],
] );

// Allow extension plugins to add their own sidebar items.
$nav_items = apply_filters( 'optee_sidebar_nav_items', $nav_items, $current_module );

$dashboard_url = \Optee\Plugin\Shortcode::get_url();
?>
<aside id="optee-sidebar" class="flex h-full w-64 flex-col border-r border-gray-200 bg-gray-50" style="transition:width 200ms ease; z-index:10;">
    <!-- Logo -->
    <div class="flex h-16 items-center border-b border-gray-200 px-4">
        <a href="<?php echo esc_url( $dashboard_url ); ?>">
            <img src="<?php echo esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-logo-light.svg' ); ?>" alt="Optee" class="sidebar-text" style="height:2.275rem;">
            <img src="<?php echo esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-icon-light.svg' ); ?>" alt="Optee" class="h-8 w-8 hidden sidebar-icon">
        </a>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto px-3 py-4">
        <ul class="space-y-1">
            <?php foreach ( $nav_items as $item ) : ?>
                <?php if ( ! empty( $item['separator'] ) ) : ?>
                    <li class="pt-4 pb-1 px-3">
                        <span class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 sidebar-text">
                            <?php echo esc_html( $item['label'] ); ?>
                        </span>
                    </li>
                <?php else :
                    $is_active = ( $current_module === $item['module'] )
                        || ( $item['module'] === 'settings-profile' && str_starts_with( $current_module, 'settings' ) )
                        || ( $item['module'] === 'logs' && str_starts_with( $current_module, 'logs' ) )
                        || ( $item['module'] === 'traffic-sources' && str_starts_with( $current_module, 'traffic-sources' ) )
                        || ( $item['module'] === 'trackers' && str_starts_with( $current_module, 'trackers' ) )
                        || ( $item['module'] === 'affiliate-networks' && str_starts_with( $current_module, 'affiliate-networks' ) )
                        || ( $item['module'] === 'antifraud-tools' && str_starts_with( $current_module, 'antifraud-tools' ) )
                        || ( $item['module'] === 'monetization-platforms' && str_starts_with( $current_module, 'monetization-platforms' ) );
                    $active_class = $is_active
                        ? 'bg-gray-200 text-gray-900'
                        : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900';

                    // Handle module parameter format for settings.
                    $module_param = $item['module'];
                    $action_param = '';
                    if ( str_contains( $module_param, '-' ) && str_starts_with( $module_param, 'settings' ) ) {
                        $parts        = explode( '-', $module_param, 2 );
                        $module_param = $parts[0];
                        $action_param = $parts[1] ?? '';
                    }

                    $link = \Optee\Plugin\Shortcode::get_url( $module_param, $action_param );
                ?>
                    <?php
                    // "NEW" badge for the Insights nav item until the user first visits the page.
                    $show_new_badge = false;
                    if ( $item['module'] === 'insights' && ! get_user_meta( get_current_user_id(), 'optee_insights_visited', true ) ) {
                        $show_new_badge = true;
                    }
                    ?>
                    <li>
                        <a href="<?php echo esc_url( $link ); ?>"
                           class="optee-nav-item flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors <?php echo esc_attr( $active_class ); ?>"
                           data-tooltip="<?php echo esc_attr( $item['label'] ); ?>">
                            <span class="shrink-0 sidebar-icon"><?php echo $item['icon']; // Safe: hardcoded SVG, not user input. ?></span>
                            <span class="sidebar-text flex-1"><?php echo esc_html( $item['label'] ); ?></span>
                            <?php if ( $show_new_badge ) : ?>
                                <span class="sidebar-text" style="background:#10b981;color:#fff;font-size:9px;font-weight:700;letter-spacing:0.04em;padding:2px 6px;border-radius:4px;line-height:1;">NEW</span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </nav>

    <!-- Collapse toggle -->
    <div class="flex h-16 items-center border-t border-gray-200 px-3">
        <button id="optee-sidebar-toggle"
                class="optee-nav-item flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-gray-500 hover:bg-gray-100 hover:text-gray-900 transition-colors"
                data-tooltip="Toggle sidebar">
            <span class="shrink-0 sidebar-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/></svg>
            </span>
            <span class="sidebar-text">Collapse</span>
        </button>
    </div>
</aside>
