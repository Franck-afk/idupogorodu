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

/**
 * Carbon Fields bootstrap.
 *
 * ПЕРЕНЕСЕНО В ПЛАГИН idpg-core: boot выполняется в idpg-core.php
 * (after_setup_theme, приоритет 5). Закомментировано во избежание
 * конфликта имён функции и двойной инициализации.



/**
 * Theme helper functions.
 */
require_once get_template_directory() . '/inc/theme-helpers.php';

/**
 * Template tags для специализированных шаблонов (события, площадки).
 */
require_once get_template_directory() . '/inc/template-tags.php';

/**
 * Хлебные крошки (с поддержкой Yoast SEO).
 */
require_once get_template_directory() . '/inc/breadcrumbs.php';

	/**
	 * Advertising widget + sidebar areas.
	 */
	require_once get_template_directory() . '/inc/widgets.php';

/**
 * Кастомный Walker для навигации.
 */
require_once get_template_directory() . '/inc/class-idpg-nav-walker.php';

/**
 * Управление областями виджетов.
 */
require_once get_template_directory() . '/inc/widget-areas.php';

/**
 * Меню и вспомогательные функции кабинета организатора.
 *
 * Файл делегирует проверку страниц и построение URL классу
 * IDPG_Organizer_URL плагина idpg-core и содержит безопасные
 * заглушки, если плагин неактивен, — поэтому подключается
 * безусловно.
 *
 * DEV-234 удалял файл вместе с ЛК; DEV-312 вернул кабинет в объёме чтения.
 */
require_once get_template_directory() . '/inc/organizer-menu.php';


/**
 * Диапазон ближайших выходных (пятница — воскресенье).
 *
 * Если сегодня пт/сб/вс — возвращаются текущие выходные,
 * иначе — ближайшие наступающие. Используется фильтром афиши
 * (?date=weekend) и секционным блоком главной.
 *
/**
 * Настройки кастомайзера: логотип в секции «Свойства сайта» (title_tagline).
 *
 * Изображение логотипа выбирается базовым контролом WordPress «Логотип»
 * (настройка custom_logo, его использует the_custom_logo()). Наш единственный
 * дополнительный элемент — ползунок:
 * - idpg_logo_width — ширина логотипа, range 50–400 px (высота автоматически
 *   сохраняет пропорции);
 * Live-превью: selective refresh partial для блока .idpg-header__logo.
 *
 * @param WP_Customize_Manager $wp_customize Менеджер кастомайзера.
 */

