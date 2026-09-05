<?php
/**
 * Widget areas (sidebars) and the advertising widget for idpg_core.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Регистрирует зоны виджетов.
 */
function idpg_core_register_sidebars() {
	register_sidebar(
		array(
			'name'          => __( 'Боковая колонка (главная страница)', 'idpg_core' ),
			'id'            => 'home-sidebar',
			'name_admin'    => 'idpg-home-sidebar',
			'description'   => __( 'Правый сайтбар на главной странице — рекламные блоки.', 'idpg_core' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s idpg-ad-widget">',
			'after_widget'  => '</section>',
			'before_title'  => '<h3 class="idpg-ad-widget__title">',
			'after_title'   => '</h3>',
		)
	);

	register_sidebar(
		array(
			'name'          => __( 'Виджеты: под контентом', 'idpg_core' ),
			'id'            => 'below-content',
			'description'   => __( 'Виджеты внизу основного контента.', 'idpg_core' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h3 class="widget-title">',
			'after_title'   => '</h3>',
		)
	);
}
add_action( 'widgets_init', 'idpg_core_register_sidebars' );

if ( ! class_exists( 'IDPG_Ad_Widget' ) ) :

/**
 * Разрешённые HTML-теги для рекламного кода.
 *
 * Расширяет безопасный набор wp_kses_post тегами <script> и <iframe>,
 * чтобы JS/iframe-реклама (РСЯ, Яндекс.Директ и т.п.) корректно
 * отображалась. Используется для сохранения и вывода, чтобы избежать
 * повторной фильтрации, которая вырезала бы теги.
 *
 * @return array
 */
function idpg_core_ad_code_allowed_html() {
	$allowed = wp_kses_allowed_html( 'post' );

	$allowed['script'] = array(
		'src'      => true,
		'type'     => true,
		'async'    => true,
		'defer'    => true,
		'charset'  => true,
		'crossorigin' => true,
	);

	$allowed['iframe'] = array(
		'src'             => true,
		'width'           => true,
		'height'          => true,
		'frameborder'     => true,
		'allow'           => true,
		'allowfullscreen' => true,
		'loading'         => true,
		'scrolling'       => true,
		'title'           => true,
		'class'           => true,
		'style'           => true,
		'referrerpolicy'  => true,
	);

	return $allowed;
}

/**
 * Виджет рекламного блока.
 *
 * Позволяет разместить рекламу (HTML/JS-код, либо баннер-изображение со ссылкой)
 * в любой зоне виджетов. Поле «Позиция» управляет порядком вывода внутри зоны.
 */
class IDPG_Ad_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'idpg_ad',
			__( 'Реклама (IDPG)', 'idpg_core' ),
			array(
				'description'              => __( 'Рекламный блок: код, баннер или ссылка.', 'idpg_core' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	public function widget( $args, $instance ) {
		$title    = ! empty( $instance['title'] ) ? apply_filters( 'widget_title', $instance['title'] ) : '';
		$ad_code  = ! empty( $instance['ad_code'] ) ? $instance['ad_code'] : '';
		$ad_image = ! empty( $instance['ad_image'] ) ? (int) $instance['ad_image'] : 0;
		$ad_link  = ! empty( $instance['ad_link'] ) ? esc_url_raw( $instance['ad_link'] ) : '';

		echo $args['before_widget'];

		if ( $title ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}

		if ( $ad_code ) {
			echo wp_kses( $ad_code, idpg_core_ad_code_allowed_html() );
		} elseif ( $ad_image ) {
			$img = wp_get_attachment_image( $ad_image, 'medium', false, array( 'class' => 'idpg-ad-widget__img' ) );
			if ( $img && $ad_link ) {
				echo '<a href="' . esc_url( $ad_link ) . '" target="_blank" rel="nofollow noopener">' . $img . '</a>';
			} elseif ( $img ) {
				echo $img;
			} else {
				// Вложение не найдено (удалено) — показываем заглушку,
				// чтобы виджет не выводил пустоту.
				echo '<p class="idpg-ad-widget__empty">' . esc_html__( '(Рекламный блок)', 'idpg_core' ) . '</p>';
			}
		} else {
			echo '<p class="idpg-ad-widget__empty">' . esc_html__( '(Рекламный блок)', 'idpg_core' ) . '</p>';
		}

		echo $args['after_widget'];
	}

	public function form( $instance ) {
		$title    = isset( $instance['title'] ) ? $instance['title'] : '';
		$ad_code  = isset( $instance['ad_code'] ) ? $instance['ad_code'] : '';
		$ad_image = isset( $instance['ad_image'] ) ? $instance['ad_image'] : '';
		$ad_link  = isset( $instance['ad_link'] ) ? $instance['ad_link'] : '';
		$position = isset( $instance['position'] ) ? $instance['position'] : 50;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Заголовок:', 'idpg_core' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'ad_code' ) ); ?>"><?php esc_html_e( 'Рекламной код (HTML/JS):', 'idpg_core' ); ?></label>
			<textarea class="widefat" rows="8" id="<?php echo esc_attr( $this->get_field_id( 'ad_code' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'ad_code' ) ); ?>"><?php echo esc_textarea( $ad_code ); ?></textarea>
			<small><?php esc_html_e( 'Пример: <code>&lt;script async src="https://example.com/ad.js"&gt;&lt;/script&gt;</code>', 'idpg_core' ); ?></small>
		</p>
		<div class="idpg-ad-widget__media" style="margin:8px 0 16px;">
			<label for="<?php echo esc_attr( $this->get_field_id( 'ad_image' ) ); ?>"><?php esc_html_e( 'Или баннер (ID медиа):', 'idpg_core' ); ?></label>
			<div class="idpg-ad-widget__image-wrap" style="margin:8px 0">
				<?php if ( $ad_image ) : ?>
					<?php echo wp_get_attachment_image( $ad_image, 'medium' ); ?>
				<?php endif; ?>
			</div>
			<input type="hidden" class="idpg-ad-widget__image-id"
				id="<?php echo esc_attr( $this->get_field_id( 'ad_image' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'ad_image' ) ); ?>"
				value="<?php echo esc_attr( $ad_image ); ?>">
			<button type="button" class="button idpg-ad-widget__select"><?php esc_html_e( 'Выбрать', 'idpg_core' ); ?></button>
			<button type="button" class="button idpg-ad-widget__remove"><?php esc_html_e( 'Убрать', 'idpg_core' ); ?></button>
		</div>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'ad_link' ) ); ?>"><?php esc_html_e( 'Ссылка рекламодателя:', 'idpg_core' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'ad_link' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'ad_link' ) ); ?>" type="url"
				value="<?php echo esc_attr( $ad_link ); ?>" placeholder="https://...">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'position' ) ); ?>"><?php esc_html_e( 'Позиция (чем меньше — тем выше):', 'idpg_core' ); ?></label>
			<input class="small-text" id="<?php echo esc_attr( $this->get_field_id( 'position' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'position' ) ); ?>" type="number" min="0"
				value="<?php echo esc_attr( $position ); ?>">
		</p>
		<p class="description"><?php esc_html_e( 'Если заполнен рекламной код — выводится он; иначе выводится баннер. "Позиция" управляет порядком внутри зоны на фронте.', 'idpg_core' ); ?></p>
		<?php
	}

	public function update( $new, $old ) {
		$instance = array();
		$instance['title']    = ! empty( $new['title'] ) ? sanitize_text_field( $new['title'] ) : '';

		$ad_code = isset( $new['ad_code'] ) ? (string) $new['ad_code'] : '';
		if ( '' !== $ad_code ) {
			// Только пользователи с правом edit_theme_options (обычно администраторы)
			// могут вставлять JS/iframe. Остальные — строго безопасный HTML.
			$instance['ad_code'] = current_user_can( 'edit_theme_options' )
				? wp_kses( $ad_code, idpg_core_ad_code_allowed_html() )
				: wp_kses_post( $ad_code );
		} else {
			$instance['ad_code'] = '';
		}

		$instance['ad_image'] = ! empty( $new['ad_image'] ) ? (int) $new['ad_image'] : '';
		$instance['ad_link']  = ! empty( $new['ad_link'] ) ? esc_url_raw( $new['ad_link'] ) : '';
		$instance['position'] = ! empty( $new['position'] ) ? max( 0, min( 999, (int) $new['position'] ) ) : 50;
		return $instance;
	}
}
endif;

