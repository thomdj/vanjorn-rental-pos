<?php
/**
 * Order Manager for VAN-Jorn Rental Platform
 * Handles Primary Rental Orders and Child Payment Orders
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order Manager Class
 */
class VanPOS_Order_Manager {

	/** Fallback VAT rate (21% BTW) used only when a tax class yields no rate. */
	const VAT_RATE = 0.21;

	/**
	 * Fractional VAT rate (e.g. 0.21) for a given product/tax class.
	 *
	 * Derives the live rate from WooCommerce tax tables via WC_Tax::get_rates(),
	 * so 21% / 9% / 0% products and any future rate change are all handled without
	 * hardcoding. Falls back to VAT_RATE only when no rate can be resolved (tax
	 * tables empty, or called too early). Pass a WC_Order_Item_Product or a tax
	 * class string.
	 *
	 * @param WC_Order_Item_Product|string|null $source Item to read the tax class from, or a class string.
	 * @return float Fractional rate (0.21 for 21%), 0.0 for a genuine zero-rate class.
	 */
	public static function get_vat_rate_fraction( $source = '' ) {
		if ( ! class_exists( 'WC_Tax' ) ) {
			return self::VAT_RATE;
		}

		$tax_class = '';
		if ( is_a( $source, 'WC_Order_Item_Product' ) ) {
			$product = $source->get_product();
			$tax_class = $product ? $product->get_tax_class() : $source->get_tax_class();
		} elseif ( is_string( $source ) ) {
			$tax_class = $source;
		}

		$rates = WC_Tax::get_rates( $tax_class );
		if ( empty( $rates ) ) {
			// Reduced/zero classes legitimately return no standard rate; but an
			// empty result can also mean "not resolvable here". Treat a known
			// non-standard class as its declared rate (often 0), else fall back.
			return ( '' === $tax_class ) ? self::VAT_RATE : 0.0;
		}

		$first = reset( $rates );
		$percent = isset( $first['rate'] ) ? (float) $first['rate'] : 0.0;
		return $percent / 100.0;
	}

	/**
	 * WC tax-rate row ID for a given product/tax class.
	 *
	 * Resolves the live rate ID from WooCommerce tax tables via WC_Tax::get_rates()
	 * so set_taxes() / set_rate_id() bind to the correct rate row rather than
	 * assuming it is always 1. Falls back to 1 only when no rate can be resolved
	 * (tax tables empty, or called too early). Pass a WC_Order_Item_Product or a
	 * tax-class string; the empty string resolves the standard rate class.
	 *
	 * @param WC_Order_Item_Product|string|null $source Item to read the tax class from, or a class string.
	 * @return int WC tax rate row ID.
	 */
	public static function get_vat_rate_id( $source = '' ) {
		if ( ! class_exists( 'WC_Tax' ) ) {
			return 1;
		}

		$tax_class = '';
		if ( is_a( $source, 'WC_Order_Item_Product' ) ) {
			$product   = $source->get_product();
			$tax_class = $product ? $product->get_tax_class() : $source->get_tax_class();
		} elseif ( is_string( $source ) ) {
			$tax_class = $source;
		}

		$rates = WC_Tax::get_rates( $tax_class );
		if ( empty( $rates ) ) {
			return 1;
		}

		$rate_id = (int) key( $rates );
		return $rate_id > 0 ? $rate_id : 1;
	}

	/**
	 * Split a tax-inclusive gross amount into net (ex-VAT) and VAT components.
	 *
	 * Single source for the inclusive-VAT split used wherever a gross rental amount
	 * is recorded as a WooCommerce line item: child payment orders, the deposit
	 * line item at checkout, and re-rated child orders after a date change. A
	 * non-positive rate or gross yields zero tax (tax-exempt).
	 *
	 * @param float $gross Tax-inclusive amount.
	 * @param float $rate  Fractional VAT rate (e.g. 0.21).
	 * @return array{excl:float,tax:float} Net amount and the included VAT.
	 */
	public static function split_inclusive_vat( $gross, $rate ) {
		$gross = (float) $gross;
		$rate  = (float) $rate;
		if ( $rate <= 0 || $gross <= 0 ) {
			return array( 'excl' => round( $gross, 2 ), 'tax' => 0.0 );
		}
		$excl = round( $gross / ( 1 + $rate ), 2 );
		return array( 'excl' => $excl, 'tax' => round( $gross - $excl, 2 ) );
	}

	/**
	 * Apply an inclusive-VAT split to a product line item.
	 *
	 * Sets the net amount as subtotal/total, the included VAT as subtotal/total tax,
	 * and the per-rate tax array — the structure WooCommerce expects so a later
	 * recalculation does not add VAT on top of the gross figure. Centralises the
	 * set_taxes() shape used by every inclusive-VAT line item.
	 *
	 * @param WC_Order_Item_Product $item    Line item to write to.
	 * @param float                 $excl    Net (ex-VAT) amount.
	 * @param float                 $tax     Included VAT amount.
	 * @param int                   $rate_id WC tax rate row id.
	 * @return void
	 */
	public static function apply_inclusive_vat_to_item( $item, $excl, $tax, $rate_id ) {
		$item->set_subtotal( $excl );
		$item->set_total( $excl );
		$item->set_subtotal_tax( $tax );
		$item->set_total_tax( $tax );
		$item->set_taxes( array(
			'total'    => array( $rate_id => $tax ),
			'subtotal' => array( $rate_id => $tax ),
		) );
	}

	/**
	 * Create primary rental order
	 *
	 * @param int    $product_id Product ID.
	 * @param string $pickup_date Pickup date (Y-m-d).
	 * @param string $pickup_time Pickup time slot.
	 * @param string $return_date Return date (Y-m-d).
	 * @param string $return_time Return time slot.
	 * @param array  $cart_item_data Additional cart item data.
	 * @return int|WP_Error Order ID or error.
	 */
	public static function create_primary_rental_order( $product_id, $pickup_date, $pickup_time, $return_date, $return_time, $cart_item_data = array() ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is required.', 'vanjorn-rental-pos' ) );
		}

		// Get customer from current cart
		$customer_id = get_current_user_id();
		$customer_email = '';
		$customer_data = array();

		if ( $customer_id ) {
			$user = get_userdata( $customer_id );
			$customer_email = $user->user_email;
			$customer_data = array(
				'first_name' => get_user_meta( $customer_id, 'billing_first_name', true ),
				'last_name'  => get_user_meta( $customer_id, 'billing_last_name', true ),
				'email'      => $customer_email,
				'phone'      => get_user_meta( $customer_id, 'billing_phone', true ),
			);
		} else {
			// Get from session if available.
			// WC()->session is null in non-frontend contexts (admin AJAX, REST API, WP-CLI,
			// cron). Guard before dereferencing so a programmatic call doesn't fatal.
			if ( WC()->session ) {
				$customer_email = WC()->session->get( 'billing_email' );
				if ( $customer_email ) {
					$customer_data = array(
						'email' => $customer_email,
					);
				}
			}
		}

