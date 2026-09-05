/**
 * JS страницы настроек «Иду по городу» (IDPG Core).
 *
 * Экспорт / импорт / сброс настроек (вкладка «Экспорт»).
 * Табы — обычные ссылки; вкладка виджетов использует собственный
 * скрипт темы (widget-areas-admin.js).
 */

( function ( $ ) {
	'use strict';

	$( function () {

		var $exportBtn   = $( '#idpg-export-btn' ),
			$importBtn   = $( '#idpg-import-btn' ),
			$resetBtn    = $( '#idpg-reset-btn' ),
			$fileInput   = $( '#idpg-import-file' ),
			$importStatus = $( '#idpg-import-status' ),
			$resetStatus = $( '#idpg-reset-status' );

		function setStatus( $el, ok, message ) {
			$el
				.removeClass( 'success error' )
				.addClass( ok ? 'success' : 'error' )
				.text( message );
		}

		/* Экспорт */
		$exportBtn.on( 'click', function ( e ) {
			e.preventDefault();

			var data = {
				action: 'idpg_export_settings',
				nonce:  idpgSettings.nonce
			};

			$.post( idpgSettings.ajaxUrl, data )
				.done( function ( response ) {
					if ( ! response.success ) {
						setStatus( $importStatus, false, response.data.message );
						return;
					}

					var json = JSON.stringify( response.data, null, 2 );
					var blob = new Blob( [ json ], { type: 'application/json' } );
					var url  = URL.createObjectURL( blob );
					var a    = document.createElement( 'a' );
					a.href = url;
					a.download = 'idpg-core-settings-' + new Date().toISOString().slice( 0, 10 ) + '.json';
					a.click();
					URL.revokeObjectURL( url );

					setStatus( $importStatus, true, idpgSettings.i18n.exportSuccess );
				} )
				.fail( function () {
					setStatus( $importStatus, false, idpgSettings.i18n.unknownError );
				} );
		} );

		/* Импорт */
		$importBtn.on( 'click', function ( e ) {
			e.preventDefault();
			$fileInput.trigger( 'click' );
		} );

		$fileInput.on( 'change', function () {
			var file = this.files[0];

			if ( ! file ) {
				setStatus( $importStatus, false, idpgSettings.i18n.fileError );
				return;
			}

			if ( ! file.type.match( /json|text\/\// ) && file.type !== '' ) {
				setStatus( $importStatus, false, idpgSettings.i18n.invalidJson );
				return;
			}

			var reader = new FileReader();

			reader.onload = function ( event ) {
				var data = {
					action: 'idpg_import_settings',
					nonce:  idpgSettings.nonce,
					data:   event.target.result
				};

				$.post( idpgSettings.ajaxUrl, data )
					.done( function ( response ) {
						if ( ! response.success ) {
							setStatus( $importStatus, false, response.data.message );
							return;
						}

						setStatus(
							$importStatus,
							true,
							idpgSettings.i18n.importSuccess.replace( '%d', response.data.imported )
						);

						$fileInput.val( '' );
					} )
					.fail( function () {
						setStatus( $importStatus, false, idpgSettings.i18n.unknownError );
					} );
			};

			reader.onerror = function () {
				setStatus( $importStatus, false, idpgSettings.i18n.fileReadError );
			};

			reader.readAsText( file );
		} );

		/* Сброс */
		$resetBtn.on( 'click', function ( e ) {
			e.preventDefault();

			if ( ! confirm( idpgSettings.i18n.resetConfirm ) ) {
				return;
			}

			var data = {
				action: 'idpg_reset_settings',
				nonce:  idpgSettings.nonce
			};

			$.post( idpgSettings.ajaxUrl, data )
				.done( function ( response ) {
					if ( ! response.success ) {
						setStatus( $resetStatus, false, response.data.message );
						return;
					}

					setStatus( $resetStatus, true, idpgSettings.i18n.resetSuccess );

					/* Reset через перезагрузку страницы, чтобы очистить кэши */
					setTimeout( function () {
						location.reload();
					}, 1500 );
				} )
				.fail( function () {
					setStatus( $resetStatus, false, idpgSettings.i18n.unknownError );
				} );
		} );
	} );
} )( jQuery );
