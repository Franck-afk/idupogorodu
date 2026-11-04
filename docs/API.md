# API организатора (idpg/v1)

> **Версия:** 1.0
> **Namespace:** `idpg/v1`
> **Базовый URL:** `https://idupogorodu.ru/wp-json/idpg/v1`
> **Авторизация:** `X-WP-Nonce` header (WP REST nonce) + cookie-аутентификация
> **Роль:** `event_organizer` (и выше: `administrator`, `editor`)
> **Статус:** API сохранён как публичный контракт (DEV-261, вариант А). Фронтенд будет переписан.

---

## Сводка маршрутов (7 URL patterns)

| Метод | Путь | Описание | Permission callback |
|-------|------|----------|---------------------|
| GET, POST | `/organizer/events` | Список своих событий / создание черновика | `can_organizer` |
| GET, PUT, DELETE | `/organizer/events/{id}` | Чтение / правка / удаление своего события | `can_access_event` |
| POST | `/organizer/events/{id}/submit` | Отправка на модерацию | `can_access_event` |
| GET | `/organizer/stats` | Счётчики своих событий | `can_organizer` |
| GET, POST | `/organizer/venues` | Список своих+общих площадок / создание | `can_organizer` / `edit_venues` |
| GET, PUT, DELETE | `/organizer/venues/{id}` | Чтение / правка / удаление своей площадки | `can_access_venue` |
| GET, PUT | `/organizer/profile` | Профиль текущего организатора | `can_profile` |

**Всего:** 7 URL patterns, 14 HTTP-методов.

---

## Аутентификация

### Заголовок `X-WP-Nonce`
Обязателен для всех маршрутов, кроме `GET /organizer/profile` (там cookie-аутентификация WP проверяется автоматически).

```
X-WP-Nonce: <wp_rest_nonce>
```

Получить nonce:
```js
// В PHP-шаблоне:
wp_create_nonce( 'wp_rest' )
// или глобально:
wp_localize_script( 'idpg-organizer', 'idpgData', array( 'restNonce' => wp_create_nonce( 'wp_rest' ) ) );
```

### Ошибки аутентификации

| Код | Ошибка | HTTP | Описание |
|-----|--------|------|----------|
| `idpg_org_nonce_failed` | `idpg_org_nonce_failed` | 403 | Неверный или отсутствующий nonce |
| `idpg_org_not_logged_in` | `idpg_org_not_logged_in` | 401 | Не авторизован |
| `idpg_org_forbidden` | `idpg_org_forbidden` | 403 | Не организатор (нет роли) |
| `idpg_org_forbidden_owner` | `idpg_org_forbidden_owner` | 403 | Не владелец ресурса |
| `idpg_org_event_not_found` | `idpg_org_event_not_found` | 404 | Событие не найдено |
| `idpg_org_event_locked` | `idpg_org_event_locked` | 409 | Событие на модерации |
| `idpg_org_event_not_editable` | `idpg_org_event_not_editable` | 403 | Статус не позволяет править |

---

## Скоуп

Организатор видит и правит **только свои** события и площадки (сравнение по
`post_author`). Чужой ресурс → **403**, а не 404, чтобы не выдавать факт
существования записи. Администратор (`edit_others_events`) видит все.

---

## 1. События организатора

### 1.1 Список событий
**GET** `/organizer/events`

**Параметры запроса:**

