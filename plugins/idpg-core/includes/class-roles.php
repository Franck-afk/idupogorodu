<?php
/**
 * Регистрация пользовательских ролей и возможностей (capabilities).
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Roles
 *
 * Управляет созданием, удалением и проверкой прав роли event_organizer,
 * а также регистрирует кастомные capabilities для CPT event
 * у ролей администратора и редактора.
 */
class IDPG_Roles {

	/**
	 * Экземпляр синглтона.
	 *
	 * @var IDPG_Roles|null
	 */
	private static $instance = null;

	/**
	 * Название роли организатора.
	 *
	 * @var string
	 */
	const ROLE_SLUG = 'event_organizer';

	/**
	 * Текущая версия набора прав ролей.
	 *
	 * Увеличивается при изменении состава capabilities, чтобы
	 * существующие роли обновлялись однократно.
	 *
	 * @var int
	 */
	// 8 — DEV-206: организатору выдано upload_files, иначе в форме события
	// не работает выбор постера и галереи из медиатеки.
	const ROLE_VERSION = 8;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Roles
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
		add_action( 'init', array( $this, 'sync_role_caps' ), 20 );
		add_action( 'admin_menu', array( $this, 'hide_unnecessary_menus' ), 999 );
		add_action( 'wp_before_admin_bar_render', array( $this, 'hide_unnecessary_admin_bar' ), 999 );
		add_action( 'show_admin_bar', array( $this, 'hide_frontend_admin_bar' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'hide_admin_bar_css' ), 999 );
		add_action( 'init', array( $this, 'ensure_safe_default_role' ), 21 );
	}

	/**
	 * Не даёт `default_role` снова стать ролью организатора (DEV-98).
	 *
	 * `default_role` применяется ядром к каждому новому пользователю без
	 * явной роли — в том числе созданному из админки. Если там стоит
	 * `event_organizer`, такой пользователь сразу получает доступ в
	 * кабинет организатора, минуя подтверждение email, которое выдаёт
	 * роль только `IDPG_Organizer_Verify`.
	 *
	 * Проверка идемпотентна: при корректном значении опция не
	 * переписывается, поэтому лишних записей в `wp_options` нет.
	 *
	 * @return void
	 */
	public function ensure_safe_default_role() {
		if ( get_option( 'default_role' ) !== self::ROLE_SLUG ) {
			return;
		}

		update_option( 'default_role', 'subscriber' );
	}

	/**
	 * Однократная синхронизация capabilities существующих ролей.
	 *
	 * Срабатывает при увеличении IDPG_Roles::ROLE_VERSION и добавляет
	 * новые capabilities уже созданным ролям (для инсталляций, где
	 * роль создана до обновления плагина).
	 *
	 * @return void
	 */
	public function sync_role_caps() {
		if ( get_option( 'idpg_roles_version', 0 ) >= self::ROLE_VERSION ) {
			return;
		}

		$organizer = get_role( self::ROLE_SLUG );

		if ( $organizer ) {
			$organizer->add_cap( 'edit_posts' );
			$organizer->add_cap( 'delete_published_events' );
			$organizer->add_cap( 'delete_private_events' );

			/*
			 * Загрузка медиафайлов (DEV-206).
			 *
			 * Право было выдано только роли `subscriber`, а организатор после
			 * подтверждения получает `event_organizer`, и роли в WordPress не
			 * наследуют друг друга. Из-за этого `current_user_can( 'upload_files' )`
			 * у организатора было ложно, и в форме события вместо кнопок выбора
			 * постера и галереи выводилась подсказка «Загрузка файлов недоступна
			 * для вашей роли», а медиатека не подключалась вовсе. Намерение роли
			 * записано рядом: создавать события она не может, загружать файлы —
			 * может.
			 *
			 * Что это даёт: организатор может загрузить в медиатеку любой файл.
			 * Ограничить набор расширений на уровне медиатеки нельзя — WordPress
			 * проверяет права по capability, а не по вызывающему коду. Если
			 * загрузка нужна только для картинок, ограничение надо делать на
			 * серверной стороне эндпоинта загрузки.
			 */
			$organizer->add_cap( 'upload_files' );
			// Модерация: снимаем право прямой публикации событий.
			$organizer->remove_cap( 'publish_events' );
			// Доступ к площадкам.
			$organizer->add_cap( 'edit_venues' );
			$organizer->add_cap( 'publish_venues' );
			$organizer->add_cap( 'delete_venues' );
			$organizer->add_cap( 'edit_published_venues' );
			$organizer->add_cap( 'delete_published_venues' );
			$organizer->add_cap( 'delete_private_venues' );
			$organizer->add_cap( 'read_private_venues' );
		}

		// Подписчикам гарантируем право загрузки файлов (аватар в ЛК).
		// Роль subscriber может отсутствовать в wp_roles (DEV-32): регистрация
		// ставит её через set_role('subscriber'), поэтому на целевой роли
		// подписчика должно стоять read + upload_files. Досоздаём при отсутствии.
		$subscriber = get_role( 'subscriber' );

		if ( ! $subscriber ) {
			$subscriber = add_role(
				'subscriber',
				__( 'Подписчик', 'idpg-core' ),
				array(
					'read'          => true,
					'level_0'       => true,
					'upload_files'  => true,
				)
			);
		}

		if ( $subscriber ) {
			$subscriber->add_cap( 'upload_files' );
		}

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			$admin->add_cap( 'delete_published_events' );
			$admin->add_cap( 'delete_private_events' );
			$admin->add_cap( 'edit_venues' );
			$admin->add_cap( 'read_venues' );
			$admin->add_cap( 'delete_venues' );
			$admin->add_cap( 'edit_published_venues' );
			$admin->add_cap( 'publish_venues' );
			$admin->add_cap( 'edit_others_venues' );
			$admin->add_cap( 'delete_published_venues' );
			$admin->add_cap( 'read_private_venues' );
			$admin->add_cap( 'delete_others_venues' );
		}

		$editor = get_role( 'editor' );

		if ( $editor ) {
			$editor->add_cap( 'delete_published_events' );
			$editor->add_cap( 'delete_private_events' );
			$editor->add_cap( 'edit_venues' );
			$editor->add_cap( 'read_venues' );
			$editor->add_cap( 'delete_venues' );
			$editor->add_cap( 'edit_published_venues' );
			$editor->add_cap( 'publish_venues' );
			$editor->add_cap( 'edit_others_venues' );
			$editor->add_cap( 'delete_published_venues' );
			$editor->add_cap( 'read_private_venues' );
			$editor->add_cap( 'delete_others_venues' );
		}

		update_option( 'idpg_roles_version', self::ROLE_VERSION );
	}

	/**
	 * Создание ролей и назначение capabilities.
	 *
	 * Вызывается на хуке activate_{plugin} через idpg_core_activate().
	 * Добавляет кастомные capabilities для CPT event
	 * ролям администратора, редактора и организатора.
	 * Сохраняет флаг idpg_roles_created, чтобы избежать повторного создания.
	 *
	 * @return void
	 */
	public function create_roles() {
		if ( get_option( 'idpg_roles_created' ) && get_option( 'idpg_roles_version', 0 ) >= self::ROLE_VERSION ) {
			return;
		}

		// Роль организатора.
		add_role(
			self::ROLE_SLUG,
			__( 'Организатор', 'idpg-core' ),
			array(
			// Базовые.
			'read'                       => true,
			'upload_files'               => true,
			'edit_posts'                 => true,

			// Capabilities для CPT event.
			// Модерация: организатор создаёт/редактирует свои события,
			// но публикует их администратор (publish_events отсутствует).
			'edit_events'                => true,
			'publish_events'             => false,
			'delete_events'              => true,
			'edit_published_events'      => true,
			'delete_published_events'    => true,
				'delete_private_events'      => true,
				'edit_others_events'         => false,
				'delete_others_events'       => false,
				'manage_categories'          => false,
				'manage_options'             => false,

				// Capabilities для CPT venue.
				'edit_venues'                => true,
				'publish_venues'             => true,
				'delete_venues'              => true,
				'edit_published_venues'      => true,
				'delete_published_venues'    => true,
				'delete_private_venues'      => true,
				'read_private_venues'        => true,
				'edit_others_venues'         => false,
				'delete_others_venues'       => false,
			)
		);

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			$caps = array(
				'edit_events',
				'read_events',
				'delete_events',
				'edit_published_events',
				'publish_events',
				'edit_others_events',
				'delete_published_events',
				'read_private_events',
				'delete_others_events',
				'manage_categories',
				'edit_venues',
				'read_venues',
				'delete_venues',
				'edit_published_venues',
				'publish_venues',
				'edit_others_venues',
				'delete_published_venues',
				'read_private_venues',
				'delete_others_venues',
			);

			foreach ( $caps as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		$editor = get_role( 'editor' );

		if ( $editor ) {
			$caps = array(
				'edit_events',
				'read_events',
				'delete_events',
				'edit_published_events',
				'publish_events',
				'edit_others_events',
				'delete_published_events',
				'read_private_events',
				'delete_others_events',
				'manage_categories',
				'edit_venues',
				'read_venues',
				'delete_venues',
				'edit_published_venues',
				'publish_venues',
				'edit_others_venues',
				'delete_published_venues',
				'read_private_venues',
				'delete_others_venues',
			);

			foreach ( $caps as $cap ) {
				$editor->add_cap( $cap );
			}
		}

		// Подписчикам даём право загрузки файлов (аватар в ЛК).
		// Роль subscriber создаётся явно (DEV-32), т.к. на целевых инсталляциях
		// она может отсутствовать: set_role('subscriber') при регистрации
		// организатора иначе пишет в wp_capabilities ключ несуществующей роли.
		$subscriber = get_role( 'subscriber' );

		if ( ! $subscriber ) {
			$subscriber = add_role(
				'subscriber',
				__( 'Подписчик', 'idpg-core' ),
				array(
					'read'          => true,
					'level_0'       => true,
					'upload_files'  => true,
				)
			);
		}

		if ( $subscriber ) {
			$subscriber->add_cap( 'upload_files' );
		}

		update_option( 'idpg_roles_created', true );
		update_option( 'idpg_roles_version', self::ROLE_VERSION );
	}

	/**
	 * Удаление кастомных capabilities при деактивации.
	 *
	 * Вызывается на хуке deactivate_{plugin} через idpg_core_deactivate().
	 * Очищает флаг idpg_roles_created.
	 *
	 * @return void
	 */
	public function remove_roles() {
		remove_role( self::ROLE_SLUG );

		$admin = get_role( 'administrator' );
		$editor = get_role( 'editor' );

		$caps = array(
			'edit_events',
			'read_events',
			'delete_events',
			'edit_published_events',
			'publish_events',
			'edit_others_events',
			'delete_published_events',
			'delete_private_events',
			'read_private_events',
			'delete_others_events',
			'manage_categories',

			// Capabilities для CPT venue (добавляются в create_roles и sync_role_caps).
			'edit_venues',
			'read_venues',
			'delete_venues',
			'edit_published_venues',
			'publish_venues',
			'edit_others_venues',
			'delete_published_venues',
			'read_private_venues',
			'delete_others_venues',
		);

		if ( $admin ) {
			foreach ( $caps as $cap ) {
				$admin->remove_cap( $cap );
			}
		}

		if ( $editor ) {
			foreach ( $caps as $cap ) {
				$editor->remove_cap( $cap );
			}
		}

		delete_option( 'idpg_roles_created' );
		delete_option( 'idpg_roles_version' );

		$subscriber = get_role( 'subscriber' );
		if ( $subscriber ) {
			$subscriber->remove_cap( 'upload_files' );
		}
	}

	/**
	 * Скрытие ненужных пунктов меню для администратора и организатора.
	 *
	 * Убирает «Записи», «Комментарии» и «Органайзер» — как у администратора,
	 * так и у организатора, которым нужны только события, площадки и настройки.
	 *
	 * @return void
	 */
	public function hide_unnecessary_menus() {
		if ( ! is_admin() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			return;
		}

		$roles = (array) $user->roles;

		// Применяем только для администратора и организатора.
		if ( ! in_array( 'administrator', $roles, true )
			&& ! in_array( 'event_organizer', $roles, true ) ) {
			return;
		}

		remove_menu_page( 'edit.php' );               // Записи.
		remove_menu_page( 'edit-comments.php' );      // Комментарии.
		remove_menu_page( 'idpg-organizer-settings' ); // Органайзер (страница настроек организатора).

		// Инструменты — дополнительно организатору.
		if ( in_array( 'event_organizer', $roles, true ) ) {
			remove_menu_page( 'tools.php' );          // Инструменты.
			remove_menu_page( 'aiowpsec' );           // AIOS (безопасность) — только администратору.

			// Yoast SEO — только администратору.
			// Yoast добавляет несколько отдельных топ-пунктов с капой edit_posts
			// (Dashboard, Academy, Brand Insights, Upgrade — см. wpseo_submenu_pages),
			// поэтому удаляем их все по префиксу слага.
			if ( isset( $GLOBALS['menu'] ) && is_array( $GLOBALS['menu'] ) ) {
				foreach ( $GLOBALS['menu'] as $item ) {
					if ( isset( $item[2] ) && 0 === strpos( $item[2], 'wpseo' ) ) {
						remove_menu_page( $item[2] );
					}
				}
			}
		}
	}

	/**
	 * Проверка: текущий пользователь — организатор.
	 *
	 * DEV-258: метод стал публичным — им пользуется `IDPG_Meta`
	 * (`enqueue_editor_styles()` и `filter_events_for_organizer()`).
	 * Проверять роль через `current_user_can( 'event_organizer' )` нельзя:
	 * такой capability в WordPress не зарегистрирован, и ядро отвечает `true`
	 * лишь потому, что совпало имя с ролью (BC-механизм `WP_User::has_cap()`).
	 * Замена на `edit_events` тоже не годится: cap есть и у редактора, и у
	 * администратора, а здесь нужна именно роль организатора.
	 *
	 * @return bool
	 */
	public function is_current_user_organizer() {
		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			return false;
		}

		return in_array( self::ROLE_SLUG, (array) $user->roles, true );
	}

	/**
	 * Убирает верхнюю панель WordPress (admin bar) у организатора на сайте.
	 *
	 * Штатный путь: фильтр `show_admin_bar` — `is_admin_bar_showing()`
	 * применяет его на фронтенде (`wp-includes/admin-bar.php`).
	 *
	 * @param bool $show_admin_bar Текущее решение ядра.
	 * @return bool
	 */
	public function hide_frontend_admin_bar( $show_admin_bar ) {
		if ( is_admin() ) {
			return $show_admin_bar;
		}

		if ( $this->is_current_user_organizer() ) {
			return false;
		}

		return $show_admin_bar;
	}

	/**
	 * Прячет верхнюю панель WordPress в админке организатора через CSS.
	 *
	 * В админке фильтр `show_admin_bar` бесполезен: `is_admin_bar_showing()`
	 * возвращает true по ветке `is_admin()` до применения фильтра
	 * (`wp-includes/admin-bar.php`), поэтому штатно панель там не отключается.
	 * Панель скрывается визуально, но остаётся в DOM — вместе со ссылками
	 * «Выйти» и профилем, поэтому организатор не теряет доступ к выходу.
	 *
	 * Отступ сверху снимается у `html.wp-toolbar`, потому что именно этот
	 * класс ставит `wp-admin/includes/template.php`, а не `body.admin-bar`.
	 *
	 * @return void
	 */
	public function hide_admin_bar_css() {
		if ( ! $this->is_current_user_organizer() ) {
			return;
		}

		$css = '#wpadminbar{display:none !important;}'
			. 'html.wp-toolbar{padding-top:0 !important;}';

		wp_add_inline_style( 'admin-bar', $css );
	}

	/**
	 * Скрытие пунктов в верхней админ-панели (admin bar) для организатора.
	 *
	 * Yoast SEO показывает свой пункт в admin bar всем, у кого есть edit_posts
	 * (см. WPSEO_Admin_Bar_Menu::add_menu), поэтому бокового remove_menu_page
	 * недостаточно — отдельно удаляем пункт из панели.
	 *
	 * @return void
	 */
	public function hide_unnecessary_admin_bar() {
		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			return;
		}

		$roles = (array) $user->roles;

		if ( ! in_array( 'event_organizer', $roles, true ) ) {
			return;
		}

		global $wp_admin_bar;

		if ( is_object( $wp_admin_bar ) ) {
			$wp_admin_bar->remove_node( 'wpseo-menu' );
		}
	}

	/**
	 * Возвращает массив capabilities для указанной роли.
	 *
	 * Используется для проверки прав в REST API.
	 *
	 * @param string $role Название роли (event_organizer, administrator, editor).
	 * @return array Массив capabilities или пустой массив, если роль не найдена.
	 */
	public function get_role_capabilities( $role ) {
		$role_object = get_role( $role );

		if ( ! $role_object ) {
			return array();
		}

		return array_keys( array_filter( $role_object->capabilities ) );
	}
}
