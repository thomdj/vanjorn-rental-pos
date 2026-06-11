<?php
/**
 * Van Specifications Product Tab for VAN-Jorn Rental POS
 *
 * Adds a custom product tab to display ACF rental van data fields
 * on the WooCommerce product detail page. Migrated from child theme.
 *
 * Fields are loaded dynamically from the ACF field group so that
 * adding or removing fields in the admin automatically updates
 * the specifications table — no code changes required.
 *
 * ACF FIELD GROUP: group_695f427a2eaa6 ("Verhuurbus data")
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'VANPOS_VAN_SPECS_GROUP_KEY' ) ) {
	define( 'VANPOS_VAN_SPECS_GROUP_KEY', 'group_695f427a2eaa6' );
}

if ( ! defined( 'VANPOS_VAN_SPECS_SKIP_TYPES' ) ) {
	define( 'VANPOS_VAN_SPECS_SKIP_TYPES', serialize( array( 'gallery', 'image' ) ) );
}

/**
 * Product Tabs Class
 */
class VanPOS_Product_Tabs {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'add_rental_van_details_tab' ) );
	}

	/**
	 * Add custom rental van details tab.
	 *
	 * @param array $tabs Existing product tabs.
	 * @return array
	 */
	public static function add_rental_van_details_tab( $tabs ) {
		global $product;

		if ( ! $product ) {
			return $tabs;
		}

		$product_id = $product->get_id();
		$fields     = self::get_van_spec_fields();

		if ( empty( $fields ) ) {
			return $tabs;
		}

		$has_data = false;
		foreach ( $fields as $field ) {
			$value = get_field( $field['name'], $product_id );
			if ( self::field_has_value( $value ) ) {
				$has_data = true;
				break;
			}
		}

		if ( $has_data ) {
			$tabs['rental_van_details'] = array(
				'title'    => __( 'Van specifications', 'vanjorn-rental-pos' ),
				'priority' => 25,
				'callback' => array( __CLASS__, 'render_tab_content' ),
			);
		}

		return $tabs;
	}

	/**
	 * Render rental van details tab content.
	 */
	public static function render_tab_content() {
		global $product;

		if ( ! $product ) {
			return;
		}

		$product_id        = $product->get_id();
		$fields            = self::get_van_spec_fields();
		$fields_to_display = array();

		foreach ( $fields as $field ) {
			$value = get_field( $field['name'], $product_id );
			if ( ! self::field_has_value( $value ) ) {
				continue;
			}
			$formatted_value = self::format_field_value( $value, $field['type'] );
			if ( '' !== $formatted_value ) {
				$fields_to_display[] = array(
					'label' => $field['label'],
					'value' => $formatted_value,
					'type'  => $field['type'],
				);
			}
		}

		if ( ! empty( $fields_to_display ) ) {
			?>
			<div class="vanjorn-rental-van-details">
				<h2 class="vanjorn-rental-van-details-title">
					<?php esc_html_e( 'Rental van specifications', 'vanjorn-rental-pos' ); ?>
				</h2>
				<p class="vanjorn-rental-van-details-description">
					<?php esc_html_e( 'Detailed specifications and features of this rental van.', 'vanjorn-rental-pos' ); ?>
				</p>

				<table class="vanjorn-rental-van-details-table">
					<thead>
						<tr>
							<th class="vanjorn-spec-label"><?php esc_html_e( 'Specification', 'vanjorn-rental-pos' ); ?></th>
							<th class="vanjorn-spec-value"><?php esc_html_e( 'Details', 'vanjorn-rental-pos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $fields_to_display as $field ) : ?>
							<tr class="vanjorn-spec-row vanjorn-spec-<?php echo esc_attr( $field['type'] ); ?>">
								<td class="vanjorn-spec-label-cell">
									<strong><?php echo esc_html( $field['label'] ); ?></strong>
								</td>
								<td class="vanjorn-spec-value-cell">
									<?php echo wp_kses_post( $field['value'] ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		} else {
			?>
			<div class="vanjorn-rental-van-details-empty">
				<p><?php esc_html_e( 'No rental van specifications available for this product.', 'vanjorn-rental-pos' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Get the ACF fields from the van specifications group, excluding
	 * field types that should not appear in the specs table.
	 *
	 * Results are cached per request so the ACF API is only hit once.
	 *
	 * @return array Array of field definitions (key, name, label, type).
	 */
	private static function get_van_spec_fields() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$cached = array();

		if ( ! function_exists( 'acf_get_fields' ) ) {
			return $cached;
		}

		$all_fields = acf_get_fields( VANPOS_VAN_SPECS_GROUP_KEY );

		if ( empty( $all_fields ) || ! is_array( $all_fields ) ) {
			return $cached;
		}

		$skip_types = (array) unserialize( VANPOS_VAN_SPECS_SKIP_TYPES );

		foreach ( $all_fields as $field ) {
			if ( in_array( $field['type'], $skip_types, true ) ) {
				continue;
			}
			$cached[] = array(
				'key'   => $field['key'],
				'name'  => $field['name'],
				'label' => $field['label'],
				'type'  => $field['type'],
			);
		}

		return $cached;
	}

	/**
	 * Check whether a field value is considered "filled in".
	 *
	 * @param mixed $value The ACF field value.
	 * @return bool
	 */
	private static function field_has_value( $value ) {
		if ( is_null( $value ) || false === $value || '' === $value ) {
			return false;
		}
		if ( is_array( $value ) && empty( $value ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Format a field value for display.
	 *
	 * @param mixed  $value      The ACF field value.
	 * @param string $field_type The ACF field type.
	 * @return string Formatted, escaped value.
	 */
	private static function format_field_value( $value, $field_type ) {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'esc_html', $value ) );
		}
		return esc_html( (string) $value );
	}
}
