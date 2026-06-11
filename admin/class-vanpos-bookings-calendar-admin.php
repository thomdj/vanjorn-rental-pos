<?php
/**
 * Admin bookings calendar (FullCalendar) under VAN-Jorn Rental POS.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers submenu, admin-ajax for month/range fetches, assets, and WooCommerce orders list shortcut.
 */
class VanPOS_Bookings_Calendar_Admin {

	const PAGE_SLUG = 'vanjorn-rental-pos-bookings-calendar';

	const AJAX_ACTION = 'vanpos_bookings_calendar_events';

	const AJAX_ACTION_SUGGEST = 'vanpos_bookings_calendar_suggest';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_calendar_events' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION_SUGGEST, array( $this, 'ajax_calendar_suggest' ) );
		add_action( 'woocommerce_order_list_table_extra_tablenav', array( $this, 'render_orders_list_calendar_link' ), 5, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_orders_list_calendar_link_legacy' ), 5 );
		add_action( 'admin_footer', array( $this, 'move_orders_list_calendar_link_to_top' ) );
	}

	/**
	 * Submenu under VAN-Jorn Rental POS (same capability as other POS pages).
	 */
	public function register_submenu() {
		add_submenu_page(
			'vanjorn-rental-pos',
			__( 'Bookings calendar', 'vanjorn-rental-pos' ),
			__( 'Bookings calendar', 'vanjorn-rental-pos' ),
			'edit_shop_orders',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Validate start/end (FullCalendar range) and return inclusive Y-m-d bounds for querying.
	 *
	 * @param string $start_raw Start (ISO or Y-m-d).
	 * @param string $end_raw   End from FullCalendar (exclusive); last query day is the day before.
	 * @return array{start: string, end: string}|null
	 */
	private function normalize_calendar_range( $start_raw, $end_raw ) {
		$start_raw = is_string( $start_raw ) ? trim( $start_raw ) : '';
		$end_raw   = is_string( $end_raw ) ? trim( $end_raw ) : '';
		if ( '' === $start_raw || '' === $end_raw ) {
			return null;
		}

		$start_ts = strtotime( $start_raw );
		$end_ts   = strtotime( $end_raw );
		if ( ! $start_ts || ! $end_ts ) {
			return null;
		}

		$range_start = date( 'Y-m-d', $start_ts );
		// FC end is exclusive; query uses inclusive end = day before exclusive end.
		$range_end = date( 'Y-m-d', $end_ts - DAY_IN_SECONDS );

		if ( $range_start > $range_end ) {
			return null;
		}

		$days = (int) floor( ( strtotime( $range_end ) - strtotime( $range_start ) ) / DAY_IN_SECONDS ) + 1;
		if ( $days > 400 ) {
			return null;
		}

		return array(
			'start' => $range_start,
			'end'   => $range_end,
		);
	}

	/**
	 * Default bootstrap window around the current month (site timezone).
	 *
	 * @return array{0: string, 1: string} Y-m-d start and inclusive end.
	 */
	private static function get_bootstrap_range() {
		try {
			$tz = wp_timezone();
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}

		try {
			$base = new DateTimeImmutable( 'now', $tz );
		} catch ( Exception $e ) {
			$base = new DateTimeImmutable( 'now' );
		}

		$range_start = $base->modify( 'first day of this month' )->modify( '-14 days' )->format( 'Y-m-d' );
		$range_end   = $base->modify( 'last day of this month' )->modify( '+21 days' )->format( 'Y-m-d' );

		return array( $range_start, $range_end );
	}

	/**
	 * FullCalendar locale code for the active WPML / admin language (nl, de, en-gb).
	 *
	 * @return string
	 */
	private static function get_fullcalendar_locale() {
		$lang = '';
		if ( class_exists( 'VanPOS_Equipment_Labels' ) ) {
			$lang = VanPOS_Equipment_Labels::get_language_code();
		}
		$wp_locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		if ( 0 === strpos( $wp_locale, 'nl' ) ) {
			$lang = 'nl';
		} elseif ( 0 === strpos( $wp_locale, 'de' ) ) {
			$lang = 'de';
		} elseif ( ! in_array( $lang, array( 'nl', 'de', 'en' ), true ) ) {
			$lang = 'en';
		}

		$map = array(
			'nl' => 'nl',
			'de' => 'de',
			'en' => 'en-gb',
		);

		return isset( $map[ $lang ] ) ? $map[ $lang ] : 'en-gb';
	}

	/**
	 * AJAX: return FullCalendar event JSON for a date range (admin only).
	 */
	public function ajax_calendar_events() {
		if ( ! check_ajax_referer( 'vanpos_bookings_calendar', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'vanjorn-rental-pos' ) ), 403 );
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ), 403 );
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce is required.', 'vanjorn-rental-pos' ) ), 500 );
		}

		$start  = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
		$end    = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '';
		$pid    = isset( $_POST['product_id'] ) ? (int) wp_unslash( $_POST['product_id'] ) : 0;
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		if ( strlen( $search ) > 120 ) {
			$search = substr( $search, 0, 120 );
		}

		$range = $this->normalize_calendar_range( $start, $end );
		if ( null === $range ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date range.', 'vanjorn-rental-pos' ) ), 400 );
		}

		$events = VanPOS_Bookings_Calendar_Query::get_events( $range['start'], $range['end'], $pid, $search );
		wp_send_json_success( $events );
	}

	/**
	 * AJAX: autocomplete suggestions (wide date window, admin only).
	 */
	public function ajax_calendar_suggest() {
		if ( ! check_ajax_referer( 'vanpos_bookings_calendar', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'vanjorn-rental-pos' ) ), 403 );
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ), 403 );
		}

		if ( ! class_exists( 'VanPOS_Bookings_Calendar_Query' ) || ! function_exists( 'wc_get_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce is required.', 'vanjorn-rental-pos' ) ), 500 );
		}

		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( strlen( $q ) > 120 ) {
			$q = substr( $q, 0, 120 );
		}
		$pid = isset( $_POST['product_id'] ) ? (int) wp_unslash( $_POST['product_id'] ) : 0;

		$items = VanPOS_Bookings_Calendar_Query::get_search_suggestions( $q, $pid, 15 );
		wp_send_json_success( $items );
	}

	/**
	 * Enqueue FullCalendar and plugin assets on the calendar screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		// Submenu hook uses get_plugin_page_hookname(): parent prefix comes from
		// sanitize_title( top-level menu title ), e.g. "VAN-Jorn Rental POS" -> vanjorn-pos_page_...
		$expected_hook = function_exists( 'get_plugin_page_hookname' )
			? get_plugin_page_hookname( self::PAGE_SLUG, 'vanjorn-rental-pos' )
			: '';
		$on_page       = isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $on_page && ( ! $expected_hook || $expected_hook !== $hook_suffix ) ) {
			return;
		}

		$fc_path = VANPOS_PLUGIN_DIR . 'admin/vendor/fullcalendar/index.global.min.js';
		if ( ! is_readable( $fc_path ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'FullCalendar bundle is missing. Reinstall the plugin or restore admin/vendor/fullcalendar/index.global.min.js.', 'vanjorn-rental-pos' ) . '</p></div>';
				}
			);
			return;
		}

		list( $bootstrap_start, $bootstrap_end ) = self::get_bootstrap_range();
		$initial_events = array();
		if ( class_exists( 'VanPOS_Bookings_Calendar_Query' ) && function_exists( 'wc_get_orders' ) ) {
			$initial_events = VanPOS_Bookings_Calendar_Query::get_events( $bootstrap_start, $bootstrap_end, 0 );
		}

		wp_enqueue_script(
			'vanpos-fullcalendar',
			VANPOS_PLUGIN_URL . 'admin/vendor/fullcalendar/index.global.min.js',
			array(),
			'6.1.11',
			true
		);

		$fc_locale     = self::get_fullcalendar_locale();
		$calendar_deps = array( 'vanpos-fullcalendar' );
		$locale_path   = VANPOS_PLUGIN_DIR . 'admin/vendor/fullcalendar/locales/' . $fc_locale . '.global.min.js';
		if ( is_readable( $locale_path ) ) {
			wp_enqueue_script(
				'vanpos-fullcalendar-locale',
				VANPOS_PLUGIN_URL . 'admin/vendor/fullcalendar/locales/' . $fc_locale . '.global.min.js',
				array( 'vanpos-fullcalendar' ),
				'6.1.11',
				true
			);
			$calendar_deps[] = 'vanpos-fullcalendar-locale';
		}

		wp_enqueue_script(
			'vanpos-bookings-calendar',
			VANPOS_PLUGIN_URL . 'admin/js/vanpos-bookings-calendar.js',
			$calendar_deps,
			VANPOS_VERSION,
			true
		);

		wp_enqueue_style(
			'vanpos-bookings-calendar',
			VANPOS_PLUGIN_URL . 'admin/css/vanpos-bookings-calendar.css',
			array(),
			VANPOS_VERSION
		);

		$vans = array();
		if ( class_exists( 'VanPOS_Functions' ) ) {
			foreach ( VanPOS_Functions::get_rental_products() as $p ) {
				if ( empty( $p['id'] ) ) {
					continue;
				}
				$vans[] = array(
					'id'   => (int) $p['id'],
					'name' => isset( $p['name'] ) ? (string) $p['name'] : '',
				);
			}
		}

		wp_localize_script(
			'vanpos-bookings-calendar',
			'vanposBookingsCalendar',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'ajaxNonce'        => wp_create_nonce( 'vanpos_bookings_calendar' ),
				'ajaxAction'       => self::AJAX_ACTION,
				'ajaxSuggestAction' => self::AJAX_ACTION_SUGGEST,
				'bootstrapStart'   => $bootstrap_start,
				'bootstrapEnd'     => $bootstrap_end,
				'initialEvents'    => $initial_events,
				'vans'             => $vans,
				'fcLocale'         => $fc_locale,
				'dateLocale'       => str_replace( '_', '-', function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale() ),
				'i18n'             => array(
					'pending'          => __( 'Pending', 'vanjorn-rental-pos' ),
					'ongoing'          => __( 'Ongoing', 'vanjorn-rental-pos' ),
					'completed'        => __( 'Completed', 'vanjorn-rental-pos' ),
					'allVans'          => __( 'All vans', 'vanjorn-rental-pos' ),
					'today'            => __( 'Today', 'vanjorn-rental-pos' ),
					'pickups'          => __( 'Pickups', 'vanjorn-rental-pos' ),
					'returns'          => __( 'Returns', 'vanjorn-rental-pos' ),
					'none'             => __( 'None', 'vanjorn-rental-pos' ),
					'call'             => __( 'Call', 'vanjorn-rental-pos' ),
					'edit'             => __( 'Edit order', 'vanjorn-rental-pos' ),
					'searchLabel'      => __( 'Search', 'vanjorn-rental-pos' ),
					'searchPlaceholder' => __( 'Order #, name, phone…', 'vanjorn-rental-pos' ),
					'loadingCalendar'  => __( 'Loading calendar…', 'vanjorn-rental-pos' ),
					'loadingSuggestions' => __( 'Searching…', 'vanjorn-rental-pos' ),
					'close'            => __( 'Close', 'vanjorn-rental-pos' ),
					'days'             => __( 'Rental days', 'vanjorn-rental-pos' ),
					'pickup'           => __( 'Pickup', 'vanjorn-rental-pos' ),
					'return'           => __( 'Return', 'vanjorn-rental-pos' ),
					'order'            => __( 'Order', 'vanjorn-rental-pos' ),
					'customer'         => __( 'Customer', 'vanjorn-rental-pos' ),
					'orderType'        => __( 'Order type', 'vanjorn-rental-pos' ),
					'paymentLabel'     => __( 'Payment', 'vanjorn-rental-pos' ),
					'phoneLabel'       => __( 'Phone', 'vanjorn-rental-pos' ),
					'wcOrderStatus'    => __( 'WooCommerce status', 'vanjorn-rental-pos' ),
					'rentalTimeline'   => __( 'Rental timeline', 'vanjorn-rental-pos' ),
					'bookingRef'       => __( 'Booking reference', 'vanjorn-rental-pos' ),
					'productTitle'     => __( 'Product title', 'vanjorn-rental-pos' ),
					'rentalPriceLabel' => __( 'Rental price', 'vanjorn-rental-pos' ),
					'totalLabel'       => __( 'Total', 'vanjorn-rental-pos' ),
					'mainOrder'        => __( 'Main order', 'vanjorn-rental-pos' ),
					'rentalContractTotal' => __( 'Rental contract total', 'vanjorn-rental-pos' ),
					'initialOnMainOrder' => __( 'Initial charge on main order', 'vanjorn-rental-pos' ),
					'scheduledRemainingLabel' => __( 'Remaining on booking (scheduled)', 'vanjorn-rental-pos' ),
					'cleaningService'   => __( 'Cleaning service', 'vanjorn-rental-pos' ),
					'bringYourDog'      => __( 'Bring your dog', 'vanjorn-rental-pos' ),
					'remainingPaymentOrder' => __( 'Remaining payment order', 'vanjorn-rental-pos' ),
					'securityDepositOrder' => __( 'Security deposit', 'vanjorn-rental-pos' ),
					'mainOrderPayment' => __( 'Main order (WooCommerce)', 'vanjorn-rental-pos' ),
					'shortTermBooking' => __( 'Payment rules', 'vanjorn-rental-pos' ),
					'sectionStatus'    => __( 'Status', 'vanjorn-rental-pos' ),
					'sectionBooking'   => __( 'Booking', 'vanjorn-rental-pos' ),
					'sectionFinancials' => __( 'Payments & balances', 'vanjorn-rental-pos' ),
					'sectionContact'   => __( 'Contact', 'vanjorn-rental-pos' ),
					'finColItem'       => __( 'Item', 'vanjorn-rental-pos' ),
					'finColDetail'     => __( 'Detail', 'vanjorn-rental-pos' ),
					'thisViewLabel'    => __( 'This calendar view', 'vanjorn-rental-pos' ),
					'visiblePeriod'    => __( 'Visible period', 'vanjorn-rental-pos' ),
					'quickSummary'     => __( 'Quick summary', 'vanjorn-rental-pos' ),
					'todayNotInView'   => __( 'Today\'s date is not in this calendar window — the first row still shows real-world pickups/returns for today.', 'vanjorn-rental-pos' ),
					'clickHint'        => __( 'Click a booking for details and actions.', 'vanjorn-rental-pos' ),
					'suggestNoResults' => __( 'No matching bookings.', 'vanjorn-rental-pos' ),
				),
			)
		);
	}

	/**
	 * Render calendar admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this calendar.', 'vanjorn-rental-pos' ),
				__( 'Bookings calendar', 'vanjorn-rental-pos' ),
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap vanpos-bookings-calendar-wrap">
			<?php
			if ( class_exists( 'VanPOS_Admin_Pos_Nav' ) ) {
				VanPOS_Admin_Pos_Nav::render( VanPOS_Admin_Pos_Nav::TAB_CALENDAR );
			}
			?>
			<h1><?php esc_html_e( 'Bookings calendar', 'vanjorn-rental-pos' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Rental bookings from WooCommerce orders (pickup through return). Payment-only child orders are hidden to avoid duplicates.', 'vanjorn-rental-pos' ); ?>
			</p>

			<div class="vanpos-bc-topbar">
				<div class="vanpos-bc-legend" aria-hidden="true">
					<span class="vanpos-bc-legend-item vanpos-bc-pending"><?php esc_html_e( 'Pending', 'vanjorn-rental-pos' ); ?></span>
					<span class="vanpos-bc-legend-item vanpos-bc-ongoing"><?php esc_html_e( 'Ongoing', 'vanjorn-rental-pos' ); ?></span>
					<span class="vanpos-bc-legend-item vanpos-bc-completed"><?php esc_html_e( 'Completed', 'vanjorn-rental-pos' ); ?></span>
				</div>
			</div>

			<p class="vanpos-bc-click-hint description"><?php esc_html_e( 'Click a booking for details and actions.', 'vanjorn-rental-pos' ); ?></p>

			<div class="vanpos-bc-filters">
				<div class="vanpos-bc-filter-group">
					<label for="vanpos-bc-van-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by van', 'vanjorn-rental-pos' ); ?></label>
					<select id="vanpos-bc-van-filter" class="vanpos-bc-van-select"></select>
				</div>
				<div class="vanpos-bc-filter-group vanpos-bc-search-wrap vanpos-bc-ac-wrap">
					<label for="vanpos-bc-search" class="screen-reader-text"><?php esc_html_e( 'Search', 'vanjorn-rental-pos' ); ?></label>
					<div class="vanpos-bc-ac" role="combobox" aria-expanded="false" aria-haspopup="listbox" aria-owns="vanpos-bc-ac-list">
						<input
							type="search"
							id="vanpos-bc-search"
							class="vanpos-bc-search regular-text"
							placeholder="<?php esc_attr_e( 'Order #, name, phone…', 'vanjorn-rental-pos' ); ?>"
							autocomplete="off"
							aria-autocomplete="list"
							aria-controls="vanpos-bc-ac-list"
							aria-expanded="false"
						/>
						<ul id="vanpos-bc-ac-list" class="vanpos-bc-ac-list" role="listbox" hidden></ul>
					</div>
				</div>
			</div>

			<h2 class="vanpos-bc-summary-heading"><?php esc_html_e( 'Quick summary', 'vanjorn-rental-pos' ); ?></h2>
			<div id="vanpos-bc-today-summary" class="vanpos-bc-today-summary" aria-live="polite"></div>
			<div class="vanpos-bc-calendar-outer">
				<div id="vanpos-bc-cal-loader" class="vanpos-bc-cal-loader" aria-hidden="true">
					<div class="vanpos-bc-cal-loader-inner">
						<span class="vanpos-bc-cal-spinner" aria-hidden="true"></span>
						<span class="vanpos-bc-cal-loader-text"><?php esc_html_e( 'Loading calendar…', 'vanjorn-rental-pos' ); ?></span>
					</div>
				</div>
				<div id="vanpos-bc-calendar"></div>
			</div>

			<div id="vanpos-bc-panel" class="vanpos-bc-panel" hidden role="dialog" aria-modal="true" aria-labelledby="vanpos-bc-panel-title">
				<div class="vanpos-bc-panel-inner">
					<button type="button" class="button-link vanpos-bc-panel-close" aria-label="<?php esc_attr_e( 'Close', 'vanjorn-rental-pos' ); ?>">&times;</button>
					<h2 id="vanpos-bc-panel-title" class="vanpos-bc-panel-title"></h2>
					<div class="vanpos-bc-panel-body"></div>
					<div class="vanpos-bc-panel-actions"></div>
				</div>
			</div>
			<div id="vanpos-bc-panel-backdrop" class="vanpos-bc-panel-backdrop" hidden aria-hidden="true"></div>
		</div>
		<?php
	}

	/**
	 * Calendar link on HPOS orders list.
	 *
	 * @param string $order_type Order type.
	 * @param string $which      top|bottom.
	 */
	public function render_orders_list_calendar_link( $order_type, $which ) {
		if ( 'shop_order' !== $order_type || 'top' !== $which ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		echo '<a href="' . esc_url( $url ) . '" class="page-title-action vanpos-bc-orders-link">' . esc_html__( 'Bookings calendar', 'vanjorn-rental-pos' ) . '</a> ';
	}

	/**
	 * Calendar link on legacy orders list.
	 *
	 * @param string $post_type Post type.
	 */
	public function render_orders_list_calendar_link_legacy( $post_type ) {
		if ( 'shop_order' !== $post_type ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		echo '<a href="' . esc_url( $url ) . '" class="page-title-action vanpos-bc-orders-link">' . esc_html__( 'Bookings calendar', 'vanjorn-rental-pos' ) . '</a> ';
	}

	/**
	 * Move orders-list calendar shortcut to the top actions area; hide if no valid top target exists.
	 *
	 * @return void
	 */
	public function move_orders_list_calendar_link_to_top() {
		$is_hpos_orders = isset( $_GET['page'] ) && 'wc-orders' === sanitize_text_field( wp_unslash( $_GET['page'] ) );
		$is_legacy_orders = 'edit.php' === $GLOBALS['pagenow'] && isset( $_GET['post_type'] ) && 'shop_order' === sanitize_text_field( wp_unslash( $_GET['post_type'] ) );
		if ( ! $is_hpos_orders && ! $is_legacy_orders ) {
			return;
		}
		?>
		<script>
		(function () {
			var link = document.querySelector('.vanpos-bc-orders-link');
			if (!link) {
				return;
			}

			var topTarget = document.querySelector('.wrap .page-title-action');
			if (topTarget && topTarget.parentNode) {
				topTarget.insertAdjacentElement('afterend', link);
				link.style.marginLeft = '8px';
				link.style.verticalAlign = 'baseline';
				return;
			}

			var heading = document.querySelector('.wrap h1.wp-heading-inline');
			if (heading && heading.parentNode) {
				heading.insertAdjacentElement('afterend', link);
				link.style.marginLeft = '8px';
				link.style.verticalAlign = 'baseline';
				return;
			}

			// Fallback requested by user: remove when top placement is not possible.
			link.remove();
		})();
		</script>
		<?php
	}
}
