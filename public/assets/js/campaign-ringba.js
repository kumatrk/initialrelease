/**
 * Shared Ringba Honeycomb helpers for campaign editor + create wizard.
 * Depends on optional #ringba-lp-codes-panel and .honeycomb-binding-panel[data-addon-slug="ringba"].
 */
(function (global) {
    'use strict';

    function ringbaBindingEnabled() {
        const cb = document.getElementById('ringba_binding_enabled');
        if (cb) return !!cb.checked;
        const panel = document.getElementById('ringba-lp-codes-panel');
        return !!(panel && panel.getAttribute('data-enabled') === '1');
    }

    function ringbaReadField(id, dataAttr, fallback) {
        const el = document.getElementById(id);
        if (el && typeof el.value === 'string' && el.value.trim() !== '') {
            return el.value.trim();
        }
        const panel = document.getElementById('ringba-lp-codes-panel');
        if (panel) {
            const v = panel.getAttribute(dataAttr);
            if (v) return v;
        }
        return fallback || '';
    }

    function updateRingbaLpCodes() {
        const panel = document.getElementById('ringba-lp-codes-panel');
        const pre = document.getElementById('ringba-script-code');
        if (!panel || !pre) return;

        let tagId = ringbaReadField('ringba_js_tag_id', 'data-js-tag', '');
        tagId = tagId.replace(/\.js$/i, '').replace(/^https?:\/\/b-js\.ringba\.com\//i, '').replace(/\/$/, '');
        const clickParam = ringbaReadField('ringba_click_param', 'data-click-param', 'click_id') || 'click_id';
        const numberReplace = ringbaReadField('ringba_number_to_replace', 'data-number-replace', '');

        const safeParam = clickParam.replace(/[^a-zA-Z0-9_]/g, '') || 'click_id';
        const tagLiteral = tagId !== '' ? tagId : 'CAxxxxxxxxxxxxxxxx';
        const nl = String.fromCharCode(10);
        const closeScript = '<' + '/script>';

        pre.textContent = [
            '<script>',
            'window._rgba_tags = window._rgba_tags || [];',
            '(function () {',
            '  function ringbaTagClickId(cid) {',
            '    if (!cid) return;',
            '    window._rgba_tags.push({ type: "User", name: "' + safeParam + '", value: String(cid) });',
            '  }',
            '  var params = new URLSearchParams(window.location.search);',
            '  var cid = params.get("' + safeParam + '") || params.get("click_id") || params.get("cid") || "";',
            '  if (!cid && window.KUMA_CLICK_ID) cid = String(window.KUMA_CLICK_ID);',
            '  if (!cid && window.kumaClickId) cid = String(window.kumaClickId);',
            '  ringbaTagClickId(cid);',
            '  document.addEventListener("kuma:click_id", function (e) {',
            '    if (e && e.detail) ringbaTagClickId(e.detail);',
            '  });',
            '})();',
            closeScript,
            '<script src="//b-js.ringba.com/' + tagLiteral + '.js" async>' + closeScript
        ].join(nl);

        const hintWrap = document.getElementById('ringba-number-hint-wrap');
        const hintText = document.getElementById('ringba-number-hint-text');
        if (hintWrap && hintText) {
            if (numberReplace !== '') {
                hintWrap.style.display = 'block';
                hintText.textContent = numberReplace;
            } else {
                hintWrap.style.display = 'none';
            }
        }

        // Keep Options-style pixel hint in sync when present
        const pixelHint = document.getElementById('ringba-pixel-tag-hint');
        if (pixelHint) {
            pixelHint.textContent = '[tag:User:' + safeParam + ']';
        }
    }

    function toggleRingbaLpCodes() {
        const panel = document.getElementById('ringba-lp-codes-panel');
        if (!panel) return;
        const on = ringbaBindingEnabled();
        panel.style.display = on ? 'block' : 'none';
        if (on) {
            updateRingbaLpCodes();
            ringbaLoadCampaigns(false);
        }
    }

    function copyRingbaSnippet(elementId, btn) {
        const el = document.getElementById(elementId);
        if (!el) return;
        const text = el.textContent || '';
        const done = function () {
            if (!btn) return;
            const prev = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = prev; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                window.prompt('Copy:', text);
            });
        } else {
            window.prompt('Copy:', text);
        }
    }

    function ringbaApiBaseUrl() {
        const panel = document.querySelector('.honeycomb-binding-panel[data-addon-slug="ringba"]');
        if (panel) {
            const u = panel.getAttribute('data-ringba-api-url');
            if (u) return u;
        }
        return (typeof global.APP_BASE_URL !== 'undefined' ? global.APP_BASE_URL : '') + '/api/honeycomb-ringba.php';
    }

    function ringbaLoadCampaigns(forceRefresh) {
        const panel = document.querySelector('.honeycomb-binding-panel[data-addon-slug="ringba"]');
        const picker = document.getElementById('ringba_campaign_picker');
        const status = document.getElementById('ringba_picker_status');
        if (!picker) return;
        if (panel && panel.getAttribute('data-ringba-api-connected') !== '1') {
            return;
        }
        if (!forceRefresh && picker.getAttribute('data-loaded') === '1') {
            return;
        }
        if (status) status.textContent = 'Loading campaigns from Ringba…';
        picker.innerHTML = '<option value="">Loading…</option>';

        fetch(ringbaApiBaseUrl() + '?action=campaigns', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    picker.innerHTML = '<option value="">Could not load</option>';
                    if (status) status.textContent = (data && data.error) ? data.error : 'Failed to load campaigns.';
                    return;
                }
                if (!data.connected) {
                    picker.innerHTML = '<option value="">Connect API under Honeycomb → Ringba</option>';
                    if (status) status.textContent = data.message || 'Not connected.';
                    return;
                }
                const selected = (panel && panel.getAttribute('data-ringba-selected-campaign')) || '';
                const jsTagEl = document.getElementById('ringba_js_tag_id');
                const currentTag = jsTagEl ? jsTagEl.value.trim() : '';
                let html = '<option value="">Select a Ringba campaign…</option>';
                const list = Array.isArray(data.campaigns) ? data.campaigns : [];
                list.forEach(function (c) {
                    const id = String(c.id || '');
                    const name = String(c.name || id);
                    const scriptId = String(c.script_id || id);
                    const label = (c.enabled === false ? '(paused) ' : '') + name + ' — ' + id;
                    const sel = (id === selected || scriptId === currentTag || id === currentTag) ? ' selected' : '';
                    html += '<option value="' + id.replace(/"/g, '&quot;') + '"'
                        + ' data-script-id="' + scriptId.replace(/"/g, '&quot;') + '"'
                        + ' data-name="' + name.replace(/"/g, '&quot;') + '"'
                        + sel + '>' + label.replace(/</g, '&lt;') + '</option>';
                });
                picker.innerHTML = html;
                picker.setAttribute('data-loaded', '1');
                if (status) {
                    status.textContent = list.length
                        ? ('Loaded ' + list.length + ' campaign' + (list.length === 1 ? '' : 's') + '.')
                        : 'No campaigns found in this Ringba account.';
                }
                if (picker.value) {
                    ringbaApplyCampaignPicker();
                }
            })
            .catch(function () {
                picker.innerHTML = '<option value="">Error</option>';
                if (status) status.textContent = 'Network error loading Ringba campaigns.';
            });
    }

    function ringbaApplyCampaignPicker() {
        const picker = document.getElementById('ringba_campaign_picker');
        if (!picker || !picker.value) return;
        const opt = picker.options[picker.selectedIndex];
        if (!opt) return;
        const scriptId = opt.getAttribute('data-script-id') || picker.value;
        const name = opt.getAttribute('data-name') || '';
        const jsTag = document.getElementById('ringba_js_tag_id');
        const remoteId = document.getElementById('ringba_remote_campaign_id');
        const remoteName = document.getElementById('ringba_remote_campaign_name');
        if (jsTag) {
            jsTag.value = scriptId;
            updateRingbaLpCodes();
        }
        if (remoteId) remoteId.value = picker.value;
        if (remoteName) remoteName.value = name;
        fetch(ringbaApiBaseUrl() + '?action=tags&campaign_id=' + encodeURIComponent(picker.value), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !Array.isArray(data.tags)) return;
                const numEl = document.getElementById('ringba_number_to_replace');
                if (!numEl || numEl.value.trim() !== '') return;
                for (let i = 0; i < data.tags.length; i++) {
                    const n = String(data.tags[i].number_to_replace || '').trim();
                    if (n !== '') {
                        numEl.value = n;
                        updateRingbaLpCodes();
                        break;
                    }
                }
            })
            .catch(function () { /* ignore */ });
    }

    global.ringbaBindingEnabled = ringbaBindingEnabled;
    global.ringbaReadField = ringbaReadField;
    global.updateRingbaLpCodes = updateRingbaLpCodes;
    global.toggleRingbaLpCodes = toggleRingbaLpCodes;
    global.copyRingbaSnippet = copyRingbaSnippet;
    global.ringbaApiBaseUrl = ringbaApiBaseUrl;
    global.ringbaLoadCampaigns = ringbaLoadCampaigns;
    global.ringbaApplyCampaignPicker = ringbaApplyCampaignPicker;
})(typeof window !== 'undefined' ? window : this);
