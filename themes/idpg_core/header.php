<?php
/**
 * Шаблон шапки сайта для темы idpg_core
 *
 * @package idpg_core
 */

// Текущий город: кука idpg_selected_city → константа → «Ижевск» по умолчанию.
$current_city_name = class_exists( 'IDPG_City_Filter' )
	? IDPG_City_Filter::get_current_city_name()
	: ( isset( $_COOKIE['idpg_city'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['idpg_city'] ) ) : 'Ижевск' );

// Дерево регионов/городов, текущий ID и подсказка об автоопределении по IP.
// Вычисляем ДО первого вывода: мобильная модалка (#idpg-city-modal) — в начале
// шапки, поэтому переменные подготавливаем заранее, иначе select останется пустым.
$city_tree  = class_exists( 'IDPG_City_Filter' ) ? IDPG_City_Filter::get_city_tree() : array();
$current_id = class_exists( 'IDPG_City_Filter' ) ? IDPG_City_Filter::get_current_city_id() : 0;

// Подсказка об автоопределении по IP — та же, что в виджете
// (class-city-selector-widget.php): показывается, если город был
// определён автоматически при первом визите, а не выбран вручную.
$idpg_city_ipnote = class_exists( 'IDPG_City_Filter' ) && IDPG_City_Filter::was_detected_from_ip()
	? '<p class="idpg-city-selector__ipnote">'
		. esc_html__( 'Мы определили ваш город автоматически. При необходимости выберите другой:', 'idpg_core' )
		. '</p>'
	: '';

// DEV-19: блок подтверждения города, определённого по IP. Тот же рендер, что в
// виджете (IDPG_City_Selector_Widget::render_confirmation_bar()), чтобы разметка
// не расходилась. 'modal' — открыть мобильную модалку, 'panel' — выпадающий список.
$idpg_city_confirm_modal = class_exists( 'IDPG_City_Selector_Widget' )
	? IDPG_City_Selector_Widget::render_confirmation_bar( 'modal' )
	: '';
$idpg_city_confirm_panel = class_exists( 'IDPG_City_Selector_Widget' )
	? IDPG_City_Selector_Widget::render_confirmation_bar( 'panel' )
	: '';

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<?php if ( file_exists( get_template_directory() . '/favicon.png' ) ) : ?>
		<link rel="icon" href="<?php echo esc_url( get_template_directory_uri() . '/favicon.png' ); ?>" type="image/png">
	<?php endif; ?>

	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="page">
<header class="idpg-header" role="banner">
	<div class="idpg-header__container container">

		<!-- Логотип -->
		<?php idpg_render_logo(); ?>

		<!-- Навигация -->
		<nav class="idpg-nav" id="idpg-nav" role="navigation" aria-label="<?php esc_attr_e( 'Основная навигация', 'idpg_core' ); ?>">
		<!-- Шапка меню: логотип + кнопка закрытия -->
		<div class="idpg-nav__header">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="idpg-nav__logo" rel="home">
				<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
			</a>
			<button
				type="button"
				class="idpg-nav__close"
				id="idpg-nav-close"
				aria-label="<?php esc_attr_e( 'Закрыть меню', 'idpg_core' ); ?>"
			>
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<line x1="18" y1="6" x2="6" y2="18"></line>
					<line x1="6" y1="6" x2="18" y2="18"></line>
				</svg>
			</button>
		</div>

		<?php
		wp_nav_menu(
			array(
				'theme_location' => 'menu-1',
				'container'      => false,
				'menu_class'     => 'idpg-nav__list',
				'fallback_cb'    => false,
				'depth'          => 2,
				'walker'         => new IDPG_Nav_Walker(),
			)
		);
		?>

</nav>

