<?php
/**
 * Core functions for VAN-Jorn Rental POS
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core functions class
 */
class VanPOS_Functions {

	/**
	 * Get all available van types
	 * Collects unique van types from all rental products using ACF van_type field
	 *
	 * @return array
	 */
	public static function get_van_types() {
		// Get all rental products
		$products = self::get_rental_products();
		
		// Collect all unique van types from all products
		$all_van_types = array();
		foreach ( $products as $product ) {
			if ( ! empty( $product['type'] ) ) {
				$van_type = trim( $product['type'] );
				if ( ! empty( $van_type ) && ! in_array( $van_type, $all_van_types, true ) ) {
					$all_van_types[] = $van_type;
				}
			}
		}
		
		// Sort alphabetically
		sort( $all_van_types );
		
		// If no van types found in products, return empty array
		if ( empty( $all_van_types ) ) {
			return array();
		}
		
		return $all_van_types;
	}

	/**
	 * Get all available equipment options
	 * Collects unique equipment from all rental products
	 *
	 * @return array
	 */
	public static function get_equipment_options() {
		// Get all rental products
		$products = self::get_rental_products();
		
		// Collect all unique equipment from all products
		$all_equipment = array();
		$seen          = array();
		$plugin_map    = class_exists( 'VanPOS_Equipment_Labels' ) ? VanPOS_Equipment_Labels::get_map() : array();
		foreach ( $products as $product ) {
			if ( ! empty( $product['equipment'] ) && is_array( $product['equipment'] ) ) {
				foreach ( $product['equipment'] as $equipment_item ) {
					$equipment_item = trim( (string) $equipment_item );
					if ( class_exists( 'VanPOS_Equipment_Labels' ) ) {
						$canonical = VanPOS_Equipment_Labels::find_canonical_key( $equipment_item );
						if ( null !== $canonical && isset( $plugin_map[ $canonical ] ) ) {
							$equipment_item = $plugin_map[ $canonical ];
						}
					}
					$dedupe_key = strtolower( $equipment_item );
					if ( ! empty( $equipment_item ) && ! isset( $seen[ $dedupe_key ] ) ) {
						$seen[ $dedupe_key ] = true;
						$all_equipment[] = $equipment_item;
					}
				}
			}
		}
		
		// Sort alphabetically
		sort( $all_equipment );
		
		// If no equipment found in products, return empty array (or fallback list if needed)
		if ( empty( $all_equipment ) ) {
			// Return empty array - let the frontend handle the "no equipment" message
			return array();
		}
		
		return $all_equipment;
	}

	/**
	 * Get plugin settings
	 *
	 * @return array
	 */
	public static function get_settings() {
		return get_option( 'vanpos_settings', array() );
	}

	/**
	 * Get setting value
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = '' ) {
		$settings = self::get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Update setting
	 *
	 * @param string $key Setting key.
	 * @param mixed  $value Setting value.
	 * @return bool
	 */
	public static function update_setting( $key, $value ) {
		$settings = self::get_settings();
		$settings[ $key ] = $value;
		return update_option( 'vanpos_settings', $settings );
	}

