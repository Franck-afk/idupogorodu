<?php
/**
 * Режим «Сайт в разработке».
 *
 * Позволяет закрыть сайт для посетителей заглушкой (HTTP 503)
 * и управлять текстом заглушки со страницы настроек в админке.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Maintenance
 *
 * Добавляет страницу «Режим разработки» в меню «Настройки»
 * и выводит страницу обслуживания на фронтенде, пока режим включён.
 */
class IDPG_Maintenance {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Maintenance|null
	 */
	private static $instance = null;

	/**
	 * Slug страницы настроек.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'idpg_maintenance';

	/**
	 * Префикс cookie для доступа по паролю.
	 *
	 * @var string
	 */
	const ACCESS_COOKIE = 'idpg_maintenance_pass';

	/**
	 * Действие nonce для формы пароля доступа.
	 *
	 * @var string
	 */
	const ACCESS_NONCE_ACTION = 'idpg_maintenance_access';

	/**
	 * Имя поля с nonce в форме пароля доступа.
	 *
	 * @var string
	 */
	const ACCESS_NONCE_FIELD = 'idpg_maintenance_nonce';

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Maintenance
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
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'template_redirect', array( $this, 'maybe_show_maintenance' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_badge' ), 100 );
		add_action( 'admin_print_styles', array( $this, 'admin_bar_badge_style' ) );
		add_action( 'update_option_idpg_maintenance_enabled', array( $this, 'flush_cache_on_status_change' ), 10, 2 );
		add_filter( 'wp_headers', array( $this, 'add_retry_after_header' ) );
	}

