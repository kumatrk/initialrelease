/**
 * Simple KUMA LP Click Tracker
 *
 * Auto-rotate offer:
 *   <a href="#" onclick="kTrack(); return false;">Click Here</a>
 *
 * Force offer (normal LP/Split — slot number):
 *   kTrack(2)  or  data-k-offer="2"
 *
 * Deep Funnel — use opaque keys from the campaign Link Code Helper:
 *   kTrackKey('a1b2c3d4')           → offer by link key
 *   kTrackHop('a1b2c3d4')           → hop to another LP by link key
 *   data-k-key="a1b2c3d4"           → offer
 *   data-k-hop-key="a1b2c3d4"       → hop
 */

(function() {
    'use strict';

    const DEBUG = false;
    const log = DEBUG ? console.log.bind(console) : function() {};
    const errLog = DEBUG ? console.error.bind(console) : function() {};

    function getClickId() {
        const params = new URLSearchParams(window.location.search);
        let clickId = params.get('click_id');
        if (clickId) {
            return clickId;
        }
        clickId = sessionStorage.getItem('kuma_click_id');
        if (clickId) {
            return clickId;
        }
        const cookies = document.cookie.split(';');
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'kuma_click_id' && value) {
                return value;
            }
        }
        return null;
    }

    function getBaseUrl() {
        const scripts = document.getElementsByTagName('script');
        for (let script of scripts) {
            if (script.src && script.src.includes('chomp.js')) {
                return script.src.replace('/chomp.js', '');
            }
        }
        return window.location.origin;
    }

    function normalizeKey(k) {
        if (k === null || k === undefined) {
            return null;
        }
        const s = String(k).toLowerCase().trim();
        if (/^[a-z0-9]{6,16}$/.test(s)) {
            return s;
        }
        return null;
    }

    /**
     * - kTrack() / kTrack(event)
     * - kTrack(2) / kTrack(2, event)           — normal campaigns: offer slot
     * - kTrack({ key: '…' })                   — Deep Funnel offer key
     * - kTrack({ offer: 2 }) / { offerId: 123 }
     */
    function parseTrackArgs(a0, a1) {
        let offerSlot = null;
        let offerId = null;
        let linkKey = null;
        let event = null;

        if (a0 && typeof a0 === 'object' && !a0.target && (
            a0.offer !== undefined || a0.offerId !== undefined || a0.o !== undefined ||
            a0.key !== undefined || a0.k !== undefined || a0.linkKey !== undefined
        )) {
            if (a0.key !== undefined || a0.k !== undefined || a0.linkKey !== undefined) {
                linkKey = normalizeKey(a0.key !== undefined ? a0.key : (a0.k !== undefined ? a0.k : a0.linkKey));
            } else if (a0.offerId !== undefined && a0.offerId !== null && a0.offerId !== '') {
                offerId = parseInt(a0.offerId, 10);
            } else if (a0.offer !== undefined && a0.offer !== null && a0.offer !== '') {
                offerSlot = parseInt(a0.offer, 10);
            } else if (a0.o !== undefined && a0.o !== null && a0.o !== '') {
                offerSlot = parseInt(a0.o, 10);
            }
            event = a1 && a1.preventDefault ? a1 : null;
        } else if (typeof a0 === 'string' && normalizeKey(a0)) {
            linkKey = normalizeKey(a0);
            event = a1 && a1.preventDefault ? a1 : null;
        } else if (typeof a0 === 'number' || (typeof a0 === 'string' && a0 !== '' && !isNaN(Number(a0)))) {
            offerSlot = parseInt(a0, 10);
            event = a1 && a1.preventDefault ? a1 : null;
        } else if (a0 && a0.preventDefault) {
            event = a0;
        }

        if (offerSlot !== null && (isNaN(offerSlot) || offerSlot < 1)) {
            offerSlot = null;
        }
        if (offerId !== null && (isNaN(offerId) || offerId < 1)) {
            offerId = null;
        }

        return { offerSlot: offerSlot, offerId: offerId, linkKey: linkKey, event: event };
    }

    function trackClick(a0, a1, retryCount) {
        const parsed = parseTrackArgs(a0, a1);
        const event = parsed.event;
        if (event) {
            event.preventDefault();
        }
        retryCount = retryCount || 0;
        const maxRetries = 5;
        const retryDelayMs = 200;
        const clickId = getClickId();

        if (!clickId) {
            if (retryCount < maxRetries) {
                log('Simple KUMA: click_id not ready yet, retrying…');
                setTimeout(function() { trackClick(a0, a1, retryCount + 1); }, retryDelayMs);
                return false;
            }
            errLog('Simple KUMA: No click_id found after retries.');
            alert('Error: Missing tracking parameters. Make sure your landing page includes the redirectless tracking script and calls kumaTrack() on load.');
            return false;
        }

        const baseUrl = getBaseUrl();
        let trackerUrl = baseUrl + '/lp/click.php?click_id=' + encodeURIComponent(clickId);
        if (parsed.linkKey) {
            trackerUrl += '&k=' + encodeURIComponent(parsed.linkKey);
        } else if (parsed.offerId) {
            trackerUrl += '&offer_id=' + encodeURIComponent(String(parsed.offerId));
        } else if (parsed.offerSlot) {
            trackerUrl += '&o=' + encodeURIComponent(String(parsed.offerSlot));
        }

        window.location.href = trackerUrl;
        return false;
    }

    /** Deep Funnel: offer exit by opaque link key */
    function trackKey(key, event) {
        return trackClick({ key: key }, event);
    }

    /**
     * Deep Funnel hop by opaque link key (preferred).
     * Legacy: kTrackLevel(level, slot) still accepted but hops should use keys.
     */
    function trackHop(keyOrLevel, slotOrEvent, maybeEvent, retryCount) {
        let linkKey = normalizeKey(keyOrLevel);
        let level = null;
        let slot = null;
        let event = null;

        if (linkKey) {
            event = slotOrEvent && slotOrEvent.preventDefault ? slotOrEvent : (maybeEvent && maybeEvent.preventDefault ? maybeEvent : null);
        } else if (typeof keyOrLevel === 'number' || (typeof keyOrLevel === 'string' && keyOrLevel !== '' && !isNaN(Number(keyOrLevel)))) {
            // Legacy level/slot form — Deep Funnel server rejects bare slots; kept for older snippets
            level = parseInt(keyOrLevel, 10);
            if (typeof slotOrEvent === 'number' || (typeof slotOrEvent === 'string' && slotOrEvent !== '' && !isNaN(Number(slotOrEvent)))) {
                slot = parseInt(slotOrEvent, 10);
                event = maybeEvent && maybeEvent.preventDefault ? maybeEvent : null;
            } else if (slotOrEvent && slotOrEvent.preventDefault) {
                event = slotOrEvent;
            }
        } else if (keyOrLevel && typeof keyOrLevel === 'object' && (keyOrLevel.key || keyOrLevel.k)) {
            linkKey = normalizeKey(keyOrLevel.key || keyOrLevel.k);
            event = slotOrEvent && slotOrEvent.preventDefault ? slotOrEvent : null;
        }

        if (event) {
            event.preventDefault();
        }

        if (!linkKey && (!level || level < 1 || level > 5)) {
            errLog('Simple KUMA: kTrackHop requires a link key (from campaign Link Code Helper)');
            return false;
        }

        retryCount = retryCount || 0;
        const maxRetries = 5;
        const retryDelayMs = 200;
        const clickId = getClickId();

        if (!clickId) {
            if (retryCount < maxRetries) {
                setTimeout(function() { trackHop(keyOrLevel, slotOrEvent, maybeEvent, retryCount + 1); }, retryDelayMs);
                return false;
            }
            errLog('Simple KUMA: No click_id for hop');
            alert('Error: Missing tracking parameters.');
            return false;
        }

        const baseUrl = getBaseUrl();
        let url = baseUrl + '/lp/hop.php?click_id=' + encodeURIComponent(clickId);
        if (linkKey) {
            url += '&k=' + encodeURIComponent(linkKey);
        } else {
            url += '&level=' + encodeURIComponent(String(level));
            if (slot) {
                url += '&slot=' + encodeURIComponent(String(slot));
            }
        }
        window.location.href = url;
        return false;
    }

    function trackLevel(level, slotOrEvent, maybeEvent, retryCount) {
        return trackHop(level, slotOrEvent, maybeEvent, retryCount);
    }

    function trackOffer(slotOrOpts, event) {
        return trackClick(slotOrOpts, event);
    }

    window.kTrack = trackClick;
    window.kTrackOffer = trackOffer;
    window.kTrackKey = trackKey;
    window.kTrackHop = trackHop;
    window.kTrackLevel = trackLevel;

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.k-track, [data-k-track]').forEach(function(element) {
            element.addEventListener('click', function(e) {
                const keyAttr = element.getAttribute('data-k-key');
                const offerIdAttr = element.getAttribute('data-k-offer-id');
                const offerAttr = element.getAttribute('data-k-offer') || element.getAttribute('data-k-track');
                if (keyAttr) {
                    trackClick({ key: keyAttr }, e);
                } else if (offerIdAttr) {
                    trackClick({ offerId: offerIdAttr }, e);
                } else if (offerAttr && offerAttr !== '' && !isNaN(Number(offerAttr))) {
                    trackClick(parseInt(offerAttr, 10), e);
                } else {
                    trackClick(e);
                }
            });
        });

        document.querySelectorAll('[data-k-hop-key]').forEach(function(element) {
            element.addEventListener('click', function(e) {
                trackHop(element.getAttribute('data-k-hop-key'), e);
            });
        });

        document.querySelectorAll('[data-k-level]').forEach(function(element) {
            element.addEventListener('click', function(e) {
                const hopKey = element.getAttribute('data-k-hop-key');
                if (hopKey) {
                    trackHop(hopKey, e);
                    return;
                }
                const level = element.getAttribute('data-k-level');
                const slot = element.getAttribute('data-k-slot');
                if (slot) {
                    trackLevel(level, slot, e);
                } else {
                    trackLevel(level, e);
                }
            });
        });
    });

    log('Simple KUMA LP Tracker loaded. Click ID:', getClickId() || 'Not found');
})();