	/**
	 * Get the original (default-language) product ID.
	 *
	 * When WPML is active each translation has its own post ID, but rental
	 * bookings and rental-plugin configuration are stored against the
	 * original (default-language) product only.  All rental operations
	 * (availability checks, add-to-cart, calendar queries) must use the
	 * original ID so data is read from / written to the correct product.
	 *
	 * If WPML is not active the passed ID is returned unchanged.
	 *
	 * @param int $product_id Product ID (possibly a translation).
	 * @return int Original (default-language) product ID.
	 */
	public static function get_original_product_id( $product_id ) {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$default_lang = apply_filters( 'wpml_default_language', null );
			$original_id  = apply_filters( 'wpml_object_id', $product_id, 'product', true, $default_lang );
			if ( $original_id ) {
				return (int) $original_id;
			}
		}
		return (int) $product_id;
	}

	/**
	 * Get all rental products
	 * 
	 * Uses the following ACF fields for filtering:
	 * - van_type: Van Type filter
	 * - number_seating_options: Travelers/Seats filter
	 * - number_sleeping_options: Beds display
	 * - additional_equipment: Equipment filter
	 *
	 * @return array
	 */
	public static function get_rental_products() {
		// Check if WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		// Get security deposit product ID to exclude it
		$security_deposit_product_id = self::get_setting( 'vanpos_security_deposit_product_id', '' );

		$args = array(
			'post_type'        => 'product',
			'posts_per_page'   => -1,
			'post_status'      => 'publish',
			'suppress_filters' => false, // Allow WPML to filter by current language
		);

		$products = get_posts( $args );
		$rental_products = array();

		if ( empty( $products ) ) {
			return $rental_products; // Return empty array if no products
		}

		foreach ( $products as $product_post ) {
			$product = wc_get_product( $product_post->ID );
			if ( ! $product ) {
				continue;
			}

			$product_id = $product->get_id();

			// Exclude security deposit product (compare original IDs for WPML compat)
			if ( ! empty( $security_deposit_product_id ) && self::get_original_product_id( $product_id ) === (int) $security_deposit_product_id ) {
				continue;
			}

			// Exclude virtual products (Security Deposit should be virtual)
			if ( $product->is_virtual() ) {
				continue;
			}

			// Get ACF fields for filtering
			$seats = (int) self::get_acf_field( $product_id, 'number_seating_options', 2 );
			$beds = (int) self::get_acf_field( $product_id, 'number_sleeping_options', 2 );
			$type = self::get_product_type( $product_id ); // Uses van_type ACF field
			$transmission = self::get_product_transmission( $product_id );
			$fuel = self::get_product_fuel( $product_id );
			$equipment = self::get_product_equipment( $product_id ); // Uses additional_equipment ACF field

			// Only include products with price
			$price = (float) $product->get_price();
			if ( $price <= 0 ) {
				continue; // Skip products without price
			}

			// Only include products that have a van_type set (from ACF)
			// Skip products without van_type to ensure filter works correctly
			if ( empty( $type ) ) {
				continue; // Skip products without van_type ACF field
			}

			$vehicle_length = self::get_acf_field( $product_id, 'vehicle_length', '' );

			// Get gallery images from ACF vehicle_gallery field
			$gallery = self::get_product_gallery( $product_id );

			$rental_products[] = array(
				'id'          => $product_id,
				'name'        => $product->get_name(),
				'price'       => $price,
				'type'        => $type, // From ACF van_type field only
				'seats'       => $seats > 0 ? $seats : 2, // Default to 2 if not set
				'beds'        => $beds > 0 ? $beds : 2, // Default to 2 if not set
				'length'      => is_string( $vehicle_length ) ? trim( $vehicle_length ) : '',
				'transmission' => $transmission ? $transmission : __( 'Manual', 'vanjorn-rental-pos' ),
				'fuel'        => $fuel ? $fuel : __( 'Diesel', 'vanjorn-rental-pos' ),
				'equipment'   => is_array( $equipment ) ? $equipment : array(),
				'image'       => get_the_post_thumbnail_url( $product_id, 'large' ) ?: '',
				'gallery'     => $gallery,
				'excerpt'     => $product->get_short_description(),
				'permalink'   => get_permalink( $product_id ),
			);
		}

		return $rental_products;
	}

	/**
	 * Get ACF field value with fallback
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field_name Field name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private static function get_acf_field( $post_id, $field_name, $default = '' ) {
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field_name, $post_id );
			// Handle false, null, empty string, and empty array
			if ( $value === false || $value === null || $value === '' ) {
				return $default;
			}
			// If it's an array and empty, return default
			if ( is_array( $value ) && empty( $value ) ) {
				return $default;
			}
			return $value;
		}
		// Fallback to post meta if ACF not available
		$meta_value = get_post_meta( $post_id, $field_name, true );
		if ( $meta_value !== false && $meta_value !== '' ) {
			return $meta_value;
		}
		return $default;
	}

	/**
	 * Get product type from ACF field
	 * Uses ACF field 'van_type' (meta key) instead of WooCommerce attributes
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public static function get_product_type( $product_id ) {
		// Get from ACF van_type field (meta key)
		// Try both 'van_type' and 'vantype' to handle different naming conventions
		$van_type = self::get_acf_field( $product_id, 'van_type', '' );
		if ( empty( $van_type ) ) {
			$van_type = self::get_acf_field( $product_id, 'vantype', '' );
		}
		
		if ( ! empty( $van_type ) ) {
			// If it's an array (select field with multiple values), get first value
			if ( is_array( $van_type ) ) {
				$van_type = ! empty( $van_type[0] ) ? $van_type[0] : '';
			}
			// If it's an object (ACF field object), get the value
			if ( is_object( $van_type ) && isset( $van_type->value ) ) {
				$van_type = $van_type->value;
			}
			if ( ! empty( $van_type ) ) {
				return trim( $van_type );
			}
		}

		// No fallback - return empty string if ACF field is not set
		// This ensures we only use ACF meta key, not attributes or categories
		return '';
	}

	/**
	 * Get product transmission
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public static function get_product_transmission( $product_id ) {
		$transmission_options = self::get_acf_field( $product_id, 'transmission_options', '' );
		if ( ! empty( $transmission_options ) ) {
			// Radio button returns a string; checkbox returns an array — handle both
			$transmission = is_array( $transmission_options ) ? $transmission_options[0] : $transmission_options;
			return trim( $transmission );
		}
		return '';
	}

	/**
	 * Get product fuel type
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public static function get_product_fuel( $product_id ) {
		$fuel_options = self::get_acf_field( $product_id, 'fuel_options', '' );
		if ( ! empty( $fuel_options ) ) {
			// Radio button returns a string; checkbox returns an array — handle both
			return is_array( $fuel_options ) ? trim( $fuel_options[0] ) : trim( $fuel_options );
		}
		return '';
	}

	/**
	 * Get product gallery images from ACF vehicle_gallery field.
	 * Handles all ACF gallery return formats (image array, ID, URL).
	 * Falls back to WooCommerce product gallery if ACF gallery is empty.
	 *
	 * Returns an array of arrays, each with 'thumb' (medium_large, ~768px)
	 * for the carousel and 'full' (original) for the lightbox.
	 *
	 * @param int $product_id Product ID.
	 * @return array Array of [ 'thumb' => url, 'full' => url ].
	 */
	public static function get_product_gallery( $product_id ) {
		$gallery = self::get_acf_field( $product_id, 'vehicle_gallery', array() );
		$images  = array();

		if ( ! empty( $gallery ) && is_array( $gallery ) ) {
			foreach ( $gallery as $image ) {
				if ( is_array( $image ) && ! empty( $image['id'] ) ) {
					// ACF return format: Image Array — use ID for sized URLs
					$images[] = self::get_gallery_image_urls( (int) $image['id'] );
				} elseif ( is_array( $image ) && ! empty( $image['url'] ) ) {
					// ACF return format: Image Array without ID
					$images[] = array( 'thumb' => $image['url'], 'full' => $image['url'] );
				} elseif ( is_numeric( $image ) ) {
					// ACF return format: Image ID
					$images[] = self::get_gallery_image_urls( (int) $image );
				} elseif ( is_string( $image ) && filter_var( $image, FILTER_VALIDATE_URL ) ) {
					// ACF return format: Image URL (no sized versions available)
					$images[] = array( 'thumb' => $image, 'full' => $image );
				}
			}
		}

		// Fallback to WooCommerce product gallery
		if ( empty( $images ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$gallery_ids = $product->get_gallery_image_ids();
				foreach ( $gallery_ids as $attachment_id ) {
					$images[] = self::get_gallery_image_urls( $attachment_id );
				}
			}
		}

		// Filter out any entries where both URLs are empty
		return array_values( array_filter( $images, function ( $img ) {
			return ! empty( $img['thumb'] ) || ! empty( $img['full'] );
		} ) );
	}

	/**
	 * Get thumb + full URLs for a single attachment ID.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array [ 'thumb' => url, 'full' => url ]
	 */
	private static function get_gallery_image_urls( $attachment_id ) {
		$thumb = wp_get_attachment_image_url( $attachment_id, 'medium_large' );
		$full  = wp_get_attachment_image_url( $attachment_id, 'full' );
		return array(
			'thumb' => $thumb ?: $full ?: '',
			'full'  => $full ?: $thumb ?: '',
		);
	}

	/**
	 * Flatten ACF additional_equipment values to trimmed strings.
	 * ACF may return nested arrays (repeater, group, etc.); trim() only accepts strings.
	 *
	 * @param array $items Raw list from ACF or JSON decode.
	 * @return array Non-empty trimmed string values.
	 */
	private static function flatten_equipment_input( $items ) {
		$out = array();
		foreach ( (array) $items as $item ) {
			if ( is_string( $item ) || is_numeric( $item ) ) {
				$s = trim( (string) $item );
				if ( '' !== $s ) {
					$out[] = $s;
				}
				continue;
			}
			if ( is_array( $item ) ) {
				$parts = array();
				array_walk_recursive(
					$item,
					function ( $v ) use ( &$parts ) {
						if ( is_string( $v ) || is_numeric( $v ) ) {
							$t = trim( (string) $v );
							if ( '' !== $t ) {
								$parts[] = $t;
							}
						}
					}
				);
				$out = array_merge( $out, $parts );
			}
		}
		return $out;
	}

	/**
	 * Get product equipment
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_product_equipment( $product_id ) {
		// Get only from additional_equipment ACF field
		$equipment = self::get_acf_field( $product_id, 'additional_equipment', array() );
		
		if ( ! empty( $equipment ) ) {
			// If it's already an array, return it (filter out empty values)
			if ( is_array( $equipment ) ) {
				$equipment = self::flatten_equipment_input( $equipment );
				return self::normalize_equipment_labels( $equipment, $product_id );
			}
			// If it's a string, try to convert to array
			if ( is_string( $equipment ) ) {
				$equipment = trim( $equipment );
				if ( empty( $equipment ) ) {
					return array();
				}
				// Try JSON decode first
				$decoded = json_decode( $equipment, true );
				if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
					$decoded = self::flatten_equipment_input( $decoded );
					return self::normalize_equipment_labels( $decoded, $product_id );
				}
				// Try comma-separated
				$items = array_map( 'trim', explode( ',', $equipment ) );
				$items = array_filter( $items, function( $item ) {
					return ! empty( $item );
				} );
				return self::normalize_equipment_labels( $items, $product_id );
			}
		}
		
		return array();
	}

	/**
	 * Normalize raw equipment strings (keys or labels in any mapped language) for display.
	 * Uses the same rules as {@see get_product_equipment()}—use elsewhere (templates, APIs)
	 * when you already have raw values and need current-language labels.
	 *
	 * @param array $items      Trimmed strings, e.g. from meta or CSV.
	 * @param int   $product_id Product ID for ACF choice fallback; use 0 for plugin map only.
	 * @return array Unique labels for the active WPML / locale language.
	 */
	public static function normalize_additional_equipment_display( array $items, $product_id = 0 ) {
		return self::normalize_equipment_labels( $items, $product_id );
	}

	/**
	 * Normalize additional_equipment values to labels in the current language.
	 *
	 * ACF checkbox/select fields can store either keys or labels depending on
	 * configuration/history. VanPOS_Equipment_Labels provides nl/en/de labels
	 * for known keys and resolves cross-language label ↔ key. ACF choices still
	 * apply for options not in the plugin map.
	 *
	 * @param array $equipment_items Raw equipment values from ACF/meta.
	 * @param int   $product_id      Product ID in the active language.
	 * @return array Equipment labels for frontend display/filtering.
	 */
	private static function normalize_equipment_labels( $equipment_items, $product_id ) {
		$normalized  = array();
		$acf_choices = array();

		if ( function_exists( 'get_field_object' ) ) {
			$field_object = get_field_object( 'additional_equipment', $product_id, false, false );
			if ( is_array( $field_object ) && ! empty( $field_object['choices'] ) && is_array( $field_object['choices'] ) ) {
				$acf_choices = $field_object['choices'];
			}
		}

		$plugin_map = class_exists( 'VanPOS_Equipment_Labels' ) ? VanPOS_Equipment_Labels::get_map() : array();
		// ACF first, plugin second: canonical multilingual labels override ACF for shared keys.
		$choices = array_merge( $acf_choices, $plugin_map );

		foreach ( (array) $equipment_items as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}

			$label = null;

			if ( isset( $choices[ $item ] ) ) {
				$label = (string) $choices[ $item ];
			} elseif ( class_exists( 'VanPOS_Equipment_Labels' ) ) {
				$canonical = VanPOS_Equipment_Labels::find_canonical_key( $item );
				if ( null !== $canonical && isset( $plugin_map[ $canonical ] ) ) {
					$label = $plugin_map[ $canonical ];
				}
			}

			if ( null === $label && ! empty( $acf_choices ) ) {
				$matched = array_search( $item, $acf_choices, true );
				if ( false !== $matched ) {
					$label = (string) $acf_choices[ $matched ];
				}
			}

			if ( null === $label && ! empty( $choices ) ) {
				$matched = array_search( $item, $choices, true );
				if ( false !== $matched ) {
					$label = (string) $choices[ $matched ];
				}
			}

			if ( null === $label ) {
				$label = $item;
			}

			$label = trim( $label );
			if ( '' !== $label ) {
				$normalized[] = $label;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize pickup/return time meta to a half-day slot.
	 *
	 * CMIT CODE - UPDATED - 15 MAY 2026
	 * VAN-Jorn uses morning return + afternoon pickup. Slots: am | pm.
	 *
	 * @param string $raw     Stored meta (morning, afternoon, 09:00, 15:00, etc.).
	 * @param string $default Default slot when empty: am or pm.
	 * @return string 'am' or 'pm'.
	 */
	private static function normalize_rental_half_day_slot( $raw, $default = 'pm' ) {
		$raw = strtolower( trim( (string) $raw ) );
		if ( in_array( $raw, array( 'am', 'morning' ), true ) ) {
			return 'am';
		}
		if ( in_array( $raw, array( 'pm', 'afternoon' ), true ) ) {
			return 'pm';
		}
		if ( preg_match( '/^0?9[:.]?0?0|^11[:.]?/', $raw ) ) {
			return 'am';
		}
		if ( preg_match( '/^1[5-9][:.]?|^2[0-3][:.]?/', $raw ) ) {
			return 'pm';
		}
		return ( 'am' === $default ) ? 'am' : 'pm';
	}

	/**
	 * Half-day slots occupied by one booking on a calendar date.
	 *
	 * @param string $rent_from    Y-m-d.
	 * @param string $rent_to      Y-m-d.
	 * @param string $pickup_slot  am|pm.
	 * @param string $return_slot  am|pm.
	 * @param string $date_str     Y-m-d.
	 * @return array<int, string>  am and/or pm.
	 */
	private static function rental_booking_slots_on_date( $rent_from, $rent_to, $pickup_slot, $return_slot, $date_str ) {
		if ( $date_str < $rent_from || $date_str > $rent_to ) {
			return array();
		}
		if ( $rent_from === $rent_to ) {
			return array_values( array_unique( array( $pickup_slot, $return_slot ) ) );
		}
		if ( $date_str === $rent_from ) {
			return array( $pickup_slot );
		}
		if ( $date_str === $rent_to ) {
			return array( $return_slot );
		}
		return array( 'am', 'pm' );
	}

	/**
	 * Half-day slots a new VAN-Jorn rental needs on a date (afternoon pickup, morning return).
	 *
	 * @param string $rent_from Y-m-d.
	 * @param string $rent_to   Y-m-d.
	 * @param string $date_str  Y-m-d.
	 * @return array<int, string>
	 */
	private static function rental_request_slots_on_date( $rent_from, $rent_to, $date_str ) {
		return self::rental_booking_slots_on_date( $rent_from, $rent_to, 'pm', 'am', $date_str );
	}

	/**
	 * Count how many active bookings use a given half-day slot on a date.
	 *
	 * @param array<int, object> $bookings     Rows from fetch_active_rental_bookings().
	 * @param string             $date_str     Y-m-d.
	 * @param string             $slot         am|pm.
	 * @return int
	 */
	private static function count_rental_slot_usage( $bookings, $date_str, $slot ) {
		$count = 0;
		foreach ( $bookings as $booking ) {
			$from = isset( $booking->rent_from ) ? (string) $booking->rent_from : '';
			$to   = isset( $booking->rent_to ) ? (string) $booking->rent_to : '';
			if ( '' === $from || '' === $to ) {
				continue;
			}
			$pickup_slot = self::normalize_rental_half_day_slot(
				isset( $booking->pickup_time ) ? $booking->pickup_time : '',
				'pm'
			);
			$return_slot = self::normalize_rental_half_day_slot(
				isset( $booking->return_time ) ? $booking->return_time : '',
				'am'
			);
			$slots = self::rental_booking_slots_on_date( $from, $to, $pickup_slot, $return_slot, $date_str );
			if ( in_array( $slot, $slots, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Whether a half-day slot has capacity for additional quantity.
	 *
	 * @param array<int, object> $bookings       Active bookings.
	 * @param string             $date_str       Y-m-d.
	 * @param string             $slot           am|pm.
	 * @param int                $stock_quantity Units in stock.
	 * @param int                $quantity       Units to reserve.
	 * @return bool
	 */
	private static function rental_slot_has_capacity( $bookings, $date_str, $slot, $stock_quantity, $quantity = 1 ) {
		return ( self::count_rental_slot_usage( $bookings, $date_str, $slot ) + (int) $quantity ) <= (int) $stock_quantity;
	}

	/**
	 * Load active rental bookings for a product within a date window.
	 *
	 * @param int    $product_id   Product ID (already resolved for WPML).
	 * @param string $window_start Y-m-d.
	 * @param string $window_end   Y-m-d.
	 * @return array<int, object>
	 */
	private static function fetch_active_rental_bookings( $product_id, $window_start, $window_end ) {
		global $wpdb;

		// CMIT CODE - UPDATED - 15 MAY 2026 — skip line items Kestrel marked returned/cancelled.
		$exclude_closed_sql = class_exists( 'VanPOS_Rental_Returned' )
			? VanPOS_Rental_Returned::sql_exclude_closed_rental_items()
			: '';

		$active_statuses = array( 'wc-processing', 'wc-on-hold', 'wc-completed' );
		$placeholders    = implode( ', ', array_fill( 0, count( $active_statuses ), '%s' ) );

		$using_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $using_hpos ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			$status_join  = "INNER JOIN {$orders_table} AS o ON oi.order_id = o.id AND o.status IN ({$placeholders})";
		} else {
			$status_join = "INNER JOIN {$wpdb->posts} AS o ON oi.order_id = o.ID AND o.post_status IN ({$placeholders})";
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT rf.meta_value AS rent_from, rt.meta_value AS rent_to,
				COALESCE(pt.meta_value, '') AS pickup_time, COALESCE(rtm.meta_value, '') AS return_time
			FROM {$wpdb->prefix}woocommerce_order_items AS oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pid
				ON oi.order_item_id = pid.order_item_id AND pid.meta_key = '_product_id'
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS rf
				ON oi.order_item_id = rf.order_item_id AND rf.meta_key = 'wcrp_rental_products_rent_from'
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS rt
				ON oi.order_item_id = rt.order_item_id AND rt.meta_key = 'wcrp_rental_products_rent_to'
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pt
				ON oi.order_item_id = pt.order_item_id AND pt.meta_key = 'vanpos_pickup_time'
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS rtm
				ON oi.order_item_id = rtm.order_item_id AND rtm.meta_key = 'vanpos_return_time'
			{$status_join}
			WHERE pid.meta_value = %s
				AND rt.meta_value >= %s
				AND rf.meta_value <= %s{$exclude_closed_sql}",
			array_merge(
				$active_statuses,
				array( (string) $product_id, $window_start, $window_end )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$bookings = $wpdb->get_results( $query );

		return is_array( $bookings ) ? $bookings : array();
	}

	/**
	 * Bust cached calendar availability for a product (all VanPOS cache versions).
	 *
	 * @param int $product_id Product ID (any language; resolves WPML original).
	 * @return void
	 */
	public static function clear_rental_availability_cache( $product_id ) {
		$product_id = self::get_original_product_id( $product_id );
		delete_transient( 'vanpos_unavail_' . $product_id );
		delete_transient( 'vanpos_cal_avail_v2_' . $product_id );
		delete_transient( 'vanpos_cal_avail_v3_' . $product_id );
	}

	/**
	 * Calendar availability with same-day turnaround (return AM, pickup PM).
	 *
	 * CMIT CODE - UPDATED - 15 MAY 2026
	 * A booking ending on date D only blocks the morning on D, so a new rental
	 * can start on D in the afternoon. Middle days still block both halves.
	 *
	 * CMIT CODE - UPDATED - 15 MAY 2026 (returned)
	 * Line items marked “returned” in Kestrel (WCRP) are excluded in
	 * fetch_active_rental_bookings() — the van is available immediately after
	 * staff click Mark as returned on the order line. See VanPOS_Rental_Returned.
	 *
	 * @param int $product_id Product ID.
	 * @return array{
	 *   unavailablePickupDates: string[],
	 *   unavailableReturnDates: string[],
	 *   unavailableFullDates: string[]
	 * }
	 */
	public static function get_rental_calendar_availability( $product_id ) {
		$product_id = self::get_original_product_id( $product_id );
		$cache_key  = 'vanpos_cal_avail_v3_' . $product_id;
		$cached     = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$empty = array(
			'unavailablePickupDates' => array(),
			'unavailableReturnDates'   => array(),
			'unavailableFullDates'     => array(),
		);

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $empty;
		}

		$stock_quantity = $product->managing_stock() ? (int) $product->get_stock_quantity() : 0;
		if ( $stock_quantity < 1 ) {
			$stock_quantity = 1;
		}

		$today     = new DateTime( current_time( 'Y-m-d' ) );
		$end       = ( clone $today )->modify( '+1 year' );
		$today_str = $today->format( 'Y-m-d' );
		$end_str   = $end->format( 'Y-m-d' );

		$bookings = self::fetch_active_rental_bookings( $product_id, $today_str, $end_str );

		$pickup_blocked = array();
		$return_blocked = array();
		$full_blocked   = array();
		$current        = clone $today;

		while ( $current <= $end ) {
			$date_str = $current->format( 'Y-m-d' );
			$am_full  = ! self::rental_slot_has_capacity( $bookings, $date_str, 'am', $stock_quantity, 1 );
			$pm_full  = ! self::rental_slot_has_capacity( $bookings, $date_str, 'pm', $stock_quantity, 1 );

			if ( $pm_full ) {
				$pickup_blocked[] = $date_str;
			}
			if ( $am_full ) {
				$return_blocked[] = $date_str;
			}
			if ( $am_full && $pm_full ) {
				$full_blocked[] = $date_str;
			}

			$current->modify( '+1 day' );
		}

		$out = array(
			'unavailablePickupDates' => $pickup_blocked,
			'unavailableReturnDates'   => $return_blocked,
			'unavailableFullDates'     => $full_blocked,
		);

		set_transient( $cache_key, $out, 10 * MINUTE_IN_SECONDS );

		return $out;
	}

	/**
	 * Get unavailable dates for a product (dates that cannot be used as pickup afternoon).
	 *
	 * @param int $product_id Product ID.
	 * @return array Array of date strings in Y-m-d format.
	 */
	public static function get_unavailable_dates( $product_id ) {
		// Resolve to original product ID (WPML: bookings are stored against default-language product)
		$product_id = self::get_original_product_id( $product_id );

		// Check if rental products plugin is available
		if ( class_exists( '\Kestrel\Rental_Products\Plugin' ) ) {
			$cal = self::get_rental_calendar_availability( $product_id );
			return $cal['unavailablePickupDates'];
		}

		// Get from ACF field if exists
		$unavailable = self::get_acf_field( $product_id, 'vanpos_unavailable_dates', array() );
		if ( ! is_array( $unavailable ) ) {
			$unavailable = array();
		}

		return $unavailable;
	}

	/**
	 * Legacy wrapper — returns pickup-afternoon blocked dates for the calendar.
	 *
	 * @param int $product_id Product ID.
	 * @return array Array of unavailable date strings in Y-m-d format.
	 */
	private static function get_rental_unavailable_dates( $product_id ) {
		$cal = self::get_rental_calendar_availability( $product_id );
		return $cal['unavailablePickupDates'];
	}

	/**
	 * Check availability using direct booking query.
	 *
	 * NOTE: The previous implementation delegated to
	 * wcrp_rental_products_check_availability() which applies Kestrel's own
	 * business rules (minimum rental period, pickup-day restrictions, buffer
	 * days, etc.) on top of checking actual bookings. When those rules differ
	 * from VanPOS settings the function rejects every date range as
	 * unavailable — the exact same class of bug that was already fixed in
	 * get_rental_unavailable_dates().
	 *
	 * VanPOS already enforces its own min/max days and pickup-day rules in
	 * the JavaScript calendar before this function is ever called, so the
	 * only thing the backend needs to verify is whether the requested dates
	 * actually have booking capacity. This version queries order bookings
	 * directly (same approach as get_rental_unavailable_dates) to avoid
	 * Kestrel business-rule conflicts.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $rent_from Start date in Y-m-d format.
	 * @param string $rent_to End date in Y-m-d format.
	 * @param int    $quantity Quantity (default 1).
	 * @return string 'available' or 'unavailable_dates'
	 */
	public static function check_rental_availability( $product_id, $rent_from, $rent_to, $quantity = 1 ) {
		// Resolve to original product ID (WPML: bookings are stored against default-language product)
		$product_id = self::get_original_product_id( $product_id );

		// If the Kestrel rental plugin is active, check bookings directly
		// against order data (bypasses Kestrel business-rule validation).
		if ( class_exists( '\Kestrel\Rental_Products\Plugin' ) ) {
			return self::check_rental_availability_direct( $product_id, $rent_from, $rent_to, $quantity );
		}

		// Fallback to basic date range check
		return self::is_date_range_available( $product_id, $rent_from, $rent_to ) ? 'available' : 'unavailable_dates';
	}

	/**
	 * Check availability by querying bookings directly (Kestrel-aware).
	 *
	 * Uses half-day slots (morning return, afternoon pickup) so a booking
	 * ending on date D does not block a new pickup on D in the afternoon.
	 * See get_rental_calendar_availability() and rental_request_slots_on_date().
	 *
	 * @param int    $product_id Product ID.
	 * @param string $rent_from Start date in Y-m-d format.
	 * @param string $rent_to End date in Y-m-d format.
	 * @param int    $quantity Quantity to reserve (default 1).
	 * @return string 'available' or 'unavailable_dates'
	 */
	private static function check_rental_availability_direct( $product_id, $rent_from, $rent_to, $quantity = 1 ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 'unavailable_dates';
		}

		$stock_quantity = $product->managing_stock() ? (int) $product->get_stock_quantity() : 0;
		if ( $stock_quantity < 1 ) {
			$stock_quantity = 1;
		}

		$bookings = self::fetch_active_rental_bookings( $product_id, $rent_from, $rent_to );
		$current  = new DateTime( $rent_from );
		$end      = new DateTime( $rent_to );

		while ( $current <= $end ) {
			$date_str = $current->format( 'Y-m-d' );
			$needed   = self::rental_request_slots_on_date( $rent_from, $rent_to, $date_str );

			foreach ( $needed as $slot ) {
				if ( ! self::rental_slot_has_capacity( $bookings, $date_str, $slot, $stock_quantity, $quantity ) ) {
					return 'unavailable_dates';
				}
			}

			$current->modify( '+1 day' );
		}

		return 'available';
	}

	/**
	 * Check if date is available for product
	 *
	 * @param int    $product_id Product ID.
	 * @param string $date Date in Y-m-d format.
	 * @return bool
	 */
	public static function is_date_available( $product_id, $date ) {
		$unavailable_dates = self::get_unavailable_dates( $product_id );
		return ! in_array( $date, $unavailable_dates, true );
	}

	/**
	 * Check if date range is available for product
	 *
	 * @param int    $product_id Product ID.
	 * @param string $start_date Start date in Y-m-d format.
	 * @param string $end_date End date in Y-m-d format.
	 * @return bool
	 */
	public static function is_date_range_available( $product_id, $start_date, $end_date ) {
		// Resolve to original product ID (WPML: bookings are stored against default-language product)
		$product_id = self::get_original_product_id( $product_id );

		// Use rental products plugin if available
		if ( function_exists( 'wcrp_rental_products_check_availability' ) ) {
			$availability = self::check_rental_availability( $product_id, $start_date, $end_date, 1 );
			return $availability === 'available';
		}

		// Fallback to basic date check
		$start = new DateTime( $start_date );
		$end = new DateTime( $end_date );
		$current = clone $start;

		while ( $current <= $end ) {
			$date_str = $current->format( 'Y-m-d' );
			if ( ! self::is_date_available( $product_id, $date_str ) ) {
				return false;
			}
			$current->modify( '+1 day' );
		}

		return true;
	}

	/**
	 * Get pickup days (Thursday, Friday by default)
	 *
	 * @return array Array of day numbers (0=Sunday, 6=Saturday).
	 */
	public static function get_pickup_days() {
		$days = self::get_setting( 'vanpos_pickup_days', array( 4, 5 ) );
		return $days;
	}

	/**
	 * Check if day is a pickup day
	 *
	 * @param int $day Day number (0=Sunday, 6=Saturday).
	 * @return bool
	 */
	public static function is_pickup_day( $day ) {
		$pickup_days = self::get_pickup_days();
		return in_array( $day, $pickup_days, true );
	}

	/**
	 * Get minimum rental days
	 *
	 * @return int
	 */
	public static function get_min_rental_days() {
		return (int) self::get_setting( 'vanpos_min_rental_days', 6 );
	}

	/**
	 * Get maximum rental days
	 *
	 * @return int
	 */
	public static function get_max_rental_days() {
		return (int) self::get_setting( 'vanpos_max_rental_days', 22 );
	}

	/**
	 * Rental day count from pickup/return dates (Kestrel-compatible, both ends inclusive).
	 *
	 * CMIT CODE - UPDATED - 15 MAY 2026
	 * Single source of truth for booking length. Matches cart/checkout (diff + 1) and
	 * WCRP_Rental_Products_Misc::days_total_from_dates when available. Avoids stale
	 * _vanpos_rental_days meta from older backfills that used diff without +1.
	 *
	 * @param string $pickup_date Y-m-d pickup.
	 * @param string $return_date Y-m-d return.
	 * @return int Day count, or 0 if invalid.
	 */
	public static function rental_days_from_dates( $pickup_date, $return_date ) {
		$pickup_date = trim( (string) $pickup_date );
		$return_date = trim( (string) $return_date );
		if ( '' === $pickup_date || '' === $return_date ) {
			return 0;
		}

		if ( class_exists( 'WCRP_Rental_Products_Misc' ) && method_exists( 'WCRP_Rental_Products_Misc', 'days_total_from_dates' ) ) {
			$days = (int) WCRP_Rental_Products_Misc::days_total_from_dates( $pickup_date, $return_date );
			return max( 0, $days );
		}

		$pickup_ts = strtotime( $pickup_date . ' 00:00:00' );
		$return_ts = strtotime( $return_date . ' 00:00:00' );
		if ( ! $pickup_ts || ! $return_ts || $return_ts < $pickup_ts ) {
			return 0;
		}

		return (int) abs( round( ( $return_ts - $pickup_ts ) / DAY_IN_SECONDS ) ) + 1;
	}

	/**
	 * Resolve rental days for an order: prefer dates, then stored meta.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public static function rental_days_for_order( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return 0;
		}

		$from_dates = self::rental_days_from_dates(
			(string) $order->get_meta( '_vanpos_pickup_date' ),
			(string) $order->get_meta( '_vanpos_return_date' )
		);
		if ( $from_dates > 0 ) {
			return $from_dates;
		}

		$stored = (int) $order->get_meta( '_vanpos_rental_days' );
		return max( 0, $stored );
	}

	/**
	 * Billable nights from pickup/return dates.
	 *
	 * CMIT CODE - billing unit is NIGHTS, not inclusive days. Defined as the
	 * canonical inclusive-day count minus one so it can never drift from
	 * rental_days_from_dates() (which mirrors WCRP / cart-checkout). A Thu->Wed
	 * booking = 7 inclusive days = 6 nights. Used for POS/admin pricing only;
	 * min/max validation and the website/WCRP cart still count inclusive days.
	 *
	 * @param string $pickup_date Y-m-d pickup.
	 * @param string $return_date Y-m-d return.
	 * @return int Night count, or 0 if invalid / same-day.
	 */
	public static function rental_nights_from_dates( $pickup_date, $return_date ) {
		$days = self::rental_days_from_dates( $pickup_date, $return_date );
		return $days > 0 ? max( 0, $days - 1 ) : 0;
	}

	/**
	 * Resolve billable nights for an order (prefers dates, then stored meta).
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public static function rental_nights_for_order( $order ) {
		$days = self::rental_days_for_order( $order );
		return $days > 0 ? max( 0, $days - 1 ) : 0;
	}

	/**
	 * Calculate due date for remaining payment based on pickup date and settings
	 *
	 * @param string $pickup_date Pickup date in Y-m-d format.
	 * @return string|null Due date in Y-m-d format, or null if pickup date is invalid.
	 */
	public static function calculate_due_date( $pickup_date ) {
		return self::calculate_due_date_from_pickup( $pickup_date, 'remaining' );
	}

	/**
	 * Calculate due date for security deposit based on pickup date and settings
	 *
	 * @param string $pickup_date Pickup date in Y-m-d format.
	 * @return string|null Due date in Y-m-d format, or null if pickup date is invalid.
	 */
	public static function calculate_security_deposit_due_date( $pickup_date ) {
		return self::calculate_due_date_from_pickup( $pickup_date, 'security_deposit' );
	}

	/**
	 * Calculate due date based on pickup date and payment type settings
	 *
	 * @param string $pickup_date Pickup date in Y-m-d format.
	 * @param string $type Payment type: 'remaining' or 'security_deposit'.
	 * @return string|null Due date in Y-m-d format, or null if pickup date is invalid.
	 */
	public static function calculate_due_date_from_pickup( $pickup_date, $type = 'remaining' ) {
		if ( empty( $pickup_date ) ) {
			return null;
		}

		if ( 'security_deposit' === $type ) {
			$due_date_days = (int) self::get_setting( 'vanpos_security_deposit_days_before_pickup', 14 );
		} else {
			$due_date_days = (int) self::get_setting( 'vanpos_due_date_days', 7 );
		}

		try {
			$pickup_datetime = new DateTime( $pickup_date );
			$due_datetime = clone $pickup_datetime;
			$due_datetime->modify( '-' . $due_date_days . ' days' );
			return $due_datetime->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Calculate rental price using rental plugin's logic
	 *
	 * @param int $product_id Product ID.
	 * @param int $rental_days Number of rental days.
	 * @return float Calculated price.
	 */
	public static function calculate_rental_price( $product_id, $rental_days ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 0;
		}

		// Get default rental options
		$default_rental_options = get_option( 'wcrp_rental_products_default_rental_options', array() );

		// Get pricing settings
		$pricing_type = get_post_meta( $product_id, '_wcrp_rental_products_pricing_type', true );
		if ( '' === $pricing_type ) {
			$pricing_type = isset( $default_rental_options['_wcrp_rental_products_pricing_type'] ) 
				? $default_rental_options['_wcrp_rental_products_pricing_type'] 
				: 'fixed';
		}

		$pricing_period = get_post_meta( $product_id, '_wcrp_rental_products_pricing_period', true );
		if ( '' === $pricing_period ) {
			$pricing_period = isset( $default_rental_options['_wcrp_rental_products_pricing_period'] ) 
				? $default_rental_options['_wcrp_rental_products_pricing_period'] 
				: 1;
		}
		$pricing_period = (int) $pricing_period;

		$base_price = (float) $product->get_price();

		// Check for total overrides (period_selection pricing type)
		$total_overrides = get_post_meta( $product_id, '_wcrp_rental_products_total_overrides', true );
		if ( ! empty( $total_overrides ) && is_array( $total_overrides ) && isset( $total_overrides[ $rental_days ] ) ) {
			// Use total override price if available for this number of days
			$override_price = (float) $total_overrides[ $rental_days ];
			// Convert to inc/exc tax based on WooCommerce settings
			$prices_include_tax = get_option( 'woocommerce_prices_include_tax' );
			if ( 'yes' === $prices_include_tax ) {
				// Price includes tax, but we need to check tax display setting
				$tax_display_shop = get_option( 'woocommerce_tax_display_shop' );
				if ( 'excl' === $tax_display_shop ) {
					// Need to remove tax
					$tax_class = $product->get_tax_class();
					$taxes = WC_Tax::get_rates( $tax_class );
					if ( ! empty( $taxes ) ) {
						$tax_rate_row = array_shift( $taxes );
						$tax_rate = isset( $tax_rate_row['rate'] ) ? (float) $tax_rate_row['rate'] : 0;
						$override_price = $override_price / ( 1 + ( $tax_rate / 100 ) );
					}
				}
			} else {
				// Price excludes tax, but we need to check tax display setting
				$tax_display_shop = get_option( 'woocommerce_tax_display_shop' );
				if ( 'incl' === $tax_display_shop ) {
					// Need to add tax
					$tax_class = $product->get_tax_class();
					$taxes = WC_Tax::get_rates( $tax_class );
					if ( ! empty( $taxes ) ) {
						$tax_rate_row = array_shift( $taxes );
						$tax_rate = isset( $tax_rate_row['rate'] ) ? (float) $tax_rate_row['rate'] : 0;
						$override_price = $override_price * ( 1 + ( $tax_rate / 100 ) );
					}
				}
			}
			return round( $override_price, wc_get_price_decimals() );
		}

		// Get pricing tiers if enabled
		$pricing_tiers = get_post_meta( $product_id, '_wcrp_rental_products_pricing_tiers', true );
		if ( '' === $pricing_tiers ) {
			$pricing_tiers = isset( $default_rental_options['_wcrp_rental_products_pricing_tiers'] ) 
				? $default_rental_options['_wcrp_rental_products_pricing_tiers'] 
				: 'no';
		}

		$pricing_tier_percent = 0;
		if ( 'yes' === $pricing_tiers ) {
			$pricing_tiers_data = get_post_meta( $product_id, '_wcrp_rental_products_pricing_tiers_data', true );
			if ( empty( $pricing_tiers_data ) || ! is_array( $pricing_tiers_data ) ) {
				$pricing_tiers_data = isset( $default_rental_options['_wcrp_rental_products_pricing_tiers_data'] ) 
					? $default_rental_options['_wcrp_rental_products_pricing_tiers_data'] 
					: array();
			}

			// Find highest matching tier
			$pricing_tier_highest = 0;
			if ( ! empty( $pricing_tiers_data ) && isset( $pricing_tiers_data['days'] ) && isset( $pricing_tiers_data['percent'] ) ) {
				foreach ( $pricing_tiers_data['days'] as $index => $tier_days ) {
					$tier_days = (int) $tier_days;
					if ( $tier_days > $pricing_tier_highest && $rental_days > $tier_days ) {
						$pricing_tier_highest = $tier_days;
						if ( isset( $pricing_tiers_data['percent'][ $index ] ) ) {
							$pricing_tier_percent = (float) $pricing_tiers_data['percent'][ $index ];
						}
					}
				}
			}
		}

		// Calculate price based on pricing type
		if ( 'fixed' === $pricing_type ) {
			$calculated_price = $base_price;
			
			// Apply pricing tiers if enabled
			if ( $pricing_tier_percent != 0 ) {
				if ( $pricing_tier_percent > 0 ) {
					$calculated_price = $calculated_price * ( 1 + ( $pricing_tier_percent / 100 ) );
				} else {
					$calculated_price = $calculated_price * ( 1 - ( abs( $pricing_tier_percent ) / 100 ) );
				}
			}
		} elseif ( 'period' === $pricing_type ) {
			if ( 1 === $pricing_period ) {
				// Daily pricing
				$calculated_price = $base_price * $rental_days;
			} else {
				// Period-based pricing
				$periods = ceil( $rental_days / $pricing_period );
				$calculated_price = $base_price * $periods;

				// Apply additional period percent if set
				$price_additional_periods_percent = get_post_meta( $product_id, '_wcrp_rental_products_price_additional_periods_percent', true );
				if ( '' === $price_additional_periods_percent ) {
					$price_additional_periods_percent = isset( $default_rental_options['_wcrp_rental_products_price_additional_periods_percent'] ) 
						? $default_rental_options['_wcrp_rental_products_price_additional_periods_percent'] 
						: 'no';
				}

				if ( 'yes' === $price_additional_periods_percent ) {
					$price_additional_period_percent = get_post_meta( $product_id, '_wcrp_rental_products_price_additional_period_percent', true );
					if ( '' === $price_additional_period_percent ) {
						$price_additional_period_percent = isset( $default_rental_options['_wcrp_rental_products_price_additional_period_percent'] ) 
							? $default_rental_options['_wcrp_rental_products_price_additional_period_percent'] 
							: 0;
					}
					$price_additional_period_percent = (float) $price_additional_period_percent;

					if ( $price_additional_period_percent > 0 && $periods > 1 ) {
						$additional_periods = $periods - 1;
						$calculated_price = $calculated_price + ( ( $base_price * $price_additional_period_percent / 100 ) * $additional_periods );
					}
				}

				// Apply pricing tiers if enabled
				if ( $pricing_tier_percent != 0 ) {
					if ( $pricing_tier_percent > 0 ) {
						$calculated_price = $calculated_price * ( 1 + ( $pricing_tier_percent / 100 ) );
					} else {
						$calculated_price = $calculated_price * ( 1 - ( abs( $pricing_tier_percent ) / 100 ) );
					}
				}
			}
		} else {
			// Default to base price
			$calculated_price = $base_price;
		}

		// Get price decimals setting
		$price_decimals = wc_get_price_decimals();

		// Round to price decimals
		return round( $calculated_price, $price_decimals );
	}

	/**
	 * Add product to cart with rental dates
	 *
	 * @param int    $product_id Product ID.
	 * @param string $pickup_date Pickup date in Y-m-d format.
	 * @param string $return_date Return date in Y-m-d format.
	 * @param string $pickup_time_slot Pickup time slot (morning/afternoon).
	 * @param string $return_time_slot Return time slot (morning/afternoon).
	 * @param bool   $include_dog Whether to include dog option.
	 * @param bool   $include_cleaning Whether to include cleaning service (always true - mandatory).
	 * @return bool|WP_Error
	 */
	public static function add_to_cart( $product_id, $pickup_date, $return_date, $pickup_time_slot = '', $return_time_slot = '', $include_dog = false, $include_cleaning = false ) {
		// Cleaning service is mandatory when enabled in admin settings.
		// Respect the admin toggle so disabling it truly removes cleaning
		// from cart data, display, fees, and order metadata.
		$include_cleaning = VanPOS_Functions::get_setting( 'vanpos_cleaning_enabled', 'yes' ) === 'yes';
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce is required.', 'vanjorn-rental-pos' ) );
		}

		// Resolve to original (default-language) product ID.
		// WPML creates separate posts for each translation, but the rental
		// plugin stores all bookings, pricing config, and validation data
		// against the original product only.  Using the translated ID causes
		// the rental plugin to reject the cart item at checkout ("not available")
		// and also makes availability calendar queries return empty results.
		// WooCommerce Multilingual still translates the product name in the
		// cart/checkout display automatically.
		$product_id = self::get_original_product_id( $product_id );

		// Clear cart before adding rental product (only one rental at a time)
		if ( ! WC()->cart->is_empty() ) {
			WC()->cart->empty_cart( false );
		}

		// Validate dates and calculate rental days
		// Use rental plugin's function if available, otherwise use same calculation method
		if ( class_exists( 'WCRP_Rental_Products_Misc' ) && method_exists( 'WCRP_Rental_Products_Misc', 'days_total_from_dates' ) ) {
			// Use rental plugin's exact calculation method
			$days = WCRP_Rental_Products_Misc::days_total_from_dates( $pickup_date, $return_date );
		} else {
			// Replicate rental plugin's calculation: abs(round((strtotime($date_to) - strtotime($date_from)) / 86400)) + 1
			$days = abs( round( ( strtotime( $return_date ) - strtotime( $pickup_date ) ) / 86400 ) ) + 1;
		}
		$days = (int) $days; // Ensure it's an integer

		// CMIT CODE - storefront line price bills on NIGHTS (days - 1). $days stays
		// inclusive for the min/max window and the calendar-day cart meta below.
		$nights = self::rental_nights_from_dates( $pickup_date, $return_date );

		if ( $days < self::get_min_rental_days() || $days > self::get_max_rental_days() ) {
			return new WP_Error( 'invalid_days', __( 'Invalid rental period.', 'vanjorn-rental-pos' ) );
		}

		// Check availability using rental products plugin
		if ( function_exists( 'wcrp_rental_products_check_availability' ) ) {
			$availability = self::check_rental_availability( $product_id, $pickup_date, $return_date, 1 );
			if ( $availability !== 'available' ) {
				return new WP_Error( 'unavailable', __( 'Selected dates are not available.', 'vanjorn-rental-pos' ) );
			}
		} else {
			// Fallback to basic availability check
			if ( ! self::is_date_range_available( $product_id, $pickup_date, $return_date ) ) {
				return new WP_Error( 'unavailable', __( 'Selected dates are not available.', 'vanjorn-rental-pos' ) );
			}
		}

		// Get return days threshold from product or default
		$return_days_threshold = get_post_meta( $product_id, '_wcrp_rental_products_return_days_threshold', true );
		if ( '' === $return_days_threshold ) {
			$default_rental_options = get_option( 'wcrp_rental_products_default_rental_options', array() );
			$return_days_threshold = isset( $default_rental_options['_wcrp_rental_products_return_days_threshold'] ) 
				? $default_rental_options['_wcrp_rental_products_return_days_threshold'] 
				: 3; // Default to 3 days if not set
		}

		// Get start days threshold
		$start_days_threshold = get_post_meta( $product_id, '_wcrp_rental_products_start_days_threshold', true );
		if ( '' === $start_days_threshold ) {
			$default_rental_options = get_option( 'wcrp_rental_products_default_rental_options', array() );
			$start_days_threshold = isset( $default_rental_options['_wcrp_rental_products_start_days_threshold'] ) 
				? $default_rental_options['_wcrp_rental_products_start_days_threshold'] 
				: 0;
		}

		// Calculate rental price using rental plugin's logic — billed on NIGHTS.
		$calculated_price = self::calculate_rental_price( $product_id, $nights );

		// Get advanced pricing setting
		$advanced_pricing = get_post_meta( $product_id, '_wcrp_rental_products_advanced_pricing', true );
		if ( '' === $advanced_pricing ) {
			$default_rental_options = get_option( 'wcrp_rental_products_default_rental_options', array() );
			$advanced_pricing = isset( $default_rental_options['_wcrp_rental_products_advanced_pricing'] ) 
				? $default_rental_options['_wcrp_rental_products_advanced_pricing'] 
				: 'off';
		}

		// Create timestamp for validation
		// Ensure it's always newer than the product's modified date to prevent "product updated" validation error
		$product_post = get_post( $product_id );
		$current_time = time();
		if ( $product_post ) {
			$product_modified = strtotime( $product_post->post_modified_gmt );
			// Set timestamp to be at least 1 second newer than product's modified date
			$cart_item_timestamp = max( $current_time, $product_modified + 1 );
		} else {
			$cart_item_timestamp = $current_time;
		}

		// Add to cart with both POS and rental plugin formats
		$cart_item_data = array(
			// POS system format (for display and custom features)
			'vanpos_pickup_date'     => $pickup_date,
			'vanpos_return_date'     => $return_date,
			'vanpos_pickup_time'     => $pickup_time_slot,
			'vanpos_return_time'      => $return_time_slot,
			'vanpos_rental_days'     => $days,
			'vanpos_rental_nights'   => $nights,
			'vanpos_include_dog'     => $include_dog,
			'vanpos_include_cleaning' => $include_cleaning,
			// Rental plugin format (required for calculations and pricing)
			'wcrp_rental_products_rent_from' => $pickup_date,
			'wcrp_rental_products_rent_to' => $return_date,
			'wcrp_rental_products_return_days_threshold' => $return_days_threshold,
			'wcrp_rental_products_start_days_threshold' => $start_days_threshold,
			'wcrp_rental_products_cart_item_price' => (string) $calculated_price,
			'wcrp_rental_products_cart_item_timestamp' => (string) $cart_item_timestamp,
			'wcrp_rental_products_cart_item_validation' => 'passed', // Validation passed since we checked availability
		);

		// Add advanced pricing data if enabled
		if ( 'on' === $advanced_pricing ) {
			$cart_item_data['wcrp_rental_products_advanced_pricing'] = 'on';
		}

		// Handle in-person pickup/return if time slots are enabled
		if ( ! empty( $pickup_time_slot ) || ! empty( $return_time_slot ) ) {
			$in_person_pick_up_return = get_post_meta( $product_id, '_wcrp_rental_products_in_person_pick_up_return', true );
			if ( 'yes' === $in_person_pick_up_return ) {
				$cart_item_data['wcrp_rental_products_in_person_pick_up_return'] = 'yes';
				$cart_item_data['wcrp_rental_products_in_person_pick_up_date'] = $pickup_date;
				$cart_item_data['wcrp_rental_products_in_person_return_date'] = $return_date;
				
				// Convert time slots to time format (morning = 09:00, afternoon = 15:00)
				if ( ! empty( $pickup_time_slot ) ) {
					$pickup_time = ( 'morning' === $pickup_time_slot ) ? '0900' : '1500';
					$cart_item_data['wcrp_rental_products_in_person_pick_up_time'] = $pickup_time;
				}
				if ( ! empty( $return_time_slot ) ) {
					$return_time = ( 'morning' === $return_time_slot ) ? '0900' : '1500';
					$cart_item_data['wcrp_rental_products_in_person_return_time'] = $return_time;
				}
			}
		}

		$cart_item_key = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data );

		if ( $cart_item_key ) {
			return true;
		}

		return new WP_Error( 'cart_error', __( 'Failed to add to cart.', 'vanjorn-rental-pos' ) );
	}

	/**
	 * Remove erroneous rental-plugin validation errors for WPML-translated products.
	 *
	 * WCML automatically translates product IDs in the cart to match the
	 * current language.  The Kestrel rental plugin then validates the
	 * translated product ID, which may lack bookings/config, and adds an
	 * "unavailable" error notice.  This method runs after all cart
	 * validation and removes those specific notices for products that are
	 * valid translations of the original rental product.
	 *
	 * Hooked to woocommerce_check_cart_items at priority 99999 so it runs
	 * after every other validator, including Kestrel.
	 */
	public static function fix_wpml_cart_validation() {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) || ! WC()->cart ) {
			return;
		}

		$notices = wc_get_notices( 'error' );
		if ( empty( $notices ) ) {
			return;
		}

		// Collect product names of translated rental items in the cart.
		$translated_rental_names = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			// Only consider rental cart items.
			if ( ! isset( $item['wcrp_rental_products_rent_from'] ) && ! isset( $item['vanpos_pickup_date'] ) ) {
				continue;
			}
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product ) {
				continue;
			}
			$pid      = $product->get_id();
			$original = self::get_original_product_id( $pid );
			if ( (int) $pid !== $original ) {
				$translated_rental_names[] = $product->get_name();
			}
		}

		if ( empty( $translated_rental_names ) ) {
			return;
		}

		// Filter out error notices that mention a translated rental product.
		$keep    = array();
		$removed = false;
		foreach ( $notices as $notice ) {
			$msg  = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;
			$text = wp_strip_all_tags( $msg );

			$is_rental_error = false;
			foreach ( $translated_rental_names as $name ) {
				if ( false !== strpos( $text, $name ) ) {
					$is_rental_error = true;
					$removed         = true;
					break;
				}
			}
			if ( ! $is_rental_error ) {
				$keep[] = $notice;
			}
		}

		if ( ! $removed ) {
			return;
		}

		// Preserve non-error notices, replace error notices with filtered set.
		$success_notices = wc_get_notices( 'success' );
		$info_notices    = wc_get_notices( 'notice' );

		wc_clear_notices();

		foreach ( $success_notices as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'success' );
		}
		foreach ( $info_notices as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'notice' );
		}
		foreach ( $keep as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'error' );
		}
	}

	/**
	 * Remove Kestrel's false "not available for the selected quantity" notices
	 * for VanPOS rental items that the slot-aware check confirms ARE available
	 * (same-day turnaround: AM return frees the PM pickup on the same date).
	 *
	 * Kestrel's WCRP_Rental_Products_Cart_Checks::check_rental_cart_items
	 * (woocommerce_check_cart_items, priority 0) uses a full-day availability
	 * model and counts the boundary day of a turnaround as occupied by both the
	 * outgoing and incoming booking, returning 'unavailable_stock_-1' and
	 * blocking checkout. VanPOS already validates these dates with the
	 * authoritative slot-aware check (check_rental_availability), which treats a
	 * morning return and an afternoon pickup as separate half-day slots.
	 *
	 * Mirrors fix_wpml_cart_validation(): rebuild the error-notice set, dropping
	 * only notices that name a re-confirmed-available rental item, leaving
	 * genuine conflicts and all non-rental notices intact.
	 *
	 * @return void
	 */
	public static function clear_false_turnaround_notices() {
		if ( ! WC()->cart ) {
			return;
		}

		$error_notices = wc_get_notices( 'error' );
		if ( empty( $error_notices ) ) {
			return;
		}

		// Names of rental items our own authoritative check re-confirms available.
		$approved_names = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$from = isset( $item['wcrp_rental_products_rent_from'] )
				? $item['wcrp_rental_products_rent_from']
				: ( isset( $item['vanpos_pickup_date'] ) ? $item['vanpos_pickup_date'] : '' );
			$to   = isset( $item['wcrp_rental_products_rent_to'] )
				? $item['wcrp_rental_products_rent_to']
				: ( isset( $item['vanpos_return_date'] ) ? $item['vanpos_return_date'] : '' );

			// Not a VanPOS rental line — leave its notices alone.
			if ( '' === $from || '' === $to ) {
				continue;
			}

			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product ) {
				continue;
			}

			$qty = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

			// check_rental_availability() resolves the WPML original id internally.
			if ( 'available' === self::check_rental_availability( $product->get_id(), $from, $to, $qty ) ) {
				$approved_names[] = $product->get_name();
			}
		}

		if ( empty( $approved_names ) ) {
			return;
		}

		// Rebuild the error set, dropping notices that name an approved item.
		$keep    = array();
		$removed = false;
		foreach ( $error_notices as $notice ) {
			$msg  = is_array( $notice ) ? ( isset( $notice['notice'] ) ? $notice['notice'] : '' ) : $notice;
			$text = wp_strip_all_tags( (string) $msg );

			$is_false_availability = false;
			foreach ( $approved_names as $name ) {
				if ( '' !== $name && false !== strpos( $text, $name ) ) {
					$is_false_availability = true;
					$removed               = true;
					break;
				}
			}

			if ( ! $is_false_availability ) {
				$keep[] = $notice;
			}
		}

		if ( ! $removed ) {
			return;
		}

		// Preserve other notice types; replace only the error set.
		$success_notices = wc_get_notices( 'success' );
		$info_notices    = wc_get_notices( 'notice' );

		wc_clear_notices();

		foreach ( $success_notices as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'success' );
		}
		foreach ( $info_notices as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'notice' );
		}
		foreach ( $keep as $n ) {
			wc_add_notice( is_array( $n ) ? $n['notice'] : $n, 'error' );
		}
	}
}

