# Полный список реализованного в проекте «Иду по городу»

Инвентаризация по фактическому коду (не по документации). Дата: 15.09.2026, дополнено (аудит
ЛК организатора DEV-00A) 19.09.2026. Проверено: `plugins/idpg-core` (v1.1.2),
`plugins/idpg-auth` (v1.0.0), `themes/idpg_core` (v1.0.75), `themes/idpg_core-child` (v1.0.0),
`docs/`, `deploy.sh`, `DOCUMENTATION.md`.

> ⚠️ **Внимание (19.09.2026).** Разделы, описывающие личный кабинет организатора и 152-ФЗ
> (п. 1.4 «кабинет», п. 1.4а, п. 3.2 `organizer-menu.php`, п. 3.4 ассеты кабинета), фиксируют
> **намеренное/документированное состояние** (версии 1.1.7…1.1.8 / 1.0.79…1.0.81 по
> `TZ_BACKLOG.md`). В **текущем рабочем дереве** 1.1.2 / 1.0.75 эти файлы есть, но **не подключены**.
> Фактическое состояние — см. **§ 6 «ЛК организатора: фактическое состояние»** и
> `docs/README_DEV-00A.md`.

---

## 1. Плагин IDPG Core (ядро) — 33 класса

### 1.1 Контент-модель

| Что | Реализация |
|---|---|
| CPT `event` (афиша, слаг `/afisha/`) | `class-cpt.php` — title, editor, thumbnail |
| CPT `venue` (площадка, `/venues/`) | `class-cpt.php` |
| Таксономия `city` | иерархическая: регион → город → район; привязана к event + venue |
| Таксономия `event_category` | иерархическая, слаг `event-category` |
| Таксономия `age_rating` | неиерархическая, слаг `age-rating` |
| Регистрация при активации | CPT + таксономии + роли + cron + миграции + flush rewrite |
| При деактивации | удаление ролей, снятие cron, flush rewrite |

### 1.2 Мета-поля (Carbon Fields + `register_post_meta`, префикс `_idpg_`)

**Событие:** `_idpg_start_date`, `_idpg_end_date`, `_idpg_all_day`, `_idpg_sessions_json`,
`_idpg_venue`, `_idpg_venue_type`, `_idpg_price_from`, `_idpg_price_to`, `_idpg_is_free`,
`_idpg_vip_price`, `_idpg_currency`, `_idpg_ticket_status`, `_idpg_ticket_type`, `_idpg_ticket_url`,
`_idpg_widget_code`, `_idpg_yandex_form_url`, `_idpg_external_id`, `_idpg_video_trailer`,
`_idpg_stream_url`, `_idpg_stream_embed`, `_idpg_is_online`, `_idpg_source`, `_idpg_promoted`,
`_idpg_views`, `_idpg_clicks`, `_idpg_hide_from_banner`, `_idpg_hide_full_banner`,
`_idpg_banner_request`, `_idpg_banner_approved`.

**Площадка:** `_idpg_venue_address`, `_idpg_venue_phone`, `_idpg_venue_email`,
`_idpg_venue_website`, `_idpg_venue_lat`, `_idpg_venue_lng`, `_idpg_venue_capacity`.

**Carbon Fields-контейнеры:** детали события, билеты/цены, данные площадки, заявка на баннер
(для организатора) + подтверждение оплаты баннера (только админ). Ленивые опции площадок,
санитизация URL/чисел через фильтр `sanitize`.

### 1.3 Многогородская механика

- Cookie `idpg_selected_city` (30 дней), автоопределение города по IP (`ip-api.com` → `ipapi.co`,
  кэш на сутки, переключатель сервиса в виджете, работа по HTTPS с fallback на HTTP).
- Город в URL: `/afisha/{city}/`, `/afisha/{city}/events/{slug}/`, `/venues/{city}/`;
  `/gorod/{slug}/` → 301, `/afisha/` и `/venues/` → 301 на версию с городом, `?city=` → 301.
  Rewrite строится из реальных слагов городов, кэш слагов сбрасывается при сохранении термина.
