<?php
/**
 * Форма создания и правки события организатора (G5).
 *
 * Обслуживает два адреса, которые регистрирует `IDPG_Organizer_URL`:
 *   - `/organizer/event/new/`  — создание нового события;
 *   - `/organizer/event/{id}/` — правка существующего.
 *
 * Отдельный шаблон, а не раздел кабинета: у формы нет боковой навигации,
 * она занимает всю страницу. Проверка доступа — та же, что и у кабинета
 * (`IDPG_Organizer_URL::get_access_decision()`), чтобы форма не оказалась
 * доступна там, где кабинет закрыт.
 *
 * Данные подставляются сервером из `IDPG_REST_Organizer::format_event()`,
 * то есть ровно те, что вернул бы `GET /idpg/v1/organizer/events/{id}`:
 * форма и API не могут разойтись. Правка идёт через
 * `organizer-event-form.js`; без JavaScript страница остаётся читаемой,
 * но изменить событие нельзя — не-JS путь для событий не предусмотрен
 * (в отличие от профиля, где он есть).
 *
 * Загрузка постера и импорт из файла в форму не выведены сознательно:
 * REST не отдаёт `upload_files` организатору и не имеет эндпоинта загрузки,
 * поэтому блоки `.create-event__upload` и `.create-event__import` из
 * `_create-event.scss` остаются неиспользуемыми до появления бэкенда.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Доступ ---------------------------------------------------------------
//
// Те же правила, что у page-organizer.php: гостю вход, «не организатору»
// честный 403. Иначе форма была бы доступнее кабинета, к которому она
// ведёт из «Моих событий».

$idpg_event_decision = 'allowed';

if ( class_exists( 'IDPG_Organizer_URL' ) ) {
	$idpg_event_decision = IDPG_Organizer_URL::get_access_decision();
} else {
	$idpg_event_fallback = wp_get_current_user();

	if ( ! $idpg_event_fallback->exists() ) {
		$idpg_event_decision = 'guest';
	} elseif ( array_intersect( array( 'event_organizer', 'administrator', 'editor' ), (array) $idpg_event_fallback->roles ) ) {
		$idpg_event_decision = 'allowed';
	} else {
		$idpg_event_decision = 'denied';
	}
}

if ( 'guest' === $idpg_event_decision ) {
	wp_safe_redirect( home_url( '/login/' ) );
	exit;
}

if ( 'denied' === $idpg_event_decision ) {
	status_header( 403 );
	nocache_headers();
}

// --- Какое событие правим -------------------------------------------------

$idpg_event_id      = class_exists( 'IDPG_Organizer_URL' ) ? IDPG_Organizer_URL::get_current_event_id() : 0;
$idpg_event_is_edit = ( $idpg_event_id > 0 );

/*
 * Значения по умолчанию. Нужны не только новому событию: у черновика,
 * созданного до появления поля, мета может отсутствовать, и без дефолтов
 * форма печатала бы предупреждения «Undefined array key» вместо пустых
 * полей. Термы — пустые массивы: в форме они приходят из списка ID, а не
 * строкой.
 */
$idpg_event_data = array(
	'title'        => '',
	'content'      => '',
	'excerpt'      => '',
	'status'       => 'draft',
	'start_date'   => '',
	'end_date'     => '',
	'all_day'      => 'no',
	'venue'        => 0,
	'address'      => '',
	'is_free'      => 'no',
	'price_from'   => '',
	'price_to'     => '',
	'vip_price'    => '',
	'ticket_status' => '',
	'ticket_type'  => '',
	'ticket_url'   => '',
	'yandex_form_url' => '',
	'widget_code'  => '',
	'sessions_json' => '',
	'video_trailer' => '',
	'stream_embed' => '',
	'is_online'    => 'no',
	'stream_url'   => '',
	'banner_request' => 'no',
	'poster_id'    => 0,
	'gallery'      => array(),
	'city'         => array(),
	'category'     => array(),
	'age_rating'   => array(),
);

// Черновик загружаем только для собственного события: чужие ID не должны
// ни показываться, ни раскрывать существование чужой записи. Ровно это же
// отдаёт REST (403 на чужое событие), поэтому UI и API согласованы.
$idpg_event_forbidden = false;

