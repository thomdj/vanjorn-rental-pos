/**
 * VanPOS Date Restrictions & Form Reordering
 *
 * Prevents future dates on date-of-birth and license fields,
 * and moves the password section to the end of the account edit form.
 *
 * Localized strings expected in vanjornDateRestrictions object
 * (set via wp_localize_script in VanPOS_Checkout_Fields::enqueue_scripts).
 *
 * Migrated from child theme to plugin (frontend/js/).
 *
 * @package VJ_Rental_POS
 */
jQuery( document ).ready( function( $ ) {
	// Set max date to today for date of birth fields
	var today = new Date();
	var maxDate = today.toISOString().split( 'T' )[0];

	// Registration page
	$( '#reg_date_of_birth' ).attr( 'max', maxDate );

	// Account edit page - date of birth fields
	$( '#account_date_of_birth, #account_second_driver_date_of_birth' ).attr( 'max', maxDate );

	// Account edit page - license date fields
	$( '#account_driver_license_issue_date, #account_driver_license_obtained_date, #account_second_driver_license_issue_date, #account_second_driver_license_obtained_date' ).attr( 'max', maxDate );

	// Checkout page - driver date of birth
	$( '#billing_driver_date_of_birth' ).attr( 'max', maxDate );

	// Checkout page - second driver date of birth
	$( '#billing_second_driver_date_of_birth' ).attr( 'max', maxDate );

	// Prevent future date selection for date of birth fields
	$( '#reg_date_of_birth, #account_date_of_birth, #account_second_driver_date_of_birth, #billing_driver_date_of_birth, #billing_second_driver_date_of_birth' ).on( 'change', function() {
		var selectedDate = new Date( $( this ).val() );
		var now = new Date();
		now.setHours( 0, 0, 0, 0 );

		if ( selectedDate > now ) {
			alert( vanjornDateRestrictions.dobFutureError );
			$( this ).val( '' );
		}
	} );

	// Prevent future date selection for license date fields
	$( '#account_driver_license_issue_date, #account_driver_license_obtained_date, #account_second_driver_license_issue_date, #account_second_driver_license_obtained_date' ).on( 'change', function() {
		var selectedDate = new Date( $( this ).val() );
		var now = new Date();
		now.setHours( 0, 0, 0, 0 );

		if ( selectedDate > now ) {
			alert( vanjornDateRestrictions.licenseFutureError );
			$( this ).val( '' );
		}
	} );

	// Move password change section to the end on account edit page
	if ( $( '.woocommerce-EditAccountForm' ).length ) {
		var passwordFieldset = $( '.woocommerce-EditAccountForm fieldset' ).has( '#password_current' );
		if ( passwordFieldset.length ) {
			passwordFieldset.addClass( 'vanjorn-password-section' );

			var form = $( '.woocommerce-EditAccountForm' );
			var submitButton = form.find( 'p' ).has( "button[type='submit']" );
			if ( submitButton.length ) {
				passwordFieldset.insertBefore( submitButton );
			} else {
				form.append( passwordFieldset );
			}
		}
	}
} );
