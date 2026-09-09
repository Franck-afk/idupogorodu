# Иду по городу — Импорт событий

Плагин `idpg-event-import` — модуль автоматического и ручного импорта событий с площадок партнёров в CPT `event` плагина **idpg-core**.

Поддерживает два типа источников:

- **JSON REST API** — для партнёров, у которых есть открытый API событий;
- **iCal (.ics)** — для партнёров без API, но с календарной лентой.

Импорт выполняется по расписанию (cron), вручную из админки или через WP-CLI. Повторные прогоны **обновляют** события, а не дублируют их (дедупликация по паре «источник + внешний ID»).

---

## Установка и требования

- **WP**: 6.4+; **PHP**: 8.0+.
- **Обязателен активный плагин idpg-core** (типы записей `event`/`venue`, таксономии `city`/`event_category`, роли). При активации без ядра плагин сам деактивируется и выводит пояснение.
- Установите папку `idpg-event-import` в `wp-content/plugins/`, активируйте в «Плагины».

### Активация

При активации:
1. проверяется наличие idpg-core (деактивация при отсутствии);
2. планируется единый cron-хук `idpgei_cron_import` (раз в час).

Не создаёт таблиц и не регистрирует CPT — работает поверх `event`.

---

## Быстрый старт

1. **Админка** → «Иду по городу» → **«Импорт событий»**.
2. **Добавьте источник** (см. раздел «Источники»).
3. Нажмите **«Импортировать»** рядом с источником — или дождитесь автоимпорта по расписанию.
4. Убедитесь, что события появились в **События → Все события** (по умолчанию — как черновики, требуют модерации).

Проверить, что источник настроен правильно и что парсер вернёт нужные поля, можно без записи в БД:

```bash
wp idpgei test <source-id>
```

---

## Источники (реестр)

Реестр источников хранится в **WP-опции** `idpgei_sources` (массив `source_id => конфиг`).

### Поля конфига

| Поле | Тип | Значение по умолчанию | Описание |
|---|---|---|---|
| `source_id` | строка | — | Уникальный латинский slug (`[a-z0-9\-_]`). После создания не меняется. |
| `name` | строка | — | Человекочитаемое название. |
| `provider` | строка | `rest` | `rest` или `ical`. |
| `endpoint` | URL | — | Endpoint API или URL .ics (только `http`/`https`). |
| `api_key` | строка | `` | Bearer-токен для REST. Пустое = без авторизации. |
| `list_path` | строка | `data` | Путь к массиву событий в JSON (см. ниже). |
| `page_param` | строка | `page` | Имя query-параметра пагинации REST. |
| `field_map` | массив | `array()` | Переопределение имён полей партнёра → наших (см. ниже). |
| `defaults` | массив | `array()` | Дефолтные `currency` и/или `city`, если их нет в API. |
| `city_term` | ID терма | `0` | Терм таксономии `city`, назначаемый всем событиям источника. Приоритетнее `city` из API. |
| `organizer_id` | ID | `0` | Автор (организатор) событий. `0` = текущий пользователь при прогоне. Важно для прав организаторов. |
| `post_status` | строка | `draft` | `draft` (модерация), `publish` (сразу) или `pending`. |
| `schedule` | секунды | `21600` | Период автоимпорта: 900 … 604800 (15 мин … 7 дней). |
| `overwrite` | bool | `false` | Перезаписывать ли заголовок и описание при изменении в API. |
| `auto_publish` | bool | `false` | Объявлен в дефолтах и санитизируется, но в админ-форме не выводится и поведением не управляет (см. «Справка по полям» в разделе «Расширяемость»). |
| `create_missing_venues` | bool | `false` | Создавать ли площадки, если не найдены по названию. |

Служебные поля (проставляет ядро, заполняются после прогона): `last_run`, `last_status` (`ok`/`error`), `last_error`, `stats` (`created`/`updated`/`skipped`/`errors`).

### Поля формы админки

- **Название**, **ID (slug)** — обязательные.
- **Тип** — JSON REST API или iCal.
- **Endpoint / URL календаря** — обязательное.
- **API-ключ (Bearer)** — необязательный. При правке источник скрытое поле остаётся пустым, чтобы **не затереть** текущий ключ: если отправить форму с пустым ключом, сохранится старый.
- **Путь к списку (REST)** — см. `list_path`.
- **Параметр пагинации (REST)** — см. `page_param`.
- **Город по умолчанию** — выпадающий список термов `city`; «— из API —» берёт город из события.
- **Автор (организатор)** — пользователь-владелец событий.
- **Статус публикаций** — черновик / публиковать сразу.
- **Частота автоимпорта** — 15 мин / час / 6 ч / 12 ч / день.
- **Политики** — перезаписывать заголовок/описание; создавать недостающие площадки.

