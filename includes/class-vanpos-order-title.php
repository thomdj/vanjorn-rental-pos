<?php
/**
 * Order Title Manager for VAN-Jorn Rental Platform
 * Modifies order titles based on order type (Security Deposit, Remaining Payment, Rental Order)
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order Title Manager Class
 */
class VanPOS_Order_Title_Manager {

	/**
	 * Recursion guard for regenerate_title(): set true while a regeneration
	 * is in flight so the save hook doesn't re-enter the title generation
	 * logic when regenerate_title() persists its changes.
	 *
	 * @var bool
	 */
	private static $regenerating = false;

	/**
	 * Initialize the order title manager
	 *
	 * @return void
	 */
	public static function init() {
		// Hook into order creation to set custom title
		add_action( 'woocommerce_new_order', array( __CLASS__, 'set_order_title_on_creation' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'set_order_title_on_creation' ), 10, 1 );
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'set_order_title_on_save' ), 10, 1 );

		// Filter the buyer name display in admin order list
		add_filter( 'woocommerce_admin_order_buyer_name', array( __CLASS__, 'modify_order_buyer_name' ), 10, 2 );

		// Use VRC order number for imported orders (e.g. 2-A, 2-B, 2-C)
		add_filter( 'woocommerce_order_number', array( __CLASS__, 'filter_order_number' ), 10, 2 );
	}

	/**
	 * Set order title when order is created
	 *
	 * @param int|WC_Order $order Order ID or order object.
	 * @return void
	 */
	public static function set_order_title_on_creation( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		// Only set title if not already set
		$existing_title = $order->get_meta( '_vanpos_custom_order_title' );
		if ( $existing_title ) {
			return;
		}

		self::set_order_title( $order );
	}

	/**
	 * Set order title when order is saved (for orders created programmatically)
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function set_order_title_on_save( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		// Recursion guard — regenerate_title() persists via $order->save(),
		// which re-fires this hook. Don't re-enter while a regeneration is
		// already in flight.
		if ( self::$regenerating ) {
			return;
		}

		// Quick bail: if the order has no VanPOS meta at all it's not a
		// rental order and we can skip the more expensive title check.
		// This avoids a meta read on every non-rental order save.
		if ( ! $order->get_meta( '_vanpos_pickup_date' )
			&& ! $order->get_meta( '_vanpos_payment_type' )
			&& ! $order->get_meta( '_vanpos_order_type' )
			&& ! $order->get_meta( '_vanpos_deposits_order_has_deposit' ) ) {
			return;
		}

		$existing_title = $order->get_meta( '_vanpos_custom_order_title' );

		// No title yet — first generation. Claims the sequential number.
		if ( empty( $existing_title ) ) {
			self::set_order_title( $order );
			return;
		}

		// Title exists — check whether it's still complete relative to the
		// current camper name. Frontend orders trigger this hook before the
		// line items and _vanpos_camper_name are written, producing a stub
		// title that needs to be regenerated on a later save.
		//
		// For children, the camper name lives on the parent; that's where
		// get_order_product_name() reads it from too.
		$source    = $order;
		$parent_id = $order->get_parent_id();
		if ( ! $parent_id ) {
			$parent_id = (int) $order->get_meta( '_vanpos_primary_order_id' );
		}
		if ( $parent_id ) {
			$parent_order = wc_get_order( $parent_id );
			if ( $parent_order ) {
				$source = $parent_order;
			}
		}

		$current_camper = (string) $source->get_meta( '_vanpos_camper_name' );

		// No camper name to validate against — leave the title alone.
		if ( '' === $current_camper ) {
			return;
		}

		// Title already contains the current camper name — nothing to do.
		if ( false !== strpos( $existing_title, $current_camper ) ) {
			return;
		}

		// Title is stale — regenerate, preserving the existing order number.
		self::regenerate_title( $order );
	}

	/**
	 * Set the order title and assign a sequential order number.
	 *
	 * Number scheme: {base}-A (main), {base}-B (deposit), {base}-C (remaining).
	 * The counter is stored in wp_option `_vanpos_next_order_number` and is
	 * initialised by the VRC importer so new orders continue where imports left off.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	private static function set_order_title( $order ) {
		$order_type = self::detect_order_type( $order );

		if ( ! $order_type ) {
			return;
		}

		// Skip if order number was already assigned (e.g. by VRC importer)
		$existing_number = $order->get_meta( '_vanpos_vrc_order_number' );
		if ( ! empty( $existing_number ) ) {
			return;
		}

		$customer_name = self::get_customer_name( $order );
		$product_name  = self::get_order_product_name( $order );

		$suffix_letter  = '';
		$type_label     = '';
		$base_number    = 0;

		switch ( $order_type ) {
			case 'rental_order':
				// Primary rental → claim next number, suffix -A
				$base_number = self::claim_next_order_number();
				$suffix_letter = 'A';
				$type_label = __( 'Main Order', 'vanjorn-rental-pos' );
				break;

			case 'security_deposit':
				$base_number = self::get_parent_base_number( $order );
				$suffix_letter = 'B';
				$type_label = __( 'Security Deposit', 'vanjorn-rental-pos' );
				break;

			case 'deposit_payment':
				$base_number = self::get_parent_base_number( $order );
				$suffix_letter = 'D';
				$deposit_pct = class_exists( 'VanPOS_Functions' )
					? (int) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 )
					: 50;
				/* translators: %d is the deposit percentage */
				$type_label = sprintf( __( 'Deposit Payment (%d%%)', 'vanjorn-rental-pos' ), $deposit_pct );
				break;

			case 'remaining_payment':
				$base_number = self::get_parent_base_number( $order );
				$suffix_letter = 'C';
				$type_label = __( 'Remaining Payment', 'vanjorn-rental-pos' );
				break;

			case 'extension_payment':
				$base_number = self::get_parent_base_number( $order );
				$suffix_letter = 'E';
				$type_label = __( 'Price Adjustment', 'vanjorn-rental-pos' );
				break;

			default:
				return;
		}

		if ( ! $base_number ) {
			// Fallback: if we can't determine a base number (e.g. orphaned child),
			// claim a fresh number so the order still gets a proper display number.
			$base_number = self::claim_next_order_number();
		}

		// Store order number meta
		$order->update_meta_data( '_vanpos_order_base_number', $base_number );
		$order->update_meta_data( '_vanpos_vrc_order_number', $base_number . '-' . $suffix_letter );

		// Build title: "Femke Routs - Main Order - For the Atmosphere Lovers"
		$title_parts = array_filter( array( $customer_name, $type_label, $product_name ) );
		$title_suffix = implode( ' - ', $title_parts );
		if ( empty( $title_suffix ) ) {
			$title_suffix = $type_label;
		}

		$order->update_meta_data( '_vanpos_custom_order_title', $title_suffix );
		$order->update_meta_data( '_vanpos_order_type_detected', $order_type );

		// Guard against woocommerce_after_order_object_save re-entry: the save
		// below re-fires the hook, and without this guard set_order_title_on_save()
		// would run a second pass (it terminates via the title-exists check today,
		// but that is fragile). This mirrors the identical pattern in regenerate_title().
		self::$regenerating = true;
		try {
			$order->save();
		} finally {
			self::$regenerating = false;
		}
	}

	/**
	 * Maximum allowed gap between the counter and the highest assigned
	 * base number before the sanity check auto-corrects.
	 *
	 * Set generously to avoid false positives during normal batch imports,
	 * but low enough to catch a corrupted counter (e.g. set to a WC order ID).
	 */
	const COUNTER_MAX_GAP = 50;

	/**
	 * Claim the next sequential order number and advance the counter.
	 *
	 * Uses MySQL's LAST_INSERT_ID(expr) for a fully atomic claim.
	 * LAST_INSERT_ID(expr) sets a connection-local value to expr and
	 * returns it, so even under concurrent UPDATEs each connection
	 * sees only its own claimed number via $wpdb->insert_id.
	 *
	 * Includes a sanity check: if the claimed number is more than
	 * COUNTER_MAX_GAP ahead of the highest actually assigned base number,
	 * the counter is assumed corrupt (e.g. initialised from a WC order ID
	 * instead of a VanPOS sequence). In that case the counter is auto-
	 * corrected and the correct next number is returned instead.
	 *
	 * The counter is stored in wp_option `_vanpos_next_order_number` and
	 * initialised by the VRC importer to continue after the last imported ID.
	 *
	 * @return int The claimed number.
	 */
	private static function claim_next_order_number() {
		global $wpdb;

		// Ensure the option exists (first-run initialisation).
		if ( false === get_option( '_vanpos_next_order_number' ) ) {
			$highest = self::get_highest_assigned_base_number();
			add_option( '_vanpos_next_order_number', $highest + 1 );
		}

		// Atomic claim: LAST_INSERT_ID(option_value) captures the current
		// value into a connection-local register, then +1 writes the next.
		// We retrieve the claimed value via SELECT LAST_INSERT_ID() rather
		// than $wpdb->insert_id, because mysqli_insert_id() does not
		// reliably return the value set by LAST_INSERT_ID(expr) in an
		// UPDATE statement on all MySQL/PHP driver combinations.
		$wpdb->query(
			"UPDATE {$wpdb->options}
			 SET option_value = LAST_INSERT_ID(option_value) + 1
			 WHERE option_name = '_vanpos_next_order_number'"
		);

		$claimed = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

		// Invalidate the object cache so any subsequent get_option() in this
		// request sees the freshly incremented value.
		wp_cache_delete( '_vanpos_next_order_number', 'options' );

		// Sanity check: detect a corrupted counter.
		$highest  = self::get_highest_assigned_base_number();
		$expected = $highest + 1;

		if ( $claimed > $expected + self::COUNTER_MAX_GAP ) {
			// Counter was way off — auto-correct.
			update_option( '_vanpos_next_order_number', $expected + 1 );
			wp_cache_delete( '_vanpos_next_order_number', 'options' );

			error_log( sprintf(
				'VanPOS: Order number counter was at %d but highest assigned base number is %d. Auto-corrected; claiming %d instead.',
				$claimed,
				$highest,
				$expected
			) );

			return $expected;
		}

		return $claimed;
	}

	/**
	 * Get the highest _vanpos_order_base_number assigned to any order.
	 *
	 * Works with both HPOS (wc_orders_meta) and legacy (wp_postmeta).
	 *
	 * @return int Highest base number, or 0 if none found.
	 */
	public static function get_highest_assigned_base_number() {
		global $wpdb;

		$value = 0;

		// Try HPOS meta table first.
		$hpos_table = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
			$value = (int) $wpdb->get_var(
				"SELECT MAX( CAST( meta_value AS UNSIGNED ) )
				 FROM {$hpos_table}
				 WHERE meta_key = '_vanpos_order_base_number'"
			);
		}

		// Also check legacy postmeta (in case of mixed storage).
		$legacy = (int) $wpdb->get_var(
			"SELECT MAX( CAST( meta_value AS UNSIGNED ) )
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_vanpos_order_base_number'"
		);

		return max( $value, $legacy );
	}

	/**
	 * Get the base order number from a child order's parent.
	 *
	 * @param WC_Order $order Child order.
	 * @return int Base number, or 0 if not found.
	 */
	private static function get_parent_base_number( $order ) {
		$parent_id = $order->get_parent_id();
		if ( ! $parent_id ) {
			$parent_id = (int) $order->get_meta( '_vanpos_primary_order_id' );
		}
		if ( ! $parent_id ) {
			return 0;
		}

		$parent = wc_get_order( $parent_id );
		if ( ! $parent ) {
			return 0;
		}

		$base = $parent->get_meta( '_vanpos_order_base_number' );
		return $base ? (int) $base : 0;
	}

	/**
	 * Get the primary product name from an order's line items.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Product name or empty string.
	 */
	private static function get_order_product_name( $order ) {
		// For child orders, get product name from parent
		$parent_id = $order->get_parent_id();
		if ( ! $parent_id ) {
			$parent_id = (int) $order->get_meta( '_vanpos_primary_order_id' );
		}
		$source = ( $parent_id && wc_get_order( $parent_id ) ) ? wc_get_order( $parent_id ) : $order;

		$camper_name = $source->get_meta( '_vanpos_camper_name' );
		if ( ! empty( $camper_name ) ) {
			return $camper_name;
		}

		foreach ( $source->get_items() as $item ) {
			if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
				$product = $item->get_product();
				if ( $product ) {
					return $product->get_name();
				}
			}
		}
		return '';
	}

	/**
	 * Detect the order type based on order meta
	 *
	 * @param WC_Order $order Order object.
	 * @return string|false Order type or false if not detected.
	 */
	private static function detect_order_type( $order ) {
		// Check payment type for child orders
		$payment_type = $order->get_meta( '_vanpos_payment_type' );

		// Check for security deposit (refundable)
		if ( $payment_type === 'security_deposit' ) {
			return 'security_deposit';
		}

		// Check for 50% initial deposit payment
		if ( $payment_type === 'deposit' ) {
			return 'deposit_payment';
		}

		// Check for remaining payment
		if ( in_array( $payment_type, array( 'remaining', 'second_payment' ), true ) ) {
			return 'remaining_payment';
		}

		// Check for extension payment (price adjustment from date change)
		if ( $payment_type === 'extension' ) {
			return 'extension_payment';
		}

		// Check for primary rental order
		$order_type = $order->get_meta( '_vanpos_order_type' );
		if ( $order_type === 'primary_rental' ) {
			return 'rental_order';
		}

		// Check if order has deposit meta (from deposit manager)
		$has_deposit = $order->get_meta( '_vanpos_deposits_order_has_deposit' );
		if ( $has_deposit === 'yes' ) {
			// This is a main rental order with deposit
			return 'rental_order';
		}

		// Check if order has rental-related meta (pickup date, etc.)
		$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
		if ( $pickup_date ) {
			// Has pickup date but no recognised payment_type and no primary_rental flag.
			// If it's a child order with an unrecognised payment_type, we can't classify it.
			if ( $order->get_parent_id() || $order->get_meta( '_vanpos_primary_order_id' ) ) {
				// Child order with unknown payment type — cannot classify
				return false;
			}
			// Parent order with pickup date = rental order
			return 'rental_order';
		}

		return false;
	}

	/**
	 * Get customer name from order
	 *
	 * @param WC_Order $order Order object.
	 * @return string Customer name.
	 */
	private static function get_customer_name( $order ) {
		$first_name = $order->get_billing_first_name();
		$last_name = $order->get_billing_last_name();

		if ( $first_name || $last_name ) {
			return trim( $first_name . ' ' . $last_name );
		}

		$company = $order->get_billing_company();
		if ( $company ) {
			return trim( $company );
		}

		$customer_id = $order->get_customer_id();
		if ( $customer_id ) {
			$user = get_user_by( 'id', $customer_id );
			if ( $user ) {
				return $user->display_name;
			}
		}

		return '';
	}

	/**
	 * Use VRC order number for imported orders.
	 *
	 * Imported orders store a custom number like "2-A", "2-B", "2-C"
	 * in _vanpos_vrc_order_number. This filter makes WooCommerce use
	 * that number everywhere: admin list, emails, my-account, etc.
	 * Non-imported orders keep their default WC order number.
	 *
	 * @param string|int $order_number Default WC order number.
	 * @param WC_Order   $order        Order object.
	 * @return string
	 */
	public static function filter_order_number( $order_number, $order ) {
		$vrc_number = $order->get_meta( '_vanpos_vrc_order_number' );
		if ( ! empty( $vrc_number ) ) {
			return $vrc_number;
		}
		return $order_number;
	}

	/**
	 * Modify the buyer name displayed in admin order list
	 *
	 * @param string   $buyer Buyer name.
	 * @param WC_Order $order Order object.
	 * @return string Modified buyer name.
	 */
	public static function modify_order_buyer_name( $buyer, $order ) {
		// Get the custom title suffix from order meta
		$custom_title = $order->get_meta( '_vanpos_custom_order_title' );

		// If custom title is set, use it
		if ( $custom_title ) {
			return '- ' . $custom_title;
		}

		// If not set yet, try to detect and build a label on the fly.
		// Don't persist here — set_order_title_on_creation / _on_save handle DB writes.
		$order_type = self::detect_order_type( $order );
		if ( $order_type ) {
			$customer_name = self::get_customer_name( $order );
			$product_name  = self::get_order_product_name( $order );
			$type_label    = '';

			switch ( $order_type ) {
				case 'security_deposit':
					$type_label = __( 'Security Deposit', 'vanjorn-rental-pos' );
					break;

				case 'deposit_payment':
					$deposit_pct = class_exists( 'VanPOS_Functions' )
						? (int) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 )
						: 50;
					/* translators: %d is the deposit percentage */
					$type_label = sprintf( __( 'Deposit Payment (%d%%)', 'vanjorn-rental-pos' ), $deposit_pct );
					break;

				case 'remaining_payment':
					$type_label = __( 'Remaining Payment', 'vanjorn-rental-pos' );
					break;

				case 'rental_order':
					$type_label = __( 'Main Order', 'vanjorn-rental-pos' );
					break;

				case 'extension_payment':
					$type_label = __( 'Price Adjustment', 'vanjorn-rental-pos' );
					break;
			}

			if ( $type_label ) {
				$parts = array_filter( array( $customer_name, $type_label, $product_name ) );
				return '- ' . implode( ' - ', $parts );
			}
		}

		// Return original buyer name if no custom title
		return $buyer;
	}

	/**
	 * Regenerate the custom order title and rebuild the VRC number suffix
	 * using the order's current meta. Preserves the existing base number —
	 * does NOT claim a new one — so this is safe to call on orders that
	 * already have an assigned number.
	 *
	 * Used by:
	 *   - VanPOS_Meta_Backfill (fix_parent / fix_child) to repair stale titles.
	 *   - set_order_title_on_save() to auto-heal stub titles produced when
	 *     the save hook fires before line items / camper name are written
	 *     (typical of frontend checkout).
	 *
	 * Bypasses the existing-title and existing-number guards in
	 * set_order_title(), which is deliberate — this is a forced regeneration.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool True on success, false if regeneration was skipped.
	 */
	public static function regenerate_title( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		// Don't re-enter while a regeneration is already in flight.
		if ( self::$regenerating ) {
			return false;
		}

		$order_type = self::detect_order_type( $order );
		if ( ! $order_type ) {
			return false;
		}

		$suffix_letter = '';
		$type_label    = '';
		$base_number   = 0;

		switch ( $order_type ) {
			case 'rental_order':
				$suffix_letter = 'A';
				$type_label    = __( 'Main Order', 'vanjorn-rental-pos' );
				// Primary rentals carry their own base number; preserve it.
				$base_number = (int) $order->get_meta( '_vanpos_order_base_number' );
				break;

			case 'security_deposit':
				$suffix_letter = 'B';
				$type_label    = __( 'Security Deposit', 'vanjorn-rental-pos' );
				$base_number   = self::get_parent_base_number( $order );
				break;

			case 'deposit_payment':
				$suffix_letter = 'D';
				$deposit_pct   = class_exists( 'VanPOS_Functions' )
					? (int) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 )
					: 50;
				/* translators: %d is the deposit percentage */
				$type_label  = sprintf( __( 'Deposit Payment (%d%%)', 'vanjorn-rental-pos' ), $deposit_pct );
				$base_number = self::get_parent_base_number( $order );
				break;

			case 'remaining_payment':
				$suffix_letter = 'C';
				$type_label    = __( 'Remaining Payment', 'vanjorn-rental-pos' );
				$base_number   = self::get_parent_base_number( $order );
				break;

			case 'extension_payment':
				$suffix_letter = 'E';
				$type_label    = __( 'Price Adjustment', 'vanjorn-rental-pos' );
				$base_number   = self::get_parent_base_number( $order );
				break;

			default:
				return false;
		}

		// Fall back to the stored full number if base_number meta is somehow
		// missing but _vanpos_vrc_order_number is set (parse e.g. "464-A").
		if ( ! $base_number ) {
			$existing_full = (string) $order->get_meta( '_vanpos_vrc_order_number' );
			if ( $existing_full && preg_match( '/^(\d+)-/', $existing_full, $m ) ) {
				$base_number = (int) $m[1];
			}
		}

		// Still no number — refuse to regenerate. Fresh generation should
		// happen via set_order_title() instead, which claims a new number.
		if ( ! $base_number ) {
			return false;
		}

		$customer_name = self::get_customer_name( $order );
		$product_name  = self::get_order_product_name( $order );

		$title_parts  = array_filter( array( $customer_name, $type_label, $product_name ) );
		$title_suffix = implode( ' - ', $title_parts );
		if ( empty( $title_suffix ) ) {
			$title_suffix = $type_label;
		}

		self::$regenerating = true;
		try {
			$order->update_meta_data( '_vanpos_order_base_number', $base_number );
			$order->update_meta_data( '_vanpos_vrc_order_number', $base_number . '-' . $suffix_letter );
			$order->update_meta_data( '_vanpos_custom_order_title', $title_suffix );
			$order->update_meta_data( '_vanpos_order_type_detected', $order_type );
			$order->save();
		} finally {
			self::$regenerating = false;
		}

		return true;
	}

}

