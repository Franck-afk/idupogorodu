<?php
/**
 * Раздел «Профиль» кабинета организатора.
 *
 * Каркас: выводит основные данные аккаунта (только чтение) и заглушку под
 * реквизиты организации и верификацию.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$idpg_org_user = wp_get_current_user();

// Данные анкеты организатора (пишутся при регистрации и доуполнении анкеты).
$idpg_org_company = get_user_meta( $idpg_org_user->ID, 'idpg_org_name', true );
$idpg_org_contact = get_user_meta( $idpg_org_user->ID, 'idpg_contact_name', true );
$idpg_org_phone   = get_user_meta( $idpg_org_user->ID, 'idpg_phone', true );

// Строки для вывода: подпись + значение (пустое значение показываем прочерком).
$idpg_org_rows = array(
	__( 'Имя в системе', 'idpg_core' )    => $idpg_org_user->display_name,
	__( 'Email', 'idpg_core' )            => $idpg_org_user->user_email,
	__( 'Организация', 'idpg_core' )      => $idpg_org_company,
	__( 'Контактное лицо', 'idpg_core' )  => $idpg_org_contact,
	__( 'Телефон', 'idpg_core' )          => $idpg_org_phone,
);
?>

<h2 class="idpg-org-section__title"><?php esc_html_e( 'Профиль', 'idpg_core' ); ?></h2>

<p class="idpg-org-section__lead">
	<?php esc_html_e( 'Данные вашего аккаунта. Редактирование профиля появится позже.', 'idpg_core' ); ?>
</p>

<dl class="idpg-org-fields">
	<?php foreach ( $idpg_org_rows as $idpg_org_label => $idpg_org_value ) : ?>
		<div class="idpg-org-fields__row">
			<dt class="idpg-org-fields__label"><?php echo esc_html( $idpg_org_label ); ?></dt>
			<dd class="idpg-org-fields__value">
				<?php if ( '' !== trim( (string) $idpg_org_value ) ) : ?>
					<?php echo esc_html( $idpg_org_value ); ?>
				<?php else : ?>
					<span class="idpg-org-fields__empty">&mdash;</span>
				<?php endif; ?>
			</dd>
		</div>
	<?php endforeach; ?>
</dl>

<div class="idpg-notice idpg-notice--info">
	<?php esc_html_e( 'Реквизиты организации, документы и статус верификации появятся в этом разделе позже.', 'idpg_core' ); ?>
</div>