- Виджет «Выбор города» (tom-select, иерархия область → город → район) + мобильная модалка в шапке.
- REST `POST /idpg/v1/city/select` (nonce `wp_rest`); admin-ajax `idpg_set_city` оставлен fallback.
- `class-geo-service.php` — фасад гео-логики; `class-city-url.php` — маршрутизация.

### 1.4 Авторизация и аккаунты

- REST (namespace `idpg/v1`): `POST /auth/login`, `POST /auth/register`, `POST /auth/logout`,
  `POST /auth/forgot-password`, `POST /auth/reset-password`, `GET /auth/me`.
- PHP-fallback обработка форм без JavaScript на `/login/` и `/reset-password/` (+ nonce-поля).
- Кастомные HTML-письма: приветствие после регистрации, восстановление пароля, настройка
  отправителя, логотип письма (фильтр `idpg_email_logo_url`).
- Rate limiter: ограничение попыток входа/регистрации/сброса по IP и email.
- Honeypot в форме регистрации: скрытое поле `idpg_company` (REST `handle_register()` и PHP-fallback
  без JS); при заполнении пользователь не создаётся, ответ нейтральный. Опциональная капча —
  фильтры `idpg_auth_captcha_enabled` / `idpg_auth_captcha_verify`.
- Подтверждение организатора по email: ключ-хэш + срок в user meta, роль `event_organizer`
  выдаётся только после перехода по ссылке.
- Страница доуполнения анкеты `/organizer/complete/` (rewrite + шаблон + JS + защита доступа).
- **Личный кабинет организатора** `/organizer/` (+ `/organizer/{section}/`, разделы
  `dashboard` | `events` | `profile`): rewrite + шаблон + меню + JS/CSS. Доступ — только
  роли `event_organizer` и выше (`administrator`, `editor`): гость → `/login/`, флаг анкеты →
  `/organizer/complete/`, нет прав → `403`. Каркас: разделы рендерятся серверно,
  `organizer.js` переключает видимость и адрес без перезагрузки. Страница исключена из sitemap Yoast.
  После входа организатор попадает в кабинет (не в `wp-admin`): вход с формы `/login/`,
  ветка «уже авторизован», фильтр `login_redirect` (приоритет 20) и соцвход;
  администратор и редактор по-прежнему ведут в `wp-admin`. Ссылка «Панель» в шапке
  организатору тоже указывает на кабинет.
  Рекламный баннер над подвалом (виджет в области «Подвал», позиция `before-footer`) в
  кабинете не выводится: фильтр `sidebars_widgets` обнуляет такие области только внутри ЛК,
  на остальных страницах реклама остаётся.
- Кастомный аватар: подмена Gravatar на загруженное вложение (`idpg_avatar_attachment_id`)
  на фронтенде, `IDPG_Media::idpg_get_avatar_id()`.
- `class-auth.php`: константа `LOGIN_URL` (`/login/`), редиректы после входа/выхода,
  `restrict_admin_access()`, отключение стандартной регистрации WP.
- Серверная проверка чекбокса оферты при регистрации; корректный JSON 429 при блокировке IP
  плагином All-In-One WP Security.
- Отдельное согласие на обработку ПДн (152-ФЗ): обязательный чекбокс `idpg_pdn_consent`
  (REST и PHP-fallback без JS), серверная проверка `400 pdn_consent_required`, запись факта
  согласия в user meta `idpg_pdn_consent` (редакция, источник, хеш IP).

### 1.4а Персональные данные (152-ФЗ)

- **Модуль `class-privacy.php`** (`IDPG_Privacy`): согласия, cookie-баннер, права субъекта.
- Cookie-баннер: cookie `idpg_cookie_consent` на год, значения `all` | `necessary`,
  работает без JavaScript (POST с nonce) и с ним; выбор можно изменить повторно.
- Права субъекта на странице `/privacy/`: самостоятельная выгрузка данных (JSON) через
  `admin-post.php?action=idpg_privacy_self_export` и запрос на удаление с подтверждением
  (nonce + серверная проверка чекбокса, письмо администратору).
