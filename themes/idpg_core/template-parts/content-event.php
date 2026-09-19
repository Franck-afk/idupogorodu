<?php
/**
 * Template part for displaying event cards in lists.
 *
 * Вертикальная карточка: фото сверху (4/3), информация на белом фоне ниже.
 * Используется в archive-event.php, front-page.php, search.php,
 * page-favorites.php, single-event.php, single-venue.php и таксономиях.
 * Работает как с основным циклом, так и с произвольным WP_Query
 * (после setup_postdata).
 *
 * @package idpg_core
 */

$idpg_post_id  = get_the_ID();
$idpg_meta     = idpg_core_event_meta( $idpg_post_id );
$idpg_sessions = idpg_core_event_sessions( $idpg_post_id );
$idpg_thumb    = idpg_core_event_poster( $idpg_post_id, 'event-card' );
$idpg_perm     = get_permalink( $idpg_post_id );

$idpg_time = '';
if ( ! empty( $idpg_meta['all_day'] ) ) {
	$idpg_time = __( 'весь день', 'idpg_core' );
} elseif ( $idpg_sessions ) {
	$idpg_time = esc_html( $idpg_sessions[0]['time'] );
}

$idpg_price = idpg_core_format_price(
	$idpg_meta['price_from'],
	$idpg_meta['price_to'],
	$idpg_meta['currency'],
	$idpg_meta['is_free']
);

$idpg_vip_price = '';
if ( ! empty( $idpg_meta['vip_price'] ) && '0' !== $idpg_meta['vip_price'] ) {
	$idpg_vip_price = sprintf(
		/* translators: 1: VIP-цена, 2: символ валюты */
		__( 'VIP: %1$s %2$s', 'idpg_core' ),
		number_format_i18n( (float) $idpg_meta['vip_price'] ),
		idpg_core_currency_symbol( $idpg_meta['currency'] )
	);
}

$idpg_date = idpg_core_event_date_range( $idpg_meta['date_start'], $idpg_meta['date_end'] );
if ( $idpg_time ) {
	$idpg_date = $idpg_date ? $idpg_date . ', ' . $idpg_time : $idpg_time;
}

$idpg_status_labels = array(
	'in_sale'   => __( 'Билеты в продаже', 'idpg_core' ),
	'sold_out'  => __( 'Билеты закончились', 'idpg_core' ),
	'cancelled' => __( 'Отменено', 'idpg_core' ),
	'postponed' => __( 'Перенесено', 'idpg_core' ),
);
$idpg_status_label = isset( $idpg_status_labels[ $idpg_meta['status'] ] ) ? $idpg_status_labels[ $idpg_meta['status'] ] : '';

$idpg_is_new       = ( time() - get_the_time( 'U' ) ) < 14 * DAY_IN_SECONDS;
$idpg_is_online    = ! empty( $idpg_meta['is_online'] );
$idpg_is_promoted  = 'yes' === get_post_meta( $idpg_post_id, '_idpg_promoted', true );

$idpg_chips = array();
if ( $idpg_status_label ) {
	$idpg_chips[] = array( 'label' => $idpg_status_label, 'type' => 'status' );
}
if ( ! empty( $idpg_meta['is_free'] ) ) {
	$idpg_chips[] = array( 'label' => __( 'Бесплатно', 'idpg_core' ), 'type' => 'free' );
}

// Бейдж «Популярное»: просмотры выше порога (среднее по секции на главной).
$idpg_pop_min = isset( $GLOBALS['idpg_popular_min_views'] ) ? (int) $GLOBALS['idpg_popular_min_views'] : 0;
if ( $idpg_pop_min > 0 && (int) get_post_meta( $idpg_post_id, '_idpg_views', true ) >= $idpg_pop_min ) {
	$idpg_chips[] = array( 'label' => __( 'Популярное', 'idpg_core' ), 'type' => 'popular' );
}
if ( $idpg_is_promoted ) {
	$idpg_chips[] = array( 'label' => __( 'ТОП', 'idpg_core' ), 'type' => 'promo' );
}
if ( $idpg_is_online ) {
	$idpg_chips[] = array( 'label' => __( 'Онлайн', 'idpg_core' ), 'type' => 'online' );
}
if ( $idpg_is_new ) {
	$idpg_chips[] = array( 'label' => __( 'Новый', 'idpg_core' ), 'type' => 'new' );
}

