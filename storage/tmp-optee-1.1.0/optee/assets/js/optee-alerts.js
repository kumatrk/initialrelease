/**
 * Optee — Alerts module.
 *   Phase 1: balance threshold alerts.
 *   Phase 2: per-campaign report alerts (multi-condition, daily schedule, presets).
 */
(function ($) {
    'use strict';

    // Metric vocabulary for report-alert conditions (mirrors the Rules builder,
    // minus affiliate_network offer_status which is rule-only).
    var METRICS = {
        traffic_source: {
            impressions: 'Impressions', clicks: 'Clicks', conversions: 'Conversions',
            cost: 'Cost ($)', payout: 'Payout ($)', profit: 'Profit ($)', roi: 'ROI (%)',
            cpm: 'CPM ($)', cpc: 'CPC ($)', cpa: 'CPA ($)', ctr: 'CTR (%)', cr: 'CR (%)'
        },
        antifraud: {
            af_visits: 'AF Visits', valid_hits: 'Valid Hits', valid_percent: 'Valid %',
            traffic_loss: 'Traffic Loss %', proxy_percent: 'Proxy %', junk_percent: 'Junk %',
            bot_percent: 'Bot %', af_cost: 'AF Cost ($)'
        },
        tracker: {
            tr_visits: 'Visits', tr_clicks: 'Clicks', tr_conv: 'Conversions',
            tr_revenue: 'Revenue ($)', tr_cost: 'Cost ($)', tr_profit: 'Profit ($)',
            tr_roi: 'ROI (%)', tr_ctr: 'CTR (%)', tr_cv: 'CV (%)', tr_cpm: 'CPM ($)',
            tr_cpa: 'CPA ($)', tr_ap: 'Avg Payout ($)'
        }
    };
    var SOURCE_LABELS = { traffic_source: 'Traffic Source', antifraud: 'Antifraud', tracker: 'Tracker', custom: 'Custom' };
    var OPERATORS = { '>': 'greater than', '>=': 'greater or equal', '<': 'less than', '<=': 'less or equal', '==': 'equal to', '!=': 'not equal to' };
    var TIMEFRAMES = { today: 'Today', yesterday: 'Yesterday', '24h': 'Last 24h' };

    var Alerts = {
        sources: [], campaigns: [], customColumns: [], presets: [], allAlerts: [],

        byId: function (id) {
            id = parseInt(id, 10);
            for (var i = 0; i < Alerts.allAlerts.length; i++) {
                if (parseInt(Alerts.allAlerts[i].id, 10) === id) return Alerts.allAlerts[i];
            }
            return null;
        },

        init: function () {
            if (!$('#optee-alerts-list').length) return;
            this.bindEvents();
            this.load();
        },

        bindEvents: function () {
            $(document).on('click', '#optee-new-alert', function () { Alerts.openTypePicker(); });
            $(document).on('click', '.optee-alert-edit', function () { Alerts.openForm(Alerts.byId($(this).data('id'))); });
            $(document).on('click', '.optee-alert-delete', function () {
                var id = $(this).data('id');
                if (Optee.Util.confirm('Delete this alert?')) Alerts.remove(id);
            });
            $(document).on('click', '.optee-alert-toggle', function () { Alerts.toggle($(this).data('id')); });
            $(document).on('click', '.optee-alert-preview', function () { Alerts.previewReport($(this).data('id')); });

            // Report condition builder (delegated within the modal).
            $(document).on('change', '.alert-cond-source', function () {
                var $row = $(this).closest('.alert-cond-row');
                $row.find('.alert-cond-metric').replaceWith(Alerts.metricSelect($(this).val(), ''));
            });
            $(document).on('click', '.alert-cond-remove', function () {
                if ($('#alert-conditions .alert-cond-row').length > 1) $(this).closest('.alert-cond-row').remove();
            });
            $(document).on('click', '#alert-add-cond', function () { $('#alert-conditions').append(Alerts.conditionRow({})); });
            $(document).on('change', '#alert-preset', function () {
                var p = Alerts.presets[parseInt($(this).val(), 10)];
                if (!p) return;
                var $c = $('#alert-conditions').empty();
                (p.conditions || []).forEach(function (cond) { $c.append(Alerts.conditionRow(cond)); });
            });
        },

        load: function () {
            var $c = $('#optee-alerts-list');
            $c.html('<div class="text-center py-12 text-gray-400">Loading alerts...</div>');
            Optee.Ajax.post('optee_get_alerts', {}).done(function (res) {
                if (!res.success) { $c.html('<div class="optee-card text-center py-12 text-red-400">Failed to load alerts.</div>'); return; }
                Alerts.sources = res.data.sources || [];
                Alerts.campaigns = res.data.campaigns || [];
                Alerts.customColumns = res.data.custom_columns || [];
                Alerts.presets = res.data.presets || [];
                Alerts.allAlerts = res.data.alerts || [];
                Alerts.render(Alerts.allAlerts);
            }).fail(function () {
                $c.html('<div class="optee-card text-center py-12 text-red-400">Failed to load alerts.</div>');
            });
        },

        /* ── List ── */

        render: function (alerts) {
            var $c = $('#optee-alerts-list');
            if (!alerts.length) {
                $c.html('<div class="optee-card text-center py-12 text-gray-400">No alerts yet. Click <strong>New Alert</strong> to get notified about a balance threshold or a campaign\'s performance.</div>');
                return;
            }

            var html = '<div class="optee-card p-0 overflow-hidden"><div class="optee-rules-table-wrap"><table class="optee-table"><thead><tr>';
            html += '<th>Target</th><th>Condition</th><th>Notify</th><th>Status</th><th>Last Triggered</th><th class="text-right">Actions</th>';
            html += '</tr></thead><tbody>';

            $.each(alerts, function (_, a) {
                var target, cond;
                if (a.type === 'report') {
                    target = Optee.Util.escapeHtml(a.campaign_name || '(campaign)') + ' <span class="optee-badge-blue" style="font-size:10px;">Report</span>';
                    cond = Optee.Util.escapeHtml(a.condition_summary || '') +
                        '<div class="text-xs text-gray-400">daily ' + Optee.Util.escapeHtml(a.schedule_time || '06:00') + ' · ' + Optee.Util.escapeHtml(TIMEFRAMES[a.timeframe] || a.timeframe) + '</div>';
                } else {
                    target = Optee.Util.escapeHtml(a.module_label || a.module_name) + ' <span class="optee-badge-gray" style="font-size:10px;">Balance</span>';
                    cond = 'balance ' + Optee.Util.escapeHtml(a.direction) + ' $' + Number(a.threshold).toFixed(2);
                }
                // Hover-revealed deep-link into Logs, filtered to this exact alert
                // (by numeric alert_id — free-text search in the URL got stripped by
                // WP's canonical redirect on the front page).
                var logsUrl = opteeData.dashboardUrl + '?module=logs&category=alert&alert_id=' + a.id;
                target += ' <a href="' + logsUrl + '" class="optee-alert-logs-link" title="View this alert\'s log entries">Logs</a>';
                var chans = (a.channels || []).map(function (c) { return c === 'email' ? 'Email' : 'Bell'; }).join(' + ');
                var statusBadge = a.enabled ? '<span class="optee-badge-green">On</span>' : '<span class="optee-badge-red">Off</span>';
                var last = a.last_fired_at ? Alerts.relTime(a.last_fired_at) : '<span class="text-gray-400">Never</span>';
                html += '<tr>';
                html += '<td class="font-medium text-gray-900">' + target + '</td>';
                html += '<td class="text-sm">' + cond + '</td>';
                html += '<td class="text-sm text-gray-500">' + Optee.Util.escapeHtml(chans) + '</td>';
                html += '<td><button class="optee-alert-toggle cursor-pointer" data-id="' + a.id + '" style="background:none;border:none;cursor:pointer;">' + statusBadge + '</button></td>';
                html += '<td class="text-sm text-gray-500">' + last + '</td>';
                var previewBtn = (a.type === 'report') ? '<button class="optee-btn-ghost optee-btn-sm optee-alert-preview" data-id="' + a.id + '">Preview</button>' : '';
                html += '<td class="text-right" style="white-space:nowrap;"><div class="flex items-center justify-end gap-1">' +
                    previewBtn +
                    '<button class="optee-btn-ghost optee-btn-sm optee-alert-edit" data-id="' + a.id + '">Edit</button>' +
                    '<button class="optee-alert-delete" data-id="' + a.id + '" title="Delete alert" style="background:none;border:none;cursor:pointer;padding:4px;color:#9ca3af;" onmouseover="this.style.color=\'#ef4444\'" onmouseout="this.style.color=\'#9ca3af\'">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>' +
                    '</button></div></td>';
                html += '</tr>';
            });

            html += '</tbody></table></div></div>';
            $c.html(html);
        },

        openForm: function (alert) {
            if (alert && alert.type === 'report') Alerts.openReportForm(alert);
            else Alerts.openBalanceForm(alert);
        },

        /* ── Type picker ── */

        openTypePicker: function () {
            var body =
                '<button type="button" class="optee-alert-pick" data-t="balance" style="display:block;width:100%;text-align:left;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin-bottom:10px;cursor:pointer;background:#fff;">' +
                    '<div style="font-weight:600;color:#111827;">💰 Balance alert</div>' +
                    '<div style="font-size:12px;color:#6b7280;margin-top:2px;">Notify when an integration balance crosses a threshold.</div></button>' +
                '<button type="button" class="optee-alert-pick" data-t="report" style="display:block;width:100%;text-align:left;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;cursor:pointer;background:#fff;">' +
                    '<div style="font-weight:600;color:#111827;">📊 Campaign report alert</div>' +
                    '<div style="font-size:12px;color:#6b7280;margin-top:2px;">Notify daily when a campaign meets your conditions (profit, ROI, bot %, custom columns…).</div></button>';
            Alerts.modal('New Alert', body, null, 440);
            $('#optee-alert-save').hide();
            $('.optee-alert-pick').on('click', function () {
                var t = $(this).data('t');
                $('#optee-alert-modal').remove();
                if (t === 'report') Alerts.openReportForm(null);
                else Alerts.openBalanceForm(null);
            });
        },

        /* ── Balance form ── */

        openBalanceForm: function (alert) {
            if (!Alerts.sources.length) {
                Optee.Toast.show('Connect an integration with a balance first (traffic source, affiliate network, or monetization platform).', 'info');
                return;
            }
            var selKey = alert ? (alert.module_type + '::' + alert.module_slug) : '';
            var srcOpts = '';
            $.each(Alerts.sources, function (_, s) {
                var key = s.type + '::' + s.slug;
                srcOpts += '<option value="' + Optee.Util.escapeHtml(key) + '"' + (key === selKey ? ' selected' : '') + '>' + Optee.Util.escapeHtml(s.label || s.name) + '</option>';
            });
            var dir = alert ? alert.direction : 'below';
            var thr = alert ? Number(alert.threshold).toFixed(2) : '';
            var chans = alert ? alert.channels : ['email', 'bell'];
            var enabled = alert ? alert.enabled : true;
            var fieldRow = 'display:flex;align-items:center;gap:12px;margin-bottom:14px;';
            var lbl = 'width:120px;font-size:13px;font-weight:600;color:#374151;flex-shrink:0;';

            var body =
                '<div style="' + fieldRow + '"><label style="' + lbl + '">Integration</label>' +
                    '<select id="alert-source" class="optee-select" style="flex:1;">' + srcOpts + '</select></div>' +
                '<div style="' + fieldRow + '"><label style="' + lbl + '">When balance</label>' +
                    '<select id="alert-direction" class="optee-select" style="width:130px;">' +
                        '<option value="below"' + (dir === 'below' ? ' selected' : '') + '>is below</option>' +
                        '<option value="above"' + (dir === 'above' ? ' selected' : '') + '>is above</option></select>' +
                    '<input type="text" id="alert-threshold" inputmode="decimal" class="optee-input" style="width:120px;" placeholder="50" value="' + Optee.Util.escapeHtml(thr) + '">' +
                    '<span style="font-size:12px;color:#9ca3af;">integration currency</span></div>' +
                Alerts.channelsRow(chans, enabled);

            Alerts.modal(alert ? 'Edit Balance Alert' : 'New Balance Alert', body, function () { Alerts.saveBalance(alert ? alert.id : 0); }, 560);
        },

        saveBalance: function (id) {
            var key = ($('#alert-source').val() || '').split('::');
            var threshold = parseFloat(String($('#alert-threshold').val()).replace(',', '.'));
            if (key.length !== 2) { Optee.Toast.show('Pick an integration.', 'error'); return; }
            if (isNaN(threshold)) { Optee.Toast.show('Enter a threshold amount.', 'error'); return; }
            if (!Alerts.channelsChecked()) { Optee.Toast.show('Pick at least one notification channel.', 'error'); return; }
            Alerts.submit({
                alert_id: id, alert_type: 'balance',
                module_type: key[0], module_slug: key[1],
                direction: $('#alert-direction').val(), threshold: threshold
            });
        },

        /* ── Report form ── */

        openReportForm: function (alert) {
            if (!Alerts.campaigns.length) {
                Optee.Toast.show('Create a campaign first — report alerts run against a campaign.', 'info');
                return;
            }
            var presetOpts = '<option value="">— Start from a preset —</option>';
            $.each(Alerts.presets, function (i, p) { presetOpts += '<option value="' + i + '">' + Optee.Util.escapeHtml(p.label) + '</option>'; });

            var tf = alert ? alert.timeframe : 'yesterday';
            var tfOpts = '';
            $.each(TIMEFRAMES, function (k, label) { tfOpts += '<option value="' + k + '"' + (k === tf ? ' selected' : '') + '>' + label + '</option>'; });
            var sched = alert ? (alert.schedule_time || '06:00') : '06:00';
            var chans = alert ? alert.channels : ['email', 'bell'];
            var enabled = alert ? alert.enabled : true;

            // Condition rows (from the alert, or one empty row for new).
            var condsHtml = '';
            var existing = (alert && alert.conditions && alert.conditions.length) ? alert.conditions : [{}];
            existing.forEach(function (c) { condsHtml += Alerts.conditionRow(c); });

            var fieldRow = 'display:flex;align-items:center;gap:12px;margin-bottom:14px;';
            var lbl = 'width:110px;font-size:13px;font-weight:600;color:#374151;flex-shrink:0;';

            var body =
                (alert ? '' :
                    '<div style="' + fieldRow + '"><label style="' + lbl + '">Preset</label>' +
                        '<select id="alert-preset" class="optee-select" style="flex:1;">' + presetOpts + '</select></div>') +
                '<div style="' + fieldRow + '"><label style="' + lbl + '">Campaign</label>' +
                    '<div class="optee-ss" id="alert-ss-campaign" style="flex:1;position:relative;">' +
                        '<input type="text" class="optee-input w-full optee-ss-input" placeholder="— Search campaigns… —" autocomplete="off">' +
                        '<input type="hidden">' +
                        '<div class="optee-ss-dropdown hidden"></div>' +
                    '</div></div>' +
                '<div style="margin-bottom:14px;"><label style="' + lbl + 'display:block;margin-bottom:6px;">Conditions (all must match)</label>' +
                    '<div id="alert-conditions">' + condsHtml + '</div>' +
                    '<button type="button" id="alert-add-cond" class="optee-btn-ghost optee-btn-sm" style="margin-top:2px;">+ Add condition</button></div>' +
                '<div style="' + fieldRow + '"><label style="' + lbl + '">Measured over</label>' +
                    '<select id="alert-timeframe" class="optee-select" style="width:150px;">' + tfOpts + '</select>' +
                    '<label style="' + lbl + 'width:auto;margin-left:8px;">Check daily at</label>' +
                    '<input type="time" id="alert-schedule" class="optee-input" style="width:120px;" value="' + Optee.Util.escapeHtml(sched) + '"></div>' +
                Alerts.channelsRow(chans, enabled);

            Alerts.modal(alert ? 'Edit Report Alert' : 'New Campaign Report Alert', body, function () { Alerts.saveReport(alert ? alert.id : 0); }, 640);

            // Searchable campaign select.
            Optee.SearchableSelect.init('alert-ss-campaign');
            Optee.SearchableSelect.setOptions('alert-ss-campaign',
                Alerts.campaigns.map(function (c) { return { value: String(c.id), label: c.name }; }),
                '— Search campaigns… —', false);
            if (alert && alert.campaign_id) {
                Optee.SearchableSelect.preSelect('alert-ss-campaign', String(alert.campaign_id), alert.campaign_name || String(alert.campaign_id));
            }
        },

        saveReport: function (id) {
            var cid = parseInt(Optee.SearchableSelect.getValue('alert-ss-campaign').value, 10) || 0;
            if (!cid) { Optee.Toast.show('Pick a campaign.', 'error'); return; }
            var conditions = [];
            var bad = false;
            $('#alert-conditions .alert-cond-row').each(function () {
                var $r = $(this);
                var v = parseFloat(String($r.find('.alert-cond-value').val()).replace(',', '.'));
                if (isNaN(v)) { bad = true; return; }
                conditions.push({
                    source: $r.find('.alert-cond-source').val(),
                    metric: $r.find('.alert-cond-metric').val(),
                    operator: $r.find('.alert-cond-op').val(),
                    value: v
                });
            });
            if (bad) { Optee.Toast.show('Every condition needs a numeric value.', 'error'); return; }
            if (!conditions.length) { Optee.Toast.show('Add at least one condition.', 'error'); return; }
            if (!Alerts.channelsChecked()) { Optee.Toast.show('Pick at least one notification channel.', 'error'); return; }
            Alerts.submit({
                alert_id: id, alert_type: 'report',
                campaign_id: cid,
                conditions: JSON.stringify(conditions),
                timeframe: $('#alert-timeframe').val(),
                schedule_time: $('#alert-schedule').val() || '06:00'
            });
        },

        /* ── Condition builder helpers ── */

        availableSources: function () {
            var s = ['traffic_source', 'antifraud', 'tracker'];
            if (Alerts.customColumns.length) s.push('custom');
            return s;
        },

        metricsFor: function (source) {
            if (source === 'custom') {
                var m = {};
                Alerts.customColumns.forEach(function (c) { m['cc__' + c.id] = c.name; });
                return m;
            }
            return METRICS[source] || {};
        },

        metricSelect: function (source, selected) {
            var metrics = Alerts.metricsFor(source);
            if (!selected || !metrics[selected]) selected = Object.keys(metrics)[0] || '';
            var html = '<select class="optee-select alert-cond-metric" style="min-width:150px;flex:1;">';
            $.each(metrics, function (k, label) { html += '<option value="' + k + '"' + (k === selected ? ' selected' : '') + '>' + Optee.Util.escapeHtml(label) + '</option>'; });
            return html + '</select>';
        },

        conditionRow: function (cond) {
            cond = cond || {};
            var avail = Alerts.availableSources();
            var source = cond.source && avail.indexOf(cond.source) !== -1 ? cond.source : avail[0];
            var srcSel = '<select class="optee-select alert-cond-source" style="width:130px;">';
            avail.forEach(function (s) { srcSel += '<option value="' + s + '"' + (s === source ? ' selected' : '') + '>' + SOURCE_LABELS[s] + '</option>'; });
            srcSel += '</select>';
            var op = cond.operator || '>';
            var opSel = '<select class="optee-select alert-cond-op" style="width:130px;">';
            $.each(OPERATORS, function (k, label) { opSel += '<option value="' + k + '"' + (k === op ? ' selected' : '') + '>' + label + '</option>'; });
            opSel += '</select>';
            var val = (cond.value !== undefined && cond.value !== null) ? cond.value : '';
            return '<div class="alert-cond-row" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">' +
                '<span style="font-size:11px;font-weight:600;color:#9ca3af;width:20px;">IF</span>' +
                srcSel + Alerts.metricSelect(source, cond.metric || '') + opSel +
                '<input type="text" class="optee-input alert-cond-value" inputmode="decimal" style="width:90px;" placeholder="0" value="' + Optee.Util.escapeHtml(String(val)) + '">' +
                '<button type="button" class="alert-cond-remove" title="Remove" style="background:none;border:none;color:#f87171;cursor:pointer;font-size:18px;width:22px;">&times;</button>' +
                '</div>';
        },

        /* ── Shared form bits ── */

        channelsRow: function (chans, enabled) {
            var emailOn = (chans || []).indexOf('email') !== -1;
            var bellOn = (chans || []).indexOf('bell') !== -1;
            var fieldRow = 'display:flex;align-items:center;gap:12px;margin-bottom:14px;';
            var lbl = 'width:110px;font-size:13px;font-weight:600;color:#374151;flex-shrink:0;';
            return '<div style="' + fieldRow + '"><label style="' + lbl + '">Notify via</label>' +
                '<label style="font-size:13px;display:inline-flex;align-items:center;gap:6px;margin-right:16px;cursor:pointer;"><input type="checkbox" id="alert-ch-email"' + (emailOn ? ' checked' : '') + '> Email</label>' +
                '<label style="font-size:13px;display:inline-flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" id="alert-ch-bell"' + (bellOn ? ' checked' : '') + '> Bell</label></div>' +
                '<div style="' + fieldRow + 'margin-bottom:0;"><label style="' + lbl + '">Enabled</label>' +
                '<label style="font-size:13px;display:inline-flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" id="alert-enabled"' + (enabled ? ' checked' : '') + '> Active</label></div>';
        },

        channelsChecked: function () {
            return $('#alert-ch-email').is(':checked') || $('#alert-ch-bell').is(':checked');
        },

        modal: function (title, body, onSave, width) {
            $('#optee-alert-modal').remove();
            var modal = '<div id="optee-alert-modal" class="optee-modal-overlay" style="display:flex;">' +
                '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);" id="optee-alert-backdrop"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:10px;padding:24px;width:' + (width || 560) + 'px;max-width:95vw;max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<div style="font-weight:600;font-size:15px;margin-bottom:18px;">' + Optee.Util.escapeHtml(title) + '</div>' +
                body +
                '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:22px;">' +
                '<button id="optee-alert-cancel" class="optee-btn-secondary">Cancel</button>' +
                '<button id="optee-alert-save" class="optee-btn-primary">Save Alert</button>' +
                '</div></div></div>';
            $('body').append(modal);
            $('#optee-alert-cancel, #optee-alert-backdrop').on('click', function () { $('#optee-alert-modal').remove(); });
            if (onSave) $('#optee-alert-save').on('click', onSave);
        },

        submit: function (data) {
            data.channel_email = $('#alert-ch-email').is(':checked') ? '1' : '0';
            data.channel_bell = $('#alert-ch-bell').is(':checked') ? '1' : '0';
            data.enabled = $('#alert-enabled').is(':checked') ? '1' : '0';
            var $btn = $('#optee-alert-save').prop('disabled', true).text('Saving...');
            Optee.Ajax.post('optee_save_alert', data).done(function (res) {
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    $('#optee-alert-modal').remove();
                    Alerts.load();
                } else {
                    Optee.Toast.show((res.data && res.data.message) || 'Save failed.', 'error');
                    $btn.prop('disabled', false).text('Save Alert');
                }
            }).fail(function () {
                Optee.Toast.show('Save failed.', 'error');
                $btn.prop('disabled', false).text('Save Alert');
            });
        },

        toggle: function (id) {
            Optee.Ajax.post('optee_toggle_alert', { alert_id: id }).done(function (res) {
                if (res.success) { Optee.Toast.show(res.data.message, 'success'); Alerts.load(); }
                else Optee.Toast.show((res.data && res.data.message) || 'Failed.', 'error');
            });
        },

        remove: function (id) {
            Optee.Ajax.post('optee_delete_alert', { alert_id: id }).done(function (res) {
                if (res.success) { Optee.Toast.show(res.data.message, 'success'); Alerts.load(); }
                else Optee.Toast.show((res.data && res.data.message) || 'Failed.', 'error');
            });
        },

        previewReport: function (id) {
            var overlay = '<div id="optee-alert-preview-modal" class="optee-modal-overlay" style="display:flex;">' +
                '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);" id="optee-alert-preview-backdrop"></div>' +
                '<div id="optee-alert-preview-inner" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:10px;padding:24px;width:520px;max-width:95vw;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<div style="font-weight:600;font-size:15px;margin-bottom:16px;">Alert Preview (dry run)</div>' +
                '<div class="text-gray-400" style="font-size:13px;">Evaluating live campaign data…</div>' +
                '</div></div>';
            $('body').append(overlay);
            $('#optee-alert-preview-backdrop').on('click', function () { $('#optee-alert-preview-modal').remove(); });

            Optee.Ajax.post('optee_preview_alert', { alert_id: id }).done(function (res) {
                if (!res.success) {
                    $('#optee-alert-preview-modal').remove();
                    Optee.Toast.show((res.data && res.data.message) || 'Preview failed.', 'error');
                    return;
                }
                var d = res.data;
                var body = '<div style="margin-bottom:12px;font-size:13px;"><span class="text-gray-500">Campaign:</span> <strong>' + Optee.Util.escapeHtml(d.campaign_name) + '</strong> &nbsp;·&nbsp; <span class="text-gray-500">Over:</span> <strong>' + Optee.Util.escapeHtml(d.timeframe) + '</strong></div>';
                if (d.passed) {
                    body += '<div style="margin-bottom:14px;color:#16a34a;font-weight:600;">✓ All conditions met — this alert would fire.</div>';
                } else {
                    body += '<div style="margin-bottom:14px;color:#9ca3af;font-weight:600;">Not all conditions met — this alert would not fire.</div>';
                }
                body += '<div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">';
                (d.details || []).forEach(function (c) {
                    var actual = (c.actual === null || c.actual === undefined) ? 'N/A' : (Math.round(parseFloat(c.actual) * 10000) / 10000);
                    var ok = c.passed;
                    body += '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-bottom:1px solid #f3f4f6;font-size:13px;">' +
                        '<span>' + (ok ? '<span style="color:#16a34a;">✓</span>' : '<span style="color:#ef4444;">✗</span>') + ' ' + Optee.Util.escapeHtml(c.label || c.metric) + '</span>' +
                        '<span><strong style="color:' + (ok ? '#16a34a' : '#b45309') + ';">' + actual + '</strong> <span class="text-gray-400">(' + Optee.Util.escapeHtml(c.operator) + ' ' + c.expected + ')</span></span>' +
                        '</div>';
                });
                body += '</div>';
                body += '<div style="margin-top:14px;padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:12px;color:#92400e;">This is a preview only — no notifications were sent.</div>';
                body += '<div style="margin-top:16px;text-align:right;"><button id="optee-alert-preview-close" class="optee-btn-secondary">Close</button></div>';
                $('#optee-alert-preview-inner').html('<div style="font-weight:600;font-size:15px;margin-bottom:16px;">Alert Preview (dry run)</div>' + body);
                $('#optee-alert-preview-close').on('click', function () { $('#optee-alert-preview-modal').remove(); });
            }).fail(function () {
                $('#optee-alert-preview-modal').remove();
                Optee.Toast.show('Preview failed.', 'error');
            });
        },

        relTime: function (mysql) {
            if (!mysql) return 'Never';
            var then = new Date(mysql.replace(/-/g, '/'));
            var diffMin = Math.floor((new Date() - then) / 60000);
            if (diffMin < 1) return 'just now';
            if (diffMin < 60) return diffMin + 'm ago';
            var h = Math.floor(diffMin / 60);
            if (h < 24) return h + 'h ago';
            return Math.floor(h / 24) + 'd ago';
        }
    };

    $(function () { Alerts.init(); });

})(jQuery);
