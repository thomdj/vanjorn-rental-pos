<?php
/**
 * VanPOS meta-key registry — single source of truth for every order/item meta
 * key the plugin owns, what it is for, and whether it is safe to delete.
 *
 * WHY THIS EXISTS
 * ----------------
 * Order meta is consumed in places that are invisible to a static grep of this
 * plugin: AutomateWoo workflow rules (stored in the AW config / DB), theme
 * templates, CSV/PDF exports, reporting, and the WCRP/Kestrel rental plugin.
 * That makes "delete every key the code doesn't read" (a whitelist purge)
 * unsafe — it would silently drop keys those external consumers depend on.
 *
 * This registry inverts that: it enumerates the keys we KNOW about and marks the
 * ones that must never be auto-deleted (financial, identity, payment-state,
 * AutomateWoo, fulfilment, third-party). Anything not listed is treated as
 * PROTECTED by default (is_protected() returns true for unknown keys), so a
 * cleanup tool can only ever remove keys that are explicitly classified as
 * deprecated (migrate-then-delete) or stray (confirmed dead).
 *
 * LEVEL MATTERS
 * --------------
 * The same string can mean different things at order vs item level. The
 * canonical convention is:
 *   - ORDER level: rental + financial meta is underscore-prefixed (_vanpos_*).
 *   - ITEM  level: rental meta is NON-underscore (vanpos_pickup_date), while the
 *     financial triplet stays underscored (_vanpos_original_price, …).
 * So _vanpos_pickup_date is canonical at order level but a STRAY at item level,
 * and _vanpos_deposit_amount is canonical at item level but DEPRECATED at order
 * level. The two maps below are kept separate for exactly this reason.
 *
 * This file performs NO deletions and registers NO hooks. It is a reference the
 * backfill/cleanup tooling can consult so its delete lists never drift from the
 * canonical schema.
 *
 * @package VJ_Rental_POS
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative catalogue of VanPOS meta keys.
 */
class VanPOS_Meta_Registry {

	// Categories. The first block is PROTECTED (never auto-delete); the second
	// block is cleanup-eligible.
	const IDENTITY    = 'identity';       // Order classification / linkage / references.
	const RENTAL      = 'rental';         // Pickup/return/duration (also feeds AutomateWoo variables).
	const FINANCIAL   = 'financial';      // Stored money amounts (financial record).
	const PAYMENT     = 'payment_state';  // Paid flags, schedule, child linkage.
	const AUTOMATEWOO = 'automatewoo';    // Workflow gates read by AutomateWoo rules.
	const FULFILMENT  = 'fulfilment';     // Returned/handover state (also Kestrel).
	const AUDIT       = 'audit';          // History / change log.
	const THIRD_PARTY = 'third_party';    // Not ours (WCRP/Kestrel/WooCommerce).

	const OPTION      = 'option';         // Add-on toggles (re-derivable from items).
	const DISPLAY     = 'display';        // Formatted/cosmetic companions (re-derivable).
	const DEPRECATED  = 'deprecated';     // Superseded; migrate_local_meta migrates then deletes.
	const STRAY       = 'stray';          // Confirmed dead, zero readers — safe to delete.

	/**
	 * Categories whose keys must never be auto-deleted.
	 *
	 * @return string[]
	 */
	private static function protected_categories() {
		return array(
			self::IDENTITY,
			self::RENTAL,
			self::FINANCIAL,
			self::PAYMENT,
			self::AUTOMATEWOO,
			self::FULFILMENT,
			self::AUDIT,
			self::THIRD_PARTY,
		);
	}

