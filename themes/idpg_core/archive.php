<?php
/**
 * The template for displaying archive pages
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package idpg_core
 */

get_header();
?>

	<main id="primary" class="site-main">
		<div class="container">

		<?php idpg_widget_areas_render( 'above-content' ); ?>

		<?php if ( have_posts() ) : ?>

			<header class="page-header">
				<?php
				the_archive_title( '<h1 class="page-title">', '</h1>' );
				the_archive_description( '<div class="archive-description">', '</div>' );
				?>
			</header>

			<?php
			/* Start the Loop */
			$is_event_grid = is_post_type_archive( 'event' ) || is_tax( array( 'event_category', 'age_rating' ) );

			if ( $is_event_grid ) {
				echo '<div class="idpg-events-grid">';
			}

			while ( have_posts() ) :
				the_post();

				/*
				 * Include the Post-Type-specific template for the content.
				 * If you want to override this in a child theme, then include a file
				 * called content-___.php (where ___ is the Post Type name) and that will be used instead.
				 */
				get_template_part( 'template-parts/content', get_post_type() );

			endwhile;

			if ( $is_event_grid ) {
				echo '</div>';
			}

			the_posts_navigation();

		else :

			get_template_part( 'template-parts/content', 'none' );

		endif;
		?>

		<?php idpg_widget_areas_render( 'below-content' ); ?>

		</div>
	</main>

<?php
get_footer();