	/**
	 * Регистрация страницы настроек в админке.
	 */
	public function register_settings_page() {
		add_options_page(
			__( 'Режим разработки', 'idpg-core' ),
			__( 'Режим разработки', 'idpg-core' ),
			'manage_options',
			self::OPTION_GROUP,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Подключение стилей и скриптов на странице настроек.
	 *
	 * @param string $hook Текущий хук экрана.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_' . self::OPTION_GROUP !== $hook ) {
			return;
		}

		$css_version = file_exists( IDPG_CORE_DIR . 'assets/css/admin-maintenance.css' )
			? (string) filemtime( IDPG_CORE_DIR . 'assets/css/admin-maintenance.css' )
			: IDPG_CORE_VERSION;
		$js_version = file_exists( IDPG_CORE_DIR . 'assets/js/admin-maintenance.js' )
			? (string) filemtime( IDPG_CORE_DIR . 'assets/js/admin-maintenance.js' )
			: IDPG_CORE_VERSION;

		wp_enqueue_style( 'idpg-maintenance-admin', IDPG_CORE_URL . 'assets/css/admin-maintenance.css', array(), $css_version );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'idpg-iconify', IDPG_CORE_URL . 'assets/vendor/iconify/iconify.min.js', array(), IDPG_CORE_VERSION );
		wp_enqueue_script( 'idpg-social-icons', IDPG_CORE_URL . 'assets/js/social-icons.js', array( 'idpg-iconify' ), $css_version );
		wp_enqueue_script( 'idpg-maintenance-admin', IDPG_CORE_URL . 'assets/js/admin-maintenance.js', array( 'jquery', 'wp-color-picker', 'idpg-iconify' ), $js_version, true );
	}

	/**
	 * Регистрация опций и настроек.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_type',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_type' ),
				'default'           => 'dev',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_roles',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_roles' ),
				'default'           => array( 'administrator' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_ips',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_password',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_login_link',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_title',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => __( 'Сайт в разработке', 'idpg-core' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_message',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => __( 'Ведутся технические работы. Загляните позже!', 'idpg-core' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_expires',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_countdown',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_retry_after',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 3600,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_logo',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_bg_color',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_bg_color' ),
				'default'           => '#f6efe3',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_contact_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_contact_phone',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_social',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'idpg_maintenance_social_items',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_social_items' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Санитизация типа режима.
	 *
	 * @param string $value Значение.
	 * @return string
	 */
	public function sanitize_type( $value ) {
		return in_array( $value, array( 'dev', 'maintenance' ), true ) ? $value : 'dev';
	}

	/**
	 * Санитизация цвета фона заглушки.
	 *
	 * @param string $value Значение.
	 * @return string
	 */
	public function sanitize_bg_color( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^#[0-9a-f]{6}$/i', $value ) ) {
			return strtolower( $value );
		}
		return '#f6efe3';
	}

	/**
	 * Санитизация списка соцсетей: URL + slug иконки из библиотеки.
	 *
	 * @param mixed $value Значение.
	 * @return array
	 */
	public function sanitize_social_items( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$icons = $this->get_social_icons();

		$clean = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url  = isset( $item['url'] ) ? esc_url_raw( trim( (string) $item['url'] ) ) : '';
			$icon = isset( $item['icon'] ) ? sanitize_key( (string) $item['icon'] ) : '';
			if ( $icon && ! array_key_exists( $icon, $icons ) ) {
				$icon = '';
			}
			if ( $url ) {
				$clean[] = array(
					'url'  => $url,
					'icon' => $icon,
				);
			}
		}

		return $clean;
	}


	/**
	 * Санитизация списка ролей.
	 *
	 * @param mixed $value Значение.
	 * @return array
	 */
	public function sanitize_roles( $value ) {
		if ( ! is_array( $value ) ) {
			return array( 'administrator' );
		}

		return array_map( 'sanitize_key', array_values( array_filter( $value ) ) );
	}

	/**
	 * Рендер страницы настроек.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$editable_roles = $this->get_editable_roles();
		$enabled        = (bool) get_option( 'idpg_maintenance_enabled', false );
		$bg_id          = (int) get_option( 'idpg_maintenance_bg', 0 );
		$bg_url         = $bg_id ? wp_get_attachment_image_url( $bg_id, 'large' ) : '';
		$bg_color       = get_option( 'idpg_maintenance_bg_color', '#f6efe3' );
		$social_items   = (array) get_option( 'idpg_maintenance_social_items', array() );
		?>
		<div class="wrap idpg-mt-wrap">
			<div class="idpg-mt-header">
				<div class="idpg-mt-header__titles">
					<h1><?php esc_html_e( 'Режим разработки', 'idpg-core' ); ?></h1>
					<p class="description"><?php esc_html_e( 'Настройки заглушки для посетителей сайта.', 'idpg-core' ); ?></p>
				</div>
				<span class="idpg-mt-status <?php echo $enabled ? 'idpg-mt-status--on' : 'idpg-mt-status--off'; ?>">
					<?php echo $enabled ? esc_html__( 'Включён', 'idpg-core' ) : esc_html__( 'Выключен', 'idpg-core' ); ?>
				</span>
			</div>

			<div class="idpg-mt-layout">
				<form method="post" action="options.php">
					<?php
					settings_fields( self::OPTION_GROUP );
					do_settings_sections( self::OPTION_GROUP );
					?>

					<div class="idpg-mt-grid">

					<section class="idpg-mt-card">
						<h2><?php esc_html_e( 'Режим', 'idpg-core' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Заглушка', 'idpg-core' ); ?></th>
								<td>
									<label class="idpg-mt-toggle">
										<input
											type="checkbox"
											id="idpg-maintenance-enabled"
											name="idpg_maintenance_enabled"
											value="1"
											<?php checked( $enabled ); ?>
										>
										<span class="idpg-mt-toggle__track"></span>
										<span class="idpg-mt-toggle__label"><?php esc_html_e( 'Показывать заглушку посетителям', 'idpg-core' ); ?></span>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Тип режима', 'idpg-core' ); ?></th>
								<td>
									<label class="idpg-mt-radio">
										<input type="radio" name="idpg_maintenance_type" value="dev" <?php checked( $this->get_type(), 'dev' ); ?>>
										<span class="idpg-mt-radio__body">
											<strong><?php esc_html_e( 'Разработка', 'idpg-core' ); ?></strong>
											<small><?php esc_html_e( 'Заглушка только для гостей, авторизованные видят сайт.', 'idpg-core' ); ?></small>
										</span>
									</label>
									<label class="idpg-mt-radio">
										<input type="radio" name="idpg_maintenance_type" value="maintenance" <?php checked( $this->get_type(), 'maintenance' ); ?>>
										<span class="idpg-mt-radio__body">
											<strong><?php esc_html_e( 'Обслуживание', 'idpg-core' ); ?></strong>
											<small><?php esc_html_e( 'Заглушка для всех, кроме администраторов, IP и пароля.', 'idpg-core' ); ?></small>
										</span>
									</label>
								</td>
							</tr>
						</table>
					</section>

					<section class="idpg-mt-card">
						<h2><?php esc_html_e( 'Доступ', 'idpg-core' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="idpg-maintenance-password"><?php esc_html_e( 'Пароль доступа', 'idpg-core' ); ?></label>
								</th>
								<td>
									<input
										type="text"
										id="idpg-maintenance-password"
										name="idpg_maintenance_password"
										class="regular-text"
										value="<?php echo esc_attr( get_option( 'idpg_maintenance_password', '' ) ); ?>"
										autocomplete="off"
									>
									<p class="description"><?php esc_html_e( 'Посетитель вводит его на заглушке и видит сайт (доступ запоминается в cookie).', 'idpg-core' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="idpg-maintenance-ips"><?php esc_html_e( 'Белый список IP', 'idpg-core' ); ?></label>
								</th>
								<td>
									<textarea
										id="idpg-maintenance-ips"
										name="idpg_maintenance_ips"
										class="large-text"
										rows="4"
										placeholder="123.45.67.89&#10;10.0.0.1"
									><?php echo esc_textarea( get_option( 'idpg_maintenance_ips', '' ) ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Эти адреса видят сайт без пароля.', 'idpg-core' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Роли с доступом', 'idpg-core' ); ?></th>
								<td>
									<div class="idpg-mt-pills">
										<?php foreach ( $editable_roles as $role => $role_details ) : ?>
											<label class="idpg-mt-pill">
												<input
													type="checkbox"
													name="idpg_maintenance_roles[]"
													value="<?php echo esc_attr( $role ); ?>"
													<?php checked( in_array( $role, $this->get_roles(), true ) ); ?>
												>
												<span><?php echo esc_html( translate_user_role( $role_details['name'] ) ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
									<p class="description"><?php esc_html_e( 'В режиме «Разработка» сайт видят все авторизованные. Администраторы получают доступ в любом режиме.', 'idpg-core' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Ссылка «Войти»', 'idpg-core' ); ?></th>
								<td>
									<label class="idpg-mt-toggle">
										<input
											type="checkbox"
											name="idpg_maintenance_login_link"
											value="1"
											<?php checked( (bool) get_option( 'idpg_maintenance_login_link', true ) ); ?>
										>
										<span class="idpg-mt-toggle__track"></span>
										<span class="idpg-mt-toggle__label"><?php esc_html_e( 'Показывать ссылку «Войти» на заглушке', 'idpg-core' ); ?></span>
									</label>
								</td>
							</tr>
						</table>
					</section>

				<section class="idpg-mt-card">
					<h2><?php esc_html_e( 'Фон и логотип', 'idpg-core' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="idpg-maintenance-bg-color"><?php esc_html_e( 'Цвет фона', 'idpg-core' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="idpg-maintenance-bg-color"
									name="idpg_maintenance_bg_color"
									class="idpg-color-picker"
									value="<?php echo esc_attr( $bg_color ); ?>"
									data-default-color="#f6efe3"
								>
								<p class="description"><?php esc_html_e( 'Цвет подложки страницы заглушки. По умолчанию — кремово-бежевый (#f6efe3).', 'idpg-core' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="idpg-maintenance-logo"><?php esc_html_e( 'Логотип', 'idpg-core' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="idpg-maintenance-logo"
									name="idpg_maintenance_logo"
									class="regular-text"
									min="0"
									value="<?php echo esc_attr( (int) get_option( 'idpg_maintenance_logo', 0 ) ); ?>"
								>
								<p class="description"><?php esc_html_e( 'ID вложения логотипа. Оставьте 0, чтобы использовать логотип темы.', 'idpg-core' ); ?></p>
							</td>
						</tr>
					</table>
				</section>

					<section class="idpg-mt-card">
						<h2><?php esc_html_e( 'Текст заглушки', 'idpg-core' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="idpg-maintenance-title"><?php esc_html_e( 'Заголовок', 'idpg-core' ); ?></label>
								</th>
								<td>
									<input
										type="text"
										id="idpg-maintenance-title"
										name="idpg_maintenance_title"
										class="regular-text"
										value="<?php echo esc_attr( get_option( 'idpg_maintenance_title', __( 'Сайт в разработке', 'idpg-core' ) ) ); ?>"
									>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="idpg-maintenance-message"><?php esc_html_e( 'Сообщение', 'idpg-core' ); ?></label>
								</th>
								<td>
									<textarea
										id="idpg-maintenance-message"
										name="idpg_maintenance_message"
										class="large-text"
										rows="4"
									><?php echo esc_textarea( get_option( 'idpg_maintenance_message', __( 'Ведутся технические работы. Загляните позже!', 'idpg-core' ) ) ); ?></textarea>
								</td>
							</tr>
						</table>
					</section>

					<section class="idpg-mt-card">
						<h2><?php esc_html_e( 'Контакты', 'idpg-core' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="idpg-maintenance-contact-email"><?php esc_html_e( 'Email', 'idpg-core' ); ?></label>
								</th>
								<td>
									<input
										type="email"
										id="idpg-maintenance-contact-email"
										name="idpg_maintenance_contact_email"
										class="regular-text"
										value="<?php echo esc_attr( get_option( 'idpg_maintenance_contact_email', '' ) ); ?>"
									>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="idpg-maintenance-contact-phone"><?php esc_html_e( 'Телефон', 'idpg-core' ); ?></label>
								</th>
								<td>
									<input
										type="text"
										id="idpg-maintenance-contact-phone"
										name="idpg_maintenance_contact_phone"
										class="regular-text"
										value="<?php echo esc_attr( get_option( 'idpg_maintenance_contact_phone', '' ) ); ?>"
									>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Соцсети', 'idpg-core' ); ?></th>
								<td>
									<div class="idpg-social" data-social-list>
										<?php if ( $social_items ) : ?>
											<?php foreach ( $social_items as $social_index => $social_item ) : ?>
												<div class="idpg-social__row">
													<input
														type="url"
														name="idpg_maintenance_social_items[<?php echo (int) $social_index; ?>][url]"
														class="regular-text idpg-social__url"
														placeholder="https://t.me/example"
														value="<?php echo esc_attr( $social_item['url'] ); ?>"
													>
													<select name="idpg_maintenance_social_items[<?php echo (int) $social_index; ?>][icon]" class="idpg-social__icon">
														<option value="" <?php selected( '', isset( $social_item['icon'] ) ? $social_item['icon'] : '' ); ?>><?php esc_html_e( 'Авто (по домену)', 'idpg-core' ); ?></option>
														<?php foreach ( $this->get_social_icons() as $social_slug => $social_label ) : ?>
															<option value="<?php echo esc_attr( $social_slug ); ?>" <?php selected( $social_slug, isset( $social_item['icon'] ) ? $social_item['icon'] : '' ); ?>><?php echo esc_html( $social_label ); ?></option>
														<?php endforeach; ?>
													</select>
													<span class="idpg-social__preview"></span>
													<button type="button" class="button-link-delete idpg-social__remove" aria-label="<?php esc_attr_e( 'Удалить', 'idpg-core' ); ?>"><?php esc_html_e( 'Удалить', 'idpg-core' ); ?></button>
												</div>
											<?php endforeach; ?>
										<?php endif; ?>
									</div>
									<button type="button" class="button idpg-social__add"><?php esc_html_e( '+ Добавить соцсеть', 'idpg-core' ); ?></button>
									<p class="description"><?php esc_html_e( 'Ссылка на соцсеть и иконка из встроенной библиотеки. Если иконка не выбрана — подставится автоматически по домену.', 'idpg-core' ); ?></p>

									<template id="idpg-social-row-template">
										<div class="idpg-social__row">
											<input type="url" name="idpg_maintenance_social_items[0][url]" class="regular-text idpg-social__url" placeholder="https://t.me/example" value="">
											<select name="idpg_maintenance_social_items[0][icon]" class="idpg-social__icon">
												<option value=""><?php esc_html_e( 'Авто (по домену)', 'idpg-core' ); ?></option>
												<?php foreach ( $this->get_social_icons() as $social_slug => $social_label ) : ?>
													<option value="<?php echo esc_attr( $social_slug ); ?>"><?php echo esc_html( $social_label ); ?></option>
												<?php endforeach; ?>
											</select>
											<span class="idpg-social__preview"></span>
											<button type="button" class="button-link-delete idpg-social__remove" aria-label="<?php esc_attr_e( 'Удалить', 'idpg-core' ); ?>"><?php esc_html_e( 'Удалить', 'idpg-core' ); ?></button>
										</div>
									</template>
								</td>
							</tr>
						</table>
					</section>

					<section class="idpg-mt-card">
						<h2><?php esc_html_e( 'Завершение', 'idpg-core' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">
									<label for="idpg-maintenance-expires"><?php esc_html_e( 'Авто-выключение', 'idpg-core' ); ?></label>
								</th>
								<td>
									<input
										type="datetime-local"
										id="idpg-maintenance-expires"
										name="idpg_maintenance_expires"
										value="<?php echo esc_attr( $this->get_expires_local() ); ?>"
									>
									<p class="description"><?php esc_html_e( 'Режим отключится автоматически в указанное время.', 'idpg-core' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Обратный отсчёт', 'idpg-core' ); ?></th>
								<td>
									<label class="idpg-mt-toggle">
										<input
											type="checkbox"
											name="idpg_maintenance_countdown"
											value="1"
											<?php checked( (bool) get_option( 'idpg_maintenance_countdown', false ) ); ?>
										>
										<span class="idpg-mt-toggle__track"></span>
										<span class="idpg-mt-toggle__label"><?php esc_html_e( 'Показывать отсчёт до авто-выключения', 'idpg-core' ); ?></span>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="idpg-maintenance-retry-after"><?php esc_html_e( 'Retry-After', 'idpg-core' ); ?></label>
								</th>
								<td>
									<input
										type="number"
										id="idpg-maintenance-retry-after"
										name="idpg_maintenance_retry_after"
										class="small-text"
										min="0"
										step="60"
										value="<?php echo esc_attr( (int) get_option( 'idpg_maintenance_retry_after', 3600 ) ); ?>"
									>
									<p class="description"><?php esc_html_e( 'Заголовок HTTP для поисковиков, сек.', 'idpg-core' ); ?></p>
								</td>
							</tr>
						</table>
					</section>

				</div>

				<div class="idpg-mt-submit">
					<?php submit_button( __( 'Сохранить настройки', 'idpg-core' ) ); ?>
				</div>
			</form>

			<aside class="idpg-mt-sidebar">
				<div class="idpg-mt-sidebar__card">
					<h3 class="idpg-mt-sidebar__title"><?php esc_html_e( 'Статус заглушки', 'idpg-core' ); ?></h3>
					<p class="idpg-mt-sidebar__status <?php echo $enabled ? 'idpg-mt-sidebar__status--on' : 'idpg-mt-sidebar__status--off'; ?>">
						<?php echo $enabled ? esc_html__( 'Включена', 'idpg-core' ) : esc_html__( 'Выключена', 'idpg-core' ); ?>
					</p>
					<?php if ( $enabled ) : ?>
						<p>
							<a class="idpg-btn idpg-btn--secondary" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Посмотреть заглушку на сайте', 'idpg-core' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<div class="idpg-mt-sidebar__card">
					<h3 class="idpg-mt-sidebar__title"><?php esc_html_e( 'Подсказка', 'idpg-core' ); ?></h3>
					<ul class="idpg-mt-sidebar__list">
						<li><?php esc_html_e( 'Заглушка видна гостям и неавторизованным посетителям.', 'idpg-core' ); ?></li>
						<li><?php esc_html_e( 'Авторизованные администраторы видят настоящий сайт.', 'idpg-core' ); ?></li>
						<li><?php esc_html_e( 'Ссылка «Войти» выводится под заглушкой.', 'idpg-core' ); ?></li>
						<li><?php esc_html_e( 'Соцсети выбираются из списка — сохраняются и сразу показываются иконки.', 'idpg-core' ); ?></li>
					</ul>
				</div>
			</aside>
		</div> <!-- /.idpg-mt-layout -->
	</div>
		<?php
	}

	/**
	 * Список редактируемых ролей (с администратором).
	 *
	 * @return array
	 */
	private function get_editable_roles() {
		$roles = wp_roles()->roles;

		if ( ! isset( $roles['administrator'] ) ) {
			$roles = array( 'administrator' => array( 'name' => 'Administrator' ) ) + $roles;
		}

		return $roles;
	}

	/**
	 * Текущий тип режима.
	 *
	 * @return string
	 */
	private function get_type() {
		$type = get_option( 'idpg_maintenance_type', 'dev' );

		return in_array( $type, array( 'dev', 'maintenance' ), true ) ? $type : 'dev';
	}

	/**
	 * Выбранные роли доступа.
	 *
	 * @return array
	 */
	private function get_roles() {
		$roles = (array) get_option( 'idpg_maintenance_roles', array( 'administrator' ) );

		return array_map( 'sanitize_key', $roles );
	}

	/**
	 * Значение expires в локальном времени для input datetime-local.
	 *
	 * @return string
	 */
	private function get_expires_local() {
		$expires = get_option( 'idpg_maintenance_expires', '' );

		if ( ! $expires ) {
			return '';
		}

		$timestamp = strtotime( $expires );
		if ( ! $timestamp ) {
			return '';
		}

		return wp_date( 'Y-m-d\TH:i', $timestamp );
	}

	/**
	 * Проверка, активен ли режим.
	 *
	 * @return bool
	 */
	public function is_maintenance_active() {
		if ( ! (bool) get_option( 'idpg_maintenance_enabled', false ) ) {
			return false;
		}

		// Авто-выключение по дате.
		if ( $this->maybe_auto_disable() ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( wp_doing_cron() || wp_doing_ajax() ) {
			return false;
		}

		// Администраторы видят сайт всегда.
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return false;
		}

		// В режиме «Разработка» сайт видят все авторизованные пользователи.
		if ( 'dev' === $this->get_type() && is_user_logged_in() ) {
			return false;
		}

		// Белый список IP.
		if ( $this->is_whitelisted_ip() ) {
			return false;
		}

		// Пароль доступа.
		if ( $this->has_access_cookie() ) {
			return false;
		}

		return true;
	}

	/**
	 * Автоматическое отключение режима по дате.
	 *
	 * @return bool True, если режим был отключён автоматически.
	 */
	private function maybe_auto_disable() {
		$expires = get_option( 'idpg_maintenance_expires', '' );

		if ( ! $expires ) {
			return false;
		}

		$expires_ts = strtotime( $expires );

		if ( $expires_ts && $expires_ts <= current_time( 'timestamp' ) ) {
			update_option( 'idpg_maintenance_enabled', false );

			return true;
		}

		return false;
	}

	/**
	 * Проверка белого списка IP.
	 *
	 * @return bool
	 */
	private function is_whitelisted_ip() {
		$ips_raw = get_option( 'idpg_maintenance_ips', '' );

		if ( empty( $ips_raw ) ) {
			return false;
		}

		$ips = preg_split( '/[\s,]+/', $ips_raw );
		$ips = array_filter( array_map( 'trim', $ips ) );

		if ( empty( $ips ) ) {
			return false;
		}

		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return $remote_ip && in_array( $remote_ip, $ips, true );
	}

	/**
	 * Проверка cookie доступа по паролю.
	 *
	 * @return bool
	 */
	private function has_access_cookie() {
		$password = get_option( 'idpg_maintenance_password', '' );

		if ( '' === $password ) {
			return false;
		}

		if ( empty( $_COOKIE[ self::ACCESS_COOKIE ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return false;
		}

		$cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ self::ACCESS_COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return hash_equals( $this->access_cookie_value(), $cookie_value );
	}

	/**
	 * Значение cookie доступа.
	 *
	 * @return string
	 */
	private function access_cookie_value() {
		$password = (string) get_option( 'idpg_maintenance_password', '' );

		return wp_hash( 'idpg_maintenance:' . $password );
	}

	/**
	 * Вывод заглушки на фронтенде, если режим активен.
	 */
	public function maybe_show_maintenance() {
		// Обработка ввода пароля доступа.
		if ( $this->handle_access_password() ) {
			return;
		}

		if ( ! $this->is_maintenance_active() ) {
			return;
		}

		// Не блокируем страницы входа.
		if ( $this->is_login_request() ) {
			return;
		}

		status_header( 503 );
		nocache_headers();

		$this->render_maintenance_page();
		exit;
	}

	/**
	 * Решение: можно ли выдать доступ по введённому паролю.
	 *
	 * Вынесено из `handle_access_password()` отдельно от выдачи cookie, потому
	 * что успешная ветка заканчивается `exit`, а `setcookie()` в CLI ничего не
	 * делает и в `$_COOKIE` не попадает. Позитивный сценарий «верный пароль →
	 * доступ» иначе нечем проверить. Логика не менялась: те же проверки в том
	 * же порядке, тот же результат.
	 *
	 * Порядок проверок важен: nonce проверяется до сравнения пароля. Иначе
	 * запрос с верным паролем, но без nonce выдавал бы доступ — то есть форма
	 * оставалась бы без защиты от подделки, ради которой и добавлена.
	 *
	 * @return bool True, если пароль верный и nonce валиден.
	 */
	private function is_access_request_valid() {
		$password = get_option( 'idpg_maintenance_password', '' );

		if ( '' === $password ) {
			return false;
		}

		if ( ! isset( $_POST['idpg_maintenance_pass'] ) ) {
			return false;
		}

		// Без валидного nonce отказ молчаливый: страница отрисовывается
		// заново, а сообщение «Неверный пароль.» показывается по факту
		// наличия поля в POST. Отдельная строка про nonce не нужна — для
		// посетителя разницы нет, а отличить одно от другого он не должен.
		$nonce = isset( $_POST[ self::ACCESS_NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::ACCESS_NONCE_FIELD ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, self::ACCESS_NONCE_ACTION ) ) {
			return false;
		}

		$submitted = sanitize_text_field( wp_unslash( $_POST['idpg_maintenance_pass'] ) );

		return hash_equals( $password, $submitted );
	}

	/**
	 * Обработка ввода пароля доступа на заглушке.
	 *
	 * @return bool True, если пароль введён и доступ предоставлен.
	 */
	private function handle_access_password() {
		if ( ! $this->is_access_request_valid() ) {
			return false;
		}

		setcookie(
			self::ACCESS_COOKIE,
			$this->access_cookie_value(),
			time() + MONTH_IN_SECONDS,
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);

		$redirect = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Проверка, что запрос относится к страницам входа/восстановления пароля.
	 *
	 * @return bool
	 */
	private function is_login_request() {
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$login_path = wp_parse_url( wp_login_url(), PHP_URL_PATH );

		if ( $login_path && 0 === strpos( $request_path, $login_path ) ) {
			return true;
		}

		if ( class_exists( 'IDPG_Auth' ) ) {
			if ( 0 === strpos( $request_path, IDPG_Auth::LOGIN_URL )
				|| 0 === strpos( $request_path, IDPG_Auth::RESET_PASSWORD_URL ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Добавление заголовка Retry-After для HTTP 503.
	 *
	 * @param array $headers Заголовки ответа.
	 * @return array
	 */
	public function add_retry_after_header( $headers ) {
		if ( $this->is_maintenance_active() && ! headers_sent() ) {
			$headers['Retry-After'] = (int) get_option( 'idpg_maintenance_retry_after', 3600 );
		}

		return $headers;
	}

	/**
	 * Список иконок соцсетей (JS-библиотека Iconify).
	 *
	 * @return array Массив slug => название.
	 */
	private function get_social_icons() {
		return array(
			'telegram'  => __( 'Telegram', 'idpg-core' ),
			'vk'        => __( 'ВКонтакте', 'idpg-core' ),
			'whatsapp'  => __( 'WhatsApp', 'idpg-core' ),
			'instagram' => __( 'Instagram', 'idpg-core' ),
			'facebook'  => __( 'Facebook', 'idpg-core' ),
			'youtube'   => __( 'YouTube', 'idpg-core' ),
			'tiktok'    => __( 'TikTok', 'idpg-core' ),
			'ok'        => __( 'Одноклассники', 'idpg-core' ),
			'x'         => __( 'X (Twitter)', 'idpg-core' ),
			'rutube'    => __( 'Rutube', 'idpg-core' ),
			'dzen'      => __( 'Дзен', 'idpg-core' ),
			'website'   => __( 'Сайт', 'idpg-core' ),
		);
	}

	/**
	 * Плейсхолдер иконки соцсети для JS-библиотеки Iconify.
	 *
	 * Иконку встраивает Iconify.renderHTML() на клиенте.
	 *
	 * @param string $slug Slug иконки.
	 * @return string
	 */
	private function social_icon_placeholder( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( ! $slug || ! array_key_exists( $slug, $this->get_social_icons() ) ) {
			return '';
		}
		return '<span class="idpg-social__icon-el" data-idpg-icon="' . esc_attr( $slug ) . '"></span>';
	}

	/**
	 * Определение slug иконки по домену ссылки.
	 *
	 * @param string $url URL социальной сети.
	 * @return string
	 */
	private function detect_social_slug( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		$map = array(
			'telegram'  => array( 't.me', 'telegram' ),
			'vk'        => array( 'vk.com' ),
			'whatsapp'  => array( 'whatsapp', 'wa.me' ),
			'instagram' => array( 'instagram' ),
			'facebook'  => array( 'facebook', 'fb.com' ),
			'youtube'   => array( 'youtube', 'youtu.be' ),
			'tiktok'    => array( 'tiktok' ),
			'ok'        => array( 'ok.ru' ),
			'x'         => array( 'x.com', 'twitter' ),
			'rutube'    => array( 'rutube' ),
			'dzen'      => array( 'dzen' ),
		);

		foreach ( $map as $slug => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $host, $needle ) ) {
					return $slug;
				}
			}
		}

		return 'website';
	}

	/**
	 * HTML-страница заглушки.
	 */
	private function render_maintenance_page() {
		$title    = get_option( 'idpg_maintenance_title', __( 'Сайт в разработке', 'idpg-core' ) );
		$message  = get_option( 'idpg_maintenance_message', __( 'Ведутся технические работы. Загляните позже!', 'idpg-core' ) );
		$password = get_option( 'idpg_maintenance_password', '' );

		$logo_id = (int) get_option( 'idpg_maintenance_logo', 0 );
		if ( ! $logo_id ) {
			$logo_id = (int) get_theme_mod( 'custom_logo', 0 );
		}

		$bg_color = get_option( 'idpg_maintenance_bg_color', '#f6efe3' );
		if ( ! preg_match( '/^#[0-9a-f]{6}$/i', $bg_color ) ) {
			$bg_color = '#f6efe3';
		}
		$bg_rgb = array_map(
			'hexdec',
			array( substr( $bg_color, 1, 2 ), substr( $bg_color, 3, 2 ), substr( $bg_color, 5, 2 ) )
		);
		$bg_rgba = sprintf( 'rgba(%d, %d, %d, 0.9)', $bg_rgb[0], $bg_rgb[1], $bg_rgb[2] );

		$contact_email = get_option( 'idpg_maintenance_contact_email', '' );
		$contact_phone = get_option( 'idpg_maintenance_contact_phone', '' );

		$social = (array) get_option( 'idpg_maintenance_social_items', array() );
		if ( empty( $social ) ) {
			$social_raw = get_option( 'idpg_maintenance_social', '' );
			$legacy_urls = array_filter( array_map( 'trim', explode( "\n", $social_raw ) ) );
			$social = array();
			foreach ( $legacy_urls as $legacy_url ) {
				$social[] = array(
					'url'  => $legacy_url,
					'icon' => 0,
				);
			}
		}

		$show_login  = (bool) get_option( 'idpg_maintenance_login_link', true );
		$countdown   = (bool) get_option( 'idpg_maintenance_countdown', false );
		$expires_ts  = 0;
		$expires_raw = get_option( 'idpg_maintenance_expires', '' );
		if ( $expires_raw ) {
			$expires_ts = (int) strtotime( $expires_raw );
		}

		$login_url = class_exists( 'IDPG_Auth' ) ? home_url( IDPG_Auth::LOGIN_URL ) : wp_login_url();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title ); ?></title>

	<link rel="stylesheet" href="<?php echo esc_url( get_stylesheet_uri() ); ?>">
	<?php if ( function_exists( 'wp_print_font_faces' ) ) : ?>
		<?php wp_print_font_faces(); ?>
	<?php endif; ?>

	<script src="<?php echo esc_url( IDPG_CORE_URL . 'assets/vendor/iconify/iconify.min.js' ); ?>"></script>
	<script src="<?php echo esc_url( IDPG_CORE_URL . 'assets/js/social-icons.js' ); ?>"></script>
	<style>		html,
		body {
			margin: 0;
			padding: 0;
			overflow-y: hidden;
		}
		#idpg-maintenance {
			min-height: 100vh;
			display: block;
			padding: 0;
			margin: 0;
			background-color: <?php echo esc_attr( $bg_rgba ); ?>;
			font-family: 'Gilroy', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
		}
		.idpg-maintenance {
			max-width: 1400px;
			width: 100%;
			margin: 0 auto;
			padding: 48px 32px;
			background: transparent;
			border-radius: 0;
			box-shadow: none;
		}
		.idpg-maintenance__header {
			display: flex;
			align-items: center;
			gap: 16px;
			padding: 0 0 16px;
			border-bottom: 1px solid #dee1e6;
		}
		.idpg-maintenance__logo {
			height: 40px;
			width: auto;
		}
		.idpg-maintenance__brand {
			font-family: 'Gilroy', sans-serif;
			font-size: 1.25rem;
			font-weight: 500;
			color: #0a0b0d;
			text-decoration: none;
			letter-spacing: -0.025em;
		}
		.idpg-maintenance__badge {
			margin-left: auto;
			font-size: 0.75rem;
			font-weight: 600;
			letter-spacing: 0.1em;
			text-transform: uppercase;
			color: #7c828a;
			border: 1px solid #dee1e6;
			border-radius: 999px;
			padding: 6px 14px;
		}
		.idpg-maintenance__body {
			padding: 24px 0;
			display: flex;
			flex-direction: column;
			align-items: center;
			text-align: center;
		}
		.idpg-maintenance__kicker {
			font-size: 1.1rem;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: 0.05em;
			color: #0052ff;
			margin-bottom: 16px;
		}
		.idpg-maintenance__title {
			font-size: 3rem;
			font-weight: 700;
			color: #0a0b0d;
			margin: 0 0 16px;
			letter-spacing: -0.025em;
		}
		.idpg-maintenance__message {
			font-size: 1.5rem;
			line-height: 1.5;
			color: #5b616e;
			margin: 0 0 32px;
		}
		.idpg-maintenance__countdown {
			display: flex;
			gap: 16px;
			margin-bottom: 32px;
			flex-wrap: wrap;
			justify-content: center;
		}
		.idpg-maintenance__unit {
			display: flex;
			flex-direction: column;
			align-items: center;
		}
		.idpg-maintenance__unit-value {
			font-size: 2rem;
			font-weight: 700;
			font-variant-numeric: tabular-nums;
			color: #0a0b0d;
		}
		.idpg-maintenance__unit-label {
			font-size: 1rem;
			color: #7c828a;
		}
		.idpg-maintenance__contacts {
			margin-bottom: 32px;
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			justify-content: center;
			gap: 0 16px;
			font-size: 1.3rem;
		}
		.idpg-maintenance__contacts .sep {
			margin: 0 6px;
			color: #a8acb3;
		}
		.idpg-maintenance__contacts a {
			color: #0052ff;
			white-space: nowrap;
			background: transparent;
			border: none;
			text-decoration: underline;
			transition: color 0.2s ease;
		}
		.idpg-maintenance__contacts a:hover,
		.idpg-maintenance__contacts a:focus {
			color: #003ecc;
			text-decoration: underline;
			background: transparent;
		}
		.idpg-maintenance__contacts a[href^="tel:"] {
			font-weight: 600;
		}
		.idpg-maintenance__social {
			display: flex;
			gap: 24px;
			margin-bottom: 32px;
			flex-wrap: wrap;
			justify-content: center;
		}
		.idpg-maintenance__social a {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 12px;
			border-radius: 14px;
			color: #0052ff;
			background: transparent;
			border: none;
			box-shadow: none;
			transition: color 0.2s ease;
		}
		.idpg-maintenance__social a:hover,
		.idpg-maintenance__social a:focus {
			color: #003ecc;
			background: transparent;
		}
		.idpg-maintenance__social svg {
			width: 32px;
			height: 32px;
		}
		.idpg-maintenance__password {
			margin-bottom: 32px;
			display: flex;
			gap: 10px;
			align-items: center;
			justify-content: center;
		}
		.idpg-maintenance__password input {
			padding: 12px 18px;
			font-size: 1.25rem;
			border: 1px solid #dee1e6;
			border-radius: 10px;
			width: 360px;
		}
		.idpg-maintenance__password button {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 14px 28px;
			font-size: 1.15rem;
			font-weight: 600;
			border-radius: 14px;
			border: none;
			cursor: pointer;
			transition: background-color 0.2s ease, color 0.2s ease;
			background-color: #0052ff;
			color: #ffffff;
		}
		.idpg-maintenance__password button:hover,
		.idpg-maintenance__password button:focus {
			background-color: #003ecc;
			color: #ffffff;
		}
		.idpg-maintenance__error {
			color: #dc2626;
		}
		.idpg-maintenance__footer {
			padding-top: 24px;
			border-top: 1px solid #dee1e6;
		}
		.idpg-maintenance__login {
			display: inline-block;
			padding: 16px 36px;
			font-size: 1.3rem;
			font-weight: 600;
			border-radius: 16px;
			color: #ffffff;
			background-color: #0052ff;
			text-decoration: none;
			transition: background-color 0.2s ease, transform 0.2s ease;
		}
		.idpg-maintenance__login:hover,
		.idpg-maintenance__login:focus {
			background-color: #003ecc;
		}
		.idpg-maintenance__done {
			font-size: 1.25rem;
			font-weight: 600;
			color: #0a0b0d;
		}
		.idpg-maintenance__error {
			font-size: 1.1rem;
			color: #dc2626;
		}
	</style>
</head>
<body id="idpg-maintenance">
	<div class="idpg-maintenance">
		<header class="idpg-maintenance__header">
			<?php if ( $logo_id ) : ?>
				<?php $logo_image = wp_get_attachment_image( $logo_id, 'medium', false, array( 'class' => 'idpg-maintenance__logo' ) ); ?>
				<?php if ( $logo_image ) : ?>
					<?php echo $logo_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<a class="idpg-maintenance__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
				<?php endif; ?>
			<?php else : ?>
				<a class="idpg-maintenance__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
			<?php endif; ?>
			<span class="idpg-maintenance__badge"><?php esc_html_e( 'Скоро вернёмся', 'idpg-core' ); ?></span>
		</header>

		<div class="idpg-maintenance__body">
			<div class="idpg-maintenance__content">
				<p class="idpg-maintenance__kicker"><?php esc_html_e( 'Плановые работы', 'idpg-core' ); ?></p>
				<h1 class="idpg-maintenance__title"><?php echo esc_html( $title ); ?></h1>
				<p class="idpg-maintenance__message"><?php echo esc_html( $message ); ?></p>

				<?php if ( $countdown && $expires_ts > 0 ) : ?>
					<div class="idpg-maintenance__countdown" data-countdown="<?php echo esc_attr( $expires_ts ); ?>">
						<div class="idpg-maintenance__unit">
							<span class="idpg-maintenance__unit-value" data-unit="days">00</span>
							<span class="idpg-maintenance__unit-label"><?php esc_html_e( 'дней', 'idpg-core' ); ?></span>
						</div>
						<div class="idpg-maintenance__unit">
							<span class="idpg-maintenance__unit-value" data-unit="hours">00</span>
							<span class="idpg-maintenance__unit-label"><?php esc_html_e( 'часов', 'idpg-core' ); ?></span>
						</div>
						<div class="idpg-maintenance__unit">
							<span class="idpg-maintenance__unit-value" data-unit="minutes">00</span>
							<span class="idpg-maintenance__unit-label"><?php esc_html_e( 'минут', 'idpg-core' ); ?></span>
						</div>
						<div class="idpg-maintenance__unit">
							<span class="idpg-maintenance__unit-value" data-unit="seconds">00</span>
							<span class="idpg-maintenance__unit-label"><?php esc_html_e( 'секунд', 'idpg-core' ); ?></span>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $contact_email || $contact_phone ) : ?>
					<div class="idpg-maintenance__contacts">
						<?php if ( $contact_email ) : ?>
							<a href="mailto:<?php echo esc_attr( $contact_email ); ?>"><?php echo esc_html( $contact_email ); ?></a>
						<?php endif; ?>
						<?php if ( $contact_email && $contact_phone ) : ?>
							<span class="sep">·</span>
						<?php endif; ?>
						<?php if ( $contact_phone ) : ?>
							<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $contact_phone ) ); ?>"><?php echo esc_html( $contact_phone ); ?></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $social ) : ?>
					<div class="idpg-maintenance__social">
						<?php foreach ( $social as $social_item ) : ?>
							<?php
							$social_url  = isset( $social_item['url'] ) ? $social_item['url'] : '';
							$social_slug = isset( $social_item['icon'] ) ? sanitize_key( $social_item['icon'] ) : '';
							if ( ! $social_url ) {
								continue;
							}
							if ( $social_slug && array_key_exists( $social_slug, $this->get_social_icons() ) ) {
								$social_markup = $this->social_icon_placeholder( $social_slug );
							} else {
								$social_markup = $this->social_icon_placeholder( $this->detect_social_slug( $social_url ) );
							}
							?>
							<a href="<?php echo esc_url( $social_url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( wp_parse_url( $social_url, PHP_URL_HOST ) ); ?>">
								<?php echo $social_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $password ) : ?>
					<form class="idpg-maintenance__password" method="post">
						<?php wp_nonce_field( self::ACCESS_NONCE_ACTION, self::ACCESS_NONCE_FIELD ); ?>
						<input
							type="password"
							name="idpg_maintenance_pass"
							placeholder="<?php esc_attr_e( 'Пароль доступа', 'idpg-core' ); ?>"
							autocomplete="current-password"
						>
						<button type="submit"><?php esc_html_e( 'Открыть', 'idpg-core' ); ?></button>
					</form>
					<?php if ( isset( $_POST['idpg_maintenance_pass'] ) ) : ?>
						<p class="idpg-maintenance__error"><?php esc_html_e( 'Неверный пароль.', 'idpg-core' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $show_login ) : ?>
			<footer class="idpg-maintenance__footer">
				<a class="idpg-maintenance__login" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Войти', 'idpg-core' ); ?></a>
			</footer>
		<?php endif; ?>
	</div>

	<script type="text/javascript">
		(function () {
			'use strict';

			var el = document.querySelector( '[data-countdown]' );
			if ( ! el ) {
				return;
			}

			var target = parseInt( el.getAttribute( 'data-countdown' ), 10 ) * 1000;

			function pad( n ) {
				return n < 10 ? '0' + n : '' + n;
			}

			function plural( n, forms ) {
				var n10 = n % 10,
					n100 = n % 100;
				if ( n10 === 1 && n100 !== 11 ) {
					return forms[0];
				}
				if ( n10 >= 2 && n10 <= 4 && ( n100 < 12 || n100 > 14 ) ) {
					return forms[1];
				}
				return forms[2];
			}

			function setValue( key, value, forms ) {
				var valueEl = el.querySelector( '[data-unit="' + key + '"]' );
				var labelEl = valueEl ? valueEl.nextElementSibling : null;
				if ( valueEl ) {
					valueEl.textContent = pad( value );
				}
				if ( labelEl && forms ) {
					labelEl.textContent = plural( value, forms );
				}
			}

			function tick() {
				var diff = target - Date.now();
				if ( diff <= 0 ) {
					el.innerHTML = '<div class="idpg-maintenance__done"><?php echo esc_js( __( 'Скоро откроемся!', 'idpg-core' ) ); ?></div>';
					return;
				}

				var d = Math.floor( diff / 86400000 );
				var h = Math.floor( ( diff % 86400000 ) / 3600000 );
				var m = Math.floor( ( diff % 3600000 ) / 60000 );
				var s = Math.floor( ( diff % 60000 ) / 1000 );

				setValue( 'days', d, [ '<?php echo esc_js( __( 'день', 'idpg-core' ) ); ?>', '<?php echo esc_js( __( 'дня', 'idpg-core' ) ); ?>', '<?php echo esc_js( __( 'дней', 'idpg-core' ) ); ?>' ] );
				setValue( 'hours', h, [ '<?php echo esc_js( __( 'час', 'idpg-core' ) ); ?>', '<?php echo esc_js( __( 'часа', 'idpg-core' ) ); ?>', '<?php echo esc_js( __( 'часов', 'idpg-core' ) ); ?>' ] );
				setValue( 'minutes', m, [ '<?php echo esc_js( __( 'минута', 'idpg-core' ) ); ?>', '<?php echo esc_js( __( 'минуты', 'idpg-core' ) ); ?>', '<?php echo esc_js( __( 'минут', 'idpg-core' ) ); ?>' ] );
				setValue( 'seconds', s, [ '<?php echo esc_js( __( 'секунда', 'idpg-core' ) ); ?>', '<?php echo esc_js( __( 'секунды', 'idpg-core' ) ); ?>', '<?php echo esc_js( __( 'секунд', 'idpg-core' ) ); ?>' ] );
			}

			tick();
			setInterval( tick, 1000 );
		}() );

		( function () {
			'use strict';

			var els = document.querySelectorAll( '[data-idpg-icon]' );
			for ( var i = 0; i < els.length; i++ ) {
				var name = els[i].getAttribute( 'data-idpg-icon' );
				if ( ! window.Iconify ) {
					continue;
				}
				var html = window.Iconify.renderHTML( 'idpg:' + name );
				if ( html ) {
					els[i].innerHTML = html;
				}
			}
		}() );
	</script>
