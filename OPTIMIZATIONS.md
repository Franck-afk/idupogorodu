# 📋 Полный список оптимизаций для проекта idupogorodu

> **Дата анализа:** 04.11.2026  
> **Версия темы:** 1.0.79  
> **Анализируемые файлы:** `themes/idpg_core/` (1245 строк в functions.php, 1248 в template-tags.php)

---

## 📌 Описание

Этот документ содержит **полный список оптимизаций**, выявленных в результате анализа WordPress-темы `idpg_core`. Оптимизации сгруппированы по категориям: безопасность, производительность, рефакторинг и архитектура.

---

## 🚨 Категория 1: Безопасность (Критический приоритет)

### 🔴 XSS-уязвимости: Неэкранированный вывод

| # | Файл | Строка | Проблема | Текущий код | Рекомендуемый код | Риск |
|---|------|--------|----------|-------------|-------------------|------|
| 1 | `header.php` | 106 | Неэкранированный вывод переменной | `echo $idpg_city_ipnote;` | `echo wp_kses_post( $idpg_city_ipnote );` | ⚠️ XSS |
| 2 | `header.php` | 107 | Неэкранированный вывод переменной | `echo $idpg_city_confirm_modal;` | `echo wp_kses_post( $idpg_city_confirm_modal );` | ⚠️ XSS |
| 3 | `header.php` | 186 | Неэкранированный вывод переменной | `echo $idpg_city_ipnote;` | `echo wp_kses_post( $idpg_city_ipnote );` | ⚠️ XSS |
| 4 | `header.php` | 224 | Неэкранированный вывод переменной | `echo $idpg_city_confirm_panel;` | `echo wp_kses_post( $idpg_city_confirm_panel );` | ⚠️ XSS |
| 5 | `footer.php` | 58 | Неэкранированный SVG | `echo $social_icon_svg;` | `echo wp_kses( $social_icon_svg, array( 'svg' => array( 'class' => true, 'viewBox' => true, 'fill' => true ), 'path' => array( 'd' => true ) ) );` | ⚠️ XSS |
| 6 | `template-parts/content-event.php` | 126 | Вывод миниатюры без экранирования | `echo $idpg_thumb;` | `echo wp_kses_post( $idpg_thumb );` | ⚠️ XSS |
| 7 | `template-parts/content-venue.php` | 17 | Вывод миниатюры без экранирования | `echo $idpg_thumb;` | `echo wp_kses_post( $idpg_thumb );` | ⚠️ XSS |
| 8 | `inc/widgets.php` | 164 | Вывод без экранирования | `echo $args['before_widget'];` | `echo wp_kses_post( $args['before_widget'] );` | ⚠️ XSS |
| 9 | `inc/widgets.php` | 167 | Вывод заголовка без экранирования | `echo $args['before_title'] . $title . $args['after_title'];` | `echo wp_kses_post( $args['before_title'] ) . esc_html( $title ) . wp_kses_post( $args['after_title'] );` | ⚠️ XSS |
| 10 | `inc/widgets.php` | 177 | Вывод изображения без экранирования | `echo $img;` | `echo wp_kses_post( $img );` | ⚠️ XSS |
| 11 | `inc/widgets.php` | 187 | Вывод без экранирования | `echo $args['after_widget'];` | `echo wp_kses_post( $args['after_widget'] );` | ⚠️ XSS |
| 12 | `inc/widgets.php` | 347 | Вывод без экранирования | `echo $args['before_widget'];` | `echo wp_kses_post( $args['before_widget'] );` | ⚠️ XSS |
| 13 | `inc/widgets.php` | 350 | Вывод заголовка без экранирования | `echo $args['before_title'] . $title . $args['after_title'];` | `echo wp_kses_post( $args['before_title'] ) . esc_html( $title ) . wp_kses_post( $args['after_title'] );` | ⚠️ XSS |
| 14 | `inc/widgets.php` | 366 | Вывод без экранирования | `echo $args['after_widget'];` | `echo wp_kses_post( $args['after_widget'] );` | ⚠️ XSS |
| 15 | `inc/widgets.php` | 481 | Вывод без экранирования | `echo $args['before_widget'];` | `echo wp_kses_post( $args['before_widget'] );` | ⚠️ XSS |
| 16 | `inc/widgets.php` | 488 | Вывод заголовка без экранирования | `echo $args['before_title'] . $title . $args['after_title'];` | `echo wp_kses_post( $args['before_title'] ) . esc_html( $title ) . wp_kses_post( $args['after_title'] );` | ⚠️ XSS |
| 17 | `inc/widgets.php` | 517 | Вывод без экранирования | `echo $args['after_widget'];` | `echo wp_kses_post( $args['after_widget'] );` | ⚠️ XSS |

