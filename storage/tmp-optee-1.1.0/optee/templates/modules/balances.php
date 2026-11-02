<?php
/**
 * Balances page — shows daily balance snapshots for configured modules.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Balances</h2>
            <p class="mt-1 text-sm text-gray-500">Daily balance snapshots from your integrations. Some integrations don't support balance updates via API — you can manually enter balances for them, <a href="#" id="optee-balance-configure" class="text-brand-600 hover:underline">configure here</a>.</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" id="optee-balance-reload" class="optee-btn-secondary text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                Reload
            </button>
            <select id="optee-balance-days" class="optee-select" style="width:auto;min-width:100px;">
                <option value="7">7 days</option>
                <option value="14">14 days</option>
                <option value="30" selected>30 days</option>
                <option value="60">60 days</option>
                <option value="90">90 days</option>
            </select>
        </div>
    </div>

    <!-- Balance table container -->
    <div id="optee-balance-container">
        <div id="optee-balance-loading" class="flex items-center justify-center py-12">
            <span class="optee-spinner"></span>
            <span class="ml-3 text-sm text-gray-500">Loading balances...</span>
        </div>
        <div id="optee-balance-empty" class="hidden text-center py-12">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto text-gray-300 mb-4"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2.5"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>
            <p class="text-gray-500 text-sm">No balance data yet.</p>
            <p class="text-gray-400 text-xs mt-1">Click <strong>Reload</strong> to fetch balances from your configured integrations.</p>
        </div>
        <div id="optee-balance-table-wrap" class="hidden" style="overflow:auto;">
            <table id="optee-balance-table" class="optee-balance-table">
                <thead></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
<script>
(function() {
    function resizeBalanceWrap() {
        var w = document.getElementById('optee-balance-table-wrap');
        var f = document.getElementById('optee-footer');
        if (!w) return;
        var top = w.getBoundingClientRect().top;
        var footerH = f ? f.offsetHeight : 0;
        var main = document.getElementById('optee-content');
        if (main) main.style.overflow = 'hidden';
        w.style.maxHeight = (window.innerHeight - top - footerH) + 'px';
    }
    resizeBalanceWrap();
    window.addEventListener('resize', resizeBalanceWrap);
    var observer = new MutationObserver(resizeBalanceWrap);
    var wrap = document.getElementById('optee-balance-table-wrap');
    if (wrap) observer.observe(wrap, { attributes: true, attributeFilter: ['class'] });
})();
</script>

<!-- Manual Balance Modal -->
<div id="optee-balance-modal" class="hidden">
    <div class="optee-modal-overlay">
        <div class="optee-modal-content">
            <h3 class="text-lg font-bold text-gray-900 mb-1">Manual Balance Tracking</h3>
            <p class="text-sm text-gray-500 mb-4">Enable platforms to manually track balances.</p>

            <div id="optee-balance-module-list">
                <div class="flex items-center justify-center py-4">
                    <span class="optee-spinner" style="width:20px;height:20px;border-width:2px;"></span>
                    <span class="ml-2 text-sm text-gray-500">Loading modules...</span>
                </div>
            </div>

            <div class="border-t border-gray-200 pt-4 mt-4 pb-4">
                <p class="text-sm font-medium text-gray-700 mb-2">Add Custom Platform</p>
                <div id="optee-balance-custom-row" class="flex items-center gap-3" style="flex-wrap:wrap;">
                    <input type="text" id="optee-balance-custom-name" placeholder="Platform name" class="optee-input" style="flex:1;min-width:160px;">
                    <select id="optee-balance-custom-type" class="optee-select" style="width:auto;">
                        <option value="traffic_source">Traffic Source</option>
                        <option value="affiliate_network">Affiliate Network</option>
                        <option value="monetization_platform">Monetization</option>
                    </select>
                    <button type="button" id="optee-balance-custom-add" class="optee-btn-secondary" style="white-space:nowrap;">Add Platform</button>
                </div>
                <style>
                /* On narrow screens, give the name field its own full-width line above the type + button. */
                @media (max-width: 640px) {
                    #optee-balance-custom-row #optee-balance-custom-name { order: 1; flex: 1 1 100%; width: 100%; }
                    #optee-balance-custom-row #optee-balance-custom-type { order: 2; flex: 1 1 auto; }
                    #optee-balance-custom-row #optee-balance-custom-add  { order: 3; }
                }
                </style>
            </div>

            <div class="border-t border-gray-200 pt-4 flex justify-end gap-3">
                <button type="button" class="optee-btn-secondary optee-balance-modal-close">Cancel</button>
                <button type="button" id="optee-balance-save-modules" class="optee-btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>
