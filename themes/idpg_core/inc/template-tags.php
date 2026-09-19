<?php
/**
 * Template tags for idpg_core theme.
 *
 * Хелперы для специализированных шаблонов событий и площадок.
 * Работают поверх плагина idpg-core (IDPG_Template_Helper) с защитными
 * проверками на случай отключённого плагина.
 *
 * @package idpg_core
 */

if ( ! function_exists( 'idpg_core_event_meta' ) ) {
	/**
	 * Мета-данные события одним массивом.
	 *
	 * @param int $post_id ID события.
	 * @return array Массив мета-данных.
	 */
	function idpg_core_event_meta( $post_id ) {
		$post_id = absint( $post_id );
		if ( class_exists( 'IDPG_Template_Helper' ) ) {
			return IDPG_Template_Helper::get_event_meta( $post_id );
		}

		return array(
			'status'      => get_post_meta( $post_id, '_idpg_ticket_status', true ),
			'ticket_type' => get_post_meta( $post_id, '_idpg_ticket_type', true ),
			'ticket_url'  => get_post_meta( $post_id, '_idpg_ticket_url', true ),
			'date_start'  => get_post_meta( $post_id, '_idpg_start_date', true ),
			'date_end'    => get_post_meta( $post_id, '_idpg_end_date', true ),
			'all_day'     => 'yes' === get_post_meta( $post_id, '_idpg_all_day', true ),
			'price_from'  => get_post_meta( $post_id, '_idpg_price_from', true ),
			'price_to'    => get_post_meta( $post_id, '_idpg_price_to', true ),
			'vip_price'   => get_post_meta( $post_id, '_idpg_vip_price', true ),
			'is_free'     => 'yes' === get_post_meta( $post_id, '_idpg_is_free', true ),
			'currency'    => get_post_meta( $post_id, '_idpg_currency', true ),
			'address'     => get_post_meta( $post_id, '_idpg_address', true ),
			'is_online'   => 'yes' === get_post_meta( $post_id, '_idpg_is_online', true ),
			'stream_url'  => get_post_meta( $post_id, '_idpg_stream_url', true ),
			'video'       => get_post_meta( $post_id, '_idpg_video_trailer', true ),
		);
	}
}

if ( ! function_exists( 'idpg_core_event_sessions' ) ) {
	/**
	 * Сеансы события.
	 *
	 * @param int $post_id ID события.
	 * @return array Массив сеансов (time, price, url, date, hall, currency).
	 */
	function idpg_core_event_sessions( $post_id ) {
		$post_id = absint( $post_id );
		if ( class_exists( 'IDPG_Template_Helper' ) ) {
			return IDPG_Template_Helper::get_event_sessions( $post_id );
		}

		$raw = get_post_meta( $post_id, '_idpg_sessions_json', true );
		if ( empty( $raw ) ) {
			return array();
		}

		$sessions = json_decode( $raw, true );
		return is_array( $sessions ) ? $sessions : array();
	}
}

if ( ! function_exists( 'idpg_core_ticket_button' ) ) {
	/**
	 * Кнопка покупки билета / статус продаж события.
	 *
	 * @param int $post_id ID события.
	 * @return string HTML кнопки или пустая строка.
	 */
	function idpg_core_ticket_button( $post_id ) {
		$post_id = absint( $post_id );
		if ( class_exists( 'IDPG_Template_Helper' ) ) {
			return IDPG_Template_Helper::get_ticket_button( $post_id );
		}

		return '';
	}
}

// =========================================================================
// Постеры и галерея (IDPG_Media) — защитные обёртки
// =========================================================================

if ( ! function_exists( 'idpg_core_event_poster_id' ) ) {
	/**
	 * ID постера (миниатюры) записи.
	 *
	 * Оборачивает IDPG_Media::idpg_get_poster_id() с fallback на
	 * стандартную миниатюру WordPress — шаблон работает без плагина.
	 *
	 * @param int $post_id ID записи.
	 * @return int ID вложения или 0.
	 */
	function idpg_core_event_poster_id( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return 0;
		}

		if ( class_exists( 'IDPG_Media' ) ) {
			return (int) IDPG_Media::idpg_get_poster_id( $post_id );
		}

		return (int) get_post_thumbnail_id( $post_id );
	}
}

if ( ! function_exists( 'idpg_core_event_poster_url' ) ) {
	/**
	 * URL постера записи указанного размера.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $size    Размер (event-card, event-featured, large, medium и т. д.).
	 * @return string URL изображения или пустая строка.
	 */
	function idpg_core_event_poster_url( $post_id, $size = 'large' ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		if ( class_exists( 'IDPG_Media' ) ) {
			return (string) IDPG_Media::idpg_get_poster_url( $post_id, $size );
		}

		$id = idpg_core_event_poster_id( $post_id );

		if ( ! $id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, $size );

		return $url ? (string) $url : '';
	}
}

if ( ! function_exists( 'idpg_core_event_poster' ) ) {
	/**
	 * HTML-тег <img> постера записи.
	 *
	 * @param int    $post_id  ID записи.
	 * @param string $size     Размер изображения.
	 * @param array  $atts     Дополнительные атрибуты (class, loading и пр.).
	 * @return string HTML-код изображения или пустая строка.
	 */
	function idpg_core_event_poster( $post_id, $size = 'large', $atts = array() ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		if ( class_exists( 'IDPG_Media' ) ) {
			return (string) IDPG_Media::idpg_get_poster( $post_id, $size, $atts );
		}

		$id = idpg_core_event_poster_id( $post_id );

		if ( ! $id ) {
			return '';
		}

		return (string) wp_get_attachment_image( $id, $size, false, $atts );
	}
}

if ( ! function_exists( 'idpg_core_event_gallery' ) ) {
	/**
	 * HTML-галерея события.
	 *
	 * @param int    $post_id ID события.
	 * @param string $size    Размер изображений (по умолчанию event-card).
	 * @return string HTML-код галереи или пустая строка.
	 */
	function idpg_core_event_gallery( $post_id, $size = 'event-card' ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! class_exists( 'IDPG_Media' ) ) {
			return '';
		}

		return (string) IDPG_Media::idpg_get_gallery( $post_id, $size );
	}
}

// =========================================================================
// Городские URL (IDPG_City_URL) — защитные обёртки
// =========================================================================

