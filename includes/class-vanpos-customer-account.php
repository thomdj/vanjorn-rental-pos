<?php
/**
 * Customer Account Integration for VAN-Jorn Rental POS
 * Handles child order creation on payment complete and display in customer account
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer Account Class
 */
class VanPOS_Customer_Account {

	/**
	 * Initialize customer account functionality
	 */
	public static function init() {
		// Set order type during checkout if it's a rental order.
		// Priority 5 ensures this runs BEFORE Deposit_Manager::process_deposit_order
		// (priority 10) which needs _vanpos_order_type to already be set.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'set_rental_order_type' ), 5, 1 );
		
		// Backfill: create child orders when payment settles or an admin manually
		// completes an order. Security deposit and remaining orders are already created
		// at checkout (priority 10/15 above), so these only fire for edge cases
		// (e.g. POS orders, or a checkout where the priority-15 hook was bypassed).
		// woocommerce_order_status_processing is intentionally omitted: Mollie/iDEAL
		// fires woocommerce_payment_complete for all successful payments, which covers
		// the same transition without running on every admin status change.
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'create_child_order_on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'create_child_order_on_payment_complete' ), 20, 1 );

		// Create the security deposit child at checkout time (classic + Store API)
		// so it no longer depends on the off-site gateway firing payment_complete
		// reliably — that webhook is unreliable for redirect gateways (e.g. Mollie
		// iDEAL) on a failed-then-retried payment. Idempotent via has_payment_order(),
		// so create_child_order_on_payment_complete() above stays as a safe backfill.
		// Priority 15 runs after set_rental_order_type (5) and
		// Deposit_Manager::process_deposit_order (10), so the order type and any
		// remaining child are already in place.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'create_security_deposit_on_checkout' ), 15, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'create_security_deposit_on_checkout' ), 15, 1 );

		// Add due date column to orders table
		add_filter( 'woocommerce_account_orders_columns', array( __CLASS__, 'add_due_date_column' ), 10, 1 );
		add_action( 'woocommerce_my_account_my_orders_column_due_date', array( __CLASS__, 'display_due_date_column' ), 10, 1 );
		
		// Add parent order link for child orders
		add_action( 'woocommerce_my_account_my_orders_column_order-number', array( __CLASS__, 'display_order_number_with_parent' ), 5, 1 );
	}

	/**
	 * Set rental order type during checkout
	 *
	 * @param int       $order_id Order ID.
	 * @param array     $posted_data Posted data (optional).
	 * @param WC_Order  $order Order object (optional).
	 * @return void
	 */
	public static function set_rental_order_type( $order_id, $posted_data = null, $order = null ) {
		try {
			// Use provided order object or get it
			if ( ! $order ) {
				$order = wc_get_order( $order_id );
			}
			
			if ( ! $order ) {
				return;
			}

			// Check if order type is already set
			$order_type = $order->get_meta( '_vanpos_order_type' );
			if ( ! empty( $order_type ) ) {
				return; // Already set
			}

			// Stamp the order type when the order has rental items/dates. Detection
			// is centralised in VanPOS_Order_Manager::is_primary_rental_order(); the
			// early return above guarantees the type is still unset here, so the
			// helper takes its metadata-probe path. Wrapped in this method's
			// try/catch, so a missing class can never break checkout.
			if ( VanPOS_Order_Manager::is_primary_rental_order( $order ) ) {
				$order->update_meta_data( '_vanpos_order_type', 'primary_rental' );
				$order->save(); // Save the order with the new meta
			}
		} catch ( Exception $e ) {
			// Log error but don't break checkout
			if ( function_exists( 'wc_get_logger' ) ) {
				$logger = wc_get_logger();
				$logger->error( 'Error setting rental order type: ' . $e->getMessage(), array( 'source' => 'vanjorn-rental-pos' ) );
			}
		}
	}

