/**
 * Поле даты блока «Баннер на главной» (вариант A, DEV-317).
 *
 * Ручной ввод формата ГГГГ-ММ-ДД без зависимости от JavaScript: поле
 * не readonly и несёт name/value, поэтому форму можно отправить без скриптов.
 * JS-обработчик валидирует ввод: допустимы только ГГГГ-ММ-ДД, при вводе
 * старого вида ДД.ММ.ГГГД поле конвертирует в ГГГГ-ММ-ДД; на пустое
 * поле очищает; невалидный текст откатывает к сохранённому значению.
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
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
