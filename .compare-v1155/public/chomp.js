/**
 * Simple KUMA LP Click Tracker
 * Lightweight script to track LP clicks and redirect to offers
 * 
 * Usage: Add to your landing page:
 * <script src="http://your-domain.com/chomp.js"></script>
 * 
 * Then use on your CTA buttons/links:
 * <a href="#" onclick="kTrack(); return false;">Click Here</a>
 * or
 * <a href="#" class="k-track">Click Here</a>
 */

(function() {
    'use strict';
    
    const DEBUG = false; // Set to true for development debugging only
    const log = DEBUG ? console.log.bind(console) : function() {};
    const errLog = DEBUG ? console.error.bind(console) : function() {};
    
    // Get click_id from URL, sessionStorage, or cookie (for redirectless tracking)
    function getClickId() {
        // First, try URL parameter
        const params = new URLSearchParams(window.location.search);
        let clickId = params.get('click_id');
        
        if (clickId) {
            return clickId;
        }
        
        // Second, try sessionStorage (set by redirectless.js)
        clickId = sessionStorage.getItem('kuma_click_id');
        if (clickId) {
            return clickId;
        }
        
        // Third, try cookie (set by track.php)
        const cookies = document.cookie.split(';');
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'kuma_click_id' && value) {
                return value;
            }
        }
        
        return null;
    }
    
    // Get base URL (auto-detect from script src)
    function getBaseUrl() {
        const scripts = document.getElementsByTagName('script');
        for (let script of scripts) {
            if (script.src && script.src.includes('chomp.js')) {
                return script.src.replace('/chomp.js', '');
            }
        }
        // Fallback: try to detect from current URL
        return window.location.origin;
    }
    
    // Track LP click and redirect
    // Retries when click_id not found (redirectless XHR may still be in flight)
    function trackClick(e, retryCount) {
        if (e) {
            e.preventDefault();
        }
        retryCount = retryCount || 0;
        const maxRetries = 5;
        const retryDelayMs = 200;

        const clickId = getClickId();
        
        if (!clickId) {
            if (retryCount < maxRetries) {
                log('Simple KUMA: click_id not ready yet, retrying in ' + retryDelayMs + 'ms (' + (retryCount + 1) + '/' + maxRetries + ')');
                setTimeout(function() { trackClick(e, retryCount + 1); }, retryDelayMs);
                return false;
            }
            errLog('Simple KUMA: No click_id found after ' + maxRetries + ' retries. Ensure redirectless.js and kumaTrack() run on page load.');
            alert('Error: Missing tracking parameters. Make sure your landing page includes the redirectless tracking script and calls kumaTrack() on load.');
            return false;
        }
        
        // Build tracker URL
        const baseUrl = getBaseUrl();
        const trackerUrl = baseUrl + '/lp/click.php?click_id=' + encodeURIComponent(clickId);
        
        // Redirect to tracker (which will then redirect to offer)
        window.location.href = trackerUrl;
        
        return false;
    }
    
    // Global function for onclick handlers
    window.kTrack = trackClick;
    
    // Auto-attach to elements with class="k-track"
    document.addEventListener('DOMContentLoaded', function() {
        const trackElements = document.querySelectorAll('.k-track, [data-k-track]');
        
        trackElements.forEach(function(element) {
            element.addEventListener('click', trackClick);
        });
    });
    
    // Log ready state
    log('Simple KUMA LP Tracker loaded. Click ID:', getClickId() || 'Not found');
})();

