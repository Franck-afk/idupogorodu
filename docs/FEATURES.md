# Полный список реализованного в проекте «Иду по городу»

Инвентаризация по фактическому коду (не по документации). Дата: 15.09.2026, дополнено (аудит
ЛК организатора DEV-00A) 19.09.2026, уточнено (DEV-130: число классов, регрессии DEV-102;
DEV-131: регрессии закрыты) 30.09.2026. Проверено: `plugins/idpg-core` (v1.1.2),
`plugins/idpg-auth` (v1.0.0), `themes/idpg_core` (v1.0.75), `themes/idpg_core-child` (v1.0.0),
`docs/`, `deploy.sh`, `DOCUMENTATION.md`.

> ⚠️ **Внимание (19.09.2026).** Разделы, описывающие личный кабинет организатора и 152-ФЗ
> (п. 1.4 «кабинет», п. 1.4а, п. 3.2 `organizer-menu.php`, п. 3.4 ассеты кабинета), фиксируют
> **намеренное/документированное состояние** (версии 1.1.7…1.1.8 / 1.0.79…1.0.81 по
> `TZ_BACKLOG.md`). В **текущем рабочем дереве** 1.1.2 / 1.0.75 эти файлы есть, но **не подключены**.
> Фактическое состояние — см. **§ 6 «ЛК организатора: фактическое состояние»** и
> `docs/README_DEV-00A.md`.

---

## 1. Плагин IDPG Core (ядро) — 39 классов

> **Счёт классов уточнён 30.09.2026 (DEV-130).** В шапке стояло «33 класса» —
> это число соответствовало дереву до DEV-97/102. Фактически `idpg-core.php`
> подключает **39** файлов классов (проверено: 39 строк `require_once IDPG_CORE_DIR`
> в блоке подключений). Пять классов (`organizer-url`, `rest-organizer`,
> `event-rejection`, `promotion`, `privacy`) были подключены, но не
> инициализировались — их `::instance()` добавлены в `idpg_core_init()`
> восстановлением DEV-102; регрессия закрыта тестом `test-class-init.php` (DEV-100).
> Карточка восстановления — `TZ_BACKLOG.md` DEV-102, порядок — `docs/PRODUCTION.md` §12.

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
  `POST /auth/forgot-password`, `POST /auth/reset-password`, `GET /auth/me`,
  `GET|PUT /organizer/profile` (профиль организатора, DEV-73),
  маршруты событий организатора (DEV-42): `GET|POST /organizer/events`,
  `GET|PUT|DELETE /organizer/events/{id}`, `POST /organizer/events/{id}/submit`,
  `GET /organizer/stats`.
- PHP-fallback обработка форм без JavaScript на `/login/` и `/reset-password/` (+ nonce-поля).
- Кастомные HTML-письма: приветствие после регистрации, восстановление пароля, настройка
  отправителя, логотип письма (фильтр `idpg_email_logo_url`).
- Rate limiter: ограничение попыток входа/регистрации/сброса по IP и email.
- Honeypot в форме регистрации: скрытое поле `idpg_company` (REST `handle_register()` и PHP-fallback
  без JS); при заполнении пользователь не создаётся, ответ нейтральный. Опциональная капча —
  фильтры `idpg_auth_captcha_enabled` / `idpg_auth_captcha_verify`.
- Подтверждение организатора по email: ключ-хэш + срок в user meta, роль `event_organizer`
  выдаётся только после перехода по ссылке. Флаг ожидания подтверждения —
  `idpg_org_pending` в user meta.
- **Личный кабинет организатора** `/organizer/` (+ `/organizer/{section}/`, разделы
  `dashboard` | `events` | `profile`): rewrite + шаблон + меню + JS/CSS. Доступ — только
  роли `event_organizer` и выше (`administrator`, `editor`): гость → `/login/`, нет прав →
  `403`, иначе кабинет. Каркас: разделы рендерятся серверно,
  `organizer.js` переключает видимость и адрес без перезагрузки. Страница исключена из sitemap Yoast.
  После входа организатор попадает в кабинет (не в `wp-admin`): вход с формы `/login/`,
  ветка «уже авторизован», фильтр `login_redirect` (приоритет 20) и соцвход;
  администратор и редактор по-прежнему ведут в `wp-admin`. Ссылка «Панель» в шапке
  организатору тоже указывает на кабинет.
- Отдельной анкеты организатора нет (DEV-72): после подтверждения email организатор
  сразу попадает в кабинет, данные организации заполняются в профиле `/organizer/profile/`.
- **Редактирование профиля** в разделе `/organizer/profile/` (DEV-73): форма полей
  `display_name`, `idpg_org_name`, `idpg_contact_name`, `idpg_phone` (+ скрытый `avatar_id`);
  email только для чтения. Работает с JS (`PUT /organizer/profile` без перезагрузки) и без
  него (обычный `POST` на страницу кабинета, nonce + Post/Redirect/Get). Маршрут не принимает
  `user_id` — редактируется только собственный профиль; доступ у ролей `event_organizer`
  и выше. Логика санитайза и записи — `IDPG_Organizer_URL::sanitize_profile_input()` /
  `update_profile()`, общая для REST и no-JS пути.
  Рекламный баннер над подвалом (виджет в области «Подвал», позиция `before-footer`) в
  кабинете не выводится: фильтр `sidebars_widgets` обнуляет такие области только внутри ЛК,
  на остальных страницах реклама остаётся.
- Кастомный аватар: подмена Gravatar на загруженное вложение (`idpg_avatar_attachment_id`)
  на фронтенде, `IDPG_Media::idpg_get_avatar_id()`.
- `class-auth.php`: константа `LOGIN_URL` (`/login/`), редиректы после входа/выхода,
  `restrict_admin_access()`, отключение стандартной регистрации WP.
- Серверная проверка чекбокса оферты при регистрации; корректный JSON 429 при блокировке IP
  плагином All-In-One WP Security.
