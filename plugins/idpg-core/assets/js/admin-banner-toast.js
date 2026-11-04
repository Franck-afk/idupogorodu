/**
 * Тосты стандартных уведомлений админки WordPress.
 *
 * PHP выводит обычные notice (fallback без JS), скрипт переносит их
 * содержимое в Toastify и показывает справа снизу. Сложные notice
 * с формами и полями ввода остаются на месте, чтобы не ломать действия.
 */
( function () {
	'use strict';

	var NOTICE_SELECTOR = '.notice, .update-nag, #message.updated, #message.error';
	var BANNER_CLASS = 'idpg-banner-saved-notice';

	/**
	 * Тип тоста по классам notice.
	 *
	 * @param {Element} notice Элемент уведомления.
	 * @return {string} success|error|warning|info.
	 */
	function getType( notice ) {
		if ( notice.classList.contains( 'notice-error' ) || notice.classList.contains( 'error' ) ) {
			return 'error';
		}

		if ( notice.classList.contains( 'notice-warning' ) ) {
			return 'warning';
		}

		if ( notice.classList.contains( 'notice-info' ) ) {
			return 'info';
		}

		return 'success';
	}

	/**
	 * Есть ли внутри notice интерактивные элементы.
	 *
	 * Кнопку закрытия не считаем: её можно отбросить при переносе.
	 *
	 * @param {Element} notice Элемент уведомления.
	 * @return {boolean} true — notice лучше оставить на месте.
	 */
	function isInteractive( notice ) {
		if ( notice.querySelector( 'form, select, textarea, input:not([type="hidden"])' ) ) {
			return true;
		}

		var buttons = notice.querySelectorAll( 'button' );

		for ( var i = 0; i < buttons.length; i++ ) {
			if ( ! buttons[ i ].classList.contains( 'notice-dismiss' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Собрать содержимое notice для тоста.
	 *
	 * Клонируем, чтобы сохранить ссылки и разметку. ID и WP-классы
	 * убираем — иначе в документе появятся дубли ID и вложенные notice.
	 *
	 * @param {Element} notice Элемент уведомления.
	 * @return {Element} Контейнер с содержимым.
	 */
	function buildContent( notice ) {
		var content = document.createElement( 'div' );
		content.className = 'idpg-admin-toast__content';

		Array.prototype.forEach.call( notice.childNodes, function ( child ) {
			if ( 1 === child.nodeType && child.classList.contains( 'notice-dismiss' ) ) {
				return;
			}

			content.appendChild( child.cloneNode( true ) );
		} );

		Array.prototype.forEach.call(
			content.querySelectorAll( '[id], .notice, .update-nag, .updated, .error' ),
			function ( element ) {
				element.removeAttribute( 'id' );
				element.classList.remove(
					'notice',
					'notice-success',
					'notice-error',
					'notice-warning',
					'notice-info',
					'updated',
					'error',
					'update-nag',
					'is-dismissible'
				);
			}
		);

		// Кнопки закрытия в тосте не нужны: у Toastify своя.
		Array.prototype.forEach.call( content.querySelectorAll( '.notice-dismiss' ), function ( button ) {
			button.parentNode.removeChild( button );
		} );

		return content;
	}

	/**
	 * Превратить notice в тост.
	 *
	 * @param {Element} notice Элемент уведомления.
	 * @return {void}
	 */
	function convert( notice ) {
		if ( ! notice.parentNode || notice.closest( '.toastify' ) ) {
			return;
		}

		var isBanner = notice.classList.contains( BANNER_CLASS );

		if ( isBanner ) {
			notice.removeAttribute( 'hidden' );
		}

		if ( isInteractive( notice ) || ( ! isBanner && ! notice.getClientRects().length ) ) {
			return;
		}

		var content = buildContent( notice );
		var text = ( content.textContent || '' ).replace( /\s+/g, ' ' ).trim();

		if ( ! text ) {
			return;
		}

		var type = getType( notice );

		try {
			window.Toastify( {
				node: content,
				duration: 'error' === type ? 10000 : ( 'warning' === type ? 7000 : 5000 ),
				gravity: 'bottom',
				position: 'right',
				close: true,
				stopOnFocus: true,
				ariaLive: 'error' === type ? 'assertive' : 'polite',
				className: 'idpg-admin-toast idpg-admin-toast--' + type
			} ).showToast();
		} catch ( error ) {
			return;
		}

		notice.parentNode.removeChild( notice );
	}

	/**
	 * Превратить все notice внутри контейнера.
	 *
	 * @param {Element|Document} root Контейнер.
	 * @return {void}
	 */
	function convertAll( root ) {
		if ( ! root.querySelectorAll ) {
			return;
		}

		Array.prototype.forEach.call( root.querySelectorAll( NOTICE_SELECTOR ), convert );
	}

	/**
	 * Точка входа: первичный проход и наблюдение за AJAX-уведомлениями.
	 *
	 * @return {void}
	 */
	function init() {
		// Toastify не загрузился — оставляем обычные notice видимыми.
		if ( 'function' !== typeof window.Toastify ) {
			Array.prototype.forEach.call(
				document.querySelectorAll( '.' + BANNER_CLASS + '[hidden]' ),
				function ( notice ) {
					notice.removeAttribute( 'hidden' );
				}
			);
			return;
		}

		convertAll( document );

		if ( 'undefined' === typeof window.MutationObserver ) {
			return;
		}

		var observer = new window.MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				Array.prototype.forEach.call( mutation.addedNodes, function ( node ) {
					if ( 1 !== node.nodeType || ( node.closest && node.closest( '.toastify' ) ) ) {
						return;
					}

					if ( node.matches && node.matches( NOTICE_SELECTOR ) ) {
						convert( node );
						return;
					}

					convertAll( node );
				} );
			} );
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
