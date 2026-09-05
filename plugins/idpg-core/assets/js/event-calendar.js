/**
 * Виджет «Календарь событий» (IDPG).
 * - Навигация по месяцам через AJAX.
 * - Клик по дню с событиями: показ списка под календарём (без перехода).
 */
(function () {
	'use strict';

	var config = window.idpgCalendar || {};

	function findCal(node) {
		return node && node.closest ? node.closest('[data-idpg-cal]') : null;
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = String(str || '');
		return div.innerHTML;
	}

	function afishaUrl(day) {
		var base = config.home || (window.location.origin + '/');
		return base + 'afisha/?date=' + encodeURIComponent(day);
	}

	// --- Навигация по месяцам (AJAX) ---
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-cal-prev],[data-cal-next]') : null;
		if (!btn) {
			return;
		}
		e.preventDefault();

		var cal = findCal(btn);
		if (!cal) {
			return;
		}

		var ym = cal.getAttribute('data-calym') || '';
		var parts = ym.split('-');
		var y = parseInt(parts[0], 10);
		var m = parseInt(parts[1], 10);

		if (btn.hasAttribute('data-cal-prev')) {
			m -= 1;
			if (m < 1) { m = 12; y -= 1; }
		} else {
			m += 1;
			if (m > 12) { m = 1; y += 1; }
		}

		var nym = y + '-' + ('0' + m).slice(-2);

		if (!config.ajaxUrl || !config.nonce) {
			return;
		}

		var body = new URLSearchParams();
		body.append('action', 'idpg_calendar_month');
		body.append('nonce', config.nonce);
		body.append('ym', nym);
		body.append('cat', cal.getAttribute('data-cat') || '0');
		body.append('city', cal.getAttribute('data-city') || '0');

		fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) {
					return;
				}
				cal.setAttribute('data-calym', res.data.ym || nym);
				cal.innerHTML = res.data.html || '';
			})
			.catch(function () { window.location.reload(); });
	});

	// --- Клик по дню с событиями → список под календарём ---
	document.addEventListener('click', function (e) {
		var cell = e.target.closest ? e.target.closest('.idpg-cal__cell.has-events') : null;
		if (!cell) {
			return;
		}

		// Если клик по прямой ссылке числа (→ афиша), не перехватываем.
		if (e.target.closest('.idpg-cal__day-link')) {
			return;
		}

		e.preventDefault();

		var cal = findCal(cell);
		var detail = cal ? cal.querySelector('[data-cal-detail]') : null;
		if (!detail) {
			return;
		}

		var day = cell.getAttribute('data-day') || '';
		var titles = (cell.getAttribute('title') || '').split('; ');

		var html = '<div class="idpg-cal__detail-title">' + escapeHtml(day) + '</div>'
			+ '<ul class="idpg-cal__detail-list">';
		titles.forEach(function (t) {
			html += '<li class="idpg-cal__detail-item"><a href="' + afishaUrl(day) + '" target="_blank" rel="noopener">'
				+ escapeHtml(t) + '</a></li>';
		});
		html += '</ul>';

		detail.innerHTML = html;
	});
})();