| Параметр | Тип | Обязательный | По умолчанию | Описание |
|----------|-----|--------------|--------------|----------|
| `status` | string | нет | все | `draft`, `pending`, `publish`, `rejected` |
| `page` | integer | нет | `1` | Номер страницы |
| `per_page` | integer | нет | `20` | Элементов на странице (макс. 50) |
| `search` | string | нет | `""` | Поиск по заголовку/контенту |
| `orderby` | string | нет | `date` | `date`, `title`, `views` |
| `order` | string | нет | `desc` | `asc`, `desc` |

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 123,
        "title": "Концерт",
        "content": "...",
        "excerpt": "Краткое описание",
        "status": "draft",
        "permalink": "https://example.com/afisha/123/",
        "date": "2026-10-07 12:00:00",
        "author": 72,
        "start_date": "2026-12-01 19:00:00",
        "end_date": "2026-12-01 22:00:00",
        "all_day": "no",
        "sessions_json": "[{\"time\":\"19:00\",\"price\":\"500\",\"url\":\"https://tickets.example.com/123\"}]",
        "venue": 45,
        "is_free": "no",
        "price_from": "500",
        "price_to": "1000",
        "vip_price": "1500",
        "ticket_status": "in_sale",
        "ticket_type": "external_link",
        "ticket_url": "https://tickets.example.com/123",
        "yandex_form_url": "",
        "widget_code": "",
        "address": "ул. Ленина, 1",
        "banner_request": "no",
        "is_online": "no",
        "stream_url": "",
        "video_trailer": "",
        "stream_embed": "",
        "poster_id": 42,
        "gallery": [43, 44],
        "city": [15],
        "category": [3],
        "age_rating": [1],
        "views": 150,
        "clicks": 12
      }
    ],
    "total": 15,
    "pages": 1
  },
  "message": ""
}
```

### 1.2 Создание черновика
**POST** `/organizer/events`

**Тело запроса** (JSON или form-data):
```json
{
  "title": "Название события",
  "content": "Описание",
  "excerpt": "Краткое описание",
  "city": 15,
  "category": 3,
  "age_rating": 1,
  "venue": 45,
  "start_date": "2026-12-01 19:00:00",
  "end_date": "2026-12-01 22:00:00",
  "all_day": "no",
  "sessions_json": "[{\"time\":\"19:00\",\"price\":\"500\",\"url\":\"https://tickets.example.com/123\"}]",
  "is_free": "no",
  "price_from": "500",
  "price_to": "1000",
  "vip_price": "1500",
  "ticket_status": "in_sale",
  "ticket_type": "external_link",
  "ticket_url": "https://tickets.example.com/123",
  "yandex_form_url": "",
  "widget_code": "",
  "address": "ул. Ленина, 1",
  "banner_request": "no",
  "is_online": "no",
  "stream_url": "",
  "video_trailer": "",
  "stream_embed": "",
  "poster_id": 42,
  "gallery": [43, 44],
  "city": 15,
  "category": 3,
  "age_rating": 1,
  "ticket_status": "in_sale",
  "vip_price": "1500",
  "yandex_form_url": "",
  "address": "ул. Ленина, 1",
  "banner_request": "no",
  "is_online": "no",
  "stream_url": "",
  "video_trailer": "",
  "stream_embed": "",
  "poster_id": 42,
  "gallery": [43, 44]
}
```

**Обязательные для `submit`:** `title`, `start_date`, `city` (проверяются при `submit`).

**Ответ 201:**
```json
{
  "success": true,
  "data": { /* полный объект события см. GET /organizer/events/{id} */ },
  "message": "Черновик создан."
}
```

### 1.3 Одно событие
**GET** `/organizer/events/{id}`

**Ответ 200:**
```json
{
  "success": true,
  "data": { /* полный объект события */ },
  "message": ""
}
```

### 1.4 Обновление (draft/rejected)
**PUT** `/organizer/events/{id}`

Тело запроса — те же поля, что и при создании (частичное обновление поддерживается).
Доступно только для статусов `draft` и `rejected`.

### 1.5 Удаление (draft/rejected)
**DELETE** `/organizer/events/{id}`

Доступно только для статусов `draft` и `rejected`.

**Ответ 200:**
```json
{
  "success": true,
  "data": { "id": 123 },
  "message": "Событие удалено."
}
```

### 1.6 Отправка на модерацию
**POST** `/organizer/events/{id}/submit`

Требует заполненных обязательных полей: `title`, `start_date`, `city`.

**Ответ 200:**
```json
{
  "success": true,
  "data": { /* событие со статусом pending */ },
  "message": "Событие отправлено на модерацию."
}
```

### 1.7 Статистика
**GET** `/organizer/stats`

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "counts": {
      "draft": 5,
      "pending": 2,
      "publish": 8,
      "rejected": 1
    },
    "total": 16,
    "views": 1250,
    "clicks": 320,
    "days": 30
  },
  "message": ""
}
```

---

## 2. Площадки организатора

