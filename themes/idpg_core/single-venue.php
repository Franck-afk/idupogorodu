<?php
/**
 * The template for displaying single venues.
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

				$idpg_post_id = get_the_ID();
				$idpg_meta    = idpg_core_venue_meta( $idpg_post_id );
				$idpg_archive = get_post_type_archive_link( 'venue' );
				?>

				<article id="post-<?php the_ID(); ?>" <?php post_class( 'idpg-venue-single' ); ?>>

					<div class="idpg-event-single__back">
						<a href="<?php echo esc_url( $idpg_archive ? $idpg_archive : home_url( '/venues/' ) ); ?>">
							<?php esc_html_e( '← Все площадки', 'idpg_core' ); ?>
						</a>
					</div>

					<header class="idpg-venue-single__header">
						<div class="idpg-venue-single__heading">
							<h1 class="idpg-event-single__title"><?php the_title(); ?></h1>

							<?php if ( ! empty( $idpg_meta['address'] ) ) : ?>
								<p class="idpg-venue-single__address">
									<?php echo esc_html( $idpg_meta['address'] ); ?>
								</p>
							<?php endif; ?>
						</div>

						<?php if ( ! empty( $idpg_meta['phone'] ) || ! empty( $idpg_meta['email'] ) || ! empty( $idpg_meta['website'] ) ) : ?>
							<div class="idpg-venue-single__actions">
								<?php if ( ! empty( $idpg_meta['phone'] ) ) : ?>
									<a class="idpg-btn idpg-btn--primary" href="tel:<?php echo esc_attr( preg_replace( '/[^+0-9]/', '', $idpg_meta['phone'] ) ); ?>">
										<?php echo esc_html( $idpg_meta['phone'] ); ?>
									</a>
								<?php endif; ?>

								<?php if ( ! empty( $idpg_meta['website'] ) ) : ?>
									<a class="idpg-btn idpg-btn--secondary" href="<?php echo esc_url( $idpg_meta['website'] ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'Сайт', 'idpg_core' ); ?>
									</a>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</header>

<?php if ( IDPG_Media::idpg_get_poster_id( get_the_ID() ) ) : ?>
					<figure class="idpg-event-single__poster">
						<?php echo IDPG_Media::idpg_get_poster( get_the_ID(), 'event-featured', array( 'loading' => 'eager' ) ); ?>
					</figure>
				<?php endif; ?>

					<div class="idpg-event-single__grid">
						<div class="idpg-event-single__main">
							<div class="idpg-event-single__content">
								<?php the_content(); ?>
							</div>

							<?php
							$idpg_venue_events = new WP_Query(
								array(
									'post_type'      => 'event',
									'post_status'    => 'publish',
									'posts_per_page' => 12,
									'orderby'        => 'meta_value',
									'meta_key'       => '_idpg_start_date',
									'order'          => 'ASC',
									'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
										array(
											'key'     => '_idpg_venue',
											'value'   => $idpg_post_id,
											'compare' => '=',
										),
									),
								)
							);

							if ( $idpg_venue_events->have_posts() ) :
								?>
								<section class="idpg-event-single__block" aria-label="<?php esc_attr_e( 'События площадки', 'idpg_core' ); ?>">
									<h2 class="idpg-event-single__block-title"><?php esc_html_e( 'События площадки', 'idpg_core' ); ?></h2>
									<div class="idpg-events-grid">
										<?php
										while ( $idpg_venue_events->have_posts() ) :
											$idpg_venue_events->the_post();
											get_template_part( 'template-parts/content', 'event' );
										endwhile;
										wp_reset_postdata();
										?>
									</div>
								</section>
							<?php endif; ?>
						</div>

<aside class="idpg-event-single__sidebar">
							<?php /* Области виджетов админа в сайдбаре страницы площадки — вверху сайдбара. */ ?>
							<?php idpg_widget_areas_render( 'event-sidebar' ); ?>

							<dl class="idpg-event-single__info">
								<?php if ( ! empty( $idpg_meta['address'] ) ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Адрес', 'idpg_core' ); ?></dt>
										<dd><?php echo esc_html( $idpg_meta['address'] ); ?></dd>
									</div>
								<?php endif; ?>

								<?php if ( ! empty( $idpg_meta['phone'] ) ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Телефон', 'idpg_core' ); ?></dt>
										<dd>
											<a href="tel:<?php echo esc_attr( preg_replace( '/[^+0-9]/', '', $idpg_meta['phone'] ) ); ?>">
												<?php echo esc_html( $idpg_meta['phone'] ); ?>
											</a>
										</dd>
									</div>
								<?php endif; ?>

								<?php if ( ! empty( $idpg_meta['email'] ) ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Email', 'idpg_core' ); ?></dt>
										<dd>
											<a href="mailto:<?php echo esc_attr( $idpg_meta['email'] ); ?>">
												<?php echo esc_html( $idpg_meta['email'] ); ?>
											</a>
										</dd>
									</div>
								<?php endif; ?>

								<?php if ( ! empty( $idpg_meta['website'] ) ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Сайт', 'idpg_core' ); ?></dt>
										<dd>
											<a href="<?php echo esc_url( $idpg_meta['website'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php echo esc_html( $idpg_meta['website'] ); ?>
											</a>
										</dd>
									</div>
								<?php endif; ?>

								<?php if ( ! empty( $idpg_meta['capacity'] ) ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Вместимость', 'idpg_core' ); ?></dt>
										<dd><?php echo esc_html( number_format_i18n( (int) $idpg_meta['capacity'] ) ); ?></dd>
</div>
								<?php endif; ?>
							</dl>
						</aside>
					</div>

				</article>

				<?php
			endwhile;
			?>

			<?php idpg_widget_areas_render( 'below-content' ); ?>

		</div>
	</main>

<?php
get_footer();
