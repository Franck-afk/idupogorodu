# Документация проекта «Иду по городу» (idpg_core)

> ⚠️ **Восстановлен из git-истории 24.10.2026** (файл удалялся в HEAD). Содержимое
> описывает более раннее состояние кодовой базы: версии плагина 1.0.x и темы 1.0.2,
> Carbon Fields как действующую технологию (удалена по DEV-311), ЛК организатора
> как веб-страницы (удалены DEV-234/235). Актуальное состояние — `docs/PROJECT.md`
> (раздел 1) и исходный код; этот файл — историческая справка.

Полная техническая документация по результатам аудита кодовой базы.
Локальная среда: Laragon (Windows), PHP 8.2, WP_CLI доступен через
`D:\laragon\bin\php\php-8.2.29-nts-Win32-vs16-x64\php.exe`.

---

## 1. ОБЩАЯ ИНФОРМАЦИЯ

| Параметр | Значение |
|---|---|
| Название | Иду по городу (IDPG / IDUPOGORODU) |
| Тип | Сайт-афиша мероприятий (городская афиша + площадки + личный кабинет) |
| Домен (прод) | https://idupogorodu.ru (в редиректе на хостинг) |
| Тема | **idpg_core** (папка `wp-content/themes/idpg_core`) |
| Плагин | **idpg-core** (папка `wp-content/plugins/idpg-core`) |
| Версия темы | 1.0.2 (константа `IDPG_VERSION`), в заголовке style.css — 1.0.0 |
| Версия плагина | 1.0.0 (`IDPG_CORE_VERSION`) |
| Requires WP | 6.0 (оба), Tested up to 6.7 (только тема) |
| Requires PHP | 7.4 |
| Text Domain | `idpg-core` (плагин), `idpg_core` (тема) |
| Лицензия | GPL v2 or later |

> Примечание: в исходных ТЗ тема фигурирует как «idpg-theme», фактическое имя папки и префикс — `idpg_core`. Термины «idpg-theme» и «idpg_core» в документации взаимозаменяемы.

### Компоненты

- **Тема** `idpg_core` — шаблоны, стили (SCSS→CSS), фронтенд-скрипты, виджеты рекламы, области виджетов, автосоздание страниц и меню.
- **Плагин** `idpg-core` — вся бизнес-логика: CPT, таксономии, Carbon Fields, REST API, авторизация, роли, календарь, баннеры, синк билетов, iCal, режим обслуживания.
- **Carbon Fields** — библиотека полей, поставляется в **vendor темы** (Composer). Плагин зависит от активной темы (архитектурное замечание).
- **clearfy-pro** (третий плагин) — CPT/таксономий не регистрирует, только `remove_filter('register_post_type_args')`.

---

## 2. СТРУКТУРА ФАЙЛОВ

### 2.1. Дерево темы (`wp-content/themes/idpg_core`)

```
idpg_core/
├── functions.php              — константа версии, setup, enqueue, автосоздание страниц/меню
├── style.css                  — скомпилированный CSS (из assets/scss/style.scss)
├── theme.json                 — настройки редактора Gutenberg
├── composer.json              — Carbon Fields + autoload (composer.lock не закоммичен)
├── package.json               — сборка SCSS (sass), линтеры, bundle (zip)
├── README.md / readme.txt / LICENSE / CODING_STANDARDS.md / IMPLEMENTATION.md
├── screenshot.png
├── header.php / footer.php / index.php / single.php / archive.php
├── front-page.php / search.php / 404.php / page.php
├── page-favorites.php / page-login.php / page-reset-password.php
├── page-terms.php / page-privacy.php
├── page-about.php / page-contacts.php / page-legal.php
├── page-organizer.php / page-organizer-event.php / page-organizer-venue.php
├── template-parts/
│   ├── content.php / content-page.php / content-none.php
│   ├── content-event.php / content-event-today.php / content-venue.php / home-section.php
│   └── organizer/
├── inc/
│   ├── theme-helpers.php          — метабокс скрытия заголовка, хелперы
│   ├── widgets.php                — IDPG_Ad_Ru_Widget, IDPG_Recent_Events_Widget, IDPG_Subscribe_Widget
│   ├── widget-areas.php           — система «областей виджетов» (позиции + условия показа)
│   └── class-idpg-nav-walker.php  — кастомный Walker меню
├── fonts/
│   └── gilroy-regular.ttf
├── assets/
│   ├── js/  navigation.js, auth.js, live-search.js, organizer.js, widget-ad.js и др.
│   ├── css/ editor-style.css, widget-areas-admin.css
│   ├── icons/ telegram.svg, vk.svg, dzen.svg
│   └── scss/ style.scss, _responsive.scss, base/, components/, pages/
└── vendor/  (Composer: htmlburger/carbon-fields и зависимости)
```

