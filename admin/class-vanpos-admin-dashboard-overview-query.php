<?php
/**
 * Query service for the wp-admin VanPOS rentals overview widget.
 *
 * Keeps data lookup separate from HTML rendering so filters/sorting rules stay
 * testable and reusable.
 *
 * Performance model
 * -----------------
 * The original implementation drove filtering through WP_Meta_Query, which on the
 * "all" view generated FOUR self-joins of the orders-meta table (one per date key
 * plus one for the meta sort) with the meta_key predicates in the WHERE and an OR
 * across three aliases. That row-multiplication made a ~37k-row table take ~47s.
 *
 * The HPOS path below instead pivots the relevant meta into one row per order in a
 * single grouped subquery, then joins ONCE. Filtering, sorting and pagination all
 * happen in that one query; only the resulting <= PER_PAGE orders are hydrated.
 *
 * The legacy wc_get_orders()/meta_query path is retained ONLY as the CPT fallback
 * for sites not using HPOS.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds normalized rental rows for dashboard display.
 */
class VanPOS_Admin_Dashboard_Overview_Query {

	/** Rows per page (fixed; no longer a UI control). */
	const PER_PAGE = 25;

	/** Upper bound on rows hydrated for the PHP text-search fallback. */
	const SEARCH_HYDRATE_LIMIT = 500;

	// =========================================================================
	// Public entry points
	// =========================================================================

	/**
	 * Fetch dashboard rows from orders based on widget filters.
	 *
	 * @param array $filters See get_result().
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_rows( array $filters ) {
		$result = self::get_result( $filters );
		return $result['rows'];
	}

	/**
	 * Fetch rows + pagination metadata.
	 *
	 * @param array $filters {
	 *     @type string $view       pickups|returns|due|all.
	 *     @type string $range      today|3days|7days|30days.
	 *     @type int    $product_id Product or variation ID (optional).
	 *     @type int    $page       Page number (1-based).
	 *     @type string $search     Search order/product/customer/VRC number.
	 *     @type string $sort_by    pickup|return|due|created.
	 *     @type string $sort_dir   asc|desc.
	 *     @type string $order_type all|main|security_deposit|remaining_payment|payment_other.
	 * }
	 * @return array{rows:array<int,array<string,mixed>>, total:int, pages:int, page:int}
	 */
	public static function get_result( array $filters ) {
		$per_page   = self::PER_PAGE;
		$page       = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$view       = isset( $filters['view'] ) ? (string) $filters['view'] : 'all';
		$order_type = isset( $filters['order_type'] ) ? (string) $filters['order_type'] : 'all';
		$product_id = (int) ( $filters['product_id'] ?? 0 );
		$search     = trim( (string) ( $filters['search'] ?? '' ) );
		$sort_by    = (string) ( $filters['sort_by'] ?? 'pickup' );
		$sort_dir   = (string) ( $filters['sort_dir'] ?? 'asc' );

		$window = self::resolve_date_window( $filters );

		// Product filter → pre-fetch matching order IDs via SQL.
		// null = no restriction; [] = product known but no orders → zero results.
		$include_ids = null;
		if ( $product_id > 0 ) {
			$include_ids = self::get_order_ids_for_product( $product_id );
			if ( is_array( $include_ids ) && empty( $include_ids ) ) {
				return array( 'rows' => array(), 'total' => 0, 'pages' => 1, 'page' => 1 );
			}
		}

		$ctx = array(
			'window'      => $window,
			'view'        => $view,
			'order_type'  => $order_type,
			'sort_by'     => $sort_by,
			'sort_dir'    => $sort_dir,
			'include_ids' => $include_ids,
		);

		if ( self::hpos_enabled() ) {
			return ( '' === $search )
				? self::result_hpos_no_search( $ctx, $page, $per_page )
				: self::result_hpos_search( $ctx, $page, $per_page, $search );
		}

		// CPT fallback (legacy, slower meta_query path).
		return self::result_cpt_fallback( $ctx, $page, $per_page, $search );
	}

	// =========================================================================
	// Fast HPOS path
	// =========================================================================

