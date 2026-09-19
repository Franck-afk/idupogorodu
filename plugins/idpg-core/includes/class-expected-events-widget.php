<?php
/**
 * Виджет «Самые ожидаемые события».
 *
 * Показывает предстоящие события с наибольшим числом просмотров
 * (метрика «ожидаемости» = счётчик _idpg_views из IDPG_Views).
 *
 * Выборка: только будущие события (дата начала >= сегодня),
 * сортировка по убыванию просмотров, затем по ближайшей дате.
 * Город фильтруется автоматически (таксономия city — как в
 * idpg_get_home_section_events), если у сайта включён выбор города.
 *
 * Кэш списка — 1 час (HOUR_IN_SECONDS), ключ включает город,
 * количество и опцию «только бесплатные».
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Expected_Events_Widget
 */
class IDPG_Expected_Events_Widget extends WP_Widget {

	/**
	 * Служебный id_base виджета.
	 *
	 * @var string
	 */
	const ID_BASE = 'idpg_expected_events';

	/**
	 * Тип записи — событие.
	 *
	 * @var string
	 */
	const POST_TYPE = 'event';

	/**
	 * Мета-ключ счётчика просмотров (ожидаемости).
	 *
	 * @var string
	 */
	const META_VIEWS = '_idpg_views';

	/**
	 * Мета-ключ даты начала события.
	 *
	 * @var string
	 */
	const META_START = '_idpg_start_date';

	/**
	 * Таксономия города.
	 *
	 * @var string
	 */
	const TAX_CITY = 'city';

	/**
	 * TTL кэша списка (сек).
	 *
	 * @var int
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Конструктор.
	 */
	public function __construct() {
		parent::__construct(
			self::ID_BASE,
			__( 'Самые ожидаемые события (IDPG)', 'idpg-core' ),
			array(
				'description' => __( 'Предстоящие события с наибольшим числом просмотров — самые ожидаемые.', 'idpg-core' ),
			)
		);

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// Новое/обновлённое событие — сброс кэша топа.
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Регистрация CSS виджета, только если он активен.
	 */
	public function register_assets() {
		if ( ! is_active_widget( false, false, self::ID_BASE ) ) {
			return;
		}

		wp_enqueue_style(
			'idpg-expected-events',
			IDPG_CORE_URL . 'assets/css/expected-events.css',
			array(),
			IDPG_CORE_VERSION
		);
	}

	/**
	 * Получение списка самых ожидаемых событий (с кэшем).
	 *
	 * @param int  $number  Сколько событий выбрать.
	 * @param bool $is_free Только бесплатные события.
	 * @return int[] Массив ID постов (отсортирован по ожидаемости).
	 */
	public static function get_expected_ids( $number = 4, $is_free = false ) {
		$number = max( 1, min( 10, (int) $number ) );

		$city_id = 0;
		if ( function_exists( 'idpg_get_current_city_id' ) ) {
			$city_id = (int) idpg_get_current_city_id();
		}

		$cache_key = 'idpg_expected_' . md5( $city_id . '|' . $number . '|' . ( $is_free ? 'free' : 'all' ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$today = current_time( 'Y-m-d' );

		// Именованные клаузулы meta_query — обязательны для сортировки
		// по нескольким мета-полям через orderby-массив.
		$meta_query = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'views_clause' => array(
				'key'     => self::META_VIEWS,
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			),
			'date_clause' => array(
				'key'     => self::META_START,
				'value'   => $today,
				'compare' => '>=',
				'type'    => 'DATE',
			),
		);

		if ( $is_free ) {
			$meta_query['free_clause'] = array(
				'key'     => '_idpg_is_free',
				'value'   => 'yes',
				'compare' => '=',
			);
		}

		$args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => $number,
			'meta_query'             => $meta_query,
			'orderby'                => array(
				'views_clause' => 'DESC',
				'date_clause' => 'ASC',
			),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);

		// Фильтр по текущему городу — как в выборках главной страницы.
		if ( $city_id && function_exists( 'get_term' ) && get_term( $city_id, self::TAX_CITY ) instanceof WP_Term ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy'         => self::TAX_CITY,
					'field'            => 'term_id',
					'terms'            => $city_id,
					'include_children' => true,
				),
			);
		}

