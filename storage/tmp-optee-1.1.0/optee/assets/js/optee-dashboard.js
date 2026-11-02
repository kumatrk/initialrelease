/**
 * Optee Dashboard — Core UI interactions.
 * Sidebar toggle, dropdowns, toasts, and utility functions.
 */
(function ($) {
    'use strict';

    window.Optee = window.Optee || {};

    /**
     * Sidebar toggle (collapse/expand).
     */
    Optee.Sidebar = {
        init: function () {
            var $app = $('#optee-app');
            var $toggle = $('#optee-sidebar-toggle');

            var $sidebar = $('#optee-sidebar');

            $toggle.on('click', function () {
                // Force overflow hidden during transition to prevent scrollbars.
                $sidebar[0].style.overflow = 'hidden';

                $app.toggleClass('sidebar-collapsed');
                var collapsed = $app.hasClass('sidebar-collapsed') ? '1' : '0';

                // After transition ends, let CSS control overflow.
                setTimeout(function () {
                    $sidebar[0].style.overflow = '';
                }, 220);

                // Persist via cookie.
                document.cookie = 'optee_sidebar_collapsed=' + collapsed + ';path=/;max-age=31536000';

                // Persist via user meta (AJAX).
                $.post(opteeData.ajaxUrl, {
                    action: 'optee_save_sidebar_state',
                    nonce: opteeData.nonce,
                    collapsed: collapsed
                });
            });

            // Collapsed-mode tooltips. The collapsed nav scrolls vertically
            // (overflow-y:auto), which clips a CSS ::after tooltip — so render
            // a single fixed-position tooltip appended to <body> that escapes
            // the scroll container. Positioned to the right of the hovered item.
            var $tip = $('<div class="optee-sidebar-tip"></div>').appendTo('body');
            $sidebar
                .on('mouseenter', '.optee-nav-item[data-tooltip]', function () {
                    if (!$app.hasClass('sidebar-collapsed')) return;
                    var r = this.getBoundingClientRect();
                    $tip.text(this.getAttribute('data-tooltip'))
                        .css({ top: (r.top + r.height / 2) + 'px', left: (r.right + 8) + 'px' })
                        .addClass('is-visible');
                })
                .on('mouseleave', '.optee-nav-item[data-tooltip]', function () {
                    $tip.removeClass('is-visible');
                });
        }
    };

    /**
     * Dropdown manager.
     */
    Optee.Dropdown = {
        init: function () {
            $(document).on('click', '[data-dropdown-toggle]', function (e) {
                e.stopPropagation();
                var targetId = $(this).data('dropdown-toggle');
                var $target = $('#' + targetId);

                // Close other dropdowns.
                $('.optee-dropdown-open').not($target).removeClass('optee-dropdown-open').addClass('hidden');

                $target.toggleClass('hidden optee-dropdown-open');
            });

            // Close dropdowns on outside click.
            $(document).on('click', function () {
                $('.optee-dropdown-open').removeClass('optee-dropdown-open').addClass('hidden');
            });

            // Prevent closing when clicking inside dropdown.
            $(document).on('click', '.optee-dropdown-open', function (e) {
                e.stopPropagation();
            });
        }
    };

    /**
     * Toast notifications.
     */
    Optee.Toast = {
        show: function (message, type) {
            type = type || 'info';

            var colorMap = {
                success: 'border-green-500 bg-green-50 text-green-700',
                error: 'border-red-500 bg-red-50 text-red-700',
                warning: 'border-yellow-500 bg-yellow-50 text-yellow-700',
                info: 'border-brand-500 bg-brand-50 text-brand-700'
            };

            var iconMap = {
                success: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
                error: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
                warning: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
                info: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>'
            };

            var $toast = $('<div class="optee-toast flex items-center gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg ' + (colorMap[type] || colorMap.info) + '">' +
                '<span class="shrink-0">' + (iconMap[type] || iconMap.info) + '</span>' +
                '<span>' + Optee.Util.escapeHtml(message) + '</span>' +
                '<button class="ml-auto shrink-0 opacity-60 hover:opacity-100">&times;</button>' +
                '</div>');

            $toast.find('button').on('click', function () {
                $toast.remove();
            });

            $('#optee-toasts').append($toast);

            // Auto-remove after 5 seconds.
            setTimeout(function () {
                $toast.fadeOut(300, function () {
                    $(this).remove();
                });
            }, 5000);
        }
    };

    /**
     * AJAX helper.
     */
    Optee.Ajax = {
        post: function (action, data) {
            data = data || {};
            data.action = action;
            data.nonce = opteeData.nonce;

            return $.ajax({
                url: opteeData.ajaxUrl,
                type: 'POST',
                data: data,
                dataType: 'json'
            });
        }
    };

    /**
     * Utility functions.
     */
    Optee.Util = {
        escapeHtml: function (str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        },

        confirm: function (message) {
            return window.confirm(message);
        },

        getUrlParam: function (name) {
            var params = new URLSearchParams(window.location.search);
            return params.get(name);
        }
    };

    /**
     * Searchable select component.
     * Usage: wrap a text input + hidden input + dropdown div in .optee-ss
     */
    Optee.SearchableSelect = {
        _inst: {},

        init: function (id) {
            var $w = $('#' + id);
            if (!$w.length) return;

            var $input = $w.find('.optee-ss-input');
            var $hidden = $w.find('input[type="hidden"]');
            var $dd = $w.find('.optee-ss-dropdown');

            var inst = {
                $w: $w, $input: $input, $hidden: $hidden, $dd: $dd,
                options: [], selValue: '', selLabel: '', open: false
            };
            this._inst[id] = inst;

            var self = this;

            $input.on('click focus', function () { self._open(id); });
            $input.on('input', function () { self._filter(id, $(this).val()); });

            $dd.on('click', '.optee-ss-option', function () {
                self._select(id, $(this).data('value') + '', $(this).text());
            });

            $(document).on('mousedown', function (e) {
                if (!$w.is(e.target) && $w.has(e.target).length === 0) {
                    self._close(id);
                }
            });

            $input.on('keydown', function (e) {
                if (e.key === 'Escape') {
                    self._close(id); $input.blur();
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault(); self._move(id, 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault(); self._move(id, -1);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    var $a = $dd.find('.optee-ss-option.active');
                    if ($a.length) self._select(id, $a.data('value') + '', $a.text());
                }
            });
        },

        setOptions: function (id, options, placeholder, keepSelection) {
            var inst = this._inst[id]; if (!inst) return;
            inst.options = options;
            if (!keepSelection) {
                inst.selValue = ''; inst.selLabel = '';
                inst.$hidden.val('');
                inst.$input.val('');
            } else if (inst.selValue) {
                // Try to find the label from options for the pre-selected value.
                for (var i = 0; i < options.length; i++) {
                    if (options[i].value === inst.selValue) {
                        inst.selLabel = options[i].label;
                        inst.$input.val(options[i].label);
                        break;
                    }
                }
            }
            inst.$input.attr('placeholder', placeholder || '\u2014 Select \u2014').prop('disabled', false);
            this._render(id, options);
        },

        setLoading: function (id) {
            var inst = this._inst[id]; if (!inst) return;
            inst.options = []; inst.selValue = ''; inst.selLabel = '';
            inst.$hidden.val('');
            inst.$input.val('').attr('placeholder', 'Loading\u2026').prop('disabled', true);
            inst.$dd.addClass('hidden').html('');
        },

        getValue: function (id) {
            var inst = this._inst[id];
            if (!inst) return { value: '', label: '' };
            return { value: inst.selValue, label: inst.selLabel };
        },

        clear: function (id) {
            var inst = this._inst[id]; if (!inst) return;
            inst.selValue = ''; inst.selLabel = '';
            inst.$hidden.val(''); inst.$input.val('');
        },

        preSelect: function (id, value, label) {
            var inst = this._inst[id]; if (!inst || !value) return;
            inst.selValue = value; inst.selLabel = label || value;
            inst.$hidden.val(value); inst.$input.val(label || value);
        },

        _open: function (id) {
            var inst = this._inst[id]; if (!inst || inst.open) return;
            inst.open = true;
            if (inst.selValue) inst.$input.val('');
            this._render(id, inst.options);
            inst.$dd.removeClass('hidden');
        },

        _close: function (id) {
            var inst = this._inst[id]; if (!inst) return;
            inst.open = false;
            inst.$dd.addClass('hidden');
            inst.$input.val(inst.selValue ? inst.selLabel : '');
        },

        _select: function (id, value, label) {
            var inst = this._inst[id]; if (!inst) return;
            inst.selValue = value; inst.selLabel = label;
            inst.$hidden.val(value); inst.$input.val(label);
            this._close(id);
            inst.$hidden.trigger('change');
        },

        _filter: function (id, q) {
            var inst = this._inst[id]; if (!inst) return;
            q = q.toLowerCase();
            var filtered = inst.options.filter(function (o) {
                return o.label.toLowerCase().indexOf(q) !== -1 || o.value.toLowerCase().indexOf(q) !== -1;
            });
            this._render(id, filtered);
            if (!inst.open) { inst.open = true; inst.$dd.removeClass('hidden'); }
        },

        _render: function (id, items) {
            var inst = this._inst[id]; if (!inst) return;
            if (!items.length) {
                inst.$dd.html('<div class="optee-ss-empty">No results found</div>');
                return;
            }
            var html = '';
            for (var i = 0; i < items.length; i++) {
                var cls = items[i].value === inst.selValue ? ' active' : '';
                html += '<div class="optee-ss-option' + cls + '" data-value="' + Optee.Util.escapeHtml(items[i].value) + '">' +
                    Optee.Util.escapeHtml(items[i].label) + '</div>';
            }
            inst.$dd.html(html);
        },

        _move: function (id, dir) {
            var inst = this._inst[id]; if (!inst) return;
            var $opts = inst.$dd.find('.optee-ss-option');
            if (!$opts.length) return;
            var idx = $opts.index($opts.filter('.active'));
            $opts.removeClass('active');
            idx += dir;
            if (idx < 0) idx = $opts.length - 1;
            if (idx >= $opts.length) idx = 0;
            var $n = $opts.eq(idx).addClass('active');
            var dd = inst.$dd[0], el = $n[0];
            if (el.offsetTop < dd.scrollTop) dd.scrollTop = el.offsetTop;
            else if (el.offsetTop + el.offsetHeight > dd.scrollTop + dd.clientHeight)
                dd.scrollTop = el.offsetTop + el.offsetHeight - dd.clientHeight;
        }
    };

    /**
     * Modal helper.
     */
    Optee.Modal = {
        open: function (title, content) {
            this.close();

            var $overlay = $('<div id="optee-modal-overlay" class="fixed inset-0 z-40 bg-black/60 flex items-center justify-center p-4">' +
                '<div class="w-full max-w-lg rounded-xl border border-gray-200 bg-white shadow-2xl">' +
                '<div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">' +
                '<h3 class="text-lg font-semibold text-gray-900">' + Optee.Util.escapeHtml(title) + '</h3>' +
                '<button id="optee-modal-close" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>' +
                '</div>' +
                '<div class="px-6 py-4">' + content + '</div>' +
                '</div>' +
                '</div>');

            $('body').append($overlay);

            $overlay.on('click', '#optee-modal-close', function () {
                Optee.Modal.close();
            });

            $overlay.on('click', function (e) {
                if ($(e.target).is('#optee-modal-overlay')) {
                    Optee.Modal.close();
                }
            });
        },

        close: function () {
            $('#optee-modal-overlay').remove();
        }
    };

    /**
     * Full-screen a scrollable table wrapper.
     *
     * Pseudo-fullscreen by design: the wrapper element itself is pinned to the
     * viewport with a CSS class — nothing is reparented. Moving the node would
     * re-run sticky layout and drop in-flight renders, and the stats table's
     * sticky header offsets are hardcoded against its own scroll box.
     *
     * The native Fullscreen API is deliberately NOT used: iOS Safari only
     * supports requestFullscreen() on <video>, so the button would be dead on
     * every iPhone. A fixed overlay behaves identically everywhere and lets us
     * own the exit affordances.
     *
     * Exits: the ✕ in the bar, Esc, or the Android hardware back button (we push
     * a history entry on open, so back pops the overlay instead of the page).
     *
     *   Optee.TableFullscreen.toggle('#optee-stats-wrapper', {
     *       title: function () { return $('#optee-campaign-title').text(); },
     *       subtitle: function () { return 'Zones · Last 7 days'; },
     *       actions: [ { label: 'Reload', icon: svg, onClick: fn } ],
     *       onToggle: function (isOpen) { ... }
     *   });
     */
    Optee.TableFullscreen = {
        _$el: null,
        _opts: null,
        _pushed: false,   // we own a history entry that still needs unwinding
        _bound: false,

        isOpen: function () {
            return !!this._$el;
        },

        toggle: function (el, opts) {
            if (this.isOpen()) this.close();
            else this.open(el, opts);
        },

        open: function (el, opts) {
            if (this._$el) return;

            var $el = $(el);
            if (!$el.length) return;

            this._$el = $el;
            this._opts = opts || {};
            this._bindGlobal();

            $('body').addClass('optee-tfs-lock');
            $el.addClass('optee-tfs-active');
            $('body').append(this._buildBar());

            // Android back should leave the overlay, not the page. Guarded because
            // pushState throws on some embedded/webview contexts.
            try {
                history.pushState({ opteeTfs: true }, '');
                this._pushed = true;
            } catch (e) {
                this._pushed = false;
            }

            if (typeof this._opts.onToggle === 'function') this._opts.onToggle(true);
        },

        /** User-initiated close (✕, Esc, or a caller). */
        close: function () {
            if (!this._$el) return;

            // Unwind our own entry so ✕ and Back leave the stack in the same
            // place — but only while it is still the top of it. The page can push
            // its own entry over ours (a zone drilldown, say), and popping that
            // would silently undo the user's navigation instead of just closing.
            var oursIsCurrent = !!(history.state && history.state.opteeTfs);
            if (this._pushed && oursIsCurrent) {
                this._pushed = false;
                history.back();   // popstate does the teardown
                return;
            }

            this._pushed = false;
            this._teardown();
        },

        /** Re-read title/subtitle — call after the underlying view changes. */
        refresh: function () {
            if (!this._$el) return;
            var o = this._opts;
            $('#optee-tfs-bar .optee-tfs-title').text(this._resolve(o.title));
            $('#optee-tfs-bar .optee-tfs-sub').text(this._resolve(o.subtitle));
        },

        _resolve: function (v) {
            return (typeof v === 'function' ? v() : v) || '';
        },

        _teardown: function () {
            if (!this._$el) return;
            var opts = this._opts;

            this._$el.removeClass('optee-tfs-active');
            $('body').removeClass('optee-tfs-lock');
            $('#optee-tfs-bar').remove();
            this._$el = null;
            this._opts = null;

            if (typeof opts.onToggle === 'function') opts.onToggle(false);
        },

        _buildBar: function () {
            var self = this;
            var o = this._opts;

            var $bar = $('<div id="optee-tfs-bar" class="optee-tfs-bar"></div>');

            // .text() throughout — titles are user data (campaign names).
            $('<div class="optee-tfs-heading"></div>')
                .append($('<div class="optee-tfs-title"></div>').text(this._resolve(o.title)))
                .append($('<div class="optee-tfs-sub"></div>').text(this._resolve(o.subtitle)))
                .appendTo($bar);

            $.each(o.actions || [], function (i, a) {
                $('<button type="button" class="optee-tfs-act"></button>')
                    .attr({ title: a.label || '', 'aria-label': a.label || '' })
                    .html(a.icon || '')
                    .on('click', function (e) {
                        e.preventDefault();
                        if (typeof a.onClick === 'function') a.onClick();
                    })
                    .appendTo($bar);
            });

            $('<button type="button" class="optee-tfs-act optee-tfs-close"></button>')
                .attr({ title: 'Close (Esc)', 'aria-label': 'Exit full screen' })
                .html('<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>')
                .on('click', function () { self.close(); })
                .appendTo($bar);

            return $bar;
        },

        _bindGlobal: function () {
            if (this._bound) return;
            this._bound = true;
            var self = this;

            $(document).on('keydown.opteeTfs', function (e) {
                // Let modals opened from inside the table swallow Esc first — they
                // sit above the overlay, so closing the overlay under them would
                // strand the modal.
                if (e.key !== 'Escape' || !self.isOpen()) return;
                if ($('#optee-cc-form, #optee-cc-modal, #optee-bd-zone-modal, #optee-bd-zones-modal').length) return;
                self.close();
            });

            $(window).on('popstate.opteeTfs', function () {
                if (!self.isOpen()) return;
                self._pushed = false;  // the entry we pushed is what just popped
                self._teardown();
            });
        }
    };

    /**
     * Initialize on DOM ready.
     */
    $(function () {
        Optee.Sidebar.init();
        Optee.Dropdown.init();
    });

})(jQuery);
