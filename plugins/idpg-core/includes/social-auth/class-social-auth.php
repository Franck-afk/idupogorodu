<?php
/**
 * Модуль социальной авторизации: фасад.
 *
 * Точка входа модуля Idpg\SocialAuth: регистрирует провайдеров,
 * обрабатывает OAuth-коллбэки, выводит кнопки входа.
 *
 * @package IDPG_Core
 */

namespace Idpg\SocialAuth;

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( __NAMESPACE__ . '\idpg_social_auth_autoload' ) ) {
	/**
	 * SPL-автозагрузчик классов модуля.
	 *
	 * Композер-автозагрузка (PSR-4) объявлена в composer.json, однако
	 * WP-соглашение об именах файлов (class-social-auth.php) не совпадает
	 * со строгим PSR-4, поэтому дублируем загрузку собственным маппером:
	 * Idpg\SocialAuth\Social_User -> class-social-user.php.
	 *
	 * @param string $class_name Полное имя класса.
	 * @return void
	 */
	function idpg_social_auth_autoload( $class_name ) {
		$prefix = 'Idpg\\SocialAuth\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$slug     = str_replace( array( '\\', '_' ), array( '/', '-' ), strtolower( $relative ) );
		$file     = IDPG_CORE_DIR . 'includes/social-auth/class-' . $slug . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	spl_autoload_register( __NAMESPACE__ . '\idpg_social_auth_autoload' );
}

/**
 * Класс Social_Auth
 *
 * Фасад модуля: инициализирует админку, обработку пользователя
 * и провайдеров, владеет маршрутом OAuth-коллбэка.
 */
final class Social_Auth {

	/**
	 * Query-переменная маршрута коллбэка.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'idpg_social_auth';

	/**
	 * Срок жизни (в секундах) токена state-параметра.
	 *
	 * @var int
	 */
	const STATE_TTL = 600;

	/**
	 * Экземпляр синглтона.
	 *
	 * @var Social_Auth|null
	 */
	private static $instance = null;

	/**
	 * Зарегистрированные провайдеры (slug => объект).
	 *
	 * @var array<string, Social_Provider>
	 */
	private $providers = array();

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return Social_Auth
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Приватный конструктор — инициализация модуля.
	 */
	private function __construct() {
		// Субмодули.
		Social_Admin::instance();
		Social_User::instance();

		// Регистрация провайдеров, включённых в настройках.
		add_action( 'init', array( $this, 'register_providers' ) );

		// Если конструктор вызван внутри уже выполняющегося init (idpg_core_init на init:10),
		// хук register_providers, добавленный выше, не успеет сработать в текущем запросе.
		// Регистрируем сразу для текущего запроса.
		if ( did_action( 'init' ) ) {
			$this->register_providers();
		}

		// Маршрут OAuth-коллбэка: /idpg-social-auth/<provider>/.
		add_action( 'init', array( $this, 'register_rewrite' ) );
		if ( did_action( 'init' ) ) {
			$this->register_rewrite();
		}
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'handle_callback' ) );

		// Принудительный сброс правил перезаписи при первом запуске модуля.
		$this->maybe_flush_rewrite();

		// Кнопки входа — рендерит Social_Routes (делегируем, чтобы не дублировать).
		// add_action( 'idpg_core_social_auth_buttons', array( $this, 'render_buttons' ) );

		// Стили кнопок.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Регистрирует провайдеров, которые включены в настройках.
	 *
	 * @return void
	 */
	public function register_providers() {
		$vk     = Provider_Vk::instance();
		$yandex = Provider_Yandex::instance();

		if ( Social_Admin::is_provider_ready( $vk->get_id() ) ) {
			$this->providers[ $vk->get_id() ] = $vk;
		}

		if ( Social_Admin::is_provider_ready( $yandex->get_id() ) ) {
			$this->providers[ $yandex->get_id() ] = $yandex;
		}

		/**
		 * Фильтр зарегистрированных провайдеров.
		 *
		 * @param array<string, Social_Provider> $providers Список доступных провайдеров.
		 */
		$this->providers = apply_filters( 'idpg_social_auth_providers', $this->providers );
	}

	/**
	 * Возвращает список доступных (включённых) провайдеров.
	 *
	 * @return array<string, Social_Provider>
	 */
	public function get_providers() {
		return $this->providers;
	}

	/**
	 * Возвращает провайдера по слагу.
	 *
	 * @param string $id Слаг провайдера (vk, mailru).
	 * @return Social_Provider|null
	 */
	public function get_provider( $id ) {
		$id = sanitize_key( $id );

		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * Регистрация правил перезаписи для коллбэков.
	 *
	 * По ТЗ: https://idupogorodu.ru/auth/callback/{provider}
	 * Держим также старый путь /idpg-social-auth/ для обратной совместимости.
	 *
	 * @return void
	 */
	public function register_rewrite() {
		add_rewrite_rule(
			'^auth/callback/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		// Legacy: /idpg-social-auth/{provider}/.
		add_rewrite_rule(
			'^idpg-social-auth/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Передаёт маршрут коллбэка в список query-переменных.
	 *
	 * @param array $vars Текущие query-переменные.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Сбрасывает rewrite-правила при первом включении модуля / смене маршрута.
	 *
	 * Версия 5: добавлен провайдер Яндекс ID (/auth/yandex/).
	 *
	 * @return void
	 */
	private function maybe_flush_rewrite() {
		$version = (int) get_option( 'idpg_social_auth_rewrite_flushed', 0 );

		// Текущая версия правил — 5.
		if ( 5 <= $version ) {
			return;
		}

		add_action(
			'shutdown',
			function () {
				flush_rewrite_rules();
			}
		);

		update_option( 'idpg_social_auth_rewrite_flushed', 5 );
	}

	/**
	 * URL коллбэка провайдера.
	 *
	 * По ТЗ: https://idupogorodu.ru/auth/callback/{provider}
	 * Делегирует в Social_Admin для единого источника (DRY).
	 *
	 * @param string $provider Слаг провайдера.
	 * @return string
	 */
	public function get_callback_url( $provider ) {
		return Social_Admin::get_callback_url( $provider );
	}

	/**
	 * Обработка OAuth-коллбэка.
	 *
	 * Проверяет state (CSRF), получает токен и профиль, выполняет вход,
	 * редиректит в ЛК.
	 *
	 * @return void
	 */
	public function handle_callback() {
		$provider = sanitize_key( (string) get_query_var( self::QUERY_VAR ) );

		// Коллбэк OneTap-виджета VK ID валиден и без secret (state держит
		// SDK в браузере, обмен code выполняет клиент) — регистрируем
		// провайдер, если включён виджет.
		if ( 'vk' === $provider && ! $this->get_provider( $provider ) && Social_Admin::is_vk_widget_ready() ) {
			$this->providers['vk'] = Provider_Vk::instance();
		}

		if ( empty( $provider ) || ! $this->get_provider( $provider ) ) {
			return;
		}

		// Параметры из запроса.
		$params = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CSRF проверяется через state.

		// Провайдер прислал ошибку (пользователь отклонил доступ).
		if ( isset( $params['error'] ) || ! isset( $params['code'] ) ) {
			$this->redirect_error( 'access_denied' );
		}

		// Проверка state (защита от CSRF).
		$state = isset( $params['state'] ) ? (string) $params['state'] : '';

		if ( ! $this->verify_state( $provider, $state ) ) {
			// Не наш server-flow state. Если это коллбэк OneTap-виджета VK ID
			// (SDK сам держит PKCE-verifier в браузере) — отдаём страницу-мост,
			// где SDK завершит обмен и передаст токен в REST.
			if ( 'vk' === $provider && Social_Admin::is_vk_widget_ready() && ! empty( $params['code'] ) ) {
				$this->log( 'info', 'VK ID: коллбэк виджета (state не серверный) — рендер моста' );
				$this->render_vkid_bridge( $params );
				exit;
			}

			$this->log( 'warning', 'Соц. вход: несоответствие state для провайдера ' . $provider );
			$this->redirect_error( 'invalid_state' );
		}

		// Получение токена.
		$provider_obj = $this->get_provider( $provider );
		$token        = $provider_obj->get_access_token( $params );

		if ( is_wp_error( $token ) ) {
			$this->log( 'error', 'Соц. вход: ошибка токена ' . $provider . ': ' . $token->get_error_message(), $token->get_error_code() );
			$this->redirect_error( 'token_failed' );
		}

		// Токен может прийти строкой или массивом (VK присылает email вместе с токеном).
		if ( is_array( $token ) && ! empty( $token['access_token'] ) ) {
			$access_token = (string) $token['access_token'];

			if ( ! empty( $token['email'] ) ) {
				$params['__email'] = sanitize_email( $token['email'] );
			}
		} else {
			$access_token = (string) $token;
		}

		// Получение профиля.
		$profile = $provider_obj->get_profile( $access_token );

		if ( empty( $profile['id'] ) ) {
			$this->log( 'error', 'Соц. вход: пустой профиль от ' . $provider );
			$this->redirect_error( 'profile_failed' );
		}

		// Подставляем email из ответа токена, если его нет в профиле.
		if ( empty( $profile['email'] ) && ! empty( $params['__email'] ) ) {
			$profile['email'] = $params['__email'];
		}

		// Вход / регистрация пользователя.
		$user_id = Social_User::instance()->login( $provider, $profile );

		if ( is_wp_error( $user_id ) ) {
			$this->log( 'error', 'Соц. вход: ошибка входа ' . $provider . ': ' . $user_id->get_error_message(), $user_id->get_error_code() );
			$this->redirect_error( 'login_failed' );
		}

		// Авторизация.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		$user = get_userdata( $user_id );
		do_action( 'wp_login', $user->user_login, $user );

		$this->log( 'info', 'Соц. вход: пользователь #' . $user_id . ' вошёл через ' . $provider );

		wp_safe_redirect( $this->get_redirect_after_login() );
		exit;
	}

	/**
	 * Создаёт одноразовый токен state для провайдера.
	 *
	 * @param string $provider Слаг провайдера.
	 * @return string Токен state.
	 */
	public function create_state( $provider ) {
		$state = wp_generate_password( 32, false, false );
		set_transient( 'idpg_social_state_' . $state, sanitize_key( $provider ), self::STATE_TTL );

		return $state;
	}

	/**
	 * Проверяет state-параметр (одноразовый).
	 *
	 * @param string $provider Слаг провайдера.
	 * @param string $state    Возвращённый state.
	 * @return bool
	 */
	private function verify_state( $provider, $state ) {
		$state = preg_replace( '/[^A-Za-z0-9]/', '', (string) $state );

		if ( empty( $state ) ) {
			return false;
		}

		$stored = get_transient( 'idpg_social_state_' . $state );

		if ( false === $stored || $stored !== sanitize_key( $provider ) ) {
			return false;
		}

		// Одноразовое использование.
		delete_transient( 'idpg_social_state_' . $state );

		return true;
	}

	/**
	 * URL редиректа после успешного входа.
	 *
	 * Подписчики — на главную, администраторы/редакторы/организаторы — в админку.
	 *
	 * @return string
	 */
	public function get_redirect_after_login() {
		$user     = wp_get_current_user();
		$redirect = home_url( '/' );

		if ( $user->exists() && array_intersect( array( 'administrator', 'editor', 'event_organizer' ), (array) $user->roles ) ) {
			$redirect = admin_url();
		}

		return apply_filters( 'idpg_social_auth_redirect', $redirect, $user );
	}

	/**
	 * Выводит кнопки входа через соцсети.
	 *
	 * Вызывается из шаблона /login/ через do_action( 'idpg_core_social_auth_buttons' ).
	 *
	 * @return void
	 */
	public function render_buttons() {
		// Только для неавторизованных и при наличии включённых провайдеров.
		if ( is_user_logged_in() || empty( $this->providers ) ) {
			return;
		}

		echo '<div class="idpg-social-auth">';
		echo '<div class="idpg-social-auth__divider"><span>' . esc_html__( 'или войдите через', 'idpg-core' ) . '</span></div>';
		echo '<div class="idpg-social-auth__buttons">';

		foreach ( $this->providers as $slug => $provider_obj ) {
			printf(
				'<a class="idpg-social-auth__btn idpg-social-auth__btn--%1$s" href="%2$s">%3$s</a>',
				esc_attr( $slug ),
				esc_url( $provider_obj->get_auth_url() ),
				esc_html( $provider_obj->get_label() )
			);
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Подключает стили кнопок на странице входа.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! is_page( 'login' ) ) {
			return;
		}

		wp_enqueue_style(
			'idpg-social-auth',
			IDPG_CORE_URL . 'assets/css/social-auth.css',
			array(),
			IDPG_CORE_VERSION
		);

		// OneTap-виджет VK ID: официальный @vkid/sdk (UMD) + обработчик.
		// Виджет — публичный OAuth-клиент (PKCE внутри SDK), достаточен Client ID.
		if ( Social_Admin::is_vk_widget_ready() ) {
			// Пиновая версия: unpkg-диапазон @<3.0.0 резолвится в 2.6.8.
			wp_enqueue_script(
				'idpg-vkid-sdk',
				'https://unpkg.com/@vkid/sdk@2.6.8/dist-sdk/umd/index.js',
				array(),
				'2.6.8',
				false
			);

			$onetap_ver = file_exists( IDPG_CORE_DIR . 'assets/js/vkid-onetap.js' )
				? (string) filemtime( IDPG_CORE_DIR . 'assets/js/vkid-onetap.js' )
				: IDPG_CORE_VERSION;

			wp_enqueue_script(
				'idpg-vkid-onetap',
				IDPG_CORE_URL . 'assets/js/vkid-onetap.js',
				array( 'idpg-vkid-sdk' ),
				$onetap_ver,
				true
			);

			wp_localize_script(
				'idpg-vkid-onetap',
				'idpgVkId',
				array(
					'app'         => Social_Admin::get_provider_option( 'vk', 'app_id' ),
					'redirectUrl' => Social_Admin::get_callback_url( 'vk' ),
					'scope'       => 'vkid.personal_info email',
					'restUrl'     => esc_url_raw( rest_url( 'idpg/v1' ) ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
	}

	/**
	 * Страница-мост для коллбэка OneTap-виджета VK ID.
	 *
	 * После полного редиректа VK (напр. «Другой способ» в виджете) браузер
	 * возвращается на /auth/callback/vk/?code=…&state=…&device_id=….
	 * PKCE-verifier у SDK в localStorage браузера — сервер обменять код
	 * не может. Страница грузит @vkid/sdk, SDK читает code из URL,
	 * обменивает его на access_token, и JS передаёт токен в
	 * REST /idpg/v1/auth/vkid (сервер верифицирует через user_info).
	 *
	 * @param array $params GET-параметры коллбэка (code, state, device_id).
	 * @return void
	 */
	private function render_vkid_bridge( $params ) {
		$code      = isset( $params['code'] ) ? sanitize_text_field( wp_unslash( $params['code'] ) ) : '';
		$device_id = isset( $params['device_id'] ) ? sanitize_text_field( wp_unslash( $params['device_id'] ) ) : '';

		$config = array(
			'app'      => Social_Admin::get_provider_option( 'vk', 'app_id' ),
			'scope'    => 'vkid.personal_info email',
			'restUrl'  => esc_url_raw( rest_url( 'idpg/v1' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'loginUrl' => home_url( '/login/' ),
			'code'     => $code,
			'deviceId' => $device_id,
		);

		nocache_headers();
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Вход через VK ID…', 'idpg-core' ); ?></title>
	<style>
		body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100dvh; margin: 0; background: #f6f7f9; color: #1d2327; }
		.idpg-bridge { text-align: center; padding: 32px; }
		.idpg-bridge__spinner { width: 28px; height: 28px; margin: 0 auto 16px; border: 3px solid rgba(0,0,0,.12); border-top-color: #0052ff; border-radius: 50%; animation: idpg-spin .8s linear infinite; }
		@keyframes idpg-spin { to { transform: rotate(360deg); } }
		.idpg-bridge__error { display: none; color: #b32d2e; margin-top: 12px; }
		.idpg-bridge__back { display: none; margin-top: 16px; }
	</style>
</head>
<body>
	<div class="idpg-bridge">
		<div class="idpg-bridge__spinner" aria-hidden="true"></div>
		<p class="idpg-bridge__text"><?php esc_html_e( 'Завершаем вход через VK ID…', 'idpg-core' ); ?></p>
		<p class="idpg-bridge__error" id="idpg-bridge-error"></p>
		<p class="idpg-bridge__back" id="idpg-bridge-back"><a href="<?php echo esc_url( home_url( '/login/' ) ); ?>"><?php esc_html_e( '← Вернуться ко входу', 'idpg-core' ); ?></a></p>
	</div>

	<script src="https://unpkg.com/@vkid/sdk@2.6.8/dist-sdk/umd/index.js"></script>
	<script>
	(function () {
		var config = <?php echo wp_json_encode( $config ); ?>;
		var errEl = document.getElementById('idpg-bridge-error');
		var backEl = document.getElementById('idpg-bridge-back');

		function showFail(msg) {
			var sp = document.querySelector('.idpg-bridge__spinner');
			if (sp) { sp.style.display = 'none'; }
			var tx = document.querySelector('.idpg-bridge__text');
			if (tx) { tx.textContent = '<?php echo esc_js( __( 'Не удалось завершить вход.', 'idpg-core' ) ); ?>'; }
			errEl.textContent = msg || '';
			errEl.style.display = 'block';
			backEl.style.display = 'block';
		}

		if (!('VKIDSDK' in window)) { showFail('SDK не загрузился'); return; }
		if (!config.code) { showFail('Код авторизации не передан'); return; }

		var VKID = window.VKIDSDK;

		VKID.Config.init({
			app: parseInt(config.app, 10),
			redirectUrl: window.location.origin + '/auth/callback/vk/',
			// mode=Redirect — чтобы SDK не пытался открывать новую вкладку
			// (источник ошибки «New tab has been closed»).
			mode: VKID.ConfigAuthMode.Redirect,
			responseMode: VKID.ConfigResponseMode.Redirect,
			source: VKID.ConfigSource.LOWCODE,
			scope: config.scope,
		});

		var hasOpener = window.opener && window.opener !== window;

		if (hasOpener) {
			// Pop-up/новая вкладка: SDK сам читает ?code из URL, отправляет
			// postMessage в opener (там сработает LOGIN_SUCCESS → exchangeCode
			// → REST /auth/vkid → вход в исходной вкладке) и закрывает окно.
			// Страховка: если окно не закрылось — показываем подсказку.
			setTimeout(function () {
				if (!window.closed) {
					showFail('<?php echo esc_js( __( 'Окно не закрылось автоматически. Закройте вкладку и повторите вход.', 'idpg-core' ) ); ?>');
				}
			}, 5000);

			return;
		}

		// Тот же таб (редирект без попапа) — обмениваем код и входим здесь.
		VKID.Auth.exchangeCode(config.code, config.deviceId || undefined)
			.then(function (data) {
				if (!data || !data.access_token) { showFail('Пустой токен'); return; }

				return fetch(config.restUrl + '/auth/vkid', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
					body: JSON.stringify({ access_token: data.access_token, id_token: data.id_token || '' }),
				})
					.then(function (r) { return r.json(); })
					.then(function (json) {
						if (json && json.success && json.redirect_url) {
							window.location.href = json.redirect_url;
						} else {
							showFail((json && json.message) || '');
						}
					});
			})
			.catch(function (e) { showFail((e && e.code) || 'exchange_failed'); });
	})();
	</script>
</body>
</html>
		<?php
	}

	/**
	 * Редирект на страницу входа с кодом ошибки.
	 *
	 * @param string $code Код ошибки.
	 * @return void
	 */
	private function redirect_error( $code ) {
		$url = add_query_arg(
			array(
				'idpg_social_error' => rawurlencode( $code ),
			),
			home_url( '/login/' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Логирование событий модуля.
	 *
	 * @param string $level   Уровень: info|warning|error.
	 * @param string $message Сообщение.
	 * @param mixed  $context Контекст (необязательно).
	 * @return void
	 */
	public function log( $level, $message, $context = null ) {
		if ( WP_DEBUG && WP_DEBUG_LOG ) {
			$line = sprintf( '[SOCIAL-AUTH][%s] %s', strtoupper( $level ), $message );

			if ( null !== $context ) {
				$line .= ' | ' . wp_json_encode( $context );
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $line );
		}

		/**
		 * Действие для перехвата событий модуля.
		 *
		 * @param string $level   Уровень.
		 * @param string $message Сообщение.
		 * @param mixed  $context Контекст.
		 */
		do_action( 'idpg_social_auth_log', $level, $message, $context );
	}
}