- Админ-страница «Иду по городу → Конфиденциальность» (`admin.php?page=idpg-privacy`,
  `manage_options`): список запросов на удаление, выгрузка данных субъекта в JSON/CSV,
  удаление по запросу с подтверждением (запрещено для пользователей с `manage_options`
  и для себя), журнал действий (`idpg_privacy_log`, до 200 записей).
- Фильтры `idpg_privacy_consent_record`, `idpg_privacy_consent_version`,
  `idpg_privacy_page_url`, `idpg_privacy_export_data`; действие `idpg_privacy_delete_requested`.
- Обновлены шаблонные тексты `page-privacy.php` (12 разделов) и `page-terms.php` (8 разделов);
  содержимое страниц в БД синхронизировано.

### 1.5 Роли, права, изоляция контента

- Роли: `administrator`, `editor`, `event_organizer` (caps на event/venue, версионная
  синхронизация capabilities), `subscriber`.
- `class-organizer-scope.php` — организатор/подписчик видит в медиатеке только свои вложения
  (список + REST media-modal), список событий скоупится `IDPG_City_Filter::filter_events_for_organizer()`.
- `class-event-moderation.php` — организатор не может публиковать: событие уходит в `pending`,
  письма админам о новом событии и организатору об одобрении.
- `class-user-purge.php` — полная зачистка при удалении организатора (постеры, вложения-потомки,
  аватар, сиротские `_edit_last`/`_edit_lock`), с учётом выбора «переназначить записи».

### 1.6 REST API (23 маршрута, namespace `idpg/v1`)

**Публичные:** `GET /events` (city, category, date_from, date_to, search, page, per_page),
`GET /venues`, `GET /venues/{id}`, `GET /cities`, `GET /categories`, `GET /age-ratings`,
`GET /event-dates` (год/месяц — для календаря), `GET /search/live`, `GET /search/popular`,
`POST /subscribe` (подписка на дайджест), `POST /city/select`.

**Избранное (3):** `GET /favorites`, `POST /favorites/add`, `POST /favorites/remove`.

**Под правами (nonce + `can_manage_events`):** `POST /venues/create`, `PUT /venues/{id}`.

**Авторизация (6):** см. п. 1.4.

У каждого маршрута — `permission_callback`; у параметров — `sanitize_callback`/`type`/`enum`.

### 1.7 Пользовательские функции

- **Избранное:** user meta `_idpg_favorites` + REST + счётчик + rewrite-страница `/lk/favorites/`
  (страницы в БД нет) + шаблон `page-favorites.php` + ассеты.
- **Просмотры/клики:** `_idpg_views`, `_idpg_clicks` с защитой от ботов и повторных заходов.
- **iCal-экспорт:** `.ics` месяца и одиночного события (`?idpg_action=ical`), iCalendar VEVENT.
- **Календарь:** REST `/event-dates`, виджет с кэшем (структура месяца 24 ч, события 1 ч),
  навигация по месяцам через AJAX, подсказки на день, фильтры по категории/городу, цветовые схемы.
- **Синхронизация билетов:** WP-Cron каждые 3 часа, обновление `_idpg_ticket_status` и
  `_idpg_external_id`; email-уведомления автору при смене статуса (`idpg_ticket_status_changed`).
- **Баннеры:** баннер текущего события (`do_action( 'idpg_banner_position', 'hero'|'middle'|'bottom' )`)
  на главной; заявка организатора на показ + подтверждение оплаты админом; флаги скрытия.
- **Режим «Сайт в разработке»:** заглушка HTTP 503, пароль доступа, белый список IP, cookie,
  обратный отсчёт до авто-выключения, контакты и соцсети, страница настроек.
- **Подписка на дайджест:** таблица `{prefix}idpg_subscribers` (email, IP, дата), REST `/subscribe`
  с honeypot, чекбоксом согласия и rate limiter.
- **Расширенный поиск:** `class-search.php` — единая точка (s-поиск + события площадок + совпадения
  по названиям таксономий), общая для темы (`pre_get_posts`) и REST, лимит 100 ID на подзапрос.
- **Медиа:** `class-media.php` — постеры, галерея, аватар, поиск вложения по URL, безопасное
  удаление (одиночное/массовое с очисткой постера), проверка типа файла.
