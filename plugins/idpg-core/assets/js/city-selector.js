/**
 * Виджет «Выбор города» (IDPG).
 *
 * Смена города → cookie через AJAX idpg_set_city (fallback-механизм)
 * и переход на афишу выбранного города: /afisha/{slug}/
 */
(function () {
	'use strict';

	var config = window.idpgCitySelector || {};
	var form = document.querySelector('[data-idpg-city-form]');
	var selects = document.querySelectorAll('select[data-idpg-city], select[data-idpg-city-header]');
	var buttons = document.querySelectorAll('[data-idpg-city] .idpg-city-selector__city');

	var currentSlug = '';

	/**
	 * Переход на афишу выбранного города.
	 * Cookie уже установлена AJAX-запросом (fallback для страниц без города в URL).
	 */
	function goAfisha() {
		var base = String(config.home || window.location.origin + '/').replace(/\/+$/, '');
		window.location.href = base + '/afisha/' + currentSlug + '/';
	}

	function selectCity(id, slug) {
		if (!id || !slug) {
			return;
		}
		currentSlug = slug;

		if (config.ajaxUrl && config.nonce) {
			var body = new URLSearchParams();
			body.append('action', 'idpg_set_city');
			body.append('city_id', id);
			body.append('nonce', config.nonce);

			fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			}).finally(goAfisha);
			return;
		}

		goAfisha();
	}

	function bindSelect(select) {
		if (!select || select.dataset.idpgBound) {
			return;
		}
		select.dataset.idpgBound = '1';
		select.addEventListener('change', function () {
			var option = select.options[select.selectedIndex];
			selectCity(option ? option.getAttribute('data-id') : '', select.value);
		});
	}

	// Все селекты города: виджет + шапка.
	Array.prototype.forEach.call(selects, function (select) {
		if (window.TomSelect && !select.tomselect) {
			new TomSelect(select, {
				searchField: ['text'],
				placeholder: config.searchPlaceholder || 'Поиск города…',
				sortField: { field: '$order' },
				create: false,
				allowEmptyOption: false
			});
		}
		bindSelect(select);
	});

	// Кнопки.
	if (buttons.length) {
		Array.prototype.forEach.call(buttons, function (btn) {
			btn.addEventListener('click', function () {
				selectCity(btn.getAttribute('data-id'), btn.getAttribute('data-slug'));
			});
		});
	}

	// Защита формы от обычной отправки при включённом JS (обработку ведёт JS).
	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
		});
	}
})();
