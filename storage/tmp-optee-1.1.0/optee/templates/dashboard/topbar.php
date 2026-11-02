<?php
/**
 * Top navigation bar.
 *
 * Variables available: $current_module, $registry
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cron_last_run = get_option( 'optee_cron_last_complete', '' );
$cron_status   = 'unknown';
$cron_text     = 'Never';

if ( $cron_last_run ) {
    $now_local = current_time( 'timestamp' );
    $last_ts   = strtotime( $cron_last_run );
    $diff      = $now_local - $last_ts;
    $cron_text = human_time_diff( $last_ts, $now_local ) . ' ago';
    if ( $diff < 300 ) {
        $cron_status = 'ok';
    } elseif ( $diff < 900 ) {
        $cron_status = 'warning';
    } else {
        $cron_status = 'error';
    }
}

$cron_dot_color = match ( $cron_status ) {
    'ok'      => 'bg-green-500',
    'warning' => 'bg-yellow-500',
    'error'   => 'bg-red-500',
    default   => 'bg-gray-500',
};

$current_user  = wp_get_current_user();
$cron_url      = \Optee\Plugin\CronHandler::get_cron_url();

// Timezone offset for mismatch detection.
$_tz_string = get_option( 'timezone_string', '' );
$_gmt_offset = get_option( 'gmt_offset', 0 );
if ( $_tz_string ) {
    $_tz_obj = new \DateTimeZone( $_tz_string );
} else {
    $_off = (float) $_gmt_offset;
    $_sign = $_off >= 0 ? '+' : '-';
    $_abs = abs( $_off );
    $_tz_obj = new \DateTimeZone( sprintf( '%s%02d:%02d', $_sign, intval( $_abs ), ( $_abs - intval( $_abs ) ) * 60 ) );
}
$_wp_offset_sec = $_tz_obj->getOffset( new \DateTime( 'now', $_tz_obj ) );
?>
<header class="flex h-16 items-center justify-between border-b border-gray-200 bg-gray-50 px-6">
    <!-- Left: page title / breadcrumb -->
    <div class="flex items-center gap-4">
        <!-- Mobile: small logo icon (hidden on desktop) -->
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url() ); ?>" id="optee-topbar-logo" style="display:none;">
            <img src="<?php echo esc_url( OPTEE_PLUGIN_URL . 'assets/images/optee-icon-light.svg' ); ?>" alt="Optee" style="height:28px;width:28px;">
        </a>
        <?php
        // The CSS `capitalize` handles most slugs; these carry internal capitals
        // it would flatten ("Landersync"), so they're spelled out to match the sidebar.
        $_module_titles = [
            'landersync' => 'LanderSync',
            'virustotal' => 'VirusTotal',
        ];
        ?>
        <h1 id="optee-topbar-title" class="text-lg font-semibold text-gray-900 capitalize">
            <?php echo esc_html( $_module_titles[ $current_module ] ?? str_replace( '-', ' ', $current_module ) ); ?>
        </h1>
    </div>

    <!-- Center: Command Palette trigger -->
    <button type="button" class="optee-cmd-trigger" id="optee-cmd-trigger" title="Search (Ctrl+K)">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <span style="flex:1;text-align:left;">Search...</span>
        <kbd>Ctrl+K</kbd>
    </button>

    <!-- Right: actions -->
    <div class="flex items-center gap-3">
        <!-- Automation status -->
        <a id="optee-automation-status" href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'logs' ) ); ?>"
           class="flex items-center gap-2 rounded-lg bg-white px-3 py-1.5 text-xs text-gray-500 hover:bg-gray-100 transition-colors">
            <span id="optee-automation-dot" class="h-2 w-2 rounded-full <?php echo esc_attr( $cron_dot_color ); ?>"></span>
            <span id="optee-automation-text">Automation: <?php echo esc_html( $cron_text ); ?></span>
        </a>

        <!-- New Campaign -->
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'campaigns', 'create' ) ); ?>"
           class="optee-btn-primary optee-btn-sm optee-new-campaign-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            <span class="optee-new-campaign-label">New Campaign</span><span class="optee-new-campaign-short">New</span>
        </a>

        <!-- Notifications -->
        <div class="relative" id="optee-notifications-wrapper">
            <button id="optee-notifications-btn"
                    class="optee-btn-ghost optee-btn-sm relative"
                    title="Notifications">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                <span id="optee-notification-badge" class="absolute hidden rounded-full bg-red-500 font-bold text-white text-center" style="top:-4px;right:-6px;font-size:10px;min-width:18px;height:18px;line-height:18px;padding:0 4px;"></span>
            </button>
            <?php include OPTEE_PLUGIN_DIR . 'templates/partials/notification-dropdown.php'; ?>
        </div>

        <!-- Settings -->
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'profile' ) ); ?>"
           id="optee-topbar-settings"
           class="optee-btn-ghost optee-btn-sm"
           title="Settings">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
        </a>

        <!-- Mobile menu toggle (visible < 768px) -->
        <button type="button" id="optee-mobile-menu-btn" class="optee-btn-ghost optee-btn-sm" title="Menu">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
        </button>

        <!-- User dropdown -->
        <div class="relative pl-2 border-l border-gray-200" id="optee-user-dropdown-wrap" data-wp-offset="<?php echo intval( $_wp_offset_sec ); ?>">
            <button type="button" id="optee-user-dropdown-btn" class="flex items-center gap-2 cursor-pointer rounded-md px-1 py-1 hover:bg-gray-100 transition-colors" style="background:none;border:none;">
                <div class="relative h-7 w-7 rounded-full bg-brand-600 flex items-center justify-center text-xs font-bold text-white">
                    <?php echo esc_html( strtoupper( substr( $current_user->display_name, 0, 1 ) ) ); ?>
                    <span id="optee-tz-avatar-dot" class="hidden absolute rounded-full bg-red-500" style="width:8px;height:8px;top:-1px;right:-1px;border:2px solid #f9fafb;"></span>
                </div>
                <span class="text-sm text-gray-600 hidden sm:inline"><?php echo esc_html( $current_user->display_name ); ?></span>
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-400"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <div id="optee-user-dropdown" class="hidden absolute right-0 mt-1 w-48 rounded-lg bg-white shadow-lg border border-gray-200 py-1" style="z-index:50;">
                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'profile' ) ); ?>"
                   class="flex items-center gap-2 px-4 py-4 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    My Profile
                    <span id="optee-tz-menu-warn" class="hidden inline-flex items-center justify-center rounded-full ml-auto" title="Timezone mismatch" style="width:20px;height:20px;background:#f59e0b;">
                        <svg class="text-white" style="width:12px;height:12px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                    </span>
                </a>
                <div class="border-t border-gray-100 my-1"></div>
                <?php if ( get_option( 'optee_has_tools', '0' ) === '1' || ( defined( 'OPTEE_DEV_TOOLS' ) && OPTEE_DEV_TOOLS ) ) : ?>
                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'tools' ) ); ?>"
                   class="flex items-center gap-2 px-4 py-4 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                    Tools
                </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( $cron_url ); ?>" target="_blank" rel="noopener noreferrer"
                   class="flex items-center gap-2 px-4 py-4 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 9 15 9"/></svg>
                    Run Automation
                </a>
                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'knowledgebase' ) ); ?>"
                   class="flex items-center gap-2 px-4 py-4 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg>
                    Knowledgebase
                </a>
                <a href="#" id="optee-start-tour"
                   class="optee-hide-mobile flex items-center gap-2 px-4 py-4 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>
                    Take a Tour
                </a>
                <a href="<?php echo esc_url( admin_url() ); ?>" target="_blank" rel="noopener noreferrer"
                   class="flex items-center gap-2 px-4 py-4 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                    Open WP Admin
                </a>
                <div class="border-t border-gray-100 my-1"></div>
                <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>"
                   onclick="if(!confirm('Are you sure you want to log out?'))return false;"
                   class="flex items-center gap-2 px-4 py-4 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Logout
                </a>
            </div>
        </div>
    </div>
</header>
<script>
(function(){
    var btn = document.getElementById('optee-user-dropdown-btn');
    var menu = document.getElementById('optee-user-dropdown');
    if (!btn || !menu) return;
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        menu.classList.toggle('hidden');
    });
    document.addEventListener('click', function(e) {
        if (!menu.contains(e.target) && e.target !== btn) {
            menu.classList.add('hidden');
        }
    });
})();

// Automation heartbeat polling.
document.addEventListener('DOMContentLoaded', function(){
    var dot  = document.getElementById('optee-automation-dot');
    var text = document.getElementById('optee-automation-text');
    if (!dot || !text || typeof opteeData === 'undefined') return;

    var interval = Math.max(10, parseInt(opteeData.heartbeatInterval, 10) || 10) * 1000;
    var colorMap = { ok: 'bg-green-500', warning: 'bg-yellow-500', error: 'bg-red-500', running: 'bg-blue-500', unknown: 'bg-gray-500' };

    function poll() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', opteeData.ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4 || xhr.status !== 200) return;
            try {
                var res = JSON.parse(xhr.responseText);
                if (!res.success) return;
                var status = res.data.status;
                var dotClass = 'h-2 w-2 rounded-full ' + (colorMap[status] || 'bg-gray-500');
                if (res.data.automation_running) {
                    status = 'running';
                    dotClass = 'h-2 w-2 rounded-full bg-blue-500 optee-pulse';
                    text.textContent = 'Automation: Running\u2026';
                } else {
                    text.textContent = 'Automation: ' + res.data.last_run;
                }
                dot.className = dotClass;
            } catch(e) {}
        };
        xhr.send('action=optee_get_cron_status&nonce=' + encodeURIComponent(opteeData.nonce));
    }

    poll();
    setInterval(poll, interval);
});

// Timezone mismatch indicator in dropdown.
(function(){
    var wrap = document.getElementById('optee-user-dropdown-wrap');
    var warn = document.getElementById('optee-tz-menu-warn');
    var dot  = document.getElementById('optee-tz-avatar-dot');
    if (!wrap) return;
    var wpOffset = parseInt(wrap.dataset.wpOffset, 10);
    var browserOffset = -new Date().getTimezoneOffset() * 60;
    if (wpOffset !== browserOffset) {
        if (warn) warn.classList.remove('hidden');
        if (dot) dot.classList.remove('hidden');
    }
})();
</script>