		// Create order
		$order = wc_create_order( array(
			'customer_id' => $customer_id,
			'status'      => 'pending',
		) );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'invalid_product', __( 'Invalid product.', 'vanjorn-rental-pos' ) );
		}

		// Calculate rental days (Kestrel-compatible: includes both pickup and return day)
		$pickup_datetime = new DateTime( $pickup_date );
		$return_datetime = new DateTime( $return_date );
		$days = $pickup_datetime->diff( $return_datetime )->days + 1;

		// CMIT CODE - bill by NIGHTS (days - 1). $days stays inclusive for meta/display.
		$nights = VanPOS_Functions::rental_nights_from_dates( $pickup_date, $return_date );

		// Calculate price using rental plugin's full pricing logic (tiers, overrides, etc.)
		$total_price = VanPOS_Functions::calculate_rental_price( $product_id, $nights );

		// Detect short-term booking using admin setting
		$short_term_threshold = (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_days_before_pickup', 14 );
		$order_date = new DateTime( current_time( 'Y-m-d' ) );
		$days_until_pickup = $order_date->diff( $pickup_datetime )->days;
		$is_short_term = ( $days_until_pickup < $short_term_threshold );

		// Read deposit percentage from settings
		$deposit_pct = (float) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 );

		if ( $is_short_term ) {
			// Full payment (100%) for short-term bookings
			$initial_payment = $total_price; // 100%
			$remaining_payment = 0;
			
			// Set short-term booking flag
			$order->update_meta_data( '_is_short_term_booking', 'yes' );
		} else {
			// Split payment based on configured deposit percentage
			$initial_payment = $total_price * ( $deposit_pct / 100 );
			$remaining_payment = $total_price - $initial_payment;
			
			// Set short-term booking flag
			$order->update_meta_data( '_is_short_term_booking', 'no' );
		}

		// Add product to order
		$order->add_product( $product, 1, array(
			'subtotal' => $initial_payment,
			'total'    => $initial_payment,
		) );

		// Add rental metadata
		$order->update_meta_data( '_vanpos_order_type', 'primary_rental' );
		$order->update_meta_data( '_vanpos_pickup_date', $pickup_date );
		$order->update_meta_data( '_vanpos_pickup_time', $pickup_time );
		$order->update_meta_data( '_vanpos_return_date', $return_date );
		$order->update_meta_data( '_vanpos_return_time', $return_time );
		$order->update_meta_data( '_vanpos_rental_days', $days );
		$order->update_meta_data( '_vanpos_rental_nights', $nights );
		$order->update_meta_data( '_vanpos_total_price', $total_price );
		$order->update_meta_data( '_vanpos_initial_payment', $initial_payment );
		$order->update_meta_data( '_vanpos_initial_payment_formatted', self::format_price( $initial_payment ) );
		$order->update_meta_data( '_vanpos_remaining_payment', $remaining_payment );
		// Explicit false defaults; the child-order factories flip these to 'yes' when
		// the corresponding remaining / security-deposit child is actually created.
		$order->update_meta_data( '_vanpos_order_has_remaining_payment', 'no' );
		$order->update_meta_data( '_vanpos_order_has_security_deposit', 'no' );
		$order->update_meta_data( '_vanpos_booking_reference', self::generate_booking_reference() );

		// Add additional options
		if ( isset( $cart_item_data['vanpos_include_dog'] ) && $cart_item_data['vanpos_include_dog'] ) {
			$order->update_meta_data( '_vanpos_include_dog', true );
		}
		if ( isset( $cart_item_data['vanpos_include_cleaning'] ) && $cart_item_data['vanpos_include_cleaning'] ) {
			$order->update_meta_data( '_vanpos_include_cleaning', true );
		}

		// Set customer data
		if ( ! empty( $customer_data ) ) {
			if ( isset( $customer_data['first_name'] ) ) {
				$order->set_billing_first_name( $customer_data['first_name'] );
			}
			if ( isset( $customer_data['last_name'] ) ) {
				$order->set_billing_last_name( $customer_data['last_name'] );
			}
			if ( isset( $customer_data['email'] ) ) {
				$order->set_billing_email( $customer_data['email'] );
			}
			if ( isset( $customer_data['phone'] ) ) {
				$order->set_billing_phone( $customer_data['phone'] );
			}
		}

		// Calculate totals
		self::calculate_totals_internal( $order );

		// Save order
		$order->save();

		// Note: The security deposit child order is created at checkout processing
		// (woocommerce_checkout_order_processed). The remaining payment child is
		// created by VanPOS_Customer_Account on payment complete.

		return $order->get_id();
	}

	/**
	 * Run WC_Order::calculate_totals() with the discount-meta sync suspended.
	 *
	 * VanPOS calls calculate_totals() while building or force-totalling its own
	 * orders. That internal recalculation must never be mistaken by
	 * VanPOS_Discount_Manager for an admin coupon apply/remove — which would
	 * overwrite the payment-split meta from a transient total. This wrapper
	 * brackets the call with the suspend flag.
	 *
	 * @param WC_Order $order Order to recalculate.
	 * @return void
	 */
	private static function calculate_totals_internal( $order ) {
		$has_discount_mgr = class_exists( 'VanPOS_Discount_Manager' );
		if ( $has_discount_mgr ) {
			VanPOS_Discount_Manager::$suspended = true;
		}
		try {
			$order->calculate_totals();
		} finally {
			if ( $has_discount_mgr ) {
				VanPOS_Discount_Manager::$suspended = false;
			}
		}
	}

	/**
	 * Create child payment order
	 *
	 * @param int    $primary_order_id Primary order ID.
	 * @param string $payment_type Payment type. Live types: 'remaining', 'security_deposit', 'extension'.
	 *                             'initial' and 'deposit' are vestigial — no live code path creates
	 *                             child orders of those types.
	 * @param float  $amount Payment amount.
	 * @param string $description Payment description.
	 * @return int|WP_Error Order ID or error.
	 */
	public static function create_payment_order( $primary_order_id, $payment_type, $amount, $description = '' ) {
		$primary_order = wc_get_order( $primary_order_id );
		if ( ! $primary_order ) {
			return new WP_Error( 'invalid_order', __( 'Invalid primary order.', 'vanjorn-rental-pos' ) );
		}

		// Idempotency self-guard. Call-site dedup via has_payment_order() is
		// check-then-create and non-atomic, so a Mollie redirect+webhook race or
		// an admin double-submit can re-enter this factory after a sibling child
		// order has already been committed. Re-check here — mirroring
		// create_security_deposit_order() — and return the existing child's id
		// instead of minting a duplicate. Idempotent: callers already treat a
		// non-error return as success.
		$existing = self::find_payment_order( $primary_order_id, $payment_type );
		if ( $existing ) {
			return $existing->get_id();
		}

		// Create child order
		$order = wc_create_order( array(
			'customer_id' => $primary_order->get_customer_id(),
			'status'      => 'pending',
			'parent'      => $primary_order_id,
		) );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// Copy customer data
		$order->set_billing_first_name( $primary_order->get_billing_first_name() );
		$order->set_billing_last_name( $primary_order->get_billing_last_name() );
		$order->set_billing_email( $primary_order->get_billing_email() );
		$order->set_billing_phone( $primary_order->get_billing_phone() );
		$order->set_billing_address_1( $primary_order->get_billing_address_1() );
		$order->set_billing_city( $primary_order->get_billing_city() );
		$order->set_billing_postcode( $primary_order->get_billing_postcode() );
		$order->set_billing_country( $primary_order->get_billing_country() );

		// Determine whether this child order is a taxable rental payment.
		// Security deposits are VAT-exempt; remaining and extension are taxable.
		$is_taxable      = ( 'security_deposit' !== $payment_type );
		$prices_incl_tax = ( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );

		$payment_label = $description ?: self::get_payment_type_label( $payment_type );

		// Pre-compute the total line-item subtotal from the parent so we can
		// distribute $amount proportionally across items.
		// This is currently a single-line-item guard: primary rental orders are
		// created with exactly one product line, so the ratio is always 1.0.
		// The proportional logic below is correct for multi-item orders should
		// that assumption ever change — every item receives its fair share rather
		// than a duplicate of the full amount.
		$parent_items_subtotal = 0.0;
		foreach ( $primary_order->get_items() as $pi ) {
			if ( is_a( $pi, 'WC_Order_Item_Product' ) ) {
				$parent_items_subtotal += (float) $pi->get_subtotal();
			}
		}

		// Copy product items from parent order, with proper VAT split
		// for taxable types when prices include tax.
		foreach ( $primary_order->get_items() as $item_id => $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			// Portion of $amount allocated to this line item.
			$item_subtotal = (float) $item->get_subtotal();
			$ratio         = ( $parent_items_subtotal > 0.0 )
				? $item_subtotal / $parent_items_subtotal
				: 1.0;
			$item_amount   = round( $amount * $ratio, 2 );

			$product_item = new WC_Order_Item_Product();
			$product_item->set_product( $item->get_product() );
			$product_item->set_quantity( $item->get_quantity() );
			$product_item->set_name( $item->get_name() . ' - ' . $payment_label );

			if ( $is_taxable && $prices_incl_tax && $item_amount > 0 ) {
				// Prices include BTW — split into ex-VAT + tax using the item's own rate.
				$rate_id = self::get_vat_rate_id( $item );
				$split   = self::split_inclusive_vat( $item_amount, self::get_vat_rate_fraction( $item ) );
				self::apply_inclusive_vat_to_item( $product_item, $split['excl'], $split['tax'], $rate_id );
			} else {
				$product_item->set_subtotal( $item_amount );
				$product_item->set_total( $item_amount );
			}

			// Copy all metadata from original item (rental dates, etc.).
			// Underscore-prefixed rental keys (_vanpos_pickup_date, _vanpos_return_date,
			// etc.) must never appear on line items — they belong at order level only.
			// Filter them out here so child orders do not inherit strays written to a
			// parent's line item by an older code path (e.g. the admin add-order tool).
			$stray_item_meta_keys = array(
				'_vanpos_pickup_date',
				'_vanpos_return_date',
				'_vanpos_pickup_time',
				'_vanpos_return_time',
				'_vanpos_rental_days',
				'_vanpos_include_dog',
				'_vanpos_include_cleaning',
			);
			foreach ( $item->get_meta_data() as $meta ) {
				if ( in_array( $meta->key, $stray_item_meta_keys, true ) ) {
					continue;
				}
				$product_item->add_meta_data( $meta->key, $meta->value );
			}

			// Add payment type as meta for display
			$product_item->add_meta_data( '_vanpos_payment_type', $payment_type );
			$product_item->add_meta_data( '_vanpos_payment_description', $payment_label );

			$order->add_item( $product_item );
		}

		// If no product items were found, add a fee as fallback
		if ( count( $order->get_items() ) === 0 ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( $payment_label );
			$fee->set_amount( $amount );
			$fee->set_total( $amount );
			$order->add_item( $fee );
		}

		// Add explicit tax item row for taxable child orders.
		// $amount is the intended total for the child order; the ex-VAT/tax split
		// mirrors the per-item calculation above and is re-derived here from
		// $amount rather than summing item taxes, which avoids accumulating any
		// per-item rounding delta.
		if ( $is_taxable && $prices_incl_tax && $amount > 0 ) {
			$rate_item = null;
			foreach ( $order->get_items() as $oi ) {
				if ( is_a( $oi, 'WC_Order_Item_Product' ) ) {
					$rate_item = $oi;
					break;
				}
			}
			$rate_src = $rate_item ? $rate_item : '';
			$rate     = self::get_vat_rate_fraction( $rate_src );
			$rate_id  = self::get_vat_rate_id( $rate_src );
			$split = self::split_inclusive_vat( $amount, $rate );
			$excl  = $split['excl'];
			$tax   = $split['tax'];
			if ( $tax > 0 ) {
				$tax_item   = new WC_Order_Item_Tax();
				$rate_label = class_exists( 'WC_Tax' ) ? WC_Tax::get_rate_label( $rate_id ) : '';
				if ( ! $rate_label ) {
					/* translators: %d is the WooCommerce tax rate ID */
					$rate_label = sprintf( __( 'VAT-%d', 'vanjorn-rental-pos' ), $rate_id );
				}
				$tax_item->set_name( $rate_label );
				$tax_item->set_rate_id( $rate_id );
				$tax_item->set_tax_total( $tax );
				$tax_item->set_shipping_tax_total( 0 );
				$order->add_item( $tax_item );
			}
		}

		// Add metadata
		$order->update_meta_data( '_vanpos_order_type', 'payment_order' );
		$order->update_meta_data( '_vanpos_payment_type', $payment_type );
		$order->update_meta_data( '_vanpos_primary_order_id', $primary_order_id );
		$order->update_meta_data( '_vanpos_booking_reference', $primary_order->get_meta( '_vanpos_booking_reference' ) );

		// Copy rental dates from parent
		$pickup_date = $primary_order->get_meta( '_vanpos_pickup_date' );
		$return_date = $primary_order->get_meta( '_vanpos_return_date' );
		$pickup_time = $primary_order->get_meta( '_vanpos_pickup_time' );
		$return_time = $primary_order->get_meta( '_vanpos_return_time' );
		
		if ( $pickup_date ) {
			$order->update_meta_data( '_vanpos_pickup_date', $pickup_date );
		}
		if ( $return_date ) {
			$order->update_meta_data( '_vanpos_return_date', $return_date );
		}
		if ( $pickup_time ) {
			$order->update_meta_data( '_vanpos_pickup_time', $pickup_time );
		}
		if ( $return_time ) {
			$order->update_meta_data( '_vanpos_return_time', $return_time );
		}

		// Copy email-friendly formatted meta from parent (for AutomateWoo templates),
		// then fill any formatted keys the parent itself was missing.
		self::copy_email_meta( $primary_order, $order );
		self::stamp_missing_formatted_meta( $order, array(
			'pickup_date'       => $pickup_date,
			'return_date'       => $return_date,
			'total_price'       => $primary_order->get_meta( '_vanpos_total_price' ),
			'remaining_payment' => $primary_order->get_meta( '_vanpos_remaining_payment' ),
		) );

		// Copy short-term booking flag from parent (for AutomateWoo triggers).
		// Only set on rental payment orders (deposit/remaining), not security deposits
		// which use _is_short_term_deposit instead.
		$is_short_term_val = $primary_order->get_meta( '_is_short_term_booking' );
		if ( $payment_type !== 'security_deposit' && $is_short_term_val !== '' && $is_short_term_val !== false ) {
			$order->update_meta_data( '_is_short_term_booking', $is_short_term_val );
		}

		// Check if short-term booking (used for due date calculation below)
		$is_short_term = $is_short_term_val === 'yes';

		// Calculate and set due date based on payment type and booking type.
		// 'initial' is collected at checkout — no future due date needed.
		// 'remaining', 'extension', and 'security_deposit' all need due dates.
		$due_date_str = null;
		if ( $payment_type === 'initial' ) {
			// Initial payment is collected at checkout — no due date
			$due_date_str = null;
		} elseif ( $is_short_term && $payment_type === 'security_deposit' ) {
			// Short-term booking security deposit: Due date = Order date + 1 day (tomorrow)
			$order_date = $primary_order->get_date_created()->format( 'Y-m-d' );
			$order_datetime = new DateTime( $order_date );
			$due_date = clone $order_datetime;
			$due_date->modify( '+1 day' );
			$due_date_str = $due_date->format( 'Y-m-d' );
			
			// Set short-term deposit flag
			$order->update_meta_data( '_is_short_term_deposit', 'yes' );
		} elseif ( $pickup_date ) {
			// Standard booking: Calculate based on pickup date using admin settings
			if ( $payment_type === 'remaining' || $payment_type === 'extension' ) {
				$due_date_str = VanPOS_Functions::calculate_due_date_from_pickup( $pickup_date, 'remaining' );
			} elseif ( $payment_type === 'security_deposit' ) {
				$due_date_str = VanPOS_Functions::calculate_due_date_from_pickup( $pickup_date, 'security_deposit' );

				// Set short-term deposit flag (only relevant for security deposits)
				$order->update_meta_data( '_is_short_term_deposit', 'no' );
			}
		}

		// Set due date meta
		if ( $due_date_str ) {
			$order->update_meta_data( '_vanpos_due_date', $due_date_str );
			$order->update_meta_data( '_payment_due_date', $due_date_str );
			$order->update_meta_data( '_payment_due_date_formatted', self::format_meta_date( $due_date_str ) );
		}

		// Add new meta keys for AutomateWoo
		$order->update_meta_data( '_payment_window_open', 'yes' );
		$order->update_meta_data( '_reminder_1_sent', 'no' );
		$order->update_meta_data( '_reminder_2_sent', 'no' );

		// Initial payment-request sent flag — one per payment type so each workflow
		// has its own gate. Only the flag matching this order's payment type is set;
		// the other flag is not relevant to this child order.
		if ( $payment_type === 'security_deposit' ) {
			$order->update_meta_data( '_security_deposit_sent', 'no' );
		} elseif ( $payment_type === 'initial' || $payment_type === 'remaining' ) {
			$order->update_meta_data( '_remaining_sent', 'no' );
		} elseif ( $payment_type === 'extension' ) {
			$order->update_meta_data( '_extension_sent', 'no' );
		}

		// Set payment amount type
		if ( $payment_type === 'security_deposit' || $payment_type === 'extension' ) {
			$order->update_meta_data( '_payment_amount_type', 'fixed' );
		} elseif ( $payment_type === 'initial' || $payment_type === 'remaining' ) {
			$order->update_meta_data( '_payment_amount_type', 'percentage' );
		}

		// Extension orders: store the charge amount as dedicated meta for email
		// templates and the backfill tool. $amount is the extension charge itself.
		if ( $payment_type === 'extension' ) {
			$order->update_meta_data( '_vanpos_extension_amount', round( $amount, 2 ) );
			$order->update_meta_data( '_vanpos_extension_amount_formatted', self::format_price( $amount ) );
		}

		// Only the refundable security deposit is VAT-exempt
		// The initial payment ('initial') is a payment for services and includes VAT
		if ( $payment_type === 'security_deposit' ) {
			$order->update_meta_data( '_vanpos_no_vat', true );
		}

		// For taxable child orders where we already built proper VAT line items,
		// skip removing taxes and recalculating — just set totals directly.
		// For non-taxable orders (security_deposit), remove auto-generated taxes.
		if ( $is_taxable && $prices_incl_tax && $amount > 0 ) {
			// VAT line items already set correctly above — don't touch them.
			$order->set_shipping_total( 0 );
			$order->set_discount_total( 0 );
			$order->set_total( $amount );
		} else {
			// Remove any taxes first
			$order->remove_order_items( 'tax' );
			
			// Set shipping to 0
			$order->set_shipping_total( 0 );
			
			// Set discount total to 0 to ensure clean calculation
			$order->set_discount_total( 0 );
			
			// Calculate totals (this will calculate subtotal from items and set taxes to 0 since we removed tax items)
			self::calculate_totals_internal( $order );
			
			// Force the total to be exactly the payment amount (override any calculation)
			// This ensures no taxes, fees, or shipping are added
			$order->set_total( $amount );
		}
		
		// Add order note indicating this is a child order
		$parent_order_number = $primary_order->get_order_number();
		$payment_type_label = self::get_payment_type_label( $payment_type );
		$order->add_order_note( 
			sprintf(
				/* translators: %1$s is the payment type, %2$s is the parent order number */
				__( 'Child payment order created: %1$s for parent order #%2$s', 'vanjorn-rental-pos' ),
				$payment_type_label,
				$parent_order_number
			),
			false,
			false // Not a customer note, admin only
		);
		
		// Add note to parent order and record that a remaining child now exists.
		$primary_order->add_order_note(
			sprintf(
				/* translators: %1$s is the payment type, %2$s is the child order number */
				__( 'Child payment order #%2$s created for %1$s', 'vanjorn-rental-pos' ),
				$payment_type_label,
				$order->get_order_number()
			),
			false,
			false
		);
		// A remaining child order now exists for this parent — record it explicitly
		// so downstream reads never depend on absence-means-false.
		if ( self::is_remaining_payment( $payment_type ) ) {
			$primary_order->update_meta_data( '_vanpos_order_has_remaining_payment', 'yes' );
		}
		// Meta-only on the parent; a full save() here can clobber the parent's
		// just-set paid status when create_child_order_on_payment_complete runs.
		$primary_order->save_meta_data();

		$order->save();

		return $order->get_id();
	}

	/**
	 * Create security deposit order
	 *
	 * @param int $primary_order_id Primary order ID.
	 * @return int|WP_Error Order ID or error.
	 */
	public static function create_security_deposit_order( $primary_order_id ) {
		$primary_order = wc_get_order( $primary_order_id );
		if ( ! $primary_order ) {
			return new WP_Error( 'invalid_order', __( 'Invalid primary order.', 'vanjorn-rental-pos' ) );
		}

		// Check if security deposit order already exists
		$existing = self::find_payment_order( $primary_order_id, 'security_deposit' );
		if ( $existing ) {
			return $existing->get_id();
		}

		// Get security deposit product
		$security_deposit_product_id = VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' );
		if ( empty( $security_deposit_product_id ) ) {
			return new WP_Error( 'no_deposit_product', __( 'Security deposit product not configured.', 'vanjorn-rental-pos' ) );
		}

		$security_deposit_product = wc_get_product( $security_deposit_product_id );
		if ( ! $security_deposit_product ) {
			return new WP_Error( 'invalid_deposit_product', __( 'Invalid security deposit product.', 'vanjorn-rental-pos' ) );
		}

		$security_deposit_amount = (float) $security_deposit_product->get_price();
		if ( $security_deposit_amount <= 0 ) {
			return new WP_Error( 'invalid_deposit_amount', __( 'Security deposit amount must be greater than 0.', 'vanjorn-rental-pos' ) );
		}

		// Create child order
		$order = wc_create_order( array(
			'customer_id' => $primary_order->get_customer_id(),
			'status'      => 'pending',
			'parent'      => $primary_order_id,
		) );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// Copy customer data
		$order->set_billing_first_name( $primary_order->get_billing_first_name() );
		$order->set_billing_last_name( $primary_order->get_billing_last_name() );
		$order->set_billing_email( $primary_order->get_billing_email() );
		$order->set_billing_phone( $primary_order->get_billing_phone() );
		$order->set_billing_address_1( $primary_order->get_billing_address_1() );
		$order->set_billing_city( $primary_order->get_billing_city() );
		$order->set_billing_postcode( $primary_order->get_billing_postcode() );
		$order->set_billing_country( $primary_order->get_billing_country() );

		// Add security deposit product to order
		$product_item = new WC_Order_Item_Product();
		$product_item->set_product( $security_deposit_product );
		$product_item->set_quantity( 1 );
		$product_item->set_name( $security_deposit_product->get_name() );
		$product_item->set_subtotal( $security_deposit_amount );
		$product_item->set_total( $security_deposit_amount );
		$product_item->add_meta_data( '_vanpos_payment_type', 'security_deposit' );
		$product_item->add_meta_data( '_vanpos_payment_description', __( 'Security deposit', 'vanjorn-rental-pos' ) );
		$order->add_item( $product_item );

		// Add metadata
		$order->update_meta_data( '_vanpos_order_type', 'payment_order' );
		$order->update_meta_data( '_vanpos_payment_type', 'security_deposit' );
		$order->update_meta_data( '_vanpos_primary_order_id', $primary_order_id );
		$order->update_meta_data( '_vanpos_booking_reference', $primary_order->get_meta( '_vanpos_booking_reference' ) );
		$order->update_meta_data( '_vanpos_no_vat', true ); // Security deposit is not subject to VAT
		$order->update_meta_data( '_vanpos_security_deposit_payment', $security_deposit_amount );
		$order->update_meta_data( '_vanpos_security_deposit_payment_formatted', self::format_price( $security_deposit_amount ) );

		// Copy important metadata from parent
		$pickup_date = $primary_order->get_meta( '_vanpos_pickup_date' );
		$return_date = $primary_order->get_meta( '_vanpos_return_date' );
		$pickup_time = $primary_order->get_meta( '_vanpos_pickup_time' );
		$return_time = $primary_order->get_meta( '_vanpos_return_time' );
		$booking_ref = $primary_order->get_meta( '_vanpos_booking_reference' );

		// Item-level fallback for rental dates. On the short-term frontend checkout
		// path, VanPOS_Deposit_Manager::promote_rental_meta_to_order() never runs
		// (it is gated on the deposit split), so the parent has no order-level
		// _vanpos_pickup_date yet when this factory is called at checkout time.
		// Read the first rental item's dates so the due-date and short-term
		// derivation below are correct for short-term bookings too.
		if ( empty( $pickup_date ) || empty( $return_date ) ) {
			foreach ( $primary_order->get_items() as $pi ) {
				if ( empty( $pickup_date ) ) {
					$pickup_date = $pi->get_meta( 'vanpos_pickup_date' );
					if ( empty( $pickup_date ) ) {
						$pickup_date = $pi->get_meta( 'wcrp_rental_products_rent_from' );
					}
				}
				if ( empty( $return_date ) ) {
					$return_date = $pi->get_meta( 'vanpos_return_date' );
					if ( empty( $return_date ) ) {
						$return_date = $pi->get_meta( 'wcrp_rental_products_rent_to' );
					}
				}
				if ( $pickup_date && $return_date ) {
					break;
				}
			}
		}

		if ( $pickup_date ) {
			$order->update_meta_data( '_vanpos_pickup_date', $pickup_date );
		}
		if ( $return_date ) {
			$order->update_meta_data( '_vanpos_return_date', $return_date );
		}
		if ( $pickup_time ) {
			$order->update_meta_data( '_vanpos_pickup_time', $pickup_time );
		}
		if ( $return_time ) {
			$order->update_meta_data( '_vanpos_return_time', $return_time );
		}
		if ( $booking_ref ) {
			$order->update_meta_data( '_vanpos_booking_reference', $booking_ref );
		}

		// Copy email-friendly formatted meta from parent (for AutomateWoo templates),
		// then fill any formatted keys the parent itself was missing. The fill step
		// is new for security deposits: previously, a short-term booking whose parent
		// lacked formatted meta produced a deposit child with blank values in
		// AutomateWoo emails. copy_email_meta() + stamp_missing_formatted_meta()
		// closes that gap, mirroring create_payment_order().
		self::copy_email_meta( $primary_order, $order );
		self::stamp_missing_formatted_meta( $order, array(
			'pickup_date'       => $pickup_date,
			'return_date'       => $return_date,
			'total_price'       => $primary_order->get_meta( '_vanpos_total_price' ),
			'remaining_payment' => $primary_order->get_meta( '_vanpos_remaining_payment' ),
		) );

		// Check if short-term booking. The short-term frontend checkout path never
		// records _is_short_term_booking on the parent (promote_rental_meta_to_order
		// is gated on the deposit split), so when the flag is absent, derive it from
		// the pickup date using the same threshold as the deposit-split decision.
		// More-than-threshold days away = long-term; otherwise short-term.
		$is_short_term_meta = $primary_order->get_meta( '_is_short_term_booking' );
		if ( '' === (string) $is_short_term_meta && $pickup_date ) {
			if ( class_exists( 'VanPOS_Deposit_Manager' ) ) {
				$is_short_term = ! VanPOS_Deposit_Manager::is_pickup_beyond_security_deposit_threshold( $pickup_date );
			} else {
				$threshold  = (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_days_before_pickup', 14 );
				$days_until = ( strtotime( $pickup_date ) - current_time( 'U' ) ) / DAY_IN_SECONDS;
				$is_short_term = ( $days_until <= $threshold );
			}
		} else {
			$is_short_term = ( 'yes' === $is_short_term_meta );
		}

		$due_date = null;
		$due_date_str = null;

		if ( $is_short_term ) {
			// Short-term booking: Due date = Order date + 1 day (tomorrow)
			$order_date = $primary_order->get_date_created()->format( 'Y-m-d' );
			$order_datetime = new DateTime( $order_date );
			$due_date_obj = clone $order_datetime;
			$due_date_obj->modify( '+1 day' );
			$due_date_str = $due_date_obj->format( 'Y-m-d' );
			$due_date = $due_date_str;
			
			// Set short-term deposit flag
			$order->update_meta_data( '_is_short_term_deposit', 'yes' );
		} else {
			// Standard booking: Due date = X days before pickup (from admin setting)
			if ( $pickup_date ) {
				$due_date = VanPOS_Functions::calculate_security_deposit_due_date( $pickup_date );
				if ( $due_date ) {
					// Ensure Y-m-d format
					if ( is_numeric( $due_date ) ) {
						$due_date_str = wp_date( 'Y-m-d', $due_date );
					} else {
						$due_date_str = $due_date;
					}
				}
			}
			
			// Set short-term deposit flag
			$order->update_meta_data( '_is_short_term_deposit', 'no' );
		}

		// Set due date meta
		if ( $due_date_str ) {
			$order->update_meta_data( '_vanpos_due_date', $due_date_str );
			$order->update_meta_data( '_payment_due_date', $due_date_str );
			$order->update_meta_data( '_payment_due_date_formatted', self::format_meta_date( $due_date_str ) );
		}

		// Add new meta keys for AutomateWoo
		$order->update_meta_data( '_payment_window_open', 'yes' );
		$order->update_meta_data( '_security_deposit_sent', 'no' );
		$order->update_meta_data( '_reminder_1_sent', 'no' );
		$order->update_meta_data( '_reminder_2_sent', 'no' );
		$order->update_meta_data( '_payment_amount_type', 'fixed' );

		// Remove any taxes (security deposit is not subject to tax)
		$order->remove_order_items( 'tax' );
		
		// Set shipping to 0
		$order->set_shipping_total( 0 );
		
		// Set discount total to 0
		$order->set_discount_total( 0 );
		
		// Calculate totals
		self::calculate_totals_internal( $order );
		
		// Force the total to be exactly the security deposit amount
		$order->set_total( $security_deposit_amount );
		
		// Add order note
		$parent_order_number = $primary_order->get_order_number();
		$order->add_order_note( 
			sprintf(
				/* translators: %s is the parent order number */
				__( 'Security deposit order created for parent order #%s', 'vanjorn-rental-pos' ),
				$parent_order_number
			),
			false,
			false
		);
		
		// Also add note to parent order
		$primary_order->add_order_note(
			sprintf(
				/* translators: %s is the child order number */
				__( 'Security deposit order #%s created', 'vanjorn-rental-pos' ),
				$order->get_order_number()
			),
			false,
			false
		);

		// Save security deposit order ID to parent order
		$primary_order->update_meta_data( '_vanpos_security_deposit_order_id', $order->get_id() );
		$primary_order->update_meta_data( '_vanpos_security_deposit_payment', $security_deposit_amount );
		$primary_order->update_meta_data( '_vanpos_security_deposit_payment_formatted', self::format_price( $security_deposit_amount ) );
		$primary_order->update_meta_data( '_vanpos_order_has_security_deposit', 'yes' );
		$primary_order->update_meta_data( '_vanpos_security_deposit_paid', 'no' );
		if ( isset( $due_date ) && $due_date ) {
			$primary_order->update_meta_data( '_vanpos_security_deposit_due_date', $due_date );
		}
		// Meta-only on the parent; a full save() here can clobber the parent's
		// just-set paid status when create_child_order_on_payment_complete runs.
		$primary_order->save_meta_data();
		
		$order->save();

		return $order->get_id();
	}

	/**
	 * Backfill rental metadata that may be missing on orders created before a
	 * given meta key existed, or on orders whose creation hook misfired.
	 *
	 * Extracted from VanPOS_Admin_Ajax and VanPOS_Admin_Order_Edit (previously
	 * identical private copies in both classes) so that any future change to
	 * the derivation logic is made in exactly one place.
	 *
	 * Derivation notes:
	 *   - `_vanpos_total_price` is derived from item-level `_vanpos_original_price`
	 *     when available, because that value records the full rental price before
	 *     the 50/50 payment split. Falling back to the order total and doubling it
	 *     via the deposit percentage is a last resort and is only correct when the
	 *     order total equals the initial deposit exactly.
	 *   - `order->get_total()` (gross) is used rather than `get_subtotal()` (net of
	 *     tax, excludes fees) so that VAT and fee amounts are not silently stripped
	 *     from the recorded price.
	 *
	 * NOT to be merged with VanPOS_Deposit_Manager::promote_rental_meta_to_order().
	 * They look similar but are deliberately separate operations with incompatible
	 * invariants:
	 *   - Source of truth for finances: this method BACK-DERIVES total/initial/
	 *     remaining (the canonical amounts are exactly what is missing here); the
	 *     checkout promoter READS the canonical amounts checkout just wrote.
	 *   - Overwrite semantics: this method is fill-if-empty and returns whether it
	 *     changed anything (admin callers re-save on true); the checkout promoter
	 *     runs once on a fresh order and writes unconditionally.
	 *   - Rental-item detection: this method also probes the wcrp_rental_products_*
	 *     fallback keys; the checkout promoter keys solely off vanpos_pickup_date.
	 * A unified method would just branch into both behaviours behind a flag.
	 *
	 * @param WC_Order $order Order to backfill.
	 * @return bool True if any meta was updated and saved.
	 */
	public static function update_missing_rental_metadata( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}
		$updated = false;

		// 1. Ensure order_type is stamped.
		if ( empty( $order->get_meta( '_vanpos_order_type' ) ) ) {
			$order->update_meta_data( '_vanpos_order_type', 'primary_rental' );
			$updated = true;
		}

		// 2. Promote item-level date meta to order level when missing.
		$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
		$return_date = $order->get_meta( '_vanpos_return_date' );
		if ( empty( $pickup_date ) || empty( $return_date ) ) {
			foreach ( $order->get_items() as $item ) {
				if ( empty( $pickup_date ) ) {
					$pickup_date = $item->get_meta( 'vanpos_pickup_date' );
					if ( empty( $pickup_date ) ) {
						$pickup_date = $item->get_meta( 'wcrp_rental_products_rent_from' );
					}
				}
				if ( empty( $return_date ) ) {
					$return_date = $item->get_meta( 'vanpos_return_date' );
					if ( empty( $return_date ) ) {
						$return_date = $item->get_meta( 'wcrp_rental_products_rent_to' );
					}
				}
				if ( $pickup_date && empty( $order->get_meta( '_vanpos_pickup_date' ) ) ) {
					$order->update_meta_data( '_vanpos_pickup_date', $pickup_date );
					$updated = true;
				}
				if ( $return_date && empty( $order->get_meta( '_vanpos_return_date' ) ) ) {
					$order->update_meta_data( '_vanpos_return_date', $return_date );
					$updated = true;
				}
			}
		}

		// 3. Derive rental-days count from dates.
		if ( $pickup_date && $return_date && class_exists( 'VanPOS_Functions' ) ) {
			$days = VanPOS_Functions::rental_days_from_dates( $pickup_date, $return_date );
			if ( $days > 0 && empty( $order->get_meta( '_vanpos_rental_days' ) ) ) {
				$order->update_meta_data( '_vanpos_rental_days', $days );
				$updated = true;
			}
		}

		// 4. Derive financial meta (total → initial → remaining).
		// Skip entirely for price-override orders: _vanpos_price_overridden = yes means
		// the admin deliberately set all financial values (a zero total is intentional,
		// not a gap), so back-deriving from order totals would corrupt that data.
		if ( 'yes' !== (string) $order->get_meta( '_vanpos_price_overridden' ) ) {
			$total_price     = (float) $order->get_meta( '_vanpos_total_price' );
			$initial_payment = (float) $order->get_meta( '_vanpos_initial_payment' );
			// Gross total (VAT + fees included) — do NOT use get_subtotal() which is
			// net of tax and excludes fee line items, causing understatement.
			$order_total = (float) $order->get_total();

			if ( $total_price <= 0 ) {
				// Prefer the per-item original price (pre-split value) over order total.
				$item_total = 0;
				foreach ( $order->get_items() as $item ) {
					$original_price = (float) $item->get_meta( '_vanpos_original_price' );
					$item_total    += $original_price > 0 ? $original_price : (float) $item->get_total();
				}
				if ( $item_total > 0 ) {
					$total_price = $item_total;
				} else {
					// Last resort: back-calculate from the deposit percentage.
					$deposit_pct = class_exists( 'VanPOS_Deposit_Manager' ) ? VanPOS_Deposit_Manager::get_deposit_percentage() : 50;
					$total_price = ( $deposit_pct > 0 && $deposit_pct < 100 )
						? ( $order_total / $deposit_pct ) * 100
						: $order_total * 2;
				}
				if ( $total_price > 0 ) {
					$order->update_meta_data( '_vanpos_total_price', $total_price );
					$updated = true;
				}
			}

			if ( $initial_payment <= 0 && $total_price > 0 ) {
				$initial_payment = $order_total;
				$order->update_meta_data( '_vanpos_initial_payment', $initial_payment );
				$updated = true;
			}

			if ( $total_price > 0 && $initial_payment > 0 ) {
				$remaining_payment = $total_price - $initial_payment;
				$current_remaining = (float) $order->get_meta( '_vanpos_remaining_payment' );
				if ( abs( $current_remaining - $remaining_payment ) > 0.01 ) {
					$order->update_meta_data( '_vanpos_remaining_payment', $remaining_payment );
					$updated = true;
				}
			}
		} // end ! price_overridden

		// 5. Generate a booking reference if missing.
		if ( empty( $order->get_meta( '_vanpos_booking_reference' ) ) ) {
			$order->update_meta_data( '_vanpos_booking_reference', self::generate_booking_reference() );
			$updated = true;
		}

		if ( $updated ) {
			$order->save();
		}

		return $updated;
	}

	/**
	 * Generate booking reference
	 *
	 * @return string
	 */
	public static function generate_booking_reference() {
		return 'VJ-' . wp_date( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 6, false ) );
	}

	/**
	 * Format a numeric value as a plain-text price string for stored meta.
	 *
	 * Single source for the price format used in all *_formatted meta
	 * (AutomateWoo templates, admin, PDF): the active WooCommerce currency format,
	 * stripped of HTML. Change it here once rather than at every wc_price() call.
	 *
	 * @param mixed $value Numeric value.
	 * @return string e.g. "€ 2.100,00".
	 */
	public static function format_price( $value ) {
		return wp_strip_all_tags( wc_price( (float) $value ) );
	}

	/**
	 * Format a date string as the canonical DD-MM-YYYY stored-meta date.
	 *
	 * Single source for the *_formatted date format used across admin, PDF, and
	 * AutomateWoo templates so the format choice lives in one place.
	 *
	 * @param string $date Any strtotime-parseable date.
	 * @return string e.g. "31-07-2026" (empty string if unparseable).
	 */
	public static function format_meta_date( $date ) {
		$ts = strtotime( (string) $date );
		return $ts ? date_i18n( 'd-m-Y', $ts ) : '';
	}

	/**
	 * Copy the email-friendly meta block from one order to another.
	 *
	 * Single source for the parent→child copy of AutomateWoo display meta. A value
	 * is copied only when the source actually has it (non-empty, not false); blanks
	 * are never propagated. Use stamp_missing_formatted_meta() afterwards to fill
	 * any keys the source itself was missing.
	 *
	 * @param WC_Order $from Source order (typically the primary rental).
	 * @param WC_Order $to   Destination order (typically a child payment order).
	 * @return void
	 */
	public static function copy_email_meta( $from, $to ) {
		$keys = array(
			'_vanpos_camper_name',
			'_vanpos_pickup_date_formatted',
			'_vanpos_return_date_formatted',
			'_vanpos_total_price',
			'_vanpos_total_price_formatted',
			'_vanpos_initial_payment',
			'_vanpos_initial_payment_formatted',
			'_vanpos_remaining_payment',
			'_vanpos_remaining_payment_formatted',
			'_vanpos_booking_reference',          // Aligns with VanPOS_Meta_Backfill::EMAIL_META_KEYS.
		);
		foreach ( $keys as $key ) {
			$value = $from->get_meta( $key );
			if ( $value !== '' && $value !== false ) {
				$to->update_meta_data( $key, $value );
			}
		}
	}

	/**
	 * Fill any missing email-friendly formatted meta on an order from raw values.
	 *
	 * Generates only the keys the order does not already have (never overwrites),
	 * so it is safe to call after copy_email_meta(). Camper name falls back to the
	 * first product line item's title when not supplied.
	 *
	 * @param WC_Order $order Order to fill.
	 * @param array    $args  Optional raw values: camper_name, pickup_date,
	 *                        return_date, total_price, remaining_payment.
	 * @return void
	 */
	public static function stamp_missing_formatted_meta( $order, $args = array() ) {
		if ( ! $order->get_meta( '_vanpos_camper_name' ) ) {
			$name = isset( $args['camper_name'] ) ? (string) $args['camper_name'] : '';
			if ( '' === $name ) {
				foreach ( $order->get_items() as $pi ) {
					if ( is_a( $pi, 'WC_Order_Item_Product' ) ) {
						$prod = $pi->get_product();
						$name = $prod ? $prod->get_title() : $pi->get_name();
						break;
					}
				}
			}
			if ( '' !== (string) $name ) {
				$order->update_meta_data( '_vanpos_camper_name', $name );
			}
		}

		$pickup = isset( $args['pickup_date'] ) ? $args['pickup_date'] : '';
		if ( $pickup && ! $order->get_meta( '_vanpos_pickup_date_formatted' ) ) {
			$order->update_meta_data( '_vanpos_pickup_date_formatted', self::format_meta_date( $pickup ) );
		}

		$return = isset( $args['return_date'] ) ? $args['return_date'] : '';
		if ( $return && ! $order->get_meta( '_vanpos_return_date_formatted' ) ) {
			$order->update_meta_data( '_vanpos_return_date_formatted', self::format_meta_date( $return ) );
		}

		if ( array_key_exists( 'total_price', $args ) ) {
			$t = $args['total_price'];
			if ( $t !== '' && $t !== false && ! $order->get_meta( '_vanpos_total_price_formatted' ) ) {
				$order->update_meta_data( '_vanpos_total_price_formatted', self::format_price( $t ) );
			}
		}

		if ( array_key_exists( 'remaining_payment', $args ) ) {
			$r = $args['remaining_payment'];
			if ( $r !== '' && $r !== false && ! $order->get_meta( '_vanpos_remaining_payment_formatted' ) ) {
				$order->update_meta_data( '_vanpos_remaining_payment_formatted', self::format_price( $r ) );
			}
		}
	}

	/**
	 * Get the human-readable label for a child payment-order type.
	 *
	 * SINGLE SOURCE OF TRUTH for child-order labels. Callers do not build these
	 * strings themselves — create_payment_order() applies this label whenever it is
	 * given an empty description, so the deposit flow (empty payment_schedule title)
	 * and the payment-complete handler (no description arg) both resolve here. This
	 * keeps one translatable string per type instead of the divergent
	 * "Remaining payment" / "Remaining rental payment" wording that existed before.
	 *
	 * Visibility changed from private to public (2026-06) so that
	 * VanPOS_Admin_Order_Edit can delegate here instead of maintaining a diverging
	 * duplicate. Any future display path that needs a label should call this method
	 * rather than building its own label map.
	 *
	 * @param string $payment_type Payment type.
	 * @return string
	 */
	public static function get_payment_type_label( $payment_type ) {
		$deposit_pct   = (int) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 );
		$remaining_pct = 100 - $deposit_pct;
		$labels = array(
			/* translators: %d is the initial payment percentage */
			'initial'          => sprintf( __( 'Initial rental payment (%d%%)', 'vanjorn-rental-pos' ), $deposit_pct ),
			'security_deposit' => __( 'Security deposit', 'vanjorn-rental-pos' ),
			/* translators: %d is the remaining payment percentage */
			'remaining'        => sprintf( __( 'Remaining payment (%d%%)', 'vanjorn-rental-pos' ), $remaining_pct ),
			'extension'        => __( 'Rental extension', 'vanjorn-rental-pos' ),
		);

		return isset( $labels[ $payment_type ] ) ? $labels[ $payment_type ] : $payment_type;
	}

	/**
	 * Get primary order ID from payment order
	 *
	 * @param int $payment_order_id Payment order ID.
	 * @return int|false
	 */
	public static function get_primary_order_id( $payment_order_id ) {
		$order = wc_get_order( $payment_order_id );
		if ( ! $order ) {
			return false;
		}

		// Check if it's a child order
		$parent_id = $order->get_parent_id();
		if ( $parent_id ) {
			return $parent_id;
		}

		// Check meta
		$primary_id = $order->get_meta( '_vanpos_primary_order_id' );
		return $primary_id ? (int) $primary_id : false;
	}

	/**
	 * Get all payment orders for a primary order
	 *
	 * @param int $primary_order_id Primary order ID.
	 * @return array
	 */
	public static function get_payment_orders( $primary_order_id ) {
		$orders = wc_get_orders( array(
			'parent' => $primary_order_id,
			'limit'  => -1,
		) );

		// Also get orders linked via meta
		$meta_orders = wc_get_orders( array(
			'meta_key'   => '_vanpos_primary_order_id',
			'meta_value' => $primary_order_id,
			'limit'      => -1,
		) );

		// Merge and remove duplicates
		$all_orders = array_merge( $orders, $meta_orders );
		$unique_orders = array();
		$seen_ids = array();

		foreach ( $all_orders as $order ) {
			// Drop refund objects. The 'parent' query above surfaces
			// WC_Order_Refund records under HPOS (a refund's parent is the order
			// it refunds). Callers treat every result as a child payment order and
			// call WC_Order-only methods on them — add_order_note(),
			// get_date_paid() — which fatals. WC_Order_Refund is a sibling of
			// WC_Order under WC_Abstract_Order, not a subclass, so this instanceof
			// check filters refunds at the single source rather than at each call
			// site. Per-call-site guards elsewhere become redundant but harmless.
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order_id = $order->get_id();
			if ( ! in_array( $order_id, $seen_ids, true ) ) {
				$unique_orders[] = $order;
				$seen_ids[] = $order_id;
			}
		}

		return $unique_orders;
	}

	/**
	 * Find the existing child payment order of a given type for a primary order.
	 *
	 * Single helper behind the "is there already a child of this type, and if so
	 * which one" question. The child-order factories use it as an idempotency
	 * self-guard (return the existing id instead of minting a duplicate), and
	 * has_payment_order() is a thin boolean wrapper over it.
	 *
	 * @param int    $primary_order_id Primary order ID.
	 * @param string $payment_type     Payment type to match on _vanpos_payment_type.
	 * @return WC_Order|null The existing child order, or null if none.
	 */
	public static function find_payment_order( $primary_order_id, $payment_type ) {
		foreach ( self::get_payment_orders( $primary_order_id ) as $order ) {
			if ( $payment_type === $order->get_meta( '_vanpos_payment_type' )
				&& ! $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Check if a payment order of specific type exists for a primary order
	 *
	 * @param int    $primary_order_id Primary order ID.
	 * @param string $payment_type Payment type.
	 * @return bool
	 */
	public static function has_payment_order( $primary_order_id, $payment_type ) {
		return null !== self::find_payment_order( $primary_order_id, $payment_type );
	}

	/**
	 * Accepted _vanpos_payment_type values for the remaining rental payment.
	 *
	 * SINGLE SOURCE OF TRUTH for the remaining-payment classification. PHP code
	 * calls is_remaining_payment(); SQL and WP_Query meta_query builders read this
	 * array directly (e.g. implode into an IN list, or pass as a meta_query value)
	 * so the accepted set is defined in exactly one place across the whole plugin.
	 *
	 * Historical note: 'second_payment' was a legacy alias here for orders created
	 * by older builds. A DB audit (2026-06) confirmed no stored order — across HPOS
	 * order meta, post meta, or line-item meta — carried it, and no code path writes
	 * it, so the alias was retired. Do not re-add it; the type is 'remaining'.
	 *
	 * @return string[]
	 */
	public static function remaining_payment_types() {
		return array( 'remaining' );
	}

	/**
	 * All child payment-order types (initial, security deposit, remaining, extension).
	 *
	 * Used by "is this any payment order" checks and by the dashboard / order-list
	 * "payment_other" NOT-IN filters. Sourced from remaining_payment_types() so the
	 * accepted set stays defined in only one place.
	 *
	 * Type notes:
	 *   'initial'          — vestigial; no live code path creates child orders of this type.
	 *   'security_deposit' — active; refundable damage hold, VAT-exempt.
	 *   'remaining'        — active; balance due before pickup.
	 *   'extension'        — active; price-increase adjustment created by change_dates()
	 *                        when the remaining child is already paid.
	 *
	 * The 'extension' type is present here so is_payment_order_type() returns true for
	 * it and the customer-facing dashboard/order-display guards work correctly. Extension
	 * orders still land in the "payment_other" dashboard bucket (the NOT-IN filter uses
	 * this array) — that is intentional.
	 *
	 * @return string[]
	 */
	public static function payment_order_types() {
		return array_merge( array( 'initial', 'security_deposit', 'extension' ), self::remaining_payment_types() );
	}

	/**
	 * Whether a payment type represents the remaining rental payment.
	 *
	 * @param string $payment_type Payment type.
	 * @return bool
	 */
	public static function is_remaining_payment( $payment_type ) {
		return in_array( $payment_type, self::remaining_payment_types(), true );
	}

	/**
	 * Whether a payment type is any recognised child payment-order type.
	 *
	 * @param string $payment_type Payment type.
	 * @return bool
	 */
	public static function is_payment_order_type( $payment_type ) {
		return in_array( $payment_type, self::payment_order_types(), true );
	}

	/**
	 * Whether a payment type represents the refundable security deposit.
	 *
	 * Note: 'initial' is the initial rental payment (e.g. 50%), NOT the refundable
	 * security deposit — only 'security_deposit' matches here.
	 *
	 * @param string $payment_type Payment type.
	 * @return bool
	 */
	public static function is_security_deposit( $payment_type ) {
		return 'security_deposit' === $payment_type;
	}

	/**
	 * Whether a remaining-payment child order already exists for a primary order.
	 *
	 * Routes through is_remaining_payment() so the accepted set lives in exactly
	 * one place. Replaces the prior pattern of calling
	 * has_payment_order() once per accepted type at the call site.
	 *
	 * @param int $primary_order_id Primary order ID.
	 * @return bool
	 */
	public static function has_remaining_payment_order( $primary_order_id ) {
		foreach ( self::get_payment_orders( $primary_order_id ) as $order ) {
			if ( self::is_remaining_payment( $order->get_meta( '_vanpos_payment_type' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an order is a primary rental order.
	 *
	 * SINGLE SOURCE for primary-rental detection — replaces the inline
	 * pickup/return/rent_from/_original_price probes duplicated across
	 * Customer_Account (set_rental_order_type, create_child_order_on_payment_complete,
	 * create_security_deposit_on_checkout) and VanPOS_Admin_Order_List (which now
	 * delegates here).
	 *
	 * Semantics, in order:
	 *   1. order_type === 'primary_rental'  → true.
	 *   2. order_type is set but something else (e.g. 'payment_order') → false.
	 *      This is deliberate and load-bearing: child payment orders carry rental
	 *      dates and a copied _vanpos_total_price from the parent, so probing their
	 *      metadata would false-positive and treat a child as a primary rental.
	 *   3. order_type unset → probe order-level dates / total price, then item-level
	 *      rental markers, and return true if any are present.
	 *
	 * Read-only: never mutates the order. Callers that want to stamp
	 * _vanpos_order_type do so themselves after a true result.
	 *
	 * @param WC_Order $order Order to inspect.
	 * @return bool
	 */
	public static function is_primary_rental_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$type = (string) $order->get_meta( '_vanpos_order_type' );
		if ( 'primary_rental' === $type ) {
			return true;
		}
		if ( '' !== $type ) {
			return false; // Explicitly another type (e.g. payment_order) — not a primary rental.
		}

		// Type unset: detect via rental metadata. _vanpos_total_price is included
		// as an order-level signal so a primary rental that recorded a price but no
		// dates is still detected (the type guard above keeps child orders out).
		if ( $order->get_meta( '_vanpos_pickup_date' ) || $order->get_meta( '_vanpos_return_date' ) || $order->get_meta( '_vanpos_total_price' ) ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( 'vanpos_pickup_date' )
				|| $item->get_meta( 'vanpos_return_date' )
				|| $item->get_meta( 'wcrp_rental_products_rent_from' )
				|| $item->get_meta( 'wcrp_rental_products_rent_to' )
				|| $item->get_meta( '_vanpos_original_price' ) ) {
				return true;
			}
		}

		return false;
	}
}
