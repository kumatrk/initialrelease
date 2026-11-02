<?php
/**
 * Insights — discover top-performing offers across affiliate networks.
 * Network-wide stats only. Reads from optee_insights_offers (cron-populated).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();

// Mark as visited so the "NEW" badge in the sidebar disappears next render.
if ( ! get_user_meta( $user_id, 'optee_insights_visited', true ) ) {
    update_user_meta( $user_id, 'optee_insights_visited', 1 );
}

$rows = \Optee\Plugin\InsightsService::get_stored( $user_id );

// Build the list of networks to show: only ones whose plugin is installed + active.
// If a user disables Mobipium plugin, the tab disappears entirely.
$registry = \Optee\Plugin\ModuleRegistry::get_instance();
$available_networks = [];
$networks_meta      = [];
foreach ( \Optee\Plugin\InsightsService::NETWORKS as $slug ) {
    $module = $registry->get_module( $slug, 'affiliate_network' );
    if ( ! $module ) {
        continue; // plugin not active — skip
    }
    $available_networks[] = $slug;

    $creds = \Optee\Plugin\ModuleRegistry::get_stored_credentials( 'affiliate_network', $slug );
    $last  = \Optee\Plugin\InsightsService::get_last_run( $user_id, $slug );
    $networks_meta[ $slug ] = [
        'name'            => $module->get_name(),
        'has_credentials' => ! empty( $creds ),
        'last_run'        => $last,
        'last_run_ago'    => $last ? human_time_diff( $last ) . ' ago' : 'Never',
    ];
}

// Sort tabs alphabetically by display name (case-insensitive).
usort( $available_networks, function ( $a, $b ) use ( $networks_meta ) {
    return strcasecmp( $networks_meta[ $a ]['name'], $networks_meta[ $b ]['name'] );
} );

// Convert rows to client-side shape (filtered to only available networks).
$client_rows = [];
foreach ( $rows as $r ) {
    if ( ! in_array( $r['network_slug'], $available_networks, true ) ) {
        continue;
    }
    // Status dot per network:
    //  - Mobipium: live per-offer status string (active / cancelled / paused …) in raw_data.
    //  - Monetizer: derive from the same vauto_paused / outage / is_capped flags the affiliate
    //    network's get_offer_status() uses — they're present on the global aggregate object.
    //  - OGAds: API returns only active offers → green.
    $status = '';
    if ( $r['network_slug'] === 'mobipium' && is_array( $r['raw_data'] ?? null ) ) {
        $status = strtolower( (string) ( $r['raw_data']['status'] ?? '' ) );
    } elseif ( $r['network_slug'] === 'monetizer' && is_array( $r['raw_data']['lifetime'] ?? null ) ) {
        $o = $r['raw_data']['lifetime'];
        if ( intval( $o['vauto_paused'] ?? 0 ) === 1 ) {
            $status = 'paused';
        } elseif ( intval( $o['outage'] ?? 0 ) > 0 ) {
            $status = 'outage';
        } elseif ( intval( $o['is_capped'] ?? 0 ) === 1 ) {
            $status = 'capped';
        } else {
            $status = 'active';
        }
    } elseif ( $r['network_slug'] === 'ogads' ) {
        $status = 'active';
    }

    $client_rows[] = [
        'network_slug'   => $r['network_slug'],
        'offer_id'       => $r['offer_id'],
        'offer_name'     => $r['offer_name'],
        'status'         => $status,
        'payout'         => (float) $r['payout'],
        'geo'            => $r['geo'],
        'vertical'       => $r['vertical'],
        'thumbnail'      => $r['thumbnail'],
        'rank_metric'    => $r['rank_metric'],
        'rank_value'     => (float) $r['rank_value'],
        'rank_display'   => $r['rank_display'],
        'cap_pct'        => $r['cap_pct'] !== null ? (int) $r['cap_pct'] : null,
        'cr_pct'         => $r['cr_pct'] !== null ? (float) $r['cr_pct'] : null,
        'revenue_today'  => (float) $r['revenue_today'],
        'leads_today'    => (int) $r['leads_today'],
        'boosted'        => (bool) $r['boosted'],
    ];
}
?>
<div class="space-y-6">
    <div class="insights-header">
        <div class="insights-header-title">
            <h2 class="text-xl font-bold text-gray-900">Insights</h2>
            <p class="mt-1 text-sm text-gray-500 insights-header-desc">Discover what's converting globally across affiliate networks. Network-wide averages, not your account stats.</p>
        </div>
        <button type="button" id="optee-insights-refresh" class="optee-btn-primary inline-flex items-center gap-1" style="flex-shrink:0;white-space:nowrap;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>
            <span class="insights-refresh-label">Refresh all networks</span>
        </button>
    </div>

    <!-- Filters -->
    <div class="optee-card insights-filters-card">
        <div class="flex flex-wrap items-end gap-3 insights-filters-wrap">
            <div class="flex flex-col gap-1" style="flex:1;min-width:240px;">
                <label class="optee-label-sm">Search</label>
                <input type="text" id="insights-filter-search" class="optee-input" placeholder="Search offer name or ID…">
            </div>
            <div class="flex flex-col gap-1">
                <label class="optee-label-sm">Geo</label>
                <select id="insights-filter-geo" class="optee-input" style="min-width:160px;">
                    <option value="">All geos</option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="optee-label-sm">Vertical</label>
                <select id="insights-filter-vertical" class="optee-input" style="min-width:160px;">
                    <option value="">All verticals</option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="optee-label-sm">Min Payout</label>
                <select id="insights-filter-payout" class="optee-input" style="min-width:140px;">
                    <option value="0">Any</option>
                    <option value="1">$1+</option>
                    <option value="5">$5+</option>
                    <option value="10">$10+</option>
                    <option value="25">$25+</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Tabs + table -->
    <div class="optee-card">
        <?php if ( empty( $available_networks ) ) : ?>
            <div style="padding:48px 24px;text-align:center;color:#6b7280;font-size:14px;">
                <div style="font-weight:600;color:#111827;margin-bottom:6px;">No supported networks active</div>
                <p style="margin:0;">Activate at least one supported affiliate network plugin (Monetizer, Mobipium, or OGAds) to use Insights.</p>
                <p style="margin-top:12px;"><a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'affiliate-networks' ) ); ?>" style="color:#2aa5ff;">Go to Affiliate Networks →</a></p>
            </div>
        <?php else : ?>
        <div class="flex border-b border-gray-200" style="padding:0 12px;">
            <?php foreach ( $available_networks as $i => $slug ) :
                $active_cls = $i === 0 ? ' active' : '';
                $label      = $networks_meta[ $slug ]['name'];
            ?>
                <button type="button" class="insights-tab<?php echo $active_cls; ?>" data-tab="<?php echo esc_attr( $slug ); ?>">
                    <?php echo esc_html( $label ); ?>
                    <span class="insights-tab-count" id="insights-count-<?php echo esc_attr( $slug ); ?>">—</span>
                </button>
            <?php endforeach; ?>
        </div>

        <div id="insights-notices"></div>

        <div class="insights-table-scroll" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
            <table class="insights-table" id="insights-table" style="width:100%;border-collapse:separate;border-spacing:0;font-size:13px;min-width:760px;">
                <thead id="insights-thead"></thead>
                <tbody id="insights-tbody"></tbody>
            </table>
        </div>

        <div class="flex items-center justify-between text-xs text-gray-500" style="padding:12px 18px;border-top:1px solid var(--color-border, #e5e7eb);">
            <div id="insights-network-status" class="flex flex-wrap gap-3"></div>
            <div>
                <span style="font-style:italic;">Numbers are network-wide averages, not your account performance.</span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Refresh progress overlay -->
<div id="insights-refresh-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:12px;padding:24px 28px;min-width:360px;box-shadow:0 20px 50px rgba(0,0,0,0.2);">
        <div style="font-weight:600;font-size:15px;margin-bottom:12px;">Refreshing insights…</div>
        <div id="insights-refresh-progress" style="display:flex;flex-direction:column;gap:8px;font-size:13px;color:#4b5563;">
            <?php foreach ( $available_networks as $slug ) :
                if ( empty( $networks_meta[ $slug ]['has_credentials'] ) ) {
                    continue; // only show networks the user has actually configured
                }
            ?>
            <div data-net="<?php echo esc_attr( $slug ); ?>" class="net-row">⏳ <?php echo esc_html( $networks_meta[ $slug ]['name'] ); ?></div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:14px;font-size:11px;color:#9ca3af;">Fetching all networks in parallel. This usually takes 5–10 seconds.</div>
    </div>
</div>

<style>
.insights-tab {
    padding: 12px 18px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    margin-bottom: -1px;
}
.insights-tab.active { color: #2aa5ff; border-bottom-color: #2aa5ff; }
.insights-tab-count {
    display: inline-block;
    font-size: 11px;
    background: #f3f4f6;
    color: #6b7280;
    padding: 1px 6px;
    border-radius: 8px;
    margin-left: 4px;
}
.insights-tab.active .insights-tab-count { background: #eff6ff; color: #2aa5ff; }
.insights-table thead th {
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #9ca3af;
    padding: 12px 16px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
}
.insights-table thead th.num { text-align: right; }
.insights-table thead th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
.insights-table thead th.sortable:hover { color: #6b7280; }
.insights-table thead th.active-sort { color: #2aa5ff; }
.insights-table thead th.sortable svg { display: inline-block; vertical-align: middle; flex-shrink: 0; }
.insights-table tbody td {
    padding: 12px 16px;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
}
.insights-table tbody tr:last-child td { border-bottom: none; }
.insights-table tbody tr:hover { background: #f9fafb; }
.insights-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
.insights-rank {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px; height: 24px;
    border-radius: 6px;
    font-size: 12px; font-weight: 700;
    color: #6b7280; background: #f3f4f6;
}
.insights-rank.top { background: #eff6ff; color: #1e8acb; }
.optee-label-sm {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #9ca3af;
}

/* Header: title + button on same row, with title block flex-shrinkable */
.insights-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.insights-header-title { min-width: 0; flex: 1; }

