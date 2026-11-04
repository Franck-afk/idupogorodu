<?php
/**
 * Тосты стандартных уведомлений админки.
 *
 * Подключает Toastify на всех страницах wp-admin: JS превращает обычные
 * notice в тосты справа снизу, сохраняя ссылки внутри уведомления.
 * Notice с формами и полями ввода не трогаются — они остаются на месте,
 * чтобы не ломать действия, которые ещё не завершены.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Admin_Toasts.
 *
 * Синглтон; вешает подключение ассетов на admin_enqueue_scripts.
 */
class IDPG_Admin_Toasts {

	/**
	 * Хэндл стилей и скрипта тостов.
	 *
	 * Имя файлов историческое (admin-banner-toast.*): сначала тост был
	 * только у плашки сохранения баннера, затем распространён на всю админку.
	 *
	 * @var string
	 */
	const HANDLE = 'idpg-admin-toasts';

	/**
	 * Экземпляр класса.
	 *
	 * @var IDPG_Admin_Toasts|null
	 */
	private static $instance = null;

	/**
	 * Получить синглтон.
	 *
	 * @return IDPG_Admin_Toasts
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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Подключить Toastify и скрипт тостов на всех страницах админки.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$js_path = IDPG_CORE_DIR . 'assets/js/admin-banner-toast.js';
		$css_path = IDPG_CORE_DIR . 'assets/css/admin-banner-toast.css';

		$js_version = file_exists( $js_path )
			? (string) filemtime( $js_path )
			: IDPG_CORE_VERSION;

		$css_version = file_exists( $css_path )
			? (string) filemtime( $css_path )
			: IDPG_CORE_VERSION;

		wp_enqueue_style(
			'idpg-toastify',
			IDPG_CORE_URL . 'assets/vendor/toastify/toastify.min.css',
			array(),
			'1.12.0'
		);

		wp_enqueue_script(
			'idpg-toastify',
			IDPG_CORE_URL . 'assets/vendor/toastify/toastify.min.js',
			array(),
			'1.12.0',
			true
		);

		wp_enqueue_style(
			self::HANDLE,
			IDPG_CORE_URL . 'assets/css/admin-banner-toast.css',
			array( 'idpg-toastify' ),
			$css_version
		);

		wp_enqueue_script(
			self::HANDLE,
			IDPG_CORE_URL . 'assets/js/admin-banner-toast.js',
			array( 'idpg-toastify' ),
			$js_version,
			true
		);
	}
}
