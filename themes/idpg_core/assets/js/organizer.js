/**
 * Кабинет организатора — переключение разделов без перезагрузки страницы.
 *
 * Разделы уже отрендерены сервером: активный виден, остальные скрыты
 * атрибутом hidden. Скрипт только переключает видимость и обновляет адрес
 * через history.pushState. Без JavaScript работает прямой заход по адресу
 * раздела — сервер отдаёт нужный раздел активным.
 */
(function(){
  'use strict';

  var root = document.querySelector('.idpg-org-page');
  if (!root) return;

  // Профиль подключаем до раннего return ниже: форма должна работать
  // независимо от того, нашлось ли меню разделов.
  initProfileForm(root);

  // Счётчики и список событий (G6) — тоже до раннего return: разделы
  // «Дашборд» и «Мои события» должны наполниться, даже если меню пустое
  // (его может отфильтровать плагин).
  //
  // Подписи типов площадки объявлены здесь, а рядом с функциями ниже: сам
  // ранний return на строке ниже не дал бы дойти до объявления, и список
  // площадок падал бы с «undefined» ровно в том случае, когда меню
  // отфильтровано. Объявления функций `var` поднимает наверх, а значение
  // `var` — нет, поэтому переносить нужно именно присваивание.
  //
  // Подписи берутся из контейнера «Данные площадки» в
  // docs/carbon-fields-export.json.
  var VENUE_TYPE_LABELS = {
    theater: 'Театр',
    cinema: 'Кинотеатр',
    museum: 'Музей',
    concert: 'Концертный зал'
  };

  initStats(root);
  initEvents(root);
  initVenues(root);

  var links = root.querySelectorAll('[data-idpg-org-section]');
  var panels = root.querySelectorAll('[data-idpg-org-panel]');
  if (!links.length || !panels.length) return;

  function panelFor(section){
    return root.querySelector('[data-idpg-org-panel="' + section + '"]');
  }

  function linkFor(section){
    return root.querySelector('[data-idpg-org-section="' + section + '"]');
  }

  /**
   * Раздел по текущему адресу. База /organizer/ отдаёт раздел по умолчанию —
   * первый в разметке.
   */
  function sectionFromUrl(){
    var match = window.location.pathname.match(/\/organizer\/([a-z0-9_-]+)\/?$/i);
    if (match && panelFor(match[1])) return match[1];

    return panels.length ? panels[0].getAttribute('data-idpg-org-panel') : '';
  }

  /**
   * Показать раздел. push = true — обновить адрес (клик по меню).
   */
  function activate(section, push){
    var panel = panelFor(section);
    if (!panel) return false;

    Array.prototype.forEach.call(panels, function(el){
      el.hidden = (el !== panel);
    });

    Array.prototype.forEach.call(links, function(el){
      if (el.getAttribute('data-idpg-org-section') === section){
        el.classList.add('is-active');
        el.setAttribute('aria-current', 'page');
      } else {
        el.classList.remove('is-active');
        el.removeAttribute('aria-current');
      }
    });

    if (push && window.history && window.history.pushState){
      var link = linkFor(section);
      if (link) window.history.pushState({ idpgOrgSection: section }, '', link.href);
    }

    // Перевод фокуса на заголовок раздела — для клавиатуры и скринридеров.
    var title = panel.querySelector('.idpg-org-section__title');
    if (title){
      title.setAttribute('tabindex', '-1');
      try { title.focus({ preventScroll: true }); } catch (e) { title.focus(); }
    }

    return true;
  }

  Array.prototype.forEach.call(links, function(link){
    link.addEventListener('click', function(e){
      // Клик с модификатором или не левой кнопкой — не перехватываем,
      // пусть браузер откроет ссылку как обычно.
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

      var section = link.getAttribute('data-idpg-org-section');
      if (!section) return;

      e.preventDefault();
      activate(section, true);
    });
  });

  // ---------------------------------------------------------------------------
  // Профиль организатора (DEV-73)
  //
  // Форма работает и без этого блока — тогда она уходит обычным POST на
  // страницу кабинета. Здесь перехватываем submit, чтобы сохранять через
  // PUT /idpg/v1/organizer/profile без перезагрузки.
  // ---------------------------------------------------------------------------

  // Кнопки «назад/вперёд» браузера — синхронизируем вид с адресом.
  window.addEventListener('popstate', function(){
    activate(sectionFromUrl(), false);
  });

  /**
   * Подключение сохранения профиля без перезагрузки.
   *
   * @param {Element} root Корневой элемент кабинета.
   */
  function initProfileForm(root){
    var form = root.querySelector('[data-idpg-profile-form]');
    if (!form) return;

    var pending = form.querySelector('[data-idpg-profile-pending]');
    var submit = form.querySelector('button[type="submit"]');

    function setStatus(message, isError){
      if (!message) return;

      var box = form.querySelector('[data-idpg-profile-status]');
      if (!box){
        box = document.createElement('div');
        box.setAttribute('data-idpg-profile-status', '');
        form.insertBefore(box, form.firstChild);
      }
      box.className = 'idpg-notice ' + (isError ? 'idpg-notice--error' : 'idpg-notice--success');
      box.setAttribute('role', isError ? 'alert' : 'status');
      box.textContent = message;
    }

    function setBusy(busy){
      if (pending) pending.hidden = !busy;
      if (submit) submit.disabled = busy;
    }

    form.addEventListener('submit', function(e){
      e.preventDefault();

      var restUrl = form.getAttribute('data-idpg-profile-rest');
      var nonce = form.getAttribute('data-idpg-profile-nonce');
      if (!restUrl || !nonce || !window.fetch) return; // откат к обычному POST

      var payload = {};
      var data = new FormData(form);

      // idpg_org_profile_submit / idpg_org_profile_nonce — только для
      // no-JS пути, REST их не принимает.
      ['display_name', 'org_name', 'contact_name', 'phone', 'avatar_id', 'legal_type', 'inn'].forEach(function(name){
        if (data.has(name)) payload[name] = data.get(name);
      });

      if (data.has('documents')) {
        try {
          payload.documents = JSON.parse(data.get('documents'));
        } catch (e) {
          payload.documents = [];
        }
      }

      setBusy(true);

      fetch(restUrl, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce
        },
        body: JSON.stringify(payload)
      })
        .then(function(response){
          return response.json().then(function(body){
            return { ok: response.ok, body: body };
          });
        })
        .then(function(result){
          setBusy(false);

          if (!result.ok){
            var message = result.body && result.body.message
              ? result.body.message
              : 'Не удалось сохранить профиль.';

            setStatus(message, true);
            return;
          }

          // Подставляем то, что реально записал сервер (обрезанные длины,
          // нормализованные значения), иначе форма продолжит показывать
          // введённое, а не сохранённое.
          if (result.body){
            Object.keys(result.body).forEach(function(key){
              var field = form.querySelector('[name="' + key + '"]');
              if (field && 'value' in field) field.value = result.body[key];
            });
          }

          setStatus('Профиль сохранён.', false);
        })
        .catch(function(){
          setBusy(false);
          setStatus('Не удалось связаться с сервером. Попробуйте ещё раз.', true);
        });
    });
  }

  // ---------------------------------------------------------------------------
  // Дашборд и список событий (G6)
  //
  // Данные берём из готовых маршрутов плагина:
  //   GET /idpg/v1/organizer/stats   — счётчики для карточек и вкладок;
  //   GET /idpg/v1/organizer/events  — список с фильтром и пагинацией.
  //
  // Запросы не дублируют серверную логику: подсчёты и выборку статусов
  // делает IDPG_REST_Organizer, здесь только отрисовка. Счётчики нужны
  // обоим разделам, поэтому stats запрашивается один раз и кладётся в кэш.
  // ---------------------------------------------------------------------------

  /**
   * Метки статусов. Русские подписи и классы бейджей — из
   * `_dashboard.scss` (`.dashboard__badge--*`).
   */
  var STATUS_META = {
    publish:  { label: 'Опубликовано', badge: 'dashboard__badge--publish' },
    pending:  { label: 'На модерации', badge: 'dashboard__badge--pending' },
    draft:    { label: 'Черновик', badge: 'dashboard__badge--draft' },
    rejected: { label: 'Отклонено', badge: 'dashboard__badge--draft' }
  };

  /**
   * Запрос к REST с nonce и общим разбором ответа.
   *
   * @param {string} url Адрес.
   * @param {string} nonce wp_rest nonce.
   * @returns {Promise<Object>} Тело ответа.
   */
  function restGet(url, nonce){
    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': nonce }
    })
      .then(function(response){
        return response.json().catch(function(){
          return {};
        }).then(function(body){
          if (!response.ok){
            throw new Error((body && body.message) || ('HTTP ' + response.status));
          }
          return body;
        });
      });
  }

  /**
   * Кэш счётчиков организатора: один запрос на страницу.
   *
   * Кэшируем именно промис, а не распакованные данные: дашборд и список
   * событий стартуют одновременно, и при кэше результата второй вызов
   * успевал бы уйти на сервер, пока первый запрос ещё в полёте. Промис
   * схлопывает и повторный, и параллельный запросы.
   *
   * Объявление стоит ниже по файлу, но `var` поднимает его наверх, так что
   * к моменту вызовов initStats/initEvents переменная уже существует. Важно
   * именно это: если бы она была не `var`, а `let`/`const` ниже вызовов,
   * обращение дало бы ReferenceError из-за временной мёртвой зоны.
   *
   * @type {Promise|null}
   */
  var statsPromise = null;

  /**
   * Запросить счётчики, отдавая кэш при повторном обращении.
   *
   * @param {string} url Адрес stats.
   * @param {string} nonce wp_rest nonce.
   * @returns {Promise<Object>} {counts, total, views, clicks}.
   */
  function loadStats(url, nonce){
    if (statsPromise) return statsPromise;

    statsPromise = restGet(url, nonce)
      .then(function(body){
        return (body && body.data) || {};
      })
      // Ошибку не кэшируем: при перезаходе по вкладкам повтор должен
      // иметь право попробовать снова.
      .catch(function(err){
        statsPromise = null;
        throw err;
      });

    return statsPromise;
  }

  /**
   * Показать/спрятать блок ошибки раздела.
   *
   * @param {Element} box Блок ошибки.
   * @param {string} message Текст; пустой — спрятать.
   */
  function setError(box, message){
    if (!box) return;

    box.textContent = message || '';
    box.hidden = !message;
  }

  /**
   * Подставить числа в карточки дашборда.
   *
   * @param {Element} root Раздел дашборда.
   * @param {Object} stats Данные stats.
   */
  function renderStats(root, stats){
    var counts = stats.counts || {};
    var values = {
      total: stats.total,
      publish: counts.publish,
      pending: counts.pending,
      draft: counts.draft
    };

    Object.keys(values).forEach(function(slot){
      var node = root.querySelector('[data-idpg-stats-value="' + slot + '"]');
      // 0 — это «ничего не опубликовано», а не «нет данных»: показываем ноль.
      if (node) node.textContent = String(typeof values[slot] === 'number' ? values[slot] : 0);
    });
  }

  /**
   * Подключение дашборда.
   *
   * @param {Element} root Корневой элемент кабинета.
   */
  function initStats(root){
    var box = root.querySelector('[data-idpg-stats]');
    if (!box) return;

    var url = box.getAttribute('data-idpg-stats-url');
    var nonce = box.getAttribute('data-idpg-stats-nonce');
    if (!url || !nonce || !window.fetch) return;

    var errorBox = box.querySelector('[data-idpg-stats-error]');

    loadStats(url, nonce)
      .then(function(stats){
        renderStats(box, stats);
        setError(errorBox, '');
      })
      .catch(function(err){
        setError(errorBox, err.message || 'Не удалось загрузить статистику.');
      });
  }

  /**
   * Подключение списка событий: фильтр + пагинация + строки таблицы.
   *
   * @param {Element} root Корневой элемент кабинета.
   */
  function initEvents(root){
    var box = root.querySelector('[data-idpg-events]');
    if (!box) return;

    var url = box.getAttribute('data-idpg-events-url');
    var statsUrl = box.getAttribute('data-idpg-stats-url');
    var nonce = box.getAttribute('data-idpg-events-nonce');
    var editBase = box.getAttribute('data-idpg-events-edit-base');
    if (!url || !nonce || !window.fetch) return;

    var body = box.querySelector('[data-idpg-events-body]');
    var empty = box.querySelector('[data-idpg-events-empty]');
    var errorBox = box.querySelector('[data-idpg-events-error]');
    var pagination = box.querySelector('[data-idpg-events-pagination]');
    var pageLabel = box.querySelector('[data-idpg-events-page-label]');
    var prevBtn = box.querySelector('[data-idpg-events-prev]');
    var nextBtn = box.querySelector('[data-idpg-events-next]');
    var filters = box.querySelectorAll('[data-idpg-events-filter]');

    // Состояние живёт в адресе: ссылкой можно поделиться, а «назад» его не
    // теряет. Начальное — из текущего адреса.
    var state = readStateFromUrl();

    /**
     * Прочитать status/p из адреса.
     *
     * @returns {Object} {status, page}.
     */
    function readStateFromUrl(){
      var params = new URLSearchParams(window.location.search);
      var status = params.get('status') || 'all';
      var page = parseInt(params.get('p'), 10) || 1;

      if (['all', 'publish', 'pending', 'draft', 'rejected'].indexOf(status) === -1) status = 'all';
      if (page < 1) page = 1;

      return { status: status, page: page };
    }

    /**
     * Отразить состояние в адресе без перехода.
     */
    function pushStateToUrl(){
      if (!window.history || !window.history.replaceState) return;

      var params = new URLSearchParams(window.location.search);
      params.delete('status');
      params.delete('p');

      if (state.status !== 'all') params.set('status', state.status);
      if (state.page > 1) params.set('p', String(state.page));

      var query = params.toString();
      window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : ''));
    }

    /**
     * Подсветить активный фильтр.
     */
    function renderFilters(){
      Array.prototype.forEach.call(filters, function(btn){
        btn.classList.toggle(
          'dashboard__filter-btn--active',
          btn.getAttribute('data-idpg-events-filter') === state.status
        );
      });
    }

    /**
     * Показать счётчики на вкладках фильтра.
     *
     * @param {Object} stats Данные stats.
     */
    function renderFilterCounts(stats){
      var counts = stats.counts || {};
      var total = typeof stats.total === 'number'
        ? stats.total
        : Object.keys(counts).reduce(function(sum, key){ return sum + (counts[key] || 0); }, 0);

      var numbers = {
        all: total,
        publish: counts.publish,
        pending: counts.pending,
        draft: counts.draft,
        rejected: counts.rejected
      };

      Object.keys(numbers).forEach(function(slot){
        var node = box.querySelector('[data-idpg-events-count="' + slot + '"]');
        if (node) node.textContent = String(numbers[slot] || 0);
      });
    }

    /**
     * Картинка события. REST отдаёт poster_id, но не URL, поэтому URL берём
     * из медиатеки (см. loadPosterUrls). Без ID — пустой контейнер-заглушка,
     * чтобы строка не прыгала по высоте после загрузки.
     *
     * @param {number} posterId ID вложения.
     * @returns {Element} Элемент-контейнер с img или без него.
     */
    function posterCell(posterId){
      var cell = document.createElement('div');
      cell.className = 'dashboard__table-thumb';

      var url = attachmentUrl(posterId);
      if (!url) return cell;

      var img = document.createElement('img');
      img.src = url;
      img.alt = '';
      img.loading = 'lazy';
      cell.appendChild(img);

      return cell;
    }

    /**
     * Кэш attachment-URL: один запрос на страницу вместо запроса на строку.
     *
     * @type {Object}
     */
    var attachmentUrls = {};

    /**
     * URL вложения из кэша.
     *
     * @param {number} id ID вложения.
     * @returns {string} Пустая строка, если URL ещё не получен.
     */
    function attachmentUrl(id){
      return attachmentUrls[id] || '';
    }

    /**
     * Подтянуть URL постеров одним запросом на страницу.
     *
     * Для организатора медиа-эндпоинт может быть закрыт (у роли нет
     * upload_files) — тогда остаются заглушки, а список всё равно
     * показывается. Ошибку здесь глушим намеренно: отсутствие картинки не
     * повод заменять список сообщением об ошибке.
     *
     * @param {Array} items События.
     * @returns {Promise}
     */
    function loadPosterUrls(items){
      var mediaUrl = box.getAttribute('data-idpg-media-url');
      if (!mediaUrl) return Promise.resolve();

      var ids = [];

      (items || []).forEach(function(item){
        var id = parseInt(item.poster_id, 10) || 0;
        if (id && !attachmentUrls[id] && ids.indexOf(id) === -1) ids.push(id);
      });

      if (!ids.length) return Promise.resolve();

      var query = '?include=' + ids.join(',') + '&per_page=' + Math.min(ids.length, 100);

      return restGet(mediaUrl + query, nonce)
        .then(function(media){
          (media || []).forEach(function(m){
            if (m && m.id && m.source_url) attachmentUrls[m.id] = m.source_url;
          });
        })
        .catch(function(){});
    }

    /**
     * Ссылка «Редактировать» на форму события.
     *
     * @param {Object} item Событие.
     * @returns {Element}
     */
    function editLink(item){
      var a = document.createElement('a');
      a.className = 'dashboard__table-title';
      a.href = editBase + item.id + '/';
      a.textContent = item.title || 'Без названия';
      return a;
    }

    /**
     * Ссылка на опубликованное событие (только для publish).
     *
     * @param {Object} item Событие.
     * @returns {Element|null}
     */
    function permalinkLink(item){
      if (item.status !== 'publish' || !item.permalink) return null;

      var a = document.createElement('a');
      a.className = 'dashboard__table-links';
      a.href = item.permalink;
      a.textContent = 'Открыть';
      a.target = '_blank';
      a.rel = 'noopener';
      return a;
    }

    /**
     * Перерисовать таблицу по ответу.
     *
     * @param {Object} data {items, total, pages}.
     */
    function render(data){
      var items = (data && data.items) || [];
      var total = (data && data.total) || 0;
      var pages = (data && data.pages) || 1;

      body.textContent = '';
      items.forEach(function(item){
        var tr = document.createElement('tr');

        var titleCell = document.createElement('td');
        titleCell.appendChild(posterCell(item.poster_id));
        var link = editLink(item);
        var wrap = document.createElement('div');
        wrap.className = 'dashboard__table-title';
        wrap.appendChild(link);
        var pl = permalinkLink(item);
        if (pl){
          var links = document.createElement('div');
          links.className = 'dashboard__table-links';
          links.appendChild(pl);
          wrap.appendChild(links);
        }
        titleCell.appendChild(wrap);
        tr.appendChild(titleCell);

        var statusCell = document.createElement('td');
        var meta = STATUS_META[item.status] || { label: item.status, badge: 'dashboard__badge--draft' };
        var badge = document.createElement('span');
        badge.className = 'dashboard__badge ' + meta.badge;
        badge.textContent = meta.label;
        statusCell.appendChild(badge);
        tr.appendChild(statusCell);

        var viewsCell = document.createElement('td');
        viewsCell.textContent = String(item.views || 0);
        tr.appendChild(viewsCell);

        body.appendChild(tr);
      });

      // Пусто только когда фильтр ничего не вернул: иначе «пока пусто» врёт
      // при активном поиске/фильтре.
      var showEmpty = (items.length === 0) && (state.status === 'all') && (state.page === 1);
      if (empty) empty.hidden = !showEmpty;

      if (pageLabel) pageLabel.textContent = state.page + ' / ' + Math.max(pages, 1);
      if (pagination) pagination.hidden = pages <= 1;
      if (prevBtn) prevBtn.disabled = state.page <= 1;
      if (nextBtn) nextBtn.disabled = state.page >= pages;
    }

    /**
     * Загрузить текущую страницу списка.
     */
    function load(){
      var params = new URLSearchParams();
      params.set('per_page', '20');
      params.set('page', String(state.page));
      if (state.status !== 'all') params.set('status', state.status);

      setError(errorBox, '');

      restGet(url + '?' + params.toString(), nonce)
        .then(function(data){
          return loadPosterUrls(data.items || []).then(function(){
            render(data);
          });
        })
        .catch(function(err){
          body.textContent = '';
          if (empty) empty.hidden = true;
          setError(errorBox, err.message || 'Не удалось загрузить список событий.');
        });
    }

    Array.prototype.forEach.call(filters, function(btn){
      btn.addEventListener('click', function(){
        state.status = btn.getAttribute('data-idpg-events-filter');
        state.page = 1;
        renderFilters();
        pushStateToUrl();
        load();
      });
    });

    if (prevBtn){
      prevBtn.addEventListener('click', function(){
        if (state.page <= 1) return;
        state.page--;
        pushStateToUrl();
        load();
      });
    }

    if (nextBtn){
      nextBtn.addEventListener('click', function(){
        state.page++;
        pushStateToUrl();
        load();
      });
    }

    renderFilters();
    load();

    // Счётчики на вкладках — из stats, параллельно со списком.
    if (statsUrl){
      loadStats(statsUrl, nonce)
        .then(function(stats){
          renderFilterCounts(stats);
        })
        .catch(function(){});
    }
  }

  // ---------------------------------------------------------------------------
  // Площадки организатора (DEV-50)
  //
  // Список приходит из GET /idpg/v1/organizer/venues. Рендерится здесь, а не
  // сервером: заполнение формы площадки идёт через REST, и список должен
  // отражать то же состояние, что и она.
  // ---------------------------------------------------------------------------

  /**
   * Наполнение раздела «Площадки».
   *
   * @param {Element} root Корневой элемент кабинета.
   */
  function initVenues(root){
    var box = root.querySelector('[data-idpg-venues]');
    if (!box) return;

    var url = box.getAttribute('data-idpg-venues-list-url');
    var nonce = box.getAttribute('data-idpg-venues-nonce');
    if (!url || !nonce || !window.fetch) return;

    var body = box.querySelector('[data-idpg-venues-body]');
    var table = box.querySelector('[data-idpg-venues-table]');
    var loading = box.querySelector('[data-idpg-venues-loading]');
    var empty = box.querySelector('[data-idpg-venues-empty]');
    var errorBox = box.querySelector('[data-idpg-venues-error]');

    restGet(url, nonce)
      .then(function(data){
        var items = (data && data.items) || [];
        var i;

        body.textContent = '';

        for (i = 0; i < items.length; i++){
          body.appendChild(venueRow(items[i]));
        }

        // Показано ровно одно состояние: пустой список показывать пустым
        // заголовком таблицы нельзя.
        if (loading) loading.hidden = true;
        if (table) table.hidden = items.length === 0;
        if (empty) empty.hidden = items.length !== 0;
        if (errorBox){ errorBox.hidden = true; errorBox.textContent = ''; }
      })
      .catch(function(err){
        body.textContent = '';

        if (loading) loading.hidden = true;
        if (table) table.hidden = true;
        if (empty) empty.hidden = true;
        setError(errorBox, err.message || 'Не удалось загрузить список площадок.');
      });
  }

  /**
   * Строка таблицы площадок.
   *
   * Ссылка на форму правки ставится только своим площадкам. В списке есть и
   * чужие опубликованные площадки (`is_own: false`) — их видно, чтобы можно
   * было выбрать уже существующую, но правки у организатора нет: заход в
   * такую форму упёрся бы в 403.
   *
   * @param {Object} venue Площадка.
   * @returns {Element} Элемент <tr>.
   */
  function venueRow(venue){
    var tr = document.createElement('tr');
    var titleCell = document.createElement('td');
    var typeCell = document.createElement('td');
    var addressCell = document.createElement('td');

    // Чужая опубликованная площадка: она видна, чтобы её можно было выбрать
    // в событии, но правки у организатора нет — клик по форме правки упёрся
    // бы в 403. Поэтому это текст, а не ссылка, и отдельный класс: общий
    // `dashboard__table-title` красит при наведении и выдаёт span за ссылку.
    if (!venue.is_own){
      var note = document.createElement('span');
      note.className = 'venue-form__note';
      note.textContent = 'общая';
      titleCell.appendChild(note);
    }

    var title = document.createElement(venue.is_own ? 'a' : 'span');
    title.className = 'venue-form__title';

    if (venue.is_own){
      title.href = editBaseFor(venue.id);
    }

    title.textContent = venue.title || 'Без названия';
    titleCell.appendChild(title);

    typeCell.textContent = VENUE_TYPE_LABELS[venue.venue_type] || '—';

    addressCell.textContent = venue.venue_address || '—';

    tr.appendChild(titleCell);
    tr.appendChild(typeCell);
    tr.appendChild(addressCell);

    return tr;
  }

  /**
   * Адрес формы правки площадки.
   *
   * Собирается из куска, который сервер отдал в data-атрибуте раздела:
   * так форма и список не разойдутся по адресу.
   *
   * @param {number} id ID площадки.
   * @returns {string}
   */
  function editBaseFor(id){
    var section = rootOfVenues();
    var base = section ? section.getAttribute('data-idpg-venues-new-url') : '';
    var path = (base || '').replace(/\/venue\/new\/?$/, '/venue/');

    return path + id + '/';
  }

  /**
   * Контейнер раздела площадок.
   *
   * @returns {Element|null}
   */
  function rootOfVenues(){
    return document.querySelector('[data-idpg-venues]');
  }
})();
