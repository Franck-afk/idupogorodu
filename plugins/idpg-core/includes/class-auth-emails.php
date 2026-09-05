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
	 * Отправляет приветственное письмо после регистрации.
	 *
	 * Содержит разный текст для обычных пользователей и организаторов.
	 *
	 * @param int $user_id ID нового пользователя.
	 * @return void
	 */
	public function send_welcome_email( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user || is_wp_error( $user ) ) {
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
