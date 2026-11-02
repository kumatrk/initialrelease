<?php
/**
 * Settings — Profile.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cache_ttl = metadata_exists( 'user', get_current_user_id(), 'optee_cache_ttl' )
    ? intval( get_user_meta( get_current_user_id(), 'optee_cache_ttl', true ) )
    : 5;

$cache_buster = get_user_meta( get_current_user_id(), 'optee_cache_buster', true );

$stats_decimals = metadata_exists( 'user', get_current_user_id(), 'optee_stats_decimals' )
    ? max( 0, min( 5, intval( get_user_meta( get_current_user_id(), 'optee_stats_decimals', true ) ) ) )
    : 2;
$highlight_weekends = (bool) get_user_meta( get_current_user_id(), 'optee_highlight_weekends', true );

$heartbeat_interval = intval( get_user_meta( get_current_user_id(), 'optee_heartbeat_interval', true ) ) ?: 10;
$lock_timeout = intval( get_user_meta( get_current_user_id(), 'optee_lock_timeout', true ) ) ?: 5;
$max_execution_time = intval( get_user_meta( get_current_user_id(), 'optee_max_execution_time', true ) );
$php_max_execution = intval( ini_get( 'max_execution_time' ) );

$wp_tz_string = get_option( 'timezone_string', '' );
$wp_gmt_offset = get_option( 'gmt_offset', 0 );

if ( $wp_tz_string ) {
    $wp_tz_label = $wp_tz_string;
    $wp_tz = new \DateTimeZone( $wp_tz_string );
} else {
    $offset_hours = (float) $wp_gmt_offset;
    $wp_tz_label = 'UTC' . ( $offset_hours >= 0 ? '+' : '' ) . $offset_hours;
    $sign = $offset_hours >= 0 ? '+' : '-';
    $abs = abs( $offset_hours );
    $h = intval( $abs );
    $m = ( $abs - $h ) * 60;
    $wp_tz = new \DateTimeZone( sprintf( '%s%02d:%02d', $sign, $h, $m ) );
}
$wp_now = new \DateTime( 'now', $wp_tz );
$wp_offset_sec = $wp_tz->getOffset( $wp_now );
?>
<div class="space-y-6">
    <div class="flex items-center gap-4 border-b border-gray-200 pb-4">
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'profile' ) ); ?>"
           class="text-sm font-medium text-gray-900 border-b-2 border-brand-500 pb-2">Profile</a>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'status' ) ); ?>"
           class="text-sm font-medium text-gray-500 hover:text-gray-900 pb-2">System Status</a>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'license' ) ); ?>"
           class="text-sm font-medium text-gray-500 hover:text-gray-900 pb-2">License</a>
    </div>

    <div>
        <h2 class="text-xl font-bold text-gray-900">Profile</h2>
        <p class="mt-1 text-sm text-gray-500">Manage your personal preferences.</p>
    </div>

    <!-- Timezone -->
    <div class="optee-card" id="optee-tz-card" data-wp-offset="<?php echo intval( $wp_offset_sec ); ?>">
        <h3 class="font-semibold text-gray-900 mb-1">Timezone</h3>
        <p class="text-xs text-gray-500 mb-4">Make sure WordPress timezone matches your local timezone for accurate report times.</p>

        <div class="text-sm text-gray-700 space-y-1">
            <div>
                <span class="font-medium">System:</span>
                <span id="optee-tz-wp-label"><?php echo esc_html( $wp_tz_label ); ?></span>
                <span class="text-gray-400 ml-1" id="optee-tz-wp-time"><?php echo esc_html( $wp_now->format( 'H:i:s' ) ); ?></span>
            </div>
            <div>
                <span class="font-medium">Browser:</span>
                <span id="optee-tz-browser-label">—</span>
                <span class="text-gray-400 ml-1" id="optee-tz-browser-time"></span>
            </div>
        </div>

        <div id="optee-tz-warning" class="hidden mt-4 p-3 border rounded-lg text-sm" style="background:#fffbeb;border-color:#fcd34d;color:#92400e;">
            <span class="inline-flex items-center justify-center rounded-full mr-1.5 align-middle" style="width:22px;height:22px;background:#f59e0b;">
                <svg class="text-white" style="width:14px;height:14px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </span>
            Your browser timezone doesn't match system settings. Reports may show times that differ from your local clock.
            <div class="mt-2">
                <button type="button" id="optee-tz-fix-btn" class="optee-btn-primary optee-btn-sm">
                    Set to <span id="optee-tz-fix-label"></span>
                </button>
            </div>
        </div>

        <div id="optee-tz-match" class="hidden mt-4 flex items-center gap-2 p-3 bg-green-50 border rounded-lg text-sm" style="border-color:#86efac;color:#15803d;">
            <svg style="width:20px;height:20px;min-width:20px;color:#22c55e;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            Timezones match — reports will show your local time.
        </div>
    </div>

    <!-- Display -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-1">Display</h3>
        <p class="text-xs text-gray-500 mb-4">How stats values are shown on the Dashboard and Campaign Stats pages.</p>

        <form id="optee-display-prefs-form" class="space-y-4" style="max-width:480px;">
            <div class="flex items-center gap-3">
                <label class="text-sm text-gray-700 font-medium whitespace-nowrap" for="optee-stats-decimals" style="width:140px;">Decimal places</label>
                <select id="optee-stats-decimals" class="optee-input" style="width:80px;">
                    <?php for ( $d = 0; $d <= 5; $d++ ) : ?>
                        <option value="<?php echo $d; ?>" <?php selected( $stats_decimals, $d ); ?>><?php echo $d; ?></option>
                    <?php endfor; ?>
                </select>
                <span class="text-sm text-gray-500">for money &amp; percent columns (default 2)</span>
            </div>

            <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700">
                <input type="checkbox" id="optee-highlight-weekends" <?php checked( $highlight_weekends ); ?>>
                <span>Highlight weekend rows (Sat/Sun) in the Days report</span>
            </label>

            <button type="submit" class="optee-btn-primary optee-btn-sm">Save</button>
        </form>
    </div>

    <!-- Cache Settings -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-1">Cache Settings</h3>
        <p class="text-xs text-gray-500 mb-4">Stats on the Dashboard and Campaign Stats pages are cached to reduce API calls. Rules always use live data. Set to 0 to disable.</p>

        <form id="optee-cache-ttl-form" class="flex items-center gap-3" style="max-width:360px;">
            <label class="text-sm text-gray-700 font-medium whitespace-nowrap" for="optee-cache-ttl">Cache TTL</label>
            <input type="number" id="optee-cache-ttl" class="optee-input" min="0" max="60" step="1"
                   value="<?php echo esc_attr( $cache_ttl ); ?>" style="width:70px;">
            <span class="text-sm text-gray-500">min</span>
            <button type="submit" class="optee-btn-primary optee-btn-sm">Save</button>
        </form>
    </div>

    <!-- Cache Buster -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-1">Cache Buster</h3>
        <p class="text-xs text-gray-500 mb-4">A random string appended to CSS/JS URLs to bypass browser cache. Regenerate after deploying updates.</p>

        <div class="flex items-center gap-3" style="max-width:360px;">
            <input type="text" id="optee-cache-buster" class="optee-input" readonly
                   value="<?php echo esc_attr( $cache_buster ?: 'Not set (using plugin version)' ); ?>">
            <button type="button" id="optee-regenerate-cache-buster" class="optee-btn-primary optee-btn-sm whitespace-nowrap">Regenerate</button>
        </div>
    </div>

    <!-- Automation Status -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-1">Automation Status</h3>
        <p class="text-xs text-gray-500 mb-4">If automation gets stuck (e.g. API timeout), the lock auto-expires after the configured timeout. Use the button below to clear it immediately.</p>

        <form id="optee-lock-timeout-form" class="flex items-center gap-3 mb-4" style="max-width:360px;">
            <label class="text-sm text-gray-700 font-medium whitespace-nowrap" for="optee-lock-timeout">Lock Timeout</label>
            <input type="number" id="optee-lock-timeout" class="optee-input" min="5" max="30" step="1"
                   value="<?php echo esc_attr( $lock_timeout ); ?>" style="width:70px;">
            <span class="text-sm text-gray-500">min</span>
            <button type="submit" class="optee-btn-primary optee-btn-sm">Save</button>
        </form>

        <button type="button" id="optee-reset-lock-btn" class="optee-btn-danger optee-btn-sm">Reset Automation Lock</button>
    </div>

    <!-- Max Execution Time -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-1">Max Execution Time</h3>
        <p class="text-xs text-gray-500 mb-4">Automation will stop picking up new rules when the elapsed time is within 5 seconds of this limit. Set to 0 to auto-detect from PHP (currently <strong><?php echo esc_html( $php_max_execution ); ?>s</strong><?php echo $php_max_execution === 0 ? ' = unlimited' : ''; ?>).</p>

        <form id="optee-max-exec-form" class="flex items-center gap-3" style="max-width:360px;">
            <label class="text-sm text-gray-700 font-medium whitespace-nowrap" for="optee-max-exec-time">Limit</label>
            <input type="number" id="optee-max-exec-time" class="optee-input" min="0" max="300" step="1"
                   value="<?php echo esc_attr( $max_execution_time ); ?>" style="width:70px;">
            <span class="text-sm text-gray-500">sec</span>
            <button type="submit" class="optee-btn-primary optee-btn-sm">Save</button>
        </form>
    </div>

    <!-- Test Email -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-1">Test Email</h3>
        <p class="text-xs text-gray-500 mb-4">Send a test email to verify your server can deliver Optee notifications.</p>

        <form id="optee-test-email-form" class="flex items-center gap-3" style="max-width:480px;">
            <input type="email" id="optee-test-email-to" class="optee-input" placeholder="your@email.com"
                   value="<?php echo esc_attr( get_option( 'admin_email', '' ) ); ?>">
            <select id="optee-test-email-template" class="optee-input" style="width:180px;">
                <option value="automation">Automation Run</option>
                <option value="notifications">Rule Notifications</option>
            </select>
            <button type="submit" class="optee-btn-primary optee-btn-sm whitespace-nowrap">Send Test</button>
        </form>
        <div id="optee-test-email-result" class="mt-3 text-sm hidden"></div>
    </div>

    <!-- Setup Wizard -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-1">Setup Wizard</h3>
        <p class="text-xs text-gray-500 mb-4">Re-run the initial setup wizard to configure timezone, cron, and email verification.</p>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'setup-wizard', '', [ 'restart' => '1' ] ) ); ?>" class="optee-btn-secondary optee-btn-sm">Run Setup Wizard</a>
    </div>

    <!-- Heartbeat Settings -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-1">Heartbeat Interval</h3>
        <p class="text-xs text-gray-500 mb-4">How often the topbar checks automation status. Minimum 10 seconds.</p>

        <form id="optee-heartbeat-form" class="flex items-center gap-3" style="max-width:360px;">
            <label class="text-sm text-gray-700 font-medium whitespace-nowrap" for="optee-heartbeat-interval">Interval</label>
            <input type="number" id="optee-heartbeat-interval" class="optee-input" min="10" max="300" step="1"
                   value="<?php echo esc_attr( $heartbeat_interval ); ?>" style="width:70px;">
            <span class="text-sm text-gray-500">sec</span>
            <button type="submit" class="optee-btn-primary optee-btn-sm">Save</button>
        </form>
    </div>
</div>

<script>
(function ($) {
    'use strict';

    $('#optee-cache-ttl-form').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true);
        var ttl = parseInt($('#optee-cache-ttl').val(), 10) || 0;

        Optee.Ajax.post('optee_save_cache_ttl', { cache_ttl: ttl }).done(function (res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
            } else {
                Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
            }
            $btn.prop('disabled', false);
        }).fail(function () {
            Optee.Toast.show('Request failed.', 'error');
            $btn.prop('disabled', false);
        });
    });

    $('#optee-display-prefs-form').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true);
        var decimals = parseInt($('#optee-stats-decimals').val(), 10);
        if (isNaN(decimals) || decimals < 0 || decimals > 5) decimals = 2;
        var weekends = $('#optee-highlight-weekends').is(':checked') ? 1 : 0;

        Optee.Ajax.post('optee_save_display_prefs', { decimals: decimals, highlight_weekends: weekends }).done(function (res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
                if (typeof opteeData !== 'undefined') {
                    opteeData.statsDecimals = res.data.decimals;
                    opteeData.highlightWeekends = !!res.data.highlight_weekends;
                }
            } else {
                Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
            }
            $btn.prop('disabled', false);
        }).fail(function () {
            Optee.Toast.show('Request failed.', 'error');
            $btn.prop('disabled', false);
        });
    });

    $('#optee-lock-timeout-form').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true);
        var val = Math.max(5, Math.min(30, parseInt($('#optee-lock-timeout').val(), 10) || 5));

        Optee.Ajax.post('optee_save_lock_timeout', { lock_timeout: val }).done(function (res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
            } else {
                Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
            }
            $btn.prop('disabled', false);
        }).fail(function () {
            Optee.Toast.show('Request failed.', 'error');
            $btn.prop('disabled', false);
        });
    });

    $('#optee-max-exec-form').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true);
        var val = Math.max(0, Math.min(300, parseInt($('#optee-max-exec-time').val(), 10) || 0));

        Optee.Ajax.post('optee_save_max_execution_time', { max_execution_time: val }).done(function (res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
            } else {
                Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
            }
            $btn.prop('disabled', false);
        }).fail(function () {
            Optee.Toast.show('Request failed.', 'error');
            $btn.prop('disabled', false);
        });
    });

    $('#optee-reset-lock-btn').on('click', function () {
        if (!confirm('Are you sure you want to reset the automation lock?')) return;
        var $btn = $(this).prop('disabled', true);

        Optee.Ajax.post('optee_reset_automation_lock', {}).done(function (res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
            } else {
                Optee.Toast.show(res.data.message || 'Failed to reset.', 'error');
            }
            $btn.prop('disabled', false);
        }).fail(function () {
            Optee.Toast.show('Request failed.', 'error');
            $btn.prop('disabled', false);
        });
    });

    $('#optee-regenerate-cache-buster').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        Optee.Ajax.post('optee_save_cache_buster', {}).done(function (res) {
            if (res.success) {
                $('#optee-cache-buster').val(res.data.cache_buster);
                Optee.Toast.show(res.data.message, 'success');
            } else {
                Optee.Toast.show(res.data.message || 'Failed to regenerate.', 'error');
            }
            $btn.prop('disabled', false);
        }).fail(function () {
            Optee.Toast.show('Request failed.', 'error');
            $btn.prop('disabled', false);
        });
    });

    // ── Test Email ──
    $('#optee-test-email-form').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true).text('Sending...');
        var $result = $('#optee-test-email-result').addClass('hidden');

        Optee.Ajax.post('optee_send_test_email', {
            to: $('#optee-test-email-to').val(),
            template: $('#optee-test-email-template').val()
        }).done(function (res) {
            $result.removeClass('hidden');
            if (res.success) {
                $result.html('<span class="text-green-600">' + Optee.Util.escapeHtml(res.data.message) + '</span>');
            } else {
                $result.html('<span class="text-red-600">' + Optee.Util.escapeHtml(res.data.message || 'Failed to send.') + '</span>');
            }
            $btn.prop('disabled', false).text('Send Test');
        }).fail(function () {
            $result.removeClass('hidden').html('<span class="text-red-600">Request failed.</span>');
            $btn.prop('disabled', false).text('Send Test');
        });
    });

    // ── Timezone check ──
    (function () {
        var card = document.getElementById('optee-tz-card');
        if (!card) return;

        var browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        var browserNow = new Date();
        var browserOffsetSec = -browserNow.getTimezoneOffset() * 60; // JS gives negative-west
        var wpOffsetSec = parseInt(card.dataset.wpOffset, 10);

        $('#optee-tz-browser-label').text(browserTz);
        $('#optee-tz-browser-time').text(browserNow.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));

        if (browserOffsetSec === wpOffsetSec) {
            $('#optee-tz-match').removeClass('hidden');
        } else {
            $('#optee-tz-warning').removeClass('hidden');
            $('#optee-tz-fix-label').text(browserTz);
        }

        $('#optee-tz-fix-btn').on('click', function () {
            var $btn = $(this).prop('disabled', true);
            Optee.Ajax.post('optee_save_timezone', { timezone: browserTz }).done(function (res) {
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    $('#optee-tz-wp-label').text(res.data.timezone);
                    $('#optee-tz-wp-time').text(res.data.server_time);
                    $('#optee-tz-warning').addClass('hidden');
                    $('#optee-tz-match').removeClass('hidden');
                    card.dataset.wpOffset = browserOffsetSec;
                } else {
                    Optee.Toast.show(res.data.message || 'Failed to update timezone.', 'error');
                }
                $btn.prop('disabled', false);
            }).fail(function () {
                Optee.Toast.show('Request failed.', 'error');
                $btn.prop('disabled', false);
            });
        });
    })();

    $('#optee-heartbeat-form').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]').prop('disabled', true);
        var val = Math.max(10, parseInt($('#optee-heartbeat-interval').val(), 10) || 10);

        Optee.Ajax.post('optee_save_heartbeat_interval', { interval: val }).done(function (res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
                if (typeof opteeData !== 'undefined') {
                    opteeData.heartbeatInterval = res.data.interval;
                }
            } else {
                Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
            }
            $btn.prop('disabled', false);
        }).fail(function () {
            Optee.Toast.show('Request failed.', 'error');
            $btn.prop('disabled', false);
        });
    });
})(jQuery);
</script>
