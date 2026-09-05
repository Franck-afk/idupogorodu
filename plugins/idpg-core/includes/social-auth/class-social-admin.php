<?php
/**
 * Настройки модуля социальной авторизации.
 *
 * Страница «Настройки → Социальная авторизация», хранение опции
 * idpg_social_auth_settings (APP_ID, SECRET_KEY, ENABLED + CALLBACK_URL).
 * Провайдер: ВКонтакте.
 *
 * @package IDPG_Core
 */

namespace Idpg\SocialAuth;

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс Social_Admin
 *
 * Регистрирует подменю настроек, страницу на Settings API и
 * хранит учётные данные провайдера VK.
 */
class Social_Admin {

	/**
	 * Название опции с настройками.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'idpg_social_auth_settings';

	/**
	 * Слаг страницы настроек.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'idpg-social-auth';

	/**
	 * Способность, требуемая для доступа к странице.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Экземпляр синглтона.
	 *
	 * @var Social_Admin|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return Social_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Приватный конструктор. Регистрирует хуки админки.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Добавляет подменю «Социальная авторизация» в настройки плагина.
	 *
	 * Пункт появляется в «Настройки → Социальная авторизация».
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'Социальная авторизация', 'idpg-core' ),
			__( 'Социальная авторизация', 'idpg-core' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Регистрирует настройки через Settings API WordPress.
	 *
	 * Опция idpg_social_auth_settings хранит массив провайдера VK.
	 * Санитизация — через sanitize_settings().
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::MENU_SLUG,
			self::OPTION_KEY,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);

		add_settings_section(
			'idpg_social_auth_main',
			__( 'Провайдер OAuth 2.0', 'idpg-core' ),
			'__return_false',
			self::MENU_SLUG
		);

		add_settings_field(
			'idpg_social_auth_vk',
			__( 'VK ID (VK, OK, Mail.ru)', 'idpg-core' ),
			array( $this, 'render_vk_fields' ),
			self::MENU_SLUG,
			'idpg_social_auth_main'
		);

		add_settings_field(
			'idpg_social_auth_yandex',
			__( 'Яндекс ID', 'idpg-core' ),
			array( $this, 'render_yandex_fields' ),
			self::MENU_SLUG,
			'idpg_social_auth_main'
		);

		add_settings_field(
			'idpg_social_auth_callbacks',
			__( 'Callback URL', 'idpg-core' ),
			array( $this, 'render_callback_fields' ),
			self::MENU_SLUG,
			'idpg_social_auth_main'
		);
	}

	/**
	 * Возвращает все сохранённые настройки с дефолтами.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'vk_enabled'     => 0,
			'vk_app_id'      => '',
			'vk_secret'      => '',
			'yandex_enabled' => 0,
			'yandex_app_id'  => '',
			'yandex_secret'  => '',
		);

		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Значение конкретного параметра провайдера.
	 *
	 * @param string $provider Слаг провайдера (vk).
	 * @param string $key      Суффикс ключа (app_id, secret, enabled).
	 * @return mixed
	 */
	public static function get_provider_option( $provider, $key ) {
		$settings = self::get_settings();
		$index    = sanitize_key( $provider ) . '_' . sanitize_key( $key );

		return isset( $settings[ $index ] ) ? $settings[ $index ] : '';
	}

	/**
	 * Включён ли провайдер и заполнены ли его учётные данные.
	 *
	 * @param string $provider Слаг провайдера (vk).
	 * @return bool
	 */
	public static function is_provider_ready( $provider ) {
		$settings = self::get_settings();
		$prefix   = sanitize_key( $provider );

		return ! empty( $settings[ $prefix . '_enabled' ] )
			&& ! empty( $settings[ $prefix . '_app_id' ] )
			&& ! empty( $settings[ $prefix . '_secret' ] );
	}

	/**
	 * Готовность OneTap-виджета VK ID.
	 *
	 * Виджет — публичный OAuth-клиент: обмен code (PKCE) выполняется
	 * SDK в браузере, Client Secret не требуется. Достаточно включённого
	 * провайдера и Client ID.
	 *
	 * @return bool
	 */
	public static function is_vk_widget_ready() {
		$settings = self::get_settings();

		return ! empty( $settings['vk_enabled'] ) && ! empty( $settings['vk_app_id'] );
	}

