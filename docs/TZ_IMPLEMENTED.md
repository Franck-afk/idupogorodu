# ТЕХНИЧЕСКОЕ ЗАДАНИЕ
## Портал-афиша «Иду по городу» — актуальная редакция (по реализованному состоянию)

**Версия документа:** 2.0
**Дата:** 15.09.2026
**Основание:** инвентаризация фактического кода (`docs/FEATURES.md`)
**Версии компонентов:** `idpg-core` 1.1.2 · `idpg-auth` 1.0.0 · тема `idpg_core` 1.0.73 · `idpg_core-child` 1.0.0
**Стек:** WordPress 6.4+, PHP 8.2, MySQL 5.7+, Carbon Fields 3.6 (Composer)

Отличие от `PLUGIN_TZ.md` v1.0: этот документ описывает **то, что реально реализовано и работает**,
а не планируемый объём. Всё нереализованное и заглушки вынесены в раздел 9.

---

## 1. НАЗНАЧЕНИЕ И ГРАНИЦЫ СИСТЕМЫ

### 1.1 Назначение

Многогородской портал-афиша: посетители смотрят события и площадки своего города,
организаторы регистрируются и подают события на модерацию, администраторы управляют
контентом из WP-админки. Вся бизнес-логика — в плагине `idpg-core`, тема отвечает
только за представление.

### 1.2 Состав системы

| Компонент | Роль | Объём |
|---|---|---|
| `plugins/idpg-core` | ядро: контент-модель, мета, города, REST, авторизация, роли, сервисы | 33 класса, 23 REST-маршрута |
| `plugins/idpg-auth` | соц-авторизация (VK / Яндекс / MAX), журналы, монитор безопасности, КоАП РФ | 11 классов (10 подключаются), 5 REST-маршрутов |
| `themes/idpg_core` | фронтенд: шаблоны, виджеты, области виджетов, скрипты | 25 шаблонов + 7 частей, 6 `inc/`-модулей |
| `themes/idpg_core-child` | дочерняя тема: стили родителя + политика регистрации | 1 файл логики |
| Внешние плагины | Yoast SEO, WP Mail SMTP, All-In-One WP Security, WpVivid Backup, Clearfy Pro | — |

### 1.3 Принципы реализации

- Префиксы: функции/хуки — `idpg_`, классы — `IDPG_`, мета-ключи — `_idpg_`, константы — `IDPG_`.
- Стандарты WordPress: табы, Yoda-условия, `array()`, escape на выводе, `$wpdb->prepare()`,
  nonce + `current_user_can()` для всех действий, php-doc-блоки на русском.
- Изменения — только аддитивно, через хуки (см. `AGENTS.md`).

---

## 2. РОЛИ И ПРАВА ДОСТУПА

| Роль | Права | Доступ |
|---|---|---|
| `administrator` | полный доступ, все caps на event/venue | админка + фронтенд |
| `editor` | полный доступ к контенту event/venue | админка + фронтенд |
| `event_organizer` | `read`, `upload_files`, `edit/publish/delete_events`, `edit/delete_published_events`, `delete_private_events`, полный набор caps на venue; `edit_others_*` = false | админка разрешена (через `restrict_admin_access`), **публикация событий только после модерации** |
| `subscriber` | только чтение + `upload_files` | фронтенд; после входа — на главную `/` |

- Версия capabilities — `IDPG_Roles::ROLE_VERSION = 6`, миграция при повышении версии
  (`idpg_roles_version`), флаги `idpg_roles_created`.
- Изоляция контента организатора: медиатека (список и REST media-modal) — только свои вложения;
  список событий в админке скоупится по автору.
- При удалении организатора админом выполняется зачистка: постеры, вложения-потомки, аватар,
  сиротские `_edit_last`/`_edit_lock`. При выборе «переназначить записи» контент сохраняется.

---

## 3. МОДЕЛЬ ДАННЫХ

### 3.1 Типы записей и таксономии

| Объект | Параметры |
|---|---|
| CPT `event` | архив `/afisha/`, поддержка title/editor/thumbnail |
| CPT `venue` | архив `/venues/` |
| Таксономия `city` | иерархическая: регион → город → район; привязана к event + venue; слаг `gorod/<slug>` |
| Таксономия `event_category` | иерархическая, слаг `event-category` |
| Таксономия `age_rating` | неиерархическая, слаг `age-rating` |

### 3.2 Мета событий и площадок (`_idpg_*`, Carbon Fields + `register_post_meta`)

**Событие — даты и сеансы:** `_idpg_start_date`, `_idpg_end_date`, `_idpg_all_day`, `_idpg_sessions_json`.

**Место:** `_idpg_venue` (ID площадки), `_idpg_venue_type`.

**Билеты и цены:** `_idpg_price_from`, `_idpg_price_to`, `_idpg_is_free`, `_idpg_vip_price`,
`_idpg_currency` (RUB/USD/EUR), `_idpg_ticket_status` (in_sale/sold_out/cancelled/postponed),
`_idpg_ticket_type` (external_link/widget_yandex), `_idpg_ticket_url`, `_idpg_widget_code`,
`_idpg_yandex_form_url`, `_idpg_external_id`.