	/**
	 * Order-level meta keys: key => category.
	 *
	 * @return array<string,string>
	 */
	public static function order_level() {
		return array(
			// --- Identity / structure ---------------------------------------
			'_vanpos_order_type'                  => self::IDENTITY,
			'_vanpos_payment_type'                => self::IDENTITY,
			'_vanpos_primary_order_id'            => self::IDENTITY,
			'_vanpos_admin_created'               => self::IDENTITY,   // gates auto child-order creation
			'_vanpos_booking_reference'           => self::IDENTITY,
			'_vanpos_order_base_number'           => self::IDENTITY,
			'_vanpos_vrc_order_number'            => self::IDENTITY,
			'_vanpos_order_type_detected'         => self::DISPLAY,    // derived by order-title manager
			'_vanpos_custom_order_title'          => self::DISPLAY,    // derived by order-title manager

			// --- Rental data (canonical at order level; AW reads these) ------
			'_vanpos_pickup_date'                 => self::RENTAL,
			'_vanpos_return_date'                 => self::RENTAL,
			'_vanpos_pickup_time'                 => self::RENTAL,
			'_vanpos_return_time'                 => self::RENTAL,
			'_vanpos_rental_days'                 => self::RENTAL,
			'_vanpos_rental_nights'               => self::RENTAL,
			'_vanpos_due_date'                    => self::RENTAL,     // AutomateWoo date variable source
			// *_formatted companions + camper name are AutomateWoo email merge fields.
			'_vanpos_pickup_date_formatted'       => self::AUTOMATEWOO,
			'_vanpos_return_date_formatted'       => self::AUTOMATEWOO,

			// --- Options -----------------------------------------------------
			'_vanpos_include_dog'                 => self::OPTION,
			'_vanpos_include_cleaning'            => self::OPTION,

			// --- Financial (money — financial record) ------------------------
			'_vanpos_total_price'                 => self::FINANCIAL,
			'_vanpos_initial_payment'             => self::FINANCIAL,
			'_vanpos_remaining_payment'           => self::FINANCIAL,
			'_vanpos_security_deposit_payment'    => self::FINANCIAL,
			'_vanpos_extension_amount'            => self::FINANCIAL,
			'_vanpos_price_per_day'               => self::FINANCIAL,
			'_vanpos_price_overridden'            => self::FINANCIAL,
			'_vanpos_no_vat'                      => self::FINANCIAL,  // affects tax handling
			'_vanpos_total_price_formatted'       => self::AUTOMATEWOO,
			'_vanpos_initial_payment_formatted'   => self::AUTOMATEWOO,
			'_vanpos_remaining_payment_formatted' => self::AUTOMATEWOO,
			'_vanpos_security_deposit_payment_formatted' => self::AUTOMATEWOO,
			'_vanpos_extension_amount_formatted'  => self::AUTOMATEWOO,

			// --- Payment state / linkage -------------------------------------
			'_vanpos_initial_payment_paid'        => self::PAYMENT,
			'_vanpos_remaining_payment_paid'      => self::PAYMENT,
			'_vanpos_security_deposit_paid'       => self::PAYMENT,
			'_vanpos_order_has_remaining_payment' => self::PAYMENT,
			'_vanpos_order_has_security_deposit'  => self::PAYMENT,
			'_vanpos_payment_schedule'            => self::PAYMENT,
			'_vanpos_payment_description'         => self::PAYMENT,
			'_vanpos_security_deposit_order_id'   => self::PAYMENT,
			'_vanpos_security_deposit_due_date'   => self::PAYMENT,

			// --- AutomateWoo workflow gates (DO NOT DELETE) ------------------
			// Heavily integrated into AutomateWoo workflows; read by AW rules,
			// not by plugin PHP, so they look "unused" to static analysis.
			'_payment_window_open'                => self::AUTOMATEWOO,
			'_remaining_sent'                     => self::AUTOMATEWOO,
			'_security_deposit_sent'              => self::AUTOMATEWOO,
			'_payment_amount_type'                => self::AUTOMATEWOO,
			'_payment_due_date'                   => self::AUTOMATEWOO,
			'_payment_due_date_formatted'         => self::AUTOMATEWOO,
			'_is_short_term_booking'              => self::AUTOMATEWOO,
			'_is_short_term_deposit'              => self::AUTOMATEWOO,

			// --- Audit -------------------------------------------------------
			'_vanpos_date_change_history'         => self::AUDIT,

			// --- Display / email --------------------------------------------
			'_vanpos_camper_name'                 => self::AUTOMATEWOO,

			// --- Deprecated (migrate_local_meta migrates then deletes) -------
			'_vanpos_deposit_amount'              => self::DEPRECATED, // order level only; canonical at ITEM level
			'_vanpos_security_deposit_amount'     => self::DEPRECATED, // -> _vanpos_security_deposit_payment
			'_vanpos_deposits_order_has_deposit'  => self::DEPRECATED,
			'_vanpos_deposits_deposit_paid'       => self::DEPRECATED,
			'_vanpos_deposits_second_payment_paid' => self::DEPRECATED,
			'_vanpos_deposits_payment_schedule'   => self::DEPRECATED,
			'_vanpos_deposits_deposit_amount'     => self::DEPRECATED,
			'_vanpos_deposits_second_payment'     => self::DEPRECATED,
			'_vanpos_deposits_deposit_breakdown'  => self::DEPRECATED,
		);
	}

