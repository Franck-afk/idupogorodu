<?php
/**
 * Page creation functions for idpg_core theme
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

 * @return int ID страницы; 0, если создать не удалось.
 */
function idpg_core_create_organizer_page() {
	$existing = get_page_by_path( 'organizer' );

	if ( $existing instanceof WP_Post ) {
		update_option( '_idpg_organizer_page_id', (int) $existing->ID );
		update_post_meta( (int) $existing->ID, '_wp_page_template', 'page-organizer.php' );

		return (int) $existing->ID;
	}

	$page_id = wp_insert_post(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'post_title'     => 'Кабинет организатора',
			'post_name'      => 'organizer',
			'post_content'   => '',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		),
		true
	);

	if ( is_wp_error( $page_id ) ) {
		return 0;
	}

	update_post_meta( (int) $page_id, '_wp_page_template', 'page-organizer.php' );
	update_option( '_idpg_organizer_page_id', (int) $page_id );

	return (int) $page_id;
}
add_action( 'after_setup_theme', 'idpg_core_create_organizer_page' );

/**
 * ID страницы кабинета организатора.
 *
 * Опция — основной источник, но при её потере (импорт базы, ручная
 * правка) ID восстанавливается по слагу. Значение, указывающее не на
 * страницу, игнорируется.
 *
 * @return int ID страницы; 0, если страницы нет.
 */
function idpg_core_get_organizer_page_id() {
	$stored = (int) get_option( '_idpg_organizer_page_id', 0 );

	if ( $stored > 0 ) {
		$page = get_post( $stored );

		if ( $page instanceof WP_Post && 'page' === $page->post_type ) {
			return $stored;
		}
	}

	$page = get_page_by_path( 'organizer' );

	if ( ! $page instanceof WP_Post ) {
		return 0;
	}

	update_option( '_idpg_organizer_page_id', (int) $page->ID );

	return (int) $page->ID;
}

/**
 * Исключает страницу кабинета организатора из sitemap.
 *
 * Кабинет закрыт от индексации, поэтому в карту сайта не попадает.
 * Имя хука — точно как в Yoast: `wpseo_exclude_from_sitemap_by_post_ids`
 * (единственное `post`). Вариант с `posts` (множественное) в плагине
 * не встречается ни разу, поэтому фильтр с ним не сработал бы.
 *
 * @param array $excluded Список исключений Yoast.
 * @return array
 */
function idpg_core_exclude_organizer_page_from_sitemap( $excluded ) {
	if ( ! is_array( $excluded ) ) {
		return array();
	}

	$page_id = idpg_core_get_organizer_page_id();

	if ( $page_id ) {
		$excluded[] = $page_id;
	}

	return array_values( array_unique( $excluded ) );
}
add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', 'idpg_core_exclude_organizer_page_from_sitemap' );

/**

/**
 * Автосоздание меню навигации по умолчанию.
 *
 * Создаёт меню «Основное меню» с пунктами:
 * - Афиша (с dropdown: Все события, Сегодня, Завтра, Этот уикенд, Бесплатные)
 * - Места (с dropdown: Все площадки, Театры, Кинотеатры, Музеи, Концертные залы)
 * - Личности
 * - Блог
 */
function idpg_core_create_default_menu() {
	$menu_name = 'Основное меню';
	$menu_exists = wp_get_nav_menu_object( $menu_name );

	if ( $menu_exists ) {
		return;
	}

	// Создаём меню
	$menu_id = wp_create_nav_menu( $menu_name, array(
		'menu-name' => $menu_name,
	) );

	if ( is_wp_error( $menu_id ) ) {
		return;
	}

	// Структура меню из CSV
	$menu_items = array(
		// Афиша (с подменю)
		array(
			'title'  => 'Афиша',
			'url'    => idpg_core_get_afisha_url(),
			'children' => array(
				array( 'title' => 'Все события',   'url' => idpg_core_get_afisha_url() ),
				array( 'title' => 'Сегодня',       'url' => home_url( '/afisha/?date=today' ) ),
				array( 'title' => 'Завтра',        'url' => home_url( '/afisha/?date=tomorrow' ) ),
				array( 'title' => 'Этот уикенд',   'url' => home_url( '/afisha/?date=weekend' ) ),
				array( 'title' => 'Бесплатные',    'url' => home_url( '/afisha/?is_free=1' ) ),
			),
		),
		// Места (с подменю)
		array(
			'title'  => 'Места',
			'url'    => home_url( '/venues/' ),
			'children' => array(
				array( 'title' => 'Все площадки',    'url' => home_url( '/venues/' ) ),
				array( 'title' => 'Театры',          'url' => home_url( '/venues/?type=theater' ) ),
				array( 'title' => 'Кинотеатры',      'url' => home_url( '/venues/?type=cinema' ) ),
				array( 'title' => 'Музеи',           'url' => home_url( '/venues/?type=museum' ) ),
				array( 'title' => 'Концертные залы', 'url' => home_url( '/venues/?type=concert' ) ),
			),
		),
		// Личности (без подменю)
		array(
			'title' => 'Личности',
			'url'   => home_url( '/personalities/' ),
		),
		// Блог (без подменю)
		array(
			'title' => 'Блог',
			'url'   => home_url( '/blog/' ),
		),
	);

	// Родительские ID для подменю
	$parent_ids = array();

	foreach ( $menu_items as $item ) {
		// Добавляем родительский пункт
		$item_id = wp_update_nav_menu_item( $menu_id, 0, array(
			'menu-item-title'  => $item['title'],
			'menu-item-url'    => $item['url'],
			'menu-item-status' => 'publish',
		) );

		// Сохраняем ID родителя
		$parent_ids[ $item['title'] ] = $item_id;

		// Добавляем подменю (дети)
		if ( ! empty( $item['children'] ) ) {
			foreach ( $item['children'] as $child ) {
				wp_update_nav_menu_item( $menu_id, 0, array(
					'menu-item-title'      => $child['title'],
					'menu-item-url'        => $child['url'],
					'menu-item-status'     => 'publish',
					'menu-item-parent-id'  => $item_id,
				) );
			}
		}
	}

	// Привязываем меню к локации
	$locations = get_theme_mod( 'nav_menu_locations', array() );
	$locations['menu-1'] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );
}
add_action( 'after_setup_theme', 'idpg_core_create_default_menu' );

