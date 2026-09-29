/**
 * Дымовой тест organizer.js и organizer-event-form.js без браузера.
 *
 * Скрипты кабинета — браузерный код, PHPUnit их не покрывает. Здесь гоняем
 * их в node с минимальными заглушками DOM/fetch. Задача теста — поймать
 * регрессы порядка объявлений (var/function hoisting), опечатки в
 * data-атрибутах и то, что обработчики вообще навешиваются. Вёрстку и
 * реальные ответы REST он не проверяет.
 *
 * Модель DOM здесь плоская: все узлы лежат в общем реестре, а querySelector
 * ищет по нему. Этого хватает, потому что оба скрипта ищут элементы по
 * data-атрибутам от корня страницы, а не обходят дерево.
 *
 * Запуск: node tests/js-smoke/organizer-js-smoke.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const THEME = path.resolve(__dirname, '..', '..', '..', '..', 'themes', 'idpg_core');
const JS_DIR = path.join(THEME, 'assets', 'js');

let failures = 0;
let checks = 0;

function check(name, condition) {
  checks++;
  if (condition) {
    console.log('  ok   ' + name);
  } else {
    failures++;
    console.log('  FAIL ' + name);
  }
}

// --- Заглушки DOM ---------------------------------------------------------

class ClassList {
  constructor() { this.set = new Set(); }
  add(c) { this.set.add(c); }
  remove(c) { this.set.delete(c); }
  contains(c) { return this.set.has(c); }
  toggle(c, force) {
    if (force === undefined) { this.set.has(c) ? this.set.delete(c) : this.set.add(c); }
    else if (force) { this.set.add(c); } else { this.set.delete(c); }
    return this.set.has(c);
  }
}

class El {
  constructor(tag, reg) {
    this.tagName = String(tag || 'div').toUpperCase();
    this.reg = reg;
    this.children = [];
    this.attributes = {};
    this.dataset = {};
    this.style = {};
    this.classList = new ClassList();
    this.parentNode = null;
    this._listeners = {};
    this._text = '';
    this.value = '';
    this.disabled = false;
    this.hidden = false;
    if (reg) reg.all.push(this);
  }

  set className(v) { this.classList.set = new Set(String(v).split(/\s+/).filter(Boolean)); }
  get className() { return Array.from(this.classList.set).join(' '); }

  setAttribute(k, v) {
    this.attributes[k] = String(v);
    if (k.indexOf('data-') === 0) this.dataset[k.slice(5)] = String(v);
  }
  getAttribute(k) {
    if (k in this.attributes) return this.attributes[k];
    if (k.indexOf('data-') === 0) return this.dataset[k.slice(5)];
    return null;
  }
  // Скрипты задают адрес и картинку свойством, а не атрибутом. Без этих
  // сеттеров проверка ссылки была бы невозможна: свойство просто появилось
  // бы на объекте и не попало в attributes.
  set href(v) { this.setAttribute('href', v); }
  get href() { return this.getAttribute('href'); }
  set src(v) { this.setAttribute('src', v); }
  get src() { return this.getAttribute('src'); }
  set type(v) { this.setAttribute('type', v); }
  get type() { return this.getAttribute('type'); }
  hasAttribute(k) {
    if (k in this.attributes) return true;
    return k.indexOf('data-') === 0 && this.dataset[k.slice(5)] !== undefined;
  }
  removeAttribute(k) { delete this.attributes[k]; }

  appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
  insertBefore(c) { c.parentNode = this; this.children.unshift(c); return c; }
  removeChild(c) { this.children = this.children.filter(x => x !== c); return c; }

  addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); }
  hasListeners(type) { return Boolean(this._listeners[type] && this._listeners[type].length); }
  dispatch(type, evt) { (this._listeners[type] || []).slice().forEach(fn => fn.call(this, evt || {})); }

  set textContent(v) { this._text = String(v); this.children = []; }
  get textContent() { return this._text || ''; }

  querySelector(sel) {
    for (const n of this.reg.all) {
      if (n === this) continue;
      if (matches(n, sel)) return n;
    }
    return null;
  }
  querySelectorAll(sel) {
    return this.reg.all.filter(n => (n === this ? false : matches(n, sel)));
  }

  focus() {}
  matches() { return false; }
  closest() { return null; }
}

// Поддерживаемые селекторы: [attr], [attr="v"], .class, tag, tag[attr="v"].
function matches(node, sel) {
  sel = String(sel).trim();
  let m;
  if ((m = sel.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/))) {
    return m[2] === undefined ? node.hasAttribute(m[1]) : node.getAttribute(m[1]) === m[2];
  }
  if ((m = sel.match(/^\.([\w-]+)$/))) return node.classList.contains(m[1]);
  if ((m = sel.match(/^([\w-]+)\[([\w-]+)(?:="([^"]*)")?\]$/))) {
    if (node.tagName !== m[1].toUpperCase()) return false;
    if (!node.hasAttribute(m[2])) return false;
    return m[3] === undefined ? true : node.getAttribute(m[2]) === m[3];
  }
  return node.tagName === sel.toUpperCase();
}

function runScript(file, { reg, pathname, search, fetchImpl }) {
  const root = reg.all[0]; // корневой <main id="primary">

  const doc = {
    getElementById: (id) => (id === 'primary' ? root : null),
    createElement: (tag) => new El(tag, reg),
    // document ищет по всему реестру, включая сам корень: в реальной
    // разметке .idpg-org-page — это и есть <main id="primary">.
    querySelector: (sel) => reg.all.find(n => matches(n, sel)) || null,
    querySelectorAll: (sel) => reg.all.filter(n => matches(n, sel))
  };

  const href = (pathname || '/organizer/') + (search || '');
  const win = {
    location: { pathname: pathname || '/organizer/', search: search || '', href, origin: 'https://example.test' },
    history: { replaceState() {}, pushState() {} },
    confirm: () => true,
    fetch: fetchImpl,
    URLSearchParams,
    setTimeout
  };
  win.window = win;
  // organizer.js вешает обработчик popstate на window.
  win.addEventListener = () => {};

  const ctx = vm.createContext(win);
  ctx.document = doc;
  ctx.window = win;
  ctx.fetch = fetchImpl;
  ctx.URLSearchParams = URLSearchParams;
  ctx.Promise = Promise;
  ctx.setTimeout = setTimeout;
  ctx.console = console;

  vm.runInContext(fs.readFileSync(path.join(JS_DIR, file), 'utf8'), ctx, { filename: file });
  return { doc, win, root };
}

// --- organizer.js: дашборд и список событий (G6) ---------------------------

console.log('organizer.js — G6 (статистика + список событий)');
{
  const reg = { all: [] };
  const main = new El('main', reg);
  main.setAttribute('id', 'primary');
  main.className = 'idpg-org-page';

  const linkDash = new El('a', reg);   linkDash.setAttribute('data-idpg-org-section', 'dashboard');
  const linkEvents = new El('a', reg); linkEvents.setAttribute('data-idpg-org-section', 'events');
  const panelDash = new El('section', reg);  panelDash.setAttribute('data-idpg-org-panel', 'dashboard');
  const panelEvents = new El('section', reg); panelEvents.setAttribute('data-idpg-org-panel', 'events');
  const title = new El('h2', reg); title.className = 'idpg-org-section__title';

  const statsBox = new El('div', reg);
  ['data-idpg-stats', 'data-idpg-stats-url', 'data-idpg-stats-nonce'].forEach(a => statsBox.setAttribute(a, ''));
  statsBox.setAttribute('data-idpg-stats-url', 'https://example.test/wp-json/idpg/v1/organizer/stats');
  statsBox.setAttribute('data-idpg-stats-nonce', 'nonce');

  const eventsBox = new El('div', reg);
  eventsBox.setAttribute('data-idpg-events', '');
  eventsBox.setAttribute('data-idpg-events-url', 'https://example.test/wp-json/idpg/v1/organizer/events');
  eventsBox.setAttribute('data-idpg-stats-url', 'https://example.test/wp-json/idpg/v1/organizer/stats');
  eventsBox.setAttribute('data-idpg-media-url', 'https://example.test/wp-json/wp/v2/media');
  eventsBox.setAttribute('data-idpg-events-nonce', 'nonce');
  eventsBox.setAttribute('data-idpg-events-edit-base', 'https://example.test/organizer/event/');

  const tbody = new El('tbody', reg);   tbody.setAttribute('data-idpg-events-body', '');
  const empty = new El('div', reg);     empty.setAttribute('data-idpg-events-empty', '');
  const filterAll = new El('button', reg); filterAll.setAttribute('data-idpg-events-filter', 'all');
  const filterPub = new El('button', reg); filterPub.setAttribute('data-idpg-events-filter', 'publish');
  const prev = new El('button', reg); prev.setAttribute('data-idpg-events-prev', '');
  const next = new El('button', reg); next.setAttribute('data-idpg-events-next', '');
  const valTotal = new El('div', reg); valTotal.setAttribute('data-idpg-stats-value', 'total');

  const urls = [];
  const fetchImpl = (url) => { urls.push(String(url)); return Promise.reject(new Error('сеть недоступна')); };

  let threw = null;
  try {
    runScript('organizer.js', {
      reg, fetchImpl, pathname: '/organizer/events/', search: '?status=publish&p=2'
    });
  } catch (e) { threw = e; }

  check('скрипт выполняется без синхронных ошибок', threw === null);
  if (threw) console.log('       ' + threw.stack.split('\n').slice(0, 5).join('\n       '));

  check('состояние из ?status=publish применено к фильтру', filterPub.classList.contains('dashboard__filter-btn--active'));
  check('фильтр «all» снят при status=publish', !filterAll.classList.contains('dashboard__filter-btn--active'));
  check('обработчик клика на фильтре навешен', filterPub.hasListeners('click'));
  check('обработчик «вперёд» навешен', next.hasListeners('click'));
  check('обработчик «назад» навешен', prev.hasListeners('click'));
  check('обработчик переключения раздела навешен', linkDash.hasListeners('click'));

  // Запросы должны уйти в stats И в events, с корректными параметрами.
  const hasStats = urls.some(u => u.indexOf('/organizer/stats') !== -1);
  const eventsCall = urls.find(u => u.indexOf('/organizer/events') !== -1) || '';

  check('запрошены счётчики (stats)', hasStats);
  check('запрошен список событий', eventsCall !== '');
  check('в список переданы page и status', eventsCall.indexOf('page=2') !== -1 && eventsCall.indexOf('status=publish') !== -1);
  check('во все запросы уходит nonce', urls.every(u => u.indexOf('nonce') === -1)); // nonce идёт заголовком, не в URL
  check('всего два запроса (stats + events)', urls.length === 2, 'получено: ' + urls.length);
}

// --- organizer-event-form.js: шаги и сеансы (G5) --------------------------

console.log('organizer-event-form.js — G5 (шаги + сеансы)');
{
  const reg = { all: [] };
  const main = new El('main', reg);
  main.setAttribute('id', 'primary');

  const form = new El('form', reg);
  form.setAttribute('data-idpg-event-form', '');
  form.setAttribute('data-idpg-event-id', '0');
  form.setAttribute('data-idpg-event-create-url', 'https://example.test/wp-json/idpg/v1/organizer/events');
  form.setAttribute('data-idpg-event-nonce', 'nonce');
  form.setAttribute('data-idpg-event-locked', '0');

  const panels = [0, 1, 2].map(() => new El('div', reg));
  panels.forEach(p => { p.className = 'create-event__panel'; });

  const steps = [0, 1, 2].map(() => new El('li', reg));
  steps.forEach(s => { s.className = 'create-event__step'; s.setAttribute('data-idpg-event-step', ''); });

  const progress = new El('div', reg); progress.setAttribute('data-idpg-event-progress', '');
  const fill = new El('div', reg); fill.className = 'create-event__progress-fill';
  progress.appendChild(fill);

  const statusBox = new El('div', reg); statusBox.setAttribute('data-idpg-event-status', '');
  const saveBtn = new El('button', reg); saveBtn.setAttribute('data-idpg-event-save', '');
  const nextBtn = new El('button', reg); nextBtn.setAttribute('data-idpg-event-step-next', '');
  const prevBtn = new El('button', reg); prevBtn.setAttribute('data-idpg-event-step-prev', '');
  const sessionsList = new El('div', reg); sessionsList.setAttribute('data-idpg-event-sessions-list', '');
  const sessionAdd = new El('button', reg); sessionAdd.setAttribute('data-idpg-event-session-add', '');

  const fetchImpl = () => Promise.reject(new Error('сеть недоступна'));

  let threw = null;
  try {
    runScript('organizer-event-form.js', { reg, fetchImpl, pathname: '/organizer/event/new/', search: '' });
  } catch (e) { threw = e; }

  check('скрипт выполняется без синхронных ошибок', threw === null);
  if (threw) console.log('       ' + threw.stack.split('\n').slice(0, 5).join('\n       '));

  check('форма перехватывает submit', form.hasListeners('submit'));
  check('форма слушает invalid в фазе захвата', form.hasListeners('invalid'));
  check('обработчик добавления сеанса навешен', sessionAdd.hasListeners('click'));
  // Ширину задаёт JS как (1/3)*100, поэтому сверяем префикс, а не точное
  // число: 33.333…% в разных движках печатается по-разному.
  check('прогресс заполнен примерно на 1/3', String(fill.style.width).indexOf('33.3') === 0);
  check('aria-valuenow = 1', progress.getAttribute('aria-valuenow') === '1');
  check('шаг 1 активен', steps[0].classList.contains('create-event__step--active'));
  check('шаг 1 панель активна', panels[0].classList.contains('create-event__panel--active'));
  check('панели 2 и 3 скрыты', !panels[1].classList.contains('create-event__panel--active') && !panels[2].classList.contains('create-event__panel--active'));

  // Переход «Далее» должен переключить панель и сдвинуть прогресс.
  main.dispatch('click', { target: nextBtn, hasAttribute: (a) => nextBtn.hasAttribute(a) });

  check('после «Далее» активна панель 2', panels[1].classList.contains('create-event__panel--active'));
  check('после «Далее» прогресс 2/3', fill.style.width === '66.66666666666666%' || fill.style.width.indexOf('66.6') === 0);
  check('после «Далее» шаг 1 помечен как пройденный', steps[0].classList.contains('create-event__step--done'));
  check('после «Далее» aria-valuenow = 2', progress.getAttribute('aria-valuenow') === '2');

  main.dispatch('click', { target: prevBtn, hasAttribute: (a) => prevBtn.hasAttribute(a) });
  check('после «Назад» активна панель 1', panels[0].classList.contains('create-event__panel--active'));
}

// --- Асинхронные проверки: список площадок и форма площадки (DEV-50) --------

/**
 * Даёт микро- и макроочереди выполниться.
 *
 * Оба скрипта строят список и обновляют подпись после ответа сервера, поэтому
 * проверять их результат можно только после того, как цепочка промисов
 * разрешится. Два тактика — достаточно: ответ mock-а разбирается через
 * .json() ещё одним промисом, отрисовка идёт следующим кадром.
 */
