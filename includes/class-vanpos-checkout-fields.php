<?php
/**
 * Checkout & Registration Custom Fields for VAN-Jorn Rental POS
 *
 * Adds custom fields for driver details on checkout, registration,
 * and account-edit pages. Migrated from child theme.
 *
 * CHECKOUT FIELDS:
 * - Driver Details (using billing address fields)
 * - Second Driver Details (additional fields)
 *
 * REGISTRATION FIELDS:
 * - Name, Email, Mobile, Date of Birth
 *
 * ACCOUNT EDIT FIELDS:
 * - Date of Birth, Driver License dates
 * - Second Driver details
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout Fields Class
 */
class VanPOS_Checkout_Fields {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		// Registration form.
		add_action( 'woocommerce_register_form', array( __CLASS__, 'add_registration_fields' ) );
		add_action( 'woocommerce_register_post', array( __CLASS__, 'validate_registration_fields' ), 10, 3 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'save_registration_fields' ) );

		// Account edit form.
		add_action( 'woocommerce_edit_account_form_fields', array( __CLASS__, 'add_date_of_birth_to_account_form' ) );
		add_action( 'woocommerce_edit_account_form_fields', array( __CLASS__, 'add_driver_details_to_account_form' ), 20 );
		add_action( 'woocommerce_edit_account_form_fields', array( __CLASS__, 'add_second_driver_details_to_account_form' ), 30 );
		add_action( 'woocommerce_save_account_details', array( __CLASS__, 'save_account_custom_fields' ) );

		// Checkout fields.
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'customize_checkout_fields' ) );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill_checkout_fields' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'save_checkout_fields' ) );

		// Admin order display.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'display_custom_fields_in_admin' ) );

		// Date-restrictions JS.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the date-restrictions script and localize error strings.
	 */
	public static function enqueue_scripts() {
		if ( ! is_account_page() && ! is_checkout() ) {
			return;
		}
		wp_enqueue_script(
			'vanpos-date-restrictions',
			VANPOS_PLUGIN_URL . 'frontend/js/vanpos-date-restrictions.js',
			array( 'jquery' ),
			VANPOS_VERSION,
			true
		);
		wp_localize_script(
			'vanpos-date-restrictions',
			'vanjornDateRestrictions',
			array(
				'dobFutureError'     => __( 'Date of birth cannot be in the future.', 'vanjorn-rental-pos' ),
				'licenseFutureError' => __( 'License date cannot be in the future.', 'vanjorn-rental-pos' ),
			)
		);
	}

	/* =========================================================================
	 * Registration Form
	 * ========================================================================= */

	/**
	 * Add custom fields to WooCommerce registration form.
	 */
	public static function add_registration_fields() {
		?>
		<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
			<label for="reg_first_name"><?php esc_html_e( 'First name', 'vanjorn-rental-pos' ); ?> <span class="required">*</span></label>
			<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="first_name" id="reg_first_name" value="<?php echo ! empty( $_POST['first_name'] ) ? esc_attr( wp_unslash( $_POST['first_name'] ) ) : ''; ?>" required />
		</p>
		<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
			<label for="reg_last_name"><?php esc_html_e( 'Last name', 'vanjorn-rental-pos' ); ?> <span class="required">*</span></label>
			<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="last_name" id="reg_last_name" value="<?php echo ! empty( $_POST['last_name'] ) ? esc_attr( wp_unslash( $_POST['last_name'] ) ) : ''; ?>" required />
		</p>
		<div class="clear"></div>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="reg_phone"><?php esc_html_e( 'Phone', 'vanjorn-rental-pos' ); ?> <span class="required">*</span></label>
			<input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="phone" id="reg_phone" value="<?php echo ! empty( $_POST['phone'] ) ? esc_attr( wp_unslash( $_POST['phone'] ) ) : ''; ?>" placeholder="12345678" required />
		</p>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="reg_date_of_birth"><?php esc_html_e( 'Date of birth', 'vanjorn-rental-pos' ); ?> <span class="required">*</span></label>
			<input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="date_of_birth" id="reg_date_of_birth" value="<?php echo ! empty( $_POST['date_of_birth'] ) ? esc_attr( wp_unslash( $_POST['date_of_birth'] ) ) : ''; ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required />
		</p>
		<?php
	}

	/**
	 * Validate registration fields.
	 *
	 * Handles both standalone registration form (first_name, last_name, etc.)
	 * and checkout account creation (billing_first_name, billing_last_name, etc.)
	 *
	 * @param string   $username         Username.
	 * @param string   $email            Email.
	 * @param WP_Error $validation_errors Validation errors object.
	 * @return WP_Error
	 */
	public static function validate_registration_fields( $username, $email, $validation_errors ) {
		$first_name    = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : ( isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '' );
		$last_name     = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : ( isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '' );
		$phone         = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : ( isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '' );
		$date_of_birth = isset( $_POST['date_of_birth'] ) ? sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ) ) : ( isset( $_POST['driver_date_of_birth'] ) ? sanitize_text_field( wp_unslash( $_POST['driver_date_of_birth'] ) ) : '' );

		if ( empty( $first_name ) ) {
			$validation_errors->add( 'first_name_error', __( 'First name is required.', 'vanjorn-rental-pos' ) );
		}
		if ( empty( $last_name ) ) {
			$validation_errors->add( 'last_name_error', __( 'Last name is required.', 'vanjorn-rental-pos' ) );
		}
		if ( empty( $phone ) ) {
			$validation_errors->add( 'phone_error', __( 'Phone number is required.', 'vanjorn-rental-pos' ) );
		}
		if ( empty( $date_of_birth ) ) {
			$validation_errors->add( 'date_of_birth_error', __( 'Date of birth is required.', 'vanjorn-rental-pos' ) );
		}
		return $validation_errors;
	}

	/**
	 * Save registration fields to user meta.
	 *
	 * @param int $customer_id Customer user ID.
	 */
	public static function save_registration_fields( $customer_id ) {
		$first_name    = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : ( isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '' );
		$last_name     = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : ( isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '' );
		$phone         = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : ( isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '' );
		$date_of_birth = isset( $_POST['date_of_birth'] ) ? sanitize_text_field( wp_unslash( $_POST['date_of_birth'] ) ) : ( isset( $_POST['driver_date_of_birth'] ) ? sanitize_text_field( wp_unslash( $_POST['driver_date_of_birth'] ) ) : '' );

		if ( ! empty( $first_name ) ) {
			update_user_meta( $customer_id, 'first_name', $first_name );
			update_user_meta( $customer_id, 'billing_first_name', $first_name );
		}
		if ( ! empty( $last_name ) ) {
			update_user_meta( $customer_id, 'last_name', $last_name );
			update_user_meta( $customer_id, 'billing_last_name', $last_name );
		}
		if ( ! empty( $phone ) ) {
			update_user_meta( $customer_id, 'billing_phone', $phone );
		}
		if ( ! empty( $date_of_birth ) ) {
			update_user_meta( $customer_id, 'date_of_birth', $date_of_birth );
		}
	}

	/* =========================================================================
	 * Account Edit Form
	 * ========================================================================= */

	/**
	 * Add Date of Birth field to account edit form.
	 */
	public static function add_date_of_birth_to_account_form() {
		$user_id       = get_current_user_id();
		$date_of_birth = get_user_meta( $user_id, 'date_of_birth', true );
		?>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="account_date_of_birth"><?php esc_html_e( 'Date of birth', 'vanjorn-rental-pos' ); ?> <span class="required">*</span></label>
			<input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="account_date_of_birth" id="account_date_of_birth" value="<?php echo esc_attr( $date_of_birth ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required />
		</p>
		<?php
	}

	/**
	 * Add Driver Details section to account edit form.
	 */
	public static function add_driver_details_to_account_form() {
		$user_id                      = get_current_user_id();
		$driver_license_issue_date    = get_user_meta( $user_id, 'driver_license_issue_date', true );
		$driver_license_obtained_date = get_user_meta( $user_id, 'driver_license_obtained_date', true );
		?>
		<fieldset class="vanjorn-driver-details-section">
			<legend><?php esc_html_e( 'Driver details', 'vanjorn-rental-pos' ); ?></legend>

			<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
				<label for="account_driver_license_issue_date"><?php esc_html_e( 'Date of issue of driving license', 'vanjorn-rental-pos' ); ?> <span class="required">*</span></label>
				<input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="account_driver_license_issue_date" id="account_driver_license_issue_date" value="<?php echo esc_attr( $driver_license_issue_date ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required />
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
				<label for="account_driver_license_obtained_date"><?php esc_html_e( 'Date of obtaining driving license', 'vanjorn-rental-pos' ); ?> <span class="required">*</span></label>
				<input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="account_driver_license_obtained_date" id="account_driver_license_obtained_date" value="<?php echo esc_attr( $driver_license_obtained_date ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required />
			</p>
			<div class="clear"></div>
		</fieldset>
		<?php
	}

	/**
	 * Add Second Driver Details section to account edit form.
	 */
	public static function add_second_driver_details_to_account_form() {
		$user_id                             = get_current_user_id();
		$second_driver_name                  = get_user_meta( $user_id, 'second_driver_name', true );
		$second_driver_date_of_birth         = get_user_meta( $user_id, 'second_driver_date_of_birth', true );
		$second_driver_license_issue_date    = get_user_meta( $user_id, 'second_driver_license_issue_date', true );
		$second_driver_license_obtained_date = get_user_meta( $user_id, 'second_driver_license_obtained_date', true );
		?>
		<fieldset class="vanjorn-second-driver-details-section">
			<legend><?php esc_html_e( 'Second driver', 'vanjorn-rental-pos' ); ?> <span class="vanjorn-optional-text">(<?php esc_html_e( 'only if applicable', 'vanjorn-rental-pos' ); ?>)</span></legend>

			<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
				<label for="account_second_driver_name"><?php esc_html_e( 'Initials and surname of second driver', 'vanjorn-rental-pos' ); ?></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_second_driver_name" id="account_second_driver_name" value="<?php echo esc_attr( $second_driver_name ); ?>" />
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
				<label for="account_second_driver_date_of_birth"><?php esc_html_e( 'Date of birth of second driver', 'vanjorn-rental-pos' ); ?></label>
				<input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="account_second_driver_date_of_birth" id="account_second_driver_date_of_birth" value="<?php echo esc_attr( $second_driver_date_of_birth ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" />
			</p>
			<div class="clear"></div>

			<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
				<label for="account_second_driver_license_issue_date"><?php esc_html_e( 'Date of issue of driving license of second driver', 'vanjorn-rental-pos' ); ?></label>
				<input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="account_second_driver_license_issue_date" id="account_second_driver_license_issue_date" value="<?php echo esc_attr( $second_driver_license_issue_date ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" />
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
				<label for="account_second_driver_license_obtained_date"><?php esc_html_e( 'Date of obtaining driving license for second driver', 'vanjorn-rental-pos' ); ?></label>
				<input type="date" class="woocommerce-Input woocommerce-Input--text input-text" name="account_second_driver_license_obtained_date" id="account_second_driver_license_obtained_date" value="<?php echo esc_attr( $second_driver_license_obtained_date ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" />
			</p>
			<div class="clear"></div>
		</fieldset>
		<?php
	}

	/**
	 * Save account form fields (Date of Birth, Driver Details, Second Driver).
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_account_custom_fields( $user_id ) {
		$today = strtotime( wp_date( 'Y-m-d' ) );

		if ( isset( $_POST['account_date_of_birth'] ) ) {
			$date_of_birth = sanitize_text_field( wp_unslash( $_POST['account_date_of_birth'] ) );
			if ( ! empty( $date_of_birth ) && strtotime( $date_of_birth ) > $today ) {
				wc_add_notice( __( 'Date of birth cannot be in the future.', 'vanjorn-rental-pos' ), 'error' );
			} else {
				update_user_meta( $user_id, 'date_of_birth', $date_of_birth );
			}
		}

		if ( isset( $_POST['account_driver_license_issue_date'] ) ) {
			$driver_license_issue_date = sanitize_text_field( wp_unslash( $_POST['account_driver_license_issue_date'] ) );
			if ( ! empty( $driver_license_issue_date ) && strtotime( $driver_license_issue_date ) > $today ) {
				wc_add_notice( __( 'License issue date cannot be in the future.', 'vanjorn-rental-pos' ), 'error' );
			} else {
				update_user_meta( $user_id, 'driver_license_issue_date', $driver_license_issue_date );
			}
		}

		if ( isset( $_POST['account_driver_license_obtained_date'] ) ) {
			$driver_license_obtained_date = sanitize_text_field( wp_unslash( $_POST['account_driver_license_obtained_date'] ) );
			if ( ! empty( $driver_license_obtained_date ) && strtotime( $driver_license_obtained_date ) > $today ) {
				wc_add_notice( __( 'License obtained date cannot be in the future.', 'vanjorn-rental-pos' ), 'error' );
			} else {
				update_user_meta( $user_id, 'driver_license_obtained_date', $driver_license_obtained_date );
			}
		}

		if ( isset( $_POST['account_second_driver_name'] ) ) {
			update_user_meta( $user_id, 'second_driver_name', sanitize_text_field( wp_unslash( $_POST['account_second_driver_name'] ) ) );
		}

		if ( isset( $_POST['account_second_driver_date_of_birth'] ) ) {
			$second_driver_date_of_birth = sanitize_text_field( wp_unslash( $_POST['account_second_driver_date_of_birth'] ) );
			if ( ! empty( $second_driver_date_of_birth ) && strtotime( $second_driver_date_of_birth ) > $today ) {
				wc_add_notice( __( 'Second driver date of birth cannot be in the future.', 'vanjorn-rental-pos' ), 'error' );
			} else {
				update_user_meta( $user_id, 'second_driver_date_of_birth', $second_driver_date_of_birth );
			}
		}

		if ( isset( $_POST['account_second_driver_license_issue_date'] ) ) {
			$second_driver_license_issue_date = sanitize_text_field( wp_unslash( $_POST['account_second_driver_license_issue_date'] ) );
			if ( ! empty( $second_driver_license_issue_date ) && strtotime( $second_driver_license_issue_date ) > $today ) {
				wc_add_notice( __( 'Second driver license issue date cannot be in the future.', 'vanjorn-rental-pos' ), 'error' );
			} else {
				update_user_meta( $user_id, 'second_driver_license_issue_date', $second_driver_license_issue_date );
			}
		}

		if ( isset( $_POST['account_second_driver_license_obtained_date'] ) ) {
			$second_driver_license_obtained_date = sanitize_text_field( wp_unslash( $_POST['account_second_driver_license_obtained_date'] ) );
			if ( ! empty( $second_driver_license_obtained_date ) && strtotime( $second_driver_license_obtained_date ) > $today ) {
				wc_add_notice( __( 'Second driver license obtained date cannot be in the future.', 'vanjorn-rental-pos' ), 'error' );
			} else {
				update_user_meta( $user_id, 'second_driver_license_obtained_date', $second_driver_license_obtained_date );
			}
		}
	}

	/* =========================================================================
	 * Checkout Fields
	 * ========================================================================= */

	/**
	 * Customize WooCommerce checkout fields for driver details.
	 *
	 * @param array $fields Existing checkout fields.
	 * @return array Modified fields.
	 */
	public static function customize_checkout_fields( $fields ) {
		$fields['billing']['billing_first_name']['label']       = __( 'Initials', 'vanjorn-rental-pos' );
		$fields['billing']['billing_first_name']['placeholder'] = __( 'Initials', 'vanjorn-rental-pos' );
		$fields['billing']['billing_first_name']['required']    = true;
		$fields['billing']['billing_first_name']['class']       = array( 'form-row-first' );
		$fields['billing']['billing_first_name']['priority']    = 10;

		$fields['billing']['billing_middle_name'] = array(
			'label'       => __( 'Name', 'vanjorn-rental-pos' ),
			'placeholder' => __( 'Name', 'vanjorn-rental-pos' ),
			'required'    => true,
			'class'       => array( 'form-row-last' ),
			'priority'    => 20,
		);

		$fields['billing']['billing_last_name']['label']       = __( 'Last name', 'vanjorn-rental-pos' );
		$fields['billing']['billing_last_name']['placeholder'] = __( 'Last name', 'vanjorn-rental-pos' );
		$fields['billing']['billing_last_name']['required']    = true;
		$fields['billing']['billing_last_name']['class']       = array( 'form-row-wide' );
		$fields['billing']['billing_last_name']['priority']    = 30;

		$fields['billing']['billing_email']['label']    = __( 'Email', 'vanjorn-rental-pos' );
		$fields['billing']['billing_email']['placeholder'] = __( 'Email', 'vanjorn-rental-pos' );
		$fields['billing']['billing_email']['priority'] = 40;

		$fields['billing']['billing_phone']['label']       = __( 'Phone', 'vanjorn-rental-pos' );
		$fields['billing']['billing_phone']['placeholder'] = '12345678';
		$fields['billing']['billing_phone']['required']    = true;
		$fields['billing']['billing_phone']['priority']    = 50;

		$fields['billing']['billing_address_1']['label']       = __( 'Address', 'vanjorn-rental-pos' );
		$fields['billing']['billing_address_1']['placeholder'] = __( 'Address', 'vanjorn-rental-pos' );
		$fields['billing']['billing_address_1']['priority']    = 60;

		$fields['billing']['billing_postcode']['label']       = __( 'Postcode', 'vanjorn-rental-pos' );
		$fields['billing']['billing_postcode']['placeholder'] = __( 'Postcode', 'vanjorn-rental-pos' );
		$fields['billing']['billing_postcode']['priority']    = 70;

		$fields['billing']['billing_city']['label']       = __( 'City', 'vanjorn-rental-pos' );
		$fields['billing']['billing_city']['placeholder'] = __( 'City', 'vanjorn-rental-pos' );
		$fields['billing']['billing_city']['priority']    = 80;

		$fields['billing']['billing_country']['label']    = __( 'Country', 'vanjorn-rental-pos' );
		$fields['billing']['billing_country']['required'] = true;
		$fields['billing']['billing_country']['priority'] = 90;

		// Pre-fill from user meta when logged in.
		$user_id                             = get_current_user_id();
		$date_of_birth                       = $user_id ? get_user_meta( $user_id, 'date_of_birth', true ) : '';
		$driver_license_issue_date           = $user_id ? get_user_meta( $user_id, 'driver_license_issue_date', true ) : '';
		$driver_license_obtained_date        = $user_id ? get_user_meta( $user_id, 'driver_license_obtained_date', true ) : '';
		$second_driver_name                  = $user_id ? get_user_meta( $user_id, 'second_driver_name', true ) : '';
		$second_driver_date_of_birth         = $user_id ? get_user_meta( $user_id, 'second_driver_date_of_birth', true ) : '';
		$second_driver_license_issue_date    = $user_id ? get_user_meta( $user_id, 'second_driver_license_issue_date', true ) : '';
		$second_driver_license_obtained_date = $user_id ? get_user_meta( $user_id, 'second_driver_license_obtained_date', true ) : '';

		$fields['billing']['driver_date_of_birth'] = array(
			'label'             => __( 'Date of birth', 'vanjorn-rental-pos' ),
			'placeholder'       => __( 'Date of birth', 'vanjorn-rental-pos' ),
			'required'          => true,
			'type'              => 'date',
			'class'             => array( 'form-row-first' ),
			'priority'          => 100,
			'default'           => $date_of_birth,
			'custom_attributes' => array( 'max' => wp_date( 'Y-m-d' ) ),
		);

		$fields['billing']['driver_license_issue_date'] = array(
			'label'       => __( 'Date of issue of driving license', 'vanjorn-rental-pos' ),
			'placeholder' => __( 'Date of issue of driving license', 'vanjorn-rental-pos' ),
			'required'    => true,
			'type'        => 'date',
			'class'       => array( 'form-row-last' ),
			'priority'    => 110,
			'default'     => $driver_license_issue_date,
		);

		$fields['billing']['driver_license_obtained_date'] = array(
			'label'       => __( 'Date of obtaining driving license', 'vanjorn-rental-pos' ),
			'placeholder' => __( 'Date of obtaining driving license', 'vanjorn-rental-pos' ),
			'required'    => true,
			'type'        => 'date',
			'class'       => array( 'form-row-wide' ),
			'priority'    => 120,
			'default'     => $driver_license_obtained_date,
		);

		$fields['billing']['second_driver_name'] = array(
			'label'       => __( 'Initials and surname of second driver', 'vanjorn-rental-pos' ),
			'placeholder' => __( 'Initials and surname of second driver', 'vanjorn-rental-pos' ),
			'required'    => false,
			'class'       => array( 'form-row-wide' ),
			'priority'    => 140,
			'default'     => $second_driver_name,
		);

		$fields['billing']['second_driver_date_of_birth'] = array(
			'label'             => __( 'Date of birth of second driver', 'vanjorn-rental-pos' ),
			'placeholder'       => __( 'Date of birth of second driver', 'vanjorn-rental-pos' ),
			'required'          => false,
			'type'              => 'date',
			'class'             => array( 'form-row-first' ),
			'priority'          => 150,
			'default'           => $second_driver_date_of_birth,
			'custom_attributes' => array( 'max' => wp_date( 'Y-m-d' ) ),
		);

		$fields['billing']['second_driver_license_issue_date'] = array(
			'label'       => __( 'Date of issue of driving license of second driver', 'vanjorn-rental-pos' ),
			'placeholder' => __( 'Date of issue of driving license of second driver', 'vanjorn-rental-pos' ),
			'required'    => false,
			'type'        => 'date',
			'class'       => array( 'form-row-last' ),
			'priority'    => 160,
			'default'     => $second_driver_license_issue_date,
		);

		$fields['billing']['second_driver_license_obtained_date'] = array(
			'label'       => __( 'Date of obtaining driving license for second driver', 'vanjorn-rental-pos' ),
			'placeholder' => __( 'Date of obtaining driving license for second driver', 'vanjorn-rental-pos' ),
			'required'    => false,
			'type'        => 'date',
			'class'       => array( 'form-row-wide' ),
			'priority'    => 170,
			'default'     => $second_driver_license_obtained_date,
		);

		if ( isset( $fields['order']['order_comments'] ) ) {
			$fields['order']['order_comments']['label']       = __( 'Order notes', 'woocommerce' );
			$fields['order']['order_comments']['placeholder'] = esc_attr__( 'Notes about your order, e.g. special notes for delivery.', 'woocommerce' );
			$fields['order']['order_comments']['priority']    = 30;
			$fields['order']['order_comments']['required']    = false;
		}

		return $fields;
	}

	/**
	 * Prefill checkout fields from user meta.
	 *
	 * @param mixed  $value Default value.
	 * @param string $input Field input name.
	 * @return mixed
	 */
	public static function prefill_checkout_fields( $value, $input ) {
		if ( ! is_user_logged_in() || ! empty( $value ) ) {
			return $value;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $value;
		}

		$field_mapping = array(
			'billing_middle_name'                  => 'billing_middle_name',
			'driver_date_of_birth'                 => 'date_of_birth',
			'driver_license_issue_date'            => 'driver_license_issue_date',
			'driver_license_obtained_date'         => 'driver_license_obtained_date',
			'second_driver_name'                   => 'second_driver_name',
			'second_driver_date_of_birth'          => 'second_driver_date_of_birth',
			'second_driver_license_issue_date'     => 'second_driver_license_issue_date',
			'second_driver_license_obtained_date'  => 'second_driver_license_obtained_date',
		);

		if ( isset( $field_mapping[ $input ] ) ) {
			$meta_value = get_user_meta( $user_id, $field_mapping[ $input ], true );
			if ( ! empty( $meta_value ) ) {
				return $meta_value;
			}
		}

		return $value;
	}

	/**
	 * Save custom checkout fields to order meta (HPOS-compatible).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function save_checkout_fields( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$user_id = $order->get_user_id();

		$driver_fields = array(
			'driver_date_of_birth'                => array( '_driver_date_of_birth',                'date_of_birth' ),
			'driver_license_issue_date'           => array( '_driver_license_issue_date',           'driver_license_issue_date' ),
			'driver_license_obtained_date'        => array( '_driver_license_obtained_date',        'driver_license_obtained_date' ),
			'billing_middle_name'                 => array( '_billing_middle_name',                 'billing_middle_name' ),
			'second_driver_name'                  => array( '_second_driver_name',                  'second_driver_name' ),
			'second_driver_date_of_birth'         => array( '_second_driver_date_of_birth',         'second_driver_date_of_birth' ),
			'second_driver_license_issue_date'    => array( '_second_driver_license_issue_date',    'second_driver_license_issue_date' ),
			'second_driver_license_obtained_date' => array( '_second_driver_license_obtained_date', 'second_driver_license_obtained_date' ),
		);

		$date_fields = array(
			'driver_date_of_birth',
			'driver_license_issue_date',
			'driver_license_obtained_date',
			'second_driver_date_of_birth',
			'second_driver_license_issue_date',
			'second_driver_license_obtained_date',
		);

		$today = strtotime( wp_date( 'Y-m-d' ) );

		foreach ( $driver_fields as $post_key => $meta_keys ) {
			if ( ! isset( $_POST[ $post_key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );

			if ( in_array( $post_key, $date_fields, true ) && ! empty( $value ) && strtotime( $value ) > $today ) {
				continue;
			}

			$order->update_meta_data( $meta_keys[0], $value );

			if ( $user_id ) {
				update_user_meta( $user_id, $meta_keys[1], $value );
			}
		}

		$order->save();
	}

	/* =========================================================================
	 * Admin Display
	 * ========================================================================= */

	/**
	 * Display custom driver fields in admin order details.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function display_custom_fields_in_admin( $order ) {
		?>
		<div class="order_data_column">
			<h3><?php esc_html_e( 'Driver details', 'vanjorn-rental-pos' ); ?></h3>
			<?php
			$middle_name      = $order->get_meta( '_billing_middle_name' );
			$dob              = $order->get_meta( '_driver_date_of_birth' );
			$license_issue    = $order->get_meta( '_driver_license_issue_date' );
			$license_obtained = $order->get_meta( '_driver_license_obtained_date' );

			if ( $middle_name ) {
				echo '<p><strong>' . esc_html__( 'Middle name:', 'vanjorn-rental-pos' ) . '</strong> ' . esc_html( $middle_name ) . '</p>';
			}
			if ( $dob ) {
				echo '<p><strong>' . esc_html__( 'Date of birth:', 'vanjorn-rental-pos' ) . '</strong> ' . esc_html( $dob ) . '</p>';
			}
			if ( $license_issue ) {
				echo '<p><strong>' . esc_html__( 'License issue date:', 'vanjorn-rental-pos' ) . '</strong> ' . esc_html( $license_issue ) . '</p>';
			}
			if ( $license_obtained ) {
				echo '<p><strong>' . esc_html__( 'License obtained date:', 'vanjorn-rental-pos' ) . '</strong> ' . esc_html( $license_obtained ) . '</p>';
			}
			?>
		</div>
		<div class="order_data_column">
			<h3><?php esc_html_e( 'Second driver details', 'vanjorn-rental-pos' ); ?></h3>
			<?php
			$second_driver_name             = $order->get_meta( '_second_driver_name' );
			$second_driver_dob              = $order->get_meta( '_second_driver_date_of_birth' );
			$second_driver_license_issue    = $order->get_meta( '_second_driver_license_issue_date' );
			$second_driver_license_obtained = $order->get_meta( '_second_driver_license_obtained_date' );

			if ( $second_driver_name ) {
				echo '<p><strong>' . esc_html__( 'Name:', 'vanjorn-rental-pos' ) . '</strong> ' . esc_html( $second_driver_name ) . '</p>';
			}
			if ( $second_driver_dob ) {
				echo '<p><strong>' . esc_html__( 'Date of birth:', 'vanjorn-rental-pos' ) . '</strong> ' . esc_html( $second_driver_dob ) . '</p>';
			}
			if ( $second_driver_license_issue ) {
				echo '<p><strong>' . esc_html__( 'License issue date:', 'vanjorn-rental-pos' ) . '</strong> ' . esc_html( $second_driver_license_issue ) . '</p>';
			}
			if ( $second_driver_license_obtained ) {
				echo '<p><strong>' . esc_html__( 'License obtained date:', 'vanjorn-rental-pos' ) . '</strong> ' . esc_html( $second_driver_license_obtained ) . '</p>';
			}
			?>
		</div>
		<?php
	}
}
