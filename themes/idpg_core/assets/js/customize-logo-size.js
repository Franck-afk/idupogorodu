/**
 * Live-preview размера логотипа в Настроителе WordPress.
 *
 * Выполняется в iframe предпросмотра после загрузки customize-preview
 * (зависимость скрипта), поэтому window.wp.customize уже определён.
 * Ползунок мгновенно меняет CSS-переменную --idpg-logo-h.
 */

( function( api ) {
	'use strict';

	if ( 'undefined' === typeof api ) {
		return;
	}

	api( 'idpg_logo_size', function( value ) {
		value.bind( function( to ) {
			var size = parseInt( to, 10 );

			if ( isNaN( size ) ) {
				return;
			}

			size = Math.min( 120, Math.max( 20, size ) );

			document.documentElement.style.setProperty( '--idpg-logo-h', size + 'px' );
		} );
	} );
} )( window.wp && window.wp.customize );
