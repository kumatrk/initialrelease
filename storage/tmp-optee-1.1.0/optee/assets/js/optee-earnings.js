/**
 * Optee Earnings Page
 */
(function($) {
    'use strict';

    var $loading    = $('#optee-earnings-loading');
    var $empty      = $('#optee-earnings-empty');
    var $tableWrap  = $('#optee-earnings-table-wrap');
    var $table      = $('#optee-earnings-table');
    var $yearSelect = $('#optee-earnings-year');
    var $reloadBtn  = $('#optee-earnings-reload');

    var selectedYear = new Date().getFullYear();
    var availableYears = [selectedYear];

    function formatRevenue(val) {
        if (val === undefined || val === null) return '<span class="optee-cell-empty">&mdash;</span>';
        var num = parseFloat(val);
        if (num === 0) return '<span class="optee-cell-empty">$0.00</span>';
        // Negative revenue = a deduction/chargeback (e.g. PIN-UP) — show it in red.
        var cls = num < 0 ? 'optee-earn-cost' : 'optee-earn-revenue';
        var prefix = num < 0 ? '-$' : '$';
        return '<span class="' + cls + '">' + prefix + Math.abs(num).toFixed(2) + '</span>';
    }

    function formatCost(val) {
        if (val === undefined || val === null) return '<span class="optee-cell-empty">&mdash;</span>';
        var num = parseFloat(val);
        if (num === 0) return '<span class="optee-cell-empty">$0.00</span>';
        return '<span class="optee-earn-cost">-$' + num.toFixed(2) + '</span>';
    }

    function formatProfit(val) {
        if (val === undefined || val === null) return '<span class="optee-cell-empty">&mdash;</span>';
        var num = parseFloat(val);
        var cls = num > 0 ? 'optee-earn-revenue' : (num < 0 ? 'optee-earn-cost' : '');
        var prefix = num < 0 ? '-$' : '$';
        return '<span class="' + cls + '">' + prefix + Math.abs(num).toFixed(2) + '</span>';
    }

    function formatRoi(val) {
        if (val === undefined || val === null) return '<span class="optee-cell-empty">&mdash;</span>';
        var num = parseFloat(val);
        var cls = num > 0 ? 'optee-earn-revenue' : (num < 0 ? 'optee-earn-cost' : '');
        return '<span class="' + cls + '">' + num.toFixed(2) + '%</span>';
    }

    function renderYearSelect() {
        var html = '';
        for (var i = 0; i < availableYears.length; i++) {
            var yr = availableYears[i];
            var sel = yr === selectedYear ? ' selected' : '';
            html += '<option value="' + yr + '"' + sel + '>' + yr + '</option>';
        }
        $yearSelect.html(html);
    }

    function renderTable(data) {
        var columns = data.columns || [];
        var rows    = data.rows || [];

        if (data.years) {
            availableYears = data.years;
        }
        if (data.selected_year) {
            selectedYear = data.selected_year;
        }
        renderYearSelect();

        $loading.addClass('hidden');

        if (!columns.length || !rows.length) {
            $empty.removeClass('hidden');
            $tableWrap.addClass('hidden');
            return;
        }

        $empty.addClass('hidden');
        $tableWrap.removeClass('hidden');

        // Separate revenue and cost columns.
        var revCols = [];
        var costCols = [];
        for (var i = 0; i < columns.length; i++) {
            if (columns[i].role === 'cost') {
                costCols.push(columns[i]);
            } else {
                revCols.push(columns[i]);
            }
        }

        // Build header: Date + summary columns (sticky left), then integrations (scrollable).
        var thead = '<tr><th class="optee-earn-date-col">Date</th>';
        thead += '<th class="optee-earn-summary-col">Total Revenue</th>';
        thead += '<th class="optee-earn-summary-col">Total Cost</th>';
        thead += '<th class="optee-earn-summary-col">Profit</th>';
        thead += '<th class="optee-earn-summary-col">ROI</th>';
        for (var r = 0; r < revCols.length; r++) {
            var revLastUp = revCols[r].last_updated || 'Never';
            thead += '<th>' + escHtml(revCols[r].name) +
                ' <span class="optee-info-icon" title="Last updated: ' + escAttr(revLastUp) + '"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></span></th>';
        }
        for (var c = 0; c < costCols.length; c++) {
            var costLastUp = costCols[c].last_updated || 'Never';
            thead += '<th>' + escHtml(costCols[c].name) +
                ' <span class="optee-info-icon" title="Last updated: ' + escAttr(costLastUp) + '"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></span></th>';
        }
        thead += '</tr>';
        $table.find('thead').html(thead);

        // Build body + accumulate sums.
        var tbody = '';
        var sums = {};
        var sumRevenue = 0, sumCost = 0;

        for (var d = 0; d < rows.length; d++) {
            var row = rows[d];
            tbody += '<tr><td class="optee-earn-date-col">' + row.date + '</td>';

            // Summary columns (sticky, right after date).
            tbody += '<td class="optee-earn-summary-col">' + formatRevenue(row.total_revenue) + '</td>';
            tbody += '<td class="optee-earn-summary-col">' + formatCost(row.total_cost) + '</td>';
            tbody += '<td class="optee-earn-summary-col">' + formatProfit(row.profit) + '</td>';
            tbody += '<td class="optee-earn-summary-col">' + formatRoi(row.roi) + '</td>';

            // Revenue columns.
            for (var rv = 0; rv < revCols.length; rv++) {
                var rKey = revCols[rv].type + '__' + revCols[rv].slug;
                tbody += '<td>' + formatRevenue(row[rKey]) + '</td>';
                sums[rKey] = (sums[rKey] || 0) + (parseFloat(row[rKey]) || 0);
            }

            // Cost columns.
            for (var ct = 0; ct < costCols.length; ct++) {
                var cKey = costCols[ct].type + '__' + costCols[ct].slug;
                tbody += '<td>' + formatCost(row[cKey]) + '</td>';
                sums[cKey] = (sums[cKey] || 0) + (parseFloat(row[cKey]) || 0);
            }

            tbody += '</tr>';

            sumRevenue += parseFloat(row.total_revenue) || 0;
            sumCost += parseFloat(row.total_cost) || 0;
        }

        // Sum row.
        if (rows.length) {
            var sumProfit = sumRevenue - sumCost;
            var sumRoi = sumCost > 0 ? ((sumRevenue - sumCost) / sumCost) * 100 : 0;

            tbody += '<tr class="optee-earn-summary-row"><td class="optee-earn-date-col"><span class="optee-summary-label">Total</span></td>';
            tbody += '<td class="optee-earn-summary-col">' + formatRevenue(sumRevenue) + '</td>';
            tbody += '<td class="optee-earn-summary-col">' + formatCost(sumCost) + '</td>';
            tbody += '<td class="optee-earn-summary-col">' + formatProfit(sumProfit) + '</td>';
            tbody += '<td class="optee-earn-summary-col">' + formatRoi(sumRoi) + '</td>';
            for (var sr = 0; sr < revCols.length; sr++) {
                var srKey = revCols[sr].type + '__' + revCols[sr].slug;
                tbody += '<td>' + formatRevenue(sums[srKey]) + '</td>';
            }
            for (var sc = 0; sc < costCols.length; sc++) {
                var scKey = costCols[sc].type + '__' + costCols[sc].slug;
                tbody += '<td>' + formatCost(sums[scKey]) + '</td>';
            }
            tbody += '</tr>';
        }

        $table.find('tbody').html(tbody);

        // Compute sticky left offsets for the 4 summary columns (after date).
        setTimeout(function() {
            var dateTh = $table.find('thead th.optee-earn-date-col')[0];
            if (!dateTh) return;
            var dateWidth = dateTh.offsetWidth;
            var headerCells = $table.find('thead th.optee-earn-summary-col');
            if (!headerCells.length) return;
            var lefts = [];
            var acc = dateWidth;
            for (var si = 0; si < headerCells.length; si++) {
                lefts[si] = acc;
                acc += headerCells[si].offsetWidth;
            }
            $table.find('.optee-earn-summary-col').each(function() {
                var cell = this;
                var row = cell.parentNode;
                var idx = 0;
                var summCells = row.querySelectorAll('.optee-earn-summary-col');
                for (var j = 0; j < summCells.length; j++) {
                    if (summCells[j] === cell) { idx = j; break; }
                }
                cell.style.left = lefts[idx] + 'px';
            });
        }, 0);
    }

    function loadEarnings() {
        $loading.removeClass('hidden');
        $empty.addClass('hidden');
        $tableWrap.addClass('hidden');

        Optee.Ajax.post('optee_get_earnings', { year: selectedYear })
            .done(function(res) {
                if (res.success) {
                    renderTable(res.data);
                } else {
                    $loading.addClass('hidden');
                    Optee.Toast.show(res.data.message || 'Failed to load earnings.', 'error');
                }
            })
            .fail(function() {
                $loading.addClass('hidden');
                Optee.Toast.show('Network error loading earnings.', 'error');
            });
    }

    var $fetchBanner = $('<div class="optee-fetch-banner" style="display:none;padding:10px 16px;margin-bottom:12px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:13px;align-items:center;gap:8px;"><span class="optee-spinner" style="width:16px;height:16px;border-width:2px;"></span> Fetching latest data from all modules...</div>');
    $('#optee-earnings-container').before($fetchBanner);

    function reloadEarnings() {
        $reloadBtn.prop('disabled', true).html(
            '<span class="optee-spinner" style="width:16px;height:16px;border-width:2px;vertical-align:middle;"></span> Fetching...'
        );
        $fetchBanner.css('display', 'flex');

        Optee.Ajax.post('optee_fetch_earnings_now', { year: selectedYear })
            .done(function(res) {
                if (res.success) {
                    renderTable(res.data);
                    var count = (res.data.fetched || []).length;
                    Optee.Toast.show('Fetched earnings from ' + count + ' module' + (count !== 1 ? 's' : '') + '.', 'success');
                } else {
                    Optee.Toast.show(res.data.message || 'Failed to fetch earnings.', 'error');
                }
            })
            .fail(function() {
                Optee.Toast.show('Network error fetching earnings.', 'error');
            })
            .always(function() {
                $fetchBanner.hide();
                $reloadBtn.prop('disabled', false).html(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg> Reload'
                );
            });
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Events.
    $reloadBtn.on('click', reloadEarnings);

    $yearSelect.on('change', function() {
        selectedYear = parseInt($(this).val(), 10);
        loadEarnings();
    });

    // Init.
    loadEarnings();

    // Auto-fetch if ?fetch=1 in URL (from command palette).
    if (new URLSearchParams(window.location.search).get('fetch') === '1') {
        setTimeout(reloadEarnings, 500);
    }

})(jQuery);
