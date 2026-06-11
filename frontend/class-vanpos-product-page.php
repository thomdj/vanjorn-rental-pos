<?php
/**
 * VanPOS Rental Product Page Customizations
 *
 * Transforms the WooCommerce single product page for rental vans.
 * Migrated from child theme — URL and version references updated to
 * use plugin constants (VANPOS_PLUGIN_URL, VANPOS_VERSION).
 *
 *   1. KESTREL REPLACEMENT
 *      Removes the Kestrel rental form and renders the VanPOS booking
 *      calendar in its place.
 *
 *   2. LAYOUT REORDER
 *      Moves title, price and product meta into the gallery column.
 *
 *   3. VANPOS GALLERY
 *      Replaces the WooCommerce product gallery with the VanPOS carousel.
 *
 * Non-rental WooCommerce products are completely unaffected.
 *
 * WPML COMPATIBLE:
 *   Translated product pages resolve back to the original (default-language)
 *   product ID when querying rental status and the booking calendar shortcode.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =========================================================================
 * WPML helper
 * ========================================================================= */

/**
 * Resolve a product ID to the original (default-language) product ID.
 *
 * @param int $product_id WC product ID (possibly a translation).
 * @return int Original product ID in the default language.
 */
function vanpos_get_original_product_id( $product_id ) {
	if ( function_exists( 'wpml_object_id_filter' ) || has_filter( 'wpml_object_id' ) ) {
		$default_lang = apply_filters( 'wpml_default_language', null );
		$original_id  = apply_filters( 'wpml_object_id', $product_id, 'product', true, $default_lang );
		if ( $original_id ) {
			return (int) $original_id;
		}
	}
	return (int) $product_id;
}

/* =========================================================================
 * Main setup — hooked to 'wp' so is_product() is available
 * ========================================================================= */

add_action( 'wp', 'vanpos_product_page_setup' );

/**
 * Main entry point — runs on 'wp' after the query is parsed.
 */
function vanpos_product_page_setup() {
	if ( is_admin() || ! is_product() ) {
		return;
	}

	global $post;
	$product_id  = $post->ID;
	$original_id = vanpos_get_original_product_id( $product_id );

	if ( ! function_exists( 'wcrp_rental_products_is_rental_only' ) ) {
		return;
	}

	$is_rental = wcrp_rental_products_is_rental_only( $original_id );

	if ( function_exists( 'wcrp_rental_products_is_rental_bundle' ) ) {
		$is_rental = $is_rental || wcrp_rental_products_is_rental_bundle( $original_id );
	}

	if ( function_exists( 'wcrp_rental_products_is_rental_purchase' ) && wcrp_rental_products_is_rental_purchase( $original_id ) ) {
		$is_rental = $is_rental || ( isset( $_GET['rent'] ) && '1' === $_GET['rent'] );
	}

	if ( ! $is_rental ) {
		return;
	}

	// Signal to VanPOS_Frontend::enqueue_scripts() to load assets on this page.
	$GLOBALS['vanpos_force_enqueue'] = true;

	// 1. Remove Kestrel rental form.
	vanpos_remove_kestrel_hooks( array(
		'woocommerce_before_add_to_cart_button',
		'woocommerce_after_add_to_cart_quantity',
		'woocommerce_before_add_to_cart_form',
		'woocommerce_after_add_to_cart_form',
	) );

	remove_action( 'woocommerce_simple_add_to_cart', 'woocommerce_simple_add_to_cart', 30 );
	add_action( 'woocommerce_single_product_summary', 'vanpos_hide_product_cart_form_css', 29 );
	add_action( 'woocommerce_single_product_summary', 'vanpos_render_single_van_calendar', 30 );

	// 2. Reorder layout.
	add_action( 'woocommerce_before_single_product_summary', 'vanpos_open_gallery_column', 1 );
	add_action( 'woocommerce_before_single_product_summary', 'vanpos_close_gallery_column', 99 );

	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
	add_action( 'woocommerce_before_single_product_summary', 'woocommerce_template_single_title', 5 );
	add_action( 'woocommerce_before_single_product_summary', 'woocommerce_template_single_price', 6 );

	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
	add_action( 'woocommerce_before_single_product_summary', 'woocommerce_template_single_meta', 40 );

	// 3. Replace WC gallery with VanPOS gallery.
	remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
	add_action( 'woocommerce_before_single_product_summary', 'vanpos_render_product_gallery', 20 );

	add_action( 'wp_enqueue_scripts', 'vanpos_enqueue_product_gallery_script' );
}