### 2.2. Дерево плагина (`wp-content/plugins/idpg-core`)

```
idpg-core/
├── idpg-core.php              — header, константы, автозагрузка 39 классов, entry point
├── .cursorrules
├── assets/
│   ├── css/  current-event-banner.css, city-selector.css, event-calendar.css,
│   │         editor.css, admin-maintenance.css, favorites.css, privacy.css,
│   │         settings.css, organizer-settings.css, expected-events.css, dashboard-stats.css
│   └── js/   city-selector.js, current-event-banner.js, event-calendar.js, admin-maintenance.js,
│             social-icons.js, favorites.js, privacy.js, settings.js, organizer-settings.js, ticket-clicks.js
└── includes/                  — 39 классов (все синглтоны)
    ├── class-cpt.php                  — CPT event/venue + таксономии
    ├── class-roles.php                — роль event_organizer + capabilities
    ├── class-city-filter.php          — фильтрация по городу, cookie, AJAX set_city, IP
    ├── class-template-helpers.php     — хелперы шаблонов (сейчас не задействованы)
    ├── class-ticket-sync.php          — синк билетов (крон, внешний API)
    ├── class-rest-api.php             — публичные и защищённые REST-эндпоинты (15 маршрутов)
    ├── class-ical-export.php          — экспорт событий в iCal (.ics)
    ├── class-current-event-banner.php — баннеры на главной: позиции hero/middle/bottom, запрос и подтверждение на событии
    ├── class-meta.php                 — Carbon Fields (поля event/venue) + register_post_meta
    ├── class-migrations.php           — миграции мета-ключей на _idpg_ (вкл. WP-CLI)
    ├── class-views.php                — счётчики просмотров/кликов, колонка в админке
    ├── class-notifications.php        — email-уведомления об изменении статуса билетов
    ├── class-auth.php                 — авторизация, редиректы, доступ к админке
    ├── class-rest-auth.php            — REST-эндпоинты авторизации (6 маршрутов)
    ├── class-auth-rate-limiter.php    — лимиты попыток (вход/регистрация/сброс)
    ├── class-auth-emails.php          — письма (приветствие, сброс пароля), HTML-шаблон
    ├── class-maintenance.php          — режим обслуживания (заглушка, пароль, роли)
    ├── class-city-selector-widget.php — виджет «выбор города» (Tom Select, дерево городов)
    └── class-event-calendar-widget.php— виджет «календарь событий» (AJAX, кэш)
```

### 2.3. Карта зависимостей

```
wp-config.php
 └── wp-content/themes/idpg_core/functions.php
      ├── vendor/autoload.php (Composer → Carbon Fields) — boot на after_setup_theme (prio 5)
      ├── inc/theme-helpers.php, inc/widgets.php, inc/widget-areas.php,
      │   inc/class-idpg-nav-walker.php
      └── использует плагин: CSS/JS city-selector (если IDPG_CORE_URL), REST idpg/v1

wp-content/plugins/idpg-core/idpg-core.php
  ├── require 22 файлов includes/*.php (порядок: базовые → баннеры (ранние) → остальные)
  ├── ранние синглтоны до init: IDPG_Banners::instance(), IDPG_Current_Event_Banner::instance()
  │     (подписка на carbon_fields_register_fields до диспетчеризации на init)
  └── idpg_core_init (init) → создаёт остальные 16 синглтонов
        → CPT → Roles → City_Filter → Template_Helpers → Ticket_Sync
        → REST_API → iCal_Export → Meta → Migrations → Views
        → Notifications → Auth → REST_Auth → Rate_Limiter → Auth_Emails
        → Maintenance → City_Selector_Widget → Event_Calendar_Widget

Внешние сервисы: API билетов (заглушка https://api.example.com/v1),
IP-геолокация (ip-api.com / ipapi.co), Gravatar (аватары), wp_mail, Iconify (иконки)
```

