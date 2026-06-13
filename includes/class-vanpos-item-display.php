<?php
/**
 * - Cart
 * - Checkout
 * Unified Order Item Display for VAN-Jorn Rental Platform
 * 
 * Provides consistent order item display across:
 * - Cart
 * - Checkout
 * - Order View
 * - Order Received
 * - Email
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order Item Display Class
 */
class VanPOS_Item_Display {

	/**
	 * Item display types
	 */
	const TYPE_CART = 'cart';
	const TYPE_CHECKOUT = 'checkout';
	const TYPE_ORDER = 'order';
	const TYPE_EMAIL = 'email';

	/**
	 * Initialize hooks (order view, admin, email only - cart/checkout unchanged)
	 * 24 FEB Updated: Removed duplicate email hook.
	 */
	public static function init() {
		// Order display (order view, order received)
		add_action( 'woocommerce_order_item_meta_start', array( __CLASS__, 'display_order_item_meta' ), 5, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_default_meta' ), 10, 1 );
		// hide_default_meta only covers the admin order screen. On the frontend (order
		// received, view order, order-pay/failed) and in emails, WooCommerce hides only
		// underscore-prefixed keys, so the non-underscore rental keys would render raw —
		// duplicating the formatted block above. Strip the same set from formatted meta.
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'hide_frontend_item_meta' ), 10, 2 );