// WooCommerce cart integration
add_filter( 'woocommerce_get_item_data', 'vanpos_display_cart_item_data', 10, 2 );
add_filter( 'woocommerce_cart_item_price', 'vanpos_cart_item_price', 10, 3 );
add_filter( 'woocommerce_cart_item_subtotal', 'vanpos_cart_item_subtotal', 10, 3 );
add_filter( 'woocommerce_widget_cart_item_quantity', 'vanpos_widget_cart_item_quantity', 10, 3 );
add_filter( 'woocommerce_cart_subtotal', 'vanpos_widget_cart_subtotal_with_fees', 10, 3 );
add_action( 'woocommerce_cart_calculate_fees', 'vanpos_add_cart_fees' );

// Clear Kestrel's false same-day-turnaround availability errors (AM return frees PM pickup).
// Runs after Kestrel's WCRP_Rental_Products_Cart_Checks::check_rental_cart_items (priority 0)
// and just before fix_wpml_cart_validation (99999).
add_action( 'woocommerce_check_cart_items', array( 'VanPOS_Functions', 'clear_false_turnaround_notices' ), 99998 );

// Fix WPML cart validation: remove false "unavailable" errors from rental plugin for translated products.
add_action( 'woocommerce_check_cart_items', array( 'VanPOS_Functions', 'fix_wpml_cart_validation' ), 99999 );

