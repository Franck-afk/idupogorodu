<?php
/**
 * Widget areas manager for idpg_core.
 *
 * Позволяет создавать области виджетов, задавать им место вывода на фронте
 * (под шапкой, над/под контентом, перед футером) и условия показа
 * (везде, только главная, конкретные страницы, архивы и т.д.).
 * Сами виджеты добавляются на стандартном экране «Виджеты».
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Склонение существительного по количеству.
 *
 * @param int    $number Число.
 * @param string $one    Форма для 1.
 * @param string $few    Форма для 2-4.
 * @param string $many   Форма для 5+.
 * @return string
 */
function idpg_widget_areas_plural( $number, $one, $few, $many ) {
	$number = absint( $number );
	$mod10  = $number % 10;
	$mod100 = $number % 100;

	if ( 11 <= $mod100 && 19 >= $mod100 ) {
		return $many;
	}
	if ( 1 === $mod10 ) {
		return $one;
	}
	if ( 2 <= $mod10 && 4 >= $mod10 ) {
		return $few;
	}
	return $many;
}

/**
 * Зарегистрированные позиции (места вывода) областей на фронте.
 *
 * @return array
 */
function idpg_widget_areas_positions() {
	return array(
		'below-header'  => __( 'Сразу под шапкой', 'idpg_core' ),
		'above-content' => __( 'Над контентом', 'idpg_core' ),
		'below-content' => __( 'Под контентом', 'idpg_core' ),
		'before-footer' => __( 'Перед футером', 'idpg_core' ),
	);
}

/**
 * Варианты условий показа областей.
 *
 * @return array
 */
function idpg_widget_areas_display_options() {
	return array(
		'all'      => __( 'Везде', 'idpg_core' ),
		'front'    => __( 'Только главная', 'idpg_core' ),
		'page'     => __( 'Все страницы', 'idpg_core' ),
		'post'     => __( 'Все записи', 'idpg_core' ),
		'archive'  => __( 'Архивы', 'idpg_core' ),
		'specific' => __( 'Конкретные страницы', 'idpg_core' ),
	);
}

/**
 * Получить все области виджетов из опции.
 *
 * @return array
 */
function idpg_widget_areas_all() {
	$areas = get_option( 'idpg_widget_areas', array() );
	return is_array( $areas ) ? $areas : array();
}

/**
 * Транслитерация кириллицы в латиницу для slug.
 *
 * @param string $text Строка.
 * @return string
 */
function idpg_widget_areas_slugify( $text ) {
	$text = mb_strtolower( (string) $text, 'UTF-8' );

	$map = array(
		'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
		'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
		'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
		'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
		'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
	);
	$text = strtr( $text, $map );

	$text = preg_replace( '/[^a-z0-9\-]+/', '-', $text );
	$text = preg_replace( '/-+/', '-', $text );
	$text = trim( $text, '-' );

	return $text ? $text : 'area';
}

/**
 * Регистрирует области виджетов как стандартные сайдбары WP.
 */
function idpg_widget_areas_register_sidebars() {
	foreach ( idpg_widget_areas_all() as $slug => $area ) {
		if ( empty( $area['title'] ) ) {
			continue;
		}

		$positions = idpg_widget_areas_positions();
		$displays  = idpg_widget_areas_display_options();
		$position  = isset( $area['position'] ) && isset( $positions[ $area['position'] ] ) ? $area['position'] : 'below-header';
		$display   = isset( $area['display'] ) && isset( $displays[ $area['display'] ] ) ? $area['display'] : 'all';

		$description = sprintf(
			/* translators: 1: позиция, 2: условие показа. */
			__( 'Область виджетов IDPG. Место: %1$s. Показ: %2$s.', 'idpg_core' ),
			$positions[ $position ],
			$displays[ $display ]
		);

		register_sidebar(
			array(
				'name'          => $area['title'],
				'id'            => 'idpg-area-' . sanitize_key( $slug ),
				'description'   => $description,
				'before_widget' => '<section id="%1$s" class="widget %2$s idpg-widget-area__widget">',
				'after_widget'  => '</section>',
				'before_title'  => '<h3 class="widget-title idpg-widget-area__title">',
				'after_title'   => '</h3>',
			)
		);
	}
}
add_action( 'widgets_init', 'idpg_widget_areas_register_sidebars', 30 );

