<?php
/**
 * Tools page — private tools enabled for this install's license.
 *
 * Populated from the grant-gated POST /api/tools. A license with no grants sees
 * nothing here (and the menu entry is hidden). Installing downloads from the
 * private tools/ channel via /api/tool-download and auto-activates the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$tools = \Optee\Plugin\Updater::get_available_tools( true );

if ( ! function_exists( 'is_plugin_active' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
?>
<div class="space-y-6">
    <div>
        <h2 class="text-xl font-bold text-gray-900">Tools</h2>
        <p class="mt-1 text-sm text-gray-500">Tools available for your license. Install one to add it to your dashboard, or request access to an available tool.</p>
    </div>

    <?php if ( empty( $tools ) ) : ?>
        <div class="optee-card">
            <div class="text-center py-10">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 12px;"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                <p class="text-gray-500 text-sm">No tools are enabled for your license yet.</p>
            </div>
        </div>
    <?php else : ?>
        <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:16px;">
            <?php foreach ( $tools as $tool ) :
                $slug        = $tool['slug'] ?? '';
                $name        = $tool['name'] ?? $slug;
                $desc        = $tool['description'] ?? '';
                $ver         = $tool['latest_version'] ?? '';
                // A tool is requestable when it's offered to all but not yet granted.
                $requestable = ! empty( $tool['requestable'] );
                $req_status  = $tool['request_status'] ?? 'none';
                $installed   = ! $requestable && ! empty( $tool['installed'] );
                $active      = $installed && $slug && is_plugin_active( $slug . '/' . $slug . '.php' );
                $update      = ! $requestable && ! empty( $tool['update_available'] );
                $inst_ver    = $tool['installed_version'] ?? '';
            ?>
                <div class="optee-card" style="padding:0;overflow:hidden;display:flex;flex-direction:column;min-height:150px;">
                    <div style="display:flex;align-items:flex-start;gap:16px;padding:18px 20px;flex:1;">
                        <!-- Icon -->
                        <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:#2aa5ff;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        </div>

                        <!-- Info -->
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:wrap;">
                                <h3 style="font-size:15px;font-weight:700;color:#111827;margin:0;"><?php echo esc_html( $name ); ?></h3>
                                <?php if ( $requestable && $req_status === 'pending' ) : ?>
                                    <span style="font-size:10px;padding:1px 8px;border-radius:9999px;background:#fef3c7;color:#92400e;font-weight:600;">Requested</span>
                                <?php elseif ( $requestable ) : ?>
                                    <span style="font-size:10px;padding:1px 8px;border-radius:9999px;background:#eff6ff;color:#1d4ed8;font-weight:600;">Available</span>
                                <?php elseif ( $installed && ! $active ) : ?>
                                    <span style="font-size:10px;padding:1px 8px;border-radius:9999px;background:#f3f4f6;color:#4b5563;font-weight:600;">Inactive</span>
                                <?php elseif ( $update ) : ?>
                                    <span style="font-size:10px;padding:1px 8px;border-radius:9999px;background:#fef3c7;color:#92400e;font-weight:600;">Update available</span>
                                <?php elseif ( $installed ) : ?>
                                    <span style="font-size:10px;padding:1px 8px;border-radius:9999px;background:#dcfce7;color:#166534;font-weight:600;">Installed</span>
                                <?php endif; ?>
                            </div>
                            <p style="font-size:12px;color:#6b7280;margin:0;line-height:1.55;"><?php echo esc_html( $desc ); ?></p>
                        </div>
                    </div>

                    <!-- Footer: slug + action -->
                    <div style="background:#f9fafb;border-top:1px solid #f3f4f6;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:auto;">
                        <span style="font-size:12px;color:#6b7280;font-weight:600;"><?php echo $ver ? 'v' . esc_html( $ver ) : ''; ?></span>
                        <div style="display:flex;align-items:center;gap:8px;">
                        <?php if ( $requestable ) : ?>
                            <?php if ( $req_status === 'pending' ) : ?>
                                <span style="font-size:12px;color:#9ca3af;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    Awaiting approval
                                </span>
                            <?php else : ?>
                                <button type="button" class="optee-btn-primary optee-btn-sm optee-tool-request" data-slug="<?php echo esc_attr( $slug ); ?>" style="font-size:12px;white-space:nowrap;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                                    Request access
                                </button>
                            <?php endif; ?>
                        <?php elseif ( ! $installed ) : ?>
                            <button type="button" class="optee-btn-primary optee-btn-sm optee-tool-install" data-slug="<?php echo esc_attr( $slug ); ?>" style="font-size:12px;white-space:nowrap;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Install
                            </button>
                        <?php else : ?>
                            <?php if ( ! $active ) : ?>
                                <button type="button" class="optee-btn-success optee-btn-sm optee-tool-activate" data-slug="<?php echo esc_attr( $slug ); ?>" style="font-size:12px;white-space:nowrap;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/></svg>
                                    Activate
                                </button>
                            <?php endif; ?>
                            <?php if ( $update ) : ?>
                                <button type="button" class="optee-tool-update" data-slug="<?php echo esc_attr( $slug ); ?>" style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;white-space:nowrap;background:#f59e0b;color:#fff;border:none;border-radius:8px;padding:0.35rem 0.75rem;cursor:pointer;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    Update<?php if ( $inst_ver && $ver ) echo ' (v' . esc_html( $inst_ver ) . ' &rarr; v' . esc_html( $ver ) . ')'; ?>
                                </button>
                            <?php endif; ?>
                            <?php if ( $active && ! $update ) : ?>
                                <span style="font-size:12px;color:#9ca3af;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    Up to date
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(function($) {
    function run(action, $btn, busyText, reloadMsg) {
        var slug = $btn.data('slug');
        var original = $btn.html();
        $btn.prop('disabled', true).css('opacity', 0.6).text(busyText);
        Optee.Ajax.post(action, { slug: slug })
            .done(function(res) {
                if (res && res.success) {
                    Optee.Toast.show((res.data && res.data.message) || 'Done.', 'success');
                    // Installing/activating a tool registers new menu items that only
                    // appear on a fresh load — prompt a reload, like the core updater.
                    setTimeout(function() {
                        if (window.confirm(reloadMsg)) {
                            location.reload();
                        } else {
                            $btn.prop('disabled', false).css('opacity', 1).html(original);
                        }
                    }, 300);
                } else {
                    Optee.Toast.show((res && res.data && res.data.message) || 'Action failed.', 'error');
                    $btn.prop('disabled', false).css('opacity', 1).html(original);
                }
            })
            .fail(function() {
                Optee.Toast.show('Request failed. Please try again.', 'error');
                $btn.prop('disabled', false).css('opacity', 1).html(original);
            });
    }

    $(document).on('click', '.optee-tool-request', function() {
        var $btn = $(this), slug = $btn.data('slug'), original = $btn.html();
        $btn.prop('disabled', true).css('opacity', 0.6).text('Requesting…');
        Optee.Ajax.post('optee_request_tool', { slug: slug })
            .done(function(res) {
                if (res && res.success) {
                    var status = res.data && res.data.status;
                    Optee.Toast.show((res.data && res.data.message) || 'Done.', 'success');
                    // Approved → card should flip to Install; pending → show Requested.
                    setTimeout(function() { location.reload(); }, 700);
                } else {
                    Optee.Toast.show((res && res.data && res.data.message) || 'Request failed.', 'error');
                    $btn.prop('disabled', false).css('opacity', 1).html(original);
                }
            })
            .fail(function() {
                Optee.Toast.show('Request failed. Please try again.', 'error');
                $btn.prop('disabled', false).css('opacity', 1).html(original);
            });
    });
    $(document).on('click', '.optee-tool-install', function() {
        run('optee_install_tool_from_api', $(this), 'Installing…', 'Tool installed and activated. Reload the page now to see it in your menu?');
    });
    $(document).on('click', '.optee-tool-update', function() {
        run('optee_update_tool_from_api', $(this), 'Updating…', 'Tool updated. Reload the page now to apply the new version?');
    });
    $(document).on('click', '.optee-tool-activate', function() {
        run('optee_activate_tool', $(this), 'Activating…', 'Tool activated. Reload the page now to see it in your menu?');
    });
});
</script>
