<?php
/**
 * VirusTotal — Configure API key.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$module = $registry->get_module( 'virustotal' );

if ( ! $module ) {
    echo '<p class="text-sm text-gray-500">VirusTotal module plugin is not active.</p>';
    return;
}

$fields = $module->get_credential_fields();
$stored = \Optee\Plugin\ModuleRegistry::get_stored_credentials( 'virustotal', 'virustotal' );

$logo_url = '';
foreach ( [ 'logo.svg', 'logo.png' ] as $logo_file ) {
    $logo_path = WP_PLUGIN_DIR . '/optee-antifraud-virustotal/assets/' . $logo_file;
    if ( file_exists( $logo_path ) ) {
        $logo_url = plugins_url( 'assets/' . $logo_file, WP_PLUGIN_DIR . '/optee-antifraud-virustotal/index.php' );
        break;
    }
}
?>
<div class="space-y-6">
    <div class="flex items-center gap-2 text-sm text-gray-500">
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'virustotal' ) ); ?>"
           class="hover:text-gray-900 transition-colors">VirusTotal</a>
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        <span class="text-gray-900">Configure</span>
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
            <?php if ( $stored ) : ?>
                <span class="optee-badge-green ml-auto">Connected</span>
            <?php else : ?>
                <span class="optee-badge-gray ml-auto">Not configured</span>
            <?php endif; ?>
        </div>

        <form class="optee-credentials-form space-y-4" data-type="virustotal" data-slug="virustotal">
            <?php foreach ( $fields as $field ) :
                $field_type = $field['type'] ?? 'text';
                $field_key  = $field['key'] ?? '';
                $field_val  = $stored[ $field_key ] ?? '';
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
                               value="<?php echo esc_attr( $field_val ); ?>"
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
                           value="<?php echo esc_attr( $field_val ); ?>"
                           class="optee-input"
                           placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="optee-vt-keys-info" style="display:none;"></div>

            <?php
            /*
             * Egress routing (test feature).
             *
             * VirusTotal enforces the free daily allowance per SOURCE IP, not per
             * key, so extra keys do not raise the ceiling by themselves. Pinning a
             * key to a proxy sends its share of the traffic from a different
             * address, which turns the existing random-key rotation into an IP
             * rotation — and makes the effect observable, because when the
             * server's pot runs out the direct keys go amber while a proxied key
             * keeps working.
             *
             * Hidden unless more than one key is configured: routing the only key
             * moves all traffic to the proxy instead of splitting it, which
             * defeats the point and removes the comparison.
             */
            $proxy_url  = $stored['proxy_url'] ?? '';
            $proxy_keys = $stored['proxy_keys'] ?? '';
            ?>
            <div class="optee-vt-proxy" style="display:none;border:1px solid #e5e7eb;border-radius:0.5rem;padding:0.75rem;background:#fcfcfd;">
                <div class="flex items-center justify-between" style="margin-bottom:0.4rem;">
                    <label class="optee-label" style="margin-bottom:0;">Proxy route <span style="font-weight:400;color:#9ca3af;">(optional, for testing)</span></label>
                </div>
                <input type="text"
                       name="credentials[proxy_url]"
                       value="<?php echo esc_attr( $proxy_url ); ?>"
                       class="optee-input optee-vt-proxy-url"
                       placeholder="https://optee-vt-proxy.example.workers.dev/?token=SECRET">
                <p class="text-xs text-gray-400" style="margin-top:0.35rem;">
                    Requests for the keys you tick below are sent through this endpoint instead of directly.
                    It must accept the VirusTotal path on its own path and forward the <code>x-apikey</code> header.
                    Leave empty to send everything directly.
                </p>
                <input type="hidden" name="credentials[proxy_keys]" value="<?php echo esc_attr( $proxy_keys ); ?>" class="optee-vt-proxy-keys">
                <div class="optee-vt-route-counts text-xs" style="margin-top:0.5rem;color:#6b7280;"></div>
            </div>

            <div class="flex items-center gap-2 pt-2">
                <button type="submit" class="optee-btn-primary optee-save-credentials" disabled>Save</button>
                <button type="button" class="optee-test-connection optee-btn-secondary">Test Connection</button>
                <?php if ( $stored ) : ?>
                    <button type="button" class="optee-delete-credentials optee-btn-danger ml-auto">Delete</button>
                <?php endif; ?>
            </div>
            <div class="optee-credentials-status text-sm mt-2"></div>
        </form>
    </div>

    <div>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'virustotal' ) ); ?>"
           class="optee-btn-ghost inline-flex items-center gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><path d="m15 18-6-6 6-6"/></svg>
            Back to VirusTotal
        </a>
    </div>
