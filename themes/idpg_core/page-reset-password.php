<?php
/**
 * Шаблон страницы восстановления пароля.
 *
 * Содержит форму ввода нового пароля. Читает ключ сброса
 * и ID пользователя из ссылки из письма (?key=...&id=...),
 * проверяет их и отправляет новый пароль через REST API.
 *
 * @package idpg_core
 */

$user_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
$reset_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

$key_valid = false;

if ( $user_id && $reset_key ) {
	$user = get_user_by( 'id', $user_id );

	if ( $user ) {
		$check = check_password_reset_key( $reset_key, $user->user_login );

		if ( ! is_wp_error( $check ) ) {
			$key_valid = true;
		}
	}
}

/**
 * PHP-обработка формы сброса пароля (fallback при отключённом JS).
 */
$reset_success = false;
$reset_error   = '';

if ( $key_valid && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['new_password'] ) ) {
	// CSRF-защита.
	if ( ! isset( $_POST['idpg_reset_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['idpg_reset_nonce'] ) ), 'idpg_reset_password' ) ) {
		$reset_error = __( 'Ошибка безопасности. Попробуйте снова.', 'idpg_core' );
	}

	$new_password = sanitize_text_field( wp_unslash( $_POST['new_password'] ) );
	$password_confirm = sanitize_text_field( wp_unslash( $_POST['password_confirm'] ) );

	if ( empty( $reset_error ) && strlen( $new_password ) < 8 ) {
		$reset_error = __( 'Пароль должен содержать минимум 8 символов.', 'idpg_core' );
	} elseif ( empty( $reset_error ) && $new_password !== $password_confirm ) {
		$reset_error = __( 'Пароли не совпадают.', 'idpg_core' );
	} else {
		reset_password( $user, $new_password );
		$reset_success = true;
	}
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<?php wp_head(); ?>
</head>
<body class="auth-body">
<?php wp_body_open(); ?>

<div class="auth">

	<div class="auth__card">

		<!-- Шапка -->
		<div class="auth__header">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="auth__logo-link">
				<?php if ( has_custom_logo() ) : ?>
					<?php the_custom_logo(); ?>
				<?php else : ?>
					<span class="auth__logo-text">Иду по городу</span>
				<?php endif; ?>
			</a>
			<h1 class="auth__title">Восстановление пароля</h1>
		</div>

		<!-- Блок сообщений -->
		<div id="auth-message" class="auth__message" role="alert" aria-live="polite" hidden>
			<p class="auth__message-text"></p>
		</div>

		<?php if ( $key_valid && ! $reset_success && ! $reset_error ) : ?>

			<!-- Форма ввода нового пароля -->
			<div id="reset-password-form" class="auth__panel auth__panel--active">
				<form class="auth__form" method="post" autocomplete="off" novalidate>
					<?php wp_nonce_field( 'idpg_reset_password', 'idpg_reset_nonce' ); ?>
					<p class="auth__description">
						Введите новый пароль. Он вступит в силу сразу после сохранения.
					</p>

					<div class="auth__field">
						<label for="reset-password" class="auth__label">Новый пароль</label>
						<input
							id="reset-password"
							class="auth__input"
							type="password"
							name="new_password"
							required
							minlength="8"
							autocomplete="new-password"
							placeholder="Минимум 8 символов"
						>
					</div>

					<div class="auth__field">
						<label for="reset-password-confirm" class="auth__label">Подтверждение пароля</label>
						<input
							id="reset-password-confirm"
							class="auth__input"
							type="password"
							name="password_confirm"
							required
							minlength="8"
							autocomplete="new-password"
							placeholder="Повторите пароль"
						>
					</div>

					<input type="hidden" name="user_id" value="<?php echo esc_attr( $user_id ); ?>">
					<input type="hidden" name="reset_key" value="<?php echo esc_attr( $reset_key ); ?>">

					<button class="auth__submit" type="submit">
						<span class="auth__submit-text">Сохранить пароль</span>
						<span class="auth__spinner" aria-hidden="true"></span>
					</button>
				</form>
			</div>

		<?php elseif ( $reset_success ) : ?>

			<!-- Успешная смена пароля -->
			<div class="auth__panel auth__panel--active">
				<p class="auth__description">
					Пароль успешно изменён.
				</p>
				<p class="auth__footer-link">
					<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="auth__link">
						Вернуться ко входу
					</a>
				</p>
			</div>

		<?php elseif ( $reset_error ) : ?>

			<!-- Ошибка сброса пароля -->
			<div class="auth__panel auth__panel--active">
				<p class="auth__description" style="color:#c00;">
					<?php echo esc_html( $reset_error ); ?>
				</p>
				<p class="auth__footer-link">
					<a href="<?php echo esc_url( add_query_arg( 'action', 'forgot', home_url( '/login/' ) ) ); ?>" class="auth__link">
						Запросить новую ссылку
					</a>
				</p>
				<p class="auth__footer-link">
					<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="auth__link">
						← Вернуться ко входу
					</a>
				</p>
			</div>

		<?php else : ?>

			<!-- Ключ невалиден -->
			<div class="auth__panel auth__panel--active">
				<p class="auth__description">
					Ссылка для восстановления пароля недействительна или устарела.
				</p>
				<p class="auth__footer-link">
					<a href="<?php echo esc_url( add_query_arg( 'action', 'forgot', home_url( '/login/' ) ) ); ?>" class="auth__link">
						Запросить новую ссылку
					</a>
				</p>
				<p class="auth__footer-link">
					<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="auth__link">
						← Вернуться ко входу
					</a>
				</p>
			</div>

		<?php endif; ?>

	</div>

</div>

<script type="text/javascript">
	window.idpgAuth = {
		restUrl: '<?php echo esc_url_raw( rest_url( 'idpg/v1' ) ); ?>',
		nonce: '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
		loginUrl: '<?php echo esc_url( home_url( '/login/' ) ); ?>',
	};
</script>

<?php wp_footer(); ?>
</body>
</html>