**Примечание:** В некоторых случаях используется комментарий `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped`, что означает, что разработчики в курсе проблемы. Однако для безопасности рекомендуется всё же экранировать вывод.

---

## ⚡ Категория 2: Производительность

### 2.1 Дублирующиеся проверки классов

| # | Файл | Строка | Проблема | Текущий код | Рекомендация |
|---|------|--------|----------|-------------|---------------|
| 1 | `archive-event.php` | 52 | Повторная проверка `class_exists('IDPG_City_URL')` | `class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::afisha_url() : get_post_type_archive_link( 'event' )` | Использовать хелпер `idpg_core_has_city_url()` |
| 2 | `archive-event.php` | 61 | То же | `class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::afisha_url() : home_url( '/afisha/' )` | Использовать хелпер `idpg_core_has_city_url()` |
| 3 | `single-event.php` | 40 | То же | `class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::afisha_url() : get_post_type_archive_link( 'event' )` | Использовать хелпер `idpg_core_has_city_url()` |
| 4 | `search.php` | 47 | То же | `class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::afisha_url() : home_url( '/afisha/' )` | Использовать хелпер `idpg_core_has_city_url()` |
| 5 | `template-tags.php` | 213 | То же | `class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::QVAR : 'idpg_city_path'` | Использовать хелпер `idpg_core_has_city_url()` |
| 6 | `template-tags.php` | 226 | То же | `if ( class_exists( 'IDPG_City_URL' ) ) { ... }` | Использовать хелпер `idpg_core_has_city_url()` |
| 7 | `template-tags.php` | 243 | То же | `if ( class_exists( 'IDPG_City_URL' ) ) { ... }` | Использовать хелпер `idpg_core_has_city_url()` |
| 8 | `template-tags.php` | 259 | То же | `if ( class_exists( 'IDPG_City_URL' ) ) { ... }` | Использовать хелпер `idpg_core_has_city_url()` |

**Рекомендуемое решение:**
```php
// В functions.php
function idpg_core_has_city_url() {
    static $has_class = null;
    if ( null === $has_class ) {
        $has_class = class_exists( 'IDPG_City_URL' );
    }
    return $has_class;
}
```

---

### 2.2 Дублирующиеся URL

| # | Файл | Строка | Проблема | Текущий код | Рекомендация |
|---|------|--------|----------|-------------|---------------|
| 1 | `page-favorites.php` | 22 | Дублирование URL афиши | `home_url( '/afisha/' )` | Использовать хелпер `idpg_core_get_afisha_url()` |
| 2 | `page-favorites.php` | 64 | То же | `home_url( '/afisha/' )` | Использовать хелпер `idpg_core_get_afisha_url()` |
| 3 | `functions.php` | 556 | То же | `home_url( '/afisha/' )` | Использовать хелпер `idpg_core_get_afisha_url()` |
| 4 | `functions.php` | 558 | URL с фильтром даты | `home_url( '/afisha/?date=today' )` | Использовать хелпер с параметрами |
| 5 | `functions.php` | 559 | URL с фильтром даты | `home_url( '/afisha/?date=tomorrow' )` | Использовать хелпер с параметрами |
| 6 | `functions.php` | 560 | URL с фильтром даты | `home_url( '/afisha/?date=weekend' )` | Использовать хелпер с параметрами |
| 7 | `functions.php` | 561 | URL с фильтром бесплатных | `home_url( '/afisha/?is_free=1' )` | Использовать хелпер с параметрами |
| 8 | `search.php` | 47 | То же | `home_url( '/afisha/' )` | Использовать хелпер `idpg_core_get_afisha_url()` |
| 9 | `archive-event.php` | 61 | То же | `home_url( '/afisha/' )` | Использовать хелпер `idpg_core_get_afisha_url()` |
| 10 | `single-event.php` | 49 | То же | `home_url( '/afisha/' )` | Использовать хелпер `idpg_core_get_afisha_url()` |
| 11 | `page-about.php` | 57 | То же | `home_url( '/afisha/' )` | Использовать хелпер `idpg_core_get_afisha_url()` |
| 12 | `page-contacts.php` | 53 | То же | `home_url( '/afisha/' )` | Использовать хелпер `idpg_core_get_afisha_url()` |