---

## 3. CPT И ТАКСОНОМИИ

### 3.1. CPT (class-cpt.php)

| CPT | Slug (URL) | Описание | Supports | REST | Связанные таксономии |
|---|---|---|---|---|---|
| `event` | `afisha` (`/afisha/`) | События | title, editor, thumbnail, autosave | `show_in_rest: true` | event_category, age_rating, city |
| `venue` | `venues` (`/venues/`) | Площадки | title, editor, thumbnail, autosave | `show_in_rest: true` | city |

- Специальных шаблонов нет — вывод через общие `single.php` / `archive.php`.
- Capability type: event — `capability_type => 'event'`, venue — `'post'`.

### 3.2. Таксономии

| Таксономия | Slug (URL) | Иерархич. | Объекты | REST | Примечание |
|---|---|---|---|---|---|
| `event_category` | `event-category` | да | event | да | Категории событий |
| `age_rating` | `age-rating` | нет | event | да | Возрастной рейтинг |
| `city` | `gorod` (`/gorod/<slug>/`) | да | event, venue | да | Гео-привязка, 3 уровня: регион → город → район |

### 3.3. Данные таксономии city (текущее состояние БД)

```
Удмуртская Республика [14] (корень)
├── Ижевск [15]  — 3 события
│   ├── Индустриальный район [22]
│   ├── Ленинский район [23]
│   ├── Октябрьский район [21]
│   ├── Первомайский район [20]
│   └── Устиновский район [24]
├── Воткинск [16]  — 0 событий
├── Глазов [17]    — 0 событий
├── Можга [19]     — 0 событий
└── Сарапул [18]   — 0 событий
```

- Удмуртия в базе есть. Все 3 существующих события привязаны к Ижевску.
- Дефолтный город: `IDPG_DEFAULT_CITY_ID = 15` (Ижевск) — захардкожен в двух местах.

---

## 4. КАСТОМНЫЕ ПОЛЯ

Всего **6 контейнеров Carbon Fields** (5 post_meta + 1 theme_options). Хук: `carbon_fields_register_fields`.

### 4.1. Контейнеры для CPT `event` (class-meta.php)

| Контейнер | Контекст | Поля |
|---|---|---|
| **Основная информация** (стр. 268) | normal | `idpg_start_date` (date), `idpg_end_date` (date), `idpg_all_day` (checkbox), `idpg_sessions` (complex, tabbed-horizontal) → `time` (time H:i), `price` (text number), `url` (text url) |
| **Событие: место и продвижение** (стр. 329) | side (вкладки) | Tab «Место»: `idpg_venue` (select), `idpg_address`, `idpg_coordinates_lat`, `idpg_coordinates_lng`. Tab «Дополнительно»: `idpg_source`, `idpg_promoted` (checkbox), `idpg_is_online` (checkbox), `idpg_stream_url`, `idpg_hide_from_banner`, `idpg_hide_full_banner`, `idpg_views`, `idpg_clicks` |
| **Билеты и цены** (стр. 385) | normal | `idpg_ticket_status` (select, default in_sale), `idpg_is_free` (checkbox), `idpg_currency` (select, default RUB), `idpg_vip_price`, `idpg_price_from`, `idpg_price_to`, `idpg_ticket_type` (select, default external_link), `idpg_external_id`, `idpg_ticket_url`, `idpg_widget_code` (textarea) |
| **Медиа** (стр. 460) | normal | `idpg_video_trailer` (text), `idpg_gallery` (media_gallery) |

### 4.2. Контейнер для CPT `venue`

| Контейнер | Контекст | Поля |
|---|---|---|
| **Данные площадки** (стр. 480) | normal | `idpg_venue_address`, `idpg_venue_phone`, `idpg_venue_email`, `idpg_venue_website`, `idpg_venue_lat`, `idpg_venue_lng`, `idpg_venue_capacity` |

### 4.3. Баннеры

