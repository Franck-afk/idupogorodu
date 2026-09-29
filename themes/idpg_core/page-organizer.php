<?php
/**
 * Кабинет организатора: /organizer/ и /organizer/{section}/.
 *
 * Обслуживается маршрутом `IDPG_Organizer_URL` (rewrite + template_include),
 * а также напрямую как страница `/organizer/`, созданная темой
 * (`idpg_core_create_organizer_page()`).
 *
 * Проверка доступа (см. `IDPG_Organizer_URL::get_access_decision()`):
 *  - гость → редирект на /login/;
 *  - авторизованный без роли организатора → 403;
 *  - организатор и выше → кабинет.
 *
 * Разделы рендерятся серверно: активный виден, остальные скрыты атрибутом
 * `hidden`. `organizer.js` только переключает видимость (и меняет URL через
 * history.pushState), поэтому прямой заход на /organizer/events/ и работа
 * без JavaScript дают тот же результат.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Доступ ---------------------------------------------------------------

$idpg_org_decision = 'allowed';

if ( class_exists( 'IDPG_Organizer_URL' ) ) {
	$idpg_org_decision = IDPG_Organizer_URL::get_access_decision();
} else {
	// Плагин ядра отключён: минимальная собственная проверка, чтобы
	// страница не оказалась открытой для всех.
	$idpg_org_fallback_user = wp_get_current_user();

	if ( ! $idpg_org_fallback_user->exists() ) {
		$idpg_org_decision = 'guest';
	} elseif ( array_intersect( array( 'event_organizer', 'administrator', 'editor' ), (array) $idpg_org_fallback_user->roles ) ) {
		$idpg_org_decision = 'allowed';
	} else {
		$idpg_org_decision = 'denied';
	}
}

if ( 'guest' === $idpg_org_decision ) {
	wp_safe_redirect( home_url( '/login/' ) );
	exit;
}

// Авторизован, но прав на кабинет нет: отдаём именно 403 (а не 200
// с текстом «нет доступа»), чтобы код ответа был честным.
if ( 'denied' === $idpg_org_decision ) {
	status_header( 403 );
	nocache_headers();
}

// --- Сохранение профиля без JavaScript (DEV-73) -----------------------------
//
// Форма в разделе «Профиль» работает и без JS: обычный POST на эту же
// страницу. Валидацию и запись делает IDPG_Organizer_URL — тот же код,
// что и у REST-метода PUT /idpg/v1/organizer/profile.

$idpg_org_profile_notice = '';

if ( isset( $_POST['idpg_org_profile_submit'] ) ) {
	if ( ! class_exists( 'IDPG_Organizer_URL' ) ) {
		// Плагин ядра отключён — сохранять нечем.
		$idpg_org_profile_notice = 'error';
	} elseif ( 'allowed' !== IDPG_Organizer_URL::get_access_decision() ) {
		// Права проверяем так же, как в REST: путь через POST не должен
		// быть слабее пути через /idpg/v1/organizer/profile.
		$idpg_org_profile_notice = 'error';
	} else {
		$idpg_org_user_id = get_current_user_id();

		// Nonce: без него форму можно отправить со стороннего сайта.
		$idpg_org_nonce = isset( $_POST['idpg_org_profile_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['idpg_org_profile_nonce'] ) )
			: '';

		if ( ! $idpg_org_user_id || ! wp_verify_nonce( $idpg_org_nonce, 'idpg_organizer_profile' ) ) {
			$idpg_org_profile_notice = 'invalid_nonce';
		} else {
			$idpg_org_input = array();

			foreach ( array( 'display_name', 'org_name', 'contact_name', 'phone', 'avatar_id' ) as $idpg_org_field ) {
				if ( ! isset( $_POST[ $idpg_org_field ] ) ) {
					continue;
				}

				$idpg_org_input[ $idpg_org_field ] = wp_unslash( $_POST[ $idpg_org_field ] );
			}

			$idpg_org_clean = IDPG_Organizer_URL::sanitize_profile_input( $idpg_org_input );
			$idpg_org_saved = is_wp_error( $idpg_org_clean )
				? $idpg_org_clean
				: IDPG_Organizer_URL::update_profile( $idpg_org_user_id, $idpg_org_clean );

			$idpg_org_profile_notice = is_wp_error( $idpg_org_saved ) ? 'error' : 'saved';

			// Post/Redirect/Get: после редиректа F5 не переотправляет форму.
			wp_safe_redirect(
				add_query_arg(
					'profile',
					$idpg_org_profile_notice,
					function_exists( 'idpg_organizer_section_url' )
						? idpg_organizer_section_url( 'profile' )
						: home_url( '/organizer/profile/' )
				)
			);
			exit;
		}
	}
}

// Уведомление после редиректа (см. Post/Redirect/Get выше).
if ( ! $idpg_org_profile_notice && isset( $_GET['profile'] ) ) {
	$idpg_org_profile_notice = sanitize_key( wp_unslash( $_GET['profile'] ) );
}

// --- Данные для разметки -------------------------------------------------

$idpg_org_user    = wp_get_current_user();
$idpg_org_menu    = function_exists( 'idpg_organizer_menu' ) ? idpg_organizer_menu() : array();
$idpg_org_current = function_exists( 'idpg_organizer_current_section' ) ? idpg_organizer_current_section() : 'dashboard';

get_header();
?>

	<main id="primary" class="site-main idpg-org-page">
		<div class="container">

		<?php if ( 'denied' === $idpg_org_decision ) : ?>

			<div class="idpg-org-denied">
				<h1 class="idpg-org-denied__title"><?php esc_html_e( 'Нет доступа', 'idpg_core' ); ?></h1>
				<p class="idpg-org-denied__text">
					<?php esc_html_e( 'Личный кабинет доступен только организаторам. Если вы организатор, войдите под своим аккаунтом или подтвердите регистрацию по ссылке из письма.', 'idpg_core' ); ?>
				</p>
				<p class="idpg-org-denied__actions">
					<a class="idpg-btn idpg-btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<?php esc_html_e( '← На главную', 'idpg_core' ); ?>
					</a>
					<a class="idpg-btn idpg-btn--secondary" href="<?php echo esc_url( home_url( '/login/' ) ); ?>">
						<?php esc_html_e( 'Войти другим аккаунтом', 'idpg_core' ); ?>
					</a>
				</p>
			</div>

		<?php else : ?>

			<header class="idpg-org-header">
				<h1 class="idpg-org-header__title"><?php esc_html_e( 'Кабинет организатора', 'idpg_core' ); ?></h1>
				<p class="idpg-org-header__subtitle">
					<?php
					printf(
						/* translators: %s: имя пользователя. */
						esc_html__( 'Вы вошли как %s', 'idpg_core' ),
						esc_html( $idpg_org_user->display_name ? $idpg_org_user->display_name : __( 'организатор', 'idpg_core' ) )
					);
					?>
				</p>
			</header>

			<div class="idpg-org-layout">

				<nav class="idpg-org-sidebar" aria-label="<?php esc_attr_e( 'Разделы кабинета', 'idpg_core' ); ?>">
					<ul class="idpg-org-nav">
						<?php foreach ( $idpg_org_menu as $idpg_org_slug => $idpg_org_item ) : ?>
							<?php $idpg_org_is_active = ( (string) $idpg_org_item['slug'] === (string) $idpg_org_current ); ?>
							<li class="idpg-org-nav__item">
								<a
									class="idpg-org-nav__link<?php echo $idpg_org_is_active ? ' is-active' : ''; ?>"
									href="<?php echo esc_url( $idpg_org_item['url'] ); ?>"
									data-idpg-org-section="<?php echo esc_attr( $idpg_org_item['slug'] ); ?>"
									<?php echo $idpg_org_is_active ? ' aria-current="page"' : ''; ?>
								>
									<?php if ( ! empty( $idpg_org_item['icon'] ) ) : ?>
										<span class="idpg-org-nav__icon" aria-hidden="true"><?php echo esc_html( $idpg_org_item['icon'] ); ?></span>
									<?php endif; ?>
									<span class="idpg-org-nav__label"><?php echo esc_html( $idpg_org_item['label'] ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>

				<div class="idpg-org-content">
					<?php foreach ( $idpg_org_menu as $idpg_org_slug => $idpg_org_item ) : ?>
						<?php $idpg_org_is_active = ( (string) $idpg_org_item['slug'] === (string) $idpg_org_current ); ?>
						<section
							class="idpg-org-section"
							id="idpg-org-section-<?php echo esc_attr( $idpg_org_item['slug'] ); ?>"
							data-idpg-org-panel="<?php echo esc_attr( $idpg_org_item['slug'] ); ?>"
							aria-label="<?php echo esc_attr( $idpg_org_item['label'] ); ?>"
							<?php if ( ! $idpg_org_is_active ) : ?>
								hidden
							<?php endif; ?>
						>
							<?php
							// Раздел рендерится серверно. Если для раздела,
							// добавленного через фильтр, нет template-part,
							// get_template_part() молча ничего не выведет.
							get_template_part( 'template-parts/organizer/section', $idpg_org_item['slug'] );
							?>
						</section>
					<?php endforeach; ?>
				</div>

			</div>

		<?php endif; ?>

		</div>
	</main>

<?php
get_footer();
