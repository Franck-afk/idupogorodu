<?php
/**
 * Виджет «Календарь событий» (мини-календарь).
 *
 * Показывает календарь с днями, где есть события (CPT 'event'),
 * с навигацией по месяцам (AJAX), всплывающими подсказками/списком
 * событий на день, фильтрами по категории и городу, цветовыми схемами
 * и экспортом месяца в iCal.
 *
 * Оптимизация: структура месяца кэшируется на 24ч, события — на 1ч;
 * данные месяца получаются 2 запросами к БД (WP_Query + bulk postmeta).
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Event_Calendar_Widget
 */
class IDPG_Event_Calendar_Widget extends WP_Widget {

	/**
	 * Служебный id_base виджета.
	 *
	 * @var string
	 */
	const ID_BASE = 'idpg_event_calendar';

	/**
	 * Тип записи — событие.
	 *
	 * @var string
	 */
	const POST_TYPE = 'event';

	/**
	 * Таксономии фильтров.
	 *
	 * @var string
	 */
	const TAX_CAT = 'event_category';
	const TAX_CITY = 'city';

	/**
	 * Ключи мета дат события.
	 *
	 * @var string
	 */
	const META_START = '_idpg_start_date';
	const META_END = '_idpg_end_date';

	/**
	 * Кэш структуры месяца (сек).
	 *
	 * @var int
	 */
	const GRID_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Кэш событий месяца (сек).
	 *
	 * @var int
	 */
	const EVENTS_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Конструктор.
	 */
	public function __construct() {
		parent::__construct(
			self::ID_BASE,
			__( 'Календарь событий (IDPG)', 'idpg-core' ),
			array(
				'description' => __( 'Мини-календарь событий с навигацией и экспортом в iCal.', 'idpg-core' ),
			)
		);

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_idpg_calendar_month', array( $this, 'ajax_month' ) );
		add_action( 'wp_ajax_nopriv_idpg_calendar_month', array( $this, 'ajax_month' ) );
	}

