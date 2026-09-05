<?php
/**
 * Абстрактный провайдер социальной авторизации.
 *
 * Общая логика OAuth 2.0 Authorization Code flow: доступ к настройкам,
 * URL коллбэка и state-токен через фасад Social_Auth.
 *
 * @package IDPG_Core
 */

namespace Idpg\SocialAuth;

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс Social_Provider
 *
 * База для провайдера VK (Provider_Vk).
 * Наследник обязан реализовать получение URL авторизации, токена
 * и нормализованного профиля пользователя.
 */
abstract class Social_Provider {

	/**
	 * Возвращает слаг провайдера (vk).
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Возвращает отображаемое название провайдера.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Строит URL страницы авторизации провайдера (с state).
	 *
	 * @return string
	 */
	abstract public function get_auth_url();

	/**
	 * Обменивает код авторизации на токен доступа.
	 *
	 * @param array $params Параметры коллбэка (code, state).
	 * @return string|array|WP_Error Токен (строка или массив ответа) или ошибка.
	 */
	abstract public function get_access_token( array $params );

	/**
	 * Получает нормализованный профиль пользователя по токену.
	 *
	 * Обязательные ключи результата: id. Опциональные: email,
	 * first_name, last_name, username, photo.
	 *
	 * @param string $access_token Токен доступа.
	 * @return array Нормализованный профиль.
	 */
	abstract public function get_profile( $access_token );

	/**
	 * ID приложения из настроек модуля.
	 *
	 * @return string
	 */
	protected function get_app_id() {
		$settings = Social_Admin::get_settings();

		return isset( $settings[ $this->get_id() . '_app_id' ] ) ? (string) $settings[ $this->get_id() . '_app_id' ] : '';
	}

	/**
	 * Секретный ключ приложения из настроек модуля.
	 *
	 * @return string
	 */
	protected function get_app_secret() {
		$settings = Social_Admin::get_settings();

		return isset( $settings[ $this->get_id() . '_secret' ] ) ? (string) $settings[ $this->get_id() . '_secret' ] : '';
	}

	/**
	 * URL коллбэка провайдера.
	 *
	 * @return string
	 */
	protected function get_callback_url() {
		return Social_Auth::instance()->get_callback_url( $this->get_id() );
	}

	/**
	 * Создаёт одноразовый state-токен (CSRF) для провайдера.
	 *
	 * @return string
	 */
	protected function create_state() {
		return Social_Auth::instance()->create_state( $this->get_id() );
	}
}
