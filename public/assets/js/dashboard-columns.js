(function () {
    'use strict';

    var BASE = ['campaign', 'traffic_source', 'views', 'lp_clicks', 'direct', 'conversions', 'cost', 'revenue', 'profit', 'roi', 'epc', 'ctr', 'cr', 'actions'];
    var EXTRA = ['clicks', 'cpc', 'cpm', 'ecpm'];
    var METRICS = ['views', 'lp_clicks', 'direct', 'clicks', 'conversions', 'cost', 'revenue', 'profit', 'roi', 'epc', 'cpc', 'cpm', 'ecpm', 'ctr', 'cr'];
    var LABELS = {
        views: 'Views',
        lp_clicks: 'Clicks',
        direct: 'Direct',
        clicks: 'LP + direct',
        conversions: 'Conv',
        cost: 'Cost',
        revenue: 'Revenue',
        profit: 'Profit',
        roi: 'ROI',
        epc: 'EPC',
        cpc: 'CPC',
        cpm: 'CPM',
        ecpm: 'eCPM',
        ctr: 'CTR',
        cr: 'CR'
    };
    var DEFAULTS = ['views', 'lp_clicks', 'direct', 'conversions', 'cost', 'revenue', 'profit', 'roi', 'epc', 'ctr', 'cr'];
    var STORAGE_KEY = 'kuma_dashboard_columns';
    var columns = null;

    function allowed(list) {
        var seen = {};
        var out = [];
        (list || []).forEach(function (key) {
            if (METRICS.indexOf(key) === -1 || seen[key]) return;
            seen[key] = true;
            out.push(key);
        });
        return out.length ? out : DEFAULTS.slice();
    }

    function loadColumns() {
        var cfg = window.KUMA_UI_PREFS_CONFIG || {};
        var saved = cfg.reportColumns && cfg.reportColumns.hasSaved ? cfg.reportColumns.dashboard : null;
        if (Array.isArray(saved) && saved.length) {
            return allowed(saved);
        }
        try {
            var local = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
            if (Array.isArray(local) && local.length) return allowed(local);
        } catch (e) { /* ignore */ }
        return allowed(cfg.reportColumns && cfg.reportColumns.dashboard);
    }

    function current() {
        return columns ? columns.slice() : null;
    }

    function currencyCode() {
        var body = document.getElementById('dashboard-campaigns-body');
        return (body && body.getAttribute('data-currency')) || 'USD';
    }

    function fmtRate(n) {
        var num = Number(n);
        if (!Number.isFinite(num)) return '—';
        var digits = Math.abs(num) > 0 && Math.abs(num) < 0.01 ? 4 : 2;
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: currencyCode(),
                minimumFractionDigits: digits,
                maximumFractionDigits: digits
            }).format(num);
        } catch (e) {
            return '$' + num.toFixed(digits);
        }
    }

    function metricText(tr, col) {
        if (!tr.hasAttribute('data-views')) return '—';
        var views = Number(tr.getAttribute('data-views')) || 0;
        var lp = Number(tr.getAttribute('data-lp')) || 0;
        var direct = Number(tr.getAttribute('data-direct')) || 0;
        var cost = Number(tr.getAttribute('data-cost')) || 0;
        var revenue = Number(tr.getAttribute('data-revenue')) || 0;
        var clicks = lp + direct;
        if (col === 'clicks') return clicks.toLocaleString();
        if (col === 'cpc') return fmtRate(clicks > 0 ? cost / clicks : 0);
        if (col === 'cpm') return fmtRate(views > 0 ? (cost / views) * 1000 : 0);
        if (col === 'ecpm') return fmtRate(views > 0 ? (revenue / views) * 1000 : 0);
        return '—';
    }

    function reorder(row, order, tag) {
        order.forEach(function (col) {
            var el = row.querySelector(tag + '[data-col="' + col + '"]');
            if (el) row.appendChild(el);
        });
    }

    function decorate(table) {
        var head = table.tHead && table.tHead.rows[0];
        var body = table.tBodies[0];
        if (!head || !body) return;

        EXTRA.forEach(function (col) {
            if (head.querySelector('th[data-col="' + col + '"]')) return;
            var th = document.createElement('th');
            th.setAttribute('data-col', col);
            th.textContent = LABELS[col];
            var actions = head.querySelector('th[data-col="actions"]');
            head.insertBefore(th, actions);
        });

        Array.prototype.forEach.call(body.rows, function (tr) {
            if (tr.querySelector('td[data-col="clicks"]')) return;
            if (tr.cells.length !== BASE.length) return;
            for (var i = 0; i < BASE.length; i++) {
                tr.cells[i].setAttribute('data-col', BASE[i]);
            }
            var actions = tr.querySelector('td[data-col="actions"]');
            EXTRA.forEach(function (col) {
                var td = document.createElement('td');
                td.setAttribute('data-col', col);
                td.textContent = metricText(tr, col);
                tr.insertBefore(td, actions);
            });
        });
    }

    function apply(root, skipPicker) {
        if (!columns) columns = loadColumns();
        var scope = root || document;
        var table = scope.querySelector('.desktop-only table') || document.querySelector('#dashboard-campaigns-content .desktop-only table');
        if (!table) return;
        decorate(table);

        var hidden = METRICS.filter(function (col) { return columns.indexOf(col) === -1; });
        var order = ['campaign', 'traffic_source'].concat(columns).concat(hidden).concat(['actions']);
        var visible = {};
        columns.forEach(function (col) { visible[col] = true; });

        reorder(table.tHead.rows[0], order, 'th');
        Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th) {
            var col = th.getAttribute('data-col');
            if (col === 'campaign' || col === 'traffic_source' || col === 'actions') {
                th.style.display = '';
                return;
            }
            th.style.display = visible[col] ? 'table-cell' : 'none';
        });
        Array.prototype.forEach.call(table.tBodies[0].rows, function (tr) {
            if (!tr.querySelector('td[data-col="campaign"]')) return;
            reorder(tr, order, 'td');
            Array.prototype.forEach.call(tr.cells, function (td) {
                var col = td.getAttribute('data-col');
                if (col === 'campaign' || col === 'traffic_source' || col === 'actions') {
                    td.style.display = '';
                    return;
                }
                td.style.display = visible[col] ? 'table-cell' : 'none';
            });
        });
        if (!skipPicker) renderPicker();
    }

    function persist() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(columns));
        } catch (e) { /* ignore */ }
        var cfg = window.KUMA_UI_PREFS_CONFIG;
        if (!window.KumaUiPrefs || !cfg) return;
        var payload = { dashboard: columns.slice() };
        var statsCols = null;
        if (cfg.reportColumns && cfg.reportColumns.hasSaved && Array.isArray(cfg.reportColumns.stats)) {
            statsCols = cfg.reportColumns.stats;
        } else {
            try {
                var statsPrefs = JSON.parse(localStorage.getItem('stats_v2_prefs') || 'null');
                if (statsPrefs && Array.isArray(statsPrefs.columns) && statsPrefs.columns.length) {
                    statsCols = statsPrefs.columns;
                }
            } catch (e) { /* ignore */ }
        }
        if (statsCols) payload.stats = statsCols;
        window.KumaUiPrefs.saveReportColumns(payload).then(function (data) {
            if (!data || !data.ok || !cfg.reportColumns) return;
            cfg.reportColumns.hasSaved = true;
            cfg.reportColumns.dashboard = columns.slice();
            if (data.report_columns && Array.isArray(data.report_columns.stats)) {
                cfg.reportColumns.stats = data.report_columns.stats;
            }
        });
    }

    function move(col, dir) {
        var i = columns.indexOf(col);
        var j = i + dir;
        if (i < 0 || j < 0 || j >= columns.length) return;
        var tmp = columns[i];
        columns[i] = columns[j];
        columns[j] = tmp;
        persist();
        apply(document.getElementById('dashboard-campaigns-content'));
    }

    function renderPicker() {
        var picker = document.getElementById('dashboard-column-picker');
        if (!picker) return;
        var ordered = columns.concat(METRICS.filter(function (col) { return columns.indexOf(col) === -1; }));
        picker.innerHTML = ordered.map(function (col) {
            var checked = columns.indexOf(col) !== -1 ? ' checked' : '';
            return '<label style="display:inline-flex;align-items:center;gap:4px;">' +
                '<button type="button" class="dash-col-move" data-dir="-1" data-col="' + col + '" aria-label="Move ' + LABELS[col] + ' earlier">↑</button>' +
                '<button type="button" class="dash-col-move" data-dir="1" data-col="' + col + '" aria-label="Move ' + LABELS[col] + ' later">↓</button>' +
                '<input type="checkbox" data-col="' + col + '"' + checked + '> ' + LABELS[col] +
                '</label>';
        }).join('');
    }

    function init() {
        columns = loadColumns();
        var picker = document.getElementById('dashboard-column-picker');
        var toggle = document.getElementById('dashboard-customize-columns');
        if (toggle && picker) {
            toggle.addEventListener('click', function () {
                var open = picker.hasAttribute('hidden');
                if (open) {
                    picker.removeAttribute('hidden');
                    picker.style.display = 'flex';
                    renderPicker();
                } else {
                    picker.setAttribute('hidden', '');
                    picker.style.display = 'none';
                }
            });
            picker.addEventListener('click', function (e) {
                var btn = e.target.closest('.dash-col-move');
                if (!btn) return;
                e.preventDefault();
                move(btn.getAttribute('data-col'), Number(btn.getAttribute('data-dir')));
            });
            picker.addEventListener('change', function (e) {
                var input = e.target;
                if (!input || !input.getAttribute || !input.getAttribute('data-col')) return;
                var col = input.getAttribute('data-col');
                if (input.checked) {
                    if (columns.indexOf(col) === -1) columns.push(col);
                } else {
                    var next = columns.filter(function (key) { return key !== col; });
                    if (!next.length) {
                        input.checked = true;
                        return;
                    }
                    columns = next;
                }
                try { persist(); } catch (e) { /* keep the table change if saving fails */ }
                apply(document.getElementById('dashboard-campaigns-content'), true);
            });
        }
    }

    window.KumaDashboardColumns = {
        apply: apply,
        current: current
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