</div>

<script>
(function ($) {
    'use strict';
    var opteeUnsavedConnection = false;
    var vtRedirectUrl = '<?php echo esc_js( \Optee\Plugin\Shortcode::get_url( 'virustotal' ) ); ?>';

    function vtRenderKeysInfo(keys) {
        var $c = $('.optee-vt-keys-info');
        if (!keys || !keys.length) { $c.hide().empty(); return; }
        var multi = keys.length > 1;
        var html = '<div style="display:flex;flex-direction:column;gap:0.5rem;">';
        if (multi) {
            html += '<div style="font-size:0.75rem;color:#6b7280;font-weight:600;">' + keys.length + ' API keys configured (one is picked at random per scan)</div>';
        }
        // The proxy panel is only meaningful with more than one key — see the
        // comment on the markup above.
        $('.optee-vt-proxy').toggle(multi);

        var proxied = vtProxyKeySet();

        keys.forEach(function (k) {
            // Three states, not two. A rate-limited key is a WORKING key that is
            // temporarily throttled — showing it as "invalid" (identical to a bad
            // key) leads people to delete or regenerate keys that are perfectly fine.
            var badge;
            if (k.valid) {
                badge = '<span style="font-size:0.7rem;color:#047857;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:9999px;padding:0.05rem 0.5rem;">valid</span>';
            } else if (k.limited) {
                badge = '<span style="font-size:0.7rem;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:9999px;padding:0.05rem 0.5rem;">rate limited</span>';
            } else {
                badge = '<span style="font-size:0.7rem;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:9999px;padding:0.05rem 0.5rem;">invalid</span>';
            }
            html += '<div style="border:1px solid #e5e7eb;border-radius:0.5rem;padding:0.5rem 0.625rem;background:#fafafa;">';
            html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">';
            html += '<span style="font-family:ui-monospace,monospace;font-size:0.78rem;color:#374151;">' + (multi ? 'Key #' + k.index + ': ' : '') + Optee.Util.escapeHtml(k.masked) + '</span>';
            html += badge;
            html += '</div>';

            if (multi) {
                // Route toggle. The label shows the route the LAST refresh actually
                // used (k.route from the server) so a ticked box that is being
                // ignored — empty proxy URL, say — cannot look active.
                var checked = proxied.indexOf(k.index) !== -1 ? ' checked' : '';
                var live    = (k.route === 'proxy')
                    ? '<span style="color:#2563eb;">via proxy</span>'
                    : '<span style="color:#9ca3af;">direct</span>';
                html += '<label style="display:flex;align-items:center;gap:0.35rem;margin-top:0.4rem;font-size:0.72rem;color:#4b5563;cursor:pointer;">' +
                    '<input type="checkbox" class="optee-vt-route-toggle" data-index="' + k.index + '"' + checked + '> ' +
                    'Send this key through the proxy <span style="color:#d1d5db;">·</span> now: ' + live +
                    '</label>';
            }
            if (k.valid && k.quotas && k.quotas.length) {
                html += '<div style="display:flex;flex-wrap:wrap;gap:0.375rem;margin-top:0.4rem;">';
                k.quotas.forEach(function (q) {
                    html += '<span style="font-size:0.72rem;background:#fff;border:1px solid #e5e7eb;border-radius:0.375rem;padding:0.1rem 0.45rem;color:#4b5563;">' +
                        Optee.Util.escapeHtml(q.label) + ': <strong style="color:#111827;">' + Number(q.remaining).toLocaleString() + '</strong> left ' +
                        '<span style="color:#9ca3af;">(' + Number(q.used).toLocaleString() + '/' + Number(q.allowed).toLocaleString() + ')</span></span>';
                });
                html += '</div>';
            } else if (!k.valid && k.error) {
                html += '<div style="font-size:0.72rem;color:' + (k.limited ? '#b45309' : '#b91c1c') + ';margin-top:0.3rem;">' + Optee.Util.escapeHtml(k.error) + '</div>';
            }
            html += '</div>';
        });
        html += '</div>';
        $c.html(html).show();
    }

    /** 1-based key positions currently pinned to the proxy. */
    function vtProxyKeySet() {
        var raw = ($('.optee-vt-proxy-keys').val() || '').split(',');
        var out = [];
        raw.forEach(function (v) {
            var n = parseInt(v, 10);
            if (n > 0 && out.indexOf(n) === -1) out.push(n);
        });
        return out;
    }

    function vtSetProxyKeySet(list) {
        list.sort(function (a, b) { return a - b; });
        $('.optee-vt-proxy-keys').val(list.join(','));
    }

    /**
     * Today's split by egress. This is the readout the whole feature exists for:
     * when Direct stalls at the ceiling while Proxy keeps climbing, the per-IP
     * allowance is confirmed on screen rather than inferred from the logs.
     */
    function vtRenderRouteCounts(routes) {
        var $c = $('.optee-vt-route-counts');
        if (!routes) { $c.empty(); return; }
        var d = Number(routes.direct || 0), p = Number(routes.proxy || 0);
        if (!d && !p) { $c.html('<span style="color:#9ca3af;">No calls recorded yet today.</span>'); return; }
        $c.html('Today &nbsp;·&nbsp; Direct: <strong style="color:#111827;">' + d.toLocaleString() + '</strong> calls' +
                ' &nbsp;·&nbsp; Proxy: <strong style="color:#2563eb;">' + p.toLocaleString() + '</strong> calls');
    }

    function vtLoadKeysInfo() {
        var $form = $('.optee-credentials-form');
        var $c = $('.optee-vt-keys-info');
        var raw = ($form.find('input[name="credentials[api_key]"]').val() || '').trim();
        if (!raw) { $c.hide().empty(); $('.optee-vt-proxy').hide(); return; }
        $c.show().html('<div style="font-size:0.78rem;color:#9ca3af;">Loading key info…</div>');
        // Send the routing fields too, so the badges and the "now:" labels reflect
        // what is typed in the form rather than what was last saved.
        Optee.Ajax.post('optee_vt_key_info', {
            'credentials[api_key]':    raw,
            'credentials[proxy_url]':  ($('.optee-vt-proxy-url').val() || '').trim(),
            'credentials[proxy_keys]': ($('.optee-vt-proxy-keys').val() || '')
        }).done(function (res) {
            if (res.success) {
                vtRenderKeysInfo(res.data.keys);
                vtRenderRouteCounts(res.data.routes);
            } else { $c.hide().empty(); }
        }).fail(function () { $c.hide().empty(); });
    }

    // Ticking a key rewrites the hidden field, then refreshes so each badge is
    // re-read over its new route.
    $(document).on('change', '.optee-vt-route-toggle', function () {
        var idx = parseInt($(this).data('index'), 10);
        var set = vtProxyKeySet();
        var at  = set.indexOf(idx);
        if (this.checked && at === -1) { set.push(idx); }
        if (!this.checked && at !== -1) { set.splice(at, 1); }
        vtSetProxyKeySet(set);
        $('.optee-credentials-form').find('.optee-save-credentials').prop('disabled', false);
        vtLoadKeysInfo();
    });

    // Re-check when the proxy URL changes, but only after typing stops.
    var vtProxyTimer = null;
    $(document).on('input', '.optee-vt-proxy-url', function () {
        $('.optee-credentials-form').find('.optee-save-credentials').prop('disabled', false);
        clearTimeout(vtProxyTimer);
        vtProxyTimer = setTimeout(vtLoadKeysInfo, 700);
    });

    $(function () { vtLoadKeysInfo(); });
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
                        window.location.href = vtRedirectUrl;
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
            if (res.success) { $status.html('<span class="text-green-700">' + Optee.Util.escapeHtml(res.data.message) + '</span>'); $saveBtn.prop('disabled', false); opteeUnsavedConnection = true; vtLoadKeysInfo(); }
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
})(jQuery);
</script>
