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
		
		// Create child order when primary order payment is completed
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'create_child_order_on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'create_child_order_on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'create_child_order_on_payment_complete' ), 20, 1 );

		// Include child orders in customer account orders list
		add_filter( 'woocommerce_my_account_my_orders_query', array( __CLASS__, 'include_child_orders_in_query' ), 10, 1 );
		
		// Modify orders query results to include child orders
		add_action( 'woocommerce_before_account_orders', array( __CLASS__, 'add_child_orders_to_account_orders' ), 10, 1 );
		
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

			// Check if order has rental items
			$has_rental_items = false;
			$items = $order->get_items();
			
			if ( ! empty( $items ) ) {
				foreach ( $items as $item ) {
					// Check for rental metadata in item
					$pickup_date = $item->get_meta( 'vanpos_pickup_date' );
					$return_date = $item->get_meta( 'vanpos_return_date' );
					$rent_from = $item->get_meta( 'wcrp_rental_products_rent_from' );
					$rent_to = $item->get_meta( 'wcrp_rental_products_rent_to' );
					$original_price = $item->get_meta( '_vanpos_original_price' );
					
					if ( $pickup_date || $return_date || $rent_from || $rent_to || $original_price ) {
						$has_rental_items = true;
						break;
					}
				}
			}
			
			// Also check order meta for rental dates
			if ( ! $has_rental_items ) {
				$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
				$return_date = $order->get_meta( '_vanpos_return_date' );
				if ( $pickup_date || $return_date ) {
					$has_rental_items = true;
				}
			}
			
			// If it has rental items, set order type
			if ( $has_rental_items ) {
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

		// Check if it's a primary rental order (check order type or rental metadata)
		$order_type = $order->get_meta( '_vanpos_order_type' );
		$is_rental_order = false;
		
		if ( 'primary_rental' === $order_type ) {
			$is_rental_order = true;
		} elseif ( empty( $order_type ) ) {
			// Check for rental metadata even if order type isn't set
			$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
			$return_date = $order->get_meta( '_vanpos_return_date' );
			
			// Also check item meta
			if ( ! $pickup_date && ! $return_date ) {
				foreach ( $order->get_items() as $item ) {
					$pickup_date = $item->get_meta( 'vanpos_pickup_date' );
					$return_date = $item->get_meta( 'vanpos_return_date' );
					$rent_from = $item->get_meta( 'wcrp_rental_products_rent_from' );
					$rent_to = $item->get_meta( 'wcrp_rental_products_rent_to' );
					$original_price = $item->get_meta( '_vanpos_original_price' );
					
					if ( $pickup_date || $return_date || $rent_from || $rent_to || $original_price ) {
						$is_rental_order = true;
						// Set order type for future reference
						$order->update_meta_data( '_vanpos_order_type', 'primary_rental' );
						$order->save();
						break;
					}
				}
			} else {
				$is_rental_order = true;
				// Set order type for future reference
				$order->update_meta_data( '_vanpos_order_type', 'primary_rental' );
				$order->save();
			}
		}
		
		if ( ! $is_rental_order ) {
			return;
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

		if ( VanPOS_Order_Manager::has_payment_order( $order_id, 'remaining' ) || VanPOS_Order_Manager::has_payment_order( $order_id, 'second_payment' ) ) {
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
			// Create remaining payment child order (due date is set correctly in create_payment_order based on vanpos_due_date_days setting)
			$deposit_pct     = (int) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 );
			$remaining_pct   = 100 - $deposit_pct;
			$child_order_id = VanPOS_Order_Manager::create_payment_order(
				$order_id,
				'remaining',
				$remaining_amount,
				/* translators: %d is the remaining payment percentage */
				sprintf( __( 'Remaining rental payment (%d%%)', 'vanjorn-rental-pos' ), $remaining_pct )
			);

			if ( ! is_wp_error( $child_order_id ) ) {
				$child_order = wc_get_order( $child_order_id );
				if ( $child_order ) {
					// Due date is already set correctly in create_payment_order (7 days before pickup by default)
					
					// Copy important metadata from parent for payment processing
					$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
					$return_date = $order->get_meta( '_vanpos_return_date' );
					$booking_ref = $order->get_meta( '_vanpos_booking_reference' );
					
					if ( $pickup_date ) {
						$child_order->update_meta_data( '_vanpos_pickup_date', $pickup_date );
					}
					if ( $return_date ) {
						$child_order->update_meta_data( '_vanpos_return_date', $return_date );
					}
					if ( $booking_ref ) {
						$child_order->update_meta_data( '_vanpos_booking_reference', $booking_ref );
					}
					
					// Ensure order is ready for payment
					$child_order->set_status( 'pending' );
					$child_order->save();
				}
			}
		}
	}

	/**
	 * Include child orders in customer account orders query
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function include_child_orders_in_query( $args ) {
		// Get current customer ID
		$customer_id = get_current_user_id();
		if ( ! $customer_id ) {
			return $args;
		}

		// Get all primary orders for this customer
		$primary_orders = wc_get_orders( array(
			'customer_id' => $customer_id,
			'meta_key'    => '_vanpos_order_type',
			'meta_value'  => 'primary_rental',
			'limit'       => -1,
			'return'      => 'ids',
		) );

		if ( empty( $primary_orders ) ) {
			return $args;
		}

		// Get all child orders for these primary orders
		$child_order_ids = array();
		foreach ( $primary_orders as $primary_order_id ) {
			if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
				continue;
			}
			$child_orders = VanPOS_Order_Manager::get_payment_orders( $primary_order_id );
			foreach ( $child_orders as $child_order ) {
				// Skip refund objects — they lack methods like get_customer_id()
				if ( $child_order instanceof WC_Order_Refund ) {
					continue;
				}
				// Verify child order belongs to same customer
				if ( $child_order->get_customer_id() === $customer_id ) {
					$child_order_ids[] = $child_order->get_id();
				}
			}
		}

		// If we have child orders, include them in the query
		// Note: Since child orders have the same customer_id, they should already be included
		// But we store the IDs for the action hook to merge them if needed
		if ( ! empty( $child_order_ids ) ) {
			// Store for later use in the action hook
			$args['vanpos_include_child_orders'] = $child_order_ids;
		}

		return $args;
	}

	/**
	 * Add child orders to customer account orders
	 * This modifies the global $customer_orders variable used in the template
	 *
	 * @param bool $has_orders Whether customer has orders.
	 * @return void
	 */
	public static function add_child_orders_to_account_orders( $has_orders ) {
		global $customer_orders;
		
		if ( ! isset( $customer_orders ) || ! is_object( $customer_orders ) || ! isset( $customer_orders->orders ) ) {
			return;
		}

		$customer_id = get_current_user_id();
		if ( ! $customer_id ) {
			return;
		}

		// Get all primary orders for this customer
		$primary_orders = wc_get_orders( array(
			'customer_id' => $customer_id,
			'meta_key'    => '_vanpos_order_type',
			'meta_value'  => 'primary_rental',
			'limit'       => -1,
			'return'      => 'ids',
		) );

		if ( empty( $primary_orders ) ) {
			return;
		}

		// Get all child orders for these primary orders
		$child_order_ids = array();
		foreach ( $primary_orders as $primary_order_id ) {
			if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
				continue;
			}
			$child_orders = VanPOS_Order_Manager::get_payment_orders( $primary_order_id );
			foreach ( $child_orders as $child_order ) {
				// Skip refund objects — they lack methods like get_customer_id()
				if ( $child_order instanceof WC_Order_Refund ) {
					continue;
				}
				// Verify child order belongs to same customer
				if ( $child_order->get_customer_id() === $customer_id ) {
					$child_order_ids[] = $child_order->get_id();
				}
			}
		}

		// Get child orders and merge with existing orders
		if ( ! empty( $child_order_ids ) ) {
			$existing_ids = array_map( function( $order ) {
				$order_obj = is_object( $order ) ? $order : wc_get_order( $order );
				return $order_obj ? $order_obj->get_id() : 0;
			}, $customer_orders->orders );

			$child_orders = wc_get_orders( array(
				'include' => $child_order_ids,
				'limit'   => -1,
			) );

			foreach ( $child_orders as $child_order ) {
				if ( ! in_array( $child_order->get_id(), $existing_ids, true ) ) {
					$customer_orders->orders[] = $child_order;
				}
			}

			// Update total count and sort orders by date (newest first)
			$customer_orders->total = count( $customer_orders->orders );
			
			// Sort orders by date (newest first)
			usort( $customer_orders->orders, function( $a, $b ) {
				$order_a = is_object( $a ) ? $a : wc_get_order( $a );
				$order_b = is_object( $b ) ? $b : wc_get_order( $b );
				if ( ! $order_a || ! $order_b ) {
					return 0;
				}
				$date_a = $order_a->get_date_created()->getTimestamp();
				$date_b = $order_b->get_date_created()->getTimestamp();
				return $date_b - $date_a; // Descending order
			} );
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
			$due_datetime = new DateTime( $due_date );
			echo esc_html( date_i18n( get_option( 'date_format' ), $due_datetime->getTimestamp() ) );
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