/* =========================================================================
 * Helper: Remove Kestrel form hooks
 * ========================================================================= */

/**
 * Remove all hooks registered by WCRP_Rental_Products_Product_Rental_Form
 * on the given action tags.
 *
 * @param string[] $tags Action/filter tags to search.
 */
function vanpos_remove_kestrel_hooks( $tags ) {
	if ( ! class_exists( 'WCRP_Rental_Products_Product_Rental_Form' ) ) {
		return;
	}

	global $wp_filter;

	foreach ( $tags as $tag ) {
		if ( ! isset( $wp_filter[ $tag ] ) ) {
			continue;
		}

		foreach ( $wp_filter[ $tag ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $key => $callback ) {
				if (
					is_array( $callback['function'] ) &&
					is_object( $callback['function'][0] ) &&
					$callback['function'][0] instanceof WCRP_Rental_Products_Product_Rental_Form
				) {
					unset( $wp_filter[ $tag ]->callbacks[ $priority ][ $key ] );
				}
			}
		}
	}
}

/* =========================================================================
 * Enqueue
 * ========================================================================= */

/**
 * Enqueue the product gallery carousel script from the plugin directory.
 */
function vanpos_enqueue_product_gallery_script() {
	wp_enqueue_script(
		'vanpos-product-gallery',
		VANPOS_PLUGIN_URL . 'frontend/js/vanpos-product-gallery.js',
		array( 'vanpos-calendar' ),
		VANPOS_VERSION,
		true
	);
}

/* =========================================================================
 * Renderers
 * ========================================================================= */

/**
 * Open the gallery-column wrapper.
 */
function vanpos_open_gallery_column() {
	echo '<div class="woocommerce-product-gallery woocommerce-product-gallery-vanpos images">';
}

/**
 * Close the gallery-column wrapper.
 */
function vanpos_close_gallery_column() {
	echo '</div>';
}

/**
 * Output CSS to hide the WooCommerce add-to-cart form on rental product pages.
 */
function vanpos_hide_product_cart_form_css() {
	?>
	<style>
		.wcrp-rental-products-is-rental .product form.cart {
			display: none !important;
		}
	</style>
	<?php
}

/**
 * Render the VanPOS booking calendar for the current product.
 */
function vanpos_render_single_van_calendar() {
	global $post;
	$original_id = vanpos_get_original_product_id( $post->ID );
	echo do_shortcode( '[vanjorn_rental_pos product_id="' . absint( $original_id ) . '"]' );
}

/**
 * Render the VanPOS gallery carousel in place of the WooCommerce product gallery.
 */
