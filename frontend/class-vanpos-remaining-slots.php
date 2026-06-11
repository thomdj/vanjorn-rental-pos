<?php
/**
 * Remaining Slots Shortcode — VAN-Jorn Rental POS
 *
 * Renders available rental windows as clickable pills.
 * One pill per contiguous free period, grouped by van.
 *
 * Usage:
 *   [vanjorn_remaining_slots end_date="2026-08-31"]
 *   [vanjorn_remaining_slots end_date="2026-08-31" min_days="3" max_days="22"]
 *
 * Attributes:
 *   end_date  (string, Y-m-d)  – Last date included in the scan (required).
 *   min_days  (int)            – Minimum window length to display. Defaults to
 *                                the plugin's global min rental days setting.
 *   max_days  (int)            – Maximum window length (caps the right edge of
 *                                each pill). Defaults to global max rental days.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remaining Slots Shortcode handler.
 */
class VanPOS_Remaining_Slots {

	/**
	 * Boot hooks.
	 */
	public function __construct() {
		add_shortcode( 'vanjorn_remaining_slots', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_vanpos_rs_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_vanpos_rs_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueue CSS + JS only on pages that contain the shortcode.
	 */
	public function enqueue_scripts() {
		global $post;

		$load = ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'vanjorn_remaining_slots' ) )
		        || ! empty( $GLOBALS['vanpos_force_enqueue_rs'] );

		if ( ! $load ) {
			return;
		}

		wp_enqueue_style(
			'vanpos-remaining-slots',
			VANPOS_PLUGIN_URL . 'frontend/css/remaining-slots.css',
			array(),
			VANPOS_VERSION
		);

		wp_enqueue_script(
			'vanpos-remaining-slots',
			VANPOS_PLUGIN_URL . 'frontend/js/remaining-slots.js',
			array( 'jquery' ),
			VANPOS_VERSION,
			true
		);

		wp_localize_script(
			'vanpos-remaining-slots',
			'vanposRSData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vanpos_rs_nonce' ),
				'cartUrl' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ),
				'strings' => array(
					'booking'     => __( 'Booking…', 'vanjorn-rental-pos' ),
					'error'       => __( 'Something went wrong. Please try again.', 'vanjorn-rental-pos' ),
					'unavailable' => __( 'This slot is no longer available. Please refresh the page.', 'vanjorn-rental-pos' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Shortcode rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the [vanjorn_remaining_slots] shortcode.
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return string     HTML output.
	 */
	public function render_shortcode( $atts ) {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'VanPOS_Functions' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'end_date' => date( 'Y-m-d', strtotime( '+3 months' ) ),
				'min_days' => (string) VanPOS_Functions::get_min_rental_days(),
				'max_days' => (string) VanPOS_Functions::get_max_rental_days(),
			),
			$atts,
			'vanjorn_remaining_slots'
		);

		$end_date_str = sanitize_text_field( $atts['end_date'] );
		$min_days     = max( 1, absint( $atts['min_days'] ) );
		$max_days     = max( $min_days, absint( $atts['max_days'] ) );

		$products = VanPOS_Functions::get_rental_products();

		$output      = '<div class="vanpos-remaining-slots">';
		$any_windows = false;

		foreach ( $products as $product ) {
			$windows = $this->find_available_windows(
				$product['id'],
				$end_date_str,
				$min_days,
				$max_days
			);

			if ( empty( $windows ) ) {
				continue;
			}

			$any_windows = true;

			// Build slot links, then join with a bullet separator.
			$slot_parts = array();
			foreach ( $windows as $window ) {
				$slot_parts[] = $this->render_slot_link( $window, $product['id'], $min_days, $max_days );
			}

			$sep = ' <span class="vanpos-rs-sep" aria-hidden="true">&#9679;</span> ';

			$output .= '<p class="vanpos-rs-row">'
			         . '<strong>' . esc_html( $product['name'] ) . '</strong>: '
			         . implode( $sep, $slot_parts )
			         . '</p>';
		}

		if ( ! $any_windows ) {
			$output .= '<p class="vanpos-rs-no-slots">'
			           . esc_html__( 'No rental slots available within the selected period.', 'vanjorn-rental-pos' )
			           . '</p>';
		}

		$output .= '</div>'; // .vanpos-remaining-slots

		return $output;
	}

