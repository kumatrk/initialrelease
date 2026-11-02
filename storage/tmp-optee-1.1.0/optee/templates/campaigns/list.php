<?php
/**
 * Campaign list view.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="space-y-6">
    <div class="optee-campaigns-header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Campaigns</h2>
                <p class="mt-1 text-sm text-gray-500">Manage your affiliate campaigns.</p>
            </div>
            <div class="flex items-center gap-3 optee-campaigns-header-desktop">
                <select id="optee-campaign-status-filter" class="optee-select" style="width:auto;min-width:100px;">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="paused">Paused</option>
                    <option value="archived">Archived</option>
                </select>
                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'campaigns', 'create' ) ); ?>"
                   class="optee-btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    New Campaign
                </a>
            </div>
        </div>
        <div class="optee-campaigns-header-mobile">
            <div class="flex items-center justify-between gap-3 mt-3">
                <select id="optee-campaign-status-filter-mob" class="optee-select" style="flex:1;">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="paused">Paused</option>
                    <option value="archived">Archived</option>
                </select>
            </div>
            <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'campaigns', 'create' ) ); ?>"
               class="optee-btn-primary mt-2" style="display:flex;justify-content:center;width:100%;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                New Campaign
            </a>
        </div>
    </div>

    <!-- Campaign table (desktop) -->
    <div id="optee-campaigns-table-wrap" class="optee-card p-0 overflow-hidden">
        <table class="optee-table">
            <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Traffic Source</th>
                    <th>Tracker</th>
                    <th>Rules</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="optee-campaigns-tbody">
                <tr>
                    <td colspan="7" class="text-center py-8 text-gray-400">Loading campaigns...</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Campaign cards (mobile) -->
    <div id="optee-campaigns-cards">
        <div class="text-center py-8 text-gray-400">Loading campaigns...</div>
    </div>

    <!-- Pagination -->
    <div id="optee-campaigns-pagination" class="flex items-center justify-between text-sm text-gray-500"></div>
</div>
<style>
#optee-campaigns-tbody tr:hover .optee-add-rules-link { opacity: 1 !important; }
</style>
