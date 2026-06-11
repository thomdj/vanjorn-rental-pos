<?php
/**
 * Plugin Name: VAN-Jorn Rental POS
 * Plugin URI: https://www.cmitexperts.com
 * Description: A POS system for rental VAN booking where customers can check date availability, choose dates, pick the van, and add to cart for payment.
 * Version: 1.9.3
 * Author: CMITEXPERTS TEAM
 * Author URI: https://www.cmitexperts.com
 * Text Domain: vanjorn-rental-pos
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires plugins: woocommerce
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package VJ_Rental_POS
 * @author CMITEXPERTS TEAM
 * @copyright Copyright (c) 2026 CMITEXPERTS TEAM
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
// CMITX UPDATE 2026-04-21: Feedback 8 code-structure release tag.
define( 'VANPOS_VERSION', '1.9.3' );
define( 'VANPOS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VANPOS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VANPOS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 *
 * @class VJ_Rental_POS
 */
class VJ_Rental_POS {

	/**
	 * Plugin instance
	 *
	 * @var VJ_Rental_POS
	 */
	private static $instance = null;

	/**
	 * Get plugin instance
	 *
	 * @return VJ_Rental_POS
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
		$this->load_dependencies();
		$this->init_components();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Activation and deactivation hooks
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Load plugin textdomain
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Check for WooCommerce
		add_action( 'admin_init', array( $this, 'check_woocommerce' ) );
	}

	/**
	 * Initialize plugin components
	 */
	private function init_components() {
		// Remaining Slots shortcode (always needed for AJAX handlers).
		if ( class_exists( 'VanPOS_Remaining_Slots' ) ) {
			new VanPOS_Remaining_Slots();
		}

		// Initialize frontend (always needed for AJAX)
		if ( class_exists( 'VanPOS_Frontend' ) ) {
			new VanPOS_Frontend();
		}

		// Initialize admin
		if ( is_admin() && class_exists( 'VanPOS_Admin' ) ) {
			// CMITX UPDATE 2026-04-21:
			// Bootstrap order is important: parent settings menu first, then admin handlers.
			if ( class_exists( 'VanPOS_Admin_Settings' ) ) {
				new VanPOS_Admin_Settings();
			}
			if ( class_exists( 'VanPOS_Admin_Dashboard_Page' ) ) {
				new VanPOS_Admin_Dashboard_Page();
			}
			if ( class_exists( 'VanPOS_Admin_Returns_Queue_Page' ) ) {
				new VanPOS_Admin_Returns_Queue_Page();
			}
			if ( class_exists( 'VanPOS_Admin_Pos_Nav' ) ) {
				VanPOS_Admin_Pos_Nav::init();
			}
			if ( class_exists( 'VanPOS_Admin_Order_List' ) ) {
				new VanPOS_Admin_Order_List();
			}
			if ( class_exists( 'VanPOS_Admin_Assets' ) ) {
				new VanPOS_Admin_Assets();
			}
			if ( class_exists( 'VanPOS_Admin_Ajax' ) ) {
				new VanPOS_Admin_Ajax();
			}
			if ( class_exists( 'VanPOS_Admin_Order_Edit' ) ) {
				new VanPOS_Admin_Order_Edit();
			}
			// CMIT CODE - UPDATED - 05 MAY 2026
			if ( class_exists( 'VanPOS_Admin_Order_Delete_Cascade' ) ) {
				new VanPOS_Admin_Order_Delete_Cascade();
			}
			if ( class_exists( 'VanPOS_Admin' ) ) {
				new VanPOS_Admin();
			}
			if ( class_exists( 'VanPOS_Admin_Add_Order' ) ) {
				new VanPOS_Admin_Add_Order();
			}
			if ( class_exists( 'VanPOS_Admin_Modify_Booking' ) ) {
				new VanPOS_Admin_Modify_Booking();
			}
			if ( class_exists( 'VanPOS_Bookings_Calendar_Admin' ) ) {
				new VanPOS_Bookings_Calendar_Admin();
			}
		}

		// Initialize deposit manager
		if ( class_exists( 'VanPOS_Deposit_Manager' ) ) {
			VanPOS_Deposit_Manager::init();
		}

		// Initialize discount manager (admin-gated internally; safe to init always)
		if ( class_exists( 'VanPOS_Discount_Manager' ) ) {
			VanPOS_Discount_Manager::init();
		}

		// Initialize customer account integration
		if ( class_exists( 'VanPOS_Customer_Account' ) ) {
			VanPOS_Customer_Account::init();
		}

		if ( class_exists( 'VanPOS_Rental_Returned' ) ) {
			VanPOS_Rental_Returned::init();
		}

		// Initialize unified order item display (order view, admin, email only - cart/checkout unchanged)
		if ( class_exists( 'VanPOS_Item_Display' ) ) {
			VanPOS_Item_Display::init();
		}

		// Initialize order display enhancement
		if ( class_exists( 'VanPOS_Order_Display' ) ) {
			VanPOS_Order_Display::init();
		}

		// Initialize checkout fields (driver details, registration, account edit).
		if ( class_exists( 'VanPOS_Checkout_Fields' ) ) {
			VanPOS_Checkout_Fields::init();
		}

		// Initialize cart validation (Kestrel timestamp fix, notice cleanup).
		if ( class_exists( 'VanPOS_Cart_Validation' ) ) {
			VanPOS_Cart_Validation::init();
		}

		// Initialize van specifications product tab.
		if ( class_exists( 'VanPOS_Product_Tabs' ) ) {
			VanPOS_Product_Tabs::init();
		}

		// Initialize WCPDF rental meta (PDF invoice integration).
		if ( class_exists( 'VanPOS_WCPDF_Rental_Meta' ) ) {
			VanPOS_WCPDF_Rental_Meta::init();
		}

		// Initialize WooCommerce template loader (checkout, account, order templates).
		if ( class_exists( 'VanPOS_Template_Loader' ) ) {
			VanPOS_Template_Loader::init();
		}

		// Initialize dashboard enhancement
		if ( class_exists( 'VanPOS_Dashboard' ) ) {
			VanPOS_Dashboard::init();
		}

		// Initialize order title manager
		if ( class_exists( 'VanPOS_Order_Title_Manager' ) ) {
			VanPOS_Order_Title_Manager::init();
		}
	}

