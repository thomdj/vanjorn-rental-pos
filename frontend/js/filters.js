/**
 * Filters JavaScript for VAN-Jorn Rental POS
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

(function($) {
    'use strict';

    const vanposFilters = {
        types: [],
        adults: 2,
        children: 0,
        equipment: []
    };

    /**
     * Initialize filters
     */
    function vanposInitFilters() {
        const clearFilters = document.getElementById('clearFilters');
        const counterBtns = document.querySelectorAll('.counter-btn');

        // Type filters (dynamically added)
        $(document).off('change', '.type-filter');
        $(document).on('change', '.type-filter', function() {
            const value = $(this).val();
            if ($(this).is(':checked')) {
                if (vanposFilters.types.indexOf(value) === -1) {
                    vanposFilters.types.push(value);
                }
            } else {
                vanposFilters.types = vanposFilters.types.filter(t => t !== value);
            }
            vanposApplyFilters();
            if (window.vanposRenderCalendar) window.vanposRenderCalendar();
        });

        // Equipment filters (dynamically added)
        $(document).off('change', '.equipment-filter');
        $(document).on('change', '.equipment-filter', function() {
            const value = $(this).val();
            if ($(this).is(':checked')) {
                if (vanposFilters.equipment.indexOf(value) === -1) {
                    vanposFilters.equipment.push(value);
                }
            } else {
                vanposFilters.equipment = vanposFilters.equipment.filter(eq => eq !== value);
            }
            vanposApplyFilters();
            if (window.vanposRenderCalendar) window.vanposRenderCalendar();
        });

        // Counter buttons - use event delegation for dynamically added elements
        $(document).off('click', '.counter-btn');
        $(document).on('click', '.counter-btn', function(e) {
            e.preventDefault();
            const action = $(this).data('action');
            const target = $(this).data('target');
            const countEl = document.getElementById(`${target}Count`);
            
            if (!countEl) return;
            
            let current = parseInt(countEl.textContent) || 0;
            const maxTravelers = 20; // Reasonable upper bound

            if (action === 'plus' && current < maxTravelers) {
                current++;
            } else if (action === 'minus' && current > 0) {
                current--;
            }

            countEl.textContent = current;
            vanposFilters[target] = current;
            vanposApplyFilters();
            if (window.vanposRenderCalendar) window.vanposRenderCalendar();
        });

        // Clear all - reset filters, dates, selected van, duration; full reset for fresh start
        if (clearFilters) {
            // Remove any existing click handlers to prevent duplicates
            $(clearFilters).off('click');
            $(clearFilters).on('click', function(e) {
                e.preventDefault();
                
                // Reset filter state
                vanposFilters.types = [];
                vanposFilters.adults = 2;
                vanposFilters.children = 0;
                vanposFilters.equipment = [];

                // Uncheck all filter checkboxes
                $('.type-filter').prop('checked', false);
                $('.equipment-filter').prop('checked', false);
                
                // Reset traveler counters
                const adultsCountEl = document.getElementById('adultsCount');
                const childrenCountEl = document.getElementById('childrenCount');
                if (adultsCountEl) {
                    adultsCountEl.textContent = '2';
                }
                if (childrenCountEl) {
                    childrenCountEl.textContent = '0';
                }

            // Full reset: dates, selected van, duration mode, view (back to calendar)
            if (typeof window.vanposFullReset === 'function') {
                window.vanposFullReset();
            }
            vanposApplyFilters();
            if (window.vanposRenderCalendar) window.vanposRenderCalendar();
        });
        }

        // Collapsible sections - use event delegation for dynamically added elements
        // Remove old event listeners first to avoid duplicates
        $(document).off('click', '.collapsible, .filter-group .toggle');
        $(document).on('click', '.collapsible, .filter-group .toggle', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const filterGroup = $(this).closest('.filter-group');
            if (filterGroup.length) {
                filterGroup.toggleClass('collapsed');
            }
        });
    }

    /**
     * Apply filters using data attributes (no AJAX needed)
     * Each filter works independently - all active filters must match (AND logic)
     */
    function vanposApplyFilters() {
        const camperCards = document.querySelectorAll('.camper-card');
        
        if (camperCards.length === 0) {
            return; // No cards to filter
        }
        
        camperCards.forEach(card => {
            let show = true;

            // Type filter - use data-type attribute
            // Only apply if at least one type is selected
            if (vanposFilters.types.length > 0) {
                const cardType = card.dataset.type || '';
                if (vanposFilters.types.indexOf(cardType) === -1) {
                    show = false;
                }
            }

            // Travelers filter - use data-seats attribute
            // Only apply if total persons > 0 (if 0, show all)
            const totalPersons = vanposFilters.adults + vanposFilters.children;
            if (totalPersons > 0) {
                const cardSeats = parseInt(card.dataset.seats || '0');
                if (totalPersons > cardSeats) {
                    show = false;
                }
            }

            // Equipment filter - use data-equipment attribute (from additional_equipment ACF field)
            // Only apply if at least one equipment is selected
            if (vanposFilters.equipment.length > 0) {
                // Decode HTML entities and split by comma
                let equipmentStr = card.dataset.equipment || '';
                // Decode HTML entities
                equipmentStr = equipmentStr.replace(/&#44;/g, ',').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
                const cardEquipment = equipmentStr.split(',').map(eq => eq.trim()).filter(eq => eq.length > 0);
                
                // Decode filter values for comparison
                const decodedFilters = vanposFilters.equipment.map(eq => 
                    String(eq).replace(/&#44;/g, ',').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
                );
                
                // Check if van has ALL selected equipment (all filters must match)
                const hasAllEquipment = decodedFilters.every(filterEq => {
                    return cardEquipment.some(cardEq => {
                        // Exact match or case-insensitive match
                        return cardEq === filterEq || cardEq.toLowerCase() === filterEq.toLowerCase();
                    });
                });
                if (!hasAllEquipment) {
                    show = false;
                }
            }

            // Apply the filter - show or hide the card
            if (show) {
                card.classList.remove('hidden');
            } else {
                card.classList.add('hidden');
            }
        });

        // Update count of visible vans
        const visibleCount = document.querySelectorAll('.camper-card:not(.hidden)').length;
        const totalCount = camperCards.length;

        // Show/hide "no vans" warning banner
        const warningEl = document.getElementById('vanposNoVansWarning');
        if (warningEl) {
            warningEl.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    /**
     * Check if any vans match the current filter criteria.
     * Uses data-based logic (works even when DOM cards are replaced).
     * @returns {boolean}
     */
    function vanposHasMatchingVans() {
        const campers = typeof window.vanposCampers === 'function' ? window.vanposCampers() : [];
        if (campers.length === 0) return false;

        const totalPersons = vanposFilters.adults + vanposFilters.children;
        const types = vanposFilters.types || [];
        const equipment = vanposFilters.equipment || [];

        return campers.some(camper => {
            if (totalPersons > 0) {
                const cardSeats = parseInt(camper.seats || 0, 10);
                if (totalPersons > cardSeats) return false;
            }
            if (types.length > 0) {
                const cardType = (camper.type || '').trim();
                if (types.indexOf(cardType) === -1) return false;
            }
            if (equipment.length > 0) {
                let cardEquipment = camper.equipment;
                if (typeof cardEquipment === 'string') {
                    cardEquipment = cardEquipment.replace(/&#44;/g, ',').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
                    cardEquipment = cardEquipment.split(',').map(eq => eq.trim()).filter(eq => eq.length > 0);
                } else if (Array.isArray(cardEquipment)) {
                    cardEquipment = cardEquipment.map(eq => String(eq).trim()).filter(eq => eq.length > 0);
                } else {
                    cardEquipment = [];
                }
                const decodedFilters = equipment.map(eq =>
                    String(eq).replace(/&#44;/g, ',').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
                );
                const hasAllEquipment = decodedFilters.every(filterEq =>
                    cardEquipment.some(cardEq =>
                        cardEq === filterEq || cardEq.toLowerCase() === filterEq.toLowerCase()
                    )
                );
                if (!hasAllEquipment) return false;
            }
            return true;
        });
    }

    // Expose functions
    window.vanposInitFiltersImpl = vanposInitFilters;
    window.vanposApplyFilters = vanposApplyFilters;
    window.vanposFilters = function() { return vanposFilters; };
    window.vanposHasMatchingVans = vanposHasMatchingVans;

    /**
     * Check if a single camper object matches the current filters.
     * Used by calendar.js getAvailableCampersForDate() so availability
     * dot colours respect active filters.
     */
    window.vanposCamperMatchesFilters = function(camper) {
        const totalPersons = vanposFilters.adults + vanposFilters.children;
        if (totalPersons > 0) {
            const seats = parseInt(camper.seats || 0, 10);
            if (totalPersons > seats) return false;
        }
        if (vanposFilters.types.length > 0) {
            const cardType = (camper.type || '').trim();
            if (vanposFilters.types.indexOf(cardType) === -1) return false;
        }
        if (vanposFilters.equipment.length > 0) {
            let cardEquipment = camper.equipment;
            if (typeof cardEquipment === 'string') {
                cardEquipment = cardEquipment.replace(/&#44;/g, ',').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
                cardEquipment = cardEquipment.split(',').map(eq => eq.trim()).filter(eq => eq.length > 0);
            } else if (Array.isArray(cardEquipment)) {
                cardEquipment = cardEquipment.map(eq => String(eq).trim()).filter(eq => eq.length > 0);
            } else {
                cardEquipment = [];
            }
            const decodedFilters = vanposFilters.equipment.map(eq =>
                String(eq).replace(/&#44;/g, ',').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
            );
            const hasAll = decodedFilters.every(filterEq =>
                cardEquipment.some(cardEq =>
                    cardEq === filterEq || cardEq.toLowerCase() === filterEq.toLowerCase()
                )
            );
            if (!hasAll) return false;
        }
        return true;
    };

})(jQuery);
