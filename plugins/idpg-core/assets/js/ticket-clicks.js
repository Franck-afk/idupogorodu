/**
 * Учёт кликов по билетным ссылкам (DEV-17).
 *
 * Кнопка «Купить билет» уже содержит data-event-id. На клике отправляем
 * POST /track/click через sendBeacon/fetch и НЕ отменяем переход —
 * учёт не должен ломать покупку билета при любой ошибке сети.
 */
(function () {
	'use strict';

	var config = window.idpgTicketClicks || {};

	/**
	 * Отправляет один клик. Все ошибки молча проглатываются.
	 *
	 * Основной транспорт — fetch с keepalive: он переживает уход со страницы
	 * и при этом умеет передать заголовок X-WP-Nonce. Beacon оставлен запасным
	 * путём (например, для очень старых браузеров), но он не умеет ставить
	 * заголовки, поэтому nonce уходит в URL параметром `_wpnonce` — такой
	 * вариант принимает permission_callback can_track_click().
	 *
	 * @param {number} eventId ID события.
	 */
	function track(eventId) {
		var url = String(config.restUrl || '').replace(/\/+$/, '') + '/track/click';

		if (!url || !eventId || !config.restNonce) {
			return;
		}

		var payload = JSON.stringify({ event_id: eventId });

		// fetch + keepalive: заголовки на месте, уход со страницы не мешает.
		try {
			if (window.fetch) {
				window.fetch(url, {
					method: 'POST',
					credentials: 'same-origin',
					keepalive: true,
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': config.restNonce
					},
					body: payload
				}).catch(function () {
					sendViaBeacon(url, eventId);
				});

				return;
			}
		} catch (e) {
			// Падаем на beacon ниже.
		}

		sendViaBeacon(url, eventId);
	}

	/**
	 * Запасная отправка через navigator.sendBeacon.
	 *
	 * @param {string} url Базовый URL маршрута.
	 * @param {number} eventId ID события.
	 */
	function sendViaBeacon(url, eventId) {
		if (!navigator.sendBeacon) {
			return;
		}

		try {
			navigator.sendBeacon(
				url + '?_wpnonce=' + encodeURIComponent(config.restNonce),
				new Blob([JSON.stringify({ event_id: eventId })], { type: 'application/json' })
			);
		} catch (e) {
			// Учёт клика не критичен для пользователя.
		}
	}

	/**
	 * Навешивает обработчик на билетные ссылки события.
	 *
	 * @param {Element} root Корень для поиска.
	 */
	function bind(root) {
		var links = root.querySelectorAll('.idpg-btn--primary[data-event-id], .idpg-event-single__register[data-event-id]');

		Array.prototype.forEach.call(links, function (link) {
			if (link.dataset.idpgClickBound) {
				return;
			}

			link.dataset.idpgClickBound = '1';
			link.addEventListener('click', function () {
				track(parseInt(link.getAttribute('data-event-id'), 10));
			});
		});
	}

	function init() {
		bind(document);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
