<?php
/**
 * Query service for the VAN-Jorn “Returns to process” admin page.
 *
 * Lists main rental bookings whose return date is on or before today and are
 * not yet marked returned (Kestrel-compatible), looking backward from today.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds normalized rows for the returns queue table.
 */
class VanPOS_Admin_Returns_Queue_Query {

	/**
	 * Default look-back window (must match returns queue page + menu badge).
	 */
	const DEFAULT_LOOKBACK = '30days';

	/**
	 * Order statuses excluded from the returns queue.
	 *
	 * @var string[]
	 */
	private static $excluded_statuses = array(
		'trash',
		'cancelled',
		'failed',
		'refunded',
		'draft',
		'auto-draft',
	);

	/**
	 * Count rentals waiting to be marked returned (menu badge).
	 *
	 * @param string $lookback Lookback key (default {@see DEFAULT_LOOKBACK}).
	 * @return int
	 */
	public static function count_pending( $lookback = null ) {
		if ( null === $lookback ) {
			$lookback = self::DEFAULT_LOOKBACK;
		}
		$result = self::get_result(
			array(
				'lookback' => $lookback,
				'overdue'  => false,
				'search'   => '',
				'limit'    => 1,
				'page'     => 1,
			),
			true
		);
		return (int) $result['total'];
	}

	/**
	 * Fetch rows + pagination for the returns queue.
	 *
	 * @param array $filters {
	 *   @type string $lookback 7days|30days|90days|all.
	 *   @type bool   $overdue  Only return date before today.
	 *   @type string $search   Free text.
	 *   @type int    $limit    Per page.
	 *   @type int    $page     1-based page.
	 * }
	 * @param bool $count_only When true, skip slicing rows (faster total for badge).
	 * @return array{rows:array<int,array<string,mixed>>, total:int, pages:int, page:int}
	 */
	public static function get_result( array $filters, $count_only = false ) {
		$window = self::resolve_lookback_window( $filters );
		$search = isset( $filters['search'] ) ? (string) $filters['search'] : '';
		$overdue_only = ! empty( $filters['overdue'] );

		$from_date = gmdate( 'Y-m-d', $window['from'] );
		$to_date   = gmdate( 'Y-m-d', $window['to'] );

		$args = array(
			'limit'      => 2000, // DB is now date-bounded; 2000 is a safe ceiling for any rental window.
			'orderby'    => 'date',
			'order'      => 'DESC',
			'status'     => self::get_allowed_statuses(),
			'return'     => 'objects',
			'meta_query' => array(
				'relation' => 'AND',
				// Restrict to the resolved lookback window at DB level so orders with
				// return dates in the window are never missed (fixes issue #14).
				array(
					'key'     => '_vanpos_return_date',
					'value'   => array( $from_date, $to_date ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
				array(
					'key'     => '_vanpos_payment_type',
					'compare' => 'NOT EXISTS',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => '_vanpos_order_type',
						'value'   => 'payment_order',
						'compare' => '!=',
					),
					array(
						'key'     => '_vanpos_order_type',
						'compare' => 'NOT EXISTS',
					),
				),
			),
		);

		$orders = wc_get_orders( $args );
		$rows   = array();
		$today_start = (int) strtotime( gmdate( 'Y-m-d', current_time( 'timestamp' ) ) . ' 00:00:00' );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			if ( ! class_exists( 'VanPOS_Order_Deletion' ) || ! VanPOS_Order_Deletion::is_primary_rental_order( $order ) ) {
				continue;
			}

			if ( class_exists( 'VanPOS_Rental_Returned' ) && VanPOS_Rental_Returned::is_order_rental_returned( $order ) ) {
				continue;
			}

			$row = VanPOS_Admin_Dashboard_Overview_Query::build_row( $order );
			if ( empty( $row['return_ts'] ) ) {
				continue;
			}

			$return_ts = (int) $row['return_ts'];
			if ( $return_ts > $window['to'] || $return_ts < $window['from'] ) {
				continue;
			}

			$row['is_overdue']    = $return_ts < $today_start;
			$row['marked_label']  = self::get_marked_label( $order );
			$row['is_main_booking_row'] = true;

			if ( $overdue_only && ! $row['is_overdue'] ) {
				continue;
			}

			if ( ! self::match_search( $row, $search ) ) {
				continue;
			}

			$rows[] = $row;
		}

		$rows = self::sort_rows( $rows );

		$total = count( $rows );
		$limit = max( 1, (int) ( $filters['limit'] ?? 50 ) );
		$page  = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$pages = max( 1, (int) ceil( $total / $limit ) );
		if ( $page > $pages ) {
			$page = $pages;
		}

		if ( $count_only ) {
			return array(
				'rows'  => array(),
				'total' => $total,
				'pages' => $pages,
				'page'  => $page,
			);
		}

		$offset = ( $page - 1 ) * $limit;

		return array(
			'rows'  => array_slice( $rows, $offset, $limit ),
			'total' => $total,
			'pages' => $pages,
			'page'  => $page,
		);
	}

