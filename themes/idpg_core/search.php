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

$idpg_s_city  = (string) get_query_var( IDPG_City_URL::QVAR );
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

				<form class="idpg-search-form" role="search" method="get"
					action="<?php echo esc_url( $idpg_s_city ? home_url( user_trailingslashit( '/afisha/' . $idpg_s_city . '/search/' ) ) : home_url( '/' ) ); ?>">
					<input type="search" class="idpg-search-form__input" name="s"
						value="<?php echo esc_attr( $idpg_s_query ); ?>"
						placeholder="<?php esc_attr_e( 'Название события…', 'idpg_core' ); ?>"
						aria-label="<?php esc_attr_e( 'Поиск', 'idpg_core' ); ?>">
					<button type="submit" class="idpg-btn idpg-btn--primary"><?php esc_html_e( 'Найти', 'idpg_core' ); ?></button>
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
				</div>

			<?php endif; ?>

		</div>
	</main>

<?php
get_footer();