- **Rate limiter сброса пароля — два независимых лимита (DEV-93).** Лимит по email
  (`check_password_reset_attempt()`) считает запросы к одному адресу, но не видит перебор
  **множества разных** адресов с одной машины. Поэтому добавлен лимит по IP:
  `RESET_IP_LIMIT = 10` запросов за `RESET_IP_WINDOW = 3600` с, метод
  `check_password_reset_ip_attempt()` (`class-auth-rate-limiter.php:272`), вызываемый из
  `handle_forgot_password()` **до** расхода лимита по email (`class-rest-auth.php:601`).
  Порядок принципиален: иначе перебор адресов исчерпывал бы квоту письма раньше, чем
  срабатывала бы защита по IP. Транзиентный ключ, окно скользящее, неразрешённый адрес
  пропускается. Тесты — `tests/test-auth-rate-limiter.php` (6 тестов / 31 утверждение:
  в пределах лимита, блокировка сверх, скоуп по адресу, неразрешённый IP, мусорные данные,
  перебор адресов, неизменность лимита по email).
- Отдельное согласие на обработку ПДн (152-ФЗ): обязательный чекбокс `idpg_pdn_consent`
  (REST и PHP-fallback без JS), серверная проверка `400 pdn_consent_required`, запись факта
  согласия в user meta `idpg_pdn_consent` (редакция, источник, хеш IP).

### 1.4а Персональные данные (152-ФЗ)

- **Модуль `class-privacy.php`** (`IDPG_Privacy`): согласия, cookie-баннер, права субъекта.
  Подключён (G8, 28.09.2026); до этого файл лежал в дереве неподключённым.
- **Согласие на обработку ПДн обязательно** при регистрации организатора: отдельный
  чекбокс в форме (не путать с согласием с офертой), серверная проверка, без него
  `POST /idpg/v1/auth/register` → `400 pdn_consent_required`. Факт согласия пишется в
  user meta `idpg_pdn_consent` (дата, редакция документов, источник, хэш IP).
  Отказ по согласию не расходует лимит попыток регистрации.
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
  письма админам о новом событии и организатору об одобрении. Правила **по цене** применяет
  вторая фаза `schedule_price_moderation()` (`:180`): бесплатное (`is_free=yes`) публикуется
  сразу, платное с полными билетными данными остаётся на модерации, платное без данных
  билетов понижается до `draft` с подсказкой. Штатный триггер — хук Carbon Fields
  `carbon_fields_post_meta_container_saved` (`:102`); на REST-пути отправки события его нет,
  поэтому `class-rest-organizer.php:1266` вызывает фазу **явно**, под защитой
  `class_exists( 'IDPG_Event_Moderation' )`. Обработка отложена на `shutdown` — в Gutenberg
  статус поста приходит первым запросом, а мета вторым, и решение должно учитывать оба
  (проверено DEV-130, 30.09.2026).
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
- Тесты: PHPUnit — **39 файлов** `tests/test-*.php` (на 30.09.2026; список растёт),
  `phpunit.xml.dist`, `bin/install-wp-tests.*`.
  Итого прогон на 30.09.2026: idpg-core — **533 теста / 1596 утверждений**,
  падений **0** (DEV-147, 30.09.2026: перепрогон после формального закрытия
  карточек; гард сборки CSS `tests/test-css-build.php` зелёный).
  Предыдущий прогон того же дня: 529 тестов / 1581 утверждение
  (DEV-143: партиалы на токенах, CHANGED 88 → 0).
  Исторический снимок DEV-52 (19.09.2026) — 85 тестов / 210 утверждений.
- **Версия темы — единый источник кэш-бастинга (DEV-138, 30.09.2026).**
  `IDPG_VERSION` (`themes/idpg_core/functions.php`) = `Version:` в `themes/idpg_core/style.css`
  = **1.0.78**; значение подставляется в `?ver=` у `style.css` и скриптов темы.
  Сторож — `tests/test-theme-version.php` (7 тестов): сверяет оба файла, формат версии,
  единственность объявления и рантайм-значение. Поводом стал откат DEV-102, после которого
  в дереве стояла `1.0.75` при заголовке `1.0.73` — версия не росла, и браузеры отдавали кэш.
- **Заголовки плагинов приведены к единому виду (DEV-139, 30.09.2026).** У `idpg-core`
  и `idpg-auth` теперь одинаковый набор: `Requires at least`, `Tested up to`, `Requires PHP`.
  Значения: `idpg-core` — WP 6.0 / PHP 7.4, `idpg-auth` — WP 5.8 / PHP 7.4, `Tested up to: 6.7`
  у обоих. PHP-порог **сознательно не поднят до 8.2**: код не использует PHP 8-фич
  (`str_contains`, `?->`, `enum`, `readonly` — нет), PHPCS `testVersion` = `5.6-`.
  Сторож — `tests/test-plugin-header.php` (7 тестов). Нюанс: `get_plugin_data()` не разбирает
  `Tested up to` и использует ключи `RequiresWP` / `RequiresPHP` — `Tested up to` читается
  отдельно через `get_file_data()`.
- Стандарты: `.phpcs.xml.dist`, `.circleci/config.yml`, `.cursorrules`.
- PHPCS в CI (DEV-27A): job `phpcs` прогоняет PHPCS (WordPress + `PHPCompatibilityWP`) по `plugins/idpg-core`,
  `plugins/idpg-auth` и теме `idpg_core`. Легаси-нарушения зафиксированы в baseline — `phpcs-baseline.neon`
  в каждом компоненте (формат NEON, плагин `forrest79/phpcs-ignores`); подключается через `--bootstrap`,
  сборка падает только на **новых** ошибках (`--runtime-set ignore_warnings_on_exit 1` — предупреждения не валят job).
  Замер объёма на 19.09.2026: `idpg-core` — 644 ошибки / 421 предупреждение, `idpg-auth` — 2814 / 241,
  тема `idpg_core` — 829 / 211.
- **CI на CircleCI (DEV-136, 30.09.2026):** в `.circleci/config.yml` два job'а — `phpunit`
  (`cimg/php:8.4` + `cimg/mysql:8.4`, ставит WP test library и прогоняет тесты `idpg-core` и `idpg-auth`)
  и `phpcs` (три прогона по компонентам). **Проект пока НЕ привязан** в CircleCI, поэтому job'ы
  на push не запускаются. Владельцу: CircleCI → Add Projects → `github.com/Franck-afk/idpg-source` → Follow;
  после привязки проверить, что job'ы стартуют на тестовом push.