---

## Формат данных

### REST-провайдер

Запрос: `GET <endpoint>?<page_param>=<номер страницы>` с заголовком `Accept: application/json` и, при наличии ключа, `Authorization: Bearer <api_key>`.

Пагинация — по номеру страницы (`page_param`, по умолчанию `page`). Реальным ограничением прогона является **`RUN_LIMIT = 500` событий** на источник (`includes/class-event-import.php:41`): ядро итерирует страницы, пока не соберёт 500 элементов или не получит пустую страницу. В `class-provider-rest.php` объявлена константа `MAX_PAGES = 20`, однако на практике ограничение по числу страниц в цикле ядра не применяется — лимит срабатывает по общему числу событий; это сделано, чтобы страницы с большими объёмами не обрывались преждевременно. Пагинация прекращается на первой пустой странице (пустой массив списка).

> **Важно для партнёров REST**: если API отдаёт одно и то же при запросе следующей страницы (некорректная пагинация), за прогон будет обработано до 500 одинаковых записей с дедупликацией по `external_id` (они попадут в `updated`/`skipped`, а не задвоятся). После первого пустого ответа страницы прогон завершается.

#### Где искать список событий (`list_path`)

- **`''`** (пусто) — корневой массив: `[ {...}, {...} ]`.
- **`data`** (по умолчанию) — список внутри ключа: `{ "data": [ ... ] }`.
- Вложенность через точку: `data.items` → `{ "data": { "items": [ ... ] } }`.

#### Поля события (псевдо-API → наши)

По умолчанию используются «синонимы» имён полей (первое непустое из списка). Через `field_map` источника можно явно задать имя поля партнёра.

| Наше поле | Синонимы по умолчанию (первое непустое) | Источник поля в iCal |
|---|---|---|
| `external_id` | `external_id`, `id`, `uid`, `event_id` | `UID` |
| `title` | `title`, `name`, `summary` | `SUMMARY` |
| `description` | `description`, `text`, `body`, `annotation` | `DESCRIPTION` |
| `starts_at` | `starts_at`, `start`, `date_start`, `startDate`, `begins_at` | `DTSTART` |
| `ends_at` | `ends_at`, `end`, `date_end`, `endDate` | `DTEND` |
| `venue_name` | `venue_name`, `venue`, `place`, `location`, `hall` | `LOCATION` |
| `venue_address` | `venue_address`, `address`, `place_address` | — |
| `city` | `city`, `town`, `locality` | — |
| `category` | `category`, `category_name`, `genre` | — |
| `price_min` | `price_min`, `price_from`, `min_price`, `price` | — |
| `price_max` | `price_max`, `price_to`, `max_price` | — |
| `is_free` | `is_free`, `free` | — |
| `currency` | `currency`, `currency_code` | — |
| `buy_url` | `buy_url`, `ticket_url`, `url`, `tickets_url` | `URL` |
| `status` | `status`, `state`, `ticket_status` | — |
| `image` | `image`, `poster`, `image_url`, `cover`, `thumbnail` | — |

`field_map` — массив вида `[ 'title' => 'название_поля_партнёра', 'starts_at' => 'date', ... ]`, где ключ — наше поле, значение — имя поля в API (добавляется в начало списка синонимов).

#### Обязательные поля

Событие **пропускается** (считается в `skipped`), если хотя бы одно из них пусто:

- `title` — заголовок;
- `starts_at` — дата начала;
- `external_id` — внешний ID (без него невозможна дедупликация).

#### Санитизация и типы

| Поле | Обработка |
|---|---|
| `title`, `venue_name`, `venue_address`, `city`, `category` | `sanitize_text_field()` |
| `description` | `wp_kses_post()` (массив строк склеивается переводом строки) |
| `starts_at` / `ends_at` | `normalize_date()` → `Y-m-d\TH:i` или `Y-m-d` |
| `currency` | `strtoupper( sanitize_key() )` |
| `buy_url` / `image` | `esc_url_raw()` только `http`/`https` |
| `price_min` / `price_max` | `to_float()` — число или число из строки («от 500 ₽» → 500) |
| `is_free` | `yes`, если значение `true`/`1`/`yes`/`free` |
| `status` | `map_status()` (см. ниже) |

##### Нормализация даты (`normalize_date`)

Локальное время партнёра сохраняется **как заявлено** (без пересчёта часовых поясов) — афиша показывает время «как есть»:

