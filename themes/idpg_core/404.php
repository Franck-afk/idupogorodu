<?php
/**
 * The template for displaying 404 pages (not found)
 *
 * @package idpg_core
 */

get_header();
?>

	<main id="primary" class="site-main">
		<div class="container">

		<?php idpg_widget_areas_render( 'above-content' ); ?>

		<section class="error-404 not-found">
			<header class="page-header">
				<h1 class="page-title">Страница не найдена</h1>
			</header>

			<div class="page-content">
				<p>Запрашиваемая страница не существует. Попробуйте воспользоваться поиском.</p>
				<?php get_search_form(); ?>
			</div>
		</section>

		<?php idpg_widget_areas_render( 'below-content' ); ?>

		</div>
	</main>

<?php
get_footer();
