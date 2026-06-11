/**
 * Remaining Slots Shortcode — VAN-Jorn Rental POS
 * frontend/js/remaining-slots.js
 */
( function ( $ ) {
	'use strict';

	if ( typeof vanposRSData === 'undefined' ) {
		return;
	}

	// Show an error paragraph after the affected row.
	function showError( $slot, message ) {
		var $row = $slot.closest( '.vanpos-rs-row' );
		var $err = $row.next( '.vanpos-rs-error' );

		if ( ! $err.length ) {
			$err = $( '<p class="vanpos-rs-error"></p>' ).insertAfter( $row );
		}

		$err.text( message ).show();

		clearTimeout( $err.data( 'hideTimer' ) );
		$err.data( 'hideTimer', setTimeout( function () {
			$err.fadeOut( 300 );
		}, 6000 ) );
	}

	$( document ).on( 'click', '.vanpos-rs-slot', function ( e ) {
		e.preventDefault();

		var $slot = $( this );

		if ( $slot.hasClass( 'is-loading' ) ) {
			return;
		}

		$slot.addClass( 'is-loading' );

		$.ajax( {
			url:  vanposRSData.ajaxUrl,
			type: 'POST',
			data: {
				action:      'vanpos_rs_add_to_cart',
				nonce:       vanposRSData.nonce,
				product_id:  $slot.data( 'product-id' ),
				pickup_date: $slot.data( 'pickup' ),
				return_date: $slot.data( 'return' ),
				min_days:    $slot.data( 'min-days' ),
				max_days:    $slot.data( 'max-days' ),
			},
			success: function ( response ) {
				if ( response.success ) {
					window.location.href = response.data.cartUrl || vanposRSData.cartUrl;
				} else {
					$slot.removeClass( 'is-loading' );
					showError( $slot, ( response.data && response.data.message )
						? response.data.message
						: vanposRSData.strings.unavailable );
				}
			},
			error: function () {
				$slot.removeClass( 'is-loading' );
				showError( $slot, vanposRSData.strings.error );
			},
		} );
	} );

} )( jQuery );
