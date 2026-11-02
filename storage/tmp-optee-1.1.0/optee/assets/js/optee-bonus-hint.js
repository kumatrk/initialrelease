/**
 * Partner bonus hints on integration cards and configure pages.
 *
 * Two behaviours:
 *  - `.optee-bonus-badge` opens a small popover with the full offer. Integration
 *    cards are too narrow for the terms, and sending someone to the Bonuses page
 *    to read them means leaving the page they were configuring.
 *  - `.optee-bonus-copy` copies a promo code. This is the ONLY handler for that
 *    selector — the Bonuses page used to carry an identical inline copy, which
 *    would have fired twice per click (and shown two toasts) once this script
 *    started loading site-wide.
 */
(function ($) {
    'use strict';

    var COPY_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
    var DONE_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function closePopover() {
        $('.optee-bonus-popover').remove();
    }

    function openPopover($badge) {
        closePopover();

        var name   = $badge.data('name')   || '';
        var code   = $badge.data('code')   || '';
        var desc   = $badge.data('desc')   || '';
        var note   = $badge.data('note')   || '';
        var signup = $badge.data('signup') || '';

        var html = '' +
            '<div class="optee-bonus-popover" style="position:absolute;z-index:60;width:280px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.12);padding:12px 14px;text-align:left;">' +
                '<div style="font-size:13px;font-weight:700;color:#111827;margin-bottom:4px;">' + esc(name) + ' bonus</div>' +
                '<p style="font-size:12px;color:#6b7280;margin:0 0 10px;line-height:1.5;">' + esc(desc) + '</p>';

        if (code) {
            html += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">' +
                        '<code style="flex:1;background:#f3f4f6;border:1px dashed #d1d5db;border-radius:6px;padding:5px 10px;font-size:13px;font-weight:600;color:#111827;">' + esc(code) + '</code>' +
                        '<button type="button" class="optee-bonus-copy" data-code="' + esc(code) + '" title="Copy promo code" style="background:none;border:1px solid #e5e7eb;border-radius:6px;padding:5px 7px;cursor:pointer;color:#6b7280;display:flex;align-items:center;">' + COPY_ICON + '</button>' +
                    '</div>';
        } else {
            html += '<div style="font-size:11px;color:#9ca3af;font-style:italic;margin-bottom:8px;">No code needed — discount via sign-up link</div>';
        }

        if (note) {
            html += '<p style="font-size:11px;color:#9ca3af;margin:0 0 10px;line-height:1.45;">' + esc(note) + '</p>';
        }
        if (signup) {
            html += '<a href="' + esc(signup) + '" target="_blank" rel="noopener" class="optee-btn-secondary optee-btn-sm" style="font-size:12px;display:inline-block;">Sign Up</a>';
        }
        html += '</div>';

        var $pop = $(html).appendTo('body');

        // Anchor under the badge, then pull back inside the viewport. Cards sit in
        // a grid that reaches the right edge, so the last column would otherwise
        // push the popover off-screen.
        var off = $badge.offset();
        var top = off.top + $badge.outerHeight() + 6;
        var left = off.left;
        var overflow = (left + $pop.outerWidth()) - ($(window).scrollLeft() + $(window).width() - 12);
        if (overflow > 0) { left -= overflow; }
        if (left < 8) { left = 8; }

        $pop.css({ top: top + 'px', left: left + 'px' });
    }

    $(document).on('click', '.optee-bonus-badge', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $b = $(this);
        // Second click on the same badge closes it.
        if ($('.optee-bonus-popover').length && $b.data('optee-open')) {
            $b.removeData('optee-open');
            closePopover();
            return;
        }
        $('.optee-bonus-badge').removeData('optee-open');
        $b.data('optee-open', true);
        openPopover($b);
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.optee-bonus-popover, .optee-bonus-badge').length) {
            $('.optee-bonus-badge').removeData('optee-open');
            closePopover();
        }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') { closePopover(); }
    });

    $(window).on('resize', closePopover);

    /* ── Campaign-form toast ────────────────────────────────────────────────
     *
     * Picking a partner on the campaign form surfaces its offer as a toast that
     * lingers ~8s and then leaves. A permanent inline block was tried first and
     * read as clutter on an already-long form; a toast says it once and gets out
     * of the way.
     *
     * Deliberately NOT built on Optee.Toast: that helper escapes its message (so
     * the "All bonuses" link is impossible), fixes the lifetime at 5s, and is
     * used by every other feature — widening it for one caller risks all of them.
     * This renders into the same #optee-toasts container so stacking and position
     * stay consistent.
     */
    var TOAST_MS = 8000;
    var GIFT_16 = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>';

    /* One toast per FIELD, not one at a time.
     *
     * Filling in a campaign means touching several dropdowns in a row, and a
     * single-slot toast meant the tracker's offer silently replaced the traffic
     * source's before it could be read. Keying by the select lets the two stack —
     * #optee-toasts is already a flex column — while re-picking within the same
     * dropdown still swaps that field's toast rather than piling up. Capped at
     * three by construction, since there are only three fields.
     */
    var toasts = {}; // select id -> { $el: jQuery, timer: number|null }

    function dismissToast(key) {
        var entry = toasts[key];
        if (!entry) { return; }
        if (entry.timer) { clearTimeout(entry.timer); }
        entry.$el.remove();
        delete toasts[key];
    }

    function fadeToast(key) {
        var entry = toasts[key];
        if (!entry) { return; }

        var $el = entry.$el;
        $el.fadeOut(300, function () {
            // Re-picking the same dropdown mid-fade puts a NEW toast in this slot.
            // Dismissing by key alone would then destroy the replacement, so only
            // clear the registry when it still holds the element that faded.
            if (toasts[key] && toasts[key].$el[0] === $el[0]) {
                dismissToast(key);
            } else {
                $el.remove();
            }
        });
    }

    function showBonusToast(key, bonus, bonusesUrl) {
        dismissToast(key);

        var $host = $('#optee-toasts');
        if (!$host.length) { return; }

        var codeHtml = bonus.code
            ? '<code style="background:#fff;border:1px dashed #d1d5db;border-radius:5px;padding:2px 8px;font-size:12.5px;font-weight:600;color:#111827;">' + esc(bonus.code) + '</code>' +
              '<button type="button" class="optee-bonus-copy" data-code="' + esc(bonus.code) + '" style="background:none;border:none;cursor:pointer;color:#b45309;font-size:12px;text-decoration:underline;padding:0;">copy</button>'
            : '<span style="font-size:11.5px;font-style:italic;color:#a16207;">No code needed — via sign-up link</span>';

        var $t = $(
            '<div class="optee-toast optee-bonus-toast rounded-lg border shadow-lg text-sm" style="border-color:#f59e0b;background:#fffbeb;color:#92400e;overflow:hidden;min-width:320px;max-width:420px;">' +
                '<div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px 10px;">' +
                    '<span style="flex-shrink:0;margin-top:1px;">' + GIFT_16 + '</span>' +
                    '<div style="flex:1;min-width:0;">' +
                        '<div style="font-weight:600;margin-bottom:3px;">' + esc(bonus.name) + ' bonus available</div>' +
                        '<div style="font-size:12px;color:#a16207;line-height:1.5;margin-bottom:8px;">' + esc(bonus.desc) + '</div>' +
                        '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">' + codeHtml +
                            '<a href="' + esc(bonusesUrl) + '" target="_blank" rel="noopener" style="margin-left:auto;font-size:12px;color:#b45309;font-weight:600;text-decoration:none;white-space:nowrap;">All bonuses &#8599;</a>' +
                        '</div>' +
                    '</div>' +
                    '<button type="button" class="optee-bonus-toast-close" style="background:none;border:none;cursor:pointer;color:#b45309;opacity:.6;font-size:16px;line-height:1;padding:0 0 0 4px;">&times;</button>' +
                '</div>' +
                '<div class="optee-bonus-toast-bar" style="height:2px;background:#f59e0b;width:100%;transition:width linear;"></div>' +
            '</div>'
        );

        $host.append($t);
        toasts[key] = { $el: $t, timer: null };

        var $bar = $t.find('.optee-bonus-toast-bar');
        window.requestAnimationFrame(function () {
            $bar.css({ transitionDuration: TOAST_MS + 'ms', width: '0%' });
        });

        toasts[key].timer = setTimeout(function () { fadeToast(key); }, TOAST_MS);

        // Freeze the countdown while the pointer is over the toast. The whole
        // point is that someone reaches for the code — having it vanish mid-reach
        // is the fastest way to make the feature annoying. Scoped per toast, so
        // hovering one does not extend the life of its neighbour.
        $t.on('mouseenter', function () {
            var entry = toasts[key];
            if (!entry) { return; }
            if (entry.timer) { clearTimeout(entry.timer); entry.timer = null; }
            $bar.css({ transitionDuration: '0ms', width: $bar[0].getBoundingClientRect().width + 'px' });
        }).on('mouseleave', function () {
            var entry = toasts[key];
            if (!entry) { return; }
            $bar.css({ transitionDuration: TOAST_MS + 'ms', width: '0%' });
            entry.timer = setTimeout(function () { fadeToast(key); }, TOAST_MS);
        });

        $t.find('.optee-bonus-toast-close').on('click', function () { dismissToast(key); });
    }

    $(function () {
        var data = window.opteeBonusData;
        if (!data || !data.campaign) { return; }

        var selects = ['#campaign-traffic-source', '#campaign-tracker', '#campaign-network'];
        $(selects.join(',')).on('change', function () {
            var key   = this.id;
            var bonus = data.campaign[$(this).val()];

            // Clearing a field (or picking a partner with no live offer) retires
            // that field's toast — leaving it up would advertise a partner the
            // form no longer refers to.
            if (!bonus) { dismissToast(key); return; }

            showBonusToast(key, bonus, data.bonusesUrl);
        });
    });

    $(document).on('click', '.optee-bonus-copy', function () {
        var $btn = $(this);
        var code = $btn.data('code');
        if (!code || !navigator.clipboard) { return; }

        // Same handler serves an icon button (cards, configure strip) and a text
        // link (campaign toast). Swapping an icon into the text one would leave a
        // stray tick where the word "copy" was, so confirm in kind.
        var isIcon = $btn.find('svg').length > 0;
        var original = isIcon ? null : $btn.text();

        navigator.clipboard.writeText(code).then(function () {
            if (window.Optee && Optee.Toast) {
                Optee.Toast.show('Promo code copied: ' + code, 'success');
            }
            if (isIcon) {
                $btn.html(DONE_ICON);
                setTimeout(function () { $btn.html(COPY_ICON); }, 2000);
            } else {
                $btn.text('copied');
                setTimeout(function () { $btn.text(original); }, 2000);
            }
        });
    });
})(jQuery);
