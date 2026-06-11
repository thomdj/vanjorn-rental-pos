<?php
/**
 * Shared top navigation between VAN-Jorn Rental POS admin screens.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab bar: Upcoming rentals | Returns to process | Bookings calendar.
 */
class VanPOS_Admin_Pos_Nav {

	const TAB_UPCOMING = 'vanjorn-rental-pos-dashboard';

	const TAB_RETURNS = 'vanjorn-rental-pos-returns-queue';

	const TAB_CALENDAR = 'vanjorn-rental-pos-bookings-calendar';

	/**
	 * Register assets hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	/**
	 * Tab definitions (label, URL, capability).
	 *
	 * @return array<string, array{label:string, url:string, cap:string}>
	 */
	public static function get_tabs() {
		$returns_label = __( 'Returns to process', 'vanjorn-rental-pos' );
		if ( class_exists( 'VanPOS_Admin_Returns_Queue_Query' ) && current_user_can( 'edit_shop_orders' ) ) {
			$pending = VanPOS_Admin_Returns_Queue_Query::count_pending();
			if ( $pending > 0 ) {
				$returns_label = sprintf(
					/* translators: %d: number of rentals awaiting mark-as-returned */
					__( 'Returns to process (%d)', 'vanjorn-rental-pos' ),
					(int) $pending
				);
			}
		}

		return array(
			self::TAB_UPCOMING => array(
				'label' => __( 'Upcoming rentals', 'vanjorn-rental-pos' ),
				'url'   => admin_url( 'admin.php?page=' . self::TAB_UPCOMING ),
				'cap'   => 'manage_options',
			),
			self::TAB_RETURNS => array(
				'label' => $returns_label,
				'url'   => admin_url( 'admin.php?page=' . self::TAB_RETURNS ),
				'cap'   => 'edit_shop_orders',
			),
			self::TAB_CALENDAR => array(
				'label' => __( 'Bookings calendar', 'vanjorn-rental-pos' ),
				'url'   => admin_url( 'admin.php?page=' . self::TAB_CALENDAR ),
				'cap'   => 'edit_shop_orders',
			),
		);
	}

	/**
	 * Whether current request is one of the POS tab pages.
	 *
	 * @return bool
	 */
	public static function is_pos_tab_page() {
		if ( ! isset( $_GET['page'] ) ) {
			return false;
		}
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
		return in_array( $page, array( self::TAB_UPCOMING, self::TAB_RETURNS, self::TAB_CALENDAR ), true );
	}

	/**
	 * Enqueue shared nav styles on POS tab pages.
	 *
	 * @return void
	 */
	public static function enqueue_styles() {
		if ( ! self::is_pos_tab_page() ) {
			return;
		}

		wp_enqueue_style(
			'vanpos-admin-pos-nav',
			VANPOS_PLUGIN_URL . 'admin/css/pos-nav.css',
			array(),
			VANPOS_VERSION
		);
	}

	/**
	 * Render horizontal tab navigation.
	 *
	 * CMIT CODE - UPDATED - 15 MAY 2026
	 *
	 * @param string $active_slug Current page slug ({@see TAB_UPCOMING}, etc.).
	 * @return void
	 */
	public static function render( $active_slug ) {
		$tabs = self::get_tabs();
		if ( empty( $tabs ) ) {
			return;
		}

		$visible = 0;
		foreach ( $tabs as $tab ) {
			if ( current_user_can( $tab['cap'] ) ) {
				++$visible;
			}
		}
		if ( $visible < 2 ) {
			return;
		}

		echo '<nav class="vanpos-pos-nav" aria-label="' . esc_attr__( 'VAN-Jorn Rental POS sections', 'vanjorn-rental-pos' ) . '">';
		foreach ( $tabs as $slug => $tab ) {
			if ( ! current_user_can( $tab['cap'] ) ) {
				continue;
			}
			$is_active = ( (string) $active_slug === (string) $slug );
			$class     = 'vanpos-pos-nav__tab' . ( $is_active ? ' is-active' : '' );
			printf(
				'<a class="%1$s" href="%2$s"%3$s>%4$s</a>',
				esc_attr( $class ),
				esc_url( $tab['url'] ),
				$is_active ? ' aria-current="page"' : '',
				esc_html( $tab['label'] )
			);
		}
		echo '</nav>';
	}
}
