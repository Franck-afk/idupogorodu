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

  // DEV-223: выбор документов в профиле проверяет сам элемент
  // (`e.target.matches`) и удаляет его (`remove`). Заглушка возвращала из
  // matches всегда false, а remove отсутствовал, поэтому этот кусок логики
  // было нечем покрыть.
  matches(sel) { return matches(this, sel); }

  remove() {
    if (this.parentNode) this.parentNode.removeChild(this);
  }

  // DEV-206: closest() заглушки всегда возвращал null, поэтому нельзя было
  // проверить ни клик по варианту кастомного списка, ни то, что скрипт вешает
  // маркер «замена построена» на обёртку поля. Ищем по реальным предкам.
  // Сам элемент входит в поиск — как в настоящем Element.closest: без этого
  // клик по варианту не находил бы его самого.
  closest(sel) {
    let node = this;
    while (node) {
      if (matches(node, sel)) return node;
      node = node.parentNode;
    }
    return null;
  }

  contains(other) {
    let node = other;
    while (node) {
      if (node === this) return true;
      node = node.parentNode;
    }
    return false;
  }

  scrollIntoView() {}

  // Собственные события нужны кастомному селекту: он пишет выбранное
  // значение обратно в нативный select через dispatchEvent(new Event('change')).
  dispatchEvent(ev) {
    this.dispatch(ev && ev.type ? ev.type : String(ev), { target: this, bubbles: true });
    return true;
  }

  // select: значение и список вариантов. Компонент читает select.options и
  // select.selectedIndex, поэтому заглушка обязана их отдавать.
  get options() {
    return this._options || [];
  }
  set options(list) {
    this._options = list || [];
  }
  get selectedIndex() {
    if (this._selectedIndex === undefined) return this._options ? this._options.length - 1 : -1;
    return this._selectedIndex;
  }
  set selectedIndex(v) {
    this._selectedIndex = v;
  }
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

