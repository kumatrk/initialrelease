/**
 * Campaign create wizard — step navigation, validation, and form helpers.
 */
(function () {
    'use strict';

    const TOTAL_STEPS = 5;
    let currentStep = 1;

    function getForm() {
        return document.getElementById('campaign-create-form');
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function setNavButtonVisible(btn, visible) {
        if (!btn) return;
        btn.classList.toggle('wizard-nav-btn--hidden', !visible);
        btn.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    let progressPctAnimFrame = null;

    function updateProgress(step) {
        document.querySelectorAll('.campaign-wizard-progress-step').forEach(function (el) {
            const n = parseInt(el.getAttribute('data-step'), 10);
            el.classList.toggle('is-active', n === step);
            el.classList.toggle('is-done', n < step);
        });

        const fill = document.getElementById('wizard-progress-fill');
        if (fill) {
            const pct = ((step - 1) / (TOTAL_STEPS - 1)) * 100;
            fill.style.width = pct + '%';
        }

        animateProgressPct(step);
    }

    function animateProgressPct(step) {
        const bar = document.getElementById('wizard-progress-pct');
        if (!bar) return;

        const target = Math.round(((step - 1) / (TOTAL_STEPS - 1)) * 100);
        const start = parseInt(bar.getAttribute('data-pct') || '0', 10);
        if (start === target) {
            bar.textContent = target + '%';
            return;
        }

        if (progressPctAnimFrame) {
            cancelAnimationFrame(progressPctAnimFrame);
        }

        bar.classList.add('is-ticking');
        const duration = 420;
        const t0 = performance.now();

        function tick(now) {
            const t = Math.min(1, (now - t0) / duration);
            const eased = 1 - Math.pow(1 - t, 3);
            const value = Math.round(start + (target - start) * eased);
            bar.textContent = value + '%';
            if (t < 1) {
                progressPctAnimFrame = requestAnimationFrame(tick);
            } else {
                bar.setAttribute('data-pct', String(target));
                bar.classList.remove('is-ticking');
                progressPctAnimFrame = null;
            }
        }

        progressPctAnimFrame = requestAnimationFrame(tick);
    }

    function showStep(step) {
        document.querySelectorAll('.wizard-step').forEach(function (el) {
            const n = parseInt(el.getAttribute('data-step'), 10);
            el.classList.toggle('is-active', n === step);
            el.setAttribute('aria-hidden', n === step ? 'false' : 'true');
        });
        currentStep = step;
        updateProgress(step);

        setNavButtonVisible(document.getElementById('wizard-btn-back'), step > 1);
        setNavButtonVisible(document.getElementById('wizard-btn-next'), step < TOTAL_STEPS);
        setNavButtonVisible(document.getElementById('wizard-btn-submit'), step === TOTAL_STEPS);

        if (step === TOTAL_STEPS) {
            buildReviewSummary();
        }
        if (step === 2 && typeof window.toggleFacebookIntegration === 'function') {
            window.toggleFacebookIntegration();
        }
    }

    function clearStepErrors(step) {
        const panel = document.querySelector('.wizard-step[data-step="' + step + '"]');
        if (!panel) return;
        panel.querySelectorAll('.wizard-field-error').forEach(function (el) {
            el.remove();
        });
        panel.querySelectorAll('.wizard-input-error').forEach(function (el) {
            el.classList.remove('wizard-input-error');
        });
    }

    function showFieldError(field, message) {
        if (!field) return;
        field.classList.add('wizard-input-error');
        const err = document.createElement('div');
        err.className = 'wizard-field-error';
        err.textContent = message;
        field.parentElement.appendChild(err);
    }

    function validateStep(step) {
        clearStepErrors(step);
        const form = getForm();
        if (!form) return false;

        if (step === 1) {
            const name = form.querySelector('[name="name"]');
            if (!name || !name.value.trim()) {
                showFieldError(name, 'Campaign name is required.');
                return false;
            }
        }

        if (step === 2) {
            const ts = form.querySelector('[name="traffic_source_id"]');
            if (!ts || !ts.value || ts.value === '0') {
                showFieldError(ts, 'Please select Facebook or a custom traffic source.');
                return false;
            }
            const opt = ts.options[ts.selectedIndex];
            if (opt && opt.disabled) {
                showFieldError(ts, 'This traffic source is not available in this release.');
                return false;
            }
        }

        if (step === 3) {
            const flowType = form.querySelector('[name="flow_type"]').value || 'DTO';
            let hasOffer = false;
            form.querySelectorAll('.offer-rotation-row').forEach(function (row) {
                const cb = row.querySelector('input[type="checkbox"]');
                const sel = row.querySelector('select[name="offer_id[]"]');
                if (cb && cb.checked && sel && sel.value) hasOffer = true;
            });
            if (!hasOffer) {
                const c = document.getElementById('offer_rotation_items');
                if (c) {
                    const e = document.createElement('div');
                    e.className = 'wizard-field-error';
                    e.textContent = 'Add at least one enabled offer.';
                    c.appendChild(e);
                }
                return false;
            }
            if (flowType === 'LP' || flowType === 'Split') {
                let hasLp = false;
                const lp = document.getElementById('lp_items');
                if (lp) {
                    lp.querySelectorAll('div[style*="grid-template-columns"]').forEach(function (row) {
                        const cb = row.querySelector('input[type="checkbox"]');
                        const sel = row.querySelector('select[name="lp_id[]"]');
                        if (cb && cb.checked && sel && sel.value) hasLp = true;
                    });
                }
                if (!hasLp) {
                    const e = document.createElement('div');
                    e.className = 'wizard-field-error';
                    e.textContent = 'Add at least one enabled landing page.';
                    lp.appendChild(e);
                    return false;
                }
            }
        }

        return true;
    }

    function buildReviewSummary() {
        const form = getForm();
        const dl = document.getElementById('wizard-review-dl');
        if (!form || !dl) return;
        const name = form.querySelector('[name="name"]').value || '—';
        const status = form.querySelector('[name="status"]').selectedOptions[0].text;
        const ts = form.querySelector('[name="traffic_source_id"]').selectedOptions[0].text.trim();
        const flow = form.querySelector('[name="flow_type"]').selectedOptions[0].text;
        const referrerModeSel = form.querySelector('[name="referrer_mode"]');
        const referrerMode = referrerModeSel ? referrerModeSel.selectedOptions[0].text.trim() : '—';
        const fbAcctSel = form.querySelector('[name="facebook_marketing_ad_account_id"]');
        const fbCampSel = form.querySelector('[name="facebook_marketing_campaign_id"]');
        const fbAcct = fbAcctSel && fbAcctSel.value ? fbAcctSel.selectedOptions[0].text.trim() : '—';
        const fbCamp = fbCampSel && fbCampSel.value ? fbCampSel.selectedOptions[0].text.trim() : '—';
        let offers = 0;
        form.querySelectorAll('.offer-rotation-row').forEach(function (row) {
            const cb = row.querySelector('input[type="checkbox"]');
            const sel = row.querySelector('select[name="offer_id[]"]');
            if (cb && cb.checked && sel && sel.value) offers++;
        });
        const multiConvCb = form.querySelector('[name="allow_multiple_conversions"]');
        const multiConv = multiConvCb && multiConvCb.checked ? 'Yes' : 'No';
        dl.innerHTML =
            '<dt>Campaign name</dt><dd>' + escapeHtml(name) + '</dd>' +
            '<dt>Status</dt><dd>' + escapeHtml(status) + '</dd>' +
            '<dt>Referrer privacy</dt><dd>' + escapeHtml(referrerMode) + '</dd>' +
            '<dt>Traffic source</dt><dd>' + escapeHtml(ts) + '</dd>' +
            '<dt>Facebook ad account</dt><dd>' + escapeHtml(fbAcct) + '</dd>' +
            '<dt>Meta campaign</dt><dd>' + escapeHtml(fbCamp) + '</dd>' +
            '<dt>Flow type</dt><dd>' + escapeHtml(flow) + '</dd>' +
            '<dt>Enabled offers</dt><dd>' + offers + '</dd>' +
            '<dt>Multiple conversions / click</dt><dd>' + multiConv + '</dd>';
    }

    function goNext() {
        if (!validateStep(currentStep)) return;
        if (currentStep < TOTAL_STEPS) showStep(currentStep + 1);
    }

    function goBack() {
        if (currentStep > 1) showStep(currentStep - 1);
    }

    function initWizard() {
        const initial = parseInt(document.body.getAttribute('data-initial-step') || '1', 10);
        currentStep = initial >= 1 && initial <= TOTAL_STEPS ? initial : 1;
        const nextBtn = document.getElementById('wizard-btn-next');
        const backBtn = document.getElementById('wizard-btn-back');
        const form = getForm();
        if (nextBtn) nextBtn.addEventListener('click', goNext);
        if (backBtn) backBtn.addEventListener('click', goBack);
        if (!form) return;
        form.addEventListener('submit', function (e) {
            if (currentStep < TOTAL_STEPS) {
                e.preventDefault();
                goNext();
                return;
            }
            for (let s = 1; s <= 3; s++) {
                if (!validateStep(s)) {
                    e.preventDefault();
                    showStep(s);
                    return;
                }
            }
            if (window.validateAllSlugs && !window.validateAllSlugs()) {
                e.preventDefault();
                showStep(4);
                return;
            }
            // Disabled rotation fields are omitted from POST and break index alignment
            // with offer_enabled[n] / lp_enabled[n]. Re-enable so they still submit.
            [
                '#offer_rotation_items select[name="offer_id[]"]',
                '#offer_rotation_items input[name="offer_weight[]"]',
                '#lp_items select[name="lp_id[]"]',
                '#lp_items input[name="lp_weight[]"]'
            ].forEach(function (sel) {
                form.querySelectorAll(sel).forEach(function (el) {
                    if (el.disabled) {
                        el.disabled = false;
                    }
                });
            });
        });
        const pctBar = document.getElementById('wizard-progress-pct');
        if (pctBar) {
            pctBar.setAttribute('data-pct', '0');
        }
        showStep(currentStep);
    }

    window.updateFlowFields = function () {
        const ft = document.getElementById('flow_type').value;
        document.getElementById('lp_fields').style.display = ft === 'LP' || ft === 'Split' ? 'block' : 'none';
        document.getElementById('split_fields').style.display = ft === 'Split' ? 'block' : 'none';
    };

    window.updateSplitPercentage = function () {
        const input = document.getElementById('split_traffic_to_lp_input');
        const span = document.getElementById('split_remaining_percent');
        if (input && span) {
            const p = parseInt(input.value, 10) || 0;
            span.textContent = '(' + (100 - p) + '% Direct)';
        }
    };

    window.toggleFacebookIntegration = function () {
        const ts = document.getElementById('traffic_source_id');
        const fb = document.getElementById('facebook_integration_field');
        const metaBlock = document.getElementById('facebook_meta_campaign_field');
        if (!ts || !fb) return;
        const opt = ts.options[ts.selectedIndex];
        const isFacebook = opt && opt.getAttribute('data-is-facebook') === '1';
        fb.style.display = isFacebook ? 'block' : 'none';
        if (metaBlock) {
            metaBlock.style.display = isFacebook ? 'block' : 'none';
        }
    };

    window.toggleGoogleAdsIntegration = function () {
        const ts = document.getElementById('traffic_source_id');
        const googleField = document.getElementById('google_ads_integration_field');
        const googleSelect = document.getElementById('google_ads_integration_id');
        if (!ts || !googleField) return;
        const opt = ts.options[ts.selectedIndex];
        const isGoogle = opt && opt.getAttribute('data-is-google') === '1';
        googleField.style.display = isGoogle ? 'block' : 'none';
        if (googleSelect) {
            googleSelect.required = false;
        }
    };

    window.toggleTrafficSourceSelector = function () {
        const ts = document.getElementById('traffic_source_id');
        const sec = document.getElementById('traffic_source_postbacks_section');
        if (sec) {
            sec.style.display = 'none';
        }
        toggleFacebookIntegration();
        toggleGoogleAdsIntegration();
    };

    function setRotationControlLocked(select, weightInput, isEnabled) {
        // Never use HTML disabled — omitted POST fields reindex against offer_enabled[n].
        if (select) {
            select.disabled = false;
            select.setAttribute('aria-disabled', isEnabled ? 'false' : 'true');
            select.tabIndex = isEnabled ? 0 : -1;
            select.style.pointerEvents = isEnabled ? '' : 'none';
            select.style.background = isEnabled ? '' : '#f5f5f5';
            select.style.color = isEnabled ? '' : '#999';
            select.style.cursor = isEnabled ? '' : 'not-allowed';
        }
        if (weightInput) {
            weightInput.disabled = false;
            weightInput.readOnly = !isEnabled;
            weightInput.setAttribute('aria-disabled', isEnabled ? 'false' : 'true');
            weightInput.style.background = isEnabled ? '' : '#f5f5f5';
            weightInput.style.color = isEnabled ? '' : '#999';
            weightInput.style.cursor = isEnabled ? '' : 'not-allowed';
        }
    }

    function redistributeOfferWeights() {
        const container = document.getElementById('offer_rotation_items');
        if (!container) return;
        const rows = Array.prototype.filter.call(container.querySelectorAll('.offer-rotation-row'), function (row) {
            const cb = row.querySelector('input[type="checkbox"]');
            return cb && cb.checked;
        });
        if (rows.length === 0) return;
        let total = 0;
        rows.forEach(function (row) {
            const w = row.querySelector('input[name="offer_weight[]"]');
            if (w) total += parseFloat(w.value) || 0;
        });
        if (total === 0) {
            const base = Math.floor(100 / rows.length);
            const rem = 100 - base * rows.length;
            rows.forEach(function (row, idx) {
                const w = row.querySelector('input[name="offer_weight[]"]');
                if (w) w.value = idx === rows.length - 1 ? base + rem : base;
            });
        }
    }

    window.handleOfferEnabledChange = function (idx, on) {
        const h = document.getElementById('offer_enabled_hidden_' + idx);
        if (h) h.value = on ? '1' : '0';
        const w = document.getElementById('offer_weight_' + idx);
        setRotationControlLocked(
            document.getElementById('offer_select_' + idx),
            w,
            on
        );
        if (w && !on) w.value = '0';
        redistributeOfferWeights();
    };

    window.handleLPEnabledChange = function (idx, on) {
        const h = document.getElementById('lp_enabled_hidden_' + idx);
        if (h) h.value = on ? '1' : '0';
        const w = document.getElementById('lp_weight_' + idx);
        setRotationControlLocked(
            document.getElementById('lp_select_' + idx),
            w,
            on
        );
        if (w && !on) w.value = '0';
    };

    window.equalizeOfferWeights = function () {
        const c = document.getElementById('offer_rotation_items');
        const rows = Array.prototype.filter.call(c.querySelectorAll('.offer-rotation-row'), function (r) {
            return r.querySelector('input[type="checkbox"]').checked;
        });
        if (rows.length === 0) {
            alert('Enable at least one offer.');
            return;
        }
        const base = Math.floor(100 / rows.length);
        const rem = 100 - base * rows.length;
        rows.forEach(function (row, i) {
            row.querySelector('input[name="offer_weight[]"]').value = i === rows.length - 1 ? base + rem : base;
        });
    };

    window.equalizeLPWeights = function () {
        const c = document.getElementById('lp_items');
        const all = c.querySelectorAll('div[style*="grid-template-columns"]');
        const enabled = Array.prototype.filter.call(all, function (row) {
            return row.querySelector('input[type="checkbox"]').checked;
        });
        if (enabled.length === 0) {
            alert('Enable at least one landing page.');
            return;
        }
        const base = Math.floor(100 / enabled.length);
        const rem = 100 - base * enabled.length;
        enabled.forEach(function (row, i) {
            row.querySelector('input[name="lp_weight[]"]').value = i === enabled.length - 1 ? base + rem : base;
        });
    };

    window.addOfferRotationItem = function () {
        const c = document.getElementById('offer_rotation_items');
        const rows = c.querySelectorAll('.offer-rotation-row');
        if (!rows.length) return;
        const idx = rows.length;
        const item = rows[0].cloneNode(true);
        item.querySelector('select[name="offer_id[]"]').selectedIndex = 0;
        item.querySelector('input[name="offer_weight[]"]').value = '';
        const hidden = item.querySelector('input[type="hidden"]');
        const cb = item.querySelector('input[type="checkbox"]');
        hidden.name = 'offer_enabled[' + idx + ']';
        hidden.id = 'offer_enabled_hidden_' + idx;
        hidden.value = '1';
        cb.id = 'offer_checkbox_' + idx;
        cb.checked = true;
        cb.onchange = function () {
            handleOfferEnabledChange(idx, this.checked);
        };
        item.querySelector('select[name="offer_id[]"]').id = 'offer_select_' + idx;
        item.querySelector('input[name="offer_weight[]"]').id = 'offer_weight_' + idx;
        setRotationControlLocked(
            item.querySelector('select[name="offer_id[]"]'),
            item.querySelector('input[name="offer_weight[]"]'),
            true
        );
        c.appendChild(item);
    };

    window.addLPItem = function () {
        const c = document.getElementById('lp_items');
        const rows = c.querySelectorAll('div[style*="grid-template-columns"]');
        if (!rows.length) return;
        const idx = rows.length;
        const item = rows[0].cloneNode(true);
        item.querySelector('select[name="lp_id[]"]').selectedIndex = 0;
        item.querySelector('input[name="lp_weight[]"]').value = '';
        const hidden = item.querySelector('input[type="hidden"]');
        const cb = item.querySelector('input[type="checkbox"]');
        hidden.name = 'lp_enabled[' + idx + ']';
        hidden.id = 'lp_enabled_hidden_' + idx;
        hidden.value = '1';
        cb.id = 'lp_checkbox_' + idx;
        cb.checked = true;
        cb.onchange = function () {
            handleLPEnabledChange(idx, this.checked);
        };
        item.querySelector('select[name="lp_id[]"]').id = 'lp_select_' + idx;
        item.querySelector('input[name="lp_weight[]"]').id = 'lp_weight_' + idx;
        setRotationControlLocked(
            item.querySelector('select[name="lp_id[]"]'),
            item.querySelector('input[name="lp_weight[]"]'),
            true
        );
        c.appendChild(item);
    };

    window.initializeDisabledStates = function () {
        document.querySelectorAll('.offer-rotation-row').forEach(function (row) {
            const cb = row.querySelector('input[type="checkbox"]');
            if (cb && cb.id.indexOf('offer_checkbox_') === 0) {
                const idx = cb.id.replace('offer_checkbox_', '');
                cb.onchange = function () {
                    handleOfferEnabledChange(idx, this.checked);
                };
                handleOfferEnabledChange(idx, cb.checked);
            }
        });
        document.getElementById('lp_items').querySelectorAll('div[style*="grid-template-columns"]').forEach(function (row) {
            const cb = row.querySelector('input[type="checkbox"]');
            if (cb && cb.id.indexOf('lp_checkbox_') === 0) {
                const idx = cb.id.replace('lp_checkbox_', '');
                cb.onchange = function () {
                    handleLPEnabledChange(idx, this.checked);
                };
                handleLPEnabledChange(idx, cb.checked);
            }
        });
    };

    function collectCustomTokensFromForm() {
        const tokens = [];
        document.querySelectorAll('#custom_tokens_container .custom-token-row').forEach(function (row) {
            const paramInp = row.querySelector('input[name="custom_token_parameter[]"]');
            const nameInp = row.querySelector('input[name="custom_token_name[]"]');
            if (!paramInp) return;
            const parameter = paramInp.value.trim();
            if (parameter === '') return;
            let name = nameInp ? nameInp.value.trim() : '';
            if (name === '') name = parameter;
            tokens.push({ name: name, parameter: parameter });
        });
        return tokens;
    }

    function collectTrafficSourceTokensFromForm() {
        const tsSelect = document.getElementById('traffic_source_id');
        const map = window.trafficSourceTokensById || {};
        if (!tsSelect || !tsSelect.value || tsSelect.value === '0') return [];
        return map[String(tsSelect.value)] || [];
    }

    function getRedirectRuleTokenData() {
        const base = window.redirectRuleTokens || { custom: [], builtIn: [], trafficSource: [] };
        return {
            custom: collectCustomTokensFromForm(),
            builtIn: base.builtIn || [],
            trafficSource: collectTrafficSourceTokensFromForm(),
        };
    }

    function buildRedirectRuleTokenOptionsHtml(selectedValue) {
        const tokenData = getRedirectRuleTokenData();
        let html = '<option value="">Select token...</option>';

        if (tokenData.custom && tokenData.custom.length > 0) {
            html += '<optgroup label="Custom Tokens">';
            tokenData.custom.forEach(function (token) {
                if (!token.name) return;
                const id = 'custom:' + token.name;
                html += '<option value="' + escapeHtml(id) + '"' + (selectedValue === id ? ' selected' : '') + '>' + escapeHtml(token.name) + '</option>';
            });
            html += '</optgroup>';
        }

        if (tokenData.builtIn && tokenData.builtIn.length > 0) {
            html += '<optgroup label="Built-in Tokens">';
            tokenData.builtIn.forEach(function (token) {
                if (!token.name) return;
                const id = 'builtin:' + token.name;
                html += '<option value="' + escapeHtml(id) + '"' + (selectedValue === id ? ' selected' : '') + '>' + escapeHtml(token.name) + '</option>';
            });
            html += '</optgroup>';
        }

        if (tokenData.trafficSource && tokenData.trafficSource.length > 0) {
            const grouped = {};
            tokenData.trafficSource.forEach(function (token) {
                const source = token.source || 'Unknown Traffic Source';
                if (!grouped[source]) grouped[source] = [];
                grouped[source].push(token);
            });
            Object.keys(grouped).forEach(function (sourceName) {
                html += '<optgroup label="Traffic Source: ' + escapeHtml(sourceName) + '">';
                grouped[sourceName].forEach(function (token) {
                    if (!token.name) return;
                    const id = 'traffic_source:' + sourceName + ':' + token.name;
                    html += '<option value="' + escapeHtml(id) + '"' + (selectedValue === id ? ' selected' : '') + '>' + escapeHtml(token.name) + '</option>';
                });
                html += '</optgroup>';
            });
        }

        return html;
    }

    window.refreshRedirectRuleTokenDropdowns = function () {
        document.querySelectorAll('#redirect_rules_container select[name="redirect_rule_token[]"]').forEach(function (sel) {
            const current = sel.value;
            sel.innerHTML = buildRedirectRuleTokenOptionsHtml(current);
            if (current) {
                const match = Array.prototype.find.call(sel.options, function (opt) {
                    return opt.value === current;
                });
                if (!match) sel.value = '';
            }
        });
    };

    function updateRedirectRuleNumbers() {
        const container = document.getElementById('redirect_rules_container');
        if (!container) return;
        container.querySelectorAll('.redirect-rule-row').forEach(function (row, idx) {
            const label = row.querySelector('.redirect-rule-number');
            if (label) label.textContent = 'Rule #' + (idx + 1);
        });
    }

    window.updateTokenUrlAppend = function (input) {
        const row = input.closest('.custom-token-row');
        if (!row) return;
        const p = row.querySelector('input[name="custom_token_parameter[]"]').value.trim();
        const ph = row.querySelector('input[name="custom_token_placeholder[]"]').value.trim();
        const d = row.querySelector('.url-append-display');
        if (d) {
            d.value = p && ph ? '&' + p + '=' + ph : p ? '&' + p + '=' : '';
        }
        refreshRedirectRuleTokenDropdowns();
    };

    window.addCustomTokenRow = function () {
        const c = document.getElementById('custom_tokens_container');
        if (c.querySelectorAll('.custom-token-row').length >= 10) {
            alert('Maximum 10 tokens.');
            return;
        }
        const clone = c.querySelector('.custom-token-row').cloneNode(true);
        clone.querySelectorAll('input').forEach(function (inp) {
            if (inp.type === 'checkbox') inp.checked = false;
            else inp.value = '';
        });
        c.appendChild(clone);
        refreshRedirectRuleTokenDropdowns();
    };

    window.removeCustomTokenRow = function (btn) {
        const c = document.getElementById('custom_tokens_container');
        if (c.querySelectorAll('.custom-token-row').length <= 1) {
            alert('At least one row required.');
            return;
        }
        btn.closest('.custom-token-row').remove();
        refreshRedirectRuleTokenDropdowns();
    };

    window.addRedirectRule = function (prefill) {
        const container = document.getElementById('redirect_rules_container');
        if (!container) return;

        prefill = prefill || {};
        const ruleIndex = container.querySelectorAll('.redirect-rule-row').length;
        const tokenId = prefill.token_identifier || '';
        const tokenOptionsHtml = buildRedirectRuleTokenOptionsHtml(tokenId);
        const op = prefill.operator || 'equals';
        const val = prefill.value || '';
        const url = prefill.redirect_url || '';
        const caseSens = prefill.case_sensitive ? '1' : '0';
        const executeOn = prefill.execute_on && prefill.execute_on.length ? prefill.execute_on : ['campaign_click', 'offer_click'];
        const onCampaign = executeOn.indexOf('campaign_click') !== -1 ? ' checked' : '';
        const onOffer = executeOn.indexOf('offer_click') !== -1 ? ' checked' : '';

        const row = document.createElement('div');
        row.className = 'redirect-rule-row';
        row.style.cssText = 'background:#f9f9f9;padding:16px;border:2px solid #ddd;border-radius:4px;margin-bottom:12px;';
        row.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">' +
            '<strong class="redirect-rule-number" style="color:#3d5a26;">Rule #' + (ruleIndex + 1) + '</strong>' +
            '<button type="button" class="btn btn-outline" onclick="removeRedirectRule(this)" style="padding:4px 8px;font-size:12px;color:#d32f2f;">× Remove</button>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:2fr 1.5fr 2fr 3fr;gap:12px;margin-bottom:12px;">' +
            '<div><label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;">Token Name</label>' +
            '<select name="redirect_rule_token[]" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;">' + tokenOptionsHtml + '</select></div>' +
            '<div><label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;">Operator</label>' +
            '<select name="redirect_rule_operator[]" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;">' +
            '<option value="equals"' + (op === 'equals' ? ' selected' : '') + '>Equals (=)</option>' +
            '<option value="not_equals"' + (op === 'not_equals' ? ' selected' : '') + '>Not Equals (≠)</option>' +
            '<option value="contains"' + (op === 'contains' ? ' selected' : '') + '>Contains</option>' +
            '<option value="starts_with"' + (op === 'starts_with' ? ' selected' : '') + '>Starts With</option>' +
            '<option value="ends_with"' + (op === 'ends_with' ? ' selected' : '') + '>Ends With</option>' +
            '</select></div>' +
            '<div><label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;">Value to Match</label>' +
            '<input type="text" name="redirect_rule_value[]" value="' + escapeHtml(val) + '" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;" placeholder="Value"></div>' +
            '<div><label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;">Redirect URL</label>' +
            '<input type="url" name="redirect_rule_url[]" value="' + escapeHtml(url) + '" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;" placeholder="https://example.com"></div>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">' +
            '<div><label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;">Case Sensitive</label>' +
            '<select name="redirect_rule_case_sensitive[]" style="width:100%;padding:8px;border:2px solid #ddd;border-radius:4px;">' +
            '<option value="0"' + (caseSens === '0' ? ' selected' : '') + '>No (Case-insensitive)</option>' +
            '<option value="1"' + (caseSens === '1' ? ' selected' : '') + '>Yes (Case-sensitive)</option>' +
            '</select></div>' +
            '<div><label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;">Execute On</label>' +
            '<div style="display:flex;gap:16px;margin-top:8px;">' +
            '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" name="redirect_rule_execute_on_' + ruleIndex + '[]" value="campaign_click"' + onCampaign + '><span style="font-size:12px;">Campaign Click</span></label>' +
            '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" name="redirect_rule_execute_on_' + ruleIndex + '[]" value="offer_click"' + onOffer + '><span style="font-size:12px;">Offer Click</span></label>' +
            '</div></div></div>';

        container.appendChild(row);
        updateRedirectRuleNumbers();
    };

    window.removeRedirectRule = function (btn) {
        btn.closest('.redirect-rule-row').remove();
        updateRedirectRuleNumbers();
    };

    function bootstrapRedirectRulesFromPost() {
        const rules = window.wizardRedirectRulesFromPost;
        if (!rules || !rules.length) return;
        const container = document.getElementById('redirect_rules_container');
        if (!container || container.querySelectorAll('.redirect-rule-row').length > 0) return;
        rules.forEach(function (rule) {
            addRedirectRule(rule);
        });
    }

    function bindCustomTokenInputListeners() {
        const c = document.getElementById('custom_tokens_container');
        if (!c || c.getAttribute('data-token-listeners') === '1') return;
        c.setAttribute('data-token-listeners', '1');
        c.addEventListener('input', function (e) {
            if (e.target.matches('input[name="custom_token_name[]"], input[name="custom_token_parameter[]"]')) {
                refreshRedirectRuleTokenDropdowns();
            }
        });
        c.addEventListener('change', function (e) {
            if (e.target.matches('input[name="custom_token_placeholder[]"]')) {
                updateTokenUrlAppend(e.target);
            }
        });
    }

    window.addSlugRow = function () {
        const c = document.getElementById('slug-items-container');
        const clone = c.querySelector('.slug-row').cloneNode(true);
        clone.querySelectorAll('input').forEach(function (i) {
            i.value = '';
        });
        c.appendChild(clone);
    };

    window.removeSlugRow = function (btn) {
        const c = document.getElementById('slug-items-container');
        if (c.querySelectorAll('.slug-row').length <= 1) return;
        btn.closest('.slug-row').remove();
    };

    window.validateSlugInput = function (input) {
        const err = input.parentElement.querySelector('.slug-error');
        if (!err) return;
        err.style.display = 'none';
        if (input.value && !/^[a-zA-Z0-9_-]+$/.test(input.value.trim())) {
            err.textContent = 'Invalid slug format.';
            err.style.display = 'block';
            input.style.borderColor = '#d32f2f';
        } else {
            input.style.borderColor = '#ddd';
        }
    };

    window.validateAllSlugs = function () {
        const seen = {};
        let ok = true;
        document.querySelectorAll('#slug-items-container input[name="slug[]"]').forEach(function (inp) {
            const s = inp.value.trim().toLowerCase();
            if (!s) return;
            if (seen[s]) {
                ok = false;
                inp.style.borderColor = '#d32f2f';
            }
            seen[s] = true;
        });
        if (!ok) alert('Duplicate slugs found.');
        return ok;
    };

    document.addEventListener('DOMContentLoaded', function () {
        initWizard();
        toggleTrafficSourceSelector();
        initializeDisabledStates();
        updateFlowFields();
        const tsSelect = document.getElementById('traffic_source_id');
        if (tsSelect) {
            tsSelect.addEventListener('change', function () {
                toggleTrafficSourceSelector();
                refreshRedirectRuleTokenDropdowns();
            });
        }
        bindCustomTokenInputListeners();
        bootstrapRedirectRulesFromPost();
    });
})();
