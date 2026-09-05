<?php
/**
 * The template for displaying single events.
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
				$idpg_meta    = idpg_core_event_meta( $idpg_post_id );
				$idpg_sessions = idpg_core_event_sessions( $idpg_post_id );
				$idpg_price   = idpg_core_format_price(
					$idpg_meta['price_from'],
					$idpg_meta['price_to'],
					$idpg_meta['currency'],
					$idpg_meta['is_free']
				);
				$idpg_ticket  = idpg_core_ticket_button( $idpg_post_id );
				$idpg_ticket_url = get_post_meta( $idpg_post_id, '_idpg_ticket_url', true );
				$idpg_yandex_form_url = get_post_meta( $idpg_post_id, '_idpg_yandex_form_url', true );
				$idpg_cities  = idpg_core_term_names( $idpg_post_id, 'city', 2 );
				$idpg_cats    = idpg_core_term_names( $idpg_post_id, 'event_category', 4 );
				$idpg_ages    = idpg_core_term_names( $idpg_post_id, 'age_rating', 2 );
				$idpg_venue   = idpg_core_get_venue( $idpg_post_id );
				$idpg_address = idpg_core_event_address( $idpg_post_id );
				$idpg_maps    = idpg_core_maps_link( $idpg_address );
				$idpg_video   = idpg_core_video_embed_url( $idpg_meta['video'] );
				$idpg_views   = (int) get_post_meta( $idpg_post_id, '_idpg_views', true );
				$idpg_archive = class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::afisha_url() : get_post_type_archive_link( 'event' );

				// Код встраивания трансляции (HTML-плеер).
				$idpg_stream_embed = get_post_meta( $idpg_post_id, '_idpg_stream_embed', true );
				?>

				<article id="post-<?php the_ID(); ?>" <?php post_class( 'idpg-event-single' ); ?>>

					<div class="idpg-event-single__back">
						<a href="<?php echo esc_url( $idpg_archive ? $idpg_archive : home_url( '/afisha/' ) ); ?>">
							<?php esc_html_e( '← Все события', 'idpg_core' ); ?>
						</a>
					</div>

					<header class="idpg-event-single__header">
						<div class="idpg-event-single__heading">
							<h1 class="idpg-event-single__title"><?php the_title(); ?></h1>

							<?php if ( $idpg_cities || $idpg_cats || $idpg_ages ) : ?>
								<div class="idpg-event-single__badges">
									<?php
									foreach ( array_merge( $idpg_cats, $idpg_ages, $idpg_cities ) as $idpg_badge ) {
										echo '<span class="idpg-event-single__badge">' . esc_html( $idpg_badge ) . '</span>';
									}
									?>
								</div>
							<?php endif; ?>
						</div>

						<?php if ( $idpg_ticket || ! empty( $idpg_yandex_form_url ) ) : ?>
							<div class="idpg-event-single__actions">
								<?php if ( $idpg_ticket ) : ?>
									<?php echo wp_kses_post( $idpg_ticket ); ?>
								<?php endif; ?>

								<?php if ( ! empty( $idpg_yandex_form_url ) ) : ?>
									<a
										class="idpg-btn idpg-btn--secondary idpg-event-single__register"
										href="<?php echo esc_url( $idpg_yandex_form_url ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										data-event-id="<?php echo esc_attr( $idpg_post_id ); ?>"
									>
										<?php esc_html_e( 'Зарегистрироваться на событие', 'idpg-core' ); ?>
									</a>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</header>

					<?php if ( has_post_thumbnail() ) : ?>
						<figure class="idpg-event-single__poster">
							<?php the_post_thumbnail( 'event-featured', array( 'loading' => 'eager' ) ); ?>
						</figure>
					<?php endif; ?>

					<div class="idpg-event-single__grid">
						<div class="idpg-event-single__main">

							<?php if ( has_excerpt() ) : ?>
								<div class="idpg-event-single__excerpt">
									<?php the_excerpt(); ?>
								</div>
							<?php endif; ?>

							<div class="idpg-event-single__content">
								<?php the_content(); ?>
							</div>

							<?php if ( $idpg_sessions ) : ?>
								<section class="idpg-event-single__block" aria-label="<?php esc_attr_e( 'Сеансы', 'idpg_core' ); ?>">
									<h2 class="idpg-event-single__block-title"><?php esc_html_e( 'Сеансы', 'idpg_core' ); ?></h2>
									<ul class="idpg-sessions">
										<?php foreach ( $idpg_sessions as $idpg_session ) : ?>
											<li class="idpg-sessions__item">
												<?php if ( ! empty( $idpg_session['url'] ) ) : ?>
													<a class="idpg-btn idpg-btn--secondary" href="<?php echo esc_url( $idpg_session['url'] ); ?>" target="_blank" rel="noopener noreferrer">
														<?php esc_html_e( 'Билеты', 'idpg_core' ); ?>
													</a>
												<?php elseif ( $idpg_ticket ) : ?>
					<?php echo wp_kses_post( $idpg_ticket ); ?>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								</section>
							<?php endif; ?>

							<?php
							$idpg_gallery = function_exists( 'carbon_get_post_meta' )
								? carbon_get_post_meta( $idpg_post_id, 'idpg_gallery' )
								: array();

							if ( $idpg_gallery ) :
								?>
								<section class="idpg-event-single__block" aria-label="<?php esc_attr_e( 'Галерея', 'idpg_core' ); ?>">
									<h2 class="idpg-event-single__block-title"><?php esc_html_e( 'Галерея', 'idpg_core' ); ?></h2>
									<div class="idpg-gallery">
										<?php
										foreach ( $idpg_gallery as $idpg_attachment ) {
											$idpg_attachment = absint( $idpg_attachment );
											$idpg_image      = wp_get_attachment_image( $idpg_attachment, 'event-card', false, array( 'loading' => 'lazy' ) );

											if ( $idpg_image ) {
												echo '<figure class="idpg-gallery__item">' . $idpg_image . '</figure>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
											}
										}
										?>
									</div>
								</section>
							<?php endif; ?>

								<?php if ( $idpg_video ) : ?>
								<section class="idpg-event-single__block" aria-label="<?php esc_attr_e( 'Видео', 'idpg_core' ); ?>">
									<h2 class="idpg-event-single__block-title"><?php esc_html_e( 'Видео', 'idpg_core' ); ?></h2>
									<?php if ( idpg_core_video_is_file( $idpg_video ) ) : ?>
										<div class="idpg-video">
											<video class="idpg-video__frame" controls preload="metadata" playsinline>
												<source src="<?php echo esc_url( $idpg_video ); ?>" type="<?php echo esc_attr( idpg_core_video_file_mime( $idpg_video ) ); ?>">
											</video>
										</div>
									<?php else : ?>
										<div class="idpg-video">
											<iframe class="idpg-video__frame" src="<?php echo esc_url( $idpg_video ); ?>" title="<?php the_title_attribute(); ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
										</div>
										<?php
										$idpg_host = idpg_core_video_host_label( $idpg_video );
										if ( $idpg_host['url'] && $idpg_host['url'] !== $idpg_video ) :
											$idpg_fallback_text = sprintf(
												/* translators: %1$s: название площадки, %2$s: ссылка для просмотра */
												__( 'Если видео не воспроизводится, перейдите на %1$s: <a href="%2$s" target="_blank" rel="noopener">%1$s →</a>', 'idpg_core' ),
												esc_html( $idpg_host['name'] ),
												esc_url( $idpg_host['url'] )
											);
											echo '<p class="idpg-video__fallback">' . $idpg_fallback_text . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										endif; ?>
									<?php endif; ?>
								</section>
							<?php endif; ?>

							<?php if ( ! empty( $idpg_stream_embed ) ) : ?>
								<section class="idpg-event-single__block" aria-label="<?php esc_attr_e( 'Трансляция', 'idpg_core' ); ?>">
									<h2 class="idpg-event-single__block-title"><?php esc_html_e( 'Прямой эфир', 'idpg_core' ); ?></h2>
									<div class="idpg-video idpg-video--embed">
										<?php echo wp_kses( $idpg_stream_embed, array(
											'iframe' => array(
												'src'         => true,
												'width'       => true,
												'height'      => true,
												'frameborder' => true,
												'allow'       => true,
												'allowfullscreen' => true,
												'title'       => true,
											),
											'video' => array(
												'src'      => true,
												'width'    => true,
												'height'   => true,
												'controls' => true,
												'poster'   => true,
											),
											'source' => array(
												'src'  => true,
												'type' => true,
											),
											'div'   => array(
												'class' => true,
												'id'    => true,
											),
										) ); ?>
									</div>
								</section>
							<?php elseif ( ! empty( $idpg_meta['is_online'] ) && ! empty( $idpg_meta['stream_url'] ) ) : ?>
								<section class="idpg-event-single__block" aria-label="<?php esc_attr_e( 'Трансляция', 'idpg_core' ); ?>">
									<h2 class="idpg-event-single__block-title"><?php esc_html_e( 'Онлайн-трансляция', 'idpg_core' ); ?></h2>
									<div class="idpg-video">
										<a href="<?php echo esc_url( $idpg_meta['stream_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="idpg-btn idpg-btn--primary">
											<?php esc_html_e( 'Перейти к трансляции', 'idpg_core' ); ?>
										</a>
									</div>
								</section>
							<?php endif; ?>

						</div>

						<aside class="idpg-event-single__sidebar">
							<dl class="idpg-event-single__info">
								<?php if ( $idpg_meta['date_start'] ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Даты', 'idpg_core' ); ?></dt>
										<dd><?php echo esc_html( idpg_core_event_date_range( $idpg_meta['date_start'], $idpg_meta['date_end'] ) ); ?></dd>
									</div>
								<?php endif; ?>

								<?php if ( ! empty( $idpg_meta['all_day'] ) ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Время', 'idpg_core' ); ?></dt>
										<dd><?php esc_html_e( 'Весь день', 'idpg_core' ); ?></dd>
									</div>
								<?php elseif ( $idpg_sessions ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Время', 'idpg_core' ); ?></dt>
										<dd><?php echo esc_html( implode( ', ', array_column( $idpg_sessions, 'time' ) ) ); ?></dd>
									</div>
								<?php endif; ?>

								<?php if ( $idpg_price ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Цена', 'idpg_core' ); ?></dt>
										<dd><?php echo esc_html( $idpg_price ); ?></dd>
									</div>
								<?php endif; ?>

								<?php if ( ! empty( $idpg_meta['vip_price'] ) && '0' !== $idpg_meta['vip_price'] ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'VIP-цена', 'idpg_core' ); ?></dt>
										<dd>
											<?php
											printf(
												/* translators: 1: VIP-цена, 2: символ валюты */
												esc_html__( 'от %1$s %2$s', 'idpg_core' ),
												esc_html( number_format_i18n( (float) $idpg_meta['vip_price'] ) ),
												esc_html( idpg_core_currency_symbol( $idpg_meta['currency'] ) )
											);
											?>
										</dd>
									</div>
								<?php endif; ?>

								<?php if ( $idpg_address ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Адрес', 'idpg_core' ); ?></dt>
										<dd>
											<span class="idpg-event-single__address-text"><?php echo esc_html( $idpg_address ); ?></span>
											<?php if ( $idpg_maps ) : ?>
												<a class="idpg-btn idpg-btn--primary idpg-event-single__map-link" href="<?php echo esc_url( $idpg_maps ); ?>" target="_blank" rel="noopener noreferrer">
													<?php esc_html_e( 'на карте', 'idpg_core' ); ?>
												</a>
											<?php endif; ?>
										</dd>
									</div>
								<?php endif; ?>

								<?php if ( ! empty( $idpg_meta['is_online'] ) && ! empty( $idpg_meta['stream_url'] ) ) : ?>
									<div class="idpg-event-single__info-row">
										<dt><?php esc_html_e( 'Формат', 'idpg_core' ); ?></dt>
										<dd>
											<a href="<?php echo esc_url( $idpg_meta['stream_url'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php esc_html_e( 'Онлайн-трансляция', 'idpg_core' ); ?>
											</a>
										</dd>
									</div>
								<?php endif; ?>
							</dl>

							<?php if ( $idpg_venue ) : ?>
								<?php $idpg_venue_meta = idpg_core_venue_meta( $idpg_venue->ID ); ?>
								<div class="idpg-venue-mini">
									<h3 class="idpg-venue-mini__title">
										<a href="<?php echo esc_url( get_permalink( $idpg_venue ) ); ?>"><?php echo esc_html( $idpg_venue->post_title ); ?></a>
									</h3>

									<?php if ( ! empty( $idpg_venue_meta['phone'] ) ) : ?>
										<p class="idpg-venue-mini__row">
											<a href="tel:<?php echo esc_attr( preg_replace( '/[^+0-9]/', '', $idpg_venue_meta['phone'] ) ); ?>">
												<?php echo esc_html( $idpg_venue_meta['phone'] ); ?>
											</a>
										</p>
									<?php endif; ?>

									<?php if ( ! empty( $idpg_venue_meta['capacity'] ) ) : ?>
										<p class="idpg-venue-mini__row">
											<?php
											printf(
												/* translators: %s: вместимость */
												esc_html__( 'Вместимость: %s', 'idpg_core' ),
												esc_html( number_format_i18n( (int) $idpg_venue_meta['capacity'] ) )
											);
											?>
										</p>
									<?php endif; ?>

							<a class="idpg-btn idpg-btn--secondary idpg-venue-mini__link" href="<?php echo esc_url( get_permalink( $idpg_venue ) ); ?>">
								<?php esc_html_e( 'О площадке', 'idpg_core' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</aside>
			</div>

		<?php idpg_core_event_share_buttons( $idpg_post_id ); ?>

		<?php if ( $idpg_venue ) : ?>
			<?php
			$idpg_venue_events = new WP_Query(
					array(
						'post_type'      => 'event',
						'post_status'    => 'publish',
						'posts_per_page' => 6,
						'orderby'        => 'meta_value',
						'meta_key'       => '_idpg_start_date',
						'order'          => 'ASC',
						'meta_query'     => array(
							array(
								'key'     => '_idpg_venue',
								'value'   => $idpg_venue->ID,
								'compare' => '=',
							),
						),
					)
				);
				?>

				<?php if ( $idpg_venue_events->have_posts() ) : ?>
					<section class="idpg-event-single__block" aria-label="<?php esc_attr_e( 'Другие события на этой площадке', 'idpg_core' ); ?>">
						<div class="idpg-events-grid">
							<?php
							while ( $idpg_venue_events->have_posts() ) :
								$idpg_venue_events->the_post();
								if ( get_the_ID() !== $idpg_post_id ) {
									get_template_part( 'template-parts/content', 'event' );
								}
							endwhile;
							wp_reset_postdata();
							?>
						</div>
					</section>
				<?php endif; ?>
			<?php endif; ?>

		</article>

		<?php
		// Schema.org/Event — JSON-LD микроразметка канонической страницы.
		idpg_core_event_jsonld( $idpg_post_id );
		?>

				<?php
			endwhile;
			?>

			<?php idpg_widget_areas_render( 'below-content' ); ?>

		</div>
	</main>

<?php
get_footer();