**Медиа и дополнительно:** `_idpg_video_trailer`, `_idpg_stream_url`, `_idpg_stream_embed`,
`_idpg_is_online`, `_idpg_source`, `_idpg_promoted`.

**Счётчики:** `_idpg_views`, `_idpg_clicks`.

**Баннеры:** `_idpg_hide_from_banner`, `_idpg_hide_full_banner`, `_idpg_banner_request`
(заявка организатора), `_idpg_banner_approved` (подтверждение оплаты админом).

**Площадка:** `_idpg_venue_address`, `_idpg_venue_phone`, `_idpg_venue_email`,
`_idpg_venue_website`, `_idpg_venue_lat`, `_idpg_venue_lng`, `_idpg_venue_capacity`.

**Carbon Fields-контейнеры:** детали события; билеты и цены; данные площадки;
блок заявки на баннер (организатор) и подтверждения (только админ). Ленивые опции площадок,
санитизация URL и чисел через фильтр `sanitize`.

### 3.3 Мета пользователя

| Ключ | Назначение |
|---|---|
| `idpg_org_name`, `idpg_contact_name`, `idpg_phone` | анкета организатора |
| `_idpg_org_pending` | флаг «анкета не доукомплектована» → редирект на `/organizer/complete/` |
| `idpg_avatar_attachment_id` | кастомный аватар (подмена Gravatar на фронтенде) |
| `_idpg_favorites` | избранные события |
| ключ-хэш подтверждения регистрации организатора + срок действия | `class-organizer-verify.php` |
| мета привязки соц-провайдеров | `class-wp-auth-ru-provider.php` (`wp_auth_ru_{provider}_oauth_id`) |

### 3.4 Таблицы БД

| Таблица | Назначение |
|---|---|
| `{prefix}idpg_subscribers` | подписчики дайджеста: `email` (UNIQUE), `ip`, `created_at` |
| `{prefix}idpg_auth_logins` | журнал входов: аккаунт, IP, браузер/ОС/устройство, провайдер, время |
| `{prefix}idpg_auth_security` | события безопасности: тип, уровень угрозы, сообщение, IP, user-agent, URL, метод, время |

Создаются через `dbDelta` при активации (подписчики) либо лениво при первой записи/просмотре.

### 3.5 Опции (`wp_options`)

- Плагин: `idpg_roles_created`, `idpg_roles_version`.
- Режим разработки: `idpg_maintenance_enabled`, `_type`, `_message`, `_title`, `_bg`, `_bg_color`,
  `_logo`, `_password`, `_ips`, `_roles`, `_countdown`, `_expires`, `_retry_after`,
  `_contact_email`, `_contact_phone`, `_social`, `_social_items`, `_login_link`.
- Тема: `idpg_home_sections` (видимость секций главной), `idpg_widget_areas` (менеджер областей),
  `sidebars_widgets`, `widget_idpg_ad`.
- `idpg-auth`: `idpg_auth_settings` (клиенты провайдеров, флаги `*_enabled`),
  `wp_auth_ru_log` (журнал событий, 200 записей), `idpg_auth_logins_db_version`,
  `idpg_auth_security_db_version`, `idpg_auth_lock_map`, `idpg_auth_security_last_email`.

### 3.6 Константы

| Константа | Значение / назначение |
|---|---|
| `IDPG_DEFAULT_CITY_ID` | ID города по умолчанию (Ижевск, 15) |
| `IDPG_TICKET_API_URL` | базовый URL API агрегатора билетов (**сейчас заглушка**) |
| `IDPG_CORE_VERSION` / `IDPG_VERSION` | версии плагина и темы (кэш-бастинг ассетов) |
| `IDPG_SEARCH_MAX_PER_SOURCE` | лимит 100 ID на подзапрос расширенного поиска |
| `IDPG_CORE_UNINSTALL_WIPE_DATA` | полная зачистка данных при удалении плагина |

---

## 4. ФУНКЦИОНАЛЬНЫЕ ТРЕБОВАНИЯ

### ФТ-1. Контент-модель афиши
Система должна предоставлять CPT событий и площадок и три таксономии (город, категория, возрастной рейтинг).
**Реализация:** `class-cpt.php`; регистрация при активации плагина + `flush_rewrite_rules()`.

### ФТ-2. Метаданные событий и площадок
Все характеристики события (даты и сеансы, площадка, билеты, цены, медиа, продвижение)
и площадки (адрес, контакты, координаты, вместимость) должны храниться в мета-полях с единым
префиксом и быть доступны в REST API. **Реализация:** `class-meta.php` (Carbon Fields + `register_post_meta`).

### ФТ-3. Многогородская механика
- Город посетителя хранится в cookie `idpg_selected_city` на 30 дней.
- Автоопределение по IP: `ip-api.com` → `ipapi.co`, кэш на сутки, переключатель сервиса
  (`none` отключает автодетект), запрос по HTTPS с fallback на HTTP.
