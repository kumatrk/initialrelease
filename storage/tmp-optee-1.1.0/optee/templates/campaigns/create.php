<?php
/**
 * Campaign create form.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$traffic_sources   = $registry->get_modules_by_type( 'traffic_source' );
$trackers          = $registry->get_modules_by_type( 'tracker' );
$networks          = $registry->get_modules_by_type( 'affiliate_network' );
$antifraud         = $registry->get_modules_by_type( 'antifraud_tool' );
?>
<div class="space-y-6 optee-form-page" style="max-width:min(672px, 60%);min-width:480px;">
    <div>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'campaigns' ) ); ?>"
           class="text-sm text-gray-500 hover:text-gray-900 transition-colors">&larr; Back to Campaigns</a>
        <h2 class="mt-2 text-xl font-bold text-gray-900">Create Campaign</h2>
    </div>

    <form id="optee-campaign-form" class="optee-card space-y-5">
        <div class="flex items-center gap-4">
            <label class="optee-label shrink-0" for="campaign-title" style="width:180px;margin-bottom:0;">Campaign Name <span class="text-red-500">*</span></label>
            <input type="text" id="campaign-title" name="title" class="optee-input flex-1" placeholder="My Campaign" required>
        </div>

        <!-- Traffic Source -->
        <div class="space-y-5" style="background:#f0f7ff;margin-left:-24px;margin-right:-24px;padding:16px 24px;">
            <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;border-left:2px solid #2aa5ff;padding-left:10px;">Traffic Source</div>
            <div class="flex items-center gap-4">
                <label class="optee-label shrink-0" for="campaign-traffic-source" style="width:180px;margin-bottom:0;">Traffic Source</label>
                <select id="campaign-traffic-source" name="traffic_source_module" class="optee-select flex-1">
                    <option value="">— Select —</option>
                    <?php foreach ( $traffic_sources as $m ) : ?>
                        <option value="<?php echo esc_attr( $m->get_slug() ); ?>"><?php echo esc_html( $m->get_name() ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="campaign-remote-wrapper" class="hidden flex items-center gap-4">
                <label class="optee-label shrink-0" style="width:180px;margin-bottom:0;">Campaign <span class="text-red-500">*</span></label>
                <div class="optee-ss flex-1" id="ss-traffic-source-campaign">
                    <input type="text" class="optee-input w-full optee-ss-input" placeholder="— Select traffic source first —" autocomplete="off" disabled>
                    <input type="hidden" name="traffic_source_campaign_id" id="campaign-remote-id">
                    <div class="optee-ss-dropdown hidden"></div>
                </div>
            </div>

            <?php // Opt-in to include archived campaigns — shown only for sources that support it (e.g. PropellerAds). ?>
            <div id="campaign-archived-wrapper" class="hidden flex items-center gap-4">
                <div class="shrink-0" style="width:180px;"></div>
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#6b7280;cursor:pointer;">
                    <input type="checkbox" id="campaign-include-archived" class="optee-checkbox">
                    <span>Include archived campaigns</span>
                </label>
            </div>

            <?php // Multi-select for sources where one creative = one campaign (e.g. Push.House). ?>
            <div id="campaign-remote-multi-wrapper" class="hidden flex items-start gap-4">
                <label class="optee-label shrink-0" style="width:180px;margin-bottom:0;">Campaigns <span class="text-red-500">*</span>
                    <span class="block text-gray-400" style="font-weight:400;font-size:11px;margin-top:2px;">Group creatives into one campaign</span>
                </label>
                <div class="flex-1">
                    <div style="display:flex;gap:8px;margin-bottom:6px;">
                        <input type="text" id="campaign-multi-search" class="optee-input" style="flex:1;" placeholder="Filter campaigns…" autocomplete="off">
                        <button type="button" id="campaign-multi-all" class="optee-btn-ghost optee-btn-sm" style="white-space:nowrap;">Select all</button>
                        <button type="button" id="campaign-multi-none" class="optee-btn-ghost optee-btn-sm">Clear</button>
                    </div>
                    <div id="campaign-multi-list" style="max-height:260px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:4px;background:#fff;">
                        <div class="text-sm text-gray-400 text-center py-4">— Select traffic source first —</div>
                    </div>
                    <div id="campaign-multi-count" class="text-xs text-gray-500 mt-1">0 selected</div>
                </div>
            </div>
        </div>

        <!-- Tracker -->
        <div class="space-y-5" style="background:#f5f3ff;margin-left:-24px;margin-right:-24px;padding:16px 24px;margin-top:0;border-top:1px solid #e5e7eb;">
            <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;border-left:2px solid #8b5cf6;padding-left:10px;">Tracker</div>
            <div class="flex items-center gap-4">
                <label class="optee-label shrink-0" for="campaign-tracker" style="width:180px;margin-bottom:0;">Tracker</label>
                <select id="campaign-tracker" name="tracker_module" class="optee-select flex-1">
                    <option value="">— None —</option>
                    <?php foreach ( $trackers as $m ) : ?>
                        <option value="<?php echo esc_attr( $m->get_slug() ); ?>"<?php echo method_exists( $m, 'get_traffic_sources' ) ? ' data-supports-ts="1"' : ''; ?>><?php echo esc_html( $m->get_name() ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="campaign-tracker-remote-wrapper" class="hidden flex items-center gap-4">
                <label class="optee-label shrink-0" style="width:180px;margin-bottom:0;">Campaign <span class="text-red-500">*</span></label>
                <div class="optee-ss flex-1" id="ss-tracker-campaign">
                    <input type="text" class="optee-input w-full optee-ss-input" placeholder="— Select tracker first —" autocomplete="off" disabled>
                    <input type="hidden" name="tracker_campaign_id" id="campaign-tracker-remote-id">
                    <div class="optee-ss-dropdown hidden"></div>
                </div>
            </div>

            <?php // Optional traffic-source filter — only shown for trackers whose funnels aggregate sources (FunnelFlux). ?>
            <div id="campaign-ts-wrapper" class="hidden flex items-center gap-4">
                <label class="optee-label shrink-0" for="campaign-ts-id" style="width:180px;margin-bottom:0;">Traffic Source
                    <span class="block text-gray-400" style="font-weight:400;font-size:11px;margin-top:2px;">Optional — scope stats to one source</span>
                </label>
                <select id="campaign-ts-id" name="tracker_ts_id" class="optee-select flex-1">
                    <option value="">— All traffic sources —</option>
                </select>
            </div>

            <?php // Optional external campaign-id filter (FunnelFlux {campaign} field) — several traffic-source campaigns can share one funnel. ?>
            <div id="campaign-ts-campaigns-wrapper" class="hidden flex items-center gap-4">
                <label class="optee-label shrink-0" for="campaign-ts-campaigns" style="width:180px;margin-bottom:0;">Campaign ID(s)
                    <span class="block text-gray-400" style="font-weight:400;font-size:11px;margin-top:2px;">Optional — the traffic source's campaign ID(s), comma-separated. Scopes stats to just those campaigns within the funnel.</span>
                </label>
                <div class="flex-1">
                    <input type="text" id="campaign-ts-campaigns" name="tracker_campaign_ids" class="optee-input w-full" placeholder="e.g. 1338175, 1343605">
                    <div id="campaign-ts-campaigns-chips" class="hidden" style="margin-top:8px;"></div>
                </div>
            </div>

            <div id="campaign-zone-wrapper" class="hidden flex items-center gap-4">
                <label class="optee-label shrink-0" for="campaign-zone-id" style="width:180px;margin-bottom:0;">Zone (Source) field <span class="text-red-500">*</span></label>
                <select id="campaign-zone-id" name="zone_id" class="optee-select flex-1">
                    <option value="">— Select tracker campaign first —</option>
                </select>
            </div>
        </div>

        <!-- Other Modules -->
        <div class="space-y-5" style="background:#f9fafb;margin-left:-24px;margin-right:-24px;padding:16px 24px;margin-top:0;border-top:1px solid #e5e7eb;">
            <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;border-left:2px solid #d1d5db;padding-left:10px;">Other Modules</div>
            <div class="flex items-center gap-4">
                <label class="optee-label shrink-0" for="campaign-network" style="width:180px;margin-bottom:0;">Affiliate Network</label>
                <select id="campaign-network" name="affiliate_network_module" class="optee-select flex-1">
                    <option value="">— None —</option>
                    <?php foreach ( $networks as $m ) : ?>
                        <option value="<?php echo esc_attr( $m->get_slug() ); ?>"><?php echo esc_html( $m->get_name() ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center gap-4">
                <label class="optee-label shrink-0" for="campaign-offer-id" style="width:180px;margin-bottom:0;">Offer ID</label>
                <input type="text" id="campaign-offer-id" name="offer_id" class="optee-input flex-1" placeholder="Offer ID (optional)">
            </div>

            <div class="flex items-center gap-4">
                <label class="optee-label shrink-0" style="width:180px;margin-bottom:0;">Is Smartlink</label>
                <div class="flex items-center gap-3 flex-1">
                    <label class="flex items-center gap-2 cursor-pointer" style="margin:0;">
                        <input type="checkbox" id="campaign-is-smartlink" name="is_smartlink" value="1" class="optee-checkbox">
                        <span class="text-sm text-gray-700">Yes</span>
                    </label>
                    <button type="button" class="optee-icon-btn" data-tip="Info" style="width:24px;height:24px;" onclick="document.getElementById('smartlink-info').classList.toggle('hidden')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    </button>
                </div>
            </div>
            <div id="smartlink-info" class="hidden" style="margin-left:180px;padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px;color:#92400e;line-height:1.5;">
                Smartlinks rotate offers automatically. When this is enabled, offer-based automation rules (pause/start campaign by offer status) will not work. The Offer ID field is also disabled since smartlinks don't use a single offer.
            </div>

            <div class="flex items-center gap-4">
                <label class="optee-label shrink-0" for="campaign-antifraud" style="width:180px;margin-bottom:0;">Antifraud Tool</label>
                <select id="campaign-antifraud" name="antifraud_module" class="optee-select flex-1">
                    <option value="">— None —</option>
                    <?php foreach ( $antifraud as $m ) : ?>
                        <option value="<?php echo esc_attr( $m->get_slug() ); ?>"><?php echo esc_html( $m->get_name() ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="campaign-antifraud-remote-wrapper" class="hidden flex items-center gap-4">
                <label class="optee-label shrink-0" style="width:180px;margin-bottom:0;">Antifraud Campaign <span class="text-red-500">*</span></label>
                <div class="optee-ss flex-1" id="ss-antifraud-campaign">
                    <input type="text" class="optee-input w-full optee-ss-input" placeholder="— Select antifraud tool first —" autocomplete="off" disabled>
                    <input type="hidden" name="antifraud_campaign_id" id="campaign-antifraud-remote-id">
                    <div class="optee-ss-dropdown hidden"></div>
                </div>
            </div>

        </div>

        <div class="flex items-center gap-3 pt-2">
            <div class="shrink-0" style="width:180px;"></div>
            <button type="submit" class="optee-btn-primary">Create Campaign</button>
            <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'campaigns' ) ); ?>" class="optee-btn-secondary">Cancel</a>
        </div>
    </form>
</div>
