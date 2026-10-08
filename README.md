# ИДПОГОРОДУ — дизайн-система

Портал-афиша «Иду по городу» (idupogorodu.ru). Этот файл описывает **дизайн-систему
темы `idpg_core`**: токены, типографику, сетку, компоненты и правила сборки стилей.

Устройство проекта (CPT, таксономии, REST, безопасность) — `docs/DOCUMENTATION.md`;
что реализовано и что делать — `docs/PROJECT.md`; выкладка — `docs/PRODUCTION.md`;
REST API организатора — `docs/API.md`;
правила работы с кодом — `AGENTS.md`.

Источник истины — код темы. Списки и числа ниже сверены с деревом 07.10.2026.

## 1. Где живут стили

| Файл | Что содержит |
|---|---|
| `themes/idpg_core/assets/scss/base/_tokens.scss` | CSS-токены `--idpg-*` в `:root` (101 запись: цвета, отступы, радиусы) + `@font-face` Gilroy |
| `themes/idpg_core/assets/scss/base/_variables.scss` | SCSS-переменные — тонкий слой над токенами |
| `themes/idpg_core/assets/scss/base/_typography.scss`, `_reset.scss` | Типографика и сброс |
| `themes/idpg_core/assets/scss/style.scss` | Вход сборки: порядок `@use` = порядок каскада |
| `themes/idpg_core/style.css` | Собранный результат (компилируется из SCSS; вручную не править) |
| `themes/idpg_core/assets/css/templates.css` | Шаблонные блоки страниц: сетки, архивы, избранное, уведомления. Пишется вручную, подключается после `style.css` |
| `themes/idpg_core/assets/css/subscribe.css`, `editor-style.css`, `widget-areas-admin.css` | Дополнительные слои: подписка, редактор блоков, админка областей виджетов |
| `themes/idpg_core/theme.json` | Настройки редактора блоков (палитра, шрифт, ширины) |

Партиалы `pages/`: `_auth.scss`, `_auth-dev85.scss`. Мёртвые удалены:
`_cabinet.scss` (DEV-253, 0 вхождений `cabinet__` в шаблонах),
`_dashboard.scss` (DEV-197), `_create-event.scss`/`_venue-form.scss`
(DEV-206) — подключения в `style.scss` не восстанавливать.

Фронтовые стили опираются на токены: в `templates.css` — 225 ссылок `var(--idpg-*)`.
Голых hex осталось **192**: `editor-style.css` 29, `widget-areas-admin.css` 91,
`templates.css` 64, `subscribe.css` 8 — план выноса в §12.

## 2. Токены

Полный список — `base/_tokens.scss`, `:root`. Именование: `--idpg-<группа>-<роль>`.
Единственный источник токенов и порядок правок — §11.

Счётчики в этом README ведутся по коду: в `:root` **101** запись, из них 47 —
алиасы (сверено 07.10.2026; комментарий в `_tokens.scss` синхронизирован, DEV-252).

### Цвета

| Группа | Токены |
|---|---|
| Brand | `--idpg-primary #0052ff`, `--idpg-primary-active #003ecc`, `--idpg-primary-disabled #a8b8cc`, `--idpg-primary-bg rgba(0, 82, 255, 0.08)` |
| Поверхности | `--idpg-canvas #ffffff`, `--idpg-surface-soft #f7f7f7`, `--idpg-surface-strong #eef0f3`, `--idpg-surface-dark #0a0b0d`, `--idpg-surface-dark-elevated #16181c` |
| Линии | `--idpg-hairline #dee1e6`, `--idpg-hairline-soft #eef0f3` |
| Текст | `--idpg-ink #0a0b0d`, `--idpg-body #5b616e`, `--idpg-muted #7c828a`, `--idpg-muted-soft #a8acb3` |
| На цвете | `--idpg-on-primary #ffffff`, `--idpg-on-dark #ffffff`, `--idpg-on-dark-soft #a8acb3` |
| Семантика | `--idpg-up #05b169`, `--idpg-down #cf202f`, `--idpg-accent-yellow #f4b000` |
| Уведомления | success `#ecfdf5` / `#065f46`, error `#fef2f2` / `#991b1b`, warning `#fffbeb` / `#92400e` |
| Статусы событий | publish `#dcfce7` / `#166534`, pending `#fef9c3` / `#854d0e`, draft `--idpg-surface-strong` / `--idpg-body` |

47 «обратных совместимости» алиасов (`--idpg-bg`, `--idpg-text`, `--idpg-border`,
`--idpg-card-bg`, …) ссылаются на базовые токены: они оставлены для старого CSS.
**Новые правила пишутся только на базовые токены**, не на алиасы.

