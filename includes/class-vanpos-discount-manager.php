<?php
/**
 * VanPOS Discount Manager
 *
 * Keeps the plugin's own financial meta in sync when a WooCommerce discount
 * (coupon) is applied to — or removed from — an order in the admin order editor.
 *
 * WooCommerce only adjusts its native line/discount/total figures when a coupon
 * changes; it has no knowledge of the VanPOS `_vanpos_*` payment-split meta that
 * the dashboard, customer account, child orders and AutomateWoo templates read.
 * Without this handler those values stay frozen at their pre-discount state, so a
 * discount is invisible everywhere except WooCommerce's own totals.
 *
 * Design (confirmed with stakeholder):
 *  - Parent (primary_rental), remaining child and security-deposit child orders are
 *    discounted SEPARATELY. A coupon on one never propagates a re-split to another.
 *  - Each order role recomputes ONLY its own bucket of meta from WooCommerce's
 *    post-discount figures.
 *  - The spanning keys (`_vanpos_total_price` + formatted, item `_vanpos_original_price`)
 *    are kept coherent with the discounted bucket.
 *  - When a remaining child is discounted, the parent's copies of the remaining /
 *    total meta are synced so the parent view stays accurate.
 *  - This never re-derives price from the product catalogue, so a manually
 *    overridden nightly rate (`_vanpos_price_overridden`) is preserved — we read
 *    WooCommerce's already-calculated totals, which include any override.
 *
 * @package VanjornRentalPOS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Discount_Manager {

	/**
	 * Re-entrancy guard so our own saves never re-trigger the handler.
	 *
	 * @var bool
	 */
	private static $is_syncing = false;

	/**
	 * When true, the handler does nothing. Set by VanPOS code that calls
	 * WC_Order::calculate_totals() as part of *building* or *forcing* an
	 * order's totals (e.g. the child-order factories), so that internal
	 * recalculation is never mistaken for an admin coupon apply/remove.
	 *
	 * @var bool
	 */
	public static $suspended = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Fires on every calculate_totals(), which WooCommerce calls on both coupon
		// apply and coupon remove in the order editor. Late priority so WooCommerce
		// has finished distributing the discount across line items first.
		add_action( 'woocommerce_order_after_calculate_totals', array( __CLASS__, 'sync_discount_meta' ), 99, 2 );
	}

	/**
	 * Recompute the VanPOS payment-split meta for the order's role.
	 *
	 * @param bool     $and_taxes Whether taxes were recalculated (unused).
	 * @param WC_Order $order     The order being recalculated.
	 * @return void
	 */
	public static function sync_discount_meta( $and_taxes, $order ) {
		// Suspended while VanPOS code recalculates an order it is building/forcing.
		if ( self::$suspended ) {
			return;
		}
		// Only act in the admin order editor. This deliberately excludes frontend
		// checkout (including the block checkout), where order creation sets this
		// meta itself and where get_total() may not yet reflect the deposit override.
		if ( ! is_admin() ) {
			return;
		}
		if ( self::$is_syncing ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// A persisted order with an ID — never a brand-new object mid-construction.
		if ( ! $order->get_id() ) {
			return;
		}

		$order_type   = (string) $order->get_meta( '_vanpos_order_type' );
		$payment_type = (string) $order->get_meta( '_vanpos_payment_type' );

		self::$is_syncing = true;
		try {
			self::dispatch( $order, $order_type, $payment_type );
		} finally {
			self::$is_syncing = false;
		}
	}

	/**
	 * Recompute the VanPOS payment-split meta for an order on demand, outside the
	 * coupon-recalculation hook. Used by the meta backfill tool to reconcile orders
	 * that were discounted before this handler existed (so their stored split meta
	 * is pre-discount and stale).
	 *
	 * Runs the IDENTICAL per-role logic as the live hook — there is no separate
	 * recompute path, so backfill and live behaviour cannot drift. Honours the
	 * re-entrancy guard but deliberately bypasses the is_admin()/hook-context gates,
	 * since the caller controls when this runs.
	 *
	 * @param WC_Order $order Order to reconcile (parent or child).
	 * @return bool True if changes were actually applied, false otherwise.
	 */
	public static function reconcile( $order ) {
		if ( self::$is_syncing ) {
			return false;
		}
		if ( ! $order instanceof WC_Order || ! $order->get_id() ) {
			return false;
		}

		// Nothing diverges → nothing to do. This is the single divergence check;
		// callers do not need to call preview()/is_stale() first as a guard.
		if ( empty( self::preview( $order ) ) ) {
			return false;
		}

		$order_type   = (string) $order->get_meta( '_vanpos_order_type' );
		$payment_type = (string) $order->get_meta( '_vanpos_payment_type' );

		self::$is_syncing = true;
		try {
			self::dispatch( $order, $order_type, $payment_type );
			return true;
		} finally {
			self::$is_syncing = false;
		}
	}

	/**
	 * Route an order to the correct per-role recompute. Shared by the live hook
	 * (sync_discount_meta) and the on-demand entry point (reconcile).
	 *
	 * @param WC_Order $order        Order to process.
	 * @param string   $order_type   _vanpos_order_type meta.
	 * @param string   $payment_type _vanpos_payment_type meta.
	 * @return bool True if a role matched and was processed.
	 */
	private static function dispatch( $order, $order_type, $payment_type ) {
		if ( 'primary_rental' === $order_type ) {
			self::sync_primary( $order );
			return true;
		} elseif ( 'remaining' === $payment_type || 'second_payment' === $payment_type ) {
			self::sync_remaining( $order );
			return true;
		} elseif ( 'security_deposit' === $payment_type ) {
			self::sync_security_deposit( $order );
			return true;
		}
		return false;
	}

	/**
	 * Primary rental order: a coupon here discounts the INITIAL payment only.
	 *
	 * The primary order's payable total IS the initial payment, so the post-discount
	 * order total is the new initial payment. The remaining payment (a separate child
	 * order) is left untouched; the spanning total is re-derived as initial + remaining.
	 *
	 * @param WC_Order $order Primary rental order.
	 * @return void
	 */
	private static function sync_primary( $order ) {
		// Creation-safety: only act on orders that already carry the bucket meta.
		$existing_initial = $order->get_meta( '_vanpos_initial_payment' );
		if ( '' === $existing_initial || false === $existing_initial ) {
			return;
		}

		$new_initial = (float) $order->get_total();
		$remaining   = (float) $order->get_meta( '_vanpos_remaining_payment' );
		$new_total   = $new_initial + $remaining;

		$order->update_meta_data( '_vanpos_initial_payment', $new_initial );
		$order->update_meta_data( '_vanpos_initial_payment_formatted', self::money( $new_initial ) );
		$order->update_meta_data( '_vanpos_total_price', $new_total );
		$order->update_meta_data( '_vanpos_total_price_formatted', self::money( $new_total ) );

		// Fees (cleaning, dog) are €100 each, loaded entirely onto the initial
		// payment, and are NOT part of the item line (which is van-only).
		$fee_total = ( '1' === (string) $order->get_meta( '_vanpos_include_cleaning' ) ? 100 : 0 )
			+ ( '1' === (string) $order->get_meta( '_vanpos_include_dog' ) ? 100 : 0 );

		// Item-level keys track the VAN only (fees live at order level):
		//   _vanpos_deposit_amount   = initial payment − fees
		//   _vanpos_remaining_amount = remaining payment
		//   _vanpos_original_price   = van deposit + van remaining (= total − fees)
		$van_deposit   = $new_initial - $fee_total;
		// A negative remaining is an over-refund artefact at order level and must never
		// appear in item-level fields. Clamp to zero so remaining_amount stays non-negative
		// and original_price is computed correctly (van_deposit + 0, not van_deposit − refund).
		$van_remaining = max( 0.0, $remaining );
		$van_original  = $van_deposit + $van_remaining;
		foreach ( $order->get_items() as $item ) {
			if ( '' === (string) $item->get_meta( '_vanpos_original_price' ) ) {
				continue;
			}
			if ( abs( (float) $item->get_meta( '_vanpos_deposit_amount' ) - $van_deposit ) <= 0.01
				&& abs( (float) $item->get_meta( '_vanpos_original_price' ) - $van_original ) <= 0.01
				&& abs( (float) $item->get_meta( '_vanpos_remaining_amount' ) - $van_remaining ) <= 0.01 ) {
				continue;
			}
			$item->update_meta_data( '_vanpos_deposit_amount', $van_deposit );
			$item->update_meta_data( '_vanpos_original_price', $van_original );
			$item->update_meta_data( '_vanpos_remaining_amount', $van_remaining );
		}

		$order->save();
	}

	/**
	 * Remaining payment child order: a coupon here discounts the REMAINING payment
	 * only. The child's own meta is updated and the parent's copies of the remaining
	 * and total meta are synced so the parent view stays accurate.
	 *
	 * @param WC_Order $order Remaining payment child order.
	 * @return void
	 */
	private static function sync_remaining( $order ) {
		$new_remaining = (float) $order->get_total();

		// --- Child's own meta ---
		$order->update_meta_data( '_vanpos_remaining_payment', $new_remaining );
		$order->update_meta_data( '_vanpos_remaining_payment_formatted', self::money( $new_remaining ) );
		// Item _vanpos_remaining_amount mirrors the child's own remaining payment.
		foreach ( $order->get_items() as $item ) {
			if ( '' === (string) $item->get_meta( '_vanpos_remaining_amount' ) ) {
				continue;
			}
			if ( abs( (float) $item->get_meta( '_vanpos_remaining_amount' ) - $new_remaining ) > 0.01 ) {
				$item->update_meta_data( '_vanpos_remaining_amount', $new_remaining );
			}
		}
		$order->save();

		// --- Sync the parent ---
		$parent = self::get_parent( $order );
		if ( ! $parent ) {
			return;
		}

		$parent_initial = (float) $parent->get_meta( '_vanpos_initial_payment' );
		$parent_total   = $parent_initial + $new_remaining;

		$parent->update_meta_data( '_vanpos_remaining_payment', $new_remaining );
		$parent->update_meta_data( '_vanpos_remaining_payment_formatted', self::money( $new_remaining ) );
		$parent->update_meta_data( '_vanpos_total_price', $parent_total );
		$parent->update_meta_data( '_vanpos_total_price_formatted', self::money( $parent_total ) );

		// Keep the parent's rental-line remaining amount(s) in sync. Distribute the
		// new remaining across lines proportionally to their current share so multi
		// line orders stay coherent; fall back to an even split if shares are absent.
		self::distribute_to_items( $parent, '_vanpos_remaining_amount', $new_remaining );

		$parent->save();
	}

	/**
	 * Security deposit child order: a coupon here discounts the SECURITY DEPOSIT only.
	 * Fully isolated from the rental split; the parent's record of the security-deposit
	 * amount is kept in sync for display.
	 *
	 * @param WC_Order $order Security deposit child order.
	 * @return void
	 */
	private static function sync_security_deposit( $order ) {
		$new_sd = (float) $order->get_total();

		$order->update_meta_data( '_vanpos_security_deposit_payment', $new_sd );
		$order->update_meta_data( '_vanpos_security_deposit_payment_formatted', self::money( $new_sd ) );
		$order->save();

		$parent = self::get_parent( $order );
		if ( ! $parent ) {
			return;
		}
		$parent->update_meta_data( '_vanpos_security_deposit_payment', $new_sd );
		$parent->update_meta_data( '_vanpos_security_deposit_payment_formatted', self::money( $new_sd ) );
		$parent->save();
	}

	/**
	 * Resolve a child order's primary/parent order.
	 *
	 * @param WC_Order $order Child order.
	 * @return WC_Order|null
	 */
	private static function get_parent( $order ) {
		$parent_id = $order->get_parent_id();
		if ( ! $parent_id ) {
			$parent_id = (int) $order->get_meta( '_vanpos_primary_order_id' );
		}
		if ( ! $parent_id ) {
			return null;
		}
		$parent = wc_get_order( $parent_id );
		return $parent instanceof WC_Order ? $parent : null;
	}

	/**
	 * Distribute a total across an order's items that carry a given amount meta,
	 * proportionally to each item's current share of that meta. Falls back to an
	 * even split when no existing shares are present.
	 *
	 * @param WC_Order $order     Order whose items to update.
	 * @param string   $meta_key  Item meta key to write.
	 * @param float    $new_total New total to distribute.
	 * @return void
	 */
	private static function distribute_to_items( $order, $meta_key, $new_total ) {
		$targets = array();
		$old_sum = 0.0;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( '' === (string) $item->get_meta( $meta_key ) ) {
				continue;
			}
			$current            = (float) $item->get_meta( $meta_key );
			$targets[ $item_id ] = $current;
			$old_sum            += $current;
		}

		$count = count( $targets );
		if ( 0 === $count ) {
			return;
		}

		$items     = $order->get_items();
		$allocated = 0.0;
		$index     = 0;
		foreach ( $targets as $item_id => $current ) {
			$index++;
			if ( $index === $count ) {
				// Last item absorbs the rounding remainder so the parts sum exactly.
				$share = $new_total - $allocated;
			} elseif ( $old_sum > 0 ) {
				$share = round( $new_total * ( $current / $old_sum ), wc_get_price_decimals() );
			} else {
				$share = round( $new_total / $count, wc_get_price_decimals() );
			}
			$allocated += $share;
			if ( isset( $items[ $item_id ] ) ) {
				$items[ $item_id ]->update_meta_data( $meta_key, $share );
			}
		}
	}

	/**
	 * Read-only preview of the order-level payment-split changes that reconcile()
	 * would make, WITHOUT mutating anything. Compares each bucket's stored amount
	 * against the order's actual WooCommerce total (the source of truth for what
	 * was charged on that order) and returns a list of pending changes.
	 *
	 * This is deliberately NOT coupon-gated: it catches any divergence, whether
	 * caused by a pre-handler coupon OR by an order created with a wrong total
	 * (e.g. an importer that stored the net item subtotal instead of the gross
	 * order total). For a correctly-created order, stored == get_total() and the
	 * result is empty.
	 *
	 * Item-level meta (per-line deposit / remaining) is reconciled by reconcile()
	 * but not enumerated here; this preview summarises the order-level financial
	 * keys, which are the user-facing figures.
	 *
	 * @param WC_Order $order     Order to inspect.
	 * @param float    $tolerance Allowed divergence before reporting (default 0.01).
	 * @return array[] Each entry: array( 'scope', 'order_id', 'key', 'old', 'new' )
	 *                 where 'scope' is 'this' or 'parent', old/new are formatted money.
	 */
	public static function preview( $order, $tolerance = 0.01 ) {
		$changes = array();
		if ( ! $order instanceof WC_Order || ! $order->get_id() ) {
			return $changes;
		}

		$order_type   = (string) $order->get_meta( '_vanpos_order_type' );
		$payment_type = (string) $order->get_meta( '_vanpos_payment_type' );
		$wc_total     = (float) $order->get_total();

		if ( 'primary_rental' === $order_type ) {
			if ( ! $order->meta_exists( '_vanpos_initial_payment' ) ) {
				return $changes;
			}
			$old_initial = (float) $order->get_meta( '_vanpos_initial_payment' );
			$remaining   = (float) $order->get_meta( '_vanpos_remaining_payment' );
			$new_initial = $wc_total;
			$old_total   = (float) $order->get_meta( '_vanpos_total_price' );
			$new_total   = $new_initial + $remaining;

			if ( abs( $old_initial - $new_initial ) > $tolerance ) {
				$changes[] = self::change( 'this', $order->get_id(), '_vanpos_initial_payment', $old_initial, $new_initial );
			}
			if ( abs( $old_total - $new_total ) > $tolerance ) {
				$changes[] = self::change( 'this', $order->get_id(), '_vanpos_total_price', $old_total, $new_total );
			}

			// Item level tracks the VAN only; fees (cleaning/dog, €100 each) load
			// onto the initial payment and are excluded from the item line.
			$fee_total = ( '1' === (string) $order->get_meta( '_vanpos_include_cleaning' ) ? 100 : 0 )
				+ ( '1' === (string) $order->get_meta( '_vanpos_include_dog' ) ? 100 : 0 );
			$van_deposit   = $new_initial - $fee_total;
			// Same clamp as sync_primary: a negative remaining is an over-refund artefact
			// and must never be proposed as a new item-level remaining_amount or folded
			// into original_price.
			$van_remaining = max( 0.0, $remaining );
			$van_original  = $van_deposit + $van_remaining;
			foreach ( $order->get_items() as $item ) {
				if ( '' === (string) $item->get_meta( '_vanpos_original_price' ) ) {
					continue;
				}
				$cur_deposit   = (float) $item->get_meta( '_vanpos_deposit_amount' );
				$cur_original  = (float) $item->get_meta( '_vanpos_original_price' );
				$cur_remaining = (float) $item->get_meta( '_vanpos_remaining_amount' );
				if ( abs( $cur_deposit - $van_deposit ) > $tolerance ) {
					$changes[] = self::item_change( $order->get_id(), $item, '_vanpos_deposit_amount', $cur_deposit, $van_deposit );
				}
				if ( abs( $cur_original - $van_original ) > $tolerance ) {
					$changes[] = self::item_change( $order->get_id(), $item, '_vanpos_original_price', $cur_original, $van_original );
				}
				if ( abs( $cur_remaining - $van_remaining ) > $tolerance ) {
					$changes[] = self::item_change( $order->get_id(), $item, '_vanpos_remaining_amount', $cur_remaining, $van_remaining );
				}
			}
			return $changes;
		}

		if ( 'remaining' === $payment_type || 'second_payment' === $payment_type ) {
			if ( ! $order->meta_exists( '_vanpos_remaining_payment' ) ) {
				return $changes;
			}
			$old_remaining = (float) $order->get_meta( '_vanpos_remaining_payment' );
			$new_remaining = $wc_total;
			if ( abs( $old_remaining - $new_remaining ) > $tolerance ) {
				$changes[] = self::change( 'this', $order->get_id(), '_vanpos_remaining_payment', $old_remaining, $new_remaining );
			}
			// Child item-level remaining_amount mirrors the child's own remaining payment.
			foreach ( $order->get_items() as $item ) {
				if ( '' === (string) $item->get_meta( '_vanpos_remaining_amount' ) ) {
					continue;
				}
				$cur = (float) $item->get_meta( '_vanpos_remaining_amount' );
				if ( abs( $cur - $new_remaining ) > $tolerance ) {
					$changes[] = self::item_change( $order->get_id(), $item, '_vanpos_remaining_amount', $cur, $new_remaining );
				}
			}
			// Parent copies that reconcile() would sync.
			$parent = self::get_parent( $order );
			if ( $parent ) {
				$old_p_rem = (float) $parent->get_meta( '_vanpos_remaining_payment' );
				if ( abs( $old_p_rem - $new_remaining ) > $tolerance ) {
					$changes[] = self::change( 'parent', $parent->get_id(), '_vanpos_remaining_payment', $old_p_rem, $new_remaining );
				}
				$p_initial   = (float) $parent->get_meta( '_vanpos_initial_payment' );
				$new_p_total = $p_initial + $new_remaining;
				$old_p_total = (float) $parent->get_meta( '_vanpos_total_price' );
				if ( abs( $old_p_total - $new_p_total ) > $tolerance ) {
					$changes[] = self::change( 'parent', $parent->get_id(), '_vanpos_total_price', $old_p_total, $new_p_total );
				}
			}
			return $changes;
		}

		if ( 'security_deposit' === $payment_type ) {
			if ( ! $order->meta_exists( '_vanpos_security_deposit_payment' ) ) {
				return $changes;
			}
			$old_sd = (float) $order->get_meta( '_vanpos_security_deposit_payment' );
			$new_sd = $wc_total;
			if ( abs( $old_sd - $new_sd ) > $tolerance ) {
				$changes[] = self::change( 'this', $order->get_id(), '_vanpos_security_deposit_payment', $old_sd, $new_sd );
				$parent = self::get_parent( $order );
				if ( $parent ) {
					$old_p_sd = (float) $parent->get_meta( '_vanpos_security_deposit_payment' );
					if ( abs( $old_p_sd - $new_sd ) > $tolerance ) {
						$changes[] = self::change( 'parent', $parent->get_id(), '_vanpos_security_deposit_payment', $old_p_sd, $new_sd );
					}
				}
			}
			return $changes;
		}

		return $changes;
	}

	/**
	 * Build a single preview change row.
	 *
	 * @param string $scope    'this' or 'parent'.
	 * @param int    $order_id Affected order id.
	 * @param string $key      Meta key.
	 * @param float  $old      Old amount.
	 * @param float  $new      New amount.
	 * @return array
	 */
	private static function change( $scope, $order_id, $key, $old, $new ) {
		return array(
			'scope'    => $scope,
			'order_id' => $order_id,
			'key'      => $key,
			'old'      => self::money( $old ),
			'new'      => self::money( $new ),
		);
	}

	/**
	 * Build an item-level preview change row.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order_Item $item     Line item.
	 * @param string        $key      Item meta key.
	 * @param float         $old      Old amount.
	 * @param float         $new      New amount.
	 * @return array
	 */
	private static function item_change( $order_id, $item, $key, $old, $new ) {
		return array(
			'scope'    => 'item',
			'order_id' => $order_id,
			'item'     => $item->get_name(),
			'key'      => $key,
			'old'      => self::money( $old ),
			'new'      => self::money( $new ),
		);
	}

	/**
	 * Format a monetary amount as plain text for AutomateWoo templates.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		return wp_strip_all_tags( wc_price( (float) $amount ) );
	}
}