	/**
	 * Create child order when primary order payment is completed
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function create_child_order_on_payment_complete( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip auto-creation for orders created via the admin "Add Rental Order" form.
		// Those orders handle child order creation explicitly through the form's
		// checkboxes, so firing here would produce duplicates.
		if ( 'yes' === $order->get_meta( '_vanpos_admin_created' ) ) {
			return;
		}

		// Check if it's a primary rental order (type stamp or rental metadata).
		// is_primary_rental_order() returns false for non-rental types such as
		// 'payment_order', so this handler correctly ignores child payment orders
		// even though they carry rental dates copied from the parent.
		if ( ! VanPOS_Order_Manager::is_primary_rental_order( $order ) ) {
			return;
		}

		// Detected via metadata (type not yet stamped) — record it for future reads.
		if ( '' === (string) $order->get_meta( '_vanpos_order_type' ) ) {
			$order->update_meta_data( '_vanpos_order_type', 'primary_rental' );
			$order->save();
		}

		// Check if child order already exists
		if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
			return;
		}

		// Create security deposit order first (if configured).
		// This runs independently of whether a remaining payment order exists,
		// because the security deposit is a separate concern (refundable, VAT-exempt).
		if ( ! VanPOS_Order_Manager::has_payment_order( $order_id, 'security_deposit' ) ) {
			$security_deposit_order_id = VanPOS_Order_Manager::create_security_deposit_order( $order_id );
			if ( is_wp_error( $security_deposit_order_id ) ) {
				// Log error but don't stop the process
				if ( function_exists( 'wc_get_logger' ) ) {
					$logger = wc_get_logger();
					$logger->error( 'Failed to create security deposit order: ' . $security_deposit_order_id->get_error_message(), array( 'source' => 'vanjorn-rental-pos' ) );
				}
			}
		}

		if ( VanPOS_Order_Manager::has_remaining_payment_order( $order_id ) ) {
			return; // Already created (typically by Deposit_Manager::create_partial_payment_orders at checkout)
		}

		// Determine remaining amount.
		// When the Deposit_Manager handled this order at checkout (long-term booking),
		// item meta '_vanpos_remaining_amount' is set and the remaining child order
		// already exists — caught by the dedup guard above.
		// When deposits were not applied (short-term / full-payment booking), that
		// item meta is absent and $remaining_amount stays at 0, which is correct:
		// there is no remaining payment to collect.
		$remaining_amount = 0;

		// Explicit guard: if the Deposit Manager already processed this order,
		// skip remaining-order creation entirely — dedup above should have caught it,
		// but this makes the intent unmistakable.
		$has_deposit_order = $order->get_meta( '_vanpos_order_has_remaining_payment' );
		if ( 'yes' !== $has_deposit_order ) {
			// No deposit split — check if there's a remaining amount from other sources
			// (e.g. admin-created orders via VanPOS_Order_Manager::create_primary_rental_order)
			foreach ( $order->get_items() as $item ) {
				$item_remaining = (float) $item->get_meta( '_vanpos_remaining_amount' );
				if ( $item_remaining > 0 ) {
					$remaining_amount += $item_remaining;
				}
			}

			// Fallback to order meta if item meta not found
			if ( $remaining_amount <= 0 ) {
				$remaining_amount = (float) $order->get_meta( '_vanpos_remaining_payment' );
			}
		}

		// Only create if there's a remaining amount
		if ( $remaining_amount > 0 ) {
			// create_payment_order() handles all meta (dates, booking reference,
			// due date, VAT, AutomateWoo flags, status) internally — no
			// supplementary copy needed here.
			VanPOS_Order_Manager::create_payment_order(
				$order_id,
				'remaining',
				$remaining_amount
			);
		}
	}

	/**
	 * Create the security deposit child order at checkout time.
	 *
	 * Runs for both short- and long-term bookings — unlike
	 * VanPOS_Deposit_Manager::process_deposit_order(), which is gated on the
	 * deposit split ( _vanpos_order_has_remaining_payment === 'yes' ) and so never
	 * runs for short-term / full-payment bookings. Creating the security deposit
	 * here removes the dependency on the gateway's payment-complete webhook, which
	 * is unreliable for off-site redirect gateways (e.g. Mollie iDEAL) on a
	 * failed-then-retried payment.
	 *
	 * Idempotent: create_child_order_on_payment_complete() remains hooked as a
	 * backfill, and create_security_deposit_order() self-guards via
	 * has_payment_order(), so double-firing never produces a duplicate.
	 *
	 * Classic checkout ( woocommerce_checkout_order_processed ) passes an order ID;
	 * the Store API ( woocommerce_store_api_checkout_order_processed ) passes a
	 * WC_Order object. Accept either.
	 *
	 * @param int|WC_Order $order_or_id Order ID or order object.
	 * @return void
	 */
	public static function create_security_deposit_on_checkout( $order_or_id ) {
		$order = ( $order_or_id instanceof WC_Order ) ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order || ! class_exists( 'VanPOS_Order_Manager' ) ) {
			return;
		}