- **Ветка `dev/phpcs-baseline` удалена (DEV-136).** Была избыточна: `a381795` целиком вошла
  в `master` через merge (DEV-59) и достижима из него. На удалёнке остались `master` и `main`.
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
  `page-favorites.php`, `page-organizer.php`.
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
  `organizer.js`, `widget-ad.js` + `widget-ad-front.js`, `customize-preview.js`,
  `customize-sidebar-sticky.js`, `social-icons.js`, `pikaday.js` + `pikaday.range.js` +
  `pikaday-locale-ru.js`; vendor: Toastify, xlsx.full.min.js.
- **CSS/SCSS:** `style.css` (исходник истины, см. 3.4.1), `templates.css`, `editor-style.css`, `subscribe.css`,
  `organizer.css`, `widget-areas-admin.css`, SCSS-исходники (`base/`, `components/`, `pages/`, `responsive.scss`).

### 3.4.1 Сборка CSS (DEV-87)

> **✅ Сборка эквивалентна `style.css` на уровне правил — пересборка
> больше не откатывает дизайн.** Закрыто 30.09.2026 (DEV-143). Ранее здесь стояло
> предупреждение «запускать нельзя»: тогда сборка расходилась с `style.css` на
> 88 правил и пересборка вернула бы жёсткий legacy-hex вместо токенов.
> Расхождение устранено, сторож `Test_Css_Build` зелёный.

**Замер 30.09.2026 (DEV-143).**

| | Байт | MD5 |
|---|---|---|
| `style.css` (эталон) | 79 743 | `62eb661b4ebc8c9beeba806200cf0de8` |
| Сборка `npx sass` | 93 287 | `96486b6ce6963008d791bbdc101f148a` |

Файлы **побайтово разные** — разное форматирование (`style.css` набран компактнее:
3 523 строки против 4 167), но **правила совпадают**: сторож даёт `MISSING` **0**,
лишних **0**, `CHANGED` **0**. Комментарии сохранены. Сборка детерминирована —
повторный прогон даёт тот же MD5.

**Что было исправлено (DEV-143).** Четыре партиала держали **170 значений**
старой палитры Tailwind. Их перевели на токены `var(--idpg-*)` — 132 замены:

| Legacy hex | Токен |
|---|---|
| `#0f172a` | `--idpg-ink` |
| `#2563eb` | `--idpg-primary` |
| `#1d4ed8` | `--idpg-primary-active` |
| `#475569` | `--idpg-body` |
| `#64748b` | `--idpg-muted` |
| `#cbd5e1`, `#e2e8f0`, `#e2e4e7` | `--idpg-hairline` |
| `#f8fafc` | `--idpg-surface-soft` |
| `#f1f5f9` | `--idpg-surface-strong` |
| `#eff6ff` | `--idpg-primary-bg` |
| `#ffffff` | `--idpg-canvas` |

Плюс: три `box-shadow` с `rgba(0, 0, 0, …)` переведены на токены; убран дубль
`.idpg-city-selector__btn__icon` (он жил и в `components/_city-selector.scss`
группой с `.idpg-city-selector__icon`, и вложенным `&__icon` в
`components/_header.scss`); правило `.create-event__btn { min-height: 44px }`
перенесено из `components/_recent-events.scss` в `components/_mobile.scss` —
в `style.css` оно стоит **после** основного правила, а `recent-events`
подключается раньше `create-event`.

**Статус `style.css`.** Он остаётся **эталоном**, с которым сверяется сборка.
Перегенерация (замена `style.css` выводом sass) не выполнялась: правила уже
совпадают, а файл стал бы на 644 строки длиннее из-за форматирования sass.

**Безопасная работа со сборкой (DEV-144).** Прямая перезапись эталона вынесена
в отдельную команду — случайный запуск больше не портит `style.css`:

| Команда | Что делает |
|---|---|
| `npm run check:css` | **основная.** Собирает SCSS в `style.css.tmp` и сверяет правила с `style.css`; печатает «потеряно / лишних / изменено», код возврата **1** при расхождении |
| `npm run compile:css:temp` | только сборка в `style.css.tmp`, без сверки |
| `npm run compile:css:apply` | **осознанная** перезапись `style.css` выводом sass |
| `npm run watch` | `--watch` сразу в `style.css` — живая разработка, тоже пишет эталон |

Команды `compile:css` **больше нет**: обращение к ней падает, а не перезаписывает
эталон. Сверку делает `bin/check-css-build.mjs` — реплика алгоритма сторожа
`Test_Css_Build` (PHPUnit-тест остаётся авторитетным гейтом в CI).

`style.css.tmp` закрыт `.gitignore` (`*.tmp`) и исключён из релизного архива
(`bundle`).

**Ручная проверка через sass** (если нужно без npm):

```
cd wp-content/themes/idpg_core
npx sass assets/scss/style.scss %TEMP%\style-check.css --no-source-map --style=expanded
```

Путь вывода — **только Windows-стилем**: POSIX `/tmp/…` sass молча игнорирует.
29.09 замер был испорчен именно тем, что вывод ушёл в `style.css`
(`TZ_BACKLOG.md` §14).

Структура `assets/scss/` (порядок `@use` в `style.scss` = порядок каскада):

| Каталог | Содержимое |
|---|---|
| `base/` | `_tokens.scss` — 103 CSS-переменные в `:root`, `_reset.scss`, `_typography.scss`, `_variables.scss` (переведён на `--idpg-*`) |
| `components/` | `_header`, `_nav`, `_auth-dropdown`, `_menu-toggle`, `_city-selector`, `_city-modal`, `_bottom-bar`, `_search`, `_footer`, `_container`, `_buttons`, `_home-sidebar`, `_widget-areas`, `_recent-events`, `_mobile` |
| `pages/` | `_auth`, `_create-event`, `_cabinet`, `_dashboard`, `_organizer` |
| корень | `responsive.scss`, `style.scss` (точка входа) |

**Дизайн-система — CSS Custom Properties.** Палитра, поверхности, типографика и
тень заданы переменными `--idpg-*` в `:root` (103 шт., 373 ссылки). Часть
партиалов (`pages/_create-event`, `pages/_cabinet`, `pages/_dashboard`,
`components/_home-sidebar`) ещё не переведена — см. выше.

