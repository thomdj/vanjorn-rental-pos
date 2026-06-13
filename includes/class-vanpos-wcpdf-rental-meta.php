<?php
/**
 * VanPOS WCPDF rental meta rendering.
 *
 * Migrated from child theme. Class renamed VanJorn_WCPDF_Rental_Meta →
 * VanPOS_WCPDF_Rental_Meta for consistency with plugin naming conventions.
 * Initialisation is now handled by the plugin bootstrap.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PDF meta adapter for VanPOS rental data.
 */
final class VanPOS_WCPDF_Rental_Meta {

	/**
	 * Order IDs for which order-level remaining was already printed (fallback when line meta absent).
	 *
	 * @var array<int, bool>
	 */
	private static $order_remaining_fallback_printed = array();

	/**
	 * Register hooks after WCPDF item-meta defaults.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wpo_wcpdf_hidden_order_itemmeta', array( __CLASS__, 'hide_raw_item_meta_keys' ), 20, 1 );
		add_filter( 'wpo_wcpdf_order_item_data', array( __CLASS__, 'inject_clean_rental_summary' ), 20, 3 );
	}

	/**
	 * Localized long date for PDF (uses site date format / locale).
	 *
	 * @param string $raw_date Raw Y-m-d or parseable date.
	 * @return string
	 */
	private static function format_pdf_date( $raw_date ) {
		$ts = strtotime( (string) $raw_date );
		if ( ! $ts ) {
			return (string) $raw_date;
		}
		$format = get_option( 'date_format' );
		if ( ! is_string( $format ) || '' === $format ) {
			$format = 'j F Y';
		}
		return wp_date( $format, $ts );
	}

