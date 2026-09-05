<?php
/**
 * Template part for displaying a message that events cannot be found
 *
 * @package idpg_core
 */

?>

<section class="no-results not-found">
	<header class="page-header">
		<h1 class="page-title">Событий не найдено</h1>
	</header>

	<div class="page-content">
		<?php if ( is_search() ) : ?>
			<p>По вашему запросу событий не найдено. Попробуйте изменить параметры поиска.</p>
			<?php get_search_form(); ?>
		<?php else : ?>
			<p>В данный момент событий нет. Загляните позже!</p>
		<?php endif; ?>
	</div>
</section>