- ISO с зоной `2026-09-10T18:00:00+03:00` → `2026-09-10T18:00` (зона отбрасывается);
- только дата `2026-09-10` → `2026-09-10`;
- произвольный формат (`10.09.2026 18:30`) — парсится через `strtotime()` и приводится к `Y-m-d\TH:i`;
- нераспознанное → пусто (событие пропускается).

##### Статусы (`map_status`)

| Из API | Наше значение |
|---|---|
| `active`, `in_sale` | `in_sale` |
| `sold_out`, `soldout` | `sold_out` |
| `cancelled`, `canceled` | `cancelled` |
| `postponed` | `postponed` |
| иное | `''` → при сохранении ставится `in_sale` |

### iCal-провайдер

Для URL `.ics`. Парсер (`class-provider-ical.php`):

- разворачивает «свёрнутые» строки RFC 5545 (продолжение с пробела/таба);
- обрабатывает только `BEGIN:VEVENT` … `END:VEVENT` (игнорирует `VTIMEZONE`, `VCALENDAR`);
- читает свойства `UID`, `SUMMARY`, `DESCRIPTION`, `LOCATION`, `URL`, `DTSTART`, `DTEND`;
- `DTSTART`/`DTEND` поддерживает `YYYYMMDD` (дата) и `YYYYMMDDTHHMMSS[Z]` (дата-время), приводится к `Y-m-d\TH:i`;
- iCal **не пагинируется** — вся лента отдаётся за один запрос.

Одно событие в результате = один `VEVENT`. События без заголовка пропускаются на этапе парсера.

---

## Чек-лист подключения партнёра

Пошагово, от получения доступа до первого импорта:

1. **Получите от партнёра** формат выдачи: JSON REST API или iCal-лента.
   - Для REST уточните: URL endpoint, параметр страницы, авторизация (Bearer?),
     имена полей (дата, цена, билеты, площадка), как помечены отменённые события.
   - Для iCal — только URL `.ics` (если защищён, может потребоваться код или валидный referer).
2. **Создайте источник** в админке («Иду по городу → Импорт событий»):
   - ID (slug) — латиницей, например `kassir` или `park-gorkogo`.
   - Тип (REST/iCal), endpoint, при необходимости Bearer-ключ.
   - Для REST — `list_path` и `page_param` по фактической структуре ответа (см. «Полный пример»).
   - Город по умолчанию (если у партнёра город не приходит — задайте терм здесь).
   - Автор (организатор) — важный шаг: от него зависят права доступа организаторов.
   - Статус публикаций: сначала `draft`, чтобы события прошли модерацию.
   - Частота автоимпорта и политики (overwrite / создание площадок).
3. **Проверьте без записи в БД**:
   ```bash
   wp idpgei test kassir
   ```
   Вы увидите «Получено элементов: N» и первые валидные события с их датами и
   `external_id`. Если элементов 0 или «пропущен» — проверьте `list_path` и имена полей.
   Любые нестандартные имена полей решаются через `field_map` в опции источника
   (см. «Полный пример», «Расширяемость»).
4. **Выполните первый импорт вручную**:
   ```bash
   wp idpgei import kassir          # или кнопка «Импортировать» в админке
   ```
   Статус: «новых: N, обновлено: 0, пропущено: M, ошибок: 0».
5. **Проверьте события**: «События → Все события» — черновики должны быть
   с датой, ценой, билетами, площадкой, городом, категорией, постером.
   Пропущенные (`skipped`) — это пустые `title`/`starts_at`/`external_id`.
6. **Если всё ок** — при необходимости переключите источник на `publish`
   (публиковать сразу) и установите частоту. Cron «раз в час» подхватит сам.

---

## Полный пример: REST-партнёр

Допустим, партнёр отдаёт следующий JSON по `https://api.partner.ru/v2/events`:

```json
{
  "result": {
    "items": [
      {
        "event_id": "ev_1024",
        "title": "Концерт группы «Апельсин»",
        "annot": "Живое выступление в центре города.",
        "date_from": "2026-09-10T19:00:00+03:00",
        "date_to": "2026-09-10T22:30:00+03:00",
        "stage_name": "Клуб «Космос»",
        "stage_addr": "Москва, ул. Тверская 20",
        "min_price": "от 1200 ₽",
        "max_price": "от 4500 ₽",
        "tickets": "https://api.partner.ru/buy/ev_1024",
        "state": "active",
        "img": "https://cdn.partner.ru/ev_1024.jpg",
        "genre": "Концерт"
      }
    ]
  }
}
```

Поля **не совпадают** с нашими синонимами по умолчанию, а список лежит не в
`data`, а в `result.items`. Настройка источника:

| Настройка | Значение | Основание |
|---|---|---|
| `list_path` | `result.items` | список событий лежит в `result → items` |
| `page_param` | `page` | пагинация по строкам `?page=N` (по умолчанию) |
| `field_map` | см. ниже | имена `event_id`, `annot`, `date_from` … отличаются от типовых |

`field_map` для этого API:

```json
{
  "external_id": "event_id",
  "description": "annot",
  "starts_at": "date_from",
  "ends_at": "date_to",
  "venue_name": "stage_name",
  "venue_address": "stage_addr",
  "price_min": "min_price",
  "price_max": "max_price",
  "buy_url": "tickets",
  "status": "state",
  "image": "img",
  "category": "genre"
}
```

(Поля `title` в маппинге нет — заголовок ищется по синонимам `title|name|summary` и в нашем примере совпадает с `title`.)

В поле `api_key` укажите Bearer-токен, если API требует авторизацию.

Пример токена не сохраняется в открытом виде (поле ввода типа password в админке, при правке пустое поле = сохранить старый ключ).

---

## Полный пример: iCal-партнёр

Input `.ics` (свёрнутые строки разворачиваются, `VTIMEZONE` игнорируется):

```
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:20260910@partner.ru
DTSTART;TZID=Europe/Moscow:20260910T190000
DTEND:20260910T223000
SUMMARY:Спектакль «Три сестры»
DESCRIPTION:Премьера в театре.
LOCATION:Театр драмы
URL:https://partner.ru/buy/20260910
END:VEVENT
END:VCALENDAR
```

Записи в `idpgei_sources`:

```json
{
  "source_id": "teatr",
  "name": "Театр драмы",
  "provider": "ical",
  "endpoint": "https://partner.ru/feed.ics",
  "list_path": "data",
  "page_param": "page",
  "field_map": {},
  "defaults": { "city": "Москва" },
  "city_term": 0,
  "organizer_id": 3,
  "post_status": "publish",
  "schedule": 21600,
  "overwrite": true,
  "auto_publish": false,
  "create_missing_venues": true
}
```

Результат: событие `2026-09-10T19:00` на площадке «Театр драмы» (создастся,
т.к. `create_missing_venues=true`), город «Москва» из дефолтов, статус `publish`.

---

## Справочник опции `idpgei_sources`

Реестр хранится как `WP Option` с именем `idpgei_sources` — ассоциативный массив
`source_id => конфиг`. Служебные поля дозаполняются ядром после каждого прогона.

Полный формат одного источника:

```json
{
  "source_id": "kassir",
  "name": "Кассир.ру",
  "provider": "rest",
  "endpoint": "https://api.kassir.ru/v1/events",
  "api_key": "секретный-bearer-токен",
  "list_path": "data",
  "page_param": "page",
  "field_map": { "title": "eventName" },
  "defaults": { "currency": "RUB", "city": "Москва" },
  "city_term": 12,
  "organizer_id": 5,
  "post_status": "draft",
  "schedule": 21600,
  "overwrite": false,
  "auto_publish": false,
  "create_missing_venues": true,
  "last_run": 1725000000,
  "last_status": "ok",
  "last_error": "",
  "stats": { "created": 12, "updated": 3, "skipped": 1, "errors": 0 }
}
```

| Служебное поле | Тип | Описание |
|---|---|---|
| `last_run` | int (unix ts) | момент последнего прогона |
| `last_status` | string | `ok` или `error` |
| `last_error` | string | текст ошибки (пусто = успех) |
| `stats` | array | счётчики `created`/`updated`/`skipped`/`errors` |

> `api_key` хранится как есть (без жёсткой санитизации — токены содержат
> спецсимволы). Надёжно защищайте БД/бэкапы; ещё безопаснее — вторым способом
> передавать ключ из секретов окружения через кастомный провайдер или фильтр
> `idpgei_http_transport`.

**Перемещение/бэкап**: бэкап делается экспортом/импортом этой опции (например,
`wp option get idpgei_sources --format=json` / `wp option update idpgei_sources < json`).
Сам плагин таблиц не создаёт — перенос сводится к копированию папки плагина + опции.

---

## Поля записи (CPT `event`)

При создании/обновлении события проставляются следующие meta-поля (ключи совпадают с тем, что читает тема `idpg_core`):

