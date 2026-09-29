# TZ_BACKLOG_PROMPTS_ORGANIZER_V14

**Версия:** 14.0
**Дата:** 28.09.2026
**Основание:** `docs/TZ_BACKLOG.md`, `docs/FEATURES.md` §6, `README_DEV-00A.md`, решения владельца по соцвходу и роли.
**Область:** P0-обвязка ЛК организатора + редиректы соцвхода + запрет регистрации подписчиков + удаление анкеты и флага pending.
**Правила:** изменения только аддитивные, через хуки `idpg_`; php-doc на русском; nonce + `current_user_can()`; escape на выводе; `$wpdb->prepare()` для SQL; сигнатуры существующих методов не менять.
**Порядок:** DEV-37 → DEV-38 → DEV-39 → DEV-63 → DEV-67 → DEV-68 → DEV-70 → DEV-71 → DEV-72 → DEV-73.

---

## ⚠️ Предположения, требующие подтверждения владельца

Перед выдачей агенту уточнить:

1. **Данные организации** (`org_name`, `contact_name`, `phone`) — переехали в **профиль ЛК** (`/organizer/profile/`), заполняются необязательно. *(Предположение A. Реализовано в DEV-73.)*
2. **Страница `/organizer/complete/`** — **удалена** (страница, шаблон, JS). *(Подтверждено и выполнено в DEV-72.)*
3. **Флаг `_idpg_org_pending`** — **убран** из кода вместе с хуком DEV-50. Флаг подтверждения email `idpg_org_pending` (без подчёркивания) сохранён — он относится к `IDPG_Organizer_Verify`, а не к анкете. *(Подтверждено и выполнено в DEV-72.)*
4. **Подтверждение email для соцвхода** — роль при соцвходе **не меняется**,
   подтверждение email остаётся **обязательным** (вход через соцсеть не засчитывается
   как подтверждение владения аккаунтом). Зарегистрированный и подтверждённый организатор
   после соцвхода попадает в `/organizer/` — это задача DEV-63. *(Пересмотрено владельцем
   28.09.2026: прежняя формулировка «соцпользователь сразу получает `event_organizer`,
   без письма» отменена как нереализуемая — хук `idpg_auth_new_user_role` срабатывает
   только в ветке создания нового аккаунта. См. карточку DEV-74 в `TZ_BACKLOG.md`.)*
5. **Существующие `subscriber`** — **удалить** через WP-CLI с бэкапом. *(Подтверждено владельцем.)*
6. **Регистрация подписчиков** — **запрещена** (`type=user` → 403). *(Подтверждено владельцем.)*

---

## DEV-37 (P0, S). Подключить роутер ЛК + унификация `_idpg_org_pending`

```
DEV-37 (P0, S). Подключить class-organizer-url.php.

Контекст (G1): includes/class-organizer-url.php (IDPG_Organizer_URL, 479 стр.)
существует, но НЕ входит в require_once в idpg-core.php (строки 75–119),
::init() нигде не вызывается. Весь роутинг /organizer/ мёртв.

Что сделать:
1) В plugins/idpg-core/idpg-core.php добавить require_once для
   includes/class-organizer-url.php рядом с остальными organizer-классами.
2) Вызвать IDPG_Organizer_URL::init() в idpg_core_init().
3) Проверить, что init регистрирует:
   - rewrite ^organizer/?$ и ^organizer/(dashboard|events|profile)/?$;
   - query vars idpg_organizer_page / idpg_organizer_section;
   - фильтр template_include → page-organizer.php;
   - flush_rewrite_rules() при активации.
4) Временно НЕ унифицировать флаг pending — это будет сделано в DEV-72
   (удаление pending). Сейчас достаточно подключить роутер.
5) PHPUnit: tests/test-organizer-url.php — доступ гостя, denied, allowed;
   корректность rewrite; template_include подменяет шаблон только для /organizer/.

Ограничения: аддитивно, существующие методы не менять (только require_once + init),
php-doc на русском.

Приёмка: /organizer/, /organizer/events/, /organizer/profile/ открываются
для организатора; гость редиректится на /login/.
```

---

## DEV-38 (P0, S). Подключить меню и ассеты ЛК

> **Статус: выполнено 28.09.2026.**
>
> - п. 1–3, 5 — выполнено. `inc/organizer-menu.php` подключён
>   (`functions.php`, строка 441), все шесть функций проверены на наличие,
>   ассеты подключаются внутри `idpg_core_is_organizer_cabinet()`,
>   `idpg_organizer_hide_before_footer_widgets()` сам регистрируется на
>   `sidebars_widgets` (`inc/organizer-menu.php:233`).
> - **п. 4 неприменим.** `organizer.js` не читает ни одного глобала: разделы
>   берёт из `data-idpg-org-panel` / `data-idpg-org-section`, текущий — из
>   `window.location`. `wp_localize_script` с `restUrl`/`nonce` стал бы
>   мёртвым кодом. В `functions.php` оставлен комментарий, чтобы параметры
>   не добавили обратно.
> - п. 6 — выполнено: `plugins/idpg-core/tests/test-organizer-menu.php`,
>   14 тестов. Сортировка по `order`, фильтр `idpg_organizer_menu_items`
>   (добавление, удаление, не-массив), отсев пунктов без слага/подписи,
>   компаратор, адреса разделов.

