# WordPress Coding Standards — idpg_core

## Источники
- https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/
- https://developer.wordpress.org/apis/security/
- https://developer.wordpress.org/apis/security/escaping/
- https://developer.wordpress.org/apis/security/data-validation/

---

## PHP Standards

### General
- Открывающие/закрывающие PHP-теги на отдельных строках (многострочный PHP)
- Никогда не использовать короткие теги `<?=` — только `<?php echo`
- Использовать `require_once`, не `include_once`
- Одинарные кавычки если нет интерполяции, двойные — если есть

### Naming
- Функции, переменные, хуки — `snake_case` (никогда `camelCase`)
- Классы — `Snake_Case` (каждое слово с заглавной)
- Константы — `UPPER_SNAKE_CASE`
- Префикс темы: `idpg_core_`

### Formatting
- Отступы — **табуляция** (не пробелы)
- Фигурные скобки **всегда** обязательны (даже для одиночных операторов)
- Пространства вокруг операторов: `if ( $foo === $bar )`
- Пространства вокруг скобок функций: `function foo( $param )`
- Yoda Conditions: `if ( true === $var )`
- `elseif`, не `else if`
- Длинный синтаксис массивов: `array()`, не `[]`

### Security — Escaping
| Контекст | Функция |
|----------|---------|
| HTML элемент | `esc_html()` |
| HTML атрибут | `esc_attr()` |
| URL в href/src | `esc_url()` |
| Inline JavaScript | `esc_js()` |
| Textarea | `esc_textarea()` |
| Произвольный HTML (посты) | `wp_kses_post()` |
| XML | `esc_xml()` |
| Число | `(int)`, `absint()`, `(float)` |

**Правило: escape на этапе вывода, как можно позже!**

### Security — Validation
- Валидация как можно раньше (до любых действий с данными)
- Использовать safelist (разрешённые значения) вместо blocklist
- Строгое сравнение: `===` и `!==`
- `in_array()` с `true` (строгий режим)
- Никогда не доверять `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER`
- Неиспользованные данные — отбрасывать, не обрабатывать

### Security — Nonce
- Все формы — `wp_nonce_field()` / `wp_verify_nonce()`
- AJAX — `wp_create_nonce()` + `check_ajax_referer()`
- URL с действием — `wp_nonce_url()`

### Security — Capabilities
- Всегда проверять права: `current_user_can()`
- Never trust capability checks from client side

---

## Template Standards

### HTML Structure
- Семантический HTML5: `<header>`, `<nav>`, `<main>`, `<article>`, `<footer>`
- Язык страницы через `language_attributes()`
- `wp_head()` и `wp_footer()` обязательны
- `body_class()` и `post_class()` для всех контейнеров

### Text Domain
- Все строки для перевода: `__( 'text', 'idpg_core' )`
- Вывод с эскейпом: `esc_html__( 'text', 'idpg_core' )`
- Текст domain: `idpg_core`

### Required Template Tags
- `wp_head()` — в `</head>`
- `wp_footer()` — перед `</body>`
- `wp_body_open()` — сразу после `<body>`
- `the_content()` — контент поста/страницы
- `the_title()` — заголовок
- `bloginfo( 'name' )` — название сайта

---

## Checklist

- [ ] Все переменные эскейпятся при выводе
- [ ] Нет прямого доступа к $_GET/$_POST без валидации
- [ ] Nonce проверяется при обработке форм
- [ ] `current_user_can()` проверяется перед действиями
- [ ] Фигурные скобки обязательны
- [ ] Yoda Conditions используются
- [ ] `elseif`, не `else if`
- [ ] Табуляция для отступов
- [ ] `require_once`, не `include_once`
- [ ] Нет коротких PHP-тегов
