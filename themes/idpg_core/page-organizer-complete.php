<?php
/**
 * Template Name: Organizer Complete
 * Template Post Type: page
 *
 * Страница доуполнения анкеты организатора, созданного через
 * соц-кнопку с intent=organizer (флаг _idpg_org_pending).
 *
 * Форма отправляется в REST /idpg/v1/auth/organizer-complete
 * (обработчик — themes/idpg_core/assets/js/organizer-complete.js).
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$user = wp_get_current_user();
$is_pending_organizer = $user->exists()
	&& in_array( 'event_organizer', (array) $user->roles, true )
	&& get_user_meta( $user->ID, '_idpg_org_pending', true );

// Плейсхолдер-email от соцсети (@*.social) — поле email обязательно.
$needs_email = (bool) preg_match( '/@([a-z0-9\-]+)\.social$/i', (string) $user->user_email );

$display_name = $user->exists() ? $user->display_name : '';
$first_name   = $user->exists() ? $user->first_name : '';
$last_name    = $user->exists() ? $user->last_name : '';
?>

<main id="primary" class="site-main">
	<div class="container">
		<div class="auth auth--complete">
			<div class="auth__card auth__card--wide">

				<div class="auth__header">
					<?php if ( has_custom_logo() ) : ?>
						<?php the_custom_logo(); ?>
					<?php else : ?>
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="auth__logo-link">
							<span class="auth__logo-text">Иду по городу</span>
						</a>
					<?php endif; ?>
					<h1 class="auth__title"><?php esc_html_e( 'Анкета организатора', 'idpg_core' ); ?></h1>
					<p class="auth__subtitle">
						<?php
						printf(
							/* translators: %s: имя пользователя из соцсети. */
							esc_html__( '%s, расскажите о вашей организации — это нужно для публикации событий.', 'idpg_core' ),
							esc_html( $display_name ? $display_name : __( 'Коллега', 'idpg_core' ) )
						);
						?>
					</p>
				</div>

				<?php if ( ! $is_pending_organizer ) : ?>

					<div class="auth__message auth__message--info" role="status">
						<span class="auth__message-text">
							<?php
							if ( $user->exists() && in_array( 'event_organizer', (array) $user->roles, true ) ) {
								esc_html_e( 'Анкета уже заполнена. Перейдите в панель организатора.', 'idpg_core' );
							} else {
								esc_html_e( 'Страница доступна организаторам, завершающим регистрацию. Войдите через страницу входа.', 'idpg_core' );
							}
							?>
						</span>
					</div>
					<p class="auth__footer-link">
						<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="auth__link">
							<?php esc_html_e( '← Перейти ко входу', 'idpg_core' ); ?>
						</a>
					</p>

				<?php else : ?>

					<form class="auth__form" id="organizer-complete-form" method="post" autocomplete="on" novalidate>
						<div class="auth__field">
							<label for="oc-org-name" class="auth__label"><?php esc_html_e( 'Название организации', 'idpg_core' ); ?></label>
							<input
								id="oc-org-name"
								class="auth__input"
								type="text"
								name="org_name"
								required
								autocomplete="organization"
								placeholder="<?php esc_attr_e( 'Название организации', 'idpg_core' ); ?>"
							>
						</div>

						<div class="auth__field">
							<label for="oc-contact-name" class="auth__label"><?php esc_html_e( 'ФИО представителя', 'idpg_core' ); ?></label>
							<input
								id="oc-contact-name"
								class="auth__input"
								type="text"
								name="contact_name"
								required
								autocomplete="name"
								value="<?php echo esc_attr( trim( $first_name . ' ' . $last_name ) ); ?>"
								placeholder="<?php esc_attr_e( 'Иван Иванов', 'idpg_core' ); ?>"
							>
						</div>

						<?php if ( $needs_email ) : ?>
							<div class="auth__field">
								<label for="oc-email" class="auth__label">
									<?php esc_html_e( 'Email (соцсеть не передала его)', 'idpg_core' ); ?>
								</label>
								<input
									id="oc-email"
									class="auth__input"
									type="email"
									name="email"
									required
									autocomplete="email"
									placeholder="email@example.com"
								>
							</div>
						<?php endif; ?>

						<div class="auth__field">
							<label for="oc-phone" class="auth__label"><?php esc_html_e( 'Телефон', 'idpg_core' ); ?></label>
							<input
								id="oc-phone"
								class="auth__input"
								type="tel"
								name="phone"
								required
								autocomplete="tel"
								placeholder="+7 (999) 123-45-67"
							>
						</div>

						<div class="auth__field">
							<label for="oc-password" class="auth__label">
								<?php esc_html_e( 'Пароль для входа через форму (необязательно)', 'idpg_core' ); ?>
							</label>
							<input
								id="oc-password"
								class="auth__input"
								type="password"
								name="password"
								minlength="8"
								autocomplete="new-password"
								placeholder="<?php esc_attr_e( 'Минимум 8 символов', 'idpg_core' ); ?>"
							>
							<p class="auth__hint">
								<?php esc_html_e( 'Задайте пароль, чтобы входить также через форму с email и паролем. Можно оставить пустым — тогда вход будет только через соц-кнопку.', 'idpg_core' ); ?>
							</p>
						</div>

						<div class="auth__field">
							<label for="oc-password-rep" class="auth__label"><?php esc_html_e( 'Повторите пароль', 'idpg_core' ); ?></label>
							<input
								id="oc-password-rep"
								class="auth__input"
								type="password"
								name="password_rep"
								minlength="8"
								autocomplete="new-password"
								placeholder="<?php esc_attr_e( 'Повторите пароль', 'idpg_core' ); ?>"
							>
						</div>

						<div id="organizer-complete-status" class="auth__message" role="status" hidden>
							<span class="auth__message-text"></span>
						</div>

						<button class="auth__submit" type="submit">
							<span class="auth__submit-text"><?php esc_html_e( 'Сохранить и продолжить', 'idpg_core' ); ?></span>
							<span class="auth__spinner" aria-hidden="true"></span>
						</button>
					</form>

				<?php endif; ?>
			</div>
		</div>
	</div>
</main>

<?php
get_footer();
