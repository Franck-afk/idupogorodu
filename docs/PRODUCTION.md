# Продакшен: чек-лист запуска сайта «Иду по городу»

Развёртывание на реальном домене (`https://idupogorodu.ru`). Пункты — обязательные (🔴)
и рекомендованные (🟡). После выполнения каждой группы выполняйте действия из
раздела «Проверка» (ниже).

---

## 1. wp-config.php (🔴)

Скопируйте текущий конфиг со стенда и приведите к виду:

```php
// Реальное окружение.
define('WP_ENVIRONMENT_TYPE', 'production');
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// Обновлённые ключи и соли: https://api.wordpress.org/secret-key/1.1/salt/
// (ОБЯЗАТЕЛЬНО новые, не из репозитория и не из стенда).
define('AUTH_KEY',         '...');
// ... остальные ключи ...

// Отключение правок файлов через админку.
define('DISALLOW_FILE_EDIT', true);
define('DISALLOW_FILE_MODS', true);

// Принудительный HTTPS в админке.
define('FORCE_SSL_ADMIN', true);

// Константы IDPG.
define('IDPG_DEFAULT_CITY_ID', 15); // Ижевск (Удмуртия) — проверить актуальный терм taxonomy city
define('IDPG_TICKET_API_URL', 'https://<реальный-агрегатор>.example.com/v1');

// Отключение WP-Cron в пользу системного (см. раздел 4).
define('DISABLE_WP_CRON', true);
```

> ⚠️ `WP_DEBUG` на проде — только `false` (или включать кратко во время деплоя).
> Плагин `idpg-core` при неотредактированной заглушке `example.com` показывает
> предупреждение в админке и не шлёт cron-запросы на несуществующий API.

**На стенде сейчас:** `WP_DEBUG=true`, `WP_ENVIRONMENT_TYPE` не задан, адрес билетов — заглушка.

---

## 2. URL сайта (🔴)

| Что | Значение |
|-----|----------|
| `siteurl` | `https://idupogorodu.ru` |
| `home`    | `https://idupogorodu.ru` |
| `AUTH_DOMAIN` / домен приложения VK ID, Яндекс ID | реальный домен |

Если домен переносится после заливки БД:

```bash
# wp-cli (на проде)
wp option update siteurl https://idupogorodu.ru
wp option update home https://idupogorodu.ru
wp rewrite flush
```

Дополнительно выполняется `flush_rewrite_rules` при обновлении пермалинков
(Настройки → Постоянные ссылки).

---

## 3. Загрузка файлов и ссылки на контент (🔴)

- Папка `wp-content/uploads/` переносится полностью (постеры, баннеры, лого).
- В БД сохраняются прямые ссылки вида `https://idupogorodu.test/...`.
  После смены домена выполнить массовую замену (seed-данные / phpMyAdmin):
  - `https://idupogorodu.test` → `https://idupogorodu.ru` — только в `post_content`,
    `post_excerpt`, `wp_options`, `postmeta` (гуид меняется аккуратно).
  - Есть инструменты: плагин Better Search Replace или WP-CLI:
    ```bash
    wp search-replace 'https://idupogorodu.test' 'https://idupogorodu.ru' --all-tables --precise
    ```
    После замены: `wp cache flush`.

---

## 4. Cron (🔴)

Плагин ставит задачу `idpg_cron_sync_tickets` (каждые 3 часа).

На проде **обязателен системный cron** (WP-Cron на пустом трафике ненадёжен):

```cron
0 */3 * * * /usr/bin/php /var/www/idupogorodu.ru/wp-cron.php >/dev/null 2>&1
# или через wget/curl:
0 */3 * * * curl -s https://idupogorodu.ru/wp-cron.php?doing_wp_cron >/dev/null
```

и константа `DISABLE_WP_CRON` из раздела 1.

---

## 5. Почта (🔴)

Email-уведомления (регистрация, сброс пароля, смена статуса билетов) идут
через `wp_mail()`.

- Настроить **WP Mail SMTP** с реальным доменным SMTP (не Gmail со стенда).
- На ONE запись: `From` = доменный адрес (например `noreply@idupogorodu.ru`).
- Проверить доставляемость писем (страница «Почтовый тест» плагина WP Mail SMTP).

