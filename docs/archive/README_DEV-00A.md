# DEV-00A · Аудит ЛК организатора — фактическое состояние

**Статус:** завершено 16.09.2026.
**Характер работы:** инвентаризация, только чтение кода и документации. Код не изменялся.
**Рабочее дерево:** `plugins/idpg-core` v1.1.2, `plugins/idpg-auth` v1.0.0, `themes/idpg_core` v1.0.75,
`themes/idpg_core-child` v1.0.0.

> **Ключевой вывод:** большая часть файлов ЛК организатора в репозитории есть, но **не подключена**.
> Документация (`docs/TZ_BACKLOG.md`, `docs/FEATURES.md`) описывает состояние 1.1.6…1.1.8 / 1.0.78…1.0.81,
> а в дереве — промежуточная версия 1.1.2 / 1.0.75: `require_once`, страница `/organizer/` в БД,
> подключение ассетов и REST-маршрут `organizer-complete` отсутствуют.

---

## 1. Страницы ЛК

| Маршрут | Существует? | Шаблон | Статус в продакшене |
|---|---|---|---|
| `/organizer/` (дашборд) | ✅ есть | `themes/idpg_core/page-organizer.php` (161 стр.) | **НЕ работает** — роутер не подключён |
| `/organizer/events/` | ✅ есть | `template-parts/organizer/section-events.php` (38 стр., заглушка «Раздел в разработке») | **НЕ работает** — роутер не подключён |
| `/organizer/profile/` | ✅ есть | `template-parts/organizer/section-profile.php` (55 стр., чтение: display_name, email, `idpg_org_name`, `idpg_contact_name`, `idpg_phone`) | **НЕ работает** — роутер не подключён |
| `/organizer/complete/` | ✅ есть | `page-organizer-complete.php` (186 стр.) | **Работает** (маршрутизация), но форма упирается в незарегистрированный REST-маршрут → 404 |
| `/organizer/event/new/` | ❌ **нет** | — | В коде отсутствуют rewrite, шаблон и REST-эндпоинт |
| `/organizer/event/{id}/` | ❌ **нет** | — | В коде отсутствуют rewrite, шаблон и REST-эндпоинт |

**Причина «НЕ работает»:** маршрутизация кабинета целиком живёт в
`plugins/idpg-core/includes/class-organizer-url.php` (`IDPG_Organizer_URL`, 479 стр.),
но файл **отсутствует в списке `require_once`** в `plugins/idpg-core/idpg-core.php`
(блок строк 75–119) и `::init()` нигде не вызывается. В результате rewrite-правила
`^organizer/?$`, `^organizer/(dashboard|events|profile)/?$`, query vars
`idpg_organizer_page`/`idpg_organizer_section` и фильтр `template_include` **не регистрируются**.

Дополнительно **не создана** страница WP `/organizer/`:
- `idpg_core_create_organizer_page()` и опция `_idpg_organizer_page_id` существуют **только в docblock'ах**;
- в `themes/idpg_core/functions.php` авто-создаются только `/login/`, `/reset-password/`, `/terms/`,
  `/privacy/`, `/offer/` (строки 516–696) — организатора нет.

---

## 2. REST-маршруты ЛК (namespace `idpg/v1`)

Зарегистрировано в `class-rest-auth.php` (`register_routes()`, строки 62–209) — **ровно 6 маршрутов**:

| Маршрут | Метод | Статус |
|---|---|---|
| `/auth/login` | POST | ✅ есть |
| `/auth/register` | POST | ✅ есть |
| `/auth/logout` | POST | ✅ есть |
| `/auth/forgot-password` | POST | ✅ есть |
| `/auth/reset-password` | POST | ✅ есть |
| `/auth/me` | GET | ✅ есть |

| Маршрут | Статус |
|---|---|
| `/auth/organizer-complete` | ❌ **НЕТ** (DEV-01, P0) |
| `/organizer/events` (GET/POST/PUT/DELETE) | ❌ **НЕТ** |
| `/organizer/events/{id}/submit` | ❌ **НЕТ** |
| `/organizer/profile` | ❌ **НЕТ** |
| `/organizer/stats` | ❌ **НЕТ** |

---

## 3. Классы, отвечающие за ЛК

