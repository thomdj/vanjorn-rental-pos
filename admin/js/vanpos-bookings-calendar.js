(function () {
	'use strict';

	var cfg = window.vanposBookingsCalendar;
	if (!cfg || typeof FullCalendar === 'undefined') {
		return;
	}

	var calendarRef = null;
	var highlightOrderId = null;
	var pendingOpenOrderId = null;

	function esc(s) {
		if (s == null) {
			return '';
		}
		var d = document.createElement('div');
		d.textContent = String(s);
		return d.innerHTML;
	}

	function getSearchValue() {
		var el = document.getElementById('vanpos-bc-search');
		return el && el.value ? String(el.value).trim() : '';
	}

	/** FullCalendar endStr is exclusive; last visible calendar day is one day before. */
	function inclusiveEndFromExclusiveEnd(iso) {
		var parts = iso.split('T')[0].split('-');
		var y = Number(parts[0]);
		var m = Number(parts[1]) - 1;
		var day = Number(parts[2]);
		var d = new Date(y, m, day);
		d.setDate(d.getDate() - 1);
		var mm = String(d.getMonth() + 1).padStart(2, '0');
		var dd = String(d.getDate()).padStart(2, '0');
		return d.getFullYear() + '-' + mm + '-' + dd;
	}

	function canServeFromBootstrap(info) {
		var bsStart = cfg.bootstrapStart;
		var bsEnd = cfg.bootstrapEnd;
		if (!cfg.initialEvents || !bsStart || !bsEnd) {
			return false;
		}
		var fetchStart = info.startStr.split('T')[0];
		var fetchEndIncl = inclusiveEndFromExclusiveEnd(info.endStr);
		return bsStart <= fetchStart && bsEnd >= fetchEndIncl;
	}

	function filterByProduct(events, productId) {
		if (!productId) {
			return events || [];
		}
		return (events || []).filter(function (ev) {
			var p = ev.extendedProps || {};
			var orig = p.originalProductId;
			var pid = p.productId;
			return String(productId) === String(orig) || String(productId) === String(pid);
		});
	}

	function filterBySearch(events, q) {
		if (!q) {
			return events || [];
		}
		var needle = q.toLowerCase();
		return (events || []).filter(function (ev) {
			var p = ev.extendedProps || {};
			var bits = [
				String(ev.id || ''),
				p.orderNumber ? String(p.orderNumber) : '',
				ev.title || '',
				p.camper || '',
				p.customer || '',
				p.phone || '',
				p.orderStatusLabel || '',
			];
			return bits.some(function (b) {
				return b && b.toLowerCase().indexOf(needle) !== -1;
			});
		});
	}

	function applyBootstrapFilters(events) {
		var vanSel = document.getElementById('vanpos-bc-van-filter');
		var pid = vanSel && vanSel.value ? vanSel.value : '';
		return filterBySearch(filterByProduct(events, pid), getSearchValue());
	}

	function fetchEventsAjax(info, successCallback, failureCallback) {
		var vanSel = document.getElementById('vanpos-bc-van-filter');
		var pid = vanSel && vanSel.value ? vanSel.value : '0';
		var fd = new FormData();
		fd.append('action', cfg.ajaxAction);
		fd.append('nonce', cfg.ajaxNonce);
		fd.append('start', info.startStr);
		fd.append('end', info.endStr);
		fd.append('product_id', pid);
		fd.append('search', getSearchValue());

		window
			.fetch(cfg.ajaxUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin',
			})
			.then(function (r) {
				return r.json();
			})
			.then(function (body) {
				if (!body || !body.success) {
					var msg =
						body && body.data && body.data.message
							? body.data.message
							: 'Request failed';
					throw new Error(msg);
				}
				successCallback(body.data);
			})
			.catch(failureCallback);
	}

	function fetchEvents(info, successCallback, failureCallback) {
		if (canServeFromBootstrap(info)) {
			successCallback(applyBootstrapFilters(cfg.initialEvents));
			return;
		}
		fetchEventsAjax(info, successCallback, failureCallback);
	}

	function buildVanFilter(selectEl) {
		var i18n = cfg.i18n || {};
		selectEl.innerHTML = '';
		var opt0 = document.createElement('option');
		opt0.value = '';
		opt0.textContent = i18n.allVans || 'All vans';
		selectEl.appendChild(opt0);
		(cfg.vans || []).forEach(function (v) {
			if (!v.id) {
				return;
			}
			var o = document.createElement('option');
			o.value = String(v.id);
			o.textContent = v.name || ('#' + v.id);
			selectEl.appendChild(o);
		});
	}

	function dateToYmd(d) {
		var y = d.getFullYear();
		var m = String(d.getMonth() + 1).padStart(2, '0');
		var day = String(d.getDate()).padStart(2, '0');
		return y + '-' + m + '-' + day;
	}

	function viewInclusiveRange(cal) {
		var s = dateToYmd(cal.view.activeStart);
		var endEx = cal.view.activeEnd;
		var last = new Date(endEx.getTime() - 86400000);
		var e = dateToYmd(last);
		return { start: s, end: e };
	}

	function ymdInRange(ymd, start, end) {
		return ymd && ymd >= start && ymd <= end;
	}

	function parseLocalYmd(ymd) {
		var p = String(ymd || '').split('-');
		if (p.length !== 3) {
			return new Date();
		}
		return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
	}

	function getDateLocale() {
		return cfg.dateLocale || undefined;
	}

	function formatYmdForList(ymd) {
		return parseLocalYmd(ymd).toLocaleDateString(getDateLocale(), {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
		});
	}

	function formatRangeLabel(startYmd, endYmd) {
		var a = parseLocalYmd(startYmd);
		var b = parseLocalYmd(endYmd);
		if (startYmd === endYmd) {
			return a.toLocaleDateString(getDateLocale(), {
				weekday: 'long',
				month: 'long',
				day: 'numeric',
				year: 'numeric',
			});
		}
		var o = { month: 'short', day: 'numeric', year: 'numeric' };
		return a.toLocaleDateString(getDateLocale(), o) + ' – ' + b.toLocaleDateString(getDateLocale(), o);
	}

	/**
	 * One booking line: van (product) · #main order number; optional date prefix when the visible range spans multiple days.
	 */
	function buildBookingLineHtml(p, e, dateYmd) {
		var van = (p.camper && String(p.camper).trim()) || String(e.title || '').trim();
		if (!van) {
			van = '—';
		}
		var rawNum = p.orderNumber ? String(p.orderNumber).replace(/^#/, '') : '';
		var orderUrl = '';
		if (e && e.url) {
			orderUrl = String(e.url);
		} else if (p.orderEditUrl) {
			orderUrl = String(p.orderEditUrl);
		}
		var prefix = '';
		if (dateYmd) {
			prefix =
				'<time class="vanpos-bc-sum-time" datetime="' +
				esc(dateYmd) +
				'">' +
				esc(formatYmdForList(dateYmd)) +
				'</time> ';
		}
		var orderEl = '';
		if (rawNum) {
			orderEl =
				'<span class="vanpos-bc-sum-sep" aria-hidden="true">·</span> ';
			if (orderUrl) {
				orderEl +=
					'<a class="vanpos-bc-sum-ord" href="' +
					esc(orderUrl) +
					'">#' +
					esc(rawNum) +
					'</a>';
			} else {
				orderEl += '<span class="vanpos-bc-sum-ord">#' + esc(rawNum) + '</span>';
			}
		}
		return prefix + '<span class="vanpos-bc-sum-van">' + esc(van) + '</span> ' + orderEl;
	}

	function sortSummaryRows(rows) {
		rows.sort(function (x, y) {
			if (x.sort < y.sort) {
				return -1;
			}
			if (x.sort > y.sort) {
				return 1;
			}
			return 0;
		});
		return rows;
	}

	function renderSummaryUl(rows, noneText) {
		if (!rows.length) {
			return '<p class="vanpos-bc-sum-empty">' + esc(noneText) + '</p>';
		}
		var lis = rows
			.map(function (r) {
				return '<li class="vanpos-bc-sum-li">' + r.html + '</li>';
			})
			.join('');
		return '<ul class="vanpos-bc-sum-list">' + lis + '</ul>';
	}

	function updateTodaySummary(calendar) {
		var i18n = cfg.i18n || {};
		var today = new Date();
		var todayStr = dateToYmd(today);
		var vr = viewInclusiveRange(calendar);
		var multiDayView = vr.start !== vr.end;

		var rowsPickupsToday = [];
		var rowsReturnsToday = [];
		var rowsPickupsView = [];
		var rowsReturnsView = [];

		calendar.getEvents().forEach(function (e) {
			var p = e.extendedProps || {};
			var pd = p.pickupDate || (e.startStr && e.startStr.split('T')[0]);
			var rd = p.returnDate;
			var vanKey = (p.camper || e.title || '').toLowerCase();

			if (pd === todayStr) {
				rowsPickupsToday.push({
					sort: vanKey,
					html: buildBookingLineHtml(p, e, null),
				});
			}
			if (rd === todayStr) {
				rowsReturnsToday.push({
					sort: vanKey,
					html: buildBookingLineHtml(p, e, null),
				});
			}

			if (pd && ymdInRange(pd, vr.start, vr.end)) {
				rowsPickupsView.push({
					sort: pd + '\0' + vanKey,
					html: buildBookingLineHtml(p, e, multiDayView ? pd : null),
				});
			}
			if (rd && ymdInRange(rd, vr.start, vr.end)) {
				rowsReturnsView.push({
					sort: rd + '\0' + vanKey,
					html: buildBookingLineHtml(p, e, multiDayView ? rd : null),
				});
			}
		});

		sortSummaryRows(rowsPickupsToday);
		sortSummaryRows(rowsReturnsToday);
		sortSummaryRows(rowsPickupsView);
		sortSummaryRows(rowsReturnsView);

		var viewPickupCount = rowsPickupsView.length;
		var viewReturnCount = rowsReturnsView.length;
		var none = i18n.none || 'None';

		var todayLong = today.toLocaleDateString(getDateLocale(), {
			weekday: 'long',
			month: 'long',
			day: 'numeric',
			year: 'numeric',
		});
		var periodTitle = formatRangeLabel(vr.start, vr.end);
		var todayInView = ymdInRange(todayStr, vr.start, vr.end);

		var noteHtml = '';
		if (!todayInView && i18n.todayNotInView) {
			noteHtml =
				'<div class="vanpos-bc-sum-alert" role="status">' + esc(i18n.todayNotInView) + '</div>';
		}

		var el = document.getElementById('vanpos-bc-today-summary');
		if (!el) {
			return;
		}

		el.innerHTML =
			'<div class="vanpos-bc-sum-grid">' +
			noteHtml +
			'<section class="vanpos-bc-sum-block" aria-labelledby="vanpos-bc-sum-today-h">' +
			'<div class="vanpos-bc-sum-block-head" id="vanpos-bc-sum-today-h">' +
			'<span class="vanpos-bc-sum-block-title">' +
			esc(i18n.today || 'Today') +
			'</span>' +
			'<span class="vanpos-bc-sum-block-sub">' +
			esc(todayLong) +
			'</span>' +
			'</div>' +
			'<div class="vanpos-bc-sum-cols">' +
			'<div class="vanpos-bc-sum-col">' +
			'<div class="vanpos-bc-sum-col-label vanpos-bc-sum-pickups">' +
			esc(i18n.pickups || 'Pickups') +
			' <span class="vanpos-bc-sum-badge">' +
			String(rowsPickupsToday.length) +
			'</span></div>' +
			renderSummaryUl(rowsPickupsToday, none) +
			'</div>' +
			'<div class="vanpos-bc-sum-col">' +
			'<div class="vanpos-bc-sum-col-label vanpos-bc-sum-returns">' +
			esc(i18n.returns || 'Returns') +
			' <span class="vanpos-bc-sum-badge">' +
			String(rowsReturnsToday.length) +
			'</span></div>' +
			renderSummaryUl(rowsReturnsToday, none) +
			'</div>' +
			'</div>' +
			'</section>' +
			'<section class="vanpos-bc-sum-block vanpos-bc-sum-view-block" aria-labelledby="vanpos-bc-sum-view-h">' +
			'<div class="vanpos-bc-sum-block-head" id="vanpos-bc-sum-view-h">' +
			'<span class="vanpos-bc-sum-block-title">' +
			esc(i18n.visiblePeriod || i18n.thisViewLabel || 'Visible period') +
			'</span>' +
			'<span class="vanpos-bc-sum-block-sub">' +
			esc(periodTitle) +
			'</span>' +
			'<span class="vanpos-bc-sum-inline-count" aria-hidden="true">' +
			'<span class="vanpos-bc-sum-pickups">' +
			esc(i18n.pickups || 'Pickups') +
			': ' +
			String(viewPickupCount) +
			'</span> · ' +
			'<span class="vanpos-bc-sum-returns">' +
			esc(i18n.returns || 'Returns') +
			': ' +
			String(viewReturnCount) +
			'</span>' +
			'</span>' +
			'</div>' +
			'<div class="vanpos-bc-sum-cols">' +
			'<div class="vanpos-bc-sum-col">' +
			'<div class="vanpos-bc-sum-col-label vanpos-bc-sum-pickups">' +
			esc(i18n.pickups || 'Pickups') +
			' <span class="vanpos-bc-sum-badge">' +
			String(viewPickupCount) +
			'</span></div>' +
			renderSummaryUl(rowsPickupsView, none) +
			'</div>' +
			'<div class="vanpos-bc-sum-col">' +
			'<div class="vanpos-bc-sum-col-label vanpos-bc-sum-returns">' +
			esc(i18n.returns || 'Returns') +
			' <span class="vanpos-bc-sum-badge">' +
			String(viewReturnCount) +
			'</span></div>' +
			renderSummaryUl(rowsReturnsView, none) +
			'</div>' +
			'</div>' +
			'</section>' +
			'</div>';
	}

	function openBookingPanel(calEvent) {
		var p = calEvent.extendedProps || {};
		var i18n = cfg.i18n || {};
		var panel = document.getElementById('vanpos-bc-panel');
		var backdrop = document.getElementById('vanpos-bc-panel-backdrop');
		var titleEl = document.getElementById('vanpos-bc-panel-title');
		var bodyEl = panel ? panel.querySelector('.vanpos-bc-panel-body') : null;
		var actions = panel ? panel.querySelector('.vanpos-bc-panel-actions') : null;
		if (!panel || !backdrop || !titleEl || !bodyEl || !actions) {
			return;
		}

		var titleBits = [];
		if (p.camper) {
			titleBits.push(p.camper);
		}
		if (p.orderNumber) {
			titleBits.push((i18n.order || 'Order') + ' #' + p.orderNumber);
		}
		titleEl.textContent = titleBits.length ? titleBits.join(' · ') : calEvent.title || '';

		var days = typeof p.rentalDays === 'number' ? p.rentalDays : '';

		function kv(label, value) {
			if (value == null || value === '') {
				return '';
			}
			return (
				'<div class="vanpos-bc-kv">' +
				'<span class="vanpos-bc-k">' +
				esc(label) +
				'</span>' +
				'<span class="vanpos-bc-v">' +
				esc(value) +
				'</span>' +
				'</div>'
			);
		}

		function section(title, inner) {
			if (!inner) {
				return '';
			}
			return (
				'<section class="vanpos-bc-section">' +
				'<h3 class="vanpos-bc-section-title">' +
				esc(title) +
				'</h3>' +
				'<div class="vanpos-bc-section-inner">' +
				inner +
				'</div>' +
				'</section>'
			);
		}

		var phaseKey = p.status ? String(p.status) : 'pending';
		var phaseWord = i18n[phaseKey] ? i18n[phaseKey] : phaseKey;
		var badge =
			'<span class="vanpos-bc-phase-badge vanpos-bc-phase-' +
			esc(phaseKey) +
			'">' +
			esc(phaseWord) +
			'</span>';

		var statusInner =
			'<div class="vanpos-bc-timeline-block">' +
			'<div class="vanpos-bc-sub-label">' +
			esc(i18n.rentalTimeline || 'Rental timeline') +
			'</div>' +
			'<div class="vanpos-bc-timeline-badge-row">' +
			badge +
			'</div>' +
			'<p class="vanpos-bc-phase-desc vanpos-bc-phase-desc-block">' +
			esc(p.bookingPhaseLabel || '') +
			'</p>' +
			'</div>' +
			(p.orderStatusLabel
				? kv(i18n.wcOrderStatus || 'WooCommerce status', p.orderStatusLabel)
				: '');

		var bookingInner = [
			kv(i18n.order || 'Order', '#' + String(p.orderNumber || calEvent.id)),
			p.customer ? kv(i18n.customer || 'Customer', p.customer) : '',
			p.rentalReference ? kv(i18n.bookingRef || 'Booking reference', p.rentalReference) : '',
			p.pickupDate ? kv(i18n.pickup || 'Pickup', p.pickupDate) : '',
			p.returnDate ? kv(i18n.return || 'Return', p.returnDate) : '',
			days !== '' ? kv(i18n.days || 'Rental days', String(days)) : '',
			p.type ? kv(i18n.orderType || 'Order type', p.type) : '',
			p.shortTermNote
				? kv(i18n.shortTermBooking || 'Payment rules', p.shortTermNote)
				: '',
		].join('');

		function finRow(label, detail) {
			if (detail == null || detail === '') {
				return '';
			}
			return (
				'<tr>' +
				'<th scope="row">' +
				esc(label) +
				'</th>' +
				'<td>' +
				esc(detail) +
				'</td>' +
				'</tr>'
			);
		}

		function finDivider() {
			return (
				'<tr class="vanpos-bc-fin-divider" aria-hidden="true">' +
				'<td colspan="2"></td>' +
				'</tr>'
			);
		}

		var mainOrderRef = '#' + String(p.orderNumber || calEvent.id || '');
		var mainOrderLabel = (i18n.mainOrder || 'Main order') + ' ' + mainOrderRef;
		var mainOrderDetail = [p.orderStatusLabel || '', p.payment || ''].filter(Boolean).join(' — ');
		var productDetail = p.camper || '';
		if (p.camper && p.contractTotal) {
			productDetail =
				String(p.camper) +
				' — ' +
				(i18n.rentalPriceLabel || 'Rental price') +
				': ' +
				String(p.contractTotal);
		}

		var breakdownRows = [
			productDetail ? finRow(i18n.productTitle || 'Product title', productDetail) : '',
			p.cleaningService ? finRow(i18n.cleaningService || 'Cleaning service', p.cleaningService) : '',
			p.bringYourDog ? finRow(i18n.bringYourDog || 'Bring your dog', p.bringYourDog) : '',
			p.breakdownTotal
				? finRow(i18n.totalLabel || 'Total', p.breakdownTotal)
				: '',
		].join('');

		var remainingLabel = p.remainingOrderLabel || '';
		var remainingDetail = p.remainingOrderDetail || '';
		if (!remainingDetail && p.remainingOrderInfo) {
			remainingDetail = p.remainingOrderInfo;
			remainingLabel = remainingLabel || i18n.remainingPaymentOrder || 'Remaining payment';
		}

		var securityLabel = p.securityDepositLabel || '';
		var securityDetail = p.securityDepositDetail || '';
		if (!securityDetail && p.securityDepositInfo) {
			securityDetail = p.securityDepositInfo;
			securityLabel = securityLabel || i18n.securityDepositOrder || 'Security deposit';
		}

		var orderRows = [
			mainOrderDetail ? finRow(mainOrderLabel, mainOrderDetail) : '',
			securityLabel && securityDetail ? finRow(securityLabel, securityDetail) : '',
			remainingLabel && remainingDetail ? finRow(remainingLabel, remainingDetail) : '',
		].join('');

		var hasBreakdown = !!(p.camper || p.cleaningService || p.bringYourDog || p.breakdownTotal);
		var hasOrderRows = !!(
			mainOrderDetail ||
			(securityLabel && securityDetail) ||
			(remainingLabel && remainingDetail)
		);
		var finRows = '';
		if (hasBreakdown) {
			finRows += breakdownRows;
		}
		if (hasBreakdown && hasOrderRows) {
			finRows += finDivider();
		}
		finRows += orderRows;

		var financialInner = '';
		var hasFinancial = !!(hasBreakdown || mainOrderDetail || securityDetail || remainingDetail);
		if (hasFinancial) {
			financialInner =
				'<table class="vanpos-bc-fin-table">' +
				'<caption class="screen-reader-text">' +
				esc(i18n.sectionFinancials || 'Payments & balances') +
				'</caption>' +
				'<thead><tr>' +
				'<th scope="col">' +
				esc(i18n.finColItem || 'Item') +
				'</th>' +
				'<th scope="col">' +
				esc(i18n.finColDetail || 'Detail') +
				'</th>' +
				'</tr></thead>' +
				'<tbody>' +
				finRows +
				'</tbody></table>';
		}

		var contactInner = p.phone ? kv(i18n.phoneLabel || 'Phone', p.phone) : '';

		bodyEl.innerHTML = [
			section(i18n.sectionStatus || 'Status', statusInner),
			section(i18n.sectionBooking || 'Booking', bookingInner),
			section(i18n.sectionFinancials || 'Payments & balances', financialInner),
			section(i18n.sectionContact || 'Contact', contactInner),
		].join('');

		var phone = p.phone || '';
		var tel = phone.replace(/\s+/g, '');
		var url = calEvent.url || '';
		var btns = '';
		if (phone) {
			btns +=
				'<a class="button vanpos-bc-tip-call" href="tel:' +
				esc(tel) +
				'">' +
				esc(i18n.call || 'Call') +
				'</a> ';
		}
		if (url) {
			btns +=
				'<a class="button button-primary" href="' +
				esc(url) +
				'" target="_blank" rel="noopener noreferrer">' +
				esc(i18n.edit || 'Edit order') +
				'</a>';
		}
		actions.innerHTML = btns;

		backdrop.hidden = false;
		panel.hidden = false;
		document.body.classList.add('vanpos-bc-panel-open');
	}

	function closeBookingPanel() {
		var panel = document.getElementById('vanpos-bc-panel');
		var backdrop = document.getElementById('vanpos-bc-panel-backdrop');
		if (panel) {
			panel.hidden = true;
		}
		if (backdrop) {
			backdrop.hidden = true;
		}
		document.body.classList.remove('vanpos-bc-panel-open');
		if (highlightOrderId && calendarRef) {
			highlightOrderId = null;
			calendarRef.render();
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var calEl = document.getElementById('vanpos-bc-calendar');
		var vanSel = document.getElementById('vanpos-bc-van-filter');
		var searchEl = document.getElementById('vanpos-bc-search');
		var panel = document.getElementById('vanpos-bc-panel');
		var backdrop = document.getElementById('vanpos-bc-panel-backdrop');
		if (!calEl || !vanSel) {
			return;
		}

		buildVanFilter(vanSel);

		var searchTimer = null;
		var acCloseList = function () {};
		function scheduleRefetch(calendar) {
			if (searchTimer) {
				window.clearTimeout(searchTimer);
			}
			searchTimer = window.setTimeout(function () {
				calendar.refetchEvents();
			}, 280);
		}

		var calLoader = document.getElementById('vanpos-bc-cal-loader');
		var calOuter = calEl ? calEl.closest('.vanpos-bc-calendar-outer') : null;

		var i18n = cfg.i18n || {};
		var fcLocale = cfg.fcLocale || 'en-gb';
		// Only override FC toolbar text when WPML/.mo actually translated the string.
		// Passing English "Today" would replace locale bundle labels (e.g. nl "Vandaag").
		var calOpts = {
			initialView: 'dayGridMonth',
			locale: fcLocale,
			firstDay: 1,
			headerToolbar: {
				left: 'prev,next today',
				center: 'title',
				right: '',
			},
			// Show every booking in the cell (no "+ N more" — avoids misleading availability).
			dayMaxEvents: false,
			events: fetchEvents,
			loading: function (isLoading) {
				if (calLoader) {
					calLoader.classList.toggle('is-visible', !!isLoading);
					calLoader.setAttribute('aria-busy', isLoading ? 'true' : 'false');
					calLoader.setAttribute('aria-hidden', isLoading ? 'false' : 'true');
				}
				if (calOuter) {
					calOuter.classList.toggle('vanpos-bc-is-loading', !!isLoading);
				}
			},
			eventClassNames: function (arg) {
				if (highlightOrderId && String(arg.event.id) === String(highlightOrderId)) {
					return ['vanpos-bc-suggest-hit'];
				}
				return [];
			},
			eventContent: function (arg) {
				var p = arg.event.extendedProps || {};
				var camper = p.camper ? String(p.camper).trim() : '';
				var orderLine = esc(arg.event.title || '');
				if (!camper) {
					return {
						html: '<span class="vanpos-bc-ev-inner">' + orderLine + '</span>',
					};
				}
				return {
					html:
						'<span class="vanpos-bc-ev-stack">' +
						'<span class="vanpos-bc-ev-product">' +
						esc(camper) +
						'</span>' +
						'<span class="vanpos-bc-ev-order">' +
						orderLine +
						'</span>' +
						'</span>',
				};
			},
			eventDidMount: function (info) {
				var p = info.event.extendedProps || {};
				var st = p.status || 'pending';
				info.el.classList.add('vanpos-bc-status-' + st);
				var tipParts = [];
				if (p.camper && String(p.camper).trim()) {
					tipParts.push(String(p.camper).trim());
				}
				if (info.event.title) {
					tipParts.push(info.event.title);
				}
				if (tipParts.length) {
					info.el.setAttribute('title', tipParts.join(' — '));
				}
			},
			datesSet: function () {
				window.setTimeout(function () {
					if (calendar) {
						updateTodaySummary(calendar);
					}
				}, 0);
			},
			eventClick: function (info) {
				info.jsEvent.preventDefault();
				if (highlightOrderId && calendar) {
					highlightOrderId = null;
					calendar.render();
				}
				openBookingPanel(info.event);
			},
			eventsSet: function () {
				window.setTimeout(function () {
					if (!calendar) {
						return;
					}
					updateTodaySummary(calendar);
					if (!pendingOpenOrderId) {
						return;
					}
					var pid = pendingOpenOrderId;
					var tryOpen = function () {
						var ev = calendar.getEventById(String(pid));
						if (ev) {
							openBookingPanel(ev);
							return true;
						}
						return false;
					};
					if (tryOpen()) {
						pendingOpenOrderId = null;
					} else {
						window.setTimeout(function () {
							if (pendingOpenOrderId !== pid) {
								return;
							}
							if (tryOpen()) {
								pendingOpenOrderId = null;
							} else {
								pendingOpenOrderId = null;
							}
						}, 380);
					}
				}, 0);
			},
		};
		if (i18n.today && i18n.today !== 'Today') {
			calOpts.buttonText = { today: i18n.today };
		}

		var calendar = new FullCalendar.Calendar(calEl, calOpts);

		calendar.render();
		calendarRef = calendar;

		if (searchEl) {
			var acRoot = searchEl.closest('.vanpos-bc-ac');
			var listEl = document.getElementById('vanpos-bc-ac-list');
			var suggestItems = [];
			var acActive = -1;
			var acSuggestTimer = null;
			var acSuggestSeq = 0;

			function setAcExpanded(on) {
				var v = on ? 'true' : 'false';
				if (acRoot) {
					acRoot.setAttribute('aria-expanded', v);
				}
				searchEl.setAttribute('aria-expanded', v);
			}

			function closeAcList() {
				if (!listEl) {
					return;
				}
				listEl.hidden = true;
				listEl.innerHTML = '';
				suggestItems = [];
				acActive = -1;
				setAcExpanded(false);
			}

			function renderAcList(items) {
				if (!listEl) {
					return;
				}
				suggestItems = items || [];
				var n = suggestItems.length;
				if (!n) {
					closeAcList();
					return;
				}
				var h = '';
				for (var i = 0; i < n; i++) {
					var it = suggestItems[i];
					var sel = i === acActive ? 'true' : 'false';
					h +=
						'<li role="option" id="vanpos-bc-ac-opt-' +
						i +
						'" class="vanpos-bc-ac-item' +
						(i === acActive ? ' is-active' : '') +
						'" aria-selected="' +
						sel +
						'" data-index="' +
						i +
						'">' +
						(it.camper && String(it.camper).trim()
							? '<div class="vanpos-bc-ac-product">' +
							  esc(String(it.camper).trim()) +
							  '</div>'
							: '') +
						'<div class="vanpos-bc-ac-title">' +
						esc(it.title || '') +
						'</div>' +
						'<div class="vanpos-bc-ac-sub">' +
						esc(it.rangeLabel || '') +
						'</div></li>';
				}
				listEl.innerHTML = h;
				listEl.hidden = false;
				setAcExpanded(true);
				Array.prototype.forEach.call(listEl.querySelectorAll('.vanpos-bc-ac-item'), function (li) {
					li.addEventListener('mousedown', function (ev) {
						ev.preventDefault();
					});
					li.addEventListener('click', function () {
						var idx = parseInt(li.getAttribute('data-index'), 10);
						if (!isNaN(idx) && suggestItems[idx]) {
							applySuggestion(suggestItems[idx]);
						}
					});
				});
			}

			function applySuggestion(item) {
				closeAcList();
				if (!item || !item.id || !item.pickupDate) {
					return;
				}
				highlightOrderId = String(item.id);
				pendingOpenOrderId = String(item.id);
				searchEl.value = '';

				if (vanSel) {
					if (item.originalProductId) {
						var want = String(item.originalProductId);
						var found = false;
						for (var oi = 0; oi < vanSel.options.length; oi++) {
							if (vanSel.options[oi].value === want) {
								vanSel.selectedIndex = oi;
								found = true;
								break;
							}
						}
						if (!found) {
							vanSel.value = '';
						}
					} else {
						vanSel.value = '';
					}
				}

				calendar.gotoDate(item.pickupDate);
				calendar.refetchEvents();
			}

			function runSuggestFetch() {
				var q = searchEl.value ? String(searchEl.value).trim() : '';
				if (q.length < 2 || !cfg.ajaxSuggestAction) {
					closeAcList();
					return;
				}
				var seq = ++acSuggestSeq;
				if (listEl) {
					listEl.innerHTML =
						'<li class="vanpos-bc-ac-loading" role="presentation">' +
						'<span class="vanpos-bc-ac-spin" aria-hidden="true"></span>' +
						'<span class="vanpos-bc-ac-loading-text">' +
						esc((cfg.i18n || {}).loadingSuggestions || 'Searching…') +
						'</span></li>';
					listEl.hidden = false;
					setAcExpanded(true);
				}
				var pid = vanSel && vanSel.value ? vanSel.value : '0';
				var fd = new FormData();
				fd.append('action', cfg.ajaxSuggestAction);
				fd.append('nonce', cfg.ajaxNonce);
				fd.append('q', q);
				fd.append('product_id', pid);
				window
					.fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) {
						return r.json();
					})
					.then(function (body) {
						if (seq !== acSuggestSeq) {
							return;
						}
						if (!body || !body.success || !Array.isArray(body.data)) {
							closeAcList();
							return;
						}
						if (!body.data.length) {
							suggestItems = [];
							acActive = -1;
							listEl.innerHTML =
								'<li class="vanpos-bc-ac-empty" role="presentation">' +
								esc((cfg.i18n || {}).suggestNoResults || 'No matching bookings.') +
								'</li>';
							listEl.hidden = false;
							setAcExpanded(true);
							return;
						}
						acActive = -1;
						renderAcList(body.data);
					})
					.catch(function () {
						if (seq === acSuggestSeq) {
							closeAcList();
						}
					});
			}

			function scheduleSuggest() {
				if (acSuggestTimer) {
					window.clearTimeout(acSuggestTimer);
				}
				acSuggestTimer = window.setTimeout(function () {
					runSuggestFetch();
				}, 220);
			}

			searchEl.addEventListener('input', function () {
				scheduleRefetch(calendar);
				scheduleSuggest();
			});
			searchEl.addEventListener('keydown', function (ev) {
				var listOpen = listEl && !listEl.hidden;
				if (listOpen && ev.key === 'Escape') {
					ev.preventDefault();
					closeAcList();
					return;
				}
				if (listOpen && suggestItems.length) {
					if (ev.key === 'ArrowDown') {
						ev.preventDefault();
						acActive =
							acActive < suggestItems.length - 1 ? acActive + 1 : 0;
						renderAcList(suggestItems);
						return;
					}
					if (ev.key === 'ArrowUp') {
						ev.preventDefault();
						acActive =
							acActive > 0 ? acActive - 1 : suggestItems.length - 1;
						renderAcList(suggestItems);
						return;
					}
					if (ev.key === 'Enter' && acActive >= 0 && suggestItems[acActive]) {
						ev.preventDefault();
						applySuggestion(suggestItems[acActive]);
						return;
					}
				}
				if (ev.key === 'Enter') {
					ev.preventDefault();
					if (searchTimer) {
						window.clearTimeout(searchTimer);
					}
					calendar.refetchEvents();
				}
			});

			document.addEventListener('mousedown', function (ev) {
				var wrap = searchEl.closest('.vanpos-bc-ac-wrap');
				if (!wrap || wrap.contains(ev.target)) {
					return;
				}
				closeAcList();
			});

			acCloseList = closeAcList;
		}

		vanSel.addEventListener('change', function () {
			acCloseList();
			calendar.refetchEvents();
		});

		if (backdrop) {
			backdrop.addEventListener('click', closeBookingPanel);
		}
		if (panel) {
			var closeBtn = panel.querySelector('.vanpos-bc-panel-close');
			if (closeBtn) {
				closeBtn.addEventListener('click', closeBookingPanel);
			}
		}
		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && document.body.classList.contains('vanpos-bc-panel-open')) {
				closeBookingPanel();
			}
		});
	});
})();