```
DEV-38 (P0, S). Подключить inc/organizer-menu.php и ассеты ЛК.

Контекст (G2, G3): themes/idpg_core/inc/organizer-menu.php (233 стр.)
существует, но НЕ входит в require_once в functions.php (строки 351–381).
organizer.js (92 стр.) и organizer.css (295 стр.) не подключаются.

Что сделать:
1) В themes/idpg_core/functions.php добавить require_once для
   inc/organizer-menu.php.
2) Проверить доступность функций: idpg_organizer_menu(),
   idpg_organizer_section_url(), idpg_organizer_current_section(),
   idpg_core_is_organizer_cabinet(), idpg_organizer_after_login_url(),
   idpg_organizer_hide_before_footer_widgets().
3) Добавить wp_enqueue_script/style для organizer.js/organizer.css
   только на страницах ЛК (idpg_core_is_organizer_cabinet()),
   с IDPG_VERSION.
4) wp_localize_script с restUrl, nonce, текущим разделом, URL разделов —
   имена сверить с фактическим organizer.js.
5) Вызвать idpg_organizer_hide_before_footer_widgets() там, где задумано.
6) PHPUnit: tests/test-organizer-menu.php — сортировка по order,
   фильтр idpg_organizer_menu_items, section_url для dashboard/events/profile.

Ограничения: аддитивно, функции темы не переименовывать, php-doc на русском,
escape на выводе.

Приёмка: разделы ЛК переключаются без перезагрузки, стили применяются.
```

---

## DEV-39 (P0, S). Создать страницу `/organizer/` в БД

> **Статус: выполнено 28.09.2026 с двумя осознанными отклонениями.**
>
> - п. 1, 4, 5, 6 — выполнено. `idpg_core_create_organizer_page()` создаёт
>   страницу и пишет `_idpg_organizer_page_id`; исключение из sitemap —
>   `wpseo_exclude_from_sitemap_by_post_ids`; в меню страница не попадает
>   (`idpg_core_create_default_menu()` про слаг `organizer` не знает).
>   Тест: `tests/test-organizer-page-creation.php`, 9 тестов, включая
>   идемпотентность и восстановление опции по слагу.
> - **п. 2 — оставлен `after_setup_theme`, не `after_switch_theme`.** Так же
>   подключены все пять соседних создателей страниц (login, reset-password,
>   terms, privacy, offer), спецификация допускает «или при первом
>   обращении», а `after_setup_theme` вдобавок самовосстанавливается, если
>   страницу удалили. `after_switch_theme` срабатывает раз в жизни темы и
>   выбил бы организатора из конвенции файла.
> - **п. 3 — мета-ключ `_wp_page_template` оставлен.** Спецификация требовала
>   шаблон только через `template_include` и без Template Name, но
>   `page-organizer.php` не объявляет `Template Name`, а именно этот
>   мета-ключ делает `is_page_template( 'page-organizer.php' )` истинным —
>   на нём держится запасная ветка `idpg_core_is_organizer_cabinet()` при
>   отключённом плагине ядра. Снос мета-ключа сломал бы fallback.
> - Заголовок страницы — «Кабинет организатора», а не «Личный кабинет» из
>   п. 1: страница 715 уже опубликована на проде. Менять заголовок только у
>   новых установок значило бы развести существующий и новый сайты.

```
DEV-39 (P0, S). Автосоздание страницы /organizer/.

Контекст: idpg_core_create_organizer_page() и опция _idpg_organizer_page_id
существуют только в docblock'ах. В functions.php авто-создаются только
/login/, /reset-password/, /terms/, /privacy/, /offer/ (строки 516–696).

Что сделать:
1) Добавить idpg_core_create_organizer_page() по образцу
   idpg_core_create_login_page():
   - проверка опции _idpg_organizer_page_id;
   - создание страницы slug 'organizer', title «Личный кабинет»;
   - запись опции.
2) Вызвать при активации темы (after_switch_theme) или при первом обращении.
3) Шаблон page-organizer.php — через template_include из DEV-37
   (не через Template Name — избежать конфликта).
4) Исключить из Yoast sitemap (wpseo_exclude_from_sitemap_by_post_ids).
5) Не показывать в меню.
6) PHPUnit: tests/test-organizer-page-creation.php — создаётся один раз,
   повторный вызов не дублирует.

Приёмка: после активации страница /organizer/ существует, привязана
к опции, не индексируется Yoast.
```

