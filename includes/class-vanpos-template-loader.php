<?php
/**
 * VanPOS WooCommerce Template Loader
 *
 * Registers the plugin's template directory with WooCommerce so that
 * overridden templates can live in the plugin rather than the child theme.
 *
 * Templates are stored at:
 *   {plugin}/templates/woocommerce/{template_name}
 *   e.g. templates/woocommerce/checkout/form-checkout.php
 *        templates/woocommerce/myaccount/orders.php
 *
 * WooCommerce checks the child theme first. Because the corresponding theme
 * files are deleted as part of the plugin migration, WC falls through to
 * `woocommerce_locate_template`, where this class intercepts the lookup.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template Loader Class
 */
class VanPOS_Template_Loader {

	/**
	 * Plugin templates directory (absolute path, no trailing slash).
	 */
	const TEMPLATE_DIR = 'templates/woocommerce/';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_locate_template', array( __CLASS__, 'locate_template' ), 10, 3 );
	}

	/**
	 * Return the plugin's template path if we have one for the requested name;
	 * otherwise return the unmodified result of WooCommerce's own lookup.
	 *
	 * @param string $template      Full path resolved so far (theme or WC default).
	 * @param string $template_name Relative name, e.g. "checkout/form-checkout.php".
	 * @param string $template_path Template path argument passed to wc_get_template().
	 * @return string
	 */
	public static function locate_template( $template, $template_name, $template_path ) {
		$plugin_template = VANPOS_PLUGIN_DIR . self::TEMPLATE_DIR . $template_name;

		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}
}