	/**
	 * No-search HPOS path: one count query + one paginated ID query.
	 *
	 * @param array $ctx      Query context.
	 * @param int   $page     Requested page.
	 * @param int   $per_page Rows per page.
	 * @return array{rows:array,total:int,pages:int,page:int}
	 */
	private static function result_hpos_no_search( array $ctx, $page, $per_page ) {
		$offset = ( $page - 1 ) * $per_page;
		$res    = self::query_filtered_order_ids( $ctx, $per_page, $offset );

		$total = (int) $res['total'];
		$pages = max( 1, (int) ceil( $total / $per_page ) );

		// Requested page past the end → clamp and re-query once.
		if ( $page > $pages ) {
			$page   = $pages;
			$offset = ( $page - 1 ) * $per_page;
			$res    = self::query_filtered_order_ids( $ctx, $per_page, $offset );
		}

		$rows = self::hydrate_rows( $res['ids'], true );

		return array( 'rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page );
	}

	/**
	 * Text-search HPOS path: fetch the full (capped) filtered ID set, hydrate,
	 * match in PHP, paginate in PHP. The date/type/product filter has already
	 * trimmed the set, so the cap is a safety bound, not the working size.
	 *
	 * @param array  $ctx      Query context.
	 * @param int    $page     Requested page.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Free-text term.
	 * @return array{rows:array,total:int,pages:int,page:int}
	 */
	private static function result_hpos_search( array $ctx, $page, $per_page, $search ) {
		$res = self::query_filtered_order_ids( $ctx, self::SEARCH_HYDRATE_LIMIT, 0 );

		$matched = array();
		foreach ( $res['ids'] as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$row = self::build_row( $order, false ); // Skip rental-returned for discarded rows.
			if ( empty( $row ) ) {
				continue;
			}
			if ( ! self::match_search_filter( $row, $search ) ) {
				continue;
			}
			$matched[] = $row;
		}

		$total = count( $matched );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( max( 1, $page ), $pages );
		$slice = array_slice( $matched, ( $page - 1 ) * $per_page, $per_page );

		// Enrich only the rows we are returning.
		$rows = array();
		foreach ( $slice as $row ) {
			$order = wc_get_order( $row['order_id'] );
			if ( $order instanceof WC_Order ) {
				self::enrich_rental_returned( $row, $order );
			}
			$rows[] = $row;
		}

		return array( 'rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page );
	}

	/**
	 * Resolve filtered + sorted + paginated order IDs in a single query.
	 *
	 * Strategy: a grouped subquery pivots the five relevant meta keys into one
	 * row per order (no fan-out), then a single join to the orders table applies
	 * the status/type/date/order-type filters, sorts, and paginates.
	 *
	 * @param array $ctx    Query context (window/view/order_type/sort/include_ids).
	 * @param int   $limit  Max rows.
	 * @param int   $offset Offset.
	 * @return array{ids:int[], total:int}
	 */
	private static function query_filtered_order_ids( array $ctx, $limit, $offset ) {
		global $wpdb;

		$orders_table = "{$wpdb->prefix}wc_orders";
		$meta_table   = "{$wpdb->prefix}wc_orders_meta";

		$from_date = gmdate( 'Y-m-d', $ctx['window']['from'] );
		$to_date   = gmdate( 'Y-m-d', $ctx['window']['to'] );

		// --- Pivot subquery (meta keys are code constants, safe to inline) ----
		$pivot = "
			SELECT order_id,
				MAX(CASE WHEN meta_key = '_vanpos_pickup_date'  THEN meta_value END) AS pickup,
				MAX(CASE WHEN meta_key = '_vanpos_return_date'  THEN meta_value END) AS ret,
				MAX(CASE WHEN meta_key = '_vanpos_due_date'     THEN meta_value END) AS due,
				MAX(CASE WHEN meta_key = '_vanpos_payment_type' THEN meta_value END) AS pay_type,
				MAX(CASE WHEN meta_key = '_vanpos_order_type'   THEN meta_value END) AS order_type_meta
			FROM {$meta_table}
			WHERE meta_key IN ('_vanpos_pickup_date','_vanpos_return_date','_vanpos_due_date','_vanpos_payment_type','_vanpos_order_type')
			GROUP BY order_id
		";

		$params = array();

		// Status filter (wc-prefixed keys, as stored in the HPOS status column).
		$statuses     = array_keys( wc_get_order_statuses() );
		$status_place = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		foreach ( $statuses as $status ) {
			$params[] = $status;
		}

		// View date condition.
		switch ( $ctx['view'] ) {
			case 'pickups':
				$view_cond = 'px.pickup BETWEEN %s AND %s';
				$params[]  = $from_date;
				$params[]  = $to_date;
				break;
			case 'returns':
				$view_cond = 'px.ret BETWEEN %s AND %s';
				$params[]  = $from_date;
				$params[]  = $to_date;
				break;
			case 'due':
				$view_cond = 'px.due BETWEEN %s AND %s';
				$params[]  = $from_date;
				$params[]  = $to_date;
				break;
			case 'all':
			default:
				$view_cond = '( px.pickup BETWEEN %s AND %s OR px.ret BETWEEN %s AND %s OR px.due BETWEEN %s AND %s )';
				array_push( $params, $from_date, $to_date, $from_date, $to_date, $from_date, $to_date );
				break;
		}

		// Order-type condition (literals are code constants; NULL == "key absent").
		$type_cond = '1=1';
		switch ( $ctx['order_type'] ) {
			case 'main':
				$type_cond = "( px.pay_type IS NULL OR px.pay_type = '' ) AND ( px.order_type_meta IS NULL OR px.order_type_meta <> 'payment_order' )";
				break;
			case 'security_deposit':
				// 'initial' is the upfront rental payment percentage, not the refundable
				// security deposit. Including it here would pull initial-payment child
				// orders into the Security Deposit tab once 'initial' is written as a live
				// child-order type (see: deposit→initial type retirement).
				$type_cond = "px.pay_type IN ('security_deposit')";
				break;
			case 'remaining_payment':
				$type_cond = "px.pay_type IN ('" . implode( "','", VanPOS_Order_Manager::remaining_payment_types() ) . "')";
				break;
			case 'payment_other':
				$type_cond = "px.order_type_meta = 'payment_order' AND ( px.pay_type IS NULL OR px.pay_type = '' OR px.pay_type NOT IN ('" . implode( "','", VanPOS_Order_Manager::payment_order_types() ) . "') )";
				break;
		}

		// Product include filter.
		$include_cond = '';
		if ( is_array( $ctx['include_ids'] ) ) {
			// Empty array is handled before this method runs (zero results).
			$place        = implode( ',', array_fill( 0, count( $ctx['include_ids'] ), '%d' ) );
			$include_cond = " AND o.id IN ({$place})";
			foreach ( $ctx['include_ids'] as $iid ) {
				$params[] = (int) $iid;
			}
		}

		$base = "
			FROM {$orders_table} o
			INNER JOIN ( {$pivot} ) px ON px.order_id = o.id
			WHERE o.type = 'shop_order'
			AND o.status IN ({$status_place})
			AND ( {$view_cond} )
			AND ( {$type_cond} ){$include_cond}
		";

		// --- Count -----------------------------------------------------------
		$count_sql = "SELECT COUNT(*) {$base}";
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		// --- Sort + paginate -------------------------------------------------
		$dir = ( 'asc' === strtolower( (string) $ctx['sort_dir'] ) ) ? 'ASC' : 'DESC';
		switch ( $ctx['sort_by'] ) {
			case 'return':
				$order_col = 'px.ret';
				break;
			case 'due':
				$order_col = 'px.due';
				break;
			case 'created':
				$order_col = 'o.date_created_gmt';
				break;
			case 'pickup':
			default:
				$order_col = 'px.pickup';
				break;
		}

		$select_sql      = "SELECT o.id {$base} ORDER BY {$order_col} {$dir}, o.id {$dir} LIMIT %d OFFSET %d";
		$select_params   = $params;
		$select_params[] = (int) max( 0, $limit );
		$select_params[] = (int) max( 0, $offset );

		$ids = $wpdb->get_col( $wpdb->prepare( $select_sql, $select_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ids = array_values( array_map( 'absint', (array) $ids ) );

		return array( 'ids' => $ids, 'total' => $total );
	}

	/**
	 * Hydrate a list of order IDs into normalized rows, preserving order.
	 *
	 * @param int[] $ids           Ordered order IDs.
	 * @param bool  $with_returned Compute rental-returned status eagerly.
	 * @return array<int,array<string,mixed>>
	 */
	private static function hydrate_rows( array $ids, $with_returned ) {
		$rows = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$row = self::build_row( $order, $with_returned );
			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Whether HPOS (custom orders table) is the active store.
	 *
	 * @return bool
	 */
	private static function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	// =========================================================================
	// CPT fallback path (legacy meta_query; only for non-HPOS sites)
	// =========================================================================

	/**
	 * Legacy path using wc_get_orders() + meta_query. Slower (generates meta
	 * self-joins) but functional on classic CPT order storage.
	 *
	 * @param array  $ctx      Query context.
	 * @param int    $page     Requested page.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Free-text term.
	 * @return array{rows:array,total:int,pages:int,page:int}
	 */
	private static function result_cpt_fallback( array $ctx, $page, $per_page, $search ) {
		$from_date = gmdate( 'Y-m-d', $ctx['window']['from'] );
		$to_date   = gmdate( 'Y-m-d', $ctx['window']['to'] );

		$meta_query = array(
			'relation' => 'AND',
			self::build_view_date_meta_clause( $ctx['view'], $from_date, $to_date ),
		);
		foreach ( self::build_order_type_meta_clauses( $ctx['order_type'] ) as $clause ) {
			$meta_query[] = $clause;
		}

		$base_args = array(
			'status'     => array_keys( wc_get_order_statuses() ),
			'return'     => 'objects',
			'meta_query' => $meta_query,
		);
		if ( is_array( $ctx['include_ids'] ) ) {
			$base_args['include'] = $ctx['include_ids'];
		}

		$sort_args = self::build_db_sort_args( $ctx['sort_by'], $ctx['sort_dir'] );

		if ( '' === $search ) {
			$query_args = array_merge(
				$base_args,
				$sort_args,
				array(
					'paginate' => true,
					'limit'    => $per_page,
					'offset'   => ( $page - 1 ) * $per_page,
				)
			);

			$res   = wc_get_orders( $query_args );
			$total = (int) $res->total;
			$pages = max( 1, (int) $res->max_num_pages );

			if ( $page > $pages ) {
				$page                 = $pages;
				$query_args['offset'] = ( $page - 1 ) * $per_page;
				$res                  = wc_get_orders( $query_args );
			}

			$rows = array();
			foreach ( $res->orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				$row = self::build_row( $order );
				if ( ! empty( $row ) ) {
					$rows[] = $row;
				}
			}

			return array( 'rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page );
		}

		$orders = wc_get_orders(
			array_merge( $base_args, $sort_args, array( 'limit' => self::SEARCH_HYDRATE_LIMIT ) )
		);

		$matched = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$row = self::build_row( $order, false );
			if ( empty( $row ) || ! self::match_search_filter( $row, $search ) ) {
				continue;
			}
			$matched[] = array(
				'row'   => $row,
				'order' => $order,
			);
		}

		$total = count( $matched );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( max( 1, $page ), $pages );
		$slice = array_slice( $matched, ( $page - 1 ) * $per_page, $per_page );

		$rows = array();
		foreach ( $slice as $pair ) {
			$row = $pair['row'];
			self::enrich_rental_returned( $row, $pair['order'] );
			$rows[] = $row;
		}

		return array( 'rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page );
	}

	/**
	 * Build meta_query date clause (CPT fallback only).
	 */
	private static function build_view_date_meta_clause( $view, $from_date, $to_date ) {
		$between = static function ( $key ) use ( $from_date, $to_date ) {
			return array(
				'key'     => $key,
				'value'   => array( $from_date, $to_date ),
				'compare' => 'BETWEEN',
			);
		};

		switch ( $view ) {
			case 'pickups':
				return $between( '_vanpos_pickup_date' );
			case 'returns':
				return $between( '_vanpos_return_date' );
			case 'due':
				return $between( '_vanpos_due_date' );
			case 'all':
			default:
				return array(
					'relation' => 'OR',
					$between( '_vanpos_pickup_date' ),
					$between( '_vanpos_return_date' ),
					$between( '_vanpos_due_date' ),
				);
		}
	}

	/**
	 * Build meta_query order-type clauses (CPT fallback only).
	 *
	 * @param string $order_type Filter key.
	 * @return array[]
	 */
	private static function build_order_type_meta_clauses( $order_type ) {
		switch ( $order_type ) {
			case 'main':
				return array(
					array( 'key' => '_vanpos_payment_type', 'compare' => 'NOT EXISTS' ),
					array(
						'relation' => 'OR',
						array( 'key' => '_vanpos_order_type', 'value' => 'payment_order', 'compare' => '!=' ),
						array( 'key' => '_vanpos_order_type', 'compare' => 'NOT EXISTS' ),
					),
				);
			case 'security_deposit':
				return array(
					array(
						'key'     => '_vanpos_payment_type',
						// 'initial' removed — same reasoning as the HPOS path above.
					'value'   => array( 'security_deposit' ),
						'compare' => 'IN',
					),
				);
			case 'remaining_payment':
				return array(
					array(
						'key'     => '_vanpos_payment_type',
						'value'   => VanPOS_Order_Manager::remaining_payment_types(),
						'compare' => 'IN',
					),
				);
			case 'payment_other':
				return array(
					array( 'key' => '_vanpos_order_type', 'value' => 'payment_order', 'compare' => '=' ),
					array(
						'relation' => 'OR',
						array( 'key' => '_vanpos_payment_type', 'compare' => 'NOT EXISTS' ),
						array(
							'key'     => '_vanpos_payment_type',
							'value'   => VanPOS_Order_Manager::payment_order_types(),
							'compare' => 'NOT IN',
						),
					),
				);
			case 'all':
			default:
				return array();
		}
	}

	/**
	 * Translate sort_by/sort_dir to wc_get_orders() args (CPT fallback only).
	 */
	private static function build_db_sort_args( $sort_by, $sort_dir ) {
		$dir = ( 'asc' === strtolower( (string) $sort_dir ) ) ? 'ASC' : 'DESC';

		switch ( $sort_by ) {
			case 'return':
				return array( 'meta_key' => '_vanpos_return_date', 'orderby' => 'meta_value', 'order' => $dir );
			case 'due':
				return array( 'meta_key' => '_vanpos_due_date', 'orderby' => 'meta_value', 'order' => $dir );
			case 'created':
				return array( 'orderby' => 'date', 'order' => $dir );
			case 'pickup':
			default:
				return array( 'meta_key' => '_vanpos_pickup_date', 'orderby' => 'meta_value', 'order' => $dir );
		}
	}

	// =========================================================================
	// Product order-ID resolver (shared by both paths)
	// =========================================================================

	/**
	 * Pre-fetch order IDs that contain a given product/variation in a line item.
	 * Returns null when product does not exist (= skip the include filter).
	 * Returns [] when product exists but has no matching orders (= zero results).
	 *
	 * @param int $product_id Product or variation ID.
	 * @return int[]|null
	 */
	private static function get_order_ids_for_product( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}

		$ids = array( $product_id );
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$ids[] = (int) $child_id;
			}
		}
		if ( class_exists( 'VanPOS_Functions' ) ) {
			$orig = (int) VanPOS_Functions::get_original_product_id( $product_id );
			if ( $orig > 0 && $orig !== $product_id ) {
				$ids[] = $orig;
				$p2 = wc_get_product( $orig );
				if ( $p2 && $p2->is_type( 'variable' ) ) {
					foreach ( $p2->get_children() as $child_id ) {
						$ids[] = (int) $child_id;
					}
				}
			}
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = $wpdb->prepare(
			"SELECT DISTINCT oi.order_id
			FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				ON oi.order_item_id = oim.order_item_id
				AND oim.meta_key = '_product_id'
			WHERE oi.order_item_type = 'line_item'
			AND CAST(oim.meta_value AS UNSIGNED) IN ({$placeholders})",
			$ids
		);
		$result = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_values( array_map( 'absint', (array) $result ) );
	}

	// =========================================================================
	// Row builder
	// =========================================================================

	/**
	 * Build one normalized row from order/meta data.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $with_returned When true (default), compute rental-returned
	 *                                status eagerly. Bulk/match paths pass false.
	 * @return array<string, mixed>
	 */
	public static function build_row( WC_Order $order, $with_returned = true ) {
		$pickup_raw = (string) $order->get_meta( '_vanpos_pickup_date' );
		$return_raw = (string) $order->get_meta( '_vanpos_return_date' );
		$due_raw    = (string) $order->get_meta( '_vanpos_due_date' );
		$order_type = (string) $order->get_meta( '_vanpos_order_type' );
		$pay_type   = (string) $order->get_meta( '_vanpos_payment_type' );

		if ( '' === $pickup_raw || '' === $return_raw ) {
			foreach ( $order->get_items() as $item ) {
				if ( '' === $pickup_raw ) {
					$pickup_raw = (string) $item->get_meta( 'vanpos_pickup_date' );
					if ( '' === $pickup_raw ) {
						$pickup_raw = (string) $item->get_meta( 'wcrp_rental_products_rent_from' );
					}
				}
				if ( '' === $return_raw ) {
					$return_raw = (string) $item->get_meta( 'vanpos_return_date' );
					if ( '' === $return_raw ) {
						$return_raw = (string) $item->get_meta( 'wcrp_rental_products_rent_to' );
					}
				}
				if ( '' !== $pickup_raw && '' !== $return_raw ) {
					break;
				}
			}
		}

		$pickup_ts = self::safe_date_to_ts( $pickup_raw );
		$return_ts = self::safe_date_to_ts( $return_raw );
		$due_ts    = self::safe_date_to_ts( $due_raw );

		$product  = self::get_primary_product_data( $order );
		$customer = self::get_customer_data( $order );

		$type_key   = 'main';
		$type_label = __( 'Main Order', 'vanjorn-rental-pos' );
		if ( 'payment_order' === $order_type || '' !== $pay_type ) {
			// 'initial' removed from the security_deposit bucket — see filter comments
			// above. An 'initial' pay_type falls through to payment_other (label
			// auto-derived as "Initial"), keeping it distinct from the refundable deposit.
			if ( in_array( $pay_type, array( 'security_deposit' ), true ) ) {
				$type_key   = 'security_deposit';
				$type_label = __( 'Security Deposit', 'vanjorn-rental-pos' );
			} elseif ( VanPOS_Order_Manager::is_remaining_payment( $pay_type ) ) {
				$type_key   = 'remaining_payment';
				$type_label = __( 'Remaining Payment', 'vanjorn-rental-pos' );
			} elseif ( '' !== $pay_type ) {
				$type_key   = 'payment_other';
				$type_label = ucfirst( str_replace( '_', ' ', $pay_type ) );
			} else {
				$type_key   = 'payment_other';
				$type_label = __( 'Payment Order', 'vanjorn-rental-pos' );
			}
		}

		$is_main_booking_row = ! ( 'payment_order' === $order_type || '' !== $pay_type );

		$rental_line_item_id = 0;
		$is_rental_returned  = false;
		if ( $with_returned && $is_main_booking_row && class_exists( 'VanPOS_Rental_Returned' ) ) {
			$rental_line_item_id = VanPOS_Rental_Returned::get_primary_rental_line_item_id( $order );
			$is_rental_returned  = VanPOS_Rental_Returned::is_order_rental_returned( $order );
		}

		return array(
			'order_id'             => $order->get_id(),
			'is_main_booking_row'  => $is_main_booking_row,
			'rental_line_item_id'  => $rental_line_item_id,
			'is_rental_returned'   => $is_rental_returned,
			'order_number'         => $order->get_order_number(),
			'vrc_order_number'     => (string) $order->get_meta( '_vanpos_vrc_order_number' ),
			'status'               => $order->get_status(),
			'status_label'         => wc_get_order_status_name( $order->get_status() ),
			'order_type'           => $type_label,
			'order_type_key'       => $type_key,
			'pickup_raw'           => $pickup_raw,
			'return_raw'           => $return_raw,
			'due_raw'              => $due_raw,
			'pickup_ts'            => $pickup_ts,
			'return_ts'            => $return_ts,
			'due_ts'               => $due_ts,
			'created_ts'           => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
			'product_id'           => $product['product_id'],
			'product_name'         => $product['product_name'],
			'product_type'         => $product['product_type'],
			'product_edit_url'     => $product['edit_url'],
			'customer_name'        => $customer['name'],
			'customer_edit_url'    => $customer['edit_url'],
			'total_html'           => $order->get_formatted_order_total(),
			'edit_url'             => self::get_admin_order_edit_url( $order ),
		);
	}

	/**
	 * Fill in deferred rental-returned fields on an already-built row.
	 *
	 * @param array<string,mixed> $row   Row built with build_row( $order, false ).
	 * @param WC_Order            $order Source order.
	 * @return void
	 */
	private static function enrich_rental_returned( array &$row, WC_Order $order ) {
		if ( empty( $row['is_main_booking_row'] ) || ! class_exists( 'VanPOS_Rental_Returned' ) ) {
			return;
		}
		$row['rental_line_item_id'] = VanPOS_Rental_Returned::get_primary_rental_line_item_id( $order );
		$row['is_rental_returned']  = (bool) VanPOS_Rental_Returned::is_order_rental_returned( $order );
	}

	// =========================================================================
	// Product / customer data helpers
	// =========================================================================

	/**
	 * Fetch primary line item product data.
	 *
	 * @param WC_Order $order Order.
	 * @return array{product_id:int, product_name:string, product_type:string, edit_url:string}
	 */
	private static function get_primary_product_data( WC_Order $order ) {
		$out = array(
			'product_id'   => 0,
			'product_name' => '',
			'product_type' => '',
			'edit_url'     => '',
		);

		foreach ( $order->get_items( 'line_item' ) as $item ) {
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
			$out['product_id']   = $pid;
			$out['product_name'] = $item->get_name();
			$out['edit_url']     = admin_url( 'post.php?post=' . $pid . '&action=edit' );
			if ( class_exists( 'VanPOS_Functions' ) ) {
				$out['product_type'] = (string) VanPOS_Functions::get_product_type( $pid );
			}
			break;
		}

		return $out;
	}

	/**
	 * Extract customer display/link data.
	 *
	 * @param WC_Order $order Order.
	 * @return array{name:string, edit_url:string}
	 */
	private static function get_customer_data( WC_Order $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		if ( '' === $name ) {
			$name = (string) $order->get_billing_email();
		}

		$user_id = (int) $order->get_customer_id();
		$url     = '';
		if ( $user_id > 0 ) {
			$url = admin_url( 'user-edit.php?user_id=' . $user_id );
		}

		return array(
			'name'     => '' !== $name ? $name : __( 'Guest', 'vanjorn-rental-pos' ),
			'edit_url' => $url,
		);
	}

	// =========================================================================
	// PHP-side search match (used by both search paths)
	// =========================================================================

	/**
	 * Check free-text search against key row columns including VRC order number.
	 *
	 * @param array<string,mixed> $row   Row.
	 * @param string              $query Search term.
	 * @return bool
	 */
	private static function match_search_filter( array $row, $query ) {
		$query = strtolower( trim( (string) $query ) );
		if ( '' === $query ) {
			return true;
		}

		$haystack = strtolower(
			implode(
				' ',
				array(
					(string) ( $row['order_number']     ?? '' ),
					(string) ( $row['vrc_order_number'] ?? '' ),
					(string) ( $row['product_name']     ?? '' ),
					(string) ( $row['product_type']     ?? '' ),
					(string) ( $row['customer_name']    ?? '' ),
					(string) ( $row['status_label']     ?? '' ),
					(string) ( $row['order_type']       ?? '' ),
				)
			)
		);

		return false !== strpos( $haystack, $query );
	}

	// =========================================================================
	// Date window resolver
	// =========================================================================

	/**
	 * Resolve filter range to [from, to] timestamps.
	 *
	 * @param array $filters Filters.
	 * @return array{from:int,to:int}
	 */
	public static function resolve_date_window( array $filters ) {
		// wp_date() returns the date in the site's configured timezone without
		// the deprecated current_time('timestamp') + gmdate() anti-pattern that
		// was used here previously (current_time('timestamp') is deprecated since WP 5.3).
		$today_date = wp_date( 'Y-m-d' );
		$start      = strtotime( $today_date . ' 00:00:00' );
		$end        = strtotime( $today_date . ' 23:59:59' );

		switch ( $filters['range'] ?? 'today' ) {
			case '3days':
				$end = strtotime( '+3 days', $end );
				break;
			case '7days':
				$end = strtotime( '+7 days', $end );
				break;
			case '30days':
				$end = strtotime( '+30 days', $end );
				break;
			case 'all':
				// Wide window so every booking (past and far-future) is listed.
				$start = strtotime( '-10 years', $start );
				$end   = strtotime( '+10 years', $end );
				break;
			case 'today':
			default:
				break;
		}

		return array( 'from' => $start, 'to' => $end );
	}

	/**
	 * Convert Y-m-d date to timestamp.
	 *
	 * @param string $date         Raw date.
	 * @param bool   $start_of_day Start-of-day when true, end-of-day when false.
	 * @return int
	 */
	private static function safe_date_to_ts( $date, $start_of_day = true ) {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return 0;
		}
		$raw = $date . ( $start_of_day ? ' 00:00:00' : ' 23:59:59' );
		$ts  = strtotime( $raw );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * WooCommerce admin edit URL (HPOS or CPT).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function get_admin_order_edit_url( WC_Order $order ) {
		$order_id = (int) $order->get_id();
		if ( $order_id <= 0 ) {
			return '';
		}
		if ( self::hpos_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}
		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}
}
