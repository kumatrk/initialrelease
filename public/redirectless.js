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
        
        // Collect all URL parameters
        const urlParams = new URLSearchParams(window.location.search);

        // Redirect hop already stamped this visit — do not create a second click
        const existingClickId = (urlParams.get('click_id') || '').trim();
        if (existingClickId) {
            adoptExistingClickId(existingClickId);
            return;
        }

        const params = {};
        urlParams.forEach((value, key) => {
            params[key] = value;
        });
        
        // Add campaign and landing page info
        params['c'] = campaignId;
        params['l'] = landingPageId;
        
        // Add slug if provided (for multi-slug support)
        if (slug && typeof slug === 'string' && slug.trim()) {
            params['slug'] = slug.trim();
        }
        
        // Build tracking URL
        const rootClean = root.replace(/\/$/, ''); // Remove trailing slash
        
        // Determine the correct path to track.php
        // - Bare domain (https://tr.example.com/): doc root is public folder -> track.php
        // - Root ends with /public: track.php
        // - Project root (http://localhost/simplekuma): public/track.php
        let trackingPath;
        try {
            const rootUrl = new URL(rootClean);
            const isBareDomain = !rootUrl.pathname || rootUrl.pathname === '/';
            if (rootClean.endsWith('/public') || isBareDomain) {
                trackingPath = 'track.php';
            } else {
                trackingPath = 'public/track.php';
            }
        } catch (e) {
            trackingPath = 'public/track.php'; // Fallback
        }
        
        // Add format=json to get click_id back
        const jsonParams = Object.assign({}, params);
        jsonParams['format'] = 'json';
        const trackingUrl = rootClean + '/' + trackingPath + '?' + new URLSearchParams(jsonParams).toString();
        
        log('Simple KUMA: Tracking URL:', trackingUrl);
        log('Simple KUMA: Campaign ID:', campaignId, 'LP ID:', landingPageId);
        
        // Use XHR to get click_id back from server
        const xhr = new XMLHttpRequest();
        xhr.open('GET', trackingUrl, true);
        xhr.withCredentials = true; // Include cookies
        let xhrSuccess = false;
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                log('Simple KUMA: XHR status:', xhr.status);
                log('Simple KUMA: XHR response:', xhr.responseText);
                if (xhr.status === 200) {
                    try {
                        const response = xhr.responseText;
                        if (response && response.trim().startsWith('{')) {
                            const data = JSON.parse(response);
                            log('Simple KUMA: Parsed response:', data);
                            if (data.success && data.click_id) {
                                // Store click_id for chomp.js to use
                                sessionStorage.setItem('kuma_click_id', data.click_id);
                                // Also add to URL without reload so chomp.js can find it
                                const url = new URL(window.location);
                                url.searchParams.set('click_id', data.click_id);
                                window.history.replaceState({}, '', url);
                                log('Simple KUMA: Redirectless tracking successful. Click ID:', data.click_id);
                                xhrSuccess = true;
                                // Notify chomp.js that click_id is ready (for any listeners)
                                window.dispatchEvent(new CustomEvent('kuma_click_id_ready', { detail: { click_id: data.click_id } }));
                            } else {
                                errLog('Simple KUMA: Response missing success or click_id:', data);
                            }
                        } else {
                            errLog('Simple KUMA: Response is not JSON:', response);
                        }
                    } catch (e) {
                        errLog('Simple KUMA: Failed to parse tracking response', e, 'Response:', response);
                    }
                } else {
                    errLog('Simple KUMA: Tracking request failed with status', xhr.status, 'Response:', xhr.responseText);
                }
                
                // Only send pixel as fallback if XHR failed
                if (!xhrSuccess) {
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
            }
        };
        xhr.onerror = function() {
            errLog('Simple KUMA: XHR network error');
            // Send pixel fallback on network error
            const img = new Image();
            const pixelUrl = rootClean + '/' + trackingPath + '?' + new URLSearchParams(params).toString();
            log('Simple KUMA: Pixel fallback URL (after XHR error):', pixelUrl);
            img.src = pixelUrl;
            img.style.display = 'none';
            img.width = 1;
            img.height = 1;
        };
        xhr.send();
    };
    
    log('Simple KUMA Redirectless Tracker loaded');
})();