Отдельного глобального контейнера `theme_options` и меню «Баннеры» в админке **нет**. Баннер реализован в `class-current-event-banner.php` и управляется двумя флагами события (Carbon Fields, `class-meta.php`):

- `_idpg_banner_request` — «Запрос на баннер на главной»: организатор включает на своём событии.
- `_idpg_banner_approved` — «Разрешить баннер на главной»: администратор подтверждает.
- Позиции вывода задаёт тема через `do_action( 'idpg_banner_position', … )`: `hero`, `middle`, `bottom` (`front-page.php`).
- Период показа и скрытие — из дат события (`_idpg_start_date`, `_idpg_end_date`) и флагов `_idpg_hide_full_banner`, `_idpg_hide_from_banner`.

### 4.4. Поля пользователя (организатор)

Carbon-контейнера для пользователей **нет**. Организатор хранится как user meta: `idpg_org_name`, `idpg_contact_name`, `idpg_phone`, `idpg_avatar_attachment_id` + роль `event_organizer`. Запись — через REST `/auth/register` (поля организатора передаются при регистрации). Эндпоинтов `/user/profile`, `/user/stats`, `/user/avatar`, `/user/city-events` в проекте **нет** — они удалены в 1.1.0, личный кабинет на `/lk/` заменён страницей избранного.

### 4.5. Мета в БД (актуально)

- event: 33 ключа с данными (все поля контейнеров присутствуют), + служебные `_idpg_meta_migrated`, `_idpg_sessions_json`, ключи complex `idpg_sessions|||0|value` и т.п.
- venue: 0 записей, полей в БД нет.
- Расхождений «код ↔ Carbon ↔ БД» нет.

---

## 5. ФУНКЦИИ

Статистика: **41 глобальная функция + 229 методов + 10 function_exists**. Реестров аудита (`wp-content/audit-functions.txt`, `wp-content/audit-functions.php`, `wp-content/audit-fields.php`) в рабочем дереве нет — это снимок на момент написания отчёта.

### 5.1. Глобальные функции по категориям

**Регистрация (CPT/таксономии/Carbon/роли):** `idpg_core_init`, `idpg_core_activate`, `idpg_core_deactivate`, `idpg_core_register_plugin_widgets`, `idpg_core_register_widgets`, `idpg_core_register_sidebars`, `idpg_carbon_fields_boot`.

**Фронтенд/вывод:** `idpg_core_scripts`, `idpg_core_image_sizes`, `idpg_core_print_fonts`, `idpg_core_setup`, `idpg_core_sort_home_sidebar_widgets`, `idpg_core_ad_widget_admin_assets`, `idpg_widget_areas_render` (~20 вызовов), `idpg_is_title_hidden` (~5), `idpg_widget_areas_all`/`display_options`/`positions`/`select`/`slugify`/`matches`/`plural`.

**Обработка данных:** `idpg_core_add_title_meta_box`, `idpg_core_save_title_meta`, `idpg_page_title_meta_box_html`, `idpg_core_calendar_flush_on_save` (сброс кэша календаря).

**Автосоздание контента:** `idpg_core_create_default_menu`, `idpg_core_create_lk_page`, `idpg_core_create_login_page`, `idpg_core_create_reset_password_page`, `idpg_core_create_terms_page`, `idpg_core_create_privacy_page`.

**Хуки-фильтры:** `idpg_core_disable_section_menu_items` (wp_nav_menu_objects), `idpg_core_get_avatar_url` (get_avatar_url).

### 5.2. Методы классов — ключевые (по классам)

