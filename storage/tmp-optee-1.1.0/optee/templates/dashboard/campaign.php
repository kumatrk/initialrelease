<?php
/**
 * Campaign statistics view.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$campaign_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
$campaign    = $campaign_id ? get_post( $campaign_id ) : null;

if ( ! $campaign || $campaign->post_type !== 'optee_campaign' || (int) $campaign->post_author !== get_current_user_id() ) {
    echo '<div class="optee-card"><p class="text-gray-500">Campaign not found.</p></div>';
    return;
}

$campaign_name       = $campaign->post_title;
$ts_module           = get_post_meta( $campaign_id, '_optee_traffic_source_module', true );
$tracker_module      = get_post_meta( $campaign_id, '_optee_tracker_module', true );
$antifraud_module    = get_post_meta( $campaign_id, '_optee_antifraud_module', true );
$ts_campaign_id      = get_post_meta( $campaign_id, '_optee_traffic_source_campaign_id', true );
$tracker_campaign_id = get_post_meta( $campaign_id, '_optee_tracker_campaign_id', true );
$antifraud_campaign_id = get_post_meta( $campaign_id, '_optee_antifraud_campaign_id', true );
$zone_id             = get_post_meta( $campaign_id, '_optee_zone_id', true );
$zone_name           = get_post_meta( $campaign_id, '_optee_zone_name', true );
$monetag_zone_id     = get_post_meta( $campaign_id, '_optee_monetag_zone_id', true );
$monetag_zone_name   = get_post_meta( $campaign_id, '_optee_monetag_zone_name', true );
$monetag_country     = get_post_meta( $campaign_id, '_optee_monetag_country', true );
$propush_zone_id     = get_post_meta( $campaign_id, '_optee_propush_zone_id', true );
$propush_zone_name   = get_post_meta( $campaign_id, '_optee_propush_zone_name', true );
$propush_site_id     = get_post_meta( $campaign_id, '_optee_propush_site_id', true );
$propush_site_name   = get_post_meta( $campaign_id, '_optee_propush_site_name', true );
$propush_source_id   = get_post_meta( $campaign_id, '_optee_propush_source_id', true );
$propush_country     = get_post_meta( $campaign_id, '_optee_propush_country', true );
$ph_active           = get_post_meta( $campaign_id, '_optee_ph_active', true );
$ph_sub1             = get_post_meta( $campaign_id, '_optee_ph_sub1', true );
$ph_sub2             = get_post_meta( $campaign_id, '_optee_ph_sub2', true );
$ph_sub3             = get_post_meta( $campaign_id, '_optee_ph_sub3', true );
$ph_sub4             = get_post_meta( $campaign_id, '_optee_ph_sub4', true );
$ph_country          = get_post_meta( $campaign_id, '_optee_ph_country', true );
$rap_site_id         = get_post_meta( $campaign_id, '_optee_rap_site_id', true );
$rap_site_name       = get_post_meta( $campaign_id, '_optee_rap_site_name', true );
$rap_zone_id         = get_post_meta( $campaign_id, '_optee_rap_zone_id', true );
$rap_zone_name       = get_post_meta( $campaign_id, '_optee_rap_zone_name', true );
$hta_site_id         = get_post_meta( $campaign_id, '_optee_hta_site_id', true );
$hta_site_name       = get_post_meta( $campaign_id, '_optee_hta_site_name', true );
$hta_zone_id         = get_post_meta( $campaign_id, '_optee_hta_zone_id', true );
$hta_zone_name       = get_post_meta( $campaign_id, '_optee_hta_zone_name', true );
$mtzr_league_id      = get_post_meta( $campaign_id, '_optee_mtzr_league_id', true );
$mtzr_league_name    = get_post_meta( $campaign_id, '_optee_mtzr_league_name', true );
$mtzr_campaign_kw    = get_post_meta( $campaign_id, '_optee_mtzr_campaign_keyword', true );
$zd_request_var      = get_post_meta( $campaign_id, '_optee_zd_request_var', true );
$local_status        = get_post_meta( $campaign_id, '_optee_status', true ) ?: 'active';
$perday_config       = get_post_meta( $campaign_id, '_optee_perday_config', true );
$cron_last           = get_option( 'optee_cron_last_complete', '' );
$rule_count          = count( get_posts( [
    'post_type'      => 'optee_rule',
    'post_status'    => 'publish',
    'author'         => get_current_user_id(),
    'meta_key'       => '_optee_rule_campaign_id',
    'meta_value'     => $campaign_id,
    'posts_per_page' => 1,
    'fields'         => 'ids',
] ) );

// VirusTotal score badges — one per domain this campaign's rules actually watch.
// Nothing shows unless a rule points at a monitored domain, so the badge always
// means "automation is guarding this", never just "a scan exists somewhere".
$vt_badges = \Optee\Plugin\Rules\RuleCondition::campaign_vt_badges( $campaign_id );

// Resolve traffic source URLs.
$ts_edit_url  = '';
$ts_stats_url = '';
if ( $ts_module && $ts_campaign_id ) {
    $ts_obj = $registry->get_module( $ts_module );
    if ( $ts_obj ) {
        if ( method_exists( $ts_obj, 'get_campaign_edit_url' ) ) {
            $ts_edit_url = $ts_obj->get_campaign_edit_url( $ts_campaign_id );
        }
        if ( method_exists( $ts_obj, 'get_campaign_stats_url' ) ) {
            $ts_stats_url = $ts_obj->get_campaign_stats_url( $ts_campaign_id );
        }
    }
}

// Resolve module display names.
$ts_name  = $ts_module ? ( $registry->get_module( $ts_module ) ? $registry->get_module( $ts_module )->get_name() : $ts_module ) : '';
// Whether the traffic source exposes native per-country stats (enables the
// "Countries → By Traffic Source" drilldown so the user can see the exact data
// the remove-countries rule acts on).
$ts_supports_countries = false;
$ts_supports_targeting = false;
if ( $ts_module ) {
    $ts_cap_obj = $registry->get_module( $ts_module );
    $ts_supports_countries = $ts_cap_obj && method_exists( $ts_cap_obj, 'get_country_stats' );
    // Can resolve tracker OS/browser names → PopAds targeting IDs (drives the
    // "PopAds Target" annotation column on the Browsers/OS tabs).
    $ts_supports_targeting = $ts_cap_obj && method_exists( $ts_cap_obj, 'describe_option_matches' );
}

// Tracker-token dimensions the traffic source forwards into tracker custom slots
// (e.g. Category via [CATEGORYID]) — each becomes an extra drilldown showing tracker
// performance keyed by the PopAds targeting value. Registry is extensible.
$mapped_dims = [];
if ( $tracker_module && $tracker_campaign_id && $ts_supports_targeting ) {
    // A dimension's tab shows when it resolves to a tracker slot — honouring the
    // per-campaign mapping override (Campaign Actions → Token Mapping) first.
    foreach ( \Optee\Plugin\AjaxHandler::token_dim_registry() as $dim => $cfg ) {
        if ( \Optee\Plugin\AjaxHandler::resolve_dim_slot( $campaign_id, $dim ) ) {
            $mapped_dims[] = [
                'dim'         => $dim,
                'label'       => $cfg['label'],
                'first_label' => $cfg['first_label'],
                'annotate'    => $cfg['annotate'],
                'ann_label'   => $cfg['ann_label'],
            ];
        }
    }
}
$tr_name  = $tracker_module ? ( $registry->get_module( $tracker_module ) ? $registry->get_module( $tracker_module )->get_name() : $tracker_module ) : '';
$af_name  = $antifraud_module ? ( $registry->get_module( $antifraud_module ) ? $registry->get_module( $antifraud_module )->get_name() : $antifraud_module ) : '';

// Grouped traffic-source campaign (one creative = one source campaign, e.g. Push.House).
$ts_members_json = get_post_meta( $campaign_id, '_optee_ts_campaign_members', true );
$ts_members      = $ts_members_json ? json_decode( $ts_members_json, true ) : [];
if ( ! is_array( $ts_members ) ) {
    $ts_members = [];
}
$is_grouped = ( strpos( (string) $ts_campaign_id, ',' ) !== false ) && count( $ts_members ) > 1;
?>
<div id="optee-campaign-stats"
     class="space-y-4"
     data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>"
     data-ts-module="<?php echo esc_attr( $ts_module ); ?>"
     data-ts-name="<?php echo esc_attr( $ts_name ); ?>"
     data-ts-supports-countries="<?php echo $ts_supports_countries ? '1' : '0'; ?>"
     data-ts-supports-targeting="<?php echo $ts_supports_targeting ? '1' : '0'; ?>"
     data-mapped-dims="<?php echo esc_attr( wp_json_encode( $mapped_dims ) ); ?>"
     data-tracker-module="<?php echo esc_attr( $tracker_module ); ?>"
     data-tracker-name="<?php echo esc_attr( $tr_name ); ?>"
     data-antifraud-module="<?php echo esc_attr( $antifraud_module ); ?>"
     data-antifraud-name="<?php echo esc_attr( $af_name ); ?>"
     data-ts-campaign-id="<?php echo esc_attr( $ts_campaign_id ); ?>"
     data-tracker-campaign-id="<?php echo esc_attr( $tracker_campaign_id ); ?>"
     data-antifraud-campaign-id="<?php echo esc_attr( $antifraud_campaign_id ); ?>"
     data-zone-id="<?php echo esc_attr( $zone_id ); ?>"
     data-zone-name="<?php echo esc_attr( $zone_name ); ?>"
     data-cron-last="<?php echo esc_attr( $cron_last ); ?>"
     data-ts-edit-url="<?php echo esc_attr( $ts_edit_url ); ?>"
     data-ts-stats-url="<?php echo esc_attr( $ts_stats_url ); ?>"
     data-monetag-zone-id="<?php echo esc_attr( $monetag_zone_id ); ?>"
     data-monetag-zone-name="<?php echo esc_attr( $monetag_zone_name ); ?>"
     data-monetag-country="<?php echo esc_attr( $monetag_country ); ?>"
     data-propush-zone-id="<?php echo esc_attr( $propush_zone_id ); ?>"
     data-propush-zone-name="<?php echo esc_attr( $propush_zone_name ); ?>"
     data-propush-site-id="<?php echo esc_attr( $propush_site_id ); ?>"
     data-propush-site-name="<?php echo esc_attr( $propush_site_name ); ?>"
     data-propush-source-id="<?php echo esc_attr( $propush_source_id ); ?>"
     data-propush-country="<?php echo esc_attr( $propush_country ); ?>"
     data-ph-active="<?php echo esc_attr( $ph_active ); ?>"
     data-ph-sub1="<?php echo esc_attr( $ph_sub1 ); ?>"
     data-ph-sub2="<?php echo esc_attr( $ph_sub2 ); ?>"
     data-ph-sub3="<?php echo esc_attr( $ph_sub3 ); ?>"
     data-ph-sub4="<?php echo esc_attr( $ph_sub4 ); ?>"
     data-ph-country="<?php echo esc_attr( $ph_country ); ?>"
     data-rap-site-id="<?php echo esc_attr( $rap_site_id ); ?>"
     data-rap-site-name="<?php echo esc_attr( $rap_site_name ); ?>"
     data-rap-zone-id="<?php echo esc_attr( $rap_zone_id ); ?>"
     data-rap-zone-name="<?php echo esc_attr( $rap_zone_name ); ?>"
     data-hta-site-id="<?php echo esc_attr( $hta_site_id ); ?>"
     data-hta-site-name="<?php echo esc_attr( $hta_site_name ); ?>"
     data-hta-zone-id="<?php echo esc_attr( $hta_zone_id ); ?>"
     data-hta-zone-name="<?php echo esc_attr( $hta_zone_name ); ?>"
     data-mtzr-league-id="<?php echo esc_attr( $mtzr_league_id ); ?>"
     data-mtzr-league-name="<?php echo esc_attr( $mtzr_league_name ); ?>"
     data-mtzr-campaign-keyword="<?php echo esc_attr( $mtzr_campaign_kw ); ?>"
     data-zd-request-var="<?php echo esc_attr( $zd_request_var ); ?>"
     data-local-status="<?php echo esc_attr( $local_status ); ?>"
     data-ts-grouped="<?php echo $is_grouped ? '1' : '0'; ?>"
     data-rule-count="<?php echo esc_attr( $rule_count ); ?>"
     data-perday-config="<?php echo esc_attr( $perday_config ?: '' ); ?>"
     data-edit-url="<?php echo esc_attr( \Optee\Plugin\Shortcode::get_url( 'campaigns', 'edit', [ 'id' => $campaign_id ] ) ); ?>">

    <?php
    // Cog button HTML (reused in two places, shown/hidden via CSS).
    $cog_btn = '<button id="optee-campaign-actions-btn" class="optee-campaign-cog text-gray-400 hover:text-gray-600" style="cursor:pointer;border:none;background:none;padding:4px;" title="Campaign Actions"
            onmouseover="this.querySelector(\'svg\').style.fill=\'#d1d5db\'" onmouseout="this.querySelector(\'svg\').style.fill=\'none\'">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transition:fill 0.15s;"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>';
    ?>
    <!-- Header -->
    <div class="flex items-center justify-between optee-campaign-header">
        <div class="flex items-center gap-3 optee-campaign-header-left">
            <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'campaigns' ) ); ?>"
               class="optee-back-link text-gray-400 hover:text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                <span class="optee-back-label">Back to Campaigns</span>
            </a>
            <h2 id="optee-campaign-title" class="text-xl font-bold text-gray-900"><?php echo esc_html( $campaign_name ); ?></h2>
            <!-- Cog: inline next to title (both desktop and mobile) -->
            <span class="optee-cog-desktop"><?php echo $cog_btn; ?></span>
        </div>
        <div class="flex items-center gap-3">
            <!-- Cog: mobile floated-right variant (unused, kept for fallback) -->
            <span class="optee-cog-mobile" style="display:none;"><?php echo str_replace( 'id="optee-campaign-actions-btn"', 'id="optee-campaign-actions-btn-mob"', $cog_btn ); ?></span>
        </div>
    </div>

    <!-- Notes modal -->
    <div id="optee-notes-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.35);align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:10px;padding:24px;width:600px;max-width:95vw;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div style="font-weight:600;font-size:14px;" id="optee-notes-title">Campaign Notes</div>
                <button id="optee-notes-close" style="background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <!-- Add new note -->
            <div id="optee-note-add-row" style="display:flex;gap:8px;margin-bottom:16px;align-items:stretch;flex-wrap:wrap;">
                <input type="date" id="optee-note-date" class="optee-input" style="width:140px;flex-shrink:0;cursor:pointer;">
                <input type="text" id="optee-note-text" class="optee-input" placeholder="Add a note..." style="flex:1;min-width:130px;" maxlength="500">
                <button id="optee-note-add" class="optee-btn-primary" type="button" style="white-space:nowrap;padding:0 16px;">Add</button>
            </div>
            <style>
            /* On narrow screens, drop the note input to its own full-width line below Date + Add. */
            @media (max-width: 640px) {
                #optee-note-add-row #optee-note-date { order: 1; flex: 1 1 auto; width: auto; }
                #optee-note-add-row #optee-note-add  { order: 2; }
                #optee-note-add-row #optee-note-text { order: 3; flex: 1 1 100%; width: 100%; }
            }
            </style>
            <!-- Notes list -->
            <div id="optee-notes-list" style="overflow-y:auto;overflow-x:hidden;flex:1;min-height:0;">
                <div class="text-center text-gray-400 text-sm py-4">Loading...</div>
            </div>
        </div>
    </div>

    <!-- Meta info -->
    <style>
    .optee-meta-badges > span:empty { display: none; }
    /* On mobile: "Report generated at" takes its own row; all status badges
       (Live data, Active, Payout N/A, etc.) wrap to a new row below it. */
    @media (max-width: 640px) {
        .optee-meta-badges #optee-stats-generated { flex: 1 1 100%; }
        /* Tighten the gap between the title and this meta block. */
        #optee-campaign-stats > .optee-campaign-meta { margin-top: 4px !important; }
        /* Custom Columns is already reachable from the cog menu on mobile — hide the inline button here. */
        #optee-stats-columns { display: none !important; }
    }
    </style>
    <div class="text-xs text-gray-400 optee-campaign-meta" style="line-height:1.6;">
        <div class="optee-meta-badges" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
            <span id="optee-stats-generated">Report generated at: —</span>
            <span id="optee-cache-indicator" style="position:relative;"></span>
            <span id="optee-campaign-status"></span>