/**
 * Автосоздание страницы входа.
 *
 * Создаёт страницу /login/ с шаблоном page-login.php,
 * если она ещё не существует.
 */
function idpg_core_create_login_page() {
	$page_slug = 'login';
	$page_title = 'Вход';
	$page_template = 'page-login.php';

	$existing_page = get_page_by_path( $page_slug );

	if ( $existing_page ) {
		$template = get_post_meta( $existing_page->ID, '_wp_page_template', true );
		if ( $template === $page_template ) {
			return;
		}
	}

	$page_data = array(
		'post_title'   => $page_title,
		'post_name'    => $page_slug,
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '',
		'post_parent'  => 0,
	);

	$page_id = wp_insert_post( $page_data, true );

	if ( ! is_wp_error( $page_id ) ) {
		update_post_meta( $page_id, '_wp_page_template', $page_template );
	}
}
add_action( 'after_setup_theme', 'idpg_core_create_login_page' );

/**
 * Автосоздание страницы восстановления пароля.
 *
 * Создаёт страницу /reset-password/ с шаблоном page-reset-password.php,
 * если она ещё не существует.
 */
function idpg_core_create_reset_password_page() {
	$page_slug = 'reset-password';
	$page_title = 'Восстановление пароля';
	$page_template = 'page-reset-password.php';

	$existing_page = get_page_by_path( $page_slug );

	if ( $existing_page ) {
		$template = get_post_meta( $existing_page->ID, '_wp_page_template', true );
		if ( $template === $page_template ) {
			return;
		}
	}

	$page_data = array(
		'post_title'   => $page_title,
		'post_name'    => $page_slug,
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '',
		'post_parent'  => 0,
	);

	$page_id = wp_insert_post( $page_data, true );

	if ( ! is_wp_error( $page_id ) ) {
		update_post_meta( $page_id, '_wp_page_template', $page_template );
	}
}
add_action( 'after_setup_theme', 'idpg_core_create_reset_password_page' );

/**
 * Автосоздание страницы «Правила сайта / оферта».
 *
 * Создаёт страницу /terms/ с шаблоном page-terms.php и
 * предзаполненным текстом оферты, если она ещё не существует.
 */
function idpg_core_create_terms_page() {
	$page_slug     = 'terms';
	$page_title    = 'Правила сайта';
	$page_template = 'page-terms.php';

	$existing_page = get_page_by_path( $page_slug );

	if ( $existing_page ) {
		$template = get_post_meta( $existing_page->ID, '_wp_page_template', true );
		if ( $template === $page_template ) {
			return;
		}

		$page_id = $existing_page->ID;
	} else {
		$page_data = array(
			'post_title'   => $page_title,
			'post_name'    => $page_slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<h2>1. Общие положения</h2>' . "\n\n" .
				'<p>Настоящие правила определяют условия использования сайта и сервисов, размещённых на нём.</p>' . "\n\n" .
				'<h2>2. Предмет Оферты</h2>' . "\n\n" .
				'<p>Сайт предоставляет информационные услуги: публикацию афиши событий, сведений о площадках и возможность взаимодействия пользователей с сервисом.</p>' . "\n\n" .
				'<h2>3. Регистрация</h2>' . "\n\n" .
				'<p>Для доступа к части сервисов требуется регистрация. Регистрируясь, пользователь обязуется предоставлять достоверные сведения.</p>' . "\n\n" .
				'<h2>4. Заключительные положения</h2>' . "\n\n" .
				'<p>Администрация вправе изменять настоящие правила в одностороннем порядке. Актуальная версия доступна на этой странице.</p>',
			'post_parent'  => 0,
		);

		$page_id = wp_insert_post( $page_data, true );

		if ( is_wp_error( $page_id ) ) {
			return;
		}
	}

	update_post_meta( $page_id, '_wp_page_template', $page_template );
}
add_action( 'after_setup_theme', 'idpg_core_create_terms_page' );