| Класс | Методов | Ключевые методы |
|---|---|---|
| Maintenance | 30 | maybe_show_maintenance, render_maintenance_page, handle_access_password, is_whitelisted_ip, get_social_icons, sanitize_* |
| City_Selector_Widget | 22 | widget, get_city_tree, resolve_current_city, detect_city_by_ip, request_ip_data |
| REST_API | 20 | get_events, get_venues, get_venue, create_venue, update_venue, get_terms, user/profile, user/stats, user/avatar, user/city-events |
| Meta | 18 | register_carbon_fields, register_event_*_fields, register_venue_fields, mirror_sessions, sanitize_carbon_field |
| City_Filter | 17 | filter_by_city, get_current_city_id, get_city_tree, set_city_cookie, ajax_set_city |
| Event_Calendar_Widget | 16 | widget, render_month/week/list, query_month_events, build_month_grid, ajax_month |
| Current_Event_Banner | 16 | render, get_current_event, get_event_meta_line, format_price, format_date_range |
| iCal_Export | 15 | maybe_export, export_single/month/all, generate_*_ical, escape_ical_text |
| Banners | 15 | render, render_html, render_simple, with_utm, get_all_data, is_active_now, purge_cache |
| Views | 13 | track_view, increment_views, increment_clicks, is_bot, is_recent_view |
| Migrations | 13 | maybe_run, run_all, migrate_post, seed_sessions, cli_migrate |
| Ticket_Sync | 12 | schedule/unschedule/run_sync, sync_single_event, build_api_url, process_api_response |
| REST_Auth | 11 | handle_login/register/logout/forgot/reset/me, get_redirect_url_by_role |
| Auth_Emails | 11 | send_welcome_email, set_from_email/name, render_email_template, render_button |
| Auth | 10 | redirect_after_login/logout, restrict_admin_access, set_login_url |
| CPT | 9 | register_post_types, register_taxonomies, register_event_post_type, register_venue_post_type, register_city_taxonomy |
| Rate_Limiter | 8 | check_login/registration/password_reset_attempt, get_client_ip, is_proxy_address |
| Widgets (тема) | 8 | widget/form/update (Ad + Sticky Ad) |
| Template_Helpers | 6 | get_ticket_button, get_event_sessions, parse_sessions, get_event_meta, get_cities |
| Roles | 6 | create_roles, remove_roles, sync_role_caps, get_role_capabilities |
| Notifications | 5 | on_status_changed, send_status_notification, get_status_label |
| Nav_Walker | 4 | start_el/end_el, start_lvl/end_lvl |

### 5.3. Мёртвый код

| Функция | Статус |
|---|---|
| `IDPG_Template_Helper` (5 методов) | Не вызываются — шаблоны рендерят данные инлайн; используется только `CACHE_PREFIX` |
| `IDPG_Views::increment_clicks` | Вызывается из `IDPG_REST_API::track_click` (POST `/track/click`) |
| `IDPG_Ticket_Sync::run_sync_now` | Вызывается из WP-CLI `wp idpg sync-tickets` |

### 5.4. Дубликаты

- `get_venue` — баннерный хелпер vs REST-колбек (разные классы, конфликт смыслов)
- `get_city_tree` — `IDPG_City_Filter` и `IDPG_City_Selector_Widget` держат собственные реализации с кешем (static + transient)
- `get_client_ip` — устранено (`DEV-22`): оба метода делегируют `IDPG_Helpers::get_client_ip()`

---

## 6. API ENDPOINTS

Неймспейс: `idpg/v1` → `https://{домен}/wp-json/idpg/v1`. Итого **36 вызовов `register_rest_route()`** из 5 файлов классов (регистрация на `rest_api_init`): 21 в `idpg-core` (разделы 6.1–6.2) + 15 в `idpg-core`/`idpg-auth`, не описанные ниже (раздел 6.4). В 6.4 `/organizer/profile` даёт две строки, поэтому строк в таблицах 37, а вызовов 36.

### 6.1. Публичные (14, `__return_true`)

| Route | Метод | Callback | Параметры |
|---|---|---|---|
| `/events` | GET | get_events | city, category, date_from, date_to, search, page, per_page |
| `/venues` | GET | get_venues | city, search, page, per_page |
| `/venues/{id}` | GET | get_venue | id |
| `/cities` | GET | get_terms | taxonomy=city |
| `/categories` | GET | get_terms | taxonomy=event_category |
| `/age-ratings` | GET | get_terms | taxonomy=age_rating |
| `/event-dates` | GET | get_event_dates | year, month |
| `/search/live` | GET | search_live | q |
| `/search/popular` | GET | search_popular | — |
| `/subscribe` | POST | subscribe | email, event_id |
| `/auth/login` | POST | handle_login | email, password, remember |
| `/auth/register` | POST | handle_register | type (user/organizer), name, email, password, org_name, contact_name, phone, terms |
| `/auth/forgot-password` | POST | handle_forgot_password | email |
| `/auth/reset-password` | POST | handle_reset_password | user_id, reset_key, new_password |

