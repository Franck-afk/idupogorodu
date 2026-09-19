/**
 * Organizer Complete — форма доуполнения анкеты организатора
 * (создан через соц-кнопку с intent=organizer, флаг _idpg_org_pending).
 *
 * POST /wp-json/idpg/v1/auth/organizer-complete
 * После успеха — редирект в панель организатора.
 *
 * @package idpg_core
 */
(function () {
	'use strict';

	var form = document.getElementById('organizer-complete-form');

	if (!form) { return; }

	var statusEl = document.getElementById('organizer-complete-status');
	var statusText = statusEl ? statusEl.querySelector('.auth__message-text') : null;
	var submitBtn = form.querySelector('.auth__submit');
	var spinner = form.querySelector('.auth__spinner');
	var busy = false;

	var REST_URL = window.idpgOrganizerComplete
		? window.idpgOrganizerComplete.restUrl
		: '/wp-json/idpg/v1';
	var NONCE = window.idpgOrganizerComplete ? window.idpgOrganizerComplete.nonce : '';

	function showMessage(text, isError) {
		if (!statusEl || !statusText) { return; }
		statusText.textContent = text || '';
		statusEl.className = 'auth__message' + (isError ? ' auth__message--error' : ' auth__message--success');
		statusEl.removeAttribute('hidden');
	}

	function setBusy(state) {
		busy = state;
		if (submitBtn) { submitBtn.disabled = state; }
		if (spinner) { spinner.style.display = state ? '' : 'none'; }
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();

		if (busy) { return; }

		var payload = {
			org_name: (form.org_name && form.org_name.value || '').trim(),
			contact_name: (form.contact_name && form.contact_name.value || '').trim(),
			phone: (form.phone && form.phone.value || '').trim(),
		};

		if (form.email) {
			payload.email = (form.email.value || '').trim();
		}

		if (form.password) {
			var pass = form.password.value || '';
			var passRep = form.password_rep ? (form.password_rep.value || '') : '';

			if (pass !== '' || passRep !== '') {
				if (pass.length < 8) {
					showMessage('Пароль должен содержать минимум 8 символов.', true);
					return;
				}
				if (pass !== passRep) {
					showMessage('Пароли не совпадают.', true);
					return;
				}
				payload.password = pass;
				payload.password_rep = passRep;
			}
		}

		if (!payload.org_name || !payload.contact_name || !payload.phone) {
			showMessage('Заполните все обязательные поля.', true);
			return;
		}

		setBusy(true);

		fetch(REST_URL + '/auth/organizer-complete', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NONCE,
			},
			credentials: 'same-origin',
			body: JSON.stringify(payload),
		})
			.then(function (response) {
				return response.json().then(function (json) {
					return { ok: response.ok, json: json };
				});
			})
			.then(function (result) {
				if (result.ok && result.json && result.json.success) {
					showMessage('Анкета сохранена. Переходим в панель…', false);
					window.location.href = result.json.redirect_url || '/wp-admin/';
				} else {
					setBusy(false);
					showMessage((result.json && result.json.message) || 'Не удалось сохранить анкету.', true);
				}
			})
			.catch(function () {
				setBusy(false);
				showMessage('Ошибка соединения. Попробуйте позже.', true);
			});
	});
})();