**На стенде сейчас:** Gmail `sevkinkosta46@gmail.com` — для прода заменить.

---

## 6. Социальная авторизация (idpg-auth) (🔴)

Модуль хранит настройки в опции `idpg_auth_settings` (Client ID / Secret для
VK и Яндекс, Bot Token MAX — в БД, полями-паролями).

После смены домена **обновить клиентские приложения провайдеров**:

| Провайдер | Где создать приложение | Redirect URI |
|-----------|------------------------|--------------|
| VK ID | dev.vk.com | `https://idupogorodu.ru/wp-json/wp-auth-ru/v1/vk/callback` |
| Яндекс ID | oauth.yandex.ru | `https://idupogorodu.ru/wp-json/wp-auth-ru/v1/yandex/callback` |
| MAX | max.ru/partner | Mini App URL = `https://idupogorodu.ru` |

- Заполнить в «Настройки → Авторизация» (в админке).
- Правильный redirect URI показывает сама страница настроек плагина.
- Включить режим соответствия законодательству (блокировка иностранных OAuth).

> ⚠️ VK/Яндекс не принимают локальные домены (`.test`) — на стенде проверка
> возможна только с реальным доменом или через проброс.

---

## 7. Реклама и виджеты (🟡)

Виджеты рекламы содержат плейсхолдеры:

| Блок | Плейсхолдер | Что заменить |
|------|-------------|--------------|
| Виджет «Реклама» (тема) | `ссылка https://timeweb.com/` | реальный URL/картинка заказчика |
| Фрагмент Я.Директ/РСЯ | `R-A-XXXXXX-Y` | реальный блок РСЯ |
| Фрагмент VK Реклама | `MyTarget_block_XXXXX`, `XXXXX` | реальный `adId` |

Проверьте настройки «Области виджетов» и позиции на главной
(«Иду по городу → Главная (Блоки)»).

---

## 8. Кэш и оптимизация (🟡)

- На проде активен Clearfy Pro — включить кэш, minify, precache gzip.
- Перейти на предупреждения: `WP_DEBUG=false`.
- Обновить правила `.htaccess` / конфиг Nginx после включения HTTPS.

---

## 9. Контент и данные (🟡)

- Убрать тестовые события/площадки/города, оставить реальную афишу.
- Проверить таксономию `city`: терм для `IDPG_DEFAULT_CITY_ID` существует
  и содержит реальные район/область.
- Проверить роли (`administrator`, `editor`, `event_organizer`, `subscriber`).
- Проверить, что `idpg_maintenance_enabled=0` (Настройки → Сайт в разработке —
  выключен). На стенде уже выключен.
- Очистить журналы: `WP Mail SMTP`, «Журнал событий» idpg-auth (если были тесты).

---

## 10. Безопасность (🔴)

- Новые ключи-соли в wp-config.
- Пароль администратора — новый, не со стенда.
- Отключен `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`.
- HTTPS с valid-сертификатом (Let's Encrypt / по хостингу), HTTP → HTTPS.
- Headers через Clearfy/хостинг: X-Frame-Options, Referrer-Policy, HSTS.
- Плагин All-In-One WP Security при включении — белый список REST `idpg`
  (иначе сломаются поиск/календарь/подписка/избранное; плагин предупредит).
- Не включать «Rename Login Page» (конфликт с кастомным `/login/`).

---

## 11. Резервное копирование (🟡)

- Настроить автоматический бэкап wpvivid (БД + файлы) по расписанию.
- Проверить восстановление на тестовую копию/стенд.

---

## Финальная проверка перед запуском

1. `wp-cron` запускается системным планировщиком; событие `idpg_cron_sync_tickets`
   стоит в очереди (`wp cron event list`).
2. Главная, афиша, площадки, страницы входа/восстановления пароля отдают HTTP 200.
3. REST API: `GET https://idupogorodu.ru/wp-json/idpg/v1/events` → 200 (не 403).
4. Авторизация VK / Яндекс / MAX работает на реальном домене.
5. Письма доставляются на тестовый ящик.
6. `IDPG_TICKET_API_URL` — реальный, в админке нет предупреждений плагина.
7. WP Mail SMTP не показывает ошибок; в журнале idpg-auth нет ERROR-строк.
8. Бэкап создан и восстановим.