	/**
	 * Human-readable rental days (e.g. "11 days").
	 *
	 * @param string $raw Raw stored value.
	 * @return string
	 */
	private static function format_rental_days_display( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '/^\d+$/', $raw ) ) {
			$n = (int) $raw;
			return sprintf(
				/* translators: %d: number of rental days */
				_n( '%d day', '%d days', $n, 'vanjorn-rental-pos' ),
				$n
			);
		}
		return $raw;
	}

	/**
	 * Hide raw VanPOS and WCRP keys from default item meta output.
	 *
	 * @param array $keys Hidden keys.
	 * @return array
	 */
	public static function hide_raw_item_meta_keys( $keys ) {
		$extra = array(
			'_vanpos_pickup_date',
			'_vanpos_return_date',
			'_vanpos_pickup_time',
			'_vanpos_return_time',
			'_vanpos_rental_days',
			'_vanpos_remaining_amount',
			'wcrp_rental_products_rent_from',
			'wcrp_rental_products_rent_to',
			'wcrp_rental_products_return_days_threshold',
		);
		return array_values( array_unique( array_merge( (array) $keys, $extra ) ) );
	}

	/**
	 * Remaining amount HTML for this line (item meta, then order fallback).
	 *
	 * @param WC_Order_Item_Product $item      Line item.
	 * @param WC_Order              $order     Order.
	 * @param bool                  $has_rental Whether this line has rental pickup meta.
	 * @return string Empty or formatted price text (no HTML tags).
	 */
	private static function get_remaining_amount_display( $item, $order, $has_rental ) {
		$line = $item->get_meta( '_vanpos_remaining_amount' );
		if ( $line !== '' && $line !== false && is_numeric( $line ) && (float) $line > 0 ) {
			return wp_strip_all_tags( wc_price( (float) $line ) );
		}
		if ( ! $has_rental ) {
			return '';
		}
		$oid = $order->get_id();
		if ( isset( self::$order_remaining_fallback_printed[ $oid ] ) ) {
			return '';
		}
		$formatted = $order->get_meta( '_vanpos_remaining_payment_formatted' );
		$out       = '';
		if ( is_string( $formatted ) && '' !== $formatted ) {
			$out = wp_strip_all_tags( $formatted );
		} else {
			$ord = $order->get_meta( '_vanpos_remaining_payment' );
			if ( $ord !== '' && $ord !== false && is_numeric( $ord ) && (float) $ord > 0 ) {
				$out = wp_strip_all_tags( wc_price( (float) $ord ) );
			}
		}
		if ( '' !== $out ) {
			self::$order_remaining_fallback_printed[ $oid ] = true;
		}
		return $out;
	}

	/**
	 * Build unified SKU + rental table and append to item meta HTML.
	 *
	 * @param array    $data          Item data from WCPDF.
	 * @param WC_Order $order         Order.
	 * @param string   $document_type Document slug.
	 * @return array
	 */
	public static function inject_clean_rental_summary( $data, $order, $document_type ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$item = isset( $data['item'] ) ? $data['item'] : null;
		if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return $data;
		}

		$sku = isset( $data['sku'] ) ? (string) $data['sku'] : '';

		// Line items store the canonical rental keys WITHOUT the underscore prefix
		// (vanpos_pickup_date, not _vanpos_pickup_date). Read those first, then the
		// stray underscore copy, then the WCRP keys. The previous underscore-only item
		// reads always returned '' for times/days, so the PDF dropped them whenever the
		// wcrp date fallback made $has_rental true (skipping the order-level fallback).
		$pickup_date = (string) $item->get_meta( 'vanpos_pickup_date' );
		if ( '' === $pickup_date ) {
			$pickup_date = (string) $item->get_meta( '_vanpos_pickup_date' );
		}
		if ( '' === $pickup_date ) {
			$pickup_date = (string) $item->get_meta( 'wcrp_rental_products_rent_from' );
		}
		$return_date = (string) $item->get_meta( 'vanpos_return_date' );
		if ( '' === $return_date ) {
			$return_date = (string) $item->get_meta( '_vanpos_return_date' );
		}
		if ( '' === $return_date ) {
			$return_date = (string) $item->get_meta( 'wcrp_rental_products_rent_to' );
		}
		$pickup_time = (string) $item->get_meta( 'vanpos_pickup_time' );
		if ( '' === $pickup_time ) {
			$pickup_time = (string) $item->get_meta( '_vanpos_pickup_time' );
		}
		$return_time = (string) $item->get_meta( 'vanpos_return_time' );
		if ( '' === $return_time ) {
			$return_time = (string) $item->get_meta( '_vanpos_return_time' );
		}
		$rental_days = (string) $item->get_meta( 'vanpos_rental_days' );
		if ( '' === $rental_days ) {
			$rental_days = (string) $item->get_meta( '_vanpos_rental_days' );
		}

		$has_rental = ( '' !== $pickup_date || '' !== $return_date || '' !== $pickup_time || '' !== $return_time || '' !== $rental_days );
		if ( ! $has_rental ) {
			$pickup_date = (string) $order->get_meta( '_vanpos_pickup_date' );
			$return_date = (string) $order->get_meta( '_vanpos_return_date' );
			$pickup_time = (string) $order->get_meta( '_vanpos_pickup_time' );
			$return_time = (string) $order->get_meta( '_vanpos_return_time' );
			$rental_days = (string) $order->get_meta( '_vanpos_rental_days' );
			$has_rental  = ( '' !== $pickup_date || '' !== $return_date || '' !== $pickup_time || '' !== $return_time || '' !== $rental_days );
		}

		if ( ! $has_rental && '' === $sku ) {
			return $data;
		}

		$vanpos_settings = get_option( 'vanpos_settings', array() );
		if ( ! is_array( $vanpos_settings ) ) {
			$vanpos_settings = array();
		}
		if ( 'morning' === strtolower( $pickup_time ) || 'afternoon' === strtolower( $pickup_time ) ) {
			$pickup_time = isset( $vanpos_settings['vanpos_pickup_time'] ) ? (string) $vanpos_settings['vanpos_pickup_time'] : $pickup_time;
		}
		if ( 'morning' === strtolower( $return_time ) || 'afternoon' === strtolower( $return_time ) ) {
			$return_time = isset( $vanpos_settings['vanpos_return_time'] ) ? (string) $vanpos_settings['vanpos_return_time'] : $return_time;
		}

		$rows = array();

		if ( '' !== $sku ) {
			$rows[] = array(
				'label' => __( 'SKU', 'vanjorn-rental-pos' ),
				'value' => $sku,
			);
		}

		if ( '' !== $pickup_date ) {
			$pickup_display = (string) $order->get_meta( '_vanpos_pickup_date_formatted' );
			if ( '' === $pickup_display ) {
				$pickup_display = self::format_pdf_date( $pickup_date );
			}
			$rows[] = array(
				'label' => __( 'Pickup date', 'vanjorn-rental-pos' ),
				'value' => $pickup_display,
			);
		}
		if ( '' !== $return_date ) {
			$return_display = (string) $order->get_meta( '_vanpos_return_date_formatted' );
			if ( '' === $return_display ) {
				$return_display = self::format_pdf_date( $return_date );
			}
			$rows[] = array(
				'label' => __( 'Return date', 'vanjorn-rental-pos' ),
				'value' => $return_display,
			);
		}
		if ( '' !== $pickup_time ) {
			$rows[] = array(
				'label' => __( 'Pickup time', 'vanjorn-rental-pos' ),
				'value' => $pickup_time,
			);
		}
		if ( '' !== $return_time ) {
			$rows[] = array(
				'label' => __( 'Return time', 'vanjorn-rental-pos' ),
				'value' => $return_time,
			);
		}
		if ( '' !== $rental_days ) {
			$rows[] = array(
				'label' => __( 'Rental days', 'vanjorn-rental-pos' ),
				'value' => self::format_rental_days_display( $rental_days ),
			);
		}

		$remaining = self::get_remaining_amount_display( $item, $order, $has_rental );
		if ( '' !== $remaining ) {
			$rows[] = array(
				'label' => __( 'Remaining amount', 'vanjorn-rental-pos' ),
				'value' => $remaining,
			);
		}

		if ( empty( $rows ) ) {
			return $data;
		}

		$inner = '';
		foreach ( $rows as $row ) {
			$inner .= '<tr><th>' . esc_html( $row['label'] ) . '</th><td>' . esc_html( $row['value'] ) . '</td></tr>';
		}

		$html = '<table class="vanjorn-wcpdf-rental-meta">' . $inner . '</table>';

		if ( isset( $data['meta'] ) && is_string( $data['meta'] ) ) {
			$data['meta'] .= $html;
		} elseif ( isset( $data['item_meta'] ) && is_string( $data['item_meta'] ) ) {
			$data['item_meta'] .= $html;
		} else {
			$data['meta'] = $html;
		}

		return $data;
	}
}
