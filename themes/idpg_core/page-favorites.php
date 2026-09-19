<?php
/**
 * Страница «Избранное» (/lk/favorites/).
 *
 * Подключается через template_include из IDPG_Favorites.
 *
 * @package idpg_core
 */

get_header();

$idpg_fav_ids = class_exists( 'IDPG_Favorites' ) ? IDPG_Favorites::get_ids() : array();
?>

	<main id="primary" class="site-main idpg-fav-page">
		<div class="container">

			<header class="idpg-archive-header">
				<h1 class="idpg-archive-header__title"><?php esc_html_e( 'Избранное', 'idpg_core' ); ?></h1>

				<div class="idpg-archive-header__back">
					<a href="<?php echo esc_url( home_url( '/afisha/' ) ); ?>">
						<?php esc_html_e( '← Все события', 'idpg_core' ); ?>
					</a>
				</div>
			</header>

			<?php if ( $idpg_fav_ids ) : ?>

				<?php
				$idpg_fav_query = new WP_Query( array(
					'post_type'      => 'event',
					'post_status'    => 'publish',
					'posts_per_page' => count( $idpg_fav_ids ),
					'post__in'       => $idpg_fav_ids,
					'orderby'        => 'post__in',
				) );
				?>

				<?php if ( $idpg_fav_query->have_posts() ) : ?>
					<div class="idpg-events-grid idpg-events-grid--3 idpg-events-grid--v">
						<?php
						while ( $idpg_fav_query->have_posts() ) :
							$idpg_fav_query->the_post();

							get_template_part( 'template-parts/content', 'event' );

						endwhile;
						wp_reset_postdata();
						?>
					</div>
				<?php else : ?>
					<div class="idpg-notice idpg-notice--info">
						<?php esc_html_e( 'Некоторые избранные события больше не опубликованы.', 'idpg_core' ); ?>
					</div>
				<?php endif; ?>

			<?php else : ?>
				<div class="idpg-notice idpg-notice--info">
					<?php esc_html_e( 'В избранном пока ничего нет. Нажимайте ♥ на карточках событий.', 'idpg_core' ); ?>
				</div>

				<div class="idpg-page-actions" style="margin-top:24px;">
					<a href="<?php echo esc_url( home_url( '/afisha/' ) ); ?>" class="idpg-btn idpg-btn--primary">
						<?php esc_html_e( 'Смотреть афишу', 'idpg_core' ); ?>
					</a>
				</div>
			<?php endif; ?>

		</div>
	</main>

<?php
get_footer();