**Правила при переносе (нарушение ломает каскад):**
- не переставлять `@use` в `style.scss` — очерёдность определяет приоритет;
- не переименовывать партиалы без перепроверки сборки;
- Sass сворачивает `calc(32px + 344px)` в `376px` — если важна читаемость
  связи, писать через интерполяцию: `calc(#{32}px + #{344}px)`;
- литерал интерполяции нельзя писать в комментарии: Sass разбирает его даже
  внутри `/* */` и падает на пустой последовательности.

**Сторож сборки.** `tests/test-css-build.php` (в наборе `idpg-core`) собирает
SCSS во временный файл и сравнивает набор правил с `style.css` на уровне
селектор + декларации + контекст `@media`. Требует нулевую разницу. **Сейчас
2 теста падают — это корректное состояние:** гард перечисляет остаток миграции
(88 правил). «Позеленить» его правкой теста было бы маскировкой. Без node
тест пропускается.

**Активна дочерняя тема** `idpg_core-child` (см. 3.5): `style.css` родителя
подключается отдельно как `chld_thm_cfg_parent-css`, дочерний — как
`idpg_core-style-css`. Оба с `?ver=IDPG_VERSION`.

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
| 1 | ~~REST `/idpg/v1/auth/organizer-complete` не зарегистрирован~~ | **Закрыто DEV-72**: расхождение снято удалением слоя анкеты — маршрута, шаблона `page-organizer-complete.php` и скрипта `organizer-complete.js` больше нет |
| 2 | `class-banners.php` отсутствует | README плагина и ТЗ описывают систему баннеров middle/bottom (UTM, период показа, TTL кэша) — файла нет. Баннеры = баннер текущего события + области виджетов + рекламный виджет |
| 3 | Модуль `includes/social-auth/*` отсутствует | README плагина описывает встроенный VK/Яндекс OAuth — соц-вход вынесен в отдельный плагин `idpg-auth` (VK/Яндекс/MAX) |
| 4 | ЛК `/lk/` удалён (1.1.0) | Нет `page-lk.php` и REST `/user/*`; осталось только `/lk/favorites/`. README темы всё ещё ссылается на ЛК |
| 5 | Синхронизация билетов — заглушка | `IDPG_TICKET_API_URL` = `example.com`, запросы блокируются защитой, в админке предупреждение |
| 6 | `class-wp-auth-ru-updater.php` не подключён | Файл автообновления с GitHub есть, но не входит в `require_once` в `idpg-auth.php` — автообновление выключено |
| 7 | Поля из ТЗ п.11 не реализованы | `idpg_pin_expires`, `idpg_venue_hours`, `idpg_venue_social`, `idpg_legal_type`, `idpg_inn`, `idpg_verified`, `idpg_documents`, панель организатора в админке — в коде отсутствуют. Реализованы `_idpg_stream_embed` и `_idpg_venue_type` |
| 8 | Координаты события из ТЗ не реализованы | `_idpg_coordinates_lat/lng`, `_idpg_address` — в коде нет (гео хранится только у площадки) |
| 9 | `documents`/`verified` организатора | Реализовано частично и иначе: подтверждение по email + модерация публикаций |
| 10 | `require_once` ≠ работающий класс | **Закрыто DEV-100**: пять классов организатора были подключены, но не инициализированы — хуки висели в конструкторах, которые никто не вызывал. Сейчас все 39 файлов `includes/` подключены, все классы инициализируются, на это есть набор `tests/test-class-init.php` (39 тестов / 96 утверждений) |

---

## 5.1 Инициализация классов плагина (DEV-100)

> Правило: **`require_once` подключает файл, но не запускает класс.**
> Хуки вешаются в конструкторе, а конструктор вызывается из `::instance()`
> или `::init()`. Класс без такого вызова молча не работает: REST-маршруты
> не регистрируются, фильтры не висят, а сайт отдаёт 200 с пустым ответом.

Состояние на 29.09.2026 — все 39 файлов `includes/` подключены в
`idpg-core.php`, все классы инициализированы. Способы инициализации:

| Способ | Классы | Где вызывается |
|---|---|---|
| `::instance()` в `idpg_core_init()` | 23 класса (`IDPG_CPT`, `IDPG_Roles`, `IDPG_REST_API`, `IDPG_Auth`, `IDPG_REST_Organizer`, `IDPG_Privacy` ядро при `require`, `IDPG_Promotion` и др.) | `idpg-core.php`, функция `idpg_core_init()` |
| `::instance()` при загрузке файла | `IDPG_Current_Event_Banner`, `IDPG_User_Purge`, `IDPG_Privacy` | `idpg-core.php` ~строка 101 и конец соответствующих файлов |
| Статический `::init()` | `IDPG_Organizer_URL`, `IDPG_City_URL`, `IDPG_Favorites`, `IDPG_Organizer_Scope` | `idpg_core_init()` либо конец файла. ~~`IDPG_Organizer_Page`~~ удалён DEV-141 вместе с анкетой |
| `register_widget()` на `widgets_init` | `IDPG_City_Selector_Widget`, `IDPG_Event_Calendar_Widget`, `IDPG_Expected_Events_Widget` | `idpg_core_register_plugin_widgets()` |
| Не требуется | `IDPG_Search`, `IDPG_Template_Helper` (статические helper-ы), `IDPG_CLI` (только под `WP_CLI`), `IDPG_Auth_Rate_Limiter`, `IDPG_GeoService` (ленивая инициализация) | — |

**Контроль.** `plugins/idpg-core/tests/test-class-init.php` разбирает все
файлы `includes/`, строит карту «файл → класс» и требует, чтобы каждый класс
был описан в одной из таблиц. Неописанный класс валит набор, поэтому новый
файл нельзя добавить, не решив, как он инициализируется. Дополнительно
проверяется, что каждый файл подключён в `idpg-core.php`, а конструктор
каждого синглтона действительно отработал (рефлексия по приватному полю
`$instance`).

Известная находка: `IDPG_GeoService` инициализируется лениво и не имеет
потребителей в production-коде — только тесты. Кандидат на удаление.

---

## 6. ЛК организатора: фактическое состояние (сверено 28.09.2026, уточнено 30.09.2026)

