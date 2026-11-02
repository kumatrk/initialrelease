/**
 * Optee — Campaign Statistics page.
 */
(function ($) {
    'use strict';

    /* =====================================================================
       Report extension points.

       Lets other plugins add to the campaign report without patching this file
       or scraping its DOM. Providers are functions, called on every render, so
       registration order and load order don't matter.

         Optee.Report.registerColumnGroup(function (ctx) {
             if (ctx.drilldown !== 'default') { return null; }   // null = skip
             return {
                 key: 'my_group',                 // unique group id
                 label: 'MY GROUP',               // group header band
                 after: 'traffic_source',         // place after this group (optional)
                 columns: [
                     { key: 'my_col', label: 'My Col', type: 'number',
                       valueFn: function (row) { return 123; } }
                 ]
             };
         });

         Optee.Report.registerZoneMenuItem(function (ctx) {
             // ctx: { value, dimension, drilldown, campaignId, modules }
             return { label: 'Do something', color: '#7c3aed', icon: '<svg…></svg>',
                      onClick: function () { … } };            // or an array, or null
         });

       Optee.Report.refresh() re-renders the table (use after data changes).
       ===================================================================== */

    window.Optee = window.Optee || {};
    Optee.Report = Optee.Report || {};
    Optee.Report.columnGroupProviders = Optee.Report.columnGroupProviders || [];
    Optee.Report.zoneMenuProviders    = Optee.Report.zoneMenuProviders || [];
    Optee.Report.rowMarkerProviders   = Optee.Report.rowMarkerProviders || [];

    Optee.Report.registerColumnGroup = function (fn) {
        if (typeof fn === 'function') { Optee.Report.columnGroupProviders.push(fn); }
    };
    Optee.Report.registerZoneMenuItem = function (fn) {
        if (typeof fn === 'function') { Optee.Report.zoneMenuProviders.push(fn); }
    };
    /**
     * Small marker appended to the first cell of a row (like the note dot).
     * Return an HTML string, or null. Bind clicks with a delegated handler on
     * your own class — core only renders what you return.
     *
     *   Optee.Report.registerRowMarker(function (ctx) {   // {value,row,drilldown}
     *       return hasThing(ctx.value) ? '<span class="my-dot"></span>' : null;
     *   });
     */
    Optee.Report.registerRowMarker = function (fn) {
        if (typeof fn === 'function') { Optee.Report.rowMarkerProviders.push(fn); }
    };

    /* =====================================================================
       Column group definitions per module type.
       ===================================================================== */

    var COL_GROUPS = {
        traffic_source: {
            label: 'Traffic Source',
            headerCls: 'optee-gh-ts',
            columns: [
                { key: 'impressions', label: 'Impressions', type: 'number' },
                { key: 'clicks', label: 'Clicks', type: 'number' },
                { key: 'conversions', label: 'Conversions', type: 'number' },
                { key: 'cost', label: 'Cost', type: 'money' },
                { key: 'payout', label: 'Payout', type: 'money' },
                { key: 'profit', label: 'Profit', type: 'money', color: 'profit' },
                { key: 'roi', label: 'ROI', type: 'percent', color: 'profit', derive: { num: 'profit', den: 'cost', mult: 100 } },
                { key: 'cpm', label: 'CPM', type: 'money', derive: { num: 'cost', den: 'impressions', mult: 1000 } },
                { key: 'cpc', label: 'CPC', type: 'money', derive: { num: 'cost', den: 'clicks' } },
                { key: 'cpa', label: 'CPA', type: 'money', derive: { num: 'cost', den: 'conversions' } },
                { key: 'ctr', label: 'CTR', type: 'percent', derive: { num: 'clicks', den: 'impressions', mult: 100 } },
                { key: 'cr', label: 'CR', type: 'percent', derive: { num: 'conversions', den: 'clicks', mult: 100 } },
                { key: 'ts_balance', label: 'Balance', type: 'money', daysOnly: true, noSum: true } // Daily snapshot from Balances table; days report only, not additive.
            ]
        },
        antifraud: {
            label: 'Antifraud',
            headerCls: 'optee-gh-af',
            columns: [
                { key: 'af_visits', label: 'Visits', type: 'number' },
                { key: 'valid_hits', label: 'Valid Hits', type: 'number' },
                { key: 'valid_percent', label: 'Valid %', type: 'percent', color: 'valid_traffic', derive: { num: 'valid_hits', den: 'af_visits', mult: 100 } },
                { key: 'traffic_loss', label: 'Traffic Loss %', type: 'percent' },
                { key: 'proxy_percent', label: 'Proxy %', type: 'percent' },
                { key: 'junk_percent', label: 'Junk %', type: 'percent' },
                { key: 'bot_percent', label: 'Bot %', type: 'percent', color: 'bot_threshold' },
                { key: 'af_cost', label: 'Cost', type: 'money' }
            ]
        },
        tracker: {
            label: 'Tracker',
            headerCls: 'optee-gh-tracker',
            columns: [
                { key: 'tr_visits', label: 'Visits', type: 'number' },
                { key: 'tr_clicks', label: 'Clicks', type: 'number' },
                { key: 'tr_conv_all', label: 'Conv.', type: 'number' },
                { key: 'tr_revenue', label: 'Revenue', type: 'money' },
                { key: 'tr_cost', label: 'Cost', type: 'money' },
                { key: 'tr_profit', label: 'Profit', type: 'money', color: 'profit' },
                { key: 'tr_roi', label: 'ROI', type: 'percent', color: 'profit', derive: { num: 'tr_profit', den: 'tr_cost', mult: 100 } },
                { key: 'tr_ctr', label: 'CTR', type: 'percent', derive: { num: 'tr_clicks', den: 'tr_visits', mult: 100 } },
                { key: 'tr_cv', label: 'CV', type: 'percent', derive: { num: 'tr_conv_all', den: 'tr_visits', mult: 100 } },
                { key: 'tr_cpm', label: 'CPM', type: 'money', derive: { num: 'tr_cost', den: 'tr_visits', mult: 1000 } },
                { key: 'tr_cpa', label: 'CPA', type: 'money', derive: { num: 'tr_cost', den: 'tr_conv_all' } },
                { key: 'tr_ap', label: 'AP', type: 'money', derive: { num: 'tr_revenue', den: 'tr_conv_all' } }
            ]
        },
        monetag_mon: {
            label: 'Monetag',
            headerCls: 'optee-gh-mon',
            columns: [
                { key: 'mtg_earnings', label: 'Earnings', type: 'money' },
                { key: 'mtg_impressions', label: 'Impressions', type: 'number' },
                { key: 'mtg_ecpm', label: 'eCPM', type: 'money', derive: { num: 'mtg_earnings', den: 'mtg_impressions', mult: 1000 } }
            ]
        },
        propush_mon: {
            label: 'ProPush',
            headerCls: 'optee-gh-mon',
            columns: [
                { key: 'pp_subscribed', label: 'Subscribed', type: 'number' },
                { key: 'pp_impressions', label: 'Impressions', type: 'number' },
                { key: 'pp_earnings', label: 'Profit', type: 'money' },
                { key: 'pp_cpm', label: 'CPM', type: 'money', derive: { num: 'pp_earnings', den: 'pp_impressions', mult: 1000 } }
            ]
        },
        ph_mon: {
            label: 'Partners.House',
            headerCls: 'optee-gh-mon',
            columns: [
                { key: 'ph_subscriptions', label: 'Subscriptions', type: 'number' },
                { key: 'ph_impressions', label: 'Impressions', type: 'number' },
                { key: 'ph_earnings', label: 'Profit', type: 'money' },
                { key: 'ph_cpm', label: 'CPM', type: 'money', derive: { num: 'ph_earnings', den: 'ph_impressions', mult: 1000 } }
            ]
        },
        rap_mon: {
            label: 'RollerAds Publisher',
            headerCls: 'optee-gh-mon',
            columns: [
                { key: 'rap_requests', label: 'Requests', type: 'number' },
                { key: 'rap_subscribed', label: 'Subscribed', type: 'number' },
                { key: 'rap_unsubscribed', label: 'Unsubscribed', type: 'number' },
                // Row values come from the API verbatim (matching the RollerAds dashboard);
                // derive only kicks in on the SUM row, so the total is subs/requests
                // weighted by volume rather than a mean of daily percentages.
                { key: 'rap_cr', label: 'CR', type: 'percent', derive: { num: 'rap_subscribed', den: 'rap_requests', mult: 100 } },
                { key: 'rap_impressions', label: 'Impressions', type: 'number' },
                { key: 'rap_earnings', label: 'Profit', type: 'money' },
                { key: 'rap_cpm', label: 'CPM', type: 'money', derive: { num: 'rap_earnings', den: 'rap_impressions', mult: 1000 } }
            ]
        },
        hta_mon: {
            label: 'HilltopAds Publisher',
            headerCls: 'optee-gh-mon',
            columns: [
                { key: 'hta_impressions', label: 'Impressions', type: 'number' },
                { key: 'hta_clicks', label: 'Clicks', type: 'number' },
                { key: 'hta_earnings', label: 'Profit', type: 'money' },
                { key: 'hta_cpm', label: 'CPM', type: 'money', derive: { num: 'hta_earnings', den: 'hta_impressions', mult: 1000 } }
            ]
        },
        mtzr_mon: {
            label: 'Monetizer',
            headerCls: 'optee-gh-mon',
            columns: [
                { key: 'mtzr_clicks', label: 'Clicks', type: 'number' },
                { key: 'mtzr_leads', label: 'Leads', type: 'number' },
                { key: 'mtzr_revenue', label: 'Revenue', type: 'money' },
                { key: 'mtzr_epc', label: 'EPC', type: 'money', derive: { num: 'mtzr_revenue', den: 'mtzr_clicks' } }
            ]
        },
        zeydoo_mon: {
            label: 'Zeydoo',
            headerCls: 'optee-gh-mon',
            columns: [
                { key: 'zd_requests', label: 'Requests', type: 'number' },
                { key: 'zd_impressions', label: 'Impressions', type: 'number' },
                { key: 'zd_conversions', label: 'Conversions', type: 'number' },
                { key: 'zd_earnings', label: 'Earnings', type: 'money' },
                { key: 'zd_cpm', label: 'CPM', type: 'money', derive: { num: 'zd_earnings', den: 'zd_impressions', mult: 1000 } }
            ]
        },
        conversions: {
            label: 'Conversions',
            headerCls: 'optee-gh-tracker',
            columns: [
                { key: 'timestamp',      label: 'Postback Time',   type: 'text', align: 'left' },
                { key: 'click_id',       label: 'Click ID',        type: 'text', align: 'left' },
                { key: 'external_id',    label: 'External ID',     type: 'text', align: 'left' },
                { key: 'offer',          label: 'Offer',           type: 'text', align: 'left' },
                { key: 'transaction_id', label: 'Transaction ID',  type: 'text', align: 'left' },
                { key: 'revenue',        label: 'Revenue',         type: 'money', decimals: 3 },
                { key: 'status',         label: 'Status',          type: 'text', align: 'left' },
                { key: 'country',        label: 'Country',         type: 'text', align: 'left' }
            ]
        },
        custom: {
            label: 'Custom',
            headerCls: 'optee-gh-custom',
            columns: []   // populated per render by buildActiveGroups()
        }
    };

    /* =====================================================================
       Custom Columns — formula tokenizer + evaluator.

       Mirrors the PHP parser in class-custom-columns.php. Tokens of the
       form "[Group] Label" are matched greedily against opteeData.customColumnTokens
       and replaced by their internal row-data keys.
       ===================================================================== */

    var CC = {
        _tokenStringsSorted: null,
        _compiledCache: {},   // formula string → RPN array

        tokenMap: function () {
            return (typeof opteeData !== 'undefined' && opteeData.customColumnTokens) || {};
        },

        tokenStringsSorted: function () {
            if (this._tokenStringsSorted) return this._tokenStringsSorted;
            var keys = Object.keys(this.tokenMap());
            keys.sort(function (a, b) { return b.length - a.length; });
            this._tokenStringsSorted = keys;
            return keys;
        },

        /**
         * Tokenize a formula string. Returns an array of tokens or null on
         * syntax / unknown-token failure.
         *
         * Token types: 'num' (value: number), 'key' (value: internal row key),
         * 'op' (value: + - * /), 'lp', 'rp'.
         */
        tokenize: function (formula) {
            var map = this.tokenMap();
            var sorted = this.tokenStringsSorted();
            var tokens = [];
            var i = 0;
            var len = formula.length;

            while (i < len) {
                var ch = formula.charAt(i);

                if (ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r') { i++; continue; }

                if (ch === '+' || ch === '-' || ch === '*' || ch === '/') {
                    tokens.push({ type: 'op', value: ch });
                    i++; continue;
                }
                if (formula.substr(i, 1) === '×') { tokens.push({ type: 'op', value: '*' }); i++; continue; }
                if (formula.substr(i, 1) === '÷') { tokens.push({ type: 'op', value: '/' }); i++; continue; }

                if (ch === '(') { tokens.push({ type: 'lp' }); i++; continue; }
                if (ch === ')') { tokens.push({ type: 'rp' }); i++; continue; }

                // Number literal.
                if ((ch >= '0' && ch <= '9') || (ch === '.' && i + 1 < len && formula.charAt(i + 1) >= '0' && formula.charAt(i + 1) <= '9')) {
                    var start = i, hasDot = false;
                    while (i < len) {
                        var c2 = formula.charAt(i);
                        if (c2 >= '0' && c2 <= '9') { i++; continue; }
                        if (c2 === '.' && !hasDot) { hasDot = true; i++; continue; }
                        break;
                    }
                    tokens.push({ type: 'num', value: parseFloat(formula.substring(start, i)) });
                    continue;
                }

                // Field token.
                if (ch === '[') {
                    var matched = null;
                    for (var k = 0; k < sorted.length; k++) {
                        if (formula.substr(i, sorted[k].length) === sorted[k]) { matched = sorted[k]; break; }
                    }
                    if (!matched) return null;
                    tokens.push({ type: 'key', value: map[matched].key });
                    i += matched.length;
                    continue;
                }

                return null;
            }

            return tokens;
        },

        /**
         * Convert tokenized formula to RPN (shunting-yard).
         */
        toRPN: function (tokens) {
            var output = [], stack = [];
            var prec = { '+': 1, '-': 1, '*': 2, '/': 2 };
            // Handle unary minus by tracking whether prev token expects a binary op.
            var prevType = null;
            for (var i = 0; i < tokens.length; i++) {
                var t = tokens[i];
                if (t.type === 'num' || t.type === 'key') {
                    output.push(t);
                } else if (t.type === 'op') {
                    // Unary minus: -x becomes (0 - x).
                    if (t.value === '-' && (prevType === null || prevType === 'op' || prevType === 'lp')) {
                        output.push({ type: 'num', value: 0 });
                    }
                    while (stack.length && stack[stack.length - 1].type === 'op' &&
                        prec[stack[stack.length - 1].value] >= prec[t.value]) {
                        output.push(stack.pop());
                    }
                    stack.push(t);
                } else if (t.type === 'lp') {
                    stack.push(t);
                } else if (t.type === 'rp') {
                    while (stack.length && stack[stack.length - 1].type !== 'lp') {
                        output.push(stack.pop());
                    }
                    if (stack.length) stack.pop();   // discard '('
                }
                prevType = t.type;
            }
            while (stack.length) output.push(stack.pop());
            return output;
        },

        /**
         * Compile and cache a formula. Returns RPN array or null on failure.
         */
        compile: function (formula) {
            if (this._compiledCache[formula]) return this._compiledCache[formula];
            var tokens = this.tokenize(formula);
            if (!tokens) return null;
            var rpn = this.toRPN(tokens);
            this._compiledCache[formula] = rpn;
            return rpn;
        },

        /**
         * Evaluate compiled RPN against a row. Returns a number or null
         * (null = missing data, division-by-zero, or NaN result).
         *
         * @param {boolean} missingAsZero  When true, a referenced field that is
         *   missing/empty counts as 0 instead of voiding the whole result. Used by
         *   columns like Click Loss so a zone that never reached the next stage
         *   (e.g. no antifraud/tracker row) reads as full loss rather than blank.
         */
        evalRPN: function (rpn, row, missingAsZero) {
            if (!rpn) return null;
            var stack = [];
            for (var i = 0; i < rpn.length; i++) {
                var t = rpn[i];
                if (t.type === 'num') {
                    stack.push(t.value);
                } else if (t.type === 'key') {
                    var v = row[t.value];
                    var n;
                    if (v === null || v === undefined || v === '') {
                        if (!missingAsZero) return null;
                        n = 0;
                    } else {
                        n = parseFloat(v);
                        if (isNaN(n)) {
                            if (!missingAsZero) return null;
                            n = 0;
                        }
                    }
                    stack.push(n);
                } else if (t.type === 'op') {
                    var b = stack.pop(), a = stack.pop();
                    if (a === undefined || b === undefined) return null;
                    var r;
                    if (t.value === '+') r = a + b;
                    else if (t.value === '-') r = a - b;
                    else if (t.value === '*') r = a * b;
                    else if (t.value === '/') { if (b === 0) return null; r = a / b; }
                    if (isNaN(r) || !isFinite(r)) return null;
                    stack.push(r);
                }
            }
            return stack.length ? stack[0] : null;
        }
    };

    /* =====================================================================
       Custom Columns — recommended templates.
       Each is a self-contained spec POSTed to optee_save_custom_column.
       ===================================================================== */

    var CC_TEMPLATES = [
        {
            name: 'True Profit',
            description: 'Tracker revenue minus what the traffic source actually billed',
            formula: '[Tracker] Revenue - [Traffic Source] Cost',
            format: 'money',
            decimals: 2,
            color: 'profit'
        },
        {
            name: 'True ROI',
            description: 'True profit as a percentage of traffic source cost',
            formula: '([Tracker] Revenue - [Traffic Source] Cost) / [Traffic Source] Cost * 100',
            format: 'percent',
            decimals: 2,
            color: 'profit'
        },
        {
            name: 'Effective CPA',
            description: 'True cost per conversion (uses what the traffic source actually billed)',
            formula: '[Traffic Source] Cost / [Tracker] Conversions',
            format: 'money',
            decimals: 2,
            color: ''
        },
        {
            name: 'Click Loss % (push/banner)',
            description: 'Share of paid clicks that never reached the tracker. Use for push / banner traffic.',
            formula: '([Traffic Source] Clicks - [Tracker] Visits) / [Traffic Source] Clicks * 100',
            format: 'percent',
            decimals: 2,
            color: 'lower_better'
        },
        {
            name: 'Click Loss % (popunder)',
            description: 'Share of paid impressions that never reached the tracker. Use for popunder traffic.',
            formula: '([Traffic Source] Impressions - [Tracker] Visits) / [Traffic Source] Impressions * 100',
            format: 'percent',
            decimals: 2,
            color: 'lower_better'
        }
    ];

    /* =====================================================================
       Drill-down options.
       ===================================================================== */

    var DRILLDOWNS = [
        { key: 'default',     label: 'Default', icon: 'bar-chart'  },
        { key: 'geo',         label: 'Countries',      icon: 'map-pin', dropdown: [
            { key: 'geo',               label: 'Countries' },
            { key: 'languages',         label: 'Languages' },
            { key: 'cities',            label: 'Cities' },
            { key: 'regions',           label: 'State / Region' }
        ]},
        { key: 'browsers',    label: 'Browsers',       icon: 'globe', dropdown: [
            { key: 'browsers',          label: 'Browsers' },
            { key: 'browser-versions',  label: 'Browser Versions' },
            { key: 'useragents',        label: 'User Agents' }
        ]},
        { key: 'isp',         label: 'ISP',            icon: 'wifi', dropdown: [
            { key: 'isp',               label: 'ISP' },
            { key: 'mobile-carriers',   label: 'Mobile Carriers' },
            { key: 'connection',        label: 'Connection' }
        ]},
        { key: 'os',          label: 'OS',             icon: 'monitor', dropdown: [
            { key: 'os',            label: 'OS' },
            { key: 'os-versions',   label: 'OS Versions' }
        ]},
        { key: 'lp',          label: 'Landings',       icon: 'layout'     },
        { key: 'offers',      label: 'Offers',         icon: 'tag'        },
        { key: 'conversions', label: 'Conversions',    icon: 'dollar'     },
        { key: 'days',        label: 'Days',            icon: 'calendar'   }
        // Custom zone fields dropdown is appended dynamically by loadZoneFields().
    ];

    /* =====================================================================
       Timeframe options.
       ===================================================================== */

    var TIMEFRAMES = [
        { value: 'today',      label: 'Today' },
        { value: 'yesterday',  label: 'Yesterday' },
        { value: '3d',         label: 'Last 3 days' },
        { value: '7d',         label: 'Last 7 days' },
        { value: '14d',        label: 'Last 14 days' },
        { value: '30d',        label: 'Last 30 days' },
        { value: '60d',        label: 'Last 60 days' },
        { value: '90d',        label: 'Last 90 days' },
        { value: '180d',       label: 'Last 180 days' },
        { value: '360d',       label: 'Last 360 days' },
        { value: 'this_month', label: 'This month' },
        { value: 'last_month', label: 'Last month' }
    ];

    /* =====================================================================
       SVG icon map (Lucide 14x14).
       ===================================================================== */

    var ICONS = {
        'bar-chart': '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>',
        'map-pin':   '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg>',
        'globe':     '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
        'wifi':      '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h.01"/><path d="M2 8.82a15 15 0 0 1 20 0"/><path d="M5 12.859a10 10 0 0 1 14 0"/><path d="M8.5 16.429a5 5 0 0 1 7 0"/></svg>',
        'monitor':   '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>',
        'link':      '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        'layout':    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
        'tag':       '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg>',
        'dollar':    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'clock':     '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'calendar':  '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>',
        'settings':  '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
        'folder':    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>',
        'smartphone':'<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>',
        'scaling':   '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14"/><path d="M21 12V5a2 2 0 0 0-2-2"/><path d="M3 21h18"/><path d="M17 21v-4a2 2 0 0 0-2-2h-4"/></svg>'
    };

    /* =====================================================================
       Country list (ISO 3166 Alpha-2) for Monetag geo filter.
       ===================================================================== */

    var MONETAG_COUNTRIES = [
        ['AF','Afghanistan'],['AX','Aland Islands'],['AL','Albania'],['DZ','Algeria'],
        ['AS','American Samoa'],['AD','Andorra'],['AO','Angola'],['AI','Anguilla'],
        ['AQ','Antarctica'],['AG','Antigua And Barbuda'],['AR','Argentina'],['AM','Armenia'],
        ['AW','Aruba'],['AU','Australia'],['AT','Austria'],['AZ','Azerbaijan'],
        ['BS','Bahamas'],['BH','Bahrain'],['BD','Bangladesh'],['BB','Barbados'],
        ['BY','Belarus'],['BE','Belgium'],['BZ','Belize'],['BJ','Benin'],
        ['BM','Bermuda'],['BT','Bhutan'],['BO','Bolivia'],['BQ','Bonaire, Sint Eustatius And Saba'],
        ['BA','Bosnia And Herzegovina'],['BW','Botswana'],['BV','Bouvet Island'],['BR','Brazil'],
        ['IO','British Indian Ocean Territory'],['BN','Brunei Darussalam'],['BG','Bulgaria'],['BF','Burkina Faso'],
        ['BI','Burundi'],['CV','Cabo Verde'],['KH','Cambodia'],['CM','Cameroon'],
        ['CA','Canada'],['KY','Cayman Islands'],['CF','Central African Republic'],['TD','Chad'],
        ['CL','Chile'],['CN','China'],['CX','Christmas Island'],['CC','Cocos (Keeling) Islands'],
        ['CO','Colombia'],['KM','Comoros'],['CD','Congo, Democratic Republic'],['CG','Congo'],
        ['CK','Cook Islands'],['CR','Costa Rica'],['CI','Cote d\'Ivoire'],['HR','Croatia'],
        ['CU','Cuba'],['CW','Curacao'],['CY','Cyprus'],['CZ','Czechia'],
        ['DK','Denmark'],['DJ','Djibouti'],['DM','Dominica'],['DO','Dominican Republic'],
        ['EC','Ecuador'],['EG','Egypt'],['SV','El Salvador'],['GQ','Equatorial Guinea'],
        ['ER','Eritrea'],['EE','Estonia'],['SZ','Eswatini'],['ET','Ethiopia'],
        ['FK','Falkland Islands'],['FO','Faroe Islands'],['FJ','Fiji'],['FI','Finland'],
        ['FR','France'],['GF','French Guiana'],['PF','French Polynesia'],['TF','French Southern Territories'],
        ['GA','Gabon'],['GM','Gambia'],['GE','Georgia'],['DE','Germany'],
        ['GH','Ghana'],['GI','Gibraltar'],['GR','Greece'],['GL','Greenland'],
        ['GD','Grenada'],['GP','Guadeloupe'],['GU','Guam'],['GT','Guatemala'],
        ['GG','Guernsey'],['GN','Guinea'],['GW','Guinea-Bissau'],['GY','Guyana'],
        ['HT','Haiti'],['HM','Heard Island And McDonald Islands'],['VA','Holy See'],['HN','Honduras'],
        ['HK','Hong Kong'],['HU','Hungary'],['IS','Iceland'],['IN','India'],
        ['ID','Indonesia'],['IR','Iran'],['IQ','Iraq'],['IE','Ireland'],
        ['IM','Isle Of Man'],['IL','Israel'],['IT','Italy'],['JM','Jamaica'],
        ['JP','Japan'],['JE','Jersey'],['JO','Jordan'],['KZ','Kazakhstan'],
        ['KE','Kenya'],['KI','Kiribati'],['KP','North Korea'],['KR','South Korea'],
        ['KW','Kuwait'],['KG','Kyrgyzstan'],['LA','Laos'],['LV','Latvia'],
        ['LB','Lebanon'],['LS','Lesotho'],['LR','Liberia'],['LY','Libya'],
        ['LI','Liechtenstein'],['LT','Lithuania'],['LU','Luxembourg'],['MO','Macao'],
        ['MK','North Macedonia'],['MG','Madagascar'],['MW','Malawi'],['MY','Malaysia'],
        ['MV','Maldives'],['ML','Mali'],['MT','Malta'],['MH','Marshall Islands'],
        ['MQ','Martinique'],['MR','Mauritania'],['MU','Mauritius'],['YT','Mayotte'],
        ['MX','Mexico'],['FM','Micronesia'],['MD','Moldova'],['MC','Monaco'],
        ['MN','Mongolia'],['ME','Montenegro'],['MS','Montserrat'],['MA','Morocco'],
        ['MZ','Mozambique'],['MM','Myanmar'],['NA','Namibia'],['NR','Nauru'],
        ['NP','Nepal'],['NL','Netherlands'],['NC','New Caledonia'],['NZ','New Zealand'],
        ['NI','Nicaragua'],['NE','Niger'],['NG','Nigeria'],['NU','Niue'],
        ['NF','Norfolk Island'],['MP','Northern Mariana Islands'],['NO','Norway'],['OM','Oman'],
        ['PK','Pakistan'],['PW','Palau'],['PS','Palestine'],['PA','Panama'],
        ['PG','Papua New Guinea'],['PY','Paraguay'],['PE','Peru'],['PH','Philippines'],
        ['PN','Pitcairn'],['PL','Poland'],['PT','Portugal'],['PR','Puerto Rico'],
        ['QA','Qatar'],['RE','Reunion'],['RO','Romania'],['RU','Russia'],
        ['RW','Rwanda'],['BL','Saint Barthelemy'],['SH','Saint Helena'],['KN','Saint Kitts And Nevis'],
        ['LC','Saint Lucia'],['MF','Saint Martin'],['PM','Saint Pierre And Miquelon'],['VC','Saint Vincent And The Grenadines'],
        ['WS','Samoa'],['SM','San Marino'],['ST','Sao Tome And Principe'],['SA','Saudi Arabia'],
        ['SN','Senegal'],['RS','Serbia'],['SC','Seychelles'],['SL','Sierra Leone'],
        ['SG','Singapore'],['SX','Sint Maarten'],['SK','Slovakia'],['SI','Slovenia'],
        ['SB','Solomon Islands'],['SO','Somalia'],['ZA','South Africa'],['GS','South Georgia And South Sandwich Islands'],
        ['SS','South Sudan'],['ES','Spain'],['LK','Sri Lanka'],['SD','Sudan'],
        ['SR','Suriname'],['SJ','Svalbard And Jan Mayen'],['SE','Sweden'],['CH','Switzerland'],
        ['SY','Syria'],['TW','Taiwan'],['TJ','Tajikistan'],['TZ','Tanzania'],
        ['TH','Thailand'],['TL','Timor-Leste'],['TG','Togo'],['TK','Tokelau'],
        ['TO','Tonga'],['TT','Trinidad And Tobago'],['TN','Tunisia'],['TR','Turkey'],
        ['TM','Turkmenistan'],['TC','Turks And Caicos Islands'],['TV','Tuvalu'],['UG','Uganda'],
        ['UA','Ukraine'],['AE','United Arab Emirates'],['GB','United Kingdom'],['UM','United States Minor Outlying Islands'],
        ['US','United States'],['UY','Uruguay'],['UZ','Uzbekistan'],['VU','Vanuatu'],
        ['VE','Venezuela'],['VN','Vietnam'],['VG','Virgin Islands, British'],['VI','Virgin Islands, U.S.'],
        ['WF','Wallis And Futuna'],['EH','Western Sahara'],['YE','Yemen'],['ZM','Zambia'],
        ['ZW','Zimbabwe']
    ];

    /* =====================================================================
       CampaignStats controller.
       ===================================================================== */

    var CampaignStats = {
        campaignId: 0,
        modules: {},
        moduleNames: {},
        activeGroups: [],
        allColumns: [],
        drilldown: 'default',
        timeframe: '7d',
        dateFrom: '',      // 'YYYY-MM-DD' — only used when timeframe === 'custom'
        dateTo: '',        // 'YYYY-MM-DD' — only used when timeframe === 'custom'
        tfViewMonth: null, // Date pinned to the 1st of the left-hand calendar month
        tfSelStart: null,  // Date — first clicked day of an in-progress custom selection
        tfSelEnd: null,    // Date — second clicked day
        sortKey: 'zone_id',
        sortDir: 'desc',
        userSorted: false,
        hideNoClicks: false,
        colFilters: {},    // colKey -> {op, v1, v2} — see applyColumnFilters
        selected: {},      // first-column value -> true, for bulk actions
        lastClickedRow: null,   // anchor for shift-click range selection
        hideEmpty: false,
        alwaysLive: false,

        /* ----- Auto-reload -----
         * Re-runs loadData() on an interval, which is byte-identical to the user
         * pressing the reload button: same drilldown, timeframe, member and zone
         * filter, and force_refresh only when always_live is on. Nothing here
         * decides how the data is fetched — that stays entirely in loadData().
         *
         * Session-scoped on purpose. This is a "watching this campaign right now"
         * mode, not a preference; persisting it to user meta would silently resume
         * polling weeks later from a tab nobody remembers opening.
         */
        autoReloadMs: 0,          // 0 = off
        autoReloadTimer: null,    // the interval tick
        autoReloadCountdown: null,// 1s ticker that repaints the countdown
        autoReloadNextAt: 0,      // epoch ms of the next fire
        autoReloadStartedAt: 0,   // for the idle expiry
        statsLoading: false,      // in-flight guard, so ticks never stack

        data: [],
        noteCounts: {},   // { '<zone id>': <count> } — drives the note dot in the zone cell
        blockedZones: [],
        whitelistedZones: [],
        targetedCountries: null,
        targetedTargeting: null,   // current targeted values for a dimension report (OS/browser/…)
        targetingDim: null,        // which dimension the Active toggle acts on
        isWhitelistCampaign: false,
        bdZonesLoaded: false,
        zoneFields: {},  // { custom1: 'Website ID', custom2: 'Quality score', ... }

        // Per-day breakout (default/zones report): standalone Today/Yesterday/… columns
        // for the chosen tracker metrics, filled by a lazy follow-up fetch.
        perdayConfig: { enabled: false, days: 4, metrics: ['tr_profit', 'tr_roi'] },
        perdayData: {},          // zone_id -> { tr_profit_d0: .., ... }
        perdayGroupLabels: {},   // 'perday_tr_profit' -> 'Profit / day'

        // Zone drilldown — when set, every report is scoped to this one zone (BeMob).
        zoneFilter: '',

        init: function () {
            var $el = $('#optee-campaign-stats');
            if (!$el.length) return;

            // Baseline for the popstate guard in onPopState(). readUrlState() only
            // runs on a pop, so without seeding this here the very first close of
            // the full-screen overlay compares against undefined and refetches.
            this.urlSnapshot = window.location.search;

            this.campaignId = $el.data('campaign-id');
            this.tsGrouped  = ($el.data('ts-grouped') == 1); // grouped traffic-source campaign (creatives)
            // When the traffic source exposes native per-country stats (e.g. PopAds),
            // the Countries (geo) drilldown becomes a side-by-side traffic-source ⇄
            // tracker table (merged by country) instead of tracker-only.
            this.tsSupportsCountries = ($el.data('ts-supports-countries') == 1);
            // Traffic source can map tracker OS/browser names → its targeting IDs →
            // show a "PopAds Target" column on the Browsers/OS tabs.
            this.tsSupportsTargeting = ($el.data('ts-supports-targeting') == 1);

            // Tracker-token dimensions (e.g. Category via [CATEGORYID]) the traffic
            // source forwards into tracker custom slots — each gets its own drilldown
            // tab showing tracker performance keyed by the PopAds targeting value.
            this.mappedDims = [];
            try { this.mappedDims = JSON.parse($el.attr('data-mapped-dims') || '[]') || []; } catch (e) {}
            this.mappedDimCfg = {};
            for (var mdi = 0; mdi < this.mappedDims.length; mdi++) {
                var md = this.mappedDims[mdi];
                if (!md || !md.dim) continue;
                this.mappedDimCfg[md.dim] = md;
                if (!DRILLDOWNS.some(function (d) { return d.key === md.dim; })) {
                    // Insert before the Days tab — Days always stays last.
                    var daysIdx = -1;
                    for (var di = 0; di < DRILLDOWNS.length; di++) { if (DRILLDOWNS[di].key === 'days') { daysIdx = di; break; } }
                    var iconByDim = { category: 'folder', form_factor: 'smartphone', resolution: 'scaling', isp: 'wifi' };
                    var item = { key: md.dim, label: md.label || md.dim, icon: iconByDim[md.dim] || 'tag' };
                    if (daysIdx >= 0) { DRILLDOWNS.splice(daysIdx, 0, item); } else { DRILLDOWNS.push(item); }
                }
            }

            // Per-day breakout config (per-campaign), from the template data attribute.
            try {
                var pdc = $el.attr('data-perday-config');
                if (pdc) {
                    var parsedPd = JSON.parse(pdc);
                    this.perdayConfig = {
                        enabled: !!parsedPd.enabled,
                        days: Math.max(1, Math.min(7, parseInt(parsedPd.days, 10) || 4)),
                        metrics: (Array.isArray(parsedPd.metrics) && parsedPd.metrics.length) ? parsedPd.metrics : ['tr_profit', 'tr_roi']
                    };
                }
            } catch (e) {}
            this.tsMember   = '';                            // '' = All campaigns; otherwise a single creative ID
            this.monetagZoneId   = $el.data('monetag-zone-id') || '';
            this.monetagZoneName = $el.data('monetag-zone-name') || '';
            this.monetagCountry  = $el.data('monetag-country') || '';
            this.propushZoneId   = $el.data('propush-zone-id') || '';
            this.propushZoneName = $el.data('propush-zone-name') || '';
            this.propushSiteId   = $el.data('propush-site-id') || '';
            this.propushSiteName = $el.data('propush-site-name') || '';
            this.propushSourceId = $el.data('propush-source-id') || '';
            this.propushCountry  = $el.data('propush-country') || '';
            this.phActive  = $el.data('ph-active') || '';
            this.phSub1    = $el.data('ph-sub1') || '';
            this.phSub2    = $el.data('ph-sub2') || '';
            this.phSub3    = $el.data('ph-sub3') || '';
            this.phSub4    = $el.data('ph-sub4') || '';
            this.phCountry = $el.data('ph-country') || '';
            this.rapSiteId   = $el.data('rap-site-id') || '';
            this.rapSiteName = $el.data('rap-site-name') || '';
            this.rapZoneId   = $el.data('rap-zone-id') || '';
            this.rapZoneName = $el.data('rap-zone-name') || '';
            this.htaSiteId   = $el.data('hta-site-id') || '';
            this.htaSiteName = $el.data('hta-site-name') || '';
            this.htaZoneId   = $el.data('hta-zone-id') || '';
            this.htaZoneName = $el.data('hta-zone-name') || '';
            this.mtzrLeagueId        = $el.data('mtzr-league-id') || '';
            this.mtzrLeagueName      = $el.data('mtzr-league-name') || '';
            this.mtzrCampaignKeyword = $el.data('mtzr-campaign-keyword') || '';
            this.zdRequestVar = $el.data('zd-request-var') || '';
            this.modules = {
                traffic_source: $el.data('ts-module') || '',
                tracker:        $el.data('tracker-module') || '',
                antifraud:      $el.data('antifraud-module') || '',
                monetag_mon:    this.monetagZoneId ? 'monetag' : '',
                propush_mon:    (this.propushZoneId || this.propushSiteId) ? 'propush' : '',
                ph_mon:         this.phActive ? 'partners-house' : '',
                rap_mon:        this.rapSiteId ? 'rollerads-publisher' : '',
                hta_mon:        this.htaSiteId ? 'hilltopads-publisher' : '',
                mtzr_mon:       this.mtzrLeagueId ? 'monetizer' : '',
                zeydoo_mon:     this.zdRequestVar ? 'zeydoo' : ''
            };
            this.moduleNames = {
                traffic_source: $el.data('ts-name') || '',
                tracker:        $el.data('tracker-name') || '',
                antifraud:      $el.data('antifraud-name') || '',
                monetag_mon:    'Monetag',
                propush_mon:    'ProPush',
                ph_mon:         'Partners.House',
                rap_mon:        'RollerAds Publisher',
                hta_mon:        'HilltopAds Publisher',
                mtzr_mon:       'Monetizer',
                zeydoo_mon:     'Zeydoo'
            };

            // Restore drilldown, timeframe, hide_no_clicks, and hide_empty from URL params.
            var params = new URLSearchParams(window.location.search);
            if (params.get('drilldown')) this.drilldown = params.get('drilldown');
            if (params.get('timeframe')) this.timeframe = params.get('timeframe');
            if (this.timeframe === 'custom') {
                var uf = params.get('date_from'), ut = params.get('date_to');
                if (/^\d{4}-\d{2}-\d{2}$/.test(uf) && /^\d{4}-\d{2}-\d{2}$/.test(ut)) {
                    this.dateFrom = uf;
                    this.dateTo = ut;
                } else {
                    this.timeframe = '7d'; // bad/missing custom dates → safe default
                }
            }
            if (params.get('hide_no_clicks') === '1') this.hideNoClicks = true;
            if (params.get('hide_empty') === '1') this.hideEmpty = true;
            if (params.get('always_live') === '1') this.alwaysLive = true;
            if (params.get('zone')) this.zoneFilter = params.get('zone');

            // Resume auto-reload within the same tab (e.g. after a drilldown
            // click reloads the view). Session-scoped, so a new tab starts off.
            try {
                var savedReload = parseInt(sessionStorage.getItem(this.autoReloadKey()), 10);
                if (savedReload > 0 && this.autoReloadChoices.indexOf(savedReload) !== -1) {
                    this.autoReloadMs = savedReload * 60000;
                    this.autoReloadStartedAt = Date.now();
                    this.startAutoReload();
                }
            } catch (e) { /* sessionStorage unavailable — auto-reload just starts off */ }

            this.buildActiveGroups();
            this.renderDrilldowns();
            this.renderTimeframe();
            this.renderTableHeader();
            this.initActionsModal();
            this.bindEvents();
            var self = this;
            window.addEventListener('popstate', function () { self.onPopState(); });
            this.renderZoneFilterChip();
            this.loadZoneFields();
            this.loadData();
            this.maybeShowRulesPrompt($el);
        },

        maybeShowRulesPrompt: function ($el) {
            var ruleCount = parseInt($el.data('rule-count')) || 0;
            if (ruleCount > 0) return;
            var storageKey = 'optee_rules_prompt_dismissed_' + this.campaignId;
            if (localStorage.getItem(storageKey)) return;

            var self = this;
            var createUrl = opteeData.dashboardUrl + '?module=rules&action=create&campaign_id=' + this.campaignId;

            var popup = '<div id="optee-rules-prompt" class="optee-modal-overlay" style="display:none;">' +
                '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:32px;width:420px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);text-align:center;">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 16px;display:block;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>' +
                '<div style="font-weight:600;font-size:16px;color:#111827;margin-bottom:8px;">No Optimization Rules</div>' +
                '<p style="font-size:13px;color:#6b7280;line-height:1.5;margin-bottom:20px;">This campaign has no automation rules yet. Rules can automatically block bad zones, pause traffic, and optimize your campaign based on real-time stats.</p>' +
                '<div style="display:flex;gap:8px;justify-content:center;">' +
                '<a href="' + createUrl + '" class="optee-btn-primary" style="text-decoration:none;">+ Add Rule</a>' +
                '<button id="optee-rules-prompt-dismiss" class="optee-btn-secondary">Dismiss</button>' +
                '</div></div></div>';

            $('body').append(popup);
            $('#optee-rules-prompt').fadeIn(150);

            $('#optee-rules-prompt-dismiss, #optee-rules-prompt > div:first-child').on('click', function () {
                localStorage.setItem(storageKey, '1');
                $('#optee-rules-prompt').fadeOut(150, function () { $(this).remove(); });
            });
        },

        /* ----- Campaign actions modal (gear icon) ----- */

        /* ----- Token Mapping (traffic-source dimension ↔ tracker custom slot) ----- */
        openTokenMappingModal: function () {
            var self = this;
            $('#optee-token-mapping-modal').remove();
            var closeX = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
            var modal = '<div id="optee-token-mapping-modal" class="optee-modal-overlay" style="display:flex;">' +
                '<div class="optee-tm-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.35);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:10px;padding:24px;width:580px;max-width:95vw;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button class="optee-tm-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;">' + closeX + '</button>' +
                '<div style="font-weight:600;font-size:15px;margin-bottom:4px;">Token Mapping</div>' +
                '<p style="font-size:12px;color:#6b7280;margin-bottom:16px;">Which tracker custom variable holds each traffic-source targeting value. Auto-detected from the tracker’s forwarded tokens — override only if your tracker is set up differently.</p>' +
                '<div id="optee-tm-body"><div style="text-align:center;color:#9ca3af;padding:24px;">Loading…</div></div>' +
                '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;"><button class="optee-btn-secondary optee-tm-close">Cancel</button><button class="optee-btn-primary optee-tm-save">Save</button></div>' +
                '</div></div>';
            $('body').append(modal);
            $('#optee-token-mapping-modal').on('click', '.optee-tm-close, .optee-tm-backdrop', function () {
                $('#optee-token-mapping-modal').remove();
            });

            Optee.Ajax.post('optee_get_token_mapping', { campaign_id: self.campaignId }).done(function (res) {
                if (!res.success) { $('#optee-tm-body').html('<div style="color:#dc2626;padding:16px;">Failed to load mapping.</div>'); return; }
                var dims = res.data.dimensions || [], slots = res.data.slots || [];
                var slotName = {}; for (var s = 0; s < slots.length; s++) slotName[slots[s].slot] = slots[s].name;
                if (!dims.length) { $('#optee-tm-body').html('<div style="color:#6b7280;padding:16px;">No mappable dimensions for this traffic source.</div>'); return; }
                var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="border-bottom:1px solid #e5e7eb;color:#6b7280;text-align:left;"><th style="padding:6px 8px;">Dimension</th><th style="padding:6px 8px;">Tracker variable</th></tr></thead><tbody>';
                for (var i = 0; i < dims.length; i++) {
                    var d = dims[i];
                    var autoTxt = d.auto_slot ? (d.auto_slot + (slotName[d.auto_slot] ? ' — ' + slotName[d.auto_slot] : '')) : 'not forwarded';
                    var opts = '<option value="">Auto (' + Optee.Util.escapeHtml(autoTxt) + ')</option>';
                    for (var s2 = 0; s2 < slots.length; s2++) {
                        var sl = slots[s2];
                        opts += '<option value="' + sl.slot + '"' + (d.override === sl.slot ? ' selected' : '') + '>' + Optee.Util.escapeHtml(sl.slot + ' — ' + sl.name) + '</option>';
                    }
                    opts += '<option value="none"' + (d.override === 'none' ? ' selected' : '') + '>Disabled</option>';
                    html += '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:8px;font-weight:500;">' + Optee.Util.escapeHtml(d.label) +
                        '<div style="color:#9ca3af;font-weight:400;font-size:11px;">' + Optee.Util.escapeHtml(d.token) + '</div></td>' +
                        '<td style="padding:8px;"><select class="optee-select optee-tm-dim" data-dim="' + d.dim + '" style="width:100%;">' + opts + '</select></td></tr>';
                }
                html += '</tbody></table>';
                $('#optee-tm-body').html(html);
            }).fail(function () { $('#optee-tm-body').html('<div style="color:#dc2626;padding:16px;">Network error.</div>'); });

            $('#optee-token-mapping-modal').on('click', '.optee-tm-save', function () {
                var $btn = $(this).prop('disabled', true).text('Saving…');
                var mapping = {};
                $('.optee-tm-dim').each(function () { var v = $(this).val(); if (v !== '') mapping[$(this).data('dim')] = v; });
                Optee.Ajax.post('optee_save_token_mapping', { campaign_id: self.campaignId, mapping: JSON.stringify(mapping) }).done(function (res) {
                    if (res.success) { Optee.Toast.show('Mapping saved — reloading…', 'success'); setTimeout(function () { location.reload(); }, 600); }
                    else { Optee.Toast.show((res.data && res.data.message) || 'Save failed.', 'error'); $btn.prop('disabled', false).text('Save'); }
                }).fail(function () { Optee.Toast.show('Save failed.', 'error'); $btn.prop('disabled', false).text('Save'); });
            });
        },

        initActionsModal: function () {
            var tsEditUrl = $('#optee-campaign-stats').data('ts-edit-url') || '';
            var tsStatsUrl = $('#optee-campaign-stats').data('ts-stats-url') || '';
            var actions = [];
            if (tsEditUrl) {
                actions.push({ icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>', label: 'Edit on traffic source' });
            }
            if (tsStatsUrl) {
                actions.push({ icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>', label: 'View statistics on traffic source' });
            }
            actions = actions.concat([
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>', label: 'Edit local campaign' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>', label: 'Copy tracker campaign ID' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" x2="15" y1="20" y2="20"/><line x1="12" x2="12" y1="4" y2="20"/></svg>', label: 'Update campaign title' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" fill="#ef4444" opacity="0.15"/><rect x="6" y="6" width="12" height="12" rx="1" fill="#ef4444" stroke="none"/></svg>', label: 'Stop traffic' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>', label: 'Resume traffic' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg>', label: 'Pause local campaign' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>', label: 'Resume local campaign' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>', label: 'Remove from dashboard' },
            ]);

            // Monetization source buttons — only show if the integration plugin is installed.
            var monSlugs = (typeof opteeData !== 'undefined' && opteeData.monSlugs) ? opteeData.monSlugs : [];
            var monActions = [
                { slug: 'monetag', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', label: 'Set Monetag Source' },
                { slug: 'propush', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', label: 'Set ProPush Source' },
                { slug: 'partners-house', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#e82f83" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', label: 'Set Partners.House Source' },
                { slug: 'rollerads-publisher', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', label: 'Set RollerAds Publisher Source' },
                { slug: 'hilltopads-publisher', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', label: 'Set HilltopAds Publisher Source' },
                { slug: 'monetizer', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', label: 'Set Monetizer Source' },
                { slug: 'zeydoo', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>', label: 'Set Zeydoo Source' },
            ];
            for (var mi = 0; mi < monActions.length; mi++) {
                if (monSlugs.indexOf(monActions[mi].slug) !== -1) {
                    actions.push({ icon: monActions[mi].icon, label: monActions[mi].label });
                }
            }

            actions = actions.concat([
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h3"/><path d="M15 3h6v6"/><path d="m21 3-8.5 8.5"/><path d="M10 13 4.5 8.5"/></svg>', label: 'Add Rule' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/></svg>', label: 'View Rules' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>', label: 'Notes' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>', label: 'Column settings' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>', label: 'Export CSV' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>', label: 'Print / Save as PDF' },
                { icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M8 18v-3"/><path d="M12 18v-5"/><path d="M16 18v-2"/></svg>', label: 'Campaign Report' }
            ]);

            // Token Mapping — only when the traffic source supports per-dimension
            // targeting (so there's a tracker↔source mapping worth reviewing/overriding).
            if ($('#optee-campaign-stats').data('ts-supports-targeting') == 1) {
                actions.push({ icon: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.17-2.83L3 3"/><path d="m15 9 6-6"/></svg>', label: 'Token Mapping' });
            }

            var tilesHtml = '';
            for (var i = 0; i < actions.length; i++) {
                var a = actions[i];
                tilesHtml += '<button type="button" class="optee-action-tile" data-action-label="' + Optee.Util.escapeHtml(a.label) + '">' +
                    '<span class="optee-action-tile-icon">' + a.icon + '</span>' +
                    '<span class="optee-action-tile-label">' + Optee.Util.escapeHtml(a.label) + '</span>' +
                    '</button>';
            }

            var modal = '<div id="optee-actions-modal" class="optee-modal-overlay" style="display:none;">' +
                '<div id="optee-actions-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:480px;max-width:95vw;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button id="optee-actions-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;z-index:1;" title="Close">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                '<div style="font-weight:600;font-size:14px;margin-bottom:12px;flex:0 0 auto;">Campaign Actions</div>' +
                '<input type="text" id="optee-actions-search" class="optee-input" placeholder="Search..." style="width:100%;margin-bottom:12px;flex:0 0 auto;">' +
                '<div id="optee-actions-grid" style="display:grid;gap:12px;overflow-y:auto;flex:1 1 auto;min-height:0;">' + tilesHtml + '</div>' +
                '</div></div>';

            $('body').append(modal);

            // Monetag source sub-modal.
            var monetagModal = '<div id="optee-monetag-modal" class="optee-modal-overlay" style="display:none;">' +
                '<div id="optee-monetag-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button id="optee-monetag-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                '<div style="font-weight:600;font-size:14px;margin-bottom:4px;">Set Monetag Source</div>' +
                '<div style="font-size:12px;color:#9ca3af;margin-bottom:16px;">Monetag data will only appear on the Days report.</div>' +
                '<div class="space-y-4">' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Zone</label>' +
                '<select id="optee-monetag-zone" class="optee-select w-full"><option value="">Loading zones...</option></select></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Country</label>' +
                '<select id="optee-monetag-country" class="optee-select w-full"><option value="">All Countries</option></select></div>' +
                '<div class="flex items-center gap-2 pt-2">' +
                '<button id="optee-monetag-save" class="optee-btn-primary">Save</button>' +
                '<button id="optee-monetag-clear" class="optee-btn-secondary">Clear</button>' +
                '<button id="optee-monetag-cancel" class="optee-btn-secondary">Cancel</button>' +
                '</div></div></div></div>';

            $('body').append(monetagModal);

            // ProPush source sub-modal.
            var propushModal = '<div id="optee-propush-modal" class="optee-modal-overlay" style="display:none;">' +
                '<div id="optee-propush-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button id="optee-propush-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                '<div style="font-weight:600;font-size:14px;margin-bottom:4px;">Set ProPush Source</div>' +
                '<div style="font-size:12px;color:#9ca3af;margin-bottom:16px;">ProPush data will only appear on the Days report.</div>' +
                '<div class="space-y-4">' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Zone <span style="color:#9ca3af;font-weight:400;">(select one or more)</span></label>' +
                '<input id="optee-propush-zone-search" type="text" class="optee-input w-full" placeholder="Search zones..." style="margin-bottom:6px;">' +
                '<div id="optee-propush-zone-list" class="optee-pp-list" style="max-height:150px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:4px 8px;font-size:13px;">Loading zones...</div></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Site <span style="color:#9ca3af;font-weight:400;">(select one or more)</span></label>' +
                '<input id="optee-propush-site-search" type="text" class="optee-input w-full" placeholder="Search sites..." style="margin-bottom:6px;">' +
                '<div id="optee-propush-site-list" class="optee-pp-list" style="max-height:150px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:4px 8px;font-size:13px;">All Sites</div></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Source ID <span style="color:#9ca3af;font-weight:400;">(one or more, comma-separated)</span></label>' +
                '<input id="optee-propush-source-id" type="text" class="optee-input w-full" placeholder="Optional — e.g. id1, id2, id3"></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Country</label>' +
                '<select id="optee-propush-country" class="optee-select w-full"><option value="">All Countries</option></select></div>' +
                '<div class="flex items-center gap-2 pt-2">' +
                '<button id="optee-propush-save" class="optee-btn-primary">Save</button>' +
                '<button id="optee-propush-clear" class="optee-btn-secondary">Clear</button>' +
                '<button id="optee-propush-cancel" class="optee-btn-secondary">Cancel</button>' +
                '</div></div></div></div>';

            $('body').append(propushModal);

            // Partners.House source sub-modal.
            var phModal = '<div id="optee-ph-modal" class="optee-modal-overlay" style="display:none;">' +
                '<div id="optee-ph-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button id="optee-ph-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                '<div style="font-weight:600;font-size:14px;margin-bottom:4px;">Set Partners.House Source</div>' +
                '<div style="font-size:12px;color:#9ca3af;margin-bottom:16px;">Partners.House data will only appear on the Days report.</div>' +
                '<div class="space-y-4">' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Sub1</label>' +
                '<input id="optee-ph-sub1" type="text" class="optee-input w-full" placeholder="Optional — filter by Sub1"></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Sub2</label>' +
                '<input id="optee-ph-sub2" type="text" class="optee-input w-full" placeholder="Optional — filter by Sub2"></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Sub3</label>' +
                '<input id="optee-ph-sub3" type="text" class="optee-input w-full" placeholder="Optional — filter by Sub3"></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Sub4</label>' +
                '<input id="optee-ph-sub4" type="text" class="optee-input w-full" placeholder="Optional — filter by Sub4"></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Country</label>' +
                '<select id="optee-ph-country" class="optee-select w-full"><option value="">All Countries</option></select></div>' +
                '<div class="flex items-center gap-2 pt-2">' +
                '<button id="optee-ph-save" class="optee-btn-primary">Save</button>' +
                '<button id="optee-ph-clear" class="optee-btn-secondary">Clear</button>' +
                '<button id="optee-ph-cancel" class="optee-btn-secondary">Cancel</button>' +
                '</div></div></div></div>';

            $('body').append(phModal);

            // RollerAds Publisher source sub-modal.
            var rapModal = '<div id="optee-rap-modal" class="optee-modal-overlay" style="display:none;">' +
                '<div id="optee-rap-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button id="optee-rap-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                '<div style="font-weight:600;font-size:14px;margin-bottom:4px;">Set RollerAds Publisher Source</div>' +
                '<div style="font-size:12px;color:#9ca3af;margin-bottom:16px;">RollerAds Publisher data will only appear on the Days report.</div>' +
                '<div class="space-y-4">' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Site</label>' +
                '<select id="optee-rap-site" class="optee-select w-full"><option value="">Loading sites...</option></select></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Zone (Ad Placement)</label>' +
                '<select id="optee-rap-zone" class="optee-select w-full"><option value="">All Zones</option></select></div>' +
                '<div class="flex items-center gap-2 pt-2">' +
                '<button id="optee-rap-save" class="optee-btn-primary">Save</button>' +
                '<button id="optee-rap-clear" class="optee-btn-secondary">Clear</button>' +
                '<button id="optee-rap-cancel" class="optee-btn-secondary">Cancel</button>' +
                '</div></div></div></div>';

            $('body').append(rapModal);

            // HilltopAds Publisher source sub-modal.
            var htaModal = '<div id="optee-hta-modal" class="optee-modal-overlay" style="display:none;">' +
                '<div id="optee-hta-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button id="optee-hta-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                '<div style="font-weight:600;font-size:14px;margin-bottom:4px;">Set HilltopAds Publisher Source</div>' +
                '<div style="font-size:12px;color:#9ca3af;margin-bottom:16px;">HilltopAds Publisher data will only appear on the Days report.</div>' +
                '<div class="space-y-4">' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Site</label>' +
                '<select id="optee-hta-site" class="optee-select w-full"><option value="">Loading sites...</option></select></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Zone (Ad Placement)</label>' +
                '<select id="optee-hta-zone" class="optee-select w-full"><option value="">All Zones</option></select></div>' +
                '<div class="flex items-center gap-2 pt-2">' +
                '<button id="optee-hta-save" class="optee-btn-primary">Save</button>' +
                '<button id="optee-hta-clear" class="optee-btn-secondary">Clear</button>' +
                '<button id="optee-hta-cancel" class="optee-btn-secondary">Cancel</button>' +
                '</div></div></div></div>';

            $('body').append(htaModal);

            // Monetizer source sub-modal.
            var mtzrModal = '<div id="optee-mtzr-modal" class="optee-modal-overlay" style="display:none;">' +
                '<div id="optee-mtzr-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button id="optee-mtzr-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                '<div style="font-weight:600;font-size:14px;margin-bottom:4px;">Set Monetizer Source</div>' +
                '<div style="font-size:12px;color:#9ca3af;margin-bottom:16px;">Monetizer data will only appear on the Days report.</div>' +
                '<div class="space-y-4">' +
                '<div><label class="optee-label" style="margin-bottom:4px;">League</label>' +
                '<select id="optee-mtzr-league" class="optee-select w-full"><option value="">Loading leagues...</option></select></div>' +
                '<div><label class="optee-label" style="margin-bottom:4px;">Campaign</label>' +
                '<select id="optee-mtzr-campaign" class="optee-select w-full"><option value="">All Campaigns</option></select></div>' +
                '<div class="flex items-center gap-2 pt-2">' +
                '<button id="optee-mtzr-save" class="optee-btn-primary">Save</button>' +
                '<button id="optee-mtzr-clear" class="optee-btn-secondary">Clear</button>' +
                '<button id="optee-mtzr-cancel" class="optee-btn-secondary">Cancel</button>' +
                '</div></div></div></div>';

            $('body').append(mtzrModal);

            // Zeydoo monetization modal.
            if (!$('#optee-zd-modal').length) {
                $('body').append(
                    '<div id="optee-zd-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;">' +
                    '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                    '<button id="optee-zd-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                    '<div style="font-weight:600;font-size:14px;margin-bottom:4px;">Set Zeydoo Source</div>' +
                    '<div style="font-size:12px;color:#9ca3af;margin-bottom:16px;">Zeydoo data will only appear on the Days report.</div>' +
                    '<div class="space-y-4">' +
                    '<div><label class="optee-label" style="margin-bottom:4px;">Request</label>' +
                    '<input type="text" id="optee-zd-request" class="optee-input" placeholder="e.g. 147caa8e-cd6a-4b6d-..." style="width:100%;"></div>' +
                    '</div>' +
                    '<div style="display:flex;gap:8px;margin-top:16px;">' +
                    '<button id="optee-zd-save" class="optee-btn-primary" style="flex:1;">Save</button>' +
                    '<button id="optee-zd-clear" class="optee-btn" style="flex:1;">Clear</button>' +
                    '</div></div></div>'
                );
            }
        },

        /* ----- Build column configuration based on connected modules ----- */

        buildActiveGroups: function () {
            var order = ['traffic_source', 'antifraud', 'tracker', 'monetag_mon', 'propush_mon', 'ph_mon', 'rap_mon', 'hta_mon', 'mtzr_mon', 'zeydoo_mon'];
            this.activeGroups = [];
            this.allColumns = [];

            // Monetization group keys (for days-only restriction).
            var monGroups = { monetag_mon: 1, propush_mon: 1, ph_mon: 1, rap_mon: 1, hta_mon: 1, mtzr_mon: 1, zeydoo_mon: 1 };

            // Conversions detail report: flat list, no sticky zone_id column.
            if (this.drilldown === 'conversions' && this.modules.tracker) {
                this.activeGroups.push('conversions');
                var cols = COL_GROUPS.conversions.columns;
                for (var c = 0; c < cols.length; c++) {
                    this.allColumns.push($.extend({}, cols[c]));
                }
                return;
            }

            // First column is always the date/zone — independent of any group.
            this.allColumns.push({ key: 'zone_id', label: this.drilldownLabel(), align: 'left', sticky: true });

            // Per-value Active toggle (enable/disable a targeting value on the traffic
            // source) on dimension reports where the TS exposes its targeting list —
            // OS / Browsers today. Sits right after the label, like zones/countries.
            if (this.targetingDim && this.targetedTargeting !== null) {
                this.allColumns.push({ key: 'targeting_toggle', label: 'Active', type: 'targeting_toggle', align: 'left', noSort: true, noSum: true });
            }

            // "PopAds Target" annotation column on the Browsers/OS tabs — shows which
            // traffic-source targeting option each tracker row maps to (a group-less
            // column that sits right after the first column).
            // Annotation column ("{TS} …"): browsers/OS map tracker names → PopAds
            // targets; mapped-token dims (Category, Form Factor) annotate per their
            // registry config (id→name or name→match). ISP has no catalog → no column.
            var hardTargetDims = { browsers:1, 'browser-versions':1, os:1, 'os-versions':1 };
            var mcfg = this.mappedDimCfg && this.mappedDimCfg[this.drilldown];
            if (this.tsSupportsTargeting && (hardTargetDims[this.drilldown] || (mcfg && mcfg.annotate))) {
                var tsName = this.moduleNames.traffic_source || 'Traffic Source';
                var annLabel = (mcfg && mcfg.annotate) ? (tsName + ' ' + (mcfg.ann_label || 'Target')) : (tsName + ' Target');
                this.allColumns.push({ key: 'ts_target', label: annLabel, align: 'left', noSum: true });
            }

            var trackerOnly = { geo:1, languages:1, cities:1, regions:1, browsers:1, 'browser-versions':1, useragents:1, isp:1, 'mobile-carriers':1, os:1, 'os-versions':1, connection:1, lp:1, offers:1, category:1 };
            // geo becomes a traffic-source ⇄ tracker side-by-side when the TS reports countries.
            var geoMerged = (this.drilldown === 'geo' && this.tsSupportsCountries);
            // Mapped-token dims (Category/ISP/Form Factor) are tracker-only (no TS perf).
            var isMappedDim = !!(this.mappedDimCfg && this.mappedDimCfg[this.drilldown]);
            for (var i = 0; i < order.length; i++) {
                var key = order[i];
                // Tracker-only drilldowns: skip traffic source, antifraud, monetization.
                // (geo keeps the traffic_source band when merged.)
                if ((trackerOnly[this.drilldown] || isMappedDim) && key !== 'tracker' && !(geoMerged && key === 'traffic_source')) continue;
                // Antifraud columns only on default and days reports.
                if (key === 'antifraud' && this.drilldown !== 'default' && this.drilldown !== 'days') continue;
                // Monetization groups only on days report.
                if (monGroups[key] && this.drilldown !== 'days') continue;
                if (this.modules[key] && COL_GROUPS[key]) {
                    this.activeGroups.push(key);
                    // Prepend toggle column inside traffic_source group on default drilldown.
                    if (key === 'traffic_source' && this.drilldown === 'default') {
                        this.allColumns.push({ key: 'zone_toggle', label: 'Active', type: 'toggle', group: 'traffic_source', noSort: true, noSum: true });
                    }
                    // Geo report: per-country Active toggle (enable/disable a country's
                    // targeting on the traffic source). Only when the TS exposes its
                    // targeted-country list.
                    if (key === 'traffic_source' && geoMerged && this.targetedCountries !== null) {
                        this.allColumns.push({ key: 'country_toggle', label: 'Active', type: 'country_toggle', group: 'traffic_source', noSort: true, noSum: true });
                    }
                    // Popunder networks have no clicks — hide clicks, ctr, cpc columns.
                    var popunderSlugs = { popads: 1, popcash: 1 };
                    var hideKeys = (key === 'traffic_source' && popunderSlugs[this.modules.traffic_source]) ? { clicks: 1, ctr: 1, cpc: 1 } : {};
                    var cols = COL_GROUPS[key].columns;
                    for (var j = 0; j < cols.length; j++) {
                        if (hideKeys[cols[j].key]) continue;
                        // Days-only columns (e.g. TS Balance) skip other drilldowns.
                        if (cols[j].daysOnly && this.drilldown !== 'days') continue;
                        this.allColumns.push($.extend({ group: key }, cols[j]));
                    }
                }
            }

            // Per-day breakout columns (default report only) — right after the tracker band.
            this.appendPerdayColumns();

            // Custom columns: append after every built-in group, auto-hide when
            // required fields aren't in the current view.
            this.appendCustomColumns();

            // Column groups contributed by other plugins (see Report extension points).
            this.applyExternalColumnGroups();
        },

        /**
         * Append the per-day breakout columns for the default (zones) report.
         * Metric-major: for each chosen metric, a band of standalone single-day
         * columns (Today, Yest, 2d, …). Values are filled by a lazy follow-up fetch
         * (loadPerday) and read straight off each row like any other column.
         */
        appendPerdayColumns: function () {
            this.perdayGroupLabels = {};
            if (!this.perdayConfig || !this.perdayConfig.enabled) return;
            if (this.drilldown !== 'default') return;
            if (!this.modules.tracker) return;

            var meta = {}; // tracker metric key -> spec (type/color/label)
            var tcols = COL_GROUPS.tracker.columns;
            for (var t = 0; t < tcols.length; t++) meta[tcols[t].key] = tcols[t];

            var days      = Math.max(1, Math.min(7, this.perdayConfig.days || 4));
            var dayLabels = this.perdayDayLabels(days);
            var metrics   = this.perdayConfig.metrics || [];
            var trName    = this.moduleNames.tracker || 'Tracker';

            for (var m = 0; m < metrics.length; m++) {
                var mk = metrics[m];
                var md = meta[mk];
                if (!md) continue; // unknown / non-tracker metric
                var groupKey = 'perday_' + mk;
                this.activeGroups.push(groupKey);
                this.perdayGroupLabels[groupKey] = trName + ': ' + (md.label || mk) + ' / day';
                for (var d = 0; d < days; d++) {
                    this.allColumns.push({
                        key: mk + '_d' + d,
                        label: dayLabels[d],
                        type: md.type,
                        color: md.color,
                        group: groupKey,
                        perday: true,
                        noSum: true
                    });
                }
            }
        },

        /** Short relative-day labels: Today, Yest, 2d, 3d, … */
        perdayDayLabels: function (days) {
            var labels = [];
            for (var d = 0; d < days; d++) {
                if (d === 0) labels.push('Today');
                else if (d === 1) labels.push('-1day');
                else labels.push('-' + d + 'days');
            }
            return labels;
        },

        /**
         * The library column definitions with this campaign's per-campaign
         * overrides applied (override wins over the global spec).
         */
        effectiveDefs: function () {
            var defs = (typeof opteeData !== 'undefined' && opteeData.customColumns) || [];
            var ov   = (typeof opteeData !== 'undefined' && opteeData.customColumnsOverrides) || {};
            return defs.map(function (d) {
                return ov[d.id] ? $.extend({}, d, ov[d.id], { overridden: true }) : d;
            });
        },

        /** Single library def for an id, override-applied (null if missing). */
        effectiveDef: function (id) {
            var defs = (typeof opteeData !== 'undefined' && opteeData.customColumns) || [];
            var base = null;
            for (var i = 0; i < defs.length; i++) { if (defs[i].id === id) { base = defs[i]; break; } }
            if (!base) return null;
            var ov = (typeof opteeData !== 'undefined' && opteeData.customColumnsOverrides) || {};
            return ov[id] ? $.extend({}, base, ov[id], { overridden: true }) : base;
        },

        /** Whether this campaign has a formula override for the column id. */
        isOverridden: function (id) {
            var ov = (typeof opteeData !== 'undefined' && opteeData.customColumnsOverrides) || {};
            return !!ov[id];
        },

        /**
         * Append user-defined custom columns whose required fields are all
         * present in the currently active built-in columns.
         */
        appendCustomColumns: function () {
            var defs = this.effectiveDefs();
            if (!defs.length) return;

            // Only columns assigned to this campaign are eligible.
            var enabledList = (typeof opteeData !== 'undefined' && opteeData.customColumnsEnabled) || [];
            var enabled = {};
            for (var e = 0; e < enabledList.length; e++) enabled[enabledList[e]] = true;

            // Build set of base row-data keys available in the current view,
            // plus a key -> module-group lookup (used to blank custom columns on
            // rows where every referenced module has no activity).
            var available = {};
            var keyGroup = {};
            for (var i = 0; i < this.allColumns.length; i++) {
                var k = this.allColumns[i].key;
                if (k) { available[k] = true; keyGroup[k] = this.allColumns[i].group; }
            }

            var added = [];
            for (var c = 0; c < defs.length; c++) {
                var def = defs[c];
                if (!enabled[def.id]) continue; // not assigned to this campaign
                if (!def.formula || !def.fields_used || !def.fields_used.length) continue;
                // All referenced fields must be present.
                var ok = true;
                for (var f = 0; f < def.fields_used.length; f++) {
                    if (!available[def.fields_used[f]]) { ok = false; break; }
                }
                if (!ok) continue;

                // Pre-compile formula.
                var rpn = CC.compile(def.formula);
                if (!rpn) continue;

                // Distinct module groups the formula depends on (traffic_source,
                // tracker, antifraud, *_mon). On a data row where EVERY referenced
                // group has no activity, the custom value is meaningless (e.g. a
                // "Cost TS" column would show $0.00 on a dead day) — so it gets
                // blanked. Requiring ALL groups empty preserves treat_missing_zero
                // semantics (e.g. Click Loss % still reads full-loss when the
                // traffic source has clicks but the tracker stage is missing).
                var depGroups = [];
                for (var g = 0; g < def.fields_used.length; g++) {
                    var grp = keyGroup[def.fields_used[g]];
                    if (grp && grp !== 'custom' && depGroups.indexOf(grp) === -1) depGroups.push(grp);
                }

                added.push({
                    key: 'cc__' + def.id,             // namespaced key so it never collides
                    label: def.name,
                    type: def.format || 'number',
                    color: def.color || null,
                    decimals: def.decimals,
                    group: 'custom',
                    isCustom: true,
                    depGroups: depGroups,
                    // Custom columns built on the daily Balance snapshot must be blanked
                    // on empty days too, same as the built-in ts_balance column.
                    refsTsBalance: Array.isArray(def.fields_used) && def.fields_used.indexOf('ts_balance') !== -1,
                    clampNegative: !!def.clamp_negative,      // show negative results as 0
                    treatMissingZero: !!def.treat_missing_zero, // count missing referenced fields as 0
                    formula: def.formula,
                    formulaRPN: rpn,
                    customId: def.id
                });
            }

            if (added.length) {
                this.activeGroups.push('custom');
                for (var a = 0; a < added.length; a++) this.allColumns.push(added[a]);
            }
        },

        /**
         * Merge column groups contributed by other plugins (see the Report
         * extension points at the top of this file).
         *
         * Columns must sit in allColumns in the same order as their group sits in
         * activeGroups — the header spans are counted from allColumns — so an
         * "after" placement splices into both lists rather than appending.
         */
        applyExternalColumnGroups: function () {
            this.externalGroups = {};
            var providers = (window.Optee && Optee.Report && Optee.Report.columnGroupProviders) || [];
            if (!providers.length) return;

            var ctx = {
                drilldown:  this.drilldown,
                campaignId: this.campaignId,
                modules:    this.modules,
                zoneFilter: this.zoneFilter
            };

            for (var i = 0; i < providers.length; i++) {
                var def;
                try {
                    def = providers[i](ctx);
                } catch (e) {
                    continue;   // a broken provider must never break the report
                }
                if (!def || !def.key || !def.columns || !def.columns.length) continue;
                if (this.activeGroups.indexOf(def.key) !== -1) continue;   // already added

                this.externalGroups[def.key] = {
                    label:     def.label || def.key,
                    headerCls: def.headerCls || 'optee-gh-custom'
                };

                // Group position.
                var gAt = def.after ? this.activeGroups.indexOf(def.after) : -1;
                if (gAt >= 0) { this.activeGroups.splice(gAt + 1, 0, def.key); }
                else          { this.activeGroups.push(def.key); }

                // Matching column position: after the last column of the anchor group.
                var cAt = -1;
                if (def.after) {
                    for (var ci = 0; ci < this.allColumns.length; ci++) {
                        if (this.allColumns[ci].group === def.after) cAt = ci;
                    }
                }
                var cols = [];
                for (var c = 0; c < def.columns.length; c++) {
                    var col = def.columns[c];
                    if (!col || !col.key) continue;
                    cols.push({
                        key:        col.key,
                        label:      col.label || col.key,
                        type:       col.type || 'number',
                        decimals:   col.decimals,
                        color:      col.color || null,
                        align:      col.align || null,
                        group:      def.key,
                        isExternal: true,
                        valueFn:    typeof col.valueFn === 'function' ? col.valueFn : null
                    });
                }
                if (!cols.length) continue;
                if (cAt >= 0) { this.allColumns.splice.apply(this.allColumns, [cAt + 1, 0].concat(cols)); }
                else          { this.allColumns = this.allColumns.concat(cols); }
            }
        },

        drilldownLabel: function () {
            // Mapped-token dims carry their own first-column label (e.g. "Category ID").
            if (this.mappedDimCfg && this.mappedDimCfg[this.drilldown] && this.mappedDimCfg[this.drilldown].first_label) {
                return this.mappedDimCfg[this.drilldown].first_label;
            }
            var map = {
                'default': 'Zone',
                'days': 'Date',
                'months': 'Month',
                'hours': 'Hour',
                'geo': 'Country',
                'ts_geo': 'Country',
                'category': 'Category ID',
                'languages': 'Language',
                'cities': 'City',
                'regions': 'State / Region',
                'browsers': 'Browser',
                'browser-versions': 'Browser Version',
                'useragents': 'User Agent',
                'isp': 'ISP',
                'mobile-carriers': 'Mobile Carrier',
                'os': 'OS',
                'os-versions': 'OS Version',
                'connection': 'Connection',
                'lp': 'Landing Page',
                'offers': 'Offer',
                'conversions': 'Conversion'
            };
            // Use human-readable zone field name if available (custom/token fields).
            if (this.zoneFields[this.drilldown]) {
                return this.zoneFields[this.drilldown];
            }
            return map[this.drilldown] || 'Zone';
        },

        /* ----- Load zone field names from tracker ----- */

        loadZoneFields: function () {
            var self = this;
            var $el = $('#optee-campaign-stats');
            var trackerSlug = $el.data('tracker-module');
            var trackerCampaignId = $el.data('tracker-campaign-id');
            if (!trackerSlug || !trackerCampaignId) return;

            Optee.Ajax.post('optee_get_tracker_zones', {
                module_slug: trackerSlug,
                campaign_id: trackerCampaignId
            }).done(function (res) {
                if (!res.success || !res.data.zones || !res.data.zones.length) return;

                var zones = res.data.zones;

                // Build zone field name map and dropdown items.
                var dropdownItems = [];
                for (var i = 0; i < zones.length; i++) {
                    var z = zones[i];
                    if (!z.zone_id) continue;
                    if (z.zone_name) {
                        self.zoneFields[z.zone_id] = z.zone_name;
                    }
                    // Extract short label from zone_id (e.g. "custom1" -> "Custom 1", "t1" -> "Token 1").
                    var shortLabel = z.zone_id;
                    var match = z.zone_id.match(/^custom(\d+)$/);
                    if (match) {
                        shortLabel = 'Custom ' + match[1];
                    } else {
                        match = z.zone_id.match(/^t(\d+)$/);
                        if (match) shortLabel = 'Token ' + match[1];
                    }
                    dropdownItems.push({
                        key: z.zone_id,
                        label: z.zone_name || shortLabel,
                        shortLabel: shortLabel,
                        suffix: shortLabel
                    });
                }

                // Store dropdown items for future use (Custom dropdown hidden for now).
                // To re-enable: uncomment the DRILLDOWNS.push block below.
                self._customDropdownItems = dropdownItems;

                // // Remove any previously added Custom dropdown, then append new one.
                // for (var d = DRILLDOWNS.length - 1; d >= 0; d--) {
                //     if (DRILLDOWNS[d]._isCustomZones) {
                //         DRILLDOWNS.splice(d, 1);
                //     }
                // }
                // DRILLDOWNS.push({
                //     key: dropdownItems[0].key,
                //     label: 'Custom',
                //     icon: 'settings',
                //     dropdown: dropdownItems,
                //     _isCustomZones: true
                // });

                // Re-render drilldowns and header to reflect updated names.
                // self.renderDrilldowns();
                // self.renderTableHeader();
            });
        },

        /* ----- Render drill-down buttons ----- */

        // Remove the body-level floating dropdown menu (drilldown caret dropdown).
        closeDrillFloat: function () {
            $('#optee-drill-float').remove();
        },

        renderDrilldowns: function () {
            var $container = $('#optee-stats-drilldowns');
            var zoneName = $('#optee-campaign-stats').data('zone-name') || '';
            var html = '';
            var self = this;

            // href for a tab. escapeHtml covers &<> but not quotes; the URL is
            // percent-encoded already, this just makes the attribute airtight.
            function href(key) {
                return Optee.Util.escapeHtml(self.buildUrl(key)).replace(/"/g, '&quot;');
            }

            for (var i = 0; i < DRILLDOWNS.length; i++) {
                var d = DRILLDOWNS[i];
                var label = d.label;
                var tooltip = '';
                if (d.key === 'default' && zoneName) {
                    tooltip = zoneName;
                }

                // Effective dropdown for this button. BeMob also supports a Month
                // grouping, so on BeMob campaigns the Days button becomes a dropdown.
                var dropdown = d.dropdown;
                if (d.key === 'days' && this.modules && this.modules.tracker === 'bemob') {
                    dropdown = [
                        { key: 'days',   label: 'Days' },
                        { key: 'months', label: 'Months' }
                    ];
                }

                if (dropdown) {
                    // Check if current drilldown is one of the dropdown items.
                    var isGroupActive = false;
                    var activeLabel = d.label;
                    for (var k = 0; k < dropdown.length; k++) {
                        if (dropdown[k].key === this.drilldown) {
                            isGroupActive = true;
                            activeLabel = dropdown[k].shortLabel || dropdown[k].label;
                            break;
                        }
                    }
                    var activeCls = isGroupActive ? ' active' : '';
                    var chevronBorder = isGroupActive ? 'border-left:1px solid rgba(255,255,255,0.3)' : 'border-left:1px solid #d1d5db';
                    html += '<div class="optee-drill-dropdown">';
                    html += '<a href="' + href(d.key) + '" draggable="false" class="optee-drill-btn optee-drill-has-dropdown' + activeCls + '" data-drilldown="' + d.key + '">' +
                        (ICONS[d.icon] || '') + ' ' + Optee.Util.escapeHtml(activeLabel) +
                        '<span class="optee-drill-chevron" style="' + chevronBorder + '"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span>' +
                        '</a>';
                    html += '<div class="optee-drill-dropdown-menu">';
                    for (var j = 0; j < dropdown.length; j++) {
                        var sub = dropdown[j];
                        var itemActive = sub.key === this.drilldown ? ' active' : '';
                        var itemHtml = Optee.Util.escapeHtml(sub.label);
                        if (sub.suffix) {
                            itemHtml += '<span style="color:#9ca3af;margin-left:6px;font-weight:400">' +
                                Optee.Util.escapeHtml(sub.suffix) + '</span>';
                        }
                        html += '<a href="' + href(sub.key) + '" draggable="false" class="optee-drill-dropdown-item' + itemActive + '" data-drilldown="' + sub.key + '">' +
                            itemHtml + '</a>';
                    }
                    html += '</div></div>';
                } else {
                    var active = d.key === this.drilldown ? ' active' : '';
                    var tipAttr = tooltip ? ' data-tip="' + Optee.Util.escapeHtml(tooltip) + '"' : '';
                    html += '<a href="' + href(d.key) + '" draggable="false" class="optee-drill-btn' + active + '" data-drilldown="' + d.key + '"' + tipAttr + '>' +
                        (ICONS[d.icon] || '') + ' ' + Optee.Util.escapeHtml(label) +
                        '</a>';
                }
            }

            $container.html(html);

            // Bring the active drilldown into view — on page load it can sit off-screen
            // in the horizontally-scrollable bar (e.g. a mapped dim like Resolution).
            this.scrollActiveDrilldownIntoView();

            // "Hide blocked" toggle lives only on the default (zones) report.
            // Reset it whenever drilldown changes so it never lingers active.
            // On the default report its actual visibility is decided after the
            // rows render (updateHideBlockedVisibility) — only shown when there
            // is at least one blocked zone to hide.
            if (this.drilldown !== 'default') {
                var $hb = $('#optee-hide-blocked-toggle');
                $hb.removeClass('is-visible is-active');
                $('#optee-stats-wrapper').removeClass('optee-hide-blocked');
                $hb.find('.optee-hide-blocked-label').text('Hide blocked');
            }
        },

        /**
         * Horizontally scroll the drilldown bar so the active tab is visible. Only
         * touches the container's scrollLeft (never the page), and measures via
         * getBoundingClientRect so it's robust regardless of offsetParent. Deferred a
         * tick so the buttons are laid out (matters on first load).
         */
        scrollActiveDrilldownIntoView: function () {
            var $container = $('#optee-stats-drilldowns');
            setTimeout(function () {
                var c = $container[0];
                if (!c) return;
                var $active = $container.find('.optee-drill-btn.active').first();
                if (!$active.length) return;
                var cRect = c.getBoundingClientRect();
                var aRect = $active[0].getBoundingClientRect();
                var pad = 12;
                if (aRect.left < cRect.left) {
                    c.scrollLeft -= (cRect.left - aRect.left) + pad;
                } else if (aRect.right > cRect.right) {
                    c.scrollLeft += (aRect.right - cRect.right) + pad;
                }
            }, 0);
        },

        /**
         * Show the "Hide blocked" toggle only on the zones report and only
         * when at least one blocked zone is present. Also clears any active
         * filter when there's nothing to hide, so the button can never end up
         * just hiding the SUM/AVG footer rows.
         */
        updateHideBlockedVisibility: function () {
            var $hb = $('#optee-hide-blocked-toggle');
            var hasBlocked = this.drilldown === 'default' &&
                $('#optee-stats-table tbody tr.optee-zone-blocked').length > 0;
            if (hasBlocked) {
                $hb.addClass('is-visible');
            } else {
                $hb.removeClass('is-visible is-active');
                $('#optee-stats-wrapper').removeClass('optee-hide-blocked');
                $hb.find('.optee-hide-blocked-label').text('Hide blocked');
            }
        },

        /* ----- Timeframe selector (presets + custom date-range picker) ----- */

        MAX_RANGE_DAYS: 365,

        // 'YYYY-MM-DD' from a Date, using local time (no UTC shift).
        tfFmtDate: function (d) {
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return d.getFullYear() + '-' + m + '-' + day;
        },

        // Date (local midnight) from a 'YYYY-MM-DD' string.
        tfParseDate: function (s) {
            var p = s.split('-');
            return new Date(+p[0], +p[1] - 1, +p[2]);
        },

        tfStartOfDay: function (d) {
            return new Date(d.getFullYear(), d.getMonth(), d.getDate());
        },

        // "Jun 1 – Jun 9, 2026"  (omits the year on the left side when both share it).
        tfPrettyRange: function (fromStr, toStr) {
            var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var a = this.tfParseDate(fromStr), b = this.tfParseDate(toStr);
            if (fromStr === toStr) {
                return MON[a.getMonth()] + ' ' + a.getDate() + ', ' + a.getFullYear();
            }
            var left = MON[a.getMonth()] + ' ' + a.getDate() + (a.getFullYear() !== b.getFullYear() ? ', ' + a.getFullYear() : '');
            var right = MON[b.getMonth()] + ' ' + b.getDate() + ', ' + b.getFullYear();
            return left + ' – ' + right;
        },

        // Label shown on the picker button.
        tfLabel: function () {
            if (this.timeframe === 'custom' && this.dateFrom && this.dateTo) {
                return this.tfPrettyRange(this.dateFrom, this.dateTo);
            }
            for (var i = 0; i < TIMEFRAMES.length; i++) {
                if (TIMEFRAMES[i].value === this.timeframe) return TIMEFRAMES[i].label;
            }
            return 'Last 7 days';
        },

        renderTimeframe: function () {
            $('#optee-tf-label').text(this.tfLabel());
        },

        openTfMenu: function () {
            // Seed the calendar view + in-progress selection from the current state.
            if (this.timeframe === 'custom' && this.dateFrom && this.dateTo) {
                this.tfSelStart = this.tfParseDate(this.dateFrom);
                this.tfSelEnd = this.tfParseDate(this.dateTo);
                this.tfViewMonth = new Date(this.tfSelEnd.getFullYear(), this.tfSelEnd.getMonth() - 1, 1);
            } else {
                this.tfSelStart = null;
                this.tfSelEnd = null;
                var now = new Date();
                this.tfViewMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            }
            this.renderTfMenu();
            $('#optee-tf-menu').show();
            $('#optee-tf').addClass('is-open');
        },

        closeTfMenu: function () {
            $('#optee-tf-menu').hide();
            $('#optee-tf').removeClass('is-open');
        },

        renderTfMenu: function () {
            var presets = '';
            for (var i = 0; i < TIMEFRAMES.length; i++) {
                var tf = TIMEFRAMES[i];
                var active = (this.timeframe === tf.value) ? ' is-active' : '';
                presets += '<button type="button" class="optee-tf-preset' + active + '" data-tf="' + tf.value + '">' +
                    Optee.Util.escapeHtml(tf.label) + '</button>';
            }
            var customActive = this.timeframe === 'custom' ? ' is-active' : '';
            presets += '<button type="button" class="optee-tf-preset optee-tf-preset-custom' + customActive + '" data-tf="custom">Custom range</button>';

            var html =
                '<div class="optee-tf-body">' +
                    '<div class="optee-tf-cal">' +
                        '<div class="optee-tf-cal-head">' +
                            '<button type="button" class="optee-tf-nav" data-nav="-1" aria-label="Previous month">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>' +
                            '</button>' +
                            '<div class="optee-tf-months" id="optee-tf-months"></div>' +
                            '<button type="button" class="optee-tf-nav" data-nav="1" aria-label="Next month">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="optee-tf-presets">' + presets + '</div>' +
                '</div>' +
                '<div class="optee-tf-foot">' +
                    '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;min-width:0;">' +
                        '<label class="optee-tf-hide-empty" style="display:inline-flex;align-items:center;gap:6px;font-size:0.8125rem;color:#374151;cursor:pointer;white-space:nowrap;" title="Hide days with no activity (campaign wasn\'t running). View-only — applies across all reports.">' +
                            '<input type="checkbox" id="optee-tf-hide-empty"' + (this.hideEmpty ? ' checked' : '') + ' style="cursor:pointer;"> Hide empty rows' +
                        '</label>' +
                        '<span class="optee-tf-foot-range" id="optee-tf-foot-range"></span>' +
                    '</div>' +
                    '<div class="optee-tf-foot-actions">' +
                        '<button type="button" class="optee-btn optee-btn-secondary optee-btn-sm" id="optee-tf-cancel">Cancel</button>' +
                        '<button type="button" class="optee-btn optee-btn-primary optee-btn-sm" id="optee-tf-apply">Apply</button>' +
                    '</div>' +
                '</div>';

            $('#optee-tf-menu').html(html);
            this.renderCalendars();
            this.updateTfFoot();
        },

        renderCalendars: function () {
            var left = this.tfViewMonth;
            var right = new Date(left.getFullYear(), left.getMonth() + 1, 1);
            $('#optee-tf-months').html(this.monthGridHtml(left) + this.monthGridHtml(right));
        },

        monthGridHtml: function (monthDate) {
            var MON = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            var DOW = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
            var year = monthDate.getFullYear(), month = monthDate.getMonth();
            var first = new Date(year, month, 1);
            var startDow = first.getDay();
            var daysInMonth = new Date(year, month + 1, 0).getDate();
            var today = this.tfStartOfDay(new Date());

            var selStart = this.tfSelStart ? this.tfStartOfDay(this.tfSelStart) : null;
            var selEnd = this.tfSelEnd ? this.tfStartOfDay(this.tfSelEnd) : null;
            // Normalise order for highlighting while a range is mid-selection.
            if (selStart && selEnd && selStart > selEnd) {
                var tmp = selStart; selStart = selEnd; selEnd = tmp;
            }

            var html = '<div class="optee-tf-month">';
            html += '<div class="optee-tf-month-name">' + MON[month] + ' ' + year + '</div>';
            html += '<div class="optee-tf-grid">';
            for (var d = 0; d < 7; d++) html += '<span class="optee-tf-dow">' + DOW[d] + '</span>';
            for (var b = 0; b < startDow; b++) html += '<span class="optee-tf-day is-empty"></span>';
            for (var day = 1; day <= daysInMonth; day++) {
                var cur = new Date(year, month, day);
                var cls = 'optee-tf-day';
                var disabled = cur > today;
                if (disabled) cls += ' is-disabled';
                if (cur.getTime() === today.getTime()) cls += ' is-today';
                if (selStart && cur.getTime() === selStart.getTime()) cls += ' is-start';
                if (selEnd && cur.getTime() === selEnd.getTime()) cls += ' is-end';
                if (selStart && selEnd && cur > selStart && cur < selEnd) cls += ' is-inrange';
                var attr = disabled ? '' : ' data-day="' + this.tfFmtDate(cur) + '"';
                html += '<span class="' + cls + '"' + attr + '>' + day + '</span>';
            }
            html += '</div></div>';
            return html;
        },

        // Footer text + Apply enabled-state reflect the in-progress selection.
        updateTfFoot: function () {
            var $range = $('#optee-tf-foot-range');
            var $apply = $('#optee-tf-apply');
            if (this.tfSelStart && this.tfSelEnd) {
                var a = this.tfFmtDate(this.tfSelStart), b = this.tfFmtDate(this.tfSelEnd);
                if (this.tfStartOfDay(this.tfSelStart) > this.tfStartOfDay(this.tfSelEnd)) { var t = a; a = b; b = t; }
                var spanDays = Math.round((this.tfParseDate(b) - this.tfParseDate(a)) / 86400000) + 1;
                var txt = this.tfPrettyRange(a, b);
                if (spanDays > this.MAX_RANGE_DAYS) {
                    txt += ' — max ' + this.MAX_RANGE_DAYS + ' days';
                    $range.addClass('is-error');
                    $apply.prop('disabled', true);
                } else {
                    $range.removeClass('is-error');
                    $apply.prop('disabled', false);
                }
                $range.text(txt);
            } else if (this.tfSelStart) {
                $range.removeClass('is-error').text('Select end date…');
                $apply.prop('disabled', true);
            } else {
                $range.removeClass('is-error').text('Select a start date');
                $apply.prop('disabled', true);
            }
        },

        /* ----- Render the two-row table header (group + columns) ----- */

        renderTableHeader: function () {
            var $thead = $('#optee-stats-table thead');
            var isConversions = this.drilldown === 'conversions';

            // Row 1: group headers with colspan.
            var groupRow = '<tr class="optee-stats-group-row">';
            if (!isConversions) {
                // The full-screen toggle rides in this cell: it spans both header
                // rows and bottom-aligns its label, leaving a free strip at the
                // top, and being sticky on both axes it never scrolls away.
                // Select-all checkbox lives inside the sticky first header cell, so the
                // column count (and every group colspan) stays exactly as it was.
                var selAll = (this.drilldown === 'default')
                    ? '<input type="checkbox" id="optee-select-all" title="Select all filtered rows" ' +
                      'style="cursor:pointer;margin-right:7px;vertical-align:middle;">'
                    : '';
                groupRow += '<th class="optee-sticky-col optee-gh-date" rowspan="2" data-sort="zone_id">' +
                    this.fsCornerButton() + selAll +
                    Optee.Util.escapeHtml(this.drilldownLabel()) +
                    ' <span class="optee-sort-arrow">\u21C5</span></th>';
            }

            // Leading group-less columns (e.g. "PopAds Target") span both header rows,
            // like the first column \u2014 otherwise the group bands would misalign.
            var lead = 0;
            if (!isConversions) {
                for (var li = 1; li < this.allColumns.length; li++) {
                    var lc = this.allColumns[li];
                    // Per-value Active toggle is a group-less lead column \u2014 spans both
                    // header rows, no sort, toggle styling.
                    if (lc.type === 'targeting_toggle') {
                        groupRow += '<th class="optee-gh-toggle" rowspan="2">' + Optee.Util.escapeHtml(lc.label) + '</th>';
                        lead++;
                        continue;
                    }
                    if (lc.group || lc.type === 'toggle') break;
                    // Annotation columns (e.g. PopAds Target) hold short labels \u2014 pin them
                    // to content width so they don't absorb the table's slack space.
                    var leadStyle = lc.key === 'ts_target' ? ' style="width:1%;white-space:nowrap;"' : '';
                    groupRow += '<th class="optee-stats-col-header text-left" rowspan="2" data-sort="' + lc.key + '"' + leadStyle + '>' +
                        Optee.Util.escapeHtml(lc.label) +
                        ' <span class="optee-sort-arrow">\u21C5</span></th>';
                    lead++;
                }
            }

            // Count actual columns per group from allColumns (accounts for hidden columns).
            var groupColCounts = {};
            for (var ci = 0; ci < this.allColumns.length; ci++) {
                var gk = this.allColumns[ci].group;
                if (gk) groupColCounts[gk] = (groupColCounts[gk] || 0) + 1;
            }

            for (var i = 0; i < this.activeGroups.length; i++) {
                var key = this.activeGroups[i];
                var g = COL_GROUPS[key];
                var name, headerCls, span;
                if (g) {
                    name = this.moduleNames[key] || g.label;
                    headerCls = g.headerCls;
                    span = groupColCounts[key] || g.columns.length;
                } else if (this.perdayGroupLabels && this.perdayGroupLabels[key]) {
                    // Per-day breakout band (not a COL_GROUPS entry).
                    name = this.perdayGroupLabels[key];
                    headerCls = 'optee-gh-perday';
                    span = groupColCounts[key] || 0;
                } else if (this.externalGroups && this.externalGroups[key]) {
                    // Band contributed by another plugin.
                    name = this.externalGroups[key].label;
                    headerCls = this.externalGroups[key].headerCls;
                    span = groupColCounts[key] || 0;
                } else {
                    continue; // unknown group — skip defensively
                }
                if (!span) continue;
                groupRow += '<th colspan="' + span + '" class="' + headerCls + '">' +
                    Optee.Util.escapeHtml(name) + '</th>';
            }
            groupRow += '</tr>';

            // Row 2: individual column headers (skip the leading group-less columns
            // already emitted with rowspan=2 above).
            var colRow = '<tr class="optee-stats-col-row">';
            var startIdx = isConversions ? 0 : (1 + lead);
            for (var j = startIdx; j < this.allColumns.length; j++) {
                var col = this.allColumns[j];
                if (col.type === 'toggle' || col.type === 'country_toggle') {
                    colRow += '<th class="optee-gh-toggle">' + Optee.Util.escapeHtml(col.label) + '</th>';
                    continue;
                }
                var alignCls = col.align === 'left' ? ' text-left' : '';
                // Tooltip for custom columns: prefer description, fall back to formula.
                var tipAttr = '';
                if (col.isCustom) {
                    var def = this.effectiveDef(col.customId);
                    var tip = (def && def.description) ? def.description : col.formula;
                    if (tip) tipAttr = ' title="' + Optee.Util.escapeHtml(tip) + '"';
                }
                // Funnel: opens the per-column filter popover. Tinted when active.
                var fActive = !!this.colFilters[col.key];
                var funnel = '<span class="optee-col-filter' + (fActive ? ' is-active' : '') + '" data-col="' +
                    Optee.Util.escapeHtml(col.key) + '" title="' +
                    (fActive ? Optee.Util.escapeHtml(this.filterLabel(col.key, this.colFilters[col.key])) : 'Filter this column') +
                    '" style="cursor:pointer;margin-left:4px;opacity:' + (fActive ? '1' : '.35') + ';color:' +
                    (fActive ? '#2aa5ff' : 'inherit') + ';">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="' +
                    (fActive ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
                    '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg></span>';

                // Flex row: label, sort arrow and funnel stay on one line however
                // narrow the column is (nowrap alone still wrapped the icon).
                colRow += '<th class="optee-stats-col-header' + alignCls + '" data-sort="' + col.key + '"' + tipAttr + '>' +
                    '<span style="display:flex;align-items:center;gap:3px;white-space:nowrap;justify-content:' +
                    (col.align === 'left' ? 'flex-start' : 'flex-end') + ';">' +
                    '<span>' + Optee.Util.escapeHtml(col.label) + '</span>' +
                    '<span class="optee-sort-arrow">\u21C5</span>' + funnel + '</span></th>';
            }
            colRow += '</tr>';

            $thead.html(groupRow + colRow);

            // Header just changed — the report label in the full-screen bar with it.
            if (Optee.TableFullscreen) Optee.TableFullscreen.refresh();
        },

        /* ----- Full screen ----- */

        /** Lucide maximize-2 / minimize-2, sized for the corner or the toolbar. */
        fsIcon: function (isOpen, size) {
            var body = isOpen
                ? '<polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/>'
                : '<polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>';
            return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size +
                '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
                'stroke-linecap="round" stroke-linejoin="round">' + body + '</svg>';
        },

        fsIsOpen: function () {
            return !!(Optee.TableFullscreen && Optee.TableFullscreen.isOpen());
        },

        fsCornerButton: function () {
            var open = this.fsIsOpen();
            var label = open ? 'Exit full screen' : 'Expand table full screen';
            return '<button type="button" class="optee-tfs-corner" title="' + label + '" aria-label="' + label + '">' +
                this.fsIcon(open, 13) + '</button>';
        },

        toggleFullscreen: function () {
            if (!Optee.TableFullscreen) return;
            var self = this;

            Optee.TableFullscreen.toggle('#optee-stats-wrapper', {
                title: function () { return $('#optee-campaign-title').text(); },
                // The page chrome is hidden while expanded, so the bar has to carry
                // which report and which date range you are actually looking at.
                subtitle: function () {
                    var parts = [];
                    if (self.drilldown !== 'conversions') parts.push(self.drilldownLabel());
                    var range = $.trim($('#optee-tf-label').text());
                    if (range) parts.push(range);
                    return parts.join(' · ');
                },
                // Trigger the real controls rather than moving them into the bar —
                // their handlers and popup positioning stay untouched.
                actions: [
                    {
                        label: 'Columns',
                        icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
                        onClick: function () { $('#optee-stats-columns').trigger('click'); }
                    },
                    {
                        label: 'Reload',
                        icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>',
                        onClick: function () { $('#optee-stats-reload').trigger('click'); }
                    }
                ],
                onToggle: function (open) { self.onFullscreenToggle(open); }
            });
        },

        onFullscreenToggle: function (open) {
            var label = open ? 'Exit full screen' : 'Expand table full screen';

            $('.optee-tfs-corner').html(this.fsIcon(open, 13)).attr({ title: label, 'aria-label': label });

            // campaign.php caps the wrapper's height with an inline max-height
            // computed from its in-page position; on exit that has to be redone.
            // It listens natively, so jQuery's .trigger() would not reach it.
            if (!open) {
                try { window.dispatchEvent(new Event('resize')); } catch (e) {}
            }
        },

        /* ----- Event bindings ----- */

        bindEvents: function () {
            var self = this;

            // Columns settings button.
            $(document).on('click', '#optee-stats-columns', function (e) {
                e.stopImmediatePropagation();
                self.openColumnsModal();
            });

            // Exit zone drilldown (breadcrumb ✕).
            $(document).on('click', '#optee-zone-drill-clear', function () {
                self.clearZoneFilter();
            });

            // Full screen — sticky corner-cell toggle. That cell IS the sort
            // control, so this is delegated on the table (nearer than document,
            // where sorting is bound) and stops the click there — otherwise every
            // expand would also re-sort the report.
            $('#optee-stats-table').on('click', '.optee-tfs-corner', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self.toggleFullscreen();
            });

            // "Hide blocked" toggle: view-only filter on the default zones report.
            // Hides rows with Active = off (optee-zone-blocked) and the SUM/AVG
            // summary rows (which would be misleading once rows are filtered).
            // Does NOT change zone block state on the traffic source.
            $(document).on('click', '#optee-hide-blocked-toggle', function () {
                var $btn = $(this);
                var $wrapper = $('#optee-stats-wrapper');
                var $lbl = $btn.find('.optee-hide-blocked-label');
                if ($btn.hasClass('is-active')) {
                    $btn.removeClass('is-active');
                    $wrapper.removeClass('optee-hide-blocked');
                    $lbl.text('Hide blocked');
                } else {
                    $btn.addClass('is-active');
                    $wrapper.addClass('optee-hide-blocked');
                    $lbl.text('Show blocked');
                }
            });

            // Right-click on zone cell — show custom context menu (Block/Unblock/Copy).
            $(document).on('contextmenu', '#optee-stats-table tbody td.optee-sticky-col', function (e) {
                // Only on the default drilldown (zone-level report) — Date / Country / etc. shouldn't get block actions.
                if (self.drilldown !== 'default') return;
                var $row = $(this).closest('tr');
                if ($row.hasClass('optee-stats-summary-row')) return; // skip SUM / AVG.
                var $toggle = $row.find('.optee-zone-toggle');
                var zoneId  = $toggle.length ? String($toggle.data('zone-id')) : $(this).text().trim();
                if (!zoneId) return;
                e.preventDefault();
                self.openZoneContextMenu(e.pageX, e.pageY, zoneId, $toggle);
            });

            // Left-click the note dot — open that zone's notes directly.
            $(document).on('click', '.optee-note-dot', function (e) {
                e.stopPropagation();
                var zoneId = String($(this).data('zone-id') || '');
                if (zoneId && window.OpteeNotes && window.OpteeNotes.open) {
                    window.OpteeNotes.open('zone', zoneId);
                }
            });

            // Drill-down tabs are real links (so right-click offers "Open in new
            // tab"), but a plain left-click stays an in-page switch. A modified
            // click — ctrl/cmd for a new tab, shift for a new window — is handed
            // to the browser untouched.
            function isModifiedClick(e) {
                return e.ctrlKey || e.metaKey || e.shiftKey || e.altKey;
            }

            // A focused <a> ignores Space where the old <button> activated on it.
            // Keep the tabs operable from the keyboard either way.
            $(document).on('keydown', 'a.optee-drill-btn[data-drilldown], a.optee-drill-dropdown-item[data-drilldown]', function (e) {
                if (e.which !== 32) return;
                e.preventDefault();
                $(this).trigger('click');
            });

            // Drill-down clicks (regular buttons — not inside a dropdown wrapper).
            $(document).on('click', '.optee-drill-btn:not(.optee-drill-dropdown .optee-drill-btn)', function (e) {
                var dd = $(this).data('drilldown');
                if (!dd) return; // Not a drilldown button (e.g., reload, columns).
                if (isModifiedClick(e)) return;
                e.preventDefault();
                self.drilldown = dd;
                self.resetViewState();
                self.userSorted = false;
                self.renderDrilldowns();
                self.buildActiveGroups();
                self.renderTableHeader();
                self.pushUrl();
                self.loadData();
            });

            // Dropdown chevron: toggle the menu.
            $(document).on('click', '.optee-drill-chevron', function (e) {
                e.stopPropagation();
                // The chevron sits inside the tab's anchor — opening the menu
                // must not navigate.
                e.preventDefault();
                var $dd = $(this).closest('.optee-drill-dropdown');
                var reopening = $('#optee-drill-float').data('dd') === $dd[0];
                self.closeDrillFloat();
                if (reopening) { return; }
                // Render the menu as a body-level clone so NO ancestor (overflow /
                // transform / stacking context on the scrolling bar) can clip it.
                var rect  = $dd[0].getBoundingClientRect();
                var $menu = $dd.find('.optee-drill-dropdown-menu').clone()
                    .attr('id', 'optee-drill-float')
                    .css({ display: 'block', position: 'fixed', left: rect.left + 'px', top: (rect.bottom + 4) + 'px', margin: 0, zIndex: 99999, minWidth: Math.max(160, rect.width) + 'px' })
                    .appendTo('body');
                $menu.data('dd', $dd[0]);
                var mh = $menu.outerHeight();
                if (rect.bottom + mh > window.innerHeight - 8) {
                    $menu.css('top', Math.max(8, rect.top - mh - 4) + 'px');
                }
            });

            // Dropdown button label: load the default drilldown key.
            $(document).on('click', '.optee-drill-dropdown > .optee-drill-btn', function (e) {
                if ($(e.target).closest('.optee-drill-chevron').length) return;
                if (isModifiedClick(e)) return;
                e.preventDefault();
                self.closeDrillFloat();
                self.drilldown = $(this).data('drilldown');
                self.resetViewState();
                self.userSorted = false;
                self.renderDrilldowns();
                self.buildActiveGroups();
                self.renderTableHeader();
                self.pushUrl();
                self.loadData();
            });

            // Dropdown item click.
            $(document).on('click', '.optee-drill-dropdown-item', function (e) {
                e.stopPropagation();
                if (isModifiedClick(e)) return;
                e.preventDefault();
                self.drilldown = $(this).data('drilldown');
                self.resetViewState();
                self.userSorted = false;
                self.closeDrillFloat();
                self.renderDrilldowns();
                self.buildActiveGroups();
                self.renderTableHeader();
                self.pushUrl();
                self.loadData();
            });

            // Prevent dropdown menu clicks from bubbling to document close handler.
            $(document).on('click', '.optee-drill-dropdown-menu', function (e) {
                e.stopPropagation();
            });

            // Close dropdown menus on outside click.
            $(document).on('click', function () {
                self.closeDrillFloat();
            });

            // Timeframe picker: toggle the dropdown.
            $(document).on('click', '#optee-tf-btn', function (e) {
                e.stopPropagation();
                if ($('#optee-tf').hasClass('is-open')) {
                    self.closeTfMenu();
                } else {
                    self.openTfMenu();
                }
            });
            // Keep clicks inside the menu from closing it.
            $(document).on('click', '#optee-tf-menu', function (e) {
                e.stopPropagation();
            });
            // Close on outside click.
            $(document).on('click', function () {
                self.closeTfMenu();
            });

            // Preset click → apply immediately.
            $(document).on('click', '.optee-tf-preset', function () {
                var tf = $(this).data('tf');
                if (tf === 'custom') {
                    // Just switch the preset highlight; user picks days then hits Apply.
                    $('.optee-tf-preset').removeClass('is-active');
                    $(this).addClass('is-active');
                    return;
                }
                self.timeframe = tf;
                self.dateFrom = '';
                self.dateTo = '';
                self.renderTimeframe();
                self.closeTfMenu();
                self.pushUrl();
                self.loadData();
            });

            // Month navigation.
            $(document).on('click', '.optee-tf-nav', function () {
                var dir = parseInt($(this).data('nav'), 10);
                self.tfViewMonth = new Date(self.tfViewMonth.getFullYear(), self.tfViewMonth.getMonth() + dir, 1);
                self.renderCalendars();
            });

            // Day click → build the custom selection.
            $(document).on('click', '.optee-tf-day[data-day]', function () {
                var d = self.tfParseDate($(this).data('day'));
                if (!self.tfSelStart || (self.tfSelStart && self.tfSelEnd)) {
                    // Start a fresh selection.
                    self.tfSelStart = d;
                    self.tfSelEnd = null;
                } else {
                    // Complete the selection.
                    self.tfSelEnd = d;
                }
                $('.optee-tf-preset').removeClass('is-active');
                $('.optee-tf-preset-custom').addClass('is-active');
                self.renderCalendars();
                self.updateTfFoot();
            });

            // Cancel custom selection.
            $(document).on('click', '#optee-tf-cancel', function () {
                self.closeTfMenu();
            });

            // Apply custom range.
            $(document).on('click', '#optee-tf-apply', function () {
                if (!self.tfSelStart || !self.tfSelEnd) return;
                var a = self.tfSelStart, b = self.tfSelEnd;
                if (self.tfStartOfDay(a) > self.tfStartOfDay(b)) { var t = a; a = b; b = t; }
                var spanDays = Math.round((self.tfStartOfDay(b) - self.tfStartOfDay(a)) / 86400000) + 1;
                if (spanDays > self.MAX_RANGE_DAYS) {
                    if (Optee.Toast) Optee.Toast.show('Date range is limited to ' + self.MAX_RANGE_DAYS + ' days.', 'error');
                    return;
                }
                self.timeframe = 'custom';
                self.dateFrom = self.tfFmtDate(a);
                self.dateTo = self.tfFmtDate(b);
                self.renderTimeframe();
                self.closeTfMenu();
                self.pushUrl();
                self.loadData();
            });

            // Reload (normal load, uses cache if available).
            $(document).on('click', '#optee-stats-reload', function () {
                // A manual reload restarts the countdown — otherwise an auto-tick
                // could fire seconds after the user just refreshed by hand.
                if (self.autoReloadMs) {
                    self.stopAutoReload();
                    self.startAutoReload();
                }
                self.loadData();
            });

            // Auto-reload interval. Rebuilt with the popover on every load, so it
            // has to be delegated rather than bound to the element.
            $(document).on('change', '#optee-auto-reload', function (e) {
                e.stopPropagation();
                self.setAutoReload($(this).val());
            });

            // Keep the popover open while choosing an interval.
            $(document).on('click', '#optee-auto-reload', function (e) { e.stopPropagation(); });

            // Repaint on tab focus so a paused countdown does not look stuck, and
            // fire immediately if the interval elapsed while hidden.
            $(document).on('visibilitychange', function () {
                if (!self.autoReloadMs) { return; }
                if (!document.hidden && self.autoReloadNextAt && Date.now() >= self.autoReloadNextAt) {
                    self.autoReloadTick();
                }
                self.paintAutoReload();
            });

            // Cache indicator popover toggle.
            $(document).on('click', '#optee-cache-indicator .optee-cache-badge', function (e) {
                e.stopPropagation();
                $('.optee-suggestion-popover').hide();

                var $pop = $('#optee-cache-popover');
                $pop.toggle();
            });
            // Close all popovers on outside click.
            $(document).on('click', function () {
                $('#optee-cache-popover').hide();
                $('.optee-suggestion-popover').hide();

            });

            // Prevent popover close when clicking inside it.
            $(document).on('click', '#optee-cache-popover', function (e) {
                e.stopPropagation();
            });

            // Clear cache & reload.
            $(document).on('click', '#optee-clear-cache', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $('#optee-cache-popover').hide();
                self.loadData(true);
            });

            // Suggestion / info badge popover toggle.
            $(document).on('click', '.optee-suggestion-badge', function (e) {
                e.stopPropagation();
                $('#optee-cache-popover').hide();
                var $myPop = $(this).siblings('.optee-suggestion-popover');
                $('.optee-suggestion-popover').not($myPop).hide();
                $myPop.toggle();
            });

            // Prevent popover close when clicking inside it.
            $(document).on('click', '.optee-suggestion-popover', function (e) {
                e.stopPropagation();
            });

            /* ---- column filters ---- */
            $(document).on('click', '.optee-col-filter', function (e) {
                e.stopPropagation();   // don't trigger the column sort
                var r = this.getBoundingClientRect();
                self.openFilterPopover($(this).data('col'), r.left, r.bottom + 6);
            });
            $(document).on('click', '#optee-filter-badge-btn', function () { self.openFilterList(); });
            $(document).on('click', '.optee-chip-x', function () {
                $('#optee-filter-list').remove();
                self.setColumnFilter($(this).data('col'), null);
            });
            $(document).on('click', '#optee-clear-filters', function () {
                self.clearColumnFilters();
            });

            /* ---- row selection + bulk actions ---- */
            $(document).on('click', '.optee-row-select', function (e) {
                e.stopPropagation();
                var val = String($(this).data('value'));
                var on = $(this).is(':checked');

                // Shift-click selects the range from the last clicked row.
                if (e.shiftKey && self.lastClickedRow) {
                    var $boxes = $('#optee-stats-table .optee-row-select');
                    var from = $boxes.index($boxes.filter('[data-value="' + self.lastClickedRow + '"]'));
                    var to = $boxes.index(this);
                    if (from > -1 && to > -1) {
                        var lo = Math.min(from, to), hi = Math.max(from, to);
                        $boxes.slice(lo, hi + 1).each(function () {
                            $(this).prop('checked', on);
                            self.selected[String($(this).data('value'))] = on || undefined;
                            if (!on) delete self.selected[String($(this).data('value'))];
                        });
                    }
                } else {
                    if (on) { self.selected[val] = true; } else { delete self.selected[val]; }
                }
                self.lastClickedRow = val;
                self.renderSelectionBar();
            });

            // The header cell sorts on click; the checkbox inside it must not.
            $(document).on('click', '#optee-select-all', function (e) { e.stopPropagation(); });
            $(document).on('change', '#optee-select-all', function () {
                var on = $(this).is(':checked');
                // "All" means every row currently passing the filters, not just what's
                // scrolled into view.
                $('#optee-stats-table .optee-row-select').each(function () {
                    $(this).prop('checked', on);
                    var v = String($(this).data('value'));
                    if (on) { self.selected[v] = true; } else { delete self.selected[v]; }
                });
                self.renderSelectionBar();
            });

            $(document).on('click', '#optee-sel-clear', function () { self.clearSelection(); });
            $(document).on('click', '#optee-sel-copy', function () { self.copySelection('\n'); });
            $(document).on('click', '#optee-sel-copy-comma', function () { self.copySelection(','); });
            $(document).on('click', '#optee-sel-block', function () { self.bulkZoneAction(true); });
            $(document).on('click', '#optee-sel-unblock', function () { self.bulkZoneAction(false); });

            // Hide no-clicks checkbox toggle.
            $(document).on('change', '#optee-hide-no-clicks', function () {
                self.hideNoClicks = $(this).is(':checked');
                self.pushUrl(true);
                self.sortAndRender();
            });

            // "Hide empty rows" checkbox in the timeframe picker footer. View-only
            // filter that drops dead days (no activity in any module); persisted in
            // the URL so it carries across reports.
            $(document).on('change', '#optee-tf-hide-empty', function () {
                self.hideEmpty = $(this).is(':checked');
                self.pushUrl(true);
                self.sortAndRender();
            });

            // Always load live data checkbox toggle.
            $(document).on('change', '#optee-always-live', function () {
                self.alwaysLive = $(this).is(':checked');
                self.pushUrl(true);
                if (self.alwaysLive) self.loadData(true);
            });

            // Grouped campaign: member dropdown scopes the report to All / a single creative.
            $(document).on('change', '#optee-ts-member-select', function () {
                self.tsMember = $(this).val() || '';
                self.applyZoneLock();
                self.loadData();
            });

            // Reflect the initial lock state (All campaigns = zone actions locked).
            self.applyZoneLock();

            // Zone toggle (block/unblock).
            $(document).on('click', '.optee-zone-toggle', function () {
                var $btn = $(this);
                if ($btn.hasClass('is-loading')) return;

                // Grouped campaign in "All" view — blocking must target one creative.
                if (self.tsGrouped && !self.tsMember) {
                    Optee.Toast.show('Select a single campaign first to block zones.', 'error');
                    return;
                }

                var zoneId = $btn.data('zone-id');
                var isOn = $btn.hasClass('is-on');
                var action = isOn ? 'block' : 'unblock';

                // Prevent removing the last whitelisted zone.
                if (self.isWhitelistCampaign && action === 'block' && self.whitelistedZones.length <= 1) {
                    Optee.Toast.show('Cannot remove the last whitelisted zone. This would convert the campaign to RON (worldwide traffic).', 'error');
                    return;
                }

                // BidVertiser: warn about targeting rate limit before manual zone toggle.
                if (self.modules.traffic_source === 'bidvertiser') {
                    var actionLabel = action === 'block' ? 'Block' : 'Unblock';
                    var actionLower = action === 'block' ? 'block' : 'unblock';
                    var warnIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';

                    $('#optee-bd-zone-modal').remove();
                    var bdModal = '<div id="optee-bd-zone-modal" style="position:fixed;inset:0;z-index:9999;">' +
                        '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);" id="optee-bd-zone-backdrop"></div>' +
                        '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:380px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                        '<button id="optee-bd-zone-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                        '<div style="font-weight:600;font-size:14px;margin-bottom:8px;">' + actionLabel + ' zone on BidVertiser?</div>' +
                        '<div style="color:#6b7280;font-size:13px;line-height:1.6;margin-bottom:16px;">' +
                        'BidVertiser limits targeting changes to <b>1 per hour</b> per campaign. This will ' + actionLower + ' zone <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;font-size:12px;">' + Optee.Util.escapeHtml(String(zoneId)) + '</code> and use your hourly quota.' +
                        '<div style="margin-top:10px;padding:8px 10px;background:#f0f9ff;border-radius:6px;font-size:12px;color:#1e40af;">' + warnIcon + ' <b>Tip:</b> For bulk zone management, use automated rules — they batch all changes into a single API call.</div>' +
                        '</div>' +
                        '<div style="display:flex;gap:8px;">' +
                        '<button id="optee-bd-zone-confirm" class="optee-btn-primary optee-btn-sm" style="flex:1;">' + actionLabel + ' Zone</button>' +
                        '<button id="optee-bd-zone-cancel" class="optee-btn-secondary optee-btn-sm" style="flex:1;">Cancel</button>' +
                        '</div></div></div>';

                    $('body').append(bdModal);

                    var closeBdModal = function () { $('#optee-bd-zone-modal').remove(); };
                    $(document).on('click', '#optee-bd-zone-close, #optee-bd-zone-cancel, #optee-bd-zone-backdrop', closeBdModal);
                    $(document).on('click', '#optee-bd-zone-confirm', function () {
                        closeBdModal();
                        doZoneToggle($btn, zoneId, action);
                    });
                    return;
                }

                doZoneToggle($btn, zoneId, action);
            });

            function doZoneToggle($btn, zoneId, action) {
                $btn.addClass('is-loading');
                var $thumb = $btn.find('.optee-zone-toggle-thumb');
                $thumb.hide();
                $btn.append('<span class="optee-zone-spinner"></span>');

                var postData = {
                    campaign_id: self.campaignId,
                    zone_id: zoneId,
                    zone_action: action
                };
                if (self.isWhitelistCampaign) postData.is_whitelist = '1';
                if (self.tsMember) postData.ts_campaign_member = self.tsMember;

                Optee.Ajax.post('optee_toggle_zone', postData).done(function (res) {
                    if (res.success) {
                        $btn.toggleClass('is-on');
                        var $row = $btn.closest('tr');
                        var zStr = String(zoneId);
                        if (self.isWhitelistCampaign) {
                            // Whitelist: block = remove from WL, unblock = add to WL.
                            if (action === 'block') {
                                $row.addClass('optee-zone-blocked');
                                var wIdx = self.whitelistedZones.indexOf(zStr);
                                if (wIdx !== -1) self.whitelistedZones.splice(wIdx, 1);
                            } else {
                                $row.removeClass('optee-zone-blocked');
                                if (self.whitelistedZones.indexOf(zStr) === -1) self.whitelistedZones.push(zStr);
                            }
                        } else {
                            // Blacklist: block = add to BL, unblock = remove from BL.
                            if (action === 'block') {
                                $row.addClass('optee-zone-blocked');
                                if (self.blockedZones.indexOf(zStr) === -1) self.blockedZones.push(zStr);
                            } else {
                                $row.removeClass('optee-zone-blocked');
                                var idx = self.blockedZones.indexOf(zStr);
                                if (idx !== -1) self.blockedZones.splice(idx, 1);
                            }
                        }
                        // Blocking/unblocking may change whether any blocked
                        // rows remain — refresh the "Hide blocked" toggle.
                        self.updateHideBlockedVisibility();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to toggle zone.', 'error');
                    }
                }).fail(function () {
                    Optee.Toast.show('Request failed.', 'error');
                }).always(function () {
                    $btn.removeClass('is-loading').find('.optee-zone-spinner').remove();
                    $thumb.show();
                });
            }

            // Country toggle (enable/disable a country's targeting) — geo report.
            $(document).on('click', '.optee-country-toggle', function () {
                var $btn = $(this);
                if ($btn.hasClass('is-loading')) return;

                var code = String($btn.data('country-code') || '');
                if (!code) return;
                var isOn = $btn.hasClass('is-on');
                var action = isOn ? 'remove' : 'add';

                // Prevent removing the last explicit country (would flip to RON). Only
                // length === 1: length 0 already targets all (RON) — let the server
                // return its accurate message instead of a misleading "last country" one.
                if (action === 'remove' && Array.isArray(self.targetedCountries) && self.targetedCountries.length === 1) {
                    Optee.Toast.show('Cannot disable the last targeted country. This would convert the campaign to worldwide (RON) traffic.', 'error');
                    return;
                }

                $btn.addClass('is-loading');
                var $thumb = $btn.find('.optee-zone-toggle-thumb');
                $thumb.hide();
                $btn.append('<span class="optee-zone-spinner"></span>');

                Optee.Ajax.post('optee_toggle_country', {
                    campaign_id: self.campaignId,
                    country_code: code,
                    country_action: action
                }).done(function (res) {
                    if (res.success) {
                        // Trust the server's fresh targeted list to resync state.
                        var tc = res.data.targeted_countries;
                        if (Array.isArray(tc)) self.targetedCountries = tc;
                        var $row = $btn.closest('tr');
                        if (action === 'remove') {
                            $btn.removeClass('is-on');
                            $row.addClass('optee-zone-blocked');
                        } else {
                            $btn.addClass('is-on');
                            $row.removeClass('optee-zone-blocked');
                        }
                        self.updateHideBlockedVisibility();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to update country.', 'error');
                    }
                }).fail(function () {
                    Optee.Toast.show('Request failed.', 'error');
                }).always(function () {
                    $btn.removeClass('is-loading').find('.optee-zone-spinner').remove();
                    $thumb.show();
                });
            });

            // Targeting toggle (enable/disable a value's targeting) — OS/browser reports.
            $(document).on('click', '.optee-targeting-toggle', function () {
                var $btn = $(this);
                if ($btn.hasClass('is-loading')) return;
                if (!self.targetingDim) return;

                var value = String($btn.data('value') || '');
                if (!value) return;
                var isOn = $btn.hasClass('is-on');
                var action = isOn ? 'remove' : 'add';

                // Prevent removing the last EXPLICIT value (would flip the dimension to
                // "targets all"). Only guard length === 1: length 0 means it already
                // targets all, so let the server return the accurate "set explicit
                // targeting first" message rather than a misleading "last value" one.
                if (action === 'remove' && Array.isArray(self.targetedTargeting) && self.targetedTargeting.length === 1) {
                    Optee.Toast.show('Cannot disable the last targeted value — that would re-enable all of them.', 'error');
                    return;
                }

                $btn.addClass('is-loading');
                var $thumb = $btn.find('.optee-zone-toggle-thumb');
                $thumb.hide();
                $btn.append('<span class="optee-zone-spinner"></span>');

                Optee.Ajax.post('optee_toggle_targeting', {
                    campaign_id: self.campaignId,
                    dimension: self.targetingDim,
                    value: value,
                    targeting_action: action
                }).done(function (res) {
                    if (res.success) {
                        var tt = res.data.targeted_targeting;
                        if (Array.isArray(tt)) self.targetedTargeting = tt;
                        // One PopAds ID can back several report rows (e.g. Chrome / Chrome
                        // Mobile / Chrome for iOS all map to "Google Chrome"). Update every
                        // toggle + row that shares this value so they stay in sync.
                        var removed = (action === 'remove');
                        $('.optee-targeting-toggle[data-value="' + value.replace(/"/g, '\\"') + '"]').each(function () {
                            var $t = $(this);
                            if (removed) { $t.removeClass('is-on'); $t.closest('tr').addClass('optee-zone-blocked'); }
                            else { $t.addClass('is-on'); $t.closest('tr').removeClass('optee-zone-blocked'); }
                        });
                        self.updateHideBlockedVisibility();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to update targeting.', 'error');
                    }
                }).fail(function () {
                    Optee.Toast.show('Request failed.', 'error');
                }).always(function () {
                    $btn.removeClass('is-loading').find('.optee-zone-spinner').remove();
                    $thumb.show();
                });
            });

            // BidVertiser: deferred zone status loading.
            $(document).on('click', '.optee-bd-load-zones', function (e) {
                e.stopPropagation();

                var lockIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

                $('#optee-bd-zones-modal').remove();
                var modal = '<div id="optee-bd-zones-modal" style="position:fixed;inset:0;z-index:9999;">' +
                    '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);" id="optee-bd-zones-backdrop"></div>' +
                    '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:380px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                    '<button id="optee-bd-zones-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                    '<div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:14px;margin-bottom:8px;">' + lockIcon + ' Load zone status?</div>' +
                    '<div style="color:#6b7280;font-size:13px;line-height:1.6;margin-bottom:16px;">' +
                    'Loading blocked/active zone status requires a <b>targeting API call</b>, which is limited to <b>1 per hour</b> per campaign on BidVertiser.' +
                    '<div style="margin-top:10px;padding:8px 10px;background:#f0f9ff;border-radius:6px;font-size:12px;color:#1e40af;">This is separate from the report API limit. Once loaded, you can toggle individual zones.</div>' +
                    '</div>' +
                    '<div style="display:flex;gap:8px;">' +
                    '<button id="optee-bd-zones-confirm" class="optee-btn-primary optee-btn-sm" style="flex:1;">' + lockIcon + ' Load Zone Status</button>' +
                    '<button id="optee-bd-zones-cancel" class="optee-btn-secondary optee-btn-sm" style="flex:1;">Cancel</button>' +
                    '</div></div></div>';

                $('body').append(modal);

                var closeModal = function () { $('#optee-bd-zones-modal').remove(); };
                $(document).on('click', '#optee-bd-zones-close, #optee-bd-zones-cancel, #optee-bd-zones-backdrop', closeModal);
                $(document).on('click', '#optee-bd-zones-confirm', function () {
                    var $confirmBtn = $(this).prop('disabled', true).text('Loading...');

                    Optee.Ajax.post('optee_load_zone_status', { campaign_id: self.campaignId }).done(function (res) {
                        closeModal();
                        if (res.success) {
                            var bz = res.data.blocked_zones;
                            self.blockedZones = Array.isArray(bz) ? bz : (bz ? Object.values(bz) : []);
                            var wz = res.data.whitelisted_zones;
                            self.whitelistedZones = Array.isArray(wz) ? wz : (wz ? Object.values(wz) : []);
                            self.isWhitelistCampaign = self.whitelistedZones.length > 0;
                            self.bdZonesLoaded = true;
                            self.renderRows(self.data);
                            self.updateWhitelistBadge();
                            Optee.Toast.show('Zone status loaded.', 'success');
                        } else {
                            Optee.Toast.show(res.data.message || 'Failed to load zone status.', 'error');
                        }
                    }).fail(function () {
                        closeModal();
                        Optee.Toast.show('Failed to load zone status.', 'error');
                    });
                });
            });

            // Column sort (both regular headers and sticky date header).
            $(document).on('click', '#optee-stats-table .optee-stats-col-header, #optee-stats-table .optee-gh-date', function () {
                var key = $(this).data('sort');
                self.userSorted = true;
                if (self.sortKey === key) {
                    self.sortDir = self.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    self.sortKey = key;
                    self.sortDir = 'desc';
                }
                self.sortAndRender();
            });

            // Campaign status badge — open modal.
            $(document).on('click', '.optee-campaign-status-badge', function (e) {
                e.stopPropagation();
                $('#optee-campaign-status-modal').fadeIn(150);
            });

            // Close modal on backdrop, cancel, or X click.
            $(document).on('click', '#optee-campaign-status-backdrop, #optee-campaign-status-cancel, #optee-campaign-status-close', function () {
                $('#optee-campaign-status-modal').fadeOut(150);
            });

            // Campaign actions modal — open.
            $(document).on('click', '#optee-campaign-actions-btn, #optee-campaign-actions-btn-mob', function (e) {
                e.stopPropagation();
                var localStatus = $('#optee-campaign-stats').data('local-status') || 'active';
                var localPaused = localStatus === 'paused';
                $('.optee-action-tile').each(function () {
                    var l = $(this).data('action-label');
                    if (l === 'Pause local campaign') $(this).toggle(!localPaused);
                    if (l === 'Resume local campaign') $(this).toggle(localPaused);
                });
                $('#optee-actions-modal').fadeIn(150);
                $('#optee-actions-search').val('').trigger('keyup').focus();
            });

            // Campaign actions modal — close on backdrop or X.
            $(document).on('click', '#optee-actions-backdrop, #optee-actions-close', function () {
                $('#optee-actions-modal').fadeOut(150);
                $('#optee-actions-search').val('');
                $('.optee-action-tile').show();
            });

            // Campaign actions modal — search filter.
            $(document).on('keyup', '#optee-actions-search', function () {
                var query = $(this).val().toLowerCase();
                $('.optee-action-tile').each(function () {
                    var label = $(this).data('action-label').toLowerCase();
                    $(this).toggle(label.indexOf(query) !== -1);
                });
            });

            // Campaign actions modal — tile click.
            $(document).on('click', '.optee-action-tile', function () {
                var label = $(this).data('action-label');
                $('#optee-actions-modal').fadeOut(150);
                $('#optee-actions-search').val('');
                $('.optee-action-tile').show();

                if (label === 'Edit on traffic source') {
                    var editUrl = $('#optee-campaign-stats').data('ts-edit-url') || '';
                    if (editUrl) {
                        window.open(editUrl, '_blank');
                    } else {
                        Optee.Toast.show('Edit URL not available for this traffic source', 'info');
                    }
                    return;
                }

                if (label === 'View statistics on traffic source') {
                    var statsUrl = $('#optee-campaign-stats').data('ts-stats-url') || '';
                    if (statsUrl) {
                        window.open(statsUrl, '_blank');
                    } else {
                        Optee.Toast.show('Stats URL not available for this traffic source', 'info');
                    }
                    return;
                }

                if (label === 'Edit local campaign') {
                    var editUrl = $('#optee-campaign-stats').data('edit-url') || '';
                    if (editUrl) {
                        window.open(editUrl, '_blank');
                    }
                    return;
                }

                if (label === 'Update campaign title') {
                    self.renameCampaign();
                    return;
                }

                if (label === 'Copy tracker campaign ID') {
                    var trackerId = $('#optee-campaign-stats').data('tracker-campaign-id') || '';
                    if (trackerId) {
                        navigator.clipboard.writeText(trackerId + '').then(function () {
                            Optee.Toast.show('Copied: ' + trackerId, 'success');
                        });
                    } else {
                        Optee.Toast.show('No tracker campaign linked', 'info');
                    }
                    return;
                }

                if (label === 'Add Rule') {
                    var campId = self.campaignId;
                    window.open(opteeData.dashboardUrl + '?module=rules&action=create&campaign_id=' + campId, '_blank');
                    return;
                }

                if (label === 'View Rules') {
                    window.open(opteeData.dashboardUrl + '?module=rules&campaign_id=' + self.campaignId, '_blank');
                    return;
                }

                if (label === 'Notes') {
                    if (typeof window.opteeOpenNotesModal === 'function') {
                        window.opteeOpenNotesModal();
                    }
                    return;
                }

                if (label === 'Token Mapping') {
                    self.openTokenMappingModal();
                    return;
                }

                if (label === 'Column settings') {
                    self.openColumnsModal();
                    return;
                }

                if (label === 'Export CSV') {
                    self.exportCSV();
                    return;
                }

                if (label === 'Print / Save as PDF') {
                    self.printTable();
                    return;
                }

                if (label === 'Campaign Report') {
                    var rtf = self.timeframe;
                    // The report expects a day-count token; convert a custom range.
                    if (rtf === 'custom' && self.dateFrom && self.dateTo) {
                        var rd = Math.round((new Date(self.dateTo) - new Date(self.dateFrom)) / 86400000) + 1;
                        rtf = Math.max(1, Math.min(90, rd)) + 'd';
                    }
                    window.open(
                        opteeData.dashboardUrl + '?module=dashboard&action=campaign-report&id=' + self.campaignId + '&timeframe=' + encodeURIComponent(rtf),
                        '_blank'
                    );
                    return;
                }

                if (label === 'Stop traffic') {
                    if (!self.campaignStatus || self.campaignStatus !== 'active') {
                        Optee.Toast.show('Campaign is not active', 'info');
                        return;
                    }
                    $('#optee-campaign-status-modal').fadeIn(150);
                    return;
                }

                if (label === 'Resume traffic') {
                    if (self.campaignStatus === 'active') {
                        Optee.Toast.show('Campaign is already active', 'info');
                        return;
                    }
                    if (!self.campaignStatus || self.campaignStatus === 'unknown') {
                        Optee.Toast.show('Campaign status unknown', 'info');
                        return;
                    }
                    $('#optee-campaign-status-modal').fadeIn(150);
                    return;
                }

                if (label === 'Pause local campaign' || label === 'Resume local campaign') {
                    self.toggleLocalStatus();
                    return;
                }

                if (label === 'Remove from dashboard') {
                    self.removeFromDashboard();
                    return;
                }

                if (label === 'Set Monetag Source') {
                    self.openMonetagModal();
                    return;
                }

                if (label === 'Set ProPush Source') {
                    self.openPropushModal();
                    return;
                }

                if (label === 'Set Partners.House Source') {
                    self.openPartnersHouseModal();
                    return;
                }

                if (label === 'Set RollerAds Publisher Source') {
                    self.openRapModal();
                    return;
                }

                if (label === 'Set HilltopAds Publisher Source') {
                    self.openHtaModal();
                    return;
                }

                if (label === 'Set Monetizer Source') {
                    self.openMonetizerMonModal();
                    return;
                }

                if (label === 'Set Zeydoo Source') {
                    self.openZeydooMonModal();
                    return;
                }

                Optee.Toast.show('Action: ' + label + ' \u2014 coming soon', 'info');
            });

            // Monetag modal — close.
            $(document).on('click', '#optee-monetag-backdrop, #optee-monetag-close, #optee-monetag-cancel', function () {
                $('#optee-monetag-modal').fadeOut(150);
            });

            // Monetag modal — save.
            $(document).on('click', '#optee-monetag-save', function () {
                var $btn = $(this);
                var zoneId   = $('#optee-monetag-zone').val();
                var zoneName = $('#optee-monetag-zone option:selected').text().trim();
                var country  = $('#optee-monetag-country').val();

                if (!zoneId) {
                    Optee.Toast.show('Please select a zone.', 'error');
                    return;
                }

                $btn.prop('disabled', true).text('Saving...');

                Optee.Ajax.post('optee_set_monetag_source', {
                    campaign_id: self.campaignId,
                    monetag_zone_id: zoneId,
                    monetag_zone_name: zoneName,
                    monetag_country: country
                }).done(function (res) {
                    $('#optee-monetag-modal').fadeOut(150);
                    if (res.success) {
                        self.monetagZoneId   = res.data.zone_id;
                        self.monetagZoneName = res.data.zone_name;
                        self.monetagCountry  = res.data.country;
                        self.modules.monetag_mon = 'monetag';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-monetag-zone-id', res.data.zone_id).data('monetag-zone-id', res.data.zone_id);
                        $el.attr('data-monetag-zone-name', res.data.zone_name).data('monetag-zone-name', res.data.zone_name);
                        $el.attr('data-monetag-country', res.data.country).data('monetag-country', res.data.country);
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
                    }
                    $btn.prop('disabled', false).text('Save');
                }).fail(function () {
                    $('#optee-monetag-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Save');
                });
            });

            // Monetag modal — clear.
            $(document).on('click', '#optee-monetag-clear', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing...');

                Optee.Ajax.post('optee_set_monetag_source', {
                    campaign_id: self.campaignId,
                    monetag_zone_id: '',
                    monetag_zone_name: '',
                    monetag_country: ''
                }).done(function (res) {
                    $('#optee-monetag-modal').fadeOut(150);
                    if (res.success) {
                        self.monetagZoneId   = '';
                        self.monetagZoneName = '';
                        self.monetagCountry  = '';
                        self.modules.monetag_mon = '';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-monetag-zone-id', '').data('monetag-zone-id', '');
                        $el.attr('data-monetag-zone-name', '').data('monetag-zone-name', '');
                        $el.attr('data-monetag-country', '').data('monetag-country', '');
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to clear.', 'error');
                    }
                    $btn.prop('disabled', false).text('Clear');
                }).fail(function () {
                    $('#optee-monetag-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Clear');
                });
            });

            // ProPush modal — close.
            $(document).on('click', '#optee-propush-backdrop, #optee-propush-close, #optee-propush-cancel', function () {
                $('#optee-propush-modal').fadeOut(150);
            });

            // ProPush modal — live search filtering over the checkbox lists.
            $(document).on('input', '#optee-propush-zone-search, #optee-propush-site-search', function () {
                var q = $(this).val().trim().toLowerCase();
                var listSel = this.id === 'optee-propush-zone-search' ? '#optee-propush-zone-list' : '#optee-propush-site-list';
                $(listSel).find('.optee-pp-check').each(function () {
                    var hay = $(this).attr('data-search') || '';
                    $(this).toggle(q === '' || hay.indexOf(q) !== -1);
                });
            });

            // ProPush modal — save.
            $(document).on('click', '#optee-propush-save', function () {
                var $btn = $(this);
                // Collect checked zone/site ids (multi-select) into comma-separated lists.
                var zoneIds = [], zoneNames = [], siteIds = [], siteNames = [];
                $('#optee-propush-zone-list .optee-pp-check input:checked').each(function () {
                    zoneIds.push($(this).val());
                    zoneNames.push($(this).closest('.optee-pp-check').find('span').text().trim());
                });
                $('#optee-propush-site-list .optee-pp-check input:checked').each(function () {
                    siteIds.push($(this).val());
                    siteNames.push($(this).closest('.optee-pp-check').find('span').text().trim());
                });
                var zoneId   = zoneIds.join(',');
                var zoneName = zoneNames.join(', ');
                var siteId   = siteIds.join(',');
                var siteName = siteNames.join(', ');
                var sourceId = $('#optee-propush-source-id').val().trim();
                var country  = $('#optee-propush-country').val();

                if (!zoneId && !siteId && !sourceId) {
                    Optee.Toast.show('Select at least one zone or site, or enter a Source ID.', 'error');
                    return;
                }

                $btn.prop('disabled', true).text('Saving...');

                Optee.Ajax.post('optee_set_propush_source', {
                    campaign_id: self.campaignId,
                    propush_zone_id: zoneId,
                    propush_zone_name: zoneName,
                    propush_site_id: siteId,
                    propush_site_name: siteId ? siteName : '',
                    propush_source_id: sourceId,
                    propush_country: country
                }).done(function (res) {
                    $('#optee-propush-modal').fadeOut(150);
                    if (res.success) {
                        self.propushZoneId   = res.data.zone_id;
                        self.propushZoneName = res.data.zone_name;
                        self.propushSiteId   = res.data.site_id;
                        self.propushSiteName = res.data.site_name;
                        self.propushSourceId = res.data.source_id;
                        self.propushCountry  = res.data.country;
                        self.modules.propush_mon = 'propush';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-propush-zone-id', res.data.zone_id).data('propush-zone-id', res.data.zone_id);
                        $el.attr('data-propush-zone-name', res.data.zone_name).data('propush-zone-name', res.data.zone_name);
                        $el.attr('data-propush-site-id', res.data.site_id).data('propush-site-id', res.data.site_id);
                        $el.attr('data-propush-site-name', res.data.site_name).data('propush-site-name', res.data.site_name);
                        $el.attr('data-propush-source-id', res.data.source_id).data('propush-source-id', res.data.source_id);
                        $el.attr('data-propush-country', res.data.country).data('propush-country', res.data.country);
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
                    }
                    $btn.prop('disabled', false).text('Save');
                }).fail(function () {
                    $('#optee-propush-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Save');
                });
            });

            // ProPush modal — clear.
            $(document).on('click', '#optee-propush-clear', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing...');

                Optee.Ajax.post('optee_set_propush_source', {
                    campaign_id: self.campaignId,
                    propush_zone_id: '',
                    propush_zone_name: '',
                    propush_site_id: '',
                    propush_site_name: '',
                    propush_source_id: '',
                    propush_country: ''
                }).done(function (res) {
                    $('#optee-propush-modal').fadeOut(150);
                    if (res.success) {
                        self.propushZoneId   = '';
                        self.propushZoneName = '';
                        self.propushSiteId   = '';
                        self.propushSiteName = '';
                        self.propushSourceId = '';
                        self.propushCountry  = '';
                        self.modules.propush_mon = '';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-propush-zone-id', '').data('propush-zone-id', '');
                        $el.attr('data-propush-zone-name', '').data('propush-zone-name', '');
                        $el.attr('data-propush-site-id', '').data('propush-site-id', '');
                        $el.attr('data-propush-site-name', '').data('propush-site-name', '');
                        $el.attr('data-propush-source-id', '').data('propush-source-id', '');
                        $el.attr('data-propush-country', '').data('propush-country', '');
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to clear.', 'error');
                    }
                    $btn.prop('disabled', false).text('Clear');
                }).fail(function () {
                    $('#optee-propush-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Clear');
                });
            });

            // Partners.House modal — close.
            $(document).on('click', '#optee-ph-backdrop, #optee-ph-close, #optee-ph-cancel', function () {
                $('#optee-ph-modal').fadeOut(150);
            });

            // Partners.House modal — save.
            $(document).on('click', '#optee-ph-save', function () {
                var $btn = $(this);
                var sub1    = $('#optee-ph-sub1').val().trim();
                var sub2    = $('#optee-ph-sub2').val().trim();
                var sub3    = $('#optee-ph-sub3').val().trim();
                var sub4    = $('#optee-ph-sub4').val().trim();
                var country = $('#optee-ph-country').val();

                $btn.prop('disabled', true).text('Saving...');

                Optee.Ajax.post('optee_set_partners_house_source', {
                    campaign_id: self.campaignId,
                    ph_active: '1',
                    ph_sub1: sub1,
                    ph_sub2: sub2,
                    ph_sub3: sub3,
                    ph_sub4: sub4,
                    ph_country: country
                }).done(function (res) {
                    $('#optee-ph-modal').fadeOut(150);
                    if (res.success) {
                        self.phActive  = '1';
                        self.phSub1    = res.data.sub1;
                        self.phSub2    = res.data.sub2;
                        self.phSub3    = res.data.sub3;
                        self.phSub4    = res.data.sub4;
                        self.phCountry = res.data.country;
                        self.modules.ph_mon = 'partners-house';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-ph-active', '1').data('ph-active', '1');
                        $el.attr('data-ph-sub1', res.data.sub1).data('ph-sub1', res.data.sub1);
                        $el.attr('data-ph-sub2', res.data.sub2).data('ph-sub2', res.data.sub2);
                        $el.attr('data-ph-sub3', res.data.sub3).data('ph-sub3', res.data.sub3);
                        $el.attr('data-ph-sub4', res.data.sub4).data('ph-sub4', res.data.sub4);
                        $el.attr('data-ph-country', res.data.country).data('ph-country', res.data.country);
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
                    }
                    $btn.prop('disabled', false).text('Save');
                }).fail(function () {
                    $('#optee-ph-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Save');
                });
            });

            // Partners.House modal — clear.
            $(document).on('click', '#optee-ph-clear', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing...');

                Optee.Ajax.post('optee_set_partners_house_source', {
                    campaign_id: self.campaignId,
                    ph_active: '',
                    ph_sub1: '',
                    ph_sub2: '',
                    ph_sub3: '',
                    ph_sub4: '',
                    ph_country: ''
                }).done(function (res) {
                    $('#optee-ph-modal').fadeOut(150);
                    if (res.success) {
                        self.phActive  = '';
                        self.phSub1    = '';
                        self.phSub2    = '';
                        self.phSub3    = '';
                        self.phSub4    = '';
                        self.phCountry = '';
                        self.modules.ph_mon = '';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-ph-active', '').data('ph-active', '');
                        $el.attr('data-ph-sub1', '').data('ph-sub1', '');
                        $el.attr('data-ph-sub2', '').data('ph-sub2', '');
                        $el.attr('data-ph-sub3', '').data('ph-sub3', '');
                        $el.attr('data-ph-sub4', '').data('ph-sub4', '');
                        $el.attr('data-ph-country', '').data('ph-country', '');
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to clear.', 'error');
                    }
                    $btn.prop('disabled', false).text('Clear');
                }).fail(function () {
                    $('#optee-ph-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Clear');
                });
            });

            // RollerAds Publisher modal — close.
            $(document).on('click', '#optee-rap-backdrop, #optee-rap-close, #optee-rap-cancel', function () {
                $('#optee-rap-modal').fadeOut(150);
            });

            // RollerAds Publisher modal — save.
            $(document).on('click', '#optee-rap-save', function () {
                var $btn = $(this);
                var siteId   = $('#optee-rap-site').val();
                var siteName = $('#optee-rap-site option:selected').text().trim();
                var zoneId   = $('#optee-rap-zone').val();
                var zoneName = $('#optee-rap-zone option:selected').text().trim();

                if (!siteId) {
                    Optee.Toast.show('Please select a site.', 'error');
                    return;
                }

                $btn.prop('disabled', true).text('Saving...');

                Optee.Ajax.post('optee_set_rap_source', {
                    campaign_id: self.campaignId,
                    rap_site_id: siteId,
                    rap_site_name: siteName,
                    rap_zone_id: zoneId || '',
                    rap_zone_name: zoneId ? zoneName : ''
                }).done(function (res) {
                    $('#optee-rap-modal').fadeOut(150);
                    if (res.success) {
                        self.rapSiteId   = res.data.site_id;
                        self.rapSiteName = res.data.site_name;
                        self.rapZoneId   = res.data.zone_id;
                        self.rapZoneName = res.data.zone_name;
                        self.modules.rap_mon = 'rollerads-publisher';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-rap-site-id', res.data.site_id).data('rap-site-id', res.data.site_id);
                        $el.attr('data-rap-site-name', res.data.site_name).data('rap-site-name', res.data.site_name);
                        $el.attr('data-rap-zone-id', res.data.zone_id).data('rap-zone-id', res.data.zone_id);
                        $el.attr('data-rap-zone-name', res.data.zone_name).data('rap-zone-name', res.data.zone_name);
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
                    }
                    $btn.prop('disabled', false).text('Save');
                }).fail(function () {
                    $('#optee-rap-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Save');
                });
            });

            // RollerAds Publisher modal — clear.
            $(document).on('click', '#optee-rap-clear', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing...');

                Optee.Ajax.post('optee_set_rap_source', {
                    campaign_id: self.campaignId,
                    rap_site_id: '',
                    rap_site_name: '',
                    rap_zone_id: '',
                    rap_zone_name: ''
                }).done(function (res) {
                    $('#optee-rap-modal').fadeOut(150);
                    if (res.success) {
                        self.rapSiteId   = '';
                        self.rapSiteName = '';
                        self.rapZoneId   = '';
                        self.rapZoneName = '';
                        self.modules.rap_mon = '';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-rap-site-id', '').data('rap-site-id', '');
                        $el.attr('data-rap-site-name', '').data('rap-site-name', '');
                        $el.attr('data-rap-zone-id', '').data('rap-zone-id', '');
                        $el.attr('data-rap-zone-name', '').data('rap-zone-name', '');
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to clear.', 'error');
                    }
                    $btn.prop('disabled', false).text('Clear');
                }).fail(function () {
                    $('#optee-rap-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Clear');
                });
            });

            // HilltopAds Publisher modal — close.
            $(document).on('click', '#optee-hta-backdrop, #optee-hta-close, #optee-hta-cancel', function () {
                $('#optee-hta-modal').fadeOut(150);
            });

            // HilltopAds Publisher modal — save.
            $(document).on('click', '#optee-hta-save', function () {
                var $btn = $(this);
                var siteId   = $('#optee-hta-site').val();
                var siteName = $('#optee-hta-site option:selected').text().trim();
                var zoneId   = $('#optee-hta-zone').val();
                var zoneName = $('#optee-hta-zone option:selected').text().trim();

                if (!siteId) {
                    Optee.Toast.show('Please select a site.', 'error');
                    return;
                }

                $btn.prop('disabled', true).text('Saving...');

                Optee.Ajax.post('optee_set_hta_source', {
                    campaign_id: self.campaignId,
                    hta_site_id: siteId,
                    hta_site_name: siteName,
                    hta_zone_id: zoneId || '',
                    hta_zone_name: zoneId ? zoneName : ''
                }).done(function (res) {
                    $('#optee-hta-modal').fadeOut(150);
                    if (res.success) {
                        self.htaSiteId   = res.data.site_id;
                        self.htaSiteName = res.data.site_name;
                        self.htaZoneId   = res.data.zone_id;
                        self.htaZoneName = res.data.zone_name;
                        self.modules.hta_mon = 'hilltopads-publisher';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-hta-site-id', res.data.site_id).data('hta-site-id', res.data.site_id);
                        $el.attr('data-hta-site-name', res.data.site_name).data('hta-site-name', res.data.site_name);
                        $el.attr('data-hta-zone-id', res.data.zone_id).data('hta-zone-id', res.data.zone_id);
                        $el.attr('data-hta-zone-name', res.data.zone_name).data('hta-zone-name', res.data.zone_name);
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
                    }
                    $btn.prop('disabled', false).text('Save');
                }).fail(function () {
                    $('#optee-hta-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Save');
                });
            });

            // HilltopAds Publisher modal — clear.
            $(document).on('click', '#optee-hta-clear', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing...');

                Optee.Ajax.post('optee_set_hta_source', {
                    campaign_id: self.campaignId,
                    hta_site_id: '',
                    hta_site_name: '',
                    hta_zone_id: '',
                    hta_zone_name: ''
                }).done(function (res) {
                    $('#optee-hta-modal').fadeOut(150);
                    if (res.success) {
                        self.htaSiteId   = '';
                        self.htaSiteName = '';
                        self.htaZoneId   = '';
                        self.htaZoneName = '';
                        self.modules.hta_mon = '';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-hta-site-id', '').data('hta-site-id', '');
                        $el.attr('data-hta-site-name', '').data('hta-site-name', '');
                        $el.attr('data-hta-zone-id', '').data('hta-zone-id', '');
                        $el.attr('data-hta-zone-name', '').data('hta-zone-name', '');
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to clear.', 'error');
                    }
                    $btn.prop('disabled', false).text('Clear');
                }).fail(function () {
                    $('#optee-hta-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Clear');
                });
            });

            // Monetizer modal — close.
            $(document).on('click', '#optee-mtzr-backdrop, #optee-mtzr-close, #optee-mtzr-cancel', function () {
                $('#optee-mtzr-modal').fadeOut(150);
            });

            // Monetizer modal — save.
            $(document).on('click', '#optee-mtzr-save', function () {
                var $btn = $(this);
                var leagueId        = $('#optee-mtzr-league').val();
                var leagueName      = $('#optee-mtzr-league option:selected').text().trim();
                var campaignKeyword = $('#optee-mtzr-campaign').val();

                if (!leagueId) {
                    Optee.Toast.show('Please select a league.', 'error');
                    return;
                }

                $btn.prop('disabled', true).text('Saving...');

                Optee.Ajax.post('optee_set_monetizer_mon_source', {
                    campaign_id: self.campaignId,
                    mtzr_league_id: leagueId,
                    mtzr_league_name: leagueName,
                    mtzr_campaign_keyword: campaignKeyword || ''
                }).done(function (res) {
                    $('#optee-mtzr-modal').fadeOut(150);
                    if (res.success) {
                        self.mtzrLeagueId        = res.data.league_id;
                        self.mtzrLeagueName      = res.data.league_name;
                        self.mtzrCampaignKeyword = res.data.campaign_keyword;
                        self.modules.mtzr_mon = 'monetizer';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-mtzr-league-id', res.data.league_id).data('mtzr-league-id', res.data.league_id);
                        $el.attr('data-mtzr-league-name', res.data.league_name).data('mtzr-league-name', res.data.league_name);
                        $el.attr('data-mtzr-campaign-keyword', res.data.campaign_keyword).data('mtzr-campaign-keyword', res.data.campaign_keyword);
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
                    }
                    $btn.prop('disabled', false).text('Save');
                }).fail(function () {
                    $('#optee-mtzr-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Save');
                });
            });

            // Monetizer modal — clear.
            $(document).on('click', '#optee-mtzr-clear', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing...');

                Optee.Ajax.post('optee_set_monetizer_mon_source', {
                    campaign_id: self.campaignId,
                    mtzr_league_id: '',
                    mtzr_league_name: '',
                    mtzr_campaign_keyword: ''
                }).done(function (res) {
                    $('#optee-mtzr-modal').fadeOut(150);
                    if (res.success) {
                        self.mtzrLeagueId        = '';
                        self.mtzrLeagueName      = '';
                        self.mtzrCampaignKeyword = '';
                        self.modules.mtzr_mon = '';
                        var $el = $('#optee-campaign-stats');
                        $el.attr('data-mtzr-league-id', '').data('mtzr-league-id', '');
                        $el.attr('data-mtzr-league-name', '').data('mtzr-league-name', '');
                        $el.attr('data-mtzr-campaign-keyword', '').data('mtzr-campaign-keyword', '');
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to clear.', 'error');
                    }
                    $btn.prop('disabled', false).text('Clear');
                }).fail(function () {
                    $('#optee-mtzr-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('Clear');
                });
            });

            // Zeydoo save.
            $(document).off('click.zd-save').on('click.zd-save', '#optee-zd-save', function () {
                var requestVar = $('#optee-zd-request').val().trim();
                if (!requestVar) {
                    Optee.Toast.show('Request field is required.', 'error');
                    return;
                }
                var $btn = $(this);
                $btn.prop('disabled', true).text('Saving...');

                Optee.Ajax.post('optee_set_zeydoo_mon_source', {
                    campaign_id: self.campaignId,
                    zd_request_var: requestVar
                }).done(function (res) {
                    if (res.success) {
                        self.zdRequestVar = requestVar;
                        self.modules.zeydoo_mon = 'zeydoo';
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        $('#optee-zd-modal').fadeOut(150);
                        Optee.Toast.show(res.data.message || 'Zeydoo source saved.', 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
                    }
                }).fail(function () {
                    Optee.Toast.show('Failed to save Zeydoo source.', 'error');
                }).always(function () {
                    $btn.prop('disabled', false).text('Save');
                });
            });

            // Zeydoo clear.
            $(document).off('click.zd-clear').on('click.zd-clear', '#optee-zd-clear', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing...');

                Optee.Ajax.post('optee_set_zeydoo_mon_source', {
                    campaign_id: self.campaignId,
                    zd_request_var: ''
                }).done(function (res) {
                    if (res.success) {
                        self.zdRequestVar = '';
                        self.modules.zeydoo_mon = '';
                        self.buildActiveGroups();
                        self.renderTableHeader();
                        self.loadData();
                        $('#optee-zd-modal').fadeOut(150);
                        Optee.Toast.show('Zeydoo source removed.', 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed.', 'error');
                    }
                }).fail(function () {
                    Optee.Toast.show('Failed to clear Zeydoo source.', 'error');
                }).always(function () {
                    $btn.prop('disabled', false).text('Clear');
                });
            });

            // Zeydoo close.
            $(document).off('click.zd-close').on('click.zd-close', '#optee-zd-close, #optee-zd-modal', function (e) {
                if (e.target === this) $('#optee-zd-modal').fadeOut(150);
            });

            // Campaign status toggle confirm.
            $(document).on('click', '#optee-campaign-status-confirm', function (e) {
                e.preventDefault();
                var $btn = $(this);
                if ($btn.prop('disabled')) return;

                var action = self.campaignStatus === 'active' ? 'pause' : 'start';
                $btn.prop('disabled', true).css('opacity', 0.5).text(action === 'pause' ? 'Pausing...' : 'Starting...');

                Optee.Ajax.post('optee_toggle_campaign_status', {
                    campaign_id: self.campaignId,
                    status_action: action
                }).done(function (res) {
                    $('#optee-campaign-status-modal').fadeOut(150);
                    if (res.success) {
                        self.updateCampaignStatus(res.data.campaign_status || (action === 'pause' ? 'paused' : 'active'));
                        Optee.Toast.show(res.data.message, 'success');
                    } else {
                        Optee.Toast.show(res.data.message || 'Failed to update campaign status.', 'error');
                    }
                }).fail(function () {
                    $('#optee-campaign-status-modal').fadeOut(150);
                    Optee.Toast.show('Request failed.', 'error');
                });
            });
        },

        /* ----- Update URL with current drilldown + timeframe ----- */

        /**
         * Build the URL for the current report state. `drilldown` may be
         * overridden so the drill-down tabs can carry a real href (right-click →
         * "Open link in new tab"); everything else — timeframe, custom dates,
         * filters, zone — comes from the live state, so a new tab opens the same
         * view with only the report dimension changed. Shared with pushUrl so a
         * tab's href can never drift from what clicking it produces.
         */
        buildUrl: function (drilldown) {
            var params = new URLSearchParams(window.location.search);
            params.set('drilldown', drilldown || this.drilldown);
            params.set('timeframe', this.timeframe);
            if (this.timeframe === 'custom' && this.dateFrom && this.dateTo) {
                params.set('date_from', this.dateFrom);
                params.set('date_to', this.dateTo);
            } else {
                params.delete('date_from');
                params.delete('date_to');
            }
            if (this.hideNoClicks) {
                params.set('hide_no_clicks', '1');
            } else {
                params.delete('hide_no_clicks');
            }
            if (this.hideEmpty) {
                params.set('hide_empty', '1');
            } else {
                params.delete('hide_empty');
            }
            if (this.alwaysLive) {
                params.set('always_live', '1');
            } else {
                params.delete('always_live');
            }
            if (this.zoneFilter) {
                params.set('zone', this.zoneFilter);
            } else {
                params.delete('zone');
            }
            return window.location.pathname + '?' + params.toString();
        },

        pushUrl: function (replace) {
            // Real navigations (drilldown / timeframe / zone) push a new history entry so
            // the browser Back button steps between report views. Programmatic URL syncs
            // (resolved custom dates) and filter toggles pass replace=true instead.
            history[replace ? 'replaceState' : 'pushState'](null, '', this.buildUrl());
            this.urlSnapshot = window.location.search;
        },

        /* ----- Read drilldown/timeframe/filters from the URL (reset, then apply) ----- */

        readUrlState: function () {
            var params = new URLSearchParams(window.location.search);
            this.drilldown = params.get('drilldown') || 'default';
            this.timeframe = params.get('timeframe') || '7d';
            this.dateFrom = '';
            this.dateTo = '';
            if (this.timeframe === 'custom') {
                var uf = params.get('date_from'), ut = params.get('date_to');
                if (/^\d{4}-\d{2}-\d{2}$/.test(uf) && /^\d{4}-\d{2}-\d{2}$/.test(ut)) {
                    this.dateFrom = uf;
                    this.dateTo = ut;
                } else {
                    this.timeframe = '7d';
                }
            }
            this.hideNoClicks = params.get('hide_no_clicks') === '1';
            this.hideEmpty = params.get('hide_empty') === '1';
            this.alwaysLive = params.get('always_live') === '1';
            this.zoneFilter = params.get('zone') || '';
            this.urlSnapshot = window.location.search;
        },

        /* ----- Browser Back/Forward: restore state from URL, no new history entry ----- */

        onPopState: function () {
            // Not every popstate is a report navigation. The full-screen overlay
            // pushes a same-URL entry so Android Back closes it (and closing from
            // ✕/Esc pops that entry back off) — reloading the report there would
            // burn a traffic-source API call and flash "Loading…" for a view that
            // did not change. Only re-render when the query string actually moved.
            if (window.location.search === this.urlSnapshot) return;
            this.readUrlState();
            this.buildActiveGroups();
            this.renderDrilldowns();
            this.renderTimeframe();
            this.renderTableHeader();
            this.renderZoneFilterChip();
            $('#optee-hide-no-clicks').prop('checked', this.hideNoClicks);
            $('#optee-tf-hide-empty').prop('checked', this.hideEmpty);
            $('#optee-always-live').prop('checked', this.alwaysLive);
            this.loadData();
        },

        /* ----- Grouped campaign: lock zone toggles in the "All campaigns" view ----- */

        applyZoneLock: function () {
            if (!this.tsGrouped) return;
            var locked = !this.tsMember;
            $('#optee-stats-wrapper').toggleClass('optee-zones-locked', locked);
            var $hint = $('#optee-ts-member-hint');
            if ($hint.length) {
                $hint.text(locked
                    ? 'Select a single campaign to block/unblock zones.'
                    : 'Zone blocking applies to the selected campaign only.');
            }
        },

        /* ----- Load data via AJAX ----- */

        loadData: function (forceRefresh) {
            var self = this;
            this.statsLoading = true;
            var colCount = this.allColumns.length || 1;
            var $tbody = $('#optee-stats-table tbody');
            $tbody.html('<tr><td colspan="' + colCount + '" class="py-8 text-gray-400"><span style="position:sticky;left:0;display:inline-block;padding-left:12px;">Loading...</span></td></tr>');

            var params = {
                campaign_id: this.campaignId,
                drilldown: this.drilldown,
                timeframe: this.timeframe
            };
            if (this.timeframe === 'custom' && this.dateFrom && this.dateTo) {
                params.date_from = this.dateFrom;
                params.date_to = this.dateTo;
            }
            if (this.tsMember) params.ts_campaign_member = this.tsMember;
            if (this.zoneFilter) params.zone_filter = this.zoneFilter;
            if (forceRefresh || this.alwaysLive) params.force_refresh = 1;

            Optee.Ajax.post('optee_get_campaign_stats', params).done(function (res) {
                if (res.success) {
                    // Reflect any server-side clamping of a custom range back into the label.
                    if (self.timeframe === 'custom' && res.data.date_from && res.data.date_to &&
                        (res.data.date_from !== self.dateFrom || res.data.date_to !== self.dateTo)) {
                        self.dateFrom = res.data.date_from;
                        self.dateTo = res.data.date_to;
                        self.renderTimeframe();
                        self.pushUrl(true);
                    }
                    self.data = res.data.rows || [];
                    self.automationRunning = !!res.data.automation_running;
                    // Tracker live fetch blocked by the running automation cycle (no cache
                    // fallback). This is what the user actually waits for — monetization
                    // balances and AdScore rows can still be present, so we key the empty-state
                    // notice off the tracker specifically, not off overall row count.
                    self.trackerBlocked = false;
                    var _cs = res.data.cache_status || {};
                    for (var _slug in _cs) {
                        if (_cs.hasOwnProperty(_slug) && _cs[_slug] &&
                            _cs[_slug].module_type === 'tracker' && _cs[_slug].skipped) {
                            self.trackerBlocked = true;
                            break;
                        }
                    }
                    // If a manual reload already returned fresh (post-automation) data,
                    // cancel any pending completion watcher so we don't prompt redundantly.
                    if (!self.automationRunning && self._autoWatchTimer) {
                        clearInterval(self._autoWatchTimer);
                        self._autoWatchTimer = null;
                    }
                    var bz = res.data.blocked_zones;
                    self.blockedZones = Array.isArray(bz) ? bz : (bz ? Object.values(bz) : []);
                    var wz = res.data.whitelisted_zones;
                    self.whitelistedZones = Array.isArray(wz) ? wz : (wz ? Object.values(wz) : []);
                    self.isWhitelistCampaign = self.whitelistedZones.length > 0;
                    self.noteCounts = res.data.note_counts || {};
                    // Per-country targeting for the geo Active toggle. null = not a geo
                    // report / TS has no country targeting → no toggle column. [] = RON
                    // (targets all countries). [codes] = explicit targeted list.
                    var tc = res.data.targeted_countries;
                    self.targetedCountries = (tc === null || typeof tc === 'undefined')
                        ? null
                        : (Array.isArray(tc) ? tc : Object.values(tc));
                    // Per-value targeting (OS / browser / …) for the dimension-report
                    // Active toggle. null = not a targetable dimension report.
                    var tt = res.data.targeted_targeting;
                    self.targetedTargeting = (tt === null || typeof tt === 'undefined')
                        ? null
                        : (Array.isArray(tt) ? tt : Object.values(tt));
                    self.targetingDim = res.data.targeting_dim || null;
                    // The Active-toggle columns (countries / targeting) depend on data that
                    // only arrives with THIS response — buildActiveGroups() already ran on
                    // the drilldown switch (before we had it). Always rebuild columns +
                    // header now so the toggle appears where it should AND a stale toggle
                    // column is dropped when switching to a report that has none.
                    self.buildActiveGroups();
                    self.renderTableHeader();
                    if (res.data.generated_at) {
                        $('#optee-stats-generated').text('Report generated at: ' + res.data.generated_at);
                    }
                    if (res.data.cron_last_run) {
                        $('#optee-stats-cron').text('Cron last run: ' + res.data.cron_last_run);
                    }
                    self.updateCacheIndicator(res.data.cache_status || {}, res.data.cache_ttl);
                    self.updateCampaignStatus(res.data.campaign_status || '');
                    self.updateWhitelistBadge();
                    self.updateSuggestionBadge();
                    self.updateTsInfoBadge();
                    if (res.data.module_errors && res.data.module_errors.length) {
                        res.data.module_errors.forEach(function (err) {
                            var msg = (err.message || 'Unknown error').replace(/^[\w.\s-]+(?:error|failed):\s*/i, '');
                            Optee.Toast.show(err.module + ': ' + msg, err.type || 'error');
                        });
                    }
                    if (!self.userSorted) {
                        self.autoDetectSort();
                    }
                    self.sortAndRender();
                    // Fill the per-day breakout columns (if enabled) via a lazy follow-up
                    // fetch, so the main table isn't blocked by the extra day-slice calls.
                    self.maybeLoadPerday(forceRefresh);
                    // If automation had priority during this load (live fetch skipped),
                    // watch for it to finish and prompt the user to reload.
                    if (self.automationRunning) {
                        self.watchAutomationDone();
                    }
                } else {
                    var errMsg = res.data.message || 'Failed to load.';
                    $tbody.html('<tr><td colspan="' + colCount + '" class="py-8 text-red-400"><span style="position:sticky;left:0;display:inline-block;padding-left:12px;">' +
                        Optee.Util.escapeHtml(errMsg) + '</span></td></tr>');
                    Optee.Toast.show(errMsg, 'error');
                }
            }).fail(function () {
                $tbody.html('<tr><td colspan="' + colCount + '" class="py-8 text-red-400"><span style="position:sticky;left:0;display:inline-block;padding-left:12px;">Request failed.</span></td></tr>');
                Optee.Toast.show('Request failed. Check your connection.', 'error');
            }).always(function () {
                self.statsLoading = false;
            });
        },

        /* ----- Auto-reload ----- */

        /** Minutes offered in the menu. No 1-minute option: on an always-live
         *  report every tick is a real traffic-source/tracker API call, and a
         *  per-minute poll left open all day is how rate limits get hit. */
        autoReloadChoices: [ 0, 5, 10, 15, 30 ],

        /** sessionStorage key — per campaign, so two open reports are independent. */
        autoReloadKey: function () {
            return 'optee_auto_reload_' + (this.campaignId || 0);
        },

        setAutoReload: function (minutes) {
            minutes = parseInt(minutes, 10) || 0;
            this.autoReloadMs = minutes * 60000;

            try {
                if (minutes) { sessionStorage.setItem(this.autoReloadKey(), String(minutes)); }
                else { sessionStorage.removeItem(this.autoReloadKey()); }
            } catch (e) { /* private mode — the timer still works for this page view */ }

            this.stopAutoReload();
            if (minutes) {
                this.autoReloadStartedAt = Date.now();
                this.startAutoReload();
            }
            this.paintAutoReload();
        },

        startAutoReload: function () {
            var self = this;
            if (!this.autoReloadMs) { return; }

            this.autoReloadNextAt = Date.now() + this.autoReloadMs;

            this.autoReloadTimer = setInterval(function () { self.autoReloadTick(); }, this.autoReloadMs);
            this.autoReloadCountdown = setInterval(function () { self.paintAutoReload(); }, 1000);
        },

        stopAutoReload: function () {
            if (this.autoReloadTimer) { clearInterval(this.autoReloadTimer); this.autoReloadTimer = null; }
            if (this.autoReloadCountdown) { clearInterval(this.autoReloadCountdown); this.autoReloadCountdown = null; }
            this.autoReloadNextAt = 0;
        },

        autoReloadTick: function () {
            // Give up after two hours. A report left open overnight should not
            // still be polling at 4am.
            if (Date.now() - this.autoReloadStartedAt > 2 * 60 * 60 * 1000) {
                this.setAutoReload(0);
                Optee.Toast.show('Auto-reload switched off after 2 hours.', 'info');
                return;
            }

            // Nobody is looking, and the tab may have been backgrounded for hours.
            if (document.hidden) { this.autoReloadNextAt = Date.now() + this.autoReloadMs; return; }

            // A previous load is still running (slow API, big timeframe) — skip
            // rather than queue, or a stalled fetch would pile up requests.
            if (this.statsLoading) { this.autoReloadNextAt = Date.now() + this.autoReloadMs; return; }

            // The automation cycle takes priority over live fetches; the page
            // already explains this and prompts to reload when it finishes.
            if (this.automationRunning) { this.autoReloadNextAt = Date.now() + this.autoReloadMs; return; }

            this.autoReloadNextAt = Date.now() + this.autoReloadMs;
            this.loadData();
        },

        /** Countdown text + the spinning reload icon that says it is armed. */
        paintAutoReload: function () {
            var $btn = $('#optee-stats-reload');
            $btn.toggleClass('optee-auto-reload-on', !!this.autoReloadMs);
            $btn.attr('title', this.autoReloadMs
                ? 'Reload (auto every ' + (this.autoReloadMs / 60000) + ' min)'
                : 'Reload');

            var $out = $('#optee-auto-reload-next');
            if (!$out.length) { return; }
            if (!this.autoReloadMs) { $out.text(''); return; }

            var left = Math.max(0, this.autoReloadNextAt - Date.now());
            var m = Math.floor(left / 60000);
            var s = Math.floor((left % 60000) / 1000);
            $out.text('Next in ' + m + ':' + (s < 10 ? '0' : '') + s + (document.hidden ? ' (paused)' : ''));
        },

        /** The popover row. Included in both cache-badge variants. */
        autoReloadHtml: function () {
            var opts = '';
            for (var i = 0; i < this.autoReloadChoices.length; i++) {
                var v = this.autoReloadChoices[i];
                opts += '<option value="' + v + '"' + (v === this.autoReloadMs / 60000 ? ' selected' : '') + '>' +
                        (v ? 'Every ' + v + ' min' : 'Off') + '</option>';
            }

            var html = '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #f3f4f6;">' +
                '<label style="display:flex;align-items:center;gap:6px;font-size:11px;color:#6b7280;">' +
                    '<span>Auto-reload</span>' +
                    '<select id="optee-auto-reload" class="optee-select" style="flex:1;font-size:11px;padding:2px 6px;height:auto;">' + opts + '</select>' +
                '</label>' +
                '<div id="optee-auto-reload-next" style="font-size:10px;color:#9ca3af;margin-top:3px;"></div>';

            // Say what it will cost. On an always-live report every tick is a real
            // API call to the traffic source, tracker and network.
            if (this.alwaysLive) {
                html += '<div style="font-size:10px;color:#b45309;margin-top:3px;line-height:1.4;">' +
                        'Live data is on, so each auto-reload is a real API call.</div>';
            }

            return html + '</div>';
        },

        /* ----- Per-day breakout lazy fetch ----- */

        maybeLoadPerday: function (forceRefresh) {
            if (!this.perdayConfig || !this.perdayConfig.enabled) return;
            if (this.drilldown !== 'default') return;
            if (!this.modules.tracker) return;
            if (!this.data || !this.data.length) return;
            this.loadPerday(forceRefresh);
        },

        loadPerday: function (forceRefresh, _isRepoll) {
            var self = this;
            // Fresh user-initiated load → reset the progressive-warm poll budget.
            if (!_isRepoll) self._perdayPolls = 0;
            if (self._perdayPollTimer) { clearTimeout(self._perdayPollTimer); self._perdayPollTimer = null; }

            var params = {
                campaign_id: this.campaignId,
                days: this.perdayConfig.days,
                metrics: this.perdayConfig.metrics
            };
            // Only the initial load may force-refresh; re-polls must read the cache we're
            // progressively warming, else we'd re-burst and never converge.
            if (!_isRepoll && (forceRefresh || this.alwaysLive)) params.force_refresh = 1;

            Optee.Ajax.post('optee_get_campaign_perday', params).done(function (res) {
                if (!res || !res.success || !res.data) return;
                self.perdayData = res.data.rows || {};
                // Merge the per-day values into the current rows, keyed by zone id.
                for (var i = 0; i < self.data.length; i++) {
                    var zid = String(self.data[i].zone_id || '');
                    var pd = self.perdayData[zid];
                    if (pd) $.extend(self.data[i], pd);
                }
                self.sortAndRender();

                // Progressive warm-up: BeMob's rate limit (~1 call/10s, shared with the
                // cron) means uncached days trickle in a couple per request. Re-poll
                // ~10s later until the backend reports nothing pending (capped so we
                // never poll forever).
                var pending = parseInt(res.data.pending, 10) || 0;
                self.updatePerdayStatus(pending);
                if (pending > 0 && (self._perdayPolls || 0) < 8) {
                    self._perdayPolls = (self._perdayPolls || 0) + 1;
                    self._perdayPollTimer = setTimeout(function () {
                        self.loadPerday(false, true);
                    }, 10000);
                }
            });
        },

        /* Small inline "loading N more days…" hint shown while the per-day cache warms. */
        updatePerdayStatus: function (pending) {
            var $el = $('#optee-perday-status');
            if (pending > 0) {
                if (!$el.length) {
                    var $host = $('#optee-stats-drilldowns');
                    if (!$host.length) return;
                    $el = $('<span id="optee-perday-status" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;font-size:12px;color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;border-radius:9999px;padding:3px 10px;vertical-align:middle;"></span>');
                    $host.append($el);
                }
                $el.html('<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Loading ' + pending + ' more day' + (pending === 1 ? '' : 's') + '… (BeMob rate limit)');
            } else {
                $el.remove();
            }
        },

        /* ----- Watch for the running automation cycle to finish ----- */

        watchAutomationDone: function () {
            var self = this;
            if (self._autoWatchTimer) return; // already watching
            var intervalMs = Math.max(5, parseInt((typeof opteeData !== 'undefined' && opteeData.heartbeatInterval), 10) || 8) * 1000;
            self._autoWatchTimer = setInterval(function () {
                Optee.Ajax.post('optee_get_cron_status', {}).done(function (res) {
                    if (!res || !res.success || !res.data) return;
                    if (!res.data.automation_running) {
                        clearInterval(self._autoWatchTimer);
                        self._autoWatchTimer = null;
                        self.automationRunning = false;
                        self.showAutomationDonePopup();
                    }
                });
            }, intervalMs);
        },

        showAutomationDonePopup: function () {
            var self = this;
            if (document.getElementById('optee-automation-done-popup')) return;

            var wrap = document.createElement('div');
            wrap.id = 'optee-automation-done-popup';
            wrap.setAttribute('style',
                'position:fixed;z-index:10000;left:50%;bottom:24px;transform:translateX(-50%);' +
                'width:380px;max-width:calc(100vw - 24px);background:#fff;border:1px solid #e5e7eb;' +
                'border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.18);padding:16px 18px;' +
                'display:flex;flex-direction:column;gap:12px;');
            wrap.innerHTML =
                '<div style="display:flex;align-items:flex-start;gap:10px;">' +
                    '<span style="flex-shrink:0;width:28px;height:28px;border-radius:9999px;background:#ecfdf5;display:inline-flex;align-items:center;justify-content:center;">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
                    '</span>' +
                    '<div style="flex:1;min-width:0;">' +
                        '<div style="font-weight:600;font-size:14px;color:#111827;">Automation finished</div>' +
                        '<div style="font-size:12px;color:#6b7280;line-height:1.5;margin-top:2px;">Fresh data is now available. Reload the report to see the latest numbers.</div>' +
                    '</div>' +
                    '<button type="button" class="optee-adp-close" title="Dismiss" style="flex-shrink:0;background:none;border:none;cursor:pointer;color:#9ca3af;padding:2px;margin:-2px -4px 0 0;">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' +
                    '</button>' +
                '</div>' +
                '<div style="display:flex;gap:8px;justify-content:flex-end;">' +
                    '<button type="button" class="optee-adp-dismiss" style="font-size:13px;color:#4b5563;background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:7px 14px;cursor:pointer;">Not now</button>' +
                    '<button type="button" class="optee-btn-primary optee-adp-reload" style="font-size:13px;padding:7px 16px;">Reload report</button>' +
                '</div>';
            document.body.appendChild(wrap);

            function close() { if (wrap.parentNode) wrap.parentNode.removeChild(wrap); }
            wrap.querySelector('.optee-adp-reload').addEventListener('click', function () {
                close();
                self.loadData(true);
            });
            wrap.querySelector('.optee-adp-dismiss').addEventListener('click', close);
            wrap.querySelector('.optee-adp-close').addEventListener('click', close);
        },

        /* ----- Auto-detect initial sort column ----- */

        autoDetectSort: function () {
            var rows = this.data;
            if (!rows.length) return;

            if (this.drilldown === 'days' || this.drilldown === 'months') {
                this.sortKey = 'zone_id';
                this.sortDir = 'desc';
                return;
            }

            // Conversions detail: sort by timestamp DESC.
            if (this.drilldown === 'conversions') {
                this.sortKey = 'timestamp';
                this.sortDir = 'desc';
                return;
            }

            // Merged country view (geo with traffic-source columns): sort by spend DESC.
            if (this.drilldown === 'ts_geo' || (this.drilldown === 'geo' && this.tsSupportsCountries)) {
                this.sortKey = 'cost';
                this.sortDir = 'desc';
                return;
            }

            // Tracker-only drilldowns (incl. mapped-token dims): sort by visits DESC.
            var trackerOnly = { geo:1, languages:1, cities:1, regions:1, browsers:1, 'browser-versions':1, useragents:1, isp:1, 'mobile-carriers':1, os:1, 'os-versions':1, connection:1, lp:1, offers:1, category:1 };
            if (trackerOnly[this.drilldown] || (this.mappedDimCfg && this.mappedDimCfg[this.drilldown])) {
                this.sortKey = 'tr_visits';
                this.sortDir = 'desc';
                return;
            }

            var hasClicks = false;
            for (var i = 0; i < rows.length; i++) {
                if (parseFloat(rows[i].clicks) > 0) {
                    hasClicks = true;
                    break;
                }
            }

            this.sortKey = hasClicks ? 'clicks' : 'impressions';
            this.sortDir = 'desc';
        },

        /* ----- Sort + render ----- */

        sortAndRender: function () {
            var self = this;
            var rows = this.data.slice();

            // Filter out no-click zones when the suggestion filter is active.
            // Default (zone) report ONLY — same gate as the suggestion badge that
            // offers this toggle. `clicks` is a traffic-source field, so tracker-only
            // drilldowns (offers, lp, geo, browsers, isp, os…) don't carry it at all;
            // since hide_no_clicks=1 survives in the URL when you switch drilldown,
            // the filter used to score every row 0 there and empty the whole report.
            if (this.hideNoClicks && this.drilldown === 'default') {
                rows = rows.filter(function (r) {
                    return (parseFloat(r.clicks) || 0) > 0;
                });
            }

            // Hide empty rows (dead days with no activity in any module) when the
            // "Hide empty rows" toggle is on. Keeps only rows with real data. Skipped
            // on the conversions detail report — its rows are text-only (no metric
            // columns) so they'd all read as "empty".
            if (this.hideEmpty && this.drilldown !== 'conversions') {
                rows = rows.filter(function (r) {
                    return self.rowHasAnyData(r);
                });
            }

            // Materialize custom-column values onto each row so sort + render read uniformly.
            for (var ci = 0; ci < this.allColumns.length; ci++) {
                var ccol = this.allColumns[ci];
                if (!ccol.isCustom) continue;
                // Zone drilldown is tracker-scoped: blank custom columns that reference any
                // non-tracker module (traffic source / antifraud / monetization) — that data
                // isn't zone-scopable, so a mixed formula would show a misleading value.
                var ccNonTracker = false;
                if (this.zoneFilter && ccol.depGroups) {
                    for (var dg = 0; dg < ccol.depGroups.length; dg++) {
                        if (ccol.depGroups[dg] !== 'tracker') { ccNonTracker = true; break; }
                    }
                }
                for (var ri = 0; ri < rows.length; ri++) {
                    if (ccNonTracker) { rows[ri][ccol.key] = null; continue; }
                    // Balance-based custom columns are blank on empty days (no TS activity),
                    // mirroring the built-in ts_balance behaviour.
                    if (ccol.refsTsBalance && !this.rowHasTsData(rows[ri])) {
                        rows[ri][ccol.key] = null;
                        continue;
                    }
                    // Blank the custom value on rows where EVERY module the formula
                    // depends on has no activity (a dead day). Otherwise a column like
                    // "Cost TS" would render $0.00 on days with no traffic at all.
                    if (ccol.depGroups && ccol.depGroups.length && this.rowIsDeadForGroups(rows[ri], ccol.depGroups)) {
                        rows[ri][ccol.key] = null;
                        continue;
                    }
                    var cv = CC.evalRPN(ccol.formulaRPN, rows[ri], ccol.treatMissingZero);
                    if (ccol.clampNegative && cv !== null && cv < 0) cv = 0;
                    rows[ri][ccol.key] = cv;
                }
            }

            if (this.sortKey) {
                rows.sort(function (a, b) {
                    var va = a[self.sortKey];
                    var vb = b[self.sortKey];
                    if (va == null) va = '';
                    if (vb == null) vb = '';
                    var sa = String(va);
                    var sb = String(vb);
                    // Only use numeric sort if the entire value is a number.
                    if (sa !== '' && sb !== '' && !isNaN(sa) && !isNaN(sb)) {
                        var na = parseFloat(sa);
                        var nb = parseFloat(sb);
                        return self.sortDir === 'asc' ? na - nb : nb - na;
                    }
                    return self.sortDir === 'asc' ? sa.localeCompare(sb) : sb.localeCompare(sa);
                });
            }

            // Per-column filters (funnel icon in each header). Runs after custom and
            // plugin column values are materialised onto the rows, so those columns
            // can be filtered too; before render, so SUM/AVG total the filtered set.
            rows = this.applyColumnFilters(rows);

            this.renderRows(rows);
            this.updateSortArrows();
        },

        renderRows: function (rows) {
            // Remember exactly what's on screen (post sort + filters) so exports match.
            this.lastRenderedRows = rows;
            // Keep the filter chips and selection bar in step with the table.
            this.renderFilterChips();
            this.renderSelectionBar();

            var $tbody = $('#optee-stats-table tbody');
            var colCount = this.allColumns.length || 1;

            // Show the empty-state notice when there are no rows at all, OR when a tracker is
            // used but its data was blocked by the running automation cycle — even if other
            // sources (balances, AdScore) produced rows. The tracker is what the user is here for.
            if (!rows.length || this.trackerBlocked) {
                var emptyInner;
                if (this.automationRunning) {
                    // Automation has priority and pauses live data fetching to respect
                    // traffic-source API rate limits, so the report comes back empty.
                    emptyInner = '<span style="position:sticky;left:0;display:inline-flex;align-items:flex-start;gap:8px;padding:0 12px;max-width:min(640px, calc(100vw - 32px));box-sizing:border-box;text-align:left;white-space:normal;">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2aa5ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
                        '<span style="line-height:1.5;"><strong style="color:#374151;">Automation is running.</strong><br>Live data fetching is paused while the automation cycle completes (it runs first to respect traffic-source API rate limits). Your report will be ready once it finishes — you\'ll get a prompt to reload.</span>' +
                        '</span>';
                } else {
                    emptyInner = '<span style="position:sticky;left:0;display:inline-block;padding-left:12px;">No data available.</span>';
                }
                $tbody.html('<tr><td colspan="' + colCount + '" class="py-8 text-gray-400">' + emptyInner + '</td></tr>');
                return;
            }

            // Check if traffic source columns should show "not available" message.
            // BidVertiser doesn't support daily reports via API.
            var tsNoDailyReport = this.drilldown === 'days' && this.modules.traffic_source === 'bidvertiser';
            var tsCols = 0, tsStartIdx = -1;
            if (tsNoDailyReport) {
                for (var ti = 0; ti < this.allColumns.length; ti++) {
                    if (this.allColumns[ti].group === 'traffic_source') {
                        if (tsStartIdx === -1) tsStartIdx = ti;
                        tsCols++;
                    }
                }
            }

            // Precompute per-column min/max for relative ("higher/lower is better") coloring.
            for (var rc = 0; rc < this.allColumns.length; rc++) {
                var rcol = this.allColumns[rc];
                rcol._cmin = null;
                rcol._cmax = null;
                if (!this.needsRelativeColor(rcol)) continue;
                var mn = Infinity, mx = -Infinity, hasAny = false;
                for (var rr = 0; rr < rows.length; rr++) {
                    var rv = parseFloat(rows[rr][rcol.key]);
                    if (isNaN(rv)) continue;
                    hasAny = true;
                    if (rv < mn) mn = rv;
                    if (rv > mx) mx = rv;
                }
                if (hasAny) { rcol._cmin = mn; rcol._cmax = mx; }
            }

            var html = '';
            for (var i = 0; i < rows.length; i++) {
                var zoneVal = String(rows[i].zone_id || '');
                var isBlocked = this.isWhitelistCampaign
                    ? this.whitelistedZones.indexOf(zoneVal) === -1
                    : this.blockedZones.indexOf(zoneVal) !== -1;
                // Geo report: a country is "removed" when the TS has an explicit
                // targeting list that doesn't include this row's country code. RON
                // (empty list) means all countries are active → never removed.
                var countryCode = String(rows[i].country_code || '');
                var isCountryRemoved = this.targetedCountries !== null
                    && this.targetedCountries.length > 0
                    && countryCode !== ''
                    && this.targetedCountries.indexOf(countryCode) === -1;
                // Dimension report (OS/browser/…): a value is "removed" when the TS has
                // an explicit targeting list that excludes this row's resolved PopAds ID.
                // Empty list = targets all → never removed. No ID = row not editable.
                var targetId = String(rows[i].ts_target_id || '');
                var isTargetingRemoved;
                if (typeof rows[i].ts_target_active !== 'undefined') {
                    // Hierarchy-aware flag from the server (e.g. form factor: a child is
                    // active when its parent is targeted, even if its own id isn't listed).
                    isTargetingRemoved = targetId !== '' && !rows[i].ts_target_active;
                } else {
                    isTargetingRemoved = this.targetedTargeting !== null
                        && this.targetedTargeting.length > 0
                        && targetId !== ''
                        && this.targetedTargeting.indexOf(targetId) === -1;
                }
                var rowCls = [];
                if (isBlocked || isCountryRemoved || isTargetingRemoved) rowCls.push('optee-zone-blocked');
                if (this.highlightWeekends() && this.drilldown === 'days' && this.isWeekendDate(rows[i].zone_id)) {
                    rowCls.push('optee-weekend-row');
                }
                // data-row-value lets core (and plugins) address a row by its first
                // column value without scraping cell text — see Optee.Report.flashRow.
                html += '<tr data-row-value="' + Optee.Util.escapeHtml(String(rows[i].zone_id || '')) + '"' +
                    (rowCls.length ? ' class="' + rowCls.join(' ') + '"' : '') + '>';
                var tsSpanDone = false;
                for (var j = 0; j < this.allColumns.length; j++) {
                    var col = this.allColumns[j];
                    // Span all TS columns with a single message cell on first data row.
                    if (tsNoDailyReport && col.group === 'traffic_source') {
                        if (!tsSpanDone) {
                            if (i === 0) {
                                html += '<td colspan="' + tsCols + '" rowspan="' + rows.length + '" style="text-align:center;vertical-align:middle;color:#9ca3af;font-size:12px;font-style:italic;background:#fafafa;">Daily report not available via BidVertiser API</td>';
                            }
                            tsSpanDone = true;
                        }
                        continue;
                    }
                    if (col.type === 'toggle') {
                        // BidVertiser: show lock icon until zone status is loaded on demand.
                        if (this.modules.traffic_source === 'bidvertiser' && !this.bdZonesLoaded) {
                            html += '<td class="optee-toggle-cell" style="text-align:center;"><button type="button" class="optee-bd-load-zones" title="Click to load zone status" style="background:none;border:none;cursor:pointer;color:#d1d5db;padding:2px;display:inline-flex;align-items:center;">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' +
                                '</button></td>';
                        } else {
                            var toggleCls = 'optee-zone-toggle' + (isBlocked ? '' : ' is-on');
                            html += '<td class="optee-toggle-cell"><button type="button" class="' + toggleCls + '" data-zone-id="' +
                                Optee.Util.escapeHtml(String(zoneVal)) + '"><span class="optee-zone-toggle-thumb"></span></button></td>';
                        }
                        continue;
                    }
                    if (col.type === 'country_toggle') {
                        // No code (tracker-only row the TS didn't report) → nothing to toggle.
                        if (countryCode === '') {
                            html += '<td class="optee-toggle-cell"></td>';
                            continue;
                        }
                        var cToggleCls = 'optee-country-toggle' + (isCountryRemoved ? '' : ' is-on');
                        html += '<td class="optee-toggle-cell"><button type="button" class="' + cToggleCls + '" data-country-code="' +
                            Optee.Util.escapeHtml(countryCode) + '"><span class="optee-zone-toggle-thumb"></span></button></td>';
                        continue;
                    }
                    if (col.type === 'targeting_toggle') {
                        // Row has no PopAds match (e.g. "—" in the target column) → not editable.
                        if (targetId === '') {
                            html += '<td class="optee-toggle-cell"></td>';
                            continue;
                        }
                        var tToggleCls = 'optee-targeting-toggle' + (isTargetingRemoved ? '' : ' is-on');
                        html += '<td class="optee-toggle-cell"><button type="button" class="' + tToggleCls + '" data-value="' +
                            Optee.Util.escapeHtml(targetId) + '"><span class="optee-zone-toggle-thumb"></span></button></td>';
                        continue;
                    }
                    // Columns from other plugins compute their own value from the row.
                    var val;
                    if (col.isExternal && col.valueFn) {
                        try { val = col.valueFn(rows[i], this); } catch (e) { val = null; }
                    } else {
                        val = rows[i][col.key];
                    }
                    // Balance is a daily snapshot stored for every date, so it would
                    // otherwise show on empty days with no traffic-source activity.
                    // Only surface it on rows that actually have TS data.
                    if (col.key === 'ts_balance' && !this.rowHasTsData(rows[i])) {
                        val = null;
                    }
                    // Traffic Loss % is stored as 0 rather than missing, so it would show
                    // 0.00% on days with no AdScore activity. Blank it on those rows.
                    if (col.key === 'traffic_loss' && !this.rowHasAfData(rows[i])) {
                        val = null;
                    }
                    var fmt = this.formatCell(val, col);
                    var stickyClass = col.sticky ? ' optee-sticky-col' : '';
                    var tdStyle = (col.align === 'left' ? '' : 'text-align:right;') + (fmt.style || '');
                    // Keep the annotation column hugging its content (see header note).
                    if (col.key === 'ts_target') { tdStyle += 'width:1%;white-space:nowrap;'; }
                    var alignStyle = tdStyle ? ' style="' + tdStyle + '"' : '';
                    var titleAttr = col.sticky && val ? ' title="' + Optee.Util.escapeHtml(String(val)) + '"' : '';
                    // Note marker on the zone label: a small dot so annotated zones are
                    // visible in the table instead of only through their context menu.
                    var cellHtml = fmt.html;
                    if (j === 0 && this.drilldown === 'default') {
                        // Row checkbox, inside the sticky cell for the same reason as
                        // the select-all above: no extra column, no colspan changes.
                        cellHtml = '<input type="checkbox" class="optee-row-select" data-value="' +
                            Optee.Util.escapeHtml(String(zoneVal)) + '"' +
                            (this.selected[String(zoneVal)] ? ' checked' : '') +
                            ' style="cursor:pointer;margin-right:7px;vertical-align:middle;">' + cellHtml;
                        // Markers contributed by other plugins (see Report extension points).
                        var markers = (window.Optee && Optee.Report && Optee.Report.rowMarkerProviders) || [];
                        for (var mi = 0; mi < markers.length; mi++) {
                            var mHtml;
                            try {
                                mHtml = markers[mi]({ value: zoneVal, row: rows[i], drilldown: this.drilldown });
                            } catch (e) {
                                continue;   // a broken provider must never break the row
                            }
                            if (mHtml) { cellHtml += mHtml; }
                        }
                        var nCount = this.noteCounts[String(zoneVal)] || 0;
                        if (nCount) {
                            cellHtml += '<span class="optee-note-dot" data-zone-id="' + Optee.Util.escapeHtml(String(zoneVal)) + '"' +
                                ' title="' + nCount + ' note' + (nCount > 1 ? 's' : '') + ' — click to view"' +
                                ' style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#2aa5ff;margin-left:6px;vertical-align:middle;cursor:pointer;"></span>';
                        }
                    }
                    html += '<td class="' + fmt.cls + stickyClass + '"' + alignStyle + titleAttr + '>' + cellHtml + '</td>';
                }
                html += '</tr>';
            }

            // Sum and Average footer rows (skip for conversions detail report).
            if (this.drilldown !== 'conversions') {
                var sumRow = this.buildSummaryRow(rows, 'sum');
                var avgRow = this.buildSummaryRow(rows, 'avg');

                var rowCountHint = ' <span style="color:#b0b8c4;font-weight:400;">(' + rows.length + ' rows)</span>';

                html += '<tr class="optee-stats-summary-row">';
                html += '<td class="optee-sticky-col optee-summary-label">SUM' + rowCountHint + '</td>';
                var sumTsSkipped = false;
                for (var s = 1; s < this.allColumns.length; s++) {
                    var col = this.allColumns[s];
                    if (tsNoDailyReport && col.group === 'traffic_source') { if (!sumTsSkipped) { html += '<td colspan="' + tsCols + '"></td>'; sumTsSkipped = true; } continue; }
                    if (col.type === 'toggle' || col.type === 'country_toggle' || col.type === 'targeting_toggle') { html += '<td></td>'; continue; }
                    var fmt = this.formatCell(sumRow[col.key], col, true);
                    html += '<td class="' + fmt.cls + '" style="text-align:right;">' + fmt.html + '</td>';
                }
                html += '</tr>';

                html += '<tr class="optee-stats-summary-row">';
                html += '<td class="optee-sticky-col optee-summary-label">AVG</td>';
                var avgTsSkipped = false;
                for (var a = 1; a < this.allColumns.length; a++) {
                    var col = this.allColumns[a];
                    if (tsNoDailyReport && col.group === 'traffic_source') { if (!avgTsSkipped) { html += '<td colspan="' + tsCols + '"></td>'; avgTsSkipped = true; } continue; }
                    if (col.type === 'toggle' || col.type === 'country_toggle' || col.type === 'targeting_toggle') { html += '<td></td>'; continue; }
                    var fmt = this.formatCell(avgRow[col.key], col, true);
                    html += '<td class="' + fmt.cls + '" style="text-align:right;">' + fmt.html + '</td>';
                }
                html += '</tr>';
            }

            $tbody.html(html);

            // Decide whether the "Hide blocked" toggle should be shown.
            this.updateHideBlockedVisibility();
        },

        buildSummaryRow: function (rows, mode) {
            var result = {};
            var count = rows.length;
            if (!count) return result;

            var startIdx = this.drilldown === 'conversions' ? 0 : 1;

            // First pass: sum every column. Used for both SUM totals and AVG averages.
            var sums = {};
            var counts = {}; // Per-column count of non-NaN values (for AVG of derived cols).
            for (var j = startIdx; j < this.allColumns.length; j++) {
                var col = this.allColumns[j];
                if (col.noSum) continue;
                var sum = 0;
                var c = 0;
                for (var i = 0; i < count; i++) {
                    var v = parseFloat(rows[i][col.key]);
                    if (!isNaN(v)) { sum += v; c++; }
                }
                sums[col.key] = sum;
                counts[col.key] = c;
            }

            // Keep the untouched column-wise totals: the AVG row wants a per-row mean
            // of the raw values, so it must not see the ratio normalization below.
            var rawSums = {};
            for (var rk in sums) { rawSums[rk] = sums[rk]; }

            // Ratio columns cannot be added up — a column-wise sum of per-row
            // percentages is meaningless (seven daily CVs summed to 121% where the
            // real total was 10.9%). Normalize them in `sums` BEFORE it becomes the
            // evaluation context for custom formulas, so a custom column wrapping
            // ROI / CV / CTR reports the same total as the built-in column beside it.
            // Reads num/den from rawSums so the result never depends on column order.
            for (var d = startIdx; d < this.allColumns.length; d++) {
                var dcol = this.allColumns[d];
                if (dcol.noSum || dcol.isCustom) continue;
                if (dcol.derive) {
                    var dnum = rawSums[dcol.derive.num];
                    var dden = rawSums[dcol.derive.den];
                    sums[dcol.key] = (dden && !isNaN(dnum)) ? (dnum / dden * (dcol.derive.mult || 1)) : 0;
                } else if (dcol.type === 'percent' && counts[dcol.key]) {
                    // No numerator/denominator to rebuild from (traffic_loss, bot_% …),
                    // so the mean is the honest aggregate — and it matches the fallback
                    // the built-in column already renders for itself.
                    sums[dcol.key] = sums[dcol.key] / counts[dcol.key];
                }
            }

            // Second pass: emit values per mode.
            for (var k = startIdx; k < this.allColumns.length; k++) {
                var c2 = this.allColumns[k];
                if (c2.noSum) continue;
                var n = counts[c2.key];
                if (!n) continue;

                var defDec = this.displayDecimals();
                var decimals = c2.type === 'money'
                    ? (c2.decimals === 0 || c2.decimals ? c2.decimals : defDec)
                    : (c2.type === 'percent' ? defDec : 0);

                if (mode === 'sum') {
                    // SUM row: derived/custom metrics are recalculated from totals.
                    if (c2.isCustom) {
                        var v = CC.evalRPN(c2.formulaRPN, sums, c2.treatMissingZero);
                        if (c2.clampNegative && v !== null && v < 0) v = 0;
                        if (v !== null) result[c2.key] = v.toFixed(decimals);
                    } else if (c2.derive) {
                        var num = sums[c2.derive.num];
                        var den = sums[c2.derive.den];
                        if (den && !isNaN(num)) {
                            var mult = c2.derive.mult || 1;
                            result[c2.key] = (num / den * mult).toFixed(decimals);
                        }
                    } else if (c2.type === 'percent') {
                        // Non-derived percent (e.g. proxy/junk/bot %) — fall back to mean.
                        result[c2.key] = (sums[c2.key] / n).toFixed(decimals);
                    } else {
                        // Additive money/number — straight sum.
                        result[c2.key] = c2.type === 'money' ? sums[c2.key].toFixed(decimals) : sums[c2.key];
                    }
                } else {
                    // AVG row: simple per-row mean for every column. Reads rawSums, NOT
                    // the ratio-normalized sums — a mean of already-aggregated ratios
                    // would divide a correct total by the row count.
                    if (c2.isCustom) {
                        // Build per-key means and evaluate the formula against them.
                        var means = {};
                        for (var mk in rawSums) {
                            if (counts[mk]) means[mk] = rawSums[mk] / counts[mk];
                        }
                        var v2 = CC.evalRPN(c2.formulaRPN, means, c2.treatMissingZero);
                        if (c2.clampNegative && v2 !== null && v2 < 0) v2 = 0;
                        if (v2 !== null) result[c2.key] = v2.toFixed(decimals);
                    } else {
                        var avg = rawSums[c2.key] / n;
                        result[c2.key] = c2.type === 'money' || c2.type === 'percent'
                            ? avg.toFixed(decimals)
                            : Math.round(avg);
                    }
                }
            }
            return result;
        },

        /* ----- Cell formatting & color coding ----- */

        /**
         * True when a row has any real traffic-source activity (raw metrics), as
         * opposed to a balance-only snapshot row. Used to hide the daily Balance
         * value on empty days.
         */
        rowHasTsData: function (row) {
            var keys = ['impressions', 'clicks', 'conversions', 'cost', 'payout'];
            for (var i = 0; i < keys.length; i++) {
                var v = parseFloat(row[keys[i]]);
                if (!isNaN(v) && v !== 0) return true;
            }
            return false;
        },

        /**
         * True when a row has any AdScore (antifraud) activity. Used to hide the
         * Traffic Loss % value on days with no AdScore data (it's stored as 0, not null).
         */
        rowHasAfData: function (row) {
            var keys = ['af_visits', 'valid_hits', 'af_cost'];
            for (var i = 0; i < keys.length; i++) {
                var v = parseFloat(row[keys[i]]);
                if (!isNaN(v) && v !== 0) return true;
            }
            return false;
        },

        /**
         * True when a row has any real activity for the given module group.
         * Scans the group's raw (non-derived) count/money columns for a non-zero
         * value. Excludes ts_balance (a daily snapshot present on every date) so a
         * balance-only row still reads as empty. Used to blank custom columns on
         * rows where every module they depend on has no activity.
         */
        rowHasGroupData: function (row, group) {
            for (var i = 0; i < this.allColumns.length; i++) {
                var col = this.allColumns[i];
                if (col.group !== group) continue;
                if (col.derive || col.key === 'ts_balance') continue;
                if (col.type !== 'number' && col.type !== 'money') continue;
                var v = parseFloat(row[col.key]);
                if (!isNaN(v) && v !== 0) return true;
            }
            return false;
        },

        /**
         * True when a row has any real activity across ANY module group — i.e. it is
         * NOT a dead/empty day. Scans raw (non-derived, non-custom) count/money columns
         * for a non-zero value; excludes ts_balance (a daily snapshot on every date) so
         * a balance-only row still reads as empty. Backs the "Hide empty rows" filter.
         */
        rowHasAnyData: function (row) {
            for (var i = 0; i < this.allColumns.length; i++) {
                var col = this.allColumns[i];
                if (col.derive || col.isCustom || col.key === 'ts_balance') continue;
                if (col.type !== 'number' && col.type !== 'money') continue;
                var v = parseFloat(row[col.key]);
                if (!isNaN(v) && v !== 0) return true;
            }
            return false;
        },

        /**
         * True when a row has no activity in ANY of the given module groups — i.e.
         * a completely dead day for everything a custom column depends on. Requiring
         * all groups to be empty (not just one) preserves treat_missing_zero columns
         * that intentionally read as full-loss when a later stage is missing.
         */
        rowIsDeadForGroups: function (row, groups) {
            for (var i = 0; i < groups.length; i++) {
                if (this.rowHasGroupData(row, groups[i])) return false;
            }
            return true;
        },

        formatCell: function (val, col, isSummary) {
            if (val == null || val === '') {
                return { html: '&mdash;', cls: 'optee-cell-empty', style: '' };
            }

            var display = '';
            var cls = '';
            var style = '';

            switch (col.type) {
                case 'number':
                    display = this.fmtNumber(val);
                    // "Higher/Lower is better" on a numeric column = relative green gradient.
                    if (!isSummary) style = this.relativeColorStyle(parseFloat(val), col);
                    break;
                case 'percent':
                    display = this.fmtPercent(val, col.decimals);
                    cls = this.colorPercent(parseFloat(val), col.color);
                    break;
                case 'money':
                    display = '$' + this.fmtMoney(val, col.decimals);
                    if (col.color === 'profit') {
                        var n = parseFloat(val);
                        cls = n < 0 ? 'optee-cell-red' : (n > 0 ? 'optee-cell-green' : '');
                    } else if (!isSummary) {
                        // "Higher/Lower is better" on a money column = relative green gradient.
                        style = this.relativeColorStyle(parseFloat(val), col);
                    }
                    break;
                default:
                    display = Optee.Util.escapeHtml(String(val));
                    break;
            }

            return { html: display, cls: cls, style: style };
        },

        /**
         * Whether a column should be colored relative to its own value range
         * (money/number columns set to "Higher is better" or "Lower is better").
         * Percent columns keep their fixed thresholds instead.
         */
        needsRelativeColor: function (col) {
            return (col.type === 'money' || col.type === 'number') &&
                   (col.color === 'higher_better' || col.color === 'lower_better');
        },

        /**
         * Inline background style: a light→dark green gradient based on where the
         * value falls within the column's min..max range (computed per render).
         * Best end = deepest green. Returns '' when not applicable.
         */
        relativeColorStyle: function (n, col) {
            if (isNaN(n)) return '';
            if (col.color !== 'higher_better' && col.color !== 'lower_better') return '';
            if (col._cmin == null || col._cmax == null || col._cmax <= col._cmin) return '';
            var t = (n - col._cmin) / (col._cmax - col._cmin); // 0 = lowest, 1 = highest
            if (col.color === 'lower_better') t = 1 - t;        // invert so "best" = deepest green
            // Interpolate green-50 (#f0fdf4) -> green-300 (#86efac).
            var c0 = [240, 253, 244], c1 = [134, 239, 172];
            var r = Math.round(c0[0] + (c1[0] - c0[0]) * t);
            var g = Math.round(c0[1] + (c1[1] - c0[1]) * t);
            var b = Math.round(c0[2] + (c1[2] - c0[2]) * t);
            return 'background-color:rgb(' + r + ',' + g + ',' + b + ');color:#166534;';
        },

        /* =============================================================
           Selection + bulk actions
           ============================================================= */

        /**
         * Selection and filters belong to the view they were made in — a "Clicks > 100"
         * filter or a set of zone ids means nothing after switching drilldown.
         */
        resetViewState: function () {
            this.selected = {};
            this.lastClickedRow = null;
            this.colFilters = {};
            $('#optee-selection-bar').remove();
            $('#optee-filter-chips').remove();
            $('#optee-filter-pop').remove();
        },

        selectedValues: function () {
            var out = [];
            for (var k in this.selected) {
                if (this.selected.hasOwnProperty(k) && this.selected[k]) out.push(k);
            }
            return out;
        },

        clearSelection: function () {
            this.selected = {};
            this.lastClickedRow = null;
            $('#optee-stats-table .optee-row-select').prop('checked', false);
            $('#optee-select-all').prop('checked', false);
            this.renderSelectionBar();
        },

        /** Floating bar with the count and the bulk actions. */
        renderSelectionBar: function () {
            var vals = this.selectedValues();
            var $bar = $('#optee-selection-bar');
            if (!vals.length) { $bar.remove(); return; }

            if (!$bar.length) {
                $bar = $('<div id="optee-selection-bar" style="position:fixed;left:50%;transform:translateX(-50%);' +
                    'bottom:22px;z-index:80;background:#111827;color:#fff;border-radius:12px;padding:10px 14px;' +
                    'box-shadow:0 12px 30px rgba(0,0,0,.28);display:flex;align-items:center;gap:10px;font-size:13px;"></div>');
                $('body').append($bar);
            }
            var btn = 'background:#1f2937;border:1px solid #374151;color:#fff;border-radius:8px;padding:6px 11px;' +
                'font-size:12.5px;font-weight:600;cursor:pointer;';
            $bar.html(
                '<span style="font-weight:700;"><span id="optee-sel-count">' + vals.length + '</span> selected</span>' +
                '<button type="button" id="optee-sel-copy" style="' + btn + '">Copy IDs</button>' +
                '<button type="button" id="optee-sel-copy-comma" style="' + btn + '">Copy comma-separated</button>' +
                '<button type="button" id="optee-sel-block" style="' + btn + 'background:#7f1d1d;border-color:#991b1b;">Block</button>' +
                '<button type="button" id="optee-sel-unblock" style="' + btn + '">Unblock</button>' +
                '<button type="button" id="optee-sel-clear" style="' + btn + 'background:transparent;border-color:#4b5563;">Clear</button>'
            );
        },

        /**
         * Block or unblock every selected zone. Runs through the existing
         * single-zone endpoint one at a time: no new backend, and the traffic
         * source sees a sane request rate rather than a burst.
         */
        bulkZoneAction: function (block) {
            var vals = this.selectedValues();
            if (!vals.length) return;
            var word = block ? 'Block' : 'Unblock';
            if (!window.confirm(word + ' ' + vals.length + ' selected zone' + (vals.length > 1 ? 's' : '') + '?')) return;

            var self = this, i = 0, ok = 0, failed = 0;
            Optee.Toast.show(word + 'ing ' + vals.length + ' zones…', 'info');

            function next() {
                if (i >= vals.length) {
                    Optee.Toast.show(word + 'ed ' + ok + ' of ' + vals.length + ' zones' +
                        (failed ? ' · ' + failed + ' failed' : ''), failed && !ok ? 'error' : 'success');
                    self.clearSelection();
                    self.loadData();
                    return;
                }
                var z = vals[i++];
                var postData = {
                    campaign_id: self.campaignId,
                    zone_id: z,
                    zone_action: block ? 'block' : 'unblock'
                };
                if (self.isWhitelistCampaign) postData.is_whitelist = '1';
                if (self.tsMember) postData.ts_campaign_member = self.tsMember;

                Optee.Ajax.post('optee_toggle_zone', postData)
                    .done(function (res) { if (res && res.success) { ok++; } else { failed++; } })
                    .fail(function () { failed++; })
                    .always(function () { setTimeout(next, 250); });
            }
            next();
        },

        copySelection: function (sep) {
            var vals = this.selectedValues();
            if (!vals.length) return;
            var text = vals.join(sep);
            var done = function () {
                Optee.Toast.show(vals.length + ' zone ID' + (vals.length > 1 ? 's' : '') + ' copied', 'success');
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {
                    Optee.Toast.show('Failed to copy.', 'error');
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); }
                catch (e) { Optee.Toast.show('Failed to copy.', 'error'); }
                document.body.removeChild(ta);
            }
        },

        /* =============================================================
           Column filters
           ============================================================= */

        /** Keep only rows passing every active filter (filters combine with AND). */
        applyColumnFilters: function (rows) {
            var keys = [];
            for (var k in this.colFilters) {
                if (this.colFilters.hasOwnProperty(k)) keys.push(k);
            }
            if (!keys.length) return rows;
            var self = this;
            return rows.filter(function (r) {
                for (var i = 0; i < keys.length; i++) {
                    if (!self.rowPassesFilter(r, keys[i], self.colFilters[keys[i]])) return false;
                }
                return true;
            });
        },

        rowPassesFilter: function (row, key, f) {
            if (!f || !f.op) return true;
            var raw = row[key];

            if (f.op === 'contains' || f.op === 'not_contains') {
                var hay = String(raw == null ? '' : raw).toLowerCase();
                var needle = String(f.v1 == null ? '' : f.v1).toLowerCase();
                if (needle === '') return true;
                var hit = hay.indexOf(needle) !== -1;
                return f.op === 'contains' ? hit : !hit;
            }

            var n = parseFloat(raw);
            if (!isFinite(n)) {
                // Blank cells only survive an "is empty" test.
                return f.op === 'empty';
            }
            var a = parseFloat(f.v1);
            var b = parseFloat(f.v2);
            switch (f.op) {
                case 'gt':      return isFinite(a) ? n >  a : true;
                case 'gte':     return isFinite(a) ? n >= a : true;
                case 'lt':      return isFinite(a) ? n <  a : true;
                case 'lte':     return isFinite(a) ? n <= a : true;
                case 'eq':      return isFinite(a) ? n === a : true;
                case 'neq':     return isFinite(a) ? n !== a : true;
                case 'between': return (isFinite(a) ? n >= a : true) && (isFinite(b) ? n <= b : true);
                case 'empty':   return false;      // has a number, so not empty
                case 'not_empty': return true;
                default:        return true;
            }
        },

        /** Chips above the table showing what's filtered, each removable. */
        /**
         * Compact "Filters: ON" badge in the campaign status row (next to Live data
         * / Active). Deliberately NOT a bar above the table: anything inserted there
         * shifts #optee-stats-wrapper, whose max-height is computed from its viewport
         * position, which pushed the horizontal scrollbar out of view.
         */
        renderFilterChips: function () {
            var $host = $('#optee-filter-badge');
            var n = this.filterCount();

            if (!n) {
                $host.remove();
                $('#optee-filter-list').remove();
                return;
            }
            if (!$host.length) {
                $host = $('<span id="optee-filter-badge"></span>');
                // Sit alongside the other status badges; fall back to above the table.
                var $anchor = $('#optee-stats-suggestion');
                if ($anchor.length) { $anchor.before($host); }
                else { $('#optee-stats-wrapper').before($host); }
            }
            $host.html(
                '<button type="button" id="optee-filter-badge-btn" title="Show active filters" ' +
                'style="display:inline-flex;align-items:center;gap:6px;background:#2aa5ff;color:#fff;' +
                'border:none;border-radius:9999px;padding:4px 12px;font-size:12px;font-weight:700;cursor:pointer;' +
                'box-shadow:0 1px 3px rgba(42,165,255,.35);">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor">' +
                '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>' +
                'Filters: ON (' + n + ')</button>'
            );
        },

        /** Popup listing the active filters, each removable. */
        openFilterList: function () {
            $('#optee-filter-list').remove();
            if (!this.filterCount()) { return; }

            var rows = '';
            for (var k in this.colFilters) {
                if (!this.colFilters.hasOwnProperty(k)) continue;
                rows += '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;' +
                    'padding:7px 0;border-bottom:1px solid #f3f4f6;">' +
                    '<span style="font-size:13px;color:#111827;">' +
                    Optee.Util.escapeHtml(this.filterLabel(k, this.colFilters[k])) + '</span>' +
                    '<span class="optee-chip-x" data-col="' + Optee.Util.escapeHtml(k) + '" title="Remove filter" ' +
                    'style="cursor:pointer;color:#9ca3af;font-size:17px;line-height:1;">&times;</span></div>';
            }

            var $btn = $('#optee-filter-badge-btn');
            var r = $btn.length ? $btn[0].getBoundingClientRect() : { left: 20, bottom: 80 };
            var $p = $('<div id="optee-filter-list" style="position:fixed;z-index:10000;background:#fff;' +
                'border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.15);' +
                'padding:12px 14px;min-width:250px;max-width:340px;">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">' +
                '<span style="font-weight:700;font-size:13px;">Active filters</span>' +
                '<span id="optee-fl-close" title="Close" style="cursor:pointer;color:#9ca3af;font-size:18px;line-height:1;">&times;</span>' +
                '</div>' + rows +
                '<button type="button" id="optee-clear-filters" style="margin-top:10px;width:100%;background:#f3f4f6;' +
                'border:1px solid #e5e7eb;color:#374151;font-size:12.5px;font-weight:600;cursor:pointer;' +
                'border-radius:8px;padding:6px 10px;">Clear all filters</button></div>');
            $('body').append($p);

            var pw = $p.outerWidth(), ph = $p.outerHeight();
            $p.css({ left: Math.max(8, Math.min(r.left, window.innerWidth - pw - 8)),
                     top:  Math.max(8, Math.min(r.bottom + 6, window.innerHeight - ph - 8)) });

            $('#optee-fl-close').on('click', function () { $p.remove(); });
            setTimeout(function () {
                $(document).on('click.filterlist', function (e) {
                    if (!$(e.target).closest('#optee-filter-list, #optee-filter-badge').length) {
                        $('#optee-filter-list').remove();
                        $(document).off('click.filterlist');
                    }
                });
            }, 0);
        },

        /** Popover for one column's filter. */
        openFilterPopover: function (colKey, x, y) {
            $('#optee-filter-pop').remove();
            var col = null;
            for (var i = 0; i < this.allColumns.length; i++) {
                if (this.allColumns[i].key === colKey) { col = this.allColumns[i]; break; }
            }
            if (!col) return;
            var cur = this.colFilters[colKey] || { op: '', v1: '', v2: '' };
            var isText = (colKey === 'zone_id' || col.align === 'left');

            var ops = isText
                ? [['contains', 'contains'], ['not_contains', 'does not contain'], ['eq', '='],
                   ['empty', 'is empty'], ['not_empty', 'has value']]
                : [['gt', 'greater than'], ['gte', 'at least'], ['lt', 'less than'], ['lte', 'at most'],
                   ['eq', 'equals'], ['neq', 'not equal'], ['between', 'between'],
                   ['empty', 'is empty'], ['not_empty', 'has value']];

            var opts = '<option value="">— no filter —</option>';
            for (var o = 0; o < ops.length; o++) {
                opts += '<option value="' + ops[o][0] + '"' + (cur.op === ops[o][0] ? ' selected' : '') + '>' + ops[o][1] + '</option>';
            }

            var $p = $('<div id="optee-filter-pop" style="position:fixed;z-index:10000;background:#fff;border:1px solid #e5e7eb;' +
                'border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.15);padding:12px;width:240px;font-size:13px;">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">' +
                '<span style="font-weight:700;">' + Optee.Util.escapeHtml(col.label) + '</span>' +
                '<span id="optee-f-close" title="Close" style="cursor:pointer;color:#9ca3af;font-size:18px;line-height:1;padding:0 2px;">×</span>' +
                '</div>' +
                '<select id="optee-f-op" style="width:100%;padding:6px;border:1px solid #e5e7eb;border-radius:7px;margin-bottom:7px;">' + opts + '</select>' +
                '<div id="optee-f-vals">' +
                '<input type="' + (isText ? 'text' : 'number') + '" id="optee-f-v1" step="any" placeholder="value" value="' +
                Optee.Util.escapeHtml(String(cur.v1 == null ? '' : cur.v1)) + '" style="width:100%;padding:6px;border:1px solid #e5e7eb;border-radius:7px;">' +
                '<input type="number" id="optee-f-v2" step="any" placeholder="and" value="' +
                Optee.Util.escapeHtml(String(cur.v2 == null ? '' : cur.v2)) + '" style="width:100%;padding:6px;border:1px solid #e5e7eb;border-radius:7px;margin-top:6px;display:none;">' +
                '</div>' +
                '<div style="display:flex;gap:6px;margin-top:10px;">' +
                '<button type="button" id="optee-f-apply" class="optee-btn-primary optee-btn-sm" style="flex:1;">Apply</button>' +
                '<button type="button" id="optee-f-clear" class="optee-btn-secondary optee-btn-sm">Clear</button>' +
                '</div></div>');
            $('body').append($p);

            var pw = $p.outerWidth(), ph = $p.outerHeight();
            $p.css({ left: Math.max(8, Math.min(x, window.innerWidth - pw - 8)),
                     top:  Math.max(8, Math.min(y, window.innerHeight - ph - 8)) });

            var self = this;
            var syncVals = function () {
                var op = $('#optee-f-op').val();
                $('#optee-f-v2').toggle(op === 'between');
                $('#optee-f-v1').toggle(op !== '' && op !== 'empty' && op !== 'not_empty');
            };
            syncVals();
            $('#optee-f-op').on('change', syncVals);
            $('#optee-f-apply').on('click', function () {
                var op = $('#optee-f-op').val();
                self.setColumnFilter(colKey, op ? { op: op, v1: $('#optee-f-v1').val(), v2: $('#optee-f-v2').val() } : null);
                $p.remove();
            });
            $('#optee-f-clear').on('click', function () {
                self.setColumnFilter(colKey, null);
                $p.remove();
            });
            $('#optee-f-close').on('click', function () { $p.remove(); });
            $('#optee-f-v1, #optee-f-v2').on('keydown', function (e) {
                if (e.key === 'Enter') { $('#optee-f-apply').trigger('click'); }
                if (e.key === 'Escape') { $p.remove(); }
            });
            setTimeout(function () {
                $(document).on('click.filterpop', function (e) {
                    if (!$(e.target).closest('#optee-filter-pop, .optee-col-filter').length) {
                        $('#optee-filter-pop').remove();
                        $(document).off('click.filterpop');
                    }
                });
            }, 0);
        },

        filterCount: function () {
            var n = 0;
            for (var k in this.colFilters) { if (this.colFilters.hasOwnProperty(k)) n++; }
            return n;
        },

        setColumnFilter: function (key, f) {
            if (!f || !f.op) { delete this.colFilters[key]; }
            else { this.colFilters[key] = f; }
            this.renderTableHeader();
            this.renderFilterChips();
            this.renderRows(this.data || this.lastRenderedRows || []);
        },

        clearColumnFilters: function () {
            this.colFilters = {};
            this.renderTableHeader();
            this.renderFilterChips();
            this.renderRows(this.data || []);
        },

        /** Human label for a filter, used on the chips. */
        filterLabel: function (key, f) {
            var col = null;
            for (var i = 0; i < this.allColumns.length; i++) {
                if (this.allColumns[i].key === key) { col = this.allColumns[i]; break; }
            }
            var name = col ? col.label : key;
            var ops = { gt: '>', gte: '≥', lt: '<', lte: '≤', eq: '=', neq: '≠',
                        between: 'between', contains: 'contains', not_contains: 'excludes',
                        empty: 'is empty', not_empty: 'has value' };
            var op = ops[f.op] || f.op;
            if (f.op === 'empty' || f.op === 'not_empty') return name + ' ' + op;
            if (f.op === 'between') return name + ' ' + op + ' ' + f.v1 + '–' + f.v2;
            return name + ' ' + op + ' ' + f.v1;
        },

        colorPercent: function (n, mode) {
            if (!mode || isNaN(n)) return '';
            if (mode === 'profit') {
                return n < 0 ? 'optee-cell-red' : (n > 0 ? 'optee-cell-green' : '');
            }
            if (mode === 'higher_better') {
                if (n >= 80) return 'optee-cell-green';
                if (n >= 50) return 'optee-cell-yellow';
                return 'optee-cell-red';
            }
            if (mode === 'lower_better') {
                if (n <= 5) return 'optee-cell-green';
                if (n <= 20) return 'optee-cell-yellow';
                return 'optee-cell-red';
            }
            if (mode === 'valid_traffic') {
                return n > 80 ? 'optee-cell-green' : '';
            }
            if (mode === 'bot_threshold') {
                return n > 20 ? 'optee-cell-red' : '';
            }
            return '';
        },

        /**
         * Default number of decimals for money/percent cells. Read from the
         * user's profile preference (Settings → Profile → Display); falls back
         * to 2 for backwards compatibility. Columns with an explicit `decimals`
         * (e.g. Revenue, custom columns) keep their own value.
         */
        displayDecimals: function () {
            var d = (typeof opteeData !== 'undefined' && opteeData.statsDecimals != null)
                ? parseInt(opteeData.statsDecimals, 10) : 2;
            return (isNaN(d) || d < 0) ? 2 : d;
        },

        /** Whether weekend (Sat/Sun) rows should be background-highlighted in the days drilldown. */
        highlightWeekends: function () {
            return (typeof opteeData !== 'undefined' && !!opteeData.highlightWeekends);
        },

        /** True when a 'YYYY-MM-DD' string falls on a Saturday or Sunday (parsed in local time). */
        isWeekendDate: function (str) {
            if (!str) return false;
            var m = String(str).match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (!m) return false;
            var dow = new Date(+m[1], +m[2] - 1, +m[3]).getDay();
            return dow === 0 || dow === 6;
        },

        fmtNumber: function (v) {
            var n = parseInt(v, 10);
            return isNaN(n) ? String(v) : n.toLocaleString();
        },

        fmtPercent: function (v, decimals) {
            // Respect an explicit per-column decimals (including 0), like fmtMoney;
            // otherwise use the user default.
            var d = (decimals === 0 || decimals) ? decimals : this.displayDecimals();
            var n = parseFloat(v);
            return isNaN(n) ? String(v) : n.toFixed(d) + '%';
        },

        fmtMoney: function (v, decimals) {
            // Respect an explicit per-column decimals (including 0); otherwise use the user default.
            var d = (decimals === 0 || decimals) ? decimals : this.displayDecimals();
            var n = parseFloat(v);
            return isNaN(n) ? (0).toFixed(d) : n.toFixed(d);
        },

        /* ----- Export: CSV + Print / Save as PDF ----- */

        // Non-toggle columns in display order, each with a disambiguated header
        // (group label + column label) so repeated names like "Revenue" stay unique.
        exportColumns: function () {
            var out = [];
            for (var i = 0; i < this.allColumns.length; i++) {
                var col = this.allColumns[i];
                if (col.type === 'toggle' || col.type === 'country_toggle' || col.type === 'targeting_toggle') continue;
                var label = (i === 0) ? this.drilldownLabel() : (col.label || col.key);
                var group = '';
                if (col.group) {
                    group = this.moduleNames[col.group] ||
                        (COL_GROUPS[col.group] && COL_GROUPS[col.group].label) || '';
                }
                out.push({ col: col, key: col.key, label: label, group: group });
            }
            return out;
        },

        // Plain-text value for a cell (no HTML), mirroring formatCell's display.
        exportCellText: function (val, col) {
            if (val == null || val === '') return '';
            switch (col.type) {
                case 'number':  return this.fmtNumber(val);
                case 'percent': return this.fmtPercent(val, col && col.decimals);
                case 'money':   return '$' + this.fmtMoney(val, col.decimals);
                default:        return String(val);
            }
        },

        // Filename stem: optee-<campaign-slug>-<drilldown>.
        exportFilenameBase: function () {
            var name = ($('#optee-campaign-title').text() || ('campaign-' + this.campaignId)).trim();
            var slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'campaign';
            return 'optee-' + slug + '-' + this.drilldown;
        },

        downloadFile: function (content, filename, mime) {
            var blob = new Blob([content], { type: mime });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        },

        exportCSV: function () {
            var self = this;
            var rows = this.lastRenderedRows || [];
            if (!rows.length) { Optee.Toast.show('No data to export.', 'info'); return; }

            var cols = this.exportColumns();
            var esc = function (v) {
                v = (v == null) ? '' : String(v);
                return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
            };

            var lines = [];
            // Header — prefix the group label when present to keep column names unique.
            lines.push(cols.map(function (c) {
                return esc(c.group ? c.group + ' ' + c.label : c.label);
            }).join(','));

            // Data rows.
            for (var i = 0; i < rows.length; i++) {
                lines.push(cols.map(function (c) {
                    return esc(self.exportCellText(rows[i][c.key], c.col));
                }).join(','));
            }

            // SUM / AVG footer (matches the on-screen summary rows).
            if (this.drilldown !== 'conversions') {
                var sumRow = this.buildSummaryRow(rows, 'sum');
                var avgRow = this.buildSummaryRow(rows, 'avg');
                var footer = function (srcRow, firstLabel) {
                    return cols.map(function (c, idx) {
                        return idx === 0 ? esc(firstLabel) : esc(self.exportCellText(srcRow[c.key], c.col));
                    }).join(',');
                };
                lines.push(footer(sumRow, 'SUM (' + rows.length + ' rows)'));
                lines.push(footer(avgRow, 'AVG'));
            }

            // BOM so Excel reads UTF-8 correctly.
            var csv = '﻿' + lines.join('\r\n');
            this.downloadFile(csv, this.exportFilenameBase() + '.csv', 'text/csv;charset=utf-8;');
            Optee.Toast.show('CSV exported.', 'success');
        },

        printTable: function () {
            var self = this;
            var rows = this.lastRenderedRows || [];
            if (!rows.length) { Optee.Toast.show('No data to print.', 'info'); return; }

            var cols = this.exportColumns();
            var esc = Optee.Util.escapeHtml;

            // Group header row — a leading blank cell for the date/zone column, then
            // one colspan cell per active group (skipping empty ones).
            var groupCounts = {};
            for (var g = 1; g < cols.length; g++) {
                var gk = cols[g].col.group;
                if (gk) groupCounts[gk] = (groupCounts[gk] || 0) + 1;
            }
            var groupHtml = '<th></th>';
            for (var ai = 0; ai < this.activeGroups.length; ai++) {
                var key = this.activeGroups[ai];
                var span = groupCounts[key] || 0;
                if (!span) continue;
                var gname = this.moduleNames[key] || (COL_GROUPS[key] && COL_GROUPS[key].label) || key;
                groupHtml += '<th colspan="' + span + '" class="grp">' + esc(gname) + '</th>';
            }

            // Column header row.
            var colHtml = '';
            for (var ci = 0; ci < cols.length; ci++) {
                var alignC = (ci === 0 || cols[ci].col.align === 'left') ? ' class="left"' : '';
                colHtml += '<th' + alignC + '>' + esc(cols[ci].label) + '</th>';
            }

            // Body rows (reuse formatCell so colors + gradients carry over).
            var bodyHtml = '';
            for (var r = 0; r < rows.length; r++) {
                bodyHtml += '<tr>';
                for (var c = 0; c < cols.length; c++) {
                    var col = cols[c].col;
                    var fmt = this.formatCell(rows[r][cols[c].key], col);
                    var align = (c === 0 || col.align === 'left') ? 'left' : 'right';
                    bodyHtml += '<td class="' + fmt.cls + '" style="text-align:' + align + ';' + (fmt.style || '') + '">' + fmt.html + '</td>';
                }
                bodyHtml += '</tr>';
            }

            // SUM / AVG footer.
            if (this.drilldown !== 'conversions') {
                var sumRow = this.buildSummaryRow(rows, 'sum');
                var avgRow = this.buildSummaryRow(rows, 'avg');
                var footRow = function (srcRow, firstLabel) {
                    var h = '<tr class="sumrow"><td class="left">' + esc(firstLabel) + '</td>';
                    for (var k = 1; k < cols.length; k++) {
                        var fmt = self.formatCell(srcRow[cols[k].key], cols[k].col, true);
                        h += '<td class="' + fmt.cls + '" style="text-align:right;">' + fmt.html + '</td>';
                    }
                    return h + '</tr>';
                };
                bodyHtml += footRow(sumRow, 'SUM (' + rows.length + ' rows)');
                bodyHtml += footRow(avgRow, 'AVG');
            }

            var title = ($('#optee-campaign-title').text() || 'Campaign').trim();
            var subtitle = this.drilldownLabel() + ' report · ' + this.tfLabel();

            var css = 'body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111827;margin:24px;}' +
                'h1{font-size:18px;margin:0 0 2px;}' +
                '.sub{font-size:12px;color:#6b7280;margin:0 0 16px;}' +
                'table{border-collapse:collapse;width:100%;font-size:10px;}' +
                'th,td{border:1px solid #e5e7eb;padding:3px 6px;white-space:nowrap;}' +
                'thead th{background:#f9fafb;font-weight:600;}' +
                'th.grp{background:#eff6ff;text-align:center;color:#1d4ed8;}' +
                'th.left,td.left{text-align:left;}' +
                'tr.sumrow td{background:#f3f4f6;font-weight:600;}' +
                '.optee-cell-green{color:#166534;}.optee-cell-red{color:#b91c1c;}.optee-cell-yellow{color:#a16207;}.optee-cell-empty{color:#9ca3af;}' +
                '@media print{@page{size:landscape;margin:10mm;}}';

            var html = '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title>' +
                '<style>' + css + '</style></head><body>' +
                '<h1>' + esc(title) + '</h1>' +
                '<p class="sub">' + esc(subtitle) + '</p>' +
                '<table><thead><tr class="grouprow">' + groupHtml + '</tr><tr>' + colHtml + '</tr></thead>' +
                '<tbody>' + bodyHtml + '</tbody></table></body></html>';

            var w = window.open('', '_blank');
            if (!w) { Optee.Toast.show('Please allow popups to print.', 'error'); return; }
            w.document.open();
            w.document.write(html);
            w.document.close();
            // Give the new document a tick to lay out before invoking print.
            w.onload = function () { w.focus(); w.print(); };
        },

        /* ----- Suggestion badge for hiding no-click zones ----- */

        updateSuggestionBadge: function () {
            var $el = $('#optee-stats-suggestion');
            if (!$el.length) return;

            // Only show on default drilldown.
            if (this.drilldown !== 'default') {
                $el.html('');
                return;
            }

            var rows = this.data;
            if (!rows.length) {
                $el.html('');
                return;
            }

            var hasClicks = false;
            var noClickCount = 0;
            var totalCount = rows.length;

            for (var i = 0; i < rows.length; i++) {
                var clk = parseFloat(rows[i].clicks) || 0;
                if (clk > 0) hasClicks = true;
                else noClickCount++;
            }

            // Need both: some rows with clicks (push traffic) and some without.
            if (!hasClicks || noClickCount === 0) {
                $el.html('');
                return;
            }

            var lightbulbIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>';

            var checked = this.hideNoClicks ? ' checked' : '';

            var html = '<span class="optee-suggestion-badge">' +
                lightbulbIcon + ' Suggestion</span>';

            html += '<div class="optee-suggestion-popover" style="display:none;">';
            html += '<div style="font-weight:600;font-size:12px;margin-bottom:6px;">Push traffic detected (or similar)</div>';
            html += '<div style="color:#6b7280;line-height:1.5;">This campaign might have push traffic or similar. Some zones have no clicks \u2014 these are likely inactive or dead zones.</div>';
            html += '<div style="margin-top:6px;color:#6b7280;">' + noClickCount + ' of ' + totalCount + ' zones have no clicks</div>';
            html += '<label><input type="checkbox" id="optee-hide-no-clicks"' + checked + '> Hide zones with no clicks</label>';
            html += '</div>';

            $el.html(html);
        },

        /* ----- Traffic source info badge ----- */

        updateTsInfoBadge: function () {
            var $el = $('#optee-stats-ts-info');
            if (!$el.length) return;

            if (!this.modules.traffic_source) {
                $el.html('');
                return;
            }

            var tsName = this.moduleNames.traffic_source || 'Traffic Source';
            var infoIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
            var html = '';

            // BidVertiser API limit info badge.
            if (this.modules.traffic_source === 'bidvertiser') {
                html += '<span style="position:relative;">';
                html += '<span class="optee-suggestion-badge" style="cursor:pointer;">' + infoIcon + ' API Limits</span>';
                html += '<div class="optee-suggestion-popover" style="display:none;">';
                html += '<div style="font-weight:600;font-size:12px;margin-bottom:6px;">BidVertiser API Limits</div>';
                html += '<div style="color:#6b7280;line-height:1.5;">BidVertiser limits API reports to <b>1 request per hour</b> per report type per campaign. Daily breakdown is not available via API.</div>';
                html += '<div style="color:#6b7280;line-height:1.5;margin-top:6px;"><b>Tip:</b> Use <b>Tracker</b> columns for rule conditions and daily analysis. BidVertiser columns are best used for zone-level bid requests and cost overview.</div>';
                html += '</div></span>';
            }

            // Check if any merged row has a non-zero payout — if so, the TS supports payout.
            var rows = this.data || [];
            var hasPayout = false;
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].hasOwnProperty('payout')) {
                    hasPayout = true;
                    break;
                }
            }
            if (!hasPayout) {
                html += '<span style="position:relative;">';
                html += '<span class="optee-suggestion-badge">' + infoIcon + ' Payout N/A</span>';
                html += '<div class="optee-suggestion-popover" style="display:none;">';
                html += '<div style="font-weight:600;font-size:12px;margin-bottom:6px;">Why are Payout, Profit & ROI zero?</div>';
                html += '<div style="color:#6b7280;line-height:1.5;">' + Optee.Util.escapeHtml(tsName) + ' API does not provide conversion payout data, so these fields cannot be calculated on the traffic source side.</div>';
                html += '<div style="color:#6b7280;line-height:1.5;margin-top:6px;">Use the <b>Tracker</b> columns (Revenue, Profit, ROI) for actual earnings data.</div>';
                html += '</div></span>';
            }

            $el.html(html || '');
        },

        /* ----- Cache indicator badge + popover ----- */

        updateCacheIndicator: function (cacheStatus, cacheTtl) {
            var $el = $('#optee-cache-indicator');
            if (!$el.length) return;

            var liveIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20h.01"/><path d="M7 20v-4"/><path d="M12 20v-8"/><path d="M17 20V8"/><path d="M22 4v16"/></svg>';
            var cachedIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';

            var slugs = Object.keys(cacheStatus);
            if ((!slugs.length || cacheTtl === 0) && !this.alwaysLive) {
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

            if (allLive && !this.alwaysLive) {
                $el.html('<span class="optee-badge-green optee-cache-icon-badge">' + liveIcon + ' Live data</span>');
                return;
            }

            // When alwaysLive is on, show live badge but make it clickable with popover.
            if (allLive && this.alwaysLive) {
                var alwaysChecked = ' checked';
                var html = '<span class="optee-cache-badge optee-badge-green optee-cache-icon-badge" style="cursor:pointer;">' +
                    liveIcon + ' Live data</span>';
                html += '<div id="optee-cache-popover" class="optee-cache-popover" style="display:none;">';
                html += '<label style="display:block;font-size:11px;color:#6b7280;cursor:pointer;"><input type="checkbox" id="optee-always-live"' + alwaysChecked + ' style="margin-right:4px;"> Always load live data</label>';
                html += this.autoReloadHtml();
                html += '</div>';
                $el.html(html);
                this.paintAutoReload();
                return;
            }

            var label = slugs.length === 1 ? 'Cached data' : (anyCached && !allLive ? 'Partially cached' : 'Cached data');
            var html = '<span class="optee-cache-badge optee-badge-yellow optee-cache-icon-badge" style="cursor:pointer;">' +
                cachedIcon + ' ' + Optee.Util.escapeHtml(label) + '</span>';

            // Popover with module details.
            html += '<div id="optee-cache-popover" class="optee-cache-popover" style="display:none;">';
            html += '<div style="font-weight:600;font-size:12px;margin-bottom:6px;">Cache status (TTL: ' + cacheTtl + ' min)</div>';
            for (var j = 0; j < slugs.length; j++) {
                var s = cacheStatus[slugs[j]];
                var dotColor = s.cached ? '#eab308' : '#22c55e';
                var statusText = s.cached ? 'Cached' : 'Live';
                html += '<div class="optee-cache-detail">' +
                    '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + dotColor + ';margin-right:6px;"></span>' +
                    Optee.Util.escapeHtml(s.module_name) +
                    '<span style="margin-left:auto;color:#6b7280;font-size:11px;">' + statusText + '</span>' +
                    '</div>';
            }
            html += '<a href="#" id="optee-clear-cache" style="display:block;margin-top:8px;font-size:11px;color:#2aa5ff;text-decoration:none;">Clear cache &amp; reload</a>';
            var alwaysChecked = this.alwaysLive ? ' checked' : '';
            html += '<label style="display:block;margin-top:6px;font-size:11px;color:#6b7280;cursor:pointer;"><input type="checkbox" id="optee-always-live"' + alwaysChecked + ' style="margin-right:4px;"> Always load live data</label>';
            html += this.autoReloadHtml();
            html += '</div>';

            $el.html(html);
            this.paintAutoReload();
        },

        updateCampaignStatus: function (status) {
            var $el = $('#optee-campaign-status');
            if (!$el.length || !status || status === 'unknown') {
                $el.html('');
                this.updateLocalStatusBadge();
                return;
            }

            this.campaignStatus = status;

            var playIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>';
            var pauseIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg>';

            var clockIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';

            var badge;
            if (status === 'pending') {
                badge = '<span class="optee-badge-yellow optee-cache-icon-badge" title="Campaign is pending editorial review">' + clockIcon + ' Pending Review</span>';
                $el.html(badge);
                this.updateLocalStatusBadge();
                return;
            }

            if (status === 'declined') {
                var xIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
                badge = '<span class="optee-badge-red optee-cache-icon-badge" title="Campaign was declined by editorial review">' + xIcon + ' Declined</span>';
                $el.html(badge);
                this.updateLocalStatusBadge();
                return;
            }

            if (status === 'capped') {
                var checkIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
                badge = '<span class="optee-badge-blue optee-cache-icon-badge" title="Daily cap reached. Campaign will resume after the next daily reset.">' + checkIcon + ' Capped</span>';
                $el.html(badge);
                this.updateLocalStatusBadge();
                return;
            }

            if (status === 'active') {
                badge = '<span class="optee-badge-green optee-cache-icon-badge optee-campaign-status-badge" style="cursor:pointer;">' + playIcon + ' Active</span>';
            } else {
                badge = '<span class="optee-badge-red optee-cache-icon-badge optee-campaign-status-badge" style="cursor:pointer;">' + pauseIcon + ' Paused</span>';
            }

            // Remove any existing modal.
            $('#optee-campaign-status-modal').remove();

            // Build centered modal (appended to body).
            var tsName = this.moduleNames.traffic_source || 'traffic source';
            var isActive = status === 'active';
            var title = isActive ? 'Pause campaign on ' + tsName + '?' : 'Start campaign on ' + tsName + '?';
            var desc = isActive
                ? 'This will pause the campaign on ' + tsName + '. No more traffic will be sent.'
                : 'This will resume the campaign on ' + tsName + '. Traffic will start flowing again.';
            var btnIcon = isActive ? pauseIcon : playIcon;
            var btnLabel = isActive ? 'Pause Campaign' : 'Start Campaign';

            var modal = '<div id="optee-campaign-status-modal" style="display:none;position:fixed;inset:0;z-index:9999;display:none;">' +
                '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);" id="optee-campaign-status-backdrop"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:340px;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button id="optee-campaign-status-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                '<div style="font-weight:600;font-size:14px;margin-bottom:8px;">' + title + '</div>' +
                '<div style="color:#6b7280;font-size:13px;line-height:1.5;margin-bottom:16px;">' + desc + '</div>' +
                '<div style="display:flex;gap:8px;">' +
                '<button id="optee-campaign-status-confirm" class="optee-btn-primary optee-btn-sm" style="flex:1;">' + btnIcon + ' ' + btnLabel + '</button>' +
                '<button id="optee-campaign-status-cancel" class="optee-btn-secondary optee-btn-sm" style="flex:1;">Cancel</button>' +
                '</div></div></div>';

            $('body').append(modal);
            $el.html(badge);
            this.updateLocalStatusBadge();
        },

        updateWhitelistBadge: function () {
            var $container = $('#optee-whitelist-badge');
            if (!this.isWhitelistCampaign) {
                $container.remove();
                return;
            }
            if (!$container.length) {
                // Insert after campaign status badge area.
                var $statusEl = $('#optee-campaign-status');
                if ($statusEl.length) {
                    $statusEl.after('<span id="optee-whitelist-badge"></span>');
                    $container = $('#optee-whitelist-badge');
                } else {
                    return;
                }
            }
            var checkIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
            $container.html(
                '<span class="optee-badge optee-cache-icon-badge" style="border:1px solid #d1d5db;background:#fff;color:#6b7280;">' +
                checkIcon + ' Whitelist campaign</span>'
            );
        },

        updateLocalStatusBadge: function () {
            var $el = $('#optee-local-status-badge');
            var $title = $('#optee-campaign-stats').closest('.space-y-4').find('h2').first();
            var localStatus = $('#optee-campaign-stats').data('local-status') || 'active';
            if (localStatus === 'paused') {
                var pauseIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg>';
                if (!$el.length) {
                    $('#optee-campaign-status').after('<span id="optee-local-status-badge" class="optee-badge-red optee-cache-icon-badge" title="This campaign is paused locally and excluded from cron jobs and rules">' + pauseIcon + ' Local campaign paused</span>');
                } else {
                    $el.show();
                }
                $title.css('color', '#9ca3af');
            } else {
                $el.hide();
                $title.css('color', '');
            }
        },

        updateSortArrows: function () {
            var self = this;
            $('#optee-stats-table .optee-sort-arrow').each(function () {
                var key = $(this).parent().data('sort');
                if (key === self.sortKey) {
                    $(this).text(self.sortDir === 'asc' ? '\u2191' : '\u2193').css('color', '#374151');
                } else {
                    $(this).text('\u21C5').css('color', '#d1d5db');
                }
            });
        },

        renameCampaign: function () {
            var self = this;
            var current = $('#optee-campaign-stats').closest('.space-y-4').find('h2').first().text().trim();
            var newTitle = prompt('Enter new campaign title:', current);
            if (!newTitle || newTitle.trim() === '' || newTitle.trim() === current) return;
            newTitle = newTitle.trim();

            Optee.Ajax.post('optee_update_campaign', {
                campaign_id: self.campaignId,
                title: newTitle
            }).done(function (res) {
                if (res.success) {
                    $('#optee-campaign-stats').closest('.space-y-4').find('h2').first().text(newTitle);
                    Optee.Toast.show('Campaign title updated', 'success');
                } else {
                    Optee.Toast.show(res.data.message || 'Failed to update title', 'error');
                }
            }).fail(function () {
                Optee.Toast.show('Request failed', 'error');
            });
        },

        toggleLocalStatus: function () {
            var self = this;
            var $el = $('#optee-campaign-stats');
            var current = $el.data('local-status') || 'active';
            var newStatus = current === 'active' ? 'paused' : 'active';
            var msg = current === 'active'
                ? 'Pause this campaign locally? It will be excluded from cron jobs and rules.'
                : 'Activate this campaign locally? It will be included in cron jobs and rules.';

            if (!confirm(msg)) return;

            Optee.Ajax.post('optee_update_campaign', {
                campaign_id: self.campaignId,
                status: newStatus
            }).done(function (res) {
                if (res.success) {
                    $el.data('local-status', newStatus);
                    self.updateLocalStatusBadge();
                    Optee.Toast.show('Local status set to ' + newStatus, 'success');
                } else {
                    Optee.Toast.show(res.data.message || 'Failed to update status', 'error');
                }
            }).fail(function () {
                Optee.Toast.show('Request failed', 'error');
            });
        },

        removeFromDashboard: function () {
            var self = this;
            if (!confirm('Remove this campaign from the dashboard? You can restore it from the Campaigns page.')) return;

            Optee.Ajax.post('optee_update_campaign', {
                campaign_id: self.campaignId,
                status: 'archived'
            }).done(function (res) {
                if (res.success) {
                    Optee.Toast.show('Campaign removed from dashboard', 'success');
                    window.location.href = opteeData.dashboardUrl + '?module=campaigns';
                } else {
                    Optee.Toast.show(res.data.message || 'Failed to remove campaign', 'error');
                }
            }).fail(function () {
                Optee.Toast.show('Request failed', 'error');
            });
        },

        /* ----- Monetag source modal ----- */

        openMonetagModal: function () {
            var self = this;
            var $modal = $('#optee-monetag-modal');
            var $zone = $('#optee-monetag-zone');
            var $country = $('#optee-monetag-country');

            // Populate country dropdown (ISO 3166 Alpha-2, full list).
            var countryHtml = '<option value="">All Countries</option>';
            var countries = MONETAG_COUNTRIES;
            for (var i = 0; i < countries.length; i++) {
                var c = countries[i];
                var sel = self.monetagCountry === c[0] ? ' selected' : '';
                countryHtml += '<option value="' + c[0] + '"' + sel + '>' + Optee.Util.escapeHtml(c[1] + ' (' + c[0] + ')') + '</option>';
            }
            $country.html(countryHtml);

            // Load zones via AJAX.
            $zone.html('<option value="">Loading zones...</option>');
            $modal.fadeIn(150);

            Optee.Ajax.post('optee_get_monetag_zones', {}).done(function (res) {
                if (res.success && res.data.zones) {
                    var html = '<option value="">— Select Zone —</option>';
                    $.each(res.data.zones, function (i, z) {
                        var zId = z.id || z.zone_id || '';
                        var zTitle = z.title || z.name || ('Zone ' + zId);
                        var sel = self.monetagZoneId && String(self.monetagZoneId) === String(zId) ? ' selected' : '';
                        html += '<option value="' + Optee.Util.escapeHtml(zId + '') + '"' + sel + '>' +
                            Optee.Util.escapeHtml(zTitle + ' (#' + zId + ')') + '</option>';
                    });
                    $zone.html(html);
                } else {
                    $zone.html('<option value="">No zones found</option>');
                    Optee.Toast.show((res.data && res.data.message) || 'Failed to load zones', 'error');
                }
            }).fail(function () {
                $zone.html('<option value="">Failed to load zones</option>');
                Optee.Toast.show('Failed to load Monetag zones', 'error');
            });
        },

        /* ----- ProPush source modal ----- */

        openPartnersHouseModal: function () {
            var self = this;
            var $modal = $('#optee-ph-modal');
            var $country = $('#optee-ph-country');

            // Populate country dropdown.
            var countryHtml = '<option value="">All Countries</option>';
            var countries = MONETAG_COUNTRIES;
            for (var i = 0; i < countries.length; i++) {
                var c = countries[i];
                var sel = self.phCountry === c[0] ? ' selected' : '';
                countryHtml += '<option value="' + c[0] + '"' + sel + '>' + Optee.Util.escapeHtml(c[1] + ' (' + c[0] + ')') + '</option>';
            }
            $country.html(countryHtml);

            // Restore sub values.
            $('#optee-ph-sub1').val(self.phSub1 || '');
            $('#optee-ph-sub2').val(self.phSub2 || '');
            $('#optee-ph-sub3').val(self.phSub3 || '');
            $('#optee-ph-sub4').val(self.phSub4 || '');

            $modal.fadeIn(150);
        },

        /* ----- RollerAds Publisher source modal ----- */

        openRapModal: function () {
            var self = this;
            var $modal = $('#optee-rap-modal');
            var $site = $('#optee-rap-site');
            var $zone = $('#optee-rap-zone');

            // Reset zone dropdown.
            $zone.html('<option value="">All Zones</option>');

            // Load sites via AJAX.
            $site.html('<option value="">Loading sites...</option>');
            $modal.fadeIn(150);

            Optee.Ajax.post('optee_get_rap_sites', {}).done(function (res) {
                if (res.success && res.data.sites) {
                    var html = '<option value="">\u2014 Select Site \u2014</option>';
                    $.each(res.data.sites, function (i, s) {
                        var sId = s.site_id || '';
                        var sUrl = s.site_url || ('Site ' + sId);
                        var sel = self.rapSiteId && String(self.rapSiteId) === String(sId) ? ' selected' : '';
                        html += '<option value="' + Optee.Util.escapeHtml(sId + '') + '"' + sel + '>' +
                            Optee.Util.escapeHtml(sUrl + ' (#' + sId + ')') + '</option>';
                    });
                    $site.html(html);
                    // If a site is already selected, load its zones.
                    if (self.rapSiteId) {
                        self.loadRapZones(self.rapSiteId);
                    }
                } else {
                    $site.html('<option value="">No sites found</option>');
                    Optee.Toast.show((res.data && res.data.message) || 'Failed to load sites', 'error');
                }
            }).fail(function () {
                $site.html('<option value="">Failed to load sites</option>');
                Optee.Toast.show('Failed to load RollerAds Publisher sites', 'error');
            });

            // Load zones when site changes.
            $site.off('change.rap').on('change.rap', function () {
                var siteId = $(this).val();
                $zone.html('<option value="">All Zones</option>');
                if (siteId) {
                    self.loadRapZones(siteId);
                }
            });
        },

        loadRapZones: function (siteId) {
            var self = this;
            var $zone = $('#optee-rap-zone');
            $zone.html('<option value="">Loading zones...</option>');

            Optee.Ajax.post('optee_get_rap_zones', { site_id: siteId }).done(function (res) {
                if (res.success && res.data.zones) {
                    var html = '<option value="">All Zones</option>';
                    $.each(res.data.zones, function (i, z) {
                        var zId = z.zone_id || '';
                        var zTitle = z.zone_title || ('Zone ' + zId);
                        var sel = self.rapZoneId && String(self.rapZoneId) === String(zId) ? ' selected' : '';
                        html += '<option value="' + Optee.Util.escapeHtml(zId + '') + '"' + sel + '>' +
                            Optee.Util.escapeHtml(zTitle + ' (#' + zId + ')') + '</option>';
                    });
                    $zone.html(html);
                } else {
                    $zone.html('<option value="">No zones found</option>');
                }
            }).fail(function () {
                $zone.html('<option value="">Failed to load zones</option>');
            });
        },

        /* ----- HilltopAds Publisher source modal ----- */

        openHtaModal: function () {
            var self = this;
            var $modal = $('#optee-hta-modal');
            var $site = $('#optee-hta-site');
            var $zone = $('#optee-hta-zone');

            // Reset zone dropdown.
            $zone.html('<option value="">All Zones</option>');

            // Load sites via AJAX.
            $site.html('<option value="">Loading sites...</option>');
            $modal.fadeIn(150);

            Optee.Ajax.post('optee_get_hta_sites', {}).done(function (res) {
                if (res.success && res.data.sites) {
                    var html = '<option value="">\u2014 Select Site \u2014</option>';
                    $.each(res.data.sites, function (i, s) {
                        var sId = s.site_id || '';
                        var sUrl = s.site_url || ('Site ' + sId);
                        var sel = self.htaSiteId && String(self.htaSiteId) === String(sId) ? ' selected' : '';
                        html += '<option value="' + Optee.Util.escapeHtml(sId + '') + '"' + sel + '>' +
                            Optee.Util.escapeHtml(sUrl + ' (#' + sId + ')') + '</option>';
                    });
                    $site.html(html);
                    // If a site is already selected, load its zones.
                    if (self.htaSiteId) {
                        self.loadHtaZones(self.htaSiteId);
                    }
                } else {
                    $site.html('<option value="">No sites found</option>');
                    Optee.Toast.show((res.data && res.data.message) || 'Failed to load sites', 'error');
                }
            }).fail(function () {
                $site.html('<option value="">Failed to load sites</option>');
                Optee.Toast.show('Failed to load HilltopAds Publisher sites', 'error');
            });

            // Load zones when site changes.
            $site.off('change.hta').on('change.hta', function () {
                var siteId = $(this).val();
                $zone.html('<option value="">All Zones</option>');
                if (siteId) {
                    self.loadHtaZones(siteId);
                }
            });
        },

        loadHtaZones: function (siteId) {
            var self = this;
            var $zone = $('#optee-hta-zone');
            $zone.html('<option value="">Loading zones...</option>');

            Optee.Ajax.post('optee_get_hta_zones', { site_id: siteId }).done(function (res) {
                if (res.success && res.data.zones) {
                    var html = '<option value="">All Zones</option>';
                    $.each(res.data.zones, function (i, z) {
                        var zId = z.zone_id || '';
                        var zTitle = z.zone_title || ('Zone ' + zId);
                        var sel = self.htaZoneId && String(self.htaZoneId) === String(zId) ? ' selected' : '';
                        html += '<option value="' + Optee.Util.escapeHtml(zId + '') + '"' + sel + '>' +
                            Optee.Util.escapeHtml(zTitle + ' (#' + zId + ')') + '</option>';
                    });
                    $zone.html(html);
                } else {
                    $zone.html('<option value="">No zones found</option>');
                }
            }).fail(function () {
                $zone.html('<option value="">Failed to load zones</option>');
            });
        },

        /* ----- Monetizer source modal ----- */

        openMonetizerMonModal: function () {
            var self = this;
            var $modal = $('#optee-mtzr-modal');
            var $league = $('#optee-mtzr-league');
            var $campaign = $('#optee-mtzr-campaign');

            // Reset campaign dropdown.
            $campaign.html('<option value="">All Campaigns</option>');

            // Load leagues via AJAX.
            $league.html('<option value="">Loading leagues...</option>');
            $modal.fadeIn(150);

            Optee.Ajax.post('optee_get_monetizer_mon_leagues', {}).done(function (res) {
                if (res.success && res.data.leagues) {
                    var html = '<option value="">\u2014 Select League \u2014</option>';
                    $.each(res.data.leagues, function (i, l) {
                        var lId = l.nid || '';
                        var lName = l.name || ('League ' + lId);
                        var sel = self.mtzrLeagueId && String(self.mtzrLeagueId) === String(lId) ? ' selected' : '';
                        html += '<option value="' + Optee.Util.escapeHtml(lId + '') + '"' + sel + '>' +
                            Optee.Util.escapeHtml(lName + ' (#' + lId + ')') + '</option>';
                    });
                    $league.html(html);
                    // If a league is already selected, load its campaigns.
                    if (self.mtzrLeagueId) {
                        self.loadMonetizerCampaigns(self.mtzrLeagueId);
                    }
                } else {
                    $league.html('<option value="">No leagues found</option>');
                    Optee.Toast.show((res.data && res.data.message) || 'Failed to load leagues', 'error');
                }
            }).fail(function () {
                $league.html('<option value="">Failed to load leagues</option>');
                Optee.Toast.show('Failed to load Monetizer leagues', 'error');
            });

            // Load campaigns when league changes.
            $league.off('change.mtzr').on('change.mtzr', function () {
                var leagueId = $(this).val();
                $campaign.html('<option value="">All Campaigns</option>');
                if (leagueId) {
                    self.loadMonetizerCampaigns(leagueId);
                }
            });
        },

        loadMonetizerCampaigns: function (leagueId) {
            var self = this;
            var $campaign = $('#optee-mtzr-campaign');
            $campaign.html('<option value="">Loading campaigns...</option>');

            Optee.Ajax.post('optee_get_monetizer_mon_campaigns', { league_id: leagueId }).done(function (res) {
                if (res.success && res.data.campaigns) {
                    var html = '<option value="">All Campaigns</option>';
                    $.each(res.data.campaigns, function (i, c) {
                        var kw = c.keyword || '';
                        var sel = self.mtzrCampaignKeyword && self.mtzrCampaignKeyword === kw ? ' selected' : '';
                        html += '<option value="' + Optee.Util.escapeHtml(kw) + '"' + sel + '>' +
                            Optee.Util.escapeHtml(kw) + '</option>';
                    });
                    $campaign.html(html);
                } else {
                    $campaign.html('<option value="">No campaigns found</option>');
                }
            }).fail(function () {
                $campaign.html('<option value="">Failed to load campaigns</option>');
            });
        },

        /* ----- Zeydoo source modal ----- */

        openZeydooMonModal: function () {
            var self = this;
            var $modal = $('#optee-zd-modal');

            // Restore saved value.
            $('#optee-zd-request').val(self.zdRequestVar || '');

            $modal.fadeIn(150);
        },

        /* ----- ProPush source modal ----- */

        // Parse a stored comma-separated id list into a lookup set of strings.
        propushIdSet: function (csv) {
            var set = {};
            String(csv || '').split(',').forEach(function (v) {
                v = v.trim();
                if (v) { set[v] = true; }
            });
            return set;
        },

        // Render a checkbox list item for the ProPush zone/site pickers.
        propushCheckItem: function (id, label, checked) {
            var idStr = String(id);
            var text = label + ' (#' + idStr + ')';
            return '<label class="optee-pp-check" data-search="' + Optee.Util.escapeHtml((label + ' ' + idStr).toLowerCase()) + '" ' +
                'style="display:flex;align-items:center;gap:8px;padding:3px 2px;cursor:pointer;">' +
                '<input type="checkbox" value="' + Optee.Util.escapeHtml(idStr) + '"' + (checked ? ' checked' : '') + '> ' +
                '<span>' + Optee.Util.escapeHtml(text) + '</span></label>';
        },

        openPropushModal: function () {
            var self = this;
            var $modal = $('#optee-propush-modal');
            var $zoneList = $('#optee-propush-zone-list');
            var $siteList = $('#optee-propush-site-list');
            var $country = $('#optee-propush-country');
            var $sourceId = $('#optee-propush-source-id');

            // Populate country dropdown (same ISO 3166 list).
            var countryHtml = '<option value="">All Countries</option>';
            var countries = MONETAG_COUNTRIES;
            for (var i = 0; i < countries.length; i++) {
                var c = countries[i];
                var sel = self.propushCountry === c[0] ? ' selected' : '';
                countryHtml += '<option value="' + c[0] + '"' + sel + '>' + Optee.Util.escapeHtml(c[1] + ' (' + c[0] + ')') + '</option>';
            }
            $country.html(countryHtml);

            // Restore source ID + reset search boxes.
            $sourceId.val(self.propushSourceId || '');
            $('#optee-propush-zone-search, #optee-propush-site-search').val('');

            // Loading placeholders.
            $zoneList.html('<div style="color:#9ca3af;padding:4px;">Loading zones...</div>');
            $siteList.html('<div style="color:#9ca3af;padding:4px;">Loading sites...</div>');
            $modal.fadeIn(150);

            // Load zones via AJAX (multi-select checkboxes).
            var zoneSel = self.propushIdSet(self.propushZoneId);
            Optee.Ajax.post('optee_get_propush_zones', {}).done(function (res) {
                if (res.success && res.data.zones && res.data.zones.length) {
                    var html = '';
                    $.each(res.data.zones, function (i, z) {
                        var zId = z.id || z.zone_id || '';
                        var zTitle = z.title || z.name || ('Zone ' + zId);
                        html += self.propushCheckItem(zId, zTitle, !!zoneSel[String(zId)]);
                    });
                    $zoneList.html(html);
                } else {
                    $zoneList.html('<div style="color:#9ca3af;padding:4px;">No zones found</div>');
                    if (!(res.success)) { Optee.Toast.show((res.data && res.data.message) || 'Failed to load zones', 'error'); }
                }
            }).fail(function () {
                $zoneList.html('<div style="color:#9ca3af;padding:4px;">Failed to load zones</div>');
                Optee.Toast.show('Failed to load ProPush zones', 'error');
            });

            // Load sites via AJAX (multi-select checkboxes).
            var siteSel = self.propushIdSet(self.propushSiteId);
            Optee.Ajax.post('optee_get_propush_sites', {}).done(function (res) {
                if (res.success && res.data.sites && res.data.sites.length) {
                    var html = '';
                    $.each(res.data.sites, function (i, s) {
                        var sId = s.id || '';
                        var sLabel = s.label || s.url || s.name || ('Site ' + sId);
                        html += self.propushCheckItem(sId, sLabel, !!siteSel[String(sId)]);
                    });
                    $siteList.html(html);
                } else {
                    $siteList.html('<div style="color:#9ca3af;padding:4px;">No sites found</div>');
                }
            }).fail(function () {
                $siteList.html('<div style="color:#9ca3af;padding:4px;">Failed to load sites</div>');
            });
        },

        /* =====================================================================
           Zone-cell context menu (right-click on zone name).
           ===================================================================== */

        /**
         * Re-read note counts and repaint the dots. Called by the notes modal after an
         * add or delete so the table reflects the change without a full stats reload
         * (which would re-hit the tracker API).
         */
        refreshNoteCounts: function () {
            var self = this;
            if (!this.campaignId) return;
            Optee.Ajax.post('optee_get_notes', { campaign_id: this.campaignId }).done(function (res) {
                if (!res || !res.success) return;
                var counts = {};
                var groups = res.data.groups || [];
                for (var i = 0; i < groups.length; i++) {
                    if (groups[i].dim === 'zone') {
                        counts[String(groups[i].value)] = groups[i].notes.length;
                    }
                }
                self.noteCounts = counts;
                if (self.lastRenderedRows) self.renderRows(self.lastRenderedRows);
            });
        },

        openZoneContextMenu: function (x, y, zoneId, $toggle) {
            $('#optee-zone-ctxmenu').remove();
            var self = this;

            var isBlocked = $toggle.length && !$toggle.hasClass('is-on');
            var canToggle = $toggle.length > 0;
            var blockLabel = isBlocked ? 'Unblock zone' : 'Block zone';
            var blockIcon  = isBlocked
                ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
            var copyIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';

            var html = '<div id="optee-zone-ctxmenu" style="position:fixed;z-index:10000;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.12);padding:6px;min-width:180px;font-size:13px;">' +
                '<div style="padding:6px 10px;color:#9ca3af;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid #f3f4f6;margin-bottom:4px;">Zone ' + Optee.Util.escapeHtml(zoneId) + '</div>';
            // Drilldown — BeMob only (uses BeMob's zone filter to scope every report to this zone).
            if (self.modules.tracker === 'bemob') {
                var drillIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/></svg>';
                html += '<button class="optee-ctx-item" data-act="drilldown" style="display:flex;align-items:center;gap:8px;width:100%;padding:8px 10px;border:none;background:none;cursor:pointer;text-align:left;border-radius:5px;color:#2563eb;font-weight:500;">' + drillIcon + ' Drilldown into zone</button>';
            }
            if (canToggle) {
                html += '<button class="optee-ctx-item" data-act="toggle" style="display:flex;align-items:center;gap:8px;width:100%;padding:8px 10px;border:none;background:none;cursor:pointer;text-align:left;border-radius:5px;color:' + (isBlocked ? '#15803d' : '#dc2626') + ';">' + blockIcon + ' ' + blockLabel + '</button>';
            }
            var noteIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>';
            var noteN = (this.noteCounts && this.noteCounts[zoneId]) || 0;
            html += '<button class="optee-ctx-item" data-act="notes" style="display:flex;align-items:center;gap:8px;width:100%;padding:8px 10px;border:none;background:none;cursor:pointer;text-align:left;border-radius:5px;color:#374151;">' + noteIcon + ' Notes' + (noteN ? ' (' + noteN + ')' : '') + '</button>';
            html += '<button class="optee-ctx-item" data-act="copy" style="display:flex;align-items:center;gap:8px;width:100%;padding:8px 10px;border:none;background:none;cursor:pointer;text-align:left;border-radius:5px;color:#374151;">' + copyIcon + ' Copy zone ID</button>';

            // Items contributed by other plugins (see Report extension points).
            var extraItems = [];
            var providers = (window.Optee && Optee.Report && Optee.Report.zoneMenuProviders) || [];
            for (var pi = 0; pi < providers.length; pi++) {
                var got;
                try {
                    got = providers[pi]({
                        value:      zoneId,
                        dimension:  'zone',
                        drilldown:  this.drilldown,
                        campaignId: this.campaignId,
                        modules:    this.modules
                    });
                } catch (e) {
                    continue;   // a broken provider must never break the menu
                }
                if (!got) continue;
                var list = $.isArray(got) ? got : [ got ];
                for (var li2 = 0; li2 < list.length; li2++) {
                    var it = list[li2];
                    if (!it || !it.label) continue;
                    var idx = extraItems.push(it) - 1;
                    html += '<button class="optee-ctx-item" data-ext="' + idx + '" style="display:flex;align-items:center;gap:8px;' +
                        'width:100%;padding:8px 10px;border:none;background:none;cursor:pointer;text-align:left;border-radius:5px;' +
                        'color:' + (it.color || '#374151') + ';' + (it.color ? 'font-weight:500;' : '') + '">' +
                        (it.icon || '') + ' ' + Optee.Util.escapeHtml(it.label) + '</button>';
                }
            }
            html += '</div>';

            $('body').append(html);
            var $menu = $('#optee-zone-ctxmenu');

            // Position: clamp to viewport so we don't overflow on right-edge clicks.
            var mw = $menu.outerWidth(), mh = $menu.outerHeight();
            var vw = window.innerWidth, vh = window.innerHeight;
            var left = (x + mw + 8 > vw) ? Math.max(8, vw - mw - 8) : x;
            var top  = (y + mh + 8 > vh) ? Math.max(8, vh - mh - 8) : y;
            $menu.css({ left: left, top: top });

            $menu.find('.optee-ctx-item').on('mouseenter', function () { $(this).css('background', '#f3f4f6'); })
                .on('mouseleave', function () { $(this).css('background', 'none'); });

            $menu.find('[data-act="toggle"]').on('click', function () {
                $menu.remove();
                $toggle.trigger('click');
            });
            $menu.find('[data-act="notes"]').on('click', function () {
                $menu.remove();
                if (window.OpteeNotes && window.OpteeNotes.open) {
                    window.OpteeNotes.open('zone', zoneId);
                }
            });
            $menu.find('[data-act="copy"]').on('click', function () {
                $menu.remove();
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(zoneId).then(function () {
                        Optee.Toast.show('Zone ID copied: ' + zoneId, 'success');
                    }, function () {
                        Optee.Toast.show('Failed to copy.', 'error');
                    });
                } else {
                    // Legacy fallback.
                    var ta = document.createElement('textarea');
                    ta.value = zoneId;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); Optee.Toast.show('Zone ID copied: ' + zoneId, 'success'); }
                    catch (err) { Optee.Toast.show('Failed to copy.', 'error'); }
                    document.body.removeChild(ta);
                }
            });
            $menu.find('[data-ext]').on('click', function () {
                var it = extraItems[parseInt($(this).data('ext'), 10)];
                if (!it.keepOpen) { $menu.remove(); }
                if (typeof it.onClick === 'function') {
                    try { it.onClick(zoneId); } catch (e) { /* provider's problem */ }
                }
            });
            $menu.find('[data-act="drilldown"]').on('click', function () {
                $menu.remove();
                self.zoneFilter  = String(zoneId);
                self.drilldown   = 'days';   // auto-open the Days report, scoped to this zone
                self.userSorted  = false;
                self.renderDrilldowns();
                self.buildActiveGroups();
                self.renderTableHeader();
                self.renderZoneFilterChip();
                self.pushUrl();
                self.loadData();
            });

            // Close on any outside click, scroll, or Escape.
            var closeMenu = function () { $('#optee-zone-ctxmenu').remove(); cleanup(); };
            var cleanup = function () {
                $(document).off('click.zonectx scroll.zonectx contextmenu.zonectx keydown.zonectx');
                $('#optee-stats-wrapper').off('scroll.zonectx');
            };
            setTimeout(function () {
                $(document).on('click.zonectx', function (e) {
                    if (!$(e.target).closest('#optee-zone-ctxmenu').length) closeMenu();
                });
                $(document).on('contextmenu.zonectx', function (e) {
                    if (!$(e.target).closest('#optee-stats-table tbody td.optee-sticky-col').length) closeMenu();
                });
                $(document).on('keydown.zonectx', function (e) { if (e.key === 'Escape') closeMenu(); });
                $('#optee-stats-wrapper').on('scroll.zonectx', closeMenu);
            }, 0);
        },

        /* ----- Zone drilldown breadcrumb ----- */

        renderZoneFilterChip: function () {
            var $bar = $('#optee-zone-drill-bar');
            if (!$bar.length) return;
            if (!this.zoneFilter) {
                $bar.hide().empty();
                return;
            }
            var icon = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/></svg>';
            $bar.html(
                icon +
                '<span style="font-weight:600;color:#1e40af;">Drilldown</span>' +
                '<span style="color:#93c5fd;">→</span>' +
                '<span style="color:#374151;">Zone: <b style="color:#111827;">' + Optee.Util.escapeHtml(this.zoneFilter) + '</b></span>' +
                '<button id="optee-zone-drill-clear" type="button" title="Exit zone drilldown" style="display:inline-flex;align-items:center;gap:4px;background:none;border:none;cursor:pointer;color:#6b7280;font-size:12px;padding:2px 6px;border-radius:5px;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Exit' +
                '</button>'
            ).css('display', 'flex');
        },

        clearZoneFilter: function () {
            this.zoneFilter = '';
            this.drilldown  = 'default';   // back to the full zone list
            this.userSorted = false;
            this.renderDrilldowns();
            this.buildActiveGroups();
            this.renderTableHeader();
            this.renderZoneFilterChip();
            this.pushUrl();
            this.loadData();
        },

        /* =====================================================================
           Custom Columns — settings modal + new/edit form.
           ===================================================================== */

        openColumnsModal: function () {
            var self = this;
            // Effective defs (global library with this campaign's overrides applied).
            var cols = self.effectiveDefs();

            // Which library columns are assigned to the campaign we're viewing.
            var enabledList = (typeof opteeData !== 'undefined' && opteeData.customColumnsEnabled) || [];
            var enabledSet = {};
            for (var es = 0; es < enabledList.length; es++) enabledSet[enabledList[es]] = true;

            // "Hide unused" preference — when on, only ticked (shown) columns are listed.
            var hideUnused = false;
            try { hideUnused = localStorage.getItem('optee_cc_hide_unused') === '1'; } catch (e) {}

            // Build templates section (excludes templates that are already saved by name, case-insensitive).
            var savedNames = {};
            for (var sn = 0; sn < cols.length; sn++) savedNames[ cols[sn].name.toLowerCase() ] = true;
            var availTemplates = CC_TEMPLATES.filter(function (t) { return !savedNames[t.name.toLowerCase()]; });

            function buildTemplatesHtml(showHeading) {
                if (!availTemplates.length) return '';
                var h = '';
                if (showHeading) {
                    h += '<div style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;margin:14px 0 8px;padding-top:14px;border-top:1px solid #f3f4f6;">Recommended templates</div>';
                }
                h += '<div style="display:flex;flex-direction:column;gap:6px;">';
                for (var ti = 0; ti < availTemplates.length; ti++) {
                    var t = availTemplates[ti];
                    h += '<div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;background:#fafafa;">' +
                        '<div style="flex:1;min-width:0;">' +
                            '<div style="font-weight:500;color:#111827;font-size:13px;">' + Optee.Util.escapeHtml(t.name) + '</div>' +
                            '<div style="color:#6b7280;font-family:ui-monospace,monospace;font-size:11px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + Optee.Util.escapeHtml(t.formula) + '">' + Optee.Util.escapeHtml(t.formula) + '</div>' +
                        '</div>' +
                        '<button class="optee-btn-secondary optee-btn-sm optee-cc-tpl-add" data-tpl-idx="' + ti + '" type="button" style="flex-shrink:0;">+ Add</button>' +
                        '</div>';
                }
                h += '</div>';
                return h;
            }

            // List body.
            var listHtml;
            var listEmpty = !cols.length;
            if (listEmpty) {
                // Empty state: show templates list directly, no placeholder.
                if (availTemplates.length) {
                    listHtml = '<div style="text-align:center;color:#6b7280;font-size:13px;margin-bottom:14px;">No custom columns yet. Click <b>+ New Column</b> or pick a recommended template below.</div>';
                    listHtml += buildTemplatesHtml(false);
                } else {
                    listHtml = '<div style="text-align:center;color:#9ca3af;font-size:13px;">No custom columns yet. Click <b>+ New Column</b> to create one.</div>';
                }
            } else {
                listHtml = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                listHtml += '<thead><tr style="border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:500;text-align:left;">' +
                    '<th style="padding:6px 8px;width:54px;text-align:center;" title="Show this column on the campaign you are viewing">Show</th>' +
                    '<th style="padding:6px 8px;width:86px;text-align:center;">Order</th>' +
                    '<th style="padding:6px 8px;">Name</th>' +
                    '<th style="padding:6px 8px;">Formula</th>' +
                    '<th style="padding:6px 8px;width:110px;text-align:center;">Actions</th>' +
                    '</tr></thead><tbody>';
                for (var i = 0; i < cols.length; i++) {
                    var c = cols[i];
                    var upDis  = i === 0 ? ' disabled style="width:24px;height:24px;opacity:0.3;cursor:not-allowed;"' : ' style="width:24px;height:24px;"';
                    var dnDis  = i === cols.length - 1 ? ' disabled style="width:24px;height:24px;opacity:0.3;cursor:not-allowed;"' : ' style="width:24px;height:24px;"';
                    listHtml += '<tr class="optee-cc-row" draggable="true" data-id="' + Optee.Util.escapeHtml(c.id) + '" style="border-bottom:1px solid #f3f4f6;">' +
                        '<td style="padding:6px 8px;text-align:center;">' +
                            '<input type="checkbox" class="optee-cc-assign" data-id="' + Optee.Util.escapeHtml(c.id) + '"' + (enabledSet[c.id] ? ' checked' : '') + ' style="width:16px;height:16px;cursor:pointer;accent-color:#2aa5ff;" title="Show on this campaign">' +
                        '</td>' +
                        '<td style="padding:6px 8px;">' +
                            '<div style="display:flex;align-items:center;justify-content:center;gap:3px;">' +
                            '<span class="optee-cc-grip" title="Drag to reorder" style="display:inline-flex;align-items:center;cursor:grab;color:#cbd5e1;" aria-hidden="true">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="22" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.8"/><circle cx="9" cy="12" r="1.8"/><circle cx="9" cy="19" r="1.8"/><circle cx="15" cy="5" r="1.8"/><circle cx="15" cy="12" r="1.8"/><circle cx="15" cy="19" r="1.8"/></svg>' +
                            '</span>' +
                            '<button class="optee-icon-btn optee-cc-up" data-id="' + Optee.Util.escapeHtml(c.id) + '"' + upDis + ' title="Move up">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>' +
                            '</button>' +
                            '<button class="optee-icon-btn optee-cc-down" data-id="' + Optee.Util.escapeHtml(c.id) + '"' + dnDis + ' title="Move down">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>' +
                            '</button>' +
                            '</div>' +
                        '</td>' +
                        '<td style="padding:8px;color:#111827;font-weight:500;">' + Optee.Util.escapeHtml(c.name) +
                            (c.overridden ? ' <span title="Customized for this campaign only" style="display:inline-block;font-size:10px;font-weight:600;color:#2563eb;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:0 5px;margin-left:4px;vertical-align:middle;">This campaign</span>' : '') +
                        '</td>' +
                        '<td style="padding:8px;color:#6b7280;font-family:ui-monospace,monospace;font-size:12px;">' + Optee.Util.escapeHtml(c.formula) + '</td>' +
                        '<td style="padding:8px;text-align:center;white-space:nowrap;">' +
                            (c.overridden ? '<button class="optee-icon-btn optee-cc-reset" data-id="' + Optee.Util.escapeHtml(c.id) + '" style="width:26px;height:26px;" title="Reset to global formula">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>' +
                            '</button> ' : '') +
                            '<button class="optee-icon-btn optee-cc-edit" data-id="' + Optee.Util.escapeHtml(c.id) + '" style="width:26px;height:26px;" title="Edit">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>' +
                            '</button> ' +
                            '<button class="optee-icon-btn optee-cc-clone" data-id="' + Optee.Util.escapeHtml(c.id) + '" style="width:26px;height:26px;" title="Duplicate">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>' +
                            '</button> ' +
                            '<button class="optee-icon-btn optee-cc-delete" data-id="' + Optee.Util.escapeHtml(c.id) + '" style="width:26px;height:26px;" title="Delete">' +
                                '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>' +
                            '</button>' +
                        '</td></tr>';
                }
                listHtml += '</tbody></table>';
                // Templates list also appears under the table so users can pick them later.
                listHtml += buildTemplatesHtml(true);
            }

            // ----- Per-day breakout section (built-in tracker metric × relative day) -----
            var pd = self.perdayConfig || { enabled: false, days: 4, metrics: ['tr_profit', 'tr_roi'] };
            var pdChecked = {};
            for (var pm = 0; pm < (pd.metrics || []).length; pm++) pdChecked[pd.metrics[pm]] = true;
            var pdDaysOpts = '';
            for (var pdD = 2; pdD <= 7; pdD++) pdDaysOpts += '<option value="' + pdD + '"' + (pd.days === pdD ? ' selected' : '') + '>' + pdD + ' days</option>';
            var pdMetricsHtml = '';
            var pdTcols = COL_GROUPS.tracker.columns;
            for (var pdc = 0; pdc < pdTcols.length; pdc++) {
                var pdcol = pdTcols[pdc];
                pdMetricsHtml += '<label style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:#374151;cursor:pointer;">' +
                    '<input type="checkbox" class="optee-pd-metric" value="' + Optee.Util.escapeHtml(pdcol.key) + '"' + (pdChecked[pdcol.key] ? ' checked' : '') + ' style="width:14px;height:14px;cursor:pointer;accent-color:#2aa5ff;">' +
                    Optee.Util.escapeHtml(pdcol.label) + '</label>';
            }
            // On mobile, keep the per-day settings collapsed by default (even when the
            // feature is enabled) so the modal stays compact — a chevron toggles them.
            var pdDefaultExpanded = pd.enabled && ( typeof window === 'undefined' || window.innerWidth > 640 );
            // Honor a manual collapse across modal re-renders. Reordering (drag or the
            // arrows) tears the modal down and reopens it, which previously re-expanded
            // this panel every time; self._pdCollapsed remembers the user's choice.
            var pdExpanded = ( self._pdCollapsed === undefined ) ? pdDefaultExpanded : ( pd.enabled && ! self._pdCollapsed );
            var pdChevron = '<svg id="optee-pd-chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:#6b7280;transition:transform .15s;transform:rotate(' + (pdExpanded ? '90deg' : '0deg') + ');visibility:' + (pd.enabled ? 'visible' : 'hidden') + ';"><path d="m9 18 6-6-6-6"/></svg>';
            var perdaySectionHtml =
                '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;margin-bottom:14px;background:#fafbfc;">' +
                    '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">' +
                        '<div id="optee-pd-toggle" style="display:flex;align-items:center;gap:6px;min-width:0;cursor:' + (pd.enabled ? 'pointer' : 'default') + ';user-select:none;">' +
                            pdChevron +
                            '<div style="font-size:13px;font-weight:600;color:#111827;">Per-day breakout</div>' +
                        '</div>' +
                        '<label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#374151;cursor:pointer;user-select:none;">' +
                            '<input type="checkbox" id="optee-pd-enabled"' + (pd.enabled ? ' checked' : '') + ' style="width:16px;height:16px;cursor:pointer;accent-color:#2aa5ff;"> Show per-day columns' +
                        '</label>' +
                    '</div>' +
                    '<div id="optee-pd-body" style="margin-top:10px;' + (pdExpanded ? '' : 'display:none;') + '">' +
                        '<div style="font-size:11px;color:#6b7280;margin-bottom:10px;line-height:1.5;">' +
                            'Adds standalone <b>Today / Yesterday / …</b> columns for the chosen metrics, on the <b>zones (default)</b> report only. Uses a fixed recent-day window, independent of the timeframe above.' +
                        '</div>' +
                        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">' +
                            '<span style="font-size:12px;color:#374151;white-space:nowrap;">Days back:</span>' +
                            '<select id="optee-pd-days" class="optee-select" style="width:100px;flex:none;font-size:12px;padding:4px 8px;">' + pdDaysOpts + '</select>' +
                        '</div>' +
                        '<div style="font-size:12px;color:#374151;margin-bottom:6px;">Metrics <span style="color:#9ca3af;">(pick a few to avoid too many columns)</span>:</div>' +
                        '<div style="display:flex;flex-wrap:wrap;gap:8px 14px;">' + pdMetricsHtml + '</div>' +
                    '</div>' +
                '</div>';

            var modalHtml = '<div id="optee-cc-modal" style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;padding:12px;">' +
                // Mobile: tighten padding and let the card use nearly the full screen so
                // the scrollable column list has room instead of overflowing the card.
                '<style>@media (max-width:640px){#optee-cc-modal{padding:6px!important;}#optee-cc-modal .optee-cc-card{padding:16px!important;width:100%!important;max-width:100vw!important;max-height:94vh!important;}#optee-cc-modal .optee-cc-hu-hint{display:none!important;}}</style>' +
                '<div class="optee-cc-card" style="background:#fff;border-radius:10px;padding:24px;width:780px;max-width:95vw;max-height:85vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                    '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">' +
                        '<div style="font-weight:600;font-size:15px;">Custom Columns</div>' +
                        '<button id="optee-cc-close" style="background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' +
                        '</button>' +
                    '</div>' +
                    '<div style="font-size:12px;color:#6b7280;margin-bottom:12px;line-height:1.5;">' +
                        'Tick <b>Show</b> to display a column on <b>this campaign</b> (it still only appears where its referenced fields exist). When you <b>edit</b> a column you can choose <b>this campaign only</b> or all campaigns. Creating and deleting always affect your global library.' +
                    '</div>' +
                    perdaySectionHtml +
                    '<div id="optee-cc-list" style="overflow-y:auto;flex:1;min-height:0;' + (listEmpty && !availTemplates.length ? 'display:flex;align-items:center;justify-content:center;' : '') + '">' + listHtml + '</div>' +
                    '<div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px 12px;margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb;">' +
                        '<button id="optee-cc-new" class="optee-btn-primary" type="button">+ New Column</button>' +
                        (listEmpty ? '' : '<label title="Show only ticked columns" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#374151;cursor:pointer;user-select:none;">' +
                            '<input type="checkbox" id="optee-cc-hide-unused"' + (hideUnused ? ' checked' : '') + ' style="width:15px;height:15px;cursor:pointer;accent-color:#2aa5ff;">' +
                            'Hide unused <span class="optee-cc-hu-hint">(show only ticked)</span>' +
                        '</label>') +
                        '<button id="optee-cc-close-2" class="optee-btn-secondary" type="button" style="margin-left:auto;">Close</button>' +
                    '</div>' +
                '</div></div>';

            $('#optee-cc-modal').remove();
            $('body').append(modalHtml);

            $('#optee-cc-close, #optee-cc-close-2').on('click', function () { $('#optee-cc-modal').remove(); });
            $('#optee-cc-modal').on('click', function (e) { if (e.target === this) $('#optee-cc-modal').remove(); });

            // ----- Per-day breakout wiring -----
            function collectPerday() {
                var metrics = [];
                $('#optee-pd-body .optee-pd-metric:checked').each(function () { metrics.push($(this).val()); });
                if (!metrics.length) metrics = ['tr_profit', 'tr_roi'];
                return {
                    enabled: $('#optee-pd-enabled').is(':checked'),
                    days: Math.max(1, Math.min(7, parseInt($('#optee-pd-days').val(), 10) || 4)),
                    metrics: metrics
                };
            }
            function savePerday() {
                var cfg = collectPerday();
                self.perdayConfig = cfg;
                Optee.Ajax.post('optee_save_perday_config', {
                    campaign_id: self.campaignId,
                    enabled: cfg.enabled ? 1 : 0,
                    days: cfg.days,
                    metrics: cfg.metrics
                });
                // Rebuild the table so the per-day columns appear/disappear immediately.
                self.buildActiveGroups();
                self.renderTableHeader();
                self.sortAndRender();
                self.maybeLoadPerday(false);
            }
            function setPdExpanded(expanded) {
                // Remember the choice so a modal re-render (e.g. after reordering)
                // keeps the panel as the user left it.
                self._pdCollapsed = !expanded;
                $('#optee-pd-body').toggle(!!expanded);
                $('#optee-pd-chevron').css('transform', 'rotate(' + (expanded ? '90deg' : '0deg') + ')');
            }
            // Chevron / title toggles the settings body open/closed (only when enabled).
            $('#optee-pd-toggle').on('click', function () {
                if (!$('#optee-pd-enabled').is(':checked')) return;
                setPdExpanded($('#optee-pd-body').is(':hidden'));
            });
            $('#optee-pd-enabled').on('change', function () {
                var on = $(this).is(':checked');
                $('#optee-pd-chevron').css('visibility', on ? 'visible' : 'hidden');
                $('#optee-pd-toggle').css('cursor', on ? 'pointer' : 'default');
                // Desktop expands the settings immediately; mobile stays collapsed until toggled.
                setPdExpanded(on && window.innerWidth > 640);
                savePerday();
            });
            $('#optee-pd-body').on('change', '#optee-pd-days, .optee-pd-metric', savePerday);

            // "Hide unused" — show only rows whose Show box is ticked, to make reordering easier.
            function applyHideUnused() {
                var on = $('#optee-cc-hide-unused').is(':checked');
                $('#optee-cc-list table tbody tr').each(function () {
                    var ticked = $(this).find('.optee-cc-assign').is(':checked');
                    $(this).css('display', (on && !ticked) ? 'none' : '');
                });
            }
            $('#optee-cc-hide-unused').on('change', function () {
                try { localStorage.setItem('optee_cc_hide_unused', $(this).is(':checked') ? '1' : '0'); } catch (e) {}
                applyHideUnused();
            });
            applyHideUnused();

            $('#optee-cc-new').on('click', function () { self.openColumnEditor(null); });
            $('.optee-cc-edit').on('click', function () {
                var id = $(this).data('id');
                var found = null;
                for (var i = 0; i < cols.length; i++) if (cols[i].id === id) { found = cols[i]; break; }
                if (found) self.openColumnEditor(found);
            });
            $('.optee-cc-clone').on('click', function () {
                var id = $(this).data('id');
                var found = null;
                for (var i = 0; i < cols.length; i++) if (cols[i].id === id) { found = cols[i]; break; }
                if (!found) return;
                // Make a unique name: append " (copy)", then " (copy 2)", etc. until free.
                var existingNames = {};
                for (var k = 0; k < cols.length; k++) existingNames[ cols[k].name.toLowerCase() ] = true;
                var base = found.name + ' (copy)';
                var candidate = base, n = 2;
                while (existingNames[ candidate.toLowerCase() ]) candidate = found.name + ' (copy ' + (n++) + ')';
                // Clone object with blank id + new name. Description, formula, format, decimals, color copied.
                var clone = $.extend({}, found, { id: '', name: candidate, created_at: '', updated_at: '' });
                self.openColumnEditor(clone);
            });
            function move(id, dir) {
                var idx = -1;
                for (var i = 0; i < opteeData.customColumns.length; i++) {
                    if (opteeData.customColumns[i].id === id) { idx = i; break; }
                }
                if (idx === -1) return;
                var target = idx + dir;
                // With "Hide unused" on, skip hidden (unticked) rows so visible columns reorder among themselves.
                if ($('#optee-cc-hide-unused').is(':checked')) {
                    var isVisible = function (colId) {
                        var vis = true;
                        $('#optee-cc-list .optee-cc-assign').each(function () {
                            if ($(this).data('id') === colId) { vis = $(this).is(':checked'); return false; }
                        });
                        return vis;
                    };
                    while (target >= 0 && target < opteeData.customColumns.length && !isVisible(opteeData.customColumns[target].id)) {
                        target += dir;
                    }
                }
                if (target < 0 || target >= opteeData.customColumns.length) return;
                var tmp = opteeData.customColumns[idx];
                opteeData.customColumns[idx] = opteeData.customColumns[target];
                opteeData.customColumns[target] = tmp;
                var ids = opteeData.customColumns.map(function (c) { return c.id; });
                Optee.Ajax.post('optee_reorder_custom_columns', { ids: ids }).done(function (res) {
                    if (!res.success) Optee.Toast.show('Reorder failed.', 'error');
                });
                self.refreshAfterCustomChange();
                $('#optee-cc-modal').remove();
                self.openColumnsModal();
            }

            $('.optee-cc-up').on('click',   function () { move($(this).data('id'), -1); });
            $('.optee-cc-down').on('click', function () { move($(this).data('id'),  1); });

            // Drag-and-drop reordering. Whole row is draggable; the grip is the affordance.
            var ccDragId = null;
            function ccClearDropIndicators() {
                $('#optee-cc-list .optee-cc-row td').css('box-shadow', '');
            }
            function reorderColumns(srcId, targetId, after) {
                var list = opteeData.customColumns;
                var srcIdx = -1;
                for (var i = 0; i < list.length; i++) { if (list[i].id === srcId) { srcIdx = i; break; } }
                if (srcIdx === -1) return;
                var item = list.splice(srcIdx, 1)[0];
                var tgtIdx = -1;
                for (var j = 0; j < list.length; j++) { if (list[j].id === targetId) { tgtIdx = j; break; } }
                if (tgtIdx === -1) { list.splice(srcIdx, 0, item); return; } // safety: restore, abort
                list.splice(after ? tgtIdx + 1 : tgtIdx, 0, item);
                var ids = list.map(function (c) { return c.id; });
                Optee.Ajax.post('optee_reorder_custom_columns', { ids: ids }).done(function (res) {
                    if (!res.success) Optee.Toast.show('Reorder failed.', 'error');
                });
                self.refreshAfterCustomChange();
                $('#optee-cc-modal').remove();
                self.openColumnsModal();
            }
            $('#optee-cc-list').on('dragstart', '.optee-cc-row', function (e) {
                ccDragId = $(this).data('id');
                try {
                    e.originalEvent.dataTransfer.effectAllowed = 'move';
                    e.originalEvent.dataTransfer.setData('text/plain', String(ccDragId));
                } catch (ex) {}
                $(this).css('opacity', '0.4');
            });
            $('#optee-cc-list').on('dragend', '.optee-cc-row', function () {
                $(this).css('opacity', '');
                ccClearDropIndicators();
                ccDragId = null;
            });
            $('#optee-cc-list').on('dragover', '.optee-cc-row', function (e) {
                if (ccDragId === null || $(this).data('id') === ccDragId) return;
                e.preventDefault();
                try { e.originalEvent.dataTransfer.dropEffect = 'move'; } catch (ex) {}
                var rect = this.getBoundingClientRect();
                var after = (e.originalEvent.clientY - rect.top) > rect.height / 2;
                ccClearDropIndicators();
                $(this).find('td').css('box-shadow', after ? 'inset 0 -2px 0 0 #2aa5ff' : 'inset 0 2px 0 0 #2aa5ff');
            });
            $('#optee-cc-list').on('drop', '.optee-cc-row', function (e) {
                e.preventDefault();
                var targetId = $(this).data('id');
                if (ccDragId === null || ccDragId === targetId) { ccClearDropIndicators(); return; }
                var rect = this.getBoundingClientRect();
                var after = (e.originalEvent.clientY - rect.top) > rect.height / 2;
                var src = ccDragId;
                ccDragId = null;
                ccClearDropIndicators();
                reorderColumns(src, targetId, after);
            });

            // Reset a per-campaign override back to the global formula.
            $('.optee-cc-reset').on('click', function () {
                var id = $(this).data('id');
                if (!Optee.Util.confirm('Reset this column to the global formula for this campaign?')) return;
                Optee.Ajax.post('optee_reset_custom_column_override', {
                    campaign_id: self.campaignId,
                    column_id: id
                }).done(function (res) {
                    if (res.success) {
                        if (opteeData.customColumnsOverrides) delete opteeData.customColumnsOverrides[id];
                        Optee.Toast.show('Reset to global formula.', 'success');
                        self.refreshAfterCustomChange();
                        $('#optee-cc-modal').remove();
                        self.openColumnsModal();
                    } else {
                        Optee.Toast.show((res.data && res.data.message) || 'Reset failed.', 'error');
                    }
                }).fail(function () { Optee.Toast.show('Request failed.', 'error'); });
            });

            // Add a recommended template.
            $('.optee-cc-tpl-add').on('click', function () {
                var idx = parseInt($(this).data('tpl-idx'), 10);
                var tpl = availTemplates[idx];
                if (!tpl) return;
                var $btn = $(this).prop('disabled', true).text('Adding…');
                Optee.Ajax.post('optee_save_custom_column', {
                    name:        tpl.name,
                    description: tpl.description,
                    formula:     tpl.formula,
                    format:      tpl.format,
                    decimals:    tpl.decimals,
                    color:       tpl.color
                }).done(function (res) {
                    if (res.success && res.data && res.data.column) {
                        opteeData.customColumns = opteeData.customColumns || [];
                        opteeData.customColumns.push(res.data.column);
                        // New column → show it on this campaign only.
                        self.assignNewColumnToCampaign(res.data.column.id);
                        Optee.Toast.show('"' + tpl.name + '" added.', 'success');
                        self.refreshAfterCustomChange();
                        $('#optee-cc-modal').remove();
                        self.openColumnsModal();
                    } else {
                        Optee.Toast.show((res.data && res.data.message) || 'Failed to add template.', 'error');
                        $btn.prop('disabled', false).text('+ Add');
                    }
                }).fail(function () {
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.prop('disabled', false).text('+ Add');
                });
            });

            $('.optee-cc-delete').on('click', function () {
                var id = $(this).data('id');
                if (!confirm('Delete this custom column?')) return;
                Optee.Ajax.post('optee_delete_custom_column', { id: id }).done(function (res) {
                    if (res.success) {
                        // Remove from opteeData and re-render.
                        opteeData.customColumns = opteeData.customColumns.filter(function (c) { return c.id !== id; });
                        opteeData.customColumnsEnabled = (opteeData.customColumnsEnabled || []).filter(function (x) { return x !== id; });
                        Optee.Toast.show('Custom column deleted.', 'success');
                        self.refreshAfterCustomChange();
                        $('#optee-cc-modal').remove();
                        self.openColumnsModal();
                    } else {
                        Optee.Toast.show((res.data && res.data.message) || 'Delete failed.', 'error');
                    }
                });
            });

            // Assign / unassign a column on the current campaign. Keeps the
            // modal open and live-refreshes the table behind it.
            $('.optee-cc-assign').on('change', function () {
                var id = $(this).data('id');
                var on = $(this).is(':checked');
                var $cb = $(this);
                Optee.Ajax.post('optee_toggle_custom_column_assignment', {
                    campaign_id: self.campaignId,
                    column_id: id,
                    enabled: on ? 1 : 0
                }).done(function (res) {
                    if (res.success && res.data && res.data.enabled) {
                        opteeData.customColumnsEnabled = res.data.enabled;
                        self.refreshAfterCustomChange();
                    } else {
                        $cb.prop('checked', !on);
                        Optee.Toast.show((res.data && res.data.message) || 'Update failed.', 'error');
                    }
                }).fail(function () {
                    $cb.prop('checked', !on);
                    Optee.Toast.show('Request failed.', 'error');
                });
            });
        },

        openColumnEditor: function (existing) {
            var self = this;
            // Clone passes a populated object with id='' — treat as new.
            var isEdit = !!(existing && existing.id);
            var tokens = (typeof opteeData !== 'undefined' && opteeData.customColumnTokens) || {};

            // Group tokens by group key for the picker.
            var grouped = {};
            for (var tokenStr in tokens) {
                var spec = tokens[tokenStr];
                if (!grouped[spec.group]) grouped[spec.group] = [];
                grouped[spec.group].push({ token: tokenStr, key: spec.key, type: spec.type });
            }

            var groupLabels = {
                traffic_source: 'Traffic Source',
                antifraud:      'Antifraud',
                tracker:        'Tracker',
                monetag_mon:    '[Monetag]',
                propush_mon:    '[ProPush]',
                ph_mon:         '[Partners.House]',
                rap_mon:        '[RollerAds Publisher]',
                hta_mon:        '[HilltopAds Publisher]',
                mtzr_mon:       '[Monetizer]',
                zeydoo_mon:     '[Zeydoo]'
            };
            var monGroups = { monetag_mon:1, propush_mon:1, ph_mon:1, rap_mon:1, hta_mon:1, mtzr_mon:1, zeydoo_mon:1 };

            // Map a group key to the pill colour class.
            var pillCls = {
                traffic_source: 'optee-cc-pill-ts',
                antifraud:      'optee-cc-pill-af',
                tracker:        'optee-cc-pill-tracker',
                monetag_mon:    'optee-cc-pill-mon',
                propush_mon:    'optee-cc-pill-mon',
                ph_mon:         'optee-cc-pill-mon',
                rap_mon:        'optee-cc-pill-mon',
                hta_mon:        'optee-cc-pill-mon',
                mtzr_mon:       'optee-cc-pill-mon',
                zeydoo_mon:     'optee-cc-pill-mon'
            };

            // Render pills, monetization platforms under one umbrella heading.
            function renderPills(order, isMon) {
                var html = '';
                for (var i = 0; i < order.length; i++) {
                    var gk = order[i];
                    if (!grouped[gk]) continue;
                    var heading = isMon ? groupLabels[gk] : groupLabels[gk];
                    html += '<div style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;margin:8px 0 4px;">' + Optee.Util.escapeHtml(heading) + '</div>';
                    html += '<div style="display:flex;flex-wrap:wrap;gap:4px;">';
                    var cls = pillCls[gk] || '';
                    for (var j = 0; j < grouped[gk].length; j++) {
                        var p = grouped[gk][j];
                        // Strip the "[Group] " prefix from the displayed token for compactness — tooltip shows the full string.
                        var displayLabel = p.token.replace(/^\[[^\]]+\]\s*/, '');
                        html += '<button type="button" class="optee-cc-pill ' + cls + '" data-token="' + Optee.Util.escapeHtml(p.token) + '" title="' + Optee.Util.escapeHtml(p.token) + '">' +
                            Optee.Util.escapeHtml(displayLabel) + '</button>';
                    }
                    html += '</div>';
                }
                return html;
            }

            var pillsHtml = '';
            pillsHtml += renderPills(['traffic_source', 'antifraud', 'tracker'], false);
            // Monetization umbrella.
            pillsHtml += '<div style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;margin:14px 0 4px;border-top:1px solid #f3f4f6;padding-top:10px;">Monetization</div>';
            pillsHtml += renderPills(['monetag_mon', 'propush_mon', 'ph_mon', 'rap_mon', 'hta_mon', 'mtzr_mon', 'zeydoo_mon'], true);

            var formatOpts = ['number', 'money', 'percent'];
            var colorOpts = [
                { value: '', label: 'None' },
                { value: 'profit', label: 'Profit (green positive / red negative)' },
                { value: 'higher_better', label: 'Higher is better' },
                { value: 'lower_better', label: 'Lower is better' }
            ];

            function selectHtml(name, opts, current) {
                var h = '<select id="' + name + '" class="optee-select" style="width:100%;">';
                for (var i = 0; i < opts.length; i++) {
                    var o = typeof opts[i] === 'string' ? { value: opts[i], label: opts[i].charAt(0).toUpperCase() + opts[i].slice(1) } : opts[i];
                    var sel = String(o.value) === String(current) ? ' selected' : '';
                    h += '<option value="' + Optee.Util.escapeHtml(String(o.value)) + '"' + sel + '>' + Optee.Util.escapeHtml(o.label) + '</option>';
                }
                h += '</select>';
                return h;
            }

            var decimalsOpts = [];
            for (var d = 0; d <= 6; d++) decimalsOpts.push({ value: d, label: String(d) });

            var cur = existing || { id: '', name: '', description: '', formula: '', format: 'money', decimals: 2, color: '' };

            // Scope chooser — only when editing an existing column. Defaults to
            // "this campaign only" so a tweak never silently changes other campaigns.
            var scopeHtml = isEdit ? (
                '<div style="margin-bottom:14px;padding:10px 12px;background:#f0f7ff;border:1px solid #dbeafe;border-radius:8px;">' +
                    '<div style="font-size:12px;color:#374151;font-weight:600;margin-bottom:6px;">Apply changes to</div>' +
                    '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#111827;cursor:pointer;margin-bottom:4px;">' +
                        '<input type="radio" name="optee-cc-scope" value="campaign" checked style="accent-color:#2aa5ff;"> This campaign only' +
                    '</label>' +
                    '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#111827;cursor:pointer;">' +
                        '<input type="radio" name="optee-cc-scope" value="global" style="accent-color:#2aa5ff;"> All campaigns (global library)' +
                    '</label>' +
                '</div>'
            ) : '';

            var formHtml = '<div id="optee-cc-form" style="position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;">' +
                '<div style="background:#fff;border-radius:10px;padding:24px;width:680px;max-width:95vw;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.2);">' +
                    '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">' +
                        '<div style="font-weight:600;font-size:15px;">' + (isEdit ? 'Edit Column' : 'New Column') + '</div>' +
                        '<button id="optee-cc-form-close" style="background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' +
                        '</button>' +
                    '</div>' +
                    '<div style="overflow-y:auto;flex:1;min-height:0;padding:4px 4px 4px 4px;margin:-4px -4px 0 -4px;">' +
                        scopeHtml +
                        '<div style="margin-bottom:12px;">' +
                            '<label style="display:block;font-size:12px;color:#374151;margin-bottom:4px;font-weight:500;">Column Name *</label>' +
                            '<input type="text" id="optee-cc-name" class="optee-input" style="width:100%;" maxlength="60" value="' + Optee.Util.escapeHtml(cur.name) + '" placeholder="e.g. True Profit">' +
                        '</div>' +
                        '<div style="margin-bottom:12px;">' +
                            '<label style="display:block;font-size:12px;color:#374151;margin-bottom:4px;font-weight:500;">Description</label>' +
                            '<input type="text" id="optee-cc-desc" class="optee-input" style="width:100%;" maxlength="200" value="' + Optee.Util.escapeHtml(cur.description) + '" placeholder="Optional">' +
                        '</div>' +
                        '<div style="margin-bottom:8px;">' +
                            '<label style="display:block;font-size:12px;color:#374151;margin-bottom:4px;font-weight:500;">Formula *</label>' +
                            '<textarea id="optee-cc-formula" class="optee-input" style="width:100%;height:64px;font-family:ui-monospace,monospace;font-size:13px;" placeholder="e.g. [Tracker] Revenue - [Traffic Source] Cost">' + Optee.Util.escapeHtml(cur.formula) + '</textarea>' +
                            '<div style="display:flex;gap:4px;margin-top:6px;">' +
                                '<button type="button" class="optee-cc-op" data-op="(" style="width:32px;height:32px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font-size:14px;">(</button>' +
                                '<button type="button" class="optee-cc-op" data-op=")" style="width:32px;height:32px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font-size:14px;">)</button>' +
                                '<button type="button" class="optee-cc-op" data-op="+" style="width:32px;height:32px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font-size:14px;">+</button>' +
                                '<button type="button" class="optee-cc-op" data-op="-" style="width:32px;height:32px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font-size:14px;">−</button>' +
                                '<button type="button" class="optee-cc-op" data-op="*" style="width:32px;height:32px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font-size:14px;">×</button>' +
                                '<button type="button" class="optee-cc-op" data-op="/" style="width:32px;height:32px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font-size:14px;">÷</button>' +
                            '</div>' +
                            '<div id="optee-cc-preview" style="margin-top:8px;font-size:12px;color:#6b7280;"></div>' +
                        '</div>' +
                        '<div style="margin-top:14px;">' +
                            '<div style="font-size:12px;color:#374151;font-weight:500;margin-bottom:6px;">Available fields (click to insert)</div>' +
                            pillsHtml +
                        '</div>' +
                        '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:14px;padding-top:14px;border-top:1px solid #f3f4f6;">' +
                            '<div><label style="display:block;font-size:12px;color:#374151;margin-bottom:4px;font-weight:500;">Format</label>' + selectHtml('optee-cc-format', formatOpts, cur.format) + '</div>' +
                            '<div><label style="display:block;font-size:12px;color:#374151;margin-bottom:4px;font-weight:500;">Decimals</label>' + selectHtml('optee-cc-decimals', decimalsOpts, cur.decimals) + '</div>' +
                            '<div><label style="display:block;font-size:12px;color:#374151;margin-bottom:4px;font-weight:500;">Color coding</label>' + selectHtml('optee-cc-color', colorOpts, cur.color) + '</div>' +
                        '</div>' +
                        '<div style="margin-top:12px;">' +
                            '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;cursor:pointer;">' +
                                '<input type="checkbox" id="optee-cc-clamp" style="width:15px;height:15px;accent-color:#2aa5ff;"' + (cur.clamp_negative ? ' checked' : '') + '> Show negative results as 0' +
                            '</label>' +
                            '<div style="font-size:11px;color:#9ca3af;margin-top:2px;margin-left:23px;">Useful for click-loss columns where traffic-source API delay can make the result temporarily negative.</div>' +
                        '</div>' +
                        '<div style="margin-top:10px;">' +
                            '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;cursor:pointer;">' +
                                '<input type="checkbox" id="optee-cc-missing" style="width:15px;height:15px;accent-color:#2aa5ff;"' + (cur.treat_missing_zero ? ' checked' : '') + '> Treat missing values as 0' +
                            '</label>' +
                            '<div style="font-size:11px;color:#9ca3af;margin-top:2px;margin-left:23px;">When a referenced field has no data for a zone (e.g. traffic that never reached the antifraud/tracker stage), count it as 0 instead of leaving the cell blank — so such zones read as full loss.</div>' +
                        '</div>' +
                    '</div>' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb;">' +
                        '<div id="optee-cc-error" style="color:#dc2626;font-size:12px;"></div>' +
                        '<div style="display:flex;gap:8px;">' +
                            '<button id="optee-cc-cancel" class="optee-btn-secondary" type="button">Cancel</button>' +
                            '<button id="optee-cc-save" class="optee-btn-primary" type="button">' + (isEdit ? 'Update' : 'Save') + '</button>' +
                        '</div>' +
                    '</div>' +
                '</div></div>';

            $('#optee-cc-form').remove();
            $('body').append(formHtml);

            // Insert token / op at cursor in the formula textarea.
            function insertAtCursor(text) {
                var $t = $('#optee-cc-formula');
                var el = $t[0];
                var start = el.selectionStart || 0;
                var end = el.selectionEnd || 0;
                var val = $t.val();
                var before = val.substring(0, start);
                // Add a leading space if previous char isn't whitespace or '('.
                var sep = (before.length && !/[\s(]$/.test(before)) ? ' ' : '';
                var newVal = before + sep + text + ' ' + val.substring(end);
                $t.val(newVal).focus();
                var newPos = (before + sep + text + ' ').length;
                el.setSelectionRange(newPos, newPos);
                self.updateColumnPreview();
            }

            $('.optee-cc-pill').on('click', function () { insertAtCursor($(this).data('token')); });
            $('.optee-cc-op').on('click', function () {
                var op = $(this).data('op');
                insertAtCursor(op);
            });
            $('#optee-cc-formula').on('input', function () { self.updateColumnPreview(); });
            $('#optee-cc-format, #optee-cc-decimals, #optee-cc-color, #optee-cc-clamp, #optee-cc-missing').on('change', function () { self.updateColumnPreview(); });

            $('#optee-cc-form-close, #optee-cc-cancel').on('click', function () { $('#optee-cc-form').remove(); });

            $('#optee-cc-save').on('click', function () {
                var $btn = $(this).prop('disabled', true);
                $('#optee-cc-error').text('');
                // New columns are always global; edits use the chosen scope (default: this campaign).
                var scope = isEdit ? ($('input[name="optee-cc-scope"]:checked').val() || 'campaign') : 'global';
                Optee.Ajax.post('optee_save_custom_column', {
                    id:          cur.id,
                    name:        $('#optee-cc-name').val(),
                    description: $('#optee-cc-desc').val(),
                    formula:     $('#optee-cc-formula').val(),
                    format:      $('#optee-cc-format').val(),
                    decimals:    $('#optee-cc-decimals').val(),
                    color:       $('#optee-cc-color').val(),
                    clamp_negative: $('#optee-cc-clamp').is(':checked') ? '1' : '0',
                    treat_missing_zero: $('#optee-cc-missing').is(':checked') ? '1' : '0',
                    scope:       scope,
                    campaign_id: self.campaignId
                }).done(function (res) {
                    if (res.success && res.data && res.data.column) {
                        var col = res.data.column;
                        opteeData.customColumnsOverrides = opteeData.customColumnsOverrides || {};

                        if (res.data.scope === 'campaign') {
                            // Per-campaign override — store the override spec, leave the library def alone.
                            opteeData.customColumnsOverrides[col.id] = {
                                name: col.name, description: col.description, formula: col.formula,
                                fields_used: col.fields_used, format: col.format,
                                decimals: col.decimals, color: col.color,
                                clamp_negative: col.clamp_negative,
                                treat_missing_zero: col.treat_missing_zero
                            };
                        } else {
                            // Global save — update the library def and drop any local override here.
                            opteeData.customColumns = opteeData.customColumns || [];
                            var found = false;
                            for (var i = 0; i < opteeData.customColumns.length; i++) {
                                if (opteeData.customColumns[i].id === col.id) {
                                    opteeData.customColumns[i] = col;
                                    found = true; break;
                                }
                            }
                            if (!found) {
                                opteeData.customColumns.push(col);
                                // New column → show it on this campaign only.
                                self.assignNewColumnToCampaign(col.id);
                            }
                            delete opteeData.customColumnsOverrides[col.id];
                        }
                        Optee.Toast.show('Custom column saved.', 'success');
                        self.refreshAfterCustomChange();
                        $('#optee-cc-form').remove();
                        $('#optee-cc-modal').remove();
                        self.openColumnsModal();
                    } else {
                        $('#optee-cc-error').text((res.data && res.data.message) || 'Save failed.');
                    }
                }).fail(function () {
                    $('#optee-cc-error').text('Request failed.');
                }).always(function () { $btn.prop('disabled', false); });
            });

            self.updateColumnPreview();
        },

        /** Live-preview the formula against the first loaded row, if any. */
        updateColumnPreview: function () {
            var formula = $('#optee-cc-formula').val() || '';
            var $out = $('#optee-cc-preview');
            if (!formula.trim()) { $out.text(''); return; }

            // Clear cache so edits re-tokenize.
            CC._compiledCache = {};
            var rpn = CC.compile(formula);
            if (!rpn) {
                $out.html('<span style="color:#dc2626;">Invalid formula or unknown field reference.</span>');
                return;
            }

            var sample = (this.data && this.data.length) ? this.data[0] : null;
            if (!sample) {
                $out.text('Formula syntax OK. (No data loaded for live preview.)');
                return;
            }
            var val = CC.evalRPN(rpn, sample, $('#optee-cc-missing').is(':checked'));
            if (val === null) {
                $out.text('Formula OK. (Sample row is missing one of the referenced fields.)');
                return;
            }
            if ($('#optee-cc-clamp').is(':checked') && val < 0) val = 0;

            var format = $('#optee-cc-format').val() || 'number';
            var decimals = parseInt($('#optee-cc-decimals').val(), 10) || 0;
            var display = val.toFixed(decimals);
            if (format === 'money') display = '$' + display;
            else if (format === 'percent') display = display + '%';
            $out.html('<span style="color:#059669;">Preview (first row): <b>' + Optee.Util.escapeHtml(display) + '</b></span>');
        },

        /**
         * Auto-assign a freshly-created column to the current campaign only
         * (in-memory + persisted), so it shows up right where it was made.
         */
        assignNewColumnToCampaign: function (id) {
            opteeData.customColumnsEnabled = opteeData.customColumnsEnabled || [];
            if (opteeData.customColumnsEnabled.indexOf(id) === -1) {
                opteeData.customColumnsEnabled.push(id);
            }
            Optee.Ajax.post('optee_toggle_custom_column_assignment', {
                campaign_id: this.campaignId,
                column_id: id,
                enabled: 1
            });
        },

        /** Rebuild header + re-render rows after a custom-column add/edit/delete. */
        refreshAfterCustomChange: function () {
            // Flush compile cache (formulas may have changed).
            CC._compiledCache = {};
            this.buildActiveGroups();
            this.renderTableHeader();
            this.sortAndRender();
        }
    };

    // Exposed so the notes modal (inline in templates/dashboard/campaign.php) can
    // repaint the zone note dots after an add/delete.
    window.OpteeStats = CampaignStats;

    $(function () {
        CampaignStats.init();
    });

    /**
     * Re-render with the rows already in memory — for plugins whose column values
     * changed (e.g. data finished loading) and that need the table redrawn.
     * Rebuilds the groups first so a newly registered column group appears.
     */
    /**
     * The rows currently rendered, in display order (i.e. honouring the active
     * sort and filters). Read-only use please — it is the live array.
     */
    Optee.Report.currentRows = function () {
        return CampaignStats.lastRenderedRows || [];
    };

    /**
     * Briefly highlight a row so a background update is visible — fades in and
     * back out. Safe to call right after refresh(): the row is looked up by its
     * first-column value, so it survives a re-render.
     */
    Optee.Report.flashRow = function (value, color) {
        var $row = $('#optee-stats-table tbody tr[data-row-value="' + String(value).replace(/"/g, '') + '"]');
        if (!$row.length) { return; }

        if (!$('#optee-row-flash-style').length) {
            $('head').append(
                '<style id="optee-row-flash-style">' +
                '@keyframes opteeRowFlash{0%{background-color:transparent}' +
                '15%{background-color:var(--optee-flash,rgba(148,163,184,.28))}' +
                '100%{background-color:transparent}}' +
                '.optee-row-flash > td{animation:opteeRowFlash 1.8s ease-out;}' +
                '</style>'
            );
        }
        if (color) { $row.css('--optee-flash', color); }
        $row.removeClass('optee-row-flash');
        void $row[0].offsetWidth;               // restart the animation on repeat calls
        $row.addClass('optee-row-flash');
        setTimeout(function () { $row.removeClass('optee-row-flash'); }, 1900);
    };

    Optee.Report.refresh = function () {
        if (!CampaignStats.lastRenderedRows) { return; }
        CampaignStats.buildActiveGroups();
        CampaignStats.renderTableHeader();
        CampaignStats.renderRows(CampaignStats.lastRenderedRows);
    };

})(jQuery);
