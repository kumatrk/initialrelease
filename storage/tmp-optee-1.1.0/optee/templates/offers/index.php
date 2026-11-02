<?php
/**
 * Offers — Track affiliate network offer statuses.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Find connected affiliate networks that support get_offer_status.
$an_modules = $registry->get_modules_by_type( 'affiliate_network' );
$networks   = [];

// Per-network info hints shown next to Offer ID.
$network_hints = [
    'zeydoo'    => 'Only offers in your "My Offers" list are checked — not all Zeydoo offers.',
    'monetizer' => 'Offer ID is the short hash (e.g. 9970db), not the internal campaign ID.',
    'ogads'     => 'All live offers are checked. If not found, the offer may be paused or capped.',
    'cpalead'   => 'CPAlead doesn\'t expose offer IDs on the dashboard — you can paste the offer name instead. Case-insensitive substring match works.',
    'cpagrip'   => 'CPAGrip doesn\'t show offer IDs in My Offers — you can paste the offer name instead. Case-insensitive substring match works. (The numeric ID is also in the tracking link as &id=.)',
];

// Per-network placeholder + label overrides for the input field.
$network_inputs = [
    'cpalead' => [
        'label'       => 'Offer ID or name',
        'placeholder' => 'e.g. AARP Membership',
    ],
    'cpagrip' => [
        'label'       => 'Offer ID or name',
        'placeholder' => 'e.g. Oobyte',
    ],
];

foreach ( $an_modules as $module ) {
    $slug  = $module->get_slug();
    $creds = \Optee\Plugin\ModuleRegistry::get_stored_credentials( 'affiliate_network', $slug );
    if ( $creds && method_exists( $module, 'get_offer_status' ) ) {
        $networks[] = [
            'slug'        => $slug,
            'name'        => $module->get_name(),
            'hint'        => $network_hints[ $slug ] ?? '',
            'label'       => $network_inputs[ $slug ]['label'] ?? 'Offer ID',
            'placeholder' => $network_inputs[ $slug ]['placeholder'] ?? 'e.g. 19037',
        ];
    }
}
?>
<div class="space-y-6">
    <div>
        <h2 class="text-xl font-bold text-gray-900">Offers</h2>
        <p class="mt-1 text-sm text-gray-500">Track offer statuses across your affiliate networks.</p>
    </div>

    <!-- Quick Check -->
    <div class="optee-card" style="max-width:540px;">
        <h3 class="text-sm font-semibold text-gray-900 mb-4">Quick Status Check</h3>
        <?php if ( empty( $networks ) ) : ?>
            <p class="text-sm text-gray-500">No affiliate networks with offer status support are configured. Connect one under
                <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'affiliate-networks' ) ); ?>" class="text-brand-600 hover:text-brand-700">Affiliate Networks</a>.
            </p>
        <?php else : ?>
            <form id="optee-offer-check-form" class="space-y-4">
                <div>
                    <label class="optee-label">Affiliate Network</label>
                    <select id="optee-offer-network" class="optee-input">
                        <?php foreach ( $networks as $net ) : ?>
                            <option value="<?php echo esc_attr( $net['slug'] ); ?>"
                                    data-hint="<?php echo esc_attr( $net['hint'] ); ?>"
                                    data-input-label="<?php echo esc_attr( $net['label'] ); ?>"
                                    data-input-placeholder="<?php echo esc_attr( $net['placeholder'] ); ?>"><?php echo esc_html( $net['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="optee-label" id="optee-offer-id-label">Offer ID</label>
                    <div class="flex items-center gap-2">
                        <input type="text" id="optee-offer-id" class="optee-input" placeholder="e.g. 19037" style="max-width:280px;">
                        <button type="button" id="optee-offer-hint-btn" class="text-gray-400 hover:text-gray-600 hidden" style="background:none;border:none;cursor:pointer;padding:0;flex-shrink:0;"
                                title="">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="optee-btn-primary" id="optee-offer-check-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;margin-right:4px;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    Check Status
                </button>
            </form>
            <div id="optee-offer-result" class="mt-4 hidden"></div>
        <?php endif; ?>
    </div>
</div>

<script>
(function ($) {
    'use strict';

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s); }
    function capitalize(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); }

    $(document).on('click', '.optee-copy-offer-id', function () {
        var id = $(this).data('id');
        if (!id) return;
        var ok = false;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(String(id));
            ok = true;
        } else {
            var $tmp = $('<textarea>').val(id).css({position: 'absolute', left: '-9999px'}).appendTo('body');
            $tmp[0].select();
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            $tmp.remove();
        }
        if (ok) {
            Optee.Toast.show('Copied offer ID: ' + id, 'success');
        }
    });

    function updateInputForNetwork() {
        var $opt        = $('#optee-offer-network option:selected');
        var hint        = $opt.data('hint') || '';
        var label       = $opt.data('input-label') || 'Offer ID';
        var placeholder = $opt.data('input-placeholder') || 'e.g. 19037';
        var $btn        = $('#optee-offer-hint-btn');

        $('#optee-offer-id-label').text(label);
        $('#optee-offer-id').attr('placeholder', placeholder);

        if (hint) {
            $btn.attr('title', hint).removeClass('hidden');
        } else {
            $btn.addClass('hidden');
        }
    }

    $(document).on('change', '#optee-offer-network', updateInputForNetwork);
    updateInputForNetwork();

    $(document).on('click', '#optee-offer-hint-btn', function () {
        Optee.Toast.show($(this).attr('title'), 'info');
    });

    $(document).on('submit', '#optee-offer-check-form', function (e) {
        e.preventDefault();

        var slug    = $('#optee-offer-network').val();
        var query   = $.trim($('#optee-offer-id').val());
        var $btn    = $('#optee-offer-check-btn');
        var $result = $('#optee-offer-result');
        var label   = ($('#optee-offer-network option:selected').data('input-label') || 'Offer ID').toLowerCase();

        if (!query) {
            Optee.Toast.show('Please enter an ' + label + '.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Checking...');
        $result.addClass('hidden');

        Optee.Ajax.post('optee_check_offer_status', {
            module_slug: slug,
            offer_id: query
        }).done(function (res) {
            if (!res.success) {
                Optee.Toast.show(res.data.message || 'Check failed.', 'error');
                return;
            }

            var d = res.data;
            var hint = '';
            if (d.status === 'unknown') {
                hint = $('#optee-offer-network option:selected').data('hint') || '';
            }

            // Build either a single-result card or a multi-result list.
            var hasMultiple = Array.isArray(d.matches) && d.matches.length > 1;
            var html = '';

            if (hasMultiple) {
                html += '<div class="text-xs text-gray-500 mb-2">' + d.matches.length + ' matches for "' + escapeHtml(query) + '":</div>';
                html += '<div style="display:flex;flex-direction:column;gap:10px;">';
                d.matches.forEach(function (m) {
                    var mBadge = (m.status === 'active')
                        ? '<span class="optee-badge-green">Active</span>'
                        : '<span class="optee-badge-red">' + capitalize(m.status || 'unknown') + '</span>';
                    var mPayout = (m.payout !== undefined && m.payout !== null && String(m.payout) !== '' && parseFloat(m.payout) > 0)
                        ? ' · $' + escapeHtml(m.payout)
                        : '';
                    html += '<div class="optee-card flex items-center gap-3" style="padding:10px 14px;background:#f9fafb;">' +
                        mBadge +
                        '<div style="min-width:0;flex:1;">' +
                            '<div class="text-sm font-medium text-gray-900" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(m.name || 'Offer #' + m.id) + '</div>' +
                            '<div class="text-xs text-gray-500">ID: ' + escapeHtml(m.id) + mPayout + '</div>' +
                        '</div>' +
                        '<button type="button" class="optee-copy-offer-id text-xs text-brand-600 hover:text-brand-700" data-id="' + escapeAttr(m.id) + '" style="background:none;border:none;cursor:pointer;flex-shrink:0;">Copy ID</button>' +
                    '</div>';
                });
                html += '</div>';
            } else {
                var isActive   = d.active;
                var badge      = isActive
                    ? '<span class="optee-badge-green">Active</span>'
                    : '<span class="optee-badge-red">' + capitalize(d.status) + '</span>';
                var resolvedId = d.id || query;

                var sPayout = (d.payout !== undefined && d.payout !== null && String(d.payout) !== '' && parseFloat(d.payout) > 0)
                    ? ' \u00B7 $' + escapeHtml(d.payout)
                    : '';
                html += '<div class="optee-card flex items-center gap-3" style="padding:12px 16px;background:#f9fafb;">' +
                    badge +
                    '<div>' +
                        '<div class="text-sm font-medium text-gray-900">' + escapeHtml(d.name || 'Offer #' + resolvedId) + '</div>' +
                        '<div class="text-xs text-gray-500">ID: ' + escapeHtml(resolvedId) + sPayout + ' \u00B7 Status: ' + escapeHtml(d.status) +
                            (hint ? '<br><span class="text-orange-600">' + escapeHtml(hint) + '</span>' : '') +
                        '</div>' +
                    '</div>' +
                '</div>';
            }

            $result.html(html).removeClass('hidden');
        }).fail(function () {
            Optee.Toast.show('Request failed.', 'error');
        }).always(function () {
            $btn.prop('disabled', false).html(
                '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;margin-right:4px;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>' +
                'Check Status'
            );
        });
    });
})(jQuery);
</script>