| Meta-ключ | Откуда | Значение |
|---|---|---|
| `_idpg_source` | источник | `source_id` (маркер для дедупликации) |
| `_idpg_external_id` | событие | внешний ID |
| `_idpg_start_date` | `starts_at` | всегда |
| `_idpg_end_date` | `ends_at` | если задано |
| `_idpg_ticket_url` | `buy_url` | всегда (может быть пустым) |
| `_idpg_ticket_status` | `status` | `in_sale`/`sold_out`/`cancelled`/`postponed` (по умолчанию `in_sale`) |
| `_idpg_price_from` | `price_min` | если число |
| `_idpg_price_to` | `price_max` | если число |
| `_idpg_is_free` | `is_free` | `yes` |
| `_idpg_currency` | `currency` | если задано |
| `_idpg_venue_address` | `venue_address` | если задано |
| `_idpg_venue` | `venue_name` | ID найденной площадки (или созданной) |

### Площадки

- Поиск площадки — точное совпадение `post_title` среди `venue` со статусом `publish`.
- Если не найдена и включён `create_missing_venues` — создаётся новая площадка (`venue`, статус `publish`, заголовок = название, адрес, при наличии — терм города источника).
- Найденная/созданная площадка связывается через `_idpg_venue`.

### Термы

- **Город** (`city`): терм источника (`city_term`) приоритетнее; иначе — город из API, если существует терм с таким названием.
- **Категория** (`event_category`): присваивается, **только если** терм с таким названием уже существует (мусорные термы не создаются).
- У новых площадок город источника назначается тоже.

### Постер (миниатюра)

- URL `image` загружается через `media_handle_sideload()` и ставится миниатюрой (`set_post_thumbnail`).
- Если у события **уже есть** постер — не перезаписывается (политика контента).
- Таймаут загрузки — 15 секунд. Имя файла берётся из URL; если расширение отсутствует — подставляется `import-<post_id>.jpg`.

---

## Логика upsert и политики

Определение существования события — по паре мета-полей:

```
_idpg_source == source_id  AND  _idpg_external_id == external_id
```

(поиск по любым статусам, включая корзину — `get_post_stati()`).

### Поведение

- **Создание**: `wp_insert_post()` со статусом `post_status` источника; заполняются заголовок, контент и все перечисленные meta-поля.
- **Обновление** (`wp_update_post()`):
  - meta-поля (даты, цены, статус, ссылка на билеты, площадка) обновляются **всегда**;
  - заголовок и описание обновляются **только** при `overwrite = true` (иначе редакторские правки заголовка/описания сохраняются);
  - **опубликованное** событие не переводится обратно в `draft`/`pending` (статус `publish` сохраняется), даже если источник настроен на `draft`.

### Статистика прогона

Вернется массив и сохранится в `stats` источника, а также выводится в админке и WP-CLI:

- `created` — создано новых;
- `updated` — обновлено существующих;
- `skipped` — отброшено: пустой `title`/`starts_at`/`external_id` или некорректная дата;
- `errors` — сбои при записи (WP_Error от `wp_insert_post`/`wp_update_post`).

---

## Cron и автоимпорт

- Единственный хук `idpgei_cron_import` планируется **раз в час** при активации (и перепланируется при изменении источников через `reschedule()`).
- `run_cron()` проходит по всем источникам и обрабатывает те, у которых `last_run + schedule <= now()`.
- Индивидуальный период задаётся в `schedule` каждого источника (15 мин … 7 дней) — cron-хук лишь «тик», а какая, когда должна — решаем сами.

Ограничение: реальный запуск WP-cron зависит от посещаемости сайта (WP-cron работает «псевдо-фоново»). Для строгого расписания настройте системный cron на `wp-cron.php` (например, `curl -s https://site/wp-cron.php?doing_wp_cron=1 > /dev/null`).

---

## Админка

**Иду по городу → Импорт событий** (`admin.php?page=idpgei-sources`, право `manage_options`).

Возможности:

- таблица источников: название, тип, режим публикации, частота, последний прогон, статистика, последняя ошибка;
- **Импортировать** — ручной запуск источника (admin-post + nonce, `idpgei_run_now`);
- **Правка** — редактирование конфига;
- **Удалить** — удаляет источник из реестра, **не трогая** уже импортированные события;
- форма создания/редактирования источника (см. раздел «Поля конфига»).

Все admin-post действия защищены nonce (`idpgei_admin`) и проверкой прав.

---

## WP-CLI

Команда `wp idpgei ...`.

| Команда | Описание |
|---|---|
| `wp idpgei sources` | Список источников (ID, тип, статус, последний прогон, название). |
| `wp idpgei import <source-id>` | Импорт одного источника (игнорируя расписание). |
| `wp idpgei import <source-id> --force` | То же, явный флаг игнорирования расписания. |
| `wp idpgei import --all` | Все источники, у которых наступило время. |
| `wp idpgei import --all --force` | Все источники независимо от расписания. |
| `wp idpgei test <source-id>` | Пробный прогон: fetch + маппинг **без записи в БД**, показ первых 3 валидных. |

