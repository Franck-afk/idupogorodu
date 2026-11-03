<?php
/**
 * Раздел «Мои события» кабинета организатора (G6).
 *
 * Список приходит из GET /idpg/v1/organizer/events, счётчики для вкладок
 * фильтра — из GET /idpg/v1/organizer/stats. Оба запроса делает
 * `organizer.js`; здесь только разметка-каркас, чтобы не дублировать
 * запрос событий, который уже реализован и покрыт тестами в плагине.
 *
 * Фильтр и страница живут в адресе (?status=…&p=…), поэтому состояние можно
 * переслать ссылкой, а кнопки браузера «назад/вперёд» его не теряют.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$idpg_events_new_url = function_exists( 'idpg_organizer_event_form_url' )
	? idpg_organizer_event_form_url()
	: home_url( '/organizer/event/new/' );
?>

<div
	class="idpg-org-body"
	data-idpg-events
	data-idpg-events-url="<?php echo esc_url( rest_url( 'idpg/v1/organizer/events' ) ); ?>"
	data-idpg-stats-url="<?php echo esc_url( rest_url( 'idpg/v1/organizer/stats' ) ); ?>"
	data-idpg-events-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	data-idpg-media-url="<?php echo esc_url( rest_url( 'wp/v2/media' ) ); ?>"
	data-idpg-events-edit-base="<?php echo esc_url( home_url( '/organizer/event/' ) ); ?>"
>
	<div class="idpg-org-filters" data-idpg-events-filters>
		<?php
		/*
		 * `all` сервер не понимает и трактует как «все рабочие статусы»
		 * (см. IDPG_REST_Organizer::get_list_post_status()), поэтому это
		 * ровно тот же набор, что вернёт пустой status.
		 */
		$idpg_events_tabs = array(
			'all'      => __( 'Все', 'idpg_core' ),
			'publish'  => __( 'Опубликованные', 'idpg_core' ),
			'pending'  => __( 'На модерации', 'idpg_core' ),
			'draft'    => __( 'Черновики', 'idpg_core' ),
			'rejected' => __( 'Отклонённые', 'idpg_core' ),
		);
		?>

		<?php foreach ( $idpg_events_tabs as $idpg_events_tab => $idpg_events_tab_label ) : ?>
			<button
				class="idpg-org-filter<?php echo 'all' === $idpg_events_tab ? ' idpg-org-filter--active' : ''; ?>"
				type="button"
				data-idpg-events-filter="<?php echo esc_attr( $idpg_events_tab ); ?>"
			>
				<?php echo esc_html( $idpg_events_tab_label ); ?>
				<span data-idpg-events-count="<?php echo esc_attr( $idpg_events_tab ); ?>"></span>
			</button>
		<?php endforeach; ?>
	</div>

	<p class="idpg-org-actions">
		<a class="idpg-btn idpg-btn--primary" href="<?php echo esc_url( $idpg_events_new_url ); ?>">
			<?php esc_html_e( '+ Создать событие', 'idpg_core' ); ?>
		</a>
	</p>

	<table class="idpg-org-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Событие', 'idpg_core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Статус', 'idpg_core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Просмотры', 'idpg_core' ); ?></th>
			</tr>
		</thead>
		<tbody data-idpg-events-body>
			<tr>
				<td colspan="3"><?php esc_html_e( 'Загрузка…', 'idpg_core' ); ?></td>
			</tr>
		</tbody>
	</table>

	<div class="idpg-org-empty" hidden data-idpg-events-empty>
		<p class="idpg-org-empty__title"><?php esc_html_e( 'Пока пусто', 'idpg_core' ); ?></p>
		<p class="idpg-org-empty__text">
			<?php esc_html_e( 'Создайте первое событие — после сохранения черновика его можно отправить на модерацию.', 'idpg_core' ); ?>
		</p>
	</div>

	<nav
		class="idpg-org-pagination"
		aria-label="<?php esc_attr_e( 'Постраничная навигация', 'idpg_core' ); ?>"
		data-idpg-events-pagination
		hidden
	>
		<button
			class="idpg-org-filter"
			type="button"
			data-idpg-events-prev
		>
			<?php esc_html_e( '← Назад', 'idpg_core' ); ?>
		</button>
		<span data-idpg-events-page-label></span>
		<button
			class="idpg-org-filter"
			type="button"
			data-idpg-events-next
		>
			<?php esc_html_e( 'Вперёд →', 'idpg_core' ); ?>
		</button>
	</nav>

	<div
		class="idpg-notice idpg-notice--error"
		role="alert"
		hidden
		data-idpg-events-error
	></div>
</div>
