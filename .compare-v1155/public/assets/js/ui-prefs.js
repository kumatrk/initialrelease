(function () {
    'use strict';

    var SIDEBAR_STORAGE_KEY = 'kuma_sidebar_collapsed';
    var CHART_STORAGE_KEY = 'kuma_dashboard_charts_hidden';

    function cfg() {
        return window.KUMA_UI_PREFS_CONFIG || null;
    }

    function toBool(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function savePref(key, value) {
        var c = cfg();
        if (!c || !c.apiUrl || !c.csrfToken) {
            return Promise.resolve({ ok: false });
        }

        var body = new URLSearchParams();
        body.set(key, toBool(value) ? '1' : '0');
        body.set('app_csrf', c.csrfToken);

        return fetch(c.apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: false };
            }).then(function (data) {
                if (!res.ok || !data.ok) {
                    console.warn('Failed to save UI pref:', (data && data.error) || res.status);
                }
                return data;
            });
        }).catch(function (err) {
            console.warn('Failed to save UI pref:', err);
            return { ok: false };
        });
    }

    function applySidebarCollapsed(collapsed, persistLocal) {
        collapsed = !!collapsed;
        document.documentElement.classList.toggle('sidebar-collapsed', collapsed);

        var toggle = document.getElementById('sidebar-collapse-toggle');
        if (toggle) {
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            toggle.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        }

        if (persistLocal !== false) {
            try {
                localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed ? '1' : '0');
            } catch (e) { /* ignore */ }
        }
    }

    function initSidebarToggle() {
        var c = cfg();
        var serverCollapsed = c ? toBool(c.sidebarCollapsed) : document.documentElement.classList.contains('sidebar-collapsed');
        applySidebarCollapsed(serverCollapsed, true);

        try {
            localStorage.setItem(SIDEBAR_STORAGE_KEY, serverCollapsed ? '1' : '0');
        } catch (e) { /* ignore */ }

        document.querySelectorAll('.sidebar-nav-link').forEach(function (link) {
            if (link.getAttribute('data-label')) {
                return;
            }
            var span = link.querySelector('span');
            if (span) {
                var label = (span.textContent || '').trim();
                if (label) {
                    link.setAttribute('data-label', label);
                    link.setAttribute('title', label);
                }
            }
        });

        var toggle = document.getElementById('sidebar-collapse-toggle');
        if (!toggle) {
            return;
        }

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var next = !document.documentElement.classList.contains('sidebar-collapsed');
            applySidebarCollapsed(next, true);
            savePref('sidebar_collapsed', next);
        });
    }

    function initDashboardChartToggle() {
        var buttons = document.querySelectorAll('[data-dashboard-chart-toggle]');
        if (!buttons.length) {
            return;
        }

        var c = cfg();
        var hidden = c ? toBool(c.dashboardChartsHidden) : false;
        try {
            localStorage.setItem(CHART_STORAGE_KEY, hidden ? '1' : '0');
        } catch (e) { /* ignore */ }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var nextHidden = btn.getAttribute('data-dashboard-chart-toggle') === 'hide';
                btn.disabled = true;
                try {
                    localStorage.setItem(CHART_STORAGE_KEY, nextHidden ? '1' : '0');
                } catch (e) { /* ignore */ }

                savePref('dashboard_charts_hidden', nextHidden).then(function () {
                    window.location.reload();
                });
            });
        });
    }

    function init() {
        initSidebarToggle();
        initDashboardChartToggle();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.KumaUiPrefs = {
        save: savePref,
        applySidebarCollapsed: applySidebarCollapsed
    };
})();