// Strip speculative Mollie PayPal surcharge from rental/child orders that did not settle on PayPal.
add_action( 'woocommerce_order_status_changed', 'vanpos_strip_non_paypal_gateway_fee', 20, 4 );

/**
 * Display rental dates in cart
 *
 * @param array $item_data Cart item data.
 * @param array $cart_item Cart item.
 * @return array
 */
function vanpos_display_cart_item_data( $item_data, $cart_item ) {
	if ( isset( $cart_item['vanpos_pickup_date'] ) ) {
		$item_data[] = array(
			'name'    => __( 'Pickup date', 'vanjorn-rental-pos' ),
			'value'   => date_i18n( get_option( 'date_format' ), strtotime( $cart_item['vanpos_pickup_date'] ) ),
			'display' => date_i18n( get_option( 'date_format' ), strtotime( $cart_item['vanpos_pickup_date'] ) ),
		);
	}

	if ( isset( $cart_item['vanpos_return_date'] ) ) {
		$item_data[] = array(
			'name'    => __( 'Return date', 'vanjorn-rental-pos' ),
			'value'   => date_i18n( get_option( 'date_format' ), strtotime( $cart_item['vanpos_return_date'] ) ),
			'display' => date_i18n( get_option( 'date_format' ), strtotime( $cart_item['vanpos_return_date'] ) ),
		);
	}

	if ( isset( $cart_item['vanpos_pickup_time'] ) && ! empty( $cart_item['vanpos_pickup_time'] ) ) {
		$pickup_time_display = VanPOS_Functions::get_setting( 'vanpos_pickup_time', '15:00' );
		$item_data[] = array(
			'name'    => __( 'Pickup time', 'vanjorn-rental-pos' ),
			'value'   => $pickup_time_display,
			'display' => $pickup_time_display,
		);
	}

	if ( isset( $cart_item['vanpos_return_time'] ) && ! empty( $cart_item['vanpos_return_time'] ) ) {
		$return_time_display = VanPOS_Functions::get_setting( 'vanpos_return_time', '11:00' );
		$item_data[] = array(
			'name'    => __( 'Return time', 'vanjorn-rental-pos' ),
			'value'   => $return_time_display,
			'display' => $return_time_display,
		);
	}

	if ( isset( $cart_item['vanpos_rental_days'] ) ) {
		$item_data[] = array(
			'name'    => __( 'Rental days', 'vanjorn-rental-pos' ),
			'value'   => $cart_item['vanpos_rental_days'],
			'display' => $cart_item['vanpos_rental_days'] . ' ' . _n( 'day', 'days', $cart_item['vanpos_rental_days'], 'vanjorn-rental-pos' ),
		);
	}

	if ( isset( $cart_item['vanpos_include_dog'] ) && $cart_item['vanpos_include_dog'] ) {
		$item_data[] = array(
			'name'    => __( 'Additional options', 'vanjorn-rental-pos' ),
			'value'   => __( 'Bring your dog', 'vanjorn-rental-pos' ),
			'display' => __( 'Bring your dog', 'vanjorn-rental-pos' ),
		);
	}

	if ( isset( $cart_item['vanpos_include_cleaning'] ) && $cart_item['vanpos_include_cleaning'] ) {
		$cleaning_price = (float) VanPOS_Functions::get_setting( 'vanpos_cleaning_price', 100 );
		$cleaning_label = __( 'Use our cleaning service', 'vanjorn-rental-pos' ) . ' (' . wc_price( $cleaning_price ) . ')';
		$item_data[] = array(
			'name'    => __( 'Additional options', 'vanjorn-rental-pos' ),
			'value'   => $cleaning_label,
			'display' => $cleaning_label,
		);
	}

	return $item_data;
}