		// Admin-created orders manage their own child orders (mirrors the guard in
		// create_child_order_on_payment_complete).
		if ( 'yes' === $order->get_meta( '_vanpos_admin_created' ) ) {
			return;
		}

		// Only act on primary rental orders. Detection is centralised in
		// is_primary_rental_order() (type stamp, with order/item metadata fallback).
		if ( ! VanPOS_Order_Manager::is_primary_rental_order( $order ) ) {
			return;
		}

		// Idempotent: skip if a security deposit child already exists (created by a
		// retry, the payment-complete backfill, or a duplicate checkout submission).
		if ( VanPOS_Order_Manager::has_payment_order( $order->get_id(), 'security_deposit' ) ) {
			return;
		}

		$result = VanPOS_Order_Manager::create_security_deposit_order( $order->get_id() );
		if ( is_wp_error( $result ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				'Checkout-time security deposit creation failed for order ' . $order->get_id() . ': ' . $result->get_error_message(),
				array( 'source' => 'vanjorn-rental-pos' )
			);
		}
	}

	/**
	 * Add due date column to orders table
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_due_date_column( $columns ) {
		// Insert due date column before order-total
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			if ( 'order-total' === $key ) {
				$new_columns['due-date'] = __( 'Due date', 'vanjorn-rental-pos' );
			}
			$new_columns[ $key ] = $value;
		}
		return $new_columns;
	}

	/**
	 * Display due date in orders table
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function display_due_date_column( $order ) {
		$due_date = $order->get_meta( '_vanpos_due_date' );
		if ( $due_date ) {
			$due_datetime = date_create( $due_date );
			if ( $due_datetime ) {
				echo esc_html( date_i18n( get_option( 'date_format' ), $due_datetime->getTimestamp() ) );
			} else {
				// Corrupt meta value — render raw rather than throw.
				echo esc_html( $due_date );
			}
		} else {
			echo esc_html_x( '—', 'placeholder for empty due date', 'vanjorn-rental-pos' );
		}
	}

	/**
	 * Display order number with parent order link for child orders
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function display_order_number_with_parent( $order ) {
		$order_type = $order->get_meta( '_vanpos_order_type' );
		
		if ( 'payment_order' === $order_type ) {
			// Get parent order
			$parent_id = $order->get_parent_id();
			if ( ! $parent_id ) {
				$parent_id = $order->get_meta( '_vanpos_primary_order_id' );
			}

			if ( $parent_id ) {
				$parent_order = wc_get_order( $parent_id );
				if ( $parent_order ) {
					?>
					<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View order number %s', 'woocommerce' ), $order->get_order_number() ) ); ?>">
						<?php echo esc_html( _x( '#', 'hash before order number', 'woocommerce' ) . $order->get_order_number() ); ?>
					</a>
					<br>
					<small style="color: #666;">
						<?php
						echo wp_kses_post( sprintf(
							/* translators: %s is the parent order number, shown as a link */
							__( 'Payment for order %s', 'vanjorn-rental-pos' ),
							'<a href="' . esc_url( $parent_order->get_view_order_url() ) . '">#' . esc_html( $parent_order->get_order_number() ) . '</a>'
						) );
						?>
					</small>
					<?php
					return;
				}
			}
		}

		// Default display for non-child orders
		?>
		<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View order number %s', 'woocommerce' ), $order->get_order_number() ) ); ?>">
			<?php echo esc_html( _x( '#', 'hash before order number', 'woocommerce' ) . $order->get_order_number() ); ?>
		</a>
		<?php
	}
}
