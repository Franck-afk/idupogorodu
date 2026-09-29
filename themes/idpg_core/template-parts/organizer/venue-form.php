<?php
/**
 * Форма площадки организатора (DEV-50).
 *
 * Поля — строго из контейнера «Данные площадки» в
 * `docs/carbon-fields-export.json`, плюс нативные `title`, `content`, `city`
 * и `_thumbnail_id` (постер).
 *
 * Оформление: контролы и кнопки намеренно берутся у формы события
 * (`pages/_create-event.scss`) — это уже готовые по токенам элементы сайта,
 * и переписывать их ради площадки означало бы две расходящиеся формы.
 * Собственные классы `venue-form__*` закрывают только то, чего у формы
 * события нет: строку удаления, опасную кнопку и подсказку о загрузке
 * файлов (см. `pages/_venue-form.scss`).
 *
 * Форма работает и без JavaScript: обычный POST обрабатывается в
 * `page-organizer-venue.php`. Скрипт `organizer-venue-form.js` перехватывает
 * submit и отправляет те же данные через REST.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Данные приходят глобальными переменными, которые страница объявляет через
 * `global` до подключения партиала: `get_template_part()` подключает файл
 * внутри `load_template()`, и области видимости вызывающего кода не
 * разделяет. Значения по умолчанию пустые, а `can_write` — `false`, чтобы
 * прямой include партиала без страницы показал пустую форму, а не форму с
 * неопределёнными полями.
 */
global $idpg_venue_id, $idpg_venue_data, $idpg_venue_cities, $idpg_venue_can_write;

$idpg_vf_id        = isset( $idpg_venue_id ) ? (int) $idpg_venue_id : 0;
$idpg_vf_data      = isset( $idpg_venue_data ) && is_array( $idpg_venue_data ) ? $idpg_venue_data : array();
$idpg_vf_cities    = isset( $idpg_venue_cities ) && is_array( $idpg_venue_cities ) ? $idpg_venue_cities : array();
$idpg_vf_can_write = ! empty( $idpg_venue_can_write );
$idpg_vf_is_edit   = ( $idpg_vf_id > 0 );

/*
 * Выбранный город. Логика живёт здесь, а не в странице: партиал
 * подключается изнутри `load_template()`, и функция, объявленная в
 * шаблоне страницы, в его области видимости не существует. Зависимость
 * молча ломала выбор города — при прямом подключении партиала `function_exists`
 * возвращал false и выбранным оказывался «— Не выбрано —».
 */
$idpg_vf_city_terms   = isset( $idpg_vf_data['city'] ) ? (array) $idpg_vf_data['city'] : array();
$idpg_vf_city_picked = ! empty( $idpg_vf_city_terms ) ? (int) reset( $idpg_vf_city_terms ) : 0;

$idpg_vf_collection = function_exists( 'rest_url' )
	? esc_url_raw( rest_url( 'idpg/v1/organizer/venues' ) )
	: '';

/*
 * Значения `venue_type` — из экспорта Carbon Fields. Пустая строка
 * соответствует варианту «— Не выбран —».
 */
$idpg_vf_types = array(
	''        => __( '— Не выбран —', 'idpg_core' ),
	'theater' => __( 'Театр', 'idpg_core' ),
	'cinema'  => __( 'Кинотеатр', 'idpg_core' ),
	'museum'  => __( 'Музей', 'idpg_core' ),
	'concert' => __( 'Концертный зал', 'idpg_core' ),
);

$idpg_vf_can_upload = current_user_can( 'upload_files' );

if ( $idpg_vf_can_upload ) {
	wp_enqueue_media();
}

/*
 * Значение поля площадки.
 *
 * Замыкание, а не именованная функция: партиал подключается внутри функции, и
 * объявленная здесь функция не видела бы локальный `$idpg_vf_data`. Замыкание
 * захватывает его и заодно не создаёт глобального имени — повторный include
 * такого партиала не приводит к фатальной ошибке о redeclare.
 */
$idpg_vf_value = static function ( $key ) use ( $idpg_vf_data ) {
	return isset( $idpg_vf_data[ $key ] ) ? (string) $idpg_vf_data[ $key ] : '';
};
?>

<form
	class="create-event__form"
	method="post"
	action=""
	data-idpg-venue-form
	data-idpg-venue-id="<?php echo esc_attr( (string) $idpg_vf_id ); ?>"
	data-idpg-venue-url="<?php echo esc_url( $idpg_vf_collection ); ?>"
	data-idpg-venue-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	data-idpg-venue-locked="<?php echo $idpg_vf_can_write ? '0' : '1'; ?>"
	data-idpg-venue-back="<?php echo esc_url( function_exists( 'idpg_organizer_venues_url' ) ? idpg_organizer_venues_url() : home_url( '/organizer/venues/' ) ); ?>"