/**
 * Update cart item price based on rental days
 * Only applies if rental plugin is not handling pricing
 *
 * @param string $price Price HTML.
 * @param array  $cart_item Cart item.
 * @param string $cart_item_key Cart item key.
 * @return string
 */
function vanpos_cart_item_price( $price, $cart_item, $cart_item_key ) {
	// Let rental plugin handle pricing if it's present
	if ( isset( $cart_item['wcrp_rental_products_rent_from'] ) && isset( $cart_item['wcrp_rental_products_rent_to'] ) ) {
		// Rental plugin will handle pricing, don't override
		return $price;
	}
	
	// Fallback: Only calculate if rental plugin data is not present
	if ( isset( $cart_item['vanpos_rental_days'] ) && isset( $cart_item['vanpos_pickup_date'] ) ) {
		$product = $cart_item['data'];
		$base_price = (float) $product->get_price();
		$days = (int) $cart_item['vanpos_rental_days'];
		$total_price = $base_price * $days;
		$price = wc_price( $total_price );
	}
	return $price;
}

/**
 * Update cart item subtotal based on rental days
 * Only applies if rental plugin is not handling pricing
 *
 * @param string $subtotal Subtotal HTML.
 * @param array  $cart_item Cart item.
 * @param string $cart_item_key Cart item key.
 * @return string
 */
