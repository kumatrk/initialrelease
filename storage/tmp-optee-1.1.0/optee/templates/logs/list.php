<?php
/**
 * Logs list view.
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
        <button id="optee-clear-logs" class="optee-btn-danger optee-btn-sm">Clear All Logs</button>
    </div>

    <div class="flex items-center gap-4 border-b border-gray-200 pb-4">
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'logs' ) ); ?>"
           class="text-sm font-medium text-gray-900 border-b-2 border-brand-500 pb-2">System Logs</a>
        <a href="<?php echo esc_url( \Optee\Plugin\Shortcode::get_url( 'logs', 'notifications' ) ); ?>"
           class="text-sm font-medium text-gray-500 hover:text-gray-900 pb-2">Notifications</a>
    </div>

    <!-- Large-table warning (shown when the log table exceeds the threshold) -->
    <div id="optee-logs-size-warning" style="display:none;">
        <div class="optee-card" style="border-left:3px solid #f59e0b;background:#fffbeb;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
            <div style="display:flex;align-items:flex-start;gap:10px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                <p class="text-sm" style="color:#92400e;margin:0;">
                    Your log table is large (<strong id="optee-logs-size-count">0</strong> rows). This can slow down queries and use significant database space. Consider clearing old logs.
                </p>
            </div>
            <button id="optee-logs-warning-clear" class="optee-btn-danger optee-btn-sm" style="flex-shrink:0;">Empty logs</button>
        </div>
    </div>

    <!-- Filters -->
    <div class="optee-logs-filters">
        <input type="search" id="optee-log-search" class="optee-input w-full" placeholder="Search messages...">
        <select id="optee-log-type-filter" class="optee-select w-full">
            <option value="">All Types</option>
            <option value="info">Info</option>
            <option value="warning">Warning</option>
            <option value="error">Error</option>
            <option value="rule_execution">Rule Execution</option>
            <option value="api_call">API Call</option>
        </select>
        <select id="optee-log-category-filter" class="optee-select w-full">
            <option value="">All Categories</option>
            <option value="rule">Rule</option>
            <option value="campaign">Campaign</option>
            <option value="cron">Cron</option>
            <option value="module">Module</option>
            <option value="alert">Alert</option>
            <option value="general">General</option>
        </select>
    </div>

    <style>
    .optee-log-row-clickable { cursor: pointer; }
    #optee-logs-tbody td.log-msg { white-space: normal !important; word-break: break-word; }
    #optee-logs-table th, #optee-logs-table td { padding: 10px 8px !important; }
    .optee-logs-filters { display: grid; grid-template-columns: 1fr; gap: 0.75rem; }
    @media (min-width: 640px) {
        .optee-logs-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    </style>

    <!-- Active alert filter chip (shown when deep-linked from the Alerts page) -->
    <div id="optee-logs-alert-chip" style="display:none;">
        <span style="display:inline-flex;align-items:center;gap:8px;background:#e6f4ff;color:#1186e6;border:1px solid #bae0ff;border-radius:9999px;padding:4px 6px 4px 12px;font-size:13px;font-weight:600;">
            Filtered to one alert
            <button id="optee-logs-alert-chip-clear" title="Clear alert filter" style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border:none;background:rgba(17,134,230,.15);color:#1186e6;border-radius:9999px;cursor:pointer;line-height:1;">&times;</button>
        </span>
    </div>

    <div id="optee-logs-pagination-top" class="flex items-center justify-between text-sm text-gray-500"></div>

    <!-- Logs table -->
    <!-- Desktop table -->
    <div id="optee-logs-table-wrap" class="optee-card p-0" style="overflow-x:auto;">
        <table class="optee-table" id="optee-logs-table" style="table-layout:fixed;width:100%;">
            <thead>
                <tr>
                    <th style="width:130px;white-space:nowrap;">Time</th>
                    <th style="width:65px;">Type</th>
                    <th style="width:85px;">Category</th>
                    <th style="width:220px;">Campaign</th>
                    <th style="width:220px;">Rule</th>
                    <th>Message</th>
                    <th style="width:50px;">Details</th>
                </tr>
            </thead>
            <tbody id="optee-logs-tbody">
                <tr>
                    <td colspan="7" class="text-center py-8 text-gray-400">Loading logs...</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Mobile stacked view -->
    <div id="optee-logs-cards" style="display:none;"></div>

    <div id="optee-logs-pagination" class="flex items-center justify-between text-sm text-gray-500"></div>
</div>

<script>
(function ($) {
    'use strict';

    var Logs = {
        page: 1,
        perPage: 50,
        currentLogs: [],
        alertId: 0,
        largeThreshold: 1000000,

        updateSizeWarning: function (total) {
            var $w = $('#optee-logs-size-warning');
            if (total && total >= Logs.largeThreshold) {
                $('#optee-logs-size-count').text(Number(total).toLocaleString());
                $w.show();
            } else {
                $w.hide();
            }
        },

        init: function () {
            // Pre-set filters from URL params (e.g. deep-links from the Alerts page).
            try {
                var params = new URLSearchParams(window.location.search);
                var cat = params.get('category');
                var typ = params.get('log_type');
                var srch = params.get('search');
                var aid = params.get('alert_id');
                if (cat) $('#optee-log-category-filter').val(cat);
                if (typ) $('#optee-log-type-filter').val(typ);
                if (srch) $('#optee-log-search').val(srch);
                if (aid) this.alertId = parseInt(aid, 10) || 0;
            } catch (e) {}
            this.renderAlertChip();
            this.bindEvents();
            this.load();
        },

        renderAlertChip: function () {
            $('#optee-logs-alert-chip').toggle(!!Logs.alertId);
        },

        bindEvents: function () {
            var $tbody = $('#optee-logs-tbody');
            var $cards = $('#optee-logs-cards');

            $('#optee-log-type-filter, #optee-log-category-filter').on('change', function () {
                Logs.page = 1;
                Logs.load();
            });

            $('#optee-logs-alert-chip-clear').on('click', function () {
                Logs.alertId = 0;
                Logs.renderAlertChip();
                Logs.page = 1;
                Logs.load();
            });

            var searchTimer = null;
            $('#optee-log-search').on('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    Logs.page = 1;
                    Logs.load();
                }, 300);
            });

            $('#optee-clear-logs, #optee-logs-warning-clear').on('click', function () {
                if (!Optee.Util.confirm('Are you sure you want to clear all logs?')) return;

                var $btns = $('#optee-clear-logs, #optee-logs-warning-clear');
                var origText = $('#optee-clear-logs').text();
                $btns.prop('disabled', true);
                $('#optee-clear-logs').text('Clearing…');

                var totalDeleted = 0;
                function step() {
                    Optee.Ajax.post('optee_clear_logs').done(function (res) {
                        if (!res.success) {
                            Optee.Toast.show('Failed to clear logs.', 'error');
                            $btns.prop('disabled', false);
                            $('#optee-clear-logs').text(origText);
                            return;
                        }
                        totalDeleted += (res.data.deleted || 0);
                        if (!res.data.done) {
                            // More rows remain — keep going, showing progress.
                            $('#optee-clear-logs').text('Clearing… ' + totalDeleted.toLocaleString());
                            step();
                        } else {
                            Optee.Toast.show('Logs cleared (' + totalDeleted.toLocaleString() + ' removed).', 'success');
                            $btns.prop('disabled', false);
                            $('#optee-clear-logs').text(origText);
                            Logs.page = 1;
                            Logs.load();
                        }
                    }).fail(function () {
                        Optee.Toast.show('Failed to clear logs.', 'error');
                        $btns.prop('disabled', false);
                        $('#optee-clear-logs').text(origText);
                    });
                }
                step();
            });

            // Delegated handlers bound once — survive table reloads.
            function toggleContext($row) {
                var idx = $row.data('idx');
                var $link = $row.find('.optee-log-details');
                var $next = $row.next('.optee-log-context-row');

                if ($next.length) {
                    $next.remove();
                    $link.text('View');
                    return;
                }

                var ctx = Logs.currentLogs[idx] && Logs.currentLogs[idx].context;
                if (!ctx || typeof ctx !== 'object' || !Object.keys(ctx).length) return;

                var formatted = JSON.stringify(ctx, null, 2);
                $row.after(
                    '<tr class="optee-log-context-row">' +
                    '<td colspan="7" style="background:#f9fafb;padding:8px 16px;">' +
                    '<pre style="margin:0;font-size:12px;white-space:pre-wrap;word-break:break-all;max-height:300px;overflow:auto;color:#374151;">' +
                    Optee.Util.escapeHtml(formatted) +
                    '</pre></td></tr>'
                );
                $link.text('Hide');
            }

            $tbody.on('click', '.optee-log-row-clickable', function (e) {
                if ($(e.target).closest('a').length) return;
                toggleContext($(this));
            });
            $tbody.on('click', '.optee-log-details', function (e) {
                e.preventDefault();
                toggleContext($(this).closest('tr'));
            });

            $cards.on('click', '.optee-log-details-mob', function (e) {
                e.preventDefault();
                var $link = $(this);
                var $card = $link.closest('.optee-log-card');
                var idx = $card.data('idx');
                var $existing = $card.find('.optee-log-ctx-mob');
                if ($existing.length) {
                    $existing.remove();
                    $link.text('View Details');
                    return;
                }
                var ctx = Logs.currentLogs[idx] && Logs.currentLogs[idx].context;
                if (!ctx) return;
                var formatted = JSON.stringify(ctx, null, 2);
                $link.after('<pre class="optee-log-ctx-mob" style="margin:8px 0 0;font-size:11px;background:#f3f4f6;border-radius:6px;padding:10px;white-space:pre-wrap;word-break:break-all;max-height:200px;overflow:auto;color:#374151;">' + Optee.Util.escapeHtml(formatted) + '</pre>');
                $link.text('Hide Details');
            });
        },

        load: function () {
            var $tbody = $('#optee-logs-tbody');
            var $cards = $('#optee-logs-cards');
            $tbody.html('<tr><td colspan="7" class="text-center py-8 text-gray-400">Loading...</td></tr>');
            $cards.html('<div class="text-center py-8 text-gray-400">Loading...</div>');

            Optee.Ajax.post('optee_get_logs', {
                page: Logs.page,
                per_page: Logs.perPage,
                log_type: $('#optee-log-type-filter').val(),
                category: $('#optee-log-category-filter').val(),
                search: $('#optee-log-search').val(),
                alert_id: Logs.alertId || ''
            }).done(function (res) {
                Logs.currentLogs = (res && res.data && res.data.logs) || [];
                Logs.updateSizeWarning(res && res.data && res.data.table_total);
                if (!res.success || !res.data.logs.length) {
                    $tbody.html('<tr><td colspan="7" class="text-center py-8 text-gray-400">No logs found.</td></tr>');
                    $cards.html('<div class="text-center py-8 text-gray-400">No logs found.</div>');
                    $('#optee-logs-pagination, #optee-logs-pagination-top').empty();
                    return;
                }

                var html = '';
                var cardHtml = '';
                var typeBadgeMap = {
                    info: 'optee-badge-blue',
                    warning: 'optee-badge-yellow',
                    error: 'optee-badge-red',
                    rule_execution: 'optee-badge-green',
                    api_call: 'optee-badge-gray'
                };

                $.each(res.data.logs, function (i, log) {
                    var badge = typeBadgeMap[log.log_type] || 'optee-badge-gray';
                    var esc = Optee.Util.escapeHtml;
                    var hasCtx = log.context && typeof log.context === 'object' && Object.keys(log.context).length;
                    var detailsCell = '<td></td>';
                    if (hasCtx) {
                        detailsCell = '<td class="text-center"><a href="#" class="optee-log-details text-xs text-brand-600 hover:underline" data-idx="' + i + '">View</a></td>';
                    }
                    var rowClass = hasCtx ? 'optee-log-row-clickable' : '';
                    var campaignCell = '<td class="text-sm"></td>';
                    if (log.campaign_name && log.campaign_id) {
                        var campUrl = opteeData.dashboardUrl + '?module=dashboard&action=campaign&id=' + log.campaign_id;
                        campaignCell = '<td class="text-sm" style="white-space:normal;overflow-wrap:anywhere;"><a href="' + campUrl + '" class="text-brand-600 hover:text-brand-800 hover:underline">' + esc(log.campaign_name) + '</a></td>';
                    }

                    var ruleCell = '<td class="text-sm"></td>';
                    if (log.rule_name && log.campaign_id) {
                        var rulesUrl = opteeData.dashboardUrl + '?module=rules&campaign_id=' + log.campaign_id;
                        ruleCell = '<td class="text-sm" style="white-space:normal;overflow-wrap:anywhere;"><a href="' + rulesUrl + '" class="text-brand-600 hover:text-brand-800 hover:underline">' + esc(log.rule_name) + '</a></td>';
                    }

                    // Desktop table row.
                    html += '<tr class="' + rowClass + '" data-idx="' + i + '">' +
                        '<td class="whitespace-nowrap text-xs">' + esc(log.created_at) + '</td>' +
                        '<td><span class="' + badge + '">' + esc(log.log_type) + '</span></td>' +
                        '<td class="text-xs">' + esc(log.category) + '</td>' +
                        campaignCell +
                        ruleCell +
                        '<td class="text-sm log-msg">' + esc(log.message) + '</td>' +
                        detailsCell +
                        '</tr>';

                    // Mobile card.
                    var bgColor = i % 2 === 0 ? '#fff' : '#f9fafb';
                    cardHtml += '<div class="optee-log-card" data-idx="' + i + '" style="padding:12px 16px;background:' + bgColor + ';border-bottom:1px solid #f3f4f6;">';
                    // Row 1: time + type badge + category
                    cardHtml += '<div class="flex items-center gap-2 flex-wrap">';
                    cardHtml += '<span class="text-xs text-gray-400">' + esc(log.created_at) + '</span>';
                    cardHtml += '<span class="' + badge + '">' + esc(log.log_type) + '</span>';
                    cardHtml += '<span class="text-xs text-gray-500">' + esc(log.category) + '</span>';
                    cardHtml += '</div>';
                    // Row 2: message
                    cardHtml += '<div class="text-sm text-gray-900 mt-1.5" style="word-break:break-word;">' + esc(log.message) + '</div>';
                    // Row 3: campaign + rule (if any)
                    if (log.campaign_name || log.rule_name) {
                        cardHtml += '<div class="flex items-center gap-3 mt-1.5 text-xs">';
                        if (log.campaign_name && log.campaign_id) {
                            var cUrl = opteeData.dashboardUrl + '?module=dashboard&action=campaign&id=' + log.campaign_id;
                            cardHtml += '<a href="' + cUrl + '" class="text-brand-600 hover:underline">' + esc(log.campaign_name) + '</a>';
                        }
                        if (log.rule_name && log.campaign_id) {
                            var rUrl = opteeData.dashboardUrl + '?module=rules&campaign_id=' + log.campaign_id;
                            cardHtml += '<a href="' + rUrl + '" class="text-brand-600 hover:underline">' + esc(log.rule_name) + '</a>';
                        }
                        cardHtml += '</div>';
                    }
                    // Row 4: details link
                    if (hasCtx) {
                        cardHtml += '<div class="mt-1.5"><a href="#" class="optee-log-details-mob text-xs text-brand-600 hover:underline" data-idx="' + i + '">View Details</a></div>';
                    }
                    cardHtml += '</div>';
                });

                $tbody.html(html);
                $cards.html(cardHtml);

                // Pagination.
                var $pag = $('#optee-logs-pagination');
                var totalPages = res.data.pages;
                var curPage = Logs.page;
                if (totalPages <= 1) {
                    var singleHtml = '<span>' + res.data.total + ' log(s)</span>';
                    $pag.html(singleHtml);
                    $('#optee-logs-pagination-top').html(singleHtml);
                } else {
                    var btnStyle = 'min-width:36px;height:36px;padding:0 8px;cursor:pointer;';
                    var activeCls = 'bg-brand-600 text-white';
                    var normalCls = 'bg-gray-100 text-gray-600 hover:bg-gray-200';
                    var disabledCls = 'bg-gray-50 text-gray-300';

                    var pagHtml = '<span>' + res.data.total + ' log(s) &nbsp; Page ' + curPage + ' of ' + totalPages + '</span><div class="flex gap-1" style="align-items:center;">';

                    // Prev button.
                    if (curPage > 1) {
                        pagHtml += '<button class="rounded text-sm ' + normalCls + '" style="' + btnStyle + '" data-page="' + (curPage - 1) + '">&laquo;</button>';
                    }

                    // Page window: show first, last, and nearby pages with ellipsis.
                    var window = 2;
                    var pages = [];
                    pages.push(1);
                    for (var p = Math.max(2, curPage - window); p <= Math.min(totalPages - 1, curPage + window); p++) {
                        pages.push(p);
                    }
                    if (totalPages > 1) pages.push(totalPages);

                    // Remove dupes and sort.
                    pages = pages.filter(function(v, i, a) { return a.indexOf(v) === i; }).sort(function(a, b) { return a - b; });

                    for (var i = 0; i < pages.length; i++) {
                        if (i > 0 && pages[i] - pages[i - 1] > 1) {
                            pagHtml += '<span style="padding:0 4px;color:#9ca3af;">…</span>';
                        }
                        var cls = pages[i] === curPage ? activeCls : normalCls;
                        pagHtml += '<button class="rounded text-sm ' + cls + '" style="' + btnStyle + '" data-page="' + pages[i] + '">' + pages[i] + '</button>';
                    }

                    // Next button.
                    if (curPage < totalPages) {
                        pagHtml += '<button class="rounded text-sm ' + normalCls + '" style="' + btnStyle + '" data-page="' + (curPage + 1) + '">&raquo;</button>';
                    }

                    pagHtml += '</div>';
                    $pag.html(pagHtml);
                    $('#optee-logs-pagination-top').html(pagHtml);
                    $('#optee-logs-pagination, #optee-logs-pagination-top').find('button[data-page]').on('click', function () {
                        Logs.page = parseInt($(this).data('page'));
                        Logs.load();
                    });
                }
            });
        }
    };

    $(function () { Logs.init(); });
})(jQuery);
</script>