| Класс / файл | Что делает | Подключён? |
|---|---|---|
| `IDPG_Organizer_URL` — `includes/class-organizer-url.php` | Роутер кабинета: rewrite `/organizer/` + разделы `dashboard\|events\|profile`, query vars, `template_include` → `page-organizer.php`, `get_access_decision()` (гость/pending/denied/allowed), `after_login_url()` | ❌ **НЕТ** — не в `require_once` `idpg-core.php`, `::init()` не вызывается |
| `IDPG_Organizer_Page` — `includes/class-organizer-page.php` | Маршрут `/organizer/complete/` + шаблон + защита доступа; `is_pending()`; админ-notice для pending-организаторов; `assets()` — **пустая заглушка** | ✅ да (`idpg-core.php:148`) |
| `IDPG_Organizer_Scope` — `includes/class-organizer-scope.php` | Изоляция медиатеки для организатора/подписчика (admin `pre_get_posts` + REST `rest_attachment_query`, только свои вложения) | ✅ да (самоинициализация, стр. 56 файла) |
| `IDPG_Organizer_Verify` — `includes/class-organizer-verify.php` | Подтверждение регистрации организатора по email: ключ `wp_hash` + срок 3 суток в user meta, `hash_equals`, выдача роли `event_organizer` через прямую запись `wp_capabilities`, действие `idpg_organizer_verified` | ✅ да (`idpg-core.php:140`) |
| `IDPG_REST_Auth` — `includes/class-rest-auth.php` | Авторизация: 6 маршрутов, pending-блок входа (`403 registration_pending`), honeypot, rate limiter | ✅ да (`idpg-core.php:138`). **`handle_organizer_complete()` НЕТ** |

**Известный баг (из аудита, не исправлен):** флаг «анкета не доукомплектована» пишется под
`idpg_org_pending` (без подчёркивания, `IDPG_Organizer_Verify::prepare()`), а читается под
`_idpg_org_pending` (с подчёркиванием, `IDPG_Organizer_Page::is_pending()` и
`page-organizer-complete.php`). `IDPG_Organizer_URL::is_pending_organizer()` (не подключён) читает
оба ключа в обход. Требуется унификация ключа (S).

---

## 4. Шаблоны темы `themes/idpg_core/`

| Шаблон | Строк | Содержимое | Подключён? |
|---|---|---|---|
| `page-organizer.php` | 161 | Доступ: гость → `/login/`, pending → `/organizer/complete/`, без прав → `403`; серверный рендер сайдбара + разделов, `get_template_part('template-parts/organizer/section', $slug)` | Шаблон есть; маршрутизация к нему не подключена |
| `page-organizer-complete.php` | 186 | Форма доуполнения анкеты (org_name, contact_name, email, phone, password), POST на REST `/idpg/v1/auth/organizer-complete` | ✅ активен (через `IDPG_Organizer_Page`), бэкенд формы отсутствует |
| `template-parts/organizer/section-dashboard.php` | 63 | 4 стат-карточки со значениями-заглушками `&mdash;` | — |
| `template-parts/organizer/section-events.php` | 38 | Заглушка «Раздел в разработке» + кнопка в `wp-admin` | — |
| `template-parts/organizer/section-profile.php` | 55 | Профиль read-only (`<dl>`) | — |
| `inc/organizer-menu.php` | 233 | `idpg_organizer_menu()` (сортировка по `order`, фильтр `idpg_organizer_menu_items`), `idpg_organizer_section_url()`, `idpg_organizer_current_section()`, `idpg_core_is_organizer_cabinet()`, `idpg_organizer_after_login_url()`, `idpg_organizer_hide_before_footer_widgets()` | ❌ **НЕТ** — не входит в `require_once` `functions.php` (строки 351–381); все `idpg_organizer_*()` не определены в рантайме |

---

## 5. Ассеты