<?php
foreach ( $vt_badges as $vtb ) :
    $vt_style = \Optee\Plugin\Rules\RuleCondition::vt_badge_style( $vtb['state'] );
    $vt_title = \Optee\Plugin\Rules\RuleCondition::vt_badge_title( $vtb );
    $vt_label = \Optee\Plugin\Rules\RuleCondition::vt_badge_label( $vtb );
    if ( ! empty( $vtb['rule_title'] ) ) {
        $vt_title .= ' (rule: ' . $vtb['rule_title'] . ')';
    }

    /**
     * Where a VirusTotal badge links to.
     *
     * A badge is already a per-host, reputation-scoped object, so a plugin that
     * owns a richer view of that host can claim the destination. Unfiltered it
     * stays on the VirusTotal page, so installs with no such plugin are
     * unaffected and a deactivated one reverts on its own.
     *
     * @param string $url         Default: the VirusTotal page.
     * @param array  $vtb         The badge — host, state, malicious, rule_id, rule_title.
     * @param int    $campaign_id Campaign the badge is rendered for.
     */
    $vt_url = apply_filters(
        'optee_vt_badge_url',
        \Optee\Plugin\Shortcode::get_url( 'virustotal' ),
        $vtb,
        $campaign_id
    );
?>
            <a href="<?php echo esc_url( $vt_url ); ?>"
               title="<?php echo esc_attr( $vt_title ); ?>"
               style="font-size:11px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:600;padding:2px 7px;border-radius:6px;text-decoration:none;background:<?php echo esc_attr( $vt_style['bg'] ); ?>;color:<?php echo esc_attr( $vt_style['fg'] ); ?>;border:<?php echo esc_attr( $vt_style['border'] ); ?>;">
                <?php echo esc_html( $vtb['host'] ); ?> <?php echo esc_html( $vt_label ); ?>
            </a>
