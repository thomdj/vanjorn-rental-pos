<?php
/**
 * VAN-Jorn Rental POS admin dashboard page (premium AJAX overview).
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dedicated dashboard submenu page.
 */
class VanPOS_Admin_Dashboard_Page {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_submenu' ), 12 );
		add_action( 'admin_menu', array( $this, 'move_submenu_to_top' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_print_styles', array( $this, 'enqueue_assets_fallback' ) );
		add_action( 'wp_ajax_vanpos_dashboard_filter', array( $this, 'ajax_filter' ) );
		add_action( 'wp_ajax_vanpos_dashboard_child_orders', array( $this, 'ajax_child_orders' ) );
	}

	/**
	 * Add "Dashboard" submenu under VAN-Jorn Rental POS.
	 */
	public function add_submenu() {
		add_submenu_page(
			'vanjorn-rental-pos',
			__( 'Dashboard', 'vanjorn-rental-pos' ),
			__( 'Dashboard', 'vanjorn-rental-pos' ),
			'manage_options',
			'vanjorn-rental-pos-dashboard',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue page-specific assets.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! $this->is_dashboard_page( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'vanpos-admin-dashboard-page',
			VANPOS_PLUGIN_URL . 'admin/css/dashboard-page.css',
			array(),
			VANPOS_VERSION
		);

		wp_enqueue_script(
			'vanpos-admin-dashboard-page',
			VANPOS_PLUGIN_URL . 'admin/js/dashboard-page.js',
			array( 'jquery' ),
			VANPOS_VERSION,
			true
		);

		wp_localize_script(
			'vanpos-admin-dashboard-page',
			'vanposDashboard',
			array(
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'vanpos_dashboard_nonce' ),
				'trashBookingNonce'    => wp_create_nonce( 'vanpos_trash_primary_rental_group' ),
				'cancelBookingNonce'   => wp_create_nonce( 'vanpos_cancel_primary_rental_group' ),
				'canTrashBookings'     => current_user_can( 'delete_shop_orders' ),
				'canCancelBookings'    => current_user_can( 'edit_shop_orders' ),
				'i18n'                 => array(
					'loading' => __( 'Loading dashboard data...', 'vanjorn-rental-pos' ),
				),
				// CMIT CODE - UPDATED - 15 MAY 2026 — table colspan includes Actions when user can edit orders.
				'tableColspan'         => self::get_table_colspan(),
				// CMIT CODE - UPDATED - 06 MAY 2026 — booking trash modal (gettext / WPML).
				'bookingDelete'        => array(
					'modalTitle'        => __( 'Move booking to Trash', 'vanjorn-rental-pos' ),
					/* translators: %s: main booking order number (e.g. #5259-A), wrapped in a highlight in JavaScript */
					'modalIntroTpl'     => wp_kses_post( __( 'You selected booking order %s — the <strong>main rental booking</strong>. Move it to Trash and optionally include <strong>linked payment or deposit orders</strong>.', 'vanjorn-rental-pos' ) ),
					'modalKestrelNote'  => wp_kses_post( __( '<strong>Calendar:</strong> Moving the booking to Trash <strong>releases</strong> reserved dates in the Kestrel calendar.', 'vanjorn-rental-pos' ) ),
					'checkboxLabel'     => wp_kses_post( __( 'Also move <strong>all linked payment orders</strong> to <strong>Trash</strong> (recommended to avoid orphaned orders).', 'vanjorn-rental-pos' ) ),
					/* translators: %s: main booking order number (highlighted in JavaScript) */
					'noChildrenBodyTpl' => wp_kses_post( __( 'No linked payment orders were found for booking %s. Only this <strong>main order</strong> will be moved to Trash.', 'vanjorn-rental-pos' ) ),
					'childrenListLead'  => __( 'Linked orders that can be moved to Trash:', 'vanjorn-rental-pos' ),
					'busy'              => __( 'Moving to Trash…', 'vanjorn-rental-pos' ),
					'confirmTrash'      => __( 'Move to Trash', 'vanjorn-rental-pos' ),
					'cancel'            => __( 'Cancel', 'vanjorn-rental-pos' ),
					'errorGeneric'      => __( 'Something went wrong. Please try again.', 'vanjorn-rental-pos' ),
					/* translators: %s: error message from server */
					'errorDetail'       => __( 'Details: %s', 'vanjorn-rental-pos' ),
				),
				'bookingCancel'        => array(
					'modalTitle'        => __( 'Cancel booking', 'vanjorn-rental-pos' ),
					/* translators: %s: main booking order number (e.g. #5259-A), wrapped in a highlight in JavaScript */
					'modalIntroTpl'     => wp_kses_post( __( 'You selected booking order %s — the <strong>main rental booking</strong>. Set it to <strong>Cancelled</strong> and optionally include <strong>linked payment or deposit orders</strong>.', 'vanjorn-rental-pos' ) ),
					'modalKestrelNote'  => wp_kses_post( __( '<strong>Calendar:</strong> Cancelling the booking <strong>releases</strong> reserved dates in the Kestrel calendar.', 'vanjorn-rental-pos' ) ),
					'checkboxLabel'     => wp_kses_post( __( 'Also set <strong>all linked payment orders</strong> to <strong>Cancelled</strong> (recommended to avoid orphaned active orders).', 'vanjorn-rental-pos' ) ),
					/* translators: %s: main booking order number (highlighted in JavaScript) */
					'noChildrenBodyTpl' => wp_kses_post( __( 'No linked payment orders were found for booking %s. Only this <strong>main order</strong> will be set to Cancelled.', 'vanjorn-rental-pos' ) ),
					'childrenListLead'  => __( 'Linked orders that can be set to Cancelled:', 'vanjorn-rental-pos' ),
					'busy'              => __( 'Cancelling…', 'vanjorn-rental-pos' ),
					'confirmAction'     => __( 'Cancel booking', 'vanjorn-rental-pos' ),
					'cancel'            => __( 'Close', 'vanjorn-rental-pos' ),
					'errorGeneric'      => __( 'Something went wrong. Please try again.', 'vanjorn-rental-pos' ),
					/* translators: %s: error message from server */
					'errorDetail'       => __( 'Details: %s', 'vanjorn-rental-pos' ),
				),
			)
		);
	}

	/**
	 * Fallback enqueue for environments where hook name differs.
	 */
	public function enqueue_assets_fallback() {
		if ( ! $this->is_dashboard_page( '' ) ) {
			return;
		}

		if ( ! wp_style_is( 'vanpos-admin-dashboard-page', 'enqueued' ) ) {
			wp_enqueue_style(
				'vanpos-admin-dashboard-page',
				VANPOS_PLUGIN_URL . 'admin/css/dashboard-page.css',
				array(),
				VANPOS_VERSION
			);
		}

		if ( ! wp_script_is( 'vanpos-admin-dashboard-page', 'enqueued' ) ) {
			wp_enqueue_script(
				'vanpos-admin-dashboard-page',
				VANPOS_PLUGIN_URL . 'admin/js/dashboard-page.js',
				array( 'jquery' ),
				VANPOS_VERSION,
				true
			);
		}
	}

	/**
	 * Check whether current request is VanPOS dashboard page.
	 */
	private function is_dashboard_page( $hook ) {
		$hook = (string) $hook;
		if ( 'vanjorn-rental-pos_page_vanjorn-rental-pos-dashboard' === $hook ) {
			return true;
		}
		if ( isset( $_GET['page'] ) ) {
			$page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
			if ( 'vanjorn-rental-pos-dashboard' === $page ) {
				return true;
			}
		}
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, 'vanjorn-rental-pos-dashboard' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Move dashboard submenu to top under VAN-Jorn Rental POS.
	 */
	public function move_submenu_to_top() {
		global $submenu;

		if ( empty( $submenu['vanjorn-rental-pos'] ) || ! is_array( $submenu['vanjorn-rental-pos'] ) ) {
			return;
		}

		$items = $submenu['vanjorn-rental-pos'];
		$dash  = null;
		$rest  = array();

		foreach ( $items as $item ) {
			if ( isset( $item[2] ) && 'vanjorn-rental-pos-dashboard' === $item[2] ) {
				$dash = $item;
				continue;
			}
			$rest[] = $item;
		}

		if ( null === $dash ) {
			return;
		}

		array_unshift( $rest, $dash );
		$submenu['vanjorn-rental-pos'] = $rest;
	}

	/**
	 * AJAX endpoint for filter updates.
	 */
	public function ajax_filter() {
		check_ajax_referer( 'vanpos_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ), 403 );
		}

		$filters = $this->read_filters( $_POST );
		$result  = VanPOS_Admin_Dashboard_Overview_Query::get_result( $filters );
		$stats   = $this->build_stats( $result['rows'], $result['total'] );

		wp_send_json_success(
			array(
				'stats_html'      => $this->render_stats_markup( $stats ),
				'table_rows_html' => $this->render_table_rows_markup( $result['rows'] ),
				'pagination_html' => $this->render_pagination_markup( $result['page'], $result['pages'] ),
				'meta'            => array(
					'page'  => $result['page'],
					'pages' => $result['pages'],
					'total' => $result['total'],
				),
			)
		);
	}

	/**
	 * AJAX: return linked payment-order summaries for one primary order.
	 *
	 * Used by the Trash/Cancel modal so the (relatively expensive) child lookup
	 * happens once, on demand, instead of for every row on every render.
	 *
	 * @return void
	 */
	public function ajax_child_orders() {
		check_ajax_referer( 'vanpos_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'delete_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing order ID.', 'vanjorn-rental-pos' ) ), 400 );
		}

		$children = array();
		if ( class_exists( 'VanPOS_Admin_Order_Delete_Cascade' ) ) {
			$children = VanPOS_Admin_Order_Delete_Cascade::collect_child_summaries_for_order_id( $order_id );
		}

		wp_send_json_success( array( 'children' => array_values( (array) $children ) ) );
	}

	/**
	 * Render dashboard page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$filters = $this->read_filters( $_GET );
		$result  = VanPOS_Admin_Dashboard_Overview_Query::get_result( $filters );
		$stats   = $this->build_stats( $result['rows'], $result['total'] );
		?>
		<div class="wrap vanpos-dashboard-page">
			<?php
			if ( class_exists( 'VanPOS_Admin_Pos_Nav' ) ) {
				VanPOS_Admin_Pos_Nav::render( VanPOS_Admin_Pos_Nav::TAB_UPCOMING );
			}
			?>
			<div class="vanpos-dashboard-page__wrap-for-notices"></div>
			<div class="vanpos-dashboard-page__hero">
				<div class="vanpos-dashboard-page__title-wrap">
					<h1><?php esc_html_e( 'VAN-Jorn Rentals Dashboard', 'vanjorn-rental-pos' ); ?></h1>
					<p class="description"><?php esc_html_e( 'Premium operational overview for bookings, payments and pickups.', 'vanjorn-rental-pos' ); ?></p>
				</div>
				<div class="vanpos-dashboard-page__stats" data-vanpos-dashboard-stats>
					<?php echo $this->render_stats_markup( $stats ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="vanpos-dashboard-page__filters" data-vanpos-dashboard-form>
				<input type="hidden" name="page" value="vanjorn-rental-pos-dashboard">
				<input type="hidden" name="vanpos_dash_page" value="<?php echo esc_attr( (string) $result['page'] ); ?>" data-vanpos-page-input>
				<?php /* sort_by / sort_dir: driven by column-header links; held as hidden inputs so AJAX carries them */ ?>
				<input type="hidden" name="vanpos_dash_sort_by"  value="<?php echo esc_attr( $filters['sort_by'] ); ?>" data-vanpos-sort-by-input>
				<input type="hidden" name="vanpos_dash_sort_dir" value="<?php echo esc_attr( $filters['sort_dir'] ); ?>" data-vanpos-sort-dir-input>

				<div class="vanpos-dashboard-page__toolbar">
					<div class="vanpos-dashboard-page__toolbar-group">
						<label><?php esc_html_e( 'View', 'vanjorn-rental-pos' ); ?></label>
						<select name="vanpos_dash_view" data-vanpos-auto>
							<?php foreach ( $this->get_view_options() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['view'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="vanpos-dashboard-page__toolbar-group">
						<label><?php esc_html_e( 'Range', 'vanjorn-rental-pos' ); ?></label>
						<select name="vanpos_dash_range" data-vanpos-auto>
							<?php foreach ( $this->get_range_options() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['range'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="vanpos-dashboard-page__toolbar-group vanpos-dashboard-page__toolbar-group--wide">
						<label><?php esc_html_e( 'Product', 'vanjorn-rental-pos' ); ?></label>
						<select name="vanpos_dash_product_id" data-vanpos-auto>
							<option value="0"><?php esc_html_e( 'All rental products', 'vanjorn-rental-pos' ); ?></option>
							<?php foreach ( $this->get_product_options() as $product ) : ?>
								<option value="<?php echo esc_attr( (string) $product['id'] ); ?>" <?php selected( $filters['product_id'], (int) $product['id'] ); ?>><?php echo esc_html( (string) $product['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="vanpos-dashboard-page__toolbar-group vanpos-dashboard-page__toolbar-group--search">
						<label><?php esc_html_e( 'Search', 'vanjorn-rental-pos' ); ?></label>
						<input type="search" name="vanpos_dash_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Order number, product, customer', 'vanjorn-rental-pos' ); ?>" data-vanpos-search>
					</div>
					<div class="vanpos-dashboard-page__toolbar-group">
						<label><?php esc_html_e( 'Order type', 'vanjorn-rental-pos' ); ?></label>
						<select name="vanpos_dash_order_type" data-vanpos-auto>
							<?php foreach ( $this->get_order_type_options() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['order_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="vanpos-dashboard-page__actions">
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply Filters', 'vanjorn-rental-pos' ); ?></button>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vanjorn-rental-pos-dashboard' ) ); ?>"><?php esc_html_e( 'Reset', 'vanjorn-rental-pos' ); ?></a>
				</div>
			</form>

			<div class="vanpos-dashboard-page__table-wrap" data-vanpos-dashboard-table-wrap>
				<table class="striped vanpos-dashboard-page__table">
					<thead>
						<tr>
							<th><?php echo wp_kses_post( $this->get_sort_heading( __( 'Order', 'vanjorn-rental-pos' ), 'created', $filters ) ); ?></th>
							<th><?php esc_html_e( 'Type', 'vanjorn-rental-pos' ); ?></th>
							<th><?php echo wp_kses_post( $this->get_sort_heading( __( 'Start Date', 'vanjorn-rental-pos' ), 'pickup', $filters ) ); ?></th>
							<th><?php echo wp_kses_post( $this->get_sort_heading( __( 'End Date', 'vanjorn-rental-pos' ), 'return', $filters ) ); ?></th>
							<th><?php echo wp_kses_post( $this->get_sort_heading( __( 'Due Date', 'vanjorn-rental-pos' ), 'due', $filters ) ); ?></th>
							<th class="vanpos-dashboard-page__product-col"><?php esc_html_e( 'Product', 'vanjorn-rental-pos' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'vanjorn-rental-pos' ); ?></th>
							<th><?php esc_html_e( 'Status', 'vanjorn-rental-pos' ); ?></th>
							<th><?php esc_html_e( 'Total', 'vanjorn-rental-pos' ); ?></th>
							<?php if ( self::show_actions_column() ) : ?>
								<th><?php esc_html_e( 'Actions', 'vanjorn-rental-pos' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody data-vanpos-dashboard-table-body>
						<?php echo $this->render_table_rows_markup( $result['rows'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</tbody>
				</table>
			</div>

			<div class="vanpos-dashboard-page__pagination" data-vanpos-dashboard-pagination>
				<?php echo $this->render_pagination_markup( $result['page'], $result['pages'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="vanpos-dashboard-page__loading" data-vanpos-dashboard-loading hidden>
				<div class="vanpos-dashboard-page__loading-card">
					<span class="spinner is-active"></span>
					<span><?php esc_html_e( 'Applying filters...', 'vanjorn-rental-pos' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// Stats cards
	// =========================================================================

	/**
	 * Render summary stats cards markup.
	 *
	 * @param array<string,int> $stats Stats.
	 * @return string
	 */
	private function render_stats_markup( array $stats ) {
		ob_start();
		?>
		<div class="vanpos-dashboard-page__card">
			<span class="dashicons dashicons-chart-bar"></span>
			<span class="vanpos-dashboard-page__card-label"><?php esc_html_e( 'Total Orders', 'vanjorn-rental-pos' ); ?></span>
			<strong><?php echo esc_html( (string) $stats['total_orders'] ); ?></strong>
		</div>
		<div class="vanpos-dashboard-page__card">
			<span class="dashicons dashicons-warning"></span>
			<span class="vanpos-dashboard-page__card-label"><?php esc_html_e( 'Pending Payments', 'vanjorn-rental-pos' ); ?></span>
			<strong><?php echo esc_html( (string) $stats['pending_payments'] ); ?></strong>
		</div>
		<div class="vanpos-dashboard-page__card">
			<span class="dashicons dashicons-calendar-alt"></span>
			<span class="vanpos-dashboard-page__card-label"><?php esc_html_e( 'Upcoming Pickups', 'vanjorn-rental-pos' ); ?></span>
			<strong><?php echo esc_html( (string) $stats['upcoming_pickups'] ); ?></strong>
		</div>
		<div class="vanpos-dashboard-page__card">
			<span class="dashicons dashicons-tickets-alt"></span>
			<span class="vanpos-dashboard-page__card-label"><?php esc_html_e( 'Security Deposits', 'vanjorn-rental-pos' ); ?></span>
			<strong><?php echo esc_html( (string) $stats['deposits'] ); ?></strong>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build top summary stats from the current page's rows.
	 *
	 * NOTE: pending_payments / upcoming_pickups / deposits are computed from the
	 * current page's rows only, so they reflect the visible page rather than the
	 * full filtered set. total_orders is the true filtered count. If you want the
	 * three counters to reflect the whole result set, they need their own COUNT
	 * queries rather than per-row tallying here.
	 *
	 * @param array<int,array<string,mixed>> $rows  Current page rows.
	 * @param int                            $total Total rows after filtering.
	 * @return array<string,int>
	 */
	private function build_stats( array $rows, $total = 0 ) {
		$out = array(
			'total_orders'     => (int) $total,
			'pending_payments' => 0,
			'upcoming_pickups' => 0,
			'deposits'         => 0,
		);

		$today = current_time( 'timestamp' );
		$soon  = strtotime( '+7 days', $today );

		foreach ( $rows as $row ) {
			// Compare by the stable type key, not the translated label string.
			if ( 'security_deposit' === ( $row['order_type_key'] ?? '' ) ) {
				++$out['deposits'];
			}

			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( in_array( $status, array( 'pending', 'on-hold' ), true ) ) {
				++$out['pending_payments'];
			}

			$pickup_ts = isset( $row['pickup_ts'] ) ? (int) $row['pickup_ts'] : 0;
			if ( $pickup_ts > 0 && $pickup_ts >= $today && $pickup_ts <= $soon ) {
				++$out['upcoming_pickups'];
			}
		}

		return $out;
	}

	// =========================================================================
	// Table rendering
	// =========================================================================

	/**
	 * Whether the dashboard table includes an Actions column.
	 */
	private static function show_actions_column() {
		return current_user_can( 'edit_shop_orders' );
	}

	/**
	 * Table column count (data columns + optional Actions).
	 */
	private static function get_table_colspan() {
		return self::show_actions_column() ? 10 : 9;
	}

	/**
	 * Render action buttons for a dashboard row (main bookings only).
	 *
	 * CMIT CODE - UPDATED - 15 MAY 2026
	 * Mark as returned uses VanPOS_Rental_Returned (Kestrel-compatible meta + instant availability).
	 *
	 * @param array<string, mixed> $row Normalized row from overview query.
	 * @return string HTML.
	 */
	private function render_row_actions_cell( array $row ) {
		if ( empty( $row['is_main_booking_row'] ) ) {
			return '<span class="vanpos-dashboard-page__dash">&mdash;</span>';
		}

		$order_id     = (int) $row['order_id'];
		$order_number = (string) $row['order_number'];
		$parts        = array();

		// Linked payment-order summaries are NOT collected here. Doing so per row
		// triggered two wc_get_orders() calls per main booking on every render
		// (an N+1). They are now fetched on demand when the modal opens, via the
		// vanpos_dashboard_child_orders AJAX endpoint.

		if ( current_user_can( 'edit_shop_orders' ) ) {
			$cancel_title = sprintf(
				/* translators: %s: order number */
				__( 'Cancel booking #%s (with optional linked orders)', 'vanjorn-rental-pos' ),
				$order_number
			);
			$parts[]      = sprintf(
				'<button type="button" class="button button-small vanpos-dashboard-cancel-booking" title="%1$s" data-vanpos-booking-cancel="1" data-order-id="%2$d" data-order-number="%3$s">%4$s</button>',
				esc_attr( $cancel_title ),
				$order_id,
				esc_attr( $order_number ),
				esc_html__( 'Cancel booking', 'vanjorn-rental-pos' )
			);
		}

		if ( current_user_can( 'delete_shop_orders' ) && class_exists( 'VanPOS_Admin_Order_Delete_Cascade' ) ) {
			$btn_title = sprintf(
				/* translators: %s: order number */
				__( 'Move booking #%s to Trash (with optional linked orders)', 'vanjorn-rental-pos' ),
				$order_number
			);
			$parts[]   = sprintf(
				'<button type="button" class="button button-small vanpos-dashboard-trash-booking" title="%1$s" data-vanpos-booking-delete="1" data-order-id="%2$d" data-order-number="%3$s">%4$s</button>',
				esc_attr( $btn_title ),
				$order_id,
				esc_attr( $order_number ),
				esc_html__( 'Trash booking', 'vanjorn-rental-pos' )
			);
		}

		if ( empty( $parts ) ) {
			return '<span class="vanpos-dashboard-page__dash">&mdash;</span>';
		}

		return '<div class="vanpos-dashboard-page__actions-stack">' . implode( '', $parts ) . '</div>';
	}

	private function render_table_rows_markup( array $rows ) {
		ob_start();

		if ( empty( $rows ) ) :
			?>
			<tr>
				<td colspan="<?php echo esc_attr( (string) self::get_table_colspan() ); ?>" class="vanpos-dashboard-page__empty-state">
					<span class="dashicons dashicons-search"></span>
					<strong><?php esc_html_e( 'No results found', 'vanjorn-rental-pos' ); ?></strong>
					<span><?php esc_html_e( 'Try changing filters or search query.', 'vanjorn-rental-pos' ); ?></span>
				</td>
			</tr>
			<?php
		else :
			foreach ( $rows as $row ) :
				?>
				<tr class="vanpos-dashboard-row">
					<td><a href="<?php echo esc_url( (string) $row['edit_url'] ); ?>">#<?php echo esc_html( (string) $row['order_number'] ); ?></a></td>
					<td><span class="vanpos-pill vanpos-pill--type"><?php echo esc_html( (string) $row['order_type'] ); ?></span></td>
					<td><?php echo esc_html( $this->format_date( (string) $row['pickup_raw'] ) ); ?></td>
					<td><?php echo esc_html( $this->format_date( (string) $row['return_raw'] ) ); ?></td>
					<td><?php echo esc_html( $this->format_date( (string) $row['due_raw'] ) ); ?></td>
					<?php echo $this->render_product_cell( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<td>
						<?php if ( ! empty( $row['customer_edit_url'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $row['customer_edit_url'] ); ?>"><?php echo esc_html( (string) $row['customer_name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) $row['customer_name'] ); ?>
						<?php endif; ?>
					</td>
					<td>
						<span class="vanpos-pill vanpos-pill--status <?php echo esc_attr( $this->get_status_class( (string) $row['status'] ) ); ?>">
							<?php echo esc_html( (string) $row['status_label'] ); ?>
						</span>
					</td>
					<td class="vanpos-dashboard-page__total-cell"><?php echo wp_kses_post( (string) $row['total_html'] ); ?></td>
					<?php if ( self::show_actions_column() ) : ?>
						<td class="vanpos-dashboard-page__actions-cell">
							<?php echo $this->render_row_actions_cell( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
					<?php endif; ?>
				</tr>
				<?php
			endforeach;
		endif;

		return (string) ob_get_clean();
	}

	/**
	 * Render pagination.
	 */
	private function render_pagination_markup( $page, $pages ) {
		$page  = max( 1, (int) $page );
		$pages = max( 1, (int) $pages );

		ob_start();
		?>
		<button type="button" class="button" data-vanpos-page="<?php echo esc_attr( (string) max( 1, $page - 1 ) ); ?>" <?php disabled( $page <= 1 ); ?>><?php esc_html_e( 'Previous', 'vanjorn-rental-pos' ); ?></button>
		<span class="vanpos-dashboard-page__pagination-label"><?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'vanjorn-rental-pos' ), $page, $pages ) ); ?></span>
		<button type="button" class="button" data-vanpos-page="<?php echo esc_attr( (string) min( $pages, $page + 1 ) ); ?>" <?php disabled( $page >= $pages ); ?>><?php esc_html_e( 'Next', 'vanjorn-rental-pos' ); ?></button>
		<?php
		return (string) ob_get_clean();
	}

	// =========================================================================
	// Filters
	// =========================================================================

	/**
	 * Read and sanitize filter input.
	 *
	 * Custom range (from_date/to_date) and year filter have been removed from the UI.
	 * Limit is fixed at PER_PAGE = 25; the control has been removed.
	 * Sort direction is now driven by column-header links, stored in hidden form inputs.
	 *
	 * @param array $source Source query array ($_GET or $_POST).
	 * @return array<string,mixed>
	 */
	private function read_filters( array $source ) {
		$view     = isset( $source['vanpos_dash_view'] ) ? sanitize_text_field( wp_unslash( $source['vanpos_dash_view'] ) ) : 'all';
		$range    = isset( $source['vanpos_dash_range'] ) ? sanitize_text_field( wp_unslash( $source['vanpos_dash_range'] ) ) : '7days';
		$sort_by  = isset( $source['vanpos_dash_sort_by'] ) ? sanitize_text_field( wp_unslash( $source['vanpos_dash_sort_by'] ) ) : 'pickup';
		$sort_dir = isset( $source['vanpos_dash_sort_dir'] ) ? strtolower( sanitize_text_field( wp_unslash( $source['vanpos_dash_sort_dir'] ) ) ) : 'desc';

		$view_opts  = $this->get_view_options();
		$range_opts = $this->get_range_options();

		if ( ! isset( $view_opts[ $view ] ) ) {
			$view = 'all';
		}
		if ( ! isset( $range_opts[ $range ] ) ) {
			$range = '7days';
		}
		if ( ! in_array( $sort_by, array( 'pickup', 'return', 'due', 'created' ), true ) ) {
			$sort_by = 'pickup';
		}
		if ( ! in_array( $sort_dir, array( 'asc', 'desc' ), true ) ) {
			$sort_dir = 'desc';
		}

		$order_type = 'all';
		if ( isset( $source['vanpos_dash_order_type'] ) && '' !== (string) $source['vanpos_dash_order_type'] ) {
			$order_type = sanitize_key( wp_unslash( $source['vanpos_dash_order_type'] ) );
		} elseif ( isset( $source['vanpos_dash_main_only'] ) && '1' === (string) wp_unslash( $source['vanpos_dash_main_only'] ) ) {
			// Legacy: checkbox removed in favour of Order type dropdown.
			$order_type = 'main';
		}
		$order_type_opts = $this->get_order_type_options();
		if ( ! isset( $order_type_opts[ $order_type ] ) ) {
			$order_type = 'all';
		}

		return array(
			'view'        => $view,
			'range'       => $range,
			'from_date'   => '', // Custom range removed; kept for resolve_date_window() compat.
			'to_date'     => '',
			'product_id'  => isset( $source['vanpos_dash_product_id'] ) ? absint( wp_unslash( $source['vanpos_dash_product_id'] ) ) : 0,
			'year'        => 0,  // Year filter removed.
			'page'        => isset( $source['vanpos_dash_page'] ) ? max( 1, absint( wp_unslash( $source['vanpos_dash_page'] ) ) ) : 1,
			'search'      => isset( $source['vanpos_dash_search'] ) ? sanitize_text_field( wp_unslash( $source['vanpos_dash_search'] ) ) : '',
			'sort_by'     => $sort_by,
			'sort_dir'    => $sort_dir,
			'order_type'  => $order_type,
		);
	}

	// =========================================================================
	// Option lists
	// =========================================================================

	private function get_view_options() {
		return array(
			'all'     => __( 'All', 'vanjorn-rental-pos' ),
			'pickups' => __( 'Pickups', 'vanjorn-rental-pos' ),
			'returns' => __( 'Returns', 'vanjorn-rental-pos' ),
			'due'     => __( 'Due Payments', 'vanjorn-rental-pos' ),
		);
	}

	/**
	 * Range options. Custom range removed — it did not function correctly.
	 */
	private function get_range_options() {
		return array(
			'today'  => __( 'Today', 'vanjorn-rental-pos' ),
			'3days'  => __( 'Next 3 days', 'vanjorn-rental-pos' ),
			'7days'  => __( 'Next 7 days', 'vanjorn-rental-pos' ),
			'30days' => __( 'Next 30 days', 'vanjorn-rental-pos' ),
			'all'    => __( 'All dates', 'vanjorn-rental-pos' ),
		);
	}

	private function get_order_type_options() {
		return array(
			'all'               => __( 'All order types', 'vanjorn-rental-pos' ),
			'main'              => __( 'Main orders only', 'vanjorn-rental-pos' ),
			'security_deposit'  => __( 'Security deposits', 'vanjorn-rental-pos' ),
			'remaining_payment' => __( 'Remaining payments', 'vanjorn-rental-pos' ),
			'payment_other'     => __( 'Other payment orders', 'vanjorn-rental-pos' ),
		);
	}

	private function get_product_options() {
		if ( ! class_exists( 'VanPOS_Functions' ) ) {
			return array();
		}
		$products = VanPOS_Functions::get_rental_products();
		usort(
			$products,
			static function ( $a, $b ) {
				$an = isset( $a['name'] ) ? (string) $a['name'] : '';
				$bn = isset( $b['name'] ) ? (string) $b['name'] : '';
				return strcasecmp( $an, $bn );
			}
		);
		return $products;
	}

	// =========================================================================
	// Column sort heading
	// =========================================================================

	/**
	 * Render a column heading as a sort link.
	 *
	 * Clicking the link reloads the page with the new sort params (all other
	 * current filters are preserved in the URL). Clicking the active column
	 * again reverses the sort direction. The hidden vanpos_dash_sort_by /
	 * vanpos_dash_sort_dir inputs in the filter form are then correctly set on
	 * load, so subsequent AJAX filter changes also carry the sort state.
	 *
	 * @param string              $label   Column header label.
	 * @param string              $key     Sort key (pickup|return|due|created).
	 * @param array<string,mixed> $filters Current filters.
	 * @return string HTML.
	 */
	private function get_sort_heading( $label, $key, array $filters ) {
		$active      = isset( $filters['sort_by'] ) && $key === $filters['sort_by'];
		$current_dir = $active ? ( $filters['sort_dir'] ?? 'asc' ) : 'asc';
		$next_dir    = ( $active && 'asc' === $current_dir ) ? 'desc' : 'asc';

		$arrow = '↕';
		if ( $active ) {
			$arrow = ( 'desc' === $current_dir ) ? '↓' : '↑';
		}

		$url = add_query_arg(
			array(
				'page'                   => 'vanjorn-rental-pos-dashboard',
				'vanpos_dash_view'       => $filters['view']       ?? 'pickups',
				'vanpos_dash_range'      => $filters['range']      ?? '7days',
				'vanpos_dash_product_id' => $filters['product_id'] ?? 0,
				'vanpos_dash_search'     => $filters['search']     ?? '',
				'vanpos_dash_order_type' => $filters['order_type'] ?? 'all',
				'vanpos_dash_sort_by'    => $key,
				'vanpos_dash_sort_dir'   => $next_dir,
				'vanpos_dash_page'       => 1, // Reset to page 1 on sort change.
			),
			admin_url( 'admin.php' )
		);

		$class = 'vanpos-sort-heading' . ( $active ? ' vanpos-sort-heading--active' : '' );

		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">'
			. esc_html( $label )
			. ' <small>' . esc_html( $arrow ) . '</small>'
			. '</a>';
	}

	// =========================================================================
	// Misc helpers
	// =========================================================================

	private function get_status_class( $status ) {
		$status = sanitize_key( $status );
		$map    = array(
			'pending'    => 'vanpos-status--pending',
			'on-hold'    => 'vanpos-status--pending',
			'completed'  => 'vanpos-status--paid',
			'processing' => 'vanpos-status--processing',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : 'vanpos-status--neutral';
	}

	private function format_date( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return '—';
		}
		$ts = strtotime( $date );
		if ( ! $ts ) {
			return $date;
		}
		return date_i18n( get_option( 'date_format' ), $ts );
	}

	/**
	 * CMIT CODE - UPDATED - 06 MAY 2026
	 * Truncate long labels for the dashboard product column (suffix ..).
	 */
	private function truncate_dashboard_label( $text, $max_chars = 36 ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}

		$suffix    = '..';
		$max_chars = max( 4, (int) $max_chars );

		$strlen_fn = static function ( $s ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
		};
		$substr_fn = static function ( $s, $start, $length ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $s, $start, $length, 'UTF-8' ) : substr( $s, $start, $length );
		};

		if ( $strlen_fn( $text ) <= $max_chars ) {
			return $text;
		}

		$suffix_len = $strlen_fn( $suffix );
		$take       = max( 1, $max_chars - $suffix_len );

		return rtrim( $substr_fn( $text, 0, $take ) ) . $suffix;
	}

	/**
	 * CMIT CODE - UPDATED - 06 MAY 2026
	 * Dashboard table cell: product ID (edit link) + truncated product name.
	 */
	private function render_product_cell( array $row ) {
		$pid   = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
		$url   = isset( $row['product_edit_url'] ) ? (string) $row['product_edit_url'] : '';
		$name  = isset( $row['product_name'] ) ? (string) $row['product_name'] : '';
		$short = $this->truncate_dashboard_label( $name );

		ob_start();
		?>
		<td class="vanpos-dashboard-page__product-cell">
			<span class="vanpos-dashboard-product">
				<?php if ( $pid > 0 && '' !== $url ) : ?>
					<a class="vanpos-dashboard-product__id" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( '#' . (string) $pid ); ?></a>
					<a class="vanpos-dashboard-product__title" href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $short ); ?></a>
				<?php elseif ( '' !== $url ) : ?>
					<a class="vanpos-dashboard-product__title" href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $short ); ?></a>
				<?php else : ?>
					<span class="vanpos-dashboard-product__plain" title="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $short ); ?></span>
				<?php endif; ?>
			</span>
		</td>
		<?php
		return (string) ob_get_clean();
	}
}
