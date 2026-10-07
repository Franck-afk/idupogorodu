<?php
/**
 * Счётчик просмотров событий.
 *
 * Инкрементирует мета-поле _idpg_views при каждом
 * уникальном просмотре (с защитой от ботов и повторных заходов).
 * Также хранит счётчик кликов _idpg_clicks.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Views
 *
 * Считает просмотры событий на фронтенде.
 */
class IDPG_Views {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Views|null
	 */
	private static $instance = null;

	/**
	 * Мета-ключ для хранения счётчика.
	 *
	 * @var string
	 */
	const META_KEY = '_idpg_views';

	/**
	 * Мета-ключ для хранения счётчика кликов.
	 *
	 * @var string
	 */
	const META_CLICKS = '_idpg_clicks';

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Views
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
		add_action( 'template_redirect', array( $this, 'track_view' ) );

		// Добавление колонок в админку.
		add_filter( 'manage_event_posts_columns', array( $this, 'add_views_column' ) );
		add_action( 'manage_event_posts_custom_column', array( $this, 'render_views_column' ), 10, 2 );

		// Учёт кликов по билетным ссылкам (DEV-17).
		add_action( 'wp_enqueue_scripts', array( $this, 'register_click_assets' ) );
	}

	/**
	 * Подключение скрипта учёта кликов на страницах событий.
	 */
	public function register_click_assets() {
		if ( is_admin() ) {
			return;
		}

		$js_path = IDPG_CORE_DIR . 'assets/js/ticket-clicks.js';

		if ( ! file_exists( $js_path ) ) {
			return;
		}

		wp_enqueue_script(
			'idpg-ticket-clicks',
			IDPG_CORE_URL . 'assets/js/ticket-clicks.js',
			array(),
			(string) filemtime( $js_path ),
			true
		);

		wp_localize_script(
			'idpg-ticket-clicks',
			'idpgTicketClicks',
			array(
				'restUrl'   => esc_url_raw( rest_url( IDPG_REST_API::NAMESPACE ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Инкремент счётчика при просмотре события.
	 *
	 * Условия:
	 * - Только наingular для CPT event.
	 * - Не для ботов (проверка UA).
	 * - Не чаще раза в 30 минут на IP (через transient).
	 * - Не в админке.
	 */
	public function track_view() {
		if ( is_admin() ) {
			return;
		}

		if ( ! is_singular( 'event' ) ) {
			return;
		}

		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return;
		}

		if ( $this->is_bot() ) {
			return;
		}

		if ( $this->is_recent_view( $post_id ) ) {
			return;
		}

		$this->increment_views( $post_id );
		$this->mark_viewed( $post_id );
	}

	/**
	 * Проверка, является ли посетитель ботом.
	 *
	 * @return bool True, если User-Agent похож на бота.
	 */
	private function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		if ( empty( $ua ) ) {
			return true;
		}

		$bot_patterns = array(
			'bot', 'crawler', 'spider', 'curl', 'wget', 'python',
			'gohttp', 'headless', 'phantom', 'semrush', 'ahrefs',
			'mj12bot', 'dotbot', 'bingpreview', 'yandex',
		);

		$ua_lower = strtolower( $ua );

		foreach ( $bot_patterns as $pattern ) {
			if ( false !== strpos( $ua_lower, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Просматривал ли данный посетитель событие за последние 30 минут.
	 *
	 * Использует transient + IP-адрес.
	 *
	 * @param int $post_id ID события.
	 * @return bool True, если просмотр был недавно.
	 */
	private function is_recent_view( $post_id ) {
		$ip     = $this->get_client_ip();
		$key    = 'idpg_viewed_' . $post_id . '_' . md5( $ip );
		$cached = get_transient( $key );

		return false !== $cached;
	}

	/**
	 * Пометка просмотра (set transient на 30 минут).
	 *
	 * @param int $post_id ID события.
	 */
	private function mark_viewed( $post_id ) {
		$ip  = $this->get_client_ip();
		$key = 'idpg_viewed_' . $post_id . '_' . md5( $ip );

		set_transient( $key, '1', 30 * MINUTE_IN_SECONDS );
	}

	/**
	 * Инкремент счётчика просмотров.
	 *
	 * @param int $post_id ID события.
	 */
	private function increment_views( $post_id ) {
		$current = (int) get_post_meta( $post_id, self::META_KEY, true );

		update_post_meta( $post_id, self::META_KEY, $current + 1 );

		// Сброс кэша template helper.
		wp_cache_delete( IDPG_Template_Helper::CACHE_PREFIX . 'meta_' . $post_id, 'idpg' );
	}

	/**
	 * Инкремент счётчика кликов по билетной ссылке.
	 *
	 * @param int $post_id ID события.
	 * @return int Новое значение счётчика.
	 */
	public static function increment_clicks( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || 'event' !== get_post_type( $post_id ) ) {
			return 0;
		}

		$clicks = (int) get_post_meta( $post_id, self::META_CLICKS, true ) + 1;

		update_post_meta( $post_id, self::META_CLICKS, $clicks );

		return $clicks;
	}

	/**
	 * Получение количества кликов.
	 *
	 * @param int $post_id ID события.
	 * @return int Количество кликов.
	 */
	public static function get_clicks( $post_id ) {
		return (int) get_post_meta( $post_id, self::META_CLICKS, true );
	}

	/**
	 * Получение IP-адреса клиента.
	 *
	 * DEV-22: реализация перенесена в IDPG_Helpers. Прежняя локальная копия
	 * брала HTTP_CLIENT_IP без проверки — этот заголовок подделывается
	 * клиентом, поэтому счётчик просмотров можно было обнулять одним
	 * запросом с произвольным значением. Теперь используется единая
	 * реализация, доверяющая прокси-заголовкам только за прокси.
	 * Метод сохранён (AGENTS.md, п.1), чтобы не менять внутренние вызовы.
	 *
	 * @return string IP-адрес.
	 */
	private function get_client_ip() {
		return IDPG_Helpers::get_client_ip();
	}

	/**
	 * Получение количества просмотров.
	 *
	 * @param int $post_id ID события.
	 * @return int Количество просмотров.
	 */
	public static function get_views( $post_id ) {
		return (int) get_post_meta( $post_id, self::META_KEY, true );
	}

	// =========================================================================
	// Админ-колонка
	// =========================================================================

	/**
	 * Добавление колонки «Просмотры» в список событий.
	 *
	 * @param array $columns Текущие колонки.
	 * @return array Обновлённые колонки.
	 */
	public function add_views_column( $columns ) {
		$columns['idpg_views']  = __( 'Просмотры', 'idpg-core' );
		$columns['idpg_clicks'] = __( 'Клики', 'idpg-core' );
		return $columns;
	}

	/**
	 * Рендер ячейки колонки «Просмотры».
	 *
	 * @param string $column  Имя колонки.
	 * @param int    $post_id ID поста.
	 */
	public function render_views_column( $column, $post_id ) {
		if ( 'idpg_views' === $column ) {
			echo esc_html( number_format_i18n( self::get_views( $post_id ) ) );
			return;
		}

		if ( 'idpg_clicks' === $column ) {
			echo esc_html( number_format_i18n( self::get_clicks( $post_id ) ) );
		}
	}
}
