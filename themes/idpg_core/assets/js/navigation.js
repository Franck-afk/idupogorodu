/**
 * Навигация — мобильное меню + аккордеон
 *
 * - Меню: открытие/закрытие выезжающего меню
 * - Аккордеон: плавное открытие/закрытие подменю при клике
 * - Закрытие: по бэкдропу, ссылке, Escape
 *
 * @package idpg_core
 */

(function () {
	'use strict';

	// Находим элементы
	var menuToggle = document.getElementById('idpg-menu-toggle');
	var nav = document.getElementById('idpg-nav');
	var body = document.body;

	// Если элементы не найдены — выходим
	if (!menuToggle || !nav) {
		return;
	}

	/**
	 * Открывает мобильное меню
	 */
	function openMenu() {
		menuToggle.setAttribute('aria-expanded', 'true');
		nav.classList.add('idpg-nav--open');
		body.classList.add('idpg-body-locked');
	}

	/**
	 * Закрывает мобильное меню
	 */
	function closeMenu() {
		menuToggle.setAttribute('aria-expanded', 'false');
		nav.classList.remove('idpg-nav--open');
		body.classList.remove('idpg-body-locked');

		// Закрываем все подменю
		var openItems = nav.querySelectorAll('.idpg-nav__item--open');
		for (var i = 0; i < openItems.length; i++) {
			openItems[i].classList.remove('idpg-nav__item--open');
		}
	}

	/**
	 * Переключает состояние мобильного меню
	 */
	function toggleMenu() {
		if (menuToggle.getAttribute('aria-expanded') === 'true') {
			closeMenu();
		} else {
			openMenu();
		}
	}

	/**
	 * Аккордеон — открывает/закрывает подменю
	 */
	function toggleSubmenu(event) {
		// Только для мобильных
		if (window.innerWidth >= 1024) {
			return;
		}

		var link = event.target.closest('.idpg-nav__link');
		if (!link) {
			return;
		}

		var parentItem = link.closest('.idpg-nav__item--has-children');
		if (!parentItem) {
			return;
		}

		// Проверяем, есть ли подменю
		var submenu = parentItem.querySelector('.idpg-nav__submenu');
		if (!submenu) {
			return;
		}

		// Предотвращаем переход по ссылке
		event.preventDefault();

		// Переключаем состояние
		var isOpen = parentItem.classList.contains('idpg-nav__item--open');
		parentItem.classList.toggle('idpg-nav__item--open', !isOpen);

		// Анимация: устанавливаем максимальную высоту
		if (!isOpen) {
			submenu.style.maxHeight = submenu.scrollHeight + 'px';
		} else {
			submenu.style.maxHeight = '0';
		}
	}

	// Клик по кнопке меню
	menuToggle.addEventListener('click', toggleMenu);

	// Клик по крестику закрывает меню
	var navClose = document.getElementById('idpg-nav-close');
	if (navClose) {
		navClose.addEventListener('click', closeMenu);
	}

	// Аккордеон для подменю
	nav.addEventListener('click', function (event) {
		// Находим ссылку (работает и при клике на SVG стрелку)
		var link = event.target.closest('.idpg-nav__link');
		if (!link) {
			return;
		}

		var parentItem = link.closest('.idpg-nav__item--has-children');
		if (parentItem && parentItem.querySelector('.idpg-nav__submenu') && link.parentElement === parentItem) {
			toggleSubmenu(event);
			return;
		}

		// Если кликнули по обычной ссылке — закрываем меню
		if (window.innerWidth < 1024) {
			closeMenu();
		}
	});

	// Закрытие по Escape
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && nav.classList.contains('idpg-nav--open')) {
			closeMenu();
			menuToggle.focus();
		}
	});

	// Закрытие при ресайзе
	window.addEventListener('resize', function () {
		if (window.innerWidth >= 1024 && nav.classList.contains('idpg-nav--open')) {
			closeMenu();
		}
	});

	// --- Нижний мобильный бар ---
	var bottomCity = document.getElementById('idpg-bottom-city');
	var bottomSearch = document.getElementById('idpg-bottom-search');

	if (bottomCity) {
		bottomCity.addEventListener('click', function () {
			var cityModal = document.getElementById('idpg-city-modal');
			if (cityModal) {
				cityModal.removeAttribute('hidden');
				body.classList.add('idpg-body-locked');
			}
		});
	}

	// --- Модалка города: закрытие ---
	var cityModal = document.getElementById('idpg-city-modal');
	var cityModalClose = document.getElementById('idpg-city-modal-close');
	var cityModalX = document.getElementById('idpg-city-modal-x');
	var cityModalSelect = document.getElementById('idpg-city-modal-select');

	function closeCityModal() {
		if (cityModal) {
			cityModal.setAttribute('hidden', '');
			body.classList.remove('idpg-body-locked');
		}
	}

	if (cityModalClose) {
		cityModalClose.addEventListener('click', closeCityModal);
	}
	if (cityModalX) {
		cityModalX.addEventListener('click', closeCityModal);
	}

	// Смена города в модалке: кука + редирект
	if (cityModalSelect) {
		cityModalSelect.addEventListener('change', function () {
			var option = cityModalSelect.options[cityModalSelect.selectedIndex];
			var slug = cityModalSelect.value;
			var id = option ? option.getAttribute('data-id') : '';
			if (!id || !slug) { return; }

			var redirectToCity = function () {
				var url = new URL(window.location.href);
				url.searchParams.set('city', slug);
				window.location.href = url.toString();
			};

			if (window.idpgData && window.idpgData.ajaxUrl) {
				var xhr = new XMLHttpRequest();
				xhr.open('POST', window.idpgData.ajaxUrl, true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.withCredentials = true;
				xhr.addEventListener('loadend', redirectToCity);
				xhr.send('action=idpg_set_city&city_id=' + encodeURIComponent(id) + '&nonce=' + encodeURIComponent(window.idpgData.cityNonce || ''));
			} else {
				redirectToCity();
			}
		});
	}

	if (bottomSearch) {
		bottomSearch.addEventListener('click', function () {
			var overlay = document.getElementById('idpg-search-overlay');
			if (overlay) {
				overlay.classList.add('idpg-search-overlay--open');
				var input = document.getElementById('idpg-search-overlay-input');
				if (input) { input.focus(); }
			} else {
				window.location.href = '/?s=';
			}
		});
	}

	// --- Закрытие оверлея поиска ---
	var searchOverlayEl = document.getElementById('idpg-search-overlay');
	var searchOverlayClose = document.getElementById('idpg-search-overlay-close');

	if (searchOverlayClose && searchOverlayEl) {
		searchOverlayClose.addEventListener('click', function () {
			searchOverlayEl.classList.remove('idpg-search-overlay--open');
		});
	}

	if (searchOverlayEl) {
		searchOverlayEl.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				searchOverlayEl.classList.remove('idpg-search-overlay--open');
			}
		});
	}

	// --- Dropdown авторизации ---
	var authDropdown = document.getElementById('idpg-auth-dropdown');
	var authToggle = document.getElementById('idpg-auth-toggle');

	if (authDropdown && authToggle) {
		// Открыть / закрыть по клику на кнопку
		authToggle.addEventListener('click', function (e) {
			e.stopPropagation();
			var isOpen = authDropdown.classList.toggle('idpg-auth-dropdown--open');
			authToggle.setAttribute('aria-expanded', isOpen);
		});

		// Закрыть по клику вне dropdown
		document.addEventListener('click', function (e) {
			if (!authDropdown.contains(e.target)) {
				authDropdown.classList.remove('idpg-auth-dropdown--open');
				authToggle.setAttribute('aria-expanded', 'false');
			}
		});

		// Закрыть по Escape
		authToggle.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				authDropdown.classList.remove('idpg-auth-dropdown--open');
				authToggle.setAttribute('aria-expanded', 'false');
				authToggle.focus();
			}
		});

	}

	// --- Гарантированный переход по пункту «Панель» (админка) ---
	// Глобальный перехват pointerdown (capture-фаза) по геометрическим границам
	// ссылки. Работает даже если пункт визуально перекрыт другим элементом:
	// переход выполняется если координаты клика попадают в прямоугольник ссылки.
	document.addEventListener('pointerdown', function (e) {
		var panel = document.querySelector('.idpg-auth-dropdown__link--panel');
		if (!panel || !panel.href) {
			return;
		}
		var r = panel.getBoundingClientRect();
		if (r.width === 0 || r.height === 0) {
			return;
		}
		var x = e.clientX;
		var y = e.clientY;
		if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) {
			e.preventDefault();
			e.stopPropagation();
			window.location.href = panel.href;
		}
	}, true);

	// --- Dropdown выбора города в шапке ---
	var cityDropdown = document.getElementById('idpg-city-dropdown');
	var cityToggle = document.getElementById('idpg-city-toggle');
	var cityPanel = document.getElementById('idpg-city-panel');
	var citySelect = document.getElementById('idpg-city-select');

	function openCityPanel() {
		cityDropdown.classList.add('idpg-city-selector--open');
		cityToggle.setAttribute('aria-expanded', 'true');
		cityPanel.removeAttribute('hidden');
	}

	function closeCityPanel() {
		cityDropdown.classList.remove('idpg-city-selector--open');
		cityToggle.setAttribute('aria-expanded', 'false');
		cityPanel.setAttribute('hidden', '');
	}

	if (cityDropdown && cityToggle && cityPanel) {
		cityToggle.addEventListener('click', function (e) {
			e.stopPropagation();
			if (cityDropdown.classList.contains('idpg-city-selector--open')) {
				closeCityPanel();
			} else {
				openCityPanel();
			}
		});

		// Закрыть по клику вне дропдауна
		document.addEventListener('click', function (e) {
			if (cityDropdown.classList.contains('idpg-city-selector--open') && !cityDropdown.contains(e.target)) {
				closeCityPanel();
			}
		});

		// Закрыть по Escape
		cityToggle.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeCityPanel();
				cityToggle.focus();
			}
		});

		// Смена города: кука + редирект на ?city=slug
		if (citySelect) {
			citySelect.addEventListener('change', function () {
				var option = citySelect.options[citySelect.selectedIndex];
				var slug = citySelect.value;
				var id = option ? option.getAttribute('data-id') : '';
				if (!id || !slug) {
					return;
				}

				// 1. Кука через существующий AJAX (30 дней).
				var redirectToCity = function () {
					var url = new URL(window.location.href);
					url.searchParams.set('city', slug);
					window.location.href = url.toString();
				};

				if (window.idpgData && window.idpgData.ajaxUrl) {
					var xhr = new XMLHttpRequest();
					xhr.open('POST', window.idpgData.ajaxUrl, true);
					xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
					xhr.withCredentials = true;
					// Ждём ответа, чтобы cookie успела установиться до перехода.
					xhr.addEventListener('loadend', redirectToCity);
					xhr.send('action=idpg_set_city&city_id=' + encodeURIComponent(id) + '&nonce=' + encodeURIComponent(window.idpgData.cityNonce || ''));
				} else {
					redirectToCity();
				}
			});
		}
	}
})();
