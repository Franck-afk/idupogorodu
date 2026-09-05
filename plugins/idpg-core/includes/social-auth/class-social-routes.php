<?php
/**
 * Маршруты социальной авторизации.
 *
 * Регистрирует rewrite-правила:
 *   /auth/vk/                  -> старт OAuth VK
 *   /auth/callback/{provider}/ -> коллбэк провайдера (vk)
 *   legacy /idpg-social-auth/{provider}/ -> коллбэк (совместимость)
 *
 * Обработка через template_redirect, рендер кнопок, шорткод.
 *
 * @package IDPG_Core
 */

namespace Idpg\SocialAuth;

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс Social_Routes
 *
 * Владеет rewrite-правилами, обработкой стартов и коллбэков,
 * рендером кнопок входа и шорткодом.
 */
class Social_Routes {

	/**
	 * Query-переменная старта авторизации.
	 *
	 * @var string
	 */
	const QUERY_VAR_START = 'idpg_social_start';

	/**
	 * Экземпляр синглтона.
	 *
	 * @var Social_Routes|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return Social_Routes
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Приватный конструктор — хуки.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_rewrite' ) );
		if ( did_action( 'init' ) ) {
			$this->register_rewrite();
		}
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_routes' ) );

		// Кнопки — фильтр по ТЗ (idpg_social_login_buttons), экшены (поддержка старого и нового имён).
		add_filter( 'idpg_social_login_buttons', array( $this, 'filter_buttons' ), 10, 2 );
		add_action( 'idpg_core_social_auth_buttons', array( $this, 'render_buttons' ) );
		add_action( 'idpg_social_login_buttons_action', array( $this, 'render_buttons' ) );

		// Шорткод [idpg_social_login] — регистрируем сразу (не через init, чтобы работать в том же запросе).
		$this->register_shortcode();
	}

	/**
	 * Регистрирует rewrite-правила.
	 *
	 * Вызывается на хуке init и при активации плагина (до flush).
	 *
	 * @return void
	 */
	public function register_rewrite() {
		// Старт: /auth/vk/ и /auth/yandex/.
		add_rewrite_rule(
			'^auth/(vk|yandex)/?$',
			'index.php?' . self::QUERY_VAR_START . '=$matches[1]',
			'top'
		);

		// Коллбэк по ТЗ: /auth/callback/{provider}/.
		add_rewrite_rule(
			'^auth/callback/([^/]+)/?$',
			'index.php?' . Social_Auth::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Добавляет query-переменные в белый список.
	 *
	 * @param array $vars Зарегистрированные переменные.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR_START;

		// QUERY_VAR коллбэка регистрирует Social_Auth, но дублируем для надёжности.
		if ( ! in_array( Social_Auth::QUERY_VAR, $vars, true ) ) {
			$vars[] = Social_Auth::QUERY_VAR;
		}

		return $vars;
	}

	/**
	 * Обработка маршрутов через template_redirect.
	 *
	 * @return void
	 */
	public function handle_routes() {
		// 1. Старт OAuth: /auth/vk/ | /auth/yandex/.
		$start = sanitize_key( (string) get_query_var( self::QUERY_VAR_START ) );

		if ( 'vk' === $start ) {
			Provider_Vk::instance()->start_auth();

			return;
		}

		if ( 'yandex' === $start ) {
			Provider_Yandex::instance()->start_auth();

			return;
		}

		// 2. Коллбэк: /auth/callback/{provider}/ — делегируем в Social_Auth.
		$provider = sanitize_key( (string) get_query_var( Social_Auth::QUERY_VAR ) );

		if ( ! empty( $provider ) ) {
			$request_path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			if ( false !== strpos( $request_path, '/auth/callback/' ) ) {
				$this->handle_callback( $provider );

				return;
			}
		}
	}

	/**
	 * Обработка коллбэка с проверкой state (nonce) через провайдер.
	 *
	 * @param string $provider Слаг провайдера.
	 * @return void
	 */
	private function handle_callback( $provider ) {
		$provider = sanitize_key( $provider );
		$social_auth = Social_Auth::instance();
		$provider_obj = $social_auth->get_provider( $provider );

		if ( ! $provider_obj ) {
			$this->redirect_error( 'provider_not_found' );

			return;
		}

		// Делегируем проверку state фасаду (одноразовый transient — нельзя валидировать дважды).
		$social_auth->handle_callback();
	}

	/**
	 * Редирект с ошибкой на страницу логина.
	 *
	 * @param string $code Код ошибки.
	 * @return void
	 */
	private function redirect_error( $code ) {
		wp_safe_redirect(
			add_query_arg(
				array( 'idpg_social_error' => rawurlencode( $code ) ),
				home_url( '/login/' )
			)
		);
		exit;
	}

	/**
	 * Регистрирует шорткод [idpg_social_login].
	 *
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( 'idpg_social_login', array( $this, 'shortcode' ) );
	}

	/**
	 * Шорткод [idpg_social_login] — выводит кнопки входа.
	 *
	 * @param array $atts Атрибуты шорткода (не используются).
	 * @return string HTML кнопок.
	 */
	public function shortcode( $atts ) {
		return $this->get_buttons_html();
	}

	/**
	 * Фильтр idpg_social_login_buttons — генерирует HTML, если фильтр вызван с пустым значением.
	 *
	 * @param string $html      Текущее значение фильтра.
	 * @param array  $providers Провайдеры.
	 * @return string
	 */
	public function filter_buttons( $html, $providers = null ) {
		if ( ! empty( $html ) ) {
			return $html;
		}

		return $this->build_buttons_html();
	}

	/**
	 * Строит HTML кнопок без применения фильтра (внутренний).
	 *
	 * @return string
	 */
	private function build_buttons_html() {
		if ( is_user_logged_in() ) {
			return '';
		}

		$providers = Social_Auth::instance()->get_providers();
		$vk_widget = Social_Admin::is_vk_widget_ready();

		if ( empty( $providers ) && ! $vk_widget ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<div class="idpg-social-login idpg-social-login--empty"><p class="description" style="text-align:center;color:#8a8f98;">' . esc_html__( 'Социальный вход не настроен: включите VK ID или Яндекс ID в Настройках → Социальная авторизация.', 'idpg-core' ) . '</p></div>';
			}

			return '';
		}

		ob_start();
		?>
		<div class="idpg-social-login">
			<div class="idpg-social-login__divider"><span><?php esc_html_e( 'или', 'idpg-core' ); ?></span></div>
			<?php if ( ! empty( $providers ) ) : ?>
			<div class="idpg-social-login__buttons">
				<?php foreach ( $providers as $slug => $provider_obj ) : ?>
					<?php
					// VK представлен OneTap-виджетом ниже — ссылку не дублируем.
					if ( 'vk' === $slug && $vk_widget ) {
						continue;
					}
					$start_url = home_url( '/auth/' . sanitize_key( $slug ) . '/' );
					$label     = ( 'yandex' === $slug ) ? __( 'Войти через Яндекс ID', 'idpg-core' ) : __( 'Войти через VK ID', 'idpg-core' );
					?>
					<a class="idpg-social-login__btn idpg-social-login__btn--<?php echo esc_attr( $slug ); ?>" href="<?php echo esc_url( $start_url ); ?>">
						<span class="idpg-social-login__label"><?php echo esc_html( $label ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<?php
			// OneTap-виджет VK ID: публичный клиент — достаточно Client ID.
			if ( $vk_widget ) :
				$vk_settings = Social_Admin::get_settings();
				?>
				<div class="idpg-social-login__onetap idpg-vkid-onetap" data-idpg-vkid-app="<?php echo esc_attr( $vk_settings['vk_app_id'] ); ?>"></div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Возвращает HTML кнопок входа (с фильтром).
	 *
	 * @param bool $echo Выводить сразу (для экшена) или вернуть.
	 * @return string HTML кнопок.
	 */
	public function get_buttons_html( $echo = false ) {
		$html = $this->build_buttons_html();

		/**
		 * Фильтр кнопок социального входа.
		 *
		 * @param string $html      HTML блока кнопок.
		 * @param array  $providers Зарегистрированные провайдеры.
		 */
		$html = apply_filters( 'idpg_social_login_buttons', $html, Social_Auth::instance()->get_providers() );
		$html = apply_filters( 'idpg_core_social_login_buttons', $html, Social_Auth::instance()->get_providers() );

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return '';
		}

		return $html;
	}

	/**
	 * Рендер кнопок для экшенов (echo).
	 *
	 * @return void
	 */
	public function render_buttons() {
		echo $this->build_buttons_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

}
