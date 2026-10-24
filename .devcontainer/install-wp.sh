#!/bin/sh
#
# Одноразовая установка WordPress внутри контейнера wp-setup (wordpress:cli).
# Ждёт, пока контейнер wordpress развернёт ядро в общий том, затем ставит сайт,
# активирует плагины и тему проекта. Идемпотентно.
#
set -e

DB_HOST="${WORDPRESS_DB_HOST:-db:3306}"
DB_NAME="${WORDPRESS_DB_NAME:-wordpress}"
DB_USER="${WORDPRESS_DB_USER:-wordpress}"
DB_PASS="${WORDPRESS_DB_PASSWORD:-wordpress}"

echo "==> Ждём ядро WordPress в /var/www/html ..."
i=0
while [ ! -f /var/www/html/wp-load.php ]; do
	i=$((i + 1))
	if [ "$i" -gt 60 ]; then
		echo "!! Ядро не появилось за 120 секунд — прерываю." >&2
		exit 1
	fi
	sleep 2
done

WP="wp --allow-root --path=/var/www/html"

if $WP core is-installed 2>/dev/null; then
	echo "==> WordPress уже установлен — пропускаю установку."
else
	echo "==> Устанавливаю WordPress ..."
	$WP core install \
		--url="http://localhost:8080" \
		--title="Иду по городу (dev)" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.com \
		--dbhost="$DB_HOST" \
		--dbname="$DB_NAME" \
		--dbuser="$DB_USER" \
		--dbpass="$DB_PASS" \
		--skip-email
	$WP rewrite structure '/%postname%/'
	$WP option update blogdescription 'Портал-афиша (локальная разработка)'
fi

echo "==> Активирую тему и плагины проекта ..."
$WP theme activate idpg_core || echo "!! Тема idpg_core не найдена."
$WP plugin activate idpg-core || echo "!! Плагин idpg-core не найден."
$WP plugin activate idpg-auth || echo "!! Плагин idpg-auth не найден."

echo ""
echo "==> Готово: http://localhost:8080 (админка — /wp-admin, admin / admin)"
