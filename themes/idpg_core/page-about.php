<?php
/**
 * Template Name: О проекте
 *
 * Шаблон страницы «О проекте» с Hero-секцией и информационными блоками.
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
				<p class="idpg-page-hero__subtitle">Городской портал-афиша Ижевска</p>
			</header>

			<div class="idpg-page-content">
				<?php
				while ( have_posts() ) :
					the_post();
					the_content();
				endwhile;
				?>
			</div>

			<div class="idpg-page-features">
				<div class="idpg-page-features__item">
					<div class="idpg-page-features__icon">🎭</div>
					<div class="idpg-page-features__text">
						<strong>Концерты и спектакли</strong>
						<span>Афиша лучших площадок города</span>
					</div>
				</div>
				<div class="idpg-page-features__item">
					<div class="idpg-page-features__icon">🎨</div>
					<div class="idpg-page-features__text">
						<strong>Выставки и мероприятия</strong>
						<span>Культурная жизнь Ижевска</span>
					</div>
				</div>
				<div class="idpg-page-features__item">
					<div class="idpg-page-features__icon">👨‍👩‍👧</div>
					<div class="idpg-page-features__text">
						<strong>Детские события</strong>
						<span>Развлечения для всей семьи</span>
					</div>
				</div>
			</div>

			<div class="idpg-page-actions">
				<a href="<?php echo esc_url( home_url( '/afisha/' ) ); ?>" class="idpg-btn idpg-btn--primary">
					<?php esc_html_e( 'Смотреть афишу', 'idpg_core' ); ?>
				</a>
				<a href="<?php echo esc_url( home_url( '/venues/' ) ); ?>" class="idpg-btn idpg-btn--secondary">
					<?php esc_html_e( 'Площадки', 'idpg_core' ); ?>
				</a>
			</div>

			<?php idpg_widget_areas_render( 'below-content' ); ?>

		</div>
	</main>

<?php
get_footer();
