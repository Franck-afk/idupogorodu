<?php
/**
 * Template Name: Правовая страница
 *
 * Шаблон для страниц «Правила сайта» и «Политика конфиденциальности».
 * Hero-секция + стилизованное контентное поле с номерами разделов.
 *
 * @package idpg_core
 */

get_header();
?>

	<main id="primary" class="site-main">
		<div class="container">

			<?php idpg_widget_areas_render( 'above-content' ); ?>

			<?php while ( have_posts() ) : the_post(); ?>

			<header class="idpg-page-hero idpg-page-hero--light">
				<h1 class="idpg-page-hero__title"><?php the_title(); ?></h1>
				<p class="idpg-page-hero__subtitle">
					<?php
					printf(
						/* translators: %s: date of last revision */
						esc_html__( 'Редакция от %s', 'idpg_core' ),
						esc_html( get_the_modified_date( 'j F Y' ) )
					);
					?>
				</p>
			</header>

			<?php
			$idpg_doc = idpg_core_legal_document();
			$idpg_doc['toc'] = array();
			idpg_core_legal_render( $idpg_doc );
			?>

			<?php endwhile; ?>

			<?php idpg_widget_areas_render( 'below-content' ); ?>

		</div>
	</main>

<?php
get_footer();
