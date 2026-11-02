<?php
/**
 * Alerts module — list + management (Phase 1: balance threshold alerts).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<style>
    /* Hover-revealed "Logs" deep-link on each alert row. */
    .optee-alert-logs-link {
        display: inline-block;
        margin-left: 6px;
        font-size: 11px;
        font-weight: 600;
        color: #2aa5ff;
        text-decoration: none;
        opacity: 0;
        transition: opacity .12s ease;
    }
    .optee-alert-logs-link:hover { text-decoration: underline; }
    #optee-alerts-list tr:hover .optee-alert-logs-link,
    .optee-alert-logs-link:focus { opacity: 1; }
</style>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Alerts</h2>
            <p class="mt-1 text-sm text-gray-500">Get notified about <strong>balance thresholds</strong> (checked when balances refresh) and <strong>campaign performance</strong> (checked daily at your chosen time). Each alert notifies at most once per day.</p>
        </div>
        <button type="button" id="optee-new-alert" class="optee-btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            New Alert
        </button>
    </div>

    <div id="optee-alerts-list">
        <div class="text-center py-12 text-gray-400">Loading alerts...</div>
    </div>
</div>
