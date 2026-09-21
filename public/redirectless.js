/**
 * Simple KUMA - Redirectless Tracking
 * Captures visitor data directly on landing pages without redirects
 */

(function() {
    'use strict';
    
    const DEBUG = false; // Set to true for development debugging only
    const log = DEBUG ? console.log.bind(console) : function() {};
    const errLog = DEBUG ? console.error.bind(console) : function() {};
    
    // Get config from global variable (should be set before this script loads)
    const config = window.kumaConfig || { root: '/' };
    const root = config.root || '/';

    const WHOP_HINT_PARAMS = ['wacid', 'wasid', 'waid', 'utm_whop', 'tw_source', 'tw_adid'];
    const WHOP_WUID_WAIT_MS = 2500;
    const WHOP_WUID_POLL_MS = 100;
    
    /**
     * Adopt an existing click_id (e.g. from campaign redirect) so chomp.js works
     * without creating a second visitor row via track.php.
     */
    function adoptExistingClickId(clickId) {
        try {
            sessionStorage.setItem('kuma_click_id', clickId);
        } catch (e) {
            // sessionStorage may be unavailable (private mode / blocked)
        }
        try {
            // Same-site cookie helps chomp when URL param is stripped later
            document.cookie = 'kuma_click_id=' + encodeURIComponent(clickId) + '; path=/; max-age=3600; SameSite=Lax';
        } catch (e) {
            // ignore
        }
        window.dispatchEvent(new CustomEvent('kuma_click_id_ready', { detail: { click_id: clickId } }));
        log('Simple KUMA: Reusing redirect click_id (skipped track.php):', clickId);
    }

    function readWhopVisitorId() {
        try {
            const m = document.cookie.match(/(?:^|;\s*)_wuid=([^;]*)/);
            if (m) {
                return decodeURIComponent(m[1]);
            }
            if (window.localStorage) {
                return localStorage.getItem('_wuid') || '';
            }
        } catch (e) {
            // ignore
        }
        return '';
    }

    function urlHasWhopHints(urlParams) {
        for (let i = 0; i < WHOP_HINT_PARAMS.length; i++) {
            const v = urlParams.get(WHOP_HINT_PARAMS[i]);
            if (v !== null && String(v).trim() !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Whop Pixel sets _wuid asynchronously. If the LP URL already has Whop/Meta
     * attribution params, wait briefly so we can pass _wuid into track.php.
     */
    function withWhopVisitorId(urlParams, done) {
        const existing = readWhopVisitorId();
        if (existing) {
            done(existing);
            return;
        }
        if (!urlHasWhopHints(urlParams)) {
            done('');
            return;
        }
        const started = Date.now();
        const timer = setInterval(function() {
            const id = readWhopVisitorId();
            if (id || (Date.now() - started) >= WHOP_WUID_WAIT_MS) {
                clearInterval(timer);
                done(id || '');
            }
        }, WHOP_WUID_POLL_MS);
    }

    function buildTrackingPath(rootClean) {
        try {
            const rootUrl = new URL(rootClean);
            const isBareDomain = !rootUrl.pathname || rootUrl.pathname === '/';
            if (rootClean.endsWith('/public') || isBareDomain) {
                return 'track.php';
            }
            return 'public/track.php';
        } catch (e) {
            return 'public/track.php';
        }
    }

    function sendTrackRequest(params) {
        const rootClean = root.replace(/\/$/, '');
        const trackingPath = buildTrackingPath(rootClean);

        const jsonParams = Object.assign({}, params);
        jsonParams['format'] = 'json';
        const trackingUrl = rootClean + '/' + trackingPath + '?' + new URLSearchParams(jsonParams).toString();

        log('Simple KUMA: Tracking URL:', trackingUrl);
        log('Simple KUMA: Campaign ID:', params.c, 'LP ID:', params.l);

        const xhr = new XMLHttpRequest();
        xhr.open('GET', trackingUrl, true);
        xhr.withCredentials = true;
        let xhrSuccess = false;

        function sendPixelFallback() {
            const img = new Image();
            const pixelUrl = rootClean + '/' + trackingPath + '?' + new URLSearchParams(params).toString();
            log('Simple KUMA: Pixel fallback URL:', pixelUrl);
            img.src = pixelUrl;
            img.style.display = 'none';
            img.width = 1;
            img.height = 1;
            img.onerror = function() {
                errLog('Simple KUMA: Pixel tracking failed');
            };
            img.onload = function() {
                log('Simple KUMA: Pixel tracking sent (fallback)');
            };
        }

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) {
                return;
            }
            log('Simple KUMA: XHR status:', xhr.status);
            log('Simple KUMA: XHR response:', xhr.responseText);
            if (xhr.status === 200) {
                try {
                    const response = xhr.responseText;
                    if (response && response.trim().startsWith('{')) {
                        const data = JSON.parse(response);
                        log('Simple KUMA: Parsed response:', data);
                        if (data.success && data.click_id) {
                            try {
                                sessionStorage.setItem('kuma_click_id', data.click_id);
                            } catch (e) {
                                // ignore
                            }
                            try {
                                const url = new URL(window.location.href);
                                url.searchParams.set('click_id', data.click_id);
                                window.history.replaceState({}, '', url);
                            } catch (e) {
                                // ignore
                            }
                            log('Simple KUMA: Redirectless tracking successful. Click ID:', data.click_id);
                            xhrSuccess = true;
                            window.dispatchEvent(new CustomEvent('kuma_click_id_ready', { detail: { click_id: data.click_id } }));
                        } else {
                            errLog('Simple KUMA: Response missing success or click_id:', data);
                        }
                    } else {
                        errLog('Simple KUMA: Response is not JSON:', response);
                    }
                } catch (e) {
                    errLog('Simple KUMA: Failed to parse tracking response', e);
                }
            } else {
                errLog('Simple KUMA: Tracking request failed with status', xhr.status, 'Response:', xhr.responseText);
            }

            if (!xhrSuccess) {
                sendPixelFallback();
            }
        };
        xhr.onerror = function() {
            errLog('Simple KUMA: XHR network error');
            sendPixelFallback();
        };
        xhr.send();
    }
    
    /**
     * Track redirectless visitor
     * @param {number} campaignId - Campaign ID
     * @param {number} landingPageId - Landing Page ID (replace LP_ID in code)
     * @param {string} [slug] - Optional campaign slug for multi-slug support
     */
    window.kumaTrack = function(campaignId, landingPageId, slug) {
        if (!campaignId || !landingPageId) {
            errLog('Simple KUMA: Campaign ID and Landing Page ID are required');
            return;
        }
        
        const urlParams = new URLSearchParams(window.location.search);

        // Redirect hop already stamped this visit — do not create a second click
        const existingClickId = (urlParams.get('click_id') || '').trim();
        if (existingClickId) {
            adoptExistingClickId(existingClickId);
            return;
        }

        withWhopVisitorId(urlParams, function(wuid) {
            const params = {};
            urlParams.forEach(function(value, key) {
                params[key] = value;
            });

            // Whop Ads: first-party _wuid is on this LP domain; pass into tracker host.
            if (wuid) {
                params['_wuid'] = wuid;
            }
            if (wuid || urlHasWhopHints(urlParams)) {
                params['whop_page_url'] = window.location.href;
            }

            params['c'] = campaignId;
            params['l'] = landingPageId;

            if (slug && typeof slug === 'string' && slug.trim()) {
                params['slug'] = slug.trim();
            }

            sendTrackRequest(params);
        });
    };
    
    log('Simple KUMA Redirectless Tracker loaded');
})();
