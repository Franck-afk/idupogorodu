<?php
/**
 * Меню и вспомогательные функции личного кабинета организатора.
 *
 * Единый источник списка разделов кабинета: пункты меню строятся из
 * `idpg_organizer_menu()`, тем же списком шаблон `page-organizer.php`
 * обходит панели разделов. Расширение — фильтр `idpg_organizer_menu_items`.
 *
 * @package idpg_core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Обслуживает ли текущий запрос кабинет организатора.
 *
 * Нужна для подключения ассетов кабинета только на /organizer/*
 * (см. `idpg_core_scripts()` в functions.php). Делегирует в
 * `IDPG_Organizer_URL`, если плагин активен.
 *
 * @return bool
 */
function idpg_core_is_organizer_cabinet() {
	if ( class_exists( 'IDPG_Organizer_URL' ) ) {
		return IDPG_Organizer_URL::is_cabinet_request();
	}

	// Плагин ядра отключён: ориентируемся на шаблон и слаг страницы.
	if ( ! function_exists( 'is_page' ) ) {
		return false;
	}

	return is_page( 'organizer' ) || is_page_template( 'page-organizer.php' );
}

/**
 * URL раздела кабинета организатора.
 *
 * Делегирует в `IDPG_Organizer_URL`, если плагин активен; иначе отдаёт
 * предсказуемый адрес, чтобы шаблон работал и при отключённом ядре.
 *
 * @param string $section Слаг раздела (dashboard|events|profile).
 * @return string
 */
function idpg_organizer_section_url( $section ) {
	$section = is_string( $section ) ? sanitize_key( $section ) : '';

	if ( class_exists( 'IDPG_Organizer_URL' ) ) {
		return IDPG_Organizer_URL::section_url( $section );
	}

	if ( 'dashboard' === $section || '' === $section ) {
		return home_url( '/organizer/' );
	}

	return home_url( '/organizer/' . $section . '/' );
}

/**
 * Активный раздел кабинета.
 *
 * @return string Слаг раздела.
 */
function idpg_organizer_current_section() {
	if ( class_exists( 'IDPG_Organizer_URL' ) ) {
		return IDPG_Organizer_URL::get_current_section();
	}

	return 'dashboard';
}

/**
 * Куда вести пользователя после авторизации.
 *
 * Организатора — в его личный кабинет, всех остальных (в том числе
 * администратора и редактора) — в wp-admin. Делегирует в
 * `IDPG_Organizer_URL`, если плагин ядра активен; иначе возвращает
 * wp-admin, чтобы страница входа оставалась рабочей и без плагина.
 *
 * @param WP_User|int $user Пользователь или его ID.
 * @return string URL для редиректа.
 */
function idpg_organizer_after_login_url( $user = 0 ) {
	if ( class_exists( 'IDPG_Organizer_URL' ) ) {
		$url = IDPG_Organizer_URL::after_login_url( $user );

		if ( '' !== $url ) {
			return $url;
		}
	}

	return admin_url();
}

/**
 * Пункты меню кабинета организатора.
 *
 * Каждый пункт — раздел кабинета. Ключ массива совпадает со слагом раздела
 * и используется как имя template-part
 * (`template-parts/organizer/section-{slug}.php`).
 *
 * @return array[] Пункты меню: slug, label, url, icon, order.
 */
