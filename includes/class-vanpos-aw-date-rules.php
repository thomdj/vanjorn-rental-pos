<?php
/**
 * VanPOS AutomateWoo Date Rules + Daily Trigger
 *
 * Provides four custom date-based rules reading VanPOS order meta:
 *   - Order - Pickup Date      (_vanpos_pickup_date)
 *   - Order - Return Date      (_vanpos_return_date)
 *   - Order - VanPOS Due Date  (_vanpos_due_date)
 *   - Order - Payment Due Date (_payment_due_date)
 *
 * And one custom trigger:
 *   - VanPOS - Daily Pending Payment Check
 *
 * The trigger fires once daily at 09:00 site time, iterating over all
 * pending child payment orders (_vanpos_order_type = payment_order).
 * Use this trigger together with the date rules above to create
 * "send X days before pickup" style workflows without relying on
 * AutomateWoo's "Schedule with a variable" timing — which does not
 * work reliably with meta-based date variables on this site.
 *
 * IMPORTANT — loading order:
 *   This file is included from the main plugin bootstrap, which
 *   runs during WordPress plugin load *before* AutomateWoo's
 *   classes are available. We therefore register the AW filters
 *   immediately but defer class definitions until the filters
 *   actually fire (by which point AW is loaded). This matches
 *   AutomateWoo's own documented pattern of including the class
 *   file inside the filter callback.
 *
 * INTEGRATION: Load in main plugin bootstrap (vanjorn-rental-pos.php):
 *   require_once VANPOS_PLUGIN_DIR . 'includes/class-vanpos-aw-date-rules.php';
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================
// FILTER REGISTRATION — runs at file load time
// ============================================================

add_filter( 'automatewoo/rules/includes', 'vanpos_aw_register_rules' );
add_filter( 'automatewoo/triggers', 'vanpos_aw_register_trigger' );

/**
 * Register our date-based rules with AutomateWoo.
 *
 * @param array $rules Existing AW rules map (key => class name).
 * @return array
 */
function vanpos_aw_register_rules( $rules ) {
	vanpos_aw_maybe_define_classes();

	$rules['vanpos_order_pickup_date']      = 'VanPOS_AW_Rule_Pickup_Date';
	$rules['vanpos_order_return_date']      = 'VanPOS_AW_Rule_Return_Date';
	$rules['vanpos_order_vanpos_due_date']  = 'VanPOS_AW_Rule_VanPOS_Due_Date';
	$rules['vanpos_order_payment_due_date'] = 'VanPOS_AW_Rule_Payment_Due_Date';

	return $rules;
}

/**
 * Register our daily trigger with AutomateWoo.
 *
 * @param array $triggers Existing AW triggers map (key => class name).
 * @return array
 */
function vanpos_aw_register_trigger( $triggers ) {
	vanpos_aw_maybe_define_classes();

	$triggers['vanpos_daily_pending_check'] = 'VanPOS_AW_Trigger_Daily_Pending_Check';

	return $triggers;
}

/**
 * Define the rule + trigger classes, but only once and only when
 * AutomateWoo's base classes are available. This is called from
 * inside the AW filter callbacks, so AW is guaranteed to be loaded.
 */
