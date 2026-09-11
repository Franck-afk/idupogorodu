<?php
/**
 * Статистика на главной странице админки (dashboard).
 *
 * - Администратор видит полную статистику проекта:
 *   события (по статусам), площадки, подписчики, пользователи/организаторы,
 *   просмотры и клики, избранное, города, ближайшие события, топ организаторов.
 * - Организатор (event_organizer) видит свою статистику:
 *   только его события, площадки, просмотры/клики и избранное по его событиям.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Dashboard_Stats
 *
 * Регистрирует виджеты статистики на dashboard-экране и рендерит их.
 */
class IDPG_Dashboard_Stats {

	/**
	 * Идентификатор виджета статистики администратора.
	 *
	 * @var string
	 */
	const WIDGET_ADMIN = 'idpg_dashboard_stats_admin';

	/**
	 * Идентификатор виджета статистики организатора.
	 *
	 * @var string
	 */
	const WIDGET_ORGANIZER = 'idpg_dashboard_stats_organizer';

	/**
	 * Мета-ключ даты начала события.
	 *
	 * @var string
	 */
	const META_DATE_START = '_idpg_start_date';

	/**
	 * Slug страницы статистики (подпункт меню «Главная»).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'idpg-stats';

	/**
	 * Экземпляр синглтона.
	 *
	 * @var IDPG_Dashboard_Stats|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Dashboard_Stats
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
		add_action( 'wp_dashboard_setup', array( $this, 'register_widgets' ) );
		add_action( 'admin_menu', array( $this, 'register_stats_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Регистрация виджетов статистики на dashboard.
	 *
	 * Администратору — виджет всей статистики проекта, организатору —
	 * виджет его собственной статистики. Остальные роли не получают виджет.
	 *
	 * @return void
	 */
	public function register_widgets() {
		// Администратор — полная статистика проекта.
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget(
				self::WIDGET_ADMIN,
				__( 'Статистика проекта', 'idpg-core' ),
				array( $this, 'render_admin_stats' )
			);

			return;
		}

		// Организатор — только его собственная статистика.
		$user = wp_get_current_user();

