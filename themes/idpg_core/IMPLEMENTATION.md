# Реализованные задачи в проекте idpg_core

## 1. Carbon Fields миграция (class-meta.php)

- Замена нативных metabox на Carbon Fields
- 3 контейнера: детали события, билеты, данные площадки
- Поля без префикса `_` (Carbon добавляет автоматически)
- Формат хранения дат: `Y-m-d\TH:i`
- Сантизация URL (поле `ticket_url`) через фильтр `sanitize`
- Сантизация числовых полей через фильтр `sanitize`
- Ленивые опции площадок через `lazy_options` callback

## 2. Удаление организаторского кабинета и визарда

### Удалённые файлы темы:
- `page-organizer.php`
- `page-dashboard.php`
- `page-create-event.php`
- `page-favorites.php`
- `page-cabinet.php`

### Удалённые JS файлы:
- `assets/js/create-event.js`
- `assets/js/dashboard.js`
- `assets/js/cabinet.js`

### Удалённые SCSS файлы:
- `assets/scss/pages/_create-event.scss`
- `assets/scss/pages/_dashboard.scss`
- `assets/scss/pages/_cabinet.scss`

### Очищенные файлы:
- `style.scss` — убраны imports удалённых SCSS файлов
- `functions.php` — убраны enqueue блоки для удалённых JS файлов
- `__diag.php` — убраны cabinet-случаи и селекторы
- `README.md` — убраны ссылки на удалённые файлы и компоненты

## 3. REST API очистка (class-rest-api.php)

### Удалённые эндпоинты:
- `/events/create` (POST)
- `/events/{id}` (PUT)
- `/organizer/stats` (GET)
- `/organizer/events` (GET)
- `/user/favorites` (GET, POST, DELETE)
- `/user/data` (GET)
- `/user/change-password` (POST)

### Удалённые методы:
- `create_event`
- `update_event`
- `get_organizer_stats`
- `get_organizer_events`
- `format_organizer_event`
- `get_user_favorites`
- `add_user_favorite`
- `remove_user_favorite`
- `get_user_data`
- `can_manage_specific_event`
- `is_event_organizer`
- `save_event_meta`
- `handle_categories`
- `handle_city`
- `handle_age_rating`
- `handle_thumbnail`
- `get_event_schema`

### Оставшиеся эндпоинты:
- `GET /events` — публичный список с фильтрацией
- `GET /venues` — публичный список площадок
- `GET /venues/{id}` — одна площадка
- `POST /venues/create` — создание площадки
- `PUT /venues/{id}` — редактирование площадки
- `GET /cities` — список городов
- `GET /categories` — список категорий событий
- `GET /age-ratings` — список возрастных рейтингов

### Добавлен `can_manage_events` permission callback для маршрутов площадок.

## 4. Авторизация и роли

### class-auth.php:
- Удалены константы `DASHBOARD_URL`, `FAVORITES_URL`
- Оставлена только константа `LOGIN_URL`
- Упрощена логика редиректа после входа (всегда на главную)
- Упрощён `restrict_admin_access()` (без редиректа на кабинеты)
- Упрощён `redirect_if_logged_in()` (редирект на главную)
- Удалён метод `get_dashboard_url()`

### class-rest-auth.php:
- Удалены ветки организатора/подписчика в `get_redirect_url_by_role()` и `get_user_role()`
- Восстановлена валидация полей организатора при регистрации
- Восстановлено назначение роли `event_organizer` при регистрации
- Сохраняются мета-поля организатора (`idpg_org_name`, `idpg_contact_name`, `idpg_phone`)

### class-roles.php:
- Восстановлена роль `event_organizer` с capabilities для CPT event
- Добавлены `delete_published_events` и `delete_private_events` с версионной миграцией (`ROLE_VERSION = 2`)
- Восстановлены методы `create_roles()`, `sync_role_caps()`, `remove_roles()`

### class-auth-emails.php:
- Упрощено приветственное письмо (без различий по ролям)

## 5. Фронтенд

### page-login.php:
- Возвращена форма "Я организатор" с табами:
  - Вход
  - Регистрация пользователя
  - Регистрация организатора
- Поля формы организатора:
  - Название организации (`org_name`)
  - ФИО представителя (`contact_name`)
  - Email (`email`)
  - Телефон (`phone`)
  - Пароль (`password`)
  - Подтверждение пароля (`password_confirm`)
  - Чекбокс согласия с офертой и политикой конфиденциальности

### header.php:
- Ссылка "События" для админов/редакторов
- Ссылки в выпадающем меню:
  - Панель для не-подписчиков
  - Вход/Регистрация для гостей

## 6. Очистка конфигурации

### README.md:
- Убраны ссылки на удалённые файлы и компоненты
- Обновлены статусы компонентов
- Убраны ссылки на `/cabinet/favorites/`, `/organizer/dashboard/`, `/create-event/`

### style.scss:
- Убраны imports `_create-event.scss`, `_dashboard.scss`, `_cabinet.scss`

### functions.php:
- Убраны enqueue блоки для `create-event.js`, `dashboard.js`, `cabinet.js`

### __diag.php:
- Убраны cabinet-случаи и селекторы

## 7. Фронтенд ЛК для подписчиков

### page-lk.php:
- Создан шаблон личного кабинета для простых пользователей (subscriber)
- Доступен только авторизованным подписчикам
- Организаторы и администраторы перенаправляются на главную
- Отображает профиль пользователя, статистику, кнопку редактирования профиля
- Inline-форма редактирования профиля (имя, email, пароль)
- Корректная стилизация с отступами между элементами
- XMLHttpRequest вместо fetch для надёжной отправки куки авторизации WordPress
- JavaScript для переключения режима редактирования и отправки формы через REST API

