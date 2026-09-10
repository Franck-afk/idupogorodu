<?php
/**
 * Кастомные HTML-письма для авторизации.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Auth_Emails
 *
 * Управляет HTML-шаблонами писем: восстановление пароля,
 * приветственное письмо после регистрации, а также настройки
 * отправителя по умолчанию.
 */
class IDPG_Auth_Emails {

	/**
	 * Экземпляр синглтона.
	 *
	 * @var IDPG_Auth_Emails|null
	 */
	private static $instance = null;

	/**
	 * Email-адрес отправителя.
	 *
	 * @var string
	 */
	private $from_email = 'noreply@idupogorodu.ru';

	/**
	 * Имя отправителя.
	 *
	 * @var string
	 */
	private $from_name = 'Иду по городу';

	/**
	 * URL логотипа для писем.
	 *
	 * @var string
	 */
	private $logo_url = '';

	/**
	 * Временный буфер пароля для нового организатора.
	 *
	 * Заполняется в момент регистрации (до wp_create_user), т.к. после
	 * создания пользователя пароль хранится только в виде хэша и его
	 * нельзя передать в письмо. Ключ — email, значение — plain-пароль.
	 *
	 * @var array
	 */
	private static $pending_passwords = array();

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Auth_Emails
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Приватный конструктор.
	 *
	 * Регистрирует фильтры для кастомизации системных писем WordPress.
	 */
	private function __construct() {
		add_filter( 'wp_mail_from', array( $this, 'set_from_email' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'set_from_name' ) );
		add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );

		add_filter( 'retrieve_password_title', array( $this, 'set_password_reset_title' ) );
		add_filter( 'retrieve_password_message', array( $this, 'format_password_reset_message' ), 10, 4 );