	/**
	 * Load plugin dependencies
	 */
	private function load_dependencies() {
		// WPML-aware equipment labels (used by VanPOS_Functions).
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-equipment-labels.php';
		if ( class_exists( 'VanPOS_Equipment_Labels' ) ) {
			VanPOS_Equipment_Labels::init();
		}
		// Load core functions
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-functions.php';

		// CMIT CODE - UPDATED - 15 MAY 2026 — Kestrel “mark returned” → instant VanPOS availability.
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-rental-returned.php';

		// Load order manager (needed by admin and other components)
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-order-manager.php';

		// CMIT CODE - UPDATED - 05 MAY 2026 — Kestrel calendar row delete (change manager + rental trash).
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-kestrel-reservation-helper.php';

		// CMIT CODE - UPDATED - 05 MAY 2026 — Primary rental trash + linked payment orders.
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-order-deletion.php';

		// Load availability manager (needed by change manager and add-order)
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-availability-manager.php';

		// Load change manager (needed by admin change dates)
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-change-manager.php';

		// Load bookings calendar query helpers.
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-bookings-calendar-query.php';

		// Load admin functionality
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-dashboard-overview-query.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-dashboard-page.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-returns-queue-query.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-returns-queue-page.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-pos-nav.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/trait-vanpos-admin-settings-registration.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/trait-vanpos-admin-settings-fields.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-settings.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-order-list.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-assets.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-ajax.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-order-edit.php';
		// CMIT CODE - UPDATED - 05 MAY 2026 — Confirm + trash primary rental with optional child orders.
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-order-delete-cascade.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-bookings-calendar-admin.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-add-order.php';
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-admin-modify-booking.php';
		// Optional admin tool — guarded so the file can be removed cleanly.
		if ( file_exists( VANPOS_PLUGIN_DIR . 'admin/class-vanpos-meta-backfill.php' ) ) {
			require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-meta-backfill.php';
		}

		// Load frontend functionality (always load for AJAX handlers)
		require_once VANPOS_PLUGIN_DIR . 'frontend/class-vanpos-frontend.php';

		// Load deposit manager
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-deposit-manager.php';

		// Load discount manager (syncs VanPOS payment-split meta on coupon apply/remove)
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-discount-manager.php';

		// Load customer account integration
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-customer-account.php';

		// Load order display enhancement
		require_once VANPOS_PLUGIN_DIR . 'frontend/class-vanpos-order-display.php';

		// Load unified order item display
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-item-display.php';

		// Load dashboard enhancement
		require_once VANPOS_PLUGIN_DIR . 'frontend/class-vanpos-dashboard.php';

		// Load order title manager
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-order-title.php';

		// Load AutomateWoo date-based rules, daily trigger, and country translation fix.
		// Used instead of AW's "Schedule with a variable" timing, which does not work
		// reliably with meta-based date variables on this site.
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-aw-date-rules.php';

		// Checkout & registration custom fields (driver details, license dates).
		// Migrated from child theme.
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-checkout-fields.php';

		// Cart validation: Kestrel timestamp fix + notice cleanup.
		// Migrated from child theme.
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-cart-validation.php';

		// Van specifications product tab (ACF field group).
		// Migrated from child theme.
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-product-tabs.php';

		// WCPDF rental meta (PDF invoice integration).
		// Migrated from child theme.
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-wcpdf-rental-meta.php';

		// Product page customizations: Kestrel form replacement + VanPOS gallery.
		// Migrated from child theme.
		require_once VANPOS_PLUGIN_DIR . 'frontend/class-vanpos-product-page.php';

		// PDF rendering helpers (rental period banner, driver details, payment terms, legal footer).
		// Migrated from child theme's woocommerce/pdf/Vanjorn/template-functions.php.
		require_once VANPOS_PLUGIN_DIR . 'includes/vanpos-pdf-helpers.php';

		// WooCommerce template loader — redirects WC template lookup to plugin's templates/ dir.
		// Migrated from child theme: form-checkout, dashboard, orders templates.
		require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-template-loader.php';

		// Load AutomateWoo cron debug & manual runner admin tool.
		require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-aw-cron-debug.php';

		// Remaining Slots shortcode.
		require_once VANPOS_PLUGIN_DIR . 'frontend/class-vanpos-remaining-slots.php';
	}