---

## DEV-63 (P1, S). Редирект организатора в ЛК после соцвхода

> **Статус: выполнено 28.09.2026, объём больше спецификации.**
>
> Решение владельца от 28.09.2026: **роль решает всегда**.
>
> - п. 1 не потребовался: `render_button_wp()` и так берёт `redirect_to`
>   из `$_GET`, поэтому при отправке гостя на вход с
>   `?redirect_to=/organizer/` кнопки уже несут этот адрес. В `page-login.php`
>   ничего не менялось.
> - п. 2, 3 — выполнено. В `IDPG_Organizer_URL` добавлены
>   `SOCIAL_PROVIDERS`, `init_social_login_redirects()` и
>   `social_login_redirect()`; фильтры `wp_auth_ru_{yandex,vk,max}_after_login_redirect`.
> - **Сверх спецификации — `guard_cabinet_redirect()` на фильтре `wp_redirect`.**
>   Фильтр провайдера не гарантирует ничего: в
>   `class-wp-auth-ru-oauth-provider.php:212` он применяется только если
>   transient пуст или ведёт не на наш домен. С явным
>   `redirect_to=/organizer/` фильтр не вызывается вовсе, подписчик попадал
>   в кабинет и его выбрасывало обратно на вход — цикл «вход → кабинет →
>   вход». Теперь допуск в кабинет проверяется на любом редиректе сайта по
>   `user_is_organizer()` — тому же правилу, что у `get_access_decision()`.
>   Администраторы и редакторы в кабинет проходят, редиректы за пределы
>   кабинета не затрагиваются. Адрес подмены настраивается фильтром
>   `idpg_organizer_denied_redirect_url`.
> - п. 4 — выполнено: `tests/test-organizer-social-login.php`, 27 тестов.
>   Повторный вход и сохранение роли дополнительно покрыты в idpg-auth
>   (`tests/test-social-roles.php`), где плагин загружен по-настоящему.

```
DEV-63 (P1, S). Редирект организатора в ЛК после соцвхода.

Проблема: render_button_wp() (class-wp-auth-ru-yandex.php:153) берёт
redirect_to из $_GET. На /login/ его нет → transient пустой →
class-wp-auth-ru-oauth-provider.php:213 → фильтр
wp_auth_ru_*_after_login_redirect нигде не подключён → home_url().

Роль НЕ теряется: login_or_create_user() находит по email
(class-wp-auth-ru-provider.php:209-217) и роль не трогает.

Что сделать:
1) Передавать redirect_to=/organizer/ в кнопки на /login/
   (page-login.php:426). Кнопки уже умеют — значение из $_GET.
2) Подключить фильтры wp_auth_ru_{yandex,vk,max}_after_login_redirect
   в idpg-core на IDPG_Organizer_URL::after_login_url()
   (wp_set_current_user() уже отработал — class-wp-auth-ru-oauth-provider.php:205).
3) after_login_url() (упрощённая логика после DEV-72, без pending):
   - роль event_organizer → /organizer/;
   - иначе → home_url().
4) PHPUnit: tests/test-organizer-social-login.php — соц-организатор →
   /organizer/; подписчик → home_url(); повторный вход не сбрасывает роль.

Ограничения: аддитивно, idpg-auth не переписывать (только фильтры),
php-doc на русском.

Приёмка: организатор после соцвхода попадает в /organizer/.
```

---

## DEV-67 (P1, S). Редирект в ЛК после подтверждения email

```
DEV-67 (P1, S). Редирект в ЛК организатора после подтверждения email.

Проблема: после клика по временной ссылке (IDPG_Organizer_Verify)
пользователь попадает на главную, а не в ЛК.

Что сделать:
1) В обработчике verify-ссылки (class-organizer-verify.php)
   заменить home_url() на IDPG_Organizer_URL::after_login_url()
   (упрощённая логика после DEV-72: роль event_organizer → /organizer/).
2) После успешного подтверждения — редирект в /organizer/.
3) PHPUnit: tests/test-organizer-verify-redirect.php.

Ограничения: аддитивно, php-doc на русском, escape на выводе.

Приёмка: организатор после клика по ссылке из письма попадает в /organizer/.
```

