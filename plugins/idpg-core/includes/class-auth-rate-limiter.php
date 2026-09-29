<?php
/**
 * Защита от брутфорса для форм авторизации.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Auth_Rate_Limiter
 *
 * Ограничивает количество попыток входа, регистрации и сброса пароля
 * с одного IP-адреса или email-адреса за единицу времени.
 */
class IDPG_Auth_Rate_Limiter {

	/**
	 * Экземпляр синглтона.
	 *
	 * @var IDPG_Auth_Rate_Limiter|null
	 */
	private static $instance = null;

	/**
	 * Максимальное количество попыток входа в минуту.
	 *
	 * @var int
	 */
	const LOGIN_LIMIT = 5;

	/**
	 * Интервал блокировки входа (секунд).
	 *
	 * @var int
	 */
	const LOGIN_WINDOW = 60;

	/**
	 * Максимальное количество регистраций в час.
	 *
	 * @var int
	 */
	const REGISTRATION_LIMIT = 3;

	/**
	 * Интервал блокировки регистрации (секунд).
	 *
	 * @var int
	 */
	const REGISTRATION_WINDOW = 3600;

	/**
	 * Максимальное количество запросов сброса пароля в час.
	 *
	 * @var int
	 */
	const RESET_LIMIT = 3;

	/**
	 * Не более RESET_IP_LIMIT запросов сброса пароля в час с одного
	 * IP-адреса. Лимит по email не спасает от перебора: злоумышленник
	 * подставляет разные адреса и на каждом свой счётчик, поэтому
	 * нужен второй, независимый счётчик по адресу клиента.
	 */
	const RESET_IP_LIMIT = 10;

	/**
	 * Окно лимита по IP в секундах, совпадает с RESET_WINDOW.
	 */
	const RESET_IP_WINDOW = 3600;

	/**
	 * Интервал блокировки сброса пароля (секунд).
	 *
	 * @var int
	 */
	const RESET_WINDOW = 3600;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Auth_Rate_Limiter
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
	 * Проверка лимита попыток входа.
	 *
	 * Если с одного IP сделано более LOGIN_LIMIT попыток за LOGIN_WINDOW секунд,
	 * возвращает WP_Error с кодом 429.
	 *
	 * @param string $ip IP-адрес клиента.
	 * @return true|WP_Error True, если лимит не превышен, иначе WP_Error.
	 */
	public function check_login_attempt( $ip ) {
		$key   = 'idpg_login_attempts_' . md5( $ip );
		$data  = get_transient( $key );

		if ( false === $data ) {
			$data = array(
				'count'         => 0,
				'first_attempt' => time(),
			);
		}

		if ( $data['count'] >= self::LOGIN_LIMIT ) {
			$elapsed = time() - $data['first_attempt'];

			if ( $elapsed < self::LOGIN_WINDOW ) {
			// Запрещено на проде (AGENTS.md, п.11): error_log() в боевом коде не используется.
			// error_log(
			// 	sprintf(
			// 		'[IDPG Rate Limiter] Превышен лимит входа. IP: %s, попыток: %d',
			// 		$ip,
			// 		$data['count']
			// 	)
			// );

			return new WP_Error(
				'too_many_attempts',
					__( 'Слишком много попыток. Подождите 1 минуту.', 'idpg-core' ),
					array( 'status' => 429 )
				);
			}

			// Окно истекло — сброс.
			$data = array(
				'count'         => 0,
				'first_attempt' => time(),
			);
		}

		$data['count']++;

		set_transient( $key, $data, self::LOGIN_WINDOW );

		return true;
	}

	/**
	 * Проверка лимита регистраций.
	 *
	 * Не более REGISTRATION_LIMIT регистраций в час с одного IP.
	 *
	 * @param string $ip IP-адрес клиента.
	 * @return true|WP_Error True, если лимит не превышен, иначе WP_Error.
	 */
	public function check_registration_attempt( $ip ) {
		$key  = 'idpg_reg_attempts_' . md5( $ip );
		$data = get_transient( $key );

		if ( false === $data ) {
			$data = array(
				'count'         => 0,
				'first_attempt' => time(),
			);
		}

		if ( $data['count'] >= self::REGISTRATION_LIMIT ) {
			$elapsed = time() - $data['first_attempt'];

			if ( $elapsed < self::REGISTRATION_WINDOW ) {
			// Запрещено на проде (AGENTS.md, п.11): error_log() в боевом коде не используется.
			// error_log(
			// 	sprintf(
			// 		'[IDPG Rate Limiter] Превышен лимит регистраций. IP: %s, попыток: %d',
			// 		$ip,
			// 		$data['count']
			// 	)
			// );

			return new WP_Error(
				'too_many_registrations',
					__( 'Слишком много регистраций с этого IP. Попробуйте позже.', 'idpg-core' ),
					array( 'status' => 429 )
				);
			}

			$data = array(
				'count'         => 0,
				'first_attempt' => time(),
			);
		}

		$data['count']++;

		set_transient( $key, $data, self::REGISTRATION_WINDOW );

		return true;
	}