	/**
	 * Build HTML for a single slot: an underlined link + duration in parentheses.
	 *
	 * @param array $window     Keys: pickup_date, return_date, days.
	 * @param int   $product_id Product ID.
	 * @param int   $min_days   Passed as data attribute for AJAX validation.
	 * @param int   $max_days   Passed as data attribute for AJAX validation.
	 * @return string
	 */
	private function render_slot_link( array $window, $product_id, $min_days, $max_days ) {
		$pickup_fmt = self::format_short_date( $window['pickup_date'] );
		$return_fmt = self::format_short_date( $window['return_date'] );
		$days       = (int) $window['days'];

		/* translators: %d is the number of rental days */
		$days_label = sprintf(
			_n( '%d day', '%d days', $days, 'vanjorn-rental-pos' ),
			$days
		);
		$days_label = apply_filters( 'vanpos_rs_days_label', $days_label, $days );

		return sprintf(
			'<a class="vanpos-rs-slot" href="#" '
			. 'data-product-id="%1$s" '
			. 'data-pickup="%2$s" '
			. 'data-return="%3$s" '
			. 'data-min-days="%4$d" '
			. 'data-max-days="%5$d">'
			. '%6$s &ndash; %7$s'
			. '</a>'
			. ' <span class="vanpos-rs-duration">(%8$s)</span>',
			esc_attr( $product_id ),
			esc_attr( $window['pickup_date'] ),
			esc_attr( $window['return_date'] ),
			$min_days,
			$max_days,
			esc_html( $pickup_fmt ),
			esc_html( $return_fmt ),
			esc_html( $days_label )
		);
	}

	/**
	 * Format a date as a short, locale-aware string, e.g. "4 jun".
	 *
	 * Uses WordPress's date_i18n() so it respects the active locale's month
	 * abbreviations, then lowercases to match Dutch convention (jun, aug, etc.).
	 *
	 * @param string $date_str Y-m-d.
	 * @return string
	 */
	private static function format_short_date( $date_str ) {
		return strtolower( date_i18n( 'j M', strtotime( $date_str ) ) );
	}

	// -------------------------------------------------------------------------
	// Availability window calculation
	// -------------------------------------------------------------------------

	/**
	 * Find available rental windows for a single product.
	 *
	 * The calendar is divided into "free periods" — contiguous stretches of
	 * dates where the van is NOT fully occupied (i.e., not in
	 * unavailableFullDates). One window is generated per free period:
	 *
	 *   – Pickup  = first valid pickup day-of-week within the period that is
	 *               not in unavailablePickupDates (PM slot free).
	 *   – Return  = latest date from [pickup + max_days − 1, period_end,
	 *               end_date] where AM is available (not in
	 *               unavailableReturnDates).
	 *   – The window is only included if its duration ≥ min_days.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $end_date_str Upper bound for the scan (Y-m-d).
	 * @param int    $min_days     Minimum duration to include.
	 * @param int    $max_days     Maximum duration (caps the return edge).
	 * @return array  Array of windows: [ pickup_date, return_date, days ].
	 */
	private function find_available_windows( $product_id, $end_date_str, $min_days, $max_days ) {
		$pickup_days = VanPOS_Functions::get_pickup_days(); // e.g. [4, 5] = Thu, Fri

		$today = new DateTime( current_time( 'Y-m-d' ) );

		try {
			$end = new DateTime( $end_date_str );
		} catch ( Exception $e ) {
			return array();
		}

		if ( $end < $today ) {
			return array();
		}

		// Fetch blocked-date arrays from the calendar availability cache.
		$cal            = VanPOS_Functions::get_rental_calendar_availability( $product_id );
		$full_blocked   = array_flip( $cal['unavailableFullDates'] );   // both AM+PM blocked
		$pickup_blocked = array_flip( $cal['unavailablePickupDates'] ); // PM blocked (no pickup)
		$return_blocked = array_flip( $cal['unavailableReturnDates'] ); // AM blocked (no return)

		$windows = array();
		$current = clone $today;

		while ( $current <= $end ) {
			$date_str = $current->format( 'Y-m-d' );

			// Skip dates where the van is completely occupied.
			if ( isset( $full_blocked[ $date_str ] ) ) {
				$current->modify( '+1 day' );
				continue;
			}

			// --- Found the start of a free period ---

			// Extend forward to find the end of this contiguous free block.
			$period_end = clone $current;
			$probe      = clone $current;
			$probe->modify( '+1 day' );

			while ( $probe <= $end ) {
				$probe_str = $probe->format( 'Y-m-d' );
				if ( isset( $full_blocked[ $probe_str ] ) ) {
					break; // Next fully-blocked date ends the free period.
				}
				$period_end = clone $probe;
				$probe->modify( '+1 day' );
			}

			// Find the first valid pickup day inside this free period.
			$pickup       = clone $current;
			$found_pickup = false;

			while ( $pickup <= $period_end ) {
				$pd_str = $pickup->format( 'Y-m-d' );
				$dow    = (int) $pickup->format( 'w' ); // 0 = Sun … 6 = Sat

				if ( in_array( $dow, $pickup_days, true ) && ! isset( $pickup_blocked[ $pd_str ] ) ) {
					$found_pickup = true;
					break;
				}
				$pickup->modify( '+1 day' );
			}

			if ( $found_pickup ) {
				// Determine the latest allowed return date.
				$max_return = clone $pickup;
				$max_return->modify( '+' . ( $max_days - 1 ) . ' days' );

				if ( $max_return > $period_end ) {
					$max_return = clone $period_end;
				}
				if ( $max_return > $end ) {
					$max_return = clone $end;
				}

				// Walk backward from max_return to find a day with a free AM slot.
				$return_date = clone $max_return;

				while ( $return_date > $pickup ) {
					if ( ! isset( $return_blocked[ $return_date->format( 'Y-m-d' ) ] ) ) {
						break;
					}
					$return_date->modify( '-1 day' );
				}

				// Include the pickup date itself as a potential same-day return only
				// when min_days == 1; otherwise require a later return date.
				$return_date_str = $return_date->format( 'Y-m-d' );
				$valid_return    = ! isset( $return_blocked[ $return_date_str ] )
				                   && $return_date >= $pickup;

				if ( $valid_return ) {
					$days = $pickup->diff( $return_date )->days + 1; // inclusive count

					if ( $days >= $min_days ) {
						$windows[] = array(
							'pickup_date' => $pickup->format( 'Y-m-d' ),
							'return_date' => $return_date_str,
							'days'        => $days,
						);
					}
				}
			}

			// Advance past the entire free period before scanning for the next.
			$current = clone $period_end;
			$current->modify( '+1 day' );
		}

		return $windows;
	}

