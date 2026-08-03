/**
 * Meta campaign picker: load cached ACTIVE campaigns and on-demand sync.
 */
(function () {
    'use strict';

    var API_BASE = 'api/facebook-meta-campaigns.php';

    function apiUrl(action, adAccountId, force) {
        var q = '?action=' + encodeURIComponent(action) + '&ad_account_id=' + encodeURIComponent(adAccountId);
        if (force) {
            q += '&force=1';
        }
        return API_BASE + q;
    }

    function setStatus(el, message, isError) {
        if (!el) {
            return;
        }
        el.textContent = message || '';
        el.style.color = isError ? '#c62828' : '#666';
    }

    function populateSelect(selectEl, campaigns, selectedId) {
        if (!selectEl) {
            return;
        }
        var current = selectedId || selectEl.value || '';
        selectEl.innerHTML = '';
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = 'No Meta campaign (optional)';
        selectEl.appendChild(empty);
        campaigns.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = String(c.id);
            opt.textContent = c.name + ' (' + c.meta_campaign_id + ')';
            if (String(c.id) === String(current)) {
                opt.selected = true;
            }
            selectEl.appendChild(opt);
        });
        selectEl.disabled = false;
    }

    function loadList(adAccountId, selectEl, statusEl, selectedId) {
        if (!adAccountId) {
            if (selectEl) {
                selectEl.innerHTML = '<option value="">Select ad account first</option>';
                selectEl.disabled = true;
            }
            setStatus(statusEl, '');
            return Promise.resolve();
        }
        setStatus(statusEl, 'Loading campaigns…');
        return fetch(apiUrl('list', adAccountId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    setStatus(statusEl, data.error || 'Failed to load campaigns', true);
                    return;
                }
                populateSelect(selectEl, data.campaigns || [], selectedId);
                var suffix = data.cached ? ' (from cache)' : '';
                setStatus(statusEl, (data.count || 0) + ' active campaign(s)' + suffix);
            })
            .catch(function (err) {
                setStatus(statusEl, err.message || 'Request failed', true);
            });
    }

    function syncCampaigns(adAccountId, selectEl, statusEl, btn, selectedId) {
        if (!adAccountId) {
            setStatus(statusEl, 'Select an ad account first', true);
            return Promise.resolve();
        }
        if (btn) {
            btn.disabled = true;
        }
        setStatus(statusEl, 'Syncing from Meta…');
        return fetch(apiUrl('sync', adAccountId, true))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    setStatus(statusEl, data.error || 'Sync failed', true);
                    return;
                }
                populateSelect(selectEl, data.campaigns || [], selectedId);
                setStatus(statusEl, 'Synced ' + (data.count || 0) + ' active campaign(s)');
            })
            .catch(function (err) {
                setStatus(statusEl, err.message || 'Sync failed', true);
            })
            .finally(function () {
                if (btn) {
                    btn.disabled = false;
                }
            });
    }

    function initPicker(options) {
        var adSelect = document.getElementById(options.adAccountSelectId || 'facebook_marketing_ad_account_id');
        var campaignSelect = document.getElementById(options.campaignSelectId || 'facebook_marketing_campaign_id');
        var refreshBtn = document.getElementById(options.refreshButtonId || 'fb_refresh_meta_campaigns_btn');
        var statusEl = document.getElementById(options.statusId || 'fb_meta_campaign_status');
        var block = document.getElementById(options.blockId || 'facebook_meta_campaign_field');
        var selectedId = options.selectedCampaignId || '';

        if (!adSelect || !campaignSelect) {
            return;
        }

        function onAccountChange() {
            var id = adSelect.value;
            if (!id) {
                campaignSelect.innerHTML = '<option value="">Select ad account first</option>';
                campaignSelect.disabled = true;
                setStatus(statusEl, '');
                return;
            }
            loadList(id, campaignSelect, statusEl, selectedId);
        }

        adSelect.addEventListener('change', function () {
            selectedId = '';
            onAccountChange();
        });

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function (e) {
                e.preventDefault();
                syncCampaigns(adSelect.value, campaignSelect, statusEl, refreshBtn, campaignSelect.value);
            });
        }

        if (adSelect.value) {
            onAccountChange();
        } else {
            campaignSelect.disabled = true;
        }

        // Visibility is controlled by the parent #facebook_integration_field (toggleFacebookIntegration).
    }

    window.FacebookCampaignPicker = {
        init: initPicker,
        loadList: loadList,
        sync: syncCampaigns,
    };
})();