if ( ! function_exists( 'idpg_core_city_qvar' ) ) {
	/**
	 * Имя кастомного query var города из URL.
	 *
	 * @return string Название query var (idpg_city_path).
	 */
	function idpg_core_city_qvar() {
		return class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::QVAR : 'idpg_city_path';
	}
}

if ( ! function_exists( 'idpg_core_city_filter_url' ) ) {
	/**
	 * URL фильтра афиши для текущего города.
	 *
	 * @param string $type  today|tomorrow|weekend|free|date|category|base.
	 * @param string $value Значение (для date/category).
	 * @return string URL афиши (или дефолтный архив событий без плагина).
	 */
	function idpg_core_city_filter_url( $type, $value = null ) {
		if ( class_exists( 'IDPG_City_URL' ) ) {
			return IDPG_City_URL::filter_url( $type, $value );
		}

		return get_post_type_archive_link( 'event' );
	}
}

if ( ! function_exists( 'idpg_core_city_afisha_url' ) ) {
	/**
	 * URL афиши текущего города.
	 *
	 * @param string|null $slug     Slug города.
	 * @param bool        $preserve Сохранять ли фильтры запроса.
	 * @return string URL афиши (или дефолтный архив событий без плагина).
	 */
	function idpg_core_city_afisha_url( $slug = null, $preserve = false ) {
		if ( class_exists( 'IDPG_City_URL' ) ) {
			return IDPG_City_URL::afisha_url( $slug, $preserve );
		}

		return get_post_type_archive_link( 'event' );
	}
}

if ( ! function_exists( 'idpg_core_city_venues_url' ) ) {
	/**
	 * URL архива площадок текущего города.
	 *
	 * @param string|null $slug Slug города.
	 * @return string URL архива площадок (или дефолтный архив venue).
	 */
	function idpg_core_city_venues_url( $slug = null ) {
		if ( class_exists( 'IDPG_City_URL' ) ) {
			return IDPG_City_URL::venues_url( $slug );
		}

		return get_post_type_archive_link( 'venue' );
	}
}

if ( ! function_exists( 'idpg_core_currency_symbol' ) ) {
	/**
	 * Символ валюты по коду.
	 *
	 * @param string $currency Код валюты (RUB, USD, EUR).
	 * @return string Символ.
	 */
	function idpg_core_currency_symbol( $currency ) {
		$symbols = array(
			'RUB' => '₽',
			'USD' => '$',
			'EUR' => '€',
		);

		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency;
	}
}

if ( ! function_exists( 'idpg_core_format_price' ) ) {
	/**
	 * Форматирование цены события.
	 *
	 * @param int|string $from     Цена от.
	 * @param int|string $to       Цена до.
	 * @param string     $currency Валюта.
	 * @param bool       $is_free  Бесплатное событие.
	 * @return string Строка цены или пустая строка.
	 */
	function idpg_core_format_price( $from, $to, $currency, $is_free = false ) {
		if ( $is_free ) {
			return __( 'Вход свободный', 'idpg_core' );
		}

		$from = '' !== trim( (string) $from ) ? (float) $from : 0;
		$to   = '' !== trim( (string) $to ) ? (float) $to : 0;
		$sym  = idpg_core_currency_symbol( $currency );

		if ( $from <= 0 && $to <= 0 ) {
			return '';
		}

		if ( $from > 0 && $to > 0 && $from !== $to ) {
			return sprintf(
				/* translators: 1: цена от, 2: цена до, 3: символ валюты */
				__( 'от %1$s до %2$s %3$s', 'idpg_core' ),
				number_format_i18n( $from ),
				number_format_i18n( $to ),
				$sym
			);
		}

		$value = $from > 0 ? $from : $to;

		return sprintf(
			/* translators: 1: цена, 2: символ валюты */
			__( '%1$s %2$s', 'idpg_core' ),
			number_format_i18n( $value ),
			$sym
		);
	}
}

if ( ! function_exists( 'idpg_core_format_date' ) ) {
	/**
	 * Форматирование даты Y-m-d в русскую форму.
	 *
	 * @param string $date Дата в формате Y-m-d.
	 * @return string Отформатированная дата или пустая строка.
	 */
	function idpg_core_format_date( $date ) {
		if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		return mysql2date( 'j F Y', $date );
	}
}

if ( ! function_exists( 'idpg_core_event_date_range' ) ) {
	/**
	 * Диапазон дат события.
	 *
	 * Примеры: «7 августа 2026», «7–14 августа 2026», «7 августа – 14 сентября 2026».
	 *
	 * @param string $date_start Дата начала Y-m-d.
	 * @param string $date_end   Дата окончания Y-m-d.
	 * @return string Строка диапазона.
	 */
	function idpg_core_event_date_range( $date_start, $date_end ) {
		$start = idpg_core_format_date( $date_start );
		$end   = idpg_core_format_date( $date_end );

		if ( '' === $start ) {
			return $end;
		}

		if ( '' === $end || $end === $start ) {
			return $start;
		}

		$start_month = mysql2date( 'j F', $date_start );
		$end_month   = mysql2date( 'j F Y', $date_end );

		if ( mysql2date( 'F Y', $date_start ) === mysql2date( 'F Y', $date_end ) ) {
			return sprintf(
				/* translators: 1: начало (число), 2: конец (число месяц год) */
				__( '%1$s–%2$s', 'idpg_core' ),
				$start_month,
				$end_month
			);
		}

		return sprintf(
			/* translators: 1: начало, 2: конец */
			__( '%1$s – %2$s', 'idpg_core' ),
			$start,
			$end
		);
	}
}

if ( ! function_exists( 'idpg_core_term_names' ) ) {
	/**
	 * Названия терминов таксономии для поста.
	 *
	 * @param int    $post_id  ID поста.
	 * @param string $taxonomy Таксономия.
	 * @param int    $limit    Максимум терминов.
	 * @return array Список названий.
	 */
	function idpg_core_term_names( $post_id, $taxonomy, $limit = 3 ) {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$names = array();
		foreach ( array_slice( $terms, 0, $limit ) as $term ) {
			$names[] = $term->name;
		}

		return $names;
	}
}