/**
 * Проверяет, нужно ли показывать область на текущей странице.
 *
 * @param array $area Данные области.
 * @return bool
 */
function idpg_widget_areas_matches( $area ) {
	$display = isset( $area['display'] ) ? $area['display'] : 'all';

	switch ( $display ) {
		case 'front':
			return is_front_page();

		case 'page':
			return is_page();

		case 'post':
			// Охватывает записи и все кастомные типы (event, venue и т.д.), но не страницы.
			return is_singular() && ! is_page();

		case 'archive':
			return is_archive() || is_home() || is_search();

		case 'specific':
			$pages = isset( $area['pages'] ) ? array_map( 'intval', (array) $area['pages'] ) : array();
			if ( empty( $pages ) ) {
				return false;
			}
			return is_page() && in_array( (int) get_queried_object_id(), $pages, true );

		case 'all':
		default:
			return true;
	}
}

/**
 * Выводит области виджетов заданной позиции, подходящие под текущую страницу.
 *
 * @param string $position Ключ позиции.
 */
function idpg_widget_areas_render( $position ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}

	foreach ( idpg_widget_areas_all() as $slug => $area ) {
		if ( empty( $area['position'] ) || $area['position'] !== $position ) {
			continue;
		}
		if ( ! idpg_widget_areas_matches( $area ) ) {
			continue;
		}

		$sidebar_id = 'idpg-area-' . sanitize_key( $slug );
		if ( is_active_sidebar( $sidebar_id ) ) {
			echo '<div class="idpg-widget-area idpg-widget-area--' . esc_attr( $position ) . '">';
			if ( in_array( $position, array( 'below-header', 'before-footer' ), true ) ) {
				echo '<div class="container">';
			}
			dynamic_sidebar( $sidebar_id );
			if ( in_array( $position, array( 'below-header', 'before-footer' ), true ) ) {
				echo '</div>';
			}
			echo '</div>';
		}
	}
}

/**
 * Регистрирует страницу админки «Области виджетов».
 */
function idpg_widget_areas_admin_menu() {
	add_submenu_page(
		'themes.php',
		__( 'Области виджетов', 'idpg_core' ),
		__( 'Области виджетов', 'idpg_core' ),
		'manage_options',
		'idpg-widget-areas',
		'idpg_widget_areas_admin_page'
	);
}
add_action( 'admin_menu', 'idpg_widget_areas_admin_menu' );

/**
 * Подключает стили только на странице «Области виджетов».
 *
 * @param string $hook_suffix Текущий хук экрана.
 */