<!-- Мобильная модалка выбора города -->
<div class="idpg-city-modal" id="idpg-city-modal" hidden>
	<div class="idpg-city-modal__backdrop" id="idpg-city-modal-close"></div>
	<div class="idpg-city-modal__content">
		<div class="idpg-city-modal__header">
			<h3><?php esc_html_e( 'Выберите город', 'idpg_core' ); ?></h3>
			<button type="button" class="idpg-city-modal__close" id="idpg-city-modal-x" aria-label="<?php esc_attr_e( 'Закрыть', 'idpg_core' ); ?>">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
			</button>
		</div>
		<?php echo $idpg_city_ipnote; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php echo $idpg_city_confirm_modal; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php if ( ! empty( $city_tree ) ) : ?>
			<select class="idpg-city-modal__select" id="idpg-city-modal-select" data-idpg-city-header>
				<?php foreach ( $city_tree as $region ) : ?>
					<?php if ( empty( $region['children'] ) ) : ?>
						<option value="<?php echo esc_attr( $region['slug'] ); ?>" data-id="<?php echo esc_attr( $region['id'] ); ?>" <?php selected( (int) $current_id, (int) $region['id'] ); ?>>
							<?php echo esc_html( $region['name'] ); ?>
						</option>
						<?php continue; ?>
					<?php endif; ?>
					<optgroup label="<?php echo esc_attr( $region['name'] ); ?>">
						<?php foreach ( $region['children'] as $city ) : ?>
							<option value="<?php echo esc_attr( $city['slug'] ); ?>" data-id="<?php echo esc_attr( $city['id'] ); ?>" <?php selected( (int) $current_id, (int) $city['id'] ); ?>>
								<?php echo esc_html( $city['name'] ); ?>
							</option>
							<?php if ( ! empty( $city['children'] ) ) : ?>
								<?php foreach ( $city['children'] as $district ) : ?>
									<option value="<?php echo esc_attr( $district['slug'] ); ?>" data-id="<?php echo esc_attr( $district['id'] ); ?>" <?php selected( (int) $current_id, (int) $district['id'] ); ?>>
										<?php echo "\u{00A0}\u{00A0}\u{2014} "; ?><?php echo esc_html( $district['name'] ); ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>
	</div>
