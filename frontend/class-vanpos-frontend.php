<?php
/**
 * Frontend functionality for VAN-Jorn Rental POS
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend class
 */
class VanPOS_Frontend {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_shortcode( 'vanjorn_rental_pos', array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_vanpos_get_products', array( $this, 'ajax_get_products' ) );
		add_action( 'wp_ajax_nopriv_vanpos_get_products', array( $this, 'ajax_get_products' ) );
		add_action( 'wp_ajax_vanpos_check_availability', array( $this, 'ajax_check_availability' ) );
		add_action( 'wp_ajax_nopriv_vanpos_check_availability', array( $this, 'ajax_check_availability' ) );
		add_action( 'wp_ajax_vanpos_check_multiple_availability', array( $this, 'ajax_check_multiple_availability' ) );
		add_action( 'wp_ajax_nopriv_vanpos_check_multiple_availability', array( $this, 'ajax_check_multiple_availability' ) );
		add_action( 'wp_ajax_vanpos_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_vanpos_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
	}

	/**
	 * Enqueue frontend scripts and styles
	 */
	public function enqueue_scripts() {
		// Only load on pages with the shortcode, or on product pages where
		// class-vanpos-product-page.php renders the shortcode via do_shortcode()
		// (it sets $GLOBALS['vanpos_force_enqueue'] before wp_enqueue_scripts fires).
		global $post;
		if ( ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'vanjorn_rental_pos' ) ) || ! empty( $GLOBALS['vanpos_force_enqueue'] ) ) {
			wp_enqueue_style( 'vanpos-main', VANPOS_PLUGIN_URL . 'frontend/css/main.css', array(), VANPOS_VERSION );
			wp_enqueue_style( 'vanpos-filters', VANPOS_PLUGIN_URL . 'frontend/css/filters.css', array(), VANPOS_VERSION );
			wp_enqueue_style( 'vanpos-calendar', VANPOS_PLUGIN_URL . 'frontend/css/calendar.css', array(), VANPOS_VERSION );

			// SweetAlert2 for professional alerts (https://sweetalert2.github.io/)
			wp_enqueue_style(
				'sweetalert2',
				'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css',
				array(),
				'11'
			);
			wp_enqueue_script(
				'sweetalert2',
				'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js',
				array(),
				'11',
				true
			);

			wp_enqueue_script( 'vanpos-app', VANPOS_PLUGIN_URL . 'frontend/js/app.js', array( 'jquery' ), VANPOS_VERSION, true );
			wp_enqueue_script( 'vanpos-filters', VANPOS_PLUGIN_URL . 'frontend/js/filters.js', array( 'jquery', 'vanpos-app' ), VANPOS_VERSION, true );
			wp_enqueue_script( 'vanpos-calendar', VANPOS_PLUGIN_URL . 'frontend/js/calendar.js', array( 'jquery', 'sweetalert2', 'vanpos-app', 'vanpos-filters' ), VANPOS_VERSION, true );

			// Single-van mode: action bar module. Self-gates via DOM check for
			// data-single-van-id — in fleet mode the IIFE returns immediately.
			wp_enqueue_script( 'vanpos-single-van', VANPOS_PLUGIN_URL . 'frontend/js/single-van.js', array( 'vanpos-calendar' ), VANPOS_VERSION, true );

			// Get currency symbol
			$currency = '€';
			if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
				$currency = get_woocommerce_currency_symbol();
			}

			// Get additional options settings
			$dog_enabled = VanPOS_Functions::get_setting( 'vanpos_dog_enabled', 'yes' ) === 'yes';
			$dog_price = (float) VanPOS_Functions::get_setting( 'vanpos_dog_price', 100 );
			$cleaning_enabled = VanPOS_Functions::get_setting( 'vanpos_cleaning_enabled', 'yes' ) === 'yes';
			$cleaning_price = (float) VanPOS_Functions::get_setting( 'vanpos_cleaning_price', 100 );
			
			// Get security deposit amount
			$security_deposit_product_id = VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' );
			$security_deposit_amount = 0;
			if ( ! empty( $security_deposit_product_id ) ) {
				$security_deposit_product = wc_get_product( $security_deposit_product_id );
				if ( $security_deposit_product ) {
					$security_deposit_amount = (float) $security_deposit_product->get_price();
				}
			}

