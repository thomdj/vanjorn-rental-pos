<?php
/**
 * VanPOS admin settings registration trait.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait VanPOS_Admin_Settings_Registration {

	/**
	 * Register all settings sections/fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'vanpos_settings_group', 'vanpos_settings', array( $this, 'sanitize_settings' ) );

		add_settings_section( 'vanpos_general_section', __( 'General Settings', 'vanjorn-rental-pos' ), array( $this, 'render_general_section' ), 'vanjorn-rental-pos' );
		add_settings_field( 'vanpos_pickup_days', __( 'Pickup/Return Days', 'vanjorn-rental-pos' ), array( $this, 'render_pickup_days_field' ), 'vanjorn-rental-pos', 'vanpos_general_section' );
		add_settings_field( 'vanpos_min_rental_days', __( 'Minimum Rental Days', 'vanjorn-rental-pos' ), array( $this, 'render_min_rental_days_field' ), 'vanjorn-rental-pos', 'vanpos_general_section' );
		add_settings_field( 'vanpos_max_rental_days', __( 'Maximum Rental Days', 'vanjorn-rental-pos' ), array( $this, 'render_max_rental_days_field' ), 'vanjorn-rental-pos', 'vanpos_general_section' );
		add_settings_field( 'vanpos_time_slots_enabled', __( 'Enable Time Slots', 'vanjorn-rental-pos' ), array( $this, 'render_time_slots_field' ), 'vanjorn-rental-pos', 'vanpos_general_section' );
		add_settings_field( 'vanpos_pickup_time', __( 'Pickup Time', 'vanjorn-rental-pos' ), array( $this, 'render_pickup_time_field' ), 'vanjorn-rental-pos', 'vanpos_general_section' );
		add_settings_field( 'vanpos_return_time', __( 'Return Time', 'vanjorn-rental-pos' ), array( $this, 'render_return_time_field' ), 'vanjorn-rental-pos', 'vanpos_general_section' );
		add_settings_field( 'vanpos_dog_enabled', __( 'Enable Dog Option', 'vanjorn-rental-pos' ), array( $this, 'render_dog_enabled_field' ), 'vanjorn-rental-pos', 'vanpos_general_section' );
		add_settings_field( 'vanpos_dog_price', __( 'Dog Option Price', 'vanjorn-rental-pos' ), array( $this, 'render_dog_price_field' ), 'vanjorn-rental-pos', 'vanpos_general_section' );
		add_settings_field( 'vanpos_cleaning_enabled', __( 'Enable Cleaning Service', 'vanjorn-rental-pos' ), array( $this, 'render_cleaning_enabled_field' ), 'vanjorn-rental-pos', 'vanpos_general_section' );
		add_settings_field( 'vanpos_cleaning_price', __( 'Cleaning Service Price', 'vanjorn-rental-pos' ), array( $this, 'render_cleaning_price_field' ), 'vanjorn-rental-pos', 'vanpos_general_section' );

		add_settings_section( 'vanpos_deposit_section', __( 'Deposit Settings', 'vanjorn-rental-pos' ), array( $this, 'render_deposit_section' ), 'vanjorn-rental-pos' );
		add_settings_field( 'vanpos_deposit_enabled', __( 'Enable Deposit Payments', 'vanjorn-rental-pos' ), array( $this, 'render_deposit_enabled_field' ), 'vanjorn-rental-pos', 'vanpos_deposit_section' );
		add_settings_field( 'vanpos_deposit_percentage', __( 'Deposit Percentage', 'vanjorn-rental-pos' ), array( $this, 'render_deposit_percentage_field' ), 'vanjorn-rental-pos', 'vanpos_deposit_section' );
		add_settings_field( 'vanpos_security_deposit_days_before_pickup', __( 'Security Deposit Days Before Pickup', 'vanjorn-rental-pos' ), array( $this, 'render_security_deposit_days_field' ), 'vanjorn-rental-pos', 'vanpos_deposit_section' );
		add_settings_field( 'vanpos_due_date_days', __( 'Remaining Payment Days Before Pickup', 'vanjorn-rental-pos' ), array( $this, 'render_due_date_days_field' ), 'vanjorn-rental-pos', 'vanpos_deposit_section' );
		add_settings_field( 'vanpos_security_deposit_product_id', __( 'Security Deposit Product', 'vanjorn-rental-pos' ), array( $this, 'render_security_deposit_product_field' ), 'vanjorn-rental-pos', 'vanpos_deposit_section' );

	}

	/**
	 * Sanitize incoming settings.
	 *
	 * @param array $input Raw settings input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$existing  = VanPOS_Functions::get_settings();
		$sanitized = $existing;

		if ( isset( $input['vanpos_pickup_days'] ) && is_array( $input['vanpos_pickup_days'] ) ) {
			$sanitized['vanpos_pickup_days'] = array_map( 'intval', $input['vanpos_pickup_days'] );
		}
		if ( isset( $input['vanpos_min_rental_days'] ) ) {
			$sanitized['vanpos_min_rental_days'] = absint( $input['vanpos_min_rental_days'] );
		}
		if ( isset( $input['vanpos_max_rental_days'] ) ) {
			$sanitized['vanpos_max_rental_days'] = absint( $input['vanpos_max_rental_days'] );
		}
		$sanitized['vanpos_time_slots_enabled'] = isset( $input['vanpos_time_slots_enabled'] ) ? 'yes' : 'no';
		if ( isset( $input['vanpos_pickup_time'] ) ) {
			$sanitized['vanpos_pickup_time'] = $this->sanitize_time_hhmm(
				$input['vanpos_pickup_time'],
				isset( $existing['vanpos_pickup_time'] ) ? $existing['vanpos_pickup_time'] : '15:00'
			);
		}
		if ( isset( $input['vanpos_return_time'] ) ) {
			$sanitized['vanpos_return_time'] = $this->sanitize_time_hhmm(
				$input['vanpos_return_time'],
				isset( $existing['vanpos_return_time'] ) ? $existing['vanpos_return_time'] : '11:00'
			);
		}
		$sanitized['vanpos_dog_enabled'] = isset( $input['vanpos_dog_enabled'] ) ? 'yes' : 'no';
		if ( isset( $input['vanpos_dog_price'] ) ) {
			$sanitized['vanpos_dog_price'] = abs( floatval( $input['vanpos_dog_price'] ) );
		}
		$sanitized['vanpos_cleaning_enabled'] = isset( $input['vanpos_cleaning_enabled'] ) ? 'yes' : 'no';
		if ( isset( $input['vanpos_cleaning_price'] ) ) {
			$sanitized['vanpos_cleaning_price'] = abs( floatval( $input['vanpos_cleaning_price'] ) );
		}
		$sanitized['vanpos_deposit_enabled'] = isset( $input['vanpos_deposit_enabled'] ) ? 'yes' : 'no';
		if ( isset( $input['vanpos_deposit_percentage'] ) ) {
			$percentage                          = abs( floatval( $input['vanpos_deposit_percentage'] ) );
			$sanitized['vanpos_deposit_percentage'] = min( 100, max( 0, $percentage ) );
		}
		if ( isset( $input['vanpos_due_date_days'] ) ) {
			$sanitized['vanpos_due_date_days'] = absint( $input['vanpos_due_date_days'] );
		}
		if ( isset( $input['vanpos_security_deposit_days_before_pickup'] ) ) {
			$sanitized['vanpos_security_deposit_days_before_pickup'] = absint( $input['vanpos_security_deposit_days_before_pickup'] );
		}
		if ( isset( $input['vanpos_security_deposit_product_id'] ) ) {
			$product_id = absint( $input['vanpos_security_deposit_product_id'] );
			$sanitized['vanpos_security_deposit_product_id'] = ( $product_id > 0 && wc_get_product( $product_id ) ) ? $product_id : '';
		} else {
			$sanitized['vanpos_security_deposit_product_id'] = isset( $existing['vanpos_security_deposit_product_id'] ) ? $existing['vanpos_security_deposit_product_id'] : '';
		}
		return $sanitized;
	}

	/**
	 * Validate and sanitize an H:i time string.
	 *
	 * Accepts any value in HH:MM 24-hour format (00:00–23:59). Falls back to
	 * $default if the value is absent or does not match the pattern, so an admin
	 * typing a free-form string cannot corrupt the stored time and break downstream
	 * slot-label resolution.
	 *
	 * @param string $value   Raw input value.
	 * @param string $default Fallback value (must itself be valid H:i).
	 * @return string
	 */
	private function sanitize_time_hhmm( $value, $default ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $default;
	}

	/**
	 * Render section intro copy.
	 *
	 * @return void
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Configure general calendar and rental settings.', 'vanjorn-rental-pos' ) . '</p>';
	}

	/**
	 * Render section intro copy.
	 *
	 * @return void
	 */
	public function render_deposit_section() {
		echo '<p>' . esc_html__( 'Configure deposit payment settings for rental products. When enabled, customers will pay a percentage deposit at checkout, with the remaining amount due later.', 'vanjorn-rental-pos' ) . '</p>';
	}

}