> **Статус: выполнено 28.09.2026. Правки кода не потребовались.**
>
> **Описание задачи не соответствовало коду.** Предписывалось заменить
> `home_url()` в обработчике verify-ссылки внутри
> `class-organizer-verify.php`, но там нет ни `home_url()`, ни редиректа:
> `confirm()` возвращает `WP_User` и ничего не выводит.
>
> Реальная цепочка оказалась такой:
>
> | Шаг | Где |
> |---|---|
> | Ссылка в письме | `class-auth-emails.php:244-251` → `get_login_url()`, то есть страница с шаблоном `page-login.php` |
> | Обработка клика | `page-login.php:12-33` — единственный обработчик confirm во всём проекте |
> | Редирект | `page-login.php:26-30` → `idpg_organizer_after_login_url()` → `IDPG_Organizer_URL::after_login_url()` → `home_url( '/organizer/' )` |
>
> Редирект в кабинет **уже был реализован**. Важно, что он стоит именно
> после `confirm()`: роль `event_organizer` выдаётся внутри подтверждения,
> а `after_login_url()` отдаёт адрес только для пользователя с этой ролью.
> Проверено отдельным тестом — до подтверждения адрес пустой.
>
> **Что добавлено:** `tests/test-organizer-verify-redirect.php` — 9 тестов,
> 26 утверждений. Раньше у `confirm()` не было покрытия вообще. Зафиксированы
> успешный сценарий, выдача роли, очистка pending-данных, идемпотентность
> повторного клика, форма ссылки и неуспешные ветки (неверный ключ,
> истёкшая ссылка, несуществующий пользователь) — в каждой кабинет
> недоступен. Тест повторяет ровно то выражение, которое стоит в шаблоне,
> чтобы правка шаблона не вернула редирект на главную.
>
> Полный прогон после добавления: 186 тестов, 447 утверждений — OK.

---

## DEV-68 (P1, S). Письмо подтверждения — только организаторам

```
DEV-68 (P1, S). Подтверждение email только для организаторов.

Проблема: нужно убедиться, что письмо со временной ссылкой отправляется
ТОЛЬКО при регистрации организатора (type=organizer в REST /auth/register).

Что сделать:
1) Проверить REST /auth/register (class-rest-auth.php) — где вызывается
   IDPG_Organizer_Verify для отправки письма.
2) Убедиться:
   - type=organizer → письмо отправляется, роль event_organizer
     только после подтверждения;
   - type=user → не обрабатывается (после DEV-70 → 403).
3) PHPUnit: tests/test-register-email.php — организатор получает письмо,
   подписчик отклоняется (403).

Приёмка: организатор регистрируется, получает письмо, подтверждает,
получает роль и попадает в ЛК.
```

> **Статус: выполнено 28.09.2026. Правки кода не потребовались — задача
> оказалась проверочной.**
>
> **Что уже было верно в коде:**
>
> | Требование | Где обеспечено |
> |---|---|
> | Письмо только при `type=organizer` | `class-rest-auth.php:551-552` внутри блока `if ( 'organizer' === $type )` |
> | Роль только после подтверждения | `class-organizer-verify.php:174` — `assign_organizer_role()` вызывается из `confirm()`; при регистрации явно ставится `subscriber` (`class-rest-auth.php:531`) |
> | `type=user` не обрабатывается | DEV-70: 403 `registration_disabled` до создания пользователя |
> | Организатору не дублируется приветствие | `class-auth-emails.php:203` — `take_password()` возвращает запомненный пароль, и `send_welcome_email()` выходит раньше |
>
> **Добавлено:** `tests/test-register-email.php` — 9 тестов, 23 утверждения.
> Письма перехватываются фильтром `pre_wp_mail`, реальная отправка не
> выполняется. Покрыто: письмо приходит один раз и на адрес организатора,
> в письме есть ссылка `action=confirm` с `idpg_user` и `idpg_key`, до
> подтверждения роли нет, а полный сценарий «зарегистрировался → получил
> письмо → подтвердил → получил роль → попал в `/organizer/`» проходит
> целиком на ссылке, извлечённой из письма. Отдельно зафиксировано, что
> `type=user`, отсутствие `type` и произвольный тип не создают ни
> пользователя, ни письма.
>
> Проверено, что статус успешной регистрации — 201, а `pending_email`
> равен `true`; ответ используется в тестах как признак ожидания
> подтверждения.
>
> Полный прогон после добавления: 195 тестов, 470 утверждений — OK.

---

## DEV-70 (P1, S). Запретить регистрацию простых пользователей