function vanpos_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {
	// Let rental plugin handle pricing if it's present
	if ( isset( $cart_item['wcrp_rental_products_rent_from'] ) && isset( $cart_item['wcrp_rental_products_rent_to'] ) ) {
		// Rental plugin will handle pricing, don't override
		return $subtotal;
	}
	
	// Fallback: Only calculate if rental plugin data is not present
	if ( isset( $cart_item['vanpos_rental_days'] ) && isset( $cart_item['vanpos_pickup_date'] ) ) {
		$product = $cart_item['data'];
		$base_price = (float) $product->get_price();
		$days = (int) $cart_item['vanpos_rental_days'];
		$total_price = $base_price * $days;
		$subtotal = wc_price( $total_price );
	}
	return $subtotal;
}

/**
 * Override mini-cart quantity display to show rental days × daily rate
 *
 * @param string $output    Default quantity HTML.
 * @param array  $cart_item Cart item.
 * @param string $cart_item_key Cart item key.
 * @return string
 */
function vanpos_widget_cart_item_quantity( $output, $cart_item, $cart_item_key ) {
	if ( isset( $cart_item['vanpos_rental_days'] ) && isset( $cart_item['vanpos_pickup_date'] ) ) {
		$product    = $cart_item['data'];
		$base_price = (float) $product->get_price();
		$days       = (int) $cart_item['vanpos_rental_days'];
		$output     = '<span class="quantity">' . $days . ' &times; ' . wc_price( $base_price ) . '</span>';
	}
	return $output;
}