/**
 * Автосоздание страницы «Политика конфиденциальности».
 *
 * Создаёт страницу /privacy/ с шаблоном page-privacy.php и
 * предзаполненным текстом политики, если она ещё не существует.
 */
function idpg_core_create_privacy_page() {
	$page_slug     = 'privacy';
	$page_title    = 'Политика конфиденциальности';
	$page_template = 'page-privacy.php';

	$existing_page = get_page_by_path( $page_slug );

	if ( $existing_page ) {
		// DEV-309: даже если шаблон уже стоит (ранний return ниже), опция
		// привязки «Политики конфиденциальности» должна быть выправлена.
		idpg_core_assign_privacy_page( (int) $existing_page->ID );

		$template = get_post_meta( $existing_page->ID, '_wp_page_template', true );
		if ( $template === $page_template ) {
			return;
		}

		$page_id = $existing_page->ID;
	} else {
		$page_data = array(
			'post_title'   => $page_title,
			'post_name'    => $page_slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<h2>1. Общие положения</h2>' . "\n\n" .
				'<p>Настоящая Политика конфиденциальности определяет порядок обработки и защиты персональных данных пользователей сайта.</p>' . "\n\n" .
				'<h2>2. Какие данные мы обрабатываем</h2>' . "\n\n" .
				'<p>При регистрации могут обрабатываться имя, адрес электронной почты, номер телефона и иные сведения, предоставляемые пользователем добровольно.</p>' . "\n\n" .
				'<h2>3. Цели обработки</h2>' . "\n\n" .
				'<p>Данные обрабатываются для предоставления доступа к сервисам, создания учётной записи и направления уведомлений.</p>' . "\n\n" .
				'<h2>4. Защита данных</h2>' . "\n\n" .
				'<p>Администрация принимает организационные и технические меры для защиты персональных данных от неправомерного доступа.</p>' . "\n\n" .
				'<h2>5. Заключительные положения</h2>' . "\n\n" .
				'<p>Администрация вправе изменять настоящую Политику в одностороннем порядке. Актуальная версия доступна на этой странице.</p>',
			'post_parent'  => 0,
		);

		$page_id = wp_insert_post( $page_data, true );

		if ( is_wp_error( $page_id ) ) {
			return;
		}
	}

	update_post_meta( $page_id, '_wp_page_template', $page_template );
	idpg_core_assign_privacy_page( (int) $page_id );
}
add_action( 'after_setup_theme', 'idpg_core_create_privacy_page' );

/**
 * Привязывает страницу как «Политика конфиденциальности» сайта (DEV-309).
 *
 * Устанавливает опцию `wp_page_for_privacy_policy`, чтобы ссылки в футере,
 * формах и в админке («Настройки → Конфиденциальность») вели на /privacy/.
 * Не перезаписывает уже назначенную валидную страницу — её мог выбрать
 * владелец вручную.
 *
 * @param int $page_id ID страницы политики.
 * @return void
 */
function idpg_core_assign_privacy_page( $page_id ) {
	$page_id = (int) $page_id;

	if ( $page_id <= 0 ) {
		return;
	}

	$assigned = (int) get_option( 'wp_page_for_privacy_policy' );
	if ( $assigned <= 0 || ! get_post( $assigned ) ) {
		update_option( 'wp_page_for_privacy_policy', $page_id );
	}
}

/**
 * Автосоздание страницы «Оферта».
 *
 * Создаёт страницу /offer/ с шаблоном page-terms.php (светлый hero без TOC),
 * если она ещё не существует. Использует тот же fallback, что и «Правила».
 */
function idpg_core_create_offer_page() {
	$page_slug     = 'offer';
	$page_title    = 'Оферта';
	$page_template = 'page-terms.php';

	$existing_page = get_page_by_path( $page_slug );

	if ( $existing_page ) {
		$template = get_post_meta( $existing_page->ID, '_wp_page_template', true );
		if ( $template === $page_template ) {
			return;
		}
		$page_id = $existing_page->ID;
	} else {
		$page_data = array(
			'post_title'   => $page_title,
			'post_name'    => $page_slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<h2>1. Общие положения</h2>' . "\n\n" .
				'<p>Настоящая оферта определяет условия использования сайта.</p>' . "\n\n" .
				'<h2>2. Предмет оферты</h2>' . "\n\n" .
				'<p>Сайт предоставляет информационные услуги.</p>' . "\n\n" .
				'<h2>3. Заключительные положения</h2>' . "\n\n" .
				'<p>Администрация вправе изменять настоящую оферту. Актуальная версия доступна на этой странице.</p>',
			'post_parent'  => 0,
		);

		$page_id = wp_insert_post( $page_data, true );

		if ( is_wp_error( $page_id ) ) {
			return;
		}
	}

	update_post_meta( $page_id, '_wp_page_template', $page_template );
}
add_action( 'after_setup_theme', 'idpg_core_create_offer_page' );
