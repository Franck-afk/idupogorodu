/**
 * Форма события организатора (G5) — /organizer/event/new/ и /organizer/event/{id}/.
 *
 * Скрипт не является прогрессивным улучшением в строгом смысле: без него
 * форма читается, но не сохраняется (no-JS путь для событий не
 * предусмотрен, в отличие от профиля). Он шлёт данные в готовый REST
 * плагина idpg-core:
 *
 *   POST   /idpg/v1/organizer/events              — создать черновик
 *   PUT    /idpg/v1/organizer/events/{id}         — сохранить правки
 *   POST   /idpg/v1/organizer/events/{id}/submit  — отправить на модерацию
 *   DELETE /idpg/v1/organizer/events/{id}         — удалить черновик
 *
 * Жизненный цикл и права здесь не дублируются: REST сам решает, что можно
 * править (draft/rejected) и что нельзя (pending/publish). Скрипт лишь
 * показывает ответ сервера, поэтому UI не может разрешить то, что API
 * запретит.
 *
 * Сеансы собираются из повторяющихся строк в JSON-массив {time, price} —
 * формат, который ждёт `sessions_json` на сервере.
 */
(function(){
  'use strict';

  var form = document.querySelector('[data-idpg-event-form]');
  if (!form) return;

  // Поиск идёт от корня main, а не от самой формы: полоса прогресса, номера
  // шагов и кнопки «отправить/удалить» лежат за пределами <form> (иначе
  // submit-кнопка отправляла бы форму), а привязка к form.parentNode
  // сломалась бы, если бы шаблон переставил блоки.
  var root = document.getElementById('primary') || document;
  var eventId = parseInt(form.getAttribute('data-idpg-event-id') || '0', 10) || 0;
  var collectionUrl = form.getAttribute('data-idpg-event-create-url');
  var nonce = form.getAttribute('data-idpg-event-nonce');
  var locked = form.getAttribute('data-idpg-event-locked') === '1';
  var statusBox = form.querySelector('[data-idpg-event-status]');
  var saveBtn = form.querySelector('[data-idpg-event-save]');

  if (!collectionUrl || !nonce || !window.fetch) return;

  /**
   * Показать сообщение под формой.
   *
   * @param {string} message Текст.
   * @param {boolean} isError Ошибка ли это.
   */
  function setStatus(message, isError){
    if (!statusBox) return;

    statusBox.className = 'create-event__' + (isError ? 'error' : 'success');
    statusBox.setAttribute('role', isError ? 'alert' : 'status');
    statusBox.textContent = message || '';
  }

  /**
   * Блокировка формы на время запроса: повторный клик не должен создать
   * два черновика.
   *
   * @param {boolean} busy Занята ли форма.
   */
  function setBusy(busy){
    var buttons = form.querySelectorAll('button');
    var i;

    for (i = 0; i < buttons.length; i++){
      // Кнопка добавления сеанса не отправляет запрос — её блокировать незачем.
      if (buttons[i].hasAttribute('data-idpg-event-session-add')) continue;
      buttons[i].disabled = busy;
    }

    if (saveBtn) saveBtn.classList.toggle('create-event__btn--loading', !!busy);
  }

  /**
   * Собрать сеансы из строк формы.
   *
   * @returns {Array} Массив {time, price}; пустые строки отбрасываются.
   */
  function collectSessions(){
    var rows = form.querySelectorAll('[data-idpg-event-session-row]');
    var out = [];
    var i, time, price, url;

    for (i = 0; i < rows.length; i++){
      time = (rows[i].querySelector('[name="session_time[]"]') || {}).value || '';
      price = (rows[i].querySelector('[name="session_price[]"]') || {}).value || '';
      url = (rows[i].querySelector('[name="session_url[]"]') || {}).value || '';

      time = time.trim();
      price = price.trim();
      url = url.trim();

      if (!time && !price) continue;

      out.push({ time: time, price: price, url: url });
    }

    return out;
  }

  /**
   * Тело запроса из полей формы.
   *
   * @returns {Object} Параметры для REST.
   */
  function collectPayload(){
    var payload = {
      title: (form.querySelector('[name="title"]') || {}).value || '',
      content: (form.querySelector('[name="content"]') || {}).value || '',
      excerpt: (form.querySelector('[name="excerpt"]') || {}).value || '',
      start_date: (form.querySelector('[name="start_date"]') || {}).value || '',
      end_date: (form.querySelector('[name="end_date"]') || {}).value || '',
      is_free: valueOf('[name="is_free"]:checked', 'no'),
      all_day: valueOf('[name="all_day"]:checked', 'no'),

      // Место.
      venue: intValue('[name="venue"]'),
      address: value('[name="address"]'),

      // Билеты. `_idpg_currency` здесь нет намеренно: организатор
      // работает только в рублях (DEV-95), валюту ставит REST.
      ticket_status: value('[name="ticket_status"]'),
      price_from: value('[name="price_from"]'),
      price_to: value('[name="price_to"]'),
      vip_price: value('[name="vip_price"]'),
      ticket_type: value('[name="ticket_type"]'),
      ticket_url: value('[name="ticket_url"]'),
      yandex_form_url: value('[name="yandex_form_url"]'),
      widget_code: value('[name="widget_code"]'),

      // Медиа и дополнительное.
      video_trailer: value('[name="video_trailer"]'),
      stream_embed: value('[name="stream_embed"]'),
      is_online: valueOf('[name="is_online"]:checked', 'no'),
      stream_url: value('[name="stream_url"]'),
      banner_request: valueOf('[name="banner_request"]:checked', 'no'),

      // Таксономии.
      city: intValue('[name="city"]'),
      category: intValue('[name="category"]'),
      age_rating: intValue('[name="age_rating"]'),

      poster_id: intValue('[name="poster_id"]'),
      gallery: galleryIds.slice()
    };

    // Сеансы. Пустой массив отправляем всегда: он означает «сеансов нет».
    // Раньше пустой список не отправлялся вовсе, и очистить уже заведённые
    // сеансы через форму было невозможно.
    payload.sessions_json = JSON.stringify(collectSessions());

    return payload;
  }

  /**
   * Значение поля по имени.
   *
   * @param {string} name Имя поля.
   * @returns {string}
   */
  function value(name){
    return (form.querySelector('[name="' + name + '"]') || {}).value || '';
  }

  /**
   * Значение выбранного radio или запасное.
   *
   * @param {string} selector Селектор.
   * @param {string} fallback Значение по умолчанию.
   * @returns {string}
   */
  function valueOf(selector, fallback){
    var node = form.querySelector(selector);
    return node ? node.value : fallback;
  }

  /**
   * Числовое значение поля; пустое — 0, что REST трактует как «не выбрано».
   *
   * @param {string} name Имя поля.
   * @returns {number}
   */
  function intValue(name){
    var node = form.querySelector('[name="' + name + '"]');
    return node ? (parseInt(node.value, 10) || 0) : 0;
  }

  /**
   * POST/PUT/DELETE с JSON-ответом, приведённым к общему виду.
   *
   * @param {string} url Адрес.
   * @param {string} method HTTP-метод.
   * @param {Object} body Тело или null.
   * @returns {Promise<Object>} {ok, body}.
   */
  function request(url, method, body){
    var options = {
      method: method,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      }
    };

    if (body) options.body = JSON.stringify(body);

    return fetch(url, options).then(function(response){
      return response.json().catch(function(){
        return {};
      }).then(function(parsed){
        return { ok: response.ok, status: response.status, body: parsed };
      });
    });
  }

  /**
   * Текст ошибки из ответа REST.
   *
   * @param {Object} result Результат request().
   * @returns {string}
   */
  function errorText(result){
    var body = result.body || {};

    return body.message || 'Не удалось сохранить событие. Проверьте поля и попробуйте ещё раз.';
  }

  // --- Сохранение ---------------------------------------------------------

  form.addEventListener('submit', function(e){
    e.preventDefault();

    if (locked) return;

    setBusy(true);
    setStatus('', false);

    var url = eventId ? collectionUrl + '/' + eventId : collectionUrl;
    var method = eventId ? 'PUT' : 'POST';

    request(url, method, collectPayload())
      .then(function(result){
        setBusy(false);

        if (!result.ok){
          setStatus(errorText(result), true);
          return;
        }

        var data = (result.body && result.body.data) || {};

        // После создания сервер вернул новый ID — переходим в режим правки,
        // чтобы повторное сохранение обновляло тот же черновик, а не плодило
        // новые. Адрес меняем через replaceState, чтобы не оставлять в
        // истории кнопку «Назад» на уже сохранённый черновик.
        if (!eventId && data.id){
          eventId = parseInt(data.id, 10) || 0;
          form.setAttribute('data-idpg-event-id', String(eventId));

          if (window.history && window.history.replaceState){
            window.history.replaceState({}, '', data.permalink || window.location.href);
          }
        }

        setStatus(result.body && result.body.message ? result.body.message : 'Событие сохранено.', false);
      })
      .catch(function(){
        setBusy(false);
        setStatus('Не удалось связаться с сервером. Попробуйте ещё раз.', true);
      });
  });

  // --- Отправка на модерацию ---------------------------------------------

  var submitBtn = root.querySelector('[data-idpg-event-submit]');

  if (submitBtn){
    submitBtn.addEventListener('click', function(){
      if (!eventId) return;

      setBusy(true);

      request(collectionUrl + '/' + eventId + '/submit', 'POST', null)
        .then(function(result){
          setBusy(false);

          if (!result.ok){
            setStatus(errorText(result), true);
            return;
          }

          // Жизненный цикл меняется на сервере, поэтому перечитываем
          // страницу: иначе форма продолжит предлагать правку того, что
          // теперь на модерации.
          window.location.reload();
        })
        .catch(function(){
          setBusy(false);
          setStatus('Не удалось связаться с сервером. Попробуйте ещё раз.', true);
        });
    });
  }

  // --- Удаление -----------------------------------------------------------

  var deleteBtn = root.querySelector('[data-idpg-event-delete]');

  if (deleteBtn){
    deleteBtn.addEventListener('click', function(){
      if (!eventId) return;
      if (!window.confirm('Удалить черновик безвозвратно?')) return;

      setBusy(true);

      request(collectionUrl + '/' + eventId, 'DELETE', null)
        .then(function(result){
          setBusy(false);

          if (!result.ok){
            setStatus(errorText(result), true);
            return;
          }

          var backUrl = (deleteBtn.getAttribute('data-idpg-event-back') || '') || '';
          window.location.href = backUrl || '/organizer/events/';
        })
        .catch(function(){
          setBusy(false);
          setStatus('Не удалось удалить событие. Попробуйте ещё раз.', true);
        });
    });
  }

  // --- Шаги ---------------------------------------------------------------

  var panels = form.querySelectorAll('.create-event__panel');
  var stepNodes = root.querySelectorAll('[data-idpg-event-step]');
  var progress = root.querySelector('[data-idpg-event-progress]');
  var progressFill = progress ? progress.querySelector('.create-event__progress-fill') : null;
  var currentStep = 0;

  /**
   * Показать шаг и пересчитать прогресс.
   *
   * @param {number} index Номер шага, с нуля.
   */
  function showStep(index){
    var i;

    if (index < 0) index = 0;
    if (index > panels.length - 1) index = panels.length - 1;

    currentStep = index;

    for (i = 0; i < panels.length; i++){
      panels[i].classList.toggle('create-event__panel--active', i === index);
    }

    for (i = 0; i < stepNodes.length; i++){
      stepNodes[i].classList.toggle('create-event__step--active', i === index);
      stepNodes[i].classList.toggle('create-event__step--done', i < index);
    }

    if (progress){
      progress.setAttribute('aria-valuenow', String(index + 1));
    }

    if (progressFill && panels.length){
      progressFill.style.width = ( ((index + 1) / panels.length) * 100 ) + '%';
    }

    // Шаг не в форме — возвращаем фокус на его заголовок, иначе клавиатура
    // и скринридер теряют позицию после переключения.
    var heading = panels[index].querySelector('.create-event__label');
    if (heading && heading.setAttribute) heading.setAttribute('tabindex', '-1');
  }

  root.addEventListener('click', function(e){
    var target = e.target;

    if (!target || !target.getAttribute) return;

    if (target.hasAttribute('data-idpg-event-step-next')){
      showStep(currentStep + 1);
      return;
    }

    if (target.hasAttribute('data-idpg-event-step-prev')){
      showStep(currentStep - 1);
    }
  });

  // Обязательные поля живут на разных шагах, а браузер блокирует отправку
  // по первому невалидному полю, оставляя его на скрытой панели. Событие
  // invalid не всплывает, поэтому ловим его на форме в фазе захвата и
  // показываем панель, которой принадлежит поле.
  form.addEventListener('invalid', function(e){
    var target = e.target;
    var panel;

    if (!target || !target.closest) return;

    panel = target.closest('.create-event__panel');
    if (!panel) return;

    var i;
    for (i = 0; i < panels.length; i++){
      if (panels[i] === panel) showStep(i);
    }
  }, true);

  showStep(0);

  // --- Сеансы -------------------------------------------------------------

  var sessionsList = form.querySelector('[data-idpg-event-sessions-list]');
  var sessionAdd = form.querySelector('[data-idpg-event-session-add]');

  /**
   * Шаблон новой строки сеанса. Создаётся из существующей разметки, чтобы
   * не дублировать верстку строки в JS и в шаблоне.
   *
   * @returns {Element|null}
   */
  function newSessionRow(){
    var first = sessionsList ? sessionsList.querySelector('[data-idpg-event-session-row]') : null;

    if (first){
      var clone = first.cloneNode(true);

      // Клонируем только «пустую» строку: значения из первой сессии иначе
      // скопируются во все следующие.
      var inputs = clone.querySelectorAll('input');
      for (var i = 0; i < inputs.length; i++) inputs[i].value = '';

      return clone;
    }

    // Строк ещё нет — собираем минимальную разметку вручную.
    var row = document.createElement('div');
    row.className = 'create-event__row';
    row.setAttribute('data-idpg-event-session-row', '');

    var time = document.createElement('input');
    time.className = 'create-event__input';
    time.type = 'text';
    time.name = 'session_time[]';

    var price = document.createElement('input');
    price.className = 'create-event__input';
    price.type = 'number';
    price.min = '0';
    price.step = '0.01';
    price.name = 'session_price[]';

    var url = document.createElement('input');
    url.className = 'create-event__input';
    url.type = 'url';
    url.name = 'session_url[]';
    url.placeholder = 'https://';

    var remove = document.createElement('button');
    remove.className = 'create-event__btn';
    remove.type = 'button';
    remove.setAttribute('data-idpg-event-session-remove', '');
    remove.textContent = 'Убрать';

    row.appendChild(time);
    row.appendChild(price);
    row.appendChild(url);
    row.appendChild(remove);

    return row;
  }

  if (sessionAdd && sessionsList){
    sessionAdd.addEventListener('click', function(){
      sessionsList.appendChild(newSessionRow());
    });
  }

  form.addEventListener('click', function(e){
    var target = e.target;

    if (!target || !target.hasAttribute || !target.hasAttribute('data-idpg-event-session-remove')) return;

    var row = target.closest ? target.closest('[data-idpg-event-session-row]') : null;
    if (row) row.parentNode.removeChild(row);
  });

  // --- Условная видимость (DEV-97) ---------------------------------------
  //
  // Поля зависят друг от друга, поэтому показ пересчитывается целиком при
  // любом изменении. Скрытие сделано через hidden, а не display: класс
  // create-event__panel уже управляет display, и смешивать два механизма
  // нельзя — скрытый блок не должен ни показываться, ни участвовать в
  // проверке required.

  var ticketsBox   = form.querySelector('[data-idpg-tickets]');
  var sessionsWrap = form.querySelector('[data-idpg-sessions-wrap]');
  var ticketTypeEl = form.querySelector('[data-idpg-ticket-type]');

  /**
   * Показать или спрятать набор полей.
   *
   * @param {Element} box Контейнер.
   * @param {boolean} visible Показывать ли.
   */
  function toggle(box, visible){
    if (box) box.hidden = !visible;
  }

  /**
   * Пересчитать видимость всех зависимых блоков.
   */
  function refreshConditions(){
    var isFree     = valueOf('[name="is_free"]:checked', 'no') === 'yes';
    var allDay     = valueOf('[name="all_day"]:checked', 'no') === 'yes';
    var isOnline   = valueOf('[name="is_online"]:checked', 'no') === 'yes';
    var ticketType = value('[name="ticket_type"]');

    // Бесплатное событие: цены и билеты не имеют смысла, карточка всё
    // равно печатает «Вход свободный».
    toggle(ticketsBox, !isFree);

    // «Весь день» — время сеансов не нужно.
    toggle(sessionsWrap, !allDay);

    // Ссылки на трансляцию показываем только для онлайн-события.
    var online = form.querySelector('[data-idpg-when-online]');
    toggle(online, isOnline);

    // По типу билета: внешняя ссылка — один адрес; виджет — код вставки
    // плюс, у Яндекс.Афиш, ещё и ссылка на форму.
    toggle(form.querySelector('[data-idpg-when-ticket-url]'), ticketType === 'external_link');
    toggle(form.querySelectorAll('[data-idpg-when-widget]'), ticketType === 'widget_yandex');
  }

  form.addEventListener('change', refreshConditions);
  refreshConditions();

  // Обязательность полей меняется вместе с видимостью: скрытое поле с
  // required не даст отправить форму, а его значение всё равно не нужно.
  function refreshRequired(){
    var type = value('[name="ticket_type"]');
    var urlEl = form.querySelector('[name="ticket_url"]');
    var codeEl = form.querySelector('[name="widget_code"]');

    if (urlEl) urlEl.required = (type === 'external_link');
    if (codeEl) codeEl.required = (type === 'widget_yandex');
  }

  form.addEventListener('change', refreshRequired);
  refreshRequired();

  // --- Постер и галерея (DEV-97) -----------------------------------------
  //
  // Загрузка идёт через wp.media, который подключается в шаблоне только
  // когда у пользователя есть upload_files. Без него блоки не инициализируются,
  // а в форме показана подсказка вместо кнопок.

  var galleryIds = [];
  var galleryPreview = form.querySelector('[data-idpg-gallery-preview]');

  if (galleryPreview){
    var rawIds = galleryPreview.getAttribute('data-idpg-gallery-ids');

    try {
      var parsed = JSON.parse(rawIds || '[]');
      if (Object.prototype.toString.call(parsed) === '[object Array]') galleryIds = parsed;
    } catch (e) {
      galleryIds = [];
    }

    renderGallery();
  }

  /**
   * Отрисовать миниатюры галереи.
   *
   * @param {Array} attachments Выбранные вложения (id + url).
   */
  function renderGallery(attachments){
    if (!galleryPreview) return;

    galleryPreview.textContent = '';

    var list = galleryIds;
    var i;

    for (i = 0; i < list.length; i++){
      var item = document.createElement('div');
      item.className = 'create-event__upload-item';

      var url = attachments && attachments[i] ? attachments[i].url : '';
      if (url){
        var img = document.createElement('img');
        img.src = url;
        img.alt = '';
        img.className = 'create-event__upload-preview';
        item.appendChild(img);
      }

      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'create-event__upload-remove';
      del.textContent = '×';
      del.setAttribute('data-idpg-gallery-remove', String(list[i]));
      item.appendChild(del);

      galleryPreview.appendChild(item);
    }
  }

  if (window.wp && window.wp.media && form.getAttribute('data-idpg-can-upload') === '1'){
    var frame;

    // Постер.
    var posterBtn = form.querySelector('[data-idpg-poster-select]');
    var posterInput = form.querySelector('[data-idpg-poster-input]');
    var posterPreview = form.querySelector('[data-idpg-poster-preview]');
    var posterRemove = form.querySelector('[data-idpg-poster-remove]');

    if (posterBtn){
      posterBtn.addEventListener('click', function(e){
        e.preventDefault();

        if (frame) { frame.open(); return; }

        frame = window.wp.media({ multiple: false, library: { type: 'image' } });

        frame.on('select', function(){
          var item = frame.state().get('selection').first().toJSON();
          if (posterInput) posterInput.value = item.id;
          if (posterPreview){
            posterPreview.textContent = '';
            var img = document.createElement('img');
            img.src = (item.sizes && item.sizes.medium ? item.sizes.medium.url : item.url);
            img.alt = '';
            posterPreview.appendChild(img);
          }
          if (posterRemove) posterRemove.hidden = false;
        });

        frame.open();
      });
    }

    if (posterRemove){
      posterRemove.addEventListener('click', function(){
        if (posterInput) posterInput.value = '';
        if (posterPreview) posterPreview.textContent = '';
        posterRemove.hidden = true;
      });
    }

    // Загрузка файлом — без медиатеки, если перетаскивание недоступно.
    var fileInput = form.querySelector('.create-event__file-input');

    if (fileInput){
      fileInput.addEventListener('change', function(){
        if (!window.FormData) return;

        var data = new FormData();
        data.append('action', 'upload-attachment');
        data.append('async-submit', '1');
        data.append('file', fileInput.files && fileInput.files[0]);

        window.fetch(window.location.origin + '/wp-admin/admin-ajax.php', {
          method: 'POST',
          credentials: 'same-origin',
          body: data
        })
          .then(function(r){ return r.json(); })
          .then(function(res){
            if (!res || !res.success || !res.data){ return; }
            if (posterInput) posterInput.value = res.data.id;
            if (posterPreview){
              posterPreview.textContent = '';
              var img = document.createElement('img');
              img.src = res.data.url;
              img.alt = '';
              posterPreview.appendChild(img);
            }
            if (posterRemove) posterRemove.hidden = false;
          })
          .catch(function(){});
      });
    }

    // Галерея.
    var galleryBtn = form.querySelector('[data-idpg-gallery-select]');
    var galleryClear = form.querySelector('[data-idpg-gallery-clear]');
    var galleryFrame;
    var lastAttachments = [];

    if (galleryBtn){
      galleryBtn.addEventListener('click', function(e){
        e.preventDefault();

        if (galleryFrame){ galleryFrame.open(); return; }

        galleryFrame = window.wp.media({ multiple: 'add', library: { type: 'image' } });

        galleryFrame.on('select', function(){
          lastAttachments = galleryFrame.state().get('selection').toJSON();

          galleryIds = lastAttachments.map(function(a){ return a.id; });
          renderGallery(lastAttachments);
        });

        galleryFrame.open();
      });
    }

    if (galleryClear){
      galleryClear.addEventListener('click', function(){
        galleryIds = [];
        lastAttachments = [];
        renderGallery([]);
      });
    }

    form.addEventListener('click', function(e){
      var t = e.target;
      if (!t || !t.getAttribute) return;

      var removeId = t.getAttribute('data-idpg-gallery-remove');
      if (!removeId) return;

      var next = [];
      var nextAttachments = [];
      var i;

      for (i = 0; i < galleryIds.length; i++){
        if (String(galleryIds[i]) === String(removeId)) continue;
        next.push(galleryIds[i]);
        nextAttachments.push(lastAttachments[i] || null);
      }

      galleryIds = next;
      lastAttachments = nextAttachments;
      renderGallery(lastAttachments);
    });
  }

  // --- Площадка из формы события (DEV-50) -------------------------------
  //
  // Отдельную форму внутри формы события вставить нельзя, поэтому
  // площадка заводится модальным окном. Состав полей — контейнер
  // «Данные площадки» в docs/carbon-fields-export.json.

  var venueBtn = form.querySelector('[data-idpg-venue-new]');
  var venuesUrl = form.getAttribute('data-idpg-venues-url');
  var venueSelect = form.querySelector('[name="venue"]');

  if (venueBtn && venuesUrl && venueSelect){
    var modal = null;
    var modalBody = null;

    /**
     * Создать (или показать) модальное окно площадки.
     */
    function openVenueModal(){
      if (!modal) buildVenueModal();

      modal.hidden = false;
      var first = modal.querySelector('input');
      if (first) first.focus();
    }

    /**
     * Разметка модального окна.
     */
    function buildVenueModal(){
      modal = document.createElement('div');
      modal.className = 'idpg-venue-modal';
      modal.hidden = true;
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');

      modalBody = document.createElement('div');
      modalBody.className = 'idpg-venue-modal__body';
      modalBody.innerHTML =
        '<h2 class="idpg-venue-modal__title">Новая площадка</h2>' +
        '<div class="create-event__field"><label class="create-event__label">Название' +
        '<input class="create-event__input" type="text" name="venue_title"></label></div>' +
        '<div class="create-event__field"><label class="create-event__label">Тип' +
        '<select class="create-event__select" name="venue_type">' +
        '<option value="">— Не выбран —</option>' +
        '<option value="theater">Театр</option>' +
        '<option value="cinema">Кинотеатр</option>' +
        '<option value="museum">Музей</option>' +
        '<option value="concert">Концертный зал</option>' +
        '</select></label></div>' +
        '<div class="create-event__field"><label class="create-event__label">Адрес' +
        '<input class="create-event__input" type="text" name="venue_address"></label></div>' +
        '<div class="create-event__field"><label class="create-event__label">Телефон' +
        '<input class="create-event__input" type="text" name="venue_phone"></label></div>' +
        '<div class="create-event__field"><label class="create-event__label">E-mail' +
        '<input class="create-event__input" type="email" name="venue_email"></label></div>' +
        '<div class="create-event__field"><label class="create-event__label">Сайт' +
        '<input class="create-event__input" type="url" name="venue_website" placeholder="https://"></label></div>' +
        '<div class="create-event__field"><label class="create-event__label">Вместимость' +
        '<input class="create-event__input" type="number" min="0" name="venue_capacity"></label></div>' +
        '<div class="create-event__row">' +
        '<div class="create-event__field"><label class="create-event__label">Широта' +
        '<input class="create-event__input" type="text" name="venue_lat"></label></div>' +
        '<div class="create-event__field"><label class="create-event__label">Долгота' +
        '<input class="create-event__input" type="text" name="venue_lng"></label></div>' +
        '</div>' +
        '<p class="idpg-venue-modal__error" data-idpg-venue-error hidden></p>' +
        '<div class="create-event__nav">' +
        '<button class="create-event__btn" type="button" data-idpg-venue-cancel>Отмена</button>' +
        '<button class="create-event__btn" type="submit" data-idpg-venue-save>Создать</button>' +
        '</div>';

      modal.appendChild(modalBody);
      root.appendChild(modal);

      modal.addEventListener('click', function(e){
        if (e.target === modal) closeVenueModal();
      });

      modal.querySelector('[data-idpg-venue-cancel]').addEventListener('click', closeVenueModal);
      modal.querySelector('[data-idpg-venue-save]').addEventListener('click', saveVenue);
    }

    /**
     * Закрыть модальное окно.
     */
    function closeVenueModal(){
      if (modal) modal.hidden = true;
    }

    /**
     * Создать площадку через REST и добавить её в список события.
     */
    function saveVenue(){
      var errorBox = modal.querySelector('[data-idpg-venue-error]');
      var payload = {
        title: (modal.querySelector('[name="venue_title"]') || {}).value || '',
        venue_type: (modal.querySelector('[name="venue_type"]') || {}).value || '',
        venue_address: (modal.querySelector('[name="venue_address"]') || {}).value || '',
        venue_phone: (modal.querySelector('[name="venue_phone"]') || {}).value || '',
        venue_email: (modal.querySelector('[name="venue_email"]') || {}).value || '',
        venue_website: (modal.querySelector('[name="venue_website"]') || {}).value || '',
        venue_capacity: (modal.querySelector('[name="venue_capacity"]') || {}).value || '',
        venue_lat: (modal.querySelector('[name="venue_lat"]') || {}).value || '',
        venue_lng: (modal.querySelector('[name="venue_lng"]') || {}).value || '',
        city: (form.querySelector('[name="city"]') || {}).value || 0
      };

      if (errorBox){ errorBox.hidden = true; errorBox.textContent = ''; }

      if (!payload.title.trim()){
        if (errorBox){ errorBox.hidden = false; errorBox.textContent = 'Укажите название площадки.'; }
        return;
      }

      request(venuesUrl, 'POST', payload)
        .then(function(result){
          if (!result.ok){
            if (errorBox){
              errorBox.hidden = false;
              errorBox.textContent = errorText(result);
            }
            return;
          }

          var data = (result.body && result.body.data) || {};

          if (data.id && data.title){
            var option = document.createElement('option');
            option.value = String(data.id);
            option.textContent = data.title;
            venueSelect.appendChild(option);
            venueSelect.value = String(data.id);
          }

          closeVenueModal();
        })
        .catch(function(){
          if (errorBox){
            errorBox.hidden = false;
            errorBox.textContent = 'Не удалось связаться с сервером.';
          }
        });
    }

    venueBtn.addEventListener('click', function(e){
      e.preventDefault();
      openVenueModal();
    });
  }
})();
