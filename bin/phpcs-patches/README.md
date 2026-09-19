# PHPCS-патчи обвязки (DEV-54)

Патчи к пакету [`forrest79/phpcs-ignores`](https://github.com/forrest79/phpcs-ignores) — основе
baseline-механизма PHPCS (DEV-27A / DEV-27B). Патчи **нельзя** держать прямо в `vendor/`:
`composer install` / `composer update` / удаление каталога `vendor` их стирают, после чего
baseline перестаёт применяться и PHPCS начинает показывать все унаследованные нарушения.

Поэтому патчи лежат здесь, в репозитории, а к vendor применяются скриптом.

| | |
|---|---|
| Пакет | `forrest79/phpcs-ignores` |
| Версия, под которую сделаны патчи | **v0.5.0** |
| Каталог применения | `vendor/forrest79/phpcs-ignores` (`-p1`) |
| Локальная обвязка | `D:\laragon\www\idupogorodu\phpcs-tools2` (вне репозитория) |
| Определение обвязки | `bin/phpcs-patches/tools.composer.json` (в репозитории) |

## Установка обвязки с нуля

Каталог инструментов **не** лежит в репозитории (`vendor/` исключён `.gitignore`), поэтому
тулчейн задаётся файлом-шаблоном `tools.composer.json`. Версии в нём **пришпилены точно**
(без `^`): обновление сниффов меняет результат проверки, а вместе с ним и baseline, поэтому
это должно быть осознанным действием, а не побочным эффектом `composer update`.

```bash
TOOLS=D:/laragon/www/idupogorodu/phpcs-tools2
mkdir -p "$TOOLS" && cd "$TOOLS"
cp /d/laragon/www/idupogorodu/wp-content/bin/phpcs-patches/tools.composer.json composer.json
composer install
cd /d/laragon/www/idupogorodu/wp-content && bash bin/phpcs-patches/apply.sh "$TOOLS"
```

Шаблон фиксирует четыре прямые зависимости:

| Пакет | Версия |
|---|---|
| `squizlabs/php_codesniffer` | 3.13.6 |
| `wp-coding-standards/wpcs` | 3.4.1 |
| `phpcompatibility/phpcompatibility-wp` | 2.1.8 |
| `forrest79/phpcs-ignores` | 0.5.0 |

Транзитивные зависимости (`nette/neon`, `phpcompatibility/php-compatibility`,
`phpcsstandards/*`, `dealerdirect/phpcodesniffer-composer-installer`) фиксируются
`composer.lock` внутри каталога инструментов — его тоже имеет смысл сохранять.

Приёмка этой процедуры (перепроверена 19.09.2026 на `dev54/pin-check`): `composer install`
по шаблону → все 10 пакетов встали в ожидаемых версиях → без патчей `idpg-core` даёт
**636 ошибок / 410 предупреждений, exit 2** → `apply.sh` → **exit 0** по всем трём компонентам.

## Что патчится

| Патч | Файл | Что меняет | Что будет, если не применить |
|---|---|---|---|
| `0001-phpcs-ignores-windows-path.patch` | `src/PhpCsInjections.php` | Перед `str_ends_with()` путь нормализуется: `str_replace( '\\', '/', $path )` | **На Windows baseline не применяется вообще.** PHP отдаёт путь с `\`, сравнение с `vendor/…/FileList.php` не срабатывает, инъекция класса `File` не происходит → PHPCS показывает все ~4 тыс. унаследованных нарушений, exit 2 |
| `0002-phpcs-ignores-baseline-tabs.patch` | `src/BaselineReport.php` | Убрано `str_replace( "\t", '\t', $message )` — табы в сообщении не экранируются | **Только при генерации baseline.** `Ignores.php` читает baseline через `Nette\Neon`, а в одинарных кавычках NEON escape-последовательности не раскрываются: запись с литералом `\t` никогда не совпадёт с реальным табом в сообщении сниффа. Запись становится «вечно несовпадающей», job падает на 1 ошибке |

Патч `0001` нужен для **применения** baseline, патч `0002` — для его **генерации**.
При обычной проверке кода (job `phpcs`) работает только `0001`.

## Как применять

После каждой переустановки `vendor`:

```bash
# по умолчанию — D:/laragon/www/idupogorodu/phpcs-tools2
bash bin/phpcs-patches/apply.sh

# явный каталог инструментов
bash bin/phpcs-patches/apply.sh /tmp/phpcs-tools

# только проверить, не меняя файлы
bash bin/phpcs-patches/apply.sh --check
```

Из `cmd.exe` / PowerShell:

```cmd
bin\phpcs-patches\apply.cmd
```

Скрипт идемпотентен: уже применённый патч он пропускает (`git apply --reverse --check`),
неприменимый — сообщает и возвращает код 1, не оставляя половину изменений.
Если версия пакета в `composer.lock` отличается от `v0.5.0`, скрипт предупредит об этом.

Требуется `git` в `PATH` (используется `git apply`; работать в git-репозитории не обязательно).

## Проверка, что патчи на месте

```bash
# должно быть пусто (обратное применение проходит = патч уже применён)
cd /d/laragon/www/idupogorodu/phpcs-tools2/vendor/forrest79/phpcs-ignores
git apply --reverse --check -p1 ../../../wp-content/bin/phpcs-patches/0001-phpcs-ignores-windows-path.patch
```

Косвенная проверка — прогон PHPCS: с применённым патчом `0001` на Windows выходит **exit 0**,
без него — сотни нарушений и **exit 2**.

```bash
cd plugins/idpg-core
"D:/laragon/www/idupogorodu/phpcs-tools2/vendor/bin/phpcs" --standard=.phpcs.xml.dist \
  --bootstrap="D:/laragon/www/idupogorodu/phpcs-tools2/vendor/forrest79/phpcs-ignores/src/bootstrap.php" \
  --runtime-set ignore_warnings_on_exit 1 --report=summary
```

## CI

Job `phpcs` в `.circleci/config.yml` ставит обвязку в `/tmp/phpcs-tools` и **патчи не применяет —
и это нормально**:

- `0001` — no-op на Linux: пути там уже с `/`, `str_replace` ничего не меняет;
- `0002` — влияет только на генерацию baseline, а CI только проверяет код.

Если понадобится генерировать baseline прямо в CI, добавьте шаг
`bash bin/phpcs-patches/apply.sh /tmp/phpcs-tools` перед генерацией.

## Почему патчи, а не форк

Альтернатива из ТЗ — форкнуть `forrest79/phpcs-ignores` (и `nette/neon`) в свой composer.
От неё отказались:

- изменений всего два и оба крошечные, а пакет стабилен (v0.5.0);
- форк требует своего VCS-репозитория, записи в `repositories` и ручной синхронизации
  с каждым апстрим-релизом — инфраструктуры больше, чем сам патч;
- `nette/neon` патчить **не нужно** — проверено побайтовым сравнением с пакетом из кэша Composer,
  он чистый (см. ниже);
- патч-файлы видны в истории git и удаляются одной строкой, когда апстрим починят проблему.

Проверка чистоты vendor (сравнение с кэшем Composer по каждому пакету, 19.09.2026):
единственный модифицированный пакет — `forrest79/phpcs-ignores` (`src/PhpCsInjections.php`,
`src/BaselineReport.php`). Все остальные — `nette/neon`, `squizlabs/php_codesniffer`,
`wp-coding-standards/wpcs`, `phpcompatibility/*`, `phpcsstandards/*`,
`dealerdirect/phpcodesniffer-composer-installer` — без изменений.

## Обновление патчей при апгрейде пакета

1. Обновить зависимость и посмотреть, применились ли патчи:
   `bash bin/phpcs-patches/apply.sh --check`.
2. Если контекст не совпал — проверить, не исправлено ли поведение в апстриме.
   Исправлено → патч удалить. Не исправлено → пересобрать патч под новую версию.
3. Пересборка (сравнение с чистым пакетом):

   ```bash
   # распаковать чистый пакет из кэша Composer
   python -c "import zipfile;zipfile.ZipFile(r'%LOCALAPPDATA%\Composer\files\forrest79\phpcs-ignores\<hash>.zip').extractall('pristine')"
   diff -u pristine/forrest79-phpcs-ignores-<hash>/src/BaselineReport.php \
           phpcs-tools2/vendor/forrest79/phpcs-ignores/src/BaselineReport.php
   ```

4. После правки патчей — обновить версию пакета **в трёх местах**: таблица выше,
   `tools.composer.json` и `EXPECTED_VERSION` в `apply.sh`. Затем раздел DEV-54
   в `docs/TZ_BACKLOG.md`.

## Связанные документы

- `docs/TZ_BACKLOG.md` → DEV-27A (baseline в CI), DEV-27B (постепенная чистка),
  DEV-54 (эти патчи).
- `docs/TZ_BACKLOG.md` → DEV-53 (состав репозитория, приёмка на чистом клоне).
