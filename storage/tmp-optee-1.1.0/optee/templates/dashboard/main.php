<?php
/**
 * Main dashboard view.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();

// Query all non-archived campaigns for this user.
$campaigns_query = new \WP_Query( [
    'post_type'      => 'optee_campaign',
    'post_status'    => 'publish',
    'author'         => $user_id,
    'posts_per_page' => -1,
    'meta_query'     => [
        'relation' => 'OR',
        [
            'key'     => '_optee_status',
            'value'   => 'archived',
            'compare' => '!=',
        ],
        [
            'key'     => '_optee_status',
            'compare' => 'NOT EXISTS',
        ],
    ],
    'orderby' => 'date',
    'order'   => 'DESC',
] );

$campaigns = [];
$active_count = 0;
$paused_count = 0;

// Group campaigns by tracker module for batch fetching.
$tracker_groups = []; // tracker_slug => [ campaign data, ... ]

foreach ( $campaigns_query->posts as $cpost ) {
    $post_id = $cpost->ID;
    $status  = get_post_meta( $post_id, '_optee_status', true ) ?: 'active';

    if ( $status === 'active' ) {
        $active_count++;
    } else {
        $paused_count++;
    }

    $tracker_module      = get_post_meta( $post_id, '_optee_tracker_module', true );
    $tracker_campaign_id = get_post_meta( $post_id, '_optee_tracker_campaign_id', true );
    $ts_module           = get_post_meta( $post_id, '_optee_traffic_source_module', true );

    // Get rules count.
    $rules_count = (int) ( new \WP_Query( [
        'post_type'      => 'optee_rule',
        'post_status'    => 'publish',
        'meta_key'       => '_optee_rule_campaign_id',
        'meta_value'     => $post_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] ) )->found_posts;

    $campaign = [
        'id'                    => $post_id,
        'title'                 => $cpost->post_title,
        'status'                => $status,
        'date'                  => get_the_date( 'Y-m-d', $cpost ),
        'traffic_source_module' => $ts_module,
        'tracker_module'        => $tracker_module,
        'tracker_campaign_id'   => $tracker_campaign_id,
        'ts_campaign_id'        => get_post_meta( $post_id, '_optee_traffic_source_campaign_id', true ),
        'offer_id'              => get_post_meta( $post_id, '_optee_offer_id', true ),
        'rules_count'           => $rules_count,
    ];

    $campaigns[] = $campaign;

    // Group by tracker for batch fetch.
    if ( $tracker_module && $tracker_campaign_id ) {
        $tracker_groups[ $tracker_module ][] = [
            'campaign_id'         => $post_id,
            'tracker_campaign_id' => $tracker_campaign_id,
        ];
    }
}

$total_count = count( $campaigns );

// JSON-encode campaigns for JS.
$campaigns_json       = wp_json_encode( $campaigns );
$tracker_groups_json  = wp_json_encode( $tracker_groups );
?>
<div id="optee-dashboard" class="space-y-4">
    <!-- Header -->
    <div id="optee-dash-header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Dashboard</h2>
                <p class="text-sm text-gray-500 mt-1">Overview of all your campaigns.</p>
            </div>
            <div class="flex items-center gap-2">
                <button id="optee-dash-reload" class="optee-drill-btn" title="Reload" style="min-height:38px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                </button>
                <select id="optee-dash-timeframe" class="optee-select w-auto text-sm">
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="3d">Last 3 days</option>
                    <option value="7d" selected>Last 7 days</option>
                    <option value="14d">Last 14 days</option>
                    <option value="30d">Last 30 days</option>
                    <option value="this_month">This Month</option>
                    <option value="last_month">Last Month</option>
                </select>
            </div>
        </div>
        <div id="optee-dash-cache-indicator" class="mt-1" style="position:relative;"></div>
    </div>

    <!-- Statistics Summary -->
    <div class="optee-card" style="border-left:3px solid #2aa5ff;">
        <div class="flex items-center justify-between mb-3">
            <div class="text-sm font-semibold text-gray-700" id="optee-dash-summary-label">
                Statistics (<?php echo $total_count; ?> Campaign<?php echo $total_count !== 1 ? 's' : ''; ?>: <?php echo $active_count; ?> Active, <?php echo $paused_count; ?> Paused)
            </div>
            <button type="button" id="optee-stats-toggle" class="optee-stats-toggle" title="Show more">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="optee-stats-toggle-icon"><path d="m6 9 6 6 6-6"/></svg>
            </button>
        </div>
        <!-- All metrics in one grid (desktop), split primary/more on mobile -->
        <div id="optee-stats-grid" class="grid gap-4" style="grid-template-columns:repeat(auto-fit, minmax(80px, 1fr));">
            <div class="optee-stat-primary"><div class="text-xl font-bold text-gray-900" id="dash-s-visits">—</div><div class="text-xs text-gray-500">Visits</div></div>
            <div class="optee-stat-primary"><div class="text-xl font-bold text-gray-900" id="dash-s-profit">—</div><div class="text-xs text-gray-500">Profit</div></div>
            <div class="optee-stat-primary"><div class="text-xl font-bold text-gray-900" id="dash-s-roi">—</div><div class="text-xs text-gray-500">ROI</div></div>
            <div class="optee-stat-more"><div class="text-xl font-bold text-gray-900" id="dash-s-clicks">—</div><div class="text-xs text-gray-500">Clicks</div></div>
            <div class="optee-stat-more"><div class="text-xl font-bold text-gray-900" id="dash-s-conv">—</div><div class="text-xs text-gray-500">Conversions</div></div>
            <div class="optee-stat-more"><div class="text-xl font-bold text-gray-900" id="dash-s-revenue">—</div><div class="text-xs text-gray-500">Revenue</div></div>
            <div class="optee-stat-more"><div class="text-xl font-bold text-gray-900" id="dash-s-cost">—</div><div class="text-xs text-gray-500">Cost</div></div>
            <div class="optee-stat-more"><div class="text-xl font-bold text-gray-900" id="dash-s-ctr">—</div><div class="text-xs text-gray-500">CTR</div></div>
            <div class="optee-stat-more"><div class="text-xl font-bold text-gray-900" id="dash-s-cv">—</div><div class="text-xs text-gray-500">CV</div></div>
            <div class="optee-stat-more"><div class="text-xl font-bold text-gray-900" id="dash-s-cpm">—</div><div class="text-xs text-gray-500">CPM</div></div>
            <div class="optee-stat-more"><div class="text-xl font-bold text-gray-900" id="dash-s-cpa">—</div><div class="text-xs text-gray-500">CPA</div></div>
        </div>
    </div>

    <!-- Campaign Table (desktop) -->
    <style>
    #optee-dash-table { table-layout: fixed; }
    #optee-dash-table th, #optee-dash-table td { padding: 8px 6px !important; }
    /* Campaign column wraps to multiple lines; everything else stays nowrap. */
    #optee-dash-table td:nth-child(4) { white-space: normal; word-break: break-word; line-height: 1.35; }
    </style>
    <div id="optee-dash-table-wrap" class="optee-card p-0" style="overflow-x:auto;">
        <table class="optee-table" id="optee-dash-table" style="font-size:13px;">
            <colgroup>
                <col style="width:85px;">  <!-- Status -->
                <col style="width:32px;">  <!-- TS -->
                <col style="width:72px;">  <!-- Offer -->
                <col>                       <!-- Campaign: auto / remainder -->
                <col style="width:58px;">  <!-- Visits -->
                <col style="width:50px;">  <!-- Clicks -->
                <col style="width:50px;">  <!-- Conv. -->
                <col style="width:65px;">  <!-- Rev. -->
                <col style="width:65px;">  <!-- Cost -->
                <col style="width:70px;">  <!-- Profit -->
                <col style="width:50px;">  <!-- CTR -->
                <col style="width:50px;">  <!-- CV -->
                <col style="width:65px;">  <!-- ROI -->
                <col style="width:55px;">  <!-- CPM -->
                <col style="width:55px;">  <!-- CPA -->
                <col style="width:165px;"> <!-- Actions -->
            </colgroup>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>TS</th>
                    <th>Offer</th>
                    <th>Campaign</th>
                    <th style="text-align:right;">Visits</th>
                    <th style="text-align:right;">Clicks</th>
                    <th style="text-align:right;">Conv.</th>
                    <th style="text-align:right;">Rev.</th>
                    <th style="text-align:right;">Cost</th>
                    <th style="text-align:right;">Profit</th>
                    <th style="text-align:right;">CTR</th>
                    <th style="text-align:right;">CV</th>
                    <th style="text-align:right;">ROI</th>
                    <th style="text-align:right;">CPM</th>
                    <th style="text-align:right;">CPA</th>
                    <th style="text-align:right;white-space:nowrap;">Actions</th>
                </tr>
            </thead>
            <tbody id="optee-dash-tbody">
                <?php if ( empty( $campaigns ) ) : ?>
                    <tr><td colspan="16" class="text-center py-8 text-gray-400">No campaigns yet. <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'campaigns', 'create' ) ); ?>" class="text-brand-600">Create one</a>.</td></tr>
                <?php else : ?>
                    <?php foreach ( $campaigns as $c ) : ?>
                    <tr data-campaign-id="<?php echo esc_attr( $c['id'] ); ?>">
                        <td>
                            <?php if ( $c['status'] === 'active' ) : ?>
                                <span class="optee-badge-green">Active</span>
                            <?php else : ?>
                                <span class="optee-badge-yellow">Paused</span>
                            <?php endif; ?>
                        </td>
                        <td class="dash-ts-status">
                            <?php if ( $c['traffic_source_module'] ) : ?>
                                <span class="text-gray-300"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:.4"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></span>
                            <?php else : ?>
                                <span class="text-gray-300">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs text-gray-500"><?php echo esc_html( $c['offer_id'] ?: '—' ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'dashboard', 'campaign', [ 'id' => $c['id'] ] ) ); ?>"
                               class="font-medium text-gray-900 hover:text-brand-600">
                                <?php echo esc_html( $c['title'] ); ?>
                            </a>
                            <?php if ( $c['rules_count'] > 0 ) : ?>
                                <span class="optee-badge-green" style="font-size:10px;padding:1px 6px;margin-left:4px;"><?php echo (int) $c['rules_count']; ?> rule<?php echo $c['rules_count'] > 1 ? 's' : ''; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right text-sm dash-stat" data-field="visits"><span class="optee-skeleton" style="width:40px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right text-sm dash-stat" data-field="clicks"><span class="optee-skeleton" style="width:35px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right text-sm dash-stat" data-field="conv"><span class="optee-skeleton" style="width:30px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right text-sm dash-stat" data-field="revenue"><span class="optee-skeleton" style="width:45px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right text-sm dash-stat" data-field="cost"><span class="optee-skeleton" style="width:45px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right text-sm dash-stat" data-field="profit"><span class="optee-skeleton" style="width:50px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right text-sm dash-stat" data-field="ctr"><span class="optee-skeleton" style="width:35px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right text-sm dash-stat" data-field="cv"><span class="optee-skeleton" style="width:35px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right text-sm dash-stat" data-field="roi"><span class="optee-skeleton" style="width:40px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right text-sm dash-stat" data-field="cpm"><span class="optee-skeleton" style="width:40px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right text-sm dash-stat" data-field="cpa"><span class="optee-skeleton" style="width:40px;height:16px;display:inline-block;border-radius:4px;"></span></td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'campaigns', 'edit', [ 'id' => $c['id'] ] ) ); ?>"
                                   class="optee-icon-btn" data-tip="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>
                                </a>
                                <button class="optee-icon-btn dash-toggle-status" data-id="<?php echo esc_attr( $c['id'] ); ?>" data-status="<?php echo esc_attr( $c['status'] ); ?>"
                                        data-tip="<?php echo $c['status'] === 'active' ? 'Pause' : 'Resume'; ?>">
                                    <?php if ( $c['status'] === 'active' ) : ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg>
                                    <?php else : ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>
                                    <?php endif; ?>
                                </button>
                                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'dashboard', 'campaign', [ 'id' => $c['id'] ] ) ); ?>"
                                   class="optee-icon-btn" data-tip="Stats">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                                </a>
                                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'rules', '', [ 'campaign_id' => $c['id'] ] ) ); ?>"
                                   class="optee-icon-btn" data-tip="Rules">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                </a>
                                <button class="optee-icon-btn dash-remove" data-id="<?php echo esc_attr( $c['id'] ); ?>" data-status="<?php echo esc_attr( $c['status'] ); ?>"
                                        data-tip="Remove">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Campaign Cards (mobile only) -->
    <div id="optee-dash-cards">
        <?php if ( empty( $campaigns ) ) : ?>
            <div class="optee-card text-center py-8 text-gray-400">No campaigns yet. <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'campaigns', 'create' ) ); ?>" class="text-brand-600">Create one</a>.</div>
        <?php else : ?>
            <?php foreach ( $campaigns as $c ) : ?>
            <div class="optee-card optee-mob-card" data-campaign-id="<?php echo esc_attr( $c['id'] ); ?>" style="padding:14px 16px;">
                <!-- Row 1: Status, TS, Offer -->
                <div class="flex items-center gap-2" style="font-size:13px;">
                    <?php if ( $c['status'] === 'active' ) : ?>
                        <span class="optee-badge-green">Active</span>
                    <?php else : ?>
                        <span class="optee-badge-yellow">Paused</span>
                    <?php endif; ?>
                    <span class="dash-ts-status">
                        <?php if ( $c['traffic_source_module'] ) : ?>
                            <span class="text-gray-300"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:.4"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></span>
                        <?php else : ?>
                            <span class="text-gray-300">—</span>
                        <?php endif; ?>
                    </span>
                    <span class="text-gray-500"><?php echo esc_html( $c['offer_id'] ?: '—' ); ?></span>
                    <?php if ( $c['rules_count'] > 0 ) : ?>
                        <span class="optee-badge-green" style="font-size:10px;padding:1px 6px;"><?php echo (int) $c['rules_count']; ?> rule<?php echo $c['rules_count'] > 1 ? 's' : ''; ?></span>
                    <?php endif; ?>
                </div>
                <!-- Row 2: Campaign title -->
                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'dashboard', 'campaign', [ 'id' => $c['id'] ] ) ); ?>"
                   class="block font-semibold text-gray-900 hover:text-brand-600 mt-2" style="font-size:17px;">
                    <?php echo esc_html( $c['title'] ); ?>
                </a>
                <!-- Row 3: Stats (collapsible) -->
                <div class="mt-3">
                    <div class="grid" style="grid-template-columns:repeat(3,1fr);gap:8px 12px;">
                        <div class="dash-stat" data-field="visits"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">Visits</span></div>
                        <div class="dash-stat" data-field="profit"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">Profit</span></div>
                        <div class="dash-stat" data-field="roi"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">ROI</span></div>
                    </div>
                    <div class="optee-mob-stats-more grid" style="grid-template-columns:repeat(3,1fr);gap:8px 12px;display:none;margin-top:8px;">
                        <div class="dash-stat" data-field="clicks"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">Clicks</span></div>
                        <div class="dash-stat" data-field="conv"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">Conv</span></div>
                        <div class="dash-stat" data-field="revenue"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">Rev</span></div>
                        <div class="dash-stat" data-field="cost"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">Cost</span></div>
                        <div class="dash-stat" data-field="ctr"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">CTR</span></div>
                        <div class="dash-stat" data-field="cv"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">CV</span></div>
                        <div class="dash-stat" data-field="cpm"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">CPM</span></div>
                        <div class="dash-stat" data-field="cpa"><span class="font-bold text-gray-900">—</span> <span class="text-gray-400">CPA</span></div>
                    </div>
                    <button type="button" class="optee-mob-stats-toggle" style="background:none;border:none;color:#9ca3af;font-size:12px;padding:6px 0 2px;cursor:pointer;display:flex;align-items:center;gap:3px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="optee-mob-stats-chevron"><path d="m6 9 6 6 6-6"/></svg>
                        <span class="optee-mob-stats-label">More stats</span>
                    </button>
                </div>
                <!-- Row 4: Actions (hidden behind button) -->
                <div style="margin-top:16px;padding-top:10px;border-top:1px solid #e5e7eb;">
                    <button type="button" class="optee-mob-actions-toggle" style="background:none;border:1px solid #e5e7eb;border-radius:6px;color:#6b7280;font-size:12px;font-weight:500;padding:6px 10px;cursor:pointer;width:100%;">
                        Show Actions
                    </button>
                    <div class="optee-mob-actions flex flex-wrap items-center gap-2 mt-2" style="display:none;">
                        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'campaigns', 'edit', [ 'id' => $c['id'] ] ) ); ?>"
                           class="optee-mob-action-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>
                            Edit
                        </a>
                        <button class="optee-mob-action-btn dash-toggle-status" data-id="<?php echo esc_attr( $c['id'] ); ?>" data-status="<?php echo esc_attr( $c['status'] ); ?>">
                            <?php if ( $c['status'] === 'active' ) : ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg>
                                Pause
                            <?php else : ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>
                                Resume
                            <?php endif; ?>
                        </button>
                        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'dashboard', 'campaign', [ 'id' => $c['id'] ] ) ); ?>"
                           class="optee-mob-action-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            Stats
                        </a>
                        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'rules', '', [ 'campaign_id' => $c['id'] ] ) ); ?>"
                           class="optee-mob-action-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            Rules
                        </a>
                        <button class="optee-mob-action-btn dash-remove" data-id="<?php echo esc_attr( $c['id'] ); ?>" data-status="<?php echo esc_attr( $c['status'] ); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                            Remove
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.optee-skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: optee-shimmer 1.5s infinite;
}
@keyframes optee-shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
.dash-remove-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0,0,0,.35);
    display: flex;
    align-items: center;
    justify-content: center;
}
.dash-remove-popup {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,.18);
    padding: 20px 24px;
    width: 380px;
    max-width: 90vw;
    position: relative;
}
.dash-remove-popup p { margin: 0; }
.dash-remove-close {
    position: absolute;
    top: 12px;
    right: 14px;
    background: none;
    border: none;
    cursor: pointer;
    color: #9ca3af;
    padding: 0;
    line-height: 1;
}
.dash-remove-close:hover { color: #374151; }
</style>

<script>
jQuery(function($) {
    // Stats collapse toggle (mobile only — summary widget).
    $('#optee-stats-toggle').on('click', function() {
        $(this).toggleClass('is-open');
        $('#optee-stats-grid').toggleClass('is-open');
    });

    // Mobile campaign card: stats expand toggle is handled globally in layout.php.

    var campaigns      = <?php echo $campaigns_json; ?>;
    var trackerGroups  = <?php echo $tracker_groups_json; ?>;

    // Per-campaign stats keyed by local campaign ID.
    var campaignStats = {};

    // Cache tracking.
    var cacheStatus = {}; // Merged cache_status from all campaign responses.
    var cacheTtl    = 0;  // TTL in minutes from response.
    var alwaysLive  = false;

    // Read state from URL params.
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('live') === '1') alwaysLive = true;
    if (urlParams.get('timeframe')) {
        var tf = urlParams.get('timeframe');
        if ($('#optee-dash-timeframe option[value="' + tf + '"]').length) {
            $('#optee-dash-timeframe').val(tf);
        }
    }

    function fmt(n, decimals) {
        if (n === undefined || n === null) return '0';
        decimals = decimals !== undefined ? decimals : 2;
        return parseFloat(n).toFixed(decimals);
    }

    function fmtMoney(n) {
        if (n === undefined || n === null) return '$0.00';
        var v = parseFloat(n);
        var prefix = v < 0 ? '-$' : '$';
        return prefix + Math.abs(v).toFixed(2);
    }

    function fmtPct(n) {
        if (n === undefined || n === null) return '0.00%';
        return parseFloat(n).toFixed(2) + '%';
    }

    function fmtInt(n) {
        if (n === undefined || n === null) return '0';
        return parseInt(n, 10).toLocaleString();
    }

    function renderCampaignStats() {
        // Update each row (table) + card (mobile).
        $('[data-campaign-id]').each(function() {
            var $row = $(this);
            var cid  = $row.data('campaign-id');
            var s    = campaignStats[cid];

            if (!s) {
                $row.find('.dash-stat').each(function() {
                    var $el = $(this);
                    // Mobile cards: set first span text. Table: set td text.
                    if ($el.is('td')) { $el.text('—'); }
                    else { $el.find('span:first').text('—'); }
                });
                return;
            }

            function setField(field, val, color) {
                $row.find('[data-field="' + field + '"]').each(function() {
                    var $el = $(this);
                    if ($el.is('td')) {
                        $el.text(val);
                        if (color !== undefined) $el.css('color', color);
                    } else {
                        $el.find('span:first').text(val);
                        if (color !== undefined) $el.find('span:first').css('color', color);
                    }
                });
            }

            setField('visits', fmtInt(s.visits));
            setField('clicks', fmtInt(s.clicks));
            setField('conv', fmtInt(s.conv));
            setField('revenue', fmtMoney(s.revenue));
            setField('cost', fmtMoney(s.cost));

            var profit = (parseFloat(s.revenue) || 0) - (parseFloat(s.cost) || 0);
            var profitColor = profit < 0 ? '#ef4444' : (profit > 0 ? '#16a34a' : '');
            setField('profit', fmtMoney(profit), profitColor);

            setField('ctr', fmtPct(s.ctr));
            setField('cv', fmtPct(s.cv));
            var roi = s.roi !== undefined ? s.roi : ((parseFloat(s.cost) || 0) >= 0.01 ? (profit / parseFloat(s.cost) * 100) : 0);
            var roiColor = roi < 0 ? '#ef4444' : (roi > 0 ? '#16a34a' : '');
            setField('roi', fmtPct(roi), roiColor);
            setField('cpm', fmtMoney(s.cpm));
            setField('cpa', fmtMoney(s.cpa));
        });

        updateSummary();
    }

    function updateSummary() {
        var totals = { visits: 0, clicks: 0, conv: 0, cost: 0, revenue: 0 };

        for (var cid in campaignStats) {
            var s = campaignStats[cid];
            if (!s) continue;
            totals.visits  += parseInt(s.visits) || 0;
            totals.clicks  += parseInt(s.clicks) || 0;
            totals.conv    += parseInt(s.conv) || 0;
            totals.cost    += parseFloat(s.cost) || 0;
            totals.revenue += parseFloat(s.revenue) || 0;
        }

        var profit = totals.revenue - totals.cost;
        var ctr    = totals.visits > 0 ? (totals.clicks / totals.visits * 100) : 0;
        var roi    = totals.cost >= 0.01 ? (profit / totals.cost * 100) : 0;
        var cv     = totals.visits > 0 ? (totals.conv / totals.visits * 100) : 0;
        var cpm    = totals.visits > 0 ? (totals.cost / totals.visits * 1000) : 0;
        var cpa    = totals.conv > 0 ? (totals.cost / totals.conv) : 0;

        $('#dash-s-visits').text(fmtInt(totals.visits));
        $('#dash-s-clicks').text(fmtInt(totals.clicks));
        $('#dash-s-conv').text(fmtInt(totals.conv));
        $('#dash-s-cost').text(fmtMoney(totals.cost));
        $('#dash-s-revenue').text(fmtMoney(totals.revenue));

        var $profit = $('#dash-s-profit');
        $profit.text(fmtMoney(profit));
        if (profit < 0) $profit.css('color', '#ef4444');
        else if (profit > 0) $profit.css('color', '#16a34a');
        else $profit.css('color', '');

        $('#dash-s-ctr').text(fmtPct(ctr));

        var $roiSum = $('#dash-s-roi');
        $roiSum.text(fmtPct(roi));
        if (roi < 0) $roiSum.css('color', '#ef4444');
        else if (roi > 0) $roiSum.css('color', '#16a34a');
        else $roiSum.css('color', '');
        $('#dash-s-cv').text(fmtPct(cv));
        $('#dash-s-cpm').text(fmtMoney(cpm));
        $('#dash-s-cpa').text(fmtMoney(cpa));
    }

    function loadStats(forceRefresh) {
        // Reset skeletons.
        $('#optee-dash-tbody .dash-stat').each(function() {
            $(this).html('<span class="optee-skeleton" style="width:40px;height:16px;display:inline-block;border-radius:4px;"></span>');
        });
        // Reset summary.
        $('#optee-dashboard [id^="dash-s-"]').text('—');

        // Reset cache tracking.
        cacheStatus = {};

        var timeframe = $('#optee-dash-timeframe').val();

        // Collect campaign IDs that have a tracker.
        var ids = [];
        campaigns.forEach(function(c) {
            if (c.tracker_module && c.tracker_campaign_id) {
                ids.push(c.id);
            } else {
                campaignStats[c.id] = null;
            }
        });

        if (!ids.length) {
            renderCampaignStats();
            updateDashCacheIndicator();
            return;
        }

        var params = {
            campaign_ids: ids,
            timeframe: timeframe
        };
        if (forceRefresh || alwaysLive) params.force_refresh = 1;

        Optee.Ajax.post('optee_get_dashboard_stats', params).done(function(res) {
            if (res.success && res.data) {
                var data = res.data.campaigns || {};
                for (var cid in data) {
                    if (!data[cid]) {
                        campaignStats[cid] = null;
                        continue;
                    }
                    var s = data[cid];
                    var agg = {
                        visits:  s.visits || 0,
                        clicks:  s.clicks || 0,
                        conv:    s.conv || 0,
                        cost:    s.cost || 0,
                        revenue: s.revenue || 0
                    };
                    agg.ctr = agg.visits > 0 ? (agg.clicks / agg.visits * 100) : 0;
                    agg.cv  = agg.visits > 0 ? (agg.conv / agg.visits * 100) : 0;
                    agg.roi = agg.cost >= 0.01 ? ((agg.revenue - agg.cost) / agg.cost * 100) : 0;
                    agg.cpm = agg.visits > 0 ? (agg.cost / agg.visits * 1000) : 0;
                    agg.cpa = agg.conv > 0 ? (agg.cost / agg.conv) : 0;
                    campaignStats[cid] = agg;
                }
                cacheStatus = res.data.cache_status || {};
                cacheTtl = res.data.cache_ttl || 0;

                if (res.data.module_errors && res.data.module_errors.length) {
                    res.data.module_errors.forEach(function(err) {
                        Optee.Toast.show(err.module + ': ' + err.message, 'error');
                    });
                }
            }
        }).fail(function() {
            campaigns.forEach(function(c) {
                campaignStats[c.id] = null;
            });
        }).always(function() {
            renderCampaignStats();
            updateDashCacheIndicator();
        });
    }

    function updateDashCacheIndicator() {
        var $el = $('#optee-dash-cache-indicator');
        if (!$el.length) return;

        var liveIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20h.01"/><path d="M7 20v-4"/><path d="M12 20v-8"/><path d="M17 20V8"/><path d="M22 4v16"/></svg>';
        var cachedIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';

        var slugs = Object.keys(cacheStatus);
        if ((!slugs.length || cacheTtl === 0) && !alwaysLive) {
            $el.html('<span class="optee-badge-green optee-cache-icon-badge">' + liveIcon + ' Live data</span>');
            return;
        }

        var allLive = true;
        var anyCached = false;
        for (var i = 0; i < slugs.length; i++) {
            if (cacheStatus[slugs[i]].cached) {
                anyCached = true;
                allLive = false;
            }
        }

        if (allLive && !alwaysLive) {
            $el.html('<span class="optee-badge-green optee-cache-icon-badge">' + liveIcon + ' Live data</span>');
            return;
        }

        // When alwaysLive is on and all live, show live badge with popover.
        if (allLive && alwaysLive) {
            var html = '<span class="optee-dash-cache-badge optee-badge-green optee-cache-icon-badge" style="cursor:pointer;">' + liveIcon + ' Live data</span>';
            html += '<div id="optee-dash-cache-popover" class="optee-cache-popover" style="display:none;">';
            html += '<label style="display:block;font-size:11px;color:#6b7280;cursor:pointer;"><input type="checkbox" id="optee-dash-always-live" checked style="margin-right:4px;"> Always load live data</label>';
            html += '</div>';
            $el.html(html);
            return;
        }

        var label = slugs.length === 1 ? 'Cached data' : (anyCached && !allLive ? 'Partially cached' : 'Cached data');
        var html = '<span class="optee-dash-cache-badge optee-badge-yellow optee-cache-icon-badge" style="cursor:pointer;">' + cachedIcon + ' ' + label + '</span>';

        html += '<div id="optee-dash-cache-popover" class="optee-cache-popover" style="display:none;">';
        html += '<div style="font-weight:600;font-size:12px;margin-bottom:6px;">Cache status (TTL: ' + cacheTtl + ' min)</div>';
        for (var j = 0; j < slugs.length; j++) {
            var s = cacheStatus[slugs[j]];
            var dotColor = s.cached ? '#eab308' : '#22c55e';
            var statusText = s.cached ? 'Cached' : 'Live';
            html += '<div class="optee-cache-detail">' +
                '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + dotColor + ';margin-right:6px;"></span>' +
                (s.module_name || slugs[j]) +
                '<span style="margin-left:auto;color:#6b7280;font-size:11px;">' + statusText + '</span>' +
                '</div>';
        }
        html += '<a href="#" id="optee-dash-clear-cache" style="display:block;margin-top:8px;font-size:11px;color:#2aa5ff;text-decoration:none;">Clear cache &amp; reload</a>';
        var alwaysChecked = alwaysLive ? ' checked' : '';
        html += '<label style="display:block;margin-top:6px;font-size:11px;color:#6b7280;cursor:pointer;"><input type="checkbox" id="optee-dash-always-live"' + alwaysChecked + ' style="margin-right:4px;"> Always load live data</label>';
        html += '</div>';

        $el.html(html);
    }

    function pushDashUrl() {
        var params = new URLSearchParams(window.location.search);
        params.set('module', 'dashboard');
        var tf = $('#optee-dash-timeframe').val();
        if (tf && tf !== '7d') params.set('timeframe', tf);
        else params.delete('timeframe');
        if (alwaysLive) params.set('live', '1');
        else params.delete('live');
        history.replaceState(null, '', window.location.pathname + '?' + params.toString());
    }

    // Toggle cache popover.
    $(document).on('click', '.optee-dash-cache-badge', function(e) {
        e.stopPropagation();
        $('#optee-dash-cache-popover').toggle();
    });

    // Close popover on outside click.
    $(document).on('click', function() {
        $('#optee-dash-cache-popover').hide();
    });

    // Prevent popover close when clicking inside.
    $(document).on('click', '#optee-dash-cache-popover', function(e) {
        e.stopPropagation();
    });

    // Clear cache & reload.
    $(document).on('click', '#optee-dash-clear-cache', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('#optee-dash-cache-popover').hide();
        loadStats(true);
    });

    // Always live checkbox.
    $(document).on('change', '#optee-dash-always-live', function() {
        alwaysLive = $(this).is(':checked');
        pushDashUrl();
        if (alwaysLive) loadStats(true);
    });

    // Timeframe change.
    $('#optee-dash-timeframe').on('change', function() {
        pushDashUrl();
        loadStats(false);
    });

    // Reload (uses cache if available).
    $('#optee-dash-reload').on('click', function() {
        loadStats(false);
    });

    // Toggle status.
    $(document).on('click', '.dash-toggle-status', function() {
        var $btn = $(this);
        var cid  = $btn.data('id');
        var current = $btn.data('status');
        var next = current === 'active' ? 'paused' : 'active';

        Optee.Ajax.post('optee_update_campaign', {
            campaign_id: cid,
            status: next
        }).done(function(res) {
            if (res.success) {
                Optee.Toast.show('Campaign ' + (next === 'active' ? 'resumed' : 'paused'), 'success');
                setTimeout(function() { location.reload(); }, 600);
            } else {
                Optee.Toast.show(res.data.message || 'Failed', 'error');
            }
        });
    });

    // Remove from dashboard — custom popup.
    $(document).on('click', '.dash-remove', function(e) {
        e.stopPropagation();
        var $btn    = $(this);
        var cid     = $btn.data('id');
        var status  = $btn.data('status');

        // Close any existing popup.
        $('.dash-remove-overlay').remove();

        var closeBtn = '<button class="dash-remove-close" title="Close"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>';

        var html = '<div class="dash-remove-overlay"><div class="dash-remove-popup">';
        html += closeBtn;
        html += '<div style="font-weight:600;font-size:14px;margin-bottom:10px;">Remove from dashboard</div>';

        if (status === 'active') {
            html += '<p style="font-size:13px;color:#6b7280;margin-bottom:14px;">This campaign is <strong>active</strong>. Active campaigns with rules will still run during automation — they just won\'t appear on the dashboard.</p>';
            html += '<div style="display:flex;flex-direction:column;gap:8px;">';
            html += '<button class="optee-btn-primary optee-btn-sm dash-remove-action" data-id="' + cid + '" data-pause="1" style="font-size:13px;">Pause &amp; remove from dashboard</button>';
            html += '<button class="optee-btn-secondary optee-btn-sm dash-remove-action" data-id="' + cid + '" data-pause="0" style="font-size:13px;">Just remove from dashboard</button>';
            html += '</div>';
        } else {
            html += '<p style="font-size:13px;color:#6b7280;margin-bottom:14px;">Remove this campaign from the dashboard?</p>';
            html += '<div style="display:flex;gap:8px;">';
            html += '<button class="optee-btn-primary optee-btn-sm dash-remove-action" data-id="' + cid + '" data-pause="0" style="font-size:13px;">Remove</button>';
            html += '<button class="optee-btn-secondary optee-btn-sm dash-remove-cancel" style="font-size:13px;">Cancel</button>';
            html += '</div>';
        }
        html += '</div></div>';

        $('body').append(html);
    });

    // Close on overlay click (outside popup).
    $(document).on('click', '.dash-remove-overlay', function(e) {
        if ($(e.target).hasClass('dash-remove-overlay')) {
            $(this).remove();
        }
    });

    // Close button & cancel.
    $(document).on('click', '.dash-remove-close, .dash-remove-cancel', function() {
        $('.dash-remove-overlay').remove();
    });

    // Execute remove action.
    $(document).on('click', '.dash-remove-action', function() {
        var $action = $(this);
        var cid     = $action.data('id');
        var pause   = $action.data('pause');
        $action.prop('disabled', true);

        // If pause requested, first pause then archive.
        var doPause = pause == 1;

        function archiveCampaign() {
            Optee.Ajax.post('optee_update_campaign', {
                campaign_id: cid,
                status: 'archived'
            }).done(function(res) {
                if (res.success) {
                    Optee.Toast.show('Campaign removed from dashboard', 'success');
                    $('tr[data-campaign-id="' + cid + '"]').fadeOut(300, function() { $(this).remove(); });
                } else {
                    Optee.Toast.show(res.data.message || 'Failed', 'error');
                }
                $('.dash-remove-overlay').remove();
            });
        }

        if (doPause) {
            Optee.Ajax.post('optee_update_campaign', {
                campaign_id: cid,
                status: 'paused'
            }).done(function(res) {
                if (res.success) {
                    archiveCampaign();
                } else {
                    Optee.Toast.show(res.data.message || 'Failed to pause', 'error');
                    $action.prop('disabled', false);
                }
            });
        } else {
            archiveCampaign();
        }
    });

    // TS status icons.
    var tsPlayIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>';
    var tsPauseIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg>';
    var tsAlertIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';

    function loadTsStatuses() {
        // Collect campaign IDs that have a traffic source.
        var ids = [];
        campaigns.forEach(function(c) {
            if (c.traffic_source_module && c.ts_campaign_id) ids.push(c.id);
        });
        if (!ids.length) return;

        Optee.Ajax.post('optee_get_ts_statuses', { campaign_ids: ids }).done(function(res) {
            if (!res.success || !res.data.statuses) return;
            var statuses = res.data.statuses;
            for (var cid in statuses) {
                var $cell = $('[data-campaign-id="' + cid + '"] .dash-ts-status');
                if (!$cell.length) continue;
                var st = statuses[cid];
                if (st === 'active' || st === 'running') {
                    $cell.html('<span data-tip="Running on traffic source">' + tsPlayIcon + '</span>');
                } else if (st === 'paused' || st === 'stopped' || st === 'inactive') {
                    $cell.html('<span data-tip="Paused on traffic source">' + tsPauseIcon + '</span>');
                } else if (st === 'error') {
                    $cell.html('<span class="text-gray-300" data-tip="Could not fetch status">—</span>');
                } else {
                    $cell.html('<span data-tip="Status: ' + Optee.Util.escapeHtml(st) + '">' + tsAlertIcon + '</span>');
                }
            }
        });
    }

    // Initial load.
    loadStats(false);
    loadTsStatuses();
});
</script>
