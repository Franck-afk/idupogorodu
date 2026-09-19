/**
 * IDPG Ad Widget — фронтенд: отложенная вставка рекламного кода.
 *
 * Виджет в режиме «Код» рендерит содержимое внутри <template>
 * и пустой слот <div data-idpg-ad="...">. После window.load
 * переносим узлы из шаблона в слот, чтобы реклама не блокировала
 * первичный рендер страницы.
 *
 * Яндекс Директ / РСЯ обрабатывается особым образом:
 *  - <script async src=".../ads/system/context.js"> НЕ вставляется в слот —
 *    его загружаем один раз в <head> (иначе дубль и поздняя загрузка
 *    ломают рендер);
 *  - блок <div id="yandex_rtb_..."> и очередь команд yaContextCb.push(...)
 *    попадают в DOM до загрузки context.js — это штатная схема РСЯ
 *    (библиотека обрабатывает накопленные команды после загрузки).
 *
 * Прочий inline-код (AdSense, iframe и т.п.) вставляется напрямую
 * с пересозданием SCRIPT-узлов, чтобы браузер их выполнил.
 *
 * @package idpg_core
 */
(function () {
	'use strict';

	// Подключает context.js Яндекса один раз на страницу.
	function ensureYandexContextRoot() {
		var src = 'https://yandex.ru/ads/system/context.js';
		var existing = document.querySelector('script[src*="ads/system/context.js"]');
		if (existing) {
			return;
		}

		window.yaContextCb = window.yaContextCb || [];

		var s = document.createElement('script');
		s.type = 'text/javascript';
		s.async = true;
		s.src = src;
		s.setAttribute('data-idpg-ad-yandex', '1');
		document.head.appendChild(s);
	}

	// Если строка/узел выглядит как подключение context.js — пропускаем вставку.
	function isYandexContextNode(node) {
		if (node.nodeName === 'SCRIPT') {
			var src = node.getAttribute ? (node.getAttribute('src') || '') : '';
			return /ads\/system\/context\.js/.test(src);
		}
		return false;
	}

	function mountAds() {
		var slots = document.querySelectorAll('.idpg-ad__slot[data-idpg-ad]');
		if (!slots.length) {
			return;
		}

		// Подключаем context.js Яндекса последним — после того как все
		// блоки и команды yaContextCb.push попали в DOM/очередь.
		var needYandex = false;

		slots.forEach(function (slot) {
			var id = slot.getAttribute('data-idpg-ad');
			var tpl = document.getElementById(id);

			if (!tpl || slot.hasAttribute('data-idpg-ad-mounted')) {
				return;
			}

			slot.setAttribute('data-idpg-ad-mounted', '1');

			var nodes = tpl.content ? tpl.content.childNodes : tpl.childNodes;

			// Флаг: в блоке есть Яндекс код → подключаем context.js в head.
			if (Array.prototype.some.call(nodes, function (n) {
				return isYandexContextNode(n) || (String(n.textContent || '').indexOf('yaContextCb') !== -1);
			})) {
				needYandex = true;
			}

			var frag = document.createDocumentFragment();

			Array.prototype.slice.call(nodes).forEach(function (node) {
				var clone = node.cloneNode(true);

				// Inline/внешние скрипты из <template> не выполняются при
				// клонировании — создаём заново, чтобы браузер запустил их.
				// context.js из шаблона убираем: он подключён в head.
				if (clone.nodeName === 'SCRIPT') {
					if (isYandexContextNode(clone)) {
						return;
					}
					var s = document.createElement('script');
					Array.prototype.slice.call(clone.attributes || []).forEach(function (attr) {
						s.setAttribute(attr.name, attr.value);
					});
					s.text = clone.text || '';
					clone = s;
				}

				frag.appendChild(clone);
			});

			slot.appendChild(frag);
		});

		if (needYandex) {
			ensureYandexContextRoot();
		}
	}

	function init() {
		if (document.readyState === 'complete') {
			mountAds();
		} else {
			window.addEventListener('load', mountAds);
		}
	}

	init();
})();