> **Источник:** сверка по текущему дереву `idpg-core` 1.1.2 / тема 1.0.78
> (проверка `require`, `enqueue`, наличия REST-маршрутов и методов), а не
> прежний отчёт DEV-00A от 19.09.2026. **Прежний вывод устарел:** на тот момент
> обвязка ЛК (DEV-37/38/39) ещё не была применена, и документ утверждал, что
> роутер, меню и ассеты «не подключены». Сейчас это не так — см. §6.1.
> Историческая версия сохранена в `docs/README_DEV-00A.md`.
>
> **Уточнение от 30.09.2026 (DEV-130).** Проверка трёх «P0»-пунктов в рантайме
> подтвердила работу меню и ассетов (DEV-38) и лимита сброса пароля по IP
> (DEV-93), но выявила **две регрессии, внесённые восстановлением DEV-102**:
> страница `/organizer/` больше не создаётся (хук `after_switch_theme` вместо
> `after_setup_theme`) и исключение из sitemap Yoast не срабатывает (опечатка
> в имени фильтра). Подробности — `TZ_BACKLOG.md` §12.
>
> **Регрессии закрыты 30.09.2026 (DEV-131).** Обе правки — по одной строке в
> `themes/idpg_core/functions.php`: хук `after_setup_theme` (~строка 464) и фильтр
> `wpseo_exclude_from_sitemap_by_post_ids` (~строка 522). Проверено в рантайме
> (страница создаётся и самовосстанавливается, фильтр отдаёт ID) и юнит-тестами
> `test-organizer-page-creation.php` (12 тестов, 23 утверждения — зелёные).
> Карточка и разбор — `TZ_BACKLOG.md` DEV-102 (регрессии) и §13 (починка).
>
> **Ревизия номеров строк и счётчиков 30.09.2026 (DEV-142).** Раздел пересверен
> по коду: все ссылки вида `файл:NNN` заменены на `~строка N` (они смещаются при
> правках выше по файлу — в `functions.php` темы сдвиг составил +9 после
> DEV-131/DEV-138, в `idpg-core.php` −3 после DEV-141), счётчик функций меню
> исправлен 7 → 8. Правило — `TZ_BACKLOG.md` §15.

### 6.1 Что фактически работает

