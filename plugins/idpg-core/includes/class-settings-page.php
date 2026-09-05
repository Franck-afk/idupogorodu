<?php
/**
 * Страница настроек плагина IDPG Core.
 *
 * Три вкладки: Общие, Экспорт / Импорт, О плагине.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Settings_Page
 */
class IDPG_Settings_Page {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Settings_Page|null
	 */
	private static $instance = null;

	/**
	 * Slug страницы.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'idpg-settings';

	/**
	 * Название группы настроек.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'idpg_settings_group';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'idpg_settings_ajax';

	/**
	 * Список опций для экспорта/импорта.
	 *
	 * @var array
	 */
	const EXPORT_OPTIONS = array(
		'idpg_settings',
		'idpg_maintenance_enabled',
		'idpg_maintenance_login_link',
		'idpg_maintenance_title',
		'idpg_maintenance_message',
		'idpg_maintenance_expires',
		'idpg_maintenance_countdown',
		'idpg_maintenance_retry_after',
		'idpg_maintenance_logo',
		'idpg_maintenance_bg_color',
		'idpg_favorites_rewrite_flushed',
		'idpg_roles_version',
		'idpg_meta_migration_version',
	);

	/**
	 * Получение экземпляра.
	 *
	 * @return IDPG_Settings_Page
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_menu', array( $this, 'hide_legacy_menus' ), 9999 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_urls' ) );
		add_action( 'wp_ajax_idpg_export_settings', array( $this, 'ajax_export' ) );
		add_action( 'wp_ajax_idpg_import_settings', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_idpg_reset_settings', array( $this, 'ajax_reset' ) );
	}

	/**
	 * Скрытие старых разрозненных пунктов меню.
	 *
	 * Функционал перенесён на единую страницу «Иду по городу»:
	 * - Настройки → Социальная авторизация;
	 * - Внешний вид → Настройки главной;
	 * - Внешний вид → Области виджетов (страница скрыта, но остаётся
	 *   обработчиком для редиректа старых прямых ссылок на вкладку).
	 *
	 * @return void
	 */
	public function hide_legacy_menus() {
		remove_submenu_page( 'themes.php', 'idpg-home-sections' );
		remove_submenu_page( 'themes.php', 'idpg-widget-areas' );
	}

	/**
	 * Регистрация единой страницы настроек как верхнего пункта меню.
	 *
	 * Вкладки: Общие, Главная (Блоки), Области виджетов,
	 * Социальная авторизация, Экспорт / О плагине.
	 */
	public function register_page() {
		add_menu_page(
			__( 'Иду по городу', 'idpg-core' ),
			__( 'Иду по городу', 'idpg-core' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-admin-generic',
			25
		);
	}

	/**
	 * Редирект старых URL на единую страницу настроек.
	 *
	 * Старые пункты меню скрыты; если админ заходит по старой ссылке
	 * (закладка / кэш), перенаправляем его на соответствующую вкладку.
	 *
	 * @return void
	 */
	public function redirect_legacy_urls() {
		if ( ! is_admin() ) {
			return;
		}

		$base = function ( $tab ) {
			return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab );
		};

		if ( isset( $_GET['page'] ) && 'idpg-home-sections' === $_GET['page'] ) {
			wp_safe_redirect( $base( 'glavnaya' ) );
			exit;
		}

		if ( isset( $_GET['page'] ) && 'idpg-widget-areas' === $_GET['page'] ) {
			wp_safe_redirect( $base( 'widgets' ) );
			exit;
		}

		if ( isset( $_GET['page'] ) && 'idpg-settings' === $_GET['page'] && isset( $_GET['dummy'] ) ) {
			// Резерв — оставлено для будущих перенаправлений настроек.
		}
	}