/**
 * Include fees (cleaning, dog) in the mini-cart widget subtotal.
 * On the full cart/checkout pages fees are shown separately, so skip there.
 *
 * @param string  $cart_subtotal Subtotal HTML.
 * @param bool    $compound      Whether it is a compound subtotal.
 * @param WC_Cart $cart          Cart object.
 * @return string
 */
function vanpos_widget_cart_subtotal_with_fees( $cart_subtotal, $compound, $cart ) {
	// Only modify in mini-cart context; leave full cart/checkout alone
	if ( is_cart() || is_checkout() ) {
		return $cart_subtotal;
	}

	$extras_total = 0;

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( ! empty( $cart_item['vanpos_include_cleaning'] ) ) {
			$cleaning_enabled = VanPOS_Functions::get_setting( 'vanpos_cleaning_enabled', 'yes' ) === 'yes';
			if ( $cleaning_enabled ) {
				$extras_total += (float) VanPOS_Functions::get_setting( 'vanpos_cleaning_price', 100 );
			}
		}
		if ( ! empty( $cart_item['vanpos_include_dog'] ) ) {
			$extras_total += (float) VanPOS_Functions::get_setting( 'vanpos_dog_price', 100 );
		}
	}

	if ( $extras_total > 0 ) {
		$displayed_subtotal = $cart->get_displayed_subtotal();
		$cart_subtotal = wc_price( $displayed_subtotal + $extras_total );
	}

	return $cart_subtotal;
}

