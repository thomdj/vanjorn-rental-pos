(function ($) {
	"use strict";

	var debounceTimer = null;
	var queueCtl = null;

	function getElements() {
		return {
			form: document.querySelector("[data-vanpos-returns-form]"),
			loading: document.querySelector("[data-vanpos-returns-loading]"),
			stats: document.querySelector("[data-vanpos-returns-stats]"),
			tableBody: document.querySelector("[data-vanpos-returns-table-body]"),
			pagination: document.querySelector("[data-vanpos-returns-pagination]"),
			pageInput: document.querySelector("[data-vanpos-page-input]"),
			tableWrap: document.querySelector("[data-vanpos-returns-table-wrap]")
		};
	}

	function setLoadingState(els, isLoading) {
		if (!els.loading) {
			return;
		}
		els.loading.hidden = !isLoading;
		if (els.tableWrap) {
			els.tableWrap.classList.toggle("is-loading", isLoading);
		}
	}

	function injectNotice(type, message) {
		var wrap = document.querySelector(".vanpos-returns-queue-page__wrap-for-notices");
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

	function runMarkReturnedAjax(orderId, $btn) {
		var base = window.vanposReturnsQueue || {};
		var mr = base.markReturned || {};
		var busy = mr.busy || "…";
		var origText = $btn.text();

		$btn.prop("disabled", true).text(busy);

		$.ajax({
			url: base.ajaxUrl || window.ajaxurl,
			method: "POST",
			data: {
				action: "vanpos_dashboard_mark_returned",
				nonce: base.markReturnedNonce || "",
				order_id: orderId
			}
		})
			.done(function (resp) {
				if (resp && resp.success && resp.data && resp.data.message) {
					injectNotice("success", resp.data.message);
					if (
						resp.data.reload_dashboard &&
						queueCtl &&
						typeof queueCtl.refresh === "function"
					) {
						queueCtl.refresh(false);
					}
					return;
				}
				$btn.prop("disabled", false).text(origText);
				injectNotice(
					"error",
					mr.errorGeneric || "Could not mark as returned. Please try again."
				);
			})
			.fail(function (xhr) {
				$btn.prop("disabled", false).text(origText);
				var msg = mr.errorGeneric || "Could not mark as returned. Please try again.";
				var j = xhr.responseJSON;
				if (j && j.data && j.data.message) {
					msg = ((mr.errorDetail || "Details: %s") + "").replace(
						"%s",
						String(j.data.message)
					);
				}
				injectNotice("error", msg);
			});
	}

	function initMarkReturned() {
		var base = window.vanposReturnsQueue || {};
		if (!base.canMarkReturned) {
			return;
		}
		var mr = base.markReturned || {};

		$(document).on("click", "[data-vanpos-mark-returned]", function (e) {
			e.preventDefault();
			var $btn = $(this);
			var orderId = $btn.attr("data-order-id");
			if (!orderId) {
				return;
			}
			if (!window.confirm(mr.confirm || "Mark this van as returned?")) {
				return;
			}
			runMarkReturnedAjax(orderId, $btn);
		});
	}

	function submitAjax(els, forcePageReset) {
		if (!els.form) {
			return;
		}

		if (forcePageReset && els.pageInput) {
			els.pageInput.value = "1";
		}

		var formData = new FormData(els.form);
		formData.append("action", "vanpos_returns_queue_filter");
		formData.append("nonce", (window.vanposReturnsQueue && window.vanposReturnsQueue.nonce) || "");

		setLoadingState(els, true);

		$.ajax({
			url: (window.vanposReturnsQueue && window.vanposReturnsQueue.ajaxUrl) || window.ajaxurl,
			method: "POST",
			data: formData,
			processData: false,
			contentType: false,
			dataType: "json"
		})
			.done(function (resp) {
				if (!resp || !resp.success || !resp.data) {
					injectNotice(
						"error",
						(window.vanposReturnsQueue &&
							window.vanposReturnsQueue.i18n &&
							window.vanposReturnsQueue.i18n.loadError) ||
							"Could not load the returns queue. Please refresh the page."
					);
					return;
				}
				if (els.stats) {
					els.stats.innerHTML = resp.data.stats_html || "";
				}
				if (els.tableBody) {
					els.tableBody.innerHTML = resp.data.table_rows_html || "";
				}
				if (els.pagination) {
					els.pagination.innerHTML = resp.data.pagination_html || "";
				}
				if (els.tableWrap) {
					els.tableWrap.classList.add("vanpos-fade-in");
					window.setTimeout(function () {
						els.tableWrap.classList.remove("vanpos-fade-in");
					}, 260);
				}
			})
			.fail(function () {
				injectNotice(
					"error",
					(window.vanposReturnsQueue &&
						window.vanposReturnsQueue.i18n &&
						window.vanposReturnsQueue.i18n.loadError) ||
						"Could not load the returns queue. Please refresh the page."
				);
			})
			.always(function () {
				setLoadingState(els, false);
			});
	}

	function initReturnsQueueAjax() {
		var els = getElements();
		if (!els.form || !els.tableBody) {
			return;
		}

		setLoadingState(els, false);

		queueCtl = {
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

		if (els.pagination) {
			els.pagination.addEventListener("click", function (event) {
				var btn = event.target.closest("[data-vanpos-page]");
				if (!btn || !els.pageInput) {
					return;
				}
				event.preventDefault();
				els.pageInput.value = btn.getAttribute("data-vanpos-page");
				submitAjax(els, false);
			});
		}

		initMarkReturned();
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initReturnsQueueAjax);
	} else {
		initReturnsQueueAjax();
	}
})(jQuery);
