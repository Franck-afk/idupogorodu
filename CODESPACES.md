# Просмотр проекта в браузере: GitHub Codespaces + Docker

Окружение поднимает полный WordPress с плагинами `idpg-core`, `idpg-auth` и
темой `idpg_core` из этого репозитория: любые изменения кода видны в браузере
сразу, без копирования файлов на сервер.

## Файлы окружения

| Файл | Роль |
|---|---|
| `docker-compose.yml` | 4 сервиса: `db` (MySQL 8), `wordpress` (сайт на порту 8080), `wp-setup` (одноразовая установка WP + активация темы и плагинов), `dev` (PHP 8.4 + Composer + Xdebug — контейнер редактора) |
| `.devcontainer/devcontainer.json` | конфиг Codespace: сервис `dev`, проброс порта 8080, расширения VS Code |
| `.devcontainer/install-wp.sh` | скрипт сервиса `wp-setup`: ждёт ядро WP, ставит сайт, активирует `idpg_core` / `idpg-core` / `idpg-auth` |
| `.devcontainer/setup.sh` | postCreateCommand: ставит WP-CLI, зависимости composer и SCSS темы |

## Запуск в Codespaces

1. Открыть репозиторий на GitHub → **Code** → **Codespaces** → **Create codespace on main**.
2. Codespace поднимет `docker-compose.yml` целиком, сервис `wp-setup` сам установит
   WordPress и активирует плагины/тему (первый запуск ~3–5 минут).
3. Внизу окна VS Code откроется уведомление о проброшенном порте **8080** →
   кнопка **Open in Browser**. Это и есть сайт со всеми изменениями кода.
4. Админка — `http://localhost:8080/wp-admin`, логин/пароль: `admin` / `admin`.

Проверить, что всё поднялось:

```bash
docker compose ps            # все сервиса healthy / exited 0 у wp-setup
docker compose logs wp-setup # последние строки: «==> Готово»
```

## Запуск локально (Docker на своём компьютере)

```bash
git clone https://github.com/Franck-afk/idupogorodu.git
cd idupogorodu
docker compose up -d
# открыть http://localhost:8080
```

Остановить: `docker compose down`. Полный сброс (удалить БД и сайт):
`docker compose down -v`.

## Как устроено монтирование

```
./plugins  →  /var/www/html/wp-content/plugins   (idpg-core, idpg-auth)
./themes   →  /var/www/html/wp-content/themes    (idpg_core, idpg_core-child)
```

То есть код в контейнере `wordpress` — это код репозитория. Правка файла в
редакторе (или в Codespace) сразу меняет сайт; перезапускать контейнер не нужно,
достаточно обновить страницу. Загрузки WordPress живут в отдельном томе
`wordpress_data` и в репозиторий не попадают.

## Полезные команды (в терминале Codespace)

```bash
docker compose logs -f wordpress  # логи сайта
docker compose exec wordpress wp --allow-root plugin list
docker compose exec db mysql -uwordpress -pwordpress wordpress  # БД напрямую
```

## Что НЕ делает это окружение

- Не ставит продакшен-конфигурацию (`wp-config.php` с солями) — только dev-значения.
- Не запускает PHPUnit/PHPCS: они живут в CircleCI (см. `.circleci/config.yml`).
- Не предназначено для выкладки: `docs/PRODUCTION.md` описывает отдельный процесс.
