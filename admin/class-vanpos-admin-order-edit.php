<?php
/**
 * VanPOS admin order-edit/meta-box rendering.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Admin_Order_Edit {

	public function __construct() {
		add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'render_create_child_order_button' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_order_meta_boxes' ) );
	}

	public function add_order_meta_boxes( $screen_id ) {
		$is_order_screen = false;
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$order_screen_id = wc_get_page_screen_id( 'shop-order' );
			if ( $screen_id === $order_screen_id ) {
				$is_order_screen = true;
			}
		}
		if ( ! $is_order_screen && 'woocommerce_page_wc-orders' === $screen_id ) {
			$is_order_screen = true;
		}
		if ( $is_order_screen ) {
			add_meta_box( 'vanpos-child-orders', __( 'Rental Payment Orders', 'vanjorn-rental-pos' ), array( $this, 'render_child_orders_meta_box' ), $screen_id, 'side', 'default' );
			add_meta_box( 'vanpos-order-meta-debug', __( 'VanPOS Meta', 'vanjorn-rental-pos' ), array( $this, 'render_order_meta_debug_box' ), $screen_id, 'normal', 'low' );
		}
	}

	public function render_child_orders_meta_box( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} else {
			$order_id = is_object( $post_or_order ) ? $post_or_order->ID : absint( $post_or_order );
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$order_type = $order->get_meta( '_vanpos_order_type' );
		$is_child_order = ( 'payment_order' === $order_type );
		if ( $is_child_order ) {
			$this->render_parent_order_info( $order );
			return;
		}
		$is_rental_order = $this->is_primary_rental_order( $order );
		if ( $is_rental_order ) {
			$this->update_missing_rental_metadata( $order );
			$order = wc_get_order( $order->get_id() );
		} else {
			echo '<p class="description">' . esc_html__( 'This order is not a rental order.', 'vanjorn-rental-pos' ) . '</p>';
			return;
		}

		$child_orders = array();
		$has_remaining_order = false;
		$has_security_deposit_order = false;
		if ( class_exists( 'VanPOS_Order_Manager' ) ) {
			$child_orders = VanPOS_Order_Manager::get_payment_orders( $order->get_id() );
			$has_remaining_order = VanPOS_Order_Manager::has_payment_order( $order->get_id(), 'remaining' );
			$has_security_deposit_order = VanPOS_Order_Manager::has_payment_order( $order->get_id(), 'security_deposit' );
		}
		$security_deposit_amount = (float) $order->get_meta( '_vanpos_security_deposit_payment' );
		if ( $security_deposit_amount <= 0 ) {
			$sd_product_id = VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' );
			if ( $sd_product_id ) {
				$sd_product = wc_get_product( $sd_product_id );
				if ( $sd_product ) {
					$security_deposit_amount = (float) $sd_product->get_price();
				}
			}
		}
		$remaining_amount = 0;
		foreach ( $order->get_items() as $item ) {
			$item_remaining = (float) $item->get_meta( '_vanpos_remaining_amount' );
			if ( $item_remaining > 0 ) {
				$remaining_amount += $item_remaining;
			}
		}
		if ( $remaining_amount <= 0 ) {
			$remaining_amount = (float) $order->get_meta( '_vanpos_remaining_payment' );
		}
		$order_type = $order->get_meta( '_vanpos_order_type' );
		$total_price = (float) $order->get_meta( '_vanpos_total_price' );
		$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
		$return_date = $order->get_meta( '_vanpos_return_date' );
		$price_overridden = 'yes' === (string) $order->get_meta( '_vanpos_price_overridden' );
		// empty( 0.0 ) is true in PHP — a deliberate zero total on a price-override order
		// would falsely trigger the notice without the $price_overridden exemption.
		$metadata_missing = ! $price_overridden && ( empty( $order_type ) || empty( $total_price ) || empty( $pickup_date ) || empty( $return_date ) );
		?>
		<?php if ( $metadata_missing ) : ?>
			<div class="notice notice-warning inline" style="margin: 10px 0;">
				<p><strong><?php esc_html_e( 'Missing Rental Metadata', 'vanjorn-rental-pos' ); ?></strong><br><?php esc_html_e( 'This order appears to be a rental order but is missing some metadata. Click the button below to update it.', 'vanjorn-rental-pos' ); ?></p>
				<p><button type="button" class="button vanpos-update-metadata" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Update Rental Metadata', 'vanjorn-rental-pos' ); ?></button></p>
			</div>
		<?php endif; ?>
		<?php if ( ! $has_security_deposit_order && $security_deposit_amount > 0 ) : ?>
			<p><button type="button" class="button button-primary vanpos-create-security-deposit-order" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Create Security Deposit Order', 'vanjorn-rental-pos' ); ?></button>
			<span class="description" style="display: block; margin-top: 5px;"><?php printf( esc_html__( 'Security deposit amount: %s', 'vanjorn-rental-pos' ), wc_price( $security_deposit_amount ) ); ?></span></p>
		<?php elseif ( $has_security_deposit_order ) : ?>
			<p class="description"><?php esc_html_e( 'Security deposit order already exists.', 'vanjorn-rental-pos' ); ?></p>
		<?php endif; ?>
		<?php if ( ! $has_remaining_order && $remaining_amount > 0 ) : ?>
			<p><button type="button" class="button button-primary vanpos-create-child-order" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-payment-type="remaining"><?php esc_html_e( 'Create Remaining Payment Order', 'vanjorn-rental-pos' ); ?></button>
			<span class="description" style="display: block; margin-top: 5px;"><?php printf( esc_html__( 'Remaining amount: %s', 'vanjorn-rental-pos' ), wc_price( $remaining_amount ) ); ?></span></p>
		<?php elseif ( $has_remaining_order ) : ?>
			<p class="description"><?php esc_html_e( 'Remaining payment order already exists.', 'vanjorn-rental-pos' ); ?></p>
		<?php elseif ( $remaining_amount <= 0 && ! $metadata_missing ) : ?>
			<p class="description"><?php esc_html_e( 'No remaining amount to collect.', 'vanjorn-rental-pos' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $child_orders ) ) : ?>
			<table class="wp-list-table widefat fixed striped table-view-list orders wc-orders-list-table wc-orders-list-table-shop_order" style="margin-top: 10px;">
				<thead><tr><th><?php esc_html_e( 'Order', 'vanjorn-rental-pos' ); ?></th><th><?php esc_html_e( 'Type', 'vanjorn-rental-pos' ); ?></th><th><?php esc_html_e( 'Amount', 'vanjorn-rental-pos' ); ?></th><th><?php esc_html_e( 'Status', 'vanjorn-rental-pos' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $child_orders as $child_order ) : $payment_type = $child_order->get_meta( '_vanpos_payment_type' ); $payment_type_label = $this->get_payment_type_label( $payment_type ); $parent_id = $child_order->get_parent_id(); if ( ! $parent_id ) { $parent_id = $child_order->get_meta( '_vanpos_primary_order_id' ); } $parent_order = $parent_id ? wc_get_order( $parent_id ) : null; $parent_order_number = $parent_order ? $parent_order->get_order_number() : $parent_id; ?>
					<tr><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $child_order->get_id() ) ); ?>">#<?php echo esc_html( $child_order->get_order_number() ); ?></a><?php if ( $parent_id && $parent_order ) : ?><br><small style="color: #666;"><?php printf( esc_html__( 'Child order of %s', 'vanjorn-rental-pos' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $parent_id ) ) . '" style="color: #3858e9;">#' . esc_html( $parent_order_number ) . '</a>' ); ?></small><?php endif; ?></td><td><?php echo esc_html( $payment_type_label ); ?></td><td><?php echo wp_kses_post( $child_order->get_formatted_order_total() ); ?></td><td><mark class="order-status status-<?php echo esc_attr( $child_order->get_status() ); ?>"><?php echo esc_html( wc_get_order_status_name( $child_order->get_status() ) ); ?></mark></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No payment orders created yet.', 'vanjorn-rental-pos' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_order_meta_debug_box( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} else {
			$order_id = is_object( $post_or_order ) ? $post_or_order->ID : absint( $post_or_order );
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'vanjorn-rental-pos' ) . '</p>';
			return;
		}
		$prefixes = array( '_vanpos_', '_payment_', '_is_short_term_', '_automatewoo_', '_reminder_', '_security_deposit_', '_remaining_' );
		$matched = array();
		foreach ( $order->get_meta_data() as $meta ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $meta->key, $prefix ) ) {
					$matched[ $meta->key ] = $meta->value;
					break;
				}
			}
		}
		$item_meta = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$item_matched = array();
			foreach ( $item->get_meta_data() as $meta ) {
				foreach ( array_merge( $prefixes, array( 'vanpos_', 'wcrp_' ) ) as $prefix ) {
					if ( 0 === strpos( $meta->key, $prefix ) ) {
						$item_matched[ $meta->key ] = $meta->value;
						break;
					}
				}
			}
			if ( ! empty( $item_matched ) ) {
				$item_meta[ $item_id ] = array( 'name' => $item->get_name(), 'meta' => $item_matched );
			}
		}
		ksort( $matched );
		if ( empty( $matched ) && empty( $item_meta ) ) {
			echo '<p class="description">' . esc_html__( 'No VanPOS meta found on this order.', 'vanjorn-rental-pos' ) . '</p>';
			return;
		}
		if ( ! empty( $matched ) ) : ?>
		<h4 style="margin: 0 0 8px;"><?php esc_html_e( 'Order Meta', 'vanjorn-rental-pos' ); ?> <span style="color: #888; font-weight: normal;">(<?php echo count( $matched ); ?>)</span></h4>
		<table class="widefat fixed striped" style="margin-bottom: 16px;"><thead><tr><th style="width: 40%;"><?php esc_html_e( 'Key', 'vanjorn-rental-pos' ); ?></th><th><?php esc_html_e( 'Value', 'vanjorn-rental-pos' ); ?></th></tr></thead><tbody>
		<?php foreach ( $matched as $key => $value ) : ?><tr><td><code style="font-size: 12px;"><?php echo esc_html( $key ); ?></code></td><td><?php echo esc_html( is_array( $value ) || is_object( $value ) ? wp_json_encode( $value, JSON_PRETTY_PRINT ) : (string) $value ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
		<?php endif;
		if ( ! empty( $item_meta ) ) : foreach ( $item_meta as $item_id => $data ) : ksort( $data['meta'] ); ?>
		<h4 style="margin: 12px 0 8px;"><?php printf( esc_html__( 'Item: %1$s (#%2$d)', 'vanjorn-rental-pos' ), esc_html( $data['name'] ), $item_id ); ?> <span style="color: #888; font-weight: normal;">(<?php echo count( $data['meta'] ); ?>)</span></h4>
		<table class="widefat fixed striped" style="margin-bottom: 16px;"><thead><tr><th style="width: 40%;"><?php esc_html_e( 'Key', 'vanjorn-rental-pos' ); ?></th><th><?php esc_html_e( 'Value', 'vanjorn-rental-pos' ); ?></th></tr></thead><tbody>
		<?php foreach ( $data['meta'] as $key => $value ) : ?><tr><td><code style="font-size: 12px;"><?php echo esc_html( $key ); ?></code></td><td><?php echo esc_html( is_array( $value ) || is_object( $value ) ? wp_json_encode( $value, JSON_PRETTY_PRINT ) : (string) $value ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
		<?php endforeach; endif;
	}

	public function render_create_child_order_button( $order ) {
		if ( ! $order ) {
			global $theorder;
			if ( isset( $theorder ) && $theorder instanceof WC_Order ) {
				$order = $theorder;
			} elseif ( isset( $_GET['id'] ) ) {
				$order = wc_get_order( absint( $_GET['id'] ) );
			}
		}
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) { return; }
		$is_rental_order = $this->is_primary_rental_order( $order );
		if ( $is_rental_order ) {
			$this->update_missing_rental_metadata( $order );
			$order = wc_get_order( $order->get_id() );
		} else { return; }
		if ( class_exists( 'VanPOS_Order_Manager' ) && VanPOS_Order_Manager::has_payment_order( $order->get_id(), 'remaining' ) ) { return; }
		$remaining_amount = (float) $order->get_meta( '_vanpos_remaining_payment' );
		if ( $remaining_amount <= 0 ) {
			$total_price = (float) $order->get_meta( '_vanpos_total_price' );
			$initial_payment = (float) $order->get_meta( '_vanpos_initial_payment' );
			if ( $total_price > 0 && $initial_payment > 0 ) { $remaining_amount = $total_price - $initial_payment; }
		}
		if ( $remaining_amount <= 0 ) { return; }
		?>
		<button type="button" class="button vanpos-create-child-order" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-payment-type="remaining"><?php esc_html_e( 'Create Remaining Payment Order', 'vanjorn-rental-pos' ); ?></button>
		<?php
	}

	/**
	 * Delegate to the single source of truth: VanPOS_Order_Manager::is_primary_rental_order().
	 *
	 * Previously delegated to VanPOS_Order_Deletion, which carried its own copy of the
	 * detection logic with a subtly looser order_type gate — only 'payment_order' triggered
	 * the early false, while Order Manager returns false for any non-empty non-'primary_rental'
	 * type. Both are now routed through Order Manager so the rules live in one place.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_primary_rental_order( $order ) {
		return class_exists( 'VanPOS_Order_Manager' )
			&& VanPOS_Order_Manager::is_primary_rental_order( $order );
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

	/**
	 * Delegate to the single source of truth: VanPOS_Order_Manager::get_payment_type_label().
	 *
	 * The previous local copy duplicated the label strings and had already drifted
	 * (Title Case here vs. Sentence case in Order Manager, and different fallback
	 * behaviour). Now that Order Manager exposes this as public static (2026-06),
	 * we delegate directly. The ucfirst() fallback is preserved for the case where
	 * VanPOS_Order_Manager is unavailable.
	 *
	 * @param string $payment_type Payment type.
	 * @return string
	 */
	private function get_payment_type_label( $payment_type ) {
		return class_exists( 'VanPOS_Order_Manager' )
			? VanPOS_Order_Manager::get_payment_type_label( $payment_type )
			: ucfirst( $payment_type );
	}

	private function render_parent_order_info( $order ) {
		$parent_id = $order->get_parent_id();
		if ( ! $parent_id ) {
			$parent_id = $order->get_meta( '_vanpos_primary_order_id' );
		}
		if ( ! $parent_id ) { return; }
		$parent_order = wc_get_order( $parent_id );
		if ( ! $parent_order ) { return; }
		$payment_type = $order->get_meta( '_vanpos_payment_type' );
		$payment_type_label = $this->get_payment_type_label( $payment_type );
		?>
		<div class="vanpos-child-orders-section" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
			<h3><?php esc_html_e( 'Parent Order Information', 'vanjorn-rental-pos' ); ?></h3>
			<p><strong><?php esc_html_e( 'This is a child payment order of:', 'vanjorn-rental-pos' ); ?></strong><br>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $parent_id ) ); ?>" style="font-size: 14px; font-weight: 600;">
					<?php printf( esc_html__( 'Parent Order #%s', 'vanjorn-rental-pos' ), esc_html( $parent_order->get_order_number() ) ); ?>
				</a>
			</p>
			<p><strong><?php esc_html_e( 'Payment Type:', 'vanjorn-rental-pos' ); ?></strong> <?php echo esc_html( $payment_type_label ); ?></p>
			<?php $booking_reference = $parent_order->get_meta( '_vanpos_booking_reference' ); if ( $booking_reference ) : ?>
				<p><strong><?php esc_html_e( 'Booking Reference:', 'vanjorn-rental-pos' ); ?></strong> <?php echo esc_html( $booking_reference ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

