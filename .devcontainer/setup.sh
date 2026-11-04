#!/usr/bin/env bash
#
# postCreateCommand для Codespace: ставит инструменты в контейнере редактора.
# Сам WordPress ставит одноразовый сервис wp-setup (см. docker-compose.yml).
#
set -euo pipefail

echo "==> WP-CLI ..."
if ! command -v wp >/dev/null 2>&1; then
	curl -sSL -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	sudo install -m 0755 /tmp/wp-cli.phar /usr/local/bin/wp
fi
wp --version

echo "==> Зависимости idpg-core (composer) ..."
if command -v composer >/dev/null 2>&1; then
	(cd plugins/idpg-core && composer install --no-interaction --no-progress --quiet) \
		|| echo "!! composer install не прошёл — PHPUnit будет недоступен."
else
	echo "!! composer не найден — пропускаю."
fi

echo "==> Сборка стилей темы (npm) ..."
if command -v npm >/dev/null 2>&1; then
	(cd themes/idpg_core && npm install --no-audit --no-fund --silent) \
		&& (cd themes/idpg_core && npm run compile:css:apply --silent) \
		|| echo "!! сборка SCSS не прошла — используется готовый style.css из репозитория."
else
	echo "!! npm не найден — используем готовый style.css из репозитория."
fi

echo ""
echo "==> Готово. Сайт: порт 8080, админка /wp-admin (admin / admin)."
echo "    Плагины и тема смонтированы из репозитория — правки видны сразу после reload."
