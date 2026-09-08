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
	}

	/**
	 * Пункт подменю внутри меню idpg-core.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'idpg-settings', // PAGE_SLUG ядра.
			__( 'Импорт событий', 'idpg-event-import' ),
			__( 'Импорт событий', 'idpg-event-import' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
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
			<h1><?php esc_html_e( 'Импорт событий с площадок партнёров', 'idpg-event-import' ); ?></h1>

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
}
