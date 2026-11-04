# Статус ТЗ: реализовано / не реализовано

**Обновлено:** 03.11.2026
**Версии:** `plugins/idpg-core` 1.1.2, `plugins/idpg-auth` 1.0.0, `themes/idpg_core` 1.0.79, `themes/idpg_core-child` 1.0.0
**Источники:** `docs/PROJECT.md`, `PROJECT_ANALYSIS.md`, `docs/specs/`, `.workbuddy-ai/memory/`, git (`origin`: `github.com/Franck-afk/idupogorodu`).

> **⚠️ Уточнение 04.11.2026 (DEV-312).** Строка §2.1 про DEV-235
> («`/organizer/*` закрыт … 404») — историческая, она фиксирует состояние на
> 07.10.2026. **Кабинет организатора восстановлен** в объёме чтения: роутер
> включён, `page-organizer.php` + 4 раздела, меню и ассеты вернулись в тему.
> Формы события/площадки не возвращены (DEV-313).
> Актуально — `docs/PROJECT.md` §6.0 и `docs/specs/DEV-312.md`.

## 1. Метод

- ТЗ — карточки `DEV-*` (`docs/PROJECT.md`, разделы 1–11).
- Статус: реализовано / не реализовано / частично.
- Приёмка — по критериям раздела 3 спецификации + обратная проба (mutation).
- Документация обновляется в том же коммите, что и код.

## 2. Что реализовано

### 2.1. Организаторский контур

- DEV-235 (P0, S) — `/organizer/*` закрыт (rewrite, query vars, `template_include`, guard, 404). PHPUnit 663 -> 542, UI 18/18, REST 135/135.
- DEV-72 / DEV-141 / DEV-148 / DEV-150 / DEV-151 / DEV-149 — pending / complete / reset flow реализован.
- DEV-83 / DEV-84 — PHP-fallback форм реализован (nonce, honeypot, rate limit).

### 2.2. REST API организатора

- DEV-42 — CRUD `/organizer/events`, submit: реализовано (GAP G6 закрыт через DEV-42).
- DEV-46 — moderation/rejection: реализовано.
- DEV-261 / DEV-266 — контракт списков `{items,total,pages}`, query-параметры, пагинация, city fixture: реализовано (31/31 REST organizer API; full suite 545/1458/0 OK).

### 2.3. Модерация и промо

- DEV-10 — промо `idpg_pin_expires`: реализовано.
- DEV-22 — промо-логика: реализовано (DEV-22, 07.10.2026).

### 2.4. Приватность / гео / мета

- DEV-288 — модуль 152-ФЗ: реализовано.
- DEV-305 — локальная БД IP -> регион (242-ФЗ): реализовано.
- DEV-308 / DEV-309 / DEV-310 / DEV-311 — реализовано (карточки визуальной сверки, привязка `/privacy/`, тесты, удаление Carbon Fields).

### 2.5. Баннер

- DEV-317 (P1, S) — дата через Pikaday, readonly, no-JS: реализовано.
- DEV-317 (P2, S) — банер только из настроек вкладки «Банер на главной»: реализовано (коммит `9a99cdd`).

### 2.6. SEO

- DEV-286 — Yoast metadesc event/venue: реализовано.

### 2.7. Дизайн-система

- DEV-87 / DEV-143 / DEV-252-258 — токены `--idpg-*`, check:css OK; DEV-253 — мёртвый `_cabinet.scss` удалён.

## 3. Что не реализовано / в работе / backlog

| Приоритет | DEV | Требование | Статус |
|---|---|---|---|
| P0 | DEV-02 | Внешний TicketSync API | Не реализовано |
| P1 | DEV-21 | OneTap VK ID | Не реализовано |
| P1 | DEV-140 | AI-промпты организатора | Не реализовано |
| P2 | DEV-09 | Баннеры middle/bottom | Не реализовано |
| P2 | DEV-273 | Clearfy Pro | Не реализовано |
| P2 | DEV-196 / DEV-206 / DEV-223 / DEV-224 / DEV-225 | Организаторский UI L/M | В работе / DEV-225 -> 404 до DEV-234/DEV-235 |

## 4. Gap'ы

- G1-G9, G11, G13 — закрыты через DEV-42/46/72.
- G10 — модерация: закрыта в рамках DEV-10/DEV-22.
- G12 — `default_role = subscriber`: реализовано (DEV-81 / DEV-32).

## 5. Открытые блокеры

1. `docs/API.md` (DEV-264) — не совпадает с фактическим контрактом.
2. `test_admin_access` / `test_admin_sees_all_events` — не реализованы (DEV-261 follow-up).
3. `Array to string conversion` — закрыты частично (gallery/documents/rubrics).

## 6. Результаты проверок

- PHPUnit: `OK (545 tests, 1458 assertions)`; REST organizer API `31/31/0`; DEV-259 — `514/1352/0`.
- PHPCS: clean (baseline DEV-183 / DEV-27A / DEV-27B).
- CSS: `npm run check:css` OK; lint:scss / lint:js - 0.
- `php -l` - 0 ошибок.

## 7. Вывод

Критический путь реализован (доступ, organizer flow, REST, модерация, промо, приватность/гео, баннер, SEO).
Не реализован остаток (внешние API, OneTap, Clearfy, middle/bottom banners, AI).
Открытые вопросы: docs/API.md, admin access-тесты, часть GAP.