- Смена города: REST `POST /idpg/v1/city/select` (обязателен nonce `wp_rest`),
  admin-ajax `idpg_set_city` — fallback.
- Архивы событий и площадок автоматически фильтруются по текущему городу.
- Виджет «Выбор города» (иерархический список, tom-select) + модалка в шапке для мобильных.
**Реализация:** `class-city-filter.php`, `class-city-url.php`, `class-geo-service.php`,
`class-city-selector-widget.php`, `class-rest-api.php::select_city`.

### ФТ-4. URL-структура и маршрутизация
Правила rewrite строятся из реальных слагов городов (`{c}` — группа города), кэш слагов
сбрасывается при сохранении термина:

| URL | Назначение |
|---|---|
| `/afisha/{c}/`, `/afisha/{c}/page/N/` | афиша города с пагинацией |
| `/afisha/{c}/events/{slug}/` | карточка события (канонический URL) |
| `/afisha/{c}/search/?s=…` | поиск по событиям города |
| `/afisha/{c}/today/`, `/tomorrow/`, `/weekend/` (+ `/page/N/`) | пресеты дат |
| `/afisha/{c}/free/` (+ `/page/N/`) | только бесплатные |
| `/afisha/{c}/date/YYYY-MM-DD/` | конкретная дата |
| `/afisha/{c}/category/{slug}/` (+ `/page/N/`) | фильтр по категории |
| `/venues/{c}/` (+ `/page/N/`) | площадки города |
| `/gorod/{slug}/` | 301 → `/afisha/{slug}/` |
| `/afisha/`, `/venues/`, `?city=…` | 301 на версию с текущим городом |
| `/lk/favorites/` | избранное (rewrite плагина, страницы в БД нет) |
| `/organizer/complete/` | доуполнение анкеты организатора |
| `/organizer/` | личный кабинет организатора, раздел `dashboard` (по умолчанию) |
| `/organizer/{section}/` | разделы кабинета: `dashboard`, `events`, `profile` |

**Реализация:** `class-city-url.php`, `class-favorites.php`, `class-organizer-page.php`,
`class-organizer-url.php`.

### ФТ-5. Регистрация, вход, восстановление пароля
- REST `idpg/v1`: `POST /auth/login`, `POST /auth/register` (типы `user` и `organizer`),
  `POST /auth/logout`, `POST /auth/forgot-password`, `POST /auth/reset-password`, `GET /auth/me`.
- Формы работают без JavaScript (серверная обработка `$_POST` + nonce) на `/login/` и `/reset-password/`.
- Редирект после входа: организатор (`event_organizer` без `administrator`/`editor`) попадает
  в свой кабинет `/organizer/`, остальные — как раньше (`admin_url()` для админа и редактора,
  главная для прочих). Решение — `IDPG_Organizer_URL::after_login_url()`; применяется в
  шаблоне `/login/`, в ветке «уже авторизован» (`IDPG_Auth::redirect_if_logged_in()`),
  в фильтре `login_redirect` (приоритет 20) и для соцвхода. Ссылка «Панель» в шапке ведёт
  организатора в кабинет.
- Кастомные HTML-письма: приветствие после регистрации, восстановление пароля, настройка
  отправителя, логотип письма (фильтр `idpg_email_logo_url`).
- Rate limiter: ограничение попыток входа/регистрации/сброса по IP и email.
- Стандартная регистрация WordPress отключена (в т.ч. принудительно в дочерней теме через
  `option_users_can_register`).
- Серверная проверка согласия с офертой при регистрации.
- Отдельное согласие на обработку персональных данных (152-ФЗ): обязательный чекбокс
  `idpg_pdn_consent` в формах (REST и PHP-fallback без JS) и серверная проверка
  (`400 pdn_consent_required`). Согласие с офертой его не заменяет. Факт согласия
  записывается в user meta `idpg_pdn_consent` (`given` / `version` / `source` / `ip_hash`,
  IP — только хешем): `IDPG_Privacy::record_consent()` / `has_consent()` / `get_consent()`.
- Защита регистрации от ботов: скрытое honeypot-поле `idpg_company` (REST и PHP-fallback без JS);
  при заполнении пользователь не создаётся, а ответ нейтральный (`200` с текстом успеха).
  Опциональная капча — фильтры `idpg_auth_captcha_enabled` / `idpg_auth_captcha_verify`.
- При блокировке IP плагином AIOS REST возвращает корректный JSON 429 вместо HTML-страницы.
- Cookie-баннер с сохранением выбора в cookie `idpg_cookie_consent` (год, значения
  `all` | `necessary`) — работает без JavaScript (POST с nonce) и с ним (`privacy.js`).
- Права субъекта персональных данных: самостоятельная выгрузка своих данных (JSON) и
  запрос на удаление со страницы политики; обработка админом на странице
  «Иду по городу → Конфиденциальность» (выгрузка JSON/CSV, удаление с подтверждением,
  журнал действий).
