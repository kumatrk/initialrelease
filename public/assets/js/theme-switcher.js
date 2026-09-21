(function () {
    'use strict';

    var STORAGE_KEY = 'kuma_theme';

    function themeMap() {
        var cfg = window.KUMA_THEME_CONFIG;
        return (cfg && cfg.themes) ? cfg.themes : {};
    }

    function isValidTheme(theme) {
        return Object.prototype.hasOwnProperty.call(themeMap(), theme);
    }

    function defaultThemeId() {
        var map = themeMap();
        if (isValidTheme('light')) {
            return 'light';
        }
        var ids = Object.keys(map);
        return ids.length ? ids[0] : 'light';
    }

    function resolveTheme(theme) {
        return isValidTheme(theme) ? theme : defaultThemeId();
    }

    function metaFor(theme) {
        var id = resolveTheme(theme);
        var entry = themeMap()[id] || {};
        return {
            id: id,
            label: entry.label || id,
            logo: entry.logo || 'mainlogo.png',
            base: entry.base === 'dark' ? 'dark' : 'light'
        };
    }

    function getTheme() {
        var theme = document.documentElement.getAttribute('data-theme') || defaultThemeId();
        return resolveTheme(theme);
    }

    function logoUrlFor(meta) {
        var cfg = window.KUMA_THEME_CONFIG;
        var base = (cfg && cfg.assetsBaseUrl) ? cfg.assetsBaseUrl : '/assets/images/';
        return base + meta.logo;
    }

    function logoForTheme(theme) {
        var img = document.getElementById('sidebar-logo-img');
        if (!img) {
            return;
        }
        img.src = logoUrlFor(metaFor(theme));
    }

    function applyTheme(theme, persistLocal) {
        var meta = metaFor(theme);
        document.documentElement.setAttribute('data-theme', meta.id);
        document.documentElement.setAttribute('data-theme-base', meta.base);
        logoForTheme(meta.id);
        if (persistLocal !== false) {
            try {
                localStorage.setItem(STORAGE_KEY, meta.id);
            } catch (e) {
                /* ignore */
            }
        }
        window.dispatchEvent(new CustomEvent('kuma-theme-change', {
            detail: { theme: meta.id, base: meta.base, meta: meta }
        }));
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
        get: getTheme,
        meta: function () {
            return metaFor(getTheme());
        }
    };
})();