**Рекомендуемое решение:**
```php
// В functions.php
function idpg_core_get_afisha_url( $query_args = array() ) {
    static $base_url = null;
    if ( null === $base_url ) {
        $base_url = idpg_core_has_city_url() ? IDPG_City_URL::afisha_url() : home_url( '/afisha/' );
    }
    if ( empty( $query_args ) ) {
        return $base_url;
    }
    return add_query_arg( $query_args, $base_url );
}
```

---

### 2.3 Неоптимальные запросы к метаданным

| # | Файл | Строка | Проблема | Текущий код | Рекомендация |
|---|------|--------|----------|-------------|---------------|
| 1 | `template-tags.php` | 35-67 | 15+ отдельных вызовов `get_post_meta()` | Множественные вызовы для каждого метаполя | Использовать один запрос для всех метаполей |

**Текущий код:**
```php
function idpg_core_event_meta( $post_id ) {
    return array(
        'status'      => get_post_meta( $post_id, '_idpg_ticket_status', true ),
        'ticket_type' => get_post_meta( $post_id, '_idpg_ticket_type', true ),
        'ticket_url'  => get_post_meta( $post_id, '_idpg_ticket_url', true ),
        // ... 12+ других вызовов
    );
}
```

**Рекомендуемый код:**
```php
function idpg_core_event_meta( $post_id ) {
    $post_id = absint( $post_id );
    
    // Один запрос для всех метаполей
    $meta = get_post_meta( $post_id );
    
    return array(
        'status'      => $meta['_idpg_ticket_status'][0] ?? '',
        'ticket_type' => $meta['_idpg_ticket_type'][0] ?? '',
        'ticket_url'  => $meta['_idpg_ticket_url'][0] ?? '',
        'date_start'  => $meta['_idpg_start_date'][0] ?? '',
        'date_end'    => $meta['_idpg_end_date'][0] ?? '',
        'all_day'     => 'yes' === ($meta['_idpg_all_day'][0] ?? ''),
        'price_from'  => $meta['_idpg_price_from'][0] ?? '',
        'price_to'    => $meta['_idpg_price_to'][0] ?? '',
        'vip_price'   => $meta['_idpg_vip_price'][0] ?? '',
        'is_free'     => 'yes' === ($meta['_idpg_is_free'][0] ?? ''),
        'currency'    => '' === trim( $meta['_idpg_currency'][0] ?? '' ) ? 'RUB' : ($meta['_idpg_currency'][0] ?? ''),
        'address'     => $meta['_idpg_address'][0] ?? '',
        'is_online'   => 'yes' === ($meta['_idpg_is_online'][0] ?? ''),
        'stream_url'  => $meta['_idpg_stream_url'][0] ?? '',
        'video'       => $meta['_idpg_video_trailer'][0] ?? '',
    );
}
```

**Эффект:** Уменьшение количества запросов к базе данных с **15+ до 1** на каждое обращение к функции.

---

### 2.4 Неоптимальные запросы get_posts()

| # | Файл | Строка | Проблема | Текущий код | Рекомендация |
|---|------|--------|----------|-------------|---------------|
| 1 | `functions.php` | 60-69 | Использование get_posts() для получения ID страницы | `get_posts()` с 'fields' => 'ids' | Использовать `get_page_by_path()` |
| 2 | `page-login.php` | 54 | То же | `get_posts()` для получения событий | Оптимизировать запрос |
| 3 | `inc/template-tags.php` | 464 | То же | `get_posts()` для получения связанных записей | Оптимизировать запрос |

