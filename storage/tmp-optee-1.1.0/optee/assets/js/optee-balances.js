/**
 * Optee Balances Page
 */
(function($) {
    'use strict';

    var $container  = $('#optee-balance-container');
    var $loading    = $('#optee-balance-loading');
    var $empty      = $('#optee-balance-empty');
    var $tableWrap  = $('#optee-balance-table-wrap');
    var $table      = $('#optee-balance-table');
    var $daysSelect = $('#optee-balance-days');
    var $reloadBtn  = $('#optee-balance-reload');

    // Current table data (set after each render).
    var currentData = null;

    function getDays() {
        return parseInt($daysSelect.val(), 10) || 30;
    }

    function formatMoney(val) {
        if (val === undefined || val === null) return '<span class="optee-cell-empty">&mdash;</span>';
        var num = parseFloat(val);
        var cls = num > 0 ? 'optee-bal-positive' : (num < 0 ? 'optee-bal-negative' : '');
        return '<span class="' + cls + '">$' + num.toFixed(2) + '</span>';
    }

    function formatDate(dateStr) {
        return dateStr; // YYYY-MM-DD as-is
    }

    function renderTable(data) {
        currentData = data;
        var columns = data.columns || [];
        var rows    = data.rows || [];

        $loading.addClass('hidden');

        if (!columns.length || !rows.length) {
            $empty.removeClass('hidden');
            $tableWrap.addClass('hidden');
            return;
        }

        $empty.addClass('hidden');
        $tableWrap.removeClass('hidden');

        // Build header: Date + Total (sticky left), then integrations (scrollable).
        var thead = '<tr><th class="optee-bal-date-col">Date</th>';
        thead += '<th class="optee-bal-total-col">Total</th>';
        for (var i = 0; i < columns.length; i++) {
            var lastUp = columns[i].last_updated || 'Never';
            thead += '<th>' + escHtml(columns[i].name) +
                ' <span class="optee-info-icon" title="Last updated: ' + escAttr(lastUp) + '"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg></span></th>';
        }
        thead += '</tr>';
        $table.find('thead').html(thead);

        // Build body. No footer sum row: balances are point-in-time snapshots, so summing a
        // column down across dates is meaningless. The per-date Total column stays (it sums
        // across networks on that date, which is correct).
        var tbody = '';

        for (var r = 0; r < rows.length; r++) {
            var row = rows[r];
            tbody += '<tr><td class="optee-bal-date-col">' + formatDate(row.date) + '</td>';
            tbody += '<td class="optee-bal-total-col">' + formatMoney(row.total) + '</td>';
            for (var c = 0; c < columns.length; c++) {
                var col = columns[c];
                var colKey = col.type + '__' + col.slug;
                var cellVal = row[colKey];

                if (col.manual) {
                    // Editable cell for manual modules.
                    var display = (cellVal !== undefined && cellVal !== null)
                        ? formatMoney(cellVal)
                        : '<span class="optee-cell-empty">---</span>';
                    tbody += '<td class="optee-bal-editable" data-slug="' + escAttr(col.slug) + '" data-type="' + escAttr(col.type) + '" data-date="' + escAttr(row.date) + '" data-value="' + (cellVal !== undefined && cellVal !== null ? cellVal : '') + '">' + display + '</td>';
                } else {
                    tbody += '<td>' + formatMoney(cellVal) + '</td>';
                }
            }
            tbody += '</tr>';
        }

        $table.find('tbody').html(tbody);

        // Compute sticky left offset for the Total column (after Date).
        setTimeout(function() {
            var dateTh = $table.find('thead th.optee-bal-date-col')[0];
            if (!dateTh) return;
            var dateWidth = dateTh.offsetWidth;
            $table.find('.optee-bal-total-col').each(function() {
                this.style.left = dateWidth + 'px';
            });
        }, 0);
    }

    function loadBalances() {
        $loading.removeClass('hidden');
        $empty.addClass('hidden');
        $tableWrap.addClass('hidden');

        Optee.Ajax.post('optee_get_balances', { days: getDays() })
            .done(function(res) {
                if (res.success) {
                    renderTable(res.data);
                } else {
                    $loading.addClass('hidden');
                    Optee.Toast.show(res.data.message || 'Failed to load balances.', 'error');
                }
            })
            .fail(function() {
                $loading.addClass('hidden');
                Optee.Toast.show('Network error loading balances.', 'error');
            });
    }

    var $fetchBanner = $('<div class="optee-fetch-banner" style="display:none;padding:10px 16px;margin-bottom:12px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:13px;display:none;align-items:center;gap:8px;"><span class="optee-spinner" style="width:16px;height:16px;border-width:2px;"></span> Fetching latest data from all modules...</div>');
    $('#optee-balance-container').before($fetchBanner);

    function reloadBalances() {
        $reloadBtn.prop('disabled', true).html(
            '<span class="optee-spinner" style="width:16px;height:16px;border-width:2px;vertical-align:middle;"></span> Fetching...'
        );
        $fetchBanner.css('display', 'flex');

        Optee.Ajax.post('optee_fetch_balances_now', { days: getDays() })
            .done(function(res) {
                if (res.success) {
                    renderTable(res.data);
                    var count = (res.data.fetched || []).length;
                    Optee.Toast.show('Fetched balance from ' + count + ' module' + (count !== 1 ? 's' : '') + '.', 'success');
                } else {
                    Optee.Toast.show(res.data.message || 'Failed to fetch balances.', 'error');
                }
            })
            .fail(function() {
                Optee.Toast.show('Network error fetching balances.', 'error');
            })
            .always(function() {
                $fetchBanner.hide();
                $reloadBtn.prop('disabled', false).html(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg> Reload'
                );
            });
    }

    // ──────────────────────────────────────────
    // Inline Cell Editing
    // ──────────────────────────────────────────

    $(document).on('click', '.optee-bal-editable', function() {
        var $td = $(this);

        // Don't re-activate if already editing.
        if ($td.find('.optee-bal-edit-input').length) return;

        var currentVal = $td.attr('data-value') || '';
        var $input = $('<input type="number" step="0.01" class="optee-bal-edit-input">')
            .val(currentVal)
            .attr('placeholder', '0.00');

        $td.html('').append($input);
        $input.focus().select();

        function saveEdit() {
            var newVal = $input.val().trim();
            if (newVal === '' || isNaN(parseFloat(newVal))) {
                // Restore original display.
                cancelEdit();
                return;
            }
            var numVal = parseFloat(newVal);
            $td.attr('data-value', numVal);
            $td.html(formatMoney(numVal));

            Optee.Ajax.post('optee_save_manual_balance', {
                module_type:  $td.attr('data-type'),
                module_slug:  $td.attr('data-slug'),
                balance_date: $td.attr('data-date'),
                balance:      numVal
            }).done(function(res) {
                if (!res.success) {
                    Optee.Toast.show(res.data.message || 'Failed to save balance.', 'error');
                }
            }).fail(function() {
                Optee.Toast.show('Network error saving balance.', 'error');
            });
        }

        function cancelEdit() {
            var val = $td.attr('data-value');
            if (val !== '' && val !== undefined) {
                $td.html(formatMoney(parseFloat(val)));
            } else {
                $td.html('<span class="optee-cell-empty">---</span>');
            }
        }

        $input.on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveEdit();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                cancelEdit();
            }
        });

        $input.on('blur', function() {
            // Small delay so keydown handlers fire first.
            setTimeout(function() {
                if ($td.find('.optee-bal-edit-input').length) {
                    saveEdit();
                }
            }, 50);
        });
    });

    // ──────────────────────────────────────────
    // Manual Balance Modal
    // ──────────────────────────────────────────

    var $modal     = $('#optee-balance-modal');
    var $modList   = $('#optee-balance-module-list');
    var modalData  = { available: [], enabled: [] };
    var customItems = [];

    function openModal() {
        $modal.removeClass('hidden');
        customItems = [];

        Optee.Ajax.post('optee_get_manual_balance_modules', {})
            .done(function(res) {
                if (res.success) {
                    modalData = res.data;
                    renderModuleList();
                } else {
                    $modList.html('<p class="text-sm text-red-700">Failed to load modules.</p>');
                }
            })
            .fail(function() {
                $modList.html('<p class="text-sm text-red-700">Network error.</p>');
            });
    }

    function closeModal() {
        $modal.addClass('hidden');
    }

    function renderModuleList() {
        var available = modalData.available || [];
        var enabled   = modalData.enabled || [];

        // Build a set of enabled slugs for quick lookup.
        var enabledSlugs = {};
        for (var i = 0; i < enabled.length; i++) {
            enabledSlugs[enabled[i].type + '__' + enabled[i].slug] = true;
        }

        var html = '';

        if (available.length === 0 && enabled.length === 0) {
            html = '<p class="text-sm text-gray-500 py-2">No modules without auto-balance found. Use "Add Custom Platform" below.</p>';
        } else {
            // Show registered modules.
            for (var j = 0; j < available.length; j++) {
                var m = available[j];
                var key = m.type + '__' + m.slug;
                var checked = enabledSlugs[key] ? ' checked' : '';
                html += '<label class="flex items-center gap-2 py-1.5 cursor-pointer text-sm text-gray-700">';
                html += '<input type="checkbox" class="optee-bal-mod-cb" data-slug="' + escAttr(m.slug) + '" data-name="' + escAttr(m.name) + '" data-type="' + escAttr(m.type) + '"' + checked + '>';
                html += escHtml(m.name) + ' <span class="text-xs text-gray-400">(' + formatType(m.type) + ')</span>';
                html += '</label>';
            }

            // Show custom items from enabled list that aren't in available.
            var availSlugs = {};
            for (var k = 0; k < available.length; k++) {
                availSlugs[available[k].type + '__' + available[k].slug] = true;
            }
            for (var l = 0; l < enabled.length; l++) {
                var em = enabled[l];
                var ek = em.type + '__' + em.slug;
                if (!availSlugs[ek]) {
                    html += '<label class="flex items-center gap-2 py-1.5 cursor-pointer text-sm text-gray-700">';
                    html += '<input type="checkbox" class="optee-bal-mod-cb" data-slug="' + escAttr(em.slug) + '" data-name="' + escAttr(em.name) + '" data-type="' + escAttr(em.type) + '" checked>';
                    html += escHtml(em.name) + ' <span class="text-xs text-gray-400">(' + formatType(em.type) + ') &middot; custom</span>';
                    html += '</label>';
                }
            }
        }

        $modList.html(html);
    }

    function formatType(type) {
        var map = {
            'traffic_source': 'Traffic Source',
            'affiliate_network': 'Affiliate Network',
            'monetization_platform': 'Monetization',
            'antifraud_tool': 'Antifraud'
        };
        return map[type] || type;
    }

    // Add custom platform.
    $(document).on('click', '#optee-balance-custom-add', function() {
        var $name = $('#optee-balance-custom-name');
        var $type = $('#optee-balance-custom-type');
        var name = $name.val().trim();
        if (!name) return;

        var slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        var type = $type.val();

        // Check for duplicate.
        var exists = false;
        $modList.find('.optee-bal-mod-cb').each(function() {
            if ($(this).attr('data-slug') === slug && $(this).attr('data-type') === type) {
                exists = true;
            }
        });
        if (exists) {
            Optee.Toast.show('Platform "' + name + '" already exists.', 'error');
            return;
        }

        // Append a checked checkbox.
        var html = '<label class="flex items-center gap-2 py-1.5 cursor-pointer text-sm text-gray-700">';
        html += '<input type="checkbox" class="optee-bal-mod-cb" data-slug="' + escAttr(slug) + '" data-name="' + escAttr(name) + '" data-type="' + escAttr(type) + '" checked>';
        html += escHtml(name) + ' <span class="text-xs text-gray-400">(' + formatType(type) + ') &middot; custom</span>';
        html += '</label>';
        $modList.append(html);

        $name.val('');
    });

    // Save modules.
    $(document).on('click', '#optee-balance-save-modules', function() {
        var modules = [];
        $modList.find('.optee-bal-mod-cb:checked').each(function() {
            modules.push({
                slug: $(this).attr('data-slug'),
                name: $(this).attr('data-name'),
                type: $(this).attr('data-type')
            });
        });

        var $btn = $(this);
        $btn.prop('disabled', true).text('Saving...');

        Optee.Ajax.post('optee_save_manual_balance_modules', {
            modules: JSON.stringify(modules)
        }).done(function(res) {
            if (res.success) {
                Optee.Toast.show('Manual balance modules saved.', 'success');
                closeModal();
                loadBalances();
            } else {
                Optee.Toast.show(res.data.message || 'Failed to save.', 'error');
            }
        }).fail(function() {
            Optee.Toast.show('Network error.', 'error');
        }).always(function() {
            $btn.prop('disabled', false).text('Save');
        });
    });

    // Open/close modal.
    $(document).on('click', '#optee-balance-configure', function(e) {
        e.preventDefault();
        openModal();
    });
    $(document).on('click', '.optee-balance-modal-close', closeModal);
    $(document).on('click', '.optee-modal-overlay', function(e) {
        if ($(e.target).hasClass('optee-modal-overlay')) {
            closeModal();
        }
    });

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Events.
    $reloadBtn.on('click', reloadBalances);
    $daysSelect.on('change', loadBalances);

    // Init.
    loadBalances();

    // Auto-fetch if ?fetch=1 in URL (from command palette).
    if (new URLSearchParams(window.location.search).get('fetch') === '1') {
        setTimeout(reloadBalances, 500);
    }

})(jQuery);
