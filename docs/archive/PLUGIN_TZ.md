# ТЕХНИЧЕСКОЕ ЗАДАНИЕ
## Портал-афиша «Иду по городу»

**Версия:** 1.0  
**Дата:** 19.08.2026  
**Стек:** WordPress 6.7, PHP 8.2, Carbon Fields 3.6

---

## 1. ОБЩАЯ ИНФОРМАЦИЯ

Портал афиши событий. Пользователи смотрят события по городам, организаторы создают и управляют событиями через фронтенд-кабинет. Администраторы управляют контентом через WP-админку.

### 1.1 Структура проекта

```
wp-content/
├── plugins/
│   └── idpg-core/          ← Ядро: CPT, таксономии, роли, REST API, Carbon Fields
│       ├── idpg-core.php
│       └── includes/
│           ├── class-cpt.php
│           ├── class-roles.php
│           ├── class-meta.php
│           ├── class-rest-api.php
│           ├── class-auth.php
│           ├── class-city-filter.php
│           ├── class-banners.php
│           └── ...
├── themes/
│   └── idpg_core/          ← Тема: шаблоны, стили, JS, ЛК
│       ├── page-login.php
│       ├── page-lk.php
│       ├── single-event.php
│       ├── archive-event.php
│       ├── taxonomy-city.php
│       └── single-venue.php
```

---

## 2. КОНТЕНТ-ТИПЫ И ТАКСОНОМИИ

### 2.1 CPT «event» (Событие)

| Параметр | Значение |
|---|---|
| Слаг | `event` |
| URL архива | `/afisha/` |
| URL одиночной | `/afisha/<slug>/` |
| Поддержка | title, editor, thumbnail |
| Служба | `class-cpt.php` |

### 2.2 CPT «venue» (Площадка)

| Параметр | Значение |
|---|---|
| Слаг | `venue` |
| URL архива | `/venues/` |
| URL одиночной | `/venues/<slug>/` |
| Поддержка | title, editor, thumbnail |

### 2.3 Таксономии

| Таксономия | Тип | Привязка | Слаг URL |
|---|---|---|---|
| `event_category` | Иерархическая | event | `event-category` |
| `age_rating` | Неиерархическая | event | `age-rating` |
| `city` | Иерархическая (регион → город → район) | event + venue | `gorod/<slug>` |

### 2.4 Дерево городов

Иерархия: **Регион → Город → Район**. Определение города посетителя — по IP через ip-api.com / ipapi.co с кэшем на сутки. Cookie `idpg_selected_city` — выбор пользователя (30 дней).

---

## 3. ПОЛЬЗОВАТЕЛЬСКИЕ РОЛИ

### 3.1 event_organizer (Организатор)

```php
read, upload_files
edit_events, publish_events, delete_events
edit_published_events, delete_published_events, delete_private_events
edit_others_events = false, delete_others_events = false
manage_categories = false, manage_options = false
```

**Доступ:**
- Фронтенд ЛК `/lk/` — **закрыт** (редирект на `/`)
- WP-админка — **разрешён** (проходит через `restrict_admin`)
- После входа — редирект на главную `/`

### 3.2 subscriber (Подписчик)

Доступен фронтенд ЛК `/lk/`. Из профиля: имя, email, пароль, аватар.

### 3.3 administrator / editor

Полный доступ к админке и контенту.

---

## 4. МЕТА-ПОЛЯ СОБЫТИЯ (27 полей)

Все ключи начинаются с `_idpg_`.

### 4.1 Основная информация

| Поле | Тип | Описание |
|---|---|---|
| `_idpg_start_date` | string Y-m-d | Дата начала (обязательное) |
| `_idpg_end_date` | string Y-m-d | Дата окончания (пусто = однодневное) |
| `_idpg_all_day` | checkbox | Весь день |
| `_idpg_sessions_json` | string JSON | Зеркало complex-поля сеансов |

### 4.2 Место проведения

| Поле | Тип | Описание |
|---|---|---|
| `_idpg_venue` | integer | ID площадки (0 = не выбрана) |
| `_idpg_address` | string | Адрес (если отличается от площадки) |
| `_idpg_coordinates_lat` | string | Широта |
| `_idpg_coordinates_lng` | string | Долгота |

### 4.3 Билеты и цены

