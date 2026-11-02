<?php
/**
 * Settings — System Status.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$optee_version    = defined( 'OPTEE_VERSION' ) ? OPTEE_VERSION : get_option( 'optee_version', 'N/A' );
$db_version       = get_option( 'optee_db_version', 'N/A' );
$wp_version       = get_bloginfo( 'version' );
$php_version      = phpversion();
$mysql_version    = $wpdb->db_version();
$theme            = wp_get_theme();
$cron_last_run    = get_option( 'optee_cron_last_run', 'Never' );
$cron_last_complete = get_option( 'optee_cron_last_complete', 'Never' );
$dashboard_page   = get_option( 'optee_dashboard_page_id', '' );
$all_modules      = $registry->get_modules();

$logs_table = $wpdb->prefix . 'optee_logs';
$logs_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );

$openssl = extension_loaded( 'openssl' ) ? 'Loaded' : 'Missing';

$cron_url = \Optee\Plugin\CronHandler::get_cron_url();

// DB table sizes.
$db_tables = $wpdb->get_results( $wpdb->prepare(
    "SELECT TABLE_NAME AS table_name,
            TABLE_ROWS AS table_rows,
            ROUND( ( DATA_LENGTH + INDEX_LENGTH ) / 1024 / 1024, 2 ) AS size_mb
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = %s
     ORDER BY ( DATA_LENGTH + INDEX_LENGTH ) DESC",
    DB_NAME
) );
$db_total_rows = 0;
$db_total_size = 0;
foreach ( $db_tables as $t ) {
    $db_total_rows += (int) $t->table_rows;
    $db_total_size += (float) $t->size_mb;
}
?>
<div class="space-y-6">
    <div class="flex items-center gap-4 border-b border-gray-200 pb-4">
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'profile' ) ); ?>"
           class="text-sm font-medium text-gray-500 hover:text-gray-900 pb-2">Profile</a>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'status' ) ); ?>"
           class="text-sm font-medium text-gray-900 border-b-2 border-brand-500 pb-2">System Status</a>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'settings', 'license' ) ); ?>"
           class="text-sm font-medium text-gray-500 hover:text-gray-900 pb-2">License</a>
    </div>

    <h2 class="text-xl font-bold text-gray-900">System Status</h2>

    <!-- Plugin info -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-4">Plugin Information</h3>
        <table class="optee-table optee-status-table">
            <tbody>
                <tr><td class="font-medium text-gray-500 w-48">Optee Version</td>
                    <td class="flex items-center gap-3">
                        <?php echo esc_html( $optee_version ); ?>
                        <button type="button" id="optee-check-core-update" class="optee-btn-secondary optee-btn-sm" style="font-size:11px;padding:2px 10px;">Check for Update</button>
                        <button type="button" id="optee-update-core" class="optee-btn-primary optee-btn-sm" style="font-size:11px;padding:2px 10px;display:none;">Update to <span id="optee-core-new-ver"></span></button>
                    </td>
                </tr>
                <tr><td class="font-medium text-gray-500">DB Version</td><td><?php echo esc_html( $db_version ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">Dashboard Page ID</td><td><?php echo esc_html( $dashboard_page ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">Log Entries</td><td><?php echo number_format( $logs_count ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <!-- Cron -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-4">Cron Configuration</h3>
        <table class="optee-table optee-status-table">
            <tbody>
                <tr><td class="font-medium text-gray-500 w-48">Cron URL</td>
                    <td>
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded text-gray-600 break-all"><?php echo esc_html( $cron_url ); ?></code>
                    </td>
                </tr>
                <tr><td class="font-medium text-gray-500">Last Run Started</td><td><?php echo esc_html( $cron_last_run ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">Last Run Completed</td><td><?php echo esc_html( $cron_last_complete ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">WP Cron Disabled</td>
                    <td>
                        <?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
                            <span class="optee-badge-green">Yes</span>
                        <?php else : ?>
                            <span class="optee-badge-yellow">No</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Installed modules -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-4">Installed Modules (<?php echo count( $all_modules ); ?>)</h3>
        <?php if ( empty( $all_modules ) ) : ?>
            <p class="text-sm text-gray-500">No modules installed.</p>
        <?php else : ?>
            <table class="optee-table optee-status-modules">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Type</th>
                        <th>Credentials</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $all_modules as $module ) :
                        $creds = \Optee\Plugin\ModuleRegistry::get_stored_credentials( $module->get_type(), $module->get_slug() );
                    ?>
                        <tr>
                            <td class="font-medium text-gray-900"><?php echo esc_html( $module->get_name() ); ?></td>
                            <td><?php echo esc_html( $module->get_slug() ); ?></td>
                            <td class="capitalize"><?php echo esc_html( str_replace( '_', ' ', $module->get_type() ) ); ?></td>
                            <td>
                                <?php if ( $creds ) : ?>
                                    <span class="optee-badge-green">Configured</span>
                                <?php else : ?>
                                    <span class="optee-badge-gray">Not set</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Server environment -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-4">Server Environment</h3>
        <table class="optee-table optee-status-table">
            <tbody>
                <tr><td class="font-medium text-gray-500 w-48">WordPress</td><td><?php echo esc_html( $wp_version ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">PHP</td><td><?php echo esc_html( $php_version ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">MySQL</td><td><?php echo esc_html( $mysql_version ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">Active Theme</td><td><?php echo esc_html( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">OpenSSL</td><td><?php echo esc_html( $openssl ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">Server Software</td><td><?php echo esc_html( $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">Memory Limit</td><td><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></td></tr>
                <tr><td class="font-medium text-gray-500">Max Execution Time</td><td><?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s</td></tr>
            </tbody>
        </table>
    </div>

    <!-- DB Info -->
    <div class="optee-card">
        <h3 class="font-semibold text-gray-900 mb-4">DB Info</h3>
        <?php if ( ! empty( $db_tables ) ) : ?>
            <div style="max-height:480px;overflow:auto;">
                <table class="optee-table optee-status-db">
                    <thead>
                        <tr>
                            <th class="text-left">Table Name</th>
                            <th class="text-right">Rows</th>
                            <th class="text-right">Size (MB)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $db_tables as $t ) : ?>
                            <tr>
                                <td class="text-gray-700"><?php echo esc_html( $t->table_name ); ?></td>
                                <td class="text-right"><?php echo number_format( (int) $t->table_rows ); ?></td>
                                <td class="text-right"><?php echo esc_html( $t->size_mb ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td>Total</td>
                            <td class="text-right"><?php echo number_format( $db_total_rows ); ?></td>
                            <td class="text-right"><?php echo number_format( $db_total_size, 2 ); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else : ?>
            <p class="text-sm text-gray-500">Unable to retrieve table information.</p>
        <?php endif; ?>
    </div>
</div>

<script>
(function($) {
    'use strict';

    $('#optee-check-core-update').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Checking...');

        Optee.Ajax.post('optee_check_plugin_updates', {}).done(function(res) {
            if (res.success && res.data.updates && res.data.updates['optee']) {
                var info = res.data.updates['optee'];
                if (info.update_available) {
                    $('#optee-core-new-ver').text(info.latest_version);
                    $('#optee-update-core').show();
                    $btn.text('v' + info.latest_version + ' available').css('color', '#d97706');
                } else {
                    $btn.text('Up to date').css('color', '#16a34a');
                }
            } else {
                $btn.text('Up to date').css('color', '#16a34a');
            }
            setTimeout(function() { $btn.prop('disabled', false); }, 3000);
        }).fail(function() {
            Optee.Toast.show('Failed to check for updates.', 'error');
            $btn.prop('disabled', false).text('Check for Update');
        });
    });

    $('#optee-update-core').on('click', function() {
        if (!confirm('Update Optee to the latest version? A page refresh will be required.')) return;
        var $btn = $(this).prop('disabled', true).text('Updating...');

        Optee.Ajax.post('optee_update_plugin_from_api', { slug: 'optee' }).done(function(res) {
            if (res.success) {
                Optee.Toast.show('Optee updated! Refreshing...', 'success');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                Optee.Toast.show(res.data.message || 'Update failed.', 'error');
                $btn.prop('disabled', false).text('Update');
            }
        }).fail(function() {
            Optee.Toast.show('Network error.', 'error');
            $btn.prop('disabled', false).text('Update');
        });
    });
})(jQuery);
</script>
