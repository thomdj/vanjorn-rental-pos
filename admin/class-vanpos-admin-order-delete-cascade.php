<?php
/**
 * AJAX: trash primary rental + optional linked payment orders (dashboard UI — WC order screens stay default).
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Admin_Order_Delete_Cascade {

	/**
	 * CMIT CODE - UPDATED - 06 MAY 2026
	 */
	public function __construct() {
		add_action( 'wp_ajax_vanpos_trash_primary_rental_group', array( $this, 'ajax_trash_primary_rental_group' ) );
		add_action( 'wp_ajax_vanpos_cancel_primary_rental_group', array( $this, 'ajax_cancel_primary_rental_group' ) );
	}

	/**
	 * CMIT CODE - UPDATED - 06 MAY 2026
	 *
	 * @param int $primary_order_id Primary WC order ID.
	 * @return array<int, array<string,string>>
	 */
	public static function collect_child_summaries_for_order_id( $primary_order_id ) {
		$order = wc_get_order( absint( $primary_order_id ) );
		if ( ! $order ) {
			return array();
		}
		return self::collect_child_summaries_for_order( $order );
	}

	/**
	 * Payment child orders for popup list (dashboard + future use).
	 *
	 * @param WC_Order $order Primary rental order.
	 * @return array<int, array<string,string>>
	 */
	private static function collect_child_summaries_for_order( $order ) {
		$list = array();
		if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
			return $list;
		}
		foreach ( VanPOS_Order_Manager::get_payment_orders( $order->get_id() ) as $child ) {
			if ( ! is_a( $child, 'WC_Order' ) || $child instanceof WC_Order_Refund ) {
				continue;
			}
			if ( (int) $child->get_id() === (int) $order->get_id() ) {
				continue;
			}

			$status_slug = $child->get_status();
			// CMIT CODE - UPDATED - 06 MAY 2026 — Same localized label as dashboard table / WC admin (respects WPML admin locale).
			$status_label = function_exists( 'wc_get_order_status_name' )
				? wc_get_order_status_name( $status_slug )
				: $status_slug;

			$list[] = array(
				'id'            => (string) $child->get_id(),
				'number'        => $child->get_order_number(),
				'status'        => $status_slug,
				'status_label' => $status_label,
			);
		}

		return $list;
	}

	/**
	 * CMIT CODE - UPDATED - 06 MAY 2026
	 *
	 * @return void
	 */
	public function ajax_trash_primary_rental_group() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'vanpos_trash_primary_rental_group' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vanjorn-rental-pos' ) ), 403 );
		}

		if ( ! current_user_can( 'delete_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ), 403 );
		}

		if ( ! class_exists( 'VanPOS_Order_Deletion' ) ) {
			wp_send_json_error( array( 'message' => __( 'Delete handler not loaded.', 'vanjorn-rental-pos' ) ), 500 );
		}

		$order_id        = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$delete_children = isset( $_POST['delete_children'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['delete_children'] ) );

		$pre_order = wc_get_order( $order_id );
		$ord_num   = $pre_order ? $pre_order->get_order_number() : (string) $order_id;

		$result = VanPOS_Order_Deletion::trash_primary_rental_group( $order_id, $delete_children );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$context = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : '';

		if ( 'dashboard' === $context ) {
			if ( $delete_children ) {
				$message = sprintf(
					/* translators: %s: order number */
					__( 'Success: booking order #%s and linked payment orders were moved to Trash. Reservation calendar rows were cleared.', 'vanjorn-rental-pos' ),
					$ord_num
				);
			} else {
				$message = sprintf(
					/* translators: %s: order number */
					__( 'Success: booking order #%s was moved to Trash. Linked payment orders were not changed. Reservation calendar rows were cleared.', 'vanjorn-rental-pos' ),
					$ord_num
				);
			}
			wp_send_json_success(
				array(
					'message'            => $message,
					'reload_dashboard'   => true,
				)
			);
		}

		$legacy = isset( $_POST['legacy_list'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['legacy_list'] ) );

		if ( $delete_children ) {
			$msg = sprintf(
				/* translators: %s: order number */
				__( 'Order #%s and linked payment orders were moved to Trash.', 'vanjorn-rental-pos' ),
				$ord_num
			);
		} else {
			$msg = sprintf(
				/* translators: %s: order number */
				__( 'Order #%s was moved to Trash (linked orders unchanged).', 'vanjorn-rental-pos' ),
				$ord_num
			);
		}

		wp_send_json_success(
			array(
				'message'     => $msg,
				'redirect_to' => $legacy ? admin_url( 'edit.php?post_type=shop_order' ) : admin_url( 'admin.php?page=wc-orders' ),
			)
		);
	}

	/**
	 * AJAX: cancel primary rental + optional linked payment orders (dashboard).
	 *
	 * @return void
	 */
	public function ajax_cancel_primary_rental_group() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'vanpos_cancel_primary_rental_group' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'vanjorn-rental-pos' ) ), 403 );
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ), 403 );
		}

		if ( ! class_exists( 'VanPOS_Order_Deletion' ) ) {
			wp_send_json_error( array( 'message' => __( 'Cancel handler not loaded.', 'vanjorn-rental-pos' ) ), 500 );
		}

		$order_id        = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$cancel_children = isset( $_POST['cancel_children'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['cancel_children'] ) );

		$pre_order = wc_get_order( $order_id );
		$ord_num   = $pre_order ? $pre_order->get_order_number() : (string) $order_id;

		$result = VanPOS_Order_Deletion::cancel_primary_rental_group( $order_id, $cancel_children );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$context = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : '';

		if ( 'dashboard' === $context ) {
			if ( $cancel_children ) {
				$message = sprintf(
					/* translators: %s: order number */
					__( 'Success: booking order #%s and linked payment orders were set to Cancelled. Reservation calendar rows were cleared.', 'vanjorn-rental-pos' ),
					$ord_num
				);
			} else {
				$message = sprintf(
					/* translators: %s: order number */
					__( 'Success: booking order #%s was set to Cancelled. Linked payment orders were not changed. Reservation calendar rows were cleared.', 'vanjorn-rental-pos' ),
					$ord_num
				);
			}
			wp_send_json_success(
				array(
					'message'          => $message,
					'reload_dashboard' => true,
				)
			);
		}

		if ( $cancel_children ) {
			$msg = sprintf(
				/* translators: %s: order number */
				__( 'Order #%s and linked payment orders were set to Cancelled.', 'vanjorn-rental-pos' ),
				$ord_num
			);
		} else {
			$msg = sprintf(
				/* translators: %s: order number */
				__( 'Order #%s was set to Cancelled (linked orders unchanged).', 'vanjorn-rental-pos' ),
				$ord_num
			);
		}

		wp_send_json_success( array( 'message' => $msg ) );
	}
}
