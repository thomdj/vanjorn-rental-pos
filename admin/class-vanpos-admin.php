<?php
/**
 * Admin functionality for VAN-Jorn Rental POS
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class
 */
class VanPOS_Admin {
	public function __construct() {}
	// CMITX UPDATE 2026-04-21:
	// Legacy compatibility shell:
	// - Order edit/meta boxes moved to `class-vanpos-admin-order-edit.php`.
	// - Order-list logic moved to `class-vanpos-admin-order-list.php`.
	// - AJAX handlers moved to `class-vanpos-admin-ajax.php`.
	// - Asset loading moved to `class-vanpos-admin-assets.php`.
}
