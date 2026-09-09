<?php
/**
 * Админ-интерфейс модуля импорта.
 *
 * Подменю страницы «Иду по городу» плагина idpg-core: список источников,
 * CRUD, ручной запуск импорта (admin-post + nonce), последняя статистика.
 *
 * @package Idpg_Event_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Import_Admin
 */
class IDPG_Import_Admin {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Import_Admin|null
	 */
	private static $instance = null;

	/**
	 * Slug страницы.
	 */
	const PAGE_SLUG = 'idpgei-sources';

	/**
	 * Slug страницы документации.
	 */
	const DOCS_SLUG = 'idpgei-docs';

	/**
	 * Действие ручного запуска.
	 */
	const RUN_ACTION = 'idpgei_run_now';

	/**
	 * NONCE-действие.
	 */
	const NONCE_ACTION = 'idpgei_admin';

	/**
	 * Синглтон.
	 *
	 * @return IDPG_Import_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Конструктор: хуки.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_' . self::RUN_ACTION, array( $this, 'handle_run_now' ) );
		add_action( 'admin_post_idpgei_save_source', array( $this, 'handle_save_source' ) );
		add_action( 'admin_post_idpgei_delete_source', array( $this, 'handle_delete_source' ) );
		add_action( 'admin_init', array( $this, 'maybe_serve_raw_readme' ) );
	}

	/**
	 * Отдать README.md как текст, если запрошена страница документации с ?raw=1.
	 *
	 * @return void
	 */
	public function maybe_serve_raw_readme() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( self::DOCS_SLUG !== $page ) {
			return;
		}

