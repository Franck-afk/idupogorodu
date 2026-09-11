<?php
/**
 * Шаблон результатов поиска.
 *
 * Поиск по событиям города: /afisha/{city}/search/?s=… (rewrite из
 * IDPG_City_URL) или общий /?s=… (без ограничения по типу/городу).
 *
 * @package idpg_core
 */

get_header();

$idpg_s_city  = (string) get_query_var( idpg_core_city_qvar() );
$idpg_s_query = get_search_query();
?>

	<main id="primary" class="site-main">
		<div class="container">

			<header class="idpg-archive-header">
				<h1 class="idpg-archive-header__title">
					<?php
					if ( $idpg_s_query ) {
						printf(
							/* translators: %s: поисковый запрос */
							esc_html__( 'Поиск: %s', 'idpg_core' ),
							'<span>' . esc_html( $idpg_s_query ) . '</span>'
						);
					} else {
						esc_html_e( 'Поиск по событиям', 'idpg_core' );
					}
					?>
				</h1>

				<form class="idpg-search-form idpg-search--live" role="search" method="get"
					action="<?php echo esc_url( $idpg_s_city ? home_url( user_trailingslashit( '/afisha/' . $idpg_s_city . '/search/' ) ) : home_url( '/' ) ); ?>" data-idpg-live-search>
					<input type="search" class="idpg-search-form__input" name="s"
						value="<?php echo esc_attr( $idpg_s_query ); ?>"
						placeholder="<?php esc_attr_e( 'Название события…', 'idpg_core' ); ?>"
						aria-label="<?php esc_attr_e( 'Поиск', 'idpg_core' ); ?>"
						autocomplete="off" data-idpg-live-input>
					<button type="submit" class="idpg-btn idpg-btn--primary"><?php esc_html_e( 'Найти', 'idpg_core' ); ?></button>
					<div class="idpg-search__dropdown" hidden></div>
				</form>

				<div class="idpg-archive-header__back">
					<a href="<?php echo esc_url( class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::afisha_url() : home_url( '/afisha/' ) ); ?>">
						<?php esc_html_e( '← Все события', 'idpg_core' ); ?>
					</a>
				</div>
			</header>

			<?php if ( have_posts() ) : ?>

				<p class="idpg-search-count">
					<?php
					printf(
						/* translators: %d: количество результатов */
						esc_html( _n( 'Найдено событий: %d', 'Найдено событий: %d', (int) $wp_query->found_posts, 'idpg_core' ) ),
						(int) $wp_query->found_posts
					);
					?>
				</p>

				<div class="idpg-events-grid idpg-events-grid--3 idpg-events-grid--v">
					<?php
					while ( have_posts() ) :
						the_post();

						get_template_part( 'template-parts/content', 'event' );

					endwhile;
					?>
				</div>

				<?php
				the_posts_navigation();
				?>

			<?php else : ?>

				<div class="idpg-archive-header__empty">
					<?php esc_html_e( 'По вашему запросу ничего не найдено. Попробуйте изменить формулировку.', 'idpg_core' ); ?>
					<p class="idpg-search-empty__tip">
						<?php esc_html_e( 'Проверьте орфографию или попробуйте более короткий запрос — например, одно слово.', 'idpg_core' ); ?>
					</p>
					<?php
					// Подсказка: популярные рубрики города.
					$idpg_empty_cats = get_terms(
						array(
							'taxonomy'   => 'event_category',
							'hide_empty' => true,
							'orderby'    => 'count',
							'order'      => 'DESC',
							'number'     => 8,
						)
					);
					if ( ! is_wp_error( $idpg_empty_cats ) && $idpg_empty_cats ) :
						?>
						<p class="idpg-search-empty__cats-title"><?php esc_html_e( 'Популярные рубрики:', 'idpg_core' ); ?></p>
						<ul class="idpg-search-empty__cats">
							<?php foreach ( $idpg_empty_cats as $idpg_empty_cat ) : ?>
								<?php $idpg_empty_link = get_term_link( $idpg_empty_cat ); ?>
								<?php if ( is_wp_error( $idpg_empty_link ) ) continue; ?>
								<li>
									<a href="<?php echo esc_url( $idpg_empty_link ); ?>">
										<?php echo esc_html( $idpg_empty_cat->name ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

			<?php endif; ?>

		</div>
	</main>

<?php
get_footer();