if ( ! function_exists( 'idpg_core_get_venue' ) ) {
	/**
	 * Объект площадки события.
	 *
	 * @param int $post_id ID события.
	 * @return WP_Post|null Пост площадки или null.
	 */
	function idpg_core_get_venue( $post_id ) {
		$venue_id = absint( get_post_meta( $post_id, '_idpg_venue', true ) );

		if ( ! $venue_id ) {
			return null;
		}

		$venue = get_post( $venue_id );

		if ( ! $venue || 'venue' !== $venue->post_type || 'publish' !== $venue->post_status ) {
			return null;
		}

		return $venue;
	}
}

if ( ! function_exists( 'idpg_core_get_venue_events' ) ) {
	/**
	 * Список событий площадки.
	 *
	 * @param int $venue_id ID площадки.
	 * @param int $limit    Максимум событий.
	 * @return WP_Post[] Массив объектов событий.
	 */
	function idpg_core_get_venue_events( $venue_id, $limit = 12 ) {
		$venue_id = absint( $venue_id );

		if ( ! $venue_id ) {
			return array();
		}

		$events = get_posts( array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'meta_value',
			'meta_key'       => '_idpg_start_date',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => '_idpg_venue',
					'value'   => $venue_id,
					'compare' => '=',
				),
			),
		) );

		return is_array( $events ) ? $events : array();
	}
}

if ( ! function_exists( 'idpg_core_venue_meta' ) ) {
	/**
	 * Мета-данные площадки.
	 *
	 * @param int $post_id ID площадки.
	 * @return array Массив мета-данных.
	 */
	function idpg_core_venue_meta( $post_id ) {
		return array(
			'address'  => get_post_meta( $post_id, '_idpg_venue_address', true ),
			'phone'    => get_post_meta( $post_id, '_idpg_venue_phone', true ),
			'email'    => get_post_meta( $post_id, '_idpg_venue_email', true ),
			'website'  => get_post_meta( $post_id, '_idpg_venue_website', true ),
			'capacity' => get_post_meta( $post_id, '_idpg_venue_capacity', true ),
		);
	}
}

if ( ! function_exists( 'idpg_core_event_address' ) ) {
	/**
	 * Адрес события: свой адрес или адрес площадки.
	 *
	 * @param int $post_id ID события.
	 * @return string Адрес или пустая строка.
	 */
	function idpg_core_event_address( $post_id ) {
		$meta = idpg_core_event_meta( $post_id );

		if ( ! empty( $meta['address'] ) ) {
			return $meta['address'];
		}

		$venue = idpg_core_get_venue( $post_id );

		if ( $venue ) {
			$venue_meta = idpg_core_venue_meta( $venue->ID );
			return ! empty( $venue_meta['address'] ) ? $venue_meta['address'] : '';
		}

		return '';
	}
}

if ( ! function_exists( 'idpg_core_maps_link' ) ) {
	/**
	 * Ссылка на карту по адресу.
	 *
	 * @param string $address Адрес.
	 * @return string URL карты или пустая строка.
	 */
	function idpg_core_maps_link( $address ) {
		if ( empty( $address ) ) {
			return '';
		}

		return add_query_arg(
			array( 'text' => rawurlencode( $address ) ),
			'https://yandex.ru/maps/'
		);
	}
}

if ( ! function_exists( 'idpg_core_video_embed_url' ) ) {
	/**
	 * URL для embed-плеера по ссылке на видео.
	 *
	 * Поддерживает YouTube, Vimeo, VK Видео, Rutube, Дзен, Одноклассники,
	 * Twitch, TikTok, Dailymotion, Coub, Streamable, Facebook, а также
	 * прямые ссылки на видеофайлы (mp4/webm/ogg/ogv/mov/m4v).
	 *
	 * @param string $url Ссылка на видео.
	 * @return string URL embed-плеера / файла или пустая строка.
	 */
	function idpg_core_video_embed_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		// Прямая ссылка на видеофайл — отдаём как есть.
		if ( idpg_core_video_is_file( $url ) ) {
			return $url;
		}

		$embed = '';
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		// YouTube.
		if ( false !== strpos( $host, 'youtube.com' ) || 'youtu.be' === $host || false !== strpos( $host, 'youtube-nocookie.com' ) ) {
			if ( preg_match( '/youtu\.be\/([a-zA-Z0-9_-]{6,})/', $url, $m ) ) {
				$embed = 'https://www.youtube.com/embed/' . $m[1];
			} elseif ( preg_match( '/(?:watch\?v=|\/embed\/|\/shorts\/|\/live\/|v\/)([a-zA-Z0-9_-]{6,})/', $url, $m ) ) {
				$embed = 'https://www.youtube.com/embed/' . $m[1];
			}
		}

		// Vimeo.
		elseif ( false !== strpos( $host, 'vimeo.com' ) ) {
			if ( preg_match( '/player\.vimeo\.com\/video\/(\d+)/', $url, $m ) ) {
				$embed = 'https://player.vimeo.com/video/' . $m[1];
			} elseif ( preg_match( '/vimeo\.com\/(?:channels\/[\w]+\/|groups\/[\w]+\/|video\/)?(\d+)/', $url, $m ) ) {
				$embed = 'https://player.vimeo.com/video/' . $m[1];
			}
		}

		// VK Видео (vk.com, vkvideo.ru, vk.ru).
		elseif ( 'vkvideo.ru' === $host || false !== strpos( $host, 'vk.com' ) || 'vk.ru' === $host ) {
			if ( false !== strpos( $url, 'video_ext.php' ) ) {
				$embed = $url;
			} elseif ( preg_match( '/video(-?\d+)_(\d+)/', $url, $m ) ) {
				$embed = 'https://vk.com/video_ext.php?oid=' . $m[1] . '&id=' . $m[2] . '&hd=2';
			}
		}

		// Rutube.
		elseif ( false !== strpos( $host, 'rutube.ru' ) ) {
			if ( false !== strpos( $url, '/play/embed/' ) ) {
				$embed = $url;
			} elseif ( preg_match( '/rutube\.ru\/(?:video|v)\/([a-zA-Z0-9]+)/', $url, $m ) ) {
				$embed = 'https://rutube.ru/play/embed/' . $m[1];
			}
		}

		// Дзен (dzen.ru, zen.yandex.ru).
		elseif ( 'dzen.ru' === $host || 'zen.yandex.ru' === $host ) {
			if ( false !== strpos( $url, '/embed/' ) ) {
				$embed = $url;
			} elseif ( preg_match( '/\/video\/watch\/([a-zA-Z0-9]+)/', $url, $m ) ) {
				$embed = 'https://dzen.ru/embed/watch/' . $m[1] . '?iframe=1';
			}
		}

		// Одноклассники (ok.ru, odnoklassniki.ru).
		elseif ( 'ok.ru' === $host || 'odnoklassniki.ru' === $host || 'm.ok.ru' === $host ) {
			if ( false !== strpos( $url, 'videoembed' ) ) {
				$embed = $url;
			} elseif ( preg_match( '/\/video\/(\d+)/', $url, $m ) ) {
				$embed = 'https://ok.ru/videoembed/' . $m[1];
			}
		}

		// Coub.
		elseif ( 'coub.com' === $host ) {
			if ( preg_match( '/coub\.com\/(?:view|embed)\/([a-zA-Z0-9]+)/', $url, $m ) ) {
				$embed = 'https://coub.com/embed/' . $m[1];
			}
		}

		// Twitch (нужен родительский домен).
		elseif ( false !== strpos( $host, 'twitch.tv' ) ) {
			$idpg_parent = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			if ( preg_match( '/twitch\.tv\/videos\/(\d+)/', $url, $m ) ) {
				$embed = 'https://player.twitch.tv/?video=' . $m[1] . '&parent=' . $idpg_parent;
			} elseif ( preg_match( '/twitch\.tv\/([a-zA-Z0-9_]+)/', $url, $m ) ) {
				$embed = 'https://player.twitch.tv/?channel=' . $m[1] . '&parent=' . $idpg_parent;
			}
		}

		// TikTok.
		elseif ( false !== strpos( $host, 'tiktok.com' ) ) {
			if ( preg_match( '/\/video\/(\d+)/', $url, $m ) ) {
				$embed = 'https://www.tiktok.com/embed/v2/' . $m[1];
			}
		}

		// Dailymotion.
		elseif ( false !== strpos( $host, 'dailymotion.com' ) || 'dai.ly' === $host ) {
			if ( preg_match( '/dailymotion\.com\/video\/([a-zA-Z0-9]+)/', $url, $m ) ) {
				$embed = 'https://www.dailymotion.com/embed/video/' . $m[1];
			} elseif ( preg_match( '/dai\.ly\/([a-zA-Z0-9]+)/', $url, $m ) ) {
				$embed = 'https://www.dailymotion.com/embed/video/' . $m[1];
			}
		}

		// Streamable.
		elseif ( 'streamable.com' === $host ) {
			if ( preg_match( '/streamable\.com\/(?:e\/)?([a-zA-Z0-9]+)/', $url, $m ) ) {
				$embed = 'https://streamable.com/e/' . $m[1];
			}
		}

		// Facebook / fb.watch.
		elseif ( false !== strpos( $host, 'facebook.com' ) || 'fb.watch' === $host ) {
			if ( false !== strpos( $url, 'plugins/video.php' ) ) {
				$embed = $url;
			} else {
				$embed = 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode( $url );
			}
		}

		return $embed;
	}
}

