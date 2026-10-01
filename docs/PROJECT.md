# PROJECT — что реализовано + что делать

**Источник истины: код. Числа проверять по коду.**
Если карточка или таблица расходится с реальным кодом — прав код, а не документ.

Собрано из двух документов (DEV-166):

| Раздел | Был | Содержание |
|---|---|---|
| 1. Что реализовано | `docs/FEATURES.md` | инвентарь функций по пакетам, чек-листы, логика |
| 2. Что делать | `docs/TZ_BACKLOG.md` | все карточки `DEV-*`, приоритеты, журналы, аудиты |

Содержимое перенесено **целиком, без сокращений**: все карточки `DEV-*`, таблицы,
ссылки и статусы на месте. Изменены только уровни заголовков (сдвиг на один вниз,
чтобы встроиться в разделы 1 и 2) и пути ссылок на перемещённые документы.

## Оглавление

- **Раздел 1. Что реализовано** — бывший `docs/FEATURES.md`
- **Раздел 2. Что делать** — бывший `docs/TZ_BACKLOG.md`
- `docs/PRODUCTION.md` — чек-лист прода (отдельный активный файл)
- `.workbuddy-ai/MEMORY.md` — память проекта, грабли, рецепты
- `docs/archive/` — старые документы (неактивны, не обновляются)
- `docs/carbon-fields-export.json` — машинный экспорт полей Carbon Fields

---

# Раздел 1. Что реализовано

> Источник: бывший `docs/FEATURES.md`. Обновлён 30.09.2026 (DEV-130 / DEV-131).
> Номера версий в заголовке ниже устаревают — сверять с `IDPG_CORE_VERSION` и
> `Version:` в `themes/idpg_core/style.css`.


Инвентаризация по фактическому коду (не по документации). Дата: 15.09.2026, дополнено (аудит
ЛК организатора DEV-00A) 19.09.2026, уточнено (DEV-130: число классов, регрессии DEV-102;
DEV-131: регрессии закрыты) 30.09.2026. Проверено: `plugins/idpg-core` (v1.1.2),
`plugins/idpg-auth` (v1.0.0), `themes/idpg_core` (v1.0.75), `themes/idpg_core-child` (v1.0.0),
`docs/`, `deploy.sh`, `DOCUMENTATION.md`.

> ⚠️ **Внимание (19.09.2026).** Разделы, описывающие личный кабинет организатора и 152-ФЗ
> (п. 1.4 «кабинет», п. 1.4а, п. 3.2 `organizer-menu.php`, п. 3.4 ассеты кабинета), фиксируют
> **намеренное/документированное состояние** (версии 1.1.7…1.1.8 / 1.0.79…1.0.81 по
> `docs/PROJECT.md (раздел 2)`). В **текущем рабочем дереве** 1.1.2 / 1.0.75 эти файлы есть, но **не подключены**.
> Фактическое состояние — см. **§ 6 «ЛК организатора: фактическое состояние»** и
> `docs/archive/docs/archive/README_DEV-00A.md`.

---

### 1. Плагин IDPG Core (ядро) — 39 классов

> **Счёт классов уточнён 30.09.2026 (DEV-130).** В шапке стояло «33 класса» —
> это число соответствовало дереву до DEV-97/102. Фактически `idpg-core.php`
> подключает **39** файлов классов (проверено: 39 строк `require_once IDPG_CORE_DIR`
> в блоке подключений). Пять классов (`organizer-url`, `rest-organizer`,
> `event-rejection`, `promotion`, `privacy`) были подключены, но не
> инициализировались — их `::instance()` добавлены в `idpg_core_init()`
> восстановлением DEV-102; регрессия закрыта тестом `test-class-init.php` (DEV-100).
> Карточка восстановления — `docs/PROJECT.md (раздел 2)` DEV-102, порядок — `docs/PRODUCTION.md` §12.

#### 1.1 Контент-модель

| Что | Реализация |
|---|---|
| CPT `event` (афиша, слаг `/afisha/`) | `class-cpt.php` — title, editor, thumbnail |
| CPT `venue` (площадка, `/venues/`) | `class-cpt.php` |
| Таксономия `city` | иерархическая: регион → город → район; привязана к event + venue |
| Таксономия `event_category` | иерархическая, слаг `event-category` |
| Таксономия `age_rating` | неиерархическая, слаг `age-rating` |
| Регистрация при активации | CPT + таксономии + роли + cron + миграции + flush rewrite |
| При деактивации | удаление ролей, снятие cron, flush rewrite |

#### 1.2 Мета-поля (Carbon Fields + `register_post_meta`, префикс `_idpg_`)

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

#### 1.3 Многогородская механика

- Cookie `idpg_selected_city` (30 дней), автоопределение города по IP (`ip-api.com` → `ipapi.co`,
  кэш на сутки, переключатель сервиса в виджете, работа по HTTPS с fallback на HTTP).
- Город в URL: `/afisha/{city}/`, `/afisha/{city}/events/{slug}/`, `/venues/{city}/`;
  `/gorod/{slug}/` → 301, `/afisha/` и `/venues/` → 301 на версию с городом, `?city=` → 301.
  Rewrite строится из реальных слагов городов, кэш слагов сбрасывается при сохранении термина.
- Виджет «Выбор города» (tom-select, иерархия область → город → район) + мобильная модалка в шапке.
- REST `POST /idpg/v1/city/select` (nonce `wp_rest`); admin-ajax `idpg_set_city` оставлен fallback.
- `class-geo-service.php` — фасад гео-логики; `class-city-url.php` — маршрутизация.

#### 1.4 Авторизация и аккаунты

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

#### 1.4а Персональные данные (152-ФЗ)

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

#### 1.5 Роли, права, изоляция контента

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

#### 1.6 REST API (23 маршрута, namespace `idpg/v1`)

**Публичные:** `GET /events` (city, category, date_from, date_to, search, page, per_page),
`GET /venues`, `GET /venues/{id}`, `GET /cities`, `GET /categories`, `GET /age-ratings`,
`GET /event-dates` (год/месяц — для календаря), `GET /search/live`, `GET /search/popular`,
`POST /subscribe` (подписка на дайджест), `POST /city/select`.

**Избранное (3):** `GET /favorites`, `POST /favorites/add`, `POST /favorites/remove`.

**Под правами (nonce + `can_manage_events`):** `POST /venues/create`, `PUT /venues/{id}`.

**Авторизация (6):** см. п. 1.4.

У каждого маршрута — `permission_callback`; у параметров — `sanitize_callback`/`type`/`enum`.

#### 1.7 Пользовательские функции

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

#### 1.8 Интеграции и админка

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

#### 1.9 Инженерная обвязка

- `uninstall.php` — очистка опций, транзиентов, user meta, cron (данные событий сохраняются,
  полная зачистка — константой `IDPG_CORE_UNINSTALL_WIPE_DATA`).
- i18n: `languages/idpg-core.pot`, генератор `bin/make-pot.js` (без внешних зависимостей).
- Тесты: PHPUnit — **40 файлов** `tests/test-*.php` (на 30.09.2026; список растёт),
  `phpunit.xml.dist`, `bin/install-wp-tests.*`.
  Итого прогон на 30.09.2026 (после DEV-17/19/22/28 и правки DEV-19 на дубли id): idpg-core —
  **655 тестов / 1888 утверждений**, падений **0**. Последние приросты: DEV-12 (10 тестов реквизитов
  и верификации, 645 → 655); DEV-19, устранение дублей
  DOM-id в блоке подтверждения (2 теста, 643 → 645); DEV-28 (30.09.2026, правка документации, тестов
  не добавлял); DEV-17 (5 тестов на nonce-транспорт учёта клика, 638/1840); DEV-19 (20 тестов
  подтверждения города, 618 → 638); DEV-22 (18 тестов,
  `IDPG_Helpers`); DEV-18 (15 тестов `Test_Ticket_Sync`); DEV-33 (`Test_Register_Organizer_Only` —
  18 тестов / 86 утверждений); до них DEV-15 (guard на существование файла логотипа в письмах,
  10 новых тестов в `tests/test-email-logo.php`); DEV-151 (nonce в форме пароля maintenance,
  10 тестов); DEV-150 (honeypot в регистрации, 8 тестов); DEV-149 (nonce на `/auth/logout`, 6 тестов, 539/1612);
  DEV-147 — 533 теста / 1596 утверждений; DEV-143: партиалы на токенах, CHANGED 88 → 0.
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
  `plugins/idpg-auth` и теме `idpg_core`. Легаси-нарушения зафиксированы в baseline —
  `.phpcs.xml.dist-baseline.neon` в каждом компоненте (формат NEON, плагин `forrest79/phpcs-ignores`;
  имя файла обязано начинаться с имени ruleset-файла — иначе пакет baseline не найдёт, DEV-183);
  подключается через `--bootstrap`,
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

### 2. Плагин IDPG Auth (v1.0.0) — 11 классов

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

### 3. Тема idpg_core (v1.0.73) + дочерняя тема

#### 3.1 Шаблоны (25 PHP-файлов + 7 template-parts)

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

#### 3.2 Функциональность темы (`inc/`)

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

#### 3.3 Фронтенд-логика

- Фильтры афиши через `pre_get_posts`: `?date=today|tomorrow|weekend|YYYY-MM-DD`, `?is_free=1`,
  `?city=<term_id>`; расчёт ближайших выходных.
- Шапка: модалка выбора города (иерархический список), live-поиск с выпадающим списком
  (REST `/search/live`, `/search/popular`), меню, ссылки в зависимости от роли.
- Кастомный логотип: `idpg_render_logo()`, генерация CSS логотипа, превью в кастомайзере.
- Автосоздание при активации: страницы `/login/`, `/reset-password/`, `/terms/`, `/privacy/`,
  `/offer/` (с дефолтными текстами) и дефолтное меню.
- Правовые документы: `idpg_core_legal_document()` / `idpg_core_legal_render()`.
- `theme.json`, локальный шрифт Gilroy (`@font-face`), свои размеры изображений, версия ассетов.

#### 3.4 Ассеты

- **JS:** `auth.js`, `live-search.js`, `navigation.js`, `canvas-fix.js`, `subscribe.js`,
  `organizer.js`, `widget-ad.js` + `widget-ad-front.js`, `customize-preview.js`,
  `customize-sidebar-sticky.js`, `social-icons.js`, `pikaday.js` + `pikaday.range.js` +
  `pikaday-locale-ru.js`; vendor: Toastify, xlsx.full.min.js.
- **CSS/SCSS:** `style.css` (исходник истины, см. 3.4.1), `templates.css`, `editor-style.css`, `subscribe.css`,
  `organizer.css`, `widget-areas-admin.css`, SCSS-исходники (`base/`, `components/`, `pages/`, `responsive.scss`).

#### 3.4.1 Сборка CSS (DEV-87)

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
(`docs/PROJECT.md (раздел 2)` §14).

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

#### 3.5 Дочерняя тема `idpg_core-child` (v1.0.0)

- Подключение стилей родителя, RTL-поддержка.
- Принудительное отключение регистрации пользователей на уровне опции
  (`option_users_can_register`), даже если галочка в Настройки → Общие включена заново.

---

### 4. Инфраструктура и документация

- `docs/PRODUCTION.md` — чек-лист запуска на проде (wp-config, URL, uploads, cron, SMTP,
  соц-авторизация, реклама, безопасность).
- `docs/archive/docs/archive/PLUGIN_TZ.md` — техническое задание портала (v1.0).
- `docs/archive/docs/archive/README_new.md` — архивная расширенная документация темы; `docs/README.md` — индекс.
- `DOCUMENTATION.md` в корне WP — разбор структуры, функций, эндпоинтов, безопасности, TODO.
- `deploy.sh` — деплой через tar+ssh на Hostland (без БД и wp-config).
- `wp-config.php`: `IDPG_DEFAULT_CITY_ID` (Ижевск), `IDPG_TICKET_API_URL` (заглушка).
- `llms.txt` — генерируется Yoast SEO.
- Сторонние плагины в сборке: Yoast SEO, WP Mail SMTP, All-In-One WP Security, WpVivid Backup, Clearfy Pro.

---

### 5. Расхождения: что НЕ реализовано или устарело в документации

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

### 5.1 Инициализация классов плагина (DEV-100)

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

### 6. ЛК организатора: фактическое состояние (сверено 28.09.2026, уточнено 30.09.2026)

> **Источник:** сверка по текущему дереву `idpg-core` 1.1.2 / тема 1.0.78
> (проверка `require`, `enqueue`, наличия REST-маршрутов и методов), а не
> прежний отчёт DEV-00A от 19.09.2026. **Прежний вывод устарел:** на тот момент
> обвязка ЛК (DEV-37/38/39) ещё не была применена, и документ утверждал, что
> роутер, меню и ассеты «не подключены». Сейчас это не так — см. §6.1.
> Историческая версия сохранена в `docs/archive/docs/archive/README_DEV-00A.md`.
>
> **Уточнение от 30.09.2026 (DEV-130).** Проверка трёх «P0»-пунктов в рантайме
> подтвердила работу меню и ассетов (DEV-38) и лимита сброса пароля по IP
> (DEV-93), но выявила **две регрессии, внесённые восстановлением DEV-102**:
> страница `/organizer/` больше не создаётся (хук `after_switch_theme` вместо
> `after_setup_theme`) и исключение из sitemap Yoast не срабатывает (опечатка
> в имени фильтра). Подробности — `docs/PROJECT.md (раздел 2)` §12.
>
> **Регрессии закрыты 30.09.2026 (DEV-131).** Обе правки — по одной строке в
> `themes/idpg_core/functions.php`: хук `after_setup_theme` (~строка 464) и фильтр
> `wpseo_exclude_from_sitemap_by_post_ids` (~строка 522). Проверено в рантайме
> (страница создаётся и самовосстанавливается, фильтр отдаёт ID) и юнит-тестами
> `test-organizer-page-creation.php` (12 тестов, 23 утверждения — зелёные).
> Карточка и разбор — `docs/PROJECT.md (раздел 2)` DEV-102 (регрессии) и §13 (починка).
>
> **Ревизия номеров строк и счётчиков 30.09.2026 (DEV-142).** Раздел пересверен
> по коду: все ссылки вида `файл:NNN` заменены на `~строка N` (они смещаются при
> правках выше по файлу — в `functions.php` темы сдвиг составил +9 после
> DEV-131/DEV-138, в `idpg-core.php` −3 после DEV-141), счётчик функций меню
> исправлен 7 → 8. Правило — `docs/PROJECT.md (раздел 2)` §15.

#### 6.1 Что фактически работает

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

#### 6.2 Что есть, но НЕ подключено (мёртвый код в рантайме)

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

#### 6.2.1 Персональные данные (152-ФЗ)

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

#### 6.2.2 События организатора (REST, DEV-42)

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

#### 6.2.3 Отклонение события модератором (DEV-46)

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

#### 6.3 Чего нет вовсе

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

#### 6.4 Gap-лист

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

#### 6.5 Навигация по DEV-33…36 (TZ_BACKLOG)

Заявленные ранее «DEV-33…36» для ЛК-событий в актуальной нумерации `docs/PROJECT.md (раздел 2)` частично заняты
другими задачами. Соответствие по факту кода:

| Заявленная тема | Реальная позиция в TZ_BACKLOG | Статус по факту |
|---|---|---|
| «DEV-33-34: каркас ЛК» | DEV-33 = rate limiter (занят); DEV-34 = каркас ЛК | Каркас собран и подключён (DEV-37/38/39), профиль редактируется (DEV-73), REST событий готов (DEV-42), отклонение модератором работает (DEV-46). Открытые части кабинета — G5, G6 |
| «DEV-35: список/создание событий в ЛК» | Свободного DEV-35 нет | Открытая работа: UI над готовым API — G5 (форма, DEV-43/44), G6 (живые разделы, DEV-45) |
| ~~«DEV-36: доуполнение анкеты»~~ | Совпадало с DEV-01 (P0) | **Закрыто DEV-72**: анкета удалена целиком |


---

# Раздел 2. Что делать

> Источник: бывший `docs/TZ_BACKLOG.md`. Последний разбор — DEV-153 (30.09.2026).

### Портал-афиша «Иду по городу»

**Версия документа:** 1.0
**Дата:** 15.09.2026
**Основание:** `docs/PROJECT.md (раздел 1)` (инвентаризация), `docs/archive/docs/archive/TZ_IMPLEMENTED.md` (актуальное ТЗ, раздел 9),
аудит кода `idpg-core` 1.1.2 / `idpg-auth` 1.0.0 / темы `idpg_core` 1.0.75.
**Область:** доработка существующего функционала. Новые крупные подсистемы в объём не входят.

#### Обозначения

| Метка | Значение |
|---|---|
| **P0** | Блокер: без этого функционал не работает или прод небезопасен |
| **P1** | Высокий: влияет на пользователей или на требования законодательства |
| **P2** | Средний: улучшение, техдолг, удобство администрирования |
| **P3** | Низкий: косметика, документация, гигиена кода |
| **S / M / L** | Объём: до 1 файла / несколько файлов / подсистема с миграцией и тестами |

#### ⚠️ О числах в карточках (ревизия DEV-140, 30.09.2026)

**Числа в карточках — не факт о коде.** Перед использованием сверять с репозиторием.

- **Версии в статусах** («idpg-core 1.1.5, тема 1.0.77») относятся к состоянию
  **на момент задачи** или к живому стенду **до создания репозитория** (репозиторий
  появился 19.09.2026, DEV-53). Проверено: `IDPG_CORE_VERSION` в репозитории —
  **1.1.2 с первого коммита** и никогда не менялся; версии 1.1.3…1.1.8 в истории
  репозитория не встречаются. Сравнивать эти числа с текущим деревом нельзя.
- **Номера строк** (`functions.php:455`) устаревают при каждой правке выше по файлу.
  Считаются подсказкой **на дату**, а не адресом; в тексте они помечены `~строка`.
  Надёжный ориентир — имя функции, хука или метода.
- **Счётчики** (число тестов, правил, hex-значений, размеры файлов) — снимок на дату.
- Итоговая сверка — **§15. DEV-140. Числовая ревизия карточек**.

#### Правила выполнения (из `AGENTS.md`)

Изменения только аддитивные: не удалять существующий код, не переписывать работающие функции,
не менять сигнатуры методов, новое — через хуки (actions/filters) с префиксом `idpg_`.
Каждая задача — с php-doc на русском, nonce + `current_user_can()`, escape на выводе,
`$wpdb->prepare()` для SQL.

---

### 1. КРИТИЧЕСКИЕ ДЕФЕКТЫ (P0)

#### DEV-01. Не работает доуполнение анкеты организатора — СНЯТО, заменено DEV-72 (формально DEV-147)

> **Статус: закрыт DEV-72 — задача снята, а не исправлена.**
> DEV-01 чинил маршрут `POST /auth/organizer-complete` и слой анкеты
> `/organizer/complete/`. В DEV-72 владелец принял продуктовое решение, что
> отдельной анкеты нет: организатор после подтверждения email (или сразу при
> соцвходе) попадает в кабинет, а данные организации заполняются в профиле
> (DEV-73). Поэтому маршрут, шаблон, скрипт, класс `IDPG_Organizer_Page`,
> `can_complete_organizer()` и `handle_organizer_complete()` **удалены**, а не
> доведены до рабочего состояния. Ниже — историческое описание задачи.
>
> **Исходный статус: выполнено 19.09.2026 (локальное дерево, ещё не релиз).**
> В `includes/class-rest-auth.php` был зарегистрирован маршрут
> `POST /idpg/v1/auth/organizer-complete` + permission-колбэк `can_complete_organizer()`
> (nonce `wp_rest` через заголовок `X-WP-Nonce`, авторизация, наличие pending-флага)
> и обработчик `handle_organizer_complete()`: валидация обязательных полей
> (`org_name`/`contact_name`/`phone`), сохранение меты `idpg_org_name`,
> `idpg_contact_name`, `idpg_phone`; опционально email (защита от захвата чужим
> через `email_exists()` → `409`) и пароль (мин. 8 символов, совпадение
> `password`/`password_rep`); снятие pending-флага под **обоими** ключами
> (`_idpg_org_pending` и `idpg_org_pending`, т.к. исторически они расходятся);
> `do_action( 'idpg_organizer_completed' )`; ответ — `success`, `role`,
> `redirect_url` (админка или главная). Хелпер `is_pending_organizer()` читал оба ключа.
> Поля формы сверены с `page-organizer-complete.php` и `organizer-complete.js`.
> Проверено внутренним REST-диспатчем (`rest_do_request`): маршрут зарегистрирован
> (был 404), успех `200` + меты сохранены + email/пароль обновлены + флаг снят;
> повторный вызов → `403 organizer_not_pending`; неверный nonce → `403`. Тестовые
> пользователи удалены.
>
> ⚠️ **Открытая отдельная проблема (пайплайн-петля «соц-организатор»):** флаг,
> который открывает страницу `/organizer/complete/`, в соц-пути **никогда не ставился**,
> а кнопка с `intent=organizer` не давала соц-незаполненную анкету
> (подробности — в DEV-50 ниже).

| | |
|---|---|
| **Проблема** | Форма `/organizer/complete/` и `organizer-complete.js` отправляют `POST /wp-json/idpg/v1/auth/organizer-complete`, но маршрут **не зарегистрирован** в `class-rest-auth.php` (там только 6 маршрутов). Пользователь с флагом `_idpg_org_pending` попадает на страницу, заполняет анкету и получает 404. |
| **Требование** | Зарегистрировать маршрут `POST /auth/organizer-complete`, который: принимает `org_name`, `contact_name`, `phone`; проверяет авторизацию и nonce `wp_rest`; требует наличия флага `_idpg_org_pending`; сохраняет мету `idpg_org_name`, `idpg_contact_name`, `idpg_phone`; снимает флаг `_idpg_org_pending`; возвращает URL редиректа (админка или главная). |
| **Реализация** | `plugins/idpg-core/includes/class-rest-auth.php` — новый `register_rest_route` + `handle_organizer_complete()`; сверка с полями формы в `themes/idpg_core/page-organizer-complete.php` и `assets/js/organizer-complete.js`; при необходимости — существующий хелпер `IDPG_Organizer_Verify` / `IDPG_Organizer_Page`. |
| **Приёмка** | Организатор после подтверждения email заходит на `/organizer/complete/`, заполняет форму, получает успешный ответ, флаг снят, мета сохранена, повторный заход на страницу редиректит на главную. |
| **Объём** | S |

#### DEV-02. Синхронизация статусов билетов не работает (заглушка API)

| | |
|---|---|
| **Проблема** | `IDPG_TICKET_API_URL` = `https://api.example.com/v1`; запросы блокируются защитой, cron каждые 3 часа фактически ничего не делает, в админке висит предупреждение. |
| **Требование** | Доработать `build_api_url()` и парсер ответа под реальный API агрегатора: получение статуса по `_idpg_external_id`, маппинг в `_idpg_ticket_status`, обработка ошибок/таймаутов, повторные попытки, логирование в журнал плагина. Адрес — через `IDPG_TICKET_API_URL` (уже реализовано). |
| **Реализация** | `plugins/idpg-core/includes/class-ticket-sync.php`; уведомления — `class-notifications.php` (хук `idpg_ticket_status_changed` уже есть). |
| **Приёмка** | На тестовом событии с реальным `_idpg_external_id` статус меняется при изменении на стороне агрегатора; при недоступности API ошибка попадает в журнал, событие не портится; при заглушке `example.com` запросы по-прежнему не уходят. |
| **Блокер** | Требуются доступы/документация API агрегатора от владельца продукта. |
| **Объём** | M |

#### DEV-03. Прод не защищён HTTPS-политикой

> **Статус: выполнено 15.09.2026 (в репозиторном `wp-config.php` и `.htaccess`).**
> Применено: `WP_ENVIRONMENT_TYPE='production'`, `FORCE_SSL_ADMIN=true`, `WP_DEBUG=false`
> (`WP_DEBUG_LOG=true`, `WP_DEBUG_DISPLAY=false`), `DISALLOW_FILE_EDIT=true`,
> `DISALLOW_FILE_MODS=true`, ротация ключей и солей; в `.htaccess` добавлен блок
> «IDPG HTTPS redirect» (http → https, 301) с nginx-аналогом в `docs/PRODUCTION.md` (раздел 1.1).
> Проверено на стенде: `siteurl`=`home`=`https://idupogorodu.test`, HTTPS отдаёт 200,
> http отдаёт 301 на https, cookie `wordpress_test_cookie` приходит с флагом `secure`.
> **Осталось на сервере:** создать `wp-config.php` из шаблона (`deploy.sh` его не копирует),
> проверить сертификат на проде, применить nginx-правило (если прод на nginx).
> Бэкапы: `D:\laragon\backups\idupogorodu\{wp-config.php,.htaccess}.bak-20260915`.

| | |
|---|---|
| **Проблема** | В `docs/PRODUCTION.md` зафиксировано: на стенде `WP_DEBUG=true`, `WP_ENVIRONMENT_TYPE` не задан, нет `FORCE_SSL_ADMIN`; вероятно нет HTTPS и редиректа http→https. Пароли и cookie авторизации могут ходить в открытом виде. |
| **Требование** | Включить SSL на хостинге, редирект http→https, `FORCE_SSL_ADMIN = true`, `WP_ENVIRONMENT_TYPE = 'production'`, `WP_DEBUG = false` (+ `WP_DEBUG_LOG` по необходимости), новые ключи и соли, `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`. |
| **Реализация** | `wp-config.php`, настройки хостинга, `.htaccess`; чек-лист — `docs/PRODUCTION.md`. |
| **Приёмка** | `http://` редиректит на `https://`; в админке только HTTPS; `wp option get siteurl` и `home` — реальный домен; cookie сессии с флагом Secure; `WP_DEBUG` выключен. |
| **Объём** | S (инфраструктура) |

---

### 2. БЕЗОПАСНОСТЬ И СООТВЕТСТВИЕ ЗАКОНОДАТЕЛЬСТВУ (P1)

#### DEV-04. CSRF на выходе из аккаунта — ЗАКРЫТО (DEV-149)

> **Прежний статус был фиктивным — правка DEV-149 (30.09.2026).**
> Блок «Статус: выполнено 15.09.2026 (idpg-core 1.1.3)» ниже **не соответствовал
> коду**: ни `can_logout()`, ни кодов `idpg_logout_nonce_missing` /
> `idpg_logout_nonce_invalid`, ни фильтра `rest_authentication_errors` для этого
> маршрута в проекте не было. `git log --all -S 'can_logout'` → ноль коммитов:
> код никогда не существовал в истории. Заявленная версия 1.1.3 при текущей
> 1.1.2 подтверждает, что правка была сделана в рабочей копии и не доехала
> до репозитория. Описание ниже оставлено как есть — читать его как отчёт
> о сделанной работе нельзя.
>
> **Что сделано в DEV-149:**
> - `permission_callback` маршрута: `'is_user_logged_in'` → `can_logout()`;
> - добавлена проверка nonce `wp_rest` (заголовок `X-WP-Nonce` либо параметр
>   `_wpnonce`) **до** проверки авторизации, иначе ядро обнуляет пользователя
>   первым и настоящая причина отказа теряется за 401;
> - коды отказа: `idpg_logout_nonce_missing` (403), `idpg_logout_nonce_invalid`
>   (403), `idpg_logout_not_logged_in` (401), `idpg_logout_forbidden` (403);
> - `current_user_can( 'read' )` как проверка права;
> - `handle_logout()` тоже проверяет nonce — маршрут защищён колбэком прав,
>   но обработчик вызывается и напрямую из кода;
> - тесты `tests/test-logout-nonce.php`, 6 сценариев.
>
> **Замер до и после (обратная проба, внутренний диспатч):**
>
> | Запрос | До | После |
> |---|---|---|
> | авторизован, без nonce | `200`, **разлогинен** | `403 idpg_logout_nonce_missing`, сессия жива |
> | авторизован, неверный nonce | `200`, **разлогинен** | `403 idpg_logout_nonce_invalid`, сессия жива |
> | авторизован, валидный nonce | `200`, разлогинен | `200`, разлогинен |
> | гость, валидный nonce | `401 rest_forbidden` | `401 idpg_logout_not_logged_in` |
>
> **Уточнение о векторе атаки.** Вектор `<img src=".../auth/logout">` из
> исходной формулировки нерабочий: маршрут объявлен `CREATABLE` (POST-only),
> а `<img>` шлёт GET. Реальная защита держалась на том, что ядро при запросе
> без nonce обнуляет пользователя, плюс на `SameSite` у cookie авторизации.
> Практическая эксплуатация требовала обхода этих двух вещей. Ценность DEV-149
> — defense in depth: контракт «выход только по валидному токену» теперь
> зафиксирован в коде и в тестах, а не держится на поведении ядра.
>
> **Пункт «обновить вызов выхода в шапке/JS темы» выполнять не потребовалось.**
> Фронтенд REST-маршрут не использует: `header.php:247` выводит нативный
> `wp_logout_url()`, который ядро уже защищает nonce действия `log-out`, и он
> работает без JavaScript. Ни один JS-файл темы и ни один PHP-файл плагина
> `auth/logout` не вызывают — в дереве есть только объявление маршрута и
> комментарий в `class-auth.php:219`. Переводить рабочий выход на REST значило
> бы добавить зависимость от JS ради защиты, которой там нет.

> **Статус: выполнено 15.09.2026 (idpg-core 1.1.3, тема 1.0.76).**
> `POST /auth/logout` защищён nonce `wp_rest` (заголовок `X-WP-Nonce` или параметр `_wpnonce`),
> добавлен permission-колбэк `can_logout()`; ошибка ядра `rest_cookie_invalid_nonce` для этого
> маршрута переведена на русский текст (фильтр `rest_authentication_errors`, приоритет 110).
> Проверено внутренним REST-диспатчем: валидный nonce + авторизация → `200` (`success`,
> `redirect_url` — формат не изменился); валидный nonce + гость → `401`; без nonce →
> `403 idpg_logout_nonce_missing`; неверный nonce → `403 idpg_logout_nonce_invalid`.
> Сигнатура `handle_logout()` не менялась (0 параметров). Тема: `idpgData.restNonce`,
> маркер `data-idpg-logout` в шапке, выход через REST в `navigation.js` с откатом на
> нативный `wp_logout_url()` (работает без JS и при ошибке REST).
> Не проверено вручную: клик по кнопке «Выйти» в браузере под живой сессией (нет доступа
> к паролю администратора) — сценарий закрыт автотестом через внутренний диспатч.

| | |
|---|---|
| **Проблема** | `POST /idpg/v1/auth/logout` (`class-rest-auth.php`, маршрут ~строка 164, обработчик `handle_logout()` ~строка 575) не проверяет nonce — только `is_user_logged_in`. Возможен принудительный разлогин пользователя сторонним сайтом. |
| **Требование** | Проверять nonce `wp_rest` (`X-WP-Nonce` или параметр `_wpnonce`) перед `wp_logout()`. Обратную совместимость не ломать: при отсутствии nonce возвращать 403 с понятным текстом, фронтенд обновить. |
| **Реализация** | `class-rest-auth.php` (`handle_logout()` + `permission_callback`), вызов выхода в шапке/JS темы. |
| **Приёмка** | Запрос без nonce → 403; с валидным nonce → выход и редирект на главную; кнопка выхода в интерфейсе работает. |
| **Объём** | S |

#### DEV-05. Нет защиты от автоматических регистраций — ЗАКРЫТО (DEV-150)

> **Прежний статус был фиктивным — правка DEV-150 (30.09.2026).**
> Блок «Статус: выполнено 15.09.2026 (idpg-core 1.1.5, тема 1.0.77)» ниже **не
> соответствовал коду**. Не существовало: константы `HONEYPOT_FIELD`, методов
> `is_honeypot_filled()` и `silent_registration_response()`, параметра
> `idpg_company` в схеме маршрута, поля в `page-login.php`, правила `.auth__hp`
> в `style.css`, фильтра `idpg_auth_captcha_enabled`.
> `git log --all -S 'HONEYPOT_FIELD'` → ноль коммитов: код никогда не был
> в истории. Заявленная версия 1.1.5 при текущей 1.1.2 это подтверждает.
> В описании ниже названо и то, чего в коде не было никогда, — например
> передача поля из `auth.js`, и «одинаково срабатывает для `type=user`», хотя
> регистрация обычных пользователей отключена (DEV-70). Оставлено как есть —
> читать как отчёт о работе нельзя.
> Отдельно: запись про `auth.js` попала в цель дважды, но по-разному. Поле
> действительно не отправлялось (см. ниже), однако не из-за «формы из инпутов»,
> а потому что payload собирается вручную по списку полей.
>
> **Обратная проба (внутренний диспатч, бот заполняет приманку):**
>
> | Сценарий | До | После |
> |---|---|---|
> | заполненный honeypot | `201`, **пользователь создан (ID 2)** | `201`, пользователь **не создан** |
> | обычная регистрация | `201` | `201`, без изменений |
>
> **Что сделано в DEV-150:**
> - `handle_register()`: проверка приманки **первой строкой**, до
>   `is_user_logged_in()`, проверки `type`, согласий и rate limiter —
>   иначе бот по кодам ошибок изучал бы правила формы, а исчерпал бы лимит
>   в 3 регистрации в час на реальном человеке с того же IP;
> - ответ приманки повторяет успешную регистрацию организатора один в один
>   (201, `success`, `pending_email`, тот же текст). Отличается только
>   `user_id` — он 0, настоящий ID не подставить без создания пользователя;
> - то же поле и проверка добавлены в PHP-fallback `page-login.php`: без них
>   приманку обходил бы простой POST на страницу без JavaScript;
> - **`auth.js` в payload приманку не отправлял.** Это нашлось при проверке,
>   и это была дыра на основном пути, а не на запасном: payload там собирается
>   вручную по списку полей, минуя форму целиком, поэтому поле `idpg_company`
>   до сервера не доходило ни разу. Защищённой была одна лишь форма без
>   JavaScript. Добавлено `idpg_company: fd.get( 'idpg_company' ) || ''`
>   (приём взят из `subscribe.js`, где так же сделано);
> - `.auth__hp` в `style.css` **и** в `assets/scss/pages/_auth.scss`
>   (иначе `npm run compile:css` стёр бы правило — страхует `test-css-build.php`).
>
> Тесты `tests/test-register-honeypot.php` (8 шт.): приманка заполнена → 201 и
> пользователя нет; пусто и поле отсутствует → обычная регистрация; приманка
> сильнее валидации полей; приманка не расходует лимит 3/час; массив вместо
> строки не обходит; поле внутри формы, увод за экран без `display:none` и в
> CSS, и в SCSS; `auth.js` отправляет приманку. Последний тест проверен
> мутацией — с убранной строкой падает.
>
> **Два решения, отличающиеся от исходной формулировки DEV-05.**
> Первое: код ответа приманки — **201, а не 200**. Настоящая регистрация
> отвечает 201, поэтому 200 выдал бы приманку одним этим кодом: бот сравнил бы
> два ответа и перестроил форму. В форме подписки такой ошибки нет — там и
> honeypot, и настоящий успех отвечают 200.
> Второе: значение поля намеренно **не** санитизируется в схеме маршрута.
> `sanitize_text_field` отдаёт на массиве пустую строку, а санитизация в REST
> происходит **до** проверки — и `idpg_company[]=x` обходил приманку, отдавая
> боту настоящий аккаунт. Поле используется только для сравнения с пустотой
> и никуда не пишется, так что санитизировать нечего; не-строку ловит
> обработчик.
>
> **Captcha не делалась.** Пункт 4 («опционально, фильтр
> `idpg_auth_captcha_enabled`») выполнять не потребовалось и не выполнялось:
> honeypот отсекает тупые формы, а покупка и подключение внешнего сервиса —
> это юридический вопрос ( transferring данные третьей стороне плюс
> согласие пользователя в тексте политики). Решение за владельцем продукта.
>
> **Что осталось за рамками.** Honeypot — приманка, а не стена: целевую
> регистрацию он не остановит. Ставят её ботам, которые заполняют все поля
> формы, — их honeypot не видит. Ограничение остаётся то же, что у подписки:
> rate limiter 3 регистрации в час с одного IP плюс ручная модерация.

> **Статус: выполнено 15.09.2026 (idpg-core 1.1.5, тема 1.0.77).**
> Honeypot-поле `idpg_company` добавлено в форму регистрации организатора (`page-login.php`)
> и поддержано на сервере в двух точках: REST `handle_register()` (константа
> `IDPG_REST_Auth::HONEYPOT_FIELD`, методы `is_honeypot_filled()` и
> `silent_registration_response()`) и PHP-fallback в `page-login.php` (первая ветка
> if/elseif-цепочки). Проверка стоит **до** любой бизнес-валидации, поэтому одинаково
> срабатывает и для `type=user`. `auth.js` передаёт значение поля в REST-запрос,
> в `style.css` добавлено правило `.auth__hp` (скрыто от людей, но не `display:none`).
> Имя поля совпадает с honeypot формы подписки (`class-rest-api.php`).
> Опционально добавлены фильтры `idpg_auth_captcha_enabled` (по умолчанию `false`) и
> `idpg_auth_captcha_verify` (по умолчанию `true`): при включённом флаге и `false` от
> интеграции регистрация отклоняется с `400 idpg_auth_captcha_failed`.
> Проверено на стенде (все тестовые пользователи удалены, конфиг AIOS восстановлен):
>
> | Сценарий | Результат |
> |---|---|
> | REST, honeypot заполнен, остальное валидно | `200` + `success`/`pending_email`, пользователь **не создан** |
> | REST, honeypot заполнен + `type=user` | `200` (а не `403`) — не подсказываем боту |
> | REST, honeypot пуст + короткий пароль | `400 short_password` — обычная валидация достигнута |
> | REST, поля honeypot нет вовсе | `400 short_password` — обратная совместимость |
> | REST, honeypot пуст, всё валидно | `201`, `user_id`, пользователь создан (контроль) |
> | Капча включена + проверка пройдена | обычная валидация (`400 short_password`) |
> | Капча включена + проверка не пройдена | `400 idpg_auth_captcha_failed` |
> | Fallback без JS, honeypot заполнен | нейтральный «успех», пользователь **не создан** |
> | Fallback без JS, honeypot пуст, всё валидно | «успех», пользователь создан (контроль) |
>
> Публичный интерфейс класса не менялся — добавлены константа и три приватных метода.
> **Формы регистрации обычного пользователя в теме нет** (`handle_register()` отвечает
> `403 registration_disabled` для `type=user`), поэтому honeypot добавлен в
> единственную существующую форму — организатора; серверная проверка общая для обоих типов.
> Попутно найдены два дефекта — см. DEV-31 и DEV-32.

| | |
|---|---|
| **Проблема** | `POST /auth/register` защищён только rate limiter по IP; honeypot и капчи нет (в форме подписки honeypot уже реализован — можно переиспользовать подход). |
| **Требование** | Добавить скрытое honeypot-поле в формы регистрации (пользователь и организатор) с серверной проверкой; при заполнении — молчаливый отказ с ответом 200 (не подсказывать боту). Опционально — поддержка капчи через фильтр `idpg_auth_captcha_enabled`. |
| **Реализация** | `class-rest-auth.php` (`handle_register()`), `themes/idpg_core/page-login.php`, `assets/js/auth.js`. |
| **Приёмка** | Заполненный honeypot → пользователь не создаётся, ответ нейтральный; обычная регистрация не затронута; формы без JS тоже защищены. |
| **Объём** | S |

#### DEV-06. Требования 152-ФЗ выполнены частично

> **Статус: выполнено 15.09.2026 (idpg-core 1.1.6, тема 1.0.78).**
>
> **1) Отдельное согласие на обработку ПДн.** Новый модуль
> `includes/class-privacy.php` (`IDPG_Privacy`, синглтон, префикс `idpg_`).
> Публичный API: `IDPG_Privacy::record_consent( $user_id, $source )`,
> `has_consent()`, `get_consent()`. Факт согласия пишется в user meta
> `idpg_pdn_consent` массивом `given` / `version` / `source` / `ip_hash`
> (IP хранится **только хешем** через `wp_hash()`, не в открытом виде).
> Проверка добавлена в двух точках: REST `handle_register()`
> (`400 pdn_consent_required`) и PHP-fallback `page-login.php`.
> Согласие с офертой (`terms`) его **не заменяет** — это отдельный флаг.
>
> **2) Cookie-баннер.** Cookie `idpg_cookie_consent` на год, значения из
> safelist `array( 'all', 'necessary' )`. Работает без JavaScript (обычный
> POST с nonce `idpg_cookie_choice`) и с ним (`assets/js/privacy.js`).
>
> **3) Права субъекта.** Самостоятельная выгрузка своих данных
> (`admin-post.php?action=idpg_privacy_self_export`, JSON) и запрос на удаление
> из формы на странице политики. Обработка — на новой админ-странице
> «Иду по городу → Конфиденциальность» (`admin.php?page=idpg-privacy`,
> `manage_options`): список запросов, выгрузка субъекта в JSON/CSV, удаление
> с подтверждением, журнал действий (`idpg_privacy_log`, лимит 200 записей).
>
> Проверено сквозными HTTP-тестами на локальном сайте (все тестовые
> пользователи удалены, конфиг AIOS и настройки восстановлены):
>
> | Сценарий | Результат |
> |---|---|
> | REST, регистрация без `idpg_pdn_consent` | `400 pdn_consent_required`, пользователь не создан |
> | REST, `idpg_pdn_consent=false` | `400 pdn_consent_required` |
> | REST, `idpg_pdn_consent=true` | `201`, `user_id`, согласие записано в meta (`source=organizer`) |
> | REST, honeypot заполнен + согласие есть | `200` нейтральный, пользователь не создан |
> | REST, `type=user` + согласие | `403 registration_disabled` |
> | Fallback без JS, без чекбокса ПДн | ошибка «Необходимо согласие…», пользователь не создан |
> | Fallback без JS, с чекбоксом ПДн | пользователь создан, согласие записано |
> | Fallback без JS, honeypot заполнен | нейтральный «успех», пользователь не создан |
> | Баннер, POST `choice=all` / `choice=necessary` | `302` + `Set-Cookie: idpg_cookie_consent=…; Max-Age=31536000; Secure; SameSite=Lax` |
> | Баннер, cookie уже стоит | баннер в HTML отсутствует |
> | Баннер, нет nonce / неверный nonce / `choice=hacked` | cookie **не** выставляется |
> | Админ-страница `page=idpg-privacy` | `200`, все 4 раздела отрисованы |
> | Экспорт JSON (админ) | `Content-Disposition: attachment; filename="idpg-user-<ID>-<дата>.json"` |
> | Экспорт CSV (админ) | BOM `EF BB BF`, разделитель `;`, `text/csv` |
> | Экспорт несуществующего пользователя | редирект `idpg_msg=not_found` |
> | Экспорт с неверным nonce | `403` (`check_admin_referer`) |
> | Самостоятельная выгрузка (субъект) | `200`, корректный JSON |
> | Запрос на удаление без чекбокса подтверждения | редирект `idpg_request=confirm`, meta не записана |
> | Запрос на удаление с подтверждением | редирект `idpg_request=ok`, meta `idpg_delete_requested_at` записана, запрос виден админу |
> | Удаление без `idpg_confirm=1` | редирект `idpg_msg=confirm_required`, пользователь жив |
> | Удаление с `idpg_confirm=1` | редирект `idpg_msg=deleted`, пользователь и вся его meta удалены |
> | Попытка удалить администратора | редирект `idpg_msg=admin_protected`, пользователь жив |
>
> Попутно исправлен баг доступа: страница отдавала
> `403 «Sorry, you are not allowed to access this page.»`, потому что
> `IDPG_Privacy::instance()` вызывается при подключении файла и её `admin_menu`-колбэк
> срабатывал раньше `add_menu_page()` родителя `idpg-settings` — WordPress считал имя
> хука `admin_page_idpg-privacy` вместо `idu-po-gorodu_page_idpg-privacy`.
> Хук регистрации подстраницы переведён на приоритет 20.
> Публичный интерфейс `IDPG_REST_Auth` не менялся — добавлен только параметр маршрута.
> Тексты `page-privacy.php` / `page-terms.php` обновлены, а также синхронизировано
> содержимое страниц в БД (страницы 113/114; бэкап — `backups/idupogorodu/dev06/`).
> Удаление субъекта выполняется `wp_delete_user()`, зачистку вложений добавляет
> существующий `IDPG_User_Purge` на хуке `delete_user`.

| | |
|---|---|
| **Проблема** | Есть оферта, политика и согласие с правилами, но нет: отдельного согласия на обработку персональных данных, cookie-баннера, механизма экспорта и удаления данных пользователя. |
| **Требование** | 1) Чекбокс «Согласен на обработку персональных данных» отдельно от согласия с офертой (обязательный при регистрации, серверная проверка). 2) Cookie-баннер с сохранением выбора (можно отложенно). 3) Инструменты прав субъекта: экспорт данных пользователя (JSON/CSV) и запрос на удаление с подтверждением. |
| **Реализация** | `class-rest-auth.php` (регистрация), `page-login.php`, `page-privacy.php`/`page-terms.php`, новый модуль `includes/class-privacy.php` (класс `IDPG_Privacy`, префикс `idpg_`), админ-страница в меню «IDPG» для обработки запросов. |
| **Приёмка** | Регистрация без согласия на ПДн отклоняется; баннер показывается один раз и запоминает выбор; админ выгружает данные пользователя и удаляет их по запросу; тексты документов обновлены. |
| **Объём** | L |

#### DEV-07. Nonce в форме пароля заглушки — ЗАКРЫТО (DEV-151)

> **Прежний статус был фиктивным — правка DEV-151 (30.09.2026).**
> Блок «Статус: выполнено 15.09.2026 (idpg-core 1.1.4)» ниже **не
> соответствовал коду**. В `class-maintenance.php` не было ни одного
> `wp_verify_nonce` — вместо него стояло `phpcs:ignore
> WordPress.Security.NonceVerification` на строках 960 и 964 (обработчик) и
> 1476 (разметка), то есть правило phpcs было выключено для всего файла.
> Метода `is_access_nonce_valid()` не существовало:
> `git log --all -S 'is_access_nonce_valid'` → ноль коммитов. Констант
> `NONCE_ACTION` / `NONCE_FIELD` тоже не было, `wp_nonce_field()` в файле не
> встречался. Заявленный сквозной HTTP-тест на локальном сайте не мог быть
> проведён: код к тому моменту не содержал проверки.
>
> **Обратная проба (реальный `handle_access_password()`, пароль подсовывается
> фильтром `pre_option_`, верный пароль, nonce не отправляется):**
>
> | Сценарий | До | После |
> |---|---|---|
> | верный пароль без nonce | **доступ выдан**: `Set-Cookie` + `exit` | `handle_access_password()` → `false`, cookie не ставится |
>
> Признак выдачи — процесс уходит в `exit` до маркера в пробе, то есть функция
> не вернула `false`. Никакого «сеанс устарел» в коде не было и раньше: любой
> POST с верным паролем получал доступ.
>
> **Что сделано в DEV-151:**
> - `ACCESS_NONCE_ACTION` и `ACCESS_NONCE_FIELD` рядом с `ACCESS_COOKIE`;
> - `wp_nonce_field( self::ACCESS_NONCE_ACTION, self::ACCESS_NONCE_FIELD )`
>   первым элементом формы пароля на заглушке;
> - `wp_verify_nonce()` **до** сравнения пароля. Порядок и есть суть защиты:
>   при обратном верный пароль без nonce доходил бы до выдачи cookie, и правка
>   была бы косметикой;
> - оба `phpcs:ignore ...NonceVerification` сняты — правило phpcs снова
>   работает. Приглушения по другим правилам (`ValidatedSanitizedInput`,
>   `EscapeOutput`) не тронуты, это не в задаче.
>
> **Два решения, отличающиеся от прежнего описания карточки.**
> Первое: имена констант — `ACCESS_NONCE_ACTION` / `ACCESS_NONCE_FIELD`, а не
> `NONCE_ACTION` / `NONCE_FIELD`. В классе есть и другие формы, и префикс
> `ACCESS_` отличает nonce пароля доступа от остальных. Прежние имена в коде
> не существовали, так что обратной совместимости тут нет.
> Второе: при неверном nonce показывается существующее «Неверный пароль.»,
> а не отдельное «Сессия устарела…» — такой строки в проекте нет и добавлять
> её не требовалось. Для посетителя разницы нет, а отличить «подделка» от
> «опечатка в пароле» ему не следует.
>
> **Про формат констант.** Nonce живёт 24 часа и привязан к сессии. Форма,
> открытая дольше суток, отправит просроченный nonce и получит «Неверный
> пароль» вместо доступа — при верном пароле. Это осознанно: cookie доступа
> живёт месяц, и на практике форма открывается на минуты. Обход этого
> ограничения означал бы nonce с бесконечным сроком, а это уже не защита.
>
> **Тесты `tests/test-maintenance-nonce.php` (10 шт.).** Бьют по решению
> `is_access_request_valid()`, а не по обработчику: успешная ветка ставит
> cookie и делает `exit`, а `setcookie()` в CLI — no-op и в `$_COOKIE` не
> попадает, поэтому позитивный сценарий иначе нечем проверить. Покрыты:
> верный пароль без nonce → отказ; невалидный nonce → отказ; валидный nonce и
> верный пароль → доступ; nonce проверяется раньше пароля; верный nonce не
> спасает неверный пароль; пустая опция, пустой POST и массив вместо nonce →
> отказ; форма рендерит `wp_nonce_field()` внутри `<form>`; приглушение phpcs
> снято, а приглушения по другим правилам остались.

> **Статус: выполнено 15.09.2026 (idpg-core 1.1.4).**
> В форму доступа на заглушке добавлено скрытое поле nonce (`wp_nonce_field()` с действием
> `idpg_maintenance_access` и именем поля `idpg_maintenance_nonce`); при обработке
> `$_POST['idpg_maintenance_pass']` nonce проверяется методом `is_access_nonce_valid()`
> (`wp_verify_nonce()`), при провале `handle_access_password()` возвращает `false`.
> Проверено сквозным HTTP-тестом на локальном сайте (заглушка включена, пароль
> `TEST-DEV07-PASS`, настройки после теста восстановлены): без nonce + **верный** пароль →
> `503`, `Set-Cookie` отсутствует, выводится «Сессия устарела…»; неверный nonce + верный
> пароль → `503`, cookie нет; валидный nonce + неверный пароль → `503`, «Неверный пароль.»;
> валидный nonce + верный пароль → `302`, `Set-Cookie: idpg_maintenance_pass=…; path=/;
> secure; HttpOnly`; повторный `GET /` с этой cookie → `200` (реальная главная вместо
> заглушки). Контроль: `GET /` без cookie → `503` (заглушка оставалась активной).
> Публичный интерфейс `IDPG_Maintenance` не менялся — добавлены только константы
> `NONCE_ACTION` / `NONCE_FIELD` и приватный метод `is_access_nonce_valid()`.

| | |
|---|---|
| **Проблема** | `class-maintenance.php:960` обрабатывает `$_POST['idpg_maintenance_pass']` без nonce (`phpcs:ignore WordPress.Security.NonceVerification`). Риск низкий (нужен сам пароль), но форма не защищена от CSRF-подстановки. |
| **Требование** | Добавить `wp_nonce_field()` в форму заглушки и `wp_verify_nonce()` при обработке. |
| **Реализация** | `class-maintenance.php` (рендер формы и обработчик). |
| **Приёмка** | Отправка без nonce отклоняется; ввод корректного пароля по-прежнему ставит cookie доступа. |
| **Объём** | S |

#### DEV-08. Проблемы безопасности из аудита — подтвердить и закрыть

> **Статус: выполнено 15.09.2026.**
> Оба заявленных пункта подтверждены повторной проверкой кода, остальные закрыты
> задачами DEV-04…DEV-07 (все отмечены «выполнено» выше).
>
> **1) `error_log()` в боевом коде.** Проверка
> `grep -rnE '^\s*[^/*#]*\b(error_log|var_dump|print_r)\s*\(' plugins/idpg-core plugins/idpg-auth themes/idpg_core`
> с исключением `/vendor/` даёт пустой результат. В `idpg-core` остаются только
> **закомментированные** строки-напоминания «Запрещено на проде (AGENTS.md, п.11)» в
> `class-auth-rate-limiter.php` (4 пары) и `class-ticket-sync.php` (1 пара);
> совпадение в `idpg-auth/includes/class-wp-auth-ru-security.php:645` — это регулярное
> выражение по имени файла, а не вызов. `var_dump`/`print_r` встречаются только в
> `vendor/` (Carbon Fields, PHPUnit). Логирование идёт через журнал плагина.
>
> **2) `ip-api.com` по HTTPS.** `IDPG_City_Filter::request_ip_data()`
> (`class-city-filter.php:351`) для сервиса `ip_api` перебирает адреса по порядку:
> сначала `https://ip-api.com/json/…`, при `WP_Error` или коде ≠ 200 — `http://…`.
> `ipapi.co` запрашивается только по HTTPS. Логика не менялась (исправлено в 1.0.9),
> подтверждено чтением кода.
>
> **3) Остальные пункты.** DEV-04 (CSRF на выходе), DEV-05 (honeypot от авторегистраций),
> DEV-06 (152-ФЗ: согласие, cookie-баннер, права субъекта), DEV-07 (nonce в форме заглушки) —
> реализованы и проверены сквозными HTTP-тестами в предыдущих сессиях.
>
> **4) Финальный чек-лист.** Сводный чек-лист безопасности зафиксирован в
> `docs/PRODUCTION.md`, новый раздел **10.1** («Чек-лист безопасности из аудита
> (закрытие DEV-03…DEV-08)»): требования к коду, таблица закрытых задач и 9 проверок
> для прода (раздел 10.1.3). В «Финальную проверку перед запуском» добавлены пункты 12–13.
> Код при выполнении DEV-08 не менялся — новых версий плагина/темы не выпускалось.

| | |
|---|---|
| **Статус проверки** | ✅ `error_log()` / `var_dump()` / `print_r()` в боевом коде отсутствуют (только закомментированные строки-напоминания). ✅ Запрос к `ip-api.com` идёт сначала по HTTPS с fallback на HTTP (исправлено в 1.0.9, подтверждено). ✅ Остальные пункты закрыты задачами DEV-04…DEV-07. ✅ Чек-лист зафиксирован в `docs/PRODUCTION.md`, раздел 10.1. |
| **Требование** | Закрыть оставшиеся пункты и зафиксировать в `docs/PRODUCTION.md` финальный чек-лист безопасности. |
| **Объём** | S |

#### DEV-31. Форма регистрации без JS «подставляет» посетителя под блокировку AIOS

> **Найдено попутно при проверке DEV-05 (15.09.2026). Не исправлено — вне объёма задачи.**

| | |
|---|---|
| **Проблема** | PHP-fallback в `page-login.php` (`if ( ! $is_forgot && isset( $_POST['email'] ) && isset( $_POST['password'] ) )`, ~строка 133) срабатывает на **любую** отправку с полями `email` + `password` — включая форму регистрации, где эти поля всегда есть. Поэтому при регистрации без JS сначала выполняется `wp_signon()` с ещё не существующим логином → ошибка `invalid_username` → «Неверный email или пароль.» вперемешку с сообщением об успешной регистрации. Сама регистрация при этом проходит (это отдельный `if`, а не `elseif`). |
| **Последствие** | На стенде в AIOS включены Login Lockout и `aiowps_enable_invalid_username_lockdown` → **один** вход с несуществующим логином **мгновенно** блокирует IP на 1 час (`wp_aiowps_login_lockdown`, `lock_reason=invalid_username`). То есть реальный посетитель, зарегистрировавшийся без JavaScript, регистрируется, но тут же теряет возможность войти на час. Воспроизведено: после отправки формы регистрации IP `78.85.30.128` попал в блокировку с логином тестового пользователя. |
| **Требование** | Развести ветки: определять форму по наличию маркера (например, `idpg_form=register|login|forgot` в скрытом поле) либо по `org_name`/`password_confirm`, и не вызывать `wp_signon()` для регистрационной отправки. Дополнительно рассмотреть `aiowps_enable_invalid_username_lockdown` на проде — мгновенная блокировка по опечатке в логине бьёт по живым пользователям. |
| **Реализация** | `themes/idpg_core/page-login.php` (обработчик POST), при необходимости — скрытый маркер формы в разметке и `auth.js`. |
| **Приёмка** | Регистрация без JS не вызывает `wp_signon()`; сообщения об ошибке входа не появляются на форме регистрации; IP посетителя не попадает в блокировку AIOS; вход и восстановление пароля работают как раньше. |
| **Объём** | S |

---

### 3. НЕЗАВЕРШЁННЫЙ ФУНКЦИОНАЛ (P1–P2)

#### DEV-09. Система баннеров middle/bottom (решение владельца)

| | |
|---|---|
| **Проблема** | README ядра и ТЗ v1.0 описывают систему баннеров с двумя позициями, UTM-метками, периодом показа, TTL кэша и отдельным меню «Баннеры». Файла `class-banners.php` нет; сейчас работают баннер текущего события и области виджетов. |
| **Требование (вариант А, рекомендуется)** | Признать замену состоявшейся: удалить описания баннеров из README ядра и ТЗ v1.0, зафиксировать в документации фактическую схему (баннер события + рекламный виджет + области виджетов). |
| **Требование (вариант Б)** | Реализовать подсистему: сущность баннера (позиция middle/bottom, изображение + мобильное, ссылка, alt, период показа, приоритет, UTM, счётчик кликов), админ-страница, рендер в позициях `middle`/`bottom` через существующий хук `idpg_banner_position`, кэш. |
| **Реализация** | Вариант А — `plugins/idpg-core/README.md`, `docs/archive/docs/archive/PLUGIN_TZ.md`. Вариант Б — новый `includes/class-banners.php` (`IDPG_Banners`), `assets/css/banners.css`, интеграция с `front-page.php`. |
| **Приёмка** | Вариант А: документация не противоречит коду. Вариант Б: баннер выводится в выбранной позиции, учитывает период и приоритет, клики считаются, UTM подставляются. |
| **Объём** | S / L |

#### DEV-10. Продвижение событий: срок закрепления и авто-снятие

| | |
|---|---|
| **Проблема** | Поле `idpg_pin_expires` из ТЗ v1.0 не реализовано; `_idpg_promoted` (закрепить в топе) не снимается автоматически. |
| **Требование** | Поле «Закрепить до» (Date, контейнер «Место и продвижение»); ежедневная cron-задача снимает `_idpg_promoted`, когда дата прошла; событие с истёкшим закреплением перестаёт попадать в топ. |
| **Реализация** | `class-meta.php` (поле + зеркало меты), новый хук в `class-ticket-sync.php` либо отдельная cron-задача `idpg_cron_unpin_expired`, `class-migrations.php` (регистрация меты). |
| **Приёмка** | Событие с прошедшей датой закрепления теряет `_idpg_promoted` после запуска cron; повторный запуск идемпотентен. |
| **Объём** | M |

#### DEV-11. Часы работы и соцсети площадки — ЗАКРЫТО (30.09.2026)

| | |
|---|---|
| **Проблема** | Поля `idpg_venue_hours` (день/открытие/закрытие/выходной) и `idpg_venue_social` (сеть + ссылка) не реализованы; на странице площадки нет этих блоков. |
| **Требование** | Complex-поля в контейнере «Данные площадки» + зеркала `_idpg_venue_hours_json`, `_idpg_venue_social_json` для шаблонов; вывод на `single-venue.php`. |
| **Реализация** | `class-meta.php`, `themes/idpg_core/single-venue.php`, `inc/template-tags.php` (хелперы). |
| **Приёмка** | Данные сохраняются в админке, выводятся на странице площадки, пустые значения не ломают вёрстку. |
| **Объём** | M |

**Проверка:**

| Проверка | Результат |
|---|---|
| Complex-поле `idpg_venue_hours` в Carbon Fields | **есть**, 4 суб-поля (day, open, close, is_closed) |
| Complex-поле `idpg_venue_social` | **есть**, 2 суб-поля (network, url) |
| Зеркала JSON | `_idpg_venue_hours_json`, `_idpg_venue_social_json` — пишутся через `mirror_venue_hours()`/`mirror_venue_social()` на `carbon_fields_post_meta_container_saved` |
| REST-схема | **зарегистрирована**, `show_in_rest=true` |
| `idpg_core_venue_meta()` | возвращает `hours` и `social` как массивы; пустые/невалидные → `[]` |
| `single-venue.php` | выводит часы работы списком (с пометкой «выходной») и соцсети ссылками |
| Тесты | `test-venue-hours-social.php` — 4 теста / 15 утверждений, OK |
| Полный набор | 533 теста / 1596 утверждений, падений 0 |

#### DEV-12. Профиль организатора: реквизиты и верификация — ЗАКРЫТО (01.10.2026)

| | |
|---|---|
| **Проблема** | Из ТЗ v1.0 не реализованы: `idpg_legal_type` (ИП/ООО/физлицо/НКО), `idpg_inn` (10/12 цифр), `idpg_verified` (только админ), `idpg_documents` (галерея PDF/сканов). Сейчас есть только подтверждение email и модерация публикаций. |
| **Требование** | Поля в профиле организатора (user meta + Carbon Fields на экране пользователя), валидация ИНН `^(\d{10}|\d{12})$`, статус верификации меняет только администратор (`protect_verification_status`), загрузка документов. |
| **Реализация** | `class-organizer-url.php` — константы `META_LEGAL_TYPE`, `META_INN`, `META_VERIFIED`, `META_DOCUMENTS`, `LEGAL_TYPES`; `get_profile_data()` / `sanitize_profile_input()` / `update_profile()` расширены; `class-rest-organizer.php` — схема REST (`legal_type`, `inn`, `documents`); `section-profile.php` — поля в форме, `wp_enqueue_media()`, inline JS для загрузки документов; `organizer.js` — payload расширен; новый `class-organizer-meta.php` — Carbon Fields `user_meta` с защитой `verified`; подключение в `idpg-core.php`. |
| **Приёмка** | Организатор заполняет реквизиты и прикладывает документы; ИНН неверного формата отклоняется; организатор не может изменить свой статус верификации; админ видит и меняет статус. |
| **Объём** | L |

#### DEV-13. Панель организатора в админке — СНЯТО (01.10.2026)

| | |
|---|---|
| **Проблема** | Организаторы проходят в админку, но отдельной панели «Мои события» с полями анкеты нет; список событий ограничивается только скоупом по автору. |
| **Требование** | Пункт меню «Мои события» с доступом только к записям текущего автора; на экране редактирования события — блок полей профиля организатора (legal_type, inn, documents, verified) с защитой статуса верификации. |
| **Решение** | **Снято продуктовое решение**: организатор не имеет доступа к админке (`wp-admin`). `IDPG_Auth::restrict_admin_access()` редиректит организатора с `wp-admin` в личный кабинет `/organizer/` (тесты — `test-admin-restrict.php`). Весь функционал организатора живёт в кабинете: события (`/organizer/events/`), площадки (`/organizer/venues/`), профиль (`/organizer/profile/`). Поля реквизитов администратор видит и правит на экране пользователя в админке (`class-organizer-meta.php`), но организатор туда не попадает. |
| **Приёмка** | Организатор при попытке зайти в `wp-admin` редиректится в `/organizer/`; скоуп медиа (`IDPG_Organizer_Scope`) работает через REST media-modal в кабинете. |
| **Объём** | — |

#### DEV-34. Каркас личного кабинета организатора `/organizer/` — ЗАКРЫТО (15.09.2026)

> **Нумерация.** В запросе задача помечена как DEV-33, но этот ID уже занят
> задачей «Rate limiter регистраций тратит лимит на неудачные попытки» (см. ниже).
> Чтобы не дублировать идентификаторы, задача внесена как **DEV-34**.
> Упомянутая в запросе «DEV-34 — список событий организатора» получит следующий
> свободный номер, когда до неё дойдёт очередь.
>
> **Статус: выполнено 15.09.2026 (idpg-core 1.1.7, тема 1.0.79).**
>
> > ⚠️ **Аудит DEV-00A (19.09.2026):** в текущем рабочем дереве **idpg-core 1.1.2 / тема 1.0.75**
> > реализация **не подключена**: файлы есть (`class-organizer-url.php`, `page-organizer.php`,
> > `template-parts/organizer/*`), но нет `require_once` `class-organizer-url.php`
> > (`idpg-core.php`, блок 75–119), нигде не вызывается `IDPG_Organizer_URL::init()`,
> > страница `/organizer/` в БД не создана (`idpg_core_create_organizer_page()` есть
> > только в docblock'ах), меню-модуль `inc/organizer-menu.php` не входит в `require_once`
> > `functions.php`, а `organizer.js`/`organizer.css` не регистрируются. Forever-закрытие
> > каркаса — после применения G1–G3 (см. `docs/archive/docs/archive/README_DEV-00A.md`, §9–10 и `docs/PROJECT.md (раздел 1)` §6).
>
> **1) Страница и шаблон.** `idpg_core_create_organizer_page()`
> (`themes/idpg_core/functions.php`, хук `after_setup_theme`) создаёт страницу
> `/organizer/` («Кабинет организатора», шаблон `page-organizer.php`) и запоминает
> её ID в опции `_idpg_organizer_page_id`; хелпер `idpg_core_get_organizer_page_id()`
> переиспользует запись и находит её по слагу, если опция потеряна.
>
> **2) Маршруты.** Новый `plugins/idpg-core/includes/class-organizer-url.php`
> (`IDPG_Organizer_URL`): правила `^organizer/?$` и `^organizer/(dashboard|events|profile)/?$`
> → `index.php?idpg_organizer_page=1&idpg_organizer_section=…`. Сброс постоянных ссылок —
> при активации плагина (`idpg_core_activate()`) и однократно по маркеру `REWRITE_VERSION`.
> `template_include` подключает шаблон темы и выставляет `200` (маршрут обслуживается
> шаблоном, а не записью WP).
>
> **3) Доступ.** Проверка в `page-organizer.php` по чистому решению
> `IDPG_Organizer_URL::get_access_decision()`: гость → `302 /login/`;
> авторизованный без прав → **403**
> (тематическая страница «Нет доступа», `status_header( 403 )`);
> организатор и выше → кабинет. Допускаются роли `event_organizer`, `administrator`,
> `editor`; расширение — фильтр `idpg_organizer_user_allowed`.
>
> **4) Разметка.** Сайдбар (Дашборд, Мои события, Профиль) + контент-область.
> Разделы рендерятся серверно (`template-parts/organizer/section-*.php`), активный
> виден, остальные скрыты атрибутом `hidden`; `organizer.js` только переключает
> видимость и обновляет URL через `history.pushState` (+ `popstate`). Без JavaScript
> работает прямой заход на `/organizer/events/`. Раздел по умолчанию — `dashboard`.
>
> **5) Меню.** `themes/idpg_core/inc/organizer-menu.php`: `idpg_organizer_menu()`
> (единый список разделов, сортировка по `order`) с фильтром
> `idpg_organizer_menu_items`, хелперы `idpg_organizer_section_url()`,
> `idpg_organizer_current_section()`, `idpg_core_is_organizer_cabinet()`.
>
> **6) Ассеты.** `assets/js/organizer.js`, `assets/css/organizer.css`; подключаются
> в `functions.php` с `IDPG_VERSION` **только** на `/organizer/*`.
>
> **7) Sitemap.** `idpg_core_exclude_organizer_page_from_sitemap()` на хуке
> `wpseo_exclude_from_sitemap_by_post_ids` убирает страницу из карты сайта Yoast.
>
> **8) Тесты.** `plugins/idpg-core/tests/test-organizer-page.php` — доступ для гостя,
> организатора, pending-организатора, пользователя без ролей и подписчика;
> нормализация раздела, адреса разделов, query-переменные и rewrite-правила.
>
> Проверено сквозными HTTP-тестами на локальном сайте (все тестовые пользователи
> удалены, временный mu-plugin авто-логина удалён, `total_users` 6 → 6):
>
> | Сценарий | Результат |
> |---|---|
> | Гость, `/organizer/`, `/organizer/events/`, `/organizer/profile/`, `/organizer/dashboard/` | `302` → `/login/` |
> | Организатор, те же четыре адреса | `200` |
> | Организатор, `/organizer/nope/` (несуществующий раздел) | `404` |
> | Организатор, `/organizer/` | активен `dashboard`: он без `hidden`, `events`/`profile` — с `hidden`; `aria-current="page"` один |
> | Организатор, `/organizer/events/` | активен `events`: `dashboard`/`profile` с `hidden`; `aria-current="page"` один |
> | Пользователь с ролью `author` | `403`, страница «Нет доступа» |
> | Организатор с флагом анкеты, `/organizer/` и `/organizer/events/` | `302` → `/organizer/complete/` |
> | Ассеты `organizer.css` / `organizer.js` | `200` (5 937 и 3 528 байт) на `/organizer/`; на главной — 0 подключений |
> | Содержимое разделов | дашборд — 4 карточки, профиль — 5 полей, события — заглушка |
> | Страница и опция | `ID 715`, `organizer`, `page-organizer.php`, `_idpg_organizer_page_id = 715` |
> | Yoast `page-sitemap.xml` | 9 опубликованных страниц в БД → 8 в карте; `/organizer/` отсутствует |
> | Регресс: `/`, `/privacy/`, `/terms/`, `/login/`, `/about/`, `/contacts/`, `/offer/`, REST `/idpg/v1/events` | `200` (`/afisha/` → `301` по правилу города) |
>
> ⚠️ **Строка про редирект `302 → /organizer/complete/` устарела с DEV-72.**
> Слой анкеты удалён: теперь организатор (с флагом email-pending или без)
> попадает в кабинет напрямую, `200`, а не `302` на `/organizer/complete/`.
> Таблица выше сохранена как исторический протокол проверки DEV-36.
>
> **Попутно найдено (не исправлено — вне объёма задачи):** флаг «анкета не
> доукомплектована» пишется под ключом `idpg_org_pending`
> (`IDPG_Organizer_Verify::prepare()`), а читался под `_idpg_org_pending`
> (`IDPG_Organizer_Page::is_pending()`, `page-organizer-complete.php`), из-за чего
> редирект на `/organizer/complete/` и админ-уведомление не срабатывали.
> ~~Требуется отдельное решение: привести ключ к одному виду.~~ **Снято DEV-72:**
> рассинхрон перестал существовать — `_idpg_org_pending` удалён из кода, а
> `idpg_org_pending` остался как флаг подтверждения email в
> `IDPG_Organizer_Verify`.

| | |
|---|---|
| **Проблема** | У организатора нет фронтенд-раздела для работы: `/organizer/complete/` — только анкета, остальное — админка WordPress. Обещанный в ТЗ личный кабинет отсутствует. |
| **Требование** | Фронтенд-раздел `/organizer/`, доступный только роли `event_organizer` и выше: сайдбар с разделами (Дашборд, Мои события, Профиль) и контент-область. На первом этапе — каркас с заглушками, без реальных данных. |
| **Реализация** | `includes/class-organizer-url.php` (маршруты и доступ), `themes/idpg_core/page-organizer.php` + `template-parts/organizer/section-*.php` (разметка), `inc/organizer-menu.php` (меню), `assets/js/organizer.js` + `assets/css/organizer.css`, `functions.php` (страница, ассеты, sitemap), `tests/test-organizer-page.php`. |
| **Приёмка** | Гость уходит на `/login/`; пользователь без роли получает `403`; организатор видит кабинет, разделы переключаются без перезагрузки и открываются прямым адресом; страница исключена из sitemap. *(Формулировка «организатор с незаполненной анкетой — на `/organizer/complete/`» снята DEV-72: отдельной анкеты нет.)* |
| **Объём** | M |

#### DEV-37. Организатор после входа попадает в свой кабинет, а не в wp-admin — ЗАКРЫТО (15.09.2026)

> **Статус: выполнено 15.09.2026 (idpg-core 1.1.8, тема 1.0.80).**
>
> > ⚠️ **Аудит DEV-00A (19.09.2026):** в дереве **1.1.2 / 1.0.75** методы
> > `IDPG_Organizer_URL::after_login_url()` и `IDPG_Auth::redirect_organizer_to_cabinet()`
> > отсутствуют в рантайме (класс роутера не загружен, см. G1); хелпер
> > `idpg_organizer_after_login_url()` из `inc/organizer-menu.php` не определён (модуль не
> > подключён, см. G2). Сейчас организатор после входа попадает в `wp-admin`
> > (`IDPG_Auth::redirect_after_login()`, приоритет 10). См. `docs/archive/README_DEV-00A.md` §9–10.
>
> **1) Решение по адресу.** Новый метод `IDPG_Organizer_URL::after_login_url()`
> отдаёт URL кабинета только «чистому» организатору (роль `event_organizer` без
> `administrator`/`editor`), для остальных — пустую строку, то есть решение
> остаётся за вызывающим кодом. Поэтому метод безопасно подставлять в любую ветку
> редиректа: для администратора и редактора поведение не меняется. Расширяется
> фильтром `idpg_organizer_after_login_url`; добавлена константа `ROLE_ORGANIZER`.
>
> **2) Форма входа `/login/`** (`themes/idpg_core/page-login.php`) — три ветки:
> подтверждение регистрации по ссылке из письма, вход с формы и шаблонный редирект
> уже авторизованного пользователя. Ветка `event_organizer` в каждой теперь берёт
> адрес из нового хелпера темы `idpg_organizer_after_login_url()`
> (`inc/organizer-menu.php`), который делегирует в плагин и падает обратно на
> `admin_url()`, если ядро отключено.
>
> **3) Уже авторизован на `/login/`.** Реально эту ветку обслуживает
> `IDPG_Auth::redirect_if_logged_in()` на `template_redirect` — он срабатывает
> раньше шаблона, поэтому код в `page-login.php` до него не доходит. Метод
> дополнен: организатор → кабинет, остальные → главная (как было).
>
> **4) Вход мимо шаблона.** `IDPG_Auth::redirect_organizer_to_cabinet()` — новый
> фильтр `login_redirect` с приоритетом **20**: перекрывает решение
> `redirect_after_login()` (приоритет 10), не меняя его код. Покрывает вход через
> `wp-login.php` и любой плагин, применяющий этот фильтр.
>
> **5) Соцвход.** `IDPG_Auth::redirect_organizer_after_social_login()` подключён к
> фильтрам `wp_auth_ru_{yandex,vk,max}_after_login_redirect` плагина `idpg-auth`.
> Оговорка: провайдер применяет их только когда в сессии нет явно запрошенного
> адреса возврата; при наличии такого адреса приоритет остаётся за ним.
>
> **6) Ссылка «Панель»** в шапке (`themes/idpg_core/header.php`) — та же логика:
> организатору кабинет, администратору `wp-admin`.
>
> **7) Тесты.** В `tests/test-organizer-page.php` добавлены проверки адреса после
> входа (организатор, администратор, редактор, гость, пользователь без ролей,
> организатор с дополнительной ролью администратора), работы фильтра, регистрации
> хуков и прямого вызова метода `IDPG_Auth`.
>
> Проверено сквозными HTTP-тестами (временный mu-plugin авто-логина удалён,
> `mu-plugins/` пуст):
>
> | Сценарий | Результат |
> |---|---|
> | Вход с формы `/login/` — все 4 тестовых организатора (#72–#75) | `302` → `/organizer/` |
> | `kostya_shevkin` (#77), ветка «уже авторизован» на `/login/` | `302` → `/organizer/` |
> | Администратор (#1), та же ветка | `302` → `/` (не изменилось) |
> | Ссылка «Панель» в шапке: организатор / администратор | `/organizer/` / `/wp-admin/` |
> | `after_login_url()`: организатор / админ / editor / гость / без ролей / организатор+админ | кабинет / `''` / `''` / `''` / `''` / `''` |
> | Хуки `IDPG_Auth` | `login_redirect` 10 и 20, `wp_logout` 10, соцвход ×3 |
> | Регресс: `/`, `/privacy/`, `/terms/`, `/login/`, `/about/`, `/contacts/`, `/offer/` | `200`, размеры совпали с базой DEV-34 |
> | Регресс: `/organizer/`, `/lk/favorites/` гостем | `302` |
> | ~~Регресс: `/organizer/complete/` гостем~~ | ~~`302`~~ — адрес удалён DEV-72 |
> | Регресс: `/organizer/`, `/organizer/events/`, `/organizer/profile/`, `/lk/favorites/` организатором | `200` |
> | Регресс: `wp-admin` и `plugins.php` администратором | `200` |
>
> **Оговорка по тестам.** Окружение PHPUnit на стенде отсутствует
> (`%LOCALAPPDATA%\idpg-tests` не установлено), поэтому `bin\run-tests.cmd` не
> запускался — те же утверждения проверены разовым CLI-скриптом через
> `wp-load.php` (20 проверок, все пройдены). При появлении окружения тесты из
> `tests/test-organizer-page.php` выполнятся штатно.

| | |
|---|---|
| **Проблема** | После авторизации организатор попадал в `wp-admin`, а не в свой фронтенд-кабинет `/organizer/`; ссылка «Панель» в шапке вела туда же. |
| **Требование** | Существующие организаторы после входа должны попадать в свой личный кабинет. Администратор и редактор — как раньше. |
| **Реализация** | `includes/class-organizer-url.php` (`after_login_url()`, константа `ROLE_ORGANIZER`), `includes/class-auth.php` (фильтры `login_redirect` и соцвхода, правка `redirect_if_logged_in()`), `themes/idpg_core/page-login.php`, `themes/idpg_core/inc/organizer-menu.php` (хелпер), `themes/idpg_core/header.php` (ссылка «Панель»), `tests/test-organizer-page.php`. |
| **Приёмка** | Организатор после входа с формы и при заходе на `/login/` уже авторизованным попадает на `/organizer/`; администратор и редактор — по-прежнему в `wp-admin`; ссылка «Панель» ведёт организатора в кабинет. |
| **Объём** | S |

#### DEV-38. Рекламный баннер над подвалом скрыт в кабинете организатора — ЗАКРЫТО (15.09.2026)

> **Статус: выполнено 15.09.2026 (тема 1.0.81).**
>
> > ⚠️ **Аудит DEV-00A (19.09.2026):** фильтр `idpg_organizer_hide_before_footer_widgets()`
> > живёт в `inc/organizer-menu.php`, который в дереве **1.0.75** не подключён (`functions.php`
> > строки 351–381, G2). В кабинете организатора баннер над подвалом сейчас **не скрывается**.
> > См. `docs/archive/README_DEV-00A.md` §9–10.
>
> > ✅ **Замечание аудита снято 28.09.2026.** `inc/organizer-menu.php` подключён
> > в `functions.php` (строка 441), поэтому фильтр из этой карточки теперь
> > реально выполняется и баннер в кабинете скрывается. Причина была не в
> > самом фильтре, а в неподключённом файле.
> >
> > ⚠️ **Про нумерацию:** этот DEV-38 и DEV-38 из
> > `docs/archive/TZ_BACKLOG_PROMPTS_ORGANIZER_V14.md` («подключить меню и ассеты ЛК») —
> > разные задачи с одинаковым номером. Карточки не переименовывались, чтобы
> > не ломать ссылки в отчётах; статус V14-задач фиксируется в самом
> > V14-документе. В V14-порядке этот пункт закрыт.
>
> **1) Источник баннера.** Реклама выводится виджетом `idpg_ad-4`
> (картинка `promocode-timeweb-hosting-1.png`) в виджетной области «Подвал»
> (`area-podval`), у которой в опции `idpg_widget_areas` задана позиция
> `before-footer` и показ `all` (то есть на всех страницах). Модуль
> `inc/widget-areas.php` рендерит её через `idpg_widget_areas_render( 'before-footer' )`
> из `footer.php`. Это **не** `IDPG_Current_Event_Banner`: тот выводится только
> на позиции `hero` и только на главной, к подвалу отношения не имеет.
>
> **2) Решение — фильтр, без правки шаблонов.** Добавлена функция
> `idpg_organizer_hide_before_footer_widgets()` (`inc/organizer-menu.php`),
> подключённая к фильтру `sidebars_widgets`. В кабинете
> (`idpg_core_is_organizer_cabinet()`) она отдаёт пустой список виджетов для
> всех областей с позицией `before-footer`, поэтому `is_active_sidebar()` в
> `idpg_widget_areas_render()` возвращает `false` и разметка области не
> выводится вовсе. `footer.php` и модуль виджетных областей не менялись,
> настройки виджетов в админке не тронуты.
>
> **3) Область действия.** Правка срабатывает только внутри кабинета
> (`/organizer/*`). На главной, `/privacy/`, `/terms/`, `/about/` реклама
> остаётся. Админка исключена ранним возвратом (`is_admin()`).
>
> **4) Оговорка.** Фильтр глушит **все** виджеты любой области с позицией
> `before-footer`, а не только рекламный. Сейчас в «Подвале» один виджет;
> если там появится полезный (контакты, подписка), он тоже пропадёт в ЛК.
>
> Проверено сквозными HTTP-тестами (организатор alfa, #72; временные файлы и
> тестовая сессия удалены):
>
> | Сценарий | Результат |
> |---|---|
> | `/organizer/` — организатор | `idpg_ad-4` = 0, область `before-footer` = 0 |
> | `/organizer/events/` — организатор | `idpg_ad-4` = 0, область `before-footer` = 0 |
> | `/organizer/profile/` — организатор | `idpg_ad-4` = 0, область `before-footer` = 0 |
> | `/` — гость | `idpg_ad-4` = 1 (не изменилось) |
> | `/privacy/`, `/terms/`, `/about/` — гость | `idpg_ad-4` = 1 (не изменилось) |
> | `php -l` на `inc/organizer-menu.php` | без ошибок |

| | |
|---|---|
| **Проблема** | В кабинете организатора над подвалом выводился рекламный баннер (виджет в области «Подвал», позиция `before-footer`, показ «везде»). Для рабочего раздела он лишний. |
| **Требование** | Скрыть рекламный баннер над подвалом в личном кабинете организатора, не затрагивая остальные страницы и настройки виджетов. |
| **Реализация** | `themes/idpg_core/inc/organizer-menu.php` — `idpg_organizer_hide_before_footer_widgets()` на фильтре `sidebars_widgets`; `themes/idpg_core/functions.php` — bump `IDPG_VERSION` 1.0.80 → 1.0.81. |
| **Приёмка** | В кабинете (`/organizer/`, `/organizer/events/`, `/organizer/profile/`) блок над подвалом не выводится; на остальных страницах реклама на месте. |
| **Объём** | S |

#### DEV-50. Пайплайн-петля «соц-организатор»: флаг pending не ставится при соц-входе — СНЯТО DEV-72 (формально DEV-147)

> **Статус: выполнено 19.09.2026 (локальное дерево, ещё не релиз).**
> **Статус: закрыт DEV-72 — задача снята, а не исправлена.**
> Вся описанная ниже страховка жила ради слоя анкеты: хук `wp_login` ставил
> pending-флаг соц-организатору, а `get_access_decision()` гонял его в
> `/organizer/complete/`. В DEV-72 анкета удалена, поэтому страховка
> `event_organizer` + пустая анкета → pending **больше не нужна** и удалена
> вместе с `is_pending_organizer()`, `ACCESS_PENDING` и
> `_idpg_org_completed_at`. Тест `tests/test-organizer-pending.php` удалён.
> Флаг `idpg_org_pending` (без подчёркивания) сохранён — он принадлежит
> `IDPG_Organizer_Verify` и означает ожидание подтверждения email, а не анкеты.
> Ниже — историческое описание задачи.
>
> **Исходный статус: выполнено 19.09.2026 (локальное дерево, ещё не релиз).**
> В `includes/class-organizer-page.php`:
> - константы `META_PENDING` (`_idpg_org_pending`), `META_PENDING_ALT`
>   (`idpg_org_pending`), `META_COMPLETED_AT` (`_idpg_org_completed_at`),
>   `META_ORG_NAME` (`idpg_org_name`);
> - хук `wp_login` → `maybe_mark_social_organizer_pending()`: при первом входе
>   соц-организатора (роль `event_organizer` + мета `wp_auth_ru_provider` без
>   `_idpg_org_completed_at`) выставляет **оба** варианта pending-флага
>   (закрытие рассинхрона G7);
> - `is_pending()` переписана аддитивно: роль + явный флаг (оба ключа) **или**
>   страховка на лету — организатор без анкеты (`_idpg_org_completed_at`
>   отсутствует и `idpg_org_name` пусто) считается pending вне зависимости
>   от флага;
> - хелпер `is_questionnaire_complete()` — единый признак «анкета заполнена».
> В `includes/class-organizer-url.php` (IDPG_Organizer_URL): `is_pending_organizer()`
> получила ту же страховку (роль `event_organizer` + пустая анкета), что
> автоматически покрывает `get_access_decision()` (ACCESS_PENDING).
> В `includes/class-rest-auth.php`: приватный `is_pending_organizer()` — та же
> страховка; `handle_organizer_complete()` при успехе пишет
> `_idpg_org_completed_at` (timestamp), поэтому повторный вход не возвращает
> pending.
> Вход соц-кнопки проверен: оба провайдера idpg-auth шлют `do_action('wp_login')`
> (oauth-provider.php:205-208, class-wp-auth-ru-max.php:112), хук срабатывает.
> **Оговорка G1:** `class-organizer-url.php` в боевой загрузке всё ещё не
> подключён (аудит DEV-00A), поэтому страховка в `get_access_decision()`
> начнёт работать после применения обвязки DEV-34. Тест
> `tests/test-organizer-pending.php` подгружает класс явно.
> PHPUnit-окружение на стенде отсутствует — тест написан, но не прогнан.

| | |
|---|---|
| **Проблема** | Флаг, открывающий страницу `/organizer/complete/`, в соц-пути **не ставится**: соц-организатор сразу получает роль `event_organizer` и минует анкету (пайплайн-петля). |
| **Требование** | 1) Ставить оба `_idpg_org_pending` и `idpg_org_pending` при первом входе соц-организатора (роль + мета `wp_auth_ru_provider` + нет `_idpg_org_completed_at`); 2) страховка на лету в `get_access_decision()` и `is_pending()`: pending, если `_idpg_org_completed_at` нет и `idpg_org_name` пусто; 3) при успешном `POST /auth/organizer-complete` писать `_idpg_org_completed_at`; 4) PHPUnit `tests/test-organizer-pending.php`. |
| **Реализация** | `class-organizer-page.php` (хук `wp_login` + `is_pending()` + `is_questionnaire_complete()`), `class-organizer-url.php` (`is_pending_organizer()`), `class-rest-auth.php` (`is_pending_organizer()` + запись `_idpg_org_completed_at`), `tests/test-organizer-pending.php`. |
| **Приёмка** | Соц-организатор при первом входе помечается pending (оба ключа); без анкеты — остаётся pending даже без флага; после заполнения — allowed; повторный вход не возвращает pending. |
| **Объём** | S |

#### DEV-51. Соц-вход не выдаёт роль `event_organizer` — ЗАКРЫТО (30.09.2026)

> **Статус: ЗАКРЫТО.** Код был в локальном дереве с 19.09, но не попал в
> репозиторий (DEV-102 откатил изменение). Восстановлено и проверено 30.09.

**Проверка по коду:**

| Проверка | Результат |
|---|---|
| `new_user_role()` в `class-wp-auth-ru-provider.php` | `get_option('default_role','subscriber')` + `apply_filters('idpg_auth_new_user_role', $role, static::PROVIDER)` — **исправлено** |
| Дубль у MAX | **Удалён** (`class-wp-auth-ru-max.php`), базовый класс покрывает |
| VK / Яндекс | Наследуют базовый класс, `event_organizer` **не выдают** |
| Фильтр `idpg_auth_new_user_role` | Работает: тест `test_filter_overrides_role_for_vk` проходит |
| Тесты idpg-auth | **5 тестов / 9 утверждений, OK** |
| Полный набор idpg-core | **529 тестов / 1581 утверждение, падений 0** — регрессий нет |

**Что сделано:**
- `class-wp-auth-ru-provider.php:290` — `new_user_role()` возвращал жёсткую
  `'event_organizer'`; заменено на `get_option('default_role','subscriber')`
  + фильтр `idpg_auth_new_user_role` (2 аргумента: `$role`, `$provider`).
- Удалён дублирующий `new_user_role()` из `class-wp-auth-ru-max.php`
  (базовый класс делает то же самое + фильтр).

| | |
|---|---|
| **Проблема** | VK и Яндекс (наследники базового `WP_Auth_Ru_Provider`) создают нового пользователя с ролью `event_organizer` напрямую из `new_user_role()` (class-wp-auth-ru-provider.php, метод ~строка 290), минуя подтверждение email и анкету; MAX создавал `subscriber`. Рассинхрон ролей соц-входа. |
| **Требование** | 1) Соц-вход не выдаёт `event_organizer`: роль — из `default_role` или из настроек idpg-auth, но не организатор; 2) роль `event_organizer` — только через `IDPG_Organizer_Verify` после подтверждения email + анкеты; 3) замена опоры «соц-вход сразу даёт роль» на флаг pending (DEV-50) + verify; 4) не сломать REST `/auth/register` c `type=organizer`; 5) PHPUnit `tests/test-social-roles.php`. |
| **Реализация** | `class-wp-auth-ru-provider.php` (`new_user_role()` + фильтр `idpg_auth_new_user_role`), `plugins/idpg-auth/tests/` (bootstrap.php, phpunit.xml.dist, `test-social-roles.php`). |
| **Приёмка** | Соц-вход VK/Яндекс/MAX создаёт пользователя с `default_role` (subscriber), никогда `event_organizer`; роль организатора выдаётся только verify-потоком; `/auth/register type=organizer` работает как раньше. |
| **Объём** | S |

#### DEV-74. Роль организатора при входе через соцсеть (P1, S) — закрыто, перенесено в DEV-63

> **Статус: закрыто 28.09.2026 решением владельца. Кода не требуется.**
> Допущение 4 ТЗ V14 («соцпользователь сразу получает `event_organizer`, без
> письма») в исходной формулировке нереализуемо и отменено.

**Решение владельца:**

1. Роль при соцвходе **не меняется**. Письмо подтверждения email остаётся
   обязательным — вход через соцсеть не засчитывается как подтверждение владения
   аккаунтом.
2. Зарегистрированный и подтверждённый организатор после соцвхода должен попадать
   в кабинет `/organizer/`, а не на главную. Это единственное, что требовалось,
   и это задача **DEV-63**.

**Почему указанный в исходной формулировке хук не подходил (вывод сохранён):**
`idpg_auth_new_user_role` вызывается в `WP_Auth_Ru_Provider::login_or_create_user()`
только в ветке **создания** нового аккаунта (строка 234), тогда как найденные по ID
провайдера (строка 204) и по email (строка 216) возвращаются раньше. Фильтр не может
выразить «только для уже существующих» — он срабатывает ровно там, где роль выдавать
не нужно. Попытка реализации через него выдавала `event_organizer` каждому новому
посетителю с аккаунтом VK/Яндекс/MAX (проверено сквозным вызовом
`login_or_create_user()`), и была откачена. Изменений в коде и в `docs/PROJECT.md (раздел 1)`
не осталось; временный тест `tests/test-organizer-social-role.php` удалён.

**Побочно вскрытый конфликт с DEV-50/DEV-51 — закрыт DEV-72.**
`IDPG_Organizer_Page::maybe_mark_social_organizer_pending()` на хуке `wp_login` ставил
`_idpg_org_pending` / `idpg_org_pending` любому пользователю с ролью `event_organizer`
и meta `wp_auth_ru_provider`, у которого нет `_idpg_org_completed_at`. Поскольку meta
пишется при создании пользователя (class-wp-auth-ru-provider.php, передача роли
~строка 231), то есть **до**
`wp_login`, новый соц-организатор помечался pending и уходил на `/organizer/complete/`
вместо кабинета. В DEV-72 весь этот механизм удалён вместе с анкетой, поэтому новый
соц-организатор попадает в кабинет напрямую.

| | |
|---|---|
| **Проблема** | Допущение 4 ТЗ V14 не имело задачи. Предложенный хук `idpg_auth_new_user_role` пригоден только для ветки создания нового аккаунта, поэтому выдача роли «только зарегистрированным» через него невыразима. |
| **Решение** | Роль не трогать, подтверждение email обязательно. Требование сводится к редиректу в кабинет после соцвхода — DEV-63. |
| **Объём** | S |
#### DEV-52. Поднять PHPUnit-окружение и прогнать тесты организатора — ЗАКРЫТО (формально DEV-147)

> **Статус: выполнено 19.09.2026 (локальное дерево, ещё не релиз).**
> На стенде (Windows, Laragon, PHP 8.4/MySQL 8.4) поднята
> wordpress-tests-lib через shallow-git клон wordpress-develop 7.1.1
> (svn отсутствует) в `C:\Users\Costic\AppData\Local\Temp\opencode\idpg-tests\`,
> `wp-tests-config.php` настроен на БД `wordpress_test` (root/без пароля).
> PHPUnit 9.6.36 + `yoast/phpunit-polyfills` уже были в `vendor/` idpg-core.
> **Прогон:** idpg-core — 85 тестов, 210 утверждений, зелёный; idpg-auth —
> 5 тестов, 9 утверждений, зелёный (в т.ч. `test-social-roles.php`, DEV-51).
> **Исправлено в тестах** (код не менялся): `test-roles.php` —
> `publish_events` у организатора `false` (модерация админом, class-roles.php, ~строка 209);
> `test-organizer-page.php` — организатор без анкеты теперь `pending`
> (страховка DEV-50), тест allowed заполняет анкету; кабинетный
> `IDPG_Auth::redirect_organizer_to_cabinet()` не существует (обвязка DEV-34,
> G1) — тест переведён на фактический `redirect_after_login()`.
> CI: добавлен `.circleci/config.yml` (PHP 8.4 + MySQL 8.4, install-wp-tests.sh,
> composer install, прогон обоих плагинов). PHPCS остаётся в DEV-27.
> Результат зафиксирован в `plugins/idpg-core/CHANGELOG.md`.

| | |
|---|---|
| **Проблема** | На машине нет PHPUnit-окружения; тесты (organizer-page, organizer-pending, organizer-menu и др.) написаны, но не прогонялись; есть риск падений. |
| **Требование** | 1) Установить PHPUnit и wp-phpunit (по `bin/install-wp-tests.sh`); 2) настроить `phpunit.xml.dist`; 3) прогнать все тесты и зафиксировать результат; 4) исправить падающие тесты — только тесты, код не трогать; 5) добавить в CI (см. DEV-27). |
| **Реализация** | wordpress-tests-lib (клон wordpress-develop 7.1.1), `C:\...\opencode\idpg-tests\`, БД `wordpress_test`, правки `tests/test-roles.php` и `tests/test-organizer-page.php`, `.circleci/config.yml`, `plugins/idpg-core/CHANGELOG.md`. |
| **Приёмка** | `phpunit` проходит зелёным (idpg-core 85, idpg-auth 5); результат зафиксирован в CHANGELOG. |
| **Объём** | S/M |

#### DEV-14. Судьба личного кабинета `/lk/`

> **Статус: решён 29.09.2026 — выбран вариант А (подтвердить отказ).**
> Решение владельца: ЛК не возвращается. Вариант Б (восстановление кабинета
> вместе с G11) снят с работы. Ниже — что фактически сделано.

**Что расходилось с кодом.** ЛК удалён в 1.1.0, и `themes/idpg_core/README.md`
это отражал, но `docs/archive/docs/archive/PLUGIN_TZ.md` продолжал описывать кабинет как рабочий.
Сверка с диском и с REST-пространством показала, что документация расходилась
с кодом в шести местах:

| Место в `docs/archive/PLUGIN_TZ.md` | Было | Стало |
|---|---|---|
| §3.2 subscriber | «Доступен фронтенд ЛК `/lk/`. Из профиля: имя, email, пароль, аватар» | ЛК удалён, прав нет; работает только избранное |
| §6.2 таблица REST | 5 маршрутов `/user/profile`, `/user/avatar`, `/user/city-events`… | таблица маршрутов `/user/*` отсутствует как таковая |
| §7 дерево шаблонов | `page-lk.php` в списке шаблонов темы | строки нет, файл не существует |
| §10 «ФРОНТЕНД-КАБИНЕТ» | профиль, статистика, события в городе описаны как функции | раздел помечен неактуальным, исходное ТЗ свёрнуто в `<details>` |
| §12 безопасность | `can_access_lk`, `upload_files` «для аватара» | `can_access_lk` удалён, аватар не реализован |
| §12 дерево файлов | `page-lk.php` в перечне | строки нет |

**Про таблицу REST важно отдельно.** Раздел 6.2 описывал пять маршрутов
`/user/*`, которых в проекте нет вообще. Сверка с живым
`GET /wp-json/idpg/v1` показала 27 реальных маршрутов, и ни одного `/user/*` —
то есть таблица была не устаревшей, а вымышленной. Вместо попытки привести её
в порядок по памяти §6.2 переписан по факту: перечислен реальный состав
namespace с путями, методами и разделением прав. Заодно в дереве файлов
`class-banners.php` заменён на существующий `class-current-event-banner.php`
(и добавлены `class-rest-organizer.php`, `class-promotion.php`).

**Проверка соответствия коду.** `page-lk.php` в теме нет; `can_access_lk` и
редиректов на `/lk/` в `class-auth.php` нет. Живой `GET /wp-json/idpg/v1` —
27 маршрутов, `/user/*` среди них отсутствуют. `/lk/` отдаёт `404` (страницы
нет в БД), `/lk/favorites/` — `302` на `/login/` для гостя, то есть избранное
живо и это rewrite плагина, а не остаток кабинета.

**Открыто.** Ничего. G11 закрыт тем же решением: выбор аватара существовал
только внутри ЛК, который не возвращается.

| | |
|---|---|
| **Проблема** | ЛК удалён в 1.1.0 (страница, REST `/user/*`, редиректы), но README темы и `DOCUMENTATION.md` продолжают его описывать; осталось только `/lk/favorites/`. Неясно, планируется ли возврат. |
| **Требование (А)** | Подтвердить отказ: обновить README темы и DOCUMENTATION.md, убрать упоминания `/lk/`, зафиксировать, что подписчик после входа идёт на главную. |
| **Требование (Б)** | Вернуть ЛК: шаблон `/lk/`, профиль (имя, email, пароль, аватар), статистика, REST `/user/*` с permission-колбэком и nonce. |
| **Реализация** | А — документация. Б — `page-lk.php`, `class-rest-api.php` (маршруты), `class-auth.php` (редиректы), автосоздание страницы в `functions.php`. |
| **Приёмка** | А: документация соответствует коду. Б: подписчик заходит в ЛК, меняет профиль и пароль (с проверкой текущего пароля и уничтожением прочих сессий), загружает аватар. |
| **Объём** | S / L |

#### DEV-15. Логотип email-рассылки

> **ЗАКРЫТО (DEV-15, 30.09.2026).** Приёмка выполнена полностью. Подробности — §20.

| | |
|---|---|
| **Проблема** | `email-logo.png` не загружен; письма идут без логотипа. |
| **Требование** | Загрузить логотип в `uploads/` либо задать адрес фильтром `idpg_email_logo_url`; при отсутствии — письма не должны содержать битую картинку. |
| **Реализация** | `class-auth-emails.php`, `uploads/`. |
| **Приёмка** | Письма приходят с корректным логотипом; при отсутствии файла письмо рендерится без разрыва вёрстки. |
| **Объём** | S |
| **Статус** | ✅ ЗАКРЫТО 30.09.2026 — guard на существование файла добавлен в `get_logo_url()` (`class-auth-emails.php`), покрыт `tests/test-email-logo.php` (10 тестов). Подробности — §20 |

#### DEV-16. Автообновление плагина idpg-auth отключено

| | |
|---|---|
| **Проблема** | `class-wp-auth-ru-updater.php` присутствует, но не подключается в `idpg-auth.php` — обновления с GitHub не проверяются. |
| **Требование** | Подключить файл и инициализировать апдейтер, если он нужен; либо удалить файл, если обновления не планируются (решение владельца). |
| **Реализация** | `plugins/idpg-auth/idpg-auth.php` (`require_once` + инициализация) или удаление файла. |
| **Приёмка** | При подключении WordPress видит доступное обновление по тегу релиза; при отказе — файла нет и код не содержит мёртвых ссылок. |
| **Объём** | S |

---

### 4. ФУНКЦИОНАЛЬНЫЕ УЛУЧШЕНИЯ (P2)

#### DEV-17. Счётчик кликов по билетным ссылкам

| | |
|---|---|
| **Проблема** | `IDPG_Views::increment_clicks()` (class-views.php:182) объявлен, но **нигде не вызывается** — `_idpg_clicks` меняется только вручную из админки, статистика по кликам недостоверна. |
| **Требование** | Учитывать клик при переходе на продажу билетов (внешняя ссылка, сеанс, виджет Яндекс.Билетов) — без блокировки перехода, с защитой от накрутки. |
| **Реализация** | `class-views.php` + REST-маршрут `POST /track/click` (или admin-ajax) + перехват в `single-event.php` / `content-event.php` / `ticket-sync`-кликах; `assets/js/` — обработчик. |
| **Приёмка** | Клик по кнопке билетов увеличивает `_idpg_clicks` ровно один раз за переход; повторный клик того же пользователя в короткий интервал не считается; переход не задерживается. |
| **Объём** | M |

#### DEV-18. Ручной запуск синхронизации билетов

| | |
|---|---|
| **Проблема** | `run_sync_now()` (class-ticket-sync.php:322) не вызывается ни из админки, ни из WP-CLI. |
| **Требование** | Кнопка «Синхронизировать сейчас» на странице настроек IDPG + WP-CLI команда `wp idpg sync-tickets`, с выводом результата и защитой nonce/capabilities. |
| **Реализация** | `class-ticket-sync.php`, `class-settings-page.php`, регистрация WP-CLI команды. |
| **Приёмка** | Запуск из админки и из CLI отрабатывает, пишет результат (сколько событий обновлено, ошибки), не дублирует cron-задачу. |
| **Объём** | M |

#### DEV-19. Подтверждение города, определённого по IP

| | |
|---|---|
| **Проблема** | Город по IP ставится автоматически без подтверждения пользователя (есть только пометка в виджете). Возможны ошибочные определения (VPN, мобильный оператор). |
| **Требование** | Показывать ненавязчивое подтверждение («Ваш город — Ижевск? Да / Выбрать другой») при первом визите, когда город определён по IP, а не выбран вручную; запоминать ответ. |
| **Реализация** | `class-city-selector-widget.php`, `class-city-filter.php` (флаг источника), `header.php`, `city-selector.js/css`. |
| **Приёмка** | Подтверждение показывается один раз; отказ открывает выбор города; повторные визиты не беспокоят; выбранный вручную город не переспрашивается. |
| **Объём** | M |

> **Проверено 01.10.2026: подтверждение в UI есть и работает по всем четырём пунктам приёмки.**
> Рендер — `IDPG_City_Selector_Widget::render_confirmation_bar( $target )`
> (`class-city-selector-widget.php:165`): выход пустой, если
> `IDPG_City_Filter::needs_city_confirmation()` ложно, а тот (`class-city-filter.php:212`)
> требует `was_detected_from_ip()` **и** `! is_city_confirmed()` — то есть блок не появляется
> для ручного выбора и после ответа. В теме вызывается дважды: `header.php:32` (`modal`,
> вывод на `:107`) и `header.php:35` (`panel`, вывод на `:224`) плюс сам виджет
> (`class-city-selector-widget.php:377`) — то есть панель есть и в мобильной модалке,
> и в выпадающем списке. «Да» — ссылка с `wp_nonce_url()` на
> `IDPG_City_Filter::CONFIRM_ACTION`; «Другой город» — кнопка с
> `data-idpg-city-open`; обработчики вешаются на **все** блоки через
> `document.querySelectorAll('.idpg-city-confirm')` (`navigation.js:175`), что и снимает
> исходный дефект с дублями DOM-id (уникальный суффикс даёт `self::$confirmation_bars`).
> Регрессии: `tests/test-city-confirmation.php` — 22 теста / 50 assertions.
>
> **Повторный прогон 01.10.2026 выполнить не удалось:** на машине отсутствует библиотека
> `wordpress-tests-lib` (раньше была в `%TEMP%\wordpress-tests-lib`), PHPUnit падает на bootstrap.
> PHPUnit 9.6.36 рабочий, нужен `plugins/idpg-core/bin/install-wp-tests.cmd`. Число прогона
> выше (655/1888) — результат **до** исчезновения библиотеки; после него менялась только
> документация. Статически правка чистая: `php -l` — OK, PHPCS в строках 165–213 — 0 нарушений.

#### DEV-20. Документирование REST API

| | |
|---|---|
| **Проблема** | Описание API есть только в docblock'ах; OpenAPI/Postman-коллекции нет. Внешним потребителям (мобильное приложение, партнёрские виджеты) интегрироваться неудобно. |
| **Требование** | OpenAPI 3.0-спецификация на 23 маршрута `idpg/v1` (+ 5 маршрутов `idpg-auth`) + коллекция Postman; описания параметров, прав и примеров ответов. |
| **Реализация** | `docs/openapi.yaml`, `docs/postman_collection.json`, ссылки из `plugins/idpg-core/README.md`. |
| **Приёмка** | Спецификация валидна (lint), соответствует фактическим маршрутам и параметрам. |
| **Объём** | M |

#### DEV-21. OneTap-виджет VK ID (решение владельца)

| | |
|---|---|
| **Проблема** | В README ядра и CHANGELOG описаны OneTap-виджет и скрипт `vkid-onetap.js`; в коде темы и плагина упоминаний `vkid` нет — виджет убран, вход идёт через кнопки `idpg-auth` (ВКонтакте/Яндекс/MAX). |
| **Требование** | Либо зафиксировать отказ (обновить README и CHANGELOG), либо вернуть OneTap как дополнительный сценарий входа поверх `idpg-auth`. |
| **Приёмка** | Документация соответствует коду; при возврате — виджет рендерится на `/login/`, вход завершается через REST провайдера. |
| **Объём** | S / M |

---

### 5. ТЕХНИЧЕСКИЙ ДОЛГ (P2–P3)

#### DEV-22. Дублирование логики между классами

| | |
|---|---|
| **Проблема** | Дублируются: `get_client_ip()` — в `class-auth-rate-limiter.php` и `class-views.php`; `get_city_tree()` — в `class-city-filter.php` и `class-city-selector-widget.php`; логика получения площадки — в `class-current-event-banner.php`, `class-ical-export.php`, `class-meta.php`, `class-rest-api.php`. |
| **Требование** | Вынести общие реализации в один сервис и вызывать его из остальных мест **без изменения публичных сигнатур** (обёртки-делегаты). |
| **Реализация** | новый `includes/class-helpers.php` (`IDPG_Helpers`: IP клиента, дерево городов) + делегирование из существующих методов; площадка — использовать `IDPG_Template_Helper` / `IDPG_Media` как единый источник. |
| **Приёмка** | Поведение всех потребителей не изменилось (проверить существующими PHPUnit-тестами), дублирующие тела методов устранены. |
| **Объём** | M |

#### DEV-23. Расхождения версий и заголовков

> **Статус: ЗАКРЫТО 30.09.2026 (DEV-138 + DEV-139).**
> Версии темы синхронизированы (`IDPG_VERSION` = `style.css` = **1.0.78**), сторож —
> `tests/test-theme-version.php`. Заголовок `idpg-core.php` приведён к единому виду
> с `idpg-auth`: добавлен `Tested up to: 6.7`, сторож — `tests/test-plugin-header.php`.
> Оба поля (`Requires at least`, `Tested up to`, `Requires PHP`) присутствуют у всех
> компонентов.

| | |
|---|---|
| **Проблема** | `themes/idpg_core/style.css` — версия 1.0.73, `IDPG_VERSION` в `functions.php` — 1.0.75; версии темы и плагина обновляются вручную и расходятся. |
| **Требование** | Единый источник версии: `IDPG_VERSION` синхронизирован с `style.css` (или берётся из заголовка через `wp_get_theme()`); в заголовке плагина добавить `Tested up to` и `Requires at least`/`Requires PHP` в актуальном виде. |
| **Реализация** | `themes/idpg_core/functions.php`, `themes/idpg_core/style.css`, `plugins/idpg-core/idpg-core.php`. |
| **Приёмка** | Версии совпадают; кэш ассетов сбрасывается при обновлении темы. |
| **Объём** | S |

#### DEV-24. Carbon Fields: зависимость ядра от темы

| | |
|---|---|
| **Проблема** | Библиотека Carbon Fields лежит в `vendor/` и плагина, и темы; тема исторически была источником бутстрапа, плагин дублирует загрузку с guard'ом. Возможны конфликты версий при обновлении. |
| **Требование** | Определить единственный источник библиотеки (плагин или must-use), убрать дублирующую загрузку из темы, зафиксировать версию Carbon Fields в `composer.json` плагина. |
| **Реализация** | `idpg-core.php` (boot уже есть), `themes/idpg_core/functions.php` (закомментированный boot), `composer.json`/`composer.lock`. |
| **Приёмка** | Поля Carbon Fields работают при активной теме-родителе и дочерней; второй экземпляр библиотеки не подключается; предупреждений в `debug.log` нет. |
| **Объём** | M |

#### DEV-25. Судьба `IDPG_Template_Helper`

| | |
|---|---|
| **Проблема** | Класс подключён и используется частично (`inc/template-tags.php`, `class-meta.php`, `class-views.php`), при этом шаблоны в основном рендерят инлайн — единой точки рендера нет. |
| **Требование** | Либо расширить использование класса и перевести повторяющиеся блоки вывода на него, либо признать его вспомогательным и убрать из документации как «единый слой рендера». |
| **Приёмка** | Решение зафиксировано в `docs/PROJECT.md (раздел 1)`; мёртвых ссылок в документации нет. |
| **Объём** | S / M |

#### DEV-26. Сборочный процесс темы и ассетов

| | |
|---|---|
| **Проблема** | SCSS-исходники есть, но `style.css` собирается вручную; `node_modules` в репозиторий не попадает (хорошо), однако шага сборки в CI нет, и легко получить расхождение исходников и собранного CSS. |
| **Требование** | Добавить в CI проверку, что `style.css` соответствует `assets/scss/style.scss` (сборка + diff), и запретить ручные правки собранного файла. |
| **Реализация** | `.circleci/config.yml` (или иной CI), `themes/idpg_core/package.json` (скрипт `build`/`check:css`). |
| **Приёмка** | При расхождении сборка падает; при совпадении — проходит. |
| **Объём** | M |
| **Частично сделано** | **DEV-144**: скрипт `npm run check:css` создан (сборка в `style.css.tmp` + сверка правил через `bin/check-css-build.mjs`, код возврата 1 при расхождении), прямая перезапись эталона вынесена в `compile:css:apply`. Осталось: подключить `check:css` (или PHPUnit-сторож `Test_Css_Build`) шагом в CI — упирается в привязку CircleCI (DEV-136). |

#### DEV-27. Линтеры и тесты в CI

| | |
|---|---|
| **Проблема** | Есть `.phpcs.xml.dist` и 9 файлов PHPUnit-тестов, но прогон в CI не гарантирован; новые изменения не проверяются автоматически. |
| **Требование** | Запускать PHPCS (WordPress-стандарт) и PHPUnit на каждый пуш; покрыть тестами новый функционал из DEV-01, DEV-04, DEV-05, DEV-17. |
| **Реализация** | `.circleci/config.yml`, `phpunit.xml.dist`, `tests/`. |
| **Приёмка** | CI зелёный на текущем коде; новые тесты проходят; PHPCS не даёт новых ошибок относительно текущего базлайна. |
| **Объём** | M |

#### DEV-27A. PHPCS в CI с baseline

> **Выполнено 19.09.2026.** Уточнение DEV-27: линтер в CI без «красного навсегда» на легаси-коде.
>
> **⚠ Приёмка ниже оказалась неверной, поправлено 01.10.2026 (DEV-183).** Заявленные «exit 0 по
> всем трём компонентам» на тот момент не достигались: baseline **не находился** пакетом вообще,
> поэтому списывалось 0 записей и прогон показывал полный долг. Настоящая причина — не разделители
> путей и не экранирование, а **имя файла** (см. ниже).

| | |
|---|---|
| **Проблема** | Job `phpcs` в `.circleci/config.yml` фактически не запускался: PHPCS ставился через `composer create-project squizlabs/php_codesniffer`, после чего `composer require wp-coding-standards/wpcs` падал (корневой пакет проекта — сам `php_codesniffer`, его версия определяется как `1.0.0`). Плюс первый прогон на легаси-коде даёт ~4,3 тыс. нарушений — без baseline job был бы красным всегда. |
| **Требование** | 1) Замерить объём нарушений по трём компонентам; 2) сгенерировать и закоммитить baseline; 3) запускать PHPCS с baseline так, чтобы новая ошибка валила сборку, а предупреждения — нет; 4) зелёный на текущем коде / красный на заведомой ошибке; 5) обновить документацию. |
| **Реализация** | Baseline-механизм — `forrest79/phpcs-ignores` (baseline «в стиле PHPStan»; был предусмотрен в CI, но не подключён). Генерация: `phpcs --standard=.phpcs.xml.dist --report=\Forrest79\PhpCsIgnores\BaselineReport --report-file=phpcs-baseline.neon`. Применение: `--bootstrap=<tools>/vendor/forrest79/phpcs-ignores/src/bootstrap.php` (bootstrap сам находит `phpcs-baseline.neon` рядом с ruleset/cwd). Файлы baseline: `plugins/idpg-core/phpcs-baseline.neon`, `plugins/idpg-auth/phpcs-baseline.neon`, `themes/idpg_core/phpcs-baseline.neon`. В `.circleci/config.yml` install-шаг переписан на `composer init` + `composer require` в `/tmp/phpcs-tools` (PHPCS — обычная зависимость: путь `vendor/squizlabs/php_codesniffer/...` обязателен для инъекции плагина), прогон с `--runtime-set ignore_warnings_on_exit 1`. |
| **Приёмка** | ~~Локально (PHPCS 3.13.6, WPCS 3.4.1, PHPCompatibilityWP 2.1.8): `idpg-core`, `idpg-auth`, тема `idpg_core` — exit 0~~ **не достигнута, см. DEV-183.** После починки имени файла (01.10.2026): 195 / 4 / 77 ошибок — это реальный долг, exit 2. Зелёный статус потребует либо внесения текущих нарушений в baseline, либо их починки. |
| **Объём** | S |

> **Почему не `phpcs --baseline`.** В PHP_CodeSniffer **нет** встроенного baseline: ни
> `--report=baseline`, ни опции `--baseline` нет ни в 3.x, ни в 4.x (upstream issue #137 открыт,
> в wiki страницы Baselines нет). Поэтому используется сторонний плагин — ровно тот, что был
> изначально прописан в `config.yml`.
>
> **Оговорки по baseline-плагину.** (1) На Windows инъекция плагина не срабатывает: он сверяет
> путь по `/`, а PHPCS отдаёт `\` — на Linux (CI) работает; локальные прогоны на Windows baseline
> не применяют. (1а) **Устарело 01.10.2026 (DEV-183):** это чинил патч `0001` в
> `bin/phpcs-patches/`, сейчас работает на обеих платформах. (2) Плагин при генерации заменяет
> **реальные** табы в сообщении на литерал `\t`,
> и такие записи никогда не сопоставляются; одна такая запись (многострочный SQL в
> `includes/class-event-calendar-widget.php`) была поправлена в baseline вручную. **При следующей
> перегенерации baseline её нужно поправить снова** — заменить в этой записи литералы `\t`
> на реальные символы таба (иначе job упадёт на 1 ошибке).
>
> **Устарело (19.09.2026, DEV-27B).** Записи с `\t` в baseline больше нет: SQL-строка
> в `class-event-calendar-widget.php` переведена в одинарные кавычки, поэтому нарушение
> устранено в коде, а не заглушено в baseline. Причина дефекта и локальный патч
> генератора описаны в DEV-27B ниже. Требование «проверять baseline на литералы `\t`
> после перегенерации» сохраняется.

#### DEV-27B. Постепенная чистка PHPCS

> **В работе, начато 19.09.2026.** Уточнение DEV-27/DEV-27A: baseline со временем
> рассасывается — по одному файлу за заход.

| | |
|---|---|
| **Проблема** | Baseline фиксирует унаследованные нарушения, поэтому CI не ловит регрессии в этих файлах: любое новое нарушение в файле из baseline просто «сливается» с уже записанными. |
| **Требование** | 1) Baseline разбит по компонентам (idpg-core / idpg-auth / тема); 2) чистка по одному файлу за заход: убрать нарушения → удалить записи из baseline → прогнать PHPCS локально → закоммитить; 3) приоритет: сначала критичное (escape, nonce, SQL), потом стилевое (табы, Yoda, `array()`); 4) после каждого захода CI зелёный. |
| **Ограничения** | Код не менять функционально (только стиль и безопасность); каждый заход — отдельный коммит; чистку не смешивать с фичами. |
| **Объём** | M (растянутая задача) |

**Разбивка baseline уже сделана в DEV-27A** — три файла (переименованы 01.10.2026 по правилу
пакета, см. DEV-183 — имя должно начинаться с имени ruleset-файла):
`plugins/idpg-core/.phpcs.xml.dist-baseline.neon` (**514**),
`plugins/idpg-auth/.phpcs.xml.dist-baseline.neon` (**389**),
`themes/idpg_core/.phpcs.xml.dist-baseline.neon` (**376**).
История: 19.09.2026 — **528 / 424 / 398** (520 → 528 после DEV-59: добавлены записи для двух
тестов, привезённых из `main`); 01.10.2026 — **514 / 389 / 376** после DEV-186 (убрано 71
протухшая запись, `count` не менялись).

##### Объём критичных нарушений в baseline (замер 19.09.2026)

| Компонент | Escape / sanitize / nonce / SQL | Всего нарушений |
|---|---|---|
| `idpg-core` | 84 | 532 записи / 644 ошибки + 421 предупреждение |
| `idpg-auth` | 143 | 424 записи / 2814 + 241 |
| тема `idpg_core` | 26 | 398 записей / 829 + 211 |

Порядок работы: сначала файлы с наибольшей плотностью критичного при малом общем объёме
(их можно закрыть целиком за один заход), потом остальное.

##### Журнал проходов

| # | Компонент | Файл | Что сделано | Baseline |
|---|---|---|---|---|
| 1 | тема | `inc/theme-helpers.php` | nonce: `wp_unslash()` + `sanitize_key()`; докблоки `@param` | −4 записи |
| 2 | idpg-core | `includes/class-event-calendar-widget.php` | вторая часть SQL-строки переведена в одинарные кавычки (интерполяции нет) | −1 запись, снят красный job |
| 3 | idpg-core | `includes/class-privacy.php` | `wp_unslash()` + `sanitize_text_field()` для двух `$_POST`-чекбоксов; обоснованные inline-игноры на флаг `$_GET` и `fwrite`/`fclose` на `php://output`; выравнивание `array()` и `=` | −11 записей (16 нарушений → 1) |

**Осознанно остаётся в baseline.** Не всякую запись можно удалить:
`WordPress.Files.FileName.InvalidClassFileName` требует переименования файла
(`class-privacy.php` → `class-idpg-privacy.php`), `Generic.CodeAnalysis.UnusedFunctionParameter`
— изменения сигнатур, `WordPress.DB.SlowDBQuery` и `WordPress.DateTime.RestrictedFunctions`
— функциональных правок. Такие записи переживают чистку; цель захода — снять всё, что
снимается стилем и безопасностью, а не «обнулить файл любой ценой».

##### Обязательный порядок действий при заходе

1. `php -l` по файлу (8.2 CLI) — до и после.
2. `phpcs --standard=.phpcs.xml.dist <файл>` **без** baseline — увидеть реальный список
   (baseline может быть устаревшим, ориентироваться на него нельзя).
3. Правки: критичное (escape/nonce/SQL) → стиль. Обоснованные случаи — inline-игнор
   с комментарием-причиной, а не запись в baseline.
4. Удалить из baseline записи по этому файлу, кроме осознанно оставленных.
5. `phpcs` с baseline по компоненту — **exit 0**.
6. Сверить baseline с выводом генератора (см. ниже) и закоммитить только файл + baseline.

##### Проверка, что baseline — ровно вывод генератора

```bash
cd plugins/idpg-core   # или idpg-auth / themes/idpg_core
php <tools>/vendor/bin/phpcs --standard=.phpcs.xml.dist \
  --report="\Forrest79\PhpCsIgnores\BaselineReport" \
  --report-file=<tools>/reports/regen.neon --no-colors
## затем побайтово сравнить regen.neon с закоммиченным .phpcs.xml.dist-baseline.neon
## ВАЖНО (DEV-183): генерировать сразу под этим именем — иначе пакет не найдёт файл
## при следующем прогоне, и baseline молча перестанет списываться.
```

Совпадение означает, что в baseline нет ни «висячих» записей, ни ручных правок,
которые потеряются при следующей перегенерации. **На Windows сравнение делать
с нормализацией `\r\n` → `\n`**: генератор завершает файл `PHP_EOL`, поэтому
последняя строка регенерированного файла содержит `\r`.

##### Грабли

- **Литерал `\t` в baseline (наследие DEV-27A).** Генератор `BaselineReport.php`
  (плагин `forrest79/phpcs-ignores`) экранировал реальный таб в сообщении сниффа
  как `\t`, а `Ignores.php` читает baseline через `Nette\Neon`, где **одинарные
  кавычки не раскрывают escape-последовательности**. Запись становится
  «вечно несовпадающей»: job падает на 1 ошибке в файле, который никто не трогал
  (`1 error + 1 warning`, exit 2). Единственная такая запись была для
  многострочного SQL в `class-event-calendar-widget.php`; снята в проходе 2 —
  не правкой baseline, а переводом строки в одинарные кавычки, поэтому не вернётся.
  **Локальный патч** (в `phpcs-tools2`, вне репозитория): из
  `vendor/forrest79/phpcs-ignores/src/BaselineReport.php` убран `str_replace("\t", '\t', …)`.
  При переустановке `vendor` патч теряется — после любой перегенерации проверять
  baseline на литералы `\t`.
- **Локальный `vendor` в `phpcs-tools2` хрупкий.** Он уже дважды терял файлы:
  `Squiz/Sniffs/Strings/DoubleQuoteUsageSniff.php` (без него падает любой прогон:
  `Referenced sniff … does not exist`) и `src/File.php` у `phpcs-ignores`
  (без него `Fatal error: Class "Forrest79\PhpCsIgnores\File" not found`).
  Оба восстанавливаются из кэша Composer
  (`~/AppData/Local/Composer/files/<vendor>/<package>/*.zip`) без сети.
- **Baseline применяется на Windows** — вопреки оговорке в DEV-27A. Для этого в
  `vendor/forrest79/phpcs-ignores/src/PhpCsInjections.php` перед `str_ends_with()` путь
  нормализуется: `str_ends_with( str_replace( '\\', '/', $path ), … )`. Без этого
  сравнение с `vendor/…/FileList.php` не срабатывает (PHP на Windows отдаёт путь с `\`),
  инъекция класса `File` не происходит и baseline **не применяется вовсе** — PHPCS
  показывает все ~4 тыс. унаследованных нарушений, exit 2.
  *(Уточнено 19.09.2026, DEV-54: это **реальный локальный патч** — в апстриме v0.5.0
  нормализации нет, проверено побайтовым сравнением с пакетом из кэша Composer.
  Ранее в этом же разделе патч был ошибочно объявлен штатным upstream-кодом.
  Оба патча теперь хранятся в репозитории — см. DEV-54.)*
- **Пути в baseline с обратными слэшами валидны и на Linux.** Это отдельное свойство
  самого плагина: `Ignores::getAbsolutePath()` нормализует и `/`, и `\` к
  `DIRECTORY_SEPARATOR`, поэтому записи вида `path: includes\class-auth.php` сопоставляются
  и на Linux-CI. Патчей не требует.
- **Разделение коммитов при общем файле baseline.** Baseline компонента — один файл,
  а заходов несколько, поэтому «проход = коммит» требует промежуточного состояния:
  временно вернуть правки кода файла-предыдущего-прохода, перегенерировать baseline,
  закоммитить, вернуть правки, перегенерировать снова. Иначе в коммит попадёт
  удаление записей за два прохода сразу.

##### Состояние CI

На 19.09.2026 job `phpcs` **проверен на чистом клоне** (см. DEV-53): `git clone` даёт
215 файлов, прогон с baseline по трём компонентам — **exit 0**. Job `phpunit` на клоне
локально не воспроизводится (нужны WordPress test library и MySQL) — проверяется
только в CI.

Осталось для полного закрытия DEV-53: убедиться, что оба job'а зелёные на CircleCI.
Remote настроен, первый push выполнен 19.09.2026 **отдельной веткой** `dev/phpcs-baseline`,
ветка `main` не тронута (причина — в разделе «Remote и первый push» ниже). DEV-59 забрал
из `main` 29 уникальных файлов, но push в `main` отклонён как `non-fast-forward` —
выбор стратегии слияния остаётся за владельцем. До проверки CI требование «CI зелёный»
выполняется как «локальный прогон на чистом клоне даёт exit 0».

#### DEV-183 (P0, S). Baseline не применялся: имя файла не по правилу пакета

> **Закрыто 01.10.2026.** Найдено при разборе DEV-19/DEV-28. Постановка карточки в постановке
> DEV-183 (backslash → slash) была **неверной** — опровергнута экспериментом.

| | |
|---|---|
| **Проблема** | Job `phpcs` показывал полный долг легаси-кода: idpg-core 869 ошибок, idpg-auth 2220, тема 870. Ни одна запись baseline не списывалась — независимо от `--bootstrap`. Заявленные в DEV-27A «exit 0» никогда не достигались. |
| **Причина** | `Ignores.php:26` ищет baseline как `dirname($configFile) . '/' . basename($configFile, '.xml') . '*.neon'` — имя файла обязано **начинаться с имени ruleset-файла**. При `basename('.phpcs.xml.dist', '.xml')` = `.phpcs.xml.dist` пакет искал `.phpcs.xml.dist*.neon`, а лежал `phpcs-baseline.neon` → `glob()` пуст → **0 ключей** (проверено дампом `COUNT=0`) → ничего не списывалось. |
| **Реализация** | Переименование в трёх компонентах: `phpcs-baseline.neon` → `.phpcs.xml.dist-baseline.neon`. Правок в коде нет. `bin/phpcs-patches/README.md` дополнен разделом о правиле имени. |
| **Проверка** | idpg-core **869 → 195** ошибок (765 → 380 предупреждений, 53 файла); idpg-auth **2220 → 4** (46); тема **870 → 77** (133, 10 файлов). `includes/class-rest-api.php` — 60 → **0**. |
| **Отвергнутые гипотезы** | (1) Разделители путей: `Ignores.php:92` нормализует оба (`str_replace(['/','\\'], DIRECTORY_SEPARATOR, …)`); подстановка версии со слэшами (512 строк) дала те же 869/765. (2) Экранирование кавычек в `BaselineReport.php:51`: `\"` — это экранирование **CSV в отчёте**, в JSON сообщения чистые. (3) Отсутствие подмены `LocalFile` → `File` в `FileList.php`: инструментированный прогон дал `REWRITTEN … inject=new \Forrest79\PhpCsIgnores\File(` — инъекция работала. |
| **Остаток** | Неиспользованные записи вычищены DEV-186 (см. ниже). Job `phpcs` остаётся красным (exit 2) — это реальный долг легаси-кода, а не поломка baseline. |
| **Объём** | S |

#### DEV-186 (P2, S). Убрать протухшие записи baseline

> **Закрыто 01.10.2026.** Продолжение DEV-183: после починки имени файла baseline наконец
> применяется, и стало видно, какие записи в нём мёртвые.

| | |
|---|---|
| **Проблема** | Часть записей baseline не соответствует ни одному текущему нарушению: нарушения починены в коде, записи остались. Пакет печатает их как `Ignored sniff … was not matched in report`, и они попадают в отчёт как **настоящие** предупреждения — то есть baseline шумит и мешает считать реальный долг. |
| **Требование** | Убрать только те записи, которые не находят соответствия; значения `count` у остальных не трогать; код не менять; не регенерировать baseline целиком. |
| **Реализация** | Скрипт на PHP (Neon): каждый блок записи декодируется и сопоставляется с фактическим содержимым JSON-отчёта PHPCS (`--report=json`), не тронутые блоки переписываются байт в байт. Кода проекта не касается. |
| **Результат** | Убрано **71** запись (ожидалось 74): idpg-core 14, idpg-auth 35, тема 22. Записи: 528 → **514**, 424 → **389**, 398 → **376**. `sum(count)`: 1094 → 1080, 3055 → 2999, 1036 → 997. Проверено сравнением с исходными копиями: изменённых `count` — **0**, лишних ключей — 0, neon валиден во всех трёх. |
| **Проверка** | Ошибки не изменились и не выросли: **195 / 4 / 77**. Реальные предупреждения без учёта служебных диагностик пакета: 366 / 1 / 118 — без изменений. Служебных диагностик `Ignored sniff` стало 5 / 10 / 0. |
| **Грабли (важно)** | **Нельзя «подчищать» `count`.** Первая версия скрипта уменьшала `count` там, где фактических нарушений меньше. Это молча глушит легаси: запись `count: 812` при 283 нарушениях — не опечатка. После такой правки предупреждения исчезли, что выглядело как успех, но означало, что сотни настоящих нарушений перестали учитываться. Записи с завышенным `count` легаси по своей природе — их надо чинить в коде, а не уменьшать счётчик. |
| **Остаток** | 15 записей с завышенным `count` (idpg-core 5, idpg-auth 10) — их пакет помечает `expected N times, but occurred only M`. Это не мёртвые записи: нарушения в них есть, просто меньше, чем записано. Трогать их можно только после DEV-27B (чистка кода), иначе вернётся скрытый долг. |
| **Объём** | S |

#### DEV-53. Репозиторий и CI: монолит, только наши артефакты

> **Проверено 30.09.2026 (DEV-148). ЗАКРЫТО — состояние репозитория соответствует
> постановке.** `origin` = `https://github.com/Franck-afk/idpg-source.git`; на месте
> `.gitignore` (режим default-deny), `.gitattributes`, `.circleci/config.yml`.
> `git ls-files uploads` — **0 файлов**, сторонние плагины в индексе — **0**.
> **Уточнения:** в индексе **312 файлов** (карточка писала 250 на 19.09.2026 — рост
> законный, добавлены DEV-87…151); рабочая ветка одна — `master`; на remote
> `main` (`fdf4ca0`) и `master` (`98efd2d`), отдельной ветки с бейзлайном нет
> (см. DEV-136).

> **Выполнено 19.09.2026.** Push выполнен 19.09.2026 отдельной веткой (см. «Remote и
> первый push»). Уточнение DEV-27A/27B: без репозитория и remote CI физически не
> запускается, а проходы DEV-27A/27B ничем не защищены от регрессий.

| | |
|---|---|
| **Проблема** | `wp-content` — не git-репозиторий проекта: нет remote, закоммичено 5 файлов, всё остальное untracked. `.circleci/config.yml` не запускается, потому что репозиторий некуда пушить. |
| **Требование** | 1) Выбрать стратегию (монолит / 4 репозитория + infra); 2) `.gitignore` с исключением `uploads/`, бэкапов, `vendor/`, `node_modules/`, сторонних плагинов, `wp-config*.php`; 3) включить только наши артефакты и `docs/`; 4) настроить remote и запушить; 5) проверить CI на чистом клоне. |
| **Решение владельца** | **Монолит** — один репозиторий проекта в `wp-content` (разделение на 4 репозитория отклонено). Компонент при необходимости выделяется позже с сохранением истории через `git subtree split`. |
| **Реализация** | `.gitignore` в режиме default-deny: `/plugins/*` + `!/plugins/idpg-core/`, `!/plugins/idpg-auth/`, `!/plugins/idpg-event-import/`, `!/plugins/wp-cloud-sync/`, `!/plugins/index.php`; `/themes/*` + `!/themes/idpg_core/`, `!/themes/idpg_core-child/`, `!/themes/index.php`. Исключены `uploads/`, `languages/`, `wp-config*.php`, каталоги бэкапов (`backups/`, `aiowps_backups/`, `wpvividbackups/`, `wpvivid_uploads/`, `wpvivid_staging/`, `upgrade/`, `upgrade-temp-backup/`), `vendor/`, `node_modules/`, `*.log`, `*.sql`, `*.bak`, `phpcs-tools*/`, `.workbuddy-ai/`, `mu-plugins/`. `.gitattributes`: `* text=auto eol=lf` (единый LF на Linux-CI), `*.bat/*.cmd/*.ps1` — crlf, бинарные расширения и `*.mo` помечены `binary`. Коммит `06fe9bb`; re-include собственных плагинов — коммит `21bc289`. |
| **Приёмка** | Свежий `git clone` → 215 файлов; среди них нет `uploads/`, `languages/`, сторонних плагинов, `vendor/`, `wp-config*.php` и бэкапов. Все файлы в клоне — LF. PHPCS на клоне: `idpg-core` / `idpg-auth` / тема `idpg_core` — **exit 0**. PHPUnit на клоне локально не проверяется — только в CI. |
| **Объём** | S |

**Состав репозитория** (250 файлов на 19.09.2026, сверено `git ls-files`):
`plugins/idpg-core` — 88, `plugins/idpg-auth` — 21, `plugins/idpg-event-import` — 16,
`plugins/wp-cloud-sync` — 4, `themes/idpg_core` — 95, `themes/idpg_core-child` — 3,
`docs/` — 8, `bin/phpcs-patches/` — 6, `plugins/index.php` + `themes/index.php`,
корневые `README.md`, `AGENTS.md`, `index.php`, `package.json`, `.gitignore`,
`.gitattributes`, `.circleci/config.yml`. Динамика: 215 на момент первой приёмки
DEV-53 → +5 файлов DEV-54 и правка `.gitignore` → 221 → +29 файлов, импортированных
из `main` в DEV-59 → 250.

**Что в репозиторий не попало** (замер 19.09.2026): сторонние плагины — 3612 файлов /
35,9 МБ; `uploads/` — 549 / 33,7 МБ; `wpvividbackups/` — 49 / 95,1 МБ;
`plugins/idpg-core/vendor/` — 1683 / 14,1 МБ; `languages/` — 187 / 11,9 МБ.

##### Remote и первый push (19.09.2026)

Remote: `https://github.com/Franck-afk/idpg-source.git` (`origin`).

Перед пушем выяснилось, что на GitHub **уже есть история проекта**: ветка `main`,
40 коммитов, 05.09 → 11.09.2026, 204 файла. Локальный репозиторий создавался заново
19.09.2026 из рабочего дерева, поэтому у линий **нет общего предка** — `git merge-base`
не находит его.

| | Локальный `master` | GitHub `main` |
|---|---|---|
| Коммитов | 8 (DEV-27B/53/54) | 40 |
| Даты | 19.09.2026 | 05.09 → 11.09.2026 |
| Файлов | 221 | 204 |
| Только на этой стороне | 46 файлов | 29 файлов |

**Главное:** на `main` лежали единственные копии артефактов, которых нет ни на диске,
ни локально:

- `plugins/idpg-event-import/` — **целый плагин, 11 файлов** (CLI, admin, провайдеры
  ical/REST, тесты, `phpunit.xml.dist`) — судя по составу, заготовка DEV-02;
- `plugins/wp-cloud-sync/` — 4 файла;
- корневой `README.md` с описанием структуры репозитория;
- `plugins/idpg-core/tests/test-event-moderation.php`, `test-seo-integration.php`
  (локально вместо них `test-organizer-page.php`; `test-organizer-pending.php` удалён в DEV-72);
- вендорные ассеты, которые локально исключены правилом `vendor/`:
  `plugins/idpg-core/assets/vendor/{iconify,tom-select}`, `themes/idpg_core/assets/{css,js}/vendor/*`
  (забраны импортом в DEV-59, правила исправлены в DEV-60 — см. раздел «DEV-60»).

Прямой `git push` в `main` (тем более force) уничтожил бы эти файлы безвозвратно,
поэтому он **не выполнялся**. Решение владельца — пушить отдельной веткой:

```bash
git push -u origin master:dev/phpcs-baseline
```

Ветка `dev/phpcs-baseline` = `21bc289` → `736bcf4` → `19d2ed8` → `c9d9201` → `7858e5f` →
**`2a2b170`** (DEV-59), `main` остался на `c9c10dc`. PR создаётся по ссылке, которую печатает
GitHub.
**Оговорка:** поскольку истории несвязанные, PR покажет все файлы как изменённые, а слияние
потребует `--allow-unrelated-histories` — то есть обычным «Merge pull request» может не пройти.
Замер цены слияния — в разделе «DEV-59. Замер цены слияния историй».

Страховка: полный слепок `main` (все 40 коммитов) и список 29 уникальных файлов сохранены
в `D:\laragon\backups\idupogorodu\dev53-push\` (`idpg-source-main.bundle`, `remote-only-files.txt`).

**Грабля:** песочница ассистента отбрасывает записи в `.git/refs/remotes/**` и создание
**новых подкаталогов** в `.git/refs/`. `git fetch` печатает «[new branch] main -> origin/main»,
`git update-ref` возвращает 0, но файлы ref не появляются, и `git status` показывает `[gone]`;
ветка `tmp/origin-main` (со слэшем) молча не создаётся, а `tmpmain` — создаётся. На реальность
операций с remote не влияет — результат проверять сетевым `git ls-remote`, а не локальными
ссылками. Ветки для работы называть без слэша.

##### DEV-59. Импорт уникальных файлов из `main` (вариант C)

> **Выполнено 19.09.2026.** Слияние историй: файлы из `main` забраны в локальную историю,
> история remote 05–11.09 не переносилась.

Источник — коммит `main` `c9c10dc09de562cdb9959d06524e4249ddfdbb65`, временная локальная
ветка `tmpmain`. Список из **29** путей получен через `git diff --name-status --no-renames tmpmain master`
с фильтром `D` и сверен с независимым подсчётом по деревьям (`comm -13` по `git ls-tree`) — оба
метода дали 29. Файлы забраны побайтово: `git checkout tmpmain -- <path>` (не копированием
по рабочему дереву). Сверка `git rev-parse :<path>` с `git rev-parse tmpmain:<path>` — **0 расхождений**.
`git status --short` дал ровно 29 записей со статусом `A`, изменений существующих файлов нет.
`git check-ignore` по всем 29 путям пуст. Коммит **`19d2ed8`**, файлов в репозитории стало 250.

Состав импорта: `README.md`; `plugins/idpg-event-import/` (15 файлов, включая `phpunit.xml.dist`
и 4 теста); `plugins/wp-cloud-sync/` (4); `plugins/idpg-core/tests/` (`test-event-moderation.php`,
`test-seo-integration.php`); вендорные ассеты `plugins/idpg-core/assets/vendor/{iconify,tom-select}`
и `themes/idpg_core/assets/{css,js}/vendor/*` (6 файлов).

**Push в `main` отклонён.** `git push origin master:main` → `! [rejected] master -> main
(non-fast-forward)`. Это ожидаемо и есть прямое следствие варианта C: забрав только файлы,
но не историю `main`, мы получили коммит, **не имеющий `c9c10dc` среди предков**, поэтому
fast-forward невозможен. Вариант C и «push в main обычным merge без `--allow-unrelated-histories`»
несовместимы — чтобы `main` продвинулся без force, нужно либо слить истории
(`--allow-unrelated-histories`), либо перебазировать наши 8+ коммитов на `main`. Force не
применялся. Решение возвращено владельцу; работа лежит в `dev/phpcs-baseline` (`19d2ed8`).

`main` на GitHub остался `c9c10dc` (проверено сетевым `git ls-remote`). Bundle сохранён как
архив — из него можно распаковать исходную историю и сверить, что ни один файл не потерян.

##### DEV-59. Разрешение конфликта с lint-гейтом

Импорт сломал гейт: `plugins/idpg-core` стал давать **46 ошибок / 2 предупреждения, exit 2**
в двух привезённых тестах — `tests/test-event-moderation.php` (21) и
`tests/test-seo-integration.php` (25 + 2). Причина: `.phpcs.xml.dist` плагина содержит
`<file>.</file>` и исключает только `vendor/`, `node_modules/`, `*.min.js`, то есть `tests/`
линтуется; а в ветке `main` **не было ни одного `.phpcs.xml.dist`**, поэтому эти файлы никогда
не проверялись и в baseline их не было.

Решение владельца — внести нарушения в baseline, **не правя код тестов**: так импорт остаётся
побайтово равным `main`, и при будущем слиянии историй эти файлы смержатся без конфликтов.
Baseline не дописан вручную, а **перегенерирован** через `BaselineReport` — иначе нарушился бы
инвариант DEV-27B «baseline == вывод генератора». Коммит **`7858e5f`**.

| | до | после |
|---|---|---|
| Записей в baseline `idpg-core` | 520 | **528** (+8) |
| Дифф файла | — | ровно +48 строк, других изменений нет |
| Удалений в диффе | — | нет |
| PHPCS по трём компонентам | `idpg-core` exit 2 | **exit 0** |
| Инвариант «baseline == генератор» | — | выполнен (повторная генерация совпала побайтово) |

Добавленные записи: `PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket` (10 + 7),
`...CloseBracketLine` (10 + 7), `Generic.Commenting.DocComment.ShortNotCapital` (10),
`PSR2.Files.EndFileNewline.NoneFound` (1 + 1),
`Generic.Formatting.MultipleStatementAlignment.NotSameWarning` (2 warning).

**Побочное уточнение — исправление прежней находки.** Записи
`Generic.Files.LineEndings.InvalidEOLChar` для `includes/class-settings-page.php` и
`uninstall.php`, которые в DEV-53/DEV-27B были помечены как «устаревшие — нарушений в коде
уже нет», **регенерацией воспроизводятся, то есть законны**. Оба файла реально содержат CRLF
в рабочем дереве (483 и 106 переводов строк). «Устаревшими» они выглядят только в **чистом
клоне**: `.gitattributes` (`* text=auto eol=lf`) отдаёт там LF, снифф не срабатывает, и плагин
пишет «Ignored sniff … was not matched in report». Это и наблюдалось при приёмке DEV-53 на
клоне — отсюда неверный вывод. Для темы 10 записей остаются непроверенными.

**Грабля:** `git diff --name-status` по умолчанию включает детекцию переименований и склеивает
`plugins/idpg-event-import/phpunit.xml.dist` с `plugins/idpg-auth/phpunit.xml.dist` в пару `R`,
из-за чего файл выпадает из выборки «только `D`» — выходило 28 вместо 29. Для подобных выборок
использовать `--no-renames` и перекрёстно сверять с подсчётом по деревьям.

##### DEV-59. Замер цены слияния историй (перед решением владельца)

Push в `main` отклонён `non-fast-forward`. Прежде чем возвращать решение владельцу, цена каждого
варианта посчитана **без правок рабочего дерева** — на деревьях, объектах и пробном merge.

**Замеры.**

| Метрика | Значение |
|---|---|
| Файлов в `main` (`c9c10dc`) | 204 |
| Файлов в нашем `master` | 250 |
| Файлов «только в main» (`comm -23`) | **0** |
| `git diff --name-status --no-renames` | 63: **46 `A`** (наши) + **17 `M`** |
| Конфликтов в `git merge-tree --allow-unrelated-histories` | **17, все `add/add`** |
| Дифф по этим 17 файлам | **+1534 / −37** |
| Дерево пробного merge vs наше | **совпало побайтово** (`9bb401e6`) |

**Файлов, которые есть в `main` и отсутствуют у нас, нет вовсе** — наше дерево строгое
надмножество. 29 «уникальных» файлов были забраны импорт-коммитом `19d2ed8`, поэтому
«только в main» = 0.

**Все 37 удалений просмотрены.** 20 — переписанный `.gitignore` (наш default-deny вместо
старого), остальные — форматирование и осознанные рефакторы. Функциональные проверены отдельно:

- `class-roles.php` — `ROLE_VERSION 6 → 7` плюс досоздание роли `subscriber`; это **наша** работа
  (DEV-32), в `main` её нет;
- `class-wp-auth-ru-admin.php` — `if ( $tab === 'logs' )` заменён цепочкой `if/elseif` с новой
  вкладкой `logins`; ветка `logs` сохранена (`render_logs_tab()` на месте);
- `class-wp-auth-ru-provider.php` — хардкод `return 'event_organizer'` заменён на
  `get_option( 'default_role', 'subscriber' )` + фильтр `idpg_auth_new_user_role`.

**Вывод: во всех 17 конфликтах наша версия новее** (`main` — снимок 05–11.09, наша — рабочая
копия 19.09), поэтому ручное разрешение не требуется: `-X ours` даёт корректный результат.

**Доказательство без потерь.** Пробный merge в рабочем дереве:
`git merge -X ours --allow-unrelated-histories --no-commit c9c10dc` → «Automatic merge went
well», затем `git write-tree` (`9bb401e6e6890442987f4dc069243e61bdff58c0`) **совпал** с
`git rev-parse master^{tree}`. Значит merge-коммит даёт дерево, побайтово равное текущему:
250 файлов, ноль потерь, ноль дрейфа. Откат — `git merge --abort` + `git reset --hard master`;
состояние проверено (HEAD `2a2b170`, 250 файлов, `MERGE_HEAD` отсутствует).

**Ключевая находка — вариант C в исходном виде недостижим без force-push.** `main` на GitHub
указывает на `c9c10dc`, а любой не-force push в `main` требует, чтобы `c9c10dc` был предком
нового коммита. Следовательно 40 коммитов 05–11.09 останутся в истории `main` **и при merge,
и при rebase одинаково**; убрать их можно только force-push'ем или пересозданием репозитория.
Это меняет постановку: выбор идёт не между «тащить историю / не тащить», а между способом
присоединения к уже опубликованной истории.

##### DEV-59. Выполнено: merge историй, push в `main` без force

**Решение владельца — merge с `-X ours`.** Выполнено:

```bash
git merge -X ours --allow-unrelated-histories c9c10dc \
  -m "DEV-59: слить историю main в нашу (--allow-unrelated-histories)" …
git push origin master:main      # fast-forward, БЕЗ force
```

Результат: `c9c10dc..447d23d  master -> main`. Две точки, а не `+`, то есть **настоящий
fast-forward** — запрет на force-push не нарушен ни в каком виде.

| Проверка | Ожидалось | Получено |
|---|---|---|
| Родителей у merge-коммита | 2 | 2 (`a381795` + `c9c10dc`) |
| Дерево merge vs наше | совпадает | `b138d932…` — **совпадает** |
| `git diff a381795 HEAD` | пусто | пусто |
| Файлов | 250 | 250 |
| `c9c10dc` — предок `HEAD` | да | да |
| Рабочее дерево | чистое | чистое |
| PHPCS по трём компонентам (локально) | exit 0 | **exit 0** ×3 |
| PHPCS в чистом клоне | exit 0 | **exit 0** ×3 |
| Файлов из `main` потеряно | 0 | **0** (все 204 на месте) |
| Файлов «только в main» осталось | 0 | 0 |
| 29 импортированных файлов в клоне | все | **все 29** (missing = 0) |

Приёмка DEV-53/DEV-59 закрыта: чистый `git clone` даёт 250 файлов, `HEAD` = `447d23d`,
все 204 файла исходного `main` и все 29 импортированных на месте, PHPCS — exit 0.

**Бонус:** `.circleci/config.yml` и `plugins/idpg-core/.circleci/config.yml` теперь на `main`
(в исходном `main` их не было вовсе), поэтому CI можно привязывать к проекту.

**Цена решения.** 40 коммитов 05–11.09 остались в истории `main` как вторая родительская линия —
иначе было нельзя без force-push. Содержимое при этом не потеряно: во всех 17 расхождениях
наша версия новее, их старые редакции остаются доступны в истории.

**Ветка `dev/phpcs-baseline`** (`a381795`) стала избыточной — её содержимое целиком вошло
в `main`. Удаление (не force-операция) — по отдельному решению владельца.

**Находка про `assets/vendor/`.** Вопреки прежней оговорке «ничем не восстанавливаются», все
6 вендорных ассетов (`iconify`, `tom-select` ×2, `toastify` ×2, `xlsx`) приехали из `main`
импортом и теперь **опубликованы на `main`** — чистый клон их получает. Но правило
`vendor/` (`.gitignore:52`) **по-прежнему матчит** эти пути: `git check-ignore --no-index`
даёт `IGNORED: .gitignore:52:vendor/`. Файлы выживают только потому, что уже отслеживаются
(git никогда не игнорирует tracked-файлы). Это латентное противоречие: при удалении и повторном
`git add` ассеты будут потеряны молча. **Рекомендация — дописать re-include** (как в `21bc289`),
это архитектурное решение владельца.

Рецепт замера вынесен в `MEMORY.md («Рецепты»)` («Доказательство «merge ничего не теряет»»).

##### DEV-60. Re-include вендорных фронтенд-ассетов

**Проблема.** Правило `vendor/` (`.gitignore`, блок «Зависимости») матчит каталог на **любой
глубине**, поэтому под него попадали и наши фронтенд-ассеты в `assets/*/vendor/`. В отличие от
composer/npm-зависимостей они **не восстанавливаются ни одной командой** — после чистого клона
фронтенд темы и плагина остался бы без них.

**Уточнение к постановке.** Задача формулировалась как «локально не отслеживаются, нужно
добавить». Фактически файлы **уже были tracked** — их принёс импорт DEV-59
(`git checkout tmpmain -- <path>`), и они уже опубликованы на `main`. Поэтому `git add` в шагах
5–6 не дал изменений в индексе: правка приводит **правила** в соответствие с фактическим
составом. Ценность правки реальна — без неё ассеты выживали только как tracked и при удалении
не вернулись бы в индекс без `git add -f`.

**Решение владельца — вариант A, точечные `!`-исключения** (не глобальный `!vendor/`):

```
!plugins/idpg-core/assets/vendor/
!plugins/idpg-core/assets/vendor/**
!themes/idpg_core/assets/css/vendor/
!themes/idpg_core/assets/css/vendor/**
!themes/idpg_core/assets/js/vendor/
!themes/idpg_core/assets/js/vendor/**
```

В шапку файла добавлен комментарий со ссылкой на DEV-60; рядом с правилами — пояснение,
почему исключение точечное.

**Состав и объём.** 3 каталога, **6 файлов, 1 094 538 байт = 1,04 МБ**:

| Файл | Размер |
|---|---|
| `plugins/idpg-core/assets/vendor/iconify/iconify.min.js` | 68 639 |
| `plugins/idpg-core/assets/vendor/tom-select/tom-select.complete.min.js` | 50 310 |
| `plugins/idpg-core/assets/vendor/tom-select/tom-select.min.css` | 15 386 |
| `themes/idpg_core/assets/css/vendor/toastify.min.css` | 1 523 |
| `themes/idpg_core/assets/js/vendor/toastify.min.js` | 6 776 |
| `themes/idpg_core/assets/js/vendor/xlsx.full.min.js` | 951 904 |

Объём мал (порог обсуждения «сборки» — 10 МБ, аварийный — 50 МБ), поэтому **вариант «сборка»
не нужен**: держать 1 МБ в репозитории дешевле, чем заводить npm-сборку для статичных файлов.
Клон целиком — 7,6 МБ.

**Проверки (все пройдены).**

| Проверка | Результат |
|---|---|
| `check-ignore -q --no-index` на наши ассеты | exit **1** — не игнорируются |
| `check-ignore -q --no-index` на `wp-mail-smtp/vendor/` | exit **0** — IGNORED (`/plugins/*`) |
| `check-ignore -q --no-index` на `plugins/idpg-core/vendor/` | exit **0** — IGNORED |
| Удалить из индекса → `git add` **без `-f`** | все **6** файлов вернулись |
| Чистый клон (`dev60-clone`, `41488ee`) | все 6 файлов на месте, размеры совпали |
| Сторонние плагины и composer-vendor в клоне | отсутствуют |

Коммит **`41488ee`**, запушен в `main` (`6dfe73c..41488ee`).

**Грабля, найденная при проверке.** `git check-ignore -v` **возвращает exit 0 всегда, когда
сматчился хоть один паттерн, включая отрицательный**, и печатает сам `!`-паттерн. Поэтому
проверка из постановки («`check-ignore -v …` → ожидаемо пусто») по `-v` не проходит: для
re-include-путей вывод будет `…:!plugins/…/vendor/** <путь>` с exit 0. Истину даёт вызов
**без `-v`** (или с `-q`): exit 1 = не игнорируется. Записано в `MEMORY.md («Грабли»)`.

##### Находки и оговорки

- **Дефект состава: собственные плагины выпали из репозитория (исправлен 19.09.2026).**
  В первой редакции `.gitignore` re-include был только для `plugins/idpg-core/` и
  `plugins/idpg-auth/`, поэтому собственные плагины проекта `idpg-event-import` и
  `wp-cloud-sync` молча игнорировались — хотя в ветке `main` они отслеживались.
  Дефект обнаружился только при настройке remote. Исправлено коммитом `21bc289`
  (добавлены `!/plugins/idpg-event-import/`, `!/plugins/wp-cloud-sync/`), проверено
  `git check-ignore`: свои плагины TRACKED, сторонний `wp-mail-smtp` остаётся IGNORED.
  **Правило: при добавлении своего плагина обязательно дописать re-include.**
- **Вендорные ассеты в `assets/vendor/` — ЗАКРЫТО в DEV-60.** Правило `vendor/` (без ведущего
  слэша) матчит каталог на любой глубине, поэтому `plugins/idpg-core/assets/vendor/`
  (iconify, tom-select) и `themes/idpg_core/assets/{css,js}/vendor/` (toastify, xlsx) попадали
  под него. DEV-59 забрал все 6 файлов импортом (теперь они на `main`), DEV-60 дописал точечные
  `!`-исключения — правила приведены в соответствие с составом. Подробности и замеры — в разделе
  «DEV-60». Сторонние плагины и композерный `plugins/idpg-core/vendor/` по-прежнему IGNORED.
- **CI проверяет только ошибки — предупреждения job не валят.** В job `phpcs` стоит
  `--runtime-set ignore_warnings_on_exit 1`, поэтому exit-код зависит только от errors;
  предупреждения вне контроля CI.
- **12 записей baseline помечены как устаревшие — но для `idpg-core` это оказалось неверно
  (уточнено в DEV-59).** Речь о `Generic.Files.LineEndings.InvalidEOLChar` для
  `includes/class-settings-page.php` и `uninstall.php` (2 записи) и о 10 записях в теме
  (`Internal.LineEndings.Mixed` и 9 × `Squiz.WhiteSpace.SuperfluousWhitespace.EndLine`
  для `single-venue.php`). Плагин выводил их как «Ignored sniff … was not matched in report»
  — но это наблюдалось на **чистом клоне**, где `.gitattributes` (`* text=auto eol=lf`)
  отдаёт файлы с LF и снифф не срабатывает. В рабочем дереве оба файла `idpg-core` реально
  содержат CRLF, и регенерация baseline (DEV-59) воспроизвела эти записи — они законны.
  Для темы проверка не проводилась; там возможна та же природа. На exit-код не влияет.
  Чистить (или уточнять) — отдельным заходом DEV-27B.
- **Корневого `composer.json` нет** — `composer install` в корне репозитория нечего
  устанавливать: job `phpunit` ставит зависимости внутри `plugins/idpg-core`, job `phpcs`
  разворачивает инструменты в `/tmp/phpcs-tools`. Решение о корневом манифесте — за владельцем.
- **`composer.lock` не коммитится** — `plugins/idpg-core/.gitignore` его исключает, поэтому
  `composer install` в CI разрешает зависимости заново (сборка не воспроизводима по версиям).
- **Легаси-конфиг внутри плагина.** `plugins/idpg-core/.circleci/config.yml` — CircleCI 2.0
  с job'ами `php56`…`php74`. CircleCI читает только корневой `.circleci/config.yml`, поэтому
  файл безвреден, но вводит в заблуждение.
- **Версия PHP в CI — 8.4** (`cimg/php:8.4`), локально 8.2 (CLI) / 8.3 (веб).
- **`deploy.sh` содержит чужой домен.** Файл лежит в корне WordPress (вне этого репозитория):
  `WEBROOT="~/domains/potolkicomfort.ru/public_html"` вместо `idupogorodu.ru`. Править перед
  первым деплоем.
- **Грабля Git Bash:** `grep -c $'\r' файл` даёт ложное число (показывает примерно число строк),
  хотя файл без CRLF. Проверять переводы строк только через `tr -cd '\r' < файл | wc -c`.
- **Грабля клонирования:** `git clone` из локального репозитория под песочницей падает с
  `detected dubious ownership` (разные SID). Обход на один вызов — `git -c safe.directory='*' clone …`.

#### DEV-54. Сохранить патчи PHPCS-обвязки

> **Проверено 30.09.2026 (DEV-148). ЗАКРЫТО — все шесть файлов на месте**
> в `bin/phpcs-patches/`: `0001-phpcs-ignores-windows-path.patch`,
> `0002-phpcs-ignores-baseline-tabs.patch`, `apply.sh`, `apply.cmd`,
> `tools.composer.json`, `README.md`. Приёмка карточки (чистая установка →
> `apply.sh` → exit 0) не переигрывалась: она требует `composer install` в отдельный
> каталог обвязки и относится к CI-окружению, а не к рабочему дереву.

> **Выполнено 19.09.2026.** Уточнение DEV-27A/DEV-27B: патчи жили прямо в `vendor/`
> и стирались при переустановке зависимостей.

| | |
|---|---|
| **Проблема** | Два патча к `forrest79/phpcs-ignores` — `PhpCsInjections.php` (нормализация пути на Windows) и `BaselineReport.php` (не экранировать табы) — лежали прямо в рабочей копии `phpcs-tools2/vendor` и терялись при `composer install` / `composer update` или удалении `vendor`. После потери патча baseline перестаёт применяться, и локальный прогон даёт ~4 тыс. унаследованных нарушений вместо exit 0. |
| **Требование** | Перенести патчи в `bin/phpcs-patches/` репозитория с инструкцией apply в README. Альтернатива — форк `forrest79/phpcs-ignores` и `nette/neon` в свой composer. |
| **Решение** | Патч-файлы (от форка отказались — обоснование ниже и в README). |
| **Реализация** | `bin/phpcs-patches/`: `0001-phpcs-ignores-windows-path.patch`, `0002-phpcs-ignores-baseline-tabs.patch` (unified diff, `-p1` от корня пакета), `apply.sh` (идемпотентный, флаги `--check` и `--help`, сверка версии пакета с `composer.lock`), `apply.cmd` (обёртка для cmd.exe), `tools.composer.json` (пришпиленное определение тулчейна), `README.md`. |
| **Приёмка** | **Пройдена на реальной чистой установке.** `composer install` с нуля (10 пакетов, `forrest79/phpcs-ignores` v0.5.0) → vendor чистый → PHPCS на `idpg-core`: **636 ошибок, exit 2**. Затем `apply.sh` → PHPCS по трём компонентам: **exit 0**. |
| **Объём** | S |

##### Что именно патчится

| Патч | Файл | Эффект |
|---|---|---|
| `0001` | `src/PhpCsInjections.php` | Перед `str_ends_with()` путь нормализуется: `str_replace( '\\', '/', $path )`. Нужен для **применения** baseline на Windows |
| `0002` | `src/BaselineReport.php` | Убрано `str_replace( "\t", '\t', $message )`. Нужен для **генерации** baseline: NEON в одинарных кавычках не раскрывает escape-последовательности, поэтому запись с литералом `\t` никогда не совпадёт с реальным табом |

##### Проверка состава vendor (19.09.2026)

Побайтовое сравнение `phpcs-tools2/vendor` с пакетами из кэша Composer (с подбором версии по
`composer.lock`): модифицирован **ровно один** пакет — `forrest79/phpcs-ignores` (два файла выше).
Все остальные чисты: `nette/neon` v3.4.8, `squizlabs/php_codesniffer` 3.13.6,
`wp-coding-standards/wpcs` 3.4.1, `phpcompatibility/*`, `phpcsstandards/*`,
`dealerdirect/phpcodesniffer-composer-installer`. Поэтому форк `nette/neon` не нужен.

##### Почему не форк

Форк требует своего VCS-репозитория, записи в `repositories` и ручной синхронизации с каждым
апстрим-релизом — инфраструктуры больше, чем сами патчи. Патч-файлы видны в истории git,
не требуют обслуживания и удаляются одной строкой, когда апстрим исправит поведение.
Порядок пересборки патчей при апгрейде пакета — в заголовке `bin/phpcs-patches/apply.sh`
(комментарий и `--help`).

##### Оговорки

- **CI патчи не применяет — и не должен.** Job `phpcs` ставит обвязку в `/tmp/phpcs-tools`:
  `0001` на Linux ничего не меняет (пути уже с `/`), а `0002` влияет только на генерацию,
  которую CI не делает. Если генерация в CI понадобится — добавить шаг
  `bash bin/phpcs-patches/apply.sh /tmp/phpcs-tools`.
- **Грабля:** `git` — нативная программа Windows и не понимает POSIX-пути вида `/d/...`
  после смены каталога (`can't open patch … No such file or directory`). В `apply.sh` путь
  к патчу конвертируется через `cygpath -m`.
- Патчи сделаны под `forrest79/phpcs-ignores` **v0.5.0**; `apply.sh` предупреждает о другой
  версии и возвращает код 1, если контекст не совпал.

##### Фиксация версий обвязки

Каталог инструментов лежит вне репозитория, поэтому тулчейн задан файлом-шаблоном
`bin/phpcs-patches/tools.composer.json` — четыре прямые зависимости **пришпилены точно**
(без `^`): `squizlabs/php_codesniffer` 3.13.6, `wp-coding-standards/wpcs` 3.4.1,
`phpcompatibility/phpcompatibility-wp` 2.1.8, `forrest79/phpcs-ignores` 0.5.0. Раньше стоял
карет `^0.5.0`, из-за чего `composer update` мог подтянуть 0.6.x и сломать патчи — а вместе
с ними и baseline. Транзитивные зависимости фиксируются `composer.lock` в каталоге инструментов.

Приёмка этой процедуры (19.09.2026, `dev54/pin-check`): `composer install` по шаблону → все
10 пакетов встали в ожидаемых версиях → без патчей `idpg-core` даёт **636 ошибок / 410
предупреждений, exit 2** → `apply.sh` → **exit 0** по трём компонентам. Локальный
`phpcs-tools2` приведён к тому же пришпиленному определению.

При обновлении пакета версию нужно править **в трёх местах**: таблица в `README.md`,
`tools.composer.json`, `EXPECTED_VERSION` в `apply.sh`.

#### DEV-32. `set_role( 'subscriber' )` при регистрации пишет несуществующую роль

> **Найдено попутно при проверке DEV-05 (15.09.2026). Не исправлено — вне объёма задачи.**

| | |
|---|---|
| **Проблема** | На сайте роль `subscriber` **не зарегистрирована** (в `wp_roles` есть `administrator`, `editor`, `author`, `contributor`, `event_organizer`, `wpseo_manager`, `wpseo_editor`). При этом и `handle_register()` (`class-rest-auth.php`, ~строка 701), и PHP-fallback в `page-login.php` (~строка 222) вызывают `$org_user->set_role( 'subscriber' )`. Ядро пишет в `wp_capabilities` ключ `subscriber`, которого нет в `wp_roles`, поэтому `WP_User::get_role_caps()` его отбрасывает: `wp_capabilities = ['subscriber' => true]`, а `$user->roles = []`. |
| **Последствие** | Пользователь в период между регистрацией и подтверждением по email не имеет ни одной роли. Функционально это близко к задуманному («pending-организатор без прав»), но ключ `subscriber` остаётся мусором в мете. На этапе подтверждения `IDPG_Organizer_Verify::assign_organizer_role()` перезаписывает caps целиком, поэтому мусор в итоге исчезает — дефект косметический. В `class-organizer-verify.php:170-196` этот подводный камень уже описан и обойдён, но в путях регистрации обход не применён. |
| **Требование** | Привести регистрацию к тому же приёму, что и подтверждение: либо не назначать роль вовсе (пользователь и так без роли), либо писать `wp_capabilities` явно через хелпер `IDPG_Organizer_Verify`. Заодно сверить с `class-user-purge.php` и `class-auth.php`, ~строка 206, которые рассчитывают на роль `subscriber`. |
| **Реализация** | `plugins/idpg-core/includes/class-rest-auth.php`, `themes/idpg_core/page-login.php`; возможно, публичный хелпер в `class-organizer-verify.php`. |
| **Приёмка** | После регистрации в `wp_capabilities` нет ключа несуществующей роли; подтверждение по email по-прежнему выдаёт `event_organizer`; вход и редиректы не затронуты. |
| **Объём** | S |

#### DEV-33. Rate limiter регистраций тратит лимит на неудачные попытки

> **Найдено попутно при проверке DEV-06 (15.09.2026). Не исправлено — вне объёма задачи.**
>
> > ℹ️ **Аудит DEV-00A (19.09.2026):** номер DEV-33 в исходной формулировке («каркас ЛК»)
> > занят именно этой задачей. Уточнение диапазона «DEV-33…36» — см. §10.

| | |
|---|---|
| **Проблема** | `IDPG_Auth_Rate_Limiter::check_registration_attempt()` инкрементирует счётчик `idpg_reg_attempts_<md5(ip)>` на **каждом** вызове, в том числе когда регистрация затем отклоняется по валидации. При этом вызов стоит в `handle_register()` (`class-rest-auth.php`) **до** проверок `email_exists` (409), полей организатора, `terms` и нового `idpg_pdn_consent`. Лимит — `REGISTRATION_LIMIT = 3` попытки за `REGISTRATION_WINDOW = 3600` секунд с одного IP. |
| **Последствие** | Пользователь, который трижды забыл отметить обязательный чекбокс (или трижды ошибся в пароле/полях), получает `429 too_many_registrations` на час вперёд — хотя ни одной регистрации фактически не произошло. С появлением второго обязательного чекбокса (DEV-06) вероятность сценария выросла. Отдельно стоит учесть, что за NAT/одним офисным IP лимит общий для всех сотрудников. |
| **Требование** | Инкрементировать счётчик только при успешной регистрации (перенести вызов после `wp_create_user()`), либо разнести «проверку» и «учёт попытки» на два метода. Не менять `LOGIN_LIMIT`/`RESET_LIMIT` и их окна. |
| **Реализация** | `plugins/idpg-core/includes/class-auth-rate-limiter.php` (разделение `check_registration_attempt()` на проверку и фиксацию), `plugins/idpg-core/includes/class-rest-auth.php` (порядок вызовов). Возможно, потребуется публичный метод `register_registration_attempt()`. |
| **Приёмка** | Серия из 4+ неудачных регистраций с одного IP (неверный чекбокс, существующий email, короткий пароль) не приводит к `429`; 4 успешные регистрации подряд по-прежнему упираются в `429`; счётчик сбрасывается по истечении окна. |
| **Объём** | S |

---

### 6. ДОКУМЕНТАЦИЯ И КОНТЕНТ (P2–P3)

#### DEV-28. Привести документацию в соответствие коду

> **Проверено 01.10.2026. Приёмка выполнена по коду, расхождений не найдено.**
>
> **README — несуществующих файлов нет.** Проверены все файловые ссылки трёх README
> (`README.md` корня, `plugins/idpg-core/README.md`, `themes/idpg_core/README.md`):
> 54 упоминания в блоках-деревьях плюс все имена в `code`-кавычках сверены с диском.
> **Ни одного отсутствующего файла.** Отдельно проверены: дерево темы (21 путь — все
> на месте), дерево плагина (`assets/vendor/` с iconify и tom-select существует,
> `languages/idpg-core.pot` есть, `bin/make-pot.js` есть), 40 классов `includes/`
> (все 40 совпадают с 40 `require_once` в `idpg-core.php` — «висячих» нет),
> 14 классов в разделе «Основные файлы плагина» темы README, URL-маршруты
> (`/afisha/`, `/lk/favorites/`, `/organizer/`, `/login/`, `/reset-password/`).
> Упоминания `/auth/callback/vk/` и `/auth/yandex/` в README — исторические записи
> CHANGELOG версий 1.0.6/1.0.7, а не актуальные обещания; реальные коллбэки
> соцпровайдеров — REST `wp-auth-ru/v1/vk/callback` и `wp-auth-ru/v1/yandex/callback`.
>
> **`DOCUMENTATION.md` — исправлено.** Найдено и приведено к коду:
> §2 дерево (22 → 39 классов, `class-rest-api.php` 13 → 15 маршрутов,
> добавлен `current-event-banner.js`), §6 REST (22 → **36** вызовов
> `register_rest_route()`, `/auth/logout` убран из двойного учёта, `/auth/me` перенесён
> из публичных в защищённые, добавлен §6.4 с 15 маршрутами `class-favorites.php`,
> `class-rest-organizer.php` и соцпровайдеров), §6.3 (используются `/search/live`,
> `/search/popular`, `/subscribe`, `/favorites/*`).

#### DEV-29. Чек-лист темы: остатки задач

> **Проверено 30.09.2026 (DEV-148). Карточка остаётся ОТКРЫТОЙ.**
> Сверены все 12 пунктов чек-листа `themes/idpg_core/README.md`, а не только два
> названных в постановке: **10 закрыты или подтверждены, 2 в остатке (п. 4 и п. 8),
> и оба вне кода.** Статусы в самом чек-листе проставлены по этому разбору.

**Сверка чек-листа по коду**

| № | Задача | Было | Стало | Основание |
|---|---|---|---|---|
| 4 | `IDPG_TICKET_API_URL` в `wp-config.php` | 🔶 | **остаётся** | Константа не задана: `README.md` ~строка 271 задаёт плейсхолдер `example.com`, `class-ticket-sync.php` ~строка 234 логирует «`IDPG_TICKET_API_URL` не настроен (заглушка `example.com`)» и пропускает синхронизацию. Это внешний адрес агрегатора — настройка владельца |
| 5 | Доработать `build_api_url()` | ⬜ | ✅ закрыто | Функция реализована — `class-ticket-sync.php` ~строка 227, `private function build_api_url( $event )`; при незаданной константе отдаёт заглушку. Дорабатывать нечего, остаётся только п. 4 |
| 6 | Загрузить `email-logo.png` в `uploads/` | ⬜ | ✅ закрыто | `uploads/email-logo.png` на месте, используется в `class-auth-emails.php` ~строка 535. Чек-лист устарел. **Уточнение DEV-153:** пункт числился закрытым, но guard на отсутствие файла в `get_logo_url()` не был сделан — добавлен при закрытии `DEV-15` 30.09.2026 (§20), покрыт `tests/test-email-logo.php` |
| 7 | Скомпилировать SCSS в CSS | ⬜ | ✅ закрыто | Сборка идёт в `style.css` темы (`functions.php` ~строка 178, `get_stylesheet_uri()`), отдельного `main.css` в проекте нет по замыслу; правила страницы аутентификации в нём присутствуют. Гард сборки — `tests/test-css-build.php` (DEV-87/143) |
| 8 | Удалить `node_modules` из темы | ⬜ | **остаётся** | `themes/idpg_core/node_modules` в дереве. Решение «удалить или оставить как сборочную зависимость» — владельца |
| 11 | Проверить фильтрацию по городам | ⬜ | ✅ закрыто | `class-city-filter.php` инициализируется (`tests/test-class-init.php`), cookie и AJAX-установка используются в `class-city-selector-widget.php` и `class-rest-api.php` ~строки 880–894; город читают `class-city-url.php`, `class-search.php`, `class-meta.php` ~строка 1048, `class-current-event-banner.php`, `class-geo-service.php`, `class-organizer-scope.php` |
| 12 | Проверить стили ошибок валидации | ⬜ | ✅ закрыто | `auth.js` ~строки 107, 122, 133, 141, 153 добавляет и снимает `border-red-500` и создаёт `.auth__field-error`; правила есть в скомпилированном `style.css` ~строки 1485, 1488, 1528 |
| 9, 10 | Fallback без JS, совместимость REST и фронтенда | ✅ | ✅ подтверждено | `page-login.php` — обычный POST + nonce; покрыто `tests/test-registration-form.php` (14 тестов), `tests/js/auth-registration.test.js` (13 проверок), `tests/test-auth-js.php` |

**Два пункта из постановки в чек-листе отсутствуют**

- «Проверка сохранения мета-полей в админке» — закрыт по факту: мета ведёт
  Carbon Fields, зеркала висят на `carbon_fields_post_meta_container_saved`
  (`class-meta.php` ~строка 1279), админский бокс регистрируется в
  `class-meta.php` ~строка 90.
- «Проверка фильтров `/venues/?type=…` из меню» — **снят с обоснованием:**
  фильтрации по «типу» в проекте нет как сущности — ни `query_vars`/`pre_get_posts`
  по параметру `type`, ни ссылок вида `type=` в теме. Проверять нечего.

**Приёмка карточки не выполнена:** остаются п. 4 и п. 8 — оба вне кода.

| | |
|---|---|
| **Проблема** | В `themes/idpg_core/README.md` остаются незакрытые пункты: проверка фильтрации по городам, стили ошибок валидации `auth.js` (`border-red-500`, `auth__field-error`), проверка сохранения мета-полей в админке, проверка фильтров `/venues/?type=…` из меню. |
| **Требование** | Провести проверку каждого пункта, закрыть найденные дефекты, обновить чек-лист статусами. |
| **Приёмка** | Все пункты чек-листа имеют статус «проверено/исправлено» либо «снято с обоснованием». |
| **Объём** | M |

#### DEV-30. Наполнение данными

| | |
|---|---|
| **Проблема** | Площадок в БД нет — архив `/venues/` пуст; у части событий нет миниатюр; события не привязаны к площадкам (`_idpg_venue` пуст); города Удмуртии (Воткинск, Глазов, Можга, Сарапул) и районы Ижевска не наполнены; профиль организатора заполнен у одного пользователя. |
| **Требование** | Создать площадки и привязать события; заполнить постеры; наполнить города и районы; заполнить анкеты организаторов. |
| **Приёмка** | `/venues/` показывает площадки; у событий есть постеры; фильтр по городу возвращает события для каждого наполненного города. |
| **Объём** | L (контент, не код) |

---

#### DEV-78. Deprecation `is_email` в REST-аргументах `forgot-password` и `organizer-complete`

> **Проверено 30.09.2026 (DEV-148). ЗАКРЫТО — правка на месте, регрессия закрыта.**
> В `class-rest-auth.php` два email-аргумента объявлены замыканиями с одним
> аргументом — ~строки 132 и 213, обе с пояснением «DEV-78». Строковых
> `'validate_callback' => 'is_email'` в файле не осталось. Тест
> `tests/test-email-validator.php` — **10 тестов**, как записано после поправки
> DEV-72. Маршрута `organizer-complete` в файле нет (снят DEV-72, остатки удалены
> DEV-141), поэтому и правка `sanitize_email()` к нему отсутствует — ожидаемо.
> **Уточнение:** вызов `is_email( $value )` ~строка 912 — обычное сравнение в коде,
> а не валидатор REST; депрекейшн он не даёт.

> **Статус: выполнено 28.09.2026.**

| | |
|---|---|
| **Проблема** | В `class-rest-auth.php` email-аргументы объявляли `'validate_callback' => 'is_email'`. REST вызывает валидатор как `( $value, $request, $key )`, а второй аргумент `is_email()` помечен устаревшим с 3.0.0 — объект запроса попадал в `$deprecated` и давал notice на каждом вызове. Заметен в `auth/register` при DEV-70; остался в `auth/forgot-password` (строка 187) и `auth/organizer-complete` (строка 262). |
| **Требование** | Заменить на замыкание с одним аргументом; проверить, что notice не летит; покрыть оба маршрута тестами. |
| **Приёмка** | Notice не летит; `forgot-password` и `reset-password` работают корректно. |
| **Объём** | S |

**Уточнение постановки.** Задача называла второй маршрут `reset-password`,
но email-аргумента у него нет вообще — там `user_id`, `reset_key`,
`new_password`. Реальным вторым вхождением был `organizer-complete`
(после правок DEV-70 номера строк сдвинулись). Исправлены оба реальных
места; отсутствие email в `reset-password` зафиксировано тестом.

**Дополнительно найдено и исправлено** в том же маршруте
`organizer-complete`: `$email = sanitize_email( $request->get_param( 'email' ) )`
передавал `null` в `sanitize_email()` при незаполненном поле — deprecation
`strlen( null )` на PHP 8.1+. Добавлено приведение к строке.

**Поправка DEV-72 (28.09.2026).** Слой анкеты удалён, поэтому маршрут
`organizer-complete` и правка `sanitize_email()` к нему ушли вместе с ним.
В живом коде остался один затронутый маршрут — `auth/forgot-password`.
Тест `tests/test-email-validator.php` переработан: было 13 тестов, стало 10
(удалены три теста маршрута `organizer-complete`).

**Проверка.** `tests/test-email-validator.php`, 10 тестов / 22 утверждения. Наличие notice
контролирует сам набор WordPress: неожиданный `_deprecated_*` роняет тест.
Отдельно дефект подтверждён обратной проверкой — при возврате `'is_email'`
в `forgot-password` набор даёт 5 провалов, включая текст
`Function is_email was called with an argument that is deprecated since
version 3.0.0`.

---

### 7. РЕКОМЕНДУЕМЫЙ ПОРЯДОК ВЫПОЛНЕНИЯ

| Этап | Задачи | Цель |
|---|---|---|
| **1. Разблокировать прод** | DEV-03, DEV-01, DEV-04, DEV-07 | Безопасный запуск и работающий сценарий организатора |
| **2. Безопасность и право** | DEV-05, DEV-06, DEV-08 | Защита от автоматических регистраций, требования 152-ФЗ |
| **3. Закрыть обещания из ТЗ** | DEV-10, DEV-11, DEV-15, DEV-16, DEV-09/14/21 (решения) | Довести функционал до заявленного объёма |
| **4. Организаторы** | DEV-12, DEV-13 | Реквизиты, верификация, рабочая панель |
| **5. Данные и статистика** | DEV-17, DEV-18, DEV-30 | Достоверные метрики и наполненный контент |
| **6. Техдолг и качество** | DEV-22, DEV-23, DEV-24, DEV-26, DEV-27 | Устойчивость к изменениям, CI |
| **7. Документация** | DEV-19, DEV-20, DEV-25, DEV-28, DEV-29 | Соответствие документации коду |
| **8. Внешний API** | DEV-02 | После получения доступов к агрегатору билетов |

---

### 8. ТРЕБУЕТ РЕШЕНИЯ ВЛАДЕЛЬЦА ПРОДУКТА

1. **DEV-09** — система баннеров middle/bottom: реализовывать или зафиксировать отказ?
2. **DEV-14** — личный кабинет `/lk/`: возвращать или окончательно убрать из документации?
3. **DEV-21** — OneTap-виджет VK ID: возвращать или зафиксировать отказ?
4. **DEV-16** — автообновление `idpg-auth` с GitHub: включать или удалять апдейтер?
5. **DEV-06** — объём соответствия 152-ФЗ: достаточно ли чекбокса ПДн и cookie-баннера или нужны инструменты экспорта/удаления данных?
6. **DEV-02** — доступы и документация API агрегатора билетов.
7. **DEV-03** — доступы к хостингу (SSH/панель) для включения HTTPS и правки `wp-config.php`.

---

### 9. ЧТО УЖЕ НЕ ТРЕБУЕТ ДОРАБОТКИ

Проверено по коду и снимается с учёта (было в аудитах, но уже исправлено):

- `error_log()` в боевом коде — отсутствует (остались только комментарии-пояснения).
- Запрос города по IP — сначала HTTPS, fallback на HTTP (исправлено в 1.0.9).
- `node_modules` в теме — отсутствует, в репозиторий не попадает.
- Дублирование дефолтного города — заменено константой `IDPG_DEFAULT_CITY_ID`.
- Автосоздание страниц `/login/`, `/reset-password/`, `/terms/`, `/privacy/`, `/offer/` — реализовано.
- PHP-fallback форм авторизации без JavaScript — реализовано.
- Скрипты сборки темы (`npm run check:css`, `compile:css:temp`, `compile:css:apply`,
  `watch`, `lint:scss`, `lint:js`) — присутствуют. Состав уточнён DEV-144:
  `compile:css` разделён на безопасную проверку и явную перезапись.

---

### 10. АУДИТ DEV-00A (19.09.2026): СОСТОЯНИЕ «DEV-33…36» ПО ФАКТУ КОДА

> Проверка дерева **idpg-core 1.1.2 / тема 1.0.75**. Полный отчёт — `docs/archive/docs/archive/README_DEV-00A.md`
> (включая gap-лист G1–G10 и предложения по переклассификации). Здесь — статус именно
> заявленного диапазона «DEV-33…36».

| Заявленная тема | Фактический номер | Состояние в дереве |
|---|---|---|
| «DEV-33–34: каркас ЛК `/organizer/`» | DEV-33 занят (rate limiter), DEV-34 — каркас | Файлы каркаса есть, но **не подключены** (G1–G3): нет `require_once` `class-organizer-url.php`, обвязки `organizer-menu.php`, ассетов, страница `/organizer/` не создана |
| «DEV-35: список/создание событий организатора в ЛК» | Свободного DEV-35 нет | **Закрыто DEV-94 (29.09.2026)**: раздел `events` наполняется из `/organizer/events`, форма `/organizer/event/new/` + `/organizer/event/{id}/` работает (G5–G6 сняты) |
| «DEV-36: доуполнение анкеты организатора» | Совпадало с DEV-01 (P0) | **Закрыто DEV-72**: слой анкеты удалён целиком (G4 снят) |

**Корректировка:** каркас ЛК (DEV-34) и связанные DEV-37/DEV-38 помечены в этой версии
документа как «выполнено», что верно для целевых версий 1.1.7…1.1.8 / 1.0.79…1.0.81, но
**не отражает текущее рабочее дерево 1.1.2 / 1.0.75**. До применения обвязки (G1–G3) эти
задачи считаются **открытыми на подключение**. ЛК-события («DEV-35») и доуполнение
(«DEV-36» = DEV-01) — открытые задачи.

**Обновление 29.09.2026:** G1–G4 закрыты (DEV-37/DEV-38, DEV-72), G5–G6 закрыты
(DEV-94). ЛК-события («DEV-35») закрыты. Остаётся открытым только доуполнение
(«DEV-36» = DEV-01) — снято DEV-72 вместе с анкетой.

---

### 11. ТРЕБУЕТ РЕШЕНИЯ ВЛАДЕЛЬЦА (из DEV-00A)

1. Нумерация «DEV-33…36» из исходной формулировки не совпадает с актуальной `docs/PROJECT.md (раздел 2)`
   (DEV-33 = rate limiter, DEV-34 = каркас ЛК). Подтвердить соответствие: заново выделить
   номера под «список/создание событий в ЛК» и «доуполнение анкеты».
2. Параметр pending-анкеты: унифицировать ключ `idpg_org_pending` / `_idpg_org_pending`
   (сейчас читается со знаком и без — G7, S).
3. «Платная модерация события» как продукт: подтвердить, нужна ли между оплатой и публикацией
   (сейчас модерация только по платности, к оплате не привязана — G10).

---

#### DEV-81. `default_role = subscriber` противоречит модели

> **Статус: закрыто решением владельца 28.09.2026 — оставлено `subscriber`.**
> Боевая БД не менялась. Задача оказалась невыполнимой через API опций.

**Почему не выполнено.** WordPress не даёт пустую `default_role`, пока
существует роль `subscriber`. В `sanitize_option()`
(`wp-includes/formatting.php:5158`):

```php
case 'default_role':
	if ( ! get_role( $value ) && get_role( 'subscriber' ) ) {
		$value = 'subscriber';
	}
	break;
```

`update_option( 'default_role', '' )` проходит через `sanitize_option()`,
значение молча заменяется на `subscriber`, и функция возвращает `false` без
записи в БД. Обойти можно только прямым `UPDATE wp_options` в обход
валидации ядра — сознательно не сделано.

**Что проверено до решения.**

- Регистрация организатора от `default_role` **не зависит**: `class-rest-auth.php`
  и `page-login.php` явно вызывают `set_role( 'subscriber' )`, потому что
  `default_role` может быть чем угодно. При `default_role = ''` регистрация
  даёт тот же результат: пользователь создан, `subscriber` выставлен явно,
  `event_organizer` ещё не выдан, флаг `idpg_org_pending` выставлен,
  `idpg_org_name` / `idpg_contact_name` / `idpg_phone` сохранены.
- Пользователь, созданный в админке без выбора роли, при `default_role = ''`
  получает **ноль ролей** (не `subscriber`). В кабинет он не пускается —
  `get_access_decision()` даёт `ACCESS_DENIED` (403). В `wp-admin` его
  не пустит ядро: у пользователя без роли нет ни одной capability.
- Побочный эффект, который пришлось бы править кодом: `IDPG_Auth::restrict_admin_access()`
  (`class-auth.php`, ~строка 206) проверяет **только** роль `subscriber`, поэтому
  безролевого пользователя он не перенаправляет на главную. Это не дыра в
  безопасности ( защиту держит ядро), а косметика. Код не трогал — правка
  выходит за рамки задачи.

**Тесты.** `plugins/idpg-core/tests/test-default-role.php` — 8 тестов /
28 утверждений. Набор фиксирует и поведение ядра (`update_option`
не пишет пустую `default_role`), и независимость регистрации от значения
опции. Полный прогон: `idpg-core` 232 теста / 564 утверждения — OK.

**Как вернуться к задаче, если модель изменится.** Требуется убрать роль
`subscriber` из проекта (а не только из `default_role`) — тогда
`sanitize_option()` перестанет её подставлять. Но `subscriber` сейчас
используется в коде как осознанная промежуточная роль: `set_role( 'subscriber' )`
при регистрации (ожидание подтверждения email), `IDPG_CLI::TARGET_ROLE`
(чистка незарегистрированных, DEV-71), guard `restrict_admin_access()`,
`get_user_role()` в REST. Поэтому сначала нужна смена модели «роль ожидания
подтверждения», и только потом — `default_role`.

---

#### DEV-82. Актуализировать docs/PROJECT.md (раздел 1) §6 «ЛК организатора»

> **Проверено 30.09.2026 (DEV-148). ЗАКРЫТО — обе сверки подтвердились по документу.**
> §6 помечен как сверенный с деревом, а не по отчёту DEV-00A; gap-лист содержит
> G1–G13 с ссылками на закрывшие карточки. Уточнение по **G10** подтвердилось:
> платный показ в баннере реализован (`_idpg_banner_request` → `_idpg_banner_approved`),
> открытой осталась только оплата за публикацию события. Уточнение по **G8**
> подтвердилось и снято: `class-privacy.php` подключён, согласие обязательно при
> регистрации. Список «есть, но не подключено» (§6.2) теперь пуст —
> «Других неподключённых модулей кабинета в дереве не осталось».

> **Статус: выполнено 28.09.2026. Только документация, код не трогался.**

**Проблема.** §6.1–6.3 утверждали, что `class-organizer-url.php`,
`inc/organizer-menu.php`, `organizer.js` / `organizer.css` и страница
`/organizer/` «не подключены», а gap-лист G1–G10 был актуален на 19.09.2026 —
до применения обвязки DEV-37/38/39.

**Сверка по коду** (не по прежнему отчёту) дала: G1, G2, G3 подключены и
работают; G4, G7, G9 закрыты удалением анкеты (DEV-72); G5, G6, G8, G10, G11
открыты. §6 переписан, в gap-лист добавлены ссылки на закрывшие DEV-ID и
новая строка G12 (`default_role`, см. DEV-81).

**Уточнение по G10.** ПостановкаDEV-82 называла G10 «banner_approved —
открыт», но по коду это не так: платный показ в баннере **реализован** —
заявка `_idpg_banner_request` (организатор) → подтверждение
`_idpg_banner_approved` (админ) → событие попадает в выборку баннера
(`class-current-event-banner.php:707-713`), плюс колонка статуса в списке
событий (`class-meta.php`, методы `add_banner_column` ~1096 и
`render_banner_column` ~1108). Открытой осталась только оплата за
**публикацию** события (не за показ в баннере) — её в коде нет. В
`docs/PROJECT.md (раздел 1)` G10 переформулирован соответственно.

**Уточнение по G8.** `class-privacy.php` не подключён (1322 строки мёртвого
кода, 152-ФЗ на сайте не работает). Внутри файла есть
`IDPG_Privacy::instance()` на строке 1322, но он не выполняется — сам файл
не включён ни в один `require_once`. В постановке G8 вёл в DEV-41, ранее
значился DEV-06; в документе указаны оба.

> **Обновление 28.09.2026: G8 закрыт.** См. карточку ниже.

---

#### G8. Подключить модуль 152-ФЗ (`class-privacy.php`)

> **Статус: выполнено 28.09.2026.**

**Проблема.** Модуль `includes/class-privacy.php` (1322 строки) был написан,
но не подключён, и обвязка отсутствовала: `record_consent()` и
`render_subject_tools()` не имели ни одной точки вызова. То есть согласие
на обработку ПДн не фиксировалось, cookie-баннера не было, субъект не мог
воспользоваться правом на выгрузку или удаление данных. Тестов — 0.

**Решение владельца:** согласие при регистрации — **обязательный** чекбокс
с серверной проверкой (не «мягкий» и не только баннер). Известное последствие:
снижение конверсии регистрации.

**Что сделано.**

- `idpg-core.php`: `require_once class-privacy.php` **до** `class-rest-auth.php`
  (регистрация обращается к константе модуля). Сам модуль вызывает
  `IDPG_Privacy::instance()` в конце файла.
- `class-rest-auth.php`: аргумент `idpg_pdn_consent` в схеме
  `/auth/register` и проверка в обработчике. Проверка стоит **после** проверки
  `type` и **до** расхода лимита попыток — иначе запрос `type=user` получал бы
  400 про согласие вместо 403 «регистрация только организаторам» (это ломало бы
  DEV-70 и выдавало бы не-организатору факт существования регистрации
  организатора), а отказ по согласию съедал бы попытку из лимита.
  `required` в схеме намеренно `false` — при `true` валидация REST
  перехватывала бы запрос до всех проверок обработчика.
- `IDPG_Privacy::record_consent( $user_id, 'organizer' )` после создания
  пользователя — и в REST, и в PHP-fallback.
- `page-login.php`: отдельный чекбокс согласия в разметке + проверка. Имя поля
  вынесено на уровень файла с фолбэком `'idpg_pdn_consent'`, чтобы тема
  работала и без плагина, а не падала на отсутствующем классе.
- `page-privacy.php`: вызов `IDPG_Privacy::instance()->render_subject_tools()` —
  блок «Ваши права» (выгрузка JSON + запрос на удаление).

**Проверка на живом сайте:** главная `200`, cookie-баннер и его ассеты
отдаются; `/privacy/` `200`, блок прав субъекта присутствует; POST без
согласия → `400 pdn_consent_required`.

**Тесты.** `tests/test-privacy.php` — 25 тестов / 88 утверждений: обязательность
согласия, разбор истинных/ложных значений (включая `'1'`, `'true'`, `''`),
приоритет проверок, отсутствие расхода лимита, запись согласия, cookie-выбор,
выгрузка данных и исключение `session_tokens`. Обновлены три существующих
набора регистрации — они теперь передают новое обязательное поле.
Полный прогон: `idpg-core` **257 тестов / 652 утверждения — OK**, `idpg-auth` 5/9.

**Не сделано:** отзыв согласия пользователем в интерфейсе (бэкенд `has_consent()`
есть, кнопки нет); выгрузка в CSV упомянута в админ-странице, но `collect_user_data()`
отдаёт структуру, пригодную для JSON.

---

#### DEV-42. REST `/organizer/events` — события организатора — ЗАКРЫТО (28.09.2026)

> **Статус: выполнено 28.09.2026.**

**Проблема.** Раздел «Мои события» в кабинете был заглушкой, а REST-маршрутов
для событий не существовало: ни списка, ни создания, ни отправки на модерацию.
Профиль (`/organizer/profile`) закрыт DEV-73 и сюда не дублировался.

**Что сделано.** Новый класс `includes/class-rest-organizer.php`
(`IDPG_REST_Organizer`), зарегистрирован в `idpg-core.php`. Семь маршрутов в
namespace `idpg/v1`: список (`GET /organizer/events`), одно событие
(`GET /organizer/events/{id}`), создание (`POST`), обновление (`PUT`),
отправка на модерацию (`POST .../{id}/submit`), удаление (`DELETE`),
статистика (`GET /organizer/stats`).

**Решения, которые стоит знать.**

1. **Скоуп — только свои события.** Список всегда ограничен `author`; чужое
   событие по прямому ID даёт `403`, а не `404` — иначе 404 выдавал бы факт
   существования чужой записи. Админ и редактор (`edit_others_events`) видят всё.
2. **`pending` не редактируется и не удаляется → `409`.** Не `403`: это конфликт
   состояний, а не запрет. Если бы организатор мог править событие на
   модерации, он обошёл бы проверку, изменив текст прямо во время ревью.
   Опубликованное — `403` (403 закрыто, 409 занято под конфликт).
3. **Модерация не дублировалась.** Правила бесплатного/платного уже были в
   `IDPG_Event_Moderation`, но их штатный триггер — хук Carbon Fields, который
   на REST-пути не срабатывает. Поэтому `submit` вызывает уже существующий
   публичный `schedule_price_moderation()` явно. Продакшн-код модерации не менялся.
4. **Явная проверка nonce** в `permission_callback` — по образцу
   `IDPG_REST_Api::can_manage_events()`. Сам REST проверяет nonce только при
   cookie-аутентификации.
5. **Статус `rejected` не существовал в проекте вообще** — ни как статус, ни как
   строка. Постановка на него рассчитывает, поэтому он зарегистрирован в новом
   классе (`register_post_status()`, `protected`). Переходы
   `rejected → draft / pending` работают и покрыты тестами.

**Тесты.** `tests/test-rest-organizer.php` — 35 тестов / 78 утверждений:
401 для гостя, 403 для подписчика, 403 при отсутствии/битом nonce, 403 на чужое
событие (чтение, правка, удаление, отправка), изоляция списка, 409 при правке
`pending`, 403 при правке `publish`, 400 при неполных данных перед отправкой,
санитайз HTML и JSON сеансов, отказ несуществующей площадки, автопубликация
бесплатного, возврат в черновик платного без билетов, фильтры списка,
статистика только по своим событиям.

Проверка обратным прогоном: при ослаблении сравнения `post_author` падают
ровно два теста — `test_cannot_read_another_organizers_event` и
`test_cannot_modify_another_organizers_event`.

Полный прогон: `idpg-core` **292 теста / 730 утверждений — OK**, `idpg-auth` 5/9.
Проверка на живом сайте: все 5 уникальных путей в `wp-json`, гость без nonce
получает `403 idpg_org_nonce_failed`.

**Что осталось за DEV-42** (в G5/G6):

- Страницы и UI создания/правки события: `/organizer/event/new/`,
  `/organizer/event/{id}/`, `organizer-event-form.js`. Сейчас API есть, формы нет.
- Разделы `dashboard` и `events` в кабинете по-прежнему заглушки — они не
  читают `/organizer/events` и `/organizer/stats`.
- ~~Модераторский сценарий~~ — **закрыт DEV-46**, см. ниже.

---

#### DEV-46. Модерация: кнопка «Отклонить» и уведомление автору — ЗАКРЫТО (28.09.2026)

> **Статус: выполнено 28.09.2026.**

**Проблема.** DEV-42 зарегистрировал публичный статус «Отклонено модератором»
(`rejected`) и переходы `rejected → draft / pending` из кабинета, но
выставить статус было нечем: в админке не было ни кнопки, ни уведомления
автору. Статус существовал только как перечисление в коде.

**Что сделано.** Новый класс `includes/class-event-rejection.php`
(`IDPG_Event_Rejection`):

- row action «Отклонить» в списке событий — только для статуса `pending` и
  только при `edit_others_events`;
- подстраница «Иду по городу → Отклонить событие» с предпросмотром и
  необязательным полем «Причина отклонения»;
- `admin_post.php?action=idpg_reject_event`: проверка прав → проверка nonce →
  статус `rejected`, причина в `_idpg_rejection_reason`, дата в
  `_idpg_rejected_at`, хук `idpg_event_rejected( $post_id, $reason, $actor )`;
- письмо автору (всегда, причина попадает в текст при наличии);
- повторное отклонение отклонённого события — ошибка, а не второе письмо.

Одобрение не трогал: его роль выполняет обычная публикация записи, письмо
об одобрении шлёт `IDPG_Event_Moderation`.

**Решения.**

1. **Отдельный класс, а не дописывание `IDPG_Event_Moderation`** (ограничение
   задачи). Модерация по платности и отклонение редактором — разные решения
   с разными последствиями для события; смешивать их в одном модуле не стоит.
2. **Логика вынесена в публичный `reject_event()`,** а `handle_reject()` только
   проверяет права/nonce и редиректит. Обработчик заканчивается `exit`, и
   такой путь иначе не покрыть тестом. Первая версия теста дублировала
   логику отклонения — это опасно: тест продолжил бы «зеленеть» после поломки
   продакшна. Теперь тест вызывает тот же метод.
3. **Причина сохраняется в meta,** а не передаётся в письмо из сессии: автор
   должен видеть причину и при повторном заходе в кабинет.

**Найдено и исправлено попутно (регрессия DEV-42).** Выгрузка определений
Carbon Fields (`docs/carbon-fields-export.json`) показала, что галерея
(`idpg_gallery`, тип `media_gallery`) хранится Carbon Fields **не** под
ключом `_idpg_gallery`, а в собственном формате `_idpg_gallery|||0|value`
(для пустого — `...|||0|_empty`). DEV-42 писал и читал `idpg_gallery` без
подчёркивания обычными `update_post_meta()` / `get_post_meta()`, поэтому
галерея, заданная из кабинета, была не видна в админке — и наоборот.
Исправлено на `carbon_set_post_meta()` / `carbon_get_post_meta()`;
покрыто `test_gallery_roundtrip_uses_carbon_storage` (падает на старом коде)
и `test_empty_gallery_reads_as_empty_array`.

Тот же формат касается complex-поля сессий: `_idpg_sessions|||0|_empty`.
Код на него не опирается — `sessions_json` пишется в зеркало
`_idpg_sessions_json`, которое заполняется через Carbon Fields отдельно.

**Тесты.** `tests/test-event-rejection.php` — 19 тестов / 41 утверждение:
row action для админа и его отсутствие для организатора, для `draft` и для
не-`event`; отклонение ставит статус; причина сохраняется и попадает в
письмо; письмо уходит автору, а не модератору; HTML в причине экранируется;
письмо отправляется и без причины; двойное отклонение не шлёт второе письмо;
**обработчик формы отказывает без прав и без nonce** (wp_die перехвачен
фильтром `wp_die_handler`, чтобы не завершать процесс); организатор после
отклонения правит событие через REST и повторно отправляет на модерацию;
событие видно в списке с фильтром `rejected`.

Проверка обратным прогоном: при снятии guards в `handle_reject()` падают
ровно два теста — `test_handler_requires_capability` и
`test_handler_requires_nonce`.

Полный прогон: `idpg-core` **311 тестов / 771 утверждение — OK**, `idpg-auth` 5/9.

**Побочная находка (не баг).** В тестах перехватывались два письма автору,
что выглядело как дубль. Разбор показал: второе — приветственное письмо
`user_register` → `send_welcome_email`, отправляемое при создании тестового
пользователя. К отклонению отношения не имеет.

---

#### DEV-47. Актуализировать `docs/PROJECT.md (раздел 1)` §6 после DEV-42/DEV-46

> **Проверено 30.09.2026 (DEV-148). ЗАКРЫТО — работа подтверждена по документу.**
> §6.3 — таблица «чего нет» со ссылками на DEV-43/44, DEV-45, G10, G11 и отдельный
> блок «что закрыто» с DEV-42, DEV-46, DEV-72; дубль про `/organizer/complete/` убран.
> Строка G13 на месте. §6.5 перечисляет DEV-37/38/39, DEV-42, DEV-46, DEV-73.
> **Одно уточнение:** заявленная формулировка G5 и G6 «Открыт (API готов, UI
> в работе)» устарела — обе карточки закрыты 29.09.2026 (DEV-94) и в gap-листе
> зачёркнуты. Расхождение не в карточке, а в том, что документ ушёл вперёд.

> **Статус: выполнено 28.09.2026. Только документация, код не трогался.**

**Проблема.** После DEV-42 и DEV-46 §6 описывала состояние неполно: разрыв
по кабинету событий был записан одной строкой «REST готов, UI — нет» без
разделения, часть пунктов задваивалась, а закрытый DEV-46 не имел в
gap-листе собственной строки.

**Что сделано.**

- **§6.3** переписана таблицей «чего нет» с задачами: UI создания/правки
  события (DEV-43/44), живые разделы «Дашборд» и «Мои события» (DEV-45),
  оплата за публикацию (G10), выбор аватара (G11). Отдельным блоком
  «что закрыто» вынесены DEV-42, DEV-46, DEV-72 — чтобы они не читались
  как разрывы. Убран дубль про `/organizer/complete/`.
- **G5 и G6** разведены по задачам и обе переформулированы одинаково:
  «Открыт (API готов, UI в работе)». Раньше G6 указывал на DEV-44, хотя
  дашборд — это DEV-45.
- **G13** — новая строка на закрытый DEV-46 (кнопка «Отклонить» + письмо
  автору), с ссылкой на §6.2.3 и набор тестов.
- **§6.5** дополнена: в перечне закрытых частей кабинета добавлены DEV-73,
  DEV-42, DEV-46, а в «открытой работе» — конкретные DEV-43/44 и DEV-45.

**Проверка.** Файл не менялся иначе: `php -l` не применим к Markdown, порядок
строк gap-листа сохранён (G1…G13), таблицы отрендерены вручную — все строки
одной таблицы содержат одинаковое число колонок.

#### DEV-83. Тестовое покрытие PHP-fallback регистрации и JS-пути

> **Проверено 30.09.2026 (DEV-148). ЗАКРЫТО — все три файла покрытия на месте.**
> `tests/test-registration-form.php` — **14 тестов** (рендерит настоящий
> `page-login.php` из рабочей темы); `tests/js/auth-registration.test.js` —
> **13 проверок** `check()`, исполняет настоящий `auth.js` в node;
> `tests/test-auth-js.php` запускает node-тест из PHPUnit и пропускает его, если
> node не установлен. Оговорка про CSRF-гейт всего POST страницы
> (`page-login.php` ~строка 136, поле печатается во всех трёх формах)
> подтверждается структурой файла.

**Проблема.** Покрыты были только REST-слой и логика плагина. Форма темы
(`page-login.php`) и `assets/js/auth.js` не были покрыты ничем — именно поэтому
28.09.2026 ушли две ошибки, которые зелёный набор не заметил:

1. чекбокс согласия на обработку ПДн стоял в **форме входа**, а не в форме
   регистрации: без JS регистрация была невозможна (сервер требовал поле,
   которого в форме не было), а форма входа требовала галочку, которую
   обработчик входа не читает и в согласии не фиксирует;
2. `auth.js` не отправлял `idpg_pdn_consent` в payload — реальный браузер
   не мог зарегистрироваться, хотя REST принимал такой запрос.

**Сделано.**

- `tests/test-registration-form.php` (14 тестов) — рендерит **настоящий**
  `page-login.php` из рабочей темы и проверяет разметку панелей, разбор
  `$_POST`, CSRF-гейт, запись согласия (`source`, `version`, `given`,
  `ip_hash`), ожидание подтверждения. Регрессии ловят обе ошибки выше.
- `tests/js/auth-registration.test.js` (13 проверок) — исполняет настоящий
  `auth.js` в node: минимальный стаб DOM, перехват `fetch`. Проверяет payload
  при отмеченном/неотмеченном согласии, отсутствие запроса без согласия и то,
  что **отсутствие чекбокса даёт явный `false`, а не `undefined`**.
- `tests/test-auth-js.php` — запускает node-тест из PHPUnit, пропускает его,
  если node не установлен.

**Найдено попутно.** Проверка CSRF (`page-login.php:136`) гейтит **весь** POST
страницы, включая регистрацию: без валидного `idpg_login_nonce` не выполняется
ничего. Поле печатается во всех трёх формах (строки 338, 401, 462), так что
в реальной отправке всё в порядке — но тест обязан передавать nonce, иначе
падёт не по той причине. Это зафиксировано тестами.

**Проверка.** Обратная проба: возврат чекбокса в форму входа даёт 3 падения,
удаление поля из payload `auth.js` — 4 падения. После восстановления:
`idpg-core` 328 тестов / 825 утверждений OK, `idpg-auth` 5 / 9 OK.
Живая проверка `/login/`: согласие в панели регистрации, в панели входа его нет,
PHP-предупреждений нет.

#### DEV-84. Хвосты после DEV-83: версии, сиротские события, бэкапы, утечка документации

> **Проверено 30.09.2026 (DEV-148). ЗАКРЫТО по коду; один пункт требует подтверждения
> владельца в wp-admin.**
> п. 1 — версия синхронна: `Version: 1.0.78` в `style.css` и
> `IDPG_VERSION` = `1.0.78` в `functions.php` ~строка 20. Гард —
> `tests/test-theme-version.php`.
> п. 3 — бэкапы вне веб-корня: `D:\laragon\backup\idupogorodu\users-backup-2026-09-28.json`
> существует, в `wp-content` такого файла нет.
> п. 4 — защита на месте: `docs/.htaccess` (`Require all denied`) и
> `themes/idpg_core/.htaccess` (deny на `md|neon|log|bak|sql|po|pot|dist|orig|rej`).
> п. 5 — `includes/class-cli.php` на месте.
> **п. 2 (события 693–695 в корзине) из репозитория не проверяется:** это состояние
> БД, а не файлы, а доступные REST-инструменты не отдают маршруты CPT
> (`rest_no_route`). Статус «в корзине, а не удалены» подтвердить нечем — это
> остаток проверки, а не открытая работа.

По поручению владельца G5, G6, G10, G11 не трогаются. Закрыто следующее.

**1. Версия темы.** `style.css` содержал `Version: 1.0.73` при
`IDPG_VERSION = 1.0.76`. Поднято до **1.0.77** в обоих местах: правка формы
(DEV-83) — это изменение поведения, и версия нужна для прослеживаемости.

> ⚠️ **Уточнение DEV-138 (30.09.2026).** Подъём до 1.0.77 в дереве **не сохранился**:
> после сбоя git (DEV-102) в `functions.php` (тогда ~строка 11; после DEV-138 —
> ~строка 20) стояла `IDPG_VERSION = '1.0.75'`,
> а в `style.css` — `Version: 1.0.73`; строки `1.0.77` в теме не было вовсе.
> Та же природа, что у регрессий DEV-39 (найдены DEV-130) и `ROLE_VERSION` (DEV-133).
> **Фактическая версия — 1.0.78** (DEV-138): поднята в обоих местах, значения
> синхронизированы. Историческая запись ниже («Версия ассета на живой странице:
> `auth.js?ver=1.0.77`») относится к проверке 28.09.2026 и на текущее состояние
> не распространяется.

**2. Три опубликованных события без автора** (ID 693–695, `post_author = 75`).
Идентификация: ID 75 — `organizator-delta` (`org-delta@idupogorodu.test`),
тестовый организатор из `users-backup-2026-09-28.json`. Содержимое всех трёх
событий — строка «Тестовое событие организатора organizator-delta.», комментариев
и заявок нет, по 6 служебных meta-полей. Это тестовые фикстуры удалённого
аккаунта, а не контент. Отправлены **в корзину** (`wp_trash_post`), не удалены
навсегда: обратимо. Опубликованных событий на сайте теперь 0.

**3. Бэкапы пользователей.** Лежали во временной папке, содержат email'ы и
хэши паролей. Перенесены в `D:\laragon\backup\idupogorodu\` — **за пределы
веб-корня**; проверено по HTTP, отдавать их нечем (404). Копия
`carbon-fields-export.json` удалена из temp: в репозитории есть идентичная
копия в `docs/`.

**4. Утечка внутренней документации.** Обнаружено при проверке п. 3: каталог
`wp-content/docs/` отдавался публично — `docs/PROJECT.md (раздел 2)` (197 КБ, перечень всех
известных пробелов и незакрытых задач), `docs/PROJECT.md (раздел 1)`, `docs/archive/PLUGIN_TZ.md`,
выгрузка Carbon Fields, а также `phpcs-baseline.neon` и `*.md` темы. Добавлены
`docs/.htaccess` (`Require all denied`) и `.htaccess` темы ( deny на
`md|neon|log|bak|sql|po|pot|dist|orig|rej`). Проверено: документация 403,
`style.css`, `auth.js`, `functions.php` — 200, сайт не сломан. Сервер —
Apache 2.4, директива поддерживается.

**5. WP-CLI / DEV-71.** Бинарника в системе нет, но команда реализована
(`includes/class-cli.php`) и покрыта юнит-тестами. Проверена напрямую с
подменой класса `WP_CLI`: dry-run отрабатывает, находит 0 аккаунтов
(база чистая), а реальный организатор ID 109 мусорным не считается; защита от
несуществующего `--reassign-to` срабатывает. Устанавливать `wp-cli.phar` ради
этого не потребовалось. Если команда нужна именно из консоли — phar ставится
отдельно.

**Проверка.** Сайт: `/`, `/login/`, `/privacy/`, `/terms/`, `/organizer/` — 200.
`idpg-core` 328 тестов / 825 утверждений OK, `idpg-auth` 5 / 9 OK.
Версия ассета на живой странице: `auth.js?ver=1.0.77`.

#### DEV-85. Стили кабинета организатора (`.idpg-org-*`) — ЗАКРЫТО (формально DEV-147)

> **Уточнение DEV-147 (30.09.2026).** Работа по стилям сделана, но её
> описание ниже устарело в двух местах — читать его как есть нельзя.
>
> 1. **`@use 'pages/organizer'` в `style.scss` больше не подключён — намеренно.**
>    Правила `.idpg-org-*` отдаются отдельным файлом `assets/css/organizer.css`,
>    который вешается через `wp_enqueue_style` только на `/organizer/*`. Подключение
>    партиала в общую сборку продублировало бы весь кабинет на каждой странице сайта.
>    Проверено: в `organizer.css` 46 вхождений `.idpg-org-`, гард сборки зелёный.
> 2. **«Риск, который НЕ тронут» (пересборка `style.css` удаляла бы 71 правило)
>    снят.** DEV-87 перенёс оставшиеся правила в партиалы, DEV-143 довёл
>    расхождение до нуля. Гард `tests/test-css-build.php` зелёный в общем
>    прогоне, полная пересборка больше не откатывает дизайн.
>
> Статус карточки — ЗАКРЫТО: заявленный результат (стили кабинета есть) достигнут,
> способ выдачи изменился осознанно.

**Проблема.** Разметка `/organizer/` и её разделы используют нейминг
`idpg-org-*` и `idpg-notice*`, для которого **не было ни одного правила** ни в
одном SCSS-файле, ни в собранном `style.css`. Форма профиля (DEV-73) выводилась
без стилей. Остальной кабинет (`page-organizer.php`, дашборд, «Мои события»)
был в том же состоянии: не оформлены ни оболочка, ни сайдбар, ни заголовки
разделов, ни уведомления. Стилизованы были только `.idpg-btn*`
(`components/_header.scss`).

**Сделано.** Добавлен `assets/scss/pages/_organizer.scss` (58 правил) и
`@use 'pages/organizer'` в `assets/scss/style.scss`. Покрыто:

- оболочка и отказ в доступе: `idpg-org-page`, `idpg-org-denied*`;
- шапка, раскладка, липкий сайдбар с горизонтальной лентой на узких экранах:
  `idpg-org-header*`, `idpg-org-layout`, `idpg-org-sidebar`, `idpg-org-nav*`;
- разделы, уведомления, карточки дашборда, пустое состояние:
  `idpg-org-section*`, `idpg-notice*`, `idpg-org-card*`, `idpg-org-empty*`;
- форма профиля: `idpg-org-profile*`, `idpg-org-field*` (в т.ч. `__hint`,
  `__avatar`, `readonly`-стиль email, две колонки на планшете и шире).

Токены взяты из `base/_variables.scss`, поля повторяют `.auth__input`, чтобы
форма профиля не выглядела отдельным миром.

**Важно: `hidden` против `display`.** Разделы и строка статуса выводятся с
атрибутом `hidden`, а собственный `display` перебивает UA-правило
`[hidden] { display: none }`. Для `.idpg-org-section` и
`.idpg-org-profile__status` правило `display` продублировано явно — без этого
показывались бы сразу все разделы кабинета.

**Риск, который НЕ тронут.** Полная пересборка `style.css` из SCSS
(`npm run compile:css`) **удалила бы 71 правило**: `.auth__hero*`,
`.auth__password-toggle`, `.idpg-city-selector*`, `.idpg-city-modal*`,
`.idpg-bottom-bar*`, `.idpg-event-card a`, `.idpg-pagination a`,
`.site-footer a` и другие — они есть только в `style.css` и в SCSS не
портированы. Поэтому новый блок скомпилирован отдельно (Dart Sass 1.69.5) и
дописан в конец файла, а `style.css` целиком не пересобирался. Проверено:
скобки сбалансированы (637/637), блок на верхнем уровне, все прежние правила
на месте. **Перед следующей полной пересборкой нужно сначала портировать эти
71 правило в SCSS** — иначе логин-герой, «глаз» пароля, выбор города и мобильная
нижняя панель отвалятся.

**Проверка.** Все 12 классов формы профиля стилизованы, без единого пропуска.
`style.css` по HTTP 200, содержит и новые, и прежние правила. Версия темы
1.0.78 в `functions.php`, `style.css` и `assets/scss/style.scss`. Наборы:
`idpg-core` 328/825 OK, `idpg-auth` 5/9 OK. Страницы `/`, `/login/`,
`/privacy/`, `/organizer/` — 200.

**Побочно.** Скрипт `npm run lint:scss` нерабочий: в теме нет
`.stylelintrc.json`, хотя он исключён из архива в `package.json`.

#### DEV-87. Перенос style.css в SCSS-партиалы: сборка больше не откатывает дизайн — ЗАКРЫТО DEV-143 (формально DEV-147)

**Постановка была неточной, и это выяснилось до правок.** Оценивать потери
grep'ом по селекторам нельзя: реальный разбор (postcss, селектор + декларации +
контекст `@media`) показал не 71, а **96 отсутствующих правил** — и, что важнее,
совершенно другую проблему.

**В чём было дело.** `style.css` был построен на системе CSS Custom Properties:
`:root` с **103 переменными** `--idpg-*`, **373 ссылки** на них. SCSS-дерево о
токенах не знало вовсе (1 упоминание, `:root` отсутствовал) и держало жёсткий
hex. Плюс 13 правил отличались структурно, включая всю вёрстку логина (живая
`.auth` — флекс-колонка на всю высоту с hero-блоком; в SCSS — старая
центрированная карточка 440px).

То есть SCSS был не «немного неполным», а предыдущим поколением того же
дизайна. Пересборка удаляла `:root` → 373 ссылки становились невалидными по
всему сайту. Git-репозитория нет, истории расхождения не существует.

**Решение — не дописывать 96 правил, а сделать `style.css` исходником.** Живой
CSS перенесён в партиалы **дословно** (декларации не переписывались), по
границам компонентов, непрерывными сегментами. Порядок `@use` в `style.scss`
совпадает с порядком правил в исходнике, поэтому каскад не изменился.

`assets/scss/` (25 партиалов вместо 13):

| Каталог | Содержимое |
|---|---|
| `base/` | `_tokens.scss` (103 токена + `@font-face`), `_reset.scss`, `_typography.scss`, `_variables.scss` (не используется) |
| `components/` | `_header`, `_nav`, `_auth-dropdown`, `_menu-toggle`, `_city-selector`, `_city-modal`, `_bottom-bar`, `_search`, `_footer`, `_container`, `_buttons`, `_home-sidebar`, `_widget-areas`, `_recent-events`, `_mobile` |
| `pages/` | `_auth`, `_create-event`, `_cabinet`, `_dashboard`, `_organizer` |
| корень | `responsive.scss`, `style.scss` |

Отклонения от списка файлов в постановке: вместо `layout/_footer.scss` —
`components/_footer.scss` (каталог `layout/` в дереве не существует), вместо
отдельного `_pagination.scss` правило пагинации осталось в `components/_mobile.scss`
вместе с остальными тач-таргетами: выносить его оттуда означало бы разорвать
непрерывность сегмента и изменить каскад.

**Сборка.** `npm run compile:css` → 578 правил против 578 в эталоне:
**потеряно 0, отличий 0, лишнего 0**. Повторная сборка даёт байт-в-байт
идентичный файл.

**Одна правка в декларации.** `calc(32px + 344px)` Sass сворачивал в `376px`.
Результат тот же, но терялась читаемая связь с шириной сайдбара, поэтому
значение переписано через интерполяцию `calc(#{32}px + #{344}px)`.

**Сторож сборки.** `tests/test-css-build.php` — 5 тестов: собирает SCSS через
`npx sass` и сравнивает с `style.css` по правилам (селектор + декларации +
контекст `@media`), требуя нулевую разницу; отдельно проверяет наличие `:root`
и количества токенов. Без node пропускается.

---

**⚠️ СТАТУС на момент DEV-132: НЕ ЗАКРЫТО.** Перепроверка 30.09.2026.

> **✅ ЗАКРЫТО 30.09.2026 (DEV-143).** Расхождение устранено: `CHANGED` **88 → 0**,
> `MISSING` 0, лишних 0, оба гарда `Test_Css_Build` зелёные, полный набор —
> 529 тестов / 1581 утверждение без падений. Что именно сделано — карточка
> **DEV-143**. Ниже сохранён разбор DEV-132 как история.

Задача ставилась как «подтвердить закрытие» (заявлено: контрольная сборка даёт
байт-в-байт совпадение, MD5 `2655d57a…`, 91 613 байт). **Заявление не подтвердилось.**

Контрольная сборка воспроизводится **точно** — но она не равна живому `style.css`:

| | Байт | MD5 |
|---|---|---|
| Живой `style.css` | 79 743 | `62eb661b4ebc8c9beeba806200cf0de8` |
| Сборка `npx sass` | 91 613 | `2655d57a044e3ea4c1d584a7e6612907` |

> Замер сделан 30.09.2026 (DEV-132) и дал MD5 `f8ed3986c384009e487a2f2193962ac7`
> при тех же 79 743 байтах. Текущее значение — `62eb661b…`: DEV-138 заменил в
> заголовке `style.css` строку `Version: 1.0.73` → `Version: 1.0.78` (длина та же,
> правила CSS не тронуты). Вывод о расхождении сборки и `style.css` не меняется.

Переводы строк здесь ни при чём: в обоих файлах **0 символов CR** (оба LF),
нормированные по `tr -d '\r'` MD5 всё равно разные (3523 строки против 4170).
**Отличие содержательное, а не форматное.**

**Откуда взялась ложная уверенность.** В журнале DEV-129 (29.09) зафиксировано,
что проверка дала совпадение MD5 «у обоих» файлов. Объяснение — инцидент того же
дня: пробный прогон `npx sass … :style.css` **случайно перезаписал боевой
`style.css`** (79 743 → 91 613 байт). Сверка шла уже с затёртым файлом, поэтому
«совпало». Файл был восстановлен из git, и совпадение исчезло. **Замер был
испорчен, а вывод — неверен.**

**Что реально расходится (замер сторожа `Test_Css_Build`):**

- `MISSING` = **0** — ни одно правило не теряется;
- правил: `style.css` **525** против сборки **526** — лишнее ровно одно,
  `.idpg-city-selector__btn__icon` (правило дублируется в SCSS-дереве);
- `CHANGED` = **88** — и это тот самый остаток, который DEV-87 и называл:
  `.create-event__*` (40), `.dashboard__*` (24), `.cabinet__*` (21),
  `.idpg-ad-widget*` (3).

**Причина 88 расхождений — не формат, а откат системы токенов.** В `style.css`
эти правила используют `var(--idpg-*)`; SCSS-партиалы этих компонентов до сих
пор держат жёсткий legacy-hex:

| Файл | Hex-значений |
|---|---|
| `pages/_create-event.scss` | 78 |
| `pages/_dashboard.scss` | 41 |
| `pages/_cabinet.scss` | 38 |
| `components/_home-sidebar.scss` | 13 |
| **Итого** | **170** |

Сводка по всему файлу: ссылок `var(--idpg-*)` — 373 в `style.css` против 331
в сборке (**теряется 42**); hex-значений — 90 против 220 (**добавляется 130**).

`base/_variables.scss` уже переведён на `var(--idpg-*)` (строки 22–30), поэтому
основная масса компонентов собирается корректно — не мигрированы именно эти
четыре партиала плюс остаточный hex в `_variables.scss`.

**Следствие.** `npm run compile:css` **нельзя** запускать: он перезапишет
`style.css` и откатит токены в этих компонентах (цвета станут жёсткими
`#2563eb` / `#0f172a` / `#64748b` вместо `--idpg-primary` / `--idpg-ink` /
`--idpg-muted`). Об этом же предупреждает шапка `tests/test-css-build.php`
и комментарий в `_variables.scss`. Гард (`2 падения`) — **корректное** состояние,
он честно перечисляет остаток; «позеленить» его правкой теста было бы маскировкой.

**Что осталось сделать (для закрытия DEV-87):** перевести четыре партиала
(`_create-event`, `_dashboard`, `_cabinet`, `_home-sidebar`) и остаток
`_variables.scss` с hex на `var(--idpg-*)`; убрать дубль
`.idpg-city-selector__btn__icon` из SCSS. После этого `CHANGED` и `added` должны
стать нулями, оба CSS-теста — зелёными, и только тогда `style.css` можно
перегенерировать из SCSS.

Проверен обратной пробой, дважды:

1. удалён `.auth__password-toggle` (вне `@media`) — гард упал;
2. удалён `.idpg-bottom-bar__item--active` (**внутри** `@media`) — гард упал.

Вторая проба выявила дефект самого гарда: первая версия парсера не заходила
внутрь `@media` и видела 456 правил вместо 578, то есть молчала бы ровно там,
где потеря опаснее всего — нижняя панель и модалка целиком живут в `@media`.
Парсер переписан на рекурсивный спуск.

**Проверка.** Живой `style.css?ver=1.0.79` — 103 токена, 373 ссылки, все
компоненты на месте (глаз пароля, город, модалка, нижняя панель, пагинация,
футер, hero логина, соц. вход, safe-area, тач-таргеты). `/`, `/login/`,
`/privacy/`, `/terms/` — 200; `/organizer/` отдаёт 302 анонимным (это защита,
а не поломка). Разметка: 10/10 классов на главной, 9/11 на логине
(`auth__password-toggle` создаёт JS, `auth__hero-img` — conditional от
$hero_image_url, который не настроен). Наборы: `idpg-core` 333 теста /
831 утверждение OK (было 328/825), `idpg-auth` 5/9 OK.

**Инцидент.** При правке changelog в `functions.php` в expandable here-string
PowerShell последовательность `` `n `` превратилась в перевод строки, комментарий
разорвался, и сайт отдавал 500. Замечено сразу, исправлено, синтаксис
проверен `php -l`. Урок: литерал backtick в here-string — ловушка.

**Про активную дочернюю тему.** `idpg_core-child` подтверждена как активная
(минимальная: `functions.php` 1.3 КБ, `style.css` 338 байт, своих SCSS и
переопределений компонентов нет). Родительский `style.css` подключается как
`chld_thm_cfg_parent-css`, дочерний — как `idpg_core-style-css`.
#### DEV-10. Продвижение событий: срок закрепления и авто-снятие

**Проблема (подтверждена по коду).** Галочка «Закрепить в топе» ставила
`_idpg_promoted = 'yes'` навсегда. Снять закрепление мог только человек в
админке: поля «Закрепить до» не было, задачи `idpg_cron_unpin_expired` не было.
Забытое закрепление держало событие в подборках неограниченно долго.

**Найдено попутно.** Метку `_idpg_promoted` не использует НИ ОДИН запрос: её
читают только регистрация меты и REST-выдача. То есть обещание поля «Событие
поднимается наверх в списках и подборках» сейчас не выполняется — галочка
влияет только на JSON. Само продвижение в выдаче не реализовано, и это смежно
с **G10** (продуктовое решение по оплате публикации), поэтому здесь не
трогалось. Реализована та часть DEV-10, которая не зависит от продуктового
решения: срок, хранение состояния и его автоснятие.

**Сделано.**

- Новый класс `includes/class-promotion.php` (`IDPG_Promotion`):
  `META_PROMOTED` / `META_EXPIRES`, `CRON_HOOK = 'idpg_cron_unpin_expired'`,
  помощники `is_promoted()`, `is_expired()`, `is_active()`, `get_pin_expires()`,
  а также `schedule_unpin()` / `unschedule_unpin()` по образцу
  `IDPG_Ticket_Sync` (с защитой от задвоения).
- `unpin_expired()` — ежедневная задача, снимает `_idpg_promoted` у событий,
  у которых дата прошла. Идемпотентна: обрабатываются только события с
  закреплением, поэтому повторный запуск ничего не делает. Обход постраничный
  (200 за проход, максимум 50 проходов). Статус поста не фильтруется — снимать
  нужно и черновику, и событию на модерации. Дата окончания **сохраняется**:
  она нужна для истории, и повторное закрепление без новой даты честно снимется
  на следующем же запуске, а не будет висеть вечно.
- Хук `idpg_event_unpinned`.
- Поле «Закрепить до» (`date`, хранение `Y-m-d`) рядом с галочкой в карте мет
  и в Carbon Fields — вкладка «Дополнительно», контейнер «Событие: место и
  продвижение», только для `manage_options`.
- `idpg-core.php`: подключение класса, постановка и снятие задачи в
  activate/deactivate. `uninstall.php`: очистка хука.
- REST: `promoted` теперь отдаёт **фактическое** состояние через
  `IDPG_Promotion::is_active()`, а не сырую метку. Добавлено `promoted_until`
  (пустая строка = бессрочно). Раньше событие с прошедшей датой успевало
  отрапортовать `promoted: true` до срабатывания крона.
- Регистрация в `class-migrations.php` не потребовалась: `get_key_map()`
  переименовывает старые ключи на новые, а `idpg_pin_expires` — новое поле,
  старого ключа у него нет.

**Смысл `is_active()`.** Выдачи должны спрашивать именно его, а не `_idpg_promoted`:
между запусками крона событие с прошедшей датой формально ещё висит. Помощник
готов для будущей логики топа.

**Тесты.** `tests/test-promotion.php` — 17 тестов / 39 утверждений: состояние
(нет закрепления / бессрочное / будущее / прошедшее / сегодня включительно),
cron (снимает истёкшее, идемпотентен на трёх прогонах, не трогает действующие,
все статусы, чужие типы записей, хук один раз), расписание (не задваивается,
исполнитель подключён через `do_action`), регистрация поля.

**Обратная проба.** Тест поймал настоящий баг: синглтон создавался только в
`idpg_core_activate()`, а подписка на `idpg_cron_unpin_expired` живёт в его
конструкторе. На обычной загрузке задача срабатывала бы **вхолостую**: cron
есть, исполнителя нет. Исправлено — `IDPG_Promotion::instance()` добавлен в
список инициализации (строки 137–148). Проба повторена: с убранной строкой
тест падает.

**Проверка.** Живой сайт: задача запланирована один раз, следующий запуск
2026-09-28 21:19, исполнитель подключён. Поле создаётся как
`Carbon_Fields\Field\Date_Field` с именем `_idpg_pin_expires`. `/`, `/login/`,
`/privacy/`, `/organizer/` — 200, PHP-ошибок нет. Наборы: `idpg-core`
**350 тестов / 870 утверждений OK** (было 333/831), `idpg-auth` 5/9 OK.

**Открыто по смежным пунктам.** Продвижение в выдаче не работает (см. G10).

#### DEV-88. Отзыв согласия на обработку ПДн (152-ФЗ)

**Проблема.** Текст на `/privacy/` обещал субъекту «Вы можете запросить выгрузку
или удаление своих данных, **а также отозвать согласие на обработку**»
(`class-privacy.php`, `render_subject_tools()`), но механизма отзыва не было
вообще: ни бэкенда, ни формы. Незакрытое обещание на публичной странице —
это риск, а не косметика, и по 152-ФЗ отзыв должен быть возможен.

**Сделано.**

- `META_WITHDRAWAL = '_idpg_pdn_consent_withdrawn'` — запись об отзыве.
  Отдельная метка, а не затирание `META_CONSENT`: факт «согласие было дано и
  позже отозвано» сам по себе является персональными данными, которые нужно
  хранить (ст. 5 152-ФЗ). Без него нельзя доказать, как субъект распоряжался
  своим согласием.
- `withdraw_consent()`, `is_withdrawn()`, `get_withdrawal()`; хук
  `idpg_privacy_consent_withdrawn` (передаёт ID и запись).
- `has_consent()` теперь возвращает `false` для отозванного согласия. Чтение
  этой функции должно означать «можно обрабатывать данные прямо сейчас»,
  поэтому одной проверки факта дачи мало.
- `record_consent()` снимает прежний отзыв при повторном согласии — иначе
  `has_consent()` продолжал бы возвращать `false`.
- `handle_subject_withdraw()` на `template_redirect`: авторизация, nonce,
  серверная проверка подтверждения (required без JS не проверяется), редирект
  с `?idpg_withdraw=ok|confirm`. По образцу уже работающего
  `handle_subject_request()`.
- Блок интерфейса `render_withdraw_block()`: гостям не выводится, после отзыва
  форма исчезает и показывается дата отзыва, пользователю без записи о согласии
  — сообщение, что отзывать нечего. Отзыв и запрос на удаление разведены по
  разным блокам: необратимый шаг не должен выглядеть частью того же действия.
- `assets/css/privacy.css`: `.idpg-privacy-tools__block`,
  `__subtitle` — на тех же токенах `--idpg-*`, что и остальной файл.

**Что НЕ сделано осознанно.** Отзыв не разлогинивает, не блокирует вход и не
снимает роль организатора. Закон требует прекратить обработку, но требует ли
блокировать доступ — продуктово-юридическое решение, а не техническое. Записал
в тексте блока, что учётная запись сохраняется. Если нужно ограничить
функциональность — отдельная задача.

**Тесты.** `tests/test-privacy-withdrawal.php` — 14 тестов / 36 утверждений:
бэкенд (запись, семантика `has_consent`, сохранность записи о согласии,
повторный отзыв без дублей, снятие отзыва новым согласием, хук, невалидный
ID) и интерфейс (форма авторизованному, скрыта гостю, исчезает после отзыва,
скрыта без согласия, `required` у чекбокса, два блока разведены).

**Обратные пробы.** Убрана блокировка в `has_consent()` — 2 падения. Отзыв
стал затирать запись о согласии — 1 падение. Обе гипотезы тестами ловятся.

**Найденная ошибка в своей же правке.** Первая версия проверяла наличие отзыва
как `(string) get_user_meta(...)`, а метка хранит массив: PHP выдавал
«Array to string conversion». Исправлено на `is_array()`.

**Проверка.** Живая база: до отзыва `has_consent=да`, форма выводится; после —
`has_consent=нет`, `is_withdrawn=да`, запись о согласии на месте
(`{"at":"2026-09-28 21:28:16","version":"2026-09-15","ip_hash":"…"}`), форма
скрыта. Проверено на временном пользователе, реальный организатор ID 109 не
тронут. `/`, `/privacy/`, `/login/` — 200, PHP-ошибок нет. Наборы:
`idpg-core` **364 теста / 906 утверждений OK** (было 350/870),
`idpg-auth` 5/9 OK.

#### DEV-89. Техдолг по стилям: кабинет на токенах, карантин `_variables`, рабочий `lint:scss`

Три хвоста, накопленных по ходу DEV-85 и DEV-87. Реализовано без правок
разметки и логики — только CSS и инструменты сборки.

**1. Кабинет организатора жил на чужой палитре.** `pages/_organizer.scss`
оказался единственным местом в теме с хардкод-цветами: 17 уникальных
значений старой палитры Tailwind-дефолтов — `#2563eb`, `#0f172a`, `#e2e8f0`,
`#f8fafc` и прочие. Дизайн-система проекта — другая: `--idpg-primary`
(`#0052ff`), `--idpg-ink` (`#0a0b0d`), `--idpg-hairline` (`#dee1e6`). Кабинет
выглядел иначе, чем остальной сайт. Переведено 17 цветов на `var(--idpg-*)`
(50 вхождений, жёстких цветов не осталось), фокус-кольцо — литерал
`rgba(0, 82, 255, 0.15)`, как в эталонной `pages/_auth.scss`. Шрифты и
медиазапросы оставлены как были: токенов для типографики в системе нет, а
медиазапросы в токены не выражены.

Заодно обновлён устаревший комментарий в шапке партиала: он описывал
состояние до DEV-87 и упоминал «71 правило, которого нет в SCSS».

**Проверка изменения изолированно.** Сравнил `style.css` до и после через
разбор правил: изменилось **ровно 30 правил, все — `.idpg-org*` и
`.idpg-notice*`**, ни одного вне кабинета; потерь 0, лишнего 0 (578 → 578).

**2. `base/_variables.scss` — карантин, не удаление.** Файл не используется
ни одним партиалом (проверено поиском по `$var` во всём дереве). Проблема не
в мёртвом коде, а в ловушке: токены заданы через переменную, поэтому
`$color-primary: #2563eb` в этом файле **не влияет ни на одно правило**, а
партиалы используют `var(--idpg-primary)` (`#0052ff`). Любой, кто подключит
переменные, получит старые цвета без единого предупреждения. Файл сохранён,
но помечен шапкой-предупреждением с указанием, как правильно. Удалять не стал:
решение владельца.

**3. `npm run lint:scss` не работал.** Две причины: не было `.stylelintrc.json`
и скрипт передавал glob в одинарных кавычках, из-за чего stylelint получал
путь литералом («No files matching the pattern "'assets/...'»). Кавычки убраны
(glob раскрывает сам stylelint), добавлен конфиг.

Правила в конфиге подобраны под реальный код, а не задним числом навязаны:

- `unit-no-unknown` со списком современных viewport-единиц: stylelint 16 не
  знает `dvh`/`svh`, а в теме они используются в заголовке, навигации и
  логине;
- `length-zero-no-unit` убран: `0px` в `env(safe-area-inset-bottom, 0px)` и в
  шкале радиусов (`--idpg-radius-none: 0px`) намеренны, а правило не умеет
  исключать фолбэки функций;
- `no-duplicate-selectors` оставлен только для `_typography.scss` и
  `_reset.scss`, где повтор селектора действительно означает мусор;
- `indentation` не задан: перенесённые партиалы используют 2 пробела, а не
  табы. Форматировать 25 файлов под новый стандарт — отдельная задача, и она
  не имеет отношения к починке скрипта.

Проверено с обеих сторон: на чистом коде код выхода 0, после подстановки пустого
правила — 2.

**Проверка.** `npm run compile:css` — без дрейфа, `npm run lint:scss` — 0.
Живой `style.css?ver=1.0.80`: 103 токена, `.idpg-org-field__input`,
`.idpg-notice--success` и `.idpg-org-nav__link.is-active` используют
`var(--idpg-*)`. `/`, `/login/`, `/privacy/`, `/terms/`, `/organizer/` — 200,
PHP-ошибок нет. Наборы: `idpg-core` **364 теста / 906 утверждений OK**,
`idpg-auth` 5/9 OK.

**Открыто.** Ничего.

**Проверено 29.09.2026: расхождения отступов, записанные выше, не было.** Утверждение
про «2 пробела против табов» не подтверждается кодом: табов нет ни в одном из
26 файлов `assets/scss` (ноль символов `\t` во всём дереве) и нет в собранном
`style.css` — везде пробельные отступы по 2 пробела, смешения нет. 31 строка с
отступом 3 или 5 пробелов — это центрированные баннеры многострочных
комментариев (`/* ====... */`), где такое смещение сделано намеренно, чтобы
равные повёрнуты по центру; трогать их не нужно. Форматировать 26 файлов под
тайлы смысла нет: расхождения не существует, а `indentation` в stylelint по
этому остаётся незаданным.

#### DEV-90. Вход организатора: редирект в кабинет и воспроизводимый стенд тестов — ЗАКРЫТО (формально DEV-147)

Два хвоста, найденных при разборе жалобы «Неверный email или пароль». Ни
аутентификация, ни пароль, ни лимит попыток не менялись — правились адрес
после успешного входа и путь к тестовому WordPress.

**1. С JS и без JS организатор попадал в разные места.** Форма входа
(`page-login.php`) при отключённом JS отдаёт
`idpg_organizer_after_login_url()` и уводит `event_organizer` в кабинет. При
включённом JS тот же вход уходит в REST `/idpg/v1/auth/login`, а
`get_redirect_url_by_role()` различал только `administrator`/`editor` (им
`admin_url()`), а всем остальным, включая организатора, отдавал
`home_url( '/' )`. Итог: с JS пользователь оказывался на главной, без JS — в
кабинете. Добавлена ветка организатора через
`IDPG_Organizer_URL::after_login_url()`: метод сам возвращает кабинет
организатору и пустую строку всем остальным, так что обычные пользователи
по-прежнему уходят на главную, а администратор и редактор обрабатываются выше
по функции и не затронуты. Пользователь берётся из `wp_set_current_user()`,
который оба места вызова выполняют заранее.

**2. Тесты падали из-за отсутствующего стенда.** `bootstrap.php` обоих
плагинов читали `WP_TESTS_DIR`, а при его отсутствии брали `tests/phpunit`
внутри `%TEMP%`, который очищается. Собран постоянный стенд в
`%LOCALAPPDATA%\idpg-tests\wordpress-develop` (WP trunk `7.2-alpha-63166-src`),
база `wp_idpg_tests`; бутстрапы ищут его первым. Наборы запускаются без
переменных окружения.

**Проверка.** Настоящий HTTP-вход в `/wp-json/idpg/v1/auth/login`: `success:
true`, `user_id: 109`, `role: event_organizer`, `redirect_url` — `/organizer/`
(до правки был `/`). `php -l` чист. Наборы: `idpg-core` **364 теста / 906
утверждений OK**, `idpg-auth` 5/9 OK — совпадает с базовой линией, регрессий
нет. Transient'ов неудачных попыток входа в базе не осталось, блокировки не
будет.

**Открыто.** ~~`bin\install-wp-tests.cmd` и `bin\run-tests.cmd` не запускаются
на русской Windows~~ — **закрыто в DEV-92** (причина оказалась в LF вместо
CRLF, а не в кодировке; плюс исправлены инвертированное условие установки и
передача пароля БД).

#### DEV-91. Восстановление пароля: сбой отправки не выдаётся за успех, частота ограничена — ЗАКРЫТО (формально DEV-147)

**1. Молчаливое «отправлено».** `page-login.php` вызывал
`retrieve_password()` и безусловно показывал «Если
email существует, инструкции отправлены». При неработающем SMTP человек
получал бодрое сообщение и ждал письмо, которого не будет.

Разбор ядра (WP 7.1.2, `wp-includes/user.php`) показал, какие коды вообще
возможны: `empty_username`, `invalid_email`, `invalidcombo` — такого адреса в
системе нет; `retrieve_password_email_failure` — ядро добавляет его, когда
`wp_mail()` вернул false; плюс всё, что добавит фильтр `lostpassword_errors`
(`throttled` и подобное).

Логика разделена на два исхода:

- «тихие» коды (`empty_username`, `invalid_email`, `invalidcombo`) — тот же
  нейтральный ответ, что и при успехе. Иначе форма стала бы способом выяснить,
  какие email зарегистрированы; по этой же причине нейтрально отвечает REST
  (`/idpg/v1/auth/forgot-password`);
- любой другой код — сообщение «Не удалось отправить письмо. Попробуйте
  позже.». Сам код пользователю не показывается.

**2. No-JS путь не был ограничен по частоте.** REST вызывает
`IDPG_Auth_Rate_Limiter::check_password_reset_attempt()` (3 запроса в час на
адрес), а `page-login.php` — нет: письмо-сброс можно было слать на чужой адрес
сколько угодно, и ядро отправляло его каждый раз. В форму добавлена та же
проверка, счётчик общий с REST. Сообщение о лимите показывается для любого
ввода, включая несуществующий адрес, — иначе по его наличию можно было бы
выяснить, зарегистрирован ли адрес. Лимитер обёрнут в `class_exists`, как
остальные проверки файла: без плагина страница входа остаётся рабочей.

**Проверка.** `php -l` чист. Живая форма (настоящий HTTP, с nonce):

- несуществующий email → нейтральное «Если email существует, инструкции
  отправлены», сообщения об ошибке нет;
- существующий email + принудительный код через временный mu-plugin на
  `lostpassword_errors` → «Не удалось отправить письмо», ложного «отправлено»
  нет. Письмо при этом не уходит: ядро выходит до `wp_mail()`, так что ящик
  пользователя не затрагивается;
- несуществующий email + тот же фильтр → ответ остался нейтральным, потому что
  ядро добавляет `invalid_email` первым и `get_error_code()` возвращает именно
  его. Утечки нет.

- лимит: 4 запроса на один адрес — первые три дают нейтральный ответ, четвёртый
  «Слишком много запросов сброса пароля» (в том числе для несуществующего
  адреса — утечки нет); на другой адрес лимит не распространяется, счётчик
  ведётся на адрес.

Временный mu-plugin удалён, `mu-plugins` пуст, тестовые transient'ы счётчика
удалены, счётчик реального аккаунта не тронут. Наборы: `idpg-core` 364/906 OK,
`idpg-auth` 5/9 OK.

#### DEV-92. `bin\*.cmd` перестали запускаться: LF вместо CRLF и инвертированное условие

**1. Причина была не в кодировке, а в переводах строк.** Оба скрипта были
сохранены с LF вместо CRLF (117 и 45 одиночных LF, CRLF — ноль), при этом
UTF-8 был валиден и BOM отсутствовал. `cmd.exe` читает батник блоками и при LF
теряет границы строк, из-за чего хвост строки становится отдельной командой.
Ровно это и наблюдалось: `'TH' is not recognized` (хвост
`WP_TESTS_PHPUNIT_POLYFILLS_PATH`), `'yoast'`, `'HPUNIT'` (хвост `%PHPUNIT%`),
`'о'`, `'те:'`, и развалившиеся `if` (`"=="" set "DB_NAME"`, `.Replace(
'youremptytestdbnamehere' was unexpected at this time`). Внешний `chcp 65001`
не помогал именно поэтому, а не из-за кодировки. Файлы нормализованы в CRLF,
BOM не добавлялся.

**2. Условие установки было инвертировано.** Строка
`if not exist "%WP_CORE_DIR%\wp-includes\version.php" goto :have_core`
противоречит собственному комментарию «если ещё нет»: при отсутствии файла
скрипт перепрыгивал загрузку и затем падал с «Не найден WP core», то есть
установить окружение с нуля было невозможно; при наличии файла он скачивал
~60 МБ заново при каждом запуске. Заменено на `if exist ... goto :have_core`.

**3. Русские сообщения и опечатка.** В начало обоих скриптов добавлен переход
консоли в UTF-8 с запоминанием прежней кодовой страницы и её возвратом в
конце (`chcp %_OLDCP%`), чтобы сообщения не превращались в mojibake на русской
Windows. Опечатка `WordPres` в сообщении об ошибке исправлена на `WordPress`.

**Проверка.** Байтовый контроль обоих файлов: CRLF 122/50, одиночных LF 0,
BOM нет, файл завершается CRLF. `bin\run-tests.cmd` — код 0, **364 теста /
906 утверждений OK**. `bin\install-wp-tests.cmd` при готовом окружении — код 0,
загрузка не выполняется. Тот же скрипт с нуля (отдельный каталог через
`IDPG_WP_DEVELOP`) — код 0, распакованы `src` и `tests/phpunit`, создан
`wp-tests-config.php` с верной БД, временные zip и каталог распаковки удалены
скриптом; тестовый каталог убран. Кодовая страница до и после запуска — 866,
утечки нет.

**4. БД не создавалась никогда.** Проверка показала, что `mysql` в этой системе
**не в PATH**, а полный путь Laragon существует и работает. Значит старая схема
не просто «не передавала пароль»: первый вызов падал (errorlevel 9009), в
`if errorlevel 1` путь только **присваивался** и `mysql` повторно **не
вызывался** — то есть `CREATE DATABASE` не выполнялся ни разу. Стенд работал
только потому, что БД была заведена вручную; на чистой машине скрипт отрапортовал
бы «готово», и падение всплыло бы позже в тестах.

Исправлено: аргумент с паролем добавляется только когда пароль реально задан
(`-p""` заставил бы `mysql` жать Enter), клиент ищется в
`D:\laragon\bin\mysql\mysql-*` с проверкой `bin\mysql.exe`, обе попытки
выполняются, а неудача теперь печатает `[WARN]` вместо молчания. Перебор
вынесен под метки, а не внутрь `if ( ... )`: внутри блока `%MYSQL_BIN%`
раскрылся бы значением, взятым до цикла. Зашитая версия 8.0.30 заменена на
перебор — сервер на этой машине 8.4.3, и в Laragon стоят обе.

**Проверка БД.** `root` без пароля — чисто, без WARN. Неверный пароль и
несуществующий пользователь — WARN, то есть пароль действительно передаётся
(раньше подставлялась несуществующая `%DB_PASS_MYSQL%` и пароль просто
игнорировался). Новое имя БД создаётся. Отладочные базы `wp_idpg_probe`,
`wp_idpg_fresh`, `wp_idpg_tests_probe` удалены, осталась только `wp_idpg_tests`.
`install-wp-tests.cmd` — код 0 без WARN и ERROR, загрузки нет; `run-tests.cmd` —
код 0, **364 теста / 906 утверждений OK**; `idpg-auth` — **5/9 OK**; оба
файла по-прежнему CRLF без BOM; временные файлы убраны.

**Открыто.** Ничего.

---

#### DEV-93. Сброс пароля лимитировался только по email: перебор адресов с одной машины — ЗАКРЫТО (= DEV-116, формально DEV-145)

**1. Что было не так.** `check_password_reset_attempt()` (строка 202) считает
запросы по ключу `idpg_reset_attempts_<md5(email)>` — то есть ограничивает
**один** адрес. Логин и регистрация при этом лимитируются по IP
(`check_login_attempt()`, `check_registration_attempt()`), так что в модуле уже
есть готовый, аккуратно написанный резолвер адреса: `get_client_ip()` доверяет
`X-Forwarded-For` / `X-Real-IP` / `Client-IP` только когда `REMOTE_ADDR` указывает
на прокси, иначе берётся только `REMOTE_ADDR`.

Дыра в том, что с одного IP можно было запросить сброс для произвольного числа
**разных** адресов — счётчик на каждый из них начинался с нуля, и общий объём
ничем не ограничивался. Обратный сценарий (один адрес с множества IP) закрыт
существующим лимитом по email, поэтому не требовал изменений.

**2. Реализация.** Добавлены две константы `RESET_IP_LIMIT = 10` и
`RESET_IP_WINDOW = 3600` (втрое выше лимита по email — 10 разных адресов в час с
одной машины это ещё не похоже на перебор) и новый метод
`check_password_reset_ip_attempt( $ip )`. Существующие
`check_password_reset_attempt()`, `check_login_attempt()`,
`check_registration_attempt()`, `get_client_ip()` и все их сигнатуры не тронуты;
метод ничего не знает о существовании email и не меняет его счётчик, поэтому
вызывается независимо.

Вызов добавлен в обе точки, где живёт сброс пароля, иначе лимит обходился бы
отключением JavaScript:

- `class-rest-auth.php`, `handle_forgot_password()` — перед проверкой по email;
- `page-login.php` (PHP-fallback формы) — с сохранённой защитой
  `class_exists()`, при которой страница входа остаётся рабочей при
  выключенном плагине.

**3. Неопределимый адрес не лимитируется.** Первый вариант guard-а проверял
только `filter_var( $ip, FILTER_VALIDATE_IP )`, и это оказалось недостаточно:
`0.0.0.0` и `::` проходят `FILTER_VALIDATE_IP` как валидные адреса. Поскольку
именно таким значением `get_client_ip()` помечает неудачу определения адреса,
все клиенты за одним прокси делили бы общий счётчик и блокировали друг друга.
Отсечка неопределённых адресов вынесена явным списком `array( '0.0.0.0', '::' )`.
Это нашлось обратной пробой, а не чтением кода.

**Проверка.** `tests/test-auth-rate-limiter.php` — 7 тестов / 36 утверждений:
прохождение в пределах лимита, отказ сверх лимита с кодом `429` и
`too_many_resets_from_ip`, независимость счётчиков разных IP, пропуск
`0.0.0.0`, пропуск мусора вместо адреса, перебор разных email с одного IP и
работа прежнего лимита по email (код `too_many_resets` не изменился).

**Обратная проба.** Убрана отсечка `0.0.0.0` — падает
`test_reset_ip_skips_unresolved_address`. Гипотеза тестом ловится.

**Прогон.** `idpg-core` — **371 тест / 942 утверждения OK** (было 364/906, то
есть ровно 7 новых, регрессий нет). `idpg-auth` — без изменений.
Живой `POST /wp-json/idpg/v1/auth/forgot-password` трижды на несуществующий
адрес: ответы идентичны и нейтральны, анти-энумерация не сломана. В БД после
проверки видны оба независимых счётчика (`idpg_reset_attempts_*` и
`idpg_reset_ip_attempts_*`), у `127.0.0.1` накоплено 3 попытки. Тестовые
счётчики удалены, следов не осталось. `/`, `/login/`, `/privacy/`, `/terms/` —
`200`, `/organizer/` — `302` (редирект на логин, ожидаемо для гостя), PHP-ошибок
в выводе нет, `debug.log` не менялся.

**Открыто.** Ничего.

---

#### DEV-94. G5/G6: форма события и живые разделы кабинета организатора — ЗАКРЫТО (формально DEV-147)

**1. Что было не так.** REST организатора (`IDPG_REST_Organizer`, DEV-42) полностью
готов: `GET/POST /organizer/events`, `GET/PUT/DELETE /organizer/events/{id}`,
`POST /organizer/events/{id}/submit`, `GET /organizer/stats`. Но UI отсутствовал:
не было страниц `/organizer/event/new/` и `/organizer/event/{id}/`, шаблона формы,
скрипта сохранения, а разделы «Дашборд» и «Мои события» были заглушками
(`section-dashboard.php` / `section-events.php` не запрашивали данные).

**2. Что сделано.**

*Маршруты (G5).* `IDPG_Organizer_URL`: константы `EVENT_QVAR`, `EVENT_NEW`,
`EVENT_TEMPLATE`, `REWRITE_VERSION` поднят до `2`; правила
`^organizer/event/new/?$` и `^organizer/event/([0-9]+)/?$`; qvar;
`is_event_form_request()`, `get_current_event_id()`, `event_form_url()`.
Правила формы объявлены **первыми**: `WP_Rewrite::add_rule()` делает обычное
присваивание в `extra_rules_top`, поэтому порядок массива = порядок регистрации,
и при будущем разделе со слагом `event` форма выиграет.

*Шаблон (G5).* `page-organizer-event.php`: 3 шага (Основное → Даты и место →
Билеты и зал), прогресс-бар, сеансы (повторяющиеся строки → JSON), все поля
`format_event()`, блокировка при `pending`/`publish`, 403 для чужих событий.

*Скрипт (G5).* `organizer-event-form.js`: POST/PUT/submit/delete через REST с
`X-WP-Nonce`, переключение шагов, валидация через `invalid` (capture), сеансы,
редирект после создания. Подключается только на странице формы.

*Разделы (G6).* `section-dashboard.php` — 4 карточки из `/organizer/stats`;
`section-events.php` — фильтр по статусам (5 вкладок со счётчиками), таблица
(постер, название, статус, просмотры), пагинация. Данные через `organizer.js`
(`initStats` / `initEvents`), состояние в URL (`?status=&p=`).

*Тема.* `idpg_organizer_event_form_url()` в `inc/organizer-menu.php` — единая
точка для адреса формы (используется шаблоном и обоими разделами).

**3. Проверка.** `idpg-core` — **382 теста / 972 утверждения OK** (было 371/942,
+11 тестов). Новые: `test-organizer-page.php` (+7: qvar, rewrite, порядок правил,
URL, query_var, template, версия), `test-organizer-menu.php` (+4: URL формы).
JS-дымок `tests/js-smoke/organizer-js-smoke.js` — 26 проверок (порядок объявлений,
шаги, сеансы, фильтры, пагинация, кэш stats).

Живой прогон: `/organizer/event/new/`, `/organizer/`, `/organizer/events/` —
`302 → /login/` (гость, ожидаемо). Rewrite-правила сработали автоматически
(маркер версии). PHP-ошибок нет.

**Обратная проба.** Убрана проверка порядка правил — падает
`test_event_rewrite_rules_registered` («Правила формы должны проверяться раньше
правил разделов»). Убран кэш промиса в `loadStats` — падает проверка «всего два
запроса» (stats запрашивался дважды).

**Ограничения.** Постер и галерея не редактируются в форме: у роли
`event_organizer` нет `upload_files`, а отдельного media-эндпоинта нет.
Постеры в списке событий подтягиваются через `/wp/v2/media` (с деградацией до
заглушки, если доступ закрыт). Импорт событий не реализован — тот же дефицит
прав.

**Сопутствующий баг (найден при проверке карточки, 29.09.2026).**
`idpg_core_currency_symbol()` не имела дефолта: пустое значение валюты
возвращало пустую строку, и цена печаталась как «от 500 до 1 500 » — с висящим
пробелом вместо символа. Причина системная: `_idpg_currency` необязательное
поле, а дефолт `RUB` из Carbon-поля попадает в мету **только** при сохранении
через админку. Событие, созданное через REST или форму организатора, остаётся
без кода валюты. Тот же дефолт уже был в разметке Schema.org
(`idpg_core_event_jsonld()`), из-за чего карточка и JSON-LD расходились:
микроразметка отдавала `"priceCurrency":"RUB"`, а видимая цена — без символа.
Исправлено в `inc/template-tags.php`; тесты в `tests/test-theme.php` (6 новых).

**Открыто.** Ничего. Вопрос «можно ли организатору выбирать валюту» решён
владельцем и закрыт **DEV-95**: организатор работает только в рублях,
`_idpg_currency` остаётся в модели Carbon для администратора.

---

#### DEV-95. Валюта организатора: только рубли — ЗАКРЫТО решением владельца 29.09.2026 (формально DEV-147)

**Статус: закрыто решением владельца 29.09.2026.**

**1. Контекст.** В `carbon-fields-export.json` поле `_idpg_currency` — select
`RUB|USD|EUR` в контейнере «Билеты и цены». Поиск по форме организатора и по
REST показал: поля валюты у организатора не было ни там, ни там. То есть вопрос
был не «убрать поле», а «зафиксировать валюту организатора явно», иначе значение
зависело от дефолта отрисовки (см. сопутствующий баг в DEV-94).

**2. Решение владельца.** Организатор работает **только в рублях**. Портал
российский, оплата ведётся внешними ссылками, конвертировать нечего. Поле
`_idpg_currency` остаётся в модели Carbon **для администратора** — USD и EUR
у админа работают.

**3. Что сделано.** Аддитивно, `class-meta.php` не тронут.

- `class-rest-organizer.php`, `save_meta()`: после сохранения полей `_idpg_currency`
  принудительно ставится в `RUB`, **но только если поле пустое**. Не
  перезаписывать заданное админом — сознательное решение: организатор может
  отредактировать описание события с USD (например, после импорта) и не должен
  при этом молча перевести цены в рули.
- `currency` **не добавлялся** в схему аргументов REST: параметра нет в
  контракте, поэтому переданный клиентом `currency` игнорируется WordPress'ом
  сам. Отвергать запрос с 400 не стали: это смена контракта без требования
  задачи, а приёмка прямо допускает «игнорируется/400».
- Форма `page-organizer-event.php` — поле валюты **не выводится** (и не было).
  `organizer-event-form.js` не отправляет валюту (и не отправлял).

**4. Проверка.** Новый `tests/test-organizer-currency.php` — **8 тестов /
24 утверждения**:

- новое событие организатора сохраняется в `RUB`;
- `currency=USD` при создании игнорируется (201, в мета `RUB`);
- `currency=EUR` при правке игнорируется (200, в мета `RUB`);
- валюта, выставленная админом, не затирается правкой организатора;
- `currency` отсутствует в схеме аргументов **всех** обработчиков маршрута
  `/idpg/v1/organizer/events` (список и создание) — с санитарной проверкой,
  что цикл не прошёл вхолостую;
- REST не отдаёт валюту в представлении события;
- в исходнике формы нет ни `name="currency"`, ни `idpg-ev-currency`,
  ни `_idpg_currency`; в скрипте формы нет ни одного упоминания `currency`.

Прогон `idpg-core`: **396 тестов / 1014 утверждений OK**.

**Обратная проба.** Убран блок установки `RUB` из `save_meta()` — падают 3 теста
(`test_new_organizer_event_saves_rub`, `test_create_ignores_currency_param`,
`test_update_ignores_currency_param`). Возвращён — проходят.

**Открыто.** Ничего.

---

#### DEV-48. Синхронизация сеансов Carbon Fields ↔ зеркало

> **Проверено 30.09.2026 (DEV-148). ЗАКРЫТО — реализация и тесты на месте.**
> `class-rest-organizer.php`: `const SESSIONS_FIELD = 'idpg_sessions'` ~строка 108,
> запись в complex-поле `carbon_set_post_meta()` ~строка 1515, пересборка зеркала
> `IDPG_Meta::mirror_sessions()` ~строка 1518, нормализация
> `normalize_session_rows()` ~строка 1547. Тест `tests/test-sessions-sync.php` —
> **12 тестов**, как указано в карточке.
> **Уточнение по числам:** `mirror_sessions()` находится в `class-meta.php`
> ~строка 861 (карточка писала 802) — обычный сдвиг строк, не расхождение по сути.

**Статус: закрыто 29.09.2026.**

**1. Что было не так.** Сеансы — complex-поле Carbon Fields `idpg_sessions`
(подполя `time`, `price`, `url`). CF хранит его не в одном ключе, а в наборе
служебных мета-строк (`_idpg_sessions|time|0|0|value`, `_idpg_sessions|price|0|0|value`
и т.д.), тогда как шаблоны читают простое зеркало `_idpg_sessions_json`.
`save_meta()` писал **только зеркало** и в CF ничего не отправлял, поэтому:

- администратор в wp-admin **не видел** сеансов, заведённых организатором
  (`carbon_get_post_meta()` отдавал пусто, ключ `_idpg_sessions` содержал `''`);
- обратное направление, наоборот, работало: `IDPG_Meta::mirror_sessions()`
  (`class-meta.php:802`, хук `carbon_fields_post_meta_container_saved` на
  строке 1127) зеркалит CF → JSON.

То есть баг был **односторонним**, ровно как DEV-42 для галереи
`idpg_gallery`: там вылечило использование `carbon_set_post_meta()`.

**2. Что сделано.** Аддитивно, `class-meta.php` не тронут.

- `class-rest-organizer.php`: константа `SESSIONS_FIELD = 'idpg_sessions'`;
  в `save_meta()` сеансы пишутся в complex-поле через
  `carbon_set_post_meta()`, а зеркало **пересобирает существующий**
  `IDPG_Meta::mirror_sessions()`. Так обе стороны читаются из одного источника
  и не могут разойтись в правилах нормализации; заодно снимается кэш
  карточки, иначе она показала бы старые сеансы.
- Ветка без Carbon Fields (плагин CF выключен) пишет зеркало напрямую из
  нормализованных строк — редактор в этом состоянии всё равно недоступен.
- `normalize_session_rows()` повторяет правила `mirror_sessions()`: строка без
  времени не является сеансом, значения обрезаются, ссылка проходит через
  `esc_url_raw()`.

**3. Изменён существующий тест.** `test_create_accepts_valid_sessions_json`
утверждал, что зеркало равно **исходной строке запроса**, и передавал ключи
`from`/`to`, которых нет в модели (в complex-поле только time/price/url).
Такое ожидание фиксировало именно баг. Тест переписан на реальную форму
сеанса и проверяет нормализованный результат; смысл («валидный JSON
принимается и сохраняется») сохранён, добавлена проверка видимости в CF.

**4. Проверка.** Новый `tests/test-sessions-sync.php` — **12 тестов /
34 утверждения**:

- организатор → редактор: сеансы из REST видны через `carbon_get_post_meta()`;
- зеркало совпадает с complex-полем (посимвольно не сравнивается — сравнивается
  разобранное значение, чтобы не зависеть от экранирования слэшей в JSON);
- правка через PUT тоже доходит до редактора;
- редактор → организатор: сеансы, заданные через
  `carbon_set_post_meta()` + хук, приходят в `GET /organizer/events/{id}`;
- полный круг в обе стороны;
- пустой список, пустая строка и отсутствие параметра не оставляют зеркало
  (`metadata_exists()` = false) и не протаскивают заглушку `|||` / `_empty`
  в API;
- очистка сеансов через REST опустошает complex-поле;
- битый JSON → 400 `idpg_org_invalid_sessions`, в CF ничего не пишется;
- строка без `time` отбрасывается; небезопасная ссылка (`javascript:`)
  отбрасывается;
- мусор в служебных мета-ключах CF не роняет `mirror_sessions()`.

Прогон `idpg-core`: **408 тестов / 1049 утверждений OK**.

**Обратная проба.** Убрана запись в complex-поле — падают 7 тестов из 12
(остальные проверяют второе направление и нормализацию). Возвращена — проходят.

**Живая проверка.** Событие создано через REST от имени организатора с двумя
сеансами: зеркало корректно, `carbon_get_post_meta()` отдаёт оба сеанса.
Затем сеанс изменён в стиле редактора (`carbon_set_post_meta` +
`mirror_sessions`) — `GET /organizer/events/{id}` вернул новое время и цену.
Тестовое событие удалено.

**Открыто.** Ничего. Поле `session_url` добавлено в форму в DEV-97.

---

#### DEV-97. Форма события G5 — под полную схему Carbon Fields — ЗАКРЫТО 29.09.2026 (формально DEV-147)

**Статус: закрыто 29.09.2026.** Источник истины — `docs/carbon-fields-export.json`.

**1. Сверка.** В схеме 5 контейнеров, из них **4** относятся к CPT `event`
(«Основная информация», «Событие: место и продвижение», «Билеты и цены»,
«Медиа») — 24 поля. Пятый, «Данные площадки», зарегистрирован
`->where( 'post_type', '=', 'venue' )` и в форму события не входит (площадка
живёт в своей сущности). В форме не хватало **14 полей**: статус билетов,
VIP-цена, форма Яндекс.Афиш, адрес, `is_online`, `stream_url`,
`stream_embed`, `video_trailer`, `banner_request`, постер, галерея, URL
сеанса и кнопка создания площадки.

**2. Решения владельца** (заданы в ходе работы):

- **`upload_files` выдан организатору** (`ROLE_VERSION` 7 → 8). Без него
  постер и галерея были нерабочими: медиатека на фронте ограничена
  собственными файлами организатора (`IDPG_Organizer_Scope`), а загрузить их
  было нечем. Прато прямой публикации события модерация не тронута —
  `publish_events` по-прежнему снят, это закрыто тестом.
- **Реализован маршрут `/organizer/venues` (DEV-98)**: список, создание,
  правка; `can_access_venue()` по владению записью; поля — строго из
  контейнера «Данные площадки». В форме события — модальное окно, потому
  что вложенная форма невозможна. Отдельный раздел и форма площадки
  описаны в DEV-98.

**3. Отклонения от буквы ТЗ — осознанные, по источнику истины:**

- `ticket_type = widget_custom` **не добавлялся**: в экспорте Carbon ровно два
  значения, `external_link` и `widget_yandex`. Форма и REST используют эти два.
- `_idpg_hide_from_banner` и `_idpg_hide_full_banner` **не выведены**: в коде
  они лежат в `build_extra_tab_fields()` за `current_user_can( 'manage_options' )`
  с комментарием «организаторы не видят», и в экспорте их нет.
- `_idpg_venue` **не сделан обязательным**: список обязательных в ТЗ
  (title, start_date, city) его не содержит, а требование события обязательной
  площадки противоречило бы черновикам.
- `_idpg_currency` не выводится (DEV-95), `_idpg_external_id` — служебное,
  `_idpg_yandex_form_button` — поле типа `html`, не значение.

**4. Найдено и исправлено: код виджета и вставка трансляции терялись.**

`sanitize_textarea_field()` внутри `_sanitize_text_fields()` вызывает
`wp_strip_all_tags()`, поэтому HTML-вставки сохранялись **пустыми**. Свой
тест это поймал: `widget_code` и `stream_embed` приходили пустыми.

- `stream_embed` → санитайзер по общему списку тегов
  `IDPG_REST_Organizer::stream_embed_allowed_html()`. Тот же список теперь
  используется и при выводе в `single-event.php`: раньше списки были
  продублированы и разошлись бы при правке любого из них. `wp_kses_post()`
  не годился — он вырезает `<iframe>`, то есть ровно то, ради чего поле нужно.
- `widget_code` → `wp_kses_post()`. Обоснование: код виджета выводится на
  публичной странице **без экранирования**
  (`IDPG_Template_Helper::get_ticket_button()`, комментарий «доверенный HTML от
  администратора»), а писать его теперь может организатор. Хранить «как
  пришло» было бы путём к stored XSS.
  **Компромисс:** настоящий embed Яндекс.Афиш начинается с `<script>` и будет
  вырезан. Это цена доступа организатора к полю.

**5. Прочие находки:**

- **Enum-декларации не проверялись.** `rest_validate_request_arg()` в WP 7.1
  принимает три аргумента и читает схему из атрибутов запроса, а
  `has_valid_params()` вызывает её **только** при явно заданном
  `validate_callback`. Без него `'enum'` был декорацией: `ticket_type=hack`
  проходил с 201. `validate_callback` добавлен новым enum-полям
  (`ticket_status`, `banner_request`, `is_online`, `venue_type`); для
  существовавших `all_day`, `is_free`, `ticket_type` не трогал, чтобы не менять
  контракт старого маршрута.
- `sessions_json` теперь отправляется **всегда**, пустым массивом в том
  числе: раньше пустой список не уходил, и очистить заведённые сеансы через
  форму было невозможно.
- Ценовые поля и время сеанса переведены на `type=number` / `type=time`.

**6. Проверка.** Новый `tests/test-organizer-event-form-schema.php` —
**17 тестов / 160 утверждений**. Список полей не зашит: тест читает JSON и
сверяет с разметкой, REST и обработчиками, поэтому добавление поля в схему
заметит расхождение.

- каждое поле схемы присутствует в форме; исключённые и admin-only — нет;
- значения `select` совпадают с экспортом; ключи схемы начинаются с `_idpg_`,
  `base_name` не расходится с именем;
- REST принимает каждое поле схемы; новые enum реально валидируются;
- событие из формы сохраняет все поля и возвращает их в представлении;
- сеансы и галерея ложатся в complex/media-хранилище Carbon, плоские ключи
  без подчёркивания не пишутся;
- обязательные поля помечены `required`;
- `upload_files` есть, `publish_events` по-прежнему нет;
- DEV-98: площадка создаётся с ключами Carbon, недопустимый тип отклоняется,
  пустое название отклоняется, чужую площадку не правят, гость не входит.

Прогон `idpg-core`: **425 тестов / 1210 утверждений OK**. JS-дымок 26/26.

**Обратная проба.** Возвращён `sanitize_textarea_field` для `stream_embed` —
падают `test_event_from_form_keeps_every_field` и
`test_event_payload_round_trip`. Возвращён правильный санитайзер — проходят.

**Живая проверка.** Форма отрендерена от имени организатора: 168 КБ, `wp.media`
подключён, PHP-уведомлений в выводе нет. Событие создано через REST с полным
набором полей: все сохранены и вернулись в `GET /organizer/events/{id}`,
`stream_embed` сохранился с `<iframe>` целиком, `_idpg_currency` осталась
`RUB` (DEV-95 не сломан). Тестовое событие удалено.

**Открыто.** Ничего.

---

#### DEV-98. Площадки организатора: раздел кабинета и отдельная форма — ЗАКРЫТО (формально DEV-147)

**Проблема.** Площадка — самостоятельная сущность (CPT `venue`), а не поле
события. В DEV-97 появился только REST-маршрут `/organizer/venues` и модальное
окно внутри формы события. Отдельного раздела кабинета и отдельной формы у
площадки не было: создать её можно было только «внутри» создания события, а
площадку, у которой ещё нет ни одного события, создать было негде. Правки
площадки как самостоятельной сущности тоже не было.

**Решение владельца (29.09.2026).** Нумерация: работа с площадками переехала
с занятого DEV-50 (он остаётся за соц-входом) на DEV-98; ссылки в DEV-94/97
исправлены.

**Что сделано.**

- *Маршруты.* `IDPG_Organizer_URL`: раздел `venues`, `VENUE_QVAR`,
  `VENUE_NEW`, `VENUE_TEMPLATE = 'page-organizer-venue.php'`, адреса
  `/organizer/venue/new/` и `/organizer/venue/{id}/`, хелперы
  `is_venue_form_request()`, `get_current_venue_id()`, `venue_form_url()`.
  `REWRITE_VERSION` 2 → 3, потому что правила площадки добавлены после
  предыдущего сброса.
- *Раздел.* `template-parts/organizer/section-venues.php` — каркас таблицы,
  наполняется из `GET /organizer/venues` в `organizer.js` (`initVenues`).
  Пункт меню «Площадки» с `order = 25` — между «Событиями» и «Профилем».
- *Форма.* `page-organizer-venue.php` + `template-parts/organizer/venue-form.php`
  + `assets/js/organizer-venue-form.js`. Поля — строго контейнер «Данные
  площадки» (`venue_type`, `venue_address`, `venue_phone`, `venue_email`,
  `venue_website`, `venue_capacity`, `venue_lat`, `venue_lng`), плюс
  нативные `title`, `content`, `city` и постер через `wp.media`
  (`wp_enqueue_media()` только при `upload_files`).
- *Без JavaScript.* Форма — обычный POST самой страницы: nonce
  `idpg_organizer_venue`, проверка прав и владения, Post/Redirect/Get.
  Обработчик дергает тот же REST через внутренний `rest_do_request`, чтобы
  правила валидации и записи мета не расходились с JS-путём.
- *REST.* CRUD площадки с `can_access_venue()` по владению записью.
  Обязательные `title`, `city`, `venue_address` проверяются общим
  `validate_venue_input()` и при создании, и при правке. `venue_type`
  валидируется по перечислению. Постер принимается только если это
  настоящее вложение.
- *Удаление.* `DELETE` площадки, на которую ссылается событие, отвечает
  `409 idpg_org_venue_in_use`: удаление обнулило бы `_idpg_venue` у события,
  и оно молча потеряло бы место проведения.

**Решение владельца (29.09.2026) по редактору описания.**

- **Описание площадки осталось `<textarea>`, не `wp_editor`.** В ТЗ был
  указан `wp_editor`, но в теме он не используется нигде: форма события
  (G5/DEV-97) тоже на `textarea`. Ставить TinyMCE только в одной из двух
  форм означало бы разное поведение и лишний вес на странице. Отклонение
  осознанное; при необходимости редактор добавляется сразу в обе формы.

**Отклонения и находки.**

- **Поле `content` не отображается в списке:** в таблице три колонки
  (название, тип, адрес), описание — на странице площадки.
- **Чужие площадки видны в списке, но без ссылки на правку.** `GET
  /organizer/venues` возвращает и свои, и чужие опубликованные площадки с
  флагом `is_own`. Клик по форме правки чужой площадки упёрся бы в 403,
  поэтому для `is_own: false` выводится `<span>` с пометкой «общая».
- **Возраст роли не менялся:** `ROLE_VERSION` остался 8, `upload_files` у
  организатора уже есть с DEV-97, `publish_events` по-прежнему снят.

**Найдено и исправлено: список площадок падал при отфильтрованном меню.**

`organizer.js` объявлял `var VENUE_TYPE_LABELS` ниже раннего `return`
(`if (!links.length || !panels.length) return;`). Объявления функций `var`
поднимает наверх, а значение `var` — нет, поэтому при пустом меню разделов
(его может отфильтровать плагин) подписи типов были `undefined` и список не
отрисовывался. Сценарий воспроизводится в дымке намеренно: в блоке раздела
«Площадки» ссылок и панелей меню нет. Присваивание перенесено выше раннего
`return`; сам `return` не тронут.

**Найдено и исправлено: форма площадки была нерабоча без JavaScript.**

`get_template_part()` подключает файл внутри `load_template()`, то есть в
области видимости этой функции: локальные переменные вызывающего кода оттуда
не видны. Партиал брал данные площадки и право записи у страницы, поэтому
выводился с пустыми значениями и `disabled` на всех полях — в браузере без
JavaScript заполнить форму было невозможно. Права при этом проверялись верно,
просто сама форма была закрыта.

Данные передаются глобальными переменными, объявленными **до** присваиваний:
объявление после присваивания создало бы новую глобалку со значением `null` и
потеряло бы заполненную форму.

Третий аргумент `get_template_part( $slug, $name, $args )` не помогает: в этой
версии WordPress `$args` уходят только в хуки `get_template_part_*` и
`wp_before_load_template`, в партиале переменными не становятся. Проверено по
`wp-includes/template.php:848`.

По той же причине выбор города считался функцией
`idpg_organizer_venue_picked()` из файла страницы — недостижимой из партиала,
из-за чего `function_exists()` возвращал `false` и город молча сбрасывался на
«— Не выбрано —». Логика перенесена в партиал; функция из страницы убрана, так
как больше нигде не использовалась.

**Найдено и исправлено: кнопка удаления у новой площадки была мёртвой.**

Обработчик навешивался один раз при загрузке скрипта на кнопку из разметки.
У новой площадки её в разметке нет — скрипт создаёт кнопку только после
первого сохранения, когда становится известен ID. Кнопка появлялась рабочей на
вид, но ничего не делала. Обработчик вынесен в `bindDelete()` и навешивается
на оба случая.

**Оформление.**

Контролы и кнопки формы площадки намеренно оформлены классами формы события
(`create-event__*`): это уже готовая по токенам облицовка сайта, и своё
описание полей ввода развело бы каскад и дало бы две расходящиеся формы.
Собственный партиал `assets/scss/pages/_venue-form.scss` (подключён сразу
после `pages/_create-event`) закрывает только то, чего у формы события нет:
пометку «общая» и оформление названия в списке, подсказку о недоступной
загрузке файлов, строку и опасную кнопку удаления. Всё на токенах
`var(--idpg-*)`.

- Классы `create-event__actions` и `create-event__upload-hint` использовались в
  разметке, но **стилей для них не существовало ни в одном партиале** — строка
  удаления и подсказка выводились без оформления.
- Кнопка удаления была `create-event__btn`, то есть выглядела как основное
  синее действие. Теперь это разрушающая операция, и она опасного вида.
- В кнопке отправки не было спиннера: `create-event__btn--loading` без разметки
  `.create-event__spinner` просто гасил кнопку без обратной связи. Добавлены
  `.create-event__submit-text` и `.create-event__spinner`.
- У чужой площадки в списке класс `dashboard__table-title` красил `<span>` при
  наведении и выдавал его за ссылку, а пометка «общая» вешалась на класс
  `.dashboard__table-note`, которого нет ни в одном партиале. Заменено на
  `venue-form__*`.
- Раздел «Площадки» получил три взаимоисключающих состояния: загрузка,
  таблица, пусто/ошибка. Раньше при пустом ответе оставалась шапка таблицы с
  нулём строк — это читается как сломанная страница.
- Постер в списке кабинета не выводится намеренно: это управляющий список, а
  подтягивание картинок стоило бы лишнего запроса к медиатеке при каждом входе
  в раздел. Картинка показывается на странице площадки.

**Тесты.** `tests/test-organizer-venue-form.php` — 33 теста / 160
утверждений: обязательные поля при создании и правке, скоуп (чужое — 403,
несуществующее — 404, без nonce — 403), совместимость ключей с
`docs/carbon-fields-export.json`, постер, enum типа, `409` при удалении
занятой площадки, маршруты и qvar, состав формы (все поля схемы есть, лишних
нет, `required`, типы полей, no-JS fallback), наличие раздела и меню.

Отдельно пять тестов на область видимости партиала: рендер идёт через
`load_template()`, а не прямым include — прямой include выполнялся бы в области
теста и регресс с полями `disabled` не увидел бы. Проверяются доступность
полей, подстановка всех значений, выбор типа и города, наличие кнопки удаления
у существующей площадки и безопасность подключения без глобалов.

Прогон `idpg-core`: **458 тестов / 1371 утверждение OK**. JS-дымок 75/75
(список площадок, пустой список, ошибка загрузки, правка, создание, удаление
новой площадки).

Попутно обновлён `tests/test-organizer-menu.php`: он фиксировал список
разделов из трёх пунктов, DEV-98 добавляет четвёртый.

**Обратная проба.**

- Возвращено присваивание `VENUE_TYPE_LABELS` вниз — дымка валится на
  `в таблицу добавлена строка на каждую площадку` с причиной
  `Cannot read properties of undefined (reading 'theater')`.
- Убран `global` из партиала формы — падают три теста блока области видимости
  (поля `disabled`, пустые значения, нет кнопки удаления).
- Возвращён `bindDelete()` к навешиванию один раз при загрузке — дымка валится
  на `на созданной кнопке есть обработчик`.

**Живая проверка.** REST вживую: `POST` → 201, `GET` → 200, `PUT` → 200,
`DELETE` занятой площадки → 409, свободной → 200; пустые `title` / `city` /
`venue_address` → 400 с кодами `idpg_org_venue_title_required`,
`idpg_org_venue_city_required`, `idpg_org_venue_address_required`.

Формы отрендерены от имени организатора без PHP-уведомлений: создание —
155 979 байт, 0 полей `disabled`, кнопки удаления нет, `wp.media` и скрипт
подключены; правка — 156 203 байта, 0 полей `disabled`, значения и оба
`<select>` подставлены, строка удаления на месте. Путь без JavaScript проверен
сквозным обычным POST: площадка создана со всеми полями (`address`, `type`,
`capacity`, `phone`, `city`). Временные записи удалены.

**Открыто.** Ничего.

---

#### DEV-99. Перечисления события: форма не сохранялась, а мусорные значения проходили — ЗАКРЫТО (формально DEV-147)

**Найдено при разборе DEV-98.** Задача, которую владелец свёл к «добить
пункт 1» — добавить `validate_callback` трём полям без проверки. По дороге
выяснилось, что у части маршрута поломан сам сценарий сохранения.

**1. Событие нельзя было сохранить из формы.**

`ticket_status` проверялся (`validate_callback` добавлен в DEV-97), но
перечисление `array( 'in_sale', 'sold_out', 'cancelled', 'postponed' )` не
содержало пустой строки. А форма её шлёт: «— Не выбран —» — первая опция
`select`, она же состояние новой формы, и `organizer-event-form.js`
отправляет значение `select` как есть. Итог: обычное сохранение события
отвергалось с `400 rest_invalid_param`, пока организатор явно не выберет
статус билетов.

Проверено на живом REST до правки: `ticket_status=''` → 400, после → 201.

**2. Муссор в базу проходил с 201.** У `all_day`, `is_free`, `ticket_type`
объявлено `enum`, но не было `validate_callback`, а ядро вызывает
`rest_validate_request_arg()` только для аргументов, у которых он задан
явно. `is_free=hack` и `ticket_type=hack` возвращали 201 и ложились в базу
дословно. В том же классе оказались `orderby` и `order` в списке событий.

**Правило, по которому выставлялись перечисления.** Набор значений должен
повторять ровно то, что форма способна отправить:

- `all_day`, `is_free` — радио с двумя позициями, скрипт подставляет `'no'`,
  когда ничего не отмечено → строго `{yes, no}`, пустой строки нет;
- `ticket_type`, `ticket_status` — `select` с явной пустой опцией →
  пустая строка входит в перечисление.

**Сделано.** `validate_callback` добавлен всем пяти недостающим полям, в
`ticket_status` и `ticket_type` внесена пустая строка. Теперь перечислений в
аргументах маршрута события девять, и у всех девяти есть `validate_callback`.

**Побочная находка, из-за которой чуть не остался ложный тест.** Параметры
GET нельзя передавать через `set_body_params()`: `get_parameter_order()`
(`wp-includes/rest-api/class-wp-rest-request.php`) добавляет источник `POST`
только для POST/PUT/PATCH/DELETE, поэтому переданное в тело для GET молча
игнорировалось, применялся дефолт, и проверка всегда получала 200. Нужны
`set_query_params()`.

**Тесты.** `tests/test-organizer-event-form-schema.php` — 21 тест / 212
утверждений. Добавлены:

- `test_new_enums_are_validated_server_side` — переписан с трёх полей на все
  девять плюс `orderby`/`order` аргументов чтения;
- `test_empty_string_allowed_where_form_sends_it` — пустая строка обязательна
  у двух `select` и недопустима у двух радио;
- `test_enum_rejects_unknown_value_on_write` — `hack` отвергается, 400;
- `test_enum_accepts_form_values` — все значения, которые шлёт форма,
  принимаются и пишутся в мета (ключ `_idpg_*`, а не `_*`);
- `test_list_sort_enum_rejects_unknown_value` — сортировка через
  `set_query_params()`.

Прогон `idpg-core`: **462 теста / 1423 утверждения OK**. JS-дымок 75/75.

**Обратная проба.** Убрана пустая строка из `ticket_status` и
`validate_callback` у одного поля: 3 падения и 1 ошибка в тестах схемы
(включая `Undefined array key "validate_callback"`). Файл восстановлен.

**Живая проверка.** Событие создано полным набором формы при пустых
`ticket_status` и `ticket_type` → 201, мета записаны (`ticket_status=''`,
`ticket_type=''`, `all_day='no'`, `is_free='yes'`). Список событий 200,
сортировка `views`/`asc` 200, мусорные значения 400. Временное событие
удалено.

**Открыто.** Ничего.

---

#### DEV-100. Наблюдение: функции на уровне шаблона — не задача

> **Проверено 30.09.2026 (DEV-148). Подтверждено наблюдение, работа не начата
> (и не должна начинаться без решения владельца).**
> `page-organizer-event.php` ~строка 177 — `function idpg_event_terms( $taxonomy )`
> без `function_exists`. `page-organizer-venue.php` — четыре функции уровня файла
> (~строки 200, 227, 244, 260), тоже без защиты; единственный `function_exists`
> там ~строка 245 — это вызов внутри `idpg_organizer_venues_url()`, а не защита
> объявления.
> **Добавлено к наблюдению:** партиал `template-parts/organizer/venue-form.php`
> (~строки 97 и 343) вызывает `idpg_organizer_venues_url()` под `function_exists` —
> то есть зависимость «страница объявляет функцию, партиал её использует, при
> отсутствии страницы откат на `home_url('/organizer/venues/')`» уже заложена.
> Это ровно тот класс риска, который карточка описывает.
> Конвенция подтверждена как общепроектная: функции уровня файла объявляют
> **11 из 47** PHP-файлов темы. Не трогаем.

**Наблюдение, не задача.** `page-organizer-event.php` (форма события, G5) и
`page-organizer-venue.php` объявляют функции PHP на уровне файла без защиты
`function_exists()`. При однократной загрузке шаблона в запросе это
безопасно, но любой код, включающий такой файл дважды (сторонний плагин,
собственный тест), получит фатальную ошибку `Cannot redeclare`. Форма события
при этом разметку держит в самой странице, а не в партиале, поэтому
проблема сегодня не проявляется.

Не трогал: это общая конвенция проекта, а не следствие DEV-98. Если решить —
правильное место `wp-includes`-совместимая обёртка или перенос логики в
классы, а не точечные `if ( ! function_exists() )` в шаблонах.

---

#### DEV-102. Восстановление git после сбоя (закрыто 29.09.2026)

**Статус: закрыто 29.09.2026.** Коммиты `c12921c` (код), `399477f` (документация).
Карточка заведена постфактум (DEV-133, 30.09.2026): до этого задача жила только
в `docs/PRODUCTION.md` §12 и журнале `.workbuddy-ai/memory/2026-09-29.md`.

**Что было.** Утром 29.09 индекс git оказался **пустым — 0 записей** при валидном
заголовке `DIRC`; дополнительно `.gitignore` в рабочем дереве был **подменён**
(49 строк вместо 152). Следствия:

- `git ls-files` возвращал **0 файлов**, `git status` — 250 «удалённых», которых
  на диске нет;
- новые файлы не попадали в индекс, часть своих каталогов молча игнорировалась;
- вся работа организатора за 29.09 существовала **только на диске**.

**Что сделано.**

- Бэкап всего `.git` (операция необратима) + бэкап БД (`mysqldump`) и файлов.
- `.gitignore` возвращён из HEAD → 152 строки, правила DEV-53/DEV-60 на месте.
- `git reset HEAD` + `git add -A` → индекс снова описывает **250 файлов**.
- `node_modules` (42 055 файлов) и черновик `t2.scss` исключены локально — через
  `.git/info/exclude` (не `.gitignore`), на диске сохранены.
- **`class-event-moderation.php` восстановлен из HEAD:** вернулся
  `schedule_price_moderation()` и ещё **5 методов**; файл вырос с 16 до 222 строк
  (сейчас 603 — дополнялся позже).
- **`idpg-core.php`: 33 → 39 `require_once`** (проверено в коде — 39). Пять классов
  (`organizer-url`, `rest-organizer`, `event-rejection`, `promotion`, `privacy`)
  были подключены, но нигде не вызывали `::instance()` — то есть не работали;
  инициализация добавлена в `idpg_core_init()` (подробнее — DEV-100).
- **DEV-93:** `RESET_IP_LIMIT`, `RESET_IP_WINDOW`,
  `check_password_reset_ip_attempt()`; вызов в `handle_forgot_password()` **до**
  расхода лимита по email.
- REST `GET/PUT /organizer/profile` + `can_profile()`; `class-meta.php` — поле
  срока закрепления `_idpg_pin_expires`.
- `idpg_core_create_organizer_page()`, `idpg_core_get_organizer_page_id()`,
  `idpg_core_exclude_organizer_page_from_sitemap()` + хук создателя страницы.
- Тема: `require_once inc/organizer-menu.php`; enqueue `organizer.css`,
  `organizer.js`, `organizer-event-form.js`, `organizer-venue-form.js`.

**Дефекты, найденные по ходу восстановления.** `/auth/register` отдавал **403 вместо
400** (`type` стал необязательным, а проверка осталась строгой) — закрыт обход
создания организатора через `type=ORGANIZER`. `terms` получил
`rest_sanitize_boolean` (строка `"false"` больше не проходит как `true`).
`idpg_pdn_consent` обязателен. `validate_callback => 'is_email'` заменён замыканием
на обоих маршрутах. `clean_user_cache()` перед чтением профиля. `auth.js` — отдельный
чекбокс согласия на ПДн.

**Тесты.** 465 тестов / 1426 утверждений, 0 ошибок, 0 падений (**было 275 ошибок
и 81 падение**). Удалён `test-organizer-pending.php` — он покрывал анкету
`/organizer/complete/`, снятую DEV-72.

**Регрессии, внесённые этим восстановлением (найдены DEV-130, закрыты DEV-131).**

1. **Хук создателя страницы `/organizer/`** был восстановлен как
   `after_switch_theme` вместо задокументированного DEV-39 `after_setup_theme`.
   `after_switch_theme` срабатывает раз за жизнь темы → у переключённой темы страницы
   не было совсем, `idpg_core_get_organizer_page_id()` отдавал 0, удалённая страница
   не восстанавливалась. Починено: `themes/idpg_core/functions.php` —
   `after_setup_theme` (хук `idpg_core_create_organizer_page`, ~строка 464).
2. **Исключение из sitemap Yoast** висело на `wpseo_exclude_from_sitemap_by_posts_ids`
   (множественное `posts`), тогда как Yoast вызывает
   `wpseo_exclude_from_sitemap_by_post_ids` (**единственное** `post`) — фильтр был
   мёртвым кодом, страница оставалась в карте сайта. Починено:
   `functions.php` — правильное имя (фильтр ~строка 522).

Обе правки — по одной строке, покрыты `test-organizer-page-creation.php`
(12 тестов / 23 утверждения). Разбор — §13 ниже.

**Замечание по нумерации (DEV-133).** Постановка DEV-133 указывала «ROLE_VERSION 8»
и «6 require» — это **неточность**: `ROLE_VERSION` в рамках DEV-102 менялся
**6 → 7** (сейчас в коде `class-roles.php:44` — `7`; версия **7 → 8** относится к
DEV-97, выдача `upload_files` организатору), а `require_once` — **33 → 39**.
В карточке зафиксированы проверенные значения.

**Не сделано в тот момент:** remote настроен, но **push не выполнен** — нет учётных
данных, `gh` не установлен. Пушить вручную владельцем.

**Ссылки.** Журнал — `.workbuddy-ai/memory/2026-09-29.md` (раздел «DEV-102»);
порядок восстановления — `docs/PRODUCTION.md` §12 «Восстановление git»;
грабли — `MEMORY.md («Грабли»)`, раздел «Git».

#### DEV-134. Восстановление битого HEAD в git (закрыто 30.09.2026)

**Статус: закрыто 30.09.2026.** Коммит `765500c` — воссозданный DEV-87 (этап 5).
Бэкап повреждённого `.git` — `D:\laragon\backups\idupogorodu\git-damaged-2026-09-30`.

**Что было.** `git status` и `git stash` падали с `fatal: bad object HEAD`.
`.git/refs/heads/master` указывал на `2f5c3762`, но самого объекта в хранилище
**не было** — каталог `.git/objects/2f/` оказался **пустым**. Правки в рабочем
дереве при этом не пострадали, индекс (308 записей) был цел, все pack-файлы
проходили `verify-pack`.

**Полная картина (важно: дело было не в одном HEAD).** `git fsck --full` показал:

- **5 отсутствующих blob'ов** — ровно те SCSS-файлы, что трогал DEV-87
  (`style.scss`, `pages/_auth.scss`, `components/_footer.scss`,
  `components/_home-main.scss`, `base/_typography.scss`);
- битые указатели `refs/heads/master` (`2f5c376`), `refs/remotes/origin/master`
  и `HEAD`;
- битые reflog-записи (ссылались на утраченный `2f5c376`);
- испорченный cache-tree индекса (`4778195e…`).

**Что уцелело.** Единственный безвозвратно утраченный объект — коммит `2f5c376`
(DEV-87, этап 5); он **не был запушен**, поэтому на удалёнке его нет. Уцелели
`2069e37` (`origin/master`, DEV-95), `c12921c` (DEV-102), `fdf4ca0` (DEV-60)
и вся история до них. Все 5 blob'ов побайтово лежали в рабочем дереве —
их вернул `git hash-object -w`.

**Что сделано (без `reset --hard`, рабочее дерево не тронуто):**

1. Бэкап повреждённого `.git` целиком + отдельно `.git/index`.
2. `git hash-object -w` — восстановлены 5 утраченных blob'ов из рабочего дерева.
3. `git update-ref refs/heads/master 2069e37` — ref переставлен на уцелевший
   проверяемый коммит (`reset --hard` не использовался).
4. Восстановлен `refs/remotes/origin/master` (файлом — `update-ref` его «прятал»).
5. Переписаны битые reflog-записи `HEAD` и `refs/heads/master`.
6. `git read-tree HEAD` — сброшен битый cache-tree; затем `git add -A` вернул
   рабочее состояние индекса (14 файлов DEV-87, `t2.scss` исключён).
7. Пересоздан утраченный коммит из рабочего дерева — `765500c`
   (14 файлов, +1252 / −457). Автор — `IDPG Dev <dev@idupogorodu.ru>`,
   `user.name`/`user.email` в конфиге отсутствовали, заданы локально.

**Приёмка.**

| Проверка | Результат |
|---|---|
| `git fsck --full` | **чисто** — 0 ошибок, 0 отсутствующих объектов |
| `git status` | работает; ветка чистая (`t2.scss` не tracked) |
| `git log --oneline -10` | история на месте: `765500c` → `2069e37` → DEV-102 → DEV-60 → DEV-59 |
| `git push --dry-run origin master` | `2069e37..765500c` — fast-forward, без `--force` |
| рабочее дерево | **не изменено** (`style.css` — 79 743 байта, MD5 `f8ed3986…` — замер на 30.09 до DEV-138; после DEV-138 MD5 `62eb661b…` при том же размере) |

**Технический долг / замечания.**

- `git fetch` и `git ls-remote` к GitHub периодически зависают или отдают
  `expected flush after ref listing` — это **сеть**, не репозиторий;
  `push --dry-run` при этом проходит.
- Push коммитов `765500c` и `1f6fe9a` **выполнен** в DEV-135 (30.09.2026):
  `origin/master` = `1f6fe9a`, локальная ветка синхронна.
- В репо временно появился локальный `user.name`/`user.email` в `.git/config`
  (раньше конфиг был пуст) — это исправление, а не регрессия.

**Ссылки.** Порядок восстановления — `docs/PRODUCTION.md` §12.1 «Восстановление
битого HEAD»; рецепт — `MEMORY.md («Рецепты»)` «Починка битого HEAD без потери рабочего
дерева»; грабли — `MEMORY.md («Грабли»)`, раздел «Git»; журнал —
`.workbuddy-ai/memory/2026-09-30.md`.

#### DEV-136. CircleCI и ветка dev/phpcs-baseline (30.09.2026)

> **Проверено 30.09.2026 (DEV-148). Код и документация подтверждены; остаток —
> вне кода, на владельца.**
> `.circleci/config.yml` содержит job'ы `phpunit` (`cimg/php:8.4` + `cimg/mysql:8.4`)
> и `phpcs` (три прогона с baseline) — как записано в карточке.
> Ветки `dev/phpcs-baseline` нет ни локально (единственная ветка — `master`),
> ни на remote: `git ls-remote --heads origin` отдаёт только `main` (`fdf4ca0`)
> и `master` (`98efd2d`) — удаление подтверждено. `docs/PRODUCTION.md` §13
> «CI на CircleCI» и `docs/PROJECT.md (раздел 1)` §1.9 на месте.
> Пункты 1–2 приёмки (привязка проекта в CircleCI, доступ к приватному репозиторию)
> по-прежнему непроверяемы из агента — репозиторий приватный, `gh` не установлен.

**Статус: код и документация — сделано; привязка CI — на владельце.**

**Ветка `dev/phpcs-baseline` удалена.** Была избыточна: `a381795` целиком вошла
в `master` через merge (DEV-59). Проверено перед удалением:
`git merge-base --is-ancestor a381795 master` → exit 0, а
`git log master..a381795` пуст. Удаление без `--force`:
`git push origin --delete dev/phpcs-baseline` → `- [deleted] dev/phpcs-baseline`.
Локальной ветки не было. На удалёнке остались `master` (`1f6fe9a`) и `main` (`fdf4ca0`).

**CI: конфиг есть, проект не привязан.** `.circleci/config.yml` описывает job'ы
`phpunit` (`cimg/php:8.4` + `cimg/mysql:8.4`) и `phpcs` (три прогона по компонентам
с baseline). Job'ы на push **не запускаются** — проект не привязан в CircleCI.

**Что нужно от владельца (вне кода):**

1. CircleCI → **Add Projects** → `github.com/Franck-afk/idpg-source` → **Follow**;
2. подтвердить доступ CircleCI к приватному репо в GitHub;
3. сделать тестовый push и убедиться, что job'ы `phpunit` и `phpcs` стартуют.

**Ограничение проверки.** Репозиторий приватный: неавторизованный GitHub API
отдаёт 404, `gh` не установлен — статус job'ов виден только в веб-интерфейсе
CircleCI после привязки. Поэтому пункты 1–2 приёмки на стороне агента
не проверяемы; выполнены пункты про ветку и карточку DEV-134.

**Обновлено.** Карточка DEV-134 — устаревшая строка «push не выполнялся»
заменена на факт из DEV-135; `docs/PROJECT.md (раздел 1)` §1.9 — статус CI и удаление
ветки; `docs/PRODUCTION.md` §13 — новый раздел про CI и удаление ветки.

**Ссылки.** `docs/PRODUCTION.md` §13; `docs/PROJECT.md (раздел 1)` §1.9; журнал —
`.workbuddy-ai/memory/2026-09-30.md`.

#### DEV-138. Синхронизация версий темы (закрыто 30.09.2026)

**Статус: закрыто 30.09.2026.** Версия темы — **1.0.78** в обоих местах.

**Проблема.** После сбоя git (DEV-102) версии темы разошлись и откатились:
`functions.php` (тогда ~строка 11) → `IDPG_VERSION = '1.0.75'`, `style.css` → `Version: 1.0.73`,
строки `1.0.77` в теме не было вовсе — хотя DEV-84 заявлял подъём до 1.0.77.
Та же природа, что у регрессий DEV-39 (найдены DEV-130) и `ROLE_VERSION` (DEV-133).
**Следствие:** кэш-бастинг ассетов не работал — версия не росла, браузеры отдавали
`style.css` и скрипты из кэша, правки не доезжали до пользователя.

**Решение владельца.** Вариант **A** — 1.0.78 (следующая после заявленной 1.0.77).
Варианты B (синхронизация с плагином 1.1.4) и C (1.0.77) отклонены.

**Что сделано.**

1. `themes/idpg_core/functions.php:20` → `IDPG_VERSION = '1.0.78'`; комментарий
   заменён на поясняющий (почему версия и что она означает), без «changelog»-строки.
2. `themes/idpg_core/style.css` → `Version: 1.0.78`.
3. Проверено: других определений `IDPG_VERSION` нет (grep по `themes/`, `plugins/`,
   `mu-plugins/`, `wp-config.php` — единственное объявление в `functions.php`).
4. Живая проверка `https://idupogorodu.test`: `style.css?ver=1.0.78`,
   `navigation.js?ver=1.0.78`, `live-search.js?ver=1.0.78`, `auth.js?ver=1.0.78`.
5. Новый сторож `plugins/idpg-core/tests/test-theme-version.php` — **7 тестов /
   8 утверждений**: файлы на месте, заголовок непустой, константа объявлена,
   объявлена **ровно один раз**, значения совпадают, формат версии, рантайм-значение
   совпадает с заголовком. Обратная проба: при возврате `1.0.75` падают ровно
   2 теста (`test_versions_match`, `test_runtime_constant_matches_header`).

**Приёмка.**

| Проверка | Результат |
|---|---|
| `IDPG_VERSION` = `style.css Version` | `1.0.78` = `1.0.78` |
| `?ver=` ассетов (живой HTTP) | `1.0.78` у style.css и скриптов темы |
| `test-theme-version.php` | 7 / 7 OK (обратная проба ловит расхождение) |
| Полный набор `idpg-core` | 523 теста / 1570 утверждений, падений **2** — оба гард сборки CSS (DEV-87), к версии не относятся |

**Остаток.** DEV-23 был закрыт частично: версии темы синхронизированы, но в заголовке
`plugins/idpg-core/idpg-core.php` не было `Tested up to`. **Закрыто DEV-139** в тот же
день — DEV-23 закрыт полностью.

**Ссылки.** DEV-23 (расхождения версий), DEV-84 (заявленный бамп 1.0.77),
`docs/PROJECT.md (раздел 1)` §1.9; журнал — `.workbuddy-ai/memory/2026-09-30.md`.

#### DEV-139. `Tested up to` в заголовке idpg-core (закрыто 30.09.2026)

**Статус: закрыто 30.09.2026.** Остаток DEV-23.

**Проблема.** В заголовке `plugins/idpg-core/idpg-core.php` не было `Tested up to`
(у `idpg-auth` и у темы он есть) — карточку DEV-23 нельзя было закрыть.

**Расхождение в постановке — разрешено решением владельца.** Постановка просила
`Requires PHP: 8.2` и `Requires at least: 6.4`, но одновременно «сверить с idpg-auth
для единообразия», а там `7.4` и `5.8`. Проверка по коду: **PHP 8-фич нет** —
`str_contains` / `str_starts_with` / `?->` / `enum` / `readonly` / promoted ctor
не используются (совпадения только в `vendor/`), PHPCS `testVersion` = `5.6-`.
Владелец выбрал **фактические значения** (PHP 7.4 / WP 6.0): код совместим с 7.4,
подъём порога до 8.2 заблокировал бы установку на PHP 7.4–8.1 без причины и разошёлся
бы с `idpg-auth` и темой.

**Что сделано.**

- В заголовок добавлена одна строка: `Tested up to: 6.7` (как у `idpg-auth` и темы).
  `Requires at least: 6.0` и `Requires PHP: 7.4` оставлены без изменений.
- Проверено рантаймом через `get_plugin_data()` на бутстрапе WP 7.1.2:
  `RequiresWP` = `6.0`, `RequiresPHP` = `7.4`, `Tested up to` = `6.7`.

**Грабля, вскрытая при проверке.** `get_plugin_data()` **не разбирает**
`Tested up to` вовсе, а его ключи — `RequiresWP` (`Requires at least`) и
`RequiresPHP` (`Requires PHP`), не «Requires at least»/«Requires PHP» как строки
(см. `wp-admin/includes/plugin.php:76-92`). Первая проба из-за этого показала
«(нет)» у **обоих** плагинов при верных заголовках. `Tested up to` читается
отдельно — `get_file_data( $file, array( 'Tested' => 'Tested up to' ), 'plugin' )`.
Тест учитывает это.

**Тест.** `plugins/idpg-core/tests/test-plugin-header.php` — **7 тестов /
13 утверждений**: файлы на месте, заголовок разбирается, `Requires at least` /
`Requires PHP` / `Tested up to` присутствуют и по формату похожи на версии,
`Tested up to` не ниже `Requires at least`, значения совпадают с `idpg-auth`.
Обратная проба: удаление строки `Tested up to` роняет ровно 3 теста.

**Ссылки.** DEV-23 (закрыт полностью), DEV-138 (версии темы); журнал —
`.workbuddy-ai/memory/2026-09-30.md`.

#### DEV-141. Удаление остатков анкеты `/organizer/complete/` (закрыто 30.09.2026)

**Статус: закрыто 30.09.2026.** Найдено при разборе ЛК организатора (анализ
по запросу владельца).

**Проблема.** DEV-72 снял анкету продуктово (данные организации переехали
в `/organizer/profile/`), но удалил **только REST-маршрут**
`/idpg/v1/auth/organizer-complete`. На месте и **подключено** осталось:

- `includes/class-organizer-page.php` — `require_once` в `idpg-core.php:116`,
  `IDPG_Organizer_Page::init()` в `:155`;
- шаблон `page-organizer-complete.php` (6,7 КБ);
- скрипт `assets/js/organizer-complete.js` (3,4 КБ) — **нигде не enqueue-ился**;
- rewrite-правило `^organizer/complete/?$` — живое, URL отвечал 302 на `/login/`;
- чтение флага `_idpg_org_pending` в двух местах — при том что **никто его
  не записывал**.

Итог: анкета была полностью мертва. Флаг всегда пуст → `is_pending()` всегда
`false` → форма не показывалась (выводилась ветка «Анкета уже заполнена»);
а если бы показалась, `POST` ушёл бы в несуществующий маршрут → 404.

**Три документа противоречили друг другу:** `docs/PROJECT.md (раздел 1)` §6.1 — «удалено
вместе с классом, шаблоном и скриптом»; `docs/archive/TZ_IMPLEMENTED.md` §9 п.1 — «не
работает»; `docs/PROJECT.md (раздел 1)` §6.4 G7 — «`_idpg_org_pending` удалён».

**Что сделано.**

1. Удалены файлы: `includes/class-organizer-page.php`,
   `page-organizer-complete.php`, `assets/js/organizer-complete.js`.
2. Из `idpg-core.php` убраны `require_once` и `IDPG_Organizer_Page::init()`.
3. Rewrite-правило ушло вместе с классом, который его регистрировал. Чтобы
   правило исчезло и из **сохранённой** опции `rewrite_rules`,
   `IDPG_Organizer_URL::REWRITE_VERSION` поднят **3 → 4** — штатный механизм
   `maybe_flush()` сбрасывает правила однократно на следующем запросе.
   Проверено: в правилах осталось 6 записей про organizer, `organizer/complete`
   среди них нет.
4. Удалена осиротевшая опция `idpg_organizer_complete_rewrite_flushed`.
5. Чтение `_idpg_org_pending` вычищено полностью (grep по `plugins/` и `themes/`
   пуст). **Живой флаг `idpg_org_pending` (`IDPG_Organizer_Verify::META_PENDING`,
   подтверждение email) не тронут** — это разные ключи, их легко спутать.
6. Тесты: из `test-class-init.php` убрана запись `IDPG_Organizer_Page`;
   комментарии в `test-organizer-page.php`, `test-organizer-social-login.php`,
   `test-email-validator.php` приведены к факту.

**Приёмка.**

| Проверка | Результат |
|---|---|
| `/organizer/complete/` и `/organizer/complete` | **404** |
| `/organizer/` | 302 → `/login/` (штатная защита гостя) |
| `/organizer/profile/`, `/organizer/events/`, `/organizer/venues/` | 302 → `/login/` |
| `/organizer/event/new/`, `/organizer/venue/new/` | 302 → `/login/` |
| контрольные `/`, `/login/`, `/afisha/` | 200 / 200 / 301 — не сломаны |
| Полный набор `idpg-core` | 529 тестов / 1581 утверждение, падений **2** — оба гард сборки CSS (DEV-87), к задаче не относятся |

Уменьшение счётчика на 1 тест (−2 утверждения) объяснимо: `test-class-init.php`
строит проверки через `@dataProvider data_static_init` по карте `STATIC_INIT`,
поэтому удаление одной записи убирает одну строку прогона.

**Не тронуто (ограничение задачи):** `/organizer/profile/`, `/organizer/event/`,
`/organizer/venue/`, `class-organizer-url.php` (кроме версии правил),
`class-organizer-verify.php`.

**Документы приведены к факту:** `docs/PROJECT.md (раздел 1)` §6.1 (строка про анкету), §6.2
(врезка о последнем мёртвом модуле), §6.2.2 (снята устаревшая врезка «интерфейса
нет, см. G5»), §6.4 G7; `docs/archive/TZ_IMPLEMENTED.md` §9 п.1.

**Ссылки.** DEV-72 (продуктовое решение), DEV-94 (закрытие G5/G6);
`docs/PROJECT.md (раздел 1)` §6; `docs/archive/TZ_IMPLEMENTED.md` §9; журнал —
`.workbuddy-ai/memory/2026-09-30.md`.

#### DEV-142. `docs/PROJECT.md (раздел 1)` §6 — приведён к коду (закрыто 30.09.2026)

**Статус: закрыто 30.09.2026.** Только документация, код не тронут.

**Повод.** Ревизия чисел DEV-140 была **неполной**: она покрыла карточки бэклога
и `docs/PROJECT.md (раздел 1)` §1.9/§3.4.1, но не §6 — а там оставались устаревшие ссылки
на строки и ложные утверждения. Нашлось при разборе ЛК организатора.

**Расхождения и что с ними стало.**

| Что | Было | Стало |
|---|---|---|
| §6.1 анкета | «удалена вместе с классом, шаблоном, скриптом» | исправлено **DEV-141** (остатки реально удалены) |
| §6.4 G7 | «`_idpg_org_pending` удалён» | исправлено **DEV-141** (читался в 2 местах, теперь удалён) |
| §6.2.2 врезка | «интерфейса создания/редактирования нет (см. G5)» | снята — G5 закрыт DEV-94, интерфейс есть |
| §6.1 меню | «7 функций `idpg_organizer_*`» | **8 функций** + `idpg_core_is_organizer_cabinet()` |
| §6.1 роутер | `idpg-core.php:109`, `:151` | ~строка **84** (`require_once`), ~**157** (`init()`) |
| §6.1 меню | `functions.php:404` | ~строка **413** |
| §6.1 ассеты | `functions.php:212-215`, `:216`, `:217`, `:218` | ~строки **224-227** |
| §6.1 страница `/organizer/` | `functions.php:455`, `:513` | ~строки **464**, **522** |
| §6.1 разделы | `section-{dashboard,events,profile}.php` | добавлен `section-venues.php` |
| §6.1 `guard_cabinet_redirect()` | `class-organizer-url.php:255` | ~строка **308** |
| §6.1 колонка статуса | `class-meta.php:1098-1113` | методы `add_banner_column` ~**1096**, `render_banner_column` ~**1108** |
| §6.1 модерация | `idpg-core.php:151` | ~строка **154** |
| шапка §6 | «тема 1.0.75» | **1.0.78** |
| §1.8 | `IDPG_Organizer_Page` в списке `::init()` | класс удалён DEV-141, помечен зачёркнутым |
| §1.8 | `idpg-core.php:99` | ~строка **101** |
| §6.4 G12 | `formatting.php:5158` | ~строки **5155-5157** (ветка `case 'default_role'`) |

**Проверено и оказалось верным (не трогалось):**
`class-current-event-banner.php:707-713` (выборка баннера по `META_BANNER_APPROVED`),
`class-auth-rate-limiter.php:272`, `class-rest-auth.php:601`,
`class-rest-organizer.php:1266`, а также счётчик **«23 класса»** в §1.8 —
в `test-class-init.php` ровно 23 записи с источником `idpg_core_init()`
(всего в карте 26: ещё три инициализируются в конце своих файлов).

**Причина дрейфа номеров.** В `themes/idpg_core/functions.php` правки
DEV-131/DEV-138 дали сдвиг **+9**; в `plugins/idpg-core/idpg-core.php` удаление
анкеты (DEV-141) дало сдвиг **−3**. Поэтому в тексте теперь пишется
`~строка N`, а не `файл:NNN` — номер смещается при любой правке выше.
Правило — §15, рецепт — `MEMORY.md («Рецепты»)`.

**Ссылки.** DEV-140 (неполная ревизия), DEV-141 (удаление анкеты);
`docs/PROJECT.md (раздел 1)` §1.8, §6; журнал — `.workbuddy-ai/memory/2026-09-30.md`.

#### DEV-143. Миграция партиалов на токены — DEV-87 закрыт (30.09.2026)

**Статус: закрыто 30.09.2026.** `CHANGED` **88 → 0**, `MISSING` 0, лишних 0.

**Что было.** Сборка SCSS расходилась с `style.css` на 88 правил: четыре партиала
держали **170 значений** старой палитры Tailwind (`#2563eb`, `#0f172a`, `#e2e8f0`,
`#64748b` и др.), тогда как `style.css` давно перешёл на токены `var(--idpg-*)`.
Из-за этого `npm run compile:css` откатывал дизайн, а два гарда
`Test_Css_Build` были красными — единственные падения во всём наборе.

**Метод.** Не «на глаз», а по сторожу: его алгоритм (обход `{…}`, ключ
«at-контекст + нормализованный селектор», первое вхождение, нормализация деклараций
сортировкой) **повторён на Python**, чтобы получить поправило по каждому правилу.
Это дало точную карту «hex → токен» **без конфликтов** — она же подтвердила, что
местами дело не в цвете (см. ниже).

**Что сделано.**

1. **132 замены** в четырёх партиалах: `#0f172a` → `--idpg-ink`, `#2563eb` →
   `--idpg-primary`, `#1d4ed8` → `--idpg-primary-active`, `#475569` → `--idpg-body`,
   `#64748b` → `--idpg-muted`, `#cbd5e1`/`#e2e8f0`/`#e2e4e7` → `--idpg-hairline`,
   `#f8fafc` → `--idpg-surface-soft`, `#f1f5f9` → `--idpg-surface-strong`,
   `#eff6ff` → `--idpg-primary-bg`, `#ffffff` → `--idpg-canvas`,
   `rgba(37, 99, 235, 0.1)` → `rgba(0, 82, 255, 0.1)`.
   `#fff` (8 вхождений) **не трогался** — в этих местах `style.css` тоже использует
   `#fff`.
2. **Три `box-shadow`** с `rgba(0, 0, 0, …)` переведены на токены
   (`--idpg-hairline`, `--idpg-surface-soft`).
3. **Дубль `.idpg-city-selector__btn__icon` убран.** Он был задан дважды: группой
   с `.idpg-city-selector__icon` в `components/_city-selector.scss` и вложенным
   `&__icon` в `components/_header.scss` (последний компилировался в лишнее
   правило). Оставлена группа — как в `style.css`.
4. **Порядок `.create-event__btn { min-height: 44px }` восстановлен:** блок жил
   в `components/_recent-events.scss`, который подключается **раньше**
   `pages/create-event`, поэтому в сборке он оказывался первым, а в `style.css`
   стоит **после** основного правила. Перенесён в конец `components/_mobile.scss`.

**Приёмка.**

| Проверка | Результат |
|---|---|
| `Test_Css_Build` (5 тестов) | **OK**, `MISSING` 0, лишних 0, `CHANGED` **0** |
| Полный набор `idpg-core` | **529 тестов / 1581 утверждение, падений 0** (было 2) |
| Сборка детерминирована | повторный прогон — тот же MD5 |
| `style.css` | **не изменён** (79 743 б, MD5 `62eb661b…`) |

**Замер.** Сборка — 93 287 б, MD5 `96486b6ce6963008d791bbdc101f148a`. Файлы
побайтово разные (форматирование sass длиннее: 4 167 строк против 3 523), но
правила совпадают; комментарии сохранены (64 → 63, различие — перемещённые).

**Перегенерация `style.css` не выполнялась.** Правила уже совпадают, а файл стал бы
на 644 строки длиннее из-за форматирования. Решение за владельцем; команда —
`npm run compile:css:apply`, изменение обратимо через git. Безопасные команды
сборки введены в DEV-144.

**Документы.** `docs/PROJECT.md (раздел 1)` §3.4.1 — предупреждение «`compile:css` запускать
нельзя» снято, замер обновлён; карточка DEV-87 — статус «закрыто», разбор DEV-132
сохранён как история; §14 — вывод помечен как относящийся к прежнему состоянию.

**Ссылки.** DEV-87 (постановка), DEV-132 (разбор расхождения), DEV-138/DEV-139
(смежные правки темы); журнал — `.workbuddy-ai/memory/2026-09-30.md`.

#### DEV-144. Безопасный `compile:css` (закрыто 30.09.2026)

**Статус: закрыто 30.09.2026.** `style.css` не перезаписывался.

**Проблема.** После DEV-143 `CHANGED` = 0, но `style.css` остаётся **эталоном**,
а sass форматирует иначе (4 167 строк против 3 523). Скрипт `compile:css`
писал **напрямую в `style.css`** — то есть любой запуск молча переформатировал
эталон на 644 строки: поведение не менялось, но `git diff` превращался в шум,
а «эталонность» файла размывалась. Классическая мина.

**Что сделано.**

| Было | Стало |
|---|---|
| `compile:css` → `sass … :style.css` | `compile:css:temp` → в `style.css.tmp` |
| — | **`check:css`** → сборка в `style.css.tmp` + сверка правил с `style.css` |
| — | `compile:css:apply` → **осознанная** перезапись `style.css` |

- Команды `compile:css` **больше нет** — обращение к ней падает, а не перезаписывает
  эталон.
- `check:css` использует новый **`themes/idpg_core/bin/check-css-build.mjs`** —
  реплика алгоритма сторожа `Test_Css_Build` (обход `{…}`, ключ «at-контекст +
  нормализованный селектор», первое вхождение побеждает, нормализация деклараций).
  Печатает «потеряно / лишних / изменено» и возвращает **1** при расхождении.
  Авторитетный гейт в CI остаётся PHPUnit-тестом.
- `style.css.tmp` закрыт `.gitignore` (`*.tmp`) **и** добавлен в исключения
  релизного архива (`bundle`) — иначе 93 КБ мусора уехали бы в ZIP темы.
- `watch` по-прежнему пишет в `style.css` (живая разработка) — оставлен осознанно,
  описан в `docs/PROJECT.md (раздел 1)` §3.4.1.

**Приёмка.**

| Проверка | Результат |
|---|---|
| `npm run check:css` | сборка в `style.css.tmp` (93 287 б) + «OK: правила совпадают», exit 0 |
| Тот же скрипт на «плохой» сборке | «РАСХОЖДЕНИЕ: потеряно 0, лишних 1, изменено 88», exit 1 — совпадает со сторожем |
| `style.css` после проверок | **не изменён** (79 743 б, MD5 `62eb661b…`) |
| `style.css.tmp` | `git check-ignore` → `.gitignore:144:*.tmp` |
| `package.json` | валиден, `compile:css` отсутствует |

**Побочно закрыт пункт DEV-26** (скрипт `check:css` в требовании) — остался только
шаг в CI, который упирается в привязку CircleCI (DEV-136).

**Документы.** `docs/PROJECT.md (раздел 1)` §3.4.1 — таблица команд; `docs/archive/PLUGIN_TZ.md` — пометка
у `style.css`; карточка DEV-26 — отметка о частичном выполнении;
`MEMORY.md («Рецепты»)` — безопасная проверка сборки.

**Ссылки.** DEV-87 / DEV-143 (сборка эквивалентна), DEV-132 (инцидент 29.09,
когда вывод ушёл в `style.css`); журнал — `.workbuddy-ai/memory/2026-09-30.md`.

---

#### DEV-145. Формальное закрытие DEV-113/116/118 (P0, S) — закрыто 30.09.2026

**Контекст.** Три P0-задачи не имели явного статуса в бэклоге, но фактически
были закрыты ранее:

| DEV | Суть | Когда закрыто | Проверка |
|---|---|---|---|
| **DEV-113** | `schedule_price_moderation()` — модерация по цене | DEV-102 (восстановление git) | `class-event-moderation.php:180`, хук `carbon_fields_post_meta_container_saved:102`, вызов из `class-rest-organizer.php:1266` |
| **DEV-116** | `RESET_IP_LIMIT` — лимит сброса пароля по IP | DEV-93 | `class-auth-rate-limiter.php:69` (`= 10`), `check_password_reset_ip_attempt():272`, тесты 6/6 OK |
| **DEV-118** | Меню и ассеты кабинета (`inc/organizer-menu.php`, `organizer.css/js`) | DEV-102 + DEV-131 (регрессии) | `functions.php:413` (require), `:224–227` (enqueue), 4 раздела ЛК работают, страница `/organizer/` создаётся |

**Что сделано.** Проверены все три пункта в коде, статусы обновлены в
`docs/PROJECT.md (раздел 2)`, `docs/PROJECT.md (раздел 1)` §1.9 (ссылки на тесты), журнал —
`.workbuddy-ai/memory/2026-09-30.md`.

---

### 12. DEV-130 (P0, S). Проверка статуса трёх P0-задач (30.09.2026)

**Повод.** В сводке за 29.09 три P0-задачи не упоминались отдельными строками,
хотя их результат был заявлен внутри DEV-102. Проверено по коду и в рантайме.

**Важно про нумерацию.** Номеров **DEV-113, DEV-116, DEV-118 в проекте не
существует**: `docs/PROJECT.md (раздел 2)` заканчивается на DEV-100, ни в `docs/`, ни в
`.workbuddy-ai/` эти идентификаторы не встречались ни разу. Фактически
описанные работы — это:

| Заявленный номер | Фактическая задача | Источник |
|---|---|---|
| DEV-113 | Модерация по цене (`schedule_price_moderation`) | DEV-102 (`c12921c`), карточка DEV-50/94 |
| DEV-116 | Лимит сброса пароля по IP | **DEV-93** (карточка есть) |
| DEV-118 | Меню и ассеты кабинета | **DEV-38** (`docs/archive/TZ_BACKLOG_PROMPTS_ORGANIZER_V14.md`) + **DEV-39** (страница) |

Ниже — статус по факту кода.

#### DEV-113 (модерация по цене) — ЗАКРЫТО (DEV-102, формально DEV-145)

| Проверка | Результат |
|---|---|
| `schedule_price_moderation()` в `class-event-moderation.php` | **есть**, строка 180 (public) |
| Хук `carbon_fields_post_meta_container_saved` | зарегистрирован, строка 102 |
| Вызов из `class-rest-organizer.php` | **есть**, строка **1266** (не 1145 — номер в постановке устарел) |
| Защита вызова | `if ( class_exists( 'IDPG_Event_Moderation' ) )` — фатала при отсутствии класса нет |
| `php -l` на обоих файлах | без ошибок |
| Рантайм | класс `IDPG_Event_Moderation` загружается, метод резолвится |

Вызов стоит на пути `submit` организатора, после перевода поста в `pending`.
Обоснование в коде: штатный триггер — Carbon Fields, которого на REST-пути нет,
поэтому фаза запускается явно. Логика отложена на `shutdown`, т.к. в Gutenberg
статус поста приходит первым запросом, а мета — вторым.

#### DEV-116 (= DEV-93, лимит сброса пароля по IP) — ЗАКРЫТО (DEV-93, формально DEV-145)

| Проверка | Результат |
|---|---|
| `RESET_IP_LIMIT` в `class-auth-rate-limiter.php` | **есть**, строка 69, значение **10** |
| `RESET_IP_WINDOW` | **есть**, строка 74, значение **3600** |
| `check_password_reset_ip_attempt()` | **есть**, строка 272, используется в теле (290, 293, 309) |
| Вызов из `handle_forgot_password()` | **есть**, `class-rest-auth.php:601` |
| Порядок вызовов | IP-лимит **до** лимита по email — как и требовалось (иначе перебор адресов с одной машины не ловился бы) |
| Тесты `test-auth-rate-limiter.php` | **6 из 6 OK**, 31 утверждение |

Тесты (запрошенных 5 + 1 дополнительный):
`test_reset_ip_allows_within_limit`, `test_reset_ip_blocks_over_limit`,
`test_reset_ip_is_scoped_to_address`, `test_reset_ip_skips_unresolved_address`,
`test_reset_ip_ignores_garbage`, `test_reset_ip_catches_email_rotation`,
`test_reset_email_limit_unchanged`.

#### DEV-118 (= DEV-38 + DEV-39) — ЗАКРЫТО (DEV-102 + DEV-131, формально DEV-145)

**Меню и ассеты — всё на месте:**

| Проверка | Результат |
|---|---|
| `require_once …/inc/organizer-menu.php` | **есть**, `functions.php` ~строка 413 |
| `wp_enqueue_style` `organizer.css` | **есть**, ~строка 224 (зависимость `idpg_core-style`, версия по `filemtime`) |
| `wp_enqueue_script` `organizer.js` | **есть**, ~строка 225 |
| `wp_enqueue_script` `organizer-event-form.js` | **есть**, ~строка 226 |
| `wp_enqueue_script` `organizer-venue-form.js` | **есть**, ~строка 227 |
| Условие подключения | `idpg_core_is_organizer_cabinet()` — только в кабинете |
| Файлы на диске | все 4 есть: `organizer.css` 5 937 б, `organizer.js` 31 207 б, `organizer-event-form.js` 34 447 б, `organizer-venue-form.js` 9 469 б |
| Рантайм | хелпер есть, функции меню зарегистрированы (`idpg_organizer_menu`, `idpg_organizer_menu_sort`, `idpg_organizer_menu_sections`) |

**Регрессия: страница `/organizer/` не создаётся.** Найдено при рантайм-проверке:
`idpg_core_get_organizer_page_id()` возвращает **0**, `get_page_by_path('organizer')`
— `NULL`, опции `_idpg_organizer_page_id` в БД нет.

Причина: DEV-102 восстановил хук как `add_action( 'after_switch_theme', … )`
(`functions.php`, на момент DEV-130 — ~строка 448; после починки DEV-131 исправленный
`after_setup_theme` — ~строка 464), тогда как **DEV-39 явно документировал обратное** —
«`after_setup_theme`, не `after_switch_theme`» (`docs/archive/TZ_BACKLOG_PROMPTS_ORGANIZER_V14.md`,
п. 2), с обоснованием: так же подключены все пять соседних создателей страниц
(login, reset-password, terms, privacy, offer), а `after_setup_theme` вдобавок
самовосстанавливается, если страницу удалили. `after_switch_theme` срабатывает
**раз в жизни темы**, поэтому у уже переключённой темы страница не появится никогда.

Сама функция при этом исправна — прямой вызов создаёт страницу (проверено: id 718,
`publish`, шаблон `page-organizer.php`; тестовый пост и опция удалены после пробы).

**Вторая регрессия: исключение из sitemap — мёртвый код.** Зарегистрирован фильтр
`wpseo_exclude_from_sitemap_by_posts_ids` (`functions.php`, на момент DEV-130 —
~строка 502; исправленный `..._by_post_ids` после DEV-131 — ~строка 522), множественное
`posts`, а Yoast с 15.x вызывает `wpseo_exclude_from_sitemap_by_post_ids`
(**единственное** `post`) — `class-post-type-sitemap-provider.php:296`.
Проверено: множественный вариант в коде Yoast **не встречается ни разу** (0
совпадений). Фильтр не сработает никогда, страница кабинета останется в sitemap.
DEV-39 в п. 4 называл правильное имя — `..._by_post_ids`.

#### Регрессии (закрыты DEV-131)

Обе регрессии — следствие DEV-102 и относятся к **DEV-39** (задача формально
«выполнена 28.09»). Код в рамках DEV-130 не трогался (ограничение задачи).

**→ Решение принято и выполнено в DEV-131 (30.09.2026): обе регрессии починены.**
- `after_switch_theme` → `after_setup_theme` (страница `/organizer/` создаётся);
- `wpseo_exclude_from_sitemap_by_posts_ids` → `wpseo_exclude_from_sitemap_by_post_ids`
  (исключение из sitemap Yoast работает).

**Статус: полностью ЗАКРЫТО (DEV-145).**
См. §13 ниже.

#### Рантайм-проба (метод)

Стенд `https://idupogorodu.test`, PHP 8.2.29 CLI, бутстрап WP с подстановкой
`$_SERVER['HTTP_HOST']` / `REQUEST_SCHEME` (без `define('WP_CLI', true)`).
`/organizer/` гостем отдаёт **302 → /login/** — это штатная защита, а не поломка.
Полный прогон `idpg-core`: **513 тестов / 1556 утверждений**, падений ровно 2 —
оба гард сборки CSS (`test_rebuild_does_not_invent_or_drop_rules`,
`test_rebuild_keeps_declarations_identical`), к DEV-113/116/118 не относятся.

---

### 13. DEV-131 (P0, S). Починка двух регрессий DEV-102 (30.09.2026)

**Статус: ЗАКРЫТО.** Обе регрессии, найденные DEV-130 в §12, устранены.
Правки аддитивные, по одной строке каждая, в `themes/idpg_core/functions.php`.

#### Правка 1 — хук создателя страницы `/organizer/`

| | |
|---|---|
| Было (`:455`) | `add_action( 'after_switch_theme', 'idpg_core_create_organizer_page' );` |
| Стало (`:455`) | `add_action( 'after_setup_theme', 'idpg_core_create_organizer_page' );` |

`after_switch_theme` срабатывает раз за жизнь темы — у уже переключённой темы
страница не появлялась никогда, а удалённая не восстанавливалась.
`after_setup_theme` выполняется на каждом запросе и совпадает с конвенцией файла:
на нём же висят остальные шесть создателей страниц (`:611` меню, `:648` login,
`:685` reset-password, `:733` terms, `:783` privacy, `:828` offer).
Так же задокументировано в DEV-39 («`after_setup_theme`, не `after_switch_theme`»).

#### Правка 2 — имя фильтра исключения из sitemap Yoast

| | |
|---|---|
| Было (`:513`) | `add_filter( 'wpseo_exclude_from_sitemap_by_posts_ids', … );` |
| Стало (`:513`) | `add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', … );` |

Yoast вызывает `wpseo_exclude_from_sitemap_by_post_ids` (**единственное** `post`) —
`class-post-type-sitemap-provider.php:296`. Множественный вариант `posts` в коде
Yoast не встречается ни разу. До правки фильтр был мёртвым кодом, и страница
кабинета оставалась в карте сайта.

#### Проверка

**Рантайм** (стенд `https://idupogorodu.test`, PHP 8.2.29 CLI):

- `has_action('after_setup_theme', 'idpg_core_create_organizer_page')` → истина;
  `has_action('after_switch_theme', …)` → ложь;
- страница создаётся автоматически на инициализации,
  `idpg_core_get_organizer_page_id()` > 0, опция `_idpg_organizer_page_id` заполнена;
- **самовосстановление работает**: после удаления страницы (и поста, и опции)
  она создаётся заново — это и есть смысл `after_setup_theme`;
- `apply_filters('wpseo_exclude_from_sitemap_by_post_ids', array())` содержит ID
  страницы кабинета.

**Юнит-тесты** — `plugins/idpg-core/tests/test-organizer-page-creation.php`,
добавлены три регрессионных теста:

| Тест | Что проверяет |
|---|---|
| `test_page_creator_hooked_on_after_setup_theme` | привязка на `after_setup_theme` есть, на `after_switch_theme` — нет |
| `test_sitemap_filter_uses_yoast_hook_name` | фильтр на `..._by_post_ids` есть, на `..._by_posts_ids` — нет |
| `test_sitemap_exclusion_fires_through_yoast_hook` | страница реально попадает в исключения через хук Yoast |

Прогон файла: **12 тестов / 23 утверждения — OK**. Полный прогон `idpg-core`:
**516 тестов / 1562 утверждения**, падений ровно 2 — оба гард сборки CSS
(`Test_Css_Build`, см. DEV-87), к DEV-131 не относятся.

#### Замечание по тестовому окружению

`WP_UnitTestCase::tear_down()` вызывает `_restore_hooks()`, который откатывает
`$GLOBALS['wp_filter']` к снимку, снятому в первом `set_up()`. Хуки, добавленные
при `require_once functions.php` (theme подключена внутри теста), в этот снимок
не попадали и после первого теста исчезали; так как `require_once` идемпотентен,
`add_action()` повторно не выполнялся. Поэтому в `setUp()` класса добавлен
`restore_theme_hooks()` — он перерегистрирует проверяемые привязки до тела теста.
Функции темы при этом загружаются один раз, как и раньше.

#### Синтаксис

`php -l` для изменённых файлов — чисто на PHP 8.2.29 (CLI) и 8.3.31 (веб-сервер):
`themes/idpg_core/functions.php`, `plugins/idpg-core/tests/test-organizer-page-creation.php`.

---

### 14. DEV-132 (P0, S). Перепроверка DEV-87: закрытие НЕ подтверждено (30.09.2026)

**Статус: задача выполнена, но её цель не достигнута — DEV-87 остаётся открытым.**

**Постановка** просила подтвердить закрытие DEV-87: заявлено, что контрольная
сборка `npx sass` даёт байт-в-байт совпадение с `style.css` (MD5 `2655d57a…`,
91 613 байт), а «88 расхождений» не воспроизводятся.

**Результат проверки — заявление не подтвердилось.** Сборка воспроизводится
точно (91 613 б, MD5 `2655d57a…`), но **живой `style.css` — другой файл**:
79 743 б, MD5 `f8ed3986c384009e487a2f2193962ac7` (замер 30.09.2026; после DEV-138,
поднявшего версию темы, MD5 стал `62eb661b…` при том же размере — правила CSS
не менялись, вывод проверки в силе).

| Проверка | Результат |
|---|---|
| MD5 сборки | `2655d57a044e3ea4c1d584a7e6612907`, 91 613 б — **воспроизведён** |
| MD5 `style.css` | `f8ed3986c384009e487a2f2193962ac7`, 79 743 б — **не совпадает** |
| Различие форматное? | **Нет.** CR в обоих файлах 0 (оба LF); нормированные MD5 разные |
| Правил `MISSING` | **0** |
| Правил: `style.css` / сборка | **525 / 526** (лишнее — `.idpg-city-selector__btn__icon`) |
| Правил `CHANGED` | **88** — `.create-event__*` 40, `.dashboard__*` 24, `.cabinet__*` 21, `.idpg-ad-widget*` 3 |
| `var(--idpg-*)` | 373 → **331** (теряется 42) |
| Hex-значений | 90 → **220** (добавляется 130) |
| `Test_Css_Build` | **2 падения** (корректное состояние гарда) |

**Почему возникла ложная уверенность.** 29.09 (DEV-129) при проверке сборки
пробный `npx sass … :style.css` **случайно перезаписал боевой `style.css`**
(79 743 → 91 613 б). Сверка MD5 шла уже с затёртым файлом и «совпала»; файл
позже восстановили из git, и совпадение исчезло. **Измерение было испорчено
инцидентом, вывод — неверен.** В журнале 29.09 и в MEMORY.md остались записи
«сборка байт-в-байт равна style.css» — они **опровергнуты** и исправлены.

**Причина 88 расхождений — не формат, а незавершённая миграция токенов.**
`style.css` использует `var(--idpg-*)`; четыре SCSS-партиала этих компонентов
до сих пор держат жёсткий legacy-hex (`#2563eb`, `#0f172a`, `#64748b`,
`#e2e8f0`, `#f8fafc`, `#1d4ed8`, `#475569`, `#f1f5f9`, `#cbd5e1`):

| Файл | Hex-значений |
|---|---|
| `pages/_create-event.scss` | 78 |
| `pages/_dashboard.scss` | 41 |
| `pages/_cabinet.scss` | 38 |
| `components/_home-sidebar.scss` | 13 |
| **Итого** | **170** |

`base/_variables.scss` на токены уже переведён (`:22-30`), поэтому остальные
компоненты собираются верно — не мигрированы именно эти четыре.

**Вывод для эксплуатации (на момент DEV-132).** `npm run compile:css` запускать
**нельзя** — он перезапишет `style.css` и откатит токены (цвета станут жёсткими).

> **Снято 30.09.2026 (DEV-143).** Расхождение устранено, `CHANGED` = 0 —
> пересборка больше не откатывает токены. Предупреждение в шапке
> `test-css-build.php` и в `_variables.scss` относилось к тому состоянию.

**Ограничение задачи соблюдено:** пробная сборка направлена **только**
в `%TEMP%\style-check.css`, живой `style.css` не тронут (проверено: размер и MD5
до и после совпадают).

**Что нужно было для закрытия DEV-87:** перевести четыре партиала и остаток
`_variables.scss` с hex на `var(--idpg-*)`, убрать дубль
`.idpg-city-selector__btn__icon`. Критерий готовности: `CHANGED` = 0,
`added` = 0, оба CSS-теста зелёные, затем — перегенерация `style.css`.

**→ Выполнено в DEV-143 (30.09.2026):** `CHANGED` = 0, `added` = 0, оба теста
зелёные. Перегенерация `style.css` не делалась — правила уже совпадают, а файл
стал бы длиннее из-за форматирования sass; решение за владельцем.

---

### 15. DEV-140 (P2, M). Числовая ревизия карточек (30.09.2026)

Повод — пять случаев подряд, когда числа в карточках не совпадали с кодом:
DEV-129 (коммит назван «DEV-98», задача — DEV-81), DEV-130 (несуществующие
DEV-113/116/118), DEV-133 (`ROLE_VERSION` 8 vs 7, «6 require» vs 39),
DEV-138 (версия 1.0.77, которой не было), DEV-139 (`Requires PHP` 8.2 vs 7.4).
Ниже — сплошная сверка по четырём категориям. Правило вынесено в шапку
документа («О числах в карточках»).

#### 15.1 Версии

| Карточка | Заявлено | Факт в репозитории | Вердикт |
|---|---|---|---|
| DEV-04 | idpg-core 1.1.3, тема 1.0.76 | 1.1.2 / 1.0.78 | ⚠️ версии относятся к состоянию **до создания репозитория** (DEV-53, 19.09) или к живому стенду |
| DEV-05 | idpg-core 1.1.5, тема 1.0.77 | 1.1.2 / 1.0.78 | ⚠️ то же |
| DEV-06 | idpg-core 1.1.6, тема 1.0.78 | 1.1.2 / 1.0.78 | ⚠️ то же |
| DEV-07 | idpg-core 1.1.4 | 1.1.2 | ⚠️ то же |
| DEV-34 | idpg-core 1.1.7, тема 1.0.79 | 1.1.2 / 1.0.78 | ⚠️ то же |
| DEV-37 | idpg-core 1.1.8, тема 1.0.80 | 1.1.2 / 1.0.78 | ⚠️ то же |
| DEV-38 | тема 1.0.81 | 1.0.78 | ⚠️ то же |
| DEV-84 | «поднято до 1.0.77» | в дереве было 1.0.75/1.0.73 | ❌ **исправлено DEV-138** (уточнение в карточке) |
| DEV-133 | ROLE_VERSION 8 | `class-roles.php:44` → **7** | ✅ уточнено в карточке (7 → 8 — это DEV-97) |
| DEV-138 | тема 1.0.77 | было 1.0.75 / 1.0.73 | ✅ **исправлено**: 1.0.78 в обоих местах |
| DEV-139 | Requires PHP 8.2 | 7.4 | ✅ решено владельцем — оставлено 7.4 |

**Проверено отдельно:** `IDPG_CORE_VERSION` в репозитории — **1.1.2 с первого
коммита** (`06fe9bb`, DEV-53) и **никогда не менялся** (`git log -S`). Значений
`1.1.3…1.1.8` в истории репозитория нет ни разу. То есть версии плагина в статусах
карточек **непроверяемы по репозиторию** — не сравнивать их с деревом.

#### 15.2 Количества

| Карточка | Заявлено | Факт | Вердикт |
|---|---|---|---|
| DEV-102 | `require_once` 33 → **39** | 39 | ✅ |
| DEV-102 | `class-event-moderation.php` 16 → 222 строки | 603 строки (росла дальше) | ✅ (снимок на дату) |
| DEV-100 | `test-class-init` 39 тестов / 96 утверждений | **39 / 96** | ✅ |
| FEATURES §1.9 | «PHPUnit — **13 файлов**» | **39** файлов `test-*.php` | ❌ **исправлено** |
| DEV-87 | hex: 78+41+38+13 = **170** | 78 / 41 / 38 / 13 = 170 | ✅ |
| DEV-87 | 103 токена `--idpg-*`, 373 ссылки `var(--idpg-` | 103 / 373 | ✅ |
| DEV-118 | файлы 5 937 / 31 207 / 34 447 / 9 469 б | совпадают | ✅ |
| DEV-130 | 513 тестов / 1556 утверждений | снимок; сейчас 530 / 1583 | ✅ (снимок) |
| DEV-138 | 523 / 1570 | снимок | ✅ |
| DEV-139 | 530 / 1583, падений 2 | совпадает | ✅ |
| DEV-102 | 465 / 1426 после восстановления | не перепроверялось | ⚠️ снимок, не сверялся |
| FEATURES §3.4.1 | MD5 `style.css` `f8ed3986…` | `62eb661b…` (DEV-138 сменил заголовок) | ❌ **исправлено** |

#### 15.3 Номера строк

**Исправлены (были устаревшими):**

| Карточка | Было | Стало |
|---|---|---|
| DEV-04 | `class-rest-auth.php:536` | маршрут ~164, `handle_logout()` ~575 |
| DEV-51 | `class-wp-auth-ru-provider.php:293-295` | `new_user_role()` ~290 |
| DEV-51 | `class-wp-auth-ru-provider.php:245` | передача роли ~231 |
| DEV-52 | `class-roles.php:186` | `publish_events => false` ~209 |
| DEV-32 / DEV-81 | `class-auth.php:125` | проверка `subscriber` ~206 |
| DEV-130 | `functions.php:404` | `require_once organizer-menu` ~413 |
| DEV-130 | `functions.php:448` / `:502` | помечены как «на момент DEV-130»; после DEV-131 — ~464 / ~522 |
| DEV-131 | `functions.php:455` / `:513` | хук ~464, фильтр ~522 |
| DEV-82 | `class-meta.php:1098-1113` | `add_banner_column` ~1096, `render_banner_column` ~1108 |
| DEV-84 / DEV-138 | `functions.php:11` | помечено «тогда ~11, после DEV-138 ~20» |

**Подтверждены верными (не трогались):** `class-maintenance.php:960`,
`class-wp-auth-ru-security.php:645`, `class-city-filter.php:351`,
`class-wp-auth-ru-max.php:112`, `class-views.php:182`,
`class-ticket-sync.php:322`, `class-roles.php:44`, `class-meta.php:802`,
`class-meta.php:1127`, `class-current-event-banner.php:707-713`,
`class-post-type-sitemap-provider.php:296`, `class-rest-auth.php:601`,
`wp-admin/includes/plugin.php:76-92`.

**Причина дрейфа:** в `functions.php` темы правки DEV-131/DEV-138 добавили
строки — все ссылки в этом файле сместились ровно на **+9**. Номера строк
устаревают при любой правке выше по файлу, поэтому в тексте они помечены
`~строка` и считаются подсказкой на дату, а не адресом.

#### 15.4 ID коммитов и задач

| Что | Проверка | Вердикт |
|---|---|---|
| Коммиты карточек: `c12921c`, `399477f`, `f6bcedd`, `75dd425`, `2069e37`, `765500c`, `1f6fe9a`, `841f229`, `e16c322`, `64cda30`, `fdf4ca0`, `a381795`, `447d23d`, `19d2ed8` | все существуют | ✅ |
| `2f5c376` | отсутствует | ✅ корректно задокументирован как утраченный (DEV-134) |
| `f6bcedd` | сообщение коммита называет «DEV-98», а задача — **DEV-81** | ⚠️ расхождение в самом коммите, зафиксировано DEV-129 |
| DEV-113 / DEV-116 / DEV-118 | карточек с такими номерами нет | ✅ зафиксировано DEV-130 (это DEV-42/93/38+39) |

#### 15.5 Итог

- **Исправлено в этом проходе:** 11 ссылок на строки, счётчик файлов тестов
  в `docs/PROJECT.md (раздел 1)` §1.9, MD5 `style.css` в `docs/PROJECT.md (раздел 1)` §3.4.1 и `docs/PROJECT.md (раздел 2)`
  §14/DEV-87 (с пояснением, почему MD5 сменился без смены размера).
- **Помечено как непроверяемое:** версии плагина в статусах карточек (относятся
  к состоянию до создания репозитория).
- **Помечено как снимок:** счётчики тестов и строк.
- **Правило:** числа из карточек — не факт о коде; проверять перед использованием.
  Рецепт — `MEMORY.md («Рецепты»)` «Проверка чисел карточки», грабли — `MEMORY.md («Грабли»)`,
  раздел «Работа с ТЗ и документацией».
---

### 16. DEV-147 (P2, S). Формальное закрытие фактически сделанных карточек (30.09.2026)

**Проблема.** Около 20 карточек реализованы в коде, но заголовок не содержал
«закрыто» — из-за чего сводка читалась как «~50 открытых задач».

**Метод.** Ничего не закрыто «по тексту карточки». Каждая помечена только после
проверки по коду и общему прогону тестов: **533 теста / 1596 утверждений, 0 падений**
(включая гард сборки CSS `tests/test-css-build.php`).

#### 16.1 Закрыто — 13 карточек

| DEV | Чем закрыта | Проверка |
|---|---|---|
| DEV-52 | формально DEV-147 | PHPUnit поднят, общий прогон зелёный (исторический снимок 19.09: 85 тестов / 210 утверждений) |
| DEV-85 | формально DEV-147 | 46 вхождений `.idpg-org-` в `assets/css/organizer.css`; гард сборки зелёный. Тело карточки уточнено — партиал отключён намеренно |
| DEV-87 | DEV-143 | гард `test-css-build.php` зелёный, расхождение сборки с `style.css` = 0 |
| DEV-90 | формально DEV-147 | `class-organizer-verify.php`, тест `test-organizer-verify-redirect.php` зелёный |
| DEV-91 | формально DEV-147 | расщепление кодов `retrieve_password()` на «тихие» и явные; лимитер в no-JS форме; тесты `test-auth-rate-limiter.php` |
| DEV-93 | = DEV-116, формально DEV-145 | `RESET_IP_LIMIT` в `class-auth-rate-limiter.php` |
| DEV-94 | формально DEV-147 | `page-organizer-event.php`, `organizer-event-form.js`, живые `section-dashboard.php` / `section-events.php` |
| DEV-95 | решение владельца 29.09.2026 | `test-organizer-currency.php` |
| DEV-97 | 29.09.2026 | `test-organizer-event-form-schema.php`, схема по `carbon-fields-export.json` |
| DEV-98 | формально DEV-147 | CPT `venue` (`class-cpt.php`), `page-organizer-venue.php`, `section-venues.php`, `test-organizer-venue-form.php` |
| DEV-99 | формально DEV-147 | `validate_callback` на всех 9 перечислениях маршрута события |

Ранее в этом же проходе помечены: **DEV-34, DEV-37, DEV-38** (15.09.2026) и
**DEV-42, DEV-46** (28.09.2026) — по датам их собственных блоков статуса.

#### 16.2 Снято — 2 карточки

| DEV | Судьба | Основание |
|---|---|---|
| DEV-01 | заменено DEV-72 | слой анкеты организатора удалён целиком, `IDPG_Organizer_Page` и маршрут `/organizer/complete/` не существуют |
| DEV-50 | снято DEV-72 | в карточке стояли **два противоречащих статуса** — «выполнено 19.09.2026» и «закрыт DEV-72». Снят правильно: флаг `pending` ушёл вместе с анкетой. Помечен СНЯТО, а не ЗАКРЫТО |

#### 16.3 Расхождение с исходной постановкой

- **DEV-73 в бэклоге не существует.** Карточки с таким номером нет ни разу —
  DEV-73 только упоминается в других карточках (профиль организатора). Закрывать
  нечего. Тот же принцип, что у DEV-113/116/118, зафиксированный в §15.4.
- **«Ещё 3» из задания не идентифицированы** — в списке они не названы.

#### 16.4 Счётчик: 47 открытых, а не ~30

Разбор заголовков даёт: **всего 81 карточка, ЗАКРЫТО 32, СНЯТО 2, ОТКРЫТО 47.**

До ~30 не хватает ~17 карточек, и они **не входили в список задания**. Почти все
из них имеют блок «Статус: выполнено» в теле, но заголовок не помечен:

`DEV-3, DEV-4, DEV-5, DEV-6, DEV-7` (P0, безопасность), `DEV-29`, `DEV-47`,
`DEV-48`, `DEV-53`, `DEV-54`, `DEV-78`, `DEV-82`, `DEV-83`, `DEV-84`, `DEV-100`, `DEV-136`.

Это разнородная смесь: часть — реально закрытая работа, часть — снятые задачи,
часть (например `DEV-100`) уже помечена «Закрыто DEV-100» в `docs/PROJECT.md (раздел 1) §6`, но
карточка в бэклоге осталась открытой. Закрывать P0-карточки безопасности по строке
«выполнено», написанной предыдущей сессией, вслепую нельзя — поэтому они вынесены
в отдельный проход и требуют подтверждения владельца.

#### 16.5 Пересчёт DEV-148 (30.09.2026)

§16.4 посчитал карточки по блокам статуса в теле и **не учёл пометки в самих
заголовках**. После проверки по коду (см. §17) числа другие.

| Метрика | §16.4 (до прохода) | После DEV-148 |
|---|---|---|
| Всего карточек | 81 | 81 |
| ЗАКРЫТО | 32 | **50** |
| СНЯТО | 2 | **2** |
| НЕ ЗАДАЧА | 0 | **1** |
| ОТКРЫТО | 47 | **28** |

**Из чего прирост 18 закрытых:**

| Источник | Карточки | +ЗАКРЫТО |
|---|---|---|
| Объём этого прохода | `DEV-47`, `DEV-48`, `DEV-53`, `DEV-54`, `DEV-78`, `DEV-82`, `DEV-83`, `DEV-84`, `DEV-136` | 9 |
| P0-карточки безопасности, подтверждённые здесь же | `DEV-03`, `DEV-06`, `DEV-08` | 3 |
| Уже помечены «ЗАКРЫТО» **в заголовке**, но §16.4 считала открытыми | `DEV-90`, `DEV-91`, `DEV-94`, `DEV-97`, `DEV-98`, `DEV-99` | 6 |
| Итого | | **18** |

Прочие изменения статуса: `DEV-29` остаётся **открытой** (остатки п. 4 и п. 8),
`DEV-100` переведена в **НЕ ЗАДАЧА**.

**11 открытых:** `DEV-02`, `DEV-09`, `DEV-16`, `DEV-20`, `DEV-21`,
`DEV-26`, `DEV-27`, `DEV-27B`, `DEV-29`, `DEV-30`. Проверено по таблице §19.2 (30.09.2026,
после закрытия `DEV-15`, `DEV-17`, `DEV-18`, `DEV-19`, `DEV-22`, `DEV-28`, `DEV-33`, `DEV-89`, `DEV-92`).

**Про дубль `DEV-10`.** Одинаковый номер у двух карточек — «Продвижение событий:
срок закрепления и авто-снятие». Для счётчика это две единицы, потому что это два
отдельных блока с разным содержанием; идентификатор, вероятно, продублирован
опечаткой. Исправлять номер нельзя без решения владельца: на `DEV-10` ссылаются
другие карточки.

---

### 17. DEV-148 (P1, S). Разбор карточек без явного статуса (30.09.2026)

**Повод.** §16.4 перечислил 16 карточек, у которых заголовок не помечен, хотя в
теле есть блок «Статус: выполнено»: пять P0-карточек безопасности (`DEV-03`…
`DEV-07`) и одиннадцать (`DEV-29`, `DEV-47`, `DEV-48`, `DEV-53`, `DEV-54`, `DEV-78`,
`DEV-82`, `DEV-83`, `DEV-84`, `DEV-100`, `DEV-136`). Одиннадцать проверены в этом
проходе; `DEV-08` — родительская карточка этих P0 — сверена попутно. Закрывать по
строке «выполнено» вслепую нельзя, поэтому каждая сверена с кодом и документацией,
а статусная декларация добавлена аддитивно.

#### 17.1 Метод

1. Разобран **каждый** заголовок `### DEV-`: **81 заголовок**, из них **80
   уникальных ID** — `DEV-10` встречается дважды.
2. Статус карточки — её собственная декларация: пометка в заголовке (`ЗАКРЫТО`,
   `СНЯТО`) либо блок статуса в теле. Строка вида «Открыто. Ничего.» **внутри уже
   закрытой карточки** — это перечень остатков, а не статус карточки: в `DEV-94`
   остаток закрыт `DEV-95`, в `DEV-98`/`DEV-99` — своими хвостами.
3. Карточка без декларации считается открытой. Для карточек вне объёма DEV-148
   проверка по коду не выполнялась.
4. Ни одна существующая строка не переписана: все новые тексты — вставки внутрь
   карточек и новые разделы ниже.

#### 17.2 Вердикты по 11 карточкам

| DEV | Итог | Основание (проверено по коду) | Остаток |
|---|---|---|---|
| `DEV-29` | **ОТКРЫТО** | `build_api_url()` (`class-ticket-sync.php` ~227), `uploads/email-logo.png`, собранный SCSS в `style.css`, `IDPG_City_Filter` (инициализация в конструкторе, `render` ~1806), стили `.form-error`/`.is-error` (1485, 1488, 1528) | п. 4 — реальный `IDPG_TICKET_API_URL`; п. 8 — `themes/idpg_core/node_modules` |
| `DEV-47` | **ЗАКРЫТО** | `docs/PROJECT.md (раздел 1)` §6.3 (G1–G13) и §6.5 сверены с кодом; G5/G6 действительно закрыты 29.09.2026 карточкой `DEV-94`, обе строки зачёркнуты | — |
| `DEV-48` | **ЗАКРЫТО** | `SESSIONS_FIELD`, `carbon_set_post_meta()` (`class-rest-organizer.php` 1515), `IDPG_Meta::mirror_sessions()` (`class-meta.php` 861), `normalize_session_rows()`; `tests/test-sessions-sync.php` — 12 тестов | — |
| `DEV-53` | **ЗАКРЫТО** | remote `https://github.com/Franck-afk/idpg-source.git` (только `main` и `master`), `.gitignore` с deny-правилами, `.gitattributes`, `.circleci/config.yml`; tracked 312 файлов, `uploads` — 0, сторонних плагинов — 0 | — |
| `DEV-54` | **ЗАКРЫТО** | все 6 файлов `bin/phpcs-patches/` на месте | — |
| `DEV-78` | **ЗАКРЫТО** | `email-validator` как closures в `class-rest-auth.php` (132, 213), `is_email()` — единственная проверка (912), строковых `'validate_callback' => 'is_email'` не осталось; маршрут `organizer-complete` снят; `tests/test-email-validator.php` — 10 тестов | — |
| `DEV-82` | **ЗАКРЫТО** | gap-лист §6.3 сверен с кодом; G8 (152-ФЗ) подтверждён подключением `class-privacy.php`, G10 — плашка установлена, оплата публикации — отдельным вопросом | §6.2 пуст: это «нет разделов API», а не пропуск |
| `DEV-83` | **ЗАКРЫТО** | 14 PHP-тестов регистрации и 13 JS-проверок `check()`; добавлен `tests/test-auth-js.php`; CSRF-поле в трёх формах (`page-login.php`) | — |
| `DEV-84` | **ЗАКРЫТО по коду** | версия 1.0.78 синхронна в `functions.php`/`style.css`/`idpg-core.php`, `docs/.htaccess` и `theme .htaccess` на месте, `includes/class-cli.php` есть, бэкап `users-backup-2026-09-28.json` существует | состояние событий 693–695 (trash/untrash) не подтверждено — нужен доступ к БД |
| `DEV-100` | **НЕ ЗАДАЧА** | file-level функции — наблюдение, а не работа; встречаются в 11 из 47 PHP-файлов темы | — |
| `DEV-136` | **ЗАКРЫТО по коду и документации** | `.circleci/config.yml`: `phpunit` и `phpcs` (три прогона, baseline), `dev/phpcs-baseline` в репозитории отсутствует; CI и документация соответствуют коду | привязка CircleCI остаётся на владельца |

**Попутно подтверждены (вне объёма, но из списка §16.4):** `DEV-03` — HTTPS-политика
и `wp-config.php`/`.htaccess` по стенду, остаток только серверный (создать
`wp-config.php` из шаблона, сертификат прод, nginx-правило); `DEV-06` — модуль
152-ФЗ `IDPG_Privacy` полностью реализован (согласие, cookie-баннер, права
субъекта, админ-журнал), заголовок «частично» устарел; `DEV-08` — отсутствие
`error_log()`/`var_dump()`/`print_r()` в боевом коде и HTTPS-first к `ip-api.com`
подтверждены, чек-лист в `PRODUCTION.md` §10.1.

#### 17.3 Что проверить не удалось

- **События 693–695** (сироты после DEV-83). WP REST для записи недоступен:
  `rest_post_invalid_id` (404) для 693/694; CPT-маршруты — `rest_no_route` (404).
  Требуется прямой запрос к БД или подтверждение владельца через wp-admin.
- **`DEV-29` п. 4** — внешний URL провайлера билетов, вне репозитория.
- **`DEV-29` п. 8** — судьба `node_modules` в теме: решение владельца (удалить или
  вынести сборку в CI), не техническая ошибка.
- **`DEV-136`** — привязка CircleCI к репозиторию, недоступна агенту.

---

### 18. Журнал DEV-148 (30.09.2026)

#### 18.1 Хронология

1. Разобран `docs/PROJECT.md (раздел 2)`: 81 заголовок `### DEV-`, 80 уникальных ID, дубль
   `DEV-10` (строки 531 и 2644). Найдено, что §12–§16 лежат внутри тела карточки
   `DEV-118` — на счёт заголовков это не влияет, но структурно карточка «съела»
   разделы 12–16.
2. По каждой из 11 карточек прочитан код и документация, перечисленные в §17.2.
3. Проверено расхождение, всплывшее при пересчёте: `DEV-94` помечена «ЗАКРЫТО» в
   заголовке, а строка «Открыто. Ничего.» в её теле — перечень остатков, закрытый
   `DEV-95`. Аналогично у `DEV-89`/`DEV-92` строка «Открыто. Ничего.» является
   статусом карточки, поэтому обе остаются открытыми.
4. Уточнён счётчик: 50 / 2 / 1 / 28 вместо 32 / 2 / 0 / 47 (§16.4).
5. Обновлён чек-лист темы: пункты 5, 6, 7, 11, 12 → ✅; пункт 4 уточнён
   «🔶 ждёт реального URL»; пункт 8 остался открытым.

#### 18.2 Изменённые файлы

| Файл | Что сделано |
|---|---|
| `docs/PROJECT.md (раздел 2)` | 11 статусных деклараций в карточках (аддитивно), §16.5 (пересчёт), §17 (разбор), §18 (журнал) |
| `themes/idpg_core/README.md` | статусы пунктов 4, 5, 6, 7, 11, 12 в «Чек-листе для исправления» |
| `docs/PROJECT.md (раздел 1)` | не менялся: тесты не добавлялись, счётчик 557/1659 остаётся верным |

Код не менялся: DEV-148 — документационный проход.

#### 18.3 Остаётся за владельцем

| Вопрос | Где |
|---|---|
| Реальный `IDPG_TICKET_API_URL` | `DEV-29` п. 4, `README` п. 4 |
| Судьба `themes/idpg_core/node_modules` | `DEV-29` п. 8, `README` п. 8 |
| Состояние событий 693–695 (trash/untrash) | `DEV-84` |
| Привязка CircleCI к репозиторию | `DEV-136` |
| Дубль номера `DEV-10` и структура §12–§16 внутри карточки `DEV-118` | этот документ |
| Серверные остатки HTTPS-политики (wp-config из шаблона, сертификат, nginx) | `DEV-03` |

#### 18.4 Проверки

Код не менялся, полный прогон PHPUnit не перезапускался: последний результат —
`OK (557 tests, 1659 assertions)`. Для документационного прохода этого
достаточно; новые тесты не добавлялись, поэтому счётчик в `docs/PROJECT.md (раздел 1)`
не обновлялся.

---

### 19. DEV-153 (2026-09-30): повторная проверка 28 открытых карточек

#### 19.1 Метод и рамки

Проверены **все 28 физически открытых карточек** из §18.1. Для каждой —
сверка с исходниками (`wp-content`, ветка `dev/phpcs-baseline`), а не только с
текстом карточки: `git log` по спорным файлам, поиск по коду и по baseline.

Рамки: **код не менялся**, новые тесты не добавлялись, поэтому счётчик тестов
в `docs/PROJECT.md (раздел 1)` не обновлялся. Ни одна существующая секция не
переписывалась — этот раздел добавлен в конец файла, поэтому номера строк в
колонке «Карточка» (заголовки `### DEV-NN.`) остаются актуальными.

#### 19.2 Вердикты по всем 28 карточкам

| Карточка | Стр. | Вердикт | Основание (проверено в коде) |
|---|---:|---|---|
| `DEV-02` | 88 | **ОТКРЫТО** — блокер вне кода | `build_api_url()` есть (`class-ticket-sync.php:227-243`) и корректно читает `IDPG_TICKET_API_URL`; без константы работает заглушка `api.example.com` с отказом в лог. Парсер реального формата агрегатора и приёмка невозможны без доступа владельца к API (то же, что `DEV-29` п. 4) |
| `DEV-09` | 520 | **ОТКРЫТО** — решение владельца + чистка 2 документов | Вариант А ещё не выполнен: `class-banners.php` отсутствует, но `plugins/idpg-core/README.md:118` и `docs/archive/docs/archive/PLUGIN_TZ.md:28,408` описывают его как существующий. Решение (вариант А) уже зафиксировано в `docs/PROJECT.md (раздел 1):488`; осталось вычистить два документа |
| `DEV-10` (копия 1) | 531 | **ЗАКРЫТО** — дубль | Полностью дублирует второй блок `DEV-10` (стр. 2644). Канонической считается копия 2; текст копии 1 сохранён как есть, удаление запрещено |
| `DEV-12` | 564 | **ЗАКРЫТО** (01.10.2026) | `class-organizer-meta.php` создан, `class-organizer-url.php` расширен, форма и REST работают, тесты +10 |
| `DEV-13` | 574 | **СНЯТО** (01.10.2026) | Организатор не имеет доступа к админке (`restrict_admin_access()` → `/organizer/`), панель в админке не нужна |
| `DEV-15` | 1026 | **выполнена** | `get_logo_url()` в `class-auth-emails.php` через `validate_logo_url()` проверяет, что файл реально отдаётся по HTTP 200, и без `<img>` не ломает вёрстку письма. Фильтр `idpg_email_logo_url` сохранён. Тесты: `tests/test-email-logo.php` (10 тестов) |
| `DEV-16` | 1036 | **ОТКРЫТО** — решение владельца | `class-wp-auth-ru-updater.php` существует, но `plugins/idpg-auth/idpg-auth.php:29-36` его не подключает, инициализатора нет; 18 записей этого файла лежат в `phpcs-baseline.neon`. Нужно либо подключить, либо удалить вместе с baseline |
| `DEV-17` | 1050 | **выполнена** | Учёт клика подключён: `IDPG_Views::increment_clicks()` вызывается из `IDPG_REST_API::track_click()` (маршрут `POST /idpg/v1/track/click`, `class-rest-api.php`). Frontend `assets/js/ticket-clicks.js` шлёт `fetch` с `keepalive: true` и заголовком `X-WP-Nonce`; fallback через `navigator.sendBeacon` передаёт nonce параметром `_wpnonce` в URL, который принимает `can_track_click()`. Тесты: `tests/test-ticket-clicks.php` (18 тестов, 35 assertions), включая nonce из URL, из заголовка и отказ при невалидном nonce. |
| `DEV-18` | 1060 | **выполнена** | `IDPG_Ticket_Sync::run_sync()` сохранён void-совместимым для cron, `run_sync_counting()` возвращает число событий, `run_sync_now()` возвращает `int`. Ручной запуск доступен через WP-CLI `wp idpg sync-tickets` (`class-cli.php:229`). Тесты: `tests/test-ticket-sync.php` (15 тестов, 22 assertions). |
| `DEV-19` | 1070 | **выполнена** | Добавлены подтверждения выбора города: `IDPG_City_Filter::mark_city_confirmed()` / `is_city_confirmed()` / `set_chosen_city()` / `needs_city_confirmation()` (отдельная cookie `idpg_city_confirmed`, автоопределение по IP подтверждения не ставит), `maybe_confirm_city_from_link()` на `template_redirect` с `wp_nonce_url`, маршрут `POST /idpg/v1/city/confirm` (`can_select_city`), общий renderer `IDPG_City_Selector_Widget::render_confirmation_bar()` для виджета и шапки (panel + modal), JS-подтверждение без перезагрузки через `idpgData.restNonce`, стили `.idpg-city-confirm*`. **Правка от 30.09.2026:** блок выводится в трёх местах страницы (модалка шапки, панель шапки, виджет) и раньше печатал одинаковые `id` трижды, а `navigation.js` цеплял обработчики по `getElementById` — клик во втором и третьем экземпляре ничего не делал. Теперь `render_confirmation_bar()` добавляет суффикс экземпляра (`-2`, `-3`; первому остаются базовые id), а `navigation.js` вешает обработчики на все экземпляры через `document.querySelectorAll('.idpg-city-confirm')`. Тесты: `tests/test-city-confirmation.php` (22 теста, 50 assertions), включая `test_confirmation_bar_ids_are_unique` и `test_navigation_js_binds_all_confirmation_bars_by_class`; обе регрессии проверены мутацией (сломанный суффикс и возврат к `getElementById` дают падение). |
| `DEV-20` | 1080 | **ОТКРЫТО** | Ни `docs/openapi.yaml`, ни `docs/postman_collection.json` в репозитории нет |
| `DEV-21` | 1090 | **ОТКРЫТО** — решение владельца | `assets/js/vkid-onetap.js` отсутствует (в `assets/js` 10 файлов, OneTap среди них нет); OneTap был описан текстом в README и `CHANGELOG.md`, но в коде его нет. Фактическая авторизация — кнопочный OAuth (`class-wp-auth-ru-vk.php`). `DEV-28` (30.09.2026) пометил упоминания OneTap в документации как нереализованные, но сама фича не появилась — карточка остаётся открытой. |
| `DEV-22` | 1103 | **выполнена** | Создан `IDPG_Helpers` (`class-helpers.php`): `get_client_ip()` (с отклонением `HTTP_CLIENT_IP` для публичных соединений) и `get_city_tree()`, константы и общие геттеры билетных meta. `get_client_ip()` в `class-auth-rate-limiter.php` и `class-views.php` теперь делегируют helper. Тесты: `tests/test-helpers.php` (18 тестов, 53 assertions). |
| `DEV-24` | 1130 | **ЗАКРЫТО** | Единственный источник — плагин: `idpg-core.php:32,44-49` (`Carbon_Fields::boot()` под `function_exists`). Бутстрап темы закомментирован (`functions.php:112-129`), `vendor/` в теме нет, `composer.json:6` фиксирует `htmlburger/carbon-fields` |
| `DEV-25` | 1140 | **ЗАКРЫТО** | Решение зафиксировано в `docs/PROJECT.md (раздел 1):516` («вспомогательный helper-слой»). Класс не мёртвый: 26 использований по кодовой базе, есть `tests/test-template-helper.php` |
| `DEV-26` | 1149 | **ОТКРЫТО** | Скрипт создан (`package.json` → `check:css` + `bin/check-css-build.mjs`), но шага в `.circleci/config.yml` нет — в job только `phpunit` и `phpcs`. Остаток упирается в DEV-136 |
| `DEV-27` | 1160 | **ОТКРЫТО** — частично | Оба job определены и вызываются из workflow, но пункт «покрыть тестами DEV-01, DEV-04, DEV-05, DEV-17» не выполним для `DEV-17` (механики нет), а «зелёный CI на текущем коде» удалённо не подтверждён: push в `main` отклонён (DEV-53), ветка `dev/phpcs-baseline` |
| `DEV-27B` | 1201 | **ОТКРЫТО** — прогресса нет | Baseline совпадает со снимком 19.09.2026: **528 / 424 / 398** записей для `idpg-core` / `idpg-auth` / `idpg_core`. Новых проходов после 19.09 в журнале карточки нет |
| `DEV-32` | 1763 | **ЗАКРЫТО** — премисса устранена | Дефект был в том, что роль `subscriber` **не зарегистрирована** (постановка от 15.09.2026). С 28.09.2026 она зарегистрирована: `docs/PROJECT.md (раздел 1):728` (G12) — `default_role = subscriber`, проверено на живом сайте, есть тест `tests/test-default-role.php`. Теперь `set_role( 'subscriber' )` (`class-rest-auth.php:535`, `page-login.php:242`) пишет существующую роль, приёмка выполнена; обход через `IDPG_Organizer_Verify` больше не нужен |
| `DEV-33` | 1776 | **выполнена** | `check_registration_attempt()` в `class-rest-auth.php` вызывается после проверки e-mail, данных организатора и согласия, а не до них, — лимит регистрации больше не расходуется на попытки, не создавшие пользователя. Тесты: `Test_Register_Organizer_Only` (18 тестов, 86 assertions). |
| `DEV-28` | 1796 | **выполнена** | Оба README плагина и темы и `DOCUMENTATION.md` приведены в соответствие с кодом: убраны ссылки на несуществующие файлы (`class-banners.php`, `social-auth/`, `js/vkid-onetap.js`, `banners.css`, `lk.js`, `audit-*.php`, `vendor/` темы), баннеры описаны как запрос/подтверждение на событии с позициями `hero`/`middle`/`bottom`, соц-авторизация отнесена к плагину `idpg-auth` (OneTap помечен как нереализованный, `DEV-21`), REST-маршруты синхронизированы с `class-rest-api.php`/`class-rest-auth.php` (добавлены `/event-dates`, `/search/live`, `/search/popular`, `/subscribe`, `/track/click`, `/city/confirm`; удалённый `/user/*` убран), `page-lk.php` заменён на `page-favorites.php`, имена виджетов исправлены на реальные (`IDPG_Ad_Ru_Widget`, `IDPG_Recent_Events_Widget`, `IDPG_Subscribe_Widget`). |
| `DEV-29` | 1805 | **ОТКРЫТО** (DEV-148, без изменений) | п. 4 (`IDPG_TICKET_API_URL`) и п. 8 (`themes/idpg_core/node_modules`) — вне кода. **Уточнение DEV-153:** п. 6 числится закрытым, но guard на отсутствие файла логотипа в `get_logo_url()` не сделан — см. `DEV-15`. Уточнение DEV-15 (30.09.2026): guard сделан и покрыт тестом, п. 6 закрыт по-настоящему; на статус карточки `DEV-29` это не влияет — остаются п. 4 и п. 8 |
| `DEV-30` | 1844 | **ОТКРЫТО** | Контентная задача (площадки/города/иконки). В репозитории не проверяется, проверяется только на живой БД |
| `DEV-10` (копия 2) | 2644 | **ЗАКРЫТО** | Реализация подтверждена: `class-promotion.php` (`:31` `CRON_HOOK`, `:38/:45` мета, `:91` хук, `:160` `is_active()`, `:177` `schedule_unpin()`), Carbon-поле `idpg_pin_expires` (`class-meta.php:325,554`), инициализация (`idpg-core.php:166`), тест `tests/test-promotion.php`. Приёмка карточки выполнена; остаток «продвижение в выдаче» — продуктовое решение G10, а не блокер |
| `DEV-88` | 2712 | **ЗАКРЫТО** | Всё на месте в `class-privacy.php`: `META_WITHDRAWAL` (`:89`), хук `template_redirect` (`:190`), `withdraw_consent()` (`:263`), `is_withdrawn()` (`:295`), `get_withdrawal()` (`:307`), `handle_subject_withdraw()` (`:648`), блок ссылки в письме `render_withdraw_block()` (`:814`). Тест `tests/test-privacy-withdrawal.php` есть. Отсутствие разлогина осознанно вынесено самой карточкой в отдельную задачу |
| `DEV-89` | 2772 | **выполнена** | п. 1 подтверждён: **0** хардкод-цветов в `themes/idpg_core/assets/scss/pages/_organizer.scss`. п. 2 подтверждён: шапка-предупреждение об изоляции в `base/_variables.scss`. п. 3 выполнен: `lint:scss` в `package.json` переведён на двойные кавычки, добавлены `stylelint ^13.13.1` и `stylelint-config-recommended-scss ^4.3.0`; `npm.cmd run lint:scss` проходит с кодом 0 |
| `DEV-92` | 2936 | **выполнена** | `plugins/idpg-core/bin/install-wp-tests.cmd` и `run-tests.cmd` переведены в CRLF; инвертированное условие в `install-wp-tests.cmd:67` исправлено (`if not exist "%WP_CORE_DIR%\wp-includes\version.php" goto :have_core`). Контрольный запуск `run-tests.cmd` — `exit=0` |

#### 19.3 Что закрыто DEV-153

| Карточка | Почему закрыта |
|---|---|
| `DEV-10` (обе копии) | Реализация подтверждена; копия 1 закрыта как дубль (см. 19.5) |
| `DEV-24` | Единственный источник Carbon Fields — плагин, тема дубль не грузит |
| `DEV-25` | Решение зафиксировано в `docs/PROJECT.md (раздел 1):516`, класс реально используется |
| `DEV-32` | Премисса снята: роль `subscriber` зарегистрирована (G12, 28.09.2026) |
| `DEV-88` | Код, хуки, блок в письме и тест на месте; разлогин вынесен карточкой в отдельную задачу |

**Новый счётчик: 56 закрыто / 2 снято / 1 не задача / 22 открыто** (было
50 / 2 / 1 / 28). Всего 81 заголовок, 80 уникальных ID: из 56 закрытых блоков
**55 уникальных ID** (`DEV-10` учтён один раз), открытых — 22.

#### 19.4 Остатки внутри открытых карточек

| Карточка | Что осталось сделать |
|---|---|
| `DEV-15` | ~~Проверка существования файла логотипа в `get_logo_url()`~~ — **сделано 30.09.2026, см. §20** |
| `DEV-27` | Тесты для `DEV-17` написаны (18 тестов, `tests/test-ticket-clicks.php`); нерешённым остаётся только зелёный CI — после DEV-136 |
| `DEV-27B` | Новые проходы чистки baseline (сейчас цифры те же, что 19.09.2026) |
| `DEV-89` | ~~п. 3~~ — **выполнено 30.09.2026**: `lint:scss` на двойных кавычках, `stylelint ^13.13.1` + `stylelint-config-recommended-scss ^4.3.0` в `devDependencies`, проход `exit=0` |
| `DEV-92` | ~~CRLF и условие в `install-wp-tests.cmd:67`~~ — **выполнено 30.09.2026**, контрольный запуск `exit=0` |

#### 19.5 Разбор дубля `DEV-10`

`DEV-10` встречается дважды: стр. 531 (краткая постановка) и стр. 2644
(постановка + журнал реализации). Канонической считается **копия 2** — в ней
есть приёмка и история работ, и её требования подтверждены кодом (см. 19.2).
Копия 1 закрыта как дубль и оставлена в файле без изменений: удалять её нельзя
(дубль зафиксирован ещё в §18.2), а переписывать чужой текст мы не будем.

**Итог по дублям:** 81 заголовок / 80 уникальных ID; дублей, требующих
внимания, больше не осталось.

#### 19.6 Уточнение по `DEV-93`

Указание «обновить DEV-93 (задача) — не путать с DEV-93 (IP-лимит)» относится к
**единственной** карточке `DEV-93` в этом документе — она и есть IP-лимит
(`DEV-93 (= DEV-116, формально DEV-145)`). Других упоминаний `DEV-93`, не
связанных с этим лимитом, в бэклоге нет: все вхождения (стр. 2999 — заголовок
карточки, 3689 — раздел решений с `RESET_IP_LIMIT` / `RESET_IP_WINDOW` /
`check_password_reset_ip_attempt()`, 4174 / 4196 / 4217 — таблица
соответствий, 4560 — сводка) говорят о том же лимите, который закрыт в рамках
`DEV-116`.

**Вывод:** отдельной «задачи DEV-93» не существует, новую карточку не
заводим. Уточнение зафиксировано здесь, чтобы при следующем проходе её снова
не искали.

#### 19.7 Блокеры вне нашего кода (статус не меняем)

| Блокер | Затрагивает |
|---|---|
| Доступ к реальному API агрегатора и его документации | `DEV-02`, `DEV-29` п. 4 |
| Решение владельца: поддержать или снять | `DEV-09`, `DEV-16`, `DEV-21` |
| Привязка CircleCI к репозиторию (push в `main` отклонён) | `DEV-26`, `DEV-27` |
| Контент и живая БД | `DEV-30` |
| События 693-695 (`rest_post_invalid_id` / `rest_no_route`) и ошибка CPT-инструмента `MCP error -32602 ... received 'desc' at order` | как в DEV-148 |

#### 19.8 Изменённые файлы

| Файл | Изменение |
|---|---|
| `docs/PROJECT.md (раздел 2)` | добавлен §19 (эта секция); существующие секции не тронуты |

#### 19.9 Журнал DEV-153

| Дата | Что сделано |
|---|---|
| 2026-09-30 | Повторно проверены все 28 открытых карточек по исходникам. Закрыто 6 блоков (5 уникальных ID: `DEV-10` ×2, `DEV-24`, `DEV-25`, `DEV-32`, `DEV-88`), открытыми остались 22. Разобран дубль `DEV-10`, зафиксировано уточнение по `DEV-93`. Счётчик: **56 / 2 / 1 / 22** (81 заголовок, 80 уникальных ID). Код не менялся, новых тестов нет — счётчик тестов в `docs/PROJECT.md (раздел 1)` не обновлялся |

Итог: 6 новых закрытых, 0 снятых, 0 «не задача»; открытых осталось **22** из 28.

---

### 20. DEV-15 (2026-09-30): проверка существования файла логотипа в письмах

#### 20.1 Что было

`IDPG_Auth_Emails::get_logo_url()` (`class-auth-emails.php`) отдавала значение
фильтра `idpg_email_logo_url` как есть, не сверяясь с файловой системой:

- логотип лежит в `uploads/` — картинка работала;
- логотип удалили, заменили или перенесли — письма продолжали уходить со
  `<img src="...email-logo.png">`, то есть **битой картинкой**.

Письма при этом уходили в статусе «доставлено», поэтому дефект был виден только
получателю. Вторая часть приёмки карточки — «при отсутствии файла письмо
рендерится без разрыва вёрстки» — кодом не гарантировалась.

Оба шаблона писем (`render_email_template()`, `render_organizer_email_template()`)
уже умели показывать текстовую замену при пустом URL, то есть не хватало только
самой проверки: пустой URL → готовый fallback.

#### 20.2 Что сделано

Изменение аддитивное: сигнатура `get_logo_url()` не тронута, фильтр и его имя
сохранены, кэширование в `$logo_url` продолжает работать. Добавлены два приватных
метода:

| Метод | Назначение |
|---|---|
| `validate_logo_url( $url )` | Точка входа проверки: пустая строка остаётся пустой, внешний адрес отдаётся как есть, локальный — сверяется с диском |
| `local_path_from_url( $url )` | Переводит URL в путь ФС, если он принадлежит нашему `uploads`; иначе возвращает `''` |

Ключевое решение — **проверяются только локальные адреса**. Фильтр может вернуть
URL на CDN или стороннее хранилище: такого файла на диске нет по определению, но
картинка работает, поэтому отсекать его нельзя. Сопоставление идёт с базовым
URL загрузок из `wp_get_upload_dir()`, а не с предположением про `home_url()`, —
WordPress не обязан отдавать `uploads` по `home_url()`.

> Грабли, найденные при первой реализации: `wp_get_upload_dir()['baseurl']` может
> вернуться **без слеша на конце**, поэтому наивная склейка
> `basedir . $relative` давала путь `uploadsemail-logo.png`. Лечится
> `trailingslashit()` перед склейкой. Это и был источник первого падения тестов.

Шаблоны не менялись — их fallback-ветки уже удовлетворяли приёмке.

#### 20.3 Тесты

`tests/test-email-logo.php` — **10 тестов / 19 утверждений**, полностью зелёные.
Файл новый, в baseline не попадает и легаси-ошибок не добавляет
(0 ошибок / 0 предупреждений по проектному `.phpcs.xml.dist`).

| Сценарий | Тест |
|---|---|
| Файл существует — URL отдаётся | `test_existing_logo_returns_url` |
| Файла нет — возвращается `''` | `test_missing_logo_returns_empty_string` |
| Фильтр подставляет свой логотип | `test_filter_overrides_logo_url` |
| Фильтр отдаёт внешний URL — не отсекается | `test_external_url_from_filter_is_not_discarded` |
| Фильтр отдаёт URL удалённого файла — `''` | `test_filter_url_with_deleted_file_yields_empty` |
| Пустой URL → в шаблоне нет `<img>` | `test_template_has_no_img_when_logo_missing` |
| Файл есть → в шаблоне есть `<img>` | `test_template_has_img_when_logo_exists` |
| То же для приветственного шаблона (нет `<img>`) | `test_organizer_template_has_no_img_when_logo_missing` |
| То же для приветственного шаблона (есть `<img>`) | `test_organizer_template_has_img_when_logo_exists` |
| Вёрстка не разъезжается, нет `src=""` | `test_layout_survives_missing_logo` |

Два шаблона проверяются отдельно намеренно: правка только в одном из них дала бы
дефект, который тесты бы не поймали.

**Обратная проба.** На старом коде (`return $this->logo_url;` вместо проверки)
тесты дают **5 падений из 10** — то есть они действительно ловят исходный дефект,
а не просто повторяют новое поведение.

#### 20.4 Проверки

| Проверка | Результат |
|---|---|
| `phpunit --filter Test_Email_Logo` | OK (10 тестов, 19 утверждений) |
| Полный прогон `vendor/bin/phpunit -c phpunit.xml.dist` | **OK (567 тестов, 1678 утверждений)**, падений 0 |
| `php -l` по обоим файлам | Синтаксических ошибок нет |
| PHPCS (проектный `.phpcs.xml.dist`) по новому тесту | 0 ошибок / 0 предупреждений |
| PHPCS по `class-auth-emails.php` | 9 ошибок / 3 предупреждения — **было 11 / 3**, все оставшиеся на существующих строках; новых нарушений правка не добавила |

#### 20.5 Изменённые файлы

| Файл | Изменение |
|---|---|
| `plugins/idpg-core/includes/class-auth-emails.php` | Добавлены `validate_logo_url()` и `local_path_from_url()`; `get_logo_url()` пропускает значение через проверку. Существующие методы и фильтр не тронуты |
| `plugins/idpg-core/tests/test-email-logo.php` | Новый файл, 10 тестов |
| `docs/PROJECT.md` | Карточка `DEV-15` (статус), п. 6 `DEV-29`, уточнения в §19, этот раздел §20 |

#### 20.6 Обновлённый счётчик

После закрытия `DEV-15` показатель §19.3 меняется на **57 закрыто / 2 снято /
1 не задача / 21 открыто** (было 56 / 2 / 1 / 22). Из 57 закрытых блоков —
**56 уникальных ID** (`DEV-10` по-прежнему учтён один раз).

#### 20.7 Журнал

| Дата | Что сделано |
|---|---|
| 2026-09-30 | Guard на существование локального файла логотипа добавлен в `get_logo_url()`; 10 тестов; полный прогон зелёный (567/1678); обратная проба подтверждает, что тесты ловят исходный дефект. Приёмка `DEV-15` выполнена, п. 6 `DEV-29` закрыт по-настоящему |
