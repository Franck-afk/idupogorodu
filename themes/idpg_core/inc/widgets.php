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
	// Статический сайдбар главной страницы (правый сайтбар с рекламой).
	register_sidebar(
		array(
			'name'          => __( 'Боковая колонка (главная страница)', 'idpg_core' ),
			'id'            => 'home-sidebar',
			'name_admin'    => 'idpg-home-sidebar',
			'description'   => __( 'Правый сайтбар на главной странице — рекламные блоки.', 'idpg_core' ),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h3 class="idpg-ad-widget__title">',
			'after_title'   => '</h3>',
		)
	);
}
add_action( 'widgets_init', 'idpg_core_register_sidebars' );

if ( ! function_exists( 'idpg_core_ad_code_allowed_html' ) ) :

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

endif;

if ( ! class_exists( 'IDPG_Ad_Ru_Widget' ) ) :

/**
 * Российские рекламные площадки: пресеты с шаблонами кода.
 *
 * Список площадок: slug => массив ( 'label', 'template', 'hint' ).
 * Шаблоны — подстановочные заготовки: администратор меняет XXXXX
 * (или номер блока) на свои ID из личного кабинета площадки.
 *
 * @return array
 */
function idpg_core_ad_platforms() {
	return array(
		'yandex_direct' => array(
			'label'    => __( 'Яндекс Директ / РСЯ', 'idpg_core' ),
			'template' => "<!-- Яндекс Директ / РСЯ -->\n<script>window.yaContextCb=window.yaContextCb||[]</script>\n<script src=\"https://yandex.ru/ads/system/context.js\" async></script>\n<div id=\"yandex_rtb_R-A-XXXXXX-Y\"></div>\n<script>window.yaContextCb.push(()=>{Ya.Context.AdvManager.render({renderTo:'yandex_rtb_R-A-XXXXXX-Y',blockId:'R-A-XXXXXX-Y'})})</script>",
			'hint'     => __( 'Замените R-A-XXXXXX-Y на номер блока из кабинета Яндекс Директ (РСЯ).', 'idpg_core' ),
		),
		'vk_reklama' => array(
			'label'    => __( 'VK Реклама (ex-MyTarget)', 'idpg_core' ),
			'template' => "<!-- VK Реклама (ex-MyTarget) -->\n<div id=\"MyTarget_block_XXXXX\"></div>\n<script src=\"https://ad.mail.ru/static/ads-async.js\" async></script>\n<script>\n    new MyTarget({ id: 'MyTarget_block_XXXXX', adId: 'XXXXX' });\n</script>",
			'hint'     => __( 'Замените XXXXX на ID блока из кабинета VK Реклама (ads.vk.com).', 'idpg_core' ),
		),
		'smi2' => array(
			'label'    => __( 'SMI2 (Контент маркет)', 'idpg_core' ),
			'template' => "<!-- SMI2 (Контент маркет) -->\n<script type=\"text/javascript\" src=\"//smi2.ru/data/js/XXXXX.js\" charset=\"utf-8\"></script>\n<script type=\"text/javascript\" src=\"//static.smi2.net/js/asyncInit.js\" charset=\"utf-8\"></script>",
			'hint'     => __( 'Замените XXXXX на номер счётчика SMI2.', 'idpg_core' ),
		),
		'mediavenus' => array(
			'label'    => __( 'MediaVenus', 'idpg_core' ),
			'template' => "<!-- MediaVenus -->\n<script type=\"text/javascript\" src=\"//static.mediavenus.ru/ads/js/XXXXX.js\"></script>",
			'hint'     => __( 'Замените XXXXX на номер площадки из кабинета MediaVenus.', 'idpg_core' ),
		),
		'marketgid' => array(
			'label'    => __( 'MarketGid', 'idpg_core' ),
			'template' => "<!-- MarketGid -->\n<script type=\"text/javascript\">\n    (function() {\n        var m = document.createElement('script');\n        m.async = true;\n        m.src = '//jsn.marketgid.com/script/XXXXX.js';\n        var s = document.getElementsByTagName('script')[0];\n        s.parentNode.insertBefore(m, s);\n    })();\n</script>",
			'hint'     => __( 'Замените XXXXX на ID места из кабинета MarketGid.', 'idpg_core' ),
		),
	);
}

/**
 * Виджет рекламного блока.
 *
 * Позволяет разместить рекламу любой площадки (HTML/JS-код) либо
 * баннер-изображение со ссылкой в любой зоне виджетов.
 * Поле «Позиция» управляет порядком вывода внутри зоны.
 */
