<?php
/**
 * Earnings page — shows daily revenue and cost from integrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Earnings</h2>
            <p class="mt-1 text-sm text-gray-500">Daily revenue and cost breakdown from your integrations.</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" id="optee-earnings-reload" class="optee-btn-secondary text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                Reload
            </button>
            <select id="optee-earnings-year" class="optee-select" style="width:auto;min-width:100px;"></select>
        </div>
    </div>

    <!-- Earnings table container -->
    <div id="optee-earnings-container">
        <div id="optee-earnings-loading" class="flex items-center justify-center py-12">
            <span class="optee-spinner"></span>
            <span class="ml-3 text-sm text-gray-500">Loading earnings...</span>
        </div>
        <div id="optee-earnings-empty" class="hidden text-center py-12">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto text-gray-300 mb-4"><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>
            <p class="text-gray-500 text-sm">No earnings data yet.</p>
            <p class="text-gray-400 text-xs mt-1">Click <strong>Reload</strong> to fetch earnings from your configured integrations.</p>
        </div>
        <div id="optee-earnings-table-wrap" class="hidden" style="overflow:auto;">
            <table id="optee-earnings-table" class="optee-earnings-table">
                <thead></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
<script>
(function() {
    function resizeEarningsWrap() {
        var w = document.getElementById('optee-earnings-table-wrap');
        var f = document.getElementById('optee-footer');
        if (!w) return;
        var top = w.getBoundingClientRect().top;
        var footerH = f ? f.offsetHeight : 0;
        var main = document.getElementById('optee-content');
        if (main) main.style.overflow = 'hidden';
        w.style.maxHeight = (window.innerHeight - top - footerH) + 'px';
    }
    resizeEarningsWrap();
    window.addEventListener('resize', resizeEarningsWrap);
    // Re-calc after table renders.
    var observer = new MutationObserver(resizeEarningsWrap);
    var wrap = document.getElementById('optee-earnings-table-wrap');
    if (wrap) observer.observe(wrap, { attributes: true, attributeFilter: ['class'] });
})();


</script>
