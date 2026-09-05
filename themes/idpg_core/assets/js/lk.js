/**
 * Скрипт личного кабинета пользователя (page-lk.php).
 *
 * Работает с конфигом window.idpgLk, который передаётся
 * через wp_localize_script (см. functions.php).
 */
(function () {
	'use strict';

	var editBtn = document.getElementById( 'lk-edit-profile-btn' );
	var cancelBtn = document.getElementById( 'lk-cancel-edit-btn' );
	var formEl = document.getElementById( 'lk-edit-form' );
	var profileEl = document.querySelector( '.lk-profile' );
	var messagesEl = document.getElementById( 'lk-form-messages' );
	var form = document.getElementById( 'lk-profile-form' );
	var avatarInput = document.getElementById( 'lk-avatar' );
	var avatarPreview = document.getElementById( 'lk-avatar-preview' );
	var removeAvatarBtn = document.getElementById( 'lk-remove-avatar-btn' );
	var profileAvatarEl = document.querySelector( '.lk-profile__avatar' );
	var config = window.idpgLk || {};
	var restUrl = config.restUrl || ( window.location.origin + '/wp-json/idpg/v1' );
	var nonce = config.nonce || '';

	if ( ! editBtn || ! formEl ) {
		return;
	}

	function showMessage( text, type ) {
		if ( ! messagesEl ) {
			return;
		}
		messagesEl.className = 'lk-form__messages lk-form__messages--' + type;
		messagesEl.removeAttribute( 'hidden' );
		var msg = messagesEl.querySelector( '.lk-form__message' );
		if ( msg ) {
			msg.textContent = text;
		}
	}

	function hideMessage() {
		if ( ! messagesEl ) {
			return;
		}
		messagesEl.setAttribute( 'hidden', '' );
	}

	function toggleEditMode( active ) {
		if ( active ) {
			formEl.style.display = 'block';
			if ( profileEl ) {
				profileEl.style.display = 'none';
			}
			editBtn.style.display = 'none';
		} else {
			formEl.style.display = 'none';
			if ( profileEl ) {
				profileEl.style.display = 'flex';
			}
			editBtn.style.display = 'inline-block';
			hideMessage();
		}
	}

	editBtn.addEventListener( 'click', function () {
		toggleEditMode( true );
	} );

	if ( cancelBtn ) {
		cancelBtn.addEventListener( 'click', function () {
			toggleEditMode( false );
		} );
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			hideMessage();

			var submitBtn = form.querySelector( '.idpg-btn--primary' );
			var displayName = form.querySelector( '[name="display_name"]' ).value.trim();
			var email = form.querySelector( '[name="email"]' ).value.trim();
			var currentPassword = form.querySelector( '[name="current_password"]' ).value;
			var newPassword = form.querySelector( '[name="new_password"]' ).value;
			var passwordConfirm = form.querySelector( '[name="password_confirm"]' ).value;
			var avatarFile = avatarInput ? avatarInput.files[0] : null;

			if ( ! displayName ) {
				showMessage( 'Введите имя.', 'error' );
				return;
			}

			if ( ! email || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
				showMessage( 'Введите корректный email.', 'error' );
				return;
			}

			if ( newPassword && newPassword.length < 8 ) {
				showMessage( 'Пароль должен быть минимум 8 символов.', 'error' );
				return;
			}

			if ( newPassword && newPassword !== passwordConfirm ) {
				showMessage( 'Пароли не совпадают.', 'error' );
				return;
			}

			if ( submitBtn ) {
				submitBtn.disabled = true;
				submitBtn.classList.add( 'idpg-btn--loading' );
			}

			var baseUrl = restUrl ? restUrl.replace( /\/+$/, '' ) : '';
			var url = baseUrl + '/user/profile';

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', url, true );
			xhr.setRequestHeader( 'X-WP-Nonce', nonce );
			xhr.withCredentials = true;

			if ( avatarFile ) {
				var formData = new FormData();
				formData.append( 'display_name', displayName );
				formData.append( 'email', email );
				if ( newPassword ) {
					formData.append( 'new_password', newPassword );
					formData.append( 'current_password', currentPassword );
				}
				formData.append( 'file', avatarFile );

				xhr.send( formData );
			} else {
				xhr.setRequestHeader( 'Content-Type', 'application/json' );

				var data = {
					display_name: displayName,
					email: email,
				};

				if ( newPassword ) {
					data.new_password = newPassword;
					data.current_password = currentPassword;
				}

				xhr.send( JSON.stringify( data ) );
			}

			xhr.onload = function () {
				var response = {};

				try {
					response = JSON.parse( xhr.responseText );
				} catch ( e ) {
					response = {};
				}

				if ( xhr.status >= 200 && xhr.status < 300 ) {
					if ( response.success ) {
						showMessage( config.l10n ? config.l10n.saveSuccess : 'Профиль сохранён.', 'success' );

						var nameEl = document.querySelector( '.lk-profile__name' );
						var emailEl = document.querySelector( '.lk-profile__email' );
						if ( nameEl ) {
							nameEl.textContent = displayName;
						}
						if ( emailEl ) {
							emailEl.textContent = email;
						}

					if ( response.avatar_url && avatarPreview ) {
						var img1 = document.createElement( 'img' );
						img1.src = response.avatar_url;
						img1.alt = 'Avatar';
						img1.style.cssText = 'width:80px;height:80px;border-radius:50%;';
						avatarPreview.textContent = '';
						avatarPreview.appendChild( img1 );
					}
					if ( response.avatar_url && profileAvatarEl ) {
						var img2 = document.createElement( 'img' );
						img2.src = response.avatar_url;
						img2.alt = 'Avatar';
						img2.style.cssText = 'width:96px;height:96px;border-radius:50%;';
						profileAvatarEl.textContent = '';
						profileAvatarEl.appendChild( img2 );
					}

						setTimeout( function () {
							toggleEditMode( false );
						}, 1500 );
					} else {
						var msg = response.message || ( config.l10n ? config.l10n.saveError : 'Ошибка при сохранении.' );
						showMessage( msg, 'error' );
					}
				} else {
					var errorMsg = response.message || ( response.data && response.data.message ) || 'Ошибка сервера. Попробуйте позже.';
					showMessage( errorMsg, 'error' );
				}

				if ( submitBtn ) {
					submitBtn.disabled = false;
					submitBtn.classList.remove( 'idpg-btn--loading' );
				}
			};

			xhr.onerror = function () {
				showMessage( 'Ошибка соединения. Попробуйте позже.', 'error' );

				if ( submitBtn ) {
					submitBtn.disabled = false;
					submitBtn.classList.remove( 'idpg-btn--loading' );
				}
			};
		} );
	}

	if ( avatarInput && avatarInput.addEventListener ) {
		avatarInput.addEventListener( 'change', function () {
			if ( avatarInput.files && avatarInput.files.length > 0 ) {
				var file = avatarInput.files[0];
				if ( file.size > 5 * 1024 * 1024 ) {
					showMessage( 'Размер файла не должен превышать 5 МБ.', 'error' );
					avatarInput.value = '';
					return;
				}
				var reader = new FileReader();
				reader.onload = function ( e ) {
					if ( avatarPreview ) {
						avatarPreview.innerHTML = '<img src="' + e.target.result + '" alt="Preview" style="width:80px;height:80px;border-radius:50%;">';
					}
				};
				reader.readAsDataURL( file );
			}
		} );
	}

	if ( removeAvatarBtn ) {
		removeAvatarBtn.addEventListener( 'click', function () {
			var baseUrl2 = restUrl ? restUrl.replace( /\/+$/, '' ) : '';
			var url2 = baseUrl2 + '/user/profile';

			var xhr2 = new XMLHttpRequest();
			xhr2.open( 'POST', url2, true );
			xhr2.setRequestHeader( 'Content-Type', 'application/json' );
			xhr2.setRequestHeader( 'X-WP-Nonce', nonce );
			xhr2.withCredentials = true;

			xhr2.onload = function () {
				var response = {};

				try {
					response = JSON.parse( xhr2.responseText );
				} catch ( e ) {
					response = {};
				}

				if ( xhr2.status >= 200 && xhr2.status < 300 && response.success ) {
					if ( avatarPreview ) {
						avatarPreview.innerHTML = config.defaultAvatar80 || '';
					}
					if ( profileAvatarEl ) {
						profileAvatarEl.innerHTML = config.defaultAvatar96 || '';
					}
					if ( avatarInput ) {
						avatarInput.value = '';
					}
					showMessage( 'Аватар удалён.', 'success' );
				} else {
					var msg = response.message || ( response.data && response.data.message ) || 'Ошибка при удалении аватара.';
					showMessage( msg, 'error' );
				}
			};

			xhr2.onerror = function () {
				showMessage( 'Ошибка соединения.', 'error' );
			};

			xhr2.send( JSON.stringify( { remove_avatar: true } ) );
		} );
	}

	// =========================================================================
	// События в городе
	// =========================================================================

	var eventsListEl = document.getElementById( 'lk-events-list' );
	var eventsLoadingEl = document.getElementById( 'lk-events-loading' );
	var cityNameEl = document.getElementById( 'lk-city-name' );

	function getDateParts( dateStr ) {
		if ( ! dateStr ) {
			return { day: '', month: '' };
		}
		var parts = dateStr.split( '-' );
		if ( parts.length < 3 ) {
			return { day: dateStr, month: '' };
		}
		var months = [
			'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
			'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'
		];
		return {
			day: parseInt( parts[2], 10 ),
			month: ( months[ parseInt( parts[1], 10 ) - 1 ] || '' )
		};
	}

	function formatPrice( event ) {
		if ( event.is_free ) {
			return 'Бесплатно';
		}
		if ( event.price_from ) {
			var price = event.price_from + ' ₽';
			if ( event.price_to && event.price_to !== event.price_from ) {
				price = event.price_from + ' — ' + event.price_to + ' ₽';
			}
			return price;
		}
		return '';
	}

	var PIN_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>';

	function renderEvents( data ) {
		if ( ! eventsListEl ) {
			return;
		}

		if ( cityNameEl && data.city_name ) {
			cityNameEl.textContent = '— ' + data.city_name;
		}

		if ( eventsLoadingEl ) {
			eventsLoadingEl.remove();
		}

		if ( ! data.events || data.events.length === 0 ) {
			var emptyEl = document.createElement( 'div' );
			emptyEl.className = 'lk-events__empty';
			emptyEl.textContent = config.l10n && config.l10n.noEvents
				? config.l10n.noEvents
				: 'Событий в вашем городе пока нет.';
			eventsListEl.appendChild( emptyEl );
			return;
		}

		var listEl = document.createElement( 'div' );
		listEl.className = 'lk-events__grid';

		data.events.forEach( function ( event ) {
			var card = document.createElement( 'a' );
			card.className = 'lk-event-card';
			card.href = event.permalink;

			var dateParts = getDateParts( event.date_start );
			var priceText = formatPrice( event );
			var locationText = event.venue_name || event.address || '';
			var hasImage = !! event.thumbnail;

			var html = '<div class="lk-event-card__media' + ( hasImage ? '' : ' lk-event-card__media--empty' ) + '">';

			if ( hasImage ) {
				html += '<img class="lk-event-card__image" src="' + escapeHtml( event.thumbnail ) + '" alt="" loading="lazy">';
			}

			if ( dateParts.day ) {
				html += '<span class="lk-event-card__badge">';
				html += '<span class="lk-event-card__badge-day">' + dateParts.day + '</span>';
				if ( dateParts.month ) {
					html += '<span class="lk-event-card__badge-month">' + dateParts.month + '</span>';
				}
				html += '</span>';
			}

			html += '</div>';

			html += '<div class="lk-event-card__body">';
			html += '<h3 class="lk-event-card__title">' + escapeHtml( event.title ) + '</h3>';

			if ( locationText ) {
				html += '<div class="lk-event-card__meta">';
				html += '<span class="lk-event-card__location">' + PIN_ICON + '<span>' + escapeHtml( locationText ) + '</span></span>';
				html += '</div>';
			}

			html += '<div class="lk-event-card__footer">';

			if ( priceText ) {
				var cls = event.is_free ? 'lk-event-card__price--free' : '';
				html += '<span class="lk-event-card__price ' + cls + '">' + priceText + '</span>';
			}

			html += '<span class="lk-event-card__arrow" aria-hidden="true">→</span>';
			html += '</div>';
			html += '</div>';

			card.innerHTML = html;
			listEl.appendChild( card );
		} );

		eventsListEl.appendChild( listEl );
	}

	function escapeHtml( str ) {
		if ( ! str ) {
			return '';
		}
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	function loadCityEvents() {
		if ( ! eventsListEl ) {
			return;
		}

		var baseUrl3 = restUrl ? restUrl.replace( /\/+$/, '' ) : '';
		var url3 = baseUrl3 + '/user/city-events';

		var xhr3 = new XMLHttpRequest();
		xhr3.open( 'GET', url3, true );
		xhr3.setRequestHeader( 'X-WP-Nonce', nonce );
		xhr3.withCredentials = true;

		xhr3.onload = function () {
			var response = {};

			try {
				response = JSON.parse( xhr3.responseText );
			} catch ( e ) {
				response = {};
			}

			if ( xhr3.status >= 200 && xhr3.status < 300 ) {
				renderEvents( response );
			} else {
				if ( eventsLoadingEl ) {
					eventsLoadingEl.textContent = 'Не удалось загрузить события.';
				}
			}
		};

		xhr3.onerror = function () {
			if ( eventsLoadingEl ) {
				eventsLoadingEl.textContent = 'Ошибка соединения.';
			}
		};

		xhr3.send();
	}

	loadCityEvents();
}() );
