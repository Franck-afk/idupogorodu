/**
 * Скрипты для страницы входа и регистрации.
 *
 * Переключение табов, валидация форм, AJAX-отправка.
 *
 * @package idpg_core
 */

( function () {
	'use strict';

	// ── DOM references ──
	var authMessage = document.getElementById( 'auth-message' );
	var tabs       = document.querySelectorAll( '.auth__tab' );
	var panels     = document.querySelectorAll( '.auth__panel' );
	var forms      = document.querySelectorAll( '.auth__form' );
	var config     = window.idpgAuth || {};
	var messageTimeout = null;

	// Имя поля согласия на обработку ПДн. Задаётся темой из
	// IDPG_Privacy::CONSENT_FIELD, чтобы PHP и JS не разъезжались.
	var PDN_CONSENT_FIELD = ( config.pdnConsentField || 'idpg_pdn_consent' );

	// ── Tab switching ──

	/**
	 * Переключение таба.
	 *
	 * @param {HTMLElement} tab Выбранный элемент таба.
	 */
	function switchTab( tab ) {
		var targetId  = tab.getAttribute( 'data-tab' );
		var targetMap = {
			'login':              'login-form',
			'register-organizer': 'register-organizer-form',
		};
		var targetPanel = document.getElementById( targetMap[ targetId ] );

		if ( ! targetPanel ) {
			return;
		}

		// Очистка сообщений и ошибок.
		hideMessage();
		clearAllErrors( targetPanel );

		// Обновление табов.
		tabs.forEach( function ( t ) {
			t.classList.remove( 'auth__tab--active' );
			t.setAttribute( 'aria-selected', 'false' );
		} );

		tab.classList.add( 'auth__tab--active' );
		tab.setAttribute( 'aria-selected', 'true' );

		// Переключение панелей с fade.
		panels.forEach( function ( panel ) {
			panel.classList.remove( 'auth__panel--active' );
		} );

		// requestAnimationFrame для запуска CSS-перехода.
		requestAnimationFrame( function () {
			targetPanel.classList.add( 'auth__panel--active' );
		} );
	}

	// ── Client-side validation ──

	/**
	 * Проверка email по регулярному выражению.
	 *
	 * @param {string} email
	 * @return {boolean}
	 */
	function validateEmail( email ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
	}

	/**
	 * Проверка минимальной длины пароля.
	 *
	 * @param {string} password
	 * @return {boolean}
	 */
	function validatePassword( password ) {
		return password.length >= 8;
	}

	/**
	 * Проверка совпадения паролей.
	 *
	 * @param {string} password
	 * @param {string} confirm
	 * @return {boolean}
	 */
	function passwordsMatch( password, confirm ) {
		return password === confirm;
	}

	/**
	 * Показ ошибки под полем.
	 *
	 * @param {HTMLElement} input
	 * @param {string}      message
	 */
	function showFieldError( input, message ) {
		input.classList.add( 'border-red-500' );
		input.setAttribute( 'aria-invalid', 'true' );

		var wrapper = input.closest( '.auth__field' );
		if ( ! wrapper ) {
			return;
		}

		var existing = wrapper.querySelector( '.auth__field-error' );
		if ( existing ) {
			existing.textContent = message;
			return;
		}

		var errorEl = document.createElement( 'span' );
		errorEl.className = 'auth__field-error';
		errorEl.textContent = message;
		wrapper.appendChild( errorEl );
	}

	/**
	 * Очистка ошибки конкретного поля.
	 *
	 * @param {HTMLElement} input
	 */
	function clearFieldError( input ) {
		input.classList.remove( 'border-red-500' );
		input.removeAttribute( 'aria-invalid' );

		var wrapper = input.closest( '.auth__field' );
		if ( ! wrapper ) {
			return;
		}

		var errorEl = wrapper.querySelector( '.auth__field-error' );
		if ( errorEl ) {
			errorEl.remove();
		}
	}

	/**
	 * Очистка всех ошибок в форме.
	 *
	 * @param {HTMLElement} form
	 */
	function clearAllErrors( form ) {
		var inputs = form.querySelectorAll( '.border-red-500' );
		inputs.forEach( function ( input ) {
			clearFieldError( input );
		} );
	}

	/**
	 * Очистка ошибки конкретного поля при вводе.
	 *
	 * @param {Event} e
	 */
	function onInputClearError( e ) {
		clearFieldError( e.target );
	}

	// ── Показ/скрытие пароля ──

	/**
	 * SVG-иконка глаза.
	 *
	 * @param {string} name open | closed
	 * @return {string} HTML-строка иконки.
	 */
	function eyeIcon( name ) {
		if ( 'closed' === name ) {
			return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
	}

	/**
	 * Добавление кнопки «глаз» к полям пароля.
	 */
	function enhancePasswordFields() {
		var inputs = document.querySelectorAll( 'input.auth__input[type="password"]' );

		inputs.forEach( function ( input ) {
			if ( input.parentElement.classList.contains( 'auth__password' ) ) {
				return;
			}

			var wrapper = document.createElement( 'div' );
			wrapper.className = 'auth__password';

			var toggle = document.createElement( 'button' );
			toggle.type = 'button';
			toggle.className = 'auth__password-toggle';
			toggle.setAttribute( 'aria-label', 'Показать пароль' );
			toggle.setAttribute( 'aria-pressed', 'false' );
			toggle.innerHTML = eyeIcon( 'open' );

			input.parentNode.insertBefore( wrapper, input );
			wrapper.appendChild( input );
			wrapper.appendChild( toggle );

			toggle.addEventListener( 'click', function () {
				var show = 'password' === input.type;
				input.type = show ? 'text' : 'password';
				toggle.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
				toggle.setAttribute( 'aria-label', show ? 'Скрыть пароль' : 'Показать пароль' );
				toggle.innerHTML = eyeIcon( show ? 'closed' : 'open' );
				input.focus();
			} );
		} );
	}

	// ── Message display ──

	/**
	 * Показ сообщения пользователю.
	 *
	 * Автоматически скрывается через 5 секунд.
	 * Можно закрыть по клику на крестик.
	 *
	 * @param {string} text Текст сообщения.
	 * @param {string} type success | error.
	 */
	function showMessage( text, type ) {
		if ( ! authMessage ) {
			return;
		}

		// Сброс предыдущего таймера.
		if ( messageTimeout ) {
			clearTimeout( messageTimeout );
		}

		authMessage.className = 'auth__message auth__message--' + type;
		authMessage.removeAttribute( 'hidden' );

		var textEl = authMessage.querySelector( '.auth__message-text' );
		if ( textEl ) {
			textEl.textContent = text;
		}

		// Добавление кнопки закрытия, если её нет.
		var closeBtn = authMessage.querySelector( '.auth__message-close' );
		if ( ! closeBtn ) {
			closeBtn = document.createElement( 'button' );
			closeBtn.className = 'auth__message-close';
			closeBtn.setAttribute( 'aria-label', 'Закрыть сообщение' );
			closeBtn.innerHTML = '&times;';
			closeBtn.addEventListener( 'click', hideMessage );
			authMessage.appendChild( closeBtn );
		}

		// Автоскрытие.
		messageTimeout = setTimeout( hideMessage, 5000 );
	}

	/**
	 * Скрытие блока сообщений.
	 */
	function hideMessage() {
		if ( ! authMessage ) {
			return;
		}

		if ( messageTimeout ) {
			clearTimeout( messageTimeout );
			messageTimeout = null;
		}

		authMessage.setAttribute( 'hidden', '' );
	}

	// ── AJAX form submission ──

	/**
	 * AJAX-отправка формы на REST API.
	 *
	 * @param {HTMLElement} formElement     Элемент формы.
	 * @param {string}      endpoint        Путь эндпоинта (напр. /auth/login).
	 * @param {Function}    dataTransformer Функция преобразования FormData в JSON.
	 */
	function submitAuthForm( formElement, endpoint, dataTransformer ) {
		var submitBtn  = formElement.querySelector( '.auth__submit' );
		var formData   = new FormData( formElement );
		var data       = dataTransformer ? dataTransformer( formData ) : {};
		var nonce      = config.nonce || '';
		var apiUrl     = ( config.restUrl || window.location.origin + '/wp-json/idpg/v1' ) + endpoint;

		// Спиннер.
		if ( submitBtn ) {
			submitBtn.classList.add( 'auth__submit--loading' );
		}

		fetch( apiUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( data ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					return { ok: response.ok, json: json };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && result.json.success ) {
					showMessage( result.json.message || 'Успешно!', 'success' );

					// Регистрация с подтверждением по email: письмо со ссылкой уже
					// отправлено, редирект не делаем — остаёмся на странице входа.
					if ( result.json.pending_email ) {
						return;
					}

					setTimeout( function () {
						var redir = result.json.redirect_url || '/';
						if ( redir.indexOf( '/' ) !== 0 || redir.indexOf( '//' ) !== -1 ) {
							redir = '/';
						}
						window.location.href = redir;
					}, 1000 );
				} else {
					var errorMessage = result.json.message || 'Произошла ошибка.';
					showMessage( errorMessage, 'error' );
				}
			} )
			.catch( function () {
				showMessage( 'Ошибка соединения. Попробуйте позже.', 'error' );
			} )
			.finally( function () {
				if ( submitBtn ) {
					submitBtn.classList.remove( 'auth__submit--loading' );
				}
			} );
	}

	// ── Form handlers ──

	/**
	 * Валидация и отправка формы входа.
	 *
	 * @param {Event} e
	 */
	function handleLoginSubmit( e ) {
		e.preventDefault();
		clearAllErrors( e.target );

		var form    = e.target;
		var email   = form.querySelector( '[name="email"]' ).value.trim();
		var pass    = form.querySelector( '[name="password"]' ).value;

		if ( ! validateEmail( email ) ) {
			showFieldError( form.querySelector( '[name="email"]' ), 'Введите корректный email.' );
			return;
		}

		if ( ! pass ) {
			showFieldError( form.querySelector( '[name="password"]' ), 'Введите пароль.' );
			return;
		}

		hideMessage();

		submitAuthForm( form, '/auth/login', function ( fd ) {
			return {
				email:    fd.get( 'email' ),
				password: fd.get( 'password' ),
				remember: fd.get( 'remember' ) === '1',
			};
		} );
	}

	/**
	 * Валидация и отправка формы регистрации организатора.
	 *
	 * @param {Event} e
	 */
	function handleRegisterOrganizerSubmit( e ) {
		e.preventDefault();
		clearAllErrors( e.target );

		var form        = e.target;
		var orgName     = form.querySelector( '[name="org_name"]' ).value.trim();
		var contactName = form.querySelector( '[name="contact_name"]' ).value.trim();
		var email       = form.querySelector( '[name="email"]' ).value.trim();
		var phone       = form.querySelector( '[name="phone"]' ).value.trim();
		var pass        = form.querySelector( '[name="password"]' ).value;
		var confirm     = form.querySelector( '[name="password_confirm"]' ).value;

		if ( ! orgName ) {
			showFieldError( form.querySelector( '[name="org_name"]' ), 'Введите название организации.' );
			return;
		}

		if ( ! contactName ) {
			showFieldError( form.querySelector( '[name="contact_name"]' ), 'Введите ФИО представителя.' );
			return;
		}

		if ( ! validateEmail( email ) ) {
			showFieldError( form.querySelector( '[name="email"]' ), 'Введите корректный email.' );
			return;
		}

		if ( ! phone ) {
			showFieldError( form.querySelector( '[name="phone"]' ), 'Введите номер телефона.' );
			return;
		}

		if ( ! validatePassword( pass ) ) {
			showFieldError( form.querySelector( '[name="password"]' ), 'Пароль должен быть минимум 8 символов.' );
			return;
		}

		if ( ! passwordsMatch( pass, confirm ) ) {
			showFieldError( form.querySelector( '[name="password_confirm"]' ), 'Пароли не совпадают.' );
			return;
		}

		if ( ! form.querySelector( '[name="terms"]' ).checked ) {
			showMessage( 'Необходимо согласиться с офертой и политикой конфиденциальности.', 'error' );
			return;
		}

		// Согласие на обработку персональных данных (152-ФЗ) — отдельный
		// чекбокс: согласие с офертой его не заменяет. Отсутствующий
		// элемент даёт явный false (решение сервера), а не «не передано».
		var pdnCheckbox = form.querySelector( '[name="' + PDN_CONSENT_FIELD + '"]' );
		var pdnConsent  = !! ( pdnCheckbox && pdnCheckbox.checked );

		if ( pdnCheckbox && ! pdnConsent ) {
			showMessage( 'Необходимо согласие на обработку персональных данных.', 'error' );
			return;
		}

		hideMessage();

		submitAuthForm( form, '/auth/register', function ( fd ) {
			return {
				type:              'organizer',
				name:              fd.get( 'contact_name' ),
				email:             fd.get( 'email' ),
				password:          fd.get( 'password' ),
				org_name:          fd.get( 'org_name' ),
				contact_name:      fd.get( 'contact_name' ),
				phone:             fd.get( 'phone' ),
				terms:             fd.get( 'terms' ) === '1',
				[ PDN_CONSENT_FIELD ]: pdnConsent,
				// Honeypot (DEV-150). Поле лежит в форме, поэтому FormData
				// его уже несёт, но payload выше собирается вручную, по
				// списку полей, — и без этой строки приманка до сервера не
				// дошла бы вовсе, то есть обход был бы основным путём, а
				// защищался бы только PHP-fallback без JavaScript.
				// У человека поле пустое, у бота заполнено.
				// Приём тот же, что в subscribe.js.
				idpg_company:      fd.get( 'idpg_company' ) || '',
			};
		} );
	}

	/**
	 * Обработка формы восстановления пароля.
	 *
	 * @param {Event} e
	 */
	function handleForgotPasswordSubmit( e ) {
		e.preventDefault();
		clearAllErrors( e.target );

		var form  = e.target;
		var email = form.querySelector( '[name="email"]' ).value.trim();

		if ( ! validateEmail( email ) ) {
			showFieldError( form.querySelector( '[name="email"]' ), 'Введите корректный email.' );
			return;
		}

		hideMessage();

		submitAuthForm( form, '/auth/forgot-password', function ( fd ) {
			return {
				email: fd.get( 'email' ),
			};
		} );
	}

	/**
	 * Обработка формы сброса пароля.
	 *
	 * @param {Event} e
	 */
	function handleResetPasswordSubmit( e ) {
		e.preventDefault();
		clearAllErrors( e.target );

		var form     = e.target;
		var pass     = form.querySelector( '[name="new_password"]' ).value;
		var confirm  = form.querySelector( '[name="password_confirm"]' ).value;
		var userId   = form.querySelector( '[name="user_id"]' ).value;
		var resetKey = form.querySelector( '[name="reset_key"]' ).value;

		if ( ! validatePassword( pass ) ) {
			showFieldError( form.querySelector( '[name="new_password"]' ), 'Пароль должен быть минимум 8 символов.' );
			return;
		}

		if ( ! passwordsMatch( pass, confirm ) ) {
			showFieldError( form.querySelector( '[name="password_confirm"]' ), 'Пароли не совпадают.' );
			return;
		}

		if ( ! userId || ! resetKey ) {
			showMessage( 'Неверная ссылка для восстановления пароля.', 'error' );
			return;
		}

		hideMessage();

		submitAuthForm( form, '/auth/reset-password', function () {
			return {
				user_id:      parseInt( userId, 10 ),
				reset_key:    resetKey,
				new_password: pass,
			};
		} );
	}

	// ── Init ──

	/**
	 * Инициализация.
	 */
	function init() {
		// Удаление hidden с панелей — управление через CSS-классы.
		panels.forEach( function ( panel ) {
			panel.removeAttribute( 'hidden' );
		} );

		// Поля пароля с кнопкой «глаз».
		enhancePasswordFields();

		// Табы.
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				switchTab( tab );
			} );
		} );

		// Формы.
		forms.forEach( function ( form ) {
			var id = form.closest( '.auth__panel' )?.id || '';

			// Очистка ошибок при вводе.
			var inputs = form.querySelectorAll( 'input' );
			inputs.forEach( function ( input ) {
				input.addEventListener( 'input', onInputClearError );
			} );

			switch ( id ) {
				case 'login-form':
					form.addEventListener( 'submit', handleLoginSubmit );
					break;
				case 'register-organizer-form':
					form.addEventListener( 'submit', handleRegisterOrganizerSubmit );
					break;
				case 'forgot-password-form':
					form.addEventListener( 'submit', handleForgotPasswordSubmit );
					break;
				case 'reset-password-form':
					form.addEventListener( 'submit', handleResetPasswordSubmit );
					break;
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', init );
}() );
