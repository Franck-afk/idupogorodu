/**
 * Карусель баннера подтверждённых (оплаченных) событий на главной.
 *
 * Ротация по времени (интервал из data-interval), пауза на наведении,
 * переключение по точкам и свайпом на мобильных. Без внешних библиотек.
 *
 * @package idpg-core
 */
(function (window, document) {
	'use strict';

	function idpgBannerCarouselInit(carousel) {
		var viewport = carousel.querySelector('.idpg-current-event-banner-carousel__viewport');
		var slides = carousel.querySelectorAll('.idpg-current-event-banner-carousel__slide');
		var dots = carousel.querySelectorAll('.idpg-current-event-banner-carousel__dot');
		var interval = parseInt(carousel.getAttribute('data-interval') || '6000', 10);
		// Авто-ротация из настроек вкладки «Баннер на главной»:
		// data-autorotate="0" — таймер не запускается вовсе,
		// переключение остаётся ручным (точки, свайп).
		var autoRotate = carousel.getAttribute('data-autorotate') !== '0';
		var current = 0;
		var timer = null;
		var startX = 0;
		var activeSlide = 0;

		if (!viewport || slides.length < 2) {
			return;
		}

		function goTo(index, withTimer) {
			activeSlide = (index + slides.length) % slides.length;

			slides.forEach(function (slide, i) {
				slide.classList.toggle('is-active', i === activeSlide);
			});

			if (dots.length) {
				dots.forEach(function (dot, i) {
					dot.classList.toggle('is-active', i === activeSlide);
				});
			}

			if (withTimer !== false) {
				restartTimer();
			}
		}

		function next() {
			goTo(activeSlide + 1);
		}

		function restartTimer() {
			if (timer) {
				window.clearInterval(timer);
				timer = null;
			}
			if (!autoRotate) {
				return;
			}
			timer = window.setInterval(next, interval);
		}

		function stopTimer() {
			if (timer) {
				window.clearInterval(timer);
				timer = null;
			}
		}

		dots.forEach(function (dot, i) {
			dot.addEventListener('click', function () {
				goTo(i);
			});
		});

		carousel.addEventListener('mouseenter', stopTimer);
		carousel.addEventListener('mouseleave', restartTimer);

		// Swipe: пожатия мыши/пальца для смены слайдов на мобильных.
		viewport.addEventListener('touchstart', function (e) {
			startX = e.changedTouches[0].clientX;
		}, { passive: true });

		viewport.addEventListener('touchend', function (e) {
			var diff = e.changedTouches[0].clientX - startX;
			if (Math.abs(diff) > 40) {
				goTo(diff < 0 ? activeSlide + 1 : activeSlide - 1);
			}
		}, { passive: true });

		// Стартуем с первого слайда и запускаем ротацию.
		goTo(0, false);
		restartTimer();
	}

	function idpgBannerCarouselInitAll() {
		document.querySelectorAll('[data-idpg-banner-carousel]').forEach(idpgBannerCarouselInit);
	}

	// Инициализация после DOM и в конце загрузки (для позднего рендера).
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', idpgBannerCarouselInitAll);
	} else {
		idpgBannerCarouselInitAll();
	}
})(window, document);