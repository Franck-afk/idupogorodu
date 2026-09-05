<?php
/**
 * Система управления рекламными баннерами (Carbon Fields).
 *
 * Регистрирует страницу «Баннеры» в админке (как верхний пункт меню)
 * с полями для двух позиций (middle / bottom) и общими настройками,
 * а также рендерит баннеры на главной с кэшем, UTM, датами показа и аналитикой.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Carbon_Fields\Container;
use Carbon_Fields\Field;

/**
 * Класс IDPG_Banners
 */
class IDPG_Banners {

	/**
	 * Опция-префикс Carbon Fields (theme_options).
	 *
	 * @var string
	 */
	const OPTION = 'idpg_banners';

	/**
	 * Позиции баннеров.
	 *
	 * @var array
	 */
	const POSITIONS = array( 'middle', 'bottom' );

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Banners|null
	 */
	private static $instance = null;

	/**
	 * Активный контейнер Carbon Fields (для fallback-сохранения).
	 *
	 * @var \Carbon_Fields\Container\Container|null
	 */
	private static $container = null;

	/**
	 * Получение экземпляра.
	 *
	 * @return IDPG_Banners
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'carbon_fields_register_fields', array( $this, 'register_fields' ) );
		add_action( 'carbon_fields_theme_options_container_saved', array( $this, 'purge_cache' ) );
		add_action( 'idpg_banner_position', array( $this, 'render' ) );
		add_action( 'save_post_event', array( $this, 'purge_cache' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// Отладка: какой page_hook WordPress считает текущим для страницы баннеров.
		if ( is_admin() ) {
			// Fallback: если CF привязал сохранение к неверному хуку
			// (особенность top-level theme_options при parent=''),
			// добавляем _save на актуальный page_hook.
			add_action( 'admin_menu', function () {
				$slug = self::$container ? self::$container->get_page_file() : 'crb_upravlenie-bannerami';
				global $admin_page_hooks;
				$actual_hook = array_search( $slug, $admin_page_hooks, true );
				if ( $actual_hook && self::$container ) {
					$cf_hook = 'load-' . get_plugin_page_hookname( $slug, '' );
					if ( ! has_action( $cf_hook, array( self::$container, '_save' ) ) ) {
						add_action( 'load-' . $actual_hook, array( self::$container, '_save' ) );
					}
				}
			}, 9999 );
		}
	}

	/**
	 * Регистрация CSS на фронте, только на главной.
	 */
	public function register_assets() {
		if ( ! is_front_page() ) {
			return;
		}
		wp_enqueue_style(
			'idpg-banners',
			IDPG_CORE_URL . 'assets/css/banners.css',
			array(),
			IDPG_CORE_VERSION
		);
	}

	public function register_fields() {
		if ( ! class_exists( '\\Carbon_Fields\\Container' ) ) {
			return;
		}

		self::$container = Container::make( 'theme_options', __( 'Управление баннерами', 'idpg-core' ) )
			->set_page_menu_title( __( 'Баннеры', 'idpg-core' ) )
			->set_page_menu_position( 30 )
			->add_fields( $this->all_fields() );
	}

	/**
	 * Сборка всех полей (middle / bottom / common).
	 *
	 * @return array
	 */
	private function all_fields() {
		$fields = array_merge(
			$this->middle_fields(),
			$this->bottom_fields(),
			$this->common_fields()
		);

		// Ширина полей настроек: input‑поля — 33% (3 в ряд), разделители — 100%.
		foreach ( $fields as $field ) {
			if ( ! $field instanceof \Carbon_Fields\Field\Field ) {
				continue;
			}
			if ( $field instanceof \Carbon_Fields\Field\Separator_Field ) {
				$field->set_width( 100 );
			} else {
				$field->set_width( 33 );
			}
		}

		return $fields;
	}