		$raw = isset( $_GET['raw'] ) ? sanitize_key( wp_unslash( $_GET['raw'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '1' === $raw ) {
			$this->serve_raw_readme();
		}
	}

	/**
	 * Отдельный пункт главного меню (не подменю idpg-core).
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Импорт событий', 'idpg-event-import' ),
			__( 'Импорт событий', 'idpg-event-import' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-calendar-alt',
			30
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Документация', 'idpg-event-import' ),
			__( 'Документация', 'idpg-event-import' ),
			'manage_options',
			self::DOCS_SLUG,
			array( $this, 'render_docs_page' )
		);
	}

	/**
	 * URL страницы.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * URL страницы документации.
	 *
	 * @return string
	 */
	public static function docs_url() {
		return admin_url( 'admin.php?page=' . self::DOCS_SLUG );
	}

	/**
	 * Рендер страницы.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'idpg-event-import' ) );
		}

		$importer = IDPG_Event_Import::instance();
		$sources = $importer->get_sources();

		// Flash-сообщение после редиректа.
		$msg = isset( $_GET['idpgei_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['idpgei_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing = $edit ? $importer->get_source( $edit ) : null;
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Импорт событий с площадок партнёров', 'idpg-event-import' ); ?>
				<a class="page-title-action" href="<?php echo esc_url( self::docs_url() ); ?>">
					<?php esc_html_e( 'Документация', 'idpg-event-import' ); ?>
				</a>
			</h1>

			<?php if ( $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Источники', 'idpg-event-import' ); ?></h2>

			<?php if ( empty( $sources ) ) : ?>
				<p><?php esc_html_e( 'Источников пока нет. Добавьте первый ниже.', 'idpg-event-import' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Название', 'idpg-event-import' ); ?></th>
							<th><?php esc_html_e( 'Тип', 'idpg-event-import' ); ?></th>
							<th><?php esc_html_e( 'Публикация', 'idpg-event-import' ); ?></th>
							<th><?php esc_html_e( 'Частота', 'idpg-event-import' ); ?></th>
							<th><?php esc_html_e( 'Последний прогон', 'idpg-event-import' ); ?></th>
							<th><?php esc_html_e( 'Статистика', 'idpg-event-import' ); ?></th>
							<th><?php esc_html_e( 'Действия', 'idpg-event-import' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sources as $sid => $source ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $source['name'] ); ?></strong>
									<code><?php echo esc_html( $sid ); ?></code>
								</td>
								<td><?php echo esc_html( 'rest' === $source['provider'] ? 'REST' : 'iCal' ); ?></td>
								<td><?php echo esc_html( 'publish' === $source['post_status'] ? __( 'сразу публиковать', 'idpg-event-import' ) : __( 'черновик', 'idpg-event-import' ) ); ?></td>
								<td><?php echo esc_html( human_time_diff( 0, (int) $source['schedule'] ) ); ?></td>
								<td>
									<?php
									if ( ! empty( $source['last_run'] ) ) {
										echo esc_html( gmdate( 'd.m.Y H:i', (int) $source['last_run'] ) );
										echo ' — ';
										echo 'ok' === $source['last_status']
											? '<span style="color:#00a32a">' . esc_html__( 'успех', 'idpg-event-import' ) . '</span>'
											: '<span style="color:#d63638">' . esc_html__( 'ошибка', 'idpg-event-import' ) . '</span>';
									} else {
										esc_html_e( 'ещё не запускался', 'idpg-event-import' );
									}
									?>
								</td>
								<td>
									<?php
									if ( ! empty( $source['stats'] ) ) {
										/* translators: 1: создано, 2: обновлено, 3: пропущено, 4: ошибок */
										echo esc_html( sprintf(
											__( 'новых %1$d / обн. %2$d / пропуск %3$d / ошибок %4$d', 'idpg-event-import' ),
											(int) $source['stats']['created'],
											(int) $source['stats']['updated'],
											(int) $source['stats']['skipped'],
											(int) $source['stats']['errors']
										) );
									}
									?>
								</td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => self::RUN_ACTION, 'source' => $sid ), admin_url( 'admin-post.php' ) ), self::NONCE_ACTION ) ); ?>">
										<?php esc_html_e( 'Импортировать', 'idpg-event-import' ); ?>
									</a>
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', $sid, self::page_url() ) ); ?>">
										<?php esc_html_e( 'Правка', 'idpg-event-import' ); ?>
									</a>
									<a class="button button-small button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'idpgei_delete_source', 'source' => $sid ), admin_url( 'admin-post.php' ) ), self::NONCE_ACTION ) ); ?>"
										onclick="return confirm('<?php echo esc_js( __( 'Удалить источник? Импортированные события останутся.', 'idpg-event-import' ) ); ?>');">
										<?php esc_html_e( 'Удалить', 'idpg-event-import' ); ?>
									</a>
								</td>
							</tr>
							<?php if ( ! empty( $source['last_error'] ) ) : ?>
								<tr>
									<td colspan="7" style="color:#d63638"><?php echo esc_html( $source['last_error'] ); ?></td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 id="new"><?php echo esc_html( $editing ? sprintf( /* translators: %s: имя источника */ __( 'Правка источника «%s»', 'idpg-event-import' ), $editing['name'] ) : __( 'Новый источник', 'idpg-event-import' ) ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="idpgei_save_source" />
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<table class="form-table">
					<tr>
						<th><label for="idpgei-name"><?php esc_html_e( 'Название', 'idpg-event-import' ); ?></label></th>
						<td><input type="text" class="regular-text" id="idpgei-name" name="source[name]" required value="<?php echo esc_attr( $editing ? $editing['name'] : '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="idpgei-id"><?php esc_html_e( 'ID (slug)', 'idpg-event-import' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="idpgei-id" name="source[source_id]" pattern="[a-z0-9\-_]+" required <?php echo $editing ? 'readonly' : ''; ?> value="<?php echo esc_attr( $editing ? $editing['source_id'] : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Латиница/цифры/дефис. После создания не меняется.', 'idpg-event-import' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Тип', 'idpg-event-import' ); ?></th>
						<td>
							<label><input type="radio" name="source[provider]" value="rest" <?php checked( ! $editing || 'rest' === $editing['provider'] ); ?> /> <?php esc_html_e( 'JSON REST API', 'idpg-event-import' ); ?></label><br />
							<label><input type="radio" name="source[provider]" value="ical" <?php checked( $editing && 'ical' === $editing['provider'] ); ?> /> <?php esc_html_e( 'iCal (.ics)', 'idpg-event-import' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="idpgei-endpoint"><?php esc_html_e( 'Endpoint / URL календаря', 'idpg-event-import' ); ?></label></th>
						<td><input type="url" class="regular-text" id="idpgei-endpoint" name="source[endpoint]" required placeholder="https://..." value="<?php echo esc_attr( $editing ? $editing['endpoint'] : '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="idpgei-key"><?php esc_html_e( 'API-ключ (Bearer)', 'idpg-event-import' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="idpgei-key" name="source[api_key]" autocomplete="new-password" value="" />
							<p class="description"><?php esc_html_e( 'Опционально. При правке оставьте пустым — текущий ключ сохранится.', 'idpg-event-import' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="idpgei-list"><?php esc_html_e( 'Путь к списку (REST)', 'idpg-event-import' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="idpgei-list" name="source[list_path]" placeholder="data" value="<?php echo esc_attr( $editing ? $editing['list_path'] : 'data' ); ?>" />
							<p class="description"><?php esc_html_e( 'Пусто = корневой массив; «data» = {data:[...]}; вложенность через точку: data.items', 'idpg-event-import' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="idpgei-page"><?php esc_html_e( 'Параметр пагинации (REST)', 'idpg-event-import' ); ?></label></th>
						<td><input type="text" class="regular-text" id="idpgei-page" name="source[page_param]" placeholder="page" value="<?php echo esc_attr( $editing ? $editing['page_param'] : 'page' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="idpgei-city"><?php esc_html_e( 'Город по умолчанию', 'idpg-event-import' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_categories( array(
								'taxonomy'         => 'city',
								'name'             => 'source[city_term]',
								'show_option_none' => __( '— из API —', 'idpg-event-import' ),
								'option_none_value' => '0',
								'selected'         => $editing ? (int) $editing['city_term'] : 0,
								'hide_empty'       => false,
							) );
							?>
						</td>
					</tr>
					<tr>
						<th><label for="idpgei-org"><?php esc_html_e( 'Автор (организатор)', 'idpg-event-import' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_users( array(
								'name'             => 'source[organizer_id]',
								'show'             => 'display_name_with_login',
								'selected'         => $editing ? (int) $editing['organizer_id'] : 0,
								'include_selected' => true,
							) );
							?>
							<p class="description"><?php esc_html_e( 'События будут принадлежать этому пользователю (важно для прав доступа организаторов).', 'idpg-event-import' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Статус публикаций', 'idpg-event-import' ); ?></th>
						<td>
							<label><input type="radio" name="source[post_status]" value="draft" <?php checked( ! $editing || 'draft' === $editing['post_status'] ); ?> /> <?php esc_html_e( 'Черновик (модерация)', 'idpg-event-import' ); ?></label><br />
							<label><input type="radio" name="source[post_status]" value="publish" <?php checked( $editing && 'publish' === $editing['post_status'] ); ?> /> <?php esc_html_e( 'Публиковать сразу', 'idpg-event-import' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="idpgei-sched"><?php esc_html_e( 'Частота автоимпорта', 'idpg-event-import' ); ?></label></th>
						<td>
							<select id="idpgei-sched" name="source[schedule]">
								<?php
								$scheds = array(
									900     => __( 'каждые 15 минут', 'idpg-event-import' ),
									3600    => __( 'каждый час', 'idpg-event-import' ),
									21600   => __( 'каждые 6 часов', 'idpg-event-import' ),
									43200   => __( 'каждые 12 часов', 'idpg-event-import' ),
									86400   => __( 'раз в день', 'idpg-event-import' ),
								);
								$cur = $editing ? (int) $editing['schedule'] : 21600;
								foreach ( $scheds as $sec => $label ) {
									printf(
										'<option value="%d" %s>%s</option>',
										(int) $sec,
										selected( $cur, $sec, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Политики', 'idpg-event-import' ); ?></th>
						<td>
							<label><input type="checkbox" name="source[overwrite]" value="1" <?php checked( $editing && ! empty( $editing['overwrite'] ) ); ?> /> <?php esc_html_e( 'Перезаписывать заголовок и описание при изменении в API', 'idpg-event-import' ); ?></label><br />
							<label><input type="checkbox" name="source[create_missing_venues]" value="1" <?php checked( $editing && ! empty( $editing['create_missing_venues'] ) ); ?> /> <?php esc_html_e( 'Создавать площадки, если не найдены по названию', 'idpg-event-import' ); ?></label>
						</td>
					</tr>
				</table>

				<?php submit_button( $editing ? __( 'Сохранить источник', 'idpg-event-import' ) : __( 'Добавить источник', 'idpg-event-import' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Обработка ручного запуска импорта.
	 *
	 * @return void
	 */
	public function handle_run_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'idpg-event-import' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$source_id = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';

		$result = IDPG_Event_Import::instance()->process_source( $source_id );

		$msg = is_wp_error( $result )
			? sprintf( /* translators: %s: текст ошибки */ __( 'Ошибка импорта: %s', 'idpg-event-import' ), $result->get_error_message() )
			: sprintf( /* translators: 1: создано, 2: обновлено */ __( 'Готово: новых %1$d, обновлено %2$d', 'idpg-event-import' ), (int) $result['created'], (int) $result['updated'] );

		wp_safe_redirect( add_query_arg( 'idpgei_msg', rawurlencode( $msg ), self::page_url() ) );
		exit;
	}

	/**
	 * Обработка сохранения источника.
	 *
	 * @return void
	 */
	public function handle_save_source() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'idpg-event-import' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$posted = isset( $_POST['source'] ) && is_array( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- санитизация в sanitize_source.

		$importer = IDPG_Event_Import::instance();

		// При правке: пустой ключ = сохранить существующий.
		if ( ! empty( $posted['source_id'] ) && '' === ( isset( $posted['api_key'] ) ? $posted['api_key'] : '' ) ) {
			$existing = $importer->get_source( sanitize_key( $posted['source_id'] ) );

			if ( $existing && ! empty( $existing['api_key'] ) ) {
				$posted['api_key'] = $existing['api_key'];
			}
		}

		// Чекбоксы отсутствуют в POST, если не отмечены.
		$posted['overwrite'] = ! empty( $posted['overwrite'] );
		$posted['create_missing_venues'] = ! empty( $posted['create_missing_venues'] );

		$saved = $importer->save_source( $posted );

		$msg = $saved
			? __( 'Источник сохранён.', 'idpg-event-import' )
			: __( 'Не удалось сохранить источник (проверьте ID и endpoint).', 'idpg-event-import' );

		wp_safe_redirect( add_query_arg( 'idpgei_msg', rawurlencode( $msg ), self::page_url() ) );
		exit;
	}

	/**
	 * Обработка удаления источника.
	 *
	 * @return void
	 */
	public function handle_delete_source() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'idpg-event-import' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$source_id = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';

		IDPG_Event_Import::instance()->delete_source( $source_id );

		wp_safe_redirect( add_query_arg( 'idpgei_msg', rawurlencode( __( 'Источник удалён. Импортированные события остались.', 'idpg-event-import' ) ), self::page_url() ) );
		exit;
	}

	/**
	 * Рендер страницы документации (справка в админке).
	 *
	 * @return void
	 */
	public function render_docs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'idpg-event-import' ) );
		}

		$readme_path = IDPGEI_DIR . 'README.md';
		$has_readme  = file_exists( $readme_path );
		?>
		<div class="wrap about-wrap">
			<h1><?php esc_html_e( 'Импорт событий — документация', 'idpg-event-import' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Как подключить партнёра, настроить REST/iCal источник, импортировать вручную и по расписанию, а также расширить плагин.', 'idpg-event-import' ); ?>
			</p>

			<?php if ( $has_readme ) : ?>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( self::docs_url() . '&raw=1' ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Открыть полный README.md (Markdown)', 'idpg-event-import' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Быстрый старт', 'idpg-event-import' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Откройте «Импорт событий» и добавьте источник партнёра.', 'idpg-event-import' ); ?></li>
				<li><?php esc_html_e( 'Проверьте настройку без записи в БД: wp idpgei test <id>.', 'idpg-event-import' ); ?></li>
				<li><?php esc_html_e( 'Запустите первый импорт кнопкой «Импортировать» или wp idpgei import <id>.', 'idpg-event-import' ); ?></li>
				<li><?php esc_html_e( 'Проверьте события в «События → Все события» (по умолчанию — черновики).', 'idpg-event-import' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Типы источников', 'idpg-event-import' ); ?></h2>
			<ul>
				<li><strong><?php esc_html_e( 'JSON REST API', 'idpg-event-import' ); ?></strong> — <?php esc_html_e( 'пагинация по страницам, Bearer-авторизация, настраиваемый путь к списку (list_path) и маппинг полей (field_map).', 'idpg-event-import' ); ?></li>
				<li><strong><?php esc_html_e( 'iCal (.ics)', 'idpg-event-import' ); ?></strong> — <?php esc_html_e( 'календарные ленты по RFC 5545: VEVENT, unfold строк, DTSTART/DTEND, SUMMARY, LOCATION, URL.', 'idpg-event-import' ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'Ключевые поля конфига', 'idpg-event-import' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<thead>
					<tr><th><?php esc_html_e( 'Поле', 'idpg-event-import' ); ?></th><th><?php esc_html_e( 'Назначение', 'idpg-event-import' ); ?></th></tr>
				</thead>
				<tbody>
					<tr><td><code>source_id</code></td><td><?php esc_html_e( 'Уникальный slug (латиница).', 'idpg-event-import' ); ?></td></tr>
					<tr><td><code>provider</code></td><td><?php esc_html_e( 'rest | ical.', 'idpg-event-import' ); ?></td></tr>
					<tr><td><code>endpoint</code></td><td><?php esc_html_e( 'URL API или .ics (только http/https).', 'idpg-event-import' ); ?></td></tr>
					<tr><td><code>list_path</code></td><td><?php esc_html_e( 'Путь к массиву событий в JSON: пусто | data | data.items.', 'idpg-event-import' ); ?></td></tr>
					<tr><td><code>field_map</code></td><td><?php esc_html_e( 'Имена полей партнёра → наши (json).', 'idpg-event-import' ); ?></td></tr>
					<tr><td><code>city_term</code></td><td><?php esc_html_e( 'Город по умолчанию для всех событий источника.', 'idpg-event-import' ); ?></td></tr>
					<tr><td><code>organizer_id</code></td><td><?php esc_html_e( 'Автор (определяет права организатора).', 'idpg-event-import' ); ?></td></tr>
					<tr><td><code>post_status</code></td><td><?php esc_html_e( 'draft | publish | pending.', 'idpg-event-import' ); ?></td></tr>
					<tr><td><code>schedule</code></td><td><?php esc_html_e( 'Период автоимпорта, сек (900…604800).', 'idpg-event-import' ); ?></td></tr>
					<tr><td><code>overwrite</code></td><td><?php esc_html_e( 'Перезаписывать заголовок/описание при изменении в API.', 'idpg-event-import' ); ?></td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Дедупликация и политики', 'idpg-event-import' ); ?></h2>
			<p>
				<?php esc_html_e( 'Событие уникально по паре «источник + внешний ID» (_idpg_source + _idpg_external_id). Повторные прогоны обновляют существующие события, а не создают дубли. Обязательные поля: title, starts_at, external_id — без них событие пропускается.', 'idpg-event-import' ); ?>
			</p>

			<h2><?php esc_html_e( 'Автоимпорт (cron)', 'idpg-event-import' ); ?></h2>
			<p>
				<?php esc_html_e( 'Единый хук idpgei_cron_import (раз в час) обрабатывает источники, у которых наступило время (last_run + schedule). Для строгого расписания настройте системный cron на wp-cron.php.', 'idpg-event-import' ); ?>
			</p>

			<h2><?php esc_html_e( 'WP-CLI', 'idpg-event-import' ); ?></h2>
			<pre style="max-width:720px"><code>wp idpgei sources
wp idpgei test &lt;source-id&gt;
wp idpgei import &lt;source-id&gt; [--force]
wp idpgei import --all [--force]</code></pre>

			<h2><?php esc_html_e( 'Расширение', 'idpg-event-import' ); ?></h2>
			<p>
				<?php esc_html_e( 'Доступны фильтры idpgei_http_transport и idpgei_provider_class (кастомные провайдеры) и действие idpgei_import_completed. Подробности — в README.', 'idpg-event-import' ); ?>
			</p>

			<?php if ( $has_readme ) : ?>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( self::docs_url() . '&raw=1' ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Полная документация (README.md)', 'idpg-event-import' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Отдать содержимое README.md как текст (для вывода в браузере).
	 *
	 * Используется параметром ?raw=1 на странице документации.
	 *
	 * @return void
	 */
	public function serve_raw_readme() {
		$readme_path = IDPGEI_DIR . 'README.md';

		if ( ! file_exists( $readme_path ) ) {
			wp_die( esc_html__( 'Файл README.md не найден.', 'idpg-event-import' ) );
		}

		$content = (string) file_get_contents( $readme_path );

		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- текстовый Markdown для просмотра.
		exit;
	}
}