### 6.2. Защищённые (7)

| Route | Метод | Permission callback | Параметры |
|---|---|---|---|
| `/venues/create` | POST | can_manage_events (nonce + edit_posts) | title, content, address, phone, email, website, lat, lng, capacity, city, thumbnail |
| `/venues/{id}` | PUT | can_manage_events | то же + id |
| `/track/click` | POST | can_track_click (nonce из заголовка `X-WP-Nonce` или параметра `_wpnonce`) | event_id |
| `/city/select` | POST | can_select_city (nonce) | city_id (absint) |
| `/city/confirm` | POST | can_select_city (nonce) | — подтверждает город из cookie (DEV-19) |
| `/auth/logout` | POST | can_logout (только авторизованный) | — |
| `/auth/me` | GET | is_user_logged_in | — |

Учёт кликов по билетам — `POST /track/click`, permission callback `can_track_click` (принимает nonce из заголовка `X-WP-Nonce` или из параметра `_wpnonce` в URL — так работает `navigator.sendBeacon`).

### 6.3. Использование во фронтенде

- **Используются:** `/auth/login`, `/auth/register`, `/auth/forgot-password`, `/auth/reset-password` (auth.js), `/city/select`, `/city/confirm` (navigation.js), `/track/click` (ticket-clicks.js), `/search/popular`, `/search/live` (live-search.js), `/subscribe` (subscribe.js), `/favorites`, `/favorites/add`, `/favorites/remove` (favorites.js), `/organizer/*` (organizer.js через `data-idpg-profile-rest` в `section-profile.php`)
- **Не используются в JS:** `/auth/logout`, `/auth/me`, `/events`, `/venues`, `/venues/{id}`, `/venues/create`, `/cities`, `/categories`, `/age-ratings`, `/event-dates`, `/vk/*`, `/yandex/*`, `/max/validate` (резерв под внешних потребителей; кнопки соцвхода рендерит PHP)
- Документация: только docblock в коде; OpenAPI/Swagger/Postman-коллекции нет.

### 6.4. Остальные маршруты неймспейса (15 вызовов `register_rest_route`, 16 строк)

> **Как считали.** 36 = 31 вызов `register_rest_route()` в `idpg-core` (`class-rest-api` 15 + `class-rest-auth` 6 + `class-rest-organizer` 7 + `class-favorites` 3) + 5 маршрутов соцпровайдеров `idpg-auth`, которые базовый класс `class-wp-auth-ru-provider::register_rest_routes()` регистрирует циклом по массиву `get_rest_routes()`. Эти 5 в таблицах 6.1–6.2 не учтены — они в разделе 6.4. Файлов с вызовом `register_rest_route()` — 5 (четыре класса `idpg-core` плюс базовый `class-wp-auth-ru-provider`); маршруты соцвхода приходят из `get_rest_routes()` трёх подклассов-провайдеров.

Регистрируются в том же неймспейсе `idpg/v1`, но в других классах.

**`idpg-core`, `class-favorites.php` (3, `is_user_logged_in`)**

| Route | Метод | Callback |
|---|---|---|
| `/favorites` | GET | rest_list |
| `/favorites/add` | POST | rest_add |
| `/favorites/remove` | POST | rest_remove |

**`idpg-core`, `class-rest-organizer.php` (7 вызовов `register_rest_route`, кабинет организатора)** — `/organizer/profile` зарегистрирован одним вызовом с двумя наборами методов, поэтому строк в таблице 8.

| Route | Метод | Callback | Permission callback |
|---|---|---|---|
| `/organizer/events` | GET | get_events | can_organizer |
| `/organizer/events/{id}` | GET | get_event | can_access_event |
| `/organizer/events/{id}/submit` | POST | submit_event | can_access_event |
| `/organizer/stats` | GET | get_stats | can_organizer |
| `/organizer/venues` | GET | get_venues | can_organizer |
| `/organizer/venues/{id}` | GET | get_venue | can_access_venue |
| `/organizer/profile` | GET | get_profile | can_profile |
| `/organizer/profile` | POST/PUT/PATCH | update_profile | can_profile |