| Файл | Строк | Назначение | Подключён? |
|---|---|---|---|
| `themes/idpg_core/assets/js/organizer.js` | 92 | Переключение разделов ЛК без перезагрузки (`history.pushState` + `popstate`, фокус) | ❌ нет `wp_enqueue_script` |
| `themes/idpg_core/assets/css/organizer.css` | 295 | Стили `.idpg-org-page` (сетка, навигация, карточки, 403) | ❌ нет `wp_enqueue_style` |
| `themes/idpg_core/assets/js/organizer-complete.js` | 109 | `fetch` POST на `/auth/organizer-complete` (`window.idpgOrganizerComplete.restUrl`) | ❌ нет + целевой REST-маршрут не зарегистрирован |
| `plugins/idpg-core/assets/js/organizer-settings.js` | 91 | Админ: повторитель соцсетей площадки (VK/TG/YouTube/Rutube/OK/Dzen) | ❌ нет (орфан) |
| `plugins/idpg-core/assets/css/organizer-settings.css` | 285 | Стили админ-страницы (страница удалена в `class-roles.php:354`) | ❌ нет (орфан) |
| `organizer-event-form.js` | — | **НЕ СУЩЕСТВУЕТ** | — |

`wp_enqueue_*` с хендлом/источником, содержащим "organizer", **отсутствуют во всём репозитории**.
Создание/редактирование события сегодня идёт только в wp-admin (Carbon Fields).

---

## 6. DEV-01: маршрут `POST /auth/organizer-complete`

**НЕ ЗАРЕГИСТРИРОВАН.** В `class-rest-auth.php` 6 маршрутов, `handle_organizer_complete()` в коде нет
(grep по всем `*.php` — ноль совпадений). Форма `page-organizer-complete.php` и
`organizer-complete.js` (строка 81) отправляют на `/wp-json/idpg/v1/auth/organizer-complete`
и получают **404**. Подтверждено документацией: `docs/TZ_BACKLOG.md` DEV-01, `docs/FEATURES.md`
раздел 5 п.1.

---

## 7. Модерация по платности — `class-event-moderation.php`

Класс загружен (`idpg-core.php:151`), активен. Реализованные правила:

| Правило | Статус |
|---|---|
| Организатор без `publish_events` не может публиковать; событие принудительно переводится в `pending` (`wp_insert_post_data`, фаза 1) | ✅ полностью |
| Бесплатное событие (`_idpg_is_free = yes`) авто-публикуется без модерации (фаза 2, за флагом `$bypass_enforcement`) | ✅ полностью |
| Платное событие с неполными билетными данными (`_idpg_price_from`, `_idpg_ticket_type`, `_idpg_ticket_url`/`_idpg_widget_code`) **понижается из `pending` в `draft`** через `$wpdb->update()` + транзиент уведомления администратору | ✅ полностью |
| Письмо админам при новом pending-событии; письмо автору при публикации | ✅ полностью |
| Админ/редактор публикует напрямую (`publish_events`) | ✅ полностью |

**Чего нет:** механизма «заплати и пройди модерацию» — оплата в модерацию не влияет.
Единственный смежный механизм — платное закрепление в баннер (`_idpg_banner_request` →
подтверждение админом `_idpg_banner_approved`), к публикации события отношения не имеет.

---

## 8. 152-ФЗ / ФТ-5

Модуль `plugins/idpg-core/includes/class-privacy.php` (`IDPG_Privacy`, 1318 стр.) —
**полностью реализован в коде, но НЕ ЗАГРУЖЕН**: нет `require_once` в `idpg-core.php`,
нет `IDPG_Privacy::instance()` в `idpg_core_init()`. Весь функционал — мёртвый код в рантайме.

| Требование | Статус по коду | Детали |
|---|---|---|
| Отдельное согласие на обработку ПДн (`idpg_pdn_consent`) | ⚠️ реализовано **только внутри не загруженного** `class-privacy.php` | `record_consent()` (196–219), мета `idpg_pdn_consent` (given/version/source/ip_hash); в REST `handle_register()` и форме регистрации параметра **нет** |
| Cookie-баннер | ⚠️ мёртвый код | `idpg_cookie_consent` (1 год, `all`\|`necessary`, работа без JS через nonce), `assets/js/privacy.js`, `assets/css/privacy.css` |
| Хеш IP | ⚠️ частично | `hash_client_ip()` — только в мёртвом классе; активный код: таблица подписчиков хранит IP **как есть** (`idpg_subscribers.ip`), rate limiter использует `md5(ip)` |
| Выгрузка данных субъекта (JSON/CSV) | ⚠️ мёртвый код | `handle_export()`/`send_export()`/`collect_user_data()` (1028–1222); админ-страница `idpg-privacy` |
| Удаление данных по запросу | ⚠️ мёртвый код | `handle_subject_request()`/`handle_delete()` (490–1021); WP-встроенные `wp_privacy_personal_data_erasers`/`exporters` **не используются** (grep — нет совпадений) |
| Соединение с шаблоном `/privacy/` | ⚠️ мёртвый код | `page-privacy.php` (72 стр., статичный текст) **не вызывает** `render_subject_tools()` |
| ФЗ-5 / 524-ФЗ | ❌ упоминаний нет | — |

