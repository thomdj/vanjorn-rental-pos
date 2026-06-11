/**
 * Main App JavaScript for VAN-Jorn Rental POS
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

(function($) {
    'use strict';

    // Global variables
    let vanposCampers = [];
    let vanposCurrentDate = new Date();
    let vanposPickupDate = null;
    let vanposReturnDate = null;
    let vanposPickupTimeSlot = null;
    let vanposReturnTimeSlot = null;
    let vanposSelectedVanId = null;
    let vanposAvailabilityCache = {}; // Cache for availability results
    let overlayYear = new Date().getFullYear();
    let vanposDurationMode = 'manual'; // 'manual' or 8 | 15 | 22 for fixed days (return same weekday)

    // Expose to global scope for calendar.js
    window.vanposAvailabilityCache = vanposAvailabilityCache;

    // Check if vanposData is available
    if (typeof vanposData === 'undefined') {
        console.error('VAN-Jorn Rental POS: vanposData not found');
        return;
    }

    // Shorthand for localized strings
    const s = vanposData.strings || {};

    /**
     * Escape a string for safe insertion into HTML.
     * @param {string} str
     * @returns {string}
     */
    function vanposEscapeHTML(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
    window.vanposEscapeHTML = vanposEscapeHTML;

    /**
     * Select singular or plural form and replace %d placeholder.
     * Handles the most common two-form plural rule (one vs other).
     *
     * @param {number} n     - The count.
     * @param {string} one   - Singular form with %d placeholder.
     * @param {string} other - Plural form with %d placeholder.
     * @returns {string}
     */
    function vanposPlural(n, one, other) {
        return (n === 1 ? one : other).replace('%d', n);
    }
    window.vanposPlural = vanposPlural;

    /**
     * Format a price with the correct currency symbol, position, and
     * locale-aware decimal / thousands separators.
     *
     * Reads WooCommerce settings passed via vanposData:
     *   currencyPos  – left | right | left_space | right_space
     *   decimalSep   – e.g. ',' for NL, '.' for US
     *   thousandSep  – e.g. '.' for NL, ',' for US
     *   numDecimals  – number of decimal places (default 2)
     *
     * @param {number|string} amount - Numeric amount.
     * @returns {string}
     */
    function vanposFormatPrice(amount) {
        const currency = typeof wc_add_to_cart_params !== 'undefined'
            ? wc_add_to_cart_params.currency_symbol || '€'
            : (vanposData.currency || '€');
        const pos      = vanposData.currencyPos || 'left';
        const decSep   = vanposData.decimalSep  || ',';
        const thousSep = vanposData.thousandSep || '';
        const decimals = parseInt(vanposData.numDecimals, 10);
        const numDec   = isNaN(decimals) ? 2 : decimals;

        // Build the formatted number string
        const num     = typeof amount === 'number' ? amount : parseFloat(amount) || 0;
        const fixed   = num.toFixed(numDec);               // e.g. "1234.50"
        const parts   = fixed.split('.');                   // ["1234", "50"]
        // Insert thousands separator into the integer part
        const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousSep);
        const val     = numDec > 0 ? intPart + decSep + parts[1] : intPart;

        switch (pos) {
            case 'right':       return val + currency;
            case 'left_space':  return currency + '\u00A0' + val;
            case 'right_space': return val + '\u00A0' + currency;
            default:            return currency + val; // 'left'
        }
    }
    window.vanposFormatPrice = vanposFormatPrice;

    /**
     * Initialize the application
     */
    function vanposInit() {
        // Render the calendar grid immediately so the layout has proper
        // height while products are still loading over AJAX.
        vanposInitCalendar();
        vanposInitOverlay();

        // Load products, then re-render with availability data
        vanposLoadProducts().then(() => {
            // Re-render calendar now that product data is available
            // (campers list is already rendered by the success handler)
            vanposRenderCalendar();

            // Single-van mode: auto-select the van specified by the shortcode.
            // The product_id is stored as a data-single-van-id attribute on the
            // wrapper div when [vanjorn_rental_pos product_id="X"] is used.
            const wrapper = document.querySelector('.vanjorn-rental-pos[data-single-van-id]');
            const singleId = wrapper ? parseInt(wrapper.dataset.singleVanId, 10) : 0;
            if (singleId && vanposCampers.some(c => c.id === singleId)) {
                vanposSelectedVanId = singleId;
                vanposRenderCampers();
                vanposRenderCalendar();
            }
        });
    }

    /**
     * Load products from server
     */
    function vanposLoadProducts() {
        // Show loading state
        const campersList = document.getElementById('campersList');
        if (campersList) {
            campersList.innerHTML = '<div class="empty-state"><p>' + (s.loadingVans || 'Loading vans...') + '</p></div>';
        }

        return $.ajax({
            url: vanposData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vanpos_get_products',
                nonce: vanposData.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    vanposCampers = Array.isArray(response.data) ? response.data : [];
                    
                    if (vanposCampers.length > 0) {
                        vanposRenderCampers();
                        vanposPopulateFilters();
                        // Apply initial filters after rendering
                        setTimeout(function() {
                            if (window.vanposApplyFilters) {
                                window.vanposApplyFilters();
                            }
                        }, 100);
                    } else {
                        // No products found
                        if (campersList) {
                            campersList.innerHTML = '<div class="empty-state"><p>' + (s.noVansAvailable || 'No rental vans available. Please add WooCommerce products with ACF fields configured (van_type, number_seating_options, number_sleeping_options, additional_equipment).') + '</p></div>';
                        }
                        // Still populate filters (even if empty) so UI doesn't break
                        vanposPopulateFilters();
                    }
                } else {
                    // Error response from server
                    let errorMsg = (s.errorLoadingProducts || 'Error loading products.') + ' ';
                    if (response.data && response.data.message) {
                        errorMsg += vanposEscapeHTML(response.data.message);
                    }
                    if (campersList) {
                        campersList.innerHTML = '<div class="empty-state"><p>' + errorMsg + '</p></div>';
                    }
                    vanposPopulateFilters();
                }
            },
            error: function(xhr, status, error) {
                let errorMessage = (s.errorLoadingVans || 'Error loading vans.') + ' ';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage += vanposEscapeHTML(xhr.responseJSON.data.message);
                } else if (xhr.status === 403) {
                    errorMessage += s.securityCheckFailed || 'Security check failed. Please refresh the page.';
                } else if (xhr.status === 500) {
                    errorMessage += s.serverError || 'Server error. Please check if WooCommerce and ACF are active.';
                } else {
                    errorMessage += s.checkConsole || 'Please check console for details and refresh the page.';
                }
                
                if (campersList) {
                    campersList.innerHTML = '<div class="empty-state"><p>' + errorMessage + '</p></div>';
                }
                // Still try to populate filters
                vanposPopulateFilters();
            }
        });
    }

    /**
     * Update overlay month buttons and year nav (labels + disabled state).
     * Current year: disable months before current month.
     * Max year (current + 1): disable months after current month (the +1 year cap).
     */
    function updateOverlayYearMonthButtons() {
        const now = new Date();
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth();
        const maxYear = currentYear + 1;
        const monthNames = vanposData.monthNames;

        document.querySelectorAll('.month-option-btn').forEach(btn => {
            const monthIndex = parseInt(btn.dataset.month, 10);
            if (isNaN(monthIndex) || monthIndex < 0 || monthIndex > 11) return;
            btn.textContent = monthNames[monthIndex] + ' ' + overlayYear;
            if (overlayYear === currentYear) {
                btn.disabled = monthIndex < currentMonth;
            } else if (overlayYear === maxYear) {
                btn.disabled = monthIndex > currentMonth;
            } else {
                btn.disabled = false;
            }
        });

        const prevYearBtn = document.querySelector('.prev-year');
        const nextYearBtn = document.querySelector('.next-year');
        if (prevYearBtn) prevYearBtn.disabled = overlayYear <= currentYear;
        if (nextYearBtn) nextYearBtn.disabled = overlayYear >= currentYear + 1;
    }

    /**
     * Initialize overlay: year nav, month buttons, duration toolbar, overlay hidden by default
     */
    function vanposInitOverlay() {
        const overlay = document.getElementById('overlay');
        const monthOptionBtns = document.querySelectorAll('.month-option-btn');
        const filtersSection = document.querySelector('.filters-section');
        const campersSection = document.querySelector('.campers-section');

        function hideOverlay() {
            if (overlay) {
                overlay.classList.add('hidden');
            }
        }

        overlayYear = new Date().getFullYear();
        updateOverlayYearMonthButtons();

        document.querySelectorAll('.prev-year').forEach(btn => {
            btn.addEventListener('click', () => {
                const currentYear = new Date().getFullYear();
                if (overlayYear > currentYear) {
                    overlayYear--;
                    updateOverlayYearMonthButtons();
                }
            });
        });

        document.querySelectorAll('.next-year').forEach(btn => {
            btn.addEventListener('click', () => {
                const maxYear = new Date().getFullYear() + 1;
                if (overlayYear < maxYear) {
                    overlayYear++;
                    updateOverlayYearMonthButtons();
                }
            });
        });

        monthOptionBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                if (btn.disabled) return;
                const month = parseInt(btn.dataset.month, 10);
                const year = overlayYear;
                vanposCurrentDate = new Date(year, month, 1);
                hideOverlay();
                if (window.vanposRenderCalendar) window.vanposRenderCalendar();
            });
        });

        document.querySelectorAll('.duration-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                vanposClearDates();
                const duration = btn.dataset.duration;
                vanposDurationMode = duration === 'manual' ? 'manual' : parseInt(duration, 10);
                document.querySelectorAll('.duration-btn').forEach(b => {
                    b.classList.remove('active');
                    b.setAttribute('aria-pressed', 'false');
                });
                btn.classList.add('active');
                btn.setAttribute('aria-pressed', 'true');

                hideOverlay();
                if (window.vanposRenderCalendar) window.vanposRenderCalendar();
            });
        });

        if (filtersSection) {
            filtersSection.addEventListener('click', () => {
                if (overlay && !overlay.classList.contains('hidden')) {
                    hideOverlay();
                }
            });
        }

        if (campersSection) {
            campersSection.addEventListener('click', () => {
                if (overlay && !overlay.classList.contains('hidden')) {
                    hideOverlay();
                }
            });
        }

        const showMonthPickerBtn = document.getElementById('showMonthPicker');
        if (showMonthPickerBtn && overlay) {
            showMonthPickerBtn.addEventListener('click', () => {
                vanposClearDates();
                overlayYear = (window.vanposCurrentDate && window.vanposCurrentDate()) ? window.vanposCurrentDate().getFullYear() : new Date().getFullYear();
                updateOverlayYearMonthButtons();
                overlay.classList.remove('hidden');
            });
        }

        const clearDatesBtn = document.getElementById('vanposClearDates');
        if (clearDatesBtn) {
            clearDatesBtn.addEventListener('click', () => {
                vanposClearDates();
            });
        }
    }

    /**
     * Populate filter options from predefined lists
     * - Van Type: All available van types
     * - Equipment: All available equipment options
     */
    function vanposPopulateFilters() {
        // Get all van types from localized data
        const allVanTypes = vanposData.vanTypes || [];
        
        const typeContainer = document.getElementById('vanTypeFilters');
        if (typeContainer) {
            if (allVanTypes.length > 0) {
                typeContainer.innerHTML = allVanTypes.map(type => {
                    const escapedType = String(type).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    return `
                        <label>
                            <input type="checkbox" value="${escapedType}" class="type-filter" data-type="${escapedType}">
                            ${vanposEscapeHTML(type)}
                        </label>
                    `;
                }).join('');
            } else {
                typeContainer.innerHTML = '<p style="font-size: 0.875em; color: inherit; opacity: 0.7;">' + (s.noVanTypes || 'No van types available') + '</p>';
            }
        }

        // Get all equipment options from localized data
        const allEquipmentOptions = vanposData.equipmentOptions || [];
        
        const equipmentContainer = document.getElementById('equipmentFilters');
        if (equipmentContainer) {
            if (allEquipmentOptions.length > 0) {
                // Sort equipment alphabetically
                const sortedEquipment = [...allEquipmentOptions].sort();
                equipmentContainer.innerHTML = sortedEquipment.map(eq => {
                    const escapedEq = String(eq).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    return `
                        <label>
                            <input type="checkbox" value="${escapedEq}" class="equipment-filter" data-equipment="${escapedEq}">
                            ${vanposEscapeHTML(eq)}
                        </label>
                    `;
                }).join('');
            } else {
                equipmentContainer.innerHTML = '<p style="font-size: 0.875em; color: inherit; opacity: 0.7;">' + (s.noEquipment || 'No equipment available') + '</p>';
            }
        }

        // Reinitialize filters after populating
        vanposInitFilters();
    }

    // Make functions globally accessible
    window.vanposSelectVan = function(vanId) {
        vanposSelectedVanId = vanId;
        // Don't clear dates when van is selected - allow van-first flow
        // If dates are already selected, check availability for this van
        if (vanposPickupDate && vanposReturnDate) {
            vanposCheckAvailabilityForAllVans();
        } else {
            vanposRenderCalendar();
            vanposRenderCampers();
        }
    };

    /**
     * Deselect the currently selected van (keep dates and filters intact).
     *
     * When a van was selected while dates were set, the availability cache
     * may only contain that single van's result (partial entry). Deleting
     * the cache key forces vanposCheckAvailabilityForAllVans() to do a
     * fresh batch AJAX for every van instead of short-circuiting on the
     * incomplete entry.
     */
    window.vanposDeselectVan = function() {
        if (!vanposSelectedVanId) return;
        // In single-van mode the van is locked — don't deselect.
        if (document.querySelector('.vanjorn-rental-pos[data-single-van-id]')) return;
        vanposSelectedVanId = null;
        if (window.vanposHideVanDetails) {
            window.vanposHideVanDetails();
        }
        if (vanposPickupDate && vanposReturnDate) {
            const cacheKey = formatDate(vanposPickupDate) + '_' + formatDate(vanposReturnDate);
            delete vanposAvailabilityCache[cacheKey];
            vanposCheckAvailabilityForAllVans();
        } else {
            vanposRenderCalendar();
            vanposRenderCampers();
        }
    };

    /**
     * Clear selected pickup/return dates and time slots (for reset / change duration).
     * Does not clear selected van.
     */
    function vanposClearDates() {
        vanposPickupDate = null;
        vanposReturnDate = null;
        vanposPickupTimeSlot = null;
        vanposReturnTimeSlot = null;
        vanposRenderCalendar();
        vanposRenderCampers();
    }

    /**
     * Full reset: dates, selected van, duration mode, and view.
     * Used by "Clear all" to let user start fresh. Does not touch filters.
     */
    function vanposFullReset() {
        // In single-van mode, keep the van selected — only reset dates/duration.
        if (!document.querySelector('.vanjorn-rental-pos[data-single-van-id]')) {
            vanposSelectedVanId = null;
        }
        vanposPickupDate = null;
        vanposReturnDate = null;
        vanposPickupTimeSlot = null;
        vanposReturnTimeSlot = null;
        vanposDurationMode = 'manual';
        const now = new Date();
        vanposCurrentDate = new Date(now.getFullYear(), now.getMonth(), 1);
        if (window.vanposHideVanDetails) {
            window.vanposHideVanDetails();
        }
        document.querySelectorAll('.duration-btn').forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-pressed', 'false');
        });
        const manualBtn = document.querySelector('.duration-btn[data-duration="manual"]');
        if (manualBtn) {
            manualBtn.classList.add('active');
            manualBtn.setAttribute('aria-pressed', 'true');
        }
        vanposRenderCalendar();
        vanposRenderCampers();
    }

    window.vanposShowVanDetails = function(vanId) {
        const van = vanposCampers.find(c => c.id === vanId);
        if (!van) return;

        document.getElementById('vanDetailsName').textContent = van.name;

        // Gallery / hero image
        const vanHero = document.getElementById('vanHero');

        // Build image list: gallery objects {thumb, full} or fallback to featured image
        let galleryImages = [];
        if (van.gallery && van.gallery.length > 0) {
            galleryImages = van.gallery.map(img => ({
                thumb: img.thumb || img.full || '',
                full: img.full || img.thumb || ''
            }));
        } else if (van.image) {
            galleryImages = [{ thumb: van.image, full: van.image }];
        }
        const fullUrls = galleryImages.map(img => img.full);

        if (galleryImages.length > 1) {
            // Build gallery carousel with lazy-loaded <img> tags
            let currentSlide = 0;
            const slidesHTML = galleryImages.map((img, i) => {
                const safeThumb = vanposEscapeHTML(img.thumb);
                const srcAttr = i === 0 ? ` src="${safeThumb}"` : '';
                return `<div class="vanpos-gallery-slide${i === 0 ? ' active' : ''}"><img${srcAttr} data-src="${safeThumb}" alt="" loading="lazy" draggable="false"></div>`;
            }).join('');

            vanHero.style.backgroundImage = '';
            vanHero.innerHTML = `
                <div class="vanpos-gallery">
                    <div class="vanpos-gallery-slides">${slidesHTML}</div>
                    <button class="vanpos-gallery-btn vanpos-gallery-prev"><span class="material-icons">chevron_left</span></button>
                    <button class="vanpos-gallery-btn vanpos-gallery-next"><span class="material-icons">chevron_right</span></button>
                    <div class="vanpos-gallery-dots">
                        ${galleryImages.map((_, i) => `<span class="vanpos-gallery-dot${i === 0 ? ' active' : ''}" data-index="${i}"></span>`).join('')}
                    </div>
                </div>
            `;

            const slides = vanHero.querySelectorAll('.vanpos-gallery-slide');
            const dots = vanHero.querySelectorAll('.vanpos-gallery-dot');

            function preloadAdjacent(index) {
                [-1, 0, 1].forEach(offset => {
                    const idx = (index + offset + galleryImages.length) % galleryImages.length;
                    const img = slides[idx].querySelector('img');
                    if (img && !img.getAttribute('src')) img.src = img.dataset.src;
                });
            }
            preloadAdjacent(0);

            function goToSlide(index) {
                currentSlide = (index + galleryImages.length) % galleryImages.length;
                preloadAdjacent(currentSlide);
                slides.forEach((slide, i) => slide.classList.toggle('active', i === currentSlide));
                dots.forEach((dot, i) => dot.classList.toggle('active', i === currentSlide));
            }

            vanHero.querySelector('.vanpos-gallery-prev').onclick = (e) => { e.stopPropagation(); goToSlide(currentSlide - 1); };
            vanHero.querySelector('.vanpos-gallery-next').onclick = (e) => { e.stopPropagation(); goToSlide(currentSlide + 1); };
            dots.forEach(dot => {
                dot.onclick = (e) => { e.stopPropagation(); goToSlide(parseInt(dot.dataset.index, 10)); };
            });

            // Open lightbox on slide click (uses full-size URLs)
            slides.forEach(slide => {
                slide.onclick = () => vanposOpenLightbox(fullUrls, currentSlide);
            });
        } else if (galleryImages.length === 1) {
            vanHero.style.backgroundImage = '';
            vanHero.innerHTML = `<img src="${vanposEscapeHTML(galleryImages[0].thumb)}" alt="" draggable="false" class="vanpos-gallery-single">`;
            vanHero.style.cursor = 'pointer';
            vanHero.onclick = () => vanposOpenLightbox(fullUrls, 0);
        } else {
            vanHero.innerHTML = '<span class="material-icons">directions_bus</span>';
            vanHero.style.backgroundImage = '';
            vanHero.style.cursor = '';
            vanHero.onclick = null;
        }

        // Excerpt
        const vanExcerpt = document.getElementById('vanExcerpt');
        if (vanExcerpt) {
            if (van.excerpt) {
                vanExcerpt.innerHTML = van.excerpt;
                vanExcerpt.style.display = '';
            } else {
                vanExcerpt.innerHTML = '';
                vanExcerpt.style.display = 'none';
            }
        }

        document.getElementById('vanType').textContent = van.type;
        document.getElementById('vanPrice').textContent = vanposFormatPrice(van.price);
        document.getElementById('vanSeats').textContent = vanposPlural(parseInt(van.seats) || 0, s.seatsCount_one || '%d seat', s.seatsCount_other || '%d seats');
        document.getElementById('vanBeds').textContent = vanposPlural(parseInt(van.beds) || 0, s.bedsCount_one || '%d bed', s.bedsCount_other || '%d beds');
        document.getElementById('vanLength').textContent = van.length || (s.notApplicable || 'N/A');
        document.getElementById('vanTransmission').textContent = van.transmission || (s.notApplicable || 'N/A');
        document.getElementById('vanFuel').textContent = van.fuel || (s.notApplicable || 'N/A');

        const equipmentContainer = document.getElementById('vanEquipment');
        if (van.equipment && van.equipment.length > 0) {
            equipmentContainer.innerHTML = van.equipment
                .map(item => `<div class="equipment-item">${vanposEscapeHTML(item)}</div>`)
                .join('');
            document.getElementById('vanEquipmentSection').style.display = 'block';
        } else {
            document.getElementById('vanEquipmentSection').style.display = 'none';
        }

        // To van page link
        const moreInfoBtn = document.getElementById('moreInfoFromDetails');
        if (moreInfoBtn) {
            if (van.permalink) {
                moreInfoBtn.href = van.permalink;
                moreInfoBtn.style.display = '';
            } else {
                moreInfoBtn.style.display = 'none';
            }
        }

        const selectBtn = document.getElementById('selectVanFromDetails');
        selectBtn.onclick = () => {
            vanposHideVanDetails();
            vanposSelectVan(vanId);
        };

        document.getElementById('vanDetailsView').classList.add('active');
        document.getElementById('calendarContent').classList.add('hidden');
    };

    window.vanposHideVanDetails = function() {
        document.getElementById('vanDetailsView').classList.remove('active');
        document.getElementById('calendarContent').classList.remove('hidden');
    };

    /**
     * Open a full-screen lightbox for the given images.
     *
     * @param {string[]} images  Array of image URLs.
     * @param {number}   startIndex  Index to show first.
     */
    function vanposOpenLightbox(images, startIndex) {
        if (!images || images.length === 0) return;

        let current = startIndex || 0;
        const multi = images.length > 1;

        const el = document.createElement('div');
        el.className = 'vanpos-lightbox';
        el.innerHTML = `
            <button class="vanpos-lightbox-close"><span class="material-icons">close</span></button>
            <img class="vanpos-lightbox-img" src="${images[current].replace(/"/g, '&quot;')}" alt="">
            ${multi ? `
                <button class="vanpos-lightbox-btn vanpos-lightbox-prev"><span class="material-icons">chevron_left</span></button>
                <button class="vanpos-lightbox-btn vanpos-lightbox-next"><span class="material-icons">chevron_right</span></button>
                <div class="vanpos-lightbox-counter">${current + 1} / ${images.length}</div>
            ` : ''}
        `;

        const img = el.querySelector('.vanpos-lightbox-img');
        const counter = el.querySelector('.vanpos-lightbox-counter');

        function goTo(index) {
            current = (index + images.length) % images.length;
            img.src = images[current];
            if (counter) counter.textContent = (current + 1) + ' / ' + images.length;
        }

        // Close on background click or close button
        el.addEventListener('click', function(e) {
            if (e.target === el) el.remove();
        });
        el.querySelector('.vanpos-lightbox-close').onclick = () => el.remove();

        if (multi) {
            el.querySelector('.vanpos-lightbox-prev').onclick = () => goTo(current - 1);
            el.querySelector('.vanpos-lightbox-next').onclick = () => goTo(current + 1);
        }

        // Keyboard navigation
        function onKey(e) {
            if (e.key === 'Escape') { el.remove(); document.removeEventListener('keydown', onKey); }
            if (multi && e.key === 'ArrowLeft') goTo(current - 1);
            if (multi && e.key === 'ArrowRight') goTo(current + 1);
        }
        document.addEventListener('keydown', onKey);

        // Clean up keyboard listener when lightbox is removed
        const observer = new MutationObserver(() => {
            if (!document.body.contains(el)) {
                document.removeEventListener('keydown', onKey);
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true });

        document.body.appendChild(el);
    }

    // vanposFormatPrice is defined above and exposed on window

    // Initialize on DOM ready
    $(document).ready(function() {
        vanposInit();
    });

    /**
     * Render campers list (placeholder - implemented in calendar.js)
     */
    function vanposRenderCampers() {
        // This will be implemented in calendar.js
        if (window.vanposRenderCampersImpl) {
            window.vanposRenderCampersImpl();
        }
    }

    /**
     * Render calendar (placeholder - implemented in calendar.js)
     */
    function vanposRenderCalendar() {
        // This will be implemented in calendar.js
        if (window.vanposRenderCalendarImpl) {
            window.vanposRenderCalendarImpl();
        }
    }

    /**
     * Initialize calendar (placeholder - implemented in calendar.js)
     */
    function vanposInitCalendar() {
        // This will be implemented in calendar.js
        if (window.vanposInitCalendarImpl) {
            window.vanposInitCalendarImpl();
        }
    }

    /**
     * Initialize filters (placeholder - implemented in filters.js)
     */
    function vanposInitFilters() {
        // This will be implemented in filters.js
        if (window.vanposInitFiltersImpl) {
            window.vanposInitFiltersImpl();
        }
    }

    /**
     * Check availability for all vans when dates are selected.
     *
     * This function is fully self-contained: it renders the UI in every
     * code path (no-dates, cache-hit, AJAX). Callers should NOT call
     * vanposRenderCalendar / vanposRenderCampers after calling this.
     *
     * While an AJAX request is in flight, vanposAvailabilityLoading is
     * true so the calendar renderer skips availability colouring (shows
     * neutral pickup-return days). This prevents a "flash" of stale
     * fallback data before the server responds.
     */
    let vanposAvailabilityLoading = false;
    window.vanposAvailabilityLoading = function() { return vanposAvailabilityLoading; };

    function vanposCheckAvailabilityForAllVans() {
        const pickupDate = window.vanposPickupDate();
        const returnDate = window.vanposReturnDate();
        const selectedVanId = window.vanposSelectedVanId();

        if (!pickupDate || !returnDate) {
            // Clear availability cache in-place (preserve window reference)
            Object.keys(vanposAvailabilityCache).forEach(function(k) { delete vanposAvailabilityCache[k]; });
            vanposAvailabilityLoading = false;
            window.vanposRenderCampers();
            window.vanposRenderCalendar();
            return;
        }

        const pickupDateStr = formatDate(pickupDate);
        const returnDateStr = formatDate(returnDate);
        const cacheKey = `${pickupDateStr}_${returnDateStr}`;

        const campers = window.vanposCampers();
        let productIds = campers.map(c => c.id);

        // If a van is selected, only check that van
        if (selectedVanId) {
            productIds = [selectedVanId];
        }

        // Check cache — only short-circuit when ALL requested vans are present.
        // A partial entry (e.g. from a single-van check) must not prevent a
        // full batch fetch when switching to another van or back to overview.
        if (vanposAvailabilityCache[cacheKey]) {
            const cached = vanposAvailabilityCache[cacheKey];
            const allCached = productIds.every(function(id) { return cached[id] !== undefined; });
            if (allCached) {
                vanposAvailabilityLoading = false;
                window.vanposRenderCampers();
                window.vanposRenderCalendar();
                return;
            }
        }

        if (productIds.length === 0) {
            return;
        }

        // Show loading state — calendar will suppress availability colours
        vanposAvailabilityLoading = true;
        const campersList = document.getElementById('campersList');
        if (campersList) {
            campersList.classList.add('loading');
        }
        window.vanposRenderCampers();
        window.vanposRenderCalendar();

        $.ajax({
            url: vanposData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'vanpos_check_multiple_availability',
                nonce: vanposData.nonce,
                pickup_date: pickupDateStr,
                return_date: returnDateStr,
                product_ids: productIds
            },
            success: function(response) {
                if (response.success && response.data && response.data.availability) {
                    // Store in cache
                    if (!vanposAvailabilityCache[cacheKey]) {
                        vanposAvailabilityCache[cacheKey] = {};
                    }
                    // Merge with existing cache
                    Object.assign(vanposAvailabilityCache[cacheKey], response.data.availability);
                    
                    // Update campers with availability
                    campers.forEach(camper => {
                        if (response.data.availability[camper.id]) {
                            camper.availability = response.data.availability[camper.id];
                        }
                    });
                }
                
                vanposAvailabilityLoading = false;
                if (campersList) {
                    campersList.classList.remove('loading');
                }
                window.vanposRenderCampers();
                window.vanposRenderCalendar();
            },
            error: function() {
                vanposAvailabilityLoading = false;
                if (campersList) {
                    campersList.classList.remove('loading');
                }
                window.vanposRenderCampers();
                window.vanposRenderCalendar();
                console.error('Failed to check availability');
            }
        });
    }

    /**
     * Format date to Y-m-d
     */
    function formatDate(date) {
        if (!date) return '';
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    // Expose render functions for calendar.js and filters.js
    window.vanposRenderCampers = vanposRenderCampers;
    window.vanposRenderCalendar = vanposRenderCalendar;
    window.vanposCampers = function() { return vanposCampers; };
    window.vanposSelectedVanId = function() { return vanposSelectedVanId; };
    window.vanposPickupDate = function() { return vanposPickupDate; };
    window.vanposReturnDate = function() { return vanposReturnDate; };
    window.vanposPickupTimeSlot = function() { return vanposPickupTimeSlot; };
    window.vanposReturnTimeSlot = function() { return vanposReturnTimeSlot; };
    window.vanposSetPickupDate = function(date) { 
        vanposPickupDate = date; 
        vanposCheckAvailabilityForAllVans();
    };
    window.vanposSetReturnDate = function(date) { 
        vanposReturnDate = date; 
        vanposCheckAvailabilityForAllVans();
    };
    // Silent setters: update state without triggering AJAX.
    // Used by selectDate() which batches multiple state changes
    // and calls vanposCheckAvailabilityForAllVans() once at the end.
    window.vanposSetPickupDateSilent = function(date) { vanposPickupDate = date; };
    window.vanposSetReturnDateSilent = function(date) { vanposReturnDate = date; };
    window.vanposSetPickupTimeSlot = function(slot) { vanposPickupTimeSlot = slot; };
    window.vanposSetReturnTimeSlot = function(slot) { vanposReturnTimeSlot = slot; };
    window.vanposCurrentDate = function() { return vanposCurrentDate; };
    window.vanposSetCurrentDate = function(date) { vanposCurrentDate = date; };
    window.vanposFixedDurationDays = function() {
        if (vanposDurationMode === 'manual') return null;
        const n = parseInt(vanposDurationMode, 10);
        return (n === 8 || n === 15 || n === 22) ? n : null;
    };
    window.vanposCheckAvailabilityForAllVans = vanposCheckAvailabilityForAllVans;
    window.vanposOpenLightbox = vanposOpenLightbox;
    window.vanposClearDates = vanposClearDates;
    window.vanposFullReset = vanposFullReset;
    window.vanposFormatDate = formatDate;

})(jQuery);
