<?php
/**
 * Раздел «Площадки» кабинета организатора (DEV-50).
 *
 * Список отдаёт REST `GET /idpg/v1/organizer/venues`, а `organizer.js`
 * наполняет таблицу. Каркас рендерится серверно: без JavaScript остаются
 * заголовок и рабочая ссылка «Создать площадку», а сам список пуст с
 * подсказкой — это осознанно, потому что список формируется запросом.
 *
 * Пустая выборка не должна молча выглядеть как «у вас нет площадок»,
 * если запрос не прошёл: ошибка показывается отдельным блоком.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$idpg_venues_new_url = class_exists( 'IDPG_Organizer_URL' )
	? IDPG_Organizer_URL::venue_form_url( 0 )
	: home_url( '/organizer/venue/new/' );
?>

<div
	class="dashboard"
	data-idpg-venues
	data-idpg-venues-list-url="<?php echo esc_url( rest_url( 'idpg/v1/organizer/venues' ) ); ?>"
	data-idpg-venues-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	data-idpg-venues-new-url="<?php echo esc_url( $idpg_venues_new_url ); ?>"
>
	<p class="dashboard__actions">
		<a class="idpg-btn idpg-btn--primary" href="<?php echo esc_url( $idpg_venues_new_url ); ?>">
			<?php esc_html_e( '+ Создать площадку', 'idpg_core' ); ?>
		</a>
	</p>

	<!--
		Три взаимоисключающих состояния раздела. Ровно одно из них видимо, и
		переключает их `initVenues()` в organizer.js:

		  * загрузка — видна сразу, чтобы страница не выглядела пустой, пока
		    идёт запрос;
		  * таблица — показывается только при непустой выборке: шапка с нулём
		    строк под ней читается как сломанная страница;
		  * пусто / ошибка — вместо таблицы.

		Ошибка и пустое состояние разведены намеренно: упавший запрос не
		должен выглядеть как «у вас нет площадок», иначе организатор заведёт
		дубли, решив, что потерял данные.
	-->
	<p class="dashboard__empty" data-idpg-venues-loading>
		<?php esc_html_e( 'Загрузка…', 'idpg_core' ); ?>
	</p>

	<table class="dashboard__table" data-idpg-venues-table hidden>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Площадка', 'idpg_core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Тип', 'idpg_core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Адрес', 'idpg_core' ); ?></th>
			</tr>
		</thead>
		<tbody data-idpg-venues-body></tbody>
	</table>

	<div class="dashboard__empty" hidden data-idpg-venues-empty>
		<p class="dashboard__title"><?php esc_html_e( 'Пока ни одной площадки', 'idpg_core' ); ?></p>
		<p class="dashboard__subtitle">
			<?php esc_html_e( 'Создайте площадку, чтобы затем выбирать её в событиях.', 'idpg_core' ); ?>
		</p>
	</div>

	<div
		class="idpg-notice idpg-notice--error"
		role="alert"
		hidden
		data-idpg-venues-error
	></div>

	<noscript>
		<div class="idpg-notice idpg-notice--info">
			<?php esc_html_e( 'Список площадок загружается скриптом. Создать новую площадку можно и без JavaScript.', 'idpg_core' ); ?>
		</div>
	</noscript>
</div>
