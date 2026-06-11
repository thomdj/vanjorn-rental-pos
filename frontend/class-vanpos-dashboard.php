<?php
/**
 * Dashboard Enhancement for VAN-Jorn Rental POS
 * Displays active rental bookings and stats on My Account dashboard
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard Class
 */
class VanPOS_Dashboard {

	/**
	 * Initialize dashboard functionality
	 */
	public static function init() {
		// Display custom greeting
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'display_custom_greeting' ), 1 );
		
		// Display active rentals on dashboard
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'display_active_rentals' ), 5 );
		
		// Enqueue styles when dashboard action fires (most reliable method)
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'enqueue_styles' ), 1 );
		
		// Also try to enqueue on wp_enqueue_scripts as fallback
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles_fallback' ), 20 );
	}

	/**
	 * Display custom greeting
	 */
	public static function display_custom_greeting() {
		// Get current user
		$current_user = wp_get_current_user();
		if ( ! $current_user ) {
			return;
		}

		// Display nice greeting
		?>
		<div class="vanpos-dashboard-greeting">
			<h2 class="vanpos-greeting-title">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s is the user display name wrapped in a <span> */
						__( 'Welcome back, %s!', 'vanjorn-rental-pos' ),
						'<span class="vanpos-user-name">' . esc_html( $current_user->display_name ) . '</span>'
					),
					array( 'span' => array( 'class' => array() ) )
				);
				?>
			</h2>
		</div>
		<?php
	}

	/**
	 * Enqueue styles for dashboard (called from dashboard action)
	 */
	public static function enqueue_styles() {
		wp_enqueue_style( 'material-icons', 'https://fonts.googleapis.com/icon?family=Material+Icons', array(), null );
		wp_enqueue_style(
			'vanpos-dashboard',
			VANPOS_PLUGIN_URL . 'frontend/css/dashboard.css',
			array(),
			VANPOS_VERSION
		);
	}

	/**
	 * Fallback method to enqueue styles (called from wp_enqueue_scripts)
	 */
	public static function enqueue_styles_fallback() {
		if ( ! is_account_page() ) {
			return;
		}

		// Check if we're on the dashboard page (no WooCommerce endpoint active)
		global $wp;
		
		// Dashboard is the default when no WooCommerce endpoint is active
		$wc_endpoints = wc_get_account_menu_items();
		$is_dashboard = true;
		foreach ( array_keys( $wc_endpoints ) as $endpoint ) {
			if ( 'dashboard' === $endpoint ) {
				continue;
			}
			if ( isset( $wp->query_vars[ $endpoint ] ) ) {
				$is_dashboard = false;
				break;
			}
		}

		if ( $is_dashboard ) {
			wp_enqueue_style( 'material-icons', 'https://fonts.googleapis.com/icon?family=Material+Icons', array(), null );
			wp_enqueue_style(
				'vanpos-dashboard',
				VANPOS_PLUGIN_URL . 'frontend/css/dashboard.css',
				array(),
				VANPOS_VERSION
			);
		}
	}

	/**
	 * Get active rental orders for current customer
	 *
	 * @return array Array of active rental orders
	 */
	private static function get_active_rental_orders() {
		$customer_id = get_current_user_id();
		if ( ! $customer_id ) {
			return array();
		}

		// Get all primary rental orders for this customer
		$orders = wc_get_orders( array(
			'customer_id' => $customer_id,
			'meta_key'    => '_vanpos_order_type',
			'meta_value'  => 'primary_rental',
			'limit'       => -1,
			'orderby'     => 'date',
			'order'       => 'DESC',
		) );

		$active_orders = array();
		$current_date = new DateTime();
		$current_date->setTime( 0, 0, 0 );

		foreach ( $orders as $order ) {
			// Get return date from order meta or item meta
			$return_date = $order->get_meta( '_vanpos_return_date' );
			
			// If not in order meta, check item meta
			if ( empty( $return_date ) ) {
				foreach ( $order->get_items() as $item ) {
					$return_date = $item->get_meta( 'vanpos_return_date' );
					if ( empty( $return_date ) ) {
						$return_date = $item->get_meta( 'wcrp_rental_products_rent_to' );
					}
					if ( ! empty( $return_date ) ) {
						break;
					}
				}
			}

			// Only include orders where return date is in the future
			if ( ! empty( $return_date ) ) {
				$return_datetime = new DateTime( $return_date );
				$return_datetime->setTime( 0, 0, 0 );
				
				if ( $return_datetime >= $current_date ) {
					$active_orders[] = $order;
				}
			}
		}

		return $active_orders;
	}

	/**
	 * Get dashboard stats
	 *
	 * @param array $active_rentals Pre-fetched active rental orders (avoids duplicate query).
	 * @return array Stats array including 'child_orders_cache' keyed by parent order ID.
	 */
	private static function get_dashboard_stats( $active_rentals = array() ) {
		$customer_id = get_current_user_id();
		if ( ! $customer_id ) {
			return array();
		}

		// Get all orders for stats
		$all_orders = wc_get_orders( array(
			'customer_id' => $customer_id,
			'limit'       => -1,
			'return'      => 'ids',
		) );

		// Get pending payment orders and cache child orders for template reuse
		$pending_payments    = 0;
		$child_orders_cache  = array();
		foreach ( $active_rentals as $order ) {
			if ( ! class_exists( 'VanPOS_Order_Manager' ) ) {
				continue;
			}
			$child_orders = VanPOS_Order_Manager::get_payment_orders( $order->get_id() );
			$child_orders_cache[ $order->get_id() ] = $child_orders;
			foreach ( $child_orders as $child_order ) {
				if ( in_array( $child_order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
					$pending_payments++;
				}
			}
		}

		return array(
			'total_orders'       => count( $all_orders ),
			'active_bookings'    => count( $active_rentals ),
			'pending_payments'   => $pending_payments,
			'child_orders_cache' => $child_orders_cache,
		);
	}

	/**
	 * Get order details for display
	 *
	 * @param WC_Order $order           Order object.
	 * @param array    $cached_children Optional pre-fetched child orders to avoid duplicate DB query.
	 * @return array Order details
	 */
	private static function get_order_display_data( $order, $cached_children = null ) {
		// Get pickup and return dates
		$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
		$return_date = $order->get_meta( '_vanpos_return_date' );
		
		// Get from item meta if not in order meta
		if ( empty( $pickup_date ) || empty( $return_date ) ) {
			foreach ( $order->get_items() as $item ) {
				if ( empty( $pickup_date ) ) {
					$pickup_date = $item->get_meta( 'vanpos_pickup_date' );
					if ( empty( $pickup_date ) ) {
						$pickup_date = $item->get_meta( 'wcrp_rental_products_rent_from' );
					}
				}
				if ( empty( $return_date ) ) {
					$return_date = $item->get_meta( 'vanpos_return_date' );
					if ( empty( $return_date ) ) {
						$return_date = $item->get_meta( 'wcrp_rental_products_rent_to' );
					}
				}
				if ( ! empty( $pickup_date ) && ! empty( $return_date ) ) {
					break;
				}
			}
		}

		// Get vehicle/product name
		$vehicle_name = '';
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				$vehicle_name = $product->get_name();
				break;
			}
		}

		// Get payment information
		$total_price = (float) $order->get_meta( '_vanpos_total_price' );
		if ( $total_price <= 0 ) {
			$total_price = (float) $order->get_subtotal();
		}

		$initial_payment = (float) $order->get_total();
		$remaining_amount = 0;
		
		// Get remaining amount from item meta
		foreach ( $order->get_items() as $item ) {
			$item_remaining = (float) $item->get_meta( '_vanpos_remaining_amount' );
			if ( $item_remaining > 0 ) {
				$remaining_amount += $item_remaining;
			}
		}

		// Fallback to order meta
		if ( $remaining_amount <= 0 ) {
			$remaining_amount = (float) $order->get_meta( '_vanpos_remaining_payment' );
		}

		// If still no remaining amount, calculate it
		if ( $remaining_amount <= 0 && $total_price > 0 ) {
			$remaining_amount = $total_price - $initial_payment;
		}

		// Get due date from child order
		$due_date = null;
		if ( class_exists( 'VanPOS_Order_Manager' ) ) {
			$child_orders = $cached_children !== null ? $cached_children : VanPOS_Order_Manager::get_payment_orders( $order->get_id() );
			foreach ( $child_orders as $child_order ) {
				$child_due_date = $child_order->get_meta( '_vanpos_due_date' );
				if ( $child_due_date ) {
					$due_date = $child_due_date;
					break;
				}
			}
		}

		// Calculate due date if not set (7 days before pickup)
		if ( ! $due_date && $pickup_date ) {
			$pickup_datetime = new DateTime( $pickup_date );
			$due_datetime = clone $pickup_datetime;
			$due_datetime->modify( '-7 days' );
			$due_date = $due_datetime->format( 'Y-m-d' );
		}

		return array(
			'order_id'          => $order->get_id(),
			'order_number'      => $order->get_order_number(),
			'order_date'        => $order->get_date_created(),
			'pickup_date'       => $pickup_date,
			'return_date'       => $return_date,
			'vehicle_name'      => $vehicle_name,
			'total_price'       => $total_price,
			'initial_payment'   => $initial_payment,
			'remaining_amount' => $remaining_amount,
			'due_date'          => $due_date,
			'status'            => $order->get_status(),
			'view_url'          => $order->get_view_order_url(),
		);
	}

	/**
	 * Display active rentals section on dashboard
	 */
	public static function display_active_rentals() {
		$active_orders = self::get_active_rental_orders();
		$stats = self::get_dashboard_stats( $active_orders );
		$deposit_percentage = (float) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 );

		?>
		<div class="vanpos-dashboard-wrapper">
			<!-- Stats Section -->
			<div class="vanpos-dashboard-stats">
				<div class="vanpos-stat-card">
					<div class="vanpos-stat-icon"><span class="material-icons">inventory_2</span></div>
					<div class="vanpos-stat-content">
						<div class="vanpos-stat-value"><?php echo esc_html( $stats['total_orders'] ); ?></div>
						<div class="vanpos-stat-label"><?php esc_html_e( 'Total Orders', 'vanjorn-rental-pos' ); ?></div>
					</div>
					<a href="<?php echo esc_url( wc_get_endpoint_url( 'orders' ) ); ?>" class="vanpos-stat-link"><?php esc_html_e( 'View All', 'vanjorn-rental-pos' ); ?> <span class="material-icons">chevron_right</span></a>
				</div>

				<div class="vanpos-stat-card">
					<div class="vanpos-stat-icon"><span class="material-icons">directions_bus</span></div>
					<div class="vanpos-stat-content">
						<div class="vanpos-stat-value"><?php echo esc_html( $stats['active_bookings'] ); ?></div>
						<div class="vanpos-stat-label"><?php esc_html_e( 'Active Bookings', 'vanjorn-rental-pos' ); ?></div>
					</div>
				</div>

				<div class="vanpos-stat-card">
					<div class="vanpos-stat-icon"><span class="material-icons">credit_card</span></div>
					<div class="vanpos-stat-content">
						<div class="vanpos-stat-value"><?php echo esc_html( $stats['pending_payments'] ); ?></div>
						<div class="vanpos-stat-label"><?php esc_html_e( 'Pending Payments', 'vanjorn-rental-pos' ); ?></div>
					</div>
					<?php if ( $stats['pending_payments'] > 0 ) : ?>
						<a href="<?php echo esc_url( wc_get_endpoint_url( 'orders' ) ); ?>" class="vanpos-stat-link"><?php esc_html_e( 'Pay Now', 'vanjorn-rental-pos' ); ?> <span class="material-icons">chevron_right</span></a>
					<?php endif; ?>
				</div>
			</div>

			<!-- Active Rentals Section -->
			<?php if ( ! empty( $active_orders ) ) : ?>
				<div class="vanpos-active-rentals-section">
					<h2 class="vanpos-section-title"><?php esc_html_e( 'Active Rental Bookings', 'vanjorn-rental-pos' ); ?></h2>
					
					<div class="vanpos-rental-cards">
						<?php foreach ( $active_orders as $order ) : ?>
							<?php
							$cached_children = isset( $stats['child_orders_cache'][ $order->get_id() ] ) ? $stats['child_orders_cache'][ $order->get_id() ] : null;
							$order_data = self::get_order_display_data( $order, $cached_children );
							$pickup_datetime = $order_data['pickup_date'] ? new DateTime( $order_data['pickup_date'] ) : null;
							$return_datetime = $order_data['return_date'] ? new DateTime( $order_data['return_date'] ) : null;
							$due_datetime = $order_data['due_date'] ? new DateTime( $order_data['due_date'] ) : null;
							$current_date = new DateTime();
							$is_overdue = $due_datetime && $due_datetime < $current_date && $order_data['remaining_amount'] > 0;
							?>
							<div class="vanpos-order-card">
								<div class="vanpos-order-header">
									<div class="vanpos-order-id">
										<?php
										printf(
											/* translators: %s is the order number */
											esc_html__( 'Booking ID: #%s', 'vanjorn-rental-pos' ),
											esc_html( $order_data['order_number'] )
										);
										?>
									</div>
									<?php
									// Determine display status - show "In Progress" for active rentals
									$display_status = $order_data['status'];
									$display_status_label = wc_get_order_status_name( $display_status );
									
									// If order is completed/processing and rental hasn't ended, show as "In Progress"
									if ( in_array( $order_data['status'], array( 'completed', 'processing' ), true ) && $return_datetime ) {
										$current_date = new DateTime();
										if ( $return_datetime >= $current_date ) {
											$display_status = 'in-progress';
											$display_status_label = __( 'In Progress', 'vanjorn-rental-pos' );
										}
									}
									?>
									<div class="vanpos-status-badge vanpos-status-<?php echo esc_attr( $display_status ); ?>">
										<?php echo esc_html( $display_status_label ); ?>
									</div>
								</div>

								<div class="vanpos-order-info">
									<div class="vanpos-info-block">
										<div class="vanpos-info-title"><?php esc_html_e( 'Order Date', 'vanjorn-rental-pos' ); ?></div>
										<div class="vanpos-info-value">
											<?php echo esc_html( $order_data['order_date']->date_i18n( get_option( 'date_format' ) ) ); ?>
										</div>
									</div>

									<?php if ( $pickup_datetime ) : ?>
										<div class="vanpos-info-block">
											<div class="vanpos-info-title"><?php esc_html_e( 'Rental Start', 'vanjorn-rental-pos' ); ?></div>
											<div class="vanpos-info-value">
												<?php echo esc_html( date_i18n( get_option( 'date_format' ), $pickup_datetime->getTimestamp() ) ); ?>
											</div>
										</div>
									<?php endif; ?>

									<?php if ( $return_datetime ) : ?>
										<div class="vanpos-info-block">
											<div class="vanpos-info-title"><?php esc_html_e( 'Rental End', 'vanjorn-rental-pos' ); ?></div>
											<div class="vanpos-info-value">
												<?php echo esc_html( date_i18n( get_option( 'date_format' ), $return_datetime->getTimestamp() ) ); ?>
											</div>
										</div>
									<?php endif; ?>

									<?php if ( $order_data['vehicle_name'] ) : ?>
										<div class="vanpos-info-block">
											<div class="vanpos-info-title"><?php esc_html_e( 'Vehicle', 'vanjorn-rental-pos' ); ?></div>
											<div class="vanpos-info-value"><?php echo esc_html( $order_data['vehicle_name'] ); ?></div>
										</div>
									<?php endif; ?>
								</div>

								<?php
								// Get child payment orders from pre-fetched cache
								$payment_orders = array();
								$cached_children = isset( $stats['child_orders_cache'][ $order->get_id() ] ) ? $stats['child_orders_cache'][ $order->get_id() ] : array();
								foreach ( $cached_children as $child_order ) {
									$payment_type = $child_order->get_meta( '_vanpos_payment_type' );
									if ( VanPOS_Order_Manager::is_payment_order_type( $payment_type ) ) {
										$payment_orders[] = $child_order;
									}
								}
								
								// Also check if there are pending payments
								$has_pending_payments = false;
								if ( ! empty( $payment_orders ) || $order_data['remaining_amount'] > 0 ) {
									$has_pending_payments = true;
								}
								?>
								
								<?php if ( $has_pending_payments || $order_data['initial_payment'] > 0 ) : ?>
									<div class="vanpos-payment-section">
										<?php if ( $order_data['initial_payment'] > 0 ) : ?>
											<div class="vanpos-payment-row vanpos-payment-summary">
												<span><?php
													printf(
														/* translators: %g is the deposit percentage */
														esc_html__( 'Deposit Paid (%g%%)', 'vanjorn-rental-pos' ),
														$deposit_percentage
													);
												?></span>
												<span class="vanpos-paid">
													<?php echo wp_kses_post( wc_price( $order_data['initial_payment'] ) ); ?>
													<?php esc_html_e( 'Paid', 'vanjorn-rental-pos' ); ?>
												</span>
											</div>
										<?php endif; ?>

										<?php if ( ! empty( $payment_orders ) ) : ?>
											<div class="vanpos-payment-table-wrapper">
												<table class="vanpos-payment-table">
													<thead>
														<tr>
															<th><?php esc_html_e( 'Payment Type', 'vanjorn-rental-pos' ); ?></th>
															<th><?php esc_html_e( 'Order ID', 'vanjorn-rental-pos' ); ?></th>
															<th><?php esc_html_e( 'Due Date', 'vanjorn-rental-pos' ); ?></th>
															<th><?php esc_html_e( 'Total', 'vanjorn-rental-pos' ); ?></th>
															<th><?php esc_html_e( 'Actions', 'vanjorn-rental-pos' ); ?></th>
														</tr>
													</thead>
													<tbody>
														<?php foreach ( $payment_orders as $payment_order ) : ?>
															<?php
															$payment_type = $payment_order->get_meta( '_vanpos_payment_type' );
															$payment_due_date = $payment_order->get_meta( '_vanpos_due_date' );
															$payment_status = $payment_order->get_status();
															$is_paid = in_array( $payment_status, array( 'completed', 'processing' ), true );
															
															// Determine payment type label
															$payment_type_label = '';
															if ( in_array( $payment_type, array( 'deposit', 'security_deposit' ), true ) ) {
																$payment_type_label = __( 'Security Deposit', 'vanjorn-rental-pos' );
															} elseif ( VanPOS_Order_Manager::is_remaining_payment( $payment_type ) ) {
																$payment_type_label = __( 'Remaining Payment', 'vanjorn-rental-pos' );
															} else {
																$payment_type_label = __( 'Payment', 'vanjorn-rental-pos' );
															}
															
															// Check if overdue
															$is_payment_overdue = false;
															if ( $payment_due_date && ! $is_paid ) {
																$due_datetime = new DateTime( $payment_due_date );
																$current_datetime = new DateTime();
																$is_payment_overdue = $due_datetime < $current_datetime;
															}
															?>
															<tr class="vanpos-payment-table-row <?php echo $is_paid ? 'vanpos-payment-paid' : 'vanpos-payment-pending'; ?>">
																<td class="vanpos-payment-type">
																	<strong><?php echo esc_html( $payment_type_label ); ?></strong>
																</td>
																<td class="vanpos-payment-order-id">
																	<a href="<?php echo esc_url( $payment_order->get_view_order_url() ); ?>">
																		#<?php echo esc_html( $payment_order->get_order_number() ); ?>
																	</a>
																</td>
																<td class="vanpos-payment-due-date">
																	<?php if ( $payment_due_date ) : ?>
																		<?php
																		$due_datetime = new DateTime( $payment_due_date );
																		?>
																		<span class="<?php echo $is_payment_overdue ? 'vanpos-overdue' : ''; ?>">
																			<?php echo esc_html( date_i18n( get_option( 'date_format' ), $due_datetime->getTimestamp() ) ); ?>
																			<?php if ( $is_payment_overdue ) : ?>
																				<span class="vanpos-overdue-badge"><?php esc_html_e( 'Overdue', 'vanjorn-rental-pos' ); ?></span>
																			<?php endif; ?>
																		</span>
																	<?php else : ?>
																		<span class="vanpos-no-due-date">—</span>
																	<?php endif; ?>
																</td>
																<td class="vanpos-payment-total">
																	<strong><?php echo wp_kses_post( $payment_order->get_formatted_order_total() ); ?></strong>
																</td>
																<td class="vanpos-payment-actions">
																	<?php if ( ! $is_paid ) : ?>
																		<a href="<?php echo esc_url( $payment_order->get_checkout_payment_url() ); ?>" class="vanpos-pay-button button">
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
											</div>
										<?php elseif ( $order_data['remaining_amount'] > 0 ) : ?>
											<!-- Fallback: Show remaining amount if no child orders yet -->
											<div class="vanpos-payment-row">
												<span><?php esc_html_e( 'Remaining Amount', 'vanjorn-rental-pos' ); ?></span>
												<span class="vanpos-due <?php echo $is_overdue ? 'vanpos-overdue' : ''; ?>">
													<?php echo wp_kses_post( wc_price( $order_data['remaining_amount'] ) ); ?>
													<?php esc_html_e( 'Due', 'vanjorn-rental-pos' ); ?>
													<?php if ( $is_overdue ) : ?>
														<span class="vanpos-overdue-badge"><?php esc_html_e( 'Overdue', 'vanjorn-rental-pos' ); ?></span>
													<?php endif; ?>
												</span>
											</div>
											<?php if ( $due_datetime ) : ?>
												<div class="vanpos-payment-row">
													<span><?php esc_html_e( 'Due Before Pickup', 'vanjorn-rental-pos' ); ?></span>
													<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), $due_datetime->getTimestamp() ) ); ?></span>
												</div>
											<?php endif; ?>
										<?php endif; ?>
									</div>
								<?php endif; ?>

								<div class="vanpos-order-actions">
									<a href="<?php echo esc_url( $order_data['view_url'] ); ?>" class="vanpos-view-order-btn">
										<?php esc_html_e( 'View Booking Details', 'vanjorn-rental-pos' ); ?>
									</a>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php else : ?>
				<div class="vanpos-no-rentals">
					<p><?php esc_html_e( 'You don\'t have any active rental bookings at the moment.', 'vanjorn-rental-pos' ); ?></p>
					<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="button">
						<?php esc_html_e( 'Browse Vehicles', 'vanjorn-rental-pos' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