**Документация vs код:** `docs/FEATURES.md` (разделы 1.4 и 1.4а) и `TZ_BACKLOG.md` DEV-06 описывают
согласие ПДн, cookie-баннер и права субъекта как «выполнено в 1.1.6» — **в рабочем дереве (1.1.2)
этого нет**, код находится в незагруженном классе.

---

## 9. Gap-лист

| # | Gap | Статус | Связано с |
|---|---|---|---|
| G1 | `class-organizer-url.php` не подключён → весь `/organizer/` (route/query/template) мёртв; страница `/organizer/` в БД не создана | P0 | DEV-34 («выполнено», но в дереве нет) |
| G2 | `inc/organizer-menu.php` не подключён → `idpg_organizer_*()` хелперы не определены | P0 | DEV-34/37/38 |
| G3 | `organizer.js` / `organizer.css` не подключаются | P0 | DEV-34 |
| G4 | REST `POST /auth/organizer-complete` не зарегистрирован → анкета `/organizer/complete/` падает 404 | P0 | DEV-01 |
| ~~G5~~ | Маршруты `/organizer/event/new/`, `/organizer/event/{id}/` + REST `/organizer/events*` + `organizer-event-form.js` — не существуют | **Закрыто DEV-94 (29.09.2026)**: маршруты + шаблон + скрипт формы | — |
| ~~G6~~ | Разделы `dashboard`/`events` — заглушки без реальных данных; REST `/organizer/stats`, `/organizer/profile` нет | **Закрыто DEV-94 (29.09.2026)**: разделы наполняются из `/organizer/stats` и `/organizer/events` | — |
| G7 | Флаг pending-анкеты: рассинхрон `idpg_org_pending` / `_idpg_org_pending` | P2 | DEV-01 попутно |
| G8 | `class-privacy.php` (152-ФЗ: согласие, баннер, права субъекта, хеш IP) — код есть, не загружен | P0 (законодательство) | DEV-06 |
| G9 | `class-organizer-verify::is_pending()` требует роль `event_organizer`, которой нет сразу после регистрации (пользователь без роли) → pending-проверка может пропустить | P2 | DEV-32 |
| G10 | `_idpg_banner_approved` — модерация по платности есть только для баннера, не для публикации события. «Платная модерация события» как продуктовая фича — не задокументирована | P2 (решение владельца) | — |

---

## 10. Корректировка DEV-33…36 по итогам аудита

Аудит проводился «сверху вниз» по запрошенным пунктам; **номера DEV-33…36 в `TZ_BACKLOG.md` заняты**
(DEV-33 — rate limiter, DEV-34 — каркас ЛК). Заявленные «DEV-33…36» для ЛК-событий фактически не
имеют места в текущей нумерации. Предлагаемое соответствие по факту кода:

| Заявленная тема | Реальное состояние | Действие |
|---|---|---|
| «DEV-33–34: каркас ЛК» | Файлы каркаса есть, обвязка (`require_once`, страница в БД, ассеты, хелперы) **не применена** | Закрыть G1–G3 (подключение обвязки) — по сути «уже сделано, но не подключено» |
| «DEV-35: список/создание событий в ЛК» | Раздел `section-events.php` — заглушка; REST и форм нет | **Закрыто DEV-94 (29.09.2026)**: REST `/organizer/events` (CRUD), `/organizer/event/new/` + шаблон + `organizer-event-form.js`, разделы `dashboard`/`events` наполняются из API |
| «DEV-36: доуполнение анкеты» | REST-маршрут отсутствует (G4) — DEV-01 | Считать **открытым** (DEV-01), дублировать не нужно |

Полная переклассификация DEV-33…36 — после подтверждения владельцем определения этих номеров
(см. `TZ_BACKLOG.md`, раздел «Нумерация» DEV-34).