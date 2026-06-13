<?php
/**
 * VanPOS admin: WooCommerce order list columns/filters.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Admin_Order_List {

	/**
	 * Maps each sortable custom column ID to the order meta key used for ordering.
	 * vanpos_customer_name is handled separately (billing_last_name is a real field).
	 *
	 * @var string[]
	 */
	private static $sortable_meta_keys = array(
		'vanpos_rental_product'   => '_vanpos_camper_name',
		'vanpos_rental_start'     => '_vanpos_pickup_date',
		'vanpos_rental_end'       => '_vanpos_return_date',
		'vanpos_vrc_order_number' => '_vanpos_vrc_order_number',
	);

	public function __construct() {
		// ── Rental summary columns ─────────────────────────────────────────────
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_rental_summary_columns' ), 19 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_rental_summary_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_rental_summary_columns' ), 19 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_rental_summary_column_legacy' ), 10, 2 );

		// ── Order type column ──────────────────────────────────────────────────
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_order_type_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_type_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_type_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_type_column_legacy' ), 10, 2 );

		// ── Due date column ────────────────────────────────────────────────────
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_due_date_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_due_date_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_due_date_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_due_date_column_legacy' ), 10, 2 );

		// ── Replace default order_number with VRC # + Customer ─────────────────
		// Priority 25: runs after the p19/p20 hooks that use order_number as an
		// insertion anchor (rental dates, order type, due date) have already fired,
		// so all downstream columns are already in place before we swap it out.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'replace_order_number_column' ), 25 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'replace_order_number_column' ), 25 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_vrc_and_customer_columns_hpos' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_vrc_and_customer_columns_legacy' ), 10, 2 );

		// ── Sortable columns ───────────────────────────────────────────────────
		add_filter( 'manage_woocommerce_page_wc-orders_sortable_columns', array( $this, 'register_sortable_columns' ) );
		add_filter( 'manage_edit-shop_order_sortable_columns', array( $this, 'register_sortable_columns' ) );
		// Priority 20: runs after the filter meta_query hooks (10/15/16).
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'apply_custom_orderby_hpos' ), 20 );
		add_action( 'pre_get_posts', array( $this, 'apply_custom_orderby_legacy' ), 20 );
		// HPOS SQL-level fallback: woocommerce_orders_table_query_clauses fires inside
		// OrdersTableQuery->build_query() and gives direct control over the ORDER BY clause.
		// This handles WC versions where meta_value orderby is not implemented in the ORM.
		add_filter( 'woocommerce_orders_table_query_clauses', array( $this, 'inject_orderby_sql_hpos' ), 10, 3 );

		// ── Filters / search bar ───────────────────────────────────────────────
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_order_type_filter' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_orders_by_type_hpos' ), 10, 1 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_order_product_filter' ), 11 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_orders_by_product_hpos' ), 15, 1 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_vrc_order_number_filter' ), 12 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_by_vrc_order_number_hpos' ), 16, 1 );

		add_action( 'restrict_manage_posts', array( $this, 'render_order_type_filter_legacy' ), 20 );
		add_action( 'pre_get_posts', array( $this, 'filter_orders_by_type_legacy' ), 20 );
		add_action( 'restrict_manage_posts', array( $this, 'render_order_product_filter_legacy' ), 21 );
		add_action( 'pre_get_posts', array( $this, 'filter_orders_by_product_legacy' ), 25 );
		add_action( 'restrict_manage_posts', array( $this, 'render_vrc_order_number_filter_legacy' ), 22 );
		add_action( 'pre_get_posts', array( $this, 'filter_by_vrc_order_number_legacy' ), 26 );

		// Extend WC's keyword search box to also match _vanpos_vrc_order_number.
		// Legacy only — on HPOS the dedicated text input above handles this.
		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'extend_order_search_fields' ) );

		// Hide unwanted third-party and default WC controls on the orders list screen.
		add_action( 'admin_head', array( $this, 'hide_unwanted_order_filters' ) );
	}

	// =============================================================================
	// Existing: helpers
	// =============================================================================

	private function is_primary_rental_order( $order ) {
		// Delegates to the single source of truth. The logic (type guard, then
		// order-level date/total-price probe, then item-level markers) lives in
		// VanPOS_Order_Manager::is_primary_rental_order(); see the note there.
		return VanPOS_Order_Manager::is_primary_rental_order( $order );
	}

	private function get_rental_summary_source_order( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return null;
		}
		$order_type = $order->get_meta( '_vanpos_order_type' );
		if ( 'payment_order' === $order_type ) {
			$parent_id = (int) $order->get_parent_id();
			if ( ! $parent_id ) {
				$parent_id = (int) $order->get_meta( '_vanpos_primary_order_id' );
			}
			if ( $parent_id ) {
				$parent = wc_get_order( $parent_id );
				if ( $parent ) {
					return $parent;
				}
			}
		}
		return $order;
	}

	private function get_rental_list_product_display( $src ) {
		$out = array( 'label' => '', 'edit_url' => '' );
		if ( ! $src || ! is_a( $src, 'WC_Order' ) ) {
			return $out;
		}
		$label = is_string( $src->get_meta( '_vanpos_camper_name' ) ) ? trim( $src->get_meta( '_vanpos_camper_name' ) ) : '';
		$deposit_pid = (int) VanPOS_Functions::get_setting( 'vanpos_security_deposit_product_id', '' );
		$product_id = 0;
		foreach ( $src->get_items( 'line_item' ) as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$pid = (int) $item->get_variation_id();
			if ( ! $pid ) {
				$pid = (int) $item->get_product_id();
			}
			if ( $pid <= 0 ) {
				continue;
			}
			$orig = VanPOS_Functions::get_original_product_id( $pid );
			if ( $deposit_pid > 0 && $orig === $deposit_pid ) {
				continue;
			}
			if ( '' === $label ) {
				$label = $item->get_name();
			}
			$product_id = $pid;
			break;
		}
		if ( '' === $label && $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$label = $product->get_name();
			}
		}
		$out['label'] = $label;
		if ( $product_id && current_user_can( 'edit_product', $product_id ) ) {
			$link = get_edit_post_link( $product_id, 'raw' );
			if ( $link ) {
				$out['edit_url'] = $link;
			}
		}
		return $out;
	}

	private function is_child_payment_order_settled_after_refund( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) || 'payment_order' !== $order->get_meta( '_vanpos_order_type' ) ) {
			return false;
		}
		if ( ! $order->has_status( 'refunded' ) ) {
			return false;
		}
		if ( $order->get_date_paid() ) {
			return true;
		}
		return ( 'security_deposit' === $order->get_meta( '_vanpos_payment_type' ) && (float) $order->get_total_refunded() > 0 );
	}

	private function get_order_line_search_product_ids( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) { return array(); }
		$product = wc_get_product( $product_id );
		if ( ! $product ) { return array(); }
		$ids = array( $product_id );
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) { $ids[] = (int) $vid; }
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	private function get_order_ids_with_line_item_products( array $product_ids ) {
		global $wpdb;
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
		if ( empty( $product_ids ) ) { return array(); }
		// Use prepare() with %d placeholders even though all values are already
		// run through absint() — this is best practice and future-proofs the query
		// against any upstream changes that might drop the absint() pass.
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$sql = $wpdb->prepare(
			"SELECT DISTINCT oi.order_id
			FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				ON oi.order_item_id = oim.order_item_id
				AND oim.meta_key = '_product_id'
			WHERE oi.order_item_type = 'line_item'
			AND CAST(oim.meta_value AS UNSIGNED) IN ({$placeholders})",
			$product_ids
		);
		$col = $wpdb->get_col( $sql );
		return array_values( array_unique( array_map( 'absint', (array) $col ) ) );
	}

	// =============================================================================
	// Existing: rental summary columns
	// =============================================================================

	public function add_rental_summary_columns( $columns ) {
		if ( isset( $columns['vanpos_rental_product'] ) ) {
			return $columns;
		}
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'order_number' === $key ) {
				$new_columns['vanpos_rental_product'] = __( 'Product', 'vanjorn-rental-pos' );
				$new_columns['vanpos_rental_start']   = __( 'Start date', 'vanjorn-rental-pos' );
				$new_columns['vanpos_rental_end']     = __( 'End date', 'vanjorn-rental-pos' );
			}
		}
		return $new_columns;
	}

	public function render_rental_summary_column_hpos( $column, $order ) {
		if ( in_array( $column, array( 'vanpos_rental_product', 'vanpos_rental_start', 'vanpos_rental_end' ), true ) && $order && is_a( $order, 'WC_Order' ) ) {
			$this->render_rental_summary_column_cell( $column, $order );
		}
	}

	public function render_rental_summary_column_legacy( $column, $order_id ) {
		if ( ! in_array( $column, array( 'vanpos_rental_product', 'vanpos_rental_start', 'vanpos_rental_end' ), true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->render_rental_summary_column_cell( $column, $order );
		}
	}

	private function render_rental_summary_column_cell( $column, $order ) {
		$src = $this->get_rental_summary_source_order( $order );
		if ( ! $src ) {
			echo '<span class="vanpos-orders-list-cell">—</span>';
			return;
		}
		$fmt = get_option( 'date_format' );
		if ( 'vanpos_rental_product' === $column ) {
			$product_row = $this->get_rental_list_product_display( $src );
			if ( '' === $product_row['label'] ) {
				echo '<span class="vanpos-orders-list-cell">—</span>';
				return;
			}
			echo '<span class="vanpos-orders-list-cell">';
			echo $product_row['edit_url'] ? '<a class="vanpos-orders-list-product-link" href="' . esc_url( $product_row['edit_url'] ) . '">' . esc_html( $product_row['label'] ) . '</a>' : esc_html( $product_row['label'] );
			echo '</span>';
			return;
		}
		if ( 'vanpos_rental_start' === $column ) {
			$raw = $src->get_meta( '_vanpos_pickup_date' );
			echo $raw ? '<span class="vanpos-orders-list-cell">' . esc_html( date_i18n( $fmt, strtotime( $raw ) ) ) . '</span>' : '<span class="vanpos-orders-list-cell">—</span>';
			return;
		}
		if ( 'vanpos_rental_end' === $column ) {
			$raw = $src->get_meta( '_vanpos_return_date' );
			echo $raw ? '<span class="vanpos-orders-list-cell">' . esc_html( date_i18n( $fmt, strtotime( $raw ) ) ) . '</span>' : '<span class="vanpos-orders-list-cell">—</span>';
		}
	}

	// =============================================================================
	// Existing: order type column
	// =============================================================================

	public function add_order_type_column( $columns ) {
		if ( isset( $columns['vanpos_order_type'] ) ) {
			return $columns;
		}
		$anchor = isset( $columns['vanpos_rental_end'] ) ? 'vanpos_rental_end' : 'order_number';
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( $anchor === $key ) {
				$new_columns['vanpos_order_type'] = __( 'Order Type', 'vanjorn-rental-pos' );
			}
		}
		return $new_columns;
	}

	public function render_order_type_column( $column, $order ) {
		if ( 'vanpos_order_type' === $column && $order && is_a( $order, 'WC_Order' ) ) {
			$this->render_order_type_badge( $order );
		}
	}

	public function render_order_type_column_legacy( $column, $order_id ) {
		if ( 'vanpos_order_type' !== $column ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->render_order_type_badge( $order );
		}
	}

	private function render_order_type_badge( $order ) {
		$order_type = $order->get_meta( '_vanpos_order_type' );
		if ( 'payment_order' === $order_type ) {
			$parent_id = $order->get_parent_id() ? $order->get_parent_id() : $order->get_meta( '_vanpos_primary_order_id' );
			$parent_order = $parent_id ? wc_get_order( $parent_id ) : null;
			$parent_order_number = $parent_order ? $parent_order->get_order_number() : $parent_id;
			?>
			<mark class="order-status status-child-order" style="background: #f0ad4e; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block;"><?php esc_html_e( 'Child Order', 'vanjorn-rental-pos' ); ?></mark>
			<?php if ( $parent_id && $parent_order ) : ?>
				<br><small style="color: #666; display: block; margin-top: 3px;"><?php printf( esc_html__( 'Parent: %s', 'vanjorn-rental-pos' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $parent_id ) ) . '" style="color: #3858e9;">#' . esc_html( $parent_order_number ) . '</a>' ); ?></small>
			<?php endif; ?>
			<?php
			return;
		}
		if ( $this->is_primary_rental_order( $order ) ) {
			echo '<mark class="order-status status-main-order" style="background: #46b450; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block;">' . esc_html__( 'Main Order', 'vanjorn-rental-pos' ) . '</mark>';
		} else {
			echo '<span style="color: #999;">—</span>';
		}
	}

	// =============================================================================
	// Existing: due date column
	// =============================================================================

	public function add_due_date_column( $columns ) {
		$new_columns = array();
		$inserted = false;
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'vanpos_order_type' === $key ) {
				$new_columns['vanpos_due_date'] = __( 'Due Date', 'vanjorn-rental-pos' );
				$inserted = true;
			}
		}
		if ( ! $inserted ) {
			$new_columns = array();
			foreach ( $columns as $key => $value ) {
				$new_columns[ $key ] = $value;
				if ( 'order_number' === $key ) {
					$new_columns['vanpos_due_date'] = __( 'Due Date', 'vanjorn-rental-pos' );
				}
			}
		}
		return $new_columns;
	}

	public function render_due_date_column( $column, $order ) {
		if ( 'vanpos_due_date' === $column && $order && is_a( $order, 'WC_Order' ) ) {
			$this->render_due_date_badge( $order );
		}
	}

	public function render_due_date_column_legacy( $column, $order_id ) {
		if ( 'vanpos_due_date' !== $column ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->render_due_date_badge( $order );
		}
	}

	private function render_due_date_badge( $order ) {
		$order_type = $order->get_meta( '_vanpos_order_type' );
		$is_child_order = ( 'payment_order' === $order_type );
		$date_paid = $order->get_date_paid();
		$is_paid = $date_paid || $order->has_status( array( 'processing', 'completed' ) ) || $this->is_child_payment_order_settled_after_refund( $order );

		if ( $is_child_order && $is_paid ) {
			// $date_paid can be null even when $is_paid is true (e.g. a refunded
			// security-deposit child settled via is_child_payment_order_settled_after_refund()),
			// and get_date_created() can itself be null on imported/programmatic orders —
			// so guard both before calling ->date_i18n() or one bad row 500s the table.
			$created   = $order->get_date_created();
			$paid_date = $date_paid
				? $date_paid->date_i18n( get_option( 'date_format' ) )
				: ( $created ? $created->date_i18n( get_option( 'date_format' ) ) : '' );
			?>
			<mark class="order-status status-paid" style="background: #46b450; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-bottom: 4px;"><?php esc_html_e( 'Paid', 'vanjorn-rental-pos' ); ?></mark><br>
			<small class="vanpos-due-date-meta" style="color: #666; display: block; margin-top: 2px;">
				<?php if ( $order->has_status( 'refunded' ) ) : ?>
					<?php esc_html_e( 'Refunded — deposit returned', 'vanjorn-rental-pos' ); ?>
				<?php else : ?>
					<?php printf( esc_html__( 'on %s', 'vanjorn-rental-pos' ), esc_html( $paid_date ) ); ?>
				<?php endif; ?>
			</small>
			<?php
			return;
		}

		if ( ! $is_child_order && class_exists( 'VanPOS_Order_Manager' ) ) {
			$child_orders = VanPOS_Order_Manager::get_payment_orders( $order->get_id() );
			if ( ! empty( $child_orders ) ) {
				// The primary order IS the deposit payment, so "Fully Paid" requires the
				// primary's own deposit to be settled too — not just the children. Seed the
				// rollup with the primary's paid flag ($is_paid, computed above) instead of true.
				$all_paid = $is_paid;
				foreach ( $child_orders as $child_order ) {
					$child_date_paid = $child_order->get_date_paid();
					$child_is_paid = $child_date_paid || $child_order->has_status( array( 'processing', 'completed' ) ) || $this->is_child_payment_order_settled_after_refund( $child_order );
					if ( ! $child_is_paid ) {
						$all_paid = false;
						break;
					}
				}
				if ( $all_paid ) {
					echo '<mark class="order-status status-fully-paid" style="background: #46b450; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-bottom: 4px;">' . esc_html__( 'Fully Paid', 'vanjorn-rental-pos' ) . '</mark>';
					return;
				}
			}
		}

		$due_date = $order->get_meta( '_vanpos_due_date' );
		if ( ! $due_date ) {
			echo '<span style="color: #999;">—</span>';
			return;
		}
		$due_date_timestamp = strtotime( $due_date );
		$today = current_time( 'timestamp' );
		$days_until_due = floor( ( $due_date_timestamp - $today ) / DAY_IN_SECONDS );
		$formatted_date = date_i18n( get_option( 'date_format' ), $due_date_timestamp );
		if ( $days_until_due < 0 ) {
			echo '<mark class="order-status status-overdue" style="background: #d63638; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-bottom: 4px;">' . esc_html__( 'Overdue', 'vanjorn-rental-pos' ) . '</mark><br><small class="vanpos-due-date-meta" style="color: #666; display: block; margin-top: 2px;">' . esc_html( $formatted_date ) . '</small>';
		} elseif ( 0 === (int) $days_until_due ) {
			echo '<mark class="order-status status-due-today" style="background: #d63638; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-bottom: 4px;">' . esc_html__( 'Due Today', 'vanjorn-rental-pos' ) . '</mark><br><small class="vanpos-due-date-meta" style="color: #666; display: block; margin-top: 2px;">' . esc_html( $formatted_date ) . '</small>';
		} elseif ( $days_until_due <= 7 ) {
			echo '<mark class="order-status status-due-soon" style="background: #f0ad4e; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-bottom: 4px;">' . esc_html( sprintf( __( '%d days', 'vanjorn-rental-pos' ), $days_until_due ) ) . '</mark><br><small class="vanpos-due-date-meta" style="color: #666; display: block; margin-top: 2px;">' . esc_html( $formatted_date ) . '</small>';
		} else {
			echo '<mark class="order-status status-due-future" style="background: #8c8f94; color: #fff; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; display: inline-block; margin-bottom: 4px;">' . esc_html__( 'Due', 'vanjorn-rental-pos' ) . '</mark><br><small class="vanpos-due-date-meta" style="color: #666; display: block; margin-top: 2px;">' . esc_html( $formatted_date ) . '</small>';
		}
	}

	// =============================================================================
	// Existing: product filter
	// =============================================================================

	public function filter_orders_by_product_hpos( $query_args ) {
		if ( empty( $_GET['vanpos_filter_product_id'] ) ) { return $query_args; }
		$product_id = absint( wp_unslash( $_GET['vanpos_filter_product_id'] ) );
		if ( ! $product_id || ! wc_get_product( $product_id ) ) { return $query_args; }
		$search_ids = $this->get_order_line_search_product_ids( $product_id );
		$order_ids = $this->get_order_ids_with_line_item_products( $search_ids );
		if ( empty( $order_ids ) ) { $query_args['post__in'] = array( 0 ); return $query_args; }
		$existing = array();
		if ( ! empty( $query_args['post__in'] ) ) { $existing = (array) $query_args['post__in']; } elseif ( ! empty( $query_args['includes'] ) ) { $existing = (array) $query_args['includes']; } elseif ( ! empty( $query_args['id'] ) ) { $existing = (array) $query_args['id']; }
		if ( ! empty( $existing ) ) {
			$order_ids = array_values( array_intersect( array_map( 'absint', $existing ), $order_ids ) );
			if ( empty( $order_ids ) ) { $query_args['post__in'] = array( 0 ); unset( $query_args['includes'], $query_args['id'] ); return $query_args; }
		}
		$query_args['post__in'] = $order_ids;
		unset( $query_args['includes'], $query_args['id'] );
		return $query_args;
	}

	public function filter_orders_by_product_legacy( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) || empty( $_GET['vanpos_filter_product_id'] ) ) { return; }
		$product_id = absint( wp_unslash( $_GET['vanpos_filter_product_id'] ) );
		if ( ! $product_id || ! wc_get_product( $product_id ) ) { return; }
		$search_ids = $this->get_order_line_search_product_ids( $product_id );
		$order_ids  = $this->get_order_ids_with_line_item_products( $search_ids );
		if ( empty( $order_ids ) ) { $query->set( 'post__in', array( 0 ) ); return; }
		$existing = $query->get( 'post__in' );
		if ( ! empty( $existing ) ) {
			$order_ids = array_values( array_intersect( array_map( 'absint', (array) $existing ), $order_ids ) );
			if ( empty( $order_ids ) ) { $query->set( 'post__in', array( 0 ) ); return; }
		}
		$query->set( 'post__in', $order_ids );
	}

	public function render_order_product_filter() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) { return; }
		$selected = isset( $_GET['vanpos_filter_product_id'] ) ? absint( wp_unslash( $_GET['vanpos_filter_product_id'] ) ) : 0;
		$products = class_exists( 'VanPOS_Functions' ) ? VanPOS_Functions::get_rental_products() : array();
		if ( ! empty( $products ) ) {
			usort( $products, function ( $a, $b ) { return strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) ); } );
		}
		?>
		<select name="vanpos_filter_product_id" id="vanpos-orders-product-filter" class="vanpos-orders-product-filter" style="margin-left: 10px; max-width: 220px;">
			<option value=""><?php esc_html_e( 'All rental products', 'vanjorn-rental-pos' ); ?></option>
			<?php foreach ( $products as $p ) : $pid = isset( $p['id'] ) ? absint( $p['id'] ) : 0; if ( ! $pid ) { continue; } ?>
				<option value="<?php echo esc_attr( (string) $pid ); ?>" <?php selected( $selected, $pid ); ?>><?php echo esc_html( (string) ( $p['name'] ?? '#' . $pid ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_order_product_filter_legacy( $post_type ) {
		if ( 'shop_order' === $post_type ) {
			$this->render_order_product_filter();
		}
	}

	// =============================================================================
	// Existing: order type filter
	// =============================================================================

	public function render_order_type_filter() {
		$selected = isset( $_GET['vanpos_order_type_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['vanpos_order_type_filter'] ) ) : '';
		?>
		<select name="vanpos_order_type_filter" id="vanpos-order-type-filter" style="margin-left: 10px;">
			<option value=""><?php esc_html_e( 'All Order Types', 'vanjorn-rental-pos' ); ?></option>
			<option value="rental_order" <?php selected( $selected, 'rental_order' ); ?>><?php esc_html_e( 'Main Order', 'vanjorn-rental-pos' ); ?></option>
			<option value="security_deposit" <?php selected( $selected, 'security_deposit' ); ?>><?php esc_html_e( 'Security Deposit', 'vanjorn-rental-pos' ); ?></option>
			<option value="remaining_payment" <?php selected( $selected, 'remaining_payment' ); ?>><?php esc_html_e( 'Remaining Payment', 'vanjorn-rental-pos' ); ?></option>
		</select>
		<?php
	}

	public function filter_orders_by_type_hpos( $query_args ) {
		if ( ! isset( $_GET['vanpos_order_type_filter'] ) || empty( $_GET['vanpos_order_type_filter'] ) ) { return $query_args; }
		$filter_type = sanitize_text_field( wp_unslash( $_GET['vanpos_order_type_filter'] ) );
		if ( ! isset( $query_args['meta_query'] ) ) { $query_args['meta_query'] = array(); }
		switch ( $filter_type ) {
			case 'security_deposit':
				$query_args['meta_query'][] = array( 'key' => '_vanpos_payment_type', 'value' => array( 'deposit', 'security_deposit' ), 'compare' => 'IN' );
				break;
			case 'remaining_payment':
				$query_args['meta_query'][] = array( 'key' => '_vanpos_payment_type', 'value' => VanPOS_Order_Manager::remaining_payment_types(), 'compare' => 'IN' );
				break;
			case 'rental_order':
				$query_args['meta_query'][] = array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array( 'key' => '_vanpos_order_type', 'value' => 'primary_rental', 'compare' => '=' ),
						array( 'key' => '_vanpos_deposits_order_has_deposit', 'value' => 'yes', 'compare' => '=' ),
						array( 'key' => '_vanpos_pickup_date', 'compare' => 'EXISTS' ),
					),
					array( 'key' => '_vanpos_payment_type', 'compare' => 'NOT EXISTS' ),
				);
				break;
		}
		return $query_args;
	}

	public function render_order_type_filter_legacy( $post_type ) {
		if ( 'shop_order' !== $post_type ) { return; }
		$selected = isset( $_GET['vanpos_order_type_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['vanpos_order_type_filter'] ) ) : '';
		?>
		<select name="vanpos_order_type_filter" id="vanpos-order-type-filter" style="margin-left: 10px;">
			<option value=""><?php esc_html_e( 'All Order Types', 'vanjorn-rental-pos' ); ?></option>
			<option value="rental_order" <?php selected( $selected, 'rental_order' ); ?>><?php esc_html_e( 'Main Order', 'vanjorn-rental-pos' ); ?></option>
			<option value="security_deposit" <?php selected( $selected, 'security_deposit' ); ?>><?php esc_html_e( 'Security Deposit', 'vanjorn-rental-pos' ); ?></option>
			<option value="remaining_payment" <?php selected( $selected, 'remaining_payment' ); ?>><?php esc_html_e( 'Remaining Payment', 'vanjorn-rental-pos' ); ?></option>
		</select>
		<?php
	}

	public function filter_orders_by_type_legacy( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) { return; }
		if ( ! isset( $_GET['vanpos_order_type_filter'] ) || empty( $_GET['vanpos_order_type_filter'] ) ) { return; }
		$filter_type = sanitize_text_field( wp_unslash( $_GET['vanpos_order_type_filter'] ) );
		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) { $meta_query = array(); }
		switch ( $filter_type ) {
			case 'security_deposit':
				$meta_query[] = array( 'key' => '_vanpos_payment_type', 'value' => array( 'deposit', 'security_deposit' ), 'compare' => 'IN' );
				break;
			case 'remaining_payment':
				$meta_query[] = array( 'key' => '_vanpos_payment_type', 'value' => VanPOS_Order_Manager::remaining_payment_types(), 'compare' => 'IN' );
				break;
			case 'rental_order':
				$meta_query[] = array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array( 'key' => '_vanpos_order_type', 'value' => 'primary_rental', 'compare' => '=' ),
						array( 'key' => '_vanpos_deposits_order_has_deposit', 'value' => 'yes', 'compare' => '=' ),
						array( 'key' => '_vanpos_pickup_date', 'compare' => 'EXISTS' ),
					),
					array( 'key' => '_vanpos_payment_type', 'compare' => 'NOT EXISTS' ),
				);
				break;
		}
		$query->set( 'meta_query', $meta_query );
	}

	// =============================================================================
	// NEW: Replace default order_number column with VRC # + Customer
	// =============================================================================

	/**
	 * Remove WC's default order_number column and replace it with vanpos_vrc_order_number
	 * and vanpos_customer_name.
	 *
	 * Runs at priority 25, after the p19/p20 hooks that used order_number as an
	 * insertion anchor (rental dates, order type, due date) have already fired.
	 * At that point order_number is still in the array; we swap it out here.
	 */
	public function replace_order_number_column( $columns ) {
		if ( isset( $columns['vanpos_vrc_order_number'] ) ) {
			return $columns; // Idempotency guard.
		}
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'order_number' === $key ) {
				$new['vanpos_vrc_order_number'] = __( 'Order number', 'vanjorn-rental-pos' );
				$new['vanpos_customer_name']    = __( 'Customer', 'vanjorn-rental-pos' );
				continue; // Drop the WC default.
			}
			$new[ $key ] = $label;
		}
		return $new;
	}

	/** HPOS render hook for the two new columns. */
	public function render_vrc_and_customer_columns_hpos( $column, $order ) {
		if ( in_array( $column, array( 'vanpos_vrc_order_number', 'vanpos_customer_name' ), true ) && $order && is_a( $order, 'WC_Order' ) ) {
			$this->render_vrc_or_customer_cell( $column, $order );
		}
	}

	/** Legacy render hook for the two new columns. */
	public function render_vrc_and_customer_columns_legacy( $column, $order_id ) {
		if ( ! in_array( $column, array( 'vanpos_vrc_order_number', 'vanpos_customer_name' ), true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->render_vrc_or_customer_cell( $column, $order );
		}
	}

	/**
	 * Shared cell renderer for vanpos_vrc_order_number and vanpos_customer_name.
	 *
	 * VRC order #: shows the VRC number as an edit link; falls back to the WC order
	 *              number when _vanpos_vrc_order_number is not set.
	 * Customer:    billing first + last name linked to the user profile (when the
	 *              customer has an account), with email as secondary text.
	 */
	private function render_vrc_or_customer_cell( $column, $order ) {
		if ( 'vanpos_vrc_order_number' === $column ) {
			$vrc_number = (string) $order->get_meta( '_vanpos_vrc_order_number' );
			$edit_url   = $order->get_edit_order_url();
			echo '<span class="vanpos-orders-list-cell">';
			if ( '' !== $vrc_number ) {
				echo '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $vrc_number ) . '</a></strong>';
			} else {
				// No VRC number yet — show the WC order number so the cell is never blank.
				echo '<strong><a href="' . esc_url( $edit_url ) . '">#' . esc_html( $order->get_order_number() ) . '</a></strong>';
			}
			echo '</span>';
			return;
		}

		if ( 'vanpos_customer_name' === $column ) {
			$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$email   = $order->get_billing_email();
			$cust_id = $order->get_customer_id();
			echo '<span class="vanpos-orders-list-cell">';
			if ( '' !== $name ) {
				if ( $cust_id && current_user_can( 'edit_users' ) ) {
					echo '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $cust_id ) ) . '">' . esc_html( $name ) . '</a>';
				} else {
					echo esc_html( $name );
				}
				if ( $email ) {
					echo '<br><small style="color:#666;">' . esc_html( $email ) . '</small>';
				}
			} else {
				echo '<span style="color:#999;">—</span>';
			}
			echo '</span>';
		}
	}

	// =============================================================================
	// NEW: Sortable columns
	// =============================================================================

	/**
	 * Register custom columns as sortable.
	 *
	 * The array value matches the column key so WordPress sends ?orderby=<key> in
	 * the URL. apply_custom_orderby_hpos / apply_custom_orderby_legacy then
	 * translate that key into the real meta_key or billing field for the query.
	 */
	public function register_sortable_columns( $columns ) {
		foreach ( array_keys( self::$sortable_meta_keys ) as $col_id ) {
			$columns[ $col_id ] = $col_id;
		}
		$columns['vanpos_customer_name'] = 'vanpos_customer_name';
		return $columns;
	}

	/**
	 * Translate a custom column orderby into HPOS (wc_get_orders) query args.
	 *
	 * Reads from $_GET rather than $query_args because WC HPOS validates the
	 * orderby parameter against its own whitelist before this filter runs,
	 * replacing unrecognised column IDs with 'date'. Reading $_GET gives us
	 * the original value the user clicked.
	 *
	 * inject_orderby_sql_hpos() below is the more reliable fallback for WC
	 * versions where meta_value orderby is not implemented in OrdersTableQuery.
	 */
	public function apply_custom_orderby_hpos( $query_args ) {
		if ( empty( $_GET['orderby'] ) ) {
			return $query_args;
		}
		$orderby = sanitize_key( wp_unslash( $_GET['orderby'] ) );
		if ( ! $orderby ) {
			return $query_args;
		}
		$order = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'ASC';
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'ASC';
		}

		if ( isset( self::$sortable_meta_keys[ $orderby ] ) ) {
			$query_args['meta_key'] = self::$sortable_meta_keys[ $orderby ];
			// VRC order numbers are formatted like "15-A", "149-A": their numeric prefix
			// must sort numerically, not lexicographically (else "15" > "149").
			// Date strings (Y-m-d) also sort correctly as meta_value_num would cast to
			// the year only, but since inject_orderby_sql_hpos overrides the ORDER BY
			// clause anyway this is just a best-effort hint for ORM-level handling.
			$query_args['orderby'] = ( 'vanpos_vrc_order_number' === $orderby ) ? 'meta_value_num' : 'meta_value';
			$query_args['order']   = $order;
		} elseif ( 'vanpos_customer_name' === $orderby ) {
			// billing_last_name is a first-class field in the HPOS addresses table.
			$query_args['orderby'] = 'billing_last_name';
			$query_args['order']   = $order;
		}

		return $query_args;
	}

	/**
	 * Translate a custom column orderby into WP_Query args (legacy orders table).
	 */
	public function apply_custom_orderby_legacy( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( isset( self::$sortable_meta_keys[ $orderby ] ) ) {
			$query->set( 'meta_key', self::$sortable_meta_keys[ $orderby ] );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( 'vanpos_customer_name' === $orderby ) {
			$query->set( 'meta_key', '_billing_last_name' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Inject ORDER BY SQL directly into the HPOS OrdersTableQuery clauses.
	 *
	 * This is the reliable fallback for WC versions where OrdersTableQuery does
	 * not implement meta_value ordering. It fires inside build_query() after WC
	 * has already built its own ORDER BY, so we overwrite it.
	 *
	 * Fires for every wc_get_orders() call when HPOS is enabled, so the guard
	 * on is_admin() + $_GET['orderby'] limits it to actual column-sort requests
	 * from the orders list screen.
	 *
	 * The HPOS orders table has no alias in its FROM clause; WC references it
	 * by its full prefixed name (wp_wc_orders) in JOIN conditions, and community
	 * examples of this filter confirm the same pattern.
	 */
	public function inject_orderby_sql_hpos( $clauses, $query_obj, $query_vars ) {
		global $wpdb;

		if ( ! is_admin() || empty( $_GET['orderby'] ) ) {
			return $clauses;
		}

		$col = sanitize_key( wp_unslash( $_GET['orderby'] ) );
		if ( ! isset( self::$sortable_meta_keys[ $col ] ) ) {
			// vanpos_customer_name is handled at the args level (billing_last_name
			// is a first-class HPOS field); no SQL injection needed for it.
			return $clauses;
		}

		$dir      = ( ! empty( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'DESC' : 'ASC';
		$meta_key = self::$sortable_meta_keys[ $col ];

		$orders_table = $wpdb->prefix . 'wc_orders';
		$meta_table   = $wpdb->prefix . 'wc_orders_meta';

		// Add a LEFT JOIN for the sort meta key (unique alias avoids collision).
		$clauses['join'] = ( isset( $clauses['join'] ) ? $clauses['join'] : '' ) . $wpdb->prepare(
			" LEFT JOIN `{$meta_table}` `vanpos_sort` ON `vanpos_sort`.order_id = `{$orders_table}`.id AND `vanpos_sort`.meta_key = %s",
			$meta_key
		);
		// VRC order numbers like "15-A" and "149-A" must sort by their numeric prefix
		// so that 15 < 149. CAST(… AS UNSIGNED) strips the suffix and returns the
		// leading integer. A string tiebreak on the full value follows for equal prefixes.
		// Y-m-d date fields sort correctly as plain strings (ISO format is lexicographic).
		if ( 'vanpos_vrc_order_number' === $col ) {
			$clauses['orderby'] = 'CAST(`vanpos_sort`.meta_value AS UNSIGNED) ' . $dir . ', `vanpos_sort`.meta_value ' . $dir;
		} else {
			$clauses['orderby'] = '`vanpos_sort`.meta_value ' . $dir;
		}

		return $clauses;
	}

	// =============================================================================
	// NEW: VRC order number search filter
	// =============================================================================

	/**
	 * Render a text input in the filter bar for searching by VRC order number.
	 * Uses type="search" so browsers show a clear button and submit on Enter.
	 */
	public function render_vrc_order_number_filter() {
		$value = isset( $_GET['vanpos_filter_vrc_number'] )
			? sanitize_text_field( wp_unslash( $_GET['vanpos_filter_vrc_number'] ) )
			: '';
		?>
		<input
			type="search"
			name="vanpos_filter_vrc_number"
			id="vanpos-filter-vrc-number"
			placeholder="<?php esc_attr_e( 'Order number', 'vanjorn-rental-pos' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			style="margin-left:10px; max-width:140px;"
		>
		<?php
	}

	/** Wrapper for the legacy restrict_manage_posts context. */
	public function render_vrc_order_number_filter_legacy( $post_type ) {
		if ( 'shop_order' === $post_type ) {
			$this->render_vrc_order_number_filter();
		}
	}

	/**
	 * Apply the VRC order number filter — HPOS.
	 * Uses LIKE so a partial number (e.g. "1234") still matches.
	 */
	public function filter_by_vrc_order_number_hpos( $query_args ) {
		if ( empty( $_GET['vanpos_filter_vrc_number'] ) ) {
			return $query_args;
		}
		$val = sanitize_text_field( wp_unslash( $_GET['vanpos_filter_vrc_number'] ) );
		if ( '' === $val ) {
			return $query_args;
		}
		if ( ! isset( $query_args['meta_query'] ) ) {
			$query_args['meta_query'] = array();
		}
		$query_args['meta_query'][] = array(
			'key'     => '_vanpos_vrc_order_number',
			'value'   => $val,
			'compare' => 'LIKE',
		);
		return $query_args;
	}

	/** Apply the VRC order number filter — legacy WP_Query. */
	public function filter_by_vrc_order_number_legacy( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( empty( $_GET['vanpos_filter_vrc_number'] ) ) {
			return;
		}
		$val = sanitize_text_field( wp_unslash( $_GET['vanpos_filter_vrc_number'] ) );
		if ( '' === $val ) {
			return;
		}
		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}
		$meta_query[] = array(
			'key'     => '_vanpos_vrc_order_number',
			'value'   => $val,
			'compare' => 'LIKE',
		);
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Add _vanpos_vrc_order_number to WC's built-in keyword search (legacy only).
	 *
	 * When a shop manager types in the search box, WC performs a meta search across
	 * each key returned by this filter. On HPOS the dedicated text input above is
	 * used instead, since the HPOS search pipeline does not use this filter.
	 *
	 * @param string[] $search_fields Meta keys WC will include in its keyword search.
	 * @return string[]
	 */
	public function extend_order_search_fields( $search_fields ) {
		$search_fields[] = '_vanpos_vrc_order_number';
		return $search_fields;
	}

	/**
	 * Output targeted CSS on the WC orders list screen to suppress unwanted controls.
	 *
	 * Hides:
	 *  - WC's built-in "Created via" channel filter (select[name="_created_via"])
	 *  - The third-party WC Rental Products rental filter (wcrp_rental_products_rentals_filter)
	 *  - The default WC keyword search box (p.search-box)
	 *
	 * Using CSS rather than hooks because these elements are rendered deep inside WC's
	 * and third-party list table code with no clean removal hook.
	 */
	public function hide_unwanted_order_filters() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			return;
		}
		?>
		<style>
			/* WC "Created via" channel filter */
			select[name="_created_via"] { display: none !important; }
			/* WC Rental Products rental filter */
			select[name="wcrp_rental_products_rentals_filter"] { display: none !important; }
			/* Default WC keyword search box */
			p.search-box { display: none !important; }
		</style>
		<?php
	}
}
