<?php
/**
 * Admin Modify Booking — Meta Box & AJAX Handlers
 *
 * Adds a "Modify Booking" meta box to the WooCommerce order edit page
 * for primary rental orders. Allows changing dates and/or van (product)
 * with a price-impact preview before confirming, then delegates to
 * VanPOS_Change_Manager::change_dates().
 *
 * INTEGRATION: Load this file in your main plugin bootstrap (vanjorn-rental-pos.php)
 * and instantiate alongside VanPOS_Admin:
 *
 *   require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-modify-booking.php';
 *   new VanPOS_Admin_Modify_Booking();
 *
 * @package VJ_Rental_POS
 * @author  CMITEXPERTS TEAM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Admin_Modify_Booking {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_vanpos_preview_booking_change', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_vanpos_modify_booking', array( $this, 'ajax_modify_booking' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( ! $this->is_order_edit_page( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'vanpos-admin-modify-booking',
			VANPOS_PLUGIN_URL . 'admin/css/admin-modify-booking.css',
			array(),
			VANPOS_VERSION
		);

		wp_enqueue_script(
			'vanpos-admin-modify-booking',
			VANPOS_PLUGIN_URL . 'admin/js/admin-modify-booking.js',
			array( 'jquery' ),
			VANPOS_VERSION,
			true
		);

		wp_localize_script( 'vanpos-admin-modify-booking', 'vanposModifyBooking', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'vanpos_modify_booking' ),
			'currency' => get_woocommerce_currency_symbol(),
			'products' => array(),
			'i18n'     => array(
				'previewing'        => __( 'Calculating impact…', 'vanjorn-rental-pos' ),
				'applying'          => __( 'Applying changes…', 'vanjorn-rental-pos' ),
				'error'             => __( 'Error', 'vanjorn-rental-pos' ),
				'noChange'          => __( 'New values are the same as the current booking.', 'vanjorn-rental-pos' ),
				'selectBothDates'   => __( 'Please select both a pickup date and a return date.', 'vanjorn-rental-pos' ),
				'priceIncrease'     => __( 'Price increases by %s — an extension payment order will be created if the remaining payment order has already been paid.', 'vanjorn-rental-pos' ),
				'priceDecrease'     => __( 'Price decreases by %s — rental shortened.', 'vanjorn-rental-pos' ),
				'shorteningRefundNote' => __( 'No automatic refund will be issued. If you apply this change, a note with refund details will be added to the order for manual review by support staff.', 'vanjorn-rental-pos' ),
				'priceUnchanged'    => __( 'Total price is unchanged.', 'vanjorn-rental-pos' ),
				'childNote'         => __( 'Child order dates, due dates, and AutomateWoo flags will be updated automatically.', 'vanjorn-rental-pos' ),
				'vanAvailable'      => __( 'Van is available for the selected dates.', 'vanjorn-rental-pos' ),
				'vanUnavailable'    => __( 'Van is not available for the selected dates.', 'vanjorn-rental-pos' ),
				'labelVan'          => __( 'Van', 'vanjorn-rental-pos' ),
				'labelPickup'       => __( 'Pickup', 'vanjorn-rental-pos' ),
				'labelReturn'       => __( 'Return', 'vanjorn-rental-pos' ),
				'labelDays'         => __( 'Days', 'vanjorn-rental-pos' ),
				'labelTotalPrice'   => __( 'Total price', 'vanjorn-rental-pos' ),
				'labelRemaining'    => __( 'Remaining', 'vanjorn-rental-pos' ),
				'returnAfterPickup' => __( 'Return date must be after the pickup date.', 'vanjorn-rental-pos' ),
				'labelPickupTime'   => __( 'Pickup time', 'vanjorn-rental-pos' ),
				'labelReturnTime'   => __( 'Return time', 'vanjorn-rental-pos' ),
				'returnTimeAfterPickupTime' => __( 'Return time must be after pickup time on the same day.', 'vanjorn-rental-pos' ),
				'invalidDate'       => __( 'Please enter valid dates in YYYY-MM-DD format.', 'vanjorn-rental-pos' ),
				'invalidTime'       => __( 'Please enter valid times in HH:MM 24-hour format.', 'vanjorn-rental-pos' ),
			),
		) );
	}

	/**
	 * Check if we're on a WooCommerce order edit page.
	 */
	private function is_order_edit_page( $hook ) {
		// HPOS hook
		if ( strpos( $hook, 'woocommerce_page_wc-orders' ) !== false ) {
			return true;
		}

		// Legacy post-type order screens (non-HPOS)
		global $pagenow, $post;
		if ( in_array( $pagenow, array( 'post.php', 'post-new.php' ), true )
			&& $post && 'shop_order' === $post->post_type ) {
			return true;
		}

		return false;
	}

	/**
	 * Primary rental line product ID on an order (not deposit / add-on lines).
	 *
	 * @param WC_Order $order Order.
	 * @return int Product or variation ID.
	 */
	private function get_order_rental_line_product_id( WC_Order $order ) {
		if ( class_exists( 'VanPOS_Rental_Returned' ) ) {
			$item_id = VanPOS_Rental_Returned::get_primary_rental_line_item_id( $order );
			if ( $item_id > 0 ) {
				$item = new WC_Order_Item_Product( $item_id );
				if ( $item->get_id() ) {
					$variation_id = (int) $item->get_variation_id();
					return $variation_id > 0 ? $variation_id : (int) $item->get_product_id();
				}
			}
		}

		$deposit_pid = class_exists( 'VanPOS_Functions' )
			? (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' )
			: 0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$variation_id = (int) $item->get_variation_id();
			$product_id   = $variation_id > 0 ? $variation_id : (int) $item->get_product_id();
			if ( $product_id <= 0 ) {
				continue;
			}

			if ( $deposit_pid > 0 && class_exists( 'VanPOS_Functions' ) ) {
				$orig = VanPOS_Functions::get_original_product_id( $product_id );
				if ( $orig === $deposit_pid ) {
					continue;
				}
			}

			if (
				$item->get_meta( 'vanpos_pickup_date' )
				|| $item->get_meta( 'wcrp_rental_products_rent_from' )
				|| $item->get_meta( '_vanpos_original_price' )
			) {
				return $product_id;
			}
		}

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$variation_id = (int) $item->get_variation_id();
			$product_id   = $variation_id > 0 ? $variation_id : (int) $item->get_product_id();
			if ( $product_id <= 0 ) {
				continue;
			}
			if ( $deposit_pid > 0 && class_exists( 'VanPOS_Functions' ) ) {
				if ( VanPOS_Functions::get_original_product_id( $product_id ) === $deposit_pid ) {
					continue;
				}
			}
			return $product_id;
		}

		return 0;
	}

	/**
	 * Rental products for the van dropdown (id + name), same catalogue as POS frontend.
	 *
	 * @return array<int, array{id:int, name:string, original_id:int}>
	 */
	private function get_rental_products_for_dropdown() {
		$products = array();

		if ( class_exists( 'VanPOS_Functions' ) ) {
			foreach ( VanPOS_Functions::get_rental_products() as $p ) {
				if ( empty( $p['id'] ) ) {
					continue;
				}
				$pid = (int) $p['id'];
				$products[ $pid ] = array(
					'id'          => $pid,
					'name'        => (string) ( $p['name'] ?? '' ),
					'original_id' => VanPOS_Functions::get_original_product_id( $pid ),
				);
			}
		} else {
			$default_args = array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			);
			$args  = apply_filters( 'vanpos_modify_booking_product_query_args', $default_args );
			$query = new WP_Query( $args );
			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();
					$pid = (int) get_the_ID();
					$product = wc_get_product( $pid );
					if ( ! $product ) {
						continue;
					}
					$products[ $pid ] = array(
						'id'          => $pid,
						'name'        => $product->get_name(),
						'original_id' => $pid,
					);
				}
				wp_reset_postdata();
			}
		}

		return array_values( $products );
	}

	/**
	 * Dropdown option ID that matches the order's current rental van (WPML + variations).
	 *
	 * @param int   $line_product_id Product/variation on the rental line item.
	 * @param array $products        Dropdown rows from get_rental_products_for_dropdown().
	 * @return int ID to pre-select (0 if none).
	 */
	private function resolve_dropdown_product_id( $line_product_id, array $products ) {
		$line_product_id = (int) $line_product_id;
		if ( $line_product_id <= 0 || empty( $products ) ) {
			return 0;
		}

		$candidates = array( $line_product_id );
		$product    = wc_get_product( $line_product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			$candidates[] = (int) $product->get_parent_id();
		}

		if ( class_exists( 'VanPOS_Functions' ) ) {
			$candidates[] = VanPOS_Functions::get_original_product_id( $line_product_id );
			if ( $product && $product->is_type( 'variation' ) ) {
				$candidates[] = VanPOS_Functions::get_original_product_id( (int) $product->get_parent_id() );
			}
		}

		$candidates = array_unique( array_filter( array_map( 'intval', $candidates ) ) );

		foreach ( $products as $row ) {
			$pid = (int) $row['id'];
			if ( in_array( $pid, $candidates, true ) ) {
				return $pid;
			}
			if ( ! empty( $row['original_id'] ) && in_array( (int) $row['original_id'], $candidates, true ) ) {
				return $pid;
			}
		}

		return 0;
	}

	/**
	 * Build dropdown list and ensure the booked van is included.
	 *
	 * @param WC_Order $order Order.
	 * @return array{products:array, selected_id:int, line_product_id:int}
	 */
	private function get_van_dropdown_data( WC_Order $order ) {
		$line_product_id = $this->get_order_rental_line_product_id( $order );
		$products        = $this->get_rental_products_for_dropdown();
		$selected_id     = $this->resolve_dropdown_product_id( $line_product_id, $products );

		if ( $line_product_id > 0 && $selected_id <= 0 ) {
			$product = wc_get_product( $line_product_id );
			if ( $product ) {
				$add_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : $line_product_id;
				if ( $add_id <= 0 ) {
					$add_id = $line_product_id;
				}
				$orig = class_exists( 'VanPOS_Functions' )
					? VanPOS_Functions::get_original_product_id( $add_id )
					: $add_id;
				array_unshift(
					$products,
					array(
						'id'          => $add_id,
						'name'        => $product->get_name(),
						'original_id' => $orig,
					)
				);
				$selected_id = $add_id;
			}
		}

		return array(
			'products'        => $products,
			'selected_id'     => $selected_id > 0 ? $selected_id : $line_product_id,
			'line_product_id' => $line_product_id,
		);
	}

	/**
	 * Whether two product IDs refer to the same van (variation/parent/WPML).
	 *
	 * @param int $a First product ID.
	 * @param int $b Second product ID.
	 * @return bool
	 */
	private function product_ids_refer_to_same_van( $a, $b ) {
		$a = (int) $a;
		$b = (int) $b;
		if ( $a <= 0 || $b <= 0 ) {
			return $a === $b;
		}
		if ( $a === $b ) {
			return true;
		}

		$ids_a = array( $a );
		$ids_b = array( $b );
		$prod_a = wc_get_product( $a );
		$prod_b = wc_get_product( $b );
		if ( $prod_a && $prod_a->is_type( 'variation' ) ) {
			$ids_a[] = (int) $prod_a->get_parent_id();
		}
		if ( $prod_b && $prod_b->is_type( 'variation' ) ) {
			$ids_b[] = (int) $prod_b->get_parent_id();
		}
		if ( class_exists( 'VanPOS_Functions' ) ) {
			$ids_a[] = VanPOS_Functions::get_original_product_id( $a );
			$ids_b[] = VanPOS_Functions::get_original_product_id( $b );
		}

		$ids_a = array_unique( array_filter( array_map( 'intval', $ids_a ) ) );
		$ids_b = array_unique( array_filter( array_map( 'intval', $ids_b ) ) );

		return (bool) array_intersect( $ids_a, $ids_b );
	}

	/**
	 * Get the default pickup time from plugin settings (H:i format).
	 *
	 * @return string Default pickup time, e.g. '15:00'.
	 */
	private function get_default_pickup_time() {
		if ( class_exists( 'VanPOS_Functions' ) ) {
			return VanPOS_Functions::get_setting( 'vanpos_pickup_time', '15:00' );
		}
		return '15:00';
	}

	/**
	 * Get the default return time from plugin settings (H:i format).
	 *
	 * @return string Default return time, e.g. '11:00'.
	 */
	private function get_default_return_time() {
		if ( class_exists( 'VanPOS_Functions' ) ) {
			return VanPOS_Functions::get_setting( 'vanpos_return_time', '11:00' );
		}
		return '11:00';
	}

	public function register_meta_box( $screen_id ) {
		$is_order_screen = false;

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			if ( $screen_id === wc_get_page_screen_id( 'shop-order' ) ) {
				$is_order_screen = true;
			}
		}

		if ( ! $is_order_screen && 'woocommerce_page_wc-orders' === $screen_id ) {
			$is_order_screen = true;
		}

		// Legacy post-type order screens (non-HPOS)
		if ( ! $is_order_screen && 'shop_order' === $screen_id ) {
			$is_order_screen = true;
		}

		if ( $is_order_screen ) {
			add_meta_box(
				'vanpos-modify-booking',
				__( 'Modify Booking', 'vanjorn-rental-pos' ),
				array( $this, 'render_meta_box' ),
				$screen_id,
				'side',
				'low'
			);
		}
	}

	public function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order )
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : absint( $post_or_order ) );

		if ( ! $order ) {
			return;
		}

		// Delegate to the single source of truth so detection is consistent with
		// VanPOS_Admin_Ajax and VanPOS_Admin_Order_Edit. The Order Manager correctly
		// handles typed orders ('primary_rental'), legacy untyped orders with rental
		// dates, and explicitly non-rental types (returns false for any non-empty
		// type that isn't 'primary_rental', e.g. 'payment_order').
		if ( ! class_exists( 'VanPOS_Order_Manager' ) || ! VanPOS_Order_Manager::is_primary_rental_order( $order ) ) {
			echo '<p class="description">' . esc_html__( 'Booking modifications are only available for primary rental orders.', 'vanjorn-rental-pos' ) . '</p>';
			return;
		}

		$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
		$pickup_time = $order->get_meta( '_vanpos_pickup_time' ) ?: $this->get_default_pickup_time();
		$return_date = $order->get_meta( '_vanpos_return_date' );
		$return_time = $order->get_meta( '_vanpos_return_time' ) ?: $this->get_default_return_time();
		$rental_days = class_exists( 'VanPOS_Functions' )
			? VanPOS_Functions::rental_days_for_order( $order )
			: (int) $order->get_meta( '_vanpos_rental_days' );
		$total_price = $order->get_meta( '_vanpos_total_price' );
		$is_paid = $this->order_is_paid( $order );

		$van_dropdown         = $this->get_van_dropdown_data( $order );
		$current_product_id   = (int) $van_dropdown['selected_id'];
		$line_product_id      = (int) $van_dropdown['line_product_id'];

		// Prefer a previously-stored custom rate; otherwise use the product's
		// catalogue day price (Kestrel base price = $product->get_price()).
		// Using the catalogue price avoids the confusing back-calculation
		// (total ÷ nights) that previously produced non-round figures like
		// €155.56 when the van's actual rate is €140/day.
		$stored_rate = (float) $order->get_meta( '_vanpos_price_per_day' );
		if ( $stored_rate > 0 ) {
			$current_daily_rate = $stored_rate;
		} elseif ( $line_product_id > 0 ) {
			$rate_product       = wc_get_product( $line_product_id );
			$current_daily_rate = $rate_product ? round( (float) $rate_product->get_price(), 2 ) : 0;
		} else {
			$current_daily_rate = 0;
		}

		$current_product_name = '';
		if ( $line_product_id > 0 ) {
			$product_obj = wc_get_product( $line_product_id );
			$current_product_name = $product_obj ? $product_obj->get_name() : '';
		}
		if ( '' === $current_product_name && class_exists( 'VanPOS_Rental_Returned' ) ) {
			$item_id = VanPOS_Rental_Returned::get_primary_rental_line_item_id( $order );
			if ( $item_id > 0 ) {
				$item = new WC_Order_Item_Product( $item_id );
				if ( $item->get_id() ) {
					$current_product_name = $item->get_name();
				}
			}
		}

		?>
		<!-- Current booking display -->
		<div class="vanpos-cd-current">
			<div class="vanpos-cd-current-row">
				<span class="vanpos-cd-label"><?php esc_html_e( 'Van:', 'vanjorn-rental-pos' ); ?></span>
				<span class="vanpos-cd-value"><?php echo esc_html( $current_product_name ); ?></span>
			</div>
			<div class="vanpos-cd-current-row">
				<span class="vanpos-cd-label"><?php esc_html_e( 'Pickup:', 'vanjorn-rental-pos' ); ?></span>
				<span class="vanpos-cd-value">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $pickup_date ) ) ); ?>
					<small>(<?php echo esc_html( $pickup_time ); ?>)</small>
				</span>
			</div>
			<div class="vanpos-cd-current-row">
				<span class="vanpos-cd-label"><?php esc_html_e( 'Return:', 'vanjorn-rental-pos' ); ?></span>
				<span class="vanpos-cd-value">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $return_date ) ) ); ?>
					<small>(<?php echo esc_html( $return_time ); ?>)</small>
				</span>
			</div>
			<div class="vanpos-cd-current-row">
				<span class="vanpos-cd-label"><?php esc_html_e( 'Duration:', 'vanjorn-rental-pos' ); ?></span>
				<span class="vanpos-cd-value">
					<?php
					printf(
						/* translators: %d is the number of rental days */
						esc_html__( '%d days', 'vanjorn-rental-pos' ),
						(int) $rental_days
					);
					?>
				</span>
			</div>
			<?php if ( $total_price ) : ?>
			<div class="vanpos-cd-current-row">
				<span class="vanpos-cd-label"><?php esc_html_e( 'Total:', 'vanjorn-rental-pos' ); ?></span>
				<span class="vanpos-cd-value"><?php echo wp_kses_post( wc_price( $total_price ) ); ?></span>
			</div>
			<?php endif; ?>
		</div>

		<!-- Toggle form -->
		<button type="button" id="vanpos-cd-toggle" class="button vanpos-cd-toggle">
			<?php esc_html_e( 'Modify Booking', 'vanjorn-rental-pos' ); ?>
		</button>

		<!-- Modify booking form (hidden by default) -->
		<div id="vanpos-cd-form" class="vanpos-cd-form" style="display:none;">
			<input type="hidden" id="vanpos-cd-order-id" value="<?php echo esc_attr( $order->get_id() ); ?>">
			<input type="hidden" id="vanpos-cd-current-product-id" value="<?php echo esc_attr( (string) $current_product_id ); ?>">
			<input type="hidden" id="vanpos-cd-line-product-id" value="<?php echo esc_attr( (string) $line_product_id ); ?>">

			<div class="vanpos-cd-field">
				<label for="vanpos-cd-product"><?php esc_html_e( 'Van', 'vanjorn-rental-pos' ); ?></label>
				<select id="vanpos-cd-product">
					<?php foreach ( $van_dropdown['products'] as $product_row ) : ?>
						<option
							value="<?php echo esc_attr( (string) $product_row['id'] ); ?>"
							<?php selected( $current_product_id, (int) $product_row['id'] ); ?>
						><?php echo esc_html( (string) $product_row['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="vanpos-cd-field">
				<label for="vanpos-cd-pickup-date"><?php esc_html_e( 'Pickup date', 'vanjorn-rental-pos' ); ?></label>
				<input type="date" id="vanpos-cd-pickup-date" value="<?php echo esc_attr( $pickup_date ); ?>">
			</div>

			<div class="vanpos-cd-field">
				<label for="vanpos-cd-pickup-time"><?php esc_html_e( 'Pickup time', 'vanjorn-rental-pos' ); ?></label>
				<input type="time" id="vanpos-cd-pickup-time" value="<?php echo esc_attr( $pickup_time ); ?>">
			</div>

			<div class="vanpos-cd-field">
				<label for="vanpos-cd-return-date"><?php esc_html_e( 'Return date', 'vanjorn-rental-pos' ); ?></label>
				<input type="date" id="vanpos-cd-return-date" value="<?php echo esc_attr( $return_date ); ?>">
			</div>

			<div class="vanpos-cd-field">
				<label for="vanpos-cd-return-time"><?php esc_html_e( 'Return time', 'vanjorn-rental-pos' ); ?></label>
				<input type="time" id="vanpos-cd-return-time" value="<?php echo esc_attr( $return_time ); ?>">
			</div>

			<div class="vanpos-cd-field">
				<label for="vanpos-cd-price-per-day"><?php esc_html_e( 'Price per day (incl. VAT)', 'vanjorn-rental-pos' ); ?></label>
				<input type="number" id="vanpos-cd-price-per-day" step="0.01" min="0" inputmode="decimal"
					value="<?php echo esc_attr( $current_daily_rate ? number_format( $current_daily_rate, 2, '.', '' ) : '' ); ?>"
					data-suggested="<?php echo esc_attr( $current_daily_rate ? number_format( $current_daily_rate, 2, '.', '' ) : '' ); ?>"
					<?php disabled( $is_paid ); ?>>
				<?php if ( $is_paid ) : ?>
					<p class="description"><?php esc_html_e( 'This order has been paid — the daily rate is locked and cannot be changed.', 'vanjorn-rental-pos' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Edit to re-rate this booking. Total becomes rate × rental days. Leave unchanged to keep the existing rate.', 'vanjorn-rental-pos' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="vanpos-cd-field">
				<label class="vanpos-cd-override-label">
					<input type="checkbox" id="vanpos-cd-lock-price"<?php if ( $is_paid ) echo ' checked'; ?>>
					<span>
					<?php
					if ( $total_price ) {
						printf(
							/* translators: %s is the current formatted rental total */
							esc_html__( 'Lock total price (%s)', 'vanjorn-rental-pos' ),
							wp_strip_all_tags( wc_price( $total_price ) )
						);
					} else {
						esc_html_e( 'Lock total price', 'vanjorn-rental-pos' );
					}
					?>
					</span>
				</label>
				<p class="description"><?php esc_html_e( 'When checked, the rental total stays exactly as-is regardless of date changes.', 'vanjorn-rental-pos' ); ?></p>
			</div>

			<!-- Preview area -->
			<div id="vanpos-cd-preview" class="vanpos-cd-preview" style="display:none;"></div>

			<!-- Availability indicator (populated by preview) -->
			<div id="vanpos-cd-availability" class="vanpos-cd-availability" style="display:none;"></div>
			<div id="vanpos-cd-override-wrap" class="vanpos-cd-override-wrap" style="display:none;">
				<label class="vanpos-cd-override-label">
					<input type="checkbox" id="vanpos-cd-availability-override">
					<span><?php esc_html_e( 'Override — apply changes despite unavailability', 'vanjorn-rental-pos' ); ?></span>
				</label>
			</div>

			<!-- Refund warning (populated by preview when price decreases) -->
			<div id="vanpos-cd-refund-warning" class="vanpos-cd-availability" style="display:none;"></div>

			<!-- Notice area -->
			<div id="vanpos-cd-notice" class="vanpos-cd-notice" style="display:none;"></div>

			<div class="vanpos-cd-actions">
				<button type="button" id="vanpos-cd-preview-btn" class="button">
					<?php esc_html_e( 'Preview Impact', 'vanjorn-rental-pos' ); ?>
				</button>
				<button type="button" id="vanpos-cd-apply-btn" class="button button-primary" style="display:none;">
					<?php esc_html_e( 'Apply Changes', 'vanjorn-rental-pos' ); ?>
				</button>
				<button type="button" id="vanpos-cd-cancel-btn" class="button vanpos-cd-cancel">
					<?php esc_html_e( 'Cancel', 'vanjorn-rental-pos' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	public function ajax_preview() {
		check_ajax_referer( 'vanpos_modify_booking', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		$order_id        = isset( $_POST['order_id'] )    ? absint( $_POST['order_id'] ) : 0;
		$new_pickup_date = isset( $_POST['pickup_date'] )  ? sanitize_text_field( wp_unslash( $_POST['pickup_date'] ) ) : '';
		$new_pickup_time = isset( $_POST['pickup_time'] )  ? sanitize_text_field( wp_unslash( $_POST['pickup_time'] ) ) : $this->get_default_pickup_time();
		$new_return_date = isset( $_POST['return_date'] )  ? sanitize_text_field( wp_unslash( $_POST['return_date'] ) ) : '';
		$new_return_time = isset( $_POST['return_time'] )  ? sanitize_text_field( wp_unslash( $_POST['return_time'] ) ) : $this->get_default_return_time();
		$new_product_id  = isset( $_POST['product_id'] )   ? absint( $_POST['product_id'] ) : 0;
		$price_raw        = isset( $_POST['price_per_day'] ) ? trim( (string) wp_unslash( $_POST['price_per_day'] ) ) : '';
		$has_custom_price = ( '' !== $price_raw && is_numeric( $price_raw ) && (float) $price_raw >= 0 );
		$price_per_day    = $has_custom_price ? (float) $price_raw : null;
		$lock_price       = ( ! empty( $_POST['lock_price'] ) && 'true' === $_POST['lock_price'] );

		if ( ! $order_id || ! $new_pickup_date || ! $new_return_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Validate date format (Y-m-d)
		if ( ! $this->is_valid_date( $new_pickup_date ) || ! $this->is_valid_date( $new_return_date ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date format. Expected YYYY-MM-DD.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Validate time format (H:i, 24-hour)
		if ( ! $this->is_valid_time( $new_pickup_time ) || ! $this->is_valid_time( $new_return_time ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid time format. Expected HH:MM (24-hour).', 'vanjorn-rental-pos' ) ) );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Re-rating is only permitted on unpaid orders.
		if ( $has_custom_price && $this->order_is_paid( $order ) ) {
			$has_custom_price = false;
			$price_per_day    = null;
		}

		// Current values
		$old_pickup      = $order->get_meta( '_vanpos_pickup_date' );
		$old_return      = $order->get_meta( '_vanpos_return_date' );
		$old_pickup_time = $order->get_meta( '_vanpos_pickup_time' ) ?: $this->get_default_pickup_time();
		$old_return_time = $order->get_meta( '_vanpos_return_time' ) ?: $this->get_default_return_time();
		$old_total       = (float) $order->get_meta( '_vanpos_total_price' );
		// Delta fix: price is now calculated on inclusive calendar days, matching the
		// unit used in calculate_modification_price_for_order() and change_dates().
		$old_days = class_exists( 'VanPOS_Functions' )
			? VanPOS_Functions::rental_days_for_order( $order )
			: max( 1, (int) $order->get_meta( '_vanpos_rental_days' ) );

		// Current product
		$old_product_id = $this->get_order_rental_line_product_id( $order );
		$old_product_name = '';
		if ( $old_product_id > 0 ) {
			$old_product_obj  = wc_get_product( $old_product_id );
			$old_product_name = $old_product_obj ? $old_product_obj->get_name() : '';
		}

		if ( ! $old_product_id ) {
			wp_send_json_error( array( 'message' => __( 'No product found in order.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Determine effective product (new if changing, old if not).
		$product_changed = ( $new_product_id > 0 && ! $this->product_ids_refer_to_same_van( $new_product_id, $old_product_id ) );
		$effective_product_id = $product_changed ? $new_product_id : $old_product_id;

		// Validate new product exists
		$product = wc_get_product( $effective_product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'The selected van does not exist.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// New values
		$pickup_dt = new DateTime( $new_pickup_date );
		$return_dt = new DateTime( $new_return_date );

		if ( $return_dt < $pickup_dt ) {
			wp_send_json_error( array( 'message' => __( 'Return date must be after pickup date.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Same-day time validation
		if ( $new_pickup_date === $new_return_date && $new_pickup_time >= $new_return_time ) {
			wp_send_json_error( array( 'message' => __( 'Return time must be after pickup time on the same day.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		$new_days = class_exists( 'VanPOS_Functions' )
			? VanPOS_Functions::rental_days_from_dates( $new_pickup_date, $new_return_date )
			: ( $pickup_dt->diff( $return_dt )->days + 1 );

		if ( ! class_exists( 'VanPOS_Functions' ) ) {
			wp_send_json_error( array( 'message' => __( 'VanPOS_Functions not available.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// When the price is locked, skip calculation entirely and return the stored total.
		// Otherwise use the delta approach (stored daily rate or catalogue) via calculate_modification_price_for_order().
		if ( $lock_price ) {
			$new_total  = $old_total;
			$price_diff = 0;
		} else {
			$new_total  = VanPOS_Change_Manager::calculate_modification_price_for_order(
				$order,
				$effective_product_id,
				$new_days,
				$product_changed,
				$price_per_day
			);
			$price_diff = $new_total - $old_total;
		}

		// Recalculate remaining split
		$initial_payment = (float) $order->get_meta( '_vanpos_initial_payment' );
		$new_remaining   = ( $initial_payment > 0 ) ? max( 0, $new_total - $initial_payment ) : 0;
		$old_remaining   = (float) $order->get_meta( '_vanpos_remaining_payment' );

		// Check how many child orders will be affected
		$child_count = 0;
		if ( class_exists( 'VanPOS_Order_Manager' ) ) {
			$child_orders = VanPOS_Order_Manager::get_payment_orders( $order_id );
			$child_count  = count( $child_orders );
		}

		// Availability check — targets the effective (possibly new) product
		$available     = true;
		$avail_message = '';
		if ( class_exists( 'VanPOS_Change_Manager' ) ) {
			$avail = VanPOS_Change_Manager::check_preview_availability( $effective_product_id, $order_id, $new_pickup_date, $new_pickup_time, $new_return_date, $new_return_time );
			$available     = $avail['available'];
			$avail_message = $avail['message'];
		}

		// Use WP date format for consistent display
		$wp_date_format = get_option( 'date_format' );

		wp_send_json_success( array(
			'old_pickup'         => $old_pickup,
			'old_return'         => $old_return,
			'old_pickup_time'    => $old_pickup_time,
			'old_return_time'    => $old_return_time,
			'old_days'           => $old_days,
			'old_total'          => $old_total,
			'old_remaining'      => $old_remaining,
			'new_pickup'         => $new_pickup_date,
			'new_return'         => $new_return_date,
			'new_pickup_time'    => $new_pickup_time,
			'new_return_time'    => $new_return_time,
			'new_days'           => $new_days,
			'new_total'          => $new_total,
			'new_remaining'      => $new_remaining,
			'price_diff'         => round( $price_diff, 2 ),
			'child_count'        => $child_count,
			// Product change data
			'old_product_id'     => $old_product_id,
			'old_product_name'   => $old_product_name,
			'new_product_id'     => $effective_product_id,
			'new_product_name'   => $product->get_name(),
			'product_changed'    => $product_changed,
			// Combined change detection.
			// Include price diff so an admin-supplied rate change with no date/van
			// change still surfaces in the preview table and enables the Apply button.
			'booking_changed'    => (
				$old_pickup !== $new_pickup_date
				|| $old_return !== $new_return_date
				|| $old_pickup_time !== $new_pickup_time
				|| $old_return_time !== $new_return_time
				|| $product_changed
				|| abs( $price_diff ) > 0.01
			),
			'dates_changed'      => (
				$old_pickup !== $new_pickup_date
				|| $old_return !== $new_return_date
				|| $old_pickup_time !== $new_pickup_time
				|| $old_return_time !== $new_return_time
			),
			'available'          => $available,
			'avail_message'      => $avail_message,
			'is_price_locked'    => $lock_price,
			// Server-formatted dates for locale-aware display
			'old_pickup_display' => $old_pickup ? date_i18n( $wp_date_format, strtotime( $old_pickup ) ) : '',
			'old_return_display' => $old_return ? date_i18n( $wp_date_format, strtotime( $old_return ) ) : '',
			'new_pickup_display' => date_i18n( $wp_date_format, strtotime( $new_pickup_date ) ),
			'new_return_display' => date_i18n( $wp_date_format, strtotime( $new_return_date ) ),
			// old_days / new_days are now inclusive calendar days (= display unit).
			// _display variants kept for JS back-compat but are identical values.
			'old_days_display'   => $old_days,
			'new_days_display'   => $new_days,
			// Signals the JS to show the manual-refund reminder below the price decrease line.
			// Set for any price decrease regardless of child order payment status.
			'refund_warning'     => $price_diff < -0.01,
		) );
	}

	public function ajax_modify_booking() {
		check_ajax_referer( 'vanpos_modify_booking', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		$order_id        = isset( $_POST['order_id'] )     ? absint( $_POST['order_id'] ) : 0;
		$new_pickup_date = isset( $_POST['pickup_date'] )   ? sanitize_text_field( wp_unslash( $_POST['pickup_date'] ) ) : '';
		$new_pickup_time = isset( $_POST['pickup_time'] )   ? sanitize_text_field( wp_unslash( $_POST['pickup_time'] ) ) : $this->get_default_pickup_time();
		$new_return_date = isset( $_POST['return_date'] )   ? sanitize_text_field( wp_unslash( $_POST['return_date'] ) ) : '';
		$new_return_time = isset( $_POST['return_time'] )   ? sanitize_text_field( wp_unslash( $_POST['return_time'] ) ) : $this->get_default_return_time();
		$new_product_id  = isset( $_POST['product_id'] )    ? absint( $_POST['product_id'] ) : 0;
		$override        = isset( $_POST['availability_override'] ) && 'true' === $_POST['availability_override'];
		$price_raw        = isset( $_POST['price_per_day'] ) ? trim( (string) wp_unslash( $_POST['price_per_day'] ) ) : '';
		$has_custom_price = ( '' !== $price_raw && is_numeric( $price_raw ) && (float) $price_raw >= 0 );
		$price_per_day    = $has_custom_price ? (float) $price_raw : null;
		$lock_price       = ( ! empty( $_POST['lock_price'] ) && 'true' === $_POST['lock_price'] );

		if ( ! $order_id || ! $new_pickup_date || ! $new_return_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Validate date format (Y-m-d)
		if ( ! $this->is_valid_date( $new_pickup_date ) || ! $this->is_valid_date( $new_return_date ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date format. Expected YYYY-MM-DD.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Validate time format (H:i, 24-hour)
		if ( ! $this->is_valid_time( $new_pickup_time ) || ! $this->is_valid_time( $new_return_time ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid time format. Expected HH:MM (24-hour).', 'vanjorn-rental-pos' ) ) );
			return;
		}

		if ( ! class_exists( 'VanPOS_Change_Manager' ) ) {
			wp_send_json_error( array( 'message' => __( 'Change Manager not available.', 'vanjorn-rental-pos' ) ) );
			return;
		}

		// Re-rating is only permitted on unpaid orders.
		if ( $has_custom_price ) {
			$order_for_gate = wc_get_order( $order_id );
			if ( $order_for_gate && $this->order_is_paid( $order_for_gate ) ) {
				wp_send_json_error( array( 'message' => __( 'This order has been paid — the price cannot be changed.', 'vanjorn-rental-pos' ) ) );
				return;
			}
		}

		// When the lock-price flag is set, read the current stored total and pass it
		// directly to change_dates() as $lock_total. This bypasses all rate calculation
		// and floating-point multiplication, so the exact stored value is preserved —
		// no rounding issues, no days-vs-nights unit mismatch, no spurious price diffs.
		$lock_total = null;
		if ( $lock_price ) {
			$order_to_lock = wc_get_order( $order_id );
			if ( $order_to_lock ) {
				$raw_lock = (float) $order_to_lock->get_meta( '_vanpos_total_price' );
				if ( $raw_lock > 0 ) {
					$lock_total = $raw_lock;
				}
			}
		}

		$result = VanPOS_Change_Manager::change_dates(
			$order_id,
			$new_pickup_date,
			$new_pickup_time,
			$new_return_date,
			$new_return_time,
			$override,
			$new_product_id,
			$price_per_day,
			$lock_total
		);

		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();

			// Include alternatives if available
			$alternatives = $result->get_error_data();
			if ( ! empty( $alternatives ) && is_array( $alternatives ) ) {
				if ( ! empty( $alternatives['nearby_dates'] ) ) {
					$message .= ' ' . sprintf(
						/* translators: %s is a comma-separated list of alternative dates */
						__( 'Available nearby dates: %s', 'vanjorn-rental-pos' ),
						implode( ', ', $alternatives['nearby_dates'] )
					);
				}
			}

			wp_send_json_error( array( 'message' => $message ) );
			return;
		}

		// Reload order to get updated meta
		$order = wc_get_order( $order_id );

		wp_send_json_success( array(
			'message'     => sprintf(
				/* translators: %s is the order number */
				__( 'Booking for order #%s updated successfully.', 'vanjorn-rental-pos' ),
				$order->get_order_number()
			),
			'new_pickup'  => $order->get_meta( '_vanpos_pickup_date' ),
			'new_return'  => $order->get_meta( '_vanpos_return_date' ),
			'new_days'    => $order->get_meta( '_vanpos_rental_days' ),
			'new_total'   => $order->get_meta( '_vanpos_total_price' ),
		) );
	}

	/**
	 * Validate a date string is in Y-m-d format and represents a real date.
	 *
	 * @param string $date Date string to validate.
	 * @return bool True if valid.
	 */
	private function is_valid_date( $date ) {
		$dt = DateTime::createFromFormat( 'Y-m-d', $date );
		return $dt && $dt->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Validate a time string is in H:i 24-hour format and represents a real time.
	 *
	 * @param string $time Time string to validate.
	 * @return bool True if valid.
	 */
	private function is_valid_time( $time ) {
		return (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time );
	}

	/**
	 * Whether an order has been paid (re-rating must be blocked once paid).
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function order_is_paid( WC_Order $order ) {
		if ( method_exists( $order, 'is_paid' ) && $order->is_paid() ) {
			return true;
		}
		return (bool) $order->get_date_paid();
	}

}