> **Статус: выполнено 28.09.2026, с выходом за рамки спецификации в двух местах.**
>
> - п. 1 — выполнено, но потребовал снять `required`/`enum` у `type` в схеме
>   маршрута. Схема отвечает ДО колбэка: с `required => true` запрос без `type`
>   получал 400 `rest_missing_callback_param`, а с `enum` — 400
>   `rest_invalid_param`. Приёмка требует 403, поэтому проверка перенесена в
>   `handle_register()` условием `if ( 'organizer' !== $type )`, которое
>   одним строгим сравнением закрывает `type=user`, отсутствие параметра и
>   любой мусор. Код ответа `registration_disabled` сохранён, текст заменён на
>   продуктовый: «Регистрация доступна только организаторам».
> - п. 2, 3, 4 — выполнено. Переключателя `type` в форме не было и не стало:
>   поля только организаторские (`org_name`, `contact_name`, `phone`). Кнопка
>   отправки была «Зарегистрироваться» → «Стать организатором». В шапке
>   подпись «Войти / Регистрация» обещала гостю общую регистрацию →
>   «Войти / Стать организатором». Вкладка уже была «Регистрация организатора».
> - п. 5 — выполнено: `tests/test-register-organizer-only.php`, 13 тестов.
>   Отдельно закрыт регресс, которого не было в приёмке: 403-запросы **не**
>   расходуют лимит регистраций, потому что проверка типа стоит в колбэке
>   раньше `IDPG_Auth_Rate_Limiter`. Это проверено отдельным тестом, а не
>   предположено.
>
> ⚠️ **Побочный баг, не входивший в задачу.** В аргументах `email`
> маршрута стояло `'validate_callback' => 'is_email'`. REST вызывает
> validate-колбэк как `( $value, $request, $key )`, а второй аргумент
> `is_email()` помечен устаревшим с 3.0.0 — объект запроса попадал в
> `$deprecated` и давал notice на **каждой** успешной регистрации. Заменено на
> замыкание `static function ( $param ) { return is_email( $param ); }`:
> проверка по значению та же, notice ушёл. **Тот же дефект остался ещё в двух
> местах этого файла** — `auth/forgot-password` и `auth/organizer-complete`.
> Не трогал, чтобы не менять чужие маршруты без отдельной задачи.
>
> ✅ Оба места закрыты отдельно — см. **DEV-78** ниже.

```
DEV-70 (P1, S). Регистрация только для организаторов.

Продуктовое решение: простой пользователь не может зарегистрироваться.

Что сделать:
1) REST /auth/register (class-rest-auth.php):
   - принимать только type=organizer;
   - type=user (или отсутствие type) → 403 с текстом
     «Регистрация доступна только организаторам».
2) Убрать с /login/ (page-login.php) ссылку «Зарегистрироваться»
   для обычных пользователей — оставить только «Стать организатором».
3) Убрать переключатель type в форме, если есть.
4) Обновить тексты — везде «организатор».
5) PHPUnit: tests/test-register-organizer-only.php — type=user → 403,
   type=organizer → успех.

Приёмка: зарегистрироваться можно только как организатор;
попытка type=user → 403.
```

---

## DEV-71 (P1, S). Удалить существующих пользователей с ролью subscriber

```
DEV-71 (P1, S). Удалить существующих subscriber.

Продуктовое решение: роль subscriber не должна существовать,
существующие пользователи с этой ролью — удалить.

Что сделать:
1) Сделать бэкап БД: wp db export D:\laragon\backups\idupogorodu\dev71-pre-delete.sql
2) WP-CLI команда wp idpg cleanup-subscribers:
   - выбрать пользователей с ролью subscriber;
   - пропустить администраторов и организаторов;
   - удалить (wp_delete_user с переназначением контента на админа ID 1
     или без переназначения — уточнить);
   - вывести отчёт: сколько найдено, сколько удалено, список ID.
3) Флаг --dry-run для проверки без удаления.
4) Запустить на стенде: сначала --dry-run, потом реально.
5) PHPUnit: tests/test-cleanup-subscribers.php — dry-run не удаляет,
   реальный запуск удаляет только subscriber.

Ограничения: не удалять administrator, editor, event_organizer.
Бэкап обязателен.

Приёмка: пользователей с ролью subscriber в БД не остаётся;
администраторы и организаторы не затронуты.
```

