<?php
/**
 * Форма создания и правки площадки организатора (DEV-50).
 *
 * Обслуживает два адреса, которые регистрирует `IDPG_Organizer_URL`:
 *   - `/organizer/venue/new/`  — создание новой площадки;
 *   - `/organizer/venue/{id}/` — правка существующей.
 *
 * Площадка — самостоятельная сущность (CPT `venue`) со своими полями в
 * контейнере «Данные площадки» Carbon Fields, а не набор мета события.
 * Поэтому у неё отдельная страница, а не поле в форме события; связаны они
 * через модальное окно в форме события и через выбор площадки в её
 * секции «Место».
 *
 * Права и проверка доступа — те же, что у кабинета и формы события:
 * гостю вход, «не организатору» честный 403.
 *
 * В отличие от события здесь есть рабочий путь без JavaScript: обычный
 * POST на эту же страницу с проверкой nonce. Он же используется при
 * отключённом fetch. Запись идёт через те же санитайзеры, что и REST, чтобы
 * два пути сохранения не разошлись.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Доступ ---------------------------------------------------------------
//
// Те же правила, что у page-organizer.php и page-organizer-event.php.

$idpg_venue_decision = 'allowed';

if ( class_exists( 'IDPG_Organizer_URL' ) ) {
	$idpg_venue_decision = IDPG_Organizer_URL::get_access_decision();
} else {
	$idpg_venue_fallback = wp_get_current_user();

	if ( ! $idpg_venue_fallback->exists() ) {
		$idpg_venue_decision = 'guest';
	} elseif ( array_intersect( array( 'event_organizer', 'administrator', 'editor' ), (array) $idpg_venue_fallback->roles ) ) {
		$idpg_venue_decision = 'allowed';
	} else {
		$idpg_venue_decision = 'denied';
	}
}

if ( 'guest' === $idpg_venue_decision ) {
	wp_safe_redirect( home_url( '/login/' ) );
	exit;
}

if ( 'denied' === $idpg_venue_decision ) {
	status_header( 403 );
	nocache_headers();
}

// --- Какую площадку правим -------------------------------------------------
//
// `global` объявлен до присваиваний: партиал `venue-form.php` подключается
// через `get_template_part()`, то есть изнутри `load_template()`, и локальные
// переменные этой страницы в его области видимости не существуют. Объявление
// после присваивания создало бы новую глобалку со значением null и потеряло
// бы заполненную форму — вместе с `disabled` на всех полях.

global $idpg_venue_id, $idpg_venue_data, $idpg_venue_cities, $idpg_venue_can_write;

$idpg_venue_id      = class_exists( 'IDPG_Organizer_URL' ) ? IDPG_Organizer_URL::get_current_venue_id() : 0;
$idpg_venue_is_edit = ( $idpg_venue_id > 0 );
$idpg_venue_forbidden = false;
$idpg_venue_notice    = '';

/*
 * Данные площадки. Ключи совпадают с параметрами REST
 * `IDPG_REST_Organizer::get_venue_args()`, а набор полей — с контейнером
 * «Данные площадки» в docs/carbon-fields-export.json.
 */
$idpg_venue_data = array(
	'title'         => '',
	'content'       => '',
	'city'          => array(),
	'venue_type'    => '',
	'venue_address' => '',
	'venue_phone'   => '',
	'venue_email'   => '',
	'venue_website' => '',
	'venue_capacity' => '',
	'venue_lat'     => '',
	'venue_lng'     => '',
	'poster_id'     => 0,
);

if ( $idpg_venue_is_edit ) {
	$idpg_venue_post = get_post( $idpg_venue_id );

	if ( ! $idpg_venue_post instanceof WP_Post || 'venue' !== $idpg_venue_post->post_type ) {
		$idpg_venue_forbidden = true;
	} elseif ( (int) $idpg_venue_post->post_author !== get_current_user_id()
		&& ! current_user_can( 'edit_others_venues' ) && ! current_user_can( 'manage_options' ) ) {
		// Чужая площадка не показывается и не подтверждает существование:
		// ровно как поступает REST.
		$idpg_venue_forbidden = true;
	} else {
		$idpg_venue_data = array(
			'title'         => (string) $idpg_venue_post->post_title,
			'content'       => (string) $idpg_venue_post->post_content,
			'city'          => wp_get_post_terms( $idpg_venue_id, 'city', array( 'fields' => 'ids' ) ),
			'venue_type'    => (string) get_post_meta( $idpg_venue_id, '_idpg_venue_type', true ),
			'venue_address' => (string) get_post_meta( $idpg_venue_id, '_idpg_venue_address', true ),
			'venue_phone'   => (string) get_post_meta( $idpg_venue_id, '_idpg_venue_phone', true ),
			'venue_email'   => (string) get_post_meta( $idpg_venue_id, '_idpg_venue_email', true ),
			'venue_website' => (string) get_post_meta( $idpg_venue_id, '_idpg_venue_website', true ),
			'venue_capacity' => (string) get_post_meta( $idpg_venue_id, '_idpg_venue_capacity', true ),
			'venue_lat'     => (string) get_post_meta( $idpg_venue_id, '_idpg_venue_lat', true ),
			'venue_lng'     => (string) get_post_meta( $idpg_venue_id, '_idpg_venue_lng', true ),
			'poster_id'     => (int) get_post_thumbnail_id( $idpg_venue_id ),
		);
	}
}