Примеры:

```bash
wp idpgei test kassir
wp idpgei import kassir
wp idpgei import --all --force
```

---

## Расширяемость

Плагин спроектирован с расчётом на добавление провайдеров и кастомизацию кода без правки ядра.

### Фильтры

| Фильтр | Аргументы | Назначение |
|---|---|---|
| `idpgei_http_transport` | `$transport (callable|null)`, `$url` | Подменить HTTP-транспорт (используется тестами; пригодится для кастомных сценариев/моков). |
| `idpgei_provider_class` | `$provider_class (string|null)`, `$source` | Вернуть имя класса кастомного провайдера по `$source['provider']`. |

### Действия

| Действие | Аргументы | Назначение |
|---|---|---|
| `idpgei_import_completed` | `$source_id`, `$stats` | Срабатывает после завершения импорта источника (уведомления, логи, индексация и т.п.). |

### Кастомный провайдер

1. Наследуйте `IDPG_Import_Provider`, реализуйте `get_label()`, `get_slug()`, `fetch_page( $page )`. Используйте `$this->http_get()`.
2. Зарегистрируйте класс через фильтр `idpgei_provider_class`:

```php
add_filter( 'idpgei_provider_class', function ( $class, $source ) {
    if ( isset( $source['provider'] ) && 'mypartner' === $source['provider'] ) {
        return 'IDPGEI_Provider_MyPartner';
    }
    return $class;
}, 10, 2 );
```

3. Маппинг полей выполняет `IDPG_Import_Mapper::map_event( $raw, $source )` — верните из `fetch_page()` массив «сырых» элементов с теми же ключами, что и REST-провайдер (`external_id`, `title`, `starts_at`, …) или используйте `field_map` источника.

Полный пример провайдера, читающего CSV:

```php
class IDPGEI_Provider_CSV extends IDPG_Import_Provider {

	public static function get_label() {
		return 'CSV';
	}

	public static function get_slug() {
		return 'csv';
	}

	public function fetch_page( $page ) {
		if ( $page > 1 ) {
			return array(); // CSV без пагинации — одна страница.
		}

		$response = $this->http_get( $this->source['endpoint'] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$lines = array_filter( explode( "\n", wp_remote_retrieve_body( $response ) ) );
		$items = array();

		foreach ( $lines as $line ) {
			$cols = str_getcsv( $line, ';' );
			$items[] = array(
				'external_id' => $cols[0],
				'title'       => $cols[1],
				'starts_at'   => $cols[2],
				'is_free'     => '1' === $cols[3],
			);
		}

		return $items;
	}
}

add_filter( 'idpgei_provider_class', function ( $class, $source ) {
	if ( isset( $source['provider'] ) && 'csv' === $source['provider'] ) {
		return 'IDPGEI_Provider_CSV';
	}
	return $class;
}, 10, 2 );
```

Затем создайте источник с типом `csv` (через `wp idpgei` нет прямого CLI-создания —
добавьте через админку либо `IDPG_Event_Import::instance()->save_source()`) — и провайдер подхватится автоматически.

### Точные сигнатуры хуков

| Хук | Сигнатура колбэка |
|---|---|
| `idpgei_http_transport` | `function ( ?callable $transport, string $url )` — вернуть `callable(float $url, array $args)` или `null` для штатного похода. |
| `idpgei_provider_class` | `function ( ?string $provider_class, array $source )` — вернуть FQCN класса-провайдера или `null`. |
| `idpgei_import_completed` | `function ( string $source_id, array $stats )` — `$stats = ['created'=>int,'updated'=>int,'skipped'=>int,'errors'=>int]`. |

> **Справка по ряду полей конфига** (`sanitize_source`):
> - `schedule` приводится к интервалу `900 … 604800` сек (15 мин … 7 суток), меньший/больший — обрезается;
> - `post_status` — только `draft` / `publish` / `pending`, иначе `draft`;
> - `endpoint` — `esc_url_raw()` с разрешёнными `http`/`https`;
> - `organization/auto_publish` — поле `auto_publish` объявлено в дефолтах и санитизируется, но в текущей версии админ-форма его не выводит и upsert им не управляется (логика публикации строится на `post_status` источника). Если вы включаете `auto_publish` напрямую в опции — учтите, что это не меняет поведение без доработки кода.

---

## Каталог файлов