- **Статистика:** виджеты на dashboard — администратор (события по статусам, площадки, подписчики,
  пользователи, просмотры/клики, избранное, города, ближайшие события, топ организаторов),
  организатор (только свои данные) + подменю.
- **Миграции:** перенос `_idpg_event_*` → `_idpg_*`, идемпотентно и возобновляемо,
  WP-CLI команда `wp idpg migrate-meta`.

### 1.8 Интеграции и админка

- **Yoast SEO:** сущности Event и Place в JSON-LD (GeoCoordinates, addressLocality, Offer),
  принудительный `og:image`/`twitter:image` из постера, поддержка крошек Yoast.
- **All-In-One WP Security:** авто-добавление маршрута `idpg` в белый список REST + safety-net
  фильтр + админ-предупреждения о конфликтах (блокировка REST, переименование страницы входа).
- **Настройки плагина:** меню «IDPG» — вкладки Главная (блоки), Области виджетов, Экспорт / О плагине.
- **Подстраница «Конфиденциальность»** в этом же меню — обработка запросов субъектов ПДн
  (см. п. 1.4а).
- **Настройки режима разработки:** отдельная страница в «Настройки».
- **Виджеты плагина (3):** «Выбор города», «Календарь событий», «Самые ожидаемые события».
- **Уведомление о заглушке API билетов** в админке.

### 1.9 Инженерная обвязка

- `uninstall.php` — очистка опций, транзиентов, user meta, cron (данные событий сохраняются,
  полная зачистка — константой `IDPG_CORE_UNINSTALL_WIPE_DATA`).
- i18n: `languages/idpg-core.pot`, генератор `bin/make-pot.js` (без внешних зависимостей).
- Тесты: PHPUnit — 13 файлов (`test-auth-emails`, `test-cpt`, `test-geo-service`, `test-ical-export`,
  `test-organizer-events`, `test-organizer-page`, `test-organizer-pending`, `test-rest-api`, `test-roles`,
  `test-template-helper`, `test-theme` и др.), `phpunit.xml.dist`, `bin/install-wp-tests.*`.
  Итого зелёный прогон: idpg-core — 85 тестов / 210 утверждений (DEV-52, 19.09.2026).
- Стандарты: `.phpcs.xml.dist`, `.circleci/config.yml`, `.cursorrules`.
- PHPCS в CI (DEV-27A): job `phpcs` прогоняет PHPCS (WordPress + `PHPCompatibilityWP`) по `plugins/idpg-core`,
  `plugins/idpg-auth` и теме `idpg_core`. Легаси-нарушения зафиксированы в baseline — `phpcs-baseline.neon`
  в каждом компоненте (формат NEON, плагин `forrest79/phpcs-ignores`); подключается через `--bootstrap`,
  сборка падает только на **новых** ошибках (`--runtime-set ignore_warnings_on_exit 1` — предупреждения не валят job).
  Замер объёма на 19.09.2026: `idpg-core` — 644 ошибки / 421 предупреждение, `idpg-auth` — 2814 / 241,
  тема `idpg_core` — 829 / 211.
- Composer: Carbon Fields 3.6 + dev-зависимости PHPUnit.

---

## 2. Плагин IDPG Auth (v1.0.0) — 11 классов