/* Filter card: collapse outer .optee-card padding so the inner wrap fully controls spacing */
.insights-filters-card { padding: 0 !important; }
.insights-filters-wrap { padding: 14px 18px; }

/* Mobile-specific tweaks */
@media (max-width: 640px) {
    /* Header: keep title + button in same row, hide descriptive paragraph */
    .insights-header { align-items: center; }
    .insights-header-desc { display: none; }
    /* Compact refresh button: show icon only, hide text label */
    #optee-insights-refresh { padding: 8px 10px; }
    .insights-refresh-label { display: none; }
    /* Smaller filter card padding + tighter spacing */
    .insights-filters-wrap { padding: 10px 12px; gap: 8px; }
    .insights-filters-card .optee-input { padding: 6px 10px; font-size: 13px; }
}
</style>

<script>
(function ($) {
    'use strict';
    var data = <?php echo wp_json_encode( $client_rows ); ?>;
    var networksMeta = <?php echo wp_json_encode( $networks_meta ); ?>;
    var availableNetworks = <?php echo wp_json_encode( $available_networks ); ?>;
    var activeTab = availableNetworks[0] || null;
    var filters = { geo: '', vertical: '', payout: 0, search: '' };
    var sortKey = null, sortDir = null;

    // Bail early if no networks active — empty state was rendered server-side.
    if (!activeTab) {
        return;
    }

    var COLUMNS = {
        id:           { type: 'string',  accessor: function(o){ return o.offer_id; },                                       defaultDir: 'asc'  },
        name:         { type: 'string',  accessor: function(o){ return o.offer_name; },                                     defaultDir: 'asc'  },
        geo:          { type: 'string',  accessor: function(o){ return o.geo; },                                            defaultDir: 'asc'  },
        vertical:     { type: 'string',  accessor: function(o){ return o.vertical; },                                       defaultDir: 'asc'  },
        payout:       { type: 'numeric', accessor: function(o){ return Number(o.payout) || 0; },                            defaultDir: 'desc' },
        cap:          { type: 'numeric', accessor: function(o){ return (o.cap_pct === null || o.cap_pct === undefined) ? null : Number(o.cap_pct); }, defaultDir: 'desc' },
        rev_today:    { type: 'numeric', accessor: function(o){ return Number(o.revenue_today) || 0; },                     defaultDir: 'desc' },
        leads_today:  { type: 'numeric', accessor: function(o){ return Number(o.leads_today) || 0; },                       defaultDir: 'desc' },
        cr_today:     { type: 'numeric', accessor: function(o){ return (o.cr_pct === null || o.cr_pct === undefined) ? null : Number(o.cr_pct); }, defaultDir: 'desc' },
        metric:       { type: 'numeric', accessor: function(o){ return Number(o.rank_value) || 0; },                        defaultDir: 'desc' }
    };

    function tabDefaultSort(tab, rows) {
        if (tab === 'monetizer') return { key: 'rev_today', dir: 'desc' };
        if (tab === 'ogads')     return { key: 'cr_today',  dir: 'desc' };
        var metric = rows[0] ? rows[0].rank_metric : null;
        if (metric === 'last_conv') return { key: 'metric', dir: 'asc' };
        return { key: 'metric', dir: 'desc' };
    }

    function escapeHtml(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function fmtMoney(v){ v=Number(v)||0; if(v>=1e6) return '$'+(v/1e6).toFixed(1)+'M'; if(v>=1000) return '$'+(v/1000).toFixed(1)+'k'; if(v>=100) return '$'+Math.round(v); return '$'+v.toFixed(2); }
    function fmtInt(n){ n=Number(n)||0; if(n>=1e6) return (n/1e6).toFixed(1)+'M'; if(n>=1000) return (n/1000).toFixed(1)+'k'; return String(n); }
    function fmtPayout(p){ return '$'+(p<10 ? p.toFixed(2) : p.toFixed(0)); }
    function fmtCR(o){ var pct=Number(o.cr_pct)||0; if(!pct||!isFinite(pct)||o.cr_pct==null) return '<span style="color:#9ca3af;">—</span>'; return (pct>=10 ? pct.toFixed(1) : pct.toFixed(2))+'%'; }
    function fmtCapPct(v){ if(v==null) return '<span style="color:#9ca3af;">—</span>'; var n=Number(v); var c=n>=80?'#dc2626':(n>=50?'#d97706':'#6b7280'); return '<span style="color:'+c+';font-weight:500;">'+n+'%</span>'; }
    function fmtMetricValue(o){
        if(o.rank_metric==='epc') return '$'+Number(o.rank_value).toFixed(2);
        if(o.rank_metric==='last_conv'){
            var m=Number(o.rank_value);
            if(!isFinite(m)||m<0) return '<span style="color:#9ca3af">—</span>';
            if(m<180) return m+'min ago';
            if(m<1440) return Math.floor(m/60)+'h ago';
            return Math.floor(m/1440)+'d ago';
        }
        return o.rank_value;
    }
    function metricLabel(m){ if(m==='epc') return 'Network EPC'; if(m==='last_conv') return 'Last conv'; return 'Metric'; }

    function geoBadges(geo){
        if(!geo) return '';
        var all = geo.split(',').map(function(g){ return g.trim(); }).filter(Boolean);
        var badge = function(g){ return '<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:#f3f4f6;color:#6b7280;font-size:11px;font-family:monospace;margin-right:2px;">'+escapeHtml(g)+'</span>'; };
        if(all.length<=10) return all.map(badge).join(' ');
        var first = all.slice(0,10).map(badge).join(' ');
        var rest  = all.slice(10).map(badge).join(' ');
        return '<span class="geo-cell" style="display:inline-block;">'+first+
            '<span class="geo-more" hidden>'+rest+'</span>'+
            '<a href="#" class="geo-toggle" data-rest-count="'+(all.length-10)+'" style="color:#2aa5ff;font-size:11px;text-decoration:none;margin-left:4px;cursor:pointer;white-space:nowrap;">+'+(all.length-10)+' more</a>'+
            '</span>';
    }
    function verticalBadge(v){ if(!v) return ''; return '<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#faf5ff;color:#7e22ce;font-size:11px;font-weight:600;">'+escapeHtml(v)+'</span>'; }

    function dashboardOfferUrl(o){
        if(o.network_slug==='mobipium' && o.offer_id) return 'https://affiliates.mobipium.com/campaign/'+encodeURIComponent(o.offer_id);
        return null;
    }
    // Derive a status string from a stored row (mirrors PHP client_rows logic). Used after refresh.
    function deriveStatus(r){
        if(r.network_slug === 'mobipium' && r.raw_data) return String(r.raw_data.status || '').toLowerCase();
        if(r.network_slug === 'monetizer' && r.raw_data && r.raw_data.lifetime){
            var o = r.raw_data.lifetime;
            if(parseInt(o.vauto_paused, 10) === 1) return 'paused';
            if(parseInt(o.outage, 10) > 0)        return 'outage';
            if(parseInt(o.is_capped, 10) === 1)   return 'capped';
            return 'active';
        }
        if(r.network_slug === 'ogads') return 'active';
        return '';
    }

    // Live offer status dot. Green = active, amber = paused/capped, red = outage/cancelled/inactive.
    function statusDot(o){
        var s = (o.status || '').toLowerCase();
        if(!s) return '';
        var color, title;
        if(s === 'active'){ color = '#16a34a'; title = 'Active'; }
        else if(s === 'paused' || s === 'capped'){ color = '#d97706'; title = s.charAt(0).toUpperCase() + s.slice(1); }
        else { color = '#dc2626'; title = s.charAt(0).toUpperCase() + s.slice(1); }
        return '<span title="'+escapeHtml(title)+'" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:'+color+';flex-shrink:0;"></span>';
    }
    function offerNameCell(o){
        var name = escapeHtml(o.offer_name);
        var dot  = statusDot(o);
        var href = dashboardOfferUrl(o);
        if(!href) return '<span style="font-weight:500;display:inline-flex;align-items:center;gap:6px;">'+dot+name+'</span>';
        return '<a href="'+href+'" target="_blank" rel="noopener noreferrer" style="font-weight:500;color:#111827;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" title="Open in network dashboard">'+dot+name+
            ' <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;flex-shrink:0;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>';
    }

    var PLACEHOLDER_THUMB = '<div style="width:40px;height:40px;border-radius:6px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9ca3af;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>';

    function passesFilters(o){
        if(filters.geo && !(o.geo||'').split(',').map(function(g){return g.trim();}).includes(filters.geo)) return false;
        if(filters.vertical && o.vertical !== filters.vertical) return false;
        if(filters.payout && o.payout < filters.payout) return false;
        if(filters.search){
            var q = filters.search.toLowerCase();
            if(!(o.offer_name||'').toLowerCase().includes(q) && !(o.offer_id||'').toLowerCase().includes(q)) return false;
        }
        return true;
    }
    function getFiltered(){
        return data.filter(function(o){ return o.network_slug===activeTab && passesFilters(o); });
    }

    // Format a payout threshold compactly: $0.20+, $1.5+, $25+
    function fmtPayoutThreshold(v){
        if(v < 1)  return '$' + v.toFixed(2) + '+';
        if(v < 10) return '$' + v.toFixed(1).replace(/\.0$/, '') + '+';
        return '$' + Math.round(v) + '+';
    }

    // Compute 4 payout thresholds (quintile boundaries) from current tab's offers.
    // Returns sorted unique values; falls back to a default ladder if range is tiny.
    function computePayoutThresholds(payouts){
        if(!payouts.length) return [];
        var sorted = payouts.slice().sort(function(a,b){ return a-b; });
        var min = sorted[0], max = sorted[sorted.length-1];
        if(max - min < 0.02) return []; // not enough range to bucket meaningfully

        // Use quintile boundaries (20%, 40%, 60%, 80%) — adapts to distribution.
        var quintile = function(p){
            var idx = Math.floor(sorted.length * p);
            return sorted[Math.min(idx, sorted.length-1)];
        };
        var raw = [ quintile(0.2), quintile(0.4), quintile(0.6), quintile(0.8) ];
        // Round to sensible precision
        var rounded = raw.map(function(v){
            if(v < 1)  return Math.round(v * 100) / 100;       // 2 decimals
            if(v < 10) return Math.round(v * 10) / 10;          // 1 decimal
            return Math.round(v);                               // integer
        });
        // Dedupe while preserving order
        var seen = {};
        return rounded.filter(function(v){ if(seen[v]) return false; seen[v]=1; return true; });
    }

    function rebuildFilterDropdowns(){
        var tabData = data.filter(function(o){ return o.network_slug === activeTab; });
        var geoCounts = {}, vertCounts = {};
        var payouts = [];
        tabData.forEach(function(o){
            (o.geo || '').split(',').map(function(g){return g.trim();}).filter(Boolean).forEach(function(g){ geoCounts[g] = (geoCounts[g]||0)+1; });
            if(o.vertical) vertCounts[o.vertical] = (vertCounts[o.vertical]||0)+1;
            if(o.payout > 0) payouts.push(o.payout);
        });
        var sortByCountDesc = function(obj){ return Object.entries(obj).sort(function(a,b){ return b[1]-a[1]; }); };
        var geoSel = document.getElementById('insights-filter-geo');
        var vertSel = document.getElementById('insights-filter-vertical');
        var payoutSel = document.getElementById('insights-filter-payout');
        var prevGeo = geoSel.value, prevVert = vertSel.value, prevPayout = parseFloat(payoutSel.value) || 0;
        geoSel.innerHTML = '<option value="">All geos</option>' + sortByCountDesc(geoCounts).map(function(p){ return '<option value="'+escapeHtml(p[0])+'">'+escapeHtml(p[0])+' ('+p[1]+')</option>'; }).join('');
        vertSel.innerHTML = '<option value="">All verticals</option>' + sortByCountDesc(vertCounts).map(function(p){ return '<option value="'+escapeHtml(p[0])+'">'+escapeHtml(p[0])+' ('+p[1]+')</option>'; }).join('');

        // Dynamic payout thresholds
        var thresholds = computePayoutThresholds(payouts);
        payoutSel.innerHTML = '<option value="0">Any</option>' + thresholds.map(function(v){
            return '<option value="'+v+'">'+fmtPayoutThreshold(v)+'</option>';
        }).join('');

        if(prevGeo && geoCounts[prevGeo]) geoSel.value = prevGeo;
        if(prevVert && vertCounts[prevVert]) vertSel.value = prevVert;
        // Restore payout selection only if it still exists in the new ladder
        if(prevPayout && thresholds.includes(prevPayout)){
            payoutSel.value = prevPayout;
        } else {
            payoutSel.value = '0';
            filters.payout = 0;
        }
    }

    function networkNotice(){
        var meta = networksMeta[activeTab];
        if(!meta) return '';
        if(!meta.has_credentials){
            return '<div style="display:flex;align-items:center;gap:8px;padding:10px 16px;background:#eff6ff;border-bottom:1px solid #bfdbfe;color:#1e40af;font-size:12px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg><strong>Not connected:</strong> Connect this network in <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'affiliate-networks' ) ); ?>" style="color:inherit;font-weight:600;">Affiliate Networks</a> to see its insights.</div>';
        }
        if(meta.last_run === 0){
            return '<div style="display:flex;align-items:center;gap:8px;padding:10px 16px;background:#fefce8;border-bottom:1px solid #fde68a;color:#a16207;font-size:12px;">⏳ <strong>No data yet</strong> — click <em>Refresh all networks</em> to fetch initial data.</div>';
        }
        return '';
    }

    function cmp(a,b,dir){
        var aNull = (a==null) || (typeof a==='number' && !isFinite(a));
        var bNull = (b==null) || (typeof b==='number' && !isFinite(b));
        if(aNull && bNull) return 0;
        if(aNull) return 1; if(bNull) return -1;
        if(typeof a==='number' && typeof b==='number') return dir==='asc' ? a-b : b-a;
        var s = String(a).localeCompare(String(b), undefined, { sensitivity:'base' });
        return dir==='asc' ? s : -s;
    }
    function sortIcon(key){
        if(sortKey!==key) return '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" style="margin-left:4px;"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg>';
        var path = sortDir==='asc' ? '<path d="m7 9 5-5 5 5"/>' : '<path d="m7 15 5 5 5-5"/>';
        return '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left:4px;">'+path+'</svg>';
    }
    function sortableTh(key, label, opts){
        opts = opts || {};
        var cls = 'sortable' + (sortKey===key ? ' active-sort' : '') + (opts.num ? ' num' : '');
        var style = opts.style ? ' style="'+opts.style+'"' : '';
        return '<th class="'+cls+'" data-sort="'+key+'"'+style+'>'+label+sortIcon(key)+'</th>';
    }

    function render(){
        var rows = getFiltered();
        if(!sortKey){ var d=tabDefaultSort(activeTab, rows); sortKey=d.key; sortDir=d.dir; }
        var col = COLUMNS[sortKey];
        var sorted = col ? rows.slice().sort(function(a,b){ return cmp(col.accessor(a), col.accessor(b), sortDir); }) : rows.slice();

        var metricHeader = rows.length ? metricLabel(rows[0].rank_metric) : 'Metric';
        var showRevToday   = activeTab === 'monetizer';
        var showLeadsToday = activeTab === 'monetizer';
        var showCR         = activeTab === 'monetizer' || activeTab === 'ogads';
        var showCap        = activeTab === 'mobipium';

        document.getElementById('insights-thead').innerHTML =
            '<tr>'+
            '<th style="width:48px;">#</th>'+
            '<th style="width:52px;">IMG</th>'+
            sortableTh('id','ID',{style:'width:88px;'})+
            sortableTh('name','Offer')+
            sortableTh('geo','Geo')+
            sortableTh('vertical','Vertical')+
            sortableTh('payout','Payout',{num:true})+
            (showRevToday   ? sortableTh('rev_today','24h Rev',{num:true,style:'width:84px;'}) : '')+
            (showLeadsToday ? sortableTh('leads_today','24h Leads',{num:true,style:'width:84px;'}) : '')+
            (showCR         ? sortableTh('cr_today','CR',{num:true,style:'width:60px;'}) : '')+
            (showCap        ? sortableTh('cap','Cap',{num:true,style:'width:64px;'}) : '')+
            sortableTh('metric',metricHeader,{num:true})+
            '</tr>';

        var colCount = 7 + (showRevToday?1:0) + (showLeadsToday?1:0) + (showCR?1:0) + (showCap?1:0);
        var tbody;
        if(sorted.length === 0){
            tbody = '<tr><td colspan="'+colCount+'" style="padding:50px;text-align:center;color:#9ca3af;font-size:14px;">No offers match the current filters.</td></tr>';
        } else {
            tbody = sorted.map(function(o, i){
                var rankNum = i+1;
                var topClass = rankNum<=3 ? 'top' : '';
                var thumbCell = o.thumbnail
                    ? '<div style="position:relative;width:40px;height:40px;">'+PLACEHOLDER_THUMB.replace('width:40px;height:40px;','position:absolute;inset:0;width:40px;height:40px;')+'<img src="'+escapeHtml(o.thumbnail)+'" alt="" loading="lazy" style="position:relative;width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;" onerror="this.style.display=\'none\'"></div>'
                    : PLACEHOLDER_THUMB;
                var revCell    = showRevToday   ? '<td class="num" style="font-weight:600;color:#1e8acb;">'+(o.network_slug==='monetizer' ? fmtMoney(o.revenue_today) : '<span style="color:#9ca3af;">—</span>')+'</td>' : '';
                var leadsCell  = showLeadsToday ? '<td class="num">'+(o.network_slug==='monetizer' ? fmtInt(o.leads_today) : '<span style="color:#9ca3af;">—</span>')+'</td>' : '';
                var crCell     = showCR         ? '<td class="num">'+fmtCR(o)+'</td>' : '';
                var capCell    = showCap        ? '<td class="num">'+fmtCapPct(o.cap_pct)+'</td>' : '';
                return '<tr>'+
                    '<td><span class="insights-rank '+topClass+'">'+rankNum+'</span></td>'+
                    '<td>'+thumbCell+'</td>'+
                    '<td><span style="font-family:monospace;font-size:12px;color:#6b7280;">'+escapeHtml(o.offer_id)+'</span></td>'+
                    '<td>'+offerNameCell(o)+'</td>'+
                    '<td>'+geoBadges(o.geo)+'</td>'+
                    '<td>'+verticalBadge(o.vertical)+'</td>'+
                    '<td class="num" style="color:#16a34a;font-weight:600;">'+fmtPayout(o.payout)+'</td>'+
                    revCell+leadsCell+crCell+capCell+
                    '<td class="num" style="font-weight:600;color:#1e8acb;">'+fmtMetricValue(o)+'</td>'+
                    '</tr>';
            }).join('');
        }
        document.getElementById('insights-tbody').innerHTML = tbody;
        document.getElementById('insights-notices').innerHTML = networkNotice();

        // Counts
        availableNetworks.forEach(function(slug){
            var n = data.filter(function(o){ return o.network_slug===slug && passesFilters(o); }).length;
            var el = document.getElementById('insights-count-'+slug);
            if(el) el.textContent = n;
        });

        // Per-network status footer (only active networks, matching the tabs)
        var statusBits = availableNetworks.map(function(slug){
            var meta = networksMeta[slug];
            var ago  = meta && meta.last_run ? meta.last_run_ago : 'Never';
            var label = (meta && meta.name) ? meta.name : (slug.charAt(0).toUpperCase()+slug.slice(1));
            var dotColor = meta && meta.last_run && (Date.now()/1000 - meta.last_run < 14400) ? '#16a34a' : '#9ca3af';
            return '<span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:6px;height:6px;border-radius:50%;background:'+dotColor+';"></span>'+escapeHtml(label)+': '+ago+'</span>';
        });
        document.getElementById('insights-network-status').innerHTML = statusBits.join('');
    }

    // Tab clicks
    document.querySelectorAll('.insights-tab').forEach(function(tab){
        tab.addEventListener('click', function(){
            document.querySelectorAll('.insights-tab').forEach(function(t){ t.classList.remove('active'); });
            tab.classList.add('active');
            activeTab = tab.dataset.tab;
            sortKey = null; sortDir = null;
            rebuildFilterDropdowns();
            render();
        });
    });

    // Sort
    document.getElementById('insights-thead').addEventListener('click', function(e){
        var th = e.target.closest('th.sortable'); if(!th) return;
        var key = th.dataset.sort; if(!key) return;
        if(sortKey === key){ sortDir = (sortDir==='asc') ? 'desc' : 'asc'; }
        else { sortKey = key; sortDir = COLUMNS[key].defaultDir; }
        render();
    });

    // Filters
    document.getElementById('insights-filter-search').addEventListener('input',  function(e){ filters.search   = e.target.value.trim(); render(); });
    document.getElementById('insights-filter-geo'   ).addEventListener('change', function(e){ filters.geo      = e.target.value;        render(); });
    document.getElementById('insights-filter-vertical').addEventListener('change', function(e){ filters.vertical = e.target.value;      render(); });
    document.getElementById('insights-filter-payout').addEventListener('change', function(e){ filters.payout   = parseFloat(e.target.value); render(); });

    // Geo expand
    document.getElementById('insights-tbody').addEventListener('click', function(e){
        var toggle = e.target.closest('.geo-toggle'); if(!toggle) return;
        e.preventDefault();
        var cell = toggle.closest('.geo-cell'); if(!cell) return;
        var more = cell.querySelector('.geo-more');
        if(more.hidden){ more.hidden = false; toggle.textContent = 'show less'; }
        else { more.hidden = true; toggle.textContent = '+' + toggle.dataset.restCount + ' more'; }
    });

    // Manual refresh
    document.getElementById('optee-insights-refresh').addEventListener('click', function(){
        var overlay = document.getElementById('insights-refresh-overlay');
        overlay.style.display = 'flex';
        // Reset progress markers
        document.querySelectorAll('#insights-refresh-progress .net-row').forEach(function(row){
            var slug = row.dataset.net;
            row.textContent = '⏳ ' + slug.charAt(0).toUpperCase() + slug.slice(1);
        });

        Optee.Ajax.post('optee_refresh_insights', {}).done(function(res){
            if(res.success){
                data = res.data.offers.map(function(r){
                    return {
                        network_slug: r.network_slug,
                        offer_id:     r.offer_id,
                        offer_name:   r.offer_name,
                        status:       deriveStatus(r),
                        payout:       parseFloat(r.payout) || 0,
                        geo:          r.geo,
                        vertical:     r.vertical,
                        thumbnail:    r.thumbnail,
                        rank_metric:  r.rank_metric,
                        rank_value:   parseFloat(r.rank_value) || 0,
                        rank_display: r.rank_display,
                        cap_pct:      r.cap_pct === null ? null : parseInt(r.cap_pct, 10),
                        cr_pct:       r.cr_pct  === null ? null : parseFloat(r.cr_pct),
                        revenue_today: parseFloat(r.revenue_today) || 0,
                        leads_today:   parseInt(r.leads_today, 10) || 0,
                        boosted:       !!parseInt(r.boosted, 10),
                    };
                });
                // Update networksMeta last_run
                availableNetworks.forEach(function(slug){
                    var meta  = networksMeta[slug];
                    var label = (meta && meta.name) ? meta.name : (slug.charAt(0).toUpperCase() + slug.slice(1));
                    if(res.data.result && res.data.result[slug] && res.data.result[slug].success){
                        meta.last_run = res.data.fetched_at;
                        meta.last_run_ago = 'just now';
                    }
                    // Mark progress row (only present for configured networks)
                    var row = document.querySelector('#insights-refresh-progress .net-row[data-net="'+slug+'"]');
                    if(row){
                        if(res.data.result && res.data.result[slug] && res.data.result[slug].success){
                            row.textContent = '✓ ' + label + ' — ' + (res.data.result[slug].count || 0) + ' offers';
                        } else {
                            row.textContent = '— ' + label + ' (not connected)';
                        }
                    }
                });
                rebuildFilterDropdowns();
                render();
                setTimeout(function(){ overlay.style.display = 'none'; }, 700);
            } else {
                Optee.Toast && Optee.Toast.show(res.data.message || 'Refresh failed.', 'error');
                overlay.style.display = 'none';
            }
        }).fail(function(){
            Optee.Toast && Optee.Toast.show('Network error during refresh.', 'error');
            overlay.style.display = 'none';
        });
    });

    rebuildFilterDropdowns();
    render();
})(jQuery);
</script>