	/**
	 * Lookback window: from (older) through end of today.
	 *
	 * @param array $filters Filters.
	 * @return array{from:int,to:int}
	 */
	public static function resolve_lookback_window( array $filters ) {
		$today     = current_time( 'timestamp' );
		$to        = (int) strtotime( gmdate( 'Y-m-d', $today ) . ' 23:59:59' );
		$lookback  = isset( $filters['lookback'] ) ? (string) $filters['lookback'] : self::DEFAULT_LOOKBACK;
		$from      = (int) strtotime( gmdate( 'Y-m-d', $today ) . ' 00:00:00' );

		switch ( $lookback ) {
			case '7days':
				$from = strtotime( '-7 days', $from );
				break;
			case '30days':
				$from = strtotime( '-30 days', $from );
				break;
			case '90days':
				$from = strtotime( '-90 days', $from );
				break;
			case 'all':
				$from = strtotime( '-10 years', $from );
				break;
			default:
				$from = strtotime( '-30 days', $from );
				break;
		}

		return array(
			'from' => (int) $from,
			'to'   => $to,
		);
	}

	/**
	 * Primary rental line label (Kestrel-style “Marked” column).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function get_marked_label( WC_Order $order ) {
		$item_id = class_exists( 'VanPOS_Rental_Returned' )
			? VanPOS_Rental_Returned::get_primary_rental_line_item_id( $order )
			: 0;

		if ( $item_id > 0 ) {
			$item = new WC_Order_Item_Product( $item_id );
			if ( $item->get_id() ) {
				$qty = max( 1, (int) $item->get_quantity() );
				return sprintf(
					/* translators: 1: quantity, 2: product name */
					__( '%1$d × %2$s', 'vanjorn-rental-pos' ),
					$qty,
					$item->get_name()
				);
			}
		}

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
				$qty = max( 1, (int) $item->get_quantity() );
				return sprintf(
					__( '%1$d × %2$s', 'vanjorn-rental-pos' ),
					$qty,
					$item->get_name()
				);
			}
		}

		return '';
	}

	/**
	 * Allowed WooCommerce order statuses.
	 *
	 * @return string[]
	 */
	private static function get_allowed_statuses() {
		$all = array_keys( wc_get_order_statuses() );
		$out = array();

		foreach ( $all as $status ) {
			$slug = str_replace( 'wc-', '', $status );
			if ( in_array( $slug, self::$excluded_statuses, true ) ) {
				continue;
			}
			$out[] = $status;
		}

		return $out;
	}

	/**
	 * Search order #, customer, van, marked label.
	 *
	 * @param array<string,mixed> $row   Row.
	 * @param string              $query Search.
	 * @return bool
	 */
	private static function match_search( array $row, $query ) {
		$query = strtolower( trim( (string) $query ) );
		if ( '' === $query ) {
			return true;
		}

		$haystack = strtolower(
			implode(
				' ',
				array(
					(string) ( $row['order_number'] ?? '' ),
					(string) ( $row['product_name'] ?? '' ),
					(string) ( $row['customer_name'] ?? '' ),
					(string) ( $row['marked_label'] ?? '' ),
					(string) ( $row['return_raw'] ?? '' ),
				)
			)
		);

		return false !== strpos( $haystack, $query );
	}

	/**
	 * Sort by return date descending (most overdue / recent first).
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sort_rows( array $rows ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				$av = (int) ( $a['return_ts'] ?? 0 );
				$bv = (int) ( $b['return_ts'] ?? 0 );
				if ( $av === $bv ) {
					return (int) ( $b['order_id'] ?? 0 ) <=> (int) ( $a['order_id'] ?? 0 );
				}
				return $bv <=> $av;
			}
		);

		return $rows;
	}
}