> **Статус: команда и тесты готовы; удаление не выполнялось — удалять нечего.**
> Требуется решение владельца по двум пунктам (см. ниже).
>
> **Фактическое состояние БД (проверено 28.09.2026, только SELECT):**
> пользователей с ролью `subscriber` — **0**. Всего 7 пользователей:
> 1 `administrator` + 6 `event_organizer`. Pending-аккаунтов — 0.
> Приёмка формально выполнена до запуска команды, поэтому бэкап
> `dev71-pre-delete.sql` не создавался: удаления не было.
>
> **Добавлено:**
>
> | Файл | Назначение |
> |---|---|
> | `includes/class-cli.php` | `wp idpg cleanup-subscribers` + тестируемая логика отбора/удаления |
> | `idpg-core.php:118` | Подключение файла |
> | `tests/test-cleanup-subscribers.php` | 12 тестов, 28 утверждений |
>
> **Ключевое решение по безопасности — отступление от буквы спецификации.**
> Спецификация требует «удалить всех с ролью subscriber», но роль
> `subscriber` сейчас **живая и обязательная**: `class-rest-auth.php:531`
> выдаёт её каждому только что зарегистрировавшемуся организатору, а
> `event_organizer` приходит лишь после подтверждения email
> (`class-organizer-verify.php:174`). Наивная команда удалила бы живые
> заявки на регистрацию — прямо вопреки приёмке «организаторы не
> затронуты». Поэтому отбор сделан так:
>
> - удаляется аккаунт, у которого `subscriber` — **единственная** роль
>   (мультиролевые исключаются целиком);
> - **pending-организаторы не трогаются** — ключ
>   `IDPG_Organizer_Verify::META_PENDING`; их число попадает в отчёт
>   отдельной строкой, чтобы молчаливой потери не было;
> - **по умолчанию команда ничего не удаляет** — dry-run; реальный
>   запуск требует явного `--force`. Это переворачивает порядок из п.4
>   спецификации («сначала --dry-run, потом реально») в безопасную
>   сторону: без --force удаление невозможно в принципе.
>
> Открытый вопрос спецификации «переназначать контент на админа ID 1 или
> нет — уточнить» решён в пользу флага: `--reassign-to=<id>`, по
> умолчанию переназначения нет.
>
> **Почему команда не проверена на стенде (п.4):** WP-CLI в этом
> окружении не установлен (`wp` отсутствует в PATH, в `D:\laragon\bin`
> нет ни `wp.bat`, ни `wp-cli.phar`). Логика команды покрыта юнит-тестами,
> но реальный прогон `wp idpg cleanup-subscribers` не выполнялся.
>
> **Требуется решение владельца:**
>
> 1. Что делать с `default_role = subscriber` в `wp_options`? Роль
>    зарегистрирована (`class-roles.php:273-289`) и выдаётся при
>    регистрации, поэтому «роль subscriber не должна существовать»
>    сейчас не выполняется. Менять ли `default_role`?
> 2. Считать ли заявки, где роль `subscriber` **без** pending-меты,
>    мусором (текущее поведение команды — да)?

---

## DEV-72 (P1, M). Убрать анкету `/organizer/complete/` и флаг pending

```
DEV-72 (P1, M). Убрать анкету и флаг pending.

Продуктовое решение: организатор попадает в ЛК сразу после подтверждения
email (или сразу при соцвходе), без промежуточной анкеты.
Pending-флаг и страница /organizer/complete/ больше не нужны.

Что сделать:
1) Удалить страницу /organizer/complete/ и шаблон page-organizer-complete.php.
2) Удалить assets/js/organizer-complete.js.
3) Удалить флаг _idpg_org_pending из кода:
   - IDPG_Organizer_Page::is_pending() — убрать или заглушить;
   - IDPG_Organizer_Page::maybe_mark_social_organizer_pending() (DEV-50) — удалить;
   - IDPG_Organizer_Page::is_questionnaire_complete() — удалить;
   - константы META_PENDING / META_PENDING_ALT / META_COMPLETED_AT — удалить;
   - хук wp_login → maybe_mark_social_organizer_pending — удалить.
4) Упростить get_access_decision() (class-organizer-url.php):
   - убрать ветку pending → /organizer/complete/;
   - логика: гость → /login/, denied → 403, allowed → ЛК.
5) Упростить after_login_url():
   - роль event_organizer → /organizer/;
   - иначе → home_url().
6) Данные организации (org_name, contact_name, phone) — переезжают
   в профиль ЛК (DEV-73).
7) Удалить PHPUnit-тесты, завязанные на pending
   (test-organizer-pending.php — переработать или удалить).
8) Обновить docs/FEATURES.md, docs/TZ_BACKLOG.md.

Ограничения: удаление — только того, что создано под pending.
Не трогать роль event_organizer, verify-логику, IDPG_Organizer_Verify.

Приёмка: /organizer/complete/ недоступна; флаг pending в коде не используется;
организатор после подтверждения email или соцвхода попадает в /organizer/.
```

### Статус: выполнено (DEV-72)

Удалён весь слой анкеты, а не только перечисленные пункты:

- `includes/class-organizer-page.php` (класс `IDPG_Organizer_Page`) — удалён,
  вместе с `require` и `IDPG_Organizer_Page::init()` в `idpg-core.php`.
- `themes/idpg_core/page-organizer-complete.php` и
  `themes/idpg_core/assets/js/organizer-complete.js` — удалены.
- Тема: убран enqueue анкеты (`functions.php`) и редирект
  `pending → /organizer/complete/` в `page-organizer.php`.
- `class-rest-auth.php`: удалён маршрут `POST /auth/organizer-complete`
  вместе с `can_complete_organizer()`, `handle_organizer_complete()` и
  приватным `is_pending_organizer()`.
