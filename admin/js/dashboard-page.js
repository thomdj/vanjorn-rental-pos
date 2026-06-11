(function ($) {
	"use strict";

	var debounceTimer = null;
	var dashboardCtl = null;

	function getElements() {
		return {
			form: document.querySelector("[data-vanpos-dashboard-form]"),
			loading: document.querySelector("[data-vanpos-dashboard-loading]"),
			stats: document.querySelector("[data-vanpos-dashboard-stats]"),
			tableBody: document.querySelector("[data-vanpos-dashboard-table-body]"),
			pagination: document.querySelector("[data-vanpos-dashboard-pagination]"),
			pageInput: document.querySelector("[data-vanpos-page-input]"),
			tableWrap: document.querySelector("[data-vanpos-dashboard-table-wrap]")
		};
	}

	function tableColspan() {
		var d = window.vanposDashboard || {};
		return parseInt(String(d.tableColspan || "9"), 10) || 9;
	}

	function showSkeleton(tableBody) {
		var i;
		var colspan = tableColspan();
		var rows = "";
		for (i = 0; i < 6; i += 1) {
			rows += "<tr class='vanpos-skeleton-row'>";
			rows += "<td colspan='" + colspan + "'><span class='vanpos-skeleton-block'></span></td>";
			rows += "</tr>";
		}
		tableBody.innerHTML = rows;
	}

	function setLoadingState(els, isLoading) {
		if (!els.loading || !els.tableWrap) {
			return;
		}
		els.loading.hidden = !isLoading;
		els.tableWrap.classList.toggle("is-loading", isLoading);
	}

	function injectNotice(type, message) {
		var wrap = document.querySelector(".vanpos-dashboard-page__wrap-for-notices");
		if (!wrap) {
			return;
		}
		var cls = type === "error" ? "notice-error" : "notice-success";
		var notice = document.createElement("div");
		notice.className = "notice " + cls + " is-dismissible vanpos-dashboard-page__notice";
		notice.setAttribute("role", "alert");
		var p = document.createElement("p");
		p.textContent = String(message || "");
		notice.appendChild(p);
		var dismiss = document.createElement("button");
		dismiss.type = "button";
		dismiss.className = "notice-dismiss";
		dismiss.setAttribute("aria-label", "Dismiss");
		dismiss.innerHTML = '<span class="screen-reader-text">Dismiss</span>';
		notice.appendChild(dismiss);
		wrap.innerHTML = "";
		wrap.appendChild(notice);
		dismiss.addEventListener("click", function () {
			notice.remove();
		});
		window.scrollTo({ top: 0, behavior: "smooth" });
	}

	function highlightedMainOrderHtml(btn) {
		var raw = ((btn && btn.getAttribute("data-order-number")) || "").trim();
		var display = raw.indexOf("#") === 0 ? raw : "#" + raw;
		var escapeDiv = document.createElement("div");
		escapeDiv.appendChild(document.createTextNode(display));
		return (
			'<strong class="vanpos-dashboard-order-highlight">' +
			escapeDiv.innerHTML +
			"</strong>"
		);
	}

	function bookingModalInsertOrder(btn, template) {
		var tpl = template || "";
		if (tpl.indexOf("%s") === -1) {
			return tpl;
		}
		return tpl.split("%s").join(highlightedMainOrderHtml(btn));
	}

	/**
	 * Fetch linked payment-order summaries for a primary order on demand.
	 * Replaces the old per-row data-child-orders attribute (which forced an
	 * N+1 on every render). On any failure we fall back to an empty list so
	 * the user can still action the primary order.
	 *
	 * @param {string|number} orderId Primary order ID.
	 * @param {function(Array)} cb    Receives the child-order array.
	 */
	function fetchChildOrders(orderId, cb) {
		var base = window.vanposDashboard || {};
		$.ajax({
			url: base.ajaxUrl || window.ajaxurl,
			method: "POST",
			data: {
				action: "vanpos_dashboard_child_orders",
				nonce: base.nonce || "",
				order_id: orderId
			}
		})
			.done(function (resp) {
				if (resp && resp.success && resp.data && Array.isArray(resp.data.children)) {
					cb(resp.data.children);
				} else {
					cb([]);
				}
			})
			.fail(function () {
				cb([]);
			});
	}

	/**
	 * Build the trash/cancel confirmation modal.
	 *
	 * @param {HTMLElement} btn         The clicked action button.
	 * @param {Object}      i18n        Localized strings.
	 * @param {Array}       childOrders Linked payment orders (fetched on open).
	 */
	function buildBookingGroupModal(btn, i18n, childOrders) {
		i18n = i18n || {};
		childOrders = Array.isArray(childOrders) ? childOrders : [];
		var confirmLabel = i18n.confirmAction || i18n.confirmTrash || "Confirm";

		var $backdrop = $(
			"<div class='vanpos-booking-delete-modal-backdrop' style='display:none;' aria-modal='true' role='dialog'></div>"
		);
		var $dlg = $('<div class="vanpos-booking-delete-modal"/>');
		$dlg.append(
			$("<h2/>", { class: "vanpos-booking-delete-modal__title", html: i18n.modalTitle || "" })
		);
		$dlg.append(
			$("<div/>", {
				class: "vanpos-booking-delete-modal__intro",
				html: bookingModalInsertOrder(btn, i18n.modalIntroTpl || i18n.modalIntro || "")
			})
		);
		$dlg.append(
			$("<div/>", { class: "vanpos-booking-delete-modal__note", html: i18n.modalKestrelNote || "" })
		);

		var hasChildren = childOrders.length > 0;
		var $chkRow = $("<label class='vanpos-booking-delete-modal__check'/>");
		var $cb = $("<input type='checkbox' class='vanpos-booking-group-with-children'/>");
		if (hasChildren) {
			$cb.prop("checked", true);
		}
		$chkRow.append($cb).append(
			$("<span/>", { class: "vanpos-booking-delete-modal__check-text", html: i18n.checkboxLabel || "" })
		);

		if (hasChildren) {
			$dlg.append(
				$("<p/>", { class: "vanpos-booking-delete-modal__list-title", text: i18n.childrenListLead || "" })
			);
			var $ul = $("<ul class='vanpos-booking-delete-modal__list'/>");
			childOrders.forEach(function (c) {
				var num = String(c.number || c.id || "");
				var statusLabel = String(
					c.status_label !== undefined && c.status_label !== ""
						? c.status_label
						: c.status || ""
				);
				var $li = $("<li/>");
				$li.append(document.createTextNode("#" + num + " "));
				$li.append(
					$("<span/>", { class: "vanpos-muted" }).text(
						statusLabel !== "" ? "(" + statusLabel + ")" : ""
					)
				);
				$ul.append($li);
			});
			$dlg.append($ul);
			$dlg.append($chkRow);
		} else {
			$dlg.append(
				$("<div/>", {
					class: "vanpos-booking-delete-modal__no-children",
					html: bookingModalInsertOrder(btn, i18n.noChildrenBodyTpl || i18n.noChildrenBody || "")
				})
			);
			$chkRow.hide();
			$dlg.append($chkRow);
		}

		var $actions = $("<p class='vanpos-booking-delete-modal__actions'/>");
		var $btnCancel = $("<button type='button' class='button'/>").text(i18n.cancel || "Cancel");
		var $btnOk = $("<button type='button' class='button button-primary'/>").text(confirmLabel);
		$actions.append($btnCancel).append($btnOk);
		$dlg.append($actions);
		$backdrop.append($dlg);

		return {
			$backdrop: $backdrop,
			childCount: childOrders.length,
			getIncludeChildren: function () {
				if (!hasChildren) {
					return false;
				}
				return $cb.prop("checked");
			},
			onConfirm: function (fn) {
				$btnOk.on("click", fn);
			},
			onCancel: function (fn) {
				$btnCancel.on("click", fn);
			},
			show: function () {
				$backdrop.css("display", "flex").appendTo("body");
			},
			close: function () {
				$backdrop.remove();
			},
			disableOk: function (txt) {
				$btnOk.prop("disabled", true).text(txt || "…");
			}
		};
	}

	function runBookingGroupAjax(orderId, includeChildren, ui, config) {
		config = config || {};
		var i18nFull = config.i18n || {};

		$.ajax({
			url: (window.vanposDashboard && window.vanposDashboard.ajaxUrl) || window.ajaxurl,
			method: "POST",
			data: config.ajaxData(orderId, includeChildren)
		})
			.done(function (resp) {
				ui.close();
				if (resp && resp.success && resp.data && resp.data.message) {
					injectNotice("success", resp.data.message);
					if (
						resp.data.reload_dashboard &&
						dashboardCtl &&
						typeof dashboardCtl.refresh === "function"
					) {
						dashboardCtl.refresh(false);
					}
				} else {
					injectNotice(
						"error",
						i18nFull.errorGeneric || "Something went wrong. Please try again."
					);
				}
			})
			.fail(function (xhr) {
				ui.close();
				var msg = i18nFull.errorGeneric || "Error";
				var j = xhr.responseJSON;
				if (j && j.data && j.data.message) {
					msg = ((i18nFull.errorDetail || "Details: %s") + "").replace(
						"%s",
						String(j.data.message)
					);
				}
				injectNotice("error", msg);
			});
	}

	/**
	 * Shared click handler for both trash and cancel buttons: fetch the linked
	 * orders, then open the modal populated with them.
	 *
	 * @param {HTMLElement} btnEl  The clicked button.
	 * @param {Object}      i18n   Localized strings for this action.
	 * @param {function}    ajaxDataFn Builds the confirm-action AJAX payload.
	 */
	function openBookingGroupModal(btnEl, i18n, ajaxDataFn) {
		var $btn = $(btnEl);
		var orderId = $btn.attr("data-order-id");
		if (!orderId || $btn.prop("disabled")) {
			return;
		}

		// Brief disable while we fetch linked orders (one quick request).
		$btn.prop("disabled", true);

		fetchChildOrders(orderId, function (children) {
			$btn.prop("disabled", false);

			var ui = buildBookingGroupModal(btnEl, i18n, children);
			ui.show();
			ui.onCancel(function () {
				ui.close();
			});
			ui.onConfirm(function () {
				ui.disableOk(i18n.busy || "…");
				runBookingGroupAjax(orderId, ui.getIncludeChildren(), ui, {
					i18n: i18n,
					ajaxData: ajaxDataFn
				});
			});
		});
	}

	function initBookingTrashDelete() {
		var base = window.vanposDashboard || {};
		if (!base.canTrashBookings) {
			return;
		}
		var bd = base.bookingDelete || {};

		$(document).on("click", "[data-vanpos-booking-delete]", function (e) {
			e.preventDefault();
			openBookingGroupModal(this, bd, function (id, includeChildren) {
				return {
					action: "vanpos_trash_primary_rental_group",
					nonce: base.trashBookingNonce || "",
					order_id: id,
					delete_children: includeChildren ? "1" : "0",
					context: "dashboard"
				};
			});
		});
	}

	function initBookingCancel() {
		var base = window.vanposDashboard || {};
		if (!base.canCancelBookings) {
			return;
		}
		var bc = base.bookingCancel || {};

		$(document).on("click", "[data-vanpos-booking-cancel]", function (e) {
			e.preventDefault();
			openBookingGroupModal(this, bc, function (id, includeChildren) {
				return {
					action: "vanpos_cancel_primary_rental_group",
					nonce: base.cancelBookingNonce || "",
					order_id: id,
					cancel_children: includeChildren ? "1" : "0",
					context: "dashboard"
				};
			});
		});
	}

	function submitAjax(els, forcePageReset) {
		var formData;
		if (!els.form) {
			return;
		}

		if (forcePageReset && els.pageInput) {
			els.pageInput.value = "1";
		}

		formData = new FormData(els.form);
		formData.append("action", "vanpos_dashboard_filter");
		formData.append("nonce", (window.vanposDashboard && window.vanposDashboard.nonce) || "");

		showSkeleton(els.tableBody);
		setLoadingState(els, true);

		$.ajax({
			url: (window.vanposDashboard && window.vanposDashboard.ajaxUrl) || ajaxurl,
			method: "POST",
			data: formData,
			processData: false,
			contentType: false
		}).done(function (resp) {
			if (!resp || !resp.success || !resp.data) {
				return;
			}
			els.stats.innerHTML = resp.data.stats_html || "";
			els.tableBody.innerHTML = resp.data.table_rows_html || "";
			els.pagination.innerHTML = resp.data.pagination_html || "";
			els.tableWrap.classList.add("vanpos-fade-in");
			window.setTimeout(function () {
				els.tableWrap.classList.remove("vanpos-fade-in");
			}, 260);
		}).always(function () {
			setLoadingState(els, false);
		});
	}

	function initDashboardAjax() {
		var els = getElements();
		if (!els.form || !els.tableBody || !els.stats || !els.pagination) {
			return;
		}

		setLoadingState(els, false);

		dashboardCtl = {
			refresh: function (forceFirstPage) {
				submitAjax(els, !!forceFirstPage);
			}
		};

		els.form.addEventListener("submit", function (event) {
			event.preventDefault();
			submitAjax(els, false);
		});

		els.form.addEventListener("change", function (event) {
			if (event.target && event.target.hasAttribute("data-vanpos-auto")) {
				submitAjax(els, true);
			}
		});

		els.form.addEventListener("input", function (event) {
			if (event.target && event.target.hasAttribute("data-vanpos-search")) {
				window.clearTimeout(debounceTimer);
				debounceTimer = window.setTimeout(function () {
					submitAjax(els, true);
				}, 320);
			}
		});

		els.pagination.addEventListener("click", function (event) {
			var btn = event.target.closest("[data-vanpos-page]");
			if (!btn || !els.pageInput) {
				return;
			}
			event.preventDefault();
			els.pageInput.value = btn.getAttribute("data-vanpos-page");
			submitAjax(els, false);
		});

		initBookingTrashDelete();
		initBookingCancel();
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initDashboardAjax);
	} else {
		initDashboardAjax();
	}
})(jQuery);