$idpg_venue_can_write = 'allowed' === $idpg_venue_decision && ! $idpg_venue_forbidden;

// --- Сохранение без JavaScript --------------------------------------------
//
// Обычный POST на эту же страницу. Проверка прав идёт по образцу
// обработчика профиля в page-organizer.php: путь через форму не должен быть
// слабее пути через REST.

if ( isset( $_POST['idpg_org_venue_submit'] ) ) {
	if ( ! $idpg_venue_can_write ) {
		$idpg_venue_notice = 'error';
	} else {
		$idpg_venue_nonce = isset( $_POST['idpg_org_venue_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['idpg_org_venue_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $idpg_venue_nonce, 'idpg_organizer_venue' ) ) {
			$idpg_venue_notice = 'invalid_nonce';
		} else {
			$idpg_venue_input = array();

			foreach ( array( 'title', 'content', 'venue_type', 'venue_address', 'venue_phone', 'venue_email', 'venue_website', 'venue_capacity', 'venue_lat', 'venue_lng' ) as $idpg_venue_field ) {
				if ( ! isset( $_POST[ $idpg_venue_field ] ) ) {
					continue;
				}

				$idpg_venue_input[ $idpg_venue_field ] = wp_unslash( $_POST[ $idpg_venue_field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised
			}

			$idpg_venue_city = isset( $_POST['city'] ) ? absint( wp_unslash( $_POST['city'] ) ) : 0;
			$idpg_venue_error = '';

			// Проверки повторяют серверные, иначе форма без JS принимала бы
			// то, что REST отверг бы.
			if ( '' === trim( (string) $idpg_venue_input['title'] ) ) {
				$idpg_venue_error = 'Укажите название площадки.';
			} elseif ( ! $idpg_venue_city ) {
				$idpg_venue_error = 'Укажите город площадки.';
			} elseif ( '' === trim( (string) $idpg_venue_input['venue_address'] ) ) {
				$idpg_venue_error = 'Укажите адрес площадки.';
			}

			if ( $idpg_venue_error ) {
				$idpg_venue_notice = $idpg_venue_error;
			} else {
				$idpg_venue_saved_id = idpg_organizer_save_venue(
					$idpg_venue_id,
					array_merge( $idpg_venue_input, array( 'city' => $idpg_venue_city ) )
				);

				if ( is_wp_error( $idpg_venue_saved_id ) ) {
					$idpg_venue_notice = $idpg_venue_saved_id->get_error_message();
				} else {
					// Post/Redirect/Get: F5 не переотправляет форму.
					wp_safe_redirect(
						add_query_arg( 'venue', 'saved', idpg_organizer_venue_form_url( $idpg_venue_saved_id ) )
					);
					exit;
				}
			}
		}
	}
}

// Уведомление после редиректа.
if ( ! $idpg_venue_notice && isset( $_GET['venue'] ) ) {
	$idpg_venue_notice = 'saved';
}

// --- Справочники для полей формы -------------------------------------------

/**
 * Термы таксономии для select.
 *
 * @param string $taxonomy Таксономия.
 * @return array ID => название.
 */
function idpg_organizer_venue_terms( $taxonomy ) {
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
		$out[ (int) $term->term_id ] = $term->name;
	}

	return $out;
}

/**
 * URL формы площадки.
 *
 * @param int $venue_id ID площадки; 0 — создание.
 * @return string
 */
function idpg_organizer_venue_form_url( $venue_id = 0 ) {
	if ( class_exists( 'IDPG_Organizer_URL' ) ) {
		return IDPG_Organizer_URL::venue_form_url( $venue_id );
	}

	if ( $venue_id ) {
		return home_url( '/organizer/venue/' . absint( $venue_id ) . '/' );
	}

	return home_url( '/organizer/venue/new/' );
}

/**
 * URL раздела «Площадки».
 *
 * @return string
 */
function idpg_organizer_venues_url() {
	return function_exists( 'idpg_organizer_section_url' )
		? idpg_organizer_section_url( 'venues' )
		: home_url( '/organizer/venues/' );
}

/**
 * Сохранение площадки с теми же проверками, что и в REST.
 *
 * Функция нужна для no-JS пути. Обращается к REST-классу, чтобы санитайзеры
 * и правила обязательности не расходились между двумя путями сохранения.
 *
 * @param int   $venue_id ID площадки; 0 — создание.
 * @param array $input    Значения полей.
 * @return int|WP_Error ID площадки.
 */
function idpg_organizer_save_venue( $venue_id, $input ) {
	$venue_id = absint( $venue_id );

	if ( ! class_exists( 'IDPG_REST_Organizer' ) ) {
		return new WP_Error( 'idpg_org_no_core', __( 'Модуль организатора недоступен.', 'idpg_core' ) );
	}

	// Формируем тот же REST-запрос, что отправляет JS, и пропускаем его
	// через штатный обработчик: одна кодовая база для обоих путей.
	$request = new WP_REST_Request( $venue_id ? 'PUT' : 'POST', '/idpg/v1/organizer/venues' . ( $venue_id ? '/' . $venue_id : '' ) );

	if ( $venue_id ) {
		$request->set_param( 'id', $venue_id );
	}

	foreach ( $input as $key => $value ) {
		$request->set_param( $key, $value );
	}

	// Обходим проверку nonce: она уже выполнена выше по `wp_verify_nonce()`.
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		return $response->as_error();
	}

	$data = $response->get_data();

	return isset( $data['data']['id'] ) ? (int) $data['data']['id'] : 0;
}