### 2.1 Список площадок
**GET** `/organizer/venues`

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 45,
        "title": "Зал «Горизонт»",
        "content": "Описание зала",
        "city": [15],
        "poster_id": 42,
        "edit_url": "https://example.com/wp-admin/post.php?post=45&action=edit",
        "venue_type": "theater",
        "venue_address": "ул. Ленина, 1",
        "venue_phone": "+7 (999) 123-45-67",
        "venue_email": "hall@example.com",
        "venue_website": "https://hall.example.com",
        "venue_capacity": "500",
        "venue_lat": "55.7558",
        "venue_lng": "37.6176",
        "is_own": true
      }
    ],
    "total": 10
  },
  "message": ""
}
```

### 2.2 Создание площадки
**POST** `/organizer/venues`

**Обязательные поля:** `title`, `city`, `venue_address`.

**Ответ 201:**
```json
{
  "success": true,
  "data": { /* объект площадки */ },
  "message": "Площадка создана."
}
```

### 2.3 Одна площадка
**GET** `/organizer/venues/{id}`

### 2.4 Обновление площадки
**PUT** `/organizer/venues/{id}`

### 2.5 Удаление площадки
**DELETE** `/organizer/venues/{id}`

**Ошибка 409**, если площадка используется в событиях.

---

## 3. Профиль организатора

### 3.1 Профиль
**GET** `/organizer/profile`

**Ответ 200:**
```json
{
  "success": true,
  "data": {
    "org_name": "ООО «Афиша»",
    "contact_name": "Иван Иванов",
    "phone": "+7 (999) 123-45-67",
    "documents": [45, 46],
    "verified": false
  },
  "message": ""
}
```

### 3.2 Обновление профиля
**PUT** `/organizer/profile`

**Поля:** `org_name`, `contact_name`, `phone`, `documents` (массив ID вложений), `legal_type`, `inn`.

---

## Примеры запросов

### cURL: создать событие
```bash
curl -X POST "https://idupogorodu.ru/wp-json/idpg/v1/organizer/events" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  -d '{
    "title": "Новый концерт",
    "city": 15,
    "start_date": "2026-10-15 19:00:00",
    "is_free": "yes"
  }'
```

### JavaScript (fetch)
```js
fetch('/wp-json/idpg/v1/organizer/events', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': idpgData.nonce
  },
  body: JSON.stringify({
    title: 'Концерт',
    city: 15,
    start_date: '2026-10-15 19:00:00'
  }),
  credentials: 'same-origin'
}).then(r => r.json());
```

### JavaScript: список событий
```js
async function fetchEvents(page = 1, status = '') {
  const nonce = idpgData.restNonce;
  const params = new URLSearchParams({ page, per_page: 20 });
  if (status) params.set('status', status);
  const res = await fetch(`/wp-json/idpg/v1/organizer/events?${params}`, {
    headers: { 'X-WP-Nonce': idpgData.restNonce },
    credentials: 'same-origin'
  });
  return res.json();
}
```

---

## Коды ошибок (summary)

| Код | HTTP | Ситуация |
|-----|------|----------|
| `idpg_org_nonce_failed` | 403 | Неверный/отсутствующий nonce |
| `idpg_org_not_logged_in` | 401 | Не авторизован |
| `idpg_org_forbidden` | 403 | Нет роли организатора |
| `idpg_org_forbidden_owner` | 403 | Не владелец ресурса |
| `idpg_org_event_not_found` | 404 | Событие не найдено |
| `idpg_org_event_locked` | 409 | На модерации (pending) |
| `idpg_org_event_not_editable` | 403 | Нельзя править (publish/pending) |
| `idpg_org_event_locked` | 409 | На модерации (pending) |
| `idpg_org_submit_incomplete` | 400 | Не заполнены обязательные поля для submit |
| `idpg_org_venue_in_use` | 409 | Площадка используется в событиях |
| `idpg_org_profile_empty` | 400 | Пустое тело запроса профиля |

---

## Версионирование

- **v1.0** (07.10.2026) — текущая спецификация (DEV-261).
- Изменения, ломающие контракт, требуют минорной версии (1.1, 1.2...).
- Основные изменения: новые поля в ответе, новые параметры запроса — добавляются без breaking changes.

---

## Ссылки

- **Исходный код:** `plugins/idpg-core/includes/class-rest-organizer.php`
- **Тесты:** `plugins/idpg-core/tests/test-rest-organizer.php`, `test-rest-organizer-api.php`
- **Фронтенд (будет переписан):** `assets/js/organizer.js` (удалён в DEV-234, будет написан заново)
- **Связанные задачи:** DEV-42 (REST события), DEV-50 (площадки), DEV-261 (этот документ)