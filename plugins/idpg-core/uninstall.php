<?php
/**
 * Uninstall для плагина IDPG Core.
 *
 * Выполняется WordPress при удалении плагина (WP_UNINSTALL_PLUGIN).
 * Очищает опции, транзиенты, user meta и cron-задачи плагина.
 *
 * Кастомных таблиц в БД плагин не создаёт (весь контент хранится
 * в wp_posts/wp_postmeta/wp_usermeta), поэтому DELETE FROM TABLE
 * здесь нет.
 *
 * Опциональная полная очистка данных событий: установите в wp-config.php
 *   define( 'IDPG_CORE_UNINSTALL_WIPE_DATA', true );
 * — тогда дополнительно удаляются post meta с префиксом _idpg_ у всех
 * событий и площадок. По умолчанию данные событий сохраняются, чтобы
 * удаление плагина было обратимым.
 *
 * @package IDPG_Core
 */

// Запуск только из процесса удаления плагина.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Опции плагина (точные имена).
$direct_options = array(
	'idpg_social_auth_settings',          // Настройки соц-авторизации (APP_ID/SECRET/ENABLED).
	'idpg_social_auth_rewrite_flushed',   // Флаг версии flush rewrite-правил.
	'idpg_roles_version',                 // Версия набора capabilities ролей.
	'idpg_meta_migration_version',        // Версия выполненной мета-миграции.
);

foreach ( $direct_options as $option ) {
	delete_option( $option );
}

// Опции режима «Сайт в разработке» — все с префиксом idpg_maintenance_
// (enabled, title, message, contacts, цвет фона и т.д., регистрируются
// через register_setting с группой idpg_maintenance).
$GLOBALS['wpdb']->query(
	"DELETE FROM {$GLOBALS['wpdb']->options}
	 WHERE option_name LIKE 'idpg_maintenance\\_%'
	    OR option_name = 'idpg_maintenance_enabled'"
);

// Транзиенты: одноразовые state-токены CSRF соц-авторизации,
// PKCE-пара VK ID и блокировка миграций. Транзиенты с истечением
// создают парные ключи *_timeout — удаляем по тому же LIKE.
$transient_likes = array(
	'idpg_social_state\\_%',
	'idpg_vk_pkce\\_%',
	'idpg_migration_lock%',
);

foreach ( $transient_likes as $like ) {
	$GLOBALS['wpdb']->query(
		$GLOBALS['wpdb']->prepare(
			"DELETE FROM {$GLOBALS['wpdb']->options}
			 WHERE option_name LIKE %s
			    OR option_name LIKE %s",
			'_transient_' . $like,
			'_transient_timeout_' . $like
		)
	);
}

// User meta плагина.
$user_meta_keys = array(
	'_idpg_social_provider',       // Слаг провайдера соц-авторизации.
	'_idpg_social_id',             // ID пользователя в соцсети.
	'idpg_avatar_attachment_id',   // Загруженный аватар (вложение).
	'idpg_avatar_url',             // URL аватара из соцсети.
	'idpg_org_name',               // Название организации организатора.
	'idpg_contact_name',           // Контактное лицо организатора.
	'idpg_phone',                  // Телефон организатора.
	'_idpg_favorites',             // Избранные события.
);

foreach ( $user_meta_keys as $meta_key ) {
	delete_metadata( 'user', 0, $meta_key, '', true );
}

// Cron-задачи (на случай, если deactivation hook не сработал).
if ( wp_next_scheduled( 'idpg_cron_sync_tickets' ) ) {
	wp_clear_scheduled_hook( 'idpg_cron_sync_tickets' );
}

// Cookie режима разработки — очистить у клиента нечем при uninstall,
// WordPress удалит их по истечении срока; серверной записи нет.

/**
 * Опциональная зачистка данных контента (post meta `_idpg_*`).
 *
 * Выключена по умолчанию: удаление плагина не должно уничтожать
 * события и площадки. Включается константой в wp-config.php.
 */
if ( defined( 'IDPG_CORE_UNINSTALL_WIPE_DATA' ) && IDPG_CORE_UNINSTALL_WIPE_DATA ) {
	$wpdb = $GLOBALS['wpdb'];

	// post meta с префиксом _idpg_ (данные событий, площадок, счётчики, маркеры миграции).
	$wpdb->query(
		"DELETE pm FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key LIKE '\\_idpg\\_%'
		   AND p.post_type IN ( 'event', 'venue' )"
	);

	// Маркер миграции может остаться и на других типах — подчистим глобально.
	$wpdb->query(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_idpg_meta_migrated'"
	);
}
