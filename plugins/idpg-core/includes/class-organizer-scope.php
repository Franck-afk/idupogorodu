<?php
/**
 * Изоляция контента организатора/подписчика в админке.
 *
 * - Медиатека (список + REST media-modal): пользователь без прав
 *   edit_others_posts видит только СВОИ вложения.
 * - Список событий в админке уже скоупится классом
 *   IDPG_City_Filter::filter_events_for_organizer().
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IDPG_Organizer_Scope {

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'scope_admin_attachments' ), 20 );
		add_filter( 'rest_attachment_query', array( __CLASS__, 'scope_rest_attachments' ), 10, 2 );
	}

	/**
	 * Нужно ли ограничивать текущего пользователя своими объектами.
	 */
	private static function needs_scope() {
		return ! current_user_can( 'edit_others_posts' );
	}

	/**
	 * Классический список медиафайлов в админке.
	 */
	public static function scope_admin_attachments( $query ) {
		if ( is_admin() && $query->is_main_query() && self::needs_scope() ) {
			$post_type = $query->get( 'post_type' );

			if ( 'attachment' === $post_type ) {
				$query->set( 'author', get_current_user_id() );
			}
		}
	}

	/**
	 * REST /wp/v2/media (модальное окно выбора файла в редакторе).
	 */
	public static function scope_rest_attachments( $args, $request ) {
		if ( self::needs_scope() ) {
			$args['author'] = get_current_user_id();
		}

		return $args;
	}
}

IDPG_Organizer_Scope::init();