	/**
	 * Plugin activation
	 */
	public function activate() {
		// Set default options
		$default_options = array(
			'vanpos_pickup_days'                       => array( 4, 5 ), // Thursday, Friday
			'vanpos_min_rental_days'                   => 6,
			'vanpos_max_rental_days'                   => 22,
			'vanpos_time_slots_enabled'                => 'yes',
			'vanpos_pickup_time'                       => '15:00',
			'vanpos_return_time'                       => '11:00',
			'vanpos_dog_enabled'                       => 'yes',
			'vanpos_dog_price'                         => 100,
			'vanpos_cleaning_enabled'                  => 'yes',
			'vanpos_cleaning_price'                    => 100,
			'vanpos_deposit_enabled'                   => 'yes',
			'vanpos_deposit_percentage'                => 50,
			'vanpos_due_date_days'                     => 7,  // Remaining payment: 7 days before pickup
			'vanpos_security_deposit_days_before_pickup' => 14, // Security deposit: 14 days before pickup
			'vanpos_security_deposit_product_id'       => '', // Empty by default, admin must select a product
		);

		// add_option() is a no-op when the option already exists (reactivation).
		// We therefore attempt it first; if the option already exists we merge the
		// defaults into the stored array so that any new keys introduced since
		// this install was first activated are backfilled automatically.
		// Existing values are not overwritten — $existing wins via array_merge order.
		if ( ! add_option( 'vanpos_settings', $default_options ) ) {
			$existing = get_option( 'vanpos_settings', array() );
			$merged   = array_merge( $default_options, is_array( $existing ) ? $existing : array() );
			if ( $merged !== $existing ) {
				update_option( 'vanpos_settings', $merged );
			}
		}

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		// Clear AutomateWoo daily pending-check cron (registered by class-vanpos-aw-date-rules.php).
		if ( function_exists( 'vanpos_aw_clear_daily_cron' ) ) {
			vanpos_aw_clear_daily_cron();
		}

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Load plugin textdomain
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'vanjorn-rental-pos',
			false,
			dirname( VANPOS_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Check if WooCommerce is active
	 */
	public function check_woocommerce() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
		}
	}

	/**
	 * WooCommerce missing notice
	 */
	public function woocommerce_missing_notice() {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'VAN-Jorn Rental POS requires WooCommerce to be installed and active.', 'vanjorn-rental-pos' ); ?></p>
		</div>
		<?php
	}
}

/**
 * Initialize the plugin
 */
function vanpos_init() {
	return VJ_Rental_POS::get_instance();
}

// Start the plugin
vanpos_init();