/*
 * Функция выбора терма, которая раньше жила здесь (`idpg_organizer_venue_picked`),
 * перенесена в партиал формы: партиал подключается изнутри `load_template()`
 * и функции этого файла в его области видимости нет. Здесь она была бы
 * недостижимой — выбор города молча сбрасывался бы на «— Не выбрано —».
 */

$idpg_venue_cities = idpg_organizer_venue_terms( 'city' );

get_header();
?>

	<main id="primary" class="site-main idpg-org-page">
		<div class="container">

			<?php if ( $idpg_venue_forbidden ) : ?>

				<div class="idpg-org-denied">
					<h1 class="idpg-org-denied__title"><?php esc_html_e( 'Площадка не найдена', 'idpg_core' ); ?></h1>
					<p class="idpg-org-denied__text">
						<?php esc_html_e( 'Площадка не существует или принадлежит другому организатору.', 'idpg_core' ); ?>
					</p>
					<p class="idpg-org-denied__actions">
						<a class="idpg-btn idpg-btn--primary" href="<?php echo esc_url( idpg_organizer_venues_url() ); ?>">
							<?php esc_html_e( '← К списку площадок', 'idpg_core' ); ?>
						</a>
					</p>
				</div>

			<?php else : ?>

				<div class="create-event">
					<div class="create-event__container">

						<h1 class="create-event__title">
							<?php
							echo esc_html(
								$idpg_venue_is_edit
									? __( 'Редактирование площадки', 'idpg_core' )
									: __( 'Новая площадка', 'idpg_core' )
							);
							?>
						</h1>

						<?php if ( 'saved' === $idpg_venue_notice ) : ?>
							<div class="idpg-notice idpg-notice--success" role="status">
								<?php esc_html_e( 'Площадка сохранена.', 'idpg_core' ); ?>
							</div>
						<?php elseif ( $idpg_venue_notice ) : ?>
							<div class="idpg-notice idpg-notice--error" role="alert">
								<?php
								if ( 'error' === $idpg_venue_notice ) {
									esc_html_e( 'Недостаточно прав для сохранения.', 'idpg_core' );
								} elseif ( 'invalid_nonce' === $idpg_venue_notice ) {
									esc_html_e( 'Сессия устарела, попробуйте ещё раз.', 'idpg_core' );
								} else {
									echo esc_html( $idpg_venue_notice );
								}
								?>
							</div>
						<?php endif; ?>

					<?php
					/*
					 * Разметка формы вынесена в template-part, чтобы её можно
					 * было переиспользовать и не держать в странице.
					 *
					 * Данные формы объявлены глобальными выше (см. `global` в
					 * блоке «Какую площадку правим»), потому что партиал
					 * подключается изнутри `load_template()` и области
					 * видимости вызывающего кода не разделяет. Попытка
					 * передать их третьим аргументом `get_template_part()`
					 * не работает: в этой версии WordPress `$args` уходят
					 * только в хуки `get_template_part_*` и в партиале
					 * переменными не становятся. Без `global` форма
					 * получалась с пустыми значениями и `disabled` на всех
					 * полях — то есть без JavaScript ею было невозможно
					 * пользоваться.
					 */
					get_template_part( 'template-parts/organizer/venue-form' );

					?>

					</div>
				</div>

			<?php endif; ?>

		</div>
	</main>

<?php
get_footer();
