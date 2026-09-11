/**
 * MageWatch order attribution — records where a visit came from into a
 * first-party cookie so the order observer can read it at checkout time.
 *
 * Why this runs in the browser instead of PHP: Magento's default Varnish VCL
 * strips utm_*, gclid, fbclid and friends from req.url before the request ever
 * reaches the backend, and landing pages are served from full page cache
 * anyway. Server-side capture would silently miss most paid traffic.
 *
 * The script stays deliberately dumb — it stores raw parameters and the
 * referrer host. Mapping those onto source/medium/campaign happens server-side
 * in TouchNormalizer, where it is unit tested and can be corrected without a
 * static content deploy.
 */
(function () {
    'use strict';

    var COOKIE_NAME = 'mw_attr';
    var COOKIE_VERSION = 1;
    var MAX_VALUE_LENGTH = 128;
    var MAX_COOKIE_LENGTH = 1500;

    var UTM_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'];

    /** Ordered by trust: the first match wins when an URL carries several. */
    var CLICK_IDS = [
        'gclid', 'gbraid', 'wbraid', 'msclkid', 'dclid', 'fbclid',
        'ttclid', 'twclid', 'yclid', 'igshid', 'epik', 'irclickid'
    ];

    function config() {
        var el = document.currentScript;
        var days = 30;
        var restrictionMode = false;
        var websiteId = '0';

        if (el) {
            days = parseInt(el.getAttribute('data-mw-days'), 10) || days;
            restrictionMode = el.getAttribute('data-mw-restrict') === '1';
            websiteId = el.getAttribute('data-mw-website') || websiteId;
        }

        return { days: days, restrictionMode: restrictionMode, websiteId: websiteId };
    }

    function readCookie(name) {
        try {
            var parts = (document.cookie || '').split(';');
            for (var i = 0; i < parts.length; i++) {
                var pair = parts[i].split('=');
                if (pair.shift().trim() === name) {
                    return decodeURIComponent(pair.join('='));
                }
            }
        } catch (e) {}

        return null;
    }

    /**
     * Magento's cookie restriction mode stores per-website consent as JSON
     * ({"1":1}); anything unparsable counts as "not granted".
     */
    function magentoCookieConsent(websiteId) {
        var raw = readCookie('user_allowed_save_cookie');
        if (!raw) {
            return false;
        }

        try {
            var parsed = JSON.parse(raw);
            return !!(parsed && parsed[websiteId]);
        } catch (e) {
            return false;
        }
    }

    function consentGiven(cfg) {
        try {
            if (navigator.globalPrivacyControl === true) {
                return false;
            }
            if (typeof window.__mwAttributionConsent === 'boolean') {
                return window.__mwAttributionConsent;
            }
            if (cfg.restrictionMode && !magentoCookieConsent(cfg.websiteId)) {
                return false;
            }
            if (window.Cookiebot && window.Cookiebot.consent) {
                return window.Cookiebot.consent.marketing === true;
            }
            if (typeof window.OnetrustActiveGroups === 'string') {
                return window.OnetrustActiveGroups.split(',').indexOf('C0004') !== -1;
            }
            if (
                document.querySelector(
                    'script[src*="cookiebot" i], script[src*="cookielaw.org" i], #onetrust-consent-sdk'
                )
            ) {
                return false;
            }
        } catch (e) {}

        return true;
    }

    function trim(value) {
        return String(value).replace(/[\u0000-\u001f\u007f]/g, '').trim().slice(0, MAX_VALUE_LENGTH);
    }

    function queryParams() {
        var out = {};
        try {
            var search = window.location.search || '';
            if (search.charAt(0) === '?') {
                search = search.slice(1);
            }
            if (!search) {
                return out;
            }
            var pairs = search.split('&');
            for (var i = 0; i < pairs.length; i++) {
                var idx = pairs[i].indexOf('=');
                if (idx <= 0) {
                    continue;
                }
                var key = decodeURIComponent(pairs[i].slice(0, idx)).toLowerCase();
                var value = decodeURIComponent(pairs[i].slice(idx + 1).replace(/\+/g, ' '));
                if (value && !Object.prototype.hasOwnProperty.call(out, key)) {
                    out[key] = value;
                }
            }
        } catch (e) {}

        return out;
    }

    function externalReferrerHost() {
        try {
            if (!document.referrer) {
                return '';
            }
            var host = new URL(document.referrer).hostname.toLowerCase();

            return host && host !== window.location.hostname.toLowerCase() ? host : '';
        } catch (e) {
            return '';
        }
    }

    /**
     * @return {?Object} touch describing this pageview, or null when the visit
     *                   carries no traffic-source signal at all (direct hit or
     *                   internal navigation).
     */
    function currentTouch() {
        var params = queryParams();
        var touch = { ts: Math.floor(Date.now() / 1000) };
        var identified = false;

        for (var i = 0; i < UTM_PARAMS.length; i++) {
            var name = UTM_PARAMS[i];
            if (params[name]) {
                touch[name.slice(4)] = trim(params[name]);
                identified = true;
            }
        }

        for (var j = 0; j < CLICK_IDS.length; j++) {
            if (params[CLICK_IDS[j]]) {
                touch.ci = CLICK_IDS[j];
                touch.cv = trim(params[CLICK_IDS[j]]);
                identified = true;
                break;
            }
        }

        var referrer = externalReferrerHost();
        if (referrer) {
            touch.r = trim(referrer);
            identified = true;
        }

        if (!identified) {
            return null;
        }

        try {
            touch.lp = trim(window.location.pathname);
        } catch (e) {}

        return touch;
    }

    function readState() {
        var raw = readCookie(COOKIE_NAME);
        if (!raw || raw.length > MAX_COOKIE_LENGTH) {
            return null;
        }

        try {
            var parsed = JSON.parse(raw);
            if (parsed && parsed.v === COOKIE_VERSION) {
                return parsed;
            }
        } catch (e) {}

        return null;
    }

    function writeState(state, days) {
        try {
            var value = encodeURIComponent(JSON.stringify(state));
            if (value.length > MAX_COOKIE_LENGTH) {
                return;
            }

            document.cookie = COOKIE_NAME + '=' + value +
                '; path=/; max-age=' + (days * 86400) + '; SameSite=Lax' +
                (window.location.protocol === 'https:' ? '; Secure' : '');
        } catch (e) {}
    }

    function run() {
        var cfg = config();
        if (!consentGiven(cfg)) {
            return;
        }

        var state = readState();
        var touch = currentTouch();

        if (touch) {
            // Last non-direct touch wins, matching how merchants read their ad reports.
            state = state || { v: COOKIE_VERSION, f: null, l: null };
            state.l = touch;
            if (!state.f) {
                state.f = touch;
            }
            writeState(state, cfg.days);

            return;
        }

        if (!state) {
            // A genuinely direct first visit is still an answer — record it so
            // "(direct)" can be told apart from "we never saw this shopper".
            var direct = { ts: Math.floor(Date.now() / 1000), d: 1 };
            try {
                direct.lp = trim(window.location.pathname);
            } catch (e) {}

            writeState({ v: COOKIE_VERSION, f: direct, l: direct }, cfg.days);
        }
    }

    try {
        run();
    } catch (e) {}
})();