| Поле | Тип | Описание |
|---|---|---|
| `_idpg_price_from` | string | Цена от |
| `_idpg_price_to` | string | Цена до |
| `_idpg_is_free` | checkbox | Бесплатное |
| `_idpg_vip_price` | string | VIP-цена |
| `_idpg_ticket_status` | select | in_sale / sold_out / cancelled / postponed |
| `_idpg_ticket_type` | select | external_link / widget_yandex |
| `_idpg_ticket_url` | string | URL продажи |
| `_idpg_widget_code` | textarea | Код виджета Яндекс.Билетов |
| `_idpg_currency` | select | RUB / USD / EUR |
| `_idpg_external_id` | string | ID во внешней системе (для синхронизации) |

### 4.4 Медиа и дополнительно

| Поле | Тип | Описание |
|---|---|---|
| `_idpg_video_trailer` | string | Ссылка на трейлер |
| `idpg_gallery` | media_gallery | Галерея изображений (CF) |
| `idpg_sessions` | complex | Сеансы: time / price / url (CF) |
| `_idpg_source` | string | Источник импорта |
| `_idpg_promoted` | checkbox | Закрепить в топе |
| `_idpg_is_online` | checkbox | Онлайн-событие |
| `_idpg_stream_url` | string | Ссылка на трансляцию |
| `_idpg_views` | integer | Счётчик просмотров |
| `_idpg_clicks` | integer | Счётчик кликов |
| `_idpg_hide_from_banner` | checkbox | Скрыть из hero-баннера |
| `_idpg_hide_full_banner` | checkbox | Скрыть баннер полностью |

---

## 5. МЕТА-ПОЛЯ ПЛОЩАДКИ (7 полей)

| Поле | Тип | Описание |
|---|---|---|
| `_idpg_venue_address` | string | Адрес |
| `_idpg_venue_phone` | string | Телефон |
| `_idpg_venue_email` | string | Email |
| `_idpg_venue_website` | string | Сайт |
| `_idpg_venue_lat` | number | Широта |
| `_idpg_venue_lng` | number | Долгота |
| `_idpg_venue_capacity` | integer | Вместимость |

---

## 6. REST API (idpg/v1)

### 6.1 Публичные эндпоинты (без авторизации)

| Метод | Путь | Описание |
|---|---|---|
| GET | `/events` | Список событий: city, category, date_from, date_to, search, page, per_page |
| GET | `/venues` | Список площадок: city, search, page, per_page |
| GET | `/venues/{id}` | Одна площадка |
| GET | `/cities` | Города (taxonomy=city) |
| GET | `/categories` | Категории (taxonomy=event_category) |
| GET | `/age-ratings` | Возрастные рейтинги |

### 6.2 Личный кабинет (авторизация + nonce)

| Метод | Путь | Описание |
|---|---|---|
| GET | `/user/profile` | Данные профиля |
| PUT | `/user/profile` | Обновление: display_name, email, password, avatar |
| GET | `/user/stats` | Статистика: events_count, venues_count |
| POST | `/user/avatar` | Загрузка аватара |
| GET | `/user/city-events` | События в городе пользователя |

### 6.3 Управление (только админ/редактор)

| Метод | Путь | Описание |
|---|---|---|
| POST | `/venues/create` | Создание площадки |
| PUT | `/venues/{id}` | Редактирование площадки |

---

## 7. СТРУКТУРА ФРОНТЕНДА

### 7.1 Страницы

| URL | Шаблон | Описание |
|---|---|---|
| `/` | front-page.php | Главная (баннеры + виджеты) |
| `/afisha/` | archive-event.php | Афиша: фильтры date, is_free |
| `/afisha/<slug>/` | single-event.php | Страница события |
| `/venues/` | archive.php | Список площадок |
| `/venues/<slug>/` | single-venue.php | Страница площадки |
| `/gorod/<slug>/` | taxonomy-city.php | События города |
| `/login/` | page-login.php | Вход / Регистрация |
| `/lk/` | page-lk.php | ЛК подписчика |
| `/terms/` | page-terms.php | Пользовательское соглашение |
| `/privacy/` | page-privacy.php | Политика конфиденциальности |
| `/reset-password/` | page-reset-password.php | Восстановление пароля |

### 7.2 Фильтры афиши (functions.php → idpg_core_pre_get_posts_filters)

- `?date=today` — сегодня
- `?date=tomorrow` — завтра
- `?date=weekend` — ближайшие выходные
- `?date=2026-08-19` — конкретная дата
- `?is_free=1` — бесплатные
- `?city=<term_id>` — через таксономию