/**
 * Add fees for additional options
 *
 * @return void
 */
function vanpos_add_cart_fees() {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	$dog_added = false;
	$cleaning_added = false;

	foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
		if ( ! $dog_added && isset( $cart_item['vanpos_include_dog'] ) && $cart_item['vanpos_include_dog'] ) {
			$dog_price = (float) VanPOS_Functions::get_setting( 'vanpos_dog_price', 100 );
			if ( $dog_price > 0 ) {
				WC()->cart->add_fee( __( 'Bring your dog', 'vanjorn-rental-pos' ), $dog_price );
				$dog_added = true;
			}
		}

		// Cleaning service is mandatory when enabled — check both the cart flag and admin setting
		if ( ! $cleaning_added && isset( $cart_item['vanpos_include_cleaning'] ) && $cart_item['vanpos_include_cleaning'] ) {
			$cleaning_enabled = VanPOS_Functions::get_setting( 'vanpos_cleaning_enabled', 'yes' ) === 'yes';
			if ( $cleaning_enabled ) {
				$cleaning_price = (float) VanPOS_Functions::get_setting( 'vanpos_cleaning_price', 100 );
				// Add fee even if price is 0 (will show as free)
				$fee_label = __( 'Use our cleaning service', 'vanjorn-rental-pos' );
				if ( $cleaning_price == 0 ) {
					$fee_label = __( 'Use our cleaning service (free)', 'vanjorn-rental-pos' );
				}
				WC()->cart->add_fee( $fee_label, $cleaning_price );
				$cleaning_added = true;
			}
		}
	}
}
/**
 * Strip a speculative Mollie gateway (PayPal) surcharge from rental and child
 * payment orders that did not actually settle on PayPal.
 *
 * Background: Mollie's GatewaySurchargeHandler adds the gateway fee on the
 * order-pay page via the `update_surcharge_order_pay` AJAX call, keyed on the
 * gateway selected on that page. WooCommerce pre-selects the first available
 * gateway, so when PayPal is the default the fee can be written (and persisted
 * via calculate_totals()) before the customer makes a conscious choice — and it
 * remains stuck on the order if they switch methods or abandon. This guard runs
 * at settlement: if the order genuinely resolved on PayPal the surcharge is
 * legitimate and kept; otherwise the leftover fee is removed and totals recalc'd.
 *
 * Scope is limited to our own order types so customer carts and non-rental
 * orders are never touched. The static re-entrancy flag prevents the
 * calculate_totals() save from re-triggering this hook.
 *
 * @param int      $order_id Order ID.
 * @param string   $from     Previous status.
 * @param string   $to       New status.
 * @param WC_Order $order    Order object.
 * @return void
 */
function vanpos_strip_non_paypal_gateway_fee( $order_id, $from, $to, $order ) {
	static $running = false;
	if ( $running ) {
		return;
	}

	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
	}

	// Only our programmatic / admin-built orders. Drop 'primary_rental' from
	// this list if the front-end checkout cart surcharge should stay on the
	// main rental order.
	$type = $order->get_meta( '_vanpos_order_type' );
	if ( ! in_array( $type, array( 'payment_order', 'primary_rental' ), true ) ) {
		return;
	}

	// Genuinely settled on PayPal → surcharge is legitimate, keep it.
	if ( 'mollie_wc_gateway_paypal' === $order->get_payment_method() ) {
		return;
	}

	// Match Mollie's configurable fee label exactly (default "Gateway Fee"),
	// the same way Mollie's own orderRemoveFee() does.
	$fee_label = get_option(
		'mollie-payments-for-woocommerce_gatewayFeeLabel',
		__( 'Gateway Fee', 'mollie-payments-for-woocommerce' )
	);

	$removed = false;
	foreach ( $order->get_items( 'fee' ) as $fee_id => $fee ) {
		if ( strpos( $fee->get_name(), $fee_label ) !== false ) {
			$order->remove_item( $fee_id );
			wc_delete_order_item( $fee_id );
			$removed = true;
		}
	}

	if ( $removed ) {
		$running = true;
		$order->calculate_totals();
		$order->add_order_note(
			__( 'Removed speculative PayPal gateway surcharge (order not settled on PayPal).', 'vanjorn-rental-pos' )
		);
		$running = false;
	}
}