function vanpos_aw_maybe_define_classes() {
	static $defined = false;
	if ( $defined ) {
		return;
	}

	if ( ! class_exists( 'AutomateWoo\Rules\Abstract_Date' ) || ! class_exists( 'AutomateWoo\Trigger' ) ) {
		return;
	}

	// --------------------------------------------------------
	// Abstract base for VanPOS meta-date rules
	// --------------------------------------------------------
	abstract class VanPOS_AW_Rule_Abstract_Meta_Date extends \AutomateWoo\Rules\Abstract_Date {

		/** @var string Data item type — always order for VanPOS. */
		public $data_item = 'order';

		/** @var string Meta key to read. Override in subclass. */
		protected $meta_key = '';

		/** @var string Rule title. Override in subclass. */
		protected $rule_title = '';

		public function __construct() {
			// Allow both past and future comparisons — pickup/return/due dates
			// can legitimately be either side of "now", so we enable both
			// "is in the last X" and "is in the next X" sets of options.
			// Note: AW uses the typo "comparision" in both property names.
			$this->has_is_past_comparision   = true;
			$this->has_is_future_comparision = true;
			parent::__construct();
		}

		public function init() {
			$this->title = $this->rule_title;
			$this->group = __( 'Order', 'vanjorn-rental-pos' );
		}

		/**
		 * Validates the rule against the stored meta date.
		 *
		 * @param \WC_Order  $order
		 * @param string     $compare
		 * @param array|null $value
		 * @return bool
		 */
		public function validate( $order, $compare, $value = null ) {
			if ( ! $order instanceof \WC_Order ) {
				return false;
			}

			$raw = $order->get_meta( $this->meta_key );
			if ( empty( $raw ) ) {
				return $this->validate_date( $compare, $value, null );
			}

			// aw_normalize_date() is AW's own helper — converts Y-m-d / other
			// strtotime-parseable strings into a DateTime with site timezone.
			$date = aw_normalize_date( $raw );

			return $this->validate_date( $compare, $value, $date );
		}
	}

	// --------------------------------------------------------
	// Concrete rules — one per meta field
	// --------------------------------------------------------
	class VanPOS_AW_Rule_Pickup_Date extends VanPOS_AW_Rule_Abstract_Meta_Date {
		protected $meta_key   = '_vanpos_pickup_date';
		protected $rule_title = 'Order - Pickup Date';
	}

	class VanPOS_AW_Rule_Return_Date extends VanPOS_AW_Rule_Abstract_Meta_Date {
		protected $meta_key   = '_vanpos_return_date';
		protected $rule_title = 'Order - Return Date';
	}

	class VanPOS_AW_Rule_VanPOS_Due_Date extends VanPOS_AW_Rule_Abstract_Meta_Date {
		protected $meta_key   = '_vanpos_due_date';
		protected $rule_title = 'Order - VanPOS Due Date';
	}

	class VanPOS_AW_Rule_Payment_Due_Date extends VanPOS_AW_Rule_Abstract_Meta_Date {
		protected $meta_key   = '_payment_due_date';
		protected $rule_title = 'Order - Payment Due Date';
	}

	// --------------------------------------------------------
	// Daily trigger
	// --------------------------------------------------------
	class VanPOS_AW_Trigger_Daily_Pending_Check extends \AutomateWoo\Trigger {

		/**
		 * Data items this trigger provides to rules and actions.
		 * We supply both 'order' and 'customer' so the full range of
		 * AW variables (customer.email, customer.first_name, etc.)
		 * is available in workflow actions.
		 *
		 * @var array
		 */
		public $supplied_data_items = array( 'order', 'customer' );

		public function init() {
			$this->title       = __( 'VanPOS - Daily Pending Payment Check', 'vanjorn-rental-pos' );
			$this->group       = __( 'VanPOS', 'vanjorn-rental-pos' );
			$this->description = __(
				'Fires once daily at 09:00 for every pending or on-hold child payment order. Combine with the VanPOS date rules to send reminders relative to pickup/return/due dates.',
				'vanjorn-rental-pos'
			);
		}

		public function load_fields() {
			// No configurable fields — the date math is done in the rules.
		}

		/**
		 * AW hooks its triggers to WP actions via this method. We don't
		 * hook anything here because firing is driven by our daily cron
		 * handler — see vanpos_aw_run_daily_check() below.
		 */
		public function register_hooks() {
			// Intentionally empty.
		}

		/**
		 * Validate that the workflow's data layer contains an order.
		 *
		 * @param \AutomateWoo\Workflow $workflow
		 * @return bool
		 */
		public function validate_workflow( $workflow ) {
			$order = $workflow->data_layer()->get_order();
			return $order instanceof \WC_Order;
		}

		/**
		 * Main entrypoint — called once per day by the cron handler.
		 * Iterates pending and on-hold child payment orders and fires maybe_run()
		 * per order so AW evaluates each workflow's rules against it.
		 *
		 * For each order we also resolve a customer data item — either
		 * a registered user (via user_id) or a guest (via billing email).
		 * This lets workflows use customer.* variables.
		 */
		public function run_daily_check() {
			$orders = wc_get_orders( array(
				'status'     => array( 'pending', 'on-hold' ),
				'meta_key'   => '_vanpos_order_type',
				'meta_value' => 'payment_order',
				'limit'      => -1,
				'return'     => 'objects',
			) );

			if ( empty( $orders ) ) {
				return;
			}

			foreach ( $orders as $order ) {
				$customer = $this->resolve_customer( $order );

				$this->maybe_run( array(
					'order'    => $order,
					'customer' => $customer,
				) );
			}
		}

		/**
		 * Resolve an AutomateWoo Customer object for a given order.
		 * Registered users come from their user_id; guests are looked up
		 * or created from their billing email.
		 *
		 * @param \WC_Order $order
		 * @return \AutomateWoo\Customer|false
		 */
		private function resolve_customer( $order ) {
			if ( ! class_exists( 'AutomateWoo\Customer_Factory' ) ) {
				return false;
			}

			$user_id = (int) $order->get_user_id();
			if ( $user_id > 0 ) {
				return \AutomateWoo\Customer_Factory::get_by_user_id( $user_id );
			}

			$email = $order->get_billing_email();
			if ( empty( $email ) ) {
				return false;
			}

			return \AutomateWoo\Customer_Factory::get_by_email( $email, true );
		}
	}

	$defined = true;
}

// ============================================================
// CRON SCHEDULING — runs at init
// ============================================================

add_action( 'init', 'vanpos_aw_schedule_daily_check' );

/**
 * Schedule the daily pending-check cron if it isn't already scheduled.
 */
function vanpos_aw_schedule_daily_check() {
	if ( wp_next_scheduled( 'vanpos_aw_daily_pending_check' ) ) {
		return;
	}

	// Calculate next 09:00 in site timezone.
	$tz   = wp_timezone();
	$next = new DateTime( 'today 09:00', $tz );
	if ( $next->getTimestamp() < time() ) {
		$next->modify( '+1 day' );
	}

	wp_schedule_event( $next->getTimestamp(), 'daily', 'vanpos_aw_daily_pending_check' );
}

/**
 * Cron handler — fires the AW trigger. Does nothing if AW isn't loaded
 * or the trigger isn't registered (defensive; normally both are true).
 */
add_action( 'vanpos_aw_daily_pending_check', 'vanpos_aw_run_daily_check' );

function vanpos_aw_run_daily_check() {
	if ( ! class_exists( 'AutomateWoo\Triggers' ) ) {
		return;
	}

	$trigger = AutomateWoo\Triggers::get( 'vanpos_daily_pending_check' );
	if ( ! $trigger || ! method_exists( $trigger, 'run_daily_check' ) ) {
		return;
	}

	$trigger->run_daily_check();
}

/**
 * Helper for the main plugin's deactivate() method — clears the cron.
 */
function vanpos_aw_clear_daily_cron() {
	$timestamp = wp_next_scheduled( 'vanpos_aw_daily_pending_check' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'vanpos_aw_daily_pending_check' );
	}
}
