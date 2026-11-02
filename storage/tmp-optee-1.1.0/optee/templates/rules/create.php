<?php
/**
 * Rule create form — new layout with multi-source conditions, timeframe, templates.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$campaign_id = isset( $_GET['campaign_id'] ) ? intval( $_GET['campaign_id'] ) : 0;
$campaign    = $campaign_id ? get_post( $campaign_id ) : null;

// Back link: if campaign_id given, go to campaign stats; otherwise to rules list.
$back_url = $campaign_id
    ? \Optee\Plugin\Shortcode::get_url( 'campaigns', 'stats' ) . '&id=' . $campaign_id
    : \Optee\Plugin\Shortcode::get_url( 'rules' );
$back_label = $campaign_id ? 'Back to Campaign' : 'Back to Rules';

// Build campaign list + module map.
$user_id   = get_current_user_id();
$all_campaigns = get_posts( [
    'post_type'      => 'optee_campaign',
    'post_status'    => 'publish',
    'author'         => $user_id,
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
] );

$campaigns = $campaign_id ? [] : $all_campaigns;

// Map campaign_id → available sources (traffic_source always available).
$campaign_sources = [];
$campaign_ts_slugs = [];
$campaign_ts_ready = [];
$campaign_ts_caps  = []; // which capability-gated actions the campaign's TS supports
$_reg = \Optee\Plugin\ModuleRegistry::get_instance();
foreach ( $all_campaigns as $c ) {
    $sources = [ 'traffic_source' ];
    if ( get_post_meta( $c->ID, '_optee_antifraud_module', true ) ) {
        $sources[] = 'antifraud';
    }
    if ( get_post_meta( $c->ID, '_optee_tracker_module', true ) ) {
        $sources[] = 'tracker';
    }
    if (
        get_post_meta( $c->ID, '_optee_affiliate_network_module', true ) &&
        get_post_meta( $c->ID, '_optee_offer_id', true ) &&
        get_post_meta( $c->ID, '_optee_is_smartlink', true ) !== '1'
    ) {
        $sources[] = 'affiliate_network';
    }
    $ts_slug = get_post_meta( $c->ID, '_optee_traffic_source_module', true ) ?: '';
    $campaign_sources[ $c->ID ] = $sources;
    $campaign_ts_slugs[ $c->ID ] = $ts_slug;
    // Zone actions need both the module slug AND a remote campaign ID (see RuleAction::execute).
    $campaign_ts_ready[ $c->ID ] = ( $ts_slug && get_post_meta( $c->ID, '_optee_traffic_source_campaign_id', true ) );

    // Capability-gated actions — show only what the traffic source's API supports.
    $caps = [];
    $mod  = $ts_slug ? $_reg->get_module( $ts_slug ) : null;
    if ( $mod ) {
        if ( method_exists( $mod, 'remove_countries' ) ) { $caps[] = 'remove_countries'; }
        if ( method_exists( $mod, 'adjust_bid' ) )        { $caps[] = 'adjust_bid'; }
        if ( method_exists( $mod, 'remove_targeting' ) )  { $caps[] = 'remove_targeting'; }
    }
    $campaign_ts_caps[ $c->ID ] = $caps;
}

// If the user has any custom columns, the 'custom' source is available on every campaign.
$has_custom_cols = ! empty( \Optee\Plugin\CustomColumns::get_all( $user_id ) );
if ( $has_custom_cols ) {
    foreach ( $campaign_sources as $cid => $srcs ) {
        $campaign_sources[ $cid ][] = 'custom';
    }
}

// VirusTotal is watched per user, not per campaign — so the source is offered on
// every campaign as soon as there's at least one active monitored URL to point at.
$vt_urls = \Optee\Plugin\Rules\RuleCondition::vt_url_options( $user_id );
if ( ! empty( $vt_urls ) ) {
    foreach ( $campaign_sources as $cid => $srcs ) {
        $campaign_sources[ $cid ][] = 'virustotal';
    }
}

$rule_actions = [
    'block_zone'       => 'Block Zone(s)',
    'unblock_zone'     => 'Unblock Zone(s)',
    'remove_countries' => 'Remove Countries',
    'remove_targeting' => 'Remove Targeting',
    'adjust_bid'       => 'Adjust Bid (%)',
    'pause_traffic'    => 'Pause Traffic',
    'resume_traffic'   => 'Resume Traffic',
];

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
           class="text-sm text-gray-500 hover:text-gray-900 transition-colors">&larr; <?php echo esc_html( $back_label ); ?></a>
        <div class="flex items-center justify-between mt-2">
            <h2 class="text-xl font-bold text-gray-900">Create Rule</h2>
            <button type="button" id="rule-import-template" class="optee-btn-secondary optee-btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                Import from Template
            </button>
        </div>
    </div>

    <div id="optee-ts-api-notice" class="rounded-lg border px-4 py-3 text-sm" style="display:none; background:#eff6ff; border-color:#bfdbfe; color:#1e40af;">
        <div class="flex items-start gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 mt-0.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            <span id="optee-ts-api-notice-text"></span>
        </div>
    </div>

    <form id="optee-rule-form" class="optee-card space-y-5">
        <?php if ( $campaign_id ) : ?>
            <input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>">
            <div class="optee-form-row">
                <label class="optee-label" style="width:180px;flex-shrink:0;">Campaign</label>
                <div class="flex-1">
                    <?php if ( $campaign ) : ?>
                    <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'dashboard', 'campaign', [ 'id' => $campaign_id ] ) ); ?>"
                       target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:text-brand-700 hover:underline">
                        <?php echo esc_html( $campaign->post_title ); ?>
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                    </a>
                    <?php else : ?>
                    <span class="text-sm font-medium text-gray-900">(Unknown)</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php else : ?>
            <div class="optee-form-row">
                <label class="optee-label" style="width:180px;flex-shrink:0;" for="rule-campaign">Campaign</label>
                <div class="flex-1">
                    <select id="rule-campaign" name="campaign_id" class="optee-select w-full" required>
                        <option value="">-- Select Campaign --</option>
                        <?php foreach ( $campaigns as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->ID ); ?>"><?php echo esc_html( $c->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        <fieldset id="rule-fields" <?php if ( ! $campaign_id ) echo 'disabled'; ?> class="space-y-5" style="border:none;padding:0;">

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;" for="rule-title">Rule Name</label>
            <div class="flex-1">
                <input type="text" id="rule-title" name="title" class="optee-input w-full" placeholder="e.g. Block zones with bot% > 30" required>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;" for="rule-action">Action<br><span class="text-xs font-normal text-gray-400">(if all conditions met)</span></label>
            <div class="flex-1">
                <select id="rule-action" name="rule_action" class="optee-select w-full" required>
                    <?php foreach ( $rule_actions as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
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
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, '7d' ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;" for="rule-interval">Run Every</label>
            <div class="flex-1">
                <select id="rule-interval" name="interval" class="optee-select w-full">
                    <?php foreach ( $intervals as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, 60 ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;">Rule Status</label>
            <div class="flex-1 flex items-center gap-4">
                <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                    <input type="radio" name="enabled" value="1" checked class="text-brand-600 focus:ring-brand-500"> Active
                </label>
                <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                    <input type="radio" name="enabled" value="0" class="text-brand-600 focus:ring-brand-500"> Paused
                </label>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;">If Execution is Successful</label>
            <div class="flex-1 space-y-1.5">
                <label class="flex items-center gap-2 cursor-pointer text-sm">
                    <input type="checkbox" name="on_success_pause_rule" value="1"
                           class="h-4 w-4 rounded border-gray-300 bg-gray-100 text-brand-600 focus:ring-brand-500">
                    Pause this rule
                </label>
                <label class="flex items-center gap-2 cursor-pointer text-sm">
                    <input type="checkbox" name="on_success_pause_campaign" value="1"
                           class="h-4 w-4 rounded border-gray-300 bg-gray-100 text-brand-600 focus:ring-brand-500">
                    Pause this campaign (local + traffic source)
                </label>
            </div>
        </div>

        <div class="optee-form-row">
            <label class="optee-label" style="width:180px;flex-shrink:0;">Save as Template</label>
            <div class="flex-1 flex items-center gap-4">
                <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                    <input type="radio" name="is_template" value="0" checked class="text-brand-600 focus:ring-brand-500"> No
                </label>
                <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                    <input type="radio" name="is_template" value="1" class="text-brand-600 focus:ring-brand-500"> Yes
                </label>
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="optee-btn-primary">Create Rule</button>
            <a href="<?php echo esc_url( $back_url ); ?>" class="optee-btn-secondary">Cancel</a>
            <button type="button" class="optee-btn-secondary optee-dry-run-rule ml-auto">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;margin-right:4px;"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                Dry Run
            </button>
        </div>

        </fieldset>
    </form>
</div>

<script>
window.opteeRuleOperators = <?php echo wp_json_encode( $operators ); ?>;
window.opteeCampaignSources = <?php echo wp_json_encode( $campaign_sources ); ?>;
window.opteeCampaignTsSlugs = <?php echo wp_json_encode( $campaign_ts_slugs ); ?>;
window.opteeCampaignTsReady = <?php echo wp_json_encode( $campaign_ts_ready ); ?>;
window.opteeCampaignCaps = <?php echo wp_json_encode( $campaign_ts_caps ); ?>;
window.opteePresetCampaignId = <?php echo (int) $campaign_id; ?>;
window.opteeVtUrls = <?php echo wp_json_encode( $vt_urls ); ?>;
</script>
