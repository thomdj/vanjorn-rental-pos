<?php
/**
 * Booking Interface Template
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php
// $vanpos_single_van_id is set by render_shortcode() — 0 means fleet mode (default).
$vanpos_single_van_id = isset( $vanpos_single_van_id ) ? absint( $vanpos_single_van_id ) : 0;
$vanpos_mode_class     = $vanpos_single_van_id ? ' vanpos-single-van-mode' : '';
?>

<div class="vanjorn-rental-pos<?php echo esc_attr( $vanpos_mode_class ); ?>"<?php if ( $vanpos_single_van_id ) echo ' data-single-van-id="' . esc_attr( $vanpos_single_van_id ) . '"'; ?>>

    <div class="container">
        <div class="main-content">
            <div class="content-area">
                <!-- Filters Section -->
                <aside class="filters-section">
                    <div class="filters-header">
                        <h3><?php esc_html_e( 'Filters', 'vanjorn-rental-pos' ); ?></h3>
                        <button id="clearFilters" class="clear-filters"><?php esc_html_e( 'Clear all', 'vanjorn-rental-pos' ); ?></button>
                    </div>

                    <div class="filters-scroll">

                        <!-- Travelers Filter -->
                        <div class="filter-group">
                            <h4><?php esc_html_e( 'Travelers', 'vanjorn-rental-pos' ); ?></h4>
                            <div class="filter-content">
                                <div class="counter">
                                    <label><?php esc_html_e( 'Adults', 'vanjorn-rental-pos' ); ?></label>
                                    <div class="counter-controls">
                                        <button class="counter-btn" data-action="minus" data-target="adults"><span class="material-icons">remove</span></button>
                                        <span id="adultsCount">2</span>
                                        <button class="counter-btn" data-action="plus" data-target="adults"><span class="material-icons">add</span></button>
                                    </div>
                                </div>
                                <div class="counter">
                                    <label><?php esc_html_e( 'Children', 'vanjorn-rental-pos' ); ?></label>
                                    <div class="counter-controls">
                                        <button class="counter-btn" data-action="minus" data-target="children"><span class="material-icons">remove</span></button>
                                        <span id="childrenCount">0</span>
                                        <button class="counter-btn" data-action="plus" data-target="children"><span class="material-icons">add</span></button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Van Type Filter -->
                        <div class="filter-group">
                            <h4><?php esc_html_e( 'Van type', 'vanjorn-rental-pos' ); ?></h4>
                            <div class="filter-content" id="vanTypeFilters">
                                <!-- Dynamically populated -->
                            </div>
                        </div>

                        <!-- Equipment Filter -->
                        <div class="filter-group collapsed">
                            <h4 class="collapsible"><?php esc_html_e( 'Equipment', 'vanjorn-rental-pos' ); ?> <span class="toggle"><span class="material-icons">expand_more</span></span></h4>
                            <div class="filter-content equipment-filter-content" id="equipmentFilters">
                                <!-- Dynamically populated -->
                            </div>
                        </div>
                        
                    </div>
                </aside>

                <!-- Calendar Section -->
                <div class="calendar-section">
                    <!-- Van Details View -->
                    <div id="vanDetailsView" class="van-details-view">
                        <div class="van-details-header">
                            <h2 id="vanDetailsName"></h2>
                            <button id="backToCalendar" class="back-to-calendar-btn">
                                <span class="material-icons">chevron_left</span> <?php esc_html_e( 'Back to Calendar', 'vanjorn-rental-pos' ); ?>
                            </button>
                        </div>
                        <div class="van-details-content">
                            <div id="vanHero" class="van-hero"></div>

                            <div id="vanExcerpt" class="van-excerpt"></div>
                            
                            <div class="van-info-grid">
                                <div class="van-info-card">
                                    <h4><?php esc_html_e( 'Type', 'vanjorn-rental-pos' ); ?></h4>
                                    <p id="vanType"></p>
                                </div>
                                <div class="van-info-card">
                                    <h4><?php esc_html_e( 'Price per day', 'vanjorn-rental-pos' ); ?></h4>
                                    <p id="vanPrice"></p>
                                </div>
                                <div class="van-info-card">
                                    <h4><?php esc_html_e( 'Seats', 'vanjorn-rental-pos' ); ?></h4>
                                    <p id="vanSeats"></p>
                                </div>
                                <div class="van-info-card">
                                    <h4><?php esc_html_e( 'Beds', 'vanjorn-rental-pos' ); ?></h4>
                                    <p id="vanBeds"></p>
                                </div>
                            </div>

                            <div class="van-section">
                                <h3><span class="material-icons">directions_car</span> <?php esc_html_e( 'Specifications', 'vanjorn-rental-pos' ); ?></h3>
                                <div class="specs-list">
                                    <div class="spec-item">
                                        <span class="spec-label"><?php esc_html_e( 'Length', 'vanjorn-rental-pos' ); ?></span>
                                        <span class="spec-value" id="vanLength"></span>
                                    </div>
                                    <div class="spec-item">
                                        <span class="spec-label"><?php esc_html_e( 'Transmission', 'vanjorn-rental-pos' ); ?></span>
                                        <span class="spec-value" id="vanTransmission"></span>
                                    </div>
                                    <div class="spec-item">
                                        <span class="spec-label"><?php esc_html_e( 'Fuel Type', 'vanjorn-rental-pos' ); ?></span>
                                        <span class="spec-value" id="vanFuel"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="van-section" id="vanEquipmentSection">
                                <h3><span class="material-icons">build</span> <?php esc_html_e( 'Equipment & Features', 'vanjorn-rental-pos' ); ?></h3>
                                <div class="equipment-grid" id="vanEquipment"></div>
                            </div>

                            <div class="van-details-footer">
                                <a id="moreInfoFromDetails" class="details-btn" href="#" target="_blank"><?php esc_html_e( 'To van page', 'vanjorn-rental-pos' ); ?></a>
                                <button id="selectVanFromDetails" class="select-van-btn"><?php esc_html_e( 'Select this van', 'vanjorn-rental-pos' ); ?></button>
                            </div>
                        </div>
                    </div>

                    <!-- Calendar View -->
                    <div id="calendarContent" class="calendar-content">
                        <!-- Duration toolbar: 4 buttons always visible -->
                        <div class="duration-toolbar">
                            <button type="button" class="duration-btn active" data-duration="manual" aria-pressed="true">
                                <?php
                                printf(
                                    /* translators: %1$d is minimum rental days, %2$d is maximum rental days */
                                    esc_html__( 'Manual (%1$d–%2$d days)', 'vanjorn-rental-pos' ),
                                    VanPOS_Functions::get_min_rental_days(),
                                    VanPOS_Functions::get_max_rental_days()
                                );
                                ?>
                            </button>
                            <button type="button" class="duration-btn" data-duration="8">
                                <?php esc_html_e( '1 week (8 days)', 'vanjorn-rental-pos' ); ?>
                            </button>
                            <button type="button" class="duration-btn" data-duration="15">
                                <?php esc_html_e( '2 weeks (15 days)', 'vanjorn-rental-pos' ); ?>
                            </button>
                            <button type="button" class="duration-btn" data-duration="22">
                                <?php esc_html_e( '3 weeks (22 days)', 'vanjorn-rental-pos' ); ?>
                            </button>
                            <button type="button" class="show-month-picker-btn" id="showMonthPicker" aria-label="<?php esc_attr_e( 'Select a month', 'vanjorn-rental-pos' ); ?>">
                                <?php esc_html_e( 'or select a month', 'vanjorn-rental-pos' ); ?>
                            </button>
                            <button type="button" class="clear-dates-btn" id="vanposClearDates" aria-label="<?php esc_attr_e( 'Clear selected dates', 'vanjorn-rental-pos' ); ?>">
                                <?php esc_html_e( 'Clear dates', 'vanjorn-rental-pos' ); ?>
                            </button>
                        </div>

                        <div id="overlay" class="overlay hidden">
                            <div class="overlay-content">
                                <h2><?php esc_html_e( 'Select Your Travel Month', 'vanjorn-rental-pos' ); ?></h2>
                                <p class="overlay-subtitle">
                                    <?php esc_html_e( 'Select a month to view availability', 'vanjorn-rental-pos' ); ?>
                                </p>

                                <div class="divider">
                                    <span><?php esc_html_e( 'Available Months', 'vanjorn-rental-pos' ); ?></span>
                                </div>

                                <div class="month-options">
                                    <?php
                                    // Button labels are set by JS (updateOverlayYearMonthButtons);
                                    // only the data-month attribute matters here.
                                    for ( $i = 0; $i < 12; $i++ ) :
                                        ?>
                                        <button class="month-option-btn" data-month="<?php echo esc_attr( $i ); ?>"></button>
                                        <?php
                                    endfor;
                                    ?>
                                </div>

                                <div class="year-nav pt-4">
                                    
                                    <button class="prev-year">
                                        <span class="material-icons" aria-hidden="true">chevron_left</span> <?php esc_html_e( 'Previous year', 'vanjorn-rental-pos' ); ?>
                                    </button>

                                    <button class="next-year">
                                        <?php esc_html_e( 'Next year', 'vanjorn-rental-pos' ); ?> <span class="material-icons" aria-hidden="true">chevron_right</span>
                                    </button>

                                </div>

                            </div>
                        </div>

                        <div class="calendar-header">
                            <h2 id="monthYear"></h2>
                            <div class="calendar-nav">
                                <button class="nav-btn" id="prevMonth"></button>
                                <button class="nav-btn" id="nextMonth"></button>
                            </div>
                        </div>

                        <div class="weekdays">
                            <div class="weekday"><?php esc_html_e( 'Mon', 'vanjorn-rental-pos' ); ?></div>
                            <div class="weekday"><?php esc_html_e( 'Tue', 'vanjorn-rental-pos' ); ?></div>
                            <div class="weekday"><?php esc_html_e( 'Wed', 'vanjorn-rental-pos' ); ?></div>
                            <div class="weekday"><?php esc_html_e( 'Thu', 'vanjorn-rental-pos' ); ?></div>
                            <div class="weekday"><?php esc_html_e( 'Fri', 'vanjorn-rental-pos' ); ?></div>
                            <div class="weekday"><?php esc_html_e( 'Sat', 'vanjorn-rental-pos' ); ?></div>
                            <div class="weekday"><?php esc_html_e( 'Sun', 'vanjorn-rental-pos' ); ?></div>
                        </div>

                        <div class="calendar-grid" id="calendarGrid"></div>

                        <div class="legend">
                            <div class="legend-item">
                                <div class="legend-color legend-color-available-high"></div>
                                <span><?php esc_html_e( 'High availability (3+)', 'vanjorn-rental-pos' ); ?></span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color legend-color-available-low"></div>
                                <span><?php esc_html_e( 'Limited availability (1-2)', 'vanjorn-rental-pos' ); ?></span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color legend-color-unavailable"></div>
                                <span><?php esc_html_e( 'Not available', 'vanjorn-rental-pos' ); ?></span>
                            </div>
                        </div>

                        <?php if ( $vanpos_single_van_id ) : ?>
                        <!-- Single-van action bar: date summary + book button -->
                        <div id="vanposSingleVanAction" class="vanpos-single-van-action" style="display:none;"></div>
                        <?php endif; ?>

                    </div>
                </div>
                <div class="campers-section">
                    <h2 class="campers-header" id="campersHeader"><?php esc_html_e( 'Our vans', 'vanjorn-rental-pos' ); ?></h2>
                    <div id="vanposNoVansWarning" class="vanpos-no-vans-warning" style="display: none;">
                        <p><?php esc_html_e( 'No vans match the current filters. Try adjusting your selection.', 'vanjorn-rental-pos' ); ?></p>
                    </div>
                    <div class="campers-list" id="campersList"></div>
                </div>
            </div>
        </div>
    </div>
</div>