<?php endforeach; ?>
<?php if ( $rule_count === 0 ) : ?>
            <a id="optee-rules-badge" href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'rules', 'create' ) . '&campaign_id=' . $campaign_id ); ?>" target="_blank" class="optee-badge-yellow optee-cache-icon-badge" title="This campaign has no automation rules. Click to add one." style="text-decoration:none;cursor:pointer;">&#9888; 0 rules</a>
<?php endif; ?>
            <span id="optee-stats-suggestion" style="position:relative;"></span>
            <span id="optee-stats-ts-info" style="position:relative;display:flex;gap:8px;"></span>
            <button id="optee-hide-blocked-toggle" type="button" class="optee-hide-blocked-btn" title="Hide rows where Active = off (also hides SUM/AVG totals). View-only — does not change zone status.">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
                <span class="optee-hide-blocked-label">Hide blocked</span>
            </button>
        </div>
    </div>

<?php if ( $is_grouped ) : ?>
    <!-- Grouped campaign: scope report to all creatives or a single one. -->
    <style>.optee-zones-locked .optee-zone-toggle{opacity:.4;cursor:not-allowed;}</style>
    <div class="flex items-center flex-wrap gap-2" style="background:#f0f7ff;border:1px solid #dbeafe;border-radius:8px;padding:8px 12px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2aa5ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>
        <label for="optee-ts-member-select" style="font-size:12px;color:#374151;font-weight:600;"><?php echo esc_html( $ts_name ?: 'Traffic source' ); ?> campaign:</label>
        <select id="optee-ts-member-select" class="optee-select w-auto text-sm" style="min-width:220px;">
            <option value="">All campaigns (aggregated)</option>
            <?php foreach ( $ts_members as $m ) : ?>
                <option value="<?php echo esc_attr( $m['id'] ?? '' ); ?>"><?php echo esc_html( ( $m['name'] ?? '' ) ?: ( $m['id'] ?? '' ) ); ?></option>
            <?php endforeach; ?>
        </select>
        <span id="optee-ts-member-hint" style="font-size:11px;color:#9ca3af;">Select a single campaign to block/unblock zones.</span>
    </div>