		// Admin order edit - same unified display
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'display_admin_item_meta' ), 10, 3 );

		// Email display (handles plain text and HTML emails)
		add_action( 'woocommerce_email_order_item_meta', array( __CLASS__, 'display_email_item_meta_detailed' ), 5, 4 );

		// Rename "Subtotaal" to "Totaal" in Elementor mini-cart via scoped CSS
		add_action( 'wp_head', array( __CLASS__, 'minicart_subtotal_label_css' ), 99 );

		// Correct the mini-cart "N ×" multiplier from inclusive days to nights.
		// WCRP/Kestrel renders this string with its own day count; we run after
		// it (priority 99) and swap the count to the billed nights. Price math is
		// untouched — only the displayed multiplier changes.
		add_filter( 'woocommerce_widget_cart_item_quantity', array( __CLASS__, 'minicart_nights_quantity' ), 99, 3 );
	}

	/**
	 * Rewrite the mini-cart line quantity multiplier to show billed NIGHTS.
	 *
	 * The cart/checkout totals are already nights-correct (VanPOS seeds the
	 * line price); only WCRP's "{days} × {rate}" mini-cart label still counts
	 * inclusive days. This swaps the leading integer to nights, and only when
	 * the shown count matches the inclusive-day count — so an already-correct
	 * or unexpected string is never corrupted.
	 *
	 * @param string $html          Quantity HTML (e.g. '<span class="quantity">8 &times; …</span>').
	 * @param array  $cart_item     Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public static function minicart_nights_quantity( $html, $cart_item, $cart_item_key ) {
		$is_rental = isset( $cart_item['vanpos_pickup_date'] )
		          || isset( $cart_item['wcrp_rental_products_rent_from'] );
		if ( ! $is_rental ) {
			return $html;
		}

		$nights = isset( $cart_item['vanpos_rental_nights'] ) ? (int) $cart_item['vanpos_rental_nights'] : -1;
		$days   = isset( $cart_item['vanpos_rental_days'] )   ? (int) $cart_item['vanpos_rental_days']   : -1;

		// Older cart items (added before nights basis existed): derive from dates.
		if ( ( $nights < 0 || $days < 0 ) && class_exists( 'VanPOS_Functions' ) ) {
			$pickup = isset( $cart_item['vanpos_pickup_date'] ) ? $cart_item['vanpos_pickup_date'] : '';
			$return = isset( $cart_item['vanpos_return_date'] ) ? $cart_item['vanpos_return_date'] : '';
			if ( $pickup && $return ) {
				$nights = VanPOS_Functions::rental_nights_from_dates( $pickup, $return );
				$days   = VanPOS_Functions::rental_days_from_dates( $pickup, $return );
			}
		}

		if ( $nights < 1 ) {
			return $html;
		}

		// Swap only the leading integer inside the quantity span, and only when
		// it equals the inclusive-day count (the bug signature). wc_price markup
		// after it is left exactly as WCRP rendered it.
		return preg_replace_callback(
			'/(class="quantity">\s*)(\d+)/',
			function ( $m ) use ( $nights, $days ) {
				$shown = (int) $m[2];
				if ( $days > 0 && $shown !== $days ) {
					return $m[0]; // not the day-count we expected — leave untouched
				}
				return $m[1] . $nights;
			},
			$html,
			1
		);
	}

	/**
	 * Output scoped CSS to rename "Subtotaal:" to "Totaal:" inside the
	 * Elementor slide-out mini-cart only.
	 *
	 * @return void
	 */
	public static function minicart_subtotal_label_css() {
		?>
		<style id="vanpos-minicart-subtotal-rename">
			.elementor-menu-cart__subtotal strong {
				font-size: 0;
				line-height: 0;
			}
			.elementor-menu-cart__subtotal strong::after {
				content: "<?php echo esc_attr__( 'Total:', 'vanjorn-rental-pos' ); ?>";
				font-size: 20px;
				line-height: normal;
			}
		</style>
		<?php
	}

	/**
	 * Hide default WooCommerce meta keys to prevent duplicate display
	 *
	 * @param array $hidden_meta Hidden meta keys
	 * @return array Modified hidden meta keys
	 */
	public static function hide_default_meta( $hidden_meta ) {
		return array_merge( $hidden_meta, self::get_hidden_item_meta_keys() );
	}

	/**
	 * Internal rental/VanPOS line-item meta keys that must never render as raw
	 * "key: value" rows. The formatted block (display_order_item_meta /
	 * display_email_item_meta_detailed) presents this information instead.
	 *
	 * @return array<int,string>
	 */
	private static function get_hidden_item_meta_keys() {
		return array(
			'vanpos_pickup_date',
			'vanpos_pickup_time',
			'vanpos_return_date',
			'vanpos_return_time',
			'vanpos_rental_days',
			'vanpos_rental_nights',
			'vanpos_include_dog',
			'vanpos_include_cleaning',
			// Stray underscore duplicates on line items.
			'_vanpos_pickup_date',
			'_vanpos_return_date',
			'_vanpos_pickup_time',
			'_vanpos_return_time',
			'_vanpos_rental_days',
			'_vanpos_rental_nights',
			'_vanpos_include_dog',
			'_vanpos_include_cleaning',
			'_vanpos_original_price',
			'_vanpos_deposit_amount',
			'_vanpos_remaining_amount',
			'wcrp_rental_products_rent_from',
			'wcrp_rental_products_rent_to',
			'wcrp_rental_products_rental_duration',
			// WooCommerce internal key — suppressed here so no theme file is needed for this.
			'_reduced_stock',
		);
	}

	/**
	 * Hide internal line-item meta on the frontend and in emails.
	 *
	 * woocommerce_hidden_order_itemmeta only applies on the admin order screen.
	 * WC_Order_Item::get_formatted_meta_data() (used by wc_display_item_meta on the
	 * order-received / view-order / order-pay pages and in emails) auto-hides only
	 * underscore-prefixed keys, so the non-underscore rental keys would otherwise
	 * leak through as raw rows beside the formatted block. Strip the same set here.
	 *
	 * @param array         $formatted_meta Meta objects keyed by meta id.
	 * @param WC_Order_Item $item           Order line item (unused).
	 * @return array
	 */
	public static function hide_frontend_item_meta( $formatted_meta, $item ) {
		if ( ! is_array( $formatted_meta ) ) {
			return $formatted_meta;
		}
		$hidden = self::get_hidden_item_meta_keys();
		foreach ( $formatted_meta as $id => $meta ) {
			if ( isset( $meta->key ) && in_array( $meta->key, $hidden, true ) ) {
				unset( $formatted_meta[ $id ] );
			}
		}
		return $formatted_meta;
	}

	/**
	 * Read canonical vanpos_* item meta, falling back to stray _vanpos_* copies.
	 *
	 * @param WC_Order_Item $item Order line item.
	 * @param string        $key  Canonical key (e.g. vanpos_pickup_date).
	 * @return string
	 */
	private static function get_order_item_rental_meta_value( $item, $key ) {
		$value = (string) $item->get_meta( $key );
		if ( '' !== $value ) {
			return $value;
		}
		return (string) $item->get_meta( '_' . $key );
	}

	/**
	 * Resolve the primary rental order ID for payment-order lookups.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	private static function resolve_primary_order_id( $order ) {
		$payment_type = (string) $order->get_meta( '_vanpos_payment_type' );
		if ( '' === $payment_type || 'primary_rental' === $payment_type || 'full' === $payment_type ) {
			return (int) $order->get_id();
		}
		$primary_id = (int) $order->get_meta( '_vanpos_primary_order_id' );
		if ( $primary_id > 0 ) {
			return $primary_id;
		}
		$parent_id = (int) $order->get_parent_id();
		return $parent_id > 0 ? $parent_id : (int) $order->get_id();
	}

	/**
	 * Remaining amount to show on line-item display (aligned with admin order-edit sidebar).
	 *
	 * @param WC_Order_Item $item Line item.
	 * @return float Amount to display, or 0 when nothing should be shown.
	 */
	private static function resolve_display_remaining_amount( $item ) {
		$order = $item->get_order();
		if ( ! $order ) {
			return 0.0;
		}

		if ( 'yes' === (string) $order->get_meta( '_is_short_term_booking' ) ) {
			return 0.0;
		}

		$payment_type = (string) $order->get_meta( '_vanpos_payment_type' );
		if ( VanPOS_Order_Manager::is_remaining_payment( $payment_type ) ) {
			return 0.0;
		}
		$primary_id = self::resolve_primary_order_id( $order );
		if ( $primary_id > 0 && class_exists( 'VanPOS_Order_Manager' ) ) {
			if ( VanPOS_Order_Manager::has_remaining_payment_order( $primary_id ) ) {
				return 0.0;
			}
		}

		$item_remaining_sum = 0.0;
		foreach ( $order->get_items() as $order_item ) {
			$item_remaining = (float) $order_item->get_meta( '_vanpos_remaining_amount' );
			if ( $item_remaining > 0 ) {
				$item_remaining_sum += $item_remaining;
			}
		}

		if ( $order->meta_exists( '_vanpos_remaining_payment' ) ) {
			$remaining = (float) $order->get_meta( '_vanpos_remaining_payment' );
		} elseif ( $item_remaining_sum > 0 ) {
			$remaining = $item_remaining_sum;
		} else {
			$remaining = 0.0;
		}

		return $remaining > 0.01 ? $remaining : 0.0;
	}

	/**
	 * Display item meta in order view (order details, order received)
	 * 24 FEB Updated: Fixed method body to properly fetch and render rental meta.
	 *
	 * @param int    $item_id Item ID
	 * @param object $item Order item object
	 * @param object $order Order object
	 * @param bool   $plain_text Plain text flag
	 */
	public static function display_order_item_meta( $item_id, $item, $order, $plain_text ) {
		$rental_meta = self::get_rental_meta_from_item( $item );
		if ( empty( $rental_meta ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . self::render_item_meta_plain_text( $rental_meta );
		} else {
			echo self::render_item_meta_html( $rental_meta, self::TYPE_ORDER, $item );
		}
	}

	/**
	 * Display detailed item meta in email
	 *
	 * @param int    $item_id Item ID
	 * @param object $item Order item object
	 * @param object $order Order object
	 * @param bool   $plain_text Plain text flag
	 */
	public static function display_email_item_meta_detailed( $item_id, $item, $order, $plain_text ) {
		$rental_meta = self::get_rental_meta_from_item( $item );
		if ( empty( $rental_meta ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . self::render_item_meta_plain_text( $rental_meta );
		} else {
			echo self::render_item_meta_html( $rental_meta, self::TYPE_EMAIL, $item );
		}
	}

	/**
	 * Display item meta in admin order edit
	 * 24 FEB Updated: Uses unified rental meta from get_rental_meta_from_item.
	 *
	 * @param int    $item_id Item ID
	 * @param object $item Order item object
	 * @param object $product Product object
	 */
	public static function display_admin_item_meta( $item_id, $item, $product ) {
		$rental_meta = self::get_rental_meta_from_item( $item );

		if ( empty( $rental_meta ) ) {
			return;
		}
		echo self::render_item_meta_html( $rental_meta, self::TYPE_ORDER, $item );
	}

	/**
	 * Get rental meta from cart item
	 *
	 * @param array $cart_item Cart item data
	 * @return array Rental meta data
	 */
	private static function get_rental_meta( $cart_item ) {
		$meta = array();

		if ( isset( $cart_item['vanpos_pickup_date'] ) ) {
			$meta['pickup_date'] = $cart_item['vanpos_pickup_date'];
		}
		if ( isset( $cart_item['vanpos_pickup_time'] ) ) {
			$meta['pickup_time'] = $cart_item['vanpos_pickup_time'];
		}
		if ( isset( $cart_item['vanpos_return_date'] ) ) {
			$meta['return_date'] = $cart_item['vanpos_return_date'];
		}
		if ( isset( $cart_item['vanpos_return_time'] ) ) {
			$meta['return_time'] = $cart_item['vanpos_return_time'];
		}
		if ( isset( $cart_item['vanpos_rental_days'] ) ) {
			$meta['rental_days'] = $cart_item['vanpos_rental_days'];
		}
		if ( isset( $cart_item['vanpos_include_dog'] ) && $cart_item['vanpos_include_dog'] ) {
			$meta['include_dog'] = true;
		}
		if ( isset( $cart_item['vanpos_include_cleaning'] ) && $cart_item['vanpos_include_cleaning'] ) {
			$meta['include_cleaning'] = true;
		}

		// For cart, the cart total is the "Pay Now" amount (50% + fees)
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$meta['initial_payment_amount'] = WC()->cart->get_total( 'edit' );
		}

		return $meta;
	}

	/**
	 * Get rental meta from order item
	 *
	 * @param object $item Order item object
	 * @return array Rental meta data
	 */
	private static function get_rental_meta_from_item( $item ) {
		$meta = array();

		$pickup_date = self::get_order_item_rental_meta_value( $item, 'vanpos_pickup_date' );
		if ( '' === $pickup_date ) {
			$pickup_date = (string) $item->get_meta( 'wcrp_rental_products_rent_from' );
		}

		$pickup_time = self::get_order_item_rental_meta_value( $item, 'vanpos_pickup_time' );

		$return_date = self::get_order_item_rental_meta_value( $item, 'vanpos_return_date' );
		if ( '' === $return_date ) {
			$return_date = (string) $item->get_meta( 'wcrp_rental_products_rent_to' );
		}

		$return_time = self::get_order_item_rental_meta_value( $item, 'vanpos_return_time' );

		$rental_days = self::get_order_item_rental_meta_value( $item, 'vanpos_rental_days' );
		if ( '' === $rental_days ) {
			$rental_days = (string) $item->get_meta( 'wcrp_rental_products_rental_duration' );
		}
		if ( '' === $rental_days && '' !== $pickup_date && '' !== $return_date && class_exists( 'VanPOS_Functions' ) ) {
			$rental_days = (string) VanPOS_Functions::rental_days_from_dates( $pickup_date, $return_date );
		}

		$include_dog = self::get_order_item_rental_meta_value( $item, 'vanpos_include_dog' );
		$include_cleaning = self::get_order_item_rental_meta_value( $item, 'vanpos_include_cleaning' );

		$order = $item->get_order();
		$show_payment_breakdown = ! is_admin();

		$original_price = null;
		$deposit_amount = null;
		$remaining_amount = 0.0;
		$initial_payment_amount = null;

		if ( $show_payment_breakdown ) {
			$original_price = $item->get_meta( '_vanpos_original_price' );
			$deposit_amount = $item->get_meta( '_vanpos_deposit_amount' );
			$remaining_amount = self::resolve_display_remaining_amount( $item );

			if ( $order ) {
				// The primary rental order IS the initial payment in the current
				// architecture; _vanpos_initial_payment on it holds the canonical
				// upfront amount. Reuse the existing order object when it already
				// is the primary to avoid an extra wc_get_order() round-trip.
				$primary_order_id = self::resolve_primary_order_id( $order );
				$source           = ( $primary_order_id === (int) $order->get_id() )
					? $order
					: wc_get_order( $primary_order_id );
				if ( $source ) {
					$raw = (float) $source->get_meta( '_vanpos_initial_payment' );
					if ( $raw > 0 ) {
						$initial_payment_amount = $raw;
					}
				}
			}
		}

		if ( ! empty( $pickup_date ) ) {
			$meta['pickup_date'] = $pickup_date;
		}
		if ( ! empty( $pickup_time ) ) {
			$meta['pickup_time'] = $pickup_time;
		}
		if ( ! empty( $return_date ) ) {
			$meta['return_date'] = $return_date;
		}
		if ( ! empty( $return_time ) ) {
			$meta['return_time'] = $return_time;
		}
		if ( ! empty( $rental_days ) ) {
			$meta['rental_days'] = $rental_days;
		}
		if ( $include_dog ) {
			$meta['include_dog'] = true;
		}
		if ( $include_cleaning ) {
			$meta['include_cleaning'] = true;
		}
		if ( ! empty( $original_price ) ) {
			$meta['original_price'] = $original_price;
		}
		// 24 FEB Updated: Add deposit/payment amounts for display (Pay Now, Future payments).
		if ( ! empty( $deposit_amount ) ) {
			$meta['deposit_amount'] = $deposit_amount;
		}
		if ( $remaining_amount > 0.01 ) {
			$meta['remaining_amount'] = $remaining_amount;
		}
		if ( ! empty( $initial_payment_amount ) ) {
			$meta['initial_payment_amount'] = $initial_payment_amount;
		}

		return $meta;
	}

	/**
	 * Render item meta HTML
	 *
	 * @param array  $cart_item Cart item data
	 * @param string $type Display type
	 * @return string HTML output
	 */
	private static function render_item_meta( $cart_item, $type ) {
		$rental_meta = self::get_rental_meta( $cart_item );
		if ( empty( $rental_meta ) ) {
			return '';
		}

		// For cart/checkout, we don't have item object, so use default type
		return self::render_item_meta_html( $rental_meta, $type, null );
	}


	/**
	 * Render item meta as HTML using WooCommerce variation format
	 *
	 * @param array  $meta Rental meta data
	 * @param string $type Display type (cart, checkout, order, email)
	 * @param object $item Optional order item object
	 * @return string HTML output
	 */
	private static function render_item_meta_html( $meta, $type, $item = null ) {
		$formatted = self::format_rental_meta( $meta, $type );
		if ( empty( $formatted ) ) {
			return '';
		}

		ob_start();
		?>
		<dl class="variation">
			<?php foreach ( $formatted as $entry ) : ?>
				<?php
				$label = $entry['label'];
				$value = $entry['value'];
				// Create CSS class from label (e.g., "Pickup Date" -> "variation-PickupDate")
				$class_name = sanitize_html_class( str_replace( ' ', '', $label ) );
				?>
				<dt class="variation-<?php echo esc_attr( $class_name ); ?>"><?php echo esc_html( $label ); ?>:</dt>
				<dd class="variation-<?php echo esc_attr( $class_name ); ?>">
					<p><?php echo wp_kses_post( $value ); ?></p>
				</dd>
			<?php endforeach; ?>
		</dl>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render item meta as plain text (for emails)
	 *
	 * @param array $meta Rental meta data
	 * @return string Plain text output
	 */
	private static function render_item_meta_plain_text( $meta ) {
		$formatted = self::format_rental_meta( $meta, self::TYPE_EMAIL );
		if ( empty( $formatted ) ) {
			return '';
		}

		$output = '';
		foreach ( $formatted as $entry ) {
			$output .= $entry['label'] . ': ' . wp_strip_all_tags( $entry['value'] ) . "\n";
		}

		return $output;
	}

	/**
	 * Format rental meta data for display
	 * Returns array of entries (allows duplicate labels like "Additional Options")
	 *
	 * @param array  $meta Raw meta data
	 * @param string $type Display type
	 * @return array Formatted meta entries (array of ['label' => ..., 'value' => ...])
	 */
	private static function format_rental_meta( $meta, $type ) {
		$formatted = array();

		// Pickup date (separate from time)
		if ( ! empty( $meta['pickup_date'] ) ) {
			$formatted[] = array(
				'label' => __( 'Pickup date', 'vanjorn-rental-pos' ),
				'value' => self::format_date( $meta['pickup_date'] ),
			);
		}

		// Return date (separate from time)
		if ( ! empty( $meta['return_date'] ) ) {
			$formatted[] = array(
				'label' => __( 'Return date', 'vanjorn-rental-pos' ),
				'value' => self::format_date( $meta['return_date'] ),
			);
		}

		// Pickup time
		if ( ! empty( $meta['pickup_time'] ) ) {
			$formatted[] = array(
				'label' => __( 'Pickup time', 'vanjorn-rental-pos' ),
				'value' => self::format_time_slot( $meta['pickup_time'], 'pickup' ),
			);
		}

		// Return time
		if ( ! empty( $meta['return_time'] ) ) {
			$formatted[] = array(
				'label' => __( 'Return time', 'vanjorn-rental-pos' ),
				'value' => self::format_time_slot( $meta['return_time'], 'return' ),
			);
		}

		// Rental days
		if ( ! empty( $meta['rental_days'] ) ) {
			$days = intval( $meta['rental_days'] );
			$formatted[] = array(
				'label' => __( 'Rental days', 'vanjorn-rental-pos' ),
				'value' => $days . ' ' . _n( 'day', 'days', $days, 'vanjorn-rental-pos' ),
			);
		}

		// Additional options (separate entries for each option to allow duplicates)
		if ( ! empty( $meta['include_dog'] ) ) {
			$formatted[] = array(
				'label' => __( 'Additional options', 'vanjorn-rental-pos' ),
				'value' => __( 'Bring your dog', 'vanjorn-rental-pos' ),
			);
		}

		if ( ! empty( $meta['include_cleaning'] ) ) {
			$cleaning_price = (float) VanPOS_Functions::get_setting( 'vanpos_cleaning_price', 100 );
			$formatted[] = array(
				'label' => __( 'Additional options', 'vanjorn-rental-pos' ),
				'value' => __( 'Use our cleaning service', 'vanjorn-rental-pos' ) . ' (' . wc_price( $cleaning_price ) . ')',
			);
		}

		// Pay now (deposit % + additional fees) - show initial payment amount
		$deposit_pct = (int) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 );
		/* translators: %d is the deposit percentage */
		$pay_now_label = sprintf( __( 'Pay now (%d%% deposit + additional fees)', 'vanjorn-rental-pos' ), $deposit_pct );
		if ( ! empty( $meta['initial_payment_amount'] ) ) {
			$formatted[] = array(
				'label' => $pay_now_label,
				'value' => wc_price( $meta['initial_payment_amount'] ),
			);
		} elseif ( ! empty( $meta['deposit_amount'] ) ) {
			// Fallback to deposit_amount if initial_payment_amount not available
			$formatted[] = array(
				'label' => $pay_now_label,
				'value' => wc_price( $meta['deposit_amount'] ),
			);
		}

		// Future payments (remaining amount) — only when still owed and no remaining child order exists.
		if ( isset( $meta['remaining_amount'] ) && (float) $meta['remaining_amount'] > 0.01 ) {
			$formatted[] = array(
				'label' => __( 'Remaining amount', 'vanjorn-rental-pos' ),
				'value' => wc_price( $meta['remaining_amount'] ),
			);
		}

		return $formatted;
	}

	/**
	 * Format date for display
	 *
	 * @param string $date Date string
	 * @return string Formatted date
	 */
	private static function format_date( $date ) {
		if ( empty( $date ) ) {
			return '';
		}

		// Try to parse and format
		$timestamp = strtotime( $date );
		if ( $timestamp ) {
			return date_i18n( get_option( 'date_format' ), $timestamp );
		}

		return esc_html( $date );
	}

	/**
	 * Convert legacy slot labels (morning/afternoon) to configured times.
	 *
	 * Slot-to-time mapping is intentionally independent of $type (pickup/return):
	 *   'afternoon' → vanpos_pickup_time  (15:00 in the default afternoon-pickup model)
	 *   'morning'   → vanpos_return_time  (11:00 in the default afternoon-pickup model)
	 *
	 * In an afternoon-pickup model a "morning pickup" slot is unusual; using the
	 * return-time setting (vanpos_return_time, typically 11:00) as the morning
	 * reference is intentional — it is the only morning time configured.
	 * The $type parameter is kept for future extensibility but does not change
	 * which setting is read.
	 *
	 * @param string $value Raw time-slot value.
	 * @param string $type  Slot type: pickup|return (currently informational only).
	 * @return string
	 */
	private static function format_time_slot( $value, $type = 'pickup' ) {
		$raw = strtolower( trim( (string) $value ) );

		if ( 'afternoon' === $raw ) {
			return esc_html( VanPOS_Functions::get_setting( 'vanpos_pickup_time', '15:00' ) );
		}
		if ( 'morning' === $raw ) {
			return esc_html( VanPOS_Functions::get_setting( 'vanpos_return_time', '11:00' ) );
		}

		return esc_html( ucfirst( (string) $value ) );
	}

}
