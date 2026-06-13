<?php
/**
 * Sync VanPOS availability with Kestrel “Mark as returned” on rental line items.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When staff mark a rental line item as returned (WooCommerce order screen),
 * VanPOS must stop treating that booking as blocking the van — immediately.
 */
class VanPOS_Rental_Returned {

	/**
	 * Prevent duplicate work when Kestrel saves item meta and WC saves the item.
	 *
	 * @var array<int, bool>
	 */
	private static $handled_items = array();

	/**
	 * Order item meta keys Kestrel Rental Products uses for returned rentals.
	 *
	 * @var string[]
	 */
	private static $returned_meta_keys = array(
		'wcrp_rental_products_returned',
		'_wcrp_rental_products_returned',
		'wcrp_rental_products_rental_returned',
		'_vanpos_vehicle_returned',
	);

	/**
	 * Order item meta keys for cancelled rentals (also free stock in live view).
	 *
	 * @var string[]
	 */
	private static $cancelled_meta_keys = array(
		'wcrp_rental_products_cancelled',
		'_wcrp_rental_products_cancelled',
		'wcrp_rental_products_rental_cancelled',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_order_item_meta_added', array( __CLASS__, 'on_order_item_meta_change' ), 10, 4 );
		add_action( 'woocommerce_order_item_meta_updated', array( __CLASS__, 'on_order_item_meta_change' ), 10, 4 );
		add_action( 'woocommerce_before_save_order_item', array( __CLASS__, 'on_before_save_order_item' ), 10, 1 );
		add_action( 'wp_ajax_vanpos_dashboard_mark_returned', array( __CLASS__, 'ajax_dashboard_mark_returned' ) );
	}

	/**
	 * Kestrel meta key written when a rental is marked returned (order line + dashboard).
	 *
	 * @return string
	 */
	public static function kestrel_returned_meta_key() {
		return 'wcrp_rental_products_returned';
	}

	/**
	 * Primary rental line item on a main booking order (skips security-deposit product).
	 *
	 * @param WC_Order $order Order.
	 * @return int Order item ID or 0.
	 */
	public static function get_primary_rental_line_item_id( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return 0;
		}

		$deposit_pid = class_exists( 'VanPOS_Functions' )
			? (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' )
			: 0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$pid = (int) $item->get_variation_id();
			if ( ! $pid ) {
				$pid = (int) $item->get_product_id();
			}
			if ( $pid <= 0 ) {
				continue;
			}

			if ( $deposit_pid > 0 && class_exists( 'VanPOS_Functions' ) ) {
				$orig = VanPOS_Functions::get_original_product_id( $pid );
				if ( $orig === $deposit_pid ) {
					continue;
				}
			}

			$rent_from = $item->get_meta( 'wcrp_rental_products_rent_from' );
			$rent_to   = $item->get_meta( 'wcrp_rental_products_rent_to' );
			if ( $rent_from || $rent_to || $item->get_meta( 'vanpos_pickup_date' ) || $item->get_meta( '_vanpos_original_price' ) ) {
				return (int) $item->get_id();
			}
		}

