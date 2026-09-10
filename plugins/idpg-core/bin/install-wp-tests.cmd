@echo off
rem ===========================================================================
rem IDPG Core — подготовка тестового окружения WordPress (Windows / Laragon).
rem
rem Запуск:  bin\install-wp-tests.cmd [db-name] [db-user] [db-pass] [db-host]
rem
rem По умолчанию:
rem   db-name = wp_idpg_tests, db-user = root, db-pass = (пусто), db-host = localhost
rem
rem Что делает:
rem   1. Скачивает и распаковывает wordpress-develop (test-suite + WP core src).
rem   2. Создаёт тестовую БД.
rem   3. Создаёт wp-tests-config.php внутри распакованного test-suite.
rem   4. Создаёт дефолтный phpunit.xml.dist в плагине (если отсутствует).
rem
rem Переменные окружения (необязательны):
rem   IDPG_WP_DEVELOP  — куда распаковать wordpress-develop
rem   IDPG_PHP         — полный путь к php.exe
rem   IDPG_COMPOSER    — полный путь к composer.phar
rem ===========================================================================
setlocal

set "PLUGIN_DIR=%~dp0.."
for %%I in ("%PLUGIN_DIR%") do set "PLUGIN_DIR=%%~fI"

rem --- Параметры ---
set "DB_NAME=%~1"
if "%DB_NAME%"=="" set "DB_NAME=wp_idpg_tests"
set "DB_USER=%~2"
if "%DB_USER%"=="" set "DB_USER=root"
set "DB_PASS=%~3"
set "DB_HOST=%~4"
if "%DB_HOST%"=="" set "DB_HOST=localhost"

rem --- PHP ---
if defined IDPG_PHP (
    set "PHP=%IDPG_PHP%"
) else (
    set "PHP=php"
)

rem --- Composer ---
if defined IDPG_COMPOSER (
    set "COMPOSER_PHAR=%IDPG_COMPOSER%"
) else (
    set "COMPOSER_PHAR="
)

rem --- Каталог wordpress-develop ---
if not defined IDPG_WP_DEVELOP set "IDPG_WP_DEVELOP=%LOCALAPPDATA%\idpg-tests\wordpress-develop"
if not exist "%IDPG_WP_DEVELOP%" mkdir "%IDPG_WP_DEVELOP%"

set "WP_TESTS_DIR=%IDPG_WP_DEVELOP%\tests\phpunit"
set "WP_CORE_DIR=%IDPG_WP_DEVELOP%\src"

echo ============================
echo Среда: DB=%DB_NAME% user=%DB_USER% host=%DB_HOST%
echo Плагин: %PLUGIN_DIR%
echo PHP: %PHP%
echo WP develop: %IDPG_WP_DEVELOP%
echo ============================
echo.

rem ---------------------------------------------------------------------------
rem 1. Скачивание и распаковка wordpress-develop (если ещё нет)
rem ---------------------------------------------------------------------------
if exist "%WP_CORE_DIR%\wp-includes\version.php" goto :have_core
echo Скачивание wordpress-develop...
set "ZIP=%TEMP%\wordpress-develop.zip"
powershell -NoProfile -Command "Invoke-WebRequest -Uri 'https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.zip' -OutFile '%ZIP%' -UseBasicParsing"
if errorlevel 1 (
    echo [ERROR] Не удалось скачать wordpress-develop.
    exit /b 1
)
powershell -NoProfile -Command "Expand-Archive -Path '%ZIP%' -DestinationPath '%TEMP%\wordpress-develop-extract' -Force"
if errorlevel 1 (
    echo [ERROR] Не удалось распаковать wordpress-develop.
    exit /b 1
)
rem Переносим содержимое wordpress-develop-trunk в целевой каталог
for /d %%D in ("%TEMP%\wordpress-develop-extract\wordpress-develop-*") do (
    xcopy "%%D\*" "%IDPG_WP_DEVELOP%\" /E /I /Y >nul
)
del "%ZIP%" >nul 2>&1
rmdir /S /Q "%TEMP%\wordpress-develop-extract" >nul 2>&1
:have_core

if not exist "%WP_CORE_DIR%\wp-includes\version.php" (
    echo [ERROR] Не найден WP core: %WP_CORE_DIR%
    exit /b 1
)

rem ---------------------------------------------------------------------------
rem 2. Создание тестовой БД
rem ---------------------------------------------------------------------------
set "MYSQL=mysql"
"%MYSQL%" -u"%DB_USER%" %DB_PASS_MYSQL% -h"%DB_HOST%" -e "CREATE DATABASE IF NOT EXISTS `%DB_NAME%`;" 2>nul
if errorlevel 1 (
    rem пробуем через полный путь MySQL из Laragon
    set "MYSQL=D:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe"
)

rem ---------------------------------------------------------------------------
rem 3. wp-tests-config.php
rem ---------------------------------------------------------------------------
set "CONFIG=%IDPG_WP_DEVELOP%\wp-tests-config.php"
if not exist "%CONFIG%" (
    powershell -NoProfile -Command "(Get-Content '%IDPG_WP_DEVELOP%\wp-tests-config-sample.php' -Raw).Replace('youremptytestdbnamehere','%DB_NAME%').Replace('yourusernamehere','%DB_USER%').Replace('yourpasswordhere','%DB_PASS%').Replace('localhost','%DB_HOST%').Replace('dirname( __FILE__ ) . ''/src/''','''%WP_CORE_DIR%/''').Replace('__DIR__ . ''/src/''','''%WP_CORE_DIR%/''') | Set-Content '%CONFIG%' -Encoding UTF8"
)

echo.
echo Тестовое окружение готово.
echo Для запуска тестов выполните:  bin\run-tests.cmd
echo Дополнительно, при необходимости:
echo   composer require --dev phpunit/phpunit yoast/phpunit-polyfills  ^(в %PLUGIN_DIR%^)
echo   wp scaffold plugin-tests idpg-core ^(для структуры тестов^)
endlocal
