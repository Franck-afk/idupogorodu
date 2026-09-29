<?php
/**
 * Единый класс-хелпер для работы с медиафайлами.
 *
 * Централизует все операции с вложениями, которые используются
 * в плагине и теме:
 *
 *   — постер (обложка) события/площадки — чтение по ID и получение URL/HTML;
 *   — галерея события (Carbon Fields media_gallery `idpg_gallery`);
 *   — аватар организатора (вложение из user meta `idpg_avatar_attachment_id`);
 *   — поиск вложения по URL;
 *   — безопасное удаление вложений (одиночное и массовое с очисткой постера);
 *   — IS_AUDIO/IS_VIDEO-проверка типа файла.
 *
 * Класс содержит только статические методы, не выводит контент напрямую.
 * Тема и плагин вызывают методы как `IDPG_Media::idpg_get_poster( $id )`.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Media
 *
 * Единый шлюз для работы с медиафайлами проекта.
 * Акцент — читаемые обёртки над штатными функциями WordPress,
 * скрывающие детали кэширования и проверок.
 */
class IDPG_Media {

	/**
	 * Размеры изображений, используемые в шаблонах.
	 *
	 * @var string[]
	 */
	const SIZE_CARD      = 'event-card';
	const SIZE_FEATURED  = 'event-featured';
	const SIZE_LARGE     = 'large';
	const SIZE_MEDIUM    = 'medium';

	/**
	 * Мета-ключ аватара организатора.
	 *
	 * @var string
	 */
	const AVATAR_META_KEY = 'idpg_avatar_attachment_id';

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Media|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Media
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор. Сейчас не вешает хуков — класс объявлен как
	 * статический хелпер; хуки добавятся при необходимости.
	 */
	private function __construct() {
	}

	// =========================================================================
	// Постер события/площадки
	// =========================================================================

	/**
	 * ID постера (миниатюры) записи.
	 *
	 * @param int $post_id ID записи.
	 * @return int ID вложения или 0.
	 */
	public static function idpg_get_poster_id( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return 0;
		}

