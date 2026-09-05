<?php
/**
 * The template for displaying city taxonomy archives.
 *
 * Основной запрос ограничен событиями через pre_get_posts
 * (см. functions.php: idpg_core_city_archive_events).
 *
 * @package idpg_core
 */

get_header();
?>

	<main id="primary" class="site-main">
		<div class="container">

			<?php idpg_widget_areas_render( 'above-content' ); ?>

			<header class="idpg-archive-header">
				<h1 class="idpg-archive-header__title">
					<?php
					printf(
						/* translators: %s: название города */
						esc_html__( 'События в городе %s', 'idpg_core' ),
						'<span class="idpg-archive-header__term">' . esc_html( single_term_title( '', false ) ) . '</span>'
					);
					?>
				</h1>

				<?php the_archive_description( '<div class="idpg-archive-header__desc">', '</div>' ); ?>

				<div class="idpg-archive-header__back">
					<a href="<?php echo esc_url( get_post_type_archive_link( 'event' ) ); ?>">
						<?php esc_html_e( '← Все события', 'idpg_core' ); ?>
					</a>
				</div>
			</header>

			<?php if ( have_posts() ) : ?>

				<div class="idpg-events-grid">
					<?php
					while ( have_posts() ) :
						the_post();

						get_template_part( 'template-parts/content', 'event' );

					endwhile;
					?>
				</div>

				<?php
				the_posts_pagination(
					array(
						'prev_text' => '&larr;',
						'next_text' => '&rarr;',
						'mid_size'  => 2,
					)
				);
				?>

			<?php else : ?>

				<?php get_template_part( 'template-parts/content', 'none' ); ?>

			<?php endif; ?>

			<?php idpg_widget_areas_render( 'below-content' ); ?>

		</div>
	</main>

<?php
get_footer();