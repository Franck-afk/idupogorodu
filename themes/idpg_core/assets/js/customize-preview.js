/**
 * Live-preview ширины логотипа в Настроителе WordPress (кастомайзер).
 *
 * Выполняется в iframe предпросмотра после загрузки customize-preview
 * (зависимость скрипта), поэтому window.wp.customize уже определён.
 *
 * Изображение выбирается базовым контролом «Логотип» (настройка custom_logo,
 * the_custom_logo()). Один ползунок (idpg_logo_width) мгновенно меняет
 * CSS-переменную --idpg-logo-w и атрибут width у img — без участия сервера
 * (транспорт postMessage, ширина в partial не включена, чтобы серверный рендер
 * не отбивал drag-значение). Высота — auto, пропорции сохраняются.
 * Смена изображения перерисовывает блок через partial refresh
 * (callback idpg_render_logo), после которого ширина применяется заново.
 */

( function( api ) {
	'use strict';

	if ( 'undefined' === typeof api ) {
		return;
	}

	/**
	 * Применяет текущее значение ширины логотипа.
	 */
	function idpgApplyLogoWidth() {
		var $logo = jQuery( '.idpg-logo' );

		if ( ! $logo.length ) {
			return;
		}

		var width = parseInt( api( 'idpg_logo_width' ).get(), 10 ) || 0;
		width = Math.max( 50, Math.min( 400, width ) );

		$logo.css( '--idpg-logo-w', width + 'px' );
		$logo.find( 'img' ).attr( 'width', width ).attr( 'height', 'auto' );
	}

	// Ширина: обновляем в реальном времени.
	api( 'idpg_logo_width', function( value ) {
		value.bind( idpgApplyLogoWidth );
	} );

	// После перерисовки partial (смена изображения) ширину применяем заново.
	api.selectiveRefresh.bind( 'partial-content-rendered', function( partial ) {
		if ( partial && '.idpg-header__logo' === partial.params.selector ) {
			idpgApplyLogoWidth();
		}
	} );
} )( window.wp && window.wp.customize );