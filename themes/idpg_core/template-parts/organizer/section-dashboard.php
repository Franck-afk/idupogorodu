<?php
/**
 * Раздел «Дашборд» кабинета организатора.
 *
 * Каркас: сводные показатели — заглушки «—», реальные данные появятся
 * вместе со списком событий организатора.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$idpg_org_user = wp_get_current_user();
$idpg_org_name = $idpg_org_user->exists() ? $idpg_org_user->display_name : '';

// Карточки сводки: подпись + пояснение. Значения пока заглушки.
$idpg_org_cards = array(
	array(
		'label' => __( 'Всего событий', 'idpg_core' ),
		'hint'  => __( 'включая черновики', 'idpg_core' ),
	),
	array(
		'label' => __( 'На модерации', 'idpg_core' ),
		'hint'  => __( 'ожидают проверки', 'idpg_core' ),
	),
	array(
		'label' => __( 'Опубликовано', 'idpg_core' ),
		'hint'  => __( 'видны в афише', 'idpg_core' ),
	),
	array(
		'label' => __( 'Просмотры за 30 дней', 'idpg_core' ),
		'hint'  => __( 'по всем событиям', 'idpg_core' ),
	),
);
?>

<h2 class="idpg-org-section__title"><?php esc_html_e( 'Дашборд', 'idpg_core' ); ?></h2>

<p class="idpg-org-section__lead">
	<?php
	printf(
		/* translators: %s: имя пользователя. */
		esc_html__( '%s, это ваш рабочий кабинет: сводка по событиям, заявкам и профилю.', 'idpg_core' ),
		esc_html( $idpg_org_name ? $idpg_org_name : __( 'Коллега', 'idpg_core' ) )
	);
	?>
</p>

<div class="idpg-org-cards">
	<?php foreach ( $idpg_org_cards as $idpg_org_card ) : ?>
		<div class="idpg-org-card">
			<span class="idpg-org-card__label"><?php echo esc_html( $idpg_org_card['label'] ); ?></span>
			<span class="idpg-org-card__value" aria-hidden="true">&mdash;</span>
			<span class="idpg-org-card__hint"><?php echo esc_html( $idpg_org_card['hint'] ); ?></span>
		</div>
	<?php endforeach; ?>
</div>

<div class="idpg-notice idpg-notice--info">
	<?php esc_html_e( 'Показатели появятся после подключения раздела «Мои события». Сейчас это каркас кабинета.', 'idpg_core' ); ?>
</div>