### class-auth.php:
- Добавлена константа `LK_URL = '/lk/'`
- `restrict_admin_access()` перенаправляет подписчиков на фронтенд ЛК
- `redirect_after_login()` перенаправляет подписчиков на ЛК после входа

### class-rest-auth.php:
- `get_redirect_url_by_role()` возвращает `/lk/` для подписчиков
- `handle_login()` и `handle_register()` используют `get_redirect_url_by_role()` для редиректа

### page-login.php:
- Авторизованные подписчики перенаправляются на `/lk/` вместо главной

### header.php:
- Добавлена ссылка "Личный кабинет" для подписчиков в выпадающем меню
- Ссылка "Панель" заменена на "Личный кабинет" для подписчиков

### class-rest-api.php:
- Добавлены эндпоинты для ЛК:
  - `GET /user/profile` — данные профиля текущего пользователя
  - `PUT /user/profile` — обновление профиля (имя, email, пароль)
  - `GET /user/stats` — статистика пользователя
- Добавлен `can_access_lk()` permission callback (только подписчики)
- Поддержка смены пароля через `new_password` параметр

### idpg-core.php:
- Добавлен фильтр `rest_authentication_errors` для обхода проверки куки для LK-эндпоинтов

### functions.php:
- Автосоздание страницы `/lk/` с шаблоном `page-lk.php`
- Автосоздание страницы `/login/` с шаблоном `page-login.php`

### README.md:
- Добавлен статус ЛК для подписчиков
- Добавлен `page-lk.php` в структуру файлов

## 8. Автосоздание страниц

### functions.php:
- Добавлена функция `idpg_core_create_lk_page()` — автоматически создаёт страницу `/lk/` с шаблоном `page-lk.php`
- Добавлена функция `idpg_core_create_login_page()` — автоматически создаёт страницу `/login/` с шаблоном `page-login.php`
- Обе функции хукаются на `after_setup_theme`
- Проверяют существование страницы перед созданием
- Устанавливают правильный шаблон через `_wp_page_template` meta

## 9. Автосоздание страниц /terms/ и /privacy/

### functions.php:
- Добавлена функция `idpg_core_create_terms_page()` — страница `/terms/` с шаблоном `page-terms.php`
- Добавлена функция `idpg_core_create_privacy_page()` — страница `/privacy/` с шаблоном `page-privacy.php`
- Предзаполнены дефолтными текстами (оферта и политика конфиденциальности)
- DEV-309: `idpg_core_create_privacy_page()` привязывает страницу как «Политика конфиденциальности» сайта (`wp_page_for_privacy_policy`), если опция ещё не задана — ссылки в футере/формах и в «Настройки → Конфиденциальность» ведут на `/privacy/`

## 10. Безопасность и аутентификация

### class-rest-api.php:
- Добавлена проверка `wp_verify_nonce()` в `can_access_lk()` для POST/PUT запросов
- Добавлена проверка `current_user_can('upload_files')` для аватара
- Добавлена проверка текущего пароля при смене через `wp_check_password()`
- Добавлено `wp_destroy_other_sessions()` при смене пароля

### class-rest-auth.php:
- Серверная проверка чекбокса оферты (`terms`) при регистрации

### auth.js:
- Передача `terms` в запросах регистрации

### page-login.php, page-reset-password.php:
- PHP fallback обработки форм для работы без JavaScript

### wp-config.php:
- Уникальные ключи аутентификации (сгенерированы через api.wordpress.org)
- Защита от `Undefined array key` для `REQUEST_SCHEME` и `HTTP_HOST`

### functions.php:
- Убрано дублирование `wp_create_nonce()` (конфликт с логированием)

## 11. Стилизация и шрифты

### theme.json:
- Шрифт Gilroy подключён через `@font-face` с абсолютным URL
- `font-family` установлен для `body`, `heading`, `button` и `input` через CSS Custom Properties

### header.php:
- Удалены дублирующие шрифтовые `@font-face` блоки

## 12. Деплой

### deploy.sh:
- Копирование файлов через tar+ssh на Hostland (serv68.hostland.ru)
- Исключает `node_modules/`, `.git/`, временные файлы
- НЕ копирует БД или wp-config.php (серверная настройка отдельно)

## 13. Удаление личного кабинета (2026-09-05)

### Удалённые REST-эндпоинты (class-rest-api.php):
- `GET/PUT /user/profile`, `GET /user/stats`, `POST /user/avatar`, `GET /user/city-events`
- Permission-колбэк `can_access_lk()` и методы `get_user_profile()`, `update_user_profile()`, `upload_user_avatar()`, `get_user_stats()`, `get_user_city_events()`

### Удалённые файлы темы:
- `page-lk.php`
- `assets/js/lk.js`
- `assets/css/lk.css`
- `page-favorites.php` **сохранён** — избранное остаётся (страница `/lk/favorites/` работает через rewrite плагина, без страницы в БД)

### Внесённые изменения:
- `functions.php` — убраны enqueue `idpg_core-lk` и функция `idpg_core_create_lk_page()` (автосоздание `/lk/`)
- `class-auth.php` — убрана константа `LK_URL`; `redirect_after_login()` и `restrict_admin_access()` для подписчиков направляют на главную `/`
- `class-rest-auth.php` — `get_redirect_url_by_role()` возвращает подписчикам главную `/`
- `class-social-user.php` и `class-social-auth.php` — подписчики после соц-входа на главной `/`
- `page-login.php` — форма входа перенаправляет подписчиков на главную `/`

### База данных:
- Удалена страница `/lk/` (ID 110) с postmeta (`wp_delete_post(110, true)`); страниц `favorites`/`cabinet` в БД не было