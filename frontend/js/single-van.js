/**
 * Single-Van Action Bar Module for VAN-Jorn Rental POS
 *
 * Loaded only when the shortcode specifies a product_id (single-van mode).
 * Renders a date summary + "Book Now" button below the calendar grid,
 * replacing the camper card UI that the fleet view normally provides.
 *
 * Does not modify calendar.js or filters.js. Hooks into the render cycle
 * by wrapping window.vanposRenderCampers so the action bar updates on
 * every state change (date selection, availability response, reset, etc.).
 *
 * @package VJ_Rental_POS
 */

(function () {
    'use strict';

    // --- Bail if not in single-van mode ---
    const wrapper = document.querySelector('.vanjorn-rental-pos[data-single-van-id]');
    if (!wrapper) return;

    const singleVanId = parseInt(wrapper.dataset.singleVanId, 10);
    if (!singleVanId) return;

    const bar = document.getElementById('vanposSingleVanAction');
    if (!bar) return;

    const s = (typeof vanposData !== 'undefined' && vanposData.strings) ? vanposData.strings : {};
    const fmtPrice = typeof window.vanposFormatPrice === 'function' ? window.vanposFormatPrice : function (n) { return '€' + Number(n).toFixed(2); };
    // Local-timezone Y-m-d. Avoid toISOString(), which converts to UTC and shifts
    // the day backwards for a local-midnight date in any timezone ahead of UTC.
    function localYmd(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    const fmtDate = typeof vanposData !== 'undefined' && vanposData.dateLocale
        ? function (d) {
            try { return d.toLocaleDateString(vanposData.dateLocale, { year: 'numeric', month: 'short', day: 'numeric' }); }
            catch (e) { return localYmd(d); }
        }
        : function (d) { return localYmd(d); };

    /**
     * Calculate rental days — same Kestrel-compatible formula as calendar.js.
     * Both dates normalised to noon to avoid DST issues; result is inclusive.
     */
    function rentalDays(pickup, ret) {
        if (!pickup || !ret) return 0;
        var a = new Date(pickup.getFullYear(), pickup.getMonth(), pickup.getDate(), 12, 0, 0, 0).getTime();
        var b = new Date(ret.getFullYear(), ret.getMonth(), ret.getDate(), 12, 0, 0, 0).getTime();
        return Math.round(Math.abs((a - b) / 86400000)) + 1;
    }

    /**
     * Look up the camper object for the single van from the products array.
     */
    function getCamper() {
        var campers = typeof window.vanposCampers === 'function' ? window.vanposCampers() : [];
        return campers.find(function (c) { return c.id === singleVanId; }) || null;
    }

    /**
     * Check availability from the cache for the single van.
     * Returns: true | false | null (null = not yet checked / no cache entry).
     */
    function getAvailability(pickup, ret) {
        if (!pickup || !ret || !window.vanposAvailabilityCache) return null;
        var fd = typeof window.vanposFormatDate === 'function' ? window.vanposFormatDate : function (d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        };
        var key = fd(pickup) + '_' + fd(ret);
        var entry = window.vanposAvailabilityCache[key];
        if (!entry) return null;
        var van = entry[singleVanId];
        if (!van) return null;
        return van.available;
    }

    /**
     * Render / update the action bar based on current state.
     */
    function update() {
        var pickup = typeof window.vanposPickupDate === 'function' ? window.vanposPickupDate() : null;
        var ret    = typeof window.vanposReturnDate === 'function' ? window.vanposReturnDate() : null;
        var camper = getCamper();

        // No dates yet
        if (!pickup && !ret) {
            bar.style.display = '';
            bar.innerHTML =
                '<p class="vanpos-single-van-action-hint">' +
                (s.selectDatesHint || 'Select your pickup and return dates on the calendar.') +
                '</p>';
            return;
        }

        // Only pickup selected
        if (pickup && !ret) {
            bar.style.display = '';
            bar.innerHTML =
                '<div class="vanpos-single-van-action-dates">' +
                    '<span><strong>' + (s.pickupLabel || 'Pickup') + ':</strong> ' + fmtDate(pickup) + '</span>' +
                '</div>' +
                '<p class="vanpos-single-van-action-hint">' +
                (s.selectReturnHint || 'Now select your return date.') +
                '</p>';
            return;
        }

        // Both dates selected
        var days  = rentalDays(pickup, ret);
        var avail = getAvailability(pickup, ret);

        var datesHtml =
            '<div class="vanpos-single-van-action-dates">' +
                '<span><strong>' + (s.pickupLabel || 'Pickup') + ':</strong> ' + fmtDate(pickup) + '</span>' +
                '<span><strong>' + (s.returnLabel || 'Return') + ':</strong> ' + fmtDate(ret) + '</span>' +
                '<span><strong>' + (s.duration || 'Duration') + ':</strong> ' +
                    (typeof window.vanposPlural === 'function'
                        ? window.vanposPlural(days, s.daysCount_one || '%d day', s.daysCount_other || '%d days')
                        : days + ' days') +
                '</span>' +
            '</div>';

        // Still checking (no cache entry yet)
        if (avail === null) {
            bar.style.display = '';
            bar.innerHTML = datesHtml +
                '<button class="vanpos-single-van-action-btn" disabled>' +
                (s.checking || 'Checking...') +
                '</button>';
            return;
        }

        // Unavailable
        if (!avail) {
            bar.style.display = '';
            bar.innerHTML = datesHtml +
                '<button class="vanpos-single-van-action-btn" disabled>' +
                (s.notAvailableBadge || 'Not Available') +
                '</button>' +
                '<p class="vanpos-single-van-action-hint">' +
                (s.notAvailableMsg || 'Selected dates are not available. Please choose different dates.') +
                '</p>';
            return;
        }

        // Available — show price + Book Now
        var priceHtml = '';
        if (camper) {
            var total = days * camper.price;
            priceHtml = '<span class="vanpos-single-van-action-price">' + fmtPrice(total) + '</span>';
        }

        bar.style.display = '';
        bar.innerHTML = datesHtml + priceHtml +
            '<button class="vanpos-single-van-action-btn" id="vanposSingleVanBook">' +
            (s.bookNow || 'Book Now') +
            '</button>';

        // Wire up the button — goes through the same final availability check
        // and cart summary modal as the fleet-mode "Book Now" button.
        var btn = document.getElementById('vanposSingleVanBook');
        if (btn) {
            btn.addEventListener('click', function (e) {
                if (typeof window.vanposCheckAvailability === 'function') {
                    window.vanposCheckAvailability(singleVanId, e);
                }
            });
        }
    }

    // --- Hook into the render cycle ---
    // Every state change (date pick, availability response, reset) ends with
    // a call to window.vanposRenderCampers(). We wrap it so the action bar
    // updates automatically without touching calendar.js.
    var _origRenderCampers = window.vanposRenderCampers;
    window.vanposRenderCampers = function () {
        if (typeof _origRenderCampers === 'function') {
            _origRenderCampers();
        }
        update();
    };

    // Also wrap vanposRenderCalendar for cases where only the calendar
    // re-renders (e.g. month navigation — doesn't call renderCampers).
    var _origRenderCalendar = window.vanposRenderCalendar;
    window.vanposRenderCalendar = function () {
        if (typeof _origRenderCalendar === 'function') {
            _origRenderCalendar();
        }
        update();
    };

})();