| Блок | Что сделано |
|---|---|
| Вход через ВКонтакте | `class-wp-auth-ru-vk.php` — OAuth 2.0 (`id.vk.com`), start/callback |
| Вход через Яндекс ID | `class-wp-auth-ru-yandex.php` — OAuth 2.0 (`oauth.yandex.ru`), права `login:info`, `login:email` |
| Вход через MAX | `class-wp-auth-ru-max.php` — Mini App `initData` + HMAC-SHA256 (у MAX нет OAuth 2.0) |
| Общий движок | `class-wp-auth-ru-provider.php` + `class-wp-auth-ru-oauth-provider.php`: вход/создание пользователя по ID провайдера, по email либо регистрация, генерация логина, вывод кнопок и стилей, ошибки |
| REST | namespace `wp-auth-ru/v1`: `GET /vk/start`, `GET /vk/callback`, `GET /yandex/start`, `GET /yandex/callback`, `POST /max/validate` |
| Точки вставки кнопок | `login_form`, `register_form`, формы WooCommerce, баннер в checkout, стили на фронте |
| Админка | «Настройки» → страница с вкладками Настройки / Журнал событий, очистка логов, разблокировка IP, notice о настройке |
| Журнал событий | `class-wp-auth-ru-logger.php` — 200 последних записей в опции, уровни INFO/SUCCESS/WARNING/ERROR |
| Журнал входов | `class-wp-auth-ru-login-log.php` — отдельная таблица БД: аккаунт, IP, браузер/ОС/устройство, провайдер (VK/Яндекс/MAX/пароль), время |
| Монитор безопасности | `class-wp-auth-ru-security.php` — брутфорс + авто-блокировка IP, перечисление пользователей (REST users, `?author=N`, `/author/`), доступ к `.git`/`wp-config.php.*`/`.env`/`debug.log`/дампам, смена роли на админа, новые пользователи, активация/удаление плагинов и тем, правка файлов; своя таблица БД + email-алерты |
| Соответствие КоАП РФ | `class-wp-auth-ru-compliance.php` — предупреждение об активных плагинах иностранной авторизации, режим соответствия: блокировка входа через иностранные сервисы, отметка пользователей разрешённых российских провайдеров |
| WooCommerce | `declare_compatibility( 'custom_order_tables' )` (HPOS) |

---

## 3. Тема idpg_core (v1.0.73) + дочерняя тема

### 3.1 Шаблоны (25 PHP-файлов + 7 template-parts)

- **Главная:** `front-page.php` — секции «Сегодня», «В ближайшие выходные», «Бесплатные события»,
  «Популярные события», лента «Все события» + позиции баннеров и областей виджетов.
- **Афиша и контент:** `archive-event.php`, `archive-venue.php`, `archive.php`, `single-event.php`,
  `single-venue.php`, `taxonomy-city.php`, `taxonomy-event_category.php`, `search.php`, `404.php`,
  `page.php`, `single.php`, `index.php`.
- **Страницы:** `page-login.php` (вход / регистрация пользователя / регистрация организатора
  с полями org_name, contact_name, phone, terms), `page-reset-password.php`, `page-terms.php`,
  `page-privacy.php`, `page-offer` (через `page-legal.php`), `page-about.php`, `page-contacts.php`,
  `page-favorites.php`, `page-organizer-complete.php`, `page-organizer.php`.
- **Части:** `content-event.php`, `content-event-today.php`, `content-venue.php`, `content-page.php`,
  `content-none.php`, `content.php`, `home-section.php`.

### 3.2 Функциональность темы (`inc/`)

- **`template-tags.php` (30 хелперов):** мета события, сеансы, кнопка билетов, постер (ID/URL/HTML),
  галерея, qvar города, URL афиши/площадок города, символ и формат цены, формат даты, диапазон дат,
  имена терминов, данные площадки, события площадки, мета площадки, адрес события, ссылка на карты,
  разбор видео (embed URL, файл, MIME, метка хостинга), текущий город, выборка событий секции главной,
  schema-дата, JSON-LD события, кнопки «поделиться».
- **`widgets.php`:** 3 виджета — «Реклама» (IDPG_Ad_Ru_Widget: платформы, шаблоны, админ-JS
  вставки кода, санитайз допустимого HTML), «Последние события», «Подписка» (отправка в REST
  `/subscribe`) + JS-сортировка виджетов сайдбаров.
- **`widget-areas.php`:** менеджер областей виджетов — 5 позиций вывода (под шапкой, над контентом,
  сайдбар события, под контентом, перед футером), условия показа (везде, главная, страницы, записи,
  архивы, конкретные страницы), страница в админке.
- **`home-sections.php`:** настройки видимости секций главной, страница «Настройки главной»
  (Внешний вид), опция `idpg_home_sections`.