<?php endif; ?>

    <!-- Zone drilldown breadcrumb (shown only while a zone is drilled into) -->
    <div id="optee-zone-drill-bar" style="display:none;width:fit-content;max-width:100%;align-items:center;gap:8px;background:#eff6ff;border:1px solid #dbeafe;border-radius:8px;padding:6px 12px;font-size:13px;"></div>

    <!-- Drill-down buttons + Reload + Timeframe -->
    <style>
    /* Desktop: single row — drilldowns scroll horizontally (with arrows) on the left,
       controls stay pinned on the right; never wrap to a second row. */
    .optee-drill-bar { flex-wrap: nowrap; min-width: 0; }
    /* Size the tab strip to its content (not full width) so it stays narrow and
       leaves a gap before the controls; scrolls only when it overflows. */
    .optee-drilldowns-scroll { flex: 0 1 auto; min-width: 0; overflow-x: auto; flex-wrap: nowrap !important; scrollbar-width: none; -ms-overflow-style: none; }
    .optee-drilldowns-scroll::-webkit-scrollbar { display: none; }
    /* Controls pinned right, with a comfortable (not huge) gap from the tabs. */
    .optee-drill-controls { margin-left: auto; padding-left: 1.5rem; }
    /* Mobile keeps the controls pinned right too — only the desktop gap is dropped,
       since the bar there is just [Reports] + controls and the wide padding would
       squeeze them. margin-left:auto only spends free space, so it can't overflow. */
    @media (max-width: 768px) { .optee-drill-controls { padding-left: 0; } }
    .optee-drill-scroll-btn { flex-shrink: 0; display: none; align-items: center; justify-content: center; width: 30px; height: 38px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; color: #6b7280; cursor: pointer; transition: background .15s; }
    .optee-drill-scroll-btn:hover { background: #f9fafb; color: #374151; }
    @media (max-width: 768px) { .optee-drill-scroll-btn { display: none !important; } }
    /* Auto-reload armed. The slow spin is the only always-visible sign the report
       is polling — without it people forget it is on and keep spending API calls.
       Honours reduced-motion, where the tinted border carries the state instead. */
    .optee-drill-btn.optee-auto-reload-on { border-color: #2aa5ff; color: #2aa5ff; }
    .optee-drill-btn.optee-auto-reload-on svg { animation: optee-auto-reload-spin 3s linear infinite; }
    @keyframes optee-auto-reload-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) {
        .optee-drill-btn.optee-auto-reload-on svg { animation: none; }
    }
    </style>
    <div class="flex items-center gap-2 optee-drill-bar">
        <!-- Desktop scroll-left arrow (shown only when the tabs overflow) -->
        <button type="button" id="optee-drill-scroll-left" class="optee-drill-scroll-btn" title="Scroll left">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <!-- Desktop: drilldowns inline (horizontally scrollable) -->
        <div id="optee-stats-drilldowns" class="flex items-center gap-2 optee-drilldowns-desktop optee-drilldowns-scroll"></div>
        <!-- Desktop scroll-right arrow -->
        <button type="button" id="optee-drill-scroll-right" class="optee-drill-scroll-btn" title="Scroll right">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </button>
        <!-- Mobile: "Reports" toggle button (always blue) -->
        <button type="button" id="optee-mob-reports-btn" class="optee-drill-btn active optee-mob-reports-btn" style="display:none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <span id="optee-mob-reports-label">Default</span>
        </button>
        <div class="flex items-center gap-2 optee-drill-controls" style="flex-shrink:0;">
            <button id="optee-stats-columns" class="optee-drill-btn" title="Columns" style="min-height:38px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            </button>
            <button id="optee-stats-reload" class="optee-drill-btn" title="Reload" style="min-height:38px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
            </button>
            <div id="optee-tf" class="optee-tf">
                <button type="button" id="optee-tf-btn" class="optee-select w-auto text-sm optee-tf-btn">
                    <span id="optee-tf-label">Last 7 days</span>
                    <svg class="optee-tf-caret" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div id="optee-tf-menu" class="optee-tf-menu" style="display:none;"></div>
            </div>
        </div>
    </div>

    <!-- Mobile drilldown popup -->
    <div id="optee-mob-reports-popup" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.35);">
        <div style="position:absolute;bottom:0;left:0;right:0;background:#fff;border-radius:16px 16px 0 0;padding:20px;max-height:70vh;overflow-y:auto;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <span style="font-weight:600;font-size:15px;color:#111827;">Select Report</span>
                <button type="button" id="optee-mob-reports-close" style="background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div id="optee-mob-reports-list" class="flex flex-wrap gap-2" style="justify-content:flex-start;"></div>
        </div>
    </div>

    <!-- Data table -->
    <div id="optee-stats-wrapper" style="overflow:auto;">
        <table id="optee-stats-table" class="optee-stats-table">
            <thead></thead>
            <tbody>
                <tr><td colspan="99" class="text-center py-8 text-gray-400">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>
<script>
(function() {
    function resizeStatsWrapper() {
        var w = document.getElementById('optee-stats-wrapper');
        var f = document.getElementById('optee-footer');
        if (!w) return;
        var top = w.getBoundingClientRect().top;
        var footerH = f ? f.offsetHeight : 0;
        var main = document.getElementById('optee-content');
        // Disable outer scroll on this page — only the table scrolls.
        if (main) main.style.overflow = 'hidden';
        w.style.maxHeight = (window.innerHeight - top - footerH) + 'px';
    }
    resizeStatsWrapper();
    window.addEventListener('resize', resizeStatsWrapper);
    // Re-calc after drilldown buttons render.
    setTimeout(resizeStatsWrapper, 500);
})();

// Drilldown bar: horizontal scroll + arrows (desktop) so it stays one row.
(function() {
    var box   = document.getElementById('optee-stats-drilldowns');
    var left  = document.getElementById('optee-drill-scroll-left');
    var right = document.getElementById('optee-drill-scroll-right');
    if (!box || !left || !right) return;

    function update() {
        var overflow = box.scrollWidth > box.clientWidth + 2;
        var mobile   = window.innerWidth <= 768;
        // Both arrows show together whenever the tabs overflow — never toggle one off
        // (that shifts the layout and looks jumpy). Hide both only when everything fits.
        var show = overflow && !mobile;
        left.style.display  = show ? 'flex' : 'none';
        right.style.display = show ? 'flex' : 'none';
        var canL = show && box.scrollLeft > 2;
        var canR = show && (box.scrollLeft + box.clientWidth) < (box.scrollWidth - 2);
        // Dim (not hide) the arrow that can't scroll further, so nothing jumps.
        left.style.opacity  = canL ? '1' : '0.3';
        right.style.opacity = canR ? '1' : '0.3';
        left.style.cursor   = canL ? 'pointer' : 'default';
        right.style.cursor  = canR ? 'pointer' : 'default';
        // Fade the edge that has more tabs, so it looks like it scrolls off (not cut).
        var mask = (canL && canR) ? 'linear-gradient(to right, transparent 0, #000 24px, #000 calc(100% - 24px), transparent 100%)'
                 : canL ? 'linear-gradient(to right, transparent 0, #000 24px)'
                 : canR ? 'linear-gradient(to right, #000 0, #000 calc(100% - 24px), transparent 100%)'
                 : 'none';
        box.style.webkitMaskImage = mask;
        box.style.maskImage = mask;
    }
    function step(dx) {
        box.scrollBy({ left: dx, behavior: 'smooth' });
        setTimeout(update, 350);
    }
    left.addEventListener('click', function() { step(-260); });
    right.addEventListener('click', function() { step(260); });
    box.addEventListener('scroll', update);
    window.addEventListener('resize', update);
    // Drilldowns render asynchronously — re-check when they change, and a few times after load.
    if (window.MutationObserver) { new MutationObserver(update).observe(box, { childList: true }); }
    setTimeout(update, 300);
    setTimeout(update, 900);
})();

// Campaign Notes
(function($){
    var campaignId = $('#optee-campaign-stats').data('campaign-id');
    var editingId = 0;
    // Current scope: '' = campaign-level, otherwise a dimension value (e.g. zone 2225307).
    var scopeDim = '', scopeValue = '';

    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }

    $('#optee-note-date').val(today());

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    var editIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>';
    var delIcon  = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>';

    function notesTable(notes) {
        var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;">';
        html += '<colgroup><col style="width:90px;"><col><col style="width:66px;"></colgroup>';
        html += '<thead><tr style="border-bottom:1px solid #e5e7eb;"><th style="text-align:left;padding:6px 8px;color:#6b7280;font-weight:500;">Date</th><th style="text-align:left;padding:6px 8px;color:#6b7280;font-weight:500;">Note</th><th style="text-align:center;padding:6px 8px;color:#6b7280;font-weight:500;">Actions</th></tr></thead><tbody>';
        for (var i = 0; i < notes.length; i++) {
            var n = notes[i];
            html += '<tr style="border-bottom:1px solid #f3f4f6;">';
            html += '<td style="padding:8px;color:#374151;white-space:nowrap;">' + escHtml(n.date) + '</td>';
            html += '<td style="padding:8px;color:#374151;word-break:break-word;">' + escHtml(n.text) + '</td>';
            html += '<td style="padding:8px;text-align:center;"><div style="display:flex;gap:4px;justify-content:center;">';
            html += '<button class="optee-icon-btn optee-note-edit" data-id="' + n.id + '" data-date="' + escHtml(n.date) + '" data-text="' + escHtml(n.text) + '" style="width:26px;height:26px;" title="Edit">' + editIcon + '</button>';
            html += '<button class="optee-icon-btn optee-note-delete" data-id="' + n.id + '" style="width:26px;height:26px;" title="Delete">' + delIcon + '</button>';
            html += '</div></td></tr>';
        }
        return html + '</tbody></table>';
    }

    function loadNotes() {
        var req = { campaign_id: campaignId };
        if (scopeDim && scopeValue) { req.dim = scopeDim; req.value = scopeValue; }

        Optee.Ajax.post('optee_get_notes', req).done(function(res) {
            if (!res.success) return;
            var notes  = res.data.notes || [];
            var groups = res.data.groups || [];

            // Header badge counts everything on the campaign, scoped notes included.
            var $count = $('#optee-notes-count');
            var total = notes.length;
            for (var g = 0; g < groups.length; g++) { total += groups[g].notes.length; }
            if (total) { $count.text('(' + total + ')').removeClass('hidden'); }
            else { $count.addClass('hidden'); }

            if (!notes.length && !groups.length) {
                $('#optee-notes-list').html('<div class="text-center text-gray-400 text-sm py-4">No notes yet.</div>');
                return;
            }

            var html = '';
            if (notes.length) {
                if (groups.length) {
                    html += '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;margin:0 0 6px;">Campaign</div>';
                }
                html += notesTable(notes);
            }

            // Dimension notes (zones today), grouped under a heading each. Only shown
            // in the unscoped view — a zone's own modal lists just that zone.
            for (var i = 0; i < groups.length; i++) {
                var grp = groups[i];
                html += '<div style="display:flex;align-items:center;gap:6px;margin:16px 0 6px;">';
                html += '<span style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;">' + escHtml(grp.dim) + '</span>';
                html += '<span style="font-family:monospace;font-size:12px;color:#374151;background:#f3f4f6;border-radius:5px;padding:1px 7px;">' + escHtml(grp.value) + '</span>';
                html += '</div>';
                html += notesTable(grp.notes);
            }

            $('#optee-notes-list').html(html);
        });
    }

    // Open modal — exposed globally so the Campaign Actions popup can trigger it.
    // Pass (dim, value) to scope it to a dimension, e.g. ('zone', '2225307').
    window.opteeOpenNotesModal = function(dim, value) {
        editingId  = 0;
        scopeDim   = dim || '';
        scopeValue = value ? String(value) : '';

        var scoped = !!(scopeDim && scopeValue);
        $('#optee-notes-title').text(scoped
            ? (scopeDim.charAt(0).toUpperCase() + scopeDim.slice(1)) + ' ' + scopeValue + ' — Notes'
            : 'Campaign Notes');
        // Scoped notes are auto-stamped with today's date — no picker.
        $('#optee-note-date').val(today()).toggle(!scoped);
        $('#optee-note-text')
            .val('')
            .attr('placeholder', scoped ? 'Add a note for ' + scopeDim + ' ' + scopeValue + '…' : 'Add a note...');
        $('#optee-note-add').text('Add');
        $('#optee-notes-modal').css('display','flex');
        loadNotes();
    };

    // Zone menus live in optee-campaign-stats.js; give them a stable entry point.
    window.OpteeNotes = { open: window.opteeOpenNotesModal };

    // Close modal.
    $('#optee-notes-close').on('click', function() {
        $('#optee-notes-modal').css('display','none');
    });
    $('#optee-notes-modal').on('click', function(e) {
        if (e.target === this) $('#optee-notes-modal').css('display','none');
    });

    // Add / Update note.
    $('#optee-note-add').on('click', function() {
        var text = $.trim($('#optee-note-text').val());
        var date = $('#optee-note-date').val();
        if (!text) { $('#optee-note-text').focus(); return; }
        var $btn = $(this).prop('disabled', true);
        var payload = {
            campaign_id: campaignId,
            text: text,
            // Scoped notes auto-stamp today; the picker is hidden in that mode.
            date: (scopeDim && scopeValue) ? today() : date,
            note_id: editingId
        };
        if (scopeDim && scopeValue) { payload.dim = scopeDim; payload.value = scopeValue; }

        Optee.Ajax.post('optee_save_note', payload).done(function(res) {
            if (res.success) {
                Optee.Toast.show(res.data.message, 'success');
                editingId = 0;
                $('#optee-note-text').val('');
                $('#optee-note-date').val(today());
                $btn.text('Add');
                loadNotes();
                // Refresh the zone dots without a full reload.
                if (window.OpteeStats && window.OpteeStats.refreshNoteCounts) {
                    window.OpteeStats.refreshNoteCounts();
                }
            } else {
                Optee.Toast.show(res.data.message || 'Error', 'error');
            }
        }).always(function() { $btn.prop('disabled', false); });
    });

    // Enter to submit.
    $('#optee-note-text').on('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); $('#optee-note-add').click(); }
    });

    // Edit note.
    $(document).on('click', '.optee-note-edit', function() {
        var $b = $(this);
        editingId = $b.data('id');
        $('#optee-note-date').val($b.data('date'));
        $('#optee-note-text').val($b.data('text')).focus();
        $('#optee-note-add').text('Update');
    });

    // Cancel edit on Escape.
    $('#optee-note-text').on('keydown', function(e) {
        if (e.key === 'Escape' && editingId) {
            editingId = 0;
            $(this).val('');
            $('#optee-note-date').val(today());
            $('#optee-note-add').text('Add');
        }
    });

    // Delete note.
    $(document).on('click', '.optee-note-delete', function() {
        var noteId = $(this).data('id');
        if (!confirm('Delete this note?')) return;
        Optee.Ajax.post('optee_delete_note', {
            campaign_id: campaignId,
            note_id: noteId
        }).done(function(res) {
            if (res.success) {
                Optee.Toast.show('Note deleted.', 'success');
                loadNotes();
                if (window.OpteeStats && window.OpteeStats.refreshNoteCounts) {
                    window.OpteeStats.refreshNoteCounts();
                }
            }
        });
    });

})(jQuery);
</script>