**Реализация:** `class-rest-auth.php`, `class-auth.php`, `class-auth-emails.php`,
`class-auth-rate-limiter.php`, `class-privacy.php`, `page-login.php`, `page-reset-password.php`,
`page-privacy.php`, `page-terms.php`, `auth.js`, `privacy.js`.

### ФТ-6. Социальная авторизация
Вход через **ВКонтакте** (OAuth 2.0, `id.vk.com`), **Яндекс ID** (OAuth 2.0, права
`login:info`, `login:email`) и **MAX** (Mini App `initData` + HMAC-SHA256).
Кнопки выводятся на страницах входа/регистрации WP, в формах WooCommerce и баннером в checkout;
есть стили для фронтенда, вывод ошибок, вход/создание пользователя по ID провайдера, по email
либо регистрация с генерацией уникального логина.
**Реализация:** `plugins/idpg-auth`: `class-wp-auth-ru-provider.php`, `-oauth-provider.php`,
`-vk.php`, `-yandex.php`, `-max.php`, `-admin.php`; REST `wp-auth-ru/v1`.

### ФТ-7. Аккаунт организатора
- Регистрация организатора собирает название организации, ФИО представителя, email, телефон, пароль.
- Роль `event_organizer` выдаётся только после подтверждения email по ссылке
  (ключ хранится хэшем + срок действия).
- Организатор с флагом `_idpg_org_pending` направляется на `/organizer/complete/` для доуполнения анкеты.
- После доуполнения организатор работает в личном кабинете `/organizer/` (каркас с разделами
  `dashboard` | `events` | `profile`); доступ — только роли `event_organizer` и выше,
  гость уходит на `/login/`, пользователь без прав получает `403`.
- В кабинете не выводится блок виджетов над подвалом (в том числе рекламный баннер):
  фильтр `sidebars_widgets` обнуляет области с позицией `before-footer` только внутри ЛК.
- Модерация: организатор не имеет `publish_events`; событие программно понижается в `pending`,
  админам уходит письмо о новом событии, организатору — об одобрении.
**Реализация:** `class-organizer-verify.php`, `class-organizer-page.php`, `class-event-moderation.php`,
`class-rest-auth.php`, `page-organizer-complete.php`.

### ФТ-8. Избранное
Событие добавляется/удаляется авторизованным пользователем, хранится в user meta, доступно
через REST и выводится на странице `/lk/favorites/`; ведётся счётчик избранного.
**Реализация:** `class-favorites.php` (`GET /favorites`, `POST /favorites/add`,
`POST /favorites/remove`), `page-favorites.php`, `favorites.js`, `favorites.css`.

### ФТ-9. Поиск
Единая логика поиска (s-поиск + события площадок + совпадения по названиям таксономий)
используется темой (`pre_get_posts`) и REST: live-подсказки в шапке при вводе и популярные
запросы при фокусе. Ограничение выборки — 100 ID на подзапрос.
**Реализация:** `class-search.php`, `GET /search/live`, `GET /search/popular`, `live-search.js`.

### ФТ-10. Календарь событий
Мини-календарь с днями, где есть события: навигация по месяцам (AJAX), подсказки и список
событий на день, фильтры по категории и городу, цветовые схемы, экспорт месяца в iCal.
Кэш: структура месяца — 24 ч, события — 1 ч; данные месяца — 2 запроса к БД.
**Реализация:** `class-event-calendar-widget.php`, `GET /event-dates`, `event-calendar.js/css`.

### ФТ-11. Билеты, цены, продажи
Поддержаны: цена «от»/«до», бесплатные события, VIP-цена, валюта, статус билетов
(в продаже / распродано / отменено / перенесено), тип продажи — внешняя ссылка или виджет
Яндекс.Билетов, форма Яндекс.Билетов, внешний ID для синхронизации.
**Реализация:** `class-meta.php`, `single-event.php`, `idpg_core_format_price()`,
`idpg_core_ticket_button()`, `class-views.php` (учёт кликов).

### ФТ-12. Синхронизация статусов билетов и уведомления
Периодическая (WP-Cron, интервал 3 часа, хук `idpg_cron_sync_tickets`) сверка статусов билетов
с внешним API агрегатора с обновлением `_idpg_ticket_status` и `_idpg_external_id`;
при смене статуса автору события уходит email (хук `idpg_ticket_status_changed`).
**Реализация:** `class-ticket-sync.php`, `class-notifications.php`.
**Ограничение:** реальный адрес API не задан — см. раздел 9.

### ФТ-13. Счётчики просмотров и кликов
Инкремент `_idpg_views` при уникальном просмотре (защита от ботов и повторных заходов),
`_idpg_clicks` — при переходе на продажу билетов. **Реализация:** `class-views.php`.

### ФТ-14. Экспорт в iCalendar
Генерация валидного `.ics` (VEVENT) для месяца и для отдельного события.
**Реализация:** `class-ical-export.php` (`?idpg_action=ical`).

### ФТ-15. Баннеры и реклама
- Баннер текущего события на главной в трёх позициях: `hero`, `middle`, `bottom`
  (`do_action( 'idpg_banner_position', … )`); данные берутся из ближайшего предстоящего события,
  настройки в админке не требуются; флаги скрытия — `_idpg_hide_from_banner`, `_idpg_hide_full_banner`.
