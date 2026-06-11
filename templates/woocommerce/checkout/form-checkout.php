<?php
/**
 * Checkout Form - Custom Two Column Layout
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/form-checkout.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 9.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_before_checkout_form', $checkout );

// If checkout registration is disabled and not logged in, the user cannot checkout.
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}

?>

<form name="checkout" method="post" class="checkout woocommerce-checkout vanjorn-checkout-two-column" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">

	<div class="vanjorn-checkout-wrapper">
		
		<!-- Left Column: Billing and Shipping Forms -->
		<div class="vanjorn-checkout-left-column">
			<?php if ( $checkout->get_checkout_fields() ) : ?>

				<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

				<div class="vanjorn-checkout-forms">
					<!-- Billing Section -->
					<div class="vanjorn-checkout-section">
						<h3><?php esc_html_e( 'Billing Details', 'woocommerce' ); ?></h3>
						<?php do_action( 'woocommerce_checkout_billing' ); ?>
					</div>

					<!-- Shipping Section -->
					<?php if ( ! wc_ship_to_billing_address_only() && WC()->cart->needs_shipping_address() ) : ?>
						<div class="vanjorn-checkout-section">
							<h3><?php esc_html_e( 'Shipping Details', 'woocommerce' ); ?></h3>
							<?php do_action( 'woocommerce_checkout_shipping' ); ?>
						</div>
					<?php endif; ?>

					<!-- Order Notes Section -->
					<?php if ( apply_filters( 'woocommerce_enable_order_notes_field', 'yes' === get_option( 'woocommerce_enable_order_comments', 'yes' ) ) ) : ?>
						<div class="vanjorn-checkout-section">
							<h3><?php esc_html_e( 'Order notes', 'woocommerce' ); ?></h3>
							<?php do_action( 'woocommerce_before_order_notes', $checkout ); ?>
							<div class="woocommerce-additional-fields__field-wrapper">
								<?php foreach ( $checkout->get_checkout_fields( 'order' ) as $key => $field ) : ?>
									<?php woocommerce_form_field( $key, $field, $checkout->get_value( $key ) ); ?>
								<?php endforeach; ?>
							</div>
							<?php do_action( 'woocommerce_after_order_notes', $checkout ); ?>
						</div>
					<?php endif; ?>
				</div>

				<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

			<?php endif; ?>
		</div>

		<!-- Right Column: Order Review and Payment -->
		<div class="vanjorn-checkout-right-column">
			<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
			
			<div class="vanjorn-checkout-order-review">
				<h3 id="order_review_heading"><?php esc_html_e( 'Your order', 'woocommerce' ); ?></h3>
				
				<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

				<div id="order_review" class="woocommerce-checkout-review-order">
					<?php do_action( 'woocommerce_checkout_order_review' ); ?>
				</div>

				<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
			</div>
		</div>

	</div>

</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