### Отступы, радиусы, тени, движение

| | |
|---|---|
| Отступы | `--idpg-space-3xs 2px`, `xxs 4px`, `2xs 6px`, `xs 8px`, `sm 12px`, `base 16px`, `md 20px`, `lg 24px`, `xl 32px`, `xxl 48px`, `section 96px` |
| Радиусы | `--idpg-radius-none 0`, `xs 4px`, `sm 8px`, `md 12px`, `lg 16px`, `xl 24px`, `pill 100px`, `full 9999px` |
| Тени (SCSS) | `$shadow-sm` / `$shadow-md` / `$shadow-lg` / `$shadow-xl`; токен `--idpg-shadow 0 4px 12px var(--idpg-surface-soft)` |
| Переходы | `--idpg-transition 0.2s ease` (базовый), `$transition-normal 0.3s ease` |
| Оверлей | `--idpg-overlay rgba(0, 0, 0, 0.4)` |

### Тёмная тема

Комментарий `/* === CSS Custom Properties: Dark (DeepSeek-inspired) === */` есть
в `_tokens.scss` и `style.css`, но правил после него нет: **тёмной схемы в коде нет**
(нет ни `prefers-color-scheme`, ни `[data-theme]`). Заголовок — заглушка.

## 3. Типографика

- Шрифт — **Gilroy**, локальный `fonts/gilroy-regular.ttf` (начертание 400),
  `font-display: swap`. Основной стек: `'Gilroy', -apple-system, BlinkMacSystemFont,
  'Segoe UI', Roboto, sans-serif`. Переменная `$font-family-display` намеренно
  усечена до `'Gilroy', sans-serif` — логотип в шапке нарисован только Gilroy,
  и системная подстановка ломала бы начертание.
- База: `16px`, `line-height 1.5`.
- Заголовки h1–h6: вес **400**, `line-height 1.1`, `letter-spacing -0.02em`
  (те же значения продублированы в `theme.json`).
- Кегли в CSS — литералы: `14px` (`0.875rem`), `16px`, `18px` (`1.125rem`),
  в списках также `0.95rem`.
- Поля ввода: `font-size: 16px` — специально, чтобы iOS не зумил страницу при фокусе
  в поле.

## 4. Сетка и адаптив

| | |
|---|---|
| Контейнер | `.container`: `max-width 1400px`; отступы 16px → 24px (≥768px) → 32px (≥1024px) |
| theme.json | `contentSize 720px`, `wideSize 1400px` |
| Брейкпоинты (SCSS) | `$breakpoint-sm 640px`, `$breakpoint-tablet 768px`, `$breakpoint-desktop 1024px`, `$breakpoint-xl 1280px` |
| Подход | mobile-first: `min-width`-медиазапросы; `responsive.scss` подключается последним и перебивает всё по каскаду |
| Высота шапки | CSS-переменная `--idpg-header-h`, фолбэк в CSS — 72px, фактическое значение проставляет JS через `wp_head` (десктоп — 88px); `.site-main` получает `padding-top: var(--idpg-header-h, 72px)` |
| z-index | шапка 100, мобильная навигация 105, дропдауны 200 |

## 5. Компоненты

### Именование

- Глобальные блоки — с префиксом `.idpg-`: `.idpg-btn`, `.idpg-events-grid`, `.idpg-notice`.
- Блочные — по имени страницы или сущности: `.auth`, `.container`.
- BEM: `block__element--modifier` — `.auth__input`, `.idpg-btn--primary`, `.auth__tab--active`.

### Карта компонентов

