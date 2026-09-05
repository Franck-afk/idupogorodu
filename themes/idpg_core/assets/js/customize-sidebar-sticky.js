/**
 * Live-preview переключателя «Липкий сайдбар» в Настроителе WordPress.
 *
 * Выполняется в iframe предпросмотра после загрузки customize-preview
 * (зависимость скрипта), поэтому window.wp.customize уже определён.
 * Тогглит класс .idpg-sidebar-scroll на <html> мгновенно — CSS темы
 * переводит .idpg-home__sidebar в position: static.
 */

( function( api ) {
	'use strict';

	if ( 'undefined' === typeof api ) {
		return;
	}

	api( 'idpg_sidebar_sticky', function( value ) {
		value.bind( function( to ) {
			var enabled = ( '1' === String( to ) || true === to );

			document.documentElement.classList.toggle( 'idpg-sidebar-scroll', ! enabled );
		} );
	} );
} )( window.wp && window.wp.customize );