</div>

		<!-- Поиск (только десктоп) с live-автодополнением -->
		<?php
		$idpg_search_slug = class_exists( 'IDPG_City_Filter' ) ? IDPG_City_Filter::get_current_city_slug() : 'izhevsk';
		$idpg_search_action = $idpg_search_slug ? home_url( user_trailingslashit( '/afisha/' . $idpg_search_slug . '/search/' ) ) : home_url( '/' );
		?>
		<form class="idpg-search idpg-search--live" role="search" method="get" action="<?php echo esc_url( $idpg_search_action ); ?>" data-idpg-live-search>
			<label class="idpg-search__label" for="idpg-search-input">
				<span class="sr-only"><?php esc_html_e( 'Поиск', 'idpg_core' ); ?></span>
			</label>
			<svg class="idpg-search__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<circle cx="11" cy="11" r="8"></circle>
				<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
			</svg>
			<input
				class="idpg-search__input"
				id="idpg-search-input"
				type="search"
				name="s"
				placeholder="<?php esc_attr_e( 'Поиск событий...', 'idpg_core' ); ?>"
				value="<?php echo esc_attr( get_search_query() ); ?>"
				autocomplete="off"
				data-idpg-live-input
			>
			<div class="idpg-search__dropdown" id="idpg-search-dropdown" hidden></div>
		</form>

		<!-- Выбор города (вне actions, чтобы не скрывался на мобилке) -->
		<?php /* $city_tree, $current_id и $idpg_city_ipnote вычисляются в начале шапки. */ ?>
		<div class="idpg-city-selector" id="idpg-city-dropdown">
			<button
				type="button"
				class="idpg-city-selector__btn"
				id="idpg-city-toggle"
				aria-haspopup="true"
				aria-expanded="false"
				aria-controls="idpg-city-panel"
				aria-label="<?php esc_attr_e( 'Выбрать город', 'idpg_core' ); ?>"
			>
				<svg class="idpg-city-selector__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
					<circle cx="12" cy="10" r="3"></circle>
				</svg>
				<span class="idpg-city-selector__name"><?php echo esc_html( $current_city_name ); ?></span>
				<svg class="idpg-city-selector__arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<polyline points="6 9 12 15 18 9"></polyline>
				</svg>
			</button>

		<div class="idpg-city-selector__panel" id="idpg-city-panel" hidden>
			<?php echo $idpg_city_ipnote; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php if ( ! empty( $city_tree ) ) : ?>
					<label class="idpg-city-selector__select-label" for="idpg-city-select">
						<?php esc_html_e( 'Выберите город', 'idpg_core' ); ?>
					</label>
					<select class="idpg-city-selector__select" id="idpg-city-select" data-idpg-city-header>
						<?php foreach ( $city_tree as $region ) : ?>
							<?php if ( empty( $region['children'] ) ) : ?>
								<option value="<?php echo esc_attr( $region['slug'] ); ?>" data-id="<?php echo esc_attr( $region['id'] ); ?>" <?php selected( (int) $current_id, (int) $region['id'] ); ?>>
									<?php echo esc_html( $region['name'] ); ?>
								</option>
								<?php continue; ?>
							<?php endif; ?>
							<optgroup label="<?php echo esc_attr( $region['name'] ); ?>">
								<?php foreach ( $region['children'] as $city ) : ?>
									<option value="<?php echo esc_attr( $city['slug'] ); ?>" data-id="<?php echo esc_attr( $city['id'] ); ?>" <?php selected( (int) $current_id, (int) $city['id'] ); ?>>
										<?php echo esc_html( $city['name'] ); ?>
									</option>
									<?php if ( ! empty( $city['children'] ) ) : ?>
										<?php foreach ( $city['children'] as $district ) : ?>
											<option value="<?php echo esc_attr( $district['slug'] ); ?>" data-id="<?php echo esc_attr( $district['id'] ); ?>" <?php selected( (int) $current_id, (int) $district['id'] ); ?>>
												<?php echo "\u{00A0}\u{00A0}\u{2014} "; ?><?php echo esc_html( $district['name'] ); ?>
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<p class="idpg-city-selector__empty">
						<?php esc_html_e( 'Города ещё не добавлены.', 'idpg_core' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<?php /* DEV-19: видимый без открытия панели вопрос «это ваш город?». */ ?>
		<?php echo $idpg_city_confirm_panel; // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<!-- Блок действий -->
		<div class="idpg-header__actions">

		<!-- Выпадающее меню авторизации -->
		<div class="idpg-auth-dropdown" id="idpg-auth-dropdown">
			<button class="idpg-btn idpg-btn--secondary idpg-auth-dropdown__toggle" id="idpg-auth-toggle" aria-haspopup="true" aria-expanded="false">
				<span class="idpg-auth-dropdown__label"><?php esc_html_e( 'Добавить событие', 'idpg_core' ); ?></span>
				<svg class="idpg-auth-dropdown__arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<polyline points="6 9 12 15 18 9"></polyline>
				</svg>
			</button>
			<ul class="idpg-auth-dropdown__menu">
				<?php if ( is_user_logged_in() ) : ?>
					<?php $current_user = wp_get_current_user(); ?>
					<li class="idpg-auth-dropdown__user-info">
						<?php echo esc_html( $current_user->display_name ); ?>
					</li>
					<li class="idpg-auth-dropdown__divider"></li>
					<?php if ( array_intersect( array( 'administrator', 'event_organizer' ), (array) $current_user->roles ) ) : ?>
						<li>
							<a href="<?php echo esc_url( admin_url() ); ?>" class="idpg-auth-dropdown__link idpg-auth-dropdown__link--panel">
								<?php esc_html_e( 'Панель', 'idpg_core' ); ?>
							</a>
						</li>
						<li class="idpg-auth-dropdown__divider"></li>
					<?php endif; ?>
					<?php if ( class_exists( 'IDPG_Favorites' ) ) : ?>
						<li>
							<a href="<?php echo esc_url( home_url( '/lk/favorites/' ) ); ?>" class="idpg-auth-dropdown__link idpg-fav-counter">
								<?php esc_html_e( 'Избранное', 'idpg_core' ); ?> ❤ <span class="idpg-fav-counter__num"><?php echo esc_html( IDPG_Favorites::count() ); ?></span>
							</a>
						</li>
						<li class="idpg-auth-dropdown__divider"></li>
					<?php endif; ?>
					<li>
						<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="idpg-auth-dropdown__link idpg-auth-dropdown__link--logout">
							<?php esc_html_e( 'Выйти', 'idpg_core' ); ?>
						</a>
					</li>
				<?php else : ?>
					<li>
						<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="idpg-auth-dropdown__link idpg-auth-dropdown__link--login">
							<?php esc_html_e( 'Войти / Регистрация', 'idpg_core' ); ?>
						</a>
					</li>
				<?php endif; ?>
				</ul>
			</div>

		</div>

		<!-- Меню (мобильные) — вне actions, чтобы не скрывался на мобилке -->
		<button
			type="button"
			class="idpg-menu-toggle"
			id="idpg-menu-toggle"
			aria-expanded="false"
			aria-controls="idpg-nav"
			aria-label="<?php esc_attr_e( 'Открыть меню', 'idpg_core' ); ?>"
		>
			<span class="idpg-menu-toggle__line"></span>
			<span class="idpg-menu-toggle__line"></span>
			<span class="idpg-menu-toggle__line"></span>
		</button>

	</div>
</header>

<!-- Оверлей поиска (мобильные) -->
<div class="idpg-search-overlay" id="idpg-search-overlay">
	<div class="idpg-search-overlay__inner">
		<button type="button" class="idpg-search-overlay__close" id="idpg-search-overlay-close" aria-label="<?php esc_attr_e( 'Закрыть поиск', 'idpg_core' ); ?>">
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
		</button>
		<form class="idpg-search-overlay__form idpg-search--live" role="search" method="get" action="<?php echo esc_url( $idpg_search_action ); ?>" data-idpg-live-search>
			<svg class="idpg-search-overlay__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<circle cx="11" cy="11" r="8"></circle>
				<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
			</svg>
			<input
				class="idpg-search-overlay__input"
				id="idpg-search-overlay-input"
				type="search"
				name="s"
				placeholder="<?php esc_attr_e( 'Поиск событий...', 'idpg_core' ); ?>"
				value="<?php echo esc_attr( get_search_query() ); ?>"
				autocomplete="off"
				data-idpg-live-input
			>
			<div class="idpg-search__dropdown" id="idpg-search-overlay-dropdown" hidden></div>
		</form>
	</div>
</div>

<!-- Мобильный нижний бар (фиксирован, виден только на ≤1023px) -->
<nav class="idpg-bottom-bar" id="idpg-bottom-bar" aria-label="<?php esc_attr_e( 'Навигация', 'idpg_core' ); ?>">
	<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="idpg-bottom-bar__item" aria-label="<?php esc_attr_e( 'Главная', 'idpg_core' ); ?>">
		<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
		<span><?php esc_html_e( 'Главная', 'idpg_core' ); ?></span>
	</a>
	<button type="button" class="idpg-bottom-bar__item" id="idpg-bottom-search" aria-label="<?php esc_attr_e( 'Поиск', 'idpg_core' ); ?>">
		<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
		<span><?php esc_html_e( 'Поиск', 'idpg_core' ); ?></span>
	</button>
	<button type="button" class="idpg-bottom-bar__item" id="idpg-bottom-city" aria-label="<?php esc_attr_e( 'Город', 'idpg_core' ); ?>">
		<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
		<span><?php echo esc_html( $current_city_name ); ?></span>
	</button>
</nav>

<?php
$idpg_breadcrumbs_html = idpg_core_breadcrumbs();
if ( $idpg_breadcrumbs_html ) {
	echo '<div class="container idpg-breadcrumbs-wrap">' . $idpg_breadcrumbs_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}
?>

<?php idpg_widget_areas_render( 'below-header' ); ?>
