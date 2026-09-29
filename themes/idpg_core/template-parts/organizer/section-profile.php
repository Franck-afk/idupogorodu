<?php
/**
 * Раздел «Профиль» кабинета организатора — форма редактирования (DEV-73).
 *
 * Данные организации переехали сюда из удалённой анкеты `/organizer/complete/`.
 * Форма работает в двух режимах:
 *  - без JavaScript — обычный POST на страницу кабинета, обработка в
 *    `page-organizer.php` (с nonce и Post/Redirect/Get);
 *  - с JavaScript — `organizer.js` перехватывает submit и шлёт
 *    `PUT /idpg/v1/organizer/profile` без перезагрузки страницы.
 *
 * Email только для чтения: смена адреса требует подтверждения, в рамках
 * DEV-73 не реализована.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Данные профиля берём из IDPG_Organizer_URL — тот же источник, что и у
// REST-ответа, поэтому форма и API не показывают разные значения.
$idpg_org_profile = class_exists( 'IDPG_Organizer_URL' )
	? IDPG_Organizer_URL::get_profile_data( wp_get_current_user() )
	: array(
		'display_name' => '',
		'email'        => '',
		'org_name'     => '',
		'contact_name' => '',
		'phone'        => '',
		'avatar_id'    => 0,
		'avatar_url'   => '',
	);

// Уведомление после сохранения (приходит из page-organizer.php).
$idpg_org_notice = isset( $idpg_org_profile_notice ) ? (string) $idpg_org_profile_notice : '';
?>

<h2 class="idpg-org-section__title"><?php esc_html_e( 'Профиль', 'idpg_core' ); ?></h2>

<p class="idpg-org-section__lead">
	<?php esc_html_e( 'Контакты и данные организации. Они показываются в карточках событий и в письмах организаторам.', 'idpg_core' ); ?>
</p>

<?php if ( 'saved' === $idpg_org_notice ) : ?>
	<div class="idpg-notice idpg-notice--success" data-idpg-profile-status role="status">
		<?php esc_html_e( 'Профиль сохранён.', 'idpg_core' ); ?>
	</div>
<?php elseif ( 'error' === $idpg_org_notice ) : ?>
	<div class="idpg-notice idpg-notice--error" data-idpg-profile-status role="alert">
		<?php esc_html_e( 'Не удалось сохранить профиль. Проверьте поля и попробуйте ещё раз.', 'idpg_core' ); ?>
	</div>
<?php elseif ( 'invalid_nonce' === $idpg_org_notice ) : ?>
	<div class="idpg-notice idpg-notice--error" data-idpg-profile-status role="alert">
		<?php esc_html_e( 'Сессия устарела. Обновите страницу и отправьте форму ещё раз.', 'idpg_core' ); ?>
	</div>
<?php endif; ?>

<form
	class="idpg-org-profile"
	method="post"
	action=""
	data-idpg-profile-form
	data-idpg-profile-rest="<?php echo esc_url( rest_url( 'idpg/v1/organizer/profile' ) ); ?>"
	data-idpg-profile-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
>
	<?php wp_nonce_field( 'idpg_organizer_profile', 'idpg_org_profile_nonce' ); ?>
	<input type="hidden" name="idpg_org_profile_submit" value="1">
	<input
		type="hidden"
		name="avatar_id"
		value="<?php echo esc_attr( (string) (int) $idpg_org_profile['avatar_id'] ); ?>"
		data-idpg-profile-avatar
	>

	<div class="idpg-org-profile__grid">

		<p class="idpg-org-field">
			<label class="idpg-org-field__label" for="idpg-org-display-name">
				<?php esc_html_e( 'Имя в системе', 'idpg_core' ); ?>
			</label>
			<input
				class="idpg-org-field__input"
				type="text"
				id="idpg-org-display-name"
				name="display_name"
				value="<?php echo esc_attr( $idpg_org_profile['display_name'] ); ?>"
				maxlength="<?php echo esc_attr( class_exists( 'IDPG_Organizer_URL' ) ? IDPG_Organizer_URL::PROFILE_FIELD_MAXLEN : 190 ); ?>"
			>
		</p>

		<p class="idpg-org-field">
			<label class="idpg-org-field__label" for="idpg-org-email">
				<?php esc_html_e( 'Email', 'idpg_core' ); ?>
			</label>
			<input
				class="idpg-org-field__input"
				type="email"
				id="idpg-org-email"
				value="<?php echo esc_attr( $idpg_org_profile['email'] ); ?>"
				readonly
				aria-describedby="idpg-org-email-hint"
			>
			<span class="idpg-org-field__hint" id="idpg-org-email-hint">
				<?php esc_html_e( 'Email используется для входа и подтверждений, изменить его пока нельзя.', 'idpg_core' ); ?>
			</span>
		</p>

		<p class="idpg-org-field">
			<label class="idpg-org-field__label" for="idpg-org-name">
				<?php esc_html_e( 'Организация', 'idpg_core' ); ?>
			</label>
			<input
				class="idpg-org-field__input"
				type="text"
				id="idpg-org-name"
				name="org_name"
				value="<?php echo esc_attr( $idpg_org_profile['org_name'] ); ?>"
				maxlength="<?php echo esc_attr( class_exists( 'IDPG_Organizer_URL' ) ? IDPG_Organizer_URL::PROFILE_FIELD_MAXLEN : 190 ); ?>"
			>
		</p>

		<p class="idpg-org-field">
			<label class="idpg-org-field__label" for="idpg-org-contact">
				<?php esc_html_e( 'Контактное лицо', 'idpg_core' ); ?>
			</label>
			<input
				class="idpg-org-field__input"
				type="text"
				id="idpg-org-contact"
				name="contact_name"
				value="<?php echo esc_attr( $idpg_org_profile['contact_name'] ); ?>"
				maxlength="<?php echo esc_attr( class_exists( 'IDPG_Organizer_URL' ) ? IDPG_Organizer_URL::PROFILE_FIELD_MAXLEN : 190 ); ?>"
			>
		</p>

		<p class="idpg-org-field">
			<label class="idpg-org-field__label" for="idpg-org-phone">
				<?php esc_html_e( 'Телефон', 'idpg_core' ); ?>
			</label>
			<input
				class="idpg-org-field__input"
				type="tel"
				id="idpg-org-phone"
				name="phone"
				value="<?php echo esc_attr( $idpg_org_profile['phone'] ); ?>"
				maxlength="<?php echo esc_attr( class_exists( 'IDPG_Organizer_URL' ) ? IDPG_Organizer_URL::PROFILE_FIELD_MAXLEN : 190 ); ?>"
			>
		</p>

		<?php if ( ! empty( $idpg_org_profile['avatar_url'] ) ) : ?>
			<p class="idpg-org-field idpg-org-field--avatar">
				<span class="idpg-org-field__label"><?php esc_html_e( 'Аватар', 'idpg_core' ); ?></span>
				<img
					class="idpg-org-field__avatar"
					src="<?php echo esc_url( $idpg_org_profile['avatar_url'] ); ?>"
					alt=""
					width="96"
					height="96"
				>
			</p>
		<?php endif; ?>

	</div>

	<p class="idpg-org-profile__actions">
		<button class="idpg-btn idpg-btn--primary" type="submit">
			<?php esc_html_e( 'Сохранить', 'idpg_core' ); ?>
		</button>
		<span class="idpg-org-profile__status" data-idpg-profile-pending hidden aria-live="polite">
			<?php esc_html_e( 'Сохраняем…', 'idpg_core' ); ?>
		</span>
	</p>
</form>
