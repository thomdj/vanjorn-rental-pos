<?php
/**
 * VanPOS AutomateWoo Cron Debug & Runner
 *
 * Admin tool that provides visibility into the daily pending-check cron:
 *   - Shows cron schedule status (next run, last run, timezone)
 *   - Lists all pending payment orders the trigger would iterate
 *   - Manual "Run Now" with per-order diagnostic output
 *   - Per-order detail: AW workflows evaluated, rules matched/failed
 *
 * INTEGRATION: Load in main plugin bootstrap (vanjorn-rental-pos.php):
 *   require_once VANPOS_PLUGIN_DIR . 'admin/class-vanpos-aw-cron-debug.php';
 *
 * Assets expected at:
 *   admin/css/aw-cron-debug.css
 *   admin/js/aw-cron-debug.js
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VanPOS_AW_Cron_Debug {

	/** WP option key used to persist run history. */
	const LOG_OPTION = 'vanpos_aw_cron_debug_log';

	/** Maximum history entries to keep. */
	const LOG_MAX = 50;

	/** @var string Hook suffix returned by add_submenu_page(). */
	private static $page_hook = '';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_vanpos_aw_cron_status', array( __CLASS__, 'ajax_cron_status' ) );
		add_action( 'wp_ajax_vanpos_aw_cron_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_vanpos_aw_cron_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_vanpos_aw_cron_run_single', array( __CLASS__, 'ajax_run_single' ) );
	}

	/**
	 * Add submenu page under VAN-Jorn Rental POS.
	 */
	public static function add_menu() {
		self::$page_hook = add_submenu_page(
			'vanjorn-rental-pos',
			__( 'Mailer Debug', 'vanjorn-rental-pos' ),
			__( 'Mailer Debug', 'vanjorn-rental-pos' ),
			'manage_woocommerce',
			'vanjorn-rental-pos-aw-cron',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue CSS + JS only on our admin page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( self::$page_hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'vanpos-aw-cron-debug',
			VANPOS_PLUGIN_URL . 'admin/css/aw-cron-debug.css',
			array(),
			VANPOS_VERSION
		);

		wp_enqueue_script(
			'vanpos-aw-cron-debug',
			VANPOS_PLUGIN_URL . 'admin/js/aw-cron-debug.js',
			array( 'jquery' ),
			VANPOS_VERSION,
			true
		);

		wp_localize_script( 'vanpos-aw-cron-debug', 'vanposAwCron', array(
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'vanpos_aw_cron_debug' ),
			'order_url' => admin_url( 'admin.php?page=wc-orders&action=edit&id=' ),
		) );
	}

	// ================================================================
	// AJAX: Cron status
	// ================================================================

	public static function ajax_cron_status() {
		check_ajax_referer( 'vanpos_aw_cron_debug', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$hook    = 'vanpos_aw_daily_pending_check';
		$next_ts = wp_next_scheduled( $hook );
		$tz      = wp_timezone();
		$now     = new DateTime( 'now', $tz );

		$data = array(
			'hook'               => $hook,
			'scheduled'          => (bool) $next_ts,
			'next_utc'           => $next_ts ? gmdate( 'Y-m-d H:i:s', $next_ts ) . ' UTC' : null,
			'next_local'         => null,
			'now_local'          => $now->format( 'Y-m-d H:i:s' ),
			'timezone'           => $tz->getName(),
			'aw_loaded'          => class_exists( 'AutomateWoo\Triggers' ),
			'trigger_registered' => false,
			'trigger_class'      => null,
			'workflows'          => array(),
			'history'            => array_slice( get_option( self::LOG_OPTION, array() ), 0, 20 ),
		);

		if ( $next_ts ) {
			$local = new DateTime( '@' . $next_ts );
			$local->setTimezone( $tz );
			$data['next_local'] = $local->format( 'Y-m-d H:i:s' );
		}

		// Check if trigger is registered in AW.
		if ( $data['aw_loaded'] ) {
			$trigger = \AutomateWoo\Triggers::get( 'vanpos_daily_pending_check' );
			if ( $trigger ) {
				$data['trigger_registered'] = true;
				$data['trigger_class']      = get_class( $trigger );
			}
			$data['workflows'] = self::get_workflows_for_trigger( 'vanpos_daily_pending_check' );
		}

		wp_send_json_success( $data );
	}

	// ================================================================
	// AJAX: Preview — list orders that would be evaluated
	// ================================================================

	public static function ajax_preview() {
		check_ajax_referer( 'vanpos_aw_cron_debug', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$orders = wc_get_orders( array(
			'status'     => 'pending',
			'meta_key'   => '_vanpos_order_type',
			'meta_value' => 'payment_order',
			'limit'      => -1,
			'return'     => 'objects',
		) );

		$items = array();
		foreach ( $orders as $order ) {
			$items[] = self::build_order_row( $order );
		}

		wp_send_json_success( array(
			'count'  => count( $items ),
			'orders' => $items,
		) );
	}

	// ================================================================
	// AJAX: Run all — triggers the real cron handler with logging
	// ================================================================

	public static function ajax_run() {
		check_ajax_referer( 'vanpos_aw_cron_debug', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$results = self::execute_daily_check();

		// Persist to history.
		self::append_history( $results );

		wp_send_json_success( $results );
	}

	// ================================================================
	// AJAX: Run single order through the trigger
	// ================================================================

	public static function ajax_run_single() {
		check_ajax_referer( 'vanpos_aw_cron_debug', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( 'Missing order_id.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found.' );
		}

		$result = self::evaluate_single_order( $order );
		wp_send_json_success( $result );
	}

	// ================================================================
	// Core: Execute the daily check with diagnostic capture
	// ================================================================

	/**
	 * Runs the same logic as the real cron handler but captures
	 * diagnostic output for each order, then fires the real trigger.
	 *
	 * @return array
	 */
	private static function execute_daily_check() {
		$result = array(
			'timestamp'   => current_time( 'mysql' ),
			'aw_loaded'   => class_exists( 'AutomateWoo\Triggers' ),
			'trigger_ok'  => false,
			'order_count' => 0,
			'orders'      => array(),
			'errors'      => array(),
		);

		if ( ! $result['aw_loaded'] ) {
			$result['errors'][] = 'AutomateWoo is not loaded.';
			return $result;
		}

		$trigger = \AutomateWoo\Triggers::get( 'vanpos_daily_pending_check' );
		if ( ! $trigger || ! method_exists( $trigger, 'run_daily_check' ) ) {
			$result['errors'][] = 'Trigger "vanpos_daily_pending_check" not registered or missing run_daily_check().';
			return $result;
		}

		$result['trigger_ok'] = true;

		// Fetch the same orders the trigger would.
		$orders = wc_get_orders( array(
			'status'     => 'pending',
			'meta_key'   => '_vanpos_order_type',
			'meta_value' => 'payment_order',
			'limit'      => -1,
			'return'     => 'objects',
		) );

		$result['order_count'] = count( $orders );

		if ( empty( $orders ) ) {
			$result['errors'][] = 'No pending payment orders found.';
			return $result;
		}

		// Evaluate each order against AW workflows (diagnostic pass).
		foreach ( $orders as $order ) {
			$order_result = self::evaluate_single_order( $order );
			$result['orders'][] = $order_result;
		}

		// Fire the real trigger so AW processes everything normally.
		$trigger->run_daily_check();

		return $result;
	}

	/**
	 * Evaluate a single order against all workflows using our trigger.
	 * Returns diagnostic data showing which workflows matched and why.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	private static function evaluate_single_order( $order ) {
		$row = self::build_order_row( $order );
		$row['workflow_results'] = array();

		$workflows = self::get_workflow_objects( 'vanpos_daily_pending_check' );

		if ( empty( $workflows ) ) {
			$post_count = self::query_workflow_posts( 'vanpos_daily_pending_check' )->found_posts;
			if ( $post_count > 0 ) {
				$detail = $post_count . ' workflow(s) found in the database but none could be instantiated as AW objects. '
					. 'AutomateWoo may not be fully loaded during this request. '
					. 'The cron itself runs in a context where AW is loaded — this limitation only affects the debug tool\'s rule inspection.';
			} else {
				$detail = 'No active workflows use the "vanpos_daily_pending_check" trigger. Create a workflow in AutomateWoo using this trigger first.';
			}
			$row['workflow_results'][] = array(
				'name'   => '(none)',
				'status' => 'no_workflows',
				'detail' => $detail,
			);
			return $row;
		}

		$customer = self::resolve_customer( $order );

		foreach ( $workflows as $workflow ) {
			$wf_result = array(
				'id'     => $workflow->get_id(),
				'name'   => $workflow->get_title(),
				'status' => 'unknown',
				'rules'  => array(),
				'detail' => '',
			);

			$data_layer = array( 'order' => $order );
			if ( $customer ) {
				$data_layer['customer'] = $customer;
			}

			try {
				$workflow->set_data_layer( $data_layer, true );

				$rules_valid = $workflow->validate_rules();

				if ( $rules_valid ) {
					$wf_result['status'] = 'matched';
					$wf_result['detail'] = 'All rules passed — workflow would fire.';

					if ( self::has_workflow_run_for_order( $workflow->get_id(), $order->get_id() ) ) {
						$wf_result['status'] = 'already_run';
						$wf_result['detail'] = 'Rules passed but workflow already ran for this order (AW log entry exists).';
					}
				} else {
					$wf_result['status'] = 'rules_failed';
					$wf_result['detail'] = 'One or more rules did not pass.';
				}

				$wf_result['rules'] = self::evaluate_workflow_rules( $workflow, $order );

			} catch ( \Exception $e ) {
				$wf_result['status'] = 'error';
				$wf_result['detail'] = $e->getMessage();
			} catch ( \Error $e ) {
				$wf_result['status'] = 'error';
				$wf_result['detail'] = 'PHP Error: ' . $e->getMessage();
			}

			$row['workflow_results'][] = $wf_result;
		}

		return $row;
	}

	// ================================================================
	// Helpers
	// ================================================================

	/**
	 * Build a summary array for a single order (for table display).
	 */
	private static function build_order_row( $order ) {
		return array(
			'order_id'      => $order->get_id(),
			'order_number'  => $order->get_order_number(),
			'status'        => $order->get_status(),
			'total'         => $order->get_total(),
			'customer'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'         => $order->get_billing_email(),
			'payment_type'  => $order->get_meta( '_vanpos_payment_type' ),
			'due_date'      => $order->get_meta( '_payment_due_date' ),
			'pickup_date'   => $order->get_meta( '_vanpos_pickup_date' ),
			'return_date'   => $order->get_meta( '_vanpos_return_date' ),
			'parent_id'     => $order->get_parent_id() ?: (int) $order->get_meta( '_vanpos_primary_order_id' ),
			'booking_ref'   => $order->get_meta( '_vanpos_booking_reference' ),
			'is_short_term' => $order->get_meta( '_is_short_term_deposit' ) ?: $order->get_meta( '_is_short_term_booking' ),
		);
	}

	/**
	 * Resolve an AW Customer for a given order (mirrors the trigger logic).
	 */
	private static function resolve_customer( $order ) {
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

	/**
	 * Get summary info about workflows using our trigger.
	 * Works even if Workflow_Factory is not available — falls back
	 * to reading post data directly from the WP_Query results.
	 */
	private static function get_workflows_for_trigger( $trigger_name ) {
		$query = self::query_workflow_posts( $trigger_name );
		$out   = array();

		foreach ( $query->posts as $post ) {
			$out[] = array(
				'id'     => $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
			);
		}

		return $out;
	}

	/**
	 * Retrieve AW Workflow objects for a given trigger name.
	 *
	 * Tries multiple instantiation strategies because AutomateWoo
	 * lazy-loads classes and Workflow_Factory is often unavailable
	 * during AJAX calls:
	 *   1. Workflow_Factory::get()        (preferred, may not be loaded)
	 *   2. new Workflow( WP_Post )        (direct constructor)
	 *   3. aw_get_workflow() helper       (global function some versions expose)
	 */
	private static function get_workflow_objects( $trigger_name ) {
		$query     = self::query_workflow_posts( $trigger_name );
		$workflows = array();

		foreach ( $query->posts as $post ) {
			$wf = null;

			try {
				// Strategy 1: Factory class.
				if ( class_exists( 'AutomateWoo\Workflow_Factory' ) ) {
					$wf = \AutomateWoo\Workflow_Factory::get( $post->ID );
				}

				// Strategy 2: Direct constructor with WP_Post.
				if ( ! $wf && class_exists( 'AutomateWoo\Workflow' ) ) {
					$wf = new \AutomateWoo\Workflow( $post );
				}

				// Strategy 3: Global helper function.
				if ( ! $wf && function_exists( 'aw_get_workflow' ) ) {
					$wf = aw_get_workflow( $post->ID );
				}
			} catch ( \Exception $e ) {
				continue;
			} catch ( \Error $e ) {
				continue;
			}

			if ( $wf ) {
				$workflows[] = $wf;
			}
		}

		return $workflows;
	}

	/**
	 * Query aw_workflow posts by trigger name.
	 *
	 * @param string $trigger_name
	 * @return WP_Query
	 */
	private static function query_workflow_posts( $trigger_name ) {
		return new \WP_Query( array(
			'post_type'      => 'aw_workflow',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'   => 'trigger_name',
					'value' => $trigger_name,
				),
			),
		) );
	}

	/**
	 * Evaluate each rule in a workflow against an order and return diagnostics.
	 */
	private static function evaluate_workflow_rules( $workflow, $order ) {
		$rule_options = $workflow->get_rule_data();
		$diagnostics  = array();

		if ( empty( $rule_options ) || ! is_array( $rule_options ) ) {
			$diagnostics[] = array(
				'group'  => 0,
				'rule'   => '(no rules configured)',
				'passed' => true,
				'detail' => 'Workflow has no rules — always matches.',
			);
			return $diagnostics;
		}

		$meta_map = array(
			'vanpos_order_pickup_date'      => '_vanpos_pickup_date',
			'vanpos_order_return_date'      => '_vanpos_return_date',
			'vanpos_order_vanpos_due_date'  => '_vanpos_due_date',
			'vanpos_order_payment_due_date' => '_payment_due_date',
		);

		foreach ( $rule_options as $group_index => $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			foreach ( $group as $rule_data ) {
				$rule_name = isset( $rule_data['name'] ) ? $rule_data['name'] : '(unknown)';
				$compare   = isset( $rule_data['compare'] ) ? $rule_data['compare'] : '';
				$value     = isset( $rule_data['value'] ) ? $rule_data['value'] : '';

				$diag = array(
					'group'   => $group_index + 1,
					'rule'    => $rule_name,
					'compare' => $compare,
					'value'   => $value,
					'passed'  => null,
					'detail'  => '',
				);

				// Try to evaluate via the AW rule object.
				if ( class_exists( 'AutomateWoo\Rules' ) && method_exists( 'AutomateWoo\Rules', 'get' ) ) {
					$rule_obj = \AutomateWoo\Rules::get( $rule_name );
					if ( $rule_obj ) {
						try {
							$data_item = null;
							if ( isset( $rule_obj->data_item ) ) {
								if ( $rule_obj->data_item === 'order' ) {
									$data_item = $order;
								} elseif ( $rule_obj->data_item === 'customer' ) {
									$data_item = self::resolve_customer( $order );
								}
							}

							if ( $data_item && method_exists( $rule_obj, 'validate' ) ) {
								$passed         = $rule_obj->validate( $data_item, $compare, $value );
								$diag['passed'] = (bool) $passed;
								$diag['detail'] = $passed ? 'Passed' : 'Failed';
							} else {
								$diag['detail'] = 'Could not resolve data item or validate method.';
							}
						} catch ( \Exception $e ) {
							$diag['detail'] = 'Error: ' . $e->getMessage();
						} catch ( \Error $e ) {
							$diag['detail'] = 'PHP Error: ' . $e->getMessage();
						}
					} else {
						$diag['detail'] = 'Rule object not found in AW registry.';
					}
				} else {
					$diag['detail'] = 'AW Rules class not available.';
				}

				// Enrich with current meta value for VanPOS date rules.
				if ( isset( $meta_map[ $rule_name ] ) ) {
					$diag['meta_value'] = $order->get_meta( $meta_map[ $rule_name ] ) ?: '(empty)';
				}

				// Enrich generic custom-field rules with the stored value.
				if ( $rule_name === 'order_meta' && ! empty( $rule_data['value'] ) ) {
					// AW stores the meta key in a nested field; try common patterns.
					$field_key = '';
					if ( is_array( $value ) && isset( $value[0] ) ) {
						$field_key = $value[0];
					} elseif ( is_string( $value ) ) {
						$field_key = $value;
					}
					if ( $field_key && strpos( $field_key, '_' ) === 0 ) {
						$diag['meta_value'] = $order->get_meta( $field_key ) ?: '(empty)';
					}
				}

				$diagnostics[] = $diag;
			}
		}

		return $diagnostics;
	}

	/**
	 * Check if a workflow has already been logged as run for this order.
	 */
	private static function has_workflow_run_for_order( $workflow_id, $order_id ) {
		if ( ! class_exists( 'AutomateWoo\Log_Query' ) ) {
			return false;
		}

		try {
			$query = new \AutomateWoo\Log_Query();
			$query->where_workflow( $workflow_id );
			$query->where_order( $order_id );
			$query->set_limit( 1 );
			$results = $query->get_results();

			return ! empty( $results );
		} catch ( \Exception $e ) {
			return false;
		} catch ( \Error $e ) {
			return false;
		}
	}

	/**
	 * Append a run result to the persisted history.
	 */
	private static function append_history( $result ) {
		$log = get_option( self::LOG_OPTION, array() );

		$matched = 0;
		$failed  = 0;
		$already = 0;
		foreach ( $result['orders'] as $o ) {
			if ( ! empty( $o['workflow_results'] ) ) {
				foreach ( $o['workflow_results'] as $wf ) {
					if ( $wf['status'] === 'matched' ) {
						$matched++;
					} elseif ( $wf['status'] === 'already_run' ) {
						$already++;
					} else {
						$failed++;
					}
				}
			}
		}

		array_unshift( $log, array(
			'time'        => $result['timestamp'],
			'source'      => 'manual',
			'order_count' => $result['order_count'],
			'matched'     => $matched,
			'already_run' => $already,
			'failed'      => $failed,
			'errors'      => $result['errors'],
		) );

		$log = array_slice( $log, 0, self::LOG_MAX );
		update_option( self::LOG_OPTION, $log, false );
	}

	// ================================================================
	// Render
	// ================================================================

	public static function render_page() {
		?>
		<div class="wrap vanpos-aw-cron-wrap">
			<h1><?php esc_html_e( 'AutomateWoo Cron Debug', 'vanjorn-rental-pos' ); ?></h1>
			<p class="vanpos-aw-cron-intro"><?php esc_html_e( 'Inspect the daily pending-check cron, preview which orders would be evaluated, and run the trigger manually with full diagnostic output.', 'vanjorn-rental-pos' ); ?></p>

			<!-- Status Panel -->
			<div class="vanpos-aw-section" id="vanpos-aw-status-section">
				<h2><?php esc_html_e( 'Cron & Trigger Status', 'vanjorn-rental-pos' ); ?></h2>
				<div id="vanpos-aw-status-loading" class="vanpos-aw-loading"><?php esc_html_e( 'Loading status…', 'vanjorn-rental-pos' ); ?></div>
				<div id="vanpos-aw-status-body" style="display:none;">
					<table class="vanpos-aw-status-table">
						<tbody>
							<tr>
								<th><?php esc_html_e( 'Cron Hook', 'vanjorn-rental-pos' ); ?></th>
								<td id="vanpos-aw-s-hook"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Scheduled', 'vanjorn-rental-pos' ); ?></th>
								<td id="vanpos-aw-s-scheduled"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Next Run (Local)', 'vanjorn-rental-pos' ); ?></th>
								<td id="vanpos-aw-s-next-local"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Next Run (UTC)', 'vanjorn-rental-pos' ); ?></th>
								<td id="vanpos-aw-s-next-utc"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Current Time', 'vanjorn-rental-pos' ); ?></th>
								<td id="vanpos-aw-s-now"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Timezone', 'vanjorn-rental-pos' ); ?></th>
								<td id="vanpos-aw-s-tz"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'AutomateWoo Loaded', 'vanjorn-rental-pos' ); ?></th>
								<td id="vanpos-aw-s-awloaded"></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Trigger Registered', 'vanjorn-rental-pos' ); ?></th>
								<td id="vanpos-aw-s-trigger"></td>
							</tr>
						</tbody>
					</table>

					<h3><?php esc_html_e( 'Active Workflows Using This Trigger', 'vanjorn-rental-pos' ); ?></h3>
					<div id="vanpos-aw-s-workflows"></div>
				</div>
			</div>

			<!-- Actions -->
			<div class="vanpos-aw-section" id="vanpos-aw-actions-section">
				<h2><?php esc_html_e( 'Actions', 'vanjorn-rental-pos' ); ?></h2>
				<div class="vanpos-aw-button-row">
					<button id="vanpos-aw-btn-preview" class="button button-secondary"><?php esc_html_e( 'Preview Orders', 'vanjorn-rental-pos' ); ?></button>
					<button id="vanpos-aw-btn-run" class="button button-primary"><?php esc_html_e( 'Run Trigger Now', 'vanjorn-rental-pos' ); ?></button>
					<span id="vanpos-aw-action-status" class="vanpos-aw-action-status"></span>
				</div>
				<p class="description"><?php esc_html_e( '"Preview" lists which orders would be evaluated. "Run Trigger Now" calls the real trigger — workflows will fire and emails will send if rules match.', 'vanjorn-rental-pos' ); ?></p>
			</div>

			<!-- Preview / Results -->
			<div class="vanpos-aw-section" id="vanpos-aw-results-section" style="display:none;">
				<h2 id="vanpos-aw-results-heading"></h2>
				<div id="vanpos-aw-results-summary"></div>
				<table id="vanpos-aw-results-table" class="widefat fixed striped" style="display:none;">
					<thead>
						<tr>
							<th style="width:70px;"><?php esc_html_e( 'Order', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Parent', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Payment Type', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:130px;"><?php esc_html_e( 'Customer', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Total', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Due Date', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Pickup', 'vanjorn-rental-pos' ); ?></th>
							<th><?php esc_html_e( 'Workflow Results', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Inspect', 'vanjorn-rental-pos' ); ?></th>
						</tr>
					</thead>
					<tbody id="vanpos-aw-results-body"></tbody>
				</table>
			</div>

			<!-- Per-Order Detail Panel -->
			<div class="vanpos-aw-section" id="vanpos-aw-detail-section" style="display:none;">
				<h2 id="vanpos-aw-detail-heading"></h2>
				<div id="vanpos-aw-detail-body"></div>
			</div>

			<!-- Run History -->
			<div class="vanpos-aw-section" id="vanpos-aw-history-section" style="display:none;">
				<h2><?php esc_html_e( 'Run History', 'vanjorn-rental-pos' ); ?></h2>
				<table id="vanpos-aw-history-table" class="widefat fixed striped">
					<thead>
						<tr>
							<th style="width:160px;"><?php esc_html_e( 'Time', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Source', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Orders', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Matched', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Already Run', 'vanjorn-rental-pos' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Failed', 'vanjorn-rental-pos' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'vanjorn-rental-pos' ); ?></th>
						</tr>
					</thead>
					<tbody id="vanpos-aw-history-body"></tbody>
				</table>
			</div>

		</div>
		<?php
	}
}

VanPOS_AW_Cron_Debug::init();
