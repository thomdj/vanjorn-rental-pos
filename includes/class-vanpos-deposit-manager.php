<?php
/**
 * Deposit Manager for VAN-Jorn Rental POS
 * Handles 50% deposit payments with parent/child order structure
 * Based on AWCDP plugin concept
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deposit Manager Class
 */
class VanPOS_Deposit_Manager {

	/**
	 * Initialize deposit manager
	 */
	public static function init() {
		// Check if deposits are enabled
		if ( ! self::is_deposit_enabled() ) {
			return;
		}

		// Clear cart before adding rental products
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'maybe_clear_cart_before_rental' ), 1, 6 );
		
		// Add deposit data to cart items when added
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_deposit_to_cart_item_data' ), 20, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_item_from_session' ), 10, 2 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'cart_loaded_from_session' ) );
		
		// Update deposit meta when cart items are calculated
		// Run after rental plugin calculates prices (priority 999)
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'update_deposit_meta' ), 999 );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'force_deposit_calculation' ), 999 );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'update_deposit_meta' ), 999 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'after_add_to_cart' ), 999, 6 );
		
		// Override cart totals - this is the key!
		add_filter( 'woocommerce_calculated_total', array( __CLASS__, 'calculated_total' ), 99999, 2 );
		
		// Override cart get_total to return deposit amount
		add_filter( 'woocommerce_cart_get_total', array( __CLASS__, 'cart_get_total' ), 99999, 1 );
		
		// Display deposit info in cart
		add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'cart_totals_after_order_total' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'checkout_totals_after_order_total' ) );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'get_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'display_item_subtotal' ), 10, 3 );
		
		// Checkout hooks
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'create_order' ), 99, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'checkout_create_order_line_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_deposit_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'block_checkout_create_order' ), 10, 1 );
		
		// Payment complete hooks
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'payment_complete' ), 10, 1 );
	}

	/**
	 * Check if deposit is enabled
	 *
	 * @return bool
	 */
	public static function is_deposit_enabled() {
		$settings = VanPOS_Functions::get_settings();
		return isset( $settings['vanpos_deposit_enabled'] ) && $settings['vanpos_deposit_enabled'] === 'yes';
	}

	/**
	 * Check if pickup date is more than the configured threshold away
	 *
	 * Uses the vanpos_security_deposit_days_before_pickup admin setting
	 * (default 14) so the deposit-split decision matches both the frontend
	 * display and the cart/checkout messaging.
	 *
	 * @param string $pickup_date Pickup date in Y-m-d format.
	 * @return bool
	 */
	public static function is_pickup_beyond_security_deposit_threshold( $pickup_date ) {
		if ( empty( $pickup_date ) ) {
			return false;
		}

		$pickup_timestamp = strtotime( $pickup_date );
		if ( ! $pickup_timestamp ) {
			return false;
		}

		$threshold_days = (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_days_before_pickup', 14 );
		$current_timestamp = current_time( 'U' );
		$days_until_pickup = ( $pickup_timestamp - $current_timestamp ) / DAY_IN_SECONDS;

		return $days_until_pickup > $threshold_days;
	}

	/**
	 * Check if deposit should be applied for a cart item
	 *
	 * @param array $cart_item Cart item data.
	 * @return bool
	 */
	public static function should_apply_deposit( $cart_item ) {
		if ( ! self::is_deposit_enabled() ) {
			return false;
		}

		// Get pickup date
		$pickup_date = '';
		if ( isset( $cart_item['vanpos_pickup_date'] ) ) {
			$pickup_date = $cart_item['vanpos_pickup_date'];
		} elseif ( isset( $cart_item['wcrp_rental_products_rent_from'] ) ) {
			$pickup_date = $cart_item['wcrp_rental_products_rent_from'];
		}

		// Only apply deposit if pickup is beyond the security deposit threshold.
		return self::is_pickup_beyond_security_deposit_threshold( $pickup_date );
	}

	/**
	 * Get deposit percentage
	 *
	 * @return float
	 */
	public static function get_deposit_percentage() {
		$settings = VanPOS_Functions::get_settings();
		return isset( $settings['vanpos_deposit_percentage'] ) ? floatval( $settings['vanpos_deposit_percentage'] ) : 50.0;
	}

	/**
	 * Check if cart item is a rental product
	 *
	 * @param array $cart_item Cart item data.
	 * @return bool
	 */
	public static function is_rental_product( $cart_item ) {
		return isset( $cart_item['vanpos_pickup_date'] ) || isset( $cart_item['wcrp_rental_products_rent_from'] );
	}

	/**
	 * Clear cart before adding rental product
	 * This runs BEFORE the item is added, so we can clear the cart first
	 *
	 * @param string $cart_item_key Cart item key (will be empty on first call).
	 * @param int    $product_id Product ID.
	 * @param int    $quantity Quantity.
	 * @param int    $variation_id Variation ID.
	 * @param array  $variation Variation data.
	 * @param array  $cart_item_data Cart item data.
	 * @return void
	 */
	public static function maybe_clear_cart_before_rental( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		// Check if this is a rental product
		if ( isset( $cart_item_data['vanpos_pickup_date'] ) || isset( $cart_item_data['wcrp_rental_products_rent_from'] ) ) {
			// Only clear if cart is not empty and doesn't already have this item
			if ( ! WC()->cart->is_empty() && empty( $cart_item_key ) ) {
				// Clear cart before adding rental product
				WC()->cart->empty_cart( false );
			}
		}
	}

	/**
	 * After add to cart - force deposit calculation
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id Product ID.
	 * @param int    $quantity Quantity.
	 * @param int    $variation_id Variation ID.
	 * @param array  $variation Variation data.
	 * @param array  $cart_item_data Cart item data.
	 * @return void
	 */
	public static function after_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		// Force cart calculation to update deposit info
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Force deposit calculation after totals are calculated
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public static function force_deposit_calculation( $cart ) {
		// Ensure deposit info is calculated
		if ( ! isset( WC()->cart->deposit_info ) || empty( WC()->cart->deposit_info ) ) {
			self::update_deposit_meta( $cart );
		}
	}

	/**
	 * Add deposit data to cart item when added
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id Product ID.
	 * @param int   $variation_id Variation ID.
	 * @return array
	 */
	public static function add_deposit_to_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		// Check if deposit should be enabled based on pickup date
		$pickup_date = '';
		if ( isset( $_REQUEST['pickup_date'] ) ) {
			$pickup_date = sanitize_text_field( $_REQUEST['pickup_date'] );
		} elseif ( isset( $_REQUEST['data']['pickup_date'] ) ) {
			$pickup_date = sanitize_text_field( $_REQUEST['data']['pickup_date'] );
		}

		// Enable deposit only if pickup is beyond the security deposit threshold.
		$enable_deposit = self::is_pickup_beyond_security_deposit_threshold( $pickup_date );

		$cart_item_data['vanpos_deposit'] = array(
			'enable' => $enable_deposit,
		);
		return $cart_item_data;
	}

	/**
	 * Get cart item from session
	 *
	 * @param array $cart_item Cart item data.
	 * @param array $values Session values.
	 * @return array
	 */
	public static function get_cart_item_from_session( $cart_item, $values ) {
		if ( ! empty( $values['vanpos_deposit'] ) ) {
			$cart_item['vanpos_deposit'] = $values['vanpos_deposit'];
		}
		return $cart_item;
	}

	/**
	 * Cart loaded from session
	 */
	public static function cart_loaded_from_session() {
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart_contents() as $cart_item_key => $cart_item ) {
				if ( self::is_rental_product( $cart_item ) ) {
					self::update_deposit_meta_for_item( $cart_item['data'], $cart_item['quantity'], $cart_item, $cart_item_key );
				}
			}
		}
	}

	/**
	 * Update deposit meta for all cart items
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public static function update_deposit_meta( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( WC()->cart->get_cart_contents() as $cart_item_key => $cart_item ) {
			if ( self::is_rental_product( $cart_item ) && isset( $cart_item['vanpos_deposit'] ) ) {
				self::update_deposit_meta_for_item( $cart_item['data'], $cart_item['quantity'], $cart_item, $cart_item_key );
			}
		}
	}

	/**
	 * Update deposit meta for a single cart item
	 *
	 * @param WC_Product $product Product object.
	 * @param int        $quantity Quantity.
	 * @param array      $cart_item_data Cart item data.
	 * @param string     $cart_item_key Cart item key.
	 * @return void
	 */
	private static function update_deposit_meta_for_item( $product, $quantity, &$cart_item_data, $cart_item_key ) {
		// Check if deposit should be applied
		if ( ! self::should_apply_deposit( $cart_item_data ) ) {
			// Disable deposit if pickup is less than 14 days away
			if ( isset( $cart_item_data['vanpos_deposit'] ) ) {
				$cart_item_data['vanpos_deposit']['enable'] = false;
			}
			return;
		}

		// Ensure deposit is enabled
		if ( ! isset( $cart_item_data['vanpos_deposit'] ) || ! $cart_item_data['vanpos_deposit']['enable'] ) {
			$cart_item_data['vanpos_deposit'] = array( 'enable' => true );
		}

		// Get the BASE item total from rental plugin (this is the pure rental price, no fees)
		// This is the key: we need the item total BEFORE fees are added
		$amount = 0;
		
		// First priority: Use rental plugin's cart item price (this is the base rental price)
		if ( isset( $cart_item_data['wcrp_rental_products_cart_item_price'] ) && $cart_item_data['wcrp_rental_products_cart_item_price'] > 0 ) {
			$amount = (float) $cart_item_data['wcrp_rental_products_cart_item_price'];
		}
		// Second priority: Use line_subtotal if rental plugin price not available
		// But only if it's the base item price (we need to check if fees are already included)
		elseif ( isset( $cart_item_data['line_subtotal'] ) && $cart_item_data['line_subtotal'] > 0 ) {
			$amount = (float) $cart_item_data['line_subtotal'];
		}
		// Fallback: Calculate from product price and nights
		else {
			$nights = isset( $cart_item_data['vanpos_rental_nights'] )
				? (int) $cart_item_data['vanpos_rental_nights']
				: max( 0, ( isset( $cart_item_data['vanpos_rental_days'] ) ? (int) $cart_item_data['vanpos_rental_days'] - 1 : 0 ) );
			if ( $nights > 0 ) {
				$base_price = (float) $product->get_price();
				$amount = $base_price * $nights * $quantity;
			}
		}

		// If still no amount, try to get from the product's calculated price
		if ( $amount <= 0 ) {
			// Get price from product object (might be set by rental plugin)
			$product_price = (float) $product->get_price();
			$nights = isset( $cart_item_data['vanpos_rental_nights'] )
				? (int) $cart_item_data['vanpos_rental_nights']
				: max( 0, ( isset( $cart_item_data['vanpos_rental_days'] ) ? (int) $cart_item_data['vanpos_rental_days'] - 1 : 0 ) );
			if ( $nights > 0 && $product_price > 0 ) {
				$amount = $product_price * $nights * $quantity;
			}
		}

		if ( $amount <= 0 ) {
			// Still no amount, skip deposit calculation for now
			return;
		}

		// Calculate 50% deposit on the BASE item total (€1,260.00 -> €630.00)
		// Fees are NOT included in this calculation - they're paid in full separately
		$deposit_percentage = self::get_deposit_percentage();
		$deposit = $amount * ( $deposit_percentage / 100.0 );
		$remaining = $amount - $deposit;

		// Store deposit info in cart item (similar to AWCDP structure)
		$cart_item_data['vanpos_deposit']['deposit'] = $deposit;
		$cart_item_data['vanpos_deposit']['remaining'] = $remaining;
		$cart_item_data['vanpos_deposit']['total'] = $amount;
		$cart_item_data['vanpos_deposit']['tax'] = 0; // Tax handled separately
		$cart_item_data['vanpos_deposit']['tax_total'] = isset( $cart_item_data['line_subtotal_tax'] ) ? $cart_item_data['line_subtotal_tax'] : 0;

		// Update the cart contents
		if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
			WC()->cart->cart_contents[ $cart_item_key ] = $cart_item_data;
		}
	}

	/**
	 * Override calculated total - this is the KEY function!
	 * Returns original total but stores deposit info in WC()->cart->deposit_info
	 *
	 * @param float   $cart_total Cart total.
	 * @param WC_Cart $cart Cart object.
	 * @return float Original cart total (not modified)
	 */
	public static function calculated_total( $cart_total, $cart ) {
		$cart_original = $cart_total;
		$deposit_amount = 0;
		$deposit_total = 0;
		$full_amount_products = 0;
		$deposit_enabled = false;
		$deposit_in_cart = false;

		// Calculate deposit from cart items
		foreach ( $cart->get_cart_contents() as $cart_item_key => $cart_item ) {
			if ( self::is_rental_product( $cart_item ) && 
				 isset( $cart_item['vanpos_deposit'], $cart_item['vanpos_deposit']['enable'] ) && 
				 $cart_item['vanpos_deposit']['enable'] && 
				 isset( $cart_item['vanpos_deposit']['deposit'] ) ) {
				
				$deposit_in_cart = true;
				$deposit_amount += $cart_item['vanpos_deposit']['deposit'];
				$deposit_total += $cart_item['vanpos_deposit']['total'];
			} else {
				$full_amount_products += isset( $cart_item['line_subtotal'] ) ? $cart_item['line_subtotal'] : 0;
			}
		}

		// If we have deposits and deposit is less than total, enable deposit mode
		if ( $deposit_in_cart && $deposit_amount < ( $deposit_total + $cart->fee_total + $cart->tax_total + $cart->shipping_total ) ) {
			$deposit_amount += $full_amount_products;
			$deposit_enabled = true;
		}

		// Fees handling - fees are paid in full (not split, not included in deposit calculation)
		$deposit_fees = 0.0; // Fees are NOT included in deposit - they're paid in full separately
		$fee_taxes = $cart->get_fee_tax();

		// Taxes handling - check if prices include tax
		$prices_include_tax = get_option( 'woocommerce_prices_include_tax' ) === 'yes';
		$deposit_taxes = 0.0;
		
		if ( $prices_include_tax ) {
			// Prices already include tax, so deposit amount already includes tax
			// We don't need to add tax separately
			$deposit_taxes = 0.0;
		} else {
			// Prices exclude tax, so we need to calculate tax on deposit
			if ( $deposit_total > 0 ) {
				$division = $deposit_total;
				$division = $division == 0 ? 1 : $division;
				$deposit_percentage = $deposit_amount * 100 / floatval( $division );
				// Only calculate tax on the item subtotal tax, not fees
				$item_subtotal_tax = 0;
				foreach ( $cart->get_cart_contents() as $cart_item ) {
					if ( self::is_rental_product( $cart_item ) && 
						 isset( $cart_item['vanpos_deposit'], $cart_item['vanpos_deposit']['enable'] ) && 
						 $cart_item['vanpos_deposit']['enable'] ) {
						$item_subtotal_tax += isset( $cart_item['line_subtotal_tax'] ) ? $cart_item['line_subtotal_tax'] : 0;
					}
				}
				$deposit_taxes = $item_subtotal_tax * ( $deposit_percentage / 100 );
			}
		}

		// Shipping handling - paid in full
		$deposit_shipping = $cart->shipping_total;
		$deposit_shipping_taxes = $cart->shipping_tax_total;

		// Add taxes and shipping to deposit amount (fees are separate)
		$cart_items_deposit_amount = $deposit_amount; // Item deposit only (€630)
		
		if ( $prices_include_tax ) {
			// Prices include tax, so deposit_amount already includes tax
			// Just add shipping (which also includes tax if prices include tax)
			$deposit_amount += $deposit_shipping + $deposit_shipping_taxes;
			$item_deposit_only = $cart_items_deposit_amount; // No need to add tax, already included
		} else {
			// Prices exclude tax, so add tax to deposit
			$deposit_amount += $deposit_taxes + $deposit_shipping + $deposit_shipping_taxes;
			$item_deposit_only = $cart_items_deposit_amount + $deposit_taxes;
		}
		
		// Fees are added separately to the total (not to deposit calculation)
		// If prices include tax, fees already include tax
		$fee_total = floatval( $cart->fee_total + ( $prices_include_tax ? 0 : $fee_taxes ) );

		// Discounts - apply to remaining payment (not deposit)
		$discount_total = $cart->get_cart_discount_total() + ( $prices_include_tax ? 0 : $cart->get_cart_discount_tax_total() );
		$remaining_amounts = array(
			'discounts' => $discount_total,
			'fees' => 0, // Fees are paid in full, not split
			'taxes' => $prices_include_tax ? 0 : ( $cart->get_subtotal_tax() - $deposit_taxes ),
			'shipping' => 0, // Shipping already in deposit
			'shipping_taxes' => 0, // Shipping taxes already in deposit
		);

		// Round deposit amount
		$deposit_amount = round( $deposit_amount, wc_get_price_decimals() );

		// No point having deposit if remaining is 0 or negative
		if ( $cart_total - $deposit_amount <= 0 ) {
			$deposit_enabled = false;
		}

		// Store deposit info in cart (like AWCDP does)
		WC()->cart->deposit_info = array();
		WC()->cart->deposit_info['deposit_enabled'] = $deposit_enabled;
		WC()->cart->deposit_info['deposit_amount'] = $item_deposit_only; // Item deposit only (€630 + tax)
		WC()->cart->deposit_info['deposit_breakdown'] = array(
			'cart_items' => $cart_items_deposit_amount,
			'fees' => 0, // Fees not included in deposit
			'taxes' => $deposit_taxes,
			'shipping' => $deposit_shipping,
			'shipping_taxes' => $deposit_shipping_taxes,
			'discounts' => 0,
		);
		WC()->cart->deposit_info['fee_total'] = $fee_total; // Store fees separately
		WC()->cart->deposit_info['remaining_amounts'] = $remaining_amounts;

		// Return original total (not modified)
		return $cart_original;
	}

	/**
	 * Override cart get_total to return deposit amount + fees
	 *
	 * @param float $total Cart total.
	 * @return float Deposit amount + fees if deposit is enabled, otherwise original total.
	 */
	public static function cart_get_total( $total ) {
		if ( ! isset( WC()->cart->deposit_info['deposit_enabled'] ) || WC()->cart->deposit_info['deposit_enabled'] !== true ) {
			return $total;
		}

		// Return item deposit + fees + shipping
		// Since prices include tax, all amounts already include tax
		$item_deposit = WC()->cart->deposit_info['deposit_amount']; // Item deposit only (already includes tax if prices include tax)
		$deposit_breakdown = WC()->cart->deposit_info['deposit_breakdown'];
		$fee_total = isset( WC()->cart->deposit_info['fee_total'] ) ? WC()->cart->deposit_info['fee_total'] : 0;
		$shipping = $deposit_breakdown['shipping'] + $deposit_breakdown['shipping_taxes'];
		
		// Check if prices include tax
		$prices_include_tax = get_option( 'woocommerce_prices_include_tax' ) === 'yes';
		
		if ( $prices_include_tax ) {
			// All amounts already include tax, just sum them
			return $item_deposit + $shipping + $fee_total;
		} else {
			// Add taxes separately
			$taxes = $deposit_breakdown['taxes'];
			return $item_deposit + $taxes + $shipping + $fee_total;
		}
	}

	/**
	 * Display deposit info after order total in cart
	 */
	public static function cart_totals_after_order_total() {
		if ( ! isset( WC()->cart->deposit_info['deposit_enabled'] ) || WC()->cart->deposit_info['deposit_enabled'] !== true ) {
			return;
		}

		$deposit_amount = WC()->cart->deposit_info['deposit_amount'];
		$fee_total = isset( WC()->cart->deposit_info['fee_total'] ) ? WC()->cart->deposit_info['fee_total'] : 0;
		
		// Get original total before deposit override
		remove_filter( 'woocommerce_cart_get_total', array( __CLASS__, 'cart_get_total' ), 99999 );
		$cart_total = WC()->cart->get_total( 'edit' );
		add_filter( 'woocommerce_cart_get_total', array( __CLASS__, 'cart_get_total' ), 99999, 1 );
		
		$pay_now = $deposit_amount + $fee_total;
		$remaining = $cart_total - $deposit_amount - $fee_total;
		
		// Get security deposit amount
		$security_deposit_product_id = VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' );
		$security_deposit_amount = 0;
		if ( ! empty( $security_deposit_product_id ) ) {
			$security_deposit_product = wc_get_product( $security_deposit_product_id );
			if ( $security_deposit_product ) {
				$security_deposit_amount = (float) $security_deposit_product->get_price();
			}
		}
		
		// Get booking complete total (item + fees)
		$booking_complete_total = $cart_total;
		$security_days = (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_days_before_pickup', 14 );
		$deposit_pct   = (int) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 );
		$remaining_pct = 100 - $deposit_pct;
		?>
		<?php if ( $security_deposit_amount > 0 ) : ?>
		<tr class="vanpos-security-deposit-row">
			<th><?php esc_html_e( 'Security deposit', 'vanjorn-rental-pos' ); ?></th>
			<td data-title="<?php esc_attr_e( 'Security deposit', 'vanjorn-rental-pos' ); ?>">
				<?php echo wp_kses_post( wc_price( $security_deposit_amount ) ); ?>
				<br>
				<small style="color: #666;">
					<?php
					/* translators: %d is the number of days before pickup */
					printf(
						esc_html( _n(
							'Charged %d day before pickup and refunded after return',
							'Charged %d days before pickup and refunded after return',
							$security_days,
							'vanjorn-rental-pos'
						) ),
						$security_days
					);
					?>
				</small>
			</td>
		</tr>
		<?php endif; ?>
		<tr class="vanpos-cart-summary-section-row">
			<td colspan="2">
				<div class="vanpos-cart-summary-section">
					<h4 class="vanpos-cart-summary-section-title"><?php esc_html_e( 'Payment summary', 'vanjorn-rental-pos' ); ?></h4>
					<p class="vanpos-cart-summary-payment-note">
						<strong><?php esc_html_e( 'Payment plan:', 'vanjorn-rental-pos' ); ?></strong>
						<?php
						/* translators: %1$d is the number of days, %2$d is the deposit percentage */
						printf(
							esc_html__( 'Since your pickup is more than %d days away, you can pay %d%% now and the remainder later.', 'vanjorn-rental-pos' ),
							$security_days,
							$deposit_pct
						);
						?>
					</p>
				</div>
			</td>
		</tr>
		<tr class="vanpos-order-paid">
			<th>
				<?php
				/* translators: %d is the deposit percentage */
				printf(
					esc_html__( 'Pay now (%d%% deposit + additional fees)', 'vanjorn-rental-pos' ),
					$deposit_pct
				);
				?>
			</th>
			<td data-title="<?php esc_attr_e( 'Pay now', 'vanjorn-rental-pos' ); ?>">
				<strong class="vanpos-cart-summary-highlight"><?php echo wp_kses_post( wc_price( $pay_now ) ); ?></strong>
			</td>
		</tr>
		<tr class="vanpos-order-remaining">
			<th>
				<?php
				/* translators: %d is the remaining payment percentage */
				printf(
					esc_html__( 'Pay later (remaining %d%%)', 'vanjorn-rental-pos' ),
					$remaining_pct
				);
				?>
			</th>
			<td data-title="<?php esc_attr_e( 'Pay later', 'vanjorn-rental-pos' ); ?>">
				<strong class="vanpos-cart-summary-highlight"><?php echo wp_kses_post( wc_price( $remaining ) ); ?></strong>
			</td>
		</tr>
		<?php
	}

	/**
	 * Display deposit info after order total in checkout
	 */
	public static function checkout_totals_after_order_total() {
		if ( ! isset( WC()->cart->deposit_info['deposit_enabled'] ) || WC()->cart->deposit_info['deposit_enabled'] !== true ) {
			return;
		}

		$deposit_amount = WC()->cart->deposit_info['deposit_amount'];
		$fee_total = isset( WC()->cart->deposit_info['fee_total'] ) ? WC()->cart->deposit_info['fee_total'] : 0;
		
		// Get original total before deposit override
		remove_filter( 'woocommerce_cart_get_total', array( __CLASS__, 'cart_get_total' ), 99999 );
		$cart_total = WC()->cart->get_total( 'edit' );
		add_filter( 'woocommerce_cart_get_total', array( __CLASS__, 'cart_get_total' ), 99999, 1 );
		
		$pay_now = $deposit_amount + $fee_total;
		$remaining = $cart_total - $deposit_amount - $fee_total;
		
		// Get security deposit amount
		$security_deposit_product_id = VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' );
		$security_deposit_amount = 0;
		if ( ! empty( $security_deposit_product_id ) ) {
			$security_deposit_product = wc_get_product( $security_deposit_product_id );
			if ( $security_deposit_product ) {
				$security_deposit_amount = (float) $security_deposit_product->get_price();
			}
		}
		$security_days = (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_days_before_pickup', 14 );
		$deposit_pct   = (int) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 );
		$remaining_pct = 100 - $deposit_pct;
		?>
		<?php if ( $security_deposit_amount > 0 ) : ?>
		<tr class="vanpos-security-deposit-row">
			<th><?php esc_html_e( 'Security deposit', 'vanjorn-rental-pos' ); ?></th>
			<td>
				<?php echo wp_kses_post( wc_price( $security_deposit_amount ) ); ?>
				<br>
				<small style="color: #666;">
					<?php
					/* translators: %d is the number of days before pickup */
					printf(
						esc_html( _n(
							'Charged %d day before pickup and refunded after return',
							'Charged %d days before pickup and refunded after return',
							$security_days,
							'vanjorn-rental-pos'
						) ),
						$security_days
					);
					?>
				</small>
			</td>
		</tr>
		<?php endif; ?>
		<tr class="vanpos-cart-summary-section-row">
			<th colspan="2">
				<div class="vanpos-cart-summary-section">
					<h4 class="vanpos-cart-summary-section-title"><?php esc_html_e( 'Payment summary', 'vanjorn-rental-pos' ); ?></h4>
					<p class="vanpos-cart-summary-payment-note">
						<strong><?php esc_html_e( 'Payment plan:', 'vanjorn-rental-pos' ); ?></strong>
						<?php
						/* translators: %1$d is the number of days, %2$d is the deposit percentage */
						printf(
							esc_html__( 'Since your pickup is more than %d days away, you can pay %d%% now and the remainder later.', 'vanjorn-rental-pos' ),
							$security_days,
							$deposit_pct
						);
						?>
					</p>
				</div>
			</th>
		</tr>
		<tr class="vanpos-order-paid">
			<th>
				<?php
				/* translators: %d is the deposit percentage */
				printf(
					esc_html__( 'Pay now (%d%% deposit + additional fees)', 'vanjorn-rental-pos' ),
					$deposit_pct
				);
				?>
			</th>
			<td>
				<strong class="vanpos-cart-summary-highlight"><?php echo wp_kses_post( wc_price( $pay_now ) ); ?></strong>
			</td>
		</tr>
		<tr class="vanpos-order-remaining">
			<th>
				<?php
				/* translators: %d is the remaining payment percentage */
				printf(
					esc_html__( 'Pay later (remaining %d%%)', 'vanjorn-rental-pos' ),
					$remaining_pct
				);
				?>
			</th>
			<td>
				<strong class="vanpos-cart-summary-highlight"><?php echo wp_kses_post( wc_price( $remaining ) ); ?></strong>
			</td>
		</tr>
		<?php
	}

	/**
	 * Get item data for display in cart
	 *
	 * @param array $item_data Item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function get_item_data( $item_data, $cart_item ) {
		if ( ! self::is_rental_product( $cart_item ) ) {
			return $item_data;
		}

		if ( isset( $cart_item['vanpos_deposit'], $cart_item['vanpos_deposit']['enable'] ) && 
			 $cart_item['vanpos_deposit']['enable'] && 
			 isset( $cart_item['vanpos_deposit']['deposit'] ) ) {
			
			$deposit = $cart_item['vanpos_deposit']['deposit'];
			$remaining = $cart_item['vanpos_deposit']['remaining'];

			$item_data[] = array(
				'name'    => __( 'Deposit amount', 'vanjorn-rental-pos' ),
				'display' => wc_price( $deposit ),
				'value'   => 'vanpos_deposit_amount',
			);

			$item_data[] = array(
				'name'    => __( 'Remaining amount', 'vanjorn-rental-pos' ),
				'display' => wc_price( $remaining ),
				'value'   => 'vanpos_future_payments_amount',
			);
		}

		return $item_data;
	}

	/**
	 * Display item subtotal with deposit info
	 *
	 * @param string $output Subtotal output.
	 * @param array  $cart_item Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public static function display_item_subtotal( $output, $cart_item, $cart_item_key ) {
		if ( ! self::is_rental_product( $cart_item ) ) {
			return $output;
		}

		if ( isset( $cart_item['vanpos_deposit'], $cart_item['vanpos_deposit']['enable'] ) && 
			 $cart_item['vanpos_deposit']['enable'] && 
			 isset( $cart_item['vanpos_deposit']['deposit'] ) ) {
			
			$deposit = $cart_item['vanpos_deposit']['deposit'];
			$output .= '<br/><small>( ' . wp_kses_post( wc_price( $deposit ) ) . ' ' . esc_html__( 'payable as a deposit', 'vanjorn-rental-pos' ) . ' )</small>';
		}

		return $output;
	}

	/**
	 * Modify order line item to use deposit price
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values Cart item values.
	 * @param WC_Order               $order Order object.
	 * @return void
	 */
	public static function checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! self::is_rental_product( $values ) ) {
			return;
		}

		// 24 FEB Updated: Save rental meta to order item for display in order details, admin, emails.
		// Ensures pickup/return dates, times, days, and options persist beyond cart session.
		// Canonical convention: item-level rental meta uses no underscore prefix.
		if ( isset( $values['vanpos_pickup_date'] ) ) {
			$item->update_meta_data( 'vanpos_pickup_date', $values['vanpos_pickup_date'] );
		}
		if ( isset( $values['vanpos_return_date'] ) ) {
			$item->update_meta_data( 'vanpos_return_date', $values['vanpos_return_date'] );
		}
		if ( isset( $values['vanpos_pickup_time'] ) ) {
			$item->update_meta_data( 'vanpos_pickup_time', $values['vanpos_pickup_time'] );
		}
		if ( isset( $values['vanpos_return_time'] ) ) {
			$item->update_meta_data( 'vanpos_return_time', $values['vanpos_return_time'] );
		}
		if ( isset( $values['vanpos_rental_days'] ) ) {
			$item->update_meta_data( 'vanpos_rental_days', $values['vanpos_rental_days'] );
		}
		if ( isset( $values['vanpos_rental_nights'] ) ) {
			$item->update_meta_data( 'vanpos_rental_nights', $values['vanpos_rental_nights'] );
		}
		if ( isset( $values['vanpos_include_dog'] ) && $values['vanpos_include_dog'] ) {
			$item->update_meta_data( 'vanpos_include_dog', true );
		}
		if ( isset( $values['vanpos_include_cleaning'] ) && $values['vanpos_include_cleaning'] ) {
			$item->update_meta_data( 'vanpos_include_cleaning', true );
		}

		if ( isset( $values['vanpos_deposit'], $values['vanpos_deposit']['enable'] ) && 
			 $values['vanpos_deposit']['enable'] && 
			 isset( $values['vanpos_deposit']['deposit'] ) ) {
			
			$deposit = $values['vanpos_deposit']['deposit'];
			$original_total = $values['vanpos_deposit']['total'];
			$remaining = $values['vanpos_deposit']['remaining'];

			// Store original price and deposit info in item meta (for calculations and display)
			$item->update_meta_data( '_vanpos_original_price', $original_total );
			$item->update_meta_data( '_vanpos_deposit_amount', $deposit );
			$item->update_meta_data( '_vanpos_remaining_amount', $remaining );

			// Modify the line item subtotal and total to deposit amount.
			// The deposit is a gross (tax-inclusive) figure. When prices include tax,
			// record it as such - net portion as subtotal/total, the included VAT as
			// tax - mirroring VanPOS_Order_Manager's child-order handling. Previously
			// this zeroed the tax, which WooCommerce's checkout recalculation then
			// overrode by adding VAT on top of the gross amount (double-VAT, e.g.
			// €280 -> €338,80). Splitting it correctly is robust to recalculation.
			if ( get_option( 'woocommerce_prices_include_tax' ) === 'yes'
				&& class_exists( 'VanPOS_Order_Manager' )
				&& (float) $deposit > 0 ) {
				$rate_id = VanPOS_Order_Manager::get_vat_rate_id( $item );
				$split   = VanPOS_Order_Manager::split_inclusive_vat( (float) $deposit, VanPOS_Order_Manager::get_vat_rate_fraction( $item ) );
				VanPOS_Order_Manager::apply_inclusive_vat_to_item( $item, $split['excl'], $split['tax'], $rate_id );
			} else {
				$item->set_subtotal( $deposit );
				$item->set_total( $deposit );
			}
		}
	}

	/**
	 * Create order with deposit (parent order)
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data Checkout data.
	 * @return void
	 */
	public static function create_order( $order, $data ) {
		if ( ! isset( WC()->cart->deposit_info['deposit_enabled'] ) || WC()->cart->deposit_info['deposit_enabled'] !== true ) {
			return;
		}

		// woocommerce_checkout_create_order passes WC_Order directly
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$fee_total = isset( WC()->cart->deposit_info['fee_total'] ) ? WC()->cart->deposit_info['fee_total'] : 0;
		
		// Deposit amount for checkout includes fees (€630 + €200 = €830)
		$deposit_amount = WC()->cart->deposit_info['deposit_amount'] + $fee_total;
		
		// Get original cart total (before deposit override)
		remove_filter( 'woocommerce_cart_get_total', array( __CLASS__, 'cart_get_total' ), 99999 );
		$cart_total = WC()->cart->get_total( 'edit' );
		add_filter( 'woocommerce_cart_get_total', array( __CLASS__, 'cart_get_total' ), 99999, 1 );
		
		// Second payment is the remaining item amount only (€630), fees are already paid
		$second_payment = $cart_total - $deposit_amount;

		// Store deposit info in order meta
		$order->update_meta_data( '_vanpos_order_has_remaining_payment', 'yes' );
		$order->update_meta_data( '_vanpos_initial_payment_paid', 'no' );
		$order->update_meta_data( '_vanpos_remaining_payment_paid', 'no' );
		$order->update_meta_data( '_vanpos_initial_payment', $deposit_amount );
		$order->update_meta_data( '_vanpos_remaining_payment', $second_payment );

		// Create payment schedule
		// The main/parent order IS the deposit payment (paid at checkout),
		// so only a 'remaining' child order needs to be created.
		$payment_schedule = array(
			'remaining' => array(
				'id'    => '',
				// Title intentionally empty: the human label is applied centrally by
				// VanPOS_Order_Manager::create_payment_order() from the payment type,
				// the single source of truth for child-order labels.
				'title' => '',
				'type'  => 'remaining',
				'total' => $second_payment,
			),
		);

		$order->update_meta_data( '_vanpos_payment_schedule', $payment_schedule );
		$order->save();
	}

	/**
	 * Process deposit order after checkout
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function process_deposit_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$has_deposit = $order->get_meta( '_vanpos_order_has_remaining_payment' );
		if ( $has_deposit !== 'yes' ) {
			return;
		}

		$payment_schedule = $order->get_meta( '_vanpos_payment_schedule' );
		if ( ! $payment_schedule || ! is_array( $payment_schedule ) ) {
			return;
		}

		// Promote rental dates, times, and booking reference from item meta
		// to order-level meta so child orders and AutomateWoo can read them.
		self::promote_rental_meta_to_order( $order );

		// Create partial payment orders using shared method
		self::create_partial_payment_orders( $order, $payment_schedule );

		// The main/parent order IS the deposit payment, so mark it as paid
		// once checkout is processed.
		$order->update_meta_data( '_vanpos_initial_payment_paid', 'yes' );
		$order->save();
	}

	/**
	 * Block checkout create order (for WooCommerce blocks)
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function block_checkout_create_order( $order ) {
		if ( ! isset( WC()->cart->deposit_info['deposit_enabled'] ) || WC()->cart->deposit_info['deposit_enabled'] !== true ) {
			return;
		}

		$item_deposit = WC()->cart->deposit_info['deposit_amount']; // Item deposit only (€630 + tax)
		$fee_total = isset( WC()->cart->deposit_info['fee_total'] ) ? WC()->cart->deposit_info['fee_total'] : 0;
		
		// Get original cart total (before deposit override)
		remove_filter( 'woocommerce_cart_get_total', array( __CLASS__, 'cart_get_total' ), 99999 );
		$cart_total = WC()->cart->get_total( 'edit' );
		add_filter( 'woocommerce_cart_get_total', array( __CLASS__, 'cart_get_total' ), 99999, 1 );
		
		// Calculate remaining item amount (original item total - item deposit)
		// Original item total = cart_total - fees
		$original_item_total = $cart_total - $fee_total;
		$second_payment = $original_item_total - $item_deposit; // Remaining item amount (€630)
		
		// Deposit amount for order meta - fee-inclusive, matching the classic path.
		$deposit_amount = $item_deposit + $fee_total;

		// Store deposit info in order meta
		$order->update_meta_data( '_vanpos_order_has_remaining_payment', 'yes' );
		$order->update_meta_data( '_vanpos_initial_payment_paid', 'no' );
		$order->update_meta_data( '_vanpos_remaining_payment_paid', 'no' );
		$order->update_meta_data( '_vanpos_initial_payment', $deposit_amount );
		$order->update_meta_data( '_vanpos_remaining_payment', $second_payment );

		// Create payment schedule
		// The main/parent order IS the deposit payment (paid at checkout),
		// so only a 'remaining' child order needs to be created.
		$payment_schedule = array(
			'remaining' => array(
				'id'    => '',
				// Title intentionally empty: the human label is applied centrally by
				// VanPOS_Order_Manager::create_payment_order() from the payment type,
				// the single source of truth for child-order labels.
				'title' => '',
				'type'  => 'remaining',
				'total' => $second_payment,
			),
		);

		$order->update_meta_data( '_vanpos_payment_schedule', $payment_schedule );
		$order->save();

		// Promote rental dates, times, and booking reference from item meta
		// to order-level meta so child orders and AutomateWoo can read them.
		self::promote_rental_meta_to_order( $order );

		// Create partial payment orders immediately
		self::create_partial_payment_orders( $order, $payment_schedule );

		// The main/parent order IS the deposit payment, so mark it as paid.
		$order->update_meta_data( '_vanpos_initial_payment_paid', 'yes' );
		$order->save();
	}

	/**
	 * Create partial payment orders
	 *
	 * Delegates to VanPOS_Order_Manager::create_payment_order() as the single
	 * child-order factory. All meta (rental dates, email-friendly formatted
	 * values, AutomateWoo flags, _is_short_term_booking, VAT line items) is
	 * handled inside that factory - no supplementary "enrich" pass needed.
	 *
	 * @param WC_Order $order Parent order.
	 * @param array    $payment_schedule Payment schedule.
	 * @return void
	 */
	private static function create_partial_payment_orders( $order, $payment_schedule ) {

		foreach ( $payment_schedule as $partial_key => $payment ) {
			// Dedup guard: skip if a child order of this type already exists.
			// Prevents duplicates from payment gateway retries or webhook replays.
			$existing = class_exists( 'VanPOS_Order_Manager' )
				? VanPOS_Order_Manager::find_payment_order( $order->get_id(), $payment['type'] )
				: null;
			if ( $existing ) {
				$payment_schedule[ $partial_key ]['id'] = $existing->get_id();
				continue;
			}

			$child_id = VanPOS_Order_Manager::create_payment_order(
				$order->get_id(),
				$payment['type'],
				$payment['total'],
				$payment['title']
			);

			if ( ! is_wp_error( $child_id ) ) {
				$payment_schedule[ $partial_key ]['id'] = $child_id;
			}
		}

		// Update payment schedule in parent order
		$order->update_meta_data( '_vanpos_payment_schedule', $payment_schedule );
		$order->save();
	}

	/**
	 * Promote rental metadata from line items to order-level meta.
	 *
	 * In the deposit checkout flow, rental dates/times/options are saved to
	 * line-item meta by checkout_create_order_line_item(), but are never
	 * written to order-level meta.  This means child payment orders
	 * (created by create_partial_payment_orders) and AutomateWoo workflows
	 * cannot read them.
	 *
	 * This method reads the first rental item's meta and promotes it to
	 * order-level meta, matching what VanPOS_Order_Manager::create_primary_rental_order()
	 * does for the POS flow.
	 *
	 * NOT to be merged with VanPOS_Order_Manager::update_missing_rental_metadata().
	 * That method is the admin backfill for legacy/broken orders and BACK-DERIVES
	 * the financial meta because the canonical amounts are missing there; this
	 * method READS the canonical initial/remaining amounts that checkout just wrote
	 * and is fill-on-fresh-order only. See the note on that method for the full
	 * list of incompatible invariants. They already share the leaf helpers
	 * (generate_booking_reference, format_price, format_meta_date).
	 *
	 * @param WC_Order $order The parent rental order.
	 * @return void
	 */
	private static function promote_rental_meta_to_order( $order ) {
		// Don't overwrite if already set (e.g. POS flow already handled it).
		if ( $order->get_meta( '_vanpos_booking_reference' ) ) {
			return;
		}

		// Find rental dates from the first line item that has them.
		$pickup_date  = '';
		$return_date  = '';
		$pickup_time  = '';
		$return_time  = '';
		$rental_days  = '';
		$rental_nights = '';
		$include_dog  = false;
		$include_cleaning = false;
		$camper_name  = '';

		foreach ( $order->get_items() as $item ) {
			$pd = $item->get_meta( 'vanpos_pickup_date' );
			if ( ! $pd ) {
				continue; // Not a rental item.
			}

			$pickup_date = $pd;
			$return_date = $item->get_meta( 'vanpos_return_date' );
			$pickup_time = $item->get_meta( 'vanpos_pickup_time' );
			$return_time = $item->get_meta( 'vanpos_return_time' );
			$rental_days = $item->get_meta( 'vanpos_rental_days' );
			$rental_nights = $item->get_meta( 'vanpos_rental_nights' );

			if ( $item->get_meta( 'vanpos_include_dog' ) ) {
				$include_dog = true;
			}
			if ( $item->get_meta( 'vanpos_include_cleaning' ) ) {
				$include_cleaning = true;
			}

			// Capture product/camper name for email templates.
			$product     = $item->get_product();
			$camper_name = $product ? $product->get_title() : $item->get_name();

			break; // Only need the first rental item.
		}

		if ( ! $pickup_date ) {
			return; // No rental data found on items.
		}

		// Promote dates and times to order-level meta.
		$order->update_meta_data( '_vanpos_pickup_date', $pickup_date );
		if ( $return_date ) {
			$order->update_meta_data( '_vanpos_return_date', $return_date );
		}
		if ( $pickup_time ) {
			$order->update_meta_data( '_vanpos_pickup_time', $pickup_time );
		}
		if ( $return_time ) {
			$order->update_meta_data( '_vanpos_return_time', $return_time );
		}
		if ( $rental_days ) {
			$order->update_meta_data( '_vanpos_rental_days', $rental_days );
		}
		// CMIT CODE - stamp the NIGHTS basis so a later POS modification re-rates this
		// order on nights (locked_rate_basis() in VanPOS_Change_Manager keys off this).
		// Derive from dates if the line item predates the nights basis.
		if ( '' === (string) $rental_nights && $return_date && class_exists( 'VanPOS_Functions' ) ) {
			$rental_nights = VanPOS_Functions::rental_nights_from_dates( $pickup_date, $return_date );
		}
		if ( '' !== (string) $rental_nights ) {
			$order->update_meta_data( '_vanpos_rental_nights', (int) $rental_nights );
		}

		// Generate booking reference.
		$order->update_meta_data( '_vanpos_booking_reference', VanPOS_Order_Manager::generate_booking_reference() );

		// Set order type if not already set by customer-account hook.
		if ( ! $order->get_meta( '_vanpos_order_type' ) ) {
			$order->update_meta_data( '_vanpos_order_type', 'primary_rental' );
		}

		// Short-term booking flag.
		$is_long_term = self::is_pickup_beyond_security_deposit_threshold( $pickup_date );
		$order->update_meta_data( '_is_short_term_booking', $is_long_term ? 'no' : 'yes' );

		// Financial meta. The initial/remaining amounts are written canonically at
		// checkout (create_order / block_checkout_create_order); read them back here.
		$deposit_amount   = (float) $order->get_meta( '_vanpos_initial_payment' );
		$second_payment   = (float) $order->get_meta( '_vanpos_remaining_payment' );
		$total_price      = $deposit_amount + $second_payment;
		$order->update_meta_data( '_vanpos_total_price', $total_price );
		$order->update_meta_data( '_vanpos_initial_payment', $deposit_amount );
		$order->update_meta_data( '_vanpos_remaining_payment', $second_payment );

		// Email-friendly meta: pre-formatted values for AutomateWoo templates.
		// Camper/product name (plain text, no product grid).
		if ( $camper_name ) {
			$order->update_meta_data( '_vanpos_camper_name', $camper_name );
		}

		// Human-readable dates in DD-MM-YYYY format for admin/PDF consistency.
		if ( $pickup_date ) {
			$order->update_meta_data( '_vanpos_pickup_date_formatted', VanPOS_Order_Manager::format_meta_date( $pickup_date ) );
		}
		if ( $return_date ) {
			$order->update_meta_data( '_vanpos_return_date_formatted', VanPOS_Order_Manager::format_meta_date( $return_date ) );
		}

		// Pre-formatted prices (plain text, e.g. "€ 2.100,00").
		// Always write - even for €0 - so AutomateWoo templates never render blank.
		$order->update_meta_data( '_vanpos_total_price_formatted', VanPOS_Order_Manager::format_price( $total_price ) );
		$order->update_meta_data( '_vanpos_initial_payment_formatted', VanPOS_Order_Manager::format_price( $deposit_amount ) );
		$order->update_meta_data( '_vanpos_remaining_payment_formatted', VanPOS_Order_Manager::format_price( $second_payment ) );

		// Add-ons.
		if ( $include_dog ) {
			$order->update_meta_data( '_vanpos_include_dog', true );
		}
		if ( $include_cleaning ) {
			$order->update_meta_data( '_vanpos_include_cleaning', true );
		}

		$order->save();
	}

	/**
	 * Payment complete handler
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function payment_complete( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Check if this is the parent/main order (which IS the initial payment).
		// Mark the initial payment as paid when the main order payment completes.
		$has_remaining = $order->get_meta( '_vanpos_order_has_remaining_payment' );
		if ( $has_remaining === 'yes' ) {
			$order->update_meta_data( '_vanpos_initial_payment_paid', 'yes' );
			// Meta-only; save_meta_data() avoids re-persisting (and possibly clobbering)
			// the order's just-set paid status when this fires on woocommerce_payment_complete.
			$order->save_meta_data();
			return;
		}

		// Child order payment completion
		$payment_type = $order->get_meta( '_vanpos_payment_type' );
		$parent_id = $order->get_parent_id();

		if ( ! $parent_id ) {
			return;
		}

		$parent_order = wc_get_order( $parent_id );
		if ( ! $parent_order ) {
			return;
		}

		if ( class_exists( 'VanPOS_Order_Manager' ) && VanPOS_Order_Manager::is_remaining_payment( $payment_type ) ) {
			$parent_order->update_meta_data( '_vanpos_remaining_payment_paid', 'yes' );
		} elseif ( 'security_deposit' === $payment_type ) {
			$parent_order->update_meta_data( '_vanpos_security_deposit_paid', 'yes' );
		}

		// Meta-only on the parent; save_meta_data() avoids re-persisting (and possibly
		// clobbering) the parent's status when a child order's payment completes.
		$parent_order->save_meta_data();
	}

}