		if ( in_array( IDPG_Roles::ROLE_SLUG, (array) $user->roles, true ) && current_user_can( 'edit_events' ) ) {
			wp_add_dashboard_widget(
				self::WIDGET_ORGANIZER,
				__( 'Моя статистика', 'idpg-core' ),
				array( $this, 'render_organizer_stats' )
			);
		}
	}

	/**
	 * Подключение стилей виджета только на экране dashboard.
	 *
	 * @param string $hook Hook текущего экрана админки.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'index.php' !== $hook && 'dashboard_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'idpg-dashboard-stats',
			IDPG_CORE_URL . 'assets/css/dashboard-stats.css',
			array(),
			IDPG_CORE_VERSION
		);

		// Инлайн-стили как гарант — обходят любой кэш (браузер/CDN/opcache).
		wp_add_inline_style( 'idpg-dashboard-stats', $this->get_inline_css() );
	}

	/**
	 * Критические стили виджета (инлайн).
	 *
	 * Дублирует карточный стиль списков и таблиц, чтобы гарантированно
	 * применяться даже при агрессивном кэшировании внешнего CSS.
	 *
	 * @return string CSS-блок.
	 */
	private function get_inline_css() {
		return '
			.idpg-dash-list{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px}
			.idpg-dash-list li{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:#f8fafb;border:1px solid #e4e7ea;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
			.idpg-dash-list li:hover{border-color:#0073aa;box-shadow:0 2px 6px rgba(0,115,170,.12)}
			.idpg-dash-list__title{color:#1d2327;text-decoration:none;font-weight:500;overflow-wrap:anywhere}
			.idpg-dash-list__title:hover{color:#0073aa;text-decoration:underline}
			.idpg-dash-list__date{flex:none;font-size:12px;color:#646970;font-variant-numeric:tabular-nums;white-space:nowrap}
			.idpg-dash-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin-bottom:16px}
			.idpg-dash-tiles__item{background:#f8fafb;border:1px solid #e4e7ea;border-radius:8px;padding:12px 14px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
			.idpg-dash-tiles__item--accent{background:#0073aa;border-color:#0073aa}
			.idpg-dash-tiles__item--accent .idpg-dash-tiles__value{color:#fff}
			.idpg-dash-tiles__item--accent .idpg-dash-tiles__label{color:rgba(255,255,255,.85)}
			.idpg-dash-tiles__value{font-size:20px;font-weight:700;line-height:1.2;color:#1d2327}
			.idpg-dash-tiles__label{margin-top:4px;font-size:11.5px;line-height:1.35;color:#646970}
			.idpg-dash-section{border-top:1px solid #e4e7ea;padding-top:12px;margin-top:4px}
			.idpg-dash-section__title{margin:0 0 10px;font-size:13px;font-weight:600;color:#1d2327}
			.idpg-dash-section__empty{margin:0;font-size:12.5px;color:#646970;font-style:italic}
			.idpg-dash-table{width:100%;border-collapse:separate;border-spacing:0 8px;font-size:12.5px}
			.idpg-dash-table th{text-align:left;padding:4px 14px;font-size:11.5px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.02em}
			.idpg-dash-table td{padding:10px 14px;background:#f8fafb;border:1px solid #e4e7ea;color:#1d2327}
			.idpg-dash-table td:first-child{border-right:none;border-radius:8px 0 0 8px;font-weight:500}
			.idpg-dash-table td:last-child{border-left:none;border-radius:0 8px 8px 0;text-align:right;font-variant-numeric:tabular-nums}
			.idpg-dash-table tbody tr:hover td{border-color:#0073aa}
			.idpg-stats-page{max-width:1080px}
			.idpg-stats-page>h1{font-size:23px;font-weight:700;margin:0 0 24px;padding:0;border:none;color:#1d2327;letter-spacing:-.02em}
			.idpg-stats-page .idpg-dash-tiles{grid-template-columns:repeat(auto-fill,minmax(160px,1fr))}
			.idpg-stats-page .idpg-dash-section{padding:16px 0 8px;margin-top:8px}
			.idpg-stats-page .idpg-dash-section__title{font-size:15px}';
	}

	/**
	 * Регистрация подпункта «Статистика» в меню администратора.
	 *
	 * Пункт добавляется как подпункт «Главной» (index.php). Доступен
	 * администратору (полная статистика) и организатору (своя статистика).
	 *
	 * @return void
	 */
	public function register_stats_menu() {
		if ( current_user_can( 'manage_options' ) ) {
			$capability  = 'manage_options';
			$menu_label  = __( 'Статистика', 'idpg-core' );
		} elseif ( in_array( IDPG_Roles::ROLE_SLUG, (array) wp_get_current_user()->roles, true )
			&& current_user_can( 'edit_events' ) ) {
			$capability  = 'edit_events';
			$menu_label  = __( 'Моя статистика', 'idpg-core' );
		} else {
			return;
		}

		add_submenu_page(
			'index.php',
			$menu_label,
			$menu_label,
			$capability,
			self::PAGE_SLUG,
			array( $this, 'render_stats_page' )
		);
	}

	/**
	 * Рендер страницы статистики.
	 *
	 * Полная статистика — для администратора, собственная — для организатора.
	 *
	 * @return void
	 */
	public function render_stats_page() {
		$is_organizer = in_array( IDPG_Roles::ROLE_SLUG, (array) wp_get_current_user()->roles, true )
			&& current_user_can( 'edit_events' );

		$page_title = $is_organizer
			? __( 'Моя статистика', 'idpg-core' )
			: __( 'Статистика проекта', 'idpg-core' );

		echo '<div class="wrap idpg-stats-page">';
		echo '<h1>' . esc_html( $page_title ) . '</h1>';

		if ( current_user_can( 'manage_options' ) ) {
			$this->render_admin_stats();
		} elseif ( $is_organizer ) {
			$this->render_organizer_stats();
		}

		echo '</div>';
	}

	/**
	 * Рендер виджета статистики администратора.
	 *
	 * @return void
	 */
	public function render_admin_stats() {
		$tiles = array(
			array(
				'label' => __( 'Событий всего', 'idpg-core' ),
				'value' => $this->count_events_total(),
				'accent' => true,
			),
			array(
				'label' => __( 'Опубликовано', 'idpg-core' ),
				'value' => $this->count_posts( 'event', 'publish' ),
			),
			array(
				'label' => __( 'Черновики', 'idpg-core' ),
				'value' => $this->count_posts( 'event', 'draft' ),
			),
			array(
				'label' => __( 'На модерации', 'idpg-core' ),
				'value' => $this->count_posts( 'event', 'pending' ),
			),
			array(
				'label' => __( 'Ближайшие', 'idpg-core' ),
				'value' => $this->count_events_by_date_range( '>=' ),
			),
			array(
				'label' => __( 'Прошедшие', 'idpg-core' ),
				'value' => $this->count_events_by_date_range( '<' ),
			),
			array(
				'label' => __( 'Площадки', 'idpg-core' ),
				'value' => $this->count_posts( 'venue', 'publish' ),
			),
			array(
				'label' => __( 'Подписчики', 'idpg-core' ),
				'value' => $this->count_subscribers(),
			),
		);

		$users          = $this->count_users_by_roles();
		$tiles[]        = array( 'label' => __( 'Пользователи', 'idpg-core' ), 'value' => $users['total'], 'accent' => true );
		$tiles[]        = array( 'label' => __( 'Организаторы', 'idpg-core' ), 'value' => $users['organizers'] );
		$tiles[]        = array( 'label' => __( 'Просмотры', 'idpg-core' ), 'value' => $this->sum_meta( IDPG_Views::META_KEY ) );
		$tiles[]        = array( 'label' => __( 'Клики', 'idpg-core' ), 'value' => $this->sum_meta( IDPG_Views::META_CLICKS ) );
		$tiles[]        = array( 'label' => __( 'В избранном (польз.)', 'idpg-core' ), 'value' => $this->count_favorite_users() );
		$tiles[]        = array( 'label' => __( 'Городов', 'idpg-core' ), 'value' => $this->count_cities() );

		$this->render_tiles( $tiles );

		// Ближайшие события.
		$upcoming = $this->get_upcoming_events( 0, 5 );
		$this->render_upcoming_section( $upcoming );

		// Топ организаторов по количеству опубликованных событий.
		$this->render_top_organizers();
	}

	/**
	 * Рендер виджета статистики организатора.
	 *
	 * Все подсчёты ограничены post_author текущего пользователя.
	 *
	 * @return void
	 */
	public function render_organizer_stats() {
		$user_id = get_current_user_id();

		$tiles = array(
			array(
				'label' => __( 'Моих событий всего', 'idpg-core' ),
				'value' => $this->count_events_total( $user_id ),
				'accent' => true,
			),
			array(
				'label' => __( 'Опубликовано', 'idpg-core' ),
				'value' => $this->count_posts( 'event', 'publish', $user_id ),
			),
			array(
				'label' => __( 'Черновики', 'idpg-core' ),
				'value' => $this->count_posts( 'event', 'draft', $user_id ),
			),
			array(
				'label' => __( 'На модерации', 'idpg-core' ),
				'value' => $this->count_posts( 'event', 'pending', $user_id ),
			),
			array(
				'label' => __( 'Ближайшие', 'idpg-core' ),
				'value' => $this->count_events_by_date_range( '>=', $user_id ),
			),
			array(
				'label' => __( 'Прошедшие', 'idpg-core' ),
				'value' => $this->count_events_by_date_range( '<', $user_id ),
			),
			array(
				'label' => __( 'Мои площадки', 'idpg-core' ),
				'value' => $this->count_posts( 'venue', 'publish', $user_id ),
			),
			array(
				'label' => __( 'Просмотры', 'idpg-core' ),
				'value' => $this->sum_meta( IDPG_Views::META_KEY, $user_id ),
			),
			array(
				'label' => __( 'Клики', 'idpg-core' ),
				'value' => $this->sum_meta( IDPG_Views::META_CLICKS, $user_id ),
			),
			array(
				'label' => __( 'Меня в избранном (польз.)', 'idpg-core' ),
				'value' => $this->count_favorite_users_for_author( $user_id ),
			),
		);

		$this->render_tiles( $tiles );

		// Мои ближайшие события.
		$upcoming = $this->get_upcoming_events( $user_id, 5 );
		$this->render_upcoming_section( $upcoming );

		// Самые просматриваемые из моих событий.
		$top_views = $this->get_top_viewed_events( $user_id, 5 );
		$this->render_top_viewed_section( $top_views );
	}

	/**
	 * Вывод плиток статистики.
	 *
	 * @param array $tiles Массив карточек: label, value, (accent).
	 * @return void
	 */
	private function render_tiles( array $tiles ) {
		echo '<div class="idpg-dash-tiles">';

		foreach ( $tiles as $tile ) {
			$accent = ! empty( $tile['accent'] ) ? ' idpg-dash-tiles__item--accent' : '';
			$value  = isset( $tile['value'] ) ? (int) $tile['value'] : 0;

			echo '<div class="idpg-dash-tiles__item' . esc_attr( $accent ) . '">';
			echo '<div class="idpg-dash-tiles__value">' . esc_html( number_format_i18n( $value ) ) . '</div>';
			echo '<div class="idpg-dash-tiles__label">' . esc_html( $tile['label'] ) . '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Вывод секции «Ближайшие события».
	 *
	 * @param WP_Post[] $events Массив событий.
	 * @return void
	 */
	private function render_upcoming_section( array $events ) {
		echo '<div class="idpg-dash-section">';
		echo '<h4 class="idpg-dash-section__title">' . esc_html__( 'Ближайшие события', 'idpg-core' ) . '</h4>';

		if ( empty( $events ) ) {
			echo '<p class="idpg-dash-section__empty">' . esc_html__( 'Нет оплаченных ближайших событий.', 'idpg-core' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<ul class="idpg-dash-list">';

		foreach ( $events as $event ) {
			$date = get_post_meta( $event->ID, self::META_DATE_START, true );
			$date = $date ? date_i18n( 'd.m.Y', strtotime( $date ) ) : '';

			echo '<li>';
			echo '<a class="idpg-dash-list__title" href="' . esc_url( get_edit_post_link( $event->ID ) ) . '">' . esc_html( $event->post_title ) . '</a>';

			if ( $date ) {
				echo '<span class="idpg-dash-list__date">' . esc_html( $date ) . '</span>';
			}

			echo '</li>';
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Вывод секции «Топ просматриваемых событий» для организатора.
	 *
	 * @param WP_Post[] $events Массив событий.
	 * @return void
	 */
	private function render_top_viewed_section( array $events ) {
		echo '<div class="idpg-dash-section">';
		echo '<h4 class="idpg-dash-section__title">' . esc_html__( 'Самые просматриваемые события', 'idpg-core' ) . '</h4>';

		if ( empty( $events ) ) {
			echo '<p class="idpg-dash-section__empty">' . esc_html__( 'Нет опубликованных событий с просмотрами.', 'idpg-core' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<ul class="idpg-dash-list">';

		foreach ( $events as $event ) {
			$views = get_post_meta( $event->ID, IDPG_Views::META_KEY, true );

			echo '<li>';
			echo '<a class="idpg-dash-list__title" href="' . esc_url( get_edit_post_link( $event->ID ) ) . '">' . esc_html( $event->post_title ) . '</a>';
			echo '<span class="idpg-dash-list__date">' . esc_html( sprintf( _n( '%s просмотр', '%s просмотров', absint( $views ), 'idpg-core' ), number_format_i18n( (int) $views ) ) ) . '</span>';
			echo '</li>';
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Вывод секции «Топ организаторов» для администратора.
	 *
	 * @return void
	 */
	private function render_top_organizers() {
		$rows = $this->get_top_organizers( 5 );

		echo '<div class="idpg-dash-section">';
		echo '<h4 class="idpg-dash-section__title">' . esc_html__( 'Топ организаторов', 'idpg-core' ) . '</h4>';

		if ( empty( $rows ) ) {
			echo '<p class="idpg-dash-section__empty">' . esc_html__( 'Нет опубликованных событий.', 'idpg-core' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<table class="idpg-dash-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Организатор', 'idpg-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Событий', 'idpg-core' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row->author_id );
			$name = $user ? $user->display_name : '#' . (int) $row->author_id;

			echo '<tr>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row->events_count ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Подсчёт событий по статусу с возможным ограничением автором.
	 *
	 * @param string $post_type Тип записи (event, venue).
	 * @param string $status    Статус записи (publish, draft, pending).
	 * @param int    $author_id ID автора (0 — все пользователи).
	 * @return int
	 */
	private function count_posts( $post_type, $status, $author_id = 0 ) {
		global $wpdb;

		$sql  = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s";
		$args = array( $post_type, $status );

		if ( $author_id > 0 ) {
			$sql   .= ' AND post_author = %d';
			$args[] = $author_id;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Подсчёт событий без учёта корзины с возможным ограничением автором.
	 *
	 * @param int $author_id ID автора (0 — все пользователи).
	 * @return int
	 */
	private function count_events_total( $author_id = 0 ) {
		$statuses = array( 'publish', 'pending', 'draft', 'future' );
		$total    = 0;

		foreach ( $statuses as $status ) {
			$total += $this->count_posts( 'event', $status, $author_id );
		}

		return $total;
	}

	/**
	 * Подсчёт опубликованных событий по дате начала (строковое сравнение Y-m-d).
	 *
	 * @param string $compare   Оператор сравнения (>= или <).
	 * @param int    $author_id ID автора (0 — все пользователи).
	 * @return int
	 */
	private function count_events_by_date_range( $compare, $author_id = 0 ) {
		if ( ! in_array( $compare, array( '>=', '<' ), true ) ) {
			return 0;
		}

		global $wpdb;

		$today = current_time( 'Y-m-d' );

		$sql  = "SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = %s AND p.post_status = %s
			AND pm.meta_key = %s AND pm.meta_value {$compare} %s";
		$args = array( 'event', 'publish', self::META_DATE_START, $today );

		if ( $author_id > 0 ) {
			$sql   .= ' AND p.post_author = %d';
			$args[] = $author_id;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Сумма числового мета-поля по опубликованным событиям.
	 *
	 * Используется для просмотров (_idpg_views) и кликов (_idpg_clicks).
	 *
	 * @param string $meta_key  Мета-ключ.
	 * @param int    $author_id ID автора (0 — все пользователи).
	 * @return int
	 */
	private function sum_meta( $meta_key, $author_id = 0 ) {
		global $wpdb;

		$sql  = "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)), 0)
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = %s";
		$args = array( $meta_key, 'event', 'publish' );

		if ( $author_id > 0 ) {
			$sql   .= ' AND p.post_author = %d';
			$args[] = $author_id;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Количество подписчиков дайджеста из таблицы idpg_subscribers.
	 *
	 * @return int
	 */
	private function count_subscribers() {
		global $wpdb;

		$table = $wpdb->prefix . 'idpg_subscribers';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $table !== $exists ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}" ) );
	}

	/**
	 * Общее количество пользователей и доля организаторов.
	 *
	 * @return array Массив с ключами total и organizers.
	 */
	private function count_users_by_roles() {
		$counts = count_users();
		$total  = isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0;
		$orgs   = 0;

		if ( isset( $counts['avail_roles'][ IDPG_Roles::ROLE_SLUG ] ) ) {
			$orgs = (int) $counts['avail_roles'][ IDPG_Roles::ROLE_SLUG ];
		}

		return array(
			'total'      => $total,
			'organizers' => $orgs,
		);
	}

	/**
	 * Количество пользователей, у которых есть хотя бы одно избранное событие.
	 *
	 * @return int
	 */
	private function count_favorite_users() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id)
				FROM {$wpdb->usermeta}
				WHERE meta_key = %s AND meta_value <> '' AND meta_value <> 'a:0:{}'",
				IDPG_Favorites::META_KEY
			)
		);
	}

	/**
	 * Количество пользователей, добавивших в избранное хотя бы одно
	 * событие конкретного организатора.
	 *
	 * Выборка пользователей ограничена первыми 500 записями, чтобы
	 * не перегружать дашборд на больших инсталляциях.
	 *
	 * @param int $author_id ID организатора.
	 * @return int
	 */
	private function count_favorite_users_for_author( $author_id ) {
		if ( $author_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// ID всех событий организатора.
		$event_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d",
				array( 'event', $author_id )
			)
		);

		if ( empty( $event_ids ) ) {
			return 0;
		}

		// Пользователи с непустым списком избранного (с ограничением выборки).
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id
				FROM {$wpdb->usermeta}
				WHERE meta_key = %s AND meta_value <> '' AND meta_value <> 'a:0:{}'
				LIMIT 500",
				IDPG_Favorites::META_KEY
			)
		);

		if ( empty( $user_ids ) ) {
			return 0;
		}

		$event_ids = array_map( 'absint', $event_ids );
		$count     = 0;

		foreach ( $user_ids as $user_id ) {
			$favorites = get_user_meta( (int) $user_id, IDPG_Favorites::META_KEY, true );

			if ( ! is_array( $favorites ) ) {
				continue;
			}

			$favorites = array_map( 'absint', $favorites );

			if ( ! empty( array_intersect( $event_ids, $favorites ) ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Количество терминов таксономии city.
	 *
	 * @return int
	 */
	private function count_cities() {
		$count = wp_count_terms(
			array(
				'taxonomy'   => 'city',
				'hide_empty' => false,
			)
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Ближайшие опубликованные события.
	 *
	 * @param int $author_id ID автора (0 — все пользователи).
	 * @param int $limit     Лимит записей.
	 * @return WP_Post[]
	 */
	private function get_upcoming_events( $author_id = 0, $limit = 5 ) {
		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'no_found_rows'  => true,
			'meta_key'       => self::META_DATE_START, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_type'      => 'DATE',
			'meta_query'     => array(
				array(
					'key'     => self::META_DATE_START,
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
			),
		);

		if ( $author_id > 0 ) {
			$args['author'] = $author_id;
		}

		return get_posts( $args );
	}

	/**
	 * Опубликованные события с максимальным числом просмотров.
	 *
	 * @param int $author_id ID автора (0 — все пользователи).
	 * @param int $limit     Лимит записей.
	 * @return WP_Post[]
	 */
	private function get_top_viewed_events( $author_id = 0, $limit = 5 ) {
		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'no_found_rows'  => true,
			'meta_key'       => IDPG_Views::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
		);

		if ( $author_id > 0 ) {
			$args['author'] = $author_id;
		}

		return get_posts( $args );
	}

	/**
	 * Топ организаторов по количеству опубликованных событий.
	 *
	 * @param int $limit Лимит записей.
	 * @return object[] Строки с автор_id и events_count.
	 */
	private function get_top_organizers( $limit = 5 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_author AS author_id, COUNT(*) AS events_count
				FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND p.post_status = %s
				GROUP BY p.post_author
				ORDER BY events_count DESC
				LIMIT %d",
				array( 'event', 'publish', (int) $limit )
			)
		);
	}
}