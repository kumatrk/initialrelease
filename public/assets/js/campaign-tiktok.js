/**
 * TikTok Cost Honeycomb helpers for campaign editor pickers.
 * Depends on .honeycomb-binding-panel[data-addon-slug="tiktok-cost"].
 */
(function (global) {
    'use strict';

    function tiktokPanel() {
        return document.querySelector('.honeycomb-binding-panel[data-addon-slug="tiktok-cost"]');
    }

    function tiktokApiBaseUrl() {
        const panel = tiktokPanel();
        if (panel) {
            const u = panel.getAttribute('data-tiktok-api-url');
            if (u) return u;
        }
        const root = (typeof global.APP_BASE_URL !== 'undefined' ? global.APP_BASE_URL : '');
        return root + '/api/honeycomb-addon.php?slug=tiktok-cost';
    }

    function tiktokApiUrl(action, extraQuery) {
        const base = tiktokApiBaseUrl();
        const join = base.indexOf('?') >= 0 ? '&' : '?';
        let url = base + join + 'action=' + encodeURIComponent(action);
        if (extraQuery) {
            url += '&' + extraQuery;
        }
        return url;
    }

    function tiktokApiConnected() {
        const panel = tiktokPanel();
        return !!(panel && panel.getAttribute('data-tiktok-api-connected') === '1');
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function tiktokApplyCampaignPicker() {
        const picker = document.getElementById('tiktok_campaign_picker');
        if (!picker || !picker.value) return;
        const opt = picker.options[picker.selectedIndex];
        if (!opt) return;
        const name = opt.getAttribute('data-name') || '';
        const remoteId = document.getElementById('tiktok_remote_campaign_id');
        const remoteName = document.getElementById('tiktok_remote_campaign_name');
        if (remoteId) remoteId.value = picker.value;
        if (remoteName) remoteName.value = name;
        const panel = tiktokPanel();
        if (panel) panel.setAttribute('data-tiktok-selected-campaign', picker.value);
    }

    function tiktokApplyAdvertiserPicker() {
        const adv = document.getElementById('tiktok_advertiser_picker');
        if (!adv || !adv.value) return;
        const accountEl = document.getElementById('tiktok_remote_account_id');
        if (accountEl) accountEl.value = adv.value;
        const panel = tiktokPanel();
        if (panel) panel.setAttribute('data-tiktok-selected-advertiser', adv.value);
        tiktokLoadCampaigns(true);
    }

    function tiktokLoadAdvertisers(thenLoadCampaigns) {
        const panel = tiktokPanel();
        const advPicker = document.getElementById('tiktok_advertiser_picker');
        const status = document.getElementById('tiktok_picker_status');
        if (!advPicker) return;
        if (!tiktokApiConnected()) return;

        if (status) status.textContent = 'Loading advertisers…';
        advPicker.innerHTML = '<option value="">Loading…</option>';

        fetch(tiktokApiUrl('advertisers'), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    advPicker.innerHTML = '<option value="">Could not load</option>';
                    if (status) status.textContent = (data && data.error) ? data.error : 'Failed to load advertisers.';
                    return;
                }
                if (!data.connected) {
                    advPicker.innerHTML = '<option value="">Connect under Honeycomb → TikTok Cost API</option>';
                    if (status) status.textContent = data.message || 'Not connected.';
                    return;
                }

                const selected = (panel && panel.getAttribute('data-tiktok-selected-advertiser'))
                    || (panel && panel.getAttribute('data-tiktok-default-advertiser'))
                    || (data.default_advertiser_id || '');
                const list = Array.isArray(data.advertisers) ? data.advertisers : [];
                let html = '<option value="">Select advertiser…</option>';
                list.forEach(function (a) {
                    const id = String(a.advertiser_id || '');
                    const name = String(a.advertiser_name || id);
                    const label = name + ' — ' + id;
                    const sel = id === String(selected) ? ' selected' : '';
                    html += '<option value="' + escapeHtml(id) + '"' + sel + '>' + escapeHtml(label) + '</option>';
                });
                advPicker.innerHTML = html;
                advPicker.setAttribute('data-loaded', '1');

                if (advPicker.value) {
                    const accountEl = document.getElementById('tiktok_remote_account_id');
                    if (accountEl && !accountEl.value.trim()) accountEl.value = advPicker.value;
                    if (thenLoadCampaigns !== false) {
                        tiktokLoadCampaigns(false);
                    }
                } else if (status) {
                    status.textContent = list.length
                        ? 'Select an advertiser to load campaigns.'
                        : 'No advertisers returned. Check app authorization.';
                }
            })
            .catch(function () {
                advPicker.innerHTML = '<option value="">Error</option>';
                if (status) status.textContent = 'Network error loading TikTok advertisers.';
            });
    }

    function tiktokLoadCampaigns(forceRefresh) {
        const panel = tiktokPanel();
        const picker = document.getElementById('tiktok_campaign_picker');
        const status = document.getElementById('tiktok_picker_status');
        const advPicker = document.getElementById('tiktok_advertiser_picker');
        if (!picker) return;
        if (!tiktokApiConnected()) return;

        const advertiserId = (advPicker && advPicker.value)
            || (panel && panel.getAttribute('data-tiktok-selected-advertiser'))
            || (document.getElementById('tiktok_remote_account_id') || {}).value
            || '';

        if (!String(advertiserId).trim()) {
            picker.innerHTML = '<option value="">Select advertiser first…</option>';
            if (status) status.textContent = 'Select an advertiser to load campaigns.';
            return;
        }

        if (!forceRefresh && picker.getAttribute('data-loaded') === '1'
            && picker.getAttribute('data-advertiser-id') === String(advertiserId)) {
            return;
        }

        if (status) status.textContent = 'Loading campaigns from TikTok…';
        picker.innerHTML = '<option value="">Loading…</option>';

        fetch(tiktokApiUrl('campaigns', 'advertiser_id=' + encodeURIComponent(advertiserId)), {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    picker.innerHTML = '<option value="">Could not load</option>';
                    if (status) status.textContent = (data && data.error) ? data.error : 'Failed to load campaigns.';
                    return;
                }
                if (!data.connected) {
                    picker.innerHTML = '<option value="">Connect under Honeycomb → TikTok Cost API</option>';
                    if (status) status.textContent = data.message || 'Not connected.';
                    return;
                }

                const selected = (panel && panel.getAttribute('data-tiktok-selected-campaign')) || '';
                const remoteIdEl = document.getElementById('tiktok_remote_campaign_id');
                const currentId = remoteIdEl ? remoteIdEl.value.trim() : '';
                let html = '<option value="">Select a TikTok campaign…</option>';
                const list = Array.isArray(data.campaigns) ? data.campaigns : [];
                list.forEach(function (c) {
                    const id = String(c.id || '');
                    const name = String(c.name || id);
                    const op = String(c.operation_status || '').toUpperCase();
                    const paused = (op === 'DISABLE' || op.indexOf('DISABLE') !== -1);
                    const label = (paused ? '(paused) ' : '') + name + ' — ' + id;
                    const sel = (id === selected || id === currentId) ? ' selected' : '';
                    html += '<option value="' + escapeHtml(id) + '"'
                        + ' data-name="' + escapeHtml(name) + '"'
                        + sel + '>' + escapeHtml(label) + '</option>';
                });
                picker.innerHTML = html;
                picker.setAttribute('data-loaded', '1');
                picker.setAttribute('data-advertiser-id', String(advertiserId));
                if (status) {
                    status.textContent = list.length
                        ? ('Loaded ' + list.length + ' campaign' + (list.length === 1 ? '' : 's') + '.')
                        : 'No campaigns found for this advertiser.';
                }
                if (picker.value) {
                    tiktokApplyCampaignPicker();
                }
            })
            .catch(function () {
                picker.innerHTML = '<option value="">Error</option>';
                if (status) status.textContent = 'Network error loading TikTok campaigns.';
            });
    }

    function tiktokInitPickers() {
        const panel = tiktokPanel();
        if (!panel || !tiktokApiConnected()) return;
        if (panel.getAttribute('data-tiktok-pickers-bound') === '1') {
            // Panel may have been re-shown; still try load if empty.
            const adv = document.getElementById('tiktok_advertiser_picker');
            if (adv && adv.getAttribute('data-loaded') !== '1') {
                tiktokLoadAdvertisers(true);
            }
            return;
        }
        panel.setAttribute('data-tiktok-pickers-bound', '1');

        const adv = document.getElementById('tiktok_advertiser_picker');
        const camp = document.getElementById('tiktok_campaign_picker');
        const refresh = document.getElementById('tiktok_refresh_campaigns_btn');
        if (adv) {
            adv.addEventListener('change', function () {
                tiktokApplyAdvertiserPicker();
            });
        }
        if (camp) {
            camp.addEventListener('change', function () {
                tiktokApplyCampaignPicker();
            });
        }
        if (refresh) {
            refresh.addEventListener('click', function () {
                tiktokLoadAdvertisers(true);
            });
        }
        tiktokLoadAdvertisers(true);
    }

    // When Honeycomb panels toggle visible (traffic source change), init if TikTok panel shows.
    function tiktokWatchPanelVisibility() {
        const panel = tiktokPanel();
        if (!panel || typeof MutationObserver === 'undefined') return;
        const obs = new MutationObserver(function () {
            if (panel.style.display !== 'none' && tiktokApiConnected()) {
                tiktokInitPickers();
            }
        });
        obs.observe(panel, { attributes: true, attributeFilter: ['style', 'class'] });
        if (panel.style.display !== 'none') {
            tiktokInitPickers();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tiktokWatchPanelVisibility);
    } else {
        tiktokWatchPanelVisibility();
    }

    global.tiktokLoadAdvertisers = tiktokLoadAdvertisers;
    global.tiktokLoadCampaigns = tiktokLoadCampaigns;
    global.tiktokApplyCampaignPicker = tiktokApplyCampaignPicker;
    global.tiktokApplyAdvertiserPicker = tiktokApplyAdvertiserPicker;
    global.tiktokInitPickers = tiktokInitPickers;
})(typeof window !== 'undefined' ? window : this);
