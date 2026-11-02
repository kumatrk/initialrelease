<?php
/**
 * Promo Codes page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="space-y-6">
    <div>
        <h2 class="text-xl font-bold text-gray-900">Promo Codes</h2>
        <p class="mt-1 text-sm text-gray-500">Exclusive promo codes from our traffic source partners. Request a code, and we'll get back to you shortly.</p>
    </div>

    <div id="optee-promo-loading" class="optee-card" style="text-align:center;padding:48px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;animation:spin 1s linear infinite;color:#9ca3af;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
        <span class="text-gray-400" style="margin-left:8px;">Loading campaigns...</span>
    </div>

    <!-- Active campaigns -->
    <div id="optee-promo-campaigns" style="display:none;"></div>

    <!-- My Requests -->
    <div id="optee-promo-requests-section" style="display:none;">
        <h3 class="text-lg font-bold text-gray-900" style="margin-bottom:12px;">My Requests</h3>
        <div id="optee-promo-requests-body" style="display:flex;flex-direction:column;gap:12px;"></div>
    </div>

    <!-- Empty state -->
    <div id="optee-promo-empty" class="optee-card" style="display:none;text-align:center;padding:48px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;color:#d1d5db;margin-bottom:12px;"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>
        <p class="text-gray-400" style="font-size:15px;">No promo campaigns available at the moment.</p>
    </div>
</div>

<script>
jQuery(function($) {
    'use strict';

    var E = Optee.Util.escapeHtml;

    function statusBadge(status) {
        var map = { pending: 'optee-badge-yellow', approved: 'optee-badge-green', declined: 'optee-badge-red' };
        var labels = { pending: 'Pending', approved: 'Approved', declined: 'Declined' };
        return '<span class="' + (map[status] || 'optee-badge-gray') + '">' + (labels[status] || status) + '</span>';
    }

    function userTypeBadge(ut) {
        var map = { all: 'optee-badge-gray', new: 'optee-badge-blue', existing: 'optee-badge-green' };
        var labels = { all: 'All Users', new: 'New Users', existing: 'Existing' };
        return '<span class="' + (map[ut] || 'optee-badge-gray') + '" style="font-size:10px;">' + (labels[ut] || ut) + '</span>';
    }

    // Load campaigns
    Optee.Ajax.post('optee_get_promo_campaigns').done(function(res) {
        $('#optee-promo-loading').hide();

        var campaigns = (res.data && res.data.campaigns) || [];
        if (!campaigns.length) {
            $('#optee-promo-empty').show();
            loadMyRequests();
            return;
        }

        if (res.data && res.data.preview) {
            $('#optee-promo-campaigns').before(
                '<div class="optee-card" style="padding:10px 14px;margin-bottom:12px;background:#fef3c7;border:1px solid #fde68a;color:#92400e;font-size:13px;">' +
                '<strong>Draft Preview Mode:</strong> Draft campaigns are visible. They are NOT shown to other users.' +
                '</div>'
            );
        }

        var html = '';
        $.each(campaigns, function(i, c) {
            if (c.status === 'closed') return; // Only show active campaigns in main section

            html += '<div class="optee-card" style="padding:0;overflow:hidden;margin-bottom:16px;' + (c._draft ? 'outline:2px dashed #f59e0b;outline-offset:-2px;' : '') + '">';
            html += '<div style="display:flex;align-items:center;gap:16px;padding:20px 24px;border-bottom:1px solid #f3f4f6;">';

            // Logo
            if (c.logo_url) {
                html += '<div style="flex-shrink:0;width:56px;height:56px;display:flex;align-items:center;justify-content:center;">';
                html += '<img src="' + E(c.logo_url) + '" alt="' + E(c.traffic_source_name) + '" style="max-width:56px;max-height:56px;object-fit:contain;">';
                html += '</div>';
            }

            // Title + description
            html += '<div style="flex:1;min-width:0;">';
            html += '<div style="display:flex;align-items:center;gap:8px;">';
            html += '<h3 style="font-size:16px;font-weight:700;color:#111827;margin:0;">' + E(c.title) + '</h3>';
            if (c._draft) {
                html += '<span style="font-size:10px;font-weight:700;letter-spacing:0.05em;background:#f59e0b;color:#fff;padding:2px 8px;border-radius:4px;text-transform:uppercase;">Draft</span>';
            }
            html += '<span class="optee-badge-blue" style="font-size:10px;">' + E(c.month) + '</span>';
            html += '</div>';
            if (c.description) {
                html += '<p style="font-size:13px;color:#6b7280;margin:4px 0 0;line-height:1.5;">' + E(c.description) + '</p>';
            }
            html += '</div>';
            html += '</div>';

            // Tiers
            var tiers = c.tiers || [];
            if (tiers.length) {
                html += '<div style="padding:16px 24px;">';
                $.each(tiers, function(j, t) {
                    html += '<div style="display:flex;align-items:center;gap:12px;padding:10px 0;' + (j < tiers.length - 1 ? 'border-bottom:1px solid #f9fafb;' : '') + '">';
                    html += '<div style="flex:1;min-width:0;">';
                    html += '<span style="font-size:14px;font-weight:600;color:#111827;">' + E(t.label) + '</span> ';
                    html += userTypeBadge(t.user_type);
                    html += '<div style="font-size:12px;color:#9ca3af;margin-top:2px;">' + t.remaining_codes + ' codes available</div>';
                    html += '</div>';

                    // Action button
                    if (t.user_request) {
                        if (t.user_request.status === 'approved') {
                            html += '<span class="optee-badge-green">Approved</span>';
                        } else if (t.user_request.status === 'pending') {
                            html += '<span class="optee-badge-yellow">Pending</span>';
                        } else if (t.user_request.status === 'declined') {
                            html += '<span class="optee-badge-red">Declined</span>';
                        }
                    } else if (t.remaining_codes > 0) {
                        html += '<button class="optee-btn-primary optee-btn-sm optee-promo-request" data-tier-id="' + t.id + '" data-label="' + E(t.label) + '">Request Code</button>';
                    } else {
                        html += '<span style="font-size:12px;color:#9ca3af;">No codes left</span>';
                    }

                    html += '</div>';
                });
                html += '</div>';
            }

            // Signup link
            if (c.signup_url) {
                html += '<div style="background:#f9fafb;border-top:1px solid #f3f4f6;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;">';
                html += '<span style="font-size:12px;color:#9ca3af;">Don\'t have a ' + E(c.traffic_source_name) + ' account yet?</span>';
                html += '<a href="' + E(c.signup_url) + '" target="_blank" rel="noopener" class="optee-btn-primary optee-btn-sm" style="font-size:12px;">Sign Up <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left:4px;display:inline;vertical-align:middle;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>';
                html += '</div>';
            }

            html += '</div>';
        });

        if (html) {
            $('#optee-promo-campaigns').html(html).show();
        } else {
            $('#optee-promo-empty').show();
        }

        loadMyRequests();
    }).fail(function() {
        $('#optee-promo-loading').hide();
        $('#optee-promo-empty').show().find('p').text('Failed to load campaigns. Please try again later.');
    });

    // Request code
    $(document).on('click', '.optee-promo-request', function() {
        var $btn = $(this);
        var tierId = $btn.data('tier-id');
        var label = $btn.data('label');

        if (!confirm('Request a promo code for "' + label + '"?')) return;

        $btn.prop('disabled', true).text('Requesting...');

        Optee.Ajax.post('optee_submit_promo_request', { tier_id: tierId }).done(function(res) {
            if (res.success) {
                Optee.Toast.show('Code requested! We\'ll review it shortly.', 'success');
                $btn.replaceWith('<span class="optee-badge-yellow">Pending</span>');
            } else {
                Optee.Toast.show(res.data.message || 'Request failed.', 'error');
                $btn.prop('disabled', false).text('Request Code');
            }
        }).fail(function() {
            Optee.Toast.show('Network error.', 'error');
            $btn.prop('disabled', false).text('Request Code');
        });
    });

    // Load my requests
    function loadMyRequests() {
        Optee.Ajax.post('optee_get_promo_my_requests').done(function(res) {
            var requests = (res.data && res.data.requests) || [];
            if (!requests.length) return;

            var html = '';
            $.each(requests, function(i, r) {
                html += '<div class="optee-card" style="padding:16px;">';

                // Top row: campaign + tier on the left, status badge on the right
                html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">';
                html += '<div style="flex:1;min-width:0;">';
                html += '<div style="font-size:14px;font-weight:700;color:#111827;line-height:1.3;">' + E(r.campaign_title || 'Promo Code') + '</div>';
                if (r.tier_label) {
                    html += '<div style="font-size:12px;color:#6b7280;margin-top:3px;line-height:1.4;">' + E(r.tier_label) + '</div>';
                }
                html += '</div>';
                html += '<div style="flex-shrink:0;">' + statusBadge(r.status) + '</div>';
                html += '</div>';

                // Bottom row: code (or placeholder) + date
                html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px;flex-wrap:wrap;">';
                if (r.status === 'approved' && r.promo_code) {
                    html += '<div style="display:flex;align-items:center;gap:6px;min-width:0;">';
                    html += '<code style="background:#f3f4f6;border:1px dashed #d1d5db;border-radius:4px;padding:4px 10px;font-size:14px;font-weight:600;color:#111827;word-break:break-all;">' + E(r.promo_code) + '</code>';
                    html += '<button class="optee-promo-copy" data-code="' + E(r.promo_code) + '" title="Copy" style="background:none;border:1px solid #e5e7eb;border-radius:4px;padding:4px 6px;cursor:pointer;color:#6b7280;display:flex;align-items:center;flex-shrink:0;">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
                    html += '</button></div>';
                } else {
                    html += '<span style="color:#9ca3af;font-size:13px;">No code yet</span>';
                }
                html += '<span style="font-size:12px;color:#9ca3af;">' + (r.requested_at ? r.requested_at.replace('T', ' ').substring(0, 16) : '') + '</span>';
                html += '</div>';

                html += '</div>';
            });

            $('#optee-promo-requests-body').html(html);
            $('#optee-promo-requests-section').show();
        });
    }

    // Copy promo code
    $(document).on('click', '.optee-promo-copy', function() {
        var code = $(this).data('code');
        navigator.clipboard.writeText(code).then(function() {
            Optee.Toast.show('Promo code copied: ' + code, 'success');
        });
    });
});
</script>
