<?php
/**
 * Cart Validation Fix for VAN-Jorn Rental POS
 *
 * Prevents the "product recently updated" error from blocking checkout
 * for rental products. Migrated from child theme.
 *
 * 1. PREVENT: Sync Kestrel cart-item timestamps before Kestrel checks them.
 * 2. CATCH: Strip any "product updated" notices that still slip through.
 * 3. FRIENDLIER FALLBACK: Replace blocking errors with a helpful notice.
 * 4. TIMESTAMP SEEDING: Ensure Kestrel timestamp is set on add-to-cart.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart Validation Class
 */
class VanPOS_Cart_Validation {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		// 1. PREVENT — update timestamps before Kestrel checks them.
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'sync_rental_cart_timestamps' ), -10 );

		// 2. CATCH — strip any notices that still slip through.
		add_action( 'wp_loaded', array( __CLASS__, 'strip_product_updated_notices' ), 99 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'strip_product_updated_notices' ), 15 );
		add_action( 'woocommerce_before_checkout_process', array( __CLASS__, 'strip_product_updated_notices' ), 5 );

		// 3. FRIENDLIER FALLBACK — replace blocking errors with a helpful notice.
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'friendlier_product_updated_message' ), 999998 );

		// 4. TIMESTAMP SEEDING.
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'seed_rental_cart_timestamp' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'restore_rental_cart_timestamp' ), 20, 2 );
	}

	/**
	 * Sync Kestrel cart-item timestamps to the product's current modified date
	 * for ALL rental items — regardless of how old the gap is.
	 *
	 * Runs before Kestrel's check_rental_cart_items (priority 0), so by the
	 * time Kestrel compares timestamps, they always match.
	 */
	public static function sync_rental_cart_timestamps() {
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}

		$session_dirty = false;

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$is_rental = isset( $cart_item['wcrp_rental_products_rent_from'] )
			          || isset( $cart_item['vanpos_pickup_date'] );

			if ( ! $is_rental || ! isset( $cart_item['product_id'] ) ) {
				continue;
			}

			$product_id       = $cart_item['product_id'];
			$product_modified = (int) get_the_modified_date( 'U', $product_id );
			$cart_timestamp   = isset( $cart_item['wcrp_rental_products_cart_item_timestamp'] )
				? (int) $cart_item['wcrp_rental_products_cart_item_timestamp']
				: 0;

			if ( $product_modified > $cart_timestamp ) {
				$cart->cart_contents[ $cart_item_key ]['wcrp_rental_products_cart_item_timestamp'] = (string) $product_modified;
				$session_dirty = true;
			}
		}

		if ( $session_dirty && WC()->session ) {
			$cart_session = WC()->session->get( 'cart', array() );
			foreach ( $cart->get_cart() as $key => $item ) {
				if ( isset( $cart_session[ $key ] ) && isset( $item['wcrp_rental_products_cart_item_timestamp'] ) ) {
					$cart_session[ $key ]['wcrp_rental_products_cart_item_timestamp'] = $item['wcrp_rental_products_cart_item_timestamp'];
				}
			}
			WC()->session->set( 'cart', $cart_session );
		}
	}

	/**
	 * Remove "product updated" error notices for rental products.
	 *
	 * Uses language-agnostic keyword matching: checks for known fragments
	 * of the Kestrel notice in NL, EN, DE, and FR — combined with the
	 * product name appearing in the same notice.
	 */
	public static function strip_product_updated_notices() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! did_action( 'woocommerce_cart_loaded_from_session' ) ) {
			return;
		}

		$error_notices = wc_get_notices( 'error' );
		if ( empty( $error_notices ) ) {
			return;
		}

		$rental_product_names = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$is_rental = isset( $item['wcrp_rental_products_rent_from'] )
			          || isset( $item['vanpos_pickup_date'] );
			if ( ! $is_rental ) {
				continue;
			}
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( $product ) {
				$rental_product_names[] = $product->get_name();
			}
		}

		if ( empty( $rental_product_names ) ) {
			return;
		}

		$updated_keywords = array(
			'bijgewerkt',
			'has recently been updated',
			'recently updated',
			'aktualisiert',
			'mis à jour',
			'pricing/availability',
			'prijs/beschikbaarheid',
		);

		$keep    = array();
		$removed = false;

		foreach ( $error_notices as $notice ) {
			$msg  = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : (string) $notice;
			$text = wp_strip_all_tags( $msg );

			$is_rental_updated_notice = false;

			foreach ( $updated_keywords as $keyword ) {
				if ( false !== stripos( $text, $keyword ) ) {
					foreach ( $rental_product_names as $name ) {
						if ( false !== stripos( $text, $name ) ) {
							$is_rental_updated_notice = true;
							break 2;
						}
					}
				}
			}

			if ( $is_rental_updated_notice ) {
				$removed = true;
			} else {
				$keep[] = $notice;
			}
		}

		if ( ! $removed ) {
			return;
		}

		$success = wc_get_notices( 'success' );
		$info    = wc_get_notices( 'notice' );

		wc_clear_notices();

		foreach ( $success as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'success' );
		}
		foreach ( $info as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'notice' );
		}
		foreach ( $keep as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'error' );
		}
	}

	/**
	 * If a "product updated" error survived all previous filters, replace it
	 * with a user-friendly notice + one-time auto-fix.
	 */
	public static function friendlier_product_updated_message() {
		$error_notices = wc_get_notices( 'error' );
		if ( empty( $error_notices ) ) {
			return;
		}

		$updated_keywords = array(
			'bijgewerkt',
			'has recently been updated',
			'recently updated',
			'aktualisiert',
			'mis à jour',
		);

		$has_product_updated_error = false;
		$keep                      = array();

		foreach ( $error_notices as $notice ) {
			$msg  = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : (string) $notice;
			$text = wp_strip_all_tags( $msg );

			$is_updated = false;
			foreach ( $updated_keywords as $keyword ) {
				if ( false !== stripos( $text, $keyword ) ) {
					$is_updated = true;
					break;
				}
			}

			if ( $is_updated ) {
				$has_product_updated_error = true;
			} else {
				$keep[] = $notice;
			}
		}

		if ( ! $has_product_updated_error ) {
			return;
		}

		// Auto-fix: bump all Kestrel timestamps so a page refresh clears it.
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $key => $item ) {
				if ( isset( $item['product_id'] ) ) {
					$mod = (int) get_the_modified_date( 'U', $item['product_id'] );
					WC()->cart->cart_contents[ $key ]['wcrp_rental_products_cart_item_timestamp'] = (string) $mod;
				}
			}
		}

		$success = wc_get_notices( 'success' );
		$info    = wc_get_notices( 'notice' );

		wc_clear_notices();

		foreach ( $success as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'success' );
		}
		foreach ( $info as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'notice' );
		}
		foreach ( $keep as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'error' );
		}

		wc_add_notice(
			__( 'The details of a product in your cart have been updated. Please try again — it should work now.', 'vanjorn-rental-pos' ),
			'notice'
		);
	}

	/**
	 * Set Kestrel's cart-item timestamp when a rental product is added to cart.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @return array
	 */
	public static function seed_rental_cart_timestamp( $cart_item_data, $product_id ) {
		$is_rental = false;

		if ( function_exists( 'wcrp_rental_products_is_rental_only' ) ) {
			$is_rental = wcrp_rental_products_is_rental_only( $product_id );
		}
		if ( ! $is_rental && function_exists( 'wcrp_rental_products_is_rental_purchase' ) ) {
			$is_rental = wcrp_rental_products_is_rental_purchase( $product_id )
			          && ( isset( $_GET['rent'] ) && '1' === $_GET['rent'] );
		}
		if ( ! $is_rental && ( isset( $_REQUEST['pickup_date'] ) || isset( $_REQUEST['data']['pickup_date'] ) ) ) {
			$is_rental = true;
		}

		if ( $is_rental ) {
			$cart_item_data['wcrp_rental_products_cart_item_timestamp'] = (string) time();
			$cart_item_data['vanjorn_cart_item_timestamp']              = time();
		}

		return $cart_item_data;
	}

	/**
	 * When restoring a cart item from the session, ensure the timestamp is current.
	 *
	 * @param array $cart_item Cart item.
	 * @param array $values    Session values.
	 * @return array
	 */
	public static function restore_rental_cart_timestamp( $cart_item, $values ) {
		if ( ! isset( $cart_item['product_id'] ) ) {
			return $cart_item;
		}

		$is_rental = isset( $values['wcrp_rental_products_rent_from'] )
		          || isset( $values['vanpos_pickup_date'] );

		if ( ! $is_rental ) {
			return $cart_item;
		}

		$product_modified = (int) get_the_modified_date( 'U', $cart_item['product_id'] );
		$cart_timestamp   = isset( $cart_item['wcrp_rental_products_cart_item_timestamp'] )
			? (int) $cart_item['wcrp_rental_products_cart_item_timestamp']
			: 0;

		if ( $product_modified > $cart_timestamp ) {
			$cart_item['wcrp_rental_products_cart_item_timestamp'] = (string) $product_modified;
		}

		return $cart_item;
	}
}
