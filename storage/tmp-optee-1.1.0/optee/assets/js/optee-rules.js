/**
 * Optee — Rules CRUD logic (overhauled).
 */
(function ($) {
    'use strict';

    /* ── Metric definitions by source ── */
    var metricsBySource = {
        traffic_source: {
            impressions: 'Impressions', clicks: 'Clicks', conversions: 'Conversions',
            cost: 'Cost ($)', payout: 'Payout ($)', profit: 'Profit ($)',
            roi: 'ROI (%)', cpm: 'CPM ($)', cpc: 'CPC ($)', cpa: 'CPA ($)',
            ctr: 'CTR (%)', cr: 'CR (%)'
        },
        antifraud: {
            af_visits: 'AF Visits', valid_hits: 'Valid Hits', valid_percent: 'Valid %',
            traffic_loss: 'Traffic Loss %', proxy_percent: 'Proxy %',
            junk_percent: 'Junk %', bot_percent: 'Bot %', af_cost: 'AF Cost ($)'
        },
        tracker: {
            tr_visits: 'Visits', tr_clicks: 'Clicks', tr_conv: 'Conversions',
            tr_revenue: 'Revenue ($)', tr_cost: 'Cost ($)', tr_profit: 'Profit ($)',
            tr_roi: 'ROI (%)', tr_ctr: 'CTR (%)', tr_cv: 'CV (%)',
            tr_cpm: 'CPM ($)', tr_cpa: 'CPA ($)', tr_ap: 'Avg Payout ($)'
        },
        affiliate_network: {
            offer_status: 'Offer Status'
        },
        // Domain reputation from the VirusTotal watch list. "Flagged" is
        // malicious + suspicious — the same total the VT change notification uses,
        // so a rule fires on the same event that already rings the bell.
        virustotal: {
            vt_flagged: 'Flagged (mal + susp)',
            vt_malicious: 'Malicious',
            vt_suspicious: 'Suspicious',
            vt_flagged_pct: 'Flagged %',
            vt_total: 'Vendors Checked'
        },
        custom: {}   // populated below from opteeData.customColumns
    };

    // Fill custom-column metrics from the user's saved columns.
    var customCols = (typeof opteeData !== 'undefined' && opteeData.customColumns) || [];
    for (var cci = 0; cci < customCols.length; cci++) {
        var cc = customCols[cci];
        metricsBySource.custom['cc__' + cc.id] = cc.name;
    }

    // Flat lookup: metric key → human-readable label.
    var metricLabels = {};
    for (var src in metricsBySource) {
        for (var key in metricsBySource[src]) {
            metricLabels[key] = metricsBySource[src][key];
        }
    }

    var sourceLabels = {
        traffic_source: 'Traffic Source',
        antifraud: 'Antifraud',
        tracker: 'Tracker',
        affiliate_network: 'Affiliate Network',
        virustotal: 'VirusTotal',
        custom: 'Custom'
    };

    /* ── VirusTotal monitored URLs (target dropdown for vt conditions) ── */
    var vtUrls = window.opteeVtUrls || [];

    /**
     * Option text for a monitored URL: host first, then the label only when it adds
     * something (VT entries are often labelled with the URL itself). Host-first means
     * a truncated option still shows the part that identifies the domain.
     */
    function vtOptionLabel(u) {
        var label = (u.label || '').trim();
        if (!label || label === u.host || label.indexOf('://') !== -1) {
            return u.host;
        }
        return u.host + ' — ' + label;
    }

    function vtTargetHtml(selected) {
        // Shrinks before the rest of the row does — the row has to stay on one line
        // inside the card, and this is the only field with variable-length content.
        var html = '<select class="optee-select rule-condition-target" style="flex:1 1 190px;min-width:150px;max-width:260px;">';
        for (var i = 0; i < vtUrls.length; i++) {
            var u = vtUrls[i];
            html += '<option value="' + Optee.Util.escapeHtml(u.url) + '"' +
                (u.url === selected ? ' selected' : '') + ' title="' + Optee.Util.escapeHtml(u.url) + '">' +
                Optee.Util.escapeHtml(vtOptionLabel(u)) + '</option>';
        }
        // A rule can outlive the VT entry it points at — keep the orphan visible and
        // selected rather than silently repointing the rule at a different domain.
        if (selected && !vtUrls.some(function (u) { return u.url === selected; })) {
            html += '<option value="' + Optee.Util.escapeHtml(selected) + '" selected>' +
                Optee.Util.escapeHtml(selected) + ' (no longer monitored)</option>';
        }
        html += '</select>';
        return html;
    }

    var operatorLabels = {
        '>': 'greater than', '>=': 'greater or equal',
        '<': 'less than', '<=': 'less or equal',
        '==': 'equal to', '!=': 'not equal to'
    };

    var actionLabels = {
        block_zone: 'Block Zone(s)',
        unblock_zone: 'Unblock Zone(s)',
        remove_countries: 'Remove Countries',
        remove_targeting: 'Remove Targeting',
        adjust_bid: 'Adjust Bid (%)',
        pause_traffic: 'Pause Traffic',
        resume_traffic: 'Resume Traffic',
        pause_campaign: 'Pause Campaign',
        start_campaign: 'Start Campaign'
    };

    var timeframeLabels = {
        today: 'Today', yesterday: 'Yesterday', '24h': '24h', '48h': '48h',
        '3d': '3 days', '7d': '7 days', '14d': '14 days', '30d': '30 days', '90d': '90 days', '180d': '180 days'
    };

    // Map custom-column metric (cc__<id>) → saved column for format/decimals lookup.
    var customColsByMetric = {};
    for (var cci2 = 0; cci2 < customCols.length; cci2++) {
        customColsByMetric['cc__' + customCols[cci2].id] = customCols[cci2];
    }

    /**
     * Format a numeric `actual` value coming back from the rule engine.
     * For custom-column metrics, honour the column's saved format + decimals.
     * For built-ins, pass through unchanged so existing display behaviour is preserved.
     */
    function formatMetricValue(metric, val) {
        if (val === null || val === undefined) return 'N/A';
        var cc = customColsByMetric[metric];
        if (!cc) return val;
        var n = parseFloat(val);
        if (isNaN(n)) return val;
        var d = parseInt(cc.decimals, 10);
        if (isNaN(d)) d = 2;
        var s = n.toFixed(d);
        if (cc.format === 'money')   return '$' + s;
        if (cc.format === 'percent') return s + '%';
        return s;
    }

    /* Why a VirusTotal condition had no value. Each of these is a deliberate
       no-op — the rule stays quiet rather than acting on a verdict it can't trust. */
    var vtReasonText = {
        vt_no_target: 'no domain selected',
        vt_no_owner: 'campaign owner not found',
        vt_url_not_monitored: 'domain is no longer on the VirusTotal list',
        vt_never_scanned: 'domain has not been scanned yet',
        vt_scan_stale: 'last scan is too old to act on'
    };

    /**
     * Source label for a condition summary. VirusTotal conditions name the domain
     * they watch — without it a rule reads as "VirusTotal Flagged > 0" and gives no
     * hint which of several monitored domains it actually guards.
     */
    function condSourceLabel(cond) {
        var base = sourceLabels[cond.source || 'traffic_source'] || cond.source;
        if (cond.source === 'virustotal' && cond.target) {
            return base + ' (' + String(cond.target).replace(/^https?:\/\//, '').replace(/\/.*$/, '') + ')';
        }
        return base;
    }

    /**
     * Trailing context for one condition line in a rule result: which domain a VT
     * condition looked at, and why it had nothing to compare when it came back empty.
     */
    function detailSuffix(det) {
        var bits = [];
        if (det.target) {
            var host = String(det.target).replace(/^https?:\/\//, '').replace(/\/.*$/, '');
            bits.push(host);
        }
        if (det.actual === null && vtReasonText[det.reason]) {
            bits.push(vtReasonText[det.reason]);
        } else if (det.note && det.note.indexOf('scanned') !== -1) {
            bits.push(det.note.replace(/^vt \S+, /, ''));
        }
        return bits.length ? ' <span style="color:#9ca3af;">— ' + Optee.Util.escapeHtml(bits.join(', ')) + '</span>' : '';
    }

    var Rules = {

        availableSources: ['traffic_source', 'antifraud', 'tracker', 'affiliate_network', 'virustotal', 'custom'],
        isWhitelistCampaign: false,
        hasTrafficSource: true,
        isOfferStatusMode: false,

        init: function () {
            this.bindEvents();
            this.loadList();

            // Set available sources from preset campaign before building conditions.
            var presetId = window.opteePresetCampaignId || 0;
            if (presetId) {
                var map = window.opteeCampaignSources || {};
                this.availableSources = map[presetId] || ['traffic_source'];
                this.checkTrafficSource(presetId);
                this.checkWhitelist(presetId);
                this.checkTsApiNotice(presetId);
                this.refreshActionCaps(presetId);
            }

            this.initConditions();

            // Per-action parameter controls: prefill (edit) then show the right block.
            this.prefillActionParams();
            this.toggleActionParams($('#rule-action').val());
        },

        bindEvents: function () {
            // Create form.
            $(document).on('submit', '#optee-rule-form', function (e) {
                e.preventDefault();
                var $f = $(this);
                Rules.checkMissingCost(function () { Rules.create($f); });
            });

            // Edit form.
            $(document).on('submit', '#optee-rule-edit-form', function (e) {
                e.preventDefault();
                var $f = $(this);
                Rules.checkMissingCost(function () { Rules.update($f); });
            });

            // Dry Run — preview an unsaved rule straight from the create/edit form.
            $(document).on('click', '.optee-dry-run-rule', function (e) {
                e.preventDefault();
                var $f = $(this).closest('form');
                Rules.previewDraft($f, $(this));
            });

            // Delete.
            $(document).on('click', '#optee-delete-rule', function () {
                var ruleId = $('#optee-rule-edit-form').data('rule-id');
                if (Optee.Util.confirm('Are you sure you want to delete this rule?')) {
                    Rules.deleteRule(ruleId);
                }
            });

            // Reset last run.
            $(document).on('click', '#optee-reset-last-run', function (e) {
                e.preventDefault();
                var ruleId = $('#optee-rule-edit-form').data('rule-id');
                var $link = $(this);
                Optee.Ajax.post('optee_reset_rule_last_run', { rule_id: ruleId })
                    .done(function (resp) {
                        if (resp && resp.success) {
                            $('#optee-last-run-label').text('Never');
                            $link.remove();
                            Optee.Toast.show(resp.data.message, 'success');
                        } else {
                            Optee.Toast.show((resp && resp.data && resp.data.message) || 'Reset failed.', 'error');
                        }
                    })
                    .fail(function () {
                        Optee.Toast.show('Reset failed.', 'error');
                    });
            });

            // Enable form fields when campaign is selected + update available sources.
            $(document).on('change', '#rule-campaign', function () {
                var $fieldset = $('#rule-fields');
                var campId = $(this).val();
                if (campId) {
                    $fieldset.prop('disabled', false);
                    Rules.setAvailableSources(campId);
                    Rules.checkTrafficSource(campId);
                    Rules.checkWhitelist(campId);
                    Rules.checkTsApiNotice(campId);
                    Rules.refreshActionCaps(campId);
                } else {
                    $fieldset.prop('disabled', true);
                    Rules.setTrafficSourceState(true);
                    Rules.setWhitelistState(false);
                    Rules.checkTsApiNotice(0);
                }
            });

            // Toggle rule from list.
            $(document).on('click', '.optee-toggle-rule', function () {
                var $btn = $(this);
                var isEnabled = $btn.data('rule-enabled') === 1 || $btn.data('rule-enabled') === '1';
                var action = isEnabled ? 'pause' : 'enable';
                if (Optee.Util.confirm('Are you sure you want to ' + action + ' this rule?')) {
                    Rules.toggleRule($btn.data('rule-id'));
                }
            });

            // Snooze control — open the duration menu.
            $(document).on('click', '.optee-snooze-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();
                Rules.openSnoozeMenu($(this));
            });

            // Snooze menu option chosen.
            $(document).on('click', '.optee-snooze-option', function (e) {
                e.preventDefault();
                var hours = parseFloat($(this).data('hours'));
                var ruleId = $(this).data('rule-id');
                Rules.closeSnoozeMenu();
                Rules.snoozeRule(ruleId, hours);
            });

            // Close snooze menu on outside click / escape.
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.optee-snooze-menu, .optee-snooze-btn').length) {
                    Rules.closeSnoozeMenu();
                }
            });
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape') Rules.closeSnoozeMenu();
            });

            // Preview rule.
            $(document).on('click', '.optee-preview-rule', function () {
                Rules.previewRule($(this).data('rule-id'), $(this));
            });

            // Run rule now.
            $(document).on('click', '.optee-run-rule', function () {
                var $btn = $(this);
                if (Optee.Util.confirm('Run this rule now? This will execute the rule action if conditions are met.')) {
                    Rules.runRule($btn.data('rule-id'), $btn);
                }
            });

            // Delete rule from list.
            $(document).on('click', '.optee-delete-rule-btn', function () {
                var ruleId = $(this).data('rule-id');
                if (Optee.Util.confirm('Are you sure you want to delete this rule?')) {
                    Rules.deleteRule(ruleId);
                }
            });

            // Duplicate rule → redirect to edit the new copy.
            $(document).on('click', '.optee-duplicate-rule', function () {
                Rules.duplicateRule($(this).data('rule-id'), $(this));
            });

            // Action change → show/hide per-action parameter controls.
            $(document).on('change', '#rule-action', function () {
                Rules.toggleActionParams($(this).val());
            });

            // Add condition row.
            $(document).on('click', '#rule-add-condition', function () {
                Rules.addConditionRow();
            });

            // Remove condition row.
            $(document).on('click', '.rule-remove-condition', function () {
                var wasOffer = $(this).closest('.rule-condition').hasClass('rule-condition-offer');
                $(this).closest('.rule-condition').remove();
                if (wasOffer) {
                    Rules.setOfferStatusMode(false);
                }
                // Always keep at least one row.
                if (!$('#rule-conditions .rule-condition').length) {
                    Rules.addConditionRow();
                }
                Rules.refreshVtActionLimits();
            });

            // Source change → update metric options + auto-add cost condition.
            $(document).on('change', '.rule-condition-source', function () {
                var $row = $(this).closest('.rule-condition');
                var source = $(this).val();

                if (source === 'affiliate_network') {
                    // If other conditions exist, confirm before switching.
                    var otherCount = $('#rule-conditions .rule-condition').not($row).length;
                    if (otherCount > 0) {
                        if (!Optee.Util.confirm('Switching to Affiliate Network will remove other conditions. Continue?')) {
                            // Revert to previous source.
                            $(this).val($(this).data('prev-source') || 'traffic_source');
                            return;
                        }
                    }
                    // Remove all other condition rows.
                    $('#rule-conditions .rule-condition').not($row).remove();
                    // Replace this row with an offer status row.
                    $row.replaceWith(Rules.buildOfferStatusRow('running'));
                    Rules.setOfferStatusMode(true);
                    return;
                }

                // Leaving offer status mode.
                if (Rules.isOfferStatusMode) {
                    Rules.setOfferStatusMode(false);
                }

                Rules.updateMetricOptions($row, source);
                if (source === 'tracker' || source === 'traffic_source') {
                    Rules.ensureCostCondition(source);
                }
            });

            // Track previous source for revert on cancel.
            $(document).on('focus', '.rule-condition-source', function () {
                $(this).data('prev-source', $(this).val());
            });

            // Cost condition info popup.
            $(document).on('click', '.optee-cost-hint', function (e) {
                e.preventDefault();
                Rules.showCostInfo();
            });

            // Import from template.
            $(document).on('click', '#rule-import-template', function () {
                Rules.importTemplate();
            });

            // Expand/collapse campaign group (desktop only).
            $(document).on('click', '.optee-rules-campaign-toggle', function (e) {
                if ($(e.target).closest('a, button').length) return;
                if (window.innerWidth <= 768) return;
                var $group = $(this).closest('.optee-rules-campaign-group');
                $group.toggleClass('collapsed');
                $group.find('.optee-rules-campaign-body').slideToggle(200);
                $(this).find('.optee-rules-chevron').toggleClass('rotate-90');
            });

            // Expand last run result.
            $(document).on('click', '.optee-rule-result-toggle', function () {
                $(this).closest('tr').next('.optee-rule-result-detail').toggle();
            });
        },

        /* ── Conditions ── */

        initConditions: function () {
            var $container = $('#rule-conditions');
            if (!$container.length) return;

            // If editing, pre-populate from window.opteeRuleConditions.
            var existing = window.opteeRuleConditions || [];
            if (existing.length) {
                // Check for offer status mode.
                if (existing[0].source === 'affiliate_network' && existing[0].metric === 'offer_status') {
                    $container.append(Rules.buildOfferStatusRow(existing[0].value));
                    Rules.setOfferStatusMode(true);
                    return;
                }
                for (var i = 0; i < existing.length; i++) {
                    Rules.addConditionRow(existing[i]);
                }
            } else {
                Rules.addConditionRow();
                // Auto-add cost condition for the default source on new rules.
                var defaultSource = $('#rule-conditions .rule-condition:first .rule-condition-source').val();
                if (defaultSource === 'traffic_source' || defaultSource === 'tracker') {
                    Rules.ensureCostCondition(defaultSource);
                }
            }
        },

        addConditionRow: function (data) {
            data = data || {};
            var source = data.source || 'traffic_source';
            var metric = data.metric || '';
            var operator = data.operator || '>';
            var value = data.value !== undefined ? data.value : '';

            var operators = window.opteeRuleOperators || { '>': 'greater than', '>=': 'greater or equal', '<': 'less than', '<=': 'less or equal', '==': 'equal to', '!=': 'not equal to' };

            // Source dropdown (only available sources).
            var avail = Rules.availableSources;
            if (avail.indexOf(source) === -1) source = avail[0] || 'traffic_source';
            var sourceHtml = '<select class="optee-select rule-condition-source" style="width:140px;">';
            $.each(sourceLabels, function (val, label) {
                if (avail.indexOf(val) === -1) return;
                sourceHtml += '<option value="' + val + '"' + (val === source ? ' selected' : '') + '>' + Optee.Util.escapeHtml(label) + '</option>';
            });
            sourceHtml += '</select>';

            // Metric dropdown (filtered by source).
            var metrics = metricsBySource[source] || {};
            // On a VirusTotal row the metric shares space with the domain select, and
            // the domain is what identifies the condition — cap the metric so it can't
            // eat the row (its longest label still fits).
            var metricStyle = 'min-width:130px;' + (source === 'virustotal' ? 'max-width:180px;' : '');
            var metricHtml = '<select class="optee-select rule-condition-metric" style="' + metricStyle + '">';
            $.each(metrics, function (val, label) {
                metricHtml += '<option value="' + val + '"' + (val === metric ? ' selected' : '') + '>' + Optee.Util.escapeHtml(label) + '</option>';
            });
            metricHtml += '</select>';

            // Operator dropdown.
            var opHtml = '<select class="optee-select rule-condition-operator" style="width:130px;">';
            $.each(operators, function (val, label) {
                opHtml += '<option value="' + val + '"' + (val === operator ? ' selected' : '') + '>' + Optee.Util.escapeHtml(label) + '</option>';
            });
            opHtml += '</select>';

            // VirusTotal conditions need a target: which monitored URL to score.
            // Rendered only for that source; hidden markup stays out of other rows.
            var targetHtml = source === 'virustotal' ? vtTargetHtml(data.target || '') : '';

            var row = '<div class="rule-condition flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2">' +
                '<span class="text-xs font-semibold text-gray-400 uppercase" style="width:20px;">IF</span>' +
                sourceHtml +
                targetHtml +
                metricHtml +
                '<span class="text-xs font-semibold text-gray-400 uppercase">IS</span>' +
                opHtml +
                '<span class="text-xs font-semibold text-gray-400 uppercase">THAN</span>' +
                '<input type="text" inputmode="decimal" pattern="-?[0-9]*[.,]?[0-9]*" class="optee-input rule-condition-value" style="width:88px;flex-shrink:0;" placeholder="0" value="' + Optee.Util.escapeHtml(String(value)) + '" required>' +
                '<button type="button" class="rule-remove-condition flex items-center justify-center text-red-400 hover:text-red-600 transition-colors" title="Remove" style="width:28px;height:28px;border:none;background:none;cursor:pointer;font-size:18px;">&times;</button>' +
                '</div>';

            $('#rule-conditions').append(row);
            Rules.refreshVtActionLimits();
        },

        updateMetricOptions: function ($row, source) {
            var metrics = metricsBySource[source] || {};
            var $metric = $row.find('.rule-condition-metric');
            var html = '';
            $.each(metrics, function (val, label) {
                html += '<option value="' + val + '">' + Optee.Util.escapeHtml(label) + '</option>';
            });
            $metric.html(html);
            $metric.css('max-width', source === 'virustotal' ? '180px' : '');

            // Add/remove the VirusTotal target dropdown as the source changes.
            var $target = $row.find('.rule-condition-target');
            if (source === 'virustotal') {
                if (!$target.length) {
                    $row.find('.rule-condition-source').after(vtTargetHtml(''));
                }
            } else {
                $target.remove();
            }
            Rules.refreshVtActionLimits();
        },

        /**
         * A domain verdict says nothing about one zone, country, or targeting value,
         * so a rule carrying a VT condition may only drive campaign-level actions.
         * Mirrors the server-side guard in validate_vt_rule().
         */
        refreshVtActionLimits: function () {
            var hasVt = $('#rule-conditions .rule-condition-source').filter(function () {
                return $(this).val() === 'virustotal';
            }).length > 0;

            var allowed = ['pause_traffic', 'resume_traffic'];
            var $action = $('#rule-action');

            $action.find('option').each(function () {
                var $o = $(this);
                if (allowed.indexOf($o.val()) !== -1) return;
                if (hasVt) {
                    if (!$o.data('vt-hidden') && !$o.prop('disabled')) {
                        $o.data('vt-hidden', true).prop('disabled', true).hide();
                    }
                } else if ($o.data('vt-hidden')) {
                    $o.data('vt-hidden', false).prop('disabled', false).show();
                }
            });

            if (hasVt && allowed.indexOf($action.val()) === -1) {
                $action.val('pause_traffic').trigger('change');
            }
        },

        buildOfferStatusRow: function (value) {
            value = value || 'running';
            var avail = Rules.availableSources;

            var sourceHtml = '<select class="optee-select rule-condition-source" style="width:160px;">';
            $.each(sourceLabels, function (val, label) {
                if (avail.indexOf(val) === -1) return;
                sourceHtml += '<option value="' + val + '"' + (val === 'affiliate_network' ? ' selected' : '') + '>' + Optee.Util.escapeHtml(label) + '</option>';
            });
            sourceHtml += '</select>';

            var statusHtml = '<select class="optee-select rule-condition-status" style="width:160px;">' +
                '<option value="running"' + (value === 'running' ? ' selected' : '') + '>is Running</option>' +
                '<option value="paused"' + (value === 'paused' ? ' selected' : '') + '>is Paused</option>' +
                '</select>';

            return '<div class="rule-condition rule-condition-offer flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2">' +
                '<span class="text-xs font-semibold text-gray-400 uppercase" style="width:20px;">IF</span>' +
                sourceHtml +
                '<span class="text-sm font-medium text-gray-600">Offer Status</span>' +
                statusHtml +
                '<input type="hidden" class="rule-condition-metric" value="offer_status">' +
                '<button type="button" class="rule-remove-condition flex items-center justify-center text-red-400 hover:text-red-600 transition-colors" title="Remove" style="width:28px;height:28px;border:none;background:none;cursor:pointer;font-size:18px;">&times;</button>' +
                '</div>';
        },

        setOfferStatusMode: function (enabled) {
            Rules.isOfferStatusMode = !!enabled;
            var $action = $('#rule-action');
            var $addBtn = $('#rule-add-condition');
            var $timeframeRow = $('#rule-timeframe').closest('.optee-form-row');

            if (enabled) {
                // Restrict action to pause/resume traffic only.
                $action.find('option').each(function () {
                    var val = $(this).val();
                    if (val !== 'pause_traffic' && val !== 'resume_traffic') {
                        $(this).prop('disabled', true).hide();
                    }
                });
                // Select pause_traffic if current value is not valid.
                var current = $action.val();
                if (current !== 'pause_traffic' && current !== 'resume_traffic') {
                    $action.val('pause_traffic');
                }

                // Hide add condition button and timeframe row.
                $addBtn.hide();
                $timeframeRow.hide();
            } else {
                // Restore all action options.
                $action.find('option').each(function () {
                    $(this).prop('disabled', false).show();
                });
                // Re-apply whitelist + traffic-source filtering on the zone actions.
                Rules.refreshZoneActions();

                $addBtn.show();
                $timeframeRow.show();
            }
        },

        ensureCostCondition: function (source) {
            var costMetric = source === 'tracker' ? 'tr_cost' : 'cost';
            var hasCost = false;
            $('#rule-conditions .rule-condition').each(function () {
                if ($(this).find('.rule-condition-source').val() === source &&
                    $(this).find('.rule-condition-metric').val() === costMetric) {
                    hasCost = true;
                    return false;
                }
            });
            if (hasCost) return;

            Rules.addConditionRow({ source: source, metric: costMetric, operator: '>', value: 1 });

            var $lastRow = $('#rule-conditions .rule-condition:last');
            var infoHtml = '<span class="optee-cost-hint" style="display:inline-flex;align-items:center;cursor:pointer;color:#9ca3af;margin-left:2px;">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></span>';
            $lastRow.find('.rule-condition-value').after(infoHtml);
        },

        showCostInfo: function () {
            if ($('#optee-cost-info-popup').length) return;

            var popup = '<div id="optee-cost-info-popup" class="optee-modal-overlay" style="display:none;">' +
                '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:32px;width:420px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);text-align:center;">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 16px;display:block;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>' +
                '<div style="font-weight:600;font-size:16px;color:#111827;margin-bottom:8px;">Auto-Added Cost Condition</div>' +
                '<p style="font-size:13px;color:#6b7280;line-height:1.6;margin-bottom:12px;">This condition was added automatically when you selected a new data source.</p>' +
                '<p style="font-size:13px;color:#6b7280;line-height:1.6;margin-bottom:20px;">Adding a minimum cost filter skips zones with very little spend. This reduces the number of zones evaluated, lowers API usage, and focuses optimization on zones with meaningful data.</p>' +
                '<button id="optee-cost-info-close" class="optee-btn-secondary">Got it</button>' +
                '</div></div>';

            $('body').append(popup);
            $('#optee-cost-info-popup').fadeIn(150);

            $('#optee-cost-info-close, #optee-cost-info-popup > div:first-child').on('click', function () {
                $('#optee-cost-info-popup').fadeOut(150, function () { $(this).remove(); });
            });
        },

        checkMissingCost: function (onProceed) {
            var sources = {};
            var costMetrics = { traffic_source: 'cost', tracker: 'tr_cost' };

            $('#rule-conditions .rule-condition').each(function () {
                var src = $(this).find('.rule-condition-source').val();
                var met = $(this).find('.rule-condition-metric').val();
                if (src === 'traffic_source' || src === 'tracker') {
                    if (!sources[src]) sources[src] = { hasAny: false, hasCost: false };
                    sources[src].hasAny = true;
                    if (met === costMetrics[src]) sources[src].hasCost = true;
                }
            });

            var missing = [];
            $.each(sources, function (src, info) {
                if (info.hasAny && !info.hasCost) {
                    missing.push(sourceLabels[src] || src);
                }
            });

            if (!missing.length) {
                onProceed();
                return;
            }

            if ($('#optee-missing-cost-popup').length) return;

            var list = missing.join(' and ');
            var popup = '<div id="optee-missing-cost-popup" class="optee-modal-overlay" style="display:none;">' +
                '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>' +
                '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:32px;width:440px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);text-align:center;">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 16px;display:block;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>' +
                '<div style="font-weight:600;font-size:16px;color:#111827;margin-bottom:8px;">No Cost Condition</div>' +
                '<p style="font-size:13px;color:#6b7280;line-height:1.6;margin-bottom:20px;">You have <strong>' + Optee.Util.escapeHtml(list) + '</strong> conditions but no minimum cost filter. Without it, every zone will be evaluated — including those with very little spend — which increases API calls and may trigger actions on zones without meaningful data.</p>' +
                '<div style="display:flex;gap:8px;justify-content:center;">' +
                '<button id="optee-missing-cost-save" class="optee-btn-primary">Save Anyway</button>' +
                '<button id="optee-missing-cost-cancel" class="optee-btn-secondary">Go Back</button>' +
                '</div></div></div>';

            $('body').append(popup);
            $('#optee-missing-cost-popup').fadeIn(150);

            $('#optee-missing-cost-save').on('click', function () {
                $('#optee-missing-cost-popup').fadeOut(150, function () { $(this).remove(); });
                onProceed();
            });
            $('#optee-missing-cost-cancel, #optee-missing-cost-popup > div:first-child').on('click', function () {
                $('#optee-missing-cost-popup').fadeOut(150, function () { $(this).remove(); });
            });
        },

        setAvailableSources: function (campaignId) {
            var map = window.opteeCampaignSources || {};
            Rules.availableSources = map[campaignId] || ['traffic_source'];

            // Exit offer status mode if affiliate_network no longer available.
            if (Rules.isOfferStatusMode && Rules.availableSources.indexOf('affiliate_network') === -1) {
                Rules.setOfferStatusMode(false);
                $('#rule-conditions').empty();
                Rules.addConditionRow();
                return;
            }

            // Rebuild all condition rows with updated source options.
            var existing = Rules.collectConditions($('#optee-rule-form, #optee-rule-edit-form'));
            $('#rule-conditions').empty();

            if (Rules.isOfferStatusMode && existing.length && existing[0].source === 'affiliate_network') {
                $('#rule-conditions').append(Rules.buildOfferStatusRow(existing[0].value));
                return;
            }

            if (existing.length) {
                for (var i = 0; i < existing.length; i++) {
                    Rules.addConditionRow(existing[i]);
                }
            } else {
                Rules.addConditionRow();
            }
        },

        checkWhitelist: function (campaignId) {
            if (!campaignId) { Rules.setWhitelistState(false); return; }
            Optee.Ajax.post('optee_check_campaign_whitelist', { campaign_id: campaignId }).done(function (res) {
                Rules.setWhitelistState(res.success && res.data && res.data.is_whitelist);
            }).fail(function () {
                Rules.setWhitelistState(false);
            });
        },

        setWhitelistState: function (isWhitelist) {
            Rules.isWhitelistCampaign = !!isWhitelist;
            Rules.refreshZoneActions();
        },

        /**
         * Look up whether the selected campaign has a usable traffic source
         * (both module slug + remote campaign ID) and update the action list.
         * Zone block/unblock actions are pushed to the traffic source via API,
         * so they're pointless without one.
         */
        checkTrafficSource: function (campaignId) {
            var map = window.opteeCampaignTsReady || {};
            Rules.setTrafficSourceState(campaignId ? !!map[campaignId] : true);
        },

        setTrafficSourceState: function (hasTs) {
            Rules.hasTrafficSource = !!hasTs;
            Rules.refreshZoneActions();
        },

        /**
         * Add/remove zone block/unblock actions from the action dropdown based on
         * the current campaign. Zone actions are unavailable when the campaign is a
         * whitelist campaign OR has no traffic source connected. Shows a contextual
         * note explaining why when they're hidden.
         */
        refreshZoneActions: function () {
            var $action = $('#rule-action');
            if (!$action.length) return;

            var zoneActionsAllowed = !Rules.isWhitelistCampaign && Rules.hasTrafficSource;

            if (!zoneActionsAllowed) {
                $action.find('option[value="block_zone"], option[value="unblock_zone"]').remove();
            } else if (!$action.find('option[value="block_zone"]').length) {
                // Re-add zone actions (prepend so they keep their original position).
                $action.prepend('<option value="unblock_zone">Unblock Zone(s)</option>');
                $action.prepend('<option value="block_zone">Block Zone(s)</option>');
            }

            // Select first option if current value was removed.
            if (!$action.find('option:selected').length || !$action.val()) {
                $action[0].selectedIndex = 0;
                $action.trigger('change');
            }

            // Contextual note — whitelist takes priority over the no-traffic-source case.
            $('#rule-whitelist-note').remove();
            $('#rule-no-ts-note').remove();
            if (Rules.isWhitelistCampaign) {
                $action.closest('.flex-1').append(
                    '<div id="rule-whitelist-note" class="text-xs text-amber-600 mt-1" style="color:#d97706;">' +
                    'This is a whitelist campaign — zone block/unblock actions are not available.' +
                    '</div>'
                );
            } else if (!Rules.hasTrafficSource) {
                $action.closest('.flex-1').append(
                    '<div id="rule-no-ts-note" class="text-xs mt-1" style="color:#d97706;">' +
                    'This campaign has no traffic source connected — zone block/unblock actions are unavailable. ' +
                    'Connect a traffic source to the campaign to push zone blocks via its API.' +
                    '</div>'
                );
            }
        },

        /**
         * Show an info banner when the campaign uses a traffic source with API restrictions.
         */
        tsApiNotices: {
            bidvertiser: 'BidVertiser API limits reports and targeting changes to <strong>1 per hour per campaign</strong>. Set rule interval to at least 60 minutes to avoid API errors.'
        },

        checkTsApiNotice: function (campaignId) {
            var $notice = $('#optee-ts-api-notice');
            var $text = $('#optee-ts-api-notice-text');
            if (!$notice.length) return;

            var slugMap = window.opteeCampaignTsSlugs || {};
            var tsSlug = slugMap[campaignId] || '';
            var msg = Rules.tsApiNotices[tsSlug] || '';

            if (msg) {
                $text.html(msg);
                $notice.slideDown(200);
            } else {
                $notice.slideUp(200);
            }
        },

        collectConditions: function ($form) {
            var conditions = [];
            $form.find('.rule-condition').each(function () {
                var $row = $(this);
                // Offer status condition.
                if ($row.hasClass('rule-condition-offer')) {
                    conditions.push({
                        source: 'affiliate_network',
                        metric: 'offer_status',
                        operator: '==',
                        value: $row.find('.rule-condition-status').val() || 'running'
                    });
                    return;
                }
                var cond = {
                    source: $row.find('.rule-condition-source').val(),
                    metric: $row.find('.rule-condition-metric').val(),
                    operator: $row.find('.rule-condition-operator').val(),
                    value: parseFloat(String($row.find('.rule-condition-value').val()).replace(',', '.')) || 0
                };
                if (cond.source === 'virustotal') {
                    cond.target = $row.find('.rule-condition-target').val() || '';
                }
                conditions.push(cond);
            });
            return conditions;
        },

        collectOnSuccess: function ($form) {
            var flags = [];
            if ($form.find('[name="on_success_pause_rule"]').is(':checked')) flags.push('pause_rule');
            if ($form.find('[name="on_success_pause_campaign"]').is(':checked')) flags.push('pause_campaign');
            return flags;
        },

        /* ── Per-action parameters ── */

        // Show the parameter block relevant to the selected action.
        toggleActionParams: function (action) {
            $('#rule-bid-params').toggle(action === 'adjust_bid');
            $('#rule-country-note').toggle(action === 'remove_countries');
            $('#rule-targeting-params').toggle(action === 'remove_targeting');
            if (action === 'remove_targeting') {
                var campId = $('#rule-campaign').val() || window.opteePresetCampaignId || 0;
                Rules.loadTargetingDims(campId);
            }
        },

        // Hide capability-gated actions (remove countries/bid/targeting) the campaign's
        // traffic source doesn't support via API — keeps Rules honest per source.
        refreshActionCaps: function (campaignId) {
            var $action = $('#rule-action');
            if (!$action.length) return;
            var caps = (window.opteeCampaignCaps || {})[campaignId] || [];
            ['remove_countries', 'adjust_bid', 'remove_targeting'].forEach(function (val) {
                var supported = caps.indexOf(val) !== -1;
                var $opt = $action.find('option[value="' + val + '"]');
                if (!$opt.length) return;
                $opt.prop('disabled', !supported).toggle(supported);
                if (supported) { $opt.removeAttr('hidden'); } else { $opt.attr('hidden', 'hidden'); }
            });
            // If the current selection just got hidden, fall back to the first visible option.
            if ($action.find('option:selected').prop('disabled')) {
                var $first = $action.find('option:not([disabled])').first();
                if ($first.length) { $action.val($first.val()).trigger('change'); }
            }
            // Capability re-enables can undo the VT restriction — reassert it last.
            Rules.refreshVtActionLimits();
        },

        // Populate the "Targeting to Remove" dropdown with only the dimensions this
        // campaign can actually use (traffic source supports it + tracker has the data).
        loadTargetingDims: function (campaignId) {
            var $sel = $('#rule-targeting-dim');
            if (!campaignId || !$sel.length) return;
            var prev = Rules._targetingDimPrefill || $sel.val();
            $sel.prop('disabled', true);
            Optee.Ajax.post('optee_get_rule_targeting_dims', { campaign_id: campaignId }).done(function (res) {
                $sel.prop('disabled', false);
                if (!res.success) return;
                var dims = res.data.dims || [];
                if (!dims.length) { $sel.html('<option value="">No supported dimensions</option>'); return; }
                var html = '';
                for (var i = 0; i < dims.length; i++) {
                    html += '<option value="' + dims[i].dim + '">' + Optee.Util.escapeHtml(dims[i].label) + '</option>';
                }
                $sel.html(html);
                if (prev) { $sel.val(prev); }
                Rules._targetingDimPrefill = null;
            }).fail(function () { $sel.prop('disabled', false); });
        },

        // Prefill the bid-params inputs when editing an existing adjust_bid rule.
        prefillActionParams: function () {
            var p = window.opteeRuleActionParams || {};
            if (p && typeof p.percent !== 'undefined' && p.percent !== null && p.percent !== '') {
                var pct = parseFloat(p.percent);
                $('#rule-bid-direction').val(pct < 0 ? 'decrease' : 'increase');
                $('#rule-bid-percent').val(Math.abs(pct));
            }
            if (p && typeof p.min !== 'undefined' && p.min !== null && p.min !== '') $('#rule-bid-min').val(p.min);
            if (p && typeof p.max !== 'undefined' && p.max !== null && p.max !== '') $('#rule-bid-max').val(p.max);
            if (p && p.dimension) { Rules._targetingDimPrefill = p.dimension; $('#rule-targeting-dim').val(p.dimension); }
        },

        // Build the action_params object for the current action (empty for actions
        // that take none). Percent is signed by the increase/decrease selector.
        collectActionParams: function ($form) {
            var action = $form.find('[name="rule_action"]').val();
            if (action === 'remove_targeting') {
                return { dimension: $('#rule-targeting-dim').val() || 'category' };
            }
            if (action !== 'adjust_bid') return {};

            var num = function (sel) {
                var v = String($(sel).val() || '').replace(',', '.').trim();
                if (v === '') return null;
                var n = parseFloat(v);
                return isNaN(n) ? null : n;
            };

            var pct = num('#rule-bid-percent');
            if (pct === null) pct = 0;
            if ($('#rule-bid-direction').val() === 'decrease') pct = -Math.abs(pct);
            else pct = Math.abs(pct);

            var params = { percent: pct };
            var mn = num('#rule-bid-min');
            var mx = num('#rule-bid-max');
            if (mn !== null) params.min = mn;
            if (mx !== null) params.max = mx;
            return params;
        },

        /* ── CRUD ── */

        create: function ($form) {
            var $btn = $form.find('[type="submit"]').prop('disabled', true).text('Creating...');

            var data = {
                title: $form.find('[name="title"]').val(),
                campaign_id: $form.find('[name="campaign_id"]').val(),
                rule_action: $form.find('[name="rule_action"]').val(),
                conditions: JSON.stringify(Rules.collectConditions($form)),
                action_params: JSON.stringify(Rules.collectActionParams($form)),
                timeframe: $form.find('[name="timeframe"]').val(),
                interval: $form.find('[name="interval"]').val(),
                enabled: $form.find('[name="enabled"]:checked').val() || '1',
                on_success: JSON.stringify(Rules.collectOnSuccess($form)),
                is_template: $form.find('[name="is_template"]:checked').val() || '0'
            };

            Optee.Ajax.post('optee_create_rule', data).done(function (res) {
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    window.location.href = opteeData.dashboardUrl + '?module=rules&new_campaign=' + data.campaign_id;
                } else {
                    Optee.Toast.show(res.data.message, 'error');
                    $btn.prop('disabled', false).text('Create Rule');
                }
            }).fail(function () {
                Optee.Toast.show('Request failed.', 'error');
                $btn.prop('disabled', false).text('Create Rule');
            });
        },

        update: function ($form) {
            var $btn = $form.find('[type="submit"]').prop('disabled', true).text('Saving...');
            var ruleId = $form.data('rule-id');

            var data = {
                rule_id: ruleId,
                title: $form.find('[name="title"]').val(),
                campaign_id: $form.find('[name="campaign_id"]').val(),
                rule_action: $form.find('[name="rule_action"]').val(),
                conditions: JSON.stringify(Rules.collectConditions($form)),
                action_params: JSON.stringify(Rules.collectActionParams($form)),
                timeframe: $form.find('[name="timeframe"]').val(),
                interval: $form.find('[name="interval"]').val(),
                enabled: $form.find('[name="enabled"]:checked').val() || '1',
                on_success: JSON.stringify(Rules.collectOnSuccess($form)),
                is_template: $form.find('[name="is_template"]:checked').val() || '0'
            };

            Optee.Ajax.post('optee_update_rule', data).done(function (res) {
                if (res.success) {
                    Rules.redirectCountdown(res.data.message, data.campaign_id);
                } else {
                    Optee.Toast.show(res.data.message, 'error');
                    $btn.prop('disabled', false).text('Save Changes');
                }
            }).fail(function () {
                Optee.Toast.show('Request failed.', 'error');
                $btn.prop('disabled', false).text('Save Changes');
            });
        },

        deleteRule: function (id) {
            Optee.Ajax.post('optee_delete_rule', { rule_id: id }).done(function (res) {
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    // If on list page, just reload list; otherwise redirect.
                    if ($('#optee-rules-list').length) {
                        Rules.loadList();
                    } else {
                        window.location.href = opteeData.dashboardUrl + '?module=rules';
                    }
                } else {
                    Optee.Toast.show(res.data.message, 'error');
                }
            });
        },

        duplicateRule: function (id, $btn) {
            var origText = $btn.text();
            $btn.prop('disabled', true).text('Duplicating...');
            Optee.Ajax.post('optee_duplicate_rule', { rule_id: id }).done(function (res) {
                if (res.success) {
                    // Redirect straight into the edit form of the new copy.
                    window.location.href = opteeData.dashboardUrl + '?module=rules&action=edit&id=' + res.data.rule_id;
                } else {
                    Optee.Toast.show((res.data && res.data.message) || 'Failed to duplicate rule.', 'error');
                    $btn.prop('disabled', false).text(origText);
                }
            }).fail(function () {
                Optee.Toast.show('Failed to duplicate rule.', 'error');
                $btn.prop('disabled', false).text(origText);
            });
        },

        toggleRule: function (id) {
            Optee.Ajax.post('optee_toggle_rule', { rule_id: id }).done(function (res) {
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    Rules.loadList();
                } else {
                    Optee.Toast.show(res.data.message, 'error');
                }
            });
        },

        /* ── Snooze (temporary per-rule pause) ── */

        // Preset snooze durations shown in the menu.
        snoozePresets: [
            { label: '6 hours', hours: 6 },
            { label: '12 hours', hours: 12 },
            { label: '1 day', hours: 24 },
            { label: '2 days', hours: 48 },
            { label: '3 days', hours: 72 },
            { label: '7 days', hours: 168 }
        ],

        // Snooze/alarm icon (Lucide alarm-clock).
        snoozeIcon: '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6"/><path d="m22 6-3-3"/><path d="M6.38 18.7 4 21"/><path d="M17.64 18.67 20 21"/></svg>',

        /**
         * Build the Snooze cell/inline control for a rule row.
         * - Snoozed: amber pill showing remaining time (click to manage/resume).
         * - Enabled & not snoozed: ghost "Snooze" button.
         * - Disabled (Off): a muted dash — snoozing a disabled rule is moot.
         */
        buildSnoozeControl: function (r) {
            if (!r.enabled && !r.snoozed) {
                return '<span class="text-gray-300" title="Rule is off">—</span>';
            }
            if (r.snoozed) {
                var remain = Rules.formatRemaining(r.snooze_remaining);
                return '<button type="button" class="optee-snooze-btn optee-badge-yellow" data-rule-id="' + r.id +
                    '" data-snoozed="1" style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;border:none;" title="Snoozed — resumes in ' + Optee.Util.escapeHtml(remain) + '. Click to change or resume.">' +
                    Rules.snoozeIcon + '<span>' + Optee.Util.escapeHtml(remain) + '</span></button>';
            }
            return '<button type="button" class="optee-snooze-btn optee-btn-ghost optee-btn-sm" data-rule-id="' + r.id +
                '" data-snoozed="0" style="display:inline-flex;align-items:center;gap:4px;" title="Pause this rule temporarily, then auto-resume">' +
                Rules.snoozeIcon + '<span>Snooze</span></button>';
        },

        /**
         * VirusTotal score badge(s) for a rule whose conditions watch a domain.
         *
         * Colours, label and tooltip all arrive from the server (RuleCondition::
         * vt_badge_*) so this list and the campaign report can never disagree about
         * what a state means. A `not watched` badge is the important one: it marks a
         * rule whose target has left the watch list, which cannot evaluate and will
         * never fire.
         */
        vtBadges: function (r, extraStyle) {
            if (!r.vt || !r.vt.length) return '';
            var out = '';
            for (var i = 0; i < r.vt.length; i++) {
                var b = r.vt[i];
                var s = b.style || {};
                out += '<a href="' + opteeData.dashboardUrl + '?module=virustotal"' +
                    ' title="' + Optee.Util.escapeHtml(b.title || '') + '"' +
                    ' style="display:inline-block;font-size:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;' +
                    'font-weight:600;padding:1px 6px;border-radius:5px;text-decoration:none;white-space:nowrap;' +
                    'background:' + (s.bg || '#f3f4f6') + ';color:' + (s.fg || '#6b7280') + ';border:' + (s.border || 'none') + ';' +
                    (extraStyle || '') + '">' +
                    Optee.Util.escapeHtml(b.host || '') + ' ' + Optee.Util.escapeHtml(b.label || '') +
                    '</a>';
            }
            return out;
        },

        openSnoozeMenu: function ($btn) {
            Rules.closeSnoozeMenu();

            var ruleId = $btn.data('rule-id');
            var isSnoozed = String($btn.data('snoozed')) === '1';

            var items = '';
            if (isSnoozed) {
                items += '<button type="button" class="optee-snooze-option" data-rule-id="' + ruleId + '" data-hours="0" ' +
                    'style="display:flex;align-items:center;gap:8px;width:100%;text-align:left;padding:7px 12px;border:none;background:none;cursor:pointer;font-size:13px;color:#16a34a;font-weight:600;">' +
                    'Resume now</button>';
                items += '<div style="height:1px;background:#f3f4f6;margin:4px 0;"></div>';
                items += '<div style="padding:4px 12px 2px;font-size:10px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:#9ca3af;">Snooze again for</div>';
            } else {
                items += '<div style="padding:4px 12px 2px;font-size:10px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:#9ca3af;">Pause rule for</div>';
            }

            $.each(Rules.snoozePresets, function (_, p) {
                items += '<button type="button" class="optee-snooze-option" data-rule-id="' + ruleId + '" data-hours="' + p.hours + '" ' +
                    'style="display:flex;align-items:center;gap:8px;width:100%;text-align:left;padding:7px 12px;border:none;background:none;cursor:pointer;font-size:13px;color:#374151;" ' +
                    'onmouseover="this.style.background=\'#f9fafb\'" onmouseout="this.style.background=\'none\'">' +
                    Optee.Util.escapeHtml(p.label) + '</button>';
            });

            var $menu = $('<div class="optee-snooze-menu" style="position:fixed;z-index:9999;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.12);padding:4px 0;min-width:170px;"></div>').html(items);
            $('body').append($menu);

            // Position below the button, flipping up/left if it would overflow.
            var rect = $btn[0].getBoundingClientRect();
            var mw = $menu.outerWidth();
            var mh = $menu.outerHeight();
            var top = rect.bottom + 4;
            var left = rect.left;
            if (left + mw > window.innerWidth - 8) left = window.innerWidth - mw - 8;
            if (top + mh > window.innerHeight - 8) top = rect.top - mh - 4;
            $menu.css({ top: Math.max(8, top) + 'px', left: Math.max(8, left) + 'px' });
        },

        closeSnoozeMenu: function () {
            $('.optee-snooze-menu').remove();
        },

        snoozeRule: function (id, hours) {
            Optee.Ajax.post('optee_snooze_rule', { rule_id: id, hours: hours }).done(function (res) {
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    Rules.loadList();
                } else {
                    Optee.Toast.show((res.data && res.data.message) || 'Snooze failed.', 'error');
                }
            }).fail(function () {
                Optee.Toast.show('Snooze failed.', 'error');
            });
        },

        // Seconds → compact "2d 4h" / "6h 30m" / "45m".
        formatRemaining: function (secs) {
            secs = parseInt(secs, 10) || 0;
            if (secs <= 0) return '';
            var d = Math.floor(secs / 86400);
            var h = Math.floor((secs % 86400) / 3600);
            var m = Math.floor((secs % 3600) / 60);
            if (d > 0) return d + 'd' + (h > 0 ? ' ' + h + 'h' : '');
            if (h > 0) return h + 'h' + (m > 0 ? ' ' + m + 'm' : '');
            return m + 'm';
        },

        runRule: function (id, $btn) {
            var origText = $btn.text();
            $btn.prop('disabled', true).text('Running...');

            // Show modal immediately with skeleton loader.
            var skeletonContent =
                '<div style="animation:pulse 1.5s ease-in-out infinite;">' +
                '<div style="height:14px;width:45%;background:#e5e7eb;border-radius:4px;margin-bottom:16px;"></div>' +
                '<div style="display:flex;flex-direction:column;gap:6px;">' +
                '<div style="height:22px;background:#f3f4f6;border-radius:4px;"></div>' +
                '<div style="height:22px;background:#f3f4f6;border-radius:4px;"></div>' +
                '<div style="height:22px;background:#f3f4f6;border-radius:4px;"></div>' +
                '<div style="height:22px;background:#f3f4f6;border-radius:4px;width:60%;"></div>' +
                '</div></div>' +
                '<style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}</style>';

            var modal = '<div id="optee-run-modal" class="optee-modal-overlay" style="display:flex;">' +
                '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);" id="optee-run-backdrop"></div>' +
                '<div id="optee-run-inner" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:560px;max-width:95vw;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button id="optee-run-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                '<div style="font-weight:600;font-size:14px;margin-bottom:16px;">Rule Execution Result</div>' +
                skeletonContent +
                '</div></div>';

            $('body').append(modal);
            $('#optee-run-close, #optee-run-backdrop').on('click', function () {
                $('#optee-run-modal').remove();
            });

            Optee.Ajax.post('optee_run_rule', { rule_id: id }).done(function (res) {
                $btn.prop('disabled', false).text(origText);
                Rules.loadList();
                if (window.opteeRefreshNotifications) window.opteeRefreshNotifications();

                if (!res.success) {
                    $('#optee-run-modal').remove();
                    Optee.Toast.show(res.data.message || 'Rule execution failed.', 'error');
                    return;
                }

                var r = res.data.result || {};
                var body = '';

                // Rule name header (matches the dry-run preview).
                if (res.data.rule_name) {
                    body += '<div style="margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #f0f0f0;">' +
                        '<div class="text-xs font-semibold text-gray-400 uppercase" style="margin-bottom:2px;">Rule</div>' +
                        '<div style="font-weight:600;font-size:14px;color:#111827;">' + Optee.Util.escapeHtml(res.data.rule_name) + '</div>' +
                        '</div>';
                }

                // Action, timeframe, conditions header.
                var runAction = res.data.action || '';
                var runTf = res.data.timeframe || '';
                var runConds = res.data.conditions || [];
                if (runAction) {
                    var runActionLabel = actionLabels[runAction] || runAction;
                    // Show the targeting dimension too (e.g. "Remove Targeting · Connection Speed").
                    if (runAction === 'remove_targeting' && res.data.action_params && res.data.action_params.dimension) {
                        runActionLabel += ' · ' + Rules.targetingDimLabel(res.data.action_params.dimension);
                    }
                    body += '<div style="margin-bottom:12px;">';
                    body += '<span class="text-sm text-gray-500">Action:</span> <strong>' + Optee.Util.escapeHtml(runActionLabel) + '</strong>';
                    body += ' &nbsp;|&nbsp; <span class="text-sm text-gray-500">Timeframe:</span> <strong>' + Optee.Util.escapeHtml(timeframeLabels[runTf] || runTf) + '</strong>';
                    body += '</div>';
                }
                if (runConds.length) {
                    body += '<div style="margin-bottom:12px;padding:8px 12px;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">';
                    body += '<div class="text-xs font-semibold text-gray-400 uppercase" style="margin-bottom:4px;">Conditions</div>';
                    for (var ci = 0; ci < runConds.length; ci++) {
                        var cond = runConds[ci];
                        if (cond.source === 'affiliate_network' && cond.metric === 'offer_status') {
                            var statusLabel = cond.value === 'running' ? 'Running' : 'Paused';
                            body += '<div class="text-sm" style="padding:1px 0;">' +
                                '<span class="text-gray-400">IF</span> ' +
                                '<span class="font-medium">Affiliate Network</span> Offer Status ' +
                                '<span class="text-gray-400">is</span> ' +
                                '<strong>' + Optee.Util.escapeHtml(statusLabel) + '</strong>' +
                                '</div>';
                            continue;
                        }
                        var srcLabel = condSourceLabel(cond);
                        var allMetrics = metricsBySource[cond.source || 'traffic_source'] || {};
                        var metLabel = allMetrics[cond.metric] || cond.metric;
                        var opLabel = operatorLabels[cond.operator] || cond.operator;
                        body += '<div class="text-sm" style="padding:1px 0;">' +
                            '<span class="text-gray-400">IF</span> ' +
                            '<span class="font-medium">' + Optee.Util.escapeHtml(srcLabel) + '</span> ' +
                            Optee.Util.escapeHtml(metLabel) + ' ' +
                            '<span class="text-gray-400">is</span> ' +
                            Optee.Util.escapeHtml(opLabel) + ' ' +
                            '<strong>' + Optee.Util.escapeHtml(String(cond.value)) + '</strong>' +
                            '</div>';
                    }
                    body += '</div>';
                }

                // Offer status info (from offer_status rules).
                if (r.offer_name || r.offer_status) {
                    var osName = r.offer_name || '(unknown)';
                    var osStatus = r.offer_status || 'unknown';
                    var osColor = osStatus === 'active' ? '#16a34a' : (osStatus === 'paused' ? '#f59e0b' : '#ef4444');
                    body += '<div style="margin-bottom:12px;padding:10px 14px;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">';
                    body += '<div class="text-sm"><span class="text-gray-500">Offer:</span> <strong>' + Optee.Util.escapeHtml(osName) + '</strong></div>';
                    body += '<div class="text-sm"><span class="text-gray-500">Current Status:</span> <strong style="color:' + osColor + ';">' + Optee.Util.escapeHtml(osStatus.charAt(0).toUpperCase() + osStatus.slice(1)) + '</strong></div>';
                    body += '</div>';
                }

                // Status header.
                if (r.status === 'executed') {
                    body += '<div style="margin-bottom:12px;color:#16a34a;font-weight:600;">' + Optee.Util.escapeHtml(r.message || 'Executed') + '</div>';
                } else if (r.status === 'no_match') {
                    body += '<div style="margin-bottom:12px;color:#9ca3af;">' + Optee.Util.escapeHtml(r.message || 'No match') + '</div>';
                } else if (r.status === 'failed') {
                    body += '<div style="margin-bottom:12px;color:#ef4444;font-weight:600;">' + Optee.Util.escapeHtml(r.message || 'Failed') + '</div>';
                } else {
                    body += '<div style="margin-bottom:12px;">' + Optee.Util.escapeHtml(res.data.message || 'Done') + '</div>';
                }

                // Show fetch errors (e.g. rate limit).
                if (r.errors && r.errors.length) {
                    for (var ei = 0; ei < r.errors.length; ei++) {
                        body += '<div style="color:#dc2626;font-size:13px;margin-bottom:4px;">\u26A0 ' + Optee.Util.escapeHtml(r.errors[ei]) + '</div>';
                    }
                }

                // Matching zones (or countries for remove_countries).
                var itemNoun = runAction === 'remove_countries' ? 'countries' : (runAction === 'remove_targeting' ? 'values' : 'zones');
                if (r.matching_zones && r.matching_zones.length) {
                    body += '<div class="text-sm" style="margin-bottom:8px;"><strong>Affected ' + itemNoun + ' (' + r.matching_zones.length + '):</strong></div>';
                    body += '<div style="max-height:200px;overflow-y:auto;margin-bottom:12px;">';
                    for (var i = 0; i < r.matching_zones.length; i++) {
                        body += '<div class="text-sm" style="padding:1px 0;"><span class="optee-badge-green" style="font-size:10px;">MATCH</span> ' + Optee.Util.escapeHtml(r.matching_zones[i]) + '</div>';
                    }
                    body += '</div>';
                }

                // Zone details (non-matching, collapsed). Hide when fetch errors occurred (data is incomplete).
                if (r.details && r.details.length && !(r.errors && r.errors.length)) {
                    var nonMatching = [];
                    for (var j = 0; j < r.details.length; j++) {
                        if (!r.details[j].passed) nonMatching.push(r.details[j]);
                    }
                    if (nonMatching.length) {
                        body += '<details style="margin-top:8px;"><summary class="text-xs text-gray-400 cursor-pointer">' + nonMatching.length + ' ' + itemNoun + ' did not match</summary>';
                        body += '<div style="max-height:150px;overflow-y:auto;margin-top:4px;">';
                        for (var k = 0; k < nonMatching.length; k++) {
                            var z = nonMatching[k];
                            var condStr = '';
                            if (z.details) {
                                for (var c = 0; c < z.details.length; c++) {
                                    var cd = z.details[c];
                                    var val = formatMetricValue(cd.metric, cd.actual);
                                    condStr += (metricLabels[cd.metric] || cd.metric) + ': ' + val + (cd.passed ? ' ✓' : ' ✗') + '  ';
                                }
                            }
                            body += '<div class="text-xs text-gray-400" style="padding:1px 0;">' + Optee.Util.escapeHtml(z.zone_id || '?') + ' — ' + Optee.Util.escapeHtml(condStr) + '</div>';
                        }
                        body += '</div></details>';
                    }
                }

                // Campaign-level result details.
                if (!r.matching_zones && r.details && r.details.length) {
                    body += '<div style="margin-top:8px;">';
                    for (var m = 0; m < r.details.length; m++) {
                        var det = r.details[m];
                        var icon = det.passed ? '<span style="color:#16a34a;">✓</span>' : '<span style="color:#ef4444;">✗</span>';
                        var actual = formatMetricValue(det.metric, det.actual);
                        body += '<div class="text-sm" style="padding:2px 0;">' + icon + ' ' + Optee.Util.escapeHtml(metricLabels[det.metric] || det.metric) + ' ' + Optee.Util.escapeHtml(operatorLabels[det.operator] || det.operator) + ' ' + det.expected + ' (actual: ' + actual + ')' + detailSuffix(det) + '</div>';
                    }
                    body += '</div>';
                }

                // Replace skeleton with actual content.
                var $inner = $('#optee-run-inner');
                $inner.html(
                    '<button id="optee-run-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                    '<div style="font-weight:600;font-size:14px;margin-bottom:16px;">Rule Execution Result</div>' +
                    body +
                    '<div style="margin-top:16px;"><button id="optee-run-done" class="optee-btn-secondary">Close</button></div>'
                );

                $('#optee-run-close, #optee-run-done').on('click', function () {
                    $('#optee-run-modal').remove();
                });

            }).fail(function () {
                $btn.prop('disabled', false).text(origText);
                $('#optee-run-modal').remove();
                Optee.Toast.show('Request failed.', 'error');
            });
        },

        /* ── Rules List (grouped) ── */

        loadList: function () {
            var $container = $('#optee-rules-grouped');
            if (!$container.length) return;

            $container.html('<div class="text-center py-12 text-gray-400">Loading rules...</div>');

            // Check for ?new_campaign= param to highlight.
            var urlParams = new URLSearchParams(window.location.search);
            var newCampaignId = parseInt(urlParams.get('new_campaign')) || 0;

            var filterCampaignId = parseInt($container.data('filter-campaign')) || 0;
            var postData = { grouped: 1 };
            if (filterCampaignId) postData.campaign_id = filterCampaignId;

            Optee.Ajax.post('optee_get_rules', postData).done(function (res) {
                if (!res.success || !res.data.campaigns || !res.data.campaigns.length) {
                    var emptyHtml = '<div class="optee-card text-center py-12">';
                    if (filterCampaignId) {
                        emptyHtml += '<div class="text-gray-400 mb-3">No rules found for this campaign.</div>';
                        emptyHtml += '<a href="' + opteeData.dashboardUrl + '?module=rules&action=create&campaign_id=' + filterCampaignId + '" class="optee-btn-primary optee-btn-sm">+ Add Rule</a>';
                    } else {
                        emptyHtml += '<div class="text-gray-400">No rules found. Create your first rule from a campaign\'s actions menu.</div>';
                    }
                    emptyHtml += '</div>';
                    $container.html(emptyHtml);
                    return;
                }

                var allGroups = res.data.campaigns;

                // Filter out paused campaigns (unless filtered by specific campaign).
                var groups = [];
                $.each(allGroups, function (_, g) {
                    if (!filterCampaignId && g.campaign_status === 'paused') return;
                    groups.push(g);
                });

                // Sort: new_campaign on top.
                if (newCampaignId) {
                    groups.sort(function (a, b) {
                        if (a.campaign_id === newCampaignId) return -1;
                        if (b.campaign_id === newCampaignId) return 1;
                        return 0;
                    });
                }

                var html = '';
                $.each(groups, function (i, group) {
                    var statusClass = group.campaign_status === 'paused' ? 'optee-badge-yellow' : 'optee-badge-green';
                    var statusLabel = group.campaign_status === 'paused' ? 'Paused' : 'Active';
                    var isNew = newCampaignId && group.campaign_id === newCampaignId;

                    html += '<div class="optee-rules-campaign-group optee-card p-0 overflow-hidden mb-4">';
                    html += '<div class="optee-rules-campaign-toggle flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-gray-50 transition-colors">';
                    html += '<svg class="optee-rules-chevron rotate-90 transition-transform text-gray-400" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
                    html += '<span class="font-semibold text-gray-900">' + Optee.Util.escapeHtml(group.campaign_name) + '</span>';
                    html += '<span class="' + statusClass + '">' + statusLabel + '</span>';
                    html += '<span class="text-xs text-gray-400">' + group.rules.length + ' rule(s)</span>';
                    if (isNew) {
                        html += '<span class="optee-badge-blue">Recently added</span>';
                    }
                    html += '<a href="' + opteeData.dashboardUrl + '?module=dashboard&action=campaign&id=' + group.campaign_id + '" class="ml-auto text-xs text-gray-400" style="text-decoration:none;" onmouseover="this.style.color=\'#4b5563\'" onmouseout="this.style.color=\'\'">View Campaign Stats</a>';
                    html += '<a href="' + opteeData.dashboardUrl + '?module=rules&action=create&campaign_id=' + group.campaign_id + '" class="optee-btn-primary optee-btn-sm" style="font-size:12px;padding:4px 12px;margin-left:8px;">+ Add Rule</a>';
                    html += '<button type="button" class="optee-mob-show-rules" style="width:100%;background:none;border:1px solid #e5e7eb;border-radius:6px;color:#6b7280;font-size:14px;font-weight:500;padding:8px 10px;cursor:pointer;margin-top:6px;">Show Rules</button>';
                    html += '</div>';

                    html += '<div class="optee-rules-campaign-body">';

                    // Desktop table.
                    html += '<div class="optee-rules-table-wrap"><table class="optee-table"><thead><tr>';
                    html += '<th>Rule</th><th>Action</th><th>Run Every</th><th>Timeframe</th><th>Last Run</th><th>Status</th><th>Snooze</th><th>Last Result</th><th class="text-right">Actions</th>';
                    html += '</tr></thead><tbody>';

                    // Mobile cards container.
                    var mobHtml = '<div class="optee-rules-cards-wrap">';

                    $.each(group.rules, function (j, r) {
                        var editUrl = opteeData.dashboardUrl + '?module=rules&action=edit&id=' + r.id;
                        var enabledBadge = r.enabled
                            ? '<span class="optee-badge-green">On</span>'
                            : '<span class="optee-badge-red">Off</span>';
                        var actionLabel = actionLabels[r.action] || r.action.replace(/_/g, ' ');
                        // For Remove Targeting, show which dimension it acts on.
                        if (r.action === 'remove_targeting' && r.action_params && r.action_params.dimension) {
                            actionLabel += ' · ' + (Rules.targetingDimLabel(r.action_params.dimension));
                        }
                        var tfLabel = timeframeLabels[r.timeframe] || r.timeframe;

                        var lastRun = r.last_run ? Rules.relativeTime(r.last_run) : 'Never';

                        var resultSummary = '-';
                        var hasResult = r.last_run_result && r.last_run_result.status;
                        if (hasResult) {
                            var lr = r.last_run_result;
                            var resultMsg = lr.message || lr.status || '';
                            var shortMsg = resultMsg.length > 28 ? resultMsg.substring(0, 28) + '...' : resultMsg;
                            if (lr.status === 'executed') {
                                resultSummary = '<span class="text-green-600 cursor-pointer optee-rule-result-toggle" title="' + Optee.Util.escapeHtml(resultMsg) + '">' + Optee.Util.escapeHtml(shortMsg) + '</span>';
                            } else if (lr.status === 'no_match') {
                                resultSummary = '<span class="text-gray-400 cursor-pointer optee-rule-result-toggle" title="' + Optee.Util.escapeHtml(resultMsg) + '">' + Optee.Util.escapeHtml(shortMsg) + '</span>';
                            } else if (lr.status === 'failed') {
                                resultSummary = '<span class="text-red-500 cursor-pointer optee-rule-result-toggle" title="' + Optee.Util.escapeHtml(resultMsg) + '">' + Optee.Util.escapeHtml(shortMsg) + '</span>';
                            } else {
                                resultSummary = '<span class="text-gray-400">' + Optee.Util.escapeHtml(shortMsg) + '</span>';
                            }
                        }

                        var templateBadge = r.is_template ? ' <span class="optee-badge-blue">Template</span>' : '';

                        // Desktop table row.
                        html += '<tr>';
                        var vtDesktop = Rules.vtBadges(r, 'margin-top:4px;margin-right:4px;');
                        html += '<td style="max-width:280px;"><div style="width:280px;white-space:normal;word-break:break-word;"><a href="' + editUrl + '" class="text-gray-900 hover:text-brand-600 font-medium">' + Optee.Util.escapeHtml(r.title) + '</a>' + templateBadge +
                            (vtDesktop ? '<div>' + vtDesktop + '</div>' : '') + '</div></td>';
                        html += '<td class="text-sm">' + Optee.Util.escapeHtml(actionLabel) + '</td>';
                        html += '<td class="text-sm">' + Rules.formatInterval(r.interval) + '</td>';
                        html += '<td class="text-sm">' + Optee.Util.escapeHtml(tfLabel) + '</td>';
                        html += '<td class="text-sm text-gray-500">' + Optee.Util.escapeHtml(lastRun) + '</td>';
                        html += '<td><button class="optee-toggle-rule cursor-pointer" data-rule-id="' + r.id + '" data-rule-enabled="' + (r.enabled ? '1' : '0') + '">' + enabledBadge + '</button></td>';
                        html += '<td>' + Rules.buildSnoozeControl(r) + '</td>';
                        html += '<td class="text-sm" style="max-width:200px;padding-right:20px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + resultSummary + '</td>';
                        html += '<td class="text-right" style="white-space:nowrap;"><div class="flex items-center justify-end gap-1">' +
                            '<button class="optee-btn-ghost optee-btn-sm optee-preview-rule" data-rule-id="' + r.id + '">Preview</button>' +
                            '<button class="optee-btn-ghost optee-btn-sm optee-run-rule" data-rule-id="' + r.id + '">Run Now</button>' +
                            '<a href="' + editUrl + '" class="optee-btn-ghost optee-btn-sm">Edit</a>' +
                            '<button class="optee-btn-ghost optee-btn-sm optee-duplicate-rule" data-rule-id="' + r.id + '">Duplicate</button>' +
                            '<button class="optee-delete-rule-btn" data-rule-id="' + r.id + '" title="Delete rule" style="background:none;border:none;cursor:pointer;padding:4px;color:#9ca3af;margin-left:2px;" onmouseover="this.style.color=\'#ef4444\'" onmouseout="this.style.color=\'#9ca3af\'">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>' +
                            '</button></div></td>';
                        html += '</tr>';

                        // Expandable result detail row (hidden by default).
                        if (hasResult && lr.details && lr.details.length) {
                            html += '<tr class="optee-rule-result-detail" style="display:none;"><td colspan="9" class="bg-gray-50 px-6 py-3">';
                            html += '<div class="text-xs text-gray-500 space-y-1">';
                            if (lr.matching_zones && lr.matching_zones.length) {
                                html += '<div><strong>Matching zones:</strong> ' + Optee.Util.escapeHtml(lr.matching_zones.join(', ')) + '</div>';
                            }
                            var evals = lr.details.slice(0, 10);
                            for (var k = 0; k < evals.length; k++) {
                                var ev = evals[k];
                                var badge = ev.passed
                                    ? '<span class="optee-badge-green" style="font-size:10px;">MATCH</span>'
                                    : '<span class="optee-badge-gray" style="font-size:10px;">no match</span>';
                                html += '<div>Zone ' + Optee.Util.escapeHtml(ev.zone_id || '?') + ': ' + badge + '</div>';
                            }
                            if (lr.details.length > 10) {
                                html += '<div class="text-gray-400">...and ' + (lr.details.length - 10) + ' more</div>';
                            }
                            html += '</div></td></tr>';
                        }

                        // Mobile card.
                        mobHtml += '<div class="optee-mob-card" style="padding:14px 16px;border-bottom:1px solid #f3f4f6;">';
                        mobHtml += '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#9ca3af;margin-bottom:8px;">Rule #' + (j + 1) + '</div>';
                        // Row 1: status + action + interval
                        mobHtml += '<div class="flex items-center gap-2" style="font-size:13px;">';
                        mobHtml += '<button class="optee-toggle-rule cursor-pointer" data-rule-id="' + r.id + '" data-rule-enabled="' + (r.enabled ? '1' : '0') + '">' + enabledBadge + '</button>';
                        mobHtml += Rules.buildSnoozeControl(r);
                        mobHtml += '<span class="text-gray-500">' + Optee.Util.escapeHtml(actionLabel) + '</span>';
                        mobHtml += templateBadge;
                        mobHtml += '</div>';
                        // Row 2: rule title
                        mobHtml += '<a href="' + editUrl + '" class="block font-semibold text-gray-900 hover:text-brand-600 mt-2" style="font-size:17px;">' + Optee.Util.escapeHtml(r.title) + '</a>';
                        // Row 2b: VirusTotal score for the domain this rule watches.
                        var vtMob = Rules.vtBadges(r, 'margin-right:4px;font-size:11px;padding:2px 7px;');
                        if (vtMob) {
                            mobHtml += '<div class="mt-2">' + vtMob + '</div>';
                        }
                        // Row 3: meta info
                        mobHtml += '<div class="grid mt-3" style="grid-template-columns:repeat(3,1fr);gap:8px 12px;">';
                        mobHtml += '<div><span class="font-bold text-gray-900">' + Rules.formatInterval(r.interval) + '</span> <span class="text-gray-400">Every</span></div>';
                        mobHtml += '<div><span class="font-bold text-gray-900">' + Optee.Util.escapeHtml(tfLabel) + '</span> <span class="text-gray-400">Window</span></div>';
                        mobHtml += '<div><span class="font-bold text-gray-900">' + Optee.Util.escapeHtml(lastRun) + '</span> <span class="text-gray-400">Last Run</span></div>';
                        mobHtml += '</div>';
                        // Row 4: last result (if any)
                        if (hasResult) {
                            mobHtml += '<div class="mt-2 text-sm">' + resultSummary + '</div>';
                        }
                        // Row 5: actions
                        mobHtml += '<div style="margin-top:16px;padding-top:10px;border-top:1px solid #e5e7eb;">';
                        mobHtml += '<button type="button" class="optee-mob-actions-toggle" style="background:none;border:1px solid #e5e7eb;border-radius:6px;color:#6b7280;font-size:12px;font-weight:500;padding:6px 10px;cursor:pointer;width:100%;">Show Actions</button>';
                        mobHtml += '<div class="optee-mob-actions flex flex-wrap items-center gap-2 mt-2" style="display:none;">';
                        mobHtml += '<button class="optee-mob-action-btn optee-preview-rule" data-rule-id="' + r.id + '"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg> Preview</button>';
                        mobHtml += '<button class="optee-mob-action-btn optee-run-rule" data-rule-id="' + r.id + '"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg> Run Now</button>';
                        mobHtml += '<a href="' + editUrl + '" class="optee-mob-action-btn"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg> Edit</a>';
                        mobHtml += '<button class="optee-mob-action-btn optee-duplicate-rule" data-rule-id="' + r.id + '"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg> Duplicate</button>';
                        mobHtml += '<button class="optee-mob-action-btn optee-delete-rule-btn" data-rule-id="' + r.id + '"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg> Delete</button>';
                        mobHtml += '</div></div>';
                        mobHtml += '</div>';
                    });

                    html += '</tbody></table></div>';
                    mobHtml += '</div>';
                    html += mobHtml;
                    html += '</div></div>';
                });

                $container.html(html);

                // Summary stats (count only visible/active campaign groups).
                var activeRules = 0, totalRules = 0;
                $.each(groups, function (_, g) {
                    $.each(g.rules, function (_, r) {
                        totalRules++;
                        if (r.enabled) activeRules++;
                    });
                });
                var $summary = $('#optee-rules-summary');
                if (totalRules) {
                    $summary.html(
                        groups.length + ' active campaign' + (groups.length !== 1 ? 's' : '') +
                        ' &nbsp;&middot;&nbsp; ' +
                        activeRules + ' of ' + totalRules + ' rule' + (totalRules !== 1 ? 's' : '') + ' enabled'
                    ).show();
                }
            }).fail(function () {
                $container.html('<div class="optee-card text-center py-12 text-red-400">Failed to load rules.</div>');
            });
        },

        /* ── Import from Template ── */

        importTemplate: function () {
            Optee.Ajax.post('optee_get_rule_templates', {}).done(function (res) {
                if (!res.success || !res.data.templates.length) {
                    Optee.Toast.show('No templates or existing rules to import from.', 'info');
                    return;
                }

                // Build a simple dropdown modal.
                var options = '';
                $.each(res.data.templates, function (i, t) {
                    options += '<option value="' + i + '">' + Optee.Util.escapeHtml(t.label) + '</option>';
                });

                var html = '<div id="optee-import-modal" class="optee-modal-overlay" style="display:flex;">' +
                    '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);" id="optee-import-backdrop"></div>' +
                    '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                    '<div style="font-weight:600;font-size:14px;margin-bottom:12px;">Import Rule</div>' +
                    '<select id="optee-import-select" class="optee-select w-full mb-4">' + options + '</select>' +
                    '<div class="flex gap-2">' +
                    '<button id="optee-import-confirm" class="optee-btn-primary">Import</button>' +
                    '<button id="optee-import-cancel" class="optee-btn-secondary">Cancel</button>' +
                    '</div></div></div>';

                $('body').append(html);

                var templates = res.data.templates;

                $('#optee-import-confirm').on('click', function () {
                    var idx = parseInt($('#optee-import-select').val());
                    var t = templates[idx];
                    if (!t) return;

                    // Fill form fields.
                    $('#rule-title').val(t.title + ' (copy)');
                    $('#rule-action').val(t.action);
                    $('[name="timeframe"]').val(t.timeframe);
                    $('[name="interval"]').val(t.interval);
                    $('[name="enabled"][value="' + (t.enabled ? '1' : '0') + '"]').prop('checked', true);
                    $('[name="is_template"][value="0"]').prop('checked', true);

                    // On success checkboxes.
                    $('[name="on_success_pause_rule"]').prop('checked', t.on_success && t.on_success.indexOf('pause_rule') !== -1);
                    $('[name="on_success_pause_campaign"]').prop('checked', t.on_success && t.on_success.indexOf('pause_campaign') !== -1);

                    // Rebuild conditions.
                    $('#rule-conditions').empty();
                    if (t.conditions && t.conditions.length) {
                        for (var i = 0; i < t.conditions.length; i++) {
                            Rules.addConditionRow(t.conditions[i]);
                        }
                    } else {
                        Rules.addConditionRow();
                    }

                    $('#optee-import-modal').remove();
                    Optee.Toast.show('Rule imported. Review and save.', 'success');
                });

                $('#optee-import-cancel, #optee-import-backdrop').on('click', function () {
                    $('#optee-import-modal').remove();
                });
            }).fail(function () {
                Optee.Toast.show('Failed to load templates.', 'error');
            });
        },

        /* ── Preview ── */

        previewRule: function (ruleId, $btn) {
            var origText = $btn.text();
            $btn.prop('disabled', true).text('Loading...');
            Rules.runPreview('optee_preview_rule', { rule_id: ruleId }, function () {
                $btn.prop('disabled', false).text(origText);
            });
        },

        // Dry-run an UNSAVED rule straight from the create/edit form — evaluates the
        // current form values server-side without persisting anything.
        previewDraft: function ($form, $btn) {
            var origText = $btn.text();
            $btn.prop('disabled', true).text('Running...');
            Rules.runPreview('optee_preview_rule_draft', {
                campaign_id: $form.find('[name="campaign_id"]').val(),
                rule_action: $form.find('[name="rule_action"]').val(),
                conditions: JSON.stringify(Rules.collectConditions($form)),
                action_params: JSON.stringify(Rules.collectActionParams($form)),
                timeframe: $form.find('[name="timeframe"]').val(),
                title: $form.find('[name="title"]').val()
            }, function () {
                $btn.prop('disabled', false).text(origText);
            });
        },

        // Shared preview runner: open the skeleton modal, POST, render the result.
        runPreview: function (ajaxAction, ajaxData, restore) {

            // Show modal immediately with skeleton loader.
            var skeletonContent =
                '<div style="animation:pulse 1.5s ease-in-out infinite;">' +
                '<div style="height:14px;width:55%;background:#e5e7eb;border-radius:4px;margin-bottom:16px;"></div>' +
                '<div style="height:60px;background:#f3f4f6;border-radius:6px;margin-bottom:16px;"></div>' +
                '<div style="height:14px;width:40%;background:#e5e7eb;border-radius:4px;margin-bottom:12px;"></div>' +
                '<div style="display:flex;flex-direction:column;gap:6px;">' +
                '<div style="height:22px;background:#f3f4f6;border-radius:4px;"></div>' +
                '<div style="height:22px;background:#f3f4f6;border-radius:4px;"></div>' +
                '<div style="height:22px;background:#f3f4f6;border-radius:4px;"></div>' +
                '<div style="height:22px;background:#f3f4f6;border-radius:4px;"></div>' +
                '<div style="height:22px;background:#f3f4f6;border-radius:4px;width:70%;"></div>' +
                '</div></div>' +
                '<style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}</style>';

            var modal = '<div id="optee-preview-modal" class="optee-modal-overlay" style="display:flex;">' +
                '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);" id="optee-preview-backdrop"></div>' +
                '<div id="optee-preview-inner" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:8px;padding:24px;width:560px;max-width:95vw;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.15);">' +
                '<button id="optee-preview-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                '<div style="font-weight:600;font-size:14px;margin-bottom:16px;">Rule Preview (dry run)</div>' +
                skeletonContent +
                '</div></div>';

            $('body').append(modal);
            $('#optee-preview-close, #optee-preview-backdrop').on('click', function () {
                $('#optee-preview-modal').remove();
            });

            Optee.Ajax.post(ajaxAction, ajaxData).done(function (res) {
                restore();

                if (!res.success) {
                    $('#optee-preview-modal').remove();
                    Optee.Toast.show(res.data.message || 'Preview failed.', 'error');
                    return;
                }

                var d = res.data;
                var body = '';

                // Rule name header — so the preview says WHICH rule it's showing.
                if (d.rule_name) {
                    body += '<div style="margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #f0f0f0;">' +
                        '<div class="text-xs font-semibold text-gray-400 uppercase" style="margin-bottom:2px;">Rule</div>' +
                        '<div style="font-weight:600;font-size:14px;color:#111827;">' + Optee.Util.escapeHtml(d.rule_name) + '</div>' +
                        '</div>';
                }

                // Unit label for per-row (zone/country/dimension) previews.
                var unit = d.unit || 'zone';
                var unitPlural = unit + 's';

                // Build conditions summary.
                var condSummary = '';
                if (d.conditions && d.conditions.length) {
                    condSummary += '<div style="margin-bottom:12px;padding:8px 12px;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">';
                    condSummary += '<div class="text-xs font-semibold text-gray-400 uppercase" style="margin-bottom:4px;">Conditions</div>';
                    for (var ci = 0; ci < d.conditions.length; ci++) {
                        var cond = d.conditions[ci];
                        if (cond.source === 'affiliate_network' && cond.metric === 'offer_status') {
                            var statusLabel = cond.value === 'running' ? 'Running' : 'Paused';
                            condSummary += '<div class="text-sm" style="padding:1px 0;">' +
                                '<span class="text-gray-400">IF</span> ' +
                                '<span class="font-medium">Affiliate Network</span> Offer Status ' +
                                '<span class="text-gray-400">is</span> ' +
                                '<strong>' + Optee.Util.escapeHtml(statusLabel) + '</strong>' +
                                '</div>';
                            continue;
                        }
                        var srcLabel = condSourceLabel(cond);
                        var allMetrics = metricsBySource[cond.source || 'traffic_source'] || {};
                        var metricLabel = allMetrics[cond.metric] || cond.metric;
                        var opLabel = operatorLabels[cond.operator] || cond.operator;
                        condSummary += '<div class="text-sm" style="padding:1px 0;">' +
                            '<span class="text-gray-400">IF</span> ' +
                            '<span class="font-medium">' + Optee.Util.escapeHtml(srcLabel) + '</span> ' +
                            Optee.Util.escapeHtml(metricLabel) + ' ' +
                            '<span class="text-gray-400">is</span> ' +
                            Optee.Util.escapeHtml(opLabel) + ' ' +
                            '<strong>' + Optee.Util.escapeHtml(String(cond.value)) + '</strong>' +
                            '</div>';
                    }
                    condSummary += '</div>';
                }

                if (d.type === 'offer_status') {
                    var actionLabelOs = actionLabels[d.action] || d.action;
                    body += '<div style="margin-bottom:12px;">';
                    body += '<span class="text-sm text-gray-500">Action:</span> <strong>' + Optee.Util.escapeHtml(actionLabelOs) + '</strong>';
                    body += '</div>';
                    body += condSummary;

                    var offerName = d.offer_name || '(unknown)';
                    var offerStatus = d.offer_status || 'unknown';
                    var statusColor = offerStatus === 'active' ? '#16a34a' : (offerStatus === 'paused' ? '#f59e0b' : '#ef4444');
                    body += '<div style="margin-bottom:12px;padding:10px 14px;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">';
                    body += '<div class="text-sm"><span class="text-gray-500">Offer:</span> <strong>' + Optee.Util.escapeHtml(offerName) + '</strong></div>';
                    body += '<div class="text-sm"><span class="text-gray-500">Current Status:</span> <strong style="color:' + statusColor + ';">' + Optee.Util.escapeHtml(offerStatus.charAt(0).toUpperCase() + offerStatus.slice(1)) + '</strong></div>';
                    body += '</div>';

                    if (d.passed) {
                        body += '<div style="color:#16a34a;font-weight:600;margin-bottom:8px;">Condition met — action would execute.</div>';
                    } else {
                        body += '<div style="color:#9ca3af;margin-bottom:8px;">Condition not met — no action would be taken.</div>';
                    }
                } else if (d.type === 'zone') {
                    var actionLabel = actionLabels[d.action] || d.action;
                    if (d.action === 'remove_targeting' && d.dimension) {
                        actionLabel += ' · ' + Rules.targetingDimLabel(d.dimension);
                    }
                    body += '<div style="margin-bottom:12px;">';
                    body += '<span class="text-sm text-gray-500">Action:</span> <strong>' + Optee.Util.escapeHtml(actionLabel) + '</strong>';
                    body += ' &nbsp;|&nbsp; <span class="text-sm text-gray-500">Timeframe:</span> <strong>' + Optee.Util.escapeHtml(timeframeLabels[d.timeframe] || d.timeframe) + '</strong>';
                    body += '</div>';
                    body += condSummary;

                    // Warn when the campaign has no traffic source — the block/unblock
                    // can be matched against tracker data but cannot be pushed anywhere.
                    if (d.has_traffic_source === false) {
                        body += '<div style="margin-bottom:12px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:13px;color:#b91c1c;">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;margin-right:4px;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>' +
                            '<strong>This campaign has no traffic source connected.</strong> Zones below are matched from tracker data, but the ' +
                            (d.action === 'unblock_zone' || d.action === 'unblock_zones' ? 'unblock' : 'block') +
                            ' action has nowhere to run — a live run will fail with “Campaign has no traffic source configured.” Connect a traffic source to this campaign to use zone actions.</div>';
                    }

                    // Build a set of already-blocked zone IDs for diff display.
                    var blockedSet = {};
                    var isBlockAction = (d.action === 'block_zone' || d.action === 'block_zones');
                    var isUnblockAction = (d.action === 'unblock_zone' || d.action === 'unblock_zones');
                    if (d.blocked_zones && d.blocked_zones.length) {
                        for (var bi = 0; bi < d.blocked_zones.length; bi++) {
                            blockedSet[String(d.blocked_zones[bi])] = true;
                        }
                    }

                    if (d.matching_zones.length) {
                        // Count new vs already-blocked/not-blocked among matching zones.
                        var newZones = [];
                        var skipZones = [];
                        for (var mi = 0; mi < d.matching_zones.length; mi++) {
                            var zid = String(d.matching_zones[mi]);
                            if (isBlockAction && blockedSet[zid]) {
                                skipZones.push(zid);
                            } else if (isUnblockAction && !blockedSet[zid]) {
                                skipZones.push(zid);
                            } else {
                                newZones.push(zid);
                            }
                        }
                        var newSet = {};
                        for (var ni = 0; ni < newZones.length; ni++) newSet[newZones[ni]] = true;
                        var skipSet = {};
                        for (var si = 0; si < skipZones.length; si++) skipSet[skipZones[si]] = true;

                        var hasBlocked = d.blocked_zones && d.blocked_zones.length;
                        var headerText = d.matching_zones.length + ' of ' + d.total_zones + ' ' + unitPlural + ' match conditions';
                        if (hasBlocked && (isBlockAction || isUnblockAction) && skipZones.length > 0) {
                            if (isBlockAction) {
                                headerText += ' — <strong>' + newZones.length + ' new</strong>, ' + skipZones.length + ' already blocked';
                            } else {
                                headerText += ' — <strong>' + newZones.length + ' currently blocked</strong>, ' + skipZones.length + ' not on blacklist';
                            }
                        }
                        body += '<div class="mb-2" style="color:#16a34a;font-weight:600;font-size:13px;">' + headerText + '</div>';

                        // Sort matching zones: NEW first, then ALREADY BLOCKED/NOT BLOCKED.
                        var matchingResults = [];
                        for (var ri = 0; ri < d.zone_results.length; ri++) {
                            if (d.zone_results[ri].passed) matchingResults.push(d.zone_results[ri]);
                        }
                        if (hasBlocked && (isBlockAction || isUnblockAction)) {
                            matchingResults.sort(function (a, b) {
                                var aSkip = skipSet[String(a.zone_id)] ? 1 : 0;
                                var bSkip = skipSet[String(b.zone_id)] ? 1 : 0;
                                return aSkip - bSkip;
                            });
                        }

                        body += '<div style="max-height:250px;overflow-y:auto;margin-bottom:12px;">';
                        for (var i = 0; i < matchingResults.length; i++) {
                            var mz = matchingResults[i];
                            var mCondStr = '';
                            for (var mc = 0; mc < mz.details.length; mc++) {
                                var mcd = mz.details[mc];
                                var mVal = formatMetricValue(mcd.metric, mcd.actual);
                                mCondStr += (metricLabels[mcd.metric] || mcd.metric) + ': ' + mVal + (mcd.passed ? ' ✓' : ' ✗') + '  ';
                            }
                            var zoneIdStr = String(mz.zone_id);
                            var badgeCls = 'optee-badge-green';
                            var badgeLabel = 'NEW';
                            if (hasBlocked && isBlockAction && skipSet[zoneIdStr]) {
                                badgeCls = 'optee-badge-gray';
                                badgeLabel = 'ALREADY BLOCKED';
                            } else if (hasBlocked && isUnblockAction && skipSet[zoneIdStr]) {
                                badgeCls = 'optee-badge-gray';
                                badgeLabel = 'NOT BLOCKED';
                            } else if (!hasBlocked || (!isBlockAction && !isUnblockAction)) {
                                badgeLabel = 'MATCH';
                            }
                            body += '<div class="text-sm" style="padding:2px 0;"><span class="' + badgeCls + '" style="font-size:10px;">' + badgeLabel + '</span> ' + Optee.Util.escapeHtml(mz.zone_id) + ' <span class="text-xs text-gray-400">— ' + Optee.Util.escapeHtml(mCondStr) + '</span></div>';
                        }
                        body += '</div>';
                    } else {
                        body += '<div style="color:#9ca3af;margin-bottom:12px;">No ' + unitPlural + ' match the conditions (' + d.total_zones + ' evaluated).</div>';
                        if (d.errors && d.errors.length) {
                            for (var ei = 0; ei < d.errors.length; ei++) {
                                body += '<div style="color:#dc2626;font-size:13px;margin-bottom:4px;">\u26A0 ' + Optee.Util.escapeHtml(d.errors[ei]) + '</div>';
                            }
                        }
                    }

                    // Show non-matching zones (collapsed).
                    var nonMatching = [];
                    for (var j = 0; j < d.zone_results.length; j++) {
                        if (!d.zone_results[j].passed) nonMatching.push(d.zone_results[j]);
                    }
                    if (nonMatching.length) {
                        body += '<details style="margin-top:8px;"><summary class="text-xs text-gray-400 cursor-pointer">' + nonMatching.length + ' ' + unitPlural + ' did not match</summary>';
                        body += '<div style="max-height:150px;overflow-y:auto;margin-top:4px;">';
                        for (var k = 0; k < nonMatching.length; k++) {
                            var z = nonMatching[k];
                            var condStr = '';
                            for (var c = 0; c < z.details.length; c++) {
                                var cd = z.details[c];
                                var val = formatMetricValue(cd.metric, cd.actual);
                                condStr += (metricLabels[cd.metric] || cd.metric) + ': ' + val + (cd.passed ? ' ✓' : ' ✗') + '  ';
                            }
                            body += '<div class="text-xs text-gray-400" style="padding:1px 0;">' + Optee.Util.escapeHtml(z.zone_id) + ' — ' + Optee.Util.escapeHtml(condStr) + '</div>';
                        }
                        body += '</div></details>';
                    }
                } else {
                    // Campaign-level.
                    var actionLabel2 = actionLabels[d.action] || d.action;
                    body += '<div style="margin-bottom:12px;">';
                    body += '<span class="text-sm text-gray-500">Action:</span> <strong>' + Optee.Util.escapeHtml(actionLabel2) + '</strong>';
                    body += ' &nbsp;|&nbsp; <span class="text-sm text-gray-500">Timeframe:</span> <strong>' + Optee.Util.escapeHtml(timeframeLabels[d.timeframe] || d.timeframe) + '</strong>';
                    body += '</div>';
                    body += condSummary;

                    var passed = d.passed;
                    body += '<div style="margin-bottom:12px;">';
                    if (passed) {
                        body += '<span style="color:#16a34a;font-weight:600;">Conditions met</span> — action would execute.';
                    } else {
                        body += '<span style="color:#9ca3af;">Conditions not met</span> — no action would be taken.';
                    }
                    body += '</div>';

                    if (d.details && d.details.length) {
                        body += '<div style="margin-top:8px;">';
                        for (var m = 0; m < d.details.length; m++) {
                            var det = d.details[m];
                            var icon = det.passed ? '<span style="color:#16a34a;">✓</span>' : '<span style="color:#ef4444;">✗</span>';
                            var actual = formatMetricValue(det.metric, det.actual);
                            body += '<div class="text-sm" style="padding:2px 0;">' + icon + ' ' + Optee.Util.escapeHtml(metricLabels[det.metric] || det.metric) + ' ' + Optee.Util.escapeHtml(operatorLabels[det.operator] || det.operator) + ' ' + det.expected + ' (actual: ' + actual + ')' + detailSuffix(det) + '</div>';
                        }
                        body += '</div>';
                    }
                }

                // Build contextual disclaimer note.
                var noteAction = actionLabels[d.action] || d.action;
                var noteText = 'This is a preview only — no actions were performed.';
                if (d.type === 'offer_status') {
                    noteText = 'This is a preview only — offer status was checked but no actions were performed.';
                } else if (d.type === 'zone') {
                    if (d.action === 'block_zone' || d.action === 'block_zones') {
                        noteText = 'This is a preview only — no zones were blocked.';
                    } else if (d.action === 'unblock_zone' || d.action === 'unblock_zones') {
                        noteText = 'This is a preview only — no zones were unblocked.';
                    } else if (d.action === 'remove_countries' || d.action === 'remove_targeting') {
                        noteText = 'This is a preview only — no ' + unitPlural + ' were removed.';
                    }
                } else {
                    if (d.action === 'pause_traffic' || d.action === 'pause_campaign') {
                        noteText = 'This is a preview only — traffic was not paused.';
                    } else if (d.action === 'resume_traffic' || d.action === 'start_campaign') {
                        noteText = 'This is a preview only — traffic was not resumed.';
                    }
                }
                body += '<div style="margin-top:12px;padding:8px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:12px;color:#92400e;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:-2px;margin-right:4px;"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>' +
                    noteText + '</div>';

                // Replace skeleton with actual content.
                var $inner = $('#optee-preview-inner');
                $inner.html(
                    '<button id="optee-preview-close" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;" title="Close">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>' +
                    '<div style="font-weight:600;font-size:14px;margin-bottom:16px;">Rule Preview (dry run)</div>' +
                    body +
                    '<div style="margin-top:16px;"><button id="optee-preview-done" class="optee-btn-secondary">Close</button></div>'
                );

                $('#optee-preview-close, #optee-preview-done').on('click', function () {
                    $('#optee-preview-modal').remove();
                });

            }).fail(function () {
                restore();
                $('#optee-preview-modal').remove();
                Optee.Toast.show('Preview request failed.', 'error');
            });
        },

        /* ── Helpers ── */

        formatInterval: function (minutes) {
            if (minutes < 60) return minutes + ' min';
            if (minutes < 1440) return (minutes / 60) + 'h';
            return (minutes / 1440) + 'd';
        },

        // Human label for a Remove Targeting dimension key.
        targetingDimLabel: function (dim) {
            var labels = {
                category: 'Category', os: 'OS', browser: 'Browser', form_factor: 'Form Factor',
                connection_speed: 'Connection Speed', connection_type: 'Connection Type',
                resolution: 'Resolution', isp: 'ISP', device: 'Device'
            };
            return labels[dim] || dim.replace(/_/g, ' ');
        },

        relativeTime: function (dateStr) {
            if (!dateStr) return 'Never';
            var now = new Date();
            var then = new Date(dateStr.replace(/-/g, '/'));
            var diffMs = now - then;
            var diffMin = Math.floor(diffMs / 60000);
            if (diffMin < 1) return 'just now';
            if (diffMin < 60) return diffMin + 'm ago';
            var diffH = Math.floor(diffMin / 60);
            if (diffH < 24) return diffH + 'h ago';
            var diffD = Math.floor(diffH / 24);
            return diffD + 'd ago';
        },

        redirectCountdown: function (message, campaignId) {
            var secs = 3;
            var url = opteeData.dashboardUrl + '?module=rules' + (campaignId ? '&new_campaign=' + campaignId : '');

            // Create a toast manually so we can update the same element.
            var $toast = $('<div class="optee-toast flex items-center gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg border-green-500 bg-green-50 text-green-700">' +
                '<span class="shrink-0"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>' +
                '<span class="optee-countdown-text"></span>' +
                '</div>');
            $toast.find('.optee-countdown-text').text(message + ' — Redirecting in ' + secs + 's...');
            $('#optee-toasts').append($toast);

            var timer = setInterval(function () {
                secs--;
                if (secs <= 0) {
                    clearInterval(timer);
                    window.location.href = url;
                } else {
                    $toast.find('.optee-countdown-text').text(message + ' — Redirecting in ' + secs + 's...');
                }
            }, 1000);
        }
    };

    $(function () {
        Rules.init();
    });

})(jQuery);