if ( $idpg_event_is_edit ) {
	$idpg_event_post = get_post( $idpg_event_id );

	if ( ! $idpg_event_post instanceof WP_Post || 'event' !== $idpg_event_post->post_type ) {
		$idpg_event_forbidden = true;
	} elseif ( (int) $idpg_event_post->post_author !== get_current_user_id()
		&& ! current_user_can( 'edit_others_events' ) ) {
		$idpg_event_forbidden = true;
	} else {
		$idpg_event_data = array(
			'title'        => (string) $idpg_event_post->post_title,
			'content'      => (string) $idpg_event_post->post_content,
			'excerpt'      => (string) $idpg_event_post->post_excerpt,
			'status'       => (string) $idpg_event_post->post_status,
			'start_date'   => (string) get_post_meta( $idpg_event_id, '_idpg_start_date', true ),
			'end_date'     => (string) get_post_meta( $idpg_event_id, '_idpg_end_date', true ),
			'all_day'      => (string) get_post_meta( $idpg_event_id, '_idpg_all_day', true ),
			'venue'        => (int) get_post_meta( $idpg_event_id, '_idpg_venue', true ),
			'is_free'      => (string) get_post_meta( $idpg_event_id, '_idpg_is_free', true ),
			'price_from'   => (string) get_post_meta( $idpg_event_id, '_idpg_price_from', true ),
			'price_to'     => (string) get_post_meta( $idpg_event_id, '_idpg_price_to', true ),
			'ticket_type'  => (string) get_post_meta( $idpg_event_id, '_idpg_ticket_type', true ),
			'ticket_url'   => (string) get_post_meta( $idpg_event_id, '_idpg_ticket_url', true ),
			'yandex_form_url' => (string) get_post_meta( $idpg_event_id, '_idpg_yandex_form_url', true ),
			'widget_code'  => (string) get_post_meta( $idpg_event_id, '_idpg_widget_code', true ),
			'address'      => (string) get_post_meta( $idpg_event_id, '_idpg_address', true ),
			'vip_price'    => (string) get_post_meta( $idpg_event_id, '_idpg_vip_price', true ),
			'ticket_status' => (string) get_post_meta( $idpg_event_id, '_idpg_ticket_status', true ),
			'video_trailer' => (string) get_post_meta( $idpg_event_id, '_idpg_video_trailer', true ),
			'stream_embed' => (string) get_post_meta( $idpg_event_id, '_idpg_stream_embed', true ),
			'stream_url'   => (string) get_post_meta( $idpg_event_id, '_idpg_stream_url', true ),
			'banner_request' => (string) get_post_meta( $idpg_event_id, '_idpg_banner_request', true ),
			// Чекбоксы CF хранят как 'yes'/'no'; пустое значение равнозначно
			// «не отмечено», поэтому приводим к строке явно — иначе
			// checked( 'yes' === '' ) отработает верно, а вот JS, читающий
			// значение как булево, получил бы не то.
			'is_online'    => get_post_meta( $idpg_event_id, '_idpg_is_online', true ) ? 'yes' : 'no',
			'sessions_json' => (string) get_post_meta( $idpg_event_id, '_idpg_sessions_json', true ),
			'poster_id'    => (int) get_post_thumbnail_id( $idpg_event_id ),
			'gallery'      => idpg_core_event_gallery_ids( $idpg_event_id ),
			'city'         => wp_get_post_terms( $idpg_event_id, 'city', array( 'fields' => 'ids' ) ),
			'category'     => wp_get_post_terms( $idpg_event_id, 'event_category', array( 'fields' => 'ids' ) ),
			'age_rating'   => wp_get_post_terms( $idpg_event_id, 'age_rating', array( 'fields' => 'ids' ) ),
		);
	}
}

// Статусы, которые организатор вправе править (совпадают с
// IDPG_REST_Organizer::EDITABLE_STATUSES — правило одно на UI и API).
$idpg_event_editable_statuses = array( 'draft', 'rejected' );
$idpg_event_locked           = $idpg_event_is_edit
	&& ! $idpg_event_forbidden
	&& ! in_array( (string) $idpg_event_data['status'], $idpg_event_editable_statuses, true );

// --- Справочники для полей формы -----------------------------------------

/**
 * Термы таксономии для select. Ошибка запроса заменяется пустым списком,
 * чтобы форма отрисовалась без списка, а не упала.
 *
 * @param string $taxonomy Таксономия.
 * @return array[] Массив ID => название.
 */
function idpg_event_terms( $taxonomy ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
		return array();
	}

	$out = array();

	foreach ( $terms as $term ) {
		$out[ (int) $term->term_id ] = (string) $term->name;
	}

	return $out;
}

$idpg_event_cities     = idpg_event_terms( 'city' );
$idpg_event_categories = idpg_event_terms( 'event_category' );
$idpg_event_ages       = idpg_event_terms( 'age_rating' );
$idpg_event_venues     = class_exists( 'IDPG_Meta' ) ? IDPG_Meta::get_venue_options() : array( '0' => '—' );

// Выбранные значения термов приходят массивом, а в select нужен один ID —
// берём первый, multiple-поля в форме нет.
$idpg_event_picked = static function ( $key ) use ( $idpg_event_data ) {
	if ( empty( $idpg_event_data[ $key ] ) || ! is_array( $idpg_event_data[ $key ] ) ) {
		return 0;
	}

	return (int) reset( $idpg_event_data[ $key ] );
};

$idpg_event_new_url   = home_url( '/organizer/event/new/' );
$idpg_event_back_url  = home_url( '/organizer/events/' );
$idpg_event_can_write = ( 'allowed' === $idpg_event_decision && ! $idpg_event_forbidden && ! $idpg_event_locked );