```
idpg-event-import/
├── idpg-event-import.php         — загрузчик: зависимости, cron, init, регистрация CLI
├── includes/
│   ├── class-import-provider.php — абстрактный провайдер (общий HTTP-транспорт)
│   ├── class-provider-rest.php   — REST JSON (пагинация, list_path, Bearer)
│   ├── class-provider-ical.php   — iCal (.ics) парсер
│   ├── class-import-mapper.php   — чистый маппер: поля, даты, цены, статусы
│   ├── class-event-import.php    — ядро: реестр, cron, upsert, дедуп, термы, постеры
│   ├── class-import-admin.php    — админ-страница (CRUD, ручной запуск)
│   └── class-import-cli.php      — WP-CLI команды
├── phpunit.xml.dist              — конфиг тестов
└── tests/                        — юнит-тесты (маппер, iCal, ядро)
```

---

## Запуск тестов

Тесты используют окружение PHPUnit плагина idpg-core (общий `vendor`).

```powershell
$env:WP_TESTS_DIR = "C:\Users\Costic\AppData\Local\Temp\opencode\wordpress-develop-trunk\tests\phpunit"
$env:WP_TESTS_PHPUNIT_POLYFILLS_PATH = "D:\laragon\www\idupogorodu\wp-content\plugins\idpg-core\vendor\yoast\phpunit-polyfills"
& "D:\laragon\www\idupogorodu\wp-content\plugins\idpg-core\vendor\bin\phpunit" `
    -c "D:\laragon\www\idupogorodu\wp-content\plugins\idpg-event-import\phpunit.xml.dist"
```

Покрытие: нормализация дат и статусов, `to_float`, iCal-парсер (unfold, VEVENT, DTSTART/DTEND), upsert/дедуп, политики `overwrite`, автосоздание площадок, термы, HTTP-ошибки, сквозной iCal-импорт.

---

## Поток импорта (как это работает)

```
Активация плагина
   │
   ▼
Планируется cron-хук idpgei_cron_import (раз в час)
   │
   ▼  (каждый тик — WP cron / wp-cron.php)
run_cron(): перебор всех источников
   │  если last_run + schedule <= now() — берёмся за источник
   ▼
process_source(<id>)
   │  1. get_source(<id>)            → конфиг из опции idpgei_sources
   │  2. get_provider($source)       → REST | iCal | кастомный (через фильтр)
   │  3. цикл страниц (1..50, но реальный лимит — 500 элементов):
   │       fetch_page($page)
   │         ├─ пустая страница       → break (страницы кончились)
   │         ├─ WP_Error (стр. 1)     → finish_source_run(error); abort
   │         └─ иначе: для каждого элемента
   │              map_event($raw, $source)      → null? → skipped++
   │              upsert_event($mapped, $source)→ created | updated | errors
   │              (если total >= 500 → break 2)
   ▼
finish_source_run(): last_run/last_status/last_error/stats -> в опцию
   ▼
do_action('idpgei_import_completed', source_id, stats)
```

upsert_event() внутри:
```
find_existing(source_id, external_id)  // по _idpg_source + _idpg_external_id, любой статус
   ├─ существует →
   │    meta-поля: всегда
   │    title/description: только если overwrite
   │    если статус уже publish — не переводим в draft/pending
   │    wp_update_post → 'updated'
   └─ нет →
        wp_insert_post (post_status источника) → 'created'