if ( ! function_exists( 'idpg_core_video_is_file' ) ) {
	/**
	 * Является ли ссылка прямой ссылкой на видеофайл.
	 *
	 * @param string $url Ссылка на видео.
	 * @return bool
	 */
	function idpg_core_video_is_file( $url ) {
		return (bool) preg_match( '/\.(mp4|webm|ogg|ogv|mov|m4v)(\?.*)?$/i', trim( (string) $url ) );
	}
}

if ( ! function_exists( 'idpg_core_video_file_mime' ) ) {
	/**
	 * MIME-тип видеофайла по расширению.
	 *
	 * @param string $url Ссылка на видеофайл.
	 * @return string MIME-тип.
	 */
	function idpg_core_video_file_mime( $url ) {
		$url = trim( (string) $url );

		if ( preg_match( '/\.(webm)(\?.*)?$/i', $url ) ) {
			return 'video/webm';
		}

		if ( preg_match( '/\.(ogg|ogv)(\?.*)?$/i', $url ) ) {
			return 'video/ogg';
		}

		if ( preg_match( '/\.(mov|m4v)(\?.*)?$/i', $url ) ) {
			return 'video/mp4';
		}

		return 'video/mp4';
	}
}

if ( ! function_exists( 'idpg_core_video_host_label' ) ) {
	/**
	 * Определяет площадку видео по embed-ссылке и возвращает label для фолбэка.
	 *
	 * @param string $embed_url Embed-ссылка (результат idpg_core_video_embed_url).
	 * @return string[] Массив ['host' => название, 'url' => ссылка для просмотра].
	 */
	function idpg_core_video_host_label( $embed_url ) {
		$embed_url = trim( (string) $embed_url );
		$host = strtolower( (string) wp_parse_url( $embed_url, PHP_URL_HOST ) );

		$platforms = array(
			'youtube.com'         => array( 'name' => 'YouTube',        'pattern' => '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/' ),
			'youtu.be'            => array( 'name' => 'YouTube',        'pattern' => '/youtu\.be\/([a-zA-Z0-9_-]+)/' ),
			'vimeo.com'           => array( 'name' => 'Vimeo',          'pattern' => '/vimeo\.com\/(?:video\/)?(\d+)/' ),
			'player.vimeo.com'    => array( 'name' => 'Vimeo',          'pattern' => '/player\.vimeo\.com\/video\/(\d+)/' ),
			'vk.com'              => array( 'name' => 'VK Видео',       'pattern' => '/vk\.com\/video_ext\.php/' ),
			'vkvideo.ru'          => array( 'name' => 'VK Видео',       'pattern' => '/vkvideo\.ru\/video_ext\.php/' ),
			'rutube.ru'           => array( 'name' => 'Rutube',         'pattern' => '/rutube\.ru\/play\/embed\/([a-zA-Z0-9]+)/' ),
			'dzen.ru'             => array( 'name' => 'Дзен',           'pattern' => '/dzen\.ru\/embed\/watch\/([a-zA-Z0-9]+)/' ),
			'ok.ru'               => array( 'name' => 'Одноклассники',  'pattern' => '/ok\.ru\/videoembed\/(\d+)/' ),
			'coub.com'            => array( 'name' => 'Coub',           'pattern' => '/coub\.com\/embed\/([a-zA-Z0-9]+)/' ),
			'dailymotion.com'     => array( 'name' => 'Dailymotion',    'pattern' => '/dailymotion\.com\/embed\/video\/([a-zA-Z0-9]+)/' ),
			'streamable.com'      => array( 'name' => 'Streamable',     'pattern' => '/streamable\.com\/e\/([a-zA-Z0-9]+)/' ),
			'tiktok.com'          => array( 'name' => 'TikTok',         'pattern' => '/tiktok\.com\/embed\/v2\/(\d+)/' ),
			'facebook.com'        => array( 'name' => 'Facebook',       'pattern' => '/facebook\.com\/plugins\/video\.php/' ),
			'twitch.tv'           => array( 'name' => 'Twitch',         'pattern' => '/twitch\.tv\/(videos\/\d+|[^?]+?)(?=&|$)/' ),
		);

		foreach ( $platforms as $domain => $info ) {
			if ( false === strpos( $host, $domain ) ) {
				continue;
			}

			$link = 'https://' . $domain;

			if ( 'youtube.com' === $domain || 'youtu.be' === $domain ) {
				$id = $info['pattern'];
				preg_match( $id, $embed_url, $m );
				$link = 'https://www.youtube.com/watch?v=' . ( $m[1] ?? '' );
			} elseif ( 'vimeo.com' === $domain || 'player.vimeo.com' === $domain ) {
				preg_match( $info['pattern'], $embed_url, $m );
				$link = 'https://vimeo.com/' . ( $m[1] ?? '' );
			} elseif ( 'vk.com' === $domain || 'vkvideo.ru' === $domain ) {
				preg_match( '/oid=(-?\d+)&id=(\d+)/', $embed_url, $m );
				$link = 'https://vk.com/video' . ( $m[1] ?? '' ) . '_' . ( $m[2] ?? '' );
			} elseif ( 'rutube.ru' === $domain ) {
				preg_match( $info['pattern'], $embed_url, $m );
				$link = 'https://rutube.ru/video/' . ( $m[1] ?? '' );
			} elseif ( 'dzen.ru' === $domain ) {
				preg_match( $info['pattern'], $embed_url, $m );
				$link = 'https://dzen.ru/video/watch/' . ( $m[1] ?? '' );
			} elseif ( 'ok.ru' === $domain ) {
				preg_match( $info['pattern'], $embed_url, $m );
				$link = 'https://ok.ru/video/' . ( $m[1] ?? '' );
			} elseif ( 'coub.com' === $domain ) {
				preg_match( $info['pattern'], $embed_url, $m );
				$link = 'https://coub.com/view/' . ( $m[1] ?? '' );
			} elseif ( 'dailymotion.com' === $domain ) {
				preg_match( $info['pattern'], $embed_url, $m );
				$link = 'https://www.dailymotion.com/video/' . ( $m[1] ?? '' );
			} elseif ( 'streamable.com' === $domain ) {
				preg_match( $info['pattern'], $embed_url, $m );
				$link = 'https://streamable.com/' . ( $m[1] ?? '' );
			} elseif ( 'tiktok.com' === $domain ) {
				preg_match( $info['pattern'], $embed_url, $m );
				$link = 'https://www.tiktok.com/@user/video/' . ( $m[1] ?? '' );
			} elseif ( 'twitch.tv' === $domain ) {
				if ( preg_match( '/video=(\d+)/', $embed_url, $m ) ) {
					$link = 'https://www.twitch.tv/videos/' . $m[1];
				} elseif ( preg_match( '/channel=([^&]+)/', $embed_url, $m ) ) {
					$link = 'https://www.twitch.tv/' . $m[1];
				}
			}

			return array(
				'name' => $info['name'],
				'url'  => $link,
			);
		}

		return array(
			'name' => 'Видео',
			'url'  => $embed_url,
		);
	}
}