	/**
	 * Генерирует callback URL для провайдера.
	 *
	 * По ТЗ: https://idupogorodu.ru/auth/callback/{provider}
	 * Для портабельности используется home_url(); на проде совпадёт с ТЗ.
	 *
	 * @param string $provider Слаг провайдера.
	 * @return string
	 */
	public static function get_callback_url( $provider ) {
		$provider = sanitize_key( $provider );

		$url = home_url( '/auth/callback/' . $provider . '/' );

		return apply_filters( 'idpg_social_auth_callback_url', $url, $provider );
	}

	/**
	 * Санитизация настроек перед сохранением.
	 *
	 * @param mixed $input Сырые данные из формы.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();

		return array(
			'vk_enabled'     => empty( $input['vk_enabled'] ) ? 0 : 1,
			'vk_app_id'      => isset( $input['vk_app_id'] ) ? sanitize_text_field( $input['vk_app_id'] ) : '',
			'vk_secret'      => isset( $input['vk_secret'] ) ? sanitize_text_field( $input['vk_secret'] ) : '',

			'yandex_enabled' => empty( $input['yandex_enabled'] ) ? 0 : 1,
			'yandex_app_id'  => isset( $input['yandex_app_id'] ) ? sanitize_text_field( $input['yandex_app_id'] ) : '',
			'yandex_secret'  => isset( $input['yandex_secret'] ) ? sanitize_text_field( $input['yandex_secret'] ) : '',
		);
	}

	/**
	 * Рендер полей VK (Settings API).
	 *
	 * @return void
	 */
	public function render_vk_fields() {
		$settings = self::get_settings();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( self::OPTION_KEY ); ?>[vk_enabled]"
				value="1" <?php checked( ! empty( $settings['vk_enabled'] ) ); ?> />
			<?php esc_html_e( 'Включить вход через VK ID (ENABLED)', 'idpg-core' ); ?>
		</label>
		<br /><br />
		<label for="idpg_vk_app_id"><strong><?php esc_html_e( 'Client ID (VK ID)', 'idpg-core' ); ?></strong></label><br />
		<input type="text" id="idpg_vk_app_id" class="regular-text code"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[vk_app_id]"
			value="<?php echo esc_attr( $settings['vk_app_id'] ); ?>"
			placeholder="<?php esc_attr_e( '52629123', 'idpg-core' ); ?>" />
		<p class="description"><?php esc_html_e( 'ID приложения VK ID из https://id.vk.com/about/business/go/docs/ru/vkid/latest/vk-id/connection/create-application (раздел «Создать приложение»).', 'idpg-core' ); ?></p>
		<br />
		<label for="idpg_vk_secret"><strong><?php esc_html_e( 'Client Secret (VK ID)', 'idpg-core' ); ?></strong></label><br />
		<input type="password" id="idpg_vk_secret" class="regular-text code" autocomplete="off"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[vk_secret]"
			value="<?php echo esc_attr( $settings['vk_secret'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Секрет приложения VK ID (Client Secret).', 'idpg-core' ); ?></p>
		<br />
		<p class="description">
			<?php esc_html_e( 'В настройках приложения VK ID укажите Redirect URI (см. блок «Callback URL» ниже). Поток: OAuth 2.1 + PKCE (S256), scope vkid.personal_info email. Название кнопки на /login/: «Войти через VK ID».', 'idpg-core' ); ?>
		</p>
		<br />
		<p class="description">
			<strong><?php esc_html_e( 'Обязательный Redirect URI для приложения VK ID:', 'idpg-core' ); ?></strong><br />
			<code style="user-select:all;"><?php echo esc_html( self::get_callback_url( 'vk' ) ); ?></code><br />
			<?php esc_html_e( 'Добавьте его в id.vk.com → ваше приложение → Настройки → «Доверенный Redirect URI» и укажите базовый домен сайта. URI сверяется посимвольно (включая слэш и https). При локальном тестировании на домене, не привязанном к приложению, VK вернёт ошибку «redirect_uri is missing or invalid» — вход через VK ID работает только на продовом домене из настроек приложения.', 'idpg-core' ); ?>
		</p>
		<?php
	}

