<?php
/**
 * idpg_core functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package idpg_core
 */

if ( ! defined( 'IDPG_VERSION' ) ) {
	/*
	 * Версия темы. Единый источник для кэш-бастинга ассетов: значение
	 * подставляется в `?ver=` у style.css и скриптов темы.
	 *
	 * ВАЖНО: должна совпадать с заголовком `Version:` в style.css.
	 * 1.0.78: синхронизация после отката DEV-102 (DEV-138). Ранее в дереве
	 * оставалась 1.0.75 при заявленной DEV-84 версии 1.0.77 — версия не росла,
	 * поэтому браузеры отдавали ассеты из кэша.
	 */
	define( 'IDPG_VERSION', '1.0.79' );
}

// Load helper functions
require_once get_template_directory() . '/inc/functions-helpers.php';

// Load menu functions
require_once get_template_directory() . '/inc/functions-menus.php';

// Load setup functions
require_once get_template_directory() . '/inc/functions-setup.php';

// Load page creation functions
require_once get_template_directory() . '/inc/functions-pages.php';

// Load legal document functions
require_once get_template_directory() . '/inc/functions-legal.php';

// Load query and filter functions
require_once get_template_directory() . '/inc/functions-query.php';

// Load customizer functions
require_once get_template_directory() . '/inc/functions-customizer.php';

