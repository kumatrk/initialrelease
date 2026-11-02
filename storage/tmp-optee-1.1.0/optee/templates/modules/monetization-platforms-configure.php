<?php
/**
 * Monetization Platforms — Configure a specific integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$slug = isset( $_GET['slug'] ) ? sanitize_key( $_GET['slug'] ) : '';

if ( ! $slug ) {
    echo '<p class="text-sm text-gray-500">No integration specified.</p>';
    return;
}

$module = $registry->get_module( $slug, 'monetization_platform' );

if ( ! $module ) {
    echo '<p class="text-sm text-gray-500">Monetization platform module not found.</p>';
    return;
}

$fields = $module->get_credential_fields();
$stored = \Optee\Plugin\ModuleRegistry::get_stored_credentials( 'monetization_platform', $slug );
$logo_url = '';
foreach ( [ 'logo.svg', 'logo.png', 'logo.jpg' ] as $logo_file ) {
    $logo_path = WP_PLUGIN_DIR . '/optee-monetization-' . $slug . '/assets/' . $logo_file;
    if ( file_exists( $logo_path ) ) {
        $logo_url = plugins_url( 'assets/' . $logo_file, WP_PLUGIN_DIR . '/optee-monetization-' . $slug . '/index.php' );
        break;
    }
}
?>
<div class="space-y-6">
    <div class="flex items-center gap-2 text-sm text-gray-500">
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'monetization-platforms' ) ); ?>"
           class="hover:text-gray-900 transition-colors">Monetization Platforms</a>
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        <span class="text-gray-900"><?php echo esc_html( $module->get_name() ); ?></span>
    </div>

    <?php
    // A promo shown above the credentials form is still useful after signup —
    // several partner offers apply to a top-up rather than registration — and
    // this is the last screen before the account gets funded. Renders nothing
    // when the partner has no live offer.
    $bonus = \Optee\Plugin\Bonuses::for_slug( $slug, 'monetization_platform' );
    if ( $bonus ) {
        $variant = 'strip';
        include OPTEE_PLUGIN_DIR . 'templates/partials/bonus-hint.php';
    }
    ?>

    <div class="optee-card" style="max-width:540px;">
        <div class="flex items-center gap-4 mb-4">
            <?php if ( $logo_url ) : ?>
                <img src="<?php echo esc_url( $logo_url ); ?>"
                     alt="<?php echo esc_attr( $module->get_name() ); ?>"
                     style="max-height:40px;max-width:140px;width:auto;">
            <?php else : ?>
                <h3 class="text-lg font-semibold text-gray-900"><?php echo esc_html( $module->get_name() ); ?></h3>
            <?php endif; ?>
            <?php if ( $stored ) : ?>
                <span class="optee-badge-green ml-auto">Connected</span>
            <?php else : ?>
                <span class="optee-badge-gray ml-auto">Not configured</span>
            <?php endif; ?>
        </div>

        <form class="optee-credentials-form space-y-4" data-type="monetization_platform" data-slug="<?php echo esc_attr( $slug ); ?>" autocomplete="off">
            <?php foreach ( $fields as $field ) :
                $field_type = $field['type'] ?? 'text';
                $field_key  = $field['key'] ?? '';
                $field_val  = $stored[ $field_key ] ?? '';
            ?>
                <div>
                    <?php if ( $field_type === 'checkbox' ) : ?>
                    <label class="flex items-center gap-2 cursor-pointer" style="margin-top:4px;">
                        <input type="checkbox"
                               name="credentials[<?php echo esc_attr( $field_key ); ?>]"
                               value="1"
                               <?php checked( $field_val, '1' ); ?>
                               class="optee-checkbox"
                               style="width:16px;height:16px;accent-color:#2aa5ff;">
                        <span class="text-sm text-gray-700"><?php echo esc_html( $field['label'] ?? $field_key ); ?></span>
                    </label>
                    <?php if ( ! empty( $field['help_text'] ) ) : ?>
                        <p class="text-xs text-gray-400" style="margin:2px 0 0 24px;"><?php echo esc_html( $field['help_text'] ); ?></p>
                    <?php endif; ?>
                    <?php else : ?>
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
                               autocomplete="new-password"
                               data-lpignore="true"
                               data-form-type="other"
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
                           autocomplete="off"
                           data-lpignore="true"
                           data-form-type="other"
                           placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>">
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ( ! empty( $field['note'] ) ) : ?>
                    <div style="margin-top:8px;padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;font-size:12px;color:#1e40af;line-height:1.5;display:flex;align-items:flex-start;gap:8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <span><?php echo esc_html( $field['note'] ); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

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

    <?php
    $capabilities = method_exists( $module, 'get_capabilities' ) ? $module->get_capabilities() : [];
    if ( $capabilities ) :
    ?>
    <div style="max-width:540px;">
        <button type="button" id="optee-toggle-capabilities" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1" style="background:none;border:none;cursor:pointer;padding:0;">
            <svg id="optee-cap-chevron" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;transition:transform 150ms;"><path d="m9 18 6-6-6-6"/></svg>
            Supported API Methods
        </button>
        <div id="optee-capabilities-list" class="hidden" style="margin-top:8px;">
            <div class="optee-card" style="padding:12px 16px;">
                <?php foreach ( $capabilities as $cap ) : ?>
                <div class="flex items-center gap-2" style="padding:3px 0;font-size:12px;">
                    <?php if ( $cap['implemented'] === true ) : ?>
                        <span style="color:#16a34a;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <span class="text-gray-700"><?php echo esc_html( $cap['label'] ); ?></span>
                    <?php elseif ( ( $cap['implemented'] ?? '' ) === 'unsupported' ) : ?>
                        <span style="color:#ef4444;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </span>
                        <span class="text-gray-400"><?php echo esc_html( $cap['label'] ); ?></span>
                        <span class="text-gray-300" style="font-size:10px;">Not supported by API</span>
                    <?php else : ?>
                        <span style="color:#d1d5db;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><circle cx="12" cy="12" r="10"/></svg>
                        </span>
                        <span class="text-gray-400"><?php echo esc_html( $cap['label'] ); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="flex items-center justify-between" style="max-width:540px;">
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'monetization-platforms' ) ); ?>"
           class="optee-btn-ghost inline-flex items-center gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><path d="m15 18-6-6 6-6"/></svg>
            Back to Monetization Platforms
        </a>
        <button type="button"
                class="optee-deactivate-plugin optee-btn-ghost text-red-600 hover:text-red-700"
                data-plugin="<?php echo esc_attr( 'optee-monetization-' . $slug . '/optee-monetization-' . $slug . '.php' ); ?>"
                data-return-url="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'monetization-platforms' ) ); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><path d="M18.36 6.64A9 9 0 0 1 20.77 15"/><path d="M6.16 6.16a9 9 0 1 0 12.68 12.68"/><path d="M12 2v4"/><path d="m2 2 20 20"/></svg>
            Deactivate
        </button>
    </div>
</div>

<script>
(function ($) {
    'use strict';
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
    $(document).on('input change', '.optee-credentials-form input[name^="credentials"]', function () {
        $(this).closest('.optee-credentials-form').find('.optee-save-credentials').prop('disabled', true);
        opteeUnsavedConnection = false;
    });
    $(document).on('submit', '.optee-credentials-form', function (e) {
        e.preventDefault();
        var $form = $(this), $status = $form.find('.optee-credentials-status'), $btn = $form.find('[type="submit"]').prop('disabled', true);
        var formData = { module_type: $form.data('type'), module_slug: $form.data('slug') };
        $form.find('input[name^="credentials"]').each(function () {
            if ($(this).attr('type') === 'checkbox') { formData[$(this).attr('name')] = $(this).is(':checked') ? '1' : ''; }
            else { formData[$(this).attr('name')] = $(this).val(); }
        });
        Optee.Ajax.post('optee_save_credentials', formData).done(function (res) {
            if (res.success) { opteeUnsavedConnection = false; Optee.Toast.show(res.data.message, 'success'); setTimeout(function () { location.reload(); }, 600); }
            else { $status.html('<span class="text-red-700">' + Optee.Util.escapeHtml(res.data.message) + '</span>'); $btn.prop('disabled', false); }
        }).fail(function () { $status.html('<span class="text-red-700">Request failed.</span>'); $btn.prop('disabled', false); });
    });
    $(document).on('click', '.optee-test-connection', function () {
        var $form = $(this).closest('.optee-credentials-form'), $status = $form.find('.optee-credentials-status'), $saveBtn = $form.find('.optee-save-credentials'), $btn = $(this).prop('disabled', true).text('Testing...');
        var formData = { module_type: $form.data('type'), module_slug: $form.data('slug') };
        $form.find('input[name^="credentials"]').each(function () {
            if ($(this).attr('type') === 'checkbox') { formData[$(this).attr('name')] = $(this).is(':checked') ? '1' : ''; }
            else { formData[$(this).attr('name')] = $(this).val(); }
        });
        Optee.Ajax.post('optee_test_connection', formData).done(function (res) {
            if (res.success) { $status.html('<span class="text-green-700">' + Optee.Util.escapeHtml(res.data.message) + '</span>'); $saveBtn.prop('disabled', false); opteeUnsavedConnection = true; }
            else { $status.html('<span class="text-red-700">' + Optee.Util.escapeHtml(res.data.message || 'Connection failed.') + '</span>'); $saveBtn.prop('disabled', true); }
            $btn.prop('disabled', false).text('Test Connection');
        }).fail(function () { $status.html('<span class="text-red-700">Request failed.</span>'); $btn.prop('disabled', false).text('Test Connection'); });
    });
    $(document).on('click', '#optee-toggle-capabilities', function () {
        var $list = $('#optee-capabilities-list');
        var $chevron = $('#optee-cap-chevron');
        $list.toggleClass('hidden');
        $chevron.css('transform', $list.hasClass('hidden') ? '' : 'rotate(90deg)');
    });
    $(document).on('click', '.optee-delete-credentials', function () {
        if (!Optee.Util.confirm('Delete credentials for this module?')) return;
        var $form = $(this).closest('.optee-credentials-form'), $status = $form.find('.optee-credentials-status');
        Optee.Ajax.post('optee_delete_credentials', { module_type: $form.data('type'), module_slug: $form.data('slug') }).done(function (res) {
            if (res.success) { $status.html('<span class="text-green-700">' + Optee.Util.escapeHtml(res.data.message) + '</span>'); $form.find('input').val(''); Optee.Toast.show(res.data.message, 'success'); setTimeout(function () { location.reload(); }, 1000); }
        });
    });

    // Deactivate plugin.
    $(document).on('click', '.optee-deactivate-plugin', function () {
        if (!Optee.Util.confirm('Deactivate this plugin?')) return;
        var $btn = $(this).prop('disabled', true).text('Deactivating…');
        var pluginFile = $btn.data('plugin');
        var returnUrl  = $btn.data('return-url');
        Optee.Ajax.post('optee_deactivate_plugin', { plugin_file: pluginFile }).done(function (res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
                setTimeout(function () { window.location.href = returnUrl; }, 3000);
            } else {
                Optee.Toast.show(res.data.message || 'Deactivation failed.', 'error');
                $btn.prop('disabled', false).text('Deactivate');
            }
        }).fail(function () {
            Optee.Toast.show('Network error. Please try again.', 'error');
            $btn.prop('disabled', false).text('Deactivate');
        });
    });
})(jQuery);
</script>
