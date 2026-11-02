<?php
/**
 * Notifications archive view.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Logs</h2>
        </div>
        <button id="optee-clear-notifications" class="optee-btn-danger optee-btn-sm">Clear All Notifications</button>
    </div>

    <div class="flex items-center gap-4 border-b border-gray-200 pb-4">
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'logs' ) ); ?>"
           class="text-sm font-medium text-gray-500 hover:text-gray-900 pb-2">System Logs</a>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'logs', 'notifications' ) ); ?>"
           class="text-sm font-medium text-gray-900 border-b-2 border-brand-500 pb-2">Notifications</a>
    </div>

    <!-- Filters -->
    <div class="flex items-center gap-3">
        <select id="optee-notif-type-filter" class="optee-select w-auto">
            <option value="">All Types</option>
            <option value="info">Info</option>
            <option value="success">Success</option>
            <option value="warning">Warning</option>
            <option value="error">Error</option>
        </select>
    </div>

    <div id="optee-notif-pagination-top" class="flex items-center justify-between text-sm text-gray-500"></div>

    <!-- Notifications table (desktop) -->
    <div id="optee-notif-table-wrap" class="optee-card p-0">
        <table class="optee-table" id="optee-notif-table">
            <thead>
                <tr>
                    <th style="width:150px;white-space:nowrap;">Time</th>
                    <th style="width:70px;">Type</th>
                    <th style="width:250px;">Title</th>
                    <th style="width:260px;">Campaign</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody id="optee-notif-tbody">
                <tr>
                    <td colspan="5" class="text-center py-8 text-gray-400">Loading notifications...</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Notifications cards (mobile) -->
    <div id="optee-notif-cards" style="display:none;"></div>

    <div id="optee-notif-pagination" class="flex items-center justify-between text-sm text-gray-500"></div>
</div>

<script>
(function ($) {
    'use strict';

    var Notifs = {
        page: 1,
        perPage: 50,

        init: function () {
            this.load();
            this.bindEvents();
        },

        bindEvents: function () {
            $('#optee-notif-type-filter').on('change', function () {
                Notifs.page = 1;
                Notifs.load();
            });

            $('#optee-clear-notifications').on('click', function () {
                if (Optee.Util.confirm('Are you sure you want to clear all notifications?')) {
                    Optee.Ajax.post('optee_clear_notifications_archive').done(function (res) {
                        if (res.success) {
                            Optee.Toast.show('Notifications cleared.', 'success');
                            Notifs.load();
                        }
                    });
                }
            });
        },

        load: function () {
            var $tbody = $('#optee-notif-tbody');
            var $cards = $('#optee-notif-cards');
            $tbody.html('<tr><td colspan="5" class="text-center py-8 text-gray-400">Loading...</td></tr>');
            $cards.html('<div class="text-center py-8 text-gray-400">Loading...</div>');

            Optee.Ajax.post('optee_get_notifications_archive', {
                page: Notifs.page,
                per_page: Notifs.perPage,
                type: $('#optee-notif-type-filter').val()
            }).done(function (res) {
                if (!res.success || !res.data.notifications.length) {
                    $tbody.html('<tr><td colspan="5" class="text-center py-8 text-gray-400">No notifications found.</td></tr>');
                    $cards.html('<div class="text-center py-8 text-gray-400">No notifications found.</div>');
                    $('#optee-notif-pagination, #optee-notif-pagination-top').empty();
                    return;
                }

                var html = '';
                var cardHtml = '';
                var typeBadgeMap = {
                    info: 'optee-badge-blue',
                    success: 'optee-badge-green',
                    warning: 'optee-badge-yellow',
                    error: 'optee-badge-red'
                };
                var esc = Optee.Util.escapeHtml;

                $.each(res.data.notifications, function (i, n) {
                    var badge = typeBadgeMap[n.type] || 'optee-badge-gray';
                    var msg = n.message || '';
                    var isLong = msg.length > 80;
                    var truncated = isLong ? msg.substring(0, 80) + '...' : msg;

                    // Campaign cell: link to campaign stats when present.
                    var campaignCell = '<span class="text-gray-400">&mdash;</span>';
                    if (n.campaign_id && n.campaign_title) {
                        var campUrl = '<?php echo esc_js( \Optee\Plugin\Shortcode::get_url( 'dashboard', 'campaign' ) ); ?>' + '&id=' + encodeURIComponent(n.campaign_id);
                        campaignCell = '<a href="' + campUrl + '" class="text-brand-600 hover:underline" style="text-decoration:none;" onclick="event.stopPropagation();">' + esc(n.campaign_title) + '</a>';
                    }

                    // Desktop table row.
                    html += '<tr class="optee-notif-row" data-idx="' + i + '" style="cursor:pointer;">' +
                        '<td class="whitespace-nowrap text-xs">' + esc(n.created_at) + '</td>' +
                        '<td><span class="' + badge + '">' + esc(n.type) + '</span></td>' +
                        '<td class="text-sm font-medium">' + esc(n.title || '') + '</td>' +
                        '<td class="text-sm" style="white-space:normal;word-break:break-word;line-height:1.35;max-width:260px;">' + campaignCell + '</td>' +
                        '<td class="text-sm notif-msg" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:500px;">' + esc(truncated) + '</td>' +
                        '</tr>';

                    // Mobile card.
                    var bgColor = i % 2 === 0 ? '#fff' : '#f9fafb';
                    cardHtml += '<div class="optee-notif-card" data-idx="' + i + '" style="padding:12px 16px;background:' + bgColor + ';border-bottom:1px solid #f3f4f6;cursor:pointer;">';
                    cardHtml += '<div class="flex items-center gap-2 flex-wrap">';
                    cardHtml += '<span class="text-xs text-gray-400">' + esc(n.created_at) + '</span>';
                    cardHtml += '<span class="' + badge + '">' + esc(n.type) + '</span>';
                    cardHtml += '</div>';
                    if (n.title) {
                        cardHtml += '<div class="font-semibold text-gray-900 mt-1.5" style="font-size:15px;">' + esc(n.title) + '</div>';
                    }
                    if (n.campaign_id && n.campaign_title) {
                        cardHtml += '<div class="text-xs text-gray-500 mt-1" style="font-style:italic;">' + esc(n.campaign_title) + '</div>';
                    }
                    if (msg) {
                        cardHtml += '<div class="text-sm text-gray-600 mt-1" style="word-break:break-word;">' + esc(msg) + '</div>';
                    }
                    cardHtml += '</div>';
                });

                $tbody.html(html);
                $cards.html(cardHtml);

                // Expand/collapse full message on row click (desktop).
                $tbody.off('click', '.optee-notif-row').on('click', '.optee-notif-row', function () {
                    var idx = $(this).data('idx');
                    var $next = $(this).next('.optee-notif-expand-row');

                    if ($next.length) {
                        $next.remove();
                        return;
                    }

                    var msg = res.data.notifications[idx].message || '';
                    $(this).after(
                        '<tr class="optee-notif-expand-row">' +
                        '<td colspan="5" style="background:#f9fafb;padding:10px 16px;white-space:normal;word-break:break-word;">' +
                        '<span class="text-sm text-gray-700">' + Optee.Util.escapeHtml(msg) + '</span>' +
                        '</td></tr>'
                    );
                });

                // Pagination.
                Notifs.renderPagination(res.data.total, res.data.pages);
            });
        },

        renderPagination: function (total, totalPages) {
            var $pag = $('#optee-notif-pagination');
            var $pagTop = $('#optee-notif-pagination-top');
            var curPage = Notifs.page;

            if (totalPages <= 1) {
                var singleHtml = '<span>' + total + ' notification(s)</span>';
                $pag.html(singleHtml);
                $pagTop.html(singleHtml);
                return;
            }

            var btnStyle = 'min-width:36px;height:36px;padding:0 8px;cursor:pointer;';
            var activeCls = 'bg-brand-600 text-white';
            var normalCls = 'bg-gray-100 text-gray-600 hover:bg-gray-200';

            var pagHtml = '<span>' + total + ' notification(s) &nbsp; Page ' + curPage + ' of ' + totalPages + '</span><div class="flex gap-1" style="align-items:center;">';

            if (curPage > 1) {
                pagHtml += '<button class="rounded text-sm ' + normalCls + '" style="' + btnStyle + '" data-page="' + (curPage - 1) + '">&laquo;</button>';
            }

            var w = 2;
            var pages = [1];
            for (var p = Math.max(2, curPage - w); p <= Math.min(totalPages - 1, curPage + w); p++) {
                pages.push(p);
            }
            if (totalPages > 1) pages.push(totalPages);
            pages = pages.filter(function(v, i, a) { return a.indexOf(v) === i; }).sort(function(a, b) { return a - b; });

            for (var i = 0; i < pages.length; i++) {
                if (i > 0 && pages[i] - pages[i - 1] > 1) {
                    pagHtml += '<span style="padding:0 4px;color:#9ca3af;">…</span>';
                }
                var cls = pages[i] === curPage ? activeCls : normalCls;
                pagHtml += '<button class="rounded text-sm ' + cls + '" style="' + btnStyle + '" data-page="' + pages[i] + '">' + pages[i] + '</button>';
            }

            if (curPage < totalPages) {
                pagHtml += '<button class="rounded text-sm ' + normalCls + '" style="' + btnStyle + '" data-page="' + (curPage + 1) + '">&raquo;</button>';
            }

            pagHtml += '</div>';
            $pag.html(pagHtml);
            $pagTop.html(pagHtml);
            $('#optee-notif-pagination, #optee-notif-pagination-top').find('button[data-page]').on('click', function () {
                Notifs.page = parseInt($(this).data('page'));
                Notifs.load();
            });
        }
    };

    $(function () { Notifs.init(); });
})(jQuery);
</script>