			// Get deposit settings
			$deposit_percentage = (float) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 );
			$deposit_days       = (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_days_before_pickup', 14 );
			$deposit_enabled    = VanPOS_Functions::get_setting( 'vanpos_deposit_enabled', 'yes' ) === 'yes';
			$pickup_time        = VanPOS_Functions::get_setting( 'vanpos_pickup_time', '15:00' );
			$return_time        = VanPOS_Functions::get_setting( 'vanpos_return_time', '11:00' );
			$remaining_pct      = 100 - $deposit_percentage;

			// Localize script with data
			wp_localize_script(
				'vanpos-app',
				'vanposData',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'nonce'          => wp_create_nonce( 'vanpos_nonce' ),
					'pickupDays'     => VanPOS_Functions::get_pickup_days(),
					'minRentalDays'   => VanPOS_Functions::get_min_rental_days(),
					'maxRentalDays'   => VanPOS_Functions::get_max_rental_days(),
					'cartUrl'        => wc_get_checkout_url(),
					'currency'       => $currency,
					'currencyPos'    => get_option( 'woocommerce_currency_pos', 'left' ),
					'decimalSep'     => wc_get_price_decimal_separator(),
					'thousandSep'    => wc_get_price_thousand_separator(),
					'numDecimals'    => wc_get_price_decimals(),
					'vanTypes'       => VanPOS_Functions::get_van_types(),
					'equipmentOptions' => VanPOS_Functions::get_equipment_options(),
					'monthNames'       => array(
						__( 'January', 'vanjorn-rental-pos' ),
						__( 'February', 'vanjorn-rental-pos' ),
						__( 'March', 'vanjorn-rental-pos' ),
						__( 'April', 'vanjorn-rental-pos' ),
						__( 'May', 'vanjorn-rental-pos' ),
						__( 'June', 'vanjorn-rental-pos' ),
						__( 'July', 'vanjorn-rental-pos' ),
						__( 'August', 'vanjorn-rental-pos' ),
						__( 'September', 'vanjorn-rental-pos' ),
						__( 'October', 'vanjorn-rental-pos' ),
						__( 'November', 'vanjorn-rental-pos' ),
						__( 'December', 'vanjorn-rental-pos' ),
					),
					'dogEnabled'      => $dog_enabled,
					'dogPrice'        => $dog_price,
					'cleaningEnabled' => $cleaning_enabled,
					'cleaningPrice'   => $cleaning_price,
					'securityDepositAmount' => $security_deposit_amount,
					'pickupTime'       => $pickup_time,
					'returnTime'       => $return_time,
					'depositEnabled'   => $deposit_enabled,
					'depositPercentage' => $deposit_percentage,
					'securityDepositDaysBeforePickup' => $deposit_days,
					'pricesIncludeTax' => get_option( 'woocommerce_prices_include_tax', 'no' ) === 'yes',
					'vatRate'          => (float) apply_filters( 'vanpos_vat_rate', 0.21 ),
					'dateLocale'     => str_replace( '_', '-', get_locale() ),
					'strings'        => array(
						// Van selection
						'selectVan'      => __( 'Select this van', 'vanjorn-rental-pos' ),
						'clearSelection' => __( 'Clear selection', 'vanjorn-rental-pos' ),
						'bookNow'        => __( 'Book Now', 'vanjorn-rental-pos' ),
						'addToCart'      => __( 'Add to Cart', 'vanjorn-rental-pos' ),
						'details'        => __( 'Details', 'vanjorn-rental-pos' ),

						// Availability
						'available'      => __( 'Available', 'vanjorn-rental-pos' ),
						'notAvailable'   => __( 'Not available', 'vanjorn-rental-pos' ),
						'notAvailableBadge' => __( 'Not Available', 'vanjorn-rental-pos' ),
						'noVansAvailableForDate' => __( 'No vans available', 'vanjorn-rental-pos' ),
						/* translators: %d is the number of vans available on a date */
						'vansAvailableCount_one'   => __( '%d van available', 'vanjorn-rental-pos' ),
						/* translators: %d is the number of vans available on a date */
						'vansAvailableCount_other' => __( '%d vans available', 'vanjorn-rental-pos' ),

						// Loading / errors
						'loadingVans'    => __( 'Loading vans...', 'vanjorn-rental-pos' ),
						'noVansAvailable' => __( 'No rental vans available. Please add WooCommerce products with ACF fields configured (van_type, number_seating_options, number_sleeping_options, additional_equipment).', 'vanjorn-rental-pos' ),
						'errorLoadingProducts' => __( 'Error loading products.', 'vanjorn-rental-pos' ),
						'errorLoadingVans' => __( 'Error loading vans.', 'vanjorn-rental-pos' ),
						'securityCheckFailed' => __( 'Security check failed. Please refresh the page.', 'vanjorn-rental-pos' ),
						'serverError'    => __( 'Server error. Please check if WooCommerce and ACF are active.', 'vanjorn-rental-pos' ),
						'checkConsole'   => __( 'Please check console for details and refresh the page.', 'vanjorn-rental-pos' ),
						'error'          => __( 'Error', 'vanjorn-rental-pos' ),
						'errorGeneric'   => __( 'An error occurred. Please try again.', 'vanjorn-rental-pos' ),
						'checking'       => __( 'Checking...', 'vanjorn-rental-pos' ),

						// Calendar tooltips
						'notAvailableAdvanceNotice' => __( 'Not available - requires 3 days advance notice', 'vanjorn-rental-pos' ),
						'pickupLabel'    => __( 'Pickup', 'vanjorn-rental-pos' ),
						'returnLabel'    => __( 'Return', 'vanjorn-rental-pos' ),
						'selectTime'     => __( 'select time', 'vanjorn-rental-pos' ),
						/* translators: %s is the time slot (e.g. Morning, Afternoon, or "select time") */
						'pickupTooltip'  => __( 'Pickup: %s', 'vanjorn-rental-pos' ),
						/* translators: %s is the time slot (e.g. Morning, Afternoon, or "select time") */
						'returnTooltip'  => __( 'Return: %s', 'vanjorn-rental-pos' ),
						'weekendIncluded' => __( 'Weekend (included)', 'vanjorn-rental-pos' ),
						'includedInRental' => __( 'Included in rental period', 'vanjorn-rental-pos' ),
						'clickToSelect' => __( 'Click to select', 'vanjorn-rental-pos' ),
						'weekend'        => __( 'Weekend', 'vanjorn-rental-pos' ),

						// Dates / duration
						'datesRequired'  => __( 'Dates required', 'vanjorn-rental-pos' ),
						'datesRequiredMsg' => __( 'Please select both pickup and return dates.', 'vanjorn-rental-pos' ),
						'timeSlotsRequired' => __( 'Time slots required', 'vanjorn-rental-pos' ),
						'timeSlotsRequiredMsg' => __( 'Please select pickup and return time slots.', 'vanjorn-rental-pos' ),
						'invalidDuration' => __( 'Invalid duration', 'vanjorn-rental-pos' ),
						/* translators: %1$d is the minimum rental days, %2$d is the maximum rental days */
						'invalidDurationMsg' => __( 'Rental period must be between %1$d and %2$d days.', 'vanjorn-rental-pos' ),
						'invalidReturnDate' => __( 'Invalid return date', 'vanjorn-rental-pos' ),
						/* translators: %d is the number of days for the fixed-duration rental */
						'invalidReturnDateMsg' => __( 'For a %d-day rental, the return would fall on a day that is not Thursday or Friday. Pickup and return must be on Thursday or Friday. Please choose another start date.', 'vanjorn-rental-pos' ),

						// Plural-aware count strings (singular / plural)
						/* translators: %d is the number of days */
						'daysCount_one'   => __( '%d day', 'vanjorn-rental-pos' ),
						/* translators: %d is the number of days */
						'daysCount_other' => __( '%d days', 'vanjorn-rental-pos' ),
						/* translators: %d is the number of seats */
						'seatsCount_one'  => __( '%d seat', 'vanjorn-rental-pos' ),
						/* translators: %d is the number of seats */
						'seatsCount_other' => __( '%d seats', 'vanjorn-rental-pos' ),
						/* translators: %d is the number of beds */
						'bedsCount_one'   => __( '%d bed', 'vanjorn-rental-pos' ),
						/* translators: %d is the number of beds */
						'bedsCount_other' => __( '%d beds', 'vanjorn-rental-pos' ),

						// Price labels
						'perDay'         => __( '/day', 'vanjorn-rental-pos' ),
						'perNight'       => __( '/night', 'vanjorn-rental-pos' ),
						'total'          => __( 'total', 'vanjorn-rental-pos' ),

						// Cart summary modal
						'bookingSummary' => __( 'Booking Summary', 'vanjorn-rental-pos' ),
						'duration'       => __( 'Duration', 'vanjorn-rental-pos' ),
						'priceBreakdown' => __( 'Price Breakdown', 'vanjorn-rental-pos' ),
						'bookingCompleteTotal' => __( 'Booking Complete Total', 'vanjorn-rental-pos' ),
						/* translators: %d is the number of rental days */
						'rentalDays'     => __( 'Rental (%d days)', 'vanjorn-rental-pos' ),
						/* translators: %d is the number of rental nights */
						'rentalNights'   => __( 'Rental (%d nights)', 'vanjorn-rental-pos' ),
						'bookingTotal'   => __( 'Booking Total', 'vanjorn-rental-pos' ),
						'securityDeposit' => __( 'Security Deposit', 'vanjorn-rental-pos' ),
						'securityDepositNote' => sprintf(
							/* translators: %d is the number of days before pickup */
							__( 'This will be charged %d days before pickup and refunded after return.', 'vanjorn-rental-pos' ),
							$deposit_days
						),
						'securityDepositNoteNear' => __( 'You will receive a separate email shortly with the payment link for the deposit. The deposit will be refunded after return.', 'vanjorn-rental-pos' ),
						'extraCosts'     => __( 'Extra Costs', 'vanjorn-rental-pos' ),
						'bringDog'        => __( 'Bring your dog', 'vanjorn-rental-pos' ),
						'cleaningService' => __( 'Use our cleaning service', 'vanjorn-rental-pos' ),
						'paymentPlan'    => __( 'Payment Plan:', 'vanjorn-rental-pos' ),
						'paymentPlanDesc' => sprintf(
							/* translators: %1$d is days threshold, %2$g is deposit percentage, %3$g is remaining percentage */
							__( 'Since your pickup is more than %1$d days away, you can pay %2$g%% now and %3$g%% later.', 'vanjorn-rental-pos' ),
							$deposit_days,
							$deposit_percentage,
							$remaining_pct
						),
						'fullPayment'    => __( 'Full Payment:', 'vanjorn-rental-pos' ),
						'fullPaymentDesc' => sprintf(
							/* translators: %d is the days threshold */
							__( 'Since your pickup is within %d days, full payment is required now.', 'vanjorn-rental-pos' ),
							$deposit_days
						),
						'payNowLabel'    => sprintf(
							/* translators: %g is the deposit percentage */
							__( 'Pay Now (%g%% deposit + extra costs)', 'vanjorn-rental-pos' ),
							$deposit_percentage
						),
						'payLaterLabel'  => sprintf(
							/* translators: %g is the remaining percentage */
							__( 'Pay Later (remaining %g%%)', 'vanjorn-rental-pos' ),
							$remaining_pct
						),
						'totalToPayNow'  => __( 'Total to Pay Now', 'vanjorn-rental-pos' ),
						/* translators: %s is the formatted VAT amount with currency */
						'includingVat'   => __( 'Including %s VAT', 'vanjorn-rental-pos' ),
						'cancel'         => __( 'Cancel', 'vanjorn-rental-pos' ),

						// Add to cart states
						'addingToCart'    => __( 'Adding to cart...', 'vanjorn-rental-pos' ),
						'addedToCart'     => __( 'Added to cart', 'vanjorn-rental-pos' ),
						'addedToCartMsg'  => __( 'Product added to cart successfully!', 'vanjorn-rental-pos' ),
						'couldNotAddToCart' => __( 'Could not add to cart', 'vanjorn-rental-pos' ),
						'failedToAddToCart' => __( 'Failed to add to cart.', 'vanjorn-rental-pos' ),
						'notAvailableMsg' => __( 'Selected dates are not available. Please choose different dates.', 'vanjorn-rental-pos' ),

						// Filter states
						'noVansMatch'     => __( 'No vans available', 'vanjorn-rental-pos' ),
						'noVansMatchDesc'  => __( 'Adjust travelers or filters to see available vans. Date selection is disabled until you find a matching van.', 'vanjorn-rental-pos' ),
						'noVansMatchFilters' => __( 'No vans match the filters', 'vanjorn-rental-pos' ),
						'noVanTypes'     => __( 'No van types available', 'vanjorn-rental-pos' ),
						'noEquipment'    => __( 'No equipment available', 'vanjorn-rental-pos' ),

						// Van details
						'notApplicable'  => __( 'N/A', 'vanjorn-rental-pos' ),
						'moreInfo'       => __( 'To van page', 'vanjorn-rental-pos' ),

						// Single-van action bar
						'selectDatesHint'  => __( 'Select your pickup and return dates on the calendar.', 'vanjorn-rental-pos' ),
						'selectReturnHint' => __( 'Now select your return date.', 'vanjorn-rental-pos' ),
					),
				)
			);
		}
	}

	/**
	 * Render shortcode
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'category'   => '',
				'product_id' => 0,
			),
			$atts,
			'vanjorn_rental_pos'
		);

		// Single-van mode: when a product_id is provided, the calendar shows
		// only that van — no filters sidebar, no van list header.
		// The variable is read by booking-interface.php (it's in scope via include).
		$vanpos_single_van_id = absint( $atts['product_id'] );

		ob_start();
		include VANPOS_PLUGIN_DIR . 'frontend/views/booking-interface.php';
		return ob_get_clean();
	}

	/**
	 * AJAX: Get products
	 */
	public function ajax_get_products() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'vanpos_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Check if WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce is required but not active.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		try {
			$products = VanPOS_Functions::get_rental_products();

			// Format products for frontend
			$formatted_products = array();
			foreach ( $products as $product ) {
				try {
					$calendar_avail = class_exists( '\Kestrel\Rental_Products\Plugin' )
						? VanPOS_Functions::get_rental_calendar_availability( $product['id'] )
						: array(
							'unavailablePickupDates' => VanPOS_Functions::get_unavailable_dates( $product['id'] ),
							'unavailableReturnDates'   => array(),
							'unavailableFullDates'     => array(),
						);
					$pickup_blocked = isset( $calendar_avail['unavailablePickupDates'] ) && is_array( $calendar_avail['unavailablePickupDates'] )
						? $calendar_avail['unavailablePickupDates'] : array();
					$return_blocked = isset( $calendar_avail['unavailableReturnDates'] ) && is_array( $calendar_avail['unavailableReturnDates'] )
						? $calendar_avail['unavailableReturnDates'] : array();
					$full_blocked   = isset( $calendar_avail['unavailableFullDates'] ) && is_array( $calendar_avail['unavailableFullDates'] )
						? $calendar_avail['unavailableFullDates'] : array();
					$formatted_products[] = array(
						'id'               => (int) $product['id'],
						'name'             => sanitize_text_field( $product['name'] ),
						'type'             => sanitize_text_field( $product['type'] ),
						'price'            => (float) $product['price'],
						'seats'            => (int) $product['seats'],
						'beds'             => (int) $product['beds'],
						'length'           => sanitize_text_field( $product['length'] ),
						'transmission'     => sanitize_text_field( $product['transmission'] ),
						'fuel'             => sanitize_text_field( $product['fuel'] ),
						'equipment'        => is_array( $product['equipment'] ) ? array_map( 'sanitize_text_field', $product['equipment'] ) : array(),
						'image'            => esc_url_raw( $product['image'] ),
						'gallery'          => array_map( function( $img ) {
							return array(
								'thumb' => esc_url_raw( isset( $img['thumb'] ) ? $img['thumb'] : '' ),
								'full'  => esc_url_raw( isset( $img['full'] ) ? $img['full'] : '' ),
							);
						}, is_array( $product['gallery'] ) ? $product['gallery'] : array() ),
						'excerpt'          => wp_kses_post( $product['excerpt'] ),
						'permalink'        => esc_url_raw( $product['permalink'] ),
						// CMIT CODE - UPDATED - 15 MAY 2026 — same-day turnaround: return AM, pickup PM.
						'unavailableDates'       => array_map( 'sanitize_text_field', $pickup_blocked ),
						'unavailablePickupDates' => array_map( 'sanitize_text_field', $pickup_blocked ),
						'unavailableReturnDates' => array_map( 'sanitize_text_field', $return_blocked ),
						'unavailableFullDates'   => array_map( 'sanitize_text_field', $full_blocked ),
					);
				} catch ( Exception $e ) {
					// Skip this product if there's an error
					continue;
				}
			}

			wp_send_json_success( $formatted_products );
		} catch ( Exception $e ) {
			// Log error for debugging
			error_log( 'VAN-Jorn Rental POS AJAX Error: ' . $e->getMessage() );
			wp_send_json_error( array( 
				'message' => __( 'Error loading products. Please check if WooCommerce is active and products exist.', 'vanjorn-rental-pos' ),
				'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? $e->getMessage() : ''
			) );
		} catch ( Error $e ) {
			// Catch fatal errors
			error_log( 'VAN-Jorn Rental POS Fatal Error: ' . $e->getMessage() );
			wp_send_json_error( array( 
				'message' => __( 'Fatal error loading products. Please check plugin configuration.', 'vanjorn-rental-pos' ),
				'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? $e->getMessage() : ''
			) );
		}
	}

	/**
	 * AJAX: Check availability
	 */
	public function ajax_check_availability() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'vanpos_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$pickup_date    = isset( $_POST['pickup_date'] ) ? sanitize_text_field( $_POST['pickup_date'] ) : '';
		$return_date    = isset( $_POST['return_date'] ) ? sanitize_text_field( $_POST['return_date'] ) : '';

		if ( ! $product_id || ! $pickup_date || ! $return_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Check availability using rental products plugin
		$availability = VanPOS_Functions::check_rental_availability( $product_id, $pickup_date, $return_date, 1 );
		$available = ( $availability === 'available' );

		if ( $available ) {
			wp_send_json_success( array(
				'available' => true,
				'message'   => __( 'Selected dates are available.', 'vanjorn-rental-pos' ),
			) );
		} else {
			wp_send_json_error( array(
				'available' => false,
				'message'   => __( 'Selected dates are not available. Please choose different dates.', 'vanjorn-rental-pos' ),
			) );
		}
	}

	/**
	 * AJAX: Check availability for multiple products
	 */
	public function ajax_check_multiple_availability() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'vanpos_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		$pickup_date = isset( $_POST['pickup_date'] ) ? sanitize_text_field( $_POST['pickup_date'] ) : '';
		$return_date = isset( $_POST['return_date'] ) ? sanitize_text_field( $_POST['return_date'] ) : '';
		$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();

		if ( ! $pickup_date || ! $return_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required dates.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// If no product IDs provided, get all rental products
		if ( empty( $product_ids ) ) {
			$products = VanPOS_Functions::get_rental_products();
			$product_ids = array_map( function( $product ) {
				return $product['id'];
			}, $products );
		}

		$availability_results = array();

		foreach ( $product_ids as $product_id ) {
			$availability = VanPOS_Functions::check_rental_availability( $product_id, $pickup_date, $return_date, 1 );
			$availability_results[ $product_id ] = array(
				'available' => ( $availability === 'available' ),
				'status'    => $availability,
			);
		}

		wp_send_json_success( array(
			'availability' => $availability_results,
		) );
	}

	/**
	 * Normalize legacy time slot labels ("morning"/"afternoon") to configured admin times.
	 *
	 * The frontend calendar uses "afternoon"/"morning" internally for CSS class
	 * purposes. This method translates those labels to the actual configured
	 * time values before they are stored as order meta.
	 *
	 * @param string $value     Raw time value from the frontend.
	 * @param string $setting   Settings key (e.g. 'vanpos_pickup_time').
	 * @param string $default   Fallback time if the setting is not configured.
	 * @return string Normalized time value (e.g. '15:00').
	 */
	private function normalize_time_slot( $value, $setting, $default ) {
		$v = strtolower( trim( (string) $value ) );
		if ( in_array( $v, array( 'morning', 'afternoon' ), true ) ) {
			return (string) VanPOS_Functions::get_setting( $setting, $default );
		}
		return $value;
	}

	/**
	 * AJAX: Add to cart
	 */
	public function ajax_add_to_cart() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'vanpos_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		$product_id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$pickup_date    = isset( $_POST['pickup_date'] ) ? sanitize_text_field( $_POST['pickup_date'] ) : '';
		$return_date    = isset( $_POST['return_date'] ) ? sanitize_text_field( $_POST['return_date'] ) : '';
		$pickup_time    = isset( $_POST['pickup_time'] ) ? sanitize_text_field( $_POST['pickup_time'] ) : '';
		$return_time    = isset( $_POST['return_time'] ) ? sanitize_text_field( $_POST['return_time'] ) : '';
		$include_dog    = isset( $_POST['include_dog'] ) && $_POST['include_dog'] === '1';
		$include_cleaning = isset( $_POST['include_cleaning'] ) && $_POST['include_cleaning'] === '1';

		// Normalize legacy time slot labels to configured admin times.
		$pickup_time = $this->normalize_time_slot( $pickup_time, 'vanpos_pickup_time', '15:00' );
		$return_time = $this->normalize_time_slot( $return_time, 'vanpos_return_time', '11:00' );

		if ( ! $product_id || ! $pickup_date || ! $return_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		$result = VanPOS_Functions::add_to_cart( $product_id, $pickup_date, $return_date, $pickup_time, $return_time, $include_dog, $include_cleaning );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Product added to cart successfully.', 'vanjorn-rental-pos' ),
			'cartUrl' => wc_get_checkout_url(),
		) );
	}
}

// Initialize frontend
if ( ! is_admin() ) {
	new VanPOS_Frontend();
}
