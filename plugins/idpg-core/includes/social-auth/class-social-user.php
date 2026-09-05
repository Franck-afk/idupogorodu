<?php
/**
 * Обработка пользователя при социальной авторизации.
 *
 * Поиск существующего, создание нового, привязка провайдера,
 * загрузка аватара и вход пользователя.
 *
 * @package IDPG_Core
 */

namespace Idpg\SocialAuth;

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс Social_User
 *
 * Отвечает за поиск/создание пользователя по данным соцсети,
 * сохранение привязки, аватара и выполнение входа.
 */
class Social_User {

	/**
	 * Мета-ключ: слаг провайдера привязки.
	 *
	 * @var string
	 */
	const META_PROVIDER = '_idpg_social_provider';

	/**
	 * Мета-ключ: идентификатор пользователя в соцсети.
	 *
	 * @var string
	 */
	const META_SOCIAL_ID = '_idpg_social_id';

	/**
	 * Мета-ключ: ID вложения аватара из соцсети.
	 *
	 * @var string
	 */
	const META_AVATAR = 'idpg_avatar_attachment_id';

	/**
	 * Мета-ключ: URL аватара (по ТЗ).
	 *
	 * @var string
	 */
	const META_AVATAR_URL = 'idpg_avatar_url';

	/**
	 * Экземпляр синглтона.
	 *
	 * @var Social_User|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return Social_User
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
	private function __construct() {
	}

	// =========================================================================
	// Методы по ТЗ.
	// =========================================================================

	/**
	 * Ищет пользователя по email или создаёт нового (по ТЗ: find_or_create_user).
	 *
	 * - Проверяет email в базе (get_user_by).
	 * - Если не найден — создаёт через wp_insert_user с ролью subscriber.
	 * - Сохраняет мета-поля: idpg_social_provider, idpg_social_id, idpg_avatar_url.
	 * - Генерирует случайный пароль через wp_generate_password().
	 *
	 * @param string $provider  Слаг провайдера (vk, mailru).
	 * @param array  $user_data Нормализованные данные (id, email, first_name, last_name, photo/avatar, username).
	 * @return int|WP_Error ID пользователя или ошибка.
	 */
	public function find_or_create_user( $provider, array $user_data ) {
		$provider  = sanitize_key( $provider );
		$social_id = isset( $user_data['id'] ) ? sanitize_text_field( (string) $user_data['id'] ) : '';
		$email     = isset( $user_data['email'] ) ? sanitize_email( (string) $user_data['email'] ) : '';
		$avatar    = isset( $user_data['avatar'] ) ? esc_url_raw( (string) $user_data['avatar'] ) : ( isset( $user_data['photo'] ) ? esc_url_raw( (string) $user_data['photo'] ) : '' );

		if ( empty( $provider ) || empty( $social_id ) ) {
			return new \WP_Error( 'invalid_social_data', __( 'Некорректные данные от соцсети.', 'idpg-core' ) );
		}

		// 1. Поиск по привязке провайдера.
		$user_id = $this->find_by_provider( $provider, $social_id );

		if ( $user_id ) {
			// Обновляем аватар URL из свежих данных.
			if ( ! empty( $avatar ) ) {
				update_user_meta( $user_id, self::META_AVATAR_URL, $avatar );
			}

			return (int) $user_id;
		}

		// 2. Поиск по email.
		if ( is_email( $email ) ) {
			$existing = get_user_by( 'email', $email );

			if ( $existing ) {
				$user_id = (int) $existing->ID;
				$this->attach_provider( $user_id, $provider, $social_id );

				if ( ! empty( $avatar ) ) {
					update_user_meta( $user_id, self::META_AVATAR_URL, $avatar );
				}

				return $user_id;
			}
		}

		// 3. Создание нового пользователя.
		$username = $this->generate_username( $provider, $user_data );

		// Email обязателен для wp_insert_user — placeholder если не пришёл.
		if ( ! is_email( $email ) ) {
			$email = $provider . '_' . gmdate( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 ) . '@' . $provider . '.social';
		}

		$password = wp_generate_password( 24, true, true );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => $password,
				'user_email'   => $email,
				'first_name'   => isset( $user_data['first_name'] ) ? sanitize_text_field( $user_data['first_name'] ) : '',
				'last_name'    => isset( $user_data['last_name'] ) ? sanitize_text_field( $user_data['last_name'] ) : '',
				'display_name' => trim( sanitize_text_field( $user_data['first_name'] ?? '' ) . ' ' . sanitize_text_field( $user_data['last_name'] ?? '' ) ),
				'role'         => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH] find_or_create_user: wp_insert_user ошибка: ' . $user_id->get_error_message() );

