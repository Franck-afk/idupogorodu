/**
 * Подписка на дайджест — AJAX через REST API.
 */
(function(){
  'use strict';

  var forms = document.querySelectorAll('[data-idpg-subscribe]');
  if (!forms.length) return;

  var restUrl = (window.idpgSubscribe && window.idpgSubscribe.restUrl) || (window.location.origin + '/wp-json/idpg/v1');

  // Строки без gettext в JS — как в остальных файлах темы.
  var L10N = {
    badEmail: 'Укажите корректный email.',
    needConsent: 'Подтвердите согласие на обработку данных.',
    error: 'Что-то пошло не так. Попробуйте позже.',
    sending: 'Отправляем…'
  };

  function showMessage(form, text, ok){
    var box = form.querySelector('.idpg-subscribe__message');
    if (!box) return;
    box.hidden = false;
    box.textContent = text;
    box.classList.toggle('idpg-subscribe__message--ok', !!ok);
    box.classList.toggle('idpg-subscribe__message--error', !ok);
  }

  forms.forEach(function(form){
    var emailInput = form.querySelector('input[type="email"]');
    var button = form.querySelector('button[type="submit"]');
    if (!emailInput || !button) return;

    var defaultLabel = button.textContent;

    form.addEventListener('submit', function(e){
      e.preventDefault();

      var email = emailInput.value.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)){
        showMessage(form, L10N.badEmail, false);
        emailInput.focus();
        return;
      }

      var consentRequired = form.getAttribute('data-consent-required') === '1';
      var consentBox = form.querySelector('input[name="idpg_consent"]');
      if (consentRequired && (!consentBox || !consentBox.checked)){
        showMessage(form, L10N.needConsent, false);
        return;
      }

      var honeypot = form.querySelector('input[name="idpg_company"]');

      button.disabled = true;
      button.textContent = L10N.sending;

      fetch(restUrl + '/subscribe', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          email: email,
          idpg_company: honeypot ? honeypot.value : '',
          consent: consentBox && consentBox.checked ? '1' : '',
          consent_required: consentRequired ? '1' : ''
        })
      })
        .then(function(r){ return r.json().then(function(d){ return { status: r.status, body: d }; }); })
        .then(function(res){
          var ok = res.status < 400 && res.body && res.body.success;
          var msg = (res.body && res.body.message) || L10N.error;
          showMessage(form, msg, ok);
          if (ok) form.reset();
        })
        .catch(function(){
          showMessage(form, L10N.error, false);
        })
        .finally(function(){
          button.disabled = false;
          button.textContent = defaultLabel;
        });
    });
  });
})();
