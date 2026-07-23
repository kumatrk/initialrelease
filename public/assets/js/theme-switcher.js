(function () {
    'use strict';

    var STORAGE_KEY = 'kuma_theme';
    var VALID_THEMES = ['light', 'dark'];

    function getTheme() {
        var theme = document.documentElement.getAttribute('data-theme') || 'light';
        return VALID_THEMES.indexOf(theme) !== -1 ? theme : 'light';
    }

    function logoForTheme(theme) {
        var img = document.getElementById('sidebar-logo-img');
        if (!img) {
            return;
        }
        var light = img.getAttribute('data-logo-light');
        var dark = img.getAttribute('data-logo-dark');
        img.src = theme === 'dark' && dark ? dark : light;
    }

    function applyTheme(theme, persistLocal) {
        if (VALID_THEMES.indexOf(theme) === -1) {
            theme = 'light';
        }
        document.documentElement.setAttribute('data-theme', theme);
        logoForTheme(theme);
        if (persistLocal !== false) {
            try {
                localStorage.setItem(STORAGE_KEY, theme);
            } catch (e) {
                /* ignore */
            }
        }
        window.dispatchEvent(new CustomEvent('kuma-theme-change', { detail: { theme: theme } }));
    }

    function saveThemeToServer(theme) {
        var cfg = window.KUMA_THEME_CONFIG;
        if (!cfg || !cfg.apiUrl || !cfg.csrfToken) {
            return Promise.resolve();
        }

        var body = new URLSearchParams();
        body.set('theme', theme);
        body.set('app_csrf', cfg.csrfToken);

        return fetch(cfg.apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (res) {
            if (!res.ok) {
                return res.json().catch(function () {
                    return {};
                }).then(function (data) {
                    console.warn('Failed to save theme:', data.error || res.status);
                });
            }
            return res.json();
        }).catch(function (err) {
            console.warn('Failed to save theme:', err);
        });
    }

    function init() {
        var select = document.getElementById('theme-select');
        var serverTheme = getTheme();

        if (select) {
            select.value = serverTheme;
            select.addEventListener('change', function () {
                var theme = select.value;
                applyTheme(theme, true);
                saveThemeToServer(theme);
            });
        }

        logoForTheme(serverTheme);

        var cfg = window.KUMA_THEME_CONFIG;
        if (cfg && cfg.serverTheme) {
            try {
                var stored = localStorage.getItem(STORAGE_KEY);
                if (stored !== cfg.serverTheme) {
                    localStorage.setItem(STORAGE_KEY, cfg.serverTheme);
                }
            } catch (e) {
                /* ignore */
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.KumaTheme = {
        apply: applyTheme,
        get: getTheme
    };
})();