	// -------------------------------------------------------------------------
	// AJAX: add selected slot to cart
	// -------------------------------------------------------------------------

	/**
	 * Handle vanpos_rs_add_to_cart AJAX request.
	 *
	 * Uses the min_days / max_days passed by the pill's data attributes instead
	 * of the global plugin settings, so shortcode-level overrides are respected.
	 * Cart-item data is built to match the structure that VanPOS_Functions::add_to_cart
	 * uses, ensuring compatibility with Kestrel, WooCommerce Multilingual, and the
	 * payment-split meta pipeline.
	 */
	public function ajax_add_to_cart() {
		if ( ! check_ajax_referer( 'vanpos_rs_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		$product_id  = absint( isset( $_POST['product_id'] ) ? $_POST['product_id'] : 0 );
		$pickup_date = isset( $_POST['pickup_date'] ) ? sanitize_text_field( wp_unslash( $_POST['pickup_date'] ) ) : '';
		$return_date = isset( $_POST['return_date'] ) ? sanitize_text_field( wp_unslash( $_POST['return_date'] ) ) : '';
		$min_days    = absint( isset( $_POST['min_days'] ) ? $_POST['min_days'] : VanPOS_Functions::get_min_rental_days() );
		$max_days    = absint( isset( $_POST['max_days'] ) ? $_POST['max_days'] : VanPOS_Functions::get_max_rental_days() );

		if ( ! $product_id || ! $pickup_date || ! $return_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Resolve to original (default-language) product ID for WPML compat.
		$product_id = VanPOS_Functions::get_original_product_id( $product_id );

		// Calculate inclusive day count, mirroring WCRP / VanPOS_Functions::add_to_cart.
		if ( class_exists( 'WCRP_Rental_Products_Misc' )
		     && method_exists( 'WCRP_Rental_Products_Misc', 'days_total_from_dates' ) ) {
			$days = (int) WCRP_Rental_Products_Misc::days_total_from_dates( $pickup_date, $return_date );
		} else {
			$days = abs( round( ( strtotime( $return_date ) - strtotime( $pickup_date ) ) / DAY_IN_SECONDS ) ) + 1;
		}

		// Validate against the shortcode's own min/max constraints.
		if ( $days < $min_days || $days > $max_days ) {
			wp_send_json_error( array( 'message' => __( 'Invalid rental period.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Live availability check — in case the slot was just booked by someone else.
		$availability = VanPOS_Functions::check_rental_availability( $product_id, $pickup_date, $return_date, 1 );
		if ( 'available' !== $availability ) {
			wp_send_json_error( array(
				'message' => __( 'This slot is no longer available. Please refresh the page.', 'vanjorn-rental-pos' ),
			) );
			return;
		}

		// Nights for pricing (VanJorn bills on nights, not inclusive days).
		$nights = VanPOS_Functions::rental_nights_from_dates( $pickup_date, $return_date );

		// Rental-plugin defaults.
		$default_rental_options = get_option( 'wcrp_rental_products_default_rental_options', array() );

		$return_days_threshold = get_post_meta( $product_id, '_wcrp_rental_products_return_days_threshold', true );
		if ( '' === $return_days_threshold ) {
			$return_days_threshold = isset( $default_rental_options['_wcrp_rental_products_return_days_threshold'] )
				? $default_rental_options['_wcrp_rental_products_return_days_threshold']
				: 3;
		}

		$start_days_threshold = get_post_meta( $product_id, '_wcrp_rental_products_start_days_threshold', true );
		if ( '' === $start_days_threshold ) {
			$start_days_threshold = isset( $default_rental_options['_wcrp_rental_products_start_days_threshold'] )
				? $default_rental_options['_wcrp_rental_products_start_days_threshold']
				: 0;
		}

		$advanced_pricing = get_post_meta( $product_id, '_wcrp_rental_products_advanced_pricing', true );
		if ( '' === $advanced_pricing ) {
			$advanced_pricing = isset( $default_rental_options['_wcrp_rental_products_advanced_pricing'] )
				? $default_rental_options['_wcrp_rental_products_advanced_pricing']
				: 'off';
		}

		// Price (billed on nights, matching VanPOS_Functions::add_to_cart).
		$calculated_price = VanPOS_Functions::calculate_rental_price( $product_id, $nights );

		// Timestamp must be ≥ product's last-modified time to pass Kestrel validation.
		$product_post        = get_post( $product_id );
		$current_time        = time();
		$cart_item_timestamp = $product_post
			? max( $current_time, strtotime( $product_post->post_modified_gmt ) + 1 )
			: $current_time;

		// Times from plugin settings.
		$pickup_time      = VanPOS_Functions::get_setting( 'vanpos_pickup_time', '15:00' );
		$return_time_val  = VanPOS_Functions::get_setting( 'vanpos_return_time', '11:00' );
		$include_cleaning = VanPOS_Functions::get_setting( 'vanpos_cleaning_enabled', 'yes' ) === 'yes';

		// Clear existing cart — one rental at a time.
		if ( ! is_null( WC()->cart ) && ! WC()->cart->is_empty() ) {
			WC()->cart->empty_cart( false );
		}

		$cart_item_data = array(
			// VanPOS display / meta format.
			'vanpos_pickup_date'      => $pickup_date,
			'vanpos_return_date'      => $return_date,
			'vanpos_pickup_time'      => $pickup_time,
			'vanpos_return_time'      => $return_time_val,
			'vanpos_rental_days'      => $days,
			'vanpos_rental_nights'    => $nights,
			'vanpos_include_dog'      => false,
			'vanpos_include_cleaning' => $include_cleaning,
			// Kestrel / WooCommerce Rental Products format.
			'wcrp_rental_products_rent_from'             => $pickup_date,
			'wcrp_rental_products_rent_to'               => $return_date,
			'wcrp_rental_products_return_days_threshold' => $return_days_threshold,
			'wcrp_rental_products_start_days_threshold'  => $start_days_threshold,
			'wcrp_rental_products_cart_item_price'       => (string) $calculated_price,
			'wcrp_rental_products_cart_item_timestamp'   => (string) $cart_item_timestamp,
			'wcrp_rental_products_cart_item_validation'  => 'passed',
		);

		if ( 'on' === $advanced_pricing ) {
			$cart_item_data['wcrp_rental_products_advanced_pricing'] = 'on';
		}

		$cart_item_key = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data );

		if ( $cart_item_key ) {
			wp_send_json_success( array(
				'message' => __( 'Added to cart.', 'vanjorn-rental-pos' ),
				'cartUrl' => wc_get_checkout_url(),
			) );
		} else {
			wp_send_json_error( array(
				'message' => __( 'Failed to add to cart. Please try again.', 'vanjorn-rental-pos' ),
			) );
		}
	}
}