		// Fallback: first non-deposit line item.
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$pid = (int) $item->get_variation_id();
			if ( ! $pid ) {
				$pid = (int) $item->get_product_id();
			}
			if ( $deposit_pid > 0 && $pid && class_exists( 'VanPOS_Functions' ) ) {
				if ( VanPOS_Functions::get_original_product_id( $pid ) === $deposit_pid ) {
					continue;
				}
			}
			if ( $pid > 0 ) {
				return (int) $item->get_id();
			}
		}

		return 0;
	}

	/**
	 * Whether the primary rental line on this order is already marked returned.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_order_rental_returned( $order ) {
		$item_id = self::get_primary_rental_line_item_id( $order );
		return $item_id > 0 && self::is_rental_line_closed( $item_id );
	}

	/**
	 * Mark the primary rental on an order as returned (dashboard or API).
	 *
	 * CMIT CODE - UPDATED - 15 MAY 2026
	 * Sets Kestrel line-item meta, then VanPOS_Rental_Returned hooks free the van
	 * immediately (availability cache + future Kestrel reservation rows).
	 *
	 * @param int $order_id Primary rental order ID.
	 * @return true|WP_Error
	 */
	public static function mark_order_rental_returned( $order_id ) {
		$order_id = absint( $order_id );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'invalid_order', __( 'Invalid order.', 'vanjorn-rental-pos' ) );
		}

		if ( class_exists( 'VanPOS_Order_Deletion' ) && ! VanPOS_Order_Deletion::is_primary_rental_order( $order ) ) {
			return new WP_Error( 'not_primary_rental', __( 'This order is not a main rental booking.', 'vanjorn-rental-pos' ) );
		}

		$item_id = self::get_primary_rental_line_item_id( $order );
		if ( ! $item_id ) {
			return new WP_Error( 'no_rental_item', __( 'No rental line item found on this order.', 'vanjorn-rental-pos' ) );
		}

		if ( self::is_rental_line_closed( $item_id ) ) {
			return new WP_Error( 'already_returned', __( 'This rental is already marked as returned.', 'vanjorn-rental-pos' ) );
		}

		$meta_key = self::kestrel_returned_meta_key();
		wc_update_order_item_meta( $item_id, $meta_key, 'yes' );

		// Ensure side effects even if WC did not fire our meta hooks (edge caches).
		self::handle_rental_marked_returned( $item_id );

		$user = wp_get_current_user();
		$note = sprintf(
			/* translators: %s: admin display name */
			__( 'Rental marked as returned from VAN-Jorn Returns to process by %s. The van is now available for new bookings.', 'vanjorn-rental-pos' ),
			$user && $user->exists() ? $user->display_name : __( 'staff', 'vanjorn-rental-pos' )
		);
		$order->add_order_note( $note, false, true );

		return true;
	}

	/**
	 * AJAX: Mark as returned from VAN-Jorn Rental POS dashboard list.
	 *
	 * @return void
	 */
	public static function ajax_dashboard_mark_returned() {
		check_ajax_referer( 'vanpos_dashboard_mark_returned', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing order ID.', 'vanjorn-rental-pos' ) ) );
		}

		$result = self::mark_order_rental_returned( $order_id );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'already_returned' === $code ) {
				wp_send_json_success(
					array(
						'message'          => $result->get_error_message(),
						'already_returned' => true,
						'reload_dashboard' => true,
					)
				);
			}
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'          => __( 'Van marked as returned. It is now available on the booking calendar.', 'vanjorn-rental-pos' ),
				'reload_dashboard' => true,
			)
		);
	}

	/**
	 * Meta values that mean “returned” or “cancelled”.
	 *
	 * @param string $value Raw meta value.
	 * @return bool
	 */
	public static function is_truthy_rental_flag( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( 'yes', '1', 'true', 'returned', 'cancelled' ), true );
	}

	/**
	 * Whether a rental line item is marked returned or cancelled in Kestrel meta.
	 *
	 * @param int $order_item_id Order item ID.
	 * @return bool
	 */
	public static function is_rental_line_closed( $order_item_id ) {
		$order_item_id = absint( $order_item_id );
		if ( ! $order_item_id ) {
			return false;
		}

		$item = new WC_Order_Item_Product( $order_item_id );
		if ( ! $item->get_id() ) {
			return false;
		}

		foreach ( array_merge( self::$returned_meta_keys, self::$cancelled_meta_keys ) as $key ) {
			if ( self::is_truthy_rental_flag( $item->get_meta( $key, true ) ) ) {
				return true;
			}
		}

		// VanPOS mirror (set when we detect Kestrel returned).
		if ( self::is_truthy_rental_flag( $item->get_meta( '_vanpos_vehicle_returned', true ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * SQL fragment: exclude order items marked returned/cancelled in Kestrel meta.
	 *
	 * CMIT CODE - UPDATED - 15 MAY 2026
	 * Used by VanPOS_Functions::fetch_active_rental_bookings() so the frontend
	 * calendar and checkout see the van as free as soon as staff click
	 * “Mark as returned” on the order line (Kestrel live inventory behaviour).
	 *
	 * @return string SQL AND ... clause (empty if no keys).
	 */
	public static function sql_exclude_closed_rental_items() {
		$keys = array_merge( self::$returned_meta_keys, self::$cancelled_meta_keys );
		if ( empty( $keys ) ) {
			return '';
		}

		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		return $wpdb->prepare(
			" AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->prefix}woocommerce_order_itemmeta AS closed_meta
				WHERE closed_meta.order_item_id = oi.order_item_id
				AND closed_meta.meta_key IN ({$placeholders})
				AND LOWER( closed_meta.meta_value ) IN ('yes', '1', 'true', 'returned', 'cancelled')
			)",
			$keys
		);
	}

	/**
	 * Clear VanPOS availability transients for the product on this line item.
	 *
	 * @param int $order_item_id Order item ID.
	 * @return void
	 */
	public static function clear_availability_cache_for_item( $order_item_id ) {
		$item = new WC_Order_Item_Product( absint( $order_item_id ) );
		if ( ! $item->get_id() ) {
			return;
		}

		$product_id = (int) $item->get_product_id();
		if ( $product_id && class_exists( 'VanPOS_Functions' ) ) {
			VanPOS_Functions::clear_rental_availability_cache( $product_id );
		}
	}

	/**
	 * After Kestrel marks returned: mirror meta, trim future Kestrel calendar rows, flush cache.
	 *
	 * CMIT CODE - UPDATED - 15 MAY 2026
	 * - Mirror flag for VanPOS admin/dashboard use later.
	 * - Remove reservation rows from today onward so Kestrel’s table matches
	 *   “immediate replenishment” (VanPOS booking query already ignores returned lines).
	 *
	 * @param int $order_item_id Order item ID.
	 * @return void
	 */
	public static function handle_rental_marked_returned( $order_item_id ) {
		$order_item_id = absint( $order_item_id );
		if ( ! $order_item_id || ! empty( self::$handled_items[ $order_item_id ] ) ) {
			return;
		}

		self::$handled_items[ $order_item_id ] = true;

		$item = new WC_Order_Item_Product( $order_item_id );
		if ( ! $item->get_id() ) {
			return;
		}

		$item->update_meta_data( '_vanpos_vehicle_returned', 'yes' );
		$item->update_meta_data( '_vanpos_vehicle_returned_at', current_time( 'mysql' ) );
		$item->save();

		$order_id = (int) $item->get_order_id();
		if ( $order_id ) {
			self::release_kestrel_reservations_from_date( $order_id, current_time( 'Y-m-d' ) );
		}

		self::clear_availability_cache_for_item( $order_item_id );

		// Keep the returns-queue menu badge in sync immediately.
		if ( class_exists( 'VanPOS_Admin_Returns_Queue_Query' ) ) {
			VanPOS_Admin_Returns_Queue_Query::flush_count_cache();
		}
	}

	/**
	 * Delete Kestrel reservation rows on/after a date (van free on calendar table).
	 *
	 * @param int    $order_id WC order ID.
	 * @param string $from_date Y-m-d (usually today when marked returned).
	 * @return void
	 */
	public static function release_kestrel_reservations_from_date( $order_id, $from_date ) {
		$order_id  = absint( $order_id );
		$from_date = sanitize_text_field( $from_date );
		if ( ! $order_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_date ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wcrp_rental_products_rentals';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE order_id = %d AND reserved_date >= %s",
				$order_id,
				$from_date
			)
		);
	}

	/**
	 * React when order item meta changes (Kestrel AJAX mark returned).
	 *
	 * @param int    $item_id   Order item ID.
	 * @param int    $order_id  Order ID.
	 * @param string $meta_key  Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function on_order_item_meta_change( $item_id, $order_id, $meta_key, $meta_value ) {
		unset( $order_id );

		$watch_keys = array_merge(
			self::$returned_meta_keys,
			self::$cancelled_meta_keys
		);
		// Do not react to our own mirror meta (avoids duplicate handle).
		$watch_keys = array_diff( $watch_keys, array( '_vanpos_vehicle_returned', '_vanpos_vehicle_returned_at' ) );

		if ( ! in_array( $meta_key, $watch_keys, true ) ) {
			return;
		}

		if ( ! self::is_truthy_rental_flag( $meta_value ) ) {
			self::clear_availability_cache_for_item( $item_id );
			return;
		}

		if ( in_array( $meta_key, array_diff( self::$returned_meta_keys, array( '_vanpos_vehicle_returned' ) ), true ) ) {
			self::handle_rental_marked_returned( $item_id );
		} else {
			self::clear_availability_cache_for_item( $item_id );
			$order_id = absint( $order_id );
			if ( ! $order_id && $item_id ) {
				$line = new WC_Order_Item_Product( $item_id );
				$order_id = (int) $line->get_order_id();
			}
			if ( $order_id ) {
				self::release_kestrel_reservations_from_date( $order_id, current_time( 'Y-m-d' ) );
			}
		}
	}

	/**
	 * Catch returned meta when the whole item object is saved (HPOS / bulk edits).
	 *
	 * @param WC_Order_Item $item Order item.
	 * @return void
	 */
	public static function on_before_save_order_item( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$became_returned = false;
		foreach ( self::$returned_meta_keys as $key ) {
			$new_val = $item->get_meta( $key, true );
			if ( self::is_truthy_rental_flag( $new_val ) ) {
				$became_returned = true;
				break;
			}
		}

		if ( $became_returned ) {
			self::handle_rental_marked_returned( $item->get_id() );
		}
	}
}
