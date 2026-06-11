<?php
/**
 * Query rental orders for the admin bookings calendar.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds calendar event payloads from WooCommerce orders.
 */
class VanPOS_Bookings_Calendar_Query {

	/**
	 * WooCommerce order statuses excluded from the bookings calendar (operational view only).
	 *
	 * @var string[]
	 */
	private static $excluded_order_statuses = array(
		'trash',
		'cancelled',
		'failed',
		'draft',
		'auto-draft',
	);

	/**
	 * Admin URL to edit an order (HPOS vs posts).
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function order_edit_admin_url( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return '';
		}
		$use_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $use_hpos ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * First rental line item product ID and display label (excludes security-deposit product).
	 *
	 * @param WC_Order $order Order.
	 * @return array{product_id: int, label: string}
	 */
	public static function get_booking_product_display( $order ) {
		$out         = array(
			'product_id' => 0,
			'label'      => '',
		);
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return $out;
		}

		$label       = $order->get_meta( '_vanpos_camper_name' );
		$label       = is_string( $label ) ? trim( $label ) : '';
		$deposit_pid = (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' );
		$product_id  = 0;

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
			$orig = VanPOS_Functions::get_original_product_id( $pid );
			if ( $deposit_pid > 0 && $orig === $deposit_pid ) {
				continue;
			}
			if ( '' === $label ) {
				$label = $item->get_name();
			}
			$product_id = $pid;
			break;
		}