	/**
	 * Регистрация настроек.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			'idpg_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'default_city_id' => 0,
					'events_per_page' => 12,
				),
			)
		);
	}

	/**
	 * Санитизация настроек.
	 *
	 * @param array $input Входные данные.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$output = array();

		if ( isset( $input['default_city_id'] ) ) {
			$output['default_city_id'] = absint( $input['default_city_id'] );
		}

		if ( isset( $input['events_per_page'] ) ) {
			$per_page = absint( $input['events_per_page'] );
			$output['events_per_page'] = ( $per_page > 0 ) ? $per_page : 12;
		}

		return $output;
	}

	/**
	 * Подключение ассетов страницы настроек.
	 *
	 * @param string $hook Текущий хук.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'obshie';

		wp_enqueue_style(
			'idpg-settings',
			IDPG_CORE_URL . 'assets/css/settings.css',
			array(),
			IDPG_CORE_VERSION
		);

		wp_enqueue_script(
			'idpg-settings',
			IDPG_CORE_URL . 'assets/js/settings.js',
			array( 'jquery' ),
			IDPG_CORE_VERSION,
			true
		);

		wp_localize_script(
			'idpg-settings',
			'idpgSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'exportSuccess' => __( 'Настройки экспортированы', 'idpg-core' ),
					'importSuccess'   => __( 'Настройки импортированы', 'idpg-core' ),
					'resetSuccess'    => __( 'Настройки сброшены', 'idpg-core' ),
					'resetConfirm'    => __( 'Вы уверены? Все настройки плагина будут удалены. Это действие нельзя отменить.', 'idpg-core' ),
					'fileError'       => __( 'Выберите JSON-файл для импорта', 'idpg-core' ),
					'fileReadError'   => __( 'Ошибка чтения файла', 'idpg-core' ),
					'invalidJson'     => __( 'Неверный формат JSON', 'idpg-core' ),
					'uploadError'     => __( 'Ошибка загрузки файла', 'idpg-core' ),
					'unknownError'    => __( 'Произошла неизвестная ошибка', 'idpg-core' ),
				),
			)
		);

		// Ассеты вкладки «Области виджетов» (стили админ-страницы темы).
		if ( 'widgets' === $active_tab
			&& function_exists( 'get_template_directory_uri' )
			&& file_exists( get_template_directory() . '/assets/css/widget-areas-admin.css' ) ) {
			wp_enqueue_style(
				'idpg-widget-areas-admin',
				get_template_directory_uri() . '/assets/css/widget-areas-admin.css',
				array(),
				defined( 'IDPG_VERSION' ) ? IDPG_VERSION : IDPG_CORE_VERSION
			);
		}
	}

	/**
	 * Рендер страницы настроек.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'obshie';

		$tabs = array(
			'obshie'   => __( 'Общие', 'idpg-core' ),
			'glavnaya' => __( 'Главная (Блоки)', 'idpg-core' ),
			'widgets'  => __( 'Области виджетов', 'idpg-core' ),
			'export'   => __( 'Экспорт / О плагине', 'idpg-core' ),
		);

		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'obshie';
		}
		?>
		<div class="wrap idpg-settings-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="nav-tab-wrapper idpg-settings-nav">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $key ) ); ?>" class="nav-tab <?php echo ( $key === $active_tab ) ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="idpg-settings-content">
				<?php
				switch ( $active_tab ) {
					case 'glavnaya':
						$this->render_glavnaya_tab();
						break;
					case 'widgets':
						$this->render_widgets_tab();
						break;
					case 'export':
						$this->render_export_tab();
						break;
					default:
						$this->render_general_tab();
						break;
				}
				?>
			</div>
				</div>
		<?php
	}

	/**
	 * Вкладка «Главная (Блоки)» — видимость секций главной страницы.
	 */
	private function render_glavnaya_tab() {
		if ( ! function_exists( 'idpg_home_sections_list' ) ) {
			echo '<p>' . esc_html__( 'Модуль настроек главной не активен.', 'idpg-core' ) . '</p>';
			return;
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'idpg_home_sections' ); ?>
			<h2 class="title"><?php esc_html_e( 'Видимость блоков', 'idpg_core' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Выберите секции, которые будут показываться на главной странице.', 'idpg_core' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( idpg_home_sections_list() as $key => $label ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="idpg_home_sections[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( idpg_home_section_enabled( $key ) ); ?>>
								<?php esc_html_e( 'Показывать на главной', 'idpg_core' ); ?>
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button( __( 'Сохранить', 'idpg_core' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Вкладка «Области виджетов» — реиспользует админ-страницу темы.
	 */
	private function render_widgets_tab() {
		if ( ! function_exists( 'idpg_widget_areas_admin_page' ) ) {
			echo '<p>' . esc_html__( 'Модуль областей виджетов не активен.', 'idpg-core' ) . '</p>';
			return;
		}
		idpg_widget_areas_admin_page();
	}

	/**
	 * Вкладка «Общие».
	 */
	private function render_general_tab() {
		$settings = get_option( 'idpg_settings', array() );
		$cities   = get_terms( array( 'taxonomy' => 'city', 'hide_empty' => false ) );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_GROUP ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="idpg_default_city_id"><?php esc_html_e( 'Город по умолчанию', 'idpg-core' ); ?></label></th>
					<td>
						<select name="idpg_settings[default_city_id]" id="idpg_default_city_id">
							<option value="0"><?php esc_html_e( '— Автоопределение —', 'idpg-core' ); ?></option>
							<?php if ( ! empty( $cities ) && ! is_wp_error( $cities ) ) : ?>
								<?php foreach ( $cities as $city ) : ?>
									<option value="<?php echo esc_attr( $city->term_id ); ?>" <?php selected( isset( $settings['default_city_id'] ) ? $settings['default_city_id'] : 0, $city->term_id ); ?>>
										<?php echo esc_html( $city->name ); ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Город, который будет выбран при первом посещении сайта.', 'idpg-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="idpg_events_per_page"><?php esc_html_e( 'Событий на странице', 'idpg-core' ); ?></label></th>
					<td>
						<input type="number" name="idpg_settings[events_per_page]" id="idpg_events_per_page" value="<?php echo esc_attr( isset( $settings['events_per_page'] ) ? $settings['events_per_page'] : 12 ); ?>" min="1" max="100" class="small-text">
						<p class="description"><?php esc_html_e( 'Количество событий на страницах архивов.', 'idpg-core' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
			</form>
		<?php
	}

	/**
	 * Вкладка «Экспорт / О плагине».
	 */
	private function render_export_tab() {
		?>
		<h2 class="title"><?php esc_html_e( 'Экспорт и импорт настроек', 'idpg-core' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Экспорт настроек', 'idpg-core' ); ?></th>
				<td>
					<button type="button" class="button button-primary" id="idpg-export-btn">
						<?php esc_html_e( 'Экспортировать в JSON', 'idpg-core' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'Скачать JSON-файл со всеми настройками плагина.', 'idpg-core' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Импорт настроек', 'idpg-core' ); ?></th>
				<td>
					<input type="file" id="idpg-import-file" accept=".json,application/json" style="display:none">
					<button type="button" class="button" id="idpg-import-btn">
						<?php esc_html_e( 'Выбрать JSON и импортировать', 'idpg-core' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'Загрузить JSON-файл для восстановления настроек.', 'idpg-core' ); ?></p>
					<div id="idpg-import-status" class="idpg-import-status"></div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Сброс настроек', 'idpg-core' ); ?></th>
				<td>
					<button type="button" class="button button-link-delete" id="idpg-reset-btn">
						<?php esc_html_e( 'Сбросить все настройки', 'idpg-core' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'Удалить все настройки плагина из базы данных.', 'idpg-core' ); ?></p>
					<div id="idpg-reset-status" class="idpg-import-status"></div>
				</td>
			</tr>
		</table>

		<h2 class="title" style="margin-top:28px;"><?php esc_html_e( 'О плагине', 'idpg-core' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Версия', 'idpg-core' ); ?></th>
				<td><code><?php echo esc_html( IDPG_CORE_VERSION ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'WordPress', 'idpg-core' ); ?></th>
				<td><?php esc_html_e( '6.4+', 'idpg-core' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'PHP', 'idpg-core' ); ?></th>
				<td><?php esc_html_e( '8.2+', 'idpg-core' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Автор', 'idpg-core' ); ?></th>
				<td><a href="https://idupogorodu.ru" target="_blank" rel="noopener noreferrer">idupogorodu.ru</a></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * AJAX: экспорт настроек.
	 */
	public function ajax_export() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав', 'idpg-core' ) ) );
		}

		$export = array(
			'plugin'     => 'idpg-core',
			'version'    => IDPG_CORE_VERSION,
			'exported_at' => current_time( 'mysql' ),
			'settings'   => array(),
		);

		foreach ( self::EXPORT_OPTIONS as $option_name ) {
			$value = get_option( $option_name );
			if ( false !== $value ) {
				$export['settings'][ $option_name ] = $value;
			}
		}

		wp_send_json_success( $export );
	}

	/**
	 * AJAX: импорт настроек.
	 */
	public function ajax_import() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав', 'idpg-core' ) ) );
		}

		if ( empty( $_POST['data'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Данные не получены', 'idpg-core' ) ) );
		}

		$raw = sanitize_textarea_field( wp_unslash( $_POST['data'] ) );
		$data = json_decode( $raw, true );

		if ( null === $data || ! isset( $data['plugin'] ) || 'idpg-core' !== $data['plugin'] ) {
			wp_send_json_error( array( 'message' => __( 'Неверный формат файла импорта', 'idpg-core' ) ) );
		}

		if ( empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Настройки не найдены', 'idpg-core' ) ) );
		}

		$imported = 0;
		foreach ( $data['settings'] as $option_name => $value ) {
			if ( ! in_array( $option_name, self::EXPORT_OPTIONS, true ) ) {
				continue;
			}
			update_option( $option_name, $value );
			$imported++;
		}

		wp_send_json_success(
			array(
				'message'  => sprintf( __( 'Импортировано опций: %d', 'idpg-core' ), $imported ),
				'imported' => $imported,
			)
		);
	}

	/**
	 * AJAX: сброс настроек.
	 */
	public function ajax_reset() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав', 'idpg-core' ) ) );
		}

		foreach ( self::EXPORT_OPTIONS as $option_name ) {
			delete_option( $option_name );
		}

		wp_send_json_success( array( 'message' => __( 'Настройки сброшены', 'idpg-core' ) ) );
	}
}