function idpg_widget_areas_admin_assets( $hook_suffix ) {
	if ( 'appearance_page_idpg-widget-areas' !== $hook_suffix ) {
		return;
	}
	wp_enqueue_style(
		'idpg-widget-areas-admin',
		get_template_directory_uri() . '/assets/css/widget-areas-admin.css',
		array(),
		IDPG_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'idpg_widget_areas_admin_assets' );

/**
 * Обработка сохранения / удаления областей.
 */
function idpg_widget_areas_admin_handle() {
	if ( ! isset( $_POST['idpg_widget_areas_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( $_POST['idpg_widget_areas_nonce'], 'idpg_widget_areas_action' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$areas     = idpg_widget_areas_all();
	$action    = isset( $_POST['idpg_area_action'] ) ? sanitize_key( $_POST['idpg_area_action'] ) : 'add';
	$slug      = isset( $_POST['idpg_area_slug'] ) ? sanitize_key( $_POST['idpg_area_slug'] ) : '';
	$is_update = ( $slug && isset( $areas[ $slug ] ) );

	if ( 'delete' === $action ) {
		if ( $slug && isset( $areas[ $slug ] ) ) {
			unset( $areas[ $slug ] );
			update_option( 'idpg_widget_areas', $areas );

			// Убираем сайдбар из sidebars_widgets, иначе он остаётся
			// на экране «Виджеты» как осиротевший блок «Inactive Sidebar (not used)».
			$sidebar_id        = 'idpg-area-' . sanitize_key( $slug );
			$sidebars_widgets  = get_option( 'sidebars_widgets', array() );

			if ( isset( $sidebars_widgets[ $sidebar_id ] ) ) {
				$widgets = is_array( $sidebars_widgets[ $sidebar_id ] ) ? $sidebars_widgets[ $sidebar_id ] : array();

				if ( $widgets ) {
					$inactive = isset( $sidebars_widgets['wp_inactive_widgets'] ) && is_array( $sidebars_widgets['wp_inactive_widgets'] )
						? $sidebars_widgets['wp_inactive_widgets']
						: array();
					$sidebars_widgets['wp_inactive_widgets'] = array_merge( $inactive, $widgets );
				}

				unset( $sidebars_widgets[ $sidebar_id ] );
				update_option( 'sidebars_widgets', $sidebars_widgets );
			}
		}
		wp_safe_redirect( admin_url( 'themes.php?page=idpg-widget-areas&idpg_msg=deleted' ) );
		exit;
	}

	$title = isset( $_POST['idpg_area_title'] ) ? sanitize_text_field( wp_unslash( $_POST['idpg_area_title'] ) ) : '';
	if ( '' === $title ) {
		wp_safe_redirect( admin_url( 'themes.php?page=idpg-widget-areas&idpg_msg=empty' ) );
		exit;
	}

	if ( $is_update ) {
		$key = $slug;
	} else {
		$key = 'area-' . idpg_widget_areas_slugify( $title );
		$key = sanitize_key( $key );

		// Гарантируем уникальность без случайных компонентов.
		$candidate = $key;
		$counter   = 2;
		while ( isset( $areas[ $candidate ] ) ) {
			$candidate = $key . '-' . $counter;
			$counter++;
		}
		$key = $candidate;
	}

	$positions = idpg_widget_areas_positions();
	$displays  = idpg_widget_areas_display_options();
	$position  = isset( $_POST['idpg_area_position'] ) ? sanitize_key( $_POST['idpg_area_position'] ) : 'below-header';
	$display   = isset( $_POST['idpg_area_display'] ) ? sanitize_key( $_POST['idpg_area_display'] ) : 'all';

	if ( ! isset( $positions[ $position ] ) ) {
		$position = 'below-header';
	}
	if ( ! isset( $displays[ $display ] ) ) {
		$display = 'all';
	}

	$pages = array();
	if ( isset( $_POST['idpg_area_pages'] ) && is_array( $_POST['idpg_area_pages'] ) ) {
		$pages = array_values( array_unique( array_filter( array_map( 'intval', $_POST['idpg_area_pages'] ) ) ) );
	}

	$areas[ $key ] = array(
		'title'    => $title,
		'position' => $position,
		'display'  => $display,
		'pages'    => $pages,
	);

	update_option( 'idpg_widget_areas', $areas );

	$msg = $is_update ? 'updated' : 'added';
	wp_safe_redirect( admin_url( 'themes.php?page=idpg-widget-areas&idpg_msg=' . $msg ) );
	exit;
}
add_action( 'admin_init', 'idpg_widget_areas_admin_handle' );

/**
 * Выводит кастомный селект (скрытый input + кнопка + список опций).
 *
 * @param string $id       ID и name скрытого поля.
 * @param string $selected Текущее значение.
 * @param array  $options  Значение => метка.
 */
function idpg_widget_areas_select( $id, $selected, $options ) {
	?>
	<div class="idpg-wa-select" data-idpg-select>
		<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $selected ); ?>">
		<button type="button" class="idpg-wa-select__toggle" aria-haspopup="listbox" aria-expanded="false">
			<span class="idpg-wa-select__label"><?php echo esc_html( isset( $options[ $selected ] ) ? $options[ $selected ] : '' ); ?></span>
			<span class="dashicons dashicons-arrow-down-alt2 idpg-wa-select__arrow" aria-hidden="true"></span>
		</button>
		<ul class="idpg-wa-select__menu" role="listbox">
			<?php foreach ( $options as $value => $label ) : ?>
				<li class="idpg-wa-select__option<?php echo $value === $selected ? ' is-selected' : ''; ?>"
					role="option" aria-selected="<?php echo $value === $selected ? 'true' : 'false'; ?>"
					data-value="<?php echo esc_attr( $value ); ?>">
					<span class="dashicons dashicons-yes idpg-wa-select__check" aria-hidden="true"></span>
					<?php echo esc_html( $label ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}

/**
 * Вывод страницы админки «Области виджетов».
 */
function idpg_widget_areas_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$areas      = idpg_widget_areas_all();
	$positions  = idpg_widget_areas_positions();
	$displays   = idpg_widget_areas_display_options();
	$edit_slug  = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
	$editing    = ( $edit_slug && isset( $areas[ $edit_slug ] ) ) ? $areas[ $edit_slug ] : null;
	$msg        = isset( $_GET['idpg_msg'] ) ? sanitize_key( wp_unslash( $_GET['idpg_msg'] ) ) : '';

	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	?>
	<div class="wrap idpg-wa-wrap">
		<div class="idpg-wa-header">
			<span class="dashicons-before dashicons-welcome-widgets-menus" aria-hidden="true"></span>
			<h1><?php esc_html_e( 'Области виджетов', 'idpg_core' ); ?></h1>
		</div>

		<?php if ( $msg ) : ?>
			<div id="message" class="notice notice-success is-dismissible">
				<p>
					<?php
					switch ( $msg ) {
						case 'added':
							esc_html_e( 'Область добавлена.', 'idpg_core' );
							break;
						case 'updated':
							esc_html_e( 'Область обновлена.', 'idpg_core' );
							break;
						case 'deleted':
							esc_html_e( 'Область удалена.', 'idpg_core' );
							break;
						case 'empty':
							esc_html_e( 'Не указано название области.', 'idpg_core' );
							break;
						default:
							break;
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<div class="idpg-wa-intro">
			<p>
				<?php
				printf(
					/* translators: %s: URL страницы виджетов. */
					wp_kses_post( __( 'Области появляются на экране <a href="%s">Виджеты</a> — туда добавляются сами виджеты.', 'idpg_core' ) ),
					esc_url( admin_url( 'widgets.php' ) )
				);
				?>
			</p>
		</div>

		<div class="idpg-wa-card">
			<div class="idpg-wa-card__head">
				<span class="dashicons dashicons-plus-alt" aria-hidden="true"></span>
				<h2 class="idpg-wa-card__title">
					<?php echo $editing ? esc_html__( 'Редактировать область', 'idpg_core' ) : esc_html__( 'Добавить область', 'idpg_core' ); ?>
				</h2>
			</div>

			<form class="idpg-wa-form" method="post" action="">
				<?php wp_nonce_field( 'idpg_widget_areas_action', 'idpg_widget_areas_nonce' ); ?>
				<input type="hidden" name="idpg_area_action" value="<?php echo $editing ? 'update' : 'add'; ?>">
				<?php if ( $editing ) : ?>
					<input type="hidden" name="idpg_area_slug" value="<?php echo esc_attr( $edit_slug ); ?>">
				<?php endif; ?>

				<div class="idpg-wa-card__body">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="idpg_area_title"><?php esc_html_e( 'Название', 'idpg_core' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="idpg_area_title" name="idpg_area_title"
									value="<?php echo esc_attr( ( $editing && isset( $editing['title'] ) ) ? $editing['title'] : '' ); ?>" required>
								<p class="description"><?php esc_html_e( 'Отображается на экране «Виджеты» и над областью на фронте.', 'idpg_core' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="idpg_area_position"><?php esc_html_e( 'Место на фронте', 'idpg_core' ); ?></label></th>
							<td>
								<?php idpg_widget_areas_select( 'idpg_area_position', ( $editing && isset( $editing['position'] ) ) ? $editing['position'] : 'below-header', $positions ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="idpg_area_display"><?php esc_html_e( 'Показывать', 'idpg_core' ); ?></label></th>
							<td>
								<?php idpg_widget_areas_select( 'idpg_area_display', ( $editing && isset( $editing['display'] ) ) ? $editing['display'] : 'all', $displays ); ?>
							</td>
						</tr>
						<tr id="idpg_area_pages_row">
							<th scope="row"><?php esc_html_e( 'Страницы', 'idpg_core' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Страницы для показа', 'idpg_core' ); ?></legend>
									<?php if ( $pages ) : ?>
										<?php
										$pages_for_edit = isset( $editing['pages'] ) ? $editing['pages'] : array();
									$selected_pages = $editing ? array_map( 'intval', (array) $pages_for_edit ) : array();
										?>
										<ul class="idpg-wa-pages">
											<?php foreach ( $pages as $page ) : ?>
												<li>
													<label class="idpg-wa-pages__label" data-checked="idpg-wa-page-<?php echo esc_attr( $page->ID ); ?>">
														<input type="checkbox" name="idpg_area_pages[]" value="<?php echo esc_attr( $page->ID ); ?>"
															id="idpg-wa-page-<?php echo esc_attr( $page->ID ); ?>"
															<?php checked( in_array( (int) $page->ID, $selected_pages, true ), true ); ?>>
														<span><?php echo esc_html( get_the_title( $page ) ); ?></span>
													</label>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php else : ?>
										<p class="description"><?php esc_html_e( 'Страниц пока нет.', 'idpg_core' ); ?></p>
									<?php endif; ?>
									<p class="description"><?php esc_html_e( 'Выберите страницы, если условие показа — «Конкретные страницы».', 'idpg_core' ); ?></p>
								</fieldset>
							</td>
						</tr>
					</table>
				</div>

				<div class="idpg-wa-form__actions">
					<?php submit_button( $editing ? __( 'Сохранить область', 'idpg_core' ) : __( 'Добавить область', 'idpg_core' ), 'primary', 'submit', false ); ?>
					<?php if ( $editing ) : ?>
						<a class="idpg-wa-cancel button" href="<?php echo esc_url( admin_url( 'themes.php?page=idpg-widget-areas' ) ); ?>">
							<?php esc_html_e( 'Отмена', 'idpg_core' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</form>
		</div>

		<h2 class="title"><?php esc_html_e( 'Существующие области', 'idpg_core' ); ?></h2>

		<?php if ( empty( $areas ) ) : ?>
			<div class="idpg-wa-areas__empty">
				<span class="dashicons dashicons-layout" aria-hidden="true"></span>
				<p><?php esc_html_e( 'Областей пока нет. Создайте первую выше.', 'idpg_core' ); ?></p>
			</div>
		<?php else : ?>
			<div class="idpg-wa-areas">
				<?php foreach ( $areas as $slug => $area ) : ?>
					<?php
					$area_position  = ( isset( $area['position'] ) && isset( $positions[ $area['position'] ] ) ) ? $area['position'] : 'below-header';
					$area_display   = ( isset( $area['display'] ) && isset( $displays[ $area['display'] ] ) ) ? $area['display'] : 'all';
					$position_label = $positions[ $area_position ];
					$display_label  = $displays[ $area_display ];
					$has_pages      = ( 'specific' === $area_display && ! empty( $area['pages'] ) );
					?>
					<div class="idpg-wa-area">
						<div class="idpg-wa-area__icon">
							<span class="dashicons dashicons-welcome-widgets-menus" aria-hidden="true"></span>
						</div>
						<div class="idpg-wa-area__main">
							<h3 class="idpg-wa-area__title"><?php echo esc_html( $area['title'] ); ?></h3>
							<div class="idpg-wa-area__meta">
								<span class="idpg-wa-badge idpg-wa-badge--position">
									<span class="dashicons dashicons-location" aria-hidden="true"></span>
									<?php echo esc_html( $position_label ); ?>
								</span>
								<span class="idpg-wa-badge idpg-wa-badge--display">
									<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									<?php echo esc_html( $display_label ); ?>
								</span>
								<?php if ( $has_pages ) : ?>
									<?php
									$page_count = count( $area['pages'] );
									$page_word  = idpg_widget_areas_plural( $page_count, __( 'страница', 'idpg_core' ), __( 'страницы', 'idpg_core' ), __( 'страниц', 'idpg_core' ) );
									?>
									<button type="button" class="idpg-wa-badge idpg-wa-badge--pages idpg-widget-areas-toggle-pages" data-target="pages-<?php echo esc_attr( $slug ); ?>">
										<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
										<?php echo esc_html( $page_count . ' ' . $page_word ); ?>
									</button>
									<div id="pages-<?php echo esc_attr( $slug ); ?>" class="idpg-wa-pages-list">
										<?php
										foreach ( $area['pages'] as $pid ) {
											$ptitle = get_the_title( $pid );
											if ( $ptitle ) {
												echo esc_html( $ptitle ) . '<br>';
											}
										}
										?>
									</div>
								<?php endif; ?>
							</div>
						</div>
						<div class="idpg-wa-area__actions">
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'themes.php?page=idpg-widget-areas&edit=' . $slug ) ); ?>">
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
								<?php esc_html_e( 'Редактировать', 'idpg_core' ); ?>
							</a>
							<form method="post" action="">
								<input type="hidden" id="idpg_widget_areas_nonce_delete_<?php echo esc_attr( $slug ); ?>" name="idpg_widget_areas_nonce" value="<?php echo esc_attr( wp_create_nonce( 'idpg_widget_areas_action' ) ); ?>">
								<input type="hidden" name="idpg_area_action" value="delete">
								<input type="hidden" name="idpg_area_slug" value="<?php echo esc_attr( $slug ); ?>">
								<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Удалить область?', 'idpg_core' ) ); ?>');">
									<span class="dashicons dashicons-trash" aria-hidden="true"></span>
									<?php esc_html_e( 'Удалить', 'idpg_core' ); ?>
								</button>
							</form>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<script>
	(function () {
		'use strict';

		document.querySelectorAll('.idpg-wa-select[data-idpg-select]').forEach(function (wrap) {
			var input   = wrap.querySelector('input[type="hidden"]');
			var toggle  = wrap.querySelector('.idpg-wa-select__toggle');
			var menu    = wrap.querySelector('.idpg-wa-select__menu');
			var label   = wrap.querySelector('.idpg-wa-select__label');
			var options = Array.prototype.slice.call(wrap.querySelectorAll('.idpg-wa-select__option'));

			function setSelected(value) {
				var current = null;
				options.forEach(function (opt) {
					var isSel = opt.getAttribute('data-value') === value;
					opt.classList.toggle('is-selected', isSel);
					opt.setAttribute('aria-selected', isSel ? 'true' : 'false');
					if (isSel) { current = opt; }
				});
				if (current) { label.textContent = current.textContent.trim(); }
				input.value = value;
				input.dispatchEvent(new Event('change', { bubbles: true }));
			}

			function close() {
				wrap.classList.remove('is-open');
				toggle.setAttribute('aria-expanded', 'false');
			}

			toggle.addEventListener('click', function (e) {
				e.stopPropagation();
				var isOpen = wrap.classList.toggle('is-open');
				toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			});

			menu.addEventListener('click', function (e) {
				var opt = e.target.closest('.idpg-wa-select__option');
				if (!opt) { return; }
				setSelected(opt.getAttribute('data-value'));
				close();
			});

			document.addEventListener('click', close);
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') { close(); }
			});
		});

		var display = document.getElementById('idpg_area_display');
		var row = document.getElementById('idpg_area_pages_row');
		function toggle() {
			if (row) { row.style.display = (display && display.value === 'specific') ? '' : 'none'; }
		}
		if (display) { display.addEventListener('change', toggle); }
		toggle();
		document.addEventListener('click', function (e) {
			var t = e.target;
			if (t && t.classList.contains('idpg-widget-areas-toggle-pages')) {
				var el = document.getElementById(t.getAttribute('data-target'));
				if (el) { el.classList.toggle('is-open'); }
			}
		});
		document.querySelectorAll('.idpg-wa-pages__label').forEach(function (label) {
			var cb = label.querySelector('input[type="checkbox"]');
			function sync() {
				label.classList.toggle('is-checked', cb.checked);
			}
			if (cb) { cb.addEventListener('change', sync); sync(); }
		});
	})();
	</script>
	<?php
}

/**
 * Добавляет ссылку на страницу «Области виджетов» на экран «Виджеты».
 */
function idpg_widget_areas_widgets_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'widgets' !== $screen->id ) {
		return;
	}
	?>
	<div class="notice notice-info is-dismissible" style="margin-top:16px;">
		<p>
			<?php
			printf(
				/* translators: %s: URL страницы областей. */
				wp_kses_post( __( 'Здесь находятся все области виджетов. Управлять местами вывода и условиями показа можно на странице <a href="%s">Области виджетов</a>.', 'idpg_core' ) ),
				esc_url( admin_url( 'themes.php?page=idpg-widget-areas' ) )
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'idpg_widget_areas_widgets_notice' );