if ( ! function_exists( 'idpg_get_current_city_id' ) ) {
	/**
	 * ID текущего города посетителя.
	 *
	 * Обёртка над IDPG_City_Filter::get_current_city_id(): cookie
	 * автоопределения → константа IDPG_DEFAULT_CITY_ID (Ижевск, 15).
	 *
	 * @return int Term ID города или 0, если город не определён.
	 */
	function idpg_get_current_city_id() {
		if ( class_exists( 'IDPG_City_Filter' ) ) {
			return (int) IDPG_City_Filter::get_current_city_id();
		}

		// Плагин неактивен — дефолт из ТЗ (Ижевск).
		return defined( 'IDPG_DEFAULT_CITY_ID' ) ? (int) IDPG_DEFAULT_CITY_ID : 15;
	}
}

if ( ! function_exists( 'idpg_get_home_section_events' ) ) {
	/**
	 * WP_Query событий для секционных блоков главной страницы.
	 *
	 * Автоматически ограничивает выборку текущим городом посетителя
	 * (cookie/гео через плагин; при недоступности плагина — Ижевск, id 15).
	 * Городской фильтр объединяется с пользовательским tax_query
	 * через relation=AND.
	 *
	 * Пример:
	 *   $q = idpg_get_home_section_events( array(
	 *       'posts_per_page' => 3,
	 *       'meta_query'     => array( array(
	 *           'key' => '_idpg_is_free', 'value' => 'yes',
	 *       ) ),
	 *   ) );
	 *
	 * @param array $args Аргументы WP_Query (переопределяют дефолтные).
	 * @return WP_Query
	 */
	function idpg_get_home_section_events( $args = array() ) {
		$defaults = array(
			'post_type'           => 'event',
			'post_status'         => 'publish',
			'posts_per_page'      => 6,
			'meta_query'          => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'tax_query'           => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'orderby'             => 'meta_value',
			'meta_key'            => '_idpg_start_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'               => 'ASC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);

		$args = wp_parse_args( $args, $defaults );

		// --- Фильтр по текущему городу ---
		$city_tax_query = array();
		$city_id        = idpg_get_current_city_id();

		// Добавляем условие только если термин реально существует.
		if ( $city_id && get_term( $city_id, 'city' ) instanceof WP_Term ) {
			$city_tax_query = array(
				array(
					'taxonomy'         => 'city',
					'field'            => 'term_id',
					'terms'            => $city_id,
					// Регион («Удмуртия») включает события своих городов.
					'include_children' => true,
				),
			);
		}

		// --- Объединение tax_query: пользовательские условия + город ---
		$user_tax = is_array( $args['tax_query'] ) ? $args['tax_query'] : array();

		if ( $city_tax_query && $user_tax ) {
			// Если пользователь задал свой relation — сохраняем его на верхнем уровне.
			$relation = isset( $user_tax['relation'] ) ? strtoupper( $user_tax['relation'] ) : '';

			unset( $user_tax['relation'] );

			$args['tax_query'] = array_merge(
				array( 'relation' => 'AND' ),
				$user_tax,
				$city_tax_query
			);

			if ( in_array( $relation, array( 'AND', 'OR' ), true ) ) {
				$args['tax_query']['relation'] = $relation;
			}
		} elseif ( $city_tax_query ) {
			$args['tax_query'] = $city_tax_query;
		} else {
			$args['tax_query'] = $user_tax;
		}

		return new WP_Query( $args );
	}
}

if ( ! function_exists( 'idpg_core_event_schema_date' ) ) {
	/**
	 * Дата события в формате ISO 8601 для Schema.org.
	 *
	 * 'Y-m-d\TH:i' → 'Y-m-d\TH:i:s', 'Y-m-d' → 'Y-m-d'.
	 *
	 * @param string $raw Сырое значение мета-поля.
	 * @return string Пустая строка, если дату распознать не удалось.
	 */
	function idpg_core_event_schema_date( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		$ts = strtotime( $raw );

		if ( false === $ts ) {
			return '';
		}

		if ( preg_match( '/T\s*\d{2}:\d{2}|\d{1,2}:\d{2}\s*$/', $raw ) ) {
			return gmdate( 'Y-m-d\TH:i:s', $ts );
		}

		return gmdate( 'Y-m-d', $ts );
	}
}

if ( ! function_exists( 'idpg_core_event_jsonld' ) ) {
	/**
	 * Вывод JSON-LD микроразметки Schema.org/Event для карточки события.
	 *
	 * Пустые поля пропускаются; одно событие печатается один раз
	 * за запрос (защита от дублей, когда карточка встречается
	 * в нескольких секциях главной).
	 *
	 * @param int $post_id ID события.
	 * @return void
	 */
	function idpg_core_event_jsonld( $post_id ) {
		static $printed = array();

		$post_id = absint( $post_id );

		if ( ! $post_id || isset( $printed[ $post_id ] ) || 'event' !== get_post_type( $post_id ) ) {
			return;
		}

		/*
		 * При активном Yoast SEO разметку Event/Place на страницах события
		 * и площадки отдаёт Yoast (модуль IDPG_SEO_Integration в idpg-core
		 * добавляет сущности в его граф). Свой вывод на одиночных страницах
		 * здесь отключаем, чтобы не дублировать сущности. Разметка в карточках
		 * (главная, афиша) остаётся — на архивных страницах Yoast её не строит.
		 */
		if ( defined( 'WPSEO_VERSION' ) && ( is_singular( 'event' ) || is_singular( 'venue' ) ) ) {
			$printed[ $post_id ] = true;
			return;
		}

		$meta   = idpg_core_event_meta( $post_id );
		$venue  = idpg_core_get_venue( $post_id );
		$address = idpg_core_event_address( $post_id );

		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Event',
			'name'     => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
		);

		// --- Даты ---
		$start_iso = idpg_core_event_schema_date( $meta['date_start'] );
		if ( $start_iso ) {
			$data['startDate'] = $start_iso;
		}

		$end_iso = idpg_core_event_schema_date( $meta['date_end'] );
		if ( $end_iso ) {
			$data['endDate'] = $end_iso;
		}

		// --- Статус события ---
		$statuses = array(
			'postponed' => 'https://schema.org/EventPostponed',
			'cancelled' => 'https://schema.org/EventCancelled',
		);
		if ( isset( $statuses[ $meta['status'] ] ) ) {
			$data['eventStatus'] = $statuses[ $meta['status'] ];
		}

		// --- Место проведения ---
		if ( $venue || $address ) {
			$location = array( '@type' => 'Place' );

			if ( $venue ) {
				$location['name'] = html_entity_decode( $venue->post_title, ENT_QUOTES, 'UTF-8' );
			}

			if ( $address ) {
				$location['address'] = array(
					'@type'        => 'PostalAddress',
					'streetAddress' => $address,
				);
			}

			$data['location'] = $location;
		}

		// --- Изображение ---
		if ( idpg_core_event_poster_id( $post_id ) ) {
			$img = idpg_core_event_poster_url( $post_id, 'large' );

			if ( $img ) {
				$data['image'] = $img;
			}
		}

		// --- Описание: цитата или первые слова контента ---
		$description = has_excerpt( $post_id )
			? get_the_excerpt( $post_id )
			: wp_trim_words( wp_strip_all_tags( get_post( $post_id )->post_content ), 30, '…' );

		if ( $description ) {
			$data['description'] = wp_strip_all_tags( $description );
		}

		// --- Оффер (цена / билеты) ---
		$is_free      = ! empty( $meta['is_free'] );
		$price_from   = (float) $meta['price_from'];
		$currency     = $meta['currency'] ? strtoupper( $meta['currency'] ) : 'RUB';
		$offer_url    = $meta['ticket_url'] ? $meta['ticket_url'] : get_permalink( $post_id );

		if ( $price_from > 0 || $is_free || $meta['ticket_url'] ) {
			$offers = array(
				'@type' => 'Offer',
				'url'   => esc_url_raw( $offer_url ),
			);

			if ( $is_free ) {
				$offers['price']  = 0;
				$offers['priceCurrency'] = $currency;
			} elseif ( $price_from > 0 ) {
				$offers['price']  = $price_from;
				$offers['priceCurrency'] = $currency;
			}

			// Доступность билетов.
			$availability = array(
				'in_sale'  => 'https://schema.org/InStock',
				'sold_out' => 'https://schema.org/SoldOut',
			);
			if ( isset( $availability[ $meta['status'] ] ) ) {
				$offers['availability'] = $availability[ $meta['status'] ];
			}

			$data['offers'] = $offers;
		}

		$printed[ $post_id ] = true;

		echo '<script type="application/ld+json">'
			. wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. '</script>';
	}
}