function runScript(file, { reg, pathname, search, fetchImpl, onReload }) {
  const root = reg.all[0]; // корневой <main id="primary">

  const doc = {
    getElementById: (id) => (id === 'primary' ? root : null),
    createElement: (tag) => new El(tag, reg),
    // document ищет по всему реестру, включая сам корень: в реальной
    // разметке .idpg-org-page — это и есть <main id="primary">.
    querySelector: (sel) => reg.all.find(n => matches(n, sel)) || null,
    querySelectorAll: (sel) => reg.all.filter(n => matches(n, sel)),
    // organizer-select.js слушает document на click и keydown, чтобы закрывать
    // открытый список по клику вне и по Tab. readyState = complete заставляет
    // init() выполниться сразу, без ожидания DOMContentLoaded.
    readyState: 'complete',
    addEventListener(type, fn) {
      (doc._listeners[type] = doc._listeners[type] || []).push(fn);
    },
    _listeners: {}
  };

  const href = (pathname || '/organizer/') + (search || '');
  const win = {
    // reload нужен после отправки события на модерацию: статус меняется на
    // сервере, поэтому форма обязана перечитать страницу (DEV-224).
    location: {
      pathname: pathname || '/organizer/',
      search: search || '',
      href,
      origin: 'https://example.test',
      reload: () => { if (onReload) onReload(); }
    },
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
  // Кастомный селект рассылает change через new Event(...). В контексте vm
  // конструктора Event нет, поэтому даём минимальный: скрипту важны только
  // type и bubbles.
  ctx.Event = class {
    constructor(type, init) {
      this.type = type;
      this.bubbles = Boolean(init && init.bubbles);
    }
  };

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

  check('состояние из ?status=publish применено к фильтру', filterPub.classList.contains('idpg-org-filter--active'));
  check('фильтр «all» снят при status=publish', !filterAll.classList.contains('idpg-org-filter--active'));
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

// --- organizer-event-form.js: одностраничная форма и сеансы (G5) ---------
//
// DEV-206. Блок раньше проверял визард из трёх шагов: активную панель,
// номера шагов и заполнение полосы прогресса на 1/3. Визарда в форме больше
// нет, поэтому проверять его нечего, а оставшиеся утверждения были ложными:
// они проходили, потому что проверяли элементы, которых в разметке нет.
// Вместо них проверяется то, что форма теперь делает: держит условные блоки,
// добавляет сеансы и берёт общее уведомление кабинета.

console.log('organizer-event-form.js — G5 (одностраничная форма + сеансы)');
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

  // Условные блоки: их показывает и прячет refreshConditions() по data-атрибутам.
  const ticketsBox = new El('div', reg); ticketsBox.setAttribute('data-idpg-tickets', '');
  const sessionsWrap = new El('div', reg); sessionsWrap.setAttribute('data-idpg-sessions-wrap', '');

  // Полоса прогресса в разметке формы больше не выводится. Элемент оставлен
  // в дереве намеренно: если визард вернётся, скрипт начнёт писать в его
  // style.width, и проверка это заметит.
  const progress = new El('div', reg); progress.setAttribute('data-idpg-event-progress', '');

  const statusBox = new El('div', reg); statusBox.setAttribute('data-idpg-event-status', '');
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
  check('обработчик добавления сеанса навешен', sessionAdd.hasListeners('click'));

  // Визард не должен вернуться: старый код на старте ставил ширину полосы
  // прогресса и вёл слушатель 'invalid', чтобы показать панель с ошибкой.
  check('визард не восстановлен: полоса прогресса не тронута', !('width' in progress.style));
  check('визард не восстановлен: слушателя invalid нет', !form.hasListeners('invalid'));

  // Условная видимость. Событие платное и не на весь день, поэтому билетные
  // поля и сеансы должны быть показаны. Значение radio скрипт читает через
  // ':checked', который заглушка селекторов не поддерживает, поэтому здесь
  // проверяется стартовое состояние, а не переключение.
  check('билетные поля показаны', ticketsBox.hidden === false);
  check('блок сеансов показан', sessionsWrap.hidden === false);

  // Сесансы: строка добавляется по кнопке и несёт нужные поля.
  const rowsBefore = sessionsList.children.length;
  sessionAdd.dispatch('click', {});
  check('строка сеанса добавлена', sessionsList.children.length === rowsBefore + 1);

  const added = sessionsList.children[sessionsList.children.length - 1];
  check('у новой строки есть метка строки сеанса', added.getAttribute('data-idpg-event-session-row') !== null);
  check('у новой строки есть кнопка удаления', Boolean(added.querySelector('[data-idpg-event-session-remove]')));

  // Имена полей вида session_time[] заглушка селекторов не разбирает: её
  // регулярка атрибутов — /^\[([\w-]+)\]/, а в имени есть квадратные скобки.
  // Проверять состав полей здесь бессмысленно — это ограничение стенда,
  // а не поведение формы.
}

// --- organizer-select.js: кастомный выпадающий список (G5) ----------------
//
// Отдельный компонент, нужен обеим формам и модалке. Проверяется главное:
// нативный select прячется ТОЛЬКО после того, как замена построена, —
// иначе пользователь остаётся без элемента управления.

console.log('organizer-select.js — G5 (кастомный список)');
{
  const reg = { all: [] };
  const main = new El('main', reg);
  main.setAttribute('id', 'primary');

  // Обёртка поля: скрипт ищет .idpg-org-field через closest().
  const field = new El('div', reg);
  field.className = 'idpg-org-field';
  main.appendChild(field);

  const select = new El('select', reg);
  select.className = 'idpg-org-field__select';
  select.setAttribute('name', 'city');
  select.value = '12';
  select.options = [
    { value: '', textContent: '— Выберите город —' },
    { value: '12', textContent: 'Казань' },
    { value: '34', textContent: 'Москва' },
  ];
  select.selectedIndex = 1;
  field.appendChild(select);

  let threw = null;
  try {
    runScript('organizer-select.js', { reg, pathname: '/organizer/event/new/', search: '' });
  } catch (e) { threw = e; }

  check('скрипт выполняется без синхронных ошибок', threw === null);
  if (threw) console.log('       ' + threw.stack.split('\n').slice(0, 6).join('\n       '));

  const find = (sel) => reg.all.find((n) => matches(n, sel));

  check('список вариантов собран', Boolean(find('.idpg-org-select__menu')));
  check('вариантов в списке три', reg.all.filter((n) => matches(n, '[data-index]')).length === 3);

  const button = find('.idpg-org-select__button');
  const menu = find('.idpg-org-select__menu');
  const options = reg.all.filter((n) => matches(n, '[data-index]'));

  // Роли проверяем по атрибуту: заглушка селекторов не разбирает
  // составные селекторы вида .class[attr="value"].
  check('кнопка имеет роль combobox', button.getAttribute('role') === 'combobox');
  check('кнопка объявляет раскрытие', button.getAttribute('aria-expanded') === 'false');
  check('список имеет роль listbox', menu.getAttribute('role') === 'listbox');
  check('нативный select помечен как готовый', select.getAttribute('data-idpg-select-ready') === '1');

  // Главное условие: маркер ставится только после построения замены.
  check(
    'маркер замены стоит только после сборки контрола',
    field.classList.contains('idpg-org-field--custom') && Boolean(button)
  );

  check('текущее значение показано в кнопке', (button.children[0].textContent || '') === 'Казань');
  check('выбранный вариант помечен', options[1].classList.contains('idpg-org-select__option--selected'));

  // Раскрытие и схлопывание.
  check('список закрыт по умолчанию', menu.hidden === true);
  button.dispatch('click', { target: button });
  check('клик по кнопке открывает список', menu.hidden === false && button.getAttribute('aria-expanded') === 'true');

  // Выбор варианта пишет значение обратно в нативный select.
  // Событие шлём на списке с target = вариант: заглушка не всплывает события,
  // а слушатель висит именно на списке.
  menu.dispatch('click', { target: options[2] });
  check('выбор варианта меняет значение select', select.value === '34');
  check('выбранным стал третий вариант', select.selectedIndex === 2);
  check('после выбора список закрыт', menu.hidden === true);
  check('кнопка показывает новое значение', (button.children[0].textContent || '') === 'Москва');

  // Клавиатура. Поведение двухуровневое, как у настоящего combobox: первое
// нажатие стрелки только раскрывает список, второе — переходит к варианту.
// Раньше проверялось только первое нажатие, поэтому переход не был закреплён.
  check('список закрыт после выбора', menu.hidden === true);
  button.dispatch('keydown', { key: 'ArrowDown', target: button, preventDefault() {} });
  check('стрелка вниз на закрытом списке только раскрывает его', menu.hidden === false);
  check('раскрытие стрелкой не меняет выбор', select.selectedIndex === 2);

  button.dispatch('keydown', { key: 'ArrowDown', target: button, preventDefault() {} });
  check('второе нажатие заворачивает к первому варианту', select.selectedIndex === 0);
  check('пустое значение не подменяет подпись', (button.children[0].textContent || '') === '— Выберите город —');
}

// --- organizer-date.js: кастомный календарь (G5) -----------------------
//
// Проверяется то же главное условие, что и у списков: нативный input[type=date]
// прячется только после того, как замена построена. Если маркер появится при
// нерабочем календаре, поле останется без элемента управления.

console.log('organizer-date.js — G5 (кастомный календарь)');
{
  const reg = { all: [] };
  const main = new El('main', reg);
  main.setAttribute('id', 'primary');

  const field = new El('div', reg);
  field.className = 'idpg-org-field';
  main.appendChild(field);

  const input = new El('input', reg);
  input.className = 'idpg-org-field__input idpg-org-field__date';
  input.setAttribute('type', 'date');
  input.setAttribute('name', 'start_date');
  input.value = '2026-10-15';
  field.appendChild(input);

  let threw = null;
  try {
    runScript('organizer-date.js', { reg, pathname: '/organizer/event/new/', search: '' });
  } catch (e) { threw = e; }

  check('скрипт выполняется без синхронных ошибок', threw === null);
  if (threw) console.log('       ' + threw.stack.split('\n').slice(0, 6).join('\n       '));

  const find = (sel) => reg.all.find((n) => matches(n, sel));
  const button = find('.idpg-org-date__button');
  const popup = find('.idpg-org-date__popup');

  check('кнопка календаря построена', Boolean(button));
  check('всплывающее окно построено', Boolean(popup));
  check('native input помечен как готовый', input.getAttribute('data-idpg-date-ready') === '1');
  check(
    'маркер замены стоит только после сборки',
    find('.idpg-org-date').classList.contains('idpg-org-date--custom') && Boolean(button)
  );

  // Раскрытие рисует сетку месяца выбранной даты.
  check('окно закрыто по умолчанию', popup.hidden === true);
  button.dispatch('click', { target: button });
  check('клик раскрывает календарь', popup.hidden === false && button.getAttribute('aria-expanded') === 'true');

  const cells = reg.all.filter((n) => matches(n, '[data-date]'));
  check('в октябре 31 день', cells.length === 31);
  check('выбрана 15 октября', cells.filter((c) => c.classList.contains('idpg-org-date__cell--selected')).length === 1);
  check('месяц в родительном падеже', (button.children[0].textContent || '') === '15 октября 2026');

  // Выбор дня пишет значение в нативный input.
  const target = cells.find((c) => c.getAttribute('data-date') === '2026-10-20');
  popup.dispatch('click', { target });
  check('выбор дня записан в input', input.value === '2026-10-20');
  check('после выбора окно закрыто', popup.hidden === true);
  check('кнопка показывает новую дату', (button.children[0].textContent || '') === '20 октября 2026');

  // Стрелка месяца назад перерисовывает сетку. Считаем ячейки внутри тела
  // сетки, а не в реестре стенда: реестр накапливает элементы и не очищается,
  // поэтому в нём всегда лежат ячейки и прошлого месяца.
  button.dispatch('click', { target: button });
  const prev = reg.all.find((n) => n.hasAttribute('data-month-step') && n.getAttribute('data-month-step') === 'prev');
  check('стрелка месяца есть', Boolean(prev));
  popup.dispatch('click', { target: prev });
  const body = find('.idpg-org-date__body');
  check('сетка перерисована на сентябрь', body.children.filter((n) => n.hasAttribute('data-date')).length === 30);
  check('заголовок месяца в именительном падеже', (find('.idpg-org-date__month').textContent || '') === 'Сентябрь 2026');
}

// --- organizer.js: документы профиля (DEV-223) ---------------------------
//
// Логика перенесена из инлайнового <script> в organizer.js: инлайн
// исполнялся до подвальных скриптов, и wp.media к тому моменту не было.

console.log('organizer.js — DEV-223 (документы профиля)');
{
  const reg = { all: [] };
  const main = new El('main', reg);
  main.setAttribute('id', 'primary');
  main.className = 'idpg-org-page';

  const hidden = new El('input', reg);
  hidden.setAttribute('data-idpg-documents-input', '');
  hidden.value = '[10]';
  main.appendChild(hidden);

  const list = new El('ul', reg);
  list.setAttribute('data-idpg-documents-list', '');
  main.appendChild(list);

  // Уже сохранённый документ выводится сервером.
  const existing = new El('li', reg);
  existing.className = 'idpg-org-document';
  existing.setAttribute('data-idpg-document-id', '10');
  const existingName = new El('span', reg);
  existingName.className = 'idpg-org-document__name';
  existingName.textContent = 'Паспорт.pdf';
  const existingRemove = new El('button', reg);
  existingRemove.setAttribute('data-idpg-document-remove', '');
  existing.appendChild(existingName);
  existing.appendChild(existingRemove);
  list.appendChild(existing);

  const addBtn = new El('button', reg);
  addBtn.setAttribute('data-idpg-documents-add', '');
  main.appendChild(addBtn);

  // Навигация раздела нужна, чтобы ранний return в organizer.js не оборвал инициализацию.
  const links = new El('a', reg);
  links.setAttribute('data-idpg-org-section', 'profile');
  main.appendChild(links);
  const panel = new El('div', reg);
  panel.setAttribute('data-idpg-org-panel', 'profile');
  main.appendChild(panel);

  let opened = null;
  const selection = [
    { id: 21, get: () => 'Договор.pdf' },
    { id: 22, get: () => 'Скан.jpg' },
  ];
  const mediaStub = {
    title: '',
    opts: null,
    open() { opened = this; },
    on(evt, fn) { this._onSelect = fn; },
    state: () => ({ get: () => ({ each: (fn) => selection.forEach(fn) }) }),
  };

  let threw = null;
  try {
    const out = runScript('organizer.js', {
      reg,
      pathname: '/organizer/profile/',
      search: '',
      fetchImpl: () => Promise.resolve(jsonResponse(true, {})),
    });
    // Скрипт читает window.wp — подставляем заглушку медиатеки.
    out.win.wp = { media: (opts) => Object.assign(mediaStub, { opts }) };
  } catch (e) { threw = e; }

  check('скрипт выполняется без синхронных ошибок', threw === null);
  if (threw) console.log('       ' + threw.stack.split('\n').slice(0, 6).join('\n       '));

  check('кнопка добавления получила обработчик', addBtn.hasListeners('click'));

  addBtn.dispatch('click', {});
  check('медиатека открылась', opened !== null);
  check('фильтр типов файлов прежний', opened && opened.opts.library.type === 'application/pdf,image/*');
  check('мультивыбор прежний', opened && opened.opts.multiple === true);

  opened._onSelect();
  check('документы добавлены в список', list.children.length === 3);
  check('id сохранены в скрытое поле', hidden.value === '[10,21,22]');

  const added = list.children[1];
  check('у документа проставлен id', added.getAttribute('data-idpg-document-id') === '21');
  check('название вставлено как текст', added.children[0].textContent === 'Договор.pdf');

  // Повторный выбор тех же документов не должен ничего продублировать.
  opened._onSelect();
  check('повторный выбор не дублирует записи', hidden.value === '[10,21,22]' && list.children.length === 3);

  // Удаление убирает и из списка, и из скрытого поля.
  const removeBtn = list.children[1].children[1];
  list.dispatch('click', { target: removeBtn });
  check('документ удалён из списка', list.children.length === 2);
  check('id удалён из скрытого поля', hidden.value === '[10,22]');
}

// --- organizer-event-form.js: сохранение уходит в REST (G5) -------------
  //
  // Отдельный блок от проверок выше: там скрипт только запускался, здесь
  // нажимается кнопка и проверяется фактический запрос. «Кнопка на месте»
  // ничего не значит — обработчик мог не навеситься вовсе.
  //
  // Блок обёрнут в async-IIFE, а не лежит в asyncChecks(): ему нужен await,
  // а asyncChecks() объявлена ниже по файлу. Функции flush и jsonResponse
  // подняты hoisting'ом, поэтому порядок объявления не важен.

  (async function () {
  console.log('organizer-event-form.js — G5 (сохранение события)');
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

    const field = (name, val) => {
      const input = new El('input', reg);
      input.setAttribute('name', name);
      input.value = val;
      form.appendChild(input);
      return input;
    };

    field('title', 'Ночной концерт');
    field('city', '12');
    const saveBtn = new El('button', reg);
    saveBtn.setAttribute('data-idpg-event-save', '');
    saveBtn.setAttribute('data-idpg-event-publish', '');
    form.appendChild(saveBtn);

    // Вторичное действие DEV-224: остаться в черновике.
    const draftBtn = new El('button', reg);
    draftBtn.setAttribute('data-idpg-event-save-draft', '');
    form.appendChild(draftBtn);

    const calls = [];
    const fetchImpl = (url, options) => {
      calls.push({
        url: String(url),
        method: (options && options.method) || 'GET',
        headers: (options && options.headers) || {},
        body: options && options.body,
      });
      return Promise.resolve(jsonResponse(true, { data: { id: 77 }, message: 'Сохранено' }));
    };

    let reloads = 0;
    let threw = null;
    try {
      runScript('organizer-event-form.js', {
        reg,
        fetchImpl,
        pathname: '/organizer/event/new/',
        search: '',
        onReload: () => { reloads++; }
      });
    } catch (e) { threw = e; }

    check('скрипт выполняется без синхронных ошибок', threw === null);
    if (threw) console.log('       ' + threw.stack.split('\n').slice(0, 5).join('\n       '));

    check('кнопка сохранения найдена', form.querySelector('[data-idpg-event-save]') === saveBtn);
    check('форма перехватывает submit', form.hasListeners('submit'));

    form.dispatch('submit', { preventDefault() {} });
    await flush();

    const save = calls[0] || {};
    check('сохранение уходит POST-ом на коллекцию', save.method === 'POST' && /organizer\/events$/.test(save.url));
    check(
      'nonce передаётся заголовком, а не в URL',
      save.headers['X-WP-Nonce'] === 'nonce' && save.url.indexOf('nonce') === -1
    );
    check(
      'тело запроса — JSON с названием',
      /^[{]/.test(String(save.body)) && String(save.body).indexOf('Ночной концерт') !== -1
    );
    check(
      'город уходит числом',
      String(save.body).indexOf('"city":12') !== -1,
      'тело: ' + String(save.body).slice(0, 220)
    );

    // DEV-224. Главная кнопка одна и не оставляет черновик: после успешного
    // сохранения событие само уходит на модерацию. Раньше отправка была
    // отдельной кнопкой, её можно было не нажать — и событие навсегда
    // оставалось в draft, из-за чего не появлялось даже на главной.
    const submitCall = calls[1] || {};
    check(
      'после сохранения событие уходит на модерацию',
      submitCall.method === 'POST' && /organizer\/events\/77\/submit$/.test(submitCall.url),
      'запросы: ' + calls.map(c => c.method + ' ' + c.url).join(' | ')
    );
    check('после отправки страница перечитывается', reloads === 1);

    // Ответ с id переводит форму в режим правки: повторное сохранение должно
    // обновлять тот же черновик, а не создавать новый.
    check('id нового события записан в форму', form.getAttribute('data-idpg-event-id') === '77');

    calls.length = 0;
    form.dispatch('submit', { preventDefault() {} });
    await flush();
    const second = calls[0] || {};
    check(
      'повторное сохранение уходит PUT-ом по id',
      second.method === 'PUT' && /organizer\/events\/77$/.test(second.url)
    );

    // Вторичная кнопка оставляет событие в черновике: только сохранение, без
    // обращения к /submit. Флаг одноразовый — следующее сохранение главной
    // кнопкой снова публикует, поэтому организатор не застревает в черновиках.
    calls.length = 0;
    reloads = 0;
    draftBtn.dispatch('click', {});
    form.dispatch('submit', { preventDefault() {} });
    await flush();

    check(
      'черновик сохраняется, но не отправляется на модерацию',
      calls.length === 1 && /organizer\/events\/77$/.test(calls[0].url) && calls[0].method === 'PUT',
      'запросы: ' + calls.map(c => c.method + ' ' + c.url).join(' | ')
    );
    check('черновик не перечитывает страницу', reloads === 0);

    calls.length = 0;
    reloads = 0;
    form.dispatch('submit', { preventDefault() {} });
    await flush();
    check(
      'после черновика главная кнопка снова публикует',
      (calls[1] || {}).url !== undefined && /organizer\/events\/77\/submit$/.test(calls[1].url),
      'запросы: ' + calls.map(c => c.method + ' ' + c.url).join(' | ')
    );
  }
  })();

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

    // DEV-196: на экранах уже 640px таблица становится списком карточек, и
    // подпись колонки рисуется через td::before{content:attr(data-label)}.
    // Атрибут ставит скрипт: из шаблона в ячейку он не попадает.
    check(
      'у ячеек таблицы площадок проставлен data-label',
      firstRow.children.map((td) => td.getAttribute('data-label')).join(',') === 'Площадка,Тип,Адрес',
      'подписи: ' + firstRow.children.map((td) => td.getAttribute('data-label')).join(',')
    );

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
    check('кнопка удаления опасного вида', created !== null && created.className.indexOf('idpg-btn--danger') !== -1);

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
