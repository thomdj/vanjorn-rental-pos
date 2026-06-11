<?php
/**
 * VanPOS admin AJAX handlers for order operations.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Admin_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_vanpos_create_child_order', array( $this, 'ajax_create_child_order' ) );
		add_action( 'wp_ajax_vanpos_create_security_deposit_order', array( $this, 'ajax_create_security_deposit_order' ) );
		add_action( 'wp_ajax_vanpos_update_rental_metadata', array( $this, 'ajax_update_rental_metadata' ) );
	}

	public function ajax_create_child_order() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'vanpos_create_child_order' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vanjorn-rental-pos' ) ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'vanjorn-rental-pos' ) ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'vanjorn-rental-pos' ) ) );
		}
		if ( ! $this->is_primary_rental_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a primary rental order.', 'vanjorn-rental-pos' ) ) );
		}
		$payment_type = isset( $_POST['payment_type'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_type'] ) ) : 'remaining';
		if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
			wp_send_json_error( array( 'message' => __( 'Order Manager class not found. Please ensure the plugin is properly installed.', 'vanjorn-rental-pos' ) ) );
		}
		if ( VanPOS_Order_Manager::has_payment_order( $order_id, $payment_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Payment order of this type already exists.', 'vanjorn-rental-pos' ) ) );
		}

		$amount = 0;
		$description = '';
		if ( 'remaining' === $payment_type ) {
			foreach ( $order->get_items() as $item ) {
				$item_remaining = (float) $item->get_meta( '_vanpos_remaining_amount' );
				if ( $item_remaining > 0 ) {
					$amount += $item_remaining;
				}
			}
			if ( $amount <= 0 ) {
				$amount = (float) $order->get_meta( '_vanpos_remaining_payment' );
			}
			if ( $amount <= 0 ) {
				wp_send_json_error( array( 'message' => __( 'No remaining amount to collect.', 'vanjorn-rental-pos' ) ) );
			}
			$description = __( 'Remaining Rental Payment (50%)', 'vanjorn-rental-pos' );
		} else {
			// Security deposits are created via the dedicated
			// vanpos_create_security_deposit_order endpoint, not here.
			wp_send_json_error( array( 'message' => __( 'Invalid payment type.', 'vanjorn-rental-pos' ) ) );
		}

		$child_order_id = VanPOS_Order_Manager::create_payment_order( $order_id, $payment_type, $amount, $description );
		if ( is_wp_error( $child_order_id ) ) {
			wp_send_json_error( array( 'message' => $child_order_id->get_error_message() ) );
		}
		$child_order = wc_get_order( $child_order_id );
		if ( ! $child_order ) {
			wp_send_json_error( array( 'message' => __( 'Child order created but could not be retrieved.', 'vanjorn-rental-pos' ) ) );
		}

		if ( 'remaining' === $payment_type ) {
			$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
			$due_date = null;
			if ( $pickup_date && class_exists( 'VanPOS_Functions' ) ) {
				$due_date = VanPOS_Functions::calculate_due_date( $pickup_date );
			}
			if ( $due_date ) {
				$child_order->update_meta_data( '_vanpos_due_date', $due_date );
			}
			$parent_due_date = $order->get_meta( '_vanpos_due_date' );
			if ( ! $parent_due_date && $due_date ) {
				$order->update_meta_data( '_vanpos_due_date', $due_date );
				$order->save();
			}
		}

		$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
		$return_date = $order->get_meta( '_vanpos_return_date' );
		$booking_ref = $order->get_meta( '_vanpos_booking_reference' );
		if ( $pickup_date ) { $child_order->update_meta_data( '_vanpos_pickup_date', $pickup_date ); }
		if ( $return_date ) { $child_order->update_meta_data( '_vanpos_return_date', $return_date ); }
		if ( $booking_ref ) { $child_order->update_meta_data( '_vanpos_booking_reference', $booking_ref ); }
		$email_meta_keys = array( '_vanpos_camper_name', '_vanpos_pickup_date_formatted', '_vanpos_return_date_formatted', '_vanpos_total_price', '_vanpos_total_price_formatted', '_vanpos_initial_payment', '_vanpos_initial_payment_formatted', '_vanpos_remaining_payment', '_vanpos_remaining_payment_formatted' );
		foreach ( $email_meta_keys as $meta_key ) {
			$value = $order->get_meta( $meta_key );
			if ( $value !== '' && $value !== false ) {
				$child_order->update_meta_data( $meta_key, $value );
			}
		}
		$child_order->set_status( 'pending' );
		$child_order->save();

		wp_send_json_success(
			array(
				'message'      => sprintf( __( 'Child order #%s created successfully.', 'vanjorn-rental-pos' ), $child_order->get_order_number() ),
				'order_id'     => $child_order_id,
				'order_number' => $child_order->get_order_number(),
				'order_url'    => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $child_order_id ),
			)
		);
	}

	public function ajax_create_security_deposit_order() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'vanpos_create_child_order' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vanjorn-rental-pos' ) ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'vanjorn-rental-pos' ) ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'vanjorn-rental-pos' ) ) );
		}
		if ( ! $this->is_primary_rental_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a primary rental order.', 'vanjorn-rental-pos' ) ) );
		}
		if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
			wp_send_json_error( array( 'message' => __( 'Order Manager class not found. Please ensure the plugin is properly installed.', 'vanjorn-rental-pos' ) ) );
		}
		if ( VanPOS_Order_Manager::has_payment_order( $order_id, 'security_deposit' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security deposit order already exists.', 'vanjorn-rental-pos' ) ) );
		}
		$child_order_id = VanPOS_Order_Manager::create_security_deposit_order( $order_id );
		if ( is_wp_error( $child_order_id ) ) {
			wp_send_json_error( array( 'message' => $child_order_id->get_error_message() ) );
		}
		$child_order = wc_get_order( $child_order_id );
		if ( ! $child_order ) {
			wp_send_json_error( array( 'message' => __( 'Security deposit order created but could not be retrieved.', 'vanjorn-rental-pos' ) ) );
		}
		wp_send_json_success( array( 'message' => sprintf( __( 'Security deposit order #%s created successfully.', 'vanjorn-rental-pos' ), $child_order->get_order_number() ), 'order_id' => $child_order_id, 'order_url' => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $child_order_id ) ) );
	}

	public function ajax_update_rental_metadata() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'vanpos_create_child_order' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vanjorn-rental-pos' ) ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'vanjorn-rental-pos' ) ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'vanjorn-rental-pos' ) ) );
		}
		$updated = $this->update_missing_rental_metadata( $order );
		if ( $updated ) {
			wp_send_json_success( array( 'message' => __( 'Rental metadata updated successfully.', 'vanjorn-rental-pos' ) ) );
		}
		wp_send_json_error( array( 'message' => __( 'No metadata was updated. The order may not be a rental order or metadata is already complete.', 'vanjorn-rental-pos' ) ) );
	}

	/**
	 * CMIT CODE - UPDATED - 05 MAY 2026 — use VanPOS_Order_Deletion heuristic.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_primary_rental_order( $order ) {
		return class_exists( 'VanPOS_Order_Deletion' ) && VanPOS_Order_Deletion::is_primary_rental_order( $order );
	}

	/**
	 * Delegate to the shared implementation on VanPOS_Order_Manager.
	 *
	 * @param WC_Order $order Order to backfill.
	 * @return bool
	 */
	private function update_missing_rental_metadata( $order ) {
		return class_exists( 'VanPOS_Order_Manager' )
			? VanPOS_Order_Manager::update_missing_rental_metadata( $order )
			: false;
	}
}