- `class-organizer-url.php`: удалены константы `ACCESS_PENDING`,
  `META_PENDING` (`_idpg_org_pending`), `META_COMPLETED_AT`, метод
  `is_pending_organizer()` и ветка pending в `get_access_decision()`.
  Теперь: гость → `/login/`, `denied` → 403, `allowed` → кабинет.
- Тесты: удалён `tests/test-organizer-pending.php`; переработаны
  `test-organizer-page.php`, `test-email-validator.php`,
  `test-organizer-social-login.php`.

Сохранено намеренно:

- `IDPG_Organizer_Verify` и его флаг `idpg_org_pending` (без подчёркивания) —
  это pending **подтверждения email**, а не анкеты. DEV-68 и DEV-67
  продолжают работать.
- `after_login_url()` не переписывался. Решение владельца: оставить текущую
  схему (организатор → `/organizer/`, не-организатор → пустая строка, и
  вызывающий код сам выбирает `admin_url()` или `home_url()`). Требование
  «иначе → home_url()» трактуется как поведение `page-login.php`, которое
  уже именно такое.
- `guard_cabinet_redirect()` (DEV-63) не тронут. `is_cabinet_url()` —
  общая префиксная проверка, `/organizer/complete/` она по-прежнему
  опознаёт как кабинетный адрес (это защита от редирект-петли, а не
  утверждение о существовании страницы).

Данные: в боевой БД осталась одна запись `_idpg_org_completed_at` — это
осиротевшая мета от удалённого слоя, на код не влияет. Смысловой миграции
не требуется.

Проверки: `php -l` чисто; `idpg-core` 198 тестов / 462 утверждения — OK;
`idpg-auth` 5 тестов / 9 утверждений — OK.

---

## DEV-73 (P1, M). Профиль организатора в ЛК — редактирование

```
DEV-73 (P1, M). Профиль организатора в ЛК — редактирование.

Контекст: после DEV-72 данные организации (org_name, contact_name, phone)
переезжают из анкеты в профиль ЛК. Сейчас section-profile.php (55 стр.) —
read-only <dl>.

Что сделать:
1) Переписать template-parts/organizer/section-profile.php — форма
   редактирования:
   - display_name (стандартное поле WP);
   - email (только чтение или с подтверждением);
   - idpg_org_name (название организации);
   - idpg_contact_name (контактное лицо);
   - idpg_phone (телефон);
   - аватар (wp.media или idpg_avatar_attachment_id).
2) REST GET /organizer/profile — данные профиля.
3) REST PUT /organizer/profile — обновление.
   permission_callback: is_user_logged_in + current_user_can('read');
   nonce wp_rest. Санитайз всех полей.
4) organizer.js — сохранение формы (fetch → PUT /organizer/profile),
   вывод ошибок, индикатор успеха.
5) Без JS: форма отправляется через $_POST с nonce, обрабатывается
   в page-organizer.php.
6) PHPUnit: tests/test-organizer-profile.php — GET/PUT, санитайз,
   скоуп по текущему пользователю (нельзя редактировать чужой профиль).

Ограничения: аддитивно, php-doc на русском, escape на выводе,
nonce + current_user_can(), SQL через $wpdb->prepare().

Приёмка: организатор редактирует профиль в ЛК, данные сохраняются,
отображаются в разделе «Профиль».
```

### Статус: выполнено (DEV-73)

**Отклонение от постановки, требующее внимания владельца.** Спека предлагала
`permission_callback: is_user_logged_in + current_user_can('read')`. Такая
проверка пропустила бы **любого подписчика** — у него capability `read` есть
по умолчанию, — и противоречила бы приёмке «организатор редактирует профиль».
Поэтому permission_callback — `is_user_logged_in()` +
`IDPG_Organizer_URL::user_is_organizer()` (та же проверка, что у кабинета:
организатор и выше). Проверено обратным прогоном: при ослаблении до «только
авторизация» набор тестов падает на `test_subscriber_is_forbidden` (200 вместо
403) и подписчик дописывает себе организацию.

Второе отклонение: **логика профиля живёт в `IDPG_Organizer_URL`**, а не в
REST-классе и не в шаблоне. `PUT /organizer/profile` и no-JS форма в
`page-organizer.php` вызывают одни и те же `sanitize_profile_input()` /
`update_profile()`, поэтому валидация не может разъехаться между путями
сохранения. Новый файл не создавался (новые файлы без согласования не
добавляются), а `IDPG_Organizer_URL` — уже существующий модуль кабинета.

Что сделано:

