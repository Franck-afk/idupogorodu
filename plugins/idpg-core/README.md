# IDPG Core

Ядро сайта-афиши «Иду по городу»: типы записей событий и площадок, мета-поля на Carbon Fields, многогородская фильтрация, социальная авторизация (VK ID / Яндекс ID), REST API, интеграции с агрегаторами билетов.

Плагин содержит всю бизнес-логику проекта; тема `idpg_core` отвечает только за представление.

---

## Требования

| Компонент  | Версия                |
|------------|-----------------------|
| WordPress  | 6.4+                  |
| PHP        | 8.2+                  |
| Зависимости | Carbon Fields 3.6 (ставится через Composer) |

---

## Установка

1. Скопируйте папку плагина в `wp-content/plugins/idpg-core/`.
2. Если в папке нет `vendor/`, выполните в корне плагина:
   ```bash
   composer install --no-dev
   ```
3. Активируйте плагин «IDPG Core» в **Плагины → Установленные**.
4. При активации автоматически: регистрируются CPT и таксономии, создаются роли, ставится cron-задача синхронизации билетов, выполняются миграции меты, сбрасываются rewrite-правила.

Автосоздание страниц (`/login/`, `/reset-password/`, `/terms/`, `/privacy/`) выполняется темой; избранное доступно по `/lk/favorites/` (rewrite плагина, страницы в БД нет).

---

## Основные функции

### Типы записей и таксономии

- **События** (`event`, slug `/afisha/`) — рубрики, возрастные рейтинги, города.
- **Площадки** (`venue`) — с привязкой к городу.
- **Города** (`city`) — иерархическая таксономия: область → город → район.

### Мета-поля (Carbon Fields)

- Детали события: даты и сеансы, статус билетов, тип продажи (внешняя ссылка / виджет), цены, площадка.
- Данные площадки: адрес, контакты, соцсети.
- Единый префикс меты `_idpg_`, значения доступны в REST API.

### Многогородская механика

- Город хранится в cookie (30 дней), архивы автоматически фильтруются.
- Город в URL (DVHAB-стиль): `/afisha/{city}/`, `/afisha/{city}/events/{slug}/`, `/venues/{city}/`.
- Виджет «Выбор города» с автоопределением по IP.

### Социальная авторизация

- **VK ID** — OAuth 2.1 Authorization Code + PKCE (`id.vk.com`), вход через VK / OK / Mail.ru.
- **Яндекс ID** — OAuth 2.0.
- **OneTap-виджет VK ID** на `/login/` (`@vkid/sdk`, режим редиректа в той же вкладке).
- Поиск/создание пользователя, привязка провайдера, загрузка аватара, вход по роли.
- Защита: одноразовые state-токены (CSRF), rate limiter форм входа.

### REST API (namespace `idpg/v1`)

Публичные: `GET /events`, `GET /venues`, `GET /venues/{id}`, `GET /cities`, `GET /categories`, `GET /age-ratings`.
Авторизованные: `POST /venues/create`, `PUT /venues/{id}`, избранное (`/favorites/…`), авторизация (`/auth/…`).

### Прочее

- Избранное (user meta + REST + счётчик), экспорт событий в iCalendar (.ics).
- Счётчики просмотров/кликов событий.
- Баннер текущего события на главной.
- Синхронизация статусов билетов с внешним API (WP-Cron, каждые 3 часа), email-уведомления автору при смене статуса.
- Режим «Сайт в разработке» — заглушка HTTP 503 с настройками.
- Роль `event_organizer` с изоляцией контента (свои вложения в медиатеке, свой список событий).
- Кастомный аватар пользователя (подмена Gravatar на фронтенде).

---

## Настройка

### Социальная авторизация

**Настройки → Социальная авторизация**:

- Включите VK ID, укажите **Client ID** и **Client Secret** из приложения на [id.vk.com](https://id.vk.com).
- В настройках приложения VK ID добавьте **Доверенный Redirect URI** — точно как показано в подсказке на странице настроек (например `https://idupogorodu.ru/auth/callback/vk/`). URI сверяется посимвольно: схема, домен, слэш.
- Вход через OneTap-виджет работает по Client ID (PKCE в браузере); классический вход требует Secret.

**Важно:** вход работает только на домене, привязанном к приложению VK. Локальные домены (`.test`, `localhost`) VK не принимает — ошибка `redirect_uri is missing or invalid`.

### Баннеры

**Баннеры** (пункт главного меню админки):

- Две позиции на главной: `middle` (после первой секции) и `bottom` (перед футером).
- Для баннера: изображение, ссылка, UTM-метки, период показа, приоритет.
- Баннер текущего события отображается автоматически, настроек не требует.

---

## Структура плагина

```
idpg-core/
├── idpg-core.php              # Точка входа: константы, загрузка, activation/deactivation
├── uninstall.php              # Очистка опций/транзиентов/user meta при удалении
├── composer.json              # Зависимости (Carbon Fields) и автозагрузка
├── bin/
│   └── make-pot.js            # Генератор переводов (node bin/make-pot.js .)
├── includes/                  # Все классы (class-*.php)
│   ├── class-cpt.php          # CPT event/venue + таксономии
│   ├── class-meta.php         # Мета-поля (Carbon Fields + REST-мета)
│   ├── class-migrations.php   # Миграции меты (+ WP-CLI: wp idpg migrate-meta)
│   ├── class-city-filter.php  # Фильтрация по городам (cookie)
│   ├── class-city-url.php     # Город в URL
│   ├── class-favorites.php    # Избранное
│   ├── class-views.php        # Счётчики просмотров/кликов
│   ├── class-ical-export.php  # Экспорт .ics
│   ├── class-banners.php      # Система баннеров
│   ├── class-current-event-banner.php
│   ├── class-ticket-sync.php  # Cron-синхронизация билетов
│   ├── class-notifications.php# Email-уведомления о статусах
│   ├── class-auth.php         # Авторизация, редиректы, доступ к админке
│   ├── class-rest-auth.php    # REST-регистрация/вход/сброс пароля
│   ├── class-rest-api.php     # REST API
│   ├── class-roles.php        # Роли и capabilities
│   ├── class-auth-rate-limiter.php
│   ├── class-auth-emails.php  # HTML-письма
│   ├── class-organizer-scope.php # Изоляция контента организатора
│   ├── class-geo-service.php  # Гео-фасад
│   ├── class-maintenance.php  # Режим разработки
│   ├── class-template-helpers.php
│   └── social-auth/           # Модуль соц-авторизации (namespace Idpg\SocialAuth)
│       ├── class-social-auth.php     # Фасад: коллбэки, страница-мост
│       ├── class-social-routes.php   # /auth/{provider}/, /auth/callback/{provider}/
│       ├── class-social-user.php     # Пользователи соц-авторизации
│       ├── class-social-admin.php    # Страница настроек
│       ├── class-social-provider.php # Абстрактный OAuth-провайдер
│       ├── class-provider-vk.php     # VK ID (OAuth 2.1 + PKCE)
│       └── class-provider-yandex.php # Яндекс ID
├── assets/
│   ├── css/                   # Стили по фичам
│   ├── js/                    # Скрипты по фичам (vkid-onetap.js и др.)
│   └── vendor/                # Бандлы (iconify, tom-select)
├── languages/
│   └── idpg-core.pot          # Шаблон переводов (domain: idpg-core)
└── vendor/                    # Composer (Carbon Fields)
```

---

## Changelog

### 1.1.0 — 2026-09-05

- Удалены REST-эндпоинты ЛК (`/user/profile`, `/user/stats`, `/user/avatar`, `/user/city-events`); после входа подписчики направляются на главную `/` вместо `/lk/`.

### 1.0.9 — 2026-09-05

- REST `POST /city/select` для смены города; настройка `ip_service` реально действует; ip-api запрашивается по HTTPS с fallback на HTTP.

### 1.0.8 — 2026-09-01

**Added**
- `uninstall.php` — очистка опций, транзиентов, user meta и cron при удалении плагина; данные событий по умолчанию сохраняются (полная зачистка — константой `IDPG_CORE_UNINSTALL_WIPE_DATA` в wp-config.php).
- `languages/idpg-core.pot` — шаблон переводов (461 строка, домен `idpg-core`).
- `bin/make-pot.js` — генератор POT без внешних зависимостей.
- Подсказка с точным доверенным Redirect URI на странице настроек соц-авторизации.

**Fixed**
- VK ID OneTap: устранена ошибка «New tab has been closed» — в `Config.init()` добавлен `mode: ConfigAuthMode.Redirect` (ключ `mode`, а не `responseMode`); авторизация выполняется редиректом в той же вкладке.
- Страница-мост `/auth/callback/vk/`: конфигурация SDK синхронизирована с виджетом.

### 1.0.7

- Яндекс ID: провайдер и маршрут `/auth/yandex/`; версия rewrite-правил — 5.

### 1.0.6

- OneTap-виджет VK ID на `/login/`; страница-мост для коллбэка (PKCE-обмен в браузере).

### 1.0.5 и ранее

- Ядро: CPT, мета-поля, города, REST API, избранное, баннеры, календарь, iCal, синхронизация билетов, роли, авторизация, режим разработки.

---

## Лицензия

GPL v2 or later. Текст лицензии — [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