	/**
	 * Регистрация CSS/JS виджета, только если он активен.
	 */
	public function register_assets() {
		if ( ! is_active_widget( false, false, self::ID_BASE ) ) {
			return;
		}

		wp_enqueue_style(
			'idpg-event-calendar',
			IDPG_CORE_URL . 'assets/css/event-calendar.css',
			array(),
			IDPG_CORE_VERSION
		);

		wp_enqueue_script(
			'idpg-event-calendar',
			IDPG_CORE_URL . 'assets/js/event-calendar.js',
			array(),
			IDPG_CORE_VERSION,
			true
		);

		wp_localize_script(
			'idpg-event-calendar',
			'idpgCalendar',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'idpg_calendar_nonce' ),
				'home'    => home_url( '/' ),
			)
		);
	}

	/**
	 * Режимы отображения.
	 *
	 * @return array
	 */
	public static function modes() {
		return array(
			'month' => __( 'Месяц', 'idpg-core' ),
			'week'  => __( 'Неделя', 'idpg-core' ),
			'list'  => __( 'Список', 'idpg-core' ),
		);
	}

	/**
	 * Цветовые схемы.
	 *
	 * @return array
	 */
	public static function schemes() {
		return array(
			'light' => __( 'Светлая', 'idpg-core' ),
			'dark'  => __( 'Тёмная', 'idpg-core' ),
			'brand' => __( 'Фирменная', 'idpg-core' ),
		);
	}

	/**
	 * Форма в админке.
	 *
	 * @param array $instance Текущие настройки.
	 */
	public function form( $instance ) {
		$title    = isset( $instance['title'] ) ? $instance['title'] : '';
		$mode     = isset( $instance['mode'] ) ? $instance['mode'] : 'month';
		$category = isset( $instance['category'] ) ? (int) $instance['category'] : 0;
		$city     = isset( $instance['city'] ) ? (int) $instance['city'] : 0;
		$scheme   = isset( $instance['scheme'] ) ? $instance['scheme'] : 'light';

		$categories = get_terms( array( 'taxonomy' => self::TAX_CAT, 'hide_empty' => false ) );
		$cities     = get_terms( array( 'taxonomy' => self::TAX_CITY, 'hide_empty' => false ) );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Заголовок:', 'idpg-core' ); ?>
			</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'mode' ) ); ?>">
				<?php esc_html_e( 'Режим:', 'idpg-core' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'mode' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'mode' ) ); ?>">
				<?php foreach ( self::modes() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $mode, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'category' ) ); ?>">
				<?php esc_html_e( 'Категория (фильтр):', 'idpg-core' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'category' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'category' ) ); ?>">
				<option value="0"><?php esc_html_e( 'Все категории', 'idpg-core' ); ?></option>
				<?php
				if ( ! is_wp_error( $categories ) ) {
					foreach ( $categories as $t ) {
						printf(
							'<option value="%d"%s>%s</option>',
							(int) $t->term_id,
							selected( $category, (int) $t->term_id, false ),
							esc_html( $t->name )
						);
					}
				}
				?>
			</select>
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'city' ) ); ?>">
				<?php esc_html_e( 'Город (фильтр):', 'idpg-core' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'city' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'city' ) ); ?>">
				<option value="0"><?php esc_html_e( 'Все города', 'idpg-core' ); ?></option>
				<?php
				if ( ! is_wp_error( $cities ) ) {
					foreach ( $cities as $t ) {
						printf(
							'<option value="%d"%s>%s</option>',
							(int) $t->term_id,
							selected( $city, (int) $t->term_id, false ),
							esc_html( $t->name )
						);
					}
				}
				?>
			</select>
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'scheme' ) ); ?>">
				<?php esc_html_e( 'Цветовая схема:', 'idpg-core' ); ?>
			</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'scheme' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'scheme' ) ); ?>">
				<?php foreach ( self::schemes() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $scheme, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	/**
	 * Сохранение настроек.
	 *
	 * @param array $new Новые данные.
	 * @param array $old Старые данные.
	 * @return array
	 */
	public function update( $new, $old ) {
		$instance = array();

		$instance['title'] = isset( $new['title'] ) ? sanitize_text_field( $new['title'] ) : '';

		$mode = isset( $new['mode'] ) ? sanitize_key( $new['mode'] ) : 'month';
		$instance['mode'] = array_key_exists( $mode, self::modes() ) ? $mode : 'month';

		$instance['category'] = isset( $new['category'] ) ? absint( $new['category'] ) : 0;
		$instance['city']     = isset( $new['city'] ) ? absint( $new['city'] ) : 0;

		$scheme = isset( $new['scheme'] ) ? sanitize_key( $new['scheme'] ) : 'light';
		$instance['scheme'] = array_key_exists( $scheme, self::schemes() ) ? $scheme : 'light';

		$this->purge_caches();

		return $instance;
	}

	/**
	 * Вывод виджета.
	 *
	 * @param array $args     Параметры области виджетов.
	 * @param array $instance Настройки.
	 */
	public function widget( $args, $instance ) {
		$title    = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Календарь событий', 'idpg-core' );
		$mode     = isset( $instance['mode'] ) ? $instance['mode'] : 'month';
		$category = isset( $instance['category'] ) ? (int) $instance['category'] : 0;
		$city     = isset( $instance['city'] ) ? (int) $instance['city'] : 0;
		$scheme   = isset( $instance['scheme'] ) ? $instance['scheme'] : 'light';

		// Опорный месяц: из ?calym=YYYY-MM или текущий.
		$y = (int) gmdate( 'Y', current_time( 'timestamp' ) );
		$m = (int) gmdate( 'n', current_time( 'timestamp' ) );
		if ( isset( $_GET['calym'] ) && preg_match( '/^(\d{4})-(\d{1,2})$/', sanitize_text_field( wp_unslash( $_GET['calym'] ) ), $mt ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$yy = (int) $mt[1];
			$mm = (int) $mt[2];
			if ( $yy >= 1970 && $yy <= 2100 && $mm >= 1 && $mm <= 12 ) {
				$y = $yy;
				$m = $mm;
			}
		}

		$ym = sprintf( '%04d-%02d', $y, $m );
		$scheme_class = 'is-' . ( in_array( $scheme, array( 'light', 'dark', 'brand' ), true ) ? $scheme : 'light' );

		$data = $this->get_calendar_data( $y, $m, $category, $city );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<div class="idpg-cal idpg-cal--' . esc_attr( $mode ) . ' ' . esc_attr( $scheme_class ) . '" data-idpg-cal data-calym="' . esc_attr( $ym ) . '"'
			. ' data-cat="' . esc_attr( $category ) . '" data-city="' . esc_attr( $city ) . '">';

		if ( 'week' === $mode ) {
			echo $this->render_week( $y, $m, $data ); // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'list' === $mode ) {
			echo $this->render_list( $y, $m, $data ); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo $this->render_month( $y, $m, $data, $category, $city ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '</div>';

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Получение данных месяца: сетка + события по дням.
	 *
	 * Сетка + события хранятся в одном transient (24ч — сетка стабильна,
	 * события обновляются раз в час путём сброса при сохранении события).
	 * За месяц — 2 содержательных запроса к БД (WP_Query + bulk postmeta);
	 * cache-инфраструктура (get/set transient) — 2 запроса только на холодную.
	 *
	 * @param int  $y        Год.
	 * @param int  $m        Месяц (1-12).
	 * @param int  $category ID термина категории (0 = все).
	 * @param int  $city     ID термина города (0 = все).
	 * @return array{grid:array, events:array}
	 */
	private function get_calendar_data( $y, $m, $category, $city ) {
		$ym   = sprintf( '%04d-%02d', $y, $m );
		$fkey = $category . '|' . $city;

		$transient = 'idpg_cal_' . md5( $ym . '|' . $fkey );
		$cached    = get_transient( $transient );

		if ( false !== $cached && isset( $cached['grid'] ) ) {
			// Сетка всегда валидна; события обновляем раз в час по TTL внутри.
			$age  = isset( $cached['ts'] ) ? ( time() - (int) $cached['ts'] ) : 0;
			$grid = $cached['grid'];

			if ( $age <= self::EVENTS_CACHE_TTL && isset( $cached['events'] ) ) {
				return array( 'grid' => $grid, 'events' => $cached['events'] );
			}

			// События протухли — пересобираем только их, сетку берём из кэша.
			$events = $this->query_month_events( $y, $m, $category, $city );
			$cached['events'] = $events;
			$cached['ts']     = time();
			set_transient( $transient, $cached, self::GRID_CACHE_TTL );

			return array( 'grid' => $grid, 'events' => $events );
		}

		// Холодный путь: строим и сетку, и события.
		$grid    = $this->build_month_grid( $y, $m );
		$events  = $this->query_month_events( $y, $m, $category, $city );
		$payload = array(
			'grid'   => $grid,
			'events' => $events,
			'ts'     => time(),
		);
		set_transient( $transient, $payload, self::GRID_CACHE_TTL );

		return array( 'grid' => $grid, 'events' => $events );
	}

	/**
	 * Сетка месяца (массив недель по 7 ячеек, значение = день или 0).
	 *
	 * @param int $y Год.
	 * @param int $m Месяц.
	 * @return array
	 */
	private function build_month_grid( $y, $m ) {
		$first = new DateTime( sprintf( '%04d-%02d-01', $y, $m ) );
		$days  = (int) $first->format( 't' );
		$dow   = (int) $first->format( 'N' ); // 1 = Пн … 7 = Вс (неделя с понедельника).

		$cells = array();
		$d = 1;
		$done = false;
		while ( ! $done ) {
			$week = array_fill( 0, 7, 0 );
			for ( $i = 0; $i < 7; $i++ ) {
				$cell = ( count( $cells ) * 7 + $i ) - ( $dow - 1 );
				if ( $cell >= 1 && $cell <= $days ) {
					$week[ $i ] = $cell;
					$d++;
					if ( $d > $days ) {
						$done = true;
					}
				}
			}
			$cells[] = $week;
		}

		return $cells;
	}

	/**
	 * Запрос событий месяца (2 запроса: WP_Query + bulk postmeta).
	 *
	 * @param int $y        Год.
	 * @param int $m        Месяц.
	 * @param int $category ID категории.
	 * @param int $city     ID города.
	 * @return array День (Y-m-d) => список событий.
	 */
	private function query_month_events( $y, $m, $category, $city ) {
		global $wpdb;

		$start = sprintf( '%04d-%02d-01 00:00:00', $y, $m );
		$end   = sprintf( '%04d-%02d-%02d 23:59:59', $y, $m, (int) gmdate( 't', mktime( 0, 0, 0, $m, 1, $y ) ) );

		$args = array(
			'post_type'               => self::POST_TYPE,
			'post_status'             => 'publish',
			'posts_per_page'          => -1,
			'no_found_rows'           => true,
			'cache_results'           => false,
			'update_post_meta_cache'  => false,
			'update_post_term_cache'  => false,
			'meta_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => self::META_START,
					'value'   => array( $start, $end ),
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				),
			),
		);

		if ( $category || $city ) {
			$tax = array( 'relation' => 'AND' );
			if ( $category ) {
				$tax[] = array(
					'taxonomy' => self::TAX_CAT,
					'field'    => 'term_id',
					'terms'    => $category,
				);
			}
			if ( $city ) {
				$tax[] = array(
					'taxonomy' => self::TAX_CITY,
					'field'    => 'term_id',
					'terms'    => $city,
				);
			}
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return array();
		}

		$ids = array();
		foreach ( $posts as $p ) {
			$ids[] = (int) $p->ID;
		}

		// Bulk-запрос мета дат (1 запрос вместо N).
		$meta_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ")
				   AND meta_key IN (%s, %s)",
				array_merge( $ids, array( self::META_START, self::META_END ) )
			)
		);

		$meta_map = array();
		foreach ( $meta_rows as $row ) {
			$meta_map[ (int) $row->post_id ][ $row->meta_key ] = $row->meta_value;
		}

		$by_day = array();
		foreach ( $posts as $p ) {
			$raw = isset( $meta_map[ (int) $p->ID ][ self::META_START ] ) ? $meta_map[ (int) $p->ID ][ self::META_START ] : '';

			if ( ! $raw ) {
				continue;
			}

			// Из 'Y-m-d\TH:i' берём дату дня.
			$day = preg_replace( '/[T ].*$/', '', $raw );

			if ( ! isset( $by_day[ $day ] ) ) {
				$by_day[ $day ] = array();
			}

			$by_day[ $day ][] = array(
				'id'     => (int) $p->ID,
				'title'  => $p->post_title,
				'url'    => get_permalink( $p ),
				'date'   => $raw,
			);
		}

		ksort( $by_day );

		return $by_day;
	}

	/**
	 * Рендер месяца: навигация + сетка + события по дням.
	 *
	 * @param int   $y        Год.
	 * @param int   $m        Месяц.
	 * @param array $data     Данные {grid, events}.
	 * @param int   $category Категория.
	 * @param int   $city     Город.
	 * @return string
	 */
	private function render_month( $y, $m, $data, $category, $city ) {
		$months = array(
			1  => __( 'Январь', 'idpg-core' ), 2 => __( 'Февраль', 'idpg-core' ),
			3  => __( 'Март', 'idpg-core' ), 4 => __( 'Апрель', 'idpg-core' ),
			5  => __( 'Май', 'idpg-core' ), 6 => __( 'Июнь', 'idpg-core' ),
			7  => __( 'Июль', 'idpg-core' ), 8 => __( 'Август', 'idpg-core' ),
			9  => __( 'Сентябрь', 'idpg-core' ), 10 => __( 'Октябрь', 'idpg-core' ),
			11 => __( 'Ноябрь', 'idpg-core' ), 12 => __( 'Декабрь', 'idpg-core' ),
		);
		$dow_names = array(
			__( 'Пн', 'idpg-core' ), __( 'Вт', 'idpg-core' ), __( 'Ср', 'idpg-core' ),
			__( 'Чт', 'idpg-core' ), __( 'Пт', 'idpg-core' ), __( 'Сб', 'idpg-core' ),
			__( 'Вс', 'idpg-core' ),
		);

		$prev_m = $m - 1; $prev_y = $y; if ( 0 === $prev_m ) { $prev_m = 12; $prev_y--; }
		$next_m = $m + 1; $next_y = $y; if ( 13 === $next_m ) { $next_m = 1; $next_y++; }

		$today = gmdate( 'Y-m-d', current_time( 'timestamp' ) );
		$ym    = sprintf( '%04d-%02d', $y, $m );

		$html  = '<div class="idpg-cal__nav">';
		$html .= '<button type="button" class="idpg-cal__nav-btn" data-cal-prev aria-label="' . esc_attr__( 'Предыдущий месяц', 'idpg-core' ) . '">&#8249;</button>';
		$html .= '<div class="idpg-cal__title">' . esc_html( $months[ $m ] . ' ' . $y ) . '</div>';
		$html .= '<button type="button" class="idpg-cal__nav-btn" data-cal-next aria-label="' . esc_attr__( 'Следующий месяц', 'idpg-core' ) . '">&#8250;</button>';
		$html .= '</div>';

		$html .= '<div class="idpg-cal__grid" data-cal-grid>';
		$html .= '<div class="idpg-cal__dow">';
		foreach ( $dow_names as $dn ) {
			$html .= '<span class="idpg-cal__dow-cell">' . esc_html( $dn ) . '</span>';
		}
		$html .= '</div>';

		foreach ( $data['grid'] as $week ) {
			$html .= '<div class="idpg-cal__week">';
			foreach ( $week as $day ) {
				if ( 0 === $day ) {
					$html .= '<span class="idpg-cal__cell is-empty"></span>';
					continue;
				}
				$ymd = sprintf( '%04d-%02d-%02d', $y, $m, $day );
				$ev  = isset( $data['events'][ $ymd ] ) ? $data['events'][ $ymd ] : array();
				$html .= $this->day_cell( $day, $ymd, $ev, $today );
			}
			$html .= '</div>';
		}
		$html .= '</div>';

		$html .= '<div class="idpg-cal__detail" data-cal-detail aria-live="polite"></div>';

		return $html;
	}

	/**
	 * Ячейка дня.
	 *
	 * @param int    $day   Число месяца.
	 * @param string $ymd   Дата Y-m-d.
	 * @param array  $ev    События дня.
	 * @param string $today Текущая дата.
	 * @return string
	 */
	private function day_cell( $day, $ymd, $ev, $today ) {
		$count = count( $ev );
		$cls   = 'idpg-cal__cell';

		if ( $ymd === $today ) {
			$cls .= ' is-today';
		}
		if ( $count ) {
			$cls .= ' has-events';
		}

		$labels = array();
		foreach ( $ev as $e ) {
			$labels[] = $e['title'];
		}
		$tooltip = $count ? implode( '; ', $labels ) : '';

		$html = '<div class="' . esc_attr( $cls ) . '" data-day="' . esc_attr( $ymd ) . '"'
			. ( $tooltip ? ' title="' . esc_attr( $tooltip ) . '"' : '' ) . '>';

		if ( $count ) {
			// Клик по дню → афиша на эту дату.
			$afisha = add_query_arg( 'date', $ymd, home_url( '/afisha/' ) );
			$html .= '<a class="idpg-cal__day-link" href="' . esc_url( $afisha ) . '">' . esc_html( $day ) . '</a>';
		} else {
			$html .= '<span class="idpg-cal__day-num">' . esc_html( $day ) . '</span>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Рендер недели (полоса из первой недели месяца).
	 *
	 * @param int   $y    Год.
	 * @param int   $m    Месяц.
	 * @param array $data Данные.
	 * @return string
	 */
	private function render_week( $y, $m, $data ) {
		$today = gmdate( 'Y-m-d', current_time( 'timestamp' ) );
		$week  = isset( $data['grid'][0] ) ? $data['grid'][0] : array_fill( 0, 7, 0 );

		$html = '<div class="idpg-cal__weekgrid" data-cal-grid>';
		foreach ( $week as $day ) {
			if ( 0 === $day ) {
				$html .= '<span class="idpg-cal__cell is-empty"></span>';
				continue;
			}
			$ymd = sprintf( '%04d-%02d-%02d', $y, $m, $day );
			$ev  = isset( $data['events'][ $ymd ] ) ? $data['events'][ $ymd ] : array();
			$html .= $this->day_cell( $day, $ymd, $ev, $today );
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Рендер списка событий месяца.
	 *
	 * @param int   $y    Год.
	 * @param int   $m    Месяц.
	 * @param array $data Данные.
	 * @return string
	 */
	private function render_list( $y, $m, $data ) {
		if ( empty( $data['events'] ) ) {
			return '<p class="idpg-cal__none">' . esc_html__( 'В этом месяце событий нет.', 'idpg-core' ) . '</p>';
		}

		$html = '<ul class="idpg-cal__list" data-cal-grid>';
		foreach ( $data['events'] as $day => $evs ) {
			$label = gmdate( 'd.m.Y', strtotime( $day ) );
			foreach ( $evs as $e ) {
				$html .= '<li class="idpg-cal__list-item">';
				$html .= '<span class="idpg-cal__list-date">' . esc_html( $label ) . '</span>';
				$html .= '<a class="idpg-cal__list-link" href="' . esc_url( $e['url'] ) . '">' . esc_html( $e['title'] ) . '</a>';
				$html .= '</li>';
			}
		}
		$html .= '</ul>';

		return $html;
	}

	/**
	 * AJAX: подгрузка месяца.
	 */
	public function ajax_month() {
		check_ajax_referer( 'idpg_calendar_nonce', 'nonce' );

		$ym  = isset( $_POST['ym'] ) ? sanitize_text_field( wp_unslash( $_POST['ym'] ) ) : '';
		$cat = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0;
		$city = isset( $_POST['city'] ) ? absint( $_POST['city'] ) : 0;

		if ( ! preg_match( '/^(\d{4})-(\d{1,2})$/', $ym, $mt ) ) {
			wp_send_json_error( array( 'message' => 'Bad ym' ) );
		}

		$y = (int) $mt[1];
		$m = (int) $mt[2];

		$data = $this->get_calendar_data( $y, $m, $cat, $city );
		$html = $this->render_month( $y, $m, $data, $cat, $city );

		wp_send_json_success( array(
			'html' => $html,
			'ym'   => sprintf( '%04d-%02d', $y, $m ),
		) );
	}

	/**
	 * Очистка кэшей календаря (по текущему и соседним месяцам).
	 */
	private function purge_caches() {
		$y = (int) gmdate( 'Y', current_time( 'timestamp' ) );
		$m = (int) gmdate( 'n', current_time( 'timestamp' ) );

		for ( $i = -2; $i <= 2; $i++ ) {
			$t  = mktime( 0, 0, 0, $m + $i, 1, $y );
			$ym = gmdate( 'Y-m', $t );
			// Сбрасываем все варианты фильтров для этого месяца (cat/city = 0..N).
			delete_transient( 'idpg_cal_' . md5( $ym . '|0|0' ) );
		}
	}
}

/**
 * Сброс кэша календаря при сохранении/удалении события.
 *
 * @param int $post_id ID события.
 */
function idpg_core_calendar_flush_on_save( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'event' !== get_post_type( $post_id ) ) {
		return;
	}
	$y = (int) gmdate( 'Y', current_time( 'timestamp' ) );
	$m = (int) gmdate( 'n', current_time( 'timestamp' ) );
	for ( $i = -2; $i <= 2; $i++ ) {
		$t  = mktime( 0, 0, 0, $m + $i, 1, $y );
		$ym = gmdate( 'Y-m', $t );
		delete_transient( 'idpg_cal_' . md5( $ym . '|0|0' ) );
	}
}
add_action( 'save_post_event', 'idpg_core_calendar_flush_on_save' );
add_action( 'delete_post_event', 'idpg_core_calendar_flush_on_save' );
