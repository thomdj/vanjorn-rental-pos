<?php
/**
 * VanPOS Meta Backfill Tool
 *
 * Admin tool that scans child payment orders for missing email-friendly meta
 * and backfills them from the parent order. Also ensures parent orders have
 * the formatted meta written.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_Meta_Backfill {

	/**
	 * Email-friendly meta keys that should be copied from parent to child.
	 *
	 * _vanpos_booking_reference is included here because email templates and
	 * customer-facing pages reference it on child orders too. The parent is
	 * authoritative; children inherit on copy.
	 */
	const EMAIL_META_KEYS = array(
		'_vanpos_camper_name',
		'_vanpos_pickup_date_formatted',
		'_vanpos_return_date_formatted',
		'_vanpos_total_price',
		'_vanpos_total_price_formatted',
		'_vanpos_initial_payment',
		'_vanpos_initial_payment_formatted',
		'_vanpos_remaining_payment',
		'_vanpos_remaining_payment_formatted',
		'_vanpos_booking_reference',
	);

	/**
	 * Check whether a time value is a legacy slot label ("Morning" / "Afternoon").
	 */
	private static function is_legacy_slot_label( $value ) {
		$v = strtolower( trim( (string) $value ) );
		return in_array( $v, array( 'morning', 'afternoon' ), true );
	}

	/**
	 * Normalize a legacy slot label to the configured admin time.
	 * Non-legacy values pass through unchanged.
	 */
	private static function normalize_time_value( $raw_value, $setting_key, $default_time ) {
		$raw_value = trim( (string) $raw_value );
		if ( '' === $raw_value ) {
			return '';
		}
		if ( self::is_legacy_slot_label( $raw_value ) ) {
			if ( class_exists( 'VanPOS_Functions' ) ) {
				return (string) VanPOS_Functions::get_setting( $setting_key, $default_time );
			}
			return (string) $default_time;
		}
		return $raw_value;
	}

	/**
	 * Resolve the admin-configured default time for a setting key, falling
	 * back to a hardcoded default when VanPOS_Functions is unavailable.
	 * Used as a last-resort fallback when both child and parent order meta
	 * lack a time value (e.g. orders imported before time meta was tracked).
	 *
	 * @param string $setting_key  Setting slug (e.g. 'vanpos_pickup_time').
	 * @param string $default_time Hardcoded fallback (e.g. '15:00').
	 * @return string Configured or default time.
	 */
	private static function get_default_time( $setting_key, $default_time ) {
		if ( class_exists( 'VanPOS_Functions' ) ) {
			return (string) VanPOS_Functions::get_setting( $setting_key, $default_time );
		}
		return (string) $default_time;
	}

	/**
	 * Resolve the expected camper name for an order using the WPML base-language helper.
	 *
	 * For child orders the parent's line items are inspected. Returns empty string
	 * if no product can be found (e.g. deleted product).
	 *
	 * @param WC_Order $order Order object (parent or child).
	 * @return string Expected camper name in the WPML default language.
	 */
	private static function resolve_expected_camper_name( $order ) {
		// Determine which order holds the rental line item.
		$source = $order;
		$parent_id = $order->get_parent_id();
		if ( ! $parent_id ) {
			$parent_id = (int) $order->get_meta( '_vanpos_primary_order_id' );
		}
		if ( $parent_id ) {
			$parent = wc_get_order( $parent_id );
			if ( $parent ) {
				$source = $parent;
			}
		}

		// Use the stored line item product name, which preserves the
		// language context the order was placed in.
		foreach ( $source->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$name = $item->get_name();
			if ( $name ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Return the list of vanpos_* item-meta keys expected on every rental
	 * line item, mapped to the order-level meta key the value should be
	 * sourced from when backfilling.
	 *
	 * @return array
	 */
	private static function vanpos_item_meta_key_map() {
		return array(
			'vanpos_pickup_date' => '_vanpos_pickup_date',
			'vanpos_return_date' => '_vanpos_return_date',
			'vanpos_pickup_time' => '_vanpos_pickup_time',
			'vanpos_return_time' => '_vanpos_return_time',
			'vanpos_rental_days' => '_vanpos_rental_days',
		);
	}

	/**
	 * Underscore-prefixed meta keys that should never appear on a line item.
	 * The underscore prefix conventionally marks order-level meta — finding
	 * these on a line item is residue from an older / removed code path or
	 * a third-party plugin. Safe to delete because:
	 *  - WooCommerce treats underscore-prefixed item meta as hidden, so
	 *    nothing in the WC admin UI relies on them.
	 *  - VanPOS reads the non-prefixed versions everywhere (verified by
	 *    grep for `get_meta( '_vanpos_pickup_date'` etc. on items — zero
	 *    matches across the plugin codebase).
	 *
	 * @return string[]
	 */
	private static function stray_item_meta_keys() {
		return array(
			'_vanpos_pickup_date',
			'_vanpos_return_date',
			'_vanpos_pickup_time',
			'_vanpos_return_time',
			'_vanpos_rental_days',
			'_vanpos_include_dog',
			'_vanpos_include_cleaning',
		);
	}

	/**
	 * Detect whether a line item is missing any of the vanpos_* rental keys.
	 *
	 * @param WC_Order_Item_Product $item Line item.
	 * @return bool True if at least one expected key is missing or empty.
	 */
	private static function item_missing_vanpos_keys( $item ) {
		foreach ( array_keys( self::vanpos_item_meta_key_map() ) as $key ) {
			if ( '' === (string) $item->get_meta( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Backfill missing vanpos_* item-meta keys on the given order's line
	 * items. Values are sourced from the order's own _vanpos_* order meta;
	 * for children, the caller can pass a parent order to use as a second
	 * fallback. For vanpos_rental_days specifically, falls back to computing
	 * the day count from pickup/return dates when nothing else is available.
	 *
	 * Skips orders that should not carry rental item meta:
	 *  - security_deposit children (their line item is the deposit product)
	 *  - non-product line items (fees, taxes, shipping)
	 *
	 * @param WC_Order      $order  Order to fix.
	 * @param WC_Order|null $parent Optional parent for fallback values.
	 * @return bool True if anything was changed.
	 */
	private static function backfill_vanpos_item_meta( $order, $parent = null ) {
		$payment_type = (string) $order->get_meta( '_vanpos_payment_type' );
		if ( 'security_deposit' === $payment_type ) {
			return false;
		}

		$key_map = self::vanpos_item_meta_key_map();

		// Pre-resolve fallback values once: child's own meta, then parent's.
		$source_values = array();
		foreach ( $key_map as $item_key => $order_key ) {
			$val = (string) $order->get_meta( $order_key );
			if ( '' === $val && $parent ) {
				$val = (string) $parent->get_meta( $order_key );
			}
			$source_values[ $item_key ] = $val;
		}

		// Last-resort fallback for time keys: when neither the order nor its
		// parent carries a value (older orders predating time-meta tracking),
		// use the admin-configured default. Without this, the line item's
		// vanpos_pickup_time / vanpos_return_time stay empty even after a fix run.
		if ( '' === $source_values['vanpos_pickup_time'] ) {
			$source_values['vanpos_pickup_time'] = self::get_default_time( 'vanpos_pickup_time', '15:00' );
		}
		if ( '' === $source_values['vanpos_return_time'] ) {
			$source_values['vanpos_return_time'] = self::get_default_time( 'vanpos_return_time', '11:00' );
		}

		// Compute rental_days from dates as a last resort.
		if ( '' === $source_values['vanpos_rental_days']
			&& '' !== $source_values['vanpos_pickup_date']
			&& '' !== $source_values['vanpos_return_date']
		) {
			$pickup_ts = strtotime( $source_values['vanpos_pickup_date'] );
			$return_ts = strtotime( $source_values['vanpos_return_date'] );
			if ( $pickup_ts && $return_ts && $return_ts >= $pickup_ts ) {
				$days = class_exists( 'VanPOS_Functions' )
					? VanPOS_Functions::rental_days_from_dates( $source_values['vanpos_pickup_date'], $source_values['vanpos_return_date'] )
					: ( (int) round( ( $return_ts - $pickup_ts ) / DAY_IN_SECONDS ) + 1 );
				if ( $days > 0 ) {
					$source_values['vanpos_rental_days'] = (string) $days;
				}
			}
		}

		$any_changed = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$item_changed = false;
			foreach ( $key_map as $item_key => $order_key ) {
				if ( '' === (string) $item->get_meta( $item_key ) && '' !== $source_values[ $item_key ] ) {
					$item->update_meta_data( $item_key, $source_values[ $item_key ] );
					$item_changed = true;
				}
			}
			if ( $item_changed ) {
				$item->save();
				$any_changed = true;
			}
		}

		return $any_changed;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Money-meta rounding (sub-cent float residue cleanup)
	 *
	 * Some orders (notably VRC-imported ones) stored money meta straight from
	 * an upstream calculation without a final round() — e.g. an item-level
	 * _vanpos_original_price of 1949.995372 that should read 1950.00. Every
	 * live VanPOS write path rounds money to wc_get_price_decimals() (2), so
	 * these unrounded tails only exist on historically-written meta. The
	 * payment-split reconcile leaves them alone because the residue (< €0.005)
	 * sits inside its €0.01 tolerance, so nothing ever heals them. This pass
	 * rounds them to 2 dp.
	 *
	 * Formatted companions (_..._formatted) are NOT touched: wc_price() already
	 * renders at 2 dp, so the displayed string was correct all along — only the
	 * raw numeric value carried the tail.
	 * ──────────────────────────────────────────────────────────────────── */

	/**
	 * Order-level money meta keys that should always be stored at 2 dp.
	 * Canonical (post-migration) keys only — the legacy/consolidated
	 * _vanpos_deposit_amount at order level is deleted by migrate_local_meta()
	 * before this pass runs, so it is intentionally absent.
	 *
	 * @return string[]
	 */
	private static function money_order_meta_keys() {
		return array(
			'_vanpos_total_price',
			'_vanpos_initial_payment',
			'_vanpos_remaining_payment',
			'_vanpos_security_deposit_payment',
		);
	}

	/**
	 * Item-level money meta keys (the van-only financial triplet).
	 *
	 * @return string[]
	 */
	private static function money_item_meta_keys() {
		return array(
			'_vanpos_original_price',
			'_vanpos_deposit_amount',
			'_vanpos_remaining_amount',
		);
	}

	/**
	 * Find (and optionally apply) 2-dp rounding on money meta that carries
	 * sub-cent floating-point residue.
	 *
	 * A value is flagged only when it is numeric AND differs from its own
	 * 2-dp rounding by more than float noise (1e-6). A clean value like
	 * 957.64 or a legitimate half-euro like 1950.50 is left untouched; only
	 * tails beyond the hundredths place (1949.995372 → 1950.00) are caught.
	 *
	 * When $apply is true, order-level keys are written via update_meta_data()
	 * (the caller persists with its own $order->save()), and each affected
	 * line item is saved here. When false, nothing is written — the returned
	 * change rows drive the scan's diff preview.
	 *
	 * @param WC_Order $order Order to inspect/fix.
	 * @param bool     $apply Whether to write the rounded values.
	 * @return array Change rows ({scope, order_id, key, old, new[, item]}).
	 */
	private static function money_round_changes( $order, $apply = false ) {
		$changes = array();
		$oid     = $order->get_id();

		// Order-level money keys.
		foreach ( self::money_order_meta_keys() as $key ) {
			$raw = $order->get_meta( $key );
			if ( '' === (string) $raw || false === $raw || ! is_numeric( $raw ) ) {
				continue;
			}
			$val     = (float) $raw;
			$rounded = round( $val, 2 );
			if ( abs( $val - $rounded ) <= 0.000001 ) {
				continue; // already clean (within float noise)
			}
			$changes[] = array(
				'scope'    => 'this',
				'order_id' => $oid,
				'key'      => $key,
				'old'      => (string) $raw,
				'new'      => number_format( $rounded, 2, '.', '' ),
			);
			if ( $apply ) {
				$order->update_meta_data( $key, $rounded );
			}
		}

		// Item-level money triplet.
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$item_changed = false;
			foreach ( self::money_item_meta_keys() as $key ) {
				$raw = $item->get_meta( $key );
				if ( '' === (string) $raw || ! is_numeric( $raw ) ) {
					continue;
				}
				$val     = (float) $raw;
				$rounded = round( $val, 2 );
				if ( abs( $val - $rounded ) <= 0.000001 ) {
					continue;
				}
				$changes[] = array(
					'scope'    => 'item',
					'order_id' => $oid,
					'item'     => $item->get_name(),
					'key'      => $key,
					'old'      => (string) $raw,
					'new'      => number_format( $rounded, 2, '.', '' ),
				);
				if ( $apply ) {
					$item->update_meta_data( $key, $rounded );
					$item_changed = true;
				}
			}
			if ( $apply && $item_changed ) {
				$item->save();
			}
		}

		return $changes;
	}

	/**
	 * Compute the canonical _vanpos_order_type_detected value from an
	 * order's _vanpos_order_type + _vanpos_payment_type meta. Mirrors the
	 * private detect_order_type() in VanPOS_Order_Title_Manager so this
	 * backfill doesn't require coupling to the title manager class.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Canonical slug or empty string if not a VanPOS order.
	 */
	private static function detect_order_type_value( $order ) {
		$order_type   = (string) $order->get_meta( '_vanpos_order_type' );
		$payment_type = (string) $order->get_meta( '_vanpos_payment_type' );

		if ( 'primary_rental' === $order_type ) {
			return 'rental_order';
		}

		if ( 'payment_order' === $order_type ) {
			switch ( $payment_type ) {
				case 'security_deposit':
					return 'security_deposit';
				case 'initial':
					return 'initial_payment';
				case 'remaining':
					return 'remaining_payment';
				case 'extension':
					return 'extension_payment';
			}
		}

		return '';
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Meta consolidation migration (v2.4 key refactor)
	 *
	 * One-time migration of the pre-refactor meta keys to the canonical set:
	 *  - Pure renames (value carried over verbatim, old key deleted).
	 *  - Consolidated amount keys (folded into _vanpos_initial_payment /
	 *    _vanpos_remaining_payment, old key deleted).
	 *  - Defunct keys (deleted outright — no canonical equivalent).
	 *  - Derived flags seeded from live child-order state where no legacy
	 *    key existed to carry over (parent orders only).
	 *
	 * Idempotent: re-running is a no-op once the legacy keys are gone.
	 * ──────────────────────────────────────────────────────────────────── */

	/**
	 * Pure renames: old order-meta key => new order-meta key. Value is copied
	 * verbatim (only when the new key is empty, so already-migrated values are
	 * never clobbered) and the old key is deleted.
	 *
	 * NOTE: order-level only. The item-level _vanpos_deposit_amount triplet is
	 * deliberately NOT in any migration map and is never touched here.
	 *
	 * @return array
	 */
	private static function migration_rename_map() {
		return array(
			'_vanpos_deposits_order_has_deposit'    => '_vanpos_order_has_remaining_payment',
			'_vanpos_deposits_deposit_paid'         => '_vanpos_initial_payment_paid',
			'_vanpos_deposits_second_payment_paid'  => '_vanpos_remaining_payment_paid',
			'_vanpos_security_deposit_amount'       => '_vanpos_security_deposit_payment',
			'_vanpos_deposits_payment_schedule'     => '_vanpos_payment_schedule',
		);
	}

	/**
	 * Consolidated amount keys: old key => canonical key it folds into. The
	 * canonical value is only written from the old key when the canonical key
	 * is still empty; the old key is then deleted regardless.
	 *
	 * @return array
	 */
	private static function migration_consolidate_map() {
		return array(
			'_vanpos_deposits_deposit_amount'  => '_vanpos_initial_payment',
			'_vanpos_deposits_second_payment'  => '_vanpos_remaining_payment',
			'_vanpos_deposit_amount'           => '_vanpos_initial_payment', // ORDER-LEVEL only.
		);
	}

	/**
	 * Defunct keys with no canonical equivalent — deleted outright.
	 *
	 * @return string[]
	 */
	private static function migration_defunct_keys() {
		return array(
			'_vanpos_deposits_deposit_breakdown',
		);
	}

	/**
	 * Detect whether an order still carries any legacy key that the migration
	 * would act on (rename source, consolidate source, or defunct), or is
	 * missing a formatted companion the migration would seed.
	 *
	 * @param WC_Order $order Order to inspect.
	 * @return bool
	 */
	private static function order_needs_migration( $order ) {
		foreach ( array_keys( self::migration_rename_map() ) as $old ) {
			if ( $order->meta_exists( $old ) ) {
				return true;
			}
		}
		foreach ( array_keys( self::migration_consolidate_map() ) as $old ) {
			if ( $order->meta_exists( $old ) ) {
				return true;
			}
		}
		foreach ( self::migration_defunct_keys() as $key ) {
			if ( $order->meta_exists( $key ) ) {
				return true;
			}
		}
		// Missing formatted companions that the migration would seed.
		if ( '' !== (string) $order->get_meta( '_vanpos_initial_payment' )
			&& '' === (string) $order->get_meta( '_vanpos_initial_payment_formatted' ) ) {
			return true;
		}
		if ( '' !== (string) $order->get_meta( '_vanpos_security_deposit_payment' )
			&& '' === (string) $order->get_meta( '_vanpos_security_deposit_payment_formatted' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Detect whether a PRIMARY order is missing a derived flag the migration
	 * would seed from live child-order state.
	 *
	 * @param WC_Order $primary Primary rental order.
	 * @return bool
	 */
	private static function primary_needs_flag_seed( $primary ) {
		foreach ( array(
			'_vanpos_order_has_remaining_payment',
			'_vanpos_order_has_security_deposit',
		) as $flag ) {
			if ( '' === (string) $primary->get_meta( $flag ) ) {
				return true;
			}
		}
		// security_deposit_paid is only expected when a security deposit exists.
		if ( 'yes' === (string) $primary->get_meta( '_vanpos_order_has_security_deposit' )
			&& '' === (string) $primary->get_meta( '_vanpos_security_deposit_paid' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Apply the local (single-order) part of the migration: renames,
	 * consolidation, defunct deletion, and formatted-companion seeding.
	 * Does NOT save — the caller saves once after collecting all changes.
	 *
	 * @param WC_Order $order Order to migrate (parent or child).
	 * @return string[] List of change tokens for the order note / log.
	 */
	private static function migrate_local_meta( $order ) {
		$changed = array();

		// 1. Pure renames.
		foreach ( self::migration_rename_map() as $old => $new ) {
			if ( ! $order->meta_exists( $old ) ) {
				continue;
			}
			// Copy verbatim only when the canonical key isn't present yet, so an
			// already-migrated value is never clobbered. meta_exists avoids casting
			// array values (e.g. the payment schedule) to string.
			if ( ! $order->meta_exists( $new ) ) {
				$order->update_meta_data( $new, $order->get_meta( $old ) );
			}
			$order->delete_meta_data( $old );
			$changed[] = $old . '→' . $new;
		}

		// 2. Consolidated amount keys.
		foreach ( self::migration_consolidate_map() as $old => $canonical ) {
			if ( ! $order->meta_exists( $old ) ) {
				continue;
			}
			if ( ! $order->meta_exists( $canonical ) ) {
				$order->update_meta_data( $canonical, $order->get_meta( $old ) );
			}
			$order->delete_meta_data( $old ); // Order-level delete; item meta of same name untouched.
			$changed[] = $old . '⇒' . $canonical;
		}

		// 3. Defunct keys.
		foreach ( self::migration_defunct_keys() as $key ) {
			if ( $order->meta_exists( $key ) ) {
				$order->delete_meta_data( $key );
				$changed[] = $key . ':deleted';
			}
		}

		// 4. Formatted companions.
		$initial = $order->get_meta( '_vanpos_initial_payment' );
		if ( '' !== (string) $initial && '' === (string) $order->get_meta( '_vanpos_initial_payment_formatted' ) ) {
			$order->update_meta_data( '_vanpos_initial_payment_formatted', VanPOS_Order_Manager::format_price( (float) $initial ) );
			$changed[] = '_vanpos_initial_payment_formatted';
		}
		$sd = $order->get_meta( '_vanpos_security_deposit_payment' );
		if ( '' !== (string) $sd && '' === (string) $order->get_meta( '_vanpos_security_deposit_payment_formatted' ) ) {
			$order->update_meta_data( '_vanpos_security_deposit_payment_formatted', VanPOS_Order_Manager::format_price( (float) $sd ) );
			$changed[] = '_vanpos_security_deposit_payment_formatted';
		}

		return $changed;
	}

	/**
	 * Seed the parent-level derived flags from live child-order state, for
	 * orders that never carried a legacy flag to migrate from. Only fills
	 * flags that are currently empty (never overwrites a migrated value).
	 * Does NOT save — caller saves.
	 *
	 * @param WC_Order $primary Primary rental order.
	 * @return string[] List of seeded flag tokens.
	 */
	private static function seed_primary_flags( $primary ) {
		$seeded = array();

		$children = self::collect_children( $primary );

		$has_remaining = false;
		$sd_order      = null;
		foreach ( $children as $child ) {
			$ptype = (string) $child->get_meta( '_vanpos_payment_type' );
			if ( VanPOS_Order_Manager::is_remaining_payment( $ptype ) ) {
				$has_remaining = true;
			} elseif ( 'security_deposit' === $ptype ) {
				$sd_order = $child;
			}
		}

		if ( '' === (string) $primary->get_meta( '_vanpos_order_has_remaining_payment' ) ) {
			$primary->update_meta_data( '_vanpos_order_has_remaining_payment', $has_remaining ? 'yes' : 'no' );
			$seeded[] = '_vanpos_order_has_remaining_payment';
		}

		if ( '' === (string) $primary->get_meta( '_vanpos_order_has_security_deposit' ) ) {
			$primary->update_meta_data( '_vanpos_order_has_security_deposit', $sd_order ? 'yes' : 'no' );
			$seeded[] = '_vanpos_order_has_security_deposit';
		}

		// Only meaningful when a security deposit exists. Derive paid state from
		// the SD child order's own status (paid date / paid statuses).
		if ( $sd_order && '' === (string) $primary->get_meta( '_vanpos_security_deposit_paid' ) ) {
			$paid = $sd_order->get_date_paid() || $sd_order->has_status( array( 'processing', 'completed' ) );
			$primary->update_meta_data( '_vanpos_security_deposit_paid', $paid ? 'yes' : 'no' );
			$seeded[] = '_vanpos_security_deposit_paid';
		}

		return $seeded;
	}

	/**
	 * Collect a primary order's child orders (WC parent + meta-linked),
	 * de-duplicated, excluding refunds.
	 *
	 * @param WC_Order $primary Primary order.
	 * @return WC_Order[]
	 */
	private static function collect_children( $primary ) {
		$primary_id = $primary->get_id();
		$by_parent  = wc_get_orders( array( 'parent' => $primary_id, 'limit' => -1 ) );
		$by_meta    = wc_get_orders( array(
			'meta_key'   => '_vanpos_primary_order_id',
			'meta_value' => $primary_id,
			'limit'      => -1,
		) );
		$seen = array();
		$out  = array();
		foreach ( array_merge( $by_parent, $by_meta ) as $child ) {
			if ( ! is_a( $child, 'WC_Order' ) ) {
				continue; // skip refunds
			}
			$cid = $child->get_id();
			if ( isset( $seen[ $cid ] ) ) {
				continue;
			}
			$seen[ $cid ] = true;
			$out[]        = $child;
		}
		return $out;
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'wp_ajax_vanpos_backfill_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_vanpos_backfill_fix', array( __CLASS__, 'ajax_fix' ) );
	}

	/**
	 * Add submenu page under VAN-Jorn Rental POS.
	 */
	public static function add_menu() {
		add_submenu_page(
			'vanjorn-rental-pos',
			__( 'Meta Backfill', 'vanjorn-rental-pos' ),
			__( 'Meta Backfill', 'vanjorn-rental-pos' ),
			'manage_woocommerce',
			'vanjorn-rental-pos-backfill',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * AJAX: Scan for orders with missing meta.
	 */
	public static function ajax_scan() {
		check_ajax_referer( 'vanpos_backfill', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$results = array(
			'parents_missing'  => array(),
			'children_missing' => array(),
		);

		// 1. Scan parent orders missing formatted meta
		$parents = wc_get_orders( array(
			'meta_key'   => '_vanpos_order_type',
			'meta_value' => 'primary_rental',
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => array( 'processing', 'completed', 'on-hold', 'pending' ),
		) );

		foreach ( $parents as $parent_id ) {
			$order = wc_get_order( $parent_id );
			if ( ! $order ) {
				continue;
			}

			$missing = array();
			$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
			$return_date = $order->get_meta( '_vanpos_return_date' );
			$pickup_time = $order->get_meta( '_vanpos_pickup_time' );
			$return_time = $order->get_meta( '_vanpos_return_time' );

			// Can only generate formatted meta if raw dates exist
			if ( ! $pickup_date && ! $return_date ) {
				continue;
			}

			if ( $pickup_date && ! $order->get_meta( '_vanpos_pickup_date_formatted' ) ) {
				$missing[] = '_vanpos_pickup_date_formatted';
			}
			if ( $return_date && ! $order->get_meta( '_vanpos_return_date_formatted' ) ) {
				$missing[] = '_vanpos_return_date_formatted';
			}
			if ( ! $order->get_meta( '_vanpos_camper_name' ) ) {
				$missing[] = '_vanpos_camper_name';
			}
			// Check for missing or stale custom title.
			$stored_title  = $order->get_meta( '_vanpos_custom_order_title' );
			$stored_camper = $order->get_meta( '_vanpos_camper_name' );
			if ( ! $stored_title ) {
				$missing[] = '_vanpos_custom_order_title';
			} elseif ( in_array( '_vanpos_camper_name', $missing, true ) ) {
				// Title exists but camper name is missing — title will need regeneration.
				$missing[] = '_vanpos_custom_order_title:stale';
			} elseif ( $stored_camper && strpos( $stored_title, $stored_camper ) === false ) {
				// Title exists but doesn't contain the stored camper name —
				// it was generated before the camper name was corrected.
				$missing[] = '_vanpos_custom_order_title:stale';
			}
			$total_price = $order->get_meta( '_vanpos_total_price' );
			if ( $total_price !== '' && $total_price !== false && ! $order->get_meta( '_vanpos_total_price_formatted' ) ) {
				$missing[] = '_vanpos_total_price_formatted';
			}
			// Remaining payment: flag if missing AND computable (we can derive
			// it from total_price − initial_payment, which covers fully-prepaid
			// short-term bookings where remaining == 0). Also flag the formatted
			// variant whenever the raw value is present without the formatted one.
			$remaining       = $order->get_meta( '_vanpos_remaining_payment' );
			$initial_payment = $order->get_meta( '_vanpos_initial_payment' );
			if ( $remaining === '' || $remaining === false ) {
				if ( $total_price !== '' && $total_price !== false
					&& $initial_payment !== '' && $initial_payment !== false
				) {
					$missing[] = '_vanpos_remaining_payment';
					$missing[] = '_vanpos_remaining_payment_formatted';
				}
			} elseif ( ! $order->get_meta( '_vanpos_remaining_payment_formatted' ) ) {
				$missing[] = '_vanpos_remaining_payment_formatted';
			}
			// Pickup/return time: flag if missing OR legacy slot label. Older
			// orders predating time-meta tracking will be entirely missing
			// these; the fix path falls back to admin-configured defaults.
			if ( $pickup_time === '' || $pickup_time === false || self::is_legacy_slot_label( $pickup_time ) ) {
				$missing[] = '_vanpos_pickup_time';
			}
			if ( $return_time === '' || $return_time === false || self::is_legacy_slot_label( $return_time ) ) {
				$missing[] = '_vanpos_return_time';
			}

			// Check line items for missing Kestrel rental keys
			// (wcrp_rental_products_rent_from / rent_to). Required by
			// VanPOS_Functions::get_rental_unavailable_dates() so the
			// frontend calendar can see the booking. Orders predating
			// the admin-add-order / change-manager Kestrel-key patches
			// will be missing these.
			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				$has_from = '' !== (string) $item->get_meta( 'wcrp_rental_products_rent_from' );
				$has_to   = '' !== (string) $item->get_meta( 'wcrp_rental_products_rent_to' );
				if ( ! $has_from || ! $has_to ) {
					$missing[] = 'item_meta:kestrel_keys';
					break; // one flag per order is enough
				}
			}

			// Check line items for missing vanpos_* rental keys
			// (vanpos_pickup_date, vanpos_return_date, vanpos_pickup_time,
			// vanpos_return_time, vanpos_rental_days). Frontend-created
			// orders set the Kestrel keys but not the parallel vanpos_*
			// keys; this flag catches the asymmetry.
			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				if ( self::item_missing_vanpos_keys( $item ) ) {
					$missing[] = 'item_meta:vanpos_keys';
					break;
				}
			}

			// Check for missing _vanpos_order_type_detected order meta.
			// Cosmetic — derived from _vanpos_order_type + _vanpos_payment_type —
			// but inconsistent across the dataset (only present where the
			// title manager's regenerate_title() ran).
			if ( '' === (string) $order->get_meta( '_vanpos_order_type_detected' ) ) {
				$missing[] = '_vanpos_order_type_detected';
			}

			// Check for stray underscore-prefixed meta on line items
			// (e.g. _vanpos_return_date on the line item instead of just
			// on the order). Source unknown — probably removed code or
			// a third-party plugin — but harmless to delete.
			$stray_keys = self::stray_item_meta_keys();
			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				foreach ( $stray_keys as $sk ) {
					if ( '' !== (string) $item->get_meta( $sk ) ) {
						$missing[] = 'item_meta:stray_keys';
						break 2;
					}
				}
			}

			// Consolidation migration: legacy keys present, or derived flags
			// not yet seeded on this primary.
			if ( self::order_needs_migration( $order ) ) {
				$missing[] = 'meta_migration:legacy';
			}
			if ( self::primary_needs_flag_seed( $order ) ) {
				$missing[] = 'meta_migration:flags';
			}
			// Payment-split meta diverges from the order's real totals — whether
			// from a pre-handler coupon OR an order created with a wrong total
			// (e.g. an import storing the net subtotal). preview() returns the
			// exact before→after changes for review.
			// Skip for price-override orders: zero totals are intentional and the
			// reconcile pass would misread them as a discrepancy.
			$reconcile_changes = array();
			if ( 'yes' !== (string) $order->get_meta( '_vanpos_price_overridden' ) && class_exists( 'VanPOS_Discount_Manager' ) ) {
				$reconcile_changes = VanPOS_Discount_Manager::preview( $order );
				if ( ! empty( $reconcile_changes ) ) {
					$missing[] = 'split_reconcile';
				}
			}

			// Sub-cent float residue on stored money meta (e.g. an imported
			// _vanpos_original_price of 1949.995372 that should read 1950.00).
			$round_changes = self::money_round_changes( $order, false );
			if ( ! empty( $round_changes ) ) {
				$missing[] = 'item_meta:round_money';
			}

			if ( ! empty( $missing ) ) {
				$results['parents_missing'][] = array(
					'order_id'      => $parent_id,
					'order_number'  => $order->get_order_number(),
					'customer'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'pickup_date'   => $pickup_date,
					'missing_keys'  => $missing,
					'changes'       => array_merge( $reconcile_changes, $round_changes ),
				);
			}
		}

		// 2. Scan child payment orders missing email meta
		$children = wc_get_orders( array(
			'meta_key'   => '_vanpos_order_type',
			'meta_value' => 'payment_order',
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => array( 'processing', 'completed', 'on-hold', 'pending', 'refunded' ),
		) );

		foreach ( $children as $child_id ) {
			$order = wc_get_order( $child_id );
			if ( ! $order ) {
				continue;
			}

			$payment_type = $order->get_meta( '_vanpos_payment_type' );
			$parent_id    = $order->get_parent_id();
			if ( ! $parent_id ) {
				$parent_id = (int) $order->get_meta( '_vanpos_primary_order_id' );
			}
			if ( ! $parent_id ) {
				continue;
			}

			$missing = array();
			$email_changes = array();
			$parent_for_email = wc_get_order( $parent_id );
			// A remaining child owns _vanpos_remaining_payment as its own bucket;
			// never treat a divergence there as a parent-copy mismatch.
			$own_bucket_keys = array();
			if ( VanPOS_Order_Manager::is_remaining_payment( $payment_type ) ) {
				$own_bucket_keys = array( '_vanpos_remaining_payment', '_vanpos_remaining_payment_formatted' );
			}
			// If the parent's remaining_payment went negative (over-refund artefact),
			// treat it as an own-bucket key on every child type so the negative balance
			// is never flagged as stale or synced. A negative remaining is a bookkeeping
			// artefact only meaningful on the primary order itself (e.g. security-deposit
			// children should keep their original €0, not inherit −€18.80).
			if ( $parent_for_email && ! in_array( '_vanpos_remaining_payment', $own_bucket_keys, true ) ) {
				$parent_remaining_raw = $parent_for_email->get_meta( '_vanpos_remaining_payment' );
				if ( is_numeric( $parent_remaining_raw ) && (float) $parent_remaining_raw < 0 ) {
					$own_bucket_keys[] = '_vanpos_remaining_payment';
					$own_bucket_keys[] = '_vanpos_remaining_payment_formatted';
				}
			}
			foreach ( self::EMAIL_META_KEYS as $key ) {
				$value = $order->get_meta( $key );
				if ( $value === '' || $value === false ) {
					$missing[] = $key;
					continue;
				}
				// Present but stale: differs from the parent's (corrected) value.
				if ( $parent_for_email && ! in_array( $key, $own_bucket_keys, true ) ) {
					$parent_value = $parent_for_email->get_meta( $key );
					if ( $parent_value !== '' && $parent_value !== false && (string) $value !== (string) $parent_value ) {
						$missing[]       = 'email_meta:mismatch';
						$email_changes[] = array(
							'scope'    => 'this',
							'order_id' => $child_id,
							'key'      => $key,
							'old'      => (string) $value,
							'new'      => (string) $parent_value,
						);
					}
				}
			}

			// Camper name: if present, check it matches the parent's camper name.
			// The parent's stored name is authoritative; we don't compare against
			// the WPML base language for existing orders.
			$child_camper = $order->get_meta( '_vanpos_camper_name' );
			if ( $child_camper !== '' && $child_camper !== false ) {
				$parent_order  = wc_get_order( $parent_id );
				$parent_camper = $parent_order ? $parent_order->get_meta( '_vanpos_camper_name' ) : '';

				if ( $parent_camper && $child_camper !== $parent_camper ) {
					$missing[] = '_vanpos_camper_name:parent_mismatch';
				}
			}

			// Check for missing or stale custom title.
			$child_title  = $order->get_meta( '_vanpos_custom_order_title' );
			$child_camper_for_title = $order->get_meta( '_vanpos_camper_name' );
			if ( ! $child_title ) {
				$missing[] = '_vanpos_custom_order_title';
			} else {
				$has_camper_issue = in_array( '_vanpos_camper_name', $missing, true )
					|| in_array( '_vanpos_camper_name:parent_mismatch', $missing, true );
				if ( $has_camper_issue ) {
					$missing[] = '_vanpos_custom_order_title:stale';
				} elseif ( $child_camper_for_title && strpos( $child_title, $child_camper_for_title ) === false ) {
					// Title exists but doesn't contain the stored camper name —
					// it was generated before the camper name was corrected.
					$missing[] = '_vanpos_custom_order_title:stale';
				}
			}

			// Check formatted due date
			$due_date = $order->get_meta( '_payment_due_date' );
			if ( $due_date && ! $order->get_meta( '_payment_due_date_formatted' ) ) {
				$missing[] = '_payment_due_date_formatted';
			}

			// Extension orders: check for missing amount meta and due date.
			// Both are set by the change manager on creation; older orders won't have them.
			if ( 'extension' === $payment_type ) {
				if ( '' === (string) $order->get_meta( '_vanpos_extension_amount' ) ) {
					$missing[] = '_vanpos_extension_amount';
				} elseif ( '' === (string) $order->get_meta( '_vanpos_extension_amount_formatted' ) ) {
					$missing[] = '_vanpos_extension_amount_formatted';
				}
				if ( ! $due_date ) {
					$missing[] = '_payment_due_date';
				}
			}

			// Check _is_short_term_booking (required by AutomateWoo triggers on rental payment orders).
			// Should NOT exist on security_deposit orders (they use _is_short_term_deposit).
			if ( $payment_type === 'security_deposit' ) {
				$stb = $order->get_meta( '_is_short_term_booking' );
				if ( $stb !== '' && $stb !== false ) {
					$missing[] = '_is_short_term_booking:remove';
				}
			} else {
				$stb = $order->get_meta( '_is_short_term_booking' );
				if ( $stb === '' || $stb === false ) {
					$missing[] = '_is_short_term_booking';
				}
			}

			// Check for _is_short_term_deposit on non-security_deposit orders (should not exist)
			if ( $payment_type !== 'security_deposit' ) {
				$std = $order->get_meta( '_is_short_term_deposit' );
				if ( $std !== '' && $std !== false ) {
					$missing[] = '_is_short_term_deposit:remove';
				}
			}

			// Check for missing or legacy time slot labels on child orders.
			$child_pickup_time = $order->get_meta( '_vanpos_pickup_time' );
			$child_return_time = $order->get_meta( '_vanpos_return_time' );
			if ( $child_pickup_time === '' || $child_pickup_time === false || self::is_legacy_slot_label( $child_pickup_time ) ) {
				$missing[] = '_vanpos_pickup_time';
			}
			if ( $child_return_time === '' || $child_return_time === false || self::is_legacy_slot_label( $child_return_time ) ) {
				$missing[] = '_vanpos_return_time';
			}

			// For non-security-deposit children (remaining payment orders),
			// check line items for missing Kestrel rental keys. Frontend-created
			// remaining payment orders carry these on the payment line item;
			// admin-created ones may not.
			if ( $payment_type !== 'security_deposit' ) {
				foreach ( $order->get_items() as $item ) {
					if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
						continue;
					}
					$has_from = '' !== (string) $item->get_meta( 'wcrp_rental_products_rent_from' );
					$has_to   = '' !== (string) $item->get_meta( 'wcrp_rental_products_rent_to' );
					if ( ! $has_from || ! $has_to ) {
						$missing[] = 'item_meta:kestrel_keys';
						break;
					}
				}

				// Same scope (non-security-deposit): check for missing
				// vanpos_* rental keys on line items. The order-manager's
				// blanket meta copy only propagates what the parent's line
				// item carried at creation time; pre-patch parents didn't
				// have these, so the children don't either.
				foreach ( $order->get_items() as $item ) {
					if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
						continue;
					}
					if ( self::item_missing_vanpos_keys( $item ) ) {
						$missing[] = 'item_meta:vanpos_keys';
						break;
					}
				}
			}

			// Check for missing _vanpos_order_type_detected order meta.
			// Applies to all child types — only the title manager's
			// regenerate_title() writes this, so most historical children
			// lack it.
			if ( '' === (string) $order->get_meta( '_vanpos_order_type_detected' ) ) {
				$missing[] = '_vanpos_order_type_detected';
			}

			// Check for stray underscore-prefixed meta on line items
			// (same pattern as parents). Applies to all child types
			// including security_deposit — the deposit product item
			// shouldn't carry rental meta of any kind.
			$stray_keys = self::stray_item_meta_keys();
			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				foreach ( $stray_keys as $sk ) {
					if ( '' !== (string) $item->get_meta( $sk ) ) {
						$missing[] = 'item_meta:stray_keys';
						break 2;
					}
				}
			}

			// Consolidation migration: legacy keys present on this child.
			if ( self::order_needs_migration( $order ) ) {
				$missing[] = 'meta_migration:legacy';
			}
			// Payment-split meta diverges from this child's real total.
			$reconcile_changes = array();
			if ( class_exists( 'VanPOS_Discount_Manager' ) ) {
				$reconcile_changes = VanPOS_Discount_Manager::preview( $order );
				if ( ! empty( $reconcile_changes ) ) {
					$missing[] = 'split_reconcile';
				}
			}

			// Sub-cent float residue on stored money meta.
			$round_changes = self::money_round_changes( $order, false );
			if ( ! empty( $round_changes ) ) {
				$missing[] = 'item_meta:round_money';
			}

			if ( ! empty( $missing ) ) {
				$missing = array_values( array_unique( $missing ) );
				$results['children_missing'][] = array(
					'order_id'      => $child_id,
					'order_number'  => $order->get_order_number(),
					'parent_id'     => $parent_id,
					'payment_type'  => $payment_type,
					'customer'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'missing_keys'  => $missing,
					'changes'       => array_merge( $email_changes, $reconcile_changes, $round_changes ),
				);
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * AJAX: Fix a single order (parent or child).
	 */
	public static function ajax_fix() {
		check_ajax_referer( 'vanpos_backfill', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$type     = isset( $_POST['fix_type'] ) ? sanitize_text_field( $_POST['fix_type'] ) : '';

		if ( ! $order_id || ! in_array( $type, array( 'parent', 'child' ), true ) ) {
			wp_send_json_error( 'Invalid request.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found.' );
		}

		$fixed = array();

		if ( $type === 'parent' ) {
			$fixed = self::fix_parent( $order );
		} else {
			$fixed = self::fix_child( $order );
		}

		if ( is_wp_error( $fixed ) ) {
			wp_send_json_error( $fixed->get_error_message() );
		}

		wp_send_json_success( array(
			'order_id'  => $order_id,
			'fixed'     => $fixed,
		) );
	}

	/**
	 * Fix a parent order: generate formatted meta from raw values.
	 *
	 * @param WC_Order $order   Parent order.
	 * @param bool     $cascade Whether to also fix all child orders (default true).
	 *                          Set to false when called from fix_child to prevent recursion.
	 * @return array List of fixed keys.
	 */
	private static function fix_parent( $order, $cascade = true ) {
		// Defensive: fix_parent writes order notes (add_order_note), which only
		// WC_Order implements. The scan never feeds refunds here, but guard
		// anyway so a forged/manual AJAX call with a refund id can't fatal.
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return new WP_Error( 'not_an_order', 'fix_parent requires a WC_Order; got ' . get_class( $order ) . '.' );
		}

		$fixed = array();

		// Consolidation migration (v2.4 key refactor): renames, consolidation,
		// defunct deletion, formatted companions, then parent-level derived flags.
		foreach ( self::migrate_local_meta( $order ) as $tok ) {
			$fixed[] = 'migrate:' . $tok;
		}
		foreach ( self::seed_primary_flags( $order ) as $tok ) {
			$fixed[] = 'seed:' . $tok;
		}

		$pickup_date = $order->get_meta( '_vanpos_pickup_date' );
		$return_date = $order->get_meta( '_vanpos_return_date' );

		if ( $pickup_date && ! $order->get_meta( '_vanpos_pickup_date_formatted' ) ) {
			// Use the canonical format_meta_date() helper (d-m-Y) so backfilled
			// orders match the format written by checkout and the change manager.
			$order->update_meta_data( '_vanpos_pickup_date_formatted', VanPOS_Order_Manager::format_meta_date( $pickup_date ) );
			$fixed[] = '_vanpos_pickup_date_formatted';
		}
		if ( $return_date && ! $order->get_meta( '_vanpos_return_date_formatted' ) ) {
			$order->update_meta_data( '_vanpos_return_date_formatted', VanPOS_Order_Manager::format_meta_date( $return_date ) );
			$fixed[] = '_vanpos_return_date_formatted';
		}

		$pickup_time = $order->get_meta( '_vanpos_pickup_time' );
		$pickup_time_norm = self::normalize_time_value( $pickup_time, 'vanpos_pickup_time', '15:00' );
		if ( '' !== $pickup_time && $pickup_time_norm !== $pickup_time ) {
			$order->update_meta_data( '_vanpos_pickup_time', $pickup_time_norm );
			$fixed[] = '_vanpos_pickup_time';
		} elseif ( '' === $pickup_time ) {
			// Entirely missing — fall back to admin-configured default. Without
			// this, line-item backfill below can't source a time either.
			$default_pickup = self::get_default_time( 'vanpos_pickup_time', '15:00' );
			if ( '' !== $default_pickup ) {
				$order->update_meta_data( '_vanpos_pickup_time', $default_pickup );
				$pickup_time = $default_pickup;
				$fixed[] = '_vanpos_pickup_time';
			}
		}

		$return_time = $order->get_meta( '_vanpos_return_time' );
		$return_time_norm = self::normalize_time_value( $return_time, 'vanpos_return_time', '11:00' );
		if ( '' !== $return_time && $return_time_norm !== $return_time ) {
			$order->update_meta_data( '_vanpos_return_time', $return_time_norm );
			$fixed[] = '_vanpos_return_time';
		} elseif ( '' === $return_time ) {
			$default_return = self::get_default_time( 'vanpos_return_time', '11:00' );
			if ( '' !== $default_return ) {
				$order->update_meta_data( '_vanpos_return_time', $default_return );
				$return_time = $default_return;
				$fixed[] = '_vanpos_return_time';
			}
		}

		// Camper name: fill if missing from line item. Existing names are
		// authoritative (may be in a different language than the line item).
		$stored_camper = $order->get_meta( '_vanpos_camper_name' );
		if ( ! $stored_camper ) {
			$expected_camper = self::resolve_expected_camper_name( $order );
			if ( $expected_camper ) {
				$order->update_meta_data( '_vanpos_camper_name', $expected_camper );
				$fixed[] = '_vanpos_camper_name';
			}
		}

		$total_price = $order->get_meta( '_vanpos_total_price' );
		if ( $total_price !== '' && $total_price !== false && ! $order->get_meta( '_vanpos_total_price_formatted' ) ) {
			$order->update_meta_data( '_vanpos_total_price_formatted', VanPOS_Order_Manager::format_price( (float) $total_price ) );
			$fixed[] = '_vanpos_total_price_formatted';
		}

		$remaining = $order->get_meta( '_vanpos_remaining_payment' );
		// Compute when missing: total_price − initial_payment. For fully
		// prepaid bookings the result is 0 (a legitimate, scan-passing value).
		// Without this, the formatted-meta block below silently no-ops because
		// it gates on `$remaining !== ''`.
		if ( $remaining === '' || $remaining === false ) {
			$initial_payment = $order->get_meta( '_vanpos_initial_payment' );
			if ( $total_price !== '' && $total_price !== false
				&& $initial_payment !== '' && $initial_payment !== false
			) {
				$computed_remaining = max( 0, (float) $total_price - (float) $initial_payment );
				$order->update_meta_data( '_vanpos_remaining_payment', $computed_remaining );
				$remaining = $computed_remaining;
				$fixed[] = '_vanpos_remaining_payment';
			}
		}
		if ( $remaining !== '' && $remaining !== false && ! $order->get_meta( '_vanpos_remaining_payment_formatted' ) ) {
			$order->update_meta_data( '_vanpos_remaining_payment_formatted', VanPOS_Order_Manager::format_price( (float) $remaining ) );
			$fixed[] = '_vanpos_remaining_payment_formatted';
		}

		// Normalize legacy time slot labels on line item meta.
		foreach ( $order->get_items() as $item ) {
			$item_changed = false;
			$item_pickup = $item->get_meta( 'vanpos_pickup_time' );
			if ( self::is_legacy_slot_label( $item_pickup ) ) {
				$item->update_meta_data( 'vanpos_pickup_time', self::normalize_time_value( $item_pickup, 'vanpos_pickup_time', '15:00' ) );
				$item_changed = true;
			}
			$item_return = $item->get_meta( 'vanpos_return_time' );
			if ( self::is_legacy_slot_label( $item_return ) ) {
				$item->update_meta_data( 'vanpos_return_time', self::normalize_time_value( $item_return, 'vanpos_return_time', '11:00' ) );
				$item_changed = true;
			}
			if ( $item_changed ) {
				$item->save();
				if ( ! in_array( 'item_meta:time', $fixed, true ) ) {
					$fixed[] = 'item_meta:time';
				}
			}
		}

		// Backfill missing Kestrel rental keys on line items. Source the
		// values from the item's own vanpos_pickup_date / vanpos_return_date
		// when present, otherwise fall back to order-level meta (already
		// read into $pickup_date / $return_date above).
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$item_changed = false;

			if ( '' === (string) $item->get_meta( 'wcrp_rental_products_rent_from' ) ) {
				$source = (string) $item->get_meta( 'vanpos_pickup_date' );
				if ( '' === $source ) {
					$source = (string) $pickup_date;
				}
				if ( '' !== $source ) {
					$item->update_meta_data( 'wcrp_rental_products_rent_from', $source );
					$item_changed = true;
				}
			}

			if ( '' === (string) $item->get_meta( 'wcrp_rental_products_rent_to' ) ) {
				$source = (string) $item->get_meta( 'vanpos_return_date' );
				if ( '' === $source ) {
					$source = (string) $return_date;
				}
				if ( '' !== $source ) {
					$item->update_meta_data( 'wcrp_rental_products_rent_to', $source );
					$item_changed = true;
				}
			}

			if ( $item_changed ) {
				$item->save();
				if ( ! in_array( 'item_meta:kestrel_keys', $fixed, true ) ) {
					$fixed[] = 'item_meta:kestrel_keys';
				}
			}
		}

		// Backfill missing vanpos_* item meta on line items. Sourced from
		// the order's own _vanpos_* order meta (already populated on every
		// parent in the dataset). Skips non-product line items internally.
		if ( self::backfill_vanpos_item_meta( $order ) ) {
			$fixed[] = 'item_meta:vanpos_keys';
		}

		// Backfill _vanpos_order_type_detected if missing. Mirrors the
		// detect_order_type() logic in VanPOS_Order_Title_Manager — a
		// simple mapping from _vanpos_order_type + _vanpos_payment_type
		// to the canonical detected slug.
		if ( '' === (string) $order->get_meta( '_vanpos_order_type_detected' ) ) {
			$detected = self::detect_order_type_value( $order );
			if ( $detected ) {
				$order->update_meta_data( '_vanpos_order_type_detected', $detected );
				$fixed[] = '_vanpos_order_type_detected';
			}
		}

		// Delete stray underscore-prefixed item meta. Hidden from the WC
		// admin UI by the underscore convention, but visible in raw meta
		// dumps and noise in any code that iterates item meta. Nothing in
		// the VanPOS code reads from these.
		$stray_keys  = self::stray_item_meta_keys();
		$any_deleted = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$item_changed = false;
			foreach ( $stray_keys as $sk ) {
				if ( '' !== (string) $item->get_meta( $sk ) ) {
					$item->delete_meta_data( $sk );
					$item_changed = true;
				}
			}
			if ( $item_changed ) {
				$item->save();
				$any_deleted = true;
			}
		}
		if ( $any_deleted ) {
			$fixed[] = 'item_meta:stray_keys';
		}

		// Round sub-cent float residue on money meta. Item meta is saved inside
		// the helper; order meta is persisted by the save() below. Runs before
		// the reconcile pass so reconcile reloads already-clean values.
		if ( ! empty( self::money_round_changes( $order, true ) ) ) {
			$fixed[] = 'item_meta:round_money';
		}

		if ( ! empty( $fixed ) ) {
			$order->add_order_note(
				sprintf( 'VanPOS Meta Backfill: added %s', implode( ', ', $fixed ) ),
				false,
				false
			);
			$order->save();
		}

		// Regenerate custom title if camper name was added, title is missing,
		// or the existing title doesn't contain the stored camper name.
		$camper_changed = in_array( '_vanpos_camper_name', $fixed, true );
		$title_missing  = ! $order->get_meta( '_vanpos_custom_order_title' );
		$title_stale    = false;
		if ( ! $title_missing ) {
			$cur_camper = $order->get_meta( '_vanpos_camper_name' );
			$cur_title  = $order->get_meta( '_vanpos_custom_order_title' );
			if ( $cur_camper && strpos( $cur_title, $cur_camper ) === false ) {
				$title_stale = true;
			}
		}

		if ( ( $camper_changed || $title_missing || $title_stale ) && class_exists( 'VanPOS_Order_Title_Manager' ) ) {
			if ( VanPOS_Order_Title_Manager::regenerate_title( $order ) ) {
				$fixed[] = '_vanpos_custom_order_title:regenerated';
			}
		}

		// Reconcile a pre-handler coupon: recompute the split meta from the order's
		// actual post-discount WooCommerce total, using the SAME logic as the live
		// discount handler. Runs after the migration above so the canonical keys
		// exist, and self-saves. Reload first so it sees persisted migrate edits.
		// Skipped for price-override orders — zero is intentional, not a mismatch.
		if ( 'yes' !== (string) $order->get_meta( '_vanpos_price_overridden' ) && class_exists( 'VanPOS_Discount_Manager' ) ) {
			$reload = wc_get_order( $order->get_id() );
			if ( $reload && VanPOS_Discount_Manager::reconcile( $reload ) ) {
				$fixed[] = 'split_reconcile';
			}
		}

		// Cascade: ensure all child orders also have consistent camper name
		// and a valid title. This covers cases where only the parent was
		// flagged in the scan but children also have stale data.
		if ( $cascade ) {
			$order_id = $order->get_id();
			$children = wc_get_orders( array(
				'parent' => $order_id,
				'limit'  => -1,
			) );
			// Also include orders linked via meta (not WC parent).
			$meta_children = wc_get_orders( array(
				'meta_key'   => '_vanpos_primary_order_id',
				'meta_value' => $order_id,
				'limit'      => -1,
			) );
			$seen_ids = array();
			foreach ( array_merge( $children, $meta_children ) as $child ) {
				// wc_get_orders( 'parent' => ... ) also returns WC_Order_Refund
				// objects, which extend WC_Abstract_Order (not WC_Order) and
				// therefore lack add_order_note(). They are not VanPOS payment
				// orders and must never be passed to fix_child(). Skip anything
				// that isn't a full WC_Order.
				if ( ! is_a( $child, 'WC_Order' ) ) {
					continue;
				}

				$child_id = $child->get_id();
				if ( isset( $seen_ids[ $child_id ] ) ) {
					continue;
				}
				$seen_ids[ $child_id ] = true;

				$child_fixed = self::fix_child( $child, true );
				if ( ! is_wp_error( $child_fixed ) && ! empty( $child_fixed ) ) {
					$fixed[] = sprintf( 'child#%d:%s', $child_id, implode( ',', $child_fixed ) );
				}
			}
		}

		return $fixed;
	}

	/**
	 * Fix a child order: copy email meta from parent + generate formatted due date.
	 *
	 * @param WC_Order $order           Child order.
	 * @param bool     $skip_parent_fix Skip calling fix_parent (true when called from
	 *                                  fix_parent's cascade to prevent recursion).
	 * @return array|WP_Error List of fixed keys or error.
	 */
	private static function fix_child( $order, $skip_parent_fix = false ) {
		// Defensive: fix_child writes order notes and rental item meta, both of
		// which assume a full WC_Order. A WC_Order_Refund has a parent id (so it
		// would pass the checks below) but lacks add_order_note() and must never
		// reach this method. Refuse anything that isn't a WC_Order.
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return new WP_Error( 'not_an_order', 'fix_child requires a WC_Order; got ' . get_class( $order ) . '.' );
		}

		$parent_id = $order->get_parent_id();
		if ( ! $parent_id ) {
			$parent_id = (int) $order->get_meta( '_vanpos_primary_order_id' );
		}
		if ( ! $parent_id ) {
			return new WP_Error( 'no_parent', 'No parent order found.' );
		}

		$parent = wc_get_order( $parent_id );
		if ( ! $parent ) {
			return new WP_Error( 'invalid_parent', 'Parent order #' . $parent_id . ' not found.' );
		}

		// Make sure parent has all formatted meta before we copy.
		// Skip when called from fix_parent cascade to prevent recursion.
		if ( ! $skip_parent_fix ) {
			self::fix_parent( $parent, false );
			$parent = wc_get_order( $parent_id ); // reload after potential save
		}

		$fixed = array();

		$payment_type = (string) $order->get_meta( '_vanpos_payment_type' );

		// Consolidation migration (v2.4 key refactor): local renames /
		// consolidation / defunct deletion / formatted companions on the child.
		foreach ( self::migrate_local_meta( $order ) as $tok ) {
			$fixed[] = 'migrate:' . $tok;
		}

		// Copy email meta from parent. These mirror the parent's booking figures
		// onto the child so AutomateWoo templates triggered on the child order
		// (e.g. the security-deposit email) can render them. Overwrite when the
		// child's value DIFFERS from the parent's — not only when empty — so a
		// stale copy (e.g. a pre-fix total) is corrected to match the parent.
		//
		// Exception: a remaining-payment child owns _vanpos_remaining_payment as
		// its OWN bucket (the reconcile / discount handler sets it from the child's
		// real total, which may legitimately differ from the parent). Never
		// overwrite a child's own bucket from the parent here.
		$own_bucket_keys = array();
		if ( VanPOS_Order_Manager::is_remaining_payment( $payment_type ) ) {
			$own_bucket_keys = array( '_vanpos_remaining_payment', '_vanpos_remaining_payment_formatted' );
		}
		// Same guard as the scan: if the parent's remaining_payment is negative
		// (over-refund artefact), never write that negative value into any child
		// regardless of type. The negative balance is only meaningful on the parent.
		$parent_remaining_raw = $parent->get_meta( '_vanpos_remaining_payment' );
		if ( is_numeric( $parent_remaining_raw ) && (float) $parent_remaining_raw < 0
			&& ! in_array( '_vanpos_remaining_payment', $own_bucket_keys, true ) ) {
			$own_bucket_keys[] = '_vanpos_remaining_payment';
			$own_bucket_keys[] = '_vanpos_remaining_payment_formatted';
		}
		foreach ( self::EMAIL_META_KEYS as $key ) {
			if ( in_array( $key, $own_bucket_keys, true ) ) {
				continue;
			}
			$child_value  = $order->get_meta( $key );
			$parent_value = $parent->get_meta( $key );

			if ( $parent_value === '' || $parent_value === false ) {
				continue; // Nothing authoritative to copy.
			}
			$child_missing  = ( $child_value === '' || $child_value === false );
			$child_mismatch = ( ! $child_missing && (string) $child_value !== (string) $parent_value );
			if ( $child_missing ) {
				$order->update_meta_data( $key, $parent_value );
				$fixed[] = $key;
			} elseif ( $child_mismatch ) {
				$order->update_meta_data( $key, $parent_value );
				$fixed[] = $key . ':synced';
			}
		}

		// Camper name: also OVERWRITE if child has a value that doesn't match
		// the parent's (now-corrected) value. Covers WPML mismatches and
		// inconsistencies between parent and child orders.
		$child_camper  = $order->get_meta( '_vanpos_camper_name' );
		$parent_camper = $parent->get_meta( '_vanpos_camper_name' );
		if ( $parent_camper && $child_camper !== $parent_camper ) {
			$order->update_meta_data( '_vanpos_camper_name', $parent_camper );
			if ( ! in_array( '_vanpos_camper_name', $fixed, true ) ) {
				$fixed[] = '_vanpos_camper_name:corrected';
			}
		}

		// Generate formatted due date
		$due_date = $order->get_meta( '_payment_due_date' );
		if ( $due_date && ! $order->get_meta( '_payment_due_date_formatted' ) ) {
			$order->update_meta_data( '_payment_due_date_formatted', VanPOS_Order_Manager::format_meta_date( $due_date ) );
			$fixed[] = '_payment_due_date_formatted';
		}

		// Extension orders: backfill amount meta and due date if missing.
		if ( 'extension' === $payment_type ) {
			$ext_amount = (string) $order->get_meta( '_vanpos_extension_amount' );
			if ( '' === $ext_amount ) {
				// Derive from the order total — that is the extension charge.
				$total = (float) $order->get_total();
				if ( $total > 0 ) {
					$order->update_meta_data( '_vanpos_extension_amount', round( $total, 2 ) );
					$fixed[] = '_vanpos_extension_amount';
					$ext_amount = (string) round( $total, 2 );
				}
			}
			if ( '' !== $ext_amount && '' === (string) $order->get_meta( '_vanpos_extension_amount_formatted' ) ) {
				$order->update_meta_data( '_vanpos_extension_amount_formatted', VanPOS_Order_Manager::format_price( (float) $ext_amount ) );
				$fixed[] = '_vanpos_extension_amount_formatted';
			}
			if ( ! $due_date && class_exists( 'VanPOS_Functions' ) ) {
				// Use the pickup date on the extension order (copied from the parent).
				$pickup_date = (string) $order->get_meta( '_vanpos_pickup_date' );
				if ( $pickup_date ) {
					$ext_due = VanPOS_Functions::calculate_due_date_from_pickup( $pickup_date, 'remaining' );
					if ( $ext_due ) {
						$order->update_meta_data( '_payment_due_date', $ext_due );
						$order->update_meta_data( '_vanpos_due_date', $ext_due );
						$order->update_meta_data( '_payment_due_date_formatted', VanPOS_Order_Manager::format_meta_date( $ext_due ) );
						$fixed[] = '_payment_due_date';
					}
				}
			}
		}

		// Copy missing time meta from parent, then normalize legacy slot labels.
		$child_pickup_time = $order->get_meta( '_vanpos_pickup_time' );
		if ( $child_pickup_time === '' || $child_pickup_time === false ) {
			$parent_pickup_time = $parent->get_meta( '_vanpos_pickup_time' );
			if ( $parent_pickup_time !== '' && $parent_pickup_time !== false ) {
				$child_pickup_time = $parent_pickup_time;
				$order->update_meta_data( '_vanpos_pickup_time', $child_pickup_time );
				$fixed[] = '_vanpos_pickup_time';
			} else {
				// Parent is also empty — fall back to admin-configured default.
				$default_pickup = self::get_default_time( 'vanpos_pickup_time', '15:00' );
				if ( '' !== $default_pickup ) {
					$child_pickup_time = $default_pickup;
					$order->update_meta_data( '_vanpos_pickup_time', $child_pickup_time );
					$fixed[] = '_vanpos_pickup_time';
				}
			}
		}
		$child_pickup_time_norm = self::normalize_time_value( $child_pickup_time, 'vanpos_pickup_time', '15:00' );
		if ( '' !== $child_pickup_time && $child_pickup_time_norm !== $child_pickup_time ) {
			$order->update_meta_data( '_vanpos_pickup_time', $child_pickup_time_norm );
			if ( ! in_array( '_vanpos_pickup_time', $fixed, true ) ) {
				$fixed[] = '_vanpos_pickup_time';
			}
		}

		$child_return_time = $order->get_meta( '_vanpos_return_time' );
		if ( $child_return_time === '' || $child_return_time === false ) {
			$parent_return_time = $parent->get_meta( '_vanpos_return_time' );
			if ( $parent_return_time !== '' && $parent_return_time !== false ) {
				$child_return_time = $parent_return_time;
				$order->update_meta_data( '_vanpos_return_time', $child_return_time );
				$fixed[] = '_vanpos_return_time';
			} else {
				$default_return = self::get_default_time( 'vanpos_return_time', '11:00' );
				if ( '' !== $default_return ) {
					$child_return_time = $default_return;
					$order->update_meta_data( '_vanpos_return_time', $child_return_time );
					$fixed[] = '_vanpos_return_time';
				}
			}
		}
		$child_return_time_norm = self::normalize_time_value( $child_return_time, 'vanpos_return_time', '11:00' );
		if ( '' !== $child_return_time && $child_return_time_norm !== $child_return_time ) {
			$order->update_meta_data( '_vanpos_return_time', $child_return_time_norm );
			if ( ! in_array( '_vanpos_return_time', $fixed, true ) ) {
				$fixed[] = '_vanpos_return_time';
			}
		}

		// _is_short_term_booking: only belongs on rental payment orders (remaining/deposit),
		// NOT on security_deposit orders (which use _is_short_term_deposit instead).
		$payment_type = $order->get_meta( '_vanpos_payment_type' );
		if ( $payment_type === 'security_deposit' ) {
			// Remove if wrongly present
			$child_stb = $order->get_meta( '_is_short_term_booking' );
			if ( $child_stb !== '' && $child_stb !== false ) {
				$order->delete_meta_data( '_is_short_term_booking' );
				$fixed[] = '_is_short_term_booking:removed';
			}
		} else {
			// Add if missing
			$child_stb  = $order->get_meta( '_is_short_term_booking' );
			$parent_stb = $parent->get_meta( '_is_short_term_booking' );
			if ( ( $child_stb === '' || $child_stb === false ) && $parent_stb !== '' && $parent_stb !== false ) {
				$order->update_meta_data( '_is_short_term_booking', $parent_stb );
				$fixed[] = '_is_short_term_booking';
			}
		}

		// Remove _is_short_term_deposit from non-security_deposit orders (wrongly assigned)
		if ( $payment_type !== 'security_deposit' ) {
			$std = $order->get_meta( '_is_short_term_deposit' );
			if ( $std !== '' && $std !== false ) {
				$order->delete_meta_data( '_is_short_term_deposit' );
				$fixed[] = '_is_short_term_deposit:removed';
			}
		}

		// Normalize legacy time slot labels on line item meta.
		foreach ( $order->get_items() as $item ) {
			$item_changed = false;
			$item_pickup = $item->get_meta( 'vanpos_pickup_time' );
			if ( self::is_legacy_slot_label( $item_pickup ) ) {
				$item->update_meta_data( 'vanpos_pickup_time', self::normalize_time_value( $item_pickup, 'vanpos_pickup_time', '15:00' ) );
				$item_changed = true;
			}
			$item_return = $item->get_meta( 'vanpos_return_time' );
			if ( self::is_legacy_slot_label( $item_return ) ) {
				$item->update_meta_data( 'vanpos_return_time', self::normalize_time_value( $item_return, 'vanpos_return_time', '11:00' ) );
				$item_changed = true;
			}
			if ( $item_changed ) {
				$item->save();
				if ( ! in_array( 'item_meta:time', $fixed, true ) ) {
					$fixed[] = 'item_meta:time';
				}
			}
		}

		// Backfill missing Kestrel rental keys on line items of non-security-deposit
		// children (remaining payment orders). Source the values from the child's
		// order meta (populated by change-manager / order creation), then fall back
		// to the parent's order meta.
		if ( $payment_type !== 'security_deposit' ) {
			$child_pickup = (string) $order->get_meta( '_vanpos_pickup_date' );
			$child_return = (string) $order->get_meta( '_vanpos_return_date' );
			if ( '' === $child_pickup ) {
				$child_pickup = (string) $parent->get_meta( '_vanpos_pickup_date' );
			}
			if ( '' === $child_return ) {
				$child_return = (string) $parent->get_meta( '_vanpos_return_date' );
			}

			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				$item_changed = false;

				if ( '' === (string) $item->get_meta( 'wcrp_rental_products_rent_from' ) && '' !== $child_pickup ) {
					$item->update_meta_data( 'wcrp_rental_products_rent_from', $child_pickup );
					$item_changed = true;
				}
				if ( '' === (string) $item->get_meta( 'wcrp_rental_products_rent_to' ) && '' !== $child_return ) {
					$item->update_meta_data( 'wcrp_rental_products_rent_to', $child_return );
					$item_changed = true;
				}

				if ( $item_changed ) {
					$item->save();
					if ( ! in_array( 'item_meta:kestrel_keys', $fixed, true ) ) {
						$fixed[] = 'item_meta:kestrel_keys';
					}
				}
			}

			// Backfill missing vanpos_* item meta on the child's line items.
			// Sources from the child's own _vanpos_* order meta first, then
			// falls back to the parent's. Helper internally skips
			// security_deposit children (caller's outer if already guards
			// this, but defense-in-depth doesn't hurt).
			if ( self::backfill_vanpos_item_meta( $order, $parent ) ) {
				$fixed[] = 'item_meta:vanpos_keys';
			}
		}

		// Backfill _vanpos_order_type_detected if missing. Applies to all
		// child types (deposit, remaining, extension). Computed from
		// _vanpos_order_type + _vanpos_payment_type.
		if ( '' === (string) $order->get_meta( '_vanpos_order_type_detected' ) ) {
			$detected = self::detect_order_type_value( $order );
			if ( $detected ) {
				$order->update_meta_data( '_vanpos_order_type_detected', $detected );
				$fixed[] = '_vanpos_order_type_detected';
			}
		}

		// Delete stray underscore-prefixed item meta. Applies to all child
		// types including security_deposit — the deposit product item
		// shouldn't carry rental meta under either naming convention.
		$stray_keys  = self::stray_item_meta_keys();
		$any_deleted = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$item_changed = false;
			foreach ( $stray_keys as $sk ) {
				if ( '' !== (string) $item->get_meta( $sk ) ) {
					$item->delete_meta_data( $sk );
					$item_changed = true;
				}
			}
			if ( $item_changed ) {
				$item->save();
				$any_deleted = true;
			}
		}
		if ( $any_deleted ) {
			$fixed[] = 'item_meta:stray_keys';
		}

		// Round sub-cent float residue on money meta (same helper as the parent
		// path). Item meta is saved inside the helper; order meta is persisted
		// by the save() below.
		if ( ! empty( self::money_round_changes( $order, true ) ) ) {
			$fixed[] = 'item_meta:round_money';
		}

		if ( ! empty( $fixed ) ) {
			$order->add_order_note(
				sprintf( 'VanPOS Meta Backfill: added %s (from parent #%d)', implode( ', ', $fixed ), $parent_id ),
				false,
				false
			);
			$order->save();
		}

		// Regenerate custom title if camper name was changed, title is missing,
		// or the existing title doesn't contain the stored camper name.
		$camper_changed = in_array( '_vanpos_camper_name', $fixed, true )
			|| in_array( '_vanpos_camper_name:corrected', $fixed, true );
		$title_missing  = ! $order->get_meta( '_vanpos_custom_order_title' );
		$title_stale    = false;
		if ( ! $title_missing ) {
			$cur_camper = $order->get_meta( '_vanpos_camper_name' );
			$cur_title  = $order->get_meta( '_vanpos_custom_order_title' );
			if ( $cur_camper && strpos( $cur_title, $cur_camper ) === false ) {
				$title_stale = true;
			}
		}

		if ( ( $camper_changed || $title_missing || $title_stale ) && class_exists( 'VanPOS_Order_Title_Manager' ) ) {
			if ( VanPOS_Order_Title_Manager::regenerate_title( $order ) ) {
				$fixed[] = '_vanpos_custom_order_title:regenerated';
			}
		}

		// Reconcile a pre-handler coupon on this child (recompute its own bucket
		// from its post-discount total; sync_remaining also re-syncs the parent).
		// Same logic as the live handler. Reload so it sees persisted edits above.
		if ( class_exists( 'VanPOS_Discount_Manager' ) ) {
			$reload = wc_get_order( $order->get_id() );
			if ( $reload && VanPOS_Discount_Manager::reconcile( $reload ) ) {
				$fixed[] = 'split_reconcile';
			}
		}

		return $fixed;
	}

	/**
	 * Render admin page.
	 */
	public static function render_page() {
		$nonce = wp_create_nonce( 'vanpos_backfill' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VanPOS Meta Backfill', 'vanjorn-rental-pos' ); ?></h1>
			<p><?php esc_html_e( 'Scan for rental orders and child payment orders that are missing email-friendly meta (camper name, formatted dates, formatted prices), have inconsistent camper names between parent and child, or need their custom order title regenerated.', 'vanjorn-rental-pos' ); ?></p>

			<div id="vanpos-backfill-actions" style="margin: 20px 0;">
				<button id="vanpos-scan-btn" class="button button-primary button-hero"><?php esc_html_e( 'Scan Orders', 'vanjorn-rental-pos' ); ?></button>
				<button id="vanpos-fix-all-btn" class="button button-hero" style="display:none;"><?php esc_html_e( 'Fix All', 'vanjorn-rental-pos' ); ?></button>
				<span id="vanpos-backfill-status" style="margin-left: 15px; font-size: 14px;"></span>
			</div>

			<div id="vanpos-backfill-progress" style="display:none; margin: 20px 0; max-width: 600px;">
				<div style="background: #f0f0f0; border-radius: 4px; overflow: hidden; height: 24px;">
					<div id="vanpos-progress-bar" style="background: #3858e9; height: 100%; width: 0%; transition: width 0.3s;"></div>
				</div>
				<p id="vanpos-progress-text" style="margin: 5px 0;">0 / 0</p>
			</div>

			<div id="vanpos-scan-results" style="display:none;">
				<h2 id="vanpos-parents-heading" style="display:none;"><?php esc_html_e( 'Parent Orders — Issues', 'vanjorn-rental-pos' ); ?></h2>
				<table id="vanpos-parents-table" class="widefat fixed striped" style="display:none; margin-bottom: 30px;">
					<thead>
						<tr>
							<th style="width:80px;"><?php esc_html_e( 'Order', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'Customer', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Pickup', 'vanjorn-rental-pos' ); ?></th>
							<th><?php esc_html_e( 'Issues', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Status', 'vanjorn-rental-pos' ); ?></th>
						</tr>
					</thead>
					<tbody id="vanpos-parents-body"></tbody>
				</table>

				<h2 id="vanpos-children-heading" style="display:none;"><?php esc_html_e( 'Child Orders — Issues', 'vanjorn-rental-pos' ); ?></h2>
				<table id="vanpos-children-table" class="widefat fixed striped" style="display:none; margin-bottom: 30px;">
					<thead>
						<tr>
							<th style="width:80px;"><?php esc_html_e( 'Order', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Parent', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Type', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'Customer', 'vanjorn-rental-pos' ); ?></th>
							<th><?php esc_html_e( 'Issues', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Status', 'vanjorn-rental-pos' ); ?></th>
						</tr>
					</thead>
					<tbody id="vanpos-children-body"></tbody>
				</table>
			</div>

			<div id="vanpos-backfill-log" style="display:none; margin-top: 20px;">
				<h2><?php esc_html_e( 'Log', 'vanjorn-rental-pos' ); ?></h2>
				<div id="vanpos-log-entries" style="background: #fff; border: 1px solid #c3c4c7; padding: 12px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 13px;"></div>
			</div>
		</div>

		<script>
		(function($) {
			var nonce     = '<?php echo esc_js( $nonce ); ?>';
			var ajaxUrl   = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var scanData  = null;
			var fixQueue  = [];
			var fixTotal  = 0;
			var fixDone   = 0;
			var fixErrors = 0;

			var $status   = $('#vanpos-backfill-status');
			var $scanBtn  = $('#vanpos-scan-btn');
			var $fixBtn   = $('#vanpos-fix-all-btn');
			var $progress = $('#vanpos-backfill-progress');
			var $bar      = $('#vanpos-progress-bar');
			var $pText    = $('#vanpos-progress-text');
			var $results  = $('#vanpos-scan-results');
			var $log      = $('#vanpos-backfill-log');
			var $logEntries = $('#vanpos-log-entries');

			function log(msg) {
				$log.show();
				$logEntries.prepend('<div>' + msg + '</div>');
			}

			function orderLink(id) {
				return '<a href="' + ajaxUrl.replace('admin-ajax.php', 'admin.php?page=wc-orders&action=edit&id=' + id) + '" target="_blank">#' + id + '</a>';
			}

			// Scan
			$scanBtn.on('click', function() {
				$scanBtn.prop('disabled', true).text('Scanning...');
				$status.text('');
				$results.hide();
				$fixBtn.hide();
				$logEntries.empty();

				$.post(ajaxUrl, { action: 'vanpos_backfill_scan', nonce: nonce }, function(res) {
					$scanBtn.prop('disabled', false).text('Scan Orders');
					if (!res.success) {
						$status.text('Error: ' + res.data);
						return;
					}

					scanData = res.data;
					var pCount = scanData.parents_missing.length;
					var cCount = scanData.children_missing.length;
					var total  = pCount + cCount;

					$status.text(total === 0
						? 'All orders have complete meta — nothing to fix.'
						: total + ' order(s) need fixing (' + pCount + ' parent, ' + cCount + ' child).'
					);

					$results.show();
					renderParents(scanData.parents_missing);
					renderChildren(scanData.children_missing);

					if (total > 0) {
						$fixBtn.show();
					}
				}).fail(function() {
					$scanBtn.prop('disabled', false).text('Scan Orders');
					$status.text('Request failed.');
				});
			});

			function renderParents(items) {
				var $h = $('#vanpos-parents-heading');
				var $t = $('#vanpos-parents-table');
				var $b = $('#vanpos-parents-body').empty();
				if (!items.length) { $h.hide(); $t.hide(); return; }
				$h.show(); $t.show();
				$.each(items, function(i, p) {
					$b.append('<tr data-id="' + p.order_id + '">'
						+ '<td>' + orderLink(p.order_id) + '</td>'
						+ '<td>' + esc(p.customer) + '</td>'
						+ '<td>' + esc(p.pickup_date) + '</td>'
						+ '<td>' + p.missing_keys.map(formatKey).join(', ') + formatChanges(p.changes) + '</td>'
						+ '<td class="fix-status">—</td>'
						+ '</tr>');
				});
			}

			function renderChildren(items) {
				var $h = $('#vanpos-children-heading');
				var $t = $('#vanpos-children-table');
				var $b = $('#vanpos-children-body').empty();
				if (!items.length) { $h.hide(); $t.hide(); return; }
				$h.show(); $t.show();
				$.each(items, function(i, c) {
					$b.append('<tr data-id="' + c.order_id + '">'
						+ '<td>' + orderLink(c.order_id) + '</td>'
						+ '<td>' + orderLink(c.parent_id) + '</td>'
						+ '<td>' + esc(c.payment_type) + '</td>'
						+ '<td>' + esc(c.customer) + '</td>'
						+ '<td>' + c.missing_keys.map(formatKey).join(', ') + formatChanges(c.changes) + '</td>'
						+ '<td class="fix-status">—</td>'
						+ '</tr>');
				});
			}

			// Fix All
			$fixBtn.on('click', function() {
				if (!scanData) return;
				$fixBtn.prop('disabled', true);
				$scanBtn.prop('disabled', true);

				fixQueue = [];
				$.each(scanData.parents_missing, function(i, p) {
					fixQueue.push({ order_id: p.order_id, fix_type: 'parent' });
				});
				$.each(scanData.children_missing, function(i, c) {
					fixQueue.push({ order_id: c.order_id, fix_type: 'child' });
				});

				fixTotal  = fixQueue.length;
				fixDone   = 0;
				fixErrors = 0;

				$progress.show();
				$bar.css('width', '0%');
				$pText.text('0 / ' + fixTotal);

				processNext();
			});

			function processNext() {
				if (fixQueue.length === 0) {
					$status.text('Done! Fixed ' + (fixDone - fixErrors) + ' order(s)' + (fixErrors ? ', ' + fixErrors + ' error(s).' : '.'));
					$fixBtn.hide();
					$scanBtn.prop('disabled', false);
					return;
				}

				var item = fixQueue.shift();
				$.post(ajaxUrl, {
					action: 'vanpos_backfill_fix',
					nonce: nonce,
					order_id: item.order_id,
					fix_type: item.fix_type
				}, function(res) {
					fixDone++;
					var pct = Math.round((fixDone / fixTotal) * 100);
					$bar.css('width', pct + '%');
					$pText.text(fixDone + ' / ' + fixTotal);

					var $row = $('tr[data-id="' + item.order_id + '"]');
					if (res.success) {
						var keys = res.data.fixed;
						$row.find('.fix-status').html('<span style="color:green;">✓ ' + keys.length + '</span>');
						log('✓ #' + item.order_id + ' (' + item.fix_type + '): ' + (keys.length ? keys.join(', ') : 'no changes needed'));
					} else {
						fixErrors++;
						$row.find('.fix-status').html('<span style="color:red;">✗</span>');
						log('✗ #' + item.order_id + ': ' + res.data);
					}

					processNext();
				}).fail(function() {
					fixDone++;
					fixErrors++;
					var pct = Math.round((fixDone / fixTotal) * 100);
					$bar.css('width', pct + '%');
					$pText.text(fixDone + ' / ' + fixTotal);
					log('✗ #' + item.order_id + ': request failed');
					processNext();
				});
			}

			// wc_price() emits HTML entities (&nbsp;, &euro;). These values are
			// plugin-generated (not user input), so decode them for display rather
			// than re-escaping, which would show the raw entity text.
			function decodeEntities(s) {
				var ta = document.createElement('textarea');
				ta.innerHTML = s || '';
				return ta.value;
			}

			function formatChanges(changes) {
				if (!changes || !changes.length) return '';
				var rows = changes.map(function(ch) {
					var tag = '';
					if (ch.scope === 'parent') {
						tag = ' <em style="color:#666;">(parent #' + ch.order_id + ')</em>';
					} else if (ch.scope === 'item') {
						tag = ' <em style="color:#666;">(line: ' + esc(ch.item || '') + ')</em>';
					}
					var keyShort = ch.key.replace(/^_vanpos_/, '');
					return '<div style="font-size:11px;line-height:1.5;">'
						+ '<code>' + keyShort + '</code>' + tag + ': '
						+ '<span style="color:#b32d2e;">' + esc(decodeEntities(ch.old)) + '</span>'
						+ ' &rarr; '
						+ '<span style="color:#1a7f37;font-weight:600;">' + esc(decodeEntities(ch.new)) + '</span>'
						+ '</div>';
				}).join('');
				return '<div style="margin-top:6px;padding:6px 8px;background:#fbfbfb;border-left:3px solid #d63638;">'
					+ '<strong style="font-size:11px;display:block;margin-bottom:3px;">Proposed changes:</strong>'
					+ rows + '</div>';
			}

			function formatKey(key) {
				var labels = {
					'_vanpos_camper_name':                  '<code>camper_name</code> <em>(missing)</em>',
					'_vanpos_camper_name:wpml_mismatch':    '<code>camper_name</code> <em style="color:#b32d2e;">(WPML mismatch)</em>',
					'_vanpos_camper_name:parent_mismatch':  '<code>camper_name</code> <em style="color:#b32d2e;">(differs from parent)</em>',
					'_vanpos_camper_name:item_mismatch':    '<code>camper_name</code> <em style="color:#b32d2e;">(differs from line item)</em>',
					'_vanpos_camper_name:corrected':        '<code>camper_name</code> <em>(corrected)</em>',
					'_vanpos_booking_reference':            '<code>booking_reference</code> <em>(missing — will copy from parent)</em>',
					'_vanpos_custom_order_title':           '<code>order_title</code> <em>(missing)</em>',
					'_vanpos_custom_order_title:stale':     '<code>order_title</code> <em style="color:#b32d2e;">(stale — camper name wrong)</em>',
					'_vanpos_custom_order_title:regenerated':'<code>order_title</code> <em>(regenerated)</em>',
					'_is_short_term_booking:remove':        '<code>short_term_booking</code> <em>(should not exist)</em>',
					'_is_short_term_deposit:remove':        '<code>short_term_deposit</code> <em>(should not exist)</em>',
					'item_meta:kestrel_keys':               '<code>line_item: rent_from/rent_to</code> <em style="color:#b32d2e;">(missing — calendar can\'t see booking)</em>',
					'item_meta:vanpos_keys':                '<code>line_item: vanpos_pickup_date/return_date/times/days</code> <em>(missing)</em>',
					'item_meta:stray_keys':                 '<code>line_item: _vanpos_pickup_date/_return_date/_times/_days/_include_*</code> <em style="color:#b32d2e;">(underscore-prefixed strays — to delete)</em>',
					'item_meta:round_money':                '<code>money meta: original_price/deposit/remaining/total</code> <em style="color:#b32d2e;">(unrounded float — will round to 2 dp)</em>',
					'item_meta:time':                       '<code>line_item: pickup/return_time</code> <em>(legacy slot label)</em>',
					'_vanpos_order_type_detected':          '<code>_vanpos_order_type_detected</code> <em>(missing — cosmetic)</em>',
					'meta_migration:legacy':                '<code>legacy payment-split keys</code> <em style="color:#b32d2e;">(rename / consolidate / delete)</em>',
					'meta_migration:flags':                 '<code>has_remaining / has_security_deposit / sd_paid</code> <em>(seed from child orders)</em>',
					'split_reconcile':                      '<code>payment split</code> <em style="color:#b32d2e;">(stored total ≠ real order total — recompute)</em>',
					'email_meta:mismatch':                  '<code>email meta</code> <em style="color:#b32d2e;">(stale copy — differs from parent, will sync)</em>',
					'_vanpos_extension_amount':             '<code>extension_amount</code> <em>(missing — will derive from order total)</em>',
					'_vanpos_extension_amount_formatted':   '<code>extension_amount_formatted</code> <em>(missing — will format from amount)</em>',
					'_payment_due_date':                    '<code>payment_due_date</code> <em>(missing — will calculate from pickup date)</em>',
				};
				if (labels[key]) return labels[key];
				// Fallback: strip prefix and show as code
				return '<code>' + key.replace(/^_vanpos_/, '').replace(/^_/, '') + '</code>';
			}

			function esc(s) {
				return $('<span>').text(s || '').html();
			}
		})(jQuery);
		</script>
		<?php
	}
}

VanPOS_Meta_Backfill::init();