- **`breadcrumbs.php`:** свои крошки с интеграцией вывода Yoast и обёрткой в стили темы.
- **`class-idpg-nav-walker.php`:** кастомный Walker меню.
- **`theme-helpers.php`:** метабокс «Скрыть заголовок» для страниц/записей.
- **`organizer-menu.php`:** меню личного кабинета организатора — `idpg_organizer_menu()`
  (пункты с сортировкой по `order`), фильтр `idpg_organizer_menu_items`, хелперы
  `idpg_organizer_section_url()`, `idpg_organizer_current_section()`,
  `idpg_organizer_after_login_url()`, `idpg_core_is_organizer_cabinet()`;
  `idpg_organizer_hide_before_footer_widgets()` (фильтр `sidebars_widgets`) убирает
  блок над подвалом в кабинете.

### 3.3 Фронтенд-логика

- Фильтры афиши через `pre_get_posts`: `?date=today|tomorrow|weekend|YYYY-MM-DD`, `?is_free=1`,
  `?city=<term_id>`; расчёт ближайших выходных.
- Шапка: модалка выбора города (иерархический список), live-поиск с выпадающим списком
  (REST `/search/live`, `/search/popular`), меню, ссылки в зависимости от роли.
- Кастомный логотип: `idpg_render_logo()`, генерация CSS логотипа, превью в кастомайзере.
- Автосоздание при активации: страницы `/login/`, `/reset-password/`, `/terms/`, `/privacy/`,
  `/offer/` (с дефолтными текстами) и дефолтное меню.
- Правовые документы: `idpg_core_legal_document()` / `idpg_core_legal_render()`.
- `theme.json`, локальный шрифт Gilroy (`@font-face`), свои размеры изображений, версия ассетов.

### 3.4 Ассеты

- **JS:** `auth.js`, `live-search.js`, `navigation.js`, `canvas-fix.js`, `subscribe.js`,
  `organizer-complete.js`, `organizer.js`, `widget-ad.js` + `widget-ad-front.js`, `customize-preview.js`,
  `customize-sidebar-sticky.js`, `social-icons.js`, `pikaday.js` + `pikaday.range.js` +
  `pikaday-locale-ru.js`; vendor: Toastify, xlsx.full.min.js.
- **CSS/SCSS:** `style.css` (сборка), `templates.css`, `editor-style.css`, `subscribe.css`,
  `organizer.css`, `widget-areas-admin.css`, SCSS-исходники (`base/`, `components/`, `pages/`, `_responsive.scss`).

### 3.5 Дочерняя тема `idpg_core-child` (v1.0.0)

- Подключение стилей родителя, RTL-поддержка.
- Принудительное отключение регистрации пользователей на уровне опции
  (`option_users_can_register`), даже если галочка в Настройки → Общие включена заново.

---

## 4. Инфраструктура и документация

- `docs/PRODUCTION.md` — чек-лист запуска на проде (wp-config, URL, uploads, cron, SMTP,
  соц-авторизация, реклама, безопасность).
- `docs/PLUGIN_TZ.md` — техническое задание портала (v1.0).
- `docs/README_new.md` — архивная расширенная документация темы; `docs/README.md` — индекс.
- `DOCUMENTATION.md` в корне WP — разбор структуры, функций, эндпоинтов, безопасности, TODO.
- `deploy.sh` — деплой через tar+ssh на Hostland (без БД и wp-config).
- `wp-config.php`: `IDPG_DEFAULT_CITY_ID` (Ижевск), `IDPG_TICKET_API_URL` (заглушка).
- `llms.txt` — генерируется Yoast SEO.
- Сторонние плагины в сборке: Yoast SEO, WP Mail SMTP, All-In-One WP Security, WpVivid Backup, Clearfy Pro.

---

## 5. Расхождения: что НЕ реализовано или устарело в документации