		$query = new WP_Query( $args );
		$ids   = array_map( 'intval', $query->posts );

		// Fallback: у будущих событий нет просмотров — показываем ближайшие.
		if ( empty( $ids ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'views_clause' => array(
					'key'     => self::META_VIEWS,
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
				'date_clause' => array(
					'key'     => self::META_START,
					'compare' => 'EXISTS',
				),
			);
			$args['orderby']    = array(
				'date_clause' => 'ASC',
			);
			$fb    = new WP_Query( $args );
			$ids   = array_map( 'intval', $fb->posts );
		}

		set_transient( $cache_key, $ids, self::CACHE_TTL );

		return $ids;
	}

	/**
	 * Сброс кэша списка (при сохранении события, инкременте просмотров
	 * или смене настроек виджета).
	 *
	 * Ключи транзиентов включают md5(city|number|is_free), поэтому
	 * чистим по маске в БД + сбрасываем найденные ключи из
	 * объектного кэша (иначе persistent-кэш продолжит отдавать старое).
	 */
	public static function flush_cache() {
		global $wpdb;

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_idpg\_expected\_%'"
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $option_name ) {
			$key = str_replace( '_transient_', '', $option_name );
			delete_transient( $key ); // Чистит и БД, и объектный кэш.
		}
	}

	/**
	 * Фронтенд: вывод виджета.
	 *
	 * @param array $args     Аргументы сайдбара.
	 * @param array $instance Настройки экземпляра виджета.
	 */
	public function widget( $args, $instance ) {
		$title   = isset( $instance['title'] ) ? $instance['title'] : __( 'Самые ожидаемые события', 'idpg-core' );
		$number  = isset( $instance['number'] ) ? max( 1, min( 10, (int) $instance['number'] ) ) : 4;
		$is_free = ! empty( $instance['is_free'] );

		$ids = self::get_expected_ids( $number, $is_free );

		if ( empty( $ids ) ) {
			return; // Пустой виджет не выводим вовсе.
		}

		$events_query = new WP_Query( array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'publish',
			'post__in'               => $ids,
			'orderby'                => 'post__in',
			'posts_per_page'         => count( $ids ),
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
		) );

		if ( ! $events_query->have_posts() ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( '' !== $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="idpg-expected-events">
			<?php
			$index = 0;
			while ( $events_query->have_posts() ) :
				$events_query->the_post();
				$index++;

				$post_id    = get_the_ID();
				$views      = (int) get_post_meta( $post_id, self::META_VIEWS, true );
				$thumb      = IDPG_Media::idpg_get_poster( $post_id, 'event-card', array( 'class' => 'idpg-expected-events__img' ) );
				$date_start = get_post_meta( $post_id, self::META_START, true );

				// Дата в компактном виде: «12 сентября».
				// mysql2date парсит дату в контексте таймзоны сайта —
				// тот же способ, что и в idpg_core_event_date_range темы.
				$date_human = $date_start
					? mysql2date( 'j F', $date_start )
					: '';

				// Склонение «просмотров» вручную: 1 просмотр, 2 просмотра, 5 просмотров.
				$views_human = $this->format_views( $views );
				?>
				<a class="idpg-expected-events__item" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
					<?php if ( $thumb ) : ?>
						<?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php else : ?>
						<span class="idpg-expected-events__placeholder"><?php echo esc_html( mb_strtoupper( mb_substr( get_the_title(), 0, 1 ) ) ); ?></span>
					<?php endif; ?>

					<span class="idpg-expected-events__body">
						<span class="idpg-expected-events__rank" aria-hidden="true"><?php echo esc_html( (string) $index ); ?></span>

						<span class="idpg-expected-events__title"><?php the_title(); ?></span>

						<span class="idpg-expected-events__meta">
							<?php if ( $date_human ) : ?>
								<span class="idpg-expected-events__date"><?php echo esc_html( $date_human ); ?></span>
							<?php endif; ?>
							<?php if ( $views > 0 ) : ?>
								<span class="idpg-expected-events__views">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
										<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
										<circle cx="12" cy="12" r="3"></circle>
									</svg>
									<?php echo esc_html( $views_human ); ?>
								</span>
							<?php endif; ?>
						</span>
					</span>
				</a>
				<?php
			endwhile;
			wp_reset_postdata();
			?>

			<?php if ( class_exists( 'IDPG_City_URL' ) && method_exists( 'IDPG_City_URL', 'afisha_url' ) ) : ?>
				<a class="idpg-expected-events__all" href="<?php echo esc_url( add_query_arg( 'sort', 'popular', IDPG_City_URL::afisha_url() ) ); ?>">
					<?php esc_html_e( 'Все события', 'idpg-core' ); ?>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<line x1="5" y1="12" x2="19" y2="12"></line>
						<polyline points="12 5 19 12 12 19"></polyline>
					</svg>
				</a>
			<?php endif; ?>
		</div>
		<?php
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Форматирование числа просмотров с правильным склонением.
	 *
	 * @param int $views Число просмотров.
	 * @return string Например «21 просмотр», «3 просмотра», «5 просмотров».
	 */
	private function format_views( $views ) {
		if ( $views < 1 ) {
			return '';
		}

		$mod10  = $views % 10;
		$mod100 = $views % 100;

		if ( 1 === $mod10 && 11 !== $mod100 ) {
			/* translators: %d: число просмотров */
			return sprintf( __( '%d просмотр', 'idpg-core' ), $views );
		}

		if ( $mod10 >= 2 && $mod10 <= 4 && ( $mod100 < 10 || $mod100 >= 20 ) ) {
			/* translators: %d: число просмотров */
			return sprintf( __( '%d просмотра', 'idpg-core' ), $views );
		}

		/* translators: %d: число просмотров */
		return sprintf( __( '%d просмотров', 'idpg-core' ), $views );
	}

	/**
	 * Админка: форма настроек виджета.
	 *
	 * @param array $instance Текущие настройки.
	 */
	public function form( $instance ) {
		$title   = isset( $instance['title'] ) ? $instance['title'] : __( 'Самые ожидаемые события', 'idpg-core' );
		$number  = isset( $instance['number'] ) ? (int) $instance['number'] : 4;
		$is_free = isset( $instance['is_free'] ) ? (bool) $instance['is_free'] : false;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Заголовок:', 'idpg-core' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>"
			>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>">
				<?php esc_html_e( 'Количество событий:', 'idpg-core' ); ?>
			</label>
			<input
				class="tiny-text"
				id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>"
				type="number"
				min="1"
				max="10"
				value="<?php echo esc_attr( (string) $number ); ?>"
			>
		</p>
		<p>
			<input
				class="checkbox"
				id="<?php echo esc_attr( $this->get_field_id( 'is_free' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'is_free' ) ); ?>"
				type="checkbox"
				<?php checked( $is_free ); ?>
			>
			<label for="<?php echo esc_attr( $this->get_field_id( 'is_free' ) ); ?>">
				<?php esc_html_e( 'Только бесплатные события', 'idpg-core' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Админка: сохранение настроек виджета.
	 *
	 * @param array $new_instance Новые настройки.
	 * @param array $old_instance Старые настройки.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		$instance['title']   = sanitize_text_field( $new_instance['title'] ?? '' );
		$instance['number']  = max( 1, min( 10, (int) ( $new_instance['number'] ?? 4 ) ) );
		$instance['is_free'] = ! empty( $new_instance['is_free'] );

		self::flush_cache();

		return $instance;
	}
}