### 7.3 Дизайн-система

| Переменная | Значение |
|---|---|
| `--idpg-primary` | `#4d6bfe` |
| `--idpg-text` | `#1e232c` |
| `--idpg-border` | `rgba(0,0,0,.1)` |
| Шрифт | Gilroy |
| `.site-main` padding-top | 88px (под фиксированную шапку) |

---

## 8. УПРАВЛЕНИЕ БАННЕРАМИ

**Страница:** админка → Баннеры (top-level меню, позиция 30)

### 8.1 Средний баннер (между блоками событий)

- Активность (checkbox)
- Изображение (728×90) + мобильное (320×100)
- Ссылка перехода + alt
- Дата начала / окончания показа
- UTM-метки: source / medium / campaign
- Отслеживание кликов (data-атрибуты)
- TTL кэша (по умолчанию 60 мин)

### 8.2 Нижний баннер (перед подвалом)

Аналогично + изображение 1200×200 / мобильное 375×150.

---

## 9. СИСТЕМНЫЕ СЕРВИСЫ

### 9.1 Синхронизация билетов (IDPG_Ticket_Sync)

Cron-задача: проверка статусов билетов через внешний API, обновление `_idpg_ticket_status`, `_idpg_external_id`.

### 9.2 iCal-экспорт

- `/idpg/?action=ical_month` — месяц
- `/idpg/?action=ical_event&id=N` — одно событие
- Формат: iCalendar VEVENT

### 9.3 Режим обслуживания (IDPG_Maintenance)

- Заглушка HTTP 503 для гостей
- Режим «Разработка» — авторизованные видят сайт
- Режим «Обслуживание» — заглушка для всех кроме админов
- Пароль доступа, белый список IP, cookie
- Обратный отсчёт до авто-выключения
- Контакты и соцсети на заглушке

### 9.4 Определение города по IP

Два сервиса: ip-api.com → ipapi.co. Результат кэшируется на сутки. Cookie ставится один раз при первом визите.

---

## 10. ФРОНТЕНД-КАБИНЕТ ПОДПИСЧИКА (/lk/)

### 10.1 Профиль

- Аватар (загрузка через REST `/user/avatar`)
- Имя, email, пароль
- Обновление через `PUT /user/profile`

### 10.2 Статистика

Счётчики событий и площадок (через `_idpg_events_count`, `_idpg_venues_count`).

### 10.3 События в городе

Вывод событий выбранного города (через `GET /user/city-events`).

---

## 11. ЧТО НУЖНО ДОБАВИТЬ (согласно ТЗ)

### 11.1 Поля события (Carbon Fields, class-meta.php)

| Поле | Тип | Контейнер | Кто заполняет |
|---|---|---|---|
| `idpg_pin_expires` | Date | «Место и продвижение» → Доп. | Система (скрыто) |
| `idpg_stream_embed` | Textarea | «Медиа» | Организатор |

**Логика pin_expires:** при наступлении даты автоматически снять `_idpg_promoted`. Реализовать через WP-Cron (ежедневная проверка).

### 11.2 Поля площадки (Carbon Fields, class-meta.php)

| Поле | Тип | Контейнер |
|---|---|---|
| `idpg_venue_hours` | Complex: day/select, open/time, close/time, closed/checkbox | «Данные площадки» |
| `idpg_venue_social` | Complex: network/select, url | «Данные площадки» |

Добавить зеркала `_idpg_venue_hours_json` и `_idpg_venue_social_json` для читаемости в шаблонах.

### 11.3 Профиль организатора (User Meta)

| Поле | Тип | Кто заполняет | Кто меняет |
|---|---|---|---|
| `idpg_legal_type` | Select: individual, llc, ip, nonprofit | Организатор | — |
| `idpg_inn` | Text (10/12 цифр) | Организатор | — |
| `idpg_verified` | Checkbox | — | Только админ |
| `idpg_documents` | Media Gallery (PDF/изображения) | Организатор | — |

**Валидация INN:** `^(\d{10}|\d{12})$`

**Реализация:** создать `class-organizer-meta.php`, подключить в `idpg-core.php`, инициализировать через `IDPG_Organizer_Meta::instance()`.

### 11.4 Панель организатора в админке

