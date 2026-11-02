/**
 * Optee — Notification polling and display.
 */
(function ($) {
    'use strict';

    var Notifications = {
        pollInterval: 30000, // 30 seconds
        supportPollInterval: 300000, // 5 minutes
        timer: null,
        supportTimer: null,
        supportUpdates: [], // cached support updates

        syncChannel: null,

        init: function () {
            this.initCrossTabSync();
            this.bindEvents();
            this.poll();
            this.startPolling();
            this.pollSupport();
            this.startSupportPolling();
        },

        /**
         * Cross-tab sync: when one tab marks notifications read / dismisses, every
         * other open tab on the same origin re-polls immediately instead of waiting
         * for the 30-second poll cycle.
         *
         * Primary: BroadcastChannel API (modern browsers).
         * Fallback: localStorage `storage` event (covers older browsers and edge
         * cases where BroadcastChannel is missing). We write to localStorage in
         * both paths so either listener will fire.
         */
        initCrossTabSync: function () {
            try {
                if (typeof BroadcastChannel !== 'undefined') {
                    this.syncChannel = new BroadcastChannel('optee_notif_sync');
                    this.syncChannel.onmessage = function () { Notifications.poll(); };
                }
            } catch (e) { /* ignore */ }

            window.addEventListener('storage', function (e) {
                if (e.key === 'optee_notif_sync') Notifications.poll();
            });
        },

        /** Notify every other open Optee tab that notifications changed. */
        broadcastChange: function () {
            if (this.syncChannel) {
                try { this.syncChannel.postMessage({ ts: Date.now() }); } catch (e) { /* ignore */ }
            }
            try { localStorage.setItem('optee_notif_sync', String(Date.now())); } catch (e) { /* ignore */ }
        },

        bindEvents: function () {
            // Toggle dropdown.
            $(document).on('click', '#optee-notifications-btn', function (e) {
                e.stopPropagation();
                $('#optee-notification-dropdown').toggleClass('hidden');
            });

            // Close on outside click.
            $(document).on('click', function (e) {
                if (!$(e.target).closest('#optee-notifications-wrapper').length) {
                    $('#optee-notification-dropdown').addClass('hidden');
                }
            });

            // Dismiss single.
            $(document).on('click', '.optee-dismiss-notification', function () {
                var index = $(this).data('index');
                Notifications.dismiss(index);
            });

            // Mark all read.
            $(document).on('click', '#optee-mark-all-read', function () {
                Notifications.dismissAll();
                // Let the user see the "No notifications" state clear, then close the popup.
                setTimeout(function () {
                    $('#optee-notification-dropdown').addClass('hidden');
                }, 2000);
            });

            // Clicking a notification title link — dismiss all, then navigate.
            $(document).on('click', '#optee-notification-list a[href]', function (e) {
                var href = $(this).attr('href');
                // Support update links open in new tab — don't intercept.
                if ($(this).attr('target') === '_blank') return;
                e.preventDefault();
                Optee.Ajax.post('optee_dismiss_all_notifications').always(function () {
                    window.location.href = href;
                });
            });

            // "View all notifications" footer link — dismiss all, then navigate.
            $(document).on('click', '#optee-notification-dropdown .border-t a[href]', function (e) {
                e.preventDefault();
                var href = $(this).attr('href');
                Optee.Ajax.post('optee_dismiss_all_notifications').always(function () {
                    window.location.href = href;
                });
            });

            // Dismiss support update.
            $(document).on('click', '.optee-dismiss-support-update', function () {
                var sid = $(this).data('support-id');
                $(this).closest('.optee-support-item').fadeOut(200, function () {
                    $(this).remove();
                    // Remove from cached list
                    Notifications.supportUpdates = Notifications.supportUpdates.filter(function (u) {
                        return u.id !== sid;
                    });
                    Notifications.updateBadge();
                });
                Optee.Ajax.post('optee_mark_support_read', { update_ids: [sid] }).done(function () {
                    Notifications.broadcastChange();
                });
            });
        },

        poll: function () {
            Optee.Ajax.post('optee_get_notifications').done(function (res) {
                if (res.success) {
                    Notifications.render(res.data.notifications || []);
                }
            });
        },

        startPolling: function () {
            this.timer = setInterval(function () {
                Notifications.poll();
            }, this.pollInterval);
        },

        render: function (items) {
            var $badge = $('#optee-notification-badge');
            var $list = $('#optee-notification-list');
            var totalCount = items.length + this.supportUpdates.length;

            if (!totalCount) {
                $badge.addClass('hidden').text('');
                $list.html('<div class="px-4 py-8 text-center text-sm text-gray-500">No notifications</div>');
                return;
            }

            $badge.removeClass('hidden').text(totalCount > 99 ? '99+' : totalCount);

            // Build support updates HTML first (they go on top)
            var supportHtml = this.buildSupportHtml();

            // Build regular notifications HTML
            var html = '';
            $.each(items, function (i, item) {
                var typeColor = {
                    info: 'text-brand-600',
                    success: 'text-green-500',
                    warning: 'text-yellow-500',
                    error: 'text-red-500'
                };
                var color = typeColor[item.type] || typeColor.info;

                // If this notification is tied to a campaign, link the title to the campaign stats page.
                var notifUrl;
                if (item.campaign_id) {
                    notifUrl = opteeData.dashboardUrl + '?module=dashboard&action=campaign&id=' + encodeURIComponent(item.campaign_id);
                } else if (item.url) {
                    notifUrl = item.url.indexOf('?') === 0 ? opteeData.dashboardUrl + item.url : item.url;
                } else {
                    notifUrl = opteeData.dashboardUrl + '?module=logs&action=notifications';
                }
                // Only allow absolute http(s) or in-app (?/…) targets — never
                // javascript:/data: URLs coming from the remote notifications feed.
                if (!/^(https?:\/\/|[/?])/i.test(notifUrl)) {
                    notifUrl = opteeData.dashboardUrl + '?module=logs&action=notifications';
                }
                var titleHtml = '';
                var msgHtml = '';
                var campaignHtml = '';
                if (item.campaign_title) {
                    campaignHtml = '<p class="text-gray-500" style="font-size:11px;line-height:1.3;margin:2px 0 0;font-style:italic;">' + Optee.Util.escapeHtml(item.campaign_title) + '</p>';
                }
                if (item.title) {
                    titleHtml = '<p style="font-size:13px;line-height:1.3;margin:0;"><a href="' + Optee.Util.escapeHtml(notifUrl) + '" class="text-gray-900 font-bold hover:underline" style="text-decoration:none;">' + Optee.Util.escapeHtml(item.title) + '</a></p>';
                    msgHtml = item.message ? '<p class="text-gray-600" style="font-size:12px;line-height:1.3;margin:4px 0 0;">' + Optee.Util.escapeHtml(item.message) + '</p>' : '';
                } else {
                    msgHtml = '<p class="text-gray-800" style="font-size:13px;line-height:1.3;margin:0;">' + Optee.Util.escapeHtml(item.message) + '</p>';
                }

                html += '<div class="flex border-b border-gray-100 px-4 py-3 hover:bg-gray-50 transition-colors" style="align-items:flex-start;gap:10px;">' +
                    '<span class="shrink-0 ' + color + '" style="margin-top:4px;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="12"/></svg>' +
                    '</span>' +
                    '<div class="flex-1 min-w-0">' +
                    titleHtml +
                    campaignHtml +
                    msgHtml +
                    '<p class="text-xs text-gray-400" style="margin:4px 0 0;">' + Optee.Util.escapeHtml(item.time || '') + '</p>' +
                    '</div>' +
                    '<button class="optee-dismiss-notification shrink-0 text-gray-400 hover:text-gray-600" data-index="' + i + '" style="cursor:pointer;background:none;border:none;font-size:16px;padding:0;line-height:1;">&times;</button>' +
                    '</div>';
            });

            $list.html(supportHtml + html);
        },

        buildSupportHtml: function () {
            var html = '';
            var promoUrl = opteeData.dashboardUrl + '?module=promo-codes';
            $.each(this.supportUpdates, function (i, u) {
                var isPromo = u.update_type && u.update_type.indexOf('promo_') === 0;
                var ticketUrl = isPromo ? promoUrl : ('https://support.optee.co/tickets/' + u.ticket_id);
                var target = isPromo ? '' : ' target="_blank"';
                var title = isPromo ? 'Promo Code' : 'Support Update';
                var dotColor = isPromo
                    ? (u.update_type === 'promo_approved' ? 'text-green-500' : 'text-red-500')
                    : 'text-brand-600';
                var time = u.created_at ? u.created_at.replace('T', ' ').substring(0, 16) : '';
                html += '<div class="optee-support-item flex border-b border-gray-100 px-4 py-3 hover:bg-gray-50 transition-colors" style="align-items:flex-start;gap:10px;">' +
                    '<span class="shrink-0 ' + dotColor + '" style="margin-top:4px;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="12"/></svg>' +
                    '</span>' +
                    '<div class="flex-1 min-w-0">' +
                    '<p style="font-size:13px;line-height:1.3;margin:0;"><a href="' + ticketUrl + '"' + target + ' class="text-gray-900 font-bold hover:underline" style="text-decoration:none;">' + title + '</a></p>' +
                    '<p class="text-gray-600" style="font-size:12px;line-height:1.3;margin:4px 0 0;">' + Optee.Util.escapeHtml(u.summary) + '</p>' +
                    '<p class="text-xs text-gray-400" style="margin:4px 0 0;">' + Optee.Util.escapeHtml(time) + '</p>' +
                    '</div>' +
                    '<button class="optee-dismiss-support-update shrink-0 text-gray-400 hover:text-gray-600" data-support-id="' + u.id + '" style="cursor:pointer;background:none;border:none;font-size:16px;padding:0;line-height:1;">&times;</button>' +
                    '</div>';
            });
            return html;
        },

        updateBadge: function () {
            var $badge = $('#optee-notification-badge');
            var $list = $('#optee-notification-list');
            var regularCount = $list.find('.optee-dismiss-notification').length;
            var totalCount = regularCount + this.supportUpdates.length;
            if (totalCount > 0) {
                $badge.removeClass('hidden').text(totalCount > 99 ? '99+' : totalCount);
            } else {
                $badge.addClass('hidden').text('');
            }
        },

        dismiss: function (index) {
            Optee.Ajax.post('optee_dismiss_notification', { index: index }).done(function () {
                Notifications.poll();
                Notifications.broadcastChange();
            });
        },

        dismissAll: function () {
            Optee.Ajax.post('optee_dismiss_all_notifications').done(function () {
                Notifications.poll();
                Notifications.broadcastChange();
            });
            // Also mark all support updates as read.
            if (this.supportUpdates.length) {
                Optee.Ajax.post('optee_mark_support_read', {}).done(function () {
                    Notifications.supportUpdates = [];
                    Notifications.updateBadge();
                    Notifications.broadcastChange();
                });
            }
        },

        // ── Support ticket update polling ──

        pollSupport: function () {
            Optee.Ajax.post('optee_get_support_updates').done(function (res) {
                if (res.success && res.data.updates) {
                    Notifications.supportUpdates = res.data.updates;
                    Notifications.poll(); // re-render with support items included
                }
            });
        },

        startSupportPolling: function () {
            this.supportTimer = setInterval(function () {
                Notifications.pollSupport();
            }, this.supportPollInterval);
        }
    };

    // Expose refresh globally so other modules can trigger a poll.
    window.opteeRefreshNotifications = function () {
        Notifications.poll();
    };

    // Expose support refresh for command palette.
    window.opteeRefreshSupportNotifications = function () {
        Notifications.pollSupport();
    };

    $(function () {
        Notifications.init();
    });

})(jQuery);