get_header();
?>

	<main id="primary" class="site-main idpg-create-event-page">
		<div class="container">

			<?php if ( 'denied' === $idpg_event_decision ) : ?>

				<div class="idpg-org-denied">
					<h1 class="idpg-org-denied__title"><?php esc_html_e( 'Нет доступа', 'idpg_core' ); ?></h1>
					<p class="idpg-org-denied__text">
						<?php esc_html_e( 'Создавать события могут только организаторы.', 'idpg_core' ); ?>
					</p>
					<p class="idpg-org-denied__actions">
						<a class="idpg-btn idpg-btn--primary" href="<?php echo esc_url( $idpg_event_back_url ); ?>">
							<?php esc_html_e( '← В кабинет', 'idpg_core' ); ?>
						</a>
					</p>
				</div>

			<?php elseif ( $idpg_event_forbidden ) : ?>

				<div class="idpg-org-denied">
					<h1 class="idpg-org-denied__title"><?php esc_html_e( 'Событие не найдено', 'idpg_core' ); ?></h1>
					<p class="idpg-org-denied__text">
						<?php esc_html_e( 'Такого события нет либо оно принадлежит другому организатору.', 'idpg_core' ); ?>
					</p>
					<p class="idpg-org-denied__actions">
						<a class="idpg-btn idpg-btn--primary" href="<?php echo esc_url( $idpg_event_back_url ); ?>">
							<?php esc_html_e( '← В кабинет', 'idpg_core' ); ?>
						</a>
					</p>
				</div>

			<?php else : ?>

				<div class="create-event">
					<div class="create-event__container">

						<h1 class="create-event__title">
							<?php
							echo esc_html(
								$idpg_event_is_edit
									? __( 'Редактирование события', 'idpg_core' )
									: __( 'Новое событие', 'idpg_core' )
							);
							?>
						</h1>

						<?php if ( $idpg_event_locked ) : ?>
							<div class="idpg-notice idpg-notice--info" role="status">
								<?php
								if ( 'pending' === $idpg_event_data['status'] ) {
									esc_html_e( 'Событие на модерации — изменить его сейчас нельзя.', 'idpg_core' );
								} else {
									esc_html_e( 'Событие опубликовано — правка доступна только через модерацию.', 'idpg_core' );
								}
								?>
							</div>
						<?php endif; ?>

						<?php
						/*
						 * Прогресс-бар и номера шагов. Вёрстка приходит из
						 * `_create-event.scss` (`.create-event__steps`,
						 * `__step`, `__progress-track`, `__progress-fill`),
						 * поэтому шаги и панели обязаны идти в одном порядке:
						 * JS двигает `.create-event__panel--active` и
						 * пересчитывает ширину полосы по числу шагов.
						 */
						?>
						<ol class="create-event__steps" data-idpg-event-steps>
							<li class="create-event__step create-event__step--active" data-idpg-event-step>
								<?php esc_html_e( 'Основное', 'idpg_core' ); ?>
							</li>
							<li class="create-event__step" data-idpg-event-step>
								<?php esc_html_e( 'Даты и место', 'idpg_core' ); ?>
							</li>
							<li class="create-event__step" data-idpg-event-step>
								<?php esc_html_e( 'Билеты и зал', 'idpg_core' ); ?>
							</li>
						</ol>

						<div
							class="create-event__progress"
							role="progressbar"
							aria-valuemin="1"
							aria-valuemax="3"
							aria-valuenow="1"
							data-idpg-event-progress
						>
							<div class="create-event__progress-track">
								<div class="create-event__progress-fill" style="width: 33.333%"></div>
							</div>
						</div>

						<form
							class="create-event__form"
							method="post"
							action=""
							data-idpg-event-form
							data-idpg-event-id="<?php echo esc_attr( (string) $idpg_event_id ); ?>"
							data-idpg-event-create-url="<?php echo esc_url( esc_url_raw( rest_url( 'idpg/v1/organizer/events' ) ) ); ?>"
							data-idpg-event-item-url="<?php echo esc_url( esc_url_raw( rest_url( 'idpg/v1/organizer/events' ) ) ); ?>"
						data-idpg-event-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
						data-idpg-event-locked="<?php echo $idpg_event_can_write ? '0' : '1'; ?>"
						data-idpg-venues-url="<?php echo esc_url( esc_url_raw( rest_url( 'idpg/v1/organizer/venues' ) ) ); ?>"
						data-idpg-can-upload="<?php echo current_user_can( 'upload_files' ) ? '1' : '0'; ?>"
					>
							<?php wp_nonce_field( 'idpg_organizer_event', 'idpg_org_event_nonce' ); ?>

							<div class="create-event__panel create-event__panel--active">

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-title">
										<?php esc_html_e( 'Название', 'idpg_core' ); ?>
										<span class="create-event__required" aria-hidden="true">*</span>
									</label>
									<input
										class="create-event__input"
										type="text"
										id="idpg-ev-title"
										name="title"
										required
										maxlength="200"
										value="<?php echo esc_attr( (string) $idpg_event_data['title'] ); ?>"
									>
								</div>

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-excerpt">
										<?php esc_html_e( 'Краткое описание', 'idpg_core' ); ?>
									</label>
									<textarea
										class="create-event__textarea"
										id="idpg-ev-excerpt"
										name="excerpt"
										rows="3"
										maxlength="500"
									><?php echo esc_textarea( (string) $idpg_event_data['excerpt'] ); ?></textarea>
								</div>

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-content">
										<?php esc_html_e( 'Описание', 'idpg_core' ); ?>
									</label>
									<textarea
										class="create-event__textarea"
										id="idpg-ev-content"
										name="content"
										rows="8"
									><?php echo esc_textarea( (string) $idpg_event_data['content'] ); ?></textarea>
								</div>

								<?php
								/*
								 * Постер и галерея (DEV-97). Источник — контейнер
								 * «Медиа» в docs/carbon-fields-export.json:
								 * `_thumbnail_id` (нативное поле WP) и
								 * `_idpg_gallery` (media_gallery Carbon Fields).
								 *
								 * Загрузку выполняет wp.media, поэтому скрипты
								 * медиатеки подключаются только здесь. Если права на
								 * загрузку нет, поля остаются на месте, но с честной
								 * подсказкой вместо неработающей кнопки.
								 */
								$idpg_can_upload = current_user_can( 'upload_files' );
								?>
								<?php if ( $idpg_can_upload ) : ?>
									<?php wp_enqueue_media(); ?>
								<?php endif; ?>

								<div class="create-event__field">
									<label class="create-event__label">
										<?php esc_html_e( 'Постер', 'idpg_core' ); ?>
									</label>

									<div class="create-event__upload" data-idpg-poster>
										<div class="create-event__upload-preview" data-idpg-poster-preview>
											<?php if ( $idpg_event_data['poster_id'] ) : ?>
												<?php
												echo wp_get_attachment_image(
													(int) $idpg_event_data['poster_id'],
													'medium',
													false,
													array( 'alt' => '' )
												);
												?>
											<?php endif; ?>
										</div>

										<label class="create-event__upload-label">
											<input type="hidden" name="poster_id" value="<?php echo esc_attr( (string) $idpg_event_data['poster_id'] ); ?>" data-idpg-poster-input>
											<?php esc_html_e( 'Выбрать изображение', 'idpg_core' ); ?>
											<input type="file" class="create-event__file-input" accept="image/*" data-idpg-poster-input>
										</label>

										<?php if ( $idpg_can_upload ) : ?>
											<button
												class="create-event__btn"
												type="button"
												data-idpg-poster-select
											>
												<?php esc_html_e( 'Выбрать из медиатеки', 'idpg_core' ); ?>
											</button>

											<button
												class="create-event__upload-remove"
												type="button"
												data-idpg-poster-remove
												<?php echo $idpg_event_data['poster_id'] ? '' : 'hidden'; ?>
											>
												<?php esc_html_e( 'Убрать постер', 'idpg_core' ); ?>
											</button>
										<?php else : ?>
											<p class="create-event__upload-hint">
												<?php esc_html_e( 'Загрузка файлов недоступна для вашей роли — обратитесь к администратору.', 'idpg_core' ); ?>
											</p>
										<?php endif; ?>
									</div>
								</div>

								<div class="create-event__field">
									<label class="create-event__label">
										<?php esc_html_e( 'Галерея', 'idpg_core' ); ?>
									</label>

									<div class="create-event__upload" data-idpg-gallery>
										<div
											class="create-event__upload-preview"
											data-idpg-gallery-preview
											data-idpg-gallery-ids="<?php echo esc_attr( wp_json_encode( array_map( 'absint', (array) $idpg_event_data['gallery'] ) ) ); ?>"
										></div>

										<?php if ( $idpg_can_upload ) : ?>
											<button
												class="create-event__btn"
												type="button"
												data-idpg-gallery-select
											>
												<?php esc_html_e( 'Добавить изображения', 'idpg_core' ); ?>
											</button>

											<button
												class="create-event__upload-remove"
												type="button"
												data-idpg-gallery-clear
											>
												<?php esc_html_e( 'Очистить галерею', 'idpg_core' ); ?>
											</button>
										<?php else : ?>
											<p class="create-event__upload-hint">
												<?php esc_html_e( 'Загрузка файлов недоступна для вашей роли — обратитесь к администратору.', 'idpg_core' ); ?>
											</p>
										<?php endif; ?>
									</div>
								</div>

								<div class="create-event__nav">
									<a
										class="create-event__btn create-event__btn--prev"
										href="<?php echo esc_url( $idpg_event_back_url ); ?>"
									>
										<?php esc_html_e( 'Отмена', 'idpg_core' ); ?>
									</a>
									<button
										class="create-event__btn"
										type="button"
										data-idpg-event-step-next
									>
										<?php esc_html_e( 'Далее', 'idpg_core' ); ?>
									</button>
								</div>

							</div>

							<div class="create-event__panel">

								<div class="create-event__row">
									<div class="create-event__field">
										<label class="create-event__label" for="idpg-ev-start">
											<?php esc_html_e( 'Начало', 'idpg_core' ); ?>
											<span class="create-event__required" aria-hidden="true">*</span>
										</label>
										<input
											class="create-event__input"
											type="date"
											id="idpg-ev-start"
											name="start_date"
											required
											value="<?php echo esc_attr( (string) $idpg_event_data['start_date'] ); ?>"
										>
									</div>

									<div class="create-event__field">
										<label class="create-event__label" for="idpg-ev-end">
											<?php esc_html_e( 'Окончание', 'idpg_core' ); ?>
										</label>
										<input
											class="create-event__input"
											type="date"
											id="idpg-ev-end"
											name="end_date"
											value="<?php echo esc_attr( (string) $idpg_event_data['end_date'] ); ?>"
										>
									</div>
								</div>

								<div class="create-event__field">
									<span class="create-event__label"><?php esc_html_e( 'Весь день', 'idpg_core' ); ?></span>
									<label class="create-event__radio">
										<input type="radio" name="all_day" value="no" <?php checked( 'yes' !== (string) $idpg_event_data['all_day'] ); ?>>
										<?php esc_html_e( 'Нет', 'idpg_core' ); ?>
									</label>
									<label class="create-event__radio">
										<input type="radio" name="all_day" value="yes" <?php checked( 'yes' === (string) $idpg_event_data['all_day'] ); ?>>
										<?php esc_html_e( 'Да', 'idpg_core' ); ?>
									</label>
								</div>

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-city">
										<?php esc_html_e( 'Город', 'idpg_core' ); ?>
										<span class="create-event__required" aria-hidden="true">*</span>
									</label>
									<select class="create-event__select" id="idpg-ev-city" name="city" required>
										<option value=""><?php esc_html_e( '— Выберите город —', 'idpg_core' ); ?></option>
										<?php foreach ( $idpg_event_cities as $idpg_city_id => $idpg_city_name ) : ?>
											<option value="<?php echo esc_attr( (string) $idpg_city_id ); ?>" <?php selected( $idpg_event_picked( 'city' ), (int) $idpg_city_id ); ?>>
												<?php echo esc_html( $idpg_city_name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-venue">
										<?php esc_html_e( 'Площадка', 'idpg_core' ); ?>
									</label>
									<select class="create-event__select" id="idpg-ev-venue" name="venue">
										<?php foreach ( $idpg_event_venues as $idpg_venue_id => $idpg_venue_name ) : ?>
											<option value="<?php echo esc_attr( (string) $idpg_venue_id ); ?>" <?php selected( (int) $idpg_event_data['venue'], (int) $idpg_venue_id ); ?>>
												<?php echo esc_html( $idpg_venue_name ); ?>
											</option>
										<?php endforeach; ?>
									</select>

									<?php
									/*
									 * Создание площадки из формы события (DEV-50).
									 * Отдельная форма внутри формы события невозможна
									 * без вложенности, поэтому площадка заводится
									 * модалкой, а её поля лежат в контейнере
									 * «Данные площадки» Carbon Fields.
									 */
									?>
									<p class="create-event__actions">
										<button
											class="create-event__btn"
											type="button"
											data-idpg-venue-new
										>
											<?php esc_html_e( '+ Создать площадку', 'idpg_core' ); ?>
										</button>
									</p>
								</div>

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-address">
										<?php esc_html_e( 'Адрес', 'idpg_core' ); ?>
									</label>
									<input
										class="create-event__input"
										type="text"
										id="idpg-ev-address"
										name="address"
										placeholder="https://"
										value="<?php echo esc_attr( (string) $idpg_event_data['address'] ); ?>"
									>
									<span class="create-event__field-hint">
										<?php esc_html_e( 'Заполняется, если площадка выбрана не из списка или у неё нет адреса.', 'idpg_core' ); ?>
									</span>
								</div>

								<div class="create-event__nav">
									<button
										class="create-event__btn create-event__btn--prev"
										type="button"
										data-idpg-event-step-prev
									>
										<?php esc_html_e( 'Назад', 'idpg_core' ); ?>
									</button>
									<button
										class="create-event__btn"
										type="button"
										data-idpg-event-step-next
									>
										<?php esc_html_e( 'Далее', 'idpg_core' ); ?>
									</button>
								</div>

							</div>

							<div class="create-event__panel">

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-category">
										<?php esc_html_e( 'Категория', 'idpg_core' ); ?>
									</label>
									<select class="create-event__select" id="idpg-ev-category" name="category">
										<option value=""><?php esc_html_e( '— Не выбрана —', 'idpg_core' ); ?></option>
										<?php foreach ( $idpg_event_categories as $idpg_cat_id => $idpg_cat_name ) : ?>
											<option value="<?php echo esc_attr( (string) $idpg_cat_id ); ?>" <?php selected( $idpg_event_picked( 'category' ), (int) $idpg_cat_id ); ?>>
												<?php echo esc_html( $idpg_cat_name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-age">
										<?php esc_html_e( 'Возрастное ограничение', 'idpg_core' ); ?>
									</label>
									<select class="create-event__select" id="idpg-ev-age" name="age_rating">
										<option value=""><?php esc_html_e( '— Не выбрано —', 'idpg_core' ); ?></option>
										<?php foreach ( $idpg_event_ages as $idpg_age_id => $idpg_age_name ) : ?>
											<option value="<?php echo esc_attr( (string) $idpg_age_id ); ?>" <?php selected( $idpg_event_picked( 'age_rating' ), (int) $idpg_age_id ); ?>>
												<?php echo esc_html( $idpg_age_name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<div class="create-event__field">
									<span class="create-event__label"><?php esc_html_e( 'Бесплатное', 'idpg_core' ); ?></span>
									<label class="create-event__radio">
										<input type="radio" name="is_free" value="no" <?php checked( 'yes' !== (string) $idpg_event_data['is_free'] ); ?> data-idpg-toggle-free>
										<?php esc_html_e( 'Нет', 'idpg_core' ); ?>
									</label>
									<label class="create-event__radio">
										<input type="radio" name="is_free" value="yes" <?php checked( 'yes' === (string) $idpg_event_data['is_free'] ); ?> data-idpg-toggle-free>
										<?php esc_html_e( 'Да', 'idpg_core' ); ?>
									</label>
								</div>

								<?php
								/*
								 * Дальше идут билетные поля. Их скрывает
								 * organizer-event-form.js, когда событие бесплатное:
								 * цены и ссылки при is_free не имеют смысла, а
								 * `idpg_core_format_price()` всё равно печатает «Вход
								 * свободный». Контейнер помечен data-idpg-tickets,
								 * чтобы скрывался весь блок целиком.
								 */
								?>
								<div data-idpg-tickets>

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-ticket-status">
										<?php esc_html_e( 'Статус билетов', 'idpg_core' ); ?>
									</label>
									<select class="create-event__select" id="idpg-ev-ticket-status" name="ticket_status">
										<option value=""><?php esc_html_e( '— Не выбран —', 'idpg_core' ); ?></option>
										<?php
										// Значения — из экспорта Carbon Fields.
										$idpg_ticket_statuses = array(
											'in_sale'   => __( 'В продаже', 'idpg_core' ),
											'sold_out'  => __( 'Распроданы', 'idpg_core' ),
											'cancelled' => __( 'Отменено', 'idpg_core' ),
											'postponed' => __( 'Перенесено', 'idpg_core' ),
										);
										?>
										<?php foreach ( $idpg_ticket_statuses as $idpg_status_value => $idpg_status_label ) : ?>
											<option value="<?php echo esc_attr( $idpg_status_value ); ?>" <?php selected( (string) $idpg_event_data['ticket_status'], $idpg_status_value ); ?>>
												<?php echo esc_html( $idpg_status_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<div class="create-event__row">
									<div class="create-event__field">
										<label class="create-event__label" for="idpg-ev-price-from">
											<?php esc_html_e( 'Цена от', 'idpg_core' ); ?>
										</label>
										<input
											class="create-event__input"
											type="number"
											min="0"
											step="0.01"
											id="idpg-ev-price-from"
											name="price_from"
											value="<?php echo esc_attr( (string) $idpg_event_data['price_from'] ); ?>"
										>
									</div>

									<div class="create-event__field">
										<label class="create-event__label" for="idpg-ev-price-to">
											<?php esc_html_e( 'Цена до', 'idpg_core' ); ?>
										</label>
										<input
											class="create-event__input"
											type="number"
											min="0"
											step="0.01"
											id="idpg-ev-price-to"
											name="price_to"
											value="<?php echo esc_attr( (string) $idpg_event_data['price_to'] ); ?>"
										>
									</div>

									<div class="create-event__field">
										<label class="create-event__label" for="idpg-ev-vip-price">
											<?php esc_html_e( 'VIP-цена', 'idpg_core' ); ?>
										</label>
										<input
											class="create-event__input"
											type="number"
											min="0"
											step="0.01"
											id="idpg-ev-vip-price"
											name="vip_price"
											value="<?php echo esc_attr( (string) $idpg_event_data['vip_price'] ); ?>"
										>
									</div>
								</div>

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-ticket-type">
										<?php esc_html_e( 'Билеты', 'idpg_core' ); ?>
									</label>
									<select class="create-event__select" id="idpg-ev-ticket-type" name="ticket_type" data-idpg-ticket-type>
										<option value=""><?php esc_html_e( '— Не нужны —', 'idpg_core' ); ?></option>
										<?php
										// Ровно два значения из docs/carbon-fields-export.json.
										$idpg_ticket_types = array(
											'external_link' => __( 'Ссылка на покупку', 'idpg_core' ),
											'widget_yandex' => __( 'Виджет Яндекс.Билетов', 'idpg_core' ),
										);
										?>
										<?php foreach ( $idpg_ticket_types as $idpg_type_value => $idpg_type_label ) : ?>
											<option value="<?php echo esc_attr( $idpg_type_value ); ?>" <?php selected( (string) $idpg_event_data['ticket_type'], $idpg_type_value ); ?>>
												<?php echo esc_html( $idpg_type_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<div class="create-event__field" data-idpg-when-ticket-url>
									<label class="create-event__label" for="idpg-ev-ticket-url">
										<?php esc_html_e( 'Ссылка на билеты', 'idpg_core' ); ?>
									</label>
									<input
										class="create-event__input"
										type="url"
										id="idpg-ev-ticket-url"
										name="ticket_url"
										placeholder="https://"
										value="<?php echo esc_attr( (string) $idpg_event_data['ticket_url'] ); ?>"
									>
								</div>

								<div class="create-event__field" data-idpg-when-widget>
									<label class="create-event__label" for="idpg-ev-widget">
										<?php esc_html_e( 'Код виджета', 'idpg_core' ); ?>
									</label>
									<textarea
										class="create-event__textarea"
										id="idpg-ev-widget"
										name="widget_code"
										rows="3"
									><?php echo esc_textarea( (string) $idpg_event_data['widget_code'] ); ?></textarea>
								</div>

								<div class="create-event__field" data-idpg-when-widget>
									<label class="create-event__label" for="idpg-ev-yandex-form">
										<?php esc_html_e( 'Ссылка на форму Яндекс.Афиш', 'idpg_core' ); ?>
									</label>
									<input
										class="create-event__input"
										type="url"
										id="idpg-ev-yandex-form"
										name="yandex_form_url"
										placeholder="https://"
										value="<?php echo esc_attr( (string) $idpg_event_data['yandex_form_url'] ); ?>"
									>
								</div>

								</div><!-- /data-idpg-tickets -->

								<?php
								/*
								 * Сеансы приходят из зеркала `_idpg_sessions_json` (его же
								 * читает `format_event()`), а сохраняются как complex-поле
								 * Carbon `idpg_sessions` со строками {time, price, url}.
								 * Форма правит JSON-массив; синхронизацию с complex-полем
								 * делает REST (DEV-48).
								 *
								 * При «Весь день» блок скрывается: время сеансов
								 * событию, идущему целый день, не противоречит только
								 * формально — но показывать его в форме незачем.
								 */
								?>
								<div data-idpg-sessions-wrap>

								<?php
								$idpg_event_sessions = array();

								if ( '' !== trim( (string) $idpg_event_data['sessions_json'] ) ) {
									$idpg_event_decoded = json_decode( (string) $idpg_event_data['sessions_json'], true );

									if ( is_array( $idpg_event_decoded ) ) {
										foreach ( $idpg_event_decoded as $idpg_session_row ) {
											if ( ! is_array( $idpg_session_row ) ) {
												continue;
											}

											$idpg_event_sessions[] = array(
												'time'  => isset( $idpg_session_row['time'] ) ? (string) $idpg_session_row['time'] : '',
												'price' => isset( $idpg_session_row['price'] ) ? (string) $idpg_session_row['price'] : '',
												'url'   => isset( $idpg_session_row['url'] ) ? (string) $idpg_session_row['url'] : '',
											);
										}
									}
								}
								?>

								<fieldset class="create-event__field" data-idpg-event-sessions>
									<legend class="create-event__label"><?php esc_html_e( 'Сеансы', 'idpg_core' ); ?></legend>

									<div data-idpg-event-sessions-list>
										<?php foreach ( $idpg_event_sessions as $idpg_session_index => $idpg_session ) : ?>
											<div class="create-event__row" data-idpg-event-session-row>
												<input
													class="create-event__input"
													type="time"
													name="session_time[]"
													placeholder="<?php esc_attr_e( '19:00', 'idpg_core' ); ?>"
													value="<?php echo esc_attr( $idpg_session['time'] ); ?>"
												>
												<input
													class="create-event__input"
													type="number"
													min="0"
													step="0.01"
													name="session_price[]"
													placeholder="<?php esc_attr_e( 'Цена', 'idpg_core' ); ?>"
													value="<?php echo esc_attr( $idpg_session['price'] ); ?>"
												>
												<input
													class="create-event__input"
													type="url"
													name="session_url[]"
													placeholder="https://"
													value="<?php echo esc_attr( $idpg_session['url'] ); ?>"
												>
												<button
													class="create-event__btn"
													type="button"
													data-idpg-event-session-remove
												>
													<?php esc_html_e( 'Убрать', 'idpg_core' ); ?>
												</button>
											</div>
										<?php endforeach; ?>
									</div>

									<button
										class="create-event__btn"
										type="button"
										data-idpg-event-session-add
									>
										<?php esc_html_e( '+ Добавить сеанс', 'idpg_core' ); ?>
									</button>
								</fieldset>

								</div><!-- /data-idpg-sessions-wrap -->

								<div class="create-event__field">
									<label class="create-event__label" for="idpg-ev-video">
										<?php esc_html_e( 'Ссылка на видеотрейлер', 'idpg_core' ); ?>
									</label>
									<input
										class="create-event__input"
										type="url"
										id="idpg-ev-video"
										name="video_trailer"
										placeholder="https://"
										value="<?php echo esc_attr( (string) $idpg_event_data['video_trailer'] ); ?>"
									>
								</div>

								<div class="create-event__field">
									<span class="create-event__label"><?php esc_html_e( 'Онлайн-событие', 'idpg_core' ); ?></span>
									<label class="create-event__radio">
										<input type="radio" name="is_online" value="no" <?php checked( 'yes' !== (string) $idpg_event_data['is_online'] ); ?> data-idpg-toggle-online>
										<?php esc_html_e( 'Нет', 'idpg_core' ); ?>
									</label>
									<label class="create-event__radio">
										<input type="radio" name="is_online" value="yes" <?php checked( 'yes' === (string) $idpg_event_data['is_online'] ); ?> data-idpg-toggle-online>
										<?php esc_html_e( 'Да', 'idpg_core' ); ?>
									</label>
								</div>

								<div data-idpg-when-online>
									<div class="create-event__field">
										<label class="create-event__label" for="idpg-ev-stream-url">
											<?php esc_html_e( 'Ссылка на трансляцию', 'idpg_core' ); ?>
										</label>
										<input
											class="create-event__input"
											type="url"
											id="idpg-ev-stream-url"
											name="stream_url"
											placeholder="https://"
											value="<?php echo esc_attr( (string) $idpg_event_data['stream_url'] ); ?>"
										>
									</div>

									<div class="create-event__field">
										<label class="create-event__label" for="idpg-ev-stream-embed">
											<?php esc_html_e( 'Код вставки трансляции', 'idpg_core' ); ?>
										</label>
										<textarea
											class="create-event__textarea"
											id="idpg-ev-stream-embed"
											name="stream_embed"
											rows="3"
										><?php echo esc_textarea( (string) $idpg_event_data['stream_embed'] ); ?></textarea>
									</div>
								</div>

								<div class="create-event__field">
									<label class="create-event__radio">
										<input type="hidden" name="banner_request" value="no">
										<input
											type="checkbox"
											name="banner_request"
											value="yes"
											<?php checked( 'yes' === (string) $idpg_event_data['banner_request'] ); ?>
											data-idpg-banner-request
										>
										<?php esc_html_e( 'Подать заявку на показ в баннере на главной', 'idpg_core' ); ?>
									</label>
									<span class="create-event__field-hint">
										<?php esc_html_e( 'Показ платный. После отправки заявки администратор свяжется с вами для оплаты; событие попадёт в баннер после подтверждения.', 'idpg_core' ); ?>
									</span>
								</div>

							</div>

							<div data-idpg-event-status role="status"></div>

							<div class="create-event__nav">
								<button
									class="create-event__btn create-event__btn--prev"
									type="button"
									data-idpg-event-step-prev
								>
									<?php esc_html_e( 'Назад', 'idpg_core' ); ?>
								</button>

								<a class="create-event__btn create-event__btn--prev" href="<?php echo esc_url( $idpg_event_back_url ); ?>">
									<?php esc_html_e( 'Отмена', 'idpg_core' ); ?>
								</a>

								<button
									class="create-event__btn create-event__btn--submit"
									type="submit"
									data-idpg-event-save
									<?php disabled( ! $idpg_event_can_write ); ?>
								>
									<?php
									echo esc_html(
										$idpg_event_is_edit
											? __( 'Сохранить', 'idpg_core' )
											: __( 'Создать черновик', 'idpg_core' )
									);
									?>
								</button>
							</div>

						</form>

						<?php if ( $idpg_event_is_edit && $idpg_event_can_write ) : ?>
							<div class="create-event__actions">
								<button
									class="create-event__btn"
									type="button"
									data-idpg-event-submit
								>
									<?php esc_html_e( 'Отправить на модерацию', 'idpg_core' ); ?>
								</button>

								<button
									class="create-event__btn"
									type="button"
									data-idpg-event-delete
									data-idpg-event-back="<?php echo esc_url( $idpg_event_back_url ); ?>"
								>
									<?php esc_html_e( 'Удалить черновик', 'idpg_core' ); ?>
								</button>
							</div>
						<?php endif; ?>

					</div>
				</div>

			<?php endif; ?>

		</div>
	</main>

<?php
get_footer();