| Компонент | Файл | Что даёт |
|---|---|---|
| Контейнер | `components/_container.scss` | 1400px + адаптивные отступы |
| Кнопки | `components/_buttons.scss` | `.idpg-btn`: inline-flex, `8px 16px`, `0.875rem`, вес 600, радиус-pill, переход `0.2s ease`. `--primary` (`--idpg-primary` → `--idpg-primary-active` на hover), `--secondary` (`--idpg-ink` → `--idpg-surface-dark-elevated`) |
| Шапка и навигация | `components/_header.scss`, `_nav.scss`, `_menu-toggle.scss`, `_bottom-bar.scss` | десктопная шапка; мобильная нижняя панель `bottom-bar` (медиа ≤1023px — подключается ПОСЛЕ header, порядок в `style.scss` обусловлен каскадом) |
| Выбор города | `components/_city-selector.scss`, `_city-modal.scss` | виджет в шапке + модалка (иерархия область → город → район) |
| Живой поиск | `components/_search.scss` + `assets/js/live-search.js` | поле поиска и выпадающие результаты |
| Сетка главной | `components/_home-main.scss`, `_home-sidebar.scss` | основная колонка и сайдбар главной |
| «Последние события» | `components/_recent-events.scss` | вертикальный список с разделителями `1px solid var(--idpg-hairline)`, ссылки `0.95rem/500`, hover → primary + underline |
| Области виджетов | `components/_widget-areas.scss` | 5 позиций вывода: под шапкой, над контентом, сайдбар события, под контентом, перед футером |
| Подвал | `components/_footer.scss`, `_footer-links.scss` | |
| Дропдаун входа | `components/_auth-dropdown.scss` | меню пользователя в шапке |
| Тач-таргеты | `components/_mobile.scss` | минимальный тап 44×44 (Apple HIG); подключается последним перед адаптивом, чтобы перебивать базовые правила тех же элементов |
| Страницы входа | `pages/_auth.scss`, `pages/_auth-dev85.scss` | табы входа/регистрации, `.auth__input` (100%, `10px 16px`, 16px, рамка hairline), «глаз» показа пароля 44×44, соцвход |
| Шаблонные блоки | `assets/css/templates.css` | `.idpg-events-grid` (grid, 3 колонки, gap 24px; модификаторы `--3`, `--v`), `.idpg-notice--info`, `.idpg-archive-header` — архивы и страница избранного |

**Мёртвые партиалы удаляются по карточкам.** `pages/_cabinet.scss` удалён в
**DEV-253**: 0 вхождений `cabinet__` в шаблонах и скриптах, после пересборки
`.cabinet*` в `style.css` = 0. Ранее тем же способом удалены
`pages/_dashboard.scss` (DEV-197) и `pages/_create-event.scss`,
`pages/_venue-form.scss` (DEV-206). Подключение партиала в `style.scss`
возвращало бы правила обратно.

### Статусы и бейджи

Цвета — токены статусов (§2). Форма бейджа: радиус-pill, `padding: 2px 8px`.

## 6. Доступность

- Фокус: `:focus-visible` → `outline: 2px solid var(--idpg-primary); outline-offset: 2px`
  (кнопки, ссылки — `base/_typography.scss`, `components/_buttons.scss`).
- Тач-таргеты ≥ 44×44 (`components/_mobile.scss`).
- `.screen-reader-text` — визуально скрытый текст для скринридеров (`style.scss`).
- Базовый контраст: `--idpg-ink` на `--idpg-canvas`, вторичный текст `--idpg-body`.

## 7. Правила написания стилей

1. **Только токены.** Цвет — `var(--idpg-*)` или SCSS-переменная `$color-*`.
   Голый hex во фронтовых стилях — запрет (см. §1: редактор и админка ещё не выведены).
2. **Порядок `@use` в `style.scss` = порядок каскада.** Не переставлять без
   перепроверки: переменные → токены → base → components → pages → mobile → responsive.
   Комментарии в `style.scss` объясняют, почему порядок именно такой (например,
   `bottom-bar` обязан идти после `header`).
3. **Mobile-first:** `min-width`-медиазапросы; `responsive.scss` всегда последний.
4. Переходы — `var(--idpg-transition)` (`0.2s ease`), если не описана иная причина.
5. Новый компонент — новый партиал в `components/` + `@use` в `style.scss`
   в конце соответствующего блока.
6. `style.css` — собранный файл: править партиалы и пересобирать, не редактировать руками.

## 8. Сборка и проверка

`themes/idpg_core/package.json`:

| Команда | Что делает |
|---|---|
| `npm run watch` | `sass --watch assets/scss/style.scss:style.css` |
| `npm run compile:css:apply` | собрать `style.css` |
| `npm run compile:css:temp` + `npm run check:css` | собрать во временный файл и сверить с `style.css` скриптом `bin/check-css-build.mjs` — ловит расхождение собранного CSS с исходниками |
| `npm run lint:scss` | stylelint (из зависимостей темы, конфиг `themes/idpg_core/.stylelintrc.json`) по `assets/scss/**` |
| `npm run lint:js` | `wp-scripts lint-js` по `assets/js/*.js` |

Ручные CSS-файлы (`templates.css`, `subscribe.css`, `editor-style.css`,
`widget-areas-admin.css`) в пайплайн SCSS не входят — правятся напрямую.

## 9. Редактор блоков (theme.json)

