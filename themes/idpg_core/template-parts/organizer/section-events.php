<?php
/**
 * Раздел «Мои события» кабинета организатора.
 *
 * Заглушка: список событий организатора в кабинете ещё не реализован,
 * поэтому раздел ведёт в админку WordPress, где события уже доступны.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$idpg_org_can_edit = current_user_can( 'edit_events' );
?>

<h2 class="idpg-org-section__title"><?php esc_html_e( 'Мои события', 'idpg_core' ); ?></h2>

<div class="idpg-org-empty">
	<p class="idpg-org-empty__title"><?php esc_html_e( 'Раздел в разработке', 'idpg_core' ); ?></p>

	<p class="idpg-org-empty__text">
		<?php esc_html_e( 'Здесь появится список ваших событий, черновиков и заявок на модерацию.', 'idpg_core' ); ?>
	</p>

	<?php if ( $idpg_org_can_edit ) : ?>
		<p class="idpg-org-empty__text">
			<?php esc_html_e( 'Пока события создаются и редактируются в панели WordPress.', 'idpg_core' ); ?>
		</p>

		<p class="idpg-org-empty__actions">
			<a class="idpg-btn idpg-btn--primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=event' ) ); ?>">
				<?php esc_html_e( 'Перейти к событиям', 'idpg_core' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
