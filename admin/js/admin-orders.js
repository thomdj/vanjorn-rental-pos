/**
 * Admin Orders JavaScript for VAN-Jorn Rental POS
 * Handles child order creation via AJAX
 *
 * @package VJ_Rental_POS
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Handle create security deposit order button clicks
		$(document).on('click', '.vanpos-create-security-deposit-order', function(e) {
			e.preventDefault();

			var $button = $(this);
			var orderId = $button.data('order-id');

			if (!orderId) {
				alert(vanposAdmin.i18n.error + ' ' + vanposAdmin.i18n.invalidOrderId);
				return;
			}

			// Disable button and show loading state
			var originalText = $button.text();
			$button.prop('disabled', true).text(vanposAdmin.i18n.creating);

			// Send AJAX request
			$.ajax({
				url: vanposAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vanpos_create_security_deposit_order',
					nonce: vanposAdmin.nonce,
					order_id: orderId
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						var message = response.data.message || vanposAdmin.i18n.success;
						
						// Show admin notice
						showAdminNotice(message, 'success');

						// Optionally redirect to new order or reload page
						if (response.data.order_url) {
							// Ask user if they want to view the new order
							if (confirm(message + '\n\n' + vanposAdmin.i18n.viewNewOrder)) {
								window.location.href = response.data.order_url;
							} else {
								// Reload current page to show updated child orders list
								location.reload();
							}
						} else {
							// Just reload the page
							setTimeout(function() {
								location.reload();
							}, 1500);
						}
					} else {
						// Show error message
						var errorMessage = response.data && response.data.message 
							? response.data.message 
							: vanposAdmin.i18n.error;
						
						showAdminNotice(errorMessage, 'error');
						
						// Re-enable button
						$button.prop('disabled', false).text(originalText);
					}
				},
				error: function(xhr, status, error) {
					// Show error message
					var errorMessage = vanposAdmin.i18n.error + ' ' + error;
					showAdminNotice(errorMessage, 'error');
					
					// Re-enable button
					$button.prop('disabled', false).text(originalText);
				}
			});
		});

		// Handle create child order button clicks
		$(document).on('click', '.vanpos-create-child-order', function(e) {
			e.preventDefault();

			var $button = $(this);
			var orderId = $button.data('order-id');
			var paymentType = $button.data('payment-type') || 'remaining';

			if (!orderId) {
				alert(vanposAdmin.i18n.error + ' ' + vanposAdmin.i18n.invalidOrderId);
				return;
			}

			// Disable button and show loading state
			var originalText = $button.text();
			$button.prop('disabled', true).text(vanposAdmin.i18n.creating);

			// Send AJAX request
			$.ajax({
				url: vanposAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vanpos_create_child_order',
					nonce: vanposAdmin.nonce,
					order_id: orderId,
					payment_type: paymentType
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						var message = response.data.message || vanposAdmin.i18n.success;
						
						// Show admin notice
						showAdminNotice(message, 'success');

						// Optionally redirect to new order or reload page
						if (response.data.order_url) {
							// Ask user if they want to view the new order
							if (confirm(message + '\n\n' + vanposAdmin.i18n.viewNewOrder)) {
								window.location.href = response.data.order_url;
							} else {
								// Reload current page to show updated child orders list
								location.reload();
							}
						} else {
							// Just reload the page
							setTimeout(function() {
								location.reload();
							}, 1500);
						}
					} else {
						// Show error message
						var errorMessage = response.data && response.data.message 
							? response.data.message 
							: vanposAdmin.i18n.error;
						
						showAdminNotice(errorMessage, 'error');
						
						// Re-enable button
						$button.prop('disabled', false).text(originalText);
					}
				},
				error: function(xhr, status, error) {
					// Show error message
					var errorMessage = vanposAdmin.i18n.error + ' ' + error;
					showAdminNotice(errorMessage, 'error');
					
					// Re-enable button
					$button.prop('disabled', false).text(originalText);
				}
			});
		});

		// Handle update metadata button clicks
		$(document).on('click', '.vanpos-update-metadata', function(e) {
			e.preventDefault();

			var $button = $(this);
			var orderId = $button.data('order-id');

			if (!orderId) {
				alert(vanposAdmin.i18n.invalidOrderId);
				return;
			}

			// Disable button and show loading state
			var originalText = $button.text();
			$button.prop('disabled', true).text(vanposAdmin.i18n.updatingMetadata);

			// Send AJAX request
			$.ajax({
				url: vanposAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'vanpos_update_rental_metadata',
					nonce: vanposAdmin.nonce,
					order_id: orderId
				},
				success: function(response) {
					if (response.success) {
						showAdminNotice(response.data.message || vanposAdmin.i18n.metadataSuccess, 'success');
						// Reload page after short delay
						setTimeout(function() {
							location.reload();
						}, 1500);
					} else {
						var errorMessage = response.data && response.data.message 
							? response.data.message 
							: vanposAdmin.i18n.metadataError;
						showAdminNotice(errorMessage, 'error');
						$button.prop('disabled', false).text(originalText);
					}
				},
				error: function(xhr, status, error) {
					showAdminNotice(vanposAdmin.i18n.metadataError + ' ' + error, 'error');
					$button.prop('disabled', false).text(originalText);
				}
			});
		});

		/**
		 * Show admin notice
		 *
		 * @param {string} message Message to display.
		 * @param {string} type Notice type (success, error, warning, info).
		 */
		function showAdminNotice(message, type) {
			type = type || 'info';
			
			// Remove any existing notices
			$('.vanpos-admin-notice').remove();
			
			// Create notice element
			var $notice = $('<div>')
				.addClass('notice notice-' + type + ' is-dismissible vanpos-admin-notice')
				.css({
					'margin': '10px 0',
					'padding': '10px'
				})
				.html('<p>' + message + '</p>');

			// Add dismiss button
			$notice.append(
				$('<button>')
					.attr('type', 'button')
					.addClass('notice-dismiss')
					.html('<span class="screen-reader-text">' + vanposAdmin.i18n.dismissNotice + '</span>')
					.on('click', function() {
						$notice.fadeOut(function() {
							$(this).remove();
						});
					})
			);

			// Insert notice at the top of the page or in a specific container
			var $container = $('.wrap h1').parent();
			if ($container.length) {
				$container.prepend($notice);
			} else {
				$('.wrap').prepend($notice);
			}

			// Auto-dismiss after 5 seconds for success messages
			if ('success' === type) {
				setTimeout(function() {
					$notice.fadeOut(function() {
						$(this).remove();
					});
				}, 5000);
			}
		}
	});

})(jQuery);
