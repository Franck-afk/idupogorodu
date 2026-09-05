# IDPG «Иду по городу» — темы и плагины

Репозиторий содержит только собственные темы и плагины WordPress проекта-афиши
«Иду по городу» (`idupogorodu.ru`). Все бизнес-логика, безопасность, REST API
и социальная авторизация — в плагине `idpg-core`; тема отвечает за представление.

## Структура

```
idpg-source/
├── themes/
│   ├── idpg_core          # Основная тема (SCSS → style.css, шаблоны страниц)
│   └── idpg_core-child    # Дочерняя тема (пустой style.css, functions.php)
└── plugins/
    ├── idpg-core          # Ядро: CPT, мета (Carbon Fields), REST, авторизация, соцвход (VK/Яндекс)
    ├── idpg-auth          # Соц-вход: Яндекс / VK / MAX (вспомогательный, не обязателен)
    └── wp-cloud-sync      # Синхронизация темы/плагинов с Яндекс.Диском (крон)
```

## Требования

| Компонент   | Версия       |
|-------------|--------------|
| WordPress   | 6.4+         |
| PHP         | 8.2+         |
| Carbon Fields | 3.6 (в `idpg-core`, через Composer) |

## Установка ядра

```bash
cd plugins/idpg-core
composer install --no-dev
```

Затем активируйте плагин «IDPG Core» и тему `idpg_core`. Вендор Carbon Fields
исключён из репозитория (`.gitignore` плагина); без `composer install` плагин
работать не будет. При активации темы страницы `/login/`, `/lk/`, `/reset-password/`,
`/terms/`, `/privacy/`, `/offer/`, `/about/`, `/contacts/` создаются автоматически.

## Документация

- Плагин `idpg-core`: `plugins/idpg-core/README.md`, `CHANGELOG.md`
- Тема `idpg_core`: `themes/idpg_core/README.md`, `IMPLEMENTATION.md`, `CODING_STANDARDS.md`
- Плагин `wp-cloud-sync`: `plugins/wp-cloud-sync/README.md`
- ТЗ проекта: `docs/PLUGIN_TZ.md` (в рабочем каталоге сайта, вне репозитория)

## Правила для AI-агента

Обязательно читать и соблюдать [`AGENTS.md`](AGENTS.md): «не удаляй/не переписывай
работающий код», WordPress Coding Standards и правила безопасности (санитизация,
nonce, capabilities через `current_user_can()`, `$wpdb->prepare()`, escape на выводе).

## Вне репозитория

Сторонние и прочие плагины (clearfy-pro, wp-mail-smtp, wpvivid-backuprestore,
wp-sweep), а также `wp-config.php` и база данных в репозиторий не входят —
это окружение конкретного сайта, а не исходники проекта.