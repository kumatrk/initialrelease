/**
 * Optee Command Palette (Ctrl+K)
 * Spotlight-style search for pages, campaigns, modules, and knowledgebase.
 */
(function ($) {
    'use strict';

    window.Optee = window.Optee || {};

    Optee.CommandPalette = {
        isOpen: false,
        activeIndex: 0,
        filteredItems: [],
        campaignsLoaded: false,
        campaigns: [],
        $overlay: null,
        $input: null,
        $results: null,

        // "Frequently Used" — per-browser usage counts, top N shown when the search
        // box is empty. Stored locally (no server round-trip on every selection).
        FRECENCY_KEY: 'optee_cmd_frecency',
        FRECENCY_MAX: 3,

        // SVG icon templates (Lucide).
        icons: {
            page:      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/></svg>',
            campaign:  '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/></svg>',
            configure: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
            action:    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
            article:   '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/></svg>'
        },

        init: function () {
            var self = this;

            // Build DOM.
            this.buildOverlay();

            // Ctrl+K global shortcut.
            $(document).on('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    self.toggle();
                }
            });

            // Trigger button.
            $('#optee-cmd-trigger').on('click', function () {
                self.open();
            });
        },

        buildOverlay: function () {
            var self = this;

            var html = '<div class="optee-cmd-overlay" id="optee-cmd-overlay" style="display:none;">' +
                '<div class="optee-cmd-dialog">' +
                    '<div class="optee-cmd-input-row">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>' +
                        '<input type="text" class="optee-cmd-input" placeholder="Search pages, campaigns, modules..." autocomplete="off">' +
                    '</div>' +
                    '<div class="optee-cmd-results"></div>' +
                    '<div class="optee-cmd-footer">' +
                        '<span><kbd>&uarr;</kbd> <kbd>&darr;</kbd> navigate</span>' +
                        '<span><kbd>Enter</kbd> open</span>' +
                        '<span><kbd>Esc</kbd> close</span>' +
                    '</div>' +
                '</div>' +
            '</div>';

            $('body').append(html);
            this.$overlay = $('#optee-cmd-overlay');
            this.$input   = this.$overlay.find('.optee-cmd-input');
            this.$results = this.$overlay.find('.optee-cmd-results');

            // Input event.
            this.$input.on('input', function () {
                self.filter(this.value);
            });

            // Keyboard navigation.
            this.$input.on('keydown', function (e) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    self.moveSelection(1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    self.moveSelection(-1);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    self.selectCurrent();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    self.close();
                }
            });

            // Click backdrop to close.
            this.$overlay.on('click', function (e) {
                if (e.target === self.$overlay[0]) {
                    self.close();
                }
            });

            // Click item.
            this.$results.on('click', '.optee-cmd-item', function () {
                var idx = $(this).data('index');
                self.activeIndex = idx;
                self.selectCurrent();
            });

            // Hover item.
            this.$results.on('mouseenter', '.optee-cmd-item', function () {
                self.$results.find('.optee-cmd-item').removeClass('is-active');
                $(this).addClass('is-active');
                self.activeIndex = $(this).data('index');
            });
        },

        getAllItems: function () {
            var items = [];
            var data = typeof opteeData !== 'undefined' ? opteeData : {};
            var baseUrl = data.dashboardUrl || '';
            var sep = baseUrl.indexOf('?') !== -1 ? '&' : '?';

            // Quick Actions.
            items.push({
                label: 'New Campaign',
                url: baseUrl + sep + 'module=campaigns&action=create',
                category: 'Quick Actions',
                icon: 'action',
                keywords: 'new create campaign'
            });
            items.push({
                label: 'New Rule',
                url: baseUrl + sep + 'module=rules&action=create',
                category: 'Quick Actions',
                icon: 'action',
                keywords: 'new create rule automation'
            });
            if (data.cronUrl) {
                items.push({
                    label: 'Run Automation',
                    url: data.cronUrl,
                    category: 'Quick Actions',
                    icon: 'action',
                    keywords: 'run cron automation',
                    external: true
                });
            }
            items.push({
                label: 'Update Earnings',
                url: baseUrl + sep + 'module=earnings&fetch=1',
                category: 'Quick Actions',
                icon: 'action',
                keywords: 'update fetch earnings revenue refresh'
            });
            items.push({
                label: 'Update Balances',
                url: baseUrl + sep + 'module=balances&fetch=1',
                category: 'Quick Actions',
                icon: 'action',
                keywords: 'update fetch balances money refresh'
            });
            items.push({
                label: 'Regenerate Cache Buster',
                url: '#',
                category: 'Quick Actions',
                icon: 'action',
                keywords: 'regenerate cache buster reload assets css js refresh',
                handler: function () {
                    Optee.Ajax.post('optee_save_cache_buster', {}).done(function (res) {
                        if (res.success) {
                            // Reloading is the whole point of regenerating the buster, so
                            // surface it in a modal with a one-click Reload — a toast is
                            // easy to miss and can't carry the action.
                            var content =
                                '<p class="text-sm text-gray-600" style="margin:0 0 16px;line-height:1.5;">' +
                                'The cache buster was regenerated. Reload the page to load the fresh CSS and JS assets.' +
                                '</p>' +
                                '<div style="display:flex;gap:8px;">' +
                                '<button id="optee-cb-reload" class="optee-btn-primary" style="flex:1;">Reload now</button>' +
                                '<button id="optee-cb-later" class="optee-btn-secondary" style="flex:1;">Later</button>' +
                                '</div>';
                            Optee.Modal.open('Cache buster regenerated', content);
                            $('#optee-cb-reload').on('click', function () { location.reload(); });
                            $('#optee-cb-later').on('click', function () { Optee.Modal.close(); });
                        } else {
                            Optee.Toast.show((res.data && res.data.message) || 'Failed to regenerate cache buster.', 'error');
                        }
                    }).fail(function () {
                        Optee.Toast.show('Failed to regenerate cache buster.', 'error');
                    });
                }
            });

            items.push({
                label: 'Check for Updates',
                url: '#',
                category: 'Quick Actions',
                icon: 'action',
                keywords: 'check update updates plugin version upgrade',
                handler: function () {
                    Optee.Toast.show('Checking for updates...', 'info');
                    Optee.Ajax.post('optee_check_plugin_updates', {}).done(function (res) {
                        if (res.success && res.data.update_count > 0) {
                            Optee.Toast.show(res.data.update_count + ' update(s) available', 'success');
                            $('#optee-updates-badge').css('display', 'inline-flex').find('#optee-updates-count').text(res.data.update_count);
                            $('#optee-updates-label').text(res.data.update_count === 1 ? 'update available' : 'updates available');
                            $('#optee-updates-badge').trigger('click');
                        } else {
                            Optee.Toast.show('Everything is up to date.', 'success');
                        }
                    }).fail(function () {
                        Optee.Toast.show('Failed to check for updates.', 'error');
                    });
                }
            });

            items.push({
                label: 'Check Support Notifications',
                url: '#',
                category: 'Quick Actions',
                icon: 'action',
                keywords: 'check support ticket notifications updates bell',
                handler: function () {
                    Optee.Toast.show('Checking support notifications...', 'info');
                    Optee.Ajax.post('optee_get_support_updates').done(function (res) {
                        if (res.success && res.data.updates && res.data.updates.length) {
                            Optee.Toast.show(res.data.updates.length + ' support update(s)', 'success');
                        } else {
                            Optee.Toast.show('No new support updates.', 'success');
                        }
                        // Always refresh bell with live data
                        if (window.opteeRefreshSupportNotifications) {
                            window.opteeRefreshSupportNotifications();
                        }
                    }).fail(function () {
                        Optee.Toast.show('Failed to check support notifications.', 'error');
                    });
                }
            });

            if (data.supportUrls && data.supportUrls.active) {
                var supportHandler = function (redirect) {
                    return function () {
                        var $link = $('.optee-support-link[data-redirect="' + redirect + '"]');
                        if ($link.length) {
                            $link.trigger('click');
                        } else {
                            Optee.Toast.show('Support link not available.', 'warning');
                        }
                    };
                };
                items.push({
                    label: 'Report a Bug',
                    url: '#',
                    category: 'Quick Actions',
                    icon: 'action',
                    keywords: 'report bug issue problem error',
                    handler: supportHandler(data.supportUrls.bug)
                });
                items.push({
                    label: 'Suggest a Feature',
                    url: '#',
                    category: 'Quick Actions',
                    icon: 'action',
                    keywords: 'suggest feature request idea improvement',
                    handler: supportHandler(data.supportUrls.feature)
                });
            } else {
                items.push({
                    label: 'Report a Bug',
                    url: '#',
                    category: 'Quick Actions',
                    icon: 'action',
                    keywords: 'report bug issue problem error',
                    handler: function () { Optee.Toast.show('Please activate your license first', 'warning'); }
                });
                items.push({
                    label: 'Suggest a Feature',
                    url: '#',
                    category: 'Quick Actions',
                    icon: 'action',
                    keywords: 'suggest feature request idea improvement',
                    handler: function () { Optee.Toast.show('Please activate your license first', 'warning'); }
                });
            }

            // Pages.
            if (data.cmdPalettePages) {
                for (var i = 0; i < data.cmdPalettePages.length; i++) {
                    var page = data.cmdPalettePages[i];
                    items.push({
                        label: page.label,
                        url: page.url,
                        category: 'Pages',
                        icon: 'page',
                        keywords: (page.keywords || '') + ' ' + page.label.toLowerCase()
                    });
                }
            }

            // Campaigns (dynamic).
            for (var c = 0; c < this.campaigns.length; c++) {
                var camp = this.campaigns[c];
                items.push({
                    label: 'View Stats for ' + camp.title,
                    prefix: 'View Stats for',
                    url: baseUrl + sep + 'module=dashboard&action=campaign&id=' + camp.id,
                    category: 'Campaigns',
                    icon: 'campaign',
                    keywords: 'campaign stats view ' + camp.title.toLowerCase() + ' ' + (camp.status || '')
                });
                items.push({
                    label: 'Edit Campaign ' + camp.title,
                    prefix: 'Edit Campaign',
                    url: baseUrl + sep + 'module=campaigns&action=edit&id=' + camp.id,
                    category: 'Campaigns',
                    icon: 'campaign',
                    keywords: 'edit campaign ' + camp.title.toLowerCase()
                });
                items.push({
                    label: 'Check Rules for ' + camp.title,
                    prefix: 'Check Rules for',
                    url: baseUrl + sep + 'module=rules&campaign_id=' + camp.id,
                    category: 'Campaigns',
                    icon: 'campaign',
                    keywords: 'rules campaign ' + camp.title.toLowerCase()
                });
            }

            // Configure modules.
            if (data.cmdPaletteModules) {
                for (var m = 0; m < data.cmdPaletteModules.length; m++) {
                    var mod = data.cmdPaletteModules[m];
                    var suffix = mod.typeLabel ? ' (' + mod.typeLabel + ')' : '';
                    items.push({
                        label: 'Configure ' + mod.label + suffix,
                        prefix: 'Configure',
                        url: mod.url,
                        category: 'Configure',
                        icon: 'configure',
                        keywords: 'configure setup module ' + mod.label.toLowerCase() + ' ' + (mod.typeLabel || '').toLowerCase()
                    });
                }
            }

            // Knowledgebase articles.
            if (data.cmdPaletteKb) {
                for (var k = 0; k < data.cmdPaletteKb.length; k++) {
                    var kb = data.cmdPaletteKb[k];
                    items.push({
                        label: kb.label,
                        url: kb.url,
                        category: 'Knowledgebase',
                        icon: 'article',
                        keywords: 'article guide help ' + kb.label.toLowerCase() + ' ' + (kb.keywords || ''),
                        external: !!kb.external
                    });
                }
            }

            return items;
        },

        // Stable identity for a palette item — url when it navigates, else label
        // (actions use url '#'). Used to accumulate + match frequency counts.
        itemId: function (item) {
            return (item.url && item.url !== '#') ? ('u:' + item.url) : ('l:' + item.label);
        },

        getFrecency: function () {
            try {
                var obj = JSON.parse(localStorage.getItem(this.FRECENCY_KEY) || '{}');
                return (obj && typeof obj === 'object') ? obj : {};
            } catch (e) { return {}; }
        },

        // Count one selection toward its frequency (called regardless of which
        // section it was clicked in — the id ignores category).
        bumpFrecency: function (item) {
            if (!item) return;
            var id = this.itemId(item);
            if (!id) return;
            var f = this.getFrecency();
            f[id] = (f[id] || 0) + 1;
            try { localStorage.setItem(this.FRECENCY_KEY, JSON.stringify(f)); } catch (e) {}
        },

        // Top-N most-used items that still exist, cloned into the Frequently Used
        // group. Skips stored ids whose item is gone (e.g. a deleted campaign).
        getFrequentItems: function (allItems) {
            var f = this.getFrecency();
            var ids = Object.keys(f);
            if (!ids.length) return [];

            var byId = {};
            for (var i = 0; i < allItems.length; i++) {
                var id = this.itemId(allItems[i]);
                if (id && !(id in byId)) byId[id] = allItems[i];
            }
            ids.sort(function (a, b) { return f[b] - f[a]; });

            var out = [];
            for (var k = 0; k < ids.length && out.length < this.FRECENCY_MAX; k++) {
                var item = byId[ids[k]];
                if (item) {
                    out.push($.extend({}, item, { category: 'Frequently Used' }));
                }
            }
            return out;
        },

        filter: function (query) {
            var q = (query || '').toLowerCase().trim();
            var all = this.getAllItems();

            if (!q) {
                // Empty search: surface the most-used items above the full list.
                this.filteredItems = this.getFrequentItems(all).concat(all);
            } else {
                var words = q.split(/\s+/).filter(function (w) { return w.length > 0; });
                this.filteredItems = [];
                for (var i = 0; i < all.length; i++) {
                    var item = all[i];
                    var searchable = (item.label + ' ' + item.keywords).toLowerCase();
                    var match = true;
                    for (var w = 0; w < words.length; w++) {
                        if (searchable.indexOf(words[w]) === -1) {
                            match = false;
                            break;
                        }
                    }
                    if (match) {
                        this.filteredItems.push(item);
                    }
                }
            }

            this.activeIndex = 0;
            this.renderResults();
        },

        renderResults: function () {
            var items = this.filteredItems;

            if (items.length === 0) {
                this.$results.html('<div class="optee-cmd-empty">No results found.</div>');
                return;
            }

            var html = '';
            var currentCategory = '';
            var globalIndex = 0;

            for (var i = 0; i < items.length; i++) {
                var item = items[i];

                // Category group label.
                if (item.category !== currentCategory) {
                    currentCategory = item.category;
                    html += '<div class="optee-cmd-group-label">' + this.escHtml(currentCategory) + '</div>';
                }

                var activeClass = globalIndex === this.activeIndex ? ' is-active' : '';
                var labelHtml;
                if (item.prefix) {
                    var rest = item.label.substring(item.prefix.length);
                    labelHtml = '<strong>' + this.escHtml(item.prefix) + '</strong>' + this.escHtml(rest);
                } else {
                    labelHtml = this.escHtml(item.label);
                }
                html += '<div class="optee-cmd-item' + activeClass + '" data-index="' + globalIndex + '">' +
                    '<span class="optee-cmd-item-icon">' + (this.icons[item.icon] || '') + '</span>' +
                    '<span>' + labelHtml + '</span>' +
                '</div>';

                globalIndex++;
            }

            this.$results.html(html);
            this.scrollActiveIntoView();
        },

        moveSelection: function (delta) {
            var count = this.filteredItems.length;
            if (count === 0) return;

            this.activeIndex = (this.activeIndex + delta + count) % count;

            this.$results.find('.optee-cmd-item').removeClass('is-active');
            this.$results.find('.optee-cmd-item[data-index="' + this.activeIndex + '"]').addClass('is-active');
            this.scrollActiveIntoView();
        },

        scrollActiveIntoView: function () {
            var $active = this.$results.find('.optee-cmd-item.is-active');
            if ($active.length) {
                var container = this.$results[0];
                var el = $active[0];
                var elTop = el.offsetTop;
                var elBottom = elTop + el.offsetHeight;
                if (elTop < container.scrollTop) {
                    container.scrollTop = elTop;
                } else if (elBottom > container.scrollTop + container.clientHeight) {
                    container.scrollTop = elBottom - container.clientHeight;
                }
            }
        },

        selectCurrent: function () {
            var item = this.filteredItems[this.activeIndex];
            if (!item) return;

            this.bumpFrecency(item);
            this.close();

            if (typeof item.handler === 'function') {
                item.handler();
            } else if (item.external) {
                window.open(item.url, '_blank', 'noopener,noreferrer');
            } else {
                window.location.href = item.url;
            }
        },

        toggle: function () {
            if (this.isOpen) {
                this.close();
            } else {
                this.open();
            }
        },

        open: function () {
            if (this.isOpen) return;
            this.isOpen = true;

            this.$input.val('');
            this.$overlay.show();
            this.$input.focus();

            // Load campaigns on first open.
            if (!this.campaignsLoaded) {
                this.loadCampaigns();
            }

            this.filter('');
        },

        close: function () {
            if (!this.isOpen) return;
            this.isOpen = false;
            this.$overlay.hide();
        },

        loadCampaigns: function () {
            var self = this;
            this.campaignsLoaded = true;

            $.post(opteeData.ajaxUrl, {
                action: 'optee_search_campaigns',
                nonce: opteeData.nonce
            }, function (res) {
                if (res.success && res.data) {
                    self.campaigns = res.data;
                    // Re-render if palette is still open.
                    if (self.isOpen) {
                        self.filter(self.$input.val());
                    }
                }
            });
        },

        escHtml: function (str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };

    $(function () {
        Optee.CommandPalette.init();
    });

})(jQuery);