		add_action( 'user_register', array( $this, 'send_welcome_email' ) );
	}

	/**
	 * Устанавливает email-адрес отправителя.
	 *
	 * @return string
	 */
	public function set_from_email() {
		return $this->from_email;
	}

	/**
	 * Устанавливает имя отправителя.
	 *
	 * @return string
	 */
	public function set_from_name() {
		return $this->from_name;
	}

	/**
	 * Устанавливает Content-Type для всех писем.
	 *
	 * @return string
	 */
	public function set_html_content_type() {
		return 'text/html';
	}

	/**
	 * Устанавливает тему письма восстановления пароля.
	 *
	 * @return string
	 */
	public function set_password_reset_title() {
		return 'Восстановление пароля — Иду по городу';
	}

	/**
	 * Форматирует письмо восстановления пароля в HTML.
	 *
	 * @param string $message   Исходный текст письма (игнорируется).
	 * @param string $key       Ключ сброса пароля.
	 * @param string $user_login Логин пользователя.
	 * @param object $user_data  Объект WP_User.
	 * @return string HTML-код письма.
	 */
	public function format_password_reset_message( $message, $key, $user_login, $user_data ) {
		$reset_url = home_url(
			'/reset-password/?key=' . rawurlencode( $key ) . '&id=' . $user_data->ID
		);

		$content  = '<p style="margin: 0 0 20px; font-size: 16px; line-height: 1.6; color: #4a4a4a;">';
		$content .= 'Вы запросили сброс пароля. Нажмите кнопку ниже, чтобы задать новый пароль:';
		$content .= '</p>';

		$content .= $this->render_button( 'Сбросить пароль', $reset_url );

		$content .= '<p style="margin: 28px 0 0; font-size: 14px; line-height: 1.5; color: #888888;">';
		$content .= 'Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо.';
		$content .= '</p>';

		return $this->render_email_template( $content, 'Восстановление пароля' );
	}

	/**
	 * Запоминает пароль нового организатора до срабатывания хука user_register.
	 *
	 * Вызывается в точке регистрации ПЕРЕД wp_create_user(). После отправки
	 * приветственного письма значение очищается.
	 *
	 * @param string $email    Email нового пользователя.
	 * @param string $password Plain-пароль, введённый при регистрации.
	 * @return void
	 */
	public static function remember_password( $email, $password ) {
		if ( $email ) {
			self::$pending_passwords[ $email ] = $password;
		}
	}

	/**
	 * Извлекает и очищает отложенный пароль по email.
	 *
	 * @param string $email Email организатора.
	 * @return string Plain-пароль или пустая строка.
	 */
	private static function take_password( $email ) {
		if ( isset( self::$pending_passwords[ $email ] ) ) {
			$password = self::$pending_passwords[ $email ];
			unset( self::$pending_passwords[ $email ] );
			return $password;
		}
		return '';
	}

	/**
	 * Отправляет приветственное письмо после регистрации.
	 *
	 * Содержит разный текст для обычных пользователей и организаторов.
	 * Для организаторов (роль event_organizer) собирается отдельный HTML-шаблон
	 * с данными для входа и ссылкой на страницу авторизации.
	 * @param int $user_id ID нового пользователя.
	 * @return void
	 */
	public function send_welcome_email( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user || is_wp_error( $user ) ) {
			return;
		}

		// Новый организатор в процессе регистрации: письмо подтверждения уходит
		// отдельно из точки регистрации, поэтому приветственное письмо не шлём,
		// а буфер пароля очищаем (он планировался только для старого сценария).
		if ( self::take_password( $user->user_email ) ) {
			return;
		}

		// Организатор получает отдельное письмо с данными для входа.
		if ( in_array( 'event_organizer', (array) $user->roles, true ) ) {
			$password = self::take_password( $user->user_email );
			$this->send_organizer_welcome_email( $user, $password );
			return;
		}

		$title   = 'Добро пожаловать!';
		$content = '<p style="margin: 0 0 20px; font-size: 16px; line-height: 1.6; color: #4a4a4a;">';
		$content .= 'Добро пожаловать! Теперь вы можете сохранять события в избранное ';
		$content .= 'и получать уведомления о новых мероприятиях.';
		$content .= '</p>';

		$content .= $this->render_button( 'Перейти на сайт', home_url( '/' ) );

		$subject = 'Добро пожаловать на Иду по городу!';
		$body    = $this->render_email_template( $content, $title );

		wp_mail( $user->user_email, $subject, $body );
	}

	/**
	 * Отправляет письмо с подтверждением регистрации организатора.
	 *
	 * Ссылка ведёт на страницу входа с параметрами action=confirm.
	 *
	 * @param int    $user_id ID нового организатора.
	 * @param string $key     Plain-ключ подтверждения.
	 * @return void
	 */
	public function send_organizer_verification_email( $user_id, $key ) {
		$user = get_userdata( $user_id );

		if ( ! $user || is_wp_error( $user ) ) {
			return;
		}

		$confirm_url = add_query_arg(
			array(
				'action'    => 'confirm',
				'idpg_user' => $user->ID,
				'idpg_key'  => $key,
			),
			$this->get_login_url()
		);

		$content  = '<p style="margin: 0 0 20px; font-size: 16px; line-height: 1.6; color: #4a4a4a;">';
		$content .= 'Вы зарегистрировались на платформе «Иду по городу» как организатор. ';
		$content .= 'Чтобы активировать аккаунт и получить доступ к кабинету организатора, ';
		$content .= 'подтвердите ваш email по кнопке ниже:';
		$content .= '</p>';

		$content .= $this->render_button( 'Подтвердить регистрацию', $confirm_url );

		$content .= '<p style="margin: 28px 0 0; font-size: 14px; line-height: 1.5; color: #888888;">';
		$content .= 'Если вы не регистрировались на сайте, просто проигнорируйте это письмо.';
		$content .= '</p>';

		$subject = 'Подтверждение регистрации организатора — Иду по городу';
		$body    = $this->render_email_template( $content, 'Подтверждение регистрации' );

		wp_mail( $user->user_email, $subject, $body );
	}

	/**
	 * Собирает и отправляет приветственное письмо организатору.
	 *
	 * @param WP_User $user     Объект нового организатора.
	 * @param string  $password Plain-пароль (или пустая строка, если недоступен).
	 * @return void
	 */
	private function send_organizer_welcome_email( WP_User $user, $password = '' ) {
		$display_name = ! empty( $user->display_name ) ? $user->display_name : '';
		$email        = $user->user_email;

		$login_url = $this->get_login_url();

		$body    = $this->render_organizer_email_template( $display_name, $email, $password, $login_url );
		$subject = 'Добро пожаловать, ' . ( $display_name ?: $email ) . '! Ваш кабинет организатора готов';

		wp_mail( $email, $subject, $body );
	}

	/**
	 * Возвращает URL страницы входа (публичная обёртка).
	 *
	 * Нужен другим классам (например, формированию ссылки подтверждения
	 * регистрации организатора), чтобы не дублировать поиск страницы.
	 *
	 * @return string URL страницы входа.
	 */
	public function get_login_page_url() {
		return $this->get_login_url();
	}

	/**
	 * Возвращает URL страницы входа.
	 *
	 * Ищем страницу с шаблоном page-login.php (её слаг может быть /login/,
	 * /vhod/ и т.п. — на проде отличался). Если не найдена — fallback на /login/.
	 *
	 * @return string URL страницы входа.
	 */
	private function get_login_url() {
		$login_pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => 'page-login.php',
		) );

		if ( ! empty( $login_pages ) ) {
			$permalink = get_permalink( $login_pages[0] );
			if ( $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/login/' );
	}

	/**
	 * HTML-шаблон приветственного письма организатора.
	 *
	 * Адаптивная табличная вёрстка, инлайновые стили, максимальная ширина 600px.
	 *
	 * @param string $display_name Имя организатора.
	 * @param string $email        Email организатора.
	 * @param string $password     Plain-пароль (может быть пустым).
	 * @param string $login_url    URL страницы входа.
	 * @return string Полный HTML-документ письма.
	 */
	private function render_organizer_email_template( $display_name, $email, $password, $login_url ) {
		$logo_url = $this->get_logo_url();
		$year     = date( 'Y' );

		// Карточка с данными для входа; строка пароля показывается, только если он известен.
		$password_row = '';
		if ( $password ) {
			$password_row = '<p style="margin:0;font-size:15px;line-height:1.6;color:#1a2332;">Пароль: <strong style="color:#1a2332;">' . esc_html( $password ) . '</strong></p>';
		} else {
			$password_row = '<p style="margin:0;font-size:15px;line-height:1.6;color:#1a2332;">Пароль: <strong style="color:#1a2332;">задан при регистрации</strong></p>';
		}

		ob_start();
		?>
<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;width:100%;margin:0 auto;background-color:#f5f7fa;">
	<tr>
		<td align="center" bgcolor="#1a2332" style="background-color:#1a2332;padding:36px 32px 32px;border-radius:16px 16px 0 0;">
			<?php if ( ! empty( $logo_url ) ) : ?>
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="IDU POGORODU" width="600" style="display:block;width:150px;height:auto;max-width:100%;border:0;outline:none;text-decoration:none;" />
			<?php else : ?>
				<span style="font-size:26px;font-weight:800;color:#ffffff;letter-spacing:-0.3px;">IDU POGORODU</span>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<td align="center" bgcolor="#ffffff" style="background-color:#ffffff;padding:40px 32px 36px;border-radius:0 0 16px 16px;">
			<h1 style="margin:0 0 12px;font-size:26px;line-height:1.3;font-weight:800;color:#1a2332;letter-spacing:-0.3px;">Добро пожаловать, <?php echo esc_html( $display_name ?: $email ); ?>!</h1>
			<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#5a6473;">Ваш кабинет организатора на платформе <strong style="color:#1a2332;">IDU POGORODU</strong> создан. Теперь вы можете публиковать мероприятия, получать заявки и следить за посещаемостью — всё в одном месте.</p>
			<p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#5a6473;">Ниже — данные для входа.</p>

			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:440px;width:100%;margin:0 auto 24px;">
				<tr>
					<td style="padding:20px 24px;background-color:#fbfcfe;border-left:4px solid #4CAF50;border-radius:0 12px 12px 0;">
						<p style="margin:0 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#4CAF50;">Данные для входа</p>
						<p style="margin:0 0 10px;font-size:15px;line-height:1.6;color:#1a2332;">Email: <strong style="color:#1a2332;"><?php echo esc_html( $email ); ?></strong></p>
						<?php echo $password_row; ?>
					</td>
				</tr>
			</table>

			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:440px;width:100%;margin:0 auto 26px;">
				<tr>
					<td align="left" style="padding:2px 24px;">
						<p style="margin:0 0 12px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#8a94a6;">Как войти</p>
						<p style="margin:0 0 10px;font-size:15px;line-height:1.6;color:#5a6473;">🔑&nbsp; Войдите на сайте по email и паролю из блока выше</p>
						<p style="margin:0;font-size:15px;line-height:1.6;color:#5a6473;">Кнопка ниже просто откроет страницу входа — залогиниться можно только по паролю.</p>
					</td>
				</tr>
			</table>

			<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:440px;width:100%;margin:0 auto 8px;">
				<tr>
					<td align="center" bgcolor="#4CAF50" style="background-color:#4CAF50;border-radius:12px;">
						<a href="<?php echo esc_url( $login_url ); ?>" target="_blank" style="display:block;padding:16px 28px;font-size:16px;line-height:1.2;font-weight:700;color:#ffffff;text-decoration:none;">Перейти на страницу входа</a>
					</td>
				</tr>
			</table>
		</td>
	</tr>
	<tr>
		<td align="center" bgcolor="#1a2332" style="background-color:#1a2332;padding:28px 32px;border-radius:0 0 16px 16px;">
			<p style="margin:0 0 10px;font-size:13px;line-height:1.6;color:#aeb6c4;">Нужна помощь? Напишите нам:&nbsp;<a href="mailto:support@idupogorodu.ru" style="color:#4CAF50;font-weight:600;text-decoration:underline;text-underline-offset:2px;">support@idupogorodu.ru</a></p>
			<p style="margin:0;font-size:12px;line-height:1.6;color:#6b7683;">&copy; <?php echo esc_html( $year ); ?> IDU POGORODU — платформа для организаторов мероприятий. Все права защищены.</p>
		</td>
	</tr>
</table>
		<?php
		return ob_get_clean();
	}

	/**
	 * Базовый HTML-шаблон для всех писем.
	 *
	 * Адаптивная вёрстка, inline-стили, ширина 600px.
	 *
	 * @param string $content HTML-контент письма.
	 * @param string $title   Заголовок письма.
	 * @return string Полный HTML-документ письма.
	 */
	public function render_email_template( $content, $title ) {
		$logo_url = $this->get_logo_url();

		ob_start();
		?>
<!doctype html>
<html>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
	<meta name="x-apple-disable-message-reformatting">
	<title><?php echo esc_html( $title ); ?></title>
</head>
<body style="margin: 0; padding: 0; background-color: #f0f0f0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">

	<table role="presentation" cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f0f0f0; padding: 20px 0;">
		<tr>
			<td align="center" style="padding: 0 16px;">

				<!-- Контейнер 600px -->
				<table role="presentation" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%; background-color: #ffffff; border-radius: 8px; overflow: hidden;">

					<!-- Логотип -->
					<tr>
						<td style="padding: 40px 32px 0; text-align: center;">
							<?php if ( ! empty( $logo_url ) ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="Иду по городу" style="max-width: 160px; height: auto; border: 0; outline: none;" />
							<?php else : ?>
								<span style="font-size: 22px; font-weight: 700; color: #1a1a1a;">Иду по городу</span>
							<?php endif; ?>
						</td>
					</tr>

					<!-- Заголовок -->
					<tr>
						<td style="padding: 32px 32px 0; text-align: center;">
							<h1 style="margin: 0; font-size: 24px; font-weight: 700; color: #1a1a1a; line-height: 1.3;">
								<?php echo esc_html( $title ); ?>
							</h1>
						</td>
					</tr>

					<!-- Контент -->
					<tr>
						<td style="padding: 24px 32px 32px;">
							<?php echo wp_kses_post( $content ); ?>
						</td>
					</tr>

					<!-- Подвал -->
					<tr>
						<td style="padding: 24px 32px; background-color: #f8f8f8; border-top: 1px solid #e8e8e8;">
							<table role="presentation" cellpadding="0" cellspacing="0" style="width: 100%;">
								<tr>
									<td style="font-size: 12px; line-height: 1.5; color: #999999; text-align: center;">
										<p style="margin: 0 0 4px;">
											&copy; <?php echo esc_html( date( 'Y' ) ); ?> Иду по городу
										</p>
										<p style="margin: 0;">
											<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color: #007cba; text-decoration: none;">idupogorodu.ru</a>
										</p>
									</td>
								</tr>
							</table>
						</td>
					</tr>

				</table>

			</td>
		</tr>
	</table>

</body>
</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Рендерит кнопку для письма.
	 *
	 * Использует table-подход для совместимости со всеми почтовыми клиентами.
	 *
	 * @param string $text Текст кнопки.
	 * @param string $url  Ссылка.
	 * @return string HTML-код кнопки.
	 */
	private function render_button( $text, $url ) {
		$html  = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto;">';
		$html .= '<tr>';
		$html .= '<td style="background-color: #007cba; border-radius: 6px; text-align: center;">';
		$html .= '<a href="' . esc_url( $url ) . '" ';
		$html .= 'style="display: inline-block; padding: 14px 36px; font-size: 16px; font-weight: 600; ';
		$html .= 'color: #ffffff; text-decoration: none; line-height: 1; border-radius: 6px;">';
		$html .= esc_html( $text );
		$html .= '</a>';
		$html .= '</td>';
		$html .= '</tr>';
		$html .= '</table>';

		return $html;
	}

	/**
	 * Возвращает URL логотипа для писем.
	 *
	 * Можно переопределить через фильтр idpg_email_logo_url.
	 *
	 * @return string
	 */
	private function get_logo_url() {
		if ( empty( $this->logo_url ) ) {
			$this->logo_url = apply_filters(
				'idpg_email_logo_url',
				home_url( '/wp-content/uploads/email-logo.png' )
			);
		}

		return $this->logo_url;
	}
}
