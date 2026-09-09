<?php
/**
 * Шаблон страницы входа и регистрации.
 *
 * Содержит два таба: вход, регистрация организатора
 * и форму восстановления пароля.
 *
 * @package idpg_core
 */

// Редирект авторизованных пользователей.
if ( is_user_logged_in() ) {
	$user = wp_get_current_user();
	$redirect_url = home_url( '/' );

	if ( in_array( 'administrator', (array) $user->roles, true ) ) {
		$redirect_url = admin_url();
	} elseif ( in_array( 'editor', (array) $user->roles, true ) ) {
		$redirect_url = admin_url();
	} elseif ( in_array( 'event_organizer', (array) $user->roles, true ) ) {
		$redirect_url = admin_url();
	}

	wp_safe_redirect( $redirect_url );
	exit;
}

$is_forgot = isset( $_GET['action'] ) && 'forgot' === $_GET['action'];

/**
 * Тематическая картинка для правой панели: обложка случайного события.
 */
$hero_image_url = '';
$hero_events    = get_posts(
	array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'orderby'        => 'rand',
		'meta_key'       => '_thumbnail_id',
		'no_found_rows'  => true,
	)
);
if ( ! empty( $hero_events ) ) {
	$hero_image_url = IDPG_Media::idpg_get_poster_url( $hero_events[0]->ID, 'large' );
}

/**
 * PHP-обработка форм (fallback при отключённом JS).
 */
$login_error   = '';
$register_error = '';
$forgot_sent   = false;

// Соц. вход: отображение ошибки из редиректа (?idpg_social_error=...).
if ( isset( $_GET['idpg_social_error'] ) ) {
	$code = sanitize_text_field( wp_unslash( $_GET['idpg_social_error'] ) );
	$map  = array(
		'access_denied'          => __( 'Вы отклонили доступ.', 'idpg_core' ),
		'invalid_state'          => __( 'Ошибка безопасности (state). Попробуйте снова.', 'idpg_core' ),
		'token_failed'           => __( 'Не удалось получить токен.', 'idpg-core' ),
		'profile_failed'         => __( 'Не удалось получить профиль.', 'idpg-core' ),
		'login_failed'           => __( 'Ошибка входа.', 'idpg-core' ),
		'provider_not_configured'=> __( 'Провайдер не настроен.', 'idpg-core' ),
		'provider_not_found'     => __( 'Провайдер не найден.', 'idpg-core' ),
	);
	$login_error = isset( $map[ $code ] ) ? $map[ $code ] : sprintf( __( 'Ошибка соц. входа: %s', 'idpg-core' ), esc_html( $code ) );
}

