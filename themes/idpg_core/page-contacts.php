<?php
/**
 * Template Name: Контакты
 *
 * Шаблон страницы контактов с карточками и кнопками.
 *
 * @package idpg_core
 */

get_header();
?>

	<main id="primary" class="site-main">
		<div class="container">

			<?php idpg_widget_areas_render( 'above-content' ); ?>

			<header class="idpg-page-hero idpg-page-hero--light">
				<h1 class="idpg-page-hero__title"><?php the_title(); ?></h1>
				<p class="idpg-page-hero__subtitle">Свяжитесь с нами</p>
			</header>

			<div class="idpg-page-content">
				<?php
				while ( have_posts() ) :
					the_post();
					the_content();
				endwhile;
				?>
			</div>

			<div class="idpg-page-contacts">
				<div class="idpg-page-contacts__card">
					<div class="idpg-page-contacts__icon">✉️</div>
					<h3 class="idpg-page-contacts__title">Редакция</h3>
					<p class="idpg-page-contacts__desc">Вопросы по контенту и сотрудничеству</p>
					<a href="mailto:info@idupogorodu.test" class="idpg-btn idpg-btn--primary">
						info@idupogorodu.test
					</a>
				</div>

				<div class="idpg-page-contacts__card">
					<div class="idpg-page-contacts__icon">📢</div>
					<h3 class="idpg-page-contacts__title">Реклама на сайте</h3>
					<p class="idpg-page-contacts__desc">Размещение рекламы и партнёрство</p>
					<a href="mailto:ads@idupogorodu.test" class="idpg-btn idpg-btn--secondary">
						ads@idupogorodu.test
					</a>
				</div>
			</div>

			<div class="idpg-page-actions">
				<a href="<?php echo esc_url( home_url( '/afisha/' ) ); ?>" class="idpg-btn idpg-btn--secondary">
					<?php esc_html_e( '← Все события', 'idpg_core' ); ?>
				</a>
			</div>

			<?php idpg_widget_areas_render( 'below-content' ); ?>

		</div>
	</main>

<?php
get_footer();
