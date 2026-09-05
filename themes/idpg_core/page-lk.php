<?php
/**
 * Шаблон личного кабинета для простых пользователей.
 *
 * Доступен только авторизованным пользователям с ролью subscriber.
 * Организаторы и администраторы перенаправляются на главную.
 *
 * @package idpg_core
 */

if ( ! is_user_logged_in() ) {
	wp_safe_redirect( home_url( '/login/' ) );
	exit;
}

$current_user = wp_get_current_user();

if ( ! in_array( 'subscriber', (array) $current_user->roles, true ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$page_title = __( 'Личный кабинет', 'idpg_core' );

get_header();
?>

<main id="primary" class="site-main">
	<div class="container">

		<h1 class="page-title"><?php echo esc_html( $page_title ); ?></h1>

		<div class="lk-container">

			<div class="lk-card">
				<h2 class="lk-card__title"><?php esc_html_e( 'Профиль', 'idpg_core' ); ?></h2>

				<div class="lk-profile">
					<div class="lk-profile__avatar">
						<?php echo get_avatar( $current_user->ID, 96 ); ?>
					</div>

					<div class="lk-profile__info">
						<p class="lk-profile__name">
							<?php echo esc_html( $current_user->display_name ); ?>
						</p>
						<p class="lk-profile__email">
							<?php echo esc_html( $current_user->user_email ); ?>
						</p>
					</div>
				</div>

				<div class="lk-card__actions">
					<button
						type="button"
						class="idpg-btn idpg-btn--primary"
						id="lk-edit-profile-btn"
					>
						<?php esc_html_e( 'Редактировать профиль', 'idpg_core' ); ?>
					</button>
				</div>

				<div class="lk-card__body" id="lk-edit-form" style="display:none;">
					<form class="lk-form" id="lk-profile-form" novalidate>
						<div class="lk-form__field">
							<label for="lk-display-name" class="lk-form__label">
								<?php esc_html_e( 'Имя', 'idpg_core' ); ?>
							</label>
							<input
								id="lk-display-name"
								class="lk-form__input"
								type="text"
								name="display_name"
								value="<?php echo esc_attr( $current_user->display_name ); ?>"
								required
							>
						</div>

						<div class="lk-form__field">
							<label for="lk-email" class="lk-form__label">
								<?php esc_html_e( 'Email', 'idpg_core' ); ?>
							</label>
							<input
								id="lk-email"
								class="lk-form__input"
								type="email"
								name="email"
								value="<?php echo esc_attr( $current_user->user_email ); ?>"
								required
							>
						</div>

						<div class="lk-form__field">
							<label for="lk-current-password" class="lk-form__label">
								<?php esc_html_e( 'Текущий пароль (обязателен при смене пароля)', 'idpg_core' ); ?>
							</label>
							<input
								id="lk-current-password"
								class="lk-form__input"
								type="password"
								name="current_password"
								autocomplete="current-password"
							>
						</div>

						<div class="lk-form__field">
							<label for="lk-new-password" class="lk-form__label">
								<?php esc_html_e( 'Новый пароль (оставьте пустым, если не менять)', 'idpg_core' ); ?>
							</label>
							<input
								id="lk-new-password"
								class="lk-form__input"
								type="password"
								name="new_password"
								minlength="8"
								autocomplete="new-password"
							>
						</div>

						<div class="lk-form__field">
							<label for="lk-password-confirm" class="lk-form__label">
								<?php esc_html_e( 'Подтверждение нового пароля', 'idpg_core' ); ?>
							</label>
							<input
								id="lk-password-confirm"
								class="lk-form__input"
								type="password"
								name="password_confirm"
								autocomplete="new-password"
							>
						</div>

					<div class="lk-form__field lk-form__field--wide">
							<label for="lk-avatar" class="lk-form__label">
								<?php esc_html_e( 'Аватар (JPG, PNG, до 5 МБ)', 'idpg_core' ); ?>
							</label>
							<input
								id="lk-avatar"
								class="lk-form__input"
								type="file"
								name="avatar"
								accept="image/jpeg,image/png,image/gif"
							>
							<div class="lk-form__avatar-preview" id="lk-avatar-preview" style="margin-top:10px;">
								<?php echo get_avatar( $current_user->ID, 80 ); ?>
							</div>
							<button type="button" class="idpg-btn idpg-btn--secondary" id="lk-remove-avatar-btn" style="margin-top:10px;">
								<?php esc_html_e( 'Удалить аватар', 'idpg_core' ); ?>
							</button>
						</div>

						<div class="lk-form__messages" id="lk-form-messages" role="alert" aria-live="polite" hidden>
							<p class="lk-form__message"></p>
						</div>

						<div class="lk-card__actions">
							<button
								type="submit"
								class="idpg-btn idpg-btn--primary"
							>
								<span class="idpg-btn__text">
									<?php esc_html_e( 'Сохранить', 'idpg_core' ); ?>
								</span>
								<span class="idpg-btn__spinner" aria-hidden="true"></span>
							</button>
							<button
								type="button"
								class="idpg-btn idpg-btn--secondary"
								id="lk-cancel-edit-btn"
							>
								<?php esc_html_e( 'Отмена', 'idpg_core' ); ?>
							</button>
						</div>
					</form>
				</div>
			</div>

			<div class="lk-card">
				<h2 class="lk-card__title"><?php esc_html_e( 'Статистика', 'idpg_core' ); ?></h2>

				<div class="lk-stats">
					<div class="lk-stats__item">
						<span class="lk-stats__number" id="lk-events-count">0</span>
						<span class="lk-stats__label">
							<?php esc_html_e( 'Событий', 'idpg_core' ); ?>
						</span>
					</div>
					<div class="lk-stats__item">
						<span class="lk-stats__number" id="lk-venues-count">0</span>
						<span class="lk-stats__label">
							<?php esc_html_e( 'Площадок', 'idpg_core' ); ?>
						</span>
					</div>
				</div>
			</div>

			<div class="lk-card lk-card--events">
				<h2 class="lk-card__title">
					<?php esc_html_e( 'События в моём городе', 'idpg_core' ); ?>
					<span class="lk-card__city" id="lk-city-name"></span>
				</h2>

				<div class="lk-events" id="lk-events-list">
					<div class="lk-events__loading" id="lk-events-loading">
						<?php esc_html_e( 'Загрузка...', 'idpg_core' ); ?>
					</div>
				</div>
			</div>

		</div>

	</div>
</main>

<?php
get_footer();