if ( ! class_exists( 'IDPG_Recent_Events_Widget' ) ) :

/**
 * Виджет «Последние события».
 *
 * Показывает последние добавленные события (CPT "event").
 */
class IDPG_Recent_Events_Widget extends WP_Widget {

	/**
	 * Регистрация виджета.
	 */
	public function __construct() {
		parent::__construct(
			'idpg_recent_events_widget',
			__( 'IDPG — Последние события', 'idpg_core' ),
			array(
				'description' => __( 'Показывает последние добавленные события', 'idpg_core' ),
			)
		);
	}

	/**
	 * Вывод виджета на фронтенде.
	 *
	 * @param array $args     Параметры области виджетов.
	 * @param array $instance Настройки виджета.
	 */
	public function widget( $args, $instance ) {
		$title    = ! empty( $instance['title'] ) ? apply_filters( 'widget_title', $instance['title'] ) : '';
		$number   = isset( $instance['number'] ) ? (int) $instance['number'] : 5;
		$number   = max( 2, min( 10, $number ) );

		$query_args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => $number,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( class_exists( 'IDPG_City_Filter' ) ) {
			$city_filter = IDPG_City_Filter::instance();

			if ( ! $city_filter->has_city_in_url() ) {
				$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'city',
						'field'    => 'slug',
						'terms'    => IDPG_City_Filter::get_current_city_slug(),
					),
				);
			}
		}

		$query = new WP_Query( $query_args );

		if ( ! $query->have_posts() ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $title ) {
			echo $args['before_title'] . $title . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<ul class="idpg-recent-events">';

		while ( $query->have_posts() ) {
			$query->the_post();
			printf(
				'<li class="idpg-recent-events__item"><a href="%s">%s</a></li>',
				esc_url( get_permalink() ),
				esc_html( get_the_title() )
			);
		}

		echo '</ul>';

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		wp_reset_postdata();
	}

	/**
	 * Форма настроек в админке.
	 *
	 * @param array $instance Текущие настройки виджета.
	 */
	public function form( $instance ) {
		$title  = isset( $instance['title'] ) ? $instance['title'] : '';
		$number = isset( $instance['number'] ) ? (int) $instance['number'] : 5;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Заголовок:', 'idpg_core' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( 'Количество событий (2–10):', 'idpg_core' ); ?></label>
			<input class="small-text" id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" type="number" min="2" max="10"
				value="<?php echo esc_attr( $number ); ?>">
		</p>
		<?php
	}

	/**
	 * Сохранение настроек.
	 *
	 * @param array $new_instance Новые настройки.
	 * @param array $old_instance Старые настройки.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$instance          = array();
		$instance['title'] = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';

		// isset + проверка пустой строки: '' → дефолт 5, '0' → clamp до 2.
		$raw    = isset( $new_instance['number'] ) ? $new_instance['number'] : '';
		$number = ( '' === $raw ) ? 5 : (int) $raw;
		$instance['number'] = max( 2, min( 10, $number ) );
		return $instance;
	}
}
endif;

/**
 * Регистрирует виджеты.
 */
