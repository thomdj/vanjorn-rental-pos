<?php
/**
 * Primary rental order trash / linked payment order handling.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Order_Deletion {

	/**
	 * Delegate to the single source of truth: VanPOS_Order_Manager::is_primary_rental_order().
	 *
	 * Previously carried its own copy of the detection logic with a subtly looser
	 * order_type gate — only 'payment_order' triggered an early false, while
	 * VanPOS_Order_Manager returns false for any non-empty non-'primary_rental' type.
	 * Both callers (this class and VanPOS_Admin_Order_Edit) now route through
	 * Order Manager so the rules are defined and maintained in one place.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	public static function is_primary_rental_order( $order ) {
		// Single source of truth lives in VanPOS_Order_Manager.
		if ( class_exists( 'VanPOS_Order_Manager' ) ) {
			return VanPOS_Order_Manager::is_primary_rental_order( $order );
		}

		// Fallback: mirrors VanPOS_Order_Manager::is_primary_rental_order() exactly,
		// including the stricter '' !== $type gate — any set-but-unrecognised
		// _vanpos_order_type returns false rather than falling through to heuristics.
		// Keep in sync if Order Manager's detection logic ever changes.
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$type = (string) $order->get_meta( '_vanpos_order_type' );
		if ( 'primary_rental' === $type ) {
			return true;
		}
		if ( '' !== $type ) {
			return false;
		}
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

	/**
	 * CMIT CODE - UPDATED - 05 MAY 2026
	 * Trash primary rental order; optionally trash linked payment orders first; clear Kestrel rows on primary.
	 *
	 * @param int  $primary_order_id Primary WC order ID.
	 * @param bool $delete_children  When true, trash all payment orders returned by VanPOS_Order_Manager::get_payment_orders().
	 * @return true|WP_Error
	 */
	public static function trash_primary_rental_group( $primary_order_id, $delete_children ) {
		return self::process_primary_rental_group( $primary_order_id, $delete_children, 'trash' );
	}

	/**
	 * Cancel primary rental order; optionally cancel linked payment orders; clear Kestrel rows on primary.
	 *
	 * @param int  $primary_order_id Primary WC order ID.
	 * @param bool $cancel_children  When true, cancel all VanPOS-linked payment orders.
	 * @return true|WP_Error
	 */
	public static function cancel_primary_rental_group( $primary_order_id, $cancel_children ) {
		return self::process_primary_rental_group( $primary_order_id, $cancel_children, 'cancel' );
	}

	/**
	 * Trash or cancel a primary rental booking and optionally its linked payment orders.
	 *
	 * @param int    $primary_order_id Primary WC order ID.
	 * @param bool   $include_children Include linked payment orders.
	 * @param string $action           Either trash or cancel.
	 * @return true|WP_Error
	 */
	private static function process_primary_rental_group( $primary_order_id, $include_children, $action ) {
		$primary_order_id = absint( $primary_order_id );
		if ( ! $primary_order_id ) {
			return new WP_Error( 'invalid_id', __( 'Invalid order ID.', 'vanjorn-rental-pos' ) );
		}

		if ( ! in_array( $action, array( 'trash', 'cancel' ), true ) ) {
			return new WP_Error( 'invalid_action', __( 'Invalid booking action.', 'vanjorn-rental-pos' ) );
		}

		$order = wc_get_order( $primary_order_id );
		if ( ! $order ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'vanjorn-rental-pos' ) );
		}

		if ( ! self::is_primary_rental_order( $order ) ) {
			return new WP_Error( 'not_primary_rental', __( 'This order is not a primary rental booking order.', 'vanjorn-rental-pos' ) );
		}

		if ( $include_children ) {
			$result = self::apply_action_to_linked_payment_orders( $primary_order_id, $action );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( class_exists( 'VanPOS_Kestrel_Reservation_Helper' ) ) {
			VanPOS_Kestrel_Reservation_Helper::delete_rows_for_order( $primary_order_id );
		}

		if ( 'trash' === $action ) {
			return self::trash_shop_order_safe( $order );
		}

		return self::cancel_shop_order_safe( $order );
	}

	/**
	 * Apply trash or cancel to VanPOS-linked payment child orders.
	 *
	 * @param int    $primary_order_id Primary order ID.
	 * @param string $action           trash|cancel.
	 * @return true|WP_Error
	 */
	private static function apply_action_to_linked_payment_orders( $primary_order_id, $action ) {
		if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
			return true;
		}

		foreach ( VanPOS_Order_Manager::get_payment_orders( $primary_order_id ) as $child_order ) {
			if ( ! is_a( $child_order, 'WC_Order' ) ) {
				continue;
			}
			$child_id = (int) $child_order->get_id();
			if ( $child_id === $primary_order_id ) {
				continue;
			}
			$is_linked_payment = ( 'payment_order' === $child_order->get_meta( '_vanpos_order_type' ) )
				|| (int) $child_order->get_meta( '_vanpos_primary_order_id' ) === $primary_order_id;
			if ( ! $is_linked_payment ) {
				continue;
			}

			if ( 'trash' === $action ) {
				if ( 'trash' === $child_order->get_status() ) {
					continue;
				}
				$result = self::trash_shop_order_safe( $child_order );
			} else {
				if ( in_array( $child_order->get_status(), array( 'cancelled', 'trash' ), true ) ) {
					continue;
				}
				$result = self::cancel_shop_order_safe( $child_order );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Set order status to cancelled (WC admin "Cancelled").
	 *
	 * @param WC_Order $order Order object.
	 * @return true|WP_Error
	 */
	private static function cancel_shop_order_safe( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return new WP_Error( 'bad_order', __( 'Invalid order object.', 'vanjorn-rental-pos' ) );
		}

		if ( $order instanceof WC_Order_Refund ) {
			return new WP_Error( 'refund', __( 'Refunds cannot be handled here.', 'vanjorn-rental-pos' ) );
		}

		if ( in_array( $order->get_status(), array( 'cancelled', 'trash' ), true ) ) {
			return true;
		}

		try {
			$note = __( 'Booking cancelled via VAN-Jorn Rental POS dashboard.', 'vanjorn-rental-pos' );
			$order->update_status( 'cancelled', $note, true );

			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh ) {
				return true;
			}
			if ( 'cancelled' !== $fresh->get_status() ) {
				return new WP_Error( 'cancel_failed', __( 'Could not set order status to Cancelled.', 'vanjorn-rental-pos' ) );
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'cancel_exception', $e->getMessage() );
		}
	}

	/**
	 * Move a WC order to trash using the API WooCommerce expects for both HPOS and legacy.
	 *
	 * @param WC_Order $order Order object.
	 * @return true|WP_Error
	 */
	private static function trash_shop_order_safe( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return new WP_Error( 'bad_order', __( 'Invalid order object.', 'vanjorn-rental-pos' ) );
		}

		if ( $order instanceof WC_Order_Refund ) {
			return new WP_Error( 'refund', __( 'Refunds cannot be handled here.', 'vanjorn-rental-pos' ) );
		}

		if ( 'trash' === $order->get_status() ) {
			return true;
		}

		try {
			$id = $order->get_id();
			if ( function_exists( 'wc_delete_order' ) ) {
				wc_delete_order( $id, false );
			} else {
				$order->delete( false );
			}

			$fresh = wc_get_order( $id );
			if ( ! $fresh ) {
				return true;
			}
			if ( 'trash' !== $fresh->get_status() ) {
				return new WP_Error( 'trash_failed', __( 'Could not move order to trash.', 'vanjorn-rental-pos' ) );
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'trash_exception', $e->getMessage() );
		}
	}
}
