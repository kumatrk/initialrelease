(function () {
    'use strict';

    const app = document.getElementById('stats-v2-app');
    if (!app) return;

    const apiBase = app.dataset.apiBase;
    const savedViewsApi = app.dataset.savedViewsApi || '';
    const currency = app.dataset.currency || 'USD';
    const appToday = app.dataset.today || app.dataset.dateTo || '';

    function addDays(isoDate, days) {
        const parts = isoDate.split('-').map(Number);
        const dt = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
        dt.setUTCDate(dt.getUTCDate() + days);
        return dt.toISOString().slice(0, 10);
    }

    function daysBetween(from, to) {
        const start = new Date(from + 'T00:00:00Z');
        const end = new Date(to + 'T00:00:00Z');
        return Math.round((end - start) / 86400000) + 1;
    }
    const PREFS_KEY = 'stats_v2_prefs';
    const SAVED_VIEWS_KEY = 'stats_v2_saved_views_v1';
    const defaultColumns = ['visitors', 'lp_clicks', 'ctr', 'conversions', 'optins', 'cr', 'cost', 'revenue', 'profit', 'roi'];
    const ALL_METRIC_COLS = defaultColumns.slice();

    function ensureColumnOrder(columns) {
        const set = new Set(Array.isArray(columns) ? columns : []);
        if (!set.has('ctr') && set.has('lp_clicks')) {
            const next = [];
            columns.forEach((col) => {
                next.push(col);
                if (col === 'lp_clicks') next.push('ctr');
            });
            return next;
        }
        if (!set.has('ctr')) {
            return columns.concat(['ctr']);
        }
        return columns;
    }

    function loadPrefs() {
        try {
            const raw = localStorage.getItem(PREFS_KEY);
            if (!raw) return {};
            return JSON.parse(raw);
        } catch (e) {
            return {};
        }
    }

    const PER_PAGE_OPTIONS = [25, 50, 75, 100, 200];
    const SORTABLE_COLS = {
        name: 'name',
        visitors: 'visitors',
        lp_clicks: 'lp_clicks',
        ctr: 'ctr',
        conversions: 'conversions',
        optins: 'optins',
        cr: 'cr',
        cost: 'cost',
        revenue: 'revenue',
        profit: 'profit',
        roi: 'roi',
    };

    function normalizePerPage(value) {
        const n = parseInt(value, 10);
        return PER_PAGE_OPTIONS.includes(n) ? n : 25;
    }

    function savePrefs() {
        localStorage.setItem(PREFS_KEY, JSON.stringify({
            tab: state.tab,
            dimensions: state.dimensions,
            columns: state.visibleColumns,
            chartCollapsed: state.chartCollapsed,
            perPage: state.perPage,
            sort: state.sort,
            order: state.order,
        }));
    }

    const prefs = loadPrefs();

    let state = {
        campaignId: parseInt(app.dataset.campaignId, 10) || 0,
        dateFrom: app.dataset.dateFrom,
        dateTo: app.dataset.dateTo,
        tab: prefs.tab || 'overview',
        dimensions: Array.isArray(prefs.dimensions) && prefs.dimensions.length ? prefs.dimensions : ['offer'],
        visibleColumns: ensureColumnOrder(
            Array.isArray(prefs.columns) && prefs.columns.length ? prefs.columns : defaultColumns.slice()
        ),
        availableDimensions: [],
        breakdownPage: 1,
        perPage: normalizePerPage(prefs.perPage),
        sort: typeof prefs.sort === 'string' && prefs.sort ? prefs.sort : 'clicks',
        order: prefs.order === 'asc' ? 'asc' : 'desc',
        sortByUser: !!(prefs.sort && prefs.sort !== 'clicks'),
        parentStacks: {},
        exportRows: [],
        chartOverview: null,
        chartMain: null,
        chartCollapsed: !!prefs.chartCollapsed,
        comparePeriod: false,
        density: localStorage.getItem('stats_v2_density') || 'comfortable',
        breakdownLoading: false,
        filtersRefreshing: false,
        breakdownSearch: '',
        trafficSourceId: null,
        offerId: null,
        landingPageId: null,
        tokenParam: '',
        tokenValue: '',
        campaignMeta: null,
        savedViews: [],
        savedViewsUseServer: false,
    };

    const fmtMoney = (n) => {
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(n);
        } catch (e) {
            return '$' + Number(n).toFixed(2);
        }
    };

    const fmtPct = (n) => Number(n).toFixed(2) + '%';

    const KPI_SVGS = {
        visitors: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        clicks: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/><path d="M13 13l6 6"/></svg>',
        conversions: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
        cr: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        cost: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
        revenue: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        profit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        roi: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>',
        cpc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
        epc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        cpa: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="2"/></svg>',
        roas: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        ppclick: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        lpctr: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        margin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9"/><path d="M21 3v6h-6"/></svg>',
        cpv: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
        epv: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    };

    function kpiIconHtml(iconKey, toneClass) {
        const svg = KPI_SVGS[iconKey] || KPI_SVGS.visitors;
        return `<span class="stats-v2-kpi-icon ${toneClass}" aria-hidden="true">${svg}</span>`;
    }

    const qs = (params) => {
        const u = new URLSearchParams(params);
        return apiBase + '?' + u.toString();
    };

    async function fetchJson(params) {
        const signal = requestAbort ? requestAbort.signal : undefined;
        const doFetch = async () => {
            const res = await fetch(qs(params), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal,
            });
            let data;
            try {
                data = await res.json();
            } catch (parseErr) {
                // Aborted / killed responses often have empty bodies — treat as abort, not a user error.
                if (signal && signal.aborted) {
                    const abortErr = new Error('Aborted');
                    abortErr.name = 'AbortError';
                    throw abortErr;
                }
                throw parseErr;
            }
            return { res, data };
        };

        let { res, data } = await doFetch();
        if (data && data.busy) {
            await new Promise((r) => setTimeout(r, 800));
            ({ res, data } = await doFetch());
        }
        if (!data.ok) {
            throw new Error(data.error || 'Request failed');
        }
        return data.data;
    }

    function isAbortError(err) {
        if (!err) {
            return false;
        }
        if (err.name === 'AbortError' || err.code === 20) {
            return true;
        }
        const msg = String(err.message || '');
        return /aborted|AbortError/i.test(msg);
    }

    /** Turn raw API/SQL errors into a short user-facing line. Full text stays in console. */
    function friendlyErrorMessage(raw) {
        const msg = String(raw || '').trim();
        if (!msg) {
            return 'Something went wrong loading stats.';
        }
        if (/sql_mode|only_full_group_by|SQLSTATE|mysqli|GROUP BY clause|syntax error|Query failed/i.test(msg)) {
            return 'Couldn’t load this report. Try again or change your filters.';
        }
        if (msg.length > 140) {
            return 'Couldn’t load stats. Please try again.';
        }
        return msg;
    }

    function clearStatsFlash() {
        const flash = document.getElementById('stats-v2-flash');
        if (!flash) {
            return;
        }
        flash.classList.add('hidden');
        flash.setAttribute('hidden', '');
        flash.innerHTML = '';
    }

    function showStatsError(raw) {
        const text = friendlyErrorMessage(raw);
        if (raw && String(raw) !== text) {
            console.error('[Campaign Stats]', raw);
        } else {
            console.error('[Campaign Stats]', text);
        }
        const flash = document.getElementById('stats-v2-flash');
        if (!flash) {
            return text;
        }
        flash.innerHTML = `<span class="stats-v2-flash-text">${escapeHtml(text)}</span>`
            + `<button type="button" class="stats-v2-flash-dismiss" aria-label="Dismiss">&times;</button>`;
        flash.classList.remove('hidden');
        flash.removeAttribute('hidden');
        flash.classList.add('error');
        const btn = flash.querySelector('.stats-v2-flash-dismiss');
        if (btn) {
            btn.addEventListener('click', clearStatsFlash, { once: true });
        }
        return text;
    }

    /** Cancel in-flight stats fetches (filter change or leaving the page). */
    function resetRequestAbort() {
        if (requestAbort) {
            requestAbort.abort();
        }
        requestAbort = new AbortController();
    }

    let requestAbort = new AbortController();

    window.addEventListener('pagehide', () => {
        if (requestAbort) {
            requestAbort.abort();
        }
    });


    function baseParams() {
        const p = {
            campaign_id: state.campaignId,
            date_from: state.dateFrom,
            date_to: state.dateTo,
        };
        if (state.trafficSourceId) {
            p.traffic_source_id = state.trafficSourceId;
        }
        if (state.offerId) {
            p.offer_id = state.offerId;
        }
        if (state.landingPageId) {
            p.landing_page_id = state.landingPageId;
        }
        if (state.tokenParam && state.tokenValue) {
            p.token_param = state.tokenParam;
            p.token_value = state.tokenValue;
        }
        return p;
    }

    function countAdvancedFilters() {
        let n = [state.trafficSourceId, state.offerId, state.landingPageId].filter(Boolean).length;
        if (state.tokenParam && state.tokenValue) {
            n += 1;
        }
        return n;
    }

    function updateAdvancedToggleBadge() {
        const btn = document.getElementById('stats-v2-advanced-toggle');
        if (!btn) return;
        const n = countAdvancedFilters();
        btn.textContent = n > 0 ? `Advanced filters (${n})` : 'Advanced filters';
    }

    function renderSelectFilter(wrapId, selId, items, allLabel, stateKey) {
        const wrap = document.getElementById(wrapId);
        const sel = document.getElementById(selId);
        if (!wrap || !sel) return;

        const list = items || [];
        const show = list.length > 0;
        wrap.classList.toggle('hidden', !show);
        if (!show) {
            state[stateKey] = null;
            sel.innerHTML = `<option value="">${allLabel}</option>`;
            return;
        }

        const current = state[stateKey] ? String(state[stateKey]) : '';
        sel.innerHTML = `<option value="">${allLabel}</option>`;
        list.forEach((item) => {
            const opt = document.createElement('option');
            opt.value = String(item.id);
            opt.textContent = item.name;
            sel.appendChild(opt);
        });
        if (current && [...sel.options].some((o) => o.value === current)) {
            sel.value = current;
        } else {
            sel.value = '';
            state[stateKey] = null;
        }
    }

    function renderMetaFilters(meta) {
        renderSelectFilter(
            'stats-v2-traffic-source-wrap',
            'stats-v2-traffic-source',
            meta && meta.show_traffic_source_filter ? meta.traffic_sources : [],
            'All traffic sources',
            'trafficSourceId'
        );
        renderSelectFilter(
            'stats-v2-offer-wrap',
            'stats-v2-offer',
            meta ? meta.offers : [],
            'All offers',
            'offerId'
        );
        renderSelectFilter(
            'stats-v2-landing-wrap',
            'stats-v2-landing',
            meta ? meta.landing_pages : [],
            'All landing pages',
            'landingPageId'
        );
        renderTokenFilter(meta);
        updateAdvancedToggleBadge();
    }

    function renderTokenFilter(meta) {
        const wrap = document.getElementById('stats-v2-token-wrap');
        const valueWrap = document.getElementById('stats-v2-token-value-wrap');
        const sel = document.getElementById('stats-v2-token-param');
        if (!wrap || !sel) return;

        const tokens = (meta && meta.filter_tokens) || [];
        wrap.classList.toggle('hidden', tokens.length === 0);
        if (tokens.length === 0) {
            state.tokenParam = '';
            state.tokenValue = '';
            sel.innerHTML = '<option value="">Any token</option>';
            if (valueWrap) valueWrap.classList.add('hidden');
            return;
        }

        const current = state.tokenParam || '';
        sel.innerHTML = '<option value="">Any token</option>';
        const groups = { traffic_source: 'Traffic tokens', campaign: 'Custom tokens', tracker: 'Tracker vars', core: 'Other' };
        const byGroup = {};
        tokens.forEach((t) => {
            const g = t.group || 'core';
            if (!byGroup[g]) byGroup[g] = [];
            byGroup[g].push(t);
        });
        Object.keys(groups).forEach((g) => {
            const items = byGroup[g];
            if (!items || !items.length) return;
            const og = document.createElement('optgroup');
            og.label = groups[g];
            items.forEach((t) => {
                const opt = document.createElement('option');
                opt.value = t.key;
                opt.textContent = t.label;
                og.appendChild(opt);
            });
            sel.appendChild(og);
        });

        if (current && [...sel.options].some((o) => o.value === current)) {
            sel.value = current;
        } else {
            sel.value = '';
            state.tokenParam = '';
            state.tokenValue = '';
        }

        const showValue = !!state.tokenParam;
        if (valueWrap) valueWrap.classList.toggle('hidden', !showValue);
        const valInput = document.getElementById('stats-v2-token-value');
        if (valInput && state.tokenValue) {
            valInput.value = state.tokenValue;
        }
        if (showValue) {
            loadTokenValues(state.tokenParam);
        }
    }

    async function loadTokenValues(tokenParam) {
        const list = document.getElementById('stats-v2-token-values-list');
        if (!list || !tokenParam) return;
        list.innerHTML = '';
        try {
            const data = await fetchJson({
                campaign_id: state.campaignId,
                date_from: state.dateFrom,
                date_to: state.dateTo,
                action: 'token_values',
                token_param: tokenParam,
            });
            (data.values || []).forEach((v) => {
                const opt = document.createElement('option');
                opt.value = v;
                list.appendChild(opt);
            });
        } catch (e) {
            /* autocomplete optional */
        }
    }

    async function loadCampaignMeta() {
        const meta = await fetchJson({ ...baseParams(), action: 'meta' });
        state.campaignMeta = meta;
        renderMetaFilters(meta);
        return meta;
    }

    function syncUrl() {
        const p = new URLSearchParams(window.location.search);
        p.set('page', 'campaign-stats');
        p.set('campaign_id', state.campaignId);
        p.set('date_from', state.dateFrom);
        p.set('date_to', state.dateTo);
        window.history.replaceState({}, '', '?' + p.toString());
    }

    function getCsrfToken() {
        return (window.KUMA_THEME_CONFIG && window.KUMA_THEME_CONFIG.csrfToken) || '';
    }

    function viewConfigPayload() {
        return {
            dimensions: state.dimensions.slice(),
            columns: state.visibleColumns.slice(),
            density: state.density,
            showTotals: document.getElementById('stats-v2-show-totals')?.checked !== false,
            trafficSourceId: state.trafficSourceId || null,
            offerId: state.offerId || null,
            landingPageId: state.landingPageId || null,
            tokenParam: state.tokenParam || null,
            tokenValue: state.tokenValue || null,
        };
    }

    function loadAllSavedViewsLocal() {
        try {
            return JSON.parse(localStorage.getItem(SAVED_VIEWS_KEY) || '{}');
        } catch (e) {
            return {};
        }
    }

    function getSavedViewsLocalForCampaign() {
        const all = loadAllSavedViewsLocal();
        return all[String(state.campaignId)] || [];
    }

    function persistSavedViewsLocalForCampaign(views) {
        const all = loadAllSavedViewsLocal();
        all[String(state.campaignId)] = views;
        localStorage.setItem(SAVED_VIEWS_KEY, JSON.stringify(all));
    }

    async function postSavedViews(action, fields) {
        if (!savedViewsApi) {
            throw new Error('Saved views API not configured');
        }
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('app_csrf', getCsrfToken());
        body.set('campaign_id', String(state.campaignId));
        Object.entries(fields).forEach(([k, v]) => {
            body.set(k, typeof v === 'string' ? v : JSON.stringify(v));
        });
        const res = await fetch(savedViewsApi, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body,
        });
        const data = await res.json();
        if (!data.ok) {
            const err = new Error(data.error || 'Request failed');
            err.status = res.status;
            err.fallback = data.fallback;
            throw err;
        }
        return data.data;
    }

    async function loadSavedViews() {
        if (!savedViewsApi || !state.campaignId) {
            state.savedViewsUseServer = false;
            state.savedViews = getSavedViewsLocalForCampaign();
            return state.savedViews;
        }

        try {
            const u = new URL(savedViewsApi, window.location.origin);
            u.searchParams.set('campaign_id', String(state.campaignId));
            const res = await fetch(u.toString(), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (!data.ok) {
                throw new Error(data.error || 'Failed to load saved views');
            }
            state.savedViewsUseServer = true;
            state.savedViews = data.data.views || [];

            const localViews = getSavedViewsLocalForCampaign();
            if (state.savedViews.length === 0 && localViews.length > 0) {
                for (const local of localViews) {
                    try {
                        const saved = await postSavedViews('save', {
                            name: local.name,
                            config: {
                                dimensions: local.dimensions,
                                columns: local.columns,
                                density: local.density,
                                showTotals: local.showTotals,
                                trafficSourceId: local.trafficSourceId || null,
                                offerId: local.offerId || null,
                                landingPageId: local.landingPageId || null,
                                tokenParam: local.tokenParam || null,
                                tokenValue: local.tokenValue || null,
                            },
                        });
                        if (saved.view) {
                            state.savedViews.push(saved.view);
                        }
                    } catch (e) {
                        if (e.status === 409) {
                            continue;
                        }
                    }
                }
                if (state.savedViews.length > 0) {
                    localStorage.removeItem(SAVED_VIEWS_KEY);
                }
            }

            return state.savedViews;
        } catch (e) {
            state.savedViewsUseServer = false;
            state.savedViews = getSavedViewsLocalForCampaign();
            return state.savedViews;
        }
    }

    function getSavedViewsForCampaign() {
        return state.savedViews;
    }

    function renderSavedViewSelect() {
        const sel = document.getElementById('stats-v2-saved-view');
        if (!sel) return;
        const current = sel.value;
        sel.innerHTML = '<option value="">Saved views…</option>';
        getSavedViewsForCampaign().forEach((view) => {
            const opt = document.createElement('option');
            opt.value = String(view.id);
            opt.textContent = view.name;
            sel.appendChild(opt);
        });
        if (current && [...sel.options].some((o) => o.value === current)) {
            sel.value = current;
        }
    }

    function applyAdvancedFiltersFromView(view) {
        const keys = [
            ['trafficSourceId', 'stats-v2-traffic-source'],
            ['offerId', 'stats-v2-offer'],
            ['landingPageId', 'stats-v2-landing'],
        ];
        keys.forEach(([key, selId]) => {
            const sel = document.getElementById(selId);
            const val = view[key] || null;
            state[key] = val;
            if (sel) sel.value = val ? String(val) : '';
        });
        state.tokenParam = view.tokenParam || '';
        state.tokenValue = view.tokenValue || '';
        const tokenSel = document.getElementById('stats-v2-token-param');
        const tokenVal = document.getElementById('stats-v2-token-value');
        if (tokenSel) tokenSel.value = state.tokenParam;
        if (tokenVal) tokenVal.value = state.tokenValue;
        const valueWrap = document.getElementById('stats-v2-token-value-wrap');
        if (valueWrap) valueWrap.classList.toggle('hidden', !state.tokenParam);
        if (state.tokenParam) {
            loadTokenValues(state.tokenParam);
        }
        updateAdvancedToggleBadge();
    }

    function viewHasAdvancedFilters(view) {
        return !!(view.trafficSourceId || view.offerId || view.landingPageId
            || (view.tokenParam && view.tokenValue));
    }

    function applySavedView(view) {
        state.dimensions = Array.isArray(view.dimensions) ? view.dimensions.slice() : ['offer'];
        state.visibleColumns = ensureColumnOrder(
            Array.isArray(view.columns) && view.columns.length
                ? view.columns.slice()
                : defaultColumns.slice()
        );
        state.density = view.density || 'comfortable';
        const showTotals = document.getElementById('stats-v2-show-totals');
        if (showTotals) {
            showTotals.checked = view.showTotals !== false;
        }
        applyAdvancedFiltersFromView(view);
        document.getElementById('stats-v2-density').value = state.density;
        document.getElementById('stats-v2-breakdown-table').classList.toggle('density-compact', state.density === 'compact');
        localStorage.setItem('stats_v2_density', state.density);
        renderDimensionTags();
        populateDimensionSelect();
        applyColumnVisibility();
        savePrefs();
        syncDimensionPresetActiveState();
        if (viewHasAdvancedFilters(view)) {
            scheduleRefreshAll();
        }
    }

    function setBreakdownLoading(loading) {
        state.breakdownLoading = loading;
        const btn = document.getElementById('stats-v2-run-report');
        if (btn) {
            btn.disabled = loading;
            btn.textContent = loading ? 'Loading…' : 'Run Report';
        }
    }

    function setFiltersRefreshing(loading) {
        state.filtersRefreshing = !!loading;
        const applyBtn = document.getElementById('stats-v2-apply-filters');
        if (applyBtn) {
            applyBtn.disabled = !!loading;
            applyBtn.setAttribute('aria-busy', loading ? 'true' : 'false');
            applyBtn.textContent = loading ? 'Loading…' : 'Apply';
            applyBtn.classList.toggle('is-loading', !!loading);
        }
        document.querySelectorAll('.stats-v2-presets [data-preset]').forEach((btn) => {
            btn.disabled = !!loading;
        });
    }

    function readAdvancedFiltersFromDom() {
        const readSel = (selId, stateKey) => {
            const sel = document.getElementById(selId);
            if (sel && !sel.closest('.hidden')) {
                const v = sel.value;
                state[stateKey] = v ? parseInt(v, 10) : null;
            }
        };
        readSel('stats-v2-traffic-source', 'trafficSourceId');
        readSel('stats-v2-offer', 'offerId');
        readSel('stats-v2-landing', 'landingPageId');
        const tokenSel = document.getElementById('stats-v2-token-param');
        const tokenVal = document.getElementById('stats-v2-token-value');
        if (tokenSel && !tokenSel.closest('.hidden')) {
            state.tokenParam = tokenSel.value || '';
        }
        if (tokenVal && !tokenVal.closest('.hidden')) {
            state.tokenValue = (tokenVal.value || '').trim();
        }
        if (!state.tokenParam) {
            state.tokenValue = '';
        }
        updateAdvancedToggleBadge();
    }

    function readFiltersFromDom() {
        state.campaignId = parseInt(document.getElementById('stats-v2-campaign').value, 10);
        state.dateFrom = document.getElementById('stats-v2-date-from').value;
        state.dateTo = document.getElementById('stats-v2-date-to').value;
        readAdvancedFiltersFromDom();
        syncUrl();
    }

    const DIMENSION_PRESETS = [
        {
            group: 'Essentials',
            items: [
                { value: 'offer', label: 'By offer' },
                { value: 'landing', label: 'By landing page' },
                { value: 'landing,offer', label: 'Landing → Offer' },
                { value: 'offer,landing', label: 'Offer → Landing' },
                { value: 'date', label: 'By date' },
                { value: 'offer,date', label: 'Offer → Date' },
                { value: 'landing,date', label: 'Landing → Date' },
            ],
        },
        {
            group: 'Audience',
            items: [
                { value: 'country', label: 'By country' },
                { value: 'offer,country', label: 'Offer → Country' },
                { value: 'landing,country', label: 'Landing → Country' },
                { value: 'device', label: 'By device' },
                { value: 'offer,device', label: 'Offer → Device' },
                { value: 'os', label: 'By OS' },
                { value: 'country,device', label: 'Country → Device' },
                { value: 'region', label: 'By state / region' },
            ],
        },
        {
            group: 'Ads & traffic',
            items: [
                { value: 'adset_name', label: 'By ad set' },
                { value: 'ad_name', label: 'By ad' },
                { value: 'placement', label: 'By placement' },
                { value: 'adset_name,ad_name', label: 'Ad set → Ad' },
                { value: 'adset_name,offer', label: 'Ad set → Offer' },
                { value: 'ad_name,offer', label: 'Ad → Offer' },
                { value: 'placement,offer', label: 'Placement → Offer' },
                { value: 'adset_name,landing', label: 'Ad set → Landing' },
                { value: 'campaign_name', label: 'By TS campaign' },
                { value: 'source', label: 'By site source' },
                { value: 'keyword', label: 'By keyword' },
                { value: 'keyword,offer', label: 'Keyword → Offer' },
            ],
        },
    ];

    function populateDimensionPresets() {
        const sel = document.getElementById('stats-v2-dim-preset');
        if (!sel) return;

        const available = new Set(state.availableDimensions.map((d) => d.key));
        const current = state.dimensions.join(',');
        sel.innerHTML = '';

        const custom = document.createElement('option');
        custom.value = '';
        custom.textContent = 'Custom…';
        sel.appendChild(custom);

        DIMENSION_PRESETS.forEach((group) => {
            const usable = group.items.filter((item) => {
                const dims = item.value.split(',').map((s) => s.trim()).filter(Boolean);
                return dims.length > 0 && dims.every((d) => available.has(d));
            });
            if (!usable.length) return;

            const og = document.createElement('optgroup');
            og.label = group.group;
            usable.forEach((item) => {
                const opt = document.createElement('option');
                opt.value = item.value;
                opt.textContent = item.label;
                og.appendChild(opt);
            });
            sel.appendChild(og);
        });

        const match = Array.from(sel.options).some((opt) => opt.value === current);
        sel.value = match ? current : '';
    }

    function syncDimensionPresetActiveState() {
        const presetSelect = document.getElementById('stats-v2-dim-preset');
        if (!presetSelect) return;
        const current = state.dimensions.join(',');
        const match = Array.from(presetSelect.options).some((opt) => opt.value === current);
        presetSelect.value = match ? current : '';
    }

    function applyDimensionPreset(raw) {
        const dims = raw.split(',').map((s) => s.trim()).filter(Boolean);
        const available = new Set(state.availableDimensions.map((d) => d.key));
        const filtered = dims.filter((d) => available.has(d));
        if (filtered.length === 0) return;
        state.dimensions = filtered;
        renderDimensionTags();
        populateDimensionSelect();
        savePrefs();
        syncDimensionPresetActiveState();
    }

    async function loadSummary() {
        const kpi = document.getElementById('stats-v2-kpi');
        kpi.innerHTML = Array(8).fill('<div class="stats-v2-kpi-card skeleton"></div>').join('');

        const requests = [fetchJson({ ...baseParams(), action: 'summary' })];
        let priorRange = null;
        if (state.comparePeriod) {
            priorRange = priorPeriod(state.dateFrom, state.dateTo);
            requests.push(fetchJson({
                ...baseParams(),
                action: 'summary',
                date_from: priorRange.from,
                date_to: priorRange.to,
            }));
        }

        const results = await Promise.all(requests);
        const data = results[0];
        const compareData = results[1] || null;

        const items = [
            { key: 'visitors', label: 'Visitors', value: data.visitors, compareKey: 'visitors', fmt: (v) => v.toLocaleString(), icon: 'visitors', tone: 'stats-v2-kpi-icon--visitors' },
            { key: 'clicks', label: 'Clicks', value: data.clicks, compareKey: 'clicks', fmt: (v) => v.toLocaleString(), icon: 'clicks', tone: 'stats-v2-kpi-icon--clicks' },
            { key: 'conversions', label: 'Conversions', value: data.conversions, compareKey: 'conversions', fmt: (v) => v.toLocaleString(), icon: 'conversions', tone: 'stats-v2-kpi-icon--conversions' },
            { key: 'optins', label: 'Opt-ins', value: data.optins || 0, compareKey: 'optins', fmt: (v) => v.toLocaleString(), icon: 'conversions', tone: 'stats-v2-kpi-icon--conversions' },
            { key: 'cr', label: 'Conversion Rate', value: data.conversion_rate, compareKey: 'conversion_rate', fmt: fmtPct, icon: 'cr', tone: 'stats-v2-kpi-icon--cr' },
            { key: 'cost', label: 'Cost', value: data.cost, compareKey: 'cost', fmt: fmtMoney, icon: 'cost', tone: 'stats-v2-kpi-icon--cost' },
            { key: 'revenue', label: 'Revenue', value: data.revenue, compareKey: 'revenue', fmt: fmtMoney, icon: 'revenue', tone: 'stats-v2-kpi-icon--revenue' },
            { key: 'profit', label: 'Profit', value: data.profit, compareKey: 'profit', fmt: fmtMoney, signed: true, icon: 'profit', tone: data.profit >= 0 ? 'stats-v2-kpi-icon--profit-pos' : 'stats-v2-kpi-icon--profit-neg' },
            { key: 'roi', label: 'ROI', value: data.roi, compareKey: 'roi', fmt: fmtPct, signed: true, icon: 'roi', tone: 'stats-v2-kpi-icon--roi' },
        ];

        kpi.innerHTML = items.map((item) => {
            let cls = 'stats-v2-kpi-value';
            if (item.signed) {
                cls += Number(item.value) >= 0 ? ' positive' : ' negative';
            }

            let deltaHtml = '';
            if (compareData && item.compareKey) {
                const prev = compareData[item.compareKey];
                const deltaStr = fmtDelta(item.value, prev);
                if (deltaStr) {
                    const deltaVal = pctChange(item.value, prev);
                    const deltaCls = deltaVal >= 0 ? 'positive' : 'negative';
                    const periodHint = priorRange ? `${priorRange.from} → ${priorRange.to}` : '';
                    deltaHtml = `<div class="stats-v2-kpi-delta ${deltaCls}" title="vs prior period ${periodHint}">${deltaStr}</div>`;
                }
            }

            const tone = item.key === 'profit'
                ? (Number(item.value) >= 0 ? 'stats-v2-kpi-icon--profit-pos' : 'stats-v2-kpi-icon--profit-neg')
                : item.tone;

            return `<div class="stats-v2-kpi-card">
                <div class="stats-v2-kpi-head">${kpiIconHtml(item.icon, tone)}<span class="stats-v2-kpi-label">${item.label}</span></div>
                <div class="${cls}">${item.fmt(item.value)}</div>${deltaHtml}
            </div>`;
        }).join('');

        renderOverviewEfficiency(data);

        return data;
    }

    function applyColumnVisibility() {
        const visible = new Set(state.visibleColumns);
        document.querySelectorAll('#stats-v2-breakdown-head [data-col]').forEach((th) => {
            const col = th.dataset.col;
            if (col === 'name') return;
            th.style.display = visible.has(col) ? '' : 'none';
        });
        document.querySelectorAll('#stats-v2-breakdown-body [data-col]').forEach((td) => {
            const col = td.dataset.col;
            if (col === 'name') {
                td.style.display = '';
                return;
            }
            td.style.display = visible.has(col) ? '' : 'none';
        });
        document.querySelectorAll('.col-toggle').forEach((cb) => {
            cb.checked = visible.has(cb.dataset.col);
        });
    }

    function totalRowHtml(t) {
        const profitCls = t.profit >= 0 ? 'profit-positive' : 'profit-negative';
        const roiCls = roiRowClass(t);
        const visitors = Number(t.visitors ?? 0) || 0;
        const actionClicks = Number(t.clicks ?? ((t.lp_clicks || 0) + (t.direct_clicks || 0))) || 0;
        const lpClicks = Number(t.lp_clicks ?? 0) || 0;
        const ctr = t.ctr != null ? Number(t.ctr) : (visitors > 0 ? (lpClicks / visitors) * 100 : 0);
        return `<tr class="total-row${roiCls}">
            <td data-col="name"><span class="stats-v2-expand-spacer" aria-hidden="true"></span>Total</td>
            <td class="num" data-col="visitors">${visitors.toLocaleString()}</td>
            <td class="num" data-col="lp_clicks">${actionClicks.toLocaleString()}</td>
            <td class="num" data-col="ctr">${fmtPct(ctr)}</td>
            <td class="num" data-col="conversions">${Number(t.conversions).toLocaleString()}</td>
            <td class="num" data-col="optins">${Number(t.optins || 0).toLocaleString()}</td>
            <td class="num" data-col="cr">${fmtPct(t.conversion_rate)}</td>
            <td class="num" data-col="cost">${fmtMoney(t.cost)}</td>
            <td class="num" data-col="revenue">${fmtMoney(t.revenue)}</td>
            <td class="num" data-col="profit"><span class="${profitCls}">${fmtMoney(t.profit)}</span></td>
            <td class="num" data-col="roi"><span class="${profitCls}">${fmtPct(t.roi)}</span></td>
        </tr>`;
    }

    function exportBreakdownCsv() {
        exportBreakdownCsvAsync().catch((err) => alert(friendlyErrorMessage(err.message || 'Export failed')));
    }

    async function exportBreakdownCsvAsync() {
        if (state.dimensions.length === 0) {
            alert('Add dimensions and run a report first.');
            return;
        }

        const rows = await fetchAllBreakdownRows();
        if (!rows.length) {
            alert('Run a report first to export data.');
            return;
        }

        const header = ['Name', 'Visitors', 'Clicks', 'CTR', 'Conversions', 'Opt-ins', 'CR', 'Cost', 'Revenue', 'Profit', 'ROI'];
        const lines = [header.filter((_, i) => i === 0 || state.visibleColumns.includes(ALL_METRIC_COLS[i - 1])).join(',')];
        rows.forEach((r) => {
            const cells = [r.name];
            if (state.visibleColumns.includes('visitors')) cells.push(r.clicks);
            if (state.visibleColumns.includes('lp_clicks')) cells.push(r.action_clicks != null ? r.action_clicks : ((r.lp_clicks || 0) + (r.direct_clicks || 0)));
            if (state.visibleColumns.includes('ctr')) cells.push(r.ctr != null ? r.ctr : rowCtr(r));
            if (state.visibleColumns.includes('conversions')) cells.push(r.conversions);
            if (state.visibleColumns.includes('optins')) cells.push(r.optins || 0);
            if (state.visibleColumns.includes('cr')) cells.push(r.conversion_rate);
            if (state.visibleColumns.includes('cost')) cells.push(r.cost);
            if (state.visibleColumns.includes('revenue')) cells.push(r.revenue);
            if (state.visibleColumns.includes('profit')) cells.push(r.profit);
            if (state.visibleColumns.includes('roi')) cells.push(r.roi);
            lines.push(cells.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(','));
        });
        const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `campaign-stats-${state.campaignId}-${state.dateFrom}-${state.dateTo}.csv`;
        a.click();
    }

    async function fetchAllBreakdownRows() {
        const all = [];
        let page = 1;
        let total = 0;
        const sortParams = breakdownSortParams();

        do {
            const data = await fetchJson({
                ...baseParams(),
                action: 'breakdown',
                dimensions: state.dimensions.join(','),
                parent_path: '[]',
                page,
                per_page: 200,
                sort: sortParams.sort,
                order: sortParams.order,
            });
            total = data.total ?? 0;
            (data.rows || []).forEach((row) => {
                all.push({
                    name: row.name || row.group,
                    clicks: row.clicks,
                    lp_clicks: row.lp_clicks,
                    ctr: row.ctr != null ? row.ctr : rowCtr(row),
                    conversions: row.conversions,
                    optins: row.optins || 0,
                    conversion_rate: row.conversion_rate,
                    cost: row.cost,
                    revenue: row.revenue,
                    profit: row.profit,
                    roi: row.roi,
                });
            });
            page += 1;
        } while (all.length < total);

        return all;
    }

    function renderOverviewEfficiency(data) {
        const el = document.getElementById('stats-v2-overview-efficiency');
        if (!el) return;

        const clicks = Number(data.clicks) || 0;
        const lpClicks = Number(data.lp_clicks) || 0;
        const visitors = Number(data.visitors) || 0;
        const conversions = Number(data.conversions) || 0;
        const cost = Number(data.cost) || 0;
        const revenue = Number(data.revenue) || 0;
        const profit = Number(data.profit) || 0;

        const fmtRatio = (num) => (Number.isFinite(num) ? num.toFixed(2) + 'x' : '—');

        const metrics = [
            {
                label: 'CPC',
                sub: 'Cost per click',
                value: clicks > 0 ? fmtMoney(cost / clicks) : '—',
                icon: 'cpc',
                tone: 'stats-v2-kpi-icon--cost',
            },
            {
                label: 'EPC',
                sub: 'Earnings per click',
                value: clicks > 0 ? fmtMoney(revenue / clicks) : '—',
                icon: 'epc',
                tone: 'stats-v2-kpi-icon--revenue',
            },
            {
                label: 'CPA',
                sub: 'Cost per conversion',
                value: conversions > 0 ? fmtMoney(cost / conversions) : '—',
                icon: 'cpa',
                tone: 'stats-v2-kpi-icon--conversions',
            },
            {
                label: 'RPV',
                sub: 'Revenue per visitor',
                value: visitors > 0 ? fmtMoney(revenue / visitors) : '—',
                icon: 'rpv',
                tone: 'stats-v2-kpi-icon--visitors',
            },
            {
                label: 'CPV',
                sub: 'Cost per visitor',
                value: visitors > 0 ? fmtMoney(cost / visitors) : '—',
                icon: 'cpv',
                tone: 'stats-v2-kpi-icon--cost',
            },
            {
                label: 'PPV',
                sub: 'Profit per visitor',
                value: visitors > 0 ? fmtMoney(profit / visitors) : '—',
                icon: 'epv',
                tone: profit >= 0 ? 'stats-v2-kpi-icon--profit-pos' : 'stats-v2-kpi-icon--profit-neg',
                valueClass: profit >= 0 ? 'profit-positive' : 'profit-negative',
            },
            {
                label: 'ROAS',
                sub: 'Return on ad spend',
                value: cost > 0 ? fmtRatio(revenue / cost) : '—',
                icon: 'roas',
                tone: 'stats-v2-kpi-icon--roi',
            },
            {
                label: 'LP CTR',
                sub: 'Visitor → LP click rate',
                value: visitors > 0 ? fmtPct((lpClicks / visitors) * 100) : '—',
                icon: 'lpctr',
                tone: 'stats-v2-kpi-icon--cr',
            },
            {
                label: 'Profit / Click',
                sub: 'Net per action click',
                value: clicks > 0 ? fmtMoney(profit / clicks) : '—',
                icon: 'ppclick',
                tone: profit >= 0 ? 'stats-v2-kpi-icon--profit-pos' : 'stats-v2-kpi-icon--profit-neg',
                valueClass: profit >= 0 ? 'profit-positive' : 'profit-negative',
            },
            {
                label: 'Margin',
                sub: 'Profit ÷ revenue',
                value: revenue > 0 ? fmtPct((profit / revenue) * 100) : '—',
                icon: 'margin',
                tone: profit >= 0 ? 'stats-v2-kpi-icon--profit-pos' : 'stats-v2-kpi-icon--profit-neg',
                valueClass: profit >= 0 ? 'profit-positive' : 'profit-negative',
            },
        ];

        el.innerHTML = metrics.map((m) => `
            <div class="stats-v2-efficiency-card">
                <div class="stats-v2-kpi-head">${kpiIconHtml(m.icon, m.tone)}<span class="stats-v2-kpi-label">${m.label}</span></div>
                <div class="stats-v2-efficiency-value${m.valueClass ? ' ' + m.valueClass : ''}">${m.value}</div>
                <div class="stats-v2-efficiency-sub">${m.sub}</div>
            </div>
        `).join('');
    }

    function applyBreakdownSearch() {
        const q = (state.breakdownSearch || '').trim().toLowerCase();
        const tbody = document.getElementById('stats-v2-breakdown-body');
        if (!tbody) return;
        tbody.querySelectorAll('tr[data-row-id]').forEach((tr) => {
            if (!q) {
                tr.style.display = '';
                return;
            }
            const nameCell = tr.querySelector('[data-col="name"]');
            const text = (nameCell?.textContent || '').toLowerCase();
            tr.style.display = text.includes(q) ? '' : 'none';
        });
    }

    const chartColors = {
        visitors: '#4caf50',
        clicks: '#7b1fa2',
        conversions: '#ff9800',
        cost: '#1976d2',
        revenue: '#2e7d32',
    };

    function isDarkTheme() {
        if (window.KumaTheme && typeof window.KumaTheme.meta === 'function') {
            return window.KumaTheme.meta().base === 'dark';
        }
        return document.documentElement.getAttribute('data-theme-base') === 'dark';
    }

    function chartThemeColors() {
        const root = getComputedStyle(document.documentElement);
        const css = (name, fallback) => {
            const v = root.getPropertyValue(name).trim();
            return v || fallback;
        };
        const dark = isDarkTheme();
        return {
            gridColor: css('--chart-grid', dark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.08)'),
            tickColor: css('--chart-tick', dark ? '#9aa0a6' : '#666'),
        };
    }

    function buildChart(canvasId, chartData, selectedMetrics) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !window.Chart) return null;

        const { gridColor, tickColor } = chartThemeColors();

        const datasets = [];
        selectedMetrics.forEach((key) => {
            if (!chartData.datasets[key]) return;
            datasets.push({
                label: key.charAt(0).toUpperCase() + key.slice(1),
                data: chartData.datasets[key],
                borderColor: chartColors[key] || '#666',
                backgroundColor: 'transparent',
                tension: 0.3,
                yAxisID: key === 'cost' || key === 'revenue' ? 'y1' : 'y',
            });
        });

        return new Chart(canvas, {
            type: 'line',
            data: { labels: chartData.labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: {
                        ticks: { color: tickColor },
                        grid: { color: gridColor },
                    },
                    y: {
                        type: 'linear',
                        position: 'left',
                        beginAtZero: true,
                        ticks: { color: tickColor },
                        grid: { color: gridColor },
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        ticks: { color: tickColor },
                        grid: { drawOnChartArea: false, color: gridColor },
                    },
                },
                plugins: {
                    legend: { labels: { color: tickColor } },
                },
            },
        });
    }

    async function loadChart(canvasId, storeKey) {
        const granularity = document.getElementById('stats-v2-chart-granularity')?.value || 'auto';
        const chartData = await fetchJson({ ...baseParams(), action: 'chart', granularity });
        const metricSelector = canvasId === 'stats-v2-overview-chart'
            ? '.overview-chart-metric:checked'
            : '.stats-v2-panel-chart .chart-metric:checked';
        const selected = Array.from(document.querySelectorAll(metricSelector)).map((el) => el.value);
        // Dedupe in case duplicate controls are present in the DOM
        const uniqueSelected = [...new Set(selected)];
        if (state[storeKey]) {
            state[storeKey].destroy();
        }
        state[storeKey] = buildChart(canvasId, chartData, uniqueSelected.length ? uniqueSelected : ['visitors', 'clicks', 'conversions']);
    }

    async function loadDimensions() {
        state.availableDimensions = await fetchJson({
            campaign_id: state.campaignId,
            action: 'dimensions',
            date_from: state.dateFrom,
            date_to: state.dateTo,
        });
        renderDimensionTags();
        populateDimensionSelect();
        populateDimensionPresets();
        syncDimensionPresetActiveState();
    }

    const DIMENSION_GROUP_LABELS = {
        core: 'Core',
        traffic_source: 'Traffic Source Tokens',
        campaign: 'Campaign Custom Tokens',
        tracker: 'Tracker Variables',
    };

    function populateDimensionSelect() {
        const sel = document.getElementById('stats-v2-add-dimension');
        const used = new Set(state.dimensions);
        sel.innerHTML = '<option value="">+ Add dimension</option>';

        const groups = {};
        state.availableDimensions.forEach((d) => {
            if (used.has(d.key)) {
                return;
            }
            const groupKey = d.group || 'core';
            if (!groups[groupKey]) {
                groups[groupKey] = [];
            }
            groups[groupKey].push(d);
        });

        ['core', 'traffic_source', 'campaign', 'tracker'].forEach((groupKey) => {
            const items = groups[groupKey];
            if (!items || !items.length) {
                return;
            }
            const og = document.createElement('optgroup');
            og.label = DIMENSION_GROUP_LABELS[groupKey] || groupKey;
            items.forEach((d) => {
                const opt = document.createElement('option');
                opt.value = d.key;
                opt.textContent = d.label;
                og.appendChild(opt);
            });
            sel.appendChild(og);
        });
    }

    function renderDimensionTags() {
        const wrap = document.getElementById('stats-v2-dimension-tags');
        wrap.innerHTML = state.dimensions.map((key, idx) => {
            const def = state.availableDimensions.find((d) => d.key === key);
            const label = def ? def.label : key;
            return `<span class="stats-v2-dim-tag" data-idx="${idx}">${label} <button type="button" aria-label="Remove" data-remove="${idx}">&times;</button></span>`;
        }).join('');
        wrap.querySelectorAll('[data-remove]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const i = parseInt(btn.dataset.remove, 10);
                state.dimensions.splice(i, 1);
                renderDimensionTags();
                populateDimensionSelect();
                savePrefs();
                syncDimensionPresetActiveState();
            });
        });
        syncDimensionPresetActiveState();
    }

    function roiRowClass(row) {
        const roi = Number(row.roi);
        if (Number.isNaN(roi) || roi === 0) {
            return '';
        }
        return roi > 0 ? ' row-roi-positive' : ' row-roi-negative';
    }

    function priorPeriod(from, to) {
        const days = daysBetween(from, to);
        const priorEnd = addDays(from, -1);
        const priorStart = addDays(priorEnd, -(days - 1));
        return { from: priorStart, to: priorEnd };
    }

    function pctChange(current, previous) {
        if (previous == null || previous === undefined) {
            return null;
        }
        if (previous === 0) {
            return current === 0 ? 0 : null;
        }
        return ((current - previous) / Math.abs(previous)) * 100;
    }

    function fmtDelta(current, previous) {
        const delta = pctChange(current, previous);
        if (delta == null) {
            return '';
        }
        const sign = delta >= 0 ? '+' : '';
        return `${sign}${delta.toFixed(1)}%`;
    }

    function rowCtr(row) {
        if (row.ctr != null && row.ctr !== '') {
            return Number(row.ctr);
        }
        const visitors = Number(row.clicks) || 0;
        const lpClicks = Number(row.lp_clicks) || 0;
        return visitors > 0 ? (lpClicks / visitors) * 100 : 0;
    }

    function rowHtml(row, level, rowId, hasChildren, expanded) {
        const profitCls = row.profit >= 0 ? 'profit-positive' : 'profit-negative';
        const indent = level > 0 ? 'child-row' : '';
        const roiCls = roiRowClass(row);
        const expandBtn = hasChildren
            ? `<button type="button" class="expand-btn" data-expand="${rowId}" aria-label="Expand">${expanded ? '▼' : '▶'}</button>`
            : '<span class="stats-v2-expand-spacer" aria-hidden="true"></span>';
        return `<tr class="${indent}${roiCls}" data-row-id="${rowId}" data-level="${level}">
            <td data-col="name">${expandBtn}${escapeHtml(row.name || row.group)}</td>
            <td class="num" data-col="visitors">${Number(row.clicks).toLocaleString()}</td>
            <td class="num" data-col="lp_clicks">${Number(row.action_clicks != null ? row.action_clicks : ((row.lp_clicks || 0) + (row.direct_clicks || 0))).toLocaleString()}</td>
            <td class="num" data-col="ctr">${fmtPct(rowCtr(row))}</td>
            <td class="num" data-col="conversions">${Number(row.conversions).toLocaleString()}</td>
            <td class="num" data-col="optins">${Number(row.optins || 0).toLocaleString()}</td>
            <td class="num" data-col="cr">${fmtPct(row.conversion_rate)}</td>
            <td class="num" data-col="cost">${fmtMoney(row.cost)}</td>
            <td class="num" data-col="revenue">${fmtMoney(row.revenue)}</td>
            <td class="num" data-col="profit"><span class="${profitCls}">${fmtMoney(row.profit)}</span></td>
            <td class="num" data-col="roi"><span class="${profitCls}">${fmtPct(row.roi)}</span></td>
        </tr>`;
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function breakdownSortParams() {
        if (state.dimensions[0] === 'date' && !state.sortByUser) {
            return { sort: 'group', order: 'asc' };
        }
        return { sort: state.sort, order: state.order };
    }

    function updateSortIndicators() {
        const activeSort = breakdownSortParams().sort;
        const activeOrder = breakdownSortParams().order;
        document.querySelectorAll('#stats-v2-breakdown-head th[data-sort]').forEach((th) => {
            const colSort = th.dataset.sort;
            const mapped = SORTABLE_COLS[th.dataset.col] || colSort;
            const isActive = mapped === activeSort
                || (activeSort === 'clicks' && mapped === 'visitors')
                || (activeSort === 'group' && mapped === 'name')
                || (activeSort === 'conversion_rate' && mapped === 'cr');
            th.classList.toggle('is-sorted', isActive);
            th.setAttribute('aria-sort', isActive ? (activeOrder === 'asc' ? 'ascending' : 'descending') : 'none');
            const indicator = th.querySelector('.sort-indicator');
            if (indicator) {
                indicator.textContent = isActive ? (activeOrder === 'asc' ? ' ▲' : ' ▼') : '';
            }
        });
    }

    function bindSortHeaders() {
        const head = document.getElementById('stats-v2-breakdown-head');
        if (!head || head.dataset.sortBound === '1') return;
        head.dataset.sortBound = '1';

        head.querySelectorAll('th[data-col]').forEach((th) => {
            const col = th.dataset.col;
            const sortKey = SORTABLE_COLS[col];
            if (!sortKey) return;

            th.dataset.sort = sortKey;
            th.classList.add('sortable-col');
            th.setAttribute('role', 'columnheader');
            th.setAttribute('tabindex', '0');
            if (!th.querySelector('.sort-indicator')) {
                const span = document.createElement('span');
                span.className = 'sort-indicator';
                span.setAttribute('aria-hidden', 'true');
                th.appendChild(span);
            }

            const activate = () => {
                const apiSort = sortKey === 'visitors' ? 'clicks'
                    : sortKey === 'cr' ? 'conversion_rate'
                    : sortKey === 'name' ? 'group'
                    : sortKey;
                if (state.sort === apiSort || (apiSort === 'clicks' && state.sort === 'visitors')) {
                    state.order = state.order === 'desc' ? 'asc' : 'desc';
                } else {
                    state.sort = apiSort;
                    state.order = apiSort === 'group' || apiSort === 'name' ? 'asc' : 'desc';
                }
                state.sortByUser = true;
                state.breakdownPage = 1;
                savePrefs();
                updateSortIndicators();
                loadBreakdown();
            };

            th.addEventListener('click', activate);
            th.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    activate();
                }
            });
        });

        updateSortIndicators();
    }

    async function runBreakdown(parentPath, level, tbody, afterRow) {
        const sortParams = breakdownSortParams();
        const params = {
            ...baseParams(),
            action: 'breakdown',
            dimensions: state.dimensions.join(','),
            parent_path: JSON.stringify(parentPath),
            page: level === 0 ? state.breakdownPage : 1,
            per_page: state.perPage,
            sort: sortParams.sort,
            order: sortParams.order,
        };

        const data = await fetchJson(params);

        const frag = document.createDocumentFragment();
        const dim = data.dimension;

        data.rows.forEach((row) => {
            const rowId = 'r-' + level + '-' + Math.random().toString(36).slice(2, 9);
            const path = parentPath.concat([{ dimension: dim, value: row.group_key || row.group }]);
            const tr = document.createElement('tbody');
            tr.innerHTML = rowHtml(row, level, rowId, data.has_children, false);
            const trEl = tr.firstElementChild;
            trEl.dataset.path = JSON.stringify(path);
            frag.appendChild(trEl);
        });

        if (level === 0) {
            state.exportRows = data.rows.map((row) => ({
                name: row.name || row.group,
                clicks: row.clicks,
                lp_clicks: row.lp_clicks,
                ctr: row.ctr != null ? row.ctr : rowCtr(row),
                conversions: row.conversions,
                conversion_rate: row.conversion_rate,
                cost: row.cost,
                revenue: row.revenue,
                profit: row.profit,
                roi: row.roi,
            }));
            tbody.innerHTML = '';
            if (document.getElementById('stats-v2-show-totals').checked && data.totals) {
                tbody.innerHTML = totalRowHtml(data.totals);
            }
            renderPagination(data.total, data.page, data.per_page);
            updateSortIndicators();
        }

        if (afterRow) {
            afterRow.after(...frag.childNodes);
        } else {
            tbody.append(...frag.childNodes);
        }

        if (level === 0) {
            applyColumnVisibility();
            applyBreakdownSearch();
        }

        tbody.querySelectorAll('[data-expand]').forEach((btn) => {
            btn.addEventListener('click', onExpandClick);
        });

        return data;
    }

    function renderPagination(total, page, perPage) {
        const el = document.getElementById('stats-v2-pagination');
        const pages = Math.max(1, Math.ceil(total / perPage));
        const perPageOptions = PER_PAGE_OPTIONS.map((n) =>
            `<option value="${n}"${n === perPage ? ' selected' : ''}>${n}</option>`
        ).join('');
        el.innerHTML = `<span class="stats-v2-pagination-meta">
                Showing page ${page} of ${pages} (${total} rows)
            </span>
            <span class="stats-v2-pagination-controls">
                <label class="stats-v2-per-page" for="stats-v2-per-page">
                    Rows
                    <select id="stats-v2-per-page" aria-label="Rows per page">${perPageOptions}</select>
                </label>
                <button type="button" class="btn btn-sm btn-secondary" id="stats-v2-prev" ${page <= 1 ? 'disabled' : ''}>Prev</button>
                <button type="button" class="btn btn-sm btn-secondary" id="stats-v2-next" ${page >= pages ? 'disabled' : ''}>Next</button>
            </span>`;
        document.getElementById('stats-v2-prev')?.addEventListener('click', () => {
            state.breakdownPage--;
            loadBreakdown();
        });
        document.getElementById('stats-v2-next')?.addEventListener('click', () => {
            state.breakdownPage++;
            loadBreakdown();
        });
        document.getElementById('stats-v2-per-page')?.addEventListener('change', (e) => {
            state.perPage = normalizePerPage(e.target.value);
            state.breakdownPage = 1;
            savePrefs();
            loadBreakdown();
        });
    }

    async function onExpandClick(e) {
        const btn = e.currentTarget;
        const tr = btn.closest('tr');
        const rowId = tr.dataset.rowId;
        const path = JSON.parse(tr.dataset.path || '[]');
        const level = parseInt(tr.dataset.level, 10) + 1;
        const tbody = document.getElementById('stats-v2-breakdown-body');

        if (state.parentStacks[rowId]) {
            removeChildRows(tr);
            delete state.parentStacks[rowId];
            btn.textContent = '▶';
            return;
        }

        btn.textContent = '…';
        try {
            const childRows = [];
            const data = await fetchJson({
                ...baseParams(),
                action: 'breakdown',
                dimensions: state.dimensions.join(','),
                parent_path: JSON.stringify(path),
                page: 1,
                per_page: 100,
            });

            removeChildRows(tr);
            let insertAfter = tr;
            data.rows.forEach((row) => {
                const childId = 'r-' + level + '-' + Math.random().toString(36).slice(2, 9);
                const childPath = path.concat([{ dimension: data.dimension, value: row.group_key || row.group }]);
                const tmp = document.createElement('tbody');
                tmp.innerHTML = rowHtml(row, level, childId, data.has_children, false);
                const childTr = tmp.firstElementChild;
                childTr.dataset.path = JSON.stringify(childPath);
                childTr.dataset.parentRow = rowId;
                childTr.classList.add('child-row');
                insertAfter.after(childTr);
                insertAfter = childTr;
                childRows.push(childTr);
            });

            state.parentStacks[rowId] = childRows;
            tbody.querySelectorAll('[data-expand]').forEach((b) => b.addEventListener('click', onExpandClick));
            btn.textContent = '▼';
            applyBreakdownSearch();
        } catch (err) {
            if (isAbortError(err)) {
                return;
            }
            btn.textContent = '▶';
            showStatsError(err.message);
        }
    }

    function removeChildRows(tr) {
        const rowId = tr.dataset.rowId;
        const tbody = tr.parentElement;
        Array.from(tbody.querySelectorAll(`tr[data-parent-row="${rowId}"]`)).forEach((r) => {
            if (state.parentStacks[r.dataset.rowId]) {
                removeChildRows(r);
                delete state.parentStacks[r.dataset.rowId];
            }
            r.remove();
        });
    }

    async function loadBreakdown() {
        const msg = document.getElementById('stats-v2-breakdown-message');
        const tbody = document.getElementById('stats-v2-breakdown-body');
        msg.textContent = '';
        msg.className = 'stats-v2-message';
        clearStatsFlash();

        if (state.dimensions.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="stats-v2-empty">Add at least one dimension</td></tr>';
            return;
        }

        setBreakdownLoading(true);
        tbody.innerHTML = '<tr><td colspan="9" class="stats-v2-empty">Loading…</td></tr>';
        state.parentStacks = {};

        try {
            await runBreakdown([], 0, tbody, null);
        } catch (err) {
            if (isAbortError(err)) {
                return;
            }
            const text = showStatsError(err.message);
            msg.textContent = text;
            msg.className = 'stats-v2-message stats-v2-breakdown-message error';
            tbody.innerHTML = '<tr><td colspan="9" class="stats-v2-empty">Couldn’t load breakdown</td></tr>';
        } finally {
            setBreakdownLoading(false);
        }
    }

    function resizeChart(storeKey) {
        const chart = state[storeKey];
        if (!chart) return;
        requestAnimationFrame(() => {
            try {
                chart.resize();
            } catch (e) {
                /* chart may have been destroyed */
            }
        });
    }

    function setTab(tab) {
        state.tab = tab;
        savePrefs();
        document.querySelectorAll('.stats-v2-tab').forEach((el) => el.classList.toggle('active', el.dataset.tab === tab));
        document.querySelectorAll('.stats-v2-panel').forEach((el) => el.classList.toggle('active', el.dataset.panel === tab));
        if (tab === 'chart') {
            if (!state.chartMain) {
                loadChart('stats-v2-chart', 'chartMain');
            } else {
                resizeChart('chartMain');
            }
        }
    }

    let refreshGeneration = 0;

    async function refreshAll() {
        const gen = ++refreshGeneration;
        setFiltersRefreshing(true);
        resetRequestAbort();
        clearStatsFlash();
        readFiltersFromDom();
        try {
            await loadCampaignMeta();
            if (gen !== refreshGeneration) {
                return;
            }
            await loadSavedViews();
            renderSavedViewSelect();
            await loadDimensions();
            if (gen !== refreshGeneration) {
                return;
            }
            await loadSummary();
            if (gen !== refreshGeneration) {
                return;
            }
            // Chart is best-effort: KPI summary already succeeded; don't blank the page on chart bind/SQL issues.
            if (!state.chartCollapsed) {
                try {
                    await loadChart('stats-v2-overview-chart', 'chartOverview');
                } catch (chartErr) {
                    if (!isAbortError(chartErr) && gen === refreshGeneration) {
                        console.error('[Campaign Stats] overview chart', chartErr);
                    }
                }
            }
            if (gen !== refreshGeneration) {
                return;
            }
            if (state.tab === 'chart') {
                try {
                    await loadChart('stats-v2-chart', 'chartMain');
                } catch (chartErr) {
                    if (!isAbortError(chartErr) && gen === refreshGeneration) {
                        showStatsError(chartErr.message || 'Couldn’t load chart');
                    }
                }
            }
            if (gen !== refreshGeneration) {
                return;
            }
            if (state.tab === 'breakdown' && document.getElementById('stats-v2-breakdown-body').querySelector('[data-row-id]')) {
                await loadBreakdown();
            }
        } catch (err) {
            if (isAbortError(err) || gen !== refreshGeneration) {
                return;
            }
            showStatsError(err.message || 'Failed to refresh stats');
        } finally {
            if (gen === refreshGeneration) {
                setFiltersRefreshing(false);
            }
        }
    }

    let refreshAllTimer = null;
    function scheduleRefreshAll() {
        if (refreshAllTimer) {
            clearTimeout(refreshAllTimer);
        }
        // Immediate feedback so spam-clicks cannot queue more work.
        setFiltersRefreshing(true);
        // Debounce so rapid Apply / filter changes do not stack abandoned server queries.
        refreshAllTimer = setTimeout(() => {
            refreshAllTimer = null;
            refreshAll();
        }, 300);
    }

    document.getElementById('stats-v2-apply-filters').addEventListener('click', () => {
        if (state.filtersRefreshing && refreshAllTimer === null) {
            // Already in-flight (past debounce); ignore extra clicks.
            return;
        }
        scheduleRefreshAll();
    });

    document.getElementById('stats-v2-advanced-toggle')?.addEventListener('click', () => {
        const panel = document.getElementById('stats-v2-advanced-panel');
        const btn = document.getElementById('stats-v2-advanced-toggle');
        const open = panel?.classList.toggle('hidden') === false;
        if (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    });

    document.getElementById('stats-v2-breakdown-search')?.addEventListener('input', (e) => {
        state.breakdownSearch = e.target.value;
        applyBreakdownSearch();
    });

    document.getElementById('stats-v2-traffic-source')?.addEventListener('change', () => {
        readAdvancedFiltersFromDom();
        scheduleRefreshAll();
    });

    document.getElementById('stats-v2-offer')?.addEventListener('change', () => {
        readAdvancedFiltersFromDom();
        scheduleRefreshAll();
    });

    document.getElementById('stats-v2-landing')?.addEventListener('change', () => {
        readAdvancedFiltersFromDom();
        scheduleRefreshAll();
    });

    document.getElementById('stats-v2-token-param')?.addEventListener('change', (e) => {
        state.tokenParam = e.target.value || '';
        state.tokenValue = '';
        const tokenVal = document.getElementById('stats-v2-token-value');
        if (tokenVal) tokenVal.value = '';
        const valueWrap = document.getElementById('stats-v2-token-value-wrap');
        if (valueWrap) valueWrap.classList.toggle('hidden', !state.tokenParam);
        if (state.tokenParam) {
            loadTokenValues(state.tokenParam);
        }
        updateAdvancedToggleBadge();
    });

    document.getElementById('stats-v2-token-value')?.addEventListener('change', () => {
        readAdvancedFiltersFromDom();
        if (state.tokenParam && state.tokenValue) {
            scheduleRefreshAll();
        }
    });

    document.getElementById('stats-v2-token-value')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            readAdvancedFiltersFromDom();
            if (state.tokenParam && state.tokenValue) {
                scheduleRefreshAll();
            }
        }
    });

    document.getElementById('stats-v2-dim-preset')?.addEventListener('change', (e) => {
        const value = e.target.value || '';
        if (!value) return;
        applyDimensionPreset(value);
    });

    document.querySelectorAll('.stats-v2-presets [data-preset]').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (state.filtersRefreshing && refreshAllTimer === null) {
                return;
            }
            const today = appToday || app.dataset.dateTo || '';
            const preset = btn.dataset.preset;
            let from = today;
            let to = today;
            if (preset === 'yesterday') {
                from = to = addDays(today, -1);
            } else if (preset === 'last7') {
                from = addDays(today, -6);
            } else if (preset === 'last30') {
                from = addDays(today, -29);
            }
            document.getElementById('stats-v2-date-from').value = from;
            document.getElementById('stats-v2-date-to').value = to;
            scheduleRefreshAll();
        });
    });

    document.querySelectorAll('.stats-v2-tab').forEach((tab) => {
        tab.addEventListener('click', () => setTab(tab.dataset.tab));
    });

    document.getElementById('stats-v2-add-dimension').addEventListener('change', (e) => {
        const v = e.target.value;
        if (v && !state.dimensions.includes(v)) {
            state.dimensions.push(v);
            renderDimensionTags();
            populateDimensionSelect();
            savePrefs();
            syncDimensionPresetActiveState();
        }
        e.target.value = '';
    });

    document.getElementById('stats-v2-run-report').addEventListener('click', () => {
        if (state.breakdownLoading) return;
        state.breakdownPage = 1;
        loadBreakdown();
    });

    document.getElementById('stats-v2-save-view')?.addEventListener('click', async () => {
        const name = window.prompt('Name this saved view:', 'My report');
        if (!name || !name.trim()) return;

        if (state.savedViewsUseServer) {
            try {
                const data = await postSavedViews('save', {
                    name: name.trim(),
                    config: viewConfigPayload(),
                });
                if (data.view) {
                    state.savedViews.push(data.view);
                    state.savedViews.sort((a, b) => a.name.localeCompare(b.name));
                    renderSavedViewSelect();
                    document.getElementById('stats-v2-saved-view').value = String(data.view.id);
                }
            } catch (e) {
                alert(e.message || 'Could not save view.');
            }
            return;
        }

        const views = getSavedViewsLocalForCampaign();
        const view = {
            id: 'sv_' + Date.now(),
            name: name.trim(),
            ...viewConfigPayload(),
            created: new Date().toISOString(),
        };
        views.push(view);
        persistSavedViewsLocalForCampaign(views);
        state.savedViews = views;
        renderSavedViewSelect();
        document.getElementById('stats-v2-saved-view').value = view.id;
    });

    document.getElementById('stats-v2-saved-view')?.addEventListener('change', (e) => {
        const id = e.target.value;
        if (!id) return;
        const view = getSavedViewsForCampaign().find((v) => String(v.id) === String(id));
        if (view) {
            applySavedView(view);
        }
    });

    document.getElementById('stats-v2-delete-view')?.addEventListener('click', async () => {
        const sel = document.getElementById('stats-v2-saved-view');
        const id = sel?.value;
        if (!id) {
            alert('Select a saved view to delete.');
            return;
        }

        if (state.savedViewsUseServer) {
            try {
                await postSavedViews('delete', { view_id: id });
                state.savedViews = state.savedViews.filter((v) => String(v.id) !== String(id));
                renderSavedViewSelect();
            } catch (e) {
                alert(e.message || 'Could not delete view.');
            }
            return;
        }

        const views = getSavedViewsLocalForCampaign().filter((v) => String(v.id) !== String(id));
        persistSavedViewsLocalForCampaign(views);
        state.savedViews = views;
        renderSavedViewSelect();
    });

    document.getElementById('stats-v2-density').value = state.density;
    document.getElementById('stats-v2-density').addEventListener('change', (e) => {
        state.density = e.target.value;
        localStorage.setItem('stats_v2_density', state.density);
        document.getElementById('stats-v2-breakdown-table').classList.toggle('density-compact', state.density === 'compact');
    });
    document.getElementById('stats-v2-breakdown-table').classList.toggle('density-compact', state.density === 'compact');

    document.getElementById('stats-v2-toggle-chart').addEventListener('click', () => {
        state.chartCollapsed = !state.chartCollapsed;
        savePrefs();
        document.getElementById('stats-v2-overview-chart-area').classList.toggle('collapsed', state.chartCollapsed);
        document.getElementById('stats-v2-toggle-chart').textContent = state.chartCollapsed ? 'Expand' : 'Collapse';
        if (!state.chartCollapsed && !state.chartOverview) {
            loadChart('stats-v2-overview-chart', 'chartOverview');
        }
    });

    document.getElementById('stats-v2-customize-columns')?.addEventListener('click', () => {
        document.getElementById('stats-v2-column-picker')?.classList.toggle('hidden');
    });

    document.querySelectorAll('.col-toggle').forEach((cb) => {
        cb.addEventListener('change', () => {
            const col = cb.dataset.col;
            if (cb.checked) {
                if (!state.visibleColumns.includes(col)) state.visibleColumns.push(col);
            } else {
                state.visibleColumns = state.visibleColumns.filter((c) => c !== col);
            }
            if (state.visibleColumns.length === 0) {
                state.visibleColumns = [col];
                cb.checked = true;
            }
            savePrefs();
            applyColumnVisibility();
        });
    });

    document.getElementById('stats-v2-export-csv')?.addEventListener('click', exportBreakdownCsv);

    document.querySelectorAll('.stats-v2-panel-chart .chart-metric').forEach((el) => {
        el.addEventListener('change', () => loadChart('stats-v2-chart', 'chartMain'));
    });
    document.querySelectorAll('.overview-chart-metric').forEach((el) => {
        el.addEventListener('change', () => loadChart('stats-v2-overview-chart', 'chartOverview'));
    });
    document.getElementById('stats-v2-chart-granularity')?.addEventListener('change', () => loadChart('stats-v2-chart', 'chartMain'));

    if (state.chartCollapsed) {
        document.getElementById('stats-v2-overview-chart-area')?.classList.add('collapsed');
        document.getElementById('stats-v2-toggle-chart').textContent = 'Expand';
    }

    window.addEventListener('kuma-theme-change', () => {
        if (state.chartOverview) {
            loadChart('stats-v2-overview-chart', 'chartOverview');
        }
        if (state.chartMain) {
            loadChart('stats-v2-chart', 'chartMain');
        }
    });

    setTab(state.tab);
    bindSortHeaders();

    loadCampaignMeta().then(() => loadDimensions()).then(() => loadSavedViews()).then(() => {
        renderSavedViewSelect();
        if (!state.campaignId) return;
        return loadSummary();
    }).then(() => {
        if (!state.campaignId || state.chartCollapsed) return;
        return loadChart('stats-v2-overview-chart', 'chartOverview');
    }).then(() => {
        applyColumnVisibility();
    }).catch((err) => {
        if (isAbortError(err)) {
            return;
        }
        showStatsError(err.message || 'Failed to load stats');
    });
})();