class IDPG_Ad_Ru_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'idpg_ad',
			__( 'Реклама (IDPG)', 'idpg_core' ),
			array(
				'description'                 => __( 'Рекламный блок: код площадки, баннер или ссылка.', 'idpg_core' ),
				'customize_selective_refresh' => true,
			)
		);

		// Класс idpg-ad-widget добавляется только этому виджету
		// (не всем виджетам в зоне).
		add_filter( 'dynamic_sidebar_params', array( $this, 'add_ad_widget_class' ) );
	}

	/**
	 * Добавляет класс idpg-ad-widget в обёртку текущего рекламного виджета.
	 *
	 * @param array $params Параметры виджета.
	 * @return array
	 */
	public function add_ad_widget_class( $params ) {
		if ( isset( $params[0]['widget_id'] ) && strpos( $params[0]['widget_id'], $this->id_base . '-' ) === 0 ) {
			$params[0]['before_widget'] = str_replace(
				'class="widget ',
				'class="widget idpg-ad-widget ',
				$params[0]['before_widget']
			);
		}
		return $params;
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
		$title     = isset( $instance['title'] ) ? $instance['title'] : '';
		$ad_code   = isset( $instance['ad_code'] ) ? $instance['ad_code'] : '';
		$ad_image  = isset( $instance['ad_image'] ) ? $instance['ad_image'] : '';
		$ad_link   = isset( $instance['ad_link'] ) ? $instance['ad_link'] : '';
		$position  = isset( $instance['position'] ) ? $instance['position'] : 50;
		$platform  = isset( $instance['platform'] ) ? $instance['platform'] : '';
		$platforms = idpg_core_ad_platforms();
		$hint      = '';
		if ( '' !== $platform && isset( $platforms[ $platform ] ) ) {
			$hint = $platforms[ $platform ]['hint'];
		}
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Заголовок:', 'idpg_core' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<div class="idpg-ad-widget__platform-wrap" style="margin:8px 0 16px;">
			<label for="<?php echo esc_attr( $this->get_field_id( 'platform' ) ); ?>"><?php esc_html_e( 'Рекламная площадка (пресет):', 'idpg_core' ); ?></label>
			<select class="widefat idpg-ad-widget__platform" id="<?php echo esc_attr( $this->get_field_id( 'platform' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'platform' ) ); ?>">
				<option value=""><?php esc_html_e( '— Универсальный код (другая площадка) —', 'idpg_core' ); ?></option>
				<?php foreach ( $platforms as $slug => $platform_data ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $platform, $slug, true ); ?>><?php echo esc_html( $platform_data['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<div style="margin:6px 0;">
				<button type="button" class="button button-small idpg-ad-widget__preset-insert"><?php esc_html_e( 'Вставить шаблон в код', 'idpg_core' ); ?></button>
			</div>
			<label for="<?php echo esc_attr( $this->get_field_id( 'ad_code' ) ); ?>"><?php esc_html_e( 'Рекламный код (HTML/JS):', 'idpg_core' ); ?></label>
			<textarea class="widefat" rows="8" id="<?php echo esc_attr( $this->get_field_id( 'ad_code' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'ad_code' ) ); ?>"><?php echo esc_textarea( $ad_code ); ?></textarea>
			<small class="idpg-ad-widget__platform-hint"><?php echo esc_html( $hint ); ?></small>
		</div>
		<div class="idpg-ad-widget__media" style="margin:8px 0 16px;">
			<label for="<?php echo esc_attr( $this->get_field_id( 'ad_image' ) ); ?>"><?php esc_html_e( 'Или баннер (ID медиа):', 'idpg_core' ); ?></label>
			<div class="idpg-ad-widget__image-wrap" style="margin:8px 0">
				<?php if ( $ad_image ) : ?>
					<?php echo wp_get_attachment_image( $ad_image, 'medium', false, array( 'class' => 'idpg-ad-widget__form-img', 'style' => 'max-width:300px;height:auto;' ) ); ?>
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
		<p class="description"><?php esc_html_e( 'Код площадки имеет приоритет: если поле «Рекламный код» не пусто — выводится код, иначе — баннер-картинка. Чтобы показать баннер, очистите поле кода. «Позиция» задаёт порядок внутри зоны на фронте.', 'idpg_core' ); ?></p>
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
		$instance['platform'] = '';
		if ( ! empty( $new['platform'] ) ) {
			$slug = sanitize_key( (string) $new['platform'] );
			if ( array_key_exists( $slug, idpg_core_ad_platforms() ) ) {
				$instance['platform'] = $slug;
			}
		}
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

if ( ! class_exists( 'IDPG_Subscribe_Widget' ) ) :

/**
 * Виджет «Подписка на дайджест».
 *
 * Форма подписки на email-дайджест: заголовок, текст, поле email,
 * кнопка и опциональный чекбокс согласия. Отправка — через REST API
 * (wp-json/idpg/v1/subscribe), логика на assets/js/subscribe.js.
 */
class IDPG_Subscribe_Widget extends WP_Widget {

	/**
	 * Регистрация виджета.
	 */
	public function __construct() {
		parent::__construct(
			'idpg_subscribe',
			__( 'IDPG — Подписка на дайджест', 'idpg_core' ),
			array(
				'description' => __( 'Форма подписки на email-дайджест событий', 'idpg_core' ),
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
		$title         = ! empty( $instance['title'] ) ? apply_filters( 'widget_title', $instance['title'] ) : '';
		$text          = isset( $instance['text'] ) ? (string) $instance['text'] : '';
		$button        = ! empty( $instance['button'] ) ? $instance['button'] : __( 'Подписаться', 'idpg_core' );
		$show_consent  = ! empty( $instance['show_consent'] );
		$image         = isset( $instance['image'] ) ? absint( $instance['image'] ) : 0;

		if ( $image ) {
			echo '<div class="idpg-subscribe__image">' . wp_get_attachment_image( $image, 'medium' ) . '</div>';
		}

		if ( $title ) {
			echo $args['before_title'] . $title . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( '' !== $text ) {
			echo '<div class="idpg-subscribe__text">' . nl2br( esc_html( $text ) ) . '</div>';
		}
		?>
		<form class="idpg-subscribe__form" data-idpg-subscribe
			data-consent-required="<?php echo $show_consent ? '1' : '0'; ?>" novalidate>
			<label class="idpg-subscribe__label" for="<?php echo esc_attr( $this->id . '-email' ); ?>">
				<span class="sr-only"><?php esc_html_e( 'Email', 'idpg_core' ); ?></span>
			</label>
			<input class="idpg-subscribe__input" id="<?php echo esc_attr( $this->id . '-email' ); ?>"
				name="idpg_email" type="email" required autocomplete="email"
				placeholder="<?php esc_attr_e( 'Ваш email', 'idpg_core' ); ?>">
			<?php // Honeypot-поле против ботов: люди его не видят и не заполняют. ?>
			<input class="idpg-subscribe__hp" name="idpg_company" type="text"
				tabindex="-1" autocomplete="off" aria-hidden="true" value="">
			<?php if ( $show_consent ) : ?>
				<label class="idpg-subscribe__consent">
					<input type="checkbox" name="idpg_consent" value="1" required>
					<span><?php esc_html_e( 'Согласен на обработку персональных данных', 'idpg_core' ); ?></span>
				</label>
			<?php endif; ?>
			<button class="idpg-subscribe__button" type="submit"><?php echo esc_html( $button ); ?></button>
			<div class="idpg-subscribe__message" role="status" aria-live="polite" hidden></div>
		</form>
		<?php

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Форма настроек в админке.
	 *
	 * @param array $instance Текущие настройки виджета.
	 */
	public function form( $instance ) {
		$title         = isset( $instance['title'] ) ? $instance['title'] : '';
		$text          = isset( $instance['text'] ) ? $instance['text'] : '';
		$button        = isset( $instance['button'] ) && '' !== $instance['button'] ? $instance['button'] : __( 'Подписаться', 'idpg_core' );
		$show_consent  = ! empty( $instance['show_consent'] );
		$image         = isset( $instance['image'] ) ? absint( $instance['image'] ) : 0;
		?>
		<div class="idpg-ad-widget__media">
			<label><?php esc_html_e( 'Картинка:', 'idpg_core' ); ?></label>
			<div class="idpg-ad-widget__image-wrap" style="margin:8px 0">
				<?php if ( $image ) : ?>
					<?php echo wp_get_attachment_image( $image, 'medium', false, array( 'style' => 'max-width:300px;height:auto;' ) ); ?>
				<?php endif; ?>
			</div>
			<input type="hidden" class="idpg-ad-widget__image-id"
				id="<?php echo esc_attr( $this->get_field_id( 'image' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'image' ) ); ?>"
				value="<?php echo esc_attr( $image ); ?>">
			<button type="button" class="button idpg-ad-widget__select"><?php esc_html_e( 'Выбрать', 'idpg_core' ); ?></button>
			<button type="button" class="button idpg-ad-widget__remove"><?php esc_html_e( 'Убрать', 'idpg_core' ); ?></button>
		</div>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Заголовок:', 'idpg_core' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'text' ) ); ?>"><?php esc_html_e( 'Текст под заголовком:', 'idpg_core' ); ?></label>
			<textarea class="widefat" rows="3" id="<?php echo esc_attr( $this->get_field_id( 'text' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'text' ) ); ?>"><?php echo esc_textarea( $text ); ?></textarea>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'button' ) ); ?>"><?php esc_html_e( 'Кнопка:', 'idpg_core' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'button' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'button' ) ); ?>" type="text"
				value="<?php echo esc_attr( $button ); ?>">
		</p>
		<p>
			<input class="checkbox" type="checkbox" <?php checked( $show_consent ); ?>
				id="<?php echo esc_attr( $this->get_field_id( 'show_consent' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_consent' ) ); ?>" value="1">
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_consent' ) ); ?>"><?php esc_html_e( 'Показывать согласие на обработку данных', 'idpg_core' ); ?></label>
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
		$instance                  = array();
		$instance['title']         = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
		$instance['text']          = isset( $new_instance['text'] ) ? sanitize_textarea_field( $new_instance['text'] ) : '';
		$instance['button']        = ! empty( $new_instance['button'] ) ? sanitize_text_field( $new_instance['button'] ) : __( 'Подписаться', 'idpg_core' );
		$instance['show_consent']  = ! empty( $new_instance['show_consent'] ) ? 1 : 0;
		$instance['image']         = isset( $new_instance['image'] ) ? absint( $new_instance['image'] ) : 0;
		return $instance;
	}
}
endif;

/**
 * Регистрирует виджеты.
 */
function idpg_core_register_widgets() {
	register_widget( 'IDPG_Ad_Ru_Widget' );
	register_widget( 'IDPG_Recent_Events_Widget' );
	register_widget( 'IDPG_Subscribe_Widget' );
}
add_action( 'widgets_init', 'idpg_core_register_widgets', 20 );

/**
 * Enqueue media uploader и JS-пресетов площадок для формы виджета в админке.
 *
 * Медиа-загрузчик (widget-ad.js) — для опции «баннер-картинка»;
 * инлайн-скрипт — подстановка шаблона кода и подсказки при выборе площадки.
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

	$platforms = array();
	foreach ( idpg_core_ad_platforms() as $slug => $platform_data ) {
		$platforms[ $slug ] = array(
			'template' => $platform_data['template'],
			'hint'     => $platform_data['hint'],
		);
	}

	wp_localize_script(
		'idpg_core-ad-widget',
		'idpgAdWidgetL10n',
		array(
			'select'    => __( 'Выбрать', 'idpg_core' ),
			'platforms' => $platforms,
		)
	);

	$script = <<<'JS'
(function ($) {
	'use strict';

	function idpgAdWidgetApplyPlatformHints() {
		var l10n = window.idpgAdWidgetL10n || {};
		var platforms = l10n.platforms || {};

		$('.idpg-ad-widget__platform-wrap').each(function () {
			var $wrap = $(this);
			var slug = String($wrap.find('.idpg-ad-widget__platform').val() || '');
			var rec = platforms[slug];
			var $hint = $wrap.find('.idpg-ad-widget__platform-hint');

			$hint.text(rec ? rec.hint : '');
		});
	}

	function idpgAdWidgetInsertTemplate() {
		var $btn = $(this);
		var $wrap = $btn.closest('.idpg-ad-widget__platform-wrap');
		var slug = String($wrap.find('.idpg-ad-widget__platform').val() || '');
		var platforms = (window.idpgAdWidgetL10n || {}).platforms || {};

		if (!platforms[slug]) {
			return;
		}
		$wrap.find('textarea').val(platforms[slug].template);
		idpgAdWidgetApplyPlatformHints();
	}

	$(document).on('change', '.idpg-ad-widget__platform', idpgAdWidgetApplyPlatformHints);
	$(document).on('click', '.idpg-ad-widget__preset-insert', idpgAdWidgetInsertTemplate);
	$(document).on('widget-added widget-updated', idpgAdWidgetApplyPlatformHints);
	setTimeout(idpgAdWidgetApplyPlatformHints, 300);
})(jQuery);
JS;

	wp_add_inline_script( 'idpg_core-ad-widget', $script );
}
add_action( 'admin_enqueue_scripts', 'idpg_core_ad_widget_admin_assets' );

/**
 * Фронтенд: сортировка рекламных виджетов внутри зоны по полю «позиция».
 *
 * Применяется только к правому сайтбару главной (home-sidebar).
 * Рекламные виджеты упорядочиваются по своей «позиции» на тех местах,
 * где они уже стоят, — не-рекламные виджеты остаются на своих местах,
 * реклама не переносится в начало зоны.
 */
function idpg_core_sort_home_sidebar_widgets( $sidebar_widgets ) {
	if ( is_admin() ) {
		return $sidebar_widgets;
	}

	$zone = 'home-sidebar';
	if ( ! isset( $sidebar_widgets[ $zone ] ) || ! is_array( $sidebar_widgets[ $zone ] ) ) {
		return $sidebar_widgets;
	}

	$widgets = $sidebar_widgets[ $zone ];
	if ( empty( $widgets ) ) {
		return $sidebar_widgets;
	}

	$ads    = array();
	$others = array();
	$marker = array();

	foreach ( $widgets as $widget_id ) {
		if ( strpos( $widget_id, 'idpg_ad-' ) !== 0 ) {
			$others[] = $widget_id;
			$marker[] = 'other';
			continue;
		}

		$parts = explode( '-', $widget_id );
		$num   = isset( $parts[1] ) ? $parts[1] : 0;
		$inst  = get_option( 'widget_idpg_ad', array() );
		$pos   = 50;
		if ( isset( $inst[ $num ] ) && is_array( $inst[ $num ] ) ) {
			$pos = isset( $inst[ $num ]['position'] ) ? (int) $inst[ $num ]['position'] : 50;
		}

		$ads[]    = array( 'id' => $widget_id, 'pos' => $pos );
		$marker[] = 'ad';
	}

	if ( ! $ads ) {
		return $sidebar_widgets;
	}

	usort( $ads, function( $a, $b ) {
		return $a['pos'] <=> $b['pos'];
	} );

	$ad_ids     = array_column( $ads, 'id' );
	$ad_index   = 0;
	$other_index = 0;
	$result     = array();

	foreach ( $marker as $kind ) {
		if ( 'ad' === $kind ) {
			$result[] = isset( $ad_ids[ $ad_index ] ) ? $ad_ids[ $ad_index ] : '';
			$ad_index++;
		} else {
			$result[] = isset( $others[ $other_index ] ) ? $others[ $other_index ] : '';
			$other_index++;
		}
	}

	$sidebar_widgets[ $zone ] = $result;

	return $sidebar_widgets;
}
add_filter( 'sidebars_widgets', 'idpg_core_sort_home_sidebar_widgets' );

/**
 * Поднимает рекламные виджеты на самый верх сайдбара страниц событий.
 *
 * Применяется только к областям виджетов с позицией event-sidebar
 * (сайдбар страниц события и площадки). Рекламные виджеты переносятся
 * в начало зоны, остальные виджеты сохраняют свой порядок.
 *
 * @param array $sidebar_widgets Массив виджетов по сайдбарам.
 * @return array
 */
function idpg_core_sort_event_sidebar_widgets( $sidebar_widgets ) {
	if ( is_admin() ) {
		return $sidebar_widgets;
	}

	// Собираем id сайдбаров областей с позицией event-sidebar.
	$event_sidebars = array();
	foreach ( (array) get_option( 'idpg_widget_areas', array() ) as $slug => $area ) {
		if ( isset( $area['position'] ) && 'event-sidebar' === $area['position'] ) {
			$event_sidebars[] = 'idpg-area-' . sanitize_key( $slug );
		}
	}

	if ( ! $event_sidebars ) {
		return $sidebar_widgets;
	}

	foreach ( $event_sidebars as $zone ) {
		if ( ! isset( $sidebar_widgets[ $zone ] ) || ! is_array( $sidebar_widgets[ $zone ] ) ) {
			continue;
		}

		$widgets = $sidebar_widgets[ $zone ];
		if ( empty( $widgets ) ) {
			continue;
		}

		$ads    = array();
		$others = array();

		foreach ( $widgets as $widget_id ) {
			if ( strpos( $widget_id, 'idpg_ad-' ) === 0 ) {
				$ads[] = $widget_id;
			} else {
				$others[] = $widget_id;
			}
		}

		if ( ! $ads ) {
			continue;
		}

		$sidebar_widgets[ $zone ] = array_merge( $ads, $others );
	}

	return $sidebar_widgets;
}
add_filter( 'sidebars_widgets', 'idpg_core_sort_event_sidebar_widgets', 11 );
