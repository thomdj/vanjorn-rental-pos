<?php
/**
 * VanPOS PDF Rendering Helpers
 *
 * Global helper functions called from the Vanjorn WCPDF invoice template.
 * Migrated from woocommerce/pdf/Vanjorn/template-functions.php in the child theme.
 *
 * The invoice.php and packing-slip.php templates remain in the theme's PDF
 * template directory (woocommerce/pdf/Vanjorn/) because WCPDF selects them
 * by admin-configured path. These functions are available globally wherever
 * WCPDF renders, since the plugin loads before any template rendering.
 *
 * The woocommerce_hidden_order_itemmeta filter that was in template-functions.php
 * is intentionally NOT reproduced here — all seven of those keys are already
 * hidden by VanPOS_Item_Display::hide_default_meta().
 *
 * @package VJ_Rental_POS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================
// WPO IPS (Ink Saving) integration
// ============================================================

add_filter(
	'wpo_ips_ink_saving_supported_templates',
	function ( $templates ) {
		$templates[] = 'theme/Vanjorn';
		return $templates;
	}
);

add_filter(
	'wpo_ips_ink_saving_css',
	function ( $css, $document, $current_template ) {
		if ( 'theme/Vanjorn' !== $current_template ) {
			return $css;
		}

		$css .= '
		.order-details thead th {
			color: black;
			background-color: white;
			border: 0;
			border-bottom: 0.8pt solid black;
		}
		.notes-totals .totals tfoot tr.order_total th,
		.notes-totals .totals tfoot tr.order_total td {
			border-top: .8pt solid black;
			border-bottom: .8pt solid black;
		}
	';

		return $css;
	},
	10,
	3
);

// ============================================================
// Rental period helpers
// ============================================================

/**
 * Read rental dates/times from the first order item that has them.
 *
 * @param WC_Order $order
 * @return array{pickup_date:string,return_date:string,pickup_time:string,return_time:string}
 */
function vanjorn_get_rental_period( $order ) {
	$period = array(
		'pickup_date' => '',
		'return_date' => '',
		'pickup_time' => '',
		'return_time' => '',
	);

	if ( ! $order instanceof WC_Order ) {
		return $period;
	}

	foreach ( $order->get_items() as $item ) {
		$pickup = $item->get_meta( 'vanpos_pickup_date' );
		if ( empty( $pickup ) ) {
			continue;
		}

		$period['pickup_date'] = $pickup;
		$period['return_date'] = $item->get_meta( 'vanpos_return_date' );
		$period['pickup_time'] = $item->get_meta( 'vanpos_pickup_time' );
		$period['return_time'] = $item->get_meta( 'vanpos_return_time' );
		break;
	}

	return $period;
}

/**
 * Format a rental period as a human-readable string.
 *
 * @param array $period Array as returned by vanjorn_get_rental_period().
 * @return string Empty string if dates are missing.
 */
function vanjorn_format_rental_period( $period ) {
	if ( empty( $period['pickup_date'] ) || empty( $period['return_date'] ) ) {
		return '';
	}

	$date_format = get_option( 'date_format' );
	$pickup      = date_i18n( $date_format, strtotime( $period['pickup_date'] ) );
	$return      = date_i18n( $date_format, strtotime( $period['return_date'] ) );

	if ( ! empty( $period['pickup_time'] ) ) {
		$pickup .= ' ' . $period['pickup_time'];
	}
	if ( ! empty( $period['return_time'] ) ) {
		$return .= ' ' . $period['return_time'];
	}

	/* translators: %1$s: pickup date and time, %2$s: return date and time */
	return sprintf( __( '%1$s to %2$s', 'vanjorn-rental-pos' ), $pickup, $return );
}

/**
 * Render the rental-period banner. Echoes a full-width row.
 * Silently outputs nothing when the order has no rental dates.
 *
 * @param WC_Order $order
 */
