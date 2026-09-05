/**
 * VK ID OneTap — виджет входа на /login/.
 *
 * Официальный @vkid/sdk (UMD). Поток:
 * 1. Config.init(app, redirectUrl, mode=Redirect) — mode (ConfigAuthMode)
 *    задан как Redirect, чтобы SDK НЕ открывал id.vk.com в новой вкладке
 *    (иначе при её закрытии виджет кидает «New tab has been closed»).
 * 2. OneTap.render(container) — кнопка VK ID.
 * 3. Клик → редирект на id.vk.com → после авторизации возврат на
 *    /auth/callback/vk/?code=…&device_id=… (страница-мост).
 * 4. Мост: SDK читает code из URL, VKID.Auth.exchangeCode() (PKCE-verifier
 *    у SDK в localStorage браузера — публичный клиент).
 * 5. access_token → POST /wp-json/idpg/v1/auth/vkid → сервер проверяет
 *    токен через id.vk.com/oauth2/user_info, входит/регистрирует
 *    (Social_User::login, роль subscriber) → редирект по роли.
 */
(function () {
	'use strict';

	var config = window.idpgVkId || {};
	var authMessage = document.getElementById('auth-message');

	function showMsg(text, isError) {
		if (!authMessage) { window.alert(text); return; }
		authMessage.className = 'auth__message auth__message--' + (isError ? 'error' : 'success');
		authMessage.removeAttribute('hidden');
		var t = authMessage.querySelector('.auth__message-text');
		if (t) { t.textContent = text; }
		if (isError) { setTimeout(function () { authMessage.setAttribute('hidden', ''); }, 6000); }
	}

	function init() {
		if (!('VKIDSDK' in window)) { return; }

		var VKID = window.VKIDSDK;
		if (!config.app) { return; }

		VKID.Config.init({
			app: parseInt(config.app, 10),
			redirectUrl: config.redirectUrl || window.location.origin + '/auth/callback/vk/',
			// ВАЖНО: ключ называется именно `mode` (ConfigAuthMode), а не
			// responseMode. По умолчанию SDK использует mode='new_tab' —
			// открывает id.vk.com через window.open и при закрытии вкладки
			// кидает ошибку 102 «New tab has been closed». mode='redirect'
			// включает loginByRedirect — переход в той же вкладке.
			mode: VKID.ConfigAuthMode.Redirect,
			responseMode: VKID.ConfigResponseMode.Redirect,
			source: VKID.ConfigSource.LOWCODE,
			scope: config.scope || 'vkid.personal_info email',
		});

		var containers = document.querySelectorAll('.idpg-vkid-onetap');
		if (!containers.length) { return; }

		// Свой экземпляр OneTap на контейнер — повторный render() одного
		// инстанса дублирует кнопку.
		containers.forEach(function (container) {
			if (container.dataset.idpgRendered) { return; }
			container.dataset.idpgRendered = '1';

			var oneTap = new VKID.OneTap();

			oneTap.render({
				container: container,
				scheme: 'light',
				fastAuthEnabled: false,
				showAlternativeLogin: false,
				skin: 'secondary',
			});

			oneTap
				.on(VKID.WidgetEvents.ERROR, function (error) {
					showMsg('VK ID: ' + ((error && error.text) || 'ошибка виджета'), true);
				})
				.on(VKID.OneTapInternalEvents.LOGIN_SUCCESS, function (payload) {
					VKID.Auth.exchangeCode(payload.code, payload.device_id)
						.then(onExchange)
						.catch(function (error) {
							showMsg('VK ID: не удалось обменять код (' + ((error && error.code) || 'exchange_failed') + ')', true);
						});
				});
		});

		function onExchange(data) {
			if (!data || !data.access_token) {
				showMsg('VK ID: пустой токен', true);
				return;
			}

			fetch((config.restUrl || window.location.origin + '/wp-json/idpg/v1') + '/auth/vkid', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce || '',
				},
				body: JSON.stringify({
					access_token: data.access_token,
					id_token: data.id_token || '',
				}),
			})
				.then(function (response) {
					return response.json().then(function (json) {
						return { ok: response.ok, json: json };
					});
				})
				.then(function (result) {
					if (result.ok && result.json && result.json.success) {
						showMsg('Вход выполнен. Перенаправление…', false);
						window.location.href = result.json.redirect_url || '/';
					} else {
						var msg = (result.json && result.json.message) || 'Не удалось войти через VK ID.';
						showMsg(msg, true);
					}
				})
				.catch(function () {
					showMsg('Ошибка соединения. Попробуйте позже.', true);
				});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