		if ( '' === $label && $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$label = $product->get_name();
			}
		}

		$out['label']      = $label;
		$out['product_id'] = $product_id;
		return $out;
	}

	/**
	 * Rental lifecycle class for styling: pending | ongoing | completed.
	 *
	 * @param string   $pickup Y-m-d.
	 * @param string   $return Y-m-d.
	 * @param WC_Order $order  Order.
	 * @return string
	 */
	public static function booking_lifecycle_class( $pickup, $return, $order ) {
		$today = current_time( 'Y-m-d' );
		$st    = $order->get_status();

		if ( in_array( $st, array( 'completed', 'refunded' ), true ) ) {
			return 'completed';
		}
		if ( $return < $today ) {
			return 'completed';
		}
		if ( $pickup <= $today && $return >= $today ) {
			return 'ongoing';
		}
		return 'pending';
	}

	/**
	 * Plain-text price for JSON/JS (decodes entities left after strip_tags).
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function format_plain_price( $amount ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $amount;
		}
		$html = wc_price( (float) $amount );
		$flat = is_string( $html ) ? wp_strip_all_tags( $html ) : '';
		return html_entity_decode( $flat, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Short payment summary for tooltips.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function payment_summary( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '';
		}
		$total = (float) $order->get_total();
		$plain = self::format_plain_price( $total );
		if ( $order->is_paid() ) {
			return sprintf(
				/* translators: %s: formatted order total */
				__( 'Paid (%s)', 'vanjorn-rental-pos' ),
				$plain
			);
		}
		return sprintf(
			/* translators: %s: formatted order total */
			__( 'Due: %s', 'vanjorn-rental-pos' ),
			$plain
		);
	}

	/**
	 * Human-readable rental phase (pickup/return window), separate from WC order status.
	 *
	 * @param string   $pickup Y-m-d.
	 * @param string   $return Y-m-d.
	 * @param WC_Order $order  Order.
	 * @return string
	 */
	private static function booking_phase_label( $pickup, $return, $order ) {
		$phase = self::booking_lifecycle_class( $pickup, $return, $order );
		if ( 'completed' === $phase ) {
			return __( 'Pickup and return are in the past, or the booking is closed.', 'vanjorn-rental-pos' );
		}
		if ( 'ongoing' === $phase ) {
			return __( 'Pickup has started; the customer still has the camper.', 'vanjorn-rental-pos' );
		}
		return __( 'Pickup is still in the future.', 'vanjorn-rental-pos' );
	}

	/**
	 * Extra detail strings for the bookings calendar detail panel.
	 *
	 * @param WC_Order $order  Primary rental order.
	 * @param string   $pickup Y-m-d.
	 * @param string   $return Y-m-d.
	 * @return array<string, string>
	 */
	private static function booking_panel_financial_props( WC_Order $order, $pickup, $return ) {
		$out = array(
			'bookingPhaseLabel'     => self::booking_phase_label( $pickup, $return, $order ),
			'rentalReference'       => '',
			'contractTotal'         => '',
			'breakdownTotal'        => '',
			'initialOnMainOrder'    => '',
			'scheduledRemaining'    => '',
			'cleaningService'       => '',
			'bringYourDog'          => '',
			'remainingOrderInfo'    => '',
			'remainingOrderLabel'   => '',
			'remainingOrderDetail'  => '',
			'securityDepositInfo'   => '',
			'securityDepositLabel'  => '',
			'securityDepositDetail' => '',
			'shortTermNote'         => '',
		);

		$ref = $order->get_meta( '_vanpos_booking_reference' );
		$out['rentalReference'] = is_string( $ref ) ? trim( $ref ) : '';

		$short = $order->get_meta( '_is_short_term_booking' );
		if ( 'yes' === $short ) {
			$out['shortTermNote'] = __( 'Short-term booking: the full rental amount is collected on the main order (no split schedule).', 'vanjorn-rental-pos' );
		}

		$total_price = (float) $order->get_meta( '_vanpos_total_price' );
		$initial     = (float) $order->get_meta( '_vanpos_initial_payment' );
		if ( $total_price > 0 ) {
			$out['contractTotal'] = self::format_plain_price( $total_price );
		}
		if ( $initial > 0 || 'yes' === $short ) {
			$out['initialOnMainOrder'] = self::format_plain_price( $initial );
		}

		$remaining_amount = 0;
		foreach ( $order->get_items() as $item ) {
			$item_remaining = (float) $item->get_meta( '_vanpos_remaining_amount' );
			if ( $item_remaining > 0 ) {
				$remaining_amount += $item_remaining;
			}
		}
		if ( $remaining_amount <= 0 ) {
			$remaining_amount = (float) $order->get_meta( '_vanpos_remaining_payment' );
		}
		if ( $remaining_amount > 0.00001 ) {
			$out['scheduledRemaining'] = self::format_plain_price( $remaining_amount );
		}

		$extras = self::get_booking_extras_amounts( $order );
		if ( $extras['cleaning'] > 0 || self::is_truthy_meta_flag( $extras['cleaning_included'] ) ) {
			$out['cleaningService'] = self::format_plain_price( $extras['cleaning'] );
		}
		if ( $extras['dog'] > 0 || self::is_truthy_meta_flag( $extras['dog_included'] ) ) {
			$out['bringYourDog'] = self::format_plain_price( $extras['dog'] );
		}

		$sum_breakdown = $total_price + $extras['cleaning'] + $extras['dog'];
		if ( $sum_breakdown > 0.00001 ) {
			$out['breakdownTotal'] = self::format_plain_price( $sum_breakdown );
		}

		$security_deposit_amount = (float) $order->get_meta( '_vanpos_security_deposit_payment' );
		if ( $security_deposit_amount <= 0 ) {
			$sd_product_id = VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' );
			if ( $sd_product_id ) {
				$sd_product = wc_get_product( (int) $sd_product_id );
				if ( $sd_product ) {
					$security_deposit_amount = (float) $sd_product->get_price();
				}
			}
		}

		$child_remaining = null;
		$child_sd        = null;
		if ( class_exists( 'VanPOS_Order_Manager' ) ) {
			$children = VanPOS_Order_Manager::get_payment_orders( $order->get_id() );
			foreach ( $children as $co ) {
				if ( ! is_a( $co, 'WC_Order' ) ) {
					continue;
				}
				$pt = $co->get_meta( '_vanpos_payment_type' );
				if ( 'remaining' === $pt && ! $child_remaining ) {
					$child_remaining = $co;
				}
				if ( 'security_deposit' === $pt && ! $child_sd ) {
					$child_sd = $co;
				}
			}
		}

		if ( $child_remaining ) {
			$c   = $child_remaining;
			$st  = self::get_order_status_label( $c );
			$tot = self::format_plain_price( (float) $c->get_total() );
			$out['remainingOrderLabel'] = sprintf(
				/* translators: %s: order number (human) */
				__( 'Remaining order #%s', 'vanjorn-rental-pos' ),
				(string) $c->get_order_number()
			);
			if ( $c->is_paid() ) {
				$out['remainingOrderDetail'] = sprintf(
					/* translators: 1: WooCommerce status label, 2: formatted amount */
					__( '%1$s — paid (%2$s)', 'vanjorn-rental-pos' ),
					$st,
					$tot
				);
			} else {
				$out['remainingOrderDetail'] = sprintf(
					/* translators: 1: WooCommerce status label, 2: formatted amount */
					__( '%1$s — %2$s due', 'vanjorn-rental-pos' ),
					$st,
					$tot
				);
			}
			$out['remainingOrderInfo'] = $out['remainingOrderLabel'] . ' — ' . $out['remainingOrderDetail'];
		} elseif ( $remaining_amount > 0.00001 ) {
			$out['remainingOrderLabel']  = __( 'Remaining payment', 'vanjorn-rental-pos' );
			$out['remainingOrderDetail'] = sprintf(
				/* translators: %s: money amount */
				__( 'No separate order yet — %s scheduled on this booking.', 'vanjorn-rental-pos' ),
				self::format_plain_price( $remaining_amount )
			);
			$out['remainingOrderInfo'] = $out['remainingOrderDetail'];
		} else {
			$out['remainingOrderLabel']  = __( 'Remaining payment', 'vanjorn-rental-pos' );
			$out['remainingOrderDetail'] = __( 'No remaining rental balance on this booking.', 'vanjorn-rental-pos' );
			$out['remainingOrderInfo']   = $out['remainingOrderDetail'];
		}

		if ( $child_sd ) {
			$c   = $child_sd;
			$st  = self::get_order_status_label( $c );
			$tot = self::format_plain_price( (float) $c->get_total() );
			$out['securityDepositLabel'] = sprintf(
				/* translators: %s: order number (human) */
				__( 'Security deposit #%s', 'vanjorn-rental-pos' ),
				(string) $c->get_order_number()
			);
			if ( $c->is_paid() ) {
				$out['securityDepositDetail'] = sprintf(
					/* translators: 1: WooCommerce status label, 2: formatted amount */
					__( '%1$s — paid (%2$s)', 'vanjorn-rental-pos' ),
					$st,
					$tot
				);
			} else {
				$out['securityDepositDetail'] = sprintf(
					/* translators: 1: WooCommerce status label, 2: formatted amount */
					__( '%1$s — %2$s due', 'vanjorn-rental-pos' ),
					$st,
					$tot
				);
			}
			$out['securityDepositInfo'] = $out['securityDepositLabel'] . ' — ' . $out['securityDepositDetail'];
		} elseif ( $security_deposit_amount > 0.00001 ) {
			$out['securityDepositLabel']  = __( 'Security deposit', 'vanjorn-rental-pos' );
			$out['securityDepositDetail'] = sprintf(
				/* translators: %s: expected deposit amount */
				__( 'No order yet — expected hold %s (create from the main order if needed).', 'vanjorn-rental-pos' ),
				self::format_plain_price( $security_deposit_amount )
			);
			$out['securityDepositInfo'] = $out['securityDepositDetail'];
		} else {
			$out['securityDepositLabel']  = __( 'Security deposit', 'vanjorn-rental-pos' );
			$out['securityDepositDetail'] = __( 'No security deposit amount configured for this booking.', 'vanjorn-rental-pos' );
			$out['securityDepositInfo']   = $out['securityDepositDetail'];
		}

		return $out;
	}

	/**
	 * Resolve cleaning and dog add-on amounts for booking panel financials.
	 *
	 * @param WC_Order $order Primary rental order.
	 * @return array{cleaning:float,dog:float,cleaning_included:string,dog_included:string}
	 */
	private static function get_booking_extras_amounts( WC_Order $order ) {
		$out = array(
			'cleaning'          => 0.0,
			'dog'               => 0.0,
			'cleaning_included' => '',
			'dog_included'      => '',
		);

		$cleaning_labels = array(
			strtolower( __( 'Use our cleaning service', 'vanjorn-rental-pos' ) ),
			strtolower( __( 'Use our cleaning service (free)', 'vanjorn-rental-pos' ) ),
			strtolower( __( 'Cleaning service', 'vanjorn-rental-pos' ) ),
		);
		$dog_labels      = array(
			strtolower( __( 'Bring your dog', 'vanjorn-rental-pos' ) ),
		);

		foreach ( $order->get_items( 'fee' ) as $fee_item ) {
			if ( ! is_a( $fee_item, 'WC_Order_Item_Fee' ) ) {
				continue;
			}

			$name = strtolower( trim( wp_strip_all_tags( (string) $fee_item->get_name() ) ) );
			if ( '' === $name ) {
				continue;
			}

			$amount = (float) $fee_item->get_total() + (float) $fee_item->get_total_tax();

			if ( self::name_matches_extra_label( $name, $cleaning_labels, array( 'clean' ) ) ) {
				$out['cleaning'] += $amount;
			}
			if ( self::name_matches_extra_label( $name, $dog_labels, array( 'dog' ) ) ) {
				$out['dog'] += $amount;
			}
		}

		$out['cleaning_included'] = (string) $order->get_meta( '_vanpos_include_cleaning' );
		$out['dog_included']      = (string) $order->get_meta( '_vanpos_include_dog' );

		if ( $out['cleaning'] <= 0 && self::is_truthy_meta_flag( $out['cleaning_included'] ) ) {
			$out['cleaning'] = (float) VanPOS_Functions::get_setting( 'vanpos_cleaning_price', 100 );
		}
		if ( $out['dog'] <= 0 && self::is_truthy_meta_flag( $out['dog_included'] ) ) {
			$out['dog'] = (float) VanPOS_Functions::get_setting( 'vanpos_dog_price', 100 );
		}

		return $out;
	}

	/**
	 * Match fee label against known translated labels and lightweight keywords.
	 *
	 * @param string            $name     Lowercase normalized fee item name.
	 * @param array<int,string> $labels   Candidate labels.
	 * @param array<int,string> $keywords Candidate keyword hints.
	 * @return bool
	 */
	private static function name_matches_extra_label( $name, array $labels, array $keywords ) {
		foreach ( $labels as $label ) {
			$label = trim( (string) $label );
			if ( '' !== $label && false !== strpos( $name, $label ) ) {
				return true;
			}
		}

		foreach ( $keywords as $kw ) {
			$kw = trim( strtolower( (string) $kw ) );
			if ( '' !== $kw && false !== strpos( $name, $kw ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize yes/true style order-meta flags.
	 *
	 * @param string $value Raw order-meta value.
	 * @return bool
	 */
	private static function is_truthy_meta_flag( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * Human-readable VanPOS order type.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_type_label( $order ) {
		$t = $order->get_meta( '_vanpos_order_type' );
		if ( 'primary_rental' === $t ) {
			return __( 'Main rental', 'vanjorn-rental-pos' );
		}
		if ( 'payment_order' === $t ) {
			return __( 'Payment order', 'vanjorn-rental-pos' );
		}
		if ( $t ) {
			return (string) $t;
		}
		return __( 'Rental booking', 'vanjorn-rental-pos' );
	}

	/**
	 * Whether order / display fields match a free-text search (admin calendar).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $disp  From get_booking_product_display().
	 * @param string   $search Normalized lowercase needle (may be empty).
	 * @return bool
	 */
	private static function order_matches_search( $order, $disp, $search ) {
		if ( '' === $search ) {
			return true;
		}
		$candidates = array(
			(string) $order->get_id(),
			(string) $order->get_order_number(),
			$disp['label'],
			trim( $order->get_formatted_billing_full_name() ),
			$order->get_billing_email(),
			$order->get_billing_phone(),
			$order->get_billing_first_name(),
			$order->get_billing_last_name(),
			$order->get_billing_company(),
			self::get_order_status_label( $order ),
		);
		foreach ( $candidates as $bit ) {
			$bit = is_string( $bit ) ? strtolower( trim( wp_strip_all_tags( $bit ) ) ) : '';
			if ( '' !== $bit && false !== strpos( $bit, $search ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * WooCommerce human-readable order status for titles and search.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function get_order_status_label( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '';
		}
		$slug = $order->get_status();
		$slug = is_string( $slug ) ? str_replace( 'wc-', '', $slug ) : '';
		if ( '' === $slug ) {
			return '';
		}
		$key = ( 0 === strpos( $slug, 'wc-' ) ) ? $slug : 'wc-' . $slug;
		if ( function_exists( 'wc_get_order_status_name' ) ) {
			return (string) wc_get_order_status_name( $key );
		}
		return $slug;
	}

	/**
	 * Calendar bar title: Order #… Customer (Status) - Return expected (WCRP-style).
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $return Y-m-d return.
	 * @return string
	 */
	private static function format_event_bar_title( $order, $return ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return '';
		}
		$today    = current_time( 'Y-m-d' );
		$customer = trim( $order->get_formatted_billing_full_name() );
		$status   = self::get_order_status_label( $order );

		$title = __( 'Order #', 'vanjorn-rental-pos' ) . $order->get_order_number();
		if ( '' !== $customer ) {
			$title .= ' ' . $customer;
		}
		$title .= ' (' . $status . ')';

		$slug      = $order->get_status();
		$terminal  = in_array( $slug, array( 'completed', 'refunded', 'cancelled', 'failed', 'trash' ), true );
		$return_on = ( $return >= $today );

		if ( ! $terminal && $return_on ) {
			$title .= ' - ' . __( 'Return expected', 'vanjorn-rental-pos' );
		}

		return $title;
	}

	/**
	 * Inclusive rental day count (pickup through return, calendar days).
	 *
	 * @param string $pickup Y-m-d.
	 * @param string $return Y-m-d.
	 * @return int
	 */
	private static function rental_day_count( $pickup, $return ) {
		$ts1 = strtotime( $pickup . ' 00:00:00' );
		$ts2 = strtotime( $return . ' 00:00:00' );
		if ( ! $ts1 || ! $ts2 || $ts2 < $ts1 ) {
			return 0;
		}
		return (int) floor( ( $ts2 - $ts1 ) / DAY_IN_SECONDS ) + 1;
	}

	/**
	 * Normalized excluded status slugs (filterable).
	 *
	 * @return string[]
	 */
	private static function get_excluded_order_status_slugs() {
		$excluded = apply_filters(
			'vanpos_bookings_calendar_excluded_order_statuses',
			self::$excluded_order_statuses
		);
		if ( ! is_array( $excluded ) ) {
			$excluded = self::$excluded_order_statuses;
		}

		return array_map(
			static function ( $slug ) {
				return str_replace( 'wc-', '', (string) $slug );
			},
			$excluded
		);
	}

	/**
	 * WooCommerce statuses to pass into wc_get_orders() for the calendar.
	 *
	 * @return string[]
	 */
	private static function get_included_order_statuses() {
		$excluded = self::get_excluded_order_status_slugs();
		$out      = array();

		foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
			$slug = str_replace( 'wc-', '', $status );
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}
			$out[] = $status;
		}

		return $out;
	}

	/**
	 * Fetch orders overlapping a date range and map to FullCalendar event objects.
	 *
	 * @param string $range_start Y-m-d (inclusive).
	 * @param string $range_end   Y-m-d (inclusive).
	 * @param int    $product_id  0 = all vans, else filter by product or variation (original ID match).
	 * @param string $search      Optional case-insensitive substring match on van, order #, customer, phone, email.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_events( $range_start, $range_end, $product_id = 0, $search = '' ) {
		$range_start = self::normalize_date( $range_start );
		$range_end   = self::normalize_date( $range_end );
		if ( ! $range_start || ! $range_end || $range_start > $range_end ) {
			return array();
		}

		$limit = (int) apply_filters( 'vanpos_bookings_calendar_query_limit', 500 );

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_vanpos_pickup_date',
				'value'   => $range_end,
				'compare' => '<=',
				'type'    => 'DATE',
			),
			array(
				'key'     => '_vanpos_return_date',
				'value'   => $range_start,
				'compare' => '>=',
				'type'    => 'DATE',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => '_vanpos_order_type',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_vanpos_order_type',
					'value'   => 'payment_order',
					'compare' => '!=',
				),
			),
		);

		$statuses = self::get_included_order_statuses();
		if ( empty( $statuses ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'      => $limit,
				'status'     => $statuses,
				'meta_query' => $meta_query,
				'orderby'    => 'meta_value',
				'meta_key'   => '_vanpos_pickup_date',
				'order'      => 'ASC',
			)
		);

		$product_id = (int) $product_id;
		$search     = is_string( $search ) ? strtolower( trim( wp_strip_all_tags( $search ) ) ) : '';
		$events     = array();

		$excluded_slugs = self::get_excluded_order_status_slugs();

		foreach ( $orders as $order ) {
			if ( ! is_a( $order, 'WC_Order' ) ) {
				continue;
			}
			if ( in_array( $order->get_status(), $excluded_slugs, true ) ) {
				continue;
			}
			$pickup = $order->get_meta( '_vanpos_pickup_date' );
			$return = $order->get_meta( '_vanpos_return_date' );

			// Line-item fallback for legacy orders whose dates were not promoted to
			// order-level meta (_vanpos_pickup_date / _vanpos_return_date).
			// Note: the wc_get_orders() meta_query above still requires those order-level
			// keys to exist, so truly legacy orders (pre-backfill) are invisible at the
			// query level; running VanPOS_Order_Manager::update_missing_rental_metadata()
			// over historical orders (via the Audit tool) is the permanent fix.
			// This fallback handles edge cases where the order passed the query but has
			// empty meta (e.g. meta saved on items only as vanpos_pickup_date).
			if ( empty( $pickup ) || empty( $return ) ) {
				foreach ( $order->get_items() as $item ) {
					if ( empty( $pickup ) ) {
						$pickup = (string) $item->get_meta( 'vanpos_pickup_date' );
						if ( '' === $pickup ) {
							$pickup = (string) $item->get_meta( 'wcrp_rental_products_rent_from' );
						}
					}
					if ( empty( $return ) ) {
						$return = (string) $item->get_meta( 'vanpos_return_date' );
						if ( '' === $return ) {
							$return = (string) $item->get_meta( 'wcrp_rental_products_rent_to' );
						}
					}
					if ( $pickup && $return ) {
						break;
					}
				}
			}
			$pickup = self::normalize_date( $pickup );
			$return = self::normalize_date( $return );
			if ( ! $pickup || ! $return || $pickup > $return ) {
				continue;
			}

			$disp = self::get_booking_product_display( $order );
			if ( $product_id > 0 ) {
				if ( ! $disp['product_id'] ) {
					continue;
				}
				$orig_line = (int) VanPOS_Functions::get_original_product_id( $disp['product_id'] );
				$orig_f    = (int) VanPOS_Functions::get_original_product_id( $product_id );
				if ( $orig_line !== $orig_f ) {
					continue;
				}
			}

			if ( ! self::order_matches_search( $order, $disp, $search ) ) {
				continue;
			}

			$end_exclusive = date( 'Y-m-d', strtotime( $return . ' +1 day' ) );

			$status_class    = self::booking_lifecycle_class( $pickup, $return, $order );
			$oid              = $order->get_id();
			$rental_days      = self::rental_day_count( $pickup, $return );
			$orig_pid         = $disp['product_id'] ? (int) VanPOS_Functions::get_original_product_id( $disp['product_id'] ) : 0;
			$order_status_lbl = self::get_order_status_label( $order );
			$bar_title        = self::format_event_bar_title( $order, $return );
			$finance          = self::booking_panel_financial_props( $order, $pickup, $return );

			$events[] = array(
				'id'             => (string) $oid,
				'title'          => $bar_title,
				'start'          => $pickup,
				'end'            => $end_exclusive,
				'allDay'         => true,
				'url'            => self::order_edit_admin_url( $oid ),
				'extendedProps'  => array_merge(
					array(
						'camper'            => $disp['label'],
						'customer'          => trim( $order->get_formatted_billing_full_name() ),
						'phone'             => $order->get_billing_phone(),
						'payment'           => self::payment_summary( $order ),
						'type'              => self::order_type_label( $order ),
						'status'            => $status_class,
						'productId'         => $disp['product_id'],
						'originalProductId' => $orig_pid,
						'pickupDate'        => $pickup,
						'returnDate'        => $return,
						'orderStatus'       => $order->get_status(),
						'orderStatusLabel'  => $order_status_lbl,
						'orderNumber'       => (string) $order->get_order_number(),
						'orderEditUrl'      => self::order_edit_admin_url( $oid ),
						'rentalDays'        => $rental_days,
					),
					$finance
				),
			);
		}

		return $events;
	}

	/**
	 * One-line pickup–return label for autocomplete (locale-aware).
	 *
	 * @param string $pickup Y-m-d.
	 * @param string $return Y-m-d.
	 * @return string
	 */
	public static function format_suggestion_date_range( $pickup, $return ) {
		$pickup = self::normalize_date( $pickup );
		$return = self::normalize_date( $return );
		if ( ! $pickup || ! $return ) {
			return '';
		}
		$ts1 = strtotime( $pickup . ' 00:00:00' );
		$ts2 = strtotime( $return . ' 00:00:00' );
		if ( ! $ts1 || ! $ts2 ) {
			return '';
		}
		$start_fmt = wp_date( 'j M y', $ts1 );
		$end_fmt   = wp_date( 'j M Y', $ts2 );
		return $start_fmt . ' - ' . $end_fmt;
	}

	/**
	 * Booking rows for search autocomplete (wide pickup window, same match rules as the calendar).
	 *
	 * @param string $query      Free-text needle (min length enforced by caller).
	 * @param int    $product_id 0 = all vans, else same filter as the calendar van dropdown.
	 * @param int    $limit      Max suggestions.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_search_suggestions( $query, $product_id = 0, $limit = 15 ) {
		$query = is_string( $query ) ? strtolower( trim( wp_strip_all_tags( $query ) ) ) : '';
		if ( strlen( $query ) < 2 ) {
			return array();
		}
		$limit = max( 1, min( 25, (int) $limit ) );

		try {
			$tz = wp_timezone();
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}
		try {
			$now = new DateTimeImmutable( 'now', $tz );
		} catch ( Exception $e ) {
			$now = new DateTimeImmutable( 'now' );
		}
		$range_start = $now->modify( '-2 years' )->format( 'Y-m-d' );
		$range_end   = $now->modify( '+3 years' )->format( 'Y-m-d' );

		$bump_limit = static function () {
			return 800;
		};
		add_filter( 'vanpos_bookings_calendar_query_limit', $bump_limit, 99 );
		try {
			$events = self::get_events( $range_start, $range_end, (int) $product_id, $query );
		} finally {
			remove_filter( 'vanpos_bookings_calendar_query_limit', $bump_limit, 99 );
		}

		$out = array();
		$n   = 0;
		foreach ( $events as $ev ) {
			if ( $n >= $limit ) {
				break;
			}
			$ep = isset( $ev['extendedProps'] ) && is_array( $ev['extendedProps'] ) ? $ev['extendedProps'] : array();
			$pickup = isset( $ep['pickupDate'] ) ? (string) $ep['pickupDate'] : '';
			$ret    = isset( $ep['returnDate'] ) ? (string) $ep['returnDate'] : '';
			$out[]  = array(
				'id'                  => isset( $ev['id'] ) ? (string) $ev['id'] : '',
				'title'               => isset( $ev['title'] ) ? (string) $ev['title'] : '',
				'pickupDate'          => $pickup,
				'returnDate'          => $ret,
				'rangeLabel'          => self::format_suggestion_date_range( $pickup, $ret ),
				'camper'              => isset( $ep['camper'] ) ? (string) $ep['camper'] : '',
				'orderNumber'         => isset( $ep['orderNumber'] ) ? (string) $ep['orderNumber'] : '',
				'originalProductId'   => isset( $ep['originalProductId'] ) ? (int) $ep['originalProductId'] : 0,
			);
			++$n;
		}

		return $out;
	}

	/**
	 * Normalize a date string to Y-m-d or empty.
	 *
	 * @param mixed $date Input.
	 * @return string
	 */
	private static function normalize_date( $date ) {
		if ( ! is_string( $date ) || '' === trim( $date ) ) {
			return '';
		}
		$ts = strtotime( $date );
		if ( ! $ts ) {
			return '';
		}
		return date( 'Y-m-d', $ts );
	}
}
