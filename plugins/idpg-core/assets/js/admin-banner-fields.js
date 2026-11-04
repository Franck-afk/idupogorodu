/**
 * Поле даты блока «Баннер на главной» (вариант A, DEV-317).
 *
 * Ручной ввод формата ГГГГ-ММ-ДД без зависимости от JavaScript: поле
 * не readonly и несёт name/value, поэтому форму можно отправить без скриптов.
 * JS-обработчик валидирует ввод: допустимы только ГГГГ-ММ-ДД, при вводе
 * старого вида ДД.ММ.ГГГД поле конвертирует в ГГГГ-ММ-ДД; на пустое
 * поле очищает; невалидный текст откатывает к сохранённому значению.
 *
 * Плюс календарь Pikaday: клик по полю открывает календарь, выбранный день
 * подставляется в том же формате ГГГГ-ММ-ДД. Ручной ввод при этом остаётся
 * рабочим (календарь не заменяет поле и не делает его readonly).
 */
( function () {
	'use strict';

	/**
	 * Преобразовать ДД.ММ.ГГГД в ГГГГ-ММ-ДД.
	 *
	 * @param {string} raw
	 * @return {string}
	 */
	function toIsoFromDisplay( raw ) {
		var m = raw.match( /^(\d{2})\.(\d{2})\.(\d{4})$/ );
		if ( m ) {
			return m[ 3 ] + '-' + m[ 2 ] + '-' + m[ 1 ];
		}
		return raw;
	}

	/**
	 * Преобразовать объект Date в ГГГГ-ММ-ДД.
	 *
	 * @param {Date} date
	 * @return {string}
	 */
	function toIsoFromDate( date ) {
		var mm = '0' + ( date.getMonth() + 1 );
		var dd = '0' + date.getDate();
		return date.getFullYear() + '-' + mm.slice( -2 ) + '-' + dd.slice( -2 );
	}

	/**
	 * Инициализация календаря Pikaday на поле даты.
	 *
	 * Библиотека приходит хендлом `idpg-banner-pikaday` (плагин, vendor).
	 * Если её нет — поле остаётся обычным текстовым вводом (вариант A, DEV-317).
	 *
	 * @param {HTMLInputElement} input
	 * @return {void}
	 */
	function initPikaday( input ) {
		if ( typeof Pikaday === 'undefined' ) {
			return;
		}

		new Pikaday( {
			field: input,
			format: 'YYYY-MM-DD',
			firstDay: 1,

			/*
			 * Без moment.js в Pikaday по умолчанию берутся Date.parse()
			 * и toDateString() — задаём строгий формат хранилища
			 * ГГГГ-ММ-ДД, чтобы в настройки не попадала «Mon Dec 01 2026».
			 */
			parse: function ( value ) {
				var parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( value ).trim() );
				return parts ? new Date( +parts[1], +parts[2] - 1, +parts[3] ) : null;
			},
			toString: toIsoFromDate,

			// Русская локаль — своя копия, чтобы админка не зависела от темы.
			i18n: {
				months: [ 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь' ],
				monthsShort: [ 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек' ],
				weekdays: [ 'Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота' ],
				weekdaysShort: [ 'Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб' ],
				previousMonth: 'Предыдущий месяц',
				nextMonth: 'Следующий месяц',
				today: 'Сегодня',
				clear: 'Очистить',
				close: 'Закрыть'
			}
		} );
	}

	function init() {
		document.querySelectorAll( '.idpg-datepicker' ).forEach( function ( input ) {
			var clearBtn = input.parentNode.querySelector( '.idpg-datepicker-clear' );
			var originalValue = input.value;

			// Валидируем ручной ввод: допустимы только ГГГГ-ММ-ДД, плюс
			// при вводе старого вида ДД.ММ.ГГГД поле конвертирует в ГГГГ-ММ-ДД.
			input.addEventListener( 'change', function () {
				var raw = input.value.trim();

				if ( raw === '' ) {
					input.value = '';
					return;
				}

				if ( /^\d{2}\.\d{2}\.\d{4}$/.test( raw ) ) {
					input.value = toIsoFromDisplay( raw );
					return;
				}

				if ( ! /^\d{4}-\d{2}-\d{2}$/.test( raw ) ) {
					input.value = originalValue;
				}
			} );

			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					input.value = '';
				} );
			}

			// Календарь — после проверки ввода: наш обработчик change
			// срабатывает раньше внутреннего обработчика Pikaday.
			initPikaday( input );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
