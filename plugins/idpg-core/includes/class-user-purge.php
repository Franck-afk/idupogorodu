<?php
/**
 * Полная зачистка данных организатора при удалении профиля.
 *
 * WordPress стандартно удаляет все посты автора и всю user meta
 * удаляемого пользователя (включая соц-связи, анкету, избранное).
 * Этот модуль добавляет то, что WP НЕ покрывает при удалении
 * организатора админом:
 *
 *   — постеры/миниатюры событий, загруженные другим автором (админом)
 *     и прикреплённые к событиям организатора через _thumbnail_id;
 *   — вложения-потомки событий/площадок;
 *   — аватар организатора (вложение из user meta idpg_avatar_attachment_id);
 *   — сиротские метки последнего редактирования (_edit_last/_edit_lock),
 *     ссылающиеся на удаляемого организатора.
 *
 * Логика следует осознанному выбору админа на странице «Пользователи»:
 *   — если админ выбрал «переназначить записи другому пользователю»
 *     ($reassign задан) — контент сохраняется у нового владельца,
 *     чистим только сиротские ссылки на удаляемого юзера;
 *   — если админ выбрал «удалить все записи» — контент организатора
 *     удаляется полностью вместе с вложениями.
 *
 * Удаление дубля-подписчика при склейке аккаунтов (REST connect-accounts)
 * не затрагивается: там роль subscriber, а обработчик реагирует только
 * на event_organizer.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_User_Purge
 */
class IDPG_User_Purge {

	/**
	 * Экземпляр синглтона.
	 *
	 * @var IDPG_User_Purge|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_User_Purge
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор. Цепляет обработчик удаления пользователя.
	 */
	private function __construct() {
		// delete_user срабатывает внутри wp_delete_user ДО удаления постов автора.
		add_action( 'delete_user', array( $this, 'on_delete_user' ), 10, 2 );
	}

	/**
	 * Обработчик удаления пользователя (хук delete_user).
	 *
	 * @param int      $user_id  ID удаляемого пользователя.
	 * @param int|null $reassign ID пользователя для переназначения записей (или null).
	 */
	public function on_delete_user( $user_id, $reassign = null ) {
		// Реагируем только на удаление именно организатора.
		if ( ! $this->is_organizer( $user_id ) ) {
			return;
		}

		if ( empty( $reassign ) ) {
			// Админ выбрал «удалить все записи» — полная зачистка контента.
			$this->purge_avatar( $user_id );
			$this->purge_organizer_content( $user_id );
			$this->clean_orphan_refs( $user_id );
			return;
		}

		// Админ выбрал «переназначить» — контент сохраняется у нового
		// владельца; вычищаем только сиротские ссылки удаляемого юзера.
		$this->clean_orphan_refs( $user_id );
	}

	/**
	 * Является ли пользователь организатором.
	 *
	 * @param int $user_id ID пользователя.
	 * @return bool
	 */
	private function is_organizer( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return $user && in_array( 'event_organizer', (array) $user->roles, true );
	}

	/**
	 * Удаляет вложение-аватар организатора (если существует).
	 *
	 * @param int $user_id ID пользователя.
	 */
	private function purge_avatar( $user_id ) {
		$attachment_id = IDPG_Media::idpg_get_avatar_id( $user_id );
		if ( $attachment_id ) {
			IDPG_Media::idpg_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Удаляет все записи автора-организатора с их вложениями.
	 *
	 * @param int $user_id ID пользователя.
	 */
	private function purge_organizer_content( $user_id ) {
		$post_ids = get_posts(
			array(
				'author'           => (int) $user_id,
				'post_type'        => 'any',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
			)
		);

		foreach ( $post_ids as $post_id ) {
			$this->delete_post_with_assets( (int) $post_id );
		}
	}

	/**
	 * Удаляет запись вместе с миниатюрой и вложениями-потомками.
	 *
	 * @param int $post_id ID записи.
	 */
	private function delete_post_with_assets( $post_id ) {
		IDPG_Media::idpg_delete_post_media( $post_id );
	}

	/**
	 * Удаляет сиротские мета-записи, ссылающиеся на удаляемого юзера.
	 *
	 * _edit_last хранит просто ID последнего редактора;
	 * _edit_lock хранит "timestamp:user_id".
	 *
	 * @param int $user_id ID пользователя.
	 */
	private function clean_orphan_refs( $user_id ) {
		$post_ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_edit_last',
						'value'   => (string) $user_id,
						'compare' => '=',
					),
					array(
						'key'     => '_edit_lock',
						'value'   => ':' . (string) $user_id,
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( (int) get_post_meta( $post_id, '_edit_last', true ) === (int) $user_id ) {
				delete_post_meta( $post_id, '_edit_last' );
			}

			// _edit_lock имеет формат "timestamp:user_id" (может содержать
			// несколько пар). Убираем только пару удаляемого пользователя.
			$lock = get_post_meta( $post_id, '_edit_lock', true );
			if ( is_string( $lock ) && '' !== $lock ) {
				$new_lock = trim( preg_replace( '/:' . preg_quote( (string) $user_id, '/' ) . '\b/', '', $lock ) );
				if ( $new_lock !== $lock ) {
					// Если не осталось ни одной пары "timestamp:user_id" —
					// метка больше не несёт смысла, удаляем целиком.
					if ( preg_match( '/:\d/', $new_lock ) ) {
						update_post_meta( $post_id, '_edit_lock', $new_lock );
					} else {
						delete_post_meta( $post_id, '_edit_lock' );
					}
				}
			}
		}
	}
}

// Инициализация.
IDPG_User_Purge::instance();
