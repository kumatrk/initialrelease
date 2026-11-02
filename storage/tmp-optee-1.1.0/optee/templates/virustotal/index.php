<?php
/**
 * VirusTotal — main page.
 *
 * No API key → show configure form inline.
 * API key set → show domain monitoring table + gear icon.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$module = $registry->get_module( 'virustotal' );
$stored = \Optee\Plugin\ModuleRegistry::get_stored_credentials( 'virustotal', 'virustotal' );
?>
<div class="space-y-6">

<?php if ( ! $module ) : ?>
    <div>
        <h2 class="text-xl font-bold text-gray-900">VirusTotal</h2>
        <p class="mt-1 text-sm text-gray-500">Scan and analyze URLs, domains, and landing pages for security threats.</p>
    </div>
    <?php
    // Check if VT plugin is available on R2 for one-click install.
    $vt_api_available = false;
    $api_available    = \Optee\Plugin\Updater::get_available_plugins();
    foreach ( $api_available as $ap ) {
        if ( $ap['slug'] === 'optee-antifraud-virustotal' ) {
            $vt_api_available = true;
            break;
        }
    }
    ?>
    <?php if ( $vt_api_available ) : ?>
        <div class="optee-card text-center py-12" style="max-width:480px;">
            <div class="mx-auto mb-4 w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center text-blue-500">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">VirusTotal Plugin Available</h3>
            <p class="text-sm text-gray-500 mb-4">Install the VirusTotal integration to scan and monitor your domains.</p>
            <button type="button"
                    class="optee-btn-primary optee-install-from-api"
                    data-slug="optee-antifraud-virustotal"
                    data-name="VirusTotal">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                Install
            </button>
        </div>
    <?php else : ?>
        <div class="optee-card text-center py-12" style="max-width:480px;">
            <p class="text-sm text-gray-500">VirusTotal module plugin is not active. Please activate the <strong>Optee - VirusTotal</strong> plugin.</p>
        </div>
    <?php endif; ?>

<?php elseif ( ! $stored ) : ?>
    <?php
    /* ------ No API key: show configure form inline ------ */
    $fields = $module->get_credential_fields();
    $logo_url = '';
    foreach ( [ 'logo.svg', 'logo.png' ] as $logo_file ) {
        $logo_path = WP_PLUGIN_DIR . '/optee-antifraud-virustotal/assets/' . $logo_file;
        if ( file_exists( $logo_path ) ) {
            $logo_url = plugins_url( 'assets/' . $logo_file, WP_PLUGIN_DIR . '/optee-antifraud-virustotal/index.php' );
            break;
        }
    }
    ?>
    <div>
        <h2 class="text-xl font-bold text-gray-900">VirusTotal</h2>
        <p class="mt-1 text-sm text-gray-500">Scan and analyze URLs, domains, and landing pages for security threats.</p>
    </div>

    <div class="optee-card" style="max-width:540px;">
        <div class="flex items-center gap-4 mb-4">
            <?php if ( $logo_url ) : ?>
                <img src="<?php echo esc_url( $logo_url ); ?>"
                     alt="VirusTotal"
                     style="max-height:40px;max-width:140px;width:auto;">
            <?php else : ?>
                <h3 class="text-lg font-semibold text-gray-900">VirusTotal</h3>
            <?php endif; ?>
            <span class="optee-badge-gray ml-auto">Not configured</span>
        </div>

        <form class="optee-credentials-form space-y-4" data-type="virustotal" data-slug="virustotal">
            <?php foreach ( $fields as $field ) :
                $field_type = $field['type'] ?? 'text';
                $field_key  = $field['key'] ?? '';
                $field_val  = '';
            ?>
                <div>
                    <div class="flex items-center justify-between">
                        <label class="optee-label" style="margin-bottom:0"><?php echo esc_html( $field['label'] ?? $field_key ); ?></label>
                        <?php if ( ! empty( $field['help_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $field['help_url'] ); ?>"
                               target="_blank" rel="noopener noreferrer"
                               class="text-xs text-brand-600 hover:text-brand-700 flex items-center gap-1">
                                <?php echo esc_html( $field['help_text'] ?? 'Help' ); ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php if ( $field_type === 'password' ) : ?>
                    <div class="relative">
                        <input type="password"
                               name="credentials[<?php echo esc_attr( $field_key ); ?>]"
                               value=""
                               class="optee-input"
                               style="padding-right:2.5rem;"
                               placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>">
                        <button type="button" class="optee-toggle-password absolute flex items-center justify-center text-gray-400 hover:text-gray-600" style="right:0.5rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0.25rem;">
                            <svg class="optee-eye-show" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="optee-eye-hide hidden" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
                        </button>
                    </div>
                    <?php else : ?>
                    <input type="<?php echo esc_attr( $field_type ); ?>"
                           name="credentials[<?php echo esc_attr( $field_key ); ?>]"
                           value=""
                           class="optee-input"
                           placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="flex items-center gap-2 pt-2">
                <button type="submit" class="optee-btn-primary optee-save-credentials" disabled>Save</button>
                <button type="button" class="optee-test-connection optee-btn-secondary">Test Connection</button>
            </div>
            <div class="optee-credentials-status text-sm mt-2"></div>
        </form>
    </div>

<?php else : ?>
    <?php /* ------ API key set: show main view with gear icon ------ */ ?>
    <?php $vt_interval = get_user_meta( get_current_user_id(), 'optee_vt_check_interval', true ) ?: 30; ?>
    <div>
        <div class="flex items-center gap-2">
            <h2 class="text-xl font-bold text-gray-900">VirusTotal</h2>
            <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'virustotal', 'configure' ) ); ?>"
               class="text-gray-400 hover:text-gray-500 transition-colors" title="Configure">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3" fill="white"/></svg>
            </a>
        </div>
        <p class="mt-1 text-sm text-gray-500">Scan and analyze URLs, domains, and landing pages for security threats.</p>
    </div>

    <div class="flex items-center gap-4 flex-wrap">
        <button type="button" id="vt-add-url-btn" class="optee-btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add New URL
        </button>
        <div class="flex items-center gap-2">
            <label class="text-sm text-gray-500 whitespace-nowrap">Check every</label>
            <input type="number" id="vt-check-interval" class="optee-input" style="width:72px;" min="1" value="<?php echo esc_attr( $vt_interval ); ?>">
            <span class="text-sm text-gray-500">min</span>
            <button type="button" id="vt-save-interval" class="optee-btn-secondary" style="padding:6px 12px;font-size:13px;">Save</button>
        </div>
        <div class="ml-auto flex items-center gap-2">
            <button type="button" id="vt-reload-btn" class="optee-btn-secondary text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                Reload
            </button>
            <select id="vt-days-range" class="optee-select w-auto text-sm">
                <option value="10" selected>Last 10 days</option>
                <option value="15">Last 15 days</option>
                <option value="30">Last 30 days</option>
                <option value="60">Last 60 days</option>
                <option value="90">Last 90 days</option>
                <option value="180">Last 180 days</option>
            </select>
        </div>
    </div>
    <!-- Filled in by updateIntervalHint() once the URL list is known. The safe floor
         scales with how many URLs are active, so a fixed "30 minutes" was only ever
         right for a handful of them. -->
    <p id="vt-interval-hint" class="text-xs text-gray-400 -mt-4">Values lower than 30 minutes can spend your daily API quota quickly.</p>

    <!-- API usage chart. Two series on purpose: what VirusTotal charges against the
         allowance, and what this install actually spent. The allowance is enforced
         per source IP, so a gap between them is other traffic from the same server. -->
    <div id="vt-usage-card" class="optee-card hidden" style="padding:16px 18px;">
        <div class="flex items-center flex-wrap gap-3" style="margin-bottom:10px;">
            <span class="font-semibold text-gray-900" style="font-size:14px;">API usage</span>
            <span id="vt-usage-today" class="text-xs" style="color:#6b7280;"></span>
            <div class="ml-auto flex items-center gap-2">
                <select id="vt-usage-days" class="optee-select w-auto text-sm" style="padding:4px 26px 4px 8px;font-size:12px;">
                    <option value="14" selected>14 days</option>
                    <option value="30">30 days</option>
                </select>
                <button type="button" id="vt-usage-toggle" class="optee-btn-ghost optee-btn-sm" style="font-size:12px;">Hide</button>
            </div>
        </div>
        <div id="vt-usage-body">
            <div id="vt-usage-chart" style="overflow-x:auto;"></div>
            <div class="flex items-center flex-wrap gap-4" style="margin-top:8px;font-size:11px;color:#6b7280;">
                <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#2aa5ff;vertical-align:-1px;margin-right:4px;"></span>Quota used (VirusTotal)</span>
                <span><span style="display:inline-block;width:10px;height:3px;background:#f59e0b;vertical-align:3px;margin-right:4px;"></span>Calls made by Optee</span>
                <span><span style="display:inline-block;width:12px;border-top:2px dashed #ef4444;vertical-align:4px;margin-right:4px;"></span>Daily allowance</span>
            </div>
            <p id="vt-usage-note" class="text-xs text-gray-400" style="margin-top:8px;"></p>
        </div>
    </div>

    <div id="vt-table-wrap" class="optee-card p-0 hidden" style="overflow-x:auto;">
        <table id="vt-table" class="optee-table" style="min-width:100%;">
            <thead id="vt-thead"></thead>
            <tbody id="vt-url-tbody"></tbody>
        </table>
    </div>

    <!-- Mobile card view -->
    <div id="vt-cards" class="hidden"></div>

    <div id="vt-loading" class="optee-card text-center py-10">
        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-gray-300 mx-auto mb-2" style="animation:spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
        <p class="text-sm text-gray-400">Loading...</p>
    </div>

    <div id="vt-empty" class="optee-card text-center py-10 hidden">
        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-gray-300 mx-auto mb-2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
        <p class="text-sm text-gray-500">Add your first domain or URL to start monitoring for security threats.</p>
    </div>
<?php endif; ?>

</div>

<style>@keyframes spin { to { transform: rotate(360deg); } }</style>
<script>
jQuery(function ($) {
    'use strict';

    /* ── Activate plugin (after install on this page) ── */
    $(document).on('click', '.optee-activate-plugin', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true).text('Activating…');
        $.post(opteeData.ajaxUrl, {
            action: 'optee_activate_plugin',
            nonce:  opteeData.nonce,
            plugin_file: $btn.data('plugin')
        }).done(function (res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
                window.location.reload();
            } else {
                Optee.Toast.show(res.data.message || 'Activation failed.', 'error');
                $btn.prop('disabled', false).text('Activate');
            }
        }).fail(function () {
            Optee.Toast.show('Network error. Please try again.', 'error');
            $btn.prop('disabled', false).text('Activate');
        });
    });

    /* ── Credential form (configure branch) ── */
    var opteeUnsavedConnection = false;
    $(window).on('beforeunload', function () {
        if (opteeUnsavedConnection) return 'You have a successful connection that has not been saved. Are you sure you want to leave?';
    });
    $(document).on('click', '.optee-toggle-password', function () {
        var $input = $(this).siblings('input');
        var isPassword = $input.attr('type') === 'password';
        $input.attr('type', isPassword ? 'text' : 'password');
        $(this).find('.optee-eye-show').toggleClass('hidden', !isPassword);
        $(this).find('.optee-eye-hide').toggleClass('hidden', isPassword);
    });
    $(document).on('input', '.optee-credentials-form input[name^="credentials"]', function () {
        $(this).closest('.optee-credentials-form').find('.optee-save-credentials').prop('disabled', true);
        opteeUnsavedConnection = false;
    });
    $(document).on('submit', '.optee-credentials-form', function (e) {
        e.preventDefault();
        var $form = $(this), $status = $form.find('.optee-credentials-status'), $btn = $form.find('[type="submit"]').prop('disabled', true);
        var formData = { module_type: $form.data('type'), module_slug: $form.data('slug') };
        $form.find('input[name^="credentials"]').each(function () { formData[$(this).attr('name')] = $(this).val(); });
        Optee.Ajax.post('optee_save_credentials', formData).done(function (res) {
            if (res.success) {
                opteeUnsavedConnection = false;
                Optee.Toast.show(res.data.message, 'success');
                var countdown = 3;
                $status.html('<span class="text-green-700">Saved! Redirecting in <strong>' + countdown + '</strong>s...</span>');
                var interval = setInterval(function () {
                    countdown--;
                    if (countdown <= 0) {
                        clearInterval(interval);
                        location.reload();
                    } else {
                        $status.html('<span class="text-green-700">Saved! Redirecting in <strong>' + countdown + '</strong>s...</span>');
                    }
                }, 1000);
            }
            else { $status.html('<span class="text-red-700">' + Optee.Util.escapeHtml(res.data.message) + '</span>'); $btn.prop('disabled', false); }
        }).fail(function () { $status.html('<span class="text-red-700">Request failed.</span>'); $btn.prop('disabled', false); });
    });
    $(document).on('click', '.optee-test-connection', function () {
        var $form = $(this).closest('.optee-credentials-form'), $status = $form.find('.optee-credentials-status'), $saveBtn = $form.find('.optee-save-credentials'), $btn = $(this).prop('disabled', true).text('Testing...');
        var formData = { module_type: $form.data('type'), module_slug: $form.data('slug') };
        $form.find('input[name^="credentials"]').each(function () { formData[$(this).attr('name')] = $(this).val(); });
        Optee.Ajax.post('optee_test_connection', formData).done(function (res) {
            if (res.success) { $status.html('<span class="text-green-700">' + Optee.Util.escapeHtml(res.data.message) + '</span>'); $saveBtn.prop('disabled', false); opteeUnsavedConnection = true; }
            else { $status.html('<span class="text-red-700">' + Optee.Util.escapeHtml(res.data.message || 'Connection failed.') + '</span>'); $saveBtn.prop('disabled', true); }
            $btn.prop('disabled', false).text('Test Connection');
        }).fail(function () { $status.html('<span class="text-red-700">Request failed.</span>'); $btn.prop('disabled', false).text('Test Connection'); });
    });
    $(document).on('click', '.optee-delete-credentials', function () {
        if (!Optee.Util.confirm('Delete credentials for this module?')) return;
        var $form = $(this).closest('.optee-credentials-form'), $status = $form.find('.optee-credentials-status');
        Optee.Ajax.post('optee_delete_credentials', { module_type: $form.data('type'), module_slug: $form.data('slug') }).done(function (res) {
            if (res.success) { $status.html('<span class="text-green-700">' + Optee.Util.escapeHtml(res.data.message) + '</span>'); $form.find('input').val(''); Optee.Toast.show(res.data.message, 'success'); setTimeout(function () { location.reload(); }, 1000); }
        });
    });

    /* ── Domain Monitoring (main view branch) ── */
    var $thead = $('#vt-thead');
    var $tbody = $('#vt-url-tbody');
    var $tableWrap = $('#vt-table-wrap');
    var $loading = $('#vt-loading');
    var $empty = $('#vt-empty');
    var vtLoaded = false;

    if ($tbody.length) {
        var currentUrls = [];
        var vtDays = [];
        var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

        function escHtml(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function timeAgo(ts) {
            if (!ts) return '—';
            var diff = Math.floor(Date.now() / 1000) - ts;
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        }

        function formatLastCheck(dateStr) {
            if (!dateStr || dateStr === '—') return '—';
            var parts = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
            if (!parts) return escHtml(dateStr);
            var dt = new Date(+parts[1], +parts[2] - 1, +parts[3], +parts[4], +parts[5]);
            var diff = Math.floor((Date.now() - dt.getTime()) / 1000);
            if (diff < 0) diff = 0;
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return escHtml(dateStr);
        }

        function vtGuiUrl(url) {
            var id = btoa(url).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
            return 'https://www.virustotal.com/gui/url/' + id;
        }

        function buildDays(count) {
            var days = [];
            var now = new Date();
            for (var i = 0; i < count; i++) {
                var d = new Date(now);
                d.setDate(d.getDate() - i);
                var y = d.getFullYear();
                var m = ('0' + (d.getMonth() + 1)).slice(-2);
                var dd = ('0' + d.getDate()).slice(-2);
                days.push({ key: y + '-' + m + '-' + dd, label: MONTHS[d.getMonth()] + ' ' + d.getDate() });
            }
            return days;
        }

        function renderHead() {
            var h = '<tr>' +
                '<th class="text-center px-3 py-2 text-xs font-medium text-gray-500 uppercase whitespace-nowrap" style="width:72px;">Status</th>' +
                '<th class="text-center px-3 py-2 text-xs font-medium text-gray-500 uppercase whitespace-nowrap" style="width:100px;">Actions</th>' +
                '<th class="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase whitespace-nowrap" style="width:260px;min-width:260px;max-width:260px;">Name</th>' +
                '<th class="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Added</th>' +
                '<th class="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Last Check</th>' +
                '<th class="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase whitespace-nowrap">VT Analysis</th>' +
                '<th class="text-left px-4 py-2 text-xs font-medium text-gray-500 uppercase whitespace-nowrap" style="width:392px;min-width:392px;max-width:392px;">URL</th>';
            vtDays.forEach(function (d) {
                h += '<th class="text-center px-3 py-2 text-xs font-medium text-gray-500 uppercase whitespace-nowrap" style="width:72px;">' + d.label + '</th>';
            });
            h += '</tr>';
            $thead.html(h);
        }

        function countFlagged(vendors) {
            var flagged = 0;
            if (vendors && typeof vendors === 'object') {
                Object.keys(vendors).forEach(function (engine) {
                    var r = (vendors[engine].result || '').toLowerCase();
                    if (r === 'phishing' || r === 'malicious' || r === 'malware') flagged++;
                });
            }
            return flagged;
        }

        function renderScoreCell(item, dayKey) {
            var scans = (item.scans || {})[dayKey];
            if (!scans) return '<td class="px-3 py-2.5 text-center text-sm text-gray-300 whitespace-nowrap">—</td>';

            var vendors = scans.vendors || {};
            var total = scans.total || 0;
            var flagged = countFlagged(vendors);
            var bg, fg;
            if (flagged > 3) {
                bg = '#fee2e2'; fg = '#991b1b'; // red-100 / red-800
            } else if (flagged > 0) {
                bg = '#fff7ed'; fg = '#9a3412'; // orange-50 / orange-800
            } else {
                bg = '#f0fdf4'; fg = '#166534'; // green-50 / green-800
            }
            var label = flagged + ' / ' + total;
            return '<td class="px-3 py-2.5 text-center text-sm font-medium cursor-pointer vt-expand-day whitespace-nowrap" data-day="' + dayKey + '" style="background:' + bg + ';color:' + fg + ';">' + label + '</td>';
        }

        var $vtCards = $('#vt-cards');

        /**
         * Warn against an interval the free tier cannot actually sustain.
         *
         * Each active URL costs a submit plus at least one result read per cycle,
         * and the daily allowance is enforced per source IP, so the floor scales
         * with the size of the list: 5 URLs are fine at 30 min, 50 are not. Some
         * of the 500 is reserved for the quota snapshots and the configure page,
         * which spend from the same pot without scanning anything.
         */
        function updateIntervalHint(urls) {
            var $hint = $('#vt-interval-hint');
            if (!$hint.length) return;

            var active = (urls || []).filter(function (u) {
                return (u.status || 'active') === 'active';
            }).length;

            if (!active) {
                $hint.text('Values lower than 30 minutes can spend your daily API quota quickly.')
                     .css('color', '');
                return;
            }

            var CALLS_PER_SCAN = 2;    // one submit + one read, and reads can repeat
            var USABLE_PER_DAY = 450;  // of 500, leaving room for quota + UI requests

            var floorMin = Math.ceil(1440 * active * CALLS_PER_SCAN / USABLE_PER_DAY / 5) * 5;
            var current  = parseInt($('#vt-check-interval').val(), 10) || 0;
            var pretty   = floorMin >= 120
                ? (Math.round(floorMin / 6) / 10) + ' hours'
                : floorMin + ' minutes';

            if (current && current < floorMin) {
                $hint.text('With ' + active + ' URL(s) active, the free daily allowance only supports a check every ' +
                           pretty + '. At ' + current + ' min the quota runs out partway through the day and ' +
                           'scanning stops until 00:00 UTC.')
                     .css('color', '#b45309');
            } else {
                $hint.text('With ' + active + ' URL(s) active, keep the interval at or above ' + pretty +
                           ' to stay inside the free daily allowance.')
                     .css('color', '');
            }
        }

        function renderTable(urls) {
            updateIntervalHint(urls);
            $tbody.empty();
            $vtCards.empty();
            $loading.addClass('hidden');
            if (!urls || !urls.length) {
                $tableWrap.addClass('hidden');
                $vtCards.addClass('hidden');
                if (vtLoaded) {
                    $empty.removeClass('hidden');
                }
                return;
            }
            $tableWrap.removeClass('hidden');
            $vtCards.removeClass('hidden');
            $empty.addClass('hidden');
            urls.forEach(function (item, i) {
                var dateCells = '';
                vtDays.forEach(function (d) {
                    dateCells += renderScoreCell(item, d.key);
                });

                var st = (item.status || 'active') === 'active';
                var badgeStyle = st
                    ? 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0;'   // green
                    : 'background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;';  // gray
                var badgeLabel = st ? 'Active' : 'Paused';
                var badge = '<span class="vt-toggle-status cursor-pointer select-none" data-index="' + i + '" style="display:inline-block;font-size:11px;font-weight:500;padding:1px 8px;border-radius:9999px;' + badgeStyle + '">' + badgeLabel + '</span>';

                $tbody.append(
                    '<tr class="border-t border-gray-100 group vt-url-row" data-index="' + i + '">' +
                        /* STATUS column */
                        '<td class="px-3 py-2.5 text-center whitespace-nowrap">' + badge + '</td>' +
                        /* ACTIONS column */
                        '<td class="px-3 py-2.5 text-center whitespace-nowrap">' +
                            '<div class="flex items-center justify-center gap-3">' +
                                '<button type="button" class="vt-delete-url text-gray-400 hover:text-red-600 transition-colors cursor-pointer" data-index="' + i + '" title="Delete" style="background:none;border:none;padding:2px;line-height:0;">' +
                                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>' +
                                '</button>' +
                                '<button type="button" class="vt-edit-url text-gray-400 hover:text-gray-700 transition-colors cursor-pointer" data-index="' + i + '" title="Edit" style="background:none;border:none;padding:2px;line-height:0;">' +
                                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>' +
                                '</button>' +
                                '<a href="' + escHtml(item.url) + '" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-gray-700 transition-colors" style="line-height:0;" title="Open URL">' +
                                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
                                '</a>' +
                                (st ? '<button type="button" class="vt-refresh-url text-gray-400 hover:text-gray-700 transition-colors cursor-pointer" data-index="' + i + '" title="Refresh scan" style="background:none;border:none;padding:2px;line-height:0;">' +
                                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>' +
                                '</button>' : '') +
                            '</div>' +
                        '</td>' +
                        /* NAME column */
                        '<td class="px-4 py-2.5 text-sm text-gray-900" style="width:260px;min-width:260px;max-width:260px;white-space:normal;overflow-wrap:anywhere;">' + escHtml(item.label || '—') + '</td>' +
                        '<td class="px-4 py-2.5 text-xs text-gray-400 whitespace-nowrap">' + escHtml(item.added || '—') + '</td>' +
                        '<td class="px-4 py-2.5 text-xs text-gray-400 whitespace-nowrap">' + formatLastCheck(item.last_check) + '</td>' +
                        '<td class="px-4 py-2.5 text-xs text-gray-400 whitespace-nowrap">' +
                            timeAgo(item.vt_analysis_date) +
                            (item.vt_analysis_date ? ' <a href="' + vtGuiUrl(item.url) + '" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-gray-600" style="display:inline-block;vertical-align:-1px;margin-left:4px;" title="View on VirusTotal"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>' : '') +
                        '</td>' +
                        '<td class="px-4 py-2.5 text-sm text-gray-500 vt-url-cell cursor-pointer" style="width:392px;min-width:392px;max-width:392px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escHtml(item.url) + '</td>' +
                        dateCells +
                    '</tr>'
                );

                // Mobile card.
                // Get latest score from date columns.
                var latestScore = '—';
                var scansObj = item.scans || {};
                for (var di = 0; di < vtDays.length; di++) {
                    var dk = vtDays[di].key;
                    if (scansObj[dk]) {
                        var flaggedCount = countFlagged(scansObj[dk].vendors || {});
                        latestScore = flaggedCount + ' / ' + (scansObj[dk].total || 0);
                        break;
                    }
                }

                var cardHtml = '<div class="optee-card optee-mob-card" data-index="' + i + '" style="padding:14px 16px;">';
                // Row 1: status + VT analysis time
                cardHtml += '<div class="flex items-center gap-2" style="font-size:13px;">';
                cardHtml += badge;
                cardHtml += '<span class="text-gray-400">' + timeAgo(item.vt_analysis_date) + '</span>';
                if (latestScore !== '—') {
                    var scColor = parseInt(latestScore) > 0 ? '#dc2626' : '#059669';
                    cardHtml += '<span style="margin-left:auto;font-weight:700;color:' + scColor + ';font-size:14px;">' + escHtml(latestScore) + '</span>';
                }
                cardHtml += '</div>';
                // Row 2: name/label
                cardHtml += '<div class="font-semibold text-gray-900 mt-2" style="font-size:17px;">' + escHtml(item.label || item.url) + '</div>';
                // Row 3: URL (truncated)
                cardHtml += '<div class="text-xs text-gray-400 mt-1" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escHtml(item.url) + '</div>';
                // Row 4: meta
                cardHtml += '<div class="flex items-center gap-4 mt-2 text-xs text-gray-400">';
                cardHtml += '<span>Added: ' + escHtml(item.added || '—') + '</span>';
                cardHtml += '<span>Checked: ' + formatLastCheck(item.last_check) + '</span>';
                cardHtml += '</div>';
                // Row 5: daily scores (collapsible)
                var hasAnyScore = false;
                var scoresHtml = '<div class="optee-mob-stats-more" style="display:none;margin-top:8px;">';
                scoresHtml += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px 10px;">';
                for (var si = 0; si < vtDays.length; si++) {
                    var sdk = vtDays[si].key;
                    var sdLabel = vtDays[si].label;
                    var scanDay = scansObj[sdk];
                    var scoreText = '—';
                    var scoreStyle = 'color:#d1d5db;';
                    if (scanDay && scanDay.total) {
                        hasAnyScore = true;
                        var dayFlagged = countFlagged(scanDay.vendors || {});
                        scoreText = dayFlagged + ' / ' + scanDay.total;
                        scoreStyle = dayFlagged > 0 ? 'color:#dc2626;font-weight:700;' : 'color:#059669;font-weight:600;';
                    }
                    scoresHtml += '<div><span style="font-size:13px;' + scoreStyle + '">' + scoreText + '</span> <span style="font-size:11px;color:#9ca3af;">' + escHtml(sdLabel) + '</span></div>';
                }
                scoresHtml += '</div></div>';

                if (hasAnyScore || vtDays.length) {
                    cardHtml += '<div class="mt-3">';
                    cardHtml += '<button type="button" class="optee-mob-stats-toggle" style="background:none;border:none;color:#9ca3af;font-size:12px;padding:4px 0;cursor:pointer;display:flex;align-items:center;gap:3px;">';
                    cardHtml += '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="optee-mob-stats-chevron"><path d="m6 9 6 6 6-6"/></svg>';
                    cardHtml += '<span class="optee-mob-stats-label">Daily scores</span>';
                    cardHtml += '</button>';
                    cardHtml += scoresHtml;
                    cardHtml += '</div>';
                }

                // Row 6: actions
                cardHtml += '<div style="margin-top:16px;padding-top:10px;border-top:1px solid #e5e7eb;">';
                cardHtml += '<button type="button" class="optee-mob-actions-toggle" style="background:none;border:1px solid #e5e7eb;border-radius:6px;color:#6b7280;font-size:12px;font-weight:500;padding:6px 10px;cursor:pointer;width:100%;">Show Actions</button>';
                cardHtml += '<div class="optee-mob-actions flex flex-wrap items-center gap-2 mt-2" style="display:none;">';
                cardHtml += '<button class="optee-mob-action-btn vt-edit-url" data-index="' + i + '"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg> Edit</button>';
                cardHtml += '<a href="' + escHtml(item.url) + '" target="_blank" rel="noopener noreferrer" class="optee-mob-action-btn"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Open URL</a>';
                if (item.vt_analysis_date) {
                    cardHtml += '<a href="' + vtGuiUrl(item.url) + '" target="_blank" rel="noopener noreferrer" class="optee-mob-action-btn"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg> VirusTotal</a>';
                }
                if (st) {
                    cardHtml += '<button class="optee-mob-action-btn vt-refresh-url" data-index="' + i + '"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg> Refresh</button>';
                }
                cardHtml += '<button class="optee-mob-action-btn vt-toggle-status" data-index="' + i + '">' + (st ? 'Pause' : 'Resume') + '</button>';
                cardHtml += '<button class="optee-mob-action-btn vt-delete-url" data-index="' + i + '" style="color:#ef4444;border-color:#fecaca;"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg> Delete</button>';
                cardHtml += '</div></div>';
                cardHtml += '</div>';
                $vtCards.append(cardHtml);
            });
        }

        function setRange(count) {
            vtDays = buildDays(count);
            renderHead();
            renderTable(currentUrls);
        }

        function vendorResultStyle(result) {
            var r = (result || '').toLowerCase();
            if (r === 'clean')                       return 'background:#f0fdf4;color:#166534;'; // green
            if (r === 'unrated')                     return 'background:#f9fafb;color:#6b7280;'; // gray-50
            if (r === 'phishing')                    return 'background:#fff7ed;color:#9a3412;font-weight:600;'; // orange
            if (r === 'malicious' || r === 'malware') return 'background:#fee2e2;color:#991b1b;font-weight:600;'; // red
            if (r === 'suspicious')                  return 'background:#fefce8;color:#854d0e;font-weight:600;'; // yellow
            return 'color:#9ca3af;';
        }

        // Toggle vendor detail rows for a URL row.
        function toggleVendorRows(idx) {
            var detailClass = 'vt-detail-' + idx;

            // Toggle: if already expanded, collapse.
            if ($('.' + detailClass).length) {
                $('.' + detailClass).remove();
                return;
            }

            var item = currentUrls[idx];
            if (!item || !item.scans) return;

            var $row = $tbody.find('.vt-url-row[data-index="' + idx + '"]');

            // Collect all unique vendor names across all visible dates that have scan data.
            var allVendors = {};
            vtDays.forEach(function (d) {
                var scan = item.scans[d.key];
                if (!scan || !scan.vendors) return;
                Object.keys(scan.vendors).forEach(function (engine) {
                    if (!allVendors[engine]) allVendors[engine] = true;
                });
            });

            var vendorNames = Object.keys(allVendors).sort(function (a, b) {
                return a.toLowerCase().localeCompare(b.toLowerCase());
            });

            // Build detail rows: one per vendor, showing result across all date columns.
            var fragment = '';
            vendorNames.forEach(function (engine) {
                var cells = '<td class="px-4 py-1.5 text-xs text-gray-500 pl-8" colspan="7">' + escHtml(engine) + '</td>';
                vtDays.forEach(function (d) {
                    var scan = (item.scans || {})[d.key];
                    if (!scan || !scan.vendors || !scan.vendors[engine]) {
                        cells += '<td class="px-3 py-1.5 text-center text-xs text-gray-300">—</td>';
                        return;
                    }
                    var v = scan.vendors[engine];
                    var result = v.result || '';
                    var st = vendorResultStyle(result);
                    cells += '<td class="px-3 py-1.5 text-center text-xs" style="' + st + '">' + escHtml(result) + '</td>';
                });
                fragment += '<tr class="' + detailClass + ' bg-gray-50 border-t border-gray-50">' + cells + '</tr>';
            });

            $row.after(fragment);
        }

        // Expand/collapse vendor detail rows when clicking a score cell or URL cell.
        $tbody.on('click', '.vt-expand-day, .vt-url-cell', function () {
            var idx = $(this).closest('.vt-url-row').data('index');
            toggleVendorRows(idx);
        });

        function loadUrls() {
            var days = parseInt($('#vt-days-range').val(), 10) || 10;
            Optee.Ajax.post('optee_vt_get_urls', { days: days }).done(function (res) {
                vtLoaded = true;
                if (res.success) { currentUrls = res.data.urls; renderTable(currentUrls); }
                else { renderTable([]); }
            }).fail(function () {
                vtLoaded = true;
                renderTable([]);
            });
        }

        // Date range picker.
        $('#vt-days-range').on('change', function () {
            var count = parseInt($(this).val(), 10);
            vtDays = buildDays(count);
            renderHead();
            loadUrls();
        });

        // Reload button.
        $('#vt-reload-btn').on('click', function () {
            var $btn = $(this).prop('disabled', true);
            $btn.find('svg').css('animation', 'spin 1s linear infinite');
            var days = parseInt($('#vt-days-range').val(), 10) || 10;
            Optee.Ajax.post('optee_vt_get_urls', { days: days }).done(function (res) {
                if (res.success) { currentUrls = res.data.urls; renderTable(currentUrls); }
            }).always(function () {
                $btn.find('svg').css('animation', '');
                $btn.prop('disabled', false);
            });
        });

        // Initial load.
        setRange(10);
        loadUrls();

        // Add New URL button.
        $('#vt-add-url-btn').on('click', function () {
            var formHtml =
                '<div class="space-y-4">' +
                    '<div>' +
                        '<label class="optee-label">Internal Name</label>' +
                        '<input type="text" id="vt-url-label" class="optee-input" placeholder="My tracking domain">' +
                    '</div>' +
                    '<div>' +
                        '<label class="optee-label">URL</label>' +
                        '<input type="url" id="vt-url-value" class="optee-input" placeholder="https://example.com">' +
                    '</div>' +
                    '<div class="flex justify-end">' +
                        '<button type="button" id="vt-url-submit" class="optee-btn-primary">Add</button>' +
                    '</div>' +
                '</div>';
            Optee.Modal.open('Add URL', formHtml);

            // Focus the first field.
            setTimeout(function () { $('#vt-url-label').focus(); }, 100);

            $(document).off('click.vtSubmit').on('click.vtSubmit', '#vt-url-submit', function () {
                var label = $('#vt-url-label').val().trim();
                var url = $('#vt-url-value').val().trim();
                if (!url) {
                    Optee.Toast.show('URL is required.', 'error');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('Adding...');
                Optee.Ajax.post('optee_vt_save_url', { label: label, url: url, days: parseInt($('#vt-days-range').val(), 10) || 10 }).done(function (res) {
                    if (res.success) {
                        Optee.Toast.show(res.data.message, 'success');
                        Optee.Modal.close();
                        currentUrls = res.data.urls; renderTable(currentUrls);
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
                        $btn.prop('disabled', false).text('Add');
                    }
                }).fail(function () {
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Add');
                });
            });
        });

        // Save check interval.
        // Re-evaluate as the number is typed, so the warning appears before Save
        // rather than after the quota has already been committed to.
        $('#vt-check-interval').on('input change', function () {
            updateIntervalHint(currentUrls);
        });

        $('#vt-save-interval').on('click', function () {
            var val = parseInt($('#vt-check-interval').val(), 10) || 30;
            var $btn = $(this).prop('disabled', true);
            Optee.Ajax.post('optee_vt_save_interval', { interval: val }).done(function (res) {
                if (res.success) Optee.Toast.show(res.data.message, 'success');
                else Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
                $btn.prop('disabled', false);
            }).fail(function () {
                Optee.Toast.show('Request failed.', 'error');
                $btn.prop('disabled', false);
            });
        });

        // Refresh URL scan.
        $(document).on('click', '.vt-refresh-url', function () {
            var $btn = $(this);
            var index = $btn.data('index');
            var days = parseInt($('#vt-days-range').val(), 10) || 10;
            $btn.css('opacity', '1').find('svg').css('animation', 'spin 1s linear infinite');
            Optee.Ajax.post('optee_vt_refresh_url', { index: index, days: days }).done(function (res) {
                $btn.find('svg').css('animation', '');
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    currentUrls = res.data.urls; renderTable(currentUrls);
                } else {
                    Optee.Toast.show(res.data.message || 'Scan failed.', 'error');
                }
            }).fail(function () {
                $btn.find('svg').css('animation', '');
                Optee.Toast.show('Request failed.', 'error');
            });
        });

        // Toggle URL active/paused status.
        $(document).on('click', '.vt-toggle-status', function (e) {
            e.stopPropagation();
            var $badge = $(this);
            var index = $badge.data('index');
            var days = parseInt($('#vt-days-range').val(), 10) || 10;
            Optee.Ajax.post('optee_vt_toggle_url_status', { index: index, days: days }).done(function (res) {
                if (res.success) {
                    currentUrls = res.data.urls; renderTable(currentUrls);
                }
            });
        });

        // Edit URL.
        $(document).on('click', '.vt-edit-url', function () {
            var index = $(this).data('index');
            var item = currentUrls[index];
            if (!item) return;

            var formHtml =
                '<div class="space-y-4">' +
                    '<div>' +
                        '<label class="optee-label">Internal Name</label>' +
                        '<input type="text" id="vt-edit-label" class="optee-input" value="' + escHtml(item.label || '') + '">' +
                    '</div>' +
                    '<div>' +
                        '<label class="optee-label">URL</label>' +
                        '<input type="url" id="vt-edit-value" class="optee-input" value="' + escHtml(item.url || '') + '">' +
                    '</div>' +
                    '<div class="flex justify-end">' +
                        '<button type="button" id="vt-edit-submit" class="optee-btn-primary">Save</button>' +
                    '</div>' +
                '</div>';
            Optee.Modal.open('Edit URL', formHtml);

            setTimeout(function () { $('#vt-edit-label').focus(); }, 100);

            $(document).off('click.vtEdit').on('click.vtEdit', '#vt-edit-submit', function () {
                var label = $('#vt-edit-label').val().trim();
                var url = $('#vt-edit-value').val().trim();
                if (!url) {
                    Optee.Toast.show('URL is required.', 'error');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('Saving...');
                var days = parseInt($('#vt-days-range').val(), 10) || 10;
                Optee.Ajax.post('optee_vt_edit_url', { index: index, label: label, url: url, days: days }).done(function (res) {
                    if (res.success) {
                        Optee.Toast.show(res.data.message, 'success');
                        Optee.Modal.close();
                        currentUrls = res.data.urls; renderTable(currentUrls);
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
                        $btn.prop('disabled', false).text('Save');
                    }
                }).fail(function () {
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Save');
                });
            });
        });

        // Delete URL.
        $(document).on('click', '.vt-delete-url', function () {
            if (!confirm('Remove this URL?')) return;
            var index = $(this).data('index');
            Optee.Ajax.post('optee_vt_delete_url', { index: index, days: parseInt($('#vt-days-range').val(), 10) || 10 }).done(function (res) {
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    currentUrls = res.data.urls; renderTable(currentUrls);
                } else {
                    Optee.Toast.show(res.data.message || 'Failed to delete.', 'error');
                }
            });
        });
    }

    /* ── API usage chart ─────────────────────────────────────────────────────
       Inline SVG, no chart library. Bars are VirusTotal's own "used" figure for
       the day; the amber line is what Optee actually spent. They diverge when
       something else shares this server's IP, because the allowance is per-IP. */

    var usageHidden = false;

    function renderUsageChart(data) {
        var rows = (data && data.history) || [];
        var $card = $('#vt-usage-card');
        if (!rows.length) { $card.addClass('hidden'); return; }

        // The allowance to draw the ceiling at — last day that reported one.
        var allowed = 0;
        for (var a = rows.length - 1; a >= 0; a--) {
            if (rows[a].quota_allowed > 0) { allowed = rows[a].quota_allowed; break; }
        }

        var peak = Math.max(data.peak || 0, allowed, 1);
        var everRecorded = false;
        for (var e = 0; e < rows.length; e++) {
            if (rows[e].quota_used > 0 || rows[e].calls > 0) { everRecorded = true; break; }
        }

        if (!everRecorded) {
            $card.removeClass('hidden');
            $('#vt-usage-chart').html('<p class="text-sm text-gray-400" style="padding:18px 0;">No usage recorded yet. This chart fills in as scans run — the first bar appears after the next scan cycle.</p>');
            $('#vt-usage-today').text('');
            $('#vt-usage-note').text('');
            return;
        }

        var barW = 26, gap = 8, padL = 42, padR = 12, padT = 10, chartH = 130;
        var w = padL + padR + rows.length * (barW + gap);
        var h = chartH + 34;

        function y(v) { return padT + (chartH - (v / peak) * chartH); }

        var svg = '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" style="display:block;">';

        // Y axis: 0, half, peak.
        [0, Math.round(peak / 2), peak].forEach(function (tick) {
            var ty = y(tick);
            svg += '<line x1="' + padL + '" y1="' + ty + '" x2="' + (w - padR) + '" y2="' + ty + '" stroke="#f3f4f6" stroke-width="1"/>';
            svg += '<text x="' + (padL - 6) + '" y="' + (ty + 3) + '" text-anchor="end" font-size="9" fill="#9ca3af">' + tick + '</text>';
        });

        // Daily allowance ceiling.
        if (allowed > 0) {
            svg += '<line x1="' + padL + '" y1="' + y(allowed) + '" x2="' + (w - padR) + '" y2="' + y(allowed) +
                '" stroke="#ef4444" stroke-width="1.5" stroke-dasharray="4 3"/>';
        }

        // Bars + labels.
        var linePts = [];
        rows.forEach(function (r, i) {
            var x = padL + i * (barW + gap);
            var cx = x + barW / 2;
            var used = r.quota_used || 0;
            var bh = Math.max(used > 0 ? 2 : 0, (used / peak) * chartH);
            var atLimit = allowed > 0 && used >= allowed;

            if (used > 0) {
                svg += '<rect x="' + x + '" y="' + (padT + chartH - bh) + '" width="' + barW + '" height="' + bh +
                    '" rx="2" fill="' + (atLimit ? '#ef4444' : '#2aa5ff') + '"><title>' + r.date + ' — ' +
                    used + (allowed ? ' / ' + allowed : '') + ' quota used, ' + r.calls + ' call(s) by Optee (' +
                    r.submit + ' submit, ' + r.read + ' read)</title></rect>';
            }
            linePts.push([cx, y(r.calls || 0), r]);

            // Date label — every other one when the range is long.
            if (rows.length <= 16 || i % 2 === 0) {
                svg += '<text x="' + cx + '" y="' + (padT + chartH + 14) + '" text-anchor="middle" font-size="9" fill="#9ca3af">' +
                    r.date.slice(5) + '</text>';
            }
        });

        // Our own call count as a line on top.
        var d = '';
        linePts.forEach(function (p, i) { d += (i === 0 ? 'M' : 'L') + p[0] + ' ' + p[1]; });
        svg += '<path d="' + d + '" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linejoin="round"/>';
        linePts.forEach(function (p) {
            svg += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="2.5" fill="#f59e0b"><title>' + p[2].date + ' — ' +
                p[2].calls + ' call(s) by Optee</title></circle>';
        });

        svg += '</svg>';
        $('#vt-usage-chart').html(svg);

        // Headline for today.
        var t = data.today || {};
        var todayTxt = '';
        if (t.quota_allowed > 0) {
            todayTxt = 'Today: ' + t.quota_used + ' / ' + t.quota_allowed + ' used';
            if (t.quota_used >= t.quota_allowed) todayTxt += ' — exhausted';
        } else if (t.calls > 0) {
            todayTxt = 'Today: ' + t.calls + ' call(s) by Optee';
        }
        $('#vt-usage-today').text(todayTxt);

        // Explain the gap rather than leaving it to be misread as a bug. Deliberately
        // hedged: a difference is only evidence of other traffic once it is bigger
        // than ordinary measurement slack (the snapshot is hourly, so calls made
        // since the last one are counted by us but not yet reflected in VT's figure).
        var note = '';
        var gap = t.quota_used - t.calls;
        if (t.quota_allowed > 0 && t.calls > 0 && gap > Math.max(10, t.calls * 0.25)) {
            note = 'VirusTotal counted ' + t.quota_used + ' requests today but Optee made ' + t.calls +
                '. Each key carries its own count wherever it is used, so the difference is usually the ' +
                'same key being used elsewhere — another install, or a script — plus anything else sharing ' +
                'this server’s address.';
        } else if (t.quota_allowed === 0) {
            note = 'Quota figures appear once a scan cycle has run and reported them; until then only Optee\'s own call count is plotted.';
        }
        $('#vt-usage-note').text(note);

        $card.removeClass('hidden');
    }

    function loadUsage() {
        Optee.Ajax.post('optee_vt_usage_history', { days: parseInt($('#vt-usage-days').val(), 10) || 14 })
            .done(function (res) {
                if (res.success) renderUsageChart(res.data);
            });
    }

    if ($('#vt-usage-card').length) {
        loadUsage();
        $('#vt-usage-days').on('change', loadUsage);
        $('#vt-usage-toggle').on('click', function () {
            usageHidden = !usageHidden;
            $('#vt-usage-body').toggle(!usageHidden);
            $(this).text(usageHidden ? 'Show' : 'Hide');
        });
        $('#vt-reload-btn').on('click', loadUsage);
    }
});
</script>
