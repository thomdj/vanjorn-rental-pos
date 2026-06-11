<?php
/**
 * Kestrel rental calendar table helpers (shared cleanup).
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes rows from the Kestrel / WCRP rentals table for a WooCommerce order ID.
 */
class VanPOS_Kestrel_Reservation_Helper {

	/**
	 * CMIT CODE - UPDATED - 05 MAY 2026
	 * Delete all reservation calendar rows tied to this order (frees van dates).
	 *
	 * @param int $order_id WC order ID.
	 * @return void
	 */
	public static function delete_rows_for_order( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wcrp_rental_products_rentals';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$wpdb->delete( $table, array( 'order_id' => $order_id ) );
	}
}