| # | Факт | Детали |
|---|---|---|
| 1 | REST `/idpg/v1/auth/organizer-complete` **не зарегистрирован** | На него шлёт форма `page-organizer-complete.php` и `organizer-complete.js`; в `class-rest-auth.php` только 6 маршрутов. Форма доуполнения анкеты организатора фактически не работает |
| 2 | `class-banners.php` отсутствует | README плагина и ТЗ описывают систему баннеров middle/bottom (UTM, период показа, TTL кэша) — файла нет. Баннеры = баннер текущего события + области виджетов + рекламный виджет |
| 3 | Модуль `includes/social-auth/*` отсутствует | README плагина описывает встроенный VK/Яндекс OAuth — соц-вход вынесен в отдельный плагин `idpg-auth` (VK/Яндекс/MAX) |
| 4 | ЛК `/lk/` удалён (1.1.0) | Нет `page-lk.php` и REST `/user/*`; осталось только `/lk/favorites/`. README темы всё ещё ссылается на ЛК |
| 5 | Синхронизация билетов — заглушка | `IDPG_TICKET_API_URL` = `example.com`, запросы блокируются защитой, в админке предупреждение |
| 6 | `class-wp-auth-ru-updater.php` не подключён | Файл автообновления с GitHub есть, но не входит в `require_once` в `idpg-auth.php` — автообновление выключено |
| 7 | Поля из ТЗ п.11 не реализованы | `idpg_pin_expires`, `idpg_venue_hours`, `idpg_venue_social`, `idpg_legal_type`, `idpg_inn`, `idpg_verified`, `idpg_documents`, панель организатора в админке — в коде отсутствуют. Реализованы `_idpg_stream_embed` и `_idpg_venue_type` |
| 8 | Координаты события из ТЗ не реализованы | `_idpg_coordinates_lat/lng`, `_idpg_address` — в коде нет (гео хранится только у площадки) |
| 9 | `documents`/`verified` организатора | Реализовано частично и иначе: подтверждение по email + модерация публикаций |

---

## 6. ЛК организатора: фактическое состояние (аудит DEV-00A, 19.09.2026)

> **Источник:** `docs/README_DEV-00A.md` (полный отчёт, проверка 16.09.2026) + повторная
> сверка 19.09.2026 на текущем дереве `idpg-core` 1.1.2 / тема 1.0.75. **Выводы сходятся.**
>
> Существенная часть файлов ЛК есть в репозитории, но **не подключена** в рантайме.
> Ниже — фактическое состояние «что реально работает», затем gap-лист (см. §3).

### 6.1 Что фактически работает

| Компонент | Состояние |
|---|---|
| `/organizer/complete/` (анкета доуполнения) | Маршрутизация работает (`IDPG_Organizer_Page`, подключён `idpg-core.php:148`); **форма не работает** — REST `POST /auth/organizer-complete` не зарегистрирован → 404 (DEV-01, §5 п.1) |
| `page-organizer.php` + `template-parts/organizer/section-{dashboard,events,profile}.php` | Файлы есть и валидны, но к ним нет маршрутизации: `class-organizer-url.php` не загружен |
| Модерация по платности (`class-event-moderation.php`) | Загружен (`idpg-core.php:151`): орг. не публикует (→ `pending`), бесплатные авто-публикуются, платные с неполными билетными данными → `draft` + уведомления |
| Изоляция контента (`IDPG_Organizer_Scope`, `IDPG_City_Filter`) | Работает — организатор видит только свои медиа/события |
| Подтверждение по email (`IDPG_Organizer_Verify`) | Работает — роль `event_organizer` выдается после перехода по ссылке |
| Редирект после входа организатора | Родительская логика работает (`class-auth.php`): организатор попадает в `wp-admin`. Целевой редирект в `/organizer/` (DEV-37) не работает — метод в не подключённом `class-organizer-url.php` |

### 6.2 Что есть, но НЕ подключено (мёртвый код в рантайме)