function flush() {
  return new Promise(resolve => setTimeout(resolve, 0)).then(() => new Promise(resolve => setTimeout(resolve, 0)));
}

/**
 * Ответ mock-а fetch.
 *
 * @param {boolean} ok Успешен ли запрос.
 * @param {Object} body Тело ответа.
 * @returns {Object}
 */
function jsonResponse(ok, body) {
  return { ok: ok, status: ok ? 200 : 400, json: () => Promise.resolve(body) };
}

async function asyncChecks() {
  // --- organizer.js: раздел «Площадки» (DEV-50) ----------------------------

  console.log('organizer.js — DEV-50 (список площадок)');
  {
    const reg = { all: [] };
    const main = new El('main', reg);
    main.setAttribute('id', 'primary');
    main.className = 'idpg-org-page';

    // Меню разделов намеренно отсутствует: плагин может его отфильтровать,
    // и organizer.js на это рассчитан (initStats/initEvents/initVenues
    // вызываются до раннего return). Проверка ниже ловит и регрессию, при
    // которой значение `var` для подписей типов площадок не успело бы
    // присвоиться до раннего return.
    const venuesBox = new El('div', reg);
    venuesBox.setAttribute('data-idpg-venues', '');
    venuesBox.setAttribute('data-idpg-venues-list-url', 'https://example.test/wp-json/idpg/v1/organizer/venues');
    venuesBox.setAttribute('data-idpg-venues-nonce', 'nonce');
    venuesBox.setAttribute('data-idpg-venues-new-url', 'https://example.test/organizer/venue/new/');

    const tbody = new El('tbody', reg); tbody.setAttribute('data-idpg-venues-body', '');
    const table = new El('table', reg); table.setAttribute('data-idpg-venues-table', '');
    const loading = new El('p', reg);   loading.setAttribute('data-idpg-venues-loading', '');
    const empty = new El('div', reg);    empty.setAttribute('data-idpg-venues-empty', '');
    const errBox = new El('div', reg);   errBox.setAttribute('data-idpg-venues-error', '');

    const urls = [];
    const fetchImpl = (url) => {
      urls.push(String(url));
      return Promise.resolve(
        jsonResponse(true, {
          items: [
            { id: 7, title: 'ДК «Тест»', venue_type: 'theater', venue_address: 'ул. Ленина, 1', is_own: true },
            { id: 8, title: 'Кинотеатр', venue_type: 'cinema', venue_address: '', is_own: false }
          ]
        })
      );
    };

    let threw = null;
    try {
      runScript('organizer.js', { reg, fetchImpl, pathname: '/organizer/venues/', search: '' });
    } catch (e) { threw = e; }

    check('скрипт выполняется без синхронных ошибок', threw === null);
    if (threw) console.log('       ' + threw.stack.split('\n').slice(0, 5).join('\n       '));

    check('запрошен список площадок', urls.some(u => u.indexOf('/organizer/venues') !== -1));
    // Статистики и списка событий на этой странице нет, значит и запросов
    // к ним быть не должно: лишние запросы — лишняя работа на каждый вход
    // в кабинет.
    check('без разделов статистики и событий запросов к ним нет', urls.length === 1, 'запросов: ' + urls.length);

    await flush();

    // Если строк нет, initVenues ушёл в catch: печатаем причину, иначе
    // падение выглядело бы как «сервер вернул пусто».
    if (tbody.children.length === 0) {
      console.log('       причина: ' + (errBox.textContent || '(строка не отрисована и ошибка не показана)'));
    }

    check('в таблицу добавлена строка на каждую площадку', tbody.children.length === 2, 'строк: ' + tbody.children.length);

    const firstRow = tbody.children[0];
    const firstTitle = firstRow.children[0].children[0];
    check('название площадки ведёт на форму правки', firstTitle.getAttribute('href') === 'https://example.test/organizer/venue/7/');
    check('название площадки выведено', firstTitle.textContent === 'ДК «Тест»');
    check('тип площадки подписан по-русски', firstRow.children[1].textContent === 'Театр');
    check('адрес площадки выведен', firstRow.children[2].textContent === 'ул. Ленина, 1');
    // Пустой адрес не должен превращаться в «undefined».
    check('пустой адрес заменён на тире', tbody.children[1].children[2].textContent === '—');

    // Показано ровно одно состояние раздела: заголовки таблицы с нулём
    // строк выглядели бы как сломанная страница.
    check('при непустом списке таблица показана', table.hidden === false);
    check('при непустом списке пустое состояние скрыто', empty.hidden === true);
    check('после загрузки индикатор загрузки скрыт', loading.hidden === true);

    // Чужая опубликованная площадка видна в списке, но редактировать её
    // организатор не может: ссылка на форму правки была бы заходом в 403.
    const sharedRow = tbody.children[1];
    const sharedNote = sharedRow.children[0].children[0];
    const sharedTitle = sharedRow.children[0].children[1];

    check('у чужой площадки нет ссылки на правку', sharedTitle.getAttribute('href') === null);
    check('название чужой площадки не ссылка', sharedTitle.tagName === 'SPAN');
    check('чужая площадка помечена как общая', sharedNote.textContent === 'общая');
    check('у своей площадки пометки нет', firstRow.children[0].children.length === 1);
  }

  // --- organizer.js: ошибка загрузки списка площадок (DEV-50) --------------

  console.log('organizer.js — DEV-50 (ошибка загрузки списка)');
  {
    const reg = { all: [] };
    const main = new El('main', reg);
    main.setAttribute('id', 'primary');
    main.className = 'idpg-org-page';

    const venuesBox = new El('div', reg);
    venuesBox.setAttribute('data-idpg-venues', '');
    venuesBox.setAttribute('data-idpg-venues-list-url', 'https://example.test/wp-json/idpg/v1/organizer/venues');
    venuesBox.setAttribute('data-idpg-venues-nonce', 'nonce');

    const tbody = new El('tbody', reg); tbody.setAttribute('data-idpg-venues-body', '');
    const table = new El('table', reg); table.setAttribute('data-idpg-venues-table', '');
    const loading = new El('p', reg);   loading.setAttribute('data-idpg-venues-loading', '');
    const empty = new El('div', reg);    empty.setAttribute('data-idpg-venues-empty', '');
    const errBox = new El('div', reg);   errBox.setAttribute('data-idpg-venues-error', '');

    const fetchImpl = () => Promise.resolve(jsonResponse(false, { message: 'Сессия истекла' }));

    let threw = null;
    try {
      runScript('organizer.js', { reg, fetchImpl, pathname: '/organizer/venues/', search: '' });
    } catch (e) { threw = e; }

    check('скрипт выполняется без синхронных ошибок', threw === null);

    await flush();

    // Провал запроса не должен выглядеть как «у вас нет площадок»: иначе
    // организатор решит, что потерял данные, и заведёт дубли.
    check('таблица очищена', tbody.children.length === 0);
    check('показана причина ошибки', errBox.textContent === 'Сессия истекла');
    check('ошибка показана', errBox.hidden === false);
    check('пустое состояние не показывается при ошибке', empty.hidden === true);
    check('таблица скрыта при ошибке', table.hidden === true);
    check('индикатор загрузки снят при ошибке', loading.hidden === true);
  }

  // --- organizer.js: пустой список площадок (DEV-50) -----------------------

  console.log('organizer.js — DEV-50 (пустой список площадок)');
  {
    const reg = { all: [] };
    const main = new El('main', reg);
    main.setAttribute('id', 'primary');
    main.className = 'idpg-org-page';

    const venuesBox = new El('div', reg);
    venuesBox.setAttribute('data-idpg-venues', '');
    venuesBox.setAttribute('data-idpg-venues-list-url', 'https://example.test/wp-json/idpg/v1/organizer/venues');
    venuesBox.setAttribute('data-idpg-venues-nonce', 'nonce');
    venuesBox.setAttribute('data-idpg-venues-new-url', 'https://example.test/organizer/venue/new/');

    const tbody = new El('tbody', reg); tbody.setAttribute('data-idpg-venues-body', '');
    const table = new El('table', reg); table.setAttribute('data-idpg-venues-table', '');
    const loading = new El('p', reg);   loading.setAttribute('data-idpg-venues-loading', '');
    const empty = new El('div', reg);    empty.setAttribute('data-idpg-venues-empty', '');
    const errBox = new El('div', reg);   errBox.setAttribute('data-idpg-venues-error', '');

    const fetchImpl = () => Promise.resolve(jsonResponse(true, { items: [], total: 0 }));

    let threw = null;
    try {
      runScript('organizer.js', { reg, fetchImpl, pathname: '/organizer/venues/', search: '' });
    } catch (e) { threw = e; }

    check('скрипт выполняется без синхронных ошибок', threw === null);

    await flush();

    check('строк не добавлено', tbody.children.length === 0);
    // Заголовки таблицы с нулём строк читались бы как сломанная страница.
    check('таблица без строк скрыта', table.hidden === true);
    check('показано пустое состояние', empty.hidden === false);
    check('ошибка не показана', errBox.hidden === true);
    check('индикатор загрузки скрыт', loading.hidden === true);
  }

  // --- organizer-venue-form.js: правка существующей площадки (DEV-50) ------
  //
  // У формы правки ID известен сразу, поэтому кнопка удаления приходит из
  // разметки. Создание новой площадки разобрано отдельным блоком ниже.

  console.log('organizer-venue-form.js — DEV-50 (правка и удаление)');
  {
    const reg = { all: [] };
    const main = new El('main', reg);
    main.setAttribute('id', 'primary');

    const form = new El('form', reg);
    form.setAttribute('data-idpg-venue-form', '');
    form.setAttribute('data-idpg-venue-id', '42');
    form.setAttribute('data-idpg-venue-url', 'https://example.test/wp-json/idpg/v1/organizer/venues');
    form.setAttribute('data-idpg-venue-nonce', 'nonce');
    form.setAttribute('data-idpg-venue-locked', '0');
    form.setAttribute('data-idpg-venue-back', '/organizer/venues/');
    main.appendChild(form);

    const statusBox = new El('div', reg); statusBox.setAttribute('data-idpg-venue-status', '');
    const saveBtn = new El('button', reg); saveBtn.setAttribute('data-idpg-venue-save', '');
    const delBtn = new El('button', reg);  delBtn.setAttribute('data-idpg-venue-delete', '');
    form.appendChild(saveBtn);
    main.appendChild(delBtn);

    // Поля формы: заполняем те, что форма должна передать в REST.
    const field = (name, val) => {
      const input = new El('input', reg);
      input.setAttribute('name', name);
      input.value = val;
      form.appendChild(input);
      return input;
    };
    field('title', 'ДК «Тест»');
    field('content', 'Описание');
    field('city', '12');
    field('venue_type', 'theater');
    field('venue_address', 'ул. Ленина, 1');
    field('poster_id', '0');

    const calls = [];
    const fetchImpl = (url, options) => {
      calls.push({ url: String(url), method: (options && options.method) || 'GET', body: options && options.body });
      return Promise.resolve(jsonResponse(true, { data: { id: 42 } }));
    };

    let threw = null;
    try {
      runScript('organizer-venue-form.js', { reg, fetchImpl, pathname: '/organizer/venue/42/', search: '' });
    } catch (e) { threw = e; }

    check('скрипт выполняется без синхронных ошибок', threw === null);
    if (threw) console.log('       ' + threw.stack.split('\n').slice(0, 5).join('\n       '));

    check('форма перехватывает submit', form.hasListeners('submit'));
    // Обработчик удаления обязан навешиваться сразу: у площадки из
    // разметки ID известен до первого сохранения.
    check('обработчик удаления навешен', delBtn.hasListeners('click'));

    form.dispatch('submit', { preventDefault() {} });
    await flush();

    const save = calls[0] || {};
    // Повторное сохранение должно править ту же площадку, а не создавать
    // вторую, поэтому уходит PUT на конкретный ID.
    check('сохранение уходит PUT на конкретную площадку',
      save.method === 'PUT' && save.url === 'https://example.test/wp-json/idpg/v1/organizer/venues/42');

    // Тело должно быть JSON с полями формы.
    let payload = {};
    try { payload = JSON.parse(save.body || '{}'); } catch (e) { payload = {}; }
    check('в теле есть название', payload.title === 'ДК «Тест»');
    check('город передан числом', payload.city === 12);
    check('адрес передан', payload.venue_address === 'ул. Ленина, 1');
    check('статус сообщает об успехе', statusBox.textContent === 'Площадка сохранена.');

    delBtn.dispatch('click', {});
    await flush();

    const remove = calls[1] || {};
    check('удаление уходит DELETE на конкретную площадку',
      remove.method === 'DELETE' && remove.url === 'https://example.test/wp-json/idpg/v1/organizer/venues/42');
  }

  // --- organizer-venue-form.js: создание новой площадки (DEV-50) -----------

  console.log('organizer-venue-form.js — DEV-50 (создание новой площадки)');
  {
    const reg = { all: [] };
    const main = new El('main', reg);
    main.setAttribute('id', 'primary');

    const form = new El('form', reg);
    form.setAttribute('data-idpg-venue-form', '');
    form.setAttribute('data-idpg-venue-id', '0');
    form.setAttribute('data-idpg-venue-url', 'https://example.test/wp-json/idpg/v1/organizer/venues');
    form.setAttribute('data-idpg-venue-nonce', 'nonce');
    form.setAttribute('data-idpg-venue-locked', '0');
    form.setAttribute('data-idpg-venue-back', '/organizer/venues/');
    main.appendChild(form);

    const statusBox = new El('div', reg); statusBox.setAttribute('data-idpg-venue-status', '');
    const saveBtn = new El('button', reg); saveBtn.setAttribute('data-idpg-venue-save', '');
    form.appendChild(statusBox);
    form.appendChild(saveBtn);

    const field = (name, val) => {
      const input = new El('input', reg);
      input.setAttribute('name', name);
      input.value = val;
      form.appendChild(input);
      return input;
    };
    field('title', 'Новая площадка');
    field('city', '12');
    field('venue_address', 'ул. Ленина, 1');

    const calls = [];
    const fetchImpl = (url, options) => {
      calls.push({ url: String(url), method: (options && options.method) || 'GET', body: options && options.body });
      if (calls.length === 1) return Promise.resolve(jsonResponse(true, { data: { id: 42 } }));
      return Promise.resolve(jsonResponse(true, { success: true }));
    };

    let threw = null;
    try {
      runScript('organizer-venue-form.js', { reg, fetchImpl, pathname: '/organizer/venue/new/', search: '' });
    } catch (e) { threw = e; }

    check('скрипт выполняется без синхронных ошибок', threw === null);
    if (threw) console.log('       ' + threw.stack.split('\n').slice(0, 5).join('\n       '));

    check('форма перехватывает submit', form.hasListeners('submit'));

    form.dispatch('submit', { preventDefault() {} });
    await flush();

    const create = calls[0] || {};
    check('создание уходит POST на коллекцию площадок',
      create.method === 'POST' && create.url === 'https://example.test/wp-json/idpg/v1/organizer/venues');
    check('статус сообщает об успехе', statusBox.textContent === 'Площадка сохранена.');
    // После создания скрипт должен запомнить id, иначе второе сохранение
    // создало бы вторую площадку вместо правки первой.
    check('id новой площадки запомнен', form.getAttribute('data-idpg-venue-id') === '42');
  }

  // --- organizer-venue-form.js: удаление только что созданной площадки ------
  //
  // Регресс: у новой площадки кнопки удаления в разметке нет, скрипт создаёт
  // её после сохранения. Если обработчик навешивать один раз при загрузке
  // (как было), кнопка выглядела бы рабочей, а ничего не делала.

  console.log('organizer-venue-form.js — DEV-50 (удаление новой площадки)');
  {
    const reg = { all: [] };
    const main = new El('main', reg);
    main.setAttribute('id', 'primary');

    const form = new El('form', reg);
    form.setAttribute('data-idpg-venue-form', '');
    form.setAttribute('data-idpg-venue-id', '0');
    form.setAttribute('data-idpg-venue-url', 'https://example.test/wp-json/idpg/v1/organizer/venues');
    form.setAttribute('data-idpg-venue-nonce', 'nonce');
    form.setAttribute('data-idpg-venue-locked', '0');
    form.setAttribute('data-idpg-venue-back', '/organizer/venues/');
    main.appendChild(form);

    const statusBox = new El('div', reg); statusBox.setAttribute('data-idpg-venue-status', '');
    form.appendChild(statusBox);

    const input = new El('input', reg);
    input.setAttribute('name', 'title');
    input.value = 'Новая площадка';
    form.appendChild(input);

    const calls = [];
    const fetchImpl = (url, options) => {
      calls.push({ url: String(url), method: (options && options.method) || 'GET' });
      if (calls.length === 1) return Promise.resolve(jsonResponse(true, { data: { id: 77 } }));
      return Promise.resolve(jsonResponse(true, { success: true }));
    };

    let threw = null;
    let run = null;
    try {
      run = runScript('organizer-venue-form.js', { reg, fetchImpl, pathname: '/organizer/venue/new/', search: '' });
    } catch (e) { threw = e; }

    check('скрипт выполняется без синхронных ошибок', threw === null);

    const find = (sel) => (run ? run.doc.querySelector(sel) : null);

    // До сохранения ID нет, поэтому и кнопки удаления быть не может.
    check('до сохранения кнопки удаления нет', find('[data-idpg-venue-delete]') === null);

    form.dispatch('submit', { preventDefault() {} });
    await flush();

    const created = find('[data-idpg-venue-delete]');

    check('после сохранения кнопка удаления появилась', created !== null);
    check('кнопка удаления опасного вида', created !== null && created.className.indexOf('venue-form__danger') !== -1);

    if (created){
      check('на созданной кнопке есть обработчик', created.hasListeners('click'));

      created.dispatch('click', {});
      await flush();

      const remove = calls[1] || {};
      check('удаление только что созданной площадки уходит на сервер',
        remove.method === 'DELETE' && remove.url === 'https://example.test/wp-json/idpg/v1/organizer/venues/77');
    }
  }
}

asyncChecks().then(() => {
  console.log('');
  console.log(failures === 0 ? 'Все проверки пройдены (' + checks + ')' : 'ПРОВАЛЕНО: ' + failures + ' из ' + checks);
  process.exit(failures === 0 ? 0 : 1);
});