</body>
</html>
		<?php
	}

	/**
	 * Бейдж «Режим разработки» в админ-баре.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Админ-бар.
	 */
	public function add_admin_bar_badge( $wp_admin_bar ) {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! (bool) get_option( 'idpg_maintenance_enabled', false ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => 'idpg-maintenance',
				'parent' => 'top-secondary',
				'title'  => __( 'Режим разработки', 'idpg-core' ),
				'href'   => admin_url( 'options-general.php?page=' . self::OPTION_GROUP ),
				'meta'   => array(
					'title' => __( 'Сайт закрыт для посетителей. Нажмите, чтобы открыть настройки.', 'idpg-core' ),
				),
			)
		);
	}

	/**
	 * Стили для бейджа в админ-баре.
	 */
	public function admin_bar_badge_style() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		echo '<style>#wpadminbar #wp-admin-bar-idpg-maintenance .ab-item { background: #b71c1c; color: #fff; } #wpadminbar #wp-admin-bar-idpg-maintenance .ab-item:hover { background: #c62828; color: #fff; }</style>';
	}

	/**
	 * Сброс кэша при включении/выключении режима.
	 *
	 * @param mixed $old_value Старое значение.
	 * @param mixed $value     Новое значение.
	 */
	public function flush_cache_on_status_change( $old_value, $value ) {
		if ( (bool) $old_value === (bool) $value ) {
			return;
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}
}
