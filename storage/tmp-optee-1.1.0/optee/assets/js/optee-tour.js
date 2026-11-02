/**
 * Optee Product Tour — powered by Driver.js
 *
 * Guide state stored in sessionStorage('optee_tour_guide'):
 *   1/activate/configure  — traffic source install flow
 *   suggest_tracker        — on TS list, point to Trackers
 *   suggest_antifraud      — on Trackers list, point to Antifraud
 *   suggest_monetization   — on Antifraud list, point to Monetization
 *   suggest_vt             — on Monetization list, point to VirusTotal
 *   suggest_logs           — on VT page, show "Something not working?"
 *   suggest_final          — final tips (report bug, updates)
 */
(function ($) {
    'use strict';

    var driverJs = window.driver && window.driver.js;
    if (!driverJs) return;

    var params  = new URLSearchParams(window.location.search);
    var module  = opteeData.currentModule || '';
    var action  = params.get('action') || '';

    // ── Helpers ──

    function guideGet()    { try { return sessionStorage.getItem('optee_tour_guide'); } catch(e) { return null; } }
    function guideSet(v)   { try { sessionStorage.setItem('optee_tour_guide', v); } catch(e) {} }
    function guideClear()  { try { sessionStorage.removeItem('optee_tour_guide'); } catch(e) {} }

    function markTourSeen() {
        Optee.Ajax.post('optee_tour_seen');
    }

    /** Generic highlight popup. Dismissing (X) ends the entire guide. */
    function guideHighlight(element, title, description, side, opts) {
        opts = opts || {};
        var driverObj = driverJs.driver({ allowClose: true });
        driverObj.highlight({
            element: element,
            popover: {
                title: title,
                description: description,
                side: side || 'top',
                align: opts.align || 'center',
                showButtons: opts.showButtons || ['close'],
                onCloseClick: function () {
                    guideClear();
                    driverObj.destroy();
                    if (opts.onClose) opts.onClose();
                },
            }
        });
        return driverObj;
    }

    /** Highlight a sidebar nav item and navigate on button click. */
    function guideSuggestNav(navLabel, title, description, nextState) {
        var navEl = document.querySelector('.optee-nav-item[data-tooltip="' + navLabel + '"]');
        if (!navEl) return;
        var driverObj = driverJs.driver({ allowClose: true });
        driverObj.highlight({
            element: navEl,
            disableActiveInteraction: true,
            popover: {
                title: title,
                description: description,
                side: 'right',
                align: 'start',
                showButtons: ['next', 'close'],
                nextBtnText: "Let's go!",
                onNextClick: function () {
                    guideSet(nextState);
                    driverObj.destroy();
                    window.location.href = navEl.href;
                },
                onCloseClick: function () {
                    guideClear();
                    driverObj.destroy();
                },
            }
        });
    }

    // ── Main tour (dashboard, steps 1-11) ──

    var STEPS = [
        {
            element: '#optee-sidebar',
            popover: {
                title: 'Navigation Sidebar',
                description: 'Browse all sections of Optee from here. Your campaigns, integrations, automation rules, and settings are one click away.',
                side: 'right', align: 'center'
            }
        },
        {
            element: '.optee-nav-item[data-tooltip="Dashboard"]',
            popover: {
                title: 'Dashboard',
                description: 'Your home base. See an overview of all campaigns with key metrics like visits, cost, revenue, and ROI at a glance.',
                side: 'right', align: 'start'
            }
        },
        {
            element: '.optee-nav-item[data-tooltip="Campaigns"]',
            popover: {
                title: 'Campaigns',
                description: 'Create and manage your campaigns. Each campaign links a traffic source, tracker, and optionally an affiliate network and antifraud tool.',
                side: 'right', align: 'start'
            }
        },
        {
            element: '.optee-nav-item[data-tooltip="Rules"]',
            popover: {
                title: 'Automation Rules',
                description: 'Set up rules that automatically pause campaigns, block zones, or adjust bids based on performance thresholds. Rules run on every cron cycle.',
                side: 'right', align: 'start'
            }
        },
        {
            element: '.optee-nav-item[data-tooltip="Traffic Sources"]',
            popover: {
                title: 'Integrations',
                description: 'Connect your traffic sources, trackers, affiliate networks, antifraud tools, and monetization platforms. Each integration has its own setup card.',
                side: 'right', align: 'start'
            }
        },
        {
            element: '.optee-cmd-trigger',
            popover: {
                title: 'Quick Search',
                description: 'Press Ctrl+K anytime to quickly jump to any page, campaign, or knowledgebase article.',
                side: 'bottom', align: 'center'
            }
        },
        {
            element: '#optee-automation-status',
            popover: {
                title: 'Automation Status',
                description: 'Shows when the cron automation last ran. Green means healthy. Yellow or red means something needs attention.',
                side: 'bottom', align: 'center'
            }
        },
        {
            element: '.optee-new-campaign-link',
            popover: {
                title: 'New Campaign',
                description: 'Quickly create a new campaign from anywhere in the dashboard.',
                side: 'bottom', align: 'center'
            }
        },
        {
            element: '#optee-notifications-btn',
            popover: {
                title: 'Notifications',
                description: 'System alerts, rule execution results, and support ticket updates appear here.',
                side: 'bottom', align: 'center'
            }
        },
        {
            element: '#optee-user-dropdown-btn',
            popover: {
                title: 'Your Account',
                description: 'Access your profile settings, run automation manually, open the knowledgebase, or log out.',
                side: 'left', align: 'end'
            }
        },
        {
            element: '.optee-nav-item[data-tooltip="Traffic Sources"]',
            disableActiveInteraction: true,
            popover: {
                title: "Let's Get Started!",
                description: "Let's head over to <b>Traffic Sources</b> to install your first integration and connect a traffic source.",
                side: 'right', align: 'start',
                showButtons: ['previous', 'next'],
                nextBtnText: "Let's go!"
            }
        },
    ];

    function startTour() {
        if (window.innerWidth < 768) return;
        var driverObj = driverJs.driver({
            showProgress: true,
            showButtons: ['next', 'previous', 'close'],
            steps: STEPS,
            nextBtnText: 'Next',
            prevBtnText: 'Back',
            doneBtnText: 'Done',
            progressText: '{{current}} of {{total}}',
            onNextClick: function () {
                if (driverObj.isLastStep()) {
                    guideSet('1');
                    markTourSeen();
                    driverObj.destroy();
                    var tsLink = document.querySelector('.optee-nav-item[data-tooltip="Traffic Sources"]');
                    if (tsLink) window.location.href = tsLink.href;
                    return;
                }
                driverObj.moveNext();
            },
            onDestroyStarted: function () {
                markTourSeen();
                driverObj.destroy();
            }
        });
        driverObj.drive();
    }

    // ── Traffic Sources list page ──

    function showTrafficSourcesGuide() {
        var state = guideGet();

        // Returning after configuring a TS — suggest tracker next.
        if (state === 'suggest_tracker') {
            guideSuggestNav('Trackers',
                'Next: Set Up a Tracker',
                'Now let\'s connect a <b>tracker</b> like BeMob so Optee can pull campaign statistics and zone-level data.',
                'wait_tracker'
            );
            return;
        }

        // First-time flow: install / activate / configure.
        var $activate  = $('.optee-activate-plugin').first();
        var $configure = $('.optee-btn-primary:contains("Configure")').first();

        if ($activate.length) {
            var card = $activate.closest('.optee-ts-card')[0] || $activate[0];
            guideHighlight(card,
                'Activate Your Integration',
                'Click the <b>Activate</b> button to enable this traffic source. Once activated, you can configure your API credentials.',
                'top'
            );
            guideSet('configure');
        } else if ($configure.length) {
            var card = $configure.closest('.optee-ts-card')[0] || $configure[0];
            guideHighlight(card,
                'Configure Your Integration',
                'Click <b>Configure</b> to enter your API credentials and test the connection.',
                'top'
            );
            guideSet('configure');
        } else {
            guideHighlight('#optee-content',
                'Install Your First Integration',
                '<b>1.</b> Click <b>Install</b> on any traffic source card to download it.<br>' +
                '<b>2.</b> Once installed, click <b>Activate</b> to enable the integration.<br>' +
                '<b>3.</b> Then click <b>Configure</b> to enter your API credentials and test the connection.',
                'top'
            );
            $(document).on('click', '.optee-install-from-api', function () {
                guideSet('activate');
            });
        }
    }

    // ── Configure page (any module type) ──

    function showConfigureGuide(nextState) {
        var $form = $('.optee-credentials-form').first();
        if (!$form.length) return;

        // Set next state and clear current so it doesn't re-show on Save reload.
        guideSet(nextState);

        guideHighlight($form[0],
            'Enter Your API Credentials',
            'Fill in the required fields for this integration.<br><br>' +
            '<b>1.</b> Enter your API key (and any other required credentials).<br>' +
            '<b>2.</b> Click <b>Test Connection</b> to verify everything works.<br>' +
            '<b>3.</b> If the test passes, click <b>Save</b>.',
            'right'
        );
    }

    // ── Trackers list page ──

    function showTrackersGuide() {
        var state = guideGet();
        if (state === 'suggest_antifraud') {
            guideSuggestNav('Antifraud Tools',
                'Next: Set Up Antifraud',
                'Connect an <b>antifraud tool</b> like Adscore to detect bot traffic and protect your campaigns.',
                'wait_antifraud'
            );
        }
    }

    // ── Antifraud list page ──

    function showAntifraudGuide() {
        var state = guideGet();
        if (state === 'suggest_monetization') {
            guideSuggestNav('Monetization',
                'Next: Set Up Monetization',
                'Connect a <b>monetization platform</b> like Monetizer to track your earnings and calculate ROI automatically.',
                'wait_monetization'
            );
        }
    }

    // ── Monetization list page ──

    function showMonetizationGuide() {
        var state = guideGet();
        if (state === 'suggest_vt') {
            guideSuggestNav('VirusTotal',
                'Next: Set Up VirusTotal',
                'Monitor your landing pages and domains for malware and phishing detections with <b>VirusTotal</b> integration.',
                'wait_vt'
            );
        }
    }

    // ── VirusTotal page ──

    function showVtGuide() {
        guideSet('suggest_logs');
        guideHighlight('#optee-content',
            'VirusTotal',
            'Add your URLs here and Optee will automatically scan them on a schedule. You\'ll get notifications when a vendor flags your domain.<br><br>' +
            'Click the <b>X</b> to continue the tour.',
            'top',
            {
                onClose: function () {
                    guideSet('suggest_logs');
                    showLogsGuide();
                }
            }
        );
    }

    // ── Logs / troubleshooting ──

    function showLogsGuide() {
        guideSuggestNav('Logs',
            'Something Not Working?',
            'Check the <b>Logs</b> page first — it records every API call, cron run, and error. Most issues can be diagnosed here.',
            'suggest_final'
        );
    }

    // ── Final tips ──

    function showFinalTips() {
        guideClear();
        var $footer = $('#optee-footer');
        var target = $footer.length ? $footer[0] : '#optee-content';

        guideHighlight(target,
            "You're All Set!",
            '<b>A few last tips:</b><br><br>' +
            '&#8226; Found a bug? Use the <b>Report a Bug</b> link in the footer below.<br>' +
            '&#8226; Want a new feature? Use <b>Suggest a Feature</b> right next to it.<br>' +
            '&#8226; Press <b>Ctrl+K</b> and type <b>"check updates"</b> to manually check for plugin updates.<br><br>' +
            'Happy optimizing!',
            'top'
        );
    }

    // ── Expose & init ──

    window.opteeStartTour = startTour;

    $(function () {
        var state = guideGet();

        // Auto-start main tour on first dashboard visit.
        if (!opteeData.tourSeen && (!module || module === 'dashboard')) {
            setTimeout(startTour, 1000);
        }

        // ── Traffic Sources ──
        if (module === 'traffic-sources') {
            if (action === 'configure' && state && state !== 'suggest_tracker') {
                setTimeout(function () { showConfigureGuide('suggest_tracker'); }, 500);
            } else if (state) {
                setTimeout(showTrafficSourcesGuide, 500);
            }
        }

        // ── Trackers ──
        if (module === 'trackers') {
            if (action === 'configure' && state === 'wait_tracker') {
                setTimeout(function () { showConfigureGuide('suggest_antifraud'); }, 500);
            } else if (state === 'suggest_antifraud') {
                setTimeout(showTrackersGuide, 500);
            }
        }

        // ── Antifraud Tools ──
        if (module === 'antifraud-tools') {
            if (action === 'configure' && state === 'wait_antifraud') {
                setTimeout(function () { showConfigureGuide('suggest_monetization'); }, 500);
            } else if (state === 'suggest_monetization') {
                setTimeout(showAntifraudGuide, 500);
            }
        }

        // ── Monetization Platforms ──
        if (module === 'monetization-platforms') {
            if (action === 'configure' && state === 'wait_monetization') {
                setTimeout(function () { showConfigureGuide('suggest_vt'); }, 500);
            } else if (state === 'suggest_vt') {
                setTimeout(showMonetizationGuide, 500);
            }
        }

        // ── VirusTotal ──
        if (module === 'virustotal' && state === 'wait_vt') {
            setTimeout(showVtGuide, 500);
        }

        // ── Logs ──
        if (module === 'logs' && state === 'suggest_final') {
            setTimeout(showFinalTips, 500);
        }

        // Manual trigger from user dropdown.
        $(document).on('click', '#optee-start-tour', function (e) {
            e.preventDefault();
            $('#optee-user-dropdown').addClass('hidden');
            setTimeout(startTour, 200);
        });
    });

})(jQuery);
