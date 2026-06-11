<?php
/**
 * Admin Add Rental Order for VAN-Jorn Rental POS
 *
 * Provides a WP-admin form to create primary rental orders manually,
 * with optional child order generation (remaining payment / security deposit).
 *
 * v2: Brought to parity with the VRC Importer — now writes full item-level
 *     meta, Kestrel reservation rows, VAT breakdown, AutomateWoo integration
 *     meta, email-friendly formatted meta, custom order title, driver details,
 *     and guest address fields. Fees are collected in full with the initial
 *     payment (not split across initial/remaining like the importer's legacy logic).
 *
 * INTEGRATION: Load this file in your main plugin bootstrap (vanjorn-rental-pos.php)
 * and instantiate it alongside VanPOS_Admin:
 *
 *   require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-add-order.php';
 *   new VanPOS_Admin_Add_Order();
 *
 * @package VJ_Rental_POS
 * @author  CMITEXPERTS TEAM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Admin_Add_Order {

	/** VAT rate used for tax breakdown (21% Dutch VAT). */
	const VAT_RATE = 0.21;

	/**
	 * Admin page hook suffix returned by add_submenu_page().
	 * Used to scope asset loading without hardcoding the menu-title-derived hook.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_vanpos_search_customers', array( $this, 'ajax_search_customers' ) );
		add_action( 'wp_ajax_vanpos_calc_rental_price', array( $this, 'ajax_calc_rental_price' ) );
		add_action( 'wp_ajax_vanpos_admin_create_order', array( $this, 'ajax_create_order' ) );
	}

	public function register_submenu() {
		$this->hook_suffix = add_submenu_page(
			'vanjorn-rental-pos',
			__( 'Add Rental Order', 'vanjorn-rental-pos' ),
			__( 'Add Rental Order', 'vanjorn-rental-pos' ),
			'manage_woocommerce',
			'vanpos-add-order',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		// Scope to the Add Rental Order screen. Match the hook suffix captured at
		// registration (robust against menu-title changes that alter the hook),
		// with the page query var as a fallback.
		$is_add_order_screen = (
			( '' !== $this->hook_suffix && $hook === $this->hook_suffix )
			|| ( isset( $_GET['page'] ) && 'vanpos-add-order' === sanitize_key( wp_unslash( $_GET['page'] ) ) )
		);
		if ( ! $is_add_order_screen ) {
			return;
		}

		wp_enqueue_style(
			'vanpos-admin-add-order',
			VANPOS_PLUGIN_URL . 'admin/css/admin-add-order.css',
			array(),
			VANPOS_VERSION
		);

		wp_enqueue_script(
			'vanpos-admin-add-order',
			VANPOS_PLUGIN_URL . 'admin/js/admin-add-order.js',
			array( 'jquery' ),
			VANPOS_VERSION,
			true
		);

		// Build product list for the dropdown
		$products = $this->get_rental_products();

		// Settings the form needs
		$settings = VanPOS_Functions::get_settings();

		wp_localize_script( 'vanpos-admin-add-order', 'vanposAddOrder', array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'vanpos_admin_add_order' ),
			'products'       => $products,
			'currency'       => get_woocommerce_currency_symbol(),
			'pickupDays'     => isset( $settings['vanpos_pickup_days'] ) ? $settings['vanpos_pickup_days'] : array( 4, 5 ),
			'minRentalDays'  => isset( $settings['vanpos_min_rental_days'] ) ? (int) $settings['vanpos_min_rental_days'] : 6,
			'maxRentalDays'  => isset( $settings['vanpos_max_rental_days'] ) ? (int) $settings['vanpos_max_rental_days'] : 22,
			'pickupTime'     => isset( $settings['vanpos_pickup_time'] ) ? $settings['vanpos_pickup_time'] : '15:00',
			'returnTime'     => isset( $settings['vanpos_return_time'] ) ? $settings['vanpos_return_time'] : '11:00',
			'dogEnabled'     => isset( $settings['vanpos_dog_enabled'] ) ? $settings['vanpos_dog_enabled'] : 'yes',
			'dogPrice'       => isset( $settings['vanpos_dog_price'] ) ? (float) $settings['vanpos_dog_price'] : 100,
			'cleaningEnabled' => isset( $settings['vanpos_cleaning_enabled'] ) ? $settings['vanpos_cleaning_enabled'] : 'yes',
			'cleaningPrice'  => isset( $settings['vanpos_cleaning_price'] ) ? (float) $settings['vanpos_cleaning_price'] : 100,
			'depositPct'     => isset( $settings['vanpos_deposit_percentage'] ) ? (float) $settings['vanpos_deposit_percentage'] : 50,
			'i18n' => array(
				'searching'       => __( 'Searching…', 'vanjorn-rental-pos' ),
				'noResults'       => __( 'No customers found.', 'vanjorn-rental-pos' ),
				'selectProduct'   => __( 'Select a van…', 'vanjorn-rental-pos' ),
				'calculating'     => __( 'Calculating…', 'vanjorn-rental-pos' ),
				'creating'        => __( 'Creating order…', 'vanjorn-rental-pos' ),
				'createOrder'     => __( 'Create Rental Order', 'vanjorn-rental-pos' ),
				'success'         => __( 'Order created successfully!', 'vanjorn-rental-pos' ),
				'viewOrder'       => __( 'View order →', 'vanjorn-rental-pos' ),
				'error'           => __( 'Error', 'vanjorn-rental-pos' ),
				'warnPickupDay'   => __( 'Note: This pickup date falls outside configured pickup days.', 'vanjorn-rental-pos' ),
				'warnReturnDay'   => __( 'Note: This return date falls outside configured return days.', 'vanjorn-rental-pos' ),
				'warnDuration'    => __( 'Note: Rental duration is outside the configured range (%min%–%max% days).', 'vanjorn-rental-pos' ),
				'warnAdvance'     => __( 'Note: Pickup is within the next 3 days.', 'vanjorn-rental-pos' ),
				'vanAvailable'    => __( 'Van is available for the selected dates.', 'vanjorn-rental-pos' ),
				'vanUnavailable'  => __( 'Van is not available for the selected dates.', 'vanjorn-rental-pos' ),
				// Days badge
				'daySingular'     => __( '%d day', 'vanjorn-rental-pos' ),
				'dayPlural'       => __( '%d days', 'vanjorn-rental-pos' ),
				// Price summary labels
				'summaryVan'            => __( 'Van', 'vanjorn-rental-pos' ),
				'summaryRentalDays'     => __( 'Rental days', 'vanjorn-rental-pos' ),
				'summaryTotalPrice'     => __( 'Total rental price', 'vanjorn-rental-pos' ),
				'summaryDogFee'         => __( 'Dog surcharge', 'vanjorn-rental-pos' ),
				'summaryCleaningFee'    => __( 'Cleaning service', 'vanjorn-rental-pos' ),
				'summaryFullPayment'    => __( 'Full payment (short-term)', 'vanjorn-rental-pos' ),
				'summaryInitialPayment' => __( 'Initial payment (%pct%%)', 'vanjorn-rental-pos' ),
				'summaryRemaining'      => __( 'Remaining (%pct%%)', 'vanjorn-rental-pos' ),
				'summaryGrandTotal'     => __( 'Grand total', 'vanjorn-rental-pos' ),
				'summarySecurityDeposit' => __( 'Security deposit', 'vanjorn-rental-pos' ),
				'summaryShortTermNote'  => __( 'This is a short-term booking — full payment is collected upfront.', 'vanjorn-rental-pos' ),
			),
		) );
	}

	private function get_rental_products() {
		$products = array();

		$query = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$product_id = get_the_ID();
				$product    = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				$products[] = array(
					'id'    => $product_id,
					'name'  => $product->get_name(),
					'price' => (float) $product->get_price(),
					'image' => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: '',
				);
			}
			wp_reset_postdata();
		}

		return $products;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap vanpos-admin-wrap vanpos-add-order-wrap">
			<h1 class="vanpos-page-title">
				<span class="dashicons dashicons-plus-alt" style="font-size:24px;width:24px;height:24px;margin-right:6px;vertical-align:text-bottom;"></span>
				<?php esc_html_e( 'Add Rental Order', 'vanjorn-rental-pos' ); ?>
			</h1>

			<div class="vanpos-page-intro">
				<p><?php esc_html_e( 'Create a new rental booking directly from the admin. Business-rule warnings are shown but do not block order creation.', 'vanjorn-rental-pos' ); ?></p>
			</div>

			<!-- Success / error banner (hidden by default) -->
			<div id="vanpos-ao-notice" class="vanpos-ao-notice" style="display:none;"></div>

			<div class="vanpos-ao-layout">
				<!-- Main Form -->
				<div class="vanpos-ao-main">

					<!-- Customer Section -->
					<div class="vanpos-card vanpos-ao-section" id="vanpos-ao-customer">
						<h2><?php esc_html_e( 'Customer', 'vanjorn-rental-pos' ); ?></h2>

						<div class="vanpos-ao-customer-mode">
							<label class="vanpos-ao-radio">
								<input type="radio" name="vanpos_customer_mode" value="existing" checked>
								<?php esc_html_e( 'Existing customer', 'vanjorn-rental-pos' ); ?>
							</label>
							<label class="vanpos-ao-radio">
								<input type="radio" name="vanpos_customer_mode" value="guest">
								<?php esc_html_e( 'Guest / new customer', 'vanjorn-rental-pos' ); ?>
							</label>
						</div>

						<!-- Existing customer search -->
						<div id="vanpos-ao-existing-customer" class="vanpos-ao-customer-panel">
							<label for="vanpos-ao-customer-search"><?php esc_html_e( 'Search by name or email', 'vanjorn-rental-pos' ); ?></label>
							<div class="vanpos-ao-search-wrap">
								<input type="text" id="vanpos-ao-customer-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Start typing…', 'vanjorn-rental-pos' ); ?>">
								<div id="vanpos-ao-customer-results" class="vanpos-ao-dropdown" style="display:none;"></div>
							</div>
							<div id="vanpos-ao-customer-selected" class="vanpos-ao-selected-customer" style="display:none;">
								<span id="vanpos-ao-customer-badge"></span>
								<button type="button" class="vanpos-ao-clear-customer" title="<?php esc_attr_e( 'Clear', 'vanjorn-rental-pos' ); ?>">&times;</button>
								<input type="hidden" id="vanpos-ao-customer-id" value="">
							</div>
						</div>

						<!-- Guest entry -->
						<div id="vanpos-ao-guest-customer" class="vanpos-ao-customer-panel" style="display:none;">
							<div class="vanpos-ao-row">
								<div class="vanpos-ao-field">
									<label for="vanpos-ao-first-name"><?php esc_html_e( 'First name', 'vanjorn-rental-pos' ); ?> <abbr>*</abbr></label>
									<input type="text" id="vanpos-ao-first-name">
								</div>
								<div class="vanpos-ao-field">
									<label for="vanpos-ao-last-name"><?php esc_html_e( 'Last name', 'vanjorn-rental-pos' ); ?> <abbr>*</abbr></label>
									<input type="text" id="vanpos-ao-last-name">
								</div>
							</div>
							<div class="vanpos-ao-row">
								<div class="vanpos-ao-field">
									<label for="vanpos-ao-email"><?php esc_html_e( 'Email', 'vanjorn-rental-pos' ); ?> <abbr>*</abbr></label>
									<input type="email" id="vanpos-ao-email">
								</div>
								<div class="vanpos-ao-field">
									<label for="vanpos-ao-phone"><?php esc_html_e( 'Phone', 'vanjorn-rental-pos' ); ?></label>
									<input type="tel" id="vanpos-ao-phone">
								</div>
							</div>
							<!-- Guest address fields -->
							<div class="vanpos-ao-row">
								<div class="vanpos-ao-field">
									<label for="vanpos-ao-address"><?php esc_html_e( 'Address', 'vanjorn-rental-pos' ); ?></label>
									<input type="text" id="vanpos-ao-address">
								</div>
								<div class="vanpos-ao-field">
									<label for="vanpos-ao-postcode"><?php esc_html_e( 'Postcode', 'vanjorn-rental-pos' ); ?></label>
									<input type="text" id="vanpos-ao-postcode">
								</div>
							</div>
							<div class="vanpos-ao-row">
								<div class="vanpos-ao-field">
									<label for="vanpos-ao-city"><?php esc_html_e( 'City', 'vanjorn-rental-pos' ); ?></label>
									<input type="text" id="vanpos-ao-city">
								</div>
								<div class="vanpos-ao-field">
									<label for="vanpos-ao-country"><?php esc_html_e( 'Country', 'vanjorn-rental-pos' ); ?></label>
									<select id="vanpos-ao-country">
										<option value="NL" selected><?php esc_html_e( 'Netherlands', 'vanjorn-rental-pos' ); ?></option>
										<option value="BE"><?php esc_html_e( 'Belgium', 'vanjorn-rental-pos' ); ?></option>
										<option value="DE"><?php esc_html_e( 'Germany', 'vanjorn-rental-pos' ); ?></option>
										<option value="FR"><?php esc_html_e( 'France', 'vanjorn-rental-pos' ); ?></option>
										<option value="GB"><?php esc_html_e( 'United Kingdom', 'vanjorn-rental-pos' ); ?></option>
									</select>
								</div>
							</div>

							<!-- Create account option -->
							<div class="vanpos-ao-row vanpos-ao-account-row">
								<div class="vanpos-ao-field vanpos-ao-field--wide">
									<label class="vanpos-ao-check">
										<input type="checkbox" id="vanpos-ao-create-account">
										<span><?php esc_html_e( 'Create customer account', 'vanjorn-rental-pos' ); ?></span>
									</label>
									<label class="vanpos-ao-check vanpos-ao-sub-check" id="vanpos-ao-send-reset-wrap" style="display:none;">
										<input type="checkbox" id="vanpos-ao-send-reset" checked>
										<span><?php esc_html_e( 'Send password reset email', 'vanjorn-rental-pos' ); ?></span>
									</label>
								</div>
							</div>

							<!-- Driver details (optional, collapsible) -->
							<div class="vanpos-ao-driver-toggle">
								<button type="button" class="button button-link" id="vanpos-ao-driver-toggle">
									<span class="dashicons dashicons-id-alt" style="vertical-align:text-bottom;"></span>
									<?php esc_html_e( 'Driver details', 'vanjorn-rental-pos' ); ?>
									<span class="dashicons dashicons-arrow-down-alt2 vanpos-ao-toggle-arrow" style="vertical-align:text-bottom;"></span>
								</button>
							</div>
							<div id="vanpos-ao-driver-fields" class="vanpos-ao-driver-fields" style="display:none;">
								<div class="vanpos-ao-row">
									<div class="vanpos-ao-field">
										<label for="vanpos-ao-middle-name"><?php esc_html_e( 'Initials (voorletters)', 'vanjorn-rental-pos' ); ?></label>
										<input type="text" id="vanpos-ao-middle-name" placeholder="<?php esc_attr_e( 'e.g. J.A.', 'vanjorn-rental-pos' ); ?>">
									</div>
									<div class="vanpos-ao-field">
										<label for="vanpos-ao-dob"><?php esc_html_e( 'Date of birth', 'vanjorn-rental-pos' ); ?> <abbr>*</abbr></label>
										<input type="date" id="vanpos-ao-dob">
									</div>
								</div>
								<div class="vanpos-ao-row">
									<div class="vanpos-ao-field">
										<label for="vanpos-ao-license-issue"><?php esc_html_e( 'License issue date', 'vanjorn-rental-pos' ); ?></label>
										<input type="date" id="vanpos-ao-license-issue">
									</div>
									<div class="vanpos-ao-field">
										<label for="vanpos-ao-license-obtained"><?php esc_html_e( 'License obtained date', 'vanjorn-rental-pos' ); ?></label>
										<input type="date" id="vanpos-ao-license-obtained">
									</div>
								</div>

								<h4 class="vanpos-ao-driver-sub"><?php esc_html_e( 'Second driver', 'vanjorn-rental-pos' ); ?></h4>
								<div class="vanpos-ao-row">
									<div class="vanpos-ao-field">
										<label for="vanpos-ao-sd-name"><?php esc_html_e( 'Name', 'vanjorn-rental-pos' ); ?></label>
										<input type="text" id="vanpos-ao-sd-name">
									</div>
									<div class="vanpos-ao-field">
										<label for="vanpos-ao-sd-dob"><?php esc_html_e( 'Date of birth', 'vanjorn-rental-pos' ); ?></label>
										<input type="date" id="vanpos-ao-sd-dob">
									</div>
								</div>
								<div class="vanpos-ao-row">
									<div class="vanpos-ao-field">
										<label for="vanpos-ao-sd-license-issue"><?php esc_html_e( 'License issue date', 'vanjorn-rental-pos' ); ?></label>
										<input type="date" id="vanpos-ao-sd-license-issue">
									</div>
									<div class="vanpos-ao-field">
										<label for="vanpos-ao-sd-license-obtained"><?php esc_html_e( 'License obtained date', 'vanjorn-rental-pos' ); ?></label>
										<input type="date" id="vanpos-ao-sd-license-obtained">
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Rental Details Section -->
					<div class="vanpos-card vanpos-ao-section" id="vanpos-ao-rental">
						<h2><?php esc_html_e( 'Rental Details', 'vanjorn-rental-pos' ); ?></h2>

						<div class="vanpos-ao-field vanpos-ao-field--wide">
							<label for="vanpos-ao-product"><?php esc_html_e( 'Van / Product', 'vanjorn-rental-pos' ); ?> <abbr>*</abbr></label>
							<select id="vanpos-ao-product">
								<option value=""><?php esc_html_e( 'Select a van…', 'vanjorn-rental-pos' ); ?></option>
							</select>
						</div>

						<div class="vanpos-ao-row">
							<div class="vanpos-ao-field">
								<label for="vanpos-ao-pickup-date"><?php esc_html_e( 'Pickup date', 'vanjorn-rental-pos' ); ?> <abbr>*</abbr></label>
								<input type="date" id="vanpos-ao-pickup-date">
							</div>
							<div class="vanpos-ao-field">
								<label for="vanpos-ao-pickup-time"><?php esc_html_e( 'Pickup time', 'vanjorn-rental-pos' ); ?></label>
								<input type="time" id="vanpos-ao-pickup-time" value="<?php echo esc_attr( VanPOS_Functions::get_setting( 'vanpos_pickup_time', '15:00' ) ); ?>">
							</div>
						</div>

						<div class="vanpos-ao-row">
							<div class="vanpos-ao-field">
								<label for="vanpos-ao-return-date"><?php esc_html_e( 'Return date', 'vanjorn-rental-pos' ); ?> <abbr>*</abbr></label>
								<input type="date" id="vanpos-ao-return-date">
							</div>
							<div class="vanpos-ao-field">
								<label for="vanpos-ao-return-time"><?php esc_html_e( 'Return time', 'vanjorn-rental-pos' ); ?></label>
								<input type="time" id="vanpos-ao-return-time" value="<?php echo esc_attr( VanPOS_Functions::get_setting( 'vanpos_return_time', '11:00' ) ); ?>">
							</div>
						</div>

						<!-- Warnings area (non-blocking) -->
						<div id="vanpos-ao-warnings" class="vanpos-ao-warnings" style="display:none;"></div>

						<!-- Rental days badge -->
						<div id="vanpos-ao-days-badge" class="vanpos-ao-days-badge" style="display:none;"></div>

						<!-- Custom price per day -->
						<div class="vanpos-ao-row">
							<div class="vanpos-ao-field vanpos-ao-field--wide">
								<label for="vanpos-ao-price-per-day"><?php esc_html_e( 'Price per day (incl. BTW)', 'vanjorn-rental-pos' ); ?></label>
								<div class="vanpos-ao-price-input">
									<span class="vanpos-ao-price-currency"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
									<input type="number" id="vanpos-ao-price-per-day" step="0.01" min="0" inputmode="decimal" placeholder="<?php esc_attr_e( 'Auto', 'vanjorn-rental-pos' ); ?>">
									<a href="#" id="vanpos-ao-price-reset" class="vanpos-ao-price-reset" style="display:none;"><?php esc_html_e( 'Reset to default', 'vanjorn-rental-pos' ); ?></a>
								</div>
								<p class="description"><?php esc_html_e( 'Auto-filled from catalogue pricing. Edit to set a custom daily rate — total becomes rate × rental days.', 'vanjorn-rental-pos' ); ?></p>
							</div>
						</div>
					</div>

					<!-- Options Section -->
					<div class="vanpos-card vanpos-ao-section" id="vanpos-ao-options">
						<h2><?php esc_html_e( 'Options', 'vanjorn-rental-pos' ); ?></h2>

						<div class="vanpos-ao-checks">
							<label class="vanpos-ao-check" id="vanpos-ao-dog-wrap" style="display:none;">
								<input type="checkbox" id="vanpos-ao-dog">
								<span><?php esc_html_e( 'Include dog', 'vanjorn-rental-pos' ); ?> (<span id="vanpos-ao-dog-price-label"></span>)</span>
							</label>
							<label class="vanpos-ao-check" id="vanpos-ao-cleaning-wrap" style="display:none;">
								<input type="checkbox" id="vanpos-ao-cleaning" checked>
								<span><?php esc_html_e( 'Cleaning service', 'vanjorn-rental-pos' ); ?> (<span id="vanpos-ao-cleaning-price-label"></span>)</span>
							</label>
						</div>
					</div>

					<!-- Order Settings Section -->
					<div class="vanpos-card vanpos-ao-section" id="vanpos-ao-order-settings">
						<h2><?php esc_html_e( 'Order Settings', 'vanjorn-rental-pos' ); ?></h2>

						<div class="vanpos-ao-field vanpos-ao-field--wide">
							<label for="vanpos-ao-status"><?php esc_html_e( 'Order status', 'vanjorn-rental-pos' ); ?></label>
							<select id="vanpos-ao-status">
								<option value="pending"><?php esc_html_e( 'Pending payment', 'vanjorn-rental-pos' ); ?></option>
								<option value="processing"><?php esc_html_e( 'Processing', 'vanjorn-rental-pos' ); ?></option>
								<option value="on-hold"><?php esc_html_e( 'On hold', 'vanjorn-rental-pos' ); ?></option>
								<option value="completed"><?php esc_html_e( 'Completed', 'vanjorn-rental-pos' ); ?></option>
							</select>
						</div>

						<div class="vanpos-ao-checks vanpos-ao-child-order-checks">
							<label class="vanpos-ao-check">
								<input type="checkbox" id="vanpos-ao-create-remaining">
								<span><?php esc_html_e( 'Create remaining payment order', 'vanjorn-rental-pos' ); ?></span>
							</label>
							<label class="vanpos-ao-check">
								<input type="checkbox" id="vanpos-ao-create-deposit">
								<span><?php esc_html_e( 'Create security deposit order', 'vanjorn-rental-pos' ); ?></span>
							</label>
						</div>

						<div class="vanpos-ao-field vanpos-ao-field--wide">
							<label for="vanpos-ao-note"><?php esc_html_e( 'Admin note (optional)', 'vanjorn-rental-pos' ); ?></label>
							<textarea id="vanpos-ao-note" rows="3" placeholder="<?php esc_attr_e( 'Internal note visible only to admins…', 'vanjorn-rental-pos' ); ?>"></textarea>
						</div>
					</div>
				</div>

				<!-- Sidebar: Price Summary -->
				<aside class="vanpos-ao-aside">
					<div class="vanpos-card vanpos-ao-summary" id="vanpos-ao-summary">
						<h2><?php esc_html_e( 'Price Summary', 'vanjorn-rental-pos' ); ?></h2>
						<div id="vanpos-ao-summary-body" class="vanpos-ao-summary-body">
							<p class="vanpos-ao-summary-empty"><?php esc_html_e( 'Select a van and dates to see pricing.', 'vanjorn-rental-pos' ); ?></p>
						</div>
					</div>

					<!-- Availability status (populated by JS after price calc) -->
					<div id="vanpos-ao-availability" class="vanpos-ao-availability" style="display:none;"></div>
					<div id="vanpos-ao-override-wrap" class="vanpos-ao-override-wrap" style="display:none;">
						<label class="vanpos-ao-check">
							<input type="checkbox" id="vanpos-ao-availability-override">
							<span><?php esc_html_e( 'Override — create order despite unavailability', 'vanjorn-rental-pos' ); ?></span>
						</label>
					</div>

					<button type="button" id="vanpos-ao-submit" class="button button-primary button-hero vanpos-ao-submit" disabled>
						<?php esc_html_e( 'Create Rental Order', 'vanjorn-rental-pos' ); ?>
					</button>
				</aside>
			</div>
		</div>
		<?php
	}

	public function ajax_search_customers() {
		check_ajax_referer( 'vanpos_admin_add_order', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ) );
		}

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'customers' => array() ) );
		}

		$users = get_users( array(
			'search'         => '*' . $term . '*',
			'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
			'number'         => 10,
			'orderby'        => 'display_name',
		) );

		// Also search billing name meta
		$meta_users = get_users( array(
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => 'billing_first_name',
					'value'   => $term,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'billing_last_name',
					'value'   => $term,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'billing_email',
					'value'   => $term,
					'compare' => 'LIKE',
				),
			),
			'number' => 10,
		) );

		// Merge, dedupe
		$seen = array();
		$results = array();

		foreach ( array_merge( $users, $meta_users ) as $user ) {
			if ( isset( $seen[ $user->ID ] ) ) {
				continue;
			}
			$seen[ $user->ID ] = true;

			$first = get_user_meta( $user->ID, 'billing_first_name', true );
			$last  = get_user_meta( $user->ID, 'billing_last_name', true );
			$email = $user->user_email;
			$phone = get_user_meta( $user->ID, 'billing_phone', true );

			$display = trim( $first . ' ' . $last );
			if ( ! $display ) {
				$display = $user->display_name;
			}

			$results[] = array(
				'id'         => $user->ID,
				'display'    => $display,
				'email'      => $email,
				'phone'      => $phone,
				'first_name' => $first,
				'last_name'  => $last,
			);
		}

		wp_send_json_success( array( 'customers' => $results ) );
	}

	public function ajax_calc_rental_price() {
		check_ajax_referer( 'vanpos_admin_add_order', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ) );
		}

		$product_id  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$pickup_date = isset( $_POST['pickup_date'] ) ? sanitize_text_field( wp_unslash( $_POST['pickup_date'] ) ) : '';
		$return_date = isset( $_POST['return_date'] ) ? sanitize_text_field( wp_unslash( $_POST['return_date'] ) ) : '';
		$price_raw        = isset( $_POST['price_per_day'] ) ? trim( (string) wp_unslash( $_POST['price_per_day'] ) ) : '';
		$has_custom_price = ( '' !== $price_raw && is_numeric( $price_raw ) && (float) $price_raw >= 0 );
		$price_per_day    = $has_custom_price ? (float) $price_raw : 0;

		if ( ! $product_id || ! $pickup_date || ! $return_date ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'vanjorn-rental-pos' ) ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'vanjorn-rental-pos' ) ) );
		}

		// Kestrel-compatible day calculation
		$pickup_dt = new DateTime( $pickup_date );
		$return_dt = new DateTime( $return_date );
		$days      = $pickup_dt->diff( $return_dt )->days + 1;

		// CMIT CODE - billing unit is NIGHTS (days - 1). $days stays inclusive for the
		// duration badge; all money below is computed on $nights.
		$nights    = VanPOS_Functions::rental_nights_from_dates( $pickup_date, $return_date );

		if ( $days < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Return date must be after pickup date.', 'vanjorn-rental-pos' ) ) );
		}

		if ( $has_custom_price ) {
			// Admin-supplied gross nightly rate (incl. BTW).
			$total_price       = round( $price_per_day * $nights, wc_get_price_decimals() );
			$suggested_per_day = round( $price_per_day, 2 );
		} else {
			$total_price       = VanPOS_Functions::calculate_rental_price( $product_id, $nights );
			$suggested_per_day = $nights > 0 ? round( $total_price / $nights, 2 ) : 0;
		}

		// Deposit split info
		$deposit_pct = (float) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 );
		$short_term_threshold = (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_days_before_pickup', 14 );
		$order_date      = new DateTime( current_time( 'Y-m-d' ) );
		$days_until      = $order_date->diff( $pickup_dt )->days;
		$is_short_term   = ( $days_until < $short_term_threshold );

		$initial_payment   = $is_short_term ? $total_price : $total_price * ( $deposit_pct / 100 );
		$remaining_payment = $is_short_term ? 0 : $total_price - $initial_payment;

		// Security deposit product price
		$sec_deposit_amount = 0;
		$sec_deposit_product_id = VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' );
		if ( $sec_deposit_product_id ) {
			$sec_product = wc_get_product( $sec_deposit_product_id );
			if ( $sec_product ) {
				$sec_deposit_amount = (float) $sec_product->get_price();
			}
		}

		// Availability check
		$avail = $this->check_product_availability( $product_id, $pickup_date, $return_date );

		wp_send_json_success( array(
			'days'              => $days,
			'nights'            => $nights,
			'price_per_day'     => $suggested_per_day,
			'total_price'       => $total_price,
			'initial_payment'   => round( $initial_payment, 2 ),
			'remaining_payment' => round( $remaining_payment, 2 ),
			'is_short_term'     => $is_short_term,
			'deposit_pct'       => $deposit_pct,
			'security_deposit'  => $sec_deposit_amount,
			'product_name'      => $product->get_name(),
			'available'         => $avail['available'],
			'avail_message'     => $avail['message'],
		) );
	}

	public function ajax_create_order() {
		check_ajax_referer( 'vanpos_admin_add_order', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ) );
		}

		// Collect & sanitise inputs
		$customer_mode = isset( $_POST['customer_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_mode'] ) ) : 'guest';
		$customer_id   = ( 'existing' === $customer_mode && isset( $_POST['customer_id'] ) ) ? absint( $_POST['customer_id'] ) : 0;

		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name  = isset( $_POST['last_name'] )  ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) )  : '';
		$email      = isset( $_POST['email'] )       ? sanitize_email( wp_unslash( $_POST['email'] ) )           : '';
		$phone      = isset( $_POST['phone'] )       ? sanitize_text_field( wp_unslash( $_POST['phone'] ) )      : '';

		// Guest address fields
		$address  = isset( $_POST['address'] )  ? sanitize_text_field( wp_unslash( $_POST['address'] ) )  : '';
		$postcode = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '';
		$city     = isset( $_POST['city'] )     ? sanitize_text_field( wp_unslash( $_POST['city'] ) )     : '';
		$country  = isset( $_POST['country'] )  ? sanitize_text_field( wp_unslash( $_POST['country'] ) )  : '';

		$product_id  = isset( $_POST['product_id'] )  ? absint( $_POST['product_id'] )  : 0;
		$pickup_date = isset( $_POST['pickup_date'] )  ? sanitize_text_field( wp_unslash( $_POST['pickup_date'] ) )  : '';
		$return_date = isset( $_POST['return_date'] )  ? sanitize_text_field( wp_unslash( $_POST['return_date'] ) )  : '';
		$pickup_time = isset( $_POST['pickup_time'] )  ? sanitize_text_field( wp_unslash( $_POST['pickup_time'] ) )  : VanPOS_Functions::get_setting( 'vanpos_pickup_time', '15:00' );
		$return_time = isset( $_POST['return_time'] )  ? sanitize_text_field( wp_unslash( $_POST['return_time'] ) )  : VanPOS_Functions::get_setting( 'vanpos_return_time', '11:00' );

		$include_dog      = isset( $_POST['include_dog'] )      && 'true' === $_POST['include_dog'];
		$include_cleaning = isset( $_POST['include_cleaning'] ) && 'true' === $_POST['include_cleaning'];
		$price_raw_create = isset( $_POST['price_per_day'] ) ? trim( (string) wp_unslash( $_POST['price_per_day'] ) ) : '';
		$has_custom_price = ( '' !== $price_raw_create && is_numeric( $price_raw_create ) && (float) $price_raw_create >= 0 );
		$price_per_day    = $has_custom_price ? (float) $price_raw_create : 0;

		$order_status       = isset( $_POST['order_status'] )       ? sanitize_text_field( wp_unslash( $_POST['order_status'] ) ) : 'pending';
		$create_remaining   = isset( $_POST['create_remaining'] )   && 'true' === $_POST['create_remaining'];
		$create_deposit     = isset( $_POST['create_deposit'] )     && 'true' === $_POST['create_deposit'];
		$admin_note         = isset( $_POST['admin_note'] )         ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';

		// Guest account creation
		$create_account     = isset( $_POST['create_account'] )     && 'true' === $_POST['create_account'];
		$send_password_reset = isset( $_POST['send_password_reset'] ) && 'true' === $_POST['send_password_reset'];
		$availability_override = isset( $_POST['availability_override'] ) && 'true' === $_POST['availability_override'];

		// Driver details (optional)
		$driver_details = array(
			'middle_name'                       => isset( $_POST['middle_name'] )              ? sanitize_text_field( wp_unslash( $_POST['middle_name'] ) )              : '',
			'date_of_birth'                     => isset( $_POST['date_of_birth'] )            ? sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ) )            : '',
			'driver_license_issue_date'         => isset( $_POST['driver_license_issue_date'] ) ? sanitize_text_field( wp_unslash( $_POST['driver_license_issue_date'] ) ) : '',
			'driver_license_obtained_date'      => isset( $_POST['driver_license_obtained_date'] ) ? sanitize_text_field( wp_unslash( $_POST['driver_license_obtained_date'] ) ) : '',
			'second_driver_name'                => isset( $_POST['second_driver_name'] )       ? sanitize_text_field( wp_unslash( $_POST['second_driver_name'] ) )       : '',
			'second_driver_date_of_birth'       => isset( $_POST['second_driver_date_of_birth'] ) ? sanitize_text_field( wp_unslash( $_POST['second_driver_date_of_birth'] ) ) : '',
			'second_driver_license_issue_date'  => isset( $_POST['second_driver_license_issue_date'] ) ? sanitize_text_field( wp_unslash( $_POST['second_driver_license_issue_date'] ) ) : '',
			'second_driver_license_obtained_date' => isset( $_POST['second_driver_license_obtained_date'] ) ? sanitize_text_field( wp_unslash( $_POST['second_driver_license_obtained_date'] ) ) : '',
		);

		// Validate essentials
		if ( ! $product_id || ! $pickup_date || ! $return_date ) {
			wp_send_json_error( array( 'message' => __( 'Product and dates are required.', 'vanjorn-rental-pos' ) ) );
		}

		if ( 'existing' === $customer_mode && ! $customer_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select a customer.', 'vanjorn-rental-pos' ) ) );
		}

		if ( 'guest' === $customer_mode && ( ! $first_name || ! $last_name || ! $email ) ) {
			wp_send_json_error( array( 'message' => __( 'First name, last name and email are required for guest orders.', 'vanjorn-rental-pos' ) ) );
		}

		if ( 'guest' === $customer_mode && empty( $driver_details['date_of_birth'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Date of birth is required.', 'vanjorn-rental-pos' ) ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'vanjorn-rental-pos' ) ) );
		}

		// Calculate pricing
		$pickup_dt = new DateTime( $pickup_date );
		$return_dt = new DateTime( $return_date );
		$days      = $pickup_dt->diff( $return_dt )->days + 1;

		// CMIT CODE - billing unit is NIGHTS (days - 1). $days stays inclusive for meta.
		$nights    = VanPOS_Functions::rental_nights_from_dates( $pickup_date, $return_date );

		if ( $days < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Return date must be after pickup date.', 'vanjorn-rental-pos' ) ) );
		}

		if ( $has_custom_price ) {
			$total_price = round( $price_per_day * $nights, wc_get_price_decimals() );
		} else {
			$total_price = VanPOS_Functions::calculate_rental_price( $product_id, $nights );
		}

		// Short-term detection
		$short_term_threshold = (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_days_before_pickup', 14 );
		$order_date_dt = new DateTime( current_time( 'Y-m-d' ) );
		$days_until    = $order_date_dt->diff( $pickup_dt )->days;
		$is_short_term = ( $days_until < $short_term_threshold );

		$deposit_pct       = (float) VanPOS_Functions::get_setting( 'vanpos_deposit_percentage', 50 );

		// Always calculate the logical deposit/remaining split for meta purposes.
		// This data must exist even when the remaining child order isn't created
		// now, so it can be created later.
		if ( $is_short_term ) {
			$deposit_payment   = $total_price;
			$remaining_payment = 0;
		} else {
			$deposit_payment   = $total_price * ( $deposit_pct / 100 );
			$remaining_payment = $total_price - $deposit_payment;
		}

		// The parent order's actual charge depends on whether a remaining
		// child order captures the other half. If not, the parent must
		// charge the full rental amount.
		if ( $create_remaining && $remaining_payment > 0 ) {
			$initial_payment = $deposit_payment;
		} else {
			$initial_payment = $total_price;
		}

		// Fees (dog / cleaning) are collected in full with the initial payment.
		// They are added as WC_Order_Item_Fee line items on the main order,
		// so the remaining payment stays purely the rental-amount split.
		$dog_price      = 0;
		$cleaning_price = 0;
		$fee_total      = 0;

		if ( $include_dog ) {
			$dog_price = (float) VanPOS_Functions::get_setting( 'vanpos_dog_price', 100 );
			$fee_total += $dog_price;
		}
		if ( $include_cleaning ) {
			$cleaning_price = (float) VanPOS_Functions::get_setting( 'vanpos_cleaning_price', 100 );
			$fee_total += $cleaning_price;
		}

		// Availability check (blocking unless overridden) 
		if ( ! $availability_override ) {
			$avail = $this->check_product_availability( $product_id, $pickup_date, $return_date );
			if ( ! $avail['available'] ) {
				wp_send_json_error( array(
					'message'   => $avail['message'],
					'available' => false,
				) );
			}
		}

		// VAT breakdown: derive rate from the product's actual tax class (handles 21% / 9% / 0%).
		$vat_rate     = VanPOS_Order_Manager::get_vat_rate_fraction( $product->get_tax_class() );
		$vat_rate_id  = VanPOS_Order_Manager::get_vat_rate_id( $product->get_tax_class() );
		$initial_excl = round( $initial_payment / ( 1 + $vat_rate ), 2 );
		$initial_tax  = round( $initial_payment - $initial_excl, 2 );

		// Resolve customer data
		if ( $customer_id ) {
			$user = get_userdata( $customer_id );
			if ( ! $user ) {
				wp_send_json_error( array( 'message' => __( 'Customer not found.', 'vanjorn-rental-pos' ) ) );
			}
			$first_name = $first_name ?: get_user_meta( $customer_id, 'billing_first_name', true );
			$last_name  = $last_name  ?: get_user_meta( $customer_id, 'billing_last_name', true );
			$email      = $email      ?: $user->user_email;
			$phone      = $phone      ?: get_user_meta( $customer_id, 'billing_phone', true );
			// Also resolve address for existing customers
			$address  = $address  ?: get_user_meta( $customer_id, 'billing_address_1', true );
			$postcode = $postcode ?: get_user_meta( $customer_id, 'billing_postcode', true );
			$city     = $city     ?: get_user_meta( $customer_id, 'billing_city', true );
			$country  = $country  ?: get_user_meta( $customer_id, 'billing_country', true );
		}

		// Create guest account (if requested)
		$account_created = false;
		if ( 'guest' === $customer_mode && $create_account && $email ) {
			// Check if a user with this email already exists
			$existing_user_id = email_exists( $email );
			if ( $existing_user_id ) {
				// Attach existing account instead of creating a new one
				$customer_id     = $existing_user_id;
				$account_created = false;
			} else {
				$new_user_id = wc_create_new_customer( $email, '', wp_generate_password(), array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
				) );

				if ( is_wp_error( $new_user_id ) ) {
					wp_send_json_error( array( 'message' => sprintf(
						/* translators: %s is the error message */
						__( 'Could not create customer account: %s', 'vanjorn-rental-pos' ),
						$new_user_id->get_error_message()
					) ) );
				}

				$customer_id     = $new_user_id;
				$account_created = true;

				// Set billing profile on the new user
				update_user_meta( $customer_id, 'billing_first_name', $first_name );
				update_user_meta( $customer_id, 'billing_last_name', $last_name );
				update_user_meta( $customer_id, 'billing_email', $email );
				if ( $phone )    update_user_meta( $customer_id, 'billing_phone', $phone );
				if ( $address )  update_user_meta( $customer_id, 'billing_address_1', $address );
				if ( $postcode ) update_user_meta( $customer_id, 'billing_postcode', $postcode );
				if ( $city )     update_user_meta( $customer_id, 'billing_city', $city );
				if ( $country )  update_user_meta( $customer_id, 'billing_country', $country );

				// Save driver details to user meta (same keys as importer + checkout)
				$driver_user_meta = array(
					'billing_middle_name'                 => 'middle_name',
					'date_of_birth'                       => 'date_of_birth',
					'driver_license_issue_date'           => 'driver_license_issue_date',
					'driver_license_obtained_date'        => 'driver_license_obtained_date',
					'second_driver_name'                  => 'second_driver_name',
					'second_driver_date_of_birth'         => 'second_driver_date_of_birth',
					'second_driver_license_issue_date'    => 'second_driver_license_issue_date',
					'second_driver_license_obtained_date' => 'second_driver_license_obtained_date',
				);
				foreach ( $driver_user_meta as $meta_key => $dd_key ) {
					if ( ! empty( $driver_details[ $dd_key ] ) ) {
						update_user_meta( $customer_id, $meta_key, $driver_details[ $dd_key ] );
					}
				}

				// Send password reset email so the customer can set their password
				if ( $send_password_reset ) {
					// wp_new_user_notification with 'user' sends the set-password email
					wp_new_user_notification( $customer_id, null, 'user' );
				}
			}
		}

		// Create the primary rental order
		$order = wc_create_order( array(
			'customer_id' => $customer_id,
			'status'      => 'pending', // Set final status after save to trigger hooks correctly.
		) );

		if ( is_wp_error( $order ) ) {
			wp_send_json_error( array( 'message' => $order->get_error_message() ) );
		}

		// Explicitly set currency
		$order->set_currency( get_woocommerce_currency() );

		// Set billing data
		$order->set_billing_first_name( $first_name );
		$order->set_billing_last_name( $last_name );
		$order->set_billing_email( $email );
		if ( $phone ) {
			$order->set_billing_phone( $phone );
		}

		// Set billing address (from guest fields or existing customer)
		if ( $address ) {
			$order->set_billing_address_1( $address );
		}
		if ( $postcode ) {
			$order->set_billing_postcode( $postcode );
		}
		if ( $city ) {
			$order->set_billing_city( $city );
		}
		if ( $country ) {
			$order->set_billing_country( $country );
		}

		// Copy remaining billing profile fields from existing customer
		if ( $customer_id ) {
			$extra_billing_fields = array( 'billing_address_2', 'billing_state', 'billing_company' );
			foreach ( $extra_billing_fields as $field ) {
				$val = get_user_meta( $customer_id, $field, true );
				if ( $val ) {
					$method = 'set_' . $field;
					if ( method_exists( $order, $method ) ) {
						$order->$method( $val );
					}
				}
			}
		}

		// meta and VAT breakdown, replacing the simple $order->add_product() call.
		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_name( $product->get_name() );
		$item->set_subtotal( $initial_excl );
		$item->set_total( $initial_excl );
		$item->set_subtotal_tax( $initial_tax );
		$item->set_total_tax( $initial_tax );
		$item->set_taxes( array(
			'total'    => array( $vat_rate_id => $initial_tax ),
			'subtotal' => array( $vat_rate_id => $initial_tax ),
		) );

		// Item-level rental meta (required by set_rental_order_type, Kestrel, checkout)
		// Item meta: unprefixed keys (legacy / some admin queries).
		$item->add_meta_data( 'vanpos_pickup_date', $pickup_date );
		$item->add_meta_data( 'vanpos_return_date', $return_date );
		$item->add_meta_data( 'vanpos_pickup_time', $pickup_time );
		$item->add_meta_data( 'vanpos_return_time', $return_time );
		$item->add_meta_data( 'vanpos_rental_days', $days );
		$item->add_meta_data( 'vanpos_rental_nights', $nights );

		// Underscore-prefixed keys match checkout (see VanPOS_Deposit_Manager::checkout_create_order_line_item)
		// so storefront and admin-created orders share the same line-item shape for displays and tools.
		$item->add_meta_data( '_vanpos_pickup_date', $pickup_date );
		$item->add_meta_data( '_vanpos_return_date', $return_date );
		$item->add_meta_data( '_vanpos_pickup_time', $pickup_time );
		$item->add_meta_data( '_vanpos_return_time', $return_time );
		$item->add_meta_data( '_vanpos_rental_days', $days );
		$item->add_meta_data( '_vanpos_rental_nights', $nights );

		// Flags on the line item (not only order meta) so VanPOS_Item_Display and PDF paths
		// that read $item->get_meta( '_vanpos_include_*' ) behave like checkout orders.
		if ( $include_dog ) {
			$item->add_meta_data( '_vanpos_include_dog', true );
		}
		if ( $include_cleaning ) {
			$item->add_meta_data( '_vanpos_include_cleaning', true );
		}

		$item->add_meta_data( '_vanpos_original_price', $total_price );
		$item->add_meta_data( '_vanpos_remaining_amount', $remaining_payment );
		if ( $has_custom_price ) {
			$item->add_meta_data( '_vanpos_price_per_day', round( $price_per_day, 2 ) );
		}

		// Kestrel rental-products compatibility fields
		$item->add_meta_data( 'wcrp_rental_products_rent_from', $pickup_date );
		$item->add_meta_data( 'wcrp_rental_products_rent_to', $return_date );

		$order->add_item( $item );

		// Explicit VAT tax item row
		if ( $initial_tax > 0 ) {
			$tax_item = new WC_Order_Item_Tax();
			// Resolve the rate label from WC tax tables (e.g. "BTW 21%") so the
			// parent order matches child orders built by update_child_order_amount().
			$rate_label = class_exists( 'WC_Tax' ) ? WC_Tax::get_rate_label( $vat_rate_id ) : '';
			if ( ! $rate_label ) {
				/* translators: %d is the WooCommerce tax rate ID */
				$rate_label = sprintf( __( 'VAT-%d', 'vanjorn-rental-pos' ), $vat_rate_id );
			}
			$tax_item->set_name( $rate_label );
			$tax_item->set_rate_id( $vat_rate_id );
			$tax_item->set_tax_total( $initial_tax );
			$tax_item->set_shipping_tax_total( 0 );
			$order->add_item( $tax_item );
		}

		// Rental metadata (mirrors create_primary_rental_order)
		$booking_ref = VanPOS_Order_Manager::generate_booking_reference();

		$order->update_meta_data( '_vanpos_order_type', 'primary_rental' );
		$order->update_meta_data( '_vanpos_pickup_date', $pickup_date );
		$order->update_meta_data( '_vanpos_pickup_time', $pickup_time );
		$order->update_meta_data( '_vanpos_return_date', $return_date );
		$order->update_meta_data( '_vanpos_return_time', $return_time );
		$order->update_meta_data( '_vanpos_rental_days', $days );
		$order->update_meta_data( '_vanpos_rental_nights', $nights );
		$order->update_meta_data( '_vanpos_total_price', $total_price );
		// _vanpos_initial_payment must record what the parent order actually charges.
		// When $create_remaining is true this equals $deposit_payment (the split half);
		// when false (full payment collected now) it equals $total_price. Using
		// $deposit_payment here instead would cause update_missing_rental_metadata()
		// to back-derive a phantom 50% remaining that was never owed.
		$order->update_meta_data( '_vanpos_initial_payment', $initial_payment );
		$order->update_meta_data( '_vanpos_remaining_payment', $remaining_payment );
		// Explicit false defaults; the child-order factories flip these to 'yes' when
		// the corresponding remaining / security-deposit child is actually created.
		$order->update_meta_data( '_vanpos_order_has_remaining_payment', 'no' );
		$order->update_meta_data( '_vanpos_order_has_security_deposit', 'no' );
		$order->update_meta_data( '_vanpos_booking_reference', $booking_ref );
		$order->update_meta_data( '_is_short_term_booking', $is_short_term ? 'yes' : 'no' );
		if ( $has_custom_price ) {
			$order->update_meta_data( '_vanpos_price_per_day', round( $price_per_day, 2 ) );
			$order->update_meta_data( '_vanpos_price_overridden', 'yes' );
		}

		// Flag to prevent VanPOS_Customer_Account from auto-creating child orders
		$order->update_meta_data( '_vanpos_admin_created', 'yes' );

		// AutomateWoo: mark as created so workflows recognise it
		$order->update_meta_data( '_automatewoo_order_created', '1' );

		if ( $include_dog ) {
			$order->update_meta_data( '_vanpos_include_dog', true );
		}
		if ( $include_cleaning ) {
			$order->update_meta_data( '_vanpos_include_cleaning', true );
		}

		// Email-friendly formatted meta (for AutomateWoo templates)
		$order->update_meta_data( '_vanpos_camper_name', $product->get_name() );
		if ( $pickup_date ) {
			$order->update_meta_data( '_vanpos_pickup_date_formatted', date_i18n( 'd-m-Y', strtotime( $pickup_date ) ) );
		}
		if ( $return_date ) {
			$order->update_meta_data( '_vanpos_return_date_formatted', date_i18n( 'd-m-Y', strtotime( $return_date ) ) );
		}
		$order->update_meta_data( '_vanpos_total_price_formatted', VanPOS_Order_Manager::format_price( $total_price ) );
		$order->update_meta_data( '_vanpos_initial_payment_formatted', VanPOS_Order_Manager::format_price( $initial_payment ) );
		$order->update_meta_data( '_vanpos_remaining_payment_formatted', VanPOS_Order_Manager::format_price( $remaining_payment ) );

		// NOTE: _vanpos_custom_order_title is NOT set here deliberately.
		// VanPOS_Order_Title_Manager hooks into woocommerce_after_order_object_save
		// and assigns both the title AND the sequential order number (e.g. 9013-A).
		// Setting it manually would cause the title manager to skip the order,
		// leaving it without a proper _vanpos_vrc_order_number.

		// Driver details — from form fields (guest) or existing customer user meta
		$driver_order_meta = array(
			'_billing_middle_name'                    => 'middle_name',
			'_driver_date_of_birth'                   => 'date_of_birth',
			'_driver_license_issue_date'              => 'driver_license_issue_date',
			'_driver_license_obtained_date'           => 'driver_license_obtained_date',
			'_second_driver_name'                     => 'second_driver_name',
			'_second_driver_date_of_birth'            => 'second_driver_date_of_birth',
			'_second_driver_license_issue_date'       => 'second_driver_license_issue_date',
			'_second_driver_license_obtained_date'    => 'second_driver_license_obtained_date',
		);

		// Map from dd_key to user meta key (same mapping as importer)
		$driver_user_meta_keys = array(
			'middle_name'                       => 'billing_middle_name',
			'date_of_birth'                     => 'date_of_birth',
			'driver_license_issue_date'         => 'driver_license_issue_date',
			'driver_license_obtained_date'      => 'driver_license_obtained_date',
			'second_driver_name'                => 'second_driver_name',
			'second_driver_date_of_birth'       => 'second_driver_date_of_birth',
			'second_driver_license_issue_date'  => 'second_driver_license_issue_date',
			'second_driver_license_obtained_date' => 'second_driver_license_obtained_date',
		);

		foreach ( $driver_order_meta as $order_meta_key => $dd_key ) {
			$val = '';

			// First check form-submitted driver details
			if ( ! empty( $driver_details[ $dd_key ] ) ) {
				$val = $driver_details[ $dd_key ];
			}
			// Fall back to user meta for existing customers (when no form value provided)
			elseif ( $customer_id && isset( $driver_user_meta_keys[ $dd_key ] ) ) {
				$val = get_user_meta( $customer_id, $driver_user_meta_keys[ $dd_key ], true );
			}

			if ( ! empty( $val ) ) {
				$order->update_meta_data( $order_meta_key, $val );
			}
		}

		// Add fees for dog / cleaning (tax-exempt, same as importer)
		if ( $include_dog ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( __( 'Dog surcharge', 'vanjorn-rental-pos' ) );
			$fee->set_amount( $dog_price );
			$fee->set_total( $dog_price );
			$fee->set_tax_status( 'none' );
			$order->add_item( $fee );
		}
		if ( $include_cleaning ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( __( 'Cleaning service', 'vanjorn-rental-pos' ) );
			$fee->set_amount( $cleaning_price );
			$fee->set_total( $cleaning_price );
			$fee->set_tax_status( 'none' );
			$order->add_item( $fee );
		}

		// Set the order total explicitly (initial payment + fees)
		$order->set_total( $initial_payment + $fee_total );
		$order->save();

		// Set final status after initial save.
		// For "paid" statuses, use payment_complete() so AutomateWoo's
		// "Order Paid" trigger fires (it listens to woocommerce_payment_complete).
		$paid_statuses = array( 'processing', 'completed' );
		if ( in_array( $order_status, $paid_statuses, true ) ) {
			$order->payment_complete();
			// payment_complete() sets status to 'processing' by default;
			// if 'completed' was requested, set it explicitly after.
			if ( 'completed' === $order_status ) {
				$order->set_status( 'completed' );
				$order->save();
			}
		} elseif ( 'pending' !== $order_status ) {
			$order->set_status( $order_status );
			$order->save();
		}

		// Admin note
		if ( $admin_note ) {
			$order->add_order_note( $admin_note, false, true );
		}
		if ( $has_custom_price ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: nightly rate, 2: nights, 3: total */
					__( 'Custom rate set: %1$s/night × %2$d nights = %3$s (incl. BTW).', 'vanjorn-rental-pos' ),
					wp_strip_all_tags( wc_price( $price_per_day ) ),
					$nights,
					wp_strip_all_tags( wc_price( $total_price ) )
				),
				false,
				true
			);
		}
		$order->add_order_note(
			__( 'Order created manually from VAN-Jorn Rental POS admin.', 'vanjorn-rental-pos' ),
			false,
			true
		);

		$order_id = $order->get_id();

		// Create Kestrel reservation rows
		$this->create_kestrel_reservation( $order_id, $product_id, $pickup_date, $return_date );

		// Optional child orders
		$child_orders = array();

		if ( $create_remaining && $remaining_payment > 0 ) {
			$child_id = VanPOS_Order_Manager::create_payment_order( $order_id, 'remaining', $remaining_payment );
			if ( ! is_wp_error( $child_id ) ) {
				$child_orders[] = array(
					'type' => 'remaining',
					'id'   => $child_id,
				);
			}
		}

		if ( $create_deposit ) {
			$dep_id = VanPOS_Order_Manager::create_security_deposit_order( $order_id );
			if ( ! is_wp_error( $dep_id ) ) {
				$child_orders[] = array(
					'type' => 'security_deposit',
					'id'   => $dep_id,
				);
			}
		}

		// Build success message
		$success_msg = sprintf(
			/* translators: %s is the order number */
			__( 'Rental order #%s created successfully.', 'vanjorn-rental-pos' ),
			$order->get_order_number()
		);
		if ( $account_created ) {
			$success_msg .= ' ' . __( 'Customer account created.', 'vanjorn-rental-pos' );
			if ( $send_password_reset ) {
				$success_msg .= ' ' . __( 'Password reset email sent.', 'vanjorn-rental-pos' );
			}
		}

		wp_send_json_success( array(
			'message'         => $success_msg,
			'order_id'        => $order_id,
			'order_url'       => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id ),
			'booking_ref'     => $booking_ref,
			'child_orders'    => $child_orders,
			'account_created' => $account_created,
		) );
	}

	/**
	 * Check whether a product (van) is available for the given date range.
	 *
	 * Uses VanPOS_Availability_Manager if available, falls back to
	 * VanPOS_Functions::check_rental_availability().
	 *
	 * @param int    $product_id  WC product ID.
	 * @param string $pickup_date Pickup date (Y-m-d).
	 * @param string $return_date Return date (Y-m-d).
	 * @return array { available: bool, message: string }
	 */
	private function check_product_availability( $product_id, $pickup_date, $return_date ) {
		// Primary: Availability Manager (richer response)
		if ( class_exists( 'VanPOS_Availability_Manager' ) ) {
			$result = VanPOS_Availability_Manager::check_availability(
				$product_id,
				$pickup_date,
				'afternoon',
				$return_date,
				'morning'
			);
			return array(
				'available' => (bool) $result['available'],
				'message'   => $result['available']
					? __( 'Van is available for the selected dates.', 'vanjorn-rental-pos' )
					: ( ! empty( $result['message'] ) ? $result['message'] : __( 'Van is not available for the selected dates.', 'vanjorn-rental-pos' ) ),
			);
		}

		// Fallback: VanPOS_Functions
		if ( method_exists( 'VanPOS_Functions', 'check_rental_availability' ) ) {
			$status = VanPOS_Functions::check_rental_availability( $product_id, $pickup_date, $return_date, 1 );
			return array(
				'available' => ( 'available' === $status ),
				'message'   => ( 'available' === $status )
					? __( 'Van is available for the selected dates.', 'vanjorn-rental-pos' )
					: __( 'Van is not available for the selected dates.', 'vanjorn-rental-pos' ),
			);
		}

		// No availability checker — allow by default
		return array(
			'available' => true,
			'message'   => __( 'Availability check not available — proceeding.', 'vanjorn-rental-pos' ),
		);
	}

	/**
	 * Insert day-by-day rows into the Kestrel rental calendar
	 * table so the van shows as unavailable for the booked date range.
	 *
	 * Mirrors VRC_Importer::create_kestrel_reservation().
	 *
	 * @param int    $order_id   WC order ID.
	 * @param int    $product_id WC product ID.
	 * @param string $from       Pickup date (Y-m-d).
	 * @param string $to         Return date (Y-m-d).
	 */
	private function create_kestrel_reservation( $order_id, $product_id, $from, $to ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wcrp_rental_products_rentals';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return; // Kestrel plugin not active — nothing to do.
		}

		if ( ! $product_id || ! $from || ! $to ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Find the line item ID for this product in the order
		$item_id = 0;
		foreach ( $order->get_items() as $oi ) {
			if ( (int) $oi->get_product_id() === (int) $product_id ) {
				$item_id = $oi->get_id();
				break;
			}
		}
		if ( ! $item_id ) {
			return;
		}

		// Insert one row per day (exclusive of return date, same as importer)
		$d   = new DateTime( $from );
		$end = new DateTime( $to );
		while ( $d < $end ) {
			$wpdb->insert( $table, array(
				'reserved_date' => $d->format( 'Y-m-d' ),
				'order_id'      => $order_id,
				'order_item_id' => $item_id,
				'product_id'    => $product_id,
				'quantity'      => 1,
			) );
			$d->modify( '+1 day' );
		}
	}

}