**`idpg-auth`, соцпровайдеры (5, `__return_true`)** — регистрируются только если провайдер включён в настройках (иначе `register_rest_routes()` выходит рано). Экземпляры создаются в `idpg-auth.php` через `::instance()` для Yandex, VK и MAX. Кнопку входа рендерит `render_button_wp()`, а не JS темы, поэтому в 6.3 эти маршруты не числятся.

| Route | Метод | Callback | Файл |
|---|---|---|---|
| `/vk/start` | GET | rest_start | `class-wp-auth-ru-vk.php` |
| `/vk/callback` | GET | rest_callback | `class-wp-auth-ru-vk.php` |
| `/yandex/start` | GET | rest_start | `class-wp-auth-ru-yandex.php` |
| `/yandex/callback` | GET | rest_callback | `class-wp-auth-ru-yandex.php` |
| `/max/validate` | POST | rest_validate_initdata | `class-wp-auth-ru-max.php` |

---

## 7. ШАБЛОНЫ

Тема `idpg_core` (корень):

| Файл | Назначение | Используемые данные |
|---|---|---|
| `header.php` / `footer.php` | Каркас, навигация (menu-1), выбор города, авторизация | idpgData (ajaxUrl, nonce), город-виджет, иконки соцсетей |
| `front-page.php` | Главная | Баннеры (хук `idpg_banner_position`), текущее событие, события списком, сайдбары (`idpg_widget_areas_render`) |
| `single.php` | Просмотр события (event) | Мета: даты, сеансы, цены, билеты, площадка, медиа, возрастной рейтинг, кнопка покупки |
| `archive.php` | Архивы event/venue/таксономий | Сетка карточек, пагинация, фильтр по городу (pre_get_posts) |
| `page.php` | Обычные страницы | title, content |
| `page-favorites.php` | Избранное | список избранных событий (`class-favorites.php`) |
| `page-login.php` | Вход | `/auth/login` (auth.js), window.idpgAuth |
| `page-reset-password.php` | Восстановление пароля | `/auth/forgot-password`, `/auth/reset-password` |
| `page-terms.php` / `page-privacy.php` | Оферта / Политика конфиденциальности | Статичный текст (автосоздаются) |
| `search.php` / `404.php` | Поиск / ошибка | — |
| `template-parts/` | Части контента | content, content-page, content-none, content-event, content-event-today, content-venue, home-section, organizer/ |

Виджеты: **IDPG_City_Selector_Widget** (выбор города, Tom Select), **IDPG_Event_Calendar_Widget** (календарь, AJAX-подгрузка месяцев), **IDPG_Expected_Events_Widget** (плагин) — все три наследуют `WP_Widget`. Виджеты темы (`inc/widgets.php`): **IDPG_Ad_Ru_Widget**, **IDPG_Recent_Events_Widget**, **IDPG_Subscribe_Widget**. Области виджетов — своя система позиций с условиями показа (`idpg_widget_areas_*`). Классов `IDPG_Ad_Widget` и `IDPG_Sticky_Ad_Widget` в проекте нет.

> Примечание: шаблоны не используют класс `IDPG_Template_Helper` — вся отрисовка инлайн через PHP-методы и мету.

---

## 8. БЕЗОПАСНОСТЬ

### 8.1. Что сделано хорошо

- **Nonce:** AJAX (set_city, calendar), REST write (X-WP-Nonce / wp_rest), настройки админки (settings_fields)
- **Экранирование:** ~490 вызовов esc_* / ~190 esc_*__; wp_kses для пользовательского контента
- **Санитизация:** sanitize_* + absint/intval/floatval на всех входах; валидация REST (is_email, enum, min-длина пароля 8, проверка email_exists)
- **SQL:** все 3 прямых запроса через `$wpdb->prepare` с `%d`/`%s` — инъекций нет
- **Права:** роли + capabilities, restrict_admin_access, защита от IDOR (идентификация только по сессии), upload_files для загрузки, `current_user_can('edit_post')` для мета
- **Пароли:** хэширование WP, требование текущего пароля при смене, уничтожение сессий, `hash_equals` для пароля заглушки
- **Cookie:** HttpOnly + Secure (is_ssl) для cookie доступа

### 8.2. Уязвимости / риски