$idpg_cities = idpg_core_term_names( $idpg_post_id, 'city', 1 );
$idpg_cats   = idpg_core_term_names( $idpg_post_id, 'event_category', 2 );
$idpg_ages   = idpg_core_term_names( $idpg_post_id, 'age_rating', 1 );

$idpg_meta_items = array();
if ( $idpg_date ) {
	$idpg_meta_items[] = $idpg_date;
}
if ( $idpg_cities ) {
	$idpg_meta_items[] = implode( ', ', $idpg_cities );
}
if ( $idpg_cats ) {
	$idpg_meta_items[] = implode( ', ', $idpg_cats );
}
if ( $idpg_ages ) {
	$idpg_meta_items[] = implode( ', ', $idpg_ages );
}

$idpg_venue      = idpg_core_get_venue( $idpg_post_id );
$idpg_venue_name = $idpg_venue ? $idpg_venue->post_title : '';
$idpg_address    = trim( (string) $idpg_meta['address'] );

$idpg_venue_items = array();
if ( $idpg_venue_name ) {
	$idpg_venue_items[] = $idpg_venue_name;
}
if ( $idpg_address ) {
	$idpg_venue_items[] = $idpg_address;
}

$idpg_placeholder_letter = mb_strtoupper( mb_substr( get_the_title(), 0, 1 ) );
?>

<a id="post-<?php the_ID(); ?>" <?php post_class( 'idpg-event-card' ); ?> href="<?php echo esc_url( $idpg_perm ); ?>">
	<?php
	// Сердце «в избранное» — поверх фото, только для авторизованных.
	if ( class_exists( 'IDPG_Favorites' ) ) {
		IDPG_Favorites::button( $idpg_post_id );
	}
	?>

	<?php if ( $idpg_thumb ) : ?>
		<?php echo $idpg_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php else : ?>
		<span class="idpg-event-card__placeholder"><?php echo esc_html( $idpg_placeholder_letter ); ?></span>
	<?php endif; ?>

	<span class="idpg-event-card__info">
		<?php if ( $idpg_chips ) : ?>
			<span class="idpg-event-card__chips">
				<?php foreach ( $idpg_chips as $idpg_chip ) : ?>
					<span class="idpg-event-card__chip idpg-event-card__chip--<?php echo esc_attr( $idpg_chip['type'] ); ?>"><?php echo esc_html( $idpg_chip['label'] ); ?></span>
				<?php endforeach; ?>
			</span>
		<?php endif; ?>

		<h2 class="idpg-event-card__title"><?php the_title(); ?></h2>

		<?php if ( $idpg_meta_items ) : ?>
			<span class="idpg-event-card__meta"><?php echo esc_html( implode( ' • ', $idpg_meta_items ) ); ?></span>
		<?php endif; ?>

		<?php if ( $idpg_venue_items ) : ?>
			<span class="idpg-event-card__venue"><?php echo esc_html( implode( ', ', $idpg_venue_items ) ); ?></span>
		<?php endif; ?>

		<span class="idpg-event-card__footer">
			<?php if ( $idpg_price ) : ?>
				<span class="idpg-event-card__price">
					<?php echo esc_html( $idpg_price ); ?>
					<?php if ( $idpg_vip_price ) : ?>
						<small class="idpg-event-card__vip"><?php echo esc_html( $idpg_vip_price ); ?></small>
					<?php endif; ?>
				</span>
			<?php endif; ?>

			<span class="idpg-event-card__btn"><?php esc_html_e( 'Подробнее', 'idpg_core' ); ?></span>
		</span>
	</span>
</a>
<?php
// Schema.org/Event — JSON-LD микроразметка для поисковиков.
idpg_core_event_jsonld( $idpg_post_id );
?>
