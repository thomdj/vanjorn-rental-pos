/**
 * VanPOS Mailer Debug — Admin JavaScript
 *
 * Handles status loading, preview, run, single-order inspect,
 * and history rendering.
 *
 * @package VJ_Rental_POS
 */
(function( $, config ) {
	'use strict';

	// ============================================================
	// Cached DOM refs
	// ============================================================

	var $statusLoading = $( '#vanpos-aw-status-loading' );
	var $statusBody    = $( '#vanpos-aw-status-body' );
	var $btnPreview    = $( '#vanpos-aw-btn-preview' );
	var $btnRun        = $( '#vanpos-aw-btn-run' );
	var $actionStatus  = $( '#vanpos-aw-action-status' );
	var $resultsSection = $( '#vanpos-aw-results-section' );
	var $resultsHeading = $( '#vanpos-aw-results-heading' );
	var $resultsSummary = $( '#vanpos-aw-results-summary' );
	var $resultsTable   = $( '#vanpos-aw-results-table' );
	var $resultsBody    = $( '#vanpos-aw-results-body' );
	var $detailSection  = $( '#vanpos-aw-detail-section' );
	var $detailHeading  = $( '#vanpos-aw-detail-heading' );
	var $detailBody     = $( '#vanpos-aw-detail-body' );
	var $historySection = $( '#vanpos-aw-history-section' );
	var $historyBody    = $( '#vanpos-aw-history-body' );

	// Stores the last run result for per-order drill-down.
	var lastRunData = null;

	// ============================================================
	// Helpers
	// ============================================================

	function esc( str ) {
		return $( '<span>' ).text( str || '' ).html();
	}

	function orderLink( id ) {
		if ( ! id ) { return '—'; }
		return '<a href="' + esc( config.order_url ) + id + '" target="_blank">#' + id + '</a>';
	}

	function badge( text, type ) {
		return '<span class="vanpos-aw-badge vanpos-aw-badge--' + type + '">' + esc( text ) + '</span>';
	}

	function wfBadge( status, label ) {
		return '<span class="vanpos-aw-wf-badge vanpos-aw-wf-badge--' + status + '" title="' + esc( label ) + '">' + esc( label ) + '</span>';
	}

	function ajaxPost( action, extra, callback ) {
		var data = $.extend( { action: action, nonce: config.nonce }, extra || {} );
		return $.post( config.ajax_url, data, callback, 'json' );
	}

	function setButtons( disabled ) {
		$btnPreview.prop( 'disabled', disabled );
		$btnRun.prop( 'disabled', disabled );
	}

	// ============================================================
	// Status panel — loads on page ready
	// ============================================================

	function loadStatus() {
		ajaxPost( 'vanpos_aw_cron_status', {}, function( res ) {
			$statusLoading.hide();

			if ( ! res.success ) {
				$statusBody.html( '<p style="color:red;">' + esc( res.data ) + '</p>' ).show();
				return;
			}

			var d = res.data;

			$( '#vanpos-aw-s-hook' ).html( '<code>' + esc( d.hook ) + '</code>' );
			$( '#vanpos-aw-s-scheduled' ).html( d.scheduled ? badge( 'Yes', 'ok' ) : badge( 'No — not scheduled!', 'error' ) );
			$( '#vanpos-aw-s-next-local' ).text( d.next_local || '—' );
			$( '#vanpos-aw-s-next-utc' ).text( d.next_utc || '—' );
			$( '#vanpos-aw-s-now' ).text( d.now_local );
			$( '#vanpos-aw-s-tz' ).text( d.timezone );
			$( '#vanpos-aw-s-awloaded' ).html( d.aw_loaded ? badge( 'Yes', 'ok' ) : badge( 'No', 'error' ) );
			$( '#vanpos-aw-s-trigger' ).html(
				d.trigger_registered
					? badge( 'Yes', 'ok' ) + ' <code class="vanpos-aw-mono">' + esc( d.trigger_class ) + '</code>'
					: badge( 'No — trigger not found', 'error' )
			);

			// Workflows list.
			var $wf = $( '#vanpos-aw-s-workflows' );
			if ( d.workflows.length === 0 ) {
				$wf.html( '<p>' + badge( 'None found', 'warn' ) + ' — no active workflows use this trigger.</p>' );
			} else {
				var html = '<ul class="vanpos-aw-wf-list">';
				$.each( d.workflows, function( i, wf ) {
					html += '<li>' + esc( wf.title ) + ' <code>#' + wf.id + '</code> '
						+ badge( wf.status, wf.status === 'publish' ? 'ok' : 'warn' )
						+ '</li>';
				} );
				html += '</ul>';
				$wf.html( html );
			}

			// History.
			renderHistory( d.history );

			$statusBody.show();
		} ).fail( function() {
			$statusLoading.text( 'Failed to load status.' );
		} );
	}

	// ============================================================
	// Preview
	// ============================================================

	$btnPreview.on( 'click', function() {
		setButtons( true );
		$actionStatus.text( 'Loading preview…' );
		$detailSection.hide();
		lastRunData = null;

		ajaxPost( 'vanpos_aw_cron_preview', {}, function( res ) {
			setButtons( false );
			$actionStatus.text( '' );

			if ( ! res.success ) {
				$actionStatus.text( 'Error: ' + res.data );
				return;
			}

			var d = res.data;
			$resultsHeading.text( 'Preview — Pending Payment Orders' );
			$resultsSummary.html( d.count + ' order(s) found with status <strong>pending</strong> and <code>_vanpos_order_type = payment_order</code>.' );

			renderOrderTable( d.orders, false );
			$resultsSection.show();
		} ).fail( function() {
			setButtons( false );
			$actionStatus.text( 'Request failed.' );
		} );
	} );

	// ============================================================
	// Run Trigger Now
	// ============================================================

	$btnRun.on( 'click', function() {
		if ( ! confirm( 'This will fire the real trigger. Active workflows will send emails if their rules match.\n\nContinue?' ) ) {
			return;
		}

		setButtons( true );
		$actionStatus.text( 'Running trigger…' );
		$detailSection.hide();
		lastRunData = null;

		ajaxPost( 'vanpos_aw_cron_run', {}, function( res ) {
			setButtons( false );
			$actionStatus.text( '' );

			if ( ! res.success ) {
				$actionStatus.text( 'Error: ' + res.data );
				return;
			}

			var d = res.data;
			lastRunData = d;

			$resultsHeading.text( 'Run Results — ' + d.timestamp );

			var summary = '<strong>' + d.order_count + '</strong> order(s) processed.';
			if ( d.errors.length ) {
				summary += ' <span style="color:#a94442;">' + d.errors.join( '; ' ) + '</span>';
			}
			if ( d.trigger_ok ) {
				summary += ' ' + badge( 'Trigger fired', 'ok' );
			}
			$resultsSummary.html( summary );

			renderOrderTable( d.orders, true );
			$resultsSection.show();

			// Refresh status + history.
			loadStatus();
		} ).fail( function() {
			setButtons( false );
			$actionStatus.text( 'Request failed.' );
		} );
	} );

	// ============================================================
	// Order table rendering
	// ============================================================

	function renderOrderTable( orders, showWorkflows ) {
		$resultsBody.empty();

		if ( ! orders || orders.length === 0 ) {
			$resultsTable.hide();
			return;
		}

		$.each( orders, function( i, o ) {
			var wfHtml = '—';
			if ( showWorkflows && o.workflow_results ) {
				wfHtml = '';
				$.each( o.workflow_results, function( j, wf ) {
					wfHtml += wfBadge( wf.status, wf.name );
				} );
			}

			var inspectBtn = '<button class="button button-small vanpos-aw-inspect-btn" data-order-id="' + o.order_id + '">Inspect</button>';

			$resultsBody.append(
				'<tr>' +
				'<td>' + orderLink( o.order_id ) + '</td>' +
				'<td>' + orderLink( o.parent_id ) + '</td>' +
				'<td>' + esc( o.payment_type ) + '</td>' +
				'<td>' + esc( o.customer ) + '</td>' +
				'<td>&euro;' + esc( o.total ) + '</td>' +
				'<td>' + esc( o.due_date ) + '</td>' +
				'<td>' + esc( o.pickup_date ) + '</td>' +
				'<td>' + wfHtml + '</td>' +
				'<td>' + inspectBtn + '</td>' +
				'</tr>'
			);
		} );

		$resultsTable.show();
	}

	// ============================================================
	// Per-order inspect
	// ============================================================

	$( document ).on( 'click', '.vanpos-aw-inspect-btn', function() {
		var orderId = $( this ).data( 'order-id' );

		// Check if we already have the data from a full run.
		var cached = null;
		if ( lastRunData && lastRunData.orders ) {
			$.each( lastRunData.orders, function( i, o ) {
				if ( o.order_id === orderId ) {
					cached = o;
					return false;
				}
			} );
		}

		if ( cached && cached.workflow_results && cached.workflow_results.length > 0 ) {
			renderDetail( cached );
			return;
		}

		// Otherwise fetch via AJAX.
		$detailHeading.text( 'Loading order #' + orderId + '…' );
		$detailBody.empty();
		$detailSection.show();

		ajaxPost( 'vanpos_aw_cron_run_single', { order_id: orderId }, function( res ) {
			if ( ! res.success ) {
				$detailBody.html( '<p style="color:red;">' + esc( res.data ) + '</p>' );
				return;
			}
			renderDetail( res.data );
		} ).fail( function() {
			$detailBody.html( '<p style="color:red;">Request failed.</p>' );
		} );
	} );

	function renderDetail( order ) {
		$detailHeading.text( 'Order #' + order.order_id + ' — ' + ( order.customer || 'Unknown' ) );

		var html = '';

		// Order meta summary.
		html += '<table class="vanpos-aw-order-meta-table">';
		html += metaRow( 'Order ID', order.order_id );
		html += metaRow( 'Parent', order.parent_id );
		html += metaRow( 'Status', order.status );
		html += metaRow( 'Payment Type', order.payment_type );
		html += metaRow( 'Total', '€' + order.total );
		html += metaRow( 'Due Date', order.due_date );
		html += metaRow( 'Pickup Date', order.pickup_date );
		html += metaRow( 'Return Date', order.return_date );
		html += metaRow( 'Booking Ref', order.booking_ref );
		html += metaRow( 'Email', order.email );
		html += metaRow( 'Short-term', order.is_short_term || 'no' );
		html += '</table>';

		// Workflow results.
		if ( order.workflow_results && order.workflow_results.length > 0 ) {
			$.each( order.workflow_results, function( i, wf ) {
				html += '<div class="vanpos-aw-detail-wf">';
				html += '<h4>' + esc( wf.name ) + ' <code>#' + ( wf.id || '?' ) + '</code> ';
				html += wfBadge( wf.status, wf.status ) + '</h4>';
				html += '<p class="vanpos-aw-muted">' + esc( wf.detail ) + '</p>';

				if ( wf.rules && wf.rules.length > 0 ) {
					html += '<table class="vanpos-aw-rules-table">';
					html += '<thead><tr>';
					html += '<th>Group</th><th>Rule</th><th>Operator</th><th>Value</th><th>Meta Value</th><th>Result</th><th>Detail</th>';
					html += '</tr></thead><tbody>';

					$.each( wf.rules, function( j, r ) {
						var cls = r.passed === true ? 'rule-passed' : ( r.passed === false ? 'rule-failed' : 'rule-unknown' );
						var passText = r.passed === true ? '✓ Passed' : ( r.passed === false ? '✗ Failed' : '? Unknown' );
						var valDisplay = r.value;
						if ( typeof valDisplay === 'object' && valDisplay !== null ) {
							valDisplay = JSON.stringify( valDisplay );
						}

						html += '<tr>';
						html += '<td>' + r.group + '</td>';
						html += '<td><code>' + esc( r.rule ) + '</code></td>';
						html += '<td>' + esc( r.compare ) + '</td>';
						html += '<td>' + esc( valDisplay ) + '</td>';
						html += '<td>' + esc( r.meta_value || '' ) + '</td>';
						html += '<td class="' + cls + '">' + passText + '</td>';
						html += '<td>' + esc( r.detail ) + '</td>';
						html += '</tr>';
					} );

					html += '</tbody></table>';
				}

				html += '</div>';
			} );
		} else {
			html += '<p class="vanpos-aw-muted">No workflow data available. Click "Run Trigger Now" first for full diagnostics, or this order has no active workflows to evaluate.</p>';
		}

		$detailBody.html( html );
		$detailSection.show();

		// Scroll to detail.
		$( 'html, body' ).animate( { scrollTop: $detailSection.offset().top - 40 }, 300 );
	}

	function metaRow( label, value ) {
		return '<tr><th>' + esc( label ) + '</th><td>' + esc( value || '—' ) + '</td></tr>';
	}

	// ============================================================
	// History
	// ============================================================

	function renderHistory( history ) {
		if ( ! history || history.length === 0 ) {
			$historySection.hide();
			return;
		}

		$historyBody.empty();
		$.each( history, function( i, h ) {
			var errHtml = h.errors && h.errors.length
				? '<span style="color:#a94442;">' + $.map( h.errors, esc ).join( '; ' ) + '</span>'
				: '—';

			$historyBody.append(
				'<tr>' +
				'<td>' + esc( h.time ) + '</td>' +
				'<td>' + badge( h.source, h.source === 'manual' ? 'info' : 'muted' ) + '</td>' +
				'<td>' + h.order_count + '</td>' +
				'<td>' + ( h.matched > 0 ? '<strong style="color:#3c763d;">' + h.matched + '</strong>' : '0' ) + '</td>' +
				'<td>' + ( h.already_run > 0 ? h.already_run : '0' ) + '</td>' +
				'<td>' + ( h.failed > 0 ? '<strong style="color:#a94442;">' + h.failed + '</strong>' : '0' ) + '</td>' +
				'<td>' + errHtml + '</td>' +
				'</tr>'
			);
		} );

		$historySection.show();
	}

	// ============================================================
	// Init
	// ============================================================

	$( document ).ready( function() {
		loadStatus();
	} );

})( jQuery, vanposAwCron );
