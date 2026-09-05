<?php
/**
 * The template for displaying all single posts
 *
 * @package idpg_core
 */

get_header();
?>

	<main id="primary" class="site-main">
		<div class="container">

		<?php idpg_widget_areas_render( 'above-content' ); ?>

		<?php
		while ( have_posts() ) :
			the_post();

			get_template_part( 'template-parts/content', get_post_type() );

			the_post_navigation(
				array(
					'prev_text' => '<span class="nav-subtitle">Previous:</span> <span class="nav-title">%title</span>',
					'next_text' => '<span class="nav-subtitle">Next:</span> <span class="nav-title">%title</span>',
				)
			);

		endwhile;
		?>

		<?php idpg_widget_areas_render( 'below-content' ); ?>

		</div>
	</main>

<?php
get_footer();
