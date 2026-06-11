<?php
/**
 * VanPOS admin settings field render trait.
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait VanPOS_Admin_Settings_Fields {

	public function render_pickup_days_field() {
		$settings    = VanPOS_Functions::get_settings();
		$pickup_days = isset( $settings['vanpos_pickup_days'] ) ? $settings['vanpos_pickup_days'] : array( 4, 5 );
		$days        = array(
			1 => __( 'Monday', 'vanjorn-rental-pos' ),
			2 => __( 'Tuesday', 'vanjorn-rental-pos' ),
			3 => __( 'Wednesday', 'vanjorn-rental-pos' ),
			4 => __( 'Thursday', 'vanjorn-rental-pos' ),
			5 => __( 'Friday', 'vanjorn-rental-pos' ),
			6 => __( 'Saturday', 'vanjorn-rental-pos' ),
			0 => __( 'Sunday', 'vanjorn-rental-pos' ),
		);
		?>
		<fieldset>
			<?php foreach ( $days as $day_num => $day_name ) : ?>
				<label>
					<input type="checkbox" name="vanpos_settings[vanpos_pickup_days][]" value="<?php echo esc_attr( $day_num ); ?>" <?php checked( in_array( $day_num, $pickup_days, true ) ); ?>>
					<?php echo esc_html( $day_name ); ?>
				</label><br>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Select which days of the week are available for pickup and return.', 'vanjorn-rental-pos' ); ?></p>
		</fieldset>
		<?php
	}

	public function render_min_rental_days_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_min_rental_days'] ) ? $settings['vanpos_min_rental_days'] : 6;
		?>
		<input type="number" name="vanpos_settings[vanpos_min_rental_days]" value="<?php echo esc_attr( $value ); ?>" min="1" step="1">
		<p class="description"><?php esc_html_e( 'Minimum number of days for a rental period.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_max_rental_days_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_max_rental_days'] ) ? $settings['vanpos_max_rental_days'] : 22;
		?>
		<input type="number" name="vanpos_settings[vanpos_max_rental_days]" value="<?php echo esc_attr( $value ); ?>" min="1" step="1">
		<p class="description"><?php esc_html_e( 'Maximum number of days for a rental period.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_time_slots_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_time_slots_enabled'] ) ? $settings['vanpos_time_slots_enabled'] : 'yes';
		?>
		<label>
			<input type="checkbox" name="vanpos_settings[vanpos_time_slots_enabled]" value="yes" <?php checked( $value, 'yes' ); ?>>
			<?php esc_html_e( 'Enable morning/afternoon time slot selection', 'vanjorn-rental-pos' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Allow customers to select morning or afternoon time slots for pickup and return.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_pickup_time_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_pickup_time'] ) ? $settings['vanpos_pickup_time'] : '15:00';
		?>
		<input type="time" name="vanpos_settings[vanpos_pickup_time]" value="<?php echo esc_attr( $value ); ?>">
		<p class="description"><?php esc_html_e( 'The pickup time shown on the calendar and in booking confirmations. Default: 15:00.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_return_time_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_return_time'] ) ? $settings['vanpos_return_time'] : '11:00';
		?>
		<input type="time" name="vanpos_settings[vanpos_return_time]" value="<?php echo esc_attr( $value ); ?>">
		<p class="description"><?php esc_html_e( 'The return time shown on the calendar and in booking confirmations. Default: 11:00.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_dog_enabled_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_dog_enabled'] ) ? $settings['vanpos_dog_enabled'] : 'yes';
		?>
		<label>
			<input type="checkbox" name="vanpos_settings[vanpos_dog_enabled]" value="yes" <?php checked( $value, 'yes' ); ?>>
			<?php esc_html_e( 'Enable "Bring your dog" option', 'vanjorn-rental-pos' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Allow customers to add a dog option to their booking.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_dog_price_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_dog_price'] ) ? $settings['vanpos_dog_price'] : 100;
		?>
		<input type="number" name="vanpos_settings[vanpos_dog_price]" value="<?php echo esc_attr( $value ); ?>" min="0" step="0.01">
		<p class="description"><?php esc_html_e( 'Price for the dog option.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_cleaning_enabled_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_cleaning_enabled'] ) ? $settings['vanpos_cleaning_enabled'] : 'yes';
		?>
		<label>
			<input type="checkbox" name="vanpos_settings[vanpos_cleaning_enabled]" value="yes" <?php checked( $value, 'yes' ); ?>>
			<?php esc_html_e( 'Enable "Cleaning service" option', 'vanjorn-rental-pos' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Allow customers to add a cleaning service option to their booking.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_cleaning_price_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_cleaning_price'] ) ? $settings['vanpos_cleaning_price'] : 100;
		?>
		<input type="number" name="vanpos_settings[vanpos_cleaning_price]" value="<?php echo esc_attr( $value ); ?>" min="0" step="0.01">
		<p class="description"><?php esc_html_e( 'Price for the cleaning service option.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_deposit_enabled_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_deposit_enabled'] ) ? $settings['vanpos_deposit_enabled'] : 'yes';
		?>
		<label>
			<input type="checkbox" name="vanpos_settings[vanpos_deposit_enabled]" value="yes" <?php checked( $value, 'yes' ); ?>>
			<?php esc_html_e( 'Enable deposit payments for rental products', 'vanjorn-rental-pos' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When enabled, customers will pay a deposit percentage at checkout. A child order will be created for the remaining payment.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_deposit_percentage_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_deposit_percentage'] ) ? $settings['vanpos_deposit_percentage'] : 50;
		?>
		<input type="number" name="vanpos_settings[vanpos_deposit_percentage]" value="<?php echo esc_attr( $value ); ?>" min="0" max="100" step="0.1">
		<span>%</span>
		<p class="description"><?php esc_html_e( 'Percentage of total rental price to collect as deposit. The remaining amount will be due later via a child order.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_security_deposit_days_field() {
		$settings = VanPOS_Functions::get_settings();
		$value    = isset( $settings['vanpos_security_deposit_days_before_pickup'] ) ? $settings['vanpos_security_deposit_days_before_pickup'] : 14;
		?>
		<input type="number" name="vanpos_settings[vanpos_security_deposit_days_before_pickup]" value="<?php echo esc_attr( $value ); ?>" min="0" step="1">
		<span><?php esc_html_e( 'days', 'vanjorn-rental-pos' ); ?></span>
		<p class="description"><?php esc_html_e( 'Number of days before pickup date that the security deposit is due. Default: 14 days.', 'vanjorn-rental-pos' ); ?></p>
		<?php
	}

	public function render_due_date_days_field() {
		$settings    = VanPOS_Functions::get_settings();
		$value       = isset( $settings['vanpos_due_date_days'] ) ? $settings['vanpos_due_date_days'] : 7;
		$deposit_pct = isset( $settings['vanpos_deposit_percentage'] ) ? (int) $settings['vanpos_deposit_percentage'] : 50;
		$remaining   = 100 - $deposit_pct;
		?>
		<input type="number" name="vanpos_settings[vanpos_due_date_days]" value="<?php echo esc_attr( $value ); ?>" min="0" step="1">
		<span><?php esc_html_e( 'days', 'vanjorn-rental-pos' ); ?></span>
		<p class="description">
			<?php
			printf(
				/* translators: %d is the remaining payment percentage */
				esc_html__( 'Number of days before pickup date that the remaining %d%% payment is due. Default: 7 days.', 'vanjorn-rental-pos' ),
				$remaining
			);
			?>
		</p>
		<?php
	}

	public function render_security_deposit_product_field() {
		$settings            = VanPOS_Functions::get_settings();
		$selected_product_id = isset( $settings['vanpos_security_deposit_product_id'] ) ? $settings['vanpos_security_deposit_product_id'] : '';
		$products            = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'ids' ) );
		?>
		<select name="vanpos_settings[vanpos_security_deposit_product_id]" style="width: 400px;" class="regular-text">
			<option value=""><?php esc_html_e( '-- Select Product --', 'vanjorn-rental-pos' ); ?></option>
			<?php foreach ( $products as $product_id ) : ?>
				<?php $product = wc_get_product( $product_id ); ?>
				<?php if ( ! $product ) { continue; } ?>
				<?php $product_name = $product->get_name() . ' (#' . $product_id . ') - ' . wp_strip_all_tags( wc_price( $product->get_price() ) ); ?>
				<option value="<?php echo esc_attr( $product_id ); ?>" <?php selected( $selected_product_id, $product_id ); ?>>
					<?php echo esc_html( $product_name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Select the product to use for security deposit payments. This product should be set as virtual, no tax, and hidden from catalog. The price of this product will be used as the security deposit amount (typically €1,000).', 'vanjorn-rental-pos' ); ?></p>
		<?php if ( $selected_product_id ) : ?>
			<?php $product = wc_get_product( $selected_product_id ); ?>
			<?php if ( $product ) : ?>
				<p>
					<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $selected_product_id . '&action=edit' ) ); ?>" class="button button-small" target="_blank"><?php esc_html_e( 'Edit Product', 'vanjorn-rental-pos' ); ?></a>
					<span style="margin-left: 10px;">
						<?php printf( esc_html__( 'Current price: %s', 'vanjorn-rental-pos' ), wc_price( $product->get_price() ) ); ?>
					</span>
				</p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

}
