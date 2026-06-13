<?php
/**
 * Availability Manager for VAN-Jorn Rental Platform
 * Handles availability checking with alternatives
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Availability Manager Class
 */
class VanPOS_Availability_Manager {

	/**
	 * Check availability for date range
	 *
	 * @param int    $product_id Product ID.
	 * @param string $pickup_date Pickup date (Y-m-d).
	 * @param string $pickup_time Pickup time slot.
	 * @param string $return_date Return date (Y-m-d).
	 * @param string $return_time Return time slot.
	 * @return array Availability result with alternatives.
	 */
	public static function check_availability( $product_id, $pickup_date, $pickup_time, $return_date, $return_time ) {
		$result = array(
			'available'   => false,
			'message'     => '',
			'alternatives' => array(),
		);

		// Validate dates
		$validation = self::validate_dates( $pickup_date, $pickup_time, $return_date, $return_time );
		if ( ! $validation['valid'] ) {
			$result['message'] = $validation['message'];
			$result['alternatives'] = $validation['alternatives'];
			return $result;
		}

		// Check if product is available for the date range
		$is_available = VanPOS_Functions::is_date_range_available( $product_id, $pickup_date, $return_date );

		if ( $is_available ) {
			$result['available'] = true;
			$result['message'] = __( 'Selected dates are available.', 'vanjorn-rental-pos' );
		} else {
			$result['available'] = false;
			$result['message'] = __( 'Selected dates are not available.', 'vanjorn-rental-pos' );
			$result['alternatives'] = self::get_alternatives( $product_id, $pickup_date, $pickup_time, $return_date, $return_time );
		}

		return $result;
	}

	/**
	 * Validate dates according to business rules
	 *
	 * @param string $pickup_date Pickup date (Y-m-d).
	 * @param string $pickup_time Pickup time slot.
	 * @param string $return_date Return date (Y-m-d).
	 * @param string $return_time Return time slot.
	 * @return array Validation result.
	 */
	private static function validate_dates( $pickup_date, $pickup_time, $return_date, $return_time ) {
		$result = array(
			'valid'        => true,
			'message'     => '',
			'alternatives' => array(),
		);

		$pickup_datetime = new DateTime( $pickup_date );
		$return_datetime = new DateTime( $return_date );

		// Check pickup day
		$pickup_day = (int) $pickup_datetime->format( 'w' );
		$pickup_days = VanPOS_Functions::get_pickup_days(); // Should return [4, 5] for Thu, Fri

		if ( ! in_array( $pickup_day, $pickup_days, true ) ) {
			$result['valid'] = false;
			// Get day names for configured pickup days
			$day_names = self::get_pickup_day_names();
			$result['message'] = sprintf(
				/* translators: %s is a list of day names joined by "or", e.g. "Thursday or Friday" */
				__( 'Pickup must be on %s.', 'vanjorn-rental-pos' ),
				implode( __( ' or ', 'vanjorn-rental-pos' ), $day_names )
			);
			$result['alternatives'] = self::get_next_pickup_dates( $pickup_date );
			return $result;
		}

		// Check return day
		$return_day = (int) $return_datetime->format( 'w' );
		if ( ! in_array( $return_day, $pickup_days, true ) ) {
			$result['valid'] = false;
			// Get day names for configured pickup days
			$day_names = self::get_pickup_day_names();
			$result['message'] = sprintf(
				/* translators: %s is a list of day names joined by "or", e.g. "Thursday or Friday" */
				__( 'Return must be on %s.', 'vanjorn-rental-pos' ),
				implode( __( ' or ', 'vanjorn-rental-pos' ), $day_names )
			);
			$result['alternatives'] = self::get_next_return_dates( $return_date );
			return $result;
		}

		// Check rental duration (6-22 days, Kestrel-compatible: includes both pickup and return day)
		$days = $pickup_datetime->diff( $return_datetime )->days + 1;
		$min_days = VanPOS_Functions::get_min_rental_days();
		$max_days = VanPOS_Functions::get_max_rental_days();

		if ( $days < $min_days ) {
			$result['valid'] = false;
			$result['message'] = sprintf(
				/* translators: %d is the minimum number of rental days */
				__( 'Minimum rental period is %d days.', 'vanjorn-rental-pos' ),
				$min_days
			);
			$result['alternatives'] = self::get_valid_return_dates( $pickup_date, $min_days );
			return $result;
		}

		if ( $days > $max_days ) {
			$result['valid'] = false;
			$result['message'] = sprintf(
				/* translators: %d is the maximum number of rental days */
				__( 'Maximum rental period is %d days.', 'vanjorn-rental-pos' ),
				$max_days
			);
			$result['alternatives'] = self::get_valid_return_dates( $pickup_date, $max_days );
			return $result;
		}

		// Check if pickup is in the past. Compare date-only against "today" in the
		// site's timezone (current_time), not the server's wall clock: $pickup_datetime
		// is parsed at midnight and the pickup/return times are independent half-day
		// slots, so a same-day pickup is not "in the past". The previous `new DateTime()`
		// used the server timezone AND its wall-clock time, which rejected every
		// same-day booking (the POS/add-order and change-date flows both hit this path).
		$today = new DateTime( current_time( 'Y-m-d' ) );
		if ( $pickup_datetime < $today ) {
			$result['valid'] = false;
			$result['message'] = __( 'Pickup date cannot be in the past.', 'vanjorn-rental-pos' );
			$result['alternatives'] = self::get_next_pickup_dates( $today->format( 'Y-m-d' ) );
			return $result;
		}

		return $result;
	}

