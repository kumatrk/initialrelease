/**
 * Optee — Campaign CRUD logic.
 */
(function ($) {
    'use strict';

    var Campaigns = {
        page: 1,
        perPage: 20,
        tsGrouping: false,   // true when the selected traffic source groups creatives (e.g. Push.House)
        tsCampaigns: [],     // cached campaign list for the multi-select

        init: function () {
            this.bindEvents();
            this.loadList();

            // Initialize searchable selects (shared IDs on both create & edit forms).
            Optee.SearchableSelect.init('ss-traffic-source-campaign');
            Optee.SearchableSelect.init('ss-tracker-campaign');
            Optee.SearchableSelect.init('ss-antifraud-campaign');

            // Edit form: pre-populate with saved values and load options.
            this.initEditForm();
        },

        initEditForm: function () {
            var $form = $('#optee-campaign-edit-form');
            if (!$form.length) return;

            var tsCampaignId   = $form.data('ts-campaign-id') || '';
            var tsCampaignName = $form.data('ts-campaign-name') || '';
            var trCampaignId   = $form.data('tracker-campaign-id') || '';
            var trCampaignName = $form.data('tracker-campaign-name') || '';
            var zoneId         = $form.data('zone-id') || '';
            var zoneName       = $form.data('zone-name') || '';
            var afCampaignId   = $form.data('antifraud-campaign-id') || '';
            var afCampaignName = $form.data('antifraud-campaign-name') || '';

            // Pre-select saved values so names show immediately.
            if (tsCampaignId) {
                Optee.SearchableSelect.preSelect('ss-traffic-source-campaign', tsCampaignId + '', tsCampaignName || tsCampaignId + '');
            }
            if (trCampaignId) {
                Optee.SearchableSelect.preSelect('ss-tracker-campaign', trCampaignId + '', trCampaignName || trCampaignId + '');
            }
            if (afCampaignId) {
                Optee.SearchableSelect.preSelect('ss-antifraud-campaign', afCampaignId + '', afCampaignName || afCampaignId + '');
            }

            // Load options from APIs so the user can change the selection.
            var tsSlug = $form.find('[name="traffic_source_module"]').val();
            if (tsSlug) {
                Campaigns.loadRemoteCampaigns(tsSlug, true);
            }

            var trackerSlug = $form.find('[name="tracker_module"]').val();
            if (trackerSlug) {
                var campaignsPromise = Campaigns.loadTrackerCampaigns(trackerSlug, true);
                if (trCampaignId) {
                    $('#campaign-zone-wrapper').removeClass('hidden');
                    // Chain zone load after campaigns to avoid race condition.
                    campaignsPromise.always(function () {
                        Campaigns.loadTrackerZones(trackerSlug, trCampaignId, zoneId);
                    });
                }
                // Optional traffic-source filter — preselect the saved source.
                if (Campaigns.trackerSupportsTs(trackerSlug)) {
                    Campaigns.loadTrackerTrafficSources(trackerSlug, ($form.data('tracker-ts-id') || '') + '');
                    // Detected campaign-id chips for the saved funnel + source.
                    Campaigns.loadTrackerCampaignOptions(trackerSlug, (trCampaignId || '') + '', ($form.data('tracker-ts-id') || '') + '');
                }
            }

            var afSlug = $form.find('[name="antifraud_module"]').val();
            if (afSlug) {
                Campaigns.loadAntifraudCampaigns(afSlug, true);
            }
        },

        bindEvents: function () {
            // Status filter (desktop + mobile).
            $(document).on('change', '#optee-campaign-status-filter, #optee-campaign-status-filter-mob', function () {
                var val = $(this).val();
                // Sync both selects.
                $('#optee-campaign-status-filter, #optee-campaign-status-filter-mob').val(val);
                Campaigns.page = 1;
                Campaigns.loadList();
            });

            // Create form.
            $(document).on('submit', '#optee-campaign-form', function (e) {
                e.preventDefault();
                Campaigns.create($(this));
            });

            // Edit form.
            $(document).on('submit', '#optee-campaign-edit-form', function (e) {
                e.preventDefault();
                Campaigns.update($(this));
            });

            // Delete button.
            $(document).on('click', '#optee-delete-campaign', function () {
                var campaignId = $('#optee-campaign-edit-form').data('campaign-id');
                if (Optee.Util.confirm('Are you sure you want to delete this campaign and all its rules?')) {
                    Campaigns.deleteCampaign(campaignId);
                }
            });

            // Toggle campaign status (active/paused).
            $(document).on('click', '[data-toggle-status]', function () {
                var $btn = $(this);
                if ($btn.hasClass('is-updating')) return;
                var id = $btn.data('toggle-status');
                var current = $btn.data('current');
                var newStatus = current === 'active' ? 'paused' : 'active';

                // Show "Updating..." yellow badge.
                var loaderIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>';
                $btn.addClass('is-updating')
                    .removeClass('optee-badge-green optee-badge-red')
                    .addClass('optee-badge-yellow')
                    .html(loaderIcon + ' Updating...');

                Optee.Ajax.post('optee_update_campaign', {
                    campaign_id: id,
                    status: newStatus
                }).done(function (res) {
                    if (res.success) {
                        Campaigns.loadList();
                    } else {
                        Optee.Toast.show(res.data.message, 'error');
                        // Restore original badge.
                        $btn.removeClass('is-updating optee-badge-yellow');
                        $btn.replaceWith(Campaigns.statusBadge(current, id));
                    }
                }).fail(function () {
                    Optee.Toast.show('Request failed.', 'error');
                    $btn.removeClass('is-updating optee-badge-yellow');
                    $btn.replaceWith(Campaigns.statusBadge(current, id));
                });
            });

            // Traffic source change -> load remote campaigns.
            // loadRemoteCampaigns() reveals the right picker (single searchable select,
            // or the multi-checkbox list for sources that group creatives).
            $(document).on('change', '#campaign-traffic-source', function () {
                var slug = $(this).val();
                if (slug) {
                    Campaigns.loadRemoteCampaigns(slug);
                } else {
                    $('#campaign-remote-wrapper').addClass('hidden');
                    $('#campaign-remote-multi-wrapper').addClass('hidden');
                    $('#campaign-archived-wrapper').addClass('hidden');
                    $('#campaign-include-archived').prop('checked', false);
                    Campaigns.tsGrouping = false;
                    Optee.SearchableSelect.clear('ss-traffic-source-campaign');
                }
            });

            // "Include archived campaigns" toggle -> re-fetch. Passing no keepSelection
            // flag makes loadRemoteCampaigns() show the "Loading…" state on the input.
            $(document).on('change', '#campaign-include-archived', function () {
                var slug = $('#campaign-traffic-source').val();
                if (slug) {
                    Campaigns.loadRemoteCampaigns(slug);
                }
            });

            // Multi-select (grouped sources): filter, select-all, clear, count.
            $(document).on('input', '#campaign-multi-search', function () {
                var q = $(this).val().toLowerCase().trim();
                $('#campaign-multi-list .optee-multi-item').each(function () {
                    var hay = ($(this).attr('data-search') || '');
                    $(this).toggle(q === '' || hay.indexOf(q) !== -1);
                });
            });
            $(document).on('click', '#campaign-multi-all', function () {
                $('#campaign-multi-list .optee-multi-item:visible .campaign-multi-cb').prop('checked', true);
                Campaigns.updateMultiCount();
            });
            $(document).on('click', '#campaign-multi-none', function () {
                $('#campaign-multi-list .campaign-multi-cb').prop('checked', false);
                Campaigns.updateMultiCount();
            });
            $(document).on('change', '.campaign-multi-cb', function () {
                Campaigns.updateMultiCount();
            });

            // Tracker change -> load tracker campaigns.
            $(document).on('change', '#campaign-tracker', function () {
                var slug = $(this).val();
                if (slug) {
                    $('#campaign-tracker-remote-wrapper').removeClass('hidden');
                    Campaigns.loadTrackerCampaigns(slug);
                } else {
                    $('#campaign-tracker-remote-wrapper').addClass('hidden');
                    $('#campaign-zone-wrapper').addClass('hidden');
                    Optee.SearchableSelect.clear('ss-tracker-campaign');
                }

                // Optional traffic-source filter (FunnelFlux and similar).
                if (Campaigns.trackerSupportsTs(slug)) {
                    Campaigns.loadTrackerTrafficSources(slug);
                    $('#campaign-ts-campaigns-wrapper').removeClass('hidden');
                } else {
                    $('#campaign-ts-wrapper').addClass('hidden');
                    $('#campaign-ts-id').val('');
                    $('#campaign-ts-campaigns-wrapper').addClass('hidden');
                    $('#campaign-ts-campaigns').val('');
                }
            });

            // Tracker campaign change -> load zones (and refresh campaign-id chips).
            $(document).on('change', '#campaign-tracker-remote-id', function () {
                var campaignId = $(this).val();
                var trackerSlug = $('#campaign-tracker').val();
                if (campaignId && trackerSlug) {
                    $('#campaign-zone-wrapper').removeClass('hidden');
                    Campaigns.loadTrackerZones(trackerSlug, campaignId);
                } else {
                    $('#campaign-zone-wrapper').addClass('hidden');
                }
                if (trackerSlug && Campaigns.trackerSupportsTs(trackerSlug)) {
                    Campaigns.loadTrackerCampaignOptions(trackerSlug, campaignId, $('#campaign-ts-id').val());
                }
            });

            // Traffic-source filter change -> re-scope the campaign-id chips.
            $(document).on('change', '#campaign-ts-id', function () {
                var trackerSlug = $('#campaign-tracker').val();
                if (trackerSlug && Campaigns.trackerSupportsTs(trackerSlug)) {
                    Campaigns.loadTrackerCampaignOptions(trackerSlug, $('#campaign-tracker-remote-id').val(), $(this).val());
                }
            });

            // Click a detected campaign-id chip -> toggle it in the text box.
            $(document).on('click', '.optee-campaign-chip', function () {
                var cid = ($(this).data('cid') || '') + '';
                var ids = Campaigns.parseCampaignIds();
                var idx = ids.indexOf(cid);
                if (idx === -1) { ids.push(cid); } else { ids.splice(idx, 1); }
                $('#campaign-ts-campaigns').val(ids.join(', '));
                Campaigns.renderCampaignChips();
            });

            // Manual edits to the box -> keep chip highlight state in sync.
            $(document).on('input', '#campaign-ts-campaigns', function () {
                Campaigns.renderCampaignChips();
            });

            // Smartlink toggle -> disable/enable Offer ID.
            $(document).on('change', '#campaign-is-smartlink', function () {
                var $offerInput = $('#campaign-offer-id');
                if (this.checked) {
                    $offerInput.prop('disabled', true).val('').css('cursor', 'not-allowed');
                } else {
                    $offerInput.prop('disabled', false).css('cursor', '');
                }
            });

            // Antifraud tool change -> load antifraud campaigns.
            $(document).on('change', '#campaign-antifraud', function () {
                var slug = $(this).val();
                if (slug) {
                    $('#campaign-antifraud-remote-wrapper').removeClass('hidden');
                    Campaigns.loadAntifraudCampaigns(slug);
                } else {
                    $('#campaign-antifraud-remote-wrapper').addClass('hidden');
                    Optee.SearchableSelect.clear('ss-antifraud-campaign');
                }
            });
        },

        loadList: function () {
            var $tbody = $('#optee-campaigns-tbody');
            var $cards = $('#optee-campaigns-cards');
            if (!$tbody.length) return;

            $tbody.html('<tr><td colspan="7" class="text-center py-8 text-gray-500">Loading...</td></tr>');
            if ($cards.length) $cards.html('<div class="text-center py-8 text-gray-400">Loading campaigns...</div>');

            var status = $('#optee-campaign-status-filter').val() || '';

            Optee.Ajax.post('optee_get_campaigns', {
                page: Campaigns.page,
                per_page: Campaigns.perPage,
                status: status
            }).done(function (res) {
                if (!res.success || !res.data.campaigns.length) {
                    $tbody.html('<tr><td colspan="7" class="text-center py-8 text-gray-500">No campaigns found.</td></tr>');
                    if ($cards.length) $cards.html('<div class="optee-card text-center py-8 text-gray-400">No campaigns found.</div>');
                    return;
                }

                var html = '';
                var cardHtml = '';
                $.each(res.data.campaigns, function (i, c) {
                    var statusBadge = Campaigns.statusBadge(c.status, c.id);
                    var statsUrl = opteeData.dashboardUrl + '?module=dashboard&action=campaign&id=' + c.id;
                    var editUrl = opteeData.dashboardUrl + '?module=campaigns&action=edit&id=' + c.id;

                    var rulesCount = c.rules_count || 0;
                    var rulesCell = rulesCount > 0
                        ? '' + rulesCount
                        : '<span class="flex items-center">0 <span style="margin-left:6px;display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:9999px;background:#fef9c3;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#a16207" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg></span></span>';

                    var rulesUrl = opteeData.dashboardUrl + '?module=rules&action=create&campaign_id=' + c.id;
                    var addRulesLink = '';

                    var addRulesBtn = '<a href="' + rulesUrl + '" class="optee-btn-ghost optee-btn-sm">Add Rules</a>';

                    // Desktop table row.
                    html += '<tr class="group">' +
                        '<td><a href="' + statsUrl + '" class="text-gray-900 hover:text-brand-600 font-medium">' + Optee.Util.escapeHtml(c.title) + '</a>' + addRulesLink + '</td>' +
                        '<td>' + Optee.Util.escapeHtml(c.traffic_source_module || '—') + '</td>' +
                        '<td>' + Optee.Util.escapeHtml(c.tracker_module || '—') + '</td>' +
                        '<td>' + rulesCell + '</td>' +
                        '<td>' + statusBadge + '</td>' +
                        '<td>' + Optee.Util.escapeHtml(c.date.substring(0, 10)) + '</td>' +
                        '<td class="text-right">' +
                            '<div class="flex items-center justify-end gap-2">' +
                                addRulesBtn +
                                '<a href="' + editUrl + '" class="optee-btn-ghost optee-btn-sm">Edit</a>' +
                            '</div>' +
                        '</td>' +
                        '</tr>';

                    // Mobile card.
                    var esc = Optee.Util.escapeHtml;
                    var statusLabel = c.status === 'active' ? '<span class="optee-badge-green">Active</span>'
                        : (c.status === 'paused' ? '<span class="optee-badge-yellow">Paused</span>'
                        : '<span class="optee-badge-gray">' + esc(c.status) + '</span>');
                    var rulesBadge = rulesCount > 0
                        ? '<span class="optee-badge-green" style="font-size:10px;padding:1px 6px;">' + rulesCount + ' rule' + (rulesCount > 1 ? 's' : '') + '</span>'
                        : '';

                    cardHtml += '<div class="optee-card optee-mob-card" style="padding:14px 16px;">' +
                        '<div class="flex items-center gap-2" style="font-size:13px;">' +
                            statusLabel +
                            '<span class="text-gray-500">' + esc(c.traffic_source_module || '') + '</span>' +
                            (c.tracker_module ? '<span class="text-gray-400">&middot;</span><span class="text-gray-500">' + esc(c.tracker_module) + '</span>' : '') +
                            rulesBadge +
                        '</div>' +
                        '<a href="' + statsUrl + '" class="block font-semibold text-gray-900 hover:text-brand-600 mt-2" style="font-size:17px;">' + esc(c.title) + '</a>' +
                        '<div class="flex items-center gap-3 mt-2 text-xs text-gray-400">' +
                            '<span>' + esc(c.date.substring(0, 10)) + '</span>' +
                        '</div>' +
                        '<div style="margin-top:16px;padding-top:10px;border-top:1px solid #e5e7eb;">' +
                            '<button type="button" class="optee-mob-actions-toggle" style="background:none;border:1px solid #e5e7eb;border-radius:6px;color:#6b7280;font-size:12px;font-weight:500;padding:6px 10px;cursor:pointer;width:100%;">Show Actions</button>' +
                            '<div class="optee-mob-actions flex flex-wrap items-center gap-2 mt-2" style="display:none;">' +
                                '<a href="' + editUrl + '" class="optee-mob-action-btn"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg> Edit</a>' +
                                '<a href="' + statsUrl + '" class="optee-mob-action-btn"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Stats</a>' +
                                '<a href="' + rulesUrl + '" class="optee-mob-action-btn"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> Add Rules</a>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                });

                $tbody.html(html);
                if ($cards.length) $cards.html(cardHtml);
                Campaigns.renderPagination(res.data.total, res.data.pages);
            }).fail(function () {
                $tbody.html('<tr><td colspan="7" class="text-center py-8 text-red-400">Failed to load campaigns.</td></tr>');
                if ($cards.length) $cards.html('<div class="optee-card text-center py-8 text-red-400">Failed to load campaigns.</div>');
            });
        },

        create: function ($form) {
            var title = $form.find('[name="title"]').val().trim();
            var tsModule = $form.find('[name="traffic_source_module"]').val();
            var tsSel = Campaigns.resolveTsSelection($form);
            var tsCampaignId = tsSel.id;
            var trackerModule = $form.find('[name="tracker_module"]').val();
            var trackerCampaignId = $form.find('[name="tracker_campaign_id"]').val();
            var zoneId = $form.find('[name="zone_id"]').val();
            var antifraudModule = $form.find('[name="antifraud_module"]').val();
            var antifraudCampaignId = $form.find('[name="antifraud_campaign_id"]').val();

            // Validation.
            if (!title) {
                Optee.Toast.show('Campaign name is required.', 'error');
                return;
            }
            if (tsModule && !tsCampaignId) {
                Optee.Toast.show(Campaigns.tsGrouping ? 'Please select at least one campaign.' : 'Please select a Traffic Source Campaign.', 'error');
                return;
            }
            if (trackerModule && !trackerCampaignId) {
                Optee.Toast.show('Please select a Tracker Campaign.', 'error');
                return;
            }
            if (trackerModule && !zoneId) {
                Optee.Toast.show('Please select a Zone (Source).', 'error');
                return;
            }
            if (antifraudModule && !antifraudCampaignId) {
                Optee.Toast.show('Please select an Antifraud Campaign.', 'error');
                return;
            }

            var trackerVal = Optee.SearchableSelect.getValue('ss-tracker-campaign');
            var antifraudVal = Optee.SearchableSelect.getValue('ss-antifraud-campaign');

            var $btn = $form.find('[type="submit"]').prop('disabled', true).text('Creating...');

            var data = {
                title: title,
                traffic_source_module: tsModule,
                traffic_source_campaign_id: tsCampaignId,
                traffic_source_campaign_name: tsSel.name,
                traffic_source_campaign_members: tsSel.members,
                tracker_module: trackerModule,
                tracker_campaign_id: trackerCampaignId,
                tracker_campaign_name: trackerVal.label,
                tracker_ts_id: Campaigns.getTrackerTsId($form),
                tracker_ts_name: Campaigns.getTrackerTsName($form),
                tracker_campaign_ids: Campaigns.getTrackerCampaignIds($form),
                zone_id: zoneId,
                zone_name: $form.find('[name="zone_id"] option:selected').text().trim(),
                affiliate_network_module: $form.find('[name="affiliate_network_module"]').val(),
                antifraud_module: antifraudModule,
                antifraud_campaign_id: antifraudCampaignId,
                antifraud_campaign_name: antifraudVal.label,
                offer_id: $form.find('[name="offer_id"]').val(),
                is_smartlink: $form.find('#campaign-is-smartlink').is(':checked') ? '1' : '',
            };

            Optee.Ajax.post('optee_create_campaign', data).done(function (res) {
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    window.location.href = opteeData.dashboardUrl + '?module=campaigns';
                } else {
                    Optee.Toast.show(res.data.message, 'error');
                    $btn.prop('disabled', false).text('Create Campaign');
                }
            }).fail(function () {
                Optee.Toast.show('Request failed.', 'error');
                $btn.prop('disabled', false).text('Create Campaign');
            });
        },

        update: function ($form) {
            var $btn = $form.find('[type="submit"]').prop('disabled', true).text('Saving...');
            var campaignId = $form.data('campaign-id');

            var tsSel = Campaigns.resolveTsSelection($form);
            var trackerVal = Optee.SearchableSelect.getValue('ss-tracker-campaign');
            var antifraudVal = Optee.SearchableSelect.getValue('ss-antifraud-campaign');

            if ($form.find('[name="traffic_source_module"]').val() && !tsSel.id) {
                Optee.Toast.show(Campaigns.tsGrouping ? 'Please select at least one campaign.' : 'Please select a Traffic Source Campaign.', 'error');
                $btn.prop('disabled', false).text('Save Changes');
                return;
            }

            var data = {
                campaign_id: campaignId,
                title: $form.find('[name="title"]').val(),
                status: $form.find('[name="status"]').val(),
                traffic_source_module: $form.find('[name="traffic_source_module"]').val(),
                traffic_source_campaign_id: tsSel.id,
                traffic_source_campaign_name: tsSel.name,
                traffic_source_campaign_members: tsSel.members,
                tracker_module: $form.find('[name="tracker_module"]').val(),
                tracker_campaign_id: trackerVal.value,
                tracker_campaign_name: trackerVal.label,
                tracker_ts_id: Campaigns.getTrackerTsId($form),
                tracker_ts_name: Campaigns.getTrackerTsName($form),
                tracker_campaign_ids: Campaigns.getTrackerCampaignIds($form),
                zone_id: $form.find('[name="zone_id"]').val(),
                zone_name: $form.find('[name="zone_id"] option:selected').text().trim(),
                affiliate_network_module: $form.find('[name="affiliate_network_module"]').val(),
                antifraud_module: $form.find('[name="antifraud_module"]').val(),
                antifraud_campaign_id: antifraudVal.value,
                antifraud_campaign_name: antifraudVal.label,
                offer_id: $form.find('[name="offer_id"]').val(),
                is_smartlink: $form.find('#campaign-is-smartlink').is(':checked') ? '1' : '',
            };

            Optee.Ajax.post('optee_update_campaign', data).done(function (res) {
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    $btn.prop('disabled', true).text('Saved');
                    // Redirect to campaigns list after 3 seconds.
                    var listUrl = $form.find('.optee-btn-secondary').attr('href');
                    if (listUrl) {
                        setTimeout(function () { window.location.href = listUrl; }, 3000);
                    }
                } else {
                    Optee.Toast.show(res.data.message, 'error');
                    $btn.prop('disabled', false).text('Save Changes');
                }
            }).fail(function () {
                Optee.Toast.show('Request failed.', 'error');
                $btn.prop('disabled', false).text('Save Changes');
            });
        },

        deleteCampaign: function (id) {
            Optee.Ajax.post('optee_delete_campaign', { campaign_id: id }).done(function (res) {
                if (res.success) {
                    Optee.Toast.show(res.data.message, 'success');
                    window.location.href = opteeData.dashboardUrl + '?module=campaigns';
                } else {
                    Optee.Toast.show(res.data.message, 'error');
                }
            });
        },

        loadRemoteCampaigns: function (slug, keepSelection) {
            var ssId = 'ss-traffic-source-campaign';
            if (!keepSelection) Optee.SearchableSelect.setLoading(ssId);

            Optee.Ajax.post('optee_get_traffic_source_campaigns', {
                module_slug: slug,
                include_archived: $('#campaign-include-archived').is(':checked') ? 1 : 0
            }).done(function (res) {
                // Reveal the "Include archived" checkbox only for sources that support it.
                if (res.success && res.data.supports_archived && !res.data.supports_grouping) {
                    $('#campaign-archived-wrapper').removeClass('hidden');
                } else {
                    $('#campaign-archived-wrapper').addClass('hidden');
                }
                if (res.success && res.data.campaigns) {
                    // Sources that group creatives (e.g. Push.House) use the checkbox multi-select.
                    if (res.data.supports_grouping) {
                        Campaigns.enterGroupingMode(res.data.campaigns, keepSelection);
                        return;
                    }
                    // Single-campaign source \u2014 searchable select (default behavior).
                    Campaigns.tsGrouping = false;
                    $('#campaign-remote-multi-wrapper').addClass('hidden');
                    $('#campaign-remote-wrapper').removeClass('hidden');
                    var options = $.map(res.data.campaigns, function (c) {
                        return { value: c.campaign_id, label: c.campaign_name || c.campaign_id };
                    });
                    Optee.SearchableSelect.setOptions(ssId, options, '\u2014 Search campaigns\u2026 \u2014', keepSelection);
                } else {
                    Campaigns.tsGrouping = false;
                    $('#campaign-remote-multi-wrapper').addClass('hidden');
                    $('#campaign-remote-wrapper').removeClass('hidden');
                    var errMsg = (res.data && res.data.message) ? res.data.message : 'No campaigns found';
                    Optee.SearchableSelect.setOptions(ssId, [], 'No campaigns found', keepSelection);
                    Optee.Toast.show(errMsg, 'error');
                }
            }).fail(function () {
                Campaigns.tsGrouping = false;
                $('#campaign-remote-multi-wrapper').addClass('hidden');
                $('#campaign-remote-wrapper').removeClass('hidden');
                Optee.SearchableSelect.setOptions(ssId, [], 'Failed to load', keepSelection);
                Optee.Toast.show('Failed to load traffic source campaigns.', 'error');
            });
        },

        /* ----- Grouped traffic source (one creative = one campaign) ----- */

        enterGroupingMode: function (campaigns, keepSelection) {
            this.tsGrouping = true;
            this.tsCampaigns = campaigns || [];
            $('#campaign-remote-wrapper').addClass('hidden');
            $('#campaign-remote-multi-wrapper').removeClass('hidden');

            // Determine which campaigns should start checked.
            var checked = keepSelection ? this.getCheckedIds() : [];
            if (keepSelection && !checked.length) {
                // First edit render \u2014 restore saved members from the data attribute.
                var saved = ($('#campaign-remote-multi-wrapper').attr('data-saved-ids') || '');
                checked = saved ? saved.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];
            }
            this.renderMultiList(this.tsCampaigns, checked);
        },

        renderMultiList: function (campaigns, checkedIds) {
            var checkedSet = {};
            (checkedIds || []).forEach(function (id) { checkedSet[String(id)] = true; });

            if (!campaigns || !campaigns.length) {
                $('#campaign-multi-list').html('<div class="text-sm text-gray-400 text-center py-4">No campaigns found</div>');
                this.updateMultiCount();
                return;
            }

            var html = '';
            $.each(campaigns, function (i, c) {
                var id = String(c.campaign_id);
                var name = c.campaign_name || id;
                var isChecked = checkedSet[id] ? ' checked' : '';
                var search = Optee.Util.escapeHtml((name + ' ' + id).toLowerCase());
                html += '<label class="optee-multi-item" data-search="' + search + '" style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:4px;cursor:pointer;font-size:13px;">' +
                    '<input type="checkbox" class="optee-checkbox campaign-multi-cb" value="' + Optee.Util.escapeHtml(id) + '" data-name="' + Optee.Util.escapeHtml(name) + '"' + isChecked + '>' +
                    '<span class="text-gray-700">' + Optee.Util.escapeHtml(name) + '</span>' +
                    '<span class="text-gray-400" style="margin-left:auto;font-size:11px;">#' + Optee.Util.escapeHtml(id) + '</span>' +
                    '</label>';
            });
            $('#campaign-multi-list').html(html);
            this.updateMultiCount();
        },

        getCheckedMembers: function () {
            var members = [];
            $('#campaign-multi-list .campaign-multi-cb:checked').each(function () {
                members.push({ id: String($(this).val()), name: ($(this).attr('data-name') || String($(this).val())) });
            });
            return members;
        },

        getCheckedIds: function () {
            return this.getCheckedMembers().map(function (m) { return m.id; });
        },

        updateMultiCount: function () {
            var n = $('#campaign-multi-list .campaign-multi-cb:checked').length;
            $('#campaign-multi-count').text(n + ' selected');
        },

        // Resolve the traffic-source selection for submit, in either mode.
        resolveTsSelection: function ($form) {
            if (this.tsGrouping) {
                var members = this.getCheckedMembers();
                return {
                    id: members.map(function (m) { return m.id; }).join(','),
                    name: members.length === 1 ? members[0].name : (members.length + ' campaigns grouped'),
                    members: JSON.stringify(members)
                };
            }
            var tsVal = Optee.SearchableSelect.getValue('ss-traffic-source-campaign');
            return { id: tsVal.value, name: tsVal.label, members: '' };
        },

        // Optional tracker traffic-source filter — empty unless the field is shown and set.
        getTrackerTsId: function ($form) {
            if ($('#campaign-ts-wrapper').hasClass('hidden')) return '';
            return $form.find('[name="tracker_ts_id"]').val() || '';
        },
        getTrackerTsName: function ($form) {
            if (!Campaigns.getTrackerTsId($form)) return '';
            return $form.find('[name="tracker_ts_id"] option:selected').text().trim();
        },

        // Optional external campaign-id filter (FunnelFlux {campaign} field) — empty
        // unless the field is shown and set.
        getTrackerCampaignIds: function ($form) {
            if ($('#campaign-ts-campaigns-wrapper').hasClass('hidden')) return '';
            return ($form.find('[name="tracker_campaign_ids"]').val() || '').trim();
        },

        // Parse the comma-separated Campaign ID(s) box into a clean array.
        parseCampaignIds: function () {
            return ($('#campaign-ts-campaigns').val() || '')
                .split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        },

        // Fetch the campaign ids FunnelFlux has data for and render click-to-add
        // chips. Best-effort: an empty list (new/no-data funnel) just hides the row.
        loadTrackerCampaignOptions: function (slug, funnelId, tsId) {
            var $box = $('#campaign-ts-campaigns-chips');
            if (!slug || !funnelId) { $box.addClass('hidden').empty(); return; }
            $box.removeClass('hidden').html('<span class="text-xs text-gray-400">Loading campaign IDs…</span>');

            Optee.Ajax.post('optee_get_tracker_campaign_options', {
                module_slug: slug,
                campaign_id: funnelId,
                ts_id: tsId || ''
            }).done(function (res) {
                var list = (res.success && res.data.campaigns) ? res.data.campaigns : [];
                Campaigns._campaignOptions = list;
                Campaigns.renderCampaignChips();
            }).fail(function () {
                $box.addClass('hidden').empty();
            });
        },

        // (Re)render chips reflecting which ids are currently in the text box.
        renderCampaignChips: function () {
            var $box = $('#campaign-ts-campaigns-chips');
            var list = Campaigns._campaignOptions || [];
            if (!list.length) {
                $box.html('<span class="text-xs text-gray-400">No campaign IDs with data yet — type them manually above.</span>');
                return;
            }
            var selected = Campaigns.parseCampaignIds();
            var html = '<div class="text-xs text-gray-400" style="margin-bottom:4px;">Detected in FunnelFlux (last 90 days) — click to add:</div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
            $.each(list, function (i, c) {
                var on = selected.indexOf(c.id + '') !== -1;
                html += '<button type="button" class="optee-campaign-chip optee-badge ' +
                    (on ? 'optee-badge-blue' : 'optee-badge-gray') + '" data-cid="' +
                    Optee.Util.escapeHtml(c.id + '') + '" style="cursor:pointer;">' +
                    Optee.Util.escapeHtml(c.id + '') +
                    (c.visits ? ' · ' + c.visits + 'v' : '') +
                    (on ? ' ✓' : '') + '</button>';
            });
            html += '</div>';
            $box.html(html);
        },

        loadTrackerCampaigns: function (slug, keepSelection) {
            var ssId = 'ss-tracker-campaign';
            if (!keepSelection) {
                Optee.SearchableSelect.setLoading(ssId);
                $('#campaign-zone-wrapper').addClass('hidden');
            }

            return Optee.Ajax.post('optee_get_tracker_campaigns', {
                module_slug: slug
            }).done(function (res) {
                if (res.success && res.data.campaigns) {
                    var options = $.map(res.data.campaigns, function (c) {
                        return { value: c.campaign_id, label: c.campaign_name || c.campaign_id };
                    });
                    Optee.SearchableSelect.setOptions(ssId, options, '\u2014 Search campaigns\u2026 \u2014', keepSelection);
                } else {
                    Optee.SearchableSelect.setOptions(ssId, [], 'No campaigns found', keepSelection);
                }
            }).fail(function () {
                Optee.SearchableSelect.setOptions(ssId, [], 'Failed to load', keepSelection);
            });
        },

        loadTrackerZones: function (slug, campaignId, preselectZoneId) {
            var $select = $('#campaign-zone-id');
            var $form   = $select.closest('form');
            var $submit = $form.find('button[type="submit"]');
            var $delete = $('#optee-delete-campaign');
            var origSubmitHtml = $submit.html();

            // If we have a pre-rendered saved value, keep it visible so the form
            // remains submittable; otherwise show a Loading placeholder.
            if (!preselectZoneId) {
                $select.html('<option value="">Loading...</option>');
            }
            $select.prop('disabled', true);
            $submit.prop('disabled', true).text('Loading zones...');
            $delete.prop('disabled', true);

            Optee.Ajax.post('optee_get_tracker_zones', {
                module_slug: slug,
                campaign_id: campaignId
            }).done(function (res) {
                if (res.success && res.data.zones) {
                    var html = '<option value="">— Select Zone Field —</option>';
                    $.each(res.data.zones, function (i, z) {
                        var num = (z.zone_id || '').replace('custom', '');
                        var label = 'Custom ' + num + ': ' + (z.zone_name || z.zone_id);
                        var sel = preselectZoneId && z.zone_id === preselectZoneId ? ' selected' : '';
                        html += '<option value="' + Optee.Util.escapeHtml(z.zone_id) + '"' + sel + '>' +
                            Optee.Util.escapeHtml(label) + '</option>';
                    });
                    $select.html(html);
                } else if (!preselectZoneId) {
                    $select.html('<option value="">No zones found</option>');
                }
            }).fail(function () {
                if (!preselectZoneId) {
                    $select.html('<option value="">Failed to load</option>');
                }
            }).always(function () {
                $select.prop('disabled', false);
                $submit.prop('disabled', false).html(origSubmitHtml);
                $delete.prop('disabled', false);
            });
        },

        // True when the selected tracker exposes a traffic-source filter (FunnelFlux).
        trackerSupportsTs: function (slug) {
            if (!slug) return false;
            return $('#campaign-tracker option[value="' + slug + '"]').data('supports-ts') == 1;
        },

        // Load a tracker's traffic sources into the optional filter dropdown.
        loadTrackerTrafficSources: function (slug, preselectId) {
            var $wrap   = $('#campaign-ts-wrapper');
            var $select = $('#campaign-ts-id');
            $wrap.removeClass('hidden');
            // The external campaign-id filter travels with the traffic-source filter.
            $('#campaign-ts-campaigns-wrapper').removeClass('hidden');

            // Keep any pre-rendered saved option visible while loading.
            if (!preselectId) {
                $select.html('<option value="">Loading…</option>');
            }
            $select.prop('disabled', true);

            return Optee.Ajax.post('optee_get_tracker_traffic_sources', {
                module_slug: slug
            }).done(function (res) {
                var html = '<option value="">— All traffic sources —</option>';
                if (res.success && res.data.traffic_sources) {
                    $.each(res.data.traffic_sources, function (i, ts) {
                        var label = (ts.category ? ts.category + ' / ' : '') + (ts.name || ts.id);
                        var sel = preselectId && ts.id === preselectId ? ' selected' : '';
                        html += '<option value="' + Optee.Util.escapeHtml(ts.id) + '"' + sel + '>' +
                            Optee.Util.escapeHtml(label) + '</option>';
                    });
                }
                $select.html(html);
            }).fail(function () {
                // On failure keep "All traffic sources" so the campaign stays saveable.
                if (!preselectId) {
                    $select.html('<option value="">— All traffic sources —</option>');
                }
            }).always(function () {
                $select.prop('disabled', false);
            });
        },

        loadAntifraudCampaigns: function (slug, keepSelection) {
            var ssId = 'ss-antifraud-campaign';
            if (!keepSelection) Optee.SearchableSelect.setLoading(ssId);

            Optee.Ajax.post('optee_get_antifraud_campaigns', {
                module_slug: slug
            }).done(function (res) {
                if (res.success && res.data.campaigns) {
                    var options = $.map(res.data.campaigns, function (c) {
                        return { value: c.campaign_id, label: c.campaign_name || c.campaign_id };
                    });
                    Optee.SearchableSelect.setOptions(ssId, options, '\u2014 Search campaigns\u2026 \u2014', keepSelection);
                } else {
                    Optee.SearchableSelect.setOptions(ssId, [], 'No campaigns found', keepSelection);
                }
            }).fail(function () {
                Optee.SearchableSelect.setOptions(ssId, [], 'Failed to load', keepSelection);
            });
        },

        statusBadge: function (status, id) {
            var playIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>';
            var pauseIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg>';

            if (status === 'active') {
                return '<span class="optee-badge-green optee-cache-icon-badge" style="cursor:pointer;" data-toggle-status="' + id + '" data-current="active">' + playIcon + ' Active</span>';
            }
            if (status === 'paused') {
                return '<span class="optee-badge-red optee-cache-icon-badge" style="cursor:pointer;" data-toggle-status="' + id + '" data-current="paused">' + pauseIcon + ' Paused</span>';
            }
            return '<span class="optee-badge-gray">' + Optee.Util.escapeHtml(status || 'unknown') + '</span>';
        },

        renderPagination: function (total, pages) {
            var $el = $('#optee-campaigns-pagination');
            if (pages <= 1) {
                $el.html('<span>' + total + ' campaign(s)</span>');
                return;
            }

            var cur = Campaigns.page;
            var chevL = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>';
            var chevR = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';

            var btnBase = 'display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border-radius:6px;font-size:13px;font-weight:500;border:1px solid;cursor:pointer;transition:all 0.15s;';
            var btnNormal = btnBase + 'background:#fff;color:#374151;border-color:#e5e7eb;';
            var btnActive = btnBase + 'background:#2aa5ff;color:#fff;border-color:#2aa5ff;cursor:default;';
            var btnDisabled = btnBase + 'background:#f9fafb;color:#d1d5db;border-color:#e5e7eb;cursor:default;pointer-events:none;';

            var html = '<span>' + total + ' campaign(s)</span>';
            html += '<div style="display:flex;align-items:center;gap:4px;">';

            // Prev button.
            html += '<button class="optee-pg-btn" data-page="' + (cur - 1) + '" style="' + (cur === 1 ? btnDisabled : btnNormal) + '"' + (cur === 1 ? ' disabled' : '') + '>' + chevL + '</button>';

            // Page numbers with ellipsis.
            var range = [];
            for (var i = 1; i <= pages; i++) {
                if (i === 1 || i === pages || (i >= cur - 1 && i <= cur + 1)) {
                    range.push(i);
                } else if (range[range.length - 1] !== '...') {
                    range.push('...');
                }
            }
            for (var r = 0; r < range.length; r++) {
                if (range[r] === '...') {
                    html += '<span style="min-width:32px;text-align:center;color:#9ca3af;font-size:13px;">...</span>';
                } else {
                    html += '<button class="optee-pg-btn" data-page="' + range[r] + '" style="' + (range[r] === cur ? btnActive : btnNormal) + '">' + range[r] + '</button>';
                }
            }

            // Next button.
            html += '<button class="optee-pg-btn" data-page="' + (cur + 1) + '" style="' + (cur === pages ? btnDisabled : btnNormal) + '"' + (cur === pages ? ' disabled' : '') + '>' + chevR + '</button>';

            html += '</div>';
            $el.html(html);

            $el.find('.optee-pg-btn').not('[disabled]').on('click', function () {
                Campaigns.page = parseInt($(this).data('page'));
                Campaigns.loadList();
            }).on('mouseenter', function () {
                if (parseInt($(this).data('page')) !== cur) $(this).css({ background: '#f3f4f6', borderColor: '#d1d5db' });
            }).on('mouseleave', function () {
                if (parseInt($(this).data('page')) !== cur) $(this).css({ background: '#fff', borderColor: '#e5e7eb' });
            });
        }
    };

    $(function () {
        Campaigns.init();
    });

})(jQuery);
