<?php
/**
 * Settings — License.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$activated = \Optee\Plugin\Activation::is_activated();
$info      = \Optee\Plugin\Activation::get_info();
$is_expired = $info['is_expired'];
?>
<div class="space-y-6">
    <div class="flex items-center gap-4 border-b border-gray-200 pb-4">
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'profile' ) ); ?>"
           class="text-sm font-medium text-gray-500 hover:text-gray-900 pb-2">Profile</a>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'status' ) ); ?>"
           class="text-sm font-medium text-gray-500 hover:text-gray-900 pb-2">System Status</a>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'license' ) ); ?>"
           class="text-sm font-medium text-gray-900 border-b-2 border-brand-500 pb-2">License</a>
    </div>

    <div>
        <h2 class="text-xl font-bold text-gray-900">License</h2>
        <p class="mt-1 text-sm text-gray-500">Manage your Optee activation key.</p>
    </div>

    <?php if ( $activated ) : ?>
    <!-- Active license card -->
    <div class="optee-card">
        <div class="flex items-start justify-between">
            <div>
                <h3 class="font-semibold text-gray-900 mb-1">License Status</h3>
                <?php if ( $is_expired ) : ?>
                    <span class="inline-flex items-center gap-2 text-sm font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-3 py-0.5">
                        <span class="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0"></span>
                        Expired
                    </span>
                <?php else : ?>
                    <span class="inline-flex items-center gap-2 text-sm font-medium text-green-700 bg-green-50 border border-green-200 rounded-full px-3 py-0.5">
                        <span class="w-2 h-2 rounded-full bg-green-500 flex-shrink-0"></span>
                        Active
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( $is_expired ) : ?>
        <div class="mt-3 p-3 border rounded-lg text-sm" style="background:#fffbeb;border-color:#fcd34d;color:#92400e;">
            Your license has expired. The dashboard and automation continue to work, but plugin updates are disabled.
        </div>
        <?php endif; ?>

        <div class="mt-4 grid grid-cols-2 gap-4 text-sm optee-license-grid">
            <div>
                <span class="text-xs text-gray-500 block">License Key</span>
                <span class="font-mono text-gray-900"><?php echo esc_html( $info['key'] ); ?></span>
            </div>
            <div>
                <span class="text-xs text-gray-500 block">Key Type</span>
                <span class="text-gray-900 capitalize"><?php echo esc_html( $info['key_type'] ?: '—' ); ?></span>
            </div>
            <div>
                <span class="text-xs text-gray-500 block">Licensed To</span>
                <span class="text-gray-900"><?php echo esc_html( $info['name'] ); ?> (<?php echo esc_html( $info['email'] ); ?>)</span>
            </div>
            <div>
                <span class="text-xs text-gray-500 block">Domain</span>
                <span class="text-gray-900"><?php echo esc_html( $info['domain'] ); ?></span>
            </div>
            <div>
                <span class="text-xs text-gray-500 block">Activated</span>
                <span class="text-gray-900"><?php echo esc_html( $info['activated_at'] ?: '—' ); ?></span>
            </div>
            <div>
                <span class="text-xs text-gray-500 block">Expires</span>
                <span class="text-gray-900"><?php echo esc_html( $info['expiry'] ?: 'Never (lifetime)' ); ?></span>
            </div>
        </div>

        <div class="mt-8 pt-8 border-t border-gray-200 grid grid-cols-2 gap-4 optee-license-grid">
            <div>
                <h4 class="text-sm font-medium text-gray-900 mb-2">Transfer License</h4>
                <p class="text-xs text-gray-500 mb-3">Release the key from this domain so you can activate it on a different site. This will deactivate the plugin here.</p>
                <button type="button" id="optee-transfer-btn" class="optee-btn-danger optee-btn-sm">Transfer to Another Domain</button>
            </div>
            <div>
                <h4 class="text-sm font-medium text-gray-900 mb-2">Change Key</h4>
                <p class="text-xs text-gray-500 mb-3">Replace the current key with a different one. The old key remains active on this domain until the new one is validated.</p>
                <button type="button" id="optee-change-key-btn" class="optee-btn-secondary optee-btn-sm">Change License Key</button>
            </div>
        </div>

        <!-- Change Key form (hidden by default) -->
        <div id="optee-change-key-form-wrap" class="mt-4 pt-4 border-t border-gray-200" style="display:none;">
            <h4 class="text-sm font-medium text-gray-900 mb-3">Enter New License Key</h4>
            <form id="optee-change-key-form" class="space-y-3" style="max-width:480px;">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1" for="optee-new-key">License Key</label>
                    <input type="text" id="optee-new-key" class="optee-input" placeholder="OPTEE-XXXXX-XXXXX-XXXXX-XXXXX" required
                           style="text-transform:uppercase;letter-spacing:.05em;font-family:monospace;">
                </div>
                <div id="optee-change-key-error" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-3" style="display:none;"></div>
                <div class="flex items-center gap-2">
                    <button type="submit" id="optee-change-key-submit" class="optee-btn-primary optee-btn-sm">Activate New Key</button>
                    <button type="button" id="optee-change-key-cancel" class="optee-btn-secondary optee-btn-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <?php else : ?>
    <!-- No license — activation form -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-1">Activate Plugin</h3>
        <p class="text-xs text-gray-500 mb-4">Enter your license key to enable automation and updates.</p>

        <form id="optee-license-form" class="space-y-3" style="max-width:480px;">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1" for="optee-lic-key">License Key</label>
                <input type="text" id="optee-lic-key" class="optee-input" placeholder="OPTEE-XXXXX-XXXXX-XXXXX-XXXXX" required
                       style="text-transform:uppercase;letter-spacing:.05em;font-family:monospace;">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1" for="optee-lic-name">Your Name</label>
                <input type="text" id="optee-lic-name" class="optee-input" placeholder="John Doe" required>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1" for="optee-lic-email">Email Address</label>
                <input type="email" id="optee-lic-email" class="optee-input" placeholder="john@example.com" required>
            </div>
            <div id="optee-lic-error" class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-3"></div>
            <button type="submit" id="optee-lic-btn" class="optee-btn-primary">Activate</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
(function($) {
    'use strict';

    // Activation form (on license settings page).
    $('#optee-license-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#optee-lic-btn').prop('disabled', true).text('Activating...');
        var $error = $('#optee-lic-error').addClass('hidden');

        Optee.Ajax.post('optee_validate_activation_key', {
            key: $('#optee-lic-key').val().trim().toUpperCase(),
            name: $('#optee-lic-name').val().trim(),
            email: $('#optee-lic-email').val().trim()
        }).done(function(res) {
            if (res.success) {
                Optee.Toast.show('License activated!', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                $error.text(res.data.message || 'Activation failed.').removeClass('hidden');
                $btn.prop('disabled', false).text('Activate');
            }
        }).fail(function() {
            $error.text('Network error. Please try again.').removeClass('hidden');
            $btn.prop('disabled', false).text('Activate');
        });
    });

    // Transfer license button.
    $('#optee-transfer-btn').on('click', function() {
        if (!confirm('Are you sure? This will deactivate the plugin on this domain. You can re-activate on a different site.')) return;
        var $btn = $(this).prop('disabled', true).text('Transferring...');

        Optee.Ajax.post('optee_transfer_activation_key', {}).done(function(res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                Optee.Toast.show(res.data.message || 'Transfer failed.', 'error');
                $btn.prop('disabled', false).text('Transfer to Another Domain');
            }
        }).fail(function() {
            Optee.Toast.show('Network error.', 'error');
            $btn.prop('disabled', false).text('Transfer to Another Domain');
        });
    });

    // Change key — toggle form.
    $('#optee-change-key-btn').on('click', function() {
        $('#optee-change-key-form-wrap').slideDown(200);
        $('#optee-new-key').focus();
    });
    $('#optee-change-key-cancel').on('click', function() {
        $('#optee-change-key-form-wrap').slideUp(200);
        $('#optee-new-key').val('');
        $('#optee-change-key-error').hide();
    });

    // Change key — submit.
    $('#optee-change-key-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#optee-change-key-submit').prop('disabled', true).text('Activating...');
        var $error = $('#optee-change-key-error').hide();

        Optee.Ajax.post('optee_validate_activation_key', {
            key: $('#optee-new-key').val().trim().toUpperCase(),
            name: '<?php echo esc_js( $info['name'] ?? '' ); ?>',
            email: '<?php echo esc_js( $info['email'] ?? '' ); ?>'
        }).done(function(res) {
            if (res.success) {
                Optee.Toast.show('License key changed!', 'success');
                setTimeout(function() { location.reload(); }, 800);
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
