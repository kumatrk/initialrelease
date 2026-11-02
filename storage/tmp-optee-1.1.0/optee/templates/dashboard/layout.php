<?php
/**
 * Dashboard layout wrapper.
 *
 * Variables available: $template_file, $current_module, $current_action, $registry
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$sidebar_collapsed = get_user_meta( get_current_user_id(), 'optee_sidebar_collapsed', true );
$collapsed_class   = $sidebar_collapsed ? 'sidebar-collapsed' : '';

// WordPress core update availability. Admins rarely visit wp-admin, so we surface
// any pending core update in a frontend popup. WP core is upgraded via the native
// wp-admin flow only — we never run the upgrade from here — so the popup is info +
// a single link to update-core.php.
$wp_core_update = null;
if ( current_user_can( 'update_core' ) ) {
    if ( ! function_exists( 'get_preferred_from_update_core' ) ) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    $core = get_preferred_from_update_core();
    if ( $core && isset( $core->response ) && 'upgrade' === $core->response && ! empty( $core->current ) ) {
        $wp_core_update = [
            'current' => get_bloginfo( 'version' ),
            'latest'  => (string) $core->current,
        ];
    }
}
?>
<style>@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
<div id="optee-app" class="flex h-screen overflow-hidden <?php echo esc_attr( $collapsed_class ); ?>">
    <?php include OPTEE_PLUGIN_DIR . 'templates/dashboard/sidebar.php'; ?>

    <div class="flex flex-1 flex-col overflow-hidden">
        <?php include OPTEE_PLUGIN_DIR . 'templates/dashboard/topbar.php'; ?>

        <main id="optee-content" class="flex-1 overflow-y-auto p-6">
            <?php
            // Route values may be relative to the core templates/ dir, or an
            // absolute path supplied by an extension plugin via optee_dashboard_routes.
            $content_template = path_is_absolute( $template_file )
                ? $template_file
                : OPTEE_PLUGIN_DIR . 'templates/' . $template_file;
            if ( file_exists( $content_template ) ) {
                include $content_template;
            } else {
                echo '<div class="optee-card"><p class="text-gray-500">Page not found.</p></div>';
            }
            ?>
        </main>

        <footer id="optee-footer" class="flex h-16 items-center justify-between border-t border-gray-200 bg-gray-50 px-6" style="flex-shrink:0;">
            <?php
            $support_key   = get_option( 'optee_activation_key', '' );
            $support_email = get_option( 'optee_activation_email', '' );
            $has_support    = $support_key && $support_email;
            $no_license_onclick = ' onclick="Optee.Toast.show(\'Please activate your license first\',\'warning\');return false;"';
            ?>
            <div class="text-xs text-gray-400">
                <span>Beta version — occasional errors may occur.</span><br class="optee-footer-br">
                <span><a href="#" class="text-gray-400 hover:text-gray-600 underline optee-support-link" data-redirect="/tickets/new?type=bug"<?php echo $has_support ? '' : $no_license_onclick; ?>>Report a Bug</a> · <a href="#" class="text-gray-400 hover:text-gray-600 underline optee-support-link" data-redirect="/tickets/new?type=feature"<?php echo $has_support ? '' : $no_license_onclick; ?>>Suggest a Feature</a></span>
            </div>
            <div class="flex items-center gap-3">
                <?php
                $expiry_str = get_option( 'optee_activation_expiry', '' );
                if ( $expiry_str ) {
                    $days_left = (int) floor( ( strtotime( $expiry_str ) - current_time( 'timestamp' ) ) / 86400 );
                    if ( $days_left >= 0 && $days_left <= 30 ) :
                        $label = $days_left === 0 ? 'License expires today' : 'License expires in ' . $days_left . ' day' . ( $days_left !== 1 ? 's' : '' );
                ?>
                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'license' ) ); ?>"
                   style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#fff;background:#f59e0b;border-radius:9999px;padding:5px 14px 5px 10px;text-decoration:none;transition:background 0.15s;"
                   onmouseover="this.style.background='#d97706'" onmouseout="this.style.background='#f59e0b'">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?php echo esc_html( $label ); ?>
                </a>
                <?php endif; } ?>
                <button type="button" id="optee-updates-badge" style="display:none;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#fff;background:#2aa5ff;border:none;border-radius:9999px;padding:5px 14px 5px 10px;cursor:pointer;transition:background 0.15s;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v10"/><path d="m4.93 10.93 1.41 1.41"/><path d="M2 18h2"/><path d="M20 18h2"/><path d="m19.07 10.93-1.41 1.41"/><path d="M22 22H2"/><path d="m8 6 4-4 4 4"/><path d="M16 18a4 4 0 0 0-8 0"/></svg>
                    <span id="optee-updates-count"></span>
                    <span id="optee-updates-label">update available</span>
                </button>
                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'status' ) ); ?>"
                   class="text-xs text-gray-400 hover:text-gray-600 transition-colors" style="text-decoration:none;">
                    <span id="optee-footer-version">Optee <?php echo esc_html( OPTEE_VERSION ); ?></span> / DB <?php echo esc_html( get_option( 'optee_db_version', '?' ) ); ?>
                </a>
            </div>
        </footer>
    </div>
</div>

<!-- Mobile Menu Overlay -->
<div id="optee-mobile-menu">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:0 1rem;height:64px;border-bottom:1px solid #e5e7eb;flex-shrink:0;">
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url() ); ?>">
            <img src="<?php echo esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-logo-light.svg' ); ?>" alt="Optee" style="height:2.275rem;">
        </a>
        <button type="button" id="optee-mobile-menu-close" style="background:none;border:none;cursor:pointer;color:#6b7280;padding:8px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
    <nav style="flex:1;overflow-y:auto;padding:0.5rem 0;">
        <?php
        // Re-use the same $nav_items from sidebar.php.
        $mobile_show_promo_codes = get_option( 'optee_has_active_promo', '0' ) === '1'
            || ( defined( 'OPTEE_DEV_TOOLS' ) && OPTEE_DEV_TOOLS );
        $mobile_nav_items = [
            [ 'module' => 'dashboard',              'label' => 'Dashboard' ],
            [ 'module' => 'campaigns',              'label' => 'Campaigns' ],
            [ 'module' => 'rules',                  'label' => 'Rules' ],
            [ 'module' => 'alerts',                 'label' => 'Alerts' ],
            [ 'module' => 'bonuses',                'label' => 'Bonuses' ],
        ];
        if ( $mobile_show_promo_codes ) {
            $mobile_nav_items[] = [ 'module' => 'promo-codes', 'label' => 'Promo Codes' ];
        }
        $mobile_nav_items = array_merge( $mobile_nav_items, [
            [ 'separator' => true, 'label' => 'Integrations' ],
            [ 'module' => 'traffic-sources',        'label' => 'Traffic Sources' ],
            [ 'module' => 'trackers',               'label' => 'Trackers' ],
            [ 'module' => 'affiliate-networks',     'label' => 'Affiliate Networks' ],
            [ 'module' => 'antifraud-tools',        'label' => 'Antifraud Tools' ],
            [ 'module' => 'monetization-platforms',  'label' => 'Monetization' ],
            [ 'module' => 'balances',               'label' => 'Balances' ],
            [ 'module' => 'earnings',               'label' => 'Earnings' ],
            [ 'separator' => true, 'label' => 'Tools' ],
            [ 'module' => 'zone-database',          'label' => 'Zone Database' ],
            [ 'module' => 'virustotal',             'label' => 'VirusTotal' ],
            [ 'module' => 'offers',                 'label' => 'Offers' ],
            [ 'module' => 'insights',               'label' => 'Insights' ],
            [ 'separator' => true, 'label' => 'System' ],
            [ 'module' => 'logs',                   'label' => 'Logs' ],
            [ 'module' => 'settings-profile',       'label' => 'Settings' ],
        ] );
        // Allow extension plugins to add their own mobile menu items (parity with sidebar).
        $mobile_nav_items = apply_filters( 'optee_mobile_nav_items', $mobile_nav_items, $current_module );
        foreach ( $mobile_nav_items as $mi ) :
            if ( ! empty( $mi['separator'] ) ) : ?>
                <div class="optee-mobile-sep"><?php echo esc_html( $mi['label'] ); ?></div>
            <?php else :
                $mod_param = $mi['module'];
                $act_param = '';
                if ( str_contains( $mod_param, '-' ) && str_starts_with( $mod_param, 'settings' ) ) {
                    $parts     = explode( '-', $mod_param, 2 );
                    $mod_param = $parts[0];
                    $act_param = $parts[1] ?? '';
                }
                $mi_link = \Optee\Plugin\Shortcode::get_url( $mod_param, $act_param );
                $mi_active = ( $current_module === $mi['module'] )
                    || ( $mi['module'] === 'settings-profile' && str_starts_with( $current_module, 'settings' ) )
                    || ( $mi['module'] === 'logs' && str_starts_with( $current_module, 'logs' ) );
            ?>
                <a href="<?php echo esc_url( $mi_link ); ?>"
                   class="optee-mobile-nav-item<?php echo $mi_active ? ' active' : ''; ?>">
                    <?php echo esc_html( $mi['label'] ); ?>
                </a>
            <?php endif;
        endforeach;

        // Account section — mirrors the header user dropdown (topbar.php). Moved into the
        // mobile menu so the avatar dropdown can be dropped from the topbar on mobile,
        // reducing header clutter. Mobile-only by virtue of living in #optee-mobile-menu.
        $mobile_cron_url = \Optee\Plugin\CronHandler::get_cron_url();
        ?>
            <div class="optee-mobile-sep">Account</div>
            <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'profile' ) ); ?>"
               class="optee-mobile-nav-item">My Profile</a>
            <?php if ( get_option( 'optee_has_tools', '0' ) === '1' || ( defined( 'OPTEE_DEV_TOOLS' ) && OPTEE_DEV_TOOLS ) ) : ?>
            <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'tools' ) ); ?>"
               class="optee-mobile-nav-item">Tools</a>
            <?php endif; ?>
            <a href="<?php echo esc_url( $mobile_cron_url ); ?>" target="_blank" rel="noopener noreferrer"
               class="optee-mobile-nav-item">Run Automation</a>
            <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'knowledgebase' ) ); ?>"
               class="optee-mobile-nav-item">Knowledgebase</a>
            <a href="<?php echo esc_url( admin_url() ); ?>" target="_blank" rel="noopener noreferrer"
               class="optee-mobile-nav-item">Open WP Admin</a>
            <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>"
               onclick="if(!confirm('Are you sure you want to log out?'))return false;"
               class="optee-mobile-nav-item">Logout</a>
    </nav>
</div>

<!-- Updates Popup -->
<div id="optee-updates-popup" style="display:none;position:fixed;inset:0;z-index:90;background:rgba(0,0,0,.4);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);width:100%;max-width:560px;margin:0 16px;max-height:80vh;display:flex;flex-direction:column;">
        <div style="padding:24px 24px 16px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h2 style="font-size:16px;font-weight:700;color:#111827;margin:0;">Available Updates</h2>
                <p style="font-size:13px;color:#6b7280;margin-top:2px;">Update Optee and integration plugins.</p>
            </div>
            <button type="button" id="optee-updates-close" style="background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <div id="optee-updates-list" style="padding:16px 24px;overflow-y:auto;flex:1;">
            <div style="text-align:center;color:#9ca3af;padding:24px;">Checking for updates...</div>
        </div>
    </div>
</div>

<?php if ( $wp_core_update ) : ?>
<!-- WordPress Core Update Popup (info only — upgrade happens in wp-admin) -->
<div id="optee-wp-core-popup" data-version="<?php echo esc_attr( $wp_core_update['latest'] ); ?>" style="display:none;position:fixed;inset:0;z-index:92;background:rgba(0,0,0,.4);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);width:100%;max-width:480px;margin:0 16px;display:flex;flex-direction:column;">
        <div style="padding:24px 24px 16px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="flex-shrink:0;width:40px;height:40px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:#2aa5ff;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v10"/><path d="m4.93 10.93 1.41 1.41"/><path d="M2 18h2"/><path d="M20 18h2"/><path d="m19.07 10.93-1.41 1.41"/><path d="M22 22H2"/><path d="m8 6 4-4 4 4"/><path d="M16 18a4 4 0 0 0-8 0"/></svg>
                </div>
                <div>
                    <h2 style="font-size:16px;font-weight:700;color:#111827;margin:0;">WordPress update available</h2>
                    <p style="font-size:13px;color:#6b7280;margin-top:2px;">A new version of WordPress is ready to install.</p>
                </div>
            </div>
            <button type="button" id="optee-wp-core-close" style="background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;align-self:flex-start;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <div style="padding:20px 24px;">
            <div style="font-size:14px;color:#374151;">
                Current version: <strong style="color:#111827;"><?php echo esc_html( $wp_core_update['current'] ); ?></strong>
                &nbsp;→&nbsp;
                New version: <strong style="color:#111827;"><?php echo esc_html( $wp_core_update['latest'] ); ?></strong>
            </div>
            <p style="font-size:13px;color:#6b7280;margin-top:12px;line-height:1.6;">
                WordPress core is updated from the WordPress admin area using the built-in updater.
                Click below to open the update page, then follow the on-screen steps to finish.
            </p>
        </div>
        <div style="padding:0 24px 24px;display:flex;align-items:center;justify-content:flex-end;gap:12px;">
            <button type="button" id="optee-wp-core-later" style="font-size:13px;font-weight:600;color:#6b7280;background:none;border:none;cursor:pointer;padding:8px 4px;">Later</button>
            <a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" target="_blank" rel="noopener noreferrer"
               class="optee-btn-primary" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;font-size:14px;text-decoration:none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                Open WP Admin
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- News Announcement Popup -->
<div id="optee-news-popup" style="display:none;position:fixed;inset:0;z-index:95;background:rgba(0,0,0,.4);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);width:100%;max-width:520px;margin:0 16px;max-height:80vh;display:flex;flex-direction:column;position:relative;">
        <button type="button" id="optee-news-close" style="position:absolute;top:16px;right:16px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;z-index:2;" title="Dismiss">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div id="optee-news-slides" style="padding:32px 32px 16px;overflow-y:auto;flex:1;min-height:0;">
            <!-- slides injected by JS -->
        </div>
        <div id="optee-news-nav" style="display:none;padding:0 32px 24px;text-align:center;">
            <!-- dot navigation + prev/next -->
        </div>
    </div>
</div>

<script>
(function($) {
    'use strict';

    // Mobile menu toggle.
    $('#optee-mobile-menu-btn').on('click', function() {
        $('#optee-mobile-menu').addClass('is-open');
    });
    $('#optee-mobile-menu-close').on('click', function() {
        $('#optee-mobile-menu').removeClass('is-open');
    });

    // Mobile card: show actions toggle (global handler).
    $(document).on('click', '.optee-mob-actions-toggle', function() {
        var $btn = $(this);
        $btn.hide();
        $btn.siblings('.optee-mob-actions').show();
    });

    // Mobile card: stats/scores expand toggle (global handler).
    $(document).on('click', '.optee-mob-stats-toggle', function() {
        var $btn = $(this);
        $btn.toggleClass('is-open');
        $btn.siblings('.optee-mob-stats-more').toggle();
        var $label = $btn.find('.optee-mob-stats-label');
        if ($label.length) {
            var origText = $label.data('orig') || $label.text();
            if (!$label.data('orig')) $label.data('orig', origText);
            $label.text($btn.hasClass('is-open') ? 'Hide' : origText);
        }
    });

    // Mobile campaign stats: Reports popup.
    $(document).on('click', '#optee-mob-reports-btn', function() {
        // Clone drilldown buttons into the popup.
        var $list = $('#optee-mob-reports-list');
        $list.empty();
        $('#optee-stats-drilldowns').children().each(function() {
            var $clone = $(this).clone(true);
            $clone.css({ 'font-size': '14px', 'padding': '8px 16px', 'min-height': '40px', 'box-sizing': 'border-box' });
            $list.append($clone);
        });
        $('#optee-mob-reports-popup').css('display', 'flex').css('align-items', 'flex-end');
    });
    $(document).on('click', '#optee-mob-reports-close', function() {
        $('#optee-mob-reports-popup').hide();
    });
    $(document).on('click', '#optee-mob-reports-popup', function(e) {
        if (e.target === this) $(this).hide();
    });
    // Close popup when a drilldown button is clicked inside it, update label.
    $(document).on('click', '#optee-mob-reports-list .optee-drill-btn, #optee-mob-reports-list .optee-drill-dropdown-item', function() {
        $('#optee-mob-reports-popup').hide();
        // Update label after a short delay (wait for drilldown render).
        setTimeout(function() {
            $('#optee-mob-reports-label').text(getActiveDrillLabel());
        }, 200);
    });

    // Extract drilldown label (ignoring chevron/dropdown children).
    function getActiveDrillLabel() {
        var $active = $('#optee-stats-drilldowns .optee-drill-btn.active, #optee-stats-drilldowns .optee-drill-has-dropdown.active');
        if (!$active.length) return 'Default';
        var $clone = $active.first().clone();
        $clone.find('.optee-drill-chevron, .optee-drill-dropdown-menu').remove();
        return $clone.text().trim() || 'Default';
    }

    // Also update label whenever drilldowns re-render (MutationObserver).
    (function() {
        var target = document.getElementById('optee-stats-drilldowns');
        if (!target) return;
        var observer = new MutationObserver(function() {
            $('#optee-mob-reports-label').text(getActiveDrillLabel());
        });
        observer.observe(target, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
    })();

    // Mobile rules: show/hide rules toggle.
    $(document).on('click', '.optee-mob-show-rules', function() {
        var $btn = $(this);
        var $body = $btn.closest('.optee-rules-campaign-group').find('.optee-rules-campaign-body');
        $body.toggleClass('optee-mob-open');
        $btn.text($body.hasClass('optee-mob-open') ? 'Hide Rules' : 'Show Rules');
    });

    // Show/hide badge based on initial count (wait for opteeData from wp_footer).
    function showBadge(count) {
        if (count > 0) {
            $('#optee-updates-badge').css('display', 'inline-flex').find('#optee-updates-count').text(count);
            $('#optee-updates-label').text(count === 1 ? 'update available' : 'updates available');
        } else {
            $('#optee-updates-badge').hide();
        }
    }

    $(function() {
        if (typeof opteeData !== 'undefined') {
            showBadge(parseInt(opteeData.updatesAvailable, 10) || 0);
        }

        // Support links: fetch short-lived token from API, then redirect.
        $(document).on('click', '.optee-support-link', function(e) {
            e.preventDefault();
            var $link = $(this);
            var redirect = $link.data('redirect') || '/';
            if ($link.data('loading')) return;
            $link.data('loading', true).css('opacity', 0.5);

            $.ajax({
                url: 'https://api.optee.co/api/support-auth',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    key: '<?php echo esc_js( $support_key ); ?>',
                    email: '<?php echo esc_js( $support_email ); ?>'
                })
            }).done(function(res) {
                if (res.token) {
                    window.open('https://support.optee.co/login?token=' + encodeURIComponent(res.token) + '&redirect=' + encodeURIComponent(redirect), '_blank');
                } else {
                    Optee.Toast.show(res.error || 'Failed to authenticate.', 'error');
                }
            }).fail(function() {
                Optee.Toast.show('Failed to connect to support.', 'error');
            }).always(function() {
                $link.removeData('loading').css('opacity', 1);
            });
        });
    });

    // Open popup.
    $('#optee-updates-badge').on('click', function() {
        var $popup = $('#optee-updates-popup').css('display', 'flex');
        loadUpdates();
    });

    // Close popup.
    $('#optee-updates-close').on('click', function() {
        $('#optee-updates-popup').hide();
    });
    $('#optee-updates-popup').on('click', function(e) {
        if (e.target === this) $(this).hide();
    });

    function loadUpdates() {
        var $list = $('#optee-updates-list').html('<div style="text-align:center;color:#9ca3af;padding:24px;">Checking for updates...</div>');

        Optee.Ajax.post('optee_check_plugin_updates', {}).done(function(res) {
            if (!res.success) {
                $list.html('<div style="text-align:center;color:#dc2626;padding:24px;">Failed to check updates.</div>');
                return;
            }

            var updates = res.data.updates || {};
            var html = '';
            var count = 0;

            $.each(updates, function(slug, info) {
                if (!info.update_available) return;
                count++;

                var changelog = info.changelog ? '<div style="font-size:12px;color:#6b7280;margin-top:8px;white-space:pre-line;">' + $('<span>').text(info.changelog).html() + '</div>' : '';

                html += '<div class="optee-update-item" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid #f3f4f6;">';
                html += '<div style="flex:1;min-width:0;">';
                var displayName = info.display_name || slug;
                var typeLabel = info.type_label || '';
                html += '<div style="display:flex;align-items:center;gap:8px;">';
                html += '<span style="font-size:14px;font-weight:600;color:#111827;">' + $('<span>').text(displayName).html() + '</span>';
                if (typeLabel) {
                    html += '<span style="font-size:10px;font-weight:500;color:#6b7280;background:#f3f4f6;border-radius:9999px;padding:1px 8px;white-space:nowrap;">' + $('<span>').text(typeLabel).html() + '</span>';
                }
                html += '</div>';
                html += '<div style="font-size:12px;color:#6b7280;margin-top:2px;">' + $('<span>').text(info.current_version).html() + ' → <strong>' + $('<span>').text(info.latest_version).html() + '</strong></div>';
                html += changelog;
                html += '</div>';

                if (info.download_url) {
                    html += '<button type="button" class="optee-btn-primary optee-btn-sm optee-do-update" data-slug="' + $('<span>').text(slug).html() + '"' + (info.is_tool ? ' data-is-tool="1"' : '') + ' style="white-space:nowrap;flex-shrink:0;margin-top:2px;">Update</button>';
                } else {
                    html += '<span style="font-size:11px;color:#d97706;white-space:nowrap;">Key expired</span>';
                }

                html += '</div>';
            });

            if (count === 0) {
                $list.html('<div style="text-align:center;color:#6b7280;padding:24px;"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:6px;color:#22c55e;"><path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>Everything is up to date.</div>');
                showBadge(0);
            } else {
                $list.html(html);
                showBadge(count);
            }
        }).fail(function() {
            $list.html('<div style="text-align:center;color:#dc2626;padding:24px;">Network error.</div>');
        });
    }

    // Handle update button click (popup).
    $(document).on('click', '.optee-do-update', function() {
        var $btn = $(this).prop('disabled', true).text('Updating...');
        var slug = $btn.data('slug');
        // Private tools update through the gated tool channel, not the plugin one.
        var isTool = String($btn.data('is-tool') || '') === '1';
        var action = isTool ? 'optee_update_tool_from_api' : 'optee_update_plugin_from_api';

        Optee.Ajax.post(action, { slug: slug }).done(function(res) {
            if (res.success) {
                $btn.text('Updated!').css({ background: '#22c55e', borderColor: '#22c55e' });
                $btn.closest('.optee-update-item').css('opacity', '0.5');
                Optee.Toast.show(res.data.message, 'success');
                // Update badge with remaining count. The tool updater returns no
                // count, so decrement the current badge by one for tools.
                if (typeof res.data.update_count !== 'undefined') {
                    showBadge(res.data.update_count);
                } else if (isTool) {
                    var cur = parseInt($('#optee-updates-count').text(), 10) || 0;
                    showBadge(Math.max(0, cur - 1));
                }
                // Core plugin updated — prompt page reload for latest code.
                if (slug === 'optee' && res.data.version) {
                    $('#optee-footer-version').text('Optee ' + res.data.version);
                    setTimeout(function() {
                        if (confirm('Optee has been updated to v' + res.data.version + '. Reload page to apply changes?')) {
                            location.reload();
                        }
                    }, 500);
                }
            } else {
                Optee.Toast.show(res.data.message || 'Update failed.', 'error');
                $btn.prop('disabled', false).text('Update');
            }
        }).fail(function() {
            Optee.Toast.show('Network error.', 'error');
            $btn.prop('disabled', false).text('Update');
        });
    });

    // ─── WordPress Core Update Popup ─────────────────────
    // Shown automatically on load when a WP core update is pending (admins rarely
    // open wp-admin). Dismissed per target version via localStorage so it doesn't
    // nag on every navigation — reappears only when a newer WP version lands.
    (function() {
        var $popup = $('#optee-wp-core-popup');
        if (!$popup.length) return;

        var version = String($popup.data('version') || '');
        var storeKey = 'optee_wp_core_dismissed';
        var dismissed = '';
        try { dismissed = localStorage.getItem(storeKey) || ''; } catch (e) {}

        function dismiss() {
            $popup.hide();
            try { localStorage.setItem(storeKey, version); } catch (e) {}
        }

        $('#optee-wp-core-close, #optee-wp-core-later').on('click', dismiss);
        $popup.on('click', function(e) { if (e.target === this) dismiss(); });
        // "Update in WP Admin" is a real link — treat clicking it as acknowledged too.
        $popup.find('a.optee-btn-primary').on('click', function() {
            try { localStorage.setItem(storeKey, version); } catch (e) {}
        });

        $(function() {
            if (version && dismissed !== version) {
                $popup.css('display', 'flex');
            }
        });
    })();

    // ─── News Announcement Popup ─────────────────────────
    (function() {
        var newsItems = [];
        var currentSlide = 0;

        function renderSlide() {
            if (!newsItems.length) return;
            var item = newsItems[currentSlide];
            var typeColors = { info: '#2aa5ff', feature: '#8b5cf6', update: '#059669', promo: '#d97706' };
            var typeLabels = { info: 'Announcement', feature: 'New Feature', update: 'Update', promo: 'Promotion' };
            var color = typeColors[item.type] || '#2aa5ff';

            var html = '';
            if (item.image_url) {
                html += '<img src="' + item.image_url + '" style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;margin-bottom:16px;" alt="">';
            }
            html += '<div style="text-align:center;">';
            html += '<div style="display:inline-flex;align-items:center;gap:6px;padding:3px 12px;border-radius:9999px;font-size:11px;font-weight:600;color:#fff;background:' + color + ';margin-bottom:16px;">';
            html += (typeLabels[item.type] || 'News') + '</div>';
            html += '<h2 style="font-size:20px;font-weight:700;color:#111827;margin:0 0 12px;line-height:1.3;">' + item.title + '</h2>';
            html += '</div>';
            html += '<div style="font-size:14px;color:#374151;line-height:1.7;">' + item.content + '</div>';
            if (item.cta_text && item.cta_url) {
                html += '<div style="text-align:center;margin-top:20px;">';
                html += '<a href="' + item.cta_url + '" class="optee-btn-primary" style="display:inline-flex;padding:10px 24px;font-size:14px;text-decoration:none;">' + item.cta_text + '</a>';
                html += '</div>';
            }
            $('#optee-news-slides').html(html);

            // Dot navigation
            if (newsItems.length > 1) {
                var nav = '<div style="display:flex;align-items:center;justify-content:center;gap:12px;">';
                nav += '<button type="button" class="optee-news-prev" style="background:none;border:none;cursor:pointer;color:' + (currentSlide > 0 ? '#374151' : '#d1d5db') + ';padding:4px;" ' + (currentSlide === 0 ? 'disabled' : '') + '>';
                nav += '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>';
                nav += '<div style="display:flex;gap:6px;">';
                for (var i = 0; i < newsItems.length; i++) {
                    nav += '<button type="button" class="optee-news-dot" data-i="' + i + '" style="width:8px;height:8px;border-radius:50%;border:none;cursor:pointer;padding:0;background:' + (i === currentSlide ? color : '#d1d5db') + ';transition:background 0.2s;"></button>';
                }
                nav += '</div>';
                nav += '<button type="button" class="optee-news-next" style="background:none;border:none;cursor:pointer;color:' + (currentSlide < newsItems.length - 1 ? '#374151' : '#d1d5db') + ';padding:4px;" ' + (currentSlide === newsItems.length - 1 ? 'disabled' : '') + '>';
                nav += '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>';
                nav += '</div>';
                $('#optee-news-nav').html(nav).show();
            } else {
                $('#optee-news-nav').hide();
            }
        }

        // Navigation
        $(document).on('click', '.optee-news-prev', function() {
            if (currentSlide > 0) { currentSlide--; renderSlide(); }
        });
        $(document).on('click', '.optee-news-next', function() {
            if (currentSlide < newsItems.length - 1) { currentSlide++; renderSlide(); }
        });
        $(document).on('click', '.optee-news-dot', function() {
            currentSlide = parseInt($(this).data('i'));
            renderSlide();
        });

        // Close & dismiss all current news
        $('#optee-news-close').on('click', function() {
            $('#optee-news-popup').hide();
            var ids = newsItems.map(function(n) { return n.id; });
            if (typeof Optee !== 'undefined' && Optee.Ajax) {
                Optee.Ajax.post('optee_dismiss_news', { news_ids: ids });
            }
        });
        $('#optee-news-popup').on('click', function(e) {
            if (e.target === this) {
                $('#optee-news-close').trigger('click');
            }
        });

        // Fetch news on load (wait for Optee to be available)
        $(function() {
            if (typeof Optee === 'undefined' || !Optee.Ajax) return;
            Optee.Ajax.post('optee_get_news', {}).done(function(res) {
                if (res.success && res.data && res.data.length) {
                    newsItems = res.data;
                    currentSlide = 0;
                    renderSlide();
                    $('#optee-news-popup').css('display', 'flex');
                }
            });
        });
    })();

    // Handle update button on integration cards.
    $(document).on('click', '.optee-update-from-card', function() {
        var $btn = $(this).prop('disabled', true);
        var slug = $btn.data('slug');
        $btn.css('opacity', '0.7').html('Updating...');

        Optee.Ajax.post('optee_update_plugin_from_api', { slug: slug }).done(function(res) {
            if (res.success) {
                $btn.html('Updated!').css({ background: '#22c55e' });
                Optee.Toast.show(res.data.message, 'success');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                Optee.Toast.show(res.data.message || 'Update failed.', 'error');
                $btn.prop('disabled', false).css('opacity', '1').html('Retry');
            }
        }).fail(function() {
            Optee.Toast.show('Network error.', 'error');
            $btn.prop('disabled', false).css('opacity', '1').html('Retry');
        });
    });
})(jQuery);
</script>

<!-- Install from API handler -->
<script>
(function($) {
    'use strict';

    $(document).on('click', '.optee-install-from-api', function() {
        var $btn = $(this);
        var slug = $btn.data('slug');
        var name = $btn.data('name') || slug;

        if (!confirm('Install ' + name + '?')) return;

        $btn.prop('disabled', true).html('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;animation:spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Installing...');

        Optee.Ajax.post('optee_install_plugin_from_api', { slug: slug }).done(function(res) {
            if (res.success) {
                Optee.Toast.show(res.data.message + ' Click Activate to enable.', 'success');
                // Transform to Activate button.
                var pluginFile = slug + '/' + slug + '.php';
                $btn.removeClass('optee-btn-primary optee-install-from-api').addClass('optee-btn-success optee-activate-plugin')
                    .data('plugin', pluginFile)
                    .attr('data-plugin', pluginFile)
                    .prop('disabled', false)
                    .html('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/></svg> Activate');
                // Update badge from "Available" to "Inactive".
                $btn.closest('.optee-card').find('.optee-badge-blue').removeClass('optee-badge-blue').addClass('optee-badge-gray').text('Inactive');
            } else {
                Optee.Toast.show(res.data.message || 'Install failed.', 'error');
                $btn.prop('disabled', false).html('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> Install');
            }
        }).fail(function() {
            Optee.Toast.show('Network error. Please try again.', 'error');
            $btn.prop('disabled', false).html('<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg> Install');
        });
    });
})(jQuery);
</script>

<!-- Activation Modal -->
<?php if ( ! \Optee\Plugin\Activation::is_activated() && get_option( 'optee_setup_complete', '' ) ) :
    $current_user = wp_get_current_user();
    $prefill_name = trim( $current_user->first_name . ' ' . $current_user->last_name ) ?: $current_user->display_name;
    $prefill_email = $current_user->user_email;
?>
<div id="optee-activation-overlay" class="fixed inset-0 z-[100] flex items-center justify-center" style="background:rgba(0,0,0,.5);backdrop-filter:blur(4px);">
    <div style="background:#fff;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);width:100%;max-width:420px;margin:0 16px;overflow:hidden;">
        <div style="padding:32px 32px 16px;text-align:center;">
            <img src="<?php echo esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-logo-light.svg' ); ?>" alt="Optee" style="height:32px;margin:0 auto 16px;">
            <h2 style="font-size:18px;font-weight:700;color:#111827;margin:0;">Activate Optee</h2>
            <p style="font-size:14px;color:#6b7280;margin-top:6px;">Enter your license key to unlock the dashboard and automation.</p>
        </div>
        <form id="optee-activation-form" style="padding:0 32px 32px;display:flex;flex-direction:column;gap:12px;">
            <div>
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;" for="optee-act-key">License Key</label>
                <input type="text" id="optee-act-key" class="optee-input" placeholder="OPTEE-XXXXX-XXXXX-XXXXX-XXXXX" required
                       style="text-transform:uppercase;letter-spacing:.05em;font-family:monospace;width:100%;">
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;" for="optee-act-name">Your Name</label>
                <input type="text" id="optee-act-name" class="optee-input" placeholder="John Doe" required style="width:100%;"
                       value="<?php echo esc_attr( $prefill_name ); ?>">
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:4px;" for="optee-act-email">Email Address</label>
                <input type="email" id="optee-act-email" class="optee-input" placeholder="john@example.com" required style="width:100%;"
                       value="<?php echo esc_attr( $prefill_email ); ?>">
            </div>
            <div id="optee-act-error" style="display:none;font-size:14px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;"></div>
            <button type="submit" id="optee-act-btn" class="optee-btn-primary" style="width:100%;justify-content:center;margin-top:8px;">
                Activate
            </button>
        </form>
    </div>
</div>

<script>
(function($) {
    'use strict';
    var $form = $('#optee-activation-form');
    var $btn = $('#optee-act-btn');
    var $error = $('#optee-act-error');

    $form.on('submit', function(e) {
        e.preventDefault();
        $error.hide();
        $btn.prop('disabled', true).text('Activating...');

        var key = $('#optee-act-key').val().trim().toUpperCase();
        var name = $('#optee-act-name').val().trim();
        var email = $('#optee-act-email').val().trim();

        Optee.Ajax.post('optee_validate_activation_key', {
            key: key,
            name: name,
            email: email
        }).done(function(res) {
            if (res.success) {
                Optee.Toast.show('License activated!', 'success');
                $('#optee-activation-overlay').fadeOut(300, function() { $(this).remove(); });
                if (typeof opteeData !== 'undefined') {
                    opteeData.activationValid = true;
                }
            } else {
                $error.text(res.data.message || 'Activation failed.').show();
                $btn.prop('disabled', false).text('Activate');
            }
        }).fail(function() {
            $error.text('Network error. Please try again.').show();
            $btn.prop('disabled', false).text('Activate');
        });
    });

    // Auto-format key input with dashes.
    $('#optee-act-key').on('input', function() {
        var val = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
        // If user is typing without the OPTEE- prefix, add it.
        if (val.length > 0 && !val.startsWith('OPTEE') && !val.startsWith('O')) {
            val = 'OPTEE-' + val;
        }
        this.value = val;
    });
})(jQuery);
</script>
<?php endif; ?>

<!-- Expired License Modal -->
<?php if ( \Optee\Plugin\Activation::is_activated() && \Optee\Plugin\Activation::is_expired() ) :
    $expiry_date = get_option( 'optee_activation_expiry', '' );
?>
<div id="optee-expired-overlay" class="fixed inset-0 z-[100] flex items-center justify-center" style="background:rgba(0,0,0,.5);backdrop-filter:blur(4px);">
    <div style="background:#fff;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);width:100%;max-width:420px;margin:0 16px;overflow:hidden;">
        <div style="padding:32px 32px 24px;text-align:center;">
            <img src="<?php echo esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-logo-light.svg' ); ?>" alt="Optee" style="height:32px;margin:0 auto 16px;">
            <div style="width:48px;height:48px;margin:0 auto 16px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <h2 style="font-size:18px;font-weight:700;color:#111827;margin:0;">License Expired</h2>
            <p style="font-size:14px;color:#6b7280;margin-top:8px;line-height:1.5;">Your Optee license expired on <b style="color:#dc2626;"><?php echo esc_html( date( 'F j, Y', strtotime( $expiry_date ) ) ); ?></b>.</p>
            <p style="font-size:14px;color:#6b7280;margin-top:8px;line-height:1.5;">Automation and plugin updates are disabled. Renew your license or enter a new key to restore full functionality.</p>
        </div>
        <div style="padding:0 32px 32px;display:flex;flex-direction:column;gap:10px;">
            <a href="https://optee.co" target="_blank" class="optee-btn-primary" style="width:100%;justify-content:center;text-decoration:none;">
                Renew License
            </a>
            <button type="button" id="optee-expired-enter-key" style="width:100%;padding:8px 16px;font-size:14px;font-weight:500;color:#2aa5ff;background:none;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                Enter a Different Key
            </button>
            <div id="optee-expired-key-form" style="display:none;flex-direction:column;gap:8px;">
                <input type="text" id="optee-expired-key-input" class="optee-input" placeholder="OPTEE-XXXXX-XXXXX-XXXXX-XXXXX"
                       style="text-transform:uppercase;letter-spacing:.05em;font-family:monospace;width:100%;font-size:13px;">
                <div id="optee-expired-key-error" style="display:none;font-size:13px;color:#dc2626;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px;"></div>
                <button type="button" id="optee-expired-key-submit" class="optee-btn-primary" style="width:100%;justify-content:center;">
                    Activate New Key
                </button>
            </div>
            <button type="button" id="optee-expired-dismiss" style="width:100%;padding:8px 16px;font-size:13px;color:#9ca3af;background:none;border:none;cursor:pointer;">
                Continue without license
            </button>
        </div>
    </div>
</div>
<script>
(function($) {
    'use strict';
    $('#optee-expired-dismiss').on('click', function() {
        $('#optee-expired-overlay').fadeOut(300, function() { $(this).remove(); });
    });

    $('#optee-expired-enter-key').on('click', function() {
        $(this).hide();
        $('#optee-expired-key-form').css('display', 'flex');
        $('#optee-expired-key-input').focus();
    });

    $('#optee-expired-key-input').on('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
    });

    $('#optee-expired-key-submit').on('click', function() {
        var $btn = $(this);
        var $error = $('#optee-expired-key-error');
        var key = $('#optee-expired-key-input').val().trim().toUpperCase();
        if (!key) return;

        $error.hide();
        $btn.prop('disabled', true).text('Activating...');

        Optee.Ajax.post('optee_validate_activation_key', {
            key: key,
            name: '<?php echo esc_js( trim( wp_get_current_user()->first_name . ' ' . wp_get_current_user()->last_name ) ?: wp_get_current_user()->display_name ); ?>',
            email: '<?php echo esc_js( wp_get_current_user()->user_email ); ?>'
        }).done(function(res) {
            if (res.success) {
                Optee.Toast.show('License activated!', 'success');
                $('#optee-expired-overlay').fadeOut(300, function() { $(this).remove(); });
            } else {
                $error.text(res.data.message || 'Activation failed.').show();
                $btn.prop('disabled', false).text('Activate New Key');
            }
        }).fail(function() {
            $error.text('Network error. Please try again.').show();
            $btn.prop('disabled', false).text('Activate New Key');
        });
    });
})(jQuery);
</script>
<?php endif; ?>

<!-- Promo Announcement Popup -->
<div id="optee-promo-popup" style="display:none;position:fixed;inset:0;z-index:95;background:rgba(0,0,0,.4);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);width:100%;max-width:420px;margin:0 16px;overflow:hidden;">
        <div style="position:relative;padding:28px 28px 20px;text-align:center;">
            <button type="button" id="optee-promo-popup-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <div id="optee-promo-popup-logo" style="margin-bottom:12px;"></div>
            <h3 id="optee-promo-popup-title" style="font-size:18px;font-weight:700;color:#111827;margin:0 0 6px;"></h3>
            <p id="optee-promo-popup-desc" style="font-size:14px;color:#6b7280;margin:0 0 16px;line-height:1.5;"></p>
            <div id="optee-promo-popup-tiers" style="text-align:left;margin-bottom:20px;"></div>
            <a id="optee-promo-popup-link" href="#" class="optee-btn-primary" style="display:inline-flex;align-items:center;gap:6px;justify-content:center;width:100%;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>
                View Promo Codes
            </a>
        </div>
    </div>
</div>

<script>
(function($) {
    'use strict';

    $(function() {
        // Only show promo popup on non-promo-codes pages.
        if (typeof opteeData === 'undefined') return;
        var currentModule = new URLSearchParams(window.location.search).get('module') || 'dashboard';
        if (currentModule === 'promo-codes') return;

        var promoUrl = opteeData.dashboardUrl + '?module=promo-codes';

        setTimeout(function() {
            Optee.Ajax.post('optee_get_promo_campaigns').done(function(res) {
            var campaigns = (res.data && res.data.campaigns) || [];
            // Find first undismissed active (or draft, when in preview mode) campaign.
            var campaign = null;
            for (var i = 0; i < campaigns.length; i++) {
                var c = campaigns[i];
                if ((c.status === 'active' || c.status === 'draft') && !c.dismissed) {
                    campaign = c;
                    break;
                }
            }
            if (!campaign) return;

            var E = Optee.Util.escapeHtml;

            // Populate popup.
            if (campaign.logo_url) {
                $('#optee-promo-popup-logo').html('<img src="' + E(campaign.logo_url) + '" alt="" style="max-width:80px;max-height:60px;object-fit:contain;">');
            }
            $('#optee-promo-popup-title').text((campaign._draft ? '[DRAFT] ' : '') + campaign.title);
            $('#optee-promo-popup-desc').text(campaign.description || (campaign.traffic_source_name + ' promo codes available!'));

            // Tier list.
            var tiersHtml = '';
            var tiers = campaign.tiers || [];
            for (var j = 0; j < tiers.length; j++) {
                var t = tiers[j];
                tiersHtml += '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:#f9fafb;border-radius:8px;margin-bottom:6px;">';
                tiersHtml += '<span style="font-size:13px;font-weight:600;color:#111827;">' + E(t.label) + '</span>';
                tiersHtml += '<span style="font-size:12px;color:#6b7280;">' + t.remaining_codes + ' left</span>';
                tiersHtml += '</div>';
            }
            $('#optee-promo-popup-tiers').html(tiersHtml);
            $('#optee-promo-popup-link').attr('href', promoUrl);

            // Show popup.
            $('#optee-promo-popup').css('display', 'flex');

            // Dismiss handler.
            var cid = campaign.id;
            $('#optee-promo-popup-close').on('click', function() {
                $('#optee-promo-popup').hide();
                Optee.Ajax.post('optee_dismiss_promo_popup', { campaign_id: cid });
            });
            $('#optee-promo-popup').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                    Optee.Ajax.post('optee_dismiss_promo_popup', { campaign_id: cid });
                }
            });
        });
        }, 2000);
    });
})(jQuery);
</script>

<!-- Conflicting Plugins Warning -->
<?php
$user_id = get_current_user_id();
$cache_warning_dismissed = (bool) get_user_meta( $user_id, 'optee_cache_warning_dismissed', true );
if ( ! $cache_warning_dismissed && ! empty( $conflicting_plugins ) ) :
?>
<div id="optee-cache-warning" style="display:none;position:fixed;inset:0;z-index:95;background:rgba(0,0,0,.4);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);width:100%;max-width:460px;margin:0 16px;overflow:hidden;">
        <div style="position:relative;padding:28px 28px 24px;">
            <button type="button" id="optee-cache-warning-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                <div style="width:40px;height:40px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                </div>
                <h3 style="font-size:17px;font-weight:700;color:#111827;margin:0;">Caching Plugin Detected</h3>
            </div>
            <p style="font-size:14px;color:#4b5563;margin:0 0 14px;line-height:1.6;">
                The following plugin<?php echo count( $conflicting_plugins ) > 1 ? 's are' : ' is'; ?> active and may cache the Optee dashboard, causing stale data or broken functionality:
            </p>
            <div style="margin-bottom:18px;">
                <?php foreach ( $conflicting_plugins as $cp ) : ?>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#fef3c7;border-radius:8px;margin-bottom:6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                    <span style="font-size:13px;font-weight:600;color:#92400e;"><?php echo esc_html( $cp['name'] ); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <p style="font-size:13px;color:#6b7280;margin:0 0 20px;line-height:1.5;">
                We recommend deactivating caching plugins or excluding the Optee dashboard page from their cache settings.
            </p>
            <div style="display:flex;gap:10px;">
                <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="optee-btn-primary" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                    Go to Plugins
                </a>
                <button type="button" id="optee-cache-warning-dismiss" class="optee-btn-secondary" style="flex:1;">
                    Dismiss
                </button>
            </div>
        </div>
    </div>
</div>
<script>
(function($) {
    'use strict';
    $(function() {
        setTimeout(function() { $('#optee-cache-warning').css('display', 'flex'); }, 500);

        function dismissCacheWarning() {
            $('#optee-cache-warning').fadeOut(200);
            Optee.Ajax.post('optee_dismiss_cache_warning');
        }

        $('#optee-cache-warning-close, #optee-cache-warning-dismiss').on('click', dismissCacheWarning);
        $('#optee-cache-warning').on('click', function(e) {
            if (e.target === this) dismissCacheWarning();
        });
    });
})(jQuery);
</script>
<?php endif; ?>

<!-- Toast container -->
<div id="optee-toasts" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2"></div>
