/**
 * Избранное: клик по сердцу в карточке + счётчик в шапке.
 */
(function () {
	'use strict';

	var cfg = window.idpgFav || {};

	function updateCounters(count) {
		document.querySelectorAll('.idpg-fav-counter__num').forEach(function (el) {
			el.textContent = count;
		});
	}

	function request(action, eventId) {
		return fetch(cfg.restUrl + '/' + action, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify({ event_id: eventId })
		}).then(function (r) { return r.json(); });
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.idpg-fav-btn') : null;
		if (!btn) return;

		e.preventDefault();
		var id = parseInt(btn.getAttribute('data-fav-id'), 10);
		var isActive = btn.classList.contains('is-active');
		var action = isActive ? 'remove' : 'add';

		btn.disabled = true;

		request(action, id).then(function (res) {
			if (!res || res.code) { btn.disabled = false; return; }
			btn.classList.toggle('is-active', !!res.added);
			updateCounters(res.count);

			// Страница избранного: убираем карточку при удалении.
			if (!res.added && document.body.classList.contains('idpg-fav-page')) {
				var card = btn.closest('.idpg-event-card, .fav-page-card');
				if (card) card.remove();
			}
			btn.disabled = false;
		}).catch(function () { btn.disabled = false; });
	});
})();
