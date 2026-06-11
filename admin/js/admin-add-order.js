/**
 * Admin Add Rental Order — JavaScript
 *
 * Handles: customer search, live price calculation, date-rule warnings
 * (non-blocking), and AJAX order creation.
 *
 * Depends on: jQuery, vanposAddOrder (localized by PHP).
 *
 * @package VJ_Rental_POS
 */

(function ($) {
	'use strict';

	var cfg          = vanposAddOrder;
	var priceCache   = {};     // key = "productId|pickup|return"
	var searchTimer  = null;
	var calcTimer    = null;
	var lastCalcKey  = '';     // dedup in-flight calcs
	var isAvailable  = true;   // availability status from last price calc
	var priceOverridden = false; // true once the admin edits the daily-rate field

	// Init
	$(document).ready(function () {
		populateProductDropdown();
		initOptionToggles();
		bindCustomerMode();
		bindCustomerSearch();
		bindDateChange();
		bindAccountToggle();
		bindDriverToggle();
		bindAvailabilityOverride();
		bindSubmit();
		bindPricePerDay();
	});

	function bindPricePerDay() {
		$('#vanpos-ao-price-per-day').on('input', function () {
			var raw = $.trim($(this).val());
			var v   = parseFloat(raw);
			// Empty = auto (use catalogue rate). Any valid number >= 0 is an
			// explicit override, including 0 (a free / comped rental).
			priceOverridden = (raw !== '' && !isNaN(v) && v >= 0);
			$('#vanpos-ao-price-reset').toggle(priceOverridden);
			requestPriceCalc();
		});
		$('#vanpos-ao-price-reset').on('click', function (e) {
			e.preventDefault();
			priceOverridden = false;
			$('#vanpos-ao-price-per-day').val('');
			$(this).hide();
			requestPriceCalc();
		});
	}

	// Products
	function populateProductDropdown() {
		var $sel = $('#vanpos-ao-product');
		$.each(cfg.products, function (_, p) {
			$sel.append(
				$('<option>', { value: p.id, text: p.name, 'data-price': p.price })
			);
		});
		$sel.on('change', requestPriceCalc);
	}

	// Options
	function initOptionToggles() {
		if (cfg.dogEnabled === 'yes') {
			$('#vanpos-ao-dog-wrap').show();
			$('#vanpos-ao-dog-price-label').text(cfg.currency + formatNum(cfg.dogPrice));
		}
		if (cfg.cleaningEnabled === 'yes') {
			$('#vanpos-ao-cleaning-wrap').show();
			$('#vanpos-ao-cleaning-price-label').text(cfg.currency + formatNum(cfg.cleaningPrice));
		}
		$('#vanpos-ao-dog, #vanpos-ao-cleaning').on('change', requestPriceCalc);
	}

	// Customer
	function bindCustomerMode() {
		$('input[name="vanpos_customer_mode"]').on('change', function () {
			var mode = $(this).val();
			$('#vanpos-ao-existing-customer').toggle(mode === 'existing');
			$('#vanpos-ao-guest-customer').toggle(mode === 'guest');
			updateSubmitState();
		});
	}

	function bindCustomerSearch() {
		var $input   = $('#vanpos-ao-customer-search');
		var $results = $('#vanpos-ao-customer-results');

		$input.on('input', function () {
			clearTimeout(searchTimer);
			var term = $.trim($input.val());
			if (term.length < 2) { $results.hide(); return; }
			searchTimer = setTimeout(function () { doCustomerSearch(term); }, 300);
		});

		// Click-away to close
		$(document).on('click', function (e) {
			if (!$(e.target).closest('#vanpos-ao-existing-customer').length) {
				$results.hide();
			}
		});

		// Clear selected customer
		$(document).on('click', '.vanpos-ao-clear-customer', function () {
			$('#vanpos-ao-customer-id').val('');
			$('#vanpos-ao-customer-selected').hide();
			$('#vanpos-ao-customer-search').val('').show().focus();
			updateSubmitState();
		});
	}

	function doCustomerSearch(term) {
		var $results = $('#vanpos-ao-customer-results');
		$results.html('<div class="vanpos-ao-dropdown-item vanpos-ao-dropdown-loading">' + cfg.i18n.searching + '</div>').show();

		$.post(cfg.ajaxUrl, {
			action: 'vanpos_search_customers',
			nonce:  cfg.nonce,
			term:   term
		}, function (res) {
			if (!res.success || !res.data.customers.length) {
				$results.html('<div class="vanpos-ao-dropdown-item vanpos-ao-dropdown-empty">' + cfg.i18n.noResults + '</div>');
				return;
			}
			var html = '';
			$.each(res.data.customers, function (_, c) {
				html += '<div class="vanpos-ao-dropdown-item" data-id="' + c.id + '" data-display="' + escAttr(c.display) + '" data-email="' + escAttr(c.email) + '">';
				html += '<strong>' + esc(c.display) + '</strong>';
				html += '<span class="vanpos-ao-customer-email">' + esc(c.email) + '</span>';
				html += '</div>';
			});
			$results.html(html);

			// Click handler
			$results.find('.vanpos-ao-dropdown-item').on('click', function () {
				var $item = $(this);
				$('#vanpos-ao-customer-id').val($item.data('id'));
				$('#vanpos-ao-customer-badge').html(
					'<strong>' + $item.data('display') + '</strong> &nbsp; ' + $item.data('email')
				);
				$('#vanpos-ao-customer-selected').show();
				$('#vanpos-ao-customer-search').hide();
				$results.hide();
				updateSubmitState();
			});
		});
	}

	// Dates & Price
	function bindDateChange() {
		$('#vanpos-ao-pickup-date, #vanpos-ao-return-date').on('change', function () {
			checkDateWarnings();
			requestPriceCalc();
		});
	}

	function checkDateWarnings() {
		var pickup = $('#vanpos-ao-pickup-date').val();
		var ret    = $('#vanpos-ao-return-date').val();
		var $warn  = $('#vanpos-ao-warnings').empty().hide();
		var $badge = $('#vanpos-ao-days-badge').hide();
		var warns  = [];

		if (!pickup || !ret) return;

		var pDt = new Date(pickup + 'T12:00:00');
		var rDt = new Date(ret + 'T12:00:00');

		// Rental days (Kestrel-compatible: inclusive)
		var days = Math.round(Math.abs((rDt - pDt) / 86400000)) + 1;

		if (days < 1) return;

		$badge.text((days === 1 ? cfg.i18n.daySingular : cfg.i18n.dayPlural).replace('%d', days)).show();

		// Pickup day check
		var pickupDay = pDt.getDay();
		if (cfg.pickupDays.indexOf(pickupDay) === -1) {
			warns.push(cfg.i18n.warnPickupDay);
		}

		// Return day check
		var returnDay = rDt.getDay();
		if (cfg.pickupDays.indexOf(returnDay) === -1) {
			warns.push(cfg.i18n.warnReturnDay);
		}

		// Duration check
		if (days < cfg.minRentalDays || days > cfg.maxRentalDays) {
			warns.push(
				cfg.i18n.warnDuration
					.replace('%min%', cfg.minRentalDays)
					.replace('%max%', cfg.maxRentalDays)
			);
		}

		// Advance notice check
		var now = new Date();
		now.setHours(0,0,0,0);
		var diff = Math.ceil((pDt - now) / 86400000);
		if (diff < 3 && diff >= 0) {
			warns.push(cfg.i18n.warnAdvance);
		}

		if (warns.length) {
			var html = '';
			$.each(warns, function (_, w) {
				html += '<div class="vanpos-ao-warning-item"><span class="dashicons dashicons-info"></span> ' + w + '</div>';
			});
			$warn.html(html).show();
		}
	}

	function requestPriceCalc() {
		clearTimeout(calcTimer);
		calcTimer = setTimeout(doCalcPrice, 200);
	}

	function doCalcPrice() {
		var productId  = $('#vanpos-ao-product').val();
		var pickupDate = $('#vanpos-ao-pickup-date').val();
		var returnDate = $('#vanpos-ao-return-date').val();

		if (!productId || !pickupDate || !returnDate) {
			renderSummaryEmpty();
			updateSubmitState();
			return;
		}

		var customRate = priceOverridden ? parseFloat($('#vanpos-ao-price-per-day').val()) : 0;
		var key = productId + '|' + pickupDate + '|' + returnDate + '|' +
		          ($('#vanpos-ao-dog').is(':checked') ? '1' : '0') + '|' +
		          ($('#vanpos-ao-cleaning').is(':checked') ? '1' : '0') + '|' +
		          (priceOverridden ? customRate : 'auto');

		if (priceCache[key]) {
			if (!priceOverridden && typeof priceCache[key].price_per_day !== 'undefined') {
				$('#vanpos-ao-price-per-day').val(parseFloat(priceCache[key].price_per_day).toFixed(2));
			}
			renderSummary(priceCache[key]);
			updateSubmitState();
			return;
		}

		if (key === lastCalcKey) return; // already in-flight
		lastCalcKey = key;

		renderSummaryLoading();

		var calcData = {
			action:      'vanpos_calc_rental_price',
			nonce:       cfg.nonce,
			product_id:  productId,
			pickup_date: pickupDate,
			return_date: returnDate
		};
		if (priceOverridden) {
			calcData.price_per_day = customRate;
		}

		$.post(cfg.ajaxUrl, calcData, function (res) {
			lastCalcKey = '';
			if (!res.success) {
				renderSummaryError(res.data && res.data.message ? res.data.message : cfg.i18n.error);
				return;
			}

			// Attach addon amounts (client-side, to avoid extra round-trip)
			var data = res.data;
			data.dog_fee      = ($('#vanpos-ao-dog').is(':checked') && cfg.dogEnabled === 'yes')         ? parseFloat(cfg.dogPrice)      : 0;
			data.cleaning_fee = ($('#vanpos-ao-cleaning').is(':checked') && cfg.cleaningEnabled === 'yes') ? parseFloat(cfg.cleaningPrice) : 0;
			data.grand_total  = data.total_price + data.dog_fee + data.cleaning_fee;

			// Keep the field showing the catalogue-suggested rate until the admin overrides it.
			if (!priceOverridden && typeof data.price_per_day !== 'undefined') {
				$('#vanpos-ao-price-per-day').val(parseFloat(data.price_per_day).toFixed(2));
			}

			priceCache[key] = data;
			renderSummary(data);
			updateSubmitState();
		});
	}

	// Summary Render
	function renderSummaryEmpty() {
		$('#vanpos-ao-summary-body').html(
			'<p class="vanpos-ao-summary-empty">' + cfg.i18n.selectProduct + '</p>'
		);
		resetAvailability();
		$('#vanpos-ao-create-remaining').prop('disabled', false);
	}

	function renderSummaryLoading() {
		$('#vanpos-ao-summary-body').html(
			'<p class="vanpos-ao-summary-loading"><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>' + cfg.i18n.calculating + '</p>'
		);
		resetAvailability();
	}

	function renderSummaryError(msg) {
		$('#vanpos-ao-summary-body').html(
			'<p class="vanpos-ao-summary-error">' + esc(msg) + '</p>'
		);
		resetAvailability();
	}

	function renderSummary(d) {
		var html = '<table class="vanpos-ao-summary-table">';

		html += row(cfg.i18n.summaryVan, esc(d.product_name));
		html += row(cfg.i18n.summaryRentalDays, d.days);
		html += row(cfg.i18n.summaryTotalPrice, money(d.total_price));

		if (d.dog_fee)      html += row(cfg.i18n.summaryDogFee, money(d.dog_fee));
		if (d.cleaning_fee) html += row(cfg.i18n.summaryCleaningFee, money(d.cleaning_fee));

		html += '<tr class="vanpos-ao-summary-divider"><td colspan="2"></td></tr>';

		if (d.is_short_term) {
			html += rowStrong(cfg.i18n.summaryFullPayment, money(d.grand_total));
		} else {
			html += row(cfg.i18n.summaryInitialPayment.replace('%pct%', d.deposit_pct), money(d.initial_payment));
			html += row(cfg.i18n.summaryRemaining.replace('%pct%', 100 - d.deposit_pct), money(d.remaining_payment));
			html += '<tr class="vanpos-ao-summary-divider"><td colspan="2"></td></tr>';
			html += rowStrong(cfg.i18n.summaryGrandTotal, money(d.grand_total));
		}

		if (d.security_deposit > 0) {
			html += row(cfg.i18n.summarySecurityDeposit, money(d.security_deposit));
		}

		html += '</table>';

		if (d.is_short_term) {
			html += '<p class="vanpos-ao-summary-note">' + cfg.i18n.summaryShortTermNote + '</p>';
		}

		$('#vanpos-ao-summary-body').html(html);
		renderAvailability(d);

		// Disable "Create remaining payment order" when short-term (nothing to split)
		$('#vanpos-ao-create-remaining').prop('disabled', !!d.is_short_term).prop('checked', d.is_short_term ? false : $('#vanpos-ao-create-remaining').prop('checked'));
	}

	function row(label, value) {
		return '<tr><td>' + label + '</td><td class="vanpos-ao-summary-val">' + value + '</td></tr>';
	}
	function rowStrong(label, value) {
		return '<tr class="vanpos-ao-summary-total"><td><strong>' + label + '</strong></td><td class="vanpos-ao-summary-val"><strong>' + value + '</strong></td></tr>';
	}

	// Availability Indicator
	function renderAvailability(d) {
		var $el       = $('#vanpos-ao-availability');
		var $override = $('#vanpos-ao-override-wrap');

		if (typeof d.available === 'undefined') {
			resetAvailability();
			return;
		}

		isAvailable = d.available;

		if (d.available) {
			$el.attr('class', 'vanpos-ao-availability vanpos-ao-availability--ok')
			   .html('<span class="dashicons dashicons-yes-alt"></span> ' + esc(d.avail_message || cfg.i18n.vanAvailable))
			   .show();
			$override.hide();
			$('#vanpos-ao-availability-override').prop('checked', false);
		} else {
			$el.attr('class', 'vanpos-ao-availability vanpos-ao-availability--blocked')
			   .html('<span class="dashicons dashicons-warning"></span> ' + esc(d.avail_message || cfg.i18n.vanUnavailable))
			   .show();
			$override.show();
		}
	}

	function resetAvailability() {
		$('#vanpos-ao-availability').hide();
		$('#vanpos-ao-override-wrap').hide();
		$('#vanpos-ao-availability-override').prop('checked', false);
		isAvailable = true;
	}

	// Account & Driver
	function bindAccountToggle() {
		$('#vanpos-ao-create-account').on('change', function () {
			$('#vanpos-ao-send-reset-wrap').toggle($(this).is(':checked'));
		});
	}

	function bindDriverToggle() {
		$('#vanpos-ao-driver-toggle').on('click', function () {
			var $fields = $('#vanpos-ao-driver-fields');
			var $arrow  = $(this).find('.vanpos-ao-toggle-arrow');
			$fields.slideToggle(200);
			$arrow.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
		});
	}

	function bindAvailabilityOverride() {
		$('#vanpos-ao-availability-override').on('change', function () {
			updateSubmitState();
		});
	}

	// Submit
	function bindSubmit() {
		$('#vanpos-ao-submit').on('click', function () {
			doSubmit();
		});
	}

	function updateSubmitState() {
		var valid = true;

		// Must have product + dates
		if (!$('#vanpos-ao-product').val())    valid = false;
		if (!$('#vanpos-ao-pickup-date').val()) valid = false;
		if (!$('#vanpos-ao-return-date').val()) valid = false;

		// Must have customer
		var mode = $('input[name="vanpos_customer_mode"]:checked').val();
		if (mode === 'existing' && !$('#vanpos-ao-customer-id').val()) valid = false;
		if (mode === 'guest') {
			if (!$.trim($('#vanpos-ao-first-name').val())) valid = false;
			if (!$.trim($('#vanpos-ao-last-name').val()))  valid = false;
			if (!$.trim($('#vanpos-ao-email').val()))      valid = false;
			if (!$('#vanpos-ao-dob').val())                valid = false;
		}

		// Availability gate — blocked unless overridden
		if (!isAvailable && !$('#vanpos-ao-availability-override').is(':checked')) valid = false;

		$('#vanpos-ao-submit').prop('disabled', !valid);
	}

	// Also update on text input in guest fields (including new address fields)
	$(document).on('input change', '#vanpos-ao-first-name, #vanpos-ao-last-name, #vanpos-ao-email, #vanpos-ao-phone, #vanpos-ao-address, #vanpos-ao-postcode, #vanpos-ao-city, #vanpos-ao-dob', function () {
		updateSubmitState();
	});

	function doSubmit() {
		var $btn = $('#vanpos-ao-submit');
		var originalText = $btn.text();
		$btn.prop('disabled', true).text(cfg.i18n.creating);
		hideNotice();

		var mode = $('input[name="vanpos_customer_mode"]:checked').val();

		var payload = {
			action:           'vanpos_admin_create_order',
			nonce:            cfg.nonce,
			customer_mode:    mode,
			customer_id:      mode === 'existing' ? $('#vanpos-ao-customer-id').val() : 0,
			first_name:       mode === 'guest' ? $('#vanpos-ao-first-name').val() : '',
			last_name:        mode === 'guest' ? $('#vanpos-ao-last-name').val() : '',
			email:            mode === 'guest' ? $('#vanpos-ao-email').val() : '',
			phone:            mode === 'guest' ? $('#vanpos-ao-phone').val() : '',
			// Guest address fields
			address:          mode === 'guest' ? $('#vanpos-ao-address').val() : '',
			postcode:         mode === 'guest' ? $('#vanpos-ao-postcode').val() : '',
			city:             mode === 'guest' ? $('#vanpos-ao-city').val() : '',
			country:          mode === 'guest' ? $('#vanpos-ao-country').val() : '',
			product_id:       $('#vanpos-ao-product').val(),
			pickup_date:      $('#vanpos-ao-pickup-date').val(),
			return_date:      $('#vanpos-ao-return-date').val(),
			pickup_time:      $('#vanpos-ao-pickup-time').val(),
			return_time:      $('#vanpos-ao-return-time').val(),
			include_dog:      $('#vanpos-ao-dog').is(':checked') ? 'true' : 'false',
			include_cleaning: $('#vanpos-ao-cleaning').is(':checked') ? 'true' : 'false',
			price_per_day:    priceOverridden ? $('#vanpos-ao-price-per-day').val() : '',
			order_status:     $('#vanpos-ao-status').val(),
			create_remaining: $('#vanpos-ao-create-remaining').is(':checked') ? 'true' : 'false',
			create_deposit:   $('#vanpos-ao-create-deposit').is(':checked') ? 'true' : 'false',
			admin_note:       $('#vanpos-ao-note').val(),
			// Account creation
			create_account:     mode === 'guest' && $('#vanpos-ao-create-account').is(':checked') ? 'true' : 'false',
			send_password_reset: mode === 'guest' && $('#vanpos-ao-send-reset').is(':checked') ? 'true' : 'false',
			// Availability override
			availability_override: $('#vanpos-ao-availability-override').is(':checked') ? 'true' : 'false',
			// Driver details (optional, guest mode only — existing customers use stored user meta)
			middle_name:                       mode === 'guest' ? $('#vanpos-ao-middle-name').val() : '',
			date_of_birth:                     mode === 'guest' ? $('#vanpos-ao-dob').val() : '',
			driver_license_issue_date:         mode === 'guest' ? $('#vanpos-ao-license-issue').val() : '',
			driver_license_obtained_date:      mode === 'guest' ? $('#vanpos-ao-license-obtained').val() : '',
			second_driver_name:                mode === 'guest' ? $('#vanpos-ao-sd-name').val() : '',
			second_driver_date_of_birth:       mode === 'guest' ? $('#vanpos-ao-sd-dob').val() : '',
			second_driver_license_issue_date:  mode === 'guest' ? $('#vanpos-ao-sd-license-issue').val() : '',
			second_driver_license_obtained_date: mode === 'guest' ? $('#vanpos-ao-sd-license-obtained').val() : ''
		};

		$.post(cfg.ajaxUrl, payload, function (res) {
			if (res.success) {
				showNotice(res.data.message, 'success', res.data.order_url);
				// Disable form to prevent double-submit
				$btn.text(cfg.i18n.success);
			} else {
				var msg = res.data && res.data.message ? res.data.message : cfg.i18n.error;
				showNotice(msg, 'error');
				$btn.prop('disabled', false).text(originalText);
			}
		}).fail(function () {
			showNotice(cfg.i18n.error, 'error');
			$btn.prop('disabled', false).text(originalText);
		});
	}

	// Notices
	function showNotice(msg, type, orderUrl) {
		var $el = $('#vanpos-ao-notice');
		var html = '<p>' + esc(msg) + '</p>';
		if (orderUrl) {
			html += '<p><a href="' + orderUrl + '" class="button button-primary" style="margin-top:6px;">' + cfg.i18n.viewOrder + '</a></p>';
		}
		$el.attr('class', 'vanpos-ao-notice vanpos-ao-notice--' + type).html(html).show();
		$('html, body').animate({ scrollTop: $el.offset().top - 40 }, 300);
	}

	function hideNotice() {
		$('#vanpos-ao-notice').hide();
	}

	// Helpers
	function money(n) {
		return cfg.currency + formatNum(n);
	}

	function formatNum(n) {
		return parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	function esc(str) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(str));
		return d.innerHTML;
	}

	function escAttr(str) {
		return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

})(jQuery);