if ( ! function_exists( 'idpg_core_event_share_buttons' ) ) {
	/**
	 * Блок «Поделиться» на странице события.
	 *
	 * Российские соцсети и мессенджеры (VK, OK, Telegram, WhatsApp, Viber),
	 * западные (X, Pinterest), почта, нативный шеринг на мобильных
	 * (navigator.share) и копирование ссылки.
	 *
	 * @param int|null $post_id ID события. По умолчанию — текущий пост.
	 * @return void
	 */
	function idpg_core_event_share_buttons( $post_id = null ) {
		$post_id = $post_id ? absint( $post_id ) : get_the_ID();
		if ( ! $post_id || 'event' !== get_post_type( $post_id ) ) {
			return;
		}

		$url = rawurlencode( get_permalink( $post_id ) );
		/* translators: %s: название события */
		$text    = rawurlencode( sprintf( __( 'Событие «%s» на «Иду по городу»', 'idpg_core' ), get_the_title( $post_id ) ) );
		$subject = rawurlencode( get_the_title( $post_id ) );
		$image   = '';
		if ( idpg_core_event_poster_id( $post_id ) ) {
			$image = rawurlencode( (string) idpg_core_event_poster_url( $post_id, 'large' ) );
		}

		/**
		 * Сети шеринга. Фильтр позволяет добавлять/убирать кнопки.
		 * Иконки — официальные логотипы (Simple Icons + пресс-киты брендов).
		 *
		 * @param array $networks [ slug => [ 'label', 'url', 'mode', 'icon', 'viewbox', 'fillrule' ] ].
		 */
		$networks = apply_filters(
			'idpg_core_event_share_networks',
			array(
				'vk'       => array(
					'label'   => __( 'ВКонтакте', 'idpg_core' ),
					'url'     => "https://vk.com/share.php?url={$url}&title={$text}",
					'mode'    => 'popup',
					'icon'    => 'm9.489.004.729-.003h3.564l.73.003.914.01.433.007.418.011.403.014.388.016.374.021.36.025.345.03.333.033c1.74.196 2.933.616 3.833 1.516.9.9 1.32 2.092 1.516 3.833l.034.333.029.346.025.36.02.373.025.588.012.41.013.644.009.915.004.98-.001 3.313-.003.73-.01.914-.007.433-.011.418-.014.403-.016.388-.021.374-.025.36-.03.345-.033.333c-.196 1.74-.616 2.933-1.516 3.833-.9.9-2.092 1.32-3.833 1.516l-.333.034-.346.029-.36.025-.373.02-.588.025-.41.012-.644.013-.915.009-.98.004-3.313-.001-.73-.003-.914-.01-.433-.007-.418-.011-.403-.014-.388-.016-.374-.021-.36-.025-.345-.03-.333-.033c-1.74-.196-2.933-.616-3.833-1.516-.9-.9-1.32-2.092-1.516-3.833l-.034-.333-.029-.346-.025-.36-.02-.373-.025-.588-.012-.41-.013-.644-.009-.915-.004-.98.001-3.313.003-.73.01-.914.007-.433.011-.418.014-.403.016-.388.021-.374.025-.36.03-.345.033-.333c.196-1.74.616-2.933 1.516-3.833.9-.9 2.092-1.32 3.833-1.516l.333-.034.346-.029.36-.025.373-.02.588-.025.41-.012.644-.013.915-.009ZM6.79 7.3H4.05c.13 6.24 3.25 9.99 8.72 9.99h.31v-3.57c2.01.2 3.53 1.67 4.14 3.57h2.84c-.78-2.84-2.83-4.41-4.11-5.01 1.28-.74 3.08-2.54 3.51-4.98h-2.58c-.56 1.98-2.22 3.78-3.8 3.95V7.3H10.5v6.92c-1.6-.4-3.62-2.34-3.71-6.92Z',
					'viewbox' => '0 0 24 24',
					),
				'telegram' => array(
					'label'   => __( 'Telegram', 'idpg_core' ),
					'url'     => "https://t.me/share/url?url={$url}&text={$text}",
					'mode'    => 'popup',
					'icon'    => 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z',
					'viewbox' => '0 0 24 24',
					),
				'max'      => array(
					'label'   => 'MAX',
					'url'     => "https://max.ru/:share?text={$text}%20{$url}",
					'mode'    => 'popup',
					'icon'    => 'M50.76 0c27.53 0 49.12 22.34 49.12 49.89S77.61 99.23 51.02 99.23c-9.43 0-14.01-1.33-21.37-6.54-.5-.36-1.2-.26-1.63.19-5.66 6.04-20.17 10.28-20.83 2.03C7.19 80.53 0 71.18 0 49.61 0 21.3 23.22 0 50.76 0m.77 24.55c-13.07-.68-23.26 8.39-25.51 22.58-1.86 11.75 1.44 26.07 4.26 26.8 1.2.3 4.08-1.9 6.18-3.88.4-.37.99-.44 1.45-.15 3.27 2 6.97 3.5 11.05 3.71 13.42.7 25.3-9.8 26-23.21.71-13.42-10.01-25.14-23.43-25.85',
					'viewbox' => '0 0 100 100',
					'fillrule' => 'evenodd',
					),
				'ok'       => array(
					'label'   => __( 'Одноклассники', 'idpg_core' ),
					'url'     => "https://connect.ok.ru/offer?url={$url}&title={$text}&imageUrl={$image}",
					'mode'    => 'popup',
					'icon'    => 'M12 0a6.2 6.2 0 0 0-6.194 6.195 6.2 6.2 0 0 0 6.195 6.192 6.2 6.2 0 0 0 6.193-6.192A6.2 6.2 0 0 0 12.001 0zm0 3.63a2.567 2.567 0 0 1 2.565 2.565 2.568 2.568 0 0 1-2.564 2.564 2.568 2.568 0 0 1-2.565-2.564 2.567 2.567 0 0 1 2.565-2.564zM6.807 12.6a1.814 1.814 0 0 0-.91 3.35 11.611 11.611 0 0 0 3.597 1.49l-3.462 3.463a1.815 1.815 0 0 0 2.567 2.566L12 20.066l3.405 3.403a1.813 1.813 0 0 0 2.564 0c.71-.709.71-1.858 0-2.566l-3.462-3.462a11.593 11.593 0 0 0 3.596-1.49 1.814 1.814 0 1 0-1.932-3.073 7.867 7.867 0 0 1-8.34 0c-.318-.2-.674-.29-1.024-.278z',
					'viewbox' => '0 0 24 24',
					),
				'dzen'     => array(
					'label'   => 'Дзен',
					'url'     => "https://dzen.ru/new/share?url={$url}",
					'mode'    => 'popup',
					'icon'    => 'M148.369 82.7304C148.369 82.0906 147.849 81.5608 147.209 81.5308C124.246 80.661 110.271 77.732 100.494 67.955C90.6967 58.1581 87.7776 44.1724 86.9079 21.1596C86.8879 20.5198 86.358 20 85.7082 20H83.0291C82.3893 20 81.8594 20.5198 81.8295 21.1596C80.9597 44.1624 78.0406 58.1581 68.2437 67.955C58.4568 77.742 44.4911 80.661 21.5283 81.5308C20.8885 81.5508 20.3687 82.0806 20.3687 82.7304V85.4096C20.3687 86.0494 20.8885 86.5792 21.5283 86.6092C44.4911 87.4789 58.4667 90.408 68.2437 100.185C78.0206 109.962 80.9397 123.908 81.8195 146.83C81.8394 147.47 82.3693 147.99 83.0191 147.99H85.7082C86.348 147.99 86.8779 147.47 86.9079 146.83C87.7876 123.908 90.7067 109.962 100.484 100.185C110.271 90.398 124.236 87.4789 147.199 86.6092C147.839 86.5892 148.359 86.0594 148.359 85.4096V82.7304H148.369Z',
					'viewbox' => '0 0 169 169',
					),
				'email'    => array(
					'label'   => __( 'Email', 'idpg_core' ),
					'url'     => "mailto:?subject={$subject}&body={$text}%20{$url}",
					'mode'    => 'direct',
					'icon'    => 'M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z',
					'viewbox' => '0 0 24 24',
					),
			)
		);
		?>
		<section class="idpg-event-single__block idpg-share" aria-label="<?php esc_attr_e( 'Поделиться событием', 'idpg_core' ); ?>">
			<h2 class="idpg-event-single__block-title"><?php esc_html_e( 'Поделиться', 'idpg_core' ); ?></h2>
			<div class="idpg-share__row" data-share-url="<?php echo esc_attr( rawurldecode( $url ) ); ?>">
				<?php foreach ( $networks as $slug => $net ) : ?>
					<a
						href="<?php echo esc_url( $net['url'], array( 'http', 'https', 'mailto', 'viber' ) ); ?>"
						class="idpg-share__btn idpg-share__btn--<?php echo esc_attr( $slug ); ?>"
						data-net="<?php echo esc_attr( $net['mode'] ); ?>"
						target="<?php echo 'popup' === $net['mode'] ? '_blank' : '_self'; ?>"
						rel="noopener noreferrer nofollow"
						title="<?php echo esc_attr( $net['label'] ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: название соцсети */ __( 'Поделиться в %s', 'idpg_core' ), $net['label'] ) ); ?>"
					>
						<svg viewBox="<?php echo esc_attr( $net['viewbox'] ?? '0 0 24 24' ); ?>" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="<?php echo esc_attr( $net['icon'] ); ?>"<?php echo ! empty( $net['fillrule'] ) ? ' fill-rule="' . esc_attr( $net['fillrule'] ) . '"' : ''; ?>/></svg>
					</a>
				<?php endforeach; ?>

				<?php if ( wp_is_mobile() ) : ?>
					<button type="button" class="idpg-share__btn idpg-share__btn--native" data-net="native" title="<?php esc_attr_e( 'Поделиться', 'idpg_core' ); ?>" aria-label="<?php esc_attr_e( 'Поделиться', 'idpg_core' ); ?>">
						<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81a3 3 0 1 0-3-3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9a3 3 0 0 0 0 6c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65a2.92 2.92 0 1 0 2.92-2.92z"/></svg>
					</button>
				<?php endif; ?>

				<button type="button" class="idpg-share__btn idpg-share__btn--copy" data-net="copy" title="<?php esc_attr_e( 'Скопировать ссылку', 'idpg_core' ); ?>" aria-label="<?php esc_attr_e( 'Скопировать ссылку', 'idpg_core' ); ?>">
					<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
				</button>
			</div>
			<span class="idpg-share__copied" role="status" aria-live="polite" hidden><?php esc_html_e( 'Ссылка скопирована', 'idpg_core' ); ?></span>

			<?php
			static $printed = false;
			if ( ! $printed ) {
				$printed = true;
				?>
				<script>
				(function(){
					document.addEventListener('click', function(e){
						var btn = e.target.closest('[data-net]');
						if(!btn) return;
						var mode = btn.getAttribute('data-net');
						if(mode === 'popup'){
							e.preventDefault();
							window.open(btn.href, 'idpg-share', 'width=640,height=480,menubar=no,toolbar=no');
						} else if(mode === 'copy'){
							e.preventDefault();
							var wrap = btn.closest('.idpg-share__row');
							var url = wrap ? wrap.getAttribute('data-share-url') : location.href;
							var done = function(){
								var box = document.querySelector('.idpg-share__copied');
								if(!box) return;
								box.hidden = false;
								setTimeout(function(){ box.hidden = true; }, 2000);
							};
							if(navigator.clipboard && navigator.clipboard.writeText){
								navigator.clipboard.writeText(url).then(done).catch(function(){
									var ta = document.createElement('textarea');
									ta.value = url;
									document.body.appendChild(ta);
									ta.select();
									try{ document.execCommand('copy'); done(); }catch(err){}
									document.body.removeChild(ta);
								});
							} else {
								var ta = document.createElement('textarea');
								ta.value = url;
								document.body.appendChild(ta);
								ta.select();
								try{ document.execCommand('copy'); done(); }catch(err){}
								document.body.removeChild(ta);
							}
						} else if(mode === 'native'){
							e.preventDefault();
							var wrap2 = btn.closest('.idpg-share__row');
							var url2 = wrap2 ? wrap2.getAttribute('data-share-url') : location.href;
							if(navigator.share){
								navigator.share({ title: document.title, url: url2 }).catch(function(){});
							}
						}
					}, false);
				})();
				</script>
				<?php
			}
			?>
		</section>
		<?php
	}
}