| Компонент | Состояние |
|---|---|
| Роутер `/organizer/` и `/organizer/{section}/` (`IDPG_Organizer_URL`) | **Подключён и работает**: `require_once` в `idpg-core.php` ~строка 84, `IDPG_Organizer_URL::init()` ~строка 157. Rewrite-правила, query vars, `template_include`, принудительный flush по `REWRITE_VERSION` (сейчас **4** — поднят в DEV-141, чтобы сбросить правило удалённой анкеты). **DEV-37** |
| Меню кабинета (`inc/organizer-menu.php`, **8 функций** `idpg_organizer_*` + `idpg_core_is_organizer_cabinet()`) | **Подключено**: `require_once` в `functions.php` ~строка 413. Даёт `idpg_organizer_menu()`, `idpg_organizer_section_url()`, `idpg_core_is_organizer_cabinet()`, скрытие баннера над подвалом. **DEV-38** |
| Ассеты кабинета | **Подключены**: `assets/css/organizer.css` (`functions.php` ~строка 224), `organizer.js` (~225), `organizer-event-form.js` (~226), `organizer-venue-form.js` (~227) — все только на `/organizer/*`. **DEV-38, DEV-98** |
| Страница WP `/organizer/` | **Работает (регрессии закрыты DEV-131, 30.09.2026).** `idpg_core_create_organizer_page()` висит на `after_setup_theme` (`functions.php` ~строка 464) — хук срабатывает на каждом запросе, поэтому удалённая страница самовосстанавливается, а `idpg_core_get_organizer_page_id()` восстанавливает опцию `_idpg_organizer_page_id` по слагу. Первоисточник — DEV-39; DEV-102 откатил хук на `after_switch_theme` (срабатывает раз за жизнь темы), из-за чего у переключённой темы страницы не было, а `get_organizer_page_id()` отдавал 0. Вторая регрессия того же коммита: исключение из sitemap висело на `wpseo_exclude_from_sitemap_by_posts_ids`, тогда как Yoast вызывает `wpseo_exclude_from_sitemap_by_post_ids` (единственное `post`) — фильтр (`functions.php` ~строка 522) был мёртв. Обе правки — по одной строке, покрыты тестами `test-organizer-page-creation.php`. **DEV-39, DEV-131** (карточка — DEV-102, разбор — §13) |
| `page-organizer.php` + `template-parts/organizer/section-{dashboard,events,venues,profile}.php` | Работают: обслуживаются роутером, разделы рендерятся серверно, `organizer.js` переключает видимость и адрес без перезагрузки. **DEV-37/38, DEV-98** |
| Проверка доступа `get_access_decision()` | Гость → `/login/`, нет роли → `403`, организатор и выше → кабинет. Отдельного состояния «pending» нет. **DEV-72** |
| `guard_cabinet_redirect()` | Работает (`class-organizer-url.php` ~строка 308): не даёт зациклиться при попытке зайти в кабинет без прав. **DEV-63** |
| Редирект в кабинет после входа и после подтверждения email | Работает: `after_login_url()` + `idpg_organizer_after_login_url()`, `IDPG_Auth::redirect_organizer_to_cabinet()`, подтверждение по ссылке из письма. **DEV-37, DEV-67** |
| Подтверждение по email (`IDPG_Organizer_Verify`) | Работает — роль `event_organizer` выдаётся только после перехода по ссылке; флаг ожидания `idpg_org_pending`. **DEV-68** |
| Редактирование профиля в `/organizer/profile/` | **Работает**: форма + `GET|PUT /idpg/v1/organizer/profile`, с JavaScript и без него. **DEV-73** |
| Платный показ в баннере на главной | **Работает**: заявка организатора `_idpg_banner_request` → подтверждение админом `_idpg_banner_approved` → событие попадает в выборку баннера (`class-current-event-banner.php:707-713`), колонка статуса в списке событий (`class-meta.php`, методы `add_banner_column` ~1096 и `render_banner_column` ~1108) |
| Модерация по платности (`class-event-moderation.php`) | Подключён (`idpg-core.php` ~строка 154): орг. не публикует (→ `pending`), бесплатные авто-публикуются, платные с неполными билетными данными → `draft` + уведомления |
| Валюта события у организатора | **Только рубли (решение владельца, DEV-95).** Поле `_idpg_currency` остаётся в модели Carbon для администратора (select `RUB|USD|EUR`, контейнер «Билеты и цены»), но организатор его не выбирает: в форме `/organizer/event/new/` поля нет, параметр `currency` не объявлен в схеме REST `/idpg/v1/organizer/events` и потому игнорируется, а при сохранении `_idpg_currency` принудительно ставится в `RUB` (только если поле ещё пустое — валюту, выставленную админом, правка организатора не затирает). Карточка выводит `₽`. Пустое значение в `_idpg_currency` (события, созданные до DEV-95 или в обход организаторского пути — импорт, CLI) читается как `RUB` и в метаданных события (`IDPG_Template_Helper::get_event_meta()`), и в `idpg_core_currency_symbol()`, поэтому цена всегда выводится с символом; явно выставленная администратором валюта не затирается |
| Перечисления в маршруте события | **Проверяются сервером (DEV-99).** Все девять перечислений `GET|POST /idpg/v1/organizer/events` — `all_day`, `is_free`, `ticket_type`, `ticket_status`, `banner_request`, `is_online`, `venue_type`, `orderby`, `order` — имеют `validate_callback`, без чего `enum` был декорацией: `is_free=hack` проходил с 201 и ложился в базу. Набор значений повторяет то, что способна отправить форма: у `<select>` (`ticket_type`, `ticket_status`, `venue_type`) в перечисление входит пустая строка — это опция «— Не нужны —» / «— Не выбран —», без неё сохранение новой формы отвергалось с 400; у радио (`all_day`, `is_free`) её нет, скрипт подставляет `no` |
| Форма создания/правки события (G5) | **Работает**: `page-organizer-event.php` + `organizer-event-form.js` (3 шага, прогресс, сеансы, отправка на модерацию, удаление), маршруты `/organizer/event/new/` и `/organizer/event/{id}/`. **DEV-94** |
| Синхронизация сеансов (Carbon ↔ зеркало) | **Двусторонняя (DEV-48)**. Сеансы — complex-поле CF `idpg_sessions` (подполя `time`/`price`/`url`), CF раскладывает его по служебным мета-ключам. Организатор пишет через `carbon_set_post_meta()`, зеркало `_idpg_sessions_json` (его читают шаблоны) собирает `IDPG_Meta::mirror_sessions()`. Поэтому сеансы видны обеим сторонам: заведённые в кабинете — в wp-admin, заведённые админом — в кабинете. Пустое значение удаляет зеркало, а не оставляет заглушку `...\|\|\|_empty` |
| Разделы «Дашборд» и «Мои события» (G6) | **Работают**: наполняются из `GET /organizer/stats` и `GET /organizer/events`, фильтр по статусам со счётчиками, таблица с постерами, пагинация; состояние в адресе (`?status=&p=`). **DEV-94** |
| Площадки организатора (раздел + форма) | **Работает (DEV-98)**: раздел `/organizer/venues/` (`section-venues.php`) наполняется из `GET /idpg/v1/organizer/venues`; отдельная форма `/organizer/venue/new/` и `/organizer/venue/{id}/` (`page-organizer-venue.php` + `organizer-venue-form.js`) с полями контейнера «Данные площадки», выбором постера через `wp.media` и работой без JavaScript (обычный POST + nonce + Post/Redirect/Get). Обязательны `title`, `city`, `venue_address` — проверяются и при создании, и при правке. Чужие опубликованные площадки видны в списке, но без ссылки на правку. `DELETE` площадки, на которую ссылается событие, отвечает `409 idpg_org_venue_in_use`. Оформление: контролы и кнопки общие с формой события (`create-event__*`), собственные стили в `pages/_venue-form.scss` — пометка «общая», подсказка о загрузке файлов, опасная кнопка удаления, состояния раздела |
| Изоляция контента (`IDPG_Organizer_Scope`, `IDPG_City_Filter`) | Работает — организатор видит только свои медиа/события |
| Анкета `/organizer/complete/` | **Удалена полностью.** DEV-72 снял её продуктово (данные организации переехали в `/organizer/profile/`), но удалил только REST-маршрут `/auth/organizer-complete`; класс `IDPG_Organizer_Page`, шаблон, скрипт и rewrite-правило остались подключёнными и висели мёртвым грузом. **DEV-141 (30.09.2026) удалил остатки.** Организатор после подтверждения email попадает сразу в `/organizer/`. URL `/organizer/complete/` отдаёт **404** |

### 6.2 Что есть, но НЕ подключено (мёртвый код в рантайме)

| Файл / класс | Назначение | Почему не работает |
|---|---|---|
| ~~`plugins/idpg-core/includes/class-privacy.php`~~ | ~~152-ФЗ: согласие, cookie-баннер, выгрузка и удаление данных~~ | **Подключён** — G8 (28.09.2026). `require_once` в `idpg-core.php`, согласие обязательно при регистрации, тулзы субъекта на `/privacy/` |
| ~~`class-organizer-url.php`, `inc/organizer-menu.php`, `assets/js/organizer.js`, `assets/css/organizer.css`, `page-organizer.php`, страница `/organizer/`~~ | ~~Обвязка ЛК~~ | **Подключены** — DEV-37/38/39. Больше не мёртвый код |

Других неподключённых модулей кабинета в дереве не осталось.

> **Проверка 30.09.2026 (DEV-141).** Последним «подключённым, но мёртвым» модулем
> кабинета была анкета: `IDPG_Organizer_Page` стоял в `require_once` и `init()`,
> но ни флаг `_idpg_org_pending` (никем не записывался), ни REST-маршрут
> `/auth/organizer-complete` (отсутствовал) не давали ей работать — форма не
> показывалась, а её отправка ушла бы в 404. Класс, шаблон, скрипт и rewrite
> удалены. Теперь мёртвого кода в кабинете нет.

### 6.2.1 Персональные данные (152-ФЗ)

Подключено в G8, 28.09.2026. Модуль `IDPG_Privacy` (`includes/class-privacy.php`):