>
	<?php wp_nonce_field( 'idpg_organizer_venue', 'idpg_org_venue_nonce' ); ?>
	<input type="hidden" name="idpg_org_venue_submit" value="1">

	<div class="create-event__panel create-event__panel--active">

		<div class="create-event__field">
			<label class="create-event__label" for="idpg-vf-title">
				<?php esc_html_e( 'Название', 'idpg_core' ); ?>
				<span class="create-event__required" aria-hidden="true">*</span>
			</label>
			<input
				class="create-event__input"
				type="text"
				id="idpg-vf-title"
				name="title"
				required
				<?php disabled( ! $idpg_vf_can_write ); ?>
				value="<?php echo esc_attr( $idpg_vf_value( 'title' ) ); ?>"
			>
		</div>

		<div class="create-event__field">
			<label class="create-event__label" for="idpg-vf-content">
				<?php esc_html_e( 'Описание', 'idpg_core' ); ?>
			</label>
			<textarea
				class="create-event__textarea"
				id="idpg-vf-content"
				name="content"
				rows="6"
				<?php disabled( ! $idpg_vf_can_write ); ?>
			><?php echo esc_textarea( $idpg_vf_value( 'content' ) ); ?></textarea>
		</div>

		<div class="create-event__field">
			<label class="create-event__label" for="idpg-vf-city">
				<?php esc_html_e( 'Город', 'idpg_core' ); ?>
				<span class="create-event__required" aria-hidden="true">*</span>
			</label>
			<select
				class="create-event__select"
				id="idpg-vf-city"
				name="city"
				required
				<?php disabled( ! $idpg_vf_can_write ); ?>
			>
				<option value=""><?php esc_html_e( '— Не выбрано —', 'idpg_core' ); ?></option>
				<?php foreach ( $idpg_vf_cities as $idpg_vf_city_id => $idpg_vf_city_name ) : ?>
					<option value="<?php echo esc_attr( (string) $idpg_vf_city_id ); ?>" <?php selected( $idpg_vf_city_picked, (int) $idpg_vf_city_id ); ?>>
						<?php echo esc_html( $idpg_vf_city_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="create-event__field">
			<label class="create-event__label" for="idpg-vf-type">
				<?php esc_html_e( 'Тип', 'idpg_core' ); ?>
			</label>
			<select
				class="create-event__select"
				id="idpg-vf-type"
				name="venue_type"
				<?php disabled( ! $idpg_vf_can_write ); ?>
			>
				<?php foreach ( $idpg_vf_types as $idpg_vf_type_value => $idpg_vf_type_label ) : ?>
					<option value="<?php echo esc_attr( $idpg_vf_type_value ); ?>" <?php selected( $idpg_vf_value( 'venue_type' ), $idpg_vf_type_value ); ?>>
						<?php echo esc_html( $idpg_vf_type_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="create-event__field">
			<label class="create-event__label" for="idpg-vf-address">
				<?php esc_html_e( 'Адрес', 'idpg_core' ); ?>
				<span class="create-event__required" aria-hidden="true">*</span>
			</label>
			<input
				class="create-event__input"
				type="text"
				id="idpg-vf-address"
				name="venue_address"
				required
				<?php disabled( ! $idpg_vf_can_write ); ?>
				value="<?php echo esc_attr( $idpg_vf_value( 'venue_address' ) ); ?>"
			>
		</div>

		<div class="create-event__row">
			<div class="create-event__field">
				<label class="create-event__label" for="idpg-vf-phone">
					<?php esc_html_e( 'Телефон', 'idpg_core' ); ?>
				</label>
				<input
					class="create-event__input"
					type="tel"
					id="idpg-vf-phone"
					name="venue_phone"
					<?php disabled( ! $idpg_vf_can_write ); ?>
					value="<?php echo esc_attr( $idpg_vf_value( 'venue_phone' ) ); ?>"
				>
			</div>

			<div class="create-event__field">
				<label class="create-event__label" for="idpg-vf-email">
					<?php esc_html_e( 'E-mail', 'idpg_core' ); ?>
				</label>
				<input
					class="create-event__input"
					type="email"
					id="idpg-vf-email"
					name="venue_email"
					<?php disabled( ! $idpg_vf_can_write ); ?>
					value="<?php echo esc_attr( $idpg_vf_value( 'venue_email' ) ); ?>"
				>
			</div>
		</div>

		<div class="create-event__row">
			<div class="create-event__field">
				<label class="create-event__label" for="idpg-vf-website">
					<?php esc_html_e( 'Сайт', 'idpg_core' ); ?>
				</label>
				<input
					class="create-event__input"
					type="url"
					id="idpg-vf-website"
					name="venue_website"
					placeholder="https://"
					<?php disabled( ! $idpg_vf_can_write ); ?>
					value="<?php echo esc_attr( $idpg_vf_value( 'venue_website' ) ); ?>"
				>
			</div>

			<div class="create-event__field">
				<label class="create-event__label" for="idpg-vf-capacity">
					<?php esc_html_e( 'Вместимость', 'idpg_core' ); ?>
				</label>
				<input
					class="create-event__input"
					type="number"
					min="0"
					step="1"
					id="idpg-vf-capacity"
					name="venue_capacity"
					<?php disabled( ! $idpg_vf_can_write ); ?>
					value="<?php echo esc_attr( $idpg_vf_value( 'venue_capacity' ) ); ?>"
				>
			</div>
		</div>

		<div class="create-event__row">
			<div class="create-event__field">
				<label class="create-event__label" for="idpg-vf-lat">
					<?php esc_html_e( 'Широта', 'idpg_core' ); ?>
				</label>
				<input
					class="create-event__input"
					type="text"
					inputmode="decimal"
					id="idpg-vf-lat"
					name="venue_lat"
					placeholder="56.8527"
					<?php disabled( ! $idpg_vf_can_write ); ?>
					value="<?php echo esc_attr( $idpg_vf_value( 'venue_lat' ) ); ?>"
				>
			</div>

			<div class="create-event__field">
				<label class="create-event__label" for="idpg-vf-lng">
					<?php esc_html_e( 'Долгота', 'idpg_core' ); ?>
				</label>
				<input
					class="create-event__input"
					type="text"
					inputmode="decimal"
					id="idpg-vf-lng"
					name="venue_lng"
					placeholder="53.2114"
					<?php disabled( ! $idpg_vf_can_write ); ?>
					value="<?php echo esc_attr( $idpg_vf_value( 'venue_lng' ) ); ?>"
				>
			</div>
		</div>

		<div class="create-event__field">
			<label class="create-event__label">
				<?php esc_html_e( 'Фото', 'idpg_core' ); ?>
			</label>

			<div class="create-event__upload" data-idpg-vposter>
				<div class="create-event__upload-preview" data-idpg-vposter-preview>
					<?php if ( (int) $idpg_vf_value( 'poster_id' ) ) : ?>
						<?php
						echo wp_get_attachment_image(
							(int) $idpg_vf_value( 'poster_id' ),
							'medium',
							false,
							array( 'alt' => '' )
						);
						?>
					<?php endif; ?>
				</div>

				<input type="hidden" name="poster_id" value="<?php echo esc_attr( $idpg_vf_value( 'poster_id' ) ); ?>" data-idpg-vposter-input>

				<?php if ( $idpg_vf_can_upload ) : ?>
					<label class="create-event__upload-label">
						<?php esc_html_e( 'Выбрать изображение', 'idpg_core' ); ?>
						<input type="file" class="create-event__file-input" accept="image/*" data-idpg-vposter-input>
					</label>

					<button
						class="create-event__btn"
						type="button"
						data-idpg-vposter-select
					>
						<?php esc_html_e( 'Выбрать из медиатеки', 'idpg_core' ); ?>
					</button>

					<button
						class="create-event__upload-remove"
						type="button"
						data-idpg-vposter-remove
						<?php echo (int) $idpg_vf_value( 'poster_id' ) ? '' : 'hidden'; ?>
					>
						<?php esc_html_e( 'Убрать фото', 'idpg_core' ); ?>
					</button>
				<?php else : ?>
					<p class="venue-form__hint">
						<?php esc_html_e( 'Загрузка файлов недоступна для вашей роли — обратитесь к администратору.', 'idpg_core' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>

	</div>

	<div data-idpg-venue-status role="status"></div>

	<div class="create-event__nav">
		<a
			class="create-event__btn create-event__btn--prev"
			href="<?php echo esc_url( function_exists( 'idpg_organizer_venues_url' ) ? idpg_organizer_venues_url() : home_url( '/organizer/venues/' ) ); ?>"
		>
			<?php esc_html_e( 'Отмена', 'idpg_core' ); ?>
		</a>

		<button
			class="create-event__btn create-event__btn--submit"
			type="submit"
			data-idpg-venue-save
			<?php disabled( ! $idpg_vf_can_write ); ?>
		>
			<span class="create-event__submit-text">
				<?php
				echo esc_html(
					$idpg_vf_is_edit
						? __( 'Сохранить', 'idpg_core' )
						: __( 'Создать площадку', 'idpg_core' )
				);
				?>
			</span>
			<?php // Показывается, пока идёт запрос: без него кнопка просто гаснет. ?>
			<span class="create-event__spinner" aria-hidden="true"></span>
		</button>
	</div>

</form>

<?php
// Удаление доступно только у существующей площадки и не встроено в форму:
// кнопка появляется после успешного сохранения, когда известен ID.
if ( $idpg_vf_is_edit && $idpg_vf_can_write ) :
	?>
	<p class="venue-form__delete-row">
		<button
			class="create-event__btn venue-form__danger"
			type="button"
			data-idpg-venue-delete
		>
			<?php esc_html_e( 'Удалить площадку', 'idpg_core' ); ?>
		</button>
	</p>
<?php endif; ?>