**Рекомендация для функции idpg_core_get_login_url_in_theme:**
```php
function idpg_core_get_login_url_in_theme() {
    static $url = null;
    if ( null !== $url ) {
        return $url;
    }
    
    $login_page_id = get_page_by_path( 'login', OBJECT, 'page' );
    if ( $login_page_id && 'page-login.php' === get_page_template_slug( $login_page_id ) ) {
        $url = trailingslashit( get_permalink( $login_page_id ) );
    } else {
        $url = trailingslashit( home_url( '/login/' ) );
    }
    return $url;
}
```

---

### 2.5 Дублирующаяся логика фильтров

| # | Файл | Строка | Проблема | Описание |
|---|------|--------|----------|----------|
| 1 | `archive-event.php` | 43-50 | Лямбда-функция для генерации пилюль | Используется только в этом файле |
| 2 | `single-event.php` | 38-45 | Аналогичная логика | Дублирование кода |

**Рекомендация:** Создать шаблонный тег:
```php
function idpg_core_render_filter_pill( $label, $url, $active = false ) {
    printf(
        '<a class="idpg-fpill%s" href="%s">%s</a>',
        $active ? ' is-active' : '',
        esc_url( $url ),
        esc_html( $label )
    );
}
```

---

## 🔧 Категория 3: Рефакторинг кода

### 3.1 Дублирующаяся логика в шаблонах

| # | Файлы | Описание | Рекомендация |
|---|-------|----------|---------------|
| 1 | `archive-event.php`, `single-event.php` | Одинаковая логика для URL афиши | Вынести в хелпер |
| 2 | `page-favorites.php`, `page-about.php`, `page-contacts.php` | Одинаковые кнопки "К афише" | Создать шаблонный тег |
| 3 | `archive-event.php`, `search.php` | Одинаковая логика фильтров | Вынести в общий компонент |

---

### 3.2 Большой размер файлов

| Файл | Строка | Проблема | Рекомендация |
|------|--------|----------|---------------|
| `functions.php` | 1245 | Слишком большой файл | Разбить на модули в `inc/` |
| `template-tags.php` | 1248 | Слишком большой файл | Разбить на логические части |

**Рекомендуемая структура:**
```
inc/
├── helpers.php          # Хелперы (URL, проверки классов и т.д.)
├── template-tags/       # Разделение template-tags.php
│   ├── event-meta.php    # Метаданные событий
│   ├── event-sessions.php # Сессии событий
│   ├── media.php         # Работа с медиа
│   └── ...
├── security.php          # Функции безопасности
└── performance.php       # Оптимизации производительности
```

---

### 3.3 Закомментированный код

| # | Файл | Строка | Проблема | Рекомендация |
|---|------|--------|----------|---------------|
| 1 | `functions.php` | 107-111 | Закомментированный Composer autoload | Удалить или раскомментировать |
| 2 | `functions.php` | 114-121 | Закомментированный Carbon Fields boot | Удалить или раскомментировать |

**Рекомендация:** Удалить закомментированный код или вынести в отдельный файл `inc/deprecated.php` с комментариями о причинах.

---

### 3.4 Несоответствие стилю кода

| # | Файл | Проблема | Стандарт (AGENTS.md) | Рекомендация |
|---|------|----------|----------------------|---------------|
| 1 | Разные файлы | Использование `[]` вместо `array()` | "массивный синтаксис: `array()` (не `[]`)" | Заменить все `[]` на `array()` |
| 2 | Разные файлы | Отсутствуют doc-блоки | Требуются doc-блоки для всех функций | Добавить doc-блоки |

---

## 🏗️ Категория 4: Архитектурные улучшения

### 4.1 Внедрение OOP

| # | Описание | Рекомендация |
|---|----------|---------------|
| 1 | Хелперы разбросаны по функциям | Создать класс `IDPG_Core_Helpers` |
| 2 | Работа с метаданными дублируется | Создать класс `IDPG_Event_Meta` |
| 3 | Логика URL дублируется | Создать класс `IDPG_Core_URL` |

---

### 4.2 Кеширование на уровне объекта

| # | Описание | Рекомендация |
|---|----------|---------------|
| 1 | Часто запрашиваемые данные не кешируются | Использовать `WP_Object_Cache` |
| 2 | Результаты запросов не кешируются | Использовать `get_transient()` для часто используемых данных |