	/**
	 * Поля среднего баннера (Middle).
	 *
	 * @return array
	 */
	private function middle_fields() {
		return array(
			Field::make( 'separator', 'banner_middle_separator', __( 'Средний баннер', 'idpg-core' ) ),

			Field::make( 'checkbox', 'banner_middle_active', __( 'Включить показ среднего баннера', 'idpg-core' ) )
				->set_help_text( __( 'Отображается между блоками событий на главной.', 'idpg-core' ) ),

			Field::make( 'image', 'banner_middle_image', __( 'Изображение баннера', 'idpg-core' ) )
				->set_help_text( __( 'Рекомендуемый размер: 728×90px.', 'idpg-core' ) )
				->set_conditional_logic( array(
					array( 'field' => 'banner_middle_active', 'value' => true ),
				) ),

			Field::make( 'image', 'banner_middle_image_mobile', __( 'Изображение для мобильных', 'idpg-core' ) )
				->set_help_text( __( 'Рекомендуемый размер: 320×100px.', 'idpg-core' ) )
				->set_conditional_logic( array(
					array( 'field' => 'banner_middle_active', 'value' => true ),
				) ),

			Field::make( 'text', 'banner_middle_link_url', __( 'Ссылка перехода для баннера', 'idpg-core' ) )
				->set_attribute( 'type', 'url' )
				->set_required( true )
				->set_conditional_logic( array(
					array( 'field' => 'banner_middle_active', 'value' => true ),
				) ),

			Field::make( 'text', 'banner_middle_alt_text', __( 'Alt-текст для изображения', 'idpg-core' ) )
				->set_conditional_logic( array(
					array( 'field' => 'banner_middle_active', 'value' => true ),
				) ),

			Field::make( 'date', 'banner_middle_date_start', __( 'Дата начала показа', 'idpg-core' ) )
				->set_storage_format( 'Y-m-d' )
				->set_conditional_logic( array(
					array( 'field' => 'banner_middle_active', 'value' => true ),
				) ),

			Field::make( 'date', 'banner_middle_date_end', __( 'Дата окончания показа', 'idpg-core' ) )
				->set_storage_format( 'Y-m-d' )
				->set_conditional_logic( array(
					array( 'field' => 'banner_middle_active', 'value' => true ),
				) ),
		);
	}

	/**
	 * Поля нижнего баннера (Bottom).
	 *
	 * @return array
	 */
	private function bottom_fields() {
		return array(
			Field::make( 'separator', 'banner_bottom_separator', __( 'Нижний баннер', 'idpg-core' ) ),

			Field::make( 'checkbox', 'banner_bottom_active', __( 'Включить показ нижнего баннера', 'idpg-core' ) )
				->set_help_text( __( 'Отображается перед подвалом на главной.', 'idpg-core' ) ),

			Field::make( 'image', 'banner_bottom_image', __( 'Изображение баннера', 'idpg-core' ) )
				->set_help_text( __( 'Рекомендуемый размер: 1200×200px.', 'idpg-core' ) )
				->set_conditional_logic( array(
					array( 'field' => 'banner_bottom_active', 'value' => true ),
				) ),

			Field::make( 'image', 'banner_bottom_image_mobile', __( 'Изображение для мобильных', 'idpg-core' ) )
				->set_help_text( __( 'Рекомендуемый размер: 375×150px.', 'idpg-core' ) )
				->set_conditional_logic( array(
					array( 'field' => 'banner_bottom_active', 'value' => true ),
				) ),

			Field::make( 'text', 'banner_bottom_link_url', __( 'Ссылка перехода для баннера', 'idpg-core' ) )
				->set_attribute( 'type', 'url' )
				->set_required( true )
				->set_conditional_logic( array(
					array( 'field' => 'banner_bottom_active', 'value' => true ),
				) ),

			Field::make( 'text', 'banner_bottom_alt_text', __( 'Alt-текст для изображения', 'idpg-core' ) )
				->set_conditional_logic( array(
					array( 'field' => 'banner_bottom_active', 'value' => true ),
				) ),

			Field::make( 'date', 'banner_bottom_date_start', __( 'Дата начала показа', 'idpg-core' ) )
				->set_storage_format( 'Y-m-d' )
				->set_conditional_logic( array(
					array( 'field' => 'banner_bottom_active', 'value' => true ),
				) ),

			Field::make( 'date', 'banner_bottom_date_end', __( 'Дата окончания показа', 'idpg-core' ) )
				->set_storage_format( 'Y-m-d' )
				->set_conditional_logic( array(
					array( 'field' => 'banner_bottom_active', 'value' => true ),
				) ),
		);
	}

	/**
	 * Общие настройки баннеров.
	 *
	 * @return array
	 */
	private function common_fields() {
		return array(
			Field::make( 'separator', 'banner_common_separator', __( 'Общие настройки баннеров', 'idpg-core' ) ),

			Field::make( 'checkbox', 'banner_analytics_enabled', __( 'Включить отслеживание показов и кликов', 'idpg-core' ) )
				->set_help_text( __( 'Добавляет data-атрибуты для JS-аналитики.', 'idpg-core' ) ),

			Field::make( 'text', 'banner_utm_source', __( 'UTM-метка: источник', 'idpg-core' ) )
				->set_default_value( 'banner' )
				->set_help_text( __( 'По умолчанию: banner', 'idpg-core' ) ),

			Field::make( 'text', 'banner_utm_medium', __( 'UTM-метка: тип', 'idpg-core' ) )
				->set_default_value( 'cpc' )
				->set_help_text( __( 'По умолчанию: cpc', 'idpg-core' ) ),

			Field::make( 'text', 'banner_utm_campaign', __( 'UTM-метка: кампания', 'idpg-core' ) ),

			Field::make( 'text', 'banner_cache_ttl', __( 'Время кэширования баннеров (минуты)', 'idpg-core' ) )
				->set_attribute( 'type', 'number' )
				->set_attribute( 'min', '0' )
				->set_default_value( '60' )
				->set_required( true ),

			Field::make( 'text', 'banner_css_classes', __( 'Дополнительные CSS-классы для баннеров', 'idpg-core' ) )
				->set_help_text( __( 'Через пробел, применяются ко всем баннерам.', 'idpg-core' ) ),
		);
	}

	/**
	 * Получение всех данных баннеров (с кэшем в transient).
	 *
	 * Carbon Fields theme_options читаются один раз и кэшируются.
	 *
	 * @return array
	 */
	private function get_all_data() {
		$cached = get_transient( 'idpg_banners_data' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'carbon_get_theme_option' ) ) {
			return array();
		}

		$keys = array(
			'middle' => array( 'active', 'image', 'image_mobile', 'link_url', 'alt_text', 'date_start', 'date_end' ),
			'bottom' => array( 'active', 'image', 'image_mobile', 'link_url', 'alt_text', 'date_start', 'date_end' ),
			'common' => array( 'analytics_enabled', 'utm_source', 'utm_medium', 'utm_campaign', 'cache_ttl', 'css_classes' ),
		);

		$data = array();
		foreach ( $keys as $group => $fields ) {
			$data[ $group ] = array();
			foreach ( $fields as $f ) {
				$data[ $group ][ $f ] = carbon_get_theme_option( 'banner_' . $group . '_' . $f );
			}
		}

		$ttl_minutes = isset( $data['common']['cache_ttl'] ) ? max( 0, (int) $data['common']['cache_ttl'] ) : 60;
		$ttl = ( $ttl_minutes > 0 ) ? $ttl_minutes * MINUTE_IN_SECONDS : HOUR_IN_SECONDS;

		set_transient( 'idpg_banners_data', $data, $ttl );

		return $data;
	}

	/**
	 * Проверка, активен ли баннер на текущую дату.
	 *
	 * @param array $group Данные группы баннера.
	 * @return bool
	 */
	private function is_active_now( $group ) {
		if ( empty( $group['active'] ) ) {
			return false;
		}

		$now  = current_time( 'Y-m-d' );
		$start = isset( $group['date_start'] ) ? trim( (string) $group['date_start'] ) : '';
		$end   = isset( $group['date_end'] ) ? trim( (string) $group['date_end'] ) : '';

		if ( $start && $now < $start ) {
			return false;
		}
		if ( $end && $now > $end ) {
			return false;
		}

		return true;
	}

	/**
	 * Добавление UTM-меток к URL.
	 *
	 * @param string $url  Исходный URL.
	 * @param string $pos  Позиция баннера (для метки кампании).
	 * @param array  $data Все данные.
	 * @return string
	 */
	private function with_utm( $url, $pos, $data ) {
		if ( '' === $url ) {
			return $url;
		}

		$source   = ! empty( $data['common']['utm_source'] ) ? $data['common']['utm_source'] : 'banner';
		$medium   = ! empty( $data['common']['utm_medium'] ) ? $data['common']['utm_medium'] : 'cpc';
		$campaign = ! empty( $data['common']['utm_campaign'] ) ? $data['common']['utm_campaign'] : ( 'idpg-' . $pos );

		return add_query_arg(
			array(
				'utm_source'   => rawurlencode( $source ),
				'utm_medium'   => rawurlencode( $medium ),
				'utm_campaign' => rawurlencode( $campaign ),
			),
			$url
		);
	}

	/**
	 * Очистка кэша баннеров.
	 */
	public function purge_cache() {
		delete_transient( 'idpg_banners_data' );
		delete_transient( 'idpg_banner_rubric' );
	}

	/**
	 * Получить активный контейнер Carbon Fields баннеров.
	 *
	 * @return \Carbon_Fields\Container\Theme_Options_Container|null
	 */
	public static function get_container() {
		return self::$container;
	}

	/**
	 * Рендер полей баннеров внутри вкладки единой страницы настроек.
	 *
	 * Активирует контейнер Carbon Fields, чтобы поля попали в JSON-данные
	 * cf.preloaded и была инициализирована JS-форма, затем выводит её.
	 * Метод вызывается из IDPG_Settings_Page (вкладка «Баннеры»).
	 *
	 * @return void
	 */
	public static function render_settings_tab() {
		$container = self::$container;

		if ( ! $container ) {
			// Модуль баннеров зарегистрирован, но контейнер ещё не создан.
			echo '<p>' . esc_html__( 'Поля баннеров недоступны.', 'idpg-core' ) . '</p>';
			return;
		}

		// activate() объявлена защищённой внутри Carbon Fields — вызываем
		// через замыкание с привязкой к объекту, сохраняя контекст $this.
		$activate = \Closure::bind( function () {
			$this->activate();
		}, $container, $container );
		$activate();

		$container->render();
	}

	/**
	 * Сохранение полей баннеров на единой странице настроек.
	 *
	 * Вызывается на admin_init из IDPG_Settings_Page (вкладка «Баннеры»).
	 * _save() сам проверяет nonce контейнера и права доступа, поэтому
	 * для пустых запросов ничего не делает.
	 *
	 * @return void
	 */
	public static function maybe_save() {
		$container = self::$container;

		if ( ! $container ) {
			return;
		}

		$container->_save();
	}

	/**
	 * Публичный рендер баннера (callback для хука idpg_banner_position).
	 *
	 * Выводит только на главной странице.
	 *
	 * @param string $position Позиция: middle / bottom.
	 */
	public function render( $position ) {
		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			return;
		}
		if ( ! is_front_page() ) {
			return;
		}

		$data  = $this->get_all_data();
		$group = isset( $data[ $position ] ) ? $data[ $position ] : array();

		if ( ! $this->is_active_now( $group ) ) {
			return;
		}

		$html = $this->render_html( $position, $group, $data );
		if ( $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Сборка HTML баннера.
	 *
	 * @param string $pos   Позиция.
	 * @param array  $group Данные группы.
	 * @param array  $data  Все данные (для common).
	 * @return string
	 */
	private function render_html( $pos, $group, $data ) {
		$image_id = isset( $group['image'] ) ? absint( $group['image'] ) : 0;
		if ( ! $image_id ) {
			return '';
		}

		$extra_classes = ! empty( $data['common']['css_classes'] ) ? ' ' . sanitize_html_class( $data['common']['css_classes'] ) : '';
		$analytics     = ! empty( $data['common']['analytics_enabled'] );
		$base_cls      = 'idpg-banner idpg-banner--' . $pos . $extra_classes;

		return $this->render_simple( $pos, $group, $data, $base_cls, $analytics );
	}

	/**
	 * HTML простого баннера (middle / bottom).
	 *
	 * @param string $pos       Позиция.
	 * @param array  $group     Данные группы.
	 * @param array  $data      Все данные.
	 * @param string $base_cls  Базовый CSS-класс.
	 * @param bool   $analytics Аналитика.
	 * @return string
	 */
	private function render_simple( $pos, $group, $data, $base_cls, $analytics ) {
		$image_id  = absint( $group['image'] );
		$mobile_id = isset( $group['image_mobile'] ) ? absint( $group['image_mobile'] ) : 0;

		$img_url = wp_get_attachment_image_url( $image_id, 'full' );
		if ( ! $img_url ) {
			return '';
		}
		$mobile_url = $mobile_id ? wp_get_attachment_image_url( $mobile_id, 'full' ) : '';
		$alt        = isset( $group['alt_text'] ) ? sanitize_text_field( $group['alt_text'] ) : '';
		$link_url   = isset( $group['link_url'] ) ? esc_url_raw( $group['link_url'] ) : '';

		$data_attrs = $analytics ? ' data-banner="' . esc_attr( $pos ) . '" data-banner-event="click"' : '';
		$href       = $link_url ? $this->with_utm( $link_url, $pos, $data ) : '';

		$picture  = '<picture class="idpg-banner__picture">';
		if ( $mobile_id ) {
			$picture .= '<source media="(max-width: 600px)" srcset="' . esc_url( $mobile_url ) . '">';
		}
		$picture .= '<img class="idpg-banner__img" src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy">';
		$picture .= '</picture>';

		if ( $href ) {
			return '<a class="' . esc_attr( $base_cls ) . '" href="' . esc_url( $href ) . '"'
				. ' target="_blank" rel="nofollow noopener"' . $data_attrs . '>' . $picture . '</a>';
		}

		return '<div class="' . esc_attr( $base_cls ) . '"' . $data_attrs . '>' . $picture . '</div>';
	}
}