- **Согласие на обработку ПДн** — отдельный обязательный чекбокс в форме регистрации
  организатора (не путать с согласием с офертой). Проверяется на сервере: без него
  `POST /idpg/v1/auth/register` отвечает `400 pdn_consent_required`. Факт согласия
  пишется в user meta `idpg_pdn_consent` (кто, когда, редакция документов, хэш IP).
  Порядок проверок: сначала `type` (403 для не-организатора), затем согласие —
  иначе не-организатору выдавался бы факт существования регистрации организатора.
  Отказ по согласию не расходует лимит попыток регистрации.
- **Cookie-баннер** — выбор «все» / «только необходимые», хранится в cookie
  `idpg_cookie_consent` на год. Работает без JavaScript (POST + nonce) и с ним.
- **Права субъекта** — блок «Ваши права» на странице `/privacy/`: самостоятельная
  выгрузка своих данных в JSON и запрос на удаление. Журнал действий администратора —
  в опции `idpg_privacy_log`. Админ-страница: «Иду по городу → Конфиденциальность`.

Не сделано: отзыв согласия пользователем в интерфейсе (бэкенд есть — `has_consent()`).

### 6.2.2 События организатора (REST, DEV-42)

`plugins/idpg-core/includes/class-rest-organizer.php` (`IDPG_REST_Organizer`).
Интерфейс над маршрутами есть с DEV-94 (форма события, живые разделы кабинета;
бывший разрыв G5 закрыт) — см. §6.1.

| Маршрут | Назначение |
|---|---|
| `GET /organizer/events` | Список своих событий. Фильтры: `status` (draft\|pending\|publish\|rejected), `page`, `per_page` (макс. 50), `search`, `orderby` (date\|title\|views), `order`. Ответ: `{ items, total, pages }` |
| `GET /organizer/events/{id}` | Одно событие, ответ `{ success, data, message }` |
| `POST /organizer/events` | Создать черновик, ответ `201` |
| `PUT /organizer/events/{id}` | Обновить. Только `draft` / `rejected` |
| `POST /organizer/events/{id}/submit` | Отправить на модерацию: `draft` / `rejected` → `pending` |
| `DELETE /organizer/events/{id}` | Удалить. Только `draft` / `rejected` |
| `GET /organizer/stats` | Счётчики по статусам + сумма просмотров и кликов |

Правила доступа:

- **Скоуп — только свои события.** Запрос списка всегда ограничен `author`,
  чужое событие по прямому ID → `403` (не 404, чтобы не выдавать факт
  существования чужой записи). Администратор и редактор
  (`edit_others_events`) видят все.
- **Nonce `wp_rest` обязателен** — проверяется явно в `permission_callback`,
  как в `IDPG_REST_Api::can_manage_events()`. REST сам проверяет nonce только
  при cookie-аутентификации, а с Bearer-токеном запрос с чужим заголовком
  прошёл бы.
- **Жизненный цикл.** `pending` править и удалять нельзя → `409`
  (конфликт состояний), иначе организатор обошёл бы модерацию, изменив
  событие прямо во время проверки. `publish` → `403`. Остальное не трогаем.
- Отправка на модерацию требует название, дату начала и город, иначе `400`
  с перечнем недостающих полей. Хук `idpg_organizer_event_submitted`.

Модерация не дублируется: правила бесплатного/платного применяет
`IDPG_Event_Moderation`. Его штатный триггер — Carbon Fields, которого на
REST-пути нет, поэтому `submit` вызывает
`IDPG_Event_Moderation::schedule_price_moderation()` явно. Итог: бесплатное
публикуется сразу, платное с полными данными идёт на модерацию, платное без
билетных данных возвращается в черновик.

**Статус `rejected` зарегистрирован** в этом классе (`protected`). До DEV-42
такого статуса в проекте не было вовсе: модерация уводила событие в `pending`
и на этом останавливалась. Переходы `rejected → draft / pending` работают,
и статус выставляется — см. §6.2.3.

### 6.2.3 Отклонение события модератором (DEV-46)

`plugins/idpg-core/includes/class-event-rejection.php` (`IDPG_Event_Rejection`).
До DEV-46 статус `rejected` был мёртвым: зарегистрирован как перечисление,
но выставить его было нечем.

- **Row action «Отклонить»** в списке событий (`edit.php?post_type=event`) —
  только для событий в статусе `pending` и только при наличии
  `edit_others_events` (администратор, редактор).
- **Форма отклонения** — подстраница «Иду по городу → Отклонить событие»:
  предпросмотр, необязательное поле «Причина отклонения», подтверждение.
- **Действие** — статус → `rejected`, причина в
  `_idpg_rejection_reason`, дата в `_idpg_rejected_at`, хук
  `idpg_event_rejected( $post_id, $reason, $actor )`.
- **Письмо автору** — обязательно, причина попадает в текст, если указана.
  Повторное отклонение уже отклонённого события — ошибка, а не новое письмо
  (защита от двойного клика).
- Безопасность: права и nonce `idpg_reject_event` проверяются в обработчике
  `admin_post.php` до отклонения; прямая отправка в обработчик без nonce
  ничего не делает.

Одобрение не менялось: его роль выполняет обычная публикация записи, письмо
об одобрении шлёт `IDPG_Event_Moderation`.

Модуль намеренно отдельный, а не дописан в `IDPG_Event_Moderation`: модерация
по платности и отклонение редактором — разные решения с разными последствиями
для события.

Организатор после отклонения видит событие в разделе «Мои события» с фильтром
`rejected`, может его править и отправить на модерацию повторно
(проверено `tests/test-event-rejection.php`).

### 6.3 Чего нет вовсе

| Чего нет | Задача |
|---|---|
| **UI создания и редактирования события** — страницы `/organizer/event/new/`, `/organizer/event/{id}/`, шаблон формы, скрипт `organizer-event-form.js`. REST-слой под ними готов (DEV-42), форма к нему не подключена | DEV-43, DEV-44 |
| **Живые разделы «Дашборд» и «Мои события»** — оба по-прежнему заглушки «Раздел в разработке». Не читают ни `/organizer/events`, ни `/organizer/stats`, хотя оба маршрута готовы | DEV-45 |
| **Оплата за публикацию события** (не за показ в баннере) — продукт не определён, в коде нет. Платный показ в баннере работает | G10, нужно решение владельца |
| **Выбор аватара** в профиле — UI медиатеки (wp.media) | G11 |

**Что закрыто и больше не является разрывом:**

- ~~REST-маршруты ЛК `/organizer/events*`, `/organizer/stats`~~ — **DEV-42** (§6.2.2).
- ~~Кнопка «Отклонить» и уведомление автору~~ — **DEV-46** (§6.2.3). Статус
  `rejected` выставляется, причина сохраняется, письмо уходит автору.
- ~~Анкета `/organizer/complete/`~~ — удалена **DEV-72**.
- ~~REST `POST /auth/organizer-complete`~~ — удалён **DEV-72**.

### 6.4 Gap-лист

| # | Gap | Статус | Приоритет | Куда ведёт |
|---|---|---|---|---|
| ~~G1~~ | Роутер `class-organizer-url.php` не подключён → `/organizer/` мёртв | **Закрыто DEV-37** | — | — |
| ~~G2~~ | `inc/organizer-menu.php` не подключён → `idpg_organizer_*()` не определены | **Закрыто DEV-38** | — | — |
| ~~G3~~ | `organizer.js` / `organizer.css` не подключаются | **Закрыто DEV-38** | — | — |
| ~~G4~~ | REST `POST /auth/organizer-complete` не зарегистрирован | **Закрыто DEV-72** (слой анкеты удалён целиком) | — | — |
| ~~G5~~ | **UI создания/правки события.** REST готов (**DEV-42**, §6.2.2), форма — нет: страницы `/organizer/event/new/`, `/organizer/event/{id}/`, шаблон, `organizer-event-form.js` | **Закрыто 29.09.2026**: маршруты + шаблон `page-organizer-event.php` + `organizer-event-form.js` (3 шага, прогресс, сеансы, submit/delete). Тесты: `test-organizer-page.php`, `test-organizer-menu.php`, `tests/js-smoke/organizer-js-smoke.js` | — | — |
| ~~G6~~ | **Разделы «Дашборд» и «Мои события»** — заглушки. `/organizer/stats` и `/organizer/events` готовы (**DEV-42**), но разделы их не потребляют | **Закрыто 29.09.2026**: `section-dashboard.php` (4 карточки из stats) и `section-events.php` (фильтр по статусам, таблица, пагинация, постеры) наполняются через `organizer.js`. Тесты: `tests/js-smoke/organizer-js-smoke.js` | — | — |
| ~~G7~~ | Рассинхрон флагов `idpg_org_pending` / `_idpg_org_pending` | **Закрыто DEV-72 (частично) и DEV-141 (полностью).** DEV-72 снял анкету продуктово, но удалил только REST-маршрут — чтение `_idpg_org_pending` осталось в классе и шаблоне. **DEV-141 удалил остатки**: класса, шаблона, скрипта и rewrite-правила больше нет, `_idpg_org_pending` не читается нигде (проверено grep'ом). Остался единственный флаг — `idpg_org_pending` (`IDPG_Organizer_Verify::META_PENDING`), подтверждение email | — | — |
| ~~G8~~ | ~~`class-privacy.php` (152-ФЗ) — код есть, не подключён~~ | **Закрыто (28.09.2026)**: модуль подключён, согласие на обработку ПДн обязательно при регистрации (REST + PHP-fallback), cookie-баннер работает, блок прав субъекта (выгрузка JSON + запрос на удаление) выводится на `/privacy/`. Тесты: `tests/test-privacy.php` | — | — |
| ~~G9~~ | `is_pending()` требует роль `event_organizer` | **Закрыто DEV-72**: методов `is_pending()` / `is_pending_organizer()` больше нет | — | — |
| G10 | Оплата за **публикацию** события (не за баннер) как продукт не определена. Реализована только модерация по платности; платный показ в баннере (`_idpg_banner_request` → `_idpg_banner_approved`) работает | **Отложено решением владельца 29.09.2026**: платёжного провайдера в проекте нет, без него задача невыполнима | P2 | — |
| ~~G11~~ | ~~Выбор аватара в профиле: UI медиатеки (wp.media) не сделан, `avatar_id` заполняется только через API~~ | **Закрыто решением владельца 29.09.2026 — снято как неактуальное**: аватар существовал только внутри личного кабинета `/lk/`, который по DEV-14 не возвращается. REST `/user/avatar` и UI профиля удалены вместе с ЛК в 1.1.0 | — | — |
| G12 | `default_role = subscriber` в `wp_options`: роль `subscriber` не должна быть ролью пользователя сайта | **Закрыто решением владельца 28.09.2026 — оставлено `subscriber`**. Через `update_option` задача невыполнима: ядро в `sanitize_option()` (`wp-includes/formatting.php` ~строки 5155-5157, ветка `case 'default_role'`) подставляет `subscriber`, пока такая роль существует. Обход возможен только прямой записью в `wp_options` в обход валидации ядра. Регистрация организатора от `default_role` не зависит (явный `set_role( 'subscriber' )`) — см. `tests/test-default-role.php` | — | — (решение принято) |
| ~~G13~~ | ~~Кнопка модератора «Отклонить» и уведомление автору; статус `rejected` был нечем выставить~~ | **Закрыто DEV-46** (§6.2.3): row action + форма с причиной, хук `idpg_event_rejected`, письмо автору. Тесты: `tests/test-event-rejection.php` | — | — |

### 6.5 Навигация по DEV-33…36 (TZ_BACKLOG)

Заявленные ранее «DEV-33…36» для ЛК-событий в актуальной нумерации `TZ_BACKLOG.md` частично заняты
другими задачами. Соответствие по факту кода:

| Заявленная тема | Реальная позиция в TZ_BACKLOG | Статус по факту |
|---|---|---|
| «DEV-33-34: каркас ЛК» | DEV-33 = rate limiter (занят); DEV-34 = каркас ЛК | Каркас собран и подключён (DEV-37/38/39), профиль редактируется (DEV-73), REST событий готов (DEV-42), отклонение модератором работает (DEV-46). Открытые части кабинета — G5, G6 |
| «DEV-35: список/создание событий в ЛК» | Свободного DEV-35 нет | Открытая работа: UI над готовым API — G5 (форма, DEV-43/44), G6 (живые разделы, DEV-45) |
| ~~«DEV-36: доуполнение анкеты»~~ | Совпадало с DEV-01 (P0) | **Закрыто DEV-72**: анкета удалена целиком |