- Заявка организатора на показ в баннере (`_idpg_banner_request`) и подтверждение оплаты
  администратором (`_idpg_banner_approved`).
- Рекламный виджет с поддержкой нескольких платформ, шаблонами кода, санитайзом допустимого HTML
  и админ-JS для вставки кода.
**Реализация:** `class-current-event-banner.php`, `IDPG_Ad_Ru_Widget` (`inc/widgets.php`),
`widget-ad.js`, `widget-ad-front.js`, `current-event-banner.js/css`.

### ФТ-16. Подписка на дайджест
Публичная подписка по email с honeypot-полем, чекбоксом согласия и rate limiter; данные пишутся
в таблицу `{prefix}idpg_subscribers` (email уникален). **Реализация:** REST `POST /subscribe`,
`IDPG_Subscribe_Widget`, `subscribe.js/css`.

### ФТ-17. Главная страница
Секции: «Сегодня», «В ближайшие выходные», «Бесплатные события», «Популярные события»,
лента «Все события» + баннеры и области виджетов. Видимость каждой секции управляется
администратором. **Реализация:** `front-page.php`, `inc/home-sections.php`
(страница «Настройки главной» в разделе «Внешний вид», опция `idpg_home_sections`),
`idpg_get_home_section_events()`, `template-parts/home-section.php`.

### ФТ-18. Области виджетов (динамические)
Администратор создаёт произвольные области виджетов, задаёт место вывода и условие показа.
Позиции: «Сразу под шапкой», «Над контентом», «Сайдбар страницы события», «Под контентом»,
«Перед футером». Условия: везде / только главная / все страницы / все записи / архивы /
конкретные страницы. Виджеты добавляются на стандартном экране «Виджеты».
**Реализация:** `inc/widget-areas.php`, опция `idpg_widget_areas`, `idpg_widget_areas_render()`.

### ФТ-19. Виджеты
| Виджет | Модуль |
|---|---|
| Выбор города | `class-city-selector-widget.php` |
| Календарь событий | `class-event-calendar-widget.php` |
| Самые ожидаемые события (по `_idpg_views`, кэш 1 ч, с учётом города) | `class-expected-events-widget.php` |
| Реклама | `inc/widgets.php` |
| Последние события | `inc/widgets.php` |
| Подписка на дайджест | `inc/widgets.php` |

### ФТ-20. Страницы и правовые документы
Автоматически создаются при активации: `/login/`, `/reset-password/`, `/terms/`, `/privacy/`,
`/offer/` (с дефолтными текстами), а также дефолтное меню. Отдельные шаблоны:
`/about/`, `/contacts/`, `/lk/favorites/`, `/organizer/complete/`, `/organizer/`.
**Реализация:** `functions.php` (`idpg_core_create_*_page()`, `idpg_core_create_default_menu()`),
`page-login.php`, `page-reset-password.php`, `page-terms.php`, `page-privacy.php`, `page-legal.php`,
`page-about.php`, `page-contacts.php`, `page-favorites.php`, `page-organizer-complete.php`.

### ФТ-21. SEO-интеграция
При активном Yoast SEO: сущности Event и Place в графе JSON-LD (GeoCoordinates площадки,
addressLocality города, Offer с ценами), гарантированный `og:image`/`twitter:image` из постера,
поддержка крошек Yoast с обёрткой в стили темы. Без Yoast разметку выводит тема.
**Реализация:** `class-seo-integration.php`, `idpg_core_event_jsonld()`, `inc/breadcrumbs.php`.

### ФТ-22. Режим «Сайт в разработке»
Заглушка HTTP 503 с настройками: тип режима, заголовок и текст, логотип, фон и цвет, пароль
доступа, белый список IP, разрешённые роли, cookie доступа, обратный отсчёт до авто-выключения
(`retry_after`), контакты и соцсети, ссылка на вход. **Реализация:** `class-maintenance.php`,
страница в «Настройки», `admin-maintenance.js/css`.

### ФТ-23. Админка: настройки и статистика
- Меню «IDPG» (`admin.php?page=idpg-settings`) с вкладками: Главная (блоки), Области виджетов,
  Экспорт / О плагине.
- Статистика на dashboard (`admin.php?page=idpg-stats`): администратор видит события по статусам,
  площадки, подписчиков, пользователей и организаторов, просмотры/клики, избранное, города,
  ближайшие события, топ организаторов; организатор — только свои события, площадки и метрики.
- Предупреждения: конфликты с All-In-One WP Security (блокировка REST, переименование страницы
  входа) и заглушка API билетов.
**Реализация:** `class-settings-page.php`, `class-dashboard-stats.php`, `class-maintenance.php`,
`idpg-core.php` (`admin_notices`).

### ФТ-24. Медиа
Единый слой работы с вложениями: постер (обложка) события и площадки, галерея события,
аватар организатора, поиск вложения по URL, безопасное удаление (одиночное и массовое
с очисткой постера), определение типа файла (audio/video).
**Реализация:** `class-media.php`, `class-meta.php` (Carbon Fields `media_gallery`).

