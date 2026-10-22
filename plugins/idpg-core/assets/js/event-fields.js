/**
 * IDPG Event Fields — скрипты нативных полей события.
 *
 * 1. Сеансы: добавление/удаление повторяемых строк.
 * 2. Галерея: выбор изображений через wp.media.
 * 3. Условная логика: показ/скрытие полей по значению селекта.
 * 4. Даты: кастомный календарь Pikaday вместо нативного type="date".
 *
 * @package IDPG_Core
 */

( function( $ ) {
	'use strict';

	// =========================================================================
	// Сеансы: добавление/удаление строк
	// =========================================================================

	function initSessions() {
		$( document ).on( 'click', '.idpg-sessions__add', function( e ) {
			e.preventDefault();

			var $rows = $( this ).closest( '.idpg-sessions' ).find( '.idpg-sessions__rows' );
			var index = $rows.children().length;

			var $row = $( '<div class="idpg-sessions__row" data-index="' + index + '">' +
				'<input type="text" name="idpg_sessions_rows[' + index + '][time]" placeholder="19:00" class="small-text" />' +
				'<input type="number" name="idpg_sessions_rows[' + index + '][price]" placeholder="Цена" min="0" step="0.01" class="small-text" />' +
				'<input type="url" name="idpg_sessions_rows[' + index + '][url]" placeholder="https://" class="regular-text" />' +
				'<button type="button" class="button idpg-sessions__remove" title="Удалить">&times;</button>' +
				'</div>' );

			$rows.append( $row );
			$row.find( 'input:first' ).focus();
		} );

		$( document ).on( 'click', '.idpg-sessions__remove', function( e ) {
			e.preventDefault();
			$( this ).closest( '.idpg-sessions__row' ).remove();
		} );
	}

	// =========================================================================
	// Галерея: выбор изображений через wp.media
	// =========================================================================

	function initGallery() {
		$( document ).on( 'click', '.idpg-gallery__add', function( e ) {
			e.preventDefault();

			var $container = $( this ).closest( '.idpg-gallery' );
			var $input     = $container.find( 'input[name="idpg_gallery_ids"]' );
			var $thumbs    = $container.find( '.idpg-gallery__thumbnails' );

			// Текущие ID (массив).
			var current = $input.val() ? $input.val().split( ',' ).map( function( v ) {
				return parseInt( v, 10 );
			} ).filter( function( v ) {
				return v > 0;
			} ) : [];

			var frame = wp.media( {
				title: 'Выберите изображения',
				button: { text: 'Добавить' },
				multiple: true,
				library: { type: 'image' }
			} );

			frame.on( 'select', function() {
				var attachments = frame.state().get( 'selection' ).toJSON();

				attachments.forEach( function( att ) {
					if ( current.indexOf( att.id ) === -1 ) {
						current.push( att.id );

						var thumbUrl = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;

						var $item = $( '<span class="idpg-gallery__item" data-id="' + att.id + '">' +
							'<img src="' + thumbUrl + '" alt="" />' +
							'<button type="button" class="idpg-gallery__remove" title="Удалить">&times;</button>' +
							'</span>' );

						$thumbs.append( $item );
					}
				} );

				$input.val( current.join( ',' ) );
			} );

			frame.open();
		} );

		$( document ).on( 'click', '.idpg-gallery__remove', function( e ) {
			e.preventDefault();

			var $item     = $( this ).closest( '.idpg-gallery__item' );
			var $container = $item.closest( '.idpg-gallery' );
			var $input    = $container.find( 'input[name="idpg_gallery_ids"]' );
			var id        = $item.data( 'id' );

			var current = $input.val() ? $input.val().split( ',' ).map( function( v ) {
				return parseInt( v, 10 );
			} ).filter( function( v ) {
				return v > 0 && v !== id;
			} ) : [];

			$input.val( current.join( ',' ) );
			$item.remove();
		} );
	}

	// =========================================================================
	// Условная логика: показ/скрытие полей
	// =========================================================================

	function initConditional() {
		function updateConditional( $scope ) {
			$scope.find( '[data-conditional-field]' ).each( function() {
				var $field   = $( this );
				var fieldName = $field.data( 'conditional-field' );
				var expected = $field.data( 'conditional-value' );
				var $trigger = $scope.find( '[name="' + fieldName + '"]' );

				if ( ! $trigger.length ) {
					return;
				}

				var current = $trigger.is( ':checkbox' )
					? ( $trigger.is( ':checked' ) ? 'yes' : '' )
					: $trigger.val();

				if ( current === expected ) {
					$field.show();
				} else {
					$field.hide();
				}
			} );
		}

		// При загрузке.
		$( '.idpg-event-fields, .idpg-event-fields-form' ).each( function() {
			updateConditional( $( this ) );
		} );

		// При изменении селектов.
		$( document ).on( 'change', '.idpg-event-fields select, .idpg-event-fields-form select', function() {
			updateConditional( $( this ).closest( '.idpg-event-fields, .idpg-event-fields-form' ) );
		} );
	}

	// =========================================================================
	// Площадка: часы работы и соцсети (повторяемые строки)
	// =========================================================================

	function initVenueHours() {
		$( document ).on( 'click', '.idpg-venue-hours__add', function( e ) {
			e.preventDefault();

			var $rows = $( this ).closest( '.idpg-venue-hours' ).find( '.idpg-venue-hours__rows' );
			var index = $rows.children().length;

			var $row = $( '<div class="idpg-venue-hours__row" data-index="' + index + '">' +
				'<select name="idpg_venue_hours_rows[' + index + '][day]">' +
				'<option value="">— День —</option>' +
				'<option value="mon">Понедельник</option>' +
				'<option value="tue">Вторник</option>' +
				'<option value="wed">Среда</option>' +
				'<option value="thu">Четверг</option>' +
				'<option value="fri">Пятница</option>' +
				'<option value="sat">Суббота</option>' +
				'<option value="sun">Воскресенье</option>' +
				'</select> ' +
				'<input type="time" name="idpg_venue_hours_rows[' + index + '][open]" /> ' +
				'<input type="time" name="idpg_venue_hours_rows[' + index + '][close]" /> ' +
				'<label><input type="checkbox" name="idpg_venue_hours_rows[' + index + '][is_closed]" value="1" /> Выходной</label> ' +
				'<button type="button" class="button idpg-venue-hours__remove" title="Удалить">&times;</button>' +
				'</div>' );

			$rows.append( $row );
		} );

		$( document ).on( 'click', '.idpg-venue-hours__remove', function( e ) {
			e.preventDefault();
			$( this ).closest( '.idpg-venue-hours__row' ).remove();
		} );
	}

	function initVenueSocial() {
		$( document ).on( 'click', '.idpg-venue-social__add', function( e ) {
			e.preventDefault();

			var $rows = $( this ).closest( '.idpg-venue-social' ).find( '.idpg-venue-social__rows' );
			var index = $rows.children().length;

			var $row = $( '<div class="idpg-venue-social__row" data-index="' + index + '">' +
				'<select name="idpg_venue_social_rows[' + index + '][network]">' +
				'<option value="">— Сеть —</option>' +
				'<option value="vk">VK</option>' +
				'<option value="tg">Telegram</option>' +
				'<option value="wa">WhatsApp</option>' +
				'<option value="instagram">Instagram</option>' +
				'<option value="youtube">YouTube</option>' +
				'<option value="ok">Одноклассники</option>' +
				'<option value="other">Другое</option>' +
				'</select> ' +
				'<input type="url" name="idpg_venue_social_rows[' + index + '][url]" placeholder="https://" class="regular-text" /> ' +
				'<button type="button" class="button idpg-venue-social__remove" title="Удалить">&times;</button>' +
				'</div>' );

			$rows.append( $row );
		} );

		$( document ).on( 'click', '.idpg-venue-social__remove', function( e ) {
			e.preventDefault();
			$( this ).closest( '.idpg-venue-social__row' ).remove();
		} );
	}

	// =========================================================================
	// Даты: кастомный календарь Pikaday вместо нативного type="date"
	// =========================================================================

	function initDatePickers() {
		if ( typeof Pikaday === 'undefined' ) {
			return;
		}

		$( '.idpg-pikaday' ).each( function() {
			var opts = {
				field: this,
				format: 'YYYY-MM-DD',
				firstDay: 1,

				/*
				 * Без moment.js в Pikaday по умолчанию берутся Date.parse()
				 * и toDateString() — задаём строгий формат хранилища
				 * ГГГГ-ММ-ДД, чтобы в мета не попадала «Fri May 01 2026».
				 */
				parse: function( value ) {
					var parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( value ).trim() );
					return parts ? new Date( +parts[1], +parts[2] - 1, +parts[3] ) : null;
				},
				toString: function( date ) {
					var mm = '0' + ( date.getMonth() + 1 );
					var dd = '0' + date.getDate();
					return date.getFullYear() + '-' + mm.slice( -2 ) + '-' + dd.slice( -2 );
				}
			};

			// Русская локаль из pikaday-locale-ru.js (глобальный l10n).
			if ( typeof l10n !== 'undefined' ) {
				opts.i18n = l10n;
			}

			new Pikaday( opts );
		} );
	}

	// =========================================================================
	// Инициализация
	// =========================================================================

	$( function() {
		initSessions();
		initGallery();
		initConditional();
		initVenueHours();
		initVenueSocial();
		initDatePickers();
	} );

} )( jQuery );
