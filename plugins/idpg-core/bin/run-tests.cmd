@echo off
rem ===========================================================================
rem IDPG Core — запуск PHPUnit юнит-тестов (Windows / Laragon).
rem
rem Запуск:  bin\run-tests.cmd
rem
rem Требования:
rem   - PHP в PATH либо переменная IDPG_PHP (полный путь к php.exe)
rem   - подготовленное тестовое окружение (см. bin\install-wp-tests.cmd)
rem   - в плагине установлены dev-зависимости (phpunit, yoast/phpunit-polyfills)
rem ===========================================================================
setlocal

set "PLUGIN_DIR=%~dp0.."
for %%I in ("%PLUGIN_DIR%") do set "PLUGIN_DIR=%%~fI"

rem --- PHP ---
if defined IDPG_PHP (
    set "PHP=%IDPG_PHP%"
) else (
    set "PHP=php"
)

rem --- Пути тестового окружения (по умолчанию) ---
if not defined WP_TESTS_DIR set "WP_TESTS_DIR=%LOCALAPPDATA%\idpg-tests\wordpress-develop\tests\phpunit"
if not defined WP_TESTS_PHPUNIT_POLYFILLS_PATH set "WP_TESTS_PHPUNIT_POLYFILLS_PATH=%PLUGIN_DIR%\vendor\yoast\phpunit-polyfills"
if not defined IDPG_THEME_DIR set "IDPG_THEME_DIR=%PLUGIN_DIR%\..\..\themes\idpg_core"

set "PHPUNIT=%PLUGIN_DIR%\vendor\bin\phpunit"

if not exist "%PHPUNIT%" (
    echo [ERROR] PHPUnit не установлен. Выполните: composer require --dev phpunit/phpunit yoast/phpunit-polyfills
    exit /b 1
)

if not exist "%WP_TESTS_DIR%\includes\bootstrap.php" (
    echo [ERROR] Тестовое окружение WordPres не найдено: %WP_TESTS_DIR%
    echo         Выполните предварительно  bin\install-wp-tests.cmd
    exit /b 1
)

echo Подключение к тестовой базе: %WP_TESTS_DIR%\..\..\wp-tests-config.php
"%PHP%" "%PHPUNIT%" -c "%PLUGIN_DIR%\phpunit.xml.dist" %*

endlocal
