/**
 * Скрипты страницы настроек «Режим разработки» (IDPG Core).
 *
 * Медиа-загрузчик изображения фона заглушки, список соцсетей с иконками.
 *
 * @package IDPG_Core
 */

( function ( $ ) {
	'use strict';

	/**
	 * Перенумерация полей строк соцсетей после добавления/удаления.
	 *
	 * @param {jQuery} $list Контейнер списка.
	 */
	function refreshSocialIndexes( $list ) {
		$list.find( '.idpg-social__row' ).each( function ( index ) {
			var $row = $( this );
			$row.find( '.idpg-social__url' ).attr( 'name', 'idpg_maintenance_social_items[' + index + '][url]' );
			$row.find( '.idpg-social__icon' ).attr( 'name', 'idpg_maintenance_social_items[' + index + '][icon]' );
		} );
	}

	/**
	 * Обновление превью иконки по выбранному slug (Iconify).
	 *
	 * @param {jQuery} $row Строка соцсети.
	 */
	function updateSocialPreview( $row ) {
		var slug    = $row.find( '.idpg-social__icon' ).val();
		var $target = $row.find( '.idpg-social__preview' );

		$target.empty();
		if ( ! slug || ! window.Iconify ) {
			return;
		}

		var html = window.Iconify.renderHTML( 'idpg:' + slug );
		if ( html ) {
			$target.html( html );
		}
	}

	/**
	 * Инициализация списка соцсетей.
	 *
	 * @param {HTMLElement} container Контейнер [data-social-list].
	 */
	function initSocial( container ) {
		var $list     = $( container );
		var $template = $( '#idpg-social-row-template' );

		if ( ! $list.length ) {
			return;
		}

		$list.on( 'change', '.idpg-social__icon', function () {
			updateSocialPreview( $( this ).closest( '.idpg-social__row' ) );
		} );

		$list.on( 'click', '.idpg-social__remove', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.idpg-social__row' ).remove();
			refreshSocialIndexes( $list );
		} );

		$list.siblings( '.idpg-social__add' ).on( 'click', function ( e ) {
			e.preventDefault();
			$list.append( $template.html() );
			refreshSocialIndexes( $list );
		} );
	}

	$( document ).ready( function () {
		$( '.idpg-color-picker' ).wpColorPicker();

		$( '[data-social-list]' ).each( function () {
			var $list = $( this );
			initSocial( this );
			$list.find( '.idpg-social__row' ).each( function () {
				updateSocialPreview( $( this ) );
			} );
		} );
	} );
} )( jQuery );