function vanpos_render_product_gallery() {
	global $product;

	if ( ! $product ) {
		return;
	}

	$product_id  = $product->get_id();
	$original_id = vanpos_get_original_product_id( $product_id );
	$alt         = esc_attr( $product->get_name() );

	$images    = vanpos_get_product_gallery_images( $original_id, $product );
	$full_urls = array_map( function ( $img ) {
		return $img['full'];
	}, $images );

	printf(
		'<div class="van-hero vanpos-product-gallery" id="vanposProductGallery" data-images="%s">',
		esc_attr( wp_json_encode( $full_urls ) )
	);

	if ( count( $images ) > 1 ) {
		echo '<div class="vanpos-gallery">';
		echo '<div class="vanpos-gallery-slides">';
		foreach ( $images as $i => $img ) {
			$active  = 0 === $i ? ' active' : '';
			$src_att = 0 === $i ? ' src="' . esc_url( $img['thumb'] ) . '"' : '';
			printf(
				'<div class="vanpos-gallery-slide%s"><img%s data-src="%s" alt="%s" loading="lazy" draggable="false"></div>',
				$active,
				$src_att,
				esc_url( $img['thumb'] ),
				$alt
			);
		}
		echo '</div>';

		echo '<button class="vanpos-gallery-btn vanpos-gallery-prev"><span class="material-icons">chevron_left</span></button>';
		echo '<button class="vanpos-gallery-btn vanpos-gallery-next"><span class="material-icons">chevron_right</span></button>';

		echo '<div class="vanpos-gallery-dots">';
		foreach ( $images as $i => $img ) {
			printf(
				'<span class="vanpos-gallery-dot%s" data-index="%d"></span>',
				0 === $i ? ' active' : '',
				$i
			);
		}
		echo '</div>';
		echo '</div>';

	} elseif ( count( $images ) === 1 ) {
		printf(
			'<img src="%s" alt="%s" draggable="false" class="vanpos-gallery-single">',
			esc_url( $images[0]['thumb'] ),
			$alt
		);
	} else {
		echo '<span class="material-icons">directions_bus</span>';
	}

	echo '</div>';
}

/**
 * Get gallery images for a product.
 *
 * Tries VanPOS_Functions::get_rental_products() first (includes ACF gallery
 * fields). Falls back to WooCommerce's native product gallery + featured image.
 *
 * @param int        $product_id WC product ID (original/default-language ID).
 * @param WC_Product $product    WC product object (used only for WC fallback).
 * @return array [ { thumb => url, full => url }, ... ]
 */
function vanpos_get_product_gallery_images( $product_id, $product ) {
	if ( class_exists( 'VanPOS_Functions' ) && method_exists( 'VanPOS_Functions', 'get_rental_products' ) ) {
		$all_products = VanPOS_Functions::get_rental_products();
		foreach ( $all_products as $p ) {
			if ( (int) $p['id'] !== $product_id ) {
				continue;
			}

			$images = array();

			if ( ! empty( $p['gallery'] ) && is_array( $p['gallery'] ) ) {
				foreach ( $p['gallery'] as $img ) {
					$thumb = isset( $img['thumb'] ) ? $img['thumb'] : '';
					$full  = isset( $img['full'] )  ? $img['full']  : $thumb;
					if ( $thumb || $full ) {
						$images[] = array(
							'thumb' => $thumb ?: $full,
							'full'  => $full  ?: $thumb,
						);
					}
				}
			}

			if ( ! empty( $images ) ) {
				return $images;
			}

			if ( ! empty( $p['image'] ) ) {
				return array( array( 'thumb' => $p['image'], 'full' => $p['image'] ) );
			}

			break;
		}
	}

	// Fallback: WooCommerce native gallery.
	$images   = array();
	$thumb_id = $product->get_image_id();

	if ( $thumb_id ) {
		$thumb_src = wp_get_attachment_image_src( $thumb_id, 'large' );
		$full_src  = wp_get_attachment_image_src( $thumb_id, 'full' );
		if ( $thumb_src ) {
			$images[] = array(
				'thumb' => $thumb_src[0],
				'full'  => $full_src ? $full_src[0] : $thumb_src[0],
			);
		}
	}

	foreach ( $product->get_gallery_image_ids() as $att_id ) {
		$thumb_src = wp_get_attachment_image_src( $att_id, 'large' );
		$full_src  = wp_get_attachment_image_src( $att_id, 'full' );
		if ( $thumb_src ) {
			$images[] = array(
				'thumb' => $thumb_src[0],
				'full'  => $full_src ? $full_src[0] : $thumb_src[0],
			);
		}
	}

	return $images;
}
