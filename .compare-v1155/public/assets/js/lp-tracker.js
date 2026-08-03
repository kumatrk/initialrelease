/**
 * Simple KUMA - Landing Page Click Tracker
 * Lightweight JS snippet for tracking LP→Offer clicks
 */

(function() {
    'use strict';

    // Extract click_id from URL query string
    function getClickId() {
        const params = new URLSearchParams(window.location.search);
        return params.get('click_id');
    }

    // Get click_id from data attribute or URL
    const clickId = document.body.dataset.clickId || getClickId();

    if (!clickId) {
        console.warn('Simple KUMA: No click_id found');
        return;
    }

    // Track LP click
    function trackLPClick() {
        const endpoint = '/click.php';
        const url = endpoint + '?click_id=' + encodeURIComponent(clickId);

        // Use sendBeacon for reliability (fires even if page is closing)
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url);
        } else {
            // Fallback to image pixel
            const img = new Image();
            img.src = url;
        }
    }

    // Attach to all outbound links with data-track attribute
    function attachTracking() {
        const links = document.querySelectorAll('a[data-track="true"], a[href*="http"]');
        
        links.forEach(function(link) {
            link.addEventListener('click', function(e) {
                // Track the click
                trackLPClick();
                
                // Allow the link to navigate normally
                // (No need to prevent default)
            });
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachTracking);
    } else {
        attachTracking();
    }

    // Also expose manual tracking function
    window.skTrackLPClick = trackLPClick;

})();