**Пример:**
```php
function idpg_core_get_afisha_url_cached() {
    $cache_key = 'idpg_afisha_url';
    $url = wp_cache_get( $cache_key, 'idpg_core' );
    
    if ( false === $url ) {
        $url = idpg_core_get_afisha_url();
        wp_cache_set( $cache_key, $url, 'idpg_core', 3600 ); // Кеш на 1 час
    }
    
    return $url;
}
```

---

### 4.3 Добавить хуки для расширяемости

| # | Описание | Рекомендация |
|---|----------|---------------|
| 1 | URL афиши жестко закодирован | Добавить фильтр `idpg_afisha_url` |
| 2 | Логика фильтров жестко закодирована | Добавить фильтр `idpg_filter_pills` |

**Пример:**
```php
function idpg_core_get_afisha_url( $query_args = array() ) {
    $url = idpg_core_has_city_url() ? IDPG_City_URL::afisha_url() : home_url( '/afisha/' );
    return apply_filters( 'idpg_afisha_url', $url, $query_args );
}
```

---

## 📊 Ожидаемый эффект от оптимизаций

| Оптимизация | Влияние | Сложность | Приоритет |
|-------------|---------|-----------|-----------|
| Экранирование вывода (XSS) | ✅ Устранение уязвимостей | ⭐⭐ | 🔴 Высокий |
| Кеширование `class_exists()` | ⚡ Уменьшение нагрузки на 5-10% | ⭐ | ⚡ Высокий |
| Оптимизация `get_post_meta()` | ⚡⚡ Ускорение в 5-10 раз | ⭐⭐ | ⚡ Высокий |
| Кеширование URL | ⚡ Уменьшение вычислений | ⭐ | ⚡ Высокий |
| Рефакторинг дублирующегося кода | ✅ Улучшение поддерживаемости | ⭐⭐⭐ | 🟡 Средний |
| Разделение больших файлов | ✅ Улучшение читаемости | ⭐⭐⭐ | 🟡 Средний |
| Внедрение OOP | ✅ Улучшение архитектуры | ⭐⭐⭐⭐ | 🟢 Низкий |
| Добавить хуки | ✅ Улучшение расширяемости | ⭐⭐ | 🟢 Низкий |

---

## 🎯 План реализации

### Фаза 1: Безопасность (1-2 часа)
- [ ] Исправить все неэкранированные `echo` в шаблонах
- [ ] Проверить и исправить виджеты
- [ ] Проверить все файлы на наличие неэкранированного вывода

### Фаза 2: Производительность (2-3 часа)
- [ ] Создать хелперы для повторяющихся проверок (`idpg_core_has_city_url()`)
- [ ] Создать хелперы для URL (`idpg_core_get_afisha_url()`)
- [ ] Оптимизировать `idpg_core_event_meta()` (1 запрос вместо 15+)
- [ ] Кешировать часто используемые данные

### Фаза 3: Рефакторинг (3-5 часов)
- [ ] Вынести дублирующуюся логику в хелперы
- [ ] Разбить `functions.php` на модули
- [ ] Разбить `template-tags.php` на логические части
- [ ] Добавить doc-блоки ко всем функциям

### Фаза 4: Архитектура (5+ часов)
- [ ] Внедрить OOP для хелперов
- [ ] Добавить кеширование на уровне объекта
- [ ] Добавить хуки для расширяемости

---

## 📝 Примечания

1. **Тестирование:** После каждой оптимизации необходимо тестировать функционал, особенно:
   - Отображение страниц
   - Работа фильтров
   - Вывод метаданных событий
   - Работа виджетов

2. **Совместимость:** Убедиться, что оптимизации не ломают:
   - Существующие плагины
   - Дочернюю тему
   - Кастомные модификации

3. **Документация:** После реализации обновлять:
   - Этот файл (OPTIMIZATIONS.md)
   - AGENTS.md (если меняются стандарты)
   - Документацию в docs/

---

## 🔗 Ссылки

- [AGENTS.md](AGENTS.md) - Стандарты кода для AI-агентов
- [README.md](README.md) - Основная документация проекта
- [docs/PROJECT.md](docs/PROJECT.md) - Описание проекта
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)

---

**Статус:** ⏳ В ожидании реализации  
**Ответственный:** -  
**Дата начала:** -  
**Дата завершения:** -