Организаторы уже проходят в админку (нет блокировки). Нужно:
- Добавить пункт меню «Мои события» с доступом только к записям текущего автора
- На странице редактирования события — показать Carbon Fields-контейнер с полями профиля организатора (legal_type, inn, documents, verified)
- Статус verified показывать, но не давать менять организатору (через `protect_verification_status`)

---

## 12. ОГРАНИЧЕНИЯ И БЕЗОПАСНОСТЬ

| Механизм | Описание |
|---|---|
| `meta_auth_callback` | Проверка `edit_post` для каждого мета-поля |
| `restrict_admin` (class-auth) | Подписчики → `/lk/`, организаторы → проходят в админку |
| `can_manage_events` | Nonce + `edit_posts` для CRUD площадок |
| `can_access_lk` | Auth-cookie identification, nonce для write-запросов |
| `upload_files` | У подписчиков и организаторов для аватара |
| `unfiltered_html` | Только для widget_code (виджет Яндекс.Билетов) |

---

## 13. СЕРВЕРНЫЕ ТРЕБОВАНИЯ

| Требование | Значение |
|---|---|
| PHP | 7.4+ (тестирование на 8.2.29) |
| WordPress | 6.0+ |
| Composer | для Carbon Fields 3.6 |
| Cron | WP-Cron (синхронизация билетов, daily unpin) |
| Внешние API | ip-api.com, ipapi.co (город по IP) |

---

## 14. ИЗВЕСТНЫЕ ПРОБЛЕМЫ И ЗАДАЧИ

| # | Проблема | Статус |
|---|---|---|
| 1 | Площадок в БД 0 — архив `/venues/` пуст | Не исправлено |
| 2 | У события #67 нет миниатюры (thumbnail) | Не исправлено |
| 3 | `idpg_pin_expires` — поле не зарегистрировано | Не реализовано |
| 4 | `idpg_stream_embed` — поле не зарегистрировано | Не реализовано |
| 5 | `idpg_venue_hours` / `idpg_venue_social` — не зарегистрированы | Не реализовано |
| 6 | User meta организатора (legal_type, inn, verified, documents) | Не реализовано |
| 7 | Панель организатора в админке | Не реализовано |
| 8 | Автоматическое снятие promote по pin_expires | Не реализовано |
| 9 | Пара таксономий («READ MORE») — мёртвые данные | Не исправлено |
| 10 | Кликбait-контент на страницах событий | Не исправлено |

---

## 15. СТРУКТУРА ФАЙЛОВ (полный список)

### Плагин (idpg-core)

```
includes/
├── class-cpt.php                  — CPT event, venue + таксономии
├── class-roles.php                — роли, capabilities
├── class-meta.php                 — Carbon Fields поля + register_post_meta
├── class-rest-api.php             — REST маршруты (1144 строки)
├── class-rest-auth.php            — JWT-подобная авторизация
├── class-auth.php                 — редиректы, restrict_admin, login
├── class-auth-rate-limiter.php    — rate limiting
├── class-auth-emails.php          — email-рассылки
├── class-city-filter.php          — cookie + IP определение города
├── class-banners.php              — система баннеров (theme_options)
├── class-current-event-banner.php — hero-баннер текущего события
├── class-ticket-sync.php          — cron синхронизация билетов
├── class-ical-export.php          — iCal экспорт
├── class-views.php                — счётчик просмотров
├── class-notifications.php        — уведомления
├── class-maintenance.php          — режим обслуживания
├── class-migrations.php           — миграция мета-полей
├── class-template-helpers.php     — IDPG_Template_Helper (статические хелперы)
├── class-city-selector-widget.php
└── class-event-calendar-widget.php
```

### Тема (idpg_core)

```
├── functions.php                  — Theme setup, enqueue, filters
├── inc/
│   ├── template-tags.php          — Хелперы шаблонов (дата, цена, сеансы, карта)
│   ├── widgets.php                — Виджеты
│   ├── widget-areas.php           — Widget areas (админка)
│   └── theme-helpers.php
├── assets/css/
│   ├── style.css                  — Основной (сборка: check:css / compile:css:apply)
│   └── templates.css              — Стили шаблонов (не перезаписывается)
├── page-login.php                 — Авторизация
├── page-lk.php                    — ЛК подписчика
├── archive-event.php              — Афиша
├── single-event.php               — Событие
├── taxonomy-city.php              — Город
├── single-venue.php               — Площадка
└── template-parts/
    ├── content-event.php          — Карточка события
    └── content-venue.php          — Карточка площадки
```