			return $user_id;
		}

		// Сохраняем мета-поля по ТЗ.
		$this->attach_provider( $user_id, $provider, $social_id );

		if ( ! empty( $avatar ) ) {
			update_user_meta( $user_id, self::META_AVATAR_URL, $avatar );
			// Загружаем файл аватара во вложения асинхронно (не блокируем регистрацию при ошибке).
			$this->save_avatar( $user_id, $avatar );
		}

		/**
		 * Действие после создания пользователя через соцсеть.
		 *
		 * @param int    $user_id  ID пользователя.
		 * @param string $provider Слаг провайдера.
		 */
		do_action( 'idpg_social_auth_user_created', $user_id, $provider );

		return (int) $user_id;
	}

	/**
	 * Авторизует пользователя (по ТЗ: login_user).
	 *
	 * @param int $user_id ID пользователя.
	 * @return bool|WP_Error True при успехе или ошибка.
	 */
	public function login_user( $user_id ) {
		$user_id = absint( $user_id );
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new \WP_Error( 'invalid_user', __( 'Пользователь не найден.', 'idpg-core' ) );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
		do_action( 'wp_login', $user->user_login, $user );

		return true;
	}

	/**
	 * Загружает аватар пользователя (по ТЗ: save_avatar).
	 *
	 * Скачивает изображение по URL, сохраняет через media_handle_sideload()
	 * и устанавливает как аватар пользователя (user_meta).
	 *
	 * @param int    $user_id    ID пользователя.
	 * @param string $avatar_url URL изображения аватара.
	 * @return int|WP_Error ID вложения или ошибка.
	 */
	public function save_avatar( $user_id, $avatar_url ) {
		$user_id    = absint( $user_id );
		$avatar_url = esc_url_raw( trim( (string) $avatar_url ) );

		if ( ! $user_id || empty( $avatar_url ) || ! filter_var( $avatar_url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_avatar', __( 'Некорректный URL аватара.', 'idpg-core' ) );
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$tmp = download_url( $avatar_url, 20 );

		if ( is_wp_error( $tmp ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH] save_avatar: download_url ошибка: ' . $tmp->get_error_message() );

			return $tmp;
		}

		$file_array = array(
			'name'     => 'idpg-avatar-' . $user_id . '-' . time() . '.jpg',
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, 0, null, array( 'test_form' => false ) );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- очистка временного файла.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH] save_avatar: media_handle_sideload ошибка: ' . $attachment_id->get_error_message() );

			return $attachment_id;
		}

		// Удаляем старый аватар-вложение, если было.
		$old_id = (int) get_user_meta( $user_id, self::META_AVATAR, true );
		if ( $old_id && $old_id !== (int) $attachment_id ) {
			wp_delete_attachment( $old_id, true );
		}

		update_user_meta( $user_id, self::META_AVATAR, $attachment_id );
		update_user_meta( $user_id, self::META_AVATAR_URL, $avatar_url );

		return (int) $attachment_id;
	}

	/**
	 * Определяет редирект после входа (по ТЗ: get_redirect_url).
	 *
	 * - subscriber и остальные    → главная /
	 * - event_organizer → /organizer/dashboard/
	 * - administrator   → /wp-admin/
	 *
	 * @param int $user_id ID пользователя.
	 * @return string URL редиректа.
	 */
	public function get_redirect_url( $user_id ) {
		$user = get_userdata( absint( $user_id ) );

		if ( ! $user ) {
			return home_url( '/' );
		}

		$roles = (array) $user->roles;

		if ( in_array( 'administrator', $roles, true ) ) {
			$url = admin_url();
		} elseif ( in_array( 'event_organizer', $roles, true ) ) {
			$url = home_url( '/organizer/dashboard/' );
		} elseif ( in_array( 'editor', $roles, true ) ) {
			$url = admin_url();
		} else {
			// subscriber и остальные.
			$url = home_url( '/' );
		}

		return apply_filters( 'idpg_social_auth_redirect_url', $url, $user );
	}

	// =========================================================================
	// Совместимость со старым фасадом (login).
	// =========================================================================

	/**
	 * Выполняет вход/регистрацию пользователя по данным соцсети.
	 *
	 * Совместимость: фасад Social_Auth вызывает login(). Внутри делегирует
	 * в find_or_create_user() по ТЗ и сохраняет аватар.
	 *
	 * @param string $provider Слаг провайдера (vk, mailru).
	 * @param array  $profile  Нормализованный профиль из провайдера.
	 * @return int|WP_Error ID пользователя или ошибка.
	 */
	public function login( $provider, array $profile ) {
		$user_id = $this->find_or_create_user( $provider, $profile );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Обновляем имя и аватар из свежих данных.
		$this->update_user_data( $user_id, $profile );

		/**
		 * Действие после успешного входа через соцсеть.
		 *
		 * @param int    $user_id  ID пользователя.
		 * @param string $provider Слаг провайдера.
		 */
		do_action( 'idpg_social_auth_after_login', $user_id, $provider );

		return $user_id;
	}

	/**
	 * Ищет пользователя по привязке провайдера.
	 *
	 * @param string $provider  Слаг провайдера.
	 * @param string $social_id Идентификатор в соцсети.
	 * @return int|null
	 */
	private function find_by_provider( $provider, $social_id ) {
		$query = new \WP_User_Query(
			array(
				'meta_key'   => self::META_PROVIDER,
				'meta_value' => $provider,
				'number'     => 100,
				'fields'     => 'ID',
			)
		);

		foreach ( $query->get_results() as $uid ) {
			$stored = get_user_meta( $uid, self::META_SOCIAL_ID, true );

			if ( (string) $stored === $social_id ) {
				return (int) $uid;
			}
		}

		return null;
	}

	/**
	 * Создаёт нового пользователя (внутренний, для login).
	 *
	 * @param string $provider Слаг провайдера.
	 * @param array  $profile  Профиль из соцсети.
	 * @return int|WP_Error
	 */
	private function create_user( $provider, array $profile ) {
		// Делегирует в find_or_create_user для единой логики.
		return $this->find_or_create_user( $provider, $profile );
	}

	/**
	 * Генерирует уникальный логин.
	 *
	 * @param string $provider Слаг провайдера.
	 * @param array  $profile  Профиль из соцсети.
	 * @return string
	 */
	private function generate_username( $provider, array $profile ) {
		$base = '';

		if ( ! empty( $profile['username'] ) ) {
			$base = sanitize_user( $profile['username'], true );
		}

		if ( empty( $base ) ) {
			$base = $provider . '_user';
		}

		$base     = substr( $base, 0, 46 );
		$username = $base;
		$i        = 1;

		while ( username_exists( $username ) ) {
			$suffix   = '_' . $i;
			$username = substr( $base, 0, 46 - strlen( $suffix ) ) . $suffix;
			$i++;
		}

		return $username;
	}

	/**
	 * Устанавливает имя и отображаемое имя пользователя.
	 *
	 * @param int   $user_id ID пользователя.
	 * @param array $profile Профиль из соцсети.
	 * @return void
	 */
	private function set_names( $user_id, array $profile ) {
		$first = isset( $profile['first_name'] ) ? sanitize_text_field( $profile['first_name'] ) : '';
		$last  = isset( $profile['last_name'] ) ? sanitize_text_field( $profile['last_name'] ) : '';
		$name  = trim( $first . ' ' . $last );

		$args = array( 'ID' => $user_id );

		if ( $first ) {
			$args['first_name'] = $first;
		}
		if ( $last ) {
			$args['last_name'] = $last;
		}
		if ( $name ) {
			$args['display_name'] = $name;
		}

		wp_update_user( $args );
	}

	/**
	 * Сохраняет привязку пользователя к провайдеру.
	 *
	 * @param int    $user_id   ID пользователя.
	 * @param string $provider  Слаг провайдера.
	 * @param string $social_id Идентификатор в соцсети.
	 * @return void
	 */
	private function attach_provider( $user_id, $provider, $social_id ) {
		update_user_meta( $user_id, self::META_PROVIDER, $provider );
		update_user_meta( $user_id, self::META_SOCIAL_ID, $social_id );
	}

	/**
	 * Обновляет данные пользователя (имя + аватар) из профиля.
	 *
	 * @param int   $user_id ID пользователя.
	 * @param array $profile Профиль из соцсети.
	 * @return void
	 */
	private function update_user_data( $user_id, array $profile ) {
		$this->set_names( $user_id, $profile );

		$photo = isset( $profile['avatar'] ) ? $profile['avatar'] : ( isset( $profile['photo'] ) ? $profile['photo'] : '' );

		if ( ! empty( $photo ) ) {
			$this->set_avatar_url( $user_id, esc_url_raw( $photo ) );
		}
	}

	/**
	 * Загружает аватар из соцсети во вложения и привязывает к пользователю (совместимость).
	 *
	 * @param int    $user_id   ID пользователя.
	 * @param string $photo_url URL изображения аватара.
	 * @return void
	 */
	public function set_avatar_url( $user_id, $photo_url ) {
		$this->save_avatar( $user_id, $photo_url );
	}
}