function vanjorn_render_pdf_rental_period_banner( $order ) {
	$period = vanjorn_get_rental_period( $order );
	$text   = vanjorn_format_rental_period( $period );

	if ( '' === $text ) {
		return;
	}
	?>
	<table class="rental-period-banner">
		<tr>
			<th class="label"><?php esc_html_e( 'Rental period', 'vanjorn-rental-pos' ); ?></th>
			<td class="value"><?php echo esc_html( $text ); ?></td>
		</tr>
	</table>
	<?php
}

// ============================================================
// Driver details helper
// ============================================================

/**
 * Render the driver details section (primary + optional second driver).
 *
 * Mirrors the structure of the My Account order-details section.
 * Skips cleanly if no driver data is present on the order.
 *
 * @param WC_Order $order
 */
function vanjorn_render_pdf_driver_details( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$date_format = get_option( 'date_format' );

	/**
	 * Returns true only if the value is a real, plausibly-correct past date.
	 * Rejects empty strings, "0000-00-00", epoch, and dates on/after the order.
	 *
	 * @param mixed $raw      Raw stored value.
	 * @param int   $order_ts Order creation timestamp.
	 * @return bool
	 */
	$is_valid_past_date = function ( $raw, $order_ts ) {
		if ( ! is_scalar( $raw ) ) {
			return false;
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return false;
		}
		if ( in_array( $raw, array( '0000-00-00', '0000-00-00 00:00:00', '1970-01-01' ), true ) ) {
			return false;
		}
		$ts = strtotime( $raw );
		if ( ! $ts ) {
			return false;
		}
		if ( $ts < strtotime( '1900-01-01' ) ) {
			return false;
		}
		if ( $ts >= ( $order_ts - DAY_IN_SECONDS ) ) {
			return false;
		}
		return true;
	};

	$order_ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();

	$rows = array();

	$voorletters = $order->get_billing_first_name();
	if ( $voorletters ) {
		$rows[] = array( __( 'Initials', 'vanjorn-rental-pos' ), $voorletters );
	}

	$middle_name = $order->get_meta( '_billing_middle_name' );
	if ( $middle_name ) {
		$rows[] = array( __( 'Name', 'vanjorn-rental-pos' ), $middle_name );
	}

	$last_name = $order->get_billing_last_name();
	if ( $last_name ) {
		$rows[] = array( __( 'Last name', 'vanjorn-rental-pos' ), $last_name );
	}

	$email = $order->get_billing_email();
	if ( $email ) {
		$rows[] = array( __( 'Email', 'vanjorn-rental-pos' ), $email );
	}

	$phone = $order->get_billing_phone();
	if ( $phone ) {
		$rows[] = array( __( 'Phone', 'vanjorn-rental-pos' ), $phone );
	}

	$address_1 = $order->get_billing_address_1();
	if ( $address_1 ) {
		$rows[] = array( __( 'Address', 'vanjorn-rental-pos' ), $address_1 );
	}

	$postcode = $order->get_billing_postcode();
	if ( $postcode ) {
		$rows[] = array( __( 'Postcode', 'vanjorn-rental-pos' ), $postcode );
	}

	$city = $order->get_billing_city();
	if ( $city ) {
		$rows[] = array( __( 'City', 'vanjorn-rental-pos' ), $city );
	}

	$country_code = $order->get_billing_country();
	if ( $country_code ) {
		$countries = WC()->countries ? WC()->countries->get_countries() : array();
		$country   = isset( $countries[ $country_code ] ) ? $countries[ $country_code ] : $country_code;
		$rows[]    = array( __( 'Country', 'vanjorn-rental-pos' ), $country );
	}

	$dob = $order->get_meta( '_driver_date_of_birth' );
	if ( $is_valid_past_date( $dob, $order_ts ) ) {
		$rows[] = array( __( 'Date of birth', 'vanjorn-rental-pos' ), date_i18n( $date_format, strtotime( $dob ) ) );
	}

	$license_issue = $order->get_meta( '_driver_license_issue_date' );
	if ( $is_valid_past_date( $license_issue, $order_ts ) ) {
		$rows[] = array( __( 'Date of issue of driving license', 'vanjorn-rental-pos' ), date_i18n( $date_format, strtotime( $license_issue ) ) );
	}

	$license_obtained = $order->get_meta( '_driver_license_obtained_date' );
	if ( $is_valid_past_date( $license_obtained, $order_ts ) ) {
		$rows[] = array( __( 'Date of obtaining driving license', 'vanjorn-rental-pos' ), date_i18n( $date_format, strtotime( $license_obtained ) ) );
	}

	// Second driver — entirely optional.
	$second_rows        = array();
	$second_driver_name = $order->get_meta( '_second_driver_name' );

	if ( $second_driver_name ) {
		$second_rows[] = array( __( 'Initials and surname of second driver', 'vanjorn-rental-pos' ), $second_driver_name );

		$second_dob = $order->get_meta( '_second_driver_date_of_birth' );
		if ( $is_valid_past_date( $second_dob, $order_ts ) ) {
			$second_rows[] = array( __( 'Date of birth of second driver', 'vanjorn-rental-pos' ), date_i18n( $date_format, strtotime( $second_dob ) ) );
		}

		$second_license_issue = $order->get_meta( '_second_driver_license_issue_date' );
		if ( $is_valid_past_date( $second_license_issue, $order_ts ) ) {
			$second_rows[] = array( __( 'Date of issue of driving license of second driver', 'vanjorn-rental-pos' ), date_i18n( $date_format, strtotime( $second_license_issue ) ) );
		}

		$second_license_obtained = $order->get_meta( '_second_driver_license_obtained_date' );
		if ( $is_valid_past_date( $second_license_obtained, $order_ts ) ) {
			$second_rows[] = array( __( 'Date of obtaining driving license for second driver', 'vanjorn-rental-pos' ), date_i18n( $date_format, strtotime( $second_license_obtained ) ) );
		}
	}

	if ( empty( $rows ) && empty( $second_rows ) ) {
		return;
	}
	?>
	<div class="driver-details-section">
		<?php if ( ! empty( $rows ) ) : ?>
			<h3 class="driver-details-title"><?php esc_html_e( 'Billing & driver details', 'vanjorn-rental-pos' ); ?></h3>
			<table class="driver-details-table">
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th><?php echo esc_html( $row[0] ); ?></th>
						<td><?php echo esc_html( $row[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $second_rows ) ) : ?>
			<h3 class="driver-details-title second-driver-title"><?php esc_html_e( 'Second driver details', 'vanjorn-rental-pos' ); ?></h3>
			<table class="driver-details-table">
				<?php foreach ( $second_rows as $row ) : ?>
					<tr>
						<th><?php echo esc_html( $row[0] ); ?></th>
						<td><?php echo esc_html( $row[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

// ============================================================
// Payment terms and legal footer
// ============================================================

/**
 * Render the payment terms block (installments, Mollie, T&Cs).
 * Echoed once per invoice, after the totals.
 */
function vanjorn_render_pdf_payment_terms() {
	?>
	<div class="payment-terms">
		<p>
			<?php esc_html_e( 'The amount due is payable in two installments via payment provider Mollie: 50% on reservation and 50% four weeks before departure.', 'vanjorn-rental-pos' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Our general rental terms and conditions apply to all rentals. They are available on our website: verhuur.vanjorn.com.', 'vanjorn-rental-pos' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Render the per-page legal footer (KvK / BTW / IBAN / website).
 * Echoed inside the mPDF <htmlpagefooter> block in invoice.php.
 */
function vanjorn_render_pdf_legal_footer() {
	?>
	<div class="legal-footer">
		<span>VAN-Jorn BV</span> &middot;
		<span>KvK 85536903</span> &middot;
		<span>BTW NL863657795B01</span> &middot;
		<span>IBAN NL83 RABO 0337 4650 53</span> &middot;
		<span>www.vanjorn.com</span>
	</div>
	<?php
}
