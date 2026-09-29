/**
 * Форма площадки организатора (DEV-50) — /organizer/venue/new|{id}/.
 *
 * Отправляет данные в REST /idpg/v1/organizer/venues. Без JavaScript форма
 * работает сама: обычный POST обрабатывается в page-organizer-venue.php.
 * Здесь он перехватывается, чтобы не перезагружать страницу.
 *
 * Поля берутся из контейнера «Данные площадки» в
 * docs/carbon-fields-export.json; набор здесь и в шаблоне один, поэтому
 * синхронизация проверяется тестом tests/test-organizer-venue-form.php.
 */
(function(){
  'use strict';

  var form = document.querySelector('[data-idpg-venue-form]');
  if (!form) return;

  var root = document.getElementById('primary') || document;

  var venueId  = parseInt(form.getAttribute('data-idpg-venue-id') || '0', 10) || 0;
  var url      = form.getAttribute('data-idpg-venue-url');
  var nonce    = form.getAttribute('data-idpg-venue-nonce');
  var locked   = form.getAttribute('data-idpg-venue-locked') === '1';
  var backUrl  = form.getAttribute('data-idpg-venue-back') || '/organizer/venues/';
  var statusBox = form.querySelector('[data-idpg-venue-status]');
  var saveBtn  = form.querySelector('[data-idpg-venue-save]');

  if (!url || !nonce || !window.fetch) return;

  /**
   * Сообщение под формой.
   *
   * @param {string} message Текст.
   * @param {boolean} isError Ошибка ли это.
   */
  function setStatus(message, isError){
    if (!statusBox) return;

    statusBox.className = 'idpg-notice ' + (isError ? 'idpg-notice--error' : 'idpg-notice--success');
    statusBox.setAttribute('role', isError ? 'alert' : 'status');
    statusBox.textContent = message || '';
  }

  /**
   * Блокировка формы на время запроса.
   *
   * @param {boolean} busy Занята ли форма.
   */
  function setBusy(busy){
    var buttons = form.querySelectorAll('button');
    var i;

    for (i = 0; i < buttons.length; i++) buttons[i].disabled = busy;
    if (saveBtn) saveBtn.classList.toggle('create-event__btn--loading', !!busy);
  }

  /**
   * Значение поля.
   *
   * @param {string} name Имя поля.
   * @returns {string}
   */
  function value(name){
    return (form.querySelector('[name="' + name + '"]') || {}).value || '';
  }

  /**
   * Тело запроса.
   *
   * @returns {Object}
   */
  function collectPayload(){
    return {
      title: value('title'),
      content: value('content'),
      city: parseInt(value('city'), 10) || 0,
      venue_type: value('venue_type'),
      venue_address: value('venue_address'),
      venue_phone: value('venue_phone'),
      venue_email: value('venue_email'),
      venue_website: value('venue_website'),
      venue_capacity: value('venue_capacity'),
      venue_lat: value('venue_lat'),
      venue_lng: value('venue_lng'),
      poster_id: parseInt(value('poster_id'), 10) || 0
    };
  }

  /**
   * Запрос к REST.
   *
   * @param {string} method HTTP-метод.
   * @param {string} target Адрес.
   * @param {Object} body Тело или null.
   * @returns {Promise<Object>} {ok, status, body}.
   */
  function request(method, target, body){
    var options = {
      method: method,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      }
    };

    if (body) options.body = JSON.stringify(body);

    return fetch(target, options).then(function(response){
      return response.json().catch(function(){ return {}; }).then(function(parsed){
        return { ok: response.ok, status: response.status, body: parsed };
      });
    });
  }

  /**
   * Текст ошибки из ответа.
   *
   * @param {Object} result Результат request().
   * @returns {string}
   */
  function errorText(result){
    var body = result.body || {};

    return body.message || 'Не удалось сохранить площадку. Проверьте поля и попробуйте ещё раз.';
  }

  form.addEventListener('submit', function(e){
    e.preventDefault();

    if (locked) return;

    var target = venueId ? url + '/' + venueId : url;
    var method = venueId ? 'PUT' : 'POST';

    setBusy(true);
    setStatus('', false);

    request(method, target, collectPayload())
      .then(function(result){
        setBusy(false);

        if (!result.ok){
          setStatus(errorText(result), true);
          return;
        }

        var data = (result.body && result.body.data) || {};

        if (!venueId && data.id){
          // После создания переходим в режим правки: повторное сохранение
          // должно обновлять ту же площадку, а не создавать вторую.
          venueId = parseInt(data.id, 10) || 0;
          form.setAttribute('data-idpg-venue-id', String(venueId));

          if (window.history && window.history.replaceState){
            window.history.replaceState({}, '', url.replace(/\/$/, '') + '/' + venueId + '/');
          }
        }

        setStatus('Площадка сохранена.', false);
        showDeleteButton();
      })
      .catch(function(){
        setBusy(false);
        setStatus('Не удалось связаться с сервером. Попробуйте ещё раз.', true);
      });
  });

  /**
   * Показать кнопку удаления: у новой площадки её нет, потому что ID
   * неизвестен до первого сохранения.
   */
  function showDeleteButton(){
    if (document.querySelector('[data-idpg-venue-delete]')) return;

    var wrap = document.createElement('div');
    var btn = document.createElement('button');

    // Классы те же, что и у кнопки в разметке: `venue-form__*` задаёт
    // опасный вид, `create-event__btn` — базовый.
    wrap.className = 'venue-form__delete-row';
    btn.className = 'create-event__btn venue-form__danger';
    btn.type = 'button';
    btn.setAttribute('data-idpg-venue-delete', '');
    btn.textContent = 'Удалить площадку';

    wrap.appendChild(btn);
    form.parentNode.appendChild(wrap);

    // Обработчик обязателен именно здесь: кнопка появляется уже после
    // загрузки скрипта, и навешенный ниже на старый экземпляр её не покрыл
    // бы — кнопка выглядела бы рабочей, а ничего не делала.
    bindDelete(btn);
  }

  /**
   * Удаление площадки.
   *
   * @param {Element} btn Кнопка удаления.
   */
  function bindDelete(btn){
    if (!btn || !venueId) return;

    btn.addEventListener('click', function(){
      if (!window.confirm('Удалить площадку безвозвратно?')) return;

      setBusy(true);

      request('DELETE', url + '/' + venueId, null)
        .then(function(result){
          setBusy(false);

          if (!result.ok){
            setStatus(errorText(result), true);
            return;
          }

          window.location.href = backUrl;
        })
        .catch(function(){
          setBusy(false);
          setStatus('Не удалось удалить площадку. Попробуйте ещё раз.', true);
        });
    });
  }

  // У существующей площадки кнопка приходит из разметки, у новой её
  // создаст `showDeleteButton()` после первого сохранения.
  bindDelete(root.querySelector('[data-idpg-venue-delete]'));

  // --- Фото площадки ------------------------------------------------------
  //
  // wp.media подключается шаблоном только при наличии upload_files.

  if (window.wp && window.wp.media){
    var frame;
    var selectBtn = root.querySelector('[data-idpg-vposter-select]');
    var input = root.querySelector('[data-idpg-vposter-input]');
    var preview = root.querySelector('[data-idpg-vposter-preview]');
    var removeBtn = root.querySelector('[data-idpg-vposter-remove]');

    if (selectBtn){
      selectBtn.addEventListener('click', function(e){
        e.preventDefault();

        if (frame) { frame.open(); return; }

        frame = window.wp.media({ multiple: false, library: { type: 'image' } });

        frame.on('select', function(){
          var item = frame.state().get('selection').first().toJSON();

          if (input) input.value = item.id;

          if (preview){
            preview.textContent = '';
            var img = document.createElement('img');
            img.src = (item.sizes && item.sizes.medium ? item.sizes.medium.url : item.url);
            img.alt = '';
            preview.appendChild(img);
          }

          if (removeBtn) removeBtn.hidden = false;
        });

        frame.open();
      });
    }

    if (removeBtn){
      removeBtn.addEventListener('click', function(){
        if (input) input.value = '';
        if (preview) preview.textContent = '';
        removeBtn.hidden = true;
      });
    }
  }
})();
