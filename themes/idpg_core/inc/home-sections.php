<?php
/**
 * Настройки видимости секций главной страницы.
 *
 * Страница: Внешний вид → «Настройки главной».
 * Хранение: опция idpg_home_sections (массив ключ => bool).
 * По умолчанию все секции включены (опция отсутствует / ключ не задан).
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ключи секций главной.
 *
 * @return array<string,string> key => подпись для админки.
 */
function idpg_home_sections_list() {
	return array(
		'today'   => __( 'Секция «Сегодня»', 'idpg_core' ),
		'weekend' => __( 'Секция «В ближайшие выходные»', 'idpg_core' ),
		'free'    => __( 'Секция «Бесплатные события»', 'idpg_core' ),
		'popular' => __( 'Секция «Популярные события»', 'idpg_core' ),
		'feed'    => __( 'Лента «Все события»', 'idpg_core' ),
	);
}

/**
 * Включена ли секция главной.
 *
 * @param string $section Ключ секции (today|weekend|free|feed).
 * @return bool
 */
function idpg_home_section_enabled( $section ) {
	$opts = get_option( 'idpg_home_sections', array() );

	if ( ! is_array( $opts ) || ! array_key_exists( $section, $opts ) ) {
		return true; // Нет настройки — секция показывается (обратная совместимость).
	}

	return (bool) $opts[ $section ];
}

/**
 * Санитизация настроек.
 *
 * @param mixed $input Сырые данные формы.
 * @return array<string,bool>
 */
function idpg_home_sections_sanitize( $input ) {
	$out = array();

	foreach ( array_keys( idpg_home_sections_list() ) as $key ) {
		$out[ $key ] = ! empty( $input[ $key ] );
	}

	return $out;
}

/**
 * Регистрация настроек (Settings API).
 */
function idpg_home_sections_register() {
	register_setting(
		'idpg_home_sections',
		'idpg_home_sections',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'idpg_home_sections_sanitize',
			'default'           => array(),
		)
	);

	add_settings_section(
		'idpg_home_sections_main',
		__( 'Видимость блоков', 'idpg_core' ),
		'__return_false',
		'idpg-home-sections'
	);

	foreach ( idpg_home_sections_list() as $key => $label ) {
		add_settings_field(
			'idpg_hs_' . $key,
			$label,
			'idpg_home_sections_render_field',
			'idpg-home-sections',
			'idpg_home_sections_main',
			array( 'key' => $key )
		);
	}
}
add_action( 'admin_init', 'idpg_home_sections_register' );

/**
 * Рендер чекбокса.
 *
 * @param array $args Аргументы из add_settings_field (key).
 */
function idpg_home_sections_render_field( $args ) {
	$key      = isset( $args['key'] ) ? $args['key'] : '';
	$checked  = idpg_home_section_enabled( $key );
	$name     = 'idpg_home_sections[' . esc_attr( $key ) . ']';
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>>
		<?php esc_html_e( 'Показывать на главной', 'idpg_core' ); ?>
	</label>
	<?php
}

/**
 * Страница настроек в админке.
 */
function idpg_home_sections_render_page() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Настройки главной страницы', 'idpg_core' ); ?></h1>

		<form action="options.php" method="post">
			<?php
			settings_fields( 'idpg_home_sections' );
			do_settings_sections( 'idpg-home-sections' );
			submit_button( __( 'Сохранить', 'idpg_core' ) );
			?>
		</form>
	</div>
	<?php
}

/**
 * Пункт меню: Внешний вид → Настройки главной.
 */
function idpg_home_sections_menu() {
	add_theme_page(
		__( 'Настройки главной', 'idpg_core' ),
		__( 'Настройки главной', 'idpg_core' ),
		'edit_theme_options',
		'idpg-home-sections',
		'idpg_home_sections_render_page'
	);
}
add_action( 'admin_menu', 'idpg_home_sections_menu' );
