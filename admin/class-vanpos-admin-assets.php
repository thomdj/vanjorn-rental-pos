<?php
/**
 * VanPOS admin asset loader.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Admin_Assets {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Enqueue scripts/styles for VanPOS admin screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_scripts( $hook ) {
		$allowed_hooks = array(
			'toplevel_page_vanjorn-rental-pos',
		);

		$is_order_edit_page = false;
		global $pagenow;

		if ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] && isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) {
			$is_order_edit_page = true;
		} elseif ( 'post.php' === $pagenow || 'post-new.php' === $pagenow ) {
			global $post;
			if ( $post && 'shop_order' === $post->post_type ) {
				$is_order_edit_page = true;
			}
		} elseif ( false !== strpos( $hook, 'woocommerce_page_wc-orders' ) ) {
			$is_order_edit_page = true;
		}

		$is_orders_list = false;
		if ( 'woocommerce_page_wc-orders' === $hook && ( empty( $_GET['action'] ) || 'edit' !== $_GET['action'] ) ) {
			$is_orders_list = true;
		} elseif ( 'edit.php' === $pagenow && isset( $_GET['post_type'] ) && 'shop_order' === $_GET['post_type'] ) {
			$is_orders_list = true;
		}
		if ( $is_orders_list ) {
			wp_enqueue_style( 'vanpos-orders-list', VANPOS_PLUGIN_URL . 'admin/css/orders-list.css', array(), VANPOS_VERSION );
		}

		if ( $is_order_edit_page ) {
			wp_enqueue_script( 'vanpos-admin-orders', VANPOS_PLUGIN_URL . 'admin/js/admin-orders.js', array( 'jquery' ), VANPOS_VERSION, true );
			wp_localize_script(
				'vanpos-admin-orders',
				'vanposAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'vanpos_create_child_order' ),
					'i18n'    => array(
						'creating'         => __( 'Creating child order...', 'vanjorn-rental-pos' ),
						'success'          => __( 'Child order created successfully!', 'vanjorn-rental-pos' ),
						'error'            => __( 'Error creating child order.', 'vanjorn-rental-pos' ),
						'invalidOrderId'   => __( 'Invalid order ID.', 'vanjorn-rental-pos' ),
						'viewNewOrder'     => __( 'Would you like to view the new order?', 'vanjorn-rental-pos' ),
						'updatingMetadata' => __( 'Updating metadata...', 'vanjorn-rental-pos' ),
						'metadataSuccess'  => __( 'Metadata updated successfully!', 'vanjorn-rental-pos' ),
						'metadataError'    => __( 'Error updating metadata.', 'vanjorn-rental-pos' ),
						'dismissNotice'    => __( 'Dismiss this notice.', 'vanjorn-rental-pos' ),
					),
				)
			);
			wp_enqueue_style( 'vanpos-admin', VANPOS_PLUGIN_URL . 'admin/css/admin.css', array(), VANPOS_VERSION );
			return;
		}

		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'vanpos-admin', VANPOS_PLUGIN_URL . 'admin/css/admin.css', array(), VANPOS_VERSION );
	}
}