function idpg_organizer_menu() {
	$items = array(
		'dashboard' => array(
			'slug'  => 'dashboard',
			'label' => __( 'Дашборд', 'idpg_core' ),
			'url'   => idpg_organizer_section_url( 'dashboard' ),
			'icon'  => '⌂',
			'order' => 10,
		),
		'events'    => array(
			'slug'  => 'events',
			'label' => __( 'Мои события', 'idpg_core' ),
			'url'   => idpg_organizer_section_url( 'events' ),
			'icon'  => '▤',
			'order' => 20,
		),
		'profile'   => array(
			'slug'  => 'profile',
			'label' => __( 'Профиль', 'idpg_core' ),
			'url'   => idpg_organizer_section_url( 'profile' ),
			'icon'  => '☺',
			'order' => 30,
		),
	);

	/**
	 * Фильтр пунктов меню кабинета организатора.
	 *
	 * Позволяет добавить или изменить разделы кабинета. Новый раздел должен
	 * иметь свой template-part `template-parts/organizer/section-{slug}.php`
	 * и, чтобы работал прямой заход по URL, быть добавленным также в фильтр
	 * `idpg_organizer_sections` (с последующим сбросом постоянных ссылок).
	 *
	 * @param array[] $items Пункты меню, ключ — слаг раздела.
	 */
	$items = apply_filters( 'idpg_organizer_menu_items', $items );

	if ( ! is_array( $items ) ) {
		return array();
	}

	// Пункты без слага и подписи бесполезны — отбрасываем.
	$items = array_filter(
		$items,
		static function ( $item ) {
			return is_array( $item ) && ! empty( $item['slug'] ) && isset( $item['label'] );
		}
	);

	uasort( $items, 'idpg_organizer_menu_sort' );

	return $items;
}

/**
 * Сортировка пунктов меню по полю `order`.
 *
 * @param array $a Первый пункт.
 * @param array $b Второй пункт.
 * @return int
 */
function idpg_organizer_menu_sort( $a, $b ) {
	$order_a = isset( $a['order'] ) ? (int) $a['order'] : 10;
	$order_b = isset( $b['order'] ) ? (int) $b['order'] : 10;

	if ( $order_a === $order_b ) {
		return 0;
	}

	return ( $order_a < $order_b ) ? -1 : 1;
}

/**
 * Слаги разделов меню.
 *
 * @return string[]
 */
function idpg_organizer_menu_sections() {
	$sections = array();

	foreach ( idpg_organizer_menu() as $slug => $item ) {
		$sections[] = isset( $item['slug'] ) ? (string) $item['slug'] : (string) $slug;
	}

	return $sections;
}

/**
 * Убирает блок над подвалом в кабинете организатора.
 *
 * Область «Подвал» (`area-podval`) настроена на показ «везде» и содержит
 * рекламный виджет. В личном кабинете он лишний: кабинет — рабочее место
 * организатора, а не промо-страница.
 *
 * Фильтр `sidebars_widgets` отдаёт пустой список виджетов для всех областей
 * с позицией `before-footer`, поэтому `is_active_sidebar()` в
 * `idpg_widget_areas_render()` возвращает false и разметка области не
 * выводится вовсе — шаблон `footer.php` и модуль виджетных областей не
 * меняются. Правка касается только кабинета, остальные страницы не затронуты.
 *
 * @param array $sidebars_widgets Список виджетов по областям.
 * @return array
 */
function idpg_organizer_hide_before_footer_widgets( $sidebars_widgets ) {
	if ( is_admin() || ! is_array( $sidebars_widgets ) || ! function_exists( 'idpg_widget_areas_all' ) ) {
		return $sidebars_widgets;
	}

	if ( ! function_exists( 'idpg_core_is_organizer_cabinet' ) || ! idpg_core_is_organizer_cabinet() ) {
		return $sidebars_widgets;
	}

	foreach ( idpg_widget_areas_all() as $slug => $area ) {
		if ( ! is_array( $area ) || ! isset( $area['position'] ) || 'before-footer' !== $area['position'] ) {
			continue;
		}

		$sidebar_id = 'idpg-area-' . sanitize_key( $slug );

		if ( isset( $sidebars_widgets[ $sidebar_id ] ) ) {
			$sidebars_widgets[ $sidebar_id ] = array();
		}
	}

	return $sidebars_widgets;
}
add_filter( 'sidebars_widgets', 'idpg_organizer_hide_before_footer_widgets' );
