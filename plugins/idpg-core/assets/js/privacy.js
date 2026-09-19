/**
 * Cookie-баннер: сохранение выбора без перезагрузки страницы.
 *
 * Без JavaScript форма баннера отправляется обычным POST — тот же выбор
 * обрабатывает IDPG_Privacy::handle_banner_submit() на стороне сервера.
 * Скрипт лишь избавляет от перезагрузки, ставя ту же cookie.
 */
( function () {
	'use strict';

	var config = window.idpgPrivacy || {};
	var banner = document.getElementById( config.bannerId || 'idpg-cookie-banner' );

	if ( ! banner ) {
		return;
	}

	var cookieName = config.cookieName || 'idpg_cookie_consent';
	var cookieTtl = parseInt( config.cookieTtl, 10 ) || 31536000;

	/**
	 * Ставит cookie выбора с теми же атрибутами, что и серверная ветка.
	 *
	 * @param {string} choice Значение выбора.
	 */
	function setChoiceCookie( choice ) {
		var cookie = cookieName + '=' + encodeURIComponent( choice ) +
			'; path=/; max-age=' + cookieTtl + '; SameSite=Lax';

		if ( 'https:' === window.location.protocol ) {
			cookie += '; secure';
		}

		document.cookie = cookie;
	}

	banner.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[name="idpg_cookie_choice"]' ) : null;

		if ( ! button ) {
			return;
		}

		var choice = button.value;

		if ( 'all' !== choice && 'necessary' !== choice ) {
			return;
		}

		// Значение валидно — дальше обрабатываем сами, без отправки формы.
		event.preventDefault();

		setChoiceCookie( choice );

		if ( banner.parentNode ) {
			banner.parentNode.removeChild( banner );
		}

		document.dispatchEvent( new CustomEvent( 'idpg:cookie-consent', { detail: { choice: choice } } ) );
	} );
} )();
