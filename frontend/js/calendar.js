/**
 * Calendar JavaScript for VAN-Jorn Rental POS
 * Based on mockup/js/calendar.js, adapted for WordPress
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

(function($) {
    'use strict';

    // Bail early if localized data is missing (e.g. aggressive caching)
    if (typeof vanposData === 'undefined') {
        console.error('VAN-Jorn Rental POS: vanposData not found (calendar.js)');
        return;
    }

    // Get settings from localized data
    const pickupDays = vanposData.pickupDays || [4, 5]; // Thursday, Friday
    const minRentalDays = parseInt(vanposData.minRentalDays, 10) || 6;
    const maxRentalDays = parseInt(vanposData.maxRentalDays, 10) || 22;
    const monthNames = vanposData.monthNames;
    const s = vanposData.strings || {};
    const esc = window.vanposEscapeHTML;

    /**
     * Recalculate the campers-section height from its rendered children.
     *
     * When a van is selected:  header + clear-btn + 1 card.
     * When no van is selected: header + 2 cards + gap (0.75rem).
     *
     * After fixing the section height, campersList gets a max-height so it
     * scrolls if its content exceeds the available space.
     */
    function vanposRecalcCampersHeight() {
        const section = document.querySelector('.campers-section');
        if (!section) return;

        const header = document.getElementById('campersHeader');
        const campersList = document.getElementById('campersList');
        const cards = campersList ? campersList.querySelectorAll('.camper-card:not(.hidden)') : [];

        if (!header || cards.length === 0) {
            section.style.height = '';
            if (campersList) {
                campersList.style.maxHeight = '';
                campersList.style.overflowY = '';
            }
            return;
        }

        const selectedVanId = typeof window.vanposSelectedVanId === 'function'
            ? window.vanposSelectedVanId()
            : null;

        // "Clear selection" button is excluded from the height calculation;
        // it lives in normal flow but the section is sized to header + cards only.
        let totalHeight = header.offsetHeight;

        const rootFontSize = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
        const gap = 0.75 * rootFontSize;

        if (selectedVanId) {
            // Single selected van — header + 1 card
            const card0Height = cards[0] ? cards[0].offsetHeight : 0;
            totalHeight += card0Height;
        } else {
            // Default view — header + 2 cards + gap between them
            const card0Height = cards[0] ? cards[0].offsetHeight : 0;
            const card1Height = cards[1] ? cards[1].offsetHeight : card0Height;
            totalHeight += card0Height + card1Height + gap;
        }

        section.style.height = totalHeight + 'px';

        // Make campersList scrollable within the remaining space
        const listMax = totalHeight - header.offsetHeight;
        campersList.style.maxHeight = listMax + 'px';
        campersList.style.overflowY = 'auto';
    }

    // Debounced resize handler — recalculates once resizing settles (150 ms)
    let _vanposResizeTimer = null;
    window.addEventListener('resize', function() {
        clearTimeout(_vanposResizeTimer);
        _vanposResizeTimer = setTimeout(vanposRecalcCampersHeight, 150);
    });

    /**
     * Show alert via SweetAlert2 when available, else fallback to native alert.
     * @param {string} icon - 'success' | 'error' | 'warning' | 'info'
     * @param {string} title - Modal title
     * @param {string} text - Modal text
     * @returns {Promise|undefined} - Promise when using SweetAlert2
     */
    function vanposSwal(icon, title, text) {
        if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
            return Swal.fire({
                icon: icon,
                title: title,
                text: text,
                confirmButtonColor: '#f97316'
            });
        }
        alert(title + (text ? ': ' + text : ''));
    }

    /**
     * Check if date is weekend
     */
    function isWeekend(date) {
        const day = date.getDay();
        return day === 0 || day === 6;
    }

    /**
     * Check if date is a pickup/return day
     */
    function isPickupReturnDay(date) {
        const day = date.getDay();
        return pickupDays.indexOf(day) !== -1;
    }

    /**
     * Format date to Y-m-d (use global from app.js)
     */
    const formatDate = window.vanposFormatDate || function(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    /**
     * Format date for user-facing display using the site locale.
     * Falls back to Y-m-d if toLocaleDateString is unavailable.
     */
    function formatDisplayDate(date) {
        if (!date) return '';
        try {
            const locale = vanposData.dateLocale || undefined;
            return date.toLocaleDateString(locale, { year: 'numeric', month: 'short', day: 'numeric' });
        } catch (e) {
            return formatDate(date);
        }
    }

    /**
     * Calculate rental days using same logic as rental plugin
     * Uses timestamps at 12:00:00 to avoid daylight saving time issues
     * Formula: Math.round(Math.abs((rentFrom - rentTo) / (24 * 60 * 60 * 1000))) + 1
     */
    function calculateRentalDays(pickupDate, returnDate) {
        if (!pickupDate || !returnDate) {
            return 0;
        }
        
        const pickupDateStr = formatDate(pickupDate);
        const returnDateStr = formatDate(returnDate);
        const pickupDateSplit = pickupDateStr.split('-');
        const returnDateSplit = returnDateStr.split('-');
        
        // Create timestamps at 12:00:00 (same as rental plugin)
        const pickupDateTimestamp = new Date(
            parseInt(pickupDateSplit[0]), 
            parseInt(pickupDateSplit[1]) - 1, // months are 0-indexed
            parseInt(pickupDateSplit[2]), 
            12, 0, 0, 0
        ).getTime();
        
        const returnDateTimestamp = new Date(
            parseInt(returnDateSplit[0]), 
            parseInt(returnDateSplit[1]) - 1, // months are 0-indexed
            parseInt(returnDateSplit[2]), 
            12, 0, 0, 0
        ).getTime();
        
        // Calculate days: Math.round(Math.abs((rentFrom - rentTo) / (24 * 60 * 60 * 1000))) + 1
        return Math.round(Math.abs((pickupDateTimestamp - returnDateTimestamp) / (24 * 60 * 60 * 1000))) + 1;
    }

    /**
     * Billable nights = inclusive rental days - 1. Pricing is per night; the
     * inclusive-day count is still used for the min/max duration validation.
     */
    function calculateRentalNights(pickupDate, returnDate) {
        const days = calculateRentalDays(pickupDate, returnDate);
        return days > 0 ? Math.max(0, days - 1) : 0;
    }

    /**
     * Add availability-level class to a calendar day element.
     */
    function addAvailabilityClass(el, count) {
        if (count === 0) el.classList.add('unavailable');
        else if (count > 2) el.classList.add('available-high');
        else el.classList.add('available-low');
    }

    /**
     * Get available campers for a date
     */
    function getAvailableCampersForDate(date) {
        const dateStr = formatDate(date);
        const campers = window.vanposCampers();
        const selectedVanId = window.vanposSelectedVanId();
        const pickupDate = window.vanposPickupDate();
        const returnDate = window.vanposReturnDate();
        let campersToCheck = campers;

        if (selectedVanId) {
            campersToCheck = campers.filter(c => c.id === selectedVanId);
        } else {
            // Filter by data state (not DOM) to avoid stale card visibility
            // during render-order races.
            const matchesFn = window.vanposCamperMatchesFilters;
            campersToCheck = matchesFn ? campers.filter(matchesFn) : campers;
        }

        // If dates are selected AND the queried date falls within the
        // selected range, use the range-based availability cache.
        // Days outside the range must fall through to the per-date check
        // so they reflect their own individual availability instead of
        // inheriting the selected range's result (fixes all-green bug).
        if (pickupDate && returnDate && window.vanposAvailabilityCache) {
            const isInSelectedRange = date >= pickupDate && date <= returnDate;
            if (isInSelectedRange) {
                const pickupDateStr = formatDate(pickupDate);
                const returnDateStr = formatDate(returnDate);
                const cacheKey = `${pickupDateStr}_${returnDateStr}`;
                const availability = window.vanposAvailabilityCache[cacheKey];

                if (availability) {
                    return campersToCheck.filter(camper => {
                        const camperAvailability = availability[camper.id];
                        return camperAvailability && camperAvailability.available;
                    });
                }
            }
        }

        // Per-date fallback for days outside the selected range.
        // CMIT CODE - UPDATED - 15 MAY 2026 — afternoon pickup vs morning return slots.
        const pickupBlocked = camper => camper.unavailablePickupDates || camper.unavailableDates || [];
        const returnBlocked = camper => camper.unavailableReturnDates || [];

        if (pickupDate && !returnDate) {
            const pickupStr = formatDate(pickupDate);
            if (dateStr > pickupStr) {
                return campersToCheck.filter(camper => returnBlocked(camper).indexOf(dateStr) === -1);
            }
        }

        return campersToCheck.filter(camper => pickupBlocked(camper).indexOf(dateStr) === -1);
    }

    /**
     * Check if camper is available for date range (half-day turnaround aware).
     */
    function isCamperAvailableForRange(camper, startDate, endDate) {
        const pickupBlocked = camper.unavailablePickupDates || camper.unavailableDates || [];
        const returnBlocked = camper.unavailableReturnDates || [];
        const fullBlocked = camper.unavailableFullDates || [];
        const startStr = formatDate(startDate);
        const endStr = formatDate(endDate);
        const current = new Date(startDate);

        while (current <= endDate) {
            const dateStr = formatDate(current);
            const isStart = dateStr === startStr;
            const isEnd = dateStr === endStr;

            if (isStart && isEnd) {
                if (pickupBlocked.indexOf(dateStr) !== -1 || returnBlocked.indexOf(dateStr) !== -1) {
                    return false;
                }
            } else if (isStart) {
                if (pickupBlocked.indexOf(dateStr) !== -1) {
                    return false;
                }
            } else if (isEnd) {
                if (returnBlocked.indexOf(dateStr) !== -1) {
                    return false;
                }
            } else {
                if (fullBlocked.indexOf(dateStr) !== -1) {
                    return false;
                }
                if (pickupBlocked.indexOf(dateStr) !== -1 || returnBlocked.indexOf(dateStr) !== -1) {
                    return false;
                }
            }
            current.setDate(current.getDate() + 1);
        }
        return true;
    }

    /**
     * Check if date is in range
     */
    function isDateInRange(date, start, end) {
        if (!start || !end) return false;
        const [earlierDate, laterDate] = start <= end ? [start, end] : [end, start];
        return date > earlierDate && date < laterDate;
    }

    /**
     * Render calendar
     */
    function vanposRenderCalendarImpl() {
        const currentDate = window.vanposCurrentDate();
        const pickupDate = window.vanposPickupDate();
        const returnDate = window.vanposReturnDate();
        const pickupTimeSlot = window.vanposPickupTimeSlot();
        const returnTimeSlot = window.vanposReturnTimeSlot();
        const selectedVanId = window.vanposSelectedVanId();

        // True once product data has loaded — prevents red "unavailable"
        // flash on every pickup/return day during the initial render.
        const allCampers = typeof window.vanposCampers === 'function' ? window.vanposCampers() : [];
        const hasCamperData = allCampers.length > 0;

        // True while an availability AJAX request is in flight. Suppresses
        // green/red coloring so stale fallback data doesn't flash briefly.
        const availabilityLoading = typeof window.vanposAvailabilityLoading === 'function' && window.vanposAvailabilityLoading();

        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();

        const monthYearEl = document.getElementById('monthYear');
        if (monthYearEl) {
            monthYearEl.innerHTML = `${monthNames[month]} ${year}`;
        }

        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const daysInMonth = lastDay.getDate();
        let startingDayOfWeek = firstDay.getDay();
        startingDayOfWeek = startingDayOfWeek === 0 ? 6 : startingDayOfWeek - 1;

        const calendarGrid = document.getElementById('calendarGrid');
        if (!calendarGrid) return;
        calendarGrid.innerHTML = '';

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        // Add 3-day buffer - dates within next 3 days are not selectable
        const minSelectableDate = new Date(today);
        minSelectableDate.setDate(today.getDate() + 3);
        minSelectableDate.setHours(0, 0, 0, 0);

        // Empty days
        for (let i = 0; i < startingDayOfWeek; i++) {
            const emptyDay = document.createElement('div');
            emptyDay.className = 'day empty';
            calendarGrid.appendChild(emptyDay);
        }

        // Calendar days
        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(year, month, day);
            const dateStr = formatDate(date);
            const availableCampers = getAvailableCampersForDate(date);
            const isPast = date < minSelectableDate;
            const isWeekendDay = isWeekend(date);
            const isPickupReturnDayValue = isPickupReturnDay(date);

            const dayElement = document.createElement('div');
            dayElement.className = 'day';

            const isPickup = pickupDate && formatDate(pickupDate) === dateStr;
            const isReturn = returnDate && formatDate(returnDate) === dateStr;
            const inRange = pickupDate && returnDate && isDateInRange(date, pickupDate, returnDate);

            if (isPast) {
                dayElement.classList.add('past');
            } else {
                if (isPickup) {
                    dayElement.classList.add('pickup');
                    if (pickupTimeSlot) {
                        dayElement.classList.add(`selected-${pickupTimeSlot}`);
                    }
                } else if (isReturn) {
                    dayElement.classList.add('return');
                    if (returnTimeSlot) {
                        dayElement.classList.add(`selected-${returnTimeSlot}`);
                    }
                } else if (inRange) {
                    if (isWeekendDay) {
                        dayElement.classList.add('weekend-in-range');
                    } else {
                        dayElement.classList.add('in-range');
                    }
                } else {
                    if (isPickupReturnDayValue) {
                        dayElement.classList.add('pickup-return-day');
                        // Only colour-code once product data is loaded.
                        // Days reaching this branch are always outside the
                        // selected range (pickup / return / in-range are
                        // handled above), so their per-date availability
                        // is still valid during a range-check AJAX request.
                        if (hasCamperData) {
                            if (selectedVanId) {
                                // Single-van context (selected van or product page):
                                // binary available / unavailable — no multi-level colours.
                                dayElement.classList.add(availableCampers.length > 0 ? 'available-high' : 'unavailable');
                            } else {
                                addAvailabilityClass(dayElement, availableCampers.length);
                            }
                        }
                    } else if (isWeekendDay) {
                        dayElement.classList.add('weekend');
                    }
                }

                if (isPickupReturnDayValue || isPickup || isReturn) {
                    dayElement.onclick = () => selectDate(date, dateStr);
                }
            }

            let tooltipText = '';
            if (isPast) {
                tooltipText = s.notAvailableAdvanceNotice || 'Not available - requires 3 days advance notice';
            } else if (isPickup) {
                const pickupTimeLocalized = pickupTimeSlot
                    ? (vanposData.pickupTime || '15:00')
                    : (s.selectTime || 'select time');
                tooltipText = (s.pickupTooltip || 'Pickup: %s').replace('%s', pickupTimeLocalized);
            } else if (isReturn) {
                const returnTimeLocalized = returnTimeSlot
                    ? (vanposData.returnTime || '11:00')
                    : (s.selectTime || 'select time');
                tooltipText = (s.returnTooltip || 'Return: %s').replace('%s', returnTimeLocalized);
            } else if (inRange) {
                tooltipText = isWeekendDay ? (s.weekendIncluded || 'Weekend (included)') : (s.includedInRental || 'Included in rental period');
            } else if (isPickupReturnDayValue) {
                if (selectedVanId) {
                    tooltipText = availableCampers.length > 0 ? (s.clickToSelect || 'Click to select') : (s.notAvailable || 'Not available');
                } else {
                    tooltipText = availableCampers.length === 0
                        ? (s.noVansAvailableForDate || 'No vans available')
                        : window.vanposPlural(availableCampers.length, s.vansAvailableCount_one || '%d van available', s.vansAvailableCount_other || '%d vans available');
                }
            } else if (isWeekendDay) {
                tooltipText = s.weekend || 'Weekend';
            }

            dayElement.innerHTML = `
                <span class="day-number">${day}</span>
                ${!isPast ? `<div class="tooltip">${tooltipText}</div>` : ''}
                ${isPickup && pickupTimeSlot ? `<div class="time-badge">${vanposData.pickupTime || '15:00'}</div>` : ''}
                ${isReturn && returnTimeSlot ? `<div class="time-badge">${vanposData.returnTime || '11:00'}</div>` : ''}
            `;

            calendarGrid.appendChild(dayElement);
        }

        // Enable/disable duration toolbar: enabled initially; disabled only when filters exclude all vans
        const campers = typeof window.vanposCampers === 'function' ? window.vanposCampers() : [];
        const hasMatchingVans = typeof window.vanposHasMatchingVans === 'function' && window.vanposHasMatchingVans();
        const shouldDisable = campers.length > 0 && !hasMatchingVans;
        const toolbar = document.querySelector('.duration-toolbar');
        if (toolbar) {
            toolbar.querySelectorAll('button').forEach(function(btn) {
                btn.disabled = shouldDisable;
            });
        }

        // Disable prev/next month buttons at navigation boundaries
        const now = new Date();
        const navMin = { year: now.getFullYear(), month: now.getMonth() };
        const navMax = { year: now.getFullYear() + 1, month: now.getMonth() };
        const prevBtn = document.getElementById('prevMonth');
        const nextBtn = document.getElementById('nextMonth');
        if (prevBtn) {
            prevBtn.disabled = (year === navMin.year && month <= navMin.month) || year < navMin.year;
        }
        if (nextBtn) {
            nextBtn.disabled = (year === navMax.year && month >= navMax.month) || year > navMax.year;
        }
    }

    /**
     * Add days to a date (returns new Date)
     */
    function addDays(date, days) {
        const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        d.setDate(d.getDate() + days);
        return d;
    }

    /**
     * Select date
     */
    function selectDate(date, dateStr) {
        // Block if no vans match filters
        if (typeof window.vanposHasMatchingVans === 'function' && !window.vanposHasMatchingVans()) {
            vanposSwal('warning',
                s.noVansMatch || 'No vans available',
                s.noVansMatchDesc || 'Adjust travelers or filters to see available vans. Date selection is disabled until you find a matching van.');
            return;
        }

        const pickupDate = window.vanposPickupDate();
        const returnDate = window.vanposReturnDate();
        const isPickup = pickupDate && formatDate(pickupDate) === dateStr;
        const isReturn = returnDate && formatDate(returnDate) === dateStr;
        const fixedDays = (typeof window.vanposFixedDurationDays === 'function') ? window.vanposFixedDurationDays() : null;

        // Single-click to deselect an already-selected day
        if (isPickup) {
            window.vanposSetPickupDateSilent(null);
            window.vanposSetPickupTimeSlot(null);
            if (returnDate) {
                // Promote return date to become the new pickup
                window.vanposSetPickupDateSilent(returnDate);
                window.vanposSetPickupTimeSlot('afternoon');
                window.vanposSetReturnDateSilent(null);
                window.vanposSetReturnTimeSlot(null);
            }
            window.vanposCheckAvailabilityForAllVans();
            return;
        }
        if (isReturn) {
            window.vanposSetReturnDateSilent(null);
            window.vanposSetReturnTimeSlot(null);
            window.vanposCheckAvailabilityForAllVans();
            return;
        }

        if (!isPickupReturnDay(date)) {
            return;
        }

        // Pickup is always afternoon, return is always morning
        const PICKUP_SLOT = 'afternoon';
        const RETURN_SLOT = 'morning';

        if (fixedDays === 8 || fixedDays === 15 || fixedDays === 22) {
            // Fixed-duration mode: return on same weekday as pickup (Thu pickup = Thu return)
            // Week 1 = 8 days, Week 2 = 15 days, Week 3 = 22 days
            const daysToAdd = fixedDays - 1; // 7, 14, 21 days to reach same weekday next week(s)
            if (!pickupDate && !returnDate) {
                window.vanposSetPickupDateSilent(date);
                window.vanposSetPickupTimeSlot(PICKUP_SLOT);
                const autoReturn = addDays(date, daysToAdd);
                if (!isPickupReturnDay(autoReturn)) {
                    window.vanposSetPickupDateSilent(null);
                    window.vanposSetPickupTimeSlot(null);
                    vanposSwal('warning', s.invalidReturnDate || 'Invalid return date', (s.invalidReturnDateMsg || 'For a %d-day rental, the return would fall on a day that is not Thursday or Friday. Pickup and return must be on Thursday or Friday. Please choose another start date.').replace('%d', fixedDays));
                } else {
                    window.vanposSetReturnDateSilent(autoReturn);
                    window.vanposSetReturnTimeSlot(RETURN_SLOT);
                }
            } else if (pickupDate && returnDate) {
                // New pickup: clear and set pickup + auto return
                window.vanposSetPickupDateSilent(date);
                window.vanposSetPickupTimeSlot(PICKUP_SLOT);
                const autoReturn = addDays(date, daysToAdd);
                if (!isPickupReturnDay(autoReturn)) {
                    window.vanposSetPickupDateSilent(pickupDate);
                    window.vanposSetPickupTimeSlot(PICKUP_SLOT);
                    vanposSwal('warning', s.invalidReturnDate || 'Invalid return date', (s.invalidReturnDateMsg || 'For a %d-day rental, the return would fall on a day that is not Thursday or Friday. Pickup and return must be on Thursday or Friday. Please choose another start date.').replace('%d', fixedDays));
                } else {
                    window.vanposSetReturnDateSilent(autoReturn);
                    window.vanposSetReturnTimeSlot(RETURN_SLOT);
                }
            } else if (pickupDate && !returnDate) {
                // Should not happen in fixed mode; if it does, set return
                const autoReturn = addDays(pickupDate, daysToAdd);
                if (isPickupReturnDay(autoReturn)) {
                    window.vanposSetReturnDateSilent(autoReturn);
                    window.vanposSetReturnTimeSlot(RETURN_SLOT);
                }
            }
        } else {
            // Manual mode: pickup then return, validate 6-22 days
            if (!pickupDate && !returnDate) {
                window.vanposSetPickupDateSilent(date);
                window.vanposSetPickupTimeSlot(PICKUP_SLOT);
            } else if (pickupDate && !returnDate) {
                window.vanposSetReturnDateSilent(date);
                window.vanposSetReturnTimeSlot(RETURN_SLOT);

                // If user clicked an earlier date, swap so pickup < return
                if (pickupDate > date) {
                    window.vanposSetPickupDateSilent(date);
                    window.vanposSetPickupTimeSlot(PICKUP_SLOT);
                    window.vanposSetReturnDateSilent(pickupDate);
                    window.vanposSetReturnTimeSlot(RETURN_SLOT);
                }

                const days = calculateRentalDays(window.vanposPickupDate(), window.vanposReturnDate());
                if (days < minRentalDays || days > maxRentalDays) {
                    window.vanposSetReturnDateSilent(null);
                    window.vanposSetReturnTimeSlot(null);
                    vanposSwal('warning', s.invalidDuration || 'Invalid duration', (s.invalidDurationMsg || 'Rental period must be between %1$d and %2$d days.').replace('%1$d', minRentalDays).replace('%2$d', maxRentalDays));
                }
            } else if (pickupDate && returnDate) {
                window.vanposSetPickupDateSilent(date);
                window.vanposSetPickupTimeSlot(PICKUP_SLOT);
                window.vanposSetReturnDateSilent(null);
                window.vanposSetReturnTimeSlot(null);
            }
        }

        window.vanposCheckAvailabilityForAllVans();
    }

    /**
     * Render campers list
     */
    function vanposRenderCampersImpl() {
        const campers = window.vanposCampers();
        const selectedVanId = window.vanposSelectedVanId();
        const pickupDate = window.vanposPickupDate();
        const returnDate = window.vanposReturnDate();
        const pickupTimeSlot = window.vanposPickupTimeSlot();
        const returnTimeSlot = window.vanposReturnTimeSlot();

        const campersList = document.getElementById('campersList');
        const campersHeader = document.getElementById('campersHeader');
        if (!campersList || !campersHeader) return;

        // Show / hide "Clear selection" button next to header
        let clearBtn = document.getElementById('vanposClearSelection');
        if (selectedVanId) {
            if (!clearBtn) {
                clearBtn = document.createElement('button');
                clearBtn.id = 'vanposClearSelection';
                clearBtn.className = 'clear-selection-btn';
                clearBtn.textContent = s.clearSelection || 'Clear selection';
                clearBtn.addEventListener('click', function() {
                    if (typeof window.vanposDeselectVan === 'function') {
                        window.vanposDeselectVan();
                    }
                });
                campersHeader.parentNode.insertBefore(clearBtn, campersHeader.nextSibling);
            }
            clearBtn.style.display = '';
        } else if (clearBtn) {
            clearBtn.style.display = 'none';
        }

        // Determine which campers to render into the DOM
        let renderedCampers;

        if (selectedVanId) {
            renderedCampers = campers.filter(c => c.id === selectedVanId);
        } else {
            // Always render ALL campers; filter visibility is handled by applyFilters below
            renderedCampers = campers;
        }

        if (renderedCampers.length === 0) {
            campersList.innerHTML = '<div class="empty-state"><p>' + (s.noVansMatchFilters || 'No vans match the filters') + '</p></div>';
            return;
        }

        /**
         * Determine if a camper is available for the currently selected date range.
         * Returns true when no dates are selected (default state).
         */
        function isCamperAvailable(camper) {
            if (!pickupDate || !returnDate) return true;
            const pickupDateStr = formatDate(pickupDate);
            const returnDateStr = formatDate(returnDate);
            const cacheKey = `${pickupDateStr}_${returnDateStr}`;
            if (window.vanposAvailabilityCache && window.vanposAvailabilityCache[cacheKey]) {
                const availability = window.vanposAvailabilityCache[cacheKey][camper.id];
                if (availability) return availability.available;
            }
            if (camper.availability) return camper.availability.available;
            return isCamperAvailableForRange(camper, pickupDate, returnDate);
        }

        // Sort unavailable vans to the bottom when dates are selected
        if (pickupDate && returnDate) {
            renderedCampers = [...renderedCampers].sort((a, b) => {
                const aAvail = isCamperAvailable(a);
                const bAvail = isCamperAvailable(b);
                if (aAvail === bAvail) return 0;
                return aAvail ? -1 : 1;
            });
        }

        campersList.innerHTML = renderedCampers.map(camper => {
            const available = isCamperAvailable(camper);

            let priceDisplay = `${window.vanposFormatPrice(camper.price)} <span class="price-period">${s.perNight || '/night'}</span>`;
            let buttonText = s.selectVan || 'Select this van';
            let buttonAction = `onclick="vanposSelectVan(${parseInt(camper.id)})"`;
            let buttonHidden = '';
            let badgeHtml = '';

            if (pickupDate && returnDate) {
                if (available) {
                    const nights = calculateRentalNights(pickupDate, returnDate);
                    const totalPrice = nights * camper.price;
                    priceDisplay = `${window.vanposFormatPrice(totalPrice)} <span class="price-period">${s.total || 'total'}</span>`;

                    if (pickupTimeSlot && returnTimeSlot) {
                        buttonText = s.bookNow || 'Book Now';
                        buttonAction = `onclick="vanposCheckAvailability(${parseInt(camper.id)}, event)"`;
                    } else {
                        buttonHidden = selectedVanId ? 'style="display: none;"' : '';
                    }
                    
                    // Show available badge
                    badgeHtml = '<div class="availability-badge available-badge">' + (s.available || 'Available') + '</div>';
                } else {
                    buttonText = s.selectVan || 'Select this van';
                    buttonAction = `onclick="vanposSelectVan(${parseInt(camper.id)})"`;
                    buttonHidden = 'style="display: none;"'; // Hide button for unavailable vans
                    
                    // Show unavailable badge
                    badgeHtml = '<div class="availability-badge unavailable-badge">' + (s.notAvailableBadge || 'Not Available') + '</div>';
                }
            } else {
                // No dates selected - no badge
                badgeHtml = '';
            }

            const cardClass = (!available && pickupDate && returnDate && !selectedVanId) ? 'camper-card unavailable-card' : 'camper-card';
            const safeImageUrl = camper.image ? camper.image.replace(/[()'"\\]/g, '\\$&') : '';
            const imageHtml = camper.image 
                ? `<div class="camper-image-wrapper">
                    <div class="camper-image" style="background-image: url(${safeImageUrl}); background-size: cover; background-position: center;"></div>
                    ${badgeHtml}
                   </div>`
                : `<div class="camper-image-wrapper">
                    <div class="camper-image"><span class="material-icons">directions_bus</span></div>
                    ${badgeHtml}
                   </div>`;

            // Build data attributes for filtering (using ACF fields)
            const equipmentData = camper.equipment && Array.isArray(camper.equipment) 
                ? camper.equipment.map(eq => String(eq).replace(/"/g, '&quot;').replace(/,/g, '&#44;')).join(',') 
                : '';
            const typeData = (camper.type || '').replace(/"/g, '&quot;').replace(/,/g, '&#44;');
            const dataAttributes = `data-id="${parseInt(camper.id)}" data-type="${typeData}" data-seats="${parseInt(camper.seats) || 0}" data-beds="${parseInt(camper.beds) || 0}" data-equipment="${equipmentData}"`;

            return `
                <div class="${cardClass}" ${dataAttributes}>
                    <div class="camper-content">
                        ${imageHtml}
                        <div class="camper-details">
                            <div class="camper-details-header">
                                <div class="camper-name">${esc(camper.name)}
                                <div class="camper-type">${esc(camper.type || '')}</div>
                                </div>
                                <div class="camper-price">${priceDisplay}</div>
                            </div>
                           
                            <div class="camper-info">
                                <span><span class="material-icons">group</span> ${window.vanposPlural(parseInt(camper.seats) || 0, s.seatsCount_one || '%d seat', s.seatsCount_other || '%d seats')}</span>
                                <span><span class="material-icons">bed</span> ${window.vanposPlural(parseInt(camper.beds) || 0, s.bedsCount_one || '%d bed', s.bedsCount_other || '%d beds')}</span>
                                ${camper.length ? `<span><span class="material-icons">straighten</span> ${esc(camper.length)}</span>` : ''}
                                ${camper.transmission ? `<span><span class="material-icons">settings</span> ${esc(camper.transmission)}</span>` : ''}
                                ${camper.fuel ? `<span><span class="material-icons">local_gas_station</span> ${esc(camper.fuel)}</span>` : ''}
                            </div>
                           
                        </div>
                    </div>
                    <div class="camper-footer-wrapper">
                        <div class="camper-footer">
                            <button class="details-btn" onclick="vanposShowVanDetails(${parseInt(camper.id)})">${s.details || 'Details'}</button>
                            <button class="view-btn" ${buttonAction} ${buttonHidden}>${buttonText}</button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        // Re-apply filters to newly rendered DOM elements
        if (!selectedVanId && typeof window.vanposApplyFilters === 'function') {
            window.vanposApplyFilters();
        }

        // Set campers-section height programmatically
        requestAnimationFrame(vanposRecalcCampersHeight);
    }

    /**
     * Check availability
     */
    window.vanposCheckAvailability = function(productId, event) {
        const pickupDate = window.vanposPickupDate();
        const returnDate = window.vanposReturnDate();
        const pickupTimeSlot = window.vanposPickupTimeSlot();
        const returnTimeSlot = window.vanposReturnTimeSlot();

        if (!pickupDate || !returnDate) {
            vanposSwal('info', s.datesRequired || 'Dates required', s.datesRequiredMsg || 'Please select both pickup and return dates.');
            return;
        }

        if (!pickupTimeSlot || !returnTimeSlot) {
            vanposSwal('info', s.timeSlotsRequired || 'Time slots required', s.timeSlotsRequiredMsg || 'Please select pickup and return time slots.');
            return;
        }

        // Show loading state
        const button = event ? event.target : document.querySelector(`[onclick*="vanposCheckAvailability(${productId}"]`);
        const originalText = button ? button.textContent : (s.bookNow || 'Book Now');
        if (button) {
            button.disabled = true;
            button.textContent = s.checking || 'Checking...';
        }

        $.ajax({
            url: vanposData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vanpos_check_availability',
                nonce: vanposData.nonce,
                product_id: productId,
                pickup_date: formatDate(pickupDate),
                return_date: formatDate(returnDate),
                pickup_time: pickupTimeSlot || '',
                return_time: returnTimeSlot || ''
            },
            success: function(response) {
                if (button) {
                    button.disabled = false;
                    button.textContent = originalText;
                }

                if (response.success && response.data.available) {
                    // Show cart summary modal - guests can add to cart and create account at checkout
                    window.vanposShowCartSummary(productId, response.data);
                } else {
                    vanposSwal('error', s.notAvailable || 'Not available', response.data.message || (s.notAvailableMsg || 'Selected dates are not available. Please choose different dates.'));
                }
            },
            error: function() {
                if (button) {
                    button.disabled = false;
                    button.textContent = originalText;
                }
                vanposSwal('error', s.error || 'Error', s.errorGeneric || 'An error occurred. Please try again.');
            }
        });
    };

    /**
     * Show cart summary modal
     */
    window.vanposShowCartSummary = function(productId, availabilityData) {
        const campers = window.vanposCampers();
        const camper = campers.find(c => c.id === productId);
        if (!camper) return;

        const pickupDate = window.vanposPickupDate();
        const returnDate = window.vanposReturnDate();
        const pickupTimeSlot = window.vanposPickupTimeSlot();
        const returnTimeSlot = window.vanposReturnTimeSlot();
        
        // Inclusive days for the duration label; billable nights for pricing.
        const days = calculateRentalDays(pickupDate, returnDate);
        const nights = calculateRentalNights(pickupDate, returnDate);
        const basePrice = nights * camper.price;

        const fmtPrice = window.vanposFormatPrice;

        // Get additional options from settings
        const dogEnabled = !!vanposData.dogEnabled;
        const dogPrice = parseFloat(vanposData.dogPrice) || 100;
        const cleaningEnabled = !!vanposData.cleaningEnabled;
        const cleaningPrice = parseFloat(vanposData.cleaningPrice) || 100;
        const securityDepositAmount = parseFloat(vanposData.securityDepositAmount) || 0;

        // Check if deposit applies (pickup date > N days away, from admin settings)
        const depositDaysThreshold = parseInt(vanposData.securityDepositDaysBeforePickup, 10) || 14;
        const pickupTimestamp = new Date(pickupDate).getTime();
        const currentTimestamp = Date.now();
        const daysUntilPickup = Math.ceil((pickupTimestamp - currentTimestamp) / (1000 * 60 * 60 * 24));
        const depositEnabled = !!vanposData.depositEnabled;
        const depositApplies = depositEnabled && daysUntilPickup > depositDaysThreshold;
        const depositPercentage = parseFloat(vanposData.depositPercentage) || 50;
        const remainingPercentage = 100 - depositPercentage;

        // Calculate totals (initial values with dog unchecked — updateTotal() runs immediately to confirm)
        const bookingCompleteTotal = basePrice + (cleaningEnabled ? cleaningPrice : 0);
        const initialExtraCosts = (cleaningEnabled ? cleaningPrice : 0);
        const itemDeposit = depositApplies ? (basePrice * depositPercentage / 100) : basePrice;
        const itemRemaining = depositApplies ? (basePrice * remainingPercentage / 100) : 0;
        const payNow = depositApplies ? (itemDeposit + initialExtraCosts) : bookingCompleteTotal;
        const payLater = depositApplies ? itemRemaining : 0;

        // Plural-aware days string for modal
        const daysStr = window.vanposPlural(days, s.daysCount_one || '%d day', s.daysCount_other || '%d days');

        // Create modal HTML
        const modalHTML = `
            <div id="vanposCartSummaryModal" class="vanpos-cart-summary-modal">
                <div class="vanpos-cart-summary-overlay" onclick="vanposCloseCartSummary()"></div>
                <div class="vanpos-cart-summary-content">
                    <div class="vanpos-cart-summary-header">
                        <h2>${s.bookingSummary || 'Booking Summary'}</h2>
                        <button class="vanpos-cart-summary-close" onclick="vanposCloseCartSummary()"><span class="material-icons">close</span></button>
                    </div>
                    <div class="vanpos-cart-summary-body">
                        <div class="vanpos-cart-summary-item">
                            <div class="vanpos-cart-summary-van">
                                <h3>${esc(camper.name)}</h3>
                                <p class="vanpos-cart-summary-type">${esc(camper.type)}</p>
                            </div>
                            <div class="vanpos-cart-summary-dates">
                                <p><strong>${s.pickupLabel || 'Pickup'}:</strong> ${formatDisplayDate(pickupDate)} (${vanposData.pickupTime || '15:00'})</p>
                                <p><strong>${s.returnLabel || 'Return'}:</strong> ${formatDisplayDate(returnDate)} (${vanposData.returnTime || '11:00'})</p>
                                <p><strong>${s.duration || 'Duration'}:</strong> ${daysStr}</p>
                            </div>
                        </div>
                        <div class="vanpos-cart-summary-pricing">
                            <h3 class="vanpos-cart-summary-pricing-title">${s.priceBreakdown || 'Price Breakdown'}</h3>
                            
                            <!-- Booking Complete Total -->
                            <div class="vanpos-cart-summary-section">
                                <h4 class="vanpos-cart-summary-section-title">${s.bookingCompleteTotal || 'Booking Complete Total'}</h4>
                                <div class="vanpos-cart-summary-line">
                                    <span>${(s.rentalNights || 'Rental (%d nights)').replace('%d', nights)}</span>
                                    <span>${fmtPrice(basePrice)}</span>
                                </div>
                                ${cleaningEnabled ? `
                                <div class="vanpos-cart-summary-line">
                                    <span>${s.cleaningService || 'Use our cleaning service'}</span>
                                    <span>${fmtPrice(cleaningPrice)}</span>
                                </div>
                                ` : ''}
                                <div class="vanpos-cart-summary-line vanpos-cart-summary-total-line">
                                    <span><strong>${s.bookingTotal || 'Booking Total'}</strong></span>
                                    <span><strong>${fmtPrice(bookingCompleteTotal)}</strong></span>
                                </div>
                            </div>

                            <!-- Security Deposit -->
                            ${securityDepositAmount > 0 ? `
                            <div class="vanpos-cart-summary-section">
                                <h4 class="vanpos-cart-summary-section-title">${s.securityDeposit || 'Security Deposit'}</h4>
                                <div class="vanpos-cart-summary-line">
                                    <span>${s.securityDeposit || 'Security Deposit'}</span>
                                    <span>${fmtPrice(securityDepositAmount)}</span>
                                </div>
                                <p class="vanpos-cart-summary-note">
                                    ${depositApplies
                                        ? (s.securityDepositNote || `This will be charged ${depositDaysThreshold} days before pickup and refunded after return.`)
                                        : (s.securityDepositNoteNear || 'You will receive a separate email shortly with the payment link for the deposit. The deposit will be refunded after return.')}
                                </p>
                            </div>
                            ` : ''}

                            <!-- Extra Costs -->
                            ${dogEnabled ? `
                            <div class="vanpos-cart-summary-section">
                                <h4 class="vanpos-cart-summary-section-title">${s.extraCosts || 'Extra Costs'}</h4>
                                <div class="vanpos-cart-summary-option">
                                    <label>
                                        <input type="checkbox" id="vanposDogOption" value="${dogPrice}">
                                        <span>${s.bringDog || 'Bring your dog'}</span>
                                    </label>
                                    <span class="vanpos-cart-summary-option-price">${fmtPrice(dogPrice)}</span>
                                </div>
                            </div>
                            ` : ''}

                            <div class="vanpos-cart-summary-divider"></div>

                            <!-- Payment Summary -->
                            <div class="vanpos-cart-summary-section vanpos-cart-summary-payment-section">
                                ${depositApplies ? `
                                <div class="vanpos-cart-summary-payment-info">
                                    <p class="vanpos-cart-summary-payment-note">
                                        <strong>${s.paymentPlan || 'Payment Plan:'}</strong> ${s.paymentPlanDesc || `Since your pickup is more than ${depositDaysThreshold} days away, you can pay ${depositPercentage}% now and ${remainingPercentage}% later.`}
                                    </p>
                                </div>
                                <div class="vanpos-cart-summary-line">
                                    <span>${s.payNowLabel || `Pay Now (${depositPercentage}% deposit + extra costs)`}</span>
                                    <span id="vanposPayNow" class="vanpos-cart-summary-highlight">${fmtPrice(payNow)}</span>
                                </div>
                                <div class="vanpos-cart-summary-line">
                                    <span>${s.payLaterLabel || `Pay Later (remaining ${remainingPercentage}%)`}</span>
                                    <span id="vanposPayLater" class="vanpos-cart-summary-highlight">${fmtPrice(payLater)}</span>
                                </div>
                                ` : `
                                <div class="vanpos-cart-summary-payment-info">
                                    <p class="vanpos-cart-summary-payment-note">
                                        <strong>${s.fullPayment || 'Full Payment:'}</strong> ${s.fullPaymentDesc || `Since your pickup is within ${depositDaysThreshold} days, full payment is required now.`}
                                    </p>
                                </div>
                                <div class="vanpos-cart-summary-line vanpos-cart-summary-total-line">
                                    <span><strong>${s.totalToPayNow || 'Total to Pay Now'}</strong></span>
                                    <span id="vanposPayNow" class="vanpos-cart-summary-highlight"><strong>${fmtPrice(payNow)}</strong></span>
                                </div>
                                `}
                                <div class="vanpos-cart-summary-vat">
                                    <span id="vanposCartVAT"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="vanpos-cart-summary-footer">
                        <button class="vanpos-cart-summary-cancel" onclick="vanposCloseCartSummary()">${s.cancel || 'Cancel'}</button>
                        <button class="vanpos-cart-summary-add" onclick="vanposAddToCartWithOptions(${productId})">${s.addToCart || 'Add to Cart'}</button>
                    </div>
                </div>
            </div>
        `;

        // Remove existing modal if any
        const existingModal = document.getElementById('vanposCartSummaryModal');
        if (existingModal) {
            existingModal.remove();
        }

        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Update total when options change
        const updateTotal = function() {
            // Dog option is optional
            let dogSelected = false;
            if (dogEnabled) {
                const dogCheckbox = document.getElementById('vanposDogOption');
                dogSelected = dogCheckbox && dogCheckbox.checked;
            }
            
            // Recalculate totals
            const currentExtraCosts = (dogSelected ? dogPrice : 0) + (cleaningEnabled ? cleaningPrice : 0);
            const currentItemDeposit = depositApplies ? (basePrice * depositPercentage / 100) : basePrice;
            const currentItemRemaining = depositApplies ? (basePrice * remainingPercentage / 100) : 0;
            const currentPayNow = depositApplies ? (currentItemDeposit + currentExtraCosts) : (bookingCompleteTotal + (dogSelected ? dogPrice : 0));
            const currentPayLater = depositApplies ? currentItemRemaining : 0;
            
            // Calculate VAT using correct formula depending on tax-inclusive pricing
            // Note: wp_localize_script converts all scalars to strings, so we must
            // parseFloat / !! to avoid e.g. 1 + "0.21" = "10.21" (string concat).
            const vatRate = (typeof vanposData !== 'undefined' && vanposData.vatRate) ? parseFloat(vanposData.vatRate) : 0.21;
            const pricesIncludeTax = (typeof vanposData !== 'undefined') ? !!vanposData.pricesIncludeTax : false;
            const vatAmount = pricesIncludeTax
                ? currentPayNow * vatRate / (1 + vatRate)   // Extract VAT from inclusive price
                : currentPayNow * vatRate;                   // Add VAT on top of exclusive price
            
            // Update Pay Now
            const payNowElement = document.getElementById('vanposPayNow');
            if (payNowElement) {
                payNowElement.textContent = fmtPrice(currentPayNow);
            }
            
            // Update Pay Later (if deposit applies)
            if (depositApplies) {
                const payLaterElement = document.getElementById('vanposPayLater');
                if (payLaterElement) {
                    payLaterElement.textContent = fmtPrice(currentPayLater);
                }
            }
            
            // Update VAT
            const vatElement = document.getElementById('vanposCartVAT');
            if (vatElement) {
                vatElement.textContent = (s.includingVat || 'Including %s VAT').replace('%s', fmtPrice(vatAmount));
            }
        };

        // Initialize total with cleaning service included
        updateTotal();

        if (dogEnabled) {
            const dogCheckbox = document.getElementById('vanposDogOption');
            if (dogCheckbox) {
                dogCheckbox.addEventListener('change', updateTotal);
            }
        }
    };

    /**
     * Close cart summary modal
     */
    window.vanposCloseCartSummary = function() {
        const modal = document.getElementById('vanposCartSummaryModal');
        if (modal) {
            modal.remove();
        }
    };

    /**
     * Add to cart with additional options
     */
    window.vanposAddToCartWithOptions = function(productId) {
        const pickupDate = window.vanposPickupDate();
        const returnDate = window.vanposReturnDate();
        const pickupTimeSlot = window.vanposPickupTimeSlot();
        const returnTimeSlot = window.vanposReturnTimeSlot();

        if (!pickupDate || !returnDate) {
            vanposSwal('info', s.datesRequired || 'Dates required', s.datesRequiredMsg || 'Please select both pickup and return dates.');
            return;
        }

        // Get additional options
        const dogOption = document.getElementById('vanposDogOption');
        const includeDog = dogOption && dogOption.checked;
        // Cleaning service is included when enabled in settings
        const includeCleaning = !!vanposData.cleaningEnabled;

        // Disable button during request
        const addButton = document.querySelector('.vanpos-cart-summary-add');
        if (addButton) {
            addButton.disabled = true;
            addButton.textContent = s.addingToCart || 'Adding to cart...';
        }

        $.ajax({
            url: vanposData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vanpos_add_to_cart',
                nonce: vanposData.nonce,
                product_id: productId,
                pickup_date: formatDate(pickupDate),
                return_date: formatDate(returnDate),
                pickup_time: pickupTimeSlot || '',
                return_time: returnTimeSlot || '',
                include_dog: includeDog ? '1' : '0',
                include_cleaning: includeCleaning ? '1' : '0'
            },
            success: function(response) {
                if (response.success) {
                    if (vanposData.cartUrl) {
                        window.location.href = vanposData.cartUrl;
                    } else {
                        vanposSwal('success', s.addedToCart || 'Added to cart', s.addedToCartMsg || 'Product added to cart successfully!').then(function() {
                            vanposCloseCartSummary();
                        });
                    }
                } else {
                    if (addButton) {
                        addButton.disabled = false;
                        addButton.textContent = s.addToCart || 'Add to Cart';
                    }
                    
                    vanposSwal('error', s.couldNotAddToCart || 'Could not add to cart', response.data.message || (s.failedToAddToCart || 'Failed to add to cart.'));
                }
            },
            error: function() {
                if (addButton) {
                    addButton.disabled = false;
                    addButton.textContent = s.addToCart || 'Add to Cart';
                }
                vanposSwal('error', s.error || 'Error', s.errorGeneric || 'An error occurred. Please try again.');
            }
        });
    };

    /**
     * Initialize calendar
     */
    function vanposInitCalendarImpl() {
        const prevBtn = document.getElementById('prevMonth');
        const nextBtn = document.getElementById('nextMonth');
        const backBtn = document.getElementById('backToCalendar');

        // Navigation ceiling: same month next year (current time + 1 year).
        // Floor: current month.
        function getNavBounds() {
            const now = new Date();
            return {
                minYear: now.getFullYear(),
                minMonth: now.getMonth(),
                maxYear: now.getFullYear() + 1,
                maxMonth: now.getMonth()
            };
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                const currentDate = window.vanposCurrentDate();
                const bounds = getNavBounds();
                const prevMonth = currentDate.getMonth() - 1;
                const prevYear = prevMonth < 0 ? currentDate.getFullYear() - 1 : currentDate.getFullYear();
                const prevMonthNorm = (prevMonth + 12) % 12;
                if (prevYear < bounds.minYear || (prevYear === bounds.minYear && prevMonthNorm < bounds.minMonth)) {
                    return; // already at floor
                }
                currentDate.setMonth(currentDate.getMonth() - 1);
                window.vanposSetCurrentDate(currentDate);
                window.vanposRenderCalendar();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const currentDate = window.vanposCurrentDate();
                const bounds = getNavBounds();
                const nextMonth = currentDate.getMonth() + 1;
                const nextYear = nextMonth > 11 ? currentDate.getFullYear() + 1 : currentDate.getFullYear();
                const nextMonthNorm = nextMonth % 12;
                if (nextYear > bounds.maxYear || (nextYear === bounds.maxYear && nextMonthNorm > bounds.maxMonth)) {
                    return; // already at ceiling
                }
                currentDate.setMonth(currentDate.getMonth() + 1);
                window.vanposSetCurrentDate(currentDate);
                window.vanposRenderCalendar();
            });
        }

        if (backBtn) {
            backBtn.addEventListener('click', () => {
                window.vanposHideVanDetails();
            });
        }

        // --- MOBILE ANCHORING (EXEMPTING CLEAR SELECTION) ---
        const campersSection = document.querySelector('.campers-section');
        if (campersSection) {
            campersSection.addEventListener('click', function(event) {
                // Apply only on mobile screen viewports (matching 768px CSS breakpoint)
                if (window.innerWidth <= 1024) {
                    const button = event.target.closest('button');
                    
                    if (button) {
                        // EXEMPTION: Identify if the target button is the clear selection element
                        const isClearBtn = button.classList.contains('clear-selection-btn') || button.id === 'vanposClearSelection';
                        
                        // Proceed with scrolling only if it's NOT the clear button
                        if (!isClearBtn) {
                            const calendarSection = document.querySelector('.calendar-section') || document.querySelector('.calendar-content');
                            if (calendarSection) {
                                // 100ms delay ensures state re-renders finish before executing the scroll
                                setTimeout(() => {
                                    calendarSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                }, 100);
                            }
                        }
                    }
                }
            });
        }

        window.vanposRenderCalendar();
        window.vanposRenderCampers();
    }

    // Expose functions
    window.vanposRenderCalendarImpl = vanposRenderCalendarImpl;
    window.vanposRenderCampersImpl = vanposRenderCampersImpl;
    window.vanposInitCalendarImpl = vanposInitCalendarImpl;

    // Note: availability cache is managed by app.js (window.vanposAvailabilityCache)

})(jQuery);
