<?php
/**
 * Rule edit form — same layout as create, pre-filled.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rule_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
$rule    = get_post( $rule_id );

if ( ! $rule || $rule->post_type !== 'optee_rule' || (int) $rule->post_author !== get_current_user_id() ) {
    echo '<div class="optee-card"><p class="text-red-700">Rule not found or access denied.</p></div>';
    return;
}

$rule_meta = [
    'campaign_id' => (int) get_post_meta( $rule_id, '_optee_rule_campaign_id', true ),
    'action'      => get_post_meta( $rule_id, '_optee_rule_action', true ),
    'conditions'  => get_post_meta( $rule_id, '_optee_rule_conditions', true ) ?: [],
    'interval'    => (int) get_post_meta( $rule_id, '_optee_rule_interval', true ) ?: 60,
    'timeframe'   => get_post_meta( $rule_id, '_optee_rule_timeframe', true ) ?: '7d',
    'enabled'     => get_post_meta( $rule_id, '_optee_rule_enabled', true ) === '1',
    'on_success'  => get_post_meta( $rule_id, '_optee_rule_on_success', true ) ?: [],
    'is_template' => get_post_meta( $rule_id, '_optee_rule_is_template', true ) === '1',
    'last_run'    => get_post_meta( $rule_id, '_optee_rule_last_run', true ),
];

$last_run_label = 'Never';
if ( ! empty( $rule_meta['last_run'] ) ) {
    $last_run_ts = strtotime( $rule_meta['last_run'] );
    $now_ts      = current_time( 'timestamp' );
    if ( $last_run_ts && $last_run_ts <= $now_ts ) {
        $last_run_label = human_time_diff( $last_run_ts, $now_ts ) . ' ago';
    }
}

$campaign = get_post( $rule_meta['campaign_id'] );

$back_url = \Optee\Plugin\Shortcode::get_url( 'rules' );

$rule_actions = [
    'block_zone'       => 'Block Zone(s)',
    'unblock_zone'     => 'Unblock Zone(s)',
    'remove_countries' => 'Remove Countries',
    'remove_targeting' => 'Remove Targeting',
    'adjust_bid'       => 'Adjust Bid (%)',
    'pause_traffic'    => 'Pause Traffic',
    'resume_traffic'   => 'Resume Traffic',
];

$rule_action_params = get_post_meta( $rule_id, '_optee_rule_action_params', true ) ?: [];

$operators = [
    '>'  => 'greater than',
    '>=' => 'greater or equal',
    '<'  => 'less than',
    '<=' => 'less or equal',
    '==' => 'equal to',
    '!=' => 'not equal to',
];

$timeframes = [
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    '24h'       => 'Last 24 hours',
    '48h'       => 'Last 48 hours',
    '3d'        => 'Last 3 days',
    '7d'        => 'Last 7 days',
    '14d'       => 'Last 14 days',
    '30d'       => 'Last 30 days',
    '60d'       => 'Last 60 days',
    '90d'       => 'Last 90 days',
    '180d'      => 'Last 180 days',
];

$intervals = [
    5    => '5 minutes',
    15   => '15 minutes',
    30   => '30 minutes',
    60   => '1 hour',
    180  => '3 hours',
    360  => '6 hours',
    720  => '12 hours',
    1440 => '24 hours',
    4320 => '3 days',
    10080 => '7 days',
];
?>
<div class="space-y-6 optee-form-page" style="max-width:min(900px,70%);">
    <div>
        <a href="<?php echo esc_url( $back_url ); ?>"
           class="text-sm text-gray-500 hover:text-gray-900 transition-colors">&larr; Back to Rules</a>
        <h2 class="mt-2 text-xl font-bold text-gray-900">Edit Rule</h2>
    </div>

    <div id="optee-ts-api-notice" class="rounded-lg border px-4 py-3 text-sm" style="display:none; background:#eff6ff; border-color:#bfdbfe; color:#1e40af;">
        <div class="flex items-start gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 mt-0.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            <span id="optee-ts-api-notice-text"></span>
        </div>
    </div>

    <form id="optee-rule-edit-form" class="optee-card space-y-5" data-rule-id="<?php echo esc_attr( $rule_id ); ?>">
        <input type="hidden" name="campaign_id" value="<?php echo esc_attr( $rule_meta['campaign_id'] ); ?>">

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;">Campaign</label>
            <div class="flex-1">
                <?php if ( $campaign ) : ?>
                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'dashboard', 'campaign', [ 'id' => $rule_meta['campaign_id'] ] ) ); ?>"
                   target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:text-brand-700 hover:underline">
                    <?php echo esc_html( $campaign->post_title ); ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                </a>
                <?php else : ?>
                <span class="text-sm font-medium text-gray-900">(deleted)</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;" for="rule-title">Rule Name</label>
            <div class="flex-1">
                <input type="text" id="rule-title" name="title" class="optee-input w-full"
                       value="<?php echo esc_attr( $rule->post_title ); ?>" required>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;" for="rule-action">Action<br><span class="text-xs font-normal text-gray-400">(if all conditions met)</span></label>
            <div class="flex-1">
                <select id="rule-action" name="rule_action" class="optee-select w-full" required>
                    <?php foreach ( $rule_actions as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rule_meta['action'], $val ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php include OPTEE_PLUGIN_DIR . 'templates/rules/action-params.php'; ?>

        <!-- Conditions -->
        <div>
            <label class="optee-label mb-2">Conditions (all must be true)</label>
            <div id="rule-conditions" class="space-y-2"></div>
            <button type="button" id="rule-add-condition" class="optee-btn-ghost optee-btn-sm mt-2">+ Add Condition</button>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;" for="rule-timeframe">Time Interval<br><span class="text-xs font-normal text-gray-400">(load data from)</span></label>
            <div class="flex-1">
                <select id="rule-timeframe" name="timeframe" class="optee-select w-full">
                    <?php foreach ( $timeframes as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rule_meta['timeframe'], $val ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;" for="rule-interval">Run Every</label>
            <div class="flex-1">
                <select id="rule-interval" name="interval" class="optee-select w-full">
                    <?php foreach ( $intervals as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rule_meta['interval'], (int) $val ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;">Rule Status</label>
            <div class="flex-1 flex items-center gap-4">
                <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                    <input type="radio" name="enabled" value="1" <?php checked( $rule_meta['enabled'] ); ?> class="text-brand-600 focus:ring-brand-500"> Active
                </label>
                <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                    <input type="radio" name="enabled" value="0" <?php checked( ! $rule_meta['enabled'] ); ?> class="text-brand-600 focus:ring-brand-500"> Paused
                </label>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;">If Execution is Successful</label>
            <div class="flex-1 space-y-1.5">
                <label class="flex items-center gap-2 cursor-pointer text-sm">
                    <input type="checkbox" name="on_success_pause_rule" value="1"
                           <?php checked( in_array( 'pause_rule', $rule_meta['on_success'], true ) ); ?>
                           class="h-4 w-4 rounded border-gray-300 bg-gray-100 text-brand-600 focus:ring-brand-500">
                    Pause this rule
                </label>
                <label class="flex items-center gap-2 cursor-pointer text-sm">
                    <input type="checkbox" name="on_success_pause_campaign" value="1"
                           <?php checked( in_array( 'pause_campaign', $rule_meta['on_success'], true ) ); ?>
                           class="h-4 w-4 rounded border-gray-300 bg-gray-100 text-brand-600 focus:ring-brand-500">
                    Pause this campaign (local + traffic source)
                </label>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;">Save as Template</label>
            <div class="flex-1 flex items-center gap-4">
                <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                    <input type="radio" name="is_template" value="0" <?php checked( ! $rule_meta['is_template'] ); ?> class="text-brand-600 focus:ring-brand-500"> No
                </label>
                <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                    <input type="radio" name="is_template" value="1" <?php checked( $rule_meta['is_template'] ); ?> class="text-brand-600 focus:ring-brand-500"> Yes
                </label>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;">Last Run</label>
            <div class="flex-1 flex items-center gap-3 text-sm">
                <span id="optee-last-run-label" class="text-gray-700"><?php echo esc_html( $last_run_label ); ?></span>
                <?php if ( ! empty( $rule_meta['last_run'] ) ) : ?>
                    <a href="#" id="optee-reset-last-run" class="text-brand-600 hover:text-brand-800 hover:underline"
                       title="Clear the last-run timestamp so the rule fires on the next automation cycle instead of waiting for the next interval">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="optee-btn-primary">Save Changes</button>
            <a href="<?php echo esc_url( $back_url ); ?>" class="optee-btn-secondary">Cancel</a>
            <div class="ml-auto flex items-center gap-3">
                <button type="button" class="optee-btn-secondary optee-dry-run-rule">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;margin-right:4px;"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    Dry Run
                </button>
                <button type="button" id="optee-delete-rule" class="optee-btn-danger">Delete</button>
            </div>
        </div>
    </form>
</div>

<?php
// Available sources for this campaign.
$edit_sources = [ 'traffic_source' ];
if ( get_post_meta( $rule_meta['campaign_id'], '_optee_antifraud_module', true ) ) {
    $edit_sources[] = 'antifraud';
}
if ( get_post_meta( $rule_meta['campaign_id'], '_optee_tracker_module', true ) ) {
    $edit_sources[] = 'tracker';
}
if (
    get_post_meta( $rule_meta['campaign_id'], '_optee_affiliate_network_module', true ) &&
    get_post_meta( $rule_meta['campaign_id'], '_optee_offer_id', true ) &&
    get_post_meta( $rule_meta['campaign_id'], '_optee_is_smartlink', true ) !== '1'
) {
    $edit_sources[] = 'affiliate_network';
}
if ( ! empty( \Optee\Plugin\CustomColumns::get_all( get_current_user_id() ) ) ) {
    $edit_sources[] = 'custom';
}
// VirusTotal is watched per user, so it's offered whenever there's an active URL.
$vt_urls = \Optee\Plugin\Rules\RuleCondition::vt_url_options( get_current_user_id() );
if ( ! empty( $vt_urls ) ) {
    $edit_sources[] = 'virustotal';
}
?>
<script>
window.opteeVtUrls = <?php echo wp_json_encode( $vt_urls ); ?>;
window.opteeRuleOperators = <?php echo wp_json_encode( $operators ); ?>;
window.opteeRuleConditions = <?php echo wp_json_encode( $rule_meta['conditions'] ); ?>;
window.opteeRuleActionParams = <?php echo wp_json_encode( $rule_action_params ); ?>;
window.opteeCampaignSources = { <?php echo (int) $rule_meta['campaign_id']; ?>: <?php echo wp_json_encode( $edit_sources ); ?> };
window.opteeCampaignTsSlugs = { <?php echo (int) $rule_meta['campaign_id']; ?>: <?php echo wp_json_encode( get_post_meta( $rule_meta['campaign_id'], '_optee_traffic_source_module', true ) ?: '' ); ?> };
window.opteeCampaignTsReady = { <?php echo (int) $rule_meta['campaign_id']; ?>: <?php echo wp_json_encode( (bool) ( get_post_meta( $rule_meta['campaign_id'], '_optee_traffic_source_module', true ) && get_post_meta( $rule_meta['campaign_id'], '_optee_traffic_source_campaign_id', true ) ) ); ?> };
<?php
$edit_caps    = [];
$edit_ts_slug = get_post_meta( $rule_meta['campaign_id'], '_optee_traffic_source_module', true );
$edit_mod     = $edit_ts_slug ? \Optee\Plugin\ModuleRegistry::get_instance()->get_module( $edit_ts_slug ) : null;
if ( $edit_mod ) {
    if ( method_exists( $edit_mod, 'remove_countries' ) ) { $edit_caps[] = 'remove_countries'; }
    if ( method_exists( $edit_mod, 'adjust_bid' ) )        { $edit_caps[] = 'adjust_bid'; }
    if ( method_exists( $edit_mod, 'remove_targeting' ) )  { $edit_caps[] = 'remove_targeting'; }
}
?>
window.opteeCampaignCaps = { <?php echo (int) $rule_meta['campaign_id']; ?>: <?php echo wp_json_encode( $edit_caps ); ?> };
window.opteePresetCampaignId = <?php echo (int) $rule_meta['campaign_id']; ?>;
</script>
