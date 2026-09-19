<?php
/**
 * Выполняется WordPress при удалении плагина.
 *
 * Удаляет: настройки, OAuth user_meta, журнал, transient'ы.
 * НЕ удаляет: пользователей, заказы, посты.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 1. Настройки и маркеры
delete_option( 'idpg_auth_settings' );
delete_option( 'idpg_auth_log' );
delete_option( 'idpg_auth_logins_db_version' );
delete_option( 'idpg_auth_security_db_version' );
delete_option( 'idpg_auth_security_last_email' );
delete_option( 'idpg_auth_lock_map' );

// 1a. Таблицы (классы WP_Auth_Ru_Login_Log / WP_Auth_Ru_Security здесь
// не загружены — используем имена таблиц напрямую с префиксом БД).
global $wpdb;
$table = $wpdb->prefix . 'idpg_auth_logins';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$table = $wpdb->prefix . 'idpg_auth_security';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 2. OAuth user_meta провайдеров (VK, Яндекс, MAX)
// Фактические ключи пишутся провайдерами с префиксом wp_auth_ru_.
$meta_keys = array(
    'wp_auth_ru_yandex_oauth_id',
    'wp_auth_ru_vk_oauth_id',
    'wp_auth_ru_max_oauth_id',
    'wp_auth_ru_provider',
);
foreach ( $meta_keys as $meta_key ) {
    delete_metadata( 'user', 0, $meta_key, '', true );
}

// 3. Transient'ы
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_idpg_auth_%'
        OR option_name LIKE '_transient_timeout_idpg_auth_%'"
);

// 4. Multisite
if ( is_multisite() ) {
    $sites = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
    foreach ( $sites as $site_id ) {
        switch_to_blog( $site_id );
        delete_option( 'idpg_auth_settings' );
        delete_option( 'idpg_auth_log' );
        delete_option( 'idpg_auth_logins_db_version' );
        delete_option( 'idpg_auth_security_db_version' );
        delete_option( 'idpg_auth_security_last_email' );
        delete_option( 'idpg_auth_lock_map' );
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'idpg_auth_logins' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'idpg_auth_security' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_idpg_auth_%'
                OR option_name LIKE '_transient_timeout_idpg_auth_%'"
        );
        restore_current_blog();
    }
}
