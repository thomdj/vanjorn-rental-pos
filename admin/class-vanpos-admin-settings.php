<?php
/**
 * VanPOS settings screen controller.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Admin_Settings {
	use VanPOS_Admin_Settings_Registration;
	use VanPOS_Admin_Settings_Fields;

	/**
	 * Register settings page + Settings API hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 10 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add top-level VanPOS menu.
	 *
	 * Uses manage_woocommerce (rather than manage_options) to be consistent with
	 * every other VanPOS admin page, all of which check manage_woocommerce or
	 * edit_shop_orders. Shop managers therefore see the settings menu without
	 * needing site-admin access. If tighter restriction is ever needed, gate on
	 * manage_options here and in render_settings_page() together.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'VAN-Jorn Rental POS', 'vanjorn-rental-pos' ),
			__( 'VAN-Jorn Rental POS', 'vanjorn-rental-pos' ),
			'manage_woocommerce',
			'vanjorn-rental-pos',
			array( $this, 'render_settings_page' ),
			'dashicons-calendar-alt',
			30
		);
	}

	/**
	 * Render settings page shell.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'vanpos_messages', 'vanpos_message', __( 'Settings saved successfully.', 'vanjorn-rental-pos' ), 'success' );
		}
		settings_errors( 'vanpos_messages' );
		?>
		<div class="wrap vanpos-admin-wrap">
			<h1 class="vanpos-page-title"><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<div class="vanpos-page-intro">
				<p><?php esc_html_e( 'Control how your rental calendar behaves. These settings affect pickup and return rules for all vans on the booking calendar.', 'vanjorn-rental-pos' ); ?></p>
				<p class="vanpos-page-intro__meta">
					<?php printf( esc_html__( 'To show the booking calendar on any page, add the %s shortcode to your content.', 'vanjorn-rental-pos' ), '<code>[vanjorn_rental_pos]</code>' ); ?>
				</p>
			</div>
			<div class="vanpos-settings-layout">
				<div class="vanpos-settings-main">
					<form action="options.php" method="post" class="vanpos-settings-form">
						<?php settings_fields( 'vanpos_settings_group' ); ?>
						<?php do_settings_sections( 'vanjorn-rental-pos' ); ?>
						<?php submit_button( __( 'Save Settings', 'vanjorn-rental-pos' ) ); ?>
					</form>
				</div>
				<aside class="vanpos-settings-aside">
					<div class="vanpos-card vanpos-card--help">
						<h2><?php esc_html_e( 'Quick Start', 'vanjorn-rental-pos' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Set your allowed pickup/return days.', 'vanjorn-rental-pos' ); ?></li>
							<li><?php esc_html_e( 'Choose the minimum and maximum rental length.', 'vanjorn-rental-pos' ); ?></li>
							<li><?php esc_html_e( 'Optionally enable time slots (morning/afternoon).', 'vanjorn-rental-pos' ); ?></li>
							<li><?php esc_html_e( 'Create or edit your vans as WooCommerce products.', 'vanjorn-rental-pos' ); ?></li>
							<li><?php esc_html_e( 'Add the booking shortcode to a page and publish it.', 'vanjorn-rental-pos' ); ?></li>
						</ol>
					</div>
					<div class="vanpos-card">
						<h2><?php esc_html_e( 'Need to test quickly?', 'vanjorn-rental-pos' ); ?></h2>
						<p><?php esc_html_e( 'Use Thursday and Friday for pickup/return and a 6-22 day window. This matches the calendar UX and keeps bookings realistic.', 'vanjorn-rental-pos' ); ?></p>
					</div>
					<?php if ( class_exists( 'VanPOS_Logger' ) && VanPOS_Logger::is_enabled() ) : ?>
					<div class="vanpos-card vanpos-card--info">
						<h2><?php esc_html_e( 'View Logs', 'vanjorn-rental-pos' ); ?></h2>
						<p><?php esc_html_e( 'Debug logging is enabled. View logs in WooCommerce status page.', 'vanjorn-rental-pos' ); ?></p>
						<p><a href="<?php echo esc_url( VanPOS_Logger::get_logs_url() ); ?>" class="button button-primary" target="_blank"><?php esc_html_e( 'View WooCommerce Logs', 'vanjorn-rental-pos' ); ?></a></p>
					</div>
					<?php endif; ?>
				</aside>
			</div>
		</div>
		<?php
	}
}
