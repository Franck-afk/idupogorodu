<?php
/**
 * Подтверждение регистрации организатора по email.
 *
 * Пользователь регистрируется как организатор, но до перехода по ссылке
 * из письма не получает роль event_organizer (остаётся subscriber).
 * Класс хранит хэш ключа подтверждения в user_meta, проверяет его
 * при переходе по ссылке и выставляет роль организатора.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Organizer_Verify
 *
 * Управляет ключами подтверждения регистрации организатора:
 * генерация ключа, хранение (хэш + срок действия), проверка ссылки
 * и активация роли event_organizer.
 */
class IDPG_Organizer_Verify {

	/**
	 * Экземпляр синглтона.
	 *
	 * @var IDPG_Organizer_Verify|null
	 */
	private static $instance = null;

	/**
	 * Meta-ключ флага «ожидает подтверждения».
	 *
	 * @var string
	 */
	const META_PENDING = 'idpg_org_pending';

	/**
	 * Meta-ключ хэша ключа подтверждения.
	 *
	 * @var string
	 */
	const META_KEY_HASH = 'idpg_org_verify_hash';

	/**
	 * Meta-ключ срока действия ключа (timestamp).
	 *
	 * @var string
	 */
	const META_KEY_EXPIRES = 'idpg_org_verify_expires';

	/**
	 * Срок действия ссылки подтверждения.
	 *
	 * @var int
	 */
	const KEY_LIFETIME = 3 * DAY_IN_SECONDS;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Organizer_Verify
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Приватный конструктор.
	 */
	private function __construct() {}

	/**
	 * Проверяет, ожидает ли организатор подтверждения email.
	 *
	 * @param int $user_id ID пользователя.
	 * @return bool
	 */
	public function is_pending( $user_id ) {
		$user_id = (int) $user_id;

		return (bool) get_user_meta( $user_id, self::META_PENDING, true );
	}

	/**
	 * Генерирует ключ подтверждения и сохраняет его хэш + срок действия.
	 *
	 * Вызывается в точке регистрации организатора сразу после wp_create_user().
	 * Возвращает plain-ключ для ссылки в письме (хэш хранится в user_meta).
	 *
	 * @param int $user_id ID нового пользователя.
	 * @return string Plain-ключ подтверждения.
	 */
	public function prepare( $user_id ) {
		$user_id = (int) $user_id;
		$key     = wp_generate_password( 32, false );

		update_user_meta( $user_id, self::META_KEY_HASH, wp_hash( $key ) );
		update_user_meta( $user_id, self::META_KEY_EXPIRES, time() + self::KEY_LIFETIME );
		update_user_meta( $user_id, self::META_PENDING, 1 );

		return $key;
	}

	/**
	 * URL ссылки подтверждения на странице входа.
	 *
	 * @param int    $user_id ID пользователя.
	 * @param string $key     Plain-ключ подтверждения.
	 * @return string URL.
	 */
	public function get_confirmation_url( $user_id, $key ) {
		return add_query_arg(
			array(
				'action'    => 'confirm',
				'idpg_user' => (int) $user_id,
				'idpg_key'  => $key,
			),
			IDPG_Auth_Emails::instance()->get_login_page_url()
		);
	}

	/**
	 * Подтверждает регистрацию по ссылке из письма.
	 *
	 * При успехе выдаёт роль event_organizer и очищает данные подтверждения.
	 *
	 * @param int    $user_id ID пользователя.
	 * @param string $key     Plain-ключ подтверждения.
	 * @return WP_User|WP_Error Пользователь при успехе, иначе ошибка.
	 */
	public function confirm( $user_id, $key ) {
		$user_id = (int) $user_id;
		$key     = is_string( $key ) ? trim( $key ) : '';

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'invalid_user', __( 'Пользователь не найден.', 'idpg-core' ) );
		}

		// Повторный клик по уже использованной ссылке: организатор уже активен.
		if ( ! $this->is_pending( $user_id ) ) {
			if ( in_array( 'event_organizer', (array) $user->roles, true ) ) {
				return $user;
			}

			return new WP_Error( 'already_confirmed', __( 'Регистрация уже подтверждена.', 'idpg-core' ) );
		}

		$hash    = get_user_meta( $user_id, self::META_KEY_HASH, true );
		$expires = (int) get_user_meta( $user_id, self::META_KEY_EXPIRES, true );

		// Сравнение без раскрытия ключа по времени (hash_equals).
		if ( empty( $hash ) || ! is_string( $hash ) || ! hash_equals( $hash, wp_hash( $key ) ) ) {
			return new WP_Error( 'invalid_key', __( 'Ссылка подтверждения недействительна.', 'idpg-core' ) );
		}

		if ( $expires && time() > $expires ) {
			return new WP_Error( 'expired_key', __( 'Срок действия ссылки истёк. Зарегистрируйтесь заново.', 'idpg-core' ) );
		}

		// Роль event_organizer выдаётся только после подтверждения.
		// НЕ используем WP_User::set_role(): на сайте роль subscriber не
		// зарегистрирована, из-за чего set_role() не снимает её с caps
		// и в wp_capabilities остаётся лишний ключ subscriber.
		$this->assign_organizer_role( $user_id );

		delete_user_meta( $user_id, self::META_PENDING );
		delete_user_meta( $user_id, self::META_KEY_HASH );
		delete_user_meta( $user_id, self::META_KEY_EXPIRES );

		/**
		 * Действие после подтверждения регистрации организатора.
		 *
		 * @param int     $user_id ID организатора.
		 * @param WP_User $user    Объект пользователя.
		 */
		do_action( 'idpg_organizer_verified', $user_id, $user );

		return $user;
	}

	/**
	 * Назначает организатору роль event_organizer напрямую через caps.
	 *
	 * Использование WP_User::set_role() здесь ненадёжно: если роль subscriber
	 * не зарегистрирована в wp_roles, set_role() оставляет её ключ
	 * в wp_capabilities (ядро не видит её в ->roles). Пишем caps явно.
	 *
	 * @param int $user_id ID пользователя.
	 * @return void
	 */
	private function assign_organizer_role( $user_id ) {
		$cap_key = $GLOBALS['wpdb']->prefix . 'capabilities';

		// Как и WP при set_role(): храним только ключ роли, полный набор
		// возможностей ядро соберёт из wp_roles через get_role_caps().
		update_user_meta( $user_id, $cap_key, array( 'event_organizer' => true ) );
		clean_user_cache( $user_id );
	}
}