<?php
/**
 * Order Display Enhancement for VAN-Jorn Rental POS
 * Displays driver details and child order information on order pages
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order Display Class
 */
class VanPOS_Order_Display {

	/**
	 * Initialize order display functionality
	 */
	public static function init() {
		// Note: Driver details are now handled by theme template override
		// Template: woocommerce/order/order-details.php
		// This template is used for both order view page and order received (thankyou) page
		
		// Display child orders on parent order view
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'display_child_orders_section' ), 10, 1 );
		
		// Display parent order info on child order view
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'display_parent_order_info' ), 10, 1 );
		
		// Add Security Deposit to order totals
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'add_security_deposit_to_order_totals' ), 10, 2 );

		// Fix subtotal/item total display when prices include tax (prevents double tax: €1.414 instead of €1.250)
		add_filter( 'woocommerce_order_amount_line_subtotal', array( __CLASS__, 'fix_deposit_line_subtotal_display' ), 10, 5 );
		
		// Hide internal payment type meta keys from order item meta display
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_payment_type_meta' ), 10, 1 );
		
		// Enqueue styles
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );

		// Remove cancel action for security deposit and remaining payment orders
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'remove_cancel_for_payment_orders' ), 10, 2 );

		// Prevent cancellation of payment orders even if URL is accessed directly
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( __CLASS__, 'prevent_payment_order_cancellation' ), 10, 2 );
	}

	/**
	 * Enqueue styles for order display
	 */
	public static function enqueue_styles() {
		// Enqueue on account pages and order received page
		if ( is_account_page() || is_wc_endpoint_url( 'order-received' ) ) {
			wp_enqueue_style(
				'vanpos-order-display',
				VANPOS_PLUGIN_URL . 'frontend/css/order-display.css',
				array(),
				VANPOS_VERSION
			);
		}
	}

	/**
	 * Display driver details section in two separate tables
	 * Table 1: Billing + Primary Driver Details
	 * Table 2: Second Driver Details (if exists)
	 *
	 * @param int|WC_Order $order_id Order ID or Order object.
	 * @return void
	 */
	public static function display_driver_details( $order_id ) {
		// Static variable to prevent duplicate output
		static $displayed_orders = array();
		
		// Handle both order ID and order object
		if ( is_a( $order_id, 'WC_Order' ) ) {
			$order = $order_id;
			$order_id = $order->get_id();
		} else {
			$order = wc_get_order( $order_id );
		}
		
		if ( ! $order ) {
			return;
		}

		// Prevent duplicate display if called from multiple hooks
		if ( isset( $displayed_orders[ $order_id ] ) ) {
			return;
		}
		$displayed_orders[ $order_id ] = true;

		// Get billing details
		$billing_first_name = $order->get_billing_first_name();
		$billing_last_name = $order->get_billing_last_name();
		$middle_name = $order->get_meta( '_billing_middle_name' );
		$billing_email = $order->get_billing_email();
		$billing_phone = $order->get_billing_phone();
		$billing_address = $order->get_formatted_billing_address();
		
		// Get primary driver details
		$dob = $order->get_meta( '_driver_date_of_birth' );
		$license_issue = $order->get_meta( '_driver_license_issue_date' );
		$license_obtained = $order->get_meta( '_driver_license_obtained_date' );
		
		// Get second driver details
		$second_driver_name = $order->get_meta( '_second_driver_name' );
		$second_driver_dob = $order->get_meta( '_second_driver_date_of_birth' );
		$second_driver_license_issue = $order->get_meta( '_second_driver_license_issue_date' );
		$second_driver_license_obtained = $order->get_meta( '_second_driver_license_obtained_date' );

		// Check if we have any data to display - be more lenient, show if there's any billing info
		$has_data = $billing_first_name || $billing_last_name || $billing_email || $billing_phone || $billing_address || $dob || $license_issue || $license_obtained;
		if ( ! $has_data && ! $second_driver_name ) {
			return;
		}

		// Build primary driver name
		$primary_driver_name = trim( $billing_first_name . ' ' . ( $middle_name ? $middle_name . ' ' : '' ) . $billing_last_name );

		// Format dates for display
		$date_format = get_option( 'date_format' );

		?>
		<section class="vanpos-driver-details-section">
			<!-- Table 1: Billing & Primary Driver Details -->
			<h2 class="vanpos-section-title"><?php esc_html_e( 'Billing & Driver Details', 'vanjorn-rental-pos' ); ?></h2>
			
			<?php
			self::render_detail_table(
				array(
					__( 'Name', 'vanjorn-rental-pos' )                 => $primary_driver_name,
					__( 'Email', 'vanjorn-rental-pos' )                => $billing_email,
					__( 'Phone', 'vanjorn-rental-pos' )                => $billing_phone,
					__( 'Address', 'vanjorn-rental-pos' )              => $billing_address ? wp_kses_post( nl2br( $billing_address ) ) : '',
					__( 'Date of Birth', 'vanjorn-rental-pos' )        => $dob ? date_i18n( $date_format, strtotime( $dob ) ) : '',
					__( 'License Issue Date', 'vanjorn-rental-pos' )   => $license_issue ? date_i18n( $date_format, strtotime( $license_issue ) ) : '',
					__( 'License Obtained Date', 'vanjorn-rental-pos' ) => $license_obtained ? date_i18n( $date_format, strtotime( $license_obtained ) ) : '',
				)
			);
			?>

			<!-- Table 2: Second Driver Details (only if exists) -->
			<?php if ( $second_driver_name ) : ?>
				<h2 class="vanpos-section-title vanpos-second-driver-title"><?php esc_html_e( 'Second Driver Details', 'vanjorn-rental-pos' ); ?></h2>
				
				<?php
				self::render_detail_table(
					array(
						__( 'Name', 'vanjorn-rental-pos' )                 => $second_driver_name,
						__( 'Date of Birth', 'vanjorn-rental-pos' )        => $second_driver_dob ? date_i18n( $date_format, strtotime( $second_driver_dob ) ) : '',
						__( 'License Issue Date', 'vanjorn-rental-pos' )   => $second_driver_license_issue ? date_i18n( $date_format, strtotime( $second_driver_license_issue ) ) : '',
						__( 'License Obtained Date', 'vanjorn-rental-pos' ) => $second_driver_license_obtained ? date_i18n( $date_format, strtotime( $second_driver_license_obtained ) ) : '',
					),
					'vanpos-second-driver-table'
				);
				?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render a driver-details table from an associative array of label => value pairs.
	 * Rows with empty values are skipped. Values are escaped unless they contain
	 * pre-sanitized HTML (e.g. the address field which uses wp_kses_post + nl2br).
	 *
	 * @param array  $rows        Associative array of label => value.
	 * @param string $extra_class Optional additional CSS class for the table element.
	 */
	private static function render_detail_table( $rows, $extra_class = '' ) {
		$table_class = 'woocommerce-table woocommerce-table--driver-details shop_table shop_table_responsive vanpos-driver-details-table';
		if ( $extra_class ) {
			$table_class .= ' ' . $extra_class;
		}
		?>
		<table class="<?php echo esc_attr( $table_class ); ?>">
			<thead>
				<tr>
					<th class="driver-field"><?php esc_html_e( 'Field', 'vanjorn-rental-pos' ); ?></th>
					<th class="driver-value"><?php esc_html_e( 'Value', 'vanjorn-rental-pos' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<?php if ( '' === $value || null === $value ) continue; ?>
					<tr>
						<td class="driver-field-label" data-label="<?php esc_attr_e( 'Field', 'vanjorn-rental-pos' ); ?>">
							<strong><?php echo esc_html( $label ); ?></strong>
						</td>
						<td class="driver-value-cell" data-label="<?php esc_attr_e( 'Value', 'vanjorn-rental-pos' ); ?>">
							<?php echo wp_kses_post( $value ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Display child orders section on parent order view
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function display_child_orders_section( $order ) {
		// Check if this is a primary rental order
		$order_type = $order->get_meta( '_vanpos_order_type' );
		if ( 'primary_rental' !== $order_type ) {
			return;
		}

		// Get child orders
		if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
			return;
		}

		$child_orders = VanPOS_Order_Manager::get_payment_orders( $order->get_id() );
		
		if ( empty( $child_orders ) ) {
			return;
		}

		?>
		<section class="vanpos-child-orders-section">
			<h2 class="vanpos-section-title"><?php esc_html_e( 'Payment Schedule', 'vanjorn-rental-pos' ); ?></h2>
			<p class="vanpos-section-description">
				<?php esc_html_e( 'The following payments are scheduled for this booking:', 'vanjorn-rental-pos' ); ?>
			</p>
			
			<table class="woocommerce-table woocommerce-table--payment-schedule shop_table shop_table_responsive vanpos-payment-schedule-table">
				<thead>
					<tr>
						<th class="payment-order-id"><?php esc_html_e( 'Order ID', 'vanjorn-rental-pos' ); ?></th>
						<th class="payment-amount"><?php esc_html_e( 'Amount', 'vanjorn-rental-pos' ); ?></th>
						<th class="payment-due-date"><?php esc_html_e( 'Due Date', 'vanjorn-rental-pos' ); ?></th>
						<th class="payment-status"><?php esc_html_e( 'Payment Status', 'vanjorn-rental-pos' ); ?></th>
						<th class="payment-actions"><?php esc_html_e( 'Actions', 'vanjorn-rental-pos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $child_orders as $child_order ) : ?>
						<?php
						// Skip refund objects — they lack methods like get_view_order_url()
						if ( $child_order instanceof WC_Order_Refund ) {
							continue;
						}
						$due_date = $child_order->get_meta( '_vanpos_due_date' );
						$payment_type = $child_order->get_meta( '_vanpos_payment_type' );
						$status = $child_order->get_status();
						$is_paid = in_array( $status, array( 'completed', 'processing' ), true );
						?>
						<tr class="vanpos-payment-row <?php echo $is_paid ? 'vanpos-payment-paid' : 'vanpos-payment-pending'; ?>">
							<td class="payment-order-id" data-title="<?php esc_attr_e( 'Order ID', 'vanjorn-rental-pos' ); ?>">
								<a href="<?php echo esc_url( $child_order->get_view_order_url() ); ?>">
									#<?php echo esc_html( $child_order->get_order_number() ); ?>
								</a>
							</td>
							<td class="payment-amount" data-title="<?php esc_attr_e( 'Amount', 'vanjorn-rental-pos' ); ?>">
								<strong><?php echo wp_kses_post( $child_order->get_formatted_order_total() ); ?></strong>
							</td>
							<td class="payment-due-date" data-title="<?php esc_attr_e( 'Due Date', 'vanjorn-rental-pos' ); ?>">
								<?php if ( $due_date ) : ?>
									<?php
									$due_datetime = new DateTime( $due_date );
									$now = new DateTime();
									$is_overdue = $due_datetime < $now && ! $is_paid;
									?>
									<span class="vanpos-due-date <?php echo $is_overdue ? 'vanpos-overdue' : ''; ?>">
										<?php echo esc_html( date_i18n( get_option( 'date_format' ), $due_datetime->getTimestamp() ) ); ?>
										<?php if ( $is_overdue ) : ?>
											<span class="vanpos-overdue-badge"><?php esc_html_e( 'Overdue', 'vanjorn-rental-pos' ); ?></span>
										<?php endif; ?>
									</span>
								<?php else : ?>
									<span class="vanpos-no-due-date">—</span>
								<?php endif; ?>
							</td>
							<td class="payment-status" data-title="<?php esc_attr_e( 'Payment Status', 'vanjorn-rental-pos' ); ?>">
								<span class="vanpos-status-badge vanpos-status-<?php echo esc_attr( $status ); ?>">
									<?php echo esc_html( wc_get_order_status_name( $status ) ); ?>
								</span>
							</td>
							<td class="payment-actions" data-title="<?php esc_attr_e( 'Actions', 'vanjorn-rental-pos' ); ?>">
								<?php if ( ! $is_paid ) : ?>
									<a href="<?php echo esc_url( $child_order->get_checkout_payment_url() ); ?>" class="button vanpos-pay-button">
										<?php esc_html_e( 'Pay Now', 'vanjorn-rental-pos' ); ?>
									</a>
								<?php else : ?>
									<span class="vanpos-paid-indicator"><?php esc_html_e( 'Paid', 'vanjorn-rental-pos' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Display parent order information on child order view
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function display_parent_order_info( $order ) {
		// Check if this is a payment order (child order)
		$order_type = $order->get_meta( '_vanpos_order_type' );
		if ( 'payment_order' !== $order_type ) {
			return;
		}

		// Get parent order ID
		$parent_id = $order->get_parent_id();
		if ( ! $parent_id ) {
			$parent_id = $order->get_meta( '_vanpos_primary_order_id' );
		}

		if ( ! $parent_id ) {
			return;
		}

		$parent_order = wc_get_order( $parent_id );
		if ( ! $parent_order ) {
			return;
		}

		$due_date = $order->get_meta( '_vanpos_due_date' );
		$payment_type = $order->get_meta( '_vanpos_payment_type' );
		
		?>
		<div class="vanpos-parent-order-notice">
			<div class="vanpos-notice-content">
				<div class="vanpos-notice-icon">
					<span class="material-icons">info</span>
				</div>
				<div class="vanpos-notice-text">
					<h3 class="vanpos-notice-title"><?php esc_html_e( 'Payment Order', 'vanjorn-rental-pos' ); ?></h3>
					<p class="vanpos-notice-message">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %1$s is the parent order number (bold), %2$s is a link to the parent order */
								__( 'This is a payment order for rental booking %1$s. %2$s', 'vanjorn-rental-pos' ),
								'<strong>#' . esc_html( $parent_order->get_order_number() ) . '</strong>',
								'<a href="' . esc_url( $parent_order->get_view_order_url() ) . '" class="vanpos-view-parent-link">' . esc_html__( 'View original booking', 'vanjorn-rental-pos' ) . '</a>'
							),
							array(
								'strong' => array(),
								'a'      => array( 'href' => array(), 'class' => array() ),
							)
						);
						?>
					</p>
					<?php if ( $due_date ) : ?>
						<?php
						$due_datetime = new DateTime( $due_date );
						$now = new DateTime();
						$is_overdue = $due_datetime < $now && ! in_array( $order->get_status(), array( 'completed', 'processing' ), true );
						?>
						<div class="vanpos-due-date-info <?php echo $is_overdue ? 'vanpos-overdue' : ''; ?>">
							<strong><?php esc_html_e( 'Due Date:', 'vanjorn-rental-pos' ); ?></strong>
							<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), $due_datetime->getTimestamp() ) ); ?></span>
							<?php if ( $is_overdue ) : ?>
								<span class="vanpos-overdue-badge"><?php esc_html_e( 'Overdue', 'vanjorn-rental-pos' ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Fix line subtotal display for deposit items when prices include tax.
	 * Prevents double-counting tax (e.g. €1.414 instead of €1.250).
	 *
	 * @param float    $subtotal Calculated subtotal.
	 * @param WC_Order $order Order object.
	 * @param object   $item Order item.
	 * @param bool     $inc_tax Whether to include tax.
	 * @param bool     $round Whether to round.
	 * @return float
	 */
	public static function fix_deposit_line_subtotal_display( $subtotal, $order, $item, $inc_tax, $round ) {
		if ( ! $inc_tax || get_option( 'woocommerce_prices_include_tax' ) !== 'yes' ) {
			return $subtotal;
		}
		$deposit_amount = $item->get_meta( '_vanpos_deposit_amount' );
		if ( empty( $deposit_amount ) ) {
			return $subtotal;
		}
		// Deposit already includes tax - return subtotal only, don't add subtotal_tax
		$correct = (float) $item->get_subtotal();
		return $round ? wc_round_tax_total( $correct ) : $correct;
	}

	/**
	 * Add Security Deposit to order totals
	 *
	 * @param array    $total_rows Order totals.
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	public static function add_security_deposit_to_order_totals( $total_rows, $order ) {
		// Only apply to VanPOS primary rental orders
		$order_type = $order->get_meta( '_vanpos_order_type' );
		if ( 'primary_rental' !== $order_type ) {
			return $total_rows;
		}

		// Get security deposit amount from order meta
		$security_deposit_amount = $order->get_meta( '_vanpos_security_deposit_payment' );
		
		// If not in order meta, get from settings
		if ( empty( $security_deposit_amount ) ) {
			$security_deposit_product_id = VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' );
			if ( ! empty( $security_deposit_product_id ) ) {
				$security_deposit_product = wc_get_product( $security_deposit_product_id );
				if ( $security_deposit_product ) {
					$security_deposit_amount = (float) $security_deposit_product->get_price();
				}
			}
		}

		if ( empty( $security_deposit_amount ) || $security_deposit_amount <= 0 ) {
			return $total_rows;
		}

		// Build a stable financial summary so totals are easy to verify in order details.
		// This is display-only; payment logic remains unchanged.
		$paid_now_amount  = (float) $order->get_total();
		$remaining_amount = (float) $order->get_meta( '_vanpos_remaining_payment' );
		if ( $remaining_amount < 0 ) {
			$remaining_amount = 0;
		}

		// Booking total (incl. VAT) = the real amounts the customer pays across
		// installments: what is paid now (deposit + fees, or full payment) plus what
		// is paid later (remaining). Both are gross, tax-inclusive figures, so their
		// sum is the true booking total — and because it reads actual order amounts,
		// it stays correct regardless of how the rental is split.
		//
		// We deliberately do NOT read _vanpos_total_price: it is derived from the cart
		// deposit breakdown (deposit_amount + second_payment) whose VAT basis is not
		// consistent across orders (gross on some, net on others), so it is unreliable
		// as a display source. The installment sum avoids that entirely.
		//
		// Note: on a legacy order affected by the historical deposit double-VAT (before
		// the line-item tax fix), get_total() reflects the inflated amount that was
		// actually charged. That is accurate until the order is reconciled; afterwards,
		// and for all new orders, this row shows the correct booking total.
		$booking_total = $paid_now_amount + $remaining_amount;

		// Find the position of 'order_total' to insert before it
		$new_total_rows = array();
		foreach ( $total_rows as $key => $row ) {
			if ( 'order_total' === $key ) {
				$new_total_rows['vanpos_booking_total'] = array(
					'label' => __( 'Booking total (incl. VAT):', 'vanjorn-rental-pos' ),
					'value' => wc_price( $booking_total ),
				);
				$new_total_rows['vanpos_paid_now'] = array(
					'label' => __( 'Paid now:', 'vanjorn-rental-pos' ),
					'value' => wc_price( $paid_now_amount ),
				);
				$new_total_rows['vanpos_pay_later'] = array(
					'label' => __( 'Pay later:', 'vanjorn-rental-pos' ),
					'value' => wc_price( max( 0, $remaining_amount ) ),
				);

				// Insert Security Deposit before order total
				$deposit_days = 14;
				if ( class_exists( 'VanPOS_Functions' ) ) {
					$settings = VanPOS_Functions::get_settings();
					$deposit_days = isset( $settings['vanpos_security_deposit_days_before_pickup'] ) ? (int) $settings['vanpos_security_deposit_days_before_pickup'] : 14;
				}
				$new_total_rows['security_deposit'] = array(
					'label' => __( 'Security Deposit:', 'vanjorn-rental-pos' ),
					'value' => wc_price( $security_deposit_amount ) . '<br><small style="color: #666;">' . sprintf(
						/* translators: %d is the number of days before pickup */
						esc_html__( 'Charged %d days before pickup, refunded after return', 'vanjorn-rental-pos' ),
						$deposit_days
					) . '</small>',
				);
			}
			$new_total_rows[ $key ] = $row;
		}

		return $new_total_rows;
	}

	/**
	 * Hide payment type meta keys from order item meta display
	 *
	 * @param array $hidden_meta Array of hidden meta keys.
	 * @return array
	 */
	public static function hide_payment_type_meta( $hidden_meta ) {
		// Add internal payment type meta keys to hidden list
		$hidden_meta[] = '_vanpos_payment_type';
		$hidden_meta[] = '_vanpos_payment_description';
		
		return $hidden_meta;
	}

	/**
	 * Check if an order is a child payment order (security deposit or remaining payment).
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private static function is_payment_order( $order ) {
		$order_type   = $order->get_meta( '_vanpos_order_type' );
		$payment_type = $order->get_meta( '_vanpos_payment_type' );

		if ( 'payment_order' === $order_type ) {
			return true;
		}
		if ( VanPOS_Order_Manager::is_payment_order_type( $payment_type ) ) {
			return true;
		}
		if ( $order->get_parent_id() || $order->get_meta( '_vanpos_primary_order_id' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Remove cancel action for security deposit and remaining payment orders
	 *
	 * @param array    $actions Order actions.
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	public static function remove_cancel_for_payment_orders( $actions, $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return $actions;
		}

		if ( self::is_payment_order( $order ) && isset( $actions['cancel'] ) ) {
			unset( $actions['cancel'] );
		}

		return $actions;
	}

	/**
	 * Prevent cancellation of security deposit and remaining payment orders
	 *
	 * @param array    $statuses Valid order statuses for cancellation.
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	public static function prevent_payment_order_cancellation( $statuses, $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return $statuses;
		}

		if ( self::is_payment_order( $order ) ) {
			return array();
		}

		return $statuses;
	}
}