- Палитра: `#0052ff` (primary), `#0a0b0d` (secondary и text), `#ffffff` (background).
- Типографика редактора: Gilroy 400 / 16px / 1.5; заголовки 400 / -0.02em / 1.1.
- Ширины: content 720px, wide 1400px.

## 10. Что НЕ делать

1. **НЕ использовать голый hex во фронтовых стилях.** Только `var(--idpg-*)`
   или SCSS-переменная `$color-*` (§7.1). Исключение — файлы на миграции (§12);
   новых hex в них не добавлять.
2. **НЕ переставлять `@use` в `style.scss` без проверки каскада** (§7.2):
   порядок файлов задаёт приоритет правил (`bottom-bar` после `header`,
   `mobile`/`responsive` последними). После любого изменения — пересборка
   (`npm run compile:css:apply`) и визуальная проверка.
3. **НЕ править `style.css` руками** (§8): файл собирается из SCSS, ручная
   правка затирается следующей сборкой и ловится `npm run check:css`.
4. **НЕ писать новые правила на алиасы** (`--idpg-bg`, `--idpg-text`,
   `--idpg-border`, …): 47 алиасов оставлены только для старого CSS,
   новое — исключительно на базовые токены (§2).
5. **НЕ прятать honeypot-поля форм через `display:none`** — спам-боты
   отсеивают такие поля и honeypot перестаёт работать; скрывать
   off-screen/visually-hidden-механикой, поле остаётся в DOM.

## 11. Источник токенов

- **`themes/idpg_core/assets/scss/base/_tokens.scss`, `:root` — единственный
  источник** токенов `--idpg-*` (цвета, отступы, радиусы). Все остальные места —
  производные:
  - `base/_variables.scss` — SCSS-обёртки над `var(--idpg-*)`, не самостоятельные значения;
  - `theme.json` — дублирует палитру и типографику редактора (§9);
  - `assets/css/*.css` — потребители через `var(--idpg-*)`.
- **Порядок правки при добавлении/удалении токена:**
  1. сначала `_tokens.scss` — добавить/удалить запись в `:root`;
  2. пересобрать — `npm run compile:css:apply`;
  3. синхронизировать этот README (§1, §2, таблицы);
  4. если токен попадает в палитру редактора — обновить `theme.json`.
- Числа в README сверяются по коду, а не по комментариям в SCSS (§2: по `:root`
  на 07.10.2026 — 101 токен, 47 алиасов). Комментарии SCSS и документы
  синхронизированы в DEV-252/256.
- REST API организатора (namespace `idpg/v1`) — контракт и примеры
  в `docs/API.md` (DEV-261/264).

## 12. Миграция на токены (план)

Состояние на 07.10.2026 — голых hex в CSS темы (без `vendor/`) **192**:

| Файл | hex | Уникальных | Эшелон |
|---|---|---|---|
| `assets/css/editor-style.css` | 29 | 11 | 1 |
| `assets/css/widget-areas-admin.css` | 91 | 35 | 1 |
| `assets/css/templates.css` | 64 | 21 | 2 |
| `assets/css/subscribe.css` | 8 | 6 | 2 |

**Эшелон 1 — редактор и админка (120 hex: 29 + 91):**

1. Выгрузить уникальные значения, сопоставить с токенами `base/_tokens.scss` (§2):
   совпадение → `var(--idpg-*)`; если цвета среди токенов нет — сначала завести
   токен в `_tokens.scss` (§11), потом использовать.
2. `editor-style.css`: подключить `:root`-токены в контекст редактора
   (`add_editor_style` + общий слой) и заменить 29 hex; визуальная проверка блоков.
3. `widget-areas-admin.css`: подключить токены на странице админки областей
   виджетов и заменить 91 hex; проверка админки.
4. **Приёмка эшелона:** grep hex в этих файлах = 0; визуальная сверка
   редактора и админки не изменилась.

**Эшелон 2 — фронтовые остатки (72 hex: 64 + 8):**

5. `templates.css`: 225 ссылок на токены уже есть, оставшиеся 64 hex свести
   к существующим токенам (большинство — дубли brand/status-цветов).
6. `subscribe.css`: 8 hex → токены.
7. **Приёмка эшелона:** 0 hex во всех `assets/css/*.css`, кроме `vendor/`;
   фронт визуально не изменился.

**На время миграции:** новых hex не добавлять ни в один из этих файлов (§10.1).

---

Связанные документы: `docs/PROJECT.md` (статус и задачи), `docs/DOCUMENTATION.md`
(устройство проекта), `docs/PRODUCTION.md` (выкладка), `AGENTS.md` (правила работы с кодом).