### ФТ-25. Безопасность
- Nonce (`wp_rest`, формы, `wp_nonce_field`) и `current_user_can()` для всех действий.
- Санитизация всех внешних данных, escape на выводе (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- SQL только через `$wpdb->prepare()`.
- У каждого REST-маршрута — `permission_callback`; у параметров — `sanitize_callback`, `type`, `enum`.
- Rate limiting форм авторизации; защита от перечисления пользователей (монитор безопасности).
- Кастомный аватар подменяется только на фронтенде.
- Уникальные ключи и соли аутентификации, защита от `Undefined array key` в wp-config.
- `uninstall.php` — контролируемая очистка данных при удалении плагина.

### ФТ-26. Журналы и монитор безопасности (idpg-auth)
- Журнал событий авторизации: 200 последних записей (уровни INFO / SUCCESS / WARNING / ERROR),
  просмотр и очистка в админке.
- Журнал входов в отдельной таблице БД: аккаунт, IP, браузер/ОС/устройство, провайдер, время.
- Монитор безопасности: брутфорс с авто-блокировкой IP, перечисление пользователей
  (REST `/wp/v2/users`, `?author=N`, `/author/`), доступ к `.git`, `wp-config.php.*`, `.env`,
  `debug.log`, бэкапам и дампам БД, смена роли на администратора, создание пользователей,
  активация/деактивация/удаление плагинов и тем, обновления, правка файлов через редактор;
  email-оповещения о критичных событиях, разблокировка IP из админки.

### ФТ-27. Соответствие требованиям КоАП РФ
Определение активных плагинов иностранной авторизации (Google, Facebook и др.)
с предупреждением администратору; «режим соответствия» блокирует вход и регистрацию для
пользователей, авторизованных через иностранные сервисы; отметка пользователей, созданных
через разрешённые российские провайдеры. **Реализация:** `class-wp-auth-ru-compliance.php`.

### ФТ-28. Локализация
Text domain плагина — `idpg-core`, темы — `idpg_core`. Шаблон переводов
`languages/idpg-core.pot`, генератор `bin/make-pot.js` (без внешних зависимостей).
Все строки интерфейса — через `__()`/`esc_html__()` с указанием домена.

### ФТ-29. Тестирование и качество кода
PHPUnit: 9 файлов тестов (auth-emails, cpt, geo-service, ical-export, organizer-events,
rest-api, roles, template-helper, theme), конфигурации `phpunit.xml.dist`,
`bin/install-wp-tests.sh|.cmd`, `bin/run-tests.cmd`. Стандарты — `.phpcs.xml.dist`,
CI — `.circleci/config.yml`.

### ФТ-30. Миграции данных
Идемпотентный и возобновляемый перенос мета-полей со старого префикса `_idpg_event_*`
на `_idpg_*` с приведением форматов; маркер обработанных записей, lock-транзиент;
WP-CLI команда `wp idpg migrate-meta`; запуск при активации.
**Реализация:** `class-migrations.php`.

### ФТ-31. Установка и удаление
- Установка: папка плагина → `composer install --no-dev` → активация (CPT, таксономии, роли,
  cron, миграции, flush rewrite, таблица подписчиков, whitelist AIOS).
- Деактивация: удаление ролей, снятие cron, flush rewrite.
- Удаление: очистка опций, транзиентов, user meta, cron; данные событий сохраняются,
  полная зачистка — константой `IDPG_CORE_UNINSTALL_WIPE_DATA`.

---

## 5. REST API

### 5.1 Namespace `idpg/v1` (23 маршрута)

| Метод | Путь | Доступ | Назначение |
|---|---|---|---|
| GET | `/events` | публичный | список событий: `city`, `category`, `date_from`, `date_to`, `search`, `page`, `per_page` |
| GET | `/venues` | публичный | список площадок: `city`, `search`, `page`, `per_page` |
| GET | `/venues/{id}` | публичный | одна площадка |
| POST | `/venues/create` | nonce + `can_manage_events` | создание площадки |
| PUT | `/venues/{id}` | nonce + `can_manage_events` | редактирование площадки |
| GET | `/cities` | публичный | города (taxonomy `city`) |
| GET | `/categories` | публичный | категории (`event_category`) |
| GET | `/age-ratings` | публичный | возрастные рейтинги |
| GET | `/event-dates` | публичный | даты с событиями за месяц (`year`, `month`) |
| GET | `/search/live` | публичный | live-подсказки (`q`, `city`) |
| GET | `/search/popular` | публичный | популярное при фокусе (`city`) |
| POST | `/subscribe` | публичный (honeypot + rate limit) | подписка на дайджест |
| POST | `/city/select` | nonce `wp_rest` | смена города (`city_id`) |
| GET | `/favorites` | авторизованный | список избранного |
| POST | `/favorites/add` | авторизованный | добавить в избранное |
| POST | `/favorites/remove` | авторизованный | убрать из избранного |
| POST | `/auth/login` | публичный (rate limit) | вход (`email`, `password`, `remember`) |
| POST | `/auth/register` | публичный (honeypot + rate limit) | регистрация (`type` = user/organizer); обязательны `terms` и `idpg_pdn_consent` |
| POST | `/auth/logout` | авторизованный + nonce `wp_rest` | выход |
| POST | `/auth/forgot-password` | публичный | запрос восстановления пароля |
| POST | `/auth/reset-password` | публичный | установка нового пароля (`user_id`, `reset_key`, `new_password`) |
| GET | `/auth/me` | авторизованный | данные текущего пользователя |

### 5.2 Namespace `wp-auth-ru/v1` (5 маршрутов)

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/vk/start` | старт OAuth ВКонтакте |
| GET | `/vk/callback` | коллбэк ВКонтакте |
| GET | `/yandex/start` | старт OAuth Яндекс ID |
| GET | `/yandex/callback` | коллбэк Яндекс ID |
| POST | `/max/validate` | валидация `initData` MAX |

---

## 6. ТРЕБОВАНИЯ К ОКРУЖЕНИЮ

| Требование | Значение |
|---|---|
| WordPress | 6.4+ (проверено на 6.7) |
| PHP | 8.2+ (минимум по коду — 7.4) |
| MySQL | 5.7+ |
| Composer | для Carbon Fields 3.6 |
| Cron | WP-Cron: синхронизация билетов (3 ч); на проде — системный cron при `DISABLE_WP_CRON` |
| Внешние API | `ip-api.com`, `ipapi.co` (город по IP); OAuth: `id.vk.com`, `oauth.yandex.ru`, MAX; API агрегатора билетов (не задан) |
| Почта | WP Mail SMTP (Gmail SMTP) для всех писем |
| Сервер | Apache/Nginx с mod_rewrite (ЧПУ обязательны) |
| Домен | VK ID и Яндекс ID работают только на домене, привязанном к приложению; локальные домены (`.test`, `localhost`) отклоняются |

---

## 7. НЕФУНКЦИОНАЛЬНЫЕ ТРЕБОВАНИЯ

| Категория | Требование |
|---|---|
| Производительность | Кэш календаря (24 ч / 1 ч), кэш списков виджетов (1 ч), кэш города по IP (сутки), кэш слагов городов, лимит выборок поиска (100), данные месяца календаря — 2 запроса к БД |
| Кэш-бастинг | Версии ассетов через `IDPG_VERSION` / `IDPG_CORE_VERSION` / mtime файлов |
| Обратная совместимость | Изменения только аддитивные, через хуки; запрет на удаление и переписывание существующего кода (см. `AGENTS.md`) |
| Логирование | Запрет `var_dump`/`print_r`/`error_log` в боевом коде; журналы — через модули проекта |
| Доступность | Формы авторизации работают без JavaScript; ARIA-атрибуты и `role="status"` в интерфейсах |
| Расширяемость | Хуки `idpg_banner_position`, `idpg_ticket_status_changed`, фильтры `idpg_email_logo_url`, `idpg_home_sections`, `aios_whitelisted_rest_routes` |

---

## 8. ПРОДАКШЕН-ЗАПУСК

Обязательные шаги — в `docs/PRODUCTION.md`: перевод `WP_ENVIRONMENT_TYPE` в `production`,
`WP_DEBUG=false`, новые соли, `DISALLOW_FILE_EDIT`/`DISALLOW_FILE_MODS`, `FORCE_SSL_ADMIN`,
установка `siteurl`/`home` на реальный домен, перенос `uploads/` и массовая замена домена
в БД, настройка SMTP, доверенных Redirect URI для VK ID и Яндекс ID, включение системного cron,
проверка whitelist REST в All-In-One WP Security.

---

## 9. ГРАНИЦЫ РЕАЛИЗОВАННОГО ОБЪЁМА

Пункты ниже **не входят** в реализованное состояние и требуют отдельного решения.

| # | Пункт | Текущее состояние |
|---|---|---|
| 1 | REST `/idpg/v1/auth/organizer-complete` и анкета `/organizer/complete/` | **Снято с объёма.** DEV-72 решил, что анкеты нет (данные организации — в `/organizer/profile/`), и удалил REST-маршрут; остатки (класс `IDPG_Organizer_Page`, шаблон `page-organizer-complete.php`, скрипт `organizer-complete.js`, rewrite-правило) висели подключёнными, но нерабочими — флаг `_idpg_org_pending` никем не записывался. **DEV-141 (30.09.2026) удалил остатки**, URL `/organizer/complete/` отдаёт 404 |
| 2 | Синхронизация билетов | заглушка: `IDPG_TICKET_API_URL` = `example.com`, запросы блокируются защитой; реальный API не подключён |
| 3 | Система баннеров middle/bottom с UTM и периодом показа (`class-banners.php`) | файл отсутствует; заменено баннером текущего события и областями виджетов |
| 4 | Встроенный модуль соц-авторизации в ядре (`includes/social-auth/*`) | отсутствует; соц-вход вынесен в отдельный плагин `idpg-auth` |
| 5 | Личный кабинет `/lk/` (профиль, статистика, аватар, смена пароля) | удалён в 1.1.0; сохранено только `/lk/favorites/` |
| 6 | Поля события `idpg_pin_expires` и авто-снятие `_idpg_promoted` по cron | не реализовано |
| 7 | Поля площадки `idpg_venue_hours` (часы работы), `idpg_venue_social` (соцсети) | не реализовано |
| 8 | Профиль организатора: `legal_type`, `inn`, `verified`, `documents` | не реализовано (есть подтверждение email и модерация публикаций) |
| 9 | Панель организатора в админке («Мои события» с полями анкеты) | не реализовано |
| 10 | Координаты события (`_idpg_coordinates_lat/lng`, `_idpg_address`) | не реализовано; гео хранится только у площадки |
| 11 | Автообновление `idpg-auth` с GitHub (`class-wp-auth-ru-updater.php`) | файл есть, но не подключён в `idpg-auth.php` — обновление выключено |
| 12 | Логотип email-рассылки `email-logo.png` | не загружен; задаётся фильтром `idpg_email_logo_url` |
| 13 | Компиляция SCSS в CSS в сборочном шаге | SCSS — исходники; `style.css` содержит готовые стили |

---

## 10. КРИТЕРИИ ПРИЁМКИ

1. Активация `idpg-core` создаёт CPT, таксономии, роли, cron-задачу и таблицу подписчиков без ошибок.
2. `/afisha/{city}/`, `/afisha/{city}/events/{slug}/`, `/venues/{city}/` открываются; `/gorod/{slug}/` отдаёт 301.
3. Смена города (виджет, модалка шапки, REST `/city/select`) меняет cookie и фильтрацию архивов.
4. Регистрация и вход работают с JS и без JS; письма приходят через SMTP; брутфорс ограничивается.
5. Вход через VK, Яндекс и MAX создаёт/находит пользователя; ошибки провайдера выводятся понятным текстом.
6. Организатор после подтверждения email получает роль, создаёт событие — оно уходит в `pending`,
   админ получает письмо, после публикации организатор получает уведомление.
7. Избранное сохраняется, счётчик меняется, `/lk/favorites/` показывает список.
8. Поиск: live-подсказки и популярные запросы возвращают события текущего города.
9. Календарь: дни с событиями отмечены, навигация по месяцам работает, экспорт `.ics` валиден.
10. Просмотры и клики инкрементируются без накрутки ботами.
11. Главная: секции переключаются настройками, баннер текущего события показывается в hero/middle/bottom.
12. Области виджетов выводятся в выбранной позиции и по заданному условию показа.
13. Страницы `/login/`, `/reset-password/`, `/terms/`, `/privacy/`, `/offer/` создаются автоматически.
14. Режим «Сайт в разработке» отдаёт 503 для гостей и пускает админов и IP из белого списка.
15. Yoast формирует JSON-LD Event/Place и `og:image` из постера.
16. Дашборд показывает статистику администратору и ограниченную — организатору.
17. Монитор безопасности фиксирует брутфорс, перечисление пользователей и доступ к чувствительным файлам.
18. `wp idpg migrate-meta` выполняется идемпотентно; повторный запуск не портит данные.
19. PHPUnit-тесты проходят; PHPCS не выдаёт новых ошибок.
20. Деактивация снимает роли и cron, удаление плагина очищает служебные данные.

---

## 11. ПРИЛОЖЕНИЯ

### 11.1 Структура репозитория

```
wp-content/
├── plugins/
│   ├── idpg-core/            # ядро (33 класса, 23 REST-маршрута, tests/, bin/, languages/)
│   └── idpg-auth/            # соц-авторизация, журналы, монитор безопасности (11 классов)
├── themes/
│   ├── idpg_core/            # 25 шаблонов, 7 template-parts, inc/ (6 модулей), assets/
│   └── idpg_core-child/      # дочерняя тема
├── docs/
│   ├── PLUGIN_TZ.md          # исходное ТЗ v1.0
│   ├── FEATURES.md           # инвентаризация реализованного
│   ├── TZ_IMPLEMENTED.md     # этот документ
│   ├── PRODUCTION.md         # чек-лист продакшена
│   └── README_new.md         # архивная документация темы
├── AGENTS.md                 # правила работы с кодом
└── DOCUMENTATION.md          # разбор структуры, эндпоинтов, безопасности
```

### 11.2 Связанные документы

| Документ | Назначение |
|---|---|
| `docs/FEATURES.md` | что именно реализовано, с указанием файлов |
| `docs/PRODUCTION.md` | порядок вывода на прод |
| `docs/PLUGIN_TZ.md` | исходное ТЗ (v1.0, планируемый объём) |
| `plugins/idpg-core/CHANGELOG.md` | история версий ядра |
| `themes/idpg_core/IMPLEMENTATION.md` | журнал выполненных задач по теме |
| `AGENTS.md` | правила изменений кода (не удалять, не переписывать, добавлять аддитивно) |