- `IDPG_Organizer_URL`: константы `META_CONTACT_NAME`, `META_PHONE`,
  `META_AVATAR_ID`, `PROFILE_FIELD_MAXLEN`; методы `get_profile_data()`,
  `sanitize_profile_input()`, `update_profile()`; хук
  `idpg_organizer_profile_updated` и фильтр `idpg_organizer_profile_sanitized`.
- `class-rest-auth.php`: маршрут `/idpg/v1/organizer/profile` (GET + PUT),
  `can_edit_organizer_profile()`, `handle_organizer_profile_get()`,
  `handle_organizer_profile_update()`.
- `template-parts/organizer/section-profile.php`: форма вместо read-only `<dl>`.
  Email — `readonly` (смена адреса требует подтверждения, вне объёма DEV-73).
- `page-organizer.php`: обработчик `$_POST` с nonce
  `idpg_organizer_profile`, проверкой прав (не слабее REST) и
  Post/Redirect/Get — F5 не переотправляет форму.
- `organizer.js`: `PUT` без перезагрузки, вывод ошибки/успеха, индикатор
  «Сохраняем…», подстановка сервером-сохранённых значений обратно в поля,
  откат к обычному POST при отсутствии `fetch`.

Скоуп: маршрут **не принимает `user_id`** — работает только с текущим
пользователем, поэтому подмена чужого ID в теле или query невозможна
(покрыто `test_cannot_write_to_another_users_profile` и
`test_cannot_read_another_users_profile`).

Аватар: `avatar_id` принимается только если это существующее вложение
(`post_type === 'attachment'`), иначе `400 idpg_profile_invalid_avatar`.
UI медиатеки (wp.media) не делался — поле скрытое и заполняется через
`avatar_id`; сам выбор файла остаётся открытым вопросом.

Проверки: `php -l` чисто; `idpg-core` **224 теста / 536 утверждений — OK**;
`idpg-auth` 5 тестов / 9 утверждений — OK. Новый набор
`tests/test-organizer-profile.php` — 26 тестов / 74 утверждения.

---

## СВОДНАЯ ТАБЛИЦА

| # | ID | P | Объём | Тема | Зависит |
|---|---|---|---|---|---|
| 1 | DEV-37 | P0 | S | Подключить роутер ЛК | — |
| 2 | DEV-38 | P0 | S | Подключить меню и ассеты | DEV-37 |
| 3 | DEV-39 | P0 | S | Создать страницу /organizer/ | DEV-37 |
| 4 | DEV-63 | P1 | S | Редирект в ЛК после соцвхода | DEV-37…39 |
| 5 | DEV-67 | P1 | S | Редирект в ЛК после подтверждения email | DEV-37…39 |
| 6 | DEV-68 | P1 | S | Письмо только организаторам | — |
| 7 | DEV-70 | P1 | S | Запретить регистрацию подписчиков | — |
| 8 | DEV-71 | P1 | S | Удалить существующих subscriber | — |
| 9 | DEV-72 | P1 | M | Убрать анкету и pending | ✅ выполнено |
| 10 | DEV-73 | P1 | M | Профиль в ЛК — редактирование | ✅ выполнено |

---

## ОТМЕНЕНО / НЕ ДЕЛАЕМ

| ID | Тема | Причина |
|---|---|---|
| DEV-66 | Гейт pending при соцвходе | После DEV-72 pending нет — нечего гейтить |
| DEV-69 | Ввести роль idpg_pending_organizer | Pending хранится флагом в meta, роль не носитель; вводить вторую роль = править 48 мест в 18 файлах |
| DEV-64 | VK Client ID | Конфиг, отдельная задача, не код |

---

## ЧЕК-ЛИСТ ПОСЛЕ КАЖДОЙ ЗАДАЧИ

- [ ] Изменения аддитивные, сигнатуры не тронуты.
- [ ] php-doc на русском, escape на выводе, nonce + `current_user_can()`.
- [ ] SQL через `$wpdb->prepare()`.
- [ ] `php -l` чистый.
- [ ] PHPCS без новых ошибок.
- [ ] PHPUnit — тесты по задаче зелёные.
- [ ] `docs/FEATURES.md`, `docs/TZ_BACKLOG.md`, `CHANGELOG.md` обновлены.
- [ ] Скоуп по автору проверен.

---

## ПОСЛЕ DEV-37…73 — ЧТО ДАЛЬШЕ

1. **DEV-42…45** — REST `/organizer/events`, форма события, дашборд.
   Это **цель всей затеи с ЛК** — дать организатору создавать события
   без захода в `wp-admin`.
2. **DEV-41** — подключить `class-privacy.php` (152-ФЗ).
3. **DEV-61** — пришпилить CI к тулчейну.
4. **DEV-46, DEV-47, DEV-49, DEV-56, DEV-57, DEV-58, DEV-62** — гигиена.
5. **DEV-27B** — фоновая чистка PHPCS.