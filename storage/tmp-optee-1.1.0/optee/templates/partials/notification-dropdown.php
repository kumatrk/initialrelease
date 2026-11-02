<?php
/**
 * Notification dropdown panel.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="optee-notification-dropdown"
     class="absolute right-0 top-full mt-2 hidden w-80 rounded-xl border border-gray-200 bg-white shadow-2xl z-50">
    <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
        <h3 class="text-sm font-semibold text-gray-900">Notifications</h3>
        <button id="optee-mark-all-read" class="text-xs text-brand-600 hover:text-brand-700" style="cursor:pointer;">Mark all read</button>
    </div>
    <div id="optee-notification-list" class="max-h-80 overflow-y-auto">
        <div class="px-4 py-8 text-center text-sm text-gray-400">No notifications</div>
    </div>
    <div class="border-t border-gray-200 px-4 py-2 text-center">
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'logs', 'notifications' ) ); ?>" class="text-xs text-brand-600 hover:text-brand-700" style="cursor:pointer;">View all notifications</a>
    </div>
</div>