| Файл / класс | Назначение | Причина выключености |
|---|---|---|
| `plugins/idpg-core/includes/class-organizer-url.php` (`IDPG_Organizer_URL`) | Роутер `/organizer/` + `/organizer/{section}/`: rewrite, query vars, `template_include`, `get_access_decision()`, `after_login_url()` | Нет `require_once` в `idpg-core.php` (блок 75–119), `::init()` нигде не вызывается |
| `themes/idpg_core/inc/organizer-menu.php` (7 функций `idpg_organizer_*`) | Меню кабинета, `idpg_organizer_section_url()`, `idpg_core_is_organizer_cabinet()`, скрытие баннера над подвалом | Нет `require_once` в `functions.php` (блок 351–381) |
| `themes/idpg_core/assets/js/organizer.js`, `assets/css/organizer.css` | Переключение разделов ЛК, стили кабинета | Нет `wp_enqueue_script/style` |
| `themes/idpg_core/assets/js/organizer-complete.js` | `fetch` на `/auth/organizer-complete` | Нет enqueue + целевой REST-маршрут не зарегистрирован |
| `themes/idpg_core/page-organizer.php` (`page-organizer.php`) | Шаблон кабинета | Работает только если открыт как страница WP; страница `/organizer/` в БД не создана |
| `plugins/idpg-core/includes/class-privacy.php` (`IDPG_Privacy`, 1318 стр.) | 152-ФЗ: согласие, cookie-баннер, выгрузка/удаление данных субъекта | Нет `require_once` в `idpg-core.php`, нет `IDPG_Privacy::instance()` в `idpg_core_init()` |
| Страница WP `/organizer/` | Точка входа в кабинет | Не создаётся: `idpg_core_create_organizer_page()` существует только в docblock'ах (автосоздаются только `/login/`, `/reset-password/`, `/terms/`, `/privacy/`, `/offer/`) |

### 6.3 Чего нет вовсе

- REST `POST /auth/organizer-complete` (DEV-01) и `handle_organizer_complete()`.
- REST-маршруты ЛК: `/organizer/events` (CRUD), `/organizer/events/{id}/submit`, `/organizer/profile`, `/organizer/stats`.
- Страницы `/organizer/event/new/`, `/organizer/event/{id}/`, их шаблоны и `organizer-event-form.js`.

### 6.4 Gap-лист (по приоритету)

| # | Gap | Приоритет | Куда ведёт |
|---|---|---|---|
| G1 | `class-organizer-url.php` не подключён → весь `/organizer/` мёртв; страница `/organizer/` в БД не создана | P0 | DEV-34 |
| G2 | `inc/organizer-menu.php` не подключён → `idpg_organizer_*()` не определены в рантайме | P0 | DEV-34/37/38 |
| G3 | `organizer.js` / `organizer.css` не подключаются | P0 | DEV-34 |
| G4 | REST `POST /auth/organizer-complete` не зарегистрирован → анкета падает 404 | P0 | DEV-01 |
| G5 | `/organizer/event/new/`, `/organizer/event/{id}/` + REST `/organizer/events*` + `organizer-event-form.js` — не существуют | P1 | ЛК-события (DEV-35 план) |
| G6 | Разделы `dashboard`/`events` — заглушки без реальных данных; REST `/organizer/stats`, `/organizer/profile` нет | P1 | ЛК-события (DEV-35 план) |
| G7 | Флаг pending-анкеты: рассинхрон `idpg_org_pending` / `_idpg_org_pending` | P2 | DEV-01 попутно |
| G8 | `class-privacy.php` (152-ФЗ) — код есть, не загружен | P0 (законодательство) | DEV-06 |
| G9 | `is_pending()` требует роль `event_organizer`, которой нет у только что зарегистрированного пользователя | P2 | DEV-32 |
| G10 | «Платная модерация события» (оплата → публикация) как продукт не задокументирована; реализована только модерация по платности (правила `class-event-moderation.php`) | P2 (решение владельца) | — |

### 6.5 Навигация по DEV-33…36 (TZ_BACKLOG)

Заявленные ранее «DEV-33…36» для ЛК-событий в актуальной нумерации `TZ_BACKLOG.md` частично заняты
другими задачами. Соответствие по факту кода (детали — `README_DEV-00A.md`, §10):

| Заявленная тема | Реальная позиция в TZ_BACKLOG | Статус по факту |
|---|---|---|
| «DEV-33–34: каркас ЛК» | DEV-33 = rate limiter (занят); DEV-34 = каркас ЛК | Файлы каркаса есть, обвязка (G1–G3) не применена |
| «DEV-35: список/создание событий в ЛК» | Свободного DEV-35 нет | Открытая работа: G5, G6 |
| «DEV-36: доуполнение анкеты» | Совпадает с DEV-01 (P0) | Открыто: G4 |