// Ошибки OAuth от плагина авторизации через соцсети (wp-auth-ru / idpg-auth),
// они редиректят на кастомную страницу входа (см. фильтр login_url в теме).
foreach ( array( 'yandex', 'vk', 'max' ) as $provider ) {
	$error_key = $provider . '_oauth_error';
	if ( isset( $_GET[ $error_key ] ) ) {
		$details    = sanitize_text_field( wp_unslash( $_GET[ $error_key ] ) );
		$provider_label = array(
			'yandex' => __( 'Яндекс', 'idpg_core' ),
			'vk'     => __( 'ВКонтакте', 'idpg_core' ),
			'max'    => __( 'MAX', 'idpg_core' ),
		);
		$login_error = sprintf(
			/* translators: %1$s — провайдер авторизации, %2$s — текст ошибки. */
			__( 'Ошибка входа через %1$s: %2$s', 'idpg_core' ),
			$provider_label[ $provider ],
			$details
		);
		break;
	}
}

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {

	// CSRF-защита: проверяем nonce для всех форм на странице.
	if ( ! isset( $_POST['idpg_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['idpg_login_nonce'] ) ), 'idpg_login' ) ) {
		$login_error = __( 'Ошибка безопасности. Попробуйте снова.', 'idpg_core' );
	}

	if ( empty( $login_error ) ) :
	// Форма восстановления пароля.
	if ( $is_forgot && isset( $_POST['email'] ) ) {
		$email = sanitize_email( wp_unslash( $_POST['email'] ) );

		if ( ! is_email( $email ) ) {
			$login_error = __( 'Введите корректный email.', 'idpg_core' );
		} else {
			retrieve_password( $email );
			$forgot_sent = true;
		}
	}

	// Форма входа.
	if ( ! $is_forgot && isset( $_POST['email'] ) && isset( $_POST['password'] ) ) {
		$email    = sanitize_email( wp_unslash( $_POST['email'] ) );
		$password = wp_unslash( $_POST['password'] );

		if ( ! is_email( $email ) ) {
			$login_error = __( 'Введите корректный email.', 'idpg_core' );
		} elseif ( empty( $password ) ) {
			$login_error = __( 'Введите пароль.', 'idpg_core' );
		} else {
			$credentials = array(
				'user_login'    => $email,
				'user_password' => $password,
				'remember'      => isset( $_POST['remember'] ),
			);
			$user = wp_signon( $credentials, false );

			if ( is_wp_error( $user ) ) {
				$login_error = __( 'Неверный email или пароль.', 'idpg_core' );
			} else {
				$roles = (array) $user->roles;

				if ( in_array( 'administrator', $roles, true )
					|| in_array( 'editor', $roles, true )
					|| in_array( 'event_organizer', $roles, true )
				) {
					$redirect_url = admin_url();
				} else {
					$redirect_url = home_url( '/' );
				}

				wp_safe_redirect( $redirect_url );
				exit;
			}
		}
	}

	// Форма регистрации организатора.
	if ( ! $is_forgot && isset( $_POST['org_name'] ) && isset( $_POST['email'] ) ) {
		$org_name     = sanitize_text_field( wp_unslash( $_POST['org_name'] ) );
		$contact_name = sanitize_text_field( wp_unslash( $_POST['contact_name'] ) );
		$email        = sanitize_email( wp_unslash( $_POST['email'] ) );
		$phone        = sanitize_text_field( wp_unslash( $_POST['phone'] ) );
		$password     = wp_unslash( $_POST['password'] );
		$confirm      = wp_unslash( $_POST['password_confirm'] );
		$terms        = isset( $_POST['terms'] ) ? (bool) $_POST['terms'] : false;

		if ( ! $terms ) {
			$register_error = __( 'Необходимо согласие с офертой и политикой конфиденциальности.', 'idpg_core' );
		} elseif ( empty( $org_name ) ) {
			$register_error = __( 'Введите название организации.', 'idpg_core' );
		} elseif ( empty( $contact_name ) ) {
			$register_error = __( 'Введите ФИО представителя.', 'idpg_core' );
		} elseif ( empty( $phone ) ) {
			$register_error = __( 'Введите номер телефона.', 'idpg_core' );
		} elseif ( strlen( $password ) < 8 ) {
			$register_error = __( 'Пароль должен содержать минимум 8 символов.', 'idpg_core' );
		} elseif ( $password !== $confirm ) {
			$register_error = __( 'Пароли не совпадают.', 'idpg_core' );
		} elseif ( ! is_email( $email ) ) {
			$register_error = __( 'Введите корректный email.', 'idpg_core' );
		} elseif ( email_exists( $email ) ) {
			$register_error = __( 'Этот email уже зарегистрирован.', 'idpg_core' );
		} else {
			$user_id = wp_create_user( $email, $password, $email );

			if ( is_wp_error( $user_id ) ) {
				$register_error = __( 'Ошибка при создании пользователя.', 'idpg_core' );
			} else {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => $contact_name,
					)
				);
				$user = new WP_User( $user_id );
				$user->set_role( 'event_organizer' );
				update_user_meta( $user_id, 'idpg_org_name', $org_name );
				update_user_meta( $user_id, 'idpg_contact_name', $contact_name );
				update_user_meta( $user_id, 'idpg_phone', $phone );
				wp_set_current_user( $user_id );
				wp_set_auth_cookie( $user_id );
				do_action( 'wp_login', $email, $user_id );
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
		}
	}

	endif; // empty( $login_error )
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
			<?php if ( has_custom_logo() ) : ?>
				<div class="auth__logo-link"><?php the_custom_logo(); ?></div>
			<?php else : ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="auth__logo-link"><span class="auth__logo-text">Иду по городу</span></a>
			<?php endif; ?>
			<h1 class="auth__title">Добро пожаловать!</h1>
		</div>

		<!-- Блок сообщений -->
		<div id="auth-message" class="auth__message" role="alert" aria-live="polite" hidden>
			<p class="auth__message-text"></p>
		</div>

		<?php if ( ! empty( $login_error ) ) : ?>
			<div class="auth__message auth__message--error" role="alert" style="margin-bottom:16px;">
				<p class="auth__message-text" style="margin:0;color:#c00;"><?php echo esc_html( $login_error ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $register_error ) ) : ?>
			<div class="auth__message auth__message--error" role="alert" style="margin-bottom:16px;">
				<p class="auth__message-text" style="margin:0;color:#c00;"><?php echo esc_html( $register_error ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $forgot_sent ) : ?>
			<div class="auth__message auth__message--success" role="alert" style="margin-bottom:16px;">
				<p class="auth__message-text" style="margin:0;color:#0a0;"><?php esc_html_e( 'Если email существует, инструкции отправлены.', 'idpg_core' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $is_forgot ) : ?>

			<!-- Форма восстановления пароля -->
			<div id="forgot-password-form" class="auth__panel auth__panel--active">
				<form class="auth__form" method="post" autocomplete="on" novalidate>
					<?php wp_nonce_field( 'idpg_login', 'idpg_login_nonce' ); ?>
					<p class="auth__description">
						Введите email, который вы использовали при регистрации.
						Мы отправим инструкцию по восстановлению пароля.
					</p>

					<div class="auth__field">
						<label for="forgot-email" class="auth__label">Email</label>
						<input
							id="forgot-email"
							class="auth__input"
							type="email"
							name="email"
							required
							autocomplete="email"
							placeholder="email@example.com"
						>
					</div>

					<button class="auth__submit" type="submit">
						<span class="auth__submit-text">Отправить инструкции</span>
						<span class="auth__spinner" aria-hidden="true"></span>
					</button>

					<p class="auth__footer-link">
						<a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="auth__link">
							← Вернуться ко входу
						</a>
					</p>
				</form>
			</div>

		<?php else : ?>

			<!-- Табы -->
			<div class="auth__tabs" role="tablist">
				<button
					class="auth__tab auth__tab--active"
					id="tab-login"
					type="button"
					role="tab"
					aria-selected="true"
					aria-controls="login-form"
					data-tab="login"
				>
					Вход
				</button>
				<button
					class="auth__tab"
					id="tab-register-organizer"
					type="button"
					role="tab"
					aria-selected="false"
					aria-controls="register-organizer-form"
					data-tab="register-organizer"
				>
					Регистрация организатора
				</button>
			</div>

<!-- Форма входа -->
			<div id="login-form" class="auth__panel auth__panel--active" role="tabpanel" aria-labelledby="tab-login">
				<form class="auth__form" method="post" autocomplete="on" novalidate>
					<?php wp_nonce_field( 'idpg_login', 'idpg_login_nonce' ); ?>

					<div class="auth__field">
						<label for="login-email" class="auth__label">Email</label>
						<input
							id="login-email"
							class="auth__input"
							type="email"
							name="email"
							required
							autocomplete="username"
							placeholder="email@example.com"
						>
					</div>

					<div class="auth__field">
						<label for="login-password" class="auth__label">Пароль</label>
						<input
							id="login-password"
							class="auth__input"
							type="password"
							name="password"
							required
							autocomplete="current-password"
							placeholder="Ваш пароль"
						>
					</div>

					<div class="auth__field auth__field--horizontal">
						<label class="auth__checkbox-label">
							<input
								class="auth__checkbox"
								type="checkbox"
								name="remember"
								value="1"
							>
							<span class="auth__checkbox-text">Запомнить меня</span>
						</label>
					</div>

					<button class="auth__submit" type="submit">
						<span class="auth__submit-text">Войти</span>
						<span class="auth__spinner" aria-hidden="true"></span>
					</button>

					<p class="auth__footer-link">
						<a href="<?php echo esc_url( add_query_arg( 'action', 'forgot', home_url( '/login/' ) ) ); ?>" class="auth__link">
							Забыли пароль?
						</a>
					</p>

					<?php // Кнопки авторизации через соцсети (плагин idpg-auth). ?>
					<div class="auth__social auth__social--bottom">
						<?php do_action( 'login_form' ); ?>
					</div>
				</form>
			</div>

<!-- Форма регистрации организатора -->
			<div id="register-organizer-form" class="auth__panel" role="tabpanel" aria-labelledby="tab-register-organizer" hidden>
				<form class="auth__form" method="post" autocomplete="on" novalidate>
					<?php wp_nonce_field( 'idpg_login', 'idpg_login_nonce' ); ?>

					<div class="auth__field">
						<label for="reg-org-name" class="auth__label">Название организации</label>
						<input
							id="reg-org-name"
							class="auth__input"
							type="text"
							name="org_name"
							required
							autocomplete="organization"
							placeholder="Название организации"
						>
					</div>

					<div class="auth__field">
						<label for="reg-org-contact-name" class="auth__label">ФИО представителя</label>
						<input
							id="reg-org-contact-name"
							class="auth__input"
							type="text"
							name="contact_name"
							required
							autocomplete="name"
							placeholder="Иван Иванов"
						>
					</div>

					<div class="auth__field">
						<label for="reg-org-email" class="auth__label">Email</label>
						<input
							id="reg-org-email"
							class="auth__input"
							type="email"
							name="email"
							required
							autocomplete="email"
							placeholder="email@example.com"
						>
					</div>

					<div class="auth__field">
						<label for="reg-org-phone" class="auth__label">Телефон</label>
						<input
							id="reg-org-phone"
							class="auth__input"
							type="tel"
							name="phone"
							required
							autocomplete="tel"
							placeholder="+7 (999) 123-45-67"
						>
					</div>

					<div class="auth__field">
						<label for="reg-org-password" class="auth__label">Пароль</label>
						<input
							id="reg-org-password"
							class="auth__input"
							type="password"
							name="password"
							required
							minlength="8"
							autocomplete="new-password"
							placeholder="Минимум 8 символов"
						>
					</div>

					<div class="auth__field">
						<label for="reg-org-password-confirm" class="auth__label">Подтверждение пароля</label>
						<input
							id="reg-org-password-confirm"
							class="auth__input"
							type="password"
							name="password_confirm"
							required
							autocomplete="new-password"
							placeholder="Повторите пароль"
						>
					</div>

					<div class="auth__field auth__field--horizontal">
						<label class="auth__checkbox-label">
							<input
								class="auth__checkbox"
								type="checkbox"
								name="terms"
								value="1"
								required
							>
							<span class="auth__checkbox-text">
								Согласен с
								<a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>" target="_blank" class="auth__link">
									офертой
								</a>
								и
								<a href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>" target="_blank" class="auth__link">
									политикой конфиденциальности
								</a>
							</span>
						</label>
					</div>

					<p class="auth__hint">
						После регистрации нужно будет загрузить документы для верификации.
					</p>

					<button class="auth__submit" type="submit">
						<span class="auth__submit-text">Зарегистрироваться</span>
						<span class="auth__spinner" aria-hidden="true"></span>
					</button>
				</form>
			</div>

		<?php endif; ?>

	</div>

		<!-- Правая панель: тематическая картинка + приветствие -->
		<aside class="auth__hero" aria-hidden="true">
			<?php if ( ! empty( $hero_image_url ) ) : ?>
				<img class="auth__hero-img" src="<?php echo esc_url( $hero_image_url ); ?>" alt="">
			<?php endif; ?>
			<div class="auth__hero-overlay"></div>
			<div class="auth__hero-content">
				<span class="auth__hero-badge">Афиша «Иду по городу»</span>
				<h2 class="auth__hero-title">Вся культурная жизнь города&nbsp;— в одном месте</h2>
				<p class="auth__hero-text">
					Концерты, выставки, спектакли, лекции и мастер-классы рядом с вами.
					Выбирайте события на афише, сохраняйте избранное и не пропускайте самое интересное.
				</p>
				<ul class="auth__hero-list">
					<li>Актуальная афиша событий города на каждый день</li>
					<li>Избранное и напоминания о будущих событиях</li>
					<li>Для организаторов — бесплатное размещение мероприятий</li>
				</ul>
			</div>
		</aside>

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
