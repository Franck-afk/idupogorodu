#!/usr/bin/env bash
#
# DEV-54. Применение патчей PHPCS-обвязки.
#
# Патчи правят пакет forrest79/phpcs-ignores внутри vendor/ и теряются при
# переустановке зависимостей (`composer install`/`composer update`, удаление
# каталога vendor). После каждой переустановки vendor скрипт нужно запускать
# снова — иначе baseline перестаёт применяться и PHPCS показывает все
# унаследованные нарушения вместо чистого прогона.
#
# Использование:
#   bash bin/phpcs-patches/apply.sh [каталог-инструментов] [--check]
#
#   каталог-инструментов — тот, внутри которого лежит vendor/ с PHPCS.
#   По умолчанию: D:/laragon/www/idupogorodu/phpcs-tools2
#
#   --check — только проверить применимость, ничего не менять.
#
# Примеры:
#   bash bin/phpcs-patches/apply.sh
#   bash bin/phpcs-patches/apply.sh /tmp/phpcs-tools
#   bash bin/phpcs-patches/apply.sh --check
#
# Код возврата: 0 — все патчи применены или уже были применены;
#               1 — что-то не применилось (обычно сменилась версия пакета).
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

TOOLS=""
CHECK_ONLY=0
for arg in "$@"; do
	case "$arg" in
		--check)
			CHECK_ONLY=1
			;;
		-h|--help)
			sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
			exit 0
			;;
		-*)
			echo "Неизвестный флаг: $arg (см. --help)" >&2
			exit 2
			;;
		*)
			TOOLS="$arg"
			;;
	esac
done

if [ -z "$TOOLS" ]; then
	TOOLS="D:/laragon/www/idupogorodu/phpcs-tools2"
fi
TOOLS="${TOOLS%/}"

PKG="$TOOLS/vendor/forrest79/phpcs-ignores"
EXPECTED_VERSION="v0.5.0"

if [ ! -d "$PKG" ]; then
	echo "ОШИБКА: не найден каталог $PKG" >&2
	echo "Сначала установите зависимости: ( cd \"$TOOLS\" && composer install )" >&2
	exit 1
fi

# --- предупреждение, если версия пакета отличается от той, под которую делались патчи ---
if [ -f "$TOOLS/composer.lock" ]; then
	ACTUAL_VERSION="$(grep -A2 '"name": "forrest79/phpcs-ignores"' "$TOOLS/composer.lock" \
		| grep '"version"' | head -1 | sed 's/.*"version": *"//; s/".*//' || true)"
	if [ -n "$ACTUAL_VERSION" ] && [ "$ACTUAL_VERSION" != "$EXPECTED_VERSION" ]; then
		echo "ВНИМАНИЕ: патчи сделаны под $EXPECTED_VERSION, установлена $ACTUAL_VERSION." >&2
		echo "          Контекст может не совпасть — патчи, скорее всего, придётся обновить." >&2
	fi
fi

# git — нативная программа Windows и не понимает POSIX-пути вида /d/... после
# смены каталога, поэтому путь к патчу конвертируем через cygpath.
to_git_path() {
	if command -v cygpath >/dev/null 2>&1; then
		cygpath -m "$1"
	else
		printf '%s' "$1"
	fi
}

rc=0
applied=0
skipped=0

for patch in "$HERE"/*.patch; do
	[ -e "$patch" ] || continue
	name="$(basename "$patch")"
	patch_git="$(to_git_path "$patch")"

	# Уже применён? Тогда обратное применение проходит проверку.
	if ( cd "$PKG" && git apply --reverse --check -p1 "$patch_git" ) >/dev/null 2>&1; then
		echo "уже применён     : $name"
		skipped=$(( skipped + 1 ))
		continue
	fi

	# Применим?
	if ( cd "$PKG" && git apply --check -p1 "$patch_git" ) >/dev/null 2>&1; then
		if [ "$CHECK_ONLY" = "1" ]; then
			echo "можно применить  : $name"
		else
			( cd "$PKG" && git apply -p1 "$patch_git" )
			echo "применён         : $name"
			applied=$(( applied + 1 ))
		fi
	else
		echo "НЕ ПРИМЕНЯЕТСЯ  : $name" >&2
		echo "  Контекст не совпал — вероятно, обновилась версия пакета." >&2
		( cd "$PKG" && git apply --check -p1 "$patch_git" ) 2>&1 | sed 's/^/    /' >&2 || true
		rc=1
	fi
done

echo
if [ "$CHECK_ONLY" = "1" ]; then
	echo "Режим проверки: файлы не изменялись."
else
	echo "Применено: $applied, уже было применено: $skipped."
fi

if [ "$rc" = "0" ] && [ "$CHECK_ONLY" = "0" ]; then
	echo "Проверьте прогон:"
	echo "  cd plugins/idpg-core && \"$TOOLS/vendor/bin/phpcs\" --standard=.phpcs.xml.dist \\"
	echo "    --bootstrap=\"$TOOLS/vendor/forrest79/phpcs-ignores/src/bootstrap.php\" \\"
	echo "    --runtime-set ignore_warnings_on_exit 1 --report=summary"
fi

exit "$rc"