		return (int) get_post_thumbnail_id( $post_id );
	}

	/**
	 * URL постера записи указанного размера.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $size    Размер (event-card, event-featured, large, medium и т. д.).
	 * @return string URL изображения или пустая строка.
	 */
	public static function idpg_get_poster_url( $post_id, $size = 'large' ) {
		$id = self::idpg_get_poster_id( $post_id );

		if ( ! $id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, $size );

		return $url ? (string) $url : '';
	}

	/**
	 * HTML-тег <img> постера записи.
	 *
	 * @param int    $post_id  ID записи.
	 * @param string $size     Размер изображения.
	 * @param array  $atts     Дополнительные атрибуты (class, loading и пр.).
	 * @return string HTML-код изображения или пустая строка.
	 */
	public static function idpg_get_poster( $post_id, $size = 'large', $atts = array() ) {
		$id = self::idpg_get_poster_id( $post_id );

		if ( ! $id ) {
			return '';
		}

		return wp_get_attachment_image( $id, $size, false, $atts );
	}

	// =========================================================================
	// Галерея события
	// =========================================================================

	/**
	 * Массив ID вложений галереи события.
	 *
	 * Возвращает сырые ID из Carbon Fields media_gallery `idpg_gallery`.
	 *
	 * @param int $post_id ID события.
	 * @return int[] Массив ID вложений.
	 */
	public static function idpg_get_gallery_ids( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! function_exists( 'carbon_get_post_meta' ) ) {
			return array();
		}

		$gallery = carbon_get_post_meta( $post_id, 'idpg_gallery' );

		if ( ! is_array( $gallery ) ) {
			return array();
		}

		return array_map( 'absint', $gallery );
	}

	/**
	 * HTML-галерея события.
	 *
	 * Возвращает сетку фигур с изображениями. Пустая строка, если галерея пуста.
	 *
	 * @param int    $post_id ID события.
	 * @param string $size    Размер изображений (по умолчанию event-card).
	 * @return string HTML-код галереи.
	 */
	public static function idpg_get_gallery( $post_id, $size = 'event-card' ) {
		$ids = self::idpg_get_gallery_ids( $post_id );

		if ( empty( $ids ) ) {
			return '';
		}

		$html = '';

		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			if ( ! $attachment_id || ! get_post( $attachment_id ) ) {
				continue;
			}

			$image = wp_get_attachment_image( $attachment_id, $size, false, array( 'loading' => 'lazy' ) );

			if ( $image ) {
				$html .= '<figure class="idpg-gallery__item">' . $image . '</figure>';
			}
		}

		return $html;
	}

	// =========================================================================
	// Аватар организатора
	// =========================================================================

	/**
	 * ID вложения-аватара пользователя.
	 *
	 * @param int $user_id ID пользователя.
	 * @return int ID вложения или 0.
	 */
	public static function idpg_get_avatar_id( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return 0;
		}

		$attachment_id = (int) get_user_meta( $user_id, self::AVATAR_META_KEY, true );

		if ( ! $attachment_id || ! get_post( $attachment_id ) ) {
			return 0;
		}

		return $attachment_id;
	}

	/**
	 * URL аватара пользователя (вложение из ЛК). Пустая строка, если нет.
	 *
	 * @param int    $user_id ID пользователя.
	 * @param string $size    Размер (число пикселей или именованный размер).
	 * @return string URL изображения.
	 */
	public static function idpg_get_avatar_url( $user_id, $size = 'medium' ) {
		$id = self::idpg_get_avatar_id( $user_id );

		if ( ! $id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, $size );

		return $url ? (string) $url : '';
	}

	// =========================================================================
	// Поиск вложения
	// =========================================================================

	/**
	 * ID вложения по его URL.
	 *
	 * Сначала пробует штатный attachment_url_to_postid, при промахе —
	 * резервный поиск по GUID.
	 *
	 * @param string $url URL вложения.
	 * @return int ID вложения или 0.
	 */
	public static function idpg_get_attachment_id_by_url( $url ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url ) {
			return 0;
		}

		$attachment_id = attachment_url_to_postid( $url );

		if ( $attachment_id ) {
			return (int) $attachment_id;
		}

		// Резерв: поиск по GUID (медленно, но надёжно при переносах сайта).
		global $wpdb;

		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND guid = %s LIMIT 1",
			'attachment',
			$url
		) );

		return $id ? (int) $id : 0;
	}

	/**
	 * Проверка типа файла по ID вложения.
	 *
	 * @param int    $attachment_id ID вложения.
	 * @param string $type          Тип: 'image' | 'audio' | 'video'.
	 * @return bool true, если вложение соответствует типу.
	 */
	public static function idpg_is_attachment_type( $attachment_id, $type ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return false;
		}

		$mime = get_post_mime_type( $attachment_id );

		if ( '' === $mime ) {
			return false;
		}

		return 0 === strpos( $mime, $type . '/' );
	}

	// =========================================================================
	// Удаление вложений
	// =========================================================================

	/**
	 * Безопасное удаление вложения (в том числе физического файла).
	 *
	 * Не удаляет вложение, если оно прикреплено к другому контенту,
	 * помимо миниатюры конкретной записи (проверка _thumbnail_id).
	 *
	 * @param int  $attachment_id ID вложения.
	 * @param bool $force         Удалять физический файл (true) или только запись.
	 * @return bool true, если вложение удалено.
	 */
	public static function idpg_delete_attachment( $attachment_id, $force = true ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || ! get_post( $attachment_id ) ) {
			return false;
		}

		$is_thumbnail = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d",
			'_thumbnail_id',
			$attachment_id
		) );

		if ( $is_thumbnail > 0 && ! $force ) {
			return false;
		}

		return false !== wp_delete_attachment( $attachment_id, $force );
	}

	/**
	 * Удаляет постер записи и вложения-потомки.
	 *
	 * Полезно при удалении события/площадки: миниатюра могла быть
	 * загружена админом от имени автора.
	 *
	 * @param int $post_id ID записи.
	 * @return int Количество удалённых вложений.
	 */
	public static function idpg_delete_post_media( $post_id ) {
		$post_id    = absint( $post_id );
		$deleted    = 0;

		if ( ! $post_id ) {
			return 0;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return 0;
		}

		// Если запись сама является вложением — удаляем целиком.
		if ( 'attachment' === $post->post_type ) {
			if ( self::idpg_delete_attachment( $post_id, true ) ) {
				$deleted++;
			}
			return $deleted;
		}

		// Постер (миниатюра) записи.
		$thumb_id = self::idpg_get_poster_id( $post_id );

		if ( $thumb_id && get_post( $thumb_id ) ) {
			if ( self::idpg_delete_attachment( $thumb_id, true ) ) {
				$deleted++;
			}
		}

		// Вложения-потомки (post_parent).
		$attachments = get_posts( array(
			'post_type'      => 'attachment',
			'post_parent'    => $post_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		foreach ( $attachments as $att_id ) {
			if ( self::idpg_delete_attachment( (int) $att_id, true ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}
}

// Инициализация синглтона на этапе загрузки файла.
IDPG_Media::instance();