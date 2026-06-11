<?php
/**
 * VAN-Jorn Rental POS “Returns to process” admin page (mark vans returned).
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backward-looking queue for marking rentals returned (Kestrel-style).
 */
class VanPOS_Admin_Returns_Queue_Page {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_submenu' ), 13 );
		add_action( 'admin_menu', array( $this, 'append_menu_badge' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_print_styles', array( $this, 'enqueue_assets_fallback' ) );
		add_action( 'wp_ajax_vanpos_returns_queue_filter', array( $this, 'ajax_filter' ) );
	}

	/**
	 * Add submenu under VAN-Jorn Rental POS.
	 *
	 * @return void
	 */
	public function add_submenu() {
		add_submenu_page(
			'vanjorn-rental-pos',
			__( 'Returns to process', 'vanjorn-rental-pos' ),
			__( 'Returns to process', 'vanjorn-rental-pos' ),
			'edit_shop_orders',
			'vanjorn-rental-pos-returns-queue',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Pending-count badge on submenu (90-day lookback).
	 *
	 * @return void
	 */
	public function append_menu_badge() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		global $submenu;
		if ( empty( $submenu['vanjorn-rental-pos'] ) || ! is_array( $submenu['vanjorn-rental-pos'] ) ) {
			return;
		}

		$count = class_exists( 'VanPOS_Admin_Returns_Queue_Query' )
			? VanPOS_Admin_Returns_Queue_Query::count_pending()
			: 0;

		if ( $count <= 0 ) {
			return;
		}

		foreach ( $submenu['vanjorn-rental-pos'] as $key => $item ) {
			if ( ! isset( $item[2] ) || 'vanjorn-rental-pos-returns-queue' !== $item[2] ) {
				continue;
			}
			$submenu['vanjorn-rental-pos'][ $key ][0] .= sprintf(
				' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count">%1$d</span></span>',
				(int) $count
			);
			break;
		}
	}

	/**
	 * Enqueue page assets.
	 *
	 * @param string $hook Admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! $this->is_returns_page( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'vanpos-admin-dashboard-page',
			VANPOS_PLUGIN_URL . 'admin/css/dashboard-page.css',
			array(),
			VANPOS_VERSION
		);

		wp_enqueue_style(
			'vanpos-admin-returns-queue-page',
			VANPOS_PLUGIN_URL . 'admin/css/returns-queue-page.css',
			array( 'vanpos-admin-dashboard-page' ),
			VANPOS_VERSION
		);

		wp_enqueue_script(
			'vanpos-admin-returns-queue-page',
			VANPOS_PLUGIN_URL . 'admin/js/returns-queue-page.js',
			array( 'jquery' ),
			VANPOS_VERSION,
			true
		);

		wp_localize_script(
			'vanpos-admin-returns-queue-page',
			'vanposReturnsQueue',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'vanpos_returns_queue_nonce' ),
				'markReturnedNonce' => wp_create_nonce( 'vanpos_dashboard_mark_returned' ),
				'canMarkReturned'   => current_user_can( 'edit_shop_orders' ),
				'i18n'              => array(
					'loading'   => __( 'Loading returns queue…', 'vanjorn-rental-pos' ),
					'loadError' => __( 'Could not load the returns queue. Please refresh the page.', 'vanjorn-rental-pos' ),
				),
				'markReturned'      => array(
					'confirm'      => __( 'Mark this van as returned? It will become available for new bookings immediately.', 'vanjorn-rental-pos' ),
					'busy'         => __( 'Marking returned…', 'vanjorn-rental-pos' ),
					'errorGeneric' => __( 'Could not mark as returned. Please try again.', 'vanjorn-rental-pos' ),
					/* translators: %s: error message from server */
					'errorDetail'  => __( 'Details: %s', 'vanjorn-rental-pos' ),
				),
			)
		);
	}

	/**
	 * Fallback enqueue when hook name differs.
	 *
	 * @return void
	 */
	public function enqueue_assets_fallback() {
		if ( ! $this->is_returns_page( '' ) ) {
			return;
		}

		if ( ! wp_style_is( 'vanpos-admin-returns-queue-page', 'enqueued' ) ) {
			$this->enqueue_assets( 'vanjorn-rental-pos_page_vanjorn-rental-pos-returns-queue' );
		}
	}

	/**
	 * Whether current screen is the returns queue page.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	private function is_returns_page( $hook ) {
		if ( 'vanjorn-rental-pos_page_vanjorn-rental-pos-returns-queue' === (string) $hook ) {
			return true;
		}

		if ( isset( $_GET['page'] ) && 'vanjorn-rental-pos-returns-queue' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return true;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, 'vanjorn-rental-pos-returns-queue' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * AJAX filter handler.
	 *
	 * @return void
	 */
	public function ajax_filter() {
		check_ajax_referer( 'vanpos_returns_queue_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'vanjorn-rental-pos' ) ), 403 );
		}

		$filters = $this->read_filters( $_POST );
		$result  = VanPOS_Admin_Returns_Queue_Query::get_result( $filters );

		wp_send_json_success(
			array(
				'stats_html'      => $this->render_stats_markup( $result ),
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
	 * Render page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vanjorn-rental-pos' ) );
		}

		$filters = $this->read_filters( $_GET );
		$result  = VanPOS_Admin_Returns_Queue_Query::get_result( $filters );
		?>
		<div class="wrap vanpos-returns-queue-page">
			<?php
			if ( class_exists( 'VanPOS_Admin_Pos_Nav' ) ) {
				VanPOS_Admin_Pos_Nav::render( VanPOS_Admin_Pos_Nav::TAB_RETURNS );
			}
			?>
			<div class="vanpos-returns-queue-page__wrap-for-notices"></div>
			<div class="vanpos-returns-queue-page__hero">
				<div class="vanpos-returns-queue-page__title-wrap">
					<h1><?php esc_html_e( 'Returns to process', 'vanjorn-rental-pos' ); ?></h1>
					<p class="description">
						<?php esc_html_e( 'Main bookings whose return date is on or before today and are not yet marked returned. Marking returned frees the van on the booking calendar immediately.', 'vanjorn-rental-pos' ); ?>
					</p>
				</div>
				<div class="vanpos-returns-queue-page__stats" data-vanpos-returns-stats>
					<?php echo $this->render_stats_markup( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="vanpos-returns-queue-page__filters" data-vanpos-returns-form>
				<input type="hidden" name="page" value="vanjorn-rental-pos-returns-queue">
				<input type="hidden" name="vanpos_ret_page" value="<?php echo esc_attr( (string) $result['page'] ); ?>" data-vanpos-page-input>
				<div class="vanpos-returns-queue-page__toolbar">
					<div class="vanpos-returns-queue-page__toolbar-group">
						<label for="vanpos_ret_lookback"><?php esc_html_e( 'Look back', 'vanjorn-rental-pos' ); ?></label>
						<select name="vanpos_ret_lookback" id="vanpos_ret_lookback" data-vanpos-auto>
							<?php foreach ( $this->get_lookback_options() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['lookback'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="vanpos-returns-queue-page__toolbar-group">
						<label>
							<input type="checkbox" name="vanpos_ret_overdue" value="1" data-vanpos-auto <?php checked( ! empty( $filters['overdue'] ) ); ?>>
							<?php esc_html_e( 'Overdue only', 'vanjorn-rental-pos' ); ?>
						</label>
					</div>
					<div class="vanpos-returns-queue-page__toolbar-group vanpos-returns-queue-page__toolbar-group--search">
						<label for="vanpos_ret_search"><?php esc_html_e( 'Search', 'vanjorn-rental-pos' ); ?></label>
						<input type="search" name="vanpos_ret_search" id="vanpos_ret_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Order, customer, van…', 'vanjorn-rental-pos' ); ?>" data-vanpos-search>
					</div>
					<div class="vanpos-returns-queue-page__toolbar-group">
						<label for="vanpos_ret_limit"><?php esc_html_e( 'Per page', 'vanjorn-rental-pos' ); ?></label>
						<select name="vanpos_ret_limit" id="vanpos_ret_limit" data-vanpos-auto>
							<?php foreach ( array( 25, 50, 100 ) as $n ) : ?>
								<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $filters['limit'], $n ); ?>><?php echo esc_html( (string) $n ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="vanpos-returns-queue-page__table-panel">
					<div class="vanpos-returns-queue-page__loading" data-vanpos-returns-loading hidden>
						<span class="spinner is-active"></span>
						<span><?php esc_html_e( 'Loading returns queue…', 'vanjorn-rental-pos' ); ?></span>
					</div>
					<div class="vanpos-returns-queue-page__table-wrap" data-vanpos-returns-table-wrap>
						<table class="widefat striped vanpos-returns-queue-page__table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Order', 'vanjorn-rental-pos' ); ?></th>
									<th><?php esc_html_e( 'Customer', 'vanjorn-rental-pos' ); ?></th>
									<th><?php esc_html_e( 'Marked', 'vanjorn-rental-pos' ); ?></th>
									<th><?php esc_html_e( 'Return date', 'vanjorn-rental-pos' ); ?></th>
									<th><?php esc_html_e( 'Status', 'vanjorn-rental-pos' ); ?></th>
									<?php if ( current_user_can( 'edit_shop_orders' ) ) : ?>
										<th><?php esc_html_e( 'Action', 'vanjorn-rental-pos' ); ?></th>
									<?php endif; ?>
								</tr>
							</thead>
							<tbody data-vanpos-returns-table-body>
								<?php echo $this->render_table_rows_markup( $result['rows'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</tbody>
						</table>
						<div class="vanpos-returns-queue-page__pagination" data-vanpos-returns-pagination>
							<?php echo $this->render_pagination_markup( $result['page'], $result['pages'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Read/sanitize filters.
	 *
	 * @param array $source Request source.
	 * @return array<string,mixed>
	 */
	private function read_filters( array $source ) {
		$default  = class_exists( 'VanPOS_Admin_Returns_Queue_Query' )
			? VanPOS_Admin_Returns_Queue_Query::DEFAULT_LOOKBACK
			: '30days';
		$lookback = isset( $source['vanpos_ret_lookback'] ) ? sanitize_text_field( wp_unslash( $source['vanpos_ret_lookback'] ) ) : $default;
		$opts     = $this->get_lookback_options();
		if ( ! isset( $opts[ $lookback ] ) ) {
			$lookback = $default;
		}

		$limit = isset( $source['vanpos_ret_limit'] ) ? absint( wp_unslash( $source['vanpos_ret_limit'] ) ) : 50;
		if ( ! in_array( $limit, array( 25, 50, 100 ), true ) ) {
			$limit = 50;
		}

		$page = isset( $source['vanpos_ret_page'] ) ? absint( wp_unslash( $source['vanpos_ret_page'] ) ) : 1;

		return array(
			'lookback' => $lookback,
			'overdue'  => ! empty( $source['vanpos_ret_overdue'] ),
			'search'   => isset( $source['vanpos_ret_search'] ) ? sanitize_text_field( wp_unslash( $source['vanpos_ret_search'] ) ) : '',
			'limit'    => $limit,
			'page'     => max( 1, $page ),
		);
	}

	/**
	 * Lookback dropdown options.
	 *
	 * @return array<string,string>
	 */
	private function get_lookback_options() {
		return array(
			'7days'  => __( 'Last 7 days', 'vanjorn-rental-pos' ),
			'30days' => __( 'Last 30 days', 'vanjorn-rental-pos' ),
			'90days' => __( 'Last 90 days', 'vanjorn-rental-pos' ),
			'all'    => __( 'All (10 years)', 'vanjorn-rental-pos' ),
		);
	}

	/**
	 * Stats cards markup.
	 *
	 * @param array{total:int,rows:array} $result Query result.
	 * @return string
	 */
	private function render_stats_markup( array $result ) {
		$total  = (int) ( $result['total'] ?? 0 );
		$overdue = 0;
		foreach ( $result['rows'] ?? array() as $row ) {
			if ( ! empty( $row['is_overdue'] ) ) {
				++$overdue;
			}
		}

		ob_start();
		?>
		<div class="vanpos-dashboard-page__card">
			<span class="dashicons dashicons-undo"></span>
			<span class="vanpos-dashboard-page__card-label"><?php esc_html_e( 'Awaiting return', 'vanjorn-rental-pos' ); ?></span>
			<strong><?php echo esc_html( (string) $total ); ?></strong>
		</div>
		<div class="vanpos-dashboard-page__card">
			<span class="dashicons dashicons-warning"></span>
			<span class="vanpos-dashboard-page__card-label"><?php esc_html_e( 'Overdue on this page', 'vanjorn-rental-pos' ); ?></span>
			<strong><?php echo esc_html( (string) $overdue ); ?></strong>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Table body rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return string
	 */
	private function render_table_rows_markup( array $rows ) {
		$colspan = current_user_can( 'edit_shop_orders' ) ? 6 : 5;

		ob_start();

		if ( empty( $rows ) ) :
			?>
			<tr>
				<td colspan="<?php echo esc_attr( (string) $colspan ); ?>" class="vanpos-dashboard-page__empty-state">
					<span class="dashicons dashicons-yes-alt"></span>
					<strong><?php esc_html_e( 'No rentals waiting to be marked returned', 'vanjorn-rental-pos' ); ?></strong>
					<span><?php esc_html_e( 'All vans in this period are marked returned, or try a wider look-back range.', 'vanjorn-rental-pos' ); ?></span>
				</td>
			</tr>
			<?php
		else :
			foreach ( $rows as $row ) :
				$is_overdue = ! empty( $row['is_overdue'] );
				?>
				<tr class="vanpos-returns-queue-row<?php echo $is_overdue ? ' vanpos-returns-queue-row--overdue' : ''; ?>">
					<td><a href="<?php echo esc_url( (string) $row['edit_url'] ); ?>">#<?php echo esc_html( (string) $row['order_number'] ); ?></a></td>
					<td>
						<?php if ( ! empty( $row['customer_edit_url'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $row['customer_edit_url'] ); ?>"><?php echo esc_html( (string) $row['customer_name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( (string) $row['customer_name'] ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( (string) ( $row['marked_label'] ?? $row['product_name'] ?? '' ) ); ?></td>
					<td>
						<span class="vanpos-returns-queue-page__return-date<?php echo $is_overdue ? ' is-overdue' : ''; ?>">
							<?php echo esc_html( $this->format_date( (string) $row['return_raw'] ) ); ?>
						</span>
					</td>
					<td>
						<span class="vanpos-pill vanpos-pill--status <?php echo esc_attr( $this->get_status_class( (string) $row['status'] ) ); ?>">
							<?php echo esc_html( (string) $row['status_label'] ); ?>
						</span>
					</td>
					<?php if ( current_user_can( 'edit_shop_orders' ) ) : ?>
						<td class="vanpos-returns-queue-page__action-cell">
							<?php echo $this->render_mark_returned_button( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
					<?php endif; ?>
				</tr>
				<?php
			endforeach;
		endif;

		return (string) ob_get_clean();
	}

	/**
	 * Mark as returned button.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return string
	 */
	private function render_mark_returned_button( array $row ) {
		$order_id     = (int) $row['order_id'];
		$order_number = (string) $row['order_number'];

		return sprintf(
			'<button type="button" class="button button-primary vanpos-returns-mark-returned" data-vanpos-mark-returned="1" data-order-id="%1$d" data-order-number="%2$s">%3$s</button>',
			$order_id,
			esc_attr( $order_number ),
			esc_html__( 'Mark as returned', 'vanjorn-rental-pos' )
		);
	}

	/**
	 * Pagination controls.
	 *
	 * @param int $page  Current page.
	 * @param int $pages Total pages.
	 * @return string
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

	/**
	 * Format Y-m-d for display.
	 *
	 * @param string $raw Raw date.
	 * @return string
	 */
	private function format_date( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '—';
		}
		$ts = strtotime( $raw );
		if ( ! $ts ) {
			return $raw;
		}
		return date_i18n( get_option( 'date_format' ), $ts );
	}

	/**
	 * Status pill class.
	 *
	 * @param string $status Order status slug.
	 * @return string
	 */
	private function get_status_class( $status ) {
		$status = str_replace( 'wc-', '', (string) $status );
		if ( in_array( $status, array( 'completed', 'processing' ), true ) ) {
			return 'vanpos-status--paid';
		}
		if ( in_array( $status, array( 'pending', 'on-hold' ), true ) ) {
			return 'vanpos-status--pending';
		}
		return 'vanpos-status--neutral';
	}
}
