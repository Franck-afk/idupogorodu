<?php
/**
 * Нативные метабоксы полей площадки (CPT venue).
 *
 * Заменяет Carbon Fields-контейнер «Данные площадки»: тип, адрес,
 * телефон, email, сайт, вместимость, координаты, часы работы и
 * соцсети выводятся нативными метабоксами на экране редактирования
 * площадки.
 *
 * Данные хранятся в тех же мета-ключах `_idpg_*`, что и раньше.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Venue_Fields
 */
class IDPG_Venue_Fields {

	/**
	 * Действие nonce.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'idpg_save_venue_fields';

	/**
	 * Имя поля nonce.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'idpg_venue_fields_nonce';

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Venue_Fields|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Venue_Fields
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор. Привязывает методы к хукам WordPress.
	 */
	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_venue', array( $this, 'save_post_fields' ), 20, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Подключение скриптов для метабоксов площадки.
	 *
	 * @param string $hook_suffix Текущий хук страницы.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'venue' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'idpg-event-fields',
			IDPG_CORE_URL . 'assets/js/event-fields.js',
			array( 'jquery' ),
			IDPG_CORE_VERSION,
			true
		);
	}

	/**
	 * Определения полей площадки.
	 *
	 * @return array
	 */
	public static function get_fields() {
		return array(
			array(
				'id'       => 'idpg_venue_type',
				'key'      => '_idpg_venue_type',
				'type'     => 'select',
				'label'    => __( 'Тип площадки', 'idpg-core' ),
				'sanitize' => 'text',
				'options'  => array(
					''        => __( '— Не выбран —', 'idpg-core' ),
					'theater' => __( 'Театр', 'idpg-core' ),
					'cinema'  => __( 'Кинотеатр', 'idpg-core' ),
					'museum'  => __( 'Музей', 'idpg-core' ),
					'concert' => __( 'Концертный зал', 'idpg-core' ),
				),
			),
			array(
				'id'       => 'idpg_venue_address',
				'key'      => '_idpg_venue_address',
				'type'     => 'text',
				'label'    => __( 'Адрес', 'idpg-core' ),
				'sanitize' => 'text',
			),
			array(
				'id'       => 'idpg_venue_phone',
				'key'      => '_idpg_venue_phone',
				'type'     => 'tel',
				'label'    => __( 'Телефон', 'idpg-core' ),
				'sanitize' => 'text',
			),
			array(
				'id'       => 'idpg_venue_email',
				'key'      => '_idpg_venue_email',
				'type'     => 'email',
				'label'    => __( 'Email', 'idpg-core' ),
				'sanitize' => 'email',
			),
			array(
				'id'          => 'idpg_venue_website',
				'key'         => '_idpg_venue_website',
				'type'        => 'url',
				'label'       => __( 'Сайт', 'idpg-core' ),
				'sanitize'    => 'url',
				'placeholder' => 'https://',
			),
			array(
				'id'       => 'idpg_venue_capacity',
				'key'      => '_idpg_venue_capacity',
				'type'     => 'number',
				'label'    => __( 'Вместимость', 'idpg-core' ),
				'sanitize' => 'int',
			),
			array(
				'id'          => 'idpg_venue_lat',
				'key'         => '_idpg_venue_lat',
				'type'        => 'text',
				'label'       => __( 'Широта', 'idpg-core' ),
				'help'        => __( 'Десятичные градусы, например 56.8527. Используется для микроразметки GeoCoordinates.', 'idpg-core' ),
				'sanitize'    => 'text',
				'placeholder' => '56.8527',
			),
			array(
				'id'          => 'idpg_venue_lng',
				'key'         => '_idpg_venue_lng',
				'type'        => 'text',
				'label'       => __( 'Долгота', 'idpg-core' ),
				'help'        => __( 'Десятичные градусы, например 53.2115. Используется для микроразметки GeoCoordinates.', 'idpg-core' ),
				'sanitize'    => 'text',
				'placeholder' => '53.2115',
			),
		);
	}

	/**
	 * Регистрация метабоксов площадки.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'venue' !== $screen->post_type ) {
			return;
		}

		add_meta_box(
			'idpg_venue_data',
			__( 'Данные площадки', 'idpg-core' ),
			array( $this, 'render_meta_box' ),
			'venue',
			'normal',
			'high'
		);

		add_meta_box(
			'idpg_venue_hours_social',
			__( 'Часы работы и соцсети', 'idpg-core' ),
			array( $this, 'render_hours_social_meta_box' ),
			'venue',
			'normal',
			'high'
		);
	}

	/**
	 * Вывод метабокса «Данные площадки».
	 *
	 * @param WP_Post $post Объект поста.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<table class="form-table" role="presentation">';
		foreach ( self::get_fields() as $field ) {
			$value = get_post_meta( $post->ID, $field['key'], true );
			echo '<tr>';
			echo '<th scope="row"><label for="' . esc_attr( $field['id'] ) . '">' . esc_html( $field['label'] ) . '</label></th>';
			echo '<td>';
			self::render_field_control( $field, $value );
			if ( ! empty( $field['help'] ) ) {
				echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</table>';
	}

	/**
	 * Вывод метабокса «Часы работы и соцсети».
	 *
	 * @param WP_Post $post Объект поста.
	 * @return void
	 */
	public function render_hours_social_meta_box( $post ) {
		// Nonce уже выведен в первом метабоксе — форма общая.
		$hours  = self::normalize_hours_value( get_post_meta( $post->ID, '_idpg_venue_hours', true ) );
		$social = self::normalize_social_value( get_post_meta( $post->ID, '_idpg_venue_social', true ) );

		// Часы работы.
		echo '<h4>' . esc_html__( 'Часы работы', 'idpg-core' ) . '</h4>';
		echo '<div class="idpg-venue-hours" id="idpg-venue-hours-field">';
		echo '<div class="idpg-venue-hours__rows">';

		$day_labels = self::get_day_labels();

		if ( empty( $hours ) ) {
			self::render_hour_row( 0, array(), $day_labels );
		} else {
			foreach ( $hours as $i => $row ) {
				self::render_hour_row( $i, $row, $day_labels );
			}
		}

		echo '</div>';
		echo '<p><button type="button" class="button idpg-venue-hours__add">' . esc_html__( '+ Добавить день', 'idpg-core' ) . '</button></p>';
		echo '</div>';

		// Соцсети.
		echo '<h4 style="margin-top:20px;">' . esc_html__( 'Соцсети', 'idpg-core' ) . '</h4>';
		echo '<div class="idpg-venue-social" id="idpg-venue-social-field">';
		echo '<div class="idpg-venue-social__rows">';

		$networks = self::get_network_labels();

		if ( empty( $social ) ) {
			self::render_social_row( 0, array(), $networks );
		} else {
			foreach ( $social as $i => $row ) {
				self::render_social_row( $i, $row, $networks );
			}
		}

		echo '</div>';
		echo '<p><button type="button" class="button idpg-venue-social__add">' . esc_html__( '+ Добавить соцсеть', 'idpg-core' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Вывод одной строки часов работы.
	 *
	 * @param int   $index Индекс строки.
	 * @param array $row   Значения строки.
	 * @param array $days  Метки дней.
	 * @return void
	 */
	protected static function render_hour_row( $index, $row, $days ) {
		$day       = isset( $row['day'] ) ? $row['day'] : '';
		$open      = isset( $row['open'] ) ? $row['open'] : '';
		$close     = isset( $row['close'] ) ? $row['close'] : '';
		$is_closed = ! empty( $row['is_closed'] );

		echo '<div class="idpg-venue-hours__row" data-index="' . esc_attr( $index ) . '">';

		echo '<select name="idpg_venue_hours_rows[' . esc_attr( $index ) . '][day]">';
		echo '<option value="">' . esc_html__( '— День —', 'idpg-core' ) . '</option>';
		foreach ( $days as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $day, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select> ';

		printf(
			'<input type="time" name="idpg_venue_hours_rows[%1$s][open]" value="%2$s" />',
			esc_attr( $index ),
			esc_attr( $open )
		);
		printf(
			'<input type="time" name="idpg_venue_hours_rows[%1$s][close]" value="%2$s" />',
			esc_attr( $index ),
			esc_attr( $close )
		);
		printf(
			'<label><input type="checkbox" name="idpg_venue_hours_rows[%1$s][is_closed]" value="1" %2$s /> %3$s</label>',
			esc_attr( $index ),
			checked( $is_closed, true, false ),
			esc_html__( 'Выходной', 'idpg-core' )
		);

		echo ' <button type="button" class="button idpg-venue-hours__remove" title="' . esc_attr__( 'Удалить', 'idpg-core' ) . '">&times;</button>';
		echo '</div>';
	}

	/**
	 * Вывод одной строки соцсети.
	 *
	 * @param int   $index    Индекс строки.
	 * @param array $row      Значения строки.
	 * @param array $networks Метки сетей.
	 * @return void
	 */
	protected static function render_social_row( $index, $row, $networks ) {
		$network = isset( $row['network'] ) ? $row['network'] : '';
		$url     = isset( $row['url'] ) ? $row['url'] : '';

		echo '<div class="idpg-venue-social__row" data-index="' . esc_attr( $index ) . '">';

		echo '<select name="idpg_venue_social_rows[' . esc_attr( $index ) . '][network]">';
		echo '<option value="">' . esc_html__( '— Сеть —', 'idpg-core' ) . '</option>';
		foreach ( $networks as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $network, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select> ';

		printf(
			'<input type="url" name="idpg_venue_social_rows[%1$s][url]" value="%2$s" placeholder="https://" class="regular-text" />',
			esc_attr( $index ),
			esc_attr( $url )
		);

		echo ' <button type="button" class="button idpg-venue-social__remove" title="' . esc_attr__( 'Удалить', 'idpg-core' ) . '">&times;</button>';
		echo '</div>';
	}

	/**
	 * Метки дней недели.
	 *
	 * @return array
	 */
	public static function get_day_labels() {
		return array(
			'mon' => __( 'Понедельник', 'idpg-core' ),
			'tue' => __( 'Вторник', 'idpg-core' ),
			'wed' => __( 'Среда', 'idpg-core' ),
			'thu' => __( 'Четверг', 'idpg-core' ),
			'fri' => __( 'Пятница', 'idpg-core' ),
			'sat' => __( 'Суббота', 'idpg-core' ),
			'sun' => __( 'Воскресенье', 'idpg-core' ),
		);
	}

	/**
	 * Метки социальных сетей.
	 *
	 * @return array
	 */
	public static function get_network_labels() {
		return array(
			'vk'        => 'VK',
			'tg'        => 'Telegram',
			'wa'        => 'WhatsApp',
			'instagram' => 'Instagram',
			'youtube'   => 'YouTube',
			'ok'        => __( 'Одноклассники', 'idpg-core' ),
			'other'     => __( 'Другое', 'idpg-core' ),
		);
	}

	/**
	 * Вывод управляющего элемента поля.
	 *
	 * @param array $field Описание поля.
	 * @param mixed $value Текущее значение.
	 * @return void
	 */
	protected static function render_field_control( $field, $value ) {
		$type = $field['type'];
		$id   = $field['id'];

		switch ( $type ) {
			case 'select':
				$options = isset( $field['options'] ) ? $field['options'] : array();
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
				foreach ( $options as $opt_value => $opt_label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $opt_value ),
						selected( (string) $value, (string) $opt_value, false ),
						esc_html( $opt_label )
					);
				}
				echo '</select>';
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%1$s" value="%2$s" min="0" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;

			case 'url':
				$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : 'https://';
				printf(
					'<input type="url" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value ),
					esc_attr( $placeholder )
				);
				break;

			case 'email':
				printf(
					'<input type="email" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;

			case 'tel':
				printf(
					'<input type="tel" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;

			default:
				$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
				printf(
					'<input type="text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value ),
					esc_attr( $placeholder )
				);
				break;
		}
	}

	/**
	 * Сохранение полей при сохранении поста.
	 *
	 * @param int     $post_id ID поста.
	 * @param WP_Post $post    Объект поста.
	 * @param bool    $update  Обновление ли это.
	 * @return void
	 */
	public function save_post_fields( $post_id, $post, $update ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'venue' !== $post->post_type ) {
			return;
		}

		if ( empty( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Простые поля.
		foreach ( self::get_fields() as $field ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- санитизация в switch ниже.
			$raw = isset( $_POST[ $field['id'] ] ) ? wp_unslash( $_POST[ $field['id'] ] ) : '';

			switch ( $field['sanitize'] ) {
				case 'int':
					$value = absint( $raw );
					break;
				case 'url':
					$value = esc_url_raw( $raw );
					break;
				case 'email':
					$value = sanitize_email( $raw );
					break;
				default:
					$value = sanitize_text_field( $raw );
					break;
			}

			if ( '' === $value || 0 === $value ) {
				delete_post_meta( $post_id, $field['key'] );
			} else {
				update_post_meta( $post_id, $field['key'], $value );
			}
		}

		// Часы работы.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- санитизация в normalize_hours_value().
		$hours_raw = isset( $_POST['idpg_venue_hours_rows'] ) ? wp_unslash( $_POST['idpg_venue_hours_rows'] ) : array();
		$hours     = self::normalize_hours_value( $hours_raw );

		if ( empty( $hours ) ) {
			delete_post_meta( $post_id, '_idpg_venue_hours' );
			delete_post_meta( $post_id, IDPG_Meta::VENUE_HOURS_MIRROR );
		} else {
			update_post_meta( $post_id, '_idpg_venue_hours', $hours );
			update_post_meta( $post_id, IDPG_Meta::VENUE_HOURS_MIRROR, wp_json_encode( $hours ) );
		}

		// Соцсети.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- санитизация в normalize_social_value().
		$social_raw = isset( $_POST['idpg_venue_social_rows'] ) ? wp_unslash( $_POST['idpg_venue_social_rows'] ) : array();
		$social     = self::normalize_social_value( $social_raw );

		if ( empty( $social ) ) {
			delete_post_meta( $post_id, '_idpg_venue_social' );
			delete_post_meta( $post_id, IDPG_Meta::VENUE_SOCIAL_MIRROR );
		} else {
			update_post_meta( $post_id, '_idpg_venue_social', $social );
			update_post_meta( $post_id, IDPG_Meta::VENUE_SOCIAL_MIRROR, wp_json_encode( $social ) );
		}
	}

	/**
	 * Нормализация строк часов работы.
	 *
	 * @param mixed $value Сырое значение.
	 * @return array
	 */
	public static function normalize_hours_value( $value ) {
		if ( is_array( $value ) ) {
			$rows = $value;
		} elseif ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			$rows    = is_array( $decoded ) ? $decoded : array();
		} else {
			$rows = array();
		}

		$normalized = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$day = isset( $row['day'] ) ? sanitize_text_field( (string) $row['day'] ) : '';

			if ( '' === $day ) {
				continue;
			}

			$normalized[] = array(
				'day'       => $day,
				'open'      => isset( $row['open'] ) ? sanitize_text_field( (string) $row['open'] ) : '',
				'close'     => isset( $row['close'] ) ? sanitize_text_field( (string) $row['close'] ) : '',
				'is_closed' => ! empty( $row['is_closed'] ),
			);
		}

		return $normalized;
	}

	/**
	 * Нормализация строк соцсетей.
	 *
	 * @param mixed $value Сырое значение.
	 * @return array
	 */
	public static function normalize_social_value( $value ) {
		if ( is_array( $value ) ) {
			$rows = $value;
		} elseif ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			$rows    = is_array( $decoded ) ? $decoded : array();
		} else {
			$rows = array();
		}

		$normalized = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$network = isset( $row['network'] ) ? sanitize_text_field( (string) $row['network'] ) : '';
			$url     = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';

			if ( '' === $network || '' === $url ) {
				continue;
			}

			$normalized[] = array(
				'network' => $network,
				'url'     => $url,
			);
		}

		return $normalized;
	}
}