	/**
	 * Рендер полей Яндекс ID (Settings API).
	 *
	 * @return void
	 */
	public function render_yandex_fields() {
		$settings = self::get_settings();
		?>
		<label>
			<input type="checkbox"
				name="<?php echo esc_attr( self::OPTION_KEY ); ?>[yandex_enabled]"
				value="1" <?php checked( ! empty( $settings['yandex_enabled'] ) ); ?> />
			<?php esc_html_e( 'Включить вход через Яндекс ID (ENABLED)', 'idpg-core' ); ?>
		</label>
		<br /><br />
		<label for="idpg_yandex_app_id"><strong><?php esc_html_e( 'APP_ID (Яндекс ID)', 'idpg-core' ); ?></strong></label><br />
		<input type="text" id="idpg_yandex_app_id" class="regular-text code"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[yandex_app_id]"
			value="<?php echo esc_attr( $settings['yandex_app_id'] ); ?>"
			placeholder="<?php esc_attr_e( 'abc123...', 'idpg-core' ); ?>" />
		<p class="description"><?php esc_html_e( 'ID клиента из https://oauth.yandex.ru/client/new', 'idpg-core' ); ?></p>
		<br />
		<label for="idpg_yandex_secret"><strong><?php esc_html_e( 'SECRET_KEY (Яндекс ID)', 'idpg-core' ); ?></strong></label><br />
		<input type="password" id="idpg_yandex_secret" class="regular-text code" autocomplete="off"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[yandex_secret]"
			value="<?php echo esc_attr( $settings['yandex_secret'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Секрет клиента (Client Secret).', 'idpg-core' ); ?></p>
		<?php
	}

	/**
	 * Рендер поля CALLBACK_URL (только для чтения).
	 *
	 * @return void
	 */
	public function render_callback_fields() {
		$providers = array( 'vk', 'yandex' );
		?>
		<p class="description" style="margin-bottom:12px;">
			<?php esc_html_e( 'Скопируйте адреса в настройки приложений провайдеров (Redirect URI). Для VK ID — в приложении на id.vk.com, для Яндекс ID — в OAuth-приложении:', 'idpg-core' ); ?>
		</p>
		<?php foreach ( $providers as $provider ) : ?>
		<label for="idpg_callback_<?php echo esc_attr( $provider ); ?>"><strong><?php echo esc_html( strtoupper( $provider ) . ' CALLBACK_URL' ); ?></strong></label><br />
		<input type="text" id="idpg_callback_<?php echo esc_attr( $provider ); ?>" class="regular-text code" readonly="readonly"
			value="<?php echo esc_attr( self::get_callback_url( $provider ) ); ?>"
			onclick="this.select();" />
		<p class="description"><?php printf( esc_html__( 'По ТЗ: https://idupogorodu.ru/auth/callback/%s', 'idpg-core' ), esc_html( $provider ) ); ?></p>
		<br />
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Отображает страницу настроек.
	 *
	 * Settings API генерирует nonce (settings_fields) и проверяет
	 * manage_options через add_options_page. Дополнительно — явная проверка.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра этой страницы.', 'idpg-core' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Социальная авторизация', 'idpg-core' ); ?></h1>
			<p><?php esc_html_e( 'Настройте OAuth-приложения VK ID (OAuth 2.1, id.vk.com) и Яндекс ID. CALLBACK_URL генерируется автоматически.', 'idpg-core' ); ?></p>

			<?php settings_errors( self::MENU_SLUG ); ?>

			<form method="post" action="options.php">
				<?php
				// Nonce (wp_nonce_field) + option_page hidden field.
				settings_fields( self::MENU_SLUG );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Сохранить настройки', 'idpg-core' ) );
				?>
			</form>
		</div>
		<?php
	}
}
