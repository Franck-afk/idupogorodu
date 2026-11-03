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

if ( current_user_can( 'upload_files' ) ) {
	wp_enqueue_media();
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

		<p class="idpg-org-field">
			<label class="idpg-org-field__label" for="idpg-org-legal-type">
				<?php esc_html_e( 'Организационно-правовая форма', 'idpg_core' ); ?>
			</label>
			<select
				class="idpg-org-field__input"
				id="idpg-org-legal-type"
				name="legal_type"
			>
				<?php
				$idpg_org_legal_types = array(
					''    => __( '— Не выбрана —', 'idpg_core' ),
					'ip'  => __( 'Индивидуальный предприниматель', 'idpg_core' ),
					'ooo' => __( 'Общество с ограниченной ответственностью', 'idpg_core' ),
					'fiz' => __( 'Физическое лицо', 'idpg_core' ),
					'nko' => __( 'Некоммерческая организация', 'idpg_core' ),
				);
				foreach ( $idpg_org_legal_types as $val => $label ) :
					?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $idpg_org_profile['legal_type'], $val ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p class="idpg-org-field">
			<label class="idpg-org-field__label" for="idpg-org-inn">
				<?php esc_html_e( 'ИНН', 'idpg_core' ); ?>
			</label>
			<input
				class="idpg-org-field__input"
				type="text"
				id="idpg-org-inn"
				name="inn"
				value="<?php echo esc_attr( $idpg_org_profile['inn'] ); ?>"
				pattern="\d{10}|\d{12}"
				maxlength="12"
				inputmode="numeric"
				aria-describedby="idpg-org-inn-hint"
			>
			<span class="idpg-org-field__hint" id="idpg-org-inn-hint">
				<?php esc_html_e( '10 или 12 цифр без пробелов.', 'idpg_core' ); ?>
			</span>
		</p>

		<p class="idpg-org-field idpg-org-field--readonly">
			<span class="idpg-org-field__label">
				<?php esc_html_e( 'Верификация', 'idpg_core' ); ?>
			</span>
			<span class="idpg-org-field__value">
				<?php if ( ! empty( $idpg_org_profile['verified'] ) ) : ?>
					<span class="idpg-org-badge idpg-org-badge--success">
						<?php esc_html_e( 'Верифицирован', 'idpg_core' ); ?>
					</span>
				<?php else : ?>
					<span class="idpg-org-badge idpg-org-badge--pending">
						<?php esc_html_e( 'Не верифицирован', 'idpg_core' ); ?>
					</span>
				<?php endif; ?>
			</span>
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

		<div class="idpg-org-field idpg-org-field--documents">
			<span class="idpg-org-field__label"><?php esc_html_e( 'Документы', 'idpg_core' ); ?></span>

			<input
				type="hidden"
				name="documents"
				value="<?php echo esc_attr( wp_json_encode( $idpg_org_profile['documents'] ) ); ?>"
				data-idpg-documents-input
			>

			<ul class="idpg-org-documents-list" data-idpg-documents-list>
				<?php foreach ( $idpg_org_profile['documents'] as $doc_id ) : ?>
					<?php
					$doc = get_post( $doc_id );
					if ( ! $doc ) {
						continue;
					}
					?>
					<li class="idpg-org-document" data-idpg-document-id="<?php echo esc_attr( (string) $doc_id ); ?>">
						<span class="idpg-org-document__name"><?php echo esc_html( get_the_title( $doc_id ) ); ?></span>
						<button type="button" class="idpg-org-document__remove" data-idpg-document-remove>
							<?php esc_html_e( 'Удалить', 'idpg_core' ); ?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( current_user_can( 'upload_files' ) ) : ?>
				<button
					type="button"
					class="idpg-btn idpg-btn--secondary"
					data-idpg-documents-add
				>
					<?php esc_html_e( 'Добавить документы', 'idpg_core' ); ?>
				</button>
			<?php else : ?>
				<p class="idpg-org-field__hint">
					<?php esc_html_e( 'У вас нет прав на загрузку файлов.', 'idpg_core' ); ?>
				</p>
			<?php endif; ?>
		</div>

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

<?php if ( current_user_can( 'upload_files' ) ) : ?>
<script>
(function(){
  var addBtn = document.querySelector('[data-idpg-documents-add]');
  if (!addBtn) return;

  var input = document.querySelector('[data-idpg-documents-input]');
  var list  = document.querySelector('[data-idpg-documents-list]');

  function getIds(){
    try { return JSON.parse(input.value || '[]'); } catch(e) { return []; }
  }

  function setIds(ids){
    input.value = JSON.stringify(ids);
  }

  function addDocument(id, title){
    var ids = getIds();
    if (ids.indexOf(id) !== -1) return;
    ids.push(id);
    setIds(ids);

    var li = document.createElement('li');
    li.className = 'idpg-org-document';
    li.setAttribute('data-idpg-document-id', String(id));
    li.innerHTML = '<span class="idpg-org-document__name">' + title + '</span>' +
      '<button type="button" class="idpg-org-document__remove"><?php echo esc_js( __( 'Удалить', 'idpg_core' ) ); ?></button>';
    list.appendChild(li);
  }

  list.addEventListener('click', function(e){
    if (!e.target.matches('[data-idpg-document-remove]')) return;
    var li = e.target.closest('[data-idpg-document-id]');
    if (!li) return;
    var id = parseInt(li.getAttribute('data-idpg-document-id'), 10);
    var ids = getIds().filter(function(i){ return i !== id; });
    setIds(ids);
    li.remove();
  });

  addBtn.addEventListener('click', function(){
    var frame = wp.media({
      title: '<?php echo esc_js( __( 'Выберите документы', 'idpg_core' ) ); ?>',
      button: { text: '<?php echo esc_js( __( 'Добавить', 'idpg_core' ) ); ?>' },
      multiple: true,
      library: { type: 'application/pdf,image/*' }
    });

    frame.on('select', function(){
      frame.state().get('selection').each(function(attachment){
        addDocument(attachment.id, attachment.get('title'));
      });
    });

    frame.open();
  });
})();
</script>
<?php endif;
