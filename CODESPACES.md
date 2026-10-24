# Просмотр проекта в GitHub Codespaces + Docker

Для предпросмотра сайта «Иду по городу» с локальными правками плагинов и темы.

## Способ 1: GitHub Codespaces (рекомендуется)

1. Откройте репозиторий в GitHub
2. Нажмите `Code` → `Codespaces` → `Create codespace on main` (или на нужной ветке)
3. Codespaces автоматически обнаружит `.devcontainer/devcontainer.json` и поднимет окружение через `docker-compose.yml`
4. Дождитесь сборки (первый раз ~2-5 минут)
5. После запуска откроется порт `8080` с WordPress. Пройдите установку WordPress
6. Активируйте плагины `idpg-core`, `idpg-auth` и тему `idpg_core` в админке

## Способ 2: Локально с Docker

```bash
# Клонировать репозиторий
git clone https://github.com/Franck-afk/idupogorodu.git
cd idupogorodu

# Запустить окружение
docker compose up -d

# Открыть в браузере
# http://localhost:8080
```

## Особенности монтирования

В `docker-compose.yml` смонтированы:
- `./plugins` → `/var/www/html/wp-content/plugins` (плагины `idpg-core`, `idpg-auth` и др.)
- `./themes` → `/var/www/html/wp-content/themes` (тема `idpg_core`)

Все изменения в этих директориях сразу видны в WordPress.

## Полезные команды

```bash
# Остановить
docker compose down

# Остановить с удалением БД (сброс)
docker compose down -v

# Посмотреть логи
docker compose logs -f wordpress
```

## Тестирование изменений

После активации плагинов/темы вы сможете сразу просматривать все внесённые вами правки в браузере.
