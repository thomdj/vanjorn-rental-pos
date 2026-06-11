<?php
/**
 * Change Manager for VAN-Jorn Rental Platform
 * Handles booking changes and extensions
 *
 * @package VJ_Rental_POS
 * @author  CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change Manager Class
 */
class VanPOS_Change_Manager {

	/**
	 * Look up the WooCommerce tax rate ID for the order's applicable tax rate.
	 *
	 * Resolution order:
	 *  1. Extract from existing tax items on the order (most reliable).
	 *  2. Look up the shop's standard tax rate from the WC tax rates table.
	 *  3. Fallback to rate ID 1.
	 *
	 * @param WC_Order|null $order Optional order to extract rate ID from existing tax items.
	 * @return int Tax rate ID.
	 */
	private static function get_vat_rate_id( $order = null ) {
		// 1. Try to extract from existing tax items on the order
		if ( $order ) {
			foreach ( $order->get_items( 'tax' ) as $tax_item ) {
				$rate_id = $tax_item->get_rate_id();
				if ( $rate_id > 0 ) {
					return (int) $rate_id;
				}
			}
		}

		// 2. Look up the shop's standard tax rate from the WC tax rates table
		$shop_country = function_exists( 'WC' ) && WC()->countries
			? WC()->countries->get_base_country()
			: '';

		global $wpdb;
		if ( $shop_country ) {
			$rate_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
					 WHERE tax_rate_country = %s
					 ORDER BY tax_rate_priority ASC, tax_rate_order ASC
					 LIMIT 1",
					$shop_country
				)
			);
		} else {
			// No country available — grab the first active rate
			$rate_id = $wpdb->get_var(
				"SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
				 ORDER BY tax_rate_priority ASC, tax_rate_order ASC
				 LIMIT 1"
			);
		}

		if ( $rate_id ) {
			return (int) $rate_id;
		}

		// 3. Fallback to rate ID 1
		return 1;
	}

	/**
	 * Get the tax rate percentage for a given WC tax rate ID.
	 *
	 * @param int $rate_id WooCommerce tax rate ID.
	 * @return float Tax rate as a decimal (e.g. 0.21 for 21%). Returns 0 if not found.
	 */
	private static function get_tax_rate_percentage( $rate_id ) {
		// Try WC_Tax API first (available since WC 3.x)
		if ( class_exists( 'WC_Tax' ) ) {
			$percent_str = WC_Tax::get_rate_percent_value( $rate_id );
			if ( $percent_str > 0 ) {
				return (float) $percent_str / 100;
			}
		}

		// Direct DB lookup as fallback
		global $wpdb;
		$rate = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT tax_rate FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d",
				$rate_id
			)
		);

		if ( $rate !== null ) {
			return (float) $rate / 100;
		}

		return 0;
	}

	/**
	 * Whether an explicit admin-supplied daily rate was provided.
	 *
	 * A numeric value >= 0 counts as provided (0 is a valid free/comped rate).
	 * null (the default) means "no override — use the stored or catalogue rate".
	 *
	 * @param mixed $price_per_day Rate from the request, or null.
	 * @return bool
	 */
	private static function price_is_provided( $price_per_day ) {
		return ( null !== $price_per_day && is_numeric( $price_per_day ) && (float) $price_per_day >= 0 );
	}

	/**
	 * Price for a booking modification (date change / extension).
	 *
	 * Three cases, evaluated in order:
	 *
	 * 1. Admin-supplied $price_per_day → full re-rate: rate × new_days.
	 *    The admin explicitly changed the daily rate, so the whole booking
	 *    is recalculated at the new rate regardless of legacy or new order.
	 *
	 * 2. Same van, different days → delta: old_total + $stored_rate × Δdays.
	 *    $stored_rate comes from _vanpos_price_per_day on the order, or the
	 *    product's current catalogue price. Never back-calculates a rate from
	 *    the stored total, so legacy day-billed orders (where total ÷ nights
	 *    would produce a ghost rate) are handled correctly.
	 *
	 * 3. Van changed (or no stored total/basis) → catalogue pricing via
	 *    VanPOS_Functions::calculate_rental_price().
	 *
	 * All three cases use inclusive calendar days ($old_days / $new_days),
	 * not billable nights. $stored_rate is resolved by the caller.
	 *
	 * @param int        $product_id      WC product ID for the (possibly new) van.
	 * @param int        $new_days        Inclusive rental days after the change.
	 * @param float      $old_total       Full rental total before modification.
	 * @param int        $old_days        Inclusive rental days before modification.
	 * @param bool       $product_changed Whether the van/product was swapped.
	 * @param float|null $price_per_day   Admin-supplied daily rate override, or null.
	 * @param float|null $stored_rate     Resolved daily rate for delta (from order meta
	 *                                    or catalogue). null falls back to back-calculation.
	 * @return float
	 */
	public static function calculate_modification_price( $product_id, $new_days, $old_total, $old_days, $product_changed, $price_per_day = null, $stored_rate = null ) {
		$new_days = max( 1, (int) $new_days );

		// Admin-supplied gross daily rate: full re-rate (explicit admin intent).
		// A value of 0 is a valid explicit rate (free / comped); null means "not supplied".
		if ( self::price_is_provided( $price_per_day ) ) {
			return round( (float) $price_per_day * $new_days, wc_get_price_decimals() );
		}

		if ( ! $product_changed && $old_total > 0 && $old_days > 0 && class_exists( 'VanPOS_Functions' ) ) {
			if ( $new_days === (int) $old_days ) {
				return round( (float) $old_total, wc_get_price_decimals() );
			}

			// Delta approach: old_total + rate × Δdays.
			// Never back-calculates rate from stored total, so legacy day-billed orders
			// (where total ÷ nights would produce a ghost rate) are handled correctly.
			// $stored_rate is resolved by the caller from _vanpos_price_per_day or the
			// product catalogue price — it is always the same unit as $old_days / $new_days.
			if ( $stored_rate > 0 ) {
				return round( (float) $old_total + (float) $stored_rate * ( $new_days - (int) $old_days ), wc_get_price_decimals() );
			}

			// Fallback: back-calculate only when no rate was resolvable (should be rare).
			$locked_daily_rate = (float) $old_total / (int) $old_days;
			return round( $locked_daily_rate * $new_days, wc_get_price_decimals() );
		}

		if ( class_exists( 'VanPOS_Functions' ) ) {
			return VanPOS_Functions::calculate_rental_price( $product_id, $new_days );
		}

		return 0;
	}

	/**
	 * Derive modification price from an order's current (pre-change) meta.
	 *
	 * @param WC_Order   $order           Primary rental order (unchanged meta).
	 * @param int        $product_id      Effective product ID after the change.
	 * @param int        $new_days        Inclusive rental days after the change.
	 * @param bool       $product_changed Whether the van was swapped.
	 * @param float|null $price_per_day   Admin-supplied daily rate override, or null.
	 * @return float
	 */
	public static function calculate_modification_price_for_order( $order, $product_id, $new_days, $product_changed, $price_per_day = null ) {
		$old_total = (float) $order->get_meta( '_vanpos_total_price' );
		// Delta fix: use inclusive calendar days on both sides of the comparison.
		// Previously used locked_rate_basis() (= nights), which caused a ghost rate
		// on legacy day-billed orders (stored total ÷ nights ≠ actual daily rate).
		$old_days = class_exists( 'VanPOS_Functions' )
			? VanPOS_Functions::rental_days_for_order( $order )
			: max( 1, (int) $order->get_meta( '_vanpos_rental_days' ) );

		if ( $old_total <= 0 ) {
			foreach ( $order->get_items() as $item ) {
				$original_price = (float) $item->get_meta( '_vanpos_original_price' );
				if ( $original_price > 0 ) {
					$old_total = $original_price;
					break;
				}
			}
		}

		// Resolve the effective daily rate for the delta approach.
		// Priority: stored negotiated rate → catalogue price.
		// Only resolved for same-van changes; van changes use catalogue pricing internally.
		$stored_rate = (float) $order->get_meta( '_vanpos_price_per_day' );
		if ( $stored_rate <= 0 && ! $product_changed ) {
			$product_obj = wc_get_product( $product_id );
			$stored_rate = $product_obj ? (float) $product_obj->get_price() : 0;
		}

		return self::calculate_modification_price( $product_id, $new_days, $old_total, $old_days, $product_changed, $price_per_day, $stored_rate );
	}

	/**
	 * Modify a booking's dates and/or van (product).
	 *
	 * @param int    $order_id Primary order ID.
	 * @param string $new_pickup_date New pickup date (Y-m-d).
	 * @param string $new_pickup_time New pickup time slot.
	 * @param string $new_return_date New return date (Y-m-d).
	 * @param string $new_return_time New return time slot.
	 * @param bool   $override_availability Skip availability check (admin override).
	 * @param int    $new_product_id New product/van ID (0 = no change).
	 * @return bool|WP_Error
	 */
	public static function change_dates( $order_id, $new_pickup_date, $new_pickup_time, $new_return_date, $new_return_time, $override_availability = false, $new_product_id = 0, $price_per_day = null, $lock_total = null ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'invalid_order', __( 'Invalid order.', 'vanjorn-rental-pos' ) );
		}

		// Check if order type is primary rental
		if ( $order->get_meta( '_vanpos_order_type' ) !== 'primary_rental' ) {
			return new WP_Error( 'invalid_order_type', __( 'Order is not a primary rental order.', 'vanjorn-rental-pos' ) );
		}

		// Get current product ID from order items
		$old_product_id = 0;
		foreach ( $order->get_items() as $item ) {
			$old_product_id = $item->get_product_id();
			break;
		}

		if ( ! $old_product_id ) {
			return new WP_Error( 'no_product', __( 'No product found in order.', 'vanjorn-rental-pos' ) );
		}

		// Determine effective product ID (new if changing, old if not)
		$product_changed = ( $new_product_id > 0 && (int) $new_product_id !== (int) $old_product_id );
		$product_id      = $product_changed ? (int) $new_product_id : (int) $old_product_id;

		// Validate the new product exists
		if ( $product_changed ) {
			$new_product_obj = wc_get_product( $product_id );
			if ( ! $new_product_obj ) {
				return new WP_Error( 'invalid_product', __( 'The selected van does not exist.', 'vanjorn-rental-pos' ) );
			}
		}

		// Validate new dates — with self-exclusion so the order's own
		// reservation doesn't block the availability check.
		// When changing product, the availability check targets the NEW van.
		$saved_rows_from_check = array();
		if ( ! $override_availability ) {
			$avail = self::check_availability_excluding_order( $product_id, $order_id, $new_pickup_date, $new_pickup_time, $new_return_date, $new_return_time );

			if ( ! $avail['available'] ) {
				return new WP_Error( 'unavailable', $avail['message'], isset( $avail['alternatives'] ) ? $avail['alternatives'] : array() );
			}

			// Rows were deleted by the availability check and need to be
			// restored if the subsequent Kestrel insert fails.
			$saved_rows_from_check = isset( $avail['saved_rows'] ) ? $avail['saved_rows'] : array();
		}

		// Validate date ordering before modifying any order data
		$pickup_datetime = new DateTime( $new_pickup_date );
		$return_datetime = new DateTime( $new_return_date );

		if ( $return_datetime < $pickup_datetime ) {
			return new WP_Error( 'invalid_dates', __( 'Return date must be after pickup date.', 'vanjorn-rental-pos' ) );
		}

		// Same-day validation: return time must be strictly after pickup time.
		// Times are stored as H:i strings (e.g. '15:00', '11:00') so string
		// comparison works correctly for 24-hour format.
		if ( $new_pickup_date === $new_return_date && $new_pickup_time >= $new_return_time ) {
			return new WP_Error( 'invalid_times', __( 'Return time must be after pickup time on the same day.', 'vanjorn-rental-pos' ) );
		}

		$days = class_exists( 'VanPOS_Functions' )
			? VanPOS_Functions::rental_days_from_dates( $new_pickup_date, $new_return_date )
			: ( $pickup_datetime->diff( $return_datetime )->days + 1 );

		// $nights is kept for _vanpos_rental_nights meta only.
		// Pricing now uses inclusive $days via the delta approach (see calculate_modification_price).
		$nights = class_exists( 'VanPOS_Functions' )
			? VanPOS_Functions::rental_nights_from_dates( $new_pickup_date, $new_return_date )
			: max( 0, $pickup_datetime->diff( $return_datetime )->days );

		// ── Store old values for history (and locked-rate pricing) ─────────
		$old_pickup_date = $order->get_meta( '_vanpos_pickup_date' );
		$old_return_date = $order->get_meta( '_vanpos_return_date' );

		$old_total_for_lock = (float) $order->get_meta( '_vanpos_total_price' );
		// Delta fix: old_days must use the same unit as $days (inclusive calendar days)
		// so the no-change identity check and the Δdays arithmetic are both correct.
		// Previously used locked_rate_basis() (= nights), which caused a mismatch on
		// legacy day-billed orders and produced ghost rates.
		$old_days_for_lock = class_exists( 'VanPOS_Functions' )
			? VanPOS_Functions::rental_days_for_order( $order )
			: max( 1, (int) $order->get_meta( '_vanpos_rental_days' ) );
		if ( $old_total_for_lock <= 0 ) {
			foreach ( $order->get_items() as $item ) {
				$original_price = (float) $item->get_meta( '_vanpos_original_price' );
				if ( $original_price > 0 ) {
					$old_total_for_lock = $original_price;
					break;
				}
			}
		}

		$old_values = array(
			'pickup_date' => $old_pickup_date,
			'pickup_time' => $order->get_meta( '_vanpos_pickup_time' ),
			'return_date' => $old_return_date,
			'return_time' => $order->get_meta( '_vanpos_return_time' ),
			'product_id'  => $old_product_id,
			'changed_at'  => time(),
		);

		$change_history = $order->get_meta( '_vanpos_date_change_history' );
		if ( ! is_array( $change_history ) ) {
			$change_history = array();
		}
		$change_history[] = $old_values;
		$order->update_meta_data( '_vanpos_date_change_history', $change_history );

		// ── Update order-level date meta ──────────────────────────────────
		$order->update_meta_data( '_vanpos_pickup_date', $new_pickup_date );
		$order->update_meta_data( '_vanpos_pickup_time', $new_pickup_time );
		$order->update_meta_data( '_vanpos_return_date', $new_return_date );
		$order->update_meta_data( '_vanpos_return_time', $new_return_time );

		// Rental days calculated above (before any meta modifications)
		$order->update_meta_data( '_vanpos_rental_days', $days );
		$order->update_meta_data( '_vanpos_rental_nights', $nights );

		// ── Swap line item product if van changed ─────────────────────────
		if ( $product_changed ) {
			self::swap_line_item_product( $order, $old_product_id, $product_id );
		}

		// ── Recalculate price ─────────────────────────────────────────────
		$new_remaining_amount = 0;
		$raw_remaining        = 0;
		$new_total_price      = 0;
		$old_total_price      = (float) $order->get_meta( '_vanpos_total_price' );
		$price_diff           = 0;

		if ( $product_id && class_exists( 'VanPOS_Functions' ) ) {
			if ( null !== $lock_total && (float) $lock_total > 0 ) {
				// ── Locked total: preserve the exact stored value, no recalculation ──
				// Using the raw stored float avoids any floating-point multiplication
				// that could produce 1399.99 or 1400.01 on legacy day-billed orders
				// (e.g. €1,400 / 9 nights × 9 nights ≠ €1,400.00 exactly).
				$new_total_price = (float) $lock_total;
				$order->add_order_note(
					__( 'Booking dates changed — total price locked, no recalculation.', 'vanjorn-rental-pos' ),
					false,
					true
				);
			} else {
				// ── Normal path: delta approach (same van) or catalogue (van changed) ───────────
				// Resolve the effective daily rate for the delta approach.
				// Same priority as calculate_modification_price_for_order().
				$stored_rate_for_delta = (float) $order->get_meta( '_vanpos_price_per_day' );
				if ( $stored_rate_for_delta <= 0 && ! $product_changed ) {
					$product_obj_for_rate  = wc_get_product( $product_id );
					$stored_rate_for_delta = $product_obj_for_rate ? (float) $product_obj_for_rate->get_price() : 0;
				}

				$new_total_price = self::calculate_modification_price(
					$product_id,
					$days,                    // inclusive calendar days — matches $old_days_for_lock unit
					$old_total_for_lock,
					$old_days_for_lock,
					$product_changed,
					$price_per_day,
					$stored_rate_for_delta
				);

				// Record the custom daily rate (audit + carry-forward).
				if ( self::price_is_provided( $price_per_day ) ) {
					$order->update_meta_data( '_vanpos_price_per_day', round( (float) $price_per_day, 2 ) );
					$order->update_meta_data( '_vanpos_price_overridden', 'yes' );
					$order->add_order_note(
						sprintf(
							/* translators: 1: daily rate, 2: days, 3: total */
							__( 'Custom rate applied on modification: %1$s/day × %2$d days = %3$s (incl. VAT).', 'vanjorn-rental-pos' ),
							wp_strip_all_tags( wc_price( $price_per_day ) ),
							$days,
							wp_strip_all_tags( wc_price( $new_total_price ) )
						),
						false,
						true
					);
				}
			}

			$order->update_meta_data( '_vanpos_total_price', $new_total_price );

			// Recalculate the initial / remaining split.
			// Keep the raw value (can be negative) for refund routing;
			// clamp to 0 for storage.
			$initial_payment = (float) $order->get_meta( '_vanpos_initial_payment' );
			if ( $initial_payment > 0 ) {
				$raw_remaining        = $new_total_price - $initial_payment;
				$new_remaining_amount = max( 0, $raw_remaining );
				$order->update_meta_data( '_vanpos_remaining_payment', $new_remaining_amount );
			}

			$price_diff = $new_total_price - $old_total_price;

			// ── Clean up stale adjustments from prior date changes ─────────
			// Cancel any unpaid extension orders so they don't pile up when
			// dates are changed multiple times.
			if ( abs( $price_diff ) > 0.01 ) {
				self::cancel_pending_extensions( $order_id );
			}

			// ── Price increase ─────────────────────────────────────────────
			// Only create an extension order when the remaining child is
			// already paid (or doesn't exist). When it's unpaid,
			// update_existing_child_orders() simply adjusts its amount —
			// creating an extension on top of that would double-count.
			if ( $price_diff > 0.01 ) {
				$remaining_child = self::find_remaining_child_order( $order_id );
				$remaining_is_adjustable = $remaining_child
					&& ! $remaining_child->get_date_paid()
					&& ! $remaining_child->has_status( array( 'processing', 'completed', 'cancelled', 'refunded' ) );

				if ( ! $remaining_is_adjustable ) {
					VanPOS_Order_Manager::create_payment_order(
						$order_id,
						'extension',
						$price_diff,
						__( 'Booking modification adjustment', 'vanjorn-rental-pos' )
					);
				}
				// else: remaining is unpaid and will be bumped by update_existing_child_orders.
			}
			// Price decrease is handled after $order->save() — see below.
		}

		// ── Update formatted meta for AutomateWoo email templates ─────────
		// Keep fixed DD-MM-YYYY for admin/PDF consistency across environments.
		$product = wc_get_product( $product_id );

		if ( $product ) {
			$order->update_meta_data( '_vanpos_camper_name', $product->get_name() );
		}
		$order->update_meta_data( '_vanpos_pickup_date_formatted', date_i18n( 'd-m-Y', strtotime( $new_pickup_date ) ) );
		$order->update_meta_data( '_vanpos_return_date_formatted', date_i18n( 'd-m-Y', strtotime( $new_return_date ) ) );

		$order->update_meta_data( '_vanpos_total_price_formatted', wp_strip_all_tags( wc_price( $new_total_price ) ) );
		$order->update_meta_data( '_vanpos_initial_payment_formatted', wp_strip_all_tags( wc_price( (float) $order->get_meta( '_vanpos_initial_payment' ) ) ) );
		$order->update_meta_data( '_vanpos_remaining_payment_formatted', wp_strip_all_tags( wc_price( $new_remaining_amount ) ) );

		// ── Update item-level meta ────────────────────────────────────────
		self::update_item_meta( $order, $product_id, $new_pickup_date, $new_return_date, $new_pickup_time, $new_return_time, $days, $new_total_price, $new_remaining_amount );

		$order->save();

		// ── Price decrease → smart refund routing ─────────────────────────
		// Handled after save so child order lookups see fresh parent meta.
		if ( $price_diff < -0.01 ) {
			self::handle_price_decrease( $order_id, abs( $price_diff ), $raw_remaining, $old_total_price, $new_total_price );
		}

		// ── Update Kestrel reservation rows ───────────────────────────────
		// If we already removed them during the self-exclusion availability
		// check (and the check passed), they're already gone. But if the
		// availability check was overridden, we need to remove them now.
		if ( $override_availability ) {
			// Save old rows before removal so we can restore on insert failure
			$saved_rows_for_override = self::get_kestrel_rows( $order_id );
			self::remove_kestrel_reservation( $order_id );
		}

		$rows_inserted = self::create_kestrel_reservation( $order_id, $product_id, $new_pickup_date, $new_return_date );

		if ( 0 === $rows_inserted ) {
			// Reservation insert failed — restore old rows if we removed them
			// during override or availability check, and warn the admin.
			if ( $override_availability && ! empty( $saved_rows_for_override ) ) {
				self::restore_kestrel_rows( $saved_rows_for_override );
			} elseif ( ! $override_availability && ! empty( $saved_rows_from_check ) ) {
				self::restore_kestrel_rows( $saved_rows_from_check );
			}

			$order->add_order_note(
				__( 'Warning: Kestrel reservation rows could not be created for the new dates. The van may appear available to other bookings. Please check the reservation table manually.', 'vanjorn-rental-pos' ),
				false,
				true
			);

			error_log( sprintf(
				'[VanPOS] create_kestrel_reservation returned 0 rows for order %d (product %d, %s → %s)',
				$order_id, $product_id, $new_pickup_date, $new_return_date
			) );
		}

		// ── Update existing child orders ──────────────────────────────────
		self::update_existing_child_orders( $order_id, $new_pickup_date, $new_return_date, $new_pickup_time, $new_return_time, $new_remaining_amount, $new_total_price, $product );

		return true;
	}

	/* ═══════════════════ Availability (with self-exclusion) ═══════════════ */

	/**
	 * Check availability while excluding the current order's own reservations.
	 *
	 * Temporarily removes the order's Kestrel rows, runs the availability
	 * check (with a 3-tier fallback matching VanPOS_Admin_Add_Order), then
	 * re-inserts the old rows if the check fails. If it passes, the old rows
	 * stay deleted — change_dates() will insert new ones after updating.
	 *
	 * @param int    $product_id  WC product ID.
	 * @param int    $order_id    Current order ID to exclude.
	 * @param string $pickup_date Pickup date (Y-m-d).
	 * @param string $pickup_time Pickup time slot.
	 * @param string $return_date Return date (Y-m-d).
	 * @param string $return_time Return time slot.
	 * @return array { available: bool, message: string, alternatives: array, saved_rows: array }
	 */
	private static function check_availability_excluding_order( $product_id, $order_id, $pickup_date, $pickup_time, $return_date, $return_time ) {
		// Save and temporarily remove this order's Kestrel rows so the van
		// doesn't block itself when dates overlap.
		$saved_rows = self::get_kestrel_rows( $order_id );
		if ( ! empty( $saved_rows ) ) {
			self::remove_kestrel_reservation( $order_id );
		}

		try {
			$result = self::check_product_availability( $product_id, $pickup_date, $pickup_time, $return_date, $return_time );
		} catch ( Exception $e ) {
			// If the availability check throws, restore rows and report unavailable
			if ( ! empty( $saved_rows ) ) {
				self::restore_kestrel_rows( $saved_rows );
			}
			return array(
				'available'    => false,
				'message'      => __( 'Availability check failed. Please try again.', 'vanjorn-rental-pos' ),
				'alternatives' => array(),
				'saved_rows'   => array(),
			);
		}

		// If unavailable, restore the old rows (the change won't proceed)
		if ( ! $result['available'] && ! empty( $saved_rows ) ) {
			self::restore_kestrel_rows( $saved_rows );
		}
		// If available, old rows stay deleted — change_dates() inserts new ones.

		// Pass saved_rows back so change_dates() can restore them if the
		// subsequent Kestrel insert fails.
		$result['saved_rows'] = $result['available'] ? $saved_rows : array();

		return $result;
	}

	/**
	 * 3-tier availability check matching VanPOS_Admin_Add_Order::check_product_availability().
	 *
	 * @param int    $product_id  WC product ID.
	 * @param string $pickup_date Pickup date (Y-m-d).
	 * @param string $pickup_time Pickup time slot.
	 * @param string $return_date Return date (Y-m-d).
	 * @param string $return_time Return time slot.
	 * @return array { available: bool, message: string, alternatives: array }
	 */
	private static function check_product_availability( $product_id, $pickup_date, $pickup_time, $return_date, $return_time ) {
		// Primary: VanPOS_Availability_Manager (richest response with alternatives)
		if ( class_exists( 'VanPOS_Availability_Manager' ) ) {
			$result = VanPOS_Availability_Manager::check_availability(
				$product_id,
				$pickup_date,
				$pickup_time,
				$return_date,
				$return_time
			);
			return array(
				'available'    => (bool) $result['available'],
				'message'      => $result['available']
					? __( 'Van is available for the selected dates.', 'vanjorn-rental-pos' )
					: ( ! empty( $result['message'] ) ? $result['message'] : __( 'Van is not available for the selected dates.', 'vanjorn-rental-pos' ) ),
				'alternatives' => isset( $result['alternatives'] ) ? $result['alternatives'] : array(),
			);
		}

		// Fallback: VanPOS_Functions
		if ( method_exists( 'VanPOS_Functions', 'check_rental_availability' ) ) {
			$status = VanPOS_Functions::check_rental_availability( $product_id, $pickup_date, $return_date, 1 );
			return array(
				'available'    => ( 'available' === $status ),
				'message'      => ( 'available' === $status )
					? __( 'Van is available for the selected dates.', 'vanjorn-rental-pos' )
					: __( 'Van is not available for the selected dates.', 'vanjorn-rental-pos' ),
				'alternatives' => array(),
			);
		}

		// No availability checker — allow by default
		return array(
			'available'    => true,
			'message'      => __( 'Availability check not available — proceeding.', 'vanjorn-rental-pos' ),
			'alternatives' => array(),
		);
	}

	/**
	 * Read-only availability check for previews.
	 *
	 * Unlike check_availability_excluding_order() (which leaves rows deleted
	 * on success for the subsequent reservation insert), this method ALWAYS
	 * restores the original Kestrel rows regardless of the result.
	 *
	 * Used by VanPOS_Admin_Modify_Booking::ajax_preview() so the preview
	 * doesn't share duplicated availability logic.
	 *
	 * @param int    $product_id  WC product ID.
	 * @param int    $order_id    Current order ID to exclude.
	 * @param string $pickup_date Pickup date (Y-m-d).
	 * @param string $pickup_time Pickup time slot.
	 * @param string $return_date Return date (Y-m-d).
	 * @param string $return_time Return time slot.
	 * @return array { available: bool, message: string }
	 */
	public static function check_preview_availability( $product_id, $order_id, $pickup_date, $pickup_time, $return_date, $return_time ) {
		$saved_rows = self::get_kestrel_rows( $order_id );
		if ( ! empty( $saved_rows ) ) {
			self::remove_kestrel_reservation( $order_id );
		}

		try {
			$result = self::check_product_availability( $product_id, $pickup_date, $pickup_time, $return_date, $return_time );
		} catch ( Exception $e ) {
			$result = array(
				'available' => false,
				'message'   => __( 'Availability check failed. Please try again.', 'vanjorn-rental-pos' ),
			);
		}

		// Always restore — preview is read-only, never commits row changes.
		if ( ! empty( $saved_rows ) ) {
			self::restore_kestrel_rows( $saved_rows );
		}

		return $result;
	}

	/* ═════════════════════ Kestrel Reservation Helpers ═══════════════════ */

	/**
	 * Get existing Kestrel reservation rows for an order.
	 *
	 * @param int $order_id WC order ID.
	 * @return array Raw rows from the Kestrel table.
	 */
	private static function get_kestrel_rows( $order_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wcrp_rental_products_rentals';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $order_id ),
			ARRAY_A
		);
	}

	/**
	 * Restore previously saved Kestrel rows (rollback after failed availability check).
	 *
	 * @param array $rows Rows from get_kestrel_rows().
	 * @return int Number of rows successfully restored.
	 */
	private static function restore_kestrel_rows( $rows ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'wcrp_rental_products_rentals';
		$restored = 0;

		foreach ( $rows as $row ) {
			// Remove any auto-increment ID before re-inserting
			unset( $row['id'] );
			$result = $wpdb->insert( $table, $row );

			if ( false === $result ) {
				error_log( sprintf(
					'[VanPOS] Failed to restore Kestrel reservation row for order %d, date %s: %s',
					isset( $row['order_id'] ) ? $row['order_id'] : 0,
					isset( $row['reserved_date'] ) ? $row['reserved_date'] : '?',
					$wpdb->last_error
				) );
			} else {
				$restored++;
			}
		}

		return $restored;
	}

	/**
	 * Remove all Kestrel reservation rows for an order.
	 *
	 * @param int $order_id WC order ID.
	 */
	private static function remove_kestrel_reservation( $order_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wcrp_rental_products_rentals';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$wpdb->delete( $table, array( 'order_id' => $order_id ) );
	}

	/**
	 * Insert day-by-day Kestrel reservation rows for an order.
	 *
	 * Mirrors VanPOS_Admin_Add_Order::create_kestrel_reservation().
	 *
	 * @param int    $order_id   WC order ID.
	 * @param int    $product_id WC product ID.
	 * @param string $from       Pickup date (Y-m-d).
	 * @param string $to         Return date (Y-m-d).
	 * @return int Number of reservation rows inserted (0 on failure or missing data).
	 */
	private static function create_kestrel_reservation( $order_id, $product_id, $from, $to ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wcrp_rental_products_rentals';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		if ( ! $product_id || ! $from || ! $to ) {
			return 0;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}

		// Find the line item ID for this product in the order
		$item_id = 0;
		foreach ( $order->get_items() as $oi ) {
			if ( (int) $oi->get_product_id() === (int) $product_id ) {
				$item_id = $oi->get_id();
				break;
			}
		}
		if ( ! $item_id ) {
			return 0;
		}

		// Insert one row per day (exclusive of return date, same as importer)
		$inserted = 0;
		$d        = new DateTime( $from );
		$end      = new DateTime( $to );
		while ( $d < $end ) {
			$result = $wpdb->insert( $table, array(
				'reserved_date' => $d->format( 'Y-m-d' ),
				'order_id'      => $order_id,
				'order_item_id' => $item_id,
				'product_id'    => $product_id,
				'quantity'      => 1,
			) );
			if ( false !== $result ) {
				$inserted++;
			}
			$d->modify( '+1 day' );
		}

		return $inserted;
	}

	/* ═════════════════════ Item-Level Meta Update ════════════════════════ */

	/**
	 * Update item-level meta on the order's product line item.
	 *
	 * Mirrors the item meta written by VanPOS_Admin_Add_Order::ajax_create_order().
	 *
	 * @param WC_Order $order            Order object.
	 * @param int      $product_id       WC product ID.
	 * @param string   $pickup_date      New pickup date (Y-m-d).
	 * @param string   $return_date      New return date (Y-m-d).
	 * @param string   $pickup_time      New pickup time slot.
	 * @param string   $return_time      New return time slot.
	 * @param int      $days             Rental days.
	 * @param float    $total_price      New total price.
	 * @param float    $remaining_amount New remaining amount.
	 */
	private static function update_item_meta( $order, $product_id, $pickup_date, $return_date, $pickup_time, $return_time, $days, $total_price, $remaining_amount ) {
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() !== (int) $product_id ) {
				continue;
			}

			// Core rental meta
			$item->update_meta_data( 'vanpos_pickup_date', $pickup_date );
			$item->update_meta_data( 'vanpos_return_date', $return_date );
			$item->update_meta_data( 'vanpos_pickup_time', $pickup_time );
			$item->update_meta_data( 'vanpos_return_time', $return_time );
			$item->update_meta_data( 'vanpos_rental_days', $days );
			$item->update_meta_data( '_vanpos_original_price', $total_price );
			$item->update_meta_data( '_vanpos_remaining_amount', $remaining_amount );

			// Kestrel rental-products compatibility fields
			$item->update_meta_data( 'wcrp_rental_products_rent_from', $pickup_date );
			$item->update_meta_data( 'wcrp_rental_products_rent_to', $return_date );

			$item->save();
			break; // Primary rental orders have a single product line item
		}
	}

	/* ═════════════════════ Line Item Product Swap ════════════════════════ */

	/**
	 * Swap the product on the order's primary line item.
	 *
	 * Updates the product reference, item name, and product object
	 * on the WC_Order_Item_Product. Called when an admin changes the
	 * van on an existing booking.
	 *
	 * @param WC_Order $order          Order object.
	 * @param int      $old_product_id Current product ID on the line item.
	 * @param int      $new_product_id New product ID to assign.
	 * @return bool True if swap succeeded.
	 */
	private static function swap_line_item_product( $order, $old_product_id, $new_product_id ) {
		$new_product = wc_get_product( $new_product_id );
		if ( ! $new_product ) {
			return false;
		}

		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() !== (int) $old_product_id ) {
				continue;
			}

			$item->set_product_id( $new_product_id );
			$item->set_name( $new_product->get_name() );

			// Update the product object reference if WC supports it
			if ( method_exists( $item, 'set_product' ) ) {
				$item->set_product( $new_product );
			}

			// Item is not saved here — update_item_meta() is called
			// immediately after swap_line_item_product() in change_dates()
			// and will save the same item with all meta updates in one pass.
			break;
		}

		return true;
	}

	/* ═════════════════════ Child Order Updates ═══════════════════════════ */

	/**
	 * Update existing child orders when the parent booking dates change.
	 *
	 * For every child order this method:
	 *  - Copies the new pickup / return dates from the parent.
	 *  - Recalculates the payment due date using admin settings.
	 *  - Resets AutomateWoo reminder flags so new reminder workflows
	 *    can fire against the updated due date.
	 *  - Updates formatted meta for email templates.
	 *  - (Remaining payment only) Updates the order total with proper
	 *    VAT breakdown when the rental price has changed.
	 *
	 * @param int             $order_id            Primary order ID.
	 * @param string          $new_pickup_date     New pickup date (Y-m-d).
	 * @param string          $new_return_date     New return date (Y-m-d).
	 * @param string          $new_pickup_time     New pickup time slot.
	 * @param string          $new_return_time     New return time slot.
	 * @param float           $new_remaining_amount New remaining amount (0 if unchanged).
	 * @param float           $new_total_price     New total rental price.
	 * @param WC_Product|null $product             The rental product (for name/VAT rebuild).
	 * @return void
	 */
	private static function update_existing_child_orders( $order_id, $new_pickup_date, $new_return_date, $new_pickup_time, $new_return_time, $new_remaining_amount, $new_total_price, $product ) {
		if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
			return;
		}

		$child_orders = VanPOS_Order_Manager::get_payment_orders( $order_id );
		if ( empty( $child_orders ) ) {
			return;
		}

		// Parent initial payment is unchanged by a date edit, but propagate it so
		// child copies (and their formatted variants) stay in sync on every order.
		$parent_order_for_initial = wc_get_order( $order_id );
		$parent_initial_payment   = $parent_order_for_initial ? (float) $parent_order_for_initial->get_meta( '_vanpos_initial_payment' ) : 0;

		foreach ( $child_orders as $child_order ) {
			// Skip WC_Order_Refund objects — get_payment_orders() may return them
			// alongside regular orders; refunds don't implement add_order_note().
			if ( $child_order instanceof WC_Order_Refund ) {
				continue;
			}

			// Skip child orders in terminal statuses — no point updating
			// dates or meta on orders that are already cancelled or refunded.
			if ( $child_order->has_status( array( 'cancelled', 'refunded' ) ) ) {
				continue;
			}

			$payment_type = $child_order->get_meta( '_vanpos_payment_type' );

			// --- 1. Update rental dates ---
			$child_order->update_meta_data( '_vanpos_pickup_date', $new_pickup_date );
			$child_order->update_meta_data( '_vanpos_return_date', $new_return_date );
			if ( $new_pickup_time ) {
				$child_order->update_meta_data( '_vanpos_pickup_time', $new_pickup_time );
			}
			if ( $new_return_time ) {
				$child_order->update_meta_data( '_vanpos_return_time', $new_return_time );
			}

			// --- 2. Recalculate due date ---
			$new_due_date = self::calculate_child_due_date( $child_order, $new_pickup_date, $payment_type );
			if ( $new_due_date ) {
				$child_order->update_meta_data( '_vanpos_due_date', $new_due_date );
				$child_order->update_meta_data( '_payment_due_date', $new_due_date );

				// Update formatted due date for email templates
				$child_order->update_meta_data( '_payment_due_date_formatted', date_i18n( 'd-m-Y', strtotime( $new_due_date ) ) );
			}

			// --- 3. Reset AutomateWoo reminder flags ---
			$child_order->update_meta_data( '_reminder_1_sent', 'no' );
			$child_order->update_meta_data( '_reminder_2_sent', 'no' );

			// --- 4. Update formatted meta for email templates ---
			// Fixed DD-MM-YYYY format — see comment in change_dates().
			if ( $product ) {
				$child_order->update_meta_data( '_vanpos_camper_name', $product->get_name() );
			}
			$child_order->update_meta_data( '_vanpos_pickup_date_formatted', date_i18n( 'd-m-Y', strtotime( $new_pickup_date ) ) );
			$child_order->update_meta_data( '_vanpos_return_date_formatted', date_i18n( 'd-m-Y', strtotime( $new_return_date ) ) );

			$child_order->update_meta_data( '_vanpos_total_price', $new_total_price );
			$child_order->update_meta_data( '_vanpos_total_price_formatted', wp_strip_all_tags( wc_price( $new_total_price ) ) );
			$child_order->update_meta_data( '_vanpos_initial_payment', $parent_initial_payment );
			$child_order->update_meta_data( '_vanpos_initial_payment_formatted', wp_strip_all_tags( wc_price( $parent_initial_payment ) ) );
			$child_order->update_meta_data( '_vanpos_remaining_payment', $new_remaining_amount );
			$child_order->update_meta_data( '_vanpos_remaining_payment_formatted', wp_strip_all_tags( wc_price( $new_remaining_amount ) ) );

			// --- 5. Update remaining payment amount with proper VAT if changed ---
			if ( $new_remaining_amount > 0 && self::is_remaining_payment( $payment_type ) ) {
				self::update_child_order_amount( $child_order, $new_remaining_amount, $product );
			}

			// --- 6. Update line item meta on remaining payment children ---
			// Security_deposit children carry their own deposit product line
			// item (not the rental van), so skip — they don't represent a
			// rental booking. For all other payment types (remaining, deposit,
			// extension), propagate the new dates to the line item's vanpos_*
			// meta and the Kestrel-compatibility fields so the frontend
			// calendar query and email templates see consistent values.
			if ( 'security_deposit' !== $payment_type ) {
				foreach ( $child_order->get_items() as $child_item ) {
					if ( ! is_a( $child_item, 'WC_Order_Item_Product' ) ) {
						continue;
					}

					// Core rental meta
					$child_item->update_meta_data( 'vanpos_pickup_date', $new_pickup_date );
					$child_item->update_meta_data( 'vanpos_return_date', $new_return_date );
					if ( $new_pickup_time ) {
						$child_item->update_meta_data( 'vanpos_pickup_time', $new_pickup_time );
					}
					if ( $new_return_time ) {
						$child_item->update_meta_data( 'vanpos_return_time', $new_return_time );
					}

					// Kestrel rental-products compatibility fields
					$child_item->update_meta_data( 'wcrp_rental_products_rent_from', $new_pickup_date );
					$child_item->update_meta_data( 'wcrp_rental_products_rent_to', $new_return_date );

					$child_item->save();
				}
			}

			// Add a note so admin can see the date change was propagated
			$child_order->add_order_note(
				sprintf(
					/* translators: %1$s is the new pickup date, %2$s is the new return date */
					__( 'Dates updated from parent order. New pickup: %1$s, new return: %2$s.', 'vanjorn-rental-pos' ),
					$new_pickup_date,
					$new_return_date
				)
			);

			$child_order->save();
		}
	}

	/**
	 * Calculate the due date for a child order based on its payment type.
	 *
	 * Short-term deposit orders keep their original due date (order date + 1 day)
	 * because that deadline is based on the order creation date, not the pickup date.
	 *
	 * @param WC_Order $child_order   Child order object.
	 * @param string   $new_pickup_date New pickup date (Y-m-d).
	 * @param string   $payment_type  Payment type string.
	 * @return string|null Due date in Y-m-d format, or null if not calculable.
	 */
	private static function calculate_child_due_date( $child_order, $new_pickup_date, $payment_type ) {
		// Short-term deposit due dates are relative to the order creation
		// date, not the pickup date. Don't recalculate them.
		if ( $child_order->get_meta( '_is_short_term_deposit' ) === 'yes' ) {
			return null;
		}

		if ( ! class_exists( 'VanPOS_Functions' ) ) {
			return null;
		}

		if ( self::is_remaining_payment( $payment_type ) ) {
			return VanPOS_Functions::calculate_due_date_from_pickup( $new_pickup_date, 'remaining' );
		}

		if ( self::is_security_deposit( $payment_type ) ) {
			return VanPOS_Functions::calculate_due_date_from_pickup( $new_pickup_date, 'security_deposit' );
		}

		return null;
	}

	/**
	 * Update the total amount on an unpaid child order with proper VAT breakdown.
	 *
	 * If the order has already been paid we leave the total alone —
	 * any price difference is handled by the extension / refund logic
	 * in change_dates().
	 *
	 * v2: Now rebuilds line items with correct ex-VAT / tax split,
	 *     matching VanPOS_Admin_Add_Order::enrich_child_order().
	 *
	 * @param WC_Order        $child_order Child order object.
	 * @param float           $new_amount  New total amount (inc. VAT).
	 * @param WC_Product|null $product     The rental product (for line item rebuild).
	 * @return void
	 */
	private static function update_child_order_amount( $child_order, $new_amount, $product = null ) {
		// Don't touch paid, cancelled, or refunded orders
		$is_settled = $child_order->get_date_paid()
			|| $child_order->has_status( array( 'processing', 'completed', 'cancelled', 'refunded' ) );

		if ( $is_settled ) {
			return;
		}

		$old_amount = (float) $child_order->get_total();

		// Only update if the amount actually changed
		if ( abs( $old_amount - $new_amount ) < 0.01 ) {
			return;
		}

		// VAT breakdown — derive the rate dynamically from WooCommerce
		// Capture the tax rate ID from existing items before removing them
		$tax_rate_id  = self::get_vat_rate_id( $child_order );
		$tax_rate_pct = self::get_tax_rate_percentage( $tax_rate_id );

		if ( $tax_rate_pct > 0 ) {
			$child_excl = round( $new_amount / ( 1 + $tax_rate_pct ), 2 );
			$child_tax  = round( $new_amount - $child_excl, 2 );
		} else {
			// No tax rate found — treat as tax-exempt
			$child_excl = $new_amount;
			$child_tax  = 0;
		}

		// Remove all existing line items and tax items
		foreach ( $child_order->get_items() as $old_item ) {
			$child_order->remove_item( $old_item->get_id() );
		}
		foreach ( $child_order->get_items( 'tax' ) as $old_tax ) {
			$child_order->remove_item( $old_tax->get_id() );
		}

		// Add a properly structured product item with correct VAT split
		$item = new WC_Order_Item_Product();
		if ( $product ) {
			$item->set_product( $product );
			$item->set_name( $product->get_name() );
		}
		$item->set_quantity( 1 );
		$item->set_subtotal( $child_excl );
		$item->set_total( $child_excl );
		$item->set_subtotal_tax( $child_tax );
		$item->set_total_tax( $child_tax );
		$item->set_taxes( array(
			'total'    => array( $tax_rate_id => $child_tax ),
			'subtotal' => array( $tax_rate_id => $child_tax ),
		) );
		$child_order->add_item( $item );

		// Add explicit tax item row
		$tax_item = new WC_Order_Item_Tax();
		$rate_label = class_exists( 'WC_Tax' ) ? WC_Tax::get_rate_label( $tax_rate_id ) : '';
		if ( ! $rate_label ) {
			/* translators: %d is the WooCommerce tax rate ID */
			$rate_label = sprintf( __( 'VAT-%d', 'vanjorn-rental-pos' ), $tax_rate_id );
		}
		$tax_item->set_name( $rate_label );
		$tax_item->set_rate_id( $tax_rate_id );
		$tax_item->set_tax_total( $child_tax );
		$tax_item->set_shipping_tax_total( 0 );
		$child_order->add_item( $tax_item );

		// Set order total explicitly
		$child_order->set_total( $new_amount );

		$child_order->add_order_note(
			sprintf(
				/* translators: %1$s is the old amount, %2$s is the new amount */
				__( 'Remaining payment updated from %1$s to %2$s due to date change.', 'vanjorn-rental-pos' ),
				wc_price( $old_amount ),
				wc_price( $new_amount )
			)
		);
	}

	/* ═════════════════════ Price Decrease / Refund Routing ══════════════ */

	/**
	 * Handle a price decrease caused by a rental shortening.
	 *
	 * Instead of issuing automatic refunds, this method adds a detailed order
	 * note so support staff can review the situation and process refunds manually.
	 * The child order is still cancelled when it is no longer required (unpaid
	 * and raw_remaining <= 0) — that is a lifecycle action, not a financial one.
	 *
	 * Cases:
	 *  - No remaining child → note on parent: refund entire decrease from parent.
	 *  - Remaining UNPAID, raw_remaining > 0 → do nothing here; amount is reduced
	 *    by update_existing_child_orders(). No money needs to come back.
	 *  - Remaining UNPAID, raw_remaining <= 0 → cancel child; if initial
	 *    overpays the new total, note on parent with the overpayment amount.
	 *  - Remaining PAID, raw_remaining >= 0 → note on parent: partial refund
	 *    from the remaining child.
	 *  - Remaining PAID, raw_remaining < 0 → note on parent: full refund from
	 *    child plus overpayment from parent.
	 *
	 * @param int   $order_id       Primary order ID.
	 * @param float $price_decrease Absolute price decrease (positive number).
	 * @param float $raw_remaining  Unclamped remaining (new_total − initial_payment; can be negative).
	 * @param float $old_total      Booking total before the change.
	 * @param float $new_total      Booking total after the change.
	 */
	private static function handle_price_decrease( $order_id, $price_decrease, $raw_remaining, $old_total, $new_total ) {
		$parent          = wc_get_order( $order_id );
		$remaining_child = self::find_remaining_child_order( $order_id );

		// Shorthand: format a monetary amount for plain-text order notes.
		$fmt = static function ( $amount ) {
			return wp_strip_all_tags( wc_price( $amount ) );
		};

		// Shared header used in every note that flags a potential refund.
		$warning_header = sprintf(
			/* translators: 1: old booking total, 2: new booking total, 3: decrease amount */
			__( 'Rental shortened — manual refund check required. Booking total: %1$s → %2$s (decrease: %3$s).', 'vanjorn-rental-pos' ),
			$fmt( $old_total ),
			$fmt( $new_total ),
			$fmt( $price_decrease )
		);

		// ── No remaining child ────────────────────────────────────────────────
		if ( ! $remaining_child ) {
			if ( $parent ) {
				$parent->add_order_note(
					$warning_header . "\n" . sprintf(
						/* translators: %s: refund amount */
						__( 'No remaining payment order found. Consider issuing a refund of %s from this order.', 'vanjorn-rental-pos' ),
						$fmt( $price_decrease )
					),
					false, false
				);
			}
			self::warn_if_paid_extensions_exist( $order_id );
			return;
		}

		$is_paid         = $remaining_child->get_date_paid()
			|| $remaining_child->has_status( array( 'processing', 'completed' ) );
		$old_child_total = (float) $remaining_child->get_total();
		$child_number    = $remaining_child->get_order_number();

		if ( ! $is_paid ) {
			// ── Remaining is unpaid ───────────────────────────────────────────
			if ( $raw_remaining <= 0 ) {
				// Cancel the child — it is no longer required.
				$remaining_child->set_status( 'cancelled' );
				$remaining_child->add_order_note(
					__( 'Cancelled: remaining payment no longer required after rental was shortened.', 'vanjorn-rental-pos' )
				);
				$remaining_child->save();

				if ( $parent ) {
					if ( $raw_remaining < -0.01 ) {
						// Initial payment overshoots the new total.
						$initial_payment = (float) $parent->get_meta( '_vanpos_initial_payment' );
						$parent->add_order_note(
							$warning_header . "\n" . sprintf(
								/* translators: 1: child order number, 2: initial payment amount, 3: overpayment amount */
								__( 'Remaining payment order #%1$s has been cancelled. Initial payment (%2$s) exceeds the new total by %3$s. Consider issuing a refund of %3$s from this order.', 'vanjorn-rental-pos' ),
								$child_number,
								$fmt( $initial_payment ),
								$fmt( abs( $raw_remaining ) )
							),
							false, false
						);
					} else {
						// raw_remaining == 0: child cancelled, initial payment exactly covers new total.
						$parent->add_order_note(
							sprintf(
								/* translators: 1: old total, 2: new total, 3: decrease amount, 4: child order number */
								__( 'Rental shortened. Booking total: %1$s → %2$s (decrease: %3$s). Remaining payment order #%4$s has been cancelled. Initial payment exactly covers the new total — no refund required.', 'vanjorn-rental-pos' ),
								$fmt( $old_total ),
								$fmt( $new_total ),
								$fmt( $price_decrease ),
								$child_number
							),
							false, false
						);
					}
				}
			}
			// raw_remaining > 0: child amount reduced by update_existing_child_orders().
			// No money needs to come back — no note required here.

		} else {
			// ── Remaining is already paid ─────────────────────────────────────
			if ( $raw_remaining >= 0 ) {
				$refund_from_child = $old_child_total - max( 0.0, (float) $raw_remaining );
				if ( $refund_from_child > 0.01 && $parent ) {
					$parent->add_order_note(
						$warning_header . "\n" . sprintf(
							/* translators: 1: child order number, 2: paid amount, 3: refund amount */
							__( 'Remaining payment order #%1$s was paid (%2$s). Consider issuing a partial refund of %3$s from order #%1$s.', 'vanjorn-rental-pos' ),
							$child_number,
							$fmt( $old_child_total ),
							$fmt( $refund_from_child )
						),
						false, false
					);
				}
			} else {
				// Initial payment also overshoots: full child refund + excess from parent.
				if ( $parent ) {
					$initial_payment = (float) $parent->get_meta( '_vanpos_initial_payment' );
					$parent->add_order_note(
						$warning_header . "\n" . sprintf(
							/* translators: 1: child order number, 2: child total, 3: initial payment, 4: overpayment */
							__( 'Remaining payment order #%1$s was paid (%2$s) — full refund recommended. Initial payment (%3$s) also exceeds the new total by %4$s. Consider refunding %2$s from order #%1$s and %4$s from this order.', 'vanjorn-rental-pos' ),
							$child_number,
							$fmt( $old_child_total ),
							$fmt( $initial_payment ),
							$fmt( abs( $raw_remaining ) )
						),
						false, false
					);
				}
			}
		}

		self::warn_if_paid_extensions_exist( $order_id );
	}

	/**
	 * Add a parent order note if paid extension orders exist that may
	 * need manual refund attention after a price decrease.
	 *
	 * @param int $order_id Primary order ID.
	 */
	private static function warn_if_paid_extensions_exist( $order_id ) {
		if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
			return;
		}

		$child_orders = VanPOS_Order_Manager::get_payment_orders( $order_id );
		$paid_extensions = array();

		foreach ( $child_orders as $child ) {
			if ( $child->get_meta( '_vanpos_payment_type' ) !== 'extension' ) {
				continue;
			}
			if ( $child->has_status( array( 'cancelled', 'refunded' ) ) ) {
				continue;
			}
			if ( $child->get_date_paid() || $child->has_status( array( 'processing', 'completed' ) ) ) {
				$paid_extensions[] = $child;
			}
		}

		if ( ! empty( $paid_extensions ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$ext_refs = array();
				foreach ( $paid_extensions as $ext ) {
					$ext_refs[] = '#' . $ext->get_order_number() . ' (' . wp_strip_all_tags( wc_price( $ext->get_total() ) ) . ')';
				}
				$order->add_order_note(
					sprintf(
						/* translators: %s is a comma-separated list of extension order references */
						__( 'Note: paid extension order(s) %s exist from prior date changes. Review whether a manual refund is needed.', 'vanjorn-rental-pos' ),
						implode( ', ', $ext_refs )
					),
					false,
					true
				);
			}
		}
	}

	/**
	 * Find the remaining payment child order for a primary order.
	 *
	 * @param int $order_id Primary order ID.
	 * @return WC_Order|null The remaining child order, or null.
	 */
	private static function find_remaining_child_order( $order_id ) {
		if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
			return null;
		}

		$child_orders = VanPOS_Order_Manager::get_payment_orders( $order_id );
		foreach ( $child_orders as $child ) {
			$payment_type = $child->get_meta( '_vanpos_payment_type' );
			if ( self::is_remaining_payment( $payment_type ) ) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * Cancel any unpaid extension orders from prior date changes.
	 *
	 * When dates are changed multiple times, each change used to create
	 * a new extension order. This method cancels stale unpaid extensions
	 * so they don't pile up. Paid extensions are left untouched — their
	 * money has already been collected.
	 *
	 * @param int $order_id Primary order ID.
	 * @return float Total amount of cancelled extensions (for logging).
	 */
	private static function cancel_pending_extensions( $order_id ) {
		if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
			return 0;
		}

		$cancelled_total = 0;
		$child_orders = VanPOS_Order_Manager::get_payment_orders( $order_id );

		foreach ( $child_orders as $child ) {
			if ( $child->get_meta( '_vanpos_payment_type' ) !== 'extension' ) {
				continue;
			}

			// Skip already-settled orders
			if ( $child->get_date_paid()
				|| $child->has_status( array( 'processing', 'completed', 'cancelled', 'refunded' ) ) ) {
				continue;
			}

			$amount = (float) $child->get_total();
			$child->set_status( 'cancelled' );
			$child->add_order_note(
				__( 'Cancelled: superseded by a new date change.', 'vanjorn-rental-pos' )
			);
			$child->save();

			$cancelled_total += $amount;
		}

		return $cancelled_total;
	}

	/* ═════════════════════ Payment Type Helpers ══════════════════════════ */

	/**
	 * Check if a payment type is a remaining payment.
	 *
	 * @param string $payment_type Payment type.
	 * @return bool
	 */
	private static function is_remaining_payment( $payment_type ) {
		return in_array( $payment_type, array( 'remaining', 'second_payment' ), true );
	}

	/**
	 * Check if a payment type is a security deposit.
	 *
	 * @param string $payment_type Payment type.
	 * @return bool
	 */
	private static function is_security_deposit( $payment_type ) {
		// Only the refundable security deposit matches here.
		// 'deposit' is the 50% initial rental payment — not the same thing.
		return $payment_type === 'security_deposit';
	}

	/* ═════════════════════ Public Convenience Methods ════════════════════ */

	/**
	 * Extend rental period
	 *
	 * @param int    $order_id Primary order ID.
	 * @param string $new_return_date New return date (Y-m-d).
	 * @param string $new_return_time New return time slot.
	 * @param bool   $override_availability Skip availability check.
	 * @return bool|WP_Error
	 */
	public static function extend_rental( $order_id, $new_return_date, $new_return_time, $override_availability = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'invalid_order', __( 'Invalid order.', 'vanjorn-rental-pos' ) );
		}

		$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
		$pickup_time = $order->get_meta( '_vanpos_pickup_time' );

		return self::change_dates( $order_id, $pickup_date, $pickup_time, $new_return_date, $new_return_time, $override_availability );
	}

	/**
	 * Create refund
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount Refund amount.
	 * @param string $reason Refund reason.
	 * @return int|WP_Error Refund ID or error.
	 */
	public static function create_refund( $order_id, $amount, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'invalid_order', __( 'Invalid order.', 'vanjorn-rental-pos' ) );
		}

		$refund = wc_create_refund( array(
			'order_id' => $order_id,
			'amount'   => $amount,
			'reason'   => $reason,
		) );

		return $refund;
	}

	/**
	 * Process deposit refund
	 *
	 * @param int    $order_id Primary order ID.
	 * @param float  $amount Refund amount (default: full deposit).
	 * @param string $reason Refund reason.
	 * @return int|WP_Error Refund ID or error.
	 */
	public static function refund_deposit( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'invalid_order', __( 'Invalid order.', 'vanjorn-rental-pos' ) );
		}

		// Find security deposit order
		$payment_orders = VanPOS_Order_Manager::get_payment_orders( $order_id );
		$deposit_order = null;
		foreach ( $payment_orders as $payment_order ) {
			$pt = $payment_order->get_meta( '_vanpos_payment_type' );
			if ( $pt === 'security_deposit' ) {
				$deposit_order = $payment_order;
				break;
			}
		}

		if ( ! $deposit_order ) {
			return new WP_Error( 'no_deposit', __( 'No security deposit order found.', 'vanjorn-rental-pos' ) );
		}

		if ( $amount === null ) {
			$amount = $deposit_order->get_total();
		}

		return self::create_refund( $deposit_order->get_id(), $amount, $reason ?: __( 'Deposit refund after return', 'vanjorn-rental-pos' ) );
	}
}