	/**
	 * Get alternatives when dates are not available
	 *
	 * @param int    $product_id Product ID.
	 * @param string $pickup_date Pickup date (Y-m-d).
	 * @param string $pickup_time Pickup time slot.
	 * @param string $return_date Return date (Y-m-d).
	 * @param string $return_time Return time slot.
	 * @return array Alternatives.
	 */
	private static function get_alternatives( $product_id, $pickup_date, $pickup_time, $return_date, $return_time ) {
		$alternatives = array();

		// Alternative timeslots
		$alternatives['timeslots'] = array(
			'pickup' => $pickup_time === 'morning' ? 'afternoon' : 'morning',
			'return' => $return_time === 'morning' ? 'afternoon' : 'morning',
		);

		// Alternative nearby dates
		$alternatives['nearby_dates'] = self::get_nearby_available_dates( $product_id, $pickup_date );

		// Alternative products
		$alternatives['other_products'] = self::get_available_products( $pickup_date, $return_date );

		return $alternatives;
	}

	/**
	 * Get next valid pickup dates
	 *
	 * @param string $from_date Start date (Y-m-d).
	 * @return array Next pickup dates.
	 */
	private static function get_next_pickup_dates( $from_date ) {
		$dates = array();
		$pickup_days = VanPOS_Functions::get_pickup_days();
		$current = new DateTime( $from_date );
		$current->modify( '+1 day' );

		for ( $i = 0; $i < 14; $i++ ) {
			$day = (int) $current->format( 'w' );
			if ( in_array( $day, $pickup_days, true ) ) {
				$dates[] = $current->format( 'Y-m-d' );
				if ( count( $dates ) >= 3 ) {
					break;
				}
			}
			$current->modify( '+1 day' );
		}

		return $dates;
	}

	/**
	 * Get next valid return dates
	 *
	 * @param string $from_date Start date (Y-m-d).
	 * @return array Next return dates.
	 */
	private static function get_next_return_dates( $from_date ) {
		return self::get_next_pickup_dates( $from_date );
	}

	/**
	 * Get valid return dates for a pickup date
	 *
	 * @param string $pickup_date Pickup date (Y-m-d).
	 * @param int    $target_days Target number of days.
	 * @return array Valid return dates.
	 */
	private static function get_valid_return_dates( $pickup_date, $target_days ) {
		$dates = array();
		$pickup_datetime = new DateTime( $pickup_date );
		$pickup_days = VanPOS_Functions::get_pickup_days();

		// Calculate target return date
		$target_return = clone $pickup_datetime;
		$target_return->modify( '+' . $target_days . ' days' );

		// Find nearest valid return date
		for ( $i = -3; $i <= 3; $i++ ) {
			$check_date = clone $target_return;
			$check_date->modify( $i . ' days' );
			$day = (int) $check_date->format( 'w' );

			if ( in_array( $day, $pickup_days, true ) && $check_date > $pickup_datetime ) {
				$dates[] = $check_date->format( 'Y-m-d' );
				if ( count( $dates ) >= 3 ) {
					break;
				}
			}
		}

		return $dates;
	}

	/**
	 * Get nearby available dates for a product
	 *
	 * @param int    $product_id Product ID.
	 * @param string $preferred_date Preferred date (Y-m-d).
	 * @return array Available dates.
	 */
	private static function get_nearby_available_dates( $product_id, $preferred_date ) {
		$dates = array();
		$pickup_days = VanPOS_Functions::get_pickup_days();
		$preferred_datetime = new DateTime( $preferred_date );

		// Check dates around preferred date
		for ( $offset = -14; $offset <= 14; $offset++ ) {
			$check_date = clone $preferred_datetime;
			$check_date->modify( $offset . ' days' );
			$day = (int) $check_date->format( 'w' );

			if ( in_array( $day, $pickup_days, true ) ) {
				$date_str = $check_date->format( 'Y-m-d' );
				if ( VanPOS_Functions::is_date_available( $product_id, $date_str ) ) {
					$dates[] = $date_str;
					if ( count( $dates ) >= 5 ) {
						break;
					}
				}
			}
		}

		return $dates;
	}

	/**
	 * Get pickup day names for configured pickup days
	 *
	 * @return array Day names.
	 */
	private static function get_pickup_day_names() {
		$pickup_days = VanPOS_Functions::get_pickup_days();
		$days = array(
			0 => __( 'Sunday', 'vanjorn-rental-pos' ),
			1 => __( 'Monday', 'vanjorn-rental-pos' ),
			2 => __( 'Tuesday', 'vanjorn-rental-pos' ),
			3 => __( 'Wednesday', 'vanjorn-rental-pos' ),
			4 => __( 'Thursday', 'vanjorn-rental-pos' ),
			5 => __( 'Friday', 'vanjorn-rental-pos' ),
			6 => __( 'Saturday', 'vanjorn-rental-pos' ),
		);
		
		$day_names = array();
		foreach ( $pickup_days as $day_num ) {
			if ( isset( $days[ $day_num ] ) ) {
				$day_names[] = $days[ $day_num ];
			}
		}
		
		return $day_names;
	}

	/**
	 * Get other available products for date range
	 *
	 * @param string $pickup_date Pickup date (Y-m-d).
	 * @param string $return_date Return date (Y-m-d).
	 * @return array Available product IDs.
	 */
	private static function get_available_products( $pickup_date, $return_date ) {
		$products = VanPOS_Functions::get_rental_products();
		$available = array();

		foreach ( $products as $product ) {
			if ( VanPOS_Functions::is_date_range_available( $product['id'], $pickup_date, $return_date ) ) {
				$available[] = array(
					'id'   => $product['id'],
					'name' => $product['name'],
					'type' => $product['type'],
				);
			}
		}

		return $available;
	}
}