	/**
	 * Item-level (line-item) meta keys: key => category.
	 *
	 * @return array<string,string>
	 */
	public static function item_level() {
		return array(
			// --- Canonical rental meta (NON-underscore at item level) --------
			'vanpos_pickup_date'                   => self::RENTAL,
			'vanpos_return_date'                   => self::RENTAL,
			'vanpos_pickup_time'                   => self::RENTAL,
			'vanpos_return_time'                   => self::RENTAL,
			'vanpos_rental_days'                   => self::RENTAL,
			'vanpos_rental_nights'                 => self::RENTAL,
			'vanpos_include_dog'                   => self::OPTION,
			'vanpos_include_cleaning'              => self::OPTION,

			// --- Financial triplet (underscored IS canonical at item level) --
			'_vanpos_original_price'               => self::FINANCIAL,
			'_vanpos_deposit_amount'               => self::FINANCIAL,
			'_vanpos_remaining_amount'             => self::FINANCIAL,
			'_vanpos_price_per_day'                => self::FINANCIAL,

			// --- Fulfilment / returns (also drives Kestrel availability) -----
			'_vanpos_vehicle_returned'             => self::FULFILMENT,
			'_vanpos_vehicle_returned_at'          => self::FULFILMENT,

			// --- Third-party item meta (never delete) ------------------------
			'wcrp_rental_products_rent_from'       => self::THIRD_PARTY,
			'wcrp_rental_products_rent_to'         => self::THIRD_PARTY,
			'wcrp_rental_products_rental_duration' => self::THIRD_PARTY,
			'_reduced_stock'                       => self::THIRD_PARTY, // WooCommerce internal

			// --- Stray underscore copies (confirmed dead — zero item readers) -
			// Canonical item rental meta is NON-underscore; these underscore
			// copies are residue from older POS code paths. Verified no item-level
			// reader (PDF path dual-reads canonical-first). Safe to delete.
			'_vanpos_pickup_date'                  => self::STRAY,
			'_vanpos_return_date'                  => self::STRAY,
			'_vanpos_pickup_time'                  => self::STRAY,
			'_vanpos_return_time'                  => self::STRAY,
			'_vanpos_rental_days'                  => self::STRAY,
			'_vanpos_rental_nights'                => self::STRAY, // NOTE: backfill stray list is missing this one
			'_vanpos_include_dog'                  => self::STRAY,
			'_vanpos_include_cleaning'             => self::STRAY,
		);
	}

	/**
	 * Whether a key must be preserved (never auto-deleted) at the given scope.
	 * Unknown keys are PROTECTED by default — a cleanup tool may only remove a
	 * key it can positively classify as deprecated or stray.
	 *
	 * @param string $key   Meta key.
	 * @param string $scope 'order' or 'item'.
	 * @return bool
	 */
	public static function is_protected( $key, $scope = 'order' ) {
		$map = ( 'item' === $scope ) ? self::item_level() : self::order_level();
		if ( ! isset( $map[ $key ] ) ) {
			return true; // Unknown key: conservative default.
		}
		return in_array( $map[ $key ], self::protected_categories(), true );
	}

	/**
	 * Order-level keys that are deprecated (the migration should map+delete them).
	 *
	 * @return string[]
	 */
	public static function deprecated_order_keys() {
		return array_keys(
			array_filter(
				self::order_level(),
				static function ( $cat ) {
					return self::DEPRECATED === $cat;
				}
			)
		);
	}

	/**
	 * Item-level keys that are stray/dead (safe to delete). This is the
	 * authoritative list; VanPOS_Admin_Meta_Backfill::stray_item_meta_keys()
	 * should be a subset of it (currently it omits _vanpos_rental_nights).
	 *
	 * @return string[]
	 */
	public static function stray_item_keys() {
		return array_keys(
			array_filter(
				self::item_level(),
				static function ( $cat ) {
					return self::STRAY === $cat;
				}
			)
		);
	}
}