| Приоритет | Риск | Где | Рекомендация |
|---|---|---|---|
| 🔴 Высокий | Нет HTTPS на проде (вероятно), нет FORCE_SSL_ADMIN | wp-config, хостинг | Включить SSL на хостинге, задать FORCE_SSL_ADMIN, проверить cookie Secure |
| 🟡 Средний | CSRF на POST /auth/logout (без nonce) | class-rest-auth.php:150 | Добавить nonce-проверку |
| 🟡 Средний | Нет капчи/honeypot при регистрации (только rate-limit по IP) | class-rest-auth.php | Добавить honeypot/капчу |
| 🟡 Средний | 152-ФЗ неполное: нет отдельного согласия на обработку ПДн, нет cookie-баннера, нет механизма экспорта/удаления данных | формы, политика | Добавить чекбокс согласия, баннер cookies, инструменты ст. 18 |
| 🟢 Низкий | Диагностические error_log (rate limiter, синк билетов) | class-auth-rate-limiter.php:125,326; class-ticket-sync.php:349 | Убрать/обернуть в WP_DEBUG |
| 🟢 Низкий | ip-api.com без HTTPS (IP в открытом виде) | class-city-filter.php:158 | Предпочесть ipapi.co (https) |
| 🟢 Низкий | Пароль заглушки в форме без nonce | class-maintenance.php:960 | Добавить nonce (низкий риск — нужен сам пароль) |

### 8.3. Соответствие 152-ФЗ

- ✅ Политика конфиденциальности и оферта (автосозданы)
- ✅ Обязательное согласие с правилами при регистрации
- ⚠️ Нет отдельного согласия на обработку ПДн; нет cookie-баннера; нет механизма удаления/экспорта данных

---

## 9. TODO И УЛУЧШЕНИЯ

### 9.1. Блокирующие задачи (прод)

1. **Перенос на хостинг** через WP Cloud Sync: применить `wp-content/fix-idupogorodu-host.sql` (починка БД: заменить старый домен, деактивация лишних плагинов), залить новую версию wp-cloud-sync (LEGACY_TOKEN, repair_legacy_tokens, кнопка «Починить БД», detect_old_urls), задать `target_url`. **Заблокировано: нет SSH-пароля** (`host1679118@serv68.hostland.ru -p 1024`).
2. Включить HTTPS + FORCE_SSL_ADMIN + редирект http→https.
3. Убрать диагностические error_log перед продом.

### 9.2. Функциональные улучшения

- Подключить `IDPG_Template_Helper` в шаблоны (или удалить как мёртвый код) — единая точка рендера вместо инлайна
- Задействовать неиспользуемые REST-эндпоинты: `/auth/me`/`/auth/logout` (кнопка выхода в шапке)
- Реальный API билетов вместо заглушки `IDPG_TICKET_API_URL` (сейчас крон-синк не работает)

### 9.3. Технический долг / рефакторинг

- Устранить дубликаты: `get_venue`, `get_city_tree`, `get_client_ip` — вынести в общие сервисы
- Убрать дублирование дефолтного города (ID 15) из двух мест
- Синхронизировать версии (тема 1.0.2 vs плагин 1.0.0) и заголовок style.css (1.0.0)
- Добавить в плагин `Tested up to` и `Requires Plugins` в header
- Рассмотреть вынос Carbon Fields из vendor темы в must-use или плагин (сейчас плагин зависит от темы)
- Документировать REST API (OpenAPI/Postman) и настроить линтеры (PHPCS) в CI

### 9.4. Данные/контент

- Создать площадки (CPT venue пуст) и привязать события к площадкам (`_idpg_venue` пуст)
- Наполнить события для городов Удмуртии (Воткинск, Глазов, Можга, Сарапул) и районов Ижевска
- Заполнить поля организаторов (данные 1 пользователя)

---

*Документация сгенерирована по результатам аудитов: структура проекта, CPT/таксономии, Carbon Fields, функции, REST API, таксономия city, безопасность. Скрипты-реестры аудита (`wp-content/audit-functions.php`, `wp-content/audit-fields.php`) в рабочем дереве отсутствуют; ссылки на них, на несуществующие классы и удалённые REST-маршруты приведены в соответствие с кодом (`DEV-28`).*