function idpg_core_register_widgets() {
	register_widget( 'IDPG_Ad_Widget' );
	register_widget( 'IDPG_Recent_Events_Widget' );
}
add_action( 'widgets_init', 'idpg_core_register_widgets', 20 );

/**
 * Enqueue media uploader для формы виджета в админке.
 */
function idpg_core_ad_widget_admin_assets() {
	if ( ! is_admin() ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_script(
		'idpg_core-ad-widget',
		get_template_directory_uri() . '/assets/js/widget-ad.js',
		array( 'jquery', 'media-views' ),
		IDPG_VERSION,
		true
	);
	wp_localize_script(
		'idpg_core-ad-widget',
		'idpgAdWidgetL10n',
		array(
			'select' => __( 'Выбрать', 'idpg_core' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'idpg_core_ad_widget_admin_assets' );

/**
 * Фронтенд: сортировка виджетов рекламы внутри зоны по полю «позиция».
 *
 * Читаем instance виджета из опции widget_idpg_ad, сортируем id'шники в зоне.
 */
function idpg_core_sort_home_sidebar_widgets( $sidebar_widgets ) {
	if ( is_admin() ) {
		return $sidebar_widgets;
	}

	foreach ( array_keys( $sidebar_widgets ) as $zone ) {
		if ( $zone === 'array_split' ) {
			continue;
		}
		$widgets = is_array( $sidebar_widgets[ $zone ] ) ? $sidebar_widgets[ $zone ] : array();
		if ( empty( $widgets ) ) {
			continue;
		}

		$ads    = array();
		$others = array();

		foreach ( $widgets as $widget_id ) {
			if ( strpos( $widget_id, 'idpg_ad-' ) !== 0 ) {
				$others[] = $widget_id;
				continue;
			}

			$parts  = explode( '-', $widget_id );
			$num    = isset( $parts[1] ) ? $parts[1] : 0;
			$inst   = get_option( 'widget_idpg_ad', array() );
			$pos    = 50;
			if ( isset( $inst[ $num ] ) && is_array( $inst[ $num ] ) ) {
				$pos = isset( $inst[ $num ]['position'] ) ? (int) $inst[ $num ]['position'] : 50;
			}

			$ads[] = array( 'id' => $widget_id, 'pos' => $pos );
		}

		if ( ! $ads ) {
			continue;
		}

		usort( $ads, function( $a, $b ) {
			return $a['pos'] <=> $b['pos'];
		} );

		$sidebar_widgets[ $zone ] = array_merge( array_column( $ads, 'id' ), $others );
	}

	return $sidebar_widgets;
}
add_filter( 'sidebars_widgets', 'idpg_core_sort_home_sidebar_widgets' );