	/**
	 * Проверка лимита запросов на сброс пароля.
	 *
	 * Не более RESET_LIMIT запросов в час на один email.
	 *
	 * @param string $email Email-адрес.
	 * @return true|WP_Error True, если лимит не превышен, иначе WP_Error.
	 */
	public function check_password_reset_attempt( $email ) {
		$key  = 'idpg_reset_attempts_' . md5( $email );
		$data = get_transient( $key );

		if ( false === $data ) {
			$data = array(
				'count'         => 0,
				'first_attempt' => time(),
			);
		}

		if ( $data['count'] >= self::RESET_LIMIT ) {
			$elapsed = time() - $data['first_attempt'];

			if ( $elapsed < self::RESET_WINDOW ) {
			// Запрещено на проде (AGENTS.md, п.11): error_log() в боевом коде не используется.
			// error_log(
			// 	sprintf(
			// 		'[IDPG Rate Limiter] Превышен лимит сброса пароля. Email: %s, попыток: %d',
			// 		$email,
			// 		$data['count']
			// 	)
			// );

			return new WP_Error(
				'too_many_resets',
					__( 'Слишком много запросов сброса пароля. Попробуйте позже.', 'idpg-core' ),
					array( 'status' => 429 )
				);
			}

			$data = array(
				'count'         => 0,
				'first_attempt' => time(),
			);
		}

		$data['count']++;

		set_transient( $key, $data, self::RESET_WINDOW );

		return true;
	}

	/**
	 * Проверка лимита запросов на сброс пароля по IP-адресу.
	 *
	 * Счётчик независим от счётчика по email: перебор множества
	 * разных адресов с одной машины исчерпывает именно его.
	 *
	 * Неопределимый адрес (пустая строка, «не-ip», 0.0.0.0 за
	 * общим прокси) не лимитируется — иначе все клиенты прокси
	 * делили бы один счётчик.
	 *
	 * @param string $ip IP-адрес клиента.
	 * @return true|WP_Error True, если лимит не превышен, иначе WP_Error.
	 */
	public function check_password_reset_ip_attempt( $ip ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';

		// Мусор и заведомо неопределимые адреса пропускаем без счётчика.
		if ( '' === $ip || '0.0.0.0' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		$key  = 'idpg_reset_ip_attempts_' . md5( $ip );
		$data = get_transient( $key );

		if ( false === $data ) {
			$data = array(
				'count'         => 0,
				'first_attempt' => time(),
			);
		}

		if ( $data['count'] >= self::RESET_IP_LIMIT ) {
			$elapsed = time() - $data['first_attempt'];

			if ( $elapsed < self::RESET_IP_WINDOW ) {
				return new WP_Error(
					'too_many_resets_from_ip',
					__( 'Слишком много запросов сброса пароля с вашего устройства. Попробуйте позже.', 'idpg-core' ),
					array( 'status' => 429 )
				);
			}

			$data = array(
				'count'         => 0,
				'first_attempt' => time(),
			);
		}

		$data['count']++;

		set_transient( $key, $data, self::RESET_IP_WINDOW );

		return true;
	}

	/**
	 * Сброс счетчика попыток после успешного входа.
	 *
	 * @param string $ip IP-адрес клиента.
	 * @return void
	 */
	public function reset_attempts( $ip ) {
		$key = 'idpg_login_attempts_' . md5( $ip );

		delete_transient( $key );

		// Запрещено на проде (AGENTS.md, п.11): error_log() в боевом коде не используется.
		// error_log(
		// 	sprintf(
		// 		'[IDPG Rate Limiter] Сброс попыток входа. IP: %s',
		// 		$ip
		// 	)
		// );
	}

	/**
	 * Получение IP-адреса клиента.
	 *
	 * REMOTE_ADDR — адрес, заданный самим веб-сервером, его нельзя подменить.
	 * Заголовки X-Forwarded-For / X-Real-IP / Client-IP доверяются ТОЛЬКО
	 * тогда, когда REMOTE_ADDR указывает на прокси (loopback/приватный адрес),
	 * иначе их можно подделать и обойти ограничения.
	 * Возвращает валидный IPv4/IPv6-адрес или '0.0.0.0', если определить не удалось.
	 *
	 * @return string
	 */
	public function get_client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		// Прямое соединение с публичным адресом — прокси-заголовки не используем.
		if ( filter_var( $remote, FILTER_VALIDATE_IP ) && ! $this->is_proxy_address( $remote ) ) {
			return $remote;
		}

		// Соединение через прокси (REMOTE_ADDR — адрес прокси).
		if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			// Приоритет 1: X-Forwarded-For (самый надёжный за прокси).
			if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
				$ips       = explode( ',', $forwarded );
				$first     = trim( $ips[0] );

				if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
					return $first;
				}
			}

			// Приоритет 2: X-Real-IP (используется в nginx).
			if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
				$real_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );

				if ( filter_var( $real_ip, FILTER_VALIDATE_IP ) ) {
					return $real_ip;
				}
			}

			// Приоритет 3: Client-IP (менее стандартный).
			if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
				$client_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );

				if ( filter_var( $client_ip, FILTER_VALIDATE_IP ) ) {
					return $client_ip;
				}
			}
		}

		if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return $remote;
		}

		return '0.0.0.0';
	}

	/**
	 * Является ли адрес адресом прокси-сервера.
	 *
	 * Loopback, приватные и зарезервированные диапазоны IPv4/IPv6
	 * считаются адресами прокси.
	 *
	 * @param string $ip IP-адрес.
	 * @return bool
	 */
	private function is_proxy_address( $ip ) {
		return filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) === false;
	}
}