затем: assign_terms (city, event_category) → sideload_poster (если image и нет миниатюры)
```

---

## Частые вопросы и решение проблем

**Импортируется 0 событий (test показывает «Получено элементов: 0»).**
Скорее всего неверный `list_path` либо поле списка отсутствует. Сверьте
структуру: `data`, `result.items`, `''` для корневого массива. Проверка через
`wp idpgei test <id>` покажет текст ошибки «В ответе API не найден список событий».

**События пропускаются (skipped), хотя данные на месте.**
Причины: пустой `title`, пустой/нераспознанный `starts_at`, пустой `external_id`.
Проверьте, что в ответе есть эти три поля и дата парсится (`normalize_date`).
Имена полей партнёра, не совпадающие с синонимами, задайте через `field_map`.

**События создаются, но без города/категории.**
Город ставится из `city_term` источника или из названия города в API только
**если существует терм с таким именем** в таксономии `city`. Категория — только
по уже существующему терму `event_category`. Создайте недостающие термы вручную.

**Дата сдвинулась на несколько часов.**
Плагин **намеренно** хранит время «как заявлено» (без пересчёта таймзоны).
Время с `+03:00` или `TZID` парсится как локальное. Если партнёр отдаёт время в
другой зоне и нужна конвертация — правьте `IDPG_Import_Mapper::normalize_date()`.

**События не публикуются, хотя источник на `publish`.**
`publish` применим к **новым** событиям. Уже существующее **опубликованное**
событие повторным прогоном не откатывается в `draft/pending`; но и источник,
настроенный на `draft`, не «прижмёт» опубликованное событие — статус `publish`
сохраняется.

**Как убрать лишние события после удаления источника?**
Удаление источника **не удаляет** импортированные события (сознательно). Чтобы
удалить, используйте «События → Корзина» или расширение через хук
`idpgei_import_completed`/кастомный код. Фильтрация по источнику — по мете
`_idpg_source` (SQL/NVP: `meta_key=_idpg_source&meta_value=<source_id>`).

**Импорт не запускается по расписанию.**
WP-cron срабатывает только при посещении сайта. Для гарантированного расписания
настройте системный cron на `wp-cron.php` (см. раздел «Cron»). Проверить, что хук
запланирован: `wp cron event list --fields=hook,next_run_relative,recurrence`.

**События задваиваются.**
Дедупликация возможна только при корректном `external_id`. Если поле не приходит
или меняется между прогонами — события будут создаваться заново. Проверьте, что
`external_id` стабилен в выдаче API (тот же ID для того же события).

**Ошибка «API вернул код 401/403» для REST.**
Проверьте Bearer-ключ. Напомним: при правке источника пустое поле ключа сохраняет
старый ключ — если ключ был, но стал «пуст» в реальности, проверьте опцию
`idpgei_sources` на сервере (не перезатёрли ли ключ при сохранении с формата,
который не знает о существующем ключе).

**Постер не загружается.**
Загрузка идёт только для `http(s)` URL и только если у события ещё нет миниатюры.
Имя файла берётся из пути URL; при отсутствии расширения подставляется
`import-<post_id>.jpg`. Убедитесь, что URL изображения отдаёт бинарный контент
за 15 секунд.

**Нужно добавить источник кодом (нет CLI-команды создания).**
Используйте публичный API ядра:

```php
IDPG_Event_Import::instance()->save_source( array(
	'source_id'   => 'mypartner',
	'name'        => 'Мой партнёр',
	'provider'    => 'rest',
	'endpoint'    => 'https://api.partner.ru/v1/events',
	'list_path'   => 'data',
	'post_status' => 'draft',
	'schedule'    => 3600,
) );
```

`save_source()` сам санитизирует конфиг и перепланирует cron.

---

## Безопасность и права доступа

- **Админка**: все обращения к странице и admin-post обработчикам
  (`idpgei_save_source`, `idpgei_delete_source`, `idpgei_run_now`)
  проверяют `current_user_can( 'manage_options' )` и `check_admin_referer()`
  (nonce `idpgei_admin`). Права ниже `manage_options` доступа не имеют.
- **WP-CLI**: команды требуют запуска от пользователя с правами WP-CLI
  (обычно это администратор/sudo) — дополнительных проверок внутри нет,
  рассчитывайте на это при настройке прав доступа к `wp`.
- **Автор (организатор)**: события создаются с `post_author = organizer_id`
  (или текущего пользователя, если 0). Это важно: права организаторов в
  idpg-core (`event_organizer`) строятся на владельце записи — назначайте
  корректный `organizer_id`, чтобы доступ дали тому организатору.
- **Endpoint/URL** принимается только по схеме `http`/`https`
  (`esc_url_raw`); иных протоколов (`file://`, `data:` и т.п.) нет.
- **API-ключ** (Bearer) хранится в опции `idpgei_sources` как есть. Не
  выводится на страницах/в логах WordPress; при правке источника пустое
  поле ключа сохраняет старый (не перезатирает).
- **Постер**: загрузка идёт только с `http(s)`, через `media_handle_sideload()`
  (обычная WP-проверка типа файла). На удалённые бинарные данные тратит до
  15 секунд таймаута.
- **Лимиты**: не более 500 событий за прогон источника — защита от
  «бесконечной» пагинации партнёра.

---

## Известные ограничения

- **Даты хранятся «как заявлено»** — без пересчёта часовых поясов. Если партнёр отдаёт время в другом таймзоне и нужна конвертация, это правится в `normalize_date()`/`map_event()`.
- **Категория** присваивается только по уже существующему терму — новые категории вручную создаются в таксономии.
- **Лист без `external_id`** не дедуплицируется и пропускается (невозможно отследить изменение) — внешний ID обязателен.
- **Постер** загружается только для URL с расширением файла; при его отсутствии подставляется `import-<id>.jpg`.
- Удаление источника **не удаляет** импортированные события (сознательно, для безопасности).
- Параметры REST-пагинации — только query-строка; для пагинации через заголовки/Link потребуется кастомный провайдер.
