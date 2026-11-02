<?php
/**
 * Rules list view — grouped by campaign.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$filter_campaign_id = isset( $_GET['campaign_id'] ) ? intval( $_GET['campaign_id'] ) : 0;
$filter_campaign    = $filter_campaign_id ? get_post( $filter_campaign_id ) : null;
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Rules</h2>
            <p class="mt-1 text-sm text-gray-500">Automated campaign optimization rules, grouped by campaign.</p>
            <div id="optee-rules-summary" class="mt-1.5 text-xs text-gray-400" style="display:none;"></div>
        </div>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'rules', 'create' ) ); ?>"
           class="optee-btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            New Rule
        </a>
    </div>

<?php if ( $filter_campaign ) : ?>
    <div id="optee-rules-filter" class="flex items-center gap-2">
        <span class="text-sm text-gray-500">Filtered by:</span>
        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium" style="background:#eff6ff;color:#1d4ed8;">
            <?php echo esc_html( $filter_campaign->post_title ); ?>
            <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'rules' ) ); ?>" title="Remove filter" style="color:#1d4ed8;text-decoration:none;display:inline-flex;align-items:center;margin-left:2px;cursor:pointer;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </a>
        </span>
    </div>
<?php endif; ?>

    <div id="optee-rules-grouped" data-filter-campaign="<?php echo esc_attr( $filter_campaign_id ); ?>">
        <div class="text-center py-12 text-gray-400">Loading rules...</div>
    </div>
</div>
