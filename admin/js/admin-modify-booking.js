/**
 * Admin Modify Booking — JavaScript
 *
 * Handles the "Modify Booking" meta box on the order edit page:
 * toggle form, populate product dropdown, preview price impact
 * with availability check, availability override, confirm and
 * apply via AJAX.
 *
 * Depends on: jQuery, vanposModifyBooking (localized by PHP).
 *
 * @package VJ_Rental_POS
 */

(function ($) {
	'use strict';

	var cfg         = vanposModifyBooking;
	var isAvailable  = true;     // availability status from last preview
	var priceOverridden = false; // true once the admin edits the daily-rate field
	var lockPrice    = false;    // true when "Lock total price" checkbox is ticked
	var isPaidOrder  = false;    // true when PHP rendered the price field as disabled

	$(document).ready(function () {
		isPaidOrder = $('#vanpos-cd-price-per-day').prop('disabled'); // capture PHP disabled state
		lockPrice   = $('#vanpos-cd-lock-price').is(':checked');      // init from PHP default (paid orders start checked)
		populateProductDropdown();
		bindToggle();
		bindPreview();
		bindApply();
		bindCancel();
		bindOverride();
		bindPricePerDay();
		bindLockPrice();
	});

	function bindPricePerDay() {
		$('#vanpos-cd-price-per-day').on('input', function () {
			var v         = $.trim($(this).val());
			var n         = parseFloat(v);
			var suggested = parseFloat($(this).data('suggested'));
			// Override only when a valid positive value differs (numerically) from the
			// pre-filled suggested rate. Numeric comparison avoids false positives from
			// browser number-input formatting (e.g. "140" vs "140.00").
			priceOverridden = (
				v !== '' && !isNaN(n) && n >= 0 &&
				( isNaN(suggested) || Math.abs(n - suggested) > 0.005 )
			);
		});
	}

	function bindLockPrice() {
		$('#vanpos-cd-lock-price').on('change', function () {
			lockPrice = $(this).is(':checked');
			var $priceField = $('#vanpos-cd-price-per-day');
			if ( lockPrice ) {
				$priceField.prop('disabled', true);
				priceOverridden = false;
			} else if ( ! isPaidOrder ) {
				// Only re-enable if PHP hadn't already disabled it for a paid order.
				$priceField.prop('disabled', false);
			}
		});
	}

	// Product Dropdown — options rendered in PHP; ensure correct value is selected.
	function populateProductDropdown() {
		var $select   = $('#vanpos-cd-product');
		var currentId = $('#vanpos-cd-current-product-id').val();

		if ( ! $select.length || ! currentId ) {
			return;
		}

		if ( $select.find('option').length ) {
			$select.val(String(currentId));
			return;
		}

		if ( ! cfg.products || ! cfg.products.length ) {
			return;
		}

		$.each(cfg.products, function (i, p) {
			var $opt = $('<option>')
				.val(p.id)
				.text(p.name);
			if ( String(p.id) === String(currentId) ) {
				$opt.prop('selected', true);
			}
			$select.append($opt);
		});
		$select.val(String(currentId));
	}

	// Toggle
	function bindToggle() {
		$('#vanpos-cd-toggle').on('click', function () {
			$('#vanpos-cd-form').slideDown(200);
			$(this).hide();
		});
	}

	function bindCancel() {
		$('#vanpos-cd-cancel-btn').on('click', function () {
			$('#vanpos-cd-form').slideUp(200);
			$('#vanpos-cd-toggle').show();
			$('#vanpos-cd-preview').hide().empty();
			$('#vanpos-cd-notice').hide().empty();
			$('#vanpos-cd-apply-btn').hide();
			resetAvailability();
			resetRefundWarning();
		});
	}

	// Preview
	function bindPreview() {
		$('#vanpos-cd-preview-btn').on('click', function () {
			doPreview();
		});
	}

	function doPreview() {
		var orderId    = $('#vanpos-cd-order-id').val();
		var productId  = $('#vanpos-cd-product').val();
		var pickupDate = $('#vanpos-cd-pickup-date').val();
		var pickupTime = $('#vanpos-cd-pickup-time').val();
		var returnDate = $('#vanpos-cd-return-date').val();
		var returnTime = $('#vanpos-cd-return-time').val();

		if (!pickupDate || !returnDate) {
			showNotice(cfg.i18n.selectBothDates, 'error');
			return;
		}

		// Validate date format (basic YYYY-MM-DD check)
		if ( !/^\d{4}-\d{2}-\d{2}$/.test(pickupDate) || !/^\d{4}-\d{2}-\d{2}$/.test(returnDate) ) {
			showNotice(cfg.i18n.invalidDate || 'Invalid date format.', 'error');
			return;
		}

		// Validate time format (HH:MM 24-hour)
		if (pickupTime && !/^([01]\d|2[0-3]):[0-5]\d$/.test(pickupTime)) {
			showNotice(cfg.i18n.invalidTime || 'Invalid time format.', 'error');
			return;
		}
		if (returnTime && !/^([01]\d|2[0-3]):[0-5]\d$/.test(returnTime)) {
			showNotice(cfg.i18n.invalidTime || 'Invalid time format.', 'error');
			return;
		}

		// String comparison works correctly for YYYY-MM-DD format from <input type="date">
		if (returnDate < pickupDate) {
			showNotice(cfg.i18n.returnAfterPickup, 'error');
			return;
		}

		// Same-day time validation (H:i string comparison works for 24h format)
		if (pickupDate === returnDate && pickupTime && returnTime && pickupTime >= returnTime) {
			showNotice(cfg.i18n.returnTimeAfterPickupTime || 'Return time must be after pickup time on the same day.', 'error');
			return;
		}

		var $btn = $('#vanpos-cd-preview-btn');
		var originalText = $btn.text();
		$btn.prop('disabled', true).text(cfg.i18n.previewing);
		hideNotice();
		$('#vanpos-cd-preview').hide();
		$('#vanpos-cd-apply-btn').hide();
		resetAvailability();
		resetRefundWarning();

		$.post(cfg.ajaxUrl, {
			action:      'vanpos_preview_booking_change',
			nonce:       cfg.nonce,
			order_id:    orderId,
			product_id:  productId,
			pickup_date: pickupDate,
			pickup_time: pickupTime,
			return_date: returnDate,
			return_time: returnTime,
			price_per_day: priceOverridden ? $('#vanpos-cd-price-per-day').val() : '',
			lock_price:    lockPrice ? 'true' : 'false'
		}, function (res) {
			$btn.prop('disabled', false).text(originalText);

			if (!res.success) {
				showNotice(res.data && res.data.message ? res.data.message : cfg.i18n.error, 'error');
				return;
			}

			renderPreview(res.data);
		}).fail(function () {
			$btn.prop('disabled', false).text(originalText);
			showNotice(cfg.i18n.error, 'error');
		});
	}

	function renderPreview(d) {
		if (!d.booking_changed) {
			showNotice(cfg.i18n.noChange, 'info');
			return;
		}

		var html = '<table class="vanpos-cd-preview-table">';

		// Van comparison row (only when product changed)
		if (d.product_changed) {
			html += comparisonRow(cfg.i18n.labelVan, esc(d.old_product_name), esc(d.new_product_name), true);
		}

		// Use server-formatted dates when available, fall back to formatDate()
		var oldPickupDisplay = d.old_pickup_display || formatDate(d.old_pickup);
		var newPickupDisplay = d.new_pickup_display || formatDate(d.new_pickup);
		var oldReturnDisplay = d.old_return_display || formatDate(d.old_return);
		var newReturnDisplay = d.new_return_display || formatDate(d.new_return);

		html += comparisonRow(cfg.i18n.labelPickup, oldPickupDisplay, newPickupDisplay, d.old_pickup !== d.new_pickup);
		if (d.old_pickup_time && d.new_pickup_time) {
			html += comparisonRow(cfg.i18n.labelPickupTime, esc(d.old_pickup_time), esc(d.new_pickup_time), d.old_pickup_time !== d.new_pickup_time);
		}
		html += comparisonRow(cfg.i18n.labelReturn, oldReturnDisplay, newReturnDisplay, d.old_return !== d.new_return);
		if (d.old_return_time && d.new_return_time) {
			html += comparisonRow(cfg.i18n.labelReturnTime, esc(d.old_return_time), esc(d.new_return_time), d.old_return_time !== d.new_return_time);
		}
		// Both old_days and new_days are now inclusive calendar days (same as the
		// display unit), so _display variants are identical fallbacks for back-compat.
		var oldDaysDisplay = ( d.old_days_display !== undefined ) ? d.old_days_display : d.old_days;
		var newDaysDisplay = ( d.new_days_display !== undefined ) ? d.new_days_display : d.new_days;
		html += comparisonRow(cfg.i18n.labelDays, oldDaysDisplay, newDaysDisplay, oldDaysDisplay !== newDaysDisplay);
		html += comparisonRow(cfg.i18n.labelTotalPrice, money(d.old_total), money(d.new_total), Math.abs(d.price_diff) > 0.01);

		if (d.old_remaining > 0 || d.new_remaining > 0) {
			html += comparisonRow(cfg.i18n.labelRemaining, money(d.old_remaining), money(d.new_remaining), Math.abs(d.old_remaining - d.new_remaining) > 0.01);
		}

		html += '</table>';

		// Price impact message
		if (d.price_diff > 0.01) {
			html += '<div class="vanpos-cd-impact vanpos-cd-impact--increase">';
			html += cfg.i18n.priceIncrease.replace('%s', money(d.price_diff));
			html += '</div>';
		} else if (d.price_diff < -0.01) {
			html += '<div class="vanpos-cd-impact vanpos-cd-impact--decrease">';
			html += cfg.i18n.priceDecrease.replace('%s', money(Math.abs(d.price_diff)));
			html += '</div>';
		} else {
			html += '<div class="vanpos-cd-impact vanpos-cd-impact--neutral">';
			html += cfg.i18n.priceUnchanged;
			html += '</div>';
		}

		// Child orders note
		if (d.child_count > 0) {
			html += '<div class="vanpos-cd-child-note">';
			html += '<span class="dashicons dashicons-update"></span> ';
			html += cfg.i18n.childNote;
			html += '</div>';
		}

		$('#vanpos-cd-preview').html(html).slideDown(200);

		// Render availability indicator
		renderAvailability(d);

		// Render refund warning (price decrease only)
		renderRefundWarning(d);

		// Show Apply button (may be gated by availability)
		updateApplyState();
	}

	function comparisonRow(label, oldVal, newVal, changed) {
		var cls = changed ? ' vanpos-cd-changed' : '';
		return '<tr class="' + cls + '">' +
			'<td class="vanpos-cd-row-label">' + esc(label) + '</td>' +
			'<td class="vanpos-cd-row-old">' + esc(oldVal) + '</td>' +
			'<td class="vanpos-cd-row-arrow">&rarr;</td>' +
			'<td class="vanpos-cd-row-new">' + esc(newVal) + '</td>' +
			'</tr>';
	}

	// Availability Indicator
	function renderAvailability(d) {
		var $el       = $('#vanpos-cd-availability');
		var $override = $('#vanpos-cd-override-wrap');

		if (typeof d.available === 'undefined') {
			resetAvailability();
			return;
		}

		isAvailable = d.available;

		if (d.available) {
			$el.attr('class', 'vanpos-cd-availability vanpos-cd-availability--ok')
			   .html('<span class="dashicons dashicons-yes-alt"></span> ' + esc(d.avail_message || cfg.i18n.vanAvailable))
			   .show();
			$override.hide();
			$('#vanpos-cd-availability-override').prop('checked', false);
		} else {
			$el.attr('class', 'vanpos-cd-availability vanpos-cd-availability--blocked')
			   .html('<span class="dashicons dashicons-warning"></span> ' + esc(d.avail_message || cfg.i18n.vanUnavailable))
			   .show();
			$override.show();
		}
	}

	function resetAvailability() {
		$('#vanpos-cd-availability').hide().empty();
		$('#vanpos-cd-override-wrap').hide();
		$('#vanpos-cd-availability-override').prop('checked', false);
		isAvailable = true;
	}

	function renderRefundWarning(d) {
		var $el = $('#vanpos-cd-refund-warning');
		if (d.refund_warning) {
			$el.attr('class', 'vanpos-cd-availability vanpos-cd-availability--blocked')
			   .html('<span class="dashicons dashicons-warning"></span> ' + esc(cfg.i18n.shorteningRefundNote))
			   .show();
		} else {
			resetRefundWarning();
		}
	}

	function resetRefundWarning() {
		$('#vanpos-cd-refund-warning').hide().empty()
			.attr('class', 'vanpos-cd-availability');
	}

	function bindOverride() {
		$('#vanpos-cd-availability-override').on('change', function () {
			updateApplyState();
		});
	}

	function updateApplyState() {
		var canApply = isAvailable || $('#vanpos-cd-availability-override').is(':checked');
		$('#vanpos-cd-apply-btn').toggle(canApply).prop('disabled', !canApply);
	}

	// Apply
	function bindApply() {
		$('#vanpos-cd-apply-btn').on('click', function () {
			doApply();
		});
	}

	function doApply() {
		var $btn = $('#vanpos-cd-apply-btn');
		var originalText = $btn.text();
		$btn.prop('disabled', true).text(cfg.i18n.applying);
		$('#vanpos-cd-preview-btn').prop('disabled', true);
		hideNotice();

		$.post(cfg.ajaxUrl, {
			action:                 'vanpos_modify_booking',
			nonce:                  cfg.nonce,
			order_id:               $('#vanpos-cd-order-id').val(),
			product_id:             $('#vanpos-cd-product').val(),
			pickup_date:            $('#vanpos-cd-pickup-date').val(),
			pickup_time:            $('#vanpos-cd-pickup-time').val(),
			return_date:            $('#vanpos-cd-return-date').val(),
			return_time:            $('#vanpos-cd-return-time').val(),
			availability_override:  $('#vanpos-cd-availability-override').is(':checked') ? 'true' : 'false',
			price_per_day:          priceOverridden ? $('#vanpos-cd-price-per-day').val() : '',
			lock_price:             lockPrice ? 'true' : 'false'
		}, function (res) {
			if (res.success) {
				showNotice(res.data.message, 'success');
				// Reload page after short delay so all meta boxes refresh
				setTimeout(function () {
					location.reload();
				}, 1500);
			} else {
				var msg = res.data && res.data.message ? res.data.message : cfg.i18n.error;
				showNotice(msg, 'error');
				$btn.prop('disabled', false).text(originalText);
				$('#vanpos-cd-preview-btn').prop('disabled', false);
			}
		}).fail(function () {
			showNotice(cfg.i18n.error, 'error');
			$btn.prop('disabled', false).text(originalText);
			$('#vanpos-cd-preview-btn').prop('disabled', false);
		});
	}

	// Notices
	function showNotice(msg, type) {
		$('#vanpos-cd-notice')
			.attr('class', 'vanpos-cd-notice vanpos-cd-notice--' + type)
			.html('<p>' + esc(msg) + '</p>')
			.show();
	}

	function hideNotice() {
		$('#vanpos-cd-notice').hide().empty();
	}

	// Helpers
	function money(n) {
		return cfg.currency + parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	/**
	 * Fallback date formatter for when server-formatted dates are unavailable.
	 * Uses DD/MM/YYYY as a sensible default. Prefer server-formatted dates
	 * (d.*_display fields) which respect the WP date_format setting.
	 */
	function formatDate(dateStr) {
		if (!dateStr) return '—';
		var parts = dateStr.split('-');
		if (parts.length !== 3) return dateStr;
		return parts[2] + '/' + parts[1] + '/' + parts[0];
	}

	function esc(str) {
		if (str === null || typeof str === 'undefined') return '';
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(String(str)));
		return d.innerHTML;
	}

})(